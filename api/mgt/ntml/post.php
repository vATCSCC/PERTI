<?php
/**
 * NTML Protocol Form - API Endpoint v1.7.0
 * Posts NTML entries to Discord AND saves to VATSIM_TMI database
 * 
 * @version 1.7.0
 * @author HP/Claude
 */

// Prevent caching
header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store, must-revalidate');

// Debug logging function
function ntml_debug_log($message, $data = null) {
    $logFile = '/home/LogFiles/ntml_debug.log';
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

// Register shutdown function for fatal errors
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        ntml_debug_log('FATAL ERROR', $error);
    }
});

ntml_debug_log('=== NTML POST Request Started ===');
ntml_debug_log('POST data received', $_POST);

// Session Start
if (session_status() == PHP_SESSION_NONE) {
    session_start();
    ob_start();
}

// Load dependencies
try {
    include_once("../../../load/config.php");
    include_once("../../../load/connect.php");
    include("../../../load/discord/DiscordAPI.php");
    include("../../../load/discord/TMIDiscord.php");
    ntml_debug_log('Dependencies loaded successfully');
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

// Check Permissions
$perm = false;
$userId = null;
$userName = 'Unknown';

if (!defined('DEV')) {
    if (isset($_SESSION['VATSIM_CID'])) {
        $cid = session_get('VATSIM_CID', '');
        $p_check = $conn_sqli->query("SELECT * FROM users WHERE cid='$cid'");
        if ($p_check) {
            $perm = true;
            $userId = $cid;
            $userName = ($_SESSION['VATSIM_FIRST_NAME'] ?? '') . ' ' . ($_SESSION['VATSIM_LAST_NAME'] ?? '');
            ntml_debug_log('Permission granted', ['cid' => $cid, 'name' => $userName]);
        }
    }
} else {
    $perm = true;
    $userId = 0;
    $userName = 'DEV';
    $_SESSION['VATSIM_CID'] = 0;
}

if (!$perm) {
    http_response_code(403);
    sendResponse(false, [], 'Unauthorized access');
}

// Validate required fields
if (!isset($_POST['type']) || !isset($_POST['determinant'])) {
    http_response_code(400);
    sendResponse(false, [], 'Missing required fields');
}

// Get form data
$entryType = strtoupper(post_input('type'));
$determinant = post_input('determinant');
$production = isset($_POST['production']) && $_POST['production'] === '1';
ntml_debug_log('Form data', ['type' => $entryType, 'determinant' => $determinant, 'production' => $production]);

// Initialize Discord API
$discord = new DiscordAPI();
if (!$discord->isConfigured()) {
    http_response_code(500);
    sendResponse(false, [], 'Discord integration not configured');
}

// Build message using TMIDiscord formatter
$tmiDiscord = new TMIDiscord($discord);
$entryData = mapPostToEntryData($_POST);
$message = buildNTMLMessageFromEntry($entryData);
ntml_debug_log('Message built', ['message_length' => strlen($message)]);

// Add test prefix for non-production
if (!$production) {
    $message = "🧪 **[TEST]** " . $message;
}

// Determine Discord channel
$channel = $production ? 'ntml' : 'ntml_staging';

// Post to Discord
$result = $discord->createMessage($channel, ['content' => $message]);
ntml_debug_log('Discord response', ['result' => $result, 'error' => $discord->getLastError()]);

$discordMessageId = $result['id'] ?? null;

if (!$discordMessageId) {
    $error = $discord->getLastError() ?: 'Unknown Discord API error';
    http_response_code(500);
    sendResponse(false, [], "Discord API error: $error");
}

// ============================================
// DATABASE PERSISTENCE
// Save to VATSIM_TMI.tmi_entries (Azure SQL)
// ============================================

$tmiEntryId = null;

if (isset($conn_tmi) && $conn_tmi) {
    try {
        $tmiEntryId = saveTmiEntry($conn_tmi, $entryData, $determinant, $discordMessageId, $production, $userId, $userName);
        ntml_debug_log('TMI entry saved', ['entry_id' => $tmiEntryId]);
    } catch (Exception $e) {
        ntml_debug_log('TMI save error (non-fatal)', ['error' => $e->getMessage()]);
        // Continue - Discord post succeeded, DB save is secondary
    }
}

// Also log to MySQL for legacy compatibility
logNTMLEntryMySQL($determinant, $_POST, $discordMessageId, $production, $conn_sqli);

// Success response
$channelName = $production ? 'Production NTML' : 'Test/Staging';
sendResponse(true, [
    'channel' => $channelName,
    'message_id' => $discordMessageId,
    'tmi_entry_id' => $tmiEntryId
]);

// ============================================
// SAVE TO VATSIM_TMI.tmi_entries (Azure SQL)
// ============================================

function saveTmiEntry($conn, $entry, $determinant, $discordMessageId, $production, $userId, $userName) {
    // Map entry type to protocol
    $protocolMap = [
        'CONFIG' => 1, 'DELAY' => 4, 'HOLDING' => 4,
        'MIT' => 5, 'MINIT' => 6, 'APREQ' => 7, 'CFR' => 7,
        'TBM' => 8, 'REROUTE' => 9, 'STOP' => 3, 'CANCEL' => 0
    ];
    $protocol = $protocolMap[strtoupper($entry['entry_type'])] ?? 0;
    
    // Determine status
    $status = $production ? 'ACTIVE' : 'DRAFT';
    
    // Parse valid times
    $validFrom = null;
    $validUntil = null;
    if (!empty($entry['valid_from'])) {
        $today = gmdate('Y-m-d');
        $validFrom = $today . ' ' . substr($entry['valid_from'], 0, 2) . ':' . substr($entry['valid_from'], 2, 2) . ':00';
    }
    if (!empty($entry['valid_until'])) {
        $today = gmdate('Y-m-d');
        $validUntil = $today . ' ' . substr($entry['valid_until'], 0, 2) . ':' . substr($entry['valid_until'], 2, 2) . ':00';
        // Handle overnight validity
        if ($validFrom && $validUntil < $validFrom) {
            $tomorrow = gmdate('Y-m-d', strtotime('+1 day'));
            $validUntil = $tomorrow . ' ' . substr($entry['valid_until'], 0, 2) . ':' . substr($entry['valid_until'], 2, 2) . ':00';
        }
    }
    
    // Build restriction value
    $restrictionValue = null;
    $restrictionUnit = null;
    if ($entry['entry_type'] === 'MIT') {
        $restrictionValue = intval($entry['distance'] ?? $entry['restriction_value'] ?? 0);
        $restrictionUnit = 'NM';
    } elseif ($entry['entry_type'] === 'MINIT') {
        $restrictionValue = intval($entry['minutes'] ?? $entry['restriction_value'] ?? 0);
        $restrictionUnit = 'MIN';
    } elseif ($entry['entry_type'] === 'DELAY' || $entry['entry_type'] === 'HOLDING') {
        $restrictionValue = intval($entry['longest_delay'] ?? $entry['minutes'] ?? 0);
        $restrictionUnit = 'MIN';
    }
    
    // Build reason
    $reasonCode = strtoupper($entry['reason_code'] ?? 'VOLUME');
    $reasonDetail = $entry['reason_detail'] ?? $reasonCode;
    
    // Condition text (fix/via route)
    $conditionText = $entry['fix'] ?? $entry['condition_text'] ?? null;
    
    // Qualifiers as JSON
    $qualifiers = null;
    if (!empty($entry['qualifiers'])) {
        $quals = is_string($entry['qualifiers']) ? explode(',', $entry['qualifiers']) : $entry['qualifiers'];
        $qualifiers = json_encode(array_map('trim', $quals));
    }
    
    // Build content hash for deduplication
    $hashContent = implode('|', [
        $determinant,
        $entry['entry_type'],
        $entry['airport'] ?? $entry['ctl_element'] ?? '',
        $restrictionValue ?? '',
        $validFrom ?? '',
        $validUntil ?? ''
    ]);
    $contentHash = hash('sha256', $hashContent);
    
    // Insert into tmi_entries
    $sql = "INSERT INTO dbo.tmi_entries (
        determinant_code, protocol_type, entry_type,
        ctl_element, element_type, requesting_facility, providing_facility,
        restriction_value, restriction_unit, condition_text, qualifiers,
        exclusions, reason_code, reason_detail,
        valid_from, valid_until, status,
        source_type, source_channel, discord_message_id, content_hash,
        created_by, created_by_name
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?);
    SELECT SCOPE_IDENTITY() AS id;";
    
    $params = [
        $determinant,
        $protocol,
        strtoupper($entry['entry_type']),
        strtoupper($entry['airport'] ?? $entry['ctl_element'] ?? ''),
        'AIRPORT', // element_type
        strtoupper($entry['requesting_facility'] ?? ''),
        strtoupper($entry['providing_facility'] ?? ''),
        $restrictionValue,
        $restrictionUnit,
        $conditionText,
        $qualifiers,
        strtoupper($entry['exclusions'] ?? 'NONE'),
        $reasonCode,
        $reasonDetail,
        $validFrom,
        $validUntil,
        $status,
        'NTML_PUBLISHER', // source_type
        'web', // source_channel
        $discordMessageId,
        $contentHash,
        $userId,
        $userName
    ];
    
    $stmt = sqlsrv_query($conn, $sql, $params);
    
    if ($stmt === false) {
        $errors = sqlsrv_errors();
        ntml_debug_log('SQL Error', $errors);
        throw new Exception(print_r($errors, true));
    }
    
    // Get the inserted ID
    sqlsrv_next_result($stmt);
    $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    
    return $row ? intval($row['id']) : null;
}

