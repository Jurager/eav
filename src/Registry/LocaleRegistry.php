<?php

namespace Jurager\Eav\Registry;

use RuntimeException;
use Jurager\Eav\Support\EavModels;

/**
 * Caches locale IDs and codes to avoid repeated database queries.
 *
 * Registered as a singleton in EavServiceProvider.
 */
class LocaleRegistry
{
    protected ?int $defaultLocaleId = null;

    /** @var array<int, string>|null */
    protected ?array $localeCodes = null;

    /**
     * Return the default locale ID from application configuration.
     */
    public function defaultLocaleId(): int
    {
        $code = config('app.locale', 'en');

        return $this->defaultLocaleId ??= EavModels::query('locale')->where('code', $code)->value('id')
            ?? throw new RuntimeException("Default locale \"$code\" not found in the locales table. Add it or update app.locale.");
    }

    /**
     * Return all valid locale IDs.
     *
     * @return array<int>
     */
    public function validLocaleIds(): array
    {
        return array_keys($this->localeCodes());
    }

    public function isValidLocaleId(int $localeId): bool
    {
        return isset($this->localeCodes()[$localeId]);
    }

    /**
     * Return all locale codes keyed by locale ID.
     *
     * @return array<int, string>
     */
    public function localeCodes(): array
    {
        return $this->localeCodes ??= EavModels::query('locale')->pluck('code', 'id')->all();
    }

    public function localeCode(int $localeId): ?string
    {
        return $this->localeCodes()[$localeId] ?? null;
    }

    public function localeId(string $code): ?int
    {
        $result = array_search($code, $this->localeCodes(), true);

        return $result !== false ? $result : null;
    }

    /**
     * Resolve a locale ID from a code string, falling back to the default locale.
     */
    public function resolve(?string $code = null): int
    {
        return ($code !== null ? $this->localeId($code) : null) ?? $this->defaultLocaleId();
    }

    /**
     * Flush all cached data. Useful in tests or long-running processes.
     */
    public function flush(): void
    {
        $this->defaultLocaleId = null;
        $this->localeCodes = null;
    }
}
