<?php

declare(strict_types=1);

namespace Jurager\Eav\Managers\Schema;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Jurager\Eav\Managers\TranslationManager;

abstract class BaseSchema
{
    public function __construct(
        protected TranslationManager $translations,
    ) {
    }

    /** Return the class name of the associated model. */
    abstract protected function modelClass(): string;

    /** Get the base query builder for the model. */
    protected function query(): Builder
    {
        return $this->modelClass()::query();
    }

    /** Create a record within a transaction and save translations. */
    protected function createRecord(callable $factory, array $translations): Model
    {
        return DB::transaction(function () use ($factory, $translations): Model {
            $model = $factory();
            $this->saveTranslations($model, $translations);

            return $model;
        });
    }

    /** Update a record within a transaction and save translations. */
    protected function updateRecord(Model $model, array $data, array $translations): Model
    {
        return DB::transaction(function () use ($model, $data, $translations): Model {
            $model->update($data);
            $this->saveTranslations($model, $translations);

            return $model;
        });
    }

    /** Clone, delete, and return a snapshot of the model. */
    protected function deleteRecord(Model $model): Model
    {
        $snapshot = clone $model;

        $model->delete();

        return $snapshot;
    }

    /** Apply sort order to a collection of models. */
    protected function applySort(Collection $reordered): void
    {
        DB::transaction(function () use ($reordered): void {
            $reordered->each(function (Model $item, int $index): void {
                $item->sort = $index;
                $item->saveQuietly();
            });
        });
    }

    /** Extract translations from the data array. */
    protected function extractTranslations(array &$data): array
    {
        $translations = $data['translations'] ?? [];
        unset($data['translations']);

        return $translations;
    }

    /** Save translations for the given model. */
    protected function saveTranslations(Model $model, array $translations): void
    {
        if (! empty($translations)) {
            $this->translations->save($model, $translations);
        }
    }

    /** Reorder items in a collection. */
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
