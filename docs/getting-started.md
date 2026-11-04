---
title: Installation and setup
weight: 10
---

You can install the package using Composer:

```bash
composer require jurager/eav
```

Publish the configuration and migrations:

```bash
php artisan vendor:publish --provider="Jurager\Eav\EavServiceProvider"
```

Run the migrations:

```bash
php artisan migrate
```

## Preparing your model

Implement `Attributable` and use the `HasAttributes` trait:

```php
// app/Models/Product.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Jurager\Eav\Concerns\HasAttributes;
use Jurager\Eav\Contracts\Attributable;

class Product extends Model implements Attributable
{
    use HasAttributes;
}
```

The entity type defaults to the model's morph class. Register an alias to use a short identifier:

```php
// app/Providers/AppServiceProvider.php

use Illuminate\Database\Eloquent\Relations\Relation;

public function boot(): void
{
    Relation::morphMap([
        'product' => \App\Models\Product::class,
    ]);
}
```

You may also override `getEntityType()`:

```php
public function getEntityType(): string
{
    return 'product';
}
```

## Creating your first attribute

Create the type and locale first, for example in a seeder:

```php
use Jurager\Eav\Facades\Translator;
use Jurager\Eav\Models\AttributeType;

AttributeType::firstOrCreate(['code' => 'text']);
Translator::locale('en')->name('English')->create();
```

Use the `Schema` facade to define attributes:

```php
use Jurager\Eav\Facades\Schema;

Schema::attribute('color', 'product')
    ->type('text')
    ->label('Color', 'en')
    ->create();
```

> [!NOTE]
> When using Laravel's Schema facade in the same file, import the EAV facade as `EavSchema`.

## Using the attribute

Use `eav()` to read and write attribute values.

You can assign an attribute like a model column and save the model:

```php
$product = Product::create(['name' => 'T-Shirt']);

$product->color = 'blue';
$product->save();
```

Read it with `value()`:

```php
$product = Product::find(1);

$color = $product->eav()->value('color'); // "blue"
```

Continue with [reading and writing attributes](attributes.md), or [define more attributes](schema.md).

Package settings are documented in [config/eav.php](https://github.com/Jurager/eav/blob/main/config/eav.php). Search setup is covered in [Scout integration](search.md).
