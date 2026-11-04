<?php

declare(strict_types=1);

namespace Jurager\Eav\Attributes\Validation;

use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Validation\ValidationException;
use JsonException;
use Jurager\Eav\Attributes\AttributeManager;
use Jurager\Eav\Contracts\Attributable;
use Jurager\Eav\Eav;
use Jurager\Eav\Enums\HeldBy;
use Jurager\Eav\Fields\Field;

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

    /**
     * The foreign key naming the parent relation, when the model splits entities into parent/variant.
     */
    private ?string $parentForeignKey;

    /** @var array<string, callable> */
    private array $uniqueScopes;

    /**
     * Create a validator without changing the entity's fields.
     *
     * @throws BindingResolutionException
     * @throws JsonException
     */
    public function __construct(Attributable $entity, ?AttributeManager $manager = null)
    {
        $this->entity = $entity;
        $this->manager = $manager ?? $entity->eav();
        $this->manager->ensureSchema();

        $this->entityType = $entity->getEntityType();
        $this->entityId = $entity instanceof Model ? $entity->getKey() : null;

        // The entity itself says how it is stored — no morph map lookup, which an entity
        // overriding getEntityType() may not even have.
        $this->usesSoftDeletes = in_array(SoftDeletes::class, class_uses_recursive($entity), true);

        $this->parentForeignKey = $entity->attributeParentRelation()?->getForeignKeyName();
        $this->uniqueScopes = $entity::attributeUniqueScopes();
    }

    /**
     * Validate the full schema and return the submitted fields ready to persist.
     *
     * @return array<string, Field> The submitted fields, filled, by attribute code.
     *
     * @throws ValidationException|JsonException|BindingResolutionException
     */
    public function validate(array $input): array
    {
        [$filled, $errors] = $this->fillFields($input);

        $this->validateFields($filled, $errors);

        return $filled;
    }

    /**
     * Fill copies of the submitted fields, refusing the attributes the entity does not hold.
     *
     * @return array{array<string, Field>, array<string, list<string>>} The filled fields and the errors, by attribute code.
     *
     * @throws JsonException
     * @throws BindingResolutionException
     */
    private function fillFields(array $input): array
    {
        $side = HeldBy::of($this->entity->isVariant());
        $message = __("eav::attributes.validation.held_by_{$side->opposite()->value}");

        $filled = [];
        $errors = [];

        foreach ($input as $item) {
            $field = $this->manager->field((string) ($item['code'] ?? ''));

            if ($field === null) {
                continue;
            }

            if (! $field->attribute()->isHeldBy($side)) {
                $errors[$field->attribute()->code] = [$message];

                continue;
            }

            $copy = clone $field;
            $copy->fill($item['values'] ?? null);

            $filled[$copy->attribute()->code] = $copy;
        }

        return [$filled, $errors];
    }

    /**
     * Validate every field — the submitted values where given, the stored ones otherwise.
     *
     * @param  array<string, Field>  $filled
     * @param  array<string, list<string>>  $errors
     *
     * @throws ValidationException
     */
    private function validateFields(array $filled, array $errors): void
    {
        foreach ([...$this->manager->fields(), ...$filled] as $code => $field) {
            $touched = isset($filled[$code]);

            if ($field->hasErrors()) {
                $errors[$code] = array_merge($errors[$code] ?? [], $field->errors());
            } elseif ($field->isRequired() && ! $field->isFilled()) {
                $errors[$code][] = __('eav::attributes.validation.required');
            }

            if ($touched && $field->isUnique() && $field->isFilled()) {
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

    /**
     * The entity_attribute rows a uniqueness check should compare against.
     */
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
     * Get entities eligible for uniqueness checks, excluding deleted and ineligible variants.
     */
    private function eligibleEntityIds(Field $field, bool $restrictToSide): Builder
    {
        /** @var Model $entity */
        $entity = $this->entity;

        return $entity->newQuery()
            ->when($restrictToSide, fn ($q) => $field->attribute()->held_by === HeldBy::Variant
                ? $q->whereNotNull($this->parentForeignKey)
                : $q->whereNull($this->parentForeignKey))
            ->select($entity->getKeyName());
    }

    /**
     * Whether a localizable field's translated labels collide with an existing row.
     */
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

    /**
     * Whether a non-localizable field's raw values collide with an existing row.
     */
    private function hasScalarConflict(Builder $base, Field $field): bool
    {
        $values = array_values(array_filter(array_column($field->toStorage(), 'value'), fn ($v) => $v !== null));

        if (empty($values)) {
            return false;
        }

        return $base->whereNotNull($field->column()->value)->whereIn($field->column()->value, $values)->exists();
    }
}
