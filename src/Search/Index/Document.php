<?php

declare(strict_types=1);

namespace Jurager\Eav\Search\Index;

use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Jurager\Eav\Attributes\EntityValues;
use Jurager\Eav\Contracts\Attributable;
use Jurager\Eav\Enums\IndexType;
use Jurager\Eav\Fields\FieldFactory;

/**
 * Build the search document of an entity from its index definition.
 */
class Document
{
    public function __construct(
        private readonly IndexFactory $indexes,
        private readonly FieldFactory $fields,
        private readonly Container $container,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function build(Model $entity): array
    {
        $document = [];

        foreach ($this->indexes->for($entity)->declared() as $field) {
            $value = $field->path === 'id'
                ? $entity->getScoutKey()
                : ($field->value !== null ? ($field->value)($entity) : $entity->getAttribute($field->path));

            if (($value = self::normalize($value, $field->type)) !== null) {
                Arr::set($document, $field->path, $value);
            }
        }

        if ($entity instanceof Attributable && ($attributes = $this->attributes($entity)) !== []) {
            $document[Index::ATTRIBUTES] = $attributes;
        }

        return $document;
    }

    /**
     * Indexed attribute values of the entity, including those it inherits.
     *
     * @return array<string, mixed>
     */
    private function attributes(Attributable $entity): array
    {
        $values = $this->container->make(EntityValues::class, ['entity' => $entity]);
        $attributes = [];

        foreach ($values->indexable()->groupBy('attribute_id') as $rows) {
            $field = $this->fields->make($rows->first()->attribute)->forEntity($entity);
            $field->hydrate($rows);

            $attributes += $field->indexData();
        }

        return $attributes;
    }

    /**
     * Bring a declared field's value to what the index stores for its type.
     */
    private static function normalize(mixed $value, IndexType $type): mixed
    {
        if ($value instanceof \Illuminate\Contracts\Support\Arrayable) {
            $value = $value->toArray();
        }

        if (is_array($value)) {
            // Lists are cast item by item; maps (e.g. per-locale values) are kept as they are.
            return array_is_list($value)
                ? array_values(array_filter(array_map(static fn (mixed $item) => is_array($item) ? $item : $type->cast($item), $value), static fn (mixed $item): bool => $item !== null))
                : $value;
        }

        if ($value instanceof \BackedEnum) {
            $value = $value->value;
        }

        return $type->cast($value);
    }
}
