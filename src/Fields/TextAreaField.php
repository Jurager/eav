<?php

namespace Jurager\Eav\Fields;

use Jurager\Eav\Contracts\Attributable;

/**
 * Free-form long text field.
 */
class TextAreaField extends Field
{
    public function column(): string
    {
        return self::STORAGE_TEXT;
    }

    protected function validate(mixed $value, ?Attributable $entity = null): bool
    {
        if ($value === null) {
            return true;
        }

        if (! is_string($value)) {
            return $this->addError(__('eav::attributes.validation.invalid_value'));
        }

        return true;
    }

    protected function normalize(mixed $value): string
    {
        return (string) $value;
    }
}
