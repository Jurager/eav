<?php

namespace Jurager\Eav\Fields;

/**
 * Short text field with max length guard.
 */
class TextField extends Field
{
    public function column(): string
    {
        return self::STORAGE_TEXT;
    }

    protected function validate(mixed $value): bool
    {
        if (! is_string($value)) {
            return $this->addError(__('eav::attributes.validation.invalid_value'));
        }

        if (strlen($value) > 255) {
            return $this->addError(__('eav::attributes.validation.text_too_long'));
        }

        return true;
    }

    protected function normalize(mixed $value): string
    {
        return (string) $value;
    }
}
