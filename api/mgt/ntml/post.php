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

// Register shutdown function to catch fatal errors
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        ntml_debug_log('FATAL ERROR', $error);
    }
});

// Set error handler for non-fatal errors
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    ntml_debug_log('PHP Error', [
        'errno' => $errno,
        'errstr' => $errstr,
        'errfile' => $errfile,
        'errline' => $errline
    ]);
    return false; // Let PHP handle it normally too
});

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
    include_once("../../../load/config.php");
    ntml_debug_log('config.php loaded successfully');
    
    ntml_debug_log('Loading connect.php');
    include_once("../../../load/connect.php");
    ntml_debug_log('connect.php loaded successfully');
    
    ntml_debug_log('Loading DiscordAPI.php');
    include("../../../load/discord/DiscordAPI.php");
    ntml_debug_log('DiscordAPI.php loaded successfully');
    
    ntml_debug_log('Loading TMIDiscord.php');
    include("../../../load/discord/TMIDiscord.php");
    ntml_debug_log('TMIDiscord.php loaded successfully');
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
        $cid = session_get('VATSIM_CID', '');
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
$protocol = post_input('protocol');
$determinant = post_input('determinant');
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

// Build message using TMIDiscord formatter
ntml_debug_log('Building message for protocol: ' . $protocol);
$tmiDiscord = new TMIDiscord($discord);
$entryData = mapPostToEntryData($_POST);
$message = buildNTMLMessageFromEntry($entryData, $protocol);
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
// Uses TMIDiscord for consistent formatting
// ============================================

/**
 * Map POST data to entry data structure expected by TMIDiscord
 */
function mapPostToEntryData($data) {
    return [
        // Common fields
        'entry_type' => strtoupper($data['type'] ?? 'MIT'),
        'determinant' => strip_tags($data['determinant'] ?? ''),
        'raw' => strip_tags($data['raw'] ?? ''),
        
        // Facility coordination
        'requesting_facility' => strtoupper(strip_tags($data['to_facility'] ?? $data['req_facility_id'] ?? '')),
        'providing_facility' => strtoupper(strip_tags($data['from_facility'] ?? $data['prov_facility_id'] ?? '')),
        
        // Location/condition
        'airport' => strtoupper(strip_tags($data['condition'] ?? $data['airport'] ?? '')),
        'ctl_element' => strtoupper(strip_tags($data['condition'] ?? $data['airport'] ?? '')),
        'condition_text' => strip_tags($data['via_route'] ?? ''),
        'fix' => strip_tags($data['via_route'] ?? ''),
        
        // MIT/MINIT specific
        'restriction_value' => strip_tags($data['distance'] ?? $data['minutes'] ?? ''),
        'distance' => strip_tags($data['distance'] ?? ''),
        'minutes' => strip_tags($data['minutes'] ?? ''),
        
        // Flow direction
        'flow_type' => ($data['is_departures'] ?? false) ? 'departures' : 'arrivals',
        
        // Qualifiers
        'qualifiers' => strip_tags($data['qualifiers'] ?? ''),
        
        // Restrictions
        'aircraft_type' => strip_tags($data['aircraft_type'] ?? ''),
        'speed' => strip_tags($data['speed'] ?? ''),
        'speed_operator' => strip_tags($data['speed_operator'] ?? ''),
        'altitude' => strip_tags($data['altitude'] ?? ''),
        'alt_type' => strtoupper(strip_tags($data['alt_type'] ?? '')),
        
        // Reason
        'reason_code' => strtoupper(strip_tags($data['reason'] ?? 'VOLUME')),
        'reason_detail' => strip_tags($data['weather'] ?? $data['reason_detail'] ?? ''),
        'volume' => strip_tags($data['volume'] ?? ''),
        'weather' => strip_tags($data['weather'] ?? ''),
        
        // Exclusions
        'exclusions' => strtoupper(strip_tags($data['exclusions'] ?? 'NONE')),
        
        // Valid time
        'valid_from' => strip_tags($data['valid_from'] ?? ''),
        'valid_until' => strip_tags($data['valid_until'] ?? ''),
        
        // Delay specific
        'delay_type' => strtoupper(strip_tags($data['delay_type'] ?? 'D/D')),
        'delay_facility' => strtoupper(strip_tags($data['facility'] ?? $data['airport'] ?? '')),
        'longest_delay' => strip_tags($data['minutes'] ?? $data['delay_minutes'] ?? ''),
        'delay_trend' => strip_tags($data['trend'] ?? $data['delay_change'] ?? 'steady'),
        'flights_delayed' => strip_tags($data['flights_delayed'] ?? '1'),
        'holding' => strip_tags($data['holding'] ?? $data['is_holding'] ?? 'no'),
        'report_time' => strip_tags($data['delay_time'] ?? ''),
        
        // Config specific
        'weather' => strtoupper(strip_tags($data['weather'] ?? 'VMC')),
        'arr_runways' => strtoupper(strip_tags($data['arr_runways'] ?? '')),
        'dep_runways' => strtoupper(strip_tags($data['dep_runways'] ?? '')),
        'aar' => strip_tags($data['aar'] ?? '60'),
        'aar_type' => strip_tags($data['aar_type'] ?? 'Strat'),
        'aar_adjustment' => strip_tags($data['aar_adjustment'] ?? ''),
        'adr' => strip_tags($data['adr'] ?? '60'),
        
        // Cancel specific
        'cancel_type' => strip_tags($data['cancel_type'] ?? ''),
        'cancel_reason' => strip_tags($data['cancel_reason'] ?? ''),
        
        // TBM specific
        'sector' => strip_tags($data['sector'] ?? ''),
    ];
}

