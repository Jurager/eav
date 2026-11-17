<?php

declare(strict_types=1);

namespace Jurager\Eav;

use Illuminate\Console\Events\CommandFinished;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Grammars\PostgresGrammar;
use Illuminate\Support\ServiceProvider;
use Jurager\Eav\Attributes\AttributesFactory;
use Jurager\Eav\Attributes\AttributeValueSorter;
use Jurager\Eav\Attributes\Query\AttributeQueryFactory;
use Jurager\Eav\Attributes\Scope\AttributeInheritanceResolver;
use Jurager\Eav\Commands\ClearCommand;
use Jurager\Eav\Commands\SearchReindexCommand;
use Jurager\Eav\Commands\SearchSyncCommand;
use Jurager\Eav\Events\EntityValuesChanged;
use Jurager\Eav\Exceptions\SearchNotAvailableException;
use Jurager\Eav\Fields\FieldFactory;
use Jurager\Eav\Fields\FieldTypes;
use Jurager\Eav\Filterable\AttributeEntityTypeFilterResolver;
use Jurager\Eav\Filterable\AttributeEnumUsageResolver;
use Jurager\Eav\Filterable\AttributeFilterResolver;
use Jurager\Eav\Filterable\AttributeSortResolver;
use Jurager\Eav\Media\MediaCollectionResolver;
use Jurager\Eav\Media\MediaObserver;
use Jurager\Eav\Observers\AttributeEnumObserver;
use Jurager\Eav\Observers\AttributeGroupObserver;
use Jurager\Eav\Observers\AttributeObserver;
use Jurager\Eav\Observers\AttributeTypeObserver;
use Jurager\Eav\Observers\LocaleObserver;
use Jurager\Eav\Registry\AttributeGroupRegistry;
use Jurager\Eav\Registry\AttributeRegistry;
use Jurager\Eav\Registry\AttributeTypeRegistry;
use Jurager\Eav\Registry\EnumRegistry;
use Jurager\Eav\Registry\LocaleRegistry;
use Jurager\Eav\Registry\SchemaRegistry;
use Jurager\Eav\Registry\SchemaVersion;
use Jurager\Eav\Schema\SchemaManager;
use Jurager\Eav\Search\Contracts\Transport;
use Jurager\Eav\Search\Index\Configurator;
use Jurager\Eav\Search\Index\IndexFactory;
use Jurager\Eav\Search\Index\Synchronizer;
use Jurager\Eav\Search\Resolvers\AttributeRelationFilterResolver;
use Jurager\Eav\Search\Search;
use Jurager\Eav\Search\Transport\MeilisearchTransport;
use Jurager\Eav\Translation\TranslationRepository;
use Jurager\Eav\Translation\Translator;
use Jurager\Filterable\FilterableServiceProvider;
use Jurager\Media\Models\Media;
use Jurager\Media\Support\MediaCollectionResolverRegistry;
use Meilisearch\Client;

class EavServiceProvider extends ServiceProvider
{
    /**
     * Register package services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/eav.php', 'eav');

        $this->configureModels();
        $this->registerRegistries();
        $this->registerFields();
        $this->registerAttributes();
        $this->registerSchema();
        $this->registerFilterResolvers();
        $this->registerSearch();
    }

    /**
     * Register the schema registries.
     */
    private function registerRegistries(): void
    {
        foreach ([AttributeRegistry::class, AttributeTypeRegistry::class, AttributeGroupRegistry::class, LocaleRegistry::class, EnumRegistry::class] as $registry) {
            $this->app->scoped($registry);
            $registry::flush();
        }

        $this->app->scoped(SchemaRegistry::class);
        $this->app->scoped(SchemaVersion::class);
        $this->app->when(SchemaVersion::class)->needs(Cache::class)->give(
            static fn (Application $app): Cache => $app['cache']->store(config('eav.cache.store')),
        );
    }

    private function registerFields(): void
    {
        $this->app->singleton(FieldTypes::class, static fn (): FieldTypes => new FieldTypes(config('eav.types', [])));
        $this->app->scoped(FieldFactory::class);
    }

    private function registerAttributes(): void
    {
        $this->app->scoped(AttributesFactory::class);
        $this->app->scoped(AttributeQueryFactory::class);
        $this->app->scoped(AttributeValueSorter::class);
        $this->app->singleton(AttributeInheritanceResolver::class);
    }

    private function registerSchema(): void
    {
        $this->app->scoped(SchemaManager::class);
        $this->app->scoped(Translator::class);
        $this->app->scoped(TranslationRepository::class);
    }

