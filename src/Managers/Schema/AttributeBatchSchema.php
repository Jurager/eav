<?php

declare(strict_types=1);

namespace Jurager\Eav\Managers\Schema;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Jurager\Eav\Events\AttributeCreated;
use Jurager\Eav\Managers\TranslationManager;
use Jurager\Eav\Models\Attribute;
use Jurager\Eav\Eav;

class AttributeBatchSchema
{
    public function __construct(
        private TranslationManager $translations,
    ) {
    }

    /**
     * Execute the batch creation of attributes.
     * @param array<int, array<string, mixed>> $attributesData
     * @return Collection<string, Attribute> Keyed by "{entity_type}:{code}".
     * @throws \Throwable
     */
    public function execute(array $attributesData, bool $fireEvents = true): Collection
    {
        if (empty($attributesData)) {
            return collect();
        }

        $types = $this->fetchTypes($attributesData);
        $sortCounters = $this->initializeSortCounters($attributesData);
        $now = now();

        [$rows, $translationMap] = $this->buildRows($attributesData, $types, $sortCounters, $now);

        $created = DB::transaction(function () use ($rows, $translationMap, $now): Collection {
            $maxIdBefore = (int) (Eav::$attributeModel::query()->withTrashed()->max('id') ?? 0);

            foreach (array_chunk($rows, 500) as $chunk) {
                Eav::$attributeModel::query()->insert($chunk);
            }

            $created = Eav::$attributeModel::query()
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
            $counters[(string) $groupId] = (int) Eav::$attributeModel::query()
                ->when($groupId, fn ($q) => $q->where('attribute_group_id', $groupId))
                ->unless($groupId, fn ($q) => $q->whereNull('attribute_group_id'))
                ->max('sort');
        }

        return $counters;
    }

    /** Transform raw payloads into DB row arrays and extract translation data. */
    private function buildRows(array $attributesData, Collection $types, array $sortCounters, Carbon $now): array
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

    /** Persist translations for a batch of newly created attributes. */
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
