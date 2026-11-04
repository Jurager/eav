<?php

declare(strict_types=1);

namespace Jurager\Eav\Attributes\Persistence;

/**
 * One `entity_attribute` row about to be written.
 *
 * @internal
 */
final readonly class ValueRow
{
    /**
     * @param  array<string, mixed>  $columns
     * @param  list<array{locale_id: int, value: mixed}>|null  $translations  Labels of a localizable value; null for any other.
     */
    public function __construct(
        public array $columns,
        public ?array $translations = null,
    ) {}

    /**
     * The same row, as an update of an existing one.
     */
    public function withId(int $id): self
    {
        return new self(['id' => $id, ...$this->columns], $this->translations);
    }
}
