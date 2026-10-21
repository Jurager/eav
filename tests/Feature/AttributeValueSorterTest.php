<?php

declare(strict_types=1);

namespace Jurager\Eav\Tests\Feature;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as BaseCollection;
use Jurager\Eav\Fields\FieldFactory;
use Jurager\Eav\Fields\Text;
use Jurager\Eav\Models\Attribute;
use Jurager\Eav\Models\AttributeGroup;
use Jurager\Eav\Models\EntityAttribute;
use Jurager\Eav\Support\AttributeValueSorter;

class AttributeValueSorterTest extends FeatureTestCase
{
    private function value(Attribute $attribute, int $id, ?int $order = null): EntityAttribute
    {
        return (new EntityAttribute(['id' => $id, 'attribute_id' => $attribute->id, 'value_integer' => $order]))
            ->setRawAttributes(['id' => $id, 'attribute_id' => $attribute->id, 'value_integer' => $order])
            ->setRelation('attribute', $attribute);
    }

    public function test_schema_order_and_equal_value_order_are_preserved(): void
    {
        $type = $this->createAttributeType('text');
        $group = AttributeGroup::create(['code' => 'group', 'sort' => 0]);
        $later = $this->createAttribute($type, ['code' => 'later', 'sort' => 5]);
        $earlier = $this->createAttribute($type, ['code' => 'earlier', 'sort' => 1]);
        $grouped = $this->createAttribute($type, ['code' => 'grouped', 'attribute_group_id' => $group->id, 'sort' => -1]);
        $values = new Collection([$this->value($grouped, 1), $this->value($later, 8), $this->value($earlier, 4), $this->value($earlier, 2)]);

        $sorted = app(AttributeValueSorter::class)->sort($values);

        $this->assertInstanceOf(Collection::class, $sorted);
        $this->assertSame([4, 2, 8, 1], $sorted->modelKeys());
        $this->assertSame([1, 8, 4, 2], $values->modelKeys());
    }

    public function test_field_order_is_computed_once_per_value_with_one_field_per_attribute(): void
    {
        SortableText::$orders = 0;
        SortableText::$instances = 0;
        app(FieldFactory::class)->register('ordered_text', SortableText::class);
        $attribute = $this->createAttribute($this->createAttributeType('ordered_text'), ['code' => 'ordered']);
        $values = new Collection(array_map(fn (int $id) => $this->value($attribute, $id, 16 - $id), range(1, 15)));

        $sorted = app(AttributeValueSorter::class)->sort($values);

        $this->assertSame(range(15, 1), $sorted->modelKeys());
        $this->assertSame(15, SortableText::$orders);
        $this->assertSame(1, SortableText::$instances);
    }

    public function test_attribute_id_breaks_equal_schema_keys(): void
    {
        $type = $this->createAttributeType('text');
        $first = $this->createAttribute($type, ['code' => 'first', 'sort' => 1]);
        $second = $this->createAttribute($type, ['code' => 'second', 'sort' => 1]);
        $values = new Collection([$this->value($second, 2), $this->value($first, 1)]);

        $this->assertSame([1, 2], app(AttributeValueSorter::class)->sort($values)->modelKeys());
    }

    public function test_empty_collections_keep_their_collection_type(): void
    {
        $sorter = app(AttributeValueSorter::class);

        $this->assertInstanceOf(Collection::class, $sorter->sort(new Collection));
        $this->assertSame([], $sorter->sort(new BaseCollection)->all());
    }
}

class SortableText extends Text
{
    public static int $orders = 0;

    public static int $instances = 0;

    public function __construct(...$args)
    {
        self::$instances++;
        parent::__construct(...$args);
    }

    public function sortOrder(EntityAttribute $value): int
    {
        self::$orders++;

        return $value->value_integer;
    }
}
