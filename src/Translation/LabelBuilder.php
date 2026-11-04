<?php

declare(strict_types=1);

namespace Jurager\Eav\Translation;

use Illuminate\Database\Eloquent\Model;
use Jurager\Eav\Exceptions\FluentBuilderException;
use Jurager\Eav\Registry\LocaleRegistry;

/**
 * Fluent builder for the translated labels of a single model.
 */
class LabelBuilder
{
    /** @var list<array{locale_id: int, label: string}> */
    private array $labels = [];

    private bool $partial = false;

    public function __construct(
        private readonly TranslationRepository $repository,
        private readonly LocaleRegistry $locales,
        private readonly Model $model,
    ) {}

    /**
     * Queue a translated label for the given locale code.
     */
    public function label(string $label, string $locale): static
    {
        $localeId = $this->locales->find($locale) ?? throw FluentBuilderException::unknownLocale($locale);

        $this->labels[] = ['locale_id' => $localeId, 'label' => $label];

        return $this;
    }

    /**
     * Queue labels from a validated translation array.
     *
     * @param  list<array{locale_id: int, label: string}>  $translations
     */
    public function fill(array $translations): static
    {
        $this->labels = [...$this->labels, ...$translations];

        return $this;
    }

    /**
     * Keep locales not present in the queued labels instead of removing them.
     */
    public function partial(bool $partial = true): static
    {
        $this->partial = $partial;

        return $this;
    }

    /**
     * Get the model and queued labels without saving them.
     *
     * @return array{0: Model, 1: list<array{locale_id: int, label: string}>}
     */
    public function build(): array
    {
        return [$this->model, $this->labels];
    }

    /**
     * Persist the queued labels for the model.
     */
    public function save(): void
    {
        $this->repository->save($this->model, $this->labels, $this->partial);
    }
}
