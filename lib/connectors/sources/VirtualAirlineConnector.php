<?php
/**
 * VirtualAirlineConnector — Virtual Airline platform integration.
 *
 * Push connector. Virtual airlines push schedule/PIREP/OOOI data
 * via the unified ACARS ingest endpoint.
 *
 * Endpoint:
 *   POST /api/swim/v1/ingest/acars.php (batch 100)
 *
 * Supported platforms (each has a full client implementation):
 *   - phpVMS 7:   integrations/virtual-airlines/phpvms7/
 *   - smartCARS:  integrations/virtual-airlines/smartcars/
 *   - VAM:        integrations/virtual-airlines/vam/
 *
 * Auth field: 'datalink'
 * Priority: Schedule P1, Airline P1
 * Ingest source keys: 'phpvms', 'smartcars', 'vam'
 */

namespace PERTI\Lib\Connectors\Sources;

use PERTI\Lib\Connectors\AbstractConnector;

class VirtualAirlineConnector extends AbstractConnector
{
    public function __construct()
    {
        $this->name     = 'Virtual Airlines';
        $this->sourceId = 'virtual_airline';
        $this->type     = 'push';
    }

    public function getEndpoints(): array
    {
        return [
            'ingest' => '/api/swim/v1/ingest/acars.php',
        ];
    }

    public function getConfig(): array
    {
        return array_merge(parent::getConfig(), [
            'auth_field'   => 'datalink',
            'batch_limit'  => 100,
            'data_fields'  => ['schedules', 'PIREPs', 'OOOI_times', 'CDM_T1_T4'],
            'platforms'    => [
                'phpvms7'  => 'integrations/virtual-airlines/phpvms7/',
                'smartcars' => 'integrations/virtual-airlines/smartcars/',
                'vam'      => 'integrations/virtual-airlines/vam/',
            ],
            'ingest_source_keys' => ['phpvms', 'smartcars', 'vam'],
        ]);
    }
}