// ============================================
// MESSAGE BUILDING FUNCTIONS
// ============================================

/**
 * Map POST data to entry data structure
 */
function mapPostToEntryData($data) {
    return [
        'entry_type' => strtoupper($data['type'] ?? 'MIT'),
        'determinant' => strip_tags($data['determinant'] ?? ''),
        'raw' => strip_tags($data['raw'] ?? ''),
        
        'requesting_facility' => strtoupper(strip_tags($data['to_facility'] ?? $data['req_facility_id'] ?? '')),
        'providing_facility' => strtoupper(strip_tags($data['from_facility'] ?? $data['prov_facility_id'] ?? '')),
        
        'airport' => strtoupper(strip_tags($data['condition'] ?? $data['airport'] ?? '')),
        'ctl_element' => strtoupper(strip_tags($data['condition'] ?? $data['airport'] ?? '')),
        'condition_text' => strip_tags($data['via_route'] ?? ''),
        'fix' => strip_tags($data['via_route'] ?? ''),
        
        'restriction_value' => strip_tags($data['distance'] ?? $data['minutes'] ?? ''),
        'distance' => strip_tags($data['distance'] ?? ''),
        'minutes' => strip_tags($data['minutes'] ?? ''),
        
        'flow_type' => ($data['is_departures'] ?? false) ? 'departures' : 'arrivals',
        'qualifiers' => strip_tags($data['qualifiers'] ?? ''),
        
        'aircraft_type' => strip_tags($data['aircraft_type'] ?? ''),
        'altitude' => strip_tags($data['altitude'] ?? ''),
        'alt_type' => strtoupper(strip_tags($data['alt_type'] ?? '')),
        
        'reason_code' => strtoupper(strip_tags($data['reason'] ?? 'VOLUME')),
        'reason_detail' => strip_tags($data['weather'] ?? $data['reason_detail'] ?? ''),
        
        'exclusions' => strtoupper(strip_tags($data['exclusions'] ?? 'NONE')),
        
        'valid_from' => strip_tags($data['valid_from'] ?? ''),
        'valid_until' => strip_tags($data['valid_until'] ?? ''),
        
        // Delay specific
        'delay_type' => strtoupper(strip_tags($data['delay_type'] ?? 'D/D')),
        'delay_facility' => strtoupper(strip_tags($data['facility'] ?? $data['airport'] ?? '')),
        'longest_delay' => strip_tags($data['minutes'] ?? $data['delay_minutes'] ?? '')),
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
        
        // TBM specific
        'sector' => strip_tags($data['sector'] ?? ''),
        
        // GS/GDP specific
        'gs_gs_end_time' => strip_tags($data['gs_end_time'] ?? ''),
        'gs_included_flights' => strip_tags($data['gs_included_flights'] ?? ''),
        'gdp_program_rate' => strip_tags($data['gdp_program_rate'] ?? ''),
        'gdp_delay_limit' => strip_tags($data['gdp_delay_limit'] ?? ''),
        'advisory_number' => strip_tags($data['advisory_number'] ?? ''),
    ];
}

