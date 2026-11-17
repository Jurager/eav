<?php

declare(strict_types=1);

namespace Jurager\Eav\Fields\Concerns;

use Jurager\Eav\Enums\IndexType;
use Jurager\Eav\Fields\Field;
use Jurager\Eav\Search\Index\Index;
use Jurager\Eav\Search\Index\IndexField;

/**
 * How a field appears in the search index.
 *
 * A custom field usually overrides only `indexType()` and `toIndex()`.
 *
 * @phpstan-require-extends Field
 */
trait Indexable
{
    /**
     * How the engine treats the value: full text, exact keyword, number, date or boolean.
     */
    public function indexType(): IndexType
    {
        return IndexType::Text;
    }

    /**
     * The value as the index stores it, in one locale for a localizable field.
     */
    public function toIndex(?int $localeId = null): mixed
    {
        return $this->value($localeId);
    }

    /**
     * Index fields the attribute contributes.
     *
     * @return list<IndexField>
     */
    public function indexFields(): array
    {
        return [
            $this->indexField($this->code(), $this->indexType())
                ->localized($this->isLocalizable())
                ->searchable($this->isSearchable())
                ->filterable($this->isFilterable())
                ->sortable($this->isSortable() && ! $this->isMultiple()),
        ];
    }

    /**
     * Values for the index document, keyed by attribute code; localized values are keyed by locale code.
     *
     * @return array<string, mixed>
     */
    public function indexData(): array
    {
        if (! $this->isLocalizable()) {
            $value = $this->toIndex();

            return self::blank($value) ? [] : [$this->code() => $value];
        }

        $values = [];

        foreach (array_keys($this->values) as $localeId) {
            $code = $this->localeRegistry->code($localeId);
            $value = $this->toIndex($localeId);

            if ($code !== null && ! self::blank($value)) {
                $values[$code] = $value;
            }
        }

        return $values === [] ? [] : [$this->code() => $values];
    }

    /**
     * Display labels for facet values, keyed by value.
     *
     * @param  list<string>  $values
     * @return array<string, string>
     */
    public function facetLabels(array $values, int $localeId): array
    {
        return [];
    }

    /**
     * Start an index field of this attribute, at `attributes.{name}`.
     */
    protected function indexField(string $name, IndexType $type): IndexField
    {
        $field = (new IndexField(Index::ATTRIBUTES.'.'.$name))->type($type);
        $field->attribute = $this->attribute;

        return $field;
    }

    private static function blank(mixed $value): bool
    {
        return $value === null || $value === '' || $value === [];
    }
}
