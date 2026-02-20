<?php
/**
 * Terminal Initiative Timeline API
 */

// Suppress errors from appearing in JSON output
error_reporting(0);
ini_set('display_errors', 0);

// Start session
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Set JSON header
header('Content-Type: application/json');

// Include config
$config_path = __DIR__ . "/../../../load/config.php";
$connect_path = __DIR__ . "/../../../load/connect.php";

if (!file_exists($config_path) || !file_exists($connect_path)) {
    http_response_code(500);
    echo json_encode(['error' => 'Configuration files not found']);
    exit;
}

include($config_path);
define('PERTI_MYSQL_ONLY', true);
include($connect_path);

// Check database connection
if (!isset($conn_sqli) || $conn_sqli->connect_error) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed']);
    exit;
}

// Check permissions
$perm = false;
$cid = '0';
if (defined('DEV') && DEV) {
    $perm = true;
} elseif (isset($_SESSION['VATSIM_CID'])) {
    $cid = session_get('VATSIM_CID', '');
    $p_check = $conn_sqli->query("SELECT * FROM users WHERE cid='$cid'");
    if ($p_check && $p_check->num_rows > 0) {
        $perm = true;
    }
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

require_once(dirname(__DIR__, 3) . '/load/org_context.php');

// Valid levels and TMI types
$levels = [
    'CDW' => true, 'Possible' => true, 'Probable' => true, 'Expected' => true, 'Active' => true,
    'Advisory_Terminal' => true, 'Advisory_EnRoute' => true, 'Special_Event' => true,
    'Space_Op' => true, 'VIP' => true, 'Staffing' => true, 'Misc' => true,
    'Constraint_Terminal' => true, 'Constraint_EnRoute' => true
];

$tmi_types = [
    'GS', 'GDP', 'MIT', 'MINIT', 'CFR', 'APREQ', 'Reroute', 'AFP', 'FEA', 'FCA',
    'CTOP', 'ICR', 'TBO', 'Metering', 'TBM', 'TBFM', 'Other',
    'VIP Arrival', 'VIP Departure', 'VIP Overflight', 'TFR',
    'Rocket Launch', 'Reentry', 'Launch Window', 'Hazard Area',
    'Day', 'Mid', 'Swing', 'All', 'CDW', 'Special Event', 'Misc',
    'Weather', 'Volume', 'Runway', 'Equipment', 'Construction', 'Staffing', 'Military', 'Airspace'
];

// Helper: Convert ISO datetime to MySQL format
function toMysql($dt) {
    if (!$dt) return null;
    // Handle ISO format with T and Z
    $dt = str_replace(['T', 'Z'], [' ', ''], $dt);
    // Remove milliseconds
    $dt = preg_replace('/\.\d+/', '', $dt);
    return trim($dt);
}

switch ($method) {
    case 'GET':
        $p_id = isset($_GET['p_id']) ? get_int('p_id') : 0;

        if ($p_id <= 0) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid plan ID']);
            exit;
        }

        if (!validate_plan_org($p_id, $conn_sqli)) {
            http_response_code(403);
            echo json_encode(['error' => 'Access denied']);
            exit;
        }

        // Get plan-specific items + global items from all other plans
        $sql = "SELECT t.*, p.event_name AS source_plan_name
                FROM p_terminal_init_timeline t
                LEFT JOIN p_plans p ON t.p_id = p.id
                WHERE t.p_id = ? OR (t.is_global = 1 AND t.p_id != ?)
                ORDER BY t.start_datetime ASC";
        $stmt = $conn_sqli->prepare($sql);
        if (!$stmt) {
            http_response_code(500);
            echo json_encode(['error' => 'Query preparation failed: ' . $conn_sqli->error]);
            exit;
        }
        $stmt->bind_param("ii", $p_id, $p_id);

        if (!$stmt->execute()) {
            http_response_code(500);
            echo json_encode(['error' => 'Query execution failed: ' . $stmt->error]);
            exit;
        }

        $result = $stmt->get_result();
        $data = [];

        while ($row = $result->fetch_assoc()) {
            $from_other = intval($row['p_id']) !== $p_id;
            $item = [
                'id' => intval($row['id']),
                'p_id' => intval($row['p_id']),
                'facility' => $row['facility'],
                'area' => isset($row['area']) ? $row['area'] : null,
                'tmi_type' => $row['tmi_type'],
                'tmi_type_other' => isset($row['tmi_type_other']) ? $row['tmi_type_other'] : null,
                'cause' => isset($row['cause']) ? $row['cause'] : null,
                'start_datetime' => $row['start_datetime'],
                'end_datetime' => $row['end_datetime'],
                'level' => $row['level'],
                'notes' => isset($row['notes']) ? $row['notes'] : null,
                'created_at' => isset($row['created_at']) ? $row['created_at'] : null,
                'updated_at' => isset($row['updated_at']) ? $row['updated_at'] : null,
                'created_by' => isset($row['created_by']) ? $row['created_by'] : null,
                'is_global' => isset($row['is_global']) ? intval($row['is_global']) : 0,
                'advzy_number' => isset($row['advzy_number']) ? $row['advzy_number'] : null,
                'from_other_plan' => $from_other,
            ];
            if ($from_other && isset($row['source_plan_name'])) {
                $item['source_plan_name'] = $row['source_plan_name'];
            }
            $data[] = $item;
        }

        $stmt->close();
        echo json_encode(['success' => true, 'data' => $data]);
        break;
        
    case 'POST':
        if (!$perm) {
            http_response_code(403);
            echo json_encode(['error' => 'Permission denied']);
            exit;
        }
        
        $input = json_decode(file_get_contents('php://input'), true);
        if (!$input) $input = $_POST;
        
        // Validate required
        foreach (['p_id', 'facility', 'tmi_type', 'start_datetime', 'end_datetime', 'level'] as $field) {
            if (empty($input[$field])) {
                http_response_code(400);
                echo json_encode(['error' => "Missing required field: $field"]);
                exit;
            }
        }
        
        $p_id = intval($input['p_id']);

        if (!validate_plan_org($p_id, $conn_sqli)) {
            http_response_code(403);
            echo json_encode(['error' => 'Access denied']);
            exit;
        }

        $facility = $conn_sqli->real_escape_string(trim($input['facility']));
        $area = isset($input['area']) ? $conn_sqli->real_escape_string(trim($input['area'])) : null;
        $tmi_type = $conn_sqli->real_escape_string(trim($input['tmi_type']));
        $tmi_type_other = isset($input['tmi_type_other']) ? $conn_sqli->real_escape_string(trim($input['tmi_type_other'])) : null;
        $cause = isset($input['cause']) ? $conn_sqli->real_escape_string(trim($input['cause'])) : null;
        $start_datetime = toMysql($input['start_datetime']);
        $end_datetime = toMysql($input['end_datetime']);
        $level = $conn_sqli->real_escape_string(trim($input['level']));
        $notes = isset($input['notes']) ? $conn_sqli->real_escape_string(trim($input['notes'])) : null;
        $is_global = isset($input['is_global']) ? intval($input['is_global']) : 0;
        $advzy_number = isset($input['advzy_number']) ? $conn_sqli->real_escape_string(trim($input['advzy_number'])) : null;

        // Validate
        if (!isset($levels[$level])) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid level']);
            exit;
        }
        
        if (!in_array($tmi_type, $tmi_types)) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid TMI type: ' . $tmi_type]);
            exit;
        }
        
        $sql = "INSERT INTO p_terminal_init_timeline 
                (p_id, facility, area, tmi_type, tmi_type_other, cause, start_datetime, end_datetime, level, notes, is_global, advzy_number, created_by) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        
        $stmt = $conn_sqli->prepare($sql);
        if (!$stmt) {
            http_response_code(500);
            echo json_encode(['error' => 'Prepare failed: ' . $conn_sqli->error]);
            exit;
        }
        
        $stmt->bind_param("isssssssssiss", $p_id, $facility, $area, $tmi_type, $tmi_type_other, $cause, $start_datetime, $end_datetime, $level, $notes, $is_global, $advzy_number, $cid);
        
        if ($stmt->execute()) {
            $new_id = $conn_sqli->insert_id;
            echo json_encode(['success' => true, 'id' => $new_id]);
        } else {
            http_response_code(500);
            echo json_encode(['error' => 'Insert failed: ' . $stmt->error]);
        }
        $stmt->close();
        break;
        
    case 'PUT':
        if (!$perm) {
            http_response_code(403);
            echo json_encode(['error' => 'Permission denied']);
            exit;
        }
        
        $input = json_decode(file_get_contents('php://input'), true);
        
        if (empty($input['id'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Missing ID']);
            exit;
        }
        
        $id = intval($input['id']);
        $facility = $conn_sqli->real_escape_string(trim($input['facility']));
        $area = isset($input['area']) ? $conn_sqli->real_escape_string(trim($input['area'])) : null;
        $tmi_type = $conn_sqli->real_escape_string(trim($input['tmi_type']));
        $tmi_type_other = isset($input['tmi_type_other']) ? $conn_sqli->real_escape_string(trim($input['tmi_type_other'])) : null;
        $cause = isset($input['cause']) ? $conn_sqli->real_escape_string(trim($input['cause'])) : null;
        $start_datetime = toMysql($input['start_datetime']);
        $end_datetime = toMysql($input['end_datetime']);
        $level = $conn_sqli->real_escape_string(trim($input['level']));
        $notes = isset($input['notes']) ? $conn_sqli->real_escape_string(trim($input['notes'])) : null;
        $is_global = isset($input['is_global']) ? intval($input['is_global']) : 0;
        $advzy_number = isset($input['advzy_number']) ? $conn_sqli->real_escape_string(trim($input['advzy_number'])) : null;
        
        $sql = "UPDATE p_terminal_init_timeline SET 
                facility=?, area=?, tmi_type=?, tmi_type_other=?, cause=?, 
                start_datetime=?, end_datetime=?, level=?, notes=?, is_global=?, advzy_number=?
                WHERE id=?";
        
        $stmt = $conn_sqli->prepare($sql);
        if (!$stmt) {
            http_response_code(500);
            echo json_encode(['error' => 'Prepare failed: ' . $conn_sqli->error]);
            exit;
        }
        
        $stmt->bind_param("sssssssssisi", $facility, $area, $tmi_type, $tmi_type_other, $cause, $start_datetime, $end_datetime, $level, $notes, $is_global, $advzy_number, $id);
        
        if ($stmt->execute()) {
            echo json_encode(['success' => true]);
        } else {
            http_response_code(500);
            echo json_encode(['error' => 'Update failed: ' . $stmt->error]);
        }
        $stmt->close();
        break;
        
    case 'DELETE':
        if (!$perm) {
            http_response_code(403);
            echo json_encode(['error' => 'Permission denied']);
            exit;
        }
        
        $id = isset($_GET['id']) ? get_int('id') : 0;
        
        if ($id <= 0) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid ID']);
            exit;
        }
        
        $stmt = $conn_sqli->prepare("DELETE FROM p_terminal_init_timeline WHERE id = ?");
        $stmt->bind_param("i", $id);
        
        if ($stmt->execute()) {
            echo json_encode(['success' => true]);
        } else {
            http_response_code(500);
            echo json_encode(['error' => 'Delete failed: ' . $stmt->error]);
        }
        $stmt->close();
        break;
        
    default:
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
}
?>
