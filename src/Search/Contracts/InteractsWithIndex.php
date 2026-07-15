<?php

namespace Jurager\Eav\Search\Contracts;
interface InteractsWithIndex
{
    /** @return array<string, string> External filter key => indexed searchable field. */
    public function indexed(): array;

    /**
     * Narrow a candidate identificator list by filter conditions that don't map to an indexed field.
     *
     * @param  (int|string)[]  $ids
     * @param  array<string, mixed>  $filter
     * @return (int|string)[]
     */
    public function narrow(array $ids, array $filter): array;
}
