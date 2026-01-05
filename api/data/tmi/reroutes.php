<?php
/**
 * api/data/tmi/reroutes.php
 * 
 * GET - List all reroute definitions (Azure SQL)
 * 
 * Query params:
 *   status  - Filter by status (optional, comma-separated for multiple)
 *   active  - If "1", returns only status IN (1,2,3)
 *   limit   - Max results (default 100)
 *   offset  - Pagination offset (default 0)
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../../../load/connect.php';

// Status labels for human-readable output
$STATUS_LABELS = [
    0 => 'Draft',
    1 => 'Proposed',
    2 => 'Active',
    3 => 'Monitoring',
    4 => 'Expired',
    5 => 'Cancelled'
];

try {
    $where = [];
    $params = [];
    
    // Status filter
    if (isset($_GET['status']) && $_GET['status'] !== '') {
        $statuses = array_map('intval', explode(',', $_GET['status']));
        $placeholders = implode(',', $statuses);
        $where[] = "status IN ($placeholders)";
    } elseif (isset($_GET['active']) && $_GET['active'] === '1') {
        $where[] = "status IN (1, 2, 3)";
    }
    
    // Build query
    $sql = "SELECT 
                id, status, name, adv_number,
                start_utc, end_utc, time_basis,
                protected_fixes, avoid_fixes,
                origin_airports, origin_centers,
                dest_airports, dest_centers,
                include_ac_cat, include_carriers,
                impacting_condition,
                created_by, created_utc, updated_utc, activated_utc
            FROM dbo.tmi_reroutes";
    
    if (!empty($where)) {
        $sql .= " WHERE " . implode(' AND ', $where);
    }
    
    $sql .= " ORDER BY 
                CASE WHEN status = 2 THEN 0 
                     WHEN status = 3 THEN 1 
                     WHEN status = 1 THEN 2 
                     ELSE 3 END,
                updated_utc DESC";
    
    // Limit/offset
    $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 100;
    $offset = isset($_GET['offset']) ? intval($_GET['offset']) : 0;
    $sql .= " OFFSET $offset ROWS FETCH NEXT $limit ROWS ONLY";
    
    // Execute
    $stmt = sqlsrv_query($conn_adl, $sql);
    if ($stmt === false) {
        throw new Exception('Query failed: ' . print_r(sqlsrv_errors(), true));
    }
    
    $reroutes = [];
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        // Convert DateTime objects to strings
        foreach (['created_utc', 'updated_utc', 'activated_utc'] as $field) {
            if ($row[$field] instanceof DateTime) {
                $row[$field] = $row[$field]->format('Y-m-d H:i:s');
            }
        }
        $row['status_label'] = $STATUS_LABELS[$row['status']] ?? 'Unknown';
        $reroutes[] = $row;
    }
    sqlsrv_free_stmt($stmt);
    
    // Get counts by status
    $countSql = "SELECT status, COUNT(*) as cnt FROM dbo.tmi_reroutes GROUP BY status";
    $countStmt = sqlsrv_query($conn_adl, $countSql);
    $counts = [];
    if ($countStmt) {
        while ($row = sqlsrv_fetch_array($countStmt, SQLSRV_FETCH_ASSOC)) {
            $counts[$STATUS_LABELS[$row['status']] ?? $row['status']] = intval($row['cnt']);
        }
        sqlsrv_free_stmt($countStmt);
    }
    
    echo json_encode([
        'status' => 'ok',
        'total' => count($reroutes),
        'counts' => $counts,
        'reroutes' => $reroutes
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage()
    ]);
}
