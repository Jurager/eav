<?php

namespace Jurager\Eav\Managers\Schema;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Jurager\Eav\Events\AttributeCreated;
use Jurager\Eav\Managers\TranslationManager;
use Jurager\Eav\Models\Attribute;
use Jurager\Eav\Models\AttributeType;
use Jurager\Eav\Support\EavModels;

/**
 * Handles bulk creation of attributes in a single batched DB operation.
 */
class AttributeBatchSchema
{
    public function __construct(
        private TranslationManager $translations,
    ) {
    }

    /**
     * @param  array<int, array<string, mixed>>  $attributesData
     * @return Collection<string, Attribute>  Keyed by "{entity_type}:{code}".
     */
    public function execute(array $attributesData, bool $fireEvents = true): Collection
    {
        if (empty($attributesData)) {
            return collect();
        }

        $types = $this->fetchTypes($attributesData);
        $sortCounters = $this->initializeSortCounters($attributesData);
        $now = Carbon::now();

        [$rows, $translationMap] = $this->buildRows($attributesData, $types, $sortCounters, $now);

        $created = DB::transaction(function () use ($rows, $translationMap, $now): Collection {
            $maxIdBefore = (int) (EavModels::query('attribute')->withTrashed()->max('id') ?? 0);

            foreach (array_chunk($rows, 500) as $chunk) {
                EavModels::query('attribute')->insert($chunk);
            }

            $created = EavModels::query('attribute')
                ->whereIn('entity_type', array_values(array_unique(array_column($rows, 'entity_type'))))
                ->whereIn('code', array_column($rows, 'code'))
                ->where('id', '>', $maxIdBefore)
                ->get()
                ->keyBy(fn (Attribute $a) => "{$a->entity_type}:{$a->code}");

            $this->saveBatchTranslations($created, $translationMap, $now);

            return $created;
        });

        if ($fireEvents) {
            $created->each(fn (Attribute $attribute) => Event::dispatch(new AttributeCreated($attribute)));
        }

        return $created;
    }

    /**
     * Pre-fetch attribute types indexed by ID.
     *
     * @param  array<int, array<string, mixed>>  $attributesData
     * @return Collection<int, AttributeType>
     */
    private function fetchTypes(array $attributesData): Collection
    {
        return EavModels::query('attribute_type')
            ->whereIn('id', array_values(array_unique(array_column($attributesData, 'attribute_type_id'))))
            ->get()
            ->keyBy('id');
    }

    /**
     * Pre-compute MAX(sort) per group so inserts get sequential sort values.
     *
     * @param  array<int, array<string, mixed>>  $attributesData
     * @return array<string, int>  Keyed by (string) group_id, or "" for ungrouped.
     */
    private function initializeSortCounters(array $attributesData): array
    {
        $groupIds = array_values(array_unique(
            array_map(fn (array $d) => $d['attribute_group_id'] ?? null, $attributesData),
        ));

        $counters = [];

        foreach ($groupIds as $groupId) {
            $counters[(string) $groupId] = (int) EavModels::query('attribute')
                ->when($groupId, fn ($q) => $q->where('attribute_group_id', $groupId))
                ->unless($groupId, fn ($q) => $q->whereNull('attribute_group_id'))
                ->max('sort');
        }

        return $counters;
    }

    /**
     * Transform raw payloads into DB row arrays and extract translation data.
     *
     * @param  array<int, array<string, mixed>>  $attributesData
     * @param  Collection<int, AttributeType>  $types
     * @param  array<string, int>  $sortCounters
     * @return array{0: array<int, array>, 1: array<string, array>}
     */
    private function buildRows(array $attributesData, Collection $types, array $sortCounters, Carbon $now): array
    {
        $translationMap = [];
        $rows = [];

        foreach ($attributesData as $data) {
            $key = ($data['entity_type'] ?? '').':'.$data['code'];
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

    /**
     * Persist translations for a batch of newly created attributes in a single upsert.
     *
     * @param  Collection<string, Attribute>  $created
     * @param  array<string, array>  $translationMap
     */
    private function saveBatchTranslations(Collection $created, array $translationMap, Carbon $now): void
    {
        $batch = $created
            ->filter(fn (Attribute $attribute, string $key) => ! empty($translationMap[$key]))
            ->map(fn (Attribute $attribute, string $key) => [$attribute, $translationMap[$key]])
            ->values()
            ->all();

        if (! empty($batch)) {
            $this->translations->batch($batch, $now);
        }
    }
}
