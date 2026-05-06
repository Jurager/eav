<?php

declare(strict_types=1);

namespace Jurager\Eav\Tests\Unit\Fields;

use Jurager\Eav\Fields\Field;
use Jurager\Eav\Fields\LinkField;
use Jurager\Eav\Models\Attribute;
use Jurager\Eav\Registry\LocaleRegistry;
use Jurager\Eav\Tests\TestCase;
use Mockery;

class LinkFieldTest extends TestCase
{
    private LocaleRegistry $localeRegistry;

    protected function setUp(): void
    {
        parent::setUp();

        $this->localeRegistry = Mockery::mock(LocaleRegistry::class);
        $this->localeRegistry->shouldReceive('has')->andReturn(true);
        $this->localeRegistry->shouldReceive('default')->andReturn(1);
    }

    private function makeAttribute(array $attributes = []): Attribute
    {
        return (new Attribute())->forceFill(array_merge([
            'code'        => 'website',
            'localizable' => false,
            'multiple'    => false,
            'mandatory'   => false,
            'unique'      => false,
            'filterable'  => false,
            'searchable'  => false,
            'validations' => null,
        ], $attributes));
    }

    private function makeField(array $attributes = []): LinkField
    {
        return new LinkField($this->makeAttribute($attributes), $this->localeRegistry);
    }

    // -----------------------------------------------------------------------
    // column()
    // -----------------------------------------------------------------------

    public function test_column_returns_value_text(): void
    {
        $this->assertSame(Field::STORAGE_TEXT, $this->makeField()->column());
    }

    // -----------------------------------------------------------------------
    // fill() — valid URLs
    // -----------------------------------------------------------------------

    public function test_fill_accepts_null(): void
    {
        $field = $this->makeField();

        $this->assertTrue($field->fill(null));
        $this->assertFalse($field->hasErrors());
    }

    public function test_fill_accepts_https_url(): void
    {
        $field = $this->makeField();

        $this->assertTrue($field->fill('https://example.com'));
        $this->assertFalse($field->hasErrors());
    }

    public function test_fill_accepts_http_url(): void
    {
        $field = $this->makeField();

        $this->assertTrue($field->fill('http://example.com/path?q=1'));
        $this->assertFalse($field->hasErrors());
    }

    public function test_fill_accepts_url_with_path_and_query(): void
    {
        $field = $this->makeField();

        $this->assertTrue($field->fill('https://example.com/page?foo=bar&baz=1'));
        $this->assertFalse($field->hasErrors());
    }

    // -----------------------------------------------------------------------
    // fill() — invalid URLs
    // -----------------------------------------------------------------------

    public function test_fill_rejects_ftp_scheme(): void
    {
        $field = $this->makeField();

        $this->assertFalse($field->fill('ftp://example.com'));
        $this->assertTrue($field->hasErrors());
    }

    public function test_fill_rejects_plain_string(): void
    {
        $field = $this->makeField();

        $this->assertFalse($field->fill('not-a-url'));
        $this->assertTrue($field->hasErrors());
    }

    public function test_fill_rejects_relative_path(): void
    {
        $field = $this->makeField();

        $this->assertFalse($field->fill('/relative/path'));
        $this->assertTrue($field->hasErrors());
    }

    public function test_fill_rejects_non_string(): void
    {
        $field = $this->makeField();

        $this->assertFalse($field->fill(42));
        $this->assertTrue($field->hasErrors());
    }

    // -----------------------------------------------------------------------
    // value() / set() / forget()
    // -----------------------------------------------------------------------

    public function test_value_returns_null_when_empty(): void
    {
        $this->assertNull($this->makeField()->value());
    }

    public function test_value_returns_stored_url(): void
    {
        $field = $this->makeField();
        $field->fill('https://example.com');

        $this->assertSame('https://example.com', $field->value());
    }

    public function test_set_stores_url(): void
    {
        $field = $this->makeField();
        $field->set('https://example.com');

        $this->assertSame('https://example.com', $field->value());
    }

    public function test_forget_clears_value(): void
    {
        $field = $this->makeField();
        $field->fill('https://example.com');
        $field->forget();

        $this->assertNull($field->value());
    }
}
