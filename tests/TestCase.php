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

        // SQLite in memory, or PostgreSQL with DB_CONNECTION=pgsql and the usual DB_* variables.
        $app['config']->set('database.connections.testing', env('DB_CONNECTION') === 'pgsql' ? [
            'driver' => 'pgsql',
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', 5432),
            'database' => env('DB_DATABASE', 'eav'),
            'username' => env('DB_USERNAME', 'postgres'),
            'password' => env('DB_PASSWORD', ''),
        ] : [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        $app['config']->set('app.locale', 'en');
    }

    protected function defineDatabaseMigrations(): void
    {
        if (app('db')->connection()->getDriverName() === 'sqlite') {
            $this->registerSqliteGrammar();
        }

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }

    private function registerSqliteGrammar(): void
    {
        $connection = app('db')->connection();

        $connection->setSchemaGrammar(new class($connection) extends SQLiteGrammar
        {
            /** @var string[] */
            protected $modifiers = ['Increment', 'Nullable', 'Default', 'VirtualAs', 'StoredAs'];

            protected function modifyCollate(Blueprint $blueprint, Fluent $column): string
            {
                return '';
            }
        });
    }

    /** What another process changing the schema through the package leaves behind: a moved schema version. */
    protected function changeSchemaElsewhere(): void
    {
        $this->app['cache']->store(config('eav.cache.store'))->forever('eav:schema-version', (string) \Illuminate\Support\Str::uuid());
    }
}
