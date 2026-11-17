<?php

declare(strict_types=1);

namespace Jurager\Eav\Search\Filters;

use Jurager\Eav\Search\Index\IndexField;

/**
 * Conditions that must all hold, or any of which may.
 */
final readonly class Group
{
    /**
     * @param  list<Condition|Group>  $members
     * @param  bool  $any  Whether one member holding is enough, instead of all of them.
     * @param  IndexField|null  $field  The field every member is about, when the group came from one filter key.
     */
    public function __construct(
        public array $members = [],
        public bool $any = false,
        public ?IndexField $field = null,
    ) {}

    public function isEmpty(): bool
    {
        return $this->members === [];
    }

    /**
     * Whether a member at the top of the group is about the field.
     */
    public function constrains(IndexField $field): bool
    {
        return array_any($this->members, static fn (Condition|Group $member): bool => $member->field === $field);
    }

    /**
     * The group without its top members about the given fields.
     *
     * @param  list<string>  $paths
     */
    public function except(array $paths): self
    {
        return new self(
            array_values(array_filter($this->members, static fn (Condition|Group $member): bool => ! in_array($member->field?->path, $paths, true))),
            $this->any,
            $this->field,
        );
    }
}
