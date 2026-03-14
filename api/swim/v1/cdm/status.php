<?php
/**
 * CDM Status API Endpoint
 *
 * Returns CDM status for a flight — milestones, readiness, TMI control,
 * compliance, and pending messages.
 *
 * GET /api/swim/v1/cdm/status?callsign=AAL123
 * GET /api/swim/v1/cdm/status?flight_uid=12345
 *
 * Used by:
 *   - CDM web dashboard (cdm.perti.vatcscc.org)
 *   - VatswimPlugin pilot client polling
 *   - External integrations
 *
 * Access: Requires valid SWIM API key
 * SWIM-isolated: reads from SWIM_API mirror tables only
 *
 * @package PERTI
 * @subpackage CDM
 * @version 2.0.0
 */

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../../../../load/services/CDMService.php';

// Handle CORS preflight
SwimResponse::handlePreflight();

// Auth — read-only access
$auth = swim_init_auth(true);

// Get flight identifier
$callsign = swim_get_param('callsign');
$flight_uid = swim_get_param('flight_uid');

if (!$callsign && !$flight_uid) {
    SwimResponse::error('Missing required parameter: callsign or flight_uid', 400, 'MISSING_PARAM');
}

// SWIM-only connection
$conn_swim = get_conn_swim();
if (!$conn_swim) {
    SwimResponse::error('SWIM database connection not available', 503, 'SERVICE_UNAVAILABLE');
}

// Resolve flight_uid from callsign via swim_flights
if ($callsign && !$flight_uid) {
    $sql = "SELECT TOP 1 flight_uid FROM dbo.swim_flights
            WHERE callsign = ? AND is_active = 1
            ORDER BY last_seen_utc DESC";
    $stmt = sqlsrv_query($conn_swim, $sql, [$callsign]);
    if ($stmt !== false) {
        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        if ($row) {
            $flight_uid = (int)$row['flight_uid'];
        }
        sqlsrv_free_stmt($stmt);
    }

    if (!$flight_uid) {
        SwimResponse::error("No active flight found for callsign: $callsign", 404, 'FLIGHT_NOT_FOUND');
    }
}

$flight_uid = (int)$flight_uid;

// Get CDM status (read-only, SWIM mirrors)
$cdm = new CDMService($conn_swim);
$status = $cdm->getFlightCDMStatus($flight_uid);

if (!$status) {
    SwimResponse::error('Flight not found or no CDM data available', 404, 'NOT_FOUND');
}

SwimResponse::success($status);
