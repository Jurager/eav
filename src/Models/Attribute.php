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
        'validations',
        'meta',
    ];

    protected static function booted(): void
    {
        static::forceDeleting(fn (Attribute $attribute) => $attribute->translations()->delete());

        static::saving(function (Attribute $attribute) {
            if ($attribute->type?->code === 'select') {
                $attribute->setAttribute('localizable', false);
            }
        });

        static::addGlobalScope('ordered', function (Builder $query) {
            $query->orderBy('attribute_group_id')->orderBy('sort')->orderBy('id');
        });
    }

    protected function casts(): array
    {
        return [
            'validations' => 'array',
            'meta'        => 'array',
            'required'    => 'boolean',
            'localizable' => 'boolean',
            'multiple'    => 'boolean',
            'unique'      => 'boolean',
            'filterable'  => 'boolean',
            'searchable'  => 'boolean',
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
            ->withTimestamps()
            ->active();
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
