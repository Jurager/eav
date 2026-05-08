<?php

namespace Jurager\Eav\Managers\Schema;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Jurager\Eav\Managers\TranslationManager;

abstract class BaseSchema
{
    public function __construct(
        protected TranslationManager $translations,
    ) {
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
