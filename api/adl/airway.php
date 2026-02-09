<?php
/**
 * Airway Geometry API
 *
 * Returns airway geometry as GeoJSON for map display.
 *
 * Endpoints:
 *   GET ?airway=Y290              - Get full airway geometry
 *   GET ?airway=Y290,J48,Q100     - Get multiple airways
 *
 * Response:
 *   {
 *     "success": true,
 *     "airways": {
 *       "Y290": {
 *         "name": "Y290",
 *         "type": "RNAV_HIGH",
 *         "segments": [...],
 *         "geojson": { "type": "Feature", ... }
 *       }
 *     }
 *   }
 *
 * @version 1.0.0
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once __DIR__ . '/../../load/connect.php';

/**
 * Detect airway type from name using global patterns
 * Supports: J### (Jet), V### (Victor), Q### (RNAV High), T### (RNAV Low),
 *           Y### (RNAV), A### (Oceanic), UL### (Upper European), L/M/N### (European),
 *           AR### (Area Navigation), G### (Low RNAV), B### (Control Area)
 */
function detectAirwayType(string $name): string {
    $name = strtoupper($name);

    // US Airways
    if (preg_match('/^J\d+$/', $name)) return 'JET';
    if (preg_match('/^V\d+$/', $name)) return 'VICTOR';
    if (preg_match('/^Q\d+$/', $name)) return 'RNAV_HIGH';
    if (preg_match('/^T\d+$/', $name)) return 'RNAV_LOW';

    // RNAV airways (Y, G)
    if (preg_match('/^Y\d+$/', $name)) return 'RNAV';
    if (preg_match('/^G\d+$/', $name)) return 'RNAV_LOW';

    // Oceanic
    if (preg_match('/^A\d+$/', $name)) return 'OCEANIC';

    // European/International
    if (preg_match('/^UL\d+$/', $name)) return 'UPPER_EUROPEAN';
    if (preg_match('/^U[A-Z]\d+$/', $name)) return 'UPPER_AIRWAY';
    if (preg_match('/^[LMN]\d+$/', $name)) return 'EUROPEAN';
    if (preg_match('/^B\d+$/', $name)) return 'CONTROL_AREA';

    // Area Navigation Routes
    if (preg_match('/^AR\d+$/', $name)) return 'AREA_NAV';

    // Catch-all for alphanumeric patterns
    if (preg_match('/^[A-Z]{1,2}\d+$/', $name)) return 'AIRWAY';

    return 'OTHER';
}

/**
 * Check if a string looks like an airway identifier
 */
function isAirwayIdentifier(string $name): bool {
    $name = strtoupper(trim($name));

    // Common airway patterns globally
    // J### - Jet routes (US)
    // V### - Victor routes (US)
    // Q### - RNAV high altitude (US/Global)
    // T### - RNAV low altitude (US)
    // Y### - RNAV routes (Global)
    // A### - Oceanic routes
    // UL### - Upper European
    // L/M/N### - European
    // AR### - Area Navigation
    // G### - GNSS routes
    // B### - Control area routes
    // W### - Low level routes (some countries)
    // R### - RNAV routes (some regions)

    return (bool)preg_match('/^(J|V|Q|T|Y|A|UL|UA|UB|UM|UN|L|M|N|AR|G|B|W|R)\d+$/', $name);
}

// Get connection
$conn = get_conn_ref();
if (!$conn) {
    http_response_code(503);
    echo json_encode(['success' => false, 'error' => 'Database connection unavailable']);
    exit;
}

// Parse airway parameter
$airwayParam = $_GET['airway'] ?? '';
if (empty($airwayParam)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Missing required parameter: airway',
        'example' => '/api/adl/airway?airway=Y290',
        'supported_patterns' => [
            'J###' => 'Jet Routes (US)',
            'V###' => 'Victor Routes (US)',
            'Q###' => 'RNAV High (US/Global)',
            'T###' => 'RNAV Low (US)',
            'Y###' => 'RNAV (Global)',
            'A###' => 'Oceanic',
            'UL###' => 'Upper European',
            'L/M/N###' => 'European',
            'AR###' => 'Area Navigation',
        ]
    ]);
    exit;
}

// Parse comma-separated list of airways
$airwayNames = array_filter(array_map(function($a) {
    return strtoupper(trim($a));
}, explode(',', $airwayParam)));

if (empty($airwayNames)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'No valid airway names provided']);
    exit;
}

$result = ['success' => true, 'airways' => []];

