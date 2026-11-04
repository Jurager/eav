<?php

declare(strict_types=1);

namespace Jurager\Eav\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Jurager\Eav\Fields\FieldFactory;
use Jurager\Eav\Fields\Text;
use Jurager\Eav\Filterable\AttributeFilterResolver;
use Jurager\Eav\Filterable\AttributeSortResolver;
use Jurager\Eav\Models\Attribute;
use Jurager\Eav\Models\EntityAttribute;
use Jurager\Eav\Registry\AttributeRegistry;
use Jurager\Eav\Tests\Fixtures\Product;

class SparseSchemaLoadingTest extends FeatureTestCase
{
    public function test_reading_multiple_fields_preserves_schema_order_without_hydrating_other_definitions(): void
    {
        $this->seedSchema();
        $product = $this->createProduct();
        $retrieved = [];
        Attribute::retrieved(function (Attribute $definition) use (&$retrieved): void {
            $retrieved[] = $definition->code;
        });

        $product->eav()->ensureFields(['field_2', 'field_1']);

        $this->assertSame(['field_1', 'field_2'], array_keys($product->eav()->fields()));
        $this->assertSame(['field_1', 'field_2'], $retrieved);
    }

    public function test_discovering_a_field_type_does_not_hydrate_unrelated_schema_definitions(): void
    {
        $this->seedSchema();
        app(FieldFactory::class)->register('special_text', SpecialSchemaText::class);
        $attribute = $this->createAttribute($this->createAttributeType('special_text'), ['code' => 'special']);
        app(AttributeRegistry::class)->forget();
        $retrieved = [];
        Attribute::retrieved(function (Attribute $definition) use (&$retrieved): void {
            $retrieved[] = $definition->code;
        });

        $this->assertSame([$attribute->id], app(FieldFactory::class)->using(SpecialSchemaText::class, 'product')->pluck('id')->all());
        $this->assertSame([$attribute->id], app(FieldFactory::class)->using(SpecialSchemaText::class, 'product')->pluck('id')->all());
        $this->assertSame(['special'], $retrieved);
    }

    public function test_loading_a_batch_only_hydrates_definitions_of_its_stored_values(): void
    {
        $this->seedSchema();
        $first = $this->createProduct();
        $second = $this->createProduct();
        $unrelated = $this->createProduct();
        foreach ([[$first, 1], [$second, 2], [$unrelated, 3]] as [$product, $attributeId]) {
            EntityAttribute::create(['entity_id' => $product->id, 'entity_type' => 'product', 'attribute_id' => $attributeId, 'value_text' => 'stored']);
        }
        $retrieved = [];
        Attribute::retrieved(function (Attribute $attribute) use (&$retrieved): void {
            $retrieved[] = $attribute->code;
        });

        $products = Product::query()->whereKey([$first->id, $second->id])->get();
        $relations = $products->first()->eagerLoads(['attribute_values']);

        $this->assertSame([], $retrieved);
        $this->assertArrayNotHasKey('attribute_values', $relations);

        $products->load($relations);

        $this->assertSame(['field_1', 'field_2'], $retrieved);
        DB::enableQueryLog();
        $this->assertSame(['stored'], $products->first()->eav()->values()->pluck('value')->all());
        $this->assertSame(['stored'], $products->last()->eav()->values()->pluck('value')->all());
        $this->assertSame([], DB::getQueryLog());
        DB::disableQueryLog();
    }

    public function test_sparse_resource_preparation_only_hydrates_requested_attribute_definitions(): void
    {
        $this->seedSchema();
        $product = $this->createProduct();
        $retrieved = [];
        Attribute::retrieved(function (Attribute $attribute) use (&$retrieved): void {
            $retrieved[] = $attribute->code;
        });

        for ($i = 0; $i < 15; $i++) {
            $product->eagerLoads(['attribute_values'], ['id', 'field_1', 'field_2']);
            $this->assertSame(['attribute_values' => ['field_1', 'field_2']], Product::fieldRelations(['id', 'field_1', 'field_2']));
        }

        $this->assertSame(['field_1', 'field_2'], $retrieved);
    }

    public function test_filtering_one_attribute_only_hydrates_its_definition(): void
    {
        $this->seedSchema();
        $retrieved = [];
        Attribute::retrieved(function (Attribute $attribute) use (&$retrieved): void {
            $retrieved[] = $attribute->code;
        });

        for ($i = 0; $i < 15; $i++) {
            Product::query()->whereAttribute('field_1', 'value')->toSql();
        }

        $this->assertSame(['field_1'], $retrieved);
    }

    public function test_filter_and_sort_resolvers_only_load_the_definitions_they_use(): void
    {
        $this->seedSchema();
        $retrieved = [];
        Attribute::retrieved(function (Attribute $attribute) use (&$retrieved): void {
            $retrieved[] = $attribute->code;
        });
        $product = new Product;
        $query = $product->newQuery();
        $filter = app(AttributeFilterResolver::class);
        $sort = app(AttributeSortResolver::class);

        $this->assertTrue($filter->resolve($query, 'field_1', 'red', $product));
        $this->assertTrue($sort->resolve($query, 'field_2', 'asc', $product));
        $this->assertFalse($filter->resolve($query, 'missing', 'value', $product));
        $this->assertFalse($sort->resolve($query, 'missing', 'asc', $product));
        $this->assertSame(['field_1', 'field_2'], $retrieved);
        $this->assertStringContainsString('value_text', $query->toSql());
        $this->assertSame(['product', 1, 'red', 'product', 2], $query->getBindings());
    }

    private function seedSchema(): void
    {
        $type = $this->createAttributeType('text');
        DB::table('attributes')->insert(array_map(
            fn (int $i): array => ['code' => 'field_'.$i, 'attribute_type_id' => $type->id],
            range(1, 2000),
        ));
        DB::table('attribute_entity_types')->insert(array_map(
            fn (int $id): array => ['attribute_id' => $id, 'entity_type' => 'product'],
            DB::table('attributes')->orderBy('id')->pluck('id')->all(),
        ));
        app(AttributeRegistry::class)->forget();
    }
}

class SpecialSchemaText extends Text {}
