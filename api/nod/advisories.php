<?php
/**
 * NOD Advisories API
 * 
 * GET    - List advisories (with optional filters)
 * POST   - Create new advisory
 * PUT    - Update advisory
 * DELETE - Cancel advisory
 */

header('Content-Type: application/json');

// Include database connection
$config_path = realpath(__DIR__ . '/../../load/config.php');
$connect_path = realpath(__DIR__ . '/../../load/connect.php');

if ($config_path) include($config_path);
if ($connect_path) include($connect_path);

// Check for Azure SQL connection
if (!isset($conn_adl) || !$conn_adl) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection not available']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];

try {
    switch ($method) {
        case 'GET':
            handleGet($conn_adl);
            break;
        case 'POST':
            handlePost($conn_adl);
            break;
        case 'PUT':
            handlePut($conn_adl);
            break;
        case 'DELETE':
            handleDelete($conn_adl);
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
 * GET - List advisories
 * Query params:
 *   - status: ACTIVE, CANCELLED, EXPIRED, ALL (default: ACTIVE)
 *   - type: advisory type filter
 *   - date: specific date (YYYY-MM-DD), default: today
 *   - days: number of days to look back (alternative to date)
 *   - id: specific advisory ID
 */
function handleGet($conn) {
    $status = $_GET['status'] ?? 'ACTIVE';
    $type = $_GET['type'] ?? null;
    $date = $_GET['date'] ?? null;
    $days = isset($_GET['days']) ? intval($_GET['days']) : null;
    $id = isset($_GET['id']) ? intval($_GET['id']) : null;
    
    // Single advisory lookup
    if ($id) {
        $sql = "SELECT * FROM dbo.dcc_advisories WHERE id = ?";
        $stmt = sqlsrv_query($conn, $sql, [$id]);
        
        if ($stmt === false) {
            throw new Exception(formatSqlError(sqlsrv_errors()));
        }
        
        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        if ($row) {
            echo json_encode(['advisory' => formatAdvisory($row)]);
        } else {
            http_response_code(404);
            echo json_encode(['error' => 'Advisory not found']);
        }
        return;
    }
    
    // Build query for list
    $conditions = [];
    $params = [];
    
    // Status filter
    if ($status !== 'ALL') {
        if ($status === 'ACTIVE') {
            $conditions[] = "status = 'ACTIVE'";
            $conditions[] = "valid_start_utc <= GETUTCDATE()";
            $conditions[] = "(valid_end_utc IS NULL OR valid_end_utc > GETUTCDATE())";
        } else {
            $conditions[] = "status = ?";
            $params[] = $status;
        }
    }
    
    // Type filter
    if ($type) {
        $conditions[] = "adv_type = ?";
        $params[] = $type;
    }
    
    // Date filter
    if ($date) {
        $conditions[] = "CAST(created_at AS DATE) = ?";
        $params[] = $date;
    } elseif ($days !== null) {
        $conditions[] = "created_at >= DATEADD(day, -?, GETUTCDATE())";
        $params[] = $days;
    } else {
        // Default: today only for active, last 7 days for all
        if ($status === 'ACTIVE') {
            // No additional date filter for active - show all currently valid
        } else {
            $conditions[] = "created_at >= DATEADD(day, -7, GETUTCDATE())";
        }
    }
    
    $whereClause = count($conditions) > 0 ? 'WHERE ' . implode(' AND ', $conditions) : '';
    
    $sql = "SELECT * FROM dbo.dcc_advisories $whereClause ORDER BY priority ASC, created_at DESC";
    
    $stmt = sqlsrv_query($conn, $sql, $params);
    
    if ($stmt === false) {
        throw new Exception(formatSqlError(sqlsrv_errors()));
    }
    
    $advisories = [];
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $advisories[] = formatAdvisory($row);
    }
    
    echo json_encode([
        'advisories' => $advisories,
        'count' => count($advisories),
        'filters' => [
            'status' => $status,
            'type' => $type,
            'date' => $date,
            'days' => $days
        ]
    ]);
}

/**
 * POST - Create new advisory
 */
function handlePost($conn) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid JSON input']);
        return;
    }
    
    // Required fields
    $required = ['adv_type', 'subject', 'body_text', 'valid_start_utc', 'created_by'];
    foreach ($required as $field) {
        if (empty($input[$field])) {
            http_response_code(400);
            echo json_encode(['error' => "Missing required field: $field"]);
            return;
        }
    }
    
    // Generate advisory number
    $prefix = $input['adv_prefix'] ?? 'DCC';
    $advNumSql = "EXEC dbo.sp_dcc_next_advisory_number @prefix = ?";
    $advNumStmt = sqlsrv_query($conn, $advNumSql, [$prefix]);
    
    if ($advNumStmt === false) {
        throw new Exception(formatSqlError(sqlsrv_errors()));
    }
    
    $advNumRow = sqlsrv_fetch_array($advNumStmt, SQLSRV_FETCH_ASSOC);
    $advNumber = $advNumRow['adv_number'] ?? ($prefix . ' 001');
    sqlsrv_free_stmt($advNumStmt);
    
    // Insert advisory
    $sql = "INSERT INTO dbo.dcc_advisories (
                adv_number, adv_type, adv_category, subject, body_text,
                valid_start_utc, valid_end_utc,
                impacted_facilities, impacted_airports, impacted_area,
                source, source_ref, status, priority, created_by
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?);
            SELECT SCOPE_IDENTITY() AS id;";
    
    $params = [
        $advNumber,
        $input['adv_type'],
        $input['adv_category'] ?? null,
        $input['subject'],
        $input['body_text'],
        $input['valid_start_utc'],
        $input['valid_end_utc'] ?? null,
        isset($input['impacted_facilities']) ? json_encode($input['impacted_facilities']) : null,
        isset($input['impacted_airports']) ? json_encode($input['impacted_airports']) : null,
        $input['impacted_area'] ?? null,
        $input['source'] ?? 'MANUAL',
        $input['source_ref'] ?? null,
        $input['status'] ?? 'ACTIVE',
        $input['priority'] ?? 2,
        $input['created_by']
    ];
    
    $stmt = sqlsrv_query($conn, $sql, $params);
    
    if ($stmt === false) {
        throw new Exception(formatSqlError(sqlsrv_errors()));
    }
    
    // Get inserted ID
    sqlsrv_next_result($stmt);
    $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    $insertedId = $row['id'] ?? null;
    
    echo json_encode([
        'success' => true,
        'id' => $insertedId,
        'adv_number' => $advNumber,
        'message' => 'Advisory created successfully'
    ]);
}

