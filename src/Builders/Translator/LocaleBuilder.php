<?php

declare(strict_types=1);

namespace Jurager\Eav\Builders\Translator;

use Illuminate\Support\Fluent;
use Jurager\Eav\Exceptions\FluentBuilderException;
use Jurager\Eav\Managers\TranslationManager;
use Jurager\Eav\Models\Locale;

/**
 * Fluent builder for a single locale definition.
 *
 * @method $this name(string $name)
 */
class LocaleBuilder extends Fluent
{
    public function __construct(
        private readonly TranslationManager $manager,
        private readonly Locale|string $subject,
    ) {
        parent::__construct();
    }

    /** Persist a new locale. Requires the builder to have been constructed with a code. */
    public function create(): Locale
    {
        if (! is_string($this->subject)) {
            throw FluentBuilderException::cannotCreateFromExisting();
        }

        return $this->manager->create(['code' => $this->subject, ...$this->toArray()]);
    }

    /** Apply the queued changes to an existing locale. */
    public function update(): Locale
    {
        return $this->manager->update($this->existing(), $this->toArray());
    }

    /** Delete the locale. */
    public function delete(): void
    {
        $this->manager->delete($this->existing());
    }

    /** Resolve the builder's subject as an existing locale, or fail. */
    private function existing(): Locale
    {
        return $this->subject instanceof Locale
            ? $this->subject
            : throw FluentBuilderException::cannotUpdateWithoutExisting();
    }
}
