<?php
/**
 * CDM Readiness API Endpoint
 *
 * Allows pilots to signal readiness state and report TOBT.
 *
 * GET  /api/swim/v1/cdm/readiness?callsign=AAL123  — Get current readiness
 * POST /api/swim/v1/cdm/readiness                   — Update readiness state
 *
 * POST body:
 *   { "callsign": "AAL123", "state": "READY", "tobt_utc": "2026-03-05 14:30:00" }
 *
 * Valid states: PLANNING, BOARDING, READY, TAXIING, CANCELLED
 *
 * Access: Requires valid SWIM API key (write access for POST)
 * SWIM-isolated: reads from SWIM_API mirror tables; writes require TMI/ADL connections
 *
 * @package PERTI
 * @subpackage CDM
 * @version 2.0.0
 */

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../../../../load/services/CDMService.php';

SwimResponse::handlePreflight();

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    // Read current readiness — SWIM-only
    $auth = swim_init_auth(true);

    $callsign = swim_get_param('callsign');
    $airport = swim_get_param('airport');

    $conn_swim = get_conn_swim();
    if (!$conn_swim) {
        SwimResponse::error('SWIM database connection not available', 503);
    }

    $cdm = new CDMService($conn_swim);

    if ($airport) {
        // Airport-wide readiness summary
        $readiness = $cdm->getAirportReadiness($airport);
        SwimResponse::success([
            'airport' => $airport,
            'readiness_counts' => $readiness,
            'timestamp' => gmdate('c')
        ]);
    } elseif ($callsign) {
        // Resolve flight_uid via swim_flights
        $sql = "SELECT TOP 1 flight_uid FROM dbo.swim_flights WHERE callsign = ? AND is_active = 1 ORDER BY last_seen_utc DESC";
        $stmt = sqlsrv_query($conn_swim, $sql, [$callsign]);
        $flight_uid = null;
        if ($stmt !== false) {
            $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
            if ($row) $flight_uid = (int)$row['flight_uid'];
            sqlsrv_free_stmt($stmt);
        }

        if (!$flight_uid) {
            SwimResponse::error("No active flight found for callsign: $callsign", 404);
        }

        $readiness = $cdm->getReadiness($flight_uid);
        if (!$readiness) {
            SwimResponse::success(['flight_uid' => $flight_uid, 'readiness' => null, 'message' => 'No readiness signal recorded']);
        } else {
            SwimResponse::success($readiness);
        }
    } else {
        SwimResponse::error('Missing required parameter: callsign or airport', 400);
    }

} elseif ($method === 'POST') {
    // Update readiness — requires write access + TMI/ADL connections
    $auth = swim_init_auth(true, true);

    $data = swim_get_json_body();
    if (!$data) {
        SwimResponse::error('Missing JSON body', 400);
    }

    $callsign = $data['callsign'] ?? null;
    $state = strtoupper($data['state'] ?? '');
    $tobt_utc = $data['tobt_utc'] ?? null;
    $source = $data['source'] ?? 'web';

    if (!$callsign || !$state) {
        SwimResponse::error('Missing required fields: callsign, state', 400);
    }

    $valid_states = ['PLANNING', 'BOARDING', 'READY', 'TAXIING', 'CANCELLED'];
    if (!in_array($state, $valid_states)) {
        SwimResponse::error('Invalid state. Must be one of: ' . implode(', ', $valid_states), 400);
    }

    $conn_swim = get_conn_swim();
    $conn_tmi = get_conn_tmi();
    $conn_adl = get_conn_adl();
    if (!$conn_swim || !$conn_tmi || !$conn_adl) {
        SwimResponse::error('Database connection not available', 503);
    }

    // Resolve flight_uid via swim_flights
    $sql = "SELECT TOP 1 flight_uid, cid, fp_dept_icao, fp_dest_icao FROM dbo.swim_flights WHERE callsign = ? AND is_active = 1 ORDER BY last_seen_utc DESC";
    $stmt = sqlsrv_query($conn_swim, $sql, [$callsign]);
    $flight_uid = null;
    $cid = null;
    $dep = null;
    $arr = null;
    if ($stmt !== false) {
        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        if ($row) {
            $flight_uid = (int)$row['flight_uid'];
            $cid = $row['cid'] ? (int)$row['cid'] : null;
            $dep = $row['fp_dept_icao'];
            $arr = $row['fp_dest_icao'];
        }
        sqlsrv_free_stmt($stmt);
    }

    if (!$flight_uid) {
        SwimResponse::error("No active flight found for callsign: $callsign", 404);
    }

    $cdm = new CDMService($conn_swim, $conn_tmi, $conn_adl);
    $readiness_id = $cdm->updateReadiness(
        $flight_uid, $callsign, $state, $source,
        $cid, $tobt_utc, $dep, $arr
    );

    if ($readiness_id === false) {
        SwimResponse::error('Failed to update readiness', 500);
    }

    if ($readiness_id === 0) {
        SwimResponse::success(['message' => 'State unchanged (already in ' . $state . ')']);
    }

    // If READY, compute milestones
    $milestones = null;
    if ($state === 'READY' && $dep) {
        // Check for EDCT from swim_flights
        $edct_sql = "SELECT edct_utc FROM dbo.swim_flights WHERE flight_uid = ?";
        $edct_stmt = sqlsrv_query($conn_swim, $edct_sql, [$flight_uid]);
        $edct = null;
        if ($edct_stmt !== false) {
            $edct_row = sqlsrv_fetch_array($edct_stmt, SQLSRV_FETCH_ASSOC);
            if ($edct_row && $edct_row['edct_utc']) {
                $edct = ($edct_row['edct_utc'] instanceof DateTime) ? $edct_row['edct_utc']->format('Y-m-d H:i:s') : (string)$edct_row['edct_utc'];
            }
            sqlsrv_free_stmt($edct_stmt);
        }

        $tobt = $tobt_utc ?? gmdate('Y-m-d H:i:s');
        $milestones = $cdm->computeMilestones($flight_uid, $dep, $tobt, $edct);
        $cdm->saveMilestones($flight_uid, $milestones);
    }

    SwimResponse::success([
        'readiness_id' => $readiness_id,
        'state' => $state,
        'milestones' => $milestones,
        'flight_uid' => $flight_uid
    ]);

} else {
    SwimResponse::error('Method not allowed', 405);
}
