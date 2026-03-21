<?php
/**
 * CTP Planning Simulator — Compute Engine
 *
 * POST /api/ctp/planning/compute.php
 * Body: { scenario_id }
 *
 * Runs a 5-step algorithm to simulate departure profiles, oceanic entry
 * times, and arrival profiles for a planning scenario:
 *   1. Spread departures per block (distribution types)
 *   2. Route expansion via PostGIS expand_route()
 *   3. Aircraft performance lookup (BADA cruise speeds)
 *   4. Compute oceanic entry times
 *   5. Constraint checks against throughput configs (if session-linked)
 *
 * Uses 3 database connections:
 *   - $conn_tmi (VATSIM_TMI) — planning tables, throughput configs
 *   - $conn_gis (VATSIM_GIS) — route expansion via PostGIS
 *   - $conn_adl (VATSIM_ADL) — aircraft performance lookup
 *
 * Returns ECharts-compatible binned data.
 *
 * @version 1.0.0
 */
header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    http_response_code(204);
    exit;
}
define('CTP_API_INCLUDED', true);
require_once(__DIR__ . '/../common.php');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond_json(405, ['status' => 'error', 'message' => 'Method not allowed']);
}

$cid = ctp_require_auth();
$payload = read_request_payload();

// ============================================================================
// Validate input
// ============================================================================

$scenario_id = isset($payload['scenario_id']) ? (int)$payload['scenario_id'] : 0;
if ($scenario_id <= 0) {
    respond_json(400, ['status' => 'error', 'message' => 'scenario_id is required']);
}

// ============================================================================
// Load scenario + blocks + assignments
// ============================================================================

$conn_tmi = ctp_get_conn_tmi();

// Fetch scenario
$scenario_result = ctp_fetch_one($conn_tmi,
    "SELECT scenario_id, session_id, scenario_name,
            departure_window_start, departure_window_end,
            status, notes, created_by
     FROM dbo.ctp_planning_scenarios
     WHERE scenario_id = ?",
    [$scenario_id]);

if (!$scenario_result['success'] || !$scenario_result['data']) {
    respond_json(404, ['status' => 'error', 'message' => 'Scenario not found']);
}

$scenario = $scenario_result['data'];

// Parse departure window
$dep_start = new DateTime($scenario['departure_window_start']);
$dep_end = new DateTime($scenario['departure_window_end']);
$dep_start->setTimezone(new DateTimeZone('UTC'));
$dep_end->setTimezone(new DateTimeZone('UTC'));
$window_minutes = (int)(($dep_end->getTimestamp() - $dep_start->getTimestamp()) / 60);

if ($window_minutes <= 0) {
    respond_json(400, [
        'status' => 'error',
        'message' => 'Invalid departure window: end must be after start'
    ]);
}

// Load traffic blocks
$blocks_result = ctp_fetch_all($conn_tmi,
    "SELECT block_id, scenario_id, block_label, origins_json, destinations_json,
            flight_count, dep_distribution, dep_distribution_json, aircraft_mix_json
     FROM dbo.ctp_planning_traffic_blocks
     WHERE scenario_id = ?
     ORDER BY block_id",
    [$scenario_id]);

if (!$blocks_result['success']) {
    respond_json(500, [
        'status' => 'error',
        'message' => 'Failed to load traffic blocks',
        'error' => $blocks_result['error']
    ]);
}

$blocks = $blocks_result['data'];
if (empty($blocks)) {
    respond_json(400, [
        'status' => 'error',
        'message' => 'Scenario has no traffic blocks. Add at least one block before computing.'
    ]);
}

// Load track assignments for each block
$block_ids = array_map(function($b) { return (int)$b['block_id']; }, $blocks);
$placeholders = implode(',', array_fill(0, count($block_ids), '?'));
$assignments_result = ctp_fetch_all($conn_tmi,
    "SELECT assignment_id, block_id, track_name, route_string, flight_count, altitude_range
     FROM dbo.ctp_planning_track_assignments
     WHERE block_id IN ($placeholders)
     ORDER BY block_id, assignment_id",
    $block_ids);

