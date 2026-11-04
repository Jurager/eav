<?php

declare(strict_types=1);

namespace Jurager\Eav\Tests\Feature\Schema;

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
use Jurager\Eav\Models\Attribute;
use Jurager\Eav\Models\AttributeEnum;
use Jurager\Eav\Models\AttributeGroup;
use Jurager\Eav\Registry\LocaleRegistry;
use Jurager\Eav\Schema\Repositories\AttributeRepository;
use Jurager\Eav\Schema\Repositories\EnumRepository;
use Jurager\Eav\Schema\Repositories\GroupRepository;
use Jurager\Eav\Schema\SchemaManager;
use Jurager\Eav\Tests\Feature\FeatureTestCase;

class SchemaRepositoriesTest extends FeatureTestCase
{
    private SchemaManager $schema;

    private AttributeRepository $attributes;

    private GroupRepository $groups;

    private EnumRepository $enums;

    protected function setUp(): void
    {
        parent::setUp();

        Event::fake();

        $this->createLocale('en');
        $this->schema = app(SchemaManager::class);
        $this->attributes = app(AttributeRepository::class);
        $this->groups = app(GroupRepository::class);
        $this->enums = app(EnumRepository::class);
    }

    // -----------------------------------------------------------------------
    // Attribute CRUD
    // -----------------------------------------------------------------------

    public function test_attribute_create_persists_to_db(): void
    {
        $type = $this->createAttributeType('text');

        $attr = $this->attributes->create([
            'entity_types' => ['product'],
            'attribute_type_id' => $type->id,
            'code' => 'title',
        ]);

        $this->assertInstanceOf(Attribute::class, $attr);
        $this->assertDatabaseHas('attributes', ['code' => 'title']);
        $this->assertDatabaseHas('attribute_entity_types', ['attribute_id' => $attr->id, 'entity_type' => 'product']);
    }

    public function test_attribute_create_dispatches_created_event(): void
    {
        $type = $this->createAttributeType('text');

        $this->attributes->create([
            'entity_types' => ['product'],
            'attribute_type_id' => $type->id,
            'code' => 'title',
        ]);

        Event::assertDispatched(AttributeCreated::class);
    }

    public function test_attribute_create_applies_auto_sort(): void
    {
        $type = $this->createAttributeType('text');

        $a1 = $this->attributes->create([
            'entity_types' => ['product'], 'attribute_type_id' => $type->id, 'code' => 'a1',
        ]);
        $a2 = $this->attributes->create([
            'entity_types' => ['product'], 'attribute_type_id' => $type->id, 'code' => 'a2',
        ]);

        $this->assertGreaterThan($a1->sort, $a2->sort);
    }

    public function test_attribute_create_accepts_string_group_id(): void
    {
        // Simulates raw request input (e.g. JSON body) where numeric fields
        // arrive as strings — request validation doesn't cast them.
        $type = $this->createAttributeType('text');
        $group = $this->groups->create(['code' => 'general']);

        $attr = $this->attributes->create([
            'entity_types' => ['product'],
            'attribute_type_id' => (string) $type->id,
            'attribute_group_id' => (string) $group->id,
            'code' => 'color',
        ]);

        $this->assertSame($group->id, $attr->attribute_group_id);
    }

    public function test_attribute_create_applies_type_constraints(): void
    {
        // Create a type that does NOT support localizable
        $type = $this->createAttributeType('text');
        // AttributeType defaults: localizable=false, multiple=false, etc.

        $attr = $this->attributes->create([
            'entity_types' => ['product'],
            'attribute_type_id' => $type->id,
            'code' => 'constrained',
            'localizable' => true,   // should be forced to false by type constraint
        ]);

        $this->assertFalse($attr->localizable);
    }

    public function test_attribute_find_returns_attribute(): void
    {
        $type = $this->createAttributeType('text');
        $created = $this->attributes->create([
            'entity_types' => ['product'], 'attribute_type_id' => $type->id, 'code' => 'findme',
        ]);

        $found = $this->attributes->find($created->id);

        $this->assertSame($created->id, $found->id);
        $this->assertSame('findme', $found->code);
    }

    public function test_attribute_update_changes_field(): void
    {
        $type = $this->createAttributeType('text');
        $attr = $this->attributes->create([
            'entity_types' => ['product'], 'attribute_type_id' => $type->id, 'code' => 'old',
        ]);

        $this->attributes->update($attr, ['required' => true]);

        $this->assertDatabaseHas('attributes', ['id' => $attr->id, 'required' => true]);
    }

