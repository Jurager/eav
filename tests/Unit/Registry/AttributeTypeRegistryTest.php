<?php

declare(strict_types=1);

namespace Jurager\Eav\Tests\Unit\Registry;

use Jurager\Eav\Models\AttributeType;
use Jurager\Eav\Registry\AttributeTypeRegistry;
use Jurager\Eav\Tests\TestCase;

class AttributeTypeRegistryTest extends TestCase
{
    private AttributeTypeRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();

        AttributeType::create(['code' => 'text']);
        AttributeType::create(['code' => 'number']);
        AttributeType::create(['code' => 'boolean']);

        $this->registry = app(AttributeTypeRegistry::class);
        $this->registry->forget();
    }

    public function test_all_returns_collection_keyed_by_code(): void
    {
        $all = $this->registry->all();

        $this->assertTrue($all->has('text'));
        $this->assertTrue($all->has('number'));
        $this->assertTrue($all->has('boolean'));
    }

    public function test_all_is_cached_after_first_call(): void
    {
        $first = $this->registry->all();

        AttributeType::create(['code' => 'date']);

        $second = $this->registry->all();

        $this->assertSame($first, $second);
        $this->assertFalse($second->has('date'));
    }

    public function test_codes_returns_array_of_type_codes(): void
    {
        $codes = $this->registry->codes();

        $this->assertContains('text', $codes);
        $this->assertContains('number', $codes);
    }

    public function test_has_returns_true_for_existing_code(): void
    {
        $this->assertTrue($this->registry->has('text'));
    }

    public function test_has_returns_false_for_missing_code(): void
    {
        $this->assertFalse($this->registry->has('nonexistent'));
    }

    public function test_find_returns_attribute_type_model(): void
    {
        $type = $this->registry->find('text');

        $this->assertInstanceOf(AttributeType::class, $type);
        $this->assertSame('text', $type->code);
    }

    public function test_find_returns_null_for_missing_code(): void
    {
        $this->assertNull($this->registry->find('nonexistent'));
    }

    public function test_forget_clears_cache(): void
    {
        $this->registry->all();

        $this->registry->forget();

        AttributeType::create(['code' => 'date']);

        $this->assertTrue($this->registry->has('date'));
    }
}
