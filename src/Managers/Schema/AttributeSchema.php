<?php

declare(strict_types=1);

namespace Jurager\Eav\Managers\Schema;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Jurager\Eav\Eav;
use Jurager\Eav\Events\AttributeCreated;
use Jurager\Eav\Events\AttributeDeleted;
use Jurager\Eav\Events\AttributeUpdated;
use Jurager\Eav\Exceptions\FluentBuilderException;
use Jurager\Eav\Managers\TranslationManager;
use Jurager\Eav\Models\Attribute;
use Jurager\Eav\Models\AttributeEntityType;
use Jurager\Eav\Observers\AttributeObserver;

class AttributeSchema extends BaseSchema
{
    public function __construct(
        TranslationManager $translations,
        ConnectionResolverInterface $db,
        Dispatcher $events,
        private readonly AttributeObserver $observer,
    ) {
        parent::__construct($translations, $db, $events);
    }

    /** Find an attribute by ID. */
    public function find(int $id): Attribute
    {
        /** @var Attribute */
        return $this->query()->findOrFail($id);
    }

    /** Finds or creates an attribute and attaches the given entity types to it. */
    public function findOrCreate(array $entityTypes, string $code, array $data): Attribute
    {
        $attribute = $this->query()->where('code', $code)->first();

        if ($attribute) {
            if ($translations = $data['translations'] ?? []) {
                $this->translations->save($attribute, $translations);
            }

            $this->attachEntityTypes($attribute, $entityTypes);

            return $attribute;
        }

        return $this->create($data);
    }

    /** Create a new attribute, applicable to the given entity types. */
    public function create(array $data): Attribute
    {
        $translations = $this->extractTranslations($data);
        $type = Eav::$attributeTypeModel::query()->findOrFail($data['attribute_type_id']);

        $data = $type->constrain($data);
        $groupId = $data['attribute_group_id'] ?? null;
        $data['sort'] ??= $this->nextSort($groupId !== null ? (int) $groupId : null);

        $entityTypes = array_values(array_unique($data['entity_types'] ?? []));
        unset($data['entity_types']);

        if ($entityTypes === []) {
            throw FluentBuilderException::missingEntityType((string) ($data['code'] ?? ''));
        }

        /** @var Attribute $attribute */
        $attribute = $this->createRecord(function () use ($data, $entityTypes): Attribute {
            /** @var Attribute $attribute */
            $attribute = $this->query()->create($data);
            $attribute->entityTypes()->createMany(
                array_map(fn (string $entityType) => ['entity_type' => $entityType], $entityTypes)
            );
            $attribute->unsetRelation('entityTypes');

            return $attribute;
        }, $translations);

        // The Eloquent `created` hook already fired above, before the entity types existed to see —
        // this is what actually invalidates caches and dispatches search/index sync for them.
        $this->observer->forgetCaches($entityTypes);
        $this->observer->syncAttributeStates($attribute);

        $this->events->dispatch(new AttributeCreated($attribute));

        return $attribute;
    }

    /** Update an existing attribute, optionally replacing the entity types it is applicable to. */
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

        $previousEntityTypes = $entityTypes !== null ? $attribute->applicableEntityTypes() : [];

        /** @var Attribute $attribute */
        $attribute = $this->updateRecord($attribute, $data, $translations);

        if ($entityTypes !== null) {
            $removed = array_diff($previousEntityTypes, $entityTypes);

            $this->syncEntityTypes($attribute, $entityTypes);

            if ($removed !== []) {
                // A type no longer applicable to this attribute shouldn't keep stray stored values.
                Eav::$entityAttributeModel::query()
                    ->where('attribute_id', $attribute->id)
                    ->whereIn('entity_type', $removed)
                    ->delete();
            }

            $this->observer->forgetCaches(array_values(array_unique([...$previousEntityTypes, ...$entityTypes])));
            $this->observer->syncAttributeStates($attribute);
        }

        $this->events->dispatch(new AttributeUpdated($attribute->fresh()));

