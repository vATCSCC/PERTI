<?php
/**
 * NTML Protocol Form - API Endpoint
 * Posts NTML entries to Discord via DiscordAPI class
 */

// Prevent caching
header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store, must-revalidate');

// Debug logging function - writes to /home/LogFiles/ntml_debug.log on Azure
function ntml_debug_log($message, $data = null) {
    $logFile = '/home/LogFiles/ntml_debug.log';
    // Fallback to local dir if /home/LogFiles doesn't exist
    if (!is_dir('/home/LogFiles')) {
        $logFile = __DIR__ . '/ntml_debug.log';
    }
    $timestamp = date('Y-m-d H:i:s');
    $entry = "[$timestamp] $message";
    if ($data !== null) {
        $entry .= " | Data: " . json_encode($data);
    }
    @file_put_contents($logFile, $entry . "\n", FILE_APPEND);
}

ntml_debug_log('=== NTML POST Request Started ===');
ntml_debug_log('POST data received', $_POST);

// Session Start
if (session_status() == PHP_SESSION_NONE) {
    session_start();
    ob_start();
}

ntml_debug_log('Session started', ['session_id' => session_id()]);

// Load dependencies
try {
    ntml_debug_log('Loading config.php');
    include("../../../load/config.php");
    ntml_debug_log('config.php loaded successfully');
    
    ntml_debug_log('Loading connect.php');
    include("../../../load/connect.php");
    ntml_debug_log('connect.php loaded successfully');
    
    ntml_debug_log('Loading DiscordAPI.php');
    include("../../../load/discord/DiscordAPI.php");
    ntml_debug_log('DiscordAPI.php loaded successfully');
} catch (Exception $e) {
    ntml_debug_log('ERROR loading dependencies', ['error' => $e->getMessage()]);
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Dependency load error: ' . $e->getMessage()]);
    exit();
}

// Response helper
function sendResponse($success, $data = [], $error = null) {
    echo json_encode(array_merge(
        ['success' => $success],
        $data,
        $error ? ['error' => $error] : []
    ));
    exit();
}

// Check Perms
ntml_debug_log('Checking permissions', ['DEV_defined' => defined('DEV'), 'session_cid' => $_SESSION['VATSIM_CID'] ?? 'NOT SET']);
$perm = false;
if (!defined('DEV')) {
    if (isset($_SESSION['VATSIM_CID'])) {
        $cid = strip_tags($_SESSION['VATSIM_CID']);
        $p_check = $conn_sqli->query("SELECT * FROM users WHERE cid='$cid'");
        if ($p_check) {
            $perm = true;
            ntml_debug_log('Permission granted via session CID', ['cid' => $cid]);
        } else {
            ntml_debug_log('Permission denied - user query failed', ['cid' => $cid]);
        }
    } else {
        ntml_debug_log('Permission denied - no session CID');
    }
} else {
    $perm = true;
    $_SESSION['VATSIM_CID'] = 0;
    ntml_debug_log('Permission granted via DEV mode');
}

if (!$perm) {
    ntml_debug_log('REJECTED - Unauthorized access');
    http_response_code(403);
    sendResponse(false, [], 'Unauthorized access');
}

// Validate required fields
ntml_debug_log('Validating required fields');
if (!isset($_POST['protocol']) || !isset($_POST['determinant'])) {
    ntml_debug_log('REJECTED - Missing required fields', ['has_protocol' => isset($_POST['protocol']), 'has_determinant' => isset($_POST['determinant'])]);
    http_response_code(400);
    sendResponse(false, [], 'Missing required fields');
}

// Get form data
$protocol = strip_tags($_POST['protocol']);
$determinant = strip_tags($_POST['determinant']);
$production = isset($_POST['production']) && $_POST['production'] === '1';
ntml_debug_log('Form data extracted', ['protocol' => $protocol, 'determinant' => $determinant, 'production' => $production]);

