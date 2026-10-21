<?php

declare(strict_types=1);

namespace Jurager\Eav\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Jurager\Eav\Filterable\AttributeFilterResolver;
use Jurager\Eav\Filterable\AttributeSortResolver;
use Jurager\Eav\Registry\LocaleRegistry;
use Jurager\Eav\Tests\Fixtures\Product;

class FilterResolverScopeTest extends FeatureTestCase
{
    public function test_sorting_uses_the_current_requests_locale(): void
    {
        $english = $this->createLocale('en');
        $french = $this->createLocale('fr');
        $attribute = $this->createAttribute($this->createAttributeType('text'), ['code' => 'label', 'localizable' => true]);
        app(LocaleRegistry::class)->set(['en']);
        $first = app(AttributeSortResolver::class);
        $query = Product::query();
        $this->assertTrue($first->resolve($query, 'label', 'asc', $query->getModel()));
        $this->assertSame(['entity_attribute', $english->id, 'product', $attribute->id], $query->getBindings());

        app()->forgetScopedInstances();
        app(LocaleRegistry::class)->set(['fr']);
        $second = app(AttributeSortResolver::class);
        $query = Product::query();

        $this->assertNotSame($first, $second);
        $this->assertSame($second, app(AttributeSortResolver::class));
        $this->assertTrue($second->resolve($query, 'label', 'asc', $query->getModel()));
        $this->assertSame(['entity_attribute', $french->id, 'product', $attribute->id], $query->getBindings());
    }

    public function test_filtering_detects_external_schema_changes_in_the_next_request(): void
    {
        $type = $this->createAttributeType('text');
        $first = app(AttributeFilterResolver::class);
        $query = Product::query();
        $this->assertFalse($first->resolve($query, 'color', 'red', $query->getModel()));

        $id = DB::table('attributes')->insertGetId(['attribute_type_id' => $type->id, 'code' => 'color']);
        DB::table('attribute_entity_types')->insert(['attribute_id' => $id, 'entity_type' => 'product']);
        app()->forgetScopedInstances();
        $second = app(AttributeFilterResolver::class);
        $query = Product::query();

        $this->assertNotSame($first, $second);
        $this->assertSame($second, app(AttributeFilterResolver::class));
        $this->assertTrue($second->resolve($query, 'color', 'red', $query->getModel()));
        $this->assertSame(['product', $id, 'red'], $query->getBindings());
    }
}
