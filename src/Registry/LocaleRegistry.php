<?php

declare(strict_types=1);

namespace Jurager\Eav\Registry;

use Illuminate\Support\Collection;
use Jurager\Eav\Eav;
use Jurager\Eav\Exceptions\InvalidConfigurationException;
use Jurager\Eav\Registry\Concerns\CachesResolvedItems;
use Jurager\Eav\Registry\Concerns\FollowsSchemaVersion;
use Jurager\Eav\Scopes\ActiveLocaleScope;

class LocaleRegistry
{
    use CachesResolvedItems;
    use FollowsSchemaVersion;

    /** @var Collection<int, string>|null id → code */
    private static ?Collection $locales = null;

    /** @var array<string>|null Active locales for the current request. */
    private ?array $active = null;

    /**
     * Get all cached locales.
     */
    public function all(): Collection
    {
        $this->dropStaleCaches();

        if (self::$locales === null) {
            $this->load();
        }

        return self::$locales;
    }

    /**
     * Get all locale IDs.
     */
    public function ids(): array
    {
        return $this->all()->keys()->toArray();
    }

    /**
     * Determine if the locale exists by ID.
     */
    public function has(int $id): bool
    {
        return $this->code($id) !== null;
    }

    /**
     * Get the locale code by ID.
     */
    public function code(int $id): ?string
    {
        $this->dropStaleCaches();

        if (self::$locales !== null && self::$locales->has($id)) {
            return self::$locales->get($id);
        }

        return $this->resolved('id', $id, fn () => Eav::$localeModel::query()
            ->withoutGlobalScope(ActiveLocaleScope::class)
            ->where('id', $id)
            ->value('code'));
    }

    /**
     * Find a locale ID by its code.
     */
    public function find(string $code): ?int
    {
        $this->dropStaleCaches();

        if (self::$locales !== null) {
            $id = self::$locales->search($code);

            if ($id !== false) {
                return $id;
            }
        }

        return $this->resolved('code', $code, fn () => Eav::$localeModel::query()
            ->withoutGlobalScope(ActiveLocaleScope::class)
            ->where('code', $code)
            ->value('id'));
    }

    /**
     * Resolve a locale ID by code or return the default.
     */
    public function resolve(?string $code = null): int
    {
        return ($code !== null ? $this->find($code) : null) ?? $this->default();
    }

    /**
     * Get the first active locale, falling back to the application locale or default.
     */
    public function current(): int
    {
        foreach ($this->active ?? [] as $code) {
            $id = $this->find($code);

            if ($id !== null) {
                return $id;
            }
        }

        return $this->find(app()->getLocale()) ?? $this->default();
    }

    /**
     * Codes of every locale.
     *
     * @return list<string>
     */
    public function codes(): array
    {
        return array_values($this->all()->all());
    }

    /**
     * Get the ID of the application locale (`app.locale`).
     */
    public function default(): int
    {
        $code = (string) config('app.locale', 'en');

        return $this->find($code) ?? throw InvalidConfigurationException::localeNotFound($code);
    }

    /**
     * Set the active locales for translations and attribute values.
     */
    public function set(array $codes): void
    {
        $this->active = $codes;
    }

    /**
     * Get the active locales.
     */
    public function get(): ?array
    {
        return $this->active;
    }

    /**
     * Drop the cached locales, in every process. The locales the request is narrowed to stay.
     */
    public function forget(): void
    {
        self::$locales = null;
        $this->forgetResolved();
        $this->schemaChanged();
    }

    /**
     * Clear cached data in this process.
     */
    public static function flush(): void
    {
        self::$locales = null;
        static::forgetSchemaVersion();
        static::flushResolved();
    }

    /**
     * Clear cached data when the schema changes.
     */
    private function dropStaleCaches(): void
    {
        if ($this->schemaMoved()) {
            self::$locales = null;
            $this->forgetResolved();
        }
    }

    /**
     * Reload cached records.
     */
    private function load(): void
    {
        self::$locales = Eav::$localeModel::query()
            ->withoutGlobalScope(ActiveLocaleScope::class)
            ->pluck('code', 'id');
        $this->forgetResolved();
    }
}
