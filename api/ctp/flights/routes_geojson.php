<?php
/**
 * CTP Flight Routes GeoJSON API
 *
 * GET /api/ctp/flights/routes_geojson.php?session_id=N
 *
 * Returns a bulk GeoJSON FeatureCollection of all flight routes for a session.
 * Each Feature has properties for callsign, route_status, edct_status,
 * segment statuses, and ctp_control_id for client-side styling and interaction.
 *
 * Optional parameters:
 *   perspective=NA|OCEANIC|EU  (filter to flights relevant to perspective)
 *
 * Response: GeoJSON FeatureCollection
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

$conn_tmi = ctp_get_conn_tmi();

$session_id = isset($_GET['session_id']) ? (int)$_GET['session_id'] : 0;
if ($session_id <= 0) {
    respond_json(400, ['status' => 'error', 'message' => 'session_id is required.']);
}

// Get flights with route_geojson or enough data to build entry/exit points
$sql = "
    SELECT
        ctp_control_id,
        callsign,
        dep_airport,
        arr_airport,
        aircraft_type,
        oceanic_entry_fix,
        oceanic_exit_fix,
        oceanic_entry_fir,
        oceanic_exit_fir,
        oceanic_entry_utc,
        oceanic_exit_utc,
        route_status,
        edct_status,
        edct_utc,
        seg_na_status,
        seg_oceanic_status,
        seg_eu_status,
        is_excluded,
        is_priority,
        is_event_flight,
        route_geojson
    FROM dbo.ctp_flight_control
    WHERE session_id = ?
      AND is_excluded = 0
    ORDER BY oceanic_entry_utc ASC
";

$stmt = sqlsrv_query($conn_tmi, $sql, [$session_id]);
if ($stmt === false) {
    respond_json(500, ['status' => 'error', 'message' => 'Failed to query flight routes.']);
}

$features = [];
$entry_points = [];
$exit_points = [];

while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
    $ctpId = (int)$row['ctp_control_id'];

    // Convert DateTimeInterface objects
    foreach (['oceanic_entry_utc', 'oceanic_exit_utc', 'edct_utc'] as $col) {
        if ($row[$col] instanceof DateTimeInterface) {
            $row[$col] = $row[$col]->format('Y-m-d\TH:i:s\Z');
        }
    }

    $props = [
        'ctp_control_id' => $ctpId,
        'callsign' => $row['callsign'],
        'dep_airport' => $row['dep_airport'],
        'arr_airport' => $row['arr_airport'],
        'aircraft_type' => $row['aircraft_type'],
        'oceanic_entry_fix' => $row['oceanic_entry_fix'],
        'oceanic_exit_fix' => $row['oceanic_exit_fix'],
        'oceanic_entry_fir' => $row['oceanic_entry_fir'],
        'oceanic_exit_fir' => $row['oceanic_exit_fir'],
        'oceanic_entry_utc' => $row['oceanic_entry_utc'],
        'oceanic_exit_utc' => $row['oceanic_exit_utc'],
        'route_status' => $row['route_status'],
        'edct_status' => $row['edct_status'],
        'edct_utc' => $row['edct_utc'],
        'seg_na_status' => $row['seg_na_status'],
        'seg_oceanic_status' => $row['seg_oceanic_status'],
        'seg_eu_status' => $row['seg_eu_status'],
        'is_priority' => (bool)$row['is_priority'],
        'is_event_flight' => (bool)$row['is_event_flight']
    ];

    // If pre-computed route GeoJSON exists, use it
    if (!empty($row['route_geojson'])) {
        $routeGeo = json_decode($row['route_geojson'], true);
        if ($routeGeo && isset($routeGeo['type'])) {
            $features[] = [
                'type' => 'Feature',
                'id' => $ctpId,
                'properties' => $props,
                'geometry' => $routeGeo
            ];
        }
    }

    // Collect entry/exit point features for markers
    // These are always added regardless of route_geojson presence
    if (!empty($row['oceanic_entry_fix'])) {
        $entry_points[] = [
            'fix' => $row['oceanic_entry_fix'],
            'fir' => $row['oceanic_entry_fir'],
            'ctp_control_id' => $ctpId,
            'callsign' => $row['callsign'],
            'utc' => $row['oceanic_entry_utc']
        ];
    }
    if (!empty($row['oceanic_exit_fix'])) {
        $exit_points[] = [
            'fix' => $row['oceanic_exit_fix'],
            'fir' => $row['oceanic_exit_fir'],
            'ctp_control_id' => $ctpId,
            'callsign' => $row['callsign'],
            'utc' => $row['oceanic_exit_utc']
        ];
    }
}
sqlsrv_free_stmt($stmt);

// Build entry/exit point aggregates (unique fixes with counts)
$entry_agg = [];
foreach ($entry_points as $ep) {
    $key = $ep['fix'];
    if (!isset($entry_agg[$key])) {
        $entry_agg[$key] = ['fix' => $ep['fix'], 'fir' => $ep['fir'], 'count' => 0, 'type' => 'entry'];
    }
    $entry_agg[$key]['count']++;
}

$exit_agg = [];
foreach ($exit_points as $xp) {
    $key = $xp['fix'];
    if (!isset($exit_agg[$key])) {
        $exit_agg[$key] = ['fix' => $xp['fix'], 'fir' => $xp['fir'], 'count' => 0, 'type' => 'exit'];
    }
    $exit_agg[$key]['count']++;
}

// Look up fix coordinates from VATSIM_ADL nav_fixes
$fix_names = array_unique(array_merge(array_keys($entry_agg), array_keys($exit_agg)));
$fix_coords = [];

if (!empty($fix_names)) {
    $conn_adl = ctp_get_conn_adl();
    foreach (array_chunk($fix_names, 100) as $chunk) {
        $ph = implode(',', array_fill(0, count($chunk), '?'));
        $fix_sql = "SELECT fix_name, lat, lon FROM dbo.nav_fixes WHERE fix_name IN ({$ph})";
        $fix_stmt = sqlsrv_query($conn_adl, $fix_sql, $chunk);
        if ($fix_stmt) {
            while ($fr = sqlsrv_fetch_array($fix_stmt, SQLSRV_FETCH_ASSOC)) {
                $fix_coords[$fr['fix_name']] = [
                    'lat' => (float)$fr['lat'],
                    'lon' => (float)$fr['lon']
                ];
            }
            sqlsrv_free_stmt($fix_stmt);
        }
    }
}

// Build point features for entry/exit fixes
$point_features = [];
foreach (array_merge(array_values($entry_agg), array_values($exit_agg)) as $agg) {
    if (!isset($fix_coords[$agg['fix']])) continue;
    $c = $fix_coords[$agg['fix']];

    $point_features[] = [
        'type' => 'Feature',
        'properties' => [
            'fix_name' => $agg['fix'],
            'fir' => $agg['fir'],
            'point_type' => $agg['type'],
            'flight_count' => $agg['count']
        ],
        'geometry' => [
            'type' => 'Point',
            'coordinates' => [$c['lon'], $c['lat']]
        ]
    ];
}

echo json_encode([
    'type' => 'FeatureCollection',
    'features' => $features,
    'entry_exit_points' => [
        'type' => 'FeatureCollection',
        'features' => $point_features
    ],
    'stats' => [
        'total_routes' => count($features),
        'entry_fixes' => count($entry_agg),
        'exit_fixes' => count($exit_agg)
    ]
]);