/**
 * Build NTML message from entry data
 */
function buildNTMLMessageFromEntry($entry) {
    $logTime = gmdate('d/Hi');
    $type = strtoupper($entry['entry_type'] ?? 'MIT');
    
    switch ($type) {
        case 'MIT':
        case 'MINIT':
            return formatMitMinitMessage($entry, $logTime);
        case 'STOP':
            return formatStopMessage($entry, $logTime);
        case 'APREQ':
        case 'CFR':
            return formatApreqCfrMessage($entry, $logTime);
        case 'DELAY':
        case 'HOLDING':
            return formatDelayMessage($entry, $logTime);
        case 'CONFIG':
            return formatConfigMessage($entry, $logTime);
        case 'CANCEL':
            return formatCancelMessage($entry, $logTime);
        case 'TBM':
            return formatTbmMessage($entry, $logTime);
        case 'GS':
            return formatGroundStopMessage($entry, $logTime);
        case 'GDP':
            return formatGdpMessage($entry, $logTime);
        default:
            return "$logTime {$entry['raw']}";
    }
}

/**
 * Format MIT/MINIT message
 * v1.6.0: Category:Cause format for reason
 */
function formatMitMinitMessage($entry, $logTime) {
    $type = strtoupper($entry['entry_type']);
    $airport = strtoupper($entry['airport'] ?? $entry['ctl_element'] ?? '');
    $fix = strtoupper($entry['fix'] ?? $entry['condition_text'] ?? '');
    $flowType = strtolower($entry['flow_type'] ?? 'arrivals');
    
    // Restriction value
    $value = $entry['restriction_value'] ?? $entry['distance'] ?? $entry['minutes'] ?? '';
    $restriction = "{$value}{$type}";
    
    // Qualifiers
    $qualifiers = '';
    if (!empty($entry['qualifiers'])) {
        $quals = is_string($entry['qualifiers']) ? explode(',', $entry['qualifiers']) : $entry['qualifiers'];
        $qualifiers = ' ' . implode(' ', array_map(function($q) {
            return strtoupper(str_replace('_', ' ', trim($q)));
        }, $quals));
    }
    
    // Build reason (Category:Cause format per v1.6.0)
    $reason = buildReasonString($entry);
    
    // Exclusions
    $excl = strtoupper($entry['exclusions'] ?? 'NONE');
    
    // Valid time
    $validFrom = $entry['valid_from'] ?? gmdate('Hi');
    $validUntil = $entry['valid_until'] ?? gmdate('Hi', strtotime('+2 hours'));
    
    // Facilities
    $reqFac = strtoupper($entry['requesting_facility'] ?? '');
    $provFac = strtoupper($entry['providing_facility'] ?? '');
    
    // Build message
    $line = "{$logTime}    {$airport}";
    if ($flowType === 'departures') {
        $line .= ' departures';
    }
    if ($fix) {
        $line .= " via {$fix}";
    }
    $line .= " {$restriction}{$qualifiers} {$reason} EXCL:{$excl} {$validFrom}-{$validUntil}";
    if ($reqFac || $provFac) {
        $line .= " {$reqFac}:{$provFac}";
    }
    
    return trim($line);
}

