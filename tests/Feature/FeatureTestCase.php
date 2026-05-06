<?php

declare(strict_types=1);

namespace Jurager\Eav\Tests\Feature;

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Jurager\Eav\Models\Attribute;
use Jurager\Eav\Models\AttributeEnum;
use Jurager\Eav\Models\AttributeType;
use Jurager\Eav\Models\Locale;
use Jurager\Eav\Registry\EnumRegistry;
use Jurager\Eav\Registry\LocaleRegistry;
use Jurager\Eav\Registry\SchemaRegistry;
use Jurager\Eav\Tests\Fixtures\Product;
use Jurager\Eav\Tests\TestCase;

abstract class FeatureTestCase extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        Relation::morphMap(['product' => Product::class]);

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        app(LocaleRegistry::class)->forget();
        app(SchemaRegistry::class)->forget();
        app(EnumRegistry::class)->forget();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('products');
        Relation::morphMap([]);

        parent::tearDown();
    }

    // -----------------------------------------------------------------------
    // Seed helpers
    // -----------------------------------------------------------------------

    protected function createLocale(string $code = 'en'): Locale
    {
        return Locale::create(['code' => $code, 'name' => strtoupper($code)]);
    }

    protected function createAttributeType(string $code = 'text'): AttributeType
    {
        return AttributeType::create(['code' => $code]);
    }

    protected function createAttribute(AttributeType $type, array $overrides = []): Attribute
    {
        return Attribute::create(array_merge([
            'entity_type'        => 'product',
            'attribute_type_id'  => $type->id,
            'code'               => 'name',
            'sort'               => 0,
            'mandatory'          => false,
            'localizable'        => false,
            'multiple'           => false,
            'unique'             => false,
            'filterable'         => false,
            'searchable'         => false,
        ], $overrides));
    }

    protected function createProduct(string $name = 'Widget'): Product
    {
        return Product::create(['name' => $name]);
    }

    protected function createEnum(Attribute $attribute, string $code): AttributeEnum
    {
        return AttributeEnum::create([
            'attribute_id' => $attribute->id,
            'code'         => $code,
        ]);
    }
}
