<?php

namespace Jurager\Eav\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Jurager\Eav\Contracts\Attributable;
use Jurager\Eav\Exceptions\MissingEntityException;
use Jurager\Eav\Fields\Field;

/**
 * Persists EAV attribute values and their translations.
 */
class AttributePersister
{
    private const int BIND_LIMIT = 65535;

    private const string MODEL_ATTRIBUTE = 'entity_attribute';

    private const string MODEL_TRANSLATION = 'entity_translation';

    private const array VALUE_COLUMNS = [
        'value_text', 'value_integer', 'value_float',
        'value_boolean', 'value_date', 'value_datetime',
    ];

    private const array TRANSLATION_VALUE_COLUMNS = ['label', 'updated_at'];

    /** @var array<string, array<int|string, Collection<int, Field>>> */
    private array $pending = [];

    /** @var array<int|string, Attributable> */
    private array $entities = [];

    private ?Carbon $timestamp = null;

    /** @param  Attributable|null  $entity  Omit for batch mode. */
    public function __construct(
        private readonly ?Attributable $entity = null,
    ) {
    }

    /** @param  Collection<int, Field>  $fields */
    public function persist(Collection $fields): void
    {
        if (! $this->entity || $fields->isEmpty()) {
            return;
        }

        $this->withinTimestamp(fn () => $this->persistGroup(
            $this->entity->attributeEntityType(),
            [$this->entity->id => $fields],
        ));
    }

    public function save(Field $field): void
    {
        $this->persist(collect([$field]));
    }

    /** @param  Collection<int, Field>  $fields */
    public function replace(Collection $fields): void
    {
        if (! $this->entity || $fields->isEmpty()) {
            return;
        }

        $this->withinTimestamp(fn () => DB::transaction(function () use ($fields): void {
            $keepIds = $fields->map(fn (Field $f) => $f->attribute()->id)->values()->all();

            $this->deleteExcluding($keepIds);
            $this->persistGroup(
                $this->entity->attributeEntityType(),
                [$this->entity->id => $fields],
            );
        }));
    }

    /** @param  array<int>  $attributeIds */
    public function deleteExcluding(array $attributeIds): void
    {
        $this->delete(
            $this->entityQuery()->whereNotIn('attribute_id', $attributeIds)->pluck('id')->all(),
        );
    }

    /** @param  array<int>  $attributeIds */
    public function detach(array $attributeIds): void
    {
        $this->delete(
            $this->entityQuery()->whereIn('attribute_id', $attributeIds)->pluck('id')->all(),
        );
    }

    /** @param  array<int>  $ids */
    public function delete(array $ids): void
    {
        if (empty($ids)) {
            return;
        }

        EavModels::query(self::MODEL_TRANSLATION)
            ->where('entity_type', self::MODEL_ATTRIBUTE)
            ->whereIn('entity_id', $ids)
            ->delete();

        EavModels::query(self::MODEL_ATTRIBUTE)
            ->whereIn('id', $ids)
            ->delete();
    }

    /** @param  Collection<int, Field>  $fields */
    public function add(Attributable $entity, Collection $fields): void
    {
        if ($fields->isEmpty()) {
            return;
        }

        $type = $entity->attributeEntityType();
        $entityId = $entity->getKey();

        $this->pending[$type][$entityId] = ($this->pending[$type][$entityId] ?? collect())
            ->merge($fields)
            ->unique(fn (Field $f) => $f->attribute()->id)
            ->values();

        $this->entities[$entityId] = $entity;
    }

    /** @param  callable(\Throwable, Attributable): void|null  $onEntityError */
    public function flush(?callable $onEntityError = null): void
    {
        $this->withinTimestamp(function () use ($onEntityError): void {
            foreach ($this->pending as $type => $grouped) {
                try {
                    $this->persistGroup($type, $grouped);
                } catch (\Throwable $e) {
                    if ($onEntityError !== null) {
                        foreach (array_keys($grouped) as $entityId) {
                            $onEntityError($e, $this->entities[$entityId]);
                        }
                    } else {
                        throw $e;
                    }
                }
            }
        });

        $this->pending = [];
        $this->entities = [];
    }

    /** @param  array<int|string, Collection<int, Field>>  $grouped */
    private function persistGroup(string $type, array $grouped): void
    {
        if (empty($grouped)) {
            return;
        }

        // Collect every attribute ID referenced across all entities in this group.
        $attributeIds = collect($grouped)
            ->flatMap(fn (Collection $fields) => $fields->map(fn (Field $f) => $f->attribute()->id))
            ->unique()
            ->all();

        if (empty($attributeIds)) {
            return;
        }

        // Fetch all existing rows we might touch in a single query,
        // keyed by "entity_id:attribute_id" for O(1) lookup during partitioning.
        $existing = EavModels::query(self::MODEL_ATTRIBUTE)
            ->where('entity_type', $type)
            ->whereIn('entity_id', array_keys($grouped))
            ->whereIn('attribute_id', $attributeIds)
            ->orderBy('id')
            ->get(['id', 'entity_id', 'attribute_id'])
            ->groupBy(fn ($row) => "$row->entity_id:$row->attribute_id");

        ['updates' => $updates, 'inserts' => $inserts, 'deletes' => $deletes] =
            $this->partition($type, $grouped, $existing);

        $this->delete($deletes);
        $this->applyUpdates(collect($updates));
        $this->applyInserts(collect($inserts), $type);
    }

