<?php

declare(strict_types=1);

namespace Jurager\Eav\Fields;

use Jurager\Eav\Contracts\Attributable;
use Jurager\Eav\Enums\AttributeStorage;
use Jurager\Eav\Enums\IndexType;

/**
 * Boolean attribute field with tolerant input parsing.
 */
class Boolean extends Field
{
    public function column(): AttributeStorage
    {
        return AttributeStorage::Boolean;
    }

    public function cast(mixed $value): mixed
    {
        return $this->normalize($value);
    }

    public function value(?int $localeId = null): ?bool
    {
        $raw = parent::value($localeId);

        if ($raw === null) {
            return null;
        }

        return (bool) $raw;
    }

    public function indexType(): IndexType
    {
        return IndexType::Boolean;
    }

    /**
     * A boolean with a stored row is always indexed, so `false` can be filtered on.
     */
    public function toIndex(?int $localeId = null): ?bool
    {
        return array_key_exists($this->slot($localeId), $this->values) ? ($this->value($localeId) ?? false) : null;
    }

    protected function validate(mixed $value, ?Attributable $entity = null): bool
    {
        if ($value === null) {
            return true;
        }

        if (is_bool($value) || $value === 0 || $value === 1) {
            return true;
        }

        if (is_string($value) && in_array(strtolower($value), ['0', '1', 'true', 'false', 'yes', 'no', 'on', 'off'], true)) {
            return true;
        }

        return $this->addError(__('eav::attributes.validation.invalid_value'));
    }

    protected function normalize(mixed $value): int
    {
        if ($value === null) {
            return 0;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
    }
}
