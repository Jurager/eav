<?php

namespace Jurager\Eav\Support;

use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Validation\ValidationException;
use JsonException;
use Jurager\Eav\Contracts\Attributable;
use Jurager\Eav\Fields\Field;

/**
 * Validates incoming attribute payloads against field rules and uniqueness.
 */
class AttributeValidator
{
    private AttributeManager $manager;

    private Attributable $entity;

    /**
     * Pass an existing AttributeManager to reuse its schema cache.
     *
     * @throws BindingResolutionException
     * @throws JsonException
     */
    public function __construct(Attributable $entity, ?AttributeManager $manager = null)
    {
        $this->entity = $entity;
        $this->manager = $manager ?? AttributeManager::for($entity);
        $this->manager->ensureSchema();
    }

    /**
     * Validate and fill attributes.
     *
     * @return array<string, Field>
     *
     * @throws ValidationException|JsonException|BindingResolutionException
     */
    public function validate(array $input): array
    {
        $this->fillFields($input);
        $this->validateFields();

        return $this->manager->fields();
    }

    /**
     * Fill fields with input data.
     *
     * @throws JsonException
     * @throws BindingResolutionException
     */
    private function fillFields(array $input): void
    {
        $codes = array_values(array_filter(array_column($input, 'code')));

        if (empty($codes)) {
            return;
        }

        $this->manager->ensureFields($codes);

        foreach ($input as $item) {
            $this->manager->field($item['code'] ?? '')?->fill($item['values'] ?? null);
        }
    }

    /**
     * Validate all fields and throw exception if errors found.
     *
     * @throws ValidationException
     */
    private function validateFields(): void
    {
        $errors = [];

        foreach ($this->manager->fields() as $field) {

            $attributeCode = $field->attribute()->code;

            if ($field->hasErrors()) {
                $errors[$attributeCode] = array_merge($errors[$attributeCode] ?? [], $field->errors());
            } elseif ($field->isMandatory() && ! $field->isFilled()) {
                $errors[$attributeCode][] = __('eav::attributes.validation.required');
            }

            if ($field->isUnique() && $field->isFilled()) {
                $uniqueErrors = $this->validateUniqueness($field);
                if (! empty($uniqueErrors)) {
                    $errors[$attributeCode] = array_merge($errors[$attributeCode] ?? [], $uniqueErrors);
                }
            }
        }

        if (! empty($errors)) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * Validate field value uniqueness, excluding soft-deleted entities.
     *
     * @return array<string>
     */
    private function validateUniqueness(Field $field): array
    {
        $entityType = $this->entity->getAttributeEntityType();
        $entityId = $this->entity->id ?? null;
        $attributeId = $field->attribute()->id;
        $column = $field->column();

        $values = array_values(array_filter(array_column($field->toStorage(), 'value')));

        if (empty($values)) {
            return [];
        }

        $modelClass = Relation::getMorphedModel($entityType);
        $usesSoftDeletes = $modelClass && in_array(SoftDeletes::class, class_uses_recursive($modelClass));

        $query = EavModels::query('entity_attribute')
            ->where('entity_type', $entityType)
            ->where('attribute_id', $attributeId)
            ->whereNotNull($column)
            ->whereIn($column, $values);

        if ($entityId) {
            $query->where('entity_id', '!=', $entityId);
        }

        if ($usesSoftDeletes) {
            $keyName = (new $modelClass)->getKeyName();
            $query->whereIn('entity_id', $modelClass::query()->select($keyName));
        }

        if ($query->exists()) {
            return [__('eav::attributes.validation.unique')];
        }

        return [];
    }
}