    /** @param  Collection<int, array{row: array, translations: array|null}>  $updates */
    private function applyUpdates(Collection $updates): void
    {
        if ($updates->isEmpty()) {
            return;
        }

        $this->inChunks(
            $updates->pluck('row'),
            fn (Collection $chunk) => EavModels::query(self::MODEL_ATTRIBUTE)
                ->upsert($chunk->all(), ['id'], [...self::VALUE_COLUMNS, 'updated_at']),
        );

        $this->syncTranslations($updates);
    }

    /** @param  Collection<int, array{row: array, translations: array|null}>  $inserts */
    private function applyInserts(Collection $inserts, string $type): void
    {
        if ($inserts->isEmpty()) {
            return;
        }

        $rows = $inserts->pluck('row');

        // Snapshot the highest existing ID before bulk insert to reliably identify
        // newly created rows afterward. Using max(id) avoids DATETIME precision
        // issues that arise when filtering by created_at with microsecond timestamps.
        $maxIdBefore = (int) (EavModels::query(self::MODEL_ATTRIBUTE)->max('id') ?? 0);

        $this->inChunks(
            $rows,
            fn (Collection $chunk) => EavModels::query(self::MODEL_ATTRIBUTE)->insert($chunk->all()),
        );

        // Skip the re-fetch + translation step if no field carries translations.
        $hasTranslations = $inserts->pluck('translations')->contains(fn ($t) => ! empty($t));

        if (! $hasTranslations) {
            return;
        }

        $created = $this->fetchCreatedRecords($type, $rows, $maxIdBefore);
        $mapped = $this->mapTranslationsToRecords($inserts, $created);

        $this->inChunks(
            $this->buildTranslationRows(collect($mapped)),
            fn (Collection $chunk) => EavModels::query(self::MODEL_TRANSLATION)
                ->upsert($chunk->all(), ['entity_type', 'entity_id', 'locale_id'], self::TRANSLATION_VALUE_COLUMNS),
        );
    }

    /** @param  Collection<int, array{row: array, translations: array|null}>  $entries */
    private function syncTranslations(Collection $entries): void
    {
        // Separate localizable entries (non-null translations) from non-localizable ones.
        $translatable = $entries
            ->mapWithKeys(fn ($item) => [$item['row']['id'] => $item['translations']])
            ->filter(fn ($value) => $value !== null);

        if ($translatable->isEmpty()) {
            return;
        }

        // Records with empty translations: wipe all existing translations.
        $emptyIds = $translatable->filter(fn ($t) => empty($t))->keys();

        if ($emptyIds->isNotEmpty()) {
            EavModels::query(self::MODEL_TRANSLATION)
                ->where('entity_type', self::MODEL_ATTRIBUTE)
                ->whereIn('entity_id', $emptyIds)
                ->delete();
        }

        // Records with actual translations: upsert to create or update in one pass.
        $withData = $translatable->filter(fn ($t) => ! empty($t));

        if ($withData->isEmpty()) {
            return;
        }

        // Delete translations for locales that are no longer present,
        // then upsert the current set.
        $this->pruneStaleTranslations($withData);

        $this->inChunks(
            $this->buildTranslationRows($withData),
            fn (Collection $chunk) => EavModels::query(self::MODEL_TRANSLATION)
                ->upsert($chunk->all(), ['entity_type', 'entity_id', 'locale_id'], self::TRANSLATION_VALUE_COLUMNS),
        );
    }

    /** @param  Collection<int, array>  $withData */
    private function pruneStaleTranslations(Collection $withData): void
    {
        // Build a map of record ID → locale IDs that should be kept.
        $localesByRecord = $withData->map(
            fn (array $translations) => collect($translations)
                ->pluck('locale_id')
                ->filter()
                ->values()
                ->all(),
        );

        // Delete translations where locale_id is NOT in the keep list.
        // Grouped by locale set to minimize the number of queries.
        $localesByRecord
            ->groupBy(fn ($locales) => implode(',', $locales), preserveKeys: true)
            ->each(function (Collection $group) {
                $recordIds = $group->keys()->all();
                $keepLocales = $group->first();

                EavModels::query(self::MODEL_TRANSLATION)
                    ->where('entity_type', self::MODEL_ATTRIBUTE)
                    ->whereIn('entity_id', $recordIds)
                    ->whereNotIn('locale_id', $keepLocales)
                    ->delete();
            });
    }

