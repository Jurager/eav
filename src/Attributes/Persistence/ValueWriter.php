<?php

declare(strict_types=1);

namespace Jurager\Eav\Attributes\Persistence;

use Closure;
use Illuminate\Container\Attributes\Config;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Carbon;
use Jurager\Eav\Eav;
use Jurager\Eav\Enums\AttributeStorage;
use Jurager\Eav\Events\EntityValuesChanged;
use Jurager\Eav\Fields\Field;

/**
 * Persist attribute values and translations within a transaction.
 */
class ValueWriter
{
    /**
     * The default parameter limit for each database driver.
     */
    private const array BIND_LIMITS = ['sqlsrv' => 2100, 'sqlite' => 32766];

    private const int DEFAULT_BIND_LIMIT = 65535;

    /** @param  int|null  $bindLimit  Most bound parameters a single statement may carry; the driver's limit when null. */
    public function __construct(
        #[Config('eav.bind_limit')] private readonly ?int $bindLimit = null,
    ) {}

    /**
     * Write fields of entities of one type, and announce the change.
     *
     * @param  array<int|string, iterable<Field>>  $fieldsByEntity  Fields keyed by entity key.
     */
    public function write(string $entityType, array $fieldsByEntity): void
    {
        if ($fieldsByEntity === []) {
            return;
        }

        $this->transaction(function () use ($entityType, $fieldsByEntity): void {
            $this->lockEntities($entityType, array_keys($fieldsByEntity));

            $existing = $this->existingRows($entityType, $fieldsByEntity);
            $updates = $inserts = $deletes = [];

            foreach ($fieldsByEntity as $entityId => $fields) {
                foreach ($fields as $field) {
                    $rows = $this->rowsFor($entityType, $entityId, $field);
                    $current = $existing["$entityId:{$field->attribute()->getKey()}"] ?? [];

                    foreach ($rows as $position => $row) {
                        isset($current[$position])
                            ? $updates[] = $row->withId($current[$position])
                            : $inserts[] = $row;
                    }

                    array_push($deletes, ...array_slice($current, count($rows)));
                }
            }

            $this->delete($deletes);
            $this->update($updates);
            $this->insert($inserts);
        });

        EntityValuesChanged::dispatch($entityType, array_keys($fieldsByEntity));
    }

    /**
     * Delete value rows together with their translations.
     *
     * @param  list<int>  $rowIds
     */
    public function delete(array $rowIds): void
    {
        if ($rowIds === []) {
            return;
        }

        $this->transaction(function () use ($rowIds): void {
            foreach (array_chunk($rowIds, $this->bindLimit()) as $chunk) {
                $this->translationsOf($chunk)->delete();

                Eav::$entityAttributeModel::query()->whereKey($chunk)->delete();
            }
        });
    }

    /**
     * Run work in a transaction on the connection the value rows are stored on.
     */
    public function transaction(Closure $work): mixed
    {
        return $this->connection()->transaction($work);
    }

    private function connection(): ConnectionInterface
    {
        return (new (Eav::$entityAttributeModel))->getConnection();
    }

    /**
     * Lock entity rows until the transaction completes.
     *
     * @param  list<int|string>  $entityIds
     */
    private function lockEntities(string $entityType, array $entityIds): void
    {
        $model = Relation::getMorphedModel($entityType) ?? $entityType;

        if (! is_subclass_of($model, Model::class)) {
            return;
        }

        $entity = new $model;

        // A lock on another connection would guard nothing of this transaction.
        if ($entity->getConnectionName() !== (new (Eav::$entityAttributeModel))->getConnectionName()) {
            return;
        }

        $entity->newQueryWithoutScopes()
            ->whereKey($entityIds)
            ->lockForUpdate()
            ->toBase()
            ->get([$entity->getKeyName()]);
    }

    /**
     * Get existing row IDs by entity and attribute, oldest first.
     *
     * @param  array<int|string, iterable<Field>>  $fieldsByEntity
     * @return array<string, list<int>>
     */
    private function existingRows(string $entityType, array $fieldsByEntity): array
    {
        $attributeIds = [];

        foreach ($fieldsByEntity as $fields) {
            foreach ($fields as $field) {
                $attributeIds[$field->attribute()->getKey()] = true;
            }
        }

        $existing = [];

        $rows = Eav::$entityAttributeModel::query()
            ->where('entity_type', $entityType)
            ->whereIn('entity_id', array_keys($fieldsByEntity))
            ->whereIn('attribute_id', array_keys($attributeIds))
            ->orderBy('id')
            ->toBase()
            ->get(['id', 'entity_id', 'attribute_id']);

        foreach ($rows as $row) {
            $existing["$row->entity_id:$row->attribute_id"][] = (int) $row->id;
        }

        return $existing;
    }

