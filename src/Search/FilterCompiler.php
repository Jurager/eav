<?php

namespace Jurager\Eav\Search;

/**
 * Translates a JSON:API filter array into a Meilisearch filter expression.
 *
 * Field resolution is delegated: callers pass a `fn (string $key): ?string`
 * that returns the indexed field name (or null to drop unknown keys).
 *
 * Supported operators: eq, ne, gt, gte, lt, lte, in, nin, between, not_between,
 *                      exists, not_exists.
 *
 * Structural keys: `filter[or][]` / `filter[and][]` form grouped expressions.
 */
class FilterCompiler
{
    private const array OPERATORS = [
        'eq' => '=',
        'ne' => '!=',
        'gt' => '>',
        'gte' => '>=',
        'lt' => '<',
        'lte' => '<=',
    ];

    /**
     * @param  array<string, mixed>  $filter
     * @param  callable(string): ?string  $resolve  Maps filter key → Meilisearch field (null skips).
     */
    public function compile(array $filter, callable $resolve): ?string
    {
        $parts = $this->compileBlock($filter, $resolve);

        return $parts ? implode(' AND ', $parts) : null;
    }

    /**
     * Reads a scalar value from a JSON:API filter by key, unwrapping the operator form
     * so `filter[key]=v` and `filter[key][eq]=v` both yield `v`. Returns null when absent.
     *
     * @param  array<string, mixed>  $filter
     */
    public static function scalar(array $filter, string $key, string $operator = 'eq'): mixed
    {
        $value = $filter[$key] ?? null;

        return is_array($value) ? ($value[$operator] ?? null) : $value;
    }

    /**
     * @param  array<string, mixed>  $filter
     * @return string[]
     */
    private function compileBlock(array $filter, callable $resolve): array
    {
        $parts = [];

        foreach ($filter as $key => $value) {
            if (($key === 'or' || $key === 'and') && is_array($value)) {
                $glue = strtoupper($key);
                $group = $this->compileGroup($value, $resolve, $glue);

                if ($group !== null) {
                    $parts[] = "($group)";
                }

                continue;
            }

            $field = $resolve((string) $key);

            if ($field === null) {
                continue;
            }

            $expression = $this->compileField($field, $value);

            if ($expression !== null) {
                $parts[] = $expression;
            }
        }

        return $parts;
    }

    /**
     * Compiles an `or` / `and` group. Each element of the list is treated as an
     * independent sub-filter; its conditions are AND-ed together before joining
     * with $glue. Associative arrays are treated as a single sub-filter.
     *
     * @param  array<int|string, mixed>  $group
     */
    private function compileGroup(array $group, callable $resolve, string $glue): ?string
    {
        $entries = array_is_list($group) ? $group : [$group];
        $parts = [];

        foreach ($entries as $sub) {
            if (! is_array($sub)) {
                continue;
            }

            $conditions = $this->compileBlock($sub, $resolve);

            if (! $conditions) {
                continue;
            }

            $expr = implode(' AND ', $conditions);
            $parts[] = count($conditions) > 1 ? "($expr)" : $expr;
        }

        return $parts ? implode(" $glue ", $parts) : null;
    }

    private function compileField(string $field, mixed $value): ?string
    {
        if (! is_array($value)) {
            return sprintf('%s = %s', $field, $this->escape($value));
        }

        $parts = [];

        foreach ($value as $op => $opValue) {
            $part = match (true) {
                isset(self::OPERATORS[$op]) => $this->compileComparison($field, self::OPERATORS[$op], $opValue),
                $op === 'in' => $this->compileIn($field, $opValue, negate: false),
                $op === 'nin' => $this->compileIn($field, $opValue, negate: true),
                $op === 'between' => $this->compileRange($field, $opValue, negate: false),
                $op === 'not_between' => $this->compileRange($field, $opValue, negate: true),
                $op === 'exists' => $this->compileExists($field, $opValue, negate: false),
                $op === 'not_exists' => $this->compileExists($field, $opValue, negate: true),
                default => null,
            };

            if ($part !== null) {
                $parts[] = $part;
            }
        }

        return $parts ? implode(' AND ', $parts) : null;
    }

    /**
     * Meilisearch supports `>`, `>=`, `<`, `<=` for numeric values only. A non-numeric
     * operand (empty or malformed `gte=` from a range input) would make Meilisearch reject
     * the whole filter — so the condition is dropped instead.
     */
    private function compileComparison(string $field, string $operator, mixed $value): ?string
    {
        if (! is_numeric($value)) {
            return null;
        }

        return sprintf('%s %s %s', $field, $operator, $this->escape($value));
    }

    private function compileIn(string $field, mixed $value, bool $negate): string
    {
        $values = is_array($value) ? $value : explode(',', (string) $value);
        $values = array_map(fn ($v) => $this->escape($v), $values);
        $prefix = $negate ? 'NOT ' : '';

        return sprintf('%s%s IN [%s]', $prefix, $field, implode(', ', $values));
    }

    private function compileRange(string $field, mixed $value, bool $negate): ?string
    {
        $pair = is_array($value) ? array_values($value) : explode(',', (string) $value, 2);

        if (count($pair) !== 2) {
            return null;
        }

        [$min, $max] = $pair;

        if (! is_numeric($min) || ! is_numeric($max)) {
            return null;
        }

        $minE = $this->escape($min);
        $maxE = $this->escape($max);

        return $negate
            ? sprintf('(%s < %s OR %s > %s)', $field, $minE, $field, $maxE)
            : sprintf('(%s >= %s AND %s <= %s)', $field, $minE, $field, $maxE);
    }

    private function compileExists(string $field, mixed $value, bool $negate): string
    {
        $truthy = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? true;
        $present = $truthy xor $negate;

        return $present
            ? sprintf('%s EXISTS', $field)
            : sprintf('NOT %s EXISTS', $field);
    }

    private function escape(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_numeric($value)) {
            return (string) $value;
        }

        return '"'.addcslashes((string) $value, '"\\').'"';
    }
}
