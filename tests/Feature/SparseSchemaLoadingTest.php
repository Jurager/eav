<?php

declare(strict_types=1);

namespace Jurager\Eav\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Jurager\Eav\Filterable\AttributeFilterResolver;
use Jurager\Eav\Filterable\AttributeSortResolver;
use Jurager\Eav\Models\Attribute;
use Jurager\Eav\Registry\AttributeRegistry;
use Jurager\Eav\Tests\Fixtures\Product;

class SparseSchemaLoadingTest extends FeatureTestCase
{
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
            fn (int $id): array => ['id' => $id, 'code' => 'field_'.$id, 'attribute_type_id' => $type->id],
            range(1, 2000),
        ));
        DB::table('attribute_entity_types')->insert(array_map(
            fn (int $id): array => ['attribute_id' => $id, 'entity_type' => 'product'],
            range(1, 2000),
        ));
        app(AttributeRegistry::class)->forget();
    }
}
