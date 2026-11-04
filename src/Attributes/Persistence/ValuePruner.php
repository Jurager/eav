<?php

declare(strict_types=1);

namespace Jurager\Eav\Attributes\Persistence;

use Illuminate\Support\Collection;
use Jurager\Eav\Eav;
use Jurager\Eav\Events\EntityValuesChanged;

/**
 * Permanently removes the stored values of an attribute, translations included.
 */
class ValuePruner
{
    private const int CHUNK = 1000;

    public function __construct(private readonly ValueWriter $writer) {}

    /** @param  list<string>|null  $entityTypes  Only the values of these entity types; every value when null. */
    public function prune(int $attributeId, ?array $entityTypes = null): void
    {
        Eav::$entityAttributeModel::query()
            ->where('attribute_id', $attributeId)
            ->when($entityTypes !== null, static fn ($query) => $query->whereIn('entity_type', $entityTypes))
            ->select(['id', 'entity_type', 'entity_id'])
            ->chunkById(self::CHUNK, function (Collection $rows): void {
                $this->writer->delete($rows->pluck('id')->all());

                // The documents of those entities no longer carry the values.
                foreach ($rows->groupBy('entity_type') as $entityType => $values) {
                    EntityValuesChanged::dispatch((string) $entityType, $values->pluck('entity_id')->unique()->values()->all());
                }
            });
    }
}
