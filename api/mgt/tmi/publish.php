<?php
/**
 * TMI Unified Publish API
 * 
 * Handles publishing of NTML entries and advisories to multiple Discord organizations.
 * Supports staging and production modes.
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
 *       "orgs": ["vatcscc", "vatcan"]
 *     }
 *   ],
 *   "production": false,
 *   "userCid": "123456"
 * }
 * 
 * @package PERTI
 * @subpackage API/TMI
 * @version 1.0.0
 * @date 2026-01-27
 */

header('Content-Type: application/json');
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

// Load dependencies
require_once __DIR__ . '/../../../load/config.php';
require_once __DIR__ . '/../../../load/discord/TMIDiscord.php';
require_once __DIR__ . '/../../../load/discord/MultiDiscordAPI.php';

// Parse request body
$input = file_get_contents('php://input');
$payload = json_decode($input, true);

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

if (!$multiDiscord->isConfigured()) {
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
    }
} catch (Exception $e) {
    error_log("TMI Publish: Database connection failed: " . $e->getMessage());
    // Continue without database tracking
}

// Process each entry
$results = [];
$successCount = 0;
$failCount = 0;

foreach ($entries as $index => $entry) {
    $result = [
        'index' => $index,
        'type' => $entry['type'] ?? 'unknown',
        'orgs' => $entry['orgs'] ?? [],
        'success' => false,
        'error' => null,
        'discordResults' => []
    ];
    
    try {
        // Build message content
        $messageContent = buildMessageContent($entry, $tmiDiscord);
        
        if (empty($messageContent)) {
            $result['error'] = 'Could not build message content';
            $results[] = $result;
            $failCount++;
            continue;
        }
        
        $messageData = [
            'content' => "```\n{$messageContent}\n```"
        ];
        
        // Get target orgs
        $targetOrgs = $entry['orgs'] ?? ['vatcscc'];
        
        // Determine channel purpose
        $entryType = $entry['type'] ?? 'ntml';
        $tmiType = ($entryType === 'advisory') ? 'advisory' : 'ntml';
        
        // Post to Discord
        if ($production) {
            $discordResults = $multiDiscord->postToProduction($targetOrgs, $tmiType, $messageData);
        } else {
            $discordResults = $multiDiscord->postToStaging($targetOrgs, $tmiType, $messageData);
        }
        
        // Track results
        $allSuccess = true;
        foreach ($discordResults as $orgCode => $orgResult) {
            $result['discordResults'][$orgCode] = [
                'success' => $orgResult['success'],
                'messageId' => $orgResult['message_id'] ?? null,
                'messageUrl' => $orgResult['message_url'] ?? null,
                'error' => $orgResult['error'] ?? null
            ];
            
            if (!$orgResult['success']) {
                $allSuccess = false;
            }
            
            // Track in database
            if ($tmiConn && $orgResult['success']) {
                trackDiscordPost($tmiConn, $entry, $orgCode, $orgResult, $production, $userCid);
            }
        }
        
        $result['success'] = $allSuccess;
        if ($allSuccess) {
            $successCount++;
        } else {
            $failCount++;
            $result['error'] = 'Some organizations failed';
        }
        
    } catch (Exception $e) {
        $result['error'] = $e->getMessage();
        $failCount++;
        error_log("TMI Publish error for entry {$index}: " . $e->getMessage());
    }
    
    $results[] = $result;
}

// Response
echo json_encode([
    'success' => ($failCount === 0),
    'summary' => [
        'total' => count($entries),
        'success' => $successCount,
        'failed' => $failCount,
        'production' => $production
    ],
    'results' => $results
]);

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
    
    // NTML entry - format using TMIDiscord
    $data = $entry['data'] ?? [];
    
    if (empty($data)) {
        return '';
    }
    
    // Use TMIDiscord's formatters
    $entryData = [
        'entry_type' => $data['type'] ?? 'OTHER',
        'airport' => $data['airport'] ?? $data['facility'] ?? '',
        'fix' => $data['fix'] ?? '',
        'distance' => $data['distance'] ?? $data['miles'] ?? '',
        'reason' => $data['reason'] ?? 'VOLUME',
        'requesting_facility' => $data['toFacility'] ?? '',
        'providing_facility' => $data['fromFacility'] ?? '',
        'start_time' => $data['startTime'] ?? null,
        'end_time' => $data['endTime'] ?? null,
    ];
    
    return $tmiDiscord->buildNTMLMessageFromEntry($entryData);
}

/**
 * Track Discord post in database
 */
function trackDiscordPost($conn, $entry, $orgCode, $discordResult, $production, $userCid) {
    try {
        $sql = "EXEC sp_UpsertDiscordPost 
            @entity_type = :entity_type,
            @entity_id = :entity_id,
            @org_code = :org_code,
            @channel_purpose = :channel_purpose,
            @channel_id = :channel_id,
            @guild_id = :guild_id,
            @message_id = :message_id,
            @message_url = :message_url,
            @status = :status,
            @direction = :direction,
            @created_by = :created_by,
            @post_id = :post_id";
        
        // For now, use a placeholder entity_id since we're not storing entries yet
        // In full implementation, this would be the tmi_entries.id or tmi_advisories.id
        $entityType = ($entry['type'] === 'advisory') ? 'ADVISORY' : 'ENTRY';
        $entityId = 0; // Placeholder
        $channelPurpose = ($entry['type'] === 'advisory') ? 'advisories' : 'ntml';
        if (!$production) {
            $channelPurpose .= '_staging';
        }
        
        $stmt = $conn->prepare($sql);
        $stmt->execute([
            ':entity_type' => $entityType,
            ':entity_id' => $entityId,
            ':org_code' => $orgCode,
            ':channel_purpose' => $channelPurpose,
            ':channel_id' => $discordResult['channel_id'] ?? '',
            ':guild_id' => null,
            ':message_id' => $discordResult['message_id'] ?? null,
            ':message_url' => $discordResult['message_url'] ?? null,
            ':status' => 'POSTED',
            ':direction' => 'OUTBOUND',
            ':created_by' => $userCid,
            ':post_id' => null
        ]);
        
    } catch (Exception $e) {
        error_log("Failed to track Discord post: " . $e->getMessage());
    }
}
