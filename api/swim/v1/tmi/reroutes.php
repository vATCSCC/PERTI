<?php
/**
 * VATSWIM API v1 - TMI Reroutes Endpoint
 *
 * Returns active reroute definitions and their flight assignments.
 * Data from VATSIM_TMI database (tmi_reroutes, tmi_reroute_flights tables).
 *
 * GET /api/swim/v1/tmi/reroutes                      - List active reroutes
 * GET /api/swim/v1/tmi/reroutes?active_only=false    - List all reroutes
 * GET /api/swim/v1/tmi/reroutes?origin=ZBW           - Filter by origin center
 * GET /api/swim/v1/tmi/reroutes?dest=KJFK            - Filter by destination
 * GET /api/swim/v1/tmi/reroutes?id=123               - Get single reroute
 * GET /api/swim/v1/tmi/reroutes?id=123&flights=1     - Include flight assignments
 * GET /api/swim/v1/tmi/reroutes?id=123&include_advisory=1 - Include Discord advisory text
 *
 * @version 3.0.0
 */

require_once __DIR__ . '/../auth.php';

// TMI database connection
global $conn_tmi;

if (!$conn_tmi) {
    SwimResponse::error('TMI database connection not available', 503, 'SERVICE_UNAVAILABLE');
}

$auth = swim_init_auth(false, false);  // Public read access

// Get filter parameters
$id = swim_get_int_param('id');
$origin = swim_get_param('origin');
$dest = swim_get_param('dest');
$status = swim_get_param('status');
$active_only = swim_get_param('active_only', 'true') === 'true';
$include_flights = swim_get_param('flights', '0') === '1' || swim_get_param('flights') === 'true';
$include_compliance = swim_get_param('compliance', '0') === '1' || swim_get_param('compliance') === 'true';
$include_advisory = swim_get_param('include_advisory', '0') === '1' || swim_get_param('include_advisory') === 'true';

$page = swim_get_int_param('page', 1, 1, 1000);
$per_page = swim_get_int_param('per_page', SWIM_DEFAULT_PAGE_SIZE, 1, SWIM_MAX_PAGE_SIZE);
$offset = ($page - 1) * $per_page;

// Single reroute by ID
if ($id) {
    $sql = "SELECT * FROM dbo.tmi_reroutes WHERE reroute_id = ?";

    $stmt = sqlsrv_query($conn_tmi, $sql, [$id]);
    if ($stmt === false) {
        $errors = sqlsrv_errors();
        SwimResponse::error('Database error: ' . ($errors[0]['message'] ?? 'Unknown'), 500, 'DB_ERROR');
    }

    $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    sqlsrv_free_stmt($stmt);

    if (!$row) {
        SwimResponse::error('Reroute not found', 404, 'NOT_FOUND');
    }

    $reroute = formatReroute($row, $conn_tmi, $include_advisory);

    // Include assigned flights if requested
    if ($include_flights) {
        $reroute['flights'] = getRerouteFlights($conn_tmi, $id);
    }

    // Include compliance history if requested
    if ($include_compliance) {
        $reroute['compliance_history'] = getRerouteCompliance($conn_tmi, $id);
    }

    SwimResponse::success(['reroute' => $reroute], ['source' => 'vatsim_tmi']);
    exit;
}

// Build query for list
$where_clauses = [];
$params = [];

if ($active_only) {
    $where_clauses[] = "status IN (2, 3)";  // ACTIVE or MONITORING
    $where_clauses[] = "(end_utc IS NULL OR end_utc > GETUTCDATE())";
}

if ($origin) {
    $where_clauses[] = "(origin_centers LIKE '%' + ? + '%' OR origin_airports LIKE '%' + ? + '%')";
    $params[] = strtoupper($origin);
    $params[] = strtoupper($origin);
}

if ($dest) {
    $where_clauses[] = "(dest_airports LIKE '%' + ? + '%' OR dest_centers LIKE '%' + ? + '%')";
    $params[] = strtoupper($dest);
    $params[] = strtoupper($dest);
}

if ($status) {
    $status_list = array_map('intval', explode(',', $status));
    $placeholders = implode(',', $status_list);
    $where_clauses[] = "status IN ($placeholders)";
}

