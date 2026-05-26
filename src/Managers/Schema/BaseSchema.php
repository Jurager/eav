<?php

namespace Jurager\Eav\Managers\Schema;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Jurager\Eav\Managers\TranslationManager;
use Jurager\Eav\Support\EavModels;

abstract class BaseSchema
{
    public function __construct(
        protected TranslationManager $translations,
    ) {}

    abstract protected function modelKey(): string;

    /** Base query builder for this schema's primary model. */
    protected function query(): Builder
    {
        return EavModels::query($this->modelKey());
    }

    /**
     * Run $factory inside a transaction, then persist translations.
     *
     * @param  array<int, array<string, mixed>>  $translations
     */
    protected function createRecord(callable $factory, array $translations): Model
    {
        return DB::transaction(function () use ($factory, $translations): Model {
            $model = $factory();
            $this->saveTranslations($model, $translations);

            return $model;
        });
    }

    /**
     * Update $model with $data inside a transaction, then persist translations.
     *
     * @param  array<string, mixed>  $data
     * @param  array<int, array<string, mixed>>  $translations
     */
    protected function updateRecord(Model $model, array $data, array $translations): Model
    {
        return DB::transaction(function () use ($model, $data, $translations): Model {
            $model->update($data);
            $this->saveTranslations($model, $translations);

            return $model;
        });
    }

    /**
     * Clone $model, delete it, and return the snapshot for event dispatching.
     */
    protected function deleteRecord(Model $model): Model
    {
        $snapshot = clone $model;

        $model->delete();

        return $snapshot;
    }

    /**
     * Persist zero-based sort positions for a reordered collection atomically.
     *
     * @param  Collection<int, Model>  $reordered
     */
    protected function applySort(Collection $reordered): void
    {
        DB::transaction(function () use ($reordered): void {
            $reordered->each(function (Model $item, int $index): void {
                $item->sort = $index;
                $item->saveQuietly();
            });
        });
    }

    /**
     * Extract and unset translations from the input array.
     *
     * Mutates $data by removing the 'translations' key — this prevents mass-assignment
     * errors when the remaining array is passed to Eloquent::create()/update().
     *
     * @return array<int, array<string, mixed>>
     */
    protected function extractTranslations(array &$data): array
    {
        $translations = $data['translations'] ?? [];
        unset($data['translations']);

        return $translations;
    }

    /** @param  array<int, array<string, mixed>>  $translations */
    protected function saveTranslations(Model $model, array $translations): void
    {
        if (! empty($translations)) {
            $this->translations->save($model, $translations);
        }
    }

    /**
     * @param  Collection<int, Model>  $items
     * @return Collection<int, Model>
     */
    protected function reorder(Collection $items, int $id, int $targetIndex): Collection
    {
        $list = $items->values();

        $currentIndex = $list->search(fn ($item) => $item->id === $id);

        if ($currentIndex === false) {
            return $list;
        }

        $item = $list->splice($currentIndex, 1)->first();

        $targetIndex = max(0, min($targetIndex, $list->count()));

        $list->splice($targetIndex, 0, [$item]);

        return $list->values();
    }
}
