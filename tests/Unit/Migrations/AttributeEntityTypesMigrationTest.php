<?php

declare(strict_types=1);

namespace Jurager\Eav\Tests\Unit\Migrations;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Jurager\Eav\Models\Attribute;
use Jurager\Eav\Models\AttributeType;
use Jurager\Eav\Tests\TestCase;

/**
 * `defineDatabaseMigrations()` already runs every package migration forward once when the test
 * app boots, so `attributes.entity_type` is already gone and `attribute_entity_types` already
 * exists by the time these tests start. This suite drives the two migrations' up()/down() a
 * second time, directly, to prove the column can be rolled back and reapplied without losing data.
 */
class AttributeEntityTypesMigrationTest extends TestCase
{
    private Migration $pivotMigration;

    private Migration $dropColumnMigration;

    protected function setUp(): void
    {
        parent::setUp();

        $base = __DIR__.'/../../../database/migrations/';
        $this->pivotMigration = require $base.'2026_09_29_000001_create_attribute_entity_types_table.php';
        $this->dropColumnMigration = require $base.'2026_09_29_000002_drop_entity_type_from_attributes_table.php';
    }

    public function test_down_restores_the_column_backfilled_from_the_pivot(): void
    {
        $type = AttributeType::create(['code' => 'text']);
        $attribute = Attribute::create(['attribute_type_id' => $type->id, 'code' => 'color', 'sort' => 0]);
        $attribute->entityTypes()->create(['entity_type' => 'product']);

        $this->dropColumnMigration->down();

        $this->assertTrue(Schema::hasColumn('attributes', 'entity_type'));
        $this->assertSame('product', DB::table('attributes')->where('id', $attribute->id)->value('entity_type'));

        // Round-trip back to the current schema, so the rest of the suite isn't left mid-migration.
        $this->dropColumnMigration->up();

        $this->assertFalse(Schema::hasColumn('attributes', 'entity_type'));
    }

    public function test_down_then_up_is_idempotent_for_a_reapplied_column(): void
    {
        $type = AttributeType::create(['code' => 'text']);
        $attribute = Attribute::create(['attribute_type_id' => $type->id, 'code' => 'color', 'sort' => 0]);
        $attribute->entityTypes()->create(['entity_type' => 'product']);

        $this->dropColumnMigration->down();
        $this->dropColumnMigration->up();

        $this->assertFalse(Schema::hasColumn('attributes', 'entity_type'));
        $this->assertSame(
            ['product'],
            Attribute::find($attribute->id)->applicableEntityTypes()
        );
    }

    public function test_pivot_migration_down_drops_the_table_and_up_recreates_and_backfills_it(): void
    {
        $this->dropColumnMigration->down();

        $type = AttributeType::create(['code' => 'text']);

        // The model no longer treats `entity_type` as fillable (the column only exists here
        // because of the `down()` above), so insert straight through the query builder.
        $extraId = DB::table('attributes')->insertGetId([
            'entity_type' => 'category',
            'attribute_type_id' => $type->id,
            'code' => 'seo_title',
            'sort' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->pivotMigration->down();
        $this->assertFalse(Schema::hasTable('attribute_entity_types'));

        $this->pivotMigration->up();
        $this->assertTrue(Schema::hasTable('attribute_entity_types'));
        $this->assertSame(
            'category',
            DB::table('attribute_entity_types')->where('attribute_id', $extraId)->value('entity_type')
        );

        // Round-trip back to the current schema.
        $this->dropColumnMigration->up();
    }
}
