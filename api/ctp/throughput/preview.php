<?php
header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('Access-Control-Allow-Methods: GET, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    http_response_code(204);
    exit;
}
define('CTP_API_INCLUDED', true);
require_once(__DIR__ . '/../common.php');

// Method check
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    respond_json(405, ['status' => 'error', 'message' => 'Method not allowed.']);
}

$cid = ctp_require_auth();
$conn_tmi = ctp_get_conn_tmi();

// Validate session_id parameter
$session_id = (int)($_GET['session_id'] ?? 0);
if ($session_id <= 0) {
    respond_json(400, ['status' => 'error', 'message' => 'session_id required.']);
}

// Verify session exists
$session = ctp_get_session($conn_tmi, $session_id);
if (!$session) {
    respond_json(404, ['status' => 'error', 'message' => 'Session not found.']);
}

// Get config criteria - either from existing config_id or from proposed params
$config_id = isset($_GET['config_id']) ? (int)$_GET['config_id'] : 0;
$tracks_json = null;
$origins_json = null;
$destinations_json = null;
$max_acph = 0;
$config_label = null;

if ($config_id > 0) {
    // Load existing config
    $config = ctp_fetch_one($conn_tmi,
        "SELECT config_label, tracks_json, origins_json, destinations_json, max_acph
         FROM dbo.ctp_track_throughput_config
         WHERE config_id = ? AND session_id = ?",
        [$config_id, $session_id]);

    if (!$config['success'] || !$config['data']) {
        respond_json(404, ['status' => 'error', 'message' => 'Config not found.']);
    }

    $config_label = $config['data']['config_label'];
    $tracks_json = $config['data']['tracks_json'];
    $origins_json = $config['data']['origins_json'];
    $destinations_json = $config['data']['destinations_json'];
    $max_acph = (int)$config['data']['max_acph'];
} else {
    // Use proposed parameters
    $tracks_json = isset($_GET['tracks_json']) ? $_GET['tracks_json'] : null;
    $origins_json = isset($_GET['origins_json']) ? $_GET['origins_json'] : null;
    $destinations_json = isset($_GET['destinations_json']) ? $_GET['destinations_json'] : null;
    $max_acph = isset($_GET['max_acph']) ? (int)$_GET['max_acph'] : 0;

    if ($max_acph <= 0) {
        respond_json(400, ['status' => 'error', 'message' => 'max_acph required for preview.']);
    }
}

// Parse JSON arrays if provided as strings
$tracks_array = null;
if ($tracks_json) {
    $decoded = is_string($tracks_json) ? json_decode($tracks_json, true) : $tracks_json;
    if (is_array($decoded) && !empty($decoded)) {
        $tracks_array = $decoded;
    }
}

$origins_array = null;
if ($origins_json) {
    $decoded = is_string($origins_json) ? json_decode($origins_json, true) : $origins_json;
    if (is_array($decoded) && !empty($decoded)) {
        $origins_array = $decoded;
    }
}

$destinations_array = null;
if ($destinations_json) {
    $decoded = is_string($destinations_json) ? json_decode($destinations_json, true) : $destinations_json;
    if (is_array($decoded) && !empty($decoded)) {
        $destinations_array = $decoded;
    }
}

// Build match query with generalized logic:
// - NULL JSON = match all
// - Non-NULL JSON with values = match flights in that array
$where_conditions = ["session_id = ?"];
$params = [$session_id];

// Tracks filter
if ($tracks_array !== null) {
    $placeholders = implode(',', array_fill(0, count($tracks_array), '?'));
    $where_conditions[] = "resolved_nat_track IN ($placeholders)";
    $params = array_merge($params, $tracks_array);
}

// Origins filter
if ($origins_array !== null) {
    $placeholders = implode(',', array_fill(0, count($origins_array), '?'));
    $where_conditions[] = "dep_airport IN ($placeholders)";
    $params = array_merge($params, $origins_array);
}

// Destinations filter
if ($destinations_array !== null) {
    $placeholders = implode(',', array_fill(0, count($destinations_array), '?'));
    $where_conditions[] = "arr_airport IN ($placeholders)";
    $params = array_merge($params, $destinations_array);
}

// Build query to bin by 15-minute intervals
$where_clause = implode(' AND ', $where_conditions);

$binning_query = "
    SELECT
        DATEADD(MINUTE, (DATEDIFF(MINUTE, 0, oceanic_entry_utc) / 15) * 15, 0) AS bin_start,
        COUNT(*) AS flight_count
    FROM dbo.ctp_flight_control
    WHERE $where_clause
      AND oceanic_entry_utc IS NOT NULL
    GROUP BY DATEADD(MINUTE, (DATEDIFF(MINUTE, 0, oceanic_entry_utc) / 15) * 15, 0)
    ORDER BY bin_start ASC
";

$result = ctp_fetch_all($conn_tmi, $binning_query, $params);

if (!$result['success']) {
    respond_json(500, ['status' => 'error', 'message' => 'Query failed.', 'error' => $result['error']]);
}

// Process bins and identify exceedances
$bins = [];
$total_flights = 0;
$bins_exceeding = 0;
$max_bin_count = 0;

foreach ($result['data'] as $row) {
    $bin_start_iso = datetime_to_iso($row['bin_start']);
    $flight_count = (int)$row['flight_count'];
    $total_flights += $flight_count;

    $exceeds = $flight_count > $max_acph;
    if ($exceeds) {
        $bins_exceeding++;
    }

    if ($flight_count > $max_bin_count) {
        $max_bin_count = $flight_count;
    }

    $bins[] = [
        'bin_start' => $bin_start_iso,
        'flight_count' => $flight_count,
        'max_acph' => $max_acph,
        'exceeds' => $exceeds,
        'overage' => $exceeds ? ($flight_count - $max_acph) : 0
    ];
}

respond_json(200, [
    'status' => 'ok',
    'data' => [
        'config_label' => $config_label,
        'max_acph' => $max_acph,
        'total_flights' => $total_flights,
        'total_bins' => count($bins),
        'bins_exceeding' => $bins_exceeding,
        'max_bin_count' => $max_bin_count,
        'bins' => $bins,
        'criteria' => [
            'tracks' => $tracks_array,
            'origins' => $origins_array,
            'destinations' => $destinations_array
        ]
    ]
]);
