<?php

namespace Jurager\Eav\Fields;

use Jurager\Eav\Fields\Concerns\InteractsWithStorage;

/**
 * Generic file path field with storage helper methods.
 */
class FileField extends Field
{
    use InteractsWithStorage;

    public function column(): string
    {
        return self::STORAGE_TEXT;
    }

    /**
     * File value validation is intentionally permissive here.
     * Concrete upload flow should validate file type and size before saving path.
     */
    protected function validateValue(mixed $value): bool
    {
        return true;
    }

    protected function processValue(mixed $value): string|array
    {
        return $this->processFileValue($value);
    }
}
