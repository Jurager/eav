<?php

declare(strict_types=1);

namespace Jurager\Eav\Tests\Unit\Registry;

use Jurager\Eav\Models\Attribute;
use Jurager\Eav\Models\AttributeType;
use Jurager\Eav\Registry\AttributeRegistry;
use Jurager\Eav\Tests\TestCase;

class AttributeRegistryTest extends TestCase
{
    private AttributeRegistry $registry;

    private Attribute $name;

    private Attribute $price;

    protected function setUp(): void
    {
        parent::setUp();

        $type = AttributeType::create(['code' => 'text']);

        $this->name = Attribute::create([
            'entity_type' => 'product',
            'attribute_type_id' => $type->id,
            'code' => 'name',
            'sort' => 0,
            'required' => false,
            'localizable' => false,
            'multiple' => false,
            'unique' => false,
            'filterable' => false,
            'searchable' => false,
        ]);

        $this->price = Attribute::create([
            'entity_type' => 'product',
            'attribute_type_id' => $type->id,
            'code' => 'price',
            'sort' => 1,
            'required' => false,
            'localizable' => false,
            'multiple' => false,
            'unique' => false,
            'filterable' => false,
            'searchable' => false,
        ]);

        $this->registry = app(AttributeRegistry::class);
        $this->registry->forget();
    }

    public function test_all_returns_collection_keyed_by_id(): void
    {
        $all = $this->registry->all();

        $this->assertTrue($all->has($this->name->id));
        $this->assertTrue($all->has($this->price->id));
    }

    public function test_all_is_cached_after_first_call(): void
    {
        $first = $this->registry->all();
        $second = $this->registry->all();

        $this->assertSame($first, $second);
    }

    public function test_creating_an_attribute_automatically_invalidates_the_cache(): void
    {
        $first = $this->registry->all();

        Attribute::create([
            'entity_type' => 'product',
            'attribute_type_id' => $this->name->attribute_type_id,
            'code' => 'weight',
            'sort' => 2,
            'required' => false,
            'localizable' => false,
            'multiple' => false,
            'unique' => false,
            'filterable' => false,
            'searchable' => false,
        ]);

        $second = $this->registry->all();

        $this->assertNotSame($first, $second);
        $this->assertCount(3, $second);
    }

    public function test_has_returns_true_for_existing_id(): void
    {
        $this->assertTrue($this->registry->has($this->name->id));
    }

    public function test_has_returns_false_for_missing_id(): void
    {
        $this->assertFalse($this->registry->has(9999));
    }

    public function test_get_returns_attribute_model(): void
    {
        $attribute = $this->registry->get($this->price->id);

        $this->assertInstanceOf(Attribute::class, $attribute);
        $this->assertSame('price', $attribute->code);
    }

    public function test_get_returns_null_for_missing_id(): void
    {
        $this->assertNull($this->registry->get(9999));
    }

    public function test_forget_clears_cache(): void
    {
        $this->registry->all();

        $this->registry->forget();

        $this->assertCount(2, $this->registry->all());
    }
}
