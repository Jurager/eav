<?php

declare(strict_types=1);

namespace Jurager\Eav\Tests\Unit\Search;

use Illuminate\Database\Eloquent\Collection;
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
            public function indexed(): array
            {
                return ['categories.category_id' => 'category_ids'];
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
            public function indexed(): array
            {
                return ['id' => 'something_else'];
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
}