$where_sql = !empty($where_clauses) ? 'WHERE ' . implode(' AND ', $where_clauses) : '';

// Count total
$count_sql = "SELECT COUNT(*) as total FROM dbo.tmi_reroutes $where_sql";
$count_stmt = sqlsrv_query($conn_tmi, $count_sql, $params);
if ($count_stmt === false) {
    $errors = sqlsrv_errors();
    SwimResponse::error('Database error: ' . ($errors[0]['message'] ?? 'Unknown'), 500, 'DB_ERROR');
}
$total = (int)sqlsrv_fetch_array($count_stmt, SQLSRV_FETCH_ASSOC)['total'];
sqlsrv_free_stmt($count_stmt);

// Main query
$sql = "
    SELECT *
    FROM dbo.tmi_reroutes
    $where_sql
    ORDER BY
        CASE WHEN status = 2 THEN 0 WHEN status = 3 THEN 1 WHEN status = 1 THEN 2 ELSE 3 END,
        created_at DESC
    OFFSET ? ROWS FETCH NEXT ? ROWS ONLY
";

$params[] = $offset;
$params[] = $per_page;

$stmt = sqlsrv_query($conn_tmi, $sql, $params);
if ($stmt === false) {
    $errors = sqlsrv_errors();
    SwimResponse::error('Database error: ' . ($errors[0]['message'] ?? 'Unknown'), 500, 'DB_ERROR');
}

$reroutes = [];
$stats = ['by_status' => []];

while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
    $reroute = formatReroute($row, $conn_tmi, $include_advisory);
    $reroutes[] = $reroute;

    // Update stats
    $statusName = getStatusName($row['status']);
    $stats['by_status'][$statusName] = ($stats['by_status'][$statusName] ?? 0) + 1;
}
sqlsrv_free_stmt($stmt);

$response = [
    'success' => true,
    'data' => $reroutes,
    'statistics' => $stats,
    'pagination' => [
        'total' => $total,
        'page' => $page,
        'per_page' => $per_page,
        'total_pages' => $total > 0 ? ceil($total / $per_page) : 0,
        'has_more' => $page < ceil($total / $per_page)
    ],
    'filters' => [
        'origin' => $origin,
        'dest' => $dest,
        'active_only' => $active_only
    ],
    'timestamp' => gmdate('c'),
    'meta' => [
        'source' => 'vatsim_tmi',
        'table' => 'tmi_reroutes'
    ]
];

SwimResponse::json($response);


/**
 * Format a reroute row for API output
 */
