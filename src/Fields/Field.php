<?php

declare(strict_types=1);

namespace Jurager\Eav\Fields;

use Illuminate\Support\Collection;
use Jurager\Eav\Contracts\Attributable;
use Jurager\Eav\Enums\AttributeStorage;
use Jurager\Eav\Fields\Concerns\Indexable;
use Jurager\Eav\Fields\Concerns\ValidatesPayload;
use Jurager\Eav\Models\Attribute;
use Jurager\Eav\Models\EntityAttribute;
use Jurager\Eav\Registry\EnumRegistry;
use Jurager\Eav\Registry\LocaleRegistry;

/**
 * Base attribute field.
 */
abstract class Field
{
    use Indexable;
    use ValidatesPayload;

    /**
     * Key holding the value of a field that is not localizable.
     */
    protected const int UNLOCALIZED = 0;

    /**
     * Field values keyed by locale, with lists for multiple values.
     *
     * @var array<int, mixed>
     */
    protected array $values = [];

    /**
     * The entity this field belongs to, if any.
     */
    protected ?Attributable $entity = null;

    public function __construct(
        protected Attribute $attribute,
        protected LocaleRegistry $localeRegistry,
        protected EnumRegistry $enumRegistry,
    ) {}

    abstract public function column(): AttributeStorage;

    /**
     * Whether values of this field type may differ per locale.
     */
    public static function localizable(): bool
    {
        return true;
    }

    abstract protected function validate(mixed $value, ?Attributable $entity = null): bool;

    abstract protected function normalize(mixed $value): mixed;

    public function cast(mixed $value): mixed
    {
        return $value;
    }

    public function resolve(mixed $rawValue, ?Attributable $entity = null): mixed
    {
        return $rawValue;
    }

    /**
     * Relations on the owning entity needed to resolve this field's stored value.
     *
     * @return list<string>
     */
    public function eagerLoads(): array
    {
        return [];
    }

    /**
     * Order a stored value among the other values of this field; equal keys retain storage order.
     */
    public function sortOrder(EntityAttribute $value): int
    {
        return PHP_INT_MAX;
    }

    public function isEnum(): bool
    {
        return false;
    }

    public function forEntity(?Attributable $entity): static
    {
        $this->entity = $entity;

        return $this;
    }

    public function entity(): ?Attributable
    {
        return $this->entity;
    }

    /**
     * Returns false when validation fails; errors are available via errors().
     */
    public function fill(mixed $values): bool
    {
        $this->validationErrors = [];
        $this->values = [];

        if ($values === null) {
            return true;
        }

        if (! $this->validatePayload($values)) {
            return false;
        }

        $this->values = $this->normalizeValues($values);

        return true;
    }

    /** @param  Collection<int, object>  $models  entity_attribute rows. */
    public function hydrate(Collection $models): void
    {
        $this->values = [];

        if ($models->isEmpty()) {
            return;
        }

        if (! $this->isLocalizable()) {
            $values = $models->map(fn ($model) => $this->read($model))->all();
            $this->values[self::UNLOCALIZED] = $this->isMultiple() ? $values : $values[0];

            return;
        }

        $byLocale = [];

        foreach ($models as $model) {
            foreach ($model->translations as $locale) {
                $byLocale[(int) $locale->getKey()][] = $locale->pivot->getAttribute('label');
            }
        }

        foreach ($byLocale as $localeId => $values) {
            $this->values[$localeId] = $this->isMultiple() ? $values : $values[0];
        }
    }

    /**
     * Build storage rows for the field values and translations.
     *
     * @return list<array{value: mixed, translations: list<array{locale_id: int, value: mixed}>}>
     */
    public function toStorage(): array
    {
        if (! $this->isLocalizable()) {
            $value = $this->values[self::UNLOCALIZED] ?? null;

            return array_map(static fn (mixed $item): array => ['value' => $item, 'translations' => []], is_array($value) ? $value : [$value]);
        }

        $lists = array_map(static fn (mixed $value): array => is_array($value) ? array_values($value) : [$value], $this->values);
        $rows = [];

        for ($position = 0, $count = max([1, ...array_map(count(...), $lists)]); $position < $count; $position++) {
            $translations = [];

            foreach ($lists as $localeId => $list) {
                if (isset($list[$position])) {
                    $translations[] = ['locale_id' => $localeId, 'value' => $list[$position]];
                }
            }

            $rows[] = ['value' => null, 'translations' => $translations];
        }

        return $rows;
    }

