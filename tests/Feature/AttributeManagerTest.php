<?php

declare(strict_types=1);

namespace Jurager\Eav\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Jurager\Eav\Exceptions\InvalidConfigurationException;
use Jurager\Eav\Fields\NumberField;
use Jurager\Eav\Fields\TextField;
use Jurager\Eav\Managers\AttributeManager;
use Jurager\Eav\Models\Attribute;
use Jurager\Eav\Models\AttributeType;

class AttributeManagerTest extends FeatureTestCase
{
    private AttributeType $textType;

    private AttributeType $numberType;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createLocale('en');
        $this->textType   = $this->createAttributeType('text');
        $this->numberType = $this->createAttributeType('number');
    }

    // -----------------------------------------------------------------------
    // AttributeManager::for() — factory modes
    // -----------------------------------------------------------------------

    public function test_for_entity_instance_creates_manager(): void
    {
        $product = $this->createProduct();
        $manager = AttributeManager::for($product);

        $this->assertInstanceOf(AttributeManager::class, $manager);
    }

    public function test_for_non_attributable_class_throws(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        AttributeManager::for(Attribute::class);
    }

    // -----------------------------------------------------------------------
    // field() — lazy loading by code
    // -----------------------------------------------------------------------

    public function test_field_returns_correct_field_type(): void
    {
        $this->createAttribute($this->textType, ['code' => 'title']);

        $product = $this->createProduct();
        $manager = AttributeManager::for($product);

        $field = $manager->field('title');

        $this->assertInstanceOf(TextField::class, $field);
        $this->assertSame('title', $field->code());
    }

    public function test_field_returns_null_for_nonexistent_code(): void
    {
        $product = $this->createProduct();
        $manager = AttributeManager::for($product);

        $this->assertNull($manager->field('nonexistent'));
    }

    public function test_field_returns_number_field_for_number_type(): void
    {
        $this->createAttribute($this->numberType, ['code' => 'price']);

        $product = $this->createProduct();
        $manager = AttributeManager::for($product);

        $this->assertInstanceOf(NumberField::class, $manager->field('price'));
    }

    // -----------------------------------------------------------------------
    // value() / set() — in-memory operations
    // -----------------------------------------------------------------------

    public function test_value_returns_null_when_not_set(): void
    {
        $this->createAttribute($this->textType, ['code' => 'title']);

        $product = $this->createProduct();
        $manager = AttributeManager::for($product);

        $this->assertNull($manager->value('title'));
    }

    public function test_set_and_value_roundtrip(): void
    {
        $this->createAttribute($this->textType, ['code' => 'title']);

        $product = $this->createProduct();
        $manager = AttributeManager::for($product);

        $manager->set('title', 'My Product');

        $this->assertSame('My Product', $manager->value('title'));
    }

    // -----------------------------------------------------------------------
    // fill() — batch fill from array
    // -----------------------------------------------------------------------

    public function test_fill_returns_collection_of_valid_fields(): void
    {
        $this->createAttribute($this->textType, ['code' => 'title']);
        $this->createAttribute($this->numberType, ['code' => 'price']);

        $product = $this->createProduct();
        $manager = AttributeManager::for($product);

        $filled = $manager->fill(['title' => 'Widget', 'price' => 9.99]);

        $this->assertCount(2, $filled);
    }

    public function test_fill_skips_unknown_attribute_codes(): void
    {
        $this->createAttribute($this->textType, ['code' => 'title']);

        $product = $this->createProduct();
        $manager = AttributeManager::for($product);

        $filled = $manager->fill(['title' => 'Widget', 'unknown' => 'value']);

        $this->assertCount(1, $filled);
    }

    public function test_fill_skips_fields_that_fail_validation(): void
    {
        $this->createAttribute($this->textType, ['code' => 'title']);

        $product = $this->createProduct();
        $manager = AttributeManager::for($product);

        // 42 is not a valid string for TextField
        $filled = $manager->fill(['title' => 42]);

        $this->assertCount(0, $filled);
    }

    // -----------------------------------------------------------------------
    // save() — single field persistence
    // -----------------------------------------------------------------------

    public function test_save_writes_value_to_db(): void
    {
        $attr = $this->createAttribute($this->textType, ['code' => 'title']);

        $product = $this->createProduct();
        $manager = AttributeManager::for($product);

        $manager->set('title', 'Hello World');
        $manager->save('title');

        $row = DB::table('entity_attribute')
            ->where('entity_type', 'product')
            ->where('entity_id', $product->id)
            ->where('attribute_id', $attr->id)
            ->first();

        $this->assertNotNull($row);
        $this->assertSame('Hello World', $row->value_text);
    }

    public function test_save_does_nothing_when_field_not_filled(): void
    {
        $this->createAttribute($this->textType, ['code' => 'title']);

        $product = $this->createProduct();
        $manager = AttributeManager::for($product);

        // title not filled — save() should be a no-op
        $manager->save('title');

        $count = DB::table('entity_attribute')
            ->where('entity_type', 'product')
            ->where('entity_id', $product->id)
            ->count();

        $this->assertSame(0, $count);
    }

    // -----------------------------------------------------------------------
    // attach() — add fields without replacing existing
    // -----------------------------------------------------------------------

    public function test_attach_persists_filled_fields(): void
    {
        $attr = $this->createAttribute($this->textType, ['code' => 'title']);

        $product = $this->createProduct();
        $manager = AttributeManager::for($product);

        $field = $manager->field('title');
        $field->fill('Attached Value');

        $manager->attach(['title' => $field]);

        $row = DB::table('entity_attribute')
            ->where('entity_type', 'product')
            ->where('entity_id', $product->id)
            ->where('attribute_id', $attr->id)
            ->first();

        $this->assertNotNull($row);
        $this->assertSame('Attached Value', $row->value_text);
    }

    // -----------------------------------------------------------------------
    // replace() — atomic replace all
    // -----------------------------------------------------------------------

    public function test_replace_overwrites_all_existing_rows(): void
    {
        $titleAttr = $this->createAttribute($this->textType, ['code' => 'title']);
        $priceAttr = $this->createAttribute($this->numberType, ['code' => 'price']);

        $product = $this->createProduct();
        $manager = AttributeManager::for($product);

        // Initial save of two fields
        $manager->set('title', 'Old Title')->set('price', 100);
        $manager->save('title');
        $manager->save('price');

        // Replace with only title — price row should be gone
        $manager2 = AttributeManager::for($product);
        $titleField = $manager2->field('title');
        $titleField->fill('New Title');

        $manager2->replace(['title' => $titleField]);

        $rows = DB::table('entity_attribute')
            ->where('entity_type', 'product')
            ->where('entity_id', $product->id)
            ->get();

        $this->assertCount(1, $rows);
        $this->assertSame('New Title', $rows->first()->value_text);
    }

    // -----------------------------------------------------------------------
    // detach() — delete by attribute IDs
    // -----------------------------------------------------------------------

    public function test_detach_removes_rows_for_given_attribute_ids(): void
    {
        $attr = $this->createAttribute($this->textType, ['code' => 'title']);

        $product = $this->createProduct();
        $manager = AttributeManager::for($product);

        $manager->set('title', 'To Remove');
        $manager->save('title');

        $manager->detach([$attr->id]);

        $count = DB::table('entity_attribute')
            ->where('entity_type', 'product')
            ->where('entity_id', $product->id)
            ->count();

        $this->assertSame(0, $count);
    }

    // -----------------------------------------------------------------------
    // fields() — after ensureSchema()
    // -----------------------------------------------------------------------

    public function test_fields_returns_all_schema_fields_after_ensure(): void
    {
        $this->createAttribute($this->textType, ['code' => 'title']);
        $this->createAttribute($this->numberType, ['code' => 'price']);

        $product = $this->createProduct();
        $manager = AttributeManager::for($product);
        $manager->ensureSchema();

        $fields = $manager->fields();

        $this->assertArrayHasKey('title', $fields);
        $this->assertArrayHasKey('price', $fields);
    }

    // -----------------------------------------------------------------------
    // hydrate — loads DB values into fields
    // -----------------------------------------------------------------------

    public function test_field_value_is_hydrated_from_db_after_save(): void
    {
        $this->createAttribute($this->textType, ['code' => 'title']);

        $product = $this->createProduct();

        // Save via one manager instance
        $manager1 = AttributeManager::for($product);
        $manager1->set('title', 'Stored Value');
        $manager1->save('title');

        // Read back via a fresh manager instance
        $manager2 = AttributeManager::for($product);
        $this->assertSame('Stored Value', $manager2->value('title'));
    }

    // -----------------------------------------------------------------------
    // sync() — batch persistence
    // -----------------------------------------------------------------------

    public function test_sync_persists_multiple_entities(): void
    {
        $this->createAttribute($this->textType, ['code' => 'title']);

        $p1 = $this->createProduct('Product 1');
        $p2 = $this->createProduct('Product 2');

        $batch = collect([
            ['entity' => $p1, 'data' => ['title' => 'Title One']],
            ['entity' => $p2, 'data' => ['title' => 'Title Two']],
        ]);

        AttributeManager::sync($batch);

        $row1 = DB::table('entity_attribute')
            ->where('entity_id', $p1->id)
            ->where('entity_type', 'product')
            ->first();

        $row2 = DB::table('entity_attribute')
            ->where('entity_id', $p2->id)
            ->where('entity_type', 'product')
            ->first();

        $this->assertSame('Title One', $row1->value_text);
        $this->assertSame('Title Two', $row2->value_text);
    }

    public function test_sync_with_empty_batch_is_no_op(): void
    {
        AttributeManager::sync(collect());

        $this->assertSame(0, DB::table('entity_attribute')->count());
    }
}
