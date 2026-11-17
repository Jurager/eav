<?php

declare(strict_types=1);

namespace Jurager\Eav\Search\Index;

use Illuminate\Database\Eloquent\Model;
use Jurager\Eav\Enums\IndexType;

/**
 * The shape of an entity type's search index: its fields and what each may be used for.
 *
 * Fields a model declares in `searchIndex()` come first; the entity's indexed attributes follow.
 */
class Index
{
    /**
     * The document field attribute values are nested under.
     */
    public const string ATTRIBUTES = 'attributes';

    /**
     * The document field display labels of attribute values are nested under, apart from the values themselves.
     */
    public const string LABELS = 'labels';

    /** @var array<string, IndexField> Fields keyed by path. */
    private array $fields = [];

    /** @var array<string, string>|null Field paths keyed by the names they answer to. */
    private ?array $names = null;

    private ?string $distinct = null;

    /** @var array<string, mixed> */
    private array $settings = [];

    /** @var list<string> */
    private array $relations = [];

    /**
     * @param  list<string>  $locales  Codes of the locales localized fields are indexed in.
     * @param  bool  $softDeletes  Whether trashed entities stay in the index, marked as such by Scout.
     */
    public function __construct(
        public readonly Model $model,
        public readonly string $entityType,
        private readonly array $locales = [],
        public readonly bool $softDeletes = false,
    ) {
        $key = $this->field('id')
            ->using(static fn (Model $model): mixed => $model->getScoutKey())
            ->watch()
            ->filterable()
            ->sortable();

        in_array($model->getKeyType(), ['int', 'integer'], true) ? $key->number() : $key->keyword();
    }

    /**
     * Declare a field, or get the one already declared at the path.
     */
    public function field(string $path): IndexField
    {
        return $this->fields[$path] ??= $this->add((new IndexField($path))->type($this->guess($path)));
    }

    /**
     * Add a prepared field.
     */
    public function add(IndexField $field): IndexField
    {
        $this->names = null;

        return $this->fields[$field->path] = $field;
    }

    /**
     * Keep one document per value of the field — e.g. one variant per parent.
     */
    public function distinct(?string $name): static
    {
        $this->distinct = $name;

        return $this;
    }

    /**
     * Engine settings of this index, on top of the configured ones.
     *
     * @param  array<string, mixed>  $settings
     */
    public function settings(array $settings): static
    {
        $this->settings = array_replace_recursive($this->settings, $settings);

        return $this;
    }

    /**
     * Relations to eager load before documents are built.
     */
    public function with(string ...$relations): static
    {
        $this->relations = [...$this->relations, ...$relations];

        return $this;
    }

    /**
     * Name of the index in the engine.
     */
    public function uid(): string
    {
        return $this->model->searchableAs();
    }

    /**
     * @return array<string, IndexField>
     */
    public function fields(): array
    {
        return $this->fields;
    }

    /**
     * Fields the model declares itself, as opposed to its attributes.
     *
     * @return list<IndexField>
     */
    public function declared(): array
    {
        return array_values(array_filter($this->fields, static fn (IndexField $field): bool => $field->attribute === null));
    }

    /**
     * Find a field by the name a request refers to it: its path, an alias, or the code of an attribute.
     */
    public function find(string $name): ?IndexField
    {
        if ($this->names === null) {
            $this->names = [];

            foreach ($this->fields as $path => $field) {
                $names = [$path, ...$field->aliases];

                if (str_starts_with($path, self::ATTRIBUTES.'.')) {
                    $names[] = substr($path, strlen(self::ATTRIBUTES) + 1);
                }

                foreach ($names as $alias) {
                    $this->names[$alias] ??= $path;
                }
            }
        }

        return isset($this->names[$name]) ? $this->fields[$this->names[$name]] : null;
    }

    /**
     * Document paths of the fields passing the test, in declaration order.
     *
     * @param  callable(IndexField): bool  $test
     * @param  list<string>|null  $locales  Locales to expand localized fields for; all when null.
     * @return list<string>
     */
    public function paths(callable $test, ?array $locales = null): array
    {
        $paths = [];

        foreach ($this->fields as $field) {
            if ($test($field)) {
                array_push($paths, ...$field->paths($locales ?? $this->locales));
            }
        }

        return array_values(array_unique($paths));
    }

    /**
     * @return list<string>
     */
    public function locales(): array
    {
        return $this->locales;
    }

    public function distinctField(): ?IndexField
    {
        return $this->distinct !== null ? $this->find($this->distinct) : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function ownSettings(): array
    {
        return $this->settings;
    }

    /**
     * Relations the declared fields are read from.
     *
     * @return list<string>
     */
    public function relations(): array
    {
        $relations = $this->relations;

        foreach ($this->fields as $field) {
            array_push($relations, ...$field->relations);
        }

        return array_values(array_unique($relations));
    }

    /**
     * Columns whose change alters the document.
     *
     * @return list<string>
     */
    public function watched(): array
    {
        $columns = [];

        foreach ($this->fields as $field) {
            array_push($columns, ...$field->watched());
        }

        return array_values(array_unique($columns));
    }

    /**
     * Guess a declared field's type from the model's casts.
     */
    private function guess(string $path): IndexType
    {
        $cast = strtolower((string) ($this->model->getCasts()[$path] ?? ''));

        return match (true) {
            in_array($cast, ['bool', 'boolean'], true) => IndexType::Boolean,
            in_array($cast, ['int', 'integer', 'float', 'double', 'real'], true), str_starts_with($cast, 'decimal') => IndexType::Number,
            str_contains($cast, 'date'), $cast === 'timestamp' => IndexType::Date,
            default => IndexType::Text,
        };
    }
}
