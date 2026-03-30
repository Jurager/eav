<?php

namespace Jurager\Eav\Fields;

use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Contracts\Container\CircularDependencyException;
use Jurager\Eav\Models\Attribute;
use Jurager\Eav\Models\AttributeEnum;
use Jurager\Eav\Registry\EnumRegistry;
use Jurager\Eav\Registry\LocaleRegistry;

/**
 * Enum-backed select field storing selected enum IDs in integer column.
 */
class SelectField extends Field
{
    protected EnumRegistry $enumRegistry;

    public function __construct(Attribute $attribute, ?LocaleRegistry $localeRegistry = null, ?EnumRegistry $enumRegistry = null)
    {
        parent::__construct($attribute, $localeRegistry);

        $this->enumRegistry = $enumRegistry ?? app(EnumRegistry::class);
    }

    public function column(): string
    {
        return self::STORAGE_INTEGER;
    }

    /**
     * SelectField always stores enum_id in value column without translations.
     */
    public function toStorage(): array
    {
        $value = $this->values[0]['value'] ?? null;
        $items = is_array($value) ? $value : [$value];

        return array_map(static fn ($v) => ['value' => $v, 'translations' => []], $items);
    }

    /**
     * Return the stored enum ID(s). Ignores $localeId as enums are not locale-scoped.
     */
    public function value(?int $localeId = null): int|array|null
    {
        $raw = $this->values[0]['value'] ?? null;

        return match (true) {
            $raw === null  => null,
            is_array($raw) => array_map('intval', $raw),
            default        => (int) $raw,
        };
    }

    /**
     * Return the AttributeEnum model for the current single-select value.
     */
    public function enum(?int $localeId = null): ?AttributeEnum
    {
        $enumId = $this->value($localeId);

        if ($enumId === null || is_array($enumId)) {
            return null;
        }

        return $this->attribute->enums->firstWhere('id', $enumId);
    }

    /**
     * Return AttributeEnum models for the current multi-select values.
     *
     * @return AttributeEnum[]
     */
    public function enums(?int $localeId = null): array
    {
        $value = $this->value($localeId);

        if ($value === null) {
            return [];
        }

        return $this->attribute->enums->whereIn('id', (array) $value)->values()->all();
    }

    /**
     * Return the translated label(s) for the current value.
     */
    public function label(?int $localeId = null): string|array|null
    {
        $localeId ??= $this->localeRegistry->defaultLocaleId();

        if ($this->isMultiple()) {
            return array_map(
                fn (AttributeEnum $enum) => $this->enumLabel($enum, $localeId),
                $this->enums($localeId)
            );
        }

        return $this->enum($localeId) !== null
            ? $this->enumLabel($this->enum($localeId), $localeId)
            : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function indexData(): array
    {
        $code  = $this->code();
        $value = $this->value();

        if ($value === null) {
            return [];
        }

        $result = [
            $code          => $value,
            "{$code}_code" => $this->isMultiple()
                ? array_map(static fn (AttributeEnum $e) => $e->code, $this->enums())
                : $this->enum()?->code,
        ];

        $labels = array_values(array_unique(array_filter(
            array_merge(...array_map(
                fn (int $localeId) => (array) $this->label($localeId),
                array_keys($this->localeRegistry->localeCodes())
            )),
            static fn ($label) => $label !== null && $label !== ''
        )));

        if ($labels) {
            $result["{$code}_label"] = $labels;
        }

        return $result;
    }

    /**
     * SelectField stores enum_id directly in value column,
     * ignoring attribute localizable flag (labels are translated in enums table).
     */
    protected function validatePayload(mixed $values): bool
    {
        if (! $this->isMultiple()) {
            return $this->validate($values);
        }

        if (! is_array($values)) {
            return $this->addError(__('eav::attributes.validation.array_expected'));
        }

        return $this->validateMultipleValues($values);
    }

    /**
     * @throws CircularDependencyException
     * @throws BindingResolutionException
     */
    private function validateMultipleValues(array $values): bool
    {
        foreach ($values as $value) {
            if (is_array($value)) {
                return $this->addError(__('eav::attributes.validation.invalid_format'));
            }

            if (! is_numeric($value)) {
                return $this->addError(__('eav::attributes.validation.invalid_value'));
            }
        }

        $validIds = $this->enumRegistry->resolve($this->attribute->id);
        $invalid  = array_filter(array_map('intval', $values), static fn ($id) => ! isset($validIds[$id]));

        if (! empty($invalid)) {
            return $this->addError(__('eav::attributes.validation.invalid_enum'));
        }

        return true;
    }

    /**
     * @param  array<int, int|string>|int|string  $values
     * @return array<int, array{locale_id: int|null, value: int|array<int, int>}>
     */
    protected function normalizeValues(array|string|int $values): array
    {
        $normalized = is_array($values)
            ? array_map(fn ($v) => $this->normalize($v), $values)
            : $this->normalize($values);

        return [['locale_id' => null, 'value' => $normalized]];
    }

    /**
     * @throws CircularDependencyException
     * @throws BindingResolutionException
     */
    protected function validate(mixed $value): bool
    {
        if ($value === null) {
            return true;
        }

        if (! is_numeric($value)) {
            return $this->addError(__('eav::attributes.validation.invalid_value'));
        }

        if (! isset($this->enumRegistry->resolve($this->attribute->id)[(int) $value])) {
            return $this->addError(__('eav::attributes.validation.invalid_enum'));
        }

        return true;
    }

    private function enumLabel(AttributeEnum $enum, int $localeId): ?string
    {
        return $enum->translations
            ->first(fn ($t) => $t->pivot->locale_id === $localeId)
            ?->pivot
            ?->label;
    }

    protected function normalize(mixed $value): int
    {
        return (int) $value;
    }
}