function formatReroute($row, $conn = null, $includeAdvisory = false) {
    // Parse JSON fields safely
    $protectedFixes = parseJsonField($row['protected_fixes'] ?? null);
    $avoidFixes = parseJsonField($row['avoid_fixes'] ?? null);
    $originAirports = parseJsonField($row['origin_airports'] ?? null);
    $originCenters = parseJsonField($row['origin_centers'] ?? null);
    $destAirports = parseJsonField($row['dest_airports'] ?? null);
    $destCenters = parseJsonField($row['dest_centers'] ?? null);

    // Get individual routes from tmi_reroute_routes table (or expand from scope)
    $routes = [];
    $routesGrouped = [];
    $rerouteId = $row['reroute_id'] ?? null;

    if ($conn && $rerouteId) {
        $routes = getRerouteRoutes($conn, $rerouteId);
    }

    // If no routes in table, expand from origin/dest scope
    if (empty($routes)) {
        $routes = expandRoutesFromScope($row);
    }

    // Group routes by route string for concise display
    $routesGrouped = groupRoutesByString($routes);

    // Generate plotter-compatible string (strip > and < markers)
    $plotterString = stripPlotterMarkers($row['protected_segment'] ?? '');

    $result = [
        'reroute_id' => $row['reroute_id'],
        'reroute_guid' => $row['reroute_guid'] ?? null,
        'name' => $row['name'],
        'adv_number' => $row['adv_number'] ?? null,
        'status' => (int)$row['status'],
        'status_name' => getStatusName($row['status']),

        'times' => [
            'start' => formatDT($row['start_utc'] ?? null),
            'end' => formatDT($row['end_utc'] ?? null),
            'time_basis' => $row['time_basis'] ?? 'ETD',
            'activated' => formatDT($row['activated_utc'] ?? null)
        ],

        'route' => [
            'protected_segment' => $row['protected_segment'] ?? null,
            'protected_fixes' => $protectedFixes,
            'avoid_fixes' => $avoidFixes,
            'route_type' => $row['route_type'] ?? 'FULL',
            'plotter_string' => $plotterString,
            'routes' => $routes,
            'routes_grouped' => $routesGrouped
        ],

        'scope' => [
            'origin_airports' => $originAirports,
            'origin_centers' => $originCenters,
            'origin_tracons' => parseJsonField($row['origin_tracons'] ?? null),
            'dest_airports' => $destAirports,
            'dest_centers' => $destCenters,
            'dest_tracons' => parseJsonField($row['dest_tracons'] ?? null),
            'departure_fix' => $row['departure_fix'] ?? null,
            'arrival_fix' => $row['arrival_fix'] ?? null,
            'thru_centers' => parseJsonField($row['thru_centers'] ?? null),
            'thru_fixes' => parseJsonField($row['thru_fixes'] ?? null)
        ],

        'filters' => [
            'aircraft_category' => $row['include_ac_cat'] ?? 'ALL',
            'aircraft_types' => parseJsonField($row['include_ac_types'] ?? null),
            'carriers' => parseJsonField($row['include_carriers'] ?? null),
            'weight_class' => $row['weight_class'] ?? 'ALL',
            'altitude_min' => $row['altitude_min'] ?? null,
            'altitude_max' => $row['altitude_max'] ?? null,
            'rvsm_filter' => $row['rvsm_filter'] ?? 'ALL',
            'airborne_filter' => $row['airborne_filter'] ?? 'NOT_AIRBORNE'
        ],

        'exemptions' => [
            'airports' => parseJsonField($row['exempt_airports'] ?? null),
            'carriers' => parseJsonField($row['exempt_carriers'] ?? null),
            'flights' => parseJsonField($row['exempt_flights'] ?? null),
            'active_only' => (bool)($row['exempt_active_only'] ?? false)
        ],

        'reason' => [
            'impacting_condition' => $row['impacting_condition'] ?? null,
            'advisory_text' => $row['advisory_text'] ?? null,
            'comments' => $row['comments'] ?? null
        ],

        'visualization' => [
            'color' => $row['color'] ?? '#3498db',
            'line_weight' => (int)($row['line_weight'] ?? 3),
            'line_style' => $row['line_style'] ?? 'solid',
            'geojson' => parseJsonField($row['route_geojson'] ?? null)
        ],

        'metrics' => [
            'total_assigned' => (int)($row['total_assigned'] ?? 0),
            'compliant_count' => (int)($row['compliant_count'] ?? 0),
            'partial_count' => (int)($row['partial_count'] ?? 0),
            'non_compliant_count' => (int)($row['non_compliant_count'] ?? 0),
            'exempt_count' => (int)($row['exempt_count'] ?? 0),
            'compliance_rate' => $row['compliance_rate'] !== null ? (float)$row['compliance_rate'] : null
        ],

        'source' => [
            'type' => $row['source_type'] ?? null,
            'id' => $row['source_id'] ?? null,
            'discord_message_id' => $row['discord_message_id'] ?? null,
            'discord_channel_id' => $row['discord_channel_id'] ?? null
        ],

        '_created_at' => formatDT($row['created_at'] ?? null),
        '_updated_at' => formatDT($row['updated_at'] ?? null),
        '_created_by' => $row['created_by'] ?? null
    ];

    // Include advisory text if requested
    if ($includeAdvisory && $conn) {
        $result['advisory_text'] = buildAdvisoryText($row, $routesGrouped, $conn);
    }

    return $result;
}

/**
 * Get flights assigned to a reroute
 */
