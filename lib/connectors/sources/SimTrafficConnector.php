<?php
/**
 * SimTrafficConnector — SimTraffic TBFM-style metering integration.
 *
 * Bidirectional connector:
 *   Push: SimTraffic pushes departure/arrival timing to ingest endpoint
 *   Poll: VATSWIM polls SimTraffic API for time data (simtraffic_swim_poll.php)
 *
 * Endpoints:
 *   POST /api/swim/v1/ingest/simtraffic.php (batch 500)
 *
 * Auth field: 'metering'
 * Priority: Metering P1, Times P1 (primary metering source)
 */

namespace PERTI\Lib\Connectors\Sources;

use PERTI\Lib\Connectors\AbstractConnector;
use PERTI\Lib\Connectors\CircuitBreaker;

class SimTrafficConnector extends AbstractConnector
{
    public function __construct()
    {
        $this->name     = 'SimTraffic';
        $this->sourceId = 'simtraffic';
        $this->type     = 'bidirectional';

        // Re-use same state file as the daemon
        $stateFile = sys_get_temp_dir() . '/perti_simtraffic_poll_state.json';
        $this->circuitBreaker = new CircuitBreaker($stateFile, 60, 6, 180);
    }

    public function getEndpoints(): array
    {
        return [
            'ingest' => '/api/swim/v1/ingest/simtraffic.php',
        ];
    }

    public function getConfig(): array
    {
        return array_merge(parent::getConfig(), [
            'auth_field'   => 'metering',
            'batch_limit'  => 500,
            'poll_daemon'  => 'scripts/simtraffic_swim_poll.php',
            'poll_interval' => '120s',
            'data_fields'  => ['departure_times', 'arrival_times', 'metering_data'],
            'client_sdk'   => 'integrations/connectors/simtraffic/',
        ]);
    }
}
