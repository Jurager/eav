<?php

declare(strict_types=1);

namespace Jurager\Eav\Fields;

use Jurager\Eav\Contracts\Attributable;

class Number extends Field
{
    /** Get the storage column name. */
    public function column(): string
    {
        return self::STORAGE_FLOAT;
    }

    /** Validate the field value. */
    protected function validate(mixed $value, ?Attributable $entity = null): bool
    {
        if ($value === null) {
            return true;
        }

        if (! is_numeric($value)) {
            return $this->addError(__('eav::attributes.validation.invalid_value'));
        }

        return true;
    }

    /** Normalize the field value to an int or float. */
    protected function normalize(mixed $value): int|float
    {
        $numeric = $value + 0;

        return ((float) $numeric === floor($numeric))
            ? (int) $numeric
            : (float) $numeric;
    }
}
