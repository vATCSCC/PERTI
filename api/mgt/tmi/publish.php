<?php
/**
 * Unified TMI Publish API
 * 
 * Handles publishing of NTML entries and Advisories to multiple Discord organizations.
 * Supports staging/production workflow and cross-border TMI posting.
 * 
 * @package PERTI
 * @subpackage TMI/API
 * @version 1.0.0
 * @date 2026-01-27
 * 
 * Endpoints:
 *   POST /api/mgt/tmi/publish.php
 *     - entry_type: 'NTML', 'NTML_BATCH', 'ADVISORY'
 *     - data: Entry/Advisory data object
 *     - entries: Array of entries (for NTML_BATCH)
 *     - targets: { orgs: [], staging: bool, production: bool }
 *     - source: 'PERTI', 'API', 'DISCORD'
 *     - user_cid, user_name
 */

header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store, must-revalidate');

// Error handling
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    error_log("[TMI Publish] PHP Error: $errstr in $errfile:$errline");
    return false;
});

register_shutdown_function(function() {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR])) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Fatal error: ' . $error['message']]);
    }
});

// Start session
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Load dependencies
require_once __DIR__ . '/../../../load/config.php';
require_once __DIR__ . '/../../../load/connect.php';
require_once __DIR__ . '/../../../load/discord/MultiDiscordAPI.php';
require_once __DIR__ . '/../../../load/discord/TMIDiscord.php';

// Response helper
function sendResponse($success, $data = [], $error = null, $httpCode = 200) {
    http_response_code($httpCode);
    echo json_encode(array_merge(
        ['success' => $success],
        $data,
        $error ? ['error' => $error] : []
    ));
    exit();
}

// Get request body
$input = file_get_contents('php://input');
$request = json_decode($input, true);

if (!$request) {
    // Try POST form data as fallback
    $request = $_POST;
}

if (empty($request)) {
    sendResponse(false, [], 'No request data provided', 400);
}

// Validate required fields
if (empty($request['entry_type'])) {
    sendResponse(false, [], 'Missing entry_type', 400);
}

if (empty($request['targets']['orgs']) || !is_array($request['targets']['orgs'])) {
    sendResponse(false, [], 'No target organizations specified', 400);
}

// Permission check
$userCID = $request['user_cid'] ?? ($_SESSION['VATSIM_CID'] ?? null);
$userName = $request['user_name'] ?? ($_SESSION['VATSIM_FIRST_NAME'] ?? 'Unknown');
$isAuthorized = false;

if (defined('DEV')) {
    $isAuthorized = true;
} elseif ($userCID) {
    global $conn_sqli;
    $stmt = $conn_sqli->prepare("SELECT role FROM users WHERE cid = ?");
    if ($stmt) {
        $stmt->bind_param("s", $userCID);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result && $result->num_rows > 0) {
            $isAuthorized = true;
        }
        $stmt->close();
    }
}

if (!$isAuthorized) {
    sendResponse(false, [], 'Unauthorized', 403);
}

// Initialize Discord APIs
$multiDiscord = new MultiDiscordAPI();
$tmiDiscord = new TMIDiscord();

if (!$multiDiscord->isConfigured()) {
    sendResponse(false, [], 'Discord integration not configured', 500);
}

// Extract request parameters
$entryType = strtoupper($request['entry_type']);
$targetOrgs = $request['targets']['orgs'];
$isStaging = !empty($request['targets']['staging']);
$isProduction = !empty($request['targets']['production']);
$source = $request['source'] ?? 'PERTI';

// Validate target orgs
$validOrgs = [];
foreach ($targetOrgs as $orgCode) {
    if ($multiDiscord->isOrgEnabled($orgCode)) {
        $validOrgs[] = $orgCode;
    }
}

if (empty($validOrgs)) {
    sendResponse(false, [], 'No valid target organizations specified', 400);
}

// Process based on entry type
$results = [];
$entries = [];

