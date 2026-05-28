<?php

namespace Jurager\Eav\Registry;

use Illuminate\Support\Collection;
use Jurager\Eav\Exceptions\InvalidConfigurationException;
use Jurager\Eav\Support\EavModels;

/**
 * In-memory cache of locales with per-request active locale state.
 */
class LocaleRegistry
{
    /** @var Collection<int, string>|null  id → code */
    private ?Collection $locales = null;

    private ?int $defaultId = null;

    /** @var array<string>|null Active locales for the current request, set by middleware. */
    private ?array $active = null;

    /** @return Collection<int, string> */
    public function all(): Collection
    {
        return $this->locales ??= EavModels::query('locale')->pluck('code', 'id');
    }

    /** @return array<int> */
    public function ids(): array
    {
        return $this->all()->keys()->all();
    }

    public function has(int $id): bool
    {
        return $this->all()->has($id);
    }

    public function code(int $id): ?string
    {
        return $this->all()->get($id);
    }

    public function find(string $code): ?int
    {
        $id = $this->all()->search($code);

        return $id !== false ? $id : null;
    }

    public function resolve(?string $code = null): int
    {
        return ($code !== null ? $this->find($code) : null) ?? $this->default();
    }

    /**
     * Returns the first active locale that exists in the database, falling back to the default.
     * Active locales are set from the request context (e.g. Accept-Language middleware).
     */
    public function current(): int
    {
        foreach ($this->active ?? [] as $code) {
            $id = $this->find($code);

            if ($id !== null) {
                return $id;
            }
        }

        return $this->default();
    }

    /**
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

    /** @param array<string> $codes */
    public function set(array $codes): void
    {
        $this->active = $codes;
    }

    /** @return array<string>|null */
    public function get(): ?array
    {
        return $this->active;
    }

    public function forget(): void
    {
        $this->locales = null;
        $this->defaultId = null;
        $this->active = null;
    }
}
