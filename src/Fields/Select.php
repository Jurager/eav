<?php

declare(strict_types=1);

namespace Jurager\Eav\Fields;

use Jurager\Eav\Contracts\Attributable;
use Jurager\Eav\Models\AttributeEnum;

/** Field for enum-backed select, storing selected ID in an integer column. */
class Select extends Field
{
    /** Get the storage column name. */
    public function column(): string
    {
        return self::STORAGE_INTEGER;
    }

    /** Check if this field supports enums. */
    public function isEnum(): bool
    {
        return true;
    }

    /** Convert field values to storage format. */
    public function toStorage(): array
    {
        $value = $this->values[0]['value'] ?? null;
        $items = is_array($value) ? $value : [$value];

        return array_map(static fn ($v) => ['value' => $v, 'translations' => []], $items);
    }

    /** Get the current value as an int or array of ints. */
    public function value(?int $localeId = null): int|array|null
    {
        $raw = $this->values[0]['value'] ?? null;

        return match (true) {
            $raw === null => null,
            is_array($raw) => array_map('intval', $raw),
            default => (int) $raw,
        };
    }

    /** Get the selected enum instance. */
    public function enum(?int $localeId = null): ?AttributeEnum
    {
        $enumId = $this->value($localeId);

        if ($enumId === null || is_array($enumId)) {
            return null;
        }

        return $this->enumRegistry->find($this->attribute->id, $enumId);
    }

    /** Get all selected enum instances. */
    public function enums(?int $localeId = null): array
    {
        $value = $this->value($localeId);

        if ($value === null) {
            return [];
        }

        return $this->enumRegistry->all($this->attribute->id)
            ->whereIn('id', (array) $value)
            ->values()
            ->all();
    }

    /** Get the label for the current value(s). */
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

    /** Get data for the search index. */
    public function indexData(): array
    {
        $code = $this->code();
        $value = $this->value();

        if ($value === null) {
            return [];
        }

        $enums = $this->enums();
        $enumCode = $this->isMultiple()
            ? array_map(static fn (AttributeEnum $enum) => $enum->code, $enums)
            : $this->enum()?->code;

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

    /** Enrich distribution data with labels. */
    public function enrichFacetDistribution(array $distribution, ?int $localeId = null): array
    {
        $enums = $this->enumRegistry->all($this->attribute->id)->keyBy('code');
        $result = [];

        foreach ($distribution as $code => $count) {
            $enum = $enums->get($code);
            $label = $code;

            if ($enum !== null) {
                $label = $localeId !== null
                    ? ($enum->label($localeId) ?? $code)
                    : ($enum->translations->first()?->pivot?->label ?? $code);
            }

            $result[$code] = ['count' => $count, 'label' => $label];
        }

        return $result;
    }

    /** Normalize raw input values into storage format. */
    protected function normalizeValues(array|string|int $values): array
    {
        $normalized = is_array($values)
            ? array_map(fn ($v) => $this->normalize($v), $values)
            : $this->normalize($values);

        return [['locale_id' => null, 'value' => $normalized]];
    }

    /** Validate the field value. */
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

            if (! $this->enumRegistry->isValidId($this->attribute->id, (int) $item)) {
                return $this->addError(__('eav::attributes.validation.invalid_enum'));
            }
        }

        return true;
    }

    /** Normalize the value to an integer. */
    protected function normalize(mixed $value): int
    {
        return (int) $value;
    }
}
