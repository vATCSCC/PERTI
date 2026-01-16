<?php
/**
 * JATOC Incidents API - GET list / POST create
 * GET: Public access with pagination and multi-filter support
 * POST: Requires VATSIM auth with role-based permission
 */
header('Content-Type: application/json');

include("../../sessions/handler.php");
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
include("../../load/config.php");
include("../../load/connect.php");

// Include JATOC utilities
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/datetime.php';
require_once __DIR__ . '/validators.php';
require_once __DIR__ . '/auth.php';

JatocAuth::setConnection($conn_adl);

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// POST requires authentication and create permission
if ($method === 'POST') {
    JatocAuth::requirePermission('create');
}

try {
    if ($method === 'GET') {
        // Public access for viewing with filters and pagination
        $where = ['1=1'];
        $params = [];

        // Lifecycle status filter (supports both old and new column names)
        if (!empty($_GET['status']) || !empty($_GET['lifecycle_status'])) {
            $statusVal = $_GET['lifecycle_status'] ?? $_GET['status'];
            // Try new column first, fallback to old
            $where[] = '(lifecycle_status = ? OR incident_status = ?)';
            $params[] = $statusVal;
            $params[] = $statusVal;
        }

        // Facility type filter
        if (!empty($_GET['facilityType']) || !empty($_GET['facility_type'])) {
            $where[] = 'facility_type = ?';
            $params[] = $_GET['facility_type'] ?? $_GET['facilityType'];
        }

        // Incident type filter (supports both old and new column names)
        if (!empty($_GET['incidentType']) || !empty($_GET['incident_type'])) {
            $typeVal = $_GET['incident_type'] ?? $_GET['incidentType'];
            // Try new column first, fallback to old
            $where[] = '(incident_type = ? OR status = ?)';
            $params[] = $typeVal;
            $params[] = $typeVal;
        }

        // Single facility filter (partial match)
        if (!empty($_GET['facility'])) {
            $where[] = 'facility LIKE ?';
            $params[] = '%' . $_GET['facility'] . '%';
        }

        // Multi-facility filter (exact match with IN clause)
        if (!empty($_GET['facilities'])) {
            $facilities = is_array($_GET['facilities'])
                ? $_GET['facilities']
                : explode(',', $_GET['facilities']);
            $facilities = array_map(function($f) { return strtoupper(trim($f)); }, $facilities);
            $placeholders = implode(',', array_fill(0, count($facilities), '?'));
            $where[] = "facility IN ($placeholders)";
            $params = array_merge($params, $facilities);
        }

        // Multi-facility-type filter
        if (!empty($_GET['facility_types'])) {
            $types = is_array($_GET['facility_types'])
                ? $_GET['facility_types']
                : explode(',', $_GET['facility_types']);
            $placeholders = implode(',', array_fill(0, count($types), '?'));
            $where[] = "facility_type IN ($placeholders)";
            $params = array_merge($params, $types);
        }

        // Search by incident number
        if (!empty($_GET['incident_number'])) {
            $where[] = 'incident_number LIKE ?';
            $params[] = '%' . $_GET['incident_number'] . '%';
        }

        // Search by report number
        if (!empty($_GET['report_number'])) {
            $where[] = 'report_number LIKE ?';
            $params[] = '%' . $_GET['report_number'] . '%';
        }

        // Date range filters
        if (!empty($_GET['from_date'])) {
            $where[] = 'start_utc >= ?';
            $params[] = $_GET['from_date'] . ' 00:00:00';
        }
        if (!empty($_GET['to_date'])) {
            $where[] = 'start_utc <= ?';
            $params[] = $_GET['to_date'] . ' 23:59:59';
        }

        // Pagination parameters
        $limit = isset($_GET['limit']) ? min(100, max(1, (int)$_GET['limit'])) : 50;
        $offset = isset($_GET['offset']) ? max(0, (int)$_GET['offset']) : 0;

        // Sorting
        $allowedSort = ['start_utc', 'facility', 'status', 'incident_status', 'incident_type', 'lifecycle_status', 'created_at'];
        $sort = in_array($_GET['sort'] ?? '', $allowedSort) ? $_GET['sort'] : 'start_utc';
        $order = strtoupper($_GET['order'] ?? 'DESC') === 'ASC' ? 'ASC' : 'DESC';

        $whereClause = implode(' AND ', $where);

        // Count total matching records
        $countSql = "SELECT COUNT(*) as total FROM jatoc_incidents WHERE $whereClause";
        $countStmt = sqlsrv_query($conn_adl, $countSql, $params);
        if ($countStmt === false) throw new Exception('Count query failed');
        $totalRow = sqlsrv_fetch_array($countStmt, SQLSRV_FETCH_ASSOC);
        $total = (int)$totalRow['total'];
        sqlsrv_free_stmt($countStmt);

        // Paginated query with OFFSET...FETCH (SQL Server 2012+)
        $sql = "SELECT * FROM jatoc_incidents WHERE $whereClause
                ORDER BY $sort $order
                OFFSET ? ROWS FETCH NEXT ? ROWS ONLY";
        $paginatedParams = array_merge($params, [$offset, $limit]);

        $stmt = sqlsrv_query($conn_adl, $sql, $paginatedParams);
        if ($stmt === false) throw new Exception('Query failed');

        $incidents = [];
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            // Format datetime fields
            foreach (['start_utc', 'update_utc', 'closeout_utc', 'created_at', 'updated_at'] as $field) {
                if (isset($row[$field]) && $row[$field] instanceof DateTime) {
                    $row[$field] = $row[$field]->format('Y-m-d H:i:s');
                }
            }

            // Add new column names for forward compatibility
            if (!isset($row['incident_type']) && isset($row['status'])) {
                $row['incident_type'] = $row['status'];
            }
            if (!isset($row['lifecycle_status']) && isset($row['incident_status'])) {
                $row['lifecycle_status'] = $row['incident_status'];
            }

            $incidents[] = $row;
        }
        sqlsrv_free_stmt($stmt);

        // Build response with pagination metadata
        echo json_encode([
            'success' => true,
            'data' => $incidents,
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
        $errors = JatocValidators::incidentCreate($input);
        if ($errors) {
            http_response_code(400);
            echo json_encode(['success' => false, 'errors' => $errors]);
            exit;
        }

        // Get trigger description from centralized config
        $triggerCode = $input['trigger_code'] ?? null;
        $triggerDesc = $triggerCode ? (JATOC_TRIGGERS[$triggerCode] ?? null) : null;

        // Parse start time
        $startUtc = JatocDateTime::toSqlServer($input['start_utc']);

        // Generate incident number
        $incidentNumber = null;
        $incNumStmt = sqlsrv_query($conn_adl, "DECLARE @num VARCHAR(12); EXEC sp_jatoc_next_incident_number @num OUTPUT; SELECT @num AS incident_number;");
        if ($incNumStmt) {
            $row = sqlsrv_fetch_array($incNumStmt, SQLSRV_FETCH_ASSOC);
            $incidentNumber = $row['incident_number'] ?? null;
            sqlsrv_free_stmt($incNumStmt);
        }

        // Get incident type (support both old and new field names)
        $incidentType = $input['incident_type'] ?? $input['status'] ?? null;
        $lifecycleStatus = $input['lifecycle_status'] ?? $input['incident_status'] ?? 'ACTIVE';

        // Insert with both old and new column names for transition
        $sql = "INSERT INTO jatoc_incidents
                (incident_number, facility, facility_type, status, incident_type, trigger_code, trigger_desc,
                 paged, start_utc, remarks, created_by, incident_status, lifecycle_status)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

        $params = [
            $incidentNumber,
            strtoupper($input['facility']),
            $input['facility_type'] ?? null,
            $incidentType,           // Old column
            $incidentType,           // New column
            $triggerCode,
            $triggerDesc,
            ($input['paged'] ?? false) ? 1 : 0,
            $startUtc,
            $input['remarks'] ?? null,
            $input['created_by'] ?? JatocAuth::getLogIdentifier(),
            $lifecycleStatus,        // Old column
            $lifecycleStatus         // New column
        ];

        $stmt = sqlsrv_query($conn_adl, $sql, $params);
        if ($stmt === false) {
            $errors = sqlsrv_errors();
            throw new Exception('Insert failed: ' . ($errors[0]['message'] ?? 'Unknown error'));
        }

        $idResult = sqlsrv_query($conn_adl, "SELECT SCOPE_IDENTITY() as id");
        $newId = sqlsrv_fetch_array($idResult, SQLSRV_FETCH_ASSOC)['id'];

        echo json_encode(['success' => true, 'id' => $newId, 'incident_number' => $incidentNumber]);

    } else {
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
