<?php

declare(strict_types=1);

namespace Jurager\Eav\Fields;

use Jurager\Eav\Contracts\Attributable;

class File extends Field
{
    /** Get the storage column name. */
    public function column(): string
    {
        return self::STORAGE_TEXT;
    }

    /** Validate the field value (permissive by design). */
    protected function validate(mixed $value, ?Attributable $entity = null): bool
    {
        return true;
    }

    /** Normalize the field value. */
    protected function normalize(mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        if (is_array($value)) {
            return collect($value)
                ->filter(static fn ($v): bool => $v !== null && $v !== '')
                ->values()
                ->all();
        }

        return $value;
    }
}
