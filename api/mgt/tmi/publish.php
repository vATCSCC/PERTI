<?php
/**
 * TMI Unified Publish API
 * 
 * Handles publishing of NTML entries and advisories to multiple Discord organizations.
 * Supports staging and production modes with full database tracking.
 * 
 * POST /api/mgt/tmi/publish.php
 * 
 * @package PERTI
 * @subpackage API/TMI
 * @version 1.3.0
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
    require_once __DIR__ . '/../../../load/discord/DiscordAPI.php';
    
    // Check if MultiDiscordAPI exists
    $multiDiscordPath = __DIR__ . '/../../../load/discord/MultiDiscordAPI.php';
    if (file_exists($multiDiscordPath)) {
        require_once $multiDiscordPath;
    }
    
    tmi_debug_log('Dependencies loaded');
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

// Initialize Discord API
$discord = null;
$multiDiscord = null;

try {
    $discord = new DiscordAPI();
    
    if (class_exists('MultiDiscordAPI')) {
        $multiDiscord = new MultiDiscordAPI();
    }
    
    tmi_debug_log('Discord API initialized', [
        'configured' => $discord->isConfigured(),
        'multiDiscord' => $multiDiscord !== null
    ]);
} catch (Exception $e) {
    tmi_debug_log('Discord initialization error', ['error' => $e->getMessage()]);
}

if (!$discord || !$discord->isConfigured()) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Discord not configured']);
    exit;
}

// Connect to TMI database for tracking (optional)
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
    tmi_debug_log('TMI Database connection failed (continuing without tracking)', ['error' => $e->getMessage()]);
}

// Process each entry
$results = [];
$successCount = 0;
$failCount = 0;

foreach ($entries as $index => $entry) {
    tmi_debug_log("Processing entry {$index}", ['type' => $entry['type'] ?? 'unknown', 'entryType' => $entry['entryType'] ?? 'unknown']);
    
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
        // Get message content
        $messageContent = $entry['preview'] ?? '';
        
        if (empty($messageContent)) {
            $result['error'] = 'No message content';
            $results[] = $result;
            $failCount++;
            tmi_debug_log("Entry {$index} failed - no message content");
            continue;
        }
        
        // Add staging prefix if not production
        $prefix = $production ? '' : '🧪 **[STAGING]** ';
        $fullMessage = $prefix . "```\n{$messageContent}\n```";
        
        tmi_debug_log("Entry {$index} message built", ['length' => strlen($fullMessage)]);
        
        // Get target orgs
        $targetOrgs = $entry['orgs'] ?? ['vatcscc'];
        if (empty($targetOrgs)) {
            $targetOrgs = ['vatcscc'];
        }
        
        // Determine channel based on type and mode
        $entryType = $entry['type'] ?? 'ntml';
        $isAdvisory = ($entryType === 'advisory');
        
        // Post to Discord
        $discordResults = [];
        
        if ($multiDiscord && $multiDiscord->isConfigured()) {
            // Use multi-org posting
            foreach ($targetOrgs as $orgCode) {
                $channelPurpose = $production 
                    ? ($isAdvisory ? 'advisories' : 'ntml')
                    : ($isAdvisory ? 'advzy_staging' : 'ntml_staging');
                
                tmi_debug_log("Posting to {$orgCode}/{$channelPurpose}");
                
                $postResult = $multiDiscord->postToChannel($orgCode, $channelPurpose, ['content' => $fullMessage]);
                $discordResults[$orgCode] = $postResult;
                
                tmi_debug_log("Post result for {$orgCode}", $postResult);
            }
        } else {
            // Fallback: Use single Discord API
            $channelPurpose = $production 
                ? ($isAdvisory ? 'advisories' : 'tmi')
                : ($isAdvisory ? 'advzy_staging' : 'ntml_staging');
            
            $channelId = $discord->getChannelByPurpose($channelPurpose);
            
            if (!$channelId) {
                // Try fallback channel names
                $fallbackPurpose = $production ? 'tmi' : 'ntml_staging';
                $channelId = $discord->getChannelByPurpose($fallbackPurpose);
            }
            
            tmi_debug_log("Single Discord posting to channel", ['purpose' => $channelPurpose, 'channelId' => $channelId]);
            
            if ($channelId) {
                $response = $discord->createMessage($channelId, ['content' => $fullMessage]);
                
                $discordResults['vatcscc'] = [
                    'success' => ($response && isset($response['id'])),
                    'message_id' => $response['id'] ?? null,
                    'channel_id' => $channelId,
                    'error' => $discord->getLastError()
                ];
                
                tmi_debug_log("Discord response", $discordResults['vatcscc']);
            } else {
                $discordResults['vatcscc'] = [
                    'success' => false,
                    'error' => 'No channel configured for: ' . $channelPurpose
                ];
            }
        }
        
        $result['discordResults'] = $discordResults;
        
        // Check if any org succeeded
        $anySuccess = false;
        foreach ($discordResults as $orgCode => $orgResult) {
            if ($orgResult['success'] ?? false) {
                $anySuccess = true;
            }
        }
        
        $result['success'] = $anySuccess;
        if ($anySuccess) {
            $successCount++;
            tmi_debug_log("Entry {$index} succeeded");
        } else {
            $failCount++;
            $result['error'] = 'Discord posting failed';
            tmi_debug_log("Entry {$index} failed - Discord errors");
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
    'success' => ($failCount === 0 && $successCount > 0),
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
