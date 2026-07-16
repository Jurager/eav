<?php

declare(strict_types=1);

namespace Jurager\Eav\Fields;

use Jurager\Eav\Contracts\Attributable;

class Textarea extends Field
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

        return true;
    }

    /** Normalize the field value to a string. */
    protected function normalize(mixed $value): string
    {
        return (string) $value;
    }
}
