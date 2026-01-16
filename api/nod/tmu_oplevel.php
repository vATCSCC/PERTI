<?php
/**
 * NOD TMU Operations Level API
 * 
 * Returns the TMU OpLevel from the current active PERTI Plan
 * A plan is "active" if NOW() falls between its start and end datetime
 * 
 * GET - Returns current TMU OpLevel (1-4)
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

// Include database connections
$config_path = realpath(__DIR__ . '/../../load/config.php');
$connect_path = realpath(__DIR__ . '/../../load/connect.php');

if ($config_path) include($config_path);
if ($connect_path) include($connect_path);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$result = [
    'tmu_oplevel' => null,
    'event_name' => null,
    'event_id' => null,
    'event_start' => null,
    'event_end' => null,
    'debug' => [
        'connection' => false,
        'query_error' => null,
        'current_utc' => gmdate('Y-m-d H:i:s')
    ],
    'generated_at' => gmdate('Y-m-d\TH:i:s\Z')
];

try {
    if (!isset($conn_sqli) || !$conn_sqli) {
        $result['debug']['connection'] = false;
        $result['debug']['query_error'] = 'Database connection not available';
        echo json_encode($result);
        exit;
    }
    
    $result['debug']['connection'] = true;
    
    // Get current UTC datetime
    $now_utc = gmdate('Y-m-d H:i:s');
    
    // Find active PERTI plan
    // A plan is active if:
    // - All date/time fields are present (fully bounded period required)
    // - event_date + event_start <= NOW
    // - event_end_date + event_end_time >= NOW
    $sql = "SELECT id, event_name, event_date, event_start, event_end_date, event_end_time, oplevel
            FROM p_plans 
            WHERE event_date IS NOT NULL
              AND event_start IS NOT NULL
              AND event_start != ''
              AND event_end_date IS NOT NULL
              AND event_end_date != ''
              AND event_end_time IS NOT NULL
              AND event_end_time != ''
              AND CONCAT(event_date, ' ', CONCAT(LEFT(event_start, 2), ':', RIGHT(event_start, 2), ':00')) <= ?
              AND CONCAT(event_end_date, ' ', CONCAT(LEFT(event_end_time, 2), ':', RIGHT(event_end_time, 2), ':00')) >= ?
            ORDER BY event_date DESC, event_start DESC
            LIMIT 1";
    
    $stmt = $conn_sqli->prepare($sql);
    if ($stmt) {
        $stmt->bind_param('ss', $now_utc, $now_utc);
        $stmt->execute();
        $query_result = $stmt->get_result();
        
        if ($row = $query_result->fetch_assoc()) {
            $result['tmu_oplevel'] = (int)$row['oplevel'];
            $result['event_name'] = $row['event_name'];
            $result['event_id'] = (int)$row['id'];
            
            // Format start datetime
            $start_time = $row['event_start'];
            $result['event_start'] = $row['event_date'] . 'T' . 
                substr($start_time, 0, 2) . ':' . substr($start_time, 2, 2) . ':00Z';
            
            // Format end datetime if available
            if (!empty($row['event_end_date']) && !empty($row['event_end_time'])) {
                $end_time = $row['event_end_time'];
                $result['event_end'] = $row['event_end_date'] . 'T' . 
                    substr($end_time, 0, 2) . ':' . substr($end_time, 2, 2) . ':00Z';
            }
        }
        
        $stmt->close();
    } else {
        $result['debug']['query_error'] = $conn_sqli->error;
    }
    
    echo json_encode($result);
    
} catch (Exception $e) {
    $result['debug']['query_error'] = $e->getMessage();
    echo json_encode($result);
}
