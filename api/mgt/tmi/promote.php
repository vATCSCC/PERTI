<?php
/**
 * TMI Promote API
 * 
 * Promotes staged TMI entries/advisories from staging to production Discord channels.
 * Handles both manual promotion from UI and reaction-based approval.
 * 
 * POST /api/mgt/tmi/promote.php
 * 
 * Request Body:
 * {
 *   "entityType": "ENTRY" | "ADVISORY",
 *   "entityId": 123,
 *   "orgs": ["vatcscc", "canoc"],
 *   "deleteStaging": true,
 *   "userCid": "123456"
 * }
 * 
 * OR for batch promotion:
 * {
 *   "batch": [
 *     { "entityType": "ENTRY", "entityId": 123, "orgs": ["vatcscc"] },
 *     { "entityType": "ADVISORY", "entityId": 456, "orgs": ["vatcscc", "canoc"] }
 *   ],
 *   "deleteStaging": true,
 *   "userCid": "123456"
 * }
 * 
 * @package PERTI
 * @subpackage API/TMI
 * @version 1.0.0
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
function promote_debug_log($message, $data = null) {
    $logFile = '/home/LogFiles/tmi_promote_debug.log';
    if (!is_dir('/home/LogFiles')) {
        $logFile = __DIR__ . '/tmi_promote_debug.log';
    }
    $timestamp = date('Y-m-d H:i:s');
    $entry = "[$timestamp] $message";
    if ($data !== null) {
        $entry .= " | " . json_encode($data, JSON_UNESCAPED_SLASHES);
    }
    @file_put_contents($logFile, $entry . "\n", FILE_APPEND);
}

promote_debug_log('=== TMI Promote Request Started ===');

// Load dependencies
try {
    require_once __DIR__ . '/../../../load/config.php';
    require_once __DIR__ . '/../../../load/connect.php';
    require_once __DIR__ . '/../../../load/discord/TMIDiscord.php';
    require_once __DIR__ . '/../../../load/discord/MultiDiscordAPI.php';
    promote_debug_log('Dependencies loaded');
} catch (Exception $e) {
    promote_debug_log('ERROR loading dependencies', ['error' => $e->getMessage()]);
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Dependency load error: ' . $e->getMessage()]);
    exit;
}

// Parse request body
$input = file_get_contents('php://input');
$payload = json_decode($input, true);

if (!$payload) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid request body']);
    exit;
}

$userCid = $payload['userCid'] ?? null;
$deleteStaging = $payload['deleteStaging'] ?? true;

// Determine if batch or single
$items = [];
if (isset($payload['batch']) && is_array($payload['batch'])) {
    $items = $payload['batch'];
} elseif (isset($payload['entityType']) && isset($payload['entityId'])) {
    $items = [[
        'entityType' => $payload['entityType'],
        'entityId' => $payload['entityId'],
        'orgs' => $payload['orgs'] ?? []
    ]];
} else {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing entityType/entityId or batch array']);
    exit;
}

promote_debug_log('Processing items', ['count' => count($items)]);

// Initialize Discord
$tmiDiscord = new TMIDiscord();
$multiDiscord = $tmiDiscord->getMultiDiscordAPI();

if (!$multiDiscord || !$multiDiscord->isConfigured()) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Discord not configured']);
    exit;
}

// Connect to TMI database
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
    promote_debug_log('Database connection failed', ['error' => $e->getMessage()]);
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database connection failed']);
    exit;
}

if (!$tmiConn) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database not configured']);
    exit;
}

// Process each item
$results = [];
$successCount = 0;
$failCount = 0;

foreach ($items as $index => $item) {
    $entityType = strtoupper($item['entityType'] ?? 'ENTRY');
    $entityId = intval($item['entityId'] ?? 0);
    $targetOrgs = $item['orgs'] ?? [];
    
    $result = [
        'entityType' => $entityType,
        'entityId' => $entityId,
        'success' => false,
        'error' => null,
        'productionResults' => [],
        'stagingDeleted' => []
    ];
    
    if (!$entityId) {
        $result['error'] = 'Invalid entityId';
        $results[] = $result;
        $failCount++;
        continue;
    }
    
    try {
        // Get the entity content
        $content = getEntityContent($tmiConn, $entityType, $entityId);
        
        if (!$content) {
            $result['error'] = 'Entity not found';
            $results[] = $result;
            $failCount++;
            continue;
        }
        
        promote_debug_log("Entity {$entityType}/{$entityId} loaded", ['content_length' => strlen($content)]);
        
        // Get staging posts if no specific orgs provided
        if (empty($targetOrgs)) {
            $targetOrgs = getStagingOrgs($tmiConn, $entityType, $entityId);
        }
        
        if (empty($targetOrgs)) {
            $targetOrgs = ['vatcscc']; // Default
        }
        
        // Build message
        $messageData = [
            'content' => "```\n{$content}\n```"
        ];
        
        // Determine production channel
        $tmiType = ($entityType === 'ADVISORY') ? 'advisories' : 'ntml';
        
        // Post to production channels
        $productionResults = [];
        foreach ($targetOrgs as $orgCode) {
            promote_debug_log("Posting to {$orgCode}/{$tmiType}");
            
            $postResult = $multiDiscord->postToChannel($orgCode, $tmiType, $messageData);
            $productionResults[$orgCode] = $postResult;
            
            // Track production post
            if ($postResult['success'] ?? false) {
                trackProductionPost($tmiConn, $entityType, $entityId, $orgCode, $tmiType, $postResult, $userCid);
            }
        }
        
        $result['productionResults'] = $productionResults;
        
        // Delete staging messages if requested
        $stagingDeleted = [];
        if ($deleteStaging) {
            $stagingDeleted = deleteStagingMessages($tmiConn, $multiDiscord, $entityType, $entityId, $targetOrgs);
            $result['stagingDeleted'] = $stagingDeleted;
        }
        
        // Update entity status
        updateEntityStatus($tmiConn, $entityType, $entityId, 'PUBLISHED', $userCid);
        
        // Mark staging posts as promoted
        markStagingAsPromoted($tmiConn, $entityType, $entityId, $targetOrgs);
        
        // Check if all production posts succeeded
        $allSuccess = true;
        foreach ($productionResults as $orgResult) {
            if (!($orgResult['success'] ?? false)) {
                $allSuccess = false;
            }
        }
        
        $result['success'] = $allSuccess;
        if ($allSuccess) {
            $successCount++;
        } else {
            $failCount++;
            $result['error'] = 'Some production posts failed';
        }
        
    } catch (Exception $e) {
        $result['error'] = $e->getMessage();
        $failCount++;
        promote_debug_log("Error processing {$entityType}/{$entityId}", ['error' => $e->getMessage()]);
    }
    
    $results[] = $result;
}

// Response
$response = [
    'success' => ($failCount === 0),
    'summary' => [
        'total' => count($items),
        'success' => $successCount,
        'failed' => $failCount
    ],
    'results' => $results
];

promote_debug_log('=== Promote complete ===', $response['summary']);

echo json_encode($response);

// ===========================================
// Helper Functions
// ===========================================

/**
 * Get entity content for posting
 */
