<?php

declare(strict_types=1);

namespace Jurager\Eav\Managers\Schema;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Jurager\Eav\Events\AttributeCreated;
use Jurager\Eav\Events\AttributeDeleted;
use Jurager\Eav\Events\AttributeUpdated;
use Jurager\Eav\Models\Attribute;
use Jurager\Eav\Eav;

class AttributeSchema extends BaseSchema
{
    /** Find an attribute by ID. */
    public function find(int $id): Attribute
    {
        /** @var Attribute */
        return $this->query()->findOrFail($id);
    }

    /** Find by entity type and code, or create. */
    public function findOrCreate(string $entityType, string $code, array $data): Attribute
    {
        $attribute = $this->query()
            ->where('entity_type', $entityType)
            ->where('code', $code)
            ->first();

        if ($attribute) {
            if ($translations = $data['translations'] ?? []) {
                $this->translations->save($attribute, $translations);
            }

            return $attribute;
        }

        return $this->create($data);
    }

    /** Create a new attribute. */
    public function create(array $data): Attribute
    {
        $translations = $this->extractTranslations($data);
        $type = Eav::$attributeTypeModel::query()->findOrFail($data['attribute_type_id']);

        $data = $type->constrain($data);
        $groupId = $data['attribute_group_id'] ?? null;
        $data['sort'] ??= $this->nextSort($groupId !== null ? (int) $groupId : null);

        /** @var Attribute $attribute */
        $attribute = $this->createRecord(fn () => $this->query()->create($data), $translations);

        $this->events->dispatch(new AttributeCreated($attribute));

        return $attribute;
    }

    /** Update an existing attribute. */
    public function update(Attribute $attribute, array $data): Attribute
    {
        $translations = $this->extractTranslations($data);
        $type = Eav::$attributeTypeModel::query()->findOrFail($data['attribute_type_id'] ?? $attribute->attribute_type_id);

        $data = $type->constrain($data);

        /** @var Attribute $attribute */
        $attribute = $this->updateRecord($attribute, $data, $translations);

        $this->events->dispatch(new AttributeUpdated($attribute->fresh()));

        return $attribute;
    }

    /** Delete an attribute. */
    public function delete(Attribute $attribute): void
    {
        $this->events->dispatch(new AttributeDeleted($this->deleteRecord($attribute)));
    }

    /** Sort an attribute within its group or entity scope. */
    public function sort(Attribute $attribute, int $position): Attribute
    {
        $siblings = $this->query()
            ->withoutGlobalScope('ordered')
            ->when($attribute->attribute_group_id, fn ($q, $id) => $q->where('attribute_group_id', $id))
            ->where('entity_type', $attribute->entity_type)
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

        [$rows, $translationMap] = $this->buildBatchRows($attributesData, $types, $sortCounters, $now);

        $created = $this->transaction(function () use ($rows, $translationMap, $now): Collection {
            $maxIdBefore = (int) ($this->query()->withTrashed()->max('id') ?? 0);

            foreach (array_chunk($rows, 500) as $chunk) {
                $this->query()->insert($chunk);
            }

            $created = $this->query()
                ->whereIn('entity_type', array_values(array_unique(array_column($rows, 'entity_type'))))
                ->whereIn('code', array_column($rows, 'code'))
                ->where('id', '>', $maxIdBefore)
                ->get()
                ->keyBy(fn (Attribute $a) => "{$a->entity_type}:{$a->code}");

            $this->saveBatchTranslations($created, $translationMap, $now);

            return $created;
        });

        if ($fireEvents) {
            $created->each(fn (Attribute $attribute) => $this->events->dispatch(new AttributeCreated($attribute)));
        }

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

    /** Transform raw payloads into DB row arrays and extract translation data. */
    private function buildBatchRows(array $attributesData, Collection $types, array $sortCounters, Carbon $now): array
    {
        $translationMap = [];
        $rows = [];

        foreach ($attributesData as $data) {
            $key = ($data['entity_type'] ?? '') . ':' . $data['code'];
            $translationMap[$key] = $data['translations'] ?? [];
            unset($data['translations']);

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

        return [$rows, $translationMap];
    }
}
