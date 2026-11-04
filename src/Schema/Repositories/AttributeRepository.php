<?php

declare(strict_types=1);

namespace Jurager\Eav\Schema\Repositories;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Jurager\Eav\Attributes\Persistence\ValuePruner;
use Jurager\Eav\Eav;
use Jurager\Eav\Events\AttributeCreated;
use Jurager\Eav\Events\AttributeDeleted;
use Jurager\Eav\Events\AttributeUpdated;
use Jurager\Eav\Exceptions\FluentBuilderException;
use Jurager\Eav\Models\Attribute;
use Jurager\Eav\Schema\SchemaSynchronizer;
use Jurager\Eav\Translation\TranslationRepository;

class AttributeRepository extends Repository
{
    public function __construct(
        TranslationRepository $translations,
        Dispatcher $events,
        SchemaSynchronizer $sync,
        private readonly ValuePruner $pruner,
    ) {
        parent::__construct($translations, $events, $sync);
    }

    /**
     * Find an attribute by ID.
     */
    public function find(int $id): Attribute
    {
        /** @var Attribute */
        return $this->query()->findOrFail($id);
    }

    /**
     * Finds or creates an attribute and attaches the given entity types to it.
     */
    public function findOrCreate(array $entityTypes, string $code, array $data): Attribute
    {
        $attribute = $this->query()->where('code', $code)->first();

        if ($attribute) {
            if ($translations = $data['translations'] ?? []) {
                $this->translations->save($attribute, $translations);
            }

            if ($this->syncEntityTypes($attribute, $entityTypes, detaching: false)) {
                $this->sync->refresh($attribute);
            }

            return $attribute;
        }

        return $this->create($data);
    }

    /**
     * Create a new attribute, applicable to the given entity types.
     */
    public function create(array $data): Attribute
    {
        $translations = $this->extractTranslations($data);
        $type = Eav::$attributeTypeModel::query()->findOrFail($data['attribute_type_id']);

        $data = $type->constrain($data);
        $groupId = $data['attribute_group_id'] ?? null;
        $data['sort'] ??= $this->maxSort($groupId) + 1;

        $entityTypes = array_values(array_unique($data['entity_types'] ?? []));
        unset($data['entity_types']);

        if ($entityTypes === []) {
            throw FluentBuilderException::missingEntityType((string) ($data['code'] ?? ''));
        }

        /** @var Attribute $attribute */
        $attribute = $this->createRecord(function () use ($data, $entityTypes): Attribute {
            /** @var Attribute $attribute */
            $attribute = $this->query()->create($data);
            $this->addEntityTypes($attribute, $entityTypes);

            return $attribute;
        }, $translations);

        // The Eloquent `created` hook fired before the entity types existed to see —
        // only now is the attribute complete enough to refresh what depends on it.
        $this->sync->refresh($attribute);

        $this->events->dispatch(new AttributeCreated($attribute));

        return $attribute;
    }

    /**
     * Update an existing attribute, optionally replacing the entity types it is applicable to.
     */
    public function update(Attribute $attribute, array $data): Attribute
    {
        $translations = $this->extractTranslations($data);
        $type = Eav::$attributeTypeModel::query()->findOrFail($data['attribute_type_id'] ?? $attribute->attribute_type_id);

        $data = $type->constrain($data);

        $entityTypes = array_key_exists('entity_types', $data)
            ? array_values(array_unique($data['entity_types']))
            : null;
        unset($data['entity_types']);

        if ($entityTypes === []) {
            throw FluentBuilderException::missingEntityType($attribute->code);
        }

        // Read from the database: the given instance may predate types attached elsewhere.
        $previousEntityTypes = $entityTypes !== null ? $this->sync->entityTypes($attribute) : [];

        /** @var Attribute $attribute */
        $attribute = $this->transaction(function () use ($attribute, $data, $translations, $entityTypes, $previousEntityTypes): Attribute {
            $attribute = $this->updateRecord($attribute, $data, $translations);

            if ($entityTypes !== null) {
                $this->syncEntityTypes($attribute, $entityTypes);

                // Remove values for entity types no longer using the attribute.
                if (($removed = array_values(array_diff($previousEntityTypes, $entityTypes))) !== []) {
                    $this->pruner->prune($attribute->getKey(), $removed);
                }
            }

            return $attribute;
        });

        if ($entityTypes !== null) {
            $this->sync->forget([...$previousEntityTypes, ...$entityTypes]);
            $this->sync->refresh($attribute);
        }

        $this->events->dispatch(new AttributeUpdated($attribute->fresh()));

        return $attribute;
    }

    /**
     * Delete an attribute.
     */
    public function delete(Attribute $attribute): void
    {
        $this->events->dispatch(new AttributeDeleted($this->deleteRecord($attribute)));
    }

    /**
     * Sort an attribute within its group (or among the ungrouped) — entity type plays no part.
     */
    public function sort(Attribute $attribute, int $position): Attribute
    {
        $siblings = $this->query()
            ->where('attribute_group_id', $attribute->attribute_group_id)
            ->orderBy('sort')
            ->orderBy('id')
            ->get();

        $this->applySort($this->reorder($siblings, $attribute->id, $position));

        return $attribute->fresh();
    }

