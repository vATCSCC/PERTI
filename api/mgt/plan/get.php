<?php
/**
 * PERTI Plan Get API
 *
 * Fetches PERTI Plan data for import into Ops Plan advisory.
 * Supports lookup by date, plan ID, or event name.
 *
 * GET /api/mgt/plan/get.php
 * Parameters:
 *   - date: Plan date (YYYY-MM-DD) - returns first matching plan
 *   - id: Plan ID - returns specific plan
 *   - event: Event name search (partial match)
 *   - list: '1' to return list of available plans
 *
 * @package PERTI
 * @subpackage API/MGT/Plan
 * @version 1.0.0
 * @date 2026-01-28
 */

header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle CORS preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

// Load dependencies
try {
    require_once __DIR__ . '/../../../load/config.php';
    require_once __DIR__ . '/../../../load/connect.php';
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Config load error']);
    exit;
}

// Check if we should return a list of plans
if (isset($_GET['list']) && $_GET['list'] === '1') {
    returnPlanList($conn_sqli);
    exit;
}

// Get parameters
$planDate = $_GET['date'] ?? null;
$planId = isset($_GET['id']) ? intval($_GET['id']) : null;
$eventSearch = $_GET['event'] ?? null;

if (!$planDate && !$planId && !$eventSearch) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Missing parameter: date, id, or event required'
    ]);
    exit;
}

// Build query based on parameters
$query = null;
$params = [];

if ($planId) {
    // Get by specific ID
    $stmt = $conn_sqli->prepare("SELECT * FROM p_plans WHERE id = ?");
    $stmt->bind_param('i', $planId);
} elseif ($eventSearch) {
    // Search by event name
    $searchTerm = '%' . $eventSearch . '%';
    $stmt = $conn_sqli->prepare("SELECT * FROM p_plans WHERE event_name LIKE ? ORDER BY event_date DESC LIMIT 10");
    $stmt->bind_param('s', $searchTerm);
} else {
    // Get by date
    $stmt = $conn_sqli->prepare("SELECT * FROM p_plans WHERE event_date = ? ORDER BY id DESC");
    $stmt->bind_param('s', $planDate);
}

$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    echo json_encode([
        'success' => true,
        'plan' => null,
        'message' => 'No plan found for the specified criteria'
    ]);
    exit;
}

// If searching by event, return multiple results
if ($eventSearch && $result->num_rows > 1) {
    $plans = [];
    while ($row = $result->fetch_assoc()) {
        $plans[] = formatPlanBasic($row);
    }
    echo json_encode([
        'success' => true,
        'plans' => $plans,
        'count' => count($plans)
    ]);
    exit;
}

// For date or ID lookup, return full plan details
$planRow = $result->fetch_assoc();
$plan = buildFullPlanData($conn_sqli, $planRow);

echo json_encode([
    'success' => true,
    'plan' => $plan
]);

// ===========================================
// Helper Functions
// ===========================================

/**
 * Return list of available plans
 */
function returnPlanList($conn) {
    $query = $conn->query("SELECT id, event_name, event_date, event_start, hotline, oplevel FROM p_plans ORDER BY event_date DESC LIMIT 50");

    $plans = [];
    while ($row = $query->fetch_assoc()) {
        $plans[] = formatPlanBasic($row);
    }

    echo json_encode([
        'success' => true,
        'plans' => $plans,
        'count' => count($plans)
    ]);
}

/**
 * Format basic plan info
 */
function formatPlanBasic($row) {
    return [
        'id' => intval($row['id']),
        'eventName' => $row['event_name'],
        'eventDate' => $row['event_date'],
        'eventStart' => $row['event_start'],
        'hotline' => $row['hotline'] ?? null,
        'opLevel' => intval($row['oplevel'] ?? 1)
    ];
}

/**
 * Build full plan data including TMIs, weather, events
 */
