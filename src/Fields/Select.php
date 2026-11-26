<?php

declare(strict_types=1);

namespace Jurager\Eav\Fields;

use Jurager\Eav\Contracts\Attributable;
use Jurager\Eav\Enums\AttributeStorage;
use Jurager\Eav\Enums\IndexType;
use Jurager\Eav\Models\AttributeEnum;
use Jurager\Eav\Search\Index\Index;
use Jurager\Eav\Search\Index\IndexField;

/**
 * Field for enum-backed select, storing selected ID in an integer column.
 */
class Select extends Field
{
    /**
     * Get the storage column name.
     */
    public function column(): AttributeStorage
    {
        return AttributeStorage::Integer;
    }

    /**
     * A select stores an option id; its labels are localized on the option, never the value.
     */
    public static function localizable(): bool
    {
        return false;
    }

    /**
     * Check if this field supports enums.
     */
    public function isEnum(): bool
    {
        return true;
    }

    /**
     * Get the current value as an int or array of ints.
     */
    public function value(?int $localeId = null): int|array|null
    {
        $raw = $this->values[self::UNLOCALIZED] ?? null;

        return match (true) {
            $raw === null => null,
            is_array($raw) => array_map('intval', $raw),
            default => (int) $raw,
        };
    }

    /**
     * Get the selected enum instance.
     */
    public function enum(?int $localeId = null): ?AttributeEnum
    {
        $enumId = $this->value($localeId);

        if ($enumId === null || is_array($enumId)) {
            return null;
        }

        return $this->enumRegistry->find($this->attribute->getAttribute('id'), $enumId);
    }

    /**
     * Get all selected enum instances.
     */
    public function enums(?int $localeId = null): array
    {
        $value = $this->value($localeId);

        if ($value === null) {
            return [];
        }

        return $this->enumRegistry->all($this->attribute->getAttribute('id'))
            ->whereIn('id', (array) $value)
            ->values()
            ->all();
    }

    /**
     * Get the label for the current value(s).
     */
    public function label(?int $localeId = null): string|array|null
    {
        $localeId ??= $this->localeRegistry->current();

        if ($this->isMultiple()) {
            return array_map(
                fn (AttributeEnum $enum) => $enum->label($localeId),
                $this->enums()
            );
        }

        return $this->enum()?->label($localeId);
    }

    public function indexType(): IndexType
    {
        return IndexType::Keyword;
    }

    /**
     * Options are indexed by code; their labels go to a separate localized field.
     *
     * @return string|list<string>|null
     */
    public function toIndex(?int $localeId = null): string|array|null
    {
        if ($this->isMultiple()) {
            return array_values(array_map(static fn (AttributeEnum $enum): string => (string) $enum->getAttribute('code'), $this->enums()));
        }

        $code = $this->enum()?->getAttribute('code');

        return $code === null ? null : (string) $code;
    }

    /**
     * The option code, filterable and sortable, plus its labels per locale for full-text search.
     *
     * @return list<IndexField>
     */
    public function indexFields(): array
    {
        return [
            $this->indexField(Index::ATTRIBUTES)
                ->filterable($this->isFilterable())
                ->sortable($this->isSortable() && ! $this->isMultiple()),
            $this->indexField(Index::LABELS, fn (): array => $this->perLocale($this->indexLabel(...)))
                ->text()
                ->localized()
                ->searchable($this->isSearchable()),
        ];
    }

    /**
     * Labels of the selected options in one locale.
     *
     * @return string|list<string>|null
     */
    protected function indexLabel(int $localeId): string|array|null
    {
        $labels = array_values(array_filter((array) $this->label($localeId), static fn ($label): bool => $label !== null && $label !== ''));

        return $this->isMultiple() ? $labels : ($labels[0] ?? null);
    }

    /**
     * Option labels for facet values, falling back to the option code.
     *
     * @param  list<string>  $values
     * @return array<string, string>
     */
    public function facetLabels(array $values, int $localeId): array
    {
        $enums = $this->enumRegistry->all($this->attribute->getAttribute('id'))->keyBy('code');
        $labels = [];

        foreach ($values as $code) {
            $labels[$code] = $enums->get($code)?->label($localeId) ?? $code;
        }

        return $labels;
    }

    /**
     * Validate the field value.
     */
    protected function validate(mixed $value, ?Attributable $entity = null): bool
    {
        if ($value === null) {
            return true;
        }

        $values = (array) $value;

        foreach ($values as $item) {
            $id = $this->enumRegistry->coerce($this->attribute->getAttribute('id'), $item);

            if (! is_numeric($id)) {
                return $this->addError(__('eav::attributes.validation.invalid_value'));
            }

            if (! $this->enumRegistry->isValidId($this->attribute->getAttribute('id'), (int) $id)) {
                return $this->addError(__('eav::attributes.validation.invalid_enum'));
            }
        }

        return true;
    }

    /**
     * Normalize the option id or code to the option id.
     */
    protected function normalize(mixed $value): ?int
    {
        return $value === null ? null : (int) $this->enumRegistry->coerce($this->attribute->getAttribute('id'), $value);
    }
}
