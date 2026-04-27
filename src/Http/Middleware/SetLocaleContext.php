<?php

namespace Jurager\Eav\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Jurager\Eav\Registry\LocaleRegistry;
use Symfony\Component\HttpFoundation\Response;

class SetLocaleContext
{
    public function __construct(protected LocaleRegistry $localeRegistry) {}

    public function handle(Request $request, Closure $next): Response
    {
        $param = config('eav.locale_context.parameter', 'locales');

        $value = $request->query($param);

        if (!empty($value)) {
            $codes = is_array($value) ? $value : explode(',', $value);
            $this->localeRegistry->set(array_filter(array_map('trim', $codes)));
        }

        return $next($request);
    }
}
