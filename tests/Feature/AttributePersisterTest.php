<?php

declare(strict_types=1);

namespace Jurager\Eav\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Jurager\Eav\Fields\TextField;
use Jurager\Eav\Models\Attribute;
use Jurager\Eav\Models\AttributeType;
use Jurager\Eav\Registry\LocaleRegistry;
use Jurager\Eav\Support\AttributePersister;

class AttributePersisterTest extends FeatureTestCase
{
    private AttributeType $textType;

    private Attribute $titleAttr;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createLocale('en');
        $this->textType  = $this->createAttributeType('text');
        $this->titleAttr = $this->createAttribute($this->textType, ['code' => 'title']);
    }

    private function makeTextField(Attribute $attribute, string $value): TextField
    {
        $registry = app(LocaleRegistry::class);
        $field = new TextField($attribute, $registry);
        $field->fill($value);

        return $field;
    }

    // -----------------------------------------------------------------------
    // persist() — single entity
    // -----------------------------------------------------------------------

    public function test_persist_inserts_a_new_row(): void
    {
        $product  = $this->createProduct();
        $persister = new AttributePersister($product);

        $field = $this->makeTextField($this->titleAttr, 'Hello');
        $persister->persist(collect([$field]));

        $row = DB::table('entity_attribute')
            ->where('entity_type', 'product')
            ->where('entity_id', $product->id)
            ->where('attribute_id', $this->titleAttr->id)
            ->first();

        $this->assertNotNull($row);
        $this->assertSame('Hello', $row->value_text);
    }

    public function test_persist_updates_existing_row(): void
    {
        $product   = $this->createProduct();
        $persister = new AttributePersister($product);

        $persister->persist(collect([$this->makeTextField($this->titleAttr, 'First')]));
        $persister->persist(collect([$this->makeTextField($this->titleAttr, 'Second')]));

        $count = DB::table('entity_attribute')
            ->where('entity_type', 'product')
            ->where('entity_id', $product->id)
            ->count();

        $this->assertSame(1, $count);

        $row = DB::table('entity_attribute')
            ->where('entity_type', 'product')
            ->where('entity_id', $product->id)
            ->first();

        $this->assertSame('Second', $row->value_text);
    }

    public function test_persist_does_nothing_for_empty_collection(): void
    {
        $product   = $this->createProduct();
        $persister = new AttributePersister($product);

        $persister->persist(collect());

        $this->assertSame(0, DB::table('entity_attribute')->count());
    }

    // -----------------------------------------------------------------------
    // save() — single field shorthand
    // -----------------------------------------------------------------------

    public function test_save_persists_single_field(): void
    {
        $product   = $this->createProduct();
        $persister = new AttributePersister($product);
        $field     = $this->makeTextField($this->titleAttr, 'Saved');

        $persister->save($field);

        $row = DB::table('entity_attribute')
            ->where('entity_type', 'product')
            ->where('entity_id', $product->id)
            ->first();

        $this->assertNotNull($row);
        $this->assertSame('Saved', $row->value_text);
    }

    // -----------------------------------------------------------------------
    // replace() — atomic swap
    // -----------------------------------------------------------------------

    public function test_replace_removes_rows_not_in_the_new_set(): void
    {
        $priceAttr = $this->createAttribute($this->textType, ['code' => 'price']);
        $product   = $this->createProduct();
        $persister = new AttributePersister($product);

        // Persist both
        $persister->persist(collect([
            $this->makeTextField($this->titleAttr, 'Widget'),
            $this->makeTextField($priceAttr, '9.99'),
        ]));

        // Replace with only title
        $persister->replace(collect([$this->makeTextField($this->titleAttr, 'New Widget')]));

        $rows = DB::table('entity_attribute')
            ->where('entity_type', 'product')
            ->where('entity_id', $product->id)
            ->get();

        $this->assertCount(1, $rows);
        $this->assertSame((string) $this->titleAttr->id, (string) $rows->first()->attribute_id);
        $this->assertSame('New Widget', $rows->first()->value_text);
    }

    // -----------------------------------------------------------------------
    // detach() — remove by attribute IDs
    // -----------------------------------------------------------------------

    public function test_detach_removes_rows_for_given_attribute_ids(): void
    {
        $product   = $this->createProduct();
        $persister = new AttributePersister($product);

        $persister->persist(collect([$this->makeTextField($this->titleAttr, 'To Delete')]));
        $persister->detach([$this->titleAttr->id]);

        $this->assertSame(0, DB::table('entity_attribute')
            ->where('entity_type', 'product')
            ->where('entity_id', $product->id)
            ->count());
    }

    public function test_detach_does_nothing_when_no_rows_exist(): void
    {
        $product   = $this->createProduct();
        $persister = new AttributePersister($product);

        $persister->detach([$this->titleAttr->id]);

        $this->assertSame(0, DB::table('entity_attribute')->count());
    }

    // -----------------------------------------------------------------------
    // delete() — by entity_attribute IDs
    // -----------------------------------------------------------------------

    public function test_delete_removes_given_row_ids(): void
    {
        $product   = $this->createProduct();
        $persister = new AttributePersister($product);

        $persister->persist(collect([$this->makeTextField($this->titleAttr, 'Delete Me')]));

        $id = DB::table('entity_attribute')->first()->id;

        $persister->delete([$id]);

        $this->assertSame(0, DB::table('entity_attribute')->count());
    }

    public function test_delete_with_empty_array_is_no_op(): void
    {
        $product   = $this->createProduct();
        $persister = new AttributePersister($product);

        $persister->persist(collect([$this->makeTextField($this->titleAttr, 'Keep Me')]));

        $persister->delete([]);

        $this->assertSame(1, DB::table('entity_attribute')->count());
    }

    // -----------------------------------------------------------------------
    // add() + flush() — batch mode
    // -----------------------------------------------------------------------

    public function test_batch_add_and_flush_persists_multiple_entities(): void
    {
        $p1 = $this->createProduct('P1');
        $p2 = $this->createProduct('P2');

        $persister = new AttributePersister();

        $persister->add($p1, collect([$this->makeTextField($this->titleAttr, 'First')]));
        $persister->add($p2, collect([$this->makeTextField($this->titleAttr, 'Second')]));

        $persister->flush();

        $rows = DB::table('entity_attribute')
            ->where('entity_type', 'product')
            ->orderBy('entity_id')
            ->get();

        $this->assertCount(2, $rows);
        $this->assertSame('First', $rows[0]->value_text);
        $this->assertSame('Second', $rows[1]->value_text);
    }

    public function test_flush_clears_pending_queue(): void
    {
        $p1 = $this->createProduct();
        $persister = new AttributePersister();

        $persister->add($p1, collect([$this->makeTextField($this->titleAttr, 'Once')]));
        $persister->flush();

        // Second flush should be a no-op — queue is cleared
        $persister->flush();

        $this->assertSame(1, DB::table('entity_attribute')->count());
    }

    public function test_add_with_empty_fields_is_skipped(): void
    {
        $p1 = $this->createProduct();
        $persister = new AttributePersister();

        $persister->add($p1, collect());
        $persister->flush();

        $this->assertSame(0, DB::table('entity_attribute')->count());
    }

    // -----------------------------------------------------------------------
    // Multiple values (multiple=true)
    // -----------------------------------------------------------------------

    public function test_persist_multiple_values_inserts_multiple_rows(): void
    {
        $multiAttr = $this->createAttribute($this->textType, [
            'code'     => 'tags',
            'multiple' => true,
        ]);

        $product   = $this->createProduct();
        $persister = new AttributePersister($product);

        $registry = app(LocaleRegistry::class);
        $field = new TextField($multiAttr, $registry);
        $field->fill(['tag1', 'tag2', 'tag3']);

        $persister->persist(collect([$field]));

        $count = DB::table('entity_attribute')
            ->where('entity_type', 'product')
            ->where('entity_id', $product->id)
            ->where('attribute_id', $multiAttr->id)
            ->count();

        $this->assertSame(3, $count);
    }
}
