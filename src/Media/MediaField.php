<?php

declare(strict_types=1);

namespace Jurager\Eav\Media;

use Jurager\Eav\Contracts\Attributable;
use Jurager\Eav\Enums\AttributeStorage;
use Jurager\Eav\Fields\File;
use Jurager\Media\Contracts\InteractsWithMedia;
use Jurager\Media\Conversions\Conversion;
use Jurager\Media\MediaCollection;
use Jurager\Media\Models\Media;

/** File/image attribute backed by a jurager/media record — stores media.id in value_integer. */
class MediaField extends File
{
    final public function column(): AttributeStorage
    {
        return AttributeStorage::Integer;
    }

    protected function validate(mixed $value, ?Attributable $entity = null): bool
    {
        if ($value === null || $value === '') {
            return true;
        }

        if (! is_numeric($value)) {
            return $this->addError(__('eav::attributes.validation.invalid_value'));
        }

        if (! $entity instanceof InteractsWithMedia) {
            return true;
        }

        if (! $entity->getMedia($this->code())->contains('id', (int) $value)) {
            return $this->addError(__('eav::attributes.validation.invalid_value'));
        }

        return true;
    }

    protected function normalize(mixed $value): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }

        $normalized = parent::normalize($value);

        return is_array($normalized)
            ? array_map(static fn (mixed $id): int => (int) $id, $normalized)
            : (int) $normalized;
    }

    public function resolve(mixed $rawValue, ?Attributable $entity = null): mixed
    {
        if ($rawValue === null || ! $entity instanceof InteractsWithMedia) {
            return null;
        }

        return $entity->getMedia($this->code())->firstWhere('id', (int) $rawValue);
    }

    public function indexData(): array
    {
        if ($this->isLocalizable()) {
            return [];
        }

        $value = $this->value();

        return $value === null ? [] : [$this->code() => $this->urlFor($value)];
    }

    public function mediaCollection(InteractsWithMedia $model): MediaCollection
    {
        $meta = $this->attribute->getAttribute('meta') ?? [];
        $collection = new MediaCollection($this->code());

        $this->applyConstraints($collection, $meta);
        $this->applyFallbackUrls($collection, $meta);
        $this->applyConversions($collection, $meta, $model);

        return $collection;
    }

    protected function applyConstraints(MediaCollection $collection, array $meta): void
    {
        if (! $this->attribute->getAttribute('multiple')) {
            $collection->singleFile();
        } elseif ((int) ($meta['max_count'] ?? 0) > 0) {
            $collection->onlyKeepLatest((int) $meta['max_count']);
        }

        if (! empty($meta['mime_types'])) {
            $collection->acceptsMimeTypes((array) $meta['mime_types']);
        }

        if (! empty($meta['max_size'])) {
            $collection->maxFileSize((int) $meta['max_size']);
        }
    }

    protected function applyFallbackUrls(MediaCollection $collection, array $meta): void
    {
        $fallback = $meta['fallback_url'] ?? null;

        if (is_array($fallback)) {
            foreach ($fallback as $conversion => $url) {
                if (is_string($url) && $url !== '') {
                    $collection->useFallbackUrl($url, $conversion === 'default' ? '' : (string) $conversion);
                }
            }

            return;
        }

        if (is_string($fallback) && $fallback !== '') {
            $collection->useFallbackUrl($fallback);
        }
    }

    protected function applyConversions(MediaCollection $collection, array $meta, InteractsWithMedia $model): void
    {
        $conversions = $meta['conversions'] ?? [];

        if (empty($conversions) || ! is_array($conversions)) {
            return;
        }

        $collection->withConversions(function (Media $media) use ($conversions, $model): void {
            foreach ($conversions as $config) {
                if (is_array($config) && ! empty($config['name'])) {
                    $this->applyConversionConfig($model->addMediaConversion($config['name']), $config);
                }
            }
        });
    }

    protected function applyConversionConfig(Conversion $conversion, array $config): void
    {
        if (isset($config['fit']) && is_array($config['fit']) && count($config['fit']) === 2) {
            $conversion->fit((int) $config['fit'][0], (int) $config['fit'][1]);
        } elseif (isset($config['contain']) && is_array($config['contain']) && count($config['contain']) === 2) {
            $conversion->contain((int) $config['contain'][0], (int) $config['contain'][1]);
        } else {
            if (isset($config['width'])) {
                $conversion->width((int) $config['width']);
            }

            if (isset($config['height'])) {
                $conversion->height((int) $config['height']);
            }
        }

        if (isset($config['quality'])) {
            $conversion->quality((int) $config['quality']);
        }

        if (isset($config['format']) && is_string($config['format']) && $config['format'] !== '') {
            $conversion->format($config['format']);
        }

        if (($config['queued'] ?? true) === false) {
            $conversion->nonQueued();
        }
    }

    protected function urlFor(mixed $value): string|array|null
    {
        if ($value === null) {
            return null;
        }

        if (is_array($value)) {
            return array_values(array_filter(array_map(
                static fn (mixed $media) => $media instanceof Media ? $media->getUrl() : null,
                $value,
            )));
        }

        return $value instanceof Media ? $value->getUrl() : null;
    }
}
