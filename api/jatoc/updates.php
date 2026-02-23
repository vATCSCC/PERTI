<?php
/**
 * JATOC Incident Updates API - GET/POST with pagination
 * GET: Public access with pagination
 * POST: Requires VATSIM auth
 */
header('Content-Type: application/json');

include("../../sessions/handler.php");
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
include("../../load/config.php");
define('PERTI_ADL_ONLY', true);
include("../../load/connect.php");

// Include JATOC utilities
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/datetime.php';
require_once __DIR__ . '/validators.php';
require_once __DIR__ . '/auth.php';

JatocAuth::setConnection($conn_adl);

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// POST requires authentication
if ($method === 'POST') {
    JatocAuth::requireAuth();
}

try {
    if ($method === 'GET') {
        $incidentId = $_GET['incident_id'] ?? null;
        if (!$incidentId) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Missing incident_id']);
            return;
        }

        // Pagination parameters
        $limit = isset($_GET['limit']) ? min(100, max(1, (int)$_GET['limit'])) : 50;
        $offset = isset($_GET['offset']) ? max(0, (int)$_GET['offset']) : 0;

        // Count total
        $countSql = "SELECT COUNT(*) as total FROM jatoc_incident_updates WHERE incident_id = ?";
        $countStmt = sqlsrv_query($conn_adl, $countSql, [$incidentId]);
        if ($countStmt === false) throw new Exception('Count query failed');
        $totalRow = sqlsrv_fetch_array($countStmt, SQLSRV_FETCH_ASSOC);
        $total = (int)$totalRow['total'];
        sqlsrv_free_stmt($countStmt);

        // Paginated query
        $sql = "SELECT * FROM jatoc_incident_updates WHERE incident_id = ?
                ORDER BY created_utc DESC
                OFFSET ? ROWS FETCH NEXT ? ROWS ONLY";

        $stmt = sqlsrv_query($conn_adl, $sql, [$incidentId, $offset, $limit]);
        if ($stmt === false) throw new Exception('Query failed');

        $updates = [];
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            if (isset($row['created_utc']) && $row['created_utc'] instanceof DateTime) {
                $row['created_utc'] = $row['created_utc']->format('Y-m-d H:i:s');
            }
            $updates[] = $row;
        }
        sqlsrv_free_stmt($stmt);

        echo json_encode([
            'success' => true,
            'data' => $updates,
            'pagination' => [
                'total' => $total,
                'limit' => $limit,
                'offset' => $offset,
                'page' => floor($offset / $limit) + 1,
                'pages' => $limit > 0 ? ceil($total / $limit) : 1,
                'has_next' => ($offset + $limit) < $total,
                'has_prev' => $offset > 0
            ]
        ]);

    } elseif ($method === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);

        // Validate input
        $errors = JatocValidators::updateCreate($input);
        if ($errors) {
            http_response_code(400);
            echo json_encode(['success' => false, 'errors' => $errors]);
            exit;
        }

        // Verify incident exists
        $checkStmt = sqlsrv_query($conn_adl, "SELECT id FROM jatoc_incidents WHERE id = ?", [$input['incident_id']]);
        if (!sqlsrv_fetch_array($checkStmt, SQLSRV_FETCH_ASSOC)) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Incident not found']);
            exit;
        }

        // Validate update_type if provided
        $updateType = $input['update_type'] ?? 'REMARK';
        if (!array_key_exists($updateType, JATOC_UPDATE_TYPES)) {
            $updateType = 'REMARK';
        }

        $sql = "INSERT INTO jatoc_incident_updates (incident_id, update_type, remarks, created_by) VALUES (?, ?, ?, ?)";
        $params = [
            $input['incident_id'],
            $updateType,
            $input['remarks'] ?? null,
            $input['created_by'] ?? JatocAuth::getLogIdentifier()
        ];

        $stmt = sqlsrv_query($conn_adl, $sql, $params);
        if ($stmt === false) throw new Exception('Insert failed');

        // Update parent incident's update_utc
        sqlsrv_query($conn_adl, "UPDATE jatoc_incidents SET update_utc = SYSUTCDATETIME() WHERE id = ?", [$input['incident_id']]);

        // Sync updates to report if exists
        syncReportUpdates($conn_adl, $input['incident_id']);

        echo json_encode(['success' => true]);

    } else {
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

function syncReportUpdates($conn, $incidentId) {
    // Check if report exists
    $check = sqlsrv_query($conn, "SELECT id FROM jatoc_reports WHERE incident_id = ?", [$incidentId]);
    if (!sqlsrv_fetch_array($check, SQLSRV_FETCH_ASSOC)) return;

    // Get all updates
    $updStmt = sqlsrv_query($conn, "SELECT * FROM jatoc_incident_updates WHERE incident_id = ? ORDER BY created_utc ASC", [$incidentId]);
    $updates = [];
    while ($row = sqlsrv_fetch_array($updStmt, SQLSRV_FETCH_ASSOC)) {
        $ts = $row['created_utc'];
        if ($ts instanceof DateTime) $ts = $ts->format('Y-m-d H:i:s') . 'Z';
        $updates[] = ['id' => $row['id'], 'type' => $row['update_type'], 'remarks' => $row['remarks'], 'created_by' => $row['created_by'], 'timestamp' => $ts];
    }

    sqlsrv_query($conn, "UPDATE jatoc_reports SET updates_json = ?, updated_at = SYSUTCDATETIME() WHERE incident_id = ?",
        [json_encode($updates), $incidentId]);
}
