<?php

declare(strict_types=1);

namespace Jurager\Eav\Tests\Fixtures;

use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Jurager\Eav\Concerns\HasAttributes;
use Jurager\Eav\Contracts\Attributable;
use Jurager\Eav\Models\Attribute;
use Jurager\Eav\Models\AttributeScope;

/** Category whose attribute pivot (`category_attribute`) is shared by every scoped entity type. */
class ScopedCategory extends Category implements Attributable
{
    use HasAttributes;

    public function getEntityType(): string
    {
        return 'category';
    }

    public function attributeScopeRelation(): BelongsToMany
    {
        return $this->belongsToMany(Attribute::class, 'category_attribute', 'category_id', 'attribute_id')->using(AttributeScope::class);
    }

    public function getInheritanceColumns(): array
    {
        return ['id'];
    }
}
