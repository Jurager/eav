<?php

declare(strict_types=1);

namespace Jurager\Eav\Support\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Jurager\Eav\Fields\Field;
use Jurager\Eav\Eav;

trait ExecutesPersistence
{
    private const int BIND_LIMIT = 65535;

    private const string MODEL_ATTRIBUTE = 'entity_attribute';

    private const string MODEL_TRANSLATION = 'entity_translation';

    private const array VALUE_COLUMNS = [
        'value_text', 'value_integer', 'value_float',
        'value_boolean', 'value_date', 'value_datetime',
    ];

    private const array TRANSLATION_VALUE_COLUMNS = ['label', 'updated_at'];

    private ?Carbon $timestamp = null;

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
            $this->partition($type, $grouped, $existingGrouped);

        $this->delete($deletes);
        $this->applyUpdates($updates);
        $this->applyInserts($inserts, $type);
    }

    /** @param  array<int, array{row: array, translations: array|null}>  $updates */
    private function applyUpdates(array $updates): void
    {
        if (empty($updates)) {
            return;
        }

        $this->inChunks(
            array_column($updates, 'row'),
            fn (array $chunk) => Eav::$entityAttributeModel::query()
                ->upsert($chunk, ['id'], [...self::VALUE_COLUMNS, 'updated_at']),
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

        $this->inChunks(
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

        $this->inChunks(
            $this->buildTranslationRows($mapped),
            fn (array $chunk) => Eav::$entityTranslationModel::query()
                ->upsert($chunk, ['entity_type', 'entity_id', 'locale_id'], self::TRANSLATION_VALUE_COLUMNS),
        );
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

        $this->inChunks(
            $this->buildTranslationRows($withData),
            fn (array $chunk) => Eav::$entityTranslationModel::query()
                ->upsert($chunk, ['entity_type', 'entity_id', 'locale_id'], self::TRANSLATION_VALUE_COLUMNS),
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
            ->where('entity_type', self::MODEL_ATTRIBUTE)
            ->whereIn('entity_id', is_array($entityIds) ? $entityIds : iterator_to_array($entityIds));
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
    private function partition(string $type, array $grouped, array $existing): array
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
                    $entry = $this->buildEntry($type, $entityId, $attrId, $field->column(), $field->isLocalizable(), $values[$i]);
                    $entry['row']['id'] = $records[$i]->id;
                    $updates[] = $entry;
                }

                for ($i = $overlap; $i < $valueCount; $i++) {
                    $inserts[] = $this->buildEntry($type, $entityId, $attrId, $field->column(), $field->isLocalizable(), $values[$i]);
                }

                for ($i = $overlap; $i < $recordCount; $i++) {
                    $deletes[] = (int) $records[$i]->id;
                }
            }
        }

        return compact('updates', 'inserts', 'deletes');
    }

    /** @return array{row: array, translations: array|null} */
    private function buildEntry(
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
        $rows = [];
        foreach ($map as $recordId => $translations) {
            foreach ($translations as $t) {
                if (isset($t['locale_id'])) {
                    $rows[] = [
                        'entity_type' => self::MODEL_ATTRIBUTE,
                        'entity_id'   => $recordId,
                        'locale_id'   => (int) $t['locale_id'],
                        'label'       => $t['value'] ?? null,
                        'created_at'  => $this->timestamp ?? now(),
                        'updated_at'  => $this->timestamp ?? now(),
                    ];
                }
            }
        }

        return $rows;
    }

    /** @param  array  $rows */
    private function inChunks(array $rows, callable $callback): void
    {
        if (empty($rows)) {
            return;
        }

        $columns = count(reset($rows) ?: []);
        $size = max(1, intdiv(self::BIND_LIMIT, max(1, $columns)));

        foreach (array_chunk($rows, $size) as $chunk) {
            $callback($chunk);
        }
    }

    /** @return array<string, mixed> */
    private function blankRow(string $type, int|string $entityId, int $attrId): array
    {
        $ts = $this->timestamp ?? throw new \LogicException('blankRow() called outside withinTimestamp().');

        return [
            'entity_type' => $type,
            'entity_id' => $entityId,
            'attribute_id' => $attrId,
            ...array_fill_keys(self::VALUE_COLUMNS, null),
            'created_at' => $ts,
            'updated_at' => $ts,
        ];
    }

    private function withinTimestamp(callable $callback): void
    {
        $this->timestamp = now();

        try {
            $callback();
        } finally {
            $this->timestamp = null;
        }
    }
}