// Initialize Discord API
ntml_debug_log('Initializing Discord API');
$discord = new DiscordAPI();
ntml_debug_log('Discord API initialized', [
    'configured' => $discord->isConfigured(),
    'tmi_channel' => $discord->getChannelByPurpose('tmi')
]);

// Check if Discord is configured
if (!$discord->isConfigured()) {
    ntml_debug_log('ERROR - Discord not configured');
    http_response_code(500);
    sendResponse(false, [], 'Discord integration not configured');
}

// Build message based on protocol type
ntml_debug_log('Building message for protocol: ' . $protocol);
$message = buildNTMLMessage($protocol, $_POST);
ntml_debug_log('Message built', ['message_length' => strlen($message), 'message_preview' => substr($message, 0, 100)]);

// Add test prefix for non-production
if (!$production) {
    $message = "🧪 **[TEST]** " . $message;
}

// Post to Discord using the 'tmi' channel
ntml_debug_log('Sending to Discord tmi channel');
$result = $discord->createMessage('tmi', ['content' => $message]);
ntml_debug_log('Discord API response', [
    'result' => $result,
    'last_error' => $discord->getLastError(),
    'last_http_code' => $discord->getLastHttpCode()
]);

if ($result !== null && isset($result['id'])) {
    ntml_debug_log('SUCCESS - Message posted', ['message_id' => $result['id']]);
    // Log to database
    logNTMLEntry($protocol, $determinant, $_POST, $result['id'], $production);
    
    $channelName = $production ? 'Production NTML' : 'Test/Staging';
    sendResponse(true, [
        'channel' => $channelName,
        'message_id' => $result['id']
    ]);
} else {
    $error = $discord->getLastError() ?: 'Unknown Discord API error';
    $httpCode = $discord->getLastHttpCode();
    ntml_debug_log('FAILED - Discord API error', ['error' => $error, 'http_code' => $httpCode]);
    
    http_response_code(500);
    sendResponse(false, [], "Discord API error: $error (HTTP $httpCode)");
}

// ============================================
// MESSAGE BUILDING FUNCTIONS
// ============================================

function buildNTMLMessage($protocol, $data) {
    $determinant = strip_tags($data['determinant']);
    $timestamp = gmdate('Hi') . 'Z';
    
    switch ($protocol) {
        case '05':
            return buildMITMessage($determinant, $data, $timestamp);
        case '06':
            return buildMINITMessage($determinant, $data, $timestamp);
        case '04':
            return buildDelayMessage($determinant, $data, $timestamp);
        case '01':
            return buildConfigMessage($determinant, $data, $timestamp);
        default:
            return "[$determinant] NTML Entry - $timestamp";
    }
}

function buildMITMessage($determinant, $data, $timestamp) {
    $condition = strip_tags($data['condition'] ?? '');
    $distance = strip_tags($data['distance'] ?? '');
    $reqFac = strtoupper(strip_tags($data['req_facility_id'] ?? ''));
    $provFac = strtoupper(strip_tags($data['prov_facility_id'] ?? ''));
    $reason = strip_tags($data['reason'] ?? 'VOLUME');
    $validFrom = strip_tags($data['valid_from'] ?? '');
    $validUntil = strip_tags($data['valid_until'] ?? '');
    
    // Build qualifiers string
    $qualifiers = '';
    if (!empty($data['qualifiers'])) {
        $quals = explode(',', $data['qualifiers']);
        $qualifiers = ' ' . implode(' ', array_map(function($q) {
            return str_replace('_', ' ', $q);
        }, $quals));
    }
    
    // Build restrictions
    $restrictions = '';
    if (!empty($data['speed'])) {
        $restrictions .= ' SPD ' . strip_tags($data['speed']);
    }
    if (!empty($data['altitude']) && !empty($data['alt_type'])) {
        $altType = strtoupper(strip_tags($data['alt_type']));
        $altitude = strtoupper(strip_tags($data['altitude']));
        $restrictions .= " ALT $altType $altitude";
    }
    
    // Build exclusions
    $exclusions = '';
    if (!empty($data['exclusions'])) {
        $exclusions = "\nExcluded: " . strip_tags($data['exclusions']);
    }
    
    $message = "**[$determinant] {$distance}MIT** $provFac→$reqFac $condition$qualifiers\n";
    $message .= "Valid: {$validFrom}Z-{$validUntil}Z\n";
    $message .= "Reason: $reason";
    
    if ($restrictions) {
        $message .= "\nRestrictions:$restrictions";
    }
    $message .= $exclusions;
    
    return $message;
}

