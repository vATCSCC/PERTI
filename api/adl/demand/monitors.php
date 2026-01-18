<?php
/**
 * api/adl/demand/monitors.php
 *
 * Global Demand Monitors API - CRUD operations for shared demand monitors
 *
 * Methods:
 *   GET     - List all active monitors
 *   POST    - Create a new monitor
 *   DELETE  - Remove a monitor (by id or monitor_key)
 *
 * GET Response:
 *   {
 *     "monitors": [
 *       {
 *         "id": 1,
 *         "key": "fix_MERIT",
 *         "type": "fix",
 *         "definition": {...},
 *         "label": "MERIT",
 *         "created_by": "user@example.com",
 *         "created_utc": "2026-01-15T14:30:00Z"
 *       }
 *     ]
 *   }
 *
 * POST Body (JSON):
 *   {
 *     "type": "fix",
 *     "definition": {"fix": "MERIT"},
 *     "label": "MERIT",
 *     "created_by": "user@example.com"
 *   }
 *
 * DELETE Parameters:
 *   id           - Monitor ID to delete
 *   monitor_key  - Or monitor key to delete
 */

if (session_status() == PHP_SESSION_NONE) {
    session_start();
    ob_start();
}

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once(__DIR__ . '/../../../load/config.php');

// Validate config
if (!defined("ADL_SQL_HOST") || !defined("ADL_SQL_DATABASE") ||
    !defined("ADL_SQL_USERNAME") || !defined("ADL_SQL_PASSWORD")) {
    http_response_code(500);
    echo json_encode(["error" => "ADL_SQL_* constants are not defined."]);
    exit;
}

if (!function_exists('sqlsrv_connect')) {
    http_response_code(500);
    echo json_encode(["error" => "The sqlsrv extension is not available."]);
    exit;
}

function sql_error_msg() {
    $errs = sqlsrv_errors(SQLSRV_ERR_ERRORS);
    if (!$errs) return "";
    $msgs = [];
    foreach ($errs as $e) {
        $msgs[] = ($e['SQLSTATE'] ?? '') . " " . ($e['code'] ?? '') . " " . trim($e['message'] ?? '');
    }
    return implode(" | ", $msgs);
}

// Connect to database
$connectionInfo = [
    "Database" => ADL_SQL_DATABASE,
    "UID" => ADL_SQL_USERNAME,
    "PWD" => ADL_SQL_PASSWORD
];