function getRerouteFlights($conn, $rerouteId) {
    $sql = "
        SELECT
            flight_id, flight_key, callsign,
            dep_icao, dest_icao, ac_type, filed_altitude,
            route_at_assign, assigned_route, current_route, final_route,
            last_lat, last_lon, last_altitude, last_position_utc,
            compliance_status, compliance_pct, compliance_notes,
            protected_fixes_crossed, avoid_fixes_crossed,
            assigned_utc, departed_utc, arrived_utc,
            route_distance_original_nm, route_distance_assigned_nm, route_delta_nm,
            ete_original_min, ete_assigned_min, ete_delta_min,
            manual_status, override_by, override_reason
        FROM dbo.tmi_reroute_flights
        WHERE reroute_id = ?
        ORDER BY assigned_utc DESC
    ";

    $stmt = sqlsrv_query($conn, $sql, [$rerouteId]);
    if ($stmt === false) return [];

    $flights = [];
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $flights[] = [
            'flight_id' => $row['flight_id'],
            'flight_key' => $row['flight_key'],
            'callsign' => $row['callsign'],
            'departure' => $row['dep_icao'],
            'destination' => $row['dest_icao'],
            'aircraft_type' => $row['ac_type'],
            'altitude' => $row['filed_altitude'],

            'routes' => [
                'at_assignment' => $row['route_at_assign'],
                'assigned' => $row['assigned_route'],
                'current' => $row['current_route'],
                'final' => $row['final_route']
            ],

            'position' => [
                'lat' => $row['last_lat'] !== null ? (float)$row['last_lat'] : null,
                'lon' => $row['last_lon'] !== null ? (float)$row['last_lon'] : null,
                'altitude' => $row['last_altitude'],
                'timestamp' => formatDT($row['last_position_utc'])
            ],

            'compliance' => [
                'status' => $row['compliance_status'],
                'percentage' => $row['compliance_pct'] !== null ? (float)$row['compliance_pct'] : null,
                'notes' => $row['compliance_notes'],
                'protected_crossed' => parseJsonField($row['protected_fixes_crossed']),
                'avoid_crossed' => parseJsonField($row['avoid_fixes_crossed'])
            ],

            'timing' => [
                'assigned' => formatDT($row['assigned_utc']),
                'departed' => formatDT($row['departed_utc']),
                'arrived' => formatDT($row['arrived_utc'])
            ],

            'impact' => [
                'distance_original_nm' => $row['route_distance_original_nm'],
                'distance_assigned_nm' => $row['route_distance_assigned_nm'],
                'distance_delta_nm' => $row['route_delta_nm'],
                'ete_original_min' => $row['ete_original_min'],
                'ete_assigned_min' => $row['ete_assigned_min'],
                'ete_delta_min' => $row['ete_delta_min']
            ],

            'override' => [
                'is_manual' => (bool)$row['manual_status'],
                'by' => $row['override_by'],
                'reason' => $row['override_reason']
            ]
        ];
    }
    sqlsrv_free_stmt($stmt);

    return $flights;
}

/**
 * Get compliance history for a reroute (via flight logs)
 */
function getRerouteCompliance($conn, $rerouteId) {
    $sql = "
        SELECT TOP 50
            l.log_id, l.snapshot_utc,
            l.compliance_status, l.compliance_pct,
            l.lat, l.lon, l.altitude,
            l.route_string, l.fixes_crossed,
            f.callsign, f.flight_key
        FROM dbo.tmi_reroute_compliance_log l
        INNER JOIN dbo.tmi_reroute_flights f ON l.reroute_flight_id = f.flight_id
        WHERE f.reroute_id = ?
        ORDER BY l.snapshot_utc DESC
    ";

    $stmt = sqlsrv_query($conn, $sql, [$rerouteId]);
    if ($stmt === false) return [];

    $history = [];
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $history[] = [
            'log_id' => $row['log_id'],
            'timestamp' => formatDT($row['snapshot_utc']),
            'callsign' => $row['callsign'],
            'flight_key' => $row['flight_key'],
            'status' => $row['compliance_status'],
            'percentage' => $row['compliance_pct'] !== null ? (float)$row['compliance_pct'] : null,
            'position' => [
                'lat' => $row['lat'] !== null ? (float)$row['lat'] : null,
                'lon' => $row['lon'] !== null ? (float)$row['lon'] : null,
                'altitude' => $row['altitude']
            ],
            'route' => $row['route_string'],
            'fixes_crossed' => parseJsonField($row['fixes_crossed'])
        ];
    }
    sqlsrv_free_stmt($stmt);

    return $history;
}

