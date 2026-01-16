<?php

// api/demand/override.php
// Manage manual rate overrides for airports
// Supports GET (list), POST (create), DELETE (cancel)

if (session_status() == PHP_SESSION_NONE) {
    session_start();
    ob_start();
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');

require_once("../../load/config.php");

// Check ADL database configuration
if (!defined("ADL_SQL_HOST") || !defined("ADL_SQL_DATABASE") ||
    !defined("ADL_SQL_USERNAME") || !defined("ADL_SQL_PASSWORD")) {
    http_response_code(500);
    echo json_encode(["success" => false, "error" => "ADL_SQL_* constants are not defined."]);
    exit;
}

// Helper function for SQL Server error messages
function adl_sql_error_message() {
    $errs = sqlsrv_errors(SQLSRV_ERR_ERRORS);
    if (!$errs) return "";
    $msgs = [];
    foreach ($errs as $e) {
        $msgs[] = (isset($e['SQLSTATE']) ? $e['SQLSTATE'] : '') . " " .
                  (isset($e['code']) ? $e['code'] : '') . " " .
                  (isset($e['message']) ? trim($e['message']) : '');
    }
    return implode(" | ", $msgs);
}

// Check sqlsrv extension
if (!function_exists('sqlsrv_connect')) {
    http_response_code(500);
    echo json_encode(["success" => false, "error" => "sqlsrv extension not available."]);
    exit;
}

// Connect to ADL database
$connectionInfo = [
    "Database" => ADL_SQL_DATABASE,
    "UID"      => ADL_SQL_USERNAME,
    "PWD"      => ADL_SQL_PASSWORD
];

$conn = sqlsrv_connect(ADL_SQL_HOST, $connectionInfo);
if ($conn === false) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "error" => "Unable to connect to ADL database.",
        "sql_error" => adl_sql_error_message()
    ]);
    exit;
}

// Handle different HTTP methods
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

switch ($method) {
    case 'GET':
        handleGet($conn);
        break;
    case 'POST':
        handlePost($conn);
        break;
    case 'DELETE':
        handleDelete($conn);
        break;
    default:
        http_response_code(405);
        echo json_encode(["success" => false, "error" => "Method not allowed"]);
}

sqlsrv_close($conn);

/**
 * GET - List overrides for an airport or get specific override
 */
