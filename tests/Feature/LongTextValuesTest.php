<?php

declare(strict_types=1);

namespace Jurager\Eav\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Jurager\Eav\Models\AttributeType;
use Jurager\Eav\Models\Locale;
use Jurager\Eav\Tests\Fixtures\Product;

/** Text of any length — Textarea and Link values — lives in a column of its own. */
class LongTextValuesTest extends FeatureTestCase
{
    private AttributeType $textarea;

    private Locale $en;

    protected function setUp(): void
    {
        parent::setUp();

        $this->en = $this->createLocale('en');
        $this->textarea = $this->createAttributeType('textarea');
    }

    public function test_a_long_value_is_kept_out_of_the_short_text_column(): void
    {
        $this->createAttribute($this->textarea, ['code' => 'description']);
        $description = Str::repeat('Solid oak, hand finished. ', 200);

        $product = $this->createProduct();
        $product->eav()->set('description', $description)->save();

        $row = DB::table('entity_attribute')->first();

        $this->assertNull($row->value_text);
        $this->assertSame($description, $row->value_long);
        $this->assertSame($description, $product->fresh()->eav()->value('description'));
    }

    public function test_a_long_translation_is_read_back_whole(): void
    {
        $this->createAttribute($this->textarea, ['code' => 'description', 'localizable' => true]);
        $description = Str::repeat('Massive Eiche, handgefertigt. ', 200);

        $product = $this->createProduct();
        $product->eav()->set('description', $description, $this->en->id)->save();

        $this->assertSame($description, $product->fresh()->eav()->value('description', $this->en->id));
    }

    public function test_like_searches_long_text(): void
    {
        $this->createAttribute($this->textarea, ['code' => 'description']);

        $this->createProduct('Desk')->eav()->set('description', Str::repeat('x', 400).' Hand Finished oak')->save();
        $this->createProduct('Chair')->eav()->set('description', 'Painted beech')->save();

        $this->assertSame(['Desk'], Product::query()->whereAttributeLike('description', 'hand finished')->pluck('name')->all());
    }

    public function test_short_text_keeps_its_own_column(): void
    {
        $this->createAttribute($this->textarea, ['code' => 'description']);
        $this->createAttribute($this->createAttributeType('text'), ['code' => 'sku']);
        $this->createProduct()->eav()->set('description', 'Oak desk')->set('sku', 'D-1')->save();

        $this->assertSame(['description' => null, 'sku' => 'D-1'], $this->stored('value_text'));
        $this->assertSame(['description' => 'Oak desk', 'sku' => null], $this->stored('value_long'));
    }

    /** @return array<string, string|null> The column of every stored value, by attribute code. */
    private function stored(string $column): array
    {
        return DB::table('entity_attribute')
            ->join('attributes', 'attributes.id', '=', 'entity_attribute.attribute_id')
            ->orderBy('attributes.code')
            ->pluck("entity_attribute.$column", 'attributes.code')
            ->all();
    }
}
