<?php
/**
 * VATISConnector — vATIS ATIS monitoring integration.
 *
 * Push connector. vATIS SWIMSync pushes runway correlation and
 * ATIS-derived weather data to VATSWIM.
 *
 * Endpoint:
 *   POST /api/swim/v1/ingest/adl.php (batch 500, auth field 'adl')
 *
 * Note: vATIS SWIMSync also references /api/swim/v1/ingest/weather.php
 * for weather data, but this endpoint does not exist yet (future work).
 *
 * Auth field: 'adl' (runway correlation), 'runway_weather' (authority)
 * Priority: Runway/Weather — vATIS is authoritative
 *
 * Full client implementation: integrations/vatis/
 */

namespace PERTI\Lib\Connectors\Sources;

use PERTI\Lib\Connectors\AbstractConnector;

class VATISConnector extends AbstractConnector
{
    public function __construct()
    {
        $this->name     = 'vATIS';
        $this->sourceId = 'vatis';
        $this->type     = 'push';
    }

    public function getEndpoints(): array
    {
        return [
            'ingest_adl' => '/api/swim/v1/ingest/adl.php',
            // Weather endpoint referenced by vATIS SWIMSync but not yet implemented
            // 'ingest_weather' => '/api/swim/v1/ingest/weather.php',
        ];
    }

    public function getConfig(): array
    {
        return array_merge(parent::getConfig(), [
            'auth_field'      => 'adl',
            'batch_limit'     => 500,
            'data_fields'     => ['runway_correlation', 'ATIS_info', 'weather_conditions'],
            'existing_client' => 'integrations/vatis/',
            'known_gaps'      => ['weather endpoint not yet created'],
        ]);
    }
}
