<?php

declare(strict_types=1);

namespace Jurager\Eav\Search\Filters;

use Jurager\Eav\Enums\Comparison;
use Jurager\Eav\Exceptions\InvalidSearchQueryException;
use Jurager\Eav\Search\Index\Index;
use Jurager\Eav\Search\Index\IndexField;
use Jurager\Filterable\Support\FilterOperator;
use Jurager\Filterable\Support\ParsedFilters;

/**
 * Turn request filters into checked conditions, the same for every engine.
 *
 * Only fields the index declares filterable may appear, operators must suit the field's type, and every
 * value is cast to it — a condition is never dropped or passed through unchecked.
 */
class Normalizer
{
    public function __construct(private readonly Index $index) {}

    /**
     * @throws InvalidSearchQueryException
     */
    public function normalize(ParsedFilters $parsed): Group
    {
        $members = $this->block($parsed->filters);

        foreach ([[$parsed->andGroups, false], [$parsed->orGroups, true]] as [$blocks, $any]) {
            $groups = [];

            foreach ($blocks as $conditions) {
                if (($block = $this->block($conditions)) !== []) {
                    $groups[] = count($block) > 1 ? new Group($block) : $block[0];
                }
            }

            if ($groups !== []) {
                $members[] = new Group($groups, $any);
            }
        }

        return new Group($members);
    }

    /**
     * @param  array<array-key, mixed>  $conditions
     * @return list<Condition|Group>
     */
    private function block(array $conditions): array
    {
        $members = [];

        foreach ($conditions as $name => $value) {
            $name = (string) $name;
            $field = $this->index->find($name) ?? throw InvalidSearchQueryException::unknownField($name);

            if (! $field->filterable) {
                throw InvalidSearchQueryException::notFilterable($name);
            }

            $members[] = $this->field($name, $field, $value);
        }

        return $members;
    }

    /**
     * Everything one filter key asks of its field.
     */
    private function field(string $name, IndexField $field, mixed $value): Condition|Group
    {
        if (! is_array($value)) {
            return $this->condition($name, $field, Comparison::Equal, $value);
        }

        if (array_is_list($value)) {
            return $this->condition($name, $field, Comparison::In, $value);
        }

        $conditions = [];

        foreach ($value as $alias => $operand) {
            $operator = FilterOperator::fromAlias((string) $alias);
            $comparison = $operator === null ? null : Comparison::tryFrom($operator->value);

            $conditions[] = $this->condition(
                $name,
                $field,
                $comparison ?? throw InvalidSearchQueryException::unsupportedOperator($name, (string) $alias),
                $operand,
            );
        }

        return count($conditions) > 1 ? new Group($conditions, field: $field) : $conditions[0];
    }

    private function condition(string $name, IndexField $field, Comparison $comparison, mixed $value): Condition|Group
    {
        if ($comparison->isOrdering() && ! $field->type->isNumeric()) {
            throw InvalidSearchQueryException::unsupportedOperator($name, $comparison->value);
        }

        $cast = fn (mixed $item): string|int|float|bool => $field->type->cast($item)
            ?? throw InvalidSearchQueryException::invalidValue($name, $comparison->value);

        switch ($comparison) {
            case Comparison::In:
            case Comparison::NotIn:
                $value = array_map($cast, is_array($value) ? array_values($value) : explode(',', (string) $value));
                break;

            case Comparison::Between:
            case Comparison::NotBetween:
                $pair = is_array($value) ? array_values($value) : explode(',', (string) $value, 2);

                if (count($pair) !== 2) {
                    throw InvalidSearchQueryException::invalidValue($name, $comparison->value);
                }

                $value = array_map($cast, $pair);
                break;

            case Comparison::Exists:
            case Comparison::Missing:
                // `null=false` asks for the opposite: a field that holds a value.
                if ((filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? true) === false) {
                    $comparison = $comparison === Comparison::Exists ? Comparison::Missing : Comparison::Exists;
                }

                $value = null;
                break;

            case Comparison::Contains:
                $needles = array_map(function (mixed $item) use ($name): string {
                    if (! is_scalar($item) || ($needle = trim((string) $item, '%')) === '') {
                        throw InvalidSearchQueryException::invalidValue($name, Comparison::Contains->value);
                    }

                    return $needle;
                }, (array) $value);

                if (count($needles) > 1) {
                    return new Group(
                        array_map(static fn (string $needle): Condition => new Condition($field, Comparison::Contains, $needle, $name), $needles),
                        any: true,
                        field: $field,
                    );
                }

                $value = $needles[0] ?? throw InvalidSearchQueryException::invalidValue($name, $comparison->value);
                break;

            default:
                $value = $cast($value);
        }

        return new Condition($field, $comparison, $value, $name);
    }
}
