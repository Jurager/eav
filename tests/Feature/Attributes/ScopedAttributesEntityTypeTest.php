<?php

declare(strict_types=1);

namespace Jurager\Eav\Tests\Feature\Attributes;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Jurager\Eav\Tests\Feature\FeatureTestCase;
use Jurager\Eav\Tests\Fixtures\ScopedCategory;
use Jurager\Eav\Tests\Fixtures\ScopedProduct;

class ScopedAttributesEntityTypeTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('categories', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('category_product', function (Blueprint $table): void {
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('category_id');
        });

        Schema::create('category_attribute', function (Blueprint $table): void {
            $table->unsignedBigInteger('category_id');
            $table->unsignedBigInteger('attribute_id');
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('category_attribute');
        Schema::dropIfExists('category_product');
        Schema::dropIfExists('categories');

        parent::tearDown();
    }

    public function test_category_scoped_attributes_are_narrowed_to_the_entity_type(): void
    {
        // The category pivot is shared by entity types (products and services in the app), so an
        // attribute attached to a category but applicable to another type must not leak in.
        $type = $this->createAttributeType('text');

        $own = $this->createAttribute($type, ['code' => 'title', 'entity_types' => ['product']]);
        $foreign = $this->createAttribute($type, ['code' => 'delivery_slot', 'entity_types' => ['service']]);

        $category = ScopedCategory::create(['name' => 'Tools']);

        DB::table('category_attribute')->insert([
            ['category_id' => $category->id, 'attribute_id' => $own->id],
            ['category_id' => $category->id, 'attribute_id' => $foreign->id],
        ]);

        $product = ScopedProduct::create(['name' => 'Widget']);
        $product->categories()->attach($category->id);
        $product->unsetRelation('categories');

        $product = $product->fresh();
        $scope = $product->attributeScopeIds();

        $this->assertSame([$category->id], $scope);

        $codes = $product->availableAttributes($scope)->pluck('code')->all();

        $this->assertSame(['title'], $codes);
    }
}
