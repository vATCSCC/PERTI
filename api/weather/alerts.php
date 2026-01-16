<?php
/**
 * api/weather/alerts.php
 * 
 * Returns active weather alerts (SIGMET/AIRMET) with GeoJSON geometry
 * for display on the TSD map.
 * 
 * Parameters:
 *   type     - Filter by alert type: SIGMET, AIRMET, CONVECTIVE, OUTLOOK (optional)
 *   hazard   - Filter by hazard: TURB, ICE, CONVECTIVE, IFR, MTN (optional)
 *   format   - Response format: json (default), geojson
 * 
 * @version 1.0
 * @date 2026-01-06
 */

if (session_status() == PHP_SESSION_NONE) {
    session_start();
    ob_start();
}

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: max-age=60'); // Cache for 1 minute

// ---------------------------------------------------------------------------
// 1) Database connection
// ---------------------------------------------------------------------------

require_once("../../load/config.php");
require_once("../../load/input.php");

if (!defined("ADL_SQL_HOST") || !defined("ADL_SQL_DATABASE") ||
    !defined("ADL_SQL_USERNAME") || !defined("ADL_SQL_PASSWORD")) {
    http_response_code(500);
    echo json_encode(["error" => "ADL database configuration missing"]);
    exit;
}

function sql_error_message() {
    $errs = sqlsrv_errors(SQLSRV_ERR_ERRORS);
    if (!$errs) return "";
    $msgs = [];
    foreach ($errs as $e) {
        $msgs[] = trim($e['message'] ?? '');
    }
    return implode(" | ", $msgs);
}

if (!function_exists('sqlsrv_connect')) {
    http_response_code(500);
    echo json_encode(["error" => "sqlsrv extension not available"]);
    exit;
}

$connectionInfo = [
    "Database"                => ADL_SQL_DATABASE,
    "UID"                     => ADL_SQL_USERNAME,
    "PWD"                     => ADL_SQL_PASSWORD,
    "Encrypt"                 => true,
    "TrustServerCertificate"  => false,
    "LoginTimeout"            => 10,
    "CharacterSet"            => "UTF-8"
];

$conn = sqlsrv_connect(ADL_SQL_HOST, $connectionInfo);
if ($conn === false) {
    http_response_code(500);
    echo json_encode(["error" => "Database connection failed: " . sql_error_message()]);
    exit;
}

// ---------------------------------------------------------------------------
// 2) Parse parameters
// ---------------------------------------------------------------------------

$alertType = isset($_GET['type']) ? get_upper('type') : null;
$hazard = isset($_GET['hazard']) ? get_upper('hazard') : null;
$format = isset($_GET['format']) ? get_lower('format') : 'json';

// ---------------------------------------------------------------------------
// 3) Query active alerts
// ---------------------------------------------------------------------------

$sql = "
    SELECT 
        alert_id,
        alert_type,
        hazard,
        severity,
        source_id,
        valid_from_utc,
        valid_to_utc,
        floor_fl,
        ceiling_fl,
        direction_deg,
        speed_kts,
        geometry.STAsText() AS wkt,
        center_lat,
        center_lon,
        area_sq_nm,
        raw_text,
        DATEDIFF(MINUTE, SYSUTCDATETIME(), valid_to_utc) AS minutes_remaining
    FROM dbo.weather_alerts
    WHERE is_active = 1
      AND valid_to_utc > SYSUTCDATETIME()
";

$params = [];

if ($alertType) {
    $sql .= " AND alert_type = ?";
    $params[] = $alertType;
}

if ($hazard) {
    $sql .= " AND hazard = ?";
    $params[] = $hazard;
}

$sql .= " ORDER BY 
    CASE hazard 
        WHEN 'CONVECTIVE' THEN 1 
        WHEN 'TURB' THEN 2 
        WHEN 'ICE' THEN 3 
        ELSE 4 
    END,
    valid_to_utc";

$stmt = sqlsrv_query($conn, $sql, $params);

if ($stmt === false) {
    http_response_code(500);
    echo json_encode(["error" => "Query failed: " . sql_error_message()]);
    sqlsrv_close($conn);
    exit;
}

// ---------------------------------------------------------------------------
// 4) Build response
// ---------------------------------------------------------------------------

$alerts = [];

