<?php

namespace Jurager\Eav\Registry;

use Illuminate\Support\Collection;
use Jurager\Eav\Exceptions\InvalidConfigurationException;
use Jurager\Eav\Support\EavModels;

/**
 * In-memory cache for locale data.
 */
class LocaleRegistry
{
    /** @var Collection<int, string>|null  id → code */
    private ?Collection $locales = null;

    private ?int $defaultId = null;

    /**
     * All locales keyed by ID, values are codes.
     *
     * @return Collection<int, string>
     */
    public function all(): Collection
    {
        return $this->locales ??= EavModels::query('locale')->pluck('code', 'id');
    }

    /**
     * All locale IDs.
     *
     * @return array<int>
     */
    public function ids(): array
    {
        return $this->all()->keys()->all();
    }

    public function has(int $id): bool
    {
        return $this->all()->has($id);
    }

    /**
     * Get the locale code for a given ID, or null if not found.
     */
    public function code(int $id): ?string
    {
        return $this->all()->get($id);
    }

    /**
     * Find the locale ID for a given code, or null if not found.
     */
    public function find(string $code): ?int
    {
        $id = $this->all()->search($code);

        return $id !== false ? $id : null;
    }

    /**
     * Resolve a locale ID from a code string, falling back to the application default.
     */
    public function resolve(?string $code = null): int
    {
        return ($code !== null ? $this->find($code) : null) ?? $this->default();
    }

    /**
     * The default locale ID from application configuration.
     *
     * @throws InvalidConfigurationException
     */
    public function default(): int
    {
        if ($this->defaultId !== null) {
            return $this->defaultId;
        }

        $code = config('app.locale', 'en');

        return $this->defaultId = $this->find($code)
            ?? throw InvalidConfigurationException::localeNotFound($code);
    }

    /**
     * Forget all cached data.
     */
    public function forget(): void
    {
        $this->locales = null;
        $this->defaultId = null;
    }
}
