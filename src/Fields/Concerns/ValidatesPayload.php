<?php

declare(strict_types=1);

namespace Jurager\Eav\Fields\Concerns;

use Illuminate\Support\Facades\Validator;
use Jurager\Eav\Fields\Field;

/**
 * Trait providing payload validation, normalization, and rule application for fields.
 *
 * @phpstan-require-extends Field
 */
trait ValidatesPayload
{
    /** @var array<string> */
    protected array $validationErrors = [];

    /**
     * Check if there are validation errors.
     */
    public function hasErrors(): bool
    {
        return ! empty($this->validationErrors);
    }

    /**
     * Get validation errors.
     */
    public function errors(): array
    {
        return $this->validationErrors;
    }

    /**
     * Validate a scalar, multiple values, or locale translations.
     */
    protected function validatePayload(mixed $values): bool
    {
        return match (true) {
            $this->isLocalizable() => $this->validateTranslations($values),
            $this->isMultiple() => $this->validateList($values),
            default => $this->validateSingle($values),
        };
    }

    private function validateSingle(mixed $value): bool
    {
        if (! $this->isComposite() && is_array($value)) {
            return $this->addError(__('eav::attributes.validation.multiple_not_allowed'));
        }

        return $this->applyRules([$value]);
    }

    private function validateList(mixed $values): bool
    {
        if (! is_array($values)) {
            return $this->addError(__('eav::attributes.validation.array_expected'));
        }

        foreach ($values as $value) {
            if (! $this->isComposite() && is_array($value)) {
                return $this->addError(__('eav::attributes.validation.invalid_format'));
            }
        }

        return $this->applyRules(array_values($values));
    }

    /**
     * A localizable payload is one group of translations — a group per value when multiple.
     */
    private function validateTranslations(mixed $values): bool
    {
        if (! is_array($values)) {
            return $this->addError(__('eav::attributes.validation.translations_required'));
        }

        $collected = [];

        foreach ($this->isMultiple() ? $values : [$values] as $group) {
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

                $collected[] = $translation['values'] ?? null;
            }
        }

        return $this->applyRules($collected);
    }

    /**
     * Normalize a validated payload into values by locale id.
     *
     * @return array<int, mixed>
     */
    protected function normalizeValues(mixed $values): array
    {
        if (! $this->isLocalizable()) {
            return [self::UNLOCALIZED => is_array($values)
                ? array_map(fn (mixed $value) => $this->normalize($value), $values)
                : $this->normalize($values)];
        }

        $byLocale = [];

        foreach ($this->isMultiple() ? $values : [$values] as $group) {
            foreach ($group as $translation) {
                $byLocale[(int) $translation['locale_id']][] = $this->normalize($translation['values']);
            }
        }

        return array_map(fn (array $items): mixed => $this->isMultiple() ? $items : $items[0], $byLocale);
    }

    /**
     * Add a validation error.
     */
    protected function addError(string $message): bool
    {
        $this->validationErrors[] = $message;

        return false;
    }

    /**
     * Validate values against the field type and attribute rules.
     *
     * @param  list<mixed>  $values
     */
    private function applyRules(array $values): bool
    {
        foreach ($values as $value) {
            if (! $this->validate($value, $this->entity)) {
                return false;
            }
        }

        $rules = $this->rules();
        $subjects = array_values(array_filter(array_map($this->ruleSubject(...), $values), static fn (mixed $subject): bool => $subject !== null));

        if ($rules === [] || $subjects === []) {
            return true;
        }

        $validator = Validator::make(['values' => $subjects], ['values.*' => $rules], [], ['values.*' => $this->attribute->getAttribute('code')]);

        if ($validator->fails()) {
            foreach (array_unique($validator->errors()->all()) as $error) {
                $this->addError($error);
            }

            return false;
        }

        return true;
    }

    /**
     * Get the value to validate against attribute rules.
     */
    protected function ruleSubject(mixed $value): mixed
    {
        return $value;
    }

    /**
     * Determine if a single value uses an array payload.
     */
    protected function isComposite(): bool
    {
        return false;
    }

    /**
     * Convert attribute validations to Laravel rules.
     */
    private function rules(): array
    {
        $map = config('eav.validations', []);
        $rules = [];

        foreach ($this->attribute->getAttribute('validations') ?? [] as $validation) {
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
