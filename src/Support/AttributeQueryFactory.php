<?php

declare(strict_types=1);

namespace Jurager\Eav\Support;

use Jurager\Eav\Fields\Field;
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

    /** Build filters without hydrating the entity type's entire schema. */
    public function for(string $entityType): AttributeQueryBuilder
    {
        return new AttributeQueryBuilder(
            $this->enums,
            function (string $code) use ($entityType): ?Field {
                $attribute = $this->attributes->forCodes($entityType, [$code])->first();

                return $attribute !== null ? $this->fields->make($attribute) : null;
            },
            static fn (string $code): string => $entityType,
        );
    }
}