    /**
     * @param  array<int|string, Collection<int, Field>>  $grouped
     * @return array{
     *     updates: array<int, array{row: array, translations: array|null}>,
     *     inserts: array<int, array{row: array, translations: array|null}>,
     *     deletes: array<int>
     * }
     */
    private function partition(string $type, array $grouped, Collection $existing): array
    {
        $updates = $inserts = $deletes = [];

        foreach ($grouped as $entityId => $fields) {
            foreach ($fields as $field) {
                $attrId = $field->attribute()->id;
                $column = $field->column();
                $localizable = $field->isLocalizable();
                $values = $field->toStorage();
                $records = $existing->get("$entityId:$attrId", collect())->values();

                $valueCount = count($values);
                $recordCount = $records->count();
                $overlap = min($valueCount, $recordCount);

                // Overlapping records: update in place, keeping the existing ID.
                for ($i = 0; $i < $overlap; $i++) {
                    $entry = $this->buildEntry($type, $entityId, $attrId, $column, $localizable, $values[$i]);
                    $entry['row']['id'] = $records[$i]->id;
                    $updates[] = $entry;
                }

                // New values without matching records: queue for insertion.
                for ($i = $overlap; $i < $valueCount; $i++) {
                    $inserts[] = $this->buildEntry($type, $entityId, $attrId, $column, $localizable, $values[$i]);
                }

                // Surplus records with no corresponding value: mark for deletion.
                for ($i = $overlap; $i < $recordCount; $i++) {
                    $deletes[] = $records[$i]->id;
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

    /**
     * Re-fetch rows just inserted via bulk insert(), using id > $maxIdBefore
     * to identify them. Ordered by (entity_id, attribute_id, id) for positional
     * alignment with the original insert payloads.
     */
    private function fetchCreatedRecords(string $type, Collection $rows, int $maxIdBefore): Collection
    {
        return EavModels::query(self::MODEL_ATTRIBUTE)
            ->where('entity_type', $type)
            ->whereIn('entity_id', $rows->pluck('entity_id')->unique())
            ->whereIn('attribute_id', $rows->pluck('attribute_id')->unique())
            ->where('id', '>', $maxIdBefore)
            ->orderBy('entity_id')
            ->orderBy('attribute_id')
            ->orderBy('id')
            ->get(['id', 'entity_id', 'attribute_id']);
    }

    /**
     * @param  Collection<int, array{row: array, translations: array|null}>  $inserts
     * @return array<int, array>
     */
    private function mapTranslationsToRecords(Collection $inserts, Collection $created): array
    {
        // Group translation payloads by composite key, preserving insertion order.
        $payloads = $inserts
            ->groupBy(fn ($item) => "{$item['row']['entity_id']}:{$item['row']['attribute_id']}")
            ->map(fn (Collection $group) => $group->pluck('translations')->all());

        // Match each created record to its payload by position within the same key group.
        $mapped = [];

        $created
            ->groupBy(fn ($record) => "$record->entity_id:$record->attribute_id")
            ->each(function (Collection $records, string $key) use ($payloads, &$mapped): void {
                foreach ($records->values() as $position => $record) {
                    $translations = $payloads[$key][$position] ?? [];

                    if (! empty($translations)) {
                        $mapped[(int) $record->id] = $translations;
                    }
                }
            });

        return $mapped;
    }

    /** @param  Collection<int, array<int, array{locale_id: int, value: mixed}>>  $map */
    private function buildTranslationRows(Collection $map): Collection
    {
        return $map
            ->flatMap(
                fn (array $translations, int $recordId) => collect($translations)
                    ->filter(fn (array $t) => isset($t['locale_id']))
                    ->mapWithKeys(fn (array $t) => [
                        "$recordId:{$t['locale_id']}" => [
                            'entity_type' => self::MODEL_ATTRIBUTE,
                            'entity_id' => $recordId,
                            'locale_id' => (int) $t['locale_id'],
                            'label' => $t['value'] ?? null,
                            'created_at' => $this->timestamp ?? now(),
                            'updated_at' => $this->timestamp ?? now(),
                        ],
                    ]),
            )
            ->values();
    }

    /** Split rows into bind-safe chunks respecting PDO parameter limits. */
    private function inChunks(Collection $rows, callable $callback): void
    {
        if ($rows->isEmpty()) {
            return;
        }

        $columns = count($rows->first() ?? []);
        $size = max(1, intdiv(self::BIND_LIMIT, max(1, $columns)));

        $rows->chunk($size)->each($callback);
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
        $this->timestamp = Carbon::now();

        try {
            $callback();
        } finally {
            $this->timestamp = null;
        }
    }

    /** Return a Builder scoped to the current entity's attribute rows. */
    private function entityQuery(): Builder
    {
        return $this->entity
            ? EavModels::query(self::MODEL_ATTRIBUTE)
                ->where('entity_type', $this->entity->attributeEntityType())
                ->where('entity_id', $this->entity->id)
            : throw MissingEntityException::forPersister();
    }
}
