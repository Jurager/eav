<?php

namespace Jurager\Eav\Registry;

use Jurager\Eav\Support\EavModels;

/**
 * Registry for locale management in attribute system.
 *
 * Provides cached access to locale IDs and codes to avoid
 * repeated database queries during attribute operations.
 */
class AttributeLocaleRegistry
{
    /**
     * Cached default locale ID.
     */
    protected ?int $defaultLocaleId = null;

    /**
     * Cached mapping of locale ID to locale code.
     *
     * @var array<int, string>|null
     */
    protected ?array $localeCodes = null;

    /**
     * Return the default locale ID from application configuration.
     */
    public function defaultLocaleId(): int
    {
        if ($this->defaultLocaleId === null) {
            $this->defaultLocaleId = EavModels::query('locale')
                ->where('code', config('app.locale', 'en'))
                ->value('id') ?? 1;
        }

        return $this->defaultLocaleId;
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

    /**
     * Determine if the given locale ID is registered.
     */
    public function isValidLocaleId(int $localeId): bool
    {
        return in_array($localeId, $this->validLocaleIds(), true);
    }

    /**
     * Return all locale codes keyed by locale ID.
     *
     * @return array<int, string>
     */
    public function localeCodes(): array
    {
        if ($this->localeCodes === null) {
            $this->localeCodes = EavModels::query('locale')->pluck('code', 'id')->all();
        }

        return $this->localeCodes;
    }

    /**
     * Return the locale code for a given locale ID, or null if not found.
     */
    public function localeCode(int $localeId): ?string
    {
        return $this->localeCodes()[$localeId] ?? null;
    }

    /**
     * Return the locale ID for a given locale code, or null if not found.
     */
    public function localeId(string $code): ?int
    {
        $result = array_search($code, $this->localeCodes(), true);

        return $result !== false ? $result : null;
    }

    /**
     * Resolve a locale ID from a code string, falling back to the default locale.
     */
    public function resolveLocaleId(?string $code = null): int
    {
        if ($code !== null) {
            $localeId = $this->localeId($code);

            if ($localeId !== null) {
                return $localeId;
            }
        }

        return $this->defaultLocaleId();
    }

    /**
     * Reset all cached data.
     */
    public function reset(): void
    {
        $this->defaultLocaleId = null;
        $this->localeCodes = null;
    }
}
