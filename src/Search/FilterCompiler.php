<?php

namespace Jurager\Eav\Search;

/**
 * Translates a JSON:API filter array into a Meilisearch filter expression.
 *
 * Field resolution is delegated: callers pass a callable `fn (string $key): ?string`
 * that returns the indexed field name for a filter key (or null to drop unknown keys).
 * This keeps the compiler agnostic of EAV semantics, model fields, and remapping.
 *
 * Supported operators per field:
 *   eq, ne, gt, gte, lt, lte, in, nin, between, not_between, exists, not_exists.
 *
 * Structural keys: `filter[or]` / `filter[and]` form grouped expressions.
 *
 * @phpstan-type FilterScalar string|int|float|bool|null
 */
class FilterCompiler
{
    private const array OPERATORS = [
        'eq'  => '=',
        'ne'  => '!=',
        'gt'  => '>',
        'gte' => '>=',
        'lt'  => '<',
        'lte' => '<=',
    ];

    /**
     * @param  array<string, mixed>  $filter
     * @param  callable(string): ?string  $resolve  Maps filter key → Meilisearch field name (null skips).
     */
    public function compile(array $filter, callable $resolve): ?string
    {
        $parts = $this->compileBlock($filter, $resolve);

        return $parts ? implode(' AND ', $parts) : null;
    }

    /**
     * @param  array<string, mixed>  $filter
     * @return string[]
     */
    private function compileBlock(array $filter, callable $resolve): array
    {
        $parts = [];

        foreach ($filter as $key => $value) {
            if ($key === 'or' && is_array($value)) {
                if ($p = $this->compileGroup($value, $resolve, 'OR')) {
                    $parts[] = "($p)";
                }

                continue;
            }

            if ($key === 'and' && is_array($value)) {
                if ($p = $this->compileGroup($value, $resolve, 'AND')) {
                    $parts[] = "($p)";
                }

                continue;
            }

            $field = $resolve((string) $key);

            if ($field === null) {
                continue;
            }

            if ($p = $this->compileField($field, $value)) {
                $parts[] = $p;
            }
        }

        return $parts;
    }

    /**
     * @param  array<int|string, mixed>  $group
     */
    private function compileGroup(array $group, callable $resolve, string $glue): ?string
    {
        if (array_is_list($group)) {
            $groupParts = [];

            foreach ($group as $sub) {
                if (! is_array($sub)) {
                    continue;
                }

                $parts = $this->compileBlock($sub, $resolve);

                if ($parts) {
                    $groupParts[] = '('.implode(' AND ', $parts).')';
                }
            }

            return $groupParts ? implode(" $glue ", $groupParts) : null;
        }

        $parts = $this->compileBlock($group, $resolve);

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
                isset(self::OPERATORS[$op]) => sprintf('%s %s %s', $field, self::OPERATORS[$op], $this->escape($opValue)),
                $op === 'in'                => $this->compileIn($field, $opValue, negate: false),
                $op === 'nin'               => $this->compileIn($field, $opValue, negate: true),
                $op === 'between'           => $this->compileRange($field, $opValue, negate: false),
                $op === 'not_between'       => $this->compileRange($field, $opValue, negate: true),
                $op === 'exists'            => $this->compileExists($field, $opValue, negate: false),
                $op === 'not_exists'        => $this->compileExists($field, $opValue, negate: true),
                default                     => null,
            };

            if ($part !== null) {
                $parts[] = $part;
            }
        }

        return $parts ? implode(' AND ', $parts) : null;
    }

    private function compileIn(string $field, mixed $value, bool $negate): string
    {
        $values = is_string($value) ? explode(',', $value) : (array) $value;
        $values = array_map(fn ($v) => $this->escape($v), $values);
        $prefix = $negate ? 'NOT ' : '';

        return sprintf('%s%s IN [%s]', $prefix, $field, implode(', ', $values));
    }

    private function compileRange(string $field, mixed $value, bool $negate): ?string
    {
        $pair = is_string($value) ? explode(',', $value, 2) : array_values((array) $value);

        if (count($pair) !== 2) {
            return null;
        }

        [$min, $max] = $pair;
        $minE        = $this->escape($min);
        $maxE        = $this->escape($max);

        return $negate
            ? sprintf('(%s < %s OR %s > %s)', $field, $minE, $field, $maxE)
            : sprintf('%s >= %s AND %s <= %s', $field, $minE, $field, $maxE);
    }

    private function compileExists(string $field, mixed $value, bool $negate): string
    {
        $truthy = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? true;
        $exists = $negate ? ! $truthy : $truthy;

        return $exists ? sprintf('%s EXISTS', $field) : sprintf('NOT %s EXISTS', $field);
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