/**
 * Build NTML message in proper format
 * Format: DD/HHMM APT [direction] via FIX ##TYPE [QUALIFIERS] REASON EXCL:xxx HHMM-HHMM REQ:PROV
 */
function buildNTMLMessageFromEntry($entry, $protocol) {
    $logTime = gmdate('d/Hi');
    $type = strtoupper($entry['entry_type'] ?? 'MIT');
    
    switch ($type) {
        case 'MIT':
        case 'MINIT':
        case 'STOP':
        case 'APREQ':
        case 'CFR':
            return buildRestrictionNTML($entry, $logTime);
        case 'HOLDING':
        case 'DELAY':
            return buildDelayNTML($entry, $logTime);
        case 'CONFIG':
            return buildConfigNTML($entry, $logTime);
        case 'CANCEL':
            return buildCancelNTML($entry, $logTime);
        case 'TBM':
            return buildTBMNTML($entry, $logTime);
        default:
            return "$logTime {$entry['raw']}";
    }
}

/**
 * Build MIT/MINIT/STOP/APREQ/CFR NTML entry
 */
function buildRestrictionNTML($entry, $logTime) {
    $type = strtoupper($entry['entry_type'] ?? 'MIT');
    $airport = strtoupper($entry['airport'] ?? $entry['ctl_element'] ?? '');
    $fix = strtoupper($entry['fix'] ?? $entry['condition_text'] ?? '');
    $flowType = strtolower($entry['flow_type'] ?? 'arrivals');
    
    // Build restriction value
    $restriction = '';
    if ($type === 'STOP') {
        $restriction = 'STOP';
    } elseif ($type === 'APREQ' || $type === 'CFR') {
        $restriction = $type;
    } else {
        $value = $entry['restriction_value'] ?? $entry['distance'] ?? $entry['minutes'] ?? '';
        $restriction = "{$value}{$type}";
    }
    
    // Build qualifiers
    $qualifiers = '';
    if (!empty($entry['qualifiers'])) {
        $quals = is_string($entry['qualifiers']) ? explode(',', $entry['qualifiers']) : $entry['qualifiers'];
        $qualifiers = ' ' . implode(' ', array_map(function($q) {
            return strtoupper(str_replace('_', ' ', trim($q)));
        }, $quals));
    }
    
    // Build optional fields
    $opts = [];
    if (!empty($entry['aircraft_type'])) {
        $opts[] = 'TYPE:' . strtoupper($entry['aircraft_type']);
    }
    if (!empty($entry['altitude'])) {
        $altType = strtoupper($entry['alt_type'] ?? 'AT');
        $opts[] = "ALT:{$altType}" . strtoupper($entry['altitude']);
    }
    
    // Reason
    $reason = strtoupper($entry['reason_code'] ?? 'VOLUME');
    if ($reason === 'VOLUME') {
        $opts[] = 'VOLUME:VOLUME';
    } elseif ($reason === 'WEATHER') {
        $detail = strtoupper($entry['reason_detail'] ?? $entry['weather'] ?? 'WEATHER');
        $opts[] = "WEATHER:{$detail}";
    } elseif ($reason === 'RUNWAY') {
        $detail = strtoupper($entry['reason_detail'] ?? 'CONFIG');
        $opts[] = "RUNWAY:{$detail}";
    } else {
        $opts[] = "{$reason}:{$reason}";
    }
    
    // Exclusions
    $excl = strtoupper($entry['exclusions'] ?? 'NONE');
    $opts[] = "EXCL:{$excl}";
    
    // Valid time
    $validFrom = $entry['valid_from'] ?? gmdate('Hi');
    $validUntil = $entry['valid_until'] ?? gmdate('Hi', strtotime('+2 hours'));
    $opts[] = "{$validFrom}-{$validUntil}";
    
    // Facility coordination
    $reqFac = strtoupper($entry['requesting_facility'] ?? '');
    $provFac = strtoupper($entry['providing_facility'] ?? '');
    if ($reqFac || $provFac) {
        $opts[] = "{$reqFac}:{$provFac}";
    }
    
    $optStr = implode(' ', $opts);
    
    // Build the line per format spec
    if ($type === 'APREQ' || $type === 'CFR') {
        // APREQ/CFR: DD/HHMM TYPE APT departures [via FIX] [TYPE:xx] REASON...
        $line = "{$logTime}    {$restriction} {$airport}";
        if ($flowType === 'departures') {
            $line .= ' departures';
        }
        if ($fix) {
            $line .= " via {$fix}";
        }
    } elseif ($fix) {
        // With via: DD/HHMM APT [direction] via FIX ##MIT...
        $line = "{$logTime}    {$airport}";
        if ($flowType === 'departures') {
            $line .= ' departures';
        } else if ($flowType === 'arrivals' && $type !== 'STOP') {
            // arrivals is implicit via fix pattern, but add if explicit
            $line .= ' arrivals';
        }
        $line .= " via {$fix} {$restriction}";
    } else {
        // Without via: DD/HHMM APT [direction] ##MIT...
        $line = "{$logTime}    {$airport}";
        if ($flowType === 'departures') {
            $line .= ' departures';
        }
        $line .= " {$restriction}";
    }
    
    $line .= "{$qualifiers} {$optStr}";
    
    return trim($line);
}

