<?php

declare(strict_types=1);

namespace Jurager\Eav\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Jurager\Eav\Events\AttributeTypeCreated;
use Jurager\Eav\Events\AttributeTypeDeleted;
use Jurager\Eav\Events\AttributeTypeUpdated;
use Jurager\Eav\Models\Attribute;
use Jurager\Eav\Models\AttributeGroup;
use Jurager\Eav\Models\AttributeType;
use Jurager\Eav\Registry\AttributeGroupRegistry;
use Jurager\Eav\Registry\AttributeTypeRegistry;

class AttributeReferenceDataRegistryTest extends FeatureTestCase
{
    public function test_type_relation_is_populated_from_registry_without_a_query(): void
    {
        $type = $this->createAttributeType('text');
        $attribute = $this->createAttribute($type);

        // Warm the registry once, outside of the assertion window.
        app(AttributeTypeRegistry::class)->all();

        DB::enableQueryLog();

        $fetched = Attribute::query()->find($attribute->id);

        $this->assertTrue($fetched->relationLoaded('type'));
        $this->assertSame('text', $fetched->type->code);
        $this->assertSame([], array_filter(DB::getQueryLog(), fn ($q) => str_contains($q['query'], 'attribute_types')));
    }

    public function test_group_relation_is_populated_from_registry_without_a_query(): void
    {
        $type = $this->createAttributeType('text');
        $group = AttributeGroup::create(['code' => 'general', 'sort' => 0]);
        $attribute = $this->createAttribute($type, ['attribute_group_id' => $group->id]);

        app(AttributeGroupRegistry::class)->all();

        DB::enableQueryLog();

        $fetched = Attribute::query()->find($attribute->id);

        $this->assertTrue($fetched->relationLoaded('group'));
        $this->assertSame('general', $fetched->group->code);
        $this->assertSame([], array_filter(DB::getQueryLog(), fn ($q) => str_contains($q['query'], 'attribute_groups')));
    }

    public function test_group_relation_is_null_when_attribute_has_no_group(): void
    {
        $type = $this->createAttributeType('text');
        $attribute = $this->createAttribute($type);

        $fetched = Attribute::query()->find($attribute->id);

        $this->assertTrue($fetched->relationLoaded('group'));
        $this->assertNull($fetched->group);
    }

    public function test_repeated_fetches_across_separate_queries_share_the_same_type_query_count(): void
    {
        $type = $this->createAttributeType('text');
        $this->createAttribute($type, ['code' => 'a']);
        $this->createAttribute($type, ['code' => 'b']);

        DB::enableQueryLog();

        // Two independent relation loads, mirroring separate `loadMissing()` passes
        // over distinct parent collections (e.g. category tree levels).
        Attribute::query()->where('code', 'a')->get();
        Attribute::query()->where('code', 'b')->get();

        $typeQueries = array_filter(DB::getQueryLog(), fn ($q) => str_contains($q['query'], 'attribute_types'));

        // Registry warms once on the very first Attribute retrieval; the second
        // independent load must not re-query attribute_types.
        $this->assertCount(1, $typeQueries);
    }

    public function test_updating_attribute_type_invalidates_the_registry(): void
    {
        $type = $this->createAttributeType('text');
        $attribute = $this->createAttribute($type);

        Attribute::query()->find($attribute->id)->type;

        $type->update(['code' => 'renamed']);

        $fetched = Attribute::query()->find($attribute->id);

        $this->assertSame('renamed', $fetched->type->code);
    }

    public function test_updating_attribute_group_invalidates_the_registry(): void
    {
        $type = $this->createAttributeType('text');
        $group = AttributeGroup::create(['code' => 'general', 'sort' => 0]);
        $attribute = $this->createAttribute($type, ['attribute_group_id' => $group->id]);

        Attribute::query()->find($attribute->id)->group;

        $group->update(['code' => 'renamed']);

        $fetched = Attribute::query()->find($attribute->id);

        $this->assertSame('renamed', $fetched->group->code);
    }

    public function test_deleting_attribute_type_invalidates_the_registry(): void
    {
        $type = $this->createAttributeType('text');
        app(AttributeTypeRegistry::class)->all();

        $type->delete();

        $this->assertNull(app(AttributeTypeRegistry::class)->find('text'));
    }

    public function test_attribute_type_lifecycle_dispatches_domain_events(): void
    {
        Event::fake([AttributeTypeCreated::class, AttributeTypeUpdated::class, AttributeTypeDeleted::class]);

        $type = $this->createAttributeType('text');
        $type->update(['code' => 'renamed']);
        $type->delete();

        Event::assertDispatched(AttributeTypeCreated::class);
        Event::assertDispatched(AttributeTypeUpdated::class);
        Event::assertDispatched(AttributeTypeDeleted::class);
    }
}