function buildMINITMessage($determinant, $data, $timestamp) {
    $condition = strip_tags($data['condition'] ?? '');
    $minutes = strip_tags($data['minutes'] ?? '');
    $reqFac = strtoupper(strip_tags($data['req_facility_id'] ?? ''));
    $provFac = strtoupper(strip_tags($data['prov_facility_id'] ?? ''));
    $reason = strip_tags($data['reason'] ?? 'VOLUME');
    $validFrom = strip_tags($data['valid_from'] ?? '');
    $validUntil = strip_tags($data['valid_until'] ?? '');
    
    // Build qualifiers string
    $qualifiers = '';
    if (!empty($data['qualifiers'])) {
        $quals = explode(',', $data['qualifiers']);
        $qualifiers = ' ' . implode(' ', array_map(function($q) {
            return str_replace('_', ' ', $q);
        }, $quals));
    }
    
    // Build restrictions
    $restrictions = '';
    if (!empty($data['speed'])) {
        $restrictions .= ' SPD ' . strip_tags($data['speed']);
    }
    if (!empty($data['altitude']) && !empty($data['alt_type'])) {
        $altType = strtoupper(strip_tags($data['alt_type']));
        $altitude = strtoupper(strip_tags($data['altitude']));
        $restrictions .= " ALT $altType $altitude";
    }
    
    // Build exclusions
    $exclusions = '';
    if (!empty($data['exclusions'])) {
        $exclusions = "\nExcluded: " . strip_tags($data['exclusions']);
    }
    
    $message = "**[$determinant] {$minutes}MINIT** $provFac→$reqFac $condition$qualifiers\n";
    $message .= "Valid: {$validFrom}Z-{$validUntil}Z\n";
    $message .= "Reason: $reason";
    
    if ($restrictions) {
        $message .= "\nRestrictions:$restrictions";
    }
    $message .= $exclusions;
    
    return $message;
}

function buildDelayMessage($determinant, $data, $timestamp) {
    $delayFac = strtoupper(strip_tags($data['delay_facility'] ?? ''));
    $chargeFac = strtoupper(strip_tags($data['charge_facility'] ?? $delayFac));
    $longestDelay = strip_tags($data['longest_delay'] ?? '');
    $trend = ucfirst(strip_tags($data['delay_trend'] ?? 'steady'));
    $flightsDelayed = strip_tags($data['flights_delayed'] ?? '1');
    $reason = strip_tags($data['reason'] ?? 'VOLUME');
    $holding = strip_tags($data['holding'] ?? 'no');
    
    $holdingText = '';
    if ($holding === 'yes_initiating') {
        $holdingText = 'Holding initiated';
        if (!empty($data['holding_location'])) {
            $holdingText .= ' at ' . strtoupper(strip_tags($data['holding_location']));
        }
    } else if ($holding === 'yes_15plus') {
        $holdingText = 'Holding ≥15min';
        if (!empty($data['holding_location'])) {
            $holdingText .= ' at ' . strtoupper(strip_tags($data['holding_location']));
        }
    }
    
    $message = "**[$determinant] DELAY** $delayFac";
    if ($chargeFac && $chargeFac !== $delayFac) {
        $message .= " (charged to $chargeFac)";
    }
    $message .= "\n";
    $message .= "Longest: {$longestDelay}min | Trend: $trend | Flights: $flightsDelayed\n";
    $message .= "Reason: $reason";
    
    if ($holdingText) {
        $message .= "\n$holdingText";
    }
    
    if (!empty($data['notes'])) {
        $message .= "\nNotes: " . strip_tags($data['notes']);
    }
    
    return $message;
}

