<?php

declare(strict_types=1);

namespace Jurager\Eav\Tests\Fixtures;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Collection;
use Jurager\Eav\Attributes\AttributeManager;
use Jurager\Eav\Contracts\Attributable;
use Jurager\Eav\Facades\Attributes;
use Jurager\Eav\Models\Attribute;
use Jurager\Eav\Models\EntityAttribute;

/** An entity implementing the contract on its own, without the package's traits; it hides the `hidden` attribute. */
class PlainEntity extends Model implements Attributable
{
    /** How many times the available attributes query was built. */
    public static int $availableQueries = 0;

    protected $table = 'products';

    protected $fillable = ['name'];

    public function eav(): AttributeManager
    {
        return Attributes::for($this);
    }

    public function attributeValues(): MorphMany
    {
        return $this->morphMany(EntityAttribute::class, 'entity');
    }

    public function getEntityType(): string
    {
        return 'product';
    }

    public function attributeScopeIds(): array
    {
        return [];
    }

    public function attributeScopeEntities(): Collection
    {
        return collect();
    }

    public function attributeScopeMatchesTree(array $rootIds): bool
    {
        return true;
    }

    public function attributeParent(): ?Attributable
    {
        return null;
    }

    public function isVariant(): bool
    {
        return false;
    }

    public function shouldInheritAttributes(): bool
    {
        return false;
    }

    public function getInheritanceColumns(): array
    {
        return ['id'];
    }

    public function inheritanceParentKey(): string
    {
        return 'parent_id';
    }

    public function attributeIdsInScope(): ?array
    {
        return $this->getAvailableAttributesQuery()->pluck('id')->all();
    }

    public function attributeParentRelation(): ?BelongsTo
    {
        return null;
    }

    public static function attributeUniqueScopes(): array
    {
        return [];
    }

    public function getAvailableAttributesQuery(array $scopes = []): ?Builder
    {
        self::$availableQueries++;

        return Attribute::query()->forEntity($this->getEntityType())->where('code', '!=', 'hidden');
    }
}