    /**
     * Register filter resolvers for filtering and sorting.
     */
    private function registerFilterResolvers(): void
    {
        $this->app->scoped(AttributeFilterResolver::class);
        $this->app->scoped(AttributeSortResolver::class);
        $this->app->singleton(AttributeEnumUsageResolver::class);
        $this->app->singleton(AttributeEntityTypeFilterResolver::class);
        $this->app->singleton(AttributeRelationFilterResolver::class);

        $this->app->tag([
            AttributeFilterResolver::class,
            AttributeSortResolver::class,
            AttributeEnumUsageResolver::class,
            AttributeEntityTypeFilterResolver::class,
        ], FilterableServiceProvider::RESOLVER_TAG);
    }

    /**
     * Configure package models from config.
     */
    private function configureModels(): void
    {
        Eav::$attributeModel = config('eav.models.attribute', Eav::$attributeModel);
        Eav::$attributeTypeModel = config('eav.models.attribute_type', Eav::$attributeTypeModel);
        Eav::$attributeGroupModel = config('eav.models.attribute_group', Eav::$attributeGroupModel);
        Eav::$attributeEnumModel = config('eav.models.attribute_enum', Eav::$attributeEnumModel);
        Eav::$attributeEntityTypeModel = config('eav.models.attribute_entity_type', Eav::$attributeEntityTypeModel);
        Eav::$entityAttributeModel = config('eav.models.entity_attribute', Eav::$entityAttributeModel);
        Eav::$entityTranslationModel = config('eav.models.entity_translation', Eav::$entityTranslationModel);
        Eav::$localeModel = config('eav.models.locale', Eav::$localeModel);
    }

    /**
     * Bootstrap package services.
     */
    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/eav.php' => config_path('eav.php'),
        ], 'eav-config');

        $this->publishes([
            __DIR__.'/../database/migrations/' => database_path('migrations'),
        ], 'eav-migrations');

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        if ($this->app->runningInConsole()) {
            $this->commands([ClearCommand::class, SearchSyncCommand::class, SearchReindexCommand::class]);
        }
        $this->loadTranslationsFrom(__DIR__.'/../lang', 'eav');

        $this->registerObservers();
        $this->registerMediaIntegration();
        $this->registerCitextSupport();
        $this->registerSearchListeners();
    }

    /**
     * Register citext column type support for PostgreSQL.
     */
    private function registerCitextSupport(): void
    {
        PostgresGrammar::macro('typeCitext', fn () => 'citext');

        Blueprint::macro('citext', function (string $column) {
            /** @var Blueprint $this */
            return $this->connection->getDriverName() === 'pgsql'
                ? $this->addColumn('citext', $column)
                : $this->addColumn('text', $column);
        });
    }

    /**
     * Register the search services.
     */
    private function registerSearch(): void
    {
        $this->app->scoped(IndexFactory::class);
        $this->app->scoped(Search::class);

        $this->app->bind(Transport::class, function ($app): Transport {
            if (! IndexFactory::available() || ! class_exists(Client::class)) {
                throw SearchNotAvailableException::notInstalled();
            }

            return new MeilisearchTransport($app->make(Client::class));
        });
    }

    /**
     * Keep search documents and index settings in step with attribute values.
     */
    private function registerSearchListeners(): void
    {
        $events = $this->app->make(Dispatcher::class);

        $events->listen(EntityValuesChanged::class, function (EntityValuesChanged $event): void {
            $this->app->make(Synchronizer::class)->values($event->entityType, $event->entityIds);
        });

        // Scout's own command resets an index to the configured settings: put the derived ones back.
        $events->listen(CommandFinished::class, function (CommandFinished $event): void {
            if ($event->command === 'scout:sync-index-settings' && $event->exitCode === 0) {
                $this->app->make(Configurator::class)->all();
            }
        });
    }

    /**
     * Register model observers.
     */
    private function registerObservers(): void
    {
        $observers = [
            Eav::$attributeModel => AttributeObserver::class,
            Eav::$attributeEnumModel => AttributeEnumObserver::class,
            Eav::$attributeGroupModel => AttributeGroupObserver::class,
            Eav::$attributeTypeModel => AttributeTypeObserver::class,
            Eav::$localeModel => LocaleObserver::class,
        ];

        foreach ($observers as $model => $observer) {
            $model::observe($observer);
        }
    }

    /**
     * Wire the media field integration when media package is installed.
     */
    private function registerMediaIntegration(): void
    {
        if (! class_exists(Media::class)) {
            return;
        }

        config('media.models.media', Media::class)::observe(MediaObserver::class);

        $this->app->make(MediaCollectionResolverRegistry::class)->register($this->app->make(MediaCollectionResolver::class));
    }
}
