<?php

declare(strict_types=1);

namespace Jurager\Eav\Tests\Feature\Attributes;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Jurager\Eav\Tests\Feature\FeatureTestCase;
use Jurager\Eav\Tests\Fixtures\CategorizedProduct;
use Jurager\Eav\Tests\Fixtures\Category;

class AttributeScopeAutoloadTest extends FeatureTestCase
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

    public function test_scope_is_loaded_for_the_whole_collection_when_it_has_relationship_autoloading(): void
    {
        $category = Category::create(['name' => 'Tools']);

        foreach (range(1, 3) as $i) {
            CategorizedProduct::create(['name' => "Widget $i"])->categories()->attach($category->id);
        }

        $products = CategorizedProduct::query()->get()->withRelationshipAutoloading();

        DB::enableQueryLog();

        $ids = $products->map(fn (CategorizedProduct $product) => $product->attributeScopeIds());

        $scopeQueries = collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'from "categories"'))->count();
        DB::disableQueryLog();

        $this->assertSame(1, $scopeQueries);
        $this->assertSame([[$category->id], [$category->id], [$category->id]], $ids->all());
    }

    public function test_scope_still_resolves_for_a_standalone_model(): void
    {
        $category = Category::create(['name' => 'Tools']);
        $product = CategorizedProduct::create(['name' => 'Widget']);
        $product->categories()->attach($category->id);

        $this->assertSame([$category->id], CategorizedProduct::query()->find($product->id)->attributeScopeIds());
    }
}
