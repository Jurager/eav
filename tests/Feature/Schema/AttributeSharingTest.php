<?php

declare(strict_types=1);

namespace Jurager\Eav\Tests\Feature\Schema;

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema as DbSchema;
use Jurager\Eav\Eav;
use Jurager\Eav\Facades\Schema;
use Jurager\Eav\Jobs\SyncIndexSettings;
use Jurager\Eav\Jobs\SyncSearchable;
use Jurager\Eav\Models\AttributeType;
use Jurager\Eav\Models\EntityAttribute;
use Jurager\Eav\Registry\AttributeRegistry;
use Jurager\Eav\Tests\Feature\FeatureTestCase;
use Jurager\Eav\Tests\Fixtures\Product;
use Jurager\Eav\Tests\Fixtures\Service;

class AttributeSharingTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Relation::morphMap(['service' => Service::class]);

        DbSchema::create('services', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        DbSchema::dropIfExists('services');

        parent::tearDown();
    }

    private function createService(string $name = 'Consulting'): Service
    {
        return Service::create(['name' => $name]);
    }

    public function test_first_or_create_attaches_a_second_entity_type_instead_of_duplicating(): void
    {
        $this->createAttributeType('text');
        $original = Schema::attribute('warranty_months', 'product')->type('text')->create();

        $shared = Schema::attribute('warranty_months', 'service')->type('text')->firstOrCreate();

        $this->assertTrue($shared->is($original));
        $this->assertSame(['product', 'service'], $shared->applicableEntityTypes());
        $this->assertDatabaseCount('attributes', 1);
    }

    public function test_shared_attribute_is_visible_through_the_registry_for_both_types(): void
    {
        $this->createAttributeType('text');
        Schema::attribute('warranty_months', 'product')->type('text')->create();
        Schema::attribute('warranty_months', 'service')->type('text')->firstOrCreate();

        $this->assertTrue(app(AttributeRegistry::class)->all('product')->contains('code', 'warranty_months'));
        $this->assertTrue(app(AttributeRegistry::class)->all('service')->contains('code', 'warranty_months'));
    }

    public function test_a_service_can_store_and_read_a_value_for_a_shared_attribute(): void
    {
        $this->createAttributeType('text');
        Schema::attribute('warranty_months', 'product')->type('text')->create();
        Schema::attribute('warranty_months', 'service')->type('text')->firstOrCreate();

        $service = $this->createService();
        $service->eav()->set('warranty_months', '12')->save('warranty_months');

        $this->assertSame('12', $service->eav()->value('warranty_months'));
        $this->assertDatabaseHas('entity_attribute', [
            'entity_type' => 'service',
            'entity_id' => $service->id,
            'value_text' => '12',
        ]);
    }

    public function test_where_attribute_scopes_to_the_morph_type_of_the_calling_model(): void
    {
        // Regression guard: the entity type a value is stored under comes from the calling model,
        // not from the attribute's first applicable type — for a shared attribute those differ.
        $this->createAttributeType('text');
        $attribute = Schema::attribute('warranty_months', 'product')->type('text')->create();
        Schema::attribute('warranty_months', 'service')->type('text')->firstOrCreate();

        $this->assertSame(['product', 'service'], $attribute->fresh()->applicableEntityTypes());

        $service = $this->createService();
        $service->eav()->set('warranty_months', '12')->save('warranty_months');

        $this->assertSame([$service->id], Service::whereAttribute('warranty_months', '12')->pluck('id')->all());
        $this->assertCount(0, Product::whereAttribute('warranty_months', '12')->get());
    }

    public function test_toggling_searchable_dispatches_sync_for_every_applicable_type(): void
    {
        // A type must itself support the flag, or AttributeType::constrain() forces it back to false.
        AttributeType::create(['code' => 'text', 'searchable' => true]);
        $attribute = Schema::attribute('warranty_months', 'product')->type('text')->create();
        Schema::attribute('warranty_months', 'service')->type('text')->firstOrCreate();

        Queue::fake();

        Schema::attribute($attribute)->searchable()->update();

        Queue::assertPushed(SyncSearchable::class, fn (SyncSearchable $job) => $job->uniqueId() === "product:{$attribute->id}");
        Queue::assertPushed(SyncSearchable::class, fn (SyncSearchable $job) => $job->uniqueId() === "service:{$attribute->id}");
    }

    public function test_toggling_filterable_dispatches_index_settings_sync_for_every_applicable_type(): void
    {
        // A type must itself support the flag, or AttributeType::constrain() forces it back to false.
        AttributeType::create(['code' => 'text', 'filterable' => true]);
        $attribute = Schema::attribute('warranty_months', 'product')->type('text')->create();
        Schema::attribute('warranty_months', 'service')->type('text')->firstOrCreate();

        Queue::fake();

        Schema::attribute($attribute)->filterable()->update();

        Queue::assertPushed(SyncIndexSettings::class, fn (SyncIndexSettings $job) => $job->uniqueId() === 'product');
        Queue::assertPushed(SyncIndexSettings::class, fn (SyncIndexSettings $job) => $job->uniqueId() === 'service');
    }

    public function test_attaching_a_new_type_via_create_dispatches_sync_for_it_immediately(): void
    {
        // Regression guard: entity types are attached after the Eloquent `created` event fires,
        // so a searchable attribute created with multiple types must still sync all of them.
        AttributeType::create(['code' => 'text', 'searchable' => true]);

        Queue::fake();

        $attribute = Schema::attribute('warranty_months', ['product', 'service'])->type('text')->searchable()->create();

        Queue::assertPushed(SyncSearchable::class, fn (SyncSearchable $job) => $job->uniqueId() === "product:{$attribute->id}");
        Queue::assertPushed(SyncSearchable::class, fn (SyncSearchable $job) => $job->uniqueId() === "service:{$attribute->id}");
    }

    public function test_detaching_a_type_invalidates_the_registry_cache_for_it(): void
    {
        $this->createAttributeType('text');
        $attribute = Schema::attribute('warranty_months', 'product')->type('text')->create();
        Schema::attribute('warranty_months', 'service')->type('text')->firstOrCreate();

        // Warm both caches before detaching.
        app(AttributeRegistry::class)->all('product');
        app(AttributeRegistry::class)->all('service');

        Schema::attribute($attribute)->set('entity_types', ['product'])->update();

        $this->assertFalse(app(AttributeRegistry::class)->all('service')->contains('code', 'warranty_months'));
        $this->assertTrue(app(AttributeRegistry::class)->all('product')->contains('code', 'warranty_months'));
    }

    public function test_detaching_a_type_removes_its_stray_stored_values(): void
    {
        $this->createAttributeType('text');
        $attribute = Schema::attribute('warranty_months', 'product')->type('text')->create();
        Schema::attribute('warranty_months', 'service')->type('text')->firstOrCreate();

        $service = $this->createService();
        EntityAttribute::create([
            'entity_id' => $service->id,
            'entity_type' => 'service',
            'attribute_id' => $attribute->id,
            'value_text' => '12',
        ]);

        Schema::attribute($attribute)->set('entity_types', ['product'])->update();

        $this->assertDatabaseMissing('entity_attribute', [
            'attribute_id' => $attribute->id,
            'entity_type' => 'service',
        ]);
    }

    public function test_applicable_entity_types_of_a_loaded_list_do_not_query_per_attribute(): void
    {
        $this->createAttributeType('text');

        foreach (range(1, 5) as $i) {
            Schema::attribute("code_{$i}", ['product', 'service'])->type('text')->create();
        }

        DB::enableQueryLog();
        DB::flushQueryLog();

        $types = Eav::$attributeModel::query()->get()->map(fn ($attribute) => $attribute->applicableEntityTypes());

        $this->assertCount(2, DB::getQueryLog());
        $this->assertSame(array_fill(0, 5, ['product', 'service']), $types->all());
    }

    public function test_code_is_globally_unique_across_entity_types(): void
    {
        $this->createAttributeType('text');
        Schema::attribute('warranty_months', 'product')->type('text')->create();

        $this->expectException(QueryException::class);

        Schema::attribute('warranty_months', 'category')->type('text')->create();
    }
}
