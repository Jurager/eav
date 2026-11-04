---
title: Working with variants
weight: 82
---

Variants share a schema with a parent entity and may inherit its values.

Define the parent relation and return its name:

```php
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Jurager\Eav\Concerns\HasAttributes;
use Jurager\Eav\Contracts\Attributable;

class Product extends Model implements Attributable
{
    use HasAttributes;

    protected function attributeParentRelationName(): ?string
    {
        return 'parent';
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(static::class, 'parent_id');
    }
}
```

An entity is a variant when its parent relation resolves to a model.

## Which side holds an attribute

Use `held_by` and `inherit_from_parent` to control value ownership:

| Value                 | Parent      | Variant                                   |
|-----------------------|-------------|-------------------------------------------|
| `held_by: parent`     | fills it in | never                                     |
| `held_by: variant`    | never       | fills it in                               |
| `held_by: both`       | fills it in | may override the parent's value           |
| `inherit_from_parent` | fills it in | reads the parent's value when it has none |

Read effective values through the usual API:

```php
$offer->eav()->value('color');   // its own — held_by: variant
$offer->eav()->value('name');    // the model's — inherit_from_parent
```

`validate()` rejects attributes held by the other side.

## Scope and indexing

A variant without its own scope uses its parent's scope.

Search indexes include inherited values. Updating a parent also reindexes its variants.
