<?php

declare(strict_types=1);

namespace Jurager\Eav\Schema;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Jurager\Eav\Eav;
use Jurager\Eav\Exceptions\FluentBuilderException;
use Jurager\Eav\Exceptions\SearchNotAvailableException;
use Jurager\Eav\Models\Attribute;
use Jurager\Eav\Models\AttributeEnum;
use Jurager\Eav\Models\AttributeGroup;
use Jurager\Eav\Models\AttributeType;
use Jurager\Eav\Registry\AttributeTypeRegistry;
use Jurager\Eav\Registry\LocaleRegistry;
use Jurager\Eav\Schema\Repositories\AttributeRepository;
use Jurager\Eav\Schema\Repositories\EnumRepository;
use Jurager\Eav\Schema\Repositories\GroupRepository;
use Jurager\Eav\Search\Indexer;

/**
 * Manage attributes, groups, and enum options.
 */
class SchemaManager
{
    public function __construct(
        private readonly AttributeRepository $attributes,
        private readonly GroupRepository $groups,
        private readonly EnumRepository $enums,
        private readonly AttributeTypeRegistry $types,
        private readonly LocaleRegistry $locales,
    ) {}

    /**
     * Create an attribute builder for a new or existing definition.
     */
    public function attribute(Attribute|string $attribute, string|array|null $entityTypes = null): AttributeBuilder
    {
        return new AttributeBuilder($this->attributes, $this->types, $this->locales, $attribute, $entityTypes);
    }

    /**
     * Start building a group: pass a code to create one, or an existing group to update it.
     */
    public function group(AttributeGroup|string $group): GroupBuilder
    {
        return new GroupBuilder($this->groups, $this->locales, $group);
    }

    /**
     * Create an enum builder for a new or existing option.
     */
    public function enum(AttributeEnum|Attribute $subject, ?string $code = null): EnumBuilder
    {
        return new EnumBuilder($this->enums, $this->locales, $subject, $code);
    }

    /**
     * Persist many builders of the same kind in a single batch — for imports, not one-off seeding.
     *
     * @param  list<AttributeBuilder>|list<EnumBuilder>  $builders
     */
    public function batch(array $builders, bool $fireEvents = true): Collection
    {
        if ($builders === []) {
            return collect();
        }

        $repository = match (true) {
            $builders[0] instanceof AttributeBuilder => $this->attributes,
            $builders[0] instanceof EnumBuilder => $this->enums,
            default => throw FluentBuilderException::unsupportedBatchBuilder($builders[0]::class),
        };

        return $repository->batch(array_map(static fn (AttributeBuilder|EnumBuilder $builder): array => $builder->build(), $builders), $fireEvents);
    }

    public function findAttribute(int $id): Attribute
    {
        return $this->attributes->find($id);
    }

    public function findGroup(int $id): AttributeGroup
    {
        return $this->groups->find($id);
    }

    public function findEnum(int $id): AttributeEnum
    {
        return $this->enums->find($id);
    }

    public function findType(int $id): AttributeType
    {
        return Eav::$attributeTypeModel::query()->findOrFail($id);
    }

    /** @return Builder<Attribute> */
    public function attributes(): Builder
    {
        return Eav::$attributeModel::query()->ordered();
    }

    /** @return Builder<AttributeGroup> */
    public function groups(): Builder
    {
        return Eav::$attributeGroupModel::query()->ordered();
    }

    /** @return Builder<AttributeEnum> */
    public function enums(Attribute $attribute): Builder
    {
        return $attribute->enums()->getQuery();
    }

    /** @return Builder<AttributeType> */
    public function types(): Builder
    {
        return Eav::$attributeTypeModel::query();
    }

    /**
     * Full-text search over attribute codes and translated labels.
     *
     * @throws SearchNotAvailableException When Laravel Scout is not configured on the attribute model.
     */
    public function search(string $query): mixed
    {
        $model = Eav::$attributeModel;

        if (! Indexer::searchable($model)) {
            throw SearchNotAvailableException::scoutNotInstalled();
        }

        return $model::search($query);
    }
}
