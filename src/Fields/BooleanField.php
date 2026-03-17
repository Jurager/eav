<?php

namespace Jurager\Eav\Fields;

/**
 * Boolean attribute field with tolerant input parsing.
 */
class BooleanField extends Field
{
    public function getStorageColumn(): string
    {
        return self::STORAGE_BOOLEAN;
    }

    public function getValue(?int $localeId = null): ?bool
    {
        $raw = parent::getValue($localeId);

        if ($raw === null) {
            return null;
        }

        return (bool) $raw;
    }

    /**
     * @return array<string, bool>
     */
    public function getIndexData(): array
    {
        $code = $this->getCode();

        if (!$this->isLocalizable()) {
            return [$code => $this->getValue() ?? false];
        }

        $values = array_values(array_filter(
            array_map(fn (array $item) => isset($item['value']) ? (bool) $item['value'] : null, $this->values),
            fn ($v) => $v !== null
        ));

        return $values ? [$code => $values] : [];
    }

    protected function validateValue(mixed $value): bool
    {
        if ($value === null) {
            return true;
        }

        if (is_bool($value)) {
            return true;
        }

        if (($value === 0 || $value === 1)) {
            return true;
        }

        if (is_string($value)) {
            $lower = strtolower($value);
            if (in_array($lower, ['0', '1', 'true', 'false', 'yes', 'no', 'on', 'off'], true)) {
                return true;
            }
        }

        return $this->addError(__('eav::attributes.validation.invalid_value'));
    }

    protected function processValue(mixed $value): int
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
    }
}
