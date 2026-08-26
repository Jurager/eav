<?php

declare(strict_types=1);

namespace Jurager\Eav;

use Illuminate\Console\Events\CommandFinished;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Grammars\PostgresGrammar;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Jurager\Eav\Builders\Attributes\AttributesFactory;
use Jurager\Eav\Builders\Schema\SchemaFactory;
use Jurager\Eav\Builders\Translator\TranslatorFactory;
use Jurager\Eav\Fields\FieldFactory;
use Jurager\Eav\Filterable\AttributeEnumUsageResolver;
use Jurager\Eav\Filterable\AttributeFilterResolver;
use Jurager\Eav\Filterable\AttributeSortResolver;
use Jurager\Eav\Events\EntityValuesChanged;
use Jurager\Eav\Jobs\SyncIndexSettings;
use Jurager\Eav\Listeners\ReindexChangedEntities;
use Jurager\Eav\Managers\SchemaManager;
use Jurager\Eav\Managers\TranslationManager;
use Jurager\Eav\Observers\AttributeEnumObserver;
use Jurager\Eav\Observers\AttributeGroupObserver;
use Jurager\Eav\Observers\AttributeObserver;
use Jurager\Eav\Observers\AttributeTypeObserver;
use Jurager\Eav\Registry\AttributeGroupRegistry;
use Jurager\Eav\Registry\AttributeRegistry;
use Jurager\Eav\Registry\AttributeTypeRegistry;
use Jurager\Eav\Registry\EnumRegistry;
use Jurager\Eav\Registry\LocaleRegistry;
use Jurager\Eav\Registry\SchemaRegistry;
use Jurager\Eav\Search\Contracts\InteractsWithIndex;
use Jurager\Eav\Search\Engine;
use Jurager\Eav\Search\Resolvers\AttributeRelationFilterResolver;
use Jurager\Eav\Search\SearchFactory;
use Jurager\Eav\Support\AttributeInheritanceResolver;

class EavServiceProvider extends ServiceProvider
{
    /** Register package services. */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/eav.php', 'eav');

        $this->configureModels();

        // Registries.
        $this->app->scoped(AttributeTypeRegistry::class);
        $this->app->scoped(AttributeGroupRegistry::class);
        $this->app->scoped(LocaleRegistry::class);
        $this->app->scoped(EnumRegistry::class);
        $this->app->scoped(SchemaRegistry::class);
        $this->app->scoped(FieldFactory::class);
        $this->app->scoped(AttributeRegistry::class);

        AttributeRegistry::flush();

        // Managers & Support
        $this->app->singleton(AttributeInheritanceResolver::class);
        $this->app->scoped(TranslationManager::class);
        $this->app->scoped(SchemaManager::class);
        $this->app->scoped(Engine::class);
        $this->app->scoped(SchemaFactory::class);
        $this->app->scoped(TranslatorFactory::class);
        $this->app->scoped(AttributesFactory::class);

        $this->registerFilterResolvers();
    }

    /** Register filter resolvers for filtering and sorting. */
    private function registerFilterResolvers(): void
    {
        $this->app->singleton(AttributeFilterResolver::class);
        $this->app->singleton(AttributeSortResolver::class);
        $this->app->singleton(AttributeEnumUsageResolver::class);
        $this->app->singleton(AttributeRelationFilterResolver::class);

        $this->app->tag([AttributeFilterResolver::class, AttributeSortResolver::class, AttributeEnumUsageResolver::class], 'filterable.resolvers');
        $this->app->tag(AttributeRelationFilterResolver::class, 'eav.search.resolvers');

        $this->app->when(SearchFactory::class)->needs('$resolvers')->giveTagged('eav.search.resolvers');
    }

    /** Configure package models from config. */
    private function configureModels(): void
    {
        Eav::$attributeModel       = config('eav.models.attribute', Eav::$attributeModel);
        Eav::$attributeTypeModel   = config('eav.models.attribute_type', Eav::$attributeTypeModel);
        Eav::$attributeGroupModel  = config('eav.models.attribute_group', Eav::$attributeGroupModel);
        Eav::$attributeEnumModel   = config('eav.models.attribute_enum', Eav::$attributeEnumModel);
        Eav::$entityAttributeModel = config('eav.models.entity_attribute', Eav::$entityAttributeModel);
        Eav::$entityTranslationModel = config('eav.models.entity_translation', Eav::$entityTranslationModel);
        Eav::$localeModel          = config('eav.models.locale', Eav::$localeModel);
    }

    /** Bootstrap package services. */
    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/eav.php' => config_path('eav.php'),
        ], 'eav-config');

        $this->publishes([
            __DIR__.'/../database/migrations/' => database_path('migrations'),
        ], 'eav-migrations');

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadTranslationsFrom(__DIR__.'/../lang', 'eav');

        $this->registerObservers();
        $this->registerCitextSupport();
        $this->registerScoutHook();
    }

    /** Register citext column type support for PostgreSQL. */
    private function registerCitextSupport(): void
    {
        PostgresGrammar::macro('typeCitext', fn () => 'citext');

        Blueprint::macro('citext', function (string $column) {
            /** @var Blueprint $this */
            return DB::connection()->getDriverName() === 'pgsql'
                ? $this->addColumn('citext', $column)
                : $this->addColumn('text', $column);
        });
    }

    /** Register Scout hook for automatic filterable sync. */
    private function registerScoutHook(): void
    {
        $dispatcher = $this->app->make(Dispatcher::class);

        $dispatcher->listen(EntityValuesChanged::class, ReindexChangedEntities::class);

        $dispatcher->listen(CommandFinished::class, static function (CommandFinished $event) {

            if ($event->command !== 'scout:sync-index-settings' || $event->exitCode !== 0) {
                return;
            }

            self::syncableEntityTypes()->each(static fn (string $entityType) => SyncIndexSettings::dispatchSync($entityType));
        });
    }

    /**
     * Get entity types whose filterable attributes must be restored.
     *
     * @return Collection<int, string>
     */
    private static function syncableEntityTypes(): Collection
    {
        return Eav::$attributeModel::query()
            ->withoutGlobalScopes()
            ->distinct()
            ->pluck('entity_type')
            ->merge(self::entityTypesWithIndexPaths())
            ->unique()
            ->values();
    }

    /**
     * Get morph aliases of models declaring additional index paths.
     *
     * @return Collection<int, string>
     */
    private static function entityTypesWithIndexPaths(): Collection
    {
        return (new Collection(Relation::morphMap()))
            ->filter(static fn (mixed $modelClass) => is_string($modelClass) && is_subclass_of($modelClass, InteractsWithIndex::class))
            ->keys();
    }

    /** Register model observers. */
    private function registerObservers(): void
    {
        $observers = [
            Eav::$attributeModel       => AttributeObserver::class,
            Eav::$attributeEnumModel   => AttributeEnumObserver::class,
            Eav::$attributeGroupModel  => AttributeGroupObserver::class,
            Eav::$attributeTypeModel   => AttributeTypeObserver::class,
        ];

        foreach ($observers as $model => $observer) {
            $model::observe($observer);
        }
    }
}
