<?php
/**
 * JATOC Single Incident API - GET/PUT/DELETE with Detailed Logging
 * GET: Public access
 * PUT/DELETE: Requires VATSIM auth with role-based permissions
 */
header('Content-Type: application/json');

include("../../sessions/handler.php");
if (session_status() == PHP_SESSION_NONE) session_start();
include("../../load/config.php");
define('PERTI_ADL_ONLY', true);
include("../../load/connect.php");

// Include JATOC utilities
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/datetime.php';
require_once __DIR__ . '/validators.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../../load/org_context.php';

JatocAuth::setConnection($conn_adl);

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$id = $_GET['id'] ?? null;

if (!$id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing incident ID']);
    exit;
}

// PUT requires update permission, DELETE requires delete permission
if ($method === 'PUT') {
    JatocAuth::requirePermission('update');
} elseif ($method === 'DELETE') {
    JatocAuth::requirePermission('delete');
}

try {
    if ($method === 'GET') {
        $stmt = sqlsrv_query($conn_adl, "SELECT * FROM jatoc_incidents WHERE id = ?", [$id]);
        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        if (!$row) { http_response_code(404); echo json_encode(['success' => false, 'error' => 'Not found']); exit; }

        foreach (['start_utc', 'update_utc', 'closeout_utc', 'created_at', 'updated_at'] as $f) {
            if (isset($row[$f]) && $row[$f] instanceof DateTime) $row[$f] = $row[$f]->format('Y-m-d H:i:s');
        }

        // Add new column names for forward compatibility
        if (!isset($row['incident_type']) && isset($row['status'])) {
            $row['incident_type'] = $row['status'];
        }
        if (!isset($row['lifecycle_status']) && isset($row['incident_status'])) {
            $row['lifecycle_status'] = $row['incident_status'];
        }

        echo json_encode(['success' => true, 'data' => $row]);

    } elseif ($method === 'PUT') {
        $input = json_decode(file_get_contents('php://input'), true);

        // Validate input
        $errors = JatocValidators::incidentUpdate($input);
        if ($errors) {
            http_response_code(400);
            echo json_encode(['success' => false, 'errors' => $errors]);
            exit;
        }

        // Org-scope: validate facility if being changed
        if (!empty($input['facility'])) {
            require_facility_scope(strtoupper($input['facility']), $conn_sqli, $conn_adl);
        }

        // Get current values for comparison
        $oldStmt = sqlsrv_query($conn_adl, "SELECT * FROM jatoc_incidents WHERE id = ?", [$id]);
        $old = sqlsrv_fetch_array($oldStmt, SQLSRV_FETCH_ASSOC);
        if (!$old) { http_response_code(404); echo json_encode(['success' => false, 'error' => 'Not found']); exit; }

        $updates = [];
        $params = [];
        $changes = [];
        $userName = $input['updated_by'] ?? JatocAuth::getUserName();

        // Track field changes (support both old and new column names)
        $trackFields = [
            'facility' => 'Facility',
            'facility_type' => 'Type',
            'status' => 'Incident Type',
            'incident_type' => 'Incident Type',
            'trigger_code' => 'Trigger',
            'trigger_desc' => 'Trigger Desc',
            'remarks' => 'Remarks',
            'incident_status' => 'Lifecycle',
            'lifecycle_status' => 'Lifecycle'
        ];

        foreach ($trackFields as $dbField => $label) {
            if (array_key_exists($dbField, $input)) {
                $newVal = $input[$dbField];
                $oldVal = $old[$dbField] ?? null;
                if ($newVal != $oldVal) {
                    $updates[] = "$dbField = ?";
                    $params[] = $newVal;
                    $changes[] = "$label: " . ($oldVal ?: 'null') . " -> " . ($newVal ?: 'null');

                    // Also update the paired column during transition
                    if ($dbField === 'status' && !array_key_exists('incident_type', $input)) {
                        $updates[] = "incident_type = ?";
                        $params[] = $newVal;
                    } elseif ($dbField === 'incident_type' && !array_key_exists('status', $input)) {
                        $updates[] = "status = ?";
                        $params[] = $newVal;
                    } elseif ($dbField === 'incident_status' && !array_key_exists('lifecycle_status', $input)) {
                        $updates[] = "lifecycle_status = ?";
                        $params[] = $newVal;
                    } elseif ($dbField === 'lifecycle_status' && !array_key_exists('incident_status', $input)) {
                        $updates[] = "incident_status = ?";
                        $params[] = $newVal;
                    }
                }
            }
        }

        // Handle paged (boolean)
        if (array_key_exists('paged', $input)) {
            $newPaged = $input['paged'] ? 1 : 0;
            $oldPaged = $old['paged'] ? 1 : 0;
            if ($newPaged != $oldPaged) {
                $updates[] = "paged = ?";
                $params[] = $newPaged;
                $changes[] = "Paged: " . ($oldPaged ? 'Yes' : 'No') . " -> " . ($newPaged ? 'Yes' : 'No');
            }
        }

        // Handle datetime fields using utility
        $dateFields = ['start_utc', 'update_utc', 'closeout_utc'];
        foreach ($dateFields as $f) {
            if (array_key_exists($f, $input)) {
                $v = JatocDateTime::toSqlServer($input[$f]);
                $updates[] = "$f = ?";
                $params[] = $v;

                if ($f === 'closeout_utc' && $v && !$old['closeout_utc']) {
                    $changes[] = "Closed out";
                }
            }
        }

        // Generate report number if requested
        $reportNumber = null;
        if (!empty($input['generate_report_number']) && !$old['report_number']) {
            $rptStmt = sqlsrv_query($conn_adl, "DECLARE @num VARCHAR(12); EXEC sp_jatoc_next_report_number @num OUTPUT; SELECT @num AS rn;");
            $row = sqlsrv_fetch_array($rptStmt, SQLSRV_FETCH_ASSOC);
            $reportNumber = $row['rn'];
            $updates[] = "report_number = ?";
            $params[] = $reportNumber;
        }

        // Always update timestamps
        $updates[] = "update_utc = SYSUTCDATETIME()";
        $updates[] = "updated_at = SYSUTCDATETIME()";
        if ($userName !== 'Unknown') { $updates[] = "updated_by = ?"; $params[] = $userName; }

        if (empty($updates)) {
            echo json_encode(['success' => true, 'message' => 'No changes']);
            return;
        }

        $params[] = $id;
        $sql = "UPDATE jatoc_incidents SET " . implode(', ', $updates) . " WHERE id = ?";
        $stmt = sqlsrv_query($conn_adl, $sql, $params);
        if ($stmt === false) throw new Exception(print_r(sqlsrv_errors(), true));

        // Log changes
        if (!empty($changes)) {
            $changeLog = implode('; ', $changes);
            sqlsrv_query($conn_adl,
                "INSERT INTO jatoc_incident_updates (incident_id, update_type, remarks, created_by) VALUES (?, 'EDIT', ?, ?)",
                [$id, $changeLog, $userName]
            );
        }

        // Log report creation
        if ($reportNumber) {
            sqlsrv_query($conn_adl,
                "INSERT INTO jatoc_incident_updates (incident_id, update_type, remarks, created_by) VALUES (?, 'REPORT_CREATED', ?, ?)",
                [$id, "Report number assigned: $reportNumber", $userName]
            );
            updateReportData($conn_adl, $id);
        }

        // Log closeout separately
        if (array_key_exists('closeout_utc', $input) && $input['closeout_utc'] && !$old['closeout_utc']) {
            sqlsrv_query($conn_adl,
                "INSERT INTO jatoc_incident_updates (incident_id, update_type, remarks, created_by) VALUES (?, 'CLOSEOUT', ?, ?)",
                [$id, "Incident closed", $userName]
            );
        }

        // Log status change as separate entry (check both old and new column names)
        $oldIncType = $old['incident_type'] ?? $old['status'] ?? null;
        $newIncType = $input['incident_type'] ?? $input['status'] ?? null;
        if ($newIncType && $newIncType != $oldIncType) {
            sqlsrv_query($conn_adl,
                "INSERT INTO jatoc_incident_updates (incident_id, update_type, remarks, created_by) VALUES (?, 'STATUS_CHANGE', ?, ?)",
                [$id, "Incident Type: $oldIncType -> $newIncType", $userName]
            );
        }

        // Update report if exists
        updateReportData($conn_adl, $id);

        echo json_encode(['success' => true, 'report_number' => $reportNumber]);

    } elseif ($method === 'DELETE') {
        // Log deletion
        sqlsrv_query($conn_adl, "DELETE FROM jatoc_incident_updates WHERE incident_id = ?", [$id]);
        sqlsrv_query($conn_adl, "DELETE FROM jatoc_reports WHERE incident_id = ?", [$id]);
        sqlsrv_query($conn_adl, "DELETE FROM jatoc_incidents WHERE id = ?", [$id]);
        echo json_encode(['success' => true]);

    } else {
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

function updateReportData($conn, $incidentId) {
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
