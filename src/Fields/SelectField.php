<?php

namespace Jurager\Eav\Fields;

use Jurager\Eav\Models\AttributeEnum;
use Jurager\Eav\Support\EavModels;

/**
 * Enum-backed select field storing selected enum IDs in integer column.
 */
class SelectField extends Field
{
    /**
     * In-memory cache of valid enum IDs keyed by attribute_id.
     * Shared across all instances for the lifetime of the process (one request / job run).
     * Values are dummy true-s so membership can be tested with isset() in O(1).
     *
     * @var array<int, array<int, true>>
     */
    private static array $enumCache = [];

    public function column(): string
    {
        return self::STORAGE_INTEGER;
    }

    /**
     * SelectField always stores enum_id in value column without translations.
     */
    public function toStorage(): array
    {
        $value = $this->values[0]['value'] ?? null;
        $items = is_array($value) ? $value : [$value];

        return array_map(static fn ($v) => ['value' => $v, 'translations' => []], $items);
    }

    /**
     * Return the stored enum ID(s). Ignores $localeId as enums are not locale-scoped.
     */
    public function value(?int $localeId = null): int|array|null
    {
        if (empty($this->values)) {
            return null;
        }

        $raw = $this->values[0]['value'] ?? null;

        if ($raw === null) {
            return null;
        }

        return is_array($raw) ? array_map('intval', $raw) : (int) $raw;
    }

    /**
     * Return the AttributeEnum model for the current single-select value.
     */
    public function enum(?int $localeId = null): ?AttributeEnum
    {
        $enumId = $this->value($localeId);

        if ($enumId === null || is_array($enumId)) {
            return null;
        }

        return $this->attribute->enums->firstWhere('id', $enumId);
    }

    /**
     * Return AttributeEnum models for the current multi-select values.
     *
     * @return AttributeEnum[]
     */
    public function enums(?int $localeId = null): array
    {
        $value = $this->value($localeId);

        if ($value === null) {
            return [];
        }

        $enumIds = is_array($value) ? $value : [$value];

        return $this->attribute->enums->whereIn('id', $enumIds)->values()->all();
    }

    /**
     * Return the translated label(s) for the current value.
     */
    public function label(?int $localeId = null): string|array|null
    {
        $localeId ??= $this->localeRegistry->defaultLocaleId();

        if ($this->isMultiple()) {
            return array_map(
                static fn (AttributeEnum $enum) => $enum->translations
                    ->first(fn ($t) => $t->pivot->locale_id === $localeId)
                    ?->pivot
                    ?->label,
                $this->enums($localeId)
            );
        }

        return $this->enum($localeId)?->translations
            ->first(fn ($t) => $t->pivot->locale_id === $localeId)
            ?->pivot
            ?->label;
    }

    /**
     * @return array<string, mixed>
     */
    public function indexData(): array
    {
        $code = $this->code();
        $value = $this->value();

        if ($value === null) {
            return [];
        }

        $result = [
            $code => $value,
            "{$code}_code" => $this->isMultiple()
                ? array_map(static fn (AttributeEnum $e) => $e->code, $this->enums())
                : $this->enum()?->code,
        ];

        $labels = [];
        foreach (array_keys($this->localeRegistry->localeCodes()) as $localeId) {
            foreach ((array) $this->label($localeId) as $label) {
                if ($label !== null && $label !== '') {
                    $labels[] = $label;
                }
            }
        }
        $labels = array_values(array_unique($labels));

        if ($labels) {
            $result["{$code}_label"] = $labels;
        }

        return $result;
    }

    /**
     * SelectField stores enum_id directly in value column,
     * ignoring attribute localizable flag (labels are translated in enums table).
     */
    protected function validatePayload(mixed $values): bool
    {
        if (! $this->isMultiple()) {
            return $this->validate($values);
        }

        if (! is_array($values)) {
            return $this->addError(__('eav::attributes.validation.array_expected'));
        }

        return $this->validateMultipleValues($values);
    }

    private function validateMultipleValues(array $values): bool
    {
        foreach ($values as $value) {
            if (is_array($value)) {
                return $this->addError(__('eav::attributes.validation.invalid_format'));
            }

            if (! is_numeric($value)) {
                return $this->addError(__('eav::attributes.validation.invalid_value'));
            }
        }

        $validIds = $this->cachedEnumIds();
        $invalid = array_filter(array_map('intval', $values), static fn ($id) => ! isset($validIds[$id]));

        if (! empty($invalid)) {
            return $this->addError(__('eav::attributes.validation.invalid_enum'));
        }

        return true;
    }

    /**
     * @param  array<int, int|string>|int|string  $values
     * @return array<int, array{locale_id: int|null, value: int|array<int, int>}>
     */
    protected function normalizeValues(array|string|int $values): array
    {
        $normalized = is_array($values)
            ? array_map(fn ($v) => $this->normalize($v), $values)
            : $this->normalize($values);

        return [['locale_id' => null, 'value' => $normalized]];
    }

    protected function validate(mixed $value): bool
    {
        if ($value === null) {
            return true;
        }

        if (! is_numeric($value)) {
            return $this->addError(__('eav::attributes.validation.invalid_value'));
        }

        if (! isset($this->cachedEnumIds()[(int) $value])) {
            return $this->addError(__('eav::attributes.validation.invalid_enum'));
        }

        return true;
    }

    /**
     * Return valid enum IDs for this attribute as a flip-array (id => true) for O(1) lookup.
     * The result is cached statically per attribute_id for the lifetime of the process.
     *
     * @return array<int, true>
     */
    private function cachedEnumIds(): array
    {
        $attrId = $this->attribute->id;

        if (! isset(self::$enumCache[$attrId])) {
            $ids = EavModels::query('attribute_enum')
                ->where('attribute_id', $attrId)
                ->pluck('id')
                ->all();

            self::$enumCache[$attrId] = array_fill_keys($ids, true);
        }

        return self::$enumCache[$attrId];
    }

    protected function normalize(mixed $value): int
    {
        return (int) $value;
    }
}
