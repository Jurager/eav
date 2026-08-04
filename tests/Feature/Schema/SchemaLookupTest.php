<?php

declare(strict_types=1);

namespace Jurager\Eav\Tests\Feature\Schema;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Jurager\Eav\Exceptions\SearchNotAvailableException;
use Jurager\Eav\Facades\Schema;
use Jurager\Eav\Tests\Feature\FeatureTestCase;

class SchemaLookupTest extends FeatureTestCase
{
    public function test_find_attribute_returns_the_matching_attribute(): void
    {
        $type = $this->createAttributeType('text');
        $attribute = $this->createAttribute($type, ['code' => 'name']);

        $this->assertTrue(Schema::findAttribute($attribute->id)->is($attribute));
    }

    public function test_find_attribute_throws_when_missing(): void
    {
        $this->expectException(ModelNotFoundException::class);

        Schema::findAttribute(9999);
    }

    public function test_find_group_returns_the_matching_group(): void
    {
        $group = Schema::group('default')->create();

        $this->assertTrue(Schema::findGroup($group->id)->is($group));
    }

    public function test_find_enum_returns_the_matching_enum(): void
    {
        $type = $this->createAttributeType('select');
        $attribute = $this->createAttribute($type, ['code' => 'color']);
        $enum = Schema::enum($attribute, 'red')->create();

        $this->assertTrue(Schema::findEnum($enum->id)->is($enum));
    }

    public function test_find_type_returns_the_matching_type(): void
    {
        $type = $this->createAttributeType('text');

        $this->assertTrue(Schema::findType($type->id)->is($type));
    }

    public function test_find_type_throws_when_missing(): void
    {
        $this->expectException(ModelNotFoundException::class);

        Schema::findType(9999);
    }

    public function test_attributes_returns_a_query_builder(): void
    {
        $type = $this->createAttributeType('text');
        $this->createAttribute($type, ['code' => 'name', 'entity_type' => 'product']);
        $this->createAttribute($type, ['code' => 'title', 'entity_type' => 'category']);

        $results = Schema::attributes()->where('entity_type', 'product')->get();

        $this->assertCount(1, $results);
        $this->assertSame('name', $results->first()->code);
    }

    public function test_groups_returns_a_query_builder(): void
    {
        Schema::group('a')->create();
        Schema::group('b')->create();

        $this->assertCount(2, Schema::groups()->get());
    }

    public function test_enums_returns_a_query_builder_for_the_attribute(): void
    {
        $type = $this->createAttributeType('select');
        $attribute = $this->createAttribute($type, ['code' => 'color']);
        Schema::enum($attribute, 'red')->create();
        Schema::enum($attribute, 'blue')->create();

        $this->assertCount(2, Schema::enums($attribute)->get());
    }

    public function test_types_returns_a_query_builder(): void
    {
        $this->createAttributeType('text');
        $this->createAttributeType('number');

        $this->assertCount(2, Schema::types()->get());
    }

    public function test_search_throws_when_scout_is_not_configured(): void
    {
        $this->expectException(SearchNotAvailableException::class);

        Schema::search('color');
    }
}
