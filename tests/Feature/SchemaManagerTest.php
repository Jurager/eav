<?php

declare(strict_types=1);

namespace Jurager\Eav\Tests\Feature;

use Illuminate\Support\Facades\Event;
use Jurager\Eav\Events\AttributeCreated;
use Jurager\Eav\Events\AttributeDeleted;
use Jurager\Eav\Events\AttributeEnumCreated;
use Jurager\Eav\Events\AttributeEnumDeleted;
use Jurager\Eav\Events\AttributeEnumUpdated;
use Jurager\Eav\Events\AttributeGroupCreated;
use Jurager\Eav\Events\AttributeGroupDeleted;
use Jurager\Eav\Events\AttributeGroupUpdated;
use Jurager\Eav\Events\AttributeUpdated;
use Jurager\Eav\Managers\SchemaManager;
use Jurager\Eav\Models\Attribute;
use Jurager\Eav\Models\AttributeEnum;
use Jurager\Eav\Models\AttributeGroup;

class SchemaManagerTest extends FeatureTestCase
{
    private SchemaManager $schema;

    protected function setUp(): void
    {
        parent::setUp();

        Event::fake();

        $this->createLocale('en');
        $this->schema = app(SchemaManager::class);
    }

    // -----------------------------------------------------------------------
    // Attribute CRUD
    // -----------------------------------------------------------------------

    public function test_attribute_create_persists_to_db(): void
    {
        $type = $this->createAttributeType('text');

        $attr = $this->schema->attribute()->create([
            'entity_type'       => 'product',
            'attribute_type_id' => $type->id,
            'code'              => 'title',
        ]);

        $this->assertInstanceOf(Attribute::class, $attr);
        $this->assertDatabaseHas('attributes', ['code' => 'title', 'entity_type' => 'product']);
    }

    public function test_attribute_create_dispatches_created_event(): void
    {
        $type = $this->createAttributeType('text');

        $this->schema->attribute()->create([
            'entity_type'       => 'product',
            'attribute_type_id' => $type->id,
            'code'              => 'title',
        ]);

        Event::assertDispatched(AttributeCreated::class);
    }

    public function test_attribute_create_applies_auto_sort(): void
    {
        $type = $this->createAttributeType('text');

        $a1 = $this->schema->attribute()->create([
            'entity_type' => 'product', 'attribute_type_id' => $type->id, 'code' => 'a1',
        ]);
        $a2 = $this->schema->attribute()->create([
            'entity_type' => 'product', 'attribute_type_id' => $type->id, 'code' => 'a2',
        ]);

        $this->assertGreaterThan($a1->sort, $a2->sort);
    }

    public function test_attribute_create_applies_type_constraints(): void
    {
        // Create a type that does NOT support localizable
        $type = $this->createAttributeType('text');
        // AttributeType defaults: localizable=false, multiple=false, etc.

        $attr = $this->schema->attribute()->create([
            'entity_type'       => 'product',
            'attribute_type_id' => $type->id,
            'code'              => 'constrained',
            'localizable'       => true,   // should be forced to false by type constraint
        ]);

        $this->assertFalse($attr->localizable);
    }

    public function test_attribute_find_returns_attribute(): void
    {
        $type = $this->createAttributeType('text');
        $created = $this->schema->attribute()->create([
            'entity_type' => 'product', 'attribute_type_id' => $type->id, 'code' => 'findme',
        ]);

        $found = $this->schema->attribute()->find($created->id);

        $this->assertSame($created->id, $found->id);
        $this->assertSame('findme', $found->code);
    }

    public function test_attribute_update_changes_field(): void
    {
        $type = $this->createAttributeType('text');
        $attr = $this->schema->attribute()->create([
            'entity_type' => 'product', 'attribute_type_id' => $type->id, 'code' => 'old',
        ]);

        $this->schema->attribute()->update($attr, ['mandatory' => true]);

        $this->assertDatabaseHas('attributes', ['id' => $attr->id, 'mandatory' => true]);
    }

    public function test_attribute_update_dispatches_updated_event(): void
    {
        $type = $this->createAttributeType('text');
        $attr = $this->schema->attribute()->create([
            'entity_type' => 'product', 'attribute_type_id' => $type->id, 'code' => 'upd',
        ]);

        Event::fake(); // Reset — only capture the update event
        $this->schema->attribute()->update($attr, ['mandatory' => true]);

        Event::assertDispatched(AttributeUpdated::class);
    }

    public function test_attribute_delete_soft_deletes_attribute(): void
    {
        $type = $this->createAttributeType('text');
        $attr = $this->schema->attribute()->create([
            'entity_type' => 'product', 'attribute_type_id' => $type->id, 'code' => 'deleteme',
        ]);

        $this->schema->attribute()->delete($attr);

        $this->assertSoftDeleted('attributes', ['id' => $attr->id]);
    }

