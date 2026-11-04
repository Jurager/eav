<?php

declare(strict_types=1);

namespace Jurager\Eav\Tests\Feature\Schema;

use Illuminate\Support\Facades\Event;
use Jurager\Eav\Events\AttributeCreated;
use Jurager\Eav\Events\AttributeDeleted;
use Jurager\Eav\Events\AttributeEnumCreated;
use Jurager\Eav\Events\AttributeEnumUpdated;
use Jurager\Eav\Events\AttributeGroupCreated;
use Jurager\Eav\Events\AttributeGroupDeleted;
use Jurager\Eav\Events\AttributeGroupUpdated;
use Jurager\Eav\Events\AttributeUpdated;
use Jurager\Eav\Events\EntityValuesChanged;
use Jurager\Eav\Facades\Schema;
use Jurager\Eav\Jobs\PruneAttribute;
use Jurager\Eav\Models\Attribute;
use Jurager\Eav\Models\AttributeType;
use Jurager\Eav\Models\EntityAttribute;
use Jurager\Eav\Registry\AttributeRegistry;
use Jurager\Eav\Tests\Feature\FeatureTestCase;

class SchemaLifecycleTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->createLocale('en');
        AttributeType::create(['code' => 'text', 'localizable' => true]);
        AttributeType::create(['code' => 'select']);
    }

    public function test_every_schema_operation_dispatches_its_domain_event_once(): void
    {
        Event::fake([
            AttributeCreated::class, AttributeUpdated::class, AttributeDeleted::class,
            AttributeGroupCreated::class, AttributeGroupUpdated::class, AttributeGroupDeleted::class,
            AttributeEnumCreated::class, AttributeEnumUpdated::class,
        ]);

        $group = Schema::group('dimensions')->create();
        Schema::group($group)->sort(3)->update();
        $attribute = Schema::attribute('color', 'product')->type('select')->group('dimensions')->create();
        Schema::attribute($attribute)->required()->update();
        $enum = Schema::enum($attribute, 'red')->create();
        Schema::enum($enum)->sort(2)->update();
        Schema::attribute($attribute)->delete();
        Schema::group($group)->delete();

        foreach ([
            AttributeCreated::class, AttributeUpdated::class, AttributeDeleted::class,
            AttributeGroupCreated::class, AttributeGroupUpdated::class, AttributeGroupDeleted::class,
            AttributeEnumCreated::class, AttributeEnumUpdated::class,
        ] as $event) {
            Event::assertDispatchedTimes($event, 1);
        }
    }

    public function test_attribute_created_listeners_see_the_entity_types(): void
    {
        $seen = null;

        Event::listen(AttributeCreated::class, function (AttributeCreated $event) use (&$seen): void {
            $seen = $event->attribute->applicableEntityTypes();
        });

        Schema::attribute('color', ['product', 'service'])->type('text')->create();

        $this->assertEqualsCanonicalizing(['product', 'service'], $seen);
    }

    public function test_a_select_is_never_localizable(): void
    {
        $attribute = Schema::attribute('color', 'product')->type('select')->localizable()->create();

        $this->assertFalse($attribute->localizable);
    }

    public function test_soft_deleting_keeps_the_values_so_a_restore_brings_them_back(): void
    {
        $attribute = Schema::attribute('color', 'product')->type('text')->create();
        $product = $this->createProduct();
        $product->eav()->set('color', 'red')->save('color');

        Schema::attribute($attribute)->delete();

        $this->assertNull($this->createProductReader($product->id)->eav()->value('color'));
        $this->assertDatabaseHas('entity_attribute', ['attribute_id' => $attribute->id, 'value_text' => 'red']);

        Attribute::withTrashed()->find($attribute->id)->restore();

        $this->assertSame('red', $this->createProductReader($product->id)->eav()->value('color'));
    }

    public function test_force_deleting_prunes_values_and_their_translations(): void
    {
        $attribute = Schema::attribute('title', 'product')->type('text')->localizable()->create();
        $product = $this->createProduct();
        $product->eav()->set('title', [['locale_id' => 1, 'values' => 'Desk']])->save('title');

        $valueId = EntityAttribute::query()->where('attribute_id', $attribute->id)->value('id');
        $this->assertDatabaseHas('entity_translations', ['entity_type' => 'entity_attribute', 'entity_id' => $valueId]);

        Event::fake([EntityValuesChanged::class]);

        Attribute::withTrashed()->find($attribute->id)->forceDelete();

        $this->assertDatabaseMissing('entity_attribute', ['attribute_id' => $attribute->id]);
        $this->assertDatabaseMissing('entity_translations', ['entity_type' => 'entity_attribute', 'entity_id' => $valueId]);
        Event::assertDispatched(EntityValuesChanged::class, fn (EntityValuesChanged $e) => $e->entityType === 'product' && $e->entityIds === [$product->id]);
    }

    public function test_prune_job_permanently_removes_a_soft_deleted_attribute(): void
    {
        $attribute = Schema::attribute('color', 'product')->type('text')->create();
        $product = $this->createProduct();
        $product->eav()->set('color', 'red')->save('color');

        Schema::attribute($attribute)->delete();
        (new PruneAttribute($attribute->id))->handle();

        $this->assertDatabaseMissing('attributes', ['id' => $attribute->id]);
        $this->assertDatabaseMissing('entity_attribute', ['attribute_id' => $attribute->id]);
    }

    public function test_attaching_attributes_to_a_group_refreshes_cached_definitions(): void
    {
        $group = Schema::group('dimensions')->create();
        $attribute = Schema::attribute('color', 'product')->type('text')->create();

        $this->assertNull(app(AttributeRegistry::class)->all('product')->get($attribute->id)->attribute_group_id);

        Schema::group($group)->attach([$attribute->id]);

        $this->assertSame($group->id, app(AttributeRegistry::class)->all('product')->get($attribute->id)->attribute_group_id);
    }

    /** A fresh instance, so nothing is read from the manager cached on the writer. */
    private function createProductReader(int $id): \Jurager\Eav\Tests\Fixtures\Product
    {
        return \Jurager\Eav\Tests\Fixtures\Product::query()->findOrFail($id);
    }
}
