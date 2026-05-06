<?php

declare(strict_types=1);

namespace Jurager\Eav\Tests\Feature;

use Jurager\Eav\Registry\EnumRegistry;

class EnumRegistryTest extends FeatureTestCase
{
    private EnumRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createLocale('en');
        $textType = $this->createAttributeType('text');
        $this->createAttribute($textType, ['code' => 'color']);

        $this->registry = app(EnumRegistry::class);
        $this->registry->forget();
    }

    public function test_resolve_returns_empty_array_when_no_enums(): void
    {
        $textType = $this->createAttributeType('text2');
        $attr = $this->createAttribute($textType, ['code' => 'size']);

        $result = $this->registry->resolve($attr->id);

        $this->assertSame([], $result);
    }

    public function test_resolve_returns_lookup_map_of_valid_ids(): void
    {
        $textType = $this->createAttributeType('select');
        $attr = $this->createAttribute($textType, ['code' => 'status']);

        $enum1 = $this->createEnum($attr, 'active');
        $enum2 = $this->createEnum($attr, 'inactive');

        $result = $this->registry->resolve($attr->id);

        $this->assertArrayHasKey($enum1->id, $result);
        $this->assertArrayHasKey($enum2->id, $result);
        $this->assertTrue($result[$enum1->id]);
        $this->assertTrue($result[$enum2->id]);
    }

    public function test_resolve_is_cached_after_first_call(): void
    {
        $textType = $this->createAttributeType('select2');
        $attr = $this->createAttribute($textType, ['code' => 'type']);

        // Create both enums before the first resolve so the observer cannot clear the cache
        $this->createEnum($attr, 'typeA');
        $this->createEnum($attr, 'typeB');

        $first = $this->registry->resolve($attr->id);

        // Verify the result is served from cache on the second call (same count)
        $second = $this->registry->resolve($attr->id);

        $this->assertSame($first, $second);
    }

    public function test_forget_attribute_clears_its_cache(): void
    {
        $textType = $this->createAttributeType('select3');
        $attr = $this->createAttribute($textType, ['code' => 'flag']);

        $enum1 = $this->createEnum($attr, 'yes');

        $this->registry->resolve($attr->id);

        $this->registry->forget($attr->id);

        $enum2 = $this->createEnum($attr, 'no');

        $result = $this->registry->resolve($attr->id);

        $this->assertArrayHasKey($enum2->id, $result);
    }

    public function test_forget_null_clears_all_caches(): void
    {
        $textType  = $this->createAttributeType('select4');
        $attr1 = $this->createAttribute($textType, ['code' => 'x']);
        $attr2 = $this->createAttribute($textType, ['code' => 'y']);

        $e1 = $this->createEnum($attr1, 'a');
        $e2 = $this->createEnum($attr2, 'b');

        $this->registry->resolve($attr1->id);
        $this->registry->resolve($attr2->id);

        $this->registry->forget();

        $e3 = $this->createEnum($attr1, 'c');
        $e4 = $this->createEnum($attr2, 'd');

        $r1 = $this->registry->resolve($attr1->id);
        $r2 = $this->registry->resolve($attr2->id);

        $this->assertArrayHasKey($e3->id, $r1);
        $this->assertArrayHasKey($e4->id, $r2);
    }
}
