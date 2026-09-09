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

    public function test_type_and_group_are_both_populated_from_the_registry_on_the_same_fetch(): void
    {
        $type = $this->createAttributeType('text');
        $group = AttributeGroup::create(['code' => 'general', 'sort' => 0]);
        $attribute = $this->createAttribute($type, ['attribute_group_id' => $group->id]);

        // Warm the registries once, outside of the assertion window.
        app(AttributeTypeRegistry::class)->all();
        app(AttributeGroupRegistry::class)->all();

        DB::enableQueryLog();

        $fetched = Attribute::query()->find($attribute->id);

        $this->assertTrue($fetched->relationLoaded('type'));
        $this->assertSame('text', $fetched->type->code);
        $this->assertTrue($fetched->relationLoaded('group'));
        $this->assertSame('general', $fetched->group->code);
        $this->assertSame([], array_filter(DB::getQueryLog(), fn ($q) => str_contains($q['query'], 'attribute_types') || str_contains($q['query'], 'attribute_groups')));

        // Neither relation carries translations here — those are locale-scoped, so nothing
        // preloads them; the group's own come off its per-request clone lazily instead (see
        // the dedicated coverage below).
        $this->assertFalse($fetched->relationLoaded('translations'));
        $this->assertFalse($fetched->group->relationLoaded('translations'));
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

    public function test_group_translations_are_queried_once_per_request_however_many_attributes_share_the_group(): void
    {
        $type = $this->createAttributeType('text');
        $group = AttributeGroup::create(['code' => 'general', 'sort' => 0]);
        $locale = $this->createLocale();
        $group->translations()->attach($locale->id, ['label' => 'General']);

        $a = $this->createAttribute($type, ['code' => 'a', 'attribute_group_id' => $group->id]);
        $b = $this->createAttribute($type, ['code' => 'b', 'attribute_group_id' => $group->id]);

        // Two independent top-level queries, mirroring separate calls within one request
        // (e.g. a search endpoint plus a resource that separately re-fetches attributes) —
        // exactly the shape that used to fire the same group-translations query 3 times.
        $fetchedA = Attribute::query()->find($a->id);
        $fetchedB = Attribute::query()->find($b->id);

        DB::enableQueryLog();

        $this->assertCount(1, $fetchedA->group->translations);

        $groupQueries = array_filter(DB::getQueryLog(), fn ($q) => str_contains($q['query'], 'attribute_groups') || str_contains($q['query'], 'entity_translations'));
        $this->assertNotEmpty($groupQueries, 'expected the first ->translations access to query.');

        DB::flushQueryLog();

        // $fetchedB carries the SAME clone for group id — its ->translations access must
        // reuse what $fetchedA already loaded, not fire a second query.
        $this->assertCount(1, $fetchedB->group->translations);
        $this->assertSame([], array_filter(DB::getQueryLog(), fn ($q) => str_contains($q['query'], 'attribute_groups') || str_contains($q['query'], 'entity_translations')));

        DB::disableQueryLog();
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

        // Registry warms once on the very first Attribute retrieval (a staleness stamp,
        // then the rows); the second independent load must not re-query attribute_types.
        $this->assertCount(2, $typeQueries);
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

    // -----------------------------------------------------------------------
    // Cross-request caching (Octane: the registry is rebuilt, the reference data isn't)
    // -----------------------------------------------------------------------

    public function test_attribute_types_survive_a_fresh_registry_instance_with_only_a_stamp_check(): void
    {
        $this->createAttributeType('text');
        app(AttributeTypeRegistry::class)->all();

        // Simulate the next Octane request: the container drops the scoped instance,
        // but the static state a fresh instance reads from must still be there.
        app()->forgetScopedInstances();
        $registry = app(AttributeTypeRegistry::class);

        DB::enableQueryLog();
        $all = $registry->all();

        $this->assertCount(1, $all);
        // Just the staleness stamp — not the rows themselves.
        $this->assertCount(1, array_filter(DB::getQueryLog(), fn ($q) => str_contains($q['query'], 'attribute_types')));
    }

    public function test_attribute_groups_survive_a_fresh_registry_instance_with_only_a_stamp_check(): void
    {
        AttributeGroup::create(['code' => 'general', 'sort' => 0]);
        app(AttributeGroupRegistry::class)->all();

        app()->forgetScopedInstances();
        $registry = app(AttributeGroupRegistry::class);

        DB::enableQueryLog();
        $all = $registry->all();

        $this->assertCount(1, $all);
        $this->assertCount(1, array_filter(DB::getQueryLog(), fn ($q) => str_contains($q['query'], 'attribute_groups')));
    }

    public function test_group_translations_do_not_leak_into_the_next_simulated_request(): void
    {
        $type = $this->createAttributeType('text');
        $group = AttributeGroup::create(['code' => 'general', 'sort' => 0]);
        $locale = $this->createLocale();
        $group->translations()->attach($locale->id, ['label' => 'General']);

        $attribute = $this->createAttribute($type, ['attribute_group_id' => $group->id]);

        $fetched = Attribute::query()->find($attribute->id);
        $this->assertCount(1, $fetched->group->translations);

        // The clone that carried those translations belongs to this request's registry
        // instance. Simulate the next Octane request: the container drops the scoped
        // instance, so the next fetch must get a fresh clone with no relation preloaded —
        // not the previous request's clone/translations bleeding across the boundary.
        app()->forgetScopedInstances();

        $fetchedAgain = Attribute::query()->find($attribute->id);

        $this->assertFalse($fetchedAgain->group->relationLoaded('translations'));
    }
}
