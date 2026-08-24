<?php

declare(strict_types=1);

namespace Jurager\Eav\Tests\Feature;

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Queue;
use Laravel\Scout\Jobs\MakeSearchable;
use Illuminate\Validation\ValidationException;
use Jurager\Eav\Enums\HeldBy;
use Jurager\Eav\Models\AttributeType;
use Jurager\Eav\Tests\Fixtures\Product;
use Jurager\Eav\Tests\Fixtures\SearchableProduct;

class ParentInheritanceTest extends FeatureTestCase
{
    private AttributeType $type;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createLocale('en');

        $this->type = $this->createAttributeType('text');
    }

    private function createVariant(Product $parent, string $name = 'Variant'): Product
    {
        return Product::create(['name' => $name, 'parent_id' => $parent->id]);
    }

    // -----------------------------------------------------------------------
    // Reading values
    // -----------------------------------------------------------------------

    public function test_variant_reads_the_parent_value_when_it_has_none_of_its_own(): void
    {
        $this->createAttribute($this->type, ['code' => 'title', 'inherit_from_parent' => true]);

        $parent = $this->createProduct();
        $parent->eav()->set('title', 'Parent title')->save('title');

        $variant = $this->createVariant($parent);

        $this->assertSame('Parent title', $variant->eav()->value('title'));
    }

    public function test_own_value_wins_over_the_inherited_one(): void
    {
        $this->createAttribute($this->type, ['code' => 'title', 'inherit_from_parent' => true, 'held_by' => HeldBy::Both]);

        $parent = $this->createProduct();
        $parent->eav()->set('title', 'Parent title')->save('title');

        $variant = $this->createVariant($parent);
        $variant->eav()->set('title', 'Variant title')->save('title');

        $this->assertSame('Variant title', $variant->fresh()->eav()->value('title'));
    }

    public function test_value_is_not_inherited_when_the_attribute_forbids_it(): void
    {
        $this->createAttribute($this->type, ['code' => 'sku', 'inherit_from_parent' => false, 'held_by' => HeldBy::Both]);

        $parent = $this->createProduct();
        $parent->eav()->set('sku', 'parent-sku')->save('sku');

        $variant = $this->createVariant($parent);

        $this->assertNull($variant->eav()->value('sku'));
    }

    public function test_root_entity_reads_only_its_own_values(): void
    {
        $this->createAttribute($this->type, ['code' => 'title', 'inherit_from_parent' => true]);

        $parent = $this->createProduct();
        $parent->eav()->set('title', 'Parent title')->save('title');

        $this->assertSame('Parent title', $parent->fresh()->eav()->value('title'));
    }

    public function test_values_contain_inherited_rows_alongside_own_ones(): void
    {
        $this->createAttribute($this->type, ['code' => 'title', 'inherit_from_parent' => true]);
        $this->createAttribute($this->type, ['code' => 'sku', 'inherit_from_parent' => false, 'held_by' => HeldBy::Both]);

        $parent = $this->createProduct();
        $parent->eav()->set('title', 'Parent title')->save('title');

        $variant = $this->createVariant($parent);
        $variant->eav()->set('sku', 'variant-sku')->save('sku');

        $values = $variant->fresh()->eav()->values();

        $this->assertEqualsCanonicalizing(['sku', 'title'], $values->pluck('attribute.code')->all());
        $this->assertSame('Parent title', $values->firstWhere('attribute.code', 'title')->value);
    }

    public function test_values_filtered_by_code_keep_inheritance(): void
    {
        $this->createAttribute($this->type, ['code' => 'title', 'inherit_from_parent' => true]);
        $this->createAttribute($this->type, ['code' => 'note', 'inherit_from_parent' => true]);

        $parent = $this->createProduct();
        $parent->eav()->set('title', 'Parent title')->save('title');
        $parent->eav()->set('note', 'Parent note')->save('note');

        $values = $this->createVariant($parent)->eav()->values(['title']);

        $this->assertSame(['title'], $values->pluck('attribute.code')->all());
    }

    public function test_inherited_values_are_paginated(): void
    {
        $this->createAttribute($this->type, ['code' => 'title', 'inherit_from_parent' => true]);
        $this->createAttribute($this->type, ['code' => 'note', 'inherit_from_parent' => true]);

        $parent = $this->createProduct();
        $parent->eav()->set('title', 'Parent title')->save('title');
        $parent->eav()->set('note', 'Parent note')->save('note');

        $page = $this->createVariant($parent)->eav()->values(paginated: 1);

        $this->assertInstanceOf(LengthAwarePaginator::class, $page);
        $this->assertSame(2, $page->total());
        $this->assertCount(1, $page->items());
    }

    // -----------------------------------------------------------------------
    // Schema
    // -----------------------------------------------------------------------

    public function test_schema_carries_every_attribute_in_scope_on_both_sides(): void
    {
        $this->createAttribute($this->type, ['code' => 'color', 'held_by' => HeldBy::Variant]);
        $this->createAttribute($this->type, ['code' => 'title', 'held_by' => HeldBy::Both]);
        $this->createAttribute($this->type, ['code' => 'display', 'inherit_from_parent' => true]);

        $parent = $this->createProduct();

        $this->assertEqualsCanonicalizing(
            ['color', 'title', 'display'],
            array_keys($this->createVariant($parent)->eav()->ensureSchema()->fields()),
        );
    }

    public function test_variant_fills_in_the_attributes_held_by_its_side(): void
    {
        $this->createAttribute($this->type, ['code' => 'color', 'held_by' => HeldBy::Variant]);
        $this->createAttribute($this->type, ['code' => 'title', 'held_by' => HeldBy::Both]);
        $this->createAttribute($this->type, ['code' => 'display', 'inherit_from_parent' => true]);

        $parent = $this->createProduct();

        $this->assertEqualsCanonicalizing(
            ['color', 'title'],
            $this->createVariant($parent)->available_attributes->pluck('code')->all(),
        );
    }

    public function test_parent_fills_in_everything_but_the_variant_only_attributes(): void
    {
        $this->createAttribute($this->type, ['code' => 'color', 'held_by' => HeldBy::Variant]);
        $this->createAttribute($this->type, ['code' => 'title', 'held_by' => HeldBy::Both]);
        $this->createAttribute($this->type, ['code' => 'display', 'inherit_from_parent' => true]);

        $this->assertEqualsCanonicalizing(
            ['title', 'display'],
            $this->createProduct()->available_attributes->pluck('code')->all(),
        );
    }

    // -----------------------------------------------------------------------
    // Writing values
    // -----------------------------------------------------------------------

    public function test_variant_only_attribute_is_rejected_on_the_parent(): void
    {
        $this->createAttribute($this->type, ['code' => 'color', 'held_by' => HeldBy::Variant]);

        $this->expectException(ValidationException::class);

        $this->createProduct()->validate([['code' => 'color', 'values' => 'black']]);
    }

    public function test_attribute_the_variant_may_not_override_is_rejected(): void
    {
        $this->createAttribute($this->type, ['code' => 'display', 'held_by' => HeldBy::Parent]);

        $parent = $this->createProduct();

        $this->expectException(ValidationException::class);

        $this->createVariant($parent)->validate([['code' => 'display', 'values' => 'OLED']]);
    }

    public function test_replacing_variant_values_leaves_the_parent_untouched(): void
    {
        $this->createAttribute($this->type, ['code' => 'title', 'held_by' => HeldBy::Both, 'inherit_from_parent' => true]);
        $this->createAttribute($this->type, ['code' => 'sku', 'held_by' => HeldBy::Variant]);

        $parent = $this->createProduct();
        $parent->eav()->set('title', 'Parent title')->save('title');

        $variant = $this->createVariant($parent);
        $variant->eav()->replace($variant->eav()->fill(['sku' => 'variant-sku'])->all());

        $this->assertSame('Parent title', $parent->fresh()->eav()->value('title'));
        $this->assertSame('Parent title', $variant->fresh()->eav()->value('title'));
    }

    public function test_unknown_codes_stay_silent(): void
    {
        $parent = $this->createProduct();

        $this->assertSame([], $this->createVariant($parent)->validate([['code' => 'nope', 'values' => 'x']]));
    }

    // -----------------------------------------------------------------------
    // Search index
    // -----------------------------------------------------------------------

    public function test_changing_parent_values_queues_reindexing_of_its_variants(): void
    {
        config(['scout.queue' => true]);
        Relation::morphMap(['searchable_product' => SearchableProduct::class]);

        $this->createAttribute($this->type, [
            'entity_type' => 'searchable_product',
            'code' => 'title',
            'searchable' => true,
            'inherit_from_parent' => true,
        ]);

        $parent = SearchableProduct::create(['name' => 'Model']);
        $variant = SearchableProduct::create(['name' => 'Variant', 'parent_id' => $parent->id]);

        Queue::fake();

        $parent->eav()->set('title', 'Parent title')->save('title');

        Queue::assertPushed(
            MakeSearchable::class,
            static fn (MakeSearchable $job): bool => $job->models->contains('id', $variant->id),
        );

        Relation::morphMap(['searchable_product' => null]);
    }

    public function test_index_document_of_a_variant_carries_inherited_values(): void
    {
        Relation::morphMap(['searchable_product' => SearchableProduct::class]);

        $this->createAttribute($this->type, [
            'entity_type' => 'searchable_product',
            'code' => 'title',
            'searchable' => true,
            'inherit_from_parent' => true,
        ]);

        $parent = SearchableProduct::create(['name' => 'Model']);
        $parent->eav()->set('title', 'Parent title')->save('title');

        $variant = SearchableProduct::create(['name' => 'Variant', 'parent_id' => $parent->id]);

        $this->assertSame(['title' => 'Parent title'], $variant->toSearchableArray()['attributes']);

        Relation::morphMap(['searchable_product' => null]);
    }
}
