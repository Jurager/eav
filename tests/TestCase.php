<?php

declare(strict_types=1);

namespace Jurager\Eav\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Grammars\SQLiteGrammar;
use Illuminate\Support\Fluent;
use Jurager\Eav\EavServiceProvider;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Orchestra\Testbench\TestCase as OrchestraTestCase;

abstract class TestCase extends OrchestraTestCase
{
    use MockeryPHPUnitIntegration;

    protected function getPackageProviders($app): array
    {
        return [EavServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        $app['config']->set('app.locale', 'en');
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->registerSqliteGrammar();

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }

    private function registerSqliteGrammar(): void
    {
        $connection = app('db')->connection();

        $connection->setSchemaGrammar(new class ($connection) extends SQLiteGrammar {
            /** @var string[] */
            protected $modifiers = ['Increment', 'Nullable', 'Default', 'VirtualAs', 'StoredAs'];

            protected function modifyCollate(Blueprint $blueprint, Fluent $column): string
            {
                return '';
            }
        });
    }
}