function buildConfigMessage($determinant, $data, $timestamp) {
    $airport = strtoupper(strip_tags($data['airport'] ?? ''));
    $weather = strip_tags($data['weather'] ?? 'VMC');
    $arrRwys = strtoupper(strip_tags($data['arr_runways'] ?? ''));
    $depRwys = strtoupper(strip_tags($data['dep_runways'] ?? ''));
    $aar = strip_tags($data['aar'] ?? '60');
    $adr = strip_tags($data['adr'] ?? '60');
    $singleRwy = (isset($data['single_runway']) && $data['single_runway'] === 'yes') ? 'Yes' : 'No';
    
    // Weather conditions
    $wxConditions = [];
    if (!empty($data['wx_wind'])) $wxConditions[] = 'High Winds';
    if (!empty($data['wx_ice'])) $wxConditions[] = 'Ice/Snow';
    if (!empty($data['wx_fog'])) $wxConditions[] = 'Fog';
    if (!empty($data['wx_tstorm'])) $wxConditions[] = 'Thunderstorms';
    $wxText = count($wxConditions) > 0 ? implode(', ', $wxConditions) : 'None';
    
    $message = "**[$determinant] AIRPORT CONFIG** $airport\n";
    $message .= "Weather: $weather | Single RWY: $singleRwy\n";
    $message .= "ARR: $arrRwys | DEP: $depRwys\n";
    $message .= "AAR: $aar | ADR: $adr\n";
    $message .= "Conditions: $wxText";
    
    if (!empty($data['scenery_issue']) && $data['scenery_issue'] === 'yes' && !empty($data['scenery_description'])) {
        $message .= "\nScenery Issue: " . strip_tags($data['scenery_description']);
    }
    
    return $message;
}

// ============================================
// DATABASE LOGGING
// ============================================

function logNTMLEntry($protocol, $determinant, $data, $messageId, $isProduction) {
    global $conn_sqli;
    
    // Sanitize data for JSON storage
    $safeData = array_map('strip_tags', $data);
    $jsonData = json_encode($safeData);
    
    $cid = isset($_SESSION['VATSIM_CID']) ? intval($_SESSION['VATSIM_CID']) : 0;
    $isTest = $isProduction ? 0 : 1;
    
    // Check if ntml_entries table exists, create if not
    $tableCheck = $conn_sqli->query("SHOW TABLES LIKE 'ntml_entries'");
    if ($tableCheck->num_rows === 0) {
        createNTMLTable($conn_sqli);
    }
    
    $sql = "INSERT INTO ntml_entries (determinant_code, protocol_type, entry_data, submitted_by, discord_message_id, is_test) 
            VALUES (?, ?, ?, ?, ?, ?)";
    
    $stmt = $conn_sqli->prepare($sql);
    if ($stmt) {
        $stmt->bind_param("sisisi", $determinant, $protocol, $jsonData, $cid, $messageId, $isTest);
        $stmt->execute();
        $stmt->close();
    }
}

function createNTMLTable($conn) {
    $sql = "CREATE TABLE IF NOT EXISTS ntml_entries (
        id INT AUTO_INCREMENT PRIMARY KEY,
        determinant_code VARCHAR(10),
        protocol_type TINYINT,
        valid_time VARCHAR(20),
        entry_data JSON,
        submitted_by INT,
        submitted_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        discord_message_id VARCHAR(20),
        is_test BOOLEAN DEFAULT FALSE,
        INDEX idx_determinant (determinant_code),
        INDEX idx_protocol (protocol_type),
        INDEX idx_submitted (submitted_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    
    $conn->query($sql);
}

?>
