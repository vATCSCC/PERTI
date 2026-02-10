<?php
/**
 * NOD Flow Configs API
 *
 * GET    - List configs for facility, or get single config with elements/gates
 * POST   - Create new config
 * PUT    - Update config
 * DELETE - Delete config
 */

header('Content-Type: application/json');

$config_path = realpath(__DIR__ . '/../../../load/config.php');
$connect_path = realpath(__DIR__ . '/../../../load/connect.php');

if ($config_path) include($config_path);
if ($connect_path) include($connect_path);

$conn = get_conn_adl();
if (!$conn) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection not available']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    switch ($method) {
        case 'GET':
            handleGet($conn);
            break;
        case 'POST':
            handlePost($conn);
            break;
        case 'PUT':
            handlePut($conn);
            break;
        case 'DELETE':
            handleDelete($conn);
            break;
        default:
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}

/**
 * Format a DateTime value from sqlsrv to ISO 8601 string
 */
function formatDateTime($val) {
    if ($val instanceof \DateTime) return $val->format('Y-m-d\TH:i:s\Z');
    return $val;
}

/**
 * Format a config row for JSON output
 */
function formatConfig($row) {
    $dateFields = ['created_at', 'updated_at'];
    foreach ($dateFields as $field) {
        if (isset($row[$field])) {
            $row[$field] = formatDateTime($row[$field]);
        }
    }
    if (isset($row['boundary_layers']) && is_string($row['boundary_layers'])) {
        $row['boundary_layers'] = json_decode($row['boundary_layers'], true);
    }
    return $row;
}

/**
 * Format an element row for JSON output
 */
function formatElement($row) {
    $dateFields = ['created_at', 'updated_at'];
    foreach ($dateFields as $field) {
        if (isset($row[$field])) {
            $row[$field] = formatDateTime($row[$field]);
        }
    }
    if (isset($row['route_geojson']) && is_string($row['route_geojson'])) {
        $row['route_geojson'] = json_decode($row['route_geojson'], true);
    }
    return $row;
}

/**
 * Format a gate row for JSON output
 */
function formatGate($row) {
    $dateFields = ['created_at', 'updated_at'];
    foreach ($dateFields as $field) {
        if (isset($row[$field])) {
            $row[$field] = formatDateTime($row[$field]);
        }
    }
    return $row;
}

/**
 * Format SQL Server errors for display
 */
function formatSqlError($errors) {
    if (!$errors) return 'Unknown database error';
    $messages = [];
    foreach ($errors as $error) {
        $messages[] = $error['message'] ?? $error[2] ?? 'Unknown error';
    }
    return implode('; ', $messages);
}

/**
 * GET - List configs for facility or get single config with nested data
 */
function handleGet($conn) {
    $config_id = isset($_GET['config_id']) ? intval($_GET['config_id']) : null;
    $facility_code = $_GET['facility_code'] ?? null;

    // Single config with nested elements and gates
    if ($config_id) {
        $sql = "SELECT * FROM dbo.facility_flow_configs WHERE config_id = ?";
        $stmt = sqlsrv_query($conn, $sql, [$config_id]);
        if ($stmt === false) {
            throw new Exception(formatSqlError(sqlsrv_errors()));
        }

        $config = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        if (!$config) {
            http_response_code(404);
            echo json_encode(['error' => 'Config not found']);
            return;
        }
        sqlsrv_free_stmt($stmt);

        $config = formatConfig($config);

        // Fetch elements with fix lat/lon for FIX types
        $elemSql = "SELECT e.*, nf.lat AS fix_lat, nf.lon AS fix_lon
                     FROM dbo.facility_flow_elements e
                     LEFT JOIN dbo.nav_fixes nf ON e.element_type = 'FIX' AND e.fix_name = nf.fix_name
                     WHERE e.config_id = ?
                     ORDER BY e.sort_order ASC, e.element_id ASC";
        $elemStmt = sqlsrv_query($conn, $elemSql, [$config_id]);
        if ($elemStmt === false) {
            throw new Exception(formatSqlError(sqlsrv_errors()));
        }

        $elements = [];
        while ($row = sqlsrv_fetch_array($elemStmt, SQLSRV_FETCH_ASSOC)) {
            $elements[] = formatElement($row);
        }
        sqlsrv_free_stmt($elemStmt);

        // Fetch gates
        $gateSql = "SELECT * FROM dbo.facility_flow_gates WHERE config_id = ? ORDER BY sort_order ASC, gate_id ASC";
        $gateStmt = sqlsrv_query($conn, $gateSql, [$config_id]);
        if ($gateStmt === false) {
            throw new Exception(formatSqlError(sqlsrv_errors()));
        }

        $gates = [];
        while ($row = sqlsrv_fetch_array($gateStmt, SQLSRV_FETCH_ASSOC)) {
            $gates[] = formatGate($row);
        }
        sqlsrv_free_stmt($gateStmt);

        $config['elements'] = $elements;
        $config['gates'] = $gates;

        echo json_encode(['config' => $config]);
        return;
    }

    // List configs for facility
    if (!$facility_code) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing required parameter: facility_code or config_id']);
        return;
    }

    $sql = "SELECT config_id, facility_code, facility_type, config_name, is_shared, is_default, created_at, updated_at
            FROM dbo.facility_flow_configs
            WHERE facility_code = ?
            ORDER BY is_default DESC, config_name ASC";
    $stmt = sqlsrv_query($conn, $sql, [$facility_code]);
    if ($stmt === false) {
        throw new Exception(formatSqlError(sqlsrv_errors()));
    }

    $configs = [];
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $configs[] = formatConfig($row);
    }

    echo json_encode(['configs' => $configs]);
}

