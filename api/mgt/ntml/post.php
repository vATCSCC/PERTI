<?php
/**
 * NTML Protocol Form - API Endpoint
 * Posts NTML entries to Discord via bot
 */

header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store, must-revalidate');

if (session_status() == PHP_SESSION_NONE) {
    session_start();
    ob_start();
}

include("../../../load/config.php");
include("../../../load/connect.php");

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
$perm = false;
if (!defined('DEV')) {
    if (isset($_SESSION['VATSIM_CID'])) {
        $cid = strip_tags($_SESSION['VATSIM_CID']);
        $p_check = $conn_sqli->query("SELECT * FROM users WHERE cid='$cid'");
        if ($p_check) {
            $perm = true;
        }
    }
} else {
    $perm = true;
    $_SESSION['VATSIM_CID'] = 0;
}

if (!$perm) {
    http_response_code(403);
    sendResponse(false, [], 'Unauthorized access');
}

// Validate required fields
if (!isset($_POST['protocol']) || !isset($_POST['determinant'])) {
    http_response_code(400);
    sendResponse(false, [], 'Missing required fields');
}

// Get form data
$protocol = strip_tags($_POST['protocol']);
$determinant = strip_tags($_POST['determinant']);
$production = isset($_POST['production']) && $_POST['production'] === '1';

// Get channel configuration
$channels = json_decode(DISCORD_CHANNELS, true);

// Determine target channel
// Production uses primary TMI channel, test uses the same (backup) for now
// To add production channels, add 'ntml_prod' to DISCORD_CHANNELS in config.php
$channel = $production 
    ? ($channels['ntml_prod'] ?? $channels['tmi']) 
    : $channels['tmi'];

// Build message based on protocol type
$message = buildNTMLMessage($protocol, $_POST);

// Post to Discord
$result = postToDiscord($channel, $message);

if ($result['success']) {
    // Log to database
    logNTMLEntry($protocol, $determinant, $_POST, $result['message_id'], $production);
    
    $channelName = $production ? 'Production NTML' : 'Test/Staging';
    sendResponse(true, [
        'channel' => $channelName,
        'message_id' => $result['message_id']
    ]);
} else {
    http_response_code(500);
    sendResponse(false, [], $result['error']);
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
    $condition = strip_tags($data['condition']);
    $distance = strip_tags($data['distance']);
    $reqFac = strtoupper(strip_tags($data['req_facility_id']));
    $provFac = strtoupper(strip_tags($data['prov_facility_id']));
    $reason = strip_tags($data['reason']);
    $validFrom = strip_tags($data['valid_from']);
    $validUntil = strip_tags($data['valid_until']);
    
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
    $condition = strip_tags($data['condition']);
    $minutes = strip_tags($data['minutes']);
    $reqFac = strtoupper(strip_tags($data['req_facility_id']));
    $provFac = strtoupper(strip_tags($data['prov_facility_id']));
    $reason = strip_tags($data['reason']);
    $validFrom = strip_tags($data['valid_from']);
    $validUntil = strip_tags($data['valid_until']);
    
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
    $delayFac = strtoupper(strip_tags($data['delay_facility']));
    $chargeFac = strtoupper(strip_tags($data['charge_facility']));
    $longestDelay = strip_tags($data['longest_delay']);
    $trend = ucfirst(strip_tags($data['delay_trend']));
    $flightsDelayed = strip_tags($data['flights_delayed']);
    $reason = strip_tags($data['reason']);
    $holding = strip_tags($data['holding']);
    
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
    
    $message = "**[$determinant] DELAY** $delayFac (charged to $chargeFac)\n";
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
    $airport = strtoupper(strip_tags($data['airport']));
    $weather = strip_tags($data['weather']);
    $arrRwys = strtoupper(strip_tags($data['arr_runways']));
    $depRwys = strtoupper(strip_tags($data['dep_runways']));
    $aar = strip_tags($data['aar']);
    $adr = strip_tags($data['adr']);
    $singleRwy = $data['single_runway'] === 'yes' ? 'Yes' : 'No';
    
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
// DISCORD API FUNCTIONS
// ============================================

function postToDiscord($channelId, $message) {
    $url = DISCORD_API_BASE . "/channels/$channelId/messages";
    
    $headers = [
        "Authorization: Bot " . DISCORD_BOT_TOKEN,
        "Content-Type: application/json",
        "User-Agent: PERTI-NTML/1.0"
    ];
    
    $body = json_encode([
        'content' => $message
    ]);
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) {
        return ['success' => false, 'error' => "cURL error: $error"];
    }
    
    $data = json_decode($response, true);
    
    if ($httpCode >= 200 && $httpCode < 300 && isset($data['id'])) {
        return ['success' => true, 'message_id' => $data['id']];
    } else {
        $errorMsg = isset($data['message']) ? $data['message'] : "Discord API error (HTTP $httpCode)";
        return ['success' => false, 'error' => $errorMsg];
    }
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
