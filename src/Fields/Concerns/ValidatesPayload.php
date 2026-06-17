<?php

namespace Jurager\Eav\Fields\Concerns;

use Illuminate\Support\Facades\Validator;

/**
 * Payload validation, normalization, and Laravel rule application for Field.
 *
 * @phpstan-require-extends \Jurager\Eav\Fields\Field
 */
trait ValidatesPayload
{
    /** @var array<string> */
    protected array $validationErrors = [];

    public function hasErrors(): bool
    {
        return ! empty($this->validationErrors);
    }

    /** @return array<string> */
    public function errors(): array
    {
        return $this->validationErrors;
    }

    /**
     * Validate the full incoming payload, handling cardinality and localization.
     *
     * Override in subclasses that have a non-standard payload shape (e.g. Select).
     * Default:
     *   - non-localizable, single   → validate() + rules()
     *   - non-localizable, multiple → iterate values, validate() + rules() per item
     *   - localizable               → iterate locale translations, validate() + rules() per item
     */
    protected function validatePayload(mixed $values): bool
    {
        if (! $this->isLocalizable()) {
            if (! $this->isMultiple()) {
                if (is_array($values)) {
                    return $this->addError(__('eav::attributes.validation.multiple_not_allowed'));
                }

                return $this->applyRules($values);
            }

            if (! is_array($values)) {
                return $this->addError(__('eav::attributes.validation.array_expected'));
            }

            foreach ($values as $value) {
                if (is_array($value)) {
                    return $this->addError(__('eav::attributes.validation.invalid_format'));
                }

                if (! $this->applyRules($value)) {
                    return false;
                }
            }

            return true;
        }

        if (! is_array($values)) {
            return $this->addError(__('eav::attributes.validation.translations_required'));
        }

        $groups = $this->isMultiple() ? $values : [$values];

        foreach ($groups as $group) {
            if (! is_array($group)) {
                return $this->addError(__('eav::attributes.validation.invalid_format'));
            }

            foreach ($group as $translation) {
                if (! isset($translation['locale_id'])) {
                    return $this->addError(__('eav::attributes.validation.locale_required'));
                }

                if (! $this->localeRegistry->has($translation['locale_id'])) {
                    return $this->addError(__('eav::attributes.validation.invalid_locale'));
                }

                if (! $this->applyRules($translation['values'] ?? null)) {
                    return false;
                }
            }
        }

        return true;
    }

    /** @return array<int, array{locale_id: int|null, value: mixed}> */
    protected function normalizeValues(array|string $values): array
    {
        if (! $this->isLocalizable()) {
            $normalized = is_array($values)
                ? array_map(fn ($v) => $this->normalize($v), $values)
                : $this->normalize($values);

            return [['locale_id' => null, 'value' => $normalized]];
        }

        $groups = $this->isMultiple() ? $values : [$values];

        $byLocale = [];
        foreach ($groups as $group) {
            foreach ($group as $translation) {
                $byLocale[$translation['locale_id']][] = $this->normalize($translation['values']);
            }
        }

        return collect($byLocale)->map(fn ($values, $localeId) => [
            'locale_id' => $localeId,
            'value' => $this->isMultiple() ? $values : $values[0],
        ])->values()->all();
    }

    protected function addError(string $message): bool
    {
        $this->validationErrors[] = $message;

        return false;
    }

    private function applyRules(mixed $value): bool
    {
        if (! $this->validate($value, $this->entity)) {
            return false;
        }

        $rules = $this->rules();

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
     */
    private function rules(): array
    {
        $map = config('eav.validations', []);
        $rules = [];

        foreach ($this->attribute->validations ?? [] as $validation) {
            $type = $validation['type'] ?? null;
            $param = $validation['value'] ?? null;

            if ($type === null || ! isset($map[$type])) {
                continue;
            }

            $prefix = $map[$type];
            $rules[] = $param !== null ? "$prefix:$param" : $prefix;
        }

        return $rules;
    }
}