    public function test_attribute_delete_dispatches_deleted_event(): void
    {
        $type = $this->createAttributeType('text');
        $attr = $this->schema->attribute()->create([
            'entity_type' => 'product', 'attribute_type_id' => $type->id, 'code' => 'del2',
        ]);

        Event::fake();
        $this->schema->attribute()->delete($attr);

        Event::assertDispatched(AttributeDeleted::class);
    }

    public function test_attribute_find_or_create_creates_when_not_exists(): void
    {
        $type = $this->createAttributeType('text');

        $attr = $this->schema->attribute()->findOrCreate('product', 'new_attr', [
            'entity_type'       => 'product',
            'attribute_type_id' => $type->id,
            'code'              => 'new_attr',
        ]);

        $this->assertInstanceOf(Attribute::class, $attr);
        $this->assertDatabaseHas('attributes', ['code' => 'new_attr']);
    }

    public function test_attribute_find_or_create_returns_existing_without_overwriting(): void
    {
        $type = $this->createAttributeType('text');
        $existing = $this->createAttribute($type, ['code' => 'existing', 'mandatory' => false]);

        $this->schema->attribute()->findOrCreate('product', 'existing', [
            'entity_type'       => 'product',
            'attribute_type_id' => $type->id,
            'code'              => 'existing',
            'mandatory'         => true,  // should NOT overwrite
        ]);

        $this->assertDatabaseHas('attributes', ['id' => $existing->id, 'mandatory' => false]);
    }

    public function test_attribute_sort_reorders_siblings(): void
    {
        $type = $this->createAttributeType('text');

        $a1 = $this->schema->attribute()->create([
            'entity_type' => 'product', 'attribute_type_id' => $type->id, 'code' => 's1',
        ]);
        $a2 = $this->schema->attribute()->create([
            'entity_type' => 'product', 'attribute_type_id' => $type->id, 'code' => 's2',
        ]);
        $a3 = $this->schema->attribute()->create([
            'entity_type' => 'product', 'attribute_type_id' => $type->id, 'code' => 's3',
        ]);

        // Move a3 to position 0 (first)
        $this->schema->attribute()->sort($a3, 0);

        $ordered = Attribute::withoutGlobalScope('ordered')
            ->whereIn('id', [$a1->id, $a2->id, $a3->id])
            ->orderBy('sort')
            ->pluck('code')
            ->all();

        $this->assertSame('s3', $ordered[0]);
    }

    public function test_attribute_batch_creates_multiple_attributes(): void
    {
        $type = $this->createAttributeType('text');

        $created = $this->schema->attribute()->batch([
            ['entity_type' => 'product', 'attribute_type_id' => $type->id, 'code' => 'batch1'],
            ['entity_type' => 'product', 'attribute_type_id' => $type->id, 'code' => 'batch2'],
            ['entity_type' => 'product', 'attribute_type_id' => $type->id, 'code' => 'batch3'],
        ]);

        $this->assertCount(3, $created);
        $this->assertDatabaseHas('attributes', ['code' => 'batch1']);
        $this->assertDatabaseHas('attributes', ['code' => 'batch2']);
        $this->assertDatabaseHas('attributes', ['code' => 'batch3']);
    }

    public function test_attribute_batch_dispatches_created_events(): void
    {
        $type = $this->createAttributeType('text');

        Event::fake();

        $this->schema->attribute()->batch([
            ['entity_type' => 'product', 'attribute_type_id' => $type->id, 'code' => 'ev1'],
            ['entity_type' => 'product', 'attribute_type_id' => $type->id, 'code' => 'ev2'],
        ]);

        Event::assertDispatchedTimes(AttributeCreated::class, 2);
    }

    public function test_attribute_batch_with_fire_events_false_skips_events(): void
    {
        $type = $this->createAttributeType('text');

        Event::fake();

        $this->schema->attribute()->batch([
            ['entity_type' => 'product', 'attribute_type_id' => $type->id, 'code' => 'silent'],
        ], false);

        Event::assertNotDispatched(AttributeCreated::class);
    }

    // -----------------------------------------------------------------------
    // Group CRUD
    // -----------------------------------------------------------------------

    public function test_group_create_persists_to_db(): void
    {
        $group = $this->schema->group()->create(['code' => 'general']);

        $this->assertInstanceOf(AttributeGroup::class, $group);
        $this->assertDatabaseHas('attribute_groups', ['code' => 'general']);
    }

    public function test_group_create_dispatches_created_event(): void
    {
        $this->schema->group()->create(['code' => 'specs']);

        Event::assertDispatched(AttributeGroupCreated::class);
    }

    public function test_group_update_changes_code(): void
    {
        $group = $this->schema->group()->create(['code' => 'old_code']);

        Event::fake();
        $this->schema->group()->update($group, ['code' => 'new_code']);

        $this->assertDatabaseHas('attribute_groups', ['id' => $group->id, 'code' => 'new_code']);
        Event::assertDispatched(AttributeGroupUpdated::class);
    }