$conn = sqlsrv_connect(ADL_SQL_HOST, $connectionInfo);
if ($conn === false) {
    http_response_code(500);
    echo json_encode(["error" => "Unable to connect to ADL database.", "sql_error" => sql_error_msg()]);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

switch ($method) {
    case 'GET':
        // List all active monitors
        $sql = "SELECT monitor_id, monitor_key, monitor_type, definition, display_label, created_by, created_utc
                FROM dbo.demand_monitors
                WHERE is_active = 1
                ORDER BY created_utc DESC";
        $stmt = sqlsrv_query($conn, $sql);

        if ($stmt === false) {
            // Check if it's a table-not-exists error - return empty list gracefully
            $errMsg = sql_error_msg();
            if (strpos($errMsg, 'Invalid object name') !== false ||
                strpos($errMsg, 'does not exist') !== false) {
                // Table doesn't exist yet - return empty list
                echo json_encode(["monitors" => []], JSON_PRETTY_PRINT);
                exit;
            }
            http_response_code(500);
            echo json_encode(["error" => "Query failed", "sql_error" => $errMsg]);
            exit;
        }

        $monitors = [];
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $monitors[] = [
                "id" => (int)$row['monitor_id'],
                "key" => $row['monitor_key'],
                "type" => $row['monitor_type'],
                "definition" => json_decode($row['definition'], true),
                "label" => $row['display_label'],
                "created_by" => $row['created_by'],
                "created_utc" => $row['created_utc'] instanceof DateTime ?
                    $row['created_utc']->format('Y-m-d\TH:i:s\Z') : $row['created_utc']
            ];
        }
        sqlsrv_free_stmt($stmt);

        echo json_encode(["monitors" => $monitors], JSON_PRETTY_PRINT);
        break;

    case 'POST':
        // Create a new monitor
        $input = json_decode(file_get_contents('php://input'), true);

        if (!$input || empty($input['type']) || empty($input['definition'])) {
            http_response_code(400);
            echo json_encode(["error" => "Missing required fields: type, definition"]);
            exit;
        }

        $type = strtolower(trim($input['type']));
        $definition = $input['definition'];
        $label = $input['label'] ?? '';
        $createdBy = $input['created_by'] ?? null;

        // Generate monitor key from definition
        $monitorKey = generateMonitorKey($type, $definition);

        // Check if already exists
        $checkSql = "SELECT monitor_id FROM dbo.demand_monitors WHERE monitor_key = ? AND is_active = 1";
        $checkStmt = sqlsrv_query($conn, $checkSql, [$monitorKey]);
        if ($checkStmt && sqlsrv_fetch_array($checkStmt, SQLSRV_FETCH_ASSOC)) {
            // Already exists, return success with existing monitor
            http_response_code(200);
            echo json_encode(["success" => true, "message" => "Monitor already exists", "key" => $monitorKey]);
            sqlsrv_free_stmt($checkStmt);
            exit;
        }
        if ($checkStmt) sqlsrv_free_stmt($checkStmt);

        // Insert new monitor
        $sql = "INSERT INTO dbo.demand_monitors (monitor_key, monitor_type, definition, display_label, created_by)
                VALUES (?, ?, ?, ?, ?)";
        $params = [
            $monitorKey,
            $type,
            json_encode($definition),
            $label,
            $createdBy
        ];

        $stmt = sqlsrv_query($conn, $sql, $params);
        if ($stmt === false) {
            http_response_code(500);
            echo json_encode(["error" => "Insert failed", "sql_error" => sql_error_msg()]);
            exit;
        }
        sqlsrv_free_stmt($stmt);

        // Get the new monitor ID
        $idStmt = sqlsrv_query($conn, "SELECT SCOPE_IDENTITY() AS id");
        $newId = 0;
        if ($idStmt) {
            $idRow = sqlsrv_fetch_array($idStmt, SQLSRV_FETCH_ASSOC);
            $newId = (int)($idRow['id'] ?? 0);
            sqlsrv_free_stmt($idStmt);
        }

        echo json_encode([
            "success" => true,
            "id" => $newId,
            "key" => $monitorKey,
            "message" => "Monitor created"
        ]);
        break;

    case 'DELETE':
        // Delete a monitor (soft delete by setting is_active = 0)
        $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
        $key = isset($_GET['monitor_key']) ? trim($_GET['monitor_key']) : '';

        if ($id <= 0 && empty($key)) {
            http_response_code(400);
            echo json_encode(["error" => "Missing required parameter: id or monitor_key"]);
            exit;
        }

        if ($id > 0) {
            $sql = "UPDATE dbo.demand_monitors SET is_active = 0 WHERE monitor_id = ?";
            $params = [$id];
        } else {
            $sql = "UPDATE dbo.demand_monitors SET is_active = 0 WHERE monitor_key = ?";
            $params = [$key];
        }

        $stmt = sqlsrv_query($conn, $sql, $params);
        if ($stmt === false) {
            http_response_code(500);
            echo json_encode(["error" => "Delete failed", "sql_error" => sql_error_msg()]);
            exit;
        }

        $rowsAffected = sqlsrv_rows_affected($stmt);
        sqlsrv_free_stmt($stmt);

        echo json_encode([
            "success" => true,
            "rows_affected" => $rowsAffected,
            "message" => $rowsAffected > 0 ? "Monitor deleted" : "Monitor not found"
        ]);
        break;

    default:
        http_response_code(405);
        echo json_encode(["error" => "Method not allowed"]);
}

sqlsrv_close($conn);

/**
 * Generate a unique monitor key from type and definition
 */
function generateMonitorKey($type, $definition) {
    switch ($type) {
        case 'fix':
            return 'fix_' . strtoupper($definition['fix'] ?? '');

        case 'segment':
            return 'segment_' . strtoupper($definition['from'] ?? '') . '_' . strtoupper($definition['to'] ?? '');

        case 'airway':
            return 'airway_' . strtoupper($definition['airway'] ?? '');

        case 'airway_segment':
            return 'airway_' . strtoupper($definition['airway'] ?? '') . '_' .
                   strtoupper($definition['from'] ?? '') . '_' . strtoupper($definition['to'] ?? '');

        case 'via_fix':
            $filter = $definition['filter'] ?? [];
            return 'via_' . strtolower($filter['type'] ?? '') . '_' .
                   strtoupper($filter['code'] ?? '') . '_' .
                   strtolower($filter['direction'] ?? 'both') . '_' .
                   strtoupper($definition['via'] ?? '');

        default:
            return 'unknown_' . md5(json_encode($definition));
    }
}
