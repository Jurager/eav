<?php

declare(strict_types=1);

namespace Jurager\Eav\Tests\Feature;

use Illuminate\Console\Events\CommandFinished;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Bus;
use Jurager\Eav\Jobs\SyncFilterable;
use Jurager\Eav\Tests\Fixtures\IndexedProduct;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

class ScoutFilterableSyncTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Bus::fake();
    }

    private function finishCommand(string $command = 'scout:sync-index-settings', int $exitCode = 0): void
    {
        event(new CommandFinished($command, new ArrayInput([]), new NullOutput, $exitCode));
    }

    private function assertDispatchedFor(string $entityType): void
    {
        Bus::assertDispatchedSync(SyncFilterable::class, static fn (SyncFilterable $job) => $job->uniqueId() === $entityType);
    }

    public function test_settings_sync_dispatches_for_entity_types_with_filterable_attributes(): void
    {
        $type = $this->createAttributeType('text');
        $this->createAttribute($type, ['code' => 'color', 'filterable' => true]);

        $this->finishCommand();

        $this->assertDispatchedFor('product');
    }

    public function test_settings_sync_dispatches_for_entity_types_without_filterable_attributes(): void
    {
        $type = $this->createAttributeType('text');
        $this->createAttribute($type, ['code' => 'color', 'filterable' => false]);

        $this->finishCommand();

        $this->assertDispatchedFor('product');
    }

    public function test_settings_sync_dispatches_for_models_declaring_index_filters(): void
    {
        Relation::morphMap(['indexed_product' => IndexedProduct::class]);

        $this->finishCommand();

        $this->assertDispatchedFor('indexed_product');
    }

    public function test_settings_sync_dispatches_once_per_entity_type(): void
    {
        Relation::morphMap(['indexed_product' => IndexedProduct::class]);

        $type = $this->createAttributeType('text');
        $this->createAttribute($type, ['code' => 'color', 'entity_type' => 'indexed_product']);
        $this->createAttribute($type, ['code' => 'size', 'entity_type' => 'indexed_product']);

        $this->finishCommand();

        Bus::assertDispatchedSyncTimes(SyncFilterable::class, 1);
    }

    public function test_other_commands_do_not_trigger_sync(): void
    {
        $type = $this->createAttributeType('text');
        $this->createAttribute($type, ['code' => 'color', 'filterable' => true]);

        $this->finishCommand('scout:import');

        Bus::assertNotDispatchedSync(SyncFilterable::class);
    }

    public function test_failed_command_does_not_trigger_sync(): void
    {
        $type = $this->createAttributeType('text');
        $this->createAttribute($type, ['code' => 'color', 'filterable' => true]);

        $this->finishCommand(exitCode: 1);

        Bus::assertNotDispatchedSync(SyncFilterable::class);
    }
}
