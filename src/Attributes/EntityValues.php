<?php

declare(strict_types=1);

namespace Jurager\Eav\Attributes;

use Closure;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator as Paginator;
use Illuminate\Support\Collection;
use Jurager\Eav\Contracts\Attributable;
use Jurager\Eav\Eav;
use Jurager\Eav\Enums\HeldBy;
use Jurager\Eav\Fields\Field;
use Jurager\Eav\Fields\FieldFactory;
use Jurager\Eav\Models\Attribute;
use Jurager\Eav\Scopes\ActiveLocaleScope;

/**
 * Resolve an entity's stored and inherited attribute values.
 */
class EntityValues
{
    /**
     * Relations a listed value row is rendered with.
     */
    private const array LISTED_WITH = [
        'attribute.type', 'attribute.group.translations', 'attribute.translations', 'attribute.enums.translations', 'translations',
    ];

    public function __construct(
        private readonly Attributable $entity,
        private readonly FieldFactory $fields,
    ) {}

    /**
     * Rows of the given attributes.
     *
     * @param  list<int|string>  $attributeIds
     * @return Collection<int, Model>
     */
    public function of(array $attributeIds): Collection
    {
        if (($loaded = $this->loaded()) !== null) {
            return $loaded->whereIn('attribute_id', $attributeIds)->values();
        }

        return $this->query()
            ->whereIn('attribute_id', $attributeIds)
            ->with(['translations' => fn ($query) => $query->withoutGlobalScope(ActiveLocaleScope::class)])
            ->get();
    }

    /**
     * Get rows for searchable or filterable attributes.
     *
     * @return Collection<int, Model>
     */
    public function indexable(): Collection
    {
        if (($loaded = $this->loaded()) !== null) {
            return $loaded->filter(static fn (Model $value): bool => self::indexed($value->attribute))->values();
        }

        return $this->query()
            ->whereHas('attribute', fn ($query) => $query->whereAny(['searchable', 'filterable'], true))
            ->with([
                'attribute',
                'attribute.enums.translations' => fn ($query) => $query->withoutGlobalScope(ActiveLocaleScope::class),
                'translations' => fn ($query) => $query->withoutGlobalScope(ActiveLocaleScope::class),
            ])
            ->get();
    }

    /**
     * Get stored rows with their resolved field values.
     *
     * @param  list<string>|null  $codes
     * @param  (Closure(Builder): mixed)|null  $constraint
     */
    public function list(?array $codes = null, ?int $perPage = null, ?Closure $constraint = null): Collection|LengthAwarePaginator
    {
        // No codes asked for, no rows — the same whether the relation is loaded or not.
        if ($codes === []) {
            return $perPage === null ? collect() : new Paginator([], 0, $perPage);
        }

        $fields = [];
        $field = function (Attribute $attribute) use (&$fields): Field {
            return $fields[spl_object_id($attribute)] ??= $this->fields->make($attribute)->forEntity($this->entity);
        };

        $resolve = static function (Model $row) use ($field): Model {
            $row->setAttribute('value', $field($row->attribute)->read($row));

            return $row;
        };

        if ($perPage === null && $constraint === null && ($loaded = $this->loaded()) !== null) {
            $rows = $codes === null ? $loaded : $loaded->filter(
                static fn (Model $row): bool => $row->relationLoaded('attribute') && in_array($row->attribute?->code, $codes, true),
            );

            $this->loadMissingRowRelations($rows);
            $this->loadOwnerRelations($rows, $field);

            return $rows->map($resolve)->values();
        }

        $query = $this->query();

        if ($constraint !== null) {
            $constraint($query);
        }

        $query
            ->when(
                $codes,
                fn (Builder $query) => $query->whereHas('attribute', fn (Builder $attribute) => $attribute->whereIn('code', $codes)),
                fn (Builder $query) => $query->whereHas('attribute'),
            )
            ->with(self::LISTED_WITH);

        if ($perPage === null) {
            $rows = $query->get();
            $this->loadOwnerRelations($rows, $field);

            return $rows->map($resolve);
        }

        $page = $query->paginate($perPage);
        $this->loadOwnerRelations($page->getCollection(), $field);

        return $page->through($resolve);
    }

    /**
     * Get loaded values, preferring variant values over inherited ones.
     *
     * @return Collection<int, Model>|null
     */
    public function loaded(): ?Collection
    {
        if (! $this->entity instanceof Model || ! $this->entity->relationLoaded('attribute_values')) {
            return null;
        }

        $own = $this->entity->getRelation('attribute_values');
        $parent = $this->entity->attributeParent();

        if (! $parent instanceof Model) {
            return $own;
        }

        if (! $parent->relationLoaded('attribute_values')) {
            return null;
        }

        $filled = $own->loadMissing('attribute')->filter(
            static fn (Model $value): bool => $value->hasValue() && $value->attribute?->isHeldBy(HeldBy::Variant),
        );
        $overridden = $filled->pluck('attribute_id')->all();

        return $filled->concat($parent->getRelation('attribute_values')->loadMissing('attribute')->filter(
            static fn (Model $value): bool => (bool) $value->attribute?->inherit_from_parent
                && ! in_array($value->attribute_id, $overridden, true),
        ));
    }

    /**
     * Query of the rows the entity reads.
     */
    public function query(): Builder
    {
        return Eav::$entityAttributeModel::query()->forEntity($this->entity);
    }

    private static function indexed(?Attribute $attribute): bool
    {
        return (bool) ($attribute?->searchable || $attribute?->filterable);
    }

    /**
     * Load the definition and translations of rows that miss them, in one pass for all.
     */
    private function loadMissingRowRelations(Collection $rows): void
    {
        $incomplete = $rows->contains(static fn (Model $row): bool => ! $row->relationLoaded('attribute')
            || ! $row->relationLoaded('translations')
            || ($row->getRelation('attribute') !== null && ! $row->getRelation('attribute')->relationLoaded('type')));

        if ($incomplete) {
            $rows->loadMissing(['attribute.type', 'translations']);
        }
    }

    /**
     * Set each row's owning entity and load the relations needed by its field.
     *
     * @param  Closure(Attribute): Field  $field
     */
    private function loadOwnerRelations(Collection $rows, Closure $field): void
    {
        $parent = $this->entity->attributeParent();
        $parentKey = $parent !== null ? (string) $parent->getKey() : null;
        $owners = [];
        $relations = [];

        foreach ($rows as $row) {
            $owner = $parentKey !== null && (string) $row->entity_id === $parentKey ? $parent : $this->entity;
            $row->setRelation('entity', $owner);

            if ($owner instanceof Model && $owner->hasRelationAutoloadCallback()) {
                continue;
            }

            $owners[spl_object_id($owner)] = $owner;
            $relations[spl_object_id($row->attribute)] ??= $field($row->attribute)->eagerLoads();
        }

        $relations = array_values(array_unique(array_merge(...array_values($relations))));

        if ($relations !== []) {
            (new EloquentCollection(array_values($owners)))->loadMissing($relations);
        }
    }
}
