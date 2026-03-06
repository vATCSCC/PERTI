<?php
/**
 * HoppieConnector — Hoppie ACARS/CPDLC network integration.
 *
 * Push connector (from VATSWIM's perspective). The Hoppie client library
 * polls the Hoppie network and pushes ACARS OOOI data to VATSWIM.
 *
 * Endpoint:
 *   POST /api/swim/v1/ingest/acars.php (batch 100)
 *
 * Auth field: 'datalink'
 * Priority: Datalink P1 (primary ACARS source)
 *
 * Full client implementation: integrations/hoppie-cpdlc/
 */

namespace PERTI\Lib\Connectors\Sources;

use PERTI\Lib\Connectors\AbstractConnector;

class HoppieConnector extends AbstractConnector
{
    public function __construct()
    {
        $this->name     = 'Hoppie ACARS';
        $this->sourceId = 'hoppie';
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
            'auth_field'        => 'datalink',
            'batch_limit'       => 100,
            'data_fields'       => ['OOOI_times', 'position_reports', 'CPDLC_messages'],
            'existing_client'   => 'integrations/hoppie-cpdlc/',
            'ingest_source_key' => 'hoppie',
        ]);
    }
}
