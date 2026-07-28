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

    private Attribute $categoryCode;

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

        $this->categoryCode = Attribute::create([
            'entity_type' => 'category',
            'attribute_type_id' => $type->id,
            'code' => 'code',
            'sort' => 0,
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

    public function test_for_entity_type_returns_collection_keyed_by_id(): void
    {
        $products = $this->registry->forEntityType('product');

        $this->assertTrue($products->has($this->name->id));
        $this->assertTrue($products->has($this->price->id));
    }

    public function test_for_entity_type_excludes_attributes_of_other_entity_types(): void
    {
        $products = $this->registry->forEntityType('product');

        $this->assertFalse($products->has($this->categoryCode->id));
    }

    public function test_for_entity_type_caches_are_kept_separate_per_entity_type(): void
    {
        $products = $this->registry->forEntityType('product');
        $categories = $this->registry->forEntityType('category');

        $this->assertCount(2, $products);
        $this->assertCount(1, $categories);
    }

    public function test_for_entity_type_is_cached_after_first_call(): void
    {
        $first = $this->registry->forEntityType('product');
        $second = $this->registry->forEntityType('product');

        $this->assertSame($first, $second);
    }

    public function test_warming_one_entity_type_does_not_warm_another(): void
    {
        $this->registry->forEntityType('product');

        Attribute::create([
            'entity_type' => 'category',
            'attribute_type_id' => $this->categoryCode->attribute_type_id,
            'code' => 'seo_title',
            'sort' => 1,
            'required' => false,
            'localizable' => false,
            'multiple' => false,
            'unique' => false,
            'filterable' => false,
            'searchable' => false,
        ]);

        // Not yet cached for 'category', so the freshly created row is visible.
        $this->assertCount(2, $this->registry->forEntityType('category'));
    }

    public function test_creating_an_attribute_automatically_invalidates_the_cache(): void
    {
        $first = $this->registry->forEntityType('product');

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

        $second = $this->registry->forEntityType('product');

        $this->assertNotSame($first, $second);
        $this->assertCount(3, $second);
    }

    public function test_has_returns_true_for_existing_id(): void
    {
        $this->assertTrue($this->registry->has($this->name->id, 'product'));
    }

    public function test_has_returns_false_for_missing_id(): void
    {
        $this->assertFalse($this->registry->has(9999, 'product'));
    }

    public function test_has_returns_false_when_id_belongs_to_another_entity_type(): void
    {
        $this->assertFalse($this->registry->has($this->categoryCode->id, 'product'));
    }

    public function test_get_returns_attribute_model(): void
    {
        $attribute = $this->registry->get($this->price->id, 'product');

        $this->assertInstanceOf(Attribute::class, $attribute);
        $this->assertSame('price', $attribute->code);
    }

    public function test_get_returns_null_for_missing_id(): void
    {
        $this->assertNull($this->registry->get(9999, 'product'));
    }

    public function test_forget_clears_cache_for_all_entity_types(): void
    {
        $this->registry->forEntityType('product');
        $this->registry->forEntityType('category');

        $this->registry->forget();

        $this->assertCount(2, $this->registry->forEntityType('product'));
        $this->assertCount(1, $this->registry->forEntityType('category'));
    }
}
