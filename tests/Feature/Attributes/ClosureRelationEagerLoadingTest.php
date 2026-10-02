<?php

declare(strict_types=1);

namespace Jurager\Eav\Tests\Feature\Attributes;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Jurager\Eav\Enums\HeldBy;
use Jurager\Eav\Tests\Feature\FeatureTestCase;
use Jurager\Eav\Tests\Fixtures\ScopedCategory;
use Jurager\Eav\Tests\Fixtures\ScopedProduct;

class ClosureRelationEagerLoadingTest extends FeatureTestCase
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

    public function test_relation_loads_the_related_models_default_eager_loads(): void
    {
        // Attribute::$with = ['entityTypes'] — reading it after a lazy load must not query per model.
        $category = ScopedCategory::create(['name' => 'Tools']);
        $type = $this->createAttributeType('text');

        foreach (range(1, 3) as $i) {
            $attribute = $this->createAttribute($type, ['code' => "code_{$i}", 'entity_types' => ['product']]);
            DB::table('category_attribute')->insert(['category_id' => $category->id, 'attribute_id' => $attribute->id]);
        }

        $product = ScopedProduct::create(['name' => 'Widget']);
        $product->categories()->attach($category->id);

        $attributes = $product->available_attributes;

        $this->assertCount(3, $attributes);
        $this->assertTrue($attributes->first()->relationLoaded('entityTypes'));

        DB::enableQueryLog();
        DB::flushQueryLog();

        $types = $attributes->map(fn ($attribute) => $attribute->applicableEntityTypes())->all();

        $this->assertCount(0, DB::getQueryLog());
        $this->assertSame([['product'], ['product'], ['product']], $types);

        DB::disableQueryLog();
    }

    public function test_nested_eager_loads_are_applied(): void
    {
        $category = ScopedCategory::create(['name' => 'Tools']);
        $type = $this->createAttributeType('text');
        $attribute = $this->createAttribute($type, ['code' => 'title', 'entity_types' => ['product']]);

        DB::table('category_attribute')->insert(['category_id' => $category->id, 'attribute_id' => $attribute->id]);

        $product = ScopedProduct::create(['name' => 'Widget']);
        $product->categories()->attach($category->id);

        $loaded = ScopedProduct::with('available_attributes.entityTypes')->find($product->id);

        $this->assertTrue($loaded->available_attributes->first()->relationLoaded('entityTypes'));
    }

    public function test_parents_with_the_same_scope_share_one_query_and_one_set_of_models(): void
    {
        $category = ScopedCategory::create(['name' => 'Tools']);
        $type = $this->createAttributeType('text');
        $attribute = $this->createAttribute($type, ['code' => 'title', 'entity_types' => ['product']]);

        DB::table('category_attribute')->insert(['category_id' => $category->id, 'attribute_id' => $attribute->id]);

        $ids = [];

        foreach (range(1, 3) as $i) {
            $product = ScopedProduct::create(['name' => "Widget $i"]);
            $product->categories()->attach($category->id);
            $ids[] = $product->id;
        }

        DB::enableQueryLog();
        DB::flushQueryLog();

        $products = ScopedProduct::with('available_attributes')->whereIn('id', $ids)->get();

        $attributeQueries = collect(DB::getQueryLog())
            ->filter(fn (array $query): bool => str_contains($query['query'], 'from "attributes"'))
            ->count();

        DB::disableQueryLog();

        $this->assertSame(1, $attributeQueries);
        $this->assertSame(
            $products[0]->available_attributes->first(),
            $products[1]->available_attributes->first(),
        );
    }

    public function test_variant_inherits_the_parent_scope_attributes(): void
    {
        // Variants carry no scope of their own and fall back to the parent's when the relation resolves.
        $category = ScopedCategory::create(['name' => 'Tools']);
        $type = $this->createAttributeType('text');
        $attribute = $this->createAttribute($type, [
            'code' => 'title',
            'entity_types' => ['product'],
            'held_by' => HeldBy::Both,
        ]);

        DB::table('category_attribute')->insert(['category_id' => $category->id, 'attribute_id' => $attribute->id]);

        $parent = ScopedProduct::create(['name' => 'Parent']);
        $parent->categories()->attach($category->id);

        $variant = ScopedProduct::create(['name' => 'Variant', 'parent_id' => $parent->id]);

        $this->assertSame([$category->id], $variant->attributeScopeIds());
        $this->assertSame(['title'], $variant->available_attributes->pluck('code')->all());
    }
}
