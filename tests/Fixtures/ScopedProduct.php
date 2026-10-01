<?php

declare(strict_types=1);

namespace Jurager\Eav\Tests\Fixtures;

use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/** Product whose available attributes come from the categories it is placed in. */
class ScopedProduct extends Product
{
    protected static function attributeScopeModel(): ?string
    {
        return ScopedCategory::class;
    }

    protected function attributeScopeRelationName(): ?string
    {
        return 'categories';
    }

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(ScopedCategory::class, 'category_product', 'product_id', 'category_id');
    }
}
