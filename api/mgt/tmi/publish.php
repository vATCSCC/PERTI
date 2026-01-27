<?php
/**
 * TMI Unified Publish API
 * 
 * Handles publishing of NTML entries and advisories to multiple Discord organizations.
 * Supports staging and production modes with full database tracking.
 * 
 * POST /api/mgt/tmi/publish.php
 * 
 * Request Body:
 * {
 *   "entries": [
 *     {
 *       "type": "ntml" | "advisory",
 *       "entryType": "MIT" | "MINIT" | "GS" | "GDP" | etc.,
 *       "data": {...} | null,
 *       "preview": "formatted message" | null,
 *       "orgs": ["vatcscc", "vatcan"],
 *       "rawInput": "original user input"
 *     }
 *   ],
 *   "production": false,
 *   "userCid": "123456"
 * }
 * 
 * @package PERTI
 * @subpackage API/TMI
 * @version 1.1.0
 * @date 2026-01-27
 */

header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle CORS preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Only allow POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

// Debug logging
function tmi_debug_log($message, $data = null) {
    $logFile = '/home/LogFiles/tmi_publish_debug.log';
    if (!is_dir('/home/LogFiles')) {
        $logFile = __DIR__ . '/tmi_publish_debug.log';
    }
    $timestamp = date('Y-m-d H:i:s');
    $entry = "[$timestamp] $message";
    if ($data !== null) {
        $entry .= " | " . json_encode($data, JSON_UNESCAPED_SLASHES);
    }
    @file_put_contents($logFile, $entry . "\n", FILE_APPEND);
}

tmi_debug_log('=== TMI Publish Request Started ===');

// Load dependencies
try {
    require_once __DIR__ . '/../../../load/config.php';
    require_once __DIR__ . '/../../../load/connect.php';
    require_once __DIR__ . '/../../../load/discord/TMIDiscord.php';
    require_once __DIR__ . '/../../../load/discord/MultiDiscordAPI.php';
    tmi_debug_log('Dependencies loaded successfully');
} catch (Exception $e) {
    tmi_debug_log('ERROR loading dependencies', ['error' => $e->getMessage()]);
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Dependency load error: ' . $e->getMessage()]);
    exit;
}

// Parse request body
$input = file_get_contents('php://input');
$payload = json_decode($input, true);

tmi_debug_log('Request received', [
    'content_length' => strlen($input),
    'entry_count' => count($payload['entries'] ?? []),
    'production' => $payload['production'] ?? false
]);

if (!$payload || !isset($payload['entries'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid request body']);
    exit;
}

$entries = $payload['entries'];
$production = !empty($payload['production']);
$userCid = $payload['userCid'] ?? null;

if (empty($entries)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'No entries provided']);
    exit;
}

// Initialize Discord
$tmiDiscord = new TMIDiscord();
$multiDiscord = $tmiDiscord->getMultiDiscordAPI();

if (!$multiDiscord || !$multiDiscord->isConfigured()) {
    tmi_debug_log('ERROR - Discord not configured');
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Discord not configured']);
    exit;
}

// Connect to TMI database for tracking
$tmiConn = null;
try {
    if (defined('TMI_SQL_HOST') && TMI_SQL_HOST) {
        $tmiConn = new PDO(
            "sqlsrv:Server=" . TMI_SQL_HOST . ";Database=" . TMI_SQL_DATABASE,
            TMI_SQL_USERNAME,
            TMI_SQL_PASSWORD
        );
        $tmiConn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        tmi_debug_log('TMI database connected');
    }
} catch (Exception $e) {
    tmi_debug_log('TMI Database connection failed', ['error' => $e->getMessage()]);
    // Continue without database tracking
}

// Process each entry
$results = [];
$successCount = 0;
$failCount = 0;

