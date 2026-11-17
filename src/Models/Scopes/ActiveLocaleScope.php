<?php

declare(strict_types=1);

namespace Jurager\Eav\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Jurager\Eav\Registries\LocaleRegistry;

class ActiveLocaleScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $codes = app(LocaleRegistry::class)->get();

        if ($codes) {
            $builder->whereIn($model->qualifyColumn('code'), $codes);
        }
    }
}
