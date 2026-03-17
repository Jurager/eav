<?php

namespace Jurager\Eav\Fields\Concerns;

use Illuminate\Support\Facades\Storage;

/**
 * Trait for file/image field storage operations.
 *
 * Provides methods to work with file paths, URLs, and existence checks.
 */
trait InteractsWithStorage
{
    /**
     * Get public URL(s) for stored file(s).
     *
     * @param  string  $disk  Storage disk name
     * @param  int|null  $localeId  Locale ID for localized fields
     * @return string|array|null URL string, array of URLs, or null
     */
    public function getUrl(string $disk = 'public', ?int $localeId = null): string|array|null
    {
        $value = $this->getValue($localeId);

        if ($value === null) {
            return null;
        }

        if (is_array($value)) {
            return array_map(fn (string $path): string => $this->toUrl($path, $disk), $value);
        }

        return $this->toUrl($value, $disk);
    }

    /**
     * Get first URL from multiple file field.
     *
     * @param  string  $disk  Storage disk name
     * @param  int|null  $localeId  Locale ID for localized fields
     * @return string|null First URL or null
     */
    public function getFirstUrl(string $disk = 'public', ?int $localeId = null): ?string
    {
        $urls = $this->getUrl($disk, $localeId);

        return is_array($urls) ? ($urls[0] ?? null) : $urls;
    }

    /**
     * Check if file exists in storage.
     *
     * @param  string  $disk  Storage disk name
     * @param  int|null  $localeId  Locale ID for localized fields
     * @return bool True if file exists
     */
    public function exists(string $disk = 'public', ?int $localeId = null): bool
    {
        $value = $this->getValue($localeId);

        if ($value === null) {
            return false;
        }

        if (is_array($value)) {
            return $value !== [] && $this->fileExists($value[0], $disk);
        }

        return $this->fileExists($value, $disk);
    }

    protected function toUrl(string $path, string $disk): string
    {
        if ($this->isAbsoluteUrl($path)) {
            return $path;
        }

        return Storage::disk($disk)->url($path) ?? $path;
    }

    protected function fileExists(string $path, string $disk): bool
    {
        if ($this->isAbsoluteUrl($path)) {
            return true;
        }

        return Storage::disk($disk)->exists($path);
    }

    protected function isAbsoluteUrl(string $path): bool
    {
        $parsed = parse_url($path);

        if ($parsed === false || ! isset($parsed['scheme'], $parsed['host'])) {
            return false;
        }

        return in_array(strtolower($parsed['scheme']), ['http', 'https'], true);
    }

    protected function processFileValue(mixed $value): string|array
    {
        if ($value === null) {
            return [];
        }

        if (is_array($value)) {
            return array_values(array_filter($value, static fn ($v): bool => is_string($v) && $v !== ''));
        }

        return (string) $value;
    }
}
