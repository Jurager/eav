<?php

declare(strict_types=1);

namespace Jurager\Eav\Registry;

use Illuminate\Support\Collection;
use Jurager\Eav\Eav;
use Jurager\Eav\Exceptions\InvalidConfigurationException;

class LocaleRegistry
{
    /** @var Collection<int, string>|null id → code */
    private ?Collection $locales = null;

    private ?int $defaultId = null;

    /** @var array<string>|null Active locales for the current request. */
    private ?array $active = null;

    /** Get all cached locales. */
    public function all(): Collection
    {
        return $this->locales ??= Eav::$localeModel::query()->pluck('code', 'id');
    }

    /** Get all locale IDs. */
    public function ids(): array
    {
        return $this->all()->keys()->toArray();
    }

    /** Determine if the locale exists by ID. */
    public function has(int $id): bool
    {
        return $this->all()->has($id);
    }

    /** Get the locale code by ID. */
    public function code(int $id): ?string
    {
        return $this->all()->get($id);
    }

    /** Find a locale ID by its code. */
    public function find(string $code): ?int
    {
        $id = $this->all()->search($code);

        return $id !== false ? $id : null;
    }

    /** Resolve a locale ID by code or return the default. */
    public function resolve(?string $code = null): int
    {
        return ($code !== null ? $this->find($code) : null) ?? $this->default();
    }

    /** Return the first active locale that exists, falling back to the default. */
    public function current(): int
    {
        foreach ($this->active ?? [] as $code) {
            if ($id = $this->find($code)) {
                return $id;
            }
        }

        return $this->default();
    }

    /**
     * Get the default locale ID.
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

    /** Set the active locales for the request context. */
    public function set(array $codes): void
    {
        $this->active = $codes;
    }

    /** Get the active locales. */
    public function get(): ?array
    {
        return $this->active;
    }

    /** Clear the registry cache. */
    public function forget(): void
    {
        $this->locales = null;
        $this->defaultId = null;
        $this->active = null;
    }
}