    public function test_attribute_update_dispatches_updated_event(): void
    {
        $type = $this->createAttributeType('text');
        $attr = $this->attributes->create([
            'entity_types' => ['product'], 'attribute_type_id' => $type->id, 'code' => 'upd',
        ]);

        $this->attributes->update($attr, ['required' => true]);

        Event::assertDispatched(AttributeUpdated::class);
    }

    public function test_attribute_delete_soft_deletes_attribute(): void
    {
        $type = $this->createAttributeType('text');
        $attr = $this->attributes->create([
            'entity_types' => ['product'], 'attribute_type_id' => $type->id, 'code' => 'deleteme',
        ]);

        $this->attributes->delete($attr);

        $this->assertSoftDeleted('attributes', ['id' => $attr->id]);
    }

    public function test_attribute_delete_dispatches_deleted_event(): void
    {
        $type = $this->createAttributeType('text');
        $attr = $this->attributes->create([
            'entity_types' => ['product'], 'attribute_type_id' => $type->id, 'code' => 'del2',
        ]);

        $this->attributes->delete($attr);

        Event::assertDispatched(AttributeDeleted::class);
    }

    public function test_attribute_find_or_create_creates_when_not_exists(): void
    {
        $type = $this->createAttributeType('text');

        $attr = $this->attributes->findOrCreate(['product'], 'new_attr', [
            'entity_types' => ['product'],
            'attribute_type_id' => $type->id,
            'code' => 'new_attr',
        ]);

        $this->assertInstanceOf(Attribute::class, $attr);
        $this->assertDatabaseHas('attributes', ['code' => 'new_attr']);
    }

    public function test_attribute_find_or_create_returns_existing_without_overwriting(): void
    {
        $type = $this->createAttributeType('text');
        $existing = $this->createAttribute($type, ['code' => 'existing', 'required' => false]);

        $this->attributes->findOrCreate(['product'], 'existing', [
            'entity_types' => ['product'],
            'attribute_type_id' => $type->id,
            'code' => 'existing',
            'required' => true,  // should NOT overwrite
        ]);

        $this->assertDatabaseHas('attributes', ['id' => $existing->id, 'required' => false]);
    }

    public function test_attribute_sort_reorders_siblings(): void
    {
        $type = $this->createAttributeType('text');

        $a1 = $this->attributes->create([
            'entity_types' => ['product'], 'attribute_type_id' => $type->id, 'code' => 's1',
        ]);
        $a2 = $this->attributes->create([
            'entity_types' => ['product'], 'attribute_type_id' => $type->id, 'code' => 's2',
        ]);
        $a3 = $this->attributes->create([
            'entity_types' => ['product'], 'attribute_type_id' => $type->id, 'code' => 's3',
        ]);

        // Move a3 to position 0 (first)
        $this->attributes->sort($a3, 0);

        $ordered = Attribute::query()
            ->whereIn('id', [$a1->id, $a2->id, $a3->id])
            ->orderBy('sort')
            ->pluck('code')
            ->all();

        $this->assertSame('s3', $ordered[0]);
    }

    public function test_attribute_batch_creates_multiple_attributes(): void
    {
        $type = $this->createAttributeType('text');

        $created = $this->attributes->batch([
            ['entity_types' => ['product'], 'attribute_type_id' => $type->id, 'code' => 'batch1'],
            ['entity_types' => ['product'], 'attribute_type_id' => $type->id, 'code' => 'batch2'],
            ['entity_types' => ['product'], 'attribute_type_id' => $type->id, 'code' => 'batch3'],
        ]);

        $this->assertCount(3, $created);
        $this->assertDatabaseHas('attributes', ['code' => 'batch1']);
        $this->assertDatabaseHas('attributes', ['code' => 'batch2']);
        $this->assertDatabaseHas('attributes', ['code' => 'batch3']);
    }

    public function test_attribute_batch_dispatches_created_events(): void
    {
        $type = $this->createAttributeType('text');

        $this->attributes->batch([
            ['entity_types' => ['product'], 'attribute_type_id' => $type->id, 'code' => 'ev1'],
            ['entity_types' => ['product'], 'attribute_type_id' => $type->id, 'code' => 'ev2'],
        ]);

        Event::assertDispatchedTimes(AttributeCreated::class, 2);
    }

