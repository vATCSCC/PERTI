<?php
/**
 * api/mgt/tmi/reroutes/post.php
 *
 * POST - Create or update a reroute definition (Azure SQL)
 *
 * If 'id' is provided in POST data, updates existing record.
 * Otherwise, creates a new record.
 *
 * Supports 'routes' array for individual origin/destination route pairs:
 * routes: [
 *   { "origin": "JFK", "dest": "PIT", "route": "DEEZZ5 >CANDR J60 PSB< HAYNZ6" },
 *   { "origin": "EWR LGA", "dest": "PIT", "route": ">NEWEL J60 PSB< HAYNZ6" }
 * ]
 *
 * @version 2.0.0
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../../../../load/connect.php';
require_once __DIR__ . '/../../../../sessions/handler.php';
require_once __DIR__ . '/../../../../load/coordination_log.php';

// Permission check
if (!isset($_SESSION['VATSIM_CID']) && !defined('DEV')) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Not authenticated']);
    exit;
}

try {
    // Fields that can be set
    $fields = [
        'status', 'name', 'adv_number',
        'start_utc', 'end_utc', 'time_basis',
        'protected_segment', 'protected_fixes', 'avoid_fixes', 'route_type',
        'origin_airports', 'origin_tracons', 'origin_centers',
        'dest_airports', 'dest_tracons', 'dest_centers',
        'departure_fix', 'arrival_fix', 'thru_centers', 'thru_fixes', 'use_airway',
        'include_ac_cat', 'include_ac_types', 'include_carriers',
        'weight_class', 'altitude_min', 'altitude_max',
        'rvsm_filter',
        'exempt_airports', 'exempt_carriers', 'exempt_flights', 'exempt_active_only',
        'airborne_filter',
        'comments', 'impacting_condition', 'advisory_text'
    ];
    
    // Integer fields
    $intFields = ['status', 'altitude_min', 'altitude_max', 'exempt_active_only'];
    
    // Determine if update or insert
    $isUpdate = isset($_POST['id']) && is_numeric($_POST['id']) && post_int('id') > 0;
    
    if ($isUpdate) {
        // UPDATE existing reroute
        $id = post_int('id');
        
        // Check exists
        $checkSql = "SELECT id, status FROM dbo.tmi_reroutes WHERE id = ?";
        $checkStmt = sqlsrv_query($conn_adl, $checkSql, [$id]);
        
        if ($checkStmt === false) {
            throw new Exception('Query failed: ' . print_r(sqlsrv_errors(), true));
        }
        
        $existing = sqlsrv_fetch_array($checkStmt, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($checkStmt);
        
        if (!$existing) {
            http_response_code(404);
            echo json_encode(['status' => 'error', 'message' => 'Reroute not found']);
            exit;
        }
        
        // Build UPDATE query
        $setClauses = [];
        $params = [];
        
        foreach ($fields as $field) {
            if (isset($_POST[$field])) {
                $value = $_POST[$field];
                
                // Handle empty strings as NULL
                if ($value === '') {
                    $setClauses[] = "$field = NULL";
                } else {
                    $setClauses[] = "$field = ?";
                    // Type handling
                    if (in_array($field, $intFields)) {
                        $params[] = intval($value);
                    } else {
                        $params[] = $value;
                    }
                }
            }
        }
        
        if (empty($setClauses)) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'No fields to update']);
            exit;
        }
        
        // Always update updated_utc
        $setClauses[] = "updated_utc = GETUTCDATE()";
        
        // Track activation time if status changed to active
        $newStatus = isset($_POST['status']) ? post_int('status') : $existing['status'];
        if ($newStatus == 2 && $existing['status'] != 2) {
            $setClauses[] = "activated_utc = GETUTCDATE()";
        }
        
        $sql = "UPDATE dbo.tmi_reroutes SET " . implode(', ', $setClauses) . " WHERE id = ?";
        $params[] = $id;
        
        $stmt = sqlsrv_query($conn_adl, $sql, $params);
        
        if ($stmt === false) {
            throw new Exception('Update failed: ' . print_r(sqlsrv_errors(), true));
        }
        
        $rowsAffected = sqlsrv_rows_affected($stmt);
        sqlsrv_free_stmt($stmt);

        // Handle routes array if provided
        $routesUpdated = 0;
        if (isset($_POST['routes'])) {
            $routesUpdated = saveRerouteRoutes($conn_tmi ?? $conn_adl, $id, $_POST['routes']);
        }

        echo json_encode([
            'status' => 'ok',
            'action' => 'updated',
            'id' => $id,
            'affected_rows' => $rowsAffected,
            'routes_updated' => $routesUpdated
        ]);

    } else {
        // INSERT new reroute
        
        // Validate required fields
        if (!isset($_POST['name']) || trim($_POST['name']) === '') {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'Name is required']);
            exit;
        }
        
        $insertFields = ['created_by', 'created_utc', 'updated_utc'];
        $insertValues = ['?', 'GETUTCDATE()', 'GETUTCDATE()'];
        $params = [$_SESSION['VATSIM_CID'] ?? 0];
        
        foreach ($fields as $field) {
            if (isset($_POST[$field]) && $_POST[$field] !== '') {
                $insertFields[] = $field;
                $insertValues[] = '?';
                $value = $_POST[$field];
                
                // Type handling
                if (in_array($field, $intFields)) {
                    $params[] = intval($value);
                } else {
                    $params[] = $value;
                }
            }
        }
        
        $sql = "INSERT INTO dbo.tmi_reroutes (" . implode(', ', $insertFields) . ") 
                VALUES (" . implode(', ', $insertValues) . ");
                SELECT SCOPE_IDENTITY() AS id;";
        
        $stmt = sqlsrv_query($conn_adl, $sql, $params);
        
        if ($stmt === false) {
            throw new Exception('Insert failed: ' . print_r(sqlsrv_errors(), true));
        }
        
        // Get the inserted ID
        sqlsrv_next_result($stmt);
        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        $newId = $row['id'] ?? null;
        sqlsrv_free_stmt($stmt);

        // Handle routes array if provided
        $routesSaved = 0;
        if ($newId && isset($_POST['routes'])) {
            $routesSaved = saveRerouteRoutes($conn_tmi ?? $conn_adl, $newId, $_POST['routes']);
        }

        // Log to coordination channel
        if ($newId) {
            try {
                logToCoordinationChannel(null, null, 'REROUTE_CREATED', [
                    'reroute_id' => $newId,
                    'route_name' => $_POST['name'] ?? '',
                    'user_cid' => $_SESSION['VATSIM_CID'] ?? null,
                    'user_name' => ($_SESSION['VATSIM_FIRST_NAME'] ?? '') . ' ' . ($_SESSION['VATSIM_LAST_NAME'] ?? '')
                ]);
            } catch (Exception $logEx) {
                // Don't fail the request if logging fails
            }
        }

        echo json_encode([
            'status' => 'ok',
            'action' => 'created',
            'id' => $newId,
            'routes_saved' => $routesSaved
        ]);
    }

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage()
    ]);
}

/**
 * Save individual routes to tmi_reroute_routes table
 *
 * @param resource $conn Database connection
 * @param int $rerouteId The parent reroute ID
 * @param mixed $routes Routes array (can be JSON string or array)
 * @return int Number of routes saved
 */
