<?php
/**
 * VATSWIM API v1 - TOS File Endpoint
 *
 * Receives pilot-filed Trajectory Option Set (TOS) route preferences.
 * Pilots submit ranked route options; TMI automation can later assign one.
 *
 * POST /api/swim/v1/tos/file
 *
 * Expected payload:
 * {
 *   "callsign": "UAL123",
 *   "departure": "KJFK",
 *   "destination": "KLAX",
 *   "options": [
 *     { "route": "DCT MERIT J80 BETTE ...", "flight_time_min": 310, "fuel_penalty_pct": 0.0 },
 *     { "route": "DCT WAVEY J75 BRAVO ...", "flight_time_min": 315, "fuel_penalty_pct": 2.5 }
 *   ]
 * }
 *
 * @version 1.0.0
 * @since 2026-03-30
 */

require_once __DIR__ . '/../auth.php';

// Require authentication with write access
$auth = swim_init_auth(true, true);

// Only accept POST
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    SwimResponse::error('Method not allowed. Use POST.', 405, 'METHOD_NOT_ALLOWED');
}

// Get request body
$body = swim_get_json_body();
if (!$body) {
    SwimResponse::error('Request body is required', 400, 'MISSING_BODY');
}

// Validate required fields
$callsign    = strtoupper(trim($body['callsign'] ?? ''));
$departure   = strtoupper(trim($body['departure'] ?? ''));
$destination = strtoupper(trim($body['destination'] ?? ''));

if (empty($callsign)) {
    SwimResponse::error('Missing required field: callsign', 400, 'MISSING_FIELD');
}
if (empty($departure) || strlen($departure) > 4) {
    SwimResponse::error('Missing or invalid field: departure (ICAO code)', 400, 'INVALID_FIELD');
}
if (empty($destination) || strlen($destination) > 4) {
    SwimResponse::error('Missing or invalid field: destination (ICAO code)', 400, 'INVALID_FIELD');
}

if (!isset($body['options']) || !is_array($body['options']) || count($body['options']) === 0) {
    SwimResponse::error('Request must contain a non-empty "options" array', 400, 'MISSING_OPTIONS');
}

$options = $body['options'];
$max_options = 10;

if (count($options) > $max_options) {
    SwimResponse::error(
        "Too many options. Maximum {$max_options} route options per filing.",
        400,
        'TOO_MANY_OPTIONS'
    );
}

// Validate each option
foreach ($options as $i => $opt) {
    if (empty($opt['route']) || !is_string($opt['route'])) {
        SwimResponse::error("Option [{$i}]: missing or invalid 'route' string", 400, 'INVALID_OPTION');
    }
    if (strlen($opt['route']) > 1024) {
        SwimResponse::error("Option [{$i}]: route string exceeds 1024 characters", 400, 'ROUTE_TOO_LONG');
    }
}

// SWIM database connection
global $conn_swim;
if (!$conn_swim) {
    SwimResponse::error('SWIM database connection not available', 503, 'SERVICE_UNAVAILABLE');
}

// Look up flight_uid from swim_flights
$lookup_sql = "SELECT TOP 1 flight_uid
               FROM dbo.swim_flights
               WHERE callsign = ? AND fp_dept_icao = ? AND fp_dest_icao = ? AND is_active = 1
               ORDER BY last_sync_utc DESC";
$lookup_stmt = sqlsrv_query($conn_swim, $lookup_sql, [$callsign, $departure, $destination]);
if ($lookup_stmt === false) {
    $err = sqlsrv_errors();
    SwimResponse::error('Database error looking up flight: ' . ($err[0]['message'] ?? 'Unknown'), 500, 'DB_ERROR');
}

$flight = sqlsrv_fetch_array($lookup_stmt, SQLSRV_FETCH_ASSOC);
sqlsrv_free_stmt($lookup_stmt);

if (!$flight) {
    SwimResponse::error(
        "No active flight found for {$callsign} {$departure}-{$destination}",
        404,
        'FLIGHT_NOT_FOUND'
    );
}

$flight_uid = $flight['flight_uid'];

// Delete any existing FILED options for this flight (replace strategy)
$del_sql = "DELETE FROM dbo.tos_options WHERE flight_uid = ? AND status = 'FILED'";
$del_stmt = sqlsrv_query($conn_swim, $del_sql, [$flight_uid]);
if ($del_stmt === false) {
    $err = sqlsrv_errors();
    SwimResponse::error('Database error clearing previous TOS: ' . ($err[0]['message'] ?? 'Unknown'), 500, 'DB_ERROR');
}
$deleted = sqlsrv_rows_affected($del_stmt);
sqlsrv_free_stmt($del_stmt);

// Insert new options with incremental rank
$inserted = [];
$rank = 1;

foreach ($options as $opt) {
    $route_string    = trim($opt['route']);
    $flight_time_min = isset($opt['flight_time_min']) && is_numeric($opt['flight_time_min'])
                       ? intval($opt['flight_time_min']) : null;
    $fuel_penalty    = isset($opt['fuel_penalty_pct']) && is_numeric($opt['fuel_penalty_pct'])
                       ? floatval($opt['fuel_penalty_pct']) : null;

    $ins_sql = "INSERT INTO dbo.tos_options
                    (flight_uid, callsign, departure, destination, option_rank,
                     route_string, flight_time_min, fuel_penalty_pct, status, filed_at)
                OUTPUT INSERTED.tos_id, INSERTED.option_rank, INSERTED.filed_at
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'FILED', SYSUTCDATETIME())";

    $ins_params = [
        $flight_uid,
        $callsign,
        $departure,
        $destination,
        $rank,
        $route_string,
        $flight_time_min,
        $fuel_penalty
    ];

    $ins_stmt = sqlsrv_query($conn_swim, $ins_sql, $ins_params);
    if ($ins_stmt === false) {
        $err = sqlsrv_errors();
        SwimResponse::error(
            "Database error inserting option rank {$rank}: " . ($err[0]['message'] ?? 'Unknown'),
            500,
            'DB_ERROR'
        );
    }

    $row = sqlsrv_fetch_array($ins_stmt, SQLSRV_FETCH_ASSOC);
    sqlsrv_free_stmt($ins_stmt);

    $inserted[] = [
        'tos_id'          => $row['tos_id'],
        'option_rank'     => $rank,
        'route'           => $route_string,
        'flight_time_min' => $flight_time_min,
        'fuel_penalty_pct'=> $fuel_penalty,
        'status'          => 'FILED',
        'filed_at'        => ($row['filed_at'] instanceof DateTime)
                             ? $row['filed_at']->format('c') : $row['filed_at']
    ];

    $rank++;
}

SwimResponse::success([
    'flight_uid'       => $flight_uid,
    'callsign'         => $callsign,
    'departure'        => $departure,
    'destination'      => $destination,
    'options_filed'    => count($inserted),
    'previous_cleared' => $deleted,
    'options'          => $inserted
], [
    'source' => $auth->getSourceId()
]);
