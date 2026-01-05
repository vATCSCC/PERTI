<?php
/**
 * api/tmi/rr_flight_search.php
 * 
 * GET - Search ADL for flights by callsign or flight_key (Azure SQL)
 * 
 * Query params:
 *   q - Search query (callsign or flight_key)
 *   limit - Max results (default 20)
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../../load/connect.php';

try {
    if (!isset($_GET['q']) || strlen(trim($_GET['q'])) < 2) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Query must be at least 2 characters']);
        exit;
    }
    
    $query = strtoupper(trim($_GET['q']));
    $limit = isset($_GET['limit']) ? min(50, intval($_GET['limit'])) : 20;
    
    $sql = "SELECT TOP (?) 
                flight_key, callsign, 
                fp_dept_icao, fp_dest_icao, 
                fp_dept_artcc, fp_dest_artcc,
                ac_cat, aircraft_type, major_carrier,
                fp_altitude_ft, fp_route,
                etd_runway_utc, eta_runway_utc,
                ete_minutes, gcd_nm,
                lat, lon, altitude_ft,
                phase
            FROM dbo.adl_flights
            WHERE is_active = 1 
              AND (callsign LIKE ? OR flight_key LIKE ?)
            ORDER BY callsign ASC";
    
    $searchPattern = $query . '%';
    $params = [$limit, $searchPattern, $searchPattern];
    
    $stmt = sqlsrv_query($conn_adl, $sql, $params);
    
    if ($stmt === false) {
        throw new Exception('Query failed: ' . print_r(sqlsrv_errors(), true));
    }
    
    $flights = [];
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        // Convert DateTime objects
        foreach (['etd_runway_utc', 'eta_runway_utc'] as $field) {
            if (isset($row[$field]) && $row[$field] instanceof DateTime) {
                $row[$field] = $row[$field]->format('Y-m-d H:i:s');
            }
        }
        $flights[] = $row;
    }
    sqlsrv_free_stmt($stmt);
    
    echo json_encode([
        'status' => 'ok',
        'query' => $query,
        'count' => count($flights),
        'flights' => $flights
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