    /**
     * Build storage rows for a field.
     *
     * @return list<ValueRow>
     */
    private function rowsFor(string $entityType, int|string $entityId, Field $field): array
    {
        $now = Carbon::now();
        $blank = [
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'attribute_id' => $field->attribute()->getKey(),
            ...array_fill_keys(self::valueColumns(), null),
            'created_at' => $now,
            'updated_at' => $now,
        ];

        return array_map(static fn (array $storage): ValueRow => $field->isLocalizable()
            ? new ValueRow($blank, $storage['translations'] ?? [])
            : new ValueRow([...$blank, $field->column()->value => $storage['value'] ?? null]),
            $field->toStorage(),
        );
    }

    /** @param  list<ValueRow>  $rows */
    private function update(array $rows): void
    {
        if ($rows === []) {
            return;
        }

        $this->chunked(
            array_map(static fn (ValueRow $row): array => $row->columns, $rows),
            static fn (array $chunk) => Eav::$entityAttributeModel::query()->upsert($chunk, ['id'], [...self::valueColumns(), 'updated_at']),
        );

        $translations = [];

        foreach ($rows as $row) {
            if ($row->translations !== null) {
                $translations[$row->columns['id']] = $row->translations;
            }
        }

        $this->replaceTranslations($translations);
    }

    /**
     * Insert rows, resolving IDs for localizable values.
     *
     * @param  list<ValueRow>  $rows
     */
    private function insert(array $rows): void
    {
        $plain = [];
        $translations = [];

        foreach ($rows as $row) {
            if (empty($row->translations)) {
                $plain[] = $row->columns;

                continue;
            }

            $translations[(int) Eav::$entityAttributeModel::query()->insertGetId($row->columns)] = $row->translations;
        }

        $this->chunked($plain, static fn (array $chunk) => Eav::$entityAttributeModel::query()->insert($chunk));
        $this->upsertTranslations($this->translationRows($translations));
    }

    /**
     * Replace the translations of updated rows.
     *
     * @param  array<int, list<array{locale_id: int, value: mixed}>>  $translations  By row id; an empty list drops them all.
     */
    private function replaceTranslations(array $translations): void
    {
        if ($translations === []) {
            return;
        }

        $byLocales = [];

        foreach ($translations as $rowId => $items) {
            $locales = array_values(array_unique(array_filter(array_column($items, 'locale_id'))));
            sort($locales);

            $byLocales[implode(',', $locales)][] = $rowId;
        }

        // One delete per set of kept locales, instead of one per row.
        foreach ($byLocales as $locales => $rowIds) {
            $this->translationsOf($rowIds)
                ->when($locales !== '', static fn (Builder $query) => $query->whereNotIn('locale_id', explode(',', (string) $locales)))
                ->delete();
        }

        $this->upsertTranslations($this->translationRows(array_filter($translations)));
    }

    /**
     * @param  array<int, list<array{locale_id: int, value: mixed}>>  $translations  By row id.
     * @return list<array<string, mixed>>
     */
    private function translationRows(array $translations): array
    {
        $type = (new (Eav::$entityAttributeModel)())->getMorphClass();
        $now = Carbon::now();
        $rows = [];

        foreach ($translations as $rowId => $items) {
            foreach ($items as $item) {
                if (isset($item['locale_id'])) {
                    $rows[] = [
                        'entity_type' => $type,
                        'entity_id' => $rowId,
                        'locale_id' => (int) $item['locale_id'],
                        'label' => $item['value'] ?? null,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
            }
        }

        return $rows;
    }

    /** @param  list<array<string, mixed>>  $rows */
    private function upsertTranslations(array $rows): void
    {
        $this->chunked($rows, static fn (array $chunk) => Eav::$entityTranslationModel::query()
            ->upsert($chunk, ['entity_type', 'entity_id', 'locale_id'], ['label', 'updated_at']));
    }

    /** @param  list<int>  $rowIds */
    private function translationsOf(array $rowIds): Builder
    {
        return Eav::$entityTranslationModel::query()
            ->where('entity_type', (new (Eav::$entityAttributeModel)())->getMorphClass())
            ->whereIn('entity_id', $rowIds);
    }

    /**
     * Run a bulk statement per chunk small enough for the bind limit.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  callable(list<array<string, mixed>>): mixed  $statement
     */
    private function chunked(array $rows, callable $statement): void
    {
        if ($rows === []) {
            return;
        }

        $size = max(1, intdiv($this->bindLimit(), max(1, count($rows[0]))));

        foreach (array_chunk($rows, $size) as $chunk) {
            $statement($chunk);
        }
    }

    /**
     * The configured bind limit, or the limit of the driver the values are stored with.
     */
    private function bindLimit(): int
    {
        return $this->bindLimit ?? self::BIND_LIMITS[$this->connection()->getDriverName()] ?? self::DEFAULT_BIND_LIMIT;
    }

    /** @return list<string> */
    private static function valueColumns(): array
    {
        return array_column(AttributeStorage::cases(), 'value');
    }
}
