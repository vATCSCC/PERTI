<?php
/**
 * VATSWIM API v1 - TOS Status Endpoint
 *
 * Retrieves Trajectory Option Set (TOS) options for a flight.
 * Returns all non-expired options ordered by preference rank.
 *
 * GET /api/swim/v1/tos/status?callsign=UAL123
 * GET /api/swim/v1/tos/status?callsign=UAL123&departure=KJFK&destination=KLAX
 *
 * @version 1.0.0
 * @since 2026-03-30
 */

require_once __DIR__ . '/../auth.php';

// Read-only access
$auth = swim_init_auth(true, false);

// Only accept GET
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    SwimResponse::error('Method not allowed. Use GET.', 405, 'METHOD_NOT_ALLOWED');
}

// Required parameter
$callsign = strtoupper(trim(swim_get_param('callsign', '')));
if (empty($callsign)) {
    SwimResponse::error('Missing required parameter: callsign', 400, 'MISSING_PARAM');
}

// Optional filters
$departure   = strtoupper(trim(swim_get_param('departure', '')));
$destination = strtoupper(trim(swim_get_param('destination', '')));

// SWIM database connection
global $conn_swim;
if (!$conn_swim) {
    SwimResponse::error('SWIM database connection not available', 503, 'SERVICE_UNAVAILABLE');
}

// Build query - exclude expired options
$where = "t.callsign = ? AND (t.expires_at IS NULL OR t.expires_at > SYSUTCDATETIME())";
$params = [$callsign];

if (!empty($departure)) {
    $where .= " AND t.departure = ?";
    $params[] = $departure;
}
if (!empty($destination)) {
    $where .= " AND t.destination = ?";
    $params[] = $destination;
}

$sql = "SELECT t.tos_id, t.flight_uid, t.callsign, t.departure, t.destination,
               t.option_rank, t.route_string, t.flight_time_min, t.fuel_penalty_pct,
               t.status, t.assigned_by, t.assigned_at, t.filed_at, t.expires_at
        FROM dbo.tos_options t
        WHERE {$where}
        ORDER BY t.flight_uid, t.option_rank ASC";

$stmt = sqlsrv_query($conn_swim, $sql, $params);
if ($stmt === false) {
    $err = sqlsrv_errors();
    SwimResponse::error('Database error: ' . ($err[0]['message'] ?? 'Unknown'), 500, 'DB_ERROR');
}

$results = [];
while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
    $results[] = [
        'tos_id'           => $row['tos_id'],
        'flight_uid'       => $row['flight_uid'],
        'callsign'         => $row['callsign'],
        'departure'        => $row['departure'],
        'destination'      => $row['destination'],
        'option_rank'      => $row['option_rank'],
        'route'            => $row['route_string'],
        'flight_time_min'  => $row['flight_time_min'],
        'fuel_penalty_pct' => $row['fuel_penalty_pct'] !== null ? floatval($row['fuel_penalty_pct']) : null,
        'status'           => $row['status'],
        'assigned_by'      => $row['assigned_by'],
        'assigned_at'      => ($row['assigned_at'] instanceof DateTime)
                              ? $row['assigned_at']->format('c') : $row['assigned_at'],
        'filed_at'         => ($row['filed_at'] instanceof DateTime)
                              ? $row['filed_at']->format('c') : $row['filed_at'],
        'expires_at'       => ($row['expires_at'] instanceof DateTime)
                              ? $row['expires_at']->format('c') : $row['expires_at']
    ];
}
sqlsrv_free_stmt($stmt);

SwimResponse::success([
    'callsign' => $callsign,
    'count'    => count($results),
    'options'  => $results
], [
    'filters' => array_filter([
        'departure'   => $departure ?: null,
        'destination' => $destination ?: null
    ])
]);
