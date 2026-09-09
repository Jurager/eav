<?php

declare(strict_types=1);

namespace Jurager\Eav\Media;

use Jurager\Eav\Contracts\Attributable;
use Jurager\Media\Models\Media;

/** Clears the attribute value pointing at a Media record before it's deleted — wired by EavServiceProvider. */
class MediaObserver
{
    public function deleting(Media $media): void
    {
        $mediable = $media->getAttribute('mediable');

        if (! $mediable instanceof Attributable) {
            return;
        }

        $field = $mediable->eav()->field($media->getAttribute('collection_name'));

        if (! $field instanceof MediaField) {
            return;
        }

        $mediable->attributeValues()
            ->where('attribute_id', $field->attribute()->getAttribute('id'))
            ->where('value_integer', $media->getAttribute('id'))
            ->delete();
    }
}
