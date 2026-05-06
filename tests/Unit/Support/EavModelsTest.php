<?php

declare(strict_types=1);

namespace Jurager\Eav\Tests\Unit\Support;

use Illuminate\Database\Eloquent\Builder;
use Jurager\Eav\Exceptions\InvalidConfigurationException;
use Jurager\Eav\Models\Attribute;
use Jurager\Eav\Models\AttributeEnum;
use Jurager\Eav\Models\EntityAttribute;
use Jurager\Eav\Models\Locale;
use Jurager\Eav\Support\EavModels;
use Jurager\Eav\Tests\TestCase;

class EavModelsTest extends TestCase
{
    public function test_has_returns_true_for_configured_key(): void
    {
        $this->assertTrue(EavModels::has('attribute'));
        $this->assertTrue(EavModels::has('locale'));
        $this->assertTrue(EavModels::has('attribute_enum'));
        $this->assertTrue(EavModels::has('entity_attribute'));
    }

    public function test_has_returns_false_for_unconfigured_key(): void
    {
        $this->assertFalse(EavModels::has('nonexistent_model'));
    }

    public function test_class_returns_fqcn_for_configured_key(): void
    {
        $this->assertSame(Attribute::class, EavModels::class('attribute'));
        $this->assertSame(Locale::class, EavModels::class('locale'));
        $this->assertSame(AttributeEnum::class, EavModels::class('attribute_enum'));
        $this->assertSame(EntityAttribute::class, EavModels::class('entity_attribute'));
    }

    public function test_class_throws_for_unconfigured_key(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        EavModels::class('nonexistent_model');
    }

    public function test_query_returns_eloquent_builder(): void
    {
        $this->assertInstanceOf(Builder::class, EavModels::query('attribute'));
        $this->assertInstanceOf(Builder::class, EavModels::query('locale'));
    }

    public function test_query_throws_for_unconfigured_key(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        EavModels::query('nonexistent_model');
    }

    public function test_make_returns_model_instance(): void
    {
        $model = EavModels::make('attribute');

        $this->assertInstanceOf(Attribute::class, $model);
    }

    public function test_make_returns_locale_instance(): void
    {
        $model = EavModels::make('locale');

        $this->assertInstanceOf(Locale::class, $model);
    }

    public function test_make_throws_for_unconfigured_key(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        EavModels::make('nonexistent_model');
    }
}