try {
    switch ($entryType) {
        case 'NTML':
            // Single NTML entry
            $entries = [$request['data']];
            $results = processNTMLEntries($entries, $validOrgs, $isStaging, $tmiDiscord, $multiDiscord, $userCID, $userName, $source);
            break;
            
        case 'NTML_BATCH':
            // Multiple NTML entries
            $entries = $request['entries'] ?? [];
            if (empty($entries)) {
                sendResponse(false, [], 'No entries provided for batch', 400);
            }
            $results = processNTMLEntries($entries, $validOrgs, $isStaging, $tmiDiscord, $multiDiscord, $userCID, $userName, $source);
            break;
            
        case 'ADVISORY':
            // Single advisory
            $entries = [$request['data']];
            $results = processAdvisories($entries, $validOrgs, $isStaging, $tmiDiscord, $multiDiscord, $userCID, $userName, $source);
            break;
            
        default:
            sendResponse(false, [], "Unknown entry_type: $entryType", 400);
    }
    
    // Check if all orgs succeeded
    $allSuccess = true;
    $anySuccess = false;
    foreach ($results as $orgResult) {
        if ($orgResult['success']) {
            $anySuccess = true;
        } else {
            $allSuccess = false;
        }
    }
    
    sendResponse($anySuccess, [
        'results' => $results,
        'entry_count' => count($entries),
        'mode' => $isStaging ? 'staging' : 'production',
        'all_succeeded' => $allSuccess
    ]);
    
} catch (Exception $e) {
    error_log("[TMI Publish] Exception: " . $e->getMessage());
    sendResponse(false, [], 'Server error: ' . $e->getMessage(), 500);
}

// ============================================
// NTML PROCESSING
// ============================================

function processNTMLEntries($entries, $targetOrgs, $isStaging, $tmiDiscord, $multiDiscord, $userCID, $userName, $source) {
    $results = [];
    $channelPurpose = $isStaging ? 'ntml_staging' : 'ntml';
    
    foreach ($entries as $entry) {
        // Format the NTML message
        $message = $tmiDiscord->buildNTMLMessageFromEntry($entry);
        
        // Add staging prefix if applicable
        if ($isStaging) {
            $message = "🧪 [STAGING]\n" . $message;
        }
        
        $messageData = ['content' => "```\n{$message}\n```"];
        
        // Post to each target org
        $orgResults = $multiDiscord->postToOrgs($targetOrgs, $channelPurpose, $messageData);
        
        // Save to database
        $entryId = saveNTMLEntry($entry, $orgResults, $isStaging, $userCID, $userName, $source);
        
        // Track Discord posts
        foreach ($orgResults as $orgCode => $result) {
            saveDiscordPost('ENTRY', $entryId, $orgCode, $channelPurpose, $result, $userCID, $userName);
        }
        
        // Merge results
        foreach ($orgResults as $orgCode => $result) {
            if (!isset($results[$orgCode])) {
                $results[$orgCode] = $result;
            } else {
                // Accumulate for batch
                if (!$result['success']) {
                    $results[$orgCode]['success'] = false;
                    $results[$orgCode]['error'] = ($results[$orgCode]['error'] ?? '') . '; ' . $result['error'];
                }
            }
        }
    }
    
    return $results;
}

// ============================================
// ADVISORY PROCESSING
// ============================================

function processAdvisories($advisories, $targetOrgs, $isStaging, $tmiDiscord, $multiDiscord, $userCID, $userName, $source) {
    $results = [];
    $channelPurpose = $isStaging ? 'advzy_staging' : 'advisories';
    
    foreach ($advisories as $advisory) {
        // Format the advisory message
        $message = '';
        if (!empty($advisory['content'])) {
            // Pre-formatted content
            $message = $advisory['content'];
        } else {
            // Build from structured data
            $message = $tmiDiscord->buildAdvisoryMessage($advisory);
        }
        
        // Add staging prefix if applicable
        if ($isStaging) {
            $message = "🧪 [STAGING]\n" . $message;
        }
        
        $messageData = ['content' => "```\n{$message}\n```"];
        
        // Post to each target org
        $orgResults = $multiDiscord->postToOrgs($targetOrgs, $channelPurpose, $messageData);
        
        // Save to database
        $advisoryId = saveAdvisory($advisory, $orgResults, $isStaging, $userCID, $userName, $source);
        
        // Track Discord posts
        foreach ($orgResults as $orgCode => $result) {
            saveDiscordPost('ADVISORY', $advisoryId, $orgCode, $channelPurpose, $result, $userCID, $userName);
        }
        
        // Merge results
        foreach ($orgResults as $orgCode => $result) {
            if (!isset($results[$orgCode])) {
                $results[$orgCode] = $result;
            } else {
                if (!$result['success']) {
                    $results[$orgCode]['success'] = false;
                    $results[$orgCode]['error'] = ($results[$orgCode]['error'] ?? '') . '; ' . $result['error'];
                }
            }
        }
    }
    
    return $results;
}