/**
 * POST - Create new config
 */
function handlePost($conn) {
    $input = json_decode(file_get_contents('php://input'), true);

    if (!$input) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid JSON input']);
        return;
    }

    $required = ['facility_code', 'facility_type', 'config_name'];
    foreach ($required as $field) {
        if (empty($input[$field])) {
            http_response_code(400);
            echo json_encode(['error' => "Missing required field: $field"]);
            return;
        }
    }

    $boundaryLayers = null;
    if (isset($input['boundary_layers'])) {
        $boundaryLayers = is_array($input['boundary_layers'])
            ? json_encode($input['boundary_layers'])
            : $input['boundary_layers'];
    }

    $sql = "INSERT INTO dbo.facility_flow_configs (
                facility_code, facility_type, config_name, is_shared, is_default,
                map_center_lat, map_center_lon, map_zoom, boundary_layers
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?);
            SELECT SCOPE_IDENTITY() AS config_id;";

    $params = [
        $input['facility_code'],
        $input['facility_type'],
        $input['config_name'],
        $input['is_shared'] ?? 0,
        $input['is_default'] ?? 0,
        $input['map_center_lat'] ?? null,
        $input['map_center_lon'] ?? null,
        $input['map_zoom'] ?? null,
        $boundaryLayers
    ];

    $stmt = sqlsrv_query($conn, $sql, $params);
    if ($stmt === false) {
        throw new Exception(formatSqlError(sqlsrv_errors()));
    }

    sqlsrv_next_result($stmt);
    $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    $configId = $row['config_id'] ?? null;

    echo json_encode(['config_id' => intval($configId)]);
}

/**
 * PUT - Update config
 */
function handlePut($conn) {
    $input = json_decode(file_get_contents('php://input'), true);

    if (!$input || !isset($input['config_id'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing config_id']);
        return;
    }

    $configId = intval($input['config_id']);

    $updates = [];
    $params = [];

    $allowedFields = [
        'facility_code', 'facility_type', 'config_name', 'is_shared', 'is_default',
        'map_center_lat', 'map_center_lon', 'map_zoom', 'boundary_layers'
    ];

    foreach ($allowedFields as $field) {
        if (array_key_exists($field, $input)) {
            $value = $input[$field];
            if ($field === 'boundary_layers' && is_array($value)) {
                $value = json_encode($value);
            }
            $updates[] = "$field = ?";
            $params[] = $value;
        }
    }

    if (empty($updates)) {
        http_response_code(400);
        echo json_encode(['error' => 'No fields to update']);
        return;
    }

    $updates[] = "updated_at = GETUTCDATE()";
    $params[] = $configId;

    $sql = "UPDATE dbo.facility_flow_configs SET " . implode(', ', $updates) . " WHERE config_id = ?";
    $stmt = sqlsrv_query($conn, $sql, $params);
    if ($stmt === false) {
        throw new Exception(formatSqlError(sqlsrv_errors()));
    }

    echo json_encode(['success' => true]);
}

/**
 * DELETE - Delete config (CASCADE handles elements/gates)
 */
function handleDelete($conn) {
    $config_id = isset($_GET['config_id']) ? intval($_GET['config_id']) : null;

    if (!$config_id) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing config_id']);
        return;
    }

    $sql = "DELETE FROM dbo.facility_flow_configs WHERE config_id = ?";
    $stmt = sqlsrv_query($conn, $sql, [$config_id]);
    if ($stmt === false) {
        throw new Exception(formatSqlError(sqlsrv_errors()));
    }

    $affected = sqlsrv_rows_affected($stmt);
    if ($affected === 0) {
        http_response_code(404);
        echo json_encode(['error' => 'Config not found']);
        return;
    }

    echo json_encode(['success' => true]);
}