/**
 * PUT - Update advisory
 */
function handlePut($conn) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input || !isset($input['id'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing advisory ID']);
        return;
    }
    
    $id = intval($input['id']);
    
    // Build UPDATE statement dynamically
    $updates = [];
    $params = [];
    
    $allowedFields = [
        'adv_type', 'adv_category', 'subject', 'body_text',
        'valid_start_utc', 'valid_end_utc',
        'impacted_facilities', 'impacted_airports', 'impacted_area',
        'status', 'priority'
    ];
    
    foreach ($allowedFields as $field) {
        if (isset($input[$field])) {
            $value = $input[$field];
            
            // Handle JSON fields
            if (in_array($field, ['impacted_facilities', 'impacted_airports']) && is_array($value)) {
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
    
    // Add audit fields
    $updates[] = "updated_by = ?";
    $params[] = $input['updated_by'] ?? 'SYSTEM';
    $updates[] = "updated_at = GETUTCDATE()";
    
    $params[] = $id;
    
    $sql = "UPDATE dbo.dcc_advisories SET " . implode(', ', $updates) . " WHERE id = ?";
    
    $stmt = sqlsrv_query($conn, $sql, $params);
    
    if ($stmt === false) {
        throw new Exception(formatSqlError(sqlsrv_errors()));
    }
    
    $affected = sqlsrv_rows_affected($stmt);
    
    echo json_encode([
        'success' => $affected > 0,
        'affected' => $affected,
        'message' => $affected > 0 ? 'Advisory updated' : 'No advisory found with that ID'
    ]);
}

/**
 * DELETE - Cancel advisory (soft delete)
 */
function handleDelete($conn) {
    $input = json_decode(file_get_contents('php://input'), true);
    $id = $input['id'] ?? $_GET['id'] ?? null;
    
    if (!$id) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing advisory ID']);
        return;
    }
    
    $id = intval($id);
    $cancelledBy = $input['cancelled_by'] ?? 'SYSTEM';
    $cancelReason = $input['cancel_reason'] ?? null;
    
    $sql = "UPDATE dbo.dcc_advisories 
            SET status = 'CANCELLED',
                cancelled_by = ?,
                cancelled_at = GETUTCDATE(),
                cancel_reason = ?
            WHERE id = ?";
    
    $stmt = sqlsrv_query($conn, $sql, [$cancelledBy, $cancelReason, $id]);
    
    if ($stmt === false) {
        throw new Exception(formatSqlError(sqlsrv_errors()));
    }
    
    $affected = sqlsrv_rows_affected($stmt);
    
    echo json_encode([
        'success' => $affected > 0,
        'message' => $affected > 0 ? 'Advisory cancelled' : 'No advisory found with that ID'
    ]);
}

/**
 * Format advisory row for JSON output
 */
function formatAdvisory($row) {
    // Convert DateTime objects to ISO strings
    $dateFields = ['valid_start_utc', 'valid_end_utc', 'created_at', 'updated_at', 'cancelled_at'];
    
    foreach ($dateFields as $field) {
        if (isset($row[$field]) && $row[$field] instanceof DateTime) {
            $row[$field] = $row[$field]->format('Y-m-d\TH:i:s\Z');
        }
    }
    
    // Parse JSON fields
    $jsonFields = ['impacted_facilities', 'impacted_airports'];
    foreach ($jsonFields as $field) {
        if (isset($row[$field]) && is_string($row[$field])) {
            $row[$field] = json_decode($row[$field], true);
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
