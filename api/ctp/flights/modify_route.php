<?php
/**
 * CTP Flights - Modify Route API
 *
 * POST /api/ctp/flights/modify_route.php
 *
 * Saves a modified route segment for a CTP-managed flight.
 * Enforces perspective-based jurisdiction (NA/OCEANIC/EU).
 *
 * Request body:
 * {
 *   "ctp_control_id": 12345,
 *   "segment": "NA",                        (NA, OCEANIC, EU)
 *   "route_string": "KJFK TERPZ8 DOTTY",   (new segment route)
 *   "altitude": 370,                         (optional FL)
 *   "notes": "Rerouted via DOTTY"            (optional)
 * }
 */

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

define('CTP_API_INCLUDED', true);
require_once(__DIR__ . '/../common.php');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    respond_json(405, ['status' => 'error', 'message' => 'Method not allowed. Use POST.']);
}

$cid = ctp_require_auth();
$conn_tmi = ctp_get_conn_tmi();
$payload = read_request_payload();

$ctp_control_id = isset($payload['ctp_control_id']) ? (int)$payload['ctp_control_id'] : 0;
if ($ctp_control_id <= 0) {
    respond_json(400, ['status' => 'error', 'message' => 'ctp_control_id is required.']);
}

$segment = isset($payload['segment']) ? strtoupper(trim($payload['segment'])) : '';
if (!in_array($segment, ['NA', 'OCEANIC', 'EU'])) {
    respond_json(400, ['status' => 'error', 'message' => 'segment must be NA, OCEANIC, or EU.']);
}

$route_string = isset($payload['route_string']) ? trim($payload['route_string']) : '';
if ($route_string === '') {
    respond_json(400, ['status' => 'error', 'message' => 'route_string is required.']);
}

$altitude = isset($payload['altitude']) ? (int)$payload['altitude'] : null;
$notes = isset($payload['notes']) ? trim($payload['notes']) : null;

// Get flight record
$flight_result = ctp_fetch_one($conn_tmi,
    "SELECT ctp_control_id, session_id, callsign, seg_na_route, seg_oceanic_route, seg_eu_route, modified_route, filed_route FROM dbo.ctp_flight_control WHERE ctp_control_id = ?",
    [$ctp_control_id]
);
if (!$flight_result['success'] || !$flight_result['data']) {
    respond_json(404, ['status' => 'error', 'message' => 'Flight not found.']);
}
$flight = $flight_result['data'];
$session_id = (int)$flight['session_id'];

// Get session and check perspective
$session = ctp_get_session($conn_tmi, $session_id);
if (!$session) {
    respond_json(404, ['status' => 'error', 'message' => 'Session not found.']);
}
if (!in_array($session['status'], ['DRAFT', 'ACTIVE', 'MONITORING'])) {
    respond_json(409, ['status' => 'error', 'message' => 'Session is not editable: ' . $session['status']]);
}
if (!ctp_check_perspective($session, $segment)) {
    respond_json(403, ['status' => 'error', 'message' => 'You do not have permission to edit the ' . $segment . ' segment.']);
}

// Validate route via PostGIS if GIS available
$validation_result = null;
$conn_gis = ctp_get_conn_gis();
if ($conn_gis) {
    try {
        $validation_rules = [];
        if (!empty($session['constrained_firs'])) {
            $firs = json_decode($session['constrained_firs'], true);
            if (is_array($firs)) $validation_rules['constrained_firs'] = $firs;
        }

        $sql = "SELECT * FROM validate_oceanic_route(:route, NULL, NULL, :rules)";
        $stmt = $conn_gis->prepare($sql);
        $stmt->execute([
            ':route' => $route_string,
            ':rules' => json_encode($validation_rules)
        ]);
        $validation_result = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        // GIS validation is best-effort; continue without it
    }
}

// Build geojson from validation result if available
$route_geojson = null;
if ($validation_result && !empty($validation_result['geojson'])) {
    $route_geojson = $validation_result['geojson'];
}

// Save the old segment for audit
$seg_col = 'seg_' . strtolower($segment) . '_route';
$old_segment = $flight[$seg_col] ?? null;

// Update the segment
$now = gmdate('Y-m-d H:i:s');
$seg_route_col = 'seg_' . strtolower($segment) . '_route';
$seg_status_col = 'seg_' . strtolower($segment) . '_status';
$seg_modified_by_col = 'seg_' . strtolower($segment) . '_modified_by';
$seg_modified_at_col = 'seg_' . strtolower($segment) . '_modified_at';

// Rebuild full modified route from segments
$seg_na = ($segment === 'NA') ? $route_string : ($flight['seg_na_route'] ?? '');
$seg_oceanic = ($segment === 'OCEANIC') ? $route_string : ($flight['seg_oceanic_route'] ?? '');
$seg_eu = ($segment === 'EU') ? $route_string : ($flight['seg_eu_route'] ?? '');
$full_modified = trim($seg_na . ' ' . $seg_oceanic . ' ' . $seg_eu);
if ($full_modified === '') $full_modified = $flight['filed_route'];

// Build UPDATE query
$update_sql = "
    UPDATE dbo.ctp_flight_control SET
        {$seg_route_col} = ?,
        {$seg_status_col} = 'MODIFIED',
        {$seg_modified_by_col} = ?,
        {$seg_modified_at_col} = ?,
        modified_route = ?,
        route_status = 'MODIFIED',
        route_geojson = ?,
        route_validation_json = ?,
        swim_push_version = swim_push_version + 1" .
    ($altitude !== null ? ", modified_altitude = ?" : "") .
    ($notes !== null ? ", notes = ?" : "") .
    " WHERE ctp_control_id = ?";

$params = [
    $route_string,
    $cid,
    $now,
    $full_modified,
    $route_geojson,
    $validation_result ? json_encode($validation_result) : null
];
if ($altitude !== null) $params[] = $altitude * 100;
if ($notes !== null) $params[] = $notes;
$params[] = $ctp_control_id;

$result = ctp_execute($conn_tmi, $update_sql, $params);
if (!$result['success']) {
    respond_json(500, ['status' => 'error', 'message' => 'Failed to update route.']);
}

// Audit log
ctp_audit_log($conn_tmi, $session_id, $ctp_control_id, 'ROUTE_MODIFY', [
    'segment' => $segment,
    'old_route' => $old_segment,
    'new_route' => $route_string,
    'altitude' => $altitude,
    'validation_valid' => $validation_result ? ($validation_result['valid'] === true || $validation_result['valid'] === 't') : null
], $cid, $segment);

// SWIM push
ctp_push_swim_event('ctp.route.modified', [
    'session_id' => $session_id,
    'ctp_control_id' => $ctp_control_id,
    'callsign' => $flight['callsign'],
    'segment' => $segment
]);

respond_json(200, [
    'status' => 'ok',
    'data' => [
        'ctp_control_id' => $ctp_control_id,
        'segment' => $segment,
        'route_status' => 'MODIFIED',
        'validation' => $validation_result ? [
            'valid' => ($validation_result['valid'] === true || $validation_result['valid'] === 't'),
            'errors' => isset($validation_result['errors']) ? pgArrayToPhp($validation_result['errors']) : [],
            'warnings' => isset($validation_result['warnings']) ? pgArrayToPhp($validation_result['warnings']) : []
        ] : null
    ]
]);

function pgArrayToPhp($str) {
    if (!$str || $str === '{}' || $str === 'NULL') return [];
    $str = trim($str, '{}');
    if ($str === '') return [];
    return array_map('trim', explode(',', $str));
}
