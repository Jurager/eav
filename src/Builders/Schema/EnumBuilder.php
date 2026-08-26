<?php

declare(strict_types=1);

namespace Jurager\Eav\Builders\Schema;

use Illuminate\Support\Fluent;
use Jurager\Eav\Exceptions\FluentBuilderException;
use Jurager\Eav\Managers\Schema\EnumSchema;
use Jurager\Eav\Models\Attribute;
use Jurager\Eav\Models\AttributeEnum;
use Jurager\Eav\Registry\LocaleRegistry;

/**
 * Fluent builder for a single attribute enum (select option) definition.
 *
 * @method $this sort(int $position)
 */
class EnumBuilder extends Fluent
{
    /** @var list<array{locale_id: int, label: string}> */
    private array $translations = [];

    public function __construct(
        private readonly EnumSchema $schema,
        private readonly LocaleRegistry $locales,
        private readonly AttributeEnum|Attribute $subject,
        private readonly ?string $code = null,
    ) {
        if ($subject instanceof Attribute && $code === null) {
            throw FluentBuilderException::missingCode();
        }

        parent::__construct();
    }

    /** Queue a translated label for the given locale code. */
    public function label(string $label, string $locale): static
    {
        $localeId = $this->locales->find($locale) ?? throw FluentBuilderException::unknownLocale($locale);

        $this->translations[] = ['locale_id' => $localeId, 'label' => $label];

        return $this;
    }

    /** Export the queued enum option as a data array, without persisting it. */
    public function build(): array
    {
        if (! $this->subject instanceof Attribute) {
            throw FluentBuilderException::cannotCreateFromExisting();
        }

        return [
            'attribute_id' => $this->subject->id,
            'code' => $this->code,
            'translations' => $this->translations,
            ...$this->toArray(),
        ];
    }

    /** Persist a new enum option. Requires the builder to have been constructed with an attribute and a code. */
    public function create(): AttributeEnum
    {
        $enum = $this->schema->create($this->subject, $this->build());

        return $enum->refresh();
    }

    /** Apply the queued changes to an existing enum option. */
    public function update(): AttributeEnum
    {
        $enum = $this->schema->update($this->existing(), [
            'translations' => $this->translations,
            ...$this->toArray(),
        ]);

        return $enum->refresh();
    }

    /** Delete the enum option. */
    public function delete(): void
    {
        $this->schema->delete($this->existing());
    }

    /** Resolve the builder's subject as an existing enum option, or fail. */
    private function existing(): AttributeEnum
    {
        return $this->subject instanceof AttributeEnum
            ? $this->subject
            : throw FluentBuilderException::cannotUpdateWithoutExisting();
    }
}
