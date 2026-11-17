<?php

declare(strict_types=1);

namespace Jurager\Eav\Search\Index;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Jurager\Eav\Enums\IndexType;
use Jurager\Eav\Models\Attribute;

/**
 * One field of a search index: where it lives in the document and what the engine may do with it.
 */
class IndexField
{
    public IndexType $type = IndexType::Text;

    public bool $searchable = false;

    public bool $filterable = false;

    public bool $sortable = false;

    /**
     * Whether the field holds one value per locale, under `path.{locale}`.
     */
    public bool $localized = false;

    /**
     * Whether the field holds a map of values, each of them a field of its own at `path.{key}`.
     */
    public bool $keyed = false;

    /**
     * The attribute the field is built from; null for a field the model declares.
     */
    public ?Attribute $attribute = null;

    /**
     * Computes the value; a localized field's is a map keyed by locale code. Null reads the column named by the path.
     *
     * @var (Closure(Model): mixed)|null
     */
    public ?Closure $value = null;

    /** @var list<string> Names the field also answers to in filters, sorts and facets. */
    public array $aliases = [];

    /** @var list<string> Relations the value is read from. */
    public array $relations = [];

    /** @var list<string>|null Columns whose change alters the value; null means the path itself. */
    public ?array $columns = null;

    public function __construct(public readonly string $path) {}

    public function type(IndexType $type): static
    {
        $this->type = $type;

        return $this;
    }

    public function text(): static
    {
        return $this->type(IndexType::Text);
    }

    public function keyword(): static
    {
        return $this->type(IndexType::Keyword);
    }

    public function number(): static
    {
        return $this->type(IndexType::Number);
    }

    public function date(): static
    {
        return $this->type(IndexType::Date);
    }

    public function boolean(): static
    {
        return $this->type(IndexType::Boolean);
    }

    public function searchable(bool $value = true): static
    {
        $this->searchable = $value;

        return $this;
    }

    public function filterable(bool $value = true): static
    {
        $this->filterable = $value;

        return $this;
    }

    public function sortable(bool $value = true): static
    {
        $this->sortable = $value;

        return $this;
    }

    public function localized(bool $value = true): static
    {
        $this->localized = $value;

        return $this;
    }

    /**
     * The value is a map, such as prices by price type: each key can be filtered, sorted and faceted on
     * as `path.{key}`, with the type and abilities of this field.
     */
    public function keyed(bool $value = true): static
    {
        $this->keyed = $value;

        return $this;
    }

    /**
     * The field of one key of a keyed field.
     */
    public function key(string $key): static
    {
        $field = new static("{$this->path}.{$key}");
        $field->type = $this->type;
        $field->searchable = $this->searchable;
        $field->filterable = $this->filterable;
        $field->sortable = $this->sortable;
        $field->attribute = $this->attribute;

        return $field;
    }

    /**
     * Compute the value instead of reading the column named by the path.
     *
     * @param  Closure(Model): mixed  $value
     */
    public function using(Closure $value): static
    {
        $this->value = $value;

        return $this;
    }

    /**
     * Other names the field answers to in filters, sorts and facets.
     */
    public function alias(string ...$aliases): static
    {
        $this->aliases = [...$this->aliases, ...$aliases];

        return $this;
    }

    /**
     * Relations to eager load before the value is read.
     */
    public function with(string ...$relations): static
    {
        $this->relations = [...$this->relations, ...$relations];

        return $this;
    }

    /**
     * Columns whose change means the document must be rebuilt.
     */
    public function watch(string ...$columns): static
    {
        $this->columns = [...($this->columns ?? []), ...$columns];

        return $this;
    }

    /**
     * The path the engine sees: the field's own, or its slot for one locale.
     */
    public function pathFor(?string $locale = null): string
    {
        return $this->localized && $locale !== null ? "{$this->path}.{$locale}" : $this->path;
    }

    /**
     * Every path the field occupies in a document.
     *
     * @param  list<string>  $locales
     * @return list<string>
     */
    public function paths(array $locales): array
    {
        return $this->localized
            ? array_map(fn (string $locale): string => "{$this->path}.{$locale}", $locales)
            : [$this->path];
    }

    /**
     * Columns of the model the value depends on.
     *
     * @return list<string>
     */
    public function watched(): array
    {
        if ($this->attribute !== null) {
            return [];
        }

        return $this->columns ?? ($this->value === null ? [$this->path] : []);
    }
}
