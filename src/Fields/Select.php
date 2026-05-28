<?php

namespace Jurager\Eav\Fields;

use Jurager\Eav\Contracts\Attributable;
use Jurager\Eav\Models\AttributeEnum;

/**
 * Enum-backed select field storing selected enum IDs in integer column.
 */
class Select extends Field
{
    public function column(): string
    {
        return self::STORAGE_INTEGER;
    }

    public function isEnum(): bool
    {
        return true;
    }

    public function toStorage(): array
    {
        $value = $this->values[0]['value'] ?? null;
        $items = is_array($value) ? $value : [$value];

        return array_map(static fn ($v) => ['value' => $v, 'translations' => []], $items);
    }

    public function value(?int $localeId = null): int|array|null
    {
        $raw = $this->values[0]['value'] ?? null;

        return match (true) {
            $raw === null => null,
            is_array($raw) => array_map('intval', $raw),
            default => (int) $raw,
        };
    }

    public function enum(?int $localeId = null): ?AttributeEnum
    {
        $enumId = $this->value($localeId);

        if ($enumId === null || is_array($enumId)) {
            return null;
        }

        return $this->enumRegistry->find($this->attribute->id, $enumId);
    }

    /** @return array<int, AttributeEnum> */
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

    public function indexData(): array
    {
        $code = $this->code();
        $value = $this->value();

        if ($value === null) {
            return [];
        }

        $codeValue = $this->isMultiple()
            ? array_map(static fn (AttributeEnum $e) => $e->code, $this->enums())
            : $this->enum()?->code;

        if ($codeValue === null || $codeValue === []) {
            return [];
        }

        $result = [$code => $codeValue];

        $labels = array_values(array_unique(array_filter(
            array_merge(...array_map(
                fn (int $localeId) => (array) $this->label($localeId),
                $this->localeRegistry->ids()
            )),
            static fn ($label) => $label !== null && $label !== ''
        )));

        if ($labels) {
            $result["{$code}_label"] = $labels;
        }

        return $result;
    }

    /**
     * @param  array<string, int>  $distribution
     * @return array<string, array{count: int, label: string}>
     */
    public function enrichFacetDistribution(array $distribution, ?int $localeId = null): array
    {
        $enums = $this->enumRegistry->all($this->attribute->id)->keyBy('code');
        $result = [];

        foreach ($distribution as $code => $count) {
            $enum = $enums->get($code);
            $label = $code;

            if ($enum !== null) {
                if ($localeId !== null) {
                    $label = $enum->label($localeId) ?? $code;
                } else {
                    $label = $enum->translations->first()?->pivot?->label ?? $code;
                }
            }

            $result[$code] = ['count' => $count, 'label' => $label];
        }

        return $result;
    }

    protected function normalizeValues(array|string|int $values): array
    {
        $normalized = is_array($values)
            ? array_map(fn ($v) => $this->normalize($v), $values)
            : $this->normalize($values);

        return [['locale_id' => null, 'value' => $normalized]];
    }

    protected function validate(mixed $value, ?Attributable $entity = null): bool
    {
        if ($value === null) {
            return true;
        }

        if (! is_numeric($value)) {
            return $this->addError(__('eav::attributes.validation.invalid_value'));
        }

        if (! $this->enumRegistry->isValidId($this->attribute->id, (int) $value)) {
            return $this->addError(__('eav::attributes.validation.invalid_enum'));
        }

        return true;
    }

    protected function normalize(mixed $value): int
    {
        return (int) $value;
    }
}
