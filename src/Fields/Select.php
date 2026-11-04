<?php

declare(strict_types=1);

namespace Jurager\Eav\Fields;

use Jurager\Eav\Contracts\Attributable;
use Jurager\Eav\Enums\AttributeStorage;
use Jurager\Eav\Models\AttributeEnum;

/**
 * Field for enum-backed select, storing selected ID in an integer column.
 */
class Select extends Field
{
    /**
     * Get the storage column name.
     */
    public function column(): AttributeStorage
    {
        return AttributeStorage::Integer;
    }

    /**
     * A select stores an option id; its labels are localized on the option, never the value.
     */
    public static function localizable(): bool
    {
        return false;
    }

    /**
     * Check if this field supports enums.
     */
    public function isEnum(): bool
    {
        return true;
    }

    /**
     * Get the current value as an int or array of ints.
     */
    public function value(?int $localeId = null): int|array|null
    {
        $raw = $this->values[self::UNLOCALIZED] ?? null;

        return match (true) {
            $raw === null => null,
            is_array($raw) => array_map('intval', $raw),
            default => (int) $raw,
        };
    }

    /**
     * Get the selected enum instance.
     */
    public function enum(?int $localeId = null): ?AttributeEnum
    {
        $enumId = $this->value($localeId);

        if ($enumId === null || is_array($enumId)) {
            return null;
        }

        return $this->enumRegistry->find($this->attribute->getAttribute('id'), $enumId);
    }

    /**
     * Get all selected enum instances.
     */
    public function enums(?int $localeId = null): array
    {
        $value = $this->value($localeId);

        if ($value === null) {
            return [];
        }

        return $this->enumRegistry->all($this->attribute->getAttribute('id'))
            ->whereIn('id', (array) $value)
            ->values()
            ->all();
    }

    /**
     * Get the label for the current value(s).
     */
    public function label(?int $localeId = null): string|array|null
    {
        $localeId ??= $this->localeRegistry->default();

        if ($this->isMultiple()) {
            return array_map(
                fn (AttributeEnum $enum) => $enum->label($localeId),
                $this->enums()
            );
        }

        return $this->enum()?->label($localeId);
    }

    /**
     * Get data for the search index.
     */
    public function indexData(): array
    {
        $code = $this->code();
        $value = $this->value();

        if ($value === null) {
            return [];
        }

        $enums = $this->enums();
        $enumCode = $this->isMultiple()
            ? array_map(static fn (AttributeEnum $enum) => $enum->getAttribute('code'), $enums)
            : $this->enum()?->getAttribute('code');

        if ($enumCode === null || $enumCode === []) {
            return [];
        }

        $result = [$code => $enumCode];

        $labels = collect($this->localeRegistry->ids())
            ->map(fn (int $localeId) => $this->label($localeId))
            ->flatten()
            ->filter(fn ($label) => $label !== null && $label !== '')
            ->unique()
            ->values()
            ->all();

        if ($labels) {
            $result["{$code}_label"] = $labels;
        }

        return $result;
    }

    /**
     * Enrich distribution data with labels.
     */
    public function enrichFacetDistribution(array $distribution, ?int $localeId = null): array
    {
        $enums = $this->enumRegistry->all($this->attribute->getAttribute('id'))->keyBy('code');
        $result = [];

        foreach ($distribution as $code => $count) {
            $enum = $enums->get($code);
            $label = $code;

            if ($enum !== null) {
                $label = $localeId !== null
                    ? ($enum->label($localeId) ?? $code)
                    : ($enum->translations->first()?->pivot?->getAttribute('label') ?? $code);
            }

            $result[$code] = ['count' => $count, 'label' => $label];
        }

        return $result;
    }

    /**
     * Validate the field value.
     */
    protected function validate(mixed $value, ?Attributable $entity = null): bool
    {
        if ($value === null) {
            return true;
        }

        $values = (array) $value;

        foreach ($values as $item) {

            if (! is_numeric($item)) {
                return $this->addError(__('eav::attributes.validation.invalid_value'));
            }

            if (! $this->enumRegistry->isValidId($this->attribute->getAttribute('id'), (int) $item)) {
                return $this->addError(__('eav::attributes.validation.invalid_enum'));
            }
        }

        return true;
    }

    /**
     * Normalize the value to an integer.
     */
    protected function normalize(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }
}
