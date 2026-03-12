<?php
/**
 * CTP Demand Chart Data API
 *
 * GET /api/ctp/demand.php?session_id=N
 *
 * Returns hourly demand at oceanic entry points for Chart.js visualization.
 * Bins flights by oceanic_entry_utc into configurable time intervals.
 *
 * Optional params:
 *   &bin_min=30       Time bin size in minutes (default 60)
 *   &group_by=fir     Group by: fir (entry FIR), status (EDCT status), fix (entry fix)
 */

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

define('CTP_API_INCLUDED', true);
require_once(__DIR__ . '/common.php');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    respond_json(405, ['status' => 'error', 'message' => 'Method not allowed. Use GET.']);
}

$conn_tmi = ctp_get_conn_tmi();

$session_id = isset($_GET['session_id']) ? (int)$_GET['session_id'] : 0;
if ($session_id <= 0) {
    respond_json(400, ['status' => 'error', 'message' => 'session_id is required.']);
}

$bin_min = isset($_GET['bin_min']) ? (int)$_GET['bin_min'] : 60;
if ($bin_min < 15) $bin_min = 15;
if ($bin_min > 120) $bin_min = 120;

$group_by = isset($_GET['group_by']) ? strtolower(trim($_GET['group_by'])) : 'status';
if (!in_array($group_by, ['fir', 'status', 'fix'])) $group_by = 'status';

// Get session for rate cap
$session = ctp_get_session($conn_tmi, $session_id);
if (!$session) {
    respond_json(404, ['status' => 'error', 'message' => 'Session not found.']);
}

// Get group column
$group_col = 'edct_status';
if ($group_by === 'fir') $group_col = 'oceanic_entry_fir';
elseif ($group_by === 'fix') $group_col = 'oceanic_entry_fix';

// Query flights with entry times
$sql = "
    SELECT oceanic_entry_utc, edct_utc, edct_status, oceanic_entry_fir, oceanic_entry_fix
    FROM dbo.ctp_flight_control
    WHERE session_id = ? AND is_excluded = 0 AND oceanic_entry_utc IS NOT NULL
    ORDER BY oceanic_entry_utc ASC
";
$result = ctp_fetch_all($conn_tmi, $sql, [$session_id]);
if (!$result['success']) {
    respond_json(500, ['status' => 'error', 'message' => 'Failed to fetch demand data.']);
}

$flights = $result['data'];
if (empty($flights)) {
    respond_json(200, ['status' => 'ok', 'data' => [
        'labels' => [], 'datasets' => [], 'rate_cap' => $session['max_slots_per_hour'] ?? null
    ]]);
}

// Determine time range
$min_time = null;
$max_time = null;
foreach ($flights as $f) {
    $t = $f['oceanic_entry_utc'];
    if (!$t) continue;
    if (is_string($t)) $ts = strtotime($t); else $ts = $t->getTimestamp();
    if ($min_time === null || $ts < $min_time) $min_time = $ts;
    if ($max_time === null || $ts > $max_time) $max_time = $ts;
}

// Round to bin boundaries
$bin_sec = $bin_min * 60;
$min_time = (int)floor($min_time / $bin_sec) * $bin_sec;
$max_time = (int)ceil($max_time / $bin_sec) * $bin_sec;

// Build bins
$bins = [];
$labels = [];
for ($t = $min_time; $t <= $max_time; $t += $bin_sec) {
    $bins[$t] = [];
    $labels[] = gmdate('H:i', $t);
}

// Group names collector
$groups = [];

// Assign flights to bins
foreach ($flights as $f) {
    $t = $f['oceanic_entry_utc'];
    if (!$t) continue;
    if (is_string($t)) $ts = strtotime($t); else $ts = $t->getTimestamp();

    $bin_key = (int)floor($ts / $bin_sec) * $bin_sec;
    if (!isset($bins[$bin_key])) $bin_key = $min_time; // fallback

    $group_val = 'Unknown';
    if ($group_by === 'status') {
        $group_val = $f['edct_status'] ?? 'NONE';
    } elseif ($group_by === 'fir') {
        $group_val = $f['oceanic_entry_fir'] ?? 'Unknown';
    } elseif ($group_by === 'fix') {
        $group_val = $f['oceanic_entry_fix'] ?? 'Unknown';
    }

    if (!isset($bins[$bin_key][$group_val])) $bins[$bin_key][$group_val] = 0;
    $bins[$bin_key][$group_val]++;
    $groups[$group_val] = true;
}

// Status colors
$status_colors = [
    'NONE' => 'rgba(173,181,189,0.7)',
    'ASSIGNED' => 'rgba(0,188,212,0.7)',
    'DELIVERED' => 'rgba(40,167,69,0.7)',
    'COMPLIANT' => 'rgba(40,167,69,0.9)',
    'NON_COMPLIANT' => 'rgba(220,53,69,0.7)'
];

// FIR colors (cycle through palette)
$fir_palette = [
    'rgba(0,188,212,0.7)', 'rgba(76,175,80,0.7)', 'rgba(255,152,0,0.7)',
    'rgba(156,39,176,0.7)', 'rgba(33,150,243,0.7)', 'rgba(255,87,34,0.7)'
];

// Build Chart.js datasets
$datasets = [];
$group_keys = array_keys($groups);
sort($group_keys);

foreach ($group_keys as $idx => $gk) {
    $data = [];
    foreach ($bins as $bin_key => $bin_data) {
        $data[] = isset($bin_data[$gk]) ? $bin_data[$gk] : 0;
    }

    $color = 'rgba(100,100,100,0.7)';
    if ($group_by === 'status' && isset($status_colors[$gk])) {
        $color = $status_colors[$gk];
    } else {
        $color = $fir_palette[$idx % count($fir_palette)];
    }

    $datasets[] = [
        'label' => $gk,
        'data' => $data,
        'backgroundColor' => $color,
        'borderWidth' => 1
    ];
}

// Rate cap line (scale to bin size)
$rate_cap = isset($session['max_slots_per_hour']) ? (int)$session['max_slots_per_hour'] : null;
$rate_cap_per_bin = null;
if ($rate_cap) {
    $rate_cap_per_bin = (int)round($rate_cap * ($bin_min / 60));
}

respond_json(200, [
    'status' => 'ok',
    'data' => [
        'labels' => $labels,
        'datasets' => $datasets,
        'rate_cap' => $rate_cap,
        'rate_cap_per_bin' => $rate_cap_per_bin,
        'bin_min' => $bin_min,
        'group_by' => $group_by,
        'total_flights' => count($flights)
    ]
]);