function getEntityContent($conn, $entityType, $entityId) {
    if ($entityType === 'ADVISORY') {
        $sql = "SELECT body_text FROM dbo.tmi_advisories WHERE advisory_id = ?";
    } else {
        $sql = "SELECT raw_input FROM dbo.tmi_entries WHERE entry_id = ?";
    }

    $stmt = $conn->prepare($sql);
    $stmt->execute([$entityId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($row) {
        return $row['body_text'] ?? $row['raw_input'] ?? null;
    }

    return null;
}

/**
 * Get orgs that have staging posts for this entity
 */
function getStagingOrgs($conn, $entityType, $entityId) {
    $sql = "SELECT DISTINCT org_code 
            FROM dbo.tmi_discord_posts 
            WHERE entity_type = ? 
              AND entity_id = ? 
              AND channel_purpose LIKE '%staging%'
              AND status = 'POSTED'";
    
    $stmt = $conn->prepare($sql);
    $stmt->execute([$entityType, $entityId]);
    
    $orgs = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $orgs[] = $row['org_code'];
    }
    
    return $orgs;
}

/**
 * Track production post in database
 */
function trackProductionPost($conn, $entityType, $entityId, $orgCode, $channelPurpose, $discordResult, $userCid) {
    try {
        $sql = "INSERT INTO dbo.tmi_discord_posts 
                (entity_type, entity_id, org_code, channel_purpose, 
                 channel_id, message_id, message_url, 
                 status, direction, created_by,
                 requested_at, posted_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, 'POSTED', 'OUTBOUND', ?, SYSUTCDATETIME(), SYSUTCDATETIME())";
        
        $stmt = $conn->prepare($sql);
        $stmt->execute([
            $entityType,
            $entityId,
            $orgCode,
            $channelPurpose,
            $discordResult['channel_id'] ?? '',
            $discordResult['message_id'] ?? null,
            $discordResult['message_url'] ?? null,
            $userCid
        ]);
    } catch (Exception $e) {
        promote_debug_log('Track production post error', ['error' => $e->getMessage()]);
    }
}

/**
 * Delete staging messages from Discord
 */
function deleteStagingMessages($conn, $multiDiscord, $entityType, $entityId, $targetOrgs) {
    $deleted = [];
    
    // Get staging message IDs
    $sql = "SELECT post_id, org_code, channel_id, message_id 
            FROM dbo.tmi_discord_posts 
            WHERE entity_type = ? 
              AND entity_id = ? 
              AND channel_purpose LIKE '%staging%'
              AND status = 'POSTED'
              AND message_id IS NOT NULL";
    
    $stmt = $conn->prepare($sql);
    $stmt->execute([$entityType, $entityId]);
    
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $orgCode = $row['org_code'];
        $channelId = $row['channel_id'];
        $messageId = $row['message_id'];
        
        // Only delete for target orgs
        if (!in_array($orgCode, $targetOrgs)) {
            continue;
        }
        
        // Delete from Discord
        $deleteResult = $multiDiscord->deleteMessageByChannelId($orgCode, $channelId, $messageId);
        
        if ($deleteResult) {
            $deleted[$orgCode] = $messageId;
            
            // Update database
            $updateSql = "UPDATE dbo.tmi_discord_posts SET status = 'DELETED', deleted_at = SYSUTCDATETIME() WHERE post_id = ?";
            $updateStmt = $conn->prepare($updateSql);
            $updateStmt->execute([$row['post_id']]);
        }
    }
    
    return $deleted;
}

/**
 * Update entity status
 */
function updateEntityStatus($conn, $entityType, $entityId, $status, $userCid) {
    if ($entityType === 'ADVISORY') {
        $sql = "UPDATE dbo.tmi_advisories SET status = ?, updated_at = SYSUTCDATETIME() WHERE advisory_id = ?";
    } else {
        $sql = "UPDATE dbo.tmi_entries SET status = ?, updated_at = SYSUTCDATETIME() WHERE entry_id = ?";
    }

    $stmt = $conn->prepare($sql);
    $stmt->execute([$status, $entityId]);
}

/**
 * Mark staging posts as promoted
 */
function markStagingAsPromoted($conn, $entityType, $entityId, $targetOrgs) {
    $placeholders = implode(',', array_fill(0, count($targetOrgs), '?'));
    
    $sql = "UPDATE dbo.tmi_discord_posts 
            SET status = 'PROMOTED', promoted_at = SYSUTCDATETIME() 
            WHERE entity_type = ? 
              AND entity_id = ? 
              AND channel_purpose LIKE '%staging%'
              AND org_code IN ({$placeholders})
              AND status = 'POSTED'";
    
    $params = array_merge([$entityType, $entityId], $targetOrgs);
    
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
}
