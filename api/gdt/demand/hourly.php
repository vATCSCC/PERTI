<?php
/**
 * GDT Demand - Hourly API
 * 
 * GET /api/gdt/demand/hourly.php?program_id=1
 * 
 * Returns demand by hour for a program using vw_tmi_demand_by_hour view.
 * Also provides quarter-hour breakdown if requested.
 * 
 * Query parameters:
 *   program_id      - Required: Program ID
 *   include_quarter - If "1", include 15-minute breakdown (default: 0)
 * 
 * Response:
 * {
 *   "status": "ok",
 *   "data": {
 *     "hourly": [
 *       { "bin_hour": 14, "slot_count": 30, "assigned": 25, "open": 5, "demand": 28 },
 *       ...
 *     ],
 *     "quarterly": [ ... ],  // if include_quarter=1
 *     "program_rate": 30
 *   }
 * }
 * 
 * @version 1.0.0
 * @date 2026-01-21
 */

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

define('GDT_API_INCLUDED', true);
require_once(__DIR__ . '/../common.php');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    respond_json(405, [
        'status' => 'error',
        'message' => 'Method not allowed. Use GET.'
    ]);
}

$conn_tmi = get_conn_tmi();

// ============================================================================
// Parse Parameters
// ============================================================================

$program_id = isset($_GET['program_id']) ? (int)$_GET['program_id'] : 0;
$include_quarter = isset($_GET['include_quarter']) && $_GET['include_quarter'] === '1';

if ($program_id <= 0) {
    respond_json(400, [
        'status' => 'error',
        'message' => 'program_id is required.'
    ]);
}

// Get program info
$program = get_program($conn_tmi, $program_id);
if ($program === null) {
    respond_json(404, [
        'status' => 'error',
        'message' => "Program not found: {$program_id}"
    ]);
}

$program_rate = $program['program_rate'] ?? 30;

// ============================================================================
// Fetch Hourly Demand
// ============================================================================

// Try the view first, fallback to direct query
$hourly_sql = "
    SELECT 
        bin_hour,
        COUNT(*) AS slot_count,
        SUM(CASE WHEN slot_status = 'ASSIGNED' THEN 1 ELSE 0 END) AS assigned,
        SUM(CASE WHEN slot_status = 'OPEN' THEN 1 ELSE 0 END) AS open_slots,
        SUM(CASE WHEN slot_status = 'BRIDGED' THEN 1 ELSE 0 END) AS bridged
    FROM dbo.tmi_slots
    WHERE program_id = ?
    GROUP BY bin_hour
    ORDER BY bin_hour ASC
";

$hourly_result = fetch_all($conn_tmi, $hourly_sql, [$program_id]);

// Get flight demand by original ETA hour
$demand_sql = "
    SELECT 
        DATEPART(HOUR, orig_eta_utc) AS eta_hour,
        COUNT(*) AS demand
    FROM dbo.tmi_flight_control
    WHERE program_id = ? AND orig_eta_utc IS NOT NULL
    GROUP BY DATEPART(HOUR, orig_eta_utc)
    ORDER BY eta_hour ASC
";

$demand_result = fetch_all($conn_tmi, $demand_sql, [$program_id]);

// Merge demand into hourly
$demand_by_hour = [];
if ($demand_result['success']) {
    foreach ($demand_result['data'] as $d) {
        $demand_by_hour[(int)$d['eta_hour']] = (int)$d['demand'];
    }
}

$hourly = [];
if ($hourly_result['success']) {
    foreach ($hourly_result['data'] as $row) {
        $hour = (int)$row['bin_hour'];
        $hourly[] = [
            'bin_hour' => $hour,
            'slot_count' => (int)$row['slot_count'],
            'assigned' => (int)$row['assigned'],
            'open_slots' => (int)$row['open_slots'],
            'bridged' => (int)$row['bridged'],
            'demand' => isset($demand_by_hour[$hour]) ? $demand_by_hour[$hour] : 0,
            'capacity' => $program_rate
        ];
    }
}

// ============================================================================
// Fetch Quarterly Demand (optional)
// ============================================================================

$quarterly = null;
if ($include_quarter) {
    $quarter_sql = "
        SELECT 
            bin_hour,
            bin_quarter,
            COUNT(*) AS slot_count,
            SUM(CASE WHEN slot_status = 'ASSIGNED' THEN 1 ELSE 0 END) AS assigned,
            SUM(CASE WHEN slot_status = 'OPEN' THEN 1 ELSE 0 END) AS open_slots
        FROM dbo.tmi_slots
        WHERE program_id = ?
        GROUP BY bin_hour, bin_quarter
        ORDER BY bin_hour ASC, bin_quarter ASC
    ";
    
    $quarter_result = fetch_all($conn_tmi, $quarter_sql, [$program_id]);
    $quarterly = $quarter_result['success'] ? $quarter_result['data'] : [];
}

// ============================================================================
// Response
// ============================================================================

$response_data = [
    'hourly' => $hourly,
    'program_rate' => $program_rate
];

if ($quarterly !== null) {
    $response_data['quarterly'] = $quarterly;
}

respond_json(200, [
    'status' => 'ok',
    'data' => $response_data
]);
