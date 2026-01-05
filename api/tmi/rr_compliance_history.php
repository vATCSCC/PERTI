<?php
/**
 * api/tmi/rr_compliance_history.php
 * 
 * GET - Get compliance history for a specific flight (Azure SQL)
 * 
 * Query params:
 *   flight_id - ID from tmi_reroute_flights (required)
 *   limit     - Max records (default 100)
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../../load/connect.php';

try {
    if (!isset($_GET['flight_id']) || !is_numeric($_GET['flight_id'])) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Missing flight_id parameter']);
        exit;
    }
    
    $flightId = intval($_GET['flight_id']);
    $limit = isset($_GET['limit']) ? min(500, intval($_GET['limit'])) : 100;
    
    // Get flight info
    $flightSql = "SELECT f.*, r.name as reroute_name, r.protected_fixes, r.avoid_fixes
                  FROM dbo.tmi_reroute_flights f
                  JOIN dbo.tmi_reroutes r ON f.reroute_id = r.id
                  WHERE f.id = ?";
    $flightStmt = sqlsrv_query($conn_adl, $flightSql, [$flightId]);
    $flight = sqlsrv_fetch_array($flightStmt, SQLSRV_FETCH_ASSOC);
    sqlsrv_free_stmt($flightStmt);
    
    if (!$flight) {
        http_response_code(404);
        echo json_encode(['status' => 'error', 'message' => 'Flight not found']);
        exit;
    }
    
    // Convert DateTime objects
    foreach (['assigned_utc', 'departed_utc', 'arrived_utc', 'last_position_utc', 'override_utc', 'current_route_utc'] as $field) {
        if (isset($flight[$field]) && $flight[$field] instanceof DateTime) {
            $flight[$field] = $flight[$field]->format('Y-m-d H:i:s');
        }
    }
    
    // Get compliance history
    $historySql = "SELECT 
            id, snapshot_utc, compliance_status, compliance_pct,
            lat, lon, altitude, route_string, fixes_crossed
        FROM dbo.tmi_reroute_compliance_log
        WHERE reroute_flight_id = ?
        ORDER BY snapshot_utc DESC
        OFFSET 0 ROWS FETCH NEXT ? ROWS ONLY";
    
    $historyStmt = sqlsrv_query($conn_adl, $historySql, [$flightId, $limit]);
    
    $history = [];
    if ($historyStmt) {
        while ($row = sqlsrv_fetch_array($historyStmt, SQLSRV_FETCH_ASSOC)) {
            if (isset($row['snapshot_utc']) && $row['snapshot_utc'] instanceof DateTime) {
                $row['snapshot_utc'] = $row['snapshot_utc']->format('Y-m-d H:i:s');
            }
            $history[] = $row;
        }
        sqlsrv_free_stmt($historyStmt);
    }
    
    // Build position track for map
    $track = array_filter(array_map(function($h) {
        if ($h['lat'] && $h['lon']) {
            return [
                'lat' => floatval($h['lat']),
                'lon' => floatval($h['lon']),
                'alt' => $h['altitude'],
                'time' => $h['snapshot_utc'],
                'status' => $h['compliance_status']
            ];
        }
        return null;
    }, array_reverse($history)));
    
    echo json_encode([
        'status' => 'ok',
        'flight' => $flight,
        'history' => $history,
        'track' => array_values($track)
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