foreach ($entries as $index => $entry) {
    tmi_debug_log("Processing entry {$index}", ['type' => $entry['type'] ?? 'unknown']);
    
    $result = [
        'index' => $index,
        'type' => $entry['type'] ?? 'unknown',
        'entryType' => $entry['entryType'] ?? null,
        'orgs' => $entry['orgs'] ?? [],
        'success' => false,
        'error' => null,
        'discordResults' => [],
        'entryId' => null
    ];
    
    try {
        // Build message content
        $messageContent = buildMessageContent($entry, $tmiDiscord);
        
        if (empty($messageContent)) {
            $result['error'] = 'Could not build message content';
            $results[] = $result;
            $failCount++;
            tmi_debug_log("Entry {$index} failed - no message content");
            continue;
        }
        
        tmi_debug_log("Entry {$index} message built", ['length' => strlen($messageContent)]);
        
        // Add test prefix for staging
        $prefix = $production ? '' : '🧪 **[STAGING]** ';
        
        $messageData = [
            'content' => $prefix . "```\n{$messageContent}\n```"
        ];
        
        // Get target orgs
        $targetOrgs = $entry['orgs'] ?? ['vatcscc'];
        if (empty($targetOrgs)) {
            $targetOrgs = ['vatcscc'];
        }
        
        // Determine channel purpose based on entry type and mode
        $entryType = $entry['type'] ?? 'ntml';
        $tmiType = ($entryType === 'advisory') ? 'advisory' : 'ntml';
        
        // Save to database first (if available)
        $entryId = null;
        if ($tmiConn) {
            $entryId = saveEntryToDatabase($tmiConn, $entry, $messageContent, $userCid, $production);
            $result['entryId'] = $entryId;
            tmi_debug_log("Entry {$index} saved to DB", ['entryId' => $entryId]);
        }
        
        // Post to Discord
        $discordResults = [];
        foreach ($targetOrgs as $orgCode) {
            $channelPurpose = $production ? $tmiType : ($tmiType . '_staging');
            
            tmi_debug_log("Posting to {$orgCode}/{$channelPurpose}");
            
            $postResult = $multiDiscord->postToChannel($orgCode, $channelPurpose, $messageData);
            $discordResults[$orgCode] = $postResult;
            
            // Track in database
            if ($tmiConn && $entryId) {
                trackDiscordPost($tmiConn, $entryType, $entryId, $orgCode, $channelPurpose, $postResult, $userCid);
            }
        }
        
        $result['discordResults'] = $discordResults;
        
        // Check if all succeeded
        $allSuccess = true;
        foreach ($discordResults as $orgCode => $orgResult) {
            if (!($orgResult['success'] ?? false)) {
                $allSuccess = false;
                tmi_debug_log("Discord post failed for {$orgCode}", ['error' => $orgResult['error'] ?? 'unknown']);
            }
        }
        
        $result['success'] = $allSuccess;
        if ($allSuccess) {
            $successCount++;
            tmi_debug_log("Entry {$index} succeeded");
        } else {
            $failCount++;
            $result['error'] = 'Some organizations failed';
        }
        
    } catch (Exception $e) {
        $result['error'] = $e->getMessage();
        $failCount++;
        tmi_debug_log("Entry {$index} exception", ['error' => $e->getMessage()]);
    }
    
    $results[] = $result;
}

// Response
$response = [
    'success' => ($failCount === 0),
    'summary' => [
        'total' => count($entries),
        'success' => $successCount,
        'failed' => $failCount,
        'production' => $production
    ],
    'results' => $results
];

tmi_debug_log('=== Request complete ===', $response['summary']);

echo json_encode($response);

// ===========================================
// Helper Functions
// ===========================================

/**
 * Build message content from entry
 */
function buildMessageContent($entry, $tmiDiscord) {
    $type = $entry['type'] ?? 'ntml';
    
    if ($type === 'advisory') {
        // Advisory already has preview text
        return $entry['preview'] ?? '';
    }
    
    // NTML entry - format the message
    $data = $entry['data'] ?? [];
    
    if (empty($data)) {
        // Try raw input
        return $entry['rawInput'] ?? '';
    }
    
    // Build NTML message using proper format
    return buildNTMLMessage($data);
}

