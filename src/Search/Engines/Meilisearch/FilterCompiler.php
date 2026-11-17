<?php

declare(strict_types=1);

namespace Jurager\Eav\Search\Engines\Meilisearch;

use Illuminate\Contracts\Config\Repository;
use Jurager\Eav\Enums\Comparison;
use Jurager\Eav\Exceptions\InvalidSearchQueryException;
use Jurager\Eav\Search\Filters\Condition;
use Jurager\Eav\Search\Filters\Group;

/**
 * Write checked search conditions as a Meilisearch filter expression.
 */
class FilterCompiler
{
    public function __construct(private readonly Repository $config) {}

    /**
     * @param  string|null  $locale  Locale whose slot of a localized field is compared.
     *
     * @throws InvalidSearchQueryException When a condition needs a feature that is turned off.
     */
    public function compile(Group $group, ?string $locale = null): ?string
    {
        $parts = array_map(fn (Condition|Group $member): string => $this->member($member, $locale), $group->members);

        return $parts === [] ? null : implode($group->any ? ' OR ' : ' AND ', $parts);
    }

    private function member(Condition|Group $member, ?string $locale): string
    {
        if ($member instanceof Condition) {
            return $this->condition($member, $member->field->pathFor($locale));
        }

        $expression = (string) $this->compile($member, $locale);

        return count($member->members) > 1 ? "({$expression})" : $expression;
    }

    private function condition(Condition $condition, string $path): string
    {
        $value = $condition->value;

        return match ($condition->comparison) {
            Comparison::Equal => "{$path} = ".self::literal($value),
            Comparison::NotEqual => "{$path} != ".self::literal($value),
            Comparison::Greater => "{$path} > ".self::literal($value),
            Comparison::GreaterOrEqual => "{$path} >= ".self::literal($value),
            Comparison::Less => "{$path} < ".self::literal($value),
            Comparison::LessOrEqual => "{$path} <= ".self::literal($value),
            Comparison::In => "{$path} IN [".implode(', ', array_map(self::literal(...), $value)).']',
            Comparison::NotIn => "NOT {$path} IN [".implode(', ', array_map(self::literal(...), $value)).']',
            Comparison::Between => "({$path} >= ".self::literal($value[0])." AND {$path} <= ".self::literal($value[1]).')',
            Comparison::NotBetween => "({$path} < ".self::literal($value[0])." OR {$path} > ".self::literal($value[1]).')',
            Comparison::Exists => "{$path} EXISTS",
            Comparison::Missing => "{$path} NOT EXISTS",
            Comparison::Contains => $this->contains($condition, $path),
        };
    }

    /**
     * Substring match — an experimental Meilisearch feature, off unless enabled in the configuration.
     */
    private function contains(Condition $condition, string $path): string
    {
        if (! $this->config->get('eav.search.engines.meilisearch.contains', false)) {
            throw InvalidSearchQueryException::unsupportedOperator($condition->name, $condition->comparison->value);
        }

        return "{$path} CONTAINS ".self::literal($condition->value);
    }

    private static function literal(string|int|float|bool $value): string
    {
        return match (true) {
            is_bool($value) => $value ? 'true' : 'false',
            is_string($value) => '"'.addcslashes($value, '"\\').'"',
            default => (string) $value,
        };
    }
}
