<?php

namespace Jurager\Eav;

use Illuminate\Support\ServiceProvider;
use Jurager\Eav\Observers\AttributeEnumObserver;
use Jurager\Eav\Observers\AttributeObserver;
use Jurager\Eav\Registry\EnumRegistry;
use Jurager\Eav\Registry\FieldTypeRegistry;
use Jurager\Eav\Registry\LocaleRegistry;
use Jurager\Eav\Registry\SchemaRegistry;
use Jurager\Eav\Support\AttributeInheritanceResolver;

class EavServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/eav.php', 'eav');

        $this->app->singleton(LocaleRegistry::class);
        $this->app->singleton(FieldTypeRegistry::class);
        $this->app->singleton(SchemaRegistry::class);
        $this->app->singleton(EnumRegistry::class);
        $this->app->singleton(AttributeInheritanceResolver::class);
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

        $attributeModel = config('eav.models.attribute');

        if ($attributeModel) {
            $attributeModel::observe(AttributeObserver::class);
        }

        $enumModel = config('eav.models.attribute_enum');

        if ($enumModel) {
            $enumModel::observe(AttributeEnumObserver::class);
        }
    }
}
