<?php

declare(strict_types=1);

namespace Jurager\Eav\Tests\Fixtures;

use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Jurager\Eav\Concerns\HasAttributeScope;

/**
 * A product scoped by categories for testing attributeScopeMatchesTree().
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
