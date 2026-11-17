<?php

declare(strict_types=1);

namespace Jurager\Eav\Tests\Feature\Search;

use Illuminate\Console\Events\CommandFinished;
use Illuminate\Support\Facades\Queue;
use Jurager\Eav\Jobs\ReindexAttribute;
use Jurager\Eav\Jobs\SyncIndexSettings;
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

    public function test_changing_what_the_index_depends_on_syncs(): void
    {
        foreach (['searchable' => true, 'sortable' => true, 'code' => 'renamed', 'localizable' => true] as $column => $value) {
            $attribute = $this->attribute('text', "attr_{$column}");
            Queue::fake();

            $attribute->update([$column => $value]);

            $this->assertSynced($attribute->id);
        }
    }

    public function test_changing_anything_else_syncs_nothing(): void
    {
        $attribute = $this->attribute('text', 'title', ['searchable' => true]);
        Queue::fake();

        $attribute->update(['required' => true]);

        Queue::assertNotPushed(SyncIndexSettings::class);
        Queue::assertNotPushed(ReindexAttribute::class);
    }

    public function test_an_option_of_an_indexed_select_reindexes_its_documents(): void
    {
        $color = $this->attribute('select', 'color', ['filterable' => true]);
        Queue::fake();

        $this->option($color, 'red');

        Queue::assertPushed(ReindexAttribute::class, fn (ReindexAttribute $job) => $job->uniqueId() === "searchable_product:{$color->id}");
    }

    public function test_a_new_locale_syncs_settings_of_every_searchable_type(): void
    {
        Queue::fake();

        $this->createLocale('de');

        Queue::assertPushed(SyncIndexSettings::class, 2);
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
