<?php
/**
 * JATOC Report API
 * GET: Retrieve report (PUBLIC ACCESS)
 * POST: Generate/update report for incident (REQUIRES AUTH with 'report' permission)
 */

include("../../sessions/handler.php");
if (session_status() == PHP_SESSION_NONE) session_start();
include("../../load/config.php");
include("../../load/connect.php");

// Include JATOC utilities
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/datetime.php';
require_once __DIR__ . '/auth.php';

JatocAuth::setConnection($conn_adl);

$method = $_SERVER['REQUEST_METHOD'];

// POST requires authentication with report permission
if ($method === 'POST') {
    JatocAuth::requirePermission('report');
}

// Set content type based on request
if ($method === 'GET' && isset($_GET['format']) && $_GET['format'] === 'text') {
    header('Content-Type: text/plain; charset=utf-8');
} else {
    header('Content-Type: application/json');
}

try {
    if ($method === 'GET') {
        $id = $_GET['id'] ?? null;
        $reportNum = $_GET['report'] ?? null;
        $format = $_GET['format'] ?? 'json';
        $section = $_GET['section'] ?? null;

        $report = null;
        if ($reportNum) {
            $stmt = sqlsrv_query($conn_adl, "SELECT * FROM jatoc_reports WHERE report_number = ?", [$reportNum]);
            $report = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        } elseif ($id) {
            $stmt = sqlsrv_query($conn_adl, "SELECT * FROM jatoc_reports WHERE incident_id = ?", [$id]);
            $report = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        }

        if (!$report) {
            if (!$id) { http_response_code(400); echo json_encode(['success' => false, 'error' => 'Provide id or report']); exit; }
            $report = genReportData($conn_adl, $id);
            if (!$report) { http_response_code(404); echo json_encode(['success' => false, 'error' => 'Not found']); exit; }
        } else {
            $report['updates'] = json_decode($report['updates_json'] ?? '[]', true);
            $report['timeline'] = json_decode($report['timeline_json'] ?? '[]', true);
            $report['full_report'] = json_decode($report['full_report_json'] ?? '{}', true);
        }

        if ($section && isset($report['full_report'][$section])) {
            $report = ['section' => $section, 'data' => $report['full_report'][$section]];
        }

        if ($format === 'markdown') { header('Content-Type: text/markdown'); echo genMD($report); exit; }
        elseif ($format === 'text') { header('Content-Type: text/plain'); echo genTXT($report); exit; }
        else echo json_encode(['success' => true, 'report' => $report]);

    } elseif ($method === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        $incId = $input['incident_id'] ?? null;
        if (!$incId) { http_response_code(400); echo json_encode(['success' => false, 'error' => 'Missing incident_id']); exit; }

        $stmt = sqlsrv_query($conn_adl, "SELECT * FROM jatoc_incidents WHERE id = ?", [$incId]);
        $inc = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        if (!$inc) { http_response_code(404); echo json_encode(['success' => false, 'error' => 'Not found']); exit; }

        $rptNum = $inc['report_number'];
        $createdBy = $input['created_by'] ?? JatocAuth::getLogIdentifier();

        if (!$rptNum) {
            $rptStmt = sqlsrv_query($conn_adl, "DECLARE @num VARCHAR(12); EXEC sp_jatoc_next_report_number @num OUTPUT; SELECT @num AS rn;");
            $row = sqlsrv_fetch_array($rptStmt, SQLSRV_FETCH_ASSOC);
            $rptNum = $row['rn'];
            sqlsrv_query($conn_adl, "UPDATE jatoc_incidents SET report_number = ? WHERE id = ?", [$rptNum, $incId]);
            sqlsrv_query($conn_adl, "INSERT INTO jatoc_incident_updates (incident_id, update_type, remarks, created_by) VALUES (?, 'REPORT_CREATED', ?, ?)",
                [$incId, "Report number assigned: $rptNum", $createdBy]);
        }

        $data = genReportData($conn_adl, $incId);
        $chk = sqlsrv_query($conn_adl, "SELECT id FROM jatoc_reports WHERE incident_id = ?", [$incId]);
        $ex = sqlsrv_fetch_array($chk, SQLSRV_FETCH_ASSOC);

        $uJson = json_encode($data['updates'] ?? []);
        $tJson = json_encode($data['timeline'] ?? []);
        $fJson = json_encode($data['full_report'] ?? $data);

        // Get incident type (support both columns)
        $incidentType = $inc['incident_type'] ?? $inc['status'] ?? null;

        if ($ex) {
            sqlsrv_query($conn_adl, "UPDATE jatoc_reports SET facility=?,facility_type=?,status=?,trigger_code=?,trigger_desc=?,incident_start_utc=?,incident_closeout_utc=?,initial_remarks=?,updates_json=?,timeline_json=?,full_report_json=?,updated_by=?,updated_at=SYSUTCDATETIME() WHERE incident_id=?",
                [$inc['facility'],$inc['facility_type'],$incidentType,$inc['trigger_code'],$inc['trigger_desc'],JatocDateTime::formatUTC($inc['start_utc']),JatocDateTime::formatUTC($inc['closeout_utc']),$inc['remarks'],$uJson,$tJson,$fJson,$createdBy,$incId]);
        } else {
            sqlsrv_query($conn_adl, "INSERT INTO jatoc_reports (incident_id,report_number,facility,facility_type,status,trigger_code,trigger_desc,incident_start_utc,incident_closeout_utc,initial_remarks,updates_json,timeline_json,full_report_json,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
                [$incId,$rptNum,$inc['facility'],$inc['facility_type'],$incidentType,$inc['trigger_code'],$inc['trigger_desc'],JatocDateTime::formatUTC($inc['start_utc']),JatocDateTime::formatUTC($inc['closeout_utc']),$inc['remarks'],$uJson,$tJson,$fJson,$createdBy]);
        }
        echo json_encode(['success' => true, 'report_number' => $rptNum]);
    } else {
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

function genReportData($conn, $id) {
    $stmt = sqlsrv_query($conn, "SELECT * FROM jatoc_incidents WHERE id = ?", [$id]);
    $inc = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    if (!$inc) return null;

    // Get incident type and lifecycle (support both column names)
    $incidentType = $inc['incident_type'] ?? $inc['status'] ?? 'OTHER';
    $lifecycleStatus = $inc['lifecycle_status'] ?? $inc['incident_status'] ?? 'ACTIVE';
    $incidentTypeName = JATOC_INCIDENT_TYPES[$incidentType] ?? $incidentType;
    $triggerDesc = JATOC_TRIGGERS[$inc['trigger_code']] ?? $inc['trigger_desc'] ?? 'Unknown';

    $updStmt = sqlsrv_query($conn, "SELECT * FROM jatoc_incident_updates WHERE incident_id = ? ORDER BY created_utc ASC", [$id]);
    $updates = [];
    while ($row = sqlsrv_fetch_array($updStmt, SQLSRV_FETCH_ASSOC)) {
        $updates[] = [
            'id' => $row['id'],
            'type' => $row['update_type'],
            'remarks' => $row['remarks'],
            'created_by' => $row['created_by'],
            'timestamp' => JatocDateTime::formatUTC($row['created_utc'])
        ];
    }

    // Build complete timeline
    $timeline = [
        [
            'event' => 'Incident Created',
            'timestamp' => JatocDateTime::formatUTC($inc['start_utc']),
            'by' => $inc['created_by'],
            'details' => "Initial incident: $triggerDesc"
        ]
    ];

    // Add all updates with proper event type names
    foreach ($updates as $u) {
        $eventType = JATOC_UPDATE_TYPES[$u['type']] ?? ucfirst(strtolower($u['type']));
        $timeline[] = [
            'event' => $eventType,
            'timestamp' => $u['timestamp'],
            'by' => $u['created_by'],
            'details' => $u['remarks']
        ];
    }

    // Add closeout if present
    if ($inc['closeout_utc']) {
        $timeline[] = [
            'event' => 'Incident Closed',
            'timestamp' => JatocDateTime::formatUTC($inc['closeout_utc']),
            'by' => $inc['updated_by'],
            'details' => 'Duration: ' . JatocDateTime::calcDuration($inc['start_utc'], $inc['closeout_utc'])
        ];
    }

    // Build complete full_report structure
    $fullReport = [
        'header' => [
            'report_number' => $inc['report_number'],
            'incident_number' => $inc['incident_number'],
            'facility' => $inc['facility'],
            'generated' => JatocDateTime::nowUTC(),
            'version' => '1.0'
        ],
        'summary' => [
            'total_duration' => JatocDateTime::calcDuration($inc['start_utc'], $inc['closeout_utc']),
            'update_count' => count($updates),
            'was_paged' => (bool)$inc['paged'],
            'final_status' => $lifecycleStatus
        ],
        'incident' => [
            'facility' => $inc['facility'],
            'facility_type' => $inc['facility_type'],
            'status' => $incidentTypeName,
            'incident_type' => $incidentType,
            'trigger' => $triggerDesc,
            'trigger_code' => $inc['trigger_code'],
            'paged' => $inc['paged'] ? 'Yes' : 'No'
        ],
        'timeline' => $timeline,
        'updates' => $updates,
        'remarks' => $inc['remarks'],
        'metadata' => [
            'created_by' => $inc['created_by'],
            'closed_by' => $inc['updated_by'],
            'start_utc' => JatocDateTime::formatUTC($inc['start_utc']),
            'closeout_utc' => JatocDateTime::formatUTC($inc['closeout_utc'])
        ]
    ];

    return [
        'report_number' => $inc['report_number'],
        'incident_number' => $inc['incident_number'],
        'generated_at' => JatocDateTime::nowUTC(),
        'incident' => [
            'id' => $inc['id'],
            'facility' => $inc['facility'],
            'facility_type' => $inc['facility_type'],
            'status' => $incidentType,
            'incident_type' => $incidentType,
            'status_name' => $incidentTypeName,
            'trigger_code' => $inc['trigger_code'],
            'trigger_description' => $triggerDesc,
            'paged' => (bool)$inc['paged'],
            'incident_status' => $lifecycleStatus,
            'lifecycle_status' => $lifecycleStatus,
            'remarks' => $inc['remarks'],
            'created_by' => $inc['created_by'],
            'updated_by' => $inc['updated_by']
        ],
        'timing' => [
            'start_utc' => JatocDateTime::formatUTC($inc['start_utc']),
            'last_update_utc' => JatocDateTime::formatUTC($inc['update_utc']),
            'closeout_utc' => JatocDateTime::formatUTC($inc['closeout_utc']),
            'duration' => JatocDateTime::calcDuration($inc['start_utc'], $inc['closeout_utc'])
        ],
        'updates' => $updates,
        'timeline' => $timeline,
        'full_report' => $fullReport
    ];
}

function genMD($r) {
    $f = $r['full_report'] ?? $r;
    $md = "# JATOC Incident Report\n\n";
    $md .= "**Report:** " . ($f['header']['report_number'] ?? 'N/A') . " | ";
    $md .= "**Incident:** " . ($f['header']['incident_number'] ?? 'N/A') . " | ";
    $md .= "**Generated:** " . ($f['header']['generated'] ?? gmdate('c')) . "\n\n";

    $md .= "## Details\n\n| Field | Value |\n|---|---|\n";
    $md .= "| Facility | " . ($f['incident']['facility'] ?? '-') . " (" . ($f['incident']['facility_type'] ?? '-') . ") |\n";
    $md .= "| Incident Type | " . ($f['incident']['status'] ?? '-') . " |\n";
    $md .= "| Trigger | " . ($f['incident']['trigger'] ?? '-') . " |\n";
    $md .= "| Paged | " . ($f['incident']['paged'] ?? '-') . " |\n";

    if (isset($f['summary'])) {
        $md .= "| Duration | " . ($f['summary']['total_duration'] ?? '-') . " |\n";
        $md .= "| Updates | " . ($f['summary']['update_count'] ?? 0) . " |\n";
    }
    $md .= "\n";

    $md .= "## Timeline\n\n";
    foreach (($f['timeline'] ?? []) as $t) {
        $md .= "- **" . $t['timestamp'] . "** " . $t['event'];
        if (!empty($t['by'])) $md .= " (" . $t['by'] . ")";
        if (!empty($t['details'])) $md .= ": " . $t['details'];
        $md .= "\n";
    }

    $md .= "\n## Remarks\n\n" . ($f['remarks'] ?? 'None') . "\n";
    return $md;
}

function genTXT($r) {
    $f = $r['full_report'] ?? $r;
    $t = "=== JATOC INCIDENT REPORT ===\n";
    $t .= "Report: " . ($f['header']['report_number'] ?? 'N/A') . "\n";
    $t .= "Incident: " . ($f['header']['incident_number'] ?? 'N/A') . "\n";
    $t .= "Generated: " . ($f['header']['generated'] ?? gmdate('c')) . "\n\n";

    $t .= "DETAILS\n";
    $t .= "Facility: " . ($f['incident']['facility'] ?? '-') . " (" . ($f['incident']['facility_type'] ?? '-') . ")\n";
    $t .= "Incident Type: " . ($f['incident']['status'] ?? '-') . "\n";
    $t .= "Trigger: " . ($f['incident']['trigger'] ?? '-') . "\n";
    $t .= "Paged: " . ($f['incident']['paged'] ?? '-') . "\n";

    if (isset($f['summary'])) {
        $t .= "Duration: " . ($f['summary']['total_duration'] ?? '-') . "\n";
        $t .= "Updates: " . ($f['summary']['update_count'] ?? 0) . "\n";
    }
    $t .= "\n";

    $t .= "TIMELINE\n";
    foreach (($f['timeline'] ?? []) as $e) {
        $t .= $e['timestamp'] . " | " . $e['event'];
        if (!empty($e['by'])) $t .= " (" . $e['by'] . ")";
        if (!empty($e['details'])) $t .= "\n  -> " . $e['details'];
        $t .= "\n";
    }

    $t .= "\nREMARKS\n" . ($f['remarks'] ?? 'None') . "\n";
    return $t;
}
