<?php

namespace Jurager\Eav;

use Illuminate\Console\Events\CommandFinished;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Grammars\PostgresGrammar;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Fluent;
use Illuminate\Support\ServiceProvider;
use Jurager\Eav\Jobs\SyncFilterable;
use Jurager\Eav\Managers\SchemaManager;
use Jurager\Eav\Managers\TranslationManager;
use Jurager\Eav\Observers\AttributeEnumObserver;
use Jurager\Eav\Observers\AttributeObserver;
use Jurager\Eav\Registry\AttributeTypeRegistry;
use Jurager\Eav\Registry\EnumRegistry;
use Jurager\Eav\Fields\FieldFactory;
use Jurager\Eav\Registry\LocaleRegistry;
use Jurager\Eav\Registry\SchemaRegistry;
use Jurager\Eav\Search\FilterCompiler;
use Jurager\Eav\Search\Search;
use Jurager\Eav\Support\AttributeInheritanceResolver;
use Jurager\Eav\Support\EavModels;

class EavServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/eav.php', 'eav');

        $this->app->singleton(AttributeTypeRegistry::class);
        $this->app->singleton(LocaleRegistry::class);
        $this->app->singleton(FieldFactory::class);
        $this->app->singleton(SchemaRegistry::class);
        $this->app->singleton(EnumRegistry::class);
        $this->app->singleton(AttributeInheritanceResolver::class);
        $this->app->singleton(TranslationManager::class);
        $this->app->singleton(SchemaManager::class);
        $this->app->singleton(FilterCompiler::class);

        $this->app->bind(Search::class);
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/eav.php' => config_path('eav.php'),
        ], 'eav-config');

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        $this->publishes([
            __DIR__.'/../database/migrations/' => database_path('migrations'),
        ], 'eav-migrations');

        $this->loadTranslationsFrom(__DIR__.'/../lang', 'eav');

        $this->registerObservers();
        $this->registerCitextSupport();
        $this->registerScoutHook();
    }

    /** Register citext column type support for PostgreSQL. */
    private function registerCitextSupport(): void
    {
        PostgresGrammar::macro('typeCitext', function (Fluent $column) {
            return 'citext';
        });

        Blueprint::macro('citext', function (string $column) {
            if (DB::connection()->getDriverName() === 'pgsql') {
                return $this->addColumn('citext', $column);
            }

            return $this->addColumn('text', $column);
        });
    }

    private function registerScoutHook(): void
    {
        Event::listen(CommandFinished::class, static function (CommandFinished $event) {

            if ($event->command !== 'scout:sync-index-settings' || $event->exitCode !== 0) {
                return;
            }

            if (! EavModels::has('attribute')) {
                return;
            }

            EavModels::query('attribute')
                ->withoutGlobalScopes()
                ->where('filterable', true)
                ->distinct()
                ->pluck('entity_type')
                ->each(fn (string $entityType) => SyncFilterable::dispatchSync($entityType));
        });
    }

    private function registerObservers(): void
    {
        $models = [
            'attribute' => AttributeObserver::class,
            'attribute_enum' => AttributeEnumObserver::class,
        ];

        foreach ($models as $key => $observer) {

            $model = config("eav.models.$key");

            if ($model) {
                $model::observe($observer);
            }
        }
    }
}
