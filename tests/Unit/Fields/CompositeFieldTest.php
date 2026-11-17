<?php

declare(strict_types=1);

namespace Jurager\Eav\Tests\Unit\Fields;

use Jurager\Eav\Contracts\Attributable;
use Jurager\Eav\Enums\AttributeStorage;
use Jurager\Eav\Fields\Field;
use Jurager\Eav\Models\Attribute;
use Jurager\Eav\Registries\EnumRegistry;
use Jurager\Eav\Registries\LocaleRegistry;
use Jurager\Eav\Tests\TestCase;
use Mockery;

class CompositeFieldTest extends TestCase
{
    private function makeField(bool $multiple): Field
    {
        $attribute = (new Attribute)->forceFill([
            'code' => 'weight',
            'localizable' => false,
            'multiple' => $multiple,
            'required' => false,
            'unique' => false,
            'validations' => null,
        ]);

        $locales = Mockery::mock(LocaleRegistry::class);
        $locales->shouldReceive('default')->andReturn(1);

        // A single value is an object {amount, factor}; it is stored as amount * factor.
        return new class($attribute, $locales, Mockery::mock(EnumRegistry::class)) extends Field
        {
            public function column(): AttributeStorage
            {
                return AttributeStorage::Float;
            }

            protected function isComposite(): bool
            {
                return true;
            }

            protected function validate(mixed $value, ?Attributable $entity = null): bool
            {
                return is_array($value) && isset($value['amount'], $value['factor']) ?: $this->addError('invalid');
            }

            protected function normalize(mixed $value): float
            {
                return $value['amount'] * $value['factor'];
            }
        };
    }

    public function test_single_composite_value_is_normalized_as_one_value(): void
    {
        $field = $this->makeField(multiple: false);

        $this->assertTrue($field->fill(['amount' => 2500, 'factor' => 0.001]));
        $this->assertSame(2.5, $field->value());
        $this->assertSame([['value' => 2.5, 'translations' => []]], $field->toStorage());
    }

    public function test_multiple_composite_values_are_normalized_one_by_one(): void
    {
        $field = $this->makeField(multiple: true);

        $this->assertTrue($field->fill([['amount' => 2, 'factor' => 1], ['amount' => 500, 'factor' => 0.001]]));
        $this->assertSame([2.0, 0.5], $field->value());
        $this->assertSame([2.0, 0.5], array_column($field->toStorage(), 'value'));
    }
}