function saveRerouteRoutes($conn, $rerouteId, $routes) {
    if (empty($routes) || empty($rerouteId)) {
        return 0;
    }

    // Parse routes if JSON string
    if (is_string($routes)) {
        $routes = json_decode($routes, true);
        if ($routes === null) {
            return 0;
        }
    }

    if (!is_array($routes)) {
        return 0;
    }

    // Delete existing routes for this reroute
    $deleteSql = "DELETE FROM dbo.tmi_reroute_routes WHERE reroute_id = ?";
    $deleteStmt = sqlsrv_query($conn, $deleteSql, [$rerouteId]);
    if ($deleteStmt !== false) {
        sqlsrv_free_stmt($deleteStmt);
    }

    // Insert new routes
    $insertSql = "
        INSERT INTO dbo.tmi_reroute_routes (reroute_id, origin, destination, route_string, sort_order)
        VALUES (?, ?, ?, ?, ?)
    ";

    $count = 0;
    $sortOrder = 0;

    foreach ($routes as $route) {
        $origin = strtoupper(trim($route['origin'] ?? $route['orig'] ?? ''));
        $dest = strtoupper(trim($route['dest'] ?? $route['destination'] ?? ''));
        $routeString = trim($route['route'] ?? $route['route_string'] ?? '');

        if (empty($origin) || empty($dest) || empty($routeString)) {
            continue;
        }

        $params = [
            $rerouteId,
            $origin,
            $dest,
            $routeString,
            $sortOrder++
        ];

        $stmt = sqlsrv_query($conn, $insertSql, $params);
        if ($stmt !== false) {
            $count++;
            sqlsrv_free_stmt($stmt);
        }
    }

    return $count;
}