// ============================================
// DATABASE FUNCTIONS
// ============================================

/**
 * Save NTML entry to VATSIM_TMI database
 */
function saveNTMLEntry($entry, $discordResults, $isStaging, $userCID, $userName, $source) {
    // Get TMI database connection
    $tmiConn = getTMIConnection();
    if (!$tmiConn) {
        error_log("[TMI Publish] Failed to connect to VATSIM_TMI database");
        return 0;
    }
    
    try {
        $entryType = strtoupper($entry['entry_type'] ?? 'MIT');
        $determinant = $entry['determinant_code'] ?? generateDeterminant($entryType);
        $airport = strtoupper($entry['airport'] ?? $entry['ctl_element'] ?? '');
        $rawInput = $entry['raw_input'] ?? '';
        $contentHash = md5(json_encode([
            $entryType,
            $determinant,
            $airport,
            $entry['valid_from'] ?? '',
            $entry['valid_until'] ?? ''
        ]));
        
        $sql = "INSERT INTO tmi_entries (
            entry_type, determinant_code, ctl_element, condition_text,
            valid_from, valid_until, raw_input, source_type, source_channel,
            content_hash, created_by_cid, created_by_name, status
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        
        $stmt = $tmiConn->prepare($sql);
        
        $conditionText = $entry['condition_text'] ?? $entry['via_route'] ?? '';
        $validFrom = $entry['valid_from'] ?? null;
        $validUntil = $entry['valid_until'] ?? null;
        $channel = $isStaging ? 'staging' : 'production';
        $status = $isStaging ? 'STAGED' : 'ACTIVE';
        
        $stmt->bind_param(
            "sssssssssssss",
            $entryType, $determinant, $airport, $conditionText,
            $validFrom, $validUntil, $rawInput, $source, $channel,
            $contentHash, $userCID, $userName, $status
        );
        
        $stmt->execute();
        $entryId = $tmiConn->insert_id;
        $stmt->close();
        
        return $entryId;
        
    } catch (Exception $e) {
        error_log("[TMI Publish] Error saving NTML entry: " . $e->getMessage());
        return 0;
    }
}

/**
 * Save advisory to VATSIM_TMI database
 */
function saveAdvisory($advisory, $discordResults, $isStaging, $userCID, $userName, $source) {
    $tmiConn = getTMIConnection();
    if (!$tmiConn) {
        error_log("[TMI Publish] Failed to connect to VATSIM_TMI database");
        return 0;
    }
    
    try {
        $advisoryType = strtoupper($advisory['type'] ?? $advisory['advisory_type'] ?? 'ATCSCC');
        $advisoryNumber = $advisory['number'] ?? $advisory['adv_number'] ?? null;
        $facility = strtoupper($advisory['facility'] ?? $advisory['adv_facility'] ?? 'DCC');
        $ctlElement = strtoupper($advisory['ctl_element'] ?? $advisory['adv_ctl_element'] ?? '');
        $subject = $advisory['subject'] ?? $advisory['atcscc_subject'] ?? '';
        $body = $advisory['body'] ?? $advisory['content'] ?? $advisory['atcscc_body'] ?? '';
        
        $contentHash = md5(json_encode([
            $advisoryType,
            $facility,
            $ctlElement,
            substr($body, 0, 100)
        ]));
        
        $sql = "INSERT INTO tmi_advisories (
            advisory_type, advisory_number, issuing_facility, ctl_element,
            subject, body, valid_from, valid_until,
            source_type, content_hash, created_by_cid, created_by_name, status
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        
        $stmt = $tmiConn->prepare($sql);
        
        $validFrom = $advisory['start'] ?? $advisory['adv_start'] ?? null;
        $validUntil = $advisory['end'] ?? $advisory['adv_end'] ?? null;
        $status = $isStaging ? 'STAGED' : 'ACTIVE';
        
        $stmt->bind_param(
            "sssssssssssss",
            $advisoryType, $advisoryNumber, $facility, $ctlElement,
            $subject, $body, $validFrom, $validUntil,
            $source, $contentHash, $userCID, $userName, $status
        );
        
        $stmt->execute();
        $advisoryId = $tmiConn->insert_id;
        $stmt->close();
        
        return $advisoryId;
        
    } catch (Exception $e) {
        error_log("[TMI Publish] Error saving advisory: " . $e->getMessage());
        return 0;
    }
}

