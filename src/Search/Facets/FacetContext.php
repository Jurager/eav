<?php

namespace Jurager\Eav\Search\Facets;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Jurager\Eav\Fields\Field;
use Jurager\Eav\Fields\FieldFactory;

/**
 * Carries the EAV dependencies a facet needs while collecting its distribution,
 * so facets stay plain declarative value objects (no container wiring of their own).
 */
final class FacetContext
{
    /** @var Collection<string, Model> Filterable attributes keyed by code. */
    private Collection $attributes;

    /**
     * @param  Collection<int, Model>  $attributes
     */
    public function __construct(
        Collection $attributes,
        private readonly FieldFactory $fieldFactory,
        public readonly ?int $localeId = null,
    ) {
        $this->attributes = $attributes->keyBy('code');
    }

    public function attribute(string $code): ?Model
    {
        return $this->attributes->get($code);
    }

    public function field(Model $attribute): Field
    {
        return $this->fieldFactory->make($attribute);
    }
}
