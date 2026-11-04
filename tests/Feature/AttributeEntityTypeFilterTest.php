<?php

declare(strict_types=1);

namespace Jurager\Eav\Tests\Feature;

use Jurager\Eav\Tests\Fixtures\FilterableAttribute;
use Jurager\Filterable\Exceptions\OperatorNotAllowedException;

class AttributeEntityTypeFilterTest extends FeatureTestCase
{
    /** The resolver is registered globally through the `filterable.resolvers` tag — no $resolvers on the model. */
    public function test_entity_type_filter_resolves_membership_without_per_model_resolvers(): void
    {
        $type = $this->createAttributeType('text');
        $this->createAttribute($type, ['code' => 'warranty_months', 'entity_types' => ['product', 'service']]);
        $this->createAttribute($type, ['code' => 'color', 'entity_types' => ['product']]);
        $this->createAttribute($type, ['code' => 'delivery_slot', 'entity_types' => ['service']]);

        $codes = fn (array $filter): array => FilterableAttribute::query()
            ->filter($filter)
            ->orderBy('code')
            ->pluck('code')
            ->all();

        $this->assertSame(['color', 'warranty_months'], $codes(['entity_type' => ['eq' => 'product']]));
        $this->assertSame(['delivery_slot', 'warranty_months'], $codes(['entity_type' => ['eq' => 'service']]));
        $this->assertSame([], $codes(['entity_type' => ['eq' => 'category']]));

        $this->assertSame(['color', 'delivery_slot', 'warranty_months'], $codes(['entity_type' => ['in' => 'product,service']]));
        $this->assertSame(['color', 'delivery_slot', 'warranty_months'], $codes(['entity_type' => ['product', 'service']]));

        // ne/nin mean "not applicable to": a shared attribute drops out as soon as the type is among its own.
        $this->assertSame(['color'], $codes(['entity_type' => ['ne' => 'service']]));
        $this->assertSame(['delivery_slot'], $codes(['entity_type' => ['ne' => 'product']]));
        $this->assertSame([], $codes(['entity_type' => ['nin' => ['product', 'service']]]));
        $this->assertSame(['delivery_slot'], $codes(['entity_type' => ['nin' => ['product']]]));
    }

    public function test_unsupported_operator_is_rejected(): void
    {
        $this->createAttributeType('text');

        $this->expectException(OperatorNotAllowedException::class);

        FilterableAttribute::query()->filter(['entity_type' => ['like' => 'prod']])->get();
    }

    /** The exact dataset from the reported regression. */
    public function test_negated_operators_exclude_attributes_that_carry_the_type(): void
    {
        $type = $this->createAttributeType('text');
        $shared = ['product', 'category', 'warehouse', 'service'];

        $this->createAttribute($type, ['code' => 'code', 'entity_types' => $shared]);
        $this->createAttribute($type, ['code' => 'name', 'entity_types' => $shared]);
        $this->createAttribute($type, ['code' => 'external_id', 'entity_types' => ['product', 'warehouse', 'category']]);
        $this->createAttribute($type, ['code' => 'is_pvz', 'entity_types' => ['warehouse']]);

        $codes = fn (array $filter): array => FilterableAttribute::query()
            ->filter($filter)
            ->orderBy('code')
            ->pluck('code')
            ->all();

        $this->assertSame(['is_pvz'], $codes(['entity_type' => ['ne' => 'product']]));
        $this->assertSame(['is_pvz'], $codes(['entity_type' => ['nin' => ['product', 'category']]]));
        $this->assertSame(['external_id', 'is_pvz'], $codes(['entity_type' => ['ne' => 'service']]));
    }
}
