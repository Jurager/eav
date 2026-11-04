<?php

declare(strict_types=1);

namespace Jurager\Eav\Facades;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Facade;
use Jurager\Eav\Models\Attribute;
use Jurager\Eav\Models\AttributeEnum;
use Jurager\Eav\Models\AttributeGroup;
use Jurager\Eav\Models\AttributeType;
use Jurager\Eav\Schema\AttributeBuilder;
use Jurager\Eav\Schema\EnumBuilder;
use Jurager\Eav\Schema\GroupBuilder;
use Jurager\Eav\Schema\SchemaManager;

/**
 * Manage attribute definitions, groups, and enum options.
 *
 * @method static GroupBuilder group(AttributeGroup|string $code)
 * @method static AttributeBuilder attribute(Attribute|string $code, string|array|null $entityTypes = null)
 * @method static EnumBuilder enum(AttributeEnum|Attribute $subject, ?string $code = null)
 * @method static Collection batch(array $builders, bool $fireEvents = true)
 * @method static Attribute findAttribute(int $id)
 * @method static AttributeGroup findGroup(int $id)
 * @method static AttributeEnum findEnum(int $id)
 * @method static AttributeType findType(int $id)
 * @method static Builder<Attribute> attributes()
 * @method static Builder<AttributeGroup> groups()
 * @method static Builder<AttributeEnum> enums(Attribute $attribute)
 * @method static Builder<AttributeType> types()
 * @method static mixed search(string $query)
 *
 * @see SchemaManager
 */
class Schema extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return SchemaManager::class;
    }
}
