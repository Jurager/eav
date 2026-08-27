<?php

declare(strict_types=1);

namespace Jurager\Eav\Support\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Jurager\Eav\Enums\AttributeStorage;
use Jurager\Eav\Fields\Field;
use Jurager\Eav\Eav;
use Jurager\Eav\Events\EntityValuesChanged;

trait ExecutesPersistence
{
    /** @param  array<int>  $ids */
    public function delete(array $ids): void
    {
        if (empty($ids)) {
            return;
        }

        $this->translationsFor($ids)->delete();

        Eav::$entityAttributeModel::query()
            ->whereIn('id', $ids)
            ->delete();
    }

    /** @param  array<int|string, array<Field>>  $grouped */
    private function persistGroup(string $type, array $grouped): void
    {
        if (empty($grouped)) {
            return;
        }

        $attributeIds = [];
        foreach ($grouped as $fields) {
            foreach ($fields as $field) {
                $attributeIds[] = $field->attribute()->id;
            }
        }
        $attributeIds = array_unique($attributeIds);

        if (empty($attributeIds)) {
            return;
        }

        $existing = Eav::$entityAttributeModel::query()
            ->where('entity_type', $type)
            ->whereIn('entity_id', array_keys($grouped))
            ->whereIn('attribute_id', $attributeIds)
            ->orderBy('id')
            ->get(['id', 'entity_id', 'attribute_id'])
            ->all();

        $existingGrouped = [];

        foreach ($existing as $row) {
            $existingGrouped["{$row->entity_id}:{$row->attribute_id}"][] = $row;
        }

        ['updates' => $updates, 'inserts' => $inserts, 'deletes' => $deletes] =
            $this->diffAgainstExisting($type, $grouped, $existingGrouped);

        $this->delete($deletes);
        $this->applyUpdates($updates);
        $this->applyInserts($inserts, $type);

        EntityValuesChanged::dispatch($type, array_keys($grouped));
    }


    /** @param  array<int, array{row: array, translations: array|null}>  $updates */
    private function applyUpdates(array $updates): void
    {
        if (empty($updates)) {
            return;
        }

        $this->chunk(
            array_column($updates, 'row'),
            fn (array $chunk) => Eav::$entityAttributeModel::query()
                ->upsert($chunk, ['id'], [...self::valueColumns(), 'updated_at']),
        );

        $this->syncTranslations($updates);
    }

    /** @param  array<int, array{row: array, translations: array|null}>  $inserts */
    private function applyInserts(array $inserts, string $type): void
    {
        if (empty($inserts)) {
            return;
        }

        $rows = array_column($inserts, 'row');
        $maxIdBefore = (int) (Eav::$entityAttributeModel::query()->max('id') ?? 0);

        $this->chunk(
            $rows,
            fn (array $chunk) => Eav::$entityAttributeModel::query()->insert($chunk),
        );

        $hasTranslations = false;

        foreach ($inserts as $item) {
            if (! empty($item['translations'])) {
                $hasTranslations = true;
                break;
            }
        }

        if (! $hasTranslations) {
            return;
        }

        $created = $this->fetchCreatedRecords($type, $rows, $maxIdBefore);
        $mapped = $this->mapTranslationsToRecords($inserts, $created);

        $this->upsertTranslations($this->buildTranslationRows($mapped));
    }

    /** @param  array<int, array{row: array, translations: array|null}>  $entries */
    private function syncTranslations(array $entries): void
    {
        $translatable = [];

        foreach ($entries as $item) {
            if ($item['translations'] !== null) {
                $translatable[$item['row']['id']] = $item['translations'];
            }
        }

        if (empty($translatable)) {
            return;
        }

        $withData = [];
        $emptyKeys = [];

        foreach ($translatable as $id => $translations) {
            if (empty($translations)) {
                $emptyKeys[] = $id;
            } else {
                $withData[$id] = $translations;
            }
        }

        if (! empty($emptyKeys)) {
            $this->translationsFor($emptyKeys)->delete();
        }

        if (empty($withData)) {
            return;
        }

        $this->pruneStaleTranslations($withData);

        $this->upsertTranslations($this->buildTranslationRows($withData));
    }

    /** Upsert translation rows, refreshing every column but the identity and creation time. */
    private function upsertTranslations(array $rows): void
    {
        if ($rows === []) {
            return;
        }

        $updateColumns = array_diff(array_keys($rows[0]), ['entity_type', 'entity_id', 'locale_id', 'created_at']);

        $this->chunk(
            $rows,
            fn (array $chunk) => Eav::$entityTranslationModel::query()
                ->upsert($chunk, ['entity_type', 'entity_id', 'locale_id'], $updateColumns),
        );
    }

    /** @param  array<int, array>  $withData */
    private function pruneStaleTranslations(array $withData): void
    {
        $localesByRecord = [];

        foreach ($withData as $recordId => $translations) {
            $locales = array_filter(array_column($translations, 'locale_id'));
            sort($locales);
            $localesByRecord[$recordId] = $locales;
        }

        $groups = [];
        foreach ($localesByRecord as $recordId => $locales) {
            $groups[implode(',', $locales)][] = $recordId;
        }

        foreach ($groups as $localesStr => $recordIds) {
            $keepLocales = explode(',', (string) $localesStr);
            $this->translationsFor($recordIds)->whereNotIn('locale_id', $keepLocales)->delete();
        }
    }

    /** @param  iterable<int>  $entityIds */
    private function translationsFor(iterable $entityIds): Builder
    {
        return Eav::$entityTranslationModel::query()
            ->where('entity_type', $this->entityAttributeMorphClass())
            ->whereIn('entity_id', is_array($entityIds) ? $entityIds : iterator_to_array($entityIds));
    }

