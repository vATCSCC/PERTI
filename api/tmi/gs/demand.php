<?php
/**
 * GS Demand API
 * 
 * GET /api/tmi/gs/demand.php?airport=KJFK
 * 
 * Gets arrival demand data for an airport (for GDT bar graphs).
 * Uses the vw_GDT_DemandByQuarter and vw_GDT_DemandByHour views.
 * 
 * Query parameters:
 * - airport: Required - airport ICAO code
 * - granularity: Optional - 'quarter' (15-min) or 'hour' (default: quarter)
 * - hours_ahead: Optional - hours of demand to retrieve (default: 12)
 * 
 * Response:
 * {
 *   "status": "ok",
 *   "message": "Demand data retrieved",
 *   "data": {
 *     "airport": "KJFK",
 *     "demand": [ ... ],
 *     "summary": {
 *       "total_flights": 45,
 *       "total_controlled": 12,
 *       "total_airborne": 30
 *     }
 *   }
 * }
 */

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

define('GS_API_INCLUDED', true);
require_once(__DIR__ . '/common.php');

if (!in_array($_SERVER['REQUEST_METHOD'], ['GET', 'POST'])) {
    respond_json(405, [
        'status' => 'error',
        'message' => 'Method not allowed. Use GET or POST.'
    ]);
}

$payload = read_request_payload();
$conn = get_adl_conn();

// Get parameters
$airport = isset($payload['airport']) ? strtoupper(trim($payload['airport'])) : '';
$granularity = isset($payload['granularity']) ? strtolower(trim($payload['granularity'])) : 'quarter';
$hours_ahead = isset($payload['hours_ahead']) ? (int)$payload['hours_ahead'] : 12;

if ($airport === '') {
    respond_json(400, [
        'status' => 'error',
        'message' => 'airport is required.'
    ]);
}

$hours_ahead = max(1, min($hours_ahead, 24)); // Clamp to 1-24

// Choose view based on granularity
if ($granularity === 'hour') {
    $sql = "
        SELECT *
        FROM dbo.vw_GDT_DemandByHour
        WHERE airport = ?
          AND hour_utc >= SYSUTCDATETIME()
          AND hour_utc <= DATEADD(HOUR, ?, SYSUTCDATETIME())
        ORDER BY hour_utc ASC
    ";
} else {
    $sql = "
        SELECT *
        FROM dbo.vw_GDT_DemandByQuarter
        WHERE airport = ?
          AND bucket_utc >= SYSUTCDATETIME()
          AND bucket_utc <= DATEADD(HOUR, ?, SYSUTCDATETIME())
        ORDER BY bucket_utc ASC
    ";
}

$result = fetch_all($conn, $sql, [$airport, $hours_ahead]);

if (!$result['success']) {
    respond_json(500, [
        'status' => 'error',
        'message' => 'Failed to retrieve demand data',
        'errors' => $result['error']
    ]);
}

$demand = $result['data'];

// Calculate summary
$total_flights = 0;
$total_controlled = 0;
$total_exempt = 0;
$total_airborne = 0;

foreach ($demand as $d) {
    $total_flights += (int)($d['total_demand'] ?? 0);
    $total_controlled += (int)($d['controlled'] ?? 0);
    $total_exempt += (int)($d['exempt'] ?? 0);
    $total_airborne += (int)($d['airborne'] ?? 0);
}

respond_json(200, [
    'status' => 'ok',
    'message' => 'Demand data retrieved',
    'data' => [
        'airport' => $airport,
        'granularity' => $granularity,
        'hours_ahead' => $hours_ahead,
        'demand' => $demand,
        'summary' => [
            'total_flights' => $total_flights,
            'total_controlled' => $total_controlled,
            'total_exempt' => $total_exempt,
            'total_airborne' => $total_airborne,
            'bins' => count($demand)
        ],
        'server_utc' => get_server_utc($conn)
    ]
]);
