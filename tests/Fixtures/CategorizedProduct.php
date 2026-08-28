<?php

declare(strict_types=1);

namespace Jurager\Eav\Tests\Fixtures;

use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A {@see Product} scoped by categories, the way a real product/offer pair would be — used to
 * exercise {@see \Jurager\Eav\Concerns\HasInheritedAttributes::attributeScopeMatchesTree()}
 * without pulling in a nested-set dependency the base package doesn't require.
 */
class CategorizedProduct extends Product
{
    public function getEntityType(): string
    {
        return 'categorized_product';
    }

    protected static function attributeScopeModel(): ?string
    {
        return Category::class;
    }

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class, 'category_product', 'product_id', 'category_id');
    }
}
