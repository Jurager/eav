<?php

namespace Jurager\Eav;

use Illuminate\Support\ServiceProvider;
use Jurager\Eav\Observers\AttributeObserver;

class EavServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/eav.php', 'eav');

        $this->app->singleton(AttributeLocaleRegistry::class);
        $this->app->singleton(AttributeFieldRegistry::class);
        $this->app->singleton(AttributeInheritanceResolver::class);
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/eav.php' => config_path('eav.php'),
        ], 'eav-config');

        $this->publishes([
            __DIR__.'/../database/migrations/' => database_path('migrations'),
        ], 'eav-migrations');

        $this->loadTranslationsFrom(__DIR__.'/../lang', 'eav');

        $attributeModel = config('eav.models.attribute');
        if ($attributeModel) {
            $attributeModel::observe(AttributeObserver::class);
        }
    }
}
