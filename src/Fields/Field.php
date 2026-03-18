<?php

namespace Jurager\Eav\Fields;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Jurager\Eav\AttributeLocaleRegistry;
use Jurager\Eav\Models\Attribute;

/**
 * Base attribute field abstraction for validation, localization and storage mapping.
 */
abstract class Field
{
    public const STORAGE_TEXT = 'value_text';

    public const STORAGE_INTEGER = 'value_integer';

    public const STORAGE_FLOAT = 'value_float';

    public const STORAGE_BOOLEAN = 'value_boolean';

    public const STORAGE_DATE = 'value_date';

    public const STORAGE_DATETIME = 'value_datetime';

    /**
     * @var array<int, array{locale_id: int|null, value: mixed}>
     */
    protected array $values = [];

    /**
     * @var array<string>
     */
    protected array $validationErrors = [];

    protected AttributeLocaleRegistry $localeRegistry;

    /**
     * @param  Attribute                    $attribute      Attribute definition model.
     * @param  AttributeLocaleRegistry|null $localeRegistry  Locale registry dependency.
     */
    public function __construct(protected Attribute $attribute, ?AttributeLocaleRegistry $localeRegistry = null)
    {
        $this->localeRegistry = $localeRegistry ?? new AttributeLocaleRegistry();
    }

    /**
     * Return the storage column name for this field type.
     */
    abstract public function column(): string;

    /**
     * Hydrate field values from stored entity attribute records.
     *
     * @param  Collection<int, object>  $records
     */
    public function hydrate(Collection $records): void
    {
        if ($records->isEmpty()) {
            $this->values = [];

            return;
        }

        if (! $this->isLocalizable()) {
            $values = $records->map(fn ($record) => $this->fromRecord($record))->all();

            $this->values = [[
                'locale_id' => null,
                'value' => $this->isMultiple() ? $values : $values[0],
            ]];

            return;
        }

        $records->loadMissing('translations');

        $byLocale = [];
        foreach ($records as $record) {
            foreach ($record->translations as $translation) {
                $byLocale[$translation->id][] = $translation->pivot->label;
            }
        }

        $this->values = collect($byLocale)->map(fn ($values, $localeId) => [
            'locale_id' => $localeId,
            'value' => $this->isMultiple() ? $values : $values[0],
        ])->values()->all();
    }

    /**
     * Validate and normalize incoming value payload.
     */
    public function fill(mixed $values): bool
    {
        $this->validationErrors = [];
        $this->values = [];

        if ($values === null) {
            return true;
        }

        if (! $this->validate($values)) {
            return false;
        }

        $this->values = $this->processValues($values);

        return true;
    }

    /**
     * Determine if the field currently has normalized value(s).
     */
    public function isFilled(): bool
    {
        return ! empty($this->values);
    }

    /**
     * Determine if validation produced errors.
     */
    public function hasErrors(): bool
    {
        return ! empty($this->validationErrors);
    }

    /**
     * Return all validation errors.
     *
     * @return array<string>
     */
    public function errors(): array
    {
        return $this->validationErrors;
    }

    /**
     * Return the storage representation of the current values.
     *
     * @return array<int, array{value: mixed, translations: array}>
     */
    public function toStorage(): array
    {
        if (! $this->isLocalizable()) {
            $value = $this->values[0]['value'] ?? null;
            $items = is_array($value) ? $value : [$value];

            return array_map(static fn ($v) => ['value' => $v, 'translations' => []], $items);
        }

        // Localizable: transpose locale values into records with translations.
        $maxCount = 1;
        foreach ($this->values as $item) {
            if (is_array($item['value'])) {
                $maxCount = max($maxCount, count($item['value']));
            }
        }

        $result = [];
        for ($i = 0; $i < $maxCount; $i++) {
            $translations = [];
            foreach ($this->values as $item) {
                $values = is_array($item['value']) ? $item['value'] : [$item['value']];

                if (isset($values[$i])) {
                    $translations[] = [
                        'locale_id' => $item['locale_id'],
                        'value' => $values[$i],
                    ];
                }
            }

            $result[] = ['value' => null, 'translations' => $translations];
        }

        return $result;
    }