function getStatusName($status) {
    $names = [
        0 => 'DRAFT',
        1 => 'PROPOSED',
        2 => 'ACTIVE',
        3 => 'MONITORING',
        4 => 'EXPIRED',
        5 => 'CANCELLED'
    ];
    return $names[(int)$status] ?? 'UNKNOWN';
}

function parseJsonField($value) {
    if ($value === null || $value === '') return null;
    if (is_array($value)) return $value;
    $decoded = json_decode($value, true);
    return $decoded !== null ? $decoded : $value;
}

function formatDT($dt) {
    if ($dt === null) return null;
    return ($dt instanceof DateTime) ? $dt->format('c') : $dt;
}

/**
 * Get individual routes from tmi_reroute_routes table
 */
function getRerouteRoutes($conn, $rerouteId) {
    $sql = "
        SELECT origin, destination, route_string, sort_order
        FROM dbo.tmi_reroute_routes
        WHERE reroute_id = ?
        ORDER BY sort_order, route_id
    ";

    $stmt = sqlsrv_query($conn, $sql, [$rerouteId]);
    if ($stmt === false) return [];

    $routes = [];
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $routes[] = [
            'origin' => $row['origin'],
            'dest' => $row['destination'],
            'route' => $row['route_string']
        ];
    }
    sqlsrv_free_stmt($stmt);

    return $routes;
}

/**
 * Expand routes from scope (origin_airports × dest_airports)
 * Used as fallback when no routes in tmi_reroute_routes table
 */
function expandRoutesFromScope($row) {
    $origins = parseAirportList($row['origin_airports'] ?? '');
    $dests = parseAirportList($row['dest_airports'] ?? '');
    $routeString = $row['protected_segment'] ?? '';

    if (empty($origins) || empty($dests) || empty($routeString)) {
        return [];
    }

    $routes = [];
    foreach ($origins as $orig) {
        foreach ($dests as $dest) {
            $routes[] = [
                'origin' => $orig,
                'dest' => $dest,
                'route' => $routeString
            ];
        }
    }

    return $routes;
}

/**
 * Parse airport list from comma/space/slash separated string
 */
function parseAirportList($str) {
    if (empty($str)) return [];
    // Handle JSON arrays
    if (is_array($str)) return $str;
    if ($str[0] === '[') {
        $decoded = json_decode($str, true);
        if ($decoded !== null) return $decoded;
    }
    // Parse comma/space/slash separated string
    $airports = array_filter(array_map('trim', preg_split('/[\s,\/]+/', strtoupper($str))));
    return array_values($airports);
}

/**
 * Group routes by route string for concise display
 * Consolidates origins that share the same route
 */
function groupRoutesByString($routes) {
    if (empty($routes)) return [];

    $grouped = [];
    foreach ($routes as $r) {
        $key = $r['route'];
        if (!isset($grouped[$key])) {
            $grouped[$key] = [
                'origins' => [],
                'dests' => [],
                'route' => $key
            ];
        }
        // Add origin if not already present
        $originParts = preg_split('/\s+/', trim($r['origin']));
        foreach ($originParts as $o) {
            if (!in_array($o, $grouped[$key]['origins'])) {
                $grouped[$key]['origins'][] = $o;
            }
        }
        // Add dest if not already present
        $destParts = preg_split('/\s+/', trim($r['dest']));
        foreach ($destParts as $d) {
            if (!in_array($d, $grouped[$key]['dests'])) {
                $grouped[$key]['dests'][] = $d;
            }
        }
    }

    return array_values($grouped);
}

/**
 * Strip mandatory segment markers (> and <) for Route Plotter compatibility
 */
function stripPlotterMarkers($routeString) {
    if (empty($routeString)) return '';
    // Remove > prefix and < suffix from fixes
    return trim(preg_replace('/[><]/', '', $routeString));
}

/**
 * Get next advisory number for today (auto-sequence)
 */