while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
    // Parse WKT to GeoJSON coordinates
    $geometry = null;
    if (!empty($row['wkt'])) {
        $geometry = wktToGeoJson($row['wkt']);
    }
    
    // Format times
    $validFrom = $row['valid_from_utc'] instanceof DateTime 
        ? $row['valid_from_utc']->format('Y-m-d\TH:i:s\Z')
        : $row['valid_from_utc'];
    $validTo = $row['valid_to_utc'] instanceof DateTime
        ? $row['valid_to_utc']->format('Y-m-d\TH:i:s\Z')
        : $row['valid_to_utc'];
    
    $alert = [
        'alert_id' => (int)$row['alert_id'],
        'type' => $row['alert_type'],
        'hazard' => $row['hazard'],
        'severity' => $row['severity'],
        'source_id' => $row['source_id'],
        'valid_from' => $validFrom,
        'valid_to' => $validTo,
        'floor_fl' => $row['floor_fl'] !== null ? (int)$row['floor_fl'] : null,
        'ceiling_fl' => $row['ceiling_fl'] !== null ? (int)$row['ceiling_fl'] : null,
        'direction_deg' => $row['direction_deg'] !== null ? (int)$row['direction_deg'] : null,
        'speed_kts' => $row['speed_kts'] !== null ? (int)$row['speed_kts'] : null,
        'center_lat' => $row['center_lat'] !== null ? (float)$row['center_lat'] : null,
        'center_lon' => $row['center_lon'] !== null ? (float)$row['center_lon'] : null,
        'area_sq_nm' => $row['area_sq_nm'] !== null ? (float)$row['area_sq_nm'] : null,
        'minutes_remaining' => (int)$row['minutes_remaining'],
        'raw_text' => $row['raw_text'],
        'geometry' => $geometry
    ];
    
    $alerts[] = $alert;
}

sqlsrv_free_stmt($stmt);
sqlsrv_close($conn);

// ---------------------------------------------------------------------------
// 5) Format response
// ---------------------------------------------------------------------------

if ($format === 'geojson') {
    // Return as GeoJSON FeatureCollection
    $features = [];
    foreach ($alerts as $alert) {
        if ($alert['geometry']) {
            $features[] = [
                'type' => 'Feature',
                'id' => $alert['alert_id'],
                'geometry' => $alert['geometry'],
                'properties' => [
                    'alert_id' => $alert['alert_id'],
                    'type' => $alert['type'],
                    'hazard' => $alert['hazard'],
                    'severity' => $alert['severity'],
                    'source_id' => $alert['source_id'],
                    'valid_from' => $alert['valid_from'],
                    'valid_to' => $alert['valid_to'],
                    'floor_fl' => $alert['floor_fl'],
                    'ceiling_fl' => $alert['ceiling_fl'],
                    'minutes_remaining' => $alert['minutes_remaining'],
                    'raw_text' => $alert['raw_text']
                ]
            ];
        }
    }
    
    echo json_encode([
        'type' => 'FeatureCollection',
        'features' => $features,
        'generated_utc' => gmdate('Y-m-d\TH:i:s\Z'),
        'count' => count($features)
    ], JSON_PRETTY_PRINT);
} else {
    // Standard JSON response
    echo json_encode([
        'success' => true,
        'generated_utc' => gmdate('Y-m-d\TH:i:s\Z'),
        'count' => count($alerts),
        'alerts' => $alerts
    ], JSON_PRETTY_PRINT);
}

// ---------------------------------------------------------------------------
// Helper: Convert WKT to GeoJSON geometry
// ---------------------------------------------------------------------------

function wktToGeoJson($wkt) {
    // Parse POLYGON((lon lat, lon lat, ...))
    if (preg_match('/POLYGON\s*\(\(([^)]+)\)\)/i', $wkt, $matches)) {
        $coordString = $matches[1];
        $pairs = explode(',', $coordString);
        $coordinates = [];
        
        foreach ($pairs as $pair) {
            $pair = trim($pair);
            $parts = preg_split('/\s+/', $pair);
            if (count($parts) >= 2) {
                $lon = (float)$parts[0];
                $lat = (float)$parts[1];
                $coordinates[] = [$lon, $lat];
            }
        }
        
        if (count($coordinates) >= 3) {
            return [
                'type' => 'Polygon',
                'coordinates' => [$coordinates]
            ];
        }
    }
    
    // Parse MULTIPOLYGON
    if (preg_match('/MULTIPOLYGON\s*\(\(\(([^)]+)\)\)/i', $wkt, $matches)) {
        // Simplified - just take first polygon
        $coordString = $matches[1];
        $pairs = explode(',', $coordString);
        $coordinates = [];
        
        foreach ($pairs as $pair) {
            $pair = trim($pair);
            $parts = preg_split('/\s+/', $pair);
            if (count($parts) >= 2) {
                $lon = (float)$parts[0];
                $lat = (float)$parts[1];
                $coordinates[] = [$lon, $lat];
            }
        }
        
        if (count($coordinates) >= 3) {
            return [
                'type' => 'Polygon',
                'coordinates' => [$coordinates]
            ];
        }
    }
    
    return null;
}
