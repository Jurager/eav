<?php

declare(strict_types=1);

namespace Jurager\Eav\Registry;

use Illuminate\Support\Collection;
use Jurager\Eav\Eav;
use Jurager\Eav\Exceptions\InvalidConfigurationException;
use Jurager\Eav\Registry\Concerns\CachesResolvedItems;
use Jurager\Eav\Registry\Concerns\TracksTableChanges;
use Jurager\Eav\Scopes\ActiveLocaleScope;

class LocaleRegistry
{
    use CachesResolvedItems;
    use TracksTableChanges;

    /** @var Collection<int, string>|null id → code */
    private static ?Collection $locales = null;

    private static ?int $default = null;

    /** @var array<string>|null Active locales for the current request. */
    private ?array $active = null;

    /** Get all cached locales. */
    public function all(): Collection
    {
        if (self::$locales === null || $this->tableChanged(fn () => $this->stamp())) {
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
        return $this->code($id) !== null;
    }

    /** Get the locale code by ID, without loading the whole table for a single lookup. */
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

    /** Find a locale ID by its code, without loading the whole table for a single lookup. */
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

    /** Get the default locale ID. */
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
        self::$default = null;
        $this->forgetTableChange();
        $this->forgetResolved();
        $this->active = null;
    }

    /** Drop everything the process holds. */
    public static function flush(): void
    {
        self::$locales = null;
        self::$default = null;
        static::flushTableChanges();
        static::flushResolved();
    }

    /** Drop the whole-table and point-lookup caches if the table moved since either was read. */
    private function dropStaleCaches(): void
    {
        if ($this->tableChanged(fn () => $this->stamp())) {
            self::$locales = null;
            $this->forgetResolved();
        }
    }

    /** Read the table, dropping whatever was held before. */
    private function load(): void
    {
        $this->markTableFresh(fn () => $this->stamp());
        self::$locales = Eav::$localeModel::query()
            ->withoutGlobalScope(ActiveLocaleScope::class)
            ->pluck('code', 'id');
        $this->forgetResolved();
    }

    /** Get the state of the table, as far as a change is observable. */
    private function stamp(): string
    {
        $state = Eav::$localeModel::query()
            ->withoutGlobalScope(ActiveLocaleScope::class)
            ->toBase()
            ->reorder()
            ->selectRaw('count(*) as total, max(id) as last_id, max(updated_at) as changed_at')
            ->first();

        return implode(':', [$state->total ?? 0, $state->last_id ?? 0, $state->changed_at ?? '']);
    }
}
