<?php

namespace Jurager\Eav\Fields;

use Jurager\Eav\EavModels;
use Jurager\Eav\Models\AttributeEnum;

/**
 * Enum-backed select field storing selected enum IDs in integer column.
 */
class SelectField extends Field
{
    public function getStorageColumn(): string
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
     * SelectField always returns enum_id from value column.
     */
    public function getValue(?int $localeId = null): int|array|null
    {
        if (empty($this->values)) {
            return null;
        }

        $raw = $this->values[0]['value'] ?? null;

        if ($raw === null) {
            return null;
        }

        if (is_array($raw)) {
            return array_map('intval', $raw);
        }

        return (int) $raw;
    }

    public function getEnum(?int $localeId = null): ?AttributeEnum
    {
        $enumId = $this->getValue($localeId);

        if ($enumId === null || is_array($enumId)) {
            return null;
        }

        return $this->attribute->enums->firstWhere('id', $enumId);
    }

    /**
     * @return AttributeEnum[]
     */
    public function getEnums(?int $localeId = null): array
    {
        $value = $this->getValue($localeId);

        if ($value === null) {
            return [];
        }

        $enumIds = is_array($value) ? $value : [$value];

        return $this->attribute->enums->whereIn('id', $enumIds)->values()->all();
    }

    public function getLabel(?int $localeId = null): string|array|null
    {
        $localeId ??= $this->localeRegistry->getDefaultLocaleId();

        if ($this->isMultiple()) {
            $enums = $this->getEnums($localeId);

            return array_map(
                static fn (AttributeEnum $enum) => $enum->translations
                    ->first(fn ($t) => $t->pivot->locale_id === $localeId)
                    ?->pivot
                    ?->label,
                $enums
            );
        }

        $enum = $this->getEnum($localeId);

        return $enum?->translations
            ->first(fn ($t) => $t->pivot->locale_id === $localeId)
            ?->pivot
            ?->label;
    }

    public function getEnumCode(?int $localeId = null): string|array|null
    {
        if ($this->isMultiple()) {
            $enums = $this->getEnums($localeId);

            return array_map(static fn (AttributeEnum $enum) => $enum->code, $enums);
        }

        return $this->getEnum($localeId)?->code;
    }

    /**
     * @return array<string, mixed>
     */
    public function getIndexData(): array
    {
        $code = $this->getCode();
        $value = $this->getValue();

        if ($value === null) {
            return [];
        }

        $result = [
            $code => $value,
            "{$code}_code" => $this->getEnumCode(),
        ];

        $labels = [];
        foreach (array_keys($this->localeRegistry->getLocaleCodes()) as $localeId) {
            foreach ((array) $this->getLabel($localeId) as $label) {
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
    protected function validate(mixed $values): bool
    {
        if (! $this->isMultiple()) {
            return $this->validateValue($values);
        }

        if (! is_array($values)) {
            return $this->addError(__('eav::attributes.validation.array_expected'));
        }

        return $this->validateMultipleValues($values);
    }

    protected function validateMultipleValues(array $values): bool
    {
        foreach ($values as $value) {
            if (is_array($value)) {
                return $this->addError(__('eav::attributes.validation.invalid_format'));
            }

            if (! is_numeric($value)) {
                return $this->addError(__('eav::attributes.validation.invalid_value'));
            }
        }

        $requested = array_map('intval', $values);
        $valid = EavModels::query('attribute_enum')
            ->where('attribute_id', $this->attribute->id)
            ->whereIn('id', $requested)
            ->pluck('id')
            ->all();

        $invalid = array_diff($requested, $valid);

        if (! empty($invalid)) {
            return $this->addError(__('eav::attributes.validation.invalid_enum'));
        }

        return true;
    }

    /**
     * @param  array<int, int|string>|int|string  $values
     * @return array<int, array{locale_id: int|null, value: int|array<int, int>}>
     */
    protected function processValues(array|string|int $values): array
    {
        $processed = is_array($values)
            ? array_map(fn ($v) => $this->processValue($v), $values)
            : $this->processValue($values);

        return [['locale_id' => null, 'value' => $processed]];
    }

    protected function validateValue(mixed $value): bool
    {
        if ($value === null) {
            return true;
        }

        if (! is_numeric($value)) {
            return $this->addError(__('eav::attributes.validation.invalid_value'));
        }

        $enumId = (int) $value;
        $exists = EavModels::query('attribute_enum')
            ->where('attribute_id', $this->attribute->id)
            ->where('id', $enumId)
            ->exists();

        if (! $exists) {
            return $this->addError(__('eav::attributes.validation.invalid_enum'));
        }

        return true;
    }

    protected function processValue(mixed $value): int
    {
        return (int) $value;
    }
}
