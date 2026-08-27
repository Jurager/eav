<?php

declare(strict_types=1);

namespace Jurager\Eav\Support;

use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Validation\ValidationException;
use JsonException;
use Jurager\Eav\Concerns\HasInheritedAttributes;
use Jurager\Eav\Contracts\Attributable;
use Jurager\Eav\Eav;
use Jurager\Eav\Enums\HeldBy;
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

    /** The foreign key naming the parent relation, when the model splits entities into parent/variant. */
    private ?string $parentForeignKey;

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

        $this->entityType = $entity->getEntityType();
        $this->entityId = $entity->id ?? null;

        $modelClass = Relation::getMorphedModel($this->entityType);

        $this->usesSoftDeletes = $modelClass && in_array(SoftDeletes::class, class_uses_recursive($modelClass));

        $this->parentForeignKey = $modelClass && in_array(HasInheritedAttributes::class, class_uses_recursive($modelClass))
            ? (new $modelClass())->attributeParentRelation()?->getForeignKeyName()
            : null;

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
        $touched = array_values(array_filter(array_column($input, 'code')));

        $this->validateFields($this->fillFields($input), $touched);

        return $this->manager->fields();
    }

    /**
     * Fill fields with input data, refusing the attributes the entity does not hold.
     *
     * @return array<string, list<string>> Errors keyed by attribute code
     *
     * @throws JsonException
     * @throws BindingResolutionException
     */
    private function fillFields(array $input): array
    {
        $codes = array_values(array_filter(array_column($input, 'code')));

        if (empty($codes)) {
            return [];
        }

        $this->manager->ensureFields($codes);

        $side    = HeldBy::of($this->entity->isVariant());
        $message = __("eav::attributes.validation.held_by_{$side->opposite()->value}");

        $errors = [];

        foreach ($input as $item) {
            $field = $this->manager->field($item['code'] ?? '');

            if ($field === null) {
                continue;
            }

            if (! $field->attribute()->isHeldBy($side)) {
                $errors[$field->attribute()->code] = [$message];

                continue;
            }

            $field->fill($item['values'] ?? null);
        }

        return $errors;
    }

    /**
     * Validate all fields and throw exception if errors found.
     *
     * @param array<string, list<string>> $errors
     * @param list<string> $touched
     *
     * @throws ValidationException
     */
    private function validateFields(array $errors, array $touched): void
    {
        foreach ($this->manager->fields() as $field) {

            $code = $field->attribute()->code;

            if ($field->hasErrors()) {
                $errors[$code] = array_merge($errors[$code] ?? [], $field->errors());
            } elseif ($field->isRequired() && ! $field->isFilled()) {
                $errors[$code][] = __('eav::attributes.validation.required');
            }

            if (in_array($code, $touched, true) && $field->isUnique() && $field->isFilled()) {
                $uniqueErrors = $this->validateUniqueness($field);
                if (! empty($uniqueErrors)) {
                    $errors[$code] = array_merge($errors[$code] ?? [], $uniqueErrors);
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

        $restrictToSide = $this->parentForeignKey !== null && $field->attribute()->held_by !== HeldBy::Both;

        $base = Eav::$entityAttributeModel::query()
            ->where('entity_type', $this->entityType)
            ->where('attribute_id', $field->attribute()->id)
            ->when($this->entityId, fn ($q) => $q->where('entity_id', '!=', $this->entityId))
            ->when(
                $this->usesSoftDeletes || $restrictToSide,
                fn ($q) => $q->whereIn('entity_id', $this->eligibleEntityIds($field, $restrictToSide)),
            );

        if ($scopeCallback !== null) {
            $scopeCallback($base, $this->entity);
        }

        return $base;
    }

    /**
     * IDs of the entities a uniqueness conflict may legitimately come from.
     *
     * Always excludes soft-deleted entities. When the attribute is held_by:parent or held_by:variant,
     * also excludes entities on the other side — a held_by:parent attribute can still have rows on a
     * variant (stray data left over from before the split existed, or written outside validation),
     * and those never represent a genuine duplicate.
     */
    private function eligibleEntityIds(Field $field, bool $restrictToSide): Builder
    {
        $modelClass = Relation::getMorphedModel($this->entityType);

        return $modelClass::query()
            ->when($restrictToSide, fn ($q) => $field->attribute()->held_by === HeldBy::Variant
                ? $q->whereNotNull($this->parentForeignKey)
                : $q->whereNull($this->parentForeignKey))
            ->select((new $modelClass())->getKeyName());
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
            ->where('entity_type', (new (Eav::$entityAttributeModel)())->getMorphClass())
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

        return $base->whereNotNull($field->column()->value)->whereIn($field->column()->value, $values)->exists();
    }
}
