<?php
/**
 * ECFMPConnector — ECFMP (European Collaborative Flow Management Programme).
 *
 * Poll-only connector. VATSWIM polls the ECFMP API for flow control
 * measures and events, storing them in tmi_flow_measures/tmi_flow_events.
 *
 * The SWIM API exposes READ-only endpoints for consumers:
 *   GET /api/swim/v1/tmi/flow/events.php
 *   GET /api/swim/v1/tmi/flow/measures.php
 *   GET /api/swim/v1/tmi/flow/providers.php
 *
 * Auth field: N/A (poll daemon uses direct ECFMP API access)
 * Uses tmi_flow_providers table for provider config.
 */

namespace PERTI\Lib\Connectors\Sources;

use PERTI\Lib\Connectors\AbstractConnector;
use PERTI\Lib\Connectors\CircuitBreaker;

class ECFMPConnector extends AbstractConnector
{
    public function __construct()
    {
        $this->name     = 'ECFMP';
        $this->sourceId = 'ecfmp';
        $this->type     = 'poll';

        // Re-use same state file as the daemon
        $stateFile = sys_get_temp_dir() . '/perti_ecfmp_poll_state.json';
        $this->circuitBreaker = new CircuitBreaker($stateFile, 60, 6, 180);
    }

    public function getEndpoints(): array
    {
        return [
            'flow_events'    => '/api/swim/v1/tmi/flow/events.php',
            'flow_measures'  => '/api/swim/v1/tmi/flow/measures.php',
            'flow_providers' => '/api/swim/v1/tmi/flow/providers.php',
        ];
    }

    public function getConfig(): array
    {
        return array_merge(parent::getConfig(), [
            'external_api'  => 'https://ecfmp.vatsim.net/api/v1',
            'poll_daemon'   => 'scripts/ecfmp_poll_daemon.php',
            'poll_interval' => '300s',
            'data_fields'   => ['flow_measures', 'flow_events', 'FIR_restrictions'],
            'provider_code' => 'ECFMP',
            'client_sdk'    => 'integrations/connectors/ecfmp/',
        ]);
    }
}
