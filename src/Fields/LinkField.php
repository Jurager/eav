<?php

namespace Jurager\Eav\Fields;

/**
 * URL field limited to absolute HTTP/HTTPS links.
 */
class LinkField extends Field
{
    public function column(): string
    {
        return self::STORAGE_TEXT;
    }

    protected function validate(mixed $value): bool
    {
        if ($value === null) {
            return true;
        }

        if (! is_string($value)) {
            return $this->addError(__('eav::attributes.validation.invalid_value'));
        }

        $parsed = parse_url($value);

        if ($parsed === false || ! isset($parsed['scheme'], $parsed['host'])) {
            return $this->addError(__('eav::attributes.validation.invalid_url'));
        }

        if (! in_array(strtolower($parsed['scheme']), ['http', 'https'], true)) {
            return $this->addError(__('eav::attributes.validation.invalid_url'));
        }

        return true;
    }

    protected function normalize(mixed $value): string
    {
        return (string) $value;
    }
}
