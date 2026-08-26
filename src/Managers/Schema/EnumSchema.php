<?php

declare(strict_types=1);

namespace Jurager\Eav\Managers\Schema;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Jurager\Eav\Eav;
use Jurager\Eav\Events\AttributeEnumCreated;
use Jurager\Eav\Events\AttributeEnumDeleted;
use Jurager\Eav\Events\AttributeEnumUpdated;
use Jurager\Eav\Models\Attribute;
use Jurager\Eav\Models\AttributeEnum;

class EnumSchema extends BaseSchema
{
    /** Find an enum by ID. */
    public function find(int $id): AttributeEnum
    {
        /** @var AttributeEnum */
        return $this->query()->findOrFail($id);
    }

    /** Create a new enum for the given attribute. */
    public function create(Attribute $attribute, array $data): AttributeEnum
    {
        $translations = $this->extractTranslations($data);

        /** @var AttributeEnum $enum */
        $enum = $this->createRecord(fn () => $attribute->enums()->create($data), $translations);

        $this->events->dispatch(new AttributeEnumCreated($enum));

        return $enum;
    }

    /** Update an existing enum. */
    public function update(AttributeEnum $enum, array $data): AttributeEnum
    {
        $translations = $this->extractTranslations($data);

        /** @var AttributeEnum $enum */
        $enum = $this->updateRecord($enum, $data, $translations);

        $this->events->dispatch(new AttributeEnumUpdated($enum->fresh()));

        return $enum;
    }

    /** Delete an enum. */
    public function delete(AttributeEnum $enum): void
    {
        $this->events->dispatch(new AttributeEnumDeleted($this->deleteRecord($enum)));
    }

    /** Upsert many enum options in a single batch, matched by attribute + code — for imports, not one-off editing. */
    public function batch(array $optionsData, bool $fireEvents = true): Collection
    {
        if (empty($optionsData)) {
            return collect();
        }

        $now = now();
        [$rows, $translationMap] = $this->buildBatchRows($optionsData, $now);
        $attributeIds = array_values(array_unique(array_column($rows, 'attribute_id')));

        // Read before the upsert so created/updated can still be told apart afterwards.
        $existingKeys = $fireEvents ? $this->existingBatchKeys($attributeIds) : [];

        $saved = $this->transaction(function () use ($rows, $attributeIds, $translationMap, $now): Collection {
            foreach (array_chunk($rows, 1000) as $chunk) {
                $this->query()->upsert($chunk, ['attribute_id', 'code'], ['sort', 'updated_at']);
            }

            $saved = $this->resolveBatch($attributeIds, $translationMap);

            $this->saveBatchTranslations($saved, $translationMap, $now);

            return $saved;
        });

        if ($fireEvents) {
            $this->fireBatchEvents($saved, $existingKeys);
        }

        return $saved;
    }

    /** Get the model class. */
    protected function modelClass(): string
    {
        return Eav::$attributeEnumModel;
    }

    /** Transform raw payloads into upsert row arrays and extract translation data. */
    private function buildBatchRows(array $optionsData, Carbon $now): array
    {
        $translationMap = [];
        $rows = [];

        foreach ($optionsData as $data) {
            $key = $data['attribute_id'].':'.$data['code'];
            $translationMap[$key] = $data['translations'] ?? [];

            $rows[] = [
                'attribute_id' => $data['attribute_id'],
                'code'         => $data['code'],
                'sort'         => $data['sort'] ?? 0,
                'created_at'   => $now,
                'updated_at'   => $now,
            ];
        }

        return [$rows, $translationMap];
    }

    /** Keys of options that already existed before the upsert, for created/updated events. */
    private function existingBatchKeys(array $attributeIds): array
    {
        return $this->query()
            ->whereIn('attribute_id', $attributeIds)
            ->get(['attribute_id', 'code'])
            ->map(fn (AttributeEnum $enum) => "{$enum->attribute_id}:{$enum->code}")
            ->flip()
            ->all();
    }

    /** Re-fetch every touched option, keyed the same way as $translationMap. */
    private function resolveBatch(array $attributeIds, array $translationMap): Collection
    {
        return $this->query()
            ->whereIn('attribute_id', $attributeIds)
            ->get()
            ->keyBy(fn (AttributeEnum $enum) => "{$enum->attribute_id}:{$enum->code}")
            // Eloquent\Collection::only() filters by primary key, not by array key — drop
            // down to a base collection so `only()` filters by the "attribute_id:code" keys above.
            ->toBase()
            ->only(array_keys($translationMap));
    }

    /** Dispatch a created or updated event per option, based on pre-upsert existence. */
    private function fireBatchEvents(Collection $saved, array $existingKeys): void
    {
        $saved->each(function (AttributeEnum $enum, string $key) use ($existingKeys): void {
            isset($existingKeys[$key])
                ? $this->events->dispatch(new AttributeEnumUpdated($enum))
                : $this->events->dispatch(new AttributeEnumCreated($enum));
        });
    }
}