    public function test_attribute_batch_with_fire_events_false_skips_events(): void
    {
        $type = $this->createAttributeType('text');

        $this->attributes->batch([
            ['entity_types' => ['product'], 'attribute_type_id' => $type->id, 'code' => 'silent'],
        ], false);

        Event::assertNotDispatched(AttributeCreated::class);
    }

    // -----------------------------------------------------------------------
    // Group CRUD
    // -----------------------------------------------------------------------

    public function test_group_create_persists_to_db(): void
    {
        $group = $this->groups->create(['code' => 'general']);

        $this->assertInstanceOf(AttributeGroup::class, $group);
        $this->assertDatabaseHas('attribute_groups', ['code' => 'general']);
    }

    public function test_group_create_dispatches_created_event(): void
    {
        $this->groups->create(['code' => 'specs']);

        Event::assertDispatched(AttributeGroupCreated::class);
    }

    public function test_group_update_changes_code(): void
    {
        $group = $this->groups->create(['code' => 'old_code']);

        $this->groups->update($group, ['code' => 'new_code']);

        $this->assertDatabaseHas('attribute_groups', ['id' => $group->id, 'code' => 'new_code']);
        Event::assertDispatched(AttributeGroupUpdated::class);
    }

    public function test_group_delete_removes_group(): void
    {
        $group = $this->groups->create(['code' => 'to_delete']);

        $this->groups->delete($group);

        $this->assertDatabaseMissing('attribute_groups', ['id' => $group->id]);
        Event::assertDispatched(AttributeGroupDeleted::class);
    }

    public function test_group_attach_assigns_attributes_to_group(): void
    {
        $type = $this->createAttributeType('text');
        $group = $this->groups->create(['code' => 'details']);
        $a1 = $this->createAttribute($type, ['code' => 'ga1']);
        $a2 = $this->createAttribute($type, ['code' => 'ga2']);

        $this->groups->attach($group, [$a1->id, $a2->id]);

        $this->assertDatabaseHas('attributes', ['id' => $a1->id, 'attribute_group_id' => $group->id]);
        $this->assertDatabaseHas('attributes', ['id' => $a2->id, 'attribute_group_id' => $group->id]);
    }

    public function test_group_sort_reorders_groups(): void
    {
        $g1 = $this->groups->create(['code' => 'g1']);
        $g2 = $this->groups->create(['code' => 'g2']);
        $g3 = $this->groups->create(['code' => 'g3']);

        // Move g3 to position 0 (first)
        $this->groups->sort($g3, 0);

        $ordered = AttributeGroup::query()
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

        $enum = $this->enums->create($attr, ['code' => 'red']);

        $this->assertInstanceOf(AttributeEnum::class, $enum);
        $this->assertDatabaseHas('attribute_enums', ['code' => 'red', 'attribute_id' => $attr->id]);
    }

    public function test_enum_create_dispatches_created_event(): void
    {
        $type = $this->createAttributeType('select');
        $attr = $this->createAttribute($type, ['code' => 'size']);

        $this->enums->create($attr, ['code' => 'small']);

        Event::assertDispatched(AttributeEnumCreated::class);
    }

    public function test_enum_update_changes_code(): void
    {
        $type = $this->createAttributeType('select');
        $attr = $this->createAttribute($type, ['code' => 'status']);
        $enum = $this->enums->create($attr, ['code' => 'old_val']);

        $this->enums->update($enum, ['code' => 'new_val']);

        $this->assertDatabaseHas('attribute_enums', ['id' => $enum->id, 'code' => 'new_val']);
        Event::assertDispatched(AttributeEnumUpdated::class);
    }

    public function test_enum_delete_removes_enum(): void
    {
        $type = $this->createAttributeType('select');
        $attr = $this->createAttribute($type, ['code' => 'type']);
        $enum = $this->enums->create($attr, ['code' => 'opt1']);

        $this->enums->delete($enum);

        $this->assertDatabaseMissing('attribute_enums', ['id' => $enum->id]);
        Event::assertDispatched(AttributeEnumDeleted::class);
    }

    // -----------------------------------------------------------------------
    // Enum batch
    // -----------------------------------------------------------------------

