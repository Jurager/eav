<?php

declare(strict_types=1);

namespace Jurager\Eav\Facades;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Facade;
use Jurager\Eav\Builders\Schema\AttributeBuilder;
use Jurager\Eav\Builders\Schema\EnumBuilder;
use Jurager\Eav\Builders\Schema\GroupBuilder;
use Jurager\Eav\Builders\Schema\SchemaFactory;
use Jurager\Eav\Models\Attribute;
use Jurager\Eav\Models\AttributeEnum;
use Jurager\Eav\Models\AttributeGroup;
use Jurager\Eav\Models\AttributeType;

/**
 * Fluent builder for EAV schema — attribute groups, attributes, and enum options.
 *
 * Pass a code to build a new record, or an existing model to build an update:
 *
 *     Schema::attribute('color', 'product')->type('select')->create();
 *     Schema::attribute($attribute)->searchable()->update();
 *
 * Any column not covered by a named method — including ones added by a
 * consumer's own model subclass — is set dynamically (`->measurement_id($id)`)
 * or filled in bulk from an already-validated array (`->fill($data)`).
 *
 * For bulk imports, collect builders of the same kind without persisting them and flush
 * them together — attributes and enum options both go through the same batch():
 *
 *     Schema::batch([
 *         Schema::attribute('color', 'product')->type('select'),
 *         Schema::attribute('weight', 'product')->type('number'),
 *     ], fireEvents: false);
 *
 *     Schema::batch([
 *         Schema::enum($colorAttribute, 'red')->label('Red', 'en'),
 *         Schema::enum($colorAttribute, 'blue')->label('Blue', 'en'),
 *     ], fireEvents: false);
 *
 * @method static GroupBuilder group(AttributeGroup|string $code)
 * @method static AttributeBuilder attribute(Attribute|string $code, ?string $entityType = null)
 * @method static EnumBuilder enum(AttributeEnum|Attribute $subject, ?string $code = null)
 * @method static Collection batch(array $builders, bool $fireEvents = true)
 * @method static Attribute findAttribute(int $id)
 * @method static AttributeGroup findGroup(int $id)
 * @method static AttributeEnum findEnum(int $id)
 * @method static AttributeType findType(int $id)
 * @method static Builder attributes()
 * @method static Builder groups()
 * @method static Builder enums(Attribute $attribute)
 * @method static Builder types()
 * @method static mixed search(string $query)
 *
 * @see SchemaFactory
 */
class Schema extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return SchemaFactory::class;
    }
}
