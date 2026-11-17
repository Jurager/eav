<?php

declare(strict_types=1);

namespace Jurager\Eav\Search;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;

/**
 * One search hit: the indexed document, how well it matched, and the model it stands for.
 */
class Hit
{
    /**
     * The model the hit stands for; null when it is gone from the database or filtered out on hydration.
     */
    public ?Model $model = null;

    /**
     * @param  array<string, mixed>  $document  The document as indexed.
     * @param  array<string, mixed>  $formatted  The document with matches highlighted, when highlighting was asked for.
     */
    public function __construct(
        public readonly int|string $key,
        public readonly string $entityType,
        public readonly array $document,
        public readonly array $formatted = [],
        public readonly ?float $score = null,
        private readonly ?Builder $search = null,
    ) {}

    /**
     * Value of an indexed field, by the name filters refer to it.
     */
    public function get(string $name, mixed $default = null): mixed
    {
        return Arr::get($this->document, $this->path($name), $default);
    }

    /**
     * Value of a field with the matched words highlighted, falling back to the plain value.
     */
    public function highlight(string $name): mixed
    {
        return Arr::get($this->formatted, $this->path($name)) ?? $this->get($name);
    }

    /**
     * The document path of a field named as in the search the hit came from.
     */
    private function path(string $name): string
    {
        $field = $this->search?->index->find($name);

        return $field !== null ? $this->search->path($field) : $name;
    }
}
