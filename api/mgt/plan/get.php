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

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 0); // Don't display, but capture

// Set custom error handler to catch fatal errors
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
});

// Shutdown function for uncaught errors
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json');
        }
        echo json_encode([
            'success' => false,
            'error' => 'Fatal error: ' . $error['message'],
            'file' => basename($error['file']),
            'line' => $error['line']
        ]);
    }
});

// Load dependencies
try {
    require_once __DIR__ . '/../../../load/config.php';
    require_once __DIR__ . '/../../../load/connect.php';
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Config load error: ' . $e->getMessage()]);
    exit;
} catch (Error $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Config error: ' . $e->getMessage()]);
    exit;
}

// Check MySQL connection
if (!isset($conn_sqli) || !$conn_sqli) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'MySQL database connection not available',
        'debug' => [
            'conn_sqli_set' => isset($conn_sqli),
            'sql_host_defined' => defined('SQL_HOST'),
            'sql_database_defined' => defined('SQL_DATABASE')
        ]
    ]);
    exit;
}

// Verify connection is working
if ($conn_sqli->connect_error) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'MySQL connection error: ' . $conn_sqli->connect_error]);
    exit;
}

// Check if p_plans table exists
$tableCheck = $conn_sqli->query("SHOW TABLES LIKE 'p_plans'");
if (!$tableCheck || $tableCheck->num_rows === 0) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Table p_plans does not exist in database',
        'debug' => [
            'database' => defined('SQL_DATABASE') ? SQL_DATABASE : 'unknown',
            'host' => defined('SQL_HOST') ? SQL_HOST : 'unknown'
        ]
    ]);
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
try {
    if ($planId) {
        // Get by specific ID
        $stmt = $conn_sqli->prepare("SELECT * FROM p_plans WHERE id = ?");
        if (!$stmt) {
            throw new Exception('Prepare failed: ' . $conn_sqli->error);
        }
        $stmt->bind_param('i', $planId);
    } elseif ($eventSearch) {
        // Search by event name
        $searchTerm = '%' . $eventSearch . '%';
        $stmt = $conn_sqli->prepare("SELECT * FROM p_plans WHERE event_name LIKE ? ORDER BY event_date DESC LIMIT 10");
        if (!$stmt) {
            throw new Exception('Prepare failed: ' . $conn_sqli->error);
        }
        $stmt->bind_param('s', $searchTerm);
    } else {
        // Get by date
        $stmt = $conn_sqli->prepare("SELECT * FROM p_plans WHERE event_date = ? ORDER BY id DESC");
        if (!$stmt) {
            throw new Exception('Prepare failed: ' . $conn_sqli->error);
        }
        $stmt->bind_param('s', $planDate);
    }

    if (!$stmt->execute()) {
        throw new Exception('Execute failed: ' . $stmt->error);
    }
    $result = $stmt->get_result();
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database query failed: ' . $e->getMessage()]);
    exit;
}

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

