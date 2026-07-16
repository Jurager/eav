<?php

declare(strict_types=1);

namespace Jurager\Eav\Tests\Unit\Search;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Jurager\Eav\Fields\FieldFactory;
use Jurager\Eav\Registry\LocaleRegistry;
use Jurager\Eav\Search\Contracts\InteractsWithIndex;
use Jurager\Eav\Search\FilterCompiler;
use Jurager\Eav\Search\Facets\FacetContext;
use Jurager\Eav\Search\Search;
use Jurager\Eav\Tests\TestCase;
use Meilisearch\Client;
use Mockery;
use ReflectionClass;

class SearchTest extends TestCase
{
    private Search $search;

    private FacetContext $context;

    protected function setUp(): void
    {
        parent::setUp();

        $this->search = new Search(
            new FilterCompiler(),
            Mockery::mock(FieldFactory::class),
            Mockery::mock(LocaleRegistry::class),
            Mockery::mock(Client::class),
        );

        $this->context = new FacetContext(new Collection(), Mockery::mock(FieldFactory::class));
    }

    /** Invokes the private resolver() closure factory via reflection — no public seam exists for it yet. */
    private function resolve(string $key, array $map = []): ?string
    {
        if ($map !== []) {
            $this->search->map($map);
        }

        $method = (new ReflectionClass($this->search))->getMethod('resolver');
        $method->setAccessible(true);

        $resolve = $method->invoke($this->search, $this->context);

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

    public function test_unknown_key_does_not_resolve_by_default(): void
    {
        $this->assertNull($this->resolve('sku'));
    }

    public function test_explicit_map_still_resolves_a_key(): void
    {
        $this->assertSame('category_ids', $this->resolve('categories.category_id', ['categories.category_id' => 'category_ids']));
    }

    public function test_a_model_implementing_interacts_with_index_supplies_its_own_map(): void
    {
        $this->withModel(new class () extends Model implements InteractsWithIndex {
            public function indexed(): array
            {
                return ['categories.category_id' => 'category_ids'];
            }

            public function narrow(array $ids, array $filter): array
            {
                return $ids;
            }
        });

        $this->assertSame('category_ids', $this->resolve('categories.category_id'));
    }

    public function test_a_model_not_implementing_interacts_with_index_supplies_nothing(): void
    {
        $this->withModel(new class () extends Model {
            //
        });

        $this->assertNull($this->resolve('categories.category_id'));
    }

    public function test_the_model_map_does_not_shadow_the_built_in_id_default(): void
    {
        $this->withModel(new class () extends Model implements InteractsWithIndex {
            public function indexed(): array
            {
                return ['id' => 'something_else'];
            }

            public function narrow(array $ids, array $filter): array
            {
                return $ids;
            }
        });

        // 'id' is resolved unconditionally before any contract/map lookup runs.
        $this->assertSame('id', $this->resolve('id'));
    }
}