/**
 * Format STOP message
 */
function formatStopMessage($entry, $logTime) {
    $airport = strtoupper($entry['airport'] ?? $entry['ctl_element'] ?? '');
    $fix = strtoupper($entry['fix'] ?? '');
    $flowType = strtolower($entry['flow_type'] ?? 'arrivals');
    
    $reason = buildReasonString($entry);
    $excl = strtoupper($entry['exclusions'] ?? 'NONE');
    $validFrom = $entry['valid_from'] ?? gmdate('Hi');
    $validUntil = $entry['valid_until'] ?? gmdate('Hi', strtotime('+30 minutes'));
    
    $reqFac = strtoupper($entry['requesting_facility'] ?? '');
    $provFac = strtoupper($entry['providing_facility'] ?? '');
    
    $line = "{$logTime}    {$airport}";
    if ($flowType === 'departures') {
        $line .= ' departures';
    }
    if ($fix) {
        $line .= " via {$fix}";
    }
    $line .= " STOP {$reason} EXCL:{$excl} {$validFrom}-{$validUntil}";
    if ($reqFac || $provFac) {
        $line .= " {$reqFac}:{$provFac}";
    }
    
    return trim($line);
}

/**
 * Format APREQ/CFR message
 */
function formatApreqCfrMessage($entry, $logTime) {
    $type = strtoupper($entry['entry_type']);
    $airport = strtoupper($entry['airport'] ?? $entry['ctl_element'] ?? '');
    $fix = strtoupper($entry['fix'] ?? '');
    $flowType = strtolower($entry['flow_type'] ?? 'departures');
    
    $reason = buildReasonString($entry);
    $excl = strtoupper($entry['exclusions'] ?? 'NONE');
    $validFrom = $entry['valid_from'] ?? gmdate('Hi');
    $validUntil = $entry['valid_until'] ?? gmdate('Hi', strtotime('+3 hours'));
    
    $reqFac = strtoupper($entry['requesting_facility'] ?? '');
    $provFac = strtoupper($entry['providing_facility'] ?? '');
    
    $line = "{$logTime}    {$type} {$airport}";
    if ($flowType === 'departures') {
        $line .= ' departures';
    }
    if ($fix) {
        $line .= " via {$fix}";
    }
    $line .= " {$reason} EXCL:{$excl} {$validFrom}-{$validUntil}";
    if ($reqFac || $provFac) {
        $line .= " {$reqFac}:{$provFac}";
    }
    
    return trim($line);
}

