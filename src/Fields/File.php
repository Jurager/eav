<?php

namespace Jurager\Eav\Fields;

use Jurager\Eav\Contracts\Attributable;

/**
 * Generic file field.
 *
 * Storage is intentionally minimal — values are validated permissively and
 * passed through normalization. URL resolution, existence checks and any
 * other infrastructure concerns are the responsibility of the consuming
 * application (e.g. via a subclass that overrides resolve()).
 */
class File extends Field
{
    public function column(): string
    {
        return self::STORAGE_TEXT;
    }

    /**
     * File value validation is intentionally permissive.
     * Concrete upload flow should validate file type and size before saving the value.
     */
    protected function validate(mixed $value, ?Attributable $entity = null): bool
    {
        return true;
    }

    protected function normalize(mixed $value): mixed
    {
        if ($value === null) {
            return [];
        }

        if (is_array($value)) {
            return array_values(array_filter($value, static fn ($v): bool => $v !== null && $v !== ''));
        }

        return $value;
    }
}
