<?php

declare(strict_types=1);

namespace Jurager\Eav\Tests\Feature;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Jurager\Eav\Contracts\Attributable;
use Jurager\Eav\Fields\FieldFactory;
use Jurager\Eav\Fields\Text;
use Jurager\Eav\Models\EntityAttribute;
use Jurager\Eav\Tests\Fixtures\Product;

class FieldEagerLoadingTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('field_references', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->string('label');
        });
        app(FieldFactory::class)->register('reference_text', ReferenceText::class);
    }

    public function test_custom_field_dependencies_are_loaded_for_a_batch_without_media_or_json_api(): void
    {
        $attribute = $this->createAttribute($this->createAttributeType('reference_text'), ['code' => 'reference']);
        foreach (range(1, 15) as $id) {
            $product = $this->createProduct();
            DB::table('field_references')->insert(['product_id' => $product->id, 'label' => 'Reference '.$id]);
            EntityAttribute::create(['entity_id' => $product->id, 'entity_type' => 'product', 'attribute_id' => $attribute->id, 'value_text' => 'stored']);
        }
        $products = ReferenceProduct::query()->get();

        DB::enableQueryLog();
        $products->load($products->first()->eagerLoads(['attribute_values'], ['reference']));
        $queries = DB::getQueryLog();
        DB::flushQueryLog();

        foreach ($products as $index => $product) {
            $value = $product->eav()->values()->first();
            $this->assertSame($product, $value->entity);
            $this->assertSame('Reference '.($index + 1), $value->resolveValue());
        }

        $this->assertSame([], DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertCount(1, array_filter($queries, fn ($query) => str_contains($query['query'], 'from "field_references"')));
    }

    public function test_dependencies_follow_the_actual_owner_of_inherited_values(): void
    {
        $attribute = $this->createAttribute($this->createAttributeType('reference_text'), ['code' => 'reference', 'inherit_from_parent' => true]);
        $parent = $this->createProduct();
        $child = $this->createProduct();
        $child->update(['parent_id' => $parent->id]);
        DB::table('field_references')->insert(['product_id' => $parent->id, 'label' => 'Parent reference']);
        EntityAttribute::create(['entity_id' => $parent->id, 'entity_type' => 'product', 'attribute_id' => $attribute->id, 'value_text' => 'stored']);

        $child = ReferenceProduct::query()->findOrFail($child->id);
        $child->load($child->eagerLoads(['attribute_values'], ['reference']));
        $value = $child->eav()->values()->first();

        $this->assertSame($child->parent, $value->entity);
        $this->assertSame('Parent reference', $value->resolveValue());
        $this->assertTrue($child->parent->relationLoaded('references'));
        $this->assertArrayNotHasKey('entity', $value->toArray());
        $this->assertNotContains('references', $child->eagerLoads(['attribute_values'], ['unrelated']));
        $this->assertNotContains('parent.references', $child->eagerLoads(['attribute_values'], ['unrelated']));
    }
}

class ReferenceProduct extends Product
{
    public function getMorphClass(): string
    {
        return 'product';
    }

    public function references(): HasMany
    {
        return $this->hasMany(FieldReference::class, 'product_id');
    }
}

class FieldReference extends Model
{
    protected $table = 'field_references';
}

class ReferenceText extends Text
{
    public function eagerLoads(): array
    {
        return ['references'];
    }

    public function resolve(mixed $rawValue, ?Attributable $entity = null): mixed
    {
        return $entity?->references->first()?->label;
    }
}