    public function test_group_delete_removes_group(): void
    {
        $group = $this->schema->group()->create(['code' => 'to_delete']);

        Event::fake();
        $this->schema->group()->delete($group);

        $this->assertDatabaseMissing('attribute_groups', ['id' => $group->id]);
        Event::assertDispatched(AttributeGroupDeleted::class);
    }

    public function test_group_attach_assigns_attributes_to_group(): void
    {
        $type  = $this->createAttributeType('text');
        $group = $this->schema->group()->create(['code' => 'details']);
        $a1    = $this->createAttribute($type, ['code' => 'ga1']);
        $a2    = $this->createAttribute($type, ['code' => 'ga2']);

        $this->schema->group()->attach($group, [$a1->id, $a2->id]);

        $this->assertDatabaseHas('attributes', ['id' => $a1->id, 'attribute_group_id' => $group->id]);
        $this->assertDatabaseHas('attributes', ['id' => $a2->id, 'attribute_group_id' => $group->id]);
    }

    public function test_group_sort_reorders_groups(): void
    {
        $g1 = $this->schema->group()->create(['code' => 'g1']);
        $g2 = $this->schema->group()->create(['code' => 'g2']);
        $g3 = $this->schema->group()->create(['code' => 'g3']);

        // Move g3 to position 0 (first)
        $this->schema->group()->sort($g3, 0);

        $ordered = AttributeGroup::withoutGlobalScope('ordered')
            ->whereIn('id', [$g1->id, $g2->id, $g3->id])
            ->orderBy('sort')
            ->pluck('code')
            ->all();

        $this->assertSame('g3', $ordered[0]);
    }

    // -----------------------------------------------------------------------
    // Enum CRUD
    // -----------------------------------------------------------------------

    public function test_enum_create_persists_to_db(): void
    {
        $type = $this->createAttributeType('select');
        $attr = $this->createAttribute($type, ['code' => 'color']);

        $enum = $this->schema->enum()->create($attr, ['code' => 'red']);

        $this->assertInstanceOf(AttributeEnum::class, $enum);
        $this->assertDatabaseHas('attribute_enums', ['code' => 'red', 'attribute_id' => $attr->id]);
    }

    public function test_enum_create_dispatches_created_event(): void
    {
        $type = $this->createAttributeType('select');
        $attr = $this->createAttribute($type, ['code' => 'size']);

        $this->schema->enum()->create($attr, ['code' => 'small']);

        Event::assertDispatched(AttributeEnumCreated::class);
    }

    public function test_enum_update_changes_code(): void
    {
        $type = $this->createAttributeType('select');
        $attr = $this->createAttribute($type, ['code' => 'status']);
        $enum = $this->schema->enum()->create($attr, ['code' => 'old_val']);

        Event::fake();
        $this->schema->enum()->update($enum, ['code' => 'new_val']);

        $this->assertDatabaseHas('attribute_enums', ['id' => $enum->id, 'code' => 'new_val']);
        Event::assertDispatched(AttributeEnumUpdated::class);
    }

    public function test_enum_delete_removes_enum(): void
    {
        $type = $this->createAttributeType('select');
        $attr = $this->createAttribute($type, ['code' => 'type']);
        $enum = $this->schema->enum()->create($attr, ['code' => 'opt1']);

        Event::fake();
        $this->schema->enum()->delete($enum);

        $this->assertDatabaseMissing('attribute_enums', ['id' => $enum->id]);
        Event::assertDispatched(AttributeEnumDeleted::class);
    }

    // -----------------------------------------------------------------------
    // SchemaManager query helpers
    // -----------------------------------------------------------------------

    public function test_attributes_returns_all_attributes(): void
    {
        $type = $this->createAttributeType('text');
        $this->createAttribute($type, ['code' => 'f1']);
        $this->createAttribute($type, ['code' => 'f2']);

        $result = $this->schema->attributes();

        $this->assertCount(2, $result);
    }

    public function test_attributes_accepts_modifier(): void
    {
        $type = $this->createAttributeType('text');
        $this->createAttribute($type, ['code' => 'q1']);
        $this->createAttribute($type, ['code' => 'q2']);

        $count = $this->schema->attributes(fn ($q) => $q->count());

        $this->assertSame(2, $count);
    }

    public function test_groups_returns_all_groups(): void
    {
        $this->schema->group()->create(['code' => 'grp1']);
        $this->schema->group()->create(['code' => 'grp2']);

        Event::fake();
        $result = $this->schema->groups();

        $this->assertCount(2, $result);
    }

    public function test_types_returns_all_types(): void
    {
        $this->createAttributeType('text');
        $this->createAttributeType('number');

        $result = $this->schema->types();

        $this->assertGreaterThanOrEqual(2, $result->count());
    }
}
