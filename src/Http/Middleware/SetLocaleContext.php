<?php

declare(strict_types=1);

namespace Jurager\Eav\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Jurager\Eav\Registry\LocaleRegistry;
use Symfony\Component\HttpFoundation\Response;

class SetLocaleContext
{
    public function __construct(protected LocaleRegistry $localeRegistry)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $codes = $request->getLanguages();

        if (! empty($codes)) {
            $this->localeRegistry->set($codes);
        }

        $code = $this->localeRegistry->code($this->localeRegistry->current());

        if ($code !== null) {
            app()->setLocale($code);
        }

        return $next($request);
    }
}
