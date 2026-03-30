---
title: Events
weight: 95
---

# Events

`AttributeSchemaManager` dispatches a domain event after every successful mutation. All events live in the `Jurager\Eav\Events\` namespace.

## Event List

| Event | Property | Dispatched when |
|---|---|---|
| `AttributeCreated` | `readonly Attribute $attribute` | Attribute created |
| `AttributeUpdated` | `readonly Attribute $attribute` | Attribute updated (fresh instance) |
| `AttributeDeleted` | `readonly Attribute $attribute` | Attribute deleted (snapshot before deletion) |
| `AttributeGroupCreated` | `readonly AttributeGroup $group` | Group created |
| `AttributeGroupUpdated` | `readonly AttributeGroup $group` | Group updated (fresh instance) |
| `AttributeGroupDeleted` | `readonly AttributeGroup $group` | Group deleted (snapshot) |
| `AttributeEnumCreated` | `readonly AttributeEnum $enum` | Enum value created |
| `AttributeEnumUpdated` | `readonly AttributeEnum $enum` | Enum value updated (fresh instance) |
| `AttributeEnumDeleted` | `readonly AttributeEnum $enum` | Enum value deleted (snapshot) |

## Listening

Laravel auto-discovers listeners in `app/Listeners/` by the type-hint in the `handle()` method — no manual registration needed:

```php
namespace App\Listeners;

use Jurager\Eav\Events\AttributeCreated;

class MyAttributeListener
{
    public function handle(AttributeCreated $event): void
    {
        $attribute = $event->attribute;
        // ...
    }
}
```

## Example — Attach to Default Category

A common use case is attaching a newly created product attribute to a default category:

```php
namespace App\Listeners;

use App\Services\CategoryService;
use Jurager\Eav\Events\AttributeCreated;

class AttachAttributeToDefaultCategoryListener
{
    public function __construct(
        private readonly CategoryService $categoryService,
    ) {}

    public function handle(AttributeCreated $event): void
    {
        if ($event->attribute->entity_type === 'product') {
            $this->categoryService->attachDefaultCategory($event->attribute->id);
        }
    }
}
```