    public function test_enum_batch_creates_multiple_options(): void
    {
        $type = $this->createAttributeType('select');
        $attr = $this->createAttribute($type, ['code' => 'color']);

        $created = $this->enums->batch([
            ['attribute_id' => $attr->id, 'code' => 'red', 'sort' => 0],
            ['attribute_id' => $attr->id, 'code' => 'blue', 'sort' => 1],
        ]);

        $this->assertCount(2, $created);
        $this->assertDatabaseHas('attribute_enums', ['code' => 'red', 'attribute_id' => $attr->id]);
        $this->assertDatabaseHas('attribute_enums', ['code' => 'blue', 'attribute_id' => $attr->id]);
    }

    public function test_enum_batch_persists_translations(): void
    {
        $type = $this->createAttributeType('select');
        $attr = $this->createAttribute($type, ['code' => 'color']);

        // setUp() already seeds the "en" locale.
        $created = $this->enums->batch([
            ['attribute_id' => $attr->id, 'code' => 'red', 'sort' => 0, 'translations' => [
                ['locale_id' => app(LocaleRegistry::class)->find('en'), 'label' => 'Red'],
            ]],
        ]);

        $labels = $created->get("{$attr->id}:red")->translations->pluck('pivot.label', 'code')->all();

        $this->assertSame('Red', $labels['en']);
    }

    public function test_enum_batch_is_idempotent_for_existing_options(): void
    {
        $type = $this->createAttributeType('select');
        $attr = $this->createAttribute($type, ['code' => 'color']);
        $existing = $this->createEnum($attr, 'red');

        $created = $this->enums->batch([
            ['attribute_id' => $attr->id, 'code' => 'red', 'sort' => 5],
        ]);

        $this->assertCount(1, $created);
        $this->assertSame($existing->id, $created->get("{$attr->id}:red")->id);
        $this->assertDatabaseHas('attribute_enums', ['id' => $existing->id, 'sort' => 5]);
    }

    public function test_enum_batch_dispatches_created_and_updated_events(): void
    {
        $type = $this->createAttributeType('select');
        $attr = $this->createAttribute($type, ['code' => 'color']);
        $this->createEnum($attr, 'red');

        $this->enums->batch([
            ['attribute_id' => $attr->id, 'code' => 'red', 'sort' => 1],
            ['attribute_id' => $attr->id, 'code' => 'blue', 'sort' => 2],
        ]);

        Event::assertDispatchedTimes(AttributeEnumUpdated::class, 1);
        Event::assertDispatchedTimes(AttributeEnumCreated::class, 1);
    }

    public function test_enum_batch_with_fire_events_false_skips_events(): void
    {
        $type = $this->createAttributeType('select');
        $attr = $this->createAttribute($type, ['code' => 'color']);

        $this->enums->batch([
            ['attribute_id' => $attr->id, 'code' => 'silent', 'sort' => 0],
        ], false);

        Event::assertNotDispatched(AttributeEnumCreated::class);
    }

    public function test_enum_batch_does_not_persist_anything_when_empty(): void
    {
        $created = $this->enums->batch([]);

        $this->assertCount(0, $created);
    }

    // -----------------------------------------------------------------------
    // SchemaManager queries
    // -----------------------------------------------------------------------

    public function test_attributes_query_returns_all_attributes(): void
    {
        $type = $this->createAttributeType('text');
        $this->createAttribute($type, ['code' => 'f1']);
        $this->createAttribute($type, ['code' => 'f2']);

        $result = $this->schema->attributes()->get();

        $this->assertCount(2, $result);
    }

    public function test_attributes_query_can_be_chained(): void
    {
        $type = $this->createAttributeType('text');
        $this->createAttribute($type, ['code' => 'q1']);
        $this->createAttribute($type, ['code' => 'q2']);

        $count = $this->schema->attributes()->count();

        $this->assertSame(2, $count);
    }

    public function test_groups_query_returns_all_groups(): void
    {
        $this->groups->create(['code' => 'grp1']);
        $this->groups->create(['code' => 'grp2']);

        $result = $this->schema->groups()->get();

        $this->assertCount(2, $result);
    }

    public function test_types_query_returns_all_types(): void
    {
        $this->createAttributeType('text');
        $this->createAttributeType('number');

        $result = $this->schema->types()->get();

        $this->assertGreaterThanOrEqual(2, $result->count());
    }
}
