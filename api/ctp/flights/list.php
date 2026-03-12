<?php
/**
 * CTP Flights - List API
 *
 * GET /api/ctp/flights/list.php?session_id=N
 *
 * Main flight list with server-side filtering and pagination.
 * Designed for 5K flights with 100 concurrent users.
 *
 * Query parameters:
 *   session_id       - Required
 *   search           - Search string with qualifiers: orig:, dest:, thru:, entry:, exit:, type:, status:, route:
 *   edct_status      - Comma-separated: NONE, ASSIGNED, DELIVERED, COMPLIANT, NON_COMPLIANT
 *   route_status     - Comma-separated: FILED, MODIFIED, VALIDATED, REJECTED
 *   seg_status       - Per-segment filter: na:MODIFIED,oceanic:FILED
 *   dep_airport      - Comma-separated departure airports
 *   arr_airport      - Comma-separated arrival airports
 *   oceanic_fir      - Filter by oceanic entry FIR
 *   perspective      - Filter to flights relevant to a perspective (NA, OCEANIC, EU)
 *   is_excluded      - 0 or 1
 *   sort             - Sort field (default: oceanic_entry_utc)
 *   sort_dir         - asc or desc (default: asc)
 *   limit            - Max 500 (default: 100)
 *   offset           - Pagination offset
 */

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

define('CTP_API_INCLUDED', true);
require_once(__DIR__ . '/../common.php');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    respond_json(405, ['status' => 'error', 'message' => 'Method not allowed. Use GET.']);
}

$conn = ctp_get_conn_tmi();

// ============================================================================
// Parse Parameters
// ============================================================================

$session_id = isset($_GET['session_id']) ? (int)$_GET['session_id'] : 0;
if ($session_id <= 0) {
    respond_json(400, ['status' => 'error', 'message' => 'session_id is required.']);
}

$limit = isset($_GET['limit']) ? max(1, min(500, (int)$_GET['limit'])) : 100;
$offset = isset($_GET['offset']) ? max(0, (int)$_GET['offset']) : 0;
$sort_dir = (isset($_GET['sort_dir']) && strtolower($_GET['sort_dir']) === 'desc') ? 'DESC' : 'ASC';

// Allowed sort columns (whitelist to prevent SQL injection)
$sort_map = [
    'callsign'          => 'f.callsign',
    'dep_airport'       => 'f.dep_airport',
    'arr_airport'       => 'f.arr_airport',
    'aircraft_type'     => 'f.aircraft_type',
    'oceanic_entry_utc' => 'f.oceanic_entry_utc',
    'oceanic_exit_utc'  => 'f.oceanic_exit_utc',
    'edct_utc'          => 'f.edct_utc',
    'route_status'      => 'f.route_status',
    'edct_status'       => 'f.edct_status',
    'slot_delay_min'    => 'f.slot_delay_min',
    'created_at'        => 'f.created_at',
];
$sort_field = isset($_GET['sort']) && isset($sort_map[$_GET['sort']])
    ? $sort_map[$_GET['sort']]
    : 'f.oceanic_entry_utc';

// ============================================================================
// Build WHERE Clauses
// ============================================================================

$where = ["f.session_id = ?"];
$params = [$session_id];

