<?php

declare(strict_types=1);

namespace Jurager\Eav\Fields;

use Jurager\Eav\Contracts\Attributable;

class Link extends Field
{
    /** Get the storage column name. */
    public function column(): string
    {
        return self::STORAGE_TEXT;
    }

    /** Validate the field value. */
    protected function validate(mixed $value, ?Attributable $entity = null): bool
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

    /** Normalize the field value to a string. */
    protected function normalize(mixed $value): string
    {
        return (string) $value;
    }
}