function getNextAdvisoryNumber($conn, $peek = false) {
    if ($peek) {
        $sql = "DECLARE @num NVARCHAR(3); EXEC dbo.sp_TMI_PeekAdvisoryNumber @num OUTPUT; SELECT @num AS advisory_number;";
    } else {
        $sql = "DECLARE @num NVARCHAR(3); EXEC dbo.sp_TMI_GetNextAdvisoryNumber @num OUTPUT; SELECT @num AS advisory_number;";
    }

    $stmt = sqlsrv_query($conn, $sql);
    if ($stmt === false) return '001';

    $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    sqlsrv_free_stmt($stmt);

    return $row['advisory_number'] ?? '001';
}

/**
 * Build advisory text for Discord formatting
 */
function buildAdvisoryText($row, $routesGrouped, $conn) {
    // Get next advisory number (peek, don't consume)
    $advNumber = getNextAdvisoryNumber($conn, true);

    // Build include traffic string from origins and destinations
    $origins = parseAirportList($row['origin_airports'] ?? '');
    $dests = parseAirportList($row['dest_airports'] ?? '');
    $includeTraffic = '';
    if (!empty($origins) && !empty($dests)) {
        $originStr = 'K' . implode('/K', $origins);
        $destStr = 'K' . implode('/K', $dests);
        $includeTraffic = "{$originStr} DEPARTURES TO {$destStr}";
    }

    // Build facilities list from origin_centers
    $facilities = parseAirportList($row['origin_centers'] ?? '');

    // Convert routes_grouped to TMIDiscord format
    $discordRoutes = [];
    foreach ($routesGrouped as $group) {
        $discordRoutes[] = [
            'origin' => implode(' ', $group['origins']),
            'dest' => implode(' ', $group['dests']),
            'route' => $group['route']
        ];
    }

    // Build advisory params
    $advisoryParams = [
        'advisory_number' => $advNumber,
        'facility' => 'DCC',
        'issue_date' => gmdate('Y-m-d H:i:s'),
        'name' => $row['name'] ?? '',
        'route_name' => $row['name'] ?? '',
        'constrained_area' => implode('/', $facilities),
        'impacted_area' => implode('/', $facilities),
        'reason' => strtoupper($row['impacting_condition'] ?? 'WEATHER'),
        'include_traffic' => $includeTraffic,
        'facilities' => $facilities,
        'facilities_included' => $facilities,
        'flight_status' => 'ALL_FLIGHTS',
        'start_utc' => formatDT($row['start_utc'] ?? null),
        'end_utc' => formatDT($row['end_utc'] ?? null),
        'valid_from' => formatDT($row['start_utc'] ?? null),
        'valid_until' => formatDT($row['end_utc'] ?? null),
        'valid_type' => $row['time_basis'] ?? 'ETD',
        'prob_extension' => 'MEDIUM',
        'remarks' => $row['comments'] ?? '',
        'associated_restrictions' => '',
        'modifications' => '',
        'routes' => $discordRoutes
    ];

    // Try to use TMIDiscord class if available
    $discordText = null;
    $plainText = null;

    $tmiDiscordPath = __DIR__ . '/../../../../load/discord/TMIDiscord.php';
    if (file_exists($tmiDiscordPath)) {
        require_once $tmiDiscordPath;
        if (class_exists('TMIDiscord')) {
            $discord = new TMIDiscord();
            // Use reflection to call private method, or manually format
            $plainText = formatRerouteAdvisoryText($advisoryParams);
            $discordText = "```\n{$plainText}\n```";
        }
    }

    // Fallback to manual formatting if TMIDiscord not available
    if ($plainText === null) {
        $plainText = formatRerouteAdvisoryText($advisoryParams);
        $discordText = "```\n{$plainText}\n```";
    }

    return [
        'discord' => $discordText,
        'plain' => $plainText,
        'params' => $advisoryParams
    ];
}

/**
 * Format reroute advisory text (standalone implementation)
 */
