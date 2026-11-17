<?php

declare(strict_types=1);

namespace Jurager\Eav\Search\Index;

use BackedEnum;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Jurager\Eav\Attributes\EntityValues;
use Jurager\Eav\Contracts\Attributable;
use Jurager\Eav\Enums\IndexType;
use Jurager\Eav\Fields\FieldFactory;
use Jurager\Eav\Scopes\ActiveLocaleScope;
use WeakMap;

/**
 * Build the search document of an entity from its index definition.
 */
class Document
{
    /** @var WeakMap<Model, true> Entities whose loaded values were prepared for indexing. */
    private WeakMap $prepared;

    public function __construct(
        private readonly IndexFactory $indexes,
        private readonly FieldFactory $fields,
        private readonly Container $container,
    ) {
        $this->prepared = new WeakMap;
    }

    /**
     * Mark entities as carrying their values in every locale, loaded for indexing.
     *
     * @param  iterable<Model>  $entities
     */
    public function prepared(iterable $entities): void
    {
        foreach ($entities as $entity) {
            $this->prepared[$entity] = true;
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function build(Model $entity): array
    {
        $document = [];

        foreach ($this->indexes->for($entity)->declared() as $field) {
            $value = $field->value !== null ? ($field->value)($entity) : $entity->getAttribute($field->path);

            self::put($document, $field->path, self::normalize($value, $field->type));
        }

        if ($entity instanceof Attributable) {
            foreach ($this->values($entity)->groupBy('attribute_id') as $rows) {
                $attribute = $this->fields->make($rows->first()->attribute)->forEntity($entity);
                $attribute->hydrate($rows);

                foreach ($attribute->indexFields() as $field) {
                    self::put($document, $field->path, ($field->value)($entity));
                }
            }
        }

        return $document;
    }

    /**
     * Value rows of the entity's indexed attributes, including those it inherits.
     *
     * @return Collection<int, Model>
     */
    private function values(Attributable $entity): Collection
    {
        $values = $this->container->make(EntityValues::class, ['entity' => $entity]);

        // Values loaded by anyone else may hold the active locale only: read those from the database instead.
        if ($entity instanceof Model && isset($this->prepared[$entity]) && ($loaded = $values->loaded()) !== null) {
            return $loaded
                ->filter(static fn (Model $value): bool => $value->attribute !== null && Synchronizer::indexed($value->attribute))
                ->values();
        }

        return $values->query()
            ->whereHas('attribute', static fn ($query) => $query->whereAny(Synchronizer::FLAGS, true))
            ->with([
                'attribute',
                'attribute.enums.translations' => static fn ($query) => $query->withoutGlobalScope(ActiveLocaleScope::class),
                'translations' => static fn ($query) => $query->withoutGlobalScope(ActiveLocaleScope::class),
            ])
            ->get();
    }

    /**
     * Set a value in the document unless there is none.
     *
     * @param  array<string, mixed>  $document
     */
    private static function put(array &$document, string $path, mixed $value): void
    {
        if ($value !== null && $value !== '' && $value !== []) {
            Arr::set($document, $path, $value);
        }
    }

    /**
     * Bring a declared field's value to what the index stores for its type.
     */
    private static function normalize(mixed $value, IndexType $type): mixed
    {
        if ($value instanceof Arrayable) {
            $value = $value->toArray();
        }

        if (is_array($value)) {
            // Lists are cast item by item; maps (e.g. per-locale values) are kept as they are.
            return array_is_list($value)
                ? array_values(array_filter(array_map(static fn (mixed $item) => is_array($item) ? $item : $type->cast($item), $value), static fn (mixed $item): bool => $item !== null))
                : $value;
        }

        return $type->cast($value instanceof BackedEnum ? $value->value : $value);
    }
}
