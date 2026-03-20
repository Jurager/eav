<?php

namespace Jurager\Eav\Fields;

use Jurager\Eav\Fields\Concerns\HasFileStorage;

/**
 * Generic file path field with storage helper methods.
 */
class FileField extends Field
{
    use HasFileStorage;

    public function column(): string
    {
        return self::STORAGE_TEXT;
    }

    /**
     * File value validation is intentionally permissive.
     * Concrete upload flow should validate file type and size before saving the path.
     */
    protected function validate(mixed $value): bool
    {
        return true;
    }

    protected function normalize(mixed $value): string|array
    {
        return $this->processFileValue($value);
    }
}
