<?php
/**
 * CDM Airport Status API Endpoint
 *
 * Returns A-CDM style airport operational picture — departure queue
 * composition, gate-hold metrics, weather, and rates.
 *
 * GET /api/swim/v1/cdm/airport-status?airport=KJFK
 * GET /api/swim/v1/cdm/airport-status?airport=KJFK&history=1  (last 24h snapshots)
 *
 * Access: Requires valid SWIM API key
 *
 * @package PERTI
 * @subpackage CDM
 * @version 1.0.0
 */

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../../../../load/services/CDMService.php';

SwimResponse::handlePreflight();
$auth = swim_init_auth(true);

$airport = swim_get_param('airport');
$history = swim_get_param('history', '0') === '1';
$limit = swim_get_int_param('limit', 24, 1, 288); // Max 288 = 24h of 5-min snapshots

if (!$airport) {
    SwimResponse::error('Missing required parameter: airport', 400);
}

$airport = strtoupper($airport);
if (strlen($airport) !== 4) {
    SwimResponse::error('Airport must be a 4-character ICAO code', 400);
}

$conn_tmi = get_conn_tmi();
$conn_adl = get_conn_adl();
if (!$conn_tmi || !$conn_adl) {
    SwimResponse::error('Database connection not available', 503);
}

$cdm = new CDMService($conn_tmi, $conn_adl);

if ($history) {
    // Historical snapshots
    $sql = "SELECT TOP (?) * FROM dbo.cdm_airport_status
            WHERE airport_icao = ?
            ORDER BY snapshot_utc DESC";
    $stmt = sqlsrv_query($conn_tmi, $sql, [$limit, $airport]);
    $snapshots = [];
    if ($stmt !== false) {
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            if ($row['snapshot_utc'] instanceof DateTime) {
                $row['snapshot_utc'] = $row['snapshot_utc']->format('Y-m-d H:i:s');
            }
            $snapshots[] = $row;
        }
        sqlsrv_free_stmt($stmt);
    }

    SwimResponse::success([
        'airport' => $airport,
        'snapshots' => $snapshots,
        'count' => count($snapshots)
    ]);
} else {
    // Latest snapshot + live readiness
    $latest = $cdm->getAirportStatus($airport);
    $readiness = $cdm->getAirportReadiness($airport);

    // Format DateTime
    if ($latest && isset($latest['snapshot_utc']) && $latest['snapshot_utc'] instanceof DateTime) {
        $latest['snapshot_utc'] = $latest['snapshot_utc']->format('Y-m-d H:i:s');
    }

    SwimResponse::success([
        'airport' => $airport,
        'status' => $latest,
        'live_readiness' => $readiness,
        'timestamp' => gmdate('c')
    ]);
}