/**
 * Format Delay message
 */
function formatDelayMessage($entry, $logTime) {
    $delayType = strtoupper($entry['delay_type'] ?? 'D/D');
    if ($delayType === 'ED') $delayType = 'E/D';
    if ($delayType === 'AD') $delayType = 'A/D';
    if ($delayType === 'DD') $delayType = 'D/D';
    
    $prep = 'from';
    if ($delayType === 'E/D') $prep = 'for';
    if ($delayType === 'A/D') $prep = 'to';
    
    $facility = strtoupper($entry['delay_facility'] ?? $entry['airport'] ?? '');
    $delayMin = $entry['longest_delay'] ?? $entry['minutes'] ?? '';
    $trend = strtolower($entry['delay_trend'] ?? 'steady');
    $holding = $entry['holding'] ?? 'no';
    
    $sign = '';
    if ($trend === 'increasing' || $trend === 'initiating') $sign = '+';
    elseif ($trend === 'decreasing' || $trend === 'terminating') $sign = '-';
    
    $delayValue = '';
    if ($holding === 'yes' || strpos(strtolower($holding), 'holding') !== false) {
        $delayValue = ($sign ?: '+') . 'Holding';
    } else {
        $delayValue = "{$sign}{$delayMin}";
    }
    
    $reportTime = $entry['report_time'] ?? gmdate('Hi');
    $acftCount = $entry['flights_delayed'] ?? '';
    $reason = buildReasonString($entry);
    
    $line = "{$logTime} {$delayType} {$prep} {$facility}, {$delayValue}/{$reportTime}";
    if ($acftCount) {
        $line .= "/{$acftCount} ACFT";
    }
    $line .= " {$reason}";
    
    return trim($line);
}

