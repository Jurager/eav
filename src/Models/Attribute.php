<?php

declare(strict_types=1);

namespace Jurager\Eav\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Jurager\Eav\Eav;
use Jurager\Eav\Enums\HeldBy;
use Jurager\Eav\Registry\AttributeGroupRegistry;
use Jurager\Eav\Registry\AttributeTypeRegistry;

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
 * @property HeldBy $held_by
 * @property bool $inherit_from_parent
 * @property array|null $validations
 * @property array|null $meta
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 *
 * @property-read AttributeType|null $type
 * @property-read AttributeGroup|null $group
 * @property-read \Illuminate\Database\Eloquent\Collection<int, AttributeEnum> $enums
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Locale> $translations
 *
 * @mixin Builder
 */
class Attribute extends Model
{
    use SoftDeletes;
    protected $fillable = [
        'entity_type',
        'attribute_type_id',
        'attribute_group_id',
        'code',
        'sort',
        'required',
        'localizable',
        'multiple',
        'unique',
        'filterable',
        'searchable',
        'held_by',
        'inherit_from_parent',
        'validations',
        'meta',
    ];

    protected static function booted(): void
    {
        static::forceDeleting(fn (Attribute $attribute) => $attribute->translations()->detach());

        static::saving(static fn (Attribute $attribute) => static::enforceNonLocalizableForSelectType($attribute));

        static::retrieved(static fn (Attribute $attribute) => static::hydrateFromRegistries($attribute));

        static::addGlobalScope('ordered', function (Builder $query) {
            $query->orderBy('attribute_group_id')->orderBy('sort')->orderBy('id');
        });
    }

    /** Force select-type attributes to be non-localizable. */
    protected static function enforceNonLocalizableForSelectType(Attribute $attribute): void
    {
        if ($attribute->type?->code === 'select') {
            $attribute->setAttribute('localizable', false);
        }
    }

    /** Populate the type and group relations from the registry cache. */
    protected static function hydrateFromRegistries(Attribute $attribute): void
    {
        if (! $attribute->relationLoaded('type')) {
            $attribute->setRelation('type', $attribute->getAttribute('attribute_type_id') !== null
                ? app(AttributeTypeRegistry::class)->get($attribute->getAttribute('attribute_type_id'))
                : null);
        }

        if (! $attribute->relationLoaded('group')) {
            $attribute->setRelation('group', $attribute->getAttribute('attribute_group_id') !== null
                ? app(AttributeGroupRegistry::class)->get($attribute->getAttribute('attribute_group_id'))
                : null);
        }
    }

    protected function casts(): array
    {
        return [
            'attribute_type_id'  => 'integer',
            'attribute_group_id' => 'integer',
            'sort'        => 'integer',
            'validations' => 'array',
            'meta'        => 'array',
            'required'    => 'boolean',
            'localizable' => 'boolean',
            'multiple'    => 'boolean',
            'unique'      => 'boolean',
            'filterable'  => 'boolean',
            'searchable'  => 'boolean',

            'held_by'             => HeldBy::class,
            'inherit_from_parent' => 'boolean',
        ];
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(Eav::$attributeTypeModel, 'attribute_type_id');
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(Eav::$attributeGroupModel, 'attribute_group_id');
    }

    public function enums(): HasMany
    {
        return $this->hasMany(Eav::$attributeEnumModel, 'attribute_id');
    }

    public function translations(): MorphToMany
    {
        return $this->morphToMany(Eav::$localeModel, 'entity', 'entity_translations')
            ->using(Eav::$entityTranslationModel)
            ->withPivot(['id', 'label', 'params'])
            ->withTimestamps();
    }

    /** Scope a query to only include attributes for a given entity type. */
    public function scopeForEntity(Builder $query, string $entityType): Builder
    {
        return $query->where('entity_type', $entityType);
    }

    /** Scope a query to only include searchable attributes. */
    public function scopeWhereSearchable(Builder $query): Builder
    {
        return $query->where('searchable', true);
    }

    /** Scope a query to only include filterable attributes. */
    public function scopeWhereFilterable(Builder $query): Builder
    {
        return $query->where('filterable', true);
    }

    /** Scope a query to the attributes the given side fills in. */
    public function scopeWhereHeldBy(Builder $query, HeldBy $side): Builder
    {
        return $query->whereIn('held_by', [$side, HeldBy::Both]);
    }

    /** Scope a query to the attributes a variant takes from its parent when it has no value of its own. */
    public function scopeWhereInheritable(Builder $query): Builder
    {
        return $query->where('inherit_from_parent', true);
    }

    /** Determine if the given side fills this attribute in. */
    public function isHeldBy(HeldBy $side): bool
    {
        return $this->getAttribute('held_by') === $side || $this->getAttribute('held_by') === HeldBy::Both;
    }

    /** Scope a query to eager load common attribute relationships. */
    public function scopeWithRelations(Builder $query): Builder
    {
        return $query->with([
            'type',
            'group.translations',
            'translations',
        ]);
    }
}
