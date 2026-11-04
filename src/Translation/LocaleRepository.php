<?php

declare(strict_types=1);

namespace Jurager\Eav\Translation;

use Illuminate\Database\Eloquent\Builder;
use Jurager\Eav\Eav;
use Jurager\Eav\Models\Locale;

/**
 * Creates, edits and deletes locales; the locale observer keeps the registry fresh.
 */
class LocaleRepository
{
    /** @return Builder<Locale> */
    public function query(): Builder
    {
        return Eav::$localeModel::query();
    }

    public function find(int $id): Locale
    {
        return $this->query()->findOrFail($id);
    }

    public function create(array $data): Locale
    {
        return $this->query()->create($data);
    }

    public function update(Locale $locale, array $data): Locale
    {
        $locale->update($data);

        return $locale;
    }

    public function delete(Locale $locale): void
    {
        $locale->delete();
    }
}