/**
 * Format Config message
 */
function formatConfigMessage($entry, $logTime) {
    $airport = strtoupper($entry['airport'] ?? $entry['ctl_element'] ?? '');
    $weather = strtoupper($entry['weather'] ?? 'VMC');
    $arrRwys = strtoupper($entry['arr_runways'] ?? '');
    $depRwys = strtoupper($entry['dep_runways'] ?? '');
    $aar = $entry['aar'] ?? '60';
    $aarType = $entry['aar_type'] ?? 'Strat';
    $aarAdj = $entry['aar_adjustment'] ?? '';
    $adr = $entry['adr'] ?? '60';
    
    $line = "{$logTime}    {$airport}    {$weather}    ARR:{$arrRwys} DEP:{$depRwys}    AAR({$aarType}):{$aar}";
    if ($aarAdj) {
        $line .= " AAR Adjustment:{$aarAdj}";
    }
    $line .= "    ADR:{$adr}";
    
    return $line;
}

/**
 * Format Cancel message
 */
function formatCancelMessage($entry, $logTime) {
    $cancelType = strtoupper($entry['cancel_type'] ?? '');
    $airport = strtoupper($entry['airport'] ?? '');
    $fix = strtoupper($entry['fix'] ?? '');
    
    $line = "{$logTime}    ";
    
    if ($cancelType === 'ALL') {
        $line .= 'ALL TMI CANCELLED';
    } else {
        $line .= "CANCEL {$airport}";
        if ($fix) $line .= " via {$fix}";
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
 * Format TBM message
 */
function formatTbmMessage($entry, $logTime) {
    $airport = strtoupper($entry['airport'] ?? $entry['ctl_element'] ?? '');
    $sector = strtoupper($entry['sector'] ?? '');
    $reason = buildReasonString($entry);
    $excl = strtoupper($entry['exclusions'] ?? 'NONE');
    $validFrom = $entry['valid_from'] ?? gmdate('Hi');
    $validUntil = $entry['valid_until'] ?? gmdate('Hi', strtotime('+6 hours'));
    
    $reqFac = strtoupper($entry['requesting_facility'] ?? '');
    $provFac = strtoupper($entry['providing_facility'] ?? '');
    
    $line = "{$logTime}    {$airport} TBM";
    if ($sector) $line .= " {$sector}";
    $line .= " {$reason} EXCL:{$excl} {$validFrom}-{$validUntil}";
    if ($reqFac || $provFac) $line .= " {$reqFac}:{$provFac}";
    
    return trim($line);
}

/**
 * Format Ground Stop advisory message
 */
function formatGroundStopMessage($entry, $logTime) {
    $advNum = $entry['advisory_number'] ?? '';
    $airport = strtoupper($entry['airport'] ?? '');
    $artcc = strtoupper($entry['requesting_facility'] ?? '');
    $reason = buildReasonString($entry);
    $validUntil = $entry['valid_until'] ?? gmdate('Hi', strtotime('+2 hours'));
    $includedFlights = $entry['gs_included_flights'] ?? "ALL FLIGHTS DESTINED TO {$airport}";
    
    $line = "**vATCSCC ADVZY {$advNum} {$airport}/{$artcc}** " . gmdate('m/d/Y') . "\n";
    $line .= "**CDM GROUND STOP**\n\n";
    $line .= "EFFECTIVE: {$logTime}Z UNTIL {$validUntil}Z\n";
    $line .= "FLIGHTS INCLUDED: {$includedFlights}\n";
    $line .= "REASON: {$reason}\n\n";
    $line .= "MONITOR FOR UPDATES";
    
    return $line;
}

/**
 * Format GDP advisory message
 */
function formatGdpMessage($entry, $logTime) {
    $advNum = $entry['advisory_number'] ?? '';
    $airport = strtoupper($entry['airport'] ?? '');
    $artcc = strtoupper($entry['requesting_facility'] ?? '');
    $reason = buildReasonString($entry);
    $validFrom = $entry['valid_from'] ?? gmdate('Hi');
    $validUntil = $entry['valid_until'] ?? gmdate('Hi', strtotime('+6 hours'));
    $programRate = $entry['gdp_program_rate'] ?? '30/30/30/30';
    $delayLimit = $entry['gdp_delay_limit'] ?? '120';
    
    $line = "**vATCSCC ADVZY {$advNum} {$airport}/{$artcc}** " . gmdate('m/d/Y') . "\n";
    $line .= "**CDM GROUND DELAY PROGRAM**\n\n";
    $line .= "EFFECTIVE: {$validFrom}Z - {$validUntil}Z\n";
    $line .= "PROGRAM RATE: {$programRate}\n";
    $line .= "DELAY LIMIT: {$delayLimit} MINUTES\n";
    $line .= "REASON: {$reason}\n\n";
    $line .= "MONITOR FOR UPDATES";
    
    return $line;
}

/**
 * Build reason string in Category:Cause format (v1.6.0)
 */
function buildReasonString($entry) {
    $category = strtoupper($entry['reason_code'] ?? 'VOLUME');
    $cause = strtoupper($entry['reason_detail'] ?? $category);
    
    // Normalize common patterns
    if ($category === 'WEATHER' || $category === 'WX') {
        $category = 'WEATHER';
        if (empty($cause) || $cause === 'WEATHER') {
            $cause = 'WEATHER';
        }
    } elseif ($category === 'VOLUME' || $category === 'VOL') {
        $category = 'VOLUME';
        if (empty($cause) || $cause === 'VOLUME') {
            $cause = 'VOLUME';
        }
    } elseif ($category === 'RUNWAY' || $category === 'RWY') {
        $category = 'RUNWAY';
        if (empty($cause) || $cause === 'RUNWAY') {
            $cause = 'CONFIG';
        }
    } elseif ($category === 'EQUIPMENT' || $category === 'EQUIP') {
        $category = 'EQUIPMENT';
        if (empty($cause) || $cause === 'EQUIPMENT') {
            $cause = 'EQUIPMENT';
        }
    } elseif ($category === 'OTHER') {
        if (empty($cause)) {
            $cause = 'OTHER';
        }
    }
    
    return "{$category}:{$cause}";
}

/**
 * Legacy MySQL logging (for backward compatibility)
 */
function logNTMLEntryMySQL($determinant, $data, $messageId, $isProduction, $conn) {
    if (!$conn) return;
    
    $safeData = array_map('strip_tags', $data);
    $jsonData = json_encode($safeData);
    $cid = intval($_SESSION['VATSIM_CID'] ?? 0);
    $isTest = $isProduction ? 0 : 1;
    
    // Ensure table exists
    $tableCheck = $conn->query("SHOW TABLES LIKE 'ntml_entries'");
    if ($tableCheck->num_rows === 0) {
        $sql = "CREATE TABLE IF NOT EXISTS ntml_entries (
            id INT AUTO_INCREMENT PRIMARY KEY,
            determinant_code VARCHAR(10),
            protocol_type TINYINT,
            entry_data JSON,
            submitted_by INT,
            submitted_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            discord_message_id VARCHAR(20),
            is_test BOOLEAN DEFAULT FALSE,
            INDEX idx_determinant (determinant_code),
            INDEX idx_submitted (submitted_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
        $conn->query($sql);
    }
    
    $sql = "INSERT INTO ntml_entries (determinant_code, protocol_type, entry_data, submitted_by, discord_message_id, is_test) 
            VALUES (?, ?, ?, ?, ?, ?)";
    
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $protocol = intval($data['protocol'] ?? 0);
        $stmt->bind_param("sisisi", $determinant, $protocol, $jsonData, $cid, $messageId, $isTest);
        $stmt->execute();
        $stmt->close();
    }
}
