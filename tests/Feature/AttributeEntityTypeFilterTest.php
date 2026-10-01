<?php

declare(strict_types=1);

namespace Jurager\Eav\Tests\Feature;

use Jurager\Eav\Tests\Fixtures\FilterableAttribute;

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

        // Shared attributes match both types, not just the first applicable one.
        $this->assertSame(['color', 'warranty_months'], $codes(['entity_type' => ['ne' => 'service']]));
    }
}
