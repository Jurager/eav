<?php

declare(strict_types=1);

namespace Jurager\Eav\Tests\Feature\Attributes;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Jurager\Eav\Attributes\Scope\AttributeInheritanceResolver;
use Jurager\Eav\Exceptions\CircularInheritanceException;
use Jurager\Eav\Models\AttributeType;
use Jurager\Eav\Registry\AttributeRegistry;
use Jurager\Eav\Registry\SchemaRegistry;
use Jurager\Eav\Tests\Feature\FeatureTestCase;
use Jurager\Eav\Tests\Fixtures\NestedTreeCategory;
use Jurager\Eav\Tests\Fixtures\NestedTreeProduct;
use Jurager\Eav\Tests\Fixtures\PlainEntity;
use Jurager\Eav\Tests\Fixtures\TreeCategory;
use Jurager\Eav\Tests\Fixtures\TreeProduct;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Resolve scoped attributes consistently for single entities and collections.
 *
 * The tree, with each category's own attribute named after it (* inherits its parent's):
 *
 *   1 root
 *   ├── 2 electronics*
 *   │   ├── 3 phones*
 *   │   │   └── 4 smartphones*
 *   │   └── 5 audio
 *   └── 6 garden
 *       └── 7 tools*
 */
class ScopedAttributeResolutionTest extends FeatureTestCase
{
    private AttributeType $textType;

