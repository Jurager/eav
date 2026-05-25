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

    public function test_all_returns_empty_collection_when_no_enums(): void
    {
        $textType = $this->createAttributeType('text2');
        $attr = $this->createAttribute($textType, ['code' => 'size']);

        $result = $this->registry->all($attr->id);

        $this->assertTrue($result->isEmpty());
    }

    public function test_all_returns_enums_for_attribute(): void
    {
        $selectType = $this->createAttributeType('select');
        $attr = $this->createAttribute($selectType, ['code' => 'status']);

        $enum1 = $this->createEnum($attr, 'active');
        $enum2 = $this->createEnum($attr, 'inactive');

        $result = $this->registry->all($attr->id);

        $this->assertTrue($result->contains('id', $enum1->id));
        $this->assertTrue($result->contains('id', $enum2->id));
    }

    public function test_all_is_cached_after_first_call(): void
    {
        $selectType = $this->createAttributeType('select2');
        $attr = $this->createAttribute($selectType, ['code' => 'type']);

        $this->createEnum($attr, 'typeA');
        $this->createEnum($attr, 'typeB');

        $first = $this->registry->all($attr->id);
        $second = $this->registry->all($attr->id);

        $this->assertSame($first, $second);
    }

    public function test_forget_attribute_clears_its_cache(): void
    {
        $selectType = $this->createAttributeType('select3');
        $attr = $this->createAttribute($selectType, ['code' => 'flag']);

        $this->createEnum($attr, 'yes');
        $this->registry->all($attr->id);

        $this->registry->forget($attr->id);

        $enum2 = $this->createEnum($attr, 'no');

        $result = $this->registry->all($attr->id);

        $this->assertTrue($result->contains('id', $enum2->id));
    }

    public function test_forget_null_clears_all_caches(): void
    {
        $selectType = $this->createAttributeType('select4');
        $attr1 = $this->createAttribute($selectType, ['code' => 'x']);
        $attr2 = $this->createAttribute($selectType, ['code' => 'y']);

        $this->createEnum($attr1, 'a');
        $this->createEnum($attr2, 'b');

        $this->registry->all($attr1->id);
        $this->registry->all($attr2->id);

        $this->registry->forget();

        $e3 = $this->createEnum($attr1, 'c');
        $e4 = $this->createEnum($attr2, 'd');

        $r1 = $this->registry->all($attr1->id);
        $r2 = $this->registry->all($attr2->id);

        $this->assertTrue($r1->contains('id', $e3->id));
        $this->assertTrue($r2->contains('id', $e4->id));
    }
}
