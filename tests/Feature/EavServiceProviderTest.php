<?php

declare(strict_types=1);

namespace Jurager\Eav\Tests\Feature;

use Jurager\Eav\Managers\SchemaManager;
use Jurager\Eav\Managers\TranslationManager;
use Jurager\Eav\Registry\AttributeTypeRegistry;
use Jurager\Eav\Registry\EnumRegistry;
use Jurager\Eav\Registry\FieldTypeRegistry;
use Jurager\Eav\Registry\SchemaRegistry;
use Jurager\Eav\Support\AttributeInheritanceResolver;
use Jurager\Eav\Tests\TestCase;

class EavServiceProviderTest extends TestCase
{
    // -----------------------------------------------------------------------
    // Singleton bindings
    // -----------------------------------------------------------------------

    public function test_attribute_type_registry_is_singleton(): void
    {
        $a = app(AttributeTypeRegistry::class);
        $b = app(AttributeTypeRegistry::class);

        $this->assertSame($a, $b);
    }

    public function test_field_type_registry_is_singleton(): void
    {
        $a = app(FieldTypeRegistry::class);
        $b = app(FieldTypeRegistry::class);

        $this->assertSame($a, $b);
    }

    public function test_schema_registry_is_singleton(): void
    {
        $a = app(SchemaRegistry::class);
        $b = app(SchemaRegistry::class);

        $this->assertSame($a, $b);
    }

    public function test_enum_registry_is_singleton(): void
    {
        $a = app(EnumRegistry::class);
        $b = app(EnumRegistry::class);

        $this->assertSame($a, $b);
    }

    public function test_attribute_inheritance_resolver_is_singleton(): void
    {
        $a = app(AttributeInheritanceResolver::class);
        $b = app(AttributeInheritanceResolver::class);

        $this->assertSame($a, $b);
    }

    public function test_translation_manager_is_singleton(): void
    {
        $a = app(TranslationManager::class);
        $b = app(TranslationManager::class);

        $this->assertSame($a, $b);
    }

    public function test_schema_manager_is_singleton(): void
    {
        $a = app(SchemaManager::class);
        $b = app(SchemaManager::class);

        $this->assertSame($a, $b);
    }

    // -----------------------------------------------------------------------
    // Config
    // -----------------------------------------------------------------------

    public function test_eav_config_is_loaded(): void
    {
        $this->assertNotNull(config('eav'));
        $this->assertIsArray(config('eav.models'));
        $this->assertIsArray(config('eav.types'));
    }

    public function test_default_field_types_are_registered_in_config(): void
    {
        $types = config('eav.types');

        $this->assertArrayHasKey('text', $types);
        $this->assertArrayHasKey('textarea', $types);
        $this->assertArrayHasKey('number', $types);
        $this->assertArrayHasKey('date', $types);
        $this->assertArrayHasKey('boolean', $types);
        $this->assertArrayHasKey('select', $types);
    }

    public function test_default_models_are_registered_in_config(): void
    {
        $models = config('eav.models');

        $this->assertArrayHasKey('attribute', $models);
        $this->assertArrayHasKey('locale', $models);
        $this->assertArrayHasKey('attribute_type', $models);
        $this->assertArrayHasKey('attribute_enum', $models);
        $this->assertArrayHasKey('entity_attribute', $models);
        $this->assertArrayHasKey('entity_translation', $models);
    }

    // -----------------------------------------------------------------------
    // Database tables
    // -----------------------------------------------------------------------

    public function test_migrations_create_locales_table(): void
    {
        $this->assertTrue(
            \Illuminate\Support\Facades\Schema::hasTable('locales')
        );
    }

    public function test_migrations_create_attributes_table(): void
    {
        $this->assertTrue(
            \Illuminate\Support\Facades\Schema::hasTable('attributes')
        );
    }

    public function test_migrations_create_entity_attribute_table(): void
    {
        $this->assertTrue(
            \Illuminate\Support\Facades\Schema::hasTable('entity_attribute')
        );
    }

    public function test_migrations_create_entity_translations_table(): void
    {
        $this->assertTrue(
            \Illuminate\Support\Facades\Schema::hasTable('entity_translations')
        );
    }

    public function test_migrations_create_attribute_types_table(): void
    {
        $this->assertTrue(
            \Illuminate\Support\Facades\Schema::hasTable('attribute_types')
        );
    }

    public function test_migrations_create_attribute_enums_table(): void
    {
        $this->assertTrue(
            \Illuminate\Support\Facades\Schema::hasTable('attribute_enums')
        );
    }
}