    /**
     * Get the resolved value in the given or current locale.
     */
    public function value(?int $localeId = null): mixed
    {
        $slot = $this->slot($localeId);

        return array_key_exists($slot, $this->values) ? $this->resolveValue($this->values[$slot]) : null;
    }

    /**
     * Set a value or translation list in memory; null removes the value.
     */
    public function set(mixed $value, ?int $localeId = null): void
    {
        if ($localeId === null && $this->isLocalizable() && $this->isTranslationList($value)) {
            foreach ($value as $translation) {
                $this->set($translation['values'] ?? null, (int) $translation['locale_id']);
            }

            return;
        }

        // Null removes the value — of that locale for a localizable field.
        if ($value === null) {
            unset($this->values[$this->slot($localeId)]);

            return;
        }

        $this->values[$this->slot($localeId)] = $this->isMultiple() && is_array($value)
            ? array_map(fn (mixed $item) => $this->normalize($item), $value)
            : $this->normalize($value);
    }

    /**
     * Determine if the value is a list of locale translations.
     */
    public function isTranslationList(mixed $value): bool
    {
        return is_array($value)
            && $value !== []
            && array_is_list($value)
            && array_all($value, static fn (mixed $item): bool => is_array($item) && array_key_exists('locale_id', $item));
    }

    /**
     * Append values to a multiple-value field.
     */
    public function add(mixed $value, ?int $localeId = null): void
    {
        if (! $this->isMultiple()) {
            $this->set($value, $localeId);

            return;
        }

        $slot = $this->slot($localeId);
        $additions = array_map(fn (mixed $item) => $this->normalize($item), is_array($value) ? $value : [$value]);

        $this->values[$slot] = [...(array) ($this->values[$slot] ?? []), ...$additions];
    }

    /**
     * Remove the value for a specific locale, or all values for non-localized fields.
     */
    public function forget(?int $localeId = null): void
    {
        if ($localeId === null || ! $this->isLocalizable()) {
            $this->values = [];

            return;
        }

        unset($this->values[$localeId]);
    }

    /**
     * The key a value for the given locale is held under.
     */
    protected function slot(?int $localeId): int
    {
        return $this->isLocalizable() ? ($localeId ?? $this->localeRegistry->default()) : self::UNLOCALIZED;
    }

    public function has(?int $localeId = null): bool
    {
        return $this->value($localeId) !== null;
    }

    public function isFilled(): bool
    {
        return ! empty($this->values);
    }

    public function attribute(): Attribute
    {
        return $this->attribute;
    }

    public function code(): string
    {
        return $this->attribute->getAttribute('code');
    }

    /**
     * Determine if the attribute and field type allow localized values.
     */
    public function isLocalizable(): bool
    {
        return static::localizable() && (bool) $this->attribute->getAttribute('localizable');
    }

    public function isMultiple(): bool
    {
        return $this->attribute->getAttribute('multiple');
    }

    public function isRequired(): bool
    {
        return $this->attribute->getAttribute('required');
    }

    public function isUnique(): bool
    {
        return $this->attribute->getAttribute('unique');
    }

    public function isFilterable(): bool
    {
        return $this->attribute->getAttribute('filterable') ?? false;
    }

    public function isSearchable(): bool
    {
        return $this->attribute->getAttribute('searchable') ?? false;
    }

    /** @return array<string, mixed> */
    public function toMetadata(): array
    {
        return [
            'code' => $this->code(),
            'type' => $this->attribute->type?->getAttribute('code'),
            'localizable' => $this->isLocalizable(),
            'multiple' => $this->isMultiple(),
            'required' => $this->isRequired(),
            'unique' => $this->isUnique(),
            'filterable' => $this->isFilterable(),
            'searchable' => $this->isSearchable(),
        ];
    }

    public function read(object $model): mixed
    {
        return $model->getAttribute($this->column()->value);
    }

    protected function resolveValue(mixed $raw): mixed
    {
        if ($raw === null) {
            return null;
        }

        if (is_array($raw)) {
            return array_map(fn ($v) => $this->resolve($v, $this->entity), $raw);
        }

        return $this->resolve($raw, $this->entity);
    }
}
