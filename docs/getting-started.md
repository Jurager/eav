---
title: Getting Started
weight: 10
---

This guide will walk you through installing the package and using its core features to make your first Eloquent model attributable.

## 1. Installation

First, install the package via Composer:

```bash
composer require jurager/eav
```

Next, publish the configuration file and migrations using the `vendor:publish` command:

```bash
php artisan vendor:publish --provider="Jurager\Eav\EavServiceProvider"
```

Finally, run the database migrations to create the necessary EAV tables:

```bash
php artisan migrate
```

## 2. Model Setup

To make an eloquent model support attributes, you need to implement the `Jurager\Eav\Contracts\Attributable` contract and use the `Jurager\Eav\Concerns\HasAttributes` trait.

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

Every entity needs a type identifier — a short string stored in the `entity_type` column. By default it's the model's Eloquent morph class, so registering a `morphMap` alias in your `AppServiceProvider` is enough:

```php
// app/Providers/AppServiceProvider.php

use Illuminate\Database\Eloquent\Relations\Relation;

public function boot()
{
    Relation::morphMap([
        'product' => \App\Models\Product::class,
    ]);
}
```

If you'd rather not rely on the morph map, override `getEntityType()` directly:

```php
public function getEntityType(): string
{
    return 'product';
}
```

## 3. Defining Attributes

You can define your attribute schema using the `Jurager\Eav\Facades\Schema` facade.

Let's define a `color` attribute for our `product` entity type:

```php
use Jurager\Eav\Facades\Schema;

Schema::attribute('color', 'product')
    ->type('text')
    ->label('Color')
    ->create();
```

This creates a new text attribute named "Color" for all `Product` models.

## 4. Usage

Now that you have configured your model and defined an attribute, you can start reading and writing values. The `eav()` accessor provides the interface for all interactions.

### Writing Values

You can set an attribute value using the fluent `set()` and `save()` methods.

```php
$product = Product::create(['name' => 'My Awesome T-Shirt']);

// Set and save the 'color' attribute
$product->eav()->set('color', 'blue')->save('color');
```

### Reading Values

To retrieve the value, use the `value()` method.

```php
$product = Product::find(1);

$color = $product->eav()->value('color'); // "blue"
```

That's it! You've successfully integrated the package.

## Next Steps

Now that you're familiar with the basics, you can explore the more advanced features of the package:

- [**Field Types**](field-types.md): Learn about all the built-in field types and their options.
- [**Managing Schema**](schema.md): Dive deeper into creating and managing attributes, groups, and enums.
- [**Reading & Writing Attributes**](attributes.md): Explore more ways to persist and query attribute values.
- [**Querying**](querying.md): Filter your models by EAV attributes using powerful Eloquent scopes.
- [**Localization**](localization.md): Learn how to store and retrieve values for different locales.
- [**Advanced Topics**](advanced.md): Discover features like attribute inheritance and Scout search integration.