try {
    $plan = buildFullPlanData($conn_sqli, $planRow);

    echo json_encode([
        'success' => true,
        'plan' => $plan
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Error building plan data: ' . $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]);
}

// ===========================================
// Helper Functions
// ===========================================

/**
 * Return list of available plans
 */
function returnPlanList($conn) {
    try {
        $query = $conn->query("SELECT id, event_name, event_date, event_start, hotline, oplevel FROM p_plans ORDER BY event_date DESC LIMIT 50");

        if (!$query) {
            throw new Exception('Query failed: ' . $conn->error);
        }

        $plans = [];
        while ($row = $query->fetch_assoc()) {
            $plans[] = formatPlanBasic($row);
        }

        echo json_encode([
            'success' => true,
            'plans' => $plans,
            'count' => count($plans)
        ]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Database query failed: ' . $e->getMessage()]);
    }
}

/**
 * Check which related tables exist
 */
function checkTablesExist($conn) {
    static $cache = null;
    if ($cache !== null) return $cache;

    $tables = [
        'p_terminal_init', 'p_enroute_init',
        'p_terminal_init_timeline', 'p_enroute_init_timeline',
        'p_terminal_constraints', 'p_enroute_constraints',
        'p_group_flights', 'p_op_goals',
        'p_terminal_planning', 'p_enroute_planning',
    ];
    $cache = [];

    foreach ($tables as $table) {
        $result = $conn->query("SHOW TABLES LIKE '$table'");
        $cache[$table] = ($result && $result->num_rows > 0);
    }

    return $cache;
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
        'eventName' => $row['event_name'] ?? '',
        'eventDate' => $row['event_date'] ?? date('Y-m-d'),
        'eventStart' => $row['event_start'] ?? '0000',
        'eventEndDate' => $row['event_end_date'] ?? null,
        'eventEndTime' => $row['event_end_time'] ?? null,
        'hotline' => $row['hotline'] ?? null,
        'opLevel' => intval($row['oplevel'] ?? 1),
        'eventBanner' => $row['event_banner'] ?? null,
        'tmis' => [],
        'timeline' => [],
        'constraints' => [],
        'events' => [],
        'goals' => [],
        'planning' => [],
    ];

    $tablesExist = checkTablesExist($conn);

    // ---- Initiative Timeline (primary source) ----
    // p_terminal_init_timeline / p_enroute_init_timeline contain the actual
    // TMI entries shown on the plan page (facility, tmi_type, cause, times, level).
    if ($tablesExist['p_terminal_init_timeline']) {
        $q = $conn->query(
            "SELECT * FROM p_terminal_init_timeline WHERE p_id = $planId ORDER BY start_datetime, id"
        );
        if ($q) {
            while ($t = $q->fetch_assoc()) {
                $plan['timeline'][] = formatTimelineEntry($t, 'terminal');
            }
        }
    }
    if ($tablesExist['p_enroute_init_timeline']) {
        $q = $conn->query(
            "SELECT * FROM p_enroute_init_timeline WHERE p_id = $planId ORDER BY start_datetime, id"
        );
        if ($q) {
            while ($t = $q->fetch_assoc()) {
                $plan['timeline'][] = formatTimelineEntry($t, 'enroute');
            }
        }
    }

    // ---- Legacy p_terminal_init / p_enroute_init (fallback) ----
    // Older plans may only have data here (title + context).
    if (empty($plan['timeline'])) {
        if ($tablesExist['p_terminal_init']) {
            $q = $conn->query("SELECT * FROM p_terminal_init WHERE p_id = $planId ORDER BY id");
            if ($q) {
                while ($t = $q->fetch_assoc()) {
                    $plan['tmis'][] = [
                        'type' => 'terminal',
                        'airport' => normalizeLegacyPlanText($t['title'] ?? ''),
                        'context' => normalizeLegacyPlanText($t['context'] ?? null)
                    ];
                }
            }
        }
        if ($tablesExist['p_enroute_init']) {
            $q = $conn->query("SELECT * FROM p_enroute_init WHERE p_id = $planId ORDER BY id");
            if ($q) {
                while ($t = $q->fetch_assoc()) {
                    $plan['tmis'][] = [
                        'type' => 'enroute',
                        'element' => normalizeLegacyPlanText($t['title'] ?? null),
                        'context' => normalizeLegacyPlanText($t['context'] ?? null)
                    ];
                }
            }
        }
    }

    // ---- Constraints ----
    if ($tablesExist['p_terminal_constraints']) {
        $q = $conn->query("SELECT * FROM p_terminal_constraints WHERE p_id = $planId ORDER BY id");
        if ($q) {
            while ($c = $q->fetch_assoc()) {
                $plan['constraints'][] = [
                    'type' => 'terminal',
                    'location' => normalizeLegacyPlanText($c['location'] ?? ''),
                    'context' => normalizeLegacyPlanText($c['context'] ?? ''),
                    'impact' => normalizeLegacyPlanText($c['impact'] ?? ''),
                ];
            }
        }
    }
    if ($tablesExist['p_enroute_constraints']) {
        $q = $conn->query("SELECT * FROM p_enroute_constraints WHERE p_id = $planId ORDER BY id");
        if ($q) {
            while ($c = $q->fetch_assoc()) {
                $plan['constraints'][] = [
                    'type' => 'enroute',
                    'location' => normalizeLegacyPlanText($c['location'] ?? ''),
                    'context' => normalizeLegacyPlanText($c['context'] ?? ''),
                    'impact' => normalizeLegacyPlanText($c['impact'] ?? ''),
                ];
            }
        }
    }

    // ---- Group Flights (special events) ----
    if ($tablesExist['p_group_flights']) {
        $q = $conn->query("SELECT * FROM p_group_flights WHERE p_id = $planId ORDER BY id");
        if ($q) {
            while ($gf = $q->fetch_assoc()) {
                $plan['events'][] = [
                    'title' => normalizeLegacyPlanText($gf['entity'] ?? ''),
                    'description' => trim(($gf['dep'] ?? '') . '-' . ($gf['arr'] ?? '')),
                    'time' => normalizeLegacyPlanText($gf['etd'] ?? ''),
                    'pilotCount' => intval($gf['pilot_quantity'] ?? 0),
                ];
            }
        }
    }

    // ---- Op Goals ----
    if ($tablesExist['p_op_goals']) {
        $q = $conn->query("SELECT * FROM p_op_goals WHERE p_id = $planId ORDER BY id");
        if ($q) {
            while ($g = $q->fetch_assoc()) {
                $text = normalizeLegacyPlanText($g['comments'] ?? '');
                if ($text !== '') {
                    $plan['goals'][] = $text;
                }
            }
        }
    }

    // ---- Planning notes ----
    foreach (['p_terminal_planning', 'p_enroute_planning'] as $tbl) {
        if ($tablesExist[$tbl]) {
            $q = $conn->query("SELECT * FROM $tbl WHERE p_id = $planId ORDER BY id");
            if ($q) {
                while ($p = $q->fetch_assoc()) {
                    $facility = normalizeLegacyPlanText($p['facility_name'] ?? '');
                    $comments = normalizeLegacyPlanText($p['comments'] ?? '');
                    if ($comments !== '') {
                        $plan['planning'][] = ($facility ? $facility . ': ' : '') . $comments;
                    }
                }
            }
        }
    }

    // ---- Build formatted text summaries ----
    if (!empty($plan['timeline'])) {
        $plan['initiativesSummary'] = buildTimelineSummary($plan['timeline']);
    } else {
        $plan['initiativesSummary'] = buildInitiativesSummary($plan['tmis']);
    }
    $plan['constraintsSummary'] = buildConstraintsSummary($plan);
    $plan['eventsSummary'] = buildEventsSummary($plan['events']);

    // Debug info
    $plan['_debug'] = [
        'planId' => $planId,
        'tablesChecked' => $tablesExist,
        'timelineCount' => count($plan['timeline']),
        'tmiCount' => count($plan['tmis']),
        'constraintsCount' => count($plan['constraints']),
        'eventsCount' => count($plan['events']),
        'goalsCount' => count($plan['goals']),
    ];

    // Calculate valid times
    try {
        $eventStart = normalizeTime($row['event_start'] ?? '0000') ?: '00:00';
        $startDateTime = ($row['event_date'] ?? date('Y-m-d')) . 'T' . $eventStart;
        $endDateTime = null;

        if (!empty($row['event_end_date']) && !empty($row['event_end_time'])) {
            $endDateTime = $row['event_end_date'] . 'T' . normalizeTime($row['event_end_time']);
        } else {
            $start = new DateTime($startDateTime);
            $start->modify('+4 hours');
            $endDateTime = $start->format('Y-m-d\TH:i');
        }
        $plan['validFrom'] = $startDateTime;
        $plan['validUntil'] = $endDateTime;
    } catch (Exception $e) {
        $plan['validFrom'] = date('Y-m-d') . 'T00:00';
        $plan['validUntil'] = date('Y-m-d') . 'T04:00';
    }

    return $plan;
}

/**
 * Format a timeline entry from p_terminal_init_timeline / p_enroute_init_timeline
 */
function formatTimelineEntry($row, $scope) {
    $facility = normalizeLegacyPlanText($row['facility'] ?? '');
    $tmiType = normalizeLegacyPlanText($row['tmi_type'] ?? '');
    $tmiTypeOther = normalizeLegacyPlanText($row['tmi_type_other'] ?? '');
    $cause = normalizeLegacyPlanText($row['cause'] ?? '');
    $level = normalizeLegacyPlanText($row['level'] ?? '');
    $notes = normalizeLegacyPlanText($row['notes'] ?? '');

    $type = $tmiType;
    if ($tmiType === 'OTHER' && $tmiTypeOther !== '') {
        $type = $tmiTypeOther;
    }

    return [
        'scope' => $scope,
        'facility' => $facility,
        'area' => normalizeLegacyPlanText($row['area'] ?? ''),
        'tmiType' => $type,
        'cause' => $cause,
        'level' => $level,
        'startDatetime' => $row['start_datetime'] ?? null,
        'endDatetime' => $row['end_datetime'] ?? null,
        'notes' => $notes,
        'advzyNumber' => normalizeLegacyPlanText($row['advzy_number'] ?? ''),
    ];
}

/**
 * Build initiatives summary from timeline entries (new format)
 */
function buildTimelineSummary($timeline) {
    if (empty($timeline)) return '';

    // Group by facility
    $byFacility = [];
    foreach ($timeline as $entry) {
        $fac = $entry['facility'] ?: 'Unknown';
        $byFacility[$fac][] = $entry;
    }

    $lines = [];
    foreach ($byFacility as $facility => $entries) {
        $parts = [];
        foreach ($entries as $e) {
            $tmi = $e['tmiType'] ?: 'TMI';
            $cause = $e['cause'] ? ' - ' . $e['cause'] : '';
            $lvl = $e['level'] ? ' (' . ucfirst($e['level']) . ')' : '';

            // Format times as HHMMz
            $start = '';
            $end = '';
            if ($e['startDatetime']) {
                $ts = strtotime($e['startDatetime']);
                if ($ts) $start = gmdate('Hi', $ts) . 'z';
            }
            if ($e['endDatetime']) {
                $ts = strtotime($e['endDatetime']);
                if ($ts) $end = gmdate('Hi', $ts) . 'z';
            }
            $timeRange = '';
            if ($start && $end) {
                $timeRange = " {$start}-{$end}";
            } elseif ($start) {
                $timeRange = " {$start}+";
            }

            $parts[] = "{$tmi}{$cause}{$lvl}{$timeRange}";
        }
        $lines[] = $facility . ': ' . implode('; ', $parts);
    }

    return implode("\n", $lines);
}

/**
 * Build initiatives summary from legacy init entries (fallback)
 */
function buildInitiativesSummary($tmis) {
    if (empty($tmis)) return '';

    $lines = [];
    foreach ($tmis as $tmi) {
        if ($tmi['type'] === 'terminal') {
            $line = normalizeLegacyPlanText($tmi['airport'] ?? '');
            $context = normalizeLegacyPlanText($tmi['context'] ?? '');
            if ($context !== '') $line .= ' - ' . $context;
            $lines[] = $line;
        } else {
            $line = normalizeLegacyPlanText($tmi['element'] ?? 'Enroute');
            $context = normalizeLegacyPlanText($tmi['context'] ?? '');
            if ($context !== '') $line .= ' - ' . $context;
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

    // Use explicit constraint entries first
    if (!empty($plan['constraints'])) {
        foreach ($plan['constraints'] as $c) {
            $loc = $c['location'] ?: '';
            $ctx = $c['context'] ?: '';
            $impact = $c['impact'] ?: '';
            $line = $loc;
            if ($ctx !== '') $line .= ($line ? ': ' : '') . $ctx;
            if ($impact !== '') $line .= ' (Impact: ' . $impact . ')';
            if (trim($line) !== '') $lines[] = trim($line);
        }
    }

    // Fall back to timeline cause/notes for constraint-like info
    if (empty($lines) && !empty($plan['timeline'])) {
        foreach ($plan['timeline'] as $entry) {
            if ($entry['cause'] !== '') {
                $fac = $entry['facility'] ?: 'TMI';
                $lines[] = $fac . ': ' . $entry['cause'];
            }
        }
        // Deduplicate
        $lines = array_values(array_unique($lines));
    }

    // Final fallback to legacy tmis context
    if (empty($lines) && !empty($plan['tmis'])) {
        foreach ($plan['tmis'] as $tmi) {
            $context = normalizeLegacyPlanText($tmi['context'] ?? '');
            if ($context !== '') {
                $element = normalizeLegacyPlanText($tmi['airport'] ?? $tmi['element'] ?? 'TMI');
                $lines[] = $element . ': ' . $context;
            }
        }
    }

    return implode("\n", $lines);
}

/**
 * Build events summary text from group flights
 */
function buildEventsSummary($events) {
    if (empty($events)) return '';

    $lines = [];
    foreach ($events as $ev) {
        $title = normalizeLegacyPlanText($ev['title'] ?? 'Event');
        $time = normalizeLegacyPlanText($ev['time'] ?? '');
        $description = normalizeLegacyPlanText($ev['description'] ?? '');
        $pilotCount = intval($ev['pilotCount'] ?? 0);
        $line = $title !== '' ? $title : 'Event';
        if ($description !== '' && $description !== '-') $line .= ' ' . $description;
        if ($time !== '') $line .= ' ETD ' . $time . 'z';
        if ($pilotCount > 0) $line .= ' (' . $pilotCount . ' pilots)';
        $lines[] = $line;
    }

    return implode("\n", $lines);
}

/**
 * Normalize time string to HH:MM format
 */
function normalizeTime($time) {
    if (empty($time)) return '00:00';
    $time = trim($time);
    if (strlen($time) === 4 && strpos($time, ':') === false) {
        return substr($time, 0, 2) . ':' . substr($time, 2, 2);
    }
    if (strlen($time) === 5 && strpos($time, ':') === 2) {
        return $time; // Already in HH:MM format
    }
    return $time ?: '00:00';
}

/**
 * Normalize legacy placeholder values from historical plan rows.
 */
function normalizeLegacyPlanText($value) {
    if ($value === null) {
        return '';
    }

    if (is_array($value)) {
        $parts = [];
        foreach ($value as $item) {
            $normalized = normalizeLegacyPlanText($item);
            if ($normalized !== '') {
                $parts[] = $normalized;
            }
        }
        return implode(' ', $parts);
    }

    if (is_object($value)) {
        return normalizeLegacyPlanText(get_object_vars($value));
    }

    $str = trim((string)$value);
    if (
        $str === '' ||
        $str === '[]' ||
        $str === '{}' ||
        strcasecmp($str, 'null') === 0 ||
        strcasecmp($str, 'undefined') === 0
    ) {
        return '';
    }

    $decoded = json_decode($str, true);
    if (json_last_error() === JSON_ERROR_NONE && $decoded !== null) {
        return normalizeLegacyPlanText($decoded);
    }

    return $str;
}
