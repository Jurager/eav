<?php

declare(strict_types=1);

namespace Jurager\Eav\Concerns;

use Closure;

/**
 * Validate the translations of a model with HasTranslations in a form request.
 */
trait HasTranslationRules
{
    /**
     * Rules for `translations`: existing locales and at least one non-empty label.
     * $extra sets the rules of other fields of each translation, or replaces those of `label`.
     *
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    protected function translationRules(bool $required = true, array $extra = []): array
    {
        $rules = [
            'translations' => [
                $required ? 'required' : 'nullable',
                'array',
                function ($attribute, $value, $fail) {
                    if (! empty($value) && ! collect($value)->contains(fn ($t) => filled($t['label'] ?? null))) {
                        $fail(__('eav::translations.validation.label_required'));
                    }
                },
            ],
            'translations.*.locale_id' => ['required_with:translations', 'integer', 'exists:locales,id'],
            'translations.*.label' => ['nullable', 'string', 'max:255'],
        ];

        foreach ($extra as $field => $fieldRules) {
            $rules["translations.*.$field"] = $fieldRules;
        }

        return $rules;
    }

    /**
     * A validation hook rejecting two translations for the same locale.
     */
    protected function noDuplicateLocales(): Closure
    {
        return function ($validator) {
            if (! $this->has('translations')) {
                return;
            }

            $localeIds = collect($this->input('translations'))->pluck('locale_id');

            if ($localeIds->duplicates()->isNotEmpty()) {
                $validator->errors()->add('translations', __('eav::translations.validation.duplicate_locale'));
            }
        };
    }
}
