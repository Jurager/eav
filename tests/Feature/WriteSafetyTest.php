<?php

declare(strict_types=1);

namespace Jurager\Eav\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Jurager\Eav\Attributes\Persistence\ValueWriter;
use Jurager\Eav\Eav;
use Jurager\Eav\Events\EntityValuesChanged;
use Jurager\Eav\Facades\Attributes;
use Jurager\Eav\Facades\Schema;
use Jurager\Eav\Models\AttributeType;
use Jurager\Eav\Models\EntityAttribute;
use Jurager\Eav\Models\EntityTranslation;
use Jurager\Eav\Models\Locale;
use Jurager\Eav\Registry\LocaleRegistry;
use Jurager\Eav\Tests\Fixtures\Product;
use RuntimeException;

/** Regressions of how attribute values are written, read back and validated. */
class WriteSafetyTest extends FeatureTestCase
{
    private AttributeType $text;

    private Locale $en;

    private Locale $de;

    protected function setUp(): void
    {
        parent::setUp();

        $this->en = $this->createLocale('en');
        $this->de = $this->createLocale('de');
        $this->text = $this->createAttributeType('text');
    }

    public function test_a_value_set_without_a_locale_is_read_back_in_the_same_locale(): void
    {
        $this->createAttribute($this->text, ['code' => 'name', 'localizable' => true]);
        $product = $this->createProduct();

        app(LocaleRegistry::class)->set(['de']);

        $product->eav()->set('name', 'Tisch');

        $this->assertSame('Tisch', $product->eav()->value('name'));
        $this->assertSame('Tisch', $product->eav()->value('name', $this->de->id));
        $this->assertNull($product->eav()->value('name', $this->en->id));
    }

    public function test_the_last_values_of_an_entity_in_a_batch_win(): void
    {
        $this->createAttribute($this->text, ['code' => 'color']);
        $product = $this->createProduct();

        Attributes::sync(collect([
            ['entity' => $product, 'data' => ['color' => 'red']],
            ['entity' => $product, 'data' => ['color' => 'blue']],
            ['entity' => $product, 'data' => ['color' => 'green']],
        ]));

        $this->assertSame('green', $product->fresh()->eav()->value('color'));
    }

    public function test_a_failed_write_leaves_nothing_behind(): void
    {
        $this->createAttribute($this->text, ['code' => 'name', 'localizable' => true]);
        $product = $this->createProduct();

        $fields = $product->eav()->fill(['name' => [['locale_id' => $this->en->id, 'values' => 'Desk']]]);

        // The translation insert fails after the value row was written.
        DB::listen(static function ($query): void {
            if (str_contains($query->sql, 'insert into "entity_translations"')) {
                throw new RuntimeException('disk full');
            }
        });

        try {
            $product->eav()->attach($fields->all());
        } catch (RuntimeException) {
        }

        $this->assertSame(0, EntityAttribute::query()->count(), 'The value row is rolled back with its translations.');
    }

    public function test_translations_of_new_localizable_rows_belong_to_their_rows(): void
    {
        $this->createAttribute($this->text, ['code' => 'name', 'localizable' => true]);
        $this->createAttribute($this->text, ['code' => 'tags', 'localizable' => true, 'multiple' => true]);
        $product = $this->createProduct();

        $product->eav()->attach($product->eav()->fill([
            'name' => [['locale_id' => $this->en->id, 'values' => 'Desk'], ['locale_id' => $this->de->id, 'values' => 'Tisch']],
            'tags' => [
                [['locale_id' => $this->en->id, 'values' => 'oak']],
                [['locale_id' => $this->en->id, 'values' => 'solid']],
            ],
        ])->all());

        $fresh = $product->fresh()->eav();

        $this->assertSame('Tisch', $fresh->value('name', $this->de->id));
        $this->assertSame(['oak', 'solid'], $fresh->value('tags', $this->en->id));
    }

    public function test_writes_run_in_a_transaction_on_the_connection_of_the_value_model(): void
    {
        config(['database.connections.values' => ['driver' => 'sqlite', 'database' => ':memory:']]);
        $model = Eav::$entityAttributeModel;
        Eav::$entityAttributeModel = ValuesOnOwnConnection::class;

        try {
            $levels = app(ValueWriter::class)->transaction(static fn (): array => [
                DB::connection('values')->transactionLevel(),
                DB::connection()->transactionLevel(),
            ]);
        } finally {
            Eav::$entityAttributeModel = $model;
        }

        $this->assertSame([1, 0], $levels);
    }

    public function test_the_bind_limit_follows_the_driver_unless_configured(): void
    {
        $limit = static fn (ValueWriter $writer): int => (fn (): int => $this->bindLimit())->call($writer);

        $driver = ['sqlite' => 32766, 'sqlsrv' => 2100][DB::getDriverName()] ?? 65535;

        $this->assertSame($driver, $limit(new ValueWriter));
        $this->assertSame(500, $limit(new ValueWriter(500)));
    }

