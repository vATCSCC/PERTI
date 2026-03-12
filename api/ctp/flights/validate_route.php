<?php
/**
 * CTP Flights - Validate Route API
 *
 * POST /api/ctp/flights/validate_route.php
 *
 * Validates a route string via PostGIS. Can validate full route or a segment.
 *
 * Request body:
 * {
 *   "session_id": 1,
 *   "route_string": "KJFK HAPIE J80 DOTTY NAT-A GIPER EGLL",
 *   "segment": "OCEANIC",             (optional: NA, OCEANIC, EU)
 *   "dep_airport": "KJFK",            (optional)
 *   "arr_airport": "EGLL",            (optional)
 *   "altitude": 370                   (optional, FL)
 * }
 *
 * Response:
 * {
 *   "status": "ok",
 *   "data": {
 *     "valid": true/false,
 *     "errors": [],
 *     "warnings": [],
 *     "waypoints": [...],
 *     "geojson": { GeoJSON LineString },
 *     "distance_nm": 1234.5,
 *     "entry_fix": "DOTTY",
 *     "exit_fix": "GIPER",
 *     "entry_fir": "CZQX",
 *     "exit_fir": "EGGX",
 *     "artccs_traversed": [...]
 *   }
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

$payload = read_request_payload();

$route_string = isset($payload['route_string']) ? trim($payload['route_string']) : '';
if ($route_string === '') {
    respond_json(400, ['status' => 'error', 'message' => 'route_string is required.']);
}

$session_id = isset($payload['session_id']) ? (int)$payload['session_id'] : 0;
$dep_airport = isset($payload['dep_airport']) ? strtoupper(trim($payload['dep_airport'])) : null;
$arr_airport = isset($payload['arr_airport']) ? strtoupper(trim($payload['arr_airport'])) : null;
$altitude = isset($payload['altitude']) ? (int)$payload['altitude'] : null;

// Build validation rules from session config
$validation_rules = [];
if ($session_id > 0) {
    $conn_tmi = ctp_get_conn_tmi();
    $session = ctp_get_session($conn_tmi, $session_id);
    if ($session) {
        // Parse constrained FIRs
        $firs = !empty($session['constrained_firs']) ? json_decode($session['constrained_firs'], true) : [];
        if (is_array($firs) && !empty($firs)) {
            $validation_rules['constrained_firs'] = $firs;
        }

        // Parse validation rules from session
        if (!empty($session['validation_rules_json'])) {
            $sessionRules = json_decode($session['validation_rules_json'], true);
            if (is_array($sessionRules)) {
                if (!empty($sessionRules['allowed_entry_points'])) {
                    $validation_rules['allowed_entry_points'] = $sessionRules['allowed_entry_points'];
                }
                if (!empty($sessionRules['allowed_exit_points'])) {
                    $validation_rules['allowed_exit_points'] = $sessionRules['allowed_exit_points'];
                }
                if (!empty($sessionRules['altitude_min'])) {
                    $validation_rules['altitude_min'] = (int)$sessionRules['altitude_min'];
                }
                if (!empty($sessionRules['altitude_max'])) {
                    $validation_rules['altitude_max'] = (int)$sessionRules['altitude_max'];
                }
            }
        }
    }
}

// Call PostGIS validation function
$conn_gis = ctp_get_conn_gis();
if (!$conn_gis) {
    respond_json(503, ['status' => 'error', 'message' => 'GIS service unavailable.']);
}

try {
    $sql = "SELECT * FROM validate_oceanic_route(:route, :dep, :arr, :rules)";
    $stmt = $conn_gis->prepare($sql);
    $stmt->execute([
        ':route' => $route_string,
        ':dep' => $dep_airport,
        ':arr' => $arr_airport,
        ':rules' => json_encode($validation_rules)
    ]);

    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        respond_json(500, ['status' => 'error', 'message' => 'Validation returned no result.']);
    }

    // Parse PostgreSQL arrays
    $errors = pgArrayToPhp($row['errors'] ?? '{}');
    $warnings = pgArrayToPhp($row['warnings'] ?? '{}');
    $artccs = pgArrayToPhp($row['artccs_traversed'] ?? '{}');

    respond_json(200, [
        'status' => 'ok',
        'data' => [
            'valid' => ($row['valid'] === true || $row['valid'] === 't'),
            'errors' => $errors,
            'warnings' => $warnings,
            'waypoints' => !empty($row['waypoints']) ? json_decode($row['waypoints'], true) : [],
            'geojson' => !empty($row['geojson']) ? json_decode($row['geojson'], true) : null,
            'distance_nm' => $row['distance_nm'] !== null ? (float)$row['distance_nm'] : null,
            'entry_fix' => $row['entry_fix'],
            'exit_fix' => $row['exit_fix'],
            'entry_fir' => $row['entry_fir'],
            'exit_fir' => $row['exit_fir'],
            'artccs_traversed' => $artccs
        ]
    ]);
} catch (Exception $e) {
    respond_json(500, ['status' => 'error', 'message' => 'Validation failed: ' . $e->getMessage()]);
}

/**
 * Convert PostgreSQL text array to PHP array
 */
function pgArrayToPhp($str) {
    if (!$str || $str === '{}' || $str === 'NULL') return [];
    $str = trim($str, '{}');
    if ($str === '') return [];
    return array_map('trim', explode(',', $str));
}
