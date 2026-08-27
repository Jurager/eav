<?php

declare(strict_types=1);

namespace Jurager\Eav\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Jurager\Eav\Concerns\HasScopedRelations;
use Jurager\Eav\Contracts\Attributable;
use Jurager\Eav\Enums\AttributeStorage;
use Jurager\Eav\Registry\AttributeRegistry;
use Jurager\Eav\Relations\BelongsToScoped;
use Jurager\Eav\Eav;

/**
 * @property int $id
 * @property int $entity_id
 * @property string $entity_type
 * @property int $attribute_id
 * @property string|null $value_text
 * @property int|null $value_integer
 * @property float|null $value_float
 * @property bool|null $value_boolean
 * @property string|null $value_date
 * @property string|null $value_datetime
 */
class EntityAttribute extends Model
{
    use HasScopedRelations;

    protected $table = 'entity_attribute';

    protected $fillable = [
        'entity_id', 'entity_type', 'attribute_id',
        'value_text', 'value_integer', 'value_float',
        'value_boolean', 'value_date', 'value_datetime',
    ];

    protected function casts(): array
    {
        return [
            'value_boolean' => 'boolean',
            'value_date' => 'date',
            'value_datetime' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::deleting(static function (EntityAttribute $entityAttribute) {
            $entityAttribute->translations()->delete();
        });

        static::retrieved(function (EntityAttribute $entityAttribute) {
            if (! $entityAttribute->relationLoaded('attribute') && $entityAttribute->attribute_id !== null && $entityAttribute->entity_type !== null) {
                $entityAttribute->setRelation('attribute', app(AttributeRegistry::class)->get($entityAttribute->attribute_id, $entityAttribute->entity_type));
            }
        });
    }

    /** Scope the rows an entity reads: its own values, plus the ones a variant inherits. */
    public function scopeForEntity(Builder $query, Attributable $entity): Builder
    {
        $query->where('entity_type', $entity->getEntityType());

        $parent = $entity->attributeParent();

        if ($parent === null) {
            return $query->where('entity_id', $entity->id);
        }

        $own = static::query()
            ->select('attribute_id')
            ->where('entity_type', $entity->getEntityType())
            ->where('entity_id', $entity->id)
            ->whereHasValue();

        return $query->where(fn (Builder $rows) => $rows
            ->where(fn (Builder $ownRow) => $ownRow->where('entity_id', $entity->id)->whereHasValue())
            ->orWhere(fn (Builder $inherited) => $inherited
                ->where('entity_id', $parent->id)
                ->whereNotIn('attribute_id', $own)
                ->whereHas('attribute', static fn (Builder $attribute) => $attribute->whereInheritable())));
    }

    /** Scope to rows that carry a real stored value — a scalar column, or at least one translation. */
    public function scopeWhereHasValue(Builder $query): Builder
    {
        return $query->where(fn (Builder $q) => $q
            ->whereAny(array_column(AttributeStorage::cases(), 'value'), '!=', null)
            ->orWhereHas('translations'));
    }

    /** Whether this row carries a real stored value — a scalar column, or at least one translation. */
    public function hasValue(): bool
    {
        $hasScalar = collect(AttributeStorage::cases())->contains(fn ($column) => $this->getAttribute($column->value) !== null);

        // Translations aren't loaded in every context; assume filled rather than force an N+1 lookup.
        return $hasScalar || ! $this->relationLoaded('translations') || $this->translations->isNotEmpty();
    }

    public function attribute(): BelongsTo
    {
        return $this->belongsTo(Eav::$attributeModel);
    }

    public function enum(): BelongsToScoped
    {
        return $this->belongsToScoped(Eav::$attributeEnumModel, 'value_integer', 'attribute_id');
    }

    public function translations(): MorphToMany
    {
        return $this->morphToMany(Eav::$localeModel, 'entity', 'entity_translations')
            ->using(Eav::$entityTranslationModel)
            ->withPivot(['id', 'label', 'created_at', 'updated_at'])
            ->active();
    }
}
