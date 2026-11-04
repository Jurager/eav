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

    protected function tearDown(): void
    {
        Schema::dropIfExists('field_references');

        parent::tearDown();
    }

    public function test_custom_field_dependencies_load_once_for_an_autoloading_batch(): void
    {
        $attribute = $this->createAttribute($this->createAttributeType('reference_text'), ['code' => 'reference']);
        foreach (range(1, 15) as $id) {
            $product = $this->createProduct();
            DB::table('field_references')->insert(['product_id' => $product->id, 'label' => 'Reference '.$id]);
            EntityAttribute::create(['entity_id' => $product->id, 'entity_type' => 'product', 'attribute_id' => $attribute->id, 'value_text' => 'stored']);
        }
        $products = ReferenceProduct::query()->get()->withRelationshipAutoloading();
        $products->load($products->first()->eagerLoads(['attribute_values']));

        DB::enableQueryLog();
        foreach ($products as $index => $product) {
            $value = $product->eav()->values()->first();
            $this->assertSame($product, $value->entity);
            $this->assertSame('Reference '.($index + 1), $value->resolveValue());
        }
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertCount(1, $queries);
        $this->assertStringContainsString('from "field_references"', $queries[0]['query']);
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

    public function test_an_autoloading_batch_loads_dependencies_of_values_owned_by_an_unselected_parent(): void
    {
        $attribute = $this->createAttribute($this->createAttributeType('reference_text'), ['code' => 'reference', 'inherit_from_parent' => true]);
        $parent = $this->createProduct();
        $child = $this->createProduct();
        $child->update(['parent_id' => $parent->id]);
        DB::table('field_references')->insert(['product_id' => $parent->id, 'label' => 'Parent reference']);
        EntityAttribute::create(['entity_id' => $parent->id, 'entity_type' => 'product', 'attribute_id' => $attribute->id, 'value_text' => 'stored']);
        $products = ReferenceProduct::query()->whereKey($child->id)->get()->withRelationshipAutoloading();
        $products->load($products->first()->eagerLoads(['attribute_values']));

        DB::enableQueryLog();
        $value = $products->first()->eav()->values()->first();
        $this->assertSame('Parent reference', $value->resolveValue());
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertCount(1, $queries);
        $this->assertStringContainsString('from "field_references"', $queries[0]['query']);
    }

    public function test_values_build_one_field_per_definition(): void
    {
        app(FieldFactory::class)->register('counted_text', CountedText::class);
        $type = $this->createAttributeType('counted_text');
        $tags = $this->createAttribute($type, ['code' => 'tags', 'multiple' => true]);
        $color = $this->createAttribute($type, ['code' => 'color']);
        $product = $this->createProduct();
        foreach (['a', 'b', 'c'] as $tag) {
            EntityAttribute::create(['entity_id' => $product->id, 'entity_type' => 'product', 'attribute_id' => $tags->id, 'value_text' => $tag]);
        }
        EntityAttribute::create(['entity_id' => $product->id, 'entity_type' => 'product', 'attribute_id' => $color->id, 'value_text' => 'red']);
        $product = Product::query()->with('attribute_values.attribute.type', 'attribute_values.translations')->findOrFail($product->id);

        CountedText::$instances = 0;
        DB::enableQueryLog();
        $values = $product->eav()->values();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertSame(['a', 'b', 'c', 'red'], $values->pluck('value')->all());
        $this->assertSame(2, CountedText::$instances);
        $this->assertSame([], $queries);
    }

    public function test_entity_eager_loads_neither_read_the_schema_nor_build_fields(): void
    {
        app(FieldFactory::class)->register('counted_text', CountedText::class);
        $type = $this->createAttributeType('counted_text');
        foreach (['first', 'second', 'third'] as $code) {
            $this->createAttribute($type, ['code' => $code]);
        }
        $product = $this->createProduct();

        CountedText::$instances = 0;
        DB::enableQueryLog();
        $relations = $product->eagerLoads(['attribute_values']);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertSame($relations, $this->createProduct()->eagerLoads(['attribute_values']));
        $this->assertSame([], $queries);
        $this->assertSame(0, CountedText::$instances);
    }
}

class CountedText extends Text
{
    public static int $instances = 0;

    public function __construct(...$args)
    {
        self::$instances++;
        parent::__construct(...$args);
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
