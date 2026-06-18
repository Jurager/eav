<?php

namespace Jurager\Eav\Managers\Schema;

use Illuminate\Support\Facades\Event;
use Jurager\Eav\Events\AttributeCreated;
use Jurager\Eav\Events\AttributeDeleted;
use Jurager\Eav\Events\AttributeUpdated;
use Jurager\Eav\Managers\TranslationManager;
use Jurager\Eav\Models\Attribute;
use Jurager\Eav\Support\EavModels;

class AttributeSchema extends BaseSchema
{
    public function __construct(TranslationManager $translations, private AttributeBatchSchema $batchSchema)
    {
        parent::__construct($translations);
    }

    public function find(int $id): Attribute
    {
        /** @var Attribute */
        return $this->query()->findOrFail($id);
    }

    /** Find by entity type and code, or create. Existing attributes only get translations updated. */
    public function findOrCreate(string $entityType, string $code, array $data): Attribute
    {
        $attribute = $this->query()
            ->where('entity_type', $entityType)
            ->where('code', $code)
            ->first();

        if ($attribute) {
            $translations = $data['translations'] ?? [];

            if (! empty($translations)) {
                $this->translations->save($attribute, $translations);
            }

            return $attribute;
        }

        return $this->create($data);
    }

    public function create(array $data): Attribute
    {
        $translations = $this->extractTranslations($data);
        $type = EavModels::query('attribute_type')->findOrFail($data['attribute_type_id']);

        $data = $type->constrain($data);
        $data['sort'] ??= $this->nextSort($data['attribute_group_id'] ?? null);

        /** @var Attribute $attribute */
        $attribute = $this->createRecord(fn () => $this->query()->create($data), $translations);

        Event::dispatch(new AttributeCreated($attribute));

        return $attribute;
    }

    public function update(Attribute $attribute, array $data): Attribute
    {
        $translations = $this->extractTranslations($data);
        $type = EavModels::query('attribute_type')->findOrFail($data['attribute_type_id'] ?? $attribute->attribute_type_id);

        $data = $type->constrain($data);

        /** @var Attribute $attribute */
        $attribute = $this->updateRecord($attribute, $data, $translations);

        Event::dispatch(new AttributeUpdated($attribute->fresh()));

        return $attribute;
    }

    public function delete(Attribute $attribute): void
    {
        Event::dispatch(new AttributeDeleted($this->deleteRecord($attribute)));
    }

    /** Move an attribute to a new zero-based position within its group (or across the entity type when groups are not used). */
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

    public function batch(): AttributeBatchSchema
    {
        return $this->batchSchema;
    }

    protected function modelKey(): string
    {
        return 'attribute';
    }

    /** Return the next sort value for a new attribute in the given group. */
    private function nextSort(?int $groupId): int
    {
        return (int) $this->query()
            ->when($groupId, fn ($q) => $q->where('attribute_group_id', $groupId))
            ->unless($groupId, fn ($q) => $q->whereNull('attribute_group_id'))
            ->max('sort') + 1;
    }
}
