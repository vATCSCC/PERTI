<?php
/**
 * Get PERTI Plan Info for Statsim Integration
 * 
 * Returns event date, start time, and airports for a given plan ID.
 * Used to auto-populate the Statsim traffic data form.
 * Airport names are fetched from VATSIM_ADL.dbo.apts for standardization.
 * 
 * GET Parameters:
 *   id: Plan ID (required)
 */

header('Content-Type: application/json');

include("../../sessions/handler.php");
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

include("../../load/config.php");
include("../../load/connect.php");

// Get plan ID
$plan_id = isset($_GET['id']) ? get_int('id') : 0;

if ($plan_id <= 0) {
    echo json_encode([
        'success' => false,
        'error' => 'Invalid or missing plan ID'
    ]);
    exit;
}

// Get plan info
$plan_query = $conn_sqli->query("SELECT id, event_name, event_date, event_start FROM p_plans WHERE id = $plan_id");

if (!$plan_query || $plan_query->num_rows === 0) {
    echo json_encode([
        'success' => false,
        'error' => 'Plan not found'
    ]);
    exit;
}

$plan = $plan_query->fetch_assoc();

// Get airports from terminal inits
$airports_query = $conn_sqli->query("SELECT title FROM p_terminal_init WHERE p_id = $plan_id ORDER BY id");
$airport_icaos = [];
while ($apt_row = $airports_query->fetch_assoc()) {
    $apt = strtoupper(trim($apt_row['title']));
    // Validate ICAO format (4 letters)
    if (preg_match('/^[A-Z]{4}$/', $apt)) {
        $airport_icaos[] = $apt;
    }
}

// Fetch airport names from VATSIM_ADL.dbo.apts for standardization
$airport_details = [];
if (!empty($airport_icaos) && isset($conn_adl)) {
    $icao_list = "'" . implode("','", $airport_icaos) . "'";
    $apt_sql = "SELECT ICAO_ID, ARPT_NAME FROM dbo.apts WHERE ICAO_ID IN ($icao_list)";
    $apt_result = sqlsrv_query($conn_adl, $apt_sql);
    
    if ($apt_result) {
        while ($row = sqlsrv_fetch_array($apt_result, SQLSRV_FETCH_ASSOC)) {
            $airport_details[$row['ICAO_ID']] = $row['ARPT_NAME'];
        }
        sqlsrv_free_stmt($apt_result);
    }
}

// Build airports array with names
$airports = [];
foreach ($airport_icaos as $icao) {
    $airports[] = [
        'icao' => $icao,
        'name' => $airport_details[$icao] ?? $icao  // Fallback to ICAO if not found
    ];
}

// Parse event date and time
$event_date = $plan['event_date'];
$event_start = $plan['event_start'];

// Normalize event_start to HH:mm format
$event_start_normalized = $event_start;
if (strlen($event_start) === 4 && strpos($event_start, ':') === false) {
    $event_start_normalized = substr($event_start, 0, 2) . ':' . substr($event_start, 2, 2);
}

// Create DateTime for calculations
try {
    $event_datetime_str = $event_date . ' ' . $event_start_normalized;
    $event_dt = new DateTime($event_datetime_str, new DateTimeZone('UTC'));
    
    // Calculate H+0: Round to nearest hour based on minutes
    // If minutes >= 30, round up to next hour; otherwise round down
    $h0_dt = clone $event_dt;
    $minutes = intval($h0_dt->format('i'));
    if ($minutes >= 30) {
        $h0_dt->modify('+1 hour');
    }
    $h0_dt->setTime($h0_dt->format('H'), 0, 0); // Snap to :00
    
    // Calculate default time range: T-1 hour to T+6 hours
    $from_dt = clone $event_dt;
    $from_dt->modify('-1 hour');
    $from_dt->setTime($from_dt->format('H'), 0, 0); // Snap to :00
    
    $to_dt = clone $event_dt;
    $to_dt->modify('+6 hours');
    $to_dt->setTime($to_dt->format('H'), 0, 0); // Snap to :00
    
    $defaults = [
        'airports' => implode(', ', $airport_icaos),
        'from' => $from_dt->format('Y-m-d H:i'),
        'to' => $to_dt->format('Y-m-d H:i'),
        'event_datetime' => $event_dt->format('Y-m-d H:i'),
        'h0_datetime' => $h0_dt->format('Y-m-d\TH:i:s\Z'),  // ISO format for JS parsing
        'h0_display' => $h0_dt->format('d/Hi') . 'Z'  // DD/HHMMZ display format
    ];
} catch (Exception $e) {
    $defaults = [
        'airports' => implode(', ', $airport_icaos),
        'from' => '',
        'to' => '',
        'event_datetime' => '',
        'h0_datetime' => '',
        'h0_display' => '',
        'parse_error' => $e->getMessage()
    ];
}

// Build Statsim URL
$statsim_url = '';
if (!empty($defaults['airports']) && !empty($defaults['from']) && !empty($defaults['to'])) {
    $statsim_url = 'https://statsim.net/events/custom/?' . http_build_query([
        'airports' => str_replace(' ', '', $defaults['airports']),
        'period' => 'custom',
        'from' => $defaults['from'],
        'to' => $defaults['to']
    ]);
}

echo json_encode([
    'success' => true,
    'plan' => [
        'id' => intval($plan['id']),
        'event_name' => $plan['event_name'],
        'event_date' => $event_date,
        'event_start' => $event_start,
        'event_start_normalized' => $event_start_normalized
    ],
    'airports' => $airports,  // Now includes names from VATSIM_ADL.dbo.apts
    'defaults' => $defaults,
    'statsim_url' => $statsim_url
]);