// -- Search qualifier parsing --
if (isset($_GET['search']) && trim($_GET['search']) !== '') {
    $search_clauses = ctp_parse_search(trim($_GET['search']));
    foreach ($search_clauses as $clause) {
        switch ($clause['type']) {
            case 'orig':
                $where[] = "f.dep_airport = ?";
                $params[] = strtoupper($clause['value']);
                break;
            case 'dest':
                $where[] = "f.arr_airport = ?";
                $params[] = strtoupper($clause['value']);
                break;
            case 'thru':
                $where[] = "(f.oceanic_entry_fir = ? OR f.oceanic_exit_fir = ?)";
                $params[] = strtoupper($clause['value']);
                $params[] = strtoupper($clause['value']);
                break;
            case 'entry':
                $where[] = "f.oceanic_entry_fix = ?";
                $params[] = strtoupper($clause['value']);
                break;
            case 'exit':
                $where[] = "f.oceanic_exit_fix = ?";
                $params[] = strtoupper($clause['value']);
                break;
            case 'type':
                $where[] = "f.aircraft_type LIKE ?";
                $params[] = strtoupper($clause['value']) . '%';
                break;
            case 'status':
                $where[] = "f.edct_status = ?";
                $params[] = strtoupper($clause['value']);
                break;
            case 'route':
                $where[] = "(f.filed_route LIKE ? OR f.modified_route LIKE ?)";
                $val = '%' . strtoupper($clause['value']) . '%';
                $params[] = $val;
                $params[] = $val;
                break;
            case 'callsign':
                $where[] = "f.callsign LIKE ?";
                $params[] = strtoupper($clause['value']) . '%';
                break;
        }
    }
}

// -- Direct filter parameters --
if (isset($_GET['edct_status']) && $_GET['edct_status'] !== '') {
    $statuses = split_codes($_GET['edct_status']);
    if (count($statuses) > 0) {
        $ph = implode(',', array_fill(0, count($statuses), '?'));
        $where[] = "f.edct_status IN ({$ph})";
        $params = array_merge($params, $statuses);
    }
}

if (isset($_GET['route_status']) && $_GET['route_status'] !== '') {
    $statuses = split_codes($_GET['route_status']);
    if (count($statuses) > 0) {
        $ph = implode(',', array_fill(0, count($statuses), '?'));
        $where[] = "f.route_status IN ({$ph})";
        $params = array_merge($params, $statuses);
    }
}

if (isset($_GET['dep_airport']) && $_GET['dep_airport'] !== '') {
    $airports = split_codes($_GET['dep_airport']);
    if (count($airports) > 0) {
        $ph = implode(',', array_fill(0, count($airports), '?'));
        $where[] = "f.dep_airport IN ({$ph})";
        $params = array_merge($params, $airports);
    }
}

if (isset($_GET['arr_airport']) && $_GET['arr_airport'] !== '') {
    $airports = split_codes($_GET['arr_airport']);
    if (count($airports) > 0) {
        $ph = implode(',', array_fill(0, count($airports), '?'));
        $where[] = "f.arr_airport IN ({$ph})";
        $params = array_merge($params, $airports);
    }
}

if (isset($_GET['oceanic_fir']) && $_GET['oceanic_fir'] !== '') {
    $fir = strtoupper(trim($_GET['oceanic_fir']));
    $where[] = "(f.oceanic_entry_fir = ? OR f.oceanic_exit_fir = ?)";
    $params[] = $fir;
    $params[] = $fir;
}

if (isset($_GET['is_excluded'])) {
    $where[] = "f.is_excluded = ?";
    $params[] = (int)$_GET['is_excluded'];
}

// Per-segment status filter: seg_status=na:MODIFIED,oceanic:FILED
if (isset($_GET['seg_status']) && $_GET['seg_status'] !== '') {
    $seg_parts = explode(',', $_GET['seg_status']);
    foreach ($seg_parts as $sp) {
        $sp = trim($sp);
        if (strpos($sp, ':') !== false) {
            list($seg, $st) = explode(':', $sp, 2);
            $seg = strtolower(trim($seg));
            $st = strtoupper(trim($st));
            $col = null;
            if ($seg === 'na') $col = 'f.seg_na_status';
            elseif ($seg === 'oceanic') $col = 'f.seg_oceanic_status';
            elseif ($seg === 'eu') $col = 'f.seg_eu_status';
            if ($col && in_array($st, ['FILED', 'MODIFIED', 'VALIDATED'])) {
                $where[] = "{$col} = ?";
                $params[] = $st;
            }
        }
    }
}

$where_sql = implode(" AND ", $where);

// ============================================================================
// Get Summary + Total Count
// ============================================================================

