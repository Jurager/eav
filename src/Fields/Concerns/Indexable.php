<?php

declare(strict_types=1);

namespace Jurager\Eav\Fields\Concerns;

use Closure;
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
     * Index fields the attribute contributes, each computing its own value.
     *
     * @return list<IndexField>
     */
    public function indexFields(): array
    {
        return [
            $this->indexField(Index::ATTRIBUTES)
                ->localized($this->isLocalizable())
                ->searchable($this->isSearchable())
                ->filterable($this->isFilterable())
                ->sortable($this->isSortable() && ! $this->isMultiple()),
        ];
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
     * Start an index field of this attribute at `{root}.{code}`, holding `toIndex()` unless given another value.
     *
     * @param  (Closure(): mixed)|null  $value
     */
    protected function indexField(string $root, ?Closure $value = null): IndexField
    {
        $field = new IndexField($root.'.'.$this->code());
        $field->attribute = $this->attribute;

        return $field
            ->type($this->indexType())
            ->using($value ?? fn (): mixed => $this->isLocalizable() ? $this->perLocale($this->toIndex(...)) : $this->toIndex());
    }

    /**
     * Collect a value for every locale that has one, keyed by locale code.
     *
     * @param  Closure(int): mixed  $value
     * @return array<string, mixed>
     */
    protected function perLocale(Closure $value): array
    {
        $values = [];

        foreach ($this->localeRegistry->ids() as $localeId) {
            $code = $this->localeRegistry->code($localeId);
            $item = $value($localeId);

            if ($code !== null && $item !== null && $item !== '' && $item !== []) {
                $values[$code] = $item;
            }
        }

        return $values;
    }
}
