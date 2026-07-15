<?php

namespace Jurager\Eav\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Eloquent\SoftDeletes;
use Jurager\Eav\Fields\FieldFactory;
use Jurager\Eav\Support\EavModels;
use ReflectionClass;

/**
 * @property int $id
 * @property string $entity_type
 * @property int $attribute_type_id
 * @property int|null $attribute_group_id
 * @property string $code
 * @property int $sort
 * @property bool $required
 * @property bool $localizable
 * @property bool $multiple
 * @property bool $unique
 * @property bool $filterable
 * @property bool $searchable
 * @property array|null $validations
 * @property array|null $meta
 *
 * @mixin Builder
 */
class Attribute extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'entity_type', 'attribute_type_id', 'attribute_group_id',
        'code', 'sort', 'required', 'localizable', 'multiple', 'unique',
        'filterable', 'searchable', 'validations', 'meta',
    ];

    protected static function booted(): void
    {
        static::forceDeleting(static function (Attribute $attribute) {
            $attribute->translations()->delete();
        });

        static::saving(static function (Attribute $attribute) {
            if ($attribute->type && $attribute->type->code === 'select') {
                $attribute->localizable = false;
            }
        });

        static::addGlobalScope('ordered', static function (Builder $query) {
            $query->orderBy('attribute_group_id')->orderBy('sort')->orderBy('id');
        });
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(EavModels::class('attribute_type'), 'attribute_type_id');
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(EavModels::class('attribute_group'), 'attribute_group_id');
    }

    public function enums(): HasMany
    {
        return $this->hasMany(EavModels::class('attribute_enum'), 'attribute_id');
    }

    /**
     * Consumed by Jurager\Microservice\JsonApi\Concerns\WithEagerIncludes: narrows
     * relations that would otherwise over-fetch when eager-loaded for JSON:API
     * `included`, wherever they're reached in the include tree.
     */
    public static function eagerConstraints(): array
    {
        return [
            'enums' => [self::class, 'constrainEnumsEagerLoad'],
        ];
    }

    /**
     * Consumed by Jurager\Microservice\JsonApi\Concerns\WithEagerIncludes: skips
     * the `enums` query entirely for attributes whose field type structurally
     * can't have enum options (text, number, date, ...), instead of asking
     * and getting nothing back.
     */
    public static function eagerApplicable(): array
    {
        return [
            'enums' => [self::class, 'hasEnumOptions'],
        ];
    }

    /**
     * Whether this attribute's field type can have enum options at all, per
     * the same Field::isEnum() flag the eav field system already uses (see
     * Jurager\Eav\Fields\Select) — so this stays correct if a consuming app
     * registers its own enum-backed field type.
     */
    public static function hasEnumOptions(self $attribute): bool
    {
        $code = $attribute->type?->code;
        $factory = app(FieldFactory::class);

        if ($code === null || ! $factory->has($code)) {
            return false;
        }

        return (new ReflectionClass($factory->resolve($code)))
            ->newInstanceWithoutConstructor()
            ->isEnum();
    }

    /**
     * Scope `enums` to the values actually referenced by the attribute_values
     * (entity_attribute) rows that led here, instead of returning every option
     * of the attribute (e.g. all 800 brands instead of the ones products use).
     *
     * No-op when `enums` isn't reached through an entity_attribute chain, e.g.
     * browsing an attribute's full option list directly (`/attributes?include=enums`).
     */
    public static function constrainEnumsEagerLoad(Relation $query, EloquentCollection $root, string $path): void
    {
        $segments = explode('.', $path);

        if (count($segments) < 3) {
            return;
        }

        $entityAttributes = static::walkAncestors($root, array_slice($segments, 0, -2));

        if ($entityAttributes->isEmpty() || ! $entityAttributes->first() instanceof EntityAttribute) {
            return;
        }

        $query->whereIn('id', $entityAttributes->pluck('value_integer')->filter()->unique());
    }

    private static function walkAncestors(EloquentCollection $models, array $segments): EloquentCollection
    {
        foreach ($segments as $segment) {
            $models = EloquentCollection::make(
                $models
                    ->filter(fn (Model $model) => $model->relationLoaded($segment))
                    ->map(fn (Model $model) => $model->getRelation($segment))
                    ->flatten(1)
                    ->filter()
            );
        }

        return $models;
    }

    public function translations(): MorphToMany
    {
        return $this->morphToMany(EavModels::class('locale'), 'entity', 'entity_translations')
            ->using(EavModels::class('entity_translation'))
            ->withPivot(['id', 'label', 'params'])
            ->withTimestamps()
            ->active();
    }

    /**
     * Scope: filter by entity type.
     */
    public function scopeForEntity(Builder $query, string $entityType): Builder
    {
        return $query->where('entity_type', $entityType);
    }

    /**
     * Scope: filter searchable attributes.
     */
    public function scopeWhereSearchable(Builder $query): Builder
    {
        return $query->where('searchable', true);
    }

    /**
     * Scope: filter filterable attributes.
     */
    public function scopeWhereFilterable(Builder $query): Builder
    {
        return $query->where('filterable', true);
    }

    /**
     * Scope: eager load common relationships.
     */
    public function scopeWithRelations(Builder $query): Builder
    {
        return $query->with([
            'type',
            'group.translations',
            'translations',
        ]);
    }

    protected function casts(): array
    {
        return [
            'validations' => 'array',
            'meta' => 'array',
            'required' => 'boolean',
            'localizable' => 'boolean',
            'multiple' => 'boolean',
            'unique' => 'boolean',
            'filterable' => 'boolean',
            'searchable' => 'boolean',
        ];
    }
}
