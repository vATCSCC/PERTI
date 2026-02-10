<?php
/**
 * NOD Flow Suggestions API (READ-ONLY)
 *
 * GET - Search fixes or procedures for autocomplete suggestions
 *   ?type=fix&q=MER          - Search nav_fixes by prefix
 *   ?type=procedure&airport=KATL&q=RPTOR - Search nav_procedures by airport and name
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

if ($method !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

try {
    handleGet($conn);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
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
 * GET - Search for fix or procedure suggestions
 */
function handleGet($conn) {
    $type = $_GET['type'] ?? null;
    $q = $_GET['q'] ?? null;

    if (!$type || !$q) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing required parameters: type and q']);
        return;
    }

    switch (strtolower($type)) {
        case 'fix':
            handleFixSearch($conn, $q);
            break;
        case 'procedure':
            handleProcedureSearch($conn, $q);
            break;
        default:
            http_response_code(400);
            echo json_encode(['error' => 'Invalid type. Must be "fix" or "procedure"']);
    }
}

/**
 * Search nav_fixes by prefix
 */
function handleFixSearch($conn, $q) {
    $pattern = $q . '%';

    $sql = "SELECT TOP 10 fix_name, fix_type, lat, lon
            FROM dbo.nav_fixes
            WHERE fix_name LIKE ?
            ORDER BY fix_name ASC";

    $stmt = sqlsrv_query($conn, $sql, [$pattern]);
    if ($stmt === false) {
        throw new Exception(formatSqlError(sqlsrv_errors()));
    }

    $suggestions = [];
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $suggestions[] = $row;
    }

    echo json_encode(['suggestions' => $suggestions]);
}

/**
 * Search nav_procedures by airport and name
 * Tries VATSIM_REF first, falls back to ADL copy
 */
function handleProcedureSearch($conn, $q) {
    $airport = $_GET['airport'] ?? null;

    if (!$airport) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing required parameter: airport (for procedure search)']);
        return;
    }

    $pattern = '%' . $q . '%';

    // Try VATSIM_REF first
    $refConn = get_conn_ref();
    $searchConn = $refConn ?: $conn;

    $sql = "SELECT TOP 10 procedure_id, procedure_type, procedure_name, airport_icao
            FROM dbo.nav_procedures
            WHERE airport_icao = ? AND procedure_name LIKE ?
            ORDER BY procedure_name ASC";

    $stmt = sqlsrv_query($searchConn, $sql, [$airport, $pattern]);

    // If REF query failed and we were using REF, fall back to ADL
    if ($stmt === false && $refConn && $searchConn !== $conn) {
        $stmt = sqlsrv_query($conn, $sql, [$airport, $pattern]);
    }

    if ($stmt === false) {
        throw new Exception(formatSqlError(sqlsrv_errors()));
    }

    $suggestions = [];
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $suggestions[] = $row;
    }

    echo json_encode(['suggestions' => $suggestions]);
}
