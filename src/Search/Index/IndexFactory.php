<?php

declare(strict_types=1);

namespace Jurager\Eav\Search\Index;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Jurager\Eav\Contracts\Attributable;
use Jurager\Eav\Eav;
use Jurager\Eav\Exceptions\SearchNotAvailableException;
use Jurager\Eav\Fields\FieldFactory;
use Jurager\Eav\Registries\AttributeRegistry;
use Jurager\Eav\Registries\LocaleRegistry;
use Laravel\Scout\Searchable;

/**
 * Build the search index definition of an entity type from its model and its attributes.
 */
class IndexFactory
{
    /** @var array<string, array{0: mixed, 1: Index}> Built indexes with the attribute definitions they were built from. */
    private array $indexes = [];

    public function __construct(
        private readonly AttributeRegistry $attributes,
        private readonly FieldFactory $fields,
        private readonly LocaleRegistry $locales,
    ) {}

    /**
     * Get the index of a model, its class, or its morph alias.
     *
     * @throws SearchNotAvailableException When the model is not searchable.
     */
    public function for(Model|string $entity): Index
    {
        $model = $this->model($entity) ?? throw SearchNotAvailableException::notSearchable(is_string($entity) ? $entity : $entity::class);

        $entityType = $model instanceof Attributable ? $model->getEntityType() : $model->getMorphClass();
        $locales = $this->locales->codes();
        $attributes = $model instanceof Attributable ? $this->attributes->all($entityType) : null;
        $key = $model::class.'|'.implode(',', $locales);

        // The registry hands out the same collection until the schema changes.
        if (isset($this->indexes[$key]) && $this->indexes[$key][0] === $attributes) {
            return $this->indexes[$key][1];
        }

        $index = new Index($model, $entityType, $locales);

        if (method_exists($model, 'searchIndex')) {
            $model->searchIndex($index);
        }

        if ($attributes !== null) {
            foreach ($attributes->sortBy(['sort', 'id']) as $attribute) {
                if (! Synchronizer::indexed($attribute)) {
                    continue;
                }

                foreach ($this->fields->make($attribute)->indexFields() as $field) {
                    if (! isset($index->fields()[$field->path])) {
                        $index->add($field);
                    }
                }
            }
        }

        $this->indexes[$key] = [$attributes, $index];

        return $index;
    }

    /**
     * Resolve a searchable model from an instance, a class, or a morph alias.
     */
    public function model(Model|string|null $entity): ?Model
    {
        if (is_string($entity)) {
            $class = Relation::getMorphedModel($entity) ?? $entity;
            $entity = class_exists($class) && is_subclass_of($class, Model::class) ? new $class : null;
        }

        return $entity !== null && self::searchable($entity) ? $entity : null;
    }

    /**
     * Entity types with a search index: searchable models in the morph map, and those holding attributes.
     *
     * @return list<string>
     */
    public function entityTypes(): array
    {
        $types = [
            ...array_keys(Relation::morphMap()),
            ...Eav::$attributeEntityTypeModel::query()->distinct()->pluck('entity_type')->all(),
        ];

        return array_values(array_filter(array_unique($types), fn (string $type): bool => $this->model($type) !== null));
    }

    /**
     * Whether Laravel Scout is installed — search is optional.
     */
    public static function available(): bool
    {
        return trait_exists(Searchable::class);
    }

    /**
     * Whether a model, or a model class, is indexed by Scout.
     */
    public static function searchable(mixed $model): bool
    {
        $class = is_object($model) ? $model::class : $model;

        return is_string($class)
            && self::available()
            && is_subclass_of($class, Model::class)
            && in_array(Searchable::class, class_uses_recursive($class), true);
    }
}
