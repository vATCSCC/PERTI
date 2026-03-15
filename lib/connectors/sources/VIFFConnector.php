<?php
/**
 * VIFFConnector — vIFF ATFCM System (EU CDM data source).
 *
 * Poll-only connector. VATSWIM polls the vIFF REST API for A-CDM milestones
 * (TOBT/TSAT/TTOT/CTOT/EXOT) and ATFCM statuses, writing directly to swim_flights.
 *
 * vIFF is the European ATFCM backend for VATSIM by Roger Puig, powering
 * the EuroScope CDM plugin used by 32+ vACCs. It manages server-side
 * capacity regulations (CAD) and ECFMP flow measures for EU airspace.
 *
 * External API: https://viff-system.network
 * Auth: x-api-key header
 * Uses tmi_flow_providers table for provider config (provider_code = 'VIFF').
 */

namespace PERTI\Lib\Connectors\Sources;

use PERTI\Lib\Connectors\AbstractConnector;
use PERTI\Lib\Connectors\CircuitBreaker;

class VIFFConnector extends AbstractConnector
{
    public function __construct()
    {
        $this->name     = 'vIFF ATFCM';
        $this->sourceId = 'viff_cdm';
        $this->type     = 'poll';

        // Re-use same state file as the daemon
        $stateFile = sys_get_temp_dir() . '/perti_viff_cdm_state.json';
        $this->circuitBreaker = new CircuitBreaker($stateFile, 60, 6, 180);
    }

    /**
     * Override: vIFF is SWIM-exempt — runs even during hibernation.
     * Controlled by its own feature flag instead of HIBERNATION_MODE.
     */
    public function isEnabled(): bool
    {
        return defined('VIFF_CDM_ENABLED') && VIFF_CDM_ENABLED;
    }

    public function getEndpoints(): array
    {
        return [
            'flights'       => '/api/swim/v1/flights',
            'flow_measures' => '/api/swim/v1/tmi/flow/measures.php',
        ];
    }

    public function getConfig(): array
    {
        return array_merge(parent::getConfig(), [
            'external_api'   => 'https://viff-system.network',
            'poll_daemon'    => 'scripts/viff_cdm_poll_daemon.php',
            'poll_interval'  => '30s',
            'data_fields'    => ['TOBT', 'TSAT', 'TTOT', 'CTOT', 'EXOT', 'ATFCM_STATUS'],
            'provider_code'  => 'VIFF',
        ]);
    }
}
