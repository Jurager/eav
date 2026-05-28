<?php

namespace Jurager\Eav\Fields\Concerns;

/**
 * Search index and facet support for Field.
 *
 * @phpstan-require-extends \Jurager\Eav\Fields\Field
 */
trait Indexable
{
    /**
     * Attribute-level keys this field contributes to filterableAttributes.
     * Prefixed with "attributes." by SyncFilterable before sending to Meilisearch.
     */
    public function filterableKeys(): array
    {
        return [$this->code()];
    }

    /**
     * Enrich a raw Meilisearch facet distribution with display labels.
     * Default: pass through unchanged. Override in enum-backed fields.
     *
     * @param  array<string, int>  $distribution
     * @return array<string, array{count: int, label: string}>|array<string, int>
     */
    public function enrichFacetDistribution(array $distribution, ?int $localeId = null): array
    {
        return $distribution;
    }

    /** @return array<string, mixed> */
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

        return $values ? [$code => $values] : [];
    }
}
