<?php

declare(strict_types=1);

namespace Jurager\Eav\Tests\Fixtures;

use Illuminate\Foundation\Http\FormRequest;
use Jurager\Eav\Concerns\HasTranslationRules;

/**
 * A form request validating translations, with a short name besides the label.
 */
class TranslationsRequest extends FormRequest
{
    use HasTranslationRules;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return $this->translationRules(extra: ['short_name' => ['nullable', 'string', 'max:10']]);
    }

    public function after(): array
    {
        return [$this->noDuplicateLocales()];
    }
}
