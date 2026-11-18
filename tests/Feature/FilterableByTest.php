<?php

declare(strict_types=1);

namespace Jurager\Eav\Tests\Feature;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Jurager\Eav\Models\Attribute;
use Jurager\Eav\Tests\Fixtures\FilterableProduct;

class FilterableByTest extends FeatureTestCase
{
    private Attribute $color;

    private Attribute $size;

    private FilterableProduct $red;

    private FilterableProduct $blue;

    protected function setUp(): void
    {
        parent::setUp();

        Relation::morphMap(['product' => FilterableProduct::class]);

        $type = $this->createAttributeType('text');
        $this->color = $this->createAttribute($type, ['code' => 'color']);
        $this->size = $this->createAttribute($type, ['code' => 'size']);

        $this->red = $this->product(['color' => 'red', 'size' => 'L']);
        $this->blue = $this->product(['color' => 'blue', 'size' => 'S']);
    }

    public function test_a_query_is_filtered_and_sorted_by_any_attribute_until_it_is_limited(): void
    {
        $this->assertSame([$this->red->id], $this->ids(FilterableProduct::query()->filter(['color' => 'red'])));
        $this->assertSame([$this->blue->id, $this->red->id], FilterableProduct::query()->sort('color')->pluck('id')->all());

        $this->assertSame([$this->red->id], $this->ids(FilterableProduct::query()->filterableBy(null)->filter(['color' => 'red'])));
    }

    public function test_an_attribute_outside_the_limit_neither_filters_nor_sorts_the_query(): void
    {
        $limited = fn (): Builder => FilterableProduct::query()->filterableBy([$this->size->id]);

        $this->assertSame([$this->red->id, $this->blue->id], $this->ids($limited()->filter(['color' => 'red'])));
        $this->assertSame([$this->blue->id], $this->ids($limited()->filter(['size' => 'S'])));

        $this->assertStringNotContainsStringIgnoringCase('order by', $limited()->sort('color')->toSql());
        $this->assertSame([$this->blue->id, $this->red->id], $limited()->sort('-size')->pluck('id')->all());
    }

    public function test_the_limit_reaches_groups_and_attributes_of_related_entities(): void
    {
        $child = FilterableProduct::create(['name' => 'Child', 'parent_id' => $this->red->id]);
        $limited = fn (): Builder => FilterableProduct::query()->filterableBy([$this->size->id]);

        foreach ([['or' => ['color' => 'red', 'size' => 'S']], ['and' => [['color' => 'red', 'size' => 'S']]]] as $either) {
            $this->assertSame([$this->red->id, $this->blue->id], $this->ids(FilterableProduct::query()->filter($either)));
            $this->assertSame([$this->blue->id], $this->ids($limited()->filter($either)));
        }

        $this->assertSame([$child->id], $this->ids(FilterableProduct::query()->filter(['parent.color' => 'red'])));
        $this->assertSame([$this->red->id, $this->blue->id, $child->id], $this->ids($limited()->filter(['parent.color' => 'red'])));
    }

    public function test_system_attributes_stay_available_within_a_limit(): void
    {
        $this->color->forceFill(['system' => true])->save();

        $limited = fn (): Builder => FilterableProduct::query()->filterableBy([]);

        $this->assertSame([$this->red->id], $this->ids($limited()->filter(['color' => 'red'])));
        $this->assertSame([$this->red->id, $this->blue->id], $this->ids($limited()->filter(['size' => 'S'])));
        $this->assertSame([$this->red->id, $this->blue->id], $limited()->sort('-color')->pluck('id')->all());
    }

    public function test_the_limit_stays_with_the_query_it_was_set_on(): void
    {
        FilterableProduct::query()->filterableBy([]);

        $this->assertSame([$this->red->id], $this->ids(FilterableProduct::query()->filter(['color' => 'red'])));
    }

    /** @param array<string, string> $values */
    private function product(array $values): FilterableProduct
    {
        $product = FilterableProduct::create(['name' => 'Widget']);

        foreach ($values as $code => $value) {
            $product->eav()->set($code, $value)->save($code);
        }

        return $product;
    }

    /** @return list<int> */
    private function ids(Builder $query): array
    {
        return $query->orderBy('id')->pluck('id')->all();
    }
}