    /** @var array<string, int> Attribute ids by code. */
    private array $ids = [];

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('categories', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->boolean('is_inherits_properties')->default(false);
            $table->unsignedInteger('_lft')->nullable();
            $table->unsignedInteger('_rgt')->nullable();
            $table->timestamps();
        });

        Schema::create('category_product', function (Blueprint $table): void {
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('category_id');
        });

        Schema::create('category_attribute', function (Blueprint $table): void {
            $table->unsignedBigInteger('category_id');
            $table->unsignedBigInteger('attribute_id');
        });

        $this->createLocale('en');
        $this->textType = $this->createAttributeType('text');

        $tree = [
            [1, 'root', null, false, 1, 14],
            [2, 'electronics', 1, true, 2, 9],
            [3, 'phones', 2, true, 3, 6],
            [4, 'smartphones', 3, true, 4, 5],
            [5, 'audio', 2, false, 7, 8],
            [6, 'garden', 1, false, 10, 13],
            [7, 'tools', 6, true, 11, 12],
        ];

        foreach ($tree as [$id, $name, $parentId, $inherits, $lft, $rgt]) {
            DB::table('categories')->insert(['id' => $id, 'name' => $name, 'parent_id' => $parentId, 'is_inherits_properties' => $inherits, '_lft' => $lft, '_rgt' => $rgt]);
            $this->ids[$name] = $this->createAttribute($this->textType, ['code' => $name])->id;
            DB::table('category_attribute')->insert(['category_id' => $id, 'attribute_id' => $this->ids[$name]]);
        }
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('category_attribute');
        Schema::dropIfExists('category_product');
        Schema::dropIfExists('categories');

        parent::tearDown();
    }

    /** @return array<string, array{class-string<TreeProduct>}> */
    public static function trees(): array
    {
        return [
            'parent_id' => [TreeProduct::class],
            'nested set' => [NestedTreeProduct::class],
        ];
    }

    /** @return array<string, array{list<int>, list<string>}> */
    public static function scopes(): array
    {
        return [
            'a deep chain up to the root' => [[4], ['root', 'electronics', 'phones', 'smartphones']],
            'a category that does not inherit' => [[5], ['audio']],
            'a chain stopping at an ancestor that does not inherit' => [[7], ['garden', 'tools']],
            'several categories' => [[4, 5, 7], ['root', 'electronics', 'phones', 'smartphones', 'audio', 'garden', 'tools']],
            'a category and its own ancestor' => [[3, 2], ['root', 'electronics', 'phones']],
            'no category' => [[], []],
        ];
    }

    /** @return iterable<string, array{class-string<TreeProduct>, list<int>, list<string>}> */
    public static function treesAndScopes(): iterable
    {
        foreach (self::trees() as $tree => [$product]) {
            foreach (self::scopes() as $scope => [$categories, $codes]) {
                yield "{$tree}: {$scope}" => [$product, $categories, $codes];
            }
        }
    }

    /**
     * @param  class-string<TreeProduct>  $class
     * @param  list<int>  $categories
     * @param  list<string>  $codes
     */
    #[DataProvider('treesAndScopes')]
    public function test_available_attributes_follow_the_ancestors_each_category_inherits_from(string $class, array $categories, array $codes): void
    {
        $product = $this->placeProduct($class, $categories);

        $expected = $this->idsOf($codes);

        $this->assertSame($expected, $this->sorted($product->attributeIdsInScope()));
        $this->assertSame($expected, $this->sorted($product->availableAttributes($product->attributeScopeIds())->pluck('id')->all()));
    }

    /** @param class-string<TreeProduct> $class */
    #[DataProvider('trees')]
    public function test_reading_an_attribute_answers_only_for_the_attributes_available_to_the_entity(string $class): void
    {
        $product = $this->placeProduct($class, [7]);

        $this->assertNotNull($product->eav()->field('tools'));
        $this->assertNotNull($product->eav()->field('garden'));
        $this->assertNull($product->eav()->field('root'));
        $this->assertNull($product->eav()->field('smartphones'));
    }

    /** @param class-string<TreeProduct> $class */
    #[DataProvider('trees')]
    public function test_reading_attributes_across_a_collection_resolves_every_scope_together(string $class): void
    {
        $categories = [4, 5, 7, 3, 6];

        foreach ($categories as $category) {
            $this->placeProduct($class, [$category]);
        }

        $products = $class::query()->with('categories')->get()->withRelationshipAutoloading();
        app(AttributeRegistry::class)->all('product');

        DB::enableQueryLog();

        $tools = $products->map(fn (TreeProduct $product) => $product->eav()->field('tools') !== null)->all();
        $garden = $products->map(fn (TreeProduct $product) => $product->eav()->field('garden') !== null)->all();

        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertSame([false, false, true, false, false], $tools);
        $this->assertSame([false, false, true, false, true], $garden);

        // Categories by id, their ancestors (one bounds query, or one per level of parents), the pivot rows.
        $this->assertCount($class === NestedTreeProduct::class ? 3 : 4, $this->scopeQueries($queries));
        $this->assertSame([], $this->definitionQueries($queries), 'definitions come from the registry');
    }

    /** @param class-string<TreeProduct> $class */
    #[DataProvider('trees')]
    public function test_a_category_resolved_once_is_not_resolved_again_for_another_entity(string $class): void
    {
        $first = $this->placeProduct($class, [4]);
        $second = $this->placeProduct($class, [4]);
        $first->eav()->field('root');

        DB::enableQueryLog();
        $field = $second->eav()->field('root');
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertNotNull($field);
        $this->assertSame([], $this->scopeQueries($queries));
    }

    public function test_a_variant_without_categories_of_its_own_takes_its_parents_attributes(): void
    {
        $parent = $this->placeProduct(NestedTreeProduct::class, [7]);
        $variant = NestedTreeProduct::create(['name' => 'Variant', 'parent_id' => $parent->id]);

        $this->assertSame($this->idsOf(['garden', 'tools']), $this->sorted($variant->fresh()->attributeIdsInScope()));
    }

    public function test_attributes_of_another_entity_type_linked_to_a_category_are_not_available(): void
    {
        $service = $this->createAttribute($this->textType, ['code' => 'delivery_slot', 'entity_types' => ['service']]);
        DB::table('category_attribute')->insert(['category_id' => 7, 'attribute_id' => $service->id]);

        $product = $this->placeProduct(NestedTreeProduct::class, [7]);

        $this->assertNull($product->eav()->field('delivery_slot'));
        $this->assertNotNull($product->eav()->field('tools'));
    }

    public function test_several_codes_are_hydrated_in_schema_order(): void
    {
        DB::table('attributes')->where('id', $this->ids['tools'])->update(['sort' => 1]);
        DB::table('attributes')->where('id', $this->ids['garden'])->update(['sort' => 2]);
        app(AttributeRegistry::class)->forget();

        $product = $this->placeProduct(NestedTreeProduct::class, [7]);
        $product->eav()->ensureFields(['garden', 'tools', 'root']);

        $this->assertSame(['tools', 'garden'], array_keys($product->eav()->fields()));
    }

    public function test_links_changed_before_the_schema_registry_forgets_are_seen_after(): void
    {
        $product = $this->placeProduct(NestedTreeProduct::class, [5]);
        $product->attributeIdsInScope();

        DB::table('category_attribute')->insert(['category_id' => 5, 'attribute_id' => $this->ids['root']]);
        app(SchemaRegistry::class)->forget('product');

        $this->assertSame($this->idsOf(['root', 'audio']), $this->sorted($product->attributeIdsInScope()));
    }

    // -----------------------------------------------------------------------
    // AttributeInheritanceResolver
    // -----------------------------------------------------------------------

    /** @param class-string<TreeProduct> $class */
    #[DataProvider('trees')]
    public function test_resolver_splits_the_lineage_per_entity(string $class): void
    {
        $model = $class === NestedTreeProduct::class ? NestedTreeCategory::class : TreeCategory::class;
        $entities = $model::query()->whereKey([4, 5, 7])->get()->keyBy('id');

        $lineage = app(AttributeInheritanceResolver::class)->resolveEach($entities, $model);
        $merged = app(AttributeInheritanceResolver::class)->resolve($entities, $model)->pluck('id')->sort()->values()->all();

        $this->assertSame([4 => [4, 3, 2, 1], 5 => [5], 7 => [7, 6]], $lineage);
        $this->assertSame([1, 2, 3, 4, 5, 6, 7], $merged);
    }

    public function test_resolver_leaves_out_ids_that_name_no_entity(): void
    {
        $resolved = app(AttributeInheritanceResolver::class)->attributeIds(NestedTreeCategory::class, [7, 999]);

        $this->assertSame([7], array_keys($resolved));
        $this->assertSame($this->idsOf(['garden', 'tools']), $this->sorted($resolved[7]));
    }

    public function test_resolver_rejects_a_parent_chain_that_loops_back_to_itself(): void
    {
        DB::table('categories')->where('id', 1)->update(['parent_id' => 4, 'is_inherits_properties' => true]);

        $this->expectException(CircularInheritanceException::class);

        app(AttributeInheritanceResolver::class)->resolveEach(TreeCategory::query()->whereKey([4])->get(), TreeCategory::class);
    }

    // -----------------------------------------------------------------------
    // An entity implementing the contract without the package's traits
    // -----------------------------------------------------------------------

    public function test_an_entity_without_the_traits_narrows_to_its_own_available_attributes_query(): void
    {
        $this->createAttribute($this->textType, ['code' => 'hidden']);
        $entity = PlainEntity::create(['name' => 'Widget']);
        PlainEntity::$availableQueries = 0;

        $this->assertNotNull($entity->eav()->field('root'));
        $this->assertNull($entity->eav()->field('hidden'));
        $this->assertSame(1, PlainEntity::$availableQueries, 'its scope is queried once per request');
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /**
     * @param  class-string<TreeProduct>  $class
     * @param  list<int>  $categories
     */
    private function placeProduct(string $class, array $categories): TreeProduct
    {
        $product = $class::create(['name' => 'Widget']);
        $product->categories()->attach($categories);

        return $product->fresh(['categories']);
    }

    /**
     * @param  list<string>  $codes
     * @return list<int>
     */
    private function idsOf(array $codes): array
    {
        return $this->sorted(array_map(fn (string $code): int => $this->ids[$code], $codes));
    }

    /**
     * @param  list<int|string>|null  $ids
     * @return list<int>
     */
    private function sorted(?array $ids): array
    {
        $ids = array_map(intval(...), $ids ?? []);
        sort($ids);

        return $ids;
    }

    /**
     * @param  list<array{query: string}>  $queries
     * @return list<string>
     */
    private function scopeQueries(array $queries): array
    {
        return array_values(array_filter(
            array_column($queries, 'query'),
            static fn (string $sql): bool => str_contains($sql, 'from "categories"') || str_contains($sql, 'from "category_attribute"'),
        ));
    }

    /**
     * @param  list<array{query: string}>  $queries
     * @return list<string>
     */
    private function definitionQueries(array $queries): array
    {
        return array_values(array_filter(
            array_column($queries, 'query'),
            static fn (string $sql): bool => str_contains($sql, 'from "attributes"') || str_contains($sql, 'from "attribute_entity_types"'),
        ));
    }
}
