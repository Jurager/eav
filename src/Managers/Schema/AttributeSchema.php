<?php

declare(strict_types=1);

namespace Jurager\Eav\Managers\Schema;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\ConnectionResolverInterface;
use Jurager\Eav\Events\AttributeCreated;
use Jurager\Eav\Events\AttributeDeleted;
use Jurager\Eav\Events\AttributeUpdated;
use Jurager\Eav\Managers\TranslationManager;
use Jurager\Eav\Models\Attribute;
use Jurager\Eav\Eav;

class AttributeSchema extends BaseSchema
{
    public function __construct(
        TranslationManager $translations,
        ConnectionResolverInterface $db,
        Dispatcher $events,
        private AttributeBatchSchema $batchSchema,
    ) {
        parent::__construct($translations, $db, $events);
    }

    /** Find an attribute by ID. */
    public function find(int $id): Attribute
    {
        /** @var Attribute */
        return $this->query()->findOrFail($id);
    }

    /** Find by entity type and code, or create. */
    public function findOrCreate(string $entityType, string $code, array $data): Attribute
    {
        $attribute = $this->query()
            ->where('entity_type', $entityType)
            ->where('code', $code)
            ->first();

        if ($attribute) {
            if ($translations = $data['translations'] ?? []) {
                $this->translations->save($attribute, $translations);
            }

            return $attribute;
        }

        return $this->create($data);
    }

    /** Create a new attribute. */
    public function create(array $data): Attribute
    {
        $translations = $this->extractTranslations($data);
        $type = Eav::$attributeTypeModel::query()->findOrFail($data['attribute_type_id']);

        $data = $type->constrain($data);
        $data['sort'] ??= $this->nextSort($data['attribute_group_id'] ?? null);

        /** @var Attribute $attribute */
        $attribute = $this->createRecord(fn () => $this->query()->create($data), $translations);

        $this->events->dispatch(new AttributeCreated($attribute));

        return $attribute;
    }

    /** Update an existing attribute. */
    public function update(Attribute $attribute, array $data): Attribute
    {
        $translations = $this->extractTranslations($data);
        $type = Eav::$attributeTypeModel::query()->findOrFail($data['attribute_type_id'] ?? $attribute->attribute_type_id);

        $data = $type->constrain($data);

        /** @var Attribute $attribute */
        $attribute = $this->updateRecord($attribute, $data, $translations);

        $this->events->dispatch(new AttributeUpdated($attribute->fresh()));

        return $attribute;
    }

    /** Delete an attribute. */
    public function delete(Attribute $attribute): void
    {
        $this->events->dispatch(new AttributeDeleted($this->deleteRecord($attribute)));
    }

    /** Sort an attribute within its group or entity scope. */
    public function sort(Attribute $attribute, int $position): Attribute
    {
        $siblings = $this->query()
            ->withoutGlobalScope('ordered')
            ->when($attribute->attribute_group_id, fn ($q, $id) => $q->where('attribute_group_id', $id))
            ->where('entity_type', $attribute->entity_type)
            ->orderBy('sort')
            ->orderBy('id')
            ->get();

        $this->applySort($this->reorder($siblings, $attribute->id, $position));

        return $attribute->fresh();
    }

    /** Get the batch schema manager. */
    public function batch(): AttributeBatchSchema
    {
        return $this->batchSchema;
    }

    /** Get the model class. */
    protected function modelClass(): string
    {
        return Eav::$attributeModel;
    }

    /** Get the next sort value for an attribute in the given group. */
    private function nextSort(?int $groupId): int
    {
        return (int) $this->query()
                ->when($groupId, fn ($q) => $q->where('attribute_group_id', $groupId))
                ->unless($groupId, fn ($q) => $q->whereNull('attribute_group_id'))
                ->max('sort') + 1;
    }
}