function handleGet($conn) {
    $airport = isset($_GET['airport']) ? get_upper('airport') : '';
    $overrideId = isset($_GET['id']) ? get_int('id') : null;

    // Normalize airport code
    if (!empty($airport) && strlen($airport) === 3 && !preg_match('/^[PK]/', $airport)) {
        $airport = 'K' . $airport;
    }

    if ($overrideId) {
        // Get specific override
        $sql = "SELECT
                    override_id, airport_icao, start_utc, end_utc,
                    aar, adr, config_id, reason, created_by, created_utc,
                    is_active
                FROM dbo.manual_rate_override
                WHERE override_id = ?";
        $stmt = sqlsrv_query($conn, $sql, [$overrideId]);
    } elseif (!empty($airport)) {
        // Get overrides for airport (active and future only)
        $sql = "SELECT
                    o.override_id, o.airport_icao, o.start_utc, o.end_utc,
                    o.aar, o.adr, o.config_id, c.config_name,
                    o.reason, o.created_by, o.created_utc,
                    CASE WHEN GETUTCDATE() BETWEEN o.start_utc AND o.end_utc THEN 1 ELSE 0 END AS is_current,
                    CASE WHEN o.start_utc > GETUTCDATE() THEN 1 ELSE 0 END AS is_future,
                    DATEDIFF(MINUTE, GETUTCDATE(), o.end_utc) AS remaining_mins
                FROM dbo.manual_rate_override o
                LEFT JOIN dbo.airport_config c ON o.config_id = c.config_id
                WHERE o.airport_icao = ?
                  AND o.is_active = 1
                  AND o.end_utc > GETUTCDATE()
                ORDER BY o.start_utc";
        $stmt = sqlsrv_query($conn, $sql, [$airport]);
    } else {
        // Get all current/future overrides
        $sql = "SELECT
                    o.override_id, o.airport_icao, o.start_utc, o.end_utc,
                    o.aar, o.adr, o.config_id, c.config_name,
                    o.reason, o.created_by, o.created_utc,
                    CASE WHEN GETUTCDATE() BETWEEN o.start_utc AND o.end_utc THEN 1 ELSE 0 END AS is_current,
                    CASE WHEN o.start_utc > GETUTCDATE() THEN 1 ELSE 0 END AS is_future,
                    DATEDIFF(MINUTE, GETUTCDATE(), o.end_utc) AS remaining_mins
                FROM dbo.manual_rate_override o
                LEFT JOIN dbo.airport_config c ON o.config_id = c.config_id
                WHERE o.is_active = 1
                  AND o.end_utc > GETUTCDATE()
                ORDER BY o.airport_icao, o.start_utc";
        $stmt = sqlsrv_query($conn, $sql);
    }

    if ($stmt === false) {
        http_response_code(500);
        echo json_encode([
            "success" => false,
            "error" => "Query failed",
            "sql_error" => adl_sql_error_message()
        ]);
        return;
    }

    $overrides = [];
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        // Format DateTime objects
        $override = [
            "override_id" => (int)$row['override_id'],
            "airport_icao" => $row['airport_icao'],
            "start_utc" => $row['start_utc'] instanceof DateTime
                ? $row['start_utc']->format('Y-m-d\TH:i:s\Z') : $row['start_utc'],
            "end_utc" => $row['end_utc'] instanceof DateTime
                ? $row['end_utc']->format('Y-m-d\TH:i:s\Z') : $row['end_utc'],
            "aar" => $row['aar'] !== null ? (int)$row['aar'] : null,
            "adr" => $row['adr'] !== null ? (int)$row['adr'] : null,
            "config_id" => $row['config_id'] !== null ? (int)$row['config_id'] : null,
            "config_name" => $row['config_name'] ?? null,
            "reason" => $row['reason'],
            "created_by" => $row['created_by'],
            "created_utc" => $row['created_utc'] instanceof DateTime
                ? $row['created_utc']->format('Y-m-d\TH:i:s\Z') : $row['created_utc'],
            "is_current" => isset($row['is_current']) ? (bool)$row['is_current'] : null,
            "is_future" => isset($row['is_future']) ? (bool)$row['is_future'] : null,
            "remaining_mins" => isset($row['remaining_mins']) ? (int)$row['remaining_mins'] : null
        ];
        $overrides[] = $override;
    }
    sqlsrv_free_stmt($stmt);

    echo json_encode([
        "success" => true,
        "overrides" => $overrides,
        "count" => count($overrides)
    ]);
}

/**
 * POST - Create a new rate override
 */
