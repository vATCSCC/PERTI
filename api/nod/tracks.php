<?php
/**
 * api/nod/tracks.php
 * 
 * GET - Retrieve track history for multiple flights
 * 
 * Query params:
 *   flight_keys  - Comma-separated list of flight_key values (max 50)
 *   since_hours  - Hours of history to retrieve (default: 2, max: 24)
 *   limit        - Max points per flight (default: 100, max: 500)
 * 
 * Returns GeoJSON FeatureCollection with LineString features for each flight track
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/../../load/connect.php';

try {
    // Parse parameters
    $flightKeysParam = $_GET['flight_keys'] ?? '';
    $sinceHours = min(24, max(1, intval($_GET['since_hours'] ?? 2)));
    $limitPerFlight = min(500, max(10, intval($_GET['limit'] ?? 100)));
    
    if (empty($flightKeysParam)) {
        echo json_encode([
            'type' => 'FeatureCollection',
            'features' => [],
            'debug' => ['error' => 'No flight_keys provided']
        ]);
        exit;
    }
    
    // Parse and limit flight keys (max 50 to prevent abuse)
    $flightKeys = array_slice(
        array_filter(array_map('trim', explode(',', $flightKeysParam))),
        0, 
        50
    );
    
    if (empty($flightKeys)) {
        echo json_encode([
            'type' => 'FeatureCollection',
            'features' => [],
            'debug' => ['error' => 'No valid flight_keys after parsing']
        ]);
        exit;
    }
    
    // Check if history table exists
    $checkSql = "SELECT COUNT(*) as cnt FROM sys.objects WHERE object_id = OBJECT_ID(N'dbo.adl_flights_history') AND type = 'U'";
    $checkStmt = sqlsrv_query($conn_adl, $checkSql);
    $checkRow = sqlsrv_fetch_array($checkStmt, SQLSRV_FETCH_ASSOC);
    sqlsrv_free_stmt($checkStmt);
    
    if (!$checkRow || $checkRow['cnt'] == 0) {
        echo json_encode([
            'type' => 'FeatureCollection',
            'features' => [],
            'debug' => ['error' => 'History table not found. Run migration 002_adl_history_stored_procedure.sql']
        ]);
        exit;
    }
    
    // Build placeholders for flight keys
    $placeholders = implode(',', array_fill(0, count($flightKeys), '?'));
    
    // Calculate since timestamp
    $sinceUtc = gmdate('Y-m-d H:i:s', strtotime("-{$sinceHours} hours"));
    
    // Simpler query - get all points then sample in PHP
    // This avoids complex SQL that may fail on some configurations
    $sql = "
        SELECT 
            flight_key,
            callsign,
            lat,
            lon,
            altitude,
            groundspeed,
            heading_deg,
            snapshot_utc
        FROM dbo.adl_flights_history
        WHERE flight_key IN ($placeholders)
          AND snapshot_utc >= ?
          AND lat IS NOT NULL
          AND lon IS NOT NULL
        ORDER BY flight_key, snapshot_utc ASC
    ";
    
    // Parameters: flight_keys..., since_utc
    $params = array_merge($flightKeys, [$sinceUtc]);
    
    $stmt = sqlsrv_query($conn_adl, $sql, $params);
    
    if ($stmt === false) {
        $errors = sqlsrv_errors();
        echo json_encode([
            'type' => 'FeatureCollection',
            'features' => [],
            'debug' => ['error' => 'Query failed', 'sql_errors' => $errors]
        ]);
        exit;
    }
    
    // Group points by flight_key
    $flightTracks = [];
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $key = $row['flight_key'];
        if (!isset($flightTracks[$key])) {
            $flightTracks[$key] = [
                'callsign' => $row['callsign'],
                'points' => []
            ];
        }
        
        $flightTracks[$key]['points'][] = [
            'lon' => floatval($row['lon']),
            'lat' => floatval($row['lat']),
            'alt' => intval($row['altitude'] ?? 0),
            'gs' => intval($row['groundspeed'] ?? 0),
            'hdg' => intval($row['heading_deg'] ?? 0),
            'time' => $row['snapshot_utc'] instanceof DateTime 
                ? $row['snapshot_utc']->format('Y-m-d\TH:i:s\Z')
                : $row['snapshot_utc']
        ];
    }
    sqlsrv_free_stmt($stmt);
    
    // Sample points if exceeding limit (keep first, last, and evenly spaced middle)
    foreach ($flightTracks as $key => &$track) {
        $count = count($track['points']);
        if ($count > $limitPerFlight) {
            $sampled = [];
            $step = ($count - 1) / ($limitPerFlight - 1);
            for ($i = 0; $i < $limitPerFlight; $i++) {
                $idx = (int)round($i * $step);
                $sampled[] = $track['points'][$idx];
            }
            $track['points'] = $sampled;
        }
    }
    unset($track);
    
    // Convert to GeoJSON FeatureCollection with LineString features
    $features = [];
    foreach ($flightTracks as $flightKey => $track) {
        // Need at least 2 points to make a line
        if (count($track['points']) < 2) {
            continue;
        }
        
        // Build coordinate array [lon, lat, altitude]
        $coordinates = array_map(function($pt) {
            return [$pt['lon'], $pt['lat'], $pt['alt']];
        }, $track['points']);
        
        $features[] = [
            'type' => 'Feature',
            'geometry' => [
                'type' => 'LineString',
                'coordinates' => $coordinates
            ],
            'properties' => [
                'flight_key' => $flightKey,
                'callsign' => $track['callsign'],
                'point_count' => count($track['points']),
                'start_time' => $track['points'][0]['time'],
                'end_time' => $track['points'][count($track['points']) - 1]['time']
            ]
        ];
    }
    
    echo json_encode([
        'type' => 'FeatureCollection',
        'features' => $features,
        'metadata' => [
            'requested_flights' => count($flightKeys),
            'tracks_returned' => count($features),
            'since_utc' => $sinceUtc,
            'limit_per_flight' => $limitPerFlight
        ]
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'type' => 'FeatureCollection',
        'features' => [],
        'debug' => ['error' => $e->getMessage()]
    ]);
}
