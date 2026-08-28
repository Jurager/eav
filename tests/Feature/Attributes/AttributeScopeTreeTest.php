<?php

declare(strict_types=1);

namespace Jurager\Eav\Tests\Feature\Attributes;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Jurager\Eav\Tests\Feature\FeatureTestCase;
use Jurager\Eav\Tests\Fixtures\Category;
use Jurager\Eav\Tests\Fixtures\CategorizedProduct;

class AttributeScopeTreeTest extends FeatureTestCase
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
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('category_product');
        Schema::dropIfExists('categories');

        parent::tearDown();
    }

    public function test_empty_root_ids_always_match(): void
    {
        $product = CategorizedProduct::create(['name' => 'Widget']);

        $this->assertTrue($product->attributeScopeMatchesTree([]));
    }

    public function test_matches_when_own_category_is_among_the_root_ids(): void
    {
        $category = Category::create(['name' => 'Tools']);
        $product  = CategorizedProduct::create(['name' => 'Widget']);
        $product->categories()->attach($category->id);

        $this->assertTrue($product->attributeScopeMatchesTree([$category->id]));
    }

    public function test_does_not_match_when_own_category_is_outside_the_root_ids(): void
    {
        $category = Category::create(['name' => 'Tools']);
        $other    = Category::create(['name' => 'Garden']);
        $product  = CategorizedProduct::create(['name' => 'Widget']);
        $product->categories()->attach($category->id);

        $this->assertFalse($product->attributeScopeMatchesTree([$other->id]));
    }

    public function test_falls_back_to_the_parent_categories_when_the_variant_has_none_of_its_own(): void
    {
        $category = Category::create(['name' => 'Tools']);
        $parent   = CategorizedProduct::create(['name' => 'Widget']);
        $parent->categories()->attach($category->id);

        $variant = CategorizedProduct::create(['name' => 'Widget — Red', 'parent_id' => $parent->id]);

        $this->assertTrue($variant->attributeScopeMatchesTree([$category->id]));
    }

    public function test_does_not_match_when_neither_the_entity_nor_its_parent_has_categories(): void
    {
        $category = Category::create(['name' => 'Tools']);
        $parent   = CategorizedProduct::create(['name' => 'Widget']);
        $variant  = CategorizedProduct::create(['name' => 'Widget — Red', 'parent_id' => $parent->id]);

        $this->assertFalse($variant->attributeScopeMatchesTree([$category->id]));
    }
}
