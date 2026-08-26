<?php

declare(strict_types=1);

namespace Jurager\Eav\Tests\Unit\Search;

use Illuminate\Database\Eloquent\Model;
use Jurager\Eav\Fields\FieldFactory;
use Jurager\Eav\Registry\LocaleRegistry;
use Jurager\Eav\Search\Contracts\InteractsWithIndex;
use Jurager\Eav\Search\Engine;
use Jurager\Eav\Search\Builder;
use Jurager\Eav\Tests\TestCase;
use Meilisearch\Client;
use Mockery;
use Psr\Log\LoggerInterface;
use ReflectionClass;

class SearchTest extends TestCase
{
    private Engine $engine;

    private Builder $search;

    protected function setUp(): void
    {
        parent::setUp();

        $this->engine = new Engine(
            Mockery::mock(Client::class),
            Mockery::mock(LoggerInterface::class),
            Mockery::mock(FieldFactory::class),
            Mockery::mock(LocaleRegistry::class),
            Mockery::mock(\Jurager\Eav\Registry\SchemaRegistry::class),
            new \Jurager\Eav\Search\Compiler(),
        );

        $this->search = new Builder($this->engine, [], 'product');
    }

    private function resolve(string $key, array $map = []): ?string
    {
        if ($map !== []) {
            $this->search->map($map);
        }

        $method = (new ReflectionClass($this->engine))->getMethod('createResolver');
        $method->setAccessible(true);

        $resolve = $method->invoke($this->engine, $this->search);

        return $resolve($key);
    }

    private function withModel(Model $model): void
    {
        $property = (new ReflectionClass($this->search))->getProperty('model');
        $property->setAccessible(true);
        $property->setValue($this->search, $model);
    }

    public function test_id_resolves_by_default_with_no_configuration_at_all(): void
    {
        $this->assertSame('id', $this->resolve('id'));
    }

    public function test_unknown_key_with_dot_does_not_resolve_by_default_unless_mapped(): void
    {
        // A key with a dot (nested) should pass through as-is if not mapped.
        // Wait, the new logic returns $key if it has a dot!
        $this->assertSame('prices.retail', $this->resolve('prices.retail'));
    }

    public function test_explicit_map_still_resolves_a_key(): void
    {
        $this->assertSame('category_ids', $this->resolve('categories.category_id', ['categories.category_id' => 'category_ids']));
    }

    public function test_a_model_implementing_interacts_with_index_supplies_its_own_map(): void
    {
        $this->withModel(new class () extends Model implements InteractsWithIndex {
            public function indexAliases(): array
            {
                return ['categories.category_id' => 'category_ids'];
            }

            public function indexFields(): array
            {

                return [];

            }
        });

        $this->assertSame('category_ids', $this->resolve('categories.category_id'));
    }

    public function test_a_model_not_implementing_interacts_with_index_supplies_nothing(): void
    {
        $this->withModel(new class () extends Model {
            //
        });

        $this->assertSame('categories.category_id', $this->resolve('categories.category_id'));
    }

    public function test_the_model_map_does_not_shadow_the_built_in_id_default(): void
    {
        $this->withModel(new class () extends Model implements InteractsWithIndex {
            public function indexAliases(): array
            {
                return ['id' => 'something_else'];
            }

            public function indexFields(): array
            {

                return [];

            }
        });

        // 'id' is resolved unconditionally before any contract/map lookup runs.
        $this->assertSame('id', $this->resolve('id'));
    }

    public function test_any_key_without_a_dot_resolves_as_an_attribute_by_default(): void
    {
        // The resolver delegates validation to Meilisearch, prefixing any non-dot key.
        $this->assertSame('attributes.00054', $this->resolve('00054'));
        $this->assertSame('attributes.sku', $this->resolve('sku'));
    }

