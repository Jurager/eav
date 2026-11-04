<?php

declare(strict_types=1);

namespace Jurager\Eav\Tests\Unit;

use Illuminate\Support\Collection;
use Jurager\Eav\Contracts\Attributable;
use Jurager\Eav\Media\MediaField;
use Jurager\Eav\Models\Attribute;
use Jurager\Eav\Models\EntityAttribute;
use Jurager\Eav\Registry\EnumRegistry;
use Jurager\Eav\Registry\LocaleRegistry;
use Jurager\Eav\Tests\TestCase;
use Jurager\Media\Contracts\InteractsWithMedia;
use Jurager\Media\Models\Media;

class MediaFieldTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (! class_exists(Media::class)) {
            $this->markTestSkipped('The optional jurager/media integration is not installed.');
        }
    }

    private function field(): MediaField
    {
        return new MediaField(
            new Attribute(['code' => 'image_gallery']), new LocaleRegistry, new EnumRegistry,
        );
    }

    public function test_resolves_numeric_ids_from_the_requested_media_collection(): void
    {
        $first = (new Media)->setRawAttributes(['id' => 1]);
        $second = (new Media)->setRawAttributes(['id' => 2]);
        $owner = $this->createMockForIntersectionOfInterfaces([Attributable::class, InteractsWithMedia::class]);
        $owner->expects($this->exactly(3))->method('getMedia')
            ->with('image_gallery')->willReturn(new Collection([$first, $second]));
        $field = $this->field();

        $this->assertSame($second, $field->resolve('2', $owner));
        $this->assertSame($first, $field->resolve(1, $owner));
        $this->assertNull($field->resolve(3, $owner));
    }

    public function test_null_values_and_entities_without_media_return_null(): void
    {
        $owner = $this->createMockForIntersectionOfInterfaces([Attributable::class, InteractsWithMedia::class]);
        $owner->expects($this->never())->method('getMedia');
        $field = $this->field();

        $this->assertNull($field->resolve(null, $owner));
        $this->assertNull($field->resolve(1));
        $this->assertNull($field->resolve(1, $this->createStub(Attributable::class)));
    }

    public function test_value_order_uses_the_owning_medias_collection_order(): void
    {
        $first = (new Media)->setRawAttributes(['id' => 1, 'order_column' => 20]);
        $second = (new Media)->setRawAttributes(['id' => 2, 'order_column' => 5]);
        $owner = $this->createMockForIntersectionOfInterfaces([Attributable::class, InteractsWithMedia::class]);
        $owner->expects($this->exactly(3))->method('getMedia')
            ->with('image_gallery')->willReturn(new Collection([$first, $second]));
        $value = (new EntityAttribute(['value_integer' => 2]))->setRelation('entity', $owner);
        $field = $this->field();

        $this->assertSame(5, $field->sortOrder($value));
        $value->value_integer = 1;
        $this->assertSame(20, $field->sortOrder($value));
        $value->value_integer = 99;
        $this->assertSame(PHP_INT_MAX, $field->sortOrder($value));
    }

    public function test_empty_media_values_do_not_resolve_the_owner_to_sort(): void
    {
        $value = $this->getMockBuilder(EntityAttribute::class)->onlyMethods(['getRelationValue'])->getMock();
        $value->expects($this->never())->method('getRelationValue');

        $this->assertSame(PHP_INT_MAX, $this->field()->sortOrder($value));
    }
}
