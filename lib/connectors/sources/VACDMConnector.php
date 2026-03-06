<?php
/**
 * VACDMConnector — vACDM Airport CDM integration.
 *
 * Bidirectional connector:
 *   Push: vACDM instances push A-CDM milestones to CDM ingest endpoint
 *   Poll: VATSWIM polls active vACDM providers (vacdm_poll_daemon.php)
 *
 * Endpoints:
 *   POST /api/swim/v1/ingest/cdm.php (batch 500)
 *
 * Auth field: 'cdm'
 * Priority: CDM P1 (primary CDM source for TOBT/TSAT/TTOT/ASAT/EXOT)
 *
 * Uses tmi_flow_providers table for provider discovery.
 * Per-provider circuit breakers in VACDM_STATE_DIR.
 */

namespace PERTI\Lib\Connectors\Sources;

use PERTI\Lib\Connectors\AbstractConnector;

class VACDMConnector extends AbstractConnector
{
    public function __construct()
    {
        $this->name     = 'vACDM';
        $this->sourceId = 'vacdm';
        $this->type     = 'bidirectional';
        // Per-provider circuit breakers — no single CB instance here.
        // Health check uses the state directory.
    }

    public function getEndpoints(): array
    {
        return [
            'ingest' => '/api/swim/v1/ingest/cdm.php',
        ];
    }

    public function getHealth(): array
    {
        if (!$this->isEnabled()) {
            return ['status' => 'DISABLED', 'details' => ['reason' => 'hibernation']];
        }

        $details = [
            'provider_registry' => 'tmi_flow_providers',
        ];

        // Check per-provider circuit breaker states
        $stateDir = sys_get_temp_dir() . '/perti_vacdm_state/';
        if (is_dir($stateDir)) {
            $cbFiles = glob($stateDir . 'circuit_*.json');
            $openCount = 0;
            foreach ($cbFiles as $file) {
                $data = @json_decode(@file_get_contents($file), true);
                if (is_array($data) && !empty($data['cooldown_until']) && $data['cooldown_until'] > time()) {
                    $openCount++;
                }
            }
            $details['providers_with_open_circuit'] = $openCount;
            $details['total_provider_states'] = count($cbFiles);
            if ($openCount > 0) {
                return ['status' => 'DEGRADED', 'details' => $details];
            }
        }

        return ['status' => 'OK', 'details' => $details];
    }

    public function getConfig(): array
    {
        return array_merge(parent::getConfig(), [
            'auth_field'   => 'cdm',
            'batch_limit'  => 500,
            'poll_daemon'  => 'scripts/vacdm_poll_daemon.php',
            'poll_interval' => '120s',
            'data_fields'  => ['TOBT', 'TSAT', 'TTOT', 'ASAT', 'EXOT', 'readiness_state'],
            'milestones'   => ['PLANNING', 'BOARDING', 'READY', 'TAXIING'],
            'client_sdk'   => 'integrations/connectors/vacdm/',
        ]);
    }
}
