<?php

declare(strict_types=1);

namespace Jurager\Eav\Tests\Feature;

use Jurager\Eav\Exceptions\UnknownAttributeException;
use Jurager\Eav\Models\AttributeType;
use Jurager\Eav\Models\EntityAttribute;
use Jurager\Eav\Tests\Fixtures\Product;

/** Attribute values behave like the entity's own columns: set, then saved with it. */
class SavingWithTheEntityTest extends FeatureTestCase
{
    private AttributeType $text;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createLocale('en');
        $this->text = $this->createAttributeType('text');
        $this->createAttribute($this->text, ['code' => 'color']);
        $this->createAttribute($this->text, ['code' => 'size']);
    }

    public function test_saving_the_entity_saves_the_values_set_on_it(): void
    {
        $product = $this->createProduct();
        $product->color = 'red';
        $product->size = 'L';

        $this->assertTrue($product->eav()->isDirty('color'));

        $product->save();

        $this->assertFalse($product->eav()->isDirty());
        $this->assertSame('red', $product->fresh()->color);
        $this->assertSame('L', $product->fresh()->size);
    }

    public function test_a_new_entity_gets_its_values_once_it_has_a_key(): void
    {
        $product = new Product(['name' => 'Desk']);
        $product->color = 'oak';
        $product->save();

        $this->assertSame('oak', Product::query()->find($product->getKey())->color);
    }

    public function test_save_without_a_code_saves_every_dirty_value(): void
    {
        $product = $this->createProduct();

        $product->eav()->set('color', 'red')->set('size', 'M')->save();

        $this->assertSame(2, EntityAttribute::query()->count());
    }

    public function test_a_value_set_to_null_is_removed(): void
    {
        $product = $this->createProduct();
        $product->eav()->set('color', 'red')->save();

        $product->color = null;
        $product->save();

        $this->assertNull($product->fresh()->color);
        $this->assertSame(0, EntityAttribute::query()->count());
    }

    public function test_an_unknown_attribute_cannot_be_written(): void
    {
        $this->expectException(UnknownAttributeException::class);

        $this->createProduct()->eav()->set('colour', 'red');
    }

    public function test_refresh_and_clone_read_values_of_their_own(): void
    {
        $product = $this->createProduct();
        $product->eav()->set('color', 'red')->save();

        $copy = clone $product;
        $copy->eav()->set('color', 'blue');
        $this->assertSame('red', $product->eav()->value('color'), 'The copy writes through a manager of its own.');

        EntityAttribute::query()->update(['value_text' => 'green']);
        $this->assertSame('green', $product->refresh()->eav()->value('color'));
    }
}