function buildFullPlanData($conn, $row) {
    $planId = intval($row['id']);

    $plan = [
        'id' => $planId,
        'eventName' => $row['event_name'],
        'eventDate' => $row['event_date'],
        'eventStart' => $row['event_start'],
        'eventEndDate' => $row['event_end_date'] ?? null,
        'eventEndTime' => $row['event_end_time'] ?? null,
        'hotline' => $row['hotline'] ?? null,
        'opLevel' => intval($row['oplevel'] ?? 1),
        'eventBanner' => $row['event_banner'] ?? null,
        'tmis' => [],
        'weather' => [],
        'constraints' => [],
        'events' => []
    ];

    // Get Terminal Initiatives
    $termQuery = $conn->query("SELECT * FROM p_terminal_init WHERE p_id = $planId ORDER BY id");
    while ($t = $termQuery->fetch_assoc()) {
        $plan['tmis'][] = [
            'type' => 'terminal',
            'airport' => $t['title'],
            'program' => $t['program'] ?? null,
            'rate' => $t['rate'] ?? null,
            'scope' => $t['scope'] ?? null,
            'notes' => $t['notes'] ?? null
        ];
    }

    // Get Enroute Initiatives
    $enrouteQuery = $conn->query("SELECT * FROM p_enroute_init WHERE p_id = $planId ORDER BY id");
    while ($e = $enrouteQuery->fetch_assoc()) {
        $plan['tmis'][] = [
            'type' => 'enroute',
            'element' => $e['title'] ?? null,
            'restriction' => $e['restriction'] ?? null,
            'scope' => $e['scope'] ?? null,
            'notes' => $e['notes'] ?? null
        ];
    }

    // Get Weather constraints (from terminal init notes or separate table if exists)
    // Try to parse weather info from notes
    foreach ($plan['tmis'] as $tmi) {
        if (!empty($tmi['notes']) && stripos($tmi['notes'], 'weather') !== false) {
            $plan['weather'][] = $tmi['notes'];
        }
    }

    // Get Special Events
    $eventsQuery = $conn->query("SELECT * FROM p_events WHERE p_id = $planId ORDER BY id");
    if ($eventsQuery) {
        while ($ev = $eventsQuery->fetch_assoc()) {
            $plan['events'][] = [
                'title' => $ev['title'] ?? null,
                'description' => $ev['description'] ?? null,
                'time' => $ev['event_time'] ?? null
            ];
        }
    }

    // Build formatted text summaries
    $plan['initiativesSummary'] = buildInitiativesSummary($plan['tmis']);
    $plan['constraintsSummary'] = buildConstraintsSummary($plan);
    $plan['eventsSummary'] = buildEventsSummary($plan['events']);

    // Calculate valid times
    $startDateTime = $row['event_date'] . 'T' . normalizeTime($row['event_start']);
    $endDateTime = null;
    if (!empty($row['event_end_date']) && !empty($row['event_end_time'])) {
        $endDateTime = $row['event_end_date'] . 'T' . normalizeTime($row['event_end_time']);
    } else {
        // Default to 4 hours after start
        $start = new DateTime($startDateTime);
        $start->modify('+4 hours');
        $endDateTime = $start->format('Y-m-d\TH:i');
    }
    $plan['validFrom'] = $startDateTime;
    $plan['validUntil'] = $endDateTime;

    return $plan;
}

/**
 * Build initiatives summary text
 */
function buildInitiativesSummary($tmis) {
    if (empty($tmis)) return '';

    $lines = [];
    foreach ($tmis as $tmi) {
        if ($tmi['type'] === 'terminal') {
            $line = $tmi['airport'];
            if (!empty($tmi['program'])) $line .= ' - ' . $tmi['program'];
            if (!empty($tmi['rate'])) $line .= ' (Rate: ' . $tmi['rate'] . ')';
            $lines[] = $line;
        } else {
            $line = $tmi['element'] ?? 'Enroute';
            if (!empty($tmi['restriction'])) $line .= ' - ' . $tmi['restriction'];
            $lines[] = $line;
        }
    }

    return implode("\n", $lines);
}

/**
 * Build constraints summary text
 */
function buildConstraintsSummary($plan) {
    $lines = [];

    if (!empty($plan['weather'])) {
        $lines[] = 'Weather: ' . implode(', ', $plan['weather']);
    }

    foreach ($plan['tmis'] as $tmi) {
        if (!empty($tmi['scope'])) {
            $lines[] = ($tmi['airport'] ?? $tmi['element']) . ': ' . $tmi['scope'];
        }
    }

    return implode("\n", $lines);
}

/**
 * Build events summary text
 */
function buildEventsSummary($events) {
    if (empty($events)) return '';

    $lines = [];
    foreach ($events as $ev) {
        $line = $ev['title'] ?? 'Event';
        if (!empty($ev['time'])) $line .= ' (' . $ev['time'] . 'Z)';
        if (!empty($ev['description'])) $line .= ' - ' . $ev['description'];
        $lines[] = $line;
    }

    return implode("\n", $lines);
}

/**
 * Normalize time string to HH:MM format
 */
function normalizeTime($time) {
    if (strlen($time) === 4 && strpos($time, ':') === false) {
        return substr($time, 0, 2) . ':' . substr($time, 2, 2);
    }
    return $time;
}
