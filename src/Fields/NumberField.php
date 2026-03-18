<?php

namespace Jurager\Eav\Fields;

/**
 * Numeric field with integer/float normalization.
 */
class NumberField extends Field
{
    public function column(): string
    {
        return self::STORAGE_FLOAT;
    }

    protected function validate(mixed $value): bool
    {
        if (! is_numeric($value)) {
            return $this->addError(__('eav::attributes.validation.invalid_value'));
        }

        return true;
    }

    protected function normalize(mixed $value): int|float
    {
        $numeric = $value + 0;

        return is_int($numeric) || floor($numeric) === $numeric
            ? (int) $numeric
            : (float) $numeric;
    }
}