/**
 * Build Delay/Holding NTML entry
 * Format: DD/HHMM [FAC] D/D from APT, +/-##/HHMM[/# ACFT] [NAVAID:xx] REASON
 */
function buildDelayNTML($entry, $logTime) {
    $delayType = strtoupper($entry['delay_type'] ?? 'D/D');
    // Normalize E/D, A/D, D/D
    if ($delayType === 'ED') $delayType = 'E/D';
    if ($delayType === 'AD') $delayType = 'A/D';
    if ($delayType === 'DD') $delayType = 'D/D';
    
    // Preposition based on type
    $prep = 'from';
    if ($delayType === 'E/D') $prep = 'for';
    if ($delayType === 'A/D') $prep = 'to';
    
    $facility = strtoupper($entry['delay_facility'] ?? $entry['airport'] ?? '');
    $reportingFac = $entry['reporting_facility'] ?? '';
    
    // Delay value
    $delayMin = $entry['longest_delay'] ?? $entry['delay_minutes'] ?? $entry['minutes'] ?? '';
    $trend = strtolower($entry['delay_trend'] ?? 'steady');
    $holding = $entry['holding'] ?? $entry['is_holding'] ?? 'no';
    
    $sign = '';
    if ($trend === 'increasing' || $trend === 'inc' || $trend === 'initiating') {
        $sign = '+';
    } elseif ($trend === 'decreasing' || $trend === 'dec' || $trend === 'terminating') {
        $sign = '-';
    }
    
    // Holding or minutes
    $delayValue = '';
    if ($holding === 'yes' || $holding === 'yes_initiating' || strpos(strtolower($holding), 'holding') !== false) {
        $delayValue = ($sign ?: '+') . 'Holding';
    } else {
        $delayValue = "{$sign}{$delayMin}";
    }
    
    $reportTime = $entry['report_time'] ?? $entry['delay_time'] ?? gmdate('Hi');
    $acftCount = $entry['flights_delayed'] ?? $entry['acft_count'] ?? '';
    
    // Build line
    $line = "{$logTime}";
    if ($reportingFac) {
        $line .= "    {$reportingFac}";
    }
    $line .= " {$delayType} {$prep} {$facility}, {$delayValue}/{$reportTime}";
    if ($acftCount) {
        $line .= "/{$acftCount} ACFT";
    }
    
    // Optional navaid
    if (!empty($entry['fix'])) {
        $line .= ' NAVAID:' . strtoupper($entry['fix']);
    }
    
    // Reason
    $reason = strtoupper($entry['reason_code'] ?? 'VOLUME');
    if ($reason === 'VOLUME') {
        $line .= ' VOLUME:VOLUME';
    } else {
        $line .= " {$reason}:{$reason}";
    }
    
    return trim($line);
}

