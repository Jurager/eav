<?php

declare(strict_types=1);

namespace Jurager\Eav\Builders\Translator;

use Illuminate\Database\Eloquent\Model;
use Jurager\Eav\Exceptions\FluentBuilderException;
use Jurager\Eav\Managers\TranslationManager;
use Jurager\Eav\Registry\LocaleRegistry;

/** Fluent builder for the translated labels of a single model. */
class LabelBuilder
{
    /** @var list<array{locale_id: int, label: string}> */
    private array $translations = [];

    private bool $partial = false;

    public function __construct(
        private readonly TranslationManager $manager,
        private readonly LocaleRegistry $locales,
        private readonly Model $model,
    ) {
    }

    /** Queue a translated label for the given locale code. */
    public function label(string $label, string $locale): static
    {
        $localeId = $this->locales->find($locale) ?? throw FluentBuilderException::unknownLocale($locale);

        $this->translations[] = ['locale_id' => $localeId, 'label' => $label];

        return $this;
    }

    /**
     * Queue labels already shaped as `[{locale_id, label}, ...]` — e.g. from an
     * already-validated array.
     *
     * @param  list<array{locale_id: int, label: string}>  $translations
     */
    public function fill(array $translations): static
    {
        $this->translations = [...$this->translations, ...$translations];

        return $this;
    }

    /** Keep locales not present in the queued labels instead of removing them. */
    public function partial(bool $partial = true): static
    {
        $this->partial = $partial;

        return $this;
    }

    /**
     * Export the model and its queued labels without persisting them — used to
     * collect several builders for `Translator::batch()`.
     *
     * @return array{0: Model, 1: list<array{locale_id: int, label: string}>}
     */
    public function build(): array
    {
        return [$this->model, $this->translations];
    }

    /** Persist the queued labels for the model. */
    public function save(): void
    {
        $this->manager->save($this->model, $this->translations, $this->partial);
    }
}