function handlePost($conn) {
    // Get JSON body
    $input = json_decode(file_get_contents('php://input'), true);

    if (!$input) {
        http_response_code(400);
        echo json_encode(["success" => false, "error" => "Invalid JSON body"]);
        return;
    }

    // Validate required fields
    $airport = isset($input['airport']) ? strtoupper(trim($input['airport'])) : '';
    $startUtc = isset($input['start_utc']) ? $input['start_utc'] : null;
    $endUtc = isset($input['end_utc']) ? $input['end_utc'] : null;
    $aar = isset($input['aar']) ? intval($input['aar']) : null;
    $adr = isset($input['adr']) ? intval($input['adr']) : null;
    $configId = isset($input['config_id']) ? intval($input['config_id']) : null;
    $reason = isset($input['reason']) ? trim($input['reason']) : null;
    $createdBy = isset($input['created_by']) ? trim($input['created_by']) : null;

    // Normalize airport
    if (strlen($airport) === 3 && !preg_match('/^[PK]/', $airport)) {
        $airport = 'K' . $airport;
    }

    // Validate
    if (empty($airport)) {
        http_response_code(400);
        echo json_encode(["success" => false, "error" => "Airport is required"]);
        return;
    }

    if (empty($startUtc) || empty($endUtc)) {
        http_response_code(400);
        echo json_encode(["success" => false, "error" => "start_utc and end_utc are required"]);
        return;
    }

    if ($aar === null && $adr === null && $configId === null) {
        http_response_code(400);
        echo json_encode(["success" => false, "error" => "Must specify at least one of: aar, adr, config_id"]);
        return;
    }

    // Parse dates
    try {
        $startDt = new DateTime($startUtc, new DateTimeZone('UTC'));
        $endDt = new DateTime($endUtc, new DateTimeZone('UTC'));
    } catch (Exception $e) {
        http_response_code(400);
        echo json_encode(["success" => false, "error" => "Invalid date format"]);
        return;
    }

    if ($endDt <= $startDt) {
        http_response_code(400);
        echo json_encode(["success" => false, "error" => "End time must be after start time"]);
        return;
    }

    // Call stored procedure
    $sql = "EXEC dbo.sp_SetRateOverride
            @airport_icao = ?,
            @start_utc = ?,
            @end_utc = ?,
            @aar = ?,
            @adr = ?,
            @config_id = ?,
            @reason = ?,
            @created_by = ?";

    $params = [
        $airport,
        $startDt->format('Y-m-d H:i:s'),
        $endDt->format('Y-m-d H:i:s'),
        $aar === 0 ? null : $aar,
        $adr === 0 ? null : $adr,
        $configId === 0 ? null : $configId,
        $reason,
        $createdBy
    ];

    $stmt = sqlsrv_query($conn, $sql, $params);

    if ($stmt === false) {
        http_response_code(500);
        echo json_encode([
            "success" => false,
            "error" => "Failed to create override",
            "sql_error" => adl_sql_error_message()
        ]);
        return;
    }

    $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    sqlsrv_free_stmt($stmt);

    if (!$row) {
        http_response_code(500);
        echo json_encode(["success" => false, "error" => "No result returned from procedure"]);
        return;
    }

    echo json_encode([
        "success" => true,
        "override_id" => (int)$row['override_id'],
        "airport_icao" => $row['airport_icao'],
        "start_utc" => $startDt->format('Y-m-d\TH:i:s\Z'),
        "end_utc" => $endDt->format('Y-m-d\TH:i:s\Z'),
        "aar" => $row['aar'] !== null ? (int)$row['aar'] : null,
        "adr" => $row['adr'] !== null ? (int)$row['adr'] : null,
        "status" => $row['status']
    ]);
}

/**
 * DELETE - Cancel an override
 */
function handleDelete($conn) {
    $overrideId = isset($_GET['id']) ? get_int('id') : null;
    $airport = isset($_GET['airport']) ? get_upper('airport') : '';

    // Normalize airport
    if (!empty($airport) && strlen($airport) === 3 && !preg_match('/^[PK]/', $airport)) {
        $airport = 'K' . $airport;
    }

    if (!$overrideId && empty($airport)) {
        http_response_code(400);
        echo json_encode(["success" => false, "error" => "Either id or airport is required"]);
        return;
    }

    // Call stored procedure
    $sql = "EXEC dbo.sp_CancelRateOverride @override_id = ?, @airport_icao = ?";
    $params = [
        $overrideId ?: null,
        !empty($airport) ? $airport : null
    ];

    $stmt = sqlsrv_query($conn, $sql, $params);

    if ($stmt === false) {
        http_response_code(500);
        echo json_encode([
            "success" => false,
            "error" => "Failed to cancel override",
            "sql_error" => adl_sql_error_message()
        ]);
        return;
    }

    $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    sqlsrv_free_stmt($stmt);

    $cancelled = $row ? (int)$row['overrides_cancelled'] : 0;

    echo json_encode([
        "success" => true,
        "overrides_cancelled" => $cancelled
    ]);
}