    public function test_dropping_an_entity_type_removes_its_values_with_their_translations(): void
    {
        $attribute = $this->createAttribute($this->text, ['code' => 'label', 'localizable' => true, 'entity_types' => ['product', 'category']]);
        $product = $this->createProduct();
        $product->eav()->attach($product->eav()->fill(['label' => [['locale_id' => $this->en->id, 'values' => 'Desk']]])->all());

        $this->assertSame(1, EntityAttribute::query()->count());

        Event::fake([EntityValuesChanged::class]);

        Schema::attribute($attribute)->entity_types(['category'])->update();

        $this->assertSame(0, EntityAttribute::query()->count());
        $this->assertSame(0, EntityTranslation::query()->where('entity_type', (new EntityAttribute)->getMorphClass())->count());
        Event::assertDispatched(EntityValuesChanged::class, static fn (EntityValuesChanged $event) => $event->entityIds == [$product->getKey()]);
    }

    public function test_values_change_is_announced_only_once_the_transaction_commits(): void
    {
        $this->createAttribute($this->text, ['code' => 'color']);
        $product = $this->createProduct();
        $announced = 0;

        Event::listen(EntityValuesChanged::class, static function () use (&$announced): void {
            $announced++;
        });

        try {
            DB::transaction(function () use ($product): void {
                $product->eav()->set('color', 'red')->save('color');

                throw new RuntimeException('rolled back');
            });
        } catch (RuntimeException) {
        }

        $this->assertSame(0, $announced, 'Rolled-back values are not reindexed.');

        DB::transaction(fn () => $product->eav()->set('color', 'blue')->save('color'));

        $this->assertSame(1, $announced);
    }

    public function test_validate_returns_the_submitted_fields_and_leaves_the_entity_alone(): void
    {
        $this->createAttribute($this->text, ['code' => 'color']);
        $this->createAttribute($this->text, ['code' => 'size']);
        $product = $this->createProduct();
        $product->eav()->set('size', 'L')->save('size');

        $fields = $product->validate([['code' => 'color', 'values' => 'red']]);

        $this->assertSame(['color'], array_keys($fields));
        $this->assertNull($product->eav()->value('color'), 'Validation does not change the entity before attach().');

        $product->eav()->attach($fields);

        $this->assertSame('red', $product->eav()->value('color'));
        $this->assertSame('L', $product->fresh()->eav()->value('size'));
    }

    public function test_an_empty_code_list_reads_nothing_whether_values_are_loaded_or_not(): void
    {
        $this->createAttribute($this->text, ['code' => 'color']);
        $product = $this->createProduct();
        $product->eav()->set('color', 'red')->save('color');

        $this->assertCount(0, $product->fresh()->eav()->values([]));
        $this->assertCount(0, $product->fresh()->load('attribute_values')->eav()->values([]));
        $this->assertSame(0, $product->fresh()->eav()->values([], paginated: 10)->total());
    }

    public function test_replacing_with_nothing_removes_every_value(): void
    {
        $this->createAttribute($this->text, ['code' => 'color']);
        $product = $this->createProduct();
        $product->eav()->set('color', 'red')->save('color');

        $product->eav()->replace([]);

        $this->assertSame(0, EntityAttribute::query()->count());
    }

    public function test_like_matches_case_insensitively_and_escapes_wildcards(): void
    {
        $this->createAttribute($this->text, ['code' => 'sku']);

        foreach (['AB_10%', 'abX10y', 'other'] as $sku) {
            $this->createProduct($sku)->eav()->set('sku', $sku)->save('sku');
        }

        $this->assertSame(['AB_10%'], Product::query()->whereAttributeLike('sku', 'b_10%')->pluck('name')->all());
        $this->assertSame(['AB_10%', 'abX10y'], Product::query()->whereAttributeLike('sku', 'ab')->orderBy('name')->pluck('name')->all());
    }

    public function test_rule_messages_name_the_attribute(): void
    {
        $this->createAttribute($this->text, ['code' => 'sku', 'multiple' => true, 'validations' => [['type' => 'min_length', 'value' => 5]]]);

        $field = $this->createProduct()->eav()->field('sku');

        $this->assertFalse($field->fill(['ABCDE', 'AB', 'XY']));
        $this->assertCount(1, $field->errors(), 'One message for the rule, however many values break it.');
        $this->assertStringContainsString('sku', $field->errors()[0]);
    }

    public function test_attributes_are_ordered_only_when_asked(): void
    {
        $this->assertStringNotContainsString('order by', strtolower(\Jurager\Eav\Models\Attribute::query()->toSql()));
        $this->assertStringContainsString('order by', strtolower(\Jurager\Eav\Models\Attribute::query()->ordered()->toSql()));
    }
}


class ValuesOnOwnConnection extends EntityAttribute
{
    protected $connection = 'values';
}
