<?php

declare(strict_types=1);

namespace Jurager\Eav\Tests\Fixtures;

use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Jurager\Eav\Concerns\HasAttributes;
use Jurager\Eav\Contracts\Attributable;
use Jurager\Eav\Models\Attribute;
use Jurager\Eav\Models\AttributeScope;

/** Category that takes its parent's attributes when it inherits properties, walking the tree by `parent_id`. */
class TreeCategory extends Category implements Attributable
{
    use HasAttributes;

    protected function casts(): array
    {
        return ['is_inherits_properties' => 'boolean'];
    }

    public function getEntityType(): string
    {
        return 'category';
    }

    public function attributeScopeRelation(): BelongsToMany
    {
        return $this->belongsToMany(Attribute::class, 'category_attribute', 'category_id', 'attribute_id')->using(AttributeScope::class);
    }

    public function shouldInheritAttributes(): bool
    {
        return $this->is_inherits_properties && $this->parent_id;
    }

    public function getInheritanceColumns(): array
    {
        return ['id', 'parent_id', 'is_inherits_properties'];
    }
}
