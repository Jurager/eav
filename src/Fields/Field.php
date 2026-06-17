<?php

namespace Jurager\Eav\Fields;

use Illuminate\Support\Collection;
use Jurager\Eav\Contracts\Attributable;
use Jurager\Eav\Fields\Concerns\Indexable;
use Jurager\Eav\Fields\Concerns\ValidatesPayload;
use Jurager\Eav\Models\Attribute;
use Jurager\Eav\Registry\EnumRegistry;
use Jurager\Eav\Registry\LocaleRegistry;

/**
 * Base attribute field.
 */
abstract class Field
{
    use ValidatesPayload;
    use Indexable;

    public const string STORAGE_TEXT = 'value_text';
    public const string STORAGE_INTEGER = 'value_integer';
    public const string STORAGE_FLOAT = 'value_float';
    public const string STORAGE_BOOLEAN = 'value_boolean';
    public const string STORAGE_DATE = 'value_date';
    public const string STORAGE_DATETIME = 'value_datetime';

    /** @var array<int, array{locale_id: int|null, value: mixed}> */
    protected array $values = [];

    /**
     * Bound entity context — set by AttributeManager, null in schema-only managers.
     */
    protected ?Attributable $entity = null;

    public function __construct(
        protected Attribute $attribute,
        protected LocaleRegistry $localeRegistry,
        protected EnumRegistry $enumRegistry,
    ) {
    }

    abstract public function column(): string;

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

    /** Returns false when validation fails; errors are available via errors(). */
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

    /**
     * @param  Collection<int, object>  $models  entity_attribute rows.
     */
    public function hydrate(Collection $models): void
    {
        if ($models->isEmpty()) {
            $this->values = [];

            return;
        }

        if (! $this->isLocalizable()) {
            $values = $models->map(fn ($model) => $this->read($model))->all();

            $this->values = [[
                'locale_id' => null,
                'value' => $this->isMultiple() ? $values : $values[0],
            ]];

            return;
        }

        $byLocale = [];
        foreach ($models as $model) {
            foreach ($model->translations as $translation) {
                $byLocale[$translation->id][] = $translation->pivot->label;
            }
        }

        $this->values = collect($byLocale)->map(fn ($values, $localeId) => [
            'locale_id' => $localeId,
            'value' => $this->isMultiple() ? $values : $values[0],
        ])->values()->all();
    }

    /**
     * @return array<int, array{value: mixed, translations: array}>
     */
    public function toStorage(): array
    {
        if (! $this->isLocalizable()) {
            $value = $this->values[0]['value'] ?? null;
            $items = is_array($value) ? $value : [$value];

            return array_map(static fn ($v) => ['value' => $v, 'translations' => []], $items);
        }

        $maxCount = 1;
        foreach ($this->values as $item) {
            if (is_array($item['value'])) {
                $maxCount = max($maxCount, count($item['value']));
            }
        }

        $result = [];
        for ($i = 0; $i < $maxCount; $i++) {
            $translations = [];
            foreach ($this->values as $item) {
                $values = is_array($item['value']) ? $item['value'] : [$item['value']];

                if (isset($values[$i])) {
                    $translations[] = ['locale_id' => $item['locale_id'], 'value' => $values[$i]];
                }
            }

            $result[] = ['value' => null, 'translations' => $translations];
        }

        return $result;
    }

    public function value(?int $localeId = null): mixed
    {
        if (empty($this->values)) {
            return null;
        }

        if (! $this->isLocalizable()) {
            return $this->resolveValue($this->values[0]['value'] ?? null);
        }

        $localeId ??= $this->localeRegistry->default();
        $key = array_search($localeId, array_column($this->values, 'locale_id'), true);

        return $key !== false ? $this->resolveValue($this->values[$key]['value']) : null;
    }

    public function set(mixed $value, ?int $localeId = null): void
    {
        $normalized = $this->normalize($value);
        $localeId = $this->isLocalizable()
            ? ($localeId ?? $this->localeRegistry->default())
            : null;

        $key = array_search($localeId, array_column($this->values, 'locale_id'), true);

        if ($key !== false) {
            $this->values[$key]['value'] = $normalized;

            return;
        }

        $this->values[] = ['locale_id' => $localeId, 'value' => $normalized];
    }

    /** Remove the value for a specific locale, or all values for non-localized fields. */
    public function forget(?int $localeId = null): void
    {
        if ($localeId === null || ! $this->isLocalizable()) {
            $this->values = [];

            return;
        }

        $this->values = array_values(array_filter(
            $this->values,
            static fn (array $item) => $item['locale_id'] !== $localeId,
        ));
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
        return $this->attribute->code;
    }

    public function isLocalizable(): bool
    {
        return $this->attribute->localizable;
    }

    public function isMultiple(): bool
    {
        return $this->attribute->multiple;
    }

    public function isRequired(): bool
    {
        return $this->attribute->required;
    }

    public function isUnique(): bool
    {
        return $this->attribute->unique;
    }

    public function isFilterable(): bool
    {
        return $this->attribute->filterable ?? false;
    }

    public function isSearchable(): bool
    {
        return $this->attribute->searchable ?? false;
    }

    /** @return array<string, mixed> */
    public function toMetadata(): array
    {
        return [
            'code'       => $this->code(),
            'type'       => $this->attribute->type->code ?? null,
            'localizable' => $this->isLocalizable(),
            'multiple'   => $this->isMultiple(),
            'required'   => $this->isRequired(),
            'unique'     => $this->isUnique(),
            'filterable' => $this->isFilterable(),
            'searchable' => $this->isSearchable(),
        ];
    }

    public function read(object $model): mixed
    {
        return $model->{$this->column()};
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