    /**
     * Create many attributes in a single batch — for imports, not one-off seeding.
     */
    public function batch(array $attributesData, bool $fireEvents = true): Collection
    {
        if (empty($attributesData)) {
            return collect();
        }

        $types = $this->fetchTypes($attributesData);
        $sortCounters = $this->initializeSortCounters($attributesData);
        $now = now();

        [$rows, $translationMap, $pivotMap] = $this->buildBatchRows($attributesData, $types, $sortCounters, $now);

        $created = $this->transaction(function () use ($rows, $translationMap, $pivotMap, $now): Collection {
            foreach (array_chunk($rows, 500) as $chunk) {
                $this->query()->insert($chunk);
            }

            // Codes are unique, so they name exactly the rows just inserted.
            $created = $this->query()
                ->without('entityTypes')
                ->whereIn('code', array_column($rows, 'code'))
                ->get()
                ->keyBy('code');

            $this->saveBatchTranslations($created, $translationMap, $now);
            $this->insertBatchEntityTypes($created, $pivotMap, $now);

            return $created->load('entityTypes');
        });

        if ($fireEvents) {
            $created->each(fn (Attribute $attribute) => $this->events->dispatch(new AttributeCreated($attribute)));
        }

        $created->each(fn (Attribute $attribute) => $this->sync->refresh($attribute));

        return $created;
    }

    /**
     * Get the model class.
     */
    protected function modelClass(): string
    {
        return Eav::$attributeModel;
    }

    /**
     * Get the highest sort position within the group or ungrouped attributes.
     */
    private function maxSort(int|string|null $groupId): int
    {
        return (int) $this->query()->where('attribute_group_id', $groupId)->max('sort');
    }

    /**
     * Pre-fetch attribute types indexed by ID.
     */
    private function fetchTypes(array $attributesData): Collection
    {
        return Eav::$attributeTypeModel::query()
            ->whereIn('id', array_values(array_unique(array_column($attributesData, 'attribute_type_id'))))
            ->get()
            ->keyBy('id');
    }

    /**
     * Pre-compute MAX(sort) per group for sequential numbering.
     */
    private function initializeSortCounters(array $attributesData): array
    {
        $counters = [];

        foreach (array_unique(array_map(static fn (array $d) => $d['attribute_group_id'] ?? null, $attributesData)) as $groupId) {
            $counters[(string) $groupId] = $this->maxSort($groupId);
        }

        return $counters;
    }

    /**
     * Transform raw payloads into DB row arrays and extract translation and entity-type data.
     */
    private function buildBatchRows(array $attributesData, Collection $types, array $sortCounters, Carbon $now): array
    {
        $translationMap = [];
        $pivotMap = [];
        $rows = [];

        foreach ($attributesData as $data) {
            $code = $data['code'];
            $translationMap[$code] = $data['translations'] ?? [];
            $pivotMap[$code] = array_values(array_unique($data['entity_types'] ?? []));
            unset($data['translations'], $data['entity_types']);

            if ($type = $types[$data['attribute_type_id']] ?? null) {
                $data = $type->constrain($data);
            }

            if (! isset($data['sort'])) {
                $groupKey = (string) ($data['attribute_group_id'] ?? '');
                $data['sort'] = ++$sortCounters[$groupKey];
            }

            $data['created_at'] = $now;
            $data['updated_at'] = $now;
            $rows[] = $data;
        }

        return [$rows, $translationMap, $pivotMap];
    }

    /**
     * Bulk-insert `attribute_entity_types` rows for a freshly batch-created set of attributes.
     */
    private function insertBatchEntityTypes(Collection $created, array $pivotMap, Carbon $now): void
    {
        $rows = [];

        foreach ($created as $code => $attribute) {
            foreach ($pivotMap[$code] ?? [] as $entityType) {
                $rows[] = [
                    'attribute_id' => $attribute->id,
                    'entity_type' => $entityType,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            Eav::$attributeEntityTypeModel::query()->insert($chunk);
        }
    }

    /**
     * Make the attribute applicable to the given entity types — to these alone, unless not detaching.
     *
     * @return bool Whether anything changed.
     */
    private function syncEntityTypes(Attribute $attribute, array $entityTypes, bool $detaching = true): bool
    {
        $current = $attribute->entityTypes()->pluck('entity_type')->all();
        $removed = $detaching ? array_values(array_diff($current, $entityTypes)) : [];

        if ($removed !== []) {
            $attribute->entityTypes()->whereIn('entity_type', $removed)->delete();
            $attribute->unsetRelation('entityTypes');
        }

        return $this->addEntityTypes($attribute, array_values(array_diff(array_unique($entityTypes), $current))) || $removed !== [];
    }

    /** @return bool Whether any entity type was added. */
    private function addEntityTypes(Attribute $attribute, array $entityTypes): bool
    {
        if ($entityTypes === []) {
            return false;
        }

        $attribute->entityTypes()->createMany(array_map(static fn (string $entityType): array => ['entity_type' => $entityType], $entityTypes));
        $attribute->unsetRelation('entityTypes');

        return true;
    }
}
