<?php

declare(strict_types=1);

namespace Jurager\Eav\Search;

use Jurager\Filterable\Support\FilterOperator;
use Jurager\Filterable\Support\ParsedFilters;

class MeilisearchFilterCompiler
{
    public function compile(ParsedFilters $parsed, callable $resolve): ?string
    {
        $parts = $this->compileBlock($parsed->filters, $resolve);

        foreach (['AND' => $parsed->andGroups, 'OR' => $parsed->orGroups] as $glue => $groups) {
            $group = $this->compileGroup($groups, $resolve, $glue);

            if ($group !== null) {
                $parts[] = "($group)";
            }
        }

        return ! empty($parts) ? implode(' AND ', $parts) : null;
    }

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

    private function compileGroup(array $group, callable $resolve, string $glue): ?string
    {
        $parts = [];

        foreach ($group as $sub) {
            $conditions = $this->compileBlock($sub, $resolve);

            if (empty($conditions)) {
                continue;
            }

            $expr = implode(' AND ', $conditions);
            $parts[] = count($conditions) > 1 ? "($expr)" : $expr;
        }

        return ! empty($parts) ? implode(" $glue ", $parts) : null;
    }

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

    private function compileField(string $field, mixed $value): ?string
    {
        if (! is_array($value)) {
            return sprintf('%s = %s', $field, $this->escape($value));
        }

        $parts = [];

        foreach ($value as $alias => $operand) {
            $part = $this->compileOperand($field, (string) $alias, $operand);

            if ($part !== null) {
                $parts[] = $part;
            }
        }

        return ! empty($parts) ? implode(' AND ', $parts) : null;
    }

    private function compileOperand(string $field, string $alias, mixed $operand): ?string
    {
        if ($alias === 'exists') {
            return $this->compileExists($field, $operand, negate: false);
        }

        if ($alias === 'not_exists') {
            return $this->compileExists($field, $operand, negate: true);
        }

        return match (FilterOperator::fromAlias($alias)) {
            FilterOperator::Eq         => $this->compileComparison($field, '=', $operand),
            FilterOperator::Ne         => $this->compileComparison($field, '!=', $operand),
            FilterOperator::Gt         => $this->compileComparison($field, '>', $operand),
            FilterOperator::Gte        => $this->compileComparison($field, '>=', $operand),
            FilterOperator::Lt         => $this->compileComparison($field, '<', $operand),
            FilterOperator::Lte        => $this->compileComparison($field, '<', $operand),
            FilterOperator::In         => $this->compileIn($field, $operand, negate: false),
            FilterOperator::Nin        => $this->compileIn($field, $operand, negate: true),
            FilterOperator::Between    => $this->compileRange($field, $operand, negate: false),
            FilterOperator::NotBetween => $this->compileRange($field, $operand, negate: true),
            default                    => null,
        };
    }

    private function compileComparison(string $field, string $operator, mixed $value): ?string
    {
        return is_numeric($value) ? sprintf('%s %s %s', $field, $operator, $this->escape($value)) : null;
    }

    private function compileIn(string $field, mixed $value, bool $negate): string
    {
        $values = is_array($value) ? $value : explode(',', (string) $value);
        $escaped = array_map(fn ($v) => $this->escape($v), $values);
        $prefix = $negate ? 'NOT ' : '';

        return sprintf('%s%s IN [%s]', $prefix, $field, implode(', ', $escaped));
    }

    private function compileRange(string $field, mixed $value, bool $negate): ?string
    {
        $pair = is_array($value) ? array_values($value) : explode(',', (string) $value, 2);

        if (count($pair) !== 2 || ! is_numeric($pair[0]) || ! is_numeric($pair[1])) {
            return null;
        }

        [$minE, $maxE] = [$this->escape($pair[0]), $this->escape($pair[1])];

        return $negate
            ? sprintf('(%s < %s OR %s > %s)', $field, $minE, $field, $maxE)
            : sprintf('(%s >= %s AND %s <= %s)', $field, $minE, $field, $maxE);
    }

    private function compileExists(string $field, mixed $value, bool $negate): string
    {
        $truthy = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? true;
        $present = $truthy xor $negate;

        return $present ? "$field EXISTS" : "NOT $field EXISTS";
    }

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
