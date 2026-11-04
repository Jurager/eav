<?php

declare(strict_types=1);

namespace Jurager\Eav\Tests\Fixtures;

use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/** Product whose available attributes come from its categories and the ancestors they inherit from. */
class TreeProduct extends Product
{
    protected static function attributeScopeModel(): ?string
    {
        return TreeCategory::class;
    }

    protected function attributeScopeRelationName(): ?string
    {
        return 'categories';
    }

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(static::attributeScopeModel(), 'category_product', 'product_id', 'category_id');
    }
}
