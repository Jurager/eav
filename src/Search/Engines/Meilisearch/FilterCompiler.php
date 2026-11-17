<?php

declare(strict_types=1);

namespace Jurager\Eav\Search\Engines\Meilisearch;

use Illuminate\Contracts\Config\Repository;
use Jurager\Eav\Exceptions\InvalidSearchQueryException;
use Jurager\Eav\Search\Index\Index;
use Jurager\Eav\Search\Index\IndexField;
use Jurager\Filterable\Support\FilterOperator;
use Jurager\Filterable\Support\ParsedFilters;

/**
 * Compile parsed filters into a Meilisearch filter expression.
 *
 * Only fields the index declares filterable may appear, and every value is cast to the field's type —
 * a condition is never dropped or passed through unchecked.
 */
class FilterCompiler
{
    public function __construct(private readonly Repository $config) {}

    /**
     * @param  string|null  $locale  Locale whose slot of a localized field is compared.
     * @param  list<string>  $except  Paths of fields whose top-level conditions are left out (disjunctive facets).
     *
     * @throws InvalidSearchQueryException
     */
    public function compile(ParsedFilters $parsed, Index $index, ?string $locale = null, array $except = []): ?string
    {
        $parts = $this->block($parsed->filters, $index, $locale, $except);

        foreach (['AND' => $parsed->andGroups, 'OR' => $parsed->orGroups] as $glue => $groups) {
            $group = [];

            foreach ($groups as $conditions) {
                $compiled = $this->block($conditions, $index, $locale);

                if ($compiled !== []) {
                    $group[] = count($compiled) > 1 ? '('.implode(' AND ', $compiled).')' : $compiled[0];
                }
            }

            if ($group !== []) {
                $parts[] = '('.implode(" {$glue} ", $group).')';
            }
        }

        return $parts === [] ? null : implode(' AND ', $parts);
    }

    /**
     * @param  array<array-key, mixed>  $conditions
     * @param  list<string>  $except
     * @return list<string>
     */
    private function block(array $conditions, Index $index, ?string $locale, array $except = []): array
    {
        $parts = [];

        foreach ($conditions as $name => $value) {
            $name = (string) $name;
            $field = $index->find($name) ?? throw InvalidSearchQueryException::unknownField($name);

            if (! $field->filterable) {
                throw InvalidSearchQueryException::notFilterable($name);
            }

            if (in_array($field->path, $except, true)) {
                continue;
            }

            $parts[] = $this->field($name, $field, $field->pathFor($locale), $value);
        }

        return $parts;
    }

    private function field(string $name, IndexField $field, string $path, mixed $value): string
    {
        if (! is_array($value)) {
            return $this->equality($name, $field, $path, '=', $value);
        }

        if (array_is_list($value)) {
            return $this->in($name, $field, $path, $value, false);
        }

        $parts = [];

        foreach ($value as $alias => $operand) {
            $parts[] = $this->operand($name, $field, $path, (string) $alias, $operand);
        }

        return count($parts) > 1 ? '('.implode(' AND ', $parts).')' : $parts[0];
    }

    private function operand(string $name, IndexField $field, string $path, string $alias, mixed $operand): string
    {
        return match (FilterOperator::fromAlias($alias)) {
            FilterOperator::Eq => $this->equality($name, $field, $path, '=', $operand),
            FilterOperator::Ne => $this->equality($name, $field, $path, '!=', $operand),
            FilterOperator::Gt => $this->comparison($name, $field, $path, '>', $operand),
            FilterOperator::Gte => $this->comparison($name, $field, $path, '>=', $operand),
            FilterOperator::Lt => $this->comparison($name, $field, $path, '<', $operand),
            FilterOperator::Lte => $this->comparison($name, $field, $path, '<=', $operand),
            FilterOperator::In => $this->in($name, $field, $path, $operand, false),
            FilterOperator::Nin => $this->in($name, $field, $path, $operand, true),
            FilterOperator::Between => $this->range($name, $field, $path, $operand, false),
            FilterOperator::NotBetween => $this->range($name, $field, $path, $operand, true),
            FilterOperator::IsNull => $this->exists($path, $operand, true),
            FilterOperator::IsNotNull => $this->exists($path, $operand, false),
            FilterOperator::Like => $this->contains($name, $path, $operand),
            default => throw InvalidSearchQueryException::unsupportedOperator($name, $alias),
        };
    }

    private function equality(string $name, IndexField $field, string $path, string $operator, mixed $value): string
    {
        return "{$path} {$operator} ".$this->literal($name, $field, $value, $operator);
    }

    private function comparison(string $name, IndexField $field, string $path, string $operator, mixed $value): string
    {
        if (! $field->type->isNumeric()) {
            throw InvalidSearchQueryException::unsupportedOperator($name, $operator);
        }

        return "{$path} {$operator} ".$this->literal($name, $field, $value, $operator);
    }

    private function in(string $name, IndexField $field, string $path, mixed $value, bool $negate): string
    {
        $items = is_array($value) ? array_values($value) : explode(',', (string) $value);
        $literals = array_map(fn (mixed $item): string => $this->literal($name, $field, $item, 'in'), $items);

        return ($negate ? 'NOT ' : '')."{$path} IN [".implode(', ', $literals).']';
    }

    private function range(string $name, IndexField $field, string $path, mixed $value, bool $negate): string
    {
        $pair = is_array($value) ? array_values($value) : explode(',', (string) $value, 2);

        if (! $field->type->isNumeric()) {
            throw InvalidSearchQueryException::unsupportedOperator($name, 'between');
        }

        if (count($pair) !== 2) {
            throw InvalidSearchQueryException::invalidValue($name, 'between');
        }

        $min = $this->literal($name, $field, $pair[0], 'between');
        $max = $this->literal($name, $field, $pair[1], 'between');

        return $negate ? "({$path} < {$min} OR {$path} > {$max})" : "({$path} >= {$min} AND {$path} <= {$max})";
    }

    private function exists(string $path, mixed $value, bool $negate): string
    {
        $truthy = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? true;

        return $truthy !== $negate ? "{$path} EXISTS" : "{$path} NOT EXISTS";
    }

    /**
     * Substring match — an experimental engine feature, off unless enabled in the configuration.
     */
    private function contains(string $name, string $path, mixed $value): string
    {
        if (! $this->config->get('eav.search.engines.meilisearch.contains', false)) {
            throw InvalidSearchQueryException::unsupportedOperator($name, 'like');
        }

        $parts = [];

        foreach ((array) $value as $item) {
            if (! is_scalar($item) || ($needle = trim((string) $item, '%')) === '') {
                throw InvalidSearchQueryException::invalidValue($name, 'like');
            }

            $parts[] = "{$path} CONTAINS ".self::quote($needle);
        }

        return count($parts) > 1 ? '('.implode(' OR ', $parts).')' : $parts[0];
    }

    /**
     * Render a value as the field's type, quoted and escaped where it is a string.
     */
    private function literal(string $name, IndexField $field, mixed $value, string $operator): string
    {
        $cast = $field->type->cast($value) ?? throw InvalidSearchQueryException::invalidValue($name, $operator);

        return match (true) {
            is_bool($cast) => $cast ? 'true' : 'false',
            is_string($cast) => self::quote($cast),
            default => (string) $cast,
        };
    }

    private static function quote(string $value): string
    {
        return '"'.addcslashes($value, '"\\').'"';
    }
}
