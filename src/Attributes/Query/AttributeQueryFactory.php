<?php

declare(strict_types=1);

namespace Jurager\Eav\Attributes\Query;

use Illuminate\Database\Eloquent\Model;
use Jurager\Eav\Fields\FieldFactory;
use Jurager\Eav\Registry\AttributeRegistry;
use Jurager\Eav\Registry\EnumRegistry;

class AttributeQueryFactory
{
    public function __construct(
        private readonly AttributeRegistry $attributes,
        private readonly FieldFactory $fields,
        private readonly EnumRegistry $enums,
    ) {}

    /**
     * Matching by attribute value for one entity type.
     *
     * @param  class-string<Model>|null  $model  Entity class lookups return; the type's morph-map entry when null.
     */
    public function for(string $entityType, ?string $model = null): AttributeQueryBuilder
    {
        return new AttributeQueryBuilder($this->enums, $this->fields, $this->attributes, $entityType, $model);
    }
}
