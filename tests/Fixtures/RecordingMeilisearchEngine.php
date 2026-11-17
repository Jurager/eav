<?php

declare(strict_types=1);

namespace Jurager\Eav\Tests\Fixtures;

use Laravel\Scout\Engines\MeilisearchEngine;
use Meilisearch\Client;

/**
 * Scout's Meilisearch engine with no server behind it: indexing does nothing, and settings are recorded.
 */
class RecordingMeilisearchEngine extends MeilisearchEngine
{
    public function __construct(private readonly RecordingGateway $gateway, bool $softDelete = false)
    {
        parent::__construct(new Client('http://127.0.0.1:1'), $softDelete);
    }

    public function update($models) {}

    public function delete($models) {}

    public function flush($model) {}

    public function updateIndexSettings($name, array $settings = [])
    {
        $this->gateway->settings[$name] = $settings;
    }
}
