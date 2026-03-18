<?php

namespace Jurager\Eav\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Jurager\Eav\Support\EavModels;

/**
 * @property int $id
 * @property string $entity_type
 * @property int $attribute_type_id
 * @property int|null $attribute_group_id
 * @property string $code
 * @property int $sort
 * @property bool $mandatory
 * @property bool $localizable
 * @property bool $multiple
 * @property bool $unique
 * @property bool $filterable
 * @property bool $searchable
 * @property array|null $validations
 *
 * @mixin Builder
 */
class Attribute extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'entity_type', 'attribute_type_id', 'attribute_group_id',
        'code', 'sort', 'mandatory', 'localizable', 'multiple', 'unique',
        'filterable', 'searchable', 'validations',
    ];

    protected function casts(): array
    {
        return [
            'validations' => 'array',
            'mandatory' => 'boolean',
            'localizable' => 'boolean',
            'multiple' => 'boolean',
            'unique' => 'boolean',
            'filterable' => 'boolean',
            'searchable' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::forceDeleting(static function (Attribute $attribute) {
            $attribute->translations()->delete();
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

    public function translations(): MorphToMany
    {
        return $this->morphToMany(EavModels::class('locale'), 'entity', 'entity_translations')
            ->using(EavModels::class('entity_translation'))
            ->withPivot(['id', 'label', 'params', 'updated_at']);
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
}