if (!$assignments_result['success']) {
    respond_json(500, [
        'status' => 'error',
        'message' => 'Failed to load track assignments',
        'error' => $assignments_result['error']
    ]);
}

// Group assignments by block_id
$assignments_by_block = [];
foreach ($assignments_result['data'] as $a) {
    $bid = (int)$a['block_id'];
    if (!isset($assignments_by_block[$bid])) {
        $assignments_by_block[$bid] = [];
    }
    $assignments_by_block[$bid][] = $a;
}

// ============================================================================
// Establish optional connections (GIS, ADL) — graceful fallback if unavailable
// ============================================================================

$conn_gis = null;
$conn_adl = null;

try {
    $conn_gis = get_conn_gis();
} catch (Exception $e) {
    // GIS unavailable — will use default distances
}

try {
    $conn_adl = get_conn_adl();
} catch (Exception $e) {
    // ADL unavailable — will use default cruise speeds
}

// ============================================================================
// Step 2 — Route expansion via PostGIS
// ============================================================================

$route_cache = []; // route_string => distance_nm

/**
 * Expand a route string using PostGIS and return total distance in NM.
 */
function expandRouteForCompute($conn_gis, $route_string) {
    if (!$conn_gis || !$route_string) return [];
    try {
        $stmt = $conn_gis->prepare("SELECT * FROM expand_route(?)");
        $stmt->execute([$route_string]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return [];
    }
}

/**
 * Calculate total route distance from expanded waypoints.
 */
function calculateRouteDistance($waypoints) {
    if (empty($waypoints)) return null;
    $total = 0.0;
    for ($i = 1; $i < count($waypoints); $i++) {
        $lat1 = isset($waypoints[$i - 1]['latitude']) ? (float)$waypoints[$i - 1]['latitude'] : null;
        $lon1 = isset($waypoints[$i - 1]['longitude']) ? (float)$waypoints[$i - 1]['longitude'] : null;
        $lat2 = isset($waypoints[$i]['latitude']) ? (float)$waypoints[$i]['latitude'] : null;
        $lon2 = isset($waypoints[$i]['longitude']) ? (float)$waypoints[$i]['longitude'] : null;

        if ($lat1 === null || $lon1 === null || $lat2 === null || $lon2 === null) continue;

        $total += haversineNm($lat1, $lon1, $lat2, $lon2);
    }
    return $total > 0 ? $total : null;
}

/**
 * Haversine distance in nautical miles.
 */
function haversineNm($lat1, $lon1, $lat2, $lon2) {
    $R = 3440.065; // Earth radius in NM
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    $a = sin($dLat / 2) * sin($dLat / 2)
       + cos(deg2rad($lat1)) * cos(deg2rad($lat2))
       * sin($dLon / 2) * sin($dLon / 2);
    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
    return $R * $c;
}

// Pre-expand all unique route strings
$unique_routes = [];
foreach ($blocks as $block) {
    $bid = (int)$block['block_id'];
    if (isset($assignments_by_block[$bid])) {
        foreach ($assignments_by_block[$bid] as $asgn) {
            $rs = isset($asgn['route_string']) ? trim($asgn['route_string']) : '';
            if ($rs !== '' && !isset($unique_routes[$rs])) {
                $unique_routes[$rs] = true;
            }
        }
    }
}

foreach (array_keys($unique_routes) as $rs) {
    $wpts = expandRouteForCompute($conn_gis, $rs);
    $dist = calculateRouteDistance($wpts);
    $route_cache[$rs] = $dist !== null ? $dist : 3000; // Default 3000nm for NAT
}

// ============================================================================
// Step 3 — Aircraft performance lookup
// ============================================================================

$perf_cache = []; // icao_code => cruise_ktas

/**
 * Get cruise speed (KTAS) for an aircraft type from BADA performance data.
 */
function getAircraftPerformance($conn_adl, $aircraft_icao) {
    if (!$conn_adl || !$aircraft_icao) return ['cruise_ktas' => 460];

    $sql = "SELECT TOP 1 cruise_speed_ktas FROM dbo.bada_aircraft_performance WHERE icao_code = ?";
    $stmt = sqlsrv_query($conn_adl, $sql, [$aircraft_icao]);
    if ($stmt && $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        sqlsrv_free_stmt($stmt);
        return ['cruise_ktas' => (int)$row['cruise_speed_ktas']];
    }
    if ($stmt) sqlsrv_free_stmt($stmt);
    return ['cruise_ktas' => 460]; // Fallback for unknown types
}

// Pre-fetch all unique aircraft types across blocks
$all_aircraft_types = [];
foreach ($blocks as $block) {
    $mix = !empty($block['aircraft_mix_json']) ? json_decode($block['aircraft_mix_json'], true) : null;
    if ($mix && is_array($mix)) {
        foreach ($mix as $entry) {
            $icao = isset($entry['icao']) ? strtoupper(trim($entry['icao'])) : null;
            if ($icao && !isset($perf_cache[$icao])) {
                $perf_cache[$icao] = null; // placeholder
                $all_aircraft_types[] = $icao;
            }
        }
    }
}

foreach ($all_aircraft_types as $icao) {
    $perf_cache[$icao] = getAircraftPerformance($conn_adl, $icao);
}

// ============================================================================
// Step 1 — Spread departures per block + Step 4 — Oceanic entry times
// ============================================================================

// Constants
$DEFAULT_ROUTE_DISTANCE_NM = 3000;
$DEFAULT_PRE_OCEANIC_NM = 400;
$DEFAULT_CRUISE_KTAS = 460;
$BIN_SIZE_MINUTES = 15;

// Build time bins for the departure window
$num_bins = (int)ceil($window_minutes / $BIN_SIZE_MINUTES);
$dep_bins = [];
for ($i = 0; $i < $num_bins; $i++) {
    $bin_start = clone $dep_start;
    $bin_start->modify('+' . ($i * $BIN_SIZE_MINUTES) . ' minutes');
    $bin_end = clone $dep_start;
    $bin_end->modify('+' . min(($i + 1) * $BIN_SIZE_MINUTES, $window_minutes) . ' minutes');
    $dep_bins[$i] = [
        'start_utc' => $bin_start->format('Y-m-d\TH:i:s') . 'Z',
        'end_utc' => $bin_end->format('Y-m-d\TH:i:s') . 'Z',
        'start_ts' => $bin_start->getTimestamp(),
        'end_ts' => $bin_end->getTimestamp(),
        'count' => 0,
        'by_track' => [],
    ];
}

// Extended bins for oceanic entry (up to +4h past departure window for pre-oceanic transit)
$extended_end = clone $dep_end;
$extended_end->modify('+4 hours');
$extended_minutes = (int)(($extended_end->getTimestamp() - $dep_start->getTimestamp()) / 60);
$num_extended_bins = (int)ceil($extended_minutes / $BIN_SIZE_MINUTES);

$oceanic_bins = [];
for ($i = 0; $i < $num_extended_bins; $i++) {
    $bin_start_oc = clone $dep_start;
    $bin_start_oc->modify('+' . ($i * $BIN_SIZE_MINUTES) . ' minutes');
    $bin_end_oc = clone $dep_start;
    $bin_end_oc->modify('+' . min(($i + 1) * $BIN_SIZE_MINUTES, $extended_minutes) . ' minutes');
    $oceanic_bins[$i] = [
        'start_utc' => $bin_start_oc->format('Y-m-d\TH:i:s') . 'Z',
        'end_utc' => $bin_end_oc->format('Y-m-d\TH:i:s') . 'Z',
        'start_ts' => $bin_start_oc->getTimestamp(),
        'end_ts' => $bin_end_oc->getTimestamp(),
        'count' => 0,
        'by_track' => [],
    ];
}

// Extended bins for arrivals (up to +10h past departure window for full crossing)
$arrival_end = clone $dep_end;
$arrival_end->modify('+10 hours');
$arrival_minutes = (int)(($arrival_end->getTimestamp() - $dep_start->getTimestamp()) / 60);
$num_arrival_bins = (int)ceil($arrival_minutes / $BIN_SIZE_MINUTES);

$arrival_bins = [];
for ($i = 0; $i < $num_arrival_bins; $i++) {
    $bin_start_ar = clone $dep_start;
    $bin_start_ar->modify('+' . ($i * $BIN_SIZE_MINUTES) . ' minutes');
    $bin_end_ar = clone $dep_start;
    $bin_end_ar->modify('+' . min(($i + 1) * $BIN_SIZE_MINUTES, $arrival_minutes) . ' minutes');
    $arrival_bins[$i] = [
        'start_utc' => $bin_start_ar->format('Y-m-d\TH:i:s') . 'Z',
        'end_utc' => $bin_end_ar->format('Y-m-d\TH:i:s') . 'Z',
        'start_ts' => $bin_start_ar->getTimestamp(),
        'end_ts' => $bin_end_ar->getTimestamp(),
        'count' => 0,
        'by_dest' => [],
    ];
}

$tracks_used = [];
$total_flights = 0;
$track_stats = []; // track_name => [flight_count, total_delay, peak_rates[]]

/**
 * Generate departure times within a window using the specified distribution.
 *
 * @param int $count Number of departures
 * @param int $start_ts Window start timestamp
 * @param int $end_ts Window end timestamp
 * @param string $distribution UNIFORM, FRONT_LOADED, BACK_LOADED, CUSTOM
 * @param array|null $custom_weights Custom distribution weights per 15-min bin
 * @return array Array of Unix timestamps for synthetic departures
 */
function generateDepartures($count, $start_ts, $end_ts, $distribution, $custom_weights = null) {
    if ($count <= 0 || $end_ts <= $start_ts) return [];

    $window = $end_ts - $start_ts;
    $times = [];

    switch ($distribution) {
        case 'FRONT_LOADED':
            // Higher density in first third of window
            for ($i = 0; $i < $count; $i++) {
                // Beta-like distribution: use sqrt to skew toward start
                $r = mt_rand(0, 10000) / 10000.0;
                $skewed = $r * $r; // Squares concentrate near 0 (start)
                $times[] = $start_ts + (int)($skewed * $window);
            }
            break;

        case 'BACK_LOADED':
            // Higher density in last third of window
            for ($i = 0; $i < $count; $i++) {
                $r = mt_rand(0, 10000) / 10000.0;
                $skewed = 1.0 - ((1.0 - $r) * (1.0 - $r)); // Inverted square
                $times[] = $start_ts + (int)($skewed * $window);
            }
            break;

        case 'CUSTOM':
            if ($custom_weights && is_array($custom_weights) && !empty($custom_weights)) {
                // Weights per 15-min bin
                $total_weight = array_sum($custom_weights);
                if ($total_weight <= 0) {
                    // Fallback to uniform
                    return generateDepartures($count, $start_ts, $end_ts, 'UNIFORM');
                }

                $bin_duration = 900; // 15 min in seconds
                $num_weight_bins = count($custom_weights);
                $remaining = $count;
                $bin_index = 0;

                foreach ($custom_weights as $weight) {
                    $bin_start = $start_ts + ($bin_index * $bin_duration);
                    $bin_end = min($bin_start + $bin_duration, $end_ts);
                    if ($bin_start >= $end_ts) break;

                    // Flights in this bin proportional to weight
                    $bin_count = ($bin_index === $num_weight_bins - 1)
                        ? $remaining
                        : (int)round($count * ($weight / $total_weight));
                    $bin_count = min($bin_count, $remaining);
                    $remaining -= $bin_count;

                    // Distribute uniformly within this bin
                    for ($j = 0; $j < $bin_count; $j++) {
                        $times[] = mt_rand($bin_start, max($bin_start, $bin_end - 1));
                    }

                    $bin_index++;
                }
            } else {
                return generateDepartures($count, $start_ts, $end_ts, 'UNIFORM');
            }
            break;

        case 'UNIFORM':
        default:
            // Evenly spaced
            if ($count === 1) {
                $times[] = $start_ts + (int)($window / 2);
            } else {
                $spacing = $window / $count;
                for ($i = 0; $i < $count; $i++) {
                    $times[] = $start_ts + (int)($spacing * ($i + 0.5));
                }
            }
            break;
    }

    sort($times);
    return $times;
}

// ============================================================================
// Process each block and its track assignments
// ============================================================================

foreach ($blocks as $block) {
    $bid = (int)$block['block_id'];
    $block_assignments = isset($assignments_by_block[$bid]) ? $assignments_by_block[$bid] : [];

    if (empty($block_assignments)) continue;

    $distribution = isset($block['dep_distribution']) ? strtoupper($block['dep_distribution']) : 'UNIFORM';
    $custom_weights = !empty($block['dep_distribution_json'])
        ? json_decode($block['dep_distribution_json'], true) : null;
    $aircraft_mix = !empty($block['aircraft_mix_json'])
        ? json_decode($block['aircraft_mix_json'], true) : null;
    $destinations = !empty($block['destinations_json'])
        ? json_decode($block['destinations_json'], true) : [];

    // Determine weighted cruise speed for this block
    $block_cruise_ktas = $DEFAULT_CRUISE_KTAS;
    if ($aircraft_mix && is_array($aircraft_mix)) {
        $weighted_speed = 0;
        $total_pct = 0;
        foreach ($aircraft_mix as $entry) {
            $icao = isset($entry['icao']) ? strtoupper(trim($entry['icao'])) : null;
            $pct = isset($entry['percent']) ? (float)$entry['percent'] : 0;
            if ($icao && isset($perf_cache[$icao]) && $pct > 0) {
                $weighted_speed += $perf_cache[$icao]['cruise_ktas'] * $pct;
                $total_pct += $pct;
            }
        }
        if ($total_pct > 0) {
            $block_cruise_ktas = $weighted_speed / $total_pct;
        }
    }

    // Process each track assignment
    foreach ($block_assignments as $asgn) {
        $track_name = isset($asgn['track_name']) ? trim($asgn['track_name']) : 'UNASSIGNED';
        $route_string = isset($asgn['route_string']) ? trim($asgn['route_string']) : '';
        $asgn_flight_count = isset($asgn['flight_count']) ? (int)$asgn['flight_count'] : 0;

        if ($asgn_flight_count <= 0) continue;

        $total_flights += $asgn_flight_count;
        if (!in_array($track_name, $tracks_used)) {
            $tracks_used[] = $track_name;
        }

        // Route distance (from cache or default)
        $route_distance = ($route_string !== '' && isset($route_cache[$route_string]))
            ? $route_cache[$route_string]
            : $DEFAULT_ROUTE_DISTANCE_NM;

        // Step 1: Generate departure times
        $dep_times = generateDepartures(
            $asgn_flight_count,
            $dep_start->getTimestamp(),
            $dep_end->getTimestamp(),
            $distribution,
            $custom_weights
        );

        // Step 4: For each flight, compute oceanic entry and arrival
        foreach ($dep_times as $dep_ts) {
            // Bin the departure
            $dep_bin_idx = (int)floor(($dep_ts - $dep_start->getTimestamp()) / ($BIN_SIZE_MINUTES * 60));
            $dep_bin_idx = max(0, min($dep_bin_idx, count($dep_bins) - 1));
            $dep_bins[$dep_bin_idx]['count']++;
            if (!isset($dep_bins[$dep_bin_idx]['by_track'][$track_name])) {
                $dep_bins[$dep_bin_idx]['by_track'][$track_name] = 0;
            }
            $dep_bins[$dep_bin_idx]['by_track'][$track_name]++;

            // Oceanic entry: dep_time + (pre-oceanic distance / cruise speed) in minutes
            $pre_oceanic_hours = $DEFAULT_PRE_OCEANIC_NM / $block_cruise_ktas;
            $entry_ts = $dep_ts + (int)($pre_oceanic_hours * 3600);

            $oc_bin_idx = (int)floor(($entry_ts - $dep_start->getTimestamp()) / ($BIN_SIZE_MINUTES * 60));
            $oc_bin_idx = max(0, min($oc_bin_idx, count($oceanic_bins) - 1));
            $oceanic_bins[$oc_bin_idx]['count']++;
            if (!isset($oceanic_bins[$oc_bin_idx]['by_track'][$track_name])) {
                $oceanic_bins[$oc_bin_idx]['by_track'][$track_name] = 0;
            }
            $oceanic_bins[$oc_bin_idx]['by_track'][$track_name]++;

            // Arrival estimate: dep_time + (total route distance / cruise speed)
            $total_flight_hours = $route_distance / $block_cruise_ktas;
            $arrival_ts = $dep_ts + (int)($total_flight_hours * 3600);

            $ar_bin_idx = (int)floor(($arrival_ts - $dep_start->getTimestamp()) / ($BIN_SIZE_MINUTES * 60));
            $ar_bin_idx = max(0, min($ar_bin_idx, count($arrival_bins) - 1));

            // Pick a destination from the block's destination list
            $dest = 'UNKN';
            if (!empty($destinations)) {
                $dest_idx = mt_rand(0, count($destinations) - 1);
                $dest = $destinations[$dest_idx];
            }

            $arrival_bins[$ar_bin_idx]['count']++;
            if (!isset($arrival_bins[$ar_bin_idx]['by_dest'][$dest])) {
                $arrival_bins[$ar_bin_idx]['by_dest'][$dest] = 0;
            }
            $arrival_bins[$ar_bin_idx]['by_dest'][$dest]++;

            // Track stats
            if (!isset($track_stats[$track_name])) {
                $track_stats[$track_name] = [
                    'flight_count' => 0,
                    'total_transit_min' => 0,
                ];
            }
            $track_stats[$track_name]['flight_count']++;
            $track_stats[$track_name]['total_transit_min'] += $total_flight_hours * 60;
        }
    }
}

// ============================================================================
// Step 5 — Constraint checks (only if scenario has session_id)
// ============================================================================

$constraint_checks = [];
$constraint_violations = 0;

$session_id = $scenario['session_id'];
if ($session_id) {
    // Load active throughput configs for this session
    $configs_result = ctp_fetch_all($conn_tmi,
        "SELECT config_id, config_label, tracks_json, origins_json, destinations_json,
                max_acph, priority
         FROM dbo.ctp_track_throughput_config
         WHERE session_id = ? AND is_active = 1
         ORDER BY priority ASC",
        [(int)$session_id]);

    if ($configs_result['success'] && !empty($configs_result['data'])) {
        foreach ($configs_result['data'] as $cfg) {
            $cfg_tracks = !empty($cfg['tracks_json']) ? json_decode($cfg['tracks_json'], true) : null;
            $max_acph = (int)$cfg['max_acph'];
            $max_per_bin = $max_acph / (60 / $BIN_SIZE_MINUTES); // Convert ACPH to per-bin max

            // Count flights per bin for matching tracks in oceanic entry profile
            $peak_actual = 0;
            $bins_over = 0;

            foreach ($oceanic_bins as $bin) {
                $bin_count = 0;
                if ($cfg_tracks && is_array($cfg_tracks)) {
                    // Sum only matching tracks
                    foreach ($cfg_tracks as $ct) {
                        if (isset($bin['by_track'][$ct])) {
                            $bin_count += $bin['by_track'][$ct];
                        }
                    }
                } else {
                    // No track filter — count all
                    $bin_count = $bin['count'];
                }

                // Scale bin_count to per-hour rate
                $hourly_rate = $bin_count * (60 / $BIN_SIZE_MINUTES);
                if ($hourly_rate > $peak_actual) {
                    $peak_actual = $hourly_rate;
                }
                if ($hourly_rate > $max_acph) {
                    $bins_over++;
                }
            }

            $violated = $peak_actual > $max_acph;
            if ($violated) $constraint_violations++;

            $constraint_checks[] = [
                'config_label' => $cfg['config_label'],
                'max_acph' => $max_acph,
                'peak_actual' => (int)$peak_actual,
                'violated' => $violated,
                'bins_over' => $bins_over,
            ];
        }
    }
}

// ============================================================================
// Build track_summary
// ============================================================================

$track_summary = [];
foreach ($track_stats as $tn => $stats) {
    // Calculate peak hourly rate from oceanic entry bins
    $peak_rate = 0;
    foreach ($oceanic_bins as $bin) {
        $bin_count = isset($bin['by_track'][$tn]) ? $bin['by_track'][$tn] : 0;
        $hourly = $bin_count * (60 / $BIN_SIZE_MINUTES);
        if ($hourly > $peak_rate) $peak_rate = $hourly;
    }

    $avg_transit = $stats['flight_count'] > 0
        ? round($stats['total_transit_min'] / $stats['flight_count'], 1)
        : 0;

    $track_summary[] = [
        'track' => $tn,
        'flight_count' => $stats['flight_count'],
        'avg_transit_min' => $avg_transit,
        'peak_rate_hr' => (int)$peak_rate,
    ];
}

// ============================================================================
// Format output bins (strip internal timestamps)
// ============================================================================

$format_dep_bins = [];
foreach ($dep_bins as $bin) {
    if ($bin['count'] > 0 || true) { // Include all bins for consistent charting
        $format_dep_bins[] = [
            'start_utc' => $bin['start_utc'],
            'end_utc' => $bin['end_utc'],
            'count' => $bin['count'],
            'by_track' => $bin['by_track'],
        ];
    }
}

$format_oceanic_bins = [];
foreach ($oceanic_bins as $bin) {
    $format_oceanic_bins[] = [
        'start_utc' => $bin['start_utc'],
        'end_utc' => $bin['end_utc'],
        'count' => $bin['count'],
        'by_track' => $bin['by_track'],
    ];
}

$format_arrival_bins = [];
foreach ($arrival_bins as $bin) {
    $format_arrival_bins[] = [
        'start_utc' => $bin['start_utc'],
        'end_utc' => $bin['end_utc'],
        'count' => $bin['count'],
        'by_dest' => $bin['by_dest'],
    ];
}

// ============================================================================
// Respond
// ============================================================================

// Build blocks array with nested assignments for UI rendering
$blocks_output = [];
foreach ($blocks as $block) {
    $bid = (int)$block['block_id'];
    $b = [
        'block_id' => $bid,
        'scenario_id' => (int)$block['scenario_id'],
        'block_label' => $block['block_label'],
        'origins_json' => !empty($block['origins_json']) ? json_decode($block['origins_json'], true) : [],
        'destinations_json' => !empty($block['destinations_json']) ? json_decode($block['destinations_json'], true) : [],
        'flight_count' => (int)$block['flight_count'],
        'dep_distribution' => $block['dep_distribution'],
        'assignments' => isset($assignments_by_block[$bid]) ? array_values($assignments_by_block[$bid]) : [],
    ];
    $blocks_output[] = $b;
}

respond_json(200, [
    'status' => 'ok',
    'data' => [
        'scenario_id' => $scenario_id,
        'scenario_name' => $scenario['scenario_name'],
        'departure_window' => [
            'start' => $dep_start->format('Y-m-d\TH:i:s') . 'Z',
            'end' => $dep_end->format('Y-m-d\TH:i:s') . 'Z',
        ],
        'blocks' => $blocks_output,
        'summary' => [
            'total_flights' => $total_flights,
            'tracks_used' => $tracks_used,
            'constraint_violations' => $constraint_violations,
        ],
        'departure_profile' => [
            'bins' => $format_dep_bins,
        ],
        'oceanic_entry_profile' => [
            'bins' => $format_oceanic_bins,
        ],
        'arrival_profile' => [
            'bins' => $format_arrival_bins,
        ],
        'constraint_checks' => $constraint_checks,
        'track_summary' => $track_summary,
    ],
]);
