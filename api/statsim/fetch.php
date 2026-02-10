<?php
/**
 * Statsim Traffic Data API
 * 
 * Fetches hourly arrivals/departures data from statsim.net for event analysis.
 * 
 * GET Parameters:
 *   airports: Comma-separated ICAO codes (required)
 *   from: Start datetime "YYYY-MM-DD HH:mm" (required)
 *   to: End datetime "YYYY-MM-DD HH:mm" (required)
 *   plan_id: Optional PERTI plan ID to auto-populate parameters
 * 
 * POST: Same parameters in JSON body, or just plan_id to use defaults
 */

header('Content-Type: application/json');

include("../../sessions/handler.php");
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

include("../../load/config.php");
include("../../load/connect.php");

// Get request method
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// Parse parameters
$params = [];
if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $params = $input ?: $_POST;
} else {
    $params = $_GET;
}

// If plan_id is provided, fetch plan details to auto-populate
if (isset($params['plan_id']) && !empty($params['plan_id'])) {
    $plan_id = intval($params['plan_id']);
    
    // Get plan info
    $plan_query = $conn_sqli->query("SELECT event_date, event_start FROM p_plans WHERE id = $plan_id");
    if ($plan_query && $plan_row = $plan_query->fetch_assoc()) {
        
        // Get airports from terminal inits
        $airports_query = $conn_sqli->query("SELECT title FROM p_terminal_init WHERE p_id = $plan_id");
        $airports = [];
        while ($apt_row = $airports_query->fetch_assoc()) {
            $apt = strtoupper(trim($apt_row['title']));
            // Validate ICAO format
            if (preg_match('/^[A-Z]{4}$/', $apt)) {
                $airports[] = $apt;
            }
        }
        
        // Parse event date and time
        $event_date = $plan_row['event_date']; // Expected format: YYYY-MM-DD or similar
        $event_start = $plan_row['event_start']; // Expected format: HHmm or HH:mm
        
        // Normalize event_start to HH:mm
        if (strlen($event_start) === 4 && strpos($event_start, ':') === false) {
            $event_start = substr($event_start, 0, 2) . ':' . substr($event_start, 2, 2);
        }
        
        // Create DateTime object for event start
        $event_datetime_str = $event_date . ' ' . $event_start;
        $event_dt = new DateTime($event_datetime_str, new DateTimeZone('UTC'));
        
        // Calculate time range: T-1 hour to max(T+6 hours, event_end+2 hours)
        // Since we don't have event_end, default to T+6 hours
        $from_dt = clone $event_dt;
        $from_dt->modify('-1 hour');
        
        $to_dt = clone $event_dt;
        $to_dt->modify('+6 hours');
        
        // Check if custom end time provided
        if (isset($params['event_duration']) && intval($params['event_duration']) > 0) {
            $duration_hours = intval($params['event_duration']);
            $custom_end = clone $event_dt;
            $custom_end->modify("+{$duration_hours} hours");
            $custom_end->modify('+2 hours'); // Add 2 hours after event end
            
            if ($custom_end > $to_dt) {
                $to_dt = $custom_end;
            }
        }
        
        // Snap to :00 times
        $from_dt->setTime($from_dt->format('H'), 0, 0);
        $to_dt->setTime($to_dt->format('H'), 0, 0);
        
        // Set defaults if not overridden
        if (empty($params['airports']) && count($airports) > 0) {
            $params['airports'] = implode(',', $airports);
        }
        if (empty($params['from'])) {
            $params['from'] = $from_dt->format('Y-m-d H:i');
        }
        if (empty($params['to'])) {
            $params['to'] = $to_dt->format('Y-m-d H:i');
        }
        
        // Store plan info for response
        $params['_plan_info'] = [
            'id' => $plan_id,
            'event_date' => $event_date,
            'event_start' => $plan_row['event_start'],
            'airports_found' => $airports
        ];
    }
}

// Validate required parameters
if (empty($params['airports'])) {
    echo json_encode([
        'success' => false,
        'error' => true,
        'message' => 'Missing required parameter: airports'
    ]);
    exit;
}

if (empty($params['from'])) {
    echo json_encode([
        'success' => false,
        'error' => true,
        'message' => 'Missing required parameter: from'
    ]);
    exit;
}

if (empty($params['to'])) {
    echo json_encode([
        'success' => false,
        'error' => true,
        'message' => 'Missing required parameter: to'
    ]);
    exit;
}

// Sanitize parameters
$airports = preg_replace('/[^A-Za-z,\s]/', '', $params['airports']);
$from = preg_replace('/[^0-9\-:\s]/', '', $params['from']);
$to = preg_replace('/[^0-9\-:\s]/', '', $params['to']);

// Build Statsim URL for reference (path-based format)
$fromISO = str_replace(' ', 'T', $from);
$toISO = str_replace(' ', 'T', $to);
$statsim_url = 'https://statsim.net/events/custom/' . rawurlencode($fromISO) . '/' . rawurlencode($toISO) . '/' . $airports;

// Check if Node.js and Puppeteer are available
$node_check = shell_exec('which node 2>/dev/null');
$has_node = !empty(trim($node_check));

if (!$has_node) {
    // Return URL-only response if scraping not available
    echo json_encode([
        'success' => true,
        'scraping_available' => false,
        'message' => 'Headless browser not available on this server. Use the Statsim URL directly.',
        'statsim_url' => $statsim_url,
        'parameters' => [
            'airports' => $airports,
            'from' => $from,
            'to' => $to
        ],
        'plan_info' => $params['_plan_info'] ?? null
    ]);
    exit;
}

// Check for Puppeteer - installed in /home/puppeteer-scripts to avoid wwwroot permission issues
$script_path = '/home/puppeteer-scripts/statsim_scraper.js';
if (!file_exists($script_path)) {
    echo json_encode([
        'success' => true,
        'scraping_available' => false,
        'message' => 'Scraper script not found. Use the Statsim URL directly.',
        'statsim_url' => $statsim_url,
        'parameters' => [
            'airports' => $airports,
            'from' => $from,
            'to' => $to
        ]
    ]);
    exit;
}

// Execute the scraper
$cmd = sprintf(
    'node %s %s %s %s 2>&1',
    escapeshellarg($script_path),
    escapeshellarg($airports),
    escapeshellarg($from),
    escapeshellarg($to)
);

$output = shell_exec($cmd);
$result = json_decode($output, true);

if ($result === null) {
    echo json_encode([
        'success' => false,
        'error' => 'Failed to parse scraper output',
        'raw_output' => substr($output, 0, 1000),
        'statsim_url' => $statsim_url
    ]);
    exit;
}

// Add metadata to result
$result['success'] = !isset($result['error']) || $result['error'] !== true;
$result['statsim_url'] = $statsim_url;
$result['parameters'] = [
    'airports' => $airports,
    'from' => $from,
    'to' => $to
];
$result['plan_info'] = $params['_plan_info'] ?? null;

echo json_encode($result);
