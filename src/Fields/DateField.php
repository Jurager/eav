<?php

namespace Jurager\Eav\Fields;

use Carbon\Carbon;
use Carbon\Exceptions\InvalidFormatException;
use Exception;

/**
 * Date/time field stored as datetime and exposed as Carbon instances.
 */
class DateField extends Field
{
    public function column(): string
    {
        return self::STORAGE_DATETIME;
    }

    /**
     * @return Carbon|array<int, Carbon>|null
     */
    public function value(?int $localeId = null): Carbon|array|null
    {
        $raw = parent::value($localeId);

        if ($raw === null) {
            return null;
        }

        try {
            if (is_array($raw)) {
                return array_filter(array_map(fn (mixed $value) => $this->parseDate($value), $raw));
            }

            return $this->parseDate($raw);
        } catch (Exception) {
            return null;
        }
    }

    /**
     * Return the date formatted as a string (or array of strings for multiple values).
     *
     * @return string|array<int, string>|null
     */
    public function format(string $format = 'Y-m-d', ?int $localeId = null): string|array|null
    {
        $value = $this->value($localeId);

        if ($value === null) {
            return null;
        }

        if (is_array($value)) {
            return array_map(
                static fn (Carbon $date): string => $date->format($format),
                $value
            );
        }

        return $value->format($format);
    }

    public function indexData(): array
    {
        $code = $this->code();

        if (! $this->isLocalizable()) {
            $value = $this->toTimestamp($this->value());

            return $value !== null ? [$code => $value] : [];
        }

        $values = array_values(array_filter(
            array_map(
                fn (array $item) => $this->toTimestamp($this->parseDate($item['value'])),
                $this->values
            ),
            fn ($v) => $v !== null
        ));

        return $values ? [$code => $values] : [];
    }

    protected function parseDate(mixed $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (InvalidFormatException) {
            return null;
        }
    }

    protected function validateValue(mixed $value): bool
    {
        if ($value === null || $value === '') {
            return true;
        }

        if ($value instanceof Carbon) {
            return true;
        }

        if (! is_string($value)) {
            return $this->addError(__('eav::attributes.validation.invalid_value'));
        }

        try {
            Carbon::parse($value);

            return true;
        } catch (InvalidFormatException) {
            return $this->addError(__('eav::attributes.validation.invalid_date'));
        } catch (Exception) {
            return $this->addError(__('eav::attributes.validation.invalid_value'));
        }
    }

    protected function processValue(mixed $value): ?string
    {
        if ($value instanceof Carbon) {
            return $value->toDateTimeString();
        }

        return $this->parseDate($value)?->toDateTimeString();
    }

    protected function toTimestamp(Carbon|array|null $value): int|array|null
    {
        if ($value === null) {
            return null;
        }

        if (is_array($value)) {
            return array_map(static fn (Carbon $date): int => $date->timestamp, $value);
        }

        return $value->timestamp;
    }
}
