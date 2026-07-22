<?php

declare(strict_types=1);

namespace Jurager\Eav\Search;

use Jurager\Filterable\Support\FilterOperator;
use Jurager\Filterable\Support\ParsedFilters;

class Compiler
{
    /** Compile parsed filters into a filter string. */
    public function compile(ParsedFilters $parsed, callable $resolve): ?string
    {
        $parts = $this->compileBlock($parsed->filters, $resolve);

        $groups = [
            'AND' => $parsed->andGroups,
            'OR'  => $parsed->orGroups,
        ];

        foreach ($groups as $glue => $conditions) {
            if (empty($conditions)) {
                continue;
            }

            $group = $this->compileGroup($conditions, $resolve, $glue);

            if ($group !== null) {
                $parts[] = "({$group})";
            }
        }

        return empty($parts) ? null : implode(' AND ', $parts);
    }

    /** Get unresolved filter rules. */
    public function unresolved(ParsedFilters $parsed, callable $resolve): array
    {
        $result = [];

        foreach ($parsed->filters as $key => $value) {
            if ($resolve((string) $key) === null) {
                $result[$key] = $value;
            }
        }

        if (! empty($parsed->orGroups) && ! $this->groupResolves($parsed->orGroups, $resolve)) {
            $result['or'] = $parsed->orGroups;
        }

        if (! empty($parsed->andGroups) && ! $this->groupResolves($parsed->andGroups, $resolve)) {
            $result['and'] = $parsed->andGroups;
        }

        return $result;
    }

    /** Compile a flat block of filter conditions. */
    private function compileBlock(array $filter, callable $resolve): array
    {
        $parts = [];

        foreach ($filter as $key => $value) {
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

    /** Compile nested group of conditions. */
    private function compileGroup(array $group, callable $resolve, string $glue): ?string
    {
        $parts = [];

        foreach ($group as $sub) {
            $conditions = $this->compileBlock($sub, $resolve);

            if (empty($conditions)) {
                continue;
            }

            $expr = implode(' AND ', $conditions);
            $parts[] = count($conditions) > 1 ? "({$expr})" : $expr;
        }

        return empty($parts) ? null : implode(" {$glue} ", $parts);
    }

    /** Check if all fields in a group can be resolved. */
    private function groupResolves(array $group, callable $resolve): bool
    {
        foreach ($group as $sub) {
            foreach (array_keys($sub) as $key) {
                if ($resolve((string) $key) === null) {
                    return false;
                }
            }
        }

        return true;
    }

    /** Compile single field expression. */
    private function compileField(string $field, mixed $value): ?string
    {
        if (! is_array($value)) {
            return sprintf('%s = %s', $field, $this->escape($value));
        }

        if (array_is_list($value)) {
            return $this->compileIn($field, $value, false);
        }

        $parts = [];

        foreach ($value as $alias => $operand) {
            $part = $this->compileOperand($field, (string) $alias, $operand);

            if ($part !== null) {
                $parts[] = $part;
            }
        }

        return empty($parts) ? null : implode(' AND ', $parts);
    }

    /** Compile operator-based expression. */
    private function compileOperand(string $field, string $alias, mixed $operand): ?string
    {
        return match (FilterOperator::fromAlias($alias)) {
            FilterOperator::Eq         => $this->compileEquality($field, '=', $operand),
            FilterOperator::Ne         => $this->compileEquality($field, '!=', $operand),
            FilterOperator::Gt         => $this->compileComparison($field, '>', $operand),
            FilterOperator::Gte        => $this->compileComparison($field, '>=', $operand),
            FilterOperator::Lt         => $this->compileComparison($field, '<', $operand),
            FilterOperator::Lte        => $this->compileComparison($field, '<=', $operand),
            FilterOperator::In         => $this->compileIn($field, $operand, false),
            FilterOperator::Nin        => $this->compileIn($field, $operand, true),
            FilterOperator::Between    => $this->compileRange($field, $operand, false),
            FilterOperator::NotBetween => $this->compileRange($field, $operand, true),
            FilterOperator::IsNull     => $this->compileExists($field, $operand, true),
            FilterOperator::IsNotNull  => $this->compileExists($field, $operand, false),
            default                    => null,
        };
    }

    /** Compile equality condition. */
    private function compileEquality(string $field, string $operator, mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        return sprintf('%s %s %s', $field, $operator, $this->escape($value));
    }

    /** Compile numeric comparison condition. */
    private function compileComparison(string $field, string $operator, mixed $value): ?string
    {
        if (! is_numeric($value)) {
            return null;
        }

        return sprintf('%s %s %s', $field, $operator, $this->escape($value));
    }

    /** Compile in and not in condition. */
    private function compileIn(string $field, mixed $value, bool $negate): string
    {
        $items = is_array($value) ? $value : explode(',', (string) $value);
        $escaped = [];

        foreach ($items as $item) {
            $escaped[] = $this->escape($item);
        }

        $prefix = $negate ? 'NOT ' : '';

        return sprintf('%s%s IN [%s]', $prefix, $field, implode(', ', $escaped));
    }

    /** Compile between and not between condition. */
    private function compileRange(string $field, mixed $value, bool $negate): ?string
    {
        $pair = is_array($value) ? array_values($value) : explode(',', (string) $value, 2);

        if (count($pair) !== 2 || ! is_numeric($pair[0]) || ! is_numeric($pair[1])) {
            return null;
        }

        $min = $this->escape($pair[0]);
        $max = $this->escape($pair[1]);

        if ($negate) {
            return sprintf('(%s < %s OR %s > %s)', $field, $min, $field, $max);
        }

        return sprintf('(%s >= %s AND %s <= %s)', $field, $min, $field, $max);
    }

    /** Compile exists and not exists condition. */
    private function compileExists(string $field, mixed $value, bool $negate): string
    {
        $truthy = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? true;

        return ($truthy !== $negate) ? "{$field} EXISTS" : "NOT {$field} EXISTS";
    }

    /** Escape value for query. */
    private function escape(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_numeric($value)) {
            return (string) $value;
        }

        return '"' . addcslashes((string) $value, '"\\') . '"';
    }
}