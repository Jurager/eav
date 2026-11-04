---
title: Listening for events
weight: 84
---

Schema operations dispatch these events after saving the complete definition. Events use the `Jurager\Eav\Events` namespace:

| Event                   | Property                | When                                    |
|-------------------------|-------------------------|-----------------------------------------|
| `AttributeCreated`      | `Attribute $attribute`  | Attribute created                       |
| `AttributeUpdated`      | `Attribute $attribute`  | Attribute updated                       |
| `AttributeDeleted`      | `Attribute $attribute`  | Attribute deleted                       |
| `AttributeGroupCreated` | `AttributeGroup $group` | Group created                           |
| `AttributeGroupUpdated` | `AttributeGroup $group` | Group updated                           |
| `AttributeGroupDeleted` | `AttributeGroup $group` | Group deleted                           |
| `AttributeEnumCreated`  | `AttributeEnum $enum`   | Enum value created                      |
| `AttributeEnumUpdated`  | `AttributeEnum $enum`   | Enum value updated                      |
| `AttributeEnumDeleted`  | `AttributeEnum $enum`   | Enum value deleted                      |
| `EntityValuesChanged`   | `string $entityType`, `array $entityIds` | Attribute values written or dropped |

Attribute types also dispatch `AttributeTypeCreated`, `AttributeTypeUpdated`, and `AttributeTypeDeleted` through their model observer. Direct Eloquent schema writes refresh caches and indexes without schema domain events.

Laravel auto-discovers listeners by type-hint on `handle()`, so no manual registration is needed:

```php
namespace App\Listeners;

use Jurager\Eav\Events\AttributeCreated;

class AttachAttributeToDefaultCategory
{
    public function handle(AttributeCreated $event): void
    {
        if (in_array('product', $event->attribute->applicableEntityTypes(), true)) {
            // Assign the attribute to your default category.
        }
    }
}
```
