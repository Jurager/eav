<?php

declare(strict_types=1);

namespace Jurager\Eav\Media;

use Jurager\Eav\Contracts\Attributable;
use Jurager\Eav\Fields\FieldFactory;
use Jurager\Media\Contracts\DynamicMediaCollectionResolver;
use Jurager\Media\Contracts\InteractsWithMedia;
use Jurager\Media\MediaCollection;

/** Resolves media collections backed by MediaField attributes — registered globally by EavServiceProvider. */
class MediaCollectionResolver implements DynamicMediaCollectionResolver
{
    public function __construct(protected FieldFactory $fields)
    {
    }

    public function resolve(object $model, string $name): ?MediaCollection
    {
        if (! $model instanceof Attributable || ! $model instanceof InteractsWithMedia) {
            return null;
        }

        $field = $model->eav()->field($name);

        return $field instanceof MediaField ? $field->mediaCollection($model) : null;
    }

    public function names(object $model): array
    {
        if (! $model instanceof Attributable) {
            return [];
        }

        return $this->fields->using(MediaField::class, $model->getEntityType())->pluck('code')->all();
    }
}