/**
 * Build NTML message in proper format
 * Format: DD/HHMM APT [direction] via FIX ##TYPE [QUALIFIERS] REASON EXCL:xxx HHMM-HHMM REQ:PROV
 */
function buildNTMLMessage($data) {
    $logTime = gmdate('d/Hi');
    $type = strtoupper($data['type'] ?? 'MIT');
    
    switch ($type) {
        case 'MIT':
        case 'MINIT':
            return buildRestrictionNTML($data, $logTime);
        case 'DELAY':
            return buildDelayNTML($data, $logTime);
        case 'CONFIG':
            return buildConfigNTML($data, $logTime);
        case 'GS':
            return buildGroundStopNTML($data, $logTime);
        case 'STOP':
            return buildFlowStopNTML($data, $logTime);
        default:
            // Generic format
            return "{$logTime}    " . ($data['raw'] ?? json_encode($data));
    }
}

/**
 * Build MIT/MINIT NTML entry
 */
function buildRestrictionNTML($data, $logTime) {
    $type = strtoupper($data['type'] ?? 'MIT');
    $airport = strtoupper($data['airport'] ?? $data['facility'] ?? '');
    $fix = strtoupper($data['fix'] ?? '');
    $distance = $data['distance'] ?? $data['miles'] ?? '';
    $reason = strtoupper($data['reason'] ?? 'VOLUME');
    $fromFac = strtoupper($data['fromFacility'] ?? '');
    $toFac = strtoupper($data['toFacility'] ?? '');
    $startTime = $data['startTime'] ?? gmdate('Hi');
    $endTime = $data['endTime'] ?? gmdate('Hi', strtotime('+2 hours'));
    
    // Build the line
    $parts = ["{$logTime}"];
    $parts[] = "   {$airport}";
    
    if ($fix) {
        $parts[] = "via {$fix}";
    }
    
    $parts[] = "{$distance}{$type}";
    
    // Reason
    if ($reason === 'VOLUME') {
        $parts[] = 'VOLUME:VOLUME';
    } elseif ($reason === 'WEATHER') {
        $parts[] = 'WEATHER:WEATHER';
    } else {
        $parts[] = "{$reason}:{$reason}";
    }
    
    $parts[] = 'EXCL:NONE';
    $parts[] = "{$startTime}-{$endTime}";
    
    if ($toFac || $fromFac) {
        $parts[] = "{$toFac}:{$fromFac}";
    }
    
    return implode(' ', $parts);
}

/**
 * Build Delay NTML entry
 */
function buildDelayNTML($data, $logTime) {
    $facility = strtoupper($data['facility'] ?? $data['airport'] ?? '');
    $minutes = $data['minutes'] ?? '0';
    $trend = strtoupper($data['trend'] ?? 'STABLE');
    $reason = strtoupper($data['reason'] ?? 'VOLUME');
    
    $sign = '';
    if ($trend === 'INC' || $trend === 'INCREASING') $sign = '+';
    if ($trend === 'DEC' || $trend === 'DECREASING') $sign = '-';
    
    return "{$logTime}    D/D from {$facility}, {$sign}{$minutes}/{$logTime} {$reason}:{$reason}";
}

/**
 * Build Config NTML entry
 */
function buildConfigNTML($data, $logTime) {
    $airport = strtoupper($data['airport'] ?? '');
    $weather = strtoupper($data['weather'] ?? 'VMC');
    
    // Parse raw config if available
    if (!empty($data['rawConfig'])) {
        return "{$logTime}    " . $data['rawConfig'];
    }
    
    return "{$logTime}    {$airport}    {$weather}    CONFIG CHANGE";
}

/**
 * Build Ground Stop NTML entry
 */
function buildGroundStopNTML($data, $logTime) {
    $airport = strtoupper($data['airport'] ?? '');
    $reason = strtoupper($data['reason'] ?? 'WEATHER');
    
    return "{$logTime}    {$airport} GROUND STOP - {$reason}";
}

/**
 * Build Flow Stop NTML entry
 */
