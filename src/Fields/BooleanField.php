<?php

namespace Jurager\Eav\Fields;

/**
 * Boolean attribute field with tolerant input parsing.
 */
class BooleanField extends Field
{
    public function column(): string
    {
        return self::STORAGE_BOOLEAN;
    }

    public function value(?int $localeId = null): ?bool
    {
        $raw = parent::value($localeId);

        if ($raw === null) {
            return null;
        }

        return (bool) $raw;
    }

    /**
     * @return array<string, bool>
     */
    public function indexData(): array
    {
        $code = $this->code();

        if (! $this->isLocalizable()) {
            return [$code => $this->value() ?? false];
        }

        $values = array_values(array_filter(
            array_map(fn (array $item) => isset($item['value']) ? (bool) $item['value'] : null, $this->values),
            fn ($v) => $v !== null
        ));

        return $values ? [$code => $values] : [];
    }

    protected function validate(mixed $value): bool
    {
        if ($value === null) {
            return true;
        }

        if (is_bool($value) || $value === 0 || $value === 1) {
            return true;
        }

        if (is_string($value)) {
            if (in_array(strtolower($value), ['0', '1', 'true', 'false', 'yes', 'no', 'on', 'off'], true)) {
                return true;
            }
        }

        return $this->addError(__('eav::attributes.validation.invalid_value'));
    }

    protected function normalize(mixed $value): int
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
    }
}
