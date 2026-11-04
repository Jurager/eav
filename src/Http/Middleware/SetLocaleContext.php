<?php

declare(strict_types=1);

namespace Jurager\Eav\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Jurager\Eav\Registry\LocaleRegistry;
use Symfony\Component\HttpFoundation\Response;

/**
 * Set the active locales from the Accept-Language header.
 */
class SetLocaleContext
{
    public function __construct(protected LocaleRegistry $locales) {}

    public function handle(Request $request, Closure $next): Response
    {
        $accepted = $this->accepted($request);

        if ($accepted !== []) {
            $this->locales->set($accepted);
            app()->setLocale($accepted[0]);
        }

        return $next($request);
    }

    /**
     * Get accepted locales in preference order, allowing base language matches.
     *
     * @return list<string>
     */
    protected function accepted(Request $request): array
    {
        if (! $request->headers->has('Accept-Language')) {
            return [];
        }

        $known = array_flip($this->locales->codes());
        $accepted = [];

        foreach ($request->getLanguages() as $language) {
            $base = strtok($language, '_-');

            $code = match (true) {
                isset($known[$language]) => $language,
                is_string($base) && isset($known[$base]) => $base,
                default => null,
            };

            if ($code !== null) {
                $accepted[$code] = true;
            }
        }

        return array_keys($accepted);
    }
}
