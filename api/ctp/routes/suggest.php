<?php
/**
 * CTP Route Suggestions API
 *
 * POST /api/ctp/routes/suggest.php
 *
 * Route matching matrix: suggests routes per segment based on origin/destination,
 * event status, and existing route data from multiple sources.
 *
 * Request body:
 * {
 *   "session_id": 1,
 *   "dep_airport": "KJFK",
 *   "arr_airport": "EGLL",
 *   "is_event_flight": false,
 *   "segment": "NA"              (optional: filter to single segment)
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

$conn_tmi = ctp_get_conn_tmi();
$payload = read_request_payload();

$session_id = isset($payload['session_id']) ? (int)$payload['session_id'] : 0;
$dep_airport = isset($payload['dep_airport']) ? strtoupper(trim($payload['dep_airport'])) : '';
$arr_airport = isset($payload['arr_airport']) ? strtoupper(trim($payload['arr_airport'])) : '';
$is_event = !empty($payload['is_event_flight']);
$segment_filter = isset($payload['segment']) ? strtoupper(trim($payload['segment'])) : null;

if (!$dep_airport || !$arr_airport) {
    respond_json(400, ['status' => 'error', 'message' => 'dep_airport and arr_airport are required.']);
}

$suggestions = ['na' => [], 'oceanic' => [], 'eu' => []];

// ============================================================================
// Source 1: CTP Route Templates (ctp_route_templates in VATSIM_TMI)
// ============================================================================
$tpl_sql = "
    SELECT template_name, segment, route_string, altitude_range, priority, origin_filter, dest_filter, for_event_flights
    FROM dbo.ctp_route_templates
    WHERE is_active = 1
      AND (session_id IS NULL OR session_id = ?)
    ORDER BY priority ASC
";
$tpl_result = ctp_fetch_all($conn_tmi, $tpl_sql, [$session_id]);
if ($tpl_result['success']) {
    foreach ($tpl_result['data'] as $tpl) {
        $seg = strtolower($tpl['segment'] ?? 'OCEANIC');
        if ($segment_filter && strtolower($segment_filter) !== $seg) continue;

        $score = 100 - (int)($tpl['priority'] ?? 50);

        // Event flight match bonus
        if ($tpl['for_event_flights'] !== null) {
            if ((bool)$tpl['for_event_flights'] === $is_event) {
                $score += 20;
            } else {
                $score -= 10;
            }
        }

        // Origin filter match
        if (!empty($tpl['origin_filter'])) {
            $originFilter = json_decode($tpl['origin_filter'], true);
            if (is_array($originFilter)) {
                if (in_array($dep_airport, $originFilter)) $score += 50;
                else $score -= 20;
            }
        }

        // Destination filter match
        if (!empty($tpl['dest_filter'])) {
            $destFilter = json_decode($tpl['dest_filter'], true);
            if (is_array($destFilter)) {
                if (in_array($arr_airport, $destFilter)) $score += 50;
                else $score -= 20;
            }
        }

        $suggestions[$seg][] = [
            'source' => 'ctp_template',
            'name' => $tpl['template_name'],
            'route' => $tpl['route_string'],
            'altitude_range' => $tpl['altitude_range'],
            'score' => max(0, $score)
        ];
    }
}

// ============================================================================
// Source 2: Active TMI Reroutes (tmi_reroute_routes in VATSIM_TMI)
// ============================================================================
if (!$segment_filter || $segment_filter === 'NA' || $segment_filter === 'EU') {
    $reroute_sql = "
        SELECT rr.route_string, rr.origin_filter, rr.dest_filter, r.reroute_name
        FROM dbo.tmi_reroute_routes rr
        JOIN dbo.tmi_reroutes r ON r.reroute_id = rr.reroute_id
        WHERE r.status = 'ACTIVE'
          AND (r.valid_end_utc IS NULL OR r.valid_end_utc > SYSUTCDATETIME())
    ";
    $rr_result = ctp_fetch_all($conn_tmi, $reroute_sql, []);
    if ($rr_result['success']) {
        foreach ($rr_result['data'] as $rr) {
            $score = 15; // Base score for active reroute

            if (!empty($rr['origin_filter'])) {
                $origins = array_map('trim', explode(',', $rr['origin_filter']));
                if (in_array($dep_airport, $origins)) $score += 50;
            }
            if (!empty($rr['dest_filter'])) {
                $dests = array_map('trim', explode(',', $rr['dest_filter']));
                if (in_array($arr_airport, $dests)) $score += 50;
            }

            if ($score > 15) {
                $seg = (!$segment_filter || $segment_filter === 'NA') ? 'na' : 'eu';
                $suggestions[$seg][] = [
                    'source' => 'reroute',
                    'name' => $rr['reroute_name'] ?? 'Active Reroute',
                    'route' => $rr['route_string'],
                    'score' => $score
                ];
            }
        }
    }
}

// ============================================================================
// Source 3: Public Routes (tmi_public_routes in VATSIM_TMI)
// ============================================================================
$pub_sql = "
    SELECT route_string, origin_filter, dest_filter, route_name
    FROM dbo.tmi_public_routes
    WHERE is_active = 1
      AND (valid_start_utc IS NULL OR valid_start_utc <= SYSUTCDATETIME())
      AND (valid_end_utc IS NULL OR valid_end_utc >= SYSUTCDATETIME())
";
$pub_result = ctp_fetch_all($conn_tmi, $pub_sql, []);
if ($pub_result['success']) {
    foreach ($pub_result['data'] as $pub) {
        $score = 10;
        if (!empty($pub['origin_filter'])) {
            $origins = array_map('trim', explode(',', $pub['origin_filter']));
            if (in_array($dep_airport, $origins)) $score += 50;
        }
        if (!empty($pub['dest_filter'])) {
            $dests = array_map('trim', explode(',', $pub['dest_filter']));
            if (in_array($arr_airport, $dests)) $score += 50;
        }

        if ($score > 10) {
            $suggestions['na'][] = [
                'source' => 'public_route',
                'name' => $pub['route_name'] ?? 'Public Route',
                'route' => $pub['route_string'],
                'score' => $score
            ];
        }
    }
}

// ============================================================================
// Source 4: CDR Routes (coded_departure_routes in VATSIM_REF via ADL mirror)
// ============================================================================
if (!$segment_filter || $segment_filter === 'NA') {
    $conn_adl = ctp_get_conn_adl();
    $cdr_sql = "
        SELECT TOP 10 full_route, origin_icao, dest_icao, direction, route_name
        FROM dbo.coded_departure_routes
        WHERE origin_icao = ? AND dest_icao = ?
        ORDER BY route_name
    ";
    $cdr_result = ctp_fetch_all($conn_adl, $cdr_sql, [$dep_airport, $arr_airport]);
    if ($cdr_result['success']) {
        foreach ($cdr_result['data'] as $cdr) {
            $suggestions['na'][] = [
                'source' => 'cdr',
                'name' => $cdr['route_name'] ?? 'CDR',
                'route' => $cdr['full_route'],
                'score' => 80 // Exact airport match
            ];
        }
    }
}

// ============================================================================
// Source 5: Playbook Routes (playbook_plays + playbook_routes in MySQL)
// ============================================================================
if (!$segment_filter || $segment_filter === 'NA' || $segment_filter === 'EU') {
    global $conn_pdo;
    if ($conn_pdo) {
        // Extract ARTCC from dep/arr airports for ARTCC-level matching
        $dep_prefix = substr($dep_airport, 0, 1) === 'K' ? substr($dep_airport, 1, 3) : $dep_airport;
        $arr_prefix = substr($arr_airport, 0, 1) === 'K' ? substr($arr_airport, 1, 3) : $arr_airport;

        // Query playbook routes matching by origin/dest airports or ARTCCs
        $pb_sql = "
            SELECT p.play_id, p.play_name, p.display_name, p.category, p.source,
                   r.route_string, r.origin, r.dest,
                   r.origin_airports, r.origin_artccs, r.dest_airports, r.dest_artccs
            FROM playbook_plays p
            JOIN playbook_routes r ON r.play_id = p.play_id
            WHERE p.status = 'active'
              AND (
                FIND_IN_SET(:dep1, r.origin_airports)
                OR FIND_IN_SET(:dep2, r.origin_artccs)
                OR FIND_IN_SET(:arr1, r.dest_airports)
                OR FIND_IN_SET(:arr2, r.dest_artccs)
              )
            ORDER BY p.play_name, r.sort_order
            LIMIT 50
        ";
        try {
            $pb_stmt = $conn_pdo->prepare($pb_sql);
            $pb_stmt->execute([
                ':dep1' => $dep_airport,
                ':dep2' => $dep_prefix,
                ':arr1' => $arr_airport,
                ':arr2' => $arr_prefix
            ]);
            $pb_rows = $pb_stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($pb_rows as $pb) {
                $score = 10; // Base score for playbook route

                // Origin match scoring
                if (!empty($pb['origin_airports'])) {
                    $airports = array_map('trim', explode(',', $pb['origin_airports']));
                    if (in_array($dep_airport, $airports)) $score += 50;
                }
                if (!empty($pb['origin_artccs'])) {
                    $artccs = array_map('trim', explode(',', $pb['origin_artccs']));
                    if (in_array($dep_prefix, $artccs)) $score += 30;
                }

                // Destination match scoring
                if (!empty($pb['dest_airports'])) {
                    $airports = array_map('trim', explode(',', $pb['dest_airports']));
                    if (in_array($arr_airport, $airports)) $score += 50;
                }
                if (!empty($pb['dest_artccs'])) {
                    $artccs = array_map('trim', explode(',', $pb['dest_artccs']));
                    if (in_array($arr_prefix, $artccs)) $score += 30;
                }

                if ($score > 10) {
                    $play_label = $pb['display_name'] ?: $pb['play_name'];
                    $seg = (!$segment_filter || $segment_filter === 'NA') ? 'na' : 'eu';
                    $suggestions[$seg][] = [
                        'source' => 'playbook',
                        'name' => $play_label,
                        'play_id' => (int)$pb['play_id'],
                        'origin' => $pb['origin'],
                        'dest' => $pb['dest'],
                        'route' => $pb['route_string'],
                        'category' => $pb['category'],
                        'score' => $score
                    ];
                }
            }
        } catch (Exception $e) {
            // Playbook is best-effort; continue without it
        }
    }
}

// Sort each segment by score descending, limit to top 10
foreach ($suggestions as $seg => &$list) {
    usort($list, function($a, $b) { return $b['score'] - $a['score']; });
    $list = array_slice($list, 0, 10);
}
unset($list);

respond_json(200, [
    'status' => 'ok',
    'data' => [
        'na_suggestions' => $suggestions['na'],
        'oceanic_suggestions' => $suggestions['oceanic'],
        'eu_suggestions' => $suggestions['eu']
    ]
]);
