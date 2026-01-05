<?php
/**
 * JATOC Incidents API - GET list / POST create
 * GET: Public access
 * POST: Requires VATSIM auth
 */
header('Content-Type: application/json');

include("../../sessions/handler.php");
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
include("../../load/config.php");
include("../../load/connect.php");

$method = $_SERVER['REQUEST_METHOD'];

// POST requires authentication
if ($method === 'POST' && !isset($_SESSION['VATSIM_CID'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Authentication required']);
    exit;
}

try {
    if ($method === 'GET') {
        // Public access for viewing
        $where = ['1=1'];
        $params = [];
        
        if (!empty($_GET['status'])) {
            $where[] = 'incident_status = ?';
            $params[] = $_GET['status'];
        }
        if (!empty($_GET['facilityType'])) {
            $where[] = 'facility_type = ?';
            $params[] = $_GET['facilityType'];
        }
        if (!empty($_GET['incidentType'])) {
            $where[] = 'status = ?';
            $params[] = $_GET['incidentType'];
        }
        if (!empty($_GET['facility'])) {
            $where[] = 'facility LIKE ?';
            $params[] = '%' . $_GET['facility'] . '%';
        }
        // Additional search params for retrieve functionality
        if (!empty($_GET['incident_number'])) {
            $where[] = 'incident_number LIKE ?';
            $params[] = '%' . $_GET['incident_number'] . '%';
        }
        if (!empty($_GET['report_number'])) {
            $where[] = 'report_number LIKE ?';
            $params[] = '%' . $_GET['report_number'] . '%';
        }
        if (!empty($_GET['from_date'])) {
            $where[] = 'start_utc >= ?';
            $params[] = $_GET['from_date'] . ' 00:00:00';
        }
        if (!empty($_GET['to_date'])) {
            $where[] = 'start_utc <= ?';
            $params[] = $_GET['to_date'] . ' 23:59:59';
        }
        
        $sql = "SELECT * FROM jatoc_incidents WHERE " . implode(' AND ', $where) . " ORDER BY start_utc DESC";
        $stmt = sqlsrv_query($conn_adl, $sql, $params);
        if ($stmt === false) throw new Exception('Query failed');
        
        $incidents = [];
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            foreach (['start_utc', 'update_utc', 'closeout_utc', 'created_at', 'updated_at'] as $field) {
                if (isset($row[$field]) && $row[$field] instanceof DateTime) {
                    $row[$field] = $row[$field]->format('Y-m-d H:i:s');
                }
            }
            $incidents[] = $row;
        }
        
        echo json_encode(['success' => true, 'data' => $incidents]);
        
    } elseif ($method === 'POST') {
        // Creating incidents - no session auth required, but profile checked client-side
        $input = json_decode(file_get_contents('php://input'), true);
        
        $triggers = [
            'A'=>'AFV (Audio for VATSIM)', 'B'=>'Other Audio Issue', 'C'=>'Multiple Audio Issues',
            'D'=>'Datafeed (VATSIM)', 'E'=>'Datafeed (Other)', 'F'=>'Frequency Issue',
            'H'=>'Radar Client Issue', 'J'=>'Staffing (Below Min)', 'K'=>'Staffing (At Min)',
            'M'=>'Staffing (None)', 'Q'=>'Other', 'R'=>'Pilot Issue', 'S'=>'Security (RW)',
            'T'=>'Security (VATSIM)', 'U'=>'Unknown', 'V'=>'Volume', 'W'=>'Weather'
        ];
        $triggerDesc = $triggers[$input['trigger_code'] ?? ''] ?? null;
        
        $startUtc = null;
        if (!empty($input['start_utc'])) {
            $startUtc = str_replace('T', ' ', $input['start_utc']);
            if (strlen($startUtc) === 16) $startUtc .= ':00';
        }
        
        $incidentNumber = null;
        $incNumStmt = sqlsrv_query($conn_adl, "DECLARE @num VARCHAR(12); EXEC sp_jatoc_next_incident_number @num OUTPUT; SELECT @num AS incident_number;");
        if ($incNumStmt) {
            $row = sqlsrv_fetch_array($incNumStmt, SQLSRV_FETCH_ASSOC);
            $incidentNumber = $row['incident_number'] ?? null;
        }
        
        $sql = "INSERT INTO jatoc_incidents (incident_number, facility, facility_type, status, trigger_code, trigger_desc, paged, start_utc, remarks, created_by, incident_status)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        
        $params = [
            $incidentNumber,
            strtoupper($input['facility']),
            $input['facility_type'] ?? null,
            $input['status'],
            $input['trigger_code'] ?? null,
            $triggerDesc,
            $input['paged'] ? 1 : 0,
            $startUtc,
            $input['remarks'] ?? null,
            $input['created_by'],
            $input['incident_status'] ?? 'ACTIVE'
        ];
        
        $stmt = sqlsrv_query($conn_adl, $sql, $params);
        if ($stmt === false) throw new Exception('Insert failed: ' . print_r(sqlsrv_errors(), true));
        
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
