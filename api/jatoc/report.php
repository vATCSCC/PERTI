<?php
/**
 * JATOC Report API
 * GET: Retrieve report (PUBLIC ACCESS)
 * POST: Generate/update report for incident (REQUIRES AUTH)
 */

include("../../sessions/handler.php");
if (session_status() == PHP_SESSION_NONE) session_start();
include("../../load/config.php");
include("../../load/connect.php");

$method = $_SERVER['REQUEST_METHOD'];

// POST requires authentication
if ($method === 'POST' && !isset($_SESSION['VATSIM_CID'])) {
    header('Content-Type: application/json');
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

// Set content type based on request
if ($method === 'GET' && isset($_GET['format']) && $_GET['format'] === 'text') {
    header('Content-Type: text/plain; charset=utf-8');
} else {
    header('Content-Type: application/json');
}

$TRIGGERS = [
    'A' => 'AFV (Audio for VATSIM)',
    'B' => 'Other Audio Issue',
    'C' => 'Multiple Audio Issues',
    'D' => 'Datafeed (VATSIM)',
    'E' => 'Datafeed (Other)',
    'F' => 'Frequency Issue',
    'H' => 'Radar Client Issue',
    'J' => 'Staffing (Below Minimum)',
    'K' => 'Staffing (At Minimum)',
    'M' => 'Staffing (None)',
    'Q' => 'Other',
    'R' => 'Pilot Issue',
    'S' => 'Security (Real World)',
    'T' => 'Security (VATSIM)',
    'U' => 'Unknown',
    'V' => 'Volume',
    'W' => 'Weather'
];

$STATUS_NAMES = [
    'ATC_ZERO' => 'ATC Zero',
    'ATC_ALERT' => 'ATC Alert',
    'ATC_LIMITED' => 'ATC Limited',
    'NON_RESPONSIVE' => 'Non-Responsive',
    'OTHER' => 'Other'
];

function formatDT($dt) {
    if (!$dt) return null;
    if ($dt instanceof DateTime) return $dt->format('Y-m-d H:i:s') . 'Z';
    return $dt;
}

function calcDur($start, $end = null) {
    if (!$start) return null;
    $s = ($start instanceof DateTime) ? $start : new DateTime($start, new DateTimeZone('UTC'));
    $e = $end ? (($end instanceof DateTime) ? $end : new DateTime($end, new DateTimeZone('UTC'))) : new DateTime('now', new DateTimeZone('UTC'));
    $diff = $e->getTimestamp() - $s->getTimestamp();
    if ($diff < 0) $diff = 0;
    $hours = floor($diff / 3600);
    $mins = floor(($diff % 3600) / 60);
    return ($hours > 0 ? "{$hours}h " : '') . "{$mins}m";
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
            $report = genReportData($conn_adl, $id, $TRIGGERS, $STATUS_NAMES);
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
        if (!$rptNum) {
            $rptStmt = sqlsrv_query($conn_adl, "DECLARE @num VARCHAR(12); EXEC sp_jatoc_next_report_number @num OUTPUT; SELECT @num AS rn;");
            $row = sqlsrv_fetch_array($rptStmt, SQLSRV_FETCH_ASSOC);
            $rptNum = $row['rn'];
            sqlsrv_query($conn_adl, "UPDATE jatoc_incidents SET report_number = ? WHERE id = ?", [$rptNum, $incId]);
            sqlsrv_query($conn_adl, "INSERT INTO jatoc_incident_updates (incident_id, update_type, remarks, created_by) VALUES (?, 'REPORT_CREATED', ?, ?)",
                [$incId, "Report number assigned: $rptNum", $input['created_by'] ?? 'System']);
        }
        
        $data = genReportData($conn_adl, $incId, $TRIGGERS, $STATUS_NAMES);
        $chk = sqlsrv_query($conn_adl, "SELECT id FROM jatoc_reports WHERE incident_id = ?", [$incId]);
        $ex = sqlsrv_fetch_array($chk, SQLSRV_FETCH_ASSOC);
        
        $uJson = json_encode($data['updates'] ?? []);
        $tJson = json_encode($data['timeline'] ?? []);
        $fJson = json_encode($data['full_report'] ?? $data);
        
        if ($ex) {
            sqlsrv_query($conn_adl, "UPDATE jatoc_reports SET facility=?,facility_type=?,status=?,trigger_code=?,trigger_desc=?,incident_start_utc=?,incident_closeout_utc=?,initial_remarks=?,updates_json=?,timeline_json=?,full_report_json=?,updated_by=?,updated_at=SYSUTCDATETIME() WHERE incident_id=?",
                [$inc['facility'],$inc['facility_type'],$inc['status'],$inc['trigger_code'],$inc['trigger_desc'],formatDT($inc['start_utc']),formatDT($inc['closeout_utc']),$inc['remarks'],$uJson,$tJson,$fJson,$input['created_by']??null,$incId]);
        } else {
            sqlsrv_query($conn_adl, "INSERT INTO jatoc_reports (incident_id,report_number,facility,facility_type,status,trigger_code,trigger_desc,incident_start_utc,incident_closeout_utc,initial_remarks,updates_json,timeline_json,full_report_json,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
                [$incId,$rptNum,$inc['facility'],$inc['facility_type'],$inc['status'],$inc['trigger_code'],$inc['trigger_desc'],formatDT($inc['start_utc']),formatDT($inc['closeout_utc']),$inc['remarks'],$uJson,$tJson,$fJson,$input['created_by']??null]);
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

function genReportData($conn, $id, $TRIGGERS, $STATUS_NAMES) {
    $stmt = sqlsrv_query($conn, "SELECT * FROM jatoc_incidents WHERE id = ?", [$id]);
    $inc = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    if (!$inc) return null;
    
    $updStmt = sqlsrv_query($conn, "SELECT * FROM jatoc_incident_updates WHERE incident_id = ? ORDER BY created_utc ASC", [$id]);
    $updates = [];
    while ($row = sqlsrv_fetch_array($updStmt, SQLSRV_FETCH_ASSOC)) {
        $updates[] = ['id'=>$row['id'],'type'=>$row['update_type'],'remarks'=>$row['remarks'],'created_by'=>$row['created_by'],'timestamp'=>formatDT($row['created_utc'])];
    }
    
    $timeline = [['event'=>'Incident Started','timestamp'=>formatDT($inc['start_utc']),'by'=>$inc['created_by']]];
    foreach ($updates as $u) $timeline[] = ['event'=>$u['type'],'timestamp'=>$u['timestamp'],'by'=>$u['created_by'],'details'=>$u['remarks']];
    if ($inc['closeout_utc']) $timeline[] = ['event'=>'Incident Closed','timestamp'=>formatDT($inc['closeout_utc']),'by'=>$inc['updated_by']];
    
    return [
        'report_number'=>$inc['report_number'],'incident_number'=>$inc['incident_number'],'generated_at'=>gmdate('Y-m-d H:i:s').'Z',
        'incident'=>['id'=>$inc['id'],'facility'=>$inc['facility'],'facility_type'=>$inc['facility_type'],'status'=>$inc['status'],'status_name'=>$STATUS_NAMES[$inc['status']]??$inc['status'],'trigger_code'=>$inc['trigger_code'],'trigger_description'=>$TRIGGERS[$inc['trigger_code']]??$inc['trigger_desc'],'paged'=>(bool)$inc['paged'],'incident_status'=>$inc['incident_status'],'remarks'=>$inc['remarks'],'created_by'=>$inc['created_by'],'updated_by'=>$inc['updated_by']],
        'timing'=>['start_utc'=>formatDT($inc['start_utc']),'last_update_utc'=>formatDT($inc['update_utc']),'closeout_utc'=>formatDT($inc['closeout_utc']),'duration'=>calcDur($inc['start_utc'],$inc['closeout_utc'])],
        'updates'=>$updates,'timeline'=>$timeline,
        'full_report'=>['header'=>['report_number'=>$inc['report_number'],'incident_number'=>$inc['incident_number'],'facility'=>$inc['facility'],'generated'=>gmdate('Y-m-d H:i:s').'Z'],'incident'=>['facility'=>$inc['facility'],'facility_type'=>$inc['facility_type'],'status'=>$STATUS_NAMES[$inc['status']]??$inc['status'],'trigger'=>$TRIGGERS[$inc['trigger_code']]??$inc['trigger_desc']??'Unknown','paged'=>$inc['paged']?'Yes':'No'],'timeline'=>$timeline,'updates'=>$updates,'remarks'=>$inc['remarks']]
    ];
}

function genMD($r) {
    $f = $r['full_report']??$r;
    $md = "# JATOC Incident Report\n\n**Report:** ".($f['header']['report_number']??'N/A')." | **Incident:** ".($f['header']['incident_number']??'N/A')." | **Generated:** ".($f['header']['generated']??gmdate('c'))."\n\n";
    $md .= "## Details\n\n| Field | Value |\n|---|---|\n";
    $md .= "| Facility | ".($f['incident']['facility']??'-')." (".($f['incident']['facility_type']??'-').") |\n";
    $md .= "| Status | ".($f['incident']['status']??'-')." |\n| Trigger | ".($f['incident']['trigger']??'-')." |\n| Paged | ".($f['incident']['paged']??'-')." |\n\n";
    $md .= "## Timeline\n\n";
    foreach (($f['timeline']??[]) as $t) $md .= "- **".$t['timestamp']."** ".$t['event'].(!empty($t['by'])?" (".$t['by'].")":"").(!empty($t['details'])?": ".$t['details']:"")."\n";
    $md .= "\n## Remarks\n\n".($f['remarks']??'None')."\n";
    return $md;
}

function genTXT($r) {
    $f = $r['full_report']??$r;
    $t = "=== JATOC INCIDENT REPORT ===\nReport: ".($f['header']['report_number']??'N/A')."\nIncident: ".($f['header']['incident_number']??'N/A')."\nGenerated: ".($f['header']['generated']??gmdate('c'))."\n\n";
    $t .= "DETAILS\nFacility: ".($f['incident']['facility']??'-')." (".($f['incident']['facility_type']??'-').")\nStatus: ".($f['incident']['status']??'-')."\nTrigger: ".($f['incident']['trigger']??'-')."\nPaged: ".($f['incident']['paged']??'-')."\n\n";
    $t .= "TIMELINE\n";
    foreach (($f['timeline']??[]) as $e) $t .= $e['timestamp']." | ".$e['event'].(!empty($e['by'])?" (".$e['by'].")":"").(!empty($e['details'])?"\n  -> ".$e['details']:"")."\n";
    $t .= "\nREMARKS\n".($f['remarks']??'None')."\n";
    return $t;
}
