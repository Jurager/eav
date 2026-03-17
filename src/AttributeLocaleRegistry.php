<?php

namespace Jurager\Eav;

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
     * Get default locale ID from application configuration.
     */
    public function getDefaultLocaleId(): int
    {
        if ($this->defaultLocaleId === null) {
            $this->defaultLocaleId = EavModels::query('locale')
                ->where('code', config('app.locale', 'en'))
                ->value('id') ?? 1;
        }

        return $this->defaultLocaleId;
    }

    /**
     * Get all valid locale IDs.
     *
     * @return array<int>
     */
    public function getValidLocaleIds(): array
    {
        return array_keys($this->getLocaleCodes());
    }

    /**
     * Check if locale ID is valid.
     */
    public function isValidLocaleId(int $localeId): bool
    {
        return in_array($localeId, $this->getValidLocaleIds(), true);
    }

    /**
     * @return array<int, string>
     */
    public function getLocaleCodes(): array
    {
        if ($this->localeCodes === null) {
            $this->localeCodes = EavModels::query('locale')->pluck('code', 'id')->all();
        }

        return $this->localeCodes;
    }

    /**
     * Get locale code by locale ID.
     */
    public function getLocaleCode(int $localeId): ?string
    {
        return $this->getLocaleCodes()[$localeId] ?? null;
    }

    /**
     * Get locale ID by locale code.
     */
    public function getLocaleId(string $code): ?int
    {
        $result = array_search($code, $this->getLocaleCodes(), true);

        return $result !== false ? $result : null;
    }

    /**
     * Resolve locale ID from code or get default.
     */
    public function resolveLocaleId(?string $code = null): int
    {
        if ($code !== null) {
            $localeId = $this->getLocaleId($code);
            if ($localeId !== null) {
                return $localeId;
            }
        }

        return $this->getDefaultLocaleId();
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
