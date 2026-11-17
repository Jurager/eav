<?php

declare(strict_types=1);

namespace Jurager\Eav\Tests\Feature\Search;

use Illuminate\Console\Events\CommandFinished;
use Illuminate\Support\Facades\Queue;
use Jurager\Eav\Jobs\ReindexAttribute;
use Jurager\Eav\Jobs\ReindexEntities;
use Jurager\Eav\Jobs\SyncIndexSettings;
use Jurager\Eav\Models\AttributeType;
use Jurager\Eav\Schema\Repositories\AttributeRepository;
use Laravel\Scout\Jobs\MakeSearchable;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

class SynchronizerTest extends SearchTestCase
{
    private function assertSynced(int $attributeId, string $type = 'searchable_product'): void
    {
        Queue::assertPushed(SyncIndexSettings::class, fn (SyncIndexSettings $job) => $job->entityType === $type);
        Queue::assertPushed(ReindexAttribute::class, fn (ReindexAttribute $job) => $job->uniqueId() === "{$type}:{$attributeId}");
    }

    public function test_an_indexed_attribute_syncs_settings_and_documents_when_created(): void
    {
        $attribute = $this->attribute('number', 'price', ['sortable' => true]);

        $this->assertSynced($attribute->id);
    }

    public function test_an_attribute_that_is_not_indexed_syncs_nothing(): void
    {
        Queue::fake();

        $this->attribute('text', 'notes');

        Queue::assertNotPushed(SyncIndexSettings::class);
        Queue::assertNotPushed(ReindexAttribute::class);
    }

    public function test_flagging_an_attribute_for_the_index_syncs(): void
    {
        foreach (['searchable', 'filterable', 'sortable'] as $flag) {
            $attribute = $this->attribute('text', "attr_{$flag}");
            Queue::fake();

            $attribute->update([$flag => true]);

            $this->assertSynced($attribute->id);
        }
    }

    public function test_taking_an_attribute_out_of_the_index_syncs(): void
    {
        $attribute = $this->attribute('text', 'title', ['searchable' => true]);
        Queue::fake();

        $attribute->update(['searchable' => false]);

        $this->assertSynced($attribute->id);
    }

    public function test_changing_how_an_indexed_attribute_is_indexed_syncs(): void
    {
        foreach (['code' => 'renamed', 'localizable' => true, 'sort' => 5] as $column => $value) {
            $attribute = $this->attribute('text', "attr_{$column}", ['searchable' => true]);
            Queue::fake();

            $attribute->update([$column => $value]);

            $this->assertSynced($attribute->id);
        }
    }

    public function test_changes_that_do_not_reach_the_index_sync_nothing(): void
    {
        $indexed = $this->attribute('text', 'title', ['searchable' => true]);
        $plain = $this->attribute('text', 'notes');
        Queue::fake();

        $indexed->update(['required' => true]);
        $plain->update(['code' => 'remarks', 'localizable' => true]);

        Queue::assertNotPushed(SyncIndexSettings::class);
        Queue::assertNotPushed(ReindexAttribute::class);
    }

    public function test_a_type_that_stops_using_an_attribute_is_synced_too(): void
    {
        AttributeType::create(['code' => 'number', 'filterable' => true]);
        $attribute = $this->attribute('number', 'price', ['filterable' => true, 'entity_types' => ['searchable_product', 'searchable_category']]);
        Queue::fake();

        app(AttributeRepository::class)->update($attribute, ['entity_types' => ['searchable_product']]);

        Queue::assertPushed(SyncIndexSettings::class, fn (SyncIndexSettings $job) => $job->entityType === 'searchable_category');
        Queue::assertPushed(SyncIndexSettings::class, fn (SyncIndexSettings $job) => $job->entityType === 'searchable_product');
    }

    public function test_an_option_of_an_indexed_select_reindexes_its_documents(): void
    {
        $color = $this->attribute('select', 'color', ['filterable' => true]);
        Queue::fake();

        $this->option($color, 'red');

        Queue::assertPushed(ReindexAttribute::class, fn (ReindexAttribute $job) => $job->uniqueId() === "searchable_product:{$color->id}");
    }

    public function test_a_new_locale_syncs_settings_only(): void
    {
        $this->attribute('text', 'title', ['searchable' => true, 'localizable' => true]);
        Queue::fake();

        $this->createLocale('de');

        Queue::assertPushed(SyncIndexSettings::class, 2);
        Queue::assertNotPushed(ReindexEntities::class);
    }

    public function test_a_removed_locale_rebuilds_the_documents_holding_localized_values(): void
    {
        $this->attribute('text', 'title', ['searchable' => true, 'localizable' => true]);
        Queue::fake();

        $this->ru->delete();

        Queue::assertPushed(SyncIndexSettings::class, 2);
        Queue::assertPushed(ReindexEntities::class, 1);
        Queue::assertPushed(ReindexEntities::class, fn (ReindexEntities $job) => $job->entityType === 'searchable_product');
    }

    public function test_a_renamed_locale_rebuilds_the_documents_too(): void
    {
        $this->attribute('text', 'title', ['searchable' => true, 'localizable' => true]);
        Queue::fake();

        $this->ru->update(['name' => 'Russian']);
        Queue::assertNotPushed(ReindexEntities::class);

        $this->ru->update(['code' => 'uk']);
        Queue::assertPushed(ReindexEntities::class, fn (ReindexEntities $job) => $job->entityType === 'searchable_product');
    }

    public function test_a_removed_locale_rebuilds_nothing_without_localized_fields(): void
    {
        $this->attribute('number', 'price', ['filterable' => true]);
        Queue::fake();

        $this->ru->delete();

        Queue::assertNotPushed(ReindexEntities::class);
    }

    public function test_scouts_settings_command_is_followed_by_the_derived_settings(): void
    {
        $this->attribute('number', 'price', ['filterable' => true]);

        event(new CommandFinished('scout:sync-index-settings', new ArrayInput([]), new NullOutput, 0));

        $this->assertContains('attributes.price', $this->engine->settings['eav_test_products']['filterableAttributes']);
    }

    public function test_other_or_failed_commands_sync_nothing(): void
    {
        event(new CommandFinished('migrate', new ArrayInput([]), new NullOutput, 0));
        event(new CommandFinished('scout:sync-index-settings', new ArrayInput([]), new NullOutput, 1));

        $this->assertSame([], $this->engine->settings);
    }

    public function test_reindexing_an_attribute_covers_its_holders_and_their_variants(): void
    {
        config(['scout.queue' => true]);

        $this->attribute('text', 'title', ['searchable' => true, 'inherit_from_parent' => true]);

        $parent = $this->product('Model');
        $variant = $this->product('Variant', $parent->id);
        $other = $this->product('Other');
        $parent->eav()->set('title', 'Parent title')->save();

        Queue::fake();

        (new ReindexAttribute('searchable_product', $parent->eav()->field('title')->attribute()->id))->handle();

        Queue::assertPushed(MakeSearchable::class, function (MakeSearchable $job) use ($parent, $variant, $other): bool {
            return $job->models->contains('id', $parent->id)
                && $job->models->contains('id', $variant->id)
                && ! $job->models->contains('id', $other->id);
        });
    }
}
