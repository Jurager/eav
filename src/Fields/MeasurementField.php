<?php

namespace Jurager\Eav\Fields;

use Illuminate\Database\Eloquent\Model;
use Jurager\Eav\EavModels;

/**
 * Numeric measurement field that stores values normalized to standard unit.
 */
class MeasurementField extends Field
{
    private ?Model $cachedStandardUnit = null;
    private bool $standardUnitLoaded = false;

    public function getStorageColumn(): string
    {
        return self::STORAGE_FLOAT;
    }

    /**
     * Get measurement code attached to current attribute.
     */
    public function getUnit(): ?string
    {
        return $this->attribute->measurement?->code;
    }

    /**
     * Resolve standard unit for current measurement (cached per field instance).
     * Returns null if measurement_unit model is not configured in eav.models.
     */
    public function getStandardUnit(): ?Model
    {
        if (!$this->attribute->measurement_id || !EavModels::has('measurement_unit')) {
            return null;
        }

        if (!$this->standardUnitLoaded) {
            $this->cachedStandardUnit = EavModels::query('measurement_unit')
                ->where('measurement_id', $this->attribute->measurement_id)
                ->where('standard', true)
                ->first();
            $this->standardUnitLoaded = true;
        }

        return $this->cachedStandardUnit;
    }

    /**
     * Return value formatted with resolved unit code.
     */
    public function getFormatted(?int $localeId = null): ?string
    {
        $value = $this->getValue($localeId);

        if ($value === null) {
            return null;
        }

        $standardUnit = $this->getStandardUnit();
        $unit = $standardUnit?->code ?? $this->getUnit();

        return $unit ? "$value $unit" : (string) $value;
    }

    protected function validate(mixed $values): bool
    {
        if (!$this->isLocalizable() && !$this->isMultiple()) {
            return $this->validateValue($values);
        }

        if ($this->isMultiple() && !$this->isLocalizable()) {
            if (!is_array($values)) {
                return $this->addError(__('eav::attributes.validation.array_expected'));
            }
            return $this->validateMultipleValues($values);
        }

        if (!is_array($values)) {
            return $this->addError(__('eav::attributes.validation.translations_required'));
        }

        return $this->validateLocalizableValues($values);
    }

    protected function validateMultipleValues(array $values): bool
    {
        return array_all($values, fn ($value) => $this->validateValue($value));
    }

    protected function validateValue(mixed $value): bool
    {
        if ($value === null) {
            return true;
        }

        if (!is_array($value)) {
            return $this->addError(__('eav::attributes.validation.invalid_measurement_format'));
        }

        if (!isset($value['value'], $value['measurement_unit_id'])) {
            return $this->addError(__('eav::attributes.validation.measurement_fields_required'));
        }

        if (!is_numeric($value['value'])) {
            return $this->addError(__('eav::attributes.validation.invalid_value'));
        }

        if (!is_int($value['measurement_unit_id'])) {
            return $this->addError(__('eav::attributes.validation.invalid_measurement_unit_id'));
        }

        $measurementId = $this->attribute->measurement_id;
        if (!$measurementId) {
            return $this->addError(__('eav::attributes.validation.measurement_not_configured'));
        }

        if (!EavModels::has('measurement_unit')) {
            return $this->addError(__('eav::attributes.validation.measurement_not_configured'));
        }

        $unit = EavModels::query('measurement_unit')
            ->where('id', $value['measurement_unit_id'])
            ->where('measurement_id', $measurementId)
            ->first();

        if (!$unit) {
            return $this->addError(__('eav::attributes.validation.invalid_measurement_unit'));
        }

        return true;
    }

    protected function processValue(mixed $value): float
    {
        if (!isset($value['value'], $value['measurement_unit_id']) || !is_array($value)) {
            return 0.0;
        }

        $numericValue = (float) $value['value'];
        $unitId = $value['measurement_unit_id'];

        $unit         = EavModels::has('measurement_unit') ? EavModels::query('measurement_unit')->find($unitId) : null;
        $standardUnit = $this->getStandardUnit();

        if (!$unit || !$standardUnit) {
            return $numericValue;
        }

        // Convert to standard unit
        // Formula: standard_value = value * unit_coefficient / standard_coefficient
        return $numericValue * $unit->coefficient / $standardUnit->coefficient;
    }
}