function formatRerouteAdvisoryText($data) {
    $advNum = str_pad($data['advisory_number'] ?? '001', 3, '0', STR_PAD_LEFT);
    $facility = strtoupper($data['facility'] ?? 'DCC');
    $headerDate = gmdate('m/d/Y', strtotime($data['issue_date'] ?? 'now'));
    $routeName = strtoupper($data['name'] ?? $data['route_name'] ?? '');
    $constrainedArea = strtoupper($data['constrained_area'] ?? $data['impacted_area'] ?? '');
    $reason = strtoupper($data['reason'] ?? 'WEATHER');
    $includeTraffic = strtoupper($data['include_traffic'] ?? '');
    $facilities = $data['facilities'] ?? $data['facilities_included'] ?? [];
    $facilitiesStr = is_array($facilities) ? implode('/', array_map('strtoupper', $facilities)) : strtoupper($facilities);
    $flightStatus = strtoupper($data['flight_status'] ?? 'ALL_FLIGHTS');

    $startUtc = $data['start_utc'] ?? $data['valid_from'] ?? null;
    $endUtc = $data['end_utc'] ?? $data['valid_until'] ?? null;
    $startTime = $startUtc ? gmdate('dHi', strtotime($startUtc)) : gmdate('dHi');
    $endTime = $endUtc ? gmdate('dHi', strtotime($endUtc)) : gmdate('dHi');

    $validType = strtoupper($data['valid_type'] ?? 'ETD');
    $probExt = strtoupper($data['prob_extension'] ?? 'NONE');
    $tmiId = 'RR' . $facility . $advNum;

    $lines = [];
    $lines[] = "vATCSCC ADVZY {$advNum} {$facility} {$headerDate} ROUTE RQD";

    if ($routeName) $lines[] = "NAME: {$routeName}";
    if ($constrainedArea) $lines[] = "CONSTRAINED AREA: {$constrainedArea}";
    $lines[] = "REASON: {$reason}";
    if ($includeTraffic) $lines[] = "INCLUDE TRAFFIC: {$includeTraffic}";
    if ($facilitiesStr) $lines[] = "FACILITIES INCLUDED: {$facilitiesStr}";
    $lines[] = "FLIGHT STATUS: {$flightStatus}";
    $lines[] = "VALID: {$validType} {$startTime} TO {$endTime}";
    $lines[] = "PROBABILITY OF EXTENSION: {$probExt}";
    $lines[] = "REMARKS: " . ($data['remarks'] ?? '');
    $lines[] = "ASSOCIATED RESTRICTIONS: " . ($data['associated_restrictions'] ?? '');
    $lines[] = "MODIFICATIONS: " . ($data['modifications'] ?? '');
    $lines[] = "ROUTES:";
    $lines[] = "";

    // Format route table
    $routes = $data['routes'] ?? [];
    $lines[] = formatRouteTableText($routes);

    $lines[] = "";
    $lines[] = "TMI ID: {$tmiId}";
    $lines[] = "{$startTime} - {$endTime}";
    $lines[] = gmdate('y/m/d H:i');

    return implode("\n", $lines);
}

/**
 * Format route table for advisory text
 */
function formatRouteTableText($routes) {
    if (empty($routes)) {
        return "ORIG       DEST       ROUTE\n----       ----       -----\n(No routes specified)";
    }

    $maxOrigLen = 4;
    $maxDestLen = 4;
    foreach ($routes as $route) {
        $maxOrigLen = max($maxOrigLen, strlen(strtoupper($route['origin'] ?? '---')));
        $maxDestLen = max($maxDestLen, strlen(strtoupper($route['dest'] ?? $route['destination'] ?? '---')));
    }

    $origColWidth = $maxOrigLen + 3;
    $destColWidth = $maxDestLen + 3;

    $output = str_pad('ORIG', $origColWidth) . str_pad('DEST', $destColWidth) . "ROUTE\n";
    $output .= str_pad('----', $origColWidth) . str_pad('----', $destColWidth) . "-----\n";

    foreach ($routes as $route) {
        $orig = strtoupper($route['origin'] ?? '---');
        $dest = strtoupper($route['dest'] ?? $route['destination'] ?? '---');
        $routeStr = strtoupper($route['route'] ?? '');
        $output .= str_pad($orig, $origColWidth) . str_pad($dest, $destColWidth) . $routeStr . "\n";
    }

    return rtrim($output);
}