$summary_sql = "
    SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN edct_status != 'NONE' THEN 1 ELSE 0 END) AS slotted,
        SUM(CASE WHEN route_status != 'FILED' THEN 1 ELSE 0 END) AS modified,
        SUM(CASE WHEN is_excluded = 1 THEN 1 ELSE 0 END) AS excluded,
        SUM(CASE WHEN is_event_flight = 1 THEN 1 ELSE 0 END) AS event_flights,
        AVG(CAST(slot_delay_min AS FLOAT)) AS avg_delay_min,
        MAX(slot_delay_min) AS max_delay_min
    FROM dbo.ctp_flight_control f
    WHERE {$where_sql}
";

$summary_result = ctp_fetch_one($conn, $summary_sql, $params);
$summary = $summary_result['success'] ? $summary_result['data'] : null;
if ($summary && isset($summary['avg_delay_min'])) {
    $summary['avg_delay_min'] = round((float)$summary['avg_delay_min'], 1);
}

// ============================================================================
// Fetch Flights (paginated)
// ============================================================================

$params_paged = array_merge($params, [$offset, $limit]);

$flights_sql = "
    SELECT
        f.ctp_control_id,
        f.session_id,
        f.flight_uid,
        f.callsign,
        f.tmi_control_id,
        f.dep_airport,
        f.arr_airport,
        f.dep_artcc,
        f.arr_artcc,
        f.aircraft_type,
        f.filed_altitude,
        f.oceanic_entry_fir,
        f.oceanic_exit_fir,
        f.oceanic_entry_fix,
        f.oceanic_exit_fix,
        f.oceanic_entry_utc,
        f.oceanic_exit_utc,
        f.route_status,
        f.seg_na_status,
        f.seg_oceanic_status,
        f.seg_eu_status,
        f.edct_status,
        f.edct_utc,
        f.original_etd_utc,
        f.slot_delay_min,
        f.compliance_status,
        f.compliance_delta_min,
        f.actual_dep_utc,
        f.is_event_flight,
        f.is_excluded,
        f.is_priority,
        f.notes,
        f.created_at,
        f.updated_at
    FROM dbo.ctp_flight_control f
    WHERE {$where_sql}
    ORDER BY {$sort_field} {$sort_dir}, f.callsign ASC
    OFFSET ? ROWS FETCH NEXT ? ROWS ONLY
";

$flights_result = ctp_fetch_all($conn, $flights_sql, $params_paged);

respond_json(200, [
    'status' => 'ok',
    'data' => [
        'flights' => $flights_result['success'] ? $flights_result['data'] : [],
        'total' => $summary ? (int)$summary['total'] : 0,
        'limit' => $limit,
        'offset' => $offset,
        'summary' => $summary
    ]
]);

// ============================================================================
// Search Parser
// ============================================================================

/**
 * Parse search string with CTP qualifiers.
 * Supports: orig:KJFK dest:EGLL thru:CZQX entry:DOTTY exit:GIPER type:B738 status:assigned route:NATB
 * Free text matches callsign.
 *
 * @param string $raw Raw search string
 * @return array Array of {type, value} clauses
 */
function ctp_parse_search($raw) {
    $clauses = [];
    $qualifiers = ['orig', 'dest', 'thru', 'entry', 'exit', 'type', 'status', 'route'];

    // Build regex for qualifiers
    $qual_pattern = implode('|', $qualifiers);
    $tokens = preg_split('/\s+/', $raw);

    foreach ($tokens as $token) {
        $token = trim($token);
        if ($token === '') continue;

        // Check for qualifier:value
        if (preg_match('/^(' . $qual_pattern . '):(.+)$/i', $token, $m)) {
            $clauses[] = [
                'type' => strtolower($m[1]),
                'value' => $m[2]
            ];
        } else {
            // Free text = callsign search
            $clauses[] = [
                'type' => 'callsign',
                'value' => $token
            ];
        }
    }

    return $clauses;
}
