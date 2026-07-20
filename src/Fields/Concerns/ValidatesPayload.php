<?php

declare(strict_types=1);

namespace Jurager\Eav\Fields\Concerns;

use Illuminate\Support\Facades\Validator;

/**
 * Trait providing payload validation, normalization, and rule application for fields.
 *
 * @phpstan-require-extends \Jurager\Eav\Fields\Field
 */
trait ValidatesPayload
{
    /** @var array<string> */
    protected array $validationErrors = [];

    /** Check if there are validation errors. */
    public function hasErrors(): bool
    {
        return ! empty($this->validationErrors);
    }

    /** Get validation errors. */
    public function errors(): array
    {
        return $this->validationErrors;
    }

    /** Validate the full incoming payload. */
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

                if (! $this->localeRegistry->has((int) $translation['locale_id'])) {
                    return $this->addError(__('eav::attributes.validation.invalid_locale'));
                }

                if (! $this->applyRules($translation['values'] ?? null)) {
                    return false;
                }
            }
        }

        return true;
    }

    /** Normalize input values into storage format. */
    protected function normalizeValues(mixed $values): array
    {
        if (! $this->isLocalizable()) {
            $normalized = is_array($values)
                ? array_map(fn ($v) => $this->normalize($v), $values)
                : $this->normalize($values);

            return [['locale_id' => null, 'value' => $normalized]];
        }

        $input = $this->isMultiple() ? $values : [$values];

        $byLocale = [];

        foreach ($input as $group) {
            foreach ($group as $translation) {
                $localeId = (int) $translation['locale_id'];
                $byLocale[$localeId][] = $this->normalize($translation['values']);
            }
        }

        $result = [];

        foreach ($byLocale as $localeId => $items) {
            $result[] = [
                'locale_id' => $localeId,
                'value'     => $this->isMultiple() ? $items : $items[0],
            ];
        }

        return $result;
    }

    /** Add a validation error. */
    protected function addError(string $message): bool
    {
        $this->validationErrors[] = $message;

        return false;
    }

    /** Apply Laravel validation rules to a value. */
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

    /** Convert attribute validations to Laravel rules. */
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