    /**
     * Return the typed value for a specific locale.
     *
     * @param  int|null  $localeId  Locale ID, or null for the default locale.
     */
    public function value(?int $localeId = null): mixed
    {
        if (empty($this->values)) {
            return null;
        }

        if (! $this->isLocalizable()) {
            return $this->values[0]['value'] ?? null;
        }

        $localeId ??= $this->localeRegistry->defaultLocaleId();

        $localeIds = array_column($this->values, 'locale_id');
        $key = array_search($localeId, $localeIds, true);

        return $key !== false ? $this->values[$key]['value'] : null;
    }

    /**
     * Set the value for a specific locale without persisting.
     *
     * @param  mixed    $value     Value to set.
     * @param  int|null $localeId  Target locale; null uses the default locale for localizable fields.
     */
    public function set(mixed $value, ?int $localeId = null): void
    {
        $processedValue = $this->processValue($value);
        $localeId = $this->isLocalizable()
            ? ($localeId ?? $this->localeRegistry->defaultLocaleId())
            : null;

        $localeIds = array_column($this->values, 'locale_id');
        $key = array_search($localeId, $localeIds, true);

        if ($key !== false) {
            $this->values[$key]['value'] = $processedValue;

            return;
        }

        $this->values[] = [
            'locale_id' => $localeId,
            'value' => $processedValue,
        ];
    }

    /**
     * Remove the value for a specific locale, or all values for non-localized fields.
     */
    public function forget(?int $localeId = null): void
    {
        if ($localeId === null || ! $this->isLocalizable()) {
            $this->values = [];

            return;
        }

        $this->values = array_values(array_filter(
            $this->values,
            static fn (array $item) => $item['locale_id'] !== $localeId,
        ));
    }

    /**
     * Determine if the field has a non-null value for the given locale.
     */
    public function has(?int $localeId = null): bool
    {
        return $this->value($localeId) !== null;
    }

    /**
     * Return the Attribute definition model for this field.
     */
    public function attribute(): Attribute
    {
        return $this->attribute;
    }

    /**
     * Return the attribute code.
     */
    public function code(): string
    {
        return $this->attribute->code;
    }

    public function isLocalizable(): bool
    {
        return (bool) $this->attribute->localizable;
    }

    public function isMultiple(): bool
    {
        return (bool) $this->attribute->multiple;
    }

    public function isMandatory(): bool
    {
        return (bool) $this->attribute->mandatory;
    }

    public function isUnique(): bool
    {
        return (bool) $this->attribute->unique;
    }

    public function isFilterable(): bool
    {
        return (bool) ($this->attribute->filterable ?? false);
    }

    public function isSearchable(): bool
    {
        return (bool) ($this->attribute->searchable ?? false);
    }

    /**
     * Return field metadata as an array (type, flags).
     *
     * @return array<string, mixed>
     */
    public function toMetadata(): array
    {
        return [
            'code' => $this->code(),
            'type' => $this->attribute->type->code ?? null,
            'localizable' => $this->isLocalizable(),
            'multiple' => $this->isMultiple(),
            'mandatory' => $this->isMandatory(),
            'unique' => $this->isUnique(),
            'filterable' => $this->isFilterable(),
            'searchable' => $this->isSearchable(),
        ];
    }

    /**
     * Return data for search engine indexing.
     *
     * @return array<string, mixed>
     */
    public function indexData(): array
    {
        $code = $this->code();

        if (! $this->isLocalizable()) {
            $value = $this->value();

            return $value !== null ? [$code => $value] : [];
        }

        $values = array_values(array_filter(
            array_column($this->values, 'value'),
            fn ($v) => $v !== null && $v !== ''
        ));

        return $values ? [$code => $values] : [];
    }

    /**
     * Read the typed value directly from a DB record using the storage column.
     */
    public function fromRecord(object $record): mixed
    {
        return $record->{$this->column()};
    }