        return $attribute;
    }

    /** Delete an attribute. */
    public function delete(Attribute $attribute): void
    {
        $this->events->dispatch(new AttributeDeleted($this->deleteRecord($attribute)));
    }

    /** Sort an attribute within its group (or among the ungrouped) — entity type plays no part. */
    public function sort(Attribute $attribute, int $position): Attribute
    {
        $siblings = $this->query()
            ->withoutGlobalScope('ordered')
            ->when($attribute->attribute_group_id, fn ($q, $id) => $q->where('attribute_group_id', $id))
            ->unless($attribute->attribute_group_id, fn ($q) => $q->whereNull('attribute_group_id'))
            ->orderBy('sort')
            ->orderBy('id')
            ->get();

        $this->applySort($this->reorder($siblings, $attribute->id, $position));

        return $attribute->fresh();
    }

    /** Create many attributes in a single batch — for imports, not one-off seeding. */
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
            $maxIdBefore = (int) ($this->query()->withTrashed()->max('id') ?? 0);

            foreach (array_chunk($rows, 500) as $chunk) {
                $this->query()->insert($chunk);
            }

            $created = $this->query()
                ->without('entityTypes')
                ->whereIn('code', array_column($rows, 'code'))
                ->where('id', '>', $maxIdBefore)
                ->get()
                ->keyBy('code');

            $this->saveBatchTranslations($created, $translationMap, $now);
            $this->insertBatchEntityTypes($created, $pivotMap, $now);

            return $created->load('entityTypes');
        });

        if ($fireEvents) {
            $created->each(fn (Attribute $attribute) => $this->events->dispatch(new AttributeCreated($attribute)));
        }

        $entityTypes = array_unique(array_merge([], ...array_values($pivotMap)));
        $this->observer->forgetCaches($entityTypes);
        $created->each(fn (Attribute $attribute) => $this->observer->syncAttributeStates($attribute));

        return $created;
    }

    /** Get the model class. */
    protected function modelClass(): string
    {
        return Eav::$attributeModel;
    }

    /** Get the next sort value for an attribute in the given group. */
    private function nextSort(?int $groupId): int
    {
        return (int) $this->query()
            ->when($groupId, fn ($q) => $q->where('attribute_group_id', $groupId))
            ->unless($groupId, fn ($q) => $q->whereNull('attribute_group_id'))
            ->max('sort') + 1;
    }

    /** Pre-fetch attribute types indexed by ID. */
    private function fetchTypes(array $attributesData): Collection
    {
        return Eav::$attributeTypeModel::query()
            ->whereIn('id', array_values(array_unique(array_column($attributesData, 'attribute_type_id'))))
            ->get()
            ->keyBy('id');
    }

    /** Pre-compute MAX(sort) per group for sequential numbering. */
    private function initializeSortCounters(array $attributesData): array
    {
        $groupIds = array_unique(array_map(fn (array $d) => $d['attribute_group_id'] ?? null, $attributesData));

        $counters = [];

        foreach ($groupIds as $groupId) {
            $counters[(string) $groupId] = (int) $this->query()
                ->when($groupId, fn ($q) => $q->where('attribute_group_id', $groupId))
                ->unless($groupId, fn ($q) => $q->whereNull('attribute_group_id'))
                ->max('sort');
        }

        return $counters;
    }

    /** Transform raw payloads into DB row arrays and extract translation and entity-type data. */
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

    /** Bulk-insert `attribute_entity_types` rows for a freshly batch-created set of attributes. */
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
            AttributeEntityType::query()->insert($chunk);
        }
    }

    /** Sync an attribute's entity types to exactly the given set, adding and removing pivot rows as needed. */
    private function syncEntityTypes(Attribute $attribute, array $entityTypes): void
    {
        $current = $attribute->entityTypes()->pluck('entity_type')->all();

        $toAdd = array_diff($entityTypes, $current);
        $toRemove = array_diff($current, $entityTypes);

        if ($toRemove !== []) {
            $attribute->entityTypes()->whereIn('entity_type', $toRemove)->delete();
        }

        if ($toAdd !== []) {
            $attribute->entityTypes()->createMany(
                array_map(fn (string $entityType) => ['entity_type' => $entityType], $toAdd)
            );
        }

        $attribute->unsetRelation('entityTypes');
    }

    /** Attach whichever of the given entity types the attribute isn't already applicable to. */
    private function attachEntityTypes(Attribute $attribute, array $entityTypes): void
    {
        $current = $attribute->entityTypes()->pluck('entity_type')->all();
        $missing = array_diff(array_values(array_unique($entityTypes)), $current);

        if ($missing === []) {
            return;
        }

        $attribute->entityTypes()->createMany(
            array_map(fn (string $entityType) => ['entity_type' => $entityType], $missing)
        );
        $attribute->unsetRelation('entityTypes');

        $this->observer->forgetCaches($missing);
        $this->observer->syncAttributeStates($attribute);
    }
}
