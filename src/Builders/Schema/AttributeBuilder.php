<?php

declare(strict_types=1);

namespace Jurager\Eav\Builders\Schema;

use Illuminate\Support\Fluent;
use Jurager\Eav\Eav;
use Jurager\Eav\Exceptions\FluentBuilderException;
use Jurager\Eav\Managers\Schema\AttributeSchema;
use Jurager\Eav\Models\Attribute;
use Jurager\Eav\Registry\AttributeTypeRegistry;
use Jurager\Eav\Registry\LocaleRegistry;

/**
 * Fluent builder for a single attribute definition.
 *
 * Boolean/scalar columns (required, unique, localizable, multiple, filterable,
 * searchable, sort, validations, meta, ...) are set dynamically — same mechanism
 * as Laravel's own `Schema::create()` column definitions — so any `Attribute`
 * column, including ones added by a consumer's own subclass, works without a
 * dedicated method here.
 *
 * @method $this required(bool $value = true)
 * @method $this unique(bool $value = true)
 * @method $this localizable(bool $value = true)
 * @method $this multiple(bool $value = true)
 * @method $this filterable(bool $value = true)
 * @method $this searchable(bool $value = true)
 * @method $this sort(int $position)
 * @method $this validations(array $rules)
 * @method $this meta(array $meta)
 */
class AttributeBuilder extends Fluent
{
    /** @var list<array{locale_id: int, label: string}> */
    private array $translations = [];

    public function __construct(
        private readonly AttributeSchema $schema,
        private readonly AttributeTypeRegistry $types,
        private readonly LocaleRegistry $locales,
        private readonly Attribute|string $subject,
        private readonly ?string $entityType = null,
    ) {
        if (is_string($subject) && $entityType === null) {
            throw FluentBuilderException::missingEntityType($subject);
        }

        parent::__construct();
    }

    /** Set the attribute type by its code (e.g. "text", "select"). */
    public function type(string $code): static
    {
        $type = $this->types->find($code) ?? throw FluentBuilderException::unknownType($code);

        return $this->set('attribute_type_id', $type->id);
    }

    /** Assign the attribute to a group by its code. */
    public function group(string $code): static
    {
        $group = Eav::$attributeGroupModel::query()->where('code', $code)->first();

        return $this->set('attribute_group_id', $group?->id ?? throw FluentBuilderException::unknownGroup($code));
    }

    /** Queue a translated label for the given locale code. */
    public function label(string $label, string $locale): static
    {
        $localeId = $this->locales->find($locale) ?? throw FluentBuilderException::unknownLocale($locale);

        $this->translations[] = ['locale_id' => $localeId, 'label' => $label];

        return $this;
    }

    /**
     * Export the queued attribute as a data array, without persisting it.
     *
     * Used to collect several builders for `Schema::batch()`. Requires the builder
     * to have been constructed with a code and entity type.
     *
     * @return array<string, mixed>
     */
    public function build(): array
    {
        if (! is_string($this->subject)) {
            throw FluentBuilderException::cannotCreateFromExisting();
        }

        if ($this->get('attribute_type_id') === null) {
            throw FluentBuilderException::missingType($this->subject);
        }

        return [
            'entity_type' => $this->entityType,
            'code' => $this->subject,
            'translations' => $this->translations,
            ...$this->toArray(),
        ];
    }

    /** Persist a new attribute. Requires the builder to have been constructed with a code and entity type. */
    public function create(): Attribute
    {
        $attribute = $this->schema->create($this->build());

        // Columns left unset above (e.g. boolean flags never called) only get their
        // value from the DB column default, which the in-memory instance won't see
        // until refreshed — avoids hardcoding a second copy of those defaults here.
        return $attribute->refresh();
    }

    /**
     * Persist a new attribute, or return the existing one for its entity type and code.
     *
     * For an existing attribute only translations are updated — other queued fields
     * are not applied. Requires the builder to have been constructed with a code
     * and entity type.
     */
    public function firstOrCreate(): Attribute
    {
        $data = $this->build();

        return $this->schema->findOrCreate($this->entityType, $data['code'], $data);
    }

    /** Apply the queued changes to an existing attribute. */
    public function update(): Attribute
    {
        $attribute = $this->schema->update($this->existing(), [
            'translations' => $this->translations,
            ...$this->toArray(),
        ]);

        return $attribute->refresh();
    }

    /** Delete the attribute. */
    public function delete(): void
    {
        $this->schema->delete($this->existing());
    }

    /** Move the attribute to a zero-based position within its group, renumbering its siblings. */
    public function moveTo(int $position): Attribute
    {
        return $this->schema->sort($this->existing(), $position);
    }

    /** Resolve the builder's subject as an existing attribute, or fail. */
    private function existing(): Attribute
    {
        return $this->subject instanceof Attribute
            ? $this->subject
            : throw FluentBuilderException::cannotUpdateWithoutExisting();
    }
}
