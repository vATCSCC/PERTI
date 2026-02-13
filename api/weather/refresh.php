<?php
/**
 * api/weather/refresh.php
 * 
 * Triggers a refresh of weather data from aviationweather.gov
 * Can be called manually or by a scheduled task
 * 
 * @version 1.0
 * @date 2026-01-06
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

// Only allow refresh from localhost or authenticated users
$allowed = false;

// Check if localhost
$remoteIp = $_SERVER['REMOTE_ADDR'] ?? '';
if (in_array($remoteIp, ['127.0.0.1', '::1', 'localhost'])) {
    $allowed = true;
}

// Check for API key (optional authentication)
$apiKey = $_SERVER['HTTP_X_API_KEY'] ?? $_GET['api_key'] ?? '';
if (defined('WEATHER_REFRESH_KEY') && WEATHER_REFRESH_KEY !== '' && $apiKey === WEATHER_REFRESH_KEY) {
    $allowed = true;
}

// Check session for logged-in admin
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
if (!empty($_SESSION['user_id']) && !empty($_SESSION['is_admin'])) {
    $allowed = true;
}

if (!$allowed) {
    http_response_code(403);
    echo json_encode(['error' => 'Access denied']);
    exit;
}

// ---------------------------------------------------------------------------
// Fetch weather data from aviationweather.gov
// ---------------------------------------------------------------------------

$awcUrl = 'https://aviationweather.gov/api/data/airsigmet?format=json';

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => $awcUrl,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 30,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_USERAGENT => 'PERTI-WeatherImport/1.0',
    CURLOPT_HTTPHEADER => ['Accept: application/json']
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);

if ($error) {
    http_response_code(502);
    echo json_encode(['error' => 'Failed to fetch from AWC: ' . $error]);
    exit;
}

if ($httpCode !== 200) {
    http_response_code(502);
    echo json_encode(['error' => "AWC returned HTTP $httpCode"]);
    exit;
}

$rawAlerts = json_decode($response, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(502);
    echo json_encode(['error' => 'Invalid JSON from AWC']);
    exit;
}

// ---------------------------------------------------------------------------
// Process alerts into our format
// ---------------------------------------------------------------------------

$processedAlerts = [];

foreach ($rawAlerts as $raw) {
    $alert = processAlert($raw);
    if ($alert) {
        $processedAlerts[] = $alert;
    }
}

if (count($processedAlerts) === 0) {
    echo json_encode([
        'success' => true,
        'message' => 'No valid alerts to import',
        'received' => count($rawAlerts),
        'processed' => 0
    ]);
    exit;
}

// ---------------------------------------------------------------------------
// Import to database
// ---------------------------------------------------------------------------

require_once("../../load/config.php");

if (!defined("ADL_SQL_HOST")) {
    http_response_code(500);
    echo json_encode(['error' => 'Database configuration missing']);
    exit;
}

$connectionInfo = [
    "Database" => ADL_SQL_DATABASE,
    "UID" => ADL_SQL_USERNAME,
    "PWD" => ADL_SQL_PASSWORD,
    "Encrypt" => true,
    "TrustServerCertificate" => false,
    "LoginTimeout" => 10,
    "CharacterSet" => "UTF-8"
];

$conn = sqlsrv_connect(ADL_SQL_HOST, $connectionInfo);
if ($conn === false) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed']);
    exit;
}

// Convert to JSON for stored procedure
$jsonPayload = json_encode($processedAlerts);

$sql = "EXEC dbo.sp_ImportWeatherAlerts @json = ?, @source_url = ?, 
        @alerts_inserted = ? OUTPUT, @alerts_updated = ? OUTPUT, @alerts_expired = ? OUTPUT";

$inserted = 0;
$updated = 0;
$expired = 0;

$params = [
    [$jsonPayload, SQLSRV_PARAM_IN],
    [$awcUrl, SQLSRV_PARAM_IN],
    [&$inserted, SQLSRV_PARAM_OUT, SQLSRV_PHPTYPE_INT],
    [&$updated, SQLSRV_PARAM_OUT, SQLSRV_PHPTYPE_INT],
    [&$expired, SQLSRV_PARAM_OUT, SQLSRV_PHPTYPE_INT]
];

$stmt = sqlsrv_query($conn, $sql, $params);

if ($stmt === false) {
    $errors = sqlsrv_errors();
    http_response_code(500);
    echo json_encode(['error' => 'Import failed', 'details' => $errors]);
    sqlsrv_close($conn);
    exit;
}

sqlsrv_next_result($stmt); // Move past result sets to get output params
sqlsrv_free_stmt($stmt);
sqlsrv_close($conn);

echo json_encode([
    'success' => true,
    'received' => count($rawAlerts),
    'processed' => count($processedAlerts),
    'inserted' => $inserted,
    'updated' => $updated,
    'expired' => $expired,
    'timestamp' => gmdate('Y-m-d\TH:i:s\Z')
]);

// ---------------------------------------------------------------------------
// Helper Functions
// ---------------------------------------------------------------------------

function processAlert($raw) {
    // Validate required fields
    if (empty($raw['coords']) || count($raw['coords']) < 3) {
        return null;
    }
    
    // Determine alert type
    $rawType = strtoupper($raw['airSigmetType'] ?? '');
    $hazard = strtoupper($raw['hazard'] ?? 'UNKNOWN');
    
    $alertType = 'SIGMET';
    if (strpos($rawType, 'AIRMET') !== false) $alertType = 'AIRMET';
    elseif (strpos($rawType, 'OUTLOOK') !== false) $alertType = 'OUTLOOK';
    elseif ($hazard === 'CONVECTIVE') $alertType = 'CONVECTIVE';
    
    // Parse coordinates to WKT
    $points = [];
    foreach ($raw['coords'] as $coord) {
        $lat = $coord['lat'] ?? null;
        $lon = $coord['lon'] ?? null;
        if ($lat !== null && $lon !== null) {
            $points[] = "$lon $lat";
        }
    }
    
    if (count($points) < 3) return null;
    
    // Ensure closed polygon
    if ($points[0] !== end($points)) {
        $points[] = $points[0];
    }
    
    $wkt = "POLYGON((" . implode(', ', $points) . "))";
    
    // Calculate centroid
    $latSum = 0;
    $lonSum = 0;
    foreach ($raw['coords'] as $coord) {
        $latSum += $coord['lat'];
        $lonSum += $coord['lon'];
    }
    $centerLat = round($latSum / count($raw['coords']), 7);
    $centerLon = round($lonSum / count($raw['coords']), 7);
    
    // Parse times (Unix timestamps)
    $validFrom = isset($raw['validTimeFrom']) ? gmdate('Y-m-d\TH:i:s\Z', $raw['validTimeFrom']) : null;
    $validTo = isset($raw['validTimeTo']) ? gmdate('Y-m-d\TH:i:s\Z', $raw['validTimeTo']) : null;
    
    if (!$validFrom || !$validTo) return null;
    
    // Parse altitudes (feet to FL)
    $floorFl = isset($raw['altitudeLow1']) ? intval($raw['altitudeLow1'] / 100) : null;
    $ceilingFl = isset($raw['altitudeHi2']) ? intval($raw['altitudeHi2'] / 100) : 
                 (isset($raw['altitudeHi1']) ? intval($raw['altitudeHi1'] / 100) : null);
    
    // Source ID
    $sourceId = isset($raw['seriesId']) ? str_replace(' ', '_', $raw['seriesId']) : 
                ($alertType . '_' . $hazard . '_' . time());
    
    // Severity mapping
    $severityMap = [1 => 'LGT', 2 => 'LGT', 3 => 'MOD', 4 => 'MOD', 5 => 'SEV'];
    $severity = isset($raw['severity']) && isset($severityMap[$raw['severity']]) 
                ? $severityMap[$raw['severity']] : null;
    
    return [
        'alert_type' => $alertType,
        'hazard' => $hazard,
        'severity' => $severity,
        'source_id' => $sourceId,
        'valid_from' => $validFrom,
        'valid_to' => $validTo,
        'floor_fl' => $floorFl,
        'ceiling_fl' => $ceilingFl,
        'direction' => $raw['movementDir'] ?? null,
        'speed' => $raw['movementSpd'] ?? null,
        'wkt' => $wkt,
        'center_lat' => $centerLat,
        'center_lon' => $centerLon,
        'raw_text' => $raw['rawAirSigmet'] ?? null
    ];
}
