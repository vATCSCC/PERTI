<?php
/**
 * api/weather/impact.php
 * 
 * Returns weather impact information for flights
 * 
 * Parameters:
 *   flight_uid  - Get impact for specific flight
 *   summary     - Get system-wide impact summary (if set to 1)
 *   affected    - Get list of affected flights (if set to 1)
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
header('Cache-Control: max-age=15'); // Cache for 15 seconds

// ---------------------------------------------------------------------------
// Database connection
// ---------------------------------------------------------------------------

require_once("../../load/config.php");
require_once("../../load/input.php");

if (!defined("ADL_SQL_HOST")) {
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
    echo json_encode(["error" => "Database connection failed"]);
    exit;
}

// ---------------------------------------------------------------------------
// Parse parameters
// ---------------------------------------------------------------------------

$flightUid = isset($_GET['flight_uid']) ? get_int('flight_uid') : null;
$summary = isset($_GET['summary']) && $_GET['summary'] == '1';
$affected = isset($_GET['affected']) && $_GET['affected'] == '1';

// ---------------------------------------------------------------------------
// Handle requests
// ---------------------------------------------------------------------------

if ($flightUid) {
    // Get impact for specific flight
    getFlightImpact($conn, $flightUid);
} elseif ($summary) {
    // Get system-wide summary
    getImpactSummary($conn);
} elseif ($affected) {
    // Get list of affected flights
    getAffectedFlights($conn);
} else {
    // Default: return summary stats
    getQuickStats($conn);
}

sqlsrv_close($conn);

// ---------------------------------------------------------------------------
// Functions
// ---------------------------------------------------------------------------

function getFlightImpact($conn, $flightUid) {
    $sql = "
        SELECT 
            c.flight_uid,
            c.callsign,
            c.weather_impact,
            c.weather_alert_ids,
            c.last_weather_check_utc,
            p.lat,
            p.lon,
            p.altitude_ft
        FROM dbo.adl_flight_core c
        LEFT JOIN dbo.adl_flight_position p ON p.flight_uid = c.flight_uid
        WHERE c.flight_uid = ?
    ";
    
    $stmt = sqlsrv_query($conn, $sql, [$flightUid]);
    if ($stmt === false) {
        http_response_code(500);
        echo json_encode(["error" => "Query failed"]);
        return;
    }
    
    $flight = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    sqlsrv_free_stmt($stmt);
    
    if (!$flight) {
        http_response_code(404);
        echo json_encode(["error" => "Flight not found"]);
        return;
    }
    
    // Get active impacts with alert details
    $sql = "
        SELECT 
            wi.impact_id,
            wi.alert_id,
            wi.impact_type,
            wi.distance_nm,
            wi.detected_utc,
            wa.alert_type,
            wa.hazard,
            wa.severity,
            wa.source_id,
            wa.valid_from_utc,
            wa.valid_to_utc,
            wa.floor_fl,
            wa.ceiling_fl,
            wa.raw_text,
            DATEDIFF(MINUTE, SYSUTCDATETIME(), wa.valid_to_utc) AS minutes_remaining
        FROM dbo.adl_flight_weather_impact wi
        JOIN dbo.weather_alerts wa ON wa.alert_id = wi.alert_id
        WHERE wi.flight_uid = ?
          AND wi.cleared_utc IS NULL
        ORDER BY 
            CASE wi.impact_type WHEN 'DIRECT' THEN 1 ELSE 2 END,
            CASE wa.hazard WHEN 'CONVECTIVE' THEN 1 WHEN 'TURB' THEN 2 ELSE 3 END
    ";
    
    $stmt = sqlsrv_query($conn, $sql, [$flightUid]);
    $impacts = [];
    
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $impacts[] = [
            'impact_id' => (int)$row['impact_id'],
            'alert_id' => (int)$row['alert_id'],
            'impact_type' => $row['impact_type'],
            'distance_nm' => $row['distance_nm'] !== null ? round((float)$row['distance_nm'], 1) : null,
            'detected_utc' => formatDateTime($row['detected_utc']),
            'alert' => [
                'type' => $row['alert_type'],
                'hazard' => $row['hazard'],
                'severity' => $row['severity'],
                'source_id' => $row['source_id'],
                'valid_from' => formatDateTime($row['valid_from_utc']),
                'valid_to' => formatDateTime($row['valid_to_utc']),
                'floor_fl' => $row['floor_fl'] !== null ? (int)$row['floor_fl'] : null,
                'ceiling_fl' => $row['ceiling_fl'] !== null ? (int)$row['ceiling_fl'] : null,
                'minutes_remaining' => (int)$row['minutes_remaining'],
                'raw_text' => $row['raw_text']
            ]
        ];
    }
    sqlsrv_free_stmt($stmt);
    
    echo json_encode([
        'success' => true,
        'flight' => [
            'flight_uid' => (int)$flight['flight_uid'],
            'callsign' => $flight['callsign'],
            'weather_impact' => $flight['weather_impact'],
            'weather_alert_ids' => $flight['weather_alert_ids'],
            'last_check_utc' => formatDateTime($flight['last_weather_check_utc']),
            'position' => [
                'lat' => $flight['lat'] !== null ? (float)$flight['lat'] : null,
                'lon' => $flight['lon'] !== null ? (float)$flight['lon'] : null,
                'altitude_ft' => $flight['altitude_ft'] !== null ? (int)$flight['altitude_ft'] : null
            ]
        ],
        'impacts' => $impacts,
        'impact_count' => count($impacts)
    ], JSON_PRETTY_PRINT);
}

function getImpactSummary($conn) {
    // Summary by hazard type
    $sql = "
        SELECT 
            wa.hazard,
            wa.alert_type,
            COUNT(DISTINCT wi.flight_uid) AS flights_affected,
            SUM(CASE WHEN wi.impact_type = 'DIRECT' THEN 1 ELSE 0 END) AS direct_impacts,
            SUM(CASE WHEN wi.impact_type = 'NEAR' THEN 1 ELSE 0 END) AS near_impacts
        FROM dbo.weather_alerts wa
        LEFT JOIN dbo.adl_flight_weather_impact wi ON wi.alert_id = wa.alert_id AND wi.cleared_utc IS NULL
        WHERE wa.is_active = 1
          AND wa.valid_to_utc > SYSUTCDATETIME()
        GROUP BY wa.hazard, wa.alert_type
        ORDER BY flights_affected DESC
    ";
    
    $stmt = sqlsrv_query($conn, $sql);
    $byHazard = [];
    
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $byHazard[] = [
            'hazard' => $row['hazard'],
            'alert_type' => $row['alert_type'],
            'flights_affected' => (int)$row['flights_affected'],
            'direct_impacts' => (int)$row['direct_impacts'],
            'near_impacts' => (int)$row['near_impacts']
        ];
    }
    sqlsrv_free_stmt($stmt);
    
    // Per-alert details
    $sql = "
        SELECT 
            wa.alert_id,
            wa.alert_type,
            wa.hazard,
            wa.severity,
            wa.source_id,
            wa.valid_from_utc,
            wa.valid_to_utc,
            DATEDIFF(MINUTE, SYSUTCDATETIME(), wa.valid_to_utc) AS minutes_remaining,
            COUNT(DISTINCT wi.flight_uid) AS flights_affected
        FROM dbo.weather_alerts wa
        LEFT JOIN dbo.adl_flight_weather_impact wi ON wi.alert_id = wa.alert_id AND wi.cleared_utc IS NULL
        WHERE wa.is_active = 1
          AND wa.valid_to_utc > SYSUTCDATETIME()
        GROUP BY wa.alert_id, wa.alert_type, wa.hazard, wa.severity, wa.source_id, 
                 wa.valid_from_utc, wa.valid_to_utc
        ORDER BY flights_affected DESC
    ";
    
    $stmt = sqlsrv_query($conn, $sql);
    $alerts = [];
    
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $alerts[] = [
            'alert_id' => (int)$row['alert_id'],
            'alert_type' => $row['alert_type'],
            'hazard' => $row['hazard'],
            'severity' => $row['severity'],
            'source_id' => $row['source_id'],
            'valid_from' => formatDateTime($row['valid_from_utc']),
            'valid_to' => formatDateTime($row['valid_to_utc']),
            'minutes_remaining' => (int)$row['minutes_remaining'],
            'flights_affected' => (int)$row['flights_affected']
        ];
    }
    sqlsrv_free_stmt($stmt);
    
    // Total counts
    $totalFlights = 0;
    $directCount = 0;
    $nearCount = 0;
    foreach ($byHazard as $h) {
        $totalFlights += $h['flights_affected'];
        $directCount += $h['direct_impacts'];
        $nearCount += $h['near_impacts'];
    }
    
    echo json_encode([
        'success' => true,
        'generated_utc' => gmdate('Y-m-d\TH:i:s\Z'),
        'totals' => [
            'active_alerts' => count($alerts),
            'flights_affected' => $totalFlights,
            'direct_impacts' => $directCount,
            'near_impacts' => $nearCount
        ],
        'by_hazard' => $byHazard,
        'alerts' => $alerts
    ], JSON_PRETTY_PRINT);
}

function getAffectedFlights($conn) {
    $sql = "
        SELECT TOP 200
            c.flight_uid,
            c.callsign,
            c.weather_impact,
            fp.fp_dept_icao,
            fp.fp_dest_icao,
            p.lat,
            p.lon,
            p.altitude_ft,
            wi.impact_type,
            wa.hazard,
            wa.source_id,
            wa.alert_id
        FROM dbo.adl_flight_core c
        JOIN dbo.adl_flight_weather_impact wi ON wi.flight_uid = c.flight_uid AND wi.cleared_utc IS NULL
        JOIN dbo.weather_alerts wa ON wa.alert_id = wi.alert_id
        LEFT JOIN dbo.adl_flight_position p ON p.flight_uid = c.flight_uid
        LEFT JOIN dbo.adl_flight_plan fp ON fp.flight_uid = c.flight_uid
        WHERE c.is_active = 1
        ORDER BY 
            CASE wi.impact_type WHEN 'DIRECT' THEN 1 ELSE 2 END,
            CASE wa.hazard WHEN 'CONVECTIVE' THEN 1 WHEN 'TURB' THEN 2 ELSE 3 END
    ";
    
    $stmt = sqlsrv_query($conn, $sql);
    $flights = [];
    
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $flights[] = [
            'flight_uid' => (int)$row['flight_uid'],
            'callsign' => $row['callsign'],
            'weather_impact' => $row['weather_impact'],
            'departure' => $row['fp_dept_icao'],
            'destination' => $row['fp_dest_icao'],
            'position' => [
                'lat' => $row['lat'] !== null ? (float)$row['lat'] : null,
                'lon' => $row['lon'] !== null ? (float)$row['lon'] : null,
                'altitude_ft' => $row['altitude_ft'] !== null ? (int)$row['altitude_ft'] : null
            ],
            'impact_type' => $row['impact_type'],
            'hazard' => $row['hazard'],
            'source_id' => $row['source_id'],
            'alert_id' => (int)$row['alert_id']
        ];
    }
    sqlsrv_free_stmt($stmt);
    
    echo json_encode([
        'success' => true,
        'generated_utc' => gmdate('Y-m-d\TH:i:s\Z'),
        'count' => count($flights),
        'flights' => $flights
    ], JSON_PRETTY_PRINT);
}

function getQuickStats($conn) {
    $sql = "
        SELECT 
            (SELECT COUNT(*) FROM dbo.weather_alerts WHERE is_active = 1 AND valid_to_utc > SYSUTCDATETIME()) AS active_alerts,
            (SELECT COUNT(DISTINCT flight_uid) FROM dbo.adl_flight_weather_impact WHERE cleared_utc IS NULL) AS flights_affected,
            (SELECT COUNT(*) FROM dbo.adl_flight_weather_impact WHERE cleared_utc IS NULL AND impact_type = 'DIRECT') AS direct_impacts,
            (SELECT COUNT(*) FROM dbo.adl_flight_weather_impact WHERE cleared_utc IS NULL AND impact_type = 'NEAR') AS near_impacts
    ";
    
    $stmt = sqlsrv_query($conn, $sql);
    $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    sqlsrv_free_stmt($stmt);
    
    echo json_encode([
        'success' => true,
        'generated_utc' => gmdate('Y-m-d\TH:i:s\Z'),
        'active_alerts' => (int)$row['active_alerts'],
        'flights_affected' => (int)$row['flights_affected'],
        'direct_impacts' => (int)$row['direct_impacts'],
        'near_impacts' => (int)$row['near_impacts']
    ]);
}

function formatDateTime($dt) {
    if ($dt instanceof DateTime) {
        return $dt->format('Y-m-d\TH:i:s\Z');
    }
    return $dt;
}