/**
 * Save Discord post tracking record
 */
function saveDiscordPost($entityType, $entityId, $orgCode, $channelPurpose, $result, $userCID, $userName) {
    if (!$entityId) return;
    
    $tmiConn = getTMIConnection();
    if (!$tmiConn) return;
    
    try {
        $sql = "INSERT INTO tmi_discord_posts (
            entity_type, entity_id, org_code, channel_purpose, channel_id,
            message_id, message_url, status, error_message,
            direction, created_by, created_by_name, posted_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'OUTBOUND', ?, ?, ?)";
        
        $stmt = $tmiConn->prepare($sql);
        
        $channelId = $result['channel_id'] ?? '';
        $messageId = $result['message_id'] ?? null;
        $messageUrl = $result['message_url'] ?? null;
        $status = $result['success'] ? 'POSTED' : 'FAILED';
        $error = $result['error'] ?? null;
        $postedAt = $result['success'] ? gmdate('Y-m-d H:i:s') : null;
        
        $stmt->bind_param(
            "sissssssssss",
            $entityType, $entityId, $orgCode, $channelPurpose, $channelId,
            $messageId, $messageUrl, $status, $error,
            $userCID, $userName, $postedAt
        );
        
        $stmt->execute();
        $stmt->close();
        
    } catch (Exception $e) {
        error_log("[TMI Publish] Error saving Discord post: " . $e->getMessage());
    }
}

/**
 * Get VATSIM_TMI database connection
 */
function getTMIConnection() {
    static $tmiConn = null;
    
    if ($tmiConn === null) {
        try {
            // Use sqlsrv for Azure SQL
            $serverName = TMI_SQL_HOST;
            $connectionOptions = [
                "Database" => TMI_SQL_DATABASE,
                "Uid" => TMI_SQL_USERNAME,
                "PWD" => TMI_SQL_PASSWORD,
                "Encrypt" => true,
                "TrustServerCertificate" => false,
                "CharacterSet" => "UTF-8"
            ];
            
            $tmiConn = sqlsrv_connect($serverName, $connectionOptions);
            
            if ($tmiConn === false) {
                error_log("[TMI Publish] Azure SQL connection failed: " . print_r(sqlsrv_errors(), true));
                return null;
            }
            
        } catch (Exception $e) {
            error_log("[TMI Publish] TMI connection error: " . $e->getMessage());
            return null;
        }
    }
    
    return $tmiConn;
}

/**
 * Generate determinant code
 */
function generateDeterminant($type) {
    $typeCode = [
        'MIT' => 'A', 'MINIT' => 'B', 'STOP' => 'C', 'DELAY' => 'D',
        'CONFIG' => 'E', 'TBM' => 'F', 'CFR' => 'G', 'APREQ' => 'H',
        'DSP' => 'I'
    ][$type] ?? 'A';
    
    $day = gmdate('d');
    $hour = gmdate('H');
    $seq = str_pad(rand(0, 99), 2, '0', STR_PAD_LEFT);
    
    return "{$day}{$typeCode}{$hour}";
}
