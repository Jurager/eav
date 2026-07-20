<?php

declare(strict_types=1);

namespace Jurager\Eav\Support;

use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Validation\ValidationException;
use JsonException;
use Jurager\Eav\Contracts\Attributable;
use Jurager\Eav\Eav;
use Jurager\Eav\Fields\Field;
use Jurager\Eav\Managers\AttributeManager;

/**
 * Validates incoming attribute payloads against field rules and uniqueness.
 */
class AttributeValidator
{
    private AttributeManager $manager;

    private Attributable $entity;

    private string $entityType;

    private mixed $entityId;

    private bool $usesSoftDeletes;

    /** @var array<string, callable> */
    private array $uniqueScopes;

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

        $this->entityType = $entity->getEavEntityType();
        $this->entityId = $entity->id ?? null;

        $modelClass = Relation::getMorphedModel($this->entityType);

        $this->usesSoftDeletes = $modelClass && in_array(SoftDeletes::class, class_uses_recursive($modelClass));

        $this->uniqueScopes = $modelClass && method_exists($modelClass, 'attributeUniqueScopes')
            ? $modelClass::attributeUniqueScopes()
            : [];
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
            } elseif ($field->isRequired() && ! $field->isFilled()) {
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
        $base = $this->baseUniquenessQuery($field);

        $conflict = $field->isLocalizable()
            ? $this->hasLocalizableConflict($base, $field)
            : $this->hasScalarConflict($base, $field);

        return $conflict ? [__('eav::attributes.validation.unique')] : [];
    }

    /** The entity_attribute rows a uniqueness check should compare against. */
    private function baseUniquenessQuery(Field $field): Builder
    {
        $scopeCallback = $this->uniqueScopes[$field->attribute()->code] ?? null;

        $base = Eav::$entityAttributeModel::query()
            ->where('entity_type', $this->entityType)
            ->where('attribute_id', $field->attribute()->id)
            ->when($this->entityId, fn ($q) => $q->where('entity_id', '!=', $this->entityId))
            ->when($this->usesSoftDeletes, function ($q) {
                $modelClass = Relation::getMorphedModel($this->entityType);
                $q->whereIn('entity_id', $modelClass::query()->select((new $modelClass())->getKeyName()));
            });

        if ($scopeCallback !== null) {
            $scopeCallback($base, $this->entity);
        }

        return $base;
    }

    /** Whether a localizable field's translated labels collide with an existing row. */
    private function hasLocalizableConflict(Builder $base, Field $field): bool
    {
        $labels = collect($field->toStorage())
            ->flatMap(fn ($item) => $item['translations'] ?? [])
            ->filter(fn ($t) => isset($t['value']) && $t['value'] !== null && $t['value'] !== '');

        if ($labels->isEmpty()) {
            return false;
        }

        return Eav::$entityTranslationModel::query()
            ->where('entity_type', 'entity_attribute')
            ->whereIn('entity_id', $base->select('id'))
            ->where(function ($q) use ($labels) {
                foreach ($labels as $t) {
                    // Use LOWER() for case-insensitive comparison on both MySQL and PostgreSQL.
                    $q->orWhere(fn ($q) => $q
                        ->where('locale_id', $t['locale_id'])
                        ->whereRaw('LOWER(label) = ?', [mb_strtolower((string) $t['value'])]));
                }
            })
            ->exists();
    }

    /** Whether a non-localizable field's raw values collide with an existing row. */
    private function hasScalarConflict(Builder $base, Field $field): bool
    {
        $values = array_values(array_filter(array_column($field->toStorage(), 'value'), fn ($v) => $v !== null));

        if (empty($values)) {
            return false;
        }

        return $base->whereNotNull($field->column())->whereIn($field->column(), $values)->exists();
    }
}
