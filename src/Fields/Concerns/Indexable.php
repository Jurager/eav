<?php

declare(strict_types=1);

namespace Jurager\Eav\Fields\Concerns;

/**
 * Trait providing search index and facet support for fields.
 *
 * @phpstan-require-extends \Jurager\Eav\Fields\Field
 */
trait Indexable
{
    /** Get attribute-level keys this field contributes to filterableAttributes. */
    public function filterableKeys(): array
    {
        return [$this->code()];
    }

    /**
     * Enrich a raw Meilisearch facet distribution with display labels.
     *
     * @param  array<string, int>  $distribution
     * @return array<string, array{count: int, label: string}>|array<string, int>
     */
    public function enrichFacetDistribution(array $distribution, ?int $localeId = null): array
    {
        return $distribution;
    }

    /** Get data for the search index. */
    public function indexData(): array
    {
        $code = $this->code();

        if (! $this->isLocalizable()) {
            $value = $this->value();

            return $value !== null ? [$code => $value] : [];
        }

        $values = array_values(array_filter(
            array_column($this->values, 'value'),
            static fn ($v) => $v !== null && $v !== ''
        ));

        return ! empty($values) ? [$code => $values] : [];
    }
}
