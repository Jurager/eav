<?php

declare(strict_types=1);

namespace Jurager\Eav\Tests\Feature\Attributes;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Jurager\Eav\Exceptions\UnknownAttributeException;
use Jurager\Eav\Models\Attribute;
use Jurager\Eav\Registry\SchemaRegistry;
use Jurager\Eav\Tests\Feature\FeatureTestCase;
use Jurager\Eav\Tests\Fixtures\ScopedCategory;
use Jurager\Eav\Tests\Fixtures\ScopedProduct;
use PHPUnit\Framework\Attributes\DataProvider;

class ScopeInvalidationTest extends FeatureTestCase
{
    private ScopedProduct $product;

    private ScopedCategory $category;

    private Attribute $old;

    private Attribute $added;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('categories', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        foreach (['category_product' => 'product_id', 'category_attribute' => 'attribute_id'] as $name => $foreignKey) {
            Schema::create($name, function (Blueprint $table) use ($foreignKey): void {
                $table->unsignedBigInteger('category_id');
                $table->unsignedBigInteger($foreignKey);
            });
        }

        $this->createLocale();
        $type = $this->createAttributeType('text');
        $this->old = $this->createAttribute($type, ['code' => 'old_code']);
        $this->added = $this->createAttribute($type, ['code' => 'new_code']);
        $this->category = ScopedCategory::create(['name' => 'Scope']);
        $this->category->attributeScopeRelation()->attach($this->old->id);
        $product = ScopedProduct::create(['name' => 'Product']);
        $product->categories()->attach($this->category->id);
        $this->product = $product->fresh();
    }

    protected function tearDown(): void
    {
        foreach (['category_attribute', 'category_product', 'categories'] as $table) {
            Schema::dropIfExists($table);
        }

        parent::tearDown();
    }

    public static function managers(): array
    {
        return ['same manager' => [false], 'fresh manager' => [true]];
    }

    public static function cachePaths(): array
    {
        return ['full schema first' => [false], 'selected fields first' => [true]];
    }

    #[DataProvider('managers')]
    public function test_values_are_saved_after_an_attribute_is_added_to_scope(bool $fresh): void
    {
        $this->product->eav()->attach($this->product->validate([['code' => 'old_code', 'values' => 'Old']]));
        $this->category->attributeScopeRelation()->syncWithoutDetaching([$this->added->id]);
        $product = $fresh ? $this->product->fresh() : $this->product;
        $fields = $product->validate([['code' => 'new_code', 'values' => 'New']]);
        $product->eav()->attach($fields);

        $this->assertArrayHasKey('new_code', $fields);
        $this->assertDatabaseHas('entity_attribute', [
            'entity_type' => 'product', 'entity_id' => $product->id,
            'attribute_id' => $this->added->id, 'value_text' => 'New',
        ]);
    }

    #[DataProvider('cachePaths')]
    public function test_schema_methods_agree_after_scope_changes(bool $selected): void
    {
        $schema = $this->product->eav()->schema();
        $selected ? $schema->only(['new_code']) : $schema->attributes();
        $this->category->attributeScopeRelation()->syncWithoutDetaching([$this->added->id]);

        $this->assertSame(['new_code'], $schema->only(['new_code'])->pluck('code')->all());
        $this->assertSame(['old_code', 'new_code'], $schema->attributes()->pluck('code')->all());
        $this->assertSame(['new_code'], $schema->only(['new_code'])->pluck('code')->all());
    }

    #[DataProvider('cachePaths')]
    public function test_raw_sql_uses_one_snapshot_until_explicit_invalidation(bool $selected): void
    {
        $schema = $this->product->eav()->schema();
        $selected ? $schema->only(['new_code']) : $this->product->eav()->ensureSchema();
        DB::table('category_attribute')->insert(['category_id' => $this->category->id, 'attribute_id' => $this->added->id]);

        $this->assertSame([], $schema->only(['new_code'])->all());
        $this->assertSame(['old_code'], $schema->attributes()->pluck('code')->all());

        app(SchemaRegistry::class)->forget('product');

        $this->assertSame(['new_code'], $schema->only(['new_code'])->pluck('code')->all());
        $this->assertArrayHasKey('new_code', $this->product->validate([['code' => 'new_code', 'values' => 'New']]));
    }

    public function test_removed_attributes_are_not_visible_to_an_existing_manager(): void
    {
        $this->product->eav()->ensureSchema();
        $this->category->attributeScopeRelation()->detach($this->old->id);

        $this->assertNull($this->product->eav()->field('old_code'));
        $this->assertSame([], $this->product->eav()->schema()->attributes()->all());
        $this->assertSame([], $this->product->validate([['code' => 'old_code', 'values' => 'Ignored']]));
    }

    public function test_scope_changes_preserve_unsaved_values_of_available_attributes(): void
    {
        $this->product->eav()->set('old_code', 'Unsaved');
        $this->category->attributeScopeRelation()->syncWithoutDetaching([$this->added->id]);
        $this->product->eav()->ensureSchema();

        $this->assertSame('Unsaved', $this->product->eav()->value('old_code'));
        $this->assertTrue($this->product->eav()->isDirty('old_code'));
        $this->product->eav()->save();

        $this->assertDatabaseHas('entity_attribute', ['entity_id' => $this->product->id, 'attribute_id' => $this->old->id, 'value_text' => 'Unsaved']);
    }

    public function test_dirty_attributes_removed_from_scope_cannot_be_saved(): void
    {
        $this->product->eav()->set('old_code', 'Unsaved');
        $this->category->attributeScopeRelation()->detach($this->old->id);

        $this->assertNull($this->product->eav()->field('old_code'));
        $this->assertSame('Unsaved', $this->product->eav()->getDirty()['old_code']->value());

        $this->expectException(UnknownAttributeException::class);
        $this->product->eav()->save();
    }

    public function test_pending_values_survive_removing_and_restoring_scope(): void
    {
        $this->product->eav()->set('old_code', 'Unsaved');
        $this->category->attributeScopeRelation()->detach($this->old->id);
        $this->assertNull($this->product->eav()->field('old_code'));

        $this->category->attributeScopeRelation()->attach($this->old->id);

        $this->assertSame('Unsaved', $this->product->eav()->value('old_code'));
        $this->product->eav()->save();
        $this->assertDatabaseHas('entity_attribute', ['entity_id' => $this->product->id, 'attribute_id' => $this->old->id, 'value_text' => 'Unsaved']);
    }

    public static function writes(): array
    {
        return ['attach' => ['attach'], 'replace' => ['replace']];
    }

    #[DataProvider('writes')]
    public function test_prepared_fields_removed_from_scope_cannot_be_persisted(string $method): void
    {
        $fields = $this->product->validate([['code' => 'old_code', 'values' => 'Prepared']]);
        $this->category->attributeScopeRelation()->detach($this->old->id);

        $this->expectException(UnknownAttributeException::class);
        $this->product->eav()->{$method}($fields);
    }
}
