<?php

declare(strict_types=1);

namespace Jurager\Eav\Builders\Schema;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Jurager\Eav\Exceptions\FluentBuilderException;
use Jurager\Eav\Exceptions\SearchNotAvailableException;
use Jurager\Eav\Managers\Schema\AttributeSchema;
use Jurager\Eav\Managers\Schema\EnumSchema;
use Jurager\Eav\Managers\Schema\GroupSchema;
use Jurager\Eav\Managers\SchemaManager;
use Jurager\Eav\Models\Attribute;
use Jurager\Eav\Models\AttributeEnum;
use Jurager\Eav\Models\AttributeGroup;
use Jurager\Eav\Models\AttributeType;
use Jurager\Eav\Registry\AttributeTypeRegistry;
use Jurager\Eav\Registry\LocaleRegistry;

/** Creates fluent EAV schema builders, exposed via the Schema facade. */
class SchemaFactory
{
    public function __construct(
        private readonly GroupSchema $groupSchema,
        private readonly AttributeSchema $attributeSchema,
        private readonly EnumSchema $enumSchema,
        private readonly AttributeTypeRegistry $types,
        private readonly LocaleRegistry $locales,
        private readonly SchemaManager $manager,
    ) {
    }

    /**
     * Start building a group.
     *
     * Pass a code to create a new group, or an existing `AttributeGroup` to update it.
     */
    public function group(AttributeGroup|string $code): GroupBuilder
    {
        return new GroupBuilder($this->groupSchema, $this->locales, $code);
    }

    /** Start building an attribute. */
    public function attribute(Attribute|string $code, ?string $entityType = null): AttributeBuilder
    {
        return new AttributeBuilder($this->attributeSchema, $this->types, $this->locales, $code, $entityType);
    }

    /**
     * Start building an enum option.
     *
     * Pass the parent attribute and a code to create a new option, or an existing `AttributeEnum` to update it.
     */
    public function enum(AttributeEnum|Attribute $subject, ?string $code = null): EnumBuilder
    {
        return new EnumBuilder($this->enumSchema, $this->locales, $subject, $code);
    }

    /** Persist many builders of the same kind in a single batch — for imports, not one-off seeding. */
    public function batch(array $builders, bool $fireEvents = true): Collection
    {
        if (empty($builders)) {
            return collect();
        }

        return match (true) {
            $builders[0] instanceof AttributeBuilder => $this->attributeSchema->batch(
                array_map(fn (AttributeBuilder $builder) => $builder->build(), $builders),
                $fireEvents,
            ),
            $builders[0] instanceof EnumBuilder => $this->enumSchema->batch(
                array_map(fn (EnumBuilder $builder) => $builder->build(), $builders),
                $fireEvents,
            ),
            default => throw FluentBuilderException::unsupportedBatchBuilder($builders[0]::class),
        };
    }

    /** Find an attribute by ID. */
    public function findAttribute(int $id): Attribute
    {
        return $this->attributeSchema->find($id);
    }

    /** Find a group by ID. */
    public function findGroup(int $id): AttributeGroup
    {
        return $this->groupSchema->find($id);
    }

    /** Find an enum option by ID. */
    public function findEnum(int $id): AttributeEnum
    {
        return $this->enumSchema->find($id);
    }

    /** Find an attribute type by ID. */
    public function findType(int $id): AttributeType
    {
        return $this->manager->findType($id);
    }

    /** @return Builder<Attribute> */
    public function attributes(): Builder
    {
        return $this->manager->attributesQuery();
    }

    /** @return Builder<AttributeGroup> */
    public function groups(): Builder
    {
        return $this->manager->groupsQuery();
    }

    /** @return Builder<AttributeEnum> */
    public function enums(Attribute $attribute): Builder
    {
        return $this->manager->enumsQuery($attribute);
    }

    /** @return Builder<AttributeType> */
    public function types(): Builder
    {
        return $this->manager->typesQuery();
    }

    /**
     * Full-text search over attribute codes and translated labels.
     *
     * @throws SearchNotAvailableException When Laravel Scout is not configured on the attribute model.
     */
    public function search(string $query): mixed
    {
        return $this->manager->search($query);
    }
}