/**
 * Build Config NTML entry
 * Format: DD/HHMM APT    WX    ARR:rwys DEP:rwys    AAR(type):##    [AAR Adjustment:xx]    ADR:##
 */
function buildConfigNTML($entry, $logTime) {
    $airport = strtoupper($entry['airport'] ?? $entry['ctl_element'] ?? '');
    $weather = strtoupper($entry['weather'] ?? 'VMC');
    $arrRwys = strtoupper($entry['arr_runways'] ?? '');
    $depRwys = strtoupper($entry['dep_runways'] ?? '');
    $aar = $entry['aar'] ?? '60';
    $aarType = $entry['aar_type'] ?? 'Strat';
    $aarAdj = $entry['aar_adjustment'] ?? '';
    $adr = $entry['adr'] ?? '60';
    
    // Use tab alignment like historical data
    $line = "{$logTime}    {$airport}    {$weather}    ARR:{$arrRwys} DEP:{$depRwys}    AAR({$aarType}):{$aar}";
    if ($aarAdj) {
        $line .= " AAR Adjustment:{$aarAdj}";
    }
    $line .= "    ADR:{$adr}";
    
    return $line;
}

/**
 * Build Cancel NTML entry
 */
function buildCancelNTML($entry, $logTime) {
    $cancelType = strtoupper($entry['cancel_type'] ?? '');
    $airport = strtoupper($entry['airport'] ?? $entry['ctl_element'] ?? '');
    $fix = strtoupper($entry['fix'] ?? '');
    
    $line = "{$logTime}    ";
    
    if ($cancelType === 'ALL') {
        $line .= 'ALL TMI CANCELLED';
    } else {
        $line .= "CANCEL {$airport}";
        if ($fix) {
            $line .= " via {$fix}";
        }
        if (!empty($entry['restriction_value'])) {
            $line .= ' ' . $entry['restriction_value'] . 'MIT';
        }
    }
    
    $reqFac = strtoupper($entry['requesting_facility'] ?? '');
    $provFac = strtoupper($entry['providing_facility'] ?? '');
    if ($reqFac || $provFac) {
        $line .= " {$reqFac}:{$provFac}";
    }
    
    return trim($line);
}

/**
 * Build TBM NTML entry
 * Format: DD/HHMM APT TBM SECTOR REASON EXCL:xxx HHMM-HHMM REQ:PROV
 */
function buildTBMNTML($entry, $logTime) {
    $airport = strtoupper($entry['airport'] ?? $entry['ctl_element'] ?? '');
    $sector = strtoupper($entry['sector'] ?? '');
    
    // Reason
    $reason = strtoupper($entry['reason_code'] ?? 'VOLUME');
    $reasonStr = ($reason === 'VOLUME') ? 'VOLUME:VOLUME' : "{$reason}:{$reason}";
    
    // Exclusions
    $excl = strtoupper($entry['exclusions'] ?? 'NONE');
    
    // Valid time
    $validFrom = $entry['valid_from'] ?? gmdate('Hi');
    $validUntil = $entry['valid_until'] ?? gmdate('Hi', strtotime('+2 hours'));
    
    // Facilities
    $reqFac = strtoupper($entry['requesting_facility'] ?? '');
    $provFac = strtoupper($entry['providing_facility'] ?? '');
    
    $line = "{$logTime}    {$airport} TBM";
    if ($sector) {
        $line .= " {$sector}";
    }
    $line .= " {$reasonStr} EXCL:{$excl} {$validFrom}-{$validUntil}";
    if ($reqFac || $provFac) {
        $line .= " {$reqFac}:{$provFac}";
    }
    
    return trim($line);
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
