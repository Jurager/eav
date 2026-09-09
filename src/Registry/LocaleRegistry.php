<?php

declare(strict_types=1);

namespace Jurager\Eav\Registry;

use Illuminate\Support\Collection;
use Jurager\Eav\Eav;
use Jurager\Eav\Exceptions\InvalidConfigurationException;
use Jurager\Eav\Scopes\ActiveLocaleScope;

class LocaleRegistry
{
    /** @var Collection<int, string>|null id → code */
    private static ?Collection $locales = null;

    private static ?string $stamp = null;

    private static ?int $default = null;

    private bool $checked = false;

    /** @var array<string>|null Active locales for the current request. */
    private ?array $active = null;

    /**
     * Get all cached locales.
     */
    public function all(): Collection
    {
        if (self::$locales === null || $this->changed()) {
            $this->load();
        }

        return self::$locales;
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
            $id = $this->find($code);

            if ($id !== null) {
                return $id;
            }
        }

        return $this->default();
    }

    /**
     * Get the default locale ID.
     *
     * @throws InvalidConfigurationException
     */
    public function default(): int
    {
        if (self::$default !== null) {
            return self::$default;
        }

        $code = config('app.locale', 'en');

        return self::$default = $this->find($code) ?? throw InvalidConfigurationException::localeNotFound($code);
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
        self::$locales = null;
        self::$stamp = null;
        self::$default = null;
        $this->checked = false;
        $this->active = null;
    }

    /** Drop everything the process holds. */
    public static function flush(): void
    {
        self::$locales = null;
        self::$stamp = null;
        self::$default = null;
    }

    /** Determine if the table changed since it was last read. Checked at most once per request. */
    private function changed(): bool
    {
        if ($this->checked) {
            return false;
        }

        $this->checked = true;

        return $this->stamp() !== self::$stamp;
    }

    /** Read the table, dropping whatever was held before. */
    private function load(): void
    {
        $this->checked = true;
        self::$stamp = $this->stamp();
        self::$locales = Eav::$localeModel::query()
            ->withoutGlobalScope(ActiveLocaleScope::class)
            ->pluck('code', 'id');
    }

    /** Get the state of the table, as far as a change is observable without an updated_at column. */
    private function stamp(): string
    {
        $state = Eav::$localeModel::query()
            ->withoutGlobalScope(ActiveLocaleScope::class)
            ->toBase()
            ->reorder()
            ->selectRaw('count(*) as total, max(id) as last_id')
            ->first();

        return implode(':', [$state->total ?? 0, $state->last_id ?? 0]);
    }
}