foreach ($airwayNames as $airwayName) {
    // Query airway segments
    $sql = "
        SELECT
            s.airway_name,
            s.sequence_num,
            s.from_fix,
            s.to_fix,
            s.from_lat,
            s.from_lon,
            s.to_lat,
            s.to_lon,
            s.distance_nm,
            s.course_deg,
            a.airway_type,
            a.fix_count
        FROM dbo.airway_segments s
        LEFT JOIN dbo.airways a ON s.airway_id = a.airway_id
        WHERE s.airway_name = ?
        ORDER BY s.sequence_num
    ";

    $stmt = sqlsrv_query($conn, $sql, [$airwayName]);
    if ($stmt === false) {
        $result['airways'][$airwayName] = [
            'name' => $airwayName,
            'error' => 'Query failed',
            'found' => false
        ];
        continue;
    }

    $segments = [];
    $coordinates = [];
    $airwayType = null;
    $fixCount = 0;

    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $segments[] = [
            'seq' => (int)$row['sequence_num'],
            'from' => $row['from_fix'],
            'to' => $row['to_fix'],
            'distance_nm' => (float)$row['distance_nm'],
            'course_deg' => (int)$row['course_deg']
        ];

        // Build coordinate array for LineString
        // Add from point (only for first segment or if discontinuity)
        if (empty($coordinates)) {
            $coordinates[] = [(float)$row['from_lon'], (float)$row['from_lat']];
        }
        // Add to point
        $coordinates[] = [(float)$row['to_lon'], (float)$row['to_lat']];

        $airwayType = $row['airway_type'] ?? detectAirwayType($airwayName);
        $fixCount = (int)($row['fix_count'] ?? 0);
    }

    sqlsrv_free_stmt($stmt);

    if (empty($segments)) {
        // Fallback: try PostGIS database (may have airways not in REF)
        try {
            $gis = get_conn_gis();
            if ($gis) {
                $gisStmt = $gis->prepare("
                    SELECT airway_name, from_fix, to_fix,
                           ST_X(ST_StartPoint(segment_geom)) as from_lon,
                           ST_Y(ST_StartPoint(segment_geom)) as from_lat,
                           ST_X(ST_EndPoint(segment_geom)) as to_lon,
                           ST_Y(ST_EndPoint(segment_geom)) as to_lat,
                           distance_nm
                    FROM airway_segments
                    WHERE airway_name = :name
                    ORDER BY sequence_num
                ");
                $gisStmt->execute([':name' => $airwayName]);
                $gisRows = $gisStmt->fetchAll(PDO::FETCH_ASSOC);

                if (!empty($gisRows)) {
                    $gisCoordinates = [];
                    foreach ($gisRows as $idx => $row) {
                        $segments[] = [
                            'seq' => $idx + 1,
                            'from' => $row['from_fix'],
                            'to' => $row['to_fix'],
                            'distance_nm' => (float)($row['distance_nm'] ?? 0),
                            'course_deg' => 0
                        ];
                        if (empty($gisCoordinates)) {
                            $gisCoordinates[] = [(float)$row['from_lon'], (float)$row['from_lat']];
                        }
                        $gisCoordinates[] = [(float)$row['to_lon'], (float)$row['to_lat']];
                    }
                    $coordinates = $gisCoordinates;
                    $airwayType = detectAirwayType($airwayName);
                }
            }
        } catch (Exception $e) {
            // GIS fallback failed, continue with not-found
        }
    }

    if (empty($segments)) {
        $result['airways'][$airwayName] = [
            'name' => $airwayName,
            'type' => detectAirwayType($airwayName),
            'found' => false,
            'error' => 'Airway not found in database'
        ];
        continue;
    }

    // Build GeoJSON Feature
    $geojson = [
        'type' => 'Feature',
        'properties' => [
            'airway' => $airwayName,
            'type' => $airwayType,
            'segment_count' => count($segments),
            'fix_count' => $fixCount,
            'total_distance_nm' => array_sum(array_column($segments, 'distance_nm'))
        ],
        'geometry' => [
            'type' => 'LineString',
            'coordinates' => $coordinates
        ]
    ];

    $result['airways'][$airwayName] = [
        'name' => $airwayName,
        'type' => $airwayType,
        'found' => true,
        'segment_count' => count($segments),
        'fix_count' => $fixCount,
        'total_distance_nm' => round(array_sum(array_column($segments, 'distance_nm')), 1),
        'segments' => $segments,
        'geojson' => $geojson
    ];
}

sqlsrv_close($conn);

// Add helper function export for client-side use
$result['_helpers'] = [
    'isAirway' => 'function(name) { return /^(J|V|Q|T|Y|A|UL|UA|UB|UM|UN|L|M|N|AR|G|B|W|R)\d+$/.test(name.toUpperCase()); }'
];

echo json_encode($result, JSON_PRETTY_PRINT);
