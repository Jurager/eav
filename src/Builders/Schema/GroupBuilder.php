<?php

declare(strict_types=1);

namespace Jurager\Eav\Builders\Schema;

use Illuminate\Support\Fluent;
use Jurager\Eav\Exceptions\FluentBuilderException;
use Jurager\Eav\Managers\Schema\GroupSchema;
use Jurager\Eav\Models\AttributeGroup;
use Jurager\Eav\Registry\LocaleRegistry;

/**
 * Fluent builder for a single attribute group definition.
 *
 * @method $this sort(int $position)
 */
class GroupBuilder extends Fluent
{
    /** @var list<array{locale_id: int, label: string}> */
    private array $translations = [];

    public function __construct(
        private readonly GroupSchema $schema,
        private readonly LocaleRegistry $locales,
        private readonly AttributeGroup|string $subject,
    ) {
        parent::__construct();
    }

    /** Queue a translated label for the given locale code. */
    public function label(string $label, string $locale): static
    {
        $localeId = $this->locales->find($locale) ?? throw FluentBuilderException::unknownLocale($locale);

        $this->translations[] = ['locale_id' => $localeId, 'label' => $label];

        return $this;
    }

    /**
     * Export the queued group as a data array, without persisting it.
     *
     * Requires the builder to have been constructed with a code.
     *
     * @return array<string, mixed>
     */
    public function build(): array
    {
        if (! is_string($this->subject)) {
            throw FluentBuilderException::cannotCreateFromExisting();
        }

        return [
            'code' => $this->subject,
            'translations' => $this->translations,
            ...$this->toArray(),
        ];
    }

    /** Persist a new group. Requires the builder to have been constructed with a code. */
    public function create(): AttributeGroup
    {
        return $this->schema->create($this->build())->refresh();
    }

    /** Apply the queued changes to an existing group. */
    public function update(): AttributeGroup
    {
        return $this->schema->update($this->existing(), [
            'translations' => $this->translations,
            ...$this->toArray(),
        ])->refresh();
    }

    /** Delete the group. */
    public function delete(): void
    {
        $this->schema->delete($this->existing());
    }

    /** Move the group to a zero-based position, renumbering its siblings. */
    public function moveTo(int $position): AttributeGroup
    {
        return $this->schema->sort($this->existing(), $position);
    }

    /** Assign attribute IDs to the group without affecting other rows. */
    public function attach(array $attributeIds): void
    {
        $this->schema->attach($this->existing(), $attributeIds);
    }

    /** Resolve the builder's subject as an existing group, or fail. */
    private function existing(): AttributeGroup
    {
        return $this->subject instanceof AttributeGroup
            ? $this->subject
            : throw FluentBuilderException::cannotUpdateWithoutExisting();
    }
}