    /** Compile one side of a partitioned search and read back its filter. */
    private function partitionFilter(bool $matching): ?string
    {
        $method = (new ReflectionClass($this->engine))->getMethod('partitionRequest');
        $method->setAccessible(true);

        $resolver = (new ReflectionClass($this->engine))->getMethod('createResolver');
        $resolver->setAccessible(true);

        $request = $method->invoke($this->engine, $this->search, 'products', $resolver->invoke($this->engine, $this->search), $matching);

        return $request->toArray()['filter'][0] ?? null;
    }

    public function test_partition_splits_the_result_set_on_the_condition(): void
    {
        $this->search->filter(['categories.category_id' => ['in' => '5']]);
        $this->search->partition(['stocks.1' => ['gt' => 0]]);

        $this->assertSame('categories.category_id IN [5] AND stocks.1 > 0', $this->partitionFilter(true));
        $this->assertSame('categories.category_id IN [5] AND NOT (stocks.1 > 0)', $this->partitionFilter(false));
    }

    public function test_partition_applies_on_its_own_without_a_base_filter(): void
    {
        $this->search->partition(['stocks.1' => ['gt' => 0]]);

        $this->assertSame('stocks.1 > 0', $this->partitionFilter(true));
        $this->assertSame('NOT (stocks.1 > 0)', $this->partitionFilter(false));
    }

    public function test_partition_accepts_a_disjunction_across_several_fields(): void
    {
        $this->search->partition(['or' => ['stocks.1' => ['gt' => 0], 'stocks.2' => ['gt' => 0]]]);

        $this->assertSame('(stocks.1 > 0 OR stocks.2 > 0)', $this->partitionFilter(true));
        $this->assertSame('NOT ((stocks.1 > 0 OR stocks.2 > 0))', $this->partitionFilter(false));
    }

    public function test_sort_runs_the_filterable_resolvers_declared_on_the_model(): void
    {
        $this->withModel(new class () extends Model {
            public function filterableResolvers(): array
            {
                return [
                    new class () implements \Jurager\Filterable\Contracts\SortResolver {
                        public function resolve(object $query, string $field, string $direction, Model $model, array $context = []): bool
                        {
                            if ($field !== 'in_stock' || ! $query instanceof Builder) {
                                return false;
                            }

                            $query->partition(['stocks.1' => ['gt' => 0]], first: $direction === 'asc');

                            return true;
                        }
                    },
                ];
            }
        });

        $this->search->sort('in_stock');
        $this->assertTrue($this->search->partitionsFirst(), 'in_stock puts matches first');

        $this->search->sort('-in_stock');
        $this->assertFalse($this->search->partitionsFirst(), '-in_stock puts matches last');
    }

    public function test_sort_hands_included_constraints_to_the_resolver_as_context(): void
    {
        $resolver = new class () implements \Jurager\Filterable\Contracts\SortResolver {
            public array $seen = [];

            public function resolve(object $query, string $field, string $direction, Model $model, array $context = []): bool
            {
                $this->seen = $context;

                return false;
            }
        };

        $this->withModel(new class () extends Model {
            public static array $resolvers = [];

            public function filterableResolvers(): array
            {
                return self::$resolvers;
            }
        });

        $this->search->getModel()::$resolvers = [$resolver];

        $this->search->filter(['included.stocks.warehouse_id' => ['in' => '1,2']]);
        $this->search->sort('in_stock');

        $this->assertSame(['stocks.warehouse_id' => ['in' => '1,2']], $resolver->seen);
    }

    public function test_a_sort_field_no_resolver_claims_is_ignored(): void
    {
        $this->withModel(new class () extends Model {
        });

        $this->search->sort('-whatever');

        $this->assertNull($this->search->getPartition());
    }

    public function test_no_partition_leaves_the_filter_untouched(): void
    {
        $this->search->filter(['categories.category_id' => ['in' => '5']]);

        $this->assertNull($this->search->getPartition());
        $this->assertTrue($this->search->partitionsFirst());
    }
}
