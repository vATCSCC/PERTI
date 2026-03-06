<?php
/**
 * VNASConnector — vNAS ATC automation integration.
 *
 * Push-only connector. vNAS ERAM/STARS systems push track surveillance,
 * automation tags, and handoff data to VATSWIM ingest endpoints.
 *
 * Endpoints:
 *   POST /api/swim/v1/ingest/vnas/track.php   (batch 1000)
 *   POST /api/swim/v1/ingest/vnas/tags.php    (batch 500)
 *   POST /api/swim/v1/ingest/vnas/handoff.php (batch 200)
 *
 * Auth field: 'track'
 * Priority: Track P1 (highest — primary ATC automation source)
 */

namespace PERTI\Lib\Connectors\Sources;

use PERTI\Lib\Connectors\AbstractConnector;

class VNASConnector extends AbstractConnector
{
    public function __construct()
    {
        $this->name     = 'vNAS';
        $this->sourceId = 'vnas';
        $this->type     = 'push';
    }

    public function getEndpoints(): array
    {
        return [
            'track'   => '/api/swim/v1/ingest/vnas/track.php',
            'tags'    => '/api/swim/v1/ingest/vnas/tags.php',
            'handoff' => '/api/swim/v1/ingest/vnas/handoff.php',
        ];
    }

    public function getConfig(): array
    {
        return array_merge(parent::getConfig(), [
            'auth_field'  => 'track',
            'batch_limits' => [
                'track'   => 1000,
                'tags'    => 500,
                'handoff' => 200,
            ],
            'data_fields' => ['position', 'track', 'tags', 'handoffs', 'clearances'],
            'client_sdk'  => 'integrations/connectors/vnas/',
        ]);
    }
}