    /**
     * Validate payload with cardinality/localization rules and field-specific checks.
     */
    protected function validate(mixed $values): bool
    {
        if (! $this->isLocalizable()) {
            if (! $this->isMultiple()) {
                if (is_array($values)) {
                    return $this->addError(__('eav::attributes.validation.multiple_not_allowed'));
                }

                return $this->validateSingle($values);
            }

            if (! is_array($values)) {
                return $this->addError(__('eav::attributes.validation.array_expected'));
            }

            foreach ($values as $value) {
                if (is_array($value)) {
                    return $this->addError(__('eav::attributes.validation.invalid_format'));
                }
                if (! $this->validateSingle($value)) {
                    return false;
                }
            }

            return true;
        }

        if (! is_array($values)) {
            return $this->addError(__('eav::attributes.validation.translations_required'));
        }

        return $this->validateLocalizableValues($values);
    }

    /**
     * Run type-specific validation then apply any configurable Laravel rules stored on the attribute.
     */
    protected function validateSingle(mixed $value): bool
    {
        if (! $this->validateValue($value)) {
            return false;
        }

        $rules = $this->laravelRules();

        if (empty($rules) || $value === null) {
            return true;
        }

        $validator = Validator::make(['value' => $value], ['value' => $rules]);

        if ($validator->fails()) {
            foreach ($validator->errors()->get('value') as $error) {
                $this->addError($error);
            }

            return false;
        }

        return true;
    }

    /**
     * Convert configurable validation rules stored on the attribute to Laravel rule strings.
     *
     * @return array<string>
     */
    protected function laravelRules(): array
    {
        $rules = [];

        foreach ($this->attribute->validations ?? [] as $validation) {
            $type = $validation['type'] ?? null;
            $param = $validation['value'] ?? null;

            $rule = match ($type) {
                'min_length' => "min:{$param}",
                'max_length' => "max:{$param}",
                'min'        => "min:{$param}",
                'max'        => "max:{$param}",
                'regex'      => "regex:{$param}",
                'email'      => 'email',
                'url'        => 'url',
                'date_format' => "date_format:{$param}",
                'after'      => "after:{$param}",
                'before'     => "before:{$param}",
                default      => null,
            };

            if ($rule !== null) {
                $rules[] = $rule;
            }
        }

        return $rules;
    }

    /**
     * Validate a localized payload where each translation item contains locale and value.
     *
     * @param  array<int, mixed>  $values
     */
    protected function validateLocalizableValues(array $values): bool
    {
        $groups = $this->isMultiple() ? $values : [$values];

        foreach ($groups as $group) {
            if (! is_array($group)) {
                return $this->addError(__('eav::attributes.validation.invalid_format'));
            }

            if (! array_all($group, fn ($translation) => $this->validateTranslation($translation))) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array{locale_id?: int, values?: mixed}  $translation
     */
    protected function validateTranslation(array $translation): bool
    {
        if (! isset($translation['locale_id'])) {
            return $this->addError(__('eav::attributes.validation.locale_required'));
        }

        if (! $this->localeRegistry->isValidLocaleId($translation['locale_id'])) {
            return $this->addError(__('eav::attributes.validation.invalid_locale'));
        }

        return $this->validateSingle($translation['values'] ?? null);
    }

    /**
     * Append a validation error and return false for fluent guards.
     */
    protected function addError(string $message): bool
    {
        $this->validationErrors[] = $message;

        return false;
    }

    /**
     * @return array<int, array{locale_id: int|null, value: mixed}>
     */
    protected function processValues(array|string $values): array
    {
        if (! $this->isLocalizable()) {
            $processed = is_array($values)
                ? array_map(fn ($v) => $this->processValue($v), $values)
                : $this->processValue($values);

            return [['locale_id' => null, 'value' => $processed]];
        }

        $groups = $this->isMultiple() ? $values : [$values];

        $byLocale = [];

        foreach ($groups as $group) {
            foreach ($group as $translation) {
                $byLocale[$translation['locale_id']][] = $this->processValue($translation['values']);
            }
        }

        return collect($byLocale)->map(fn ($values, $localeId) => [
            'locale_id' => $localeId,
            'value' => $this->isMultiple() ? $values : $values[0],
        ])->values()->all();
    }

    abstract protected function validateValue(mixed $value): bool;

    abstract protected function processValue(mixed $value): mixed;
}