function buildFlowStopNTML($data, $logTime) {
    $fromFac = strtoupper($data['fromFacility'] ?? '');
    $toFac = strtoupper($data['toFacility'] ?? '');
    
    return "{$logTime}    STOP {$toFac}:{$fromFac}";
}

/**
 * Save entry to TMI database
 */
function saveEntryToDatabase($conn, $entry, $messageContent, $userCid, $isProduction) {
    $type = $entry['type'] ?? 'ntml';
    $entryType = $entry['entryType'] ?? 'OTHER';
    $data = $entry['data'] ?? [];
    
    try {
        if ($type === 'advisory') {
            // Save to tmi_advisories
            $sql = "INSERT INTO dbo.tmi_advisories 
                    (advisory_type, advisory_number, facility_code, ctl_element, 
                     valid_from, valid_until, content_text, source_type, created_by)
                    OUTPUT INSERTED.advisory_id
                    VALUES (?, ?, ?, ?, SYSUTCDATETIME(), DATEADD(HOUR, 2, SYSUTCDATETIME()), ?, 'PERTI', ?)";
            
            $stmt = $conn->prepare($sql);
            $stmt->execute([
                $entryType,
                '001', // Default number
                'DCC',
                $data['ctl_element'] ?? $data['airport'] ?? '',
                $messageContent,
                $userCid
            ]);
            
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return $result['advisory_id'] ?? null;
            
        } else {
            // Save to tmi_entries
            $sql = "INSERT INTO dbo.tmi_entries 
                    (entry_type, ctl_element, determinant_code, 
                     requesting_facility, providing_facility,
                     restriction_value, reason_code,
                     valid_from, valid_until,
                     raw_text, source_type, created_by,
                     status)
                    OUTPUT INSERTED.entry_id
                    VALUES (?, ?, ?, ?, ?, ?, ?, 
                            SYSUTCDATETIME(), DATEADD(HOUR, 2, SYSUTCDATETIME()),
                            ?, 'PERTI', ?, ?)";
            
            $stmt = $conn->prepare($sql);
            $stmt->execute([
                $entryType,
                $data['airport'] ?? $data['facility'] ?? '',
                null, // determinant
                $data['toFacility'] ?? '',
                $data['fromFacility'] ?? '',
                $data['distance'] ?? $data['minutes'] ?? null,
                $data['reason'] ?? 'VOLUME',
                $messageContent,
                $userCid,
                $isProduction ? 'PUBLISHED' : 'STAGED'
            ]);
            
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return $result['entry_id'] ?? null;
        }
    } catch (Exception $e) {
        tmi_debug_log('Database save error', ['error' => $e->getMessage()]);
        return null;
    }
}

/**
 * Track Discord post in database
 */
function trackDiscordPost($conn, $entityType, $entityId, $orgCode, $channelPurpose, $discordResult, $userCid) {
    if (!$entityId) return;
    
    try {
        $sql = "INSERT INTO dbo.tmi_discord_posts 
                (entity_type, entity_id, org_code, channel_purpose, 
                 channel_id, message_id, message_url, 
                 status, direction, created_by,
                 requested_at, posted_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'OUTBOUND', ?, SYSUTCDATETIME(), 
                        CASE WHEN ? = 1 THEN SYSUTCDATETIME() ELSE NULL END)";
        
        $stmt = $conn->prepare($sql);
        $success = $discordResult['success'] ?? false;
        $stmt->execute([
            strtoupper($entityType) === 'ADVISORY' ? 'ADVISORY' : 'ENTRY',
            $entityId,
            $orgCode,
            $channelPurpose,
            $discordResult['channel_id'] ?? '',
            $discordResult['message_id'] ?? null,
            $discordResult['message_url'] ?? null,
            $success ? 'POSTED' : 'FAILED',
            $userCid,
            $success ? 1 : 0
        ]);
        
    } catch (Exception $e) {
        tmi_debug_log('Discord tracking error', ['error' => $e->getMessage()]);
    }
}