    /** The morph type entity_attribute rows are stored under, resolved via getMorphClass() rather than hardcoded. */
    private function entityAttributeMorphClass(): string
    {
        return (new (Eav::$entityAttributeModel)())->getMorphClass();
    }

    /**
     * @param  array<int|string, array<Field>>  $grouped
     * @param  array<string, array>  $existing
     * @return array{
     *     updates: array<int, array{row: array, translations: array|null}>,
     *     inserts: array<int, array{row: array, translations: array|null}>,
     *     deletes: array<int>
     * }
     */
    private function diffAgainstExisting(string $type, array $grouped, array $existing): array
    {
        $updates = $inserts = $deletes = [];

        foreach ($grouped as $entityId => $fields) {
            foreach ($fields as $field) {
                $attrId = $field->attribute()->id;
                $key = "$entityId:$attrId";
                $records = $existing[$key] ?? [];

                $values = $field->toStorage();
                $valueCount = count($values);
                $recordCount = count($records);
                $overlap = min($valueCount, $recordCount);

                for ($i = 0; $i < $overlap; $i++) {
                    $entry = $this->buildRowWithTranslations($type, $entityId, $attrId, $field->column()->value, $field->isLocalizable(), $values[$i]);
                    $entry['row']['id'] = $records[$i]->id;
                    $updates[] = $entry;
                }

                for ($i = $overlap; $i < $valueCount; $i++) {
                    $inserts[] = $this->buildRowWithTranslations($type, $entityId, $attrId, $field->column()->value, $field->isLocalizable(), $values[$i]);
                }

                for ($i = $overlap; $i < $recordCount; $i++) {
                    $deletes[] = (int) $records[$i]->id;
                }
            }
        }

        return compact('updates', 'inserts', 'deletes');
    }

    /** @return array{row: array, translations: array|null} */
    private function buildRowWithTranslations(
        string $type,
        int|string $entityId,
        int $attrId,
        string $column,
        bool $localizable,
        array $storage,
    ): array {
        $row = $this->blankRow($type, $entityId, $attrId);
        $row[$column] = $localizable ? null : ($storage['value'] ?? null);

        return [
            'row' => $row,
            'translations' => $localizable ? ($storage['translations'] ?? []) : null,
        ];
    }

    /** @param  array<array>  $rows */
    private function fetchCreatedRecords(string $type, array $rows, int $maxIdBefore): array
    {
        $entityIds = array_unique(array_column($rows, 'entity_id'));
        $attributeIds = array_unique(array_column($rows, 'attribute_id'));

        return Eav::$entityAttributeModel::query()
            ->where('entity_type', $type)
            ->whereIn('entity_id', $entityIds)
            ->whereIn('attribute_id', $attributeIds)
            ->where('id', '>', $maxIdBefore)
            ->orderBy('entity_id')
            ->orderBy('attribute_id')
            ->orderBy('id')
            ->get(['id', 'entity_id', 'attribute_id'])
            ->all();
    }

    /**
     * @param  array<int, array{row: array, translations: array|null}>  $inserts
     * @param  array  $created
     * @return array<int, array>
     */
    private function mapTranslationsToRecords(array $inserts, array $created): array
    {
        $payloads = [];
        foreach ($inserts as $item) {
            $key = "{$item['row']['entity_id']}:{$item['row']['attribute_id']}";
            $payloads[$key][] = $item['translations'];
        }

        $groupedCreated = [];
        foreach ($created as $record) {
            $groupedCreated["{$record->entity_id}:{$record->attribute_id}"][] = $record;
        }

        $mapped = [];
        foreach ($groupedCreated as $key => $records) {
            foreach ($records as $position => $record) {
                $translations = $payloads[$key][$position] ?? [];

                if (! empty($translations)) {
                    $mapped[(int) $record->id] = $translations;
                }
            }
        }

        return $mapped;
    }

    /** @param  array<int, array>  $map */
    private function buildTranslationRows(array $map): array
    {
        $entityType = $this->entityAttributeMorphClass();
        $rows = [];
        foreach ($map as $recordId => $translations) {
            foreach ($translations as $t) {
                if (isset($t['locale_id'])) {
                    $rows[] = [
                        'entity_type' => $entityType,
                        'entity_id'   => $recordId,
                        'locale_id'   => (int) $t['locale_id'],
                        'label'       => $t['value'] ?? null,
                        'created_at'  => now(),
                        'updated_at'  => now(),
                    ];
                }
            }
        }

        return $rows;
    }

    /** @param  array  $rows */
    private function chunk(array $rows, callable $callback): void
    {
        if (empty($rows)) {
            return;
        }

        $columns = count(reset($rows) ?: []);
        $size = max(1, intdiv((int) config('eav.bind_limit', 65535), max(1, $columns)));

        foreach (array_chunk($rows, $size) as $chunk) {
            $callback($chunk);
        }
    }

    /** @return array<string, mixed> */
    private function blankRow(string $type, int|string $entityId, int $attrId): array
    {
        $ts = now();

        return [
            'entity_type' => $type,
            'entity_id' => $entityId,
            'attribute_id' => $attrId,
            ...array_fill_keys(self::valueColumns(), null),
            'created_at' => $ts,
            'updated_at' => $ts,
        ];
    }

    /** @return list<string> */
    private static function valueColumns(): array
    {
        return array_column(AttributeStorage::cases(), 'value');
    }
}
