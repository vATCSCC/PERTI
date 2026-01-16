<?php
/**
 * Planned Crossings Forecast API
 * Returns boundary workload forecast data
 *
 * Endpoints:
 *   GET ?type=workload&boundary=ZDC         - Workload for specific boundary
 *   GET ?type=hot                           - Hottest boundaries in next hour
 *   GET ?type=artcc_summary                 - ARTCC workload summary
 *   GET ?type=sector_demand                 - Sector demand forecast
 *   GET ?type=flight&flight_uid=123         - All crossings for a flight
 *   GET ?type=boundary_flights&boundary=ZDC - Flights crossing a boundary
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

// Session Start
if (session_status() == PHP_SESSION_NONE) {
    session_start();
    ob_start();
}

include("../../../load/config.php");
include("../../../load/input.php");

// Use ADL database connection
try {
    $dsn = "sqlsrv:Server=" . ADL_SQL_HOST . ";Database=" . ADL_SQL_DATABASE;
    $pdo = new PDO($dsn, ADL_SQL_USERNAME, ADL_SQL_PASSWORD);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed']);
    exit;
}

$type = isset($_GET['type']) ? $_GET['type'] : 'workload';
$boundary = isset($_GET['boundary']) ? strtoupper($_GET['boundary']) : null;
$flight_uid = isset($_GET['flight_uid']) ? get_int('flight_uid') : null;
$hours = isset($_GET['hours']) ? min(get_int('hours'), 12) : 2;
$limit = isset($_GET['limit']) ? min(get_int('limit'), 500) : 100;

try {
    switch ($type) {
        // ================================================================
        // Workload forecast for a specific boundary
        // ================================================================
        case 'workload':
            if (!$boundary) {
                http_response_code(400);
                echo json_encode(['error' => 'boundary parameter required']);
                exit;
            }

            $sql = "
                SELECT
                    boundary_code,
                    boundary_type,
                    time_bucket,
                    expected_entries,
                    unique_flights
                FROM vw_boundary_workload_forecast
                WHERE boundary_code = :boundary
                  AND time_bucket >= GETUTCDATE()
                  AND time_bucket < DATEADD(HOUR, :hours, GETUTCDATE())
                ORDER BY time_bucket
            ";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([':boundary' => $boundary, ':hours' => $hours]);
            $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
            break;

        // ================================================================
        // Hottest boundaries in next hour
        // ================================================================
        case 'hot':
            $sql = "SELECT * FROM vw_hot_boundaries";
            $stmt = $pdo->query($sql);
            $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
            break;

        // ================================================================
        // ARTCC workload summary
        // ================================================================
        case 'artcc_summary':
            $sql = "
                SELECT
                    artcc,
                    hour_bucket,
                    expected_entries,
                    unique_flights,
                    first_entry,
                    last_entry
                FROM vw_artcc_workload_summary
                ORDER BY hour_bucket, expected_entries DESC
            ";
            $stmt = $pdo->query($sql);
            $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
            break;

        // ================================================================
        // Sector demand forecast
        // ================================================================
        case 'sector_demand':
            $sql = "
                SELECT
                    sector,
                    sector_type,
                    time_bucket_30min,
                    expected_transits,
                    unique_flights
                FROM vw_sector_demand_forecast
                ORDER BY time_bucket_30min, expected_transits DESC
            ";
            $stmt = $pdo->query($sql);
            $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
            break;

        // ================================================================
        // All crossings for a specific flight
        // ================================================================
        case 'flight':
            if (!$flight_uid) {
                http_response_code(400);
                echo json_encode(['error' => 'flight_uid parameter required']);
                exit;
            }

            $sql = "
                SELECT
                    callsign,
                    dept,
                    dest,
                    crossing_order,
                    boundary_type,
                    boundary_code,
                    crossing_type,
                    entry_fix_name,
                    exit_fix_name,
                    planned_entry_utc,
                    planned_exit_utc,
                    transit_minutes,
                    entry_lat,
                    entry_lon
                FROM vw_flight_route_crossings
                WHERE flight_uid = :flight_uid
                ORDER BY crossing_order
            ";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([':flight_uid' => $flight_uid]);
            $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
            break;

        // ================================================================
        // Flights crossing a specific boundary
        // ================================================================
        case 'boundary_flights':
            if (!$boundary) {
                http_response_code(400);
                echo json_encode(['error' => 'boundary parameter required']);
                exit;
            }

            $sql = "
                SELECT TOP (:limit)
                    boundary_code,
                    boundary_type,
                    flight_uid,
                    callsign,
                    dept,
                    dest,
                    aircraft_type,
                    crossing_order,
                    entry_fix_name,
                    exit_fix_name,
                    planned_entry_utc,
                    planned_exit_utc,
                    transit_minutes,
                    minutes_until_entry,
                    entry_lat,
                    entry_lon
                FROM vw_flights_crossing_boundary
                WHERE boundary_code = :boundary
                  AND minutes_until_entry >= 0
                  AND minutes_until_entry <= :minutes
                ORDER BY planned_entry_utc
            ";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':boundary' => $boundary,
                ':limit' => $limit,
                ':minutes' => $hours * 60
            ]);
            $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
            break;

        // ================================================================
        // Statistics
        // ================================================================
        case 'stats':
            $sql = "SELECT * FROM vw_crossing_statistics";
            $stmt = $pdo->query($sql);
            $data = $stmt->fetch(PDO::FETCH_ASSOC);
            break;

        default:
            http_response_code(400);
            echo json_encode(['error' => 'Invalid type parameter']);
            exit;
    }

    echo json_encode([
        'success' => true,
        'type' => $type,
        'generated_at' => gmdate('Y-m-d\TH:i:s\Z'),
        'count' => is_array($data) ? count($data) : 1,
        'data' => $data
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'error' => 'Query failed',
        'message' => $e->getMessage()
    ]);
}
?>
