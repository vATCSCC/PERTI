<?php
/**
 * TMI Unified Publish API
 * 
 * Handles publishing of NTML entries and advisories to:
 *   1. VATSIM_TMI database (tmi_entries / tmi_advisories tables)
 *   2. Discord channels (staging or production)
 * 
 * POST /api/mgt/tmi/publish.php
 * 
 * @package PERTI
 * @subpackage API/TMI
 * @version 2.0.0
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
    $timestamp = gmdate('Y-m-d H:i:s');
    $entry = "[$timestamp] $message";
    if ($data !== null) {
        $entry .= " | " . json_encode($data, JSON_UNESCAPED_SLASHES);
    }
    @file_put_contents($logFile, $entry . "\n", FILE_APPEND);
}

/**
 * Split a long message into chunks that fit Discord's 2000 char limit
 *
 * @param string $message The full message content (without code block markers)
 * @param int $maxLen Maximum length per chunk (default 1980 to leave room for code block markers + prefix)
 * @return array Array of message chunks
 */
function splitMessageForDiscord(string $message, int $maxLen = 1980): array {
    if (strlen($message) <= $maxLen) {
        return [$message];
    }

    $chunks = [];
    $lines = explode("\n", $message);
    $currentChunk = '';

    foreach ($lines as $line) {
        $tentative = $currentChunk . ($currentChunk ? "\n" : '') . $line;

        if (strlen($tentative) <= $maxLen) {
            $currentChunk = $tentative;
        } else {
            if ($currentChunk !== '') {
                $chunks[] = $currentChunk;
            }

            if (strlen($line) > $maxLen) {
                $lineChunks = str_split($line, $maxLen);
                foreach ($lineChunks as $i => $lineChunk) {
                    if ($i < count($lineChunks) - 1) {
                        $chunks[] = $lineChunk;
                    } else {
                        $currentChunk = $lineChunk;
                    }
                }
            } else {
                $currentChunk = $line;
            }
        }
    }

    if ($currentChunk !== '') {
        $chunks[] = $currentChunk;
    }

    return $chunks;
}

tmi_debug_log('=== TMI Publish Request Started (v2.0) ===');

// Load dependencies
try {
    require_once __DIR__ . '/../../../load/config.php';
    require_once __DIR__ . '/../../../load/perti_constants.php';
    require_once __DIR__ . '/../../../load/discord/DiscordAPI.php';
    require_once __DIR__ . '/../../../load/coordination_log.php';
    require_once __DIR__ . '/../../tmi/AdvisoryNumber.php';
    
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
$userName = $payload['userName'] ?? 'Unknown';
$asyncDiscord = $payload['async'] ?? false; // DISABLED: Queue processor not implemented - post directly to Discord

// Get org code from session context
if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}
$org_code = $_SESSION['ORG_CODE'] ?? 'vatcscc';

if (empty($entries)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'No entries provided']);
    exit;
}

tmi_debug_log('Async Discord mode: ' . ($asyncDiscord ? 'enabled' : 'disabled'));

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

// Connect to TMI database
$tmiConn = null;
try {
    if (defined('TMI_SQL_HOST') && TMI_SQL_HOST) {
        $connStr = "sqlsrv:Server=" . TMI_SQL_HOST . ";Database=" . TMI_SQL_DATABASE;
        $tmiConn = new PDO($connStr, TMI_SQL_USERNAME, TMI_SQL_PASSWORD);
        $tmiConn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        tmi_debug_log('TMI database connected');
    }
} catch (Exception $e) {
    tmi_debug_log('TMI Database connection failed', ['error' => $e->getMessage()]);
    // Continue without database - we can still post to Discord
}

// Process each entry
$results = [];
$successCount = 0;
$failCount = 0;

foreach ($entries as $index => $entry) {
    tmi_debug_log("Processing entry {$index}", [
        'type' => $entry['type'] ?? 'unknown', 
        'entryType' => $entry['entryType'] ?? 'unknown'
    ]);
    
    $result = [
        'index' => $index,
        'type' => $entry['type'] ?? 'unknown',
        'entryType' => $entry['entryType'] ?? null,
        'orgs' => $entry['orgs'] ?? [],
        'success' => false,
        'error' => null,
        'discordResults' => [],
        'databaseId' => null
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
        
        $entryType = $entry['type'] ?? 'ntml';
        $entrySubType = $entry['entryType'] ?? 'UNKNOWN';
        $isAdvisory = ($entryType === 'advisory');
        
        // ==================================================
        // STEP 1: Save to database (before Discord posting)
        // ==================================================
        $databaseId = null;
        $skipDiscord = false; // For CONFIG deduplication
        $status = $production ? 'ACTIVE' : 'STAGED';
        
        if ($tmiConn) {
            try {
                if ($isAdvisory) {
                    $databaseId = saveAdvisoryToDatabase(
                        $tmiConn, 
                        $entry, 
                        $messageContent, 
                        $status, 
                        $userCid, 
                        $userName
                    );
                } else {
                    $saveResult = saveNtmlEntryToDatabase(
                        $tmiConn,
                        $entry,
                        $messageContent,
                        $status,
                        $userCid,
                        $userName
                    );

                    // Handle CONFIG deduplication response (returns array) vs new entry (returns int)
                    if (is_array($saveResult)) {
                        $databaseId = $saveResult['id'];
                        $skipDiscord = !$saveResult['content_changed'] && $saveResult['already_posted'];
                        $result['is_update'] = $saveResult['is_update'];
                        $result['content_changed'] = $saveResult['content_changed'];
                        if ($skipDiscord) {
                            $result['discord_skipped'] = true;
                            $result['discord_skip_reason'] = 'CONFIG unchanged and already posted';
                        }
                    } else {
                        $databaseId = $saveResult;
                        $skipDiscord = false;
                    }
                }

                $result['databaseId'] = $databaseId;
                tmi_debug_log("Entry {$index} saved to database", ['id' => $databaseId, 'is_update' => $result['is_update'] ?? false]);

                // Log to coordination channel (only for new entries in production, not updates)
                if ($databaseId && $production && !($result['is_update'] ?? false)) {
                    try {
                        $logAction = $isAdvisory ? 'ADVISORY_CREATED' : 'TMI_CREATED';
                        $logDetails = [
                            'entry_type' => $entrySubType,
                            'entry_id' => $databaseId,
                            'ctl_element' => strtoupper($entry['data']['ctl_element'] ?? ''),
                            'user_cid' => $userCid,
                            'user_name' => $userName
                        ];
                        if ($isAdvisory) {
                            $logDetails['advisory_type'] = $entrySubType;
                            $logDetails['advisory_number'] = $entry['data']['number'] ?? '';
                        }
                        logToCoordinationChannel($tmiConn, null, $logAction, $logDetails);
                    } catch (Exception $logEx) {
                        tmi_debug_log("Coordination log failed", ['error' => $logEx->getMessage()]);
                    }
                }

            } catch (Exception $e) {
                tmi_debug_log("Database save error for entry {$index}", ['error' => $e->getMessage()]);
                // Continue to Discord even if database fails
            }
        }
        
        // ==================================================
        // STEP 2: Post to Discord (sync or async queue)
        // ==================================================

        // Skip Discord if CONFIG was unchanged and already posted
        if (!empty($skipDiscord)) {
            tmi_debug_log("Skipping Discord post - CONFIG unchanged and already posted", ['entry_id' => $databaseId]);
            $result['success'] = true;
            $result['discordResults'] = ['skipped' => true, 'reason' => 'CONFIG unchanged'];
            $results[] = $result;
            $successCount++;
            continue;
        }

        // Add staging prefix if not production
        $prefix = $production ? '' : '🧪 **[STAGING]** ';

        // Split message if it exceeds Discord's 2000 char limit
        // Account for code block markers (```\n + \n```) = 8 chars + prefix length
        $prefixLen = strlen($prefix);
        $maxContentLen = 1988 - $prefixLen;
        $messageChunks = splitMessageForDiscord($messageContent, $maxContentLen);
        $totalChunks = count($messageChunks);

        tmi_debug_log("Message splitting", [
            'content_length' => strlen($messageContent),
            'chunks' => $totalChunks
        ]);

        // Get target orgs
        $targetOrgs = $entry['orgs'] ?? ['vatcscc'];
        if (empty($targetOrgs)) {
            $targetOrgs = ['vatcscc'];
        }

        // Post to Discord
        $discordResults = [];

        if ($asyncDiscord && $databaseId && $tmiConn) {
            // ==================================================
            // ASYNC MODE: Queue Discord posts for background processing
            // ==================================================
            tmi_debug_log("Queueing Discord posts for async processing", ['chunks' => $totalChunks]);

            foreach ($targetOrgs as $orgCode) {
                $channelPurpose = $production
                    ? ($isAdvisory ? 'advisories' : 'ntml')
                    : ($isAdvisory ? 'advzy_staging' : 'ntml_staging');

                try {
                    // Queue each chunk as a separate message
                    $queueIds = [];
                    foreach ($messageChunks as $chunkIndex => $chunk) {
                        $partIndicator = ($totalChunks > 1) ? " (" . ($chunkIndex + 1) . "/{$totalChunks})" : '';
                        $chunkMessage = $prefix . "```\n{$chunk}\n```" . ($chunkIndex === 0 && $totalChunks > 1 ? $partIndicator : '');
                        if ($chunkIndex > 0) {
                            $chunkMessage = "```\n{$chunk}\n```" . $partIndicator;
                        }

                        $queueResult = queueDiscordPost(
                            $tmiConn,
                            $isAdvisory ? 'ADVISORY' : 'ENTRY',
                            $databaseId,
                            $orgCode,
                            $channelPurpose,
                            $chunkMessage,
                            $userCid,
                            $userName
                        );
                        $queueIds[] = $queueResult;
                    }

                    $discordResults[$orgCode] = [
                        'org_code' => $orgCode,
                        'channel_purpose' => $channelPurpose,
                        'success' => true,
                        'queued' => true,
                        'queue_ids' => $queueIds,
                        'chunks_queued' => $totalChunks,
                        'message_id' => null, // Will be set by background processor
                        'error' => null
                    ];

                    tmi_debug_log("Queued Discord post for {$orgCode}", ['queue_ids' => $queueIds]);

                } catch (Exception $e) {
                    $discordResults[$orgCode] = [
                        'org_code' => $orgCode,
                        'channel_purpose' => $channelPurpose,
                        'success' => false,
                        'queued' => false,
                        'error' => 'Queue failed: ' . $e->getMessage()
                    ];
                    tmi_debug_log("Failed to queue Discord post for {$orgCode}", ['error' => $e->getMessage()]);
                }
            }

        } elseif ($discord && $discord->isConfigured()) {
            // ==================================================
            // SYNC MODE: Post to Discord immediately
            // ==================================================
            if ($multiDiscord && $multiDiscord->isConfigured()) {
                // Use multi-org posting
                foreach ($targetOrgs as $orgCode) {
                    $channelPurpose = $production
                        ? ($isAdvisory ? 'advisories' : 'ntml')
                        : ($isAdvisory ? 'advzy_staging' : 'ntml_staging');

                    tmi_debug_log("Posting to {$orgCode}/{$channelPurpose}", ['chunks' => $totalChunks]);

                    // Post each chunk as a separate message
                    $firstMessageId = null;
                    $allSuccess = true;
                    $lastError = null;

                    foreach ($messageChunks as $chunkIndex => $chunk) {
                        // Add part indicator for multi-part messages
                        $partIndicator = ($totalChunks > 1) ? " (" . ($chunkIndex + 1) . "/{$totalChunks})" : '';
                        $chunkMessage = $prefix . "```\n{$chunk}\n```" . ($chunkIndex === 0 ? '' : $partIndicator);
                        if ($chunkIndex === 0 && $totalChunks > 1) {
                            $chunkMessage = $prefix . "```\n{$chunk}\n```" . $partIndicator;
                        }

                        $postResult = $multiDiscord->postToChannel($orgCode, $channelPurpose, ['content' => $chunkMessage]);

                        if ($chunkIndex === 0) {
                            $firstMessageId = $postResult['message_id'] ?? null;
                        }

                        if (!($postResult['success'] ?? false)) {
                            $allSuccess = false;
                            $lastError = $postResult['error'] ?? 'Unknown error';
                        }

                        // Small delay between chunks to maintain order
                        if ($chunkIndex < $totalChunks - 1) {
                            usleep(100000); // 100ms
                        }
                    }

                    $discordResults[$orgCode] = [
                        'success' => $allSuccess,
                        'message_id' => $firstMessageId,
                        'chunks_posted' => $totalChunks,
                        'error' => $lastError
                    ];

                    tmi_debug_log("Post result for {$orgCode}", $discordResults[$orgCode]);
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

                tmi_debug_log("Single Discord posting to channel", ['purpose' => $channelPurpose, 'channelId' => $channelId, 'chunks' => $totalChunks]);

                if ($channelId) {
                    $firstMessageId = null;
                    $allSuccess = true;
                    $lastError = null;

                    foreach ($messageChunks as $chunkIndex => $chunk) {
                        // Add part indicator for multi-part messages
                        $partIndicator = ($totalChunks > 1) ? " (" . ($chunkIndex + 1) . "/{$totalChunks})" : '';
                        $chunkMessage = $prefix . "```\n{$chunk}\n```" . ($chunkIndex === 0 && $totalChunks > 1 ? $partIndicator : '');
                        if ($chunkIndex > 0) {
                            $chunkMessage = "```\n{$chunk}\n```" . $partIndicator;
                        }

                        $response = $discord->createMessage($channelId, ['content' => $chunkMessage]);

                        if ($chunkIndex === 0) {
                            $firstMessageId = $response['id'] ?? null;
                        }

                        if (!$response || !isset($response['id'])) {
                            $allSuccess = false;
                            $lastError = $discord->getLastError();
                        }

                        // Small delay between chunks to maintain order
                        if ($chunkIndex < $totalChunks - 1) {
                            usleep(100000); // 100ms
                        }
                    }

                    $discordResults['vatcscc'] = [
                        'success' => $allSuccess,
                        'message_id' => $firstMessageId,
                        'channel_id' => $channelId,
                        'chunks_posted' => $totalChunks,
                        'error' => $lastError
                    ];

                    tmi_debug_log("Discord response", $discordResults['vatcscc']);
                } else {
                    $discordResults['vatcscc'] = [
                        'success' => false,
                        'error' => 'No channel configured for: ' . $channelPurpose
                    ];
                }
            }
        } else {
            // Discord not configured - still mark as success if database saved
            $discordResults['vatcscc'] = [
                'success' => ($databaseId !== null),
                'message_id' => null,
                'error' => 'Discord not configured'
            ];
        }
        
        $result['discordResults'] = $discordResults;
        
        // ==================================================
        // STEP 3: Update database with Discord message ID
        // ==================================================
        if ($tmiConn && $databaseId) {
            $firstSuccessfulOrg = null;
            foreach ($discordResults as $orgCode => $orgResult) {
                if (($orgResult['success'] ?? false) && !empty($orgResult['message_id'])) {
                    $firstSuccessfulOrg = $orgCode;
                    break;
                }
            }
            
            if ($firstSuccessfulOrg) {
                try {
                    $discordMsgId = $discordResults[$firstSuccessfulOrg]['message_id'];
                    $discordChannelId = $discordResults[$firstSuccessfulOrg]['channel_id'] ?? null;
                    
                    if ($isAdvisory) {
                        updateAdvisoryDiscordInfo($tmiConn, $databaseId, $discordMsgId, $discordChannelId);
                    } else {
                        updateEntryDiscordInfo($tmiConn, $databaseId, $discordMsgId, $discordChannelId);
                    }
                    
                    tmi_debug_log("Updated entry {$index} with Discord info", [
                        'message_id' => $discordMsgId
                    ]);
                    
                } catch (Exception $e) {
                    tmi_debug_log("Failed to update Discord info for entry {$index}", ['error' => $e->getMessage()]);
                }
            }
        }
        
        // Check if any org succeeded
        $anySuccess = false;
        foreach ($discordResults as $orgCode => $orgResult) {
            if ($orgResult['success'] ?? false) {
                $anySuccess = true;
            }
        }
        
        // Also count as success if database saved (even without Discord)
        if ($databaseId && !$anySuccess) {
            $anySuccess = true;
        }
        
        $result['success'] = $anySuccess;
        if ($anySuccess) {
            $successCount++;
            tmi_debug_log("Entry {$index} succeeded");
        } else {
            $failCount++;
            $result['error'] = 'Discord posting failed and no database save';
            tmi_debug_log("Entry {$index} failed");
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

// =============================================================================
// Database Helper Functions
// =============================================================================

/**
 * Save NTML entry to tmi_entries table
 *
 * For CONFIG entries: If an active CONFIG already exists for the same airport,
 * UPDATE it instead of creating a duplicate. Returns ['id' => X, 'is_update' => bool, 'content_changed' => bool]
 */
function saveNtmlEntryToDatabase($conn, $entry, $rawText, $status, $userCid, $userName) {
    $data = $entry['data'] ?? [];
    $entryType = $entry['entryType'] ?? 'UNKNOWN';

    // Map entry type to determinant code
    $determinantCodes = [
        'MIT' => 'MIT',
        'MINIT' => 'MINIT',
        'STOP' => 'STOP',
        'APREQ' => 'APREQ',
        'CFR' => 'CFR',
        'TBM' => 'TBM',
        'DELAY' => 'DELAY',
        'CONFIG' => 'CONFIG',
        'CANCEL' => 'CANCEL'
    ];

    $determinantCode = $determinantCodes[strtoupper($entryType)] ?? strtoupper($entryType);
    $ctlElement = strtoupper($data['ctl_element'] ?? '') ?: null;

    // CONFIG deduplication: Check for existing active CONFIG for same airport WITH OVERLAPPING time period
    // Only deduplicates if time periods overlap - allows multiple non-overlapping configs
    // CONFIG posts only ONCE to Discord per time period - subsequent publishes update DB only
    if (strtoupper($entryType) === 'CONFIG' && $ctlElement) {
        $existingConfig = checkExistingConfig($conn, $ctlElement, $data['valid_from'] ?? null, $data['valid_until'] ?? null);
        if ($existingConfig) {
            // Check if already posted to Discord - if so, NEVER re-post
            $alreadyPostedToDiscord = !empty($existingConfig['discord_message_id']);

            // Compare CONFIG fields (not raw text, which includes changing timestamp)
            $existingData = json_decode($existingConfig['parsed_data'], true) ?? [];
            $contentChanged = isConfigContentChanged($data, $existingData);

            if (!$contentChanged) {
                tmi_debug_log('CONFIG unchanged, skipping update', [
                    'entry_id' => $existingConfig['entry_id'],
                    'ctl_element' => $ctlElement,
                    'has_discord_id' => $alreadyPostedToDiscord
                ]);
                // Return existing ID with flags - skip Discord if already posted
                return [
                    'id' => $existingConfig['entry_id'],
                    'is_update' => true,
                    'content_changed' => false,
                    'already_posted' => $alreadyPostedToDiscord
                ];
            }

            // Content changed - update the existing entry, but NEVER re-post to Discord
            tmi_debug_log('CONFIG content changed, updating existing entry (Discord skip: ' . ($alreadyPostedToDiscord ? 'yes' : 'no') . ')', [
                'entry_id' => $existingConfig['entry_id'],
                'ctl_element' => $ctlElement,
                'changes' => getConfigChanges($data, $existingData)
            ]);
            return updateExistingConfig($conn, $existingConfig['entry_id'], $rawText, $data, $userCid, $userName, $alreadyPostedToDiscord);
        }
    }

    // Parse valid times
    $validFrom = parseValidTime($data['valid_from'] ?? null);
    $validUntil = parseValidTime($data['valid_until'] ?? null);

    // AUTO-EXPIRE DEFAULTS:
    // - If no valid_from, set to current UTC time (publication time)
    // - If no valid_until, set to COB UTC (0600 UTC next day)
    // This applies to entries like D/D (departure delay)
    // NOTE: CONFIG entries require explicit start/end times - no auto-expire

    $entryTypeUpper = strtoupper($entryType);
    $autoExpireEnabled = ($entryTypeUpper !== 'CONFIG');

    if ($autoExpireEnabled && empty($validFrom)) {
        // Default start time = now (publication time)
        $validFrom = gmdate('Y-m-d H:i:s');
        tmi_debug_log('Auto-set valid_from to publication time', ['valid_from' => $validFrom, 'entryType' => $entryType]);
    }

    if ($autoExpireEnabled && empty($validUntil)) {
        // Default end time = COB UTC (0600 UTC)
        // If current time is before 0600 UTC, COB is today at 0600
        // If current time is after 0600 UTC, COB is tomorrow at 0600
        $nowUtc = time();
        $todayCob = strtotime(gmdate('Y-m-d') . ' 06:00:00 UTC');

        if ($nowUtc >= $todayCob) {
            // We're past today's COB, use tomorrow's 0600 UTC
            $validUntil = gmdate('Y-m-d', strtotime('+1 day')) . ' 06:00:00';
        } else {
            // We're before today's COB, use today's 0600 UTC
            $validUntil = gmdate('Y-m-d') . ' 06:00:00';
        }
        tmi_debug_log('Auto-set valid_until to COB UTC', ['valid_until' => $validUntil, 'entryType' => $entryType]);
    }
    
    // Get org_code from global scope (set in main request handler)
    global $org_code;
    $entry_org_code = $org_code ?? 'vatcscc';

    $sql = "INSERT INTO dbo.tmi_entries (
                determinant_code, protocol_type, entry_type,
                ctl_element, element_type, requesting_facility, providing_facility,
                restriction_value, restriction_unit, condition_text, qualifiers, exclusions,
                reason_code, reason_detail,
                valid_from, valid_until,
                status, source_type, source_id,
                raw_input, parsed_data,
                created_by, created_by_name,
                org_code
            ) VALUES (
                :determinant_code, :protocol_type, :entry_type,
                :ctl_element, :element_type, :requesting_facility, :providing_facility,
                :restriction_value, :restriction_unit, :condition_text, :qualifiers, :exclusions,
                :reason_code, :reason_detail,
                :valid_from, :valid_until,
                :status, :source_type, :source_id,
                :raw_input, :parsed_data,
                :created_by, :created_by_name,
                :org_code
            )";
    
    $stmt = $conn->prepare($sql);
    
    $stmt->execute([
        ':determinant_code' => $determinantCode,
        ':protocol_type' => 1, // NTML
        ':entry_type' => strtoupper($entryType),
        ':ctl_element' => strtoupper($data['ctl_element'] ?? '') ?: null,
        ':element_type' => perti_detect_element_type($data['ctl_element'] ?? ''),
        ':requesting_facility' => strtoupper($data['req_facility'] ?? '') ?: null,
        ':providing_facility' => strtoupper($data['prov_facility'] ?? '') ?: null,
        ':restriction_value' => !empty($data['value']) ? intval($data['value']) : null,
        ':restriction_unit' => $entryType === 'MIT' ? 'NM' : ($entryType === 'MINIT' ? 'MIN' : null),
        ':condition_text' => $data['via_fix'] ?? null,
        ':qualifiers' => !empty($data['qualifiers']) ? implode(',', $data['qualifiers']) : null,
        ':exclusions' => $data['exclusions'] ?? null,
        ':reason_code' => $data['reason_category'] ?? null,
        ':reason_detail' => $data['reason_cause'] ?? null,
        ':valid_from' => $validFrom,
        ':valid_until' => $validUntil,
        ':status' => $status,
        ':source_type' => 'WEB',
        ':source_id' => $entry['id'] ?? null,
        ':raw_input' => $rawText,
        ':parsed_data' => json_encode($data),
        ':created_by' => $userCid,
        ':created_by_name' => $userName,
        ':org_code' => $entry_org_code
    ]);

    return $conn->lastInsertId();
}

/**
 * Save advisory to tmi_advisories table
 * Schema: advisory_id, advisory_guid, advisory_number, advisory_type, 
 *         ctl_element, element_type, scope_facilities, program_id,
 *         effective_from, effective_until, subject, body_text,
 *         reason_code, reason_detail, status, source_type, source_id,
 *         discord_message_id, discord_posted_at, discord_channel_id,
 *         created_by, created_by_name, ...
 */
function saveAdvisoryToDatabase($conn, $entry, $rawText, $status, $userCid, $userName) {
    $data = $entry['data'] ?? [];
    $advisoryType = strtoupper($entry['entryType'] ?? 'FREEFORM');

    // ALWAYS get advisory number from database at publish time (ignore client-side number)
    // This prevents race conditions when multiple users publish advisories simultaneously
    $clientNumber = $data['number'] ?? null;

    // Use centralized AdvisoryNumber class
    $advNum = new AdvisoryNumber($conn, 'pdo');
    $advisoryNumber = $advNum->reserve();

    // Replace the client-side advisory number in the body text with the server-assigned number
    if (!empty($clientNumber) && $clientNumber !== $advisoryNumber) {
        // Match patterns like "ADVZY 001" or "ADVZY 021" in the text
        $rawText = preg_replace('/ADVZY\s*\d{3}/', $advisoryNumber, $rawText, 1);
        tmi_debug_log('Replaced advisory number in body', ['old' => $clientNumber, 'new' => $advisoryNumber]);
    }
    
    // Parse valid times
    $effectiveFrom = parseValidTime($data['effective_time'] ?? $data['valid_from'] ?? null);
    $effectiveUntil = parseValidTime($data['end_time'] ?? $data['valid_until'] ?? null);
    
    // Build subject from advisory type
    $subjectMap = [
        'OPSPLAN' => 'Operations Plan',
        'FREEFORM' => $data['subject'] ?? 'General Advisory',
        'HOTLINE' => 'Hotline ' . ($data['hotline_action'] ?? 'Advisory'),
        'SWAP' => 'SWAP ' . ($data['swap_type'] ?? 'Advisory')
    ];
    $subject = $subjectMap[$advisoryType] ?? $advisoryType;
    
    // Build scope facilities from various inputs
    $facilities = $data['facilities'] ?? $data['constrained_facilities'] ?? $data['areas'] ?? null;
    
    // Get org_code from global scope (set in main request handler)
    global $org_code;
    $adv_org_code = $org_code ?? 'vatcscc';

    $sql = "INSERT INTO dbo.tmi_advisories (
                advisory_number, advisory_type,
                ctl_element, element_type, scope_facilities,
                effective_from, effective_until,
                subject, body_text,
                reason_code, reason_detail,
                status, is_proposed,
                source_type, source_id,
                created_by, created_by_name,
                org_code
            ) VALUES (
                :advisory_number, :advisory_type,
                :ctl_element, :element_type, :scope_facilities,
                :effective_from, :effective_until,
                :subject, :body_text,
                :reason_code, :reason_detail,
                :status, :is_proposed,
                :source_type, :source_id,
                :created_by, :created_by_name,
                :org_code
            )";
    
    $stmt = $conn->prepare($sql);
    
    $ctlElement = strtoupper($data['impacted_area'] ?? $data['ctl_element'] ?? '') ?: null;
    
    $stmt->execute([
        ':advisory_number' => $advisoryNumber,
        ':advisory_type' => $advisoryType,
        ':ctl_element' => $ctlElement,
        ':element_type' => perti_detect_element_type($ctlElement),
        ':scope_facilities' => $facilities,
        ':effective_from' => $effectiveFrom,
        ':effective_until' => $effectiveUntil,
        ':subject' => $subject,
        ':body_text' => $rawText,
        ':reason_code' => $data['reason'] ?? null,
        ':reason_detail' => $data['weather'] ?? $data['notes'] ?? null,
        ':status' => $status,
        ':is_proposed' => ($status === 'STAGED') ? 1 : 0,
        ':source_type' => 'WEB',
        ':source_id' => $entry['id'] ?? null,
        ':created_by' => $userCid,
        ':created_by_name' => $userName,
        ':org_code' => $adv_org_code
    ]);

    return $conn->lastInsertId();
}

/**
 * Update NTML entry with Discord info
 */
function updateEntryDiscordInfo($conn, $entryId, $messageId, $channelId) {
    global $org_code;
    $sql = "UPDATE dbo.tmi_entries
            SET discord_message_id = :message_id,
                discord_channel_id = :channel_id,
                discord_posted_at = SYSUTCDATETIME(),
                updated_at = SYSUTCDATETIME()
            WHERE entry_id = :entry_id
              AND org_code = :org_code";

    $stmt = $conn->prepare($sql);
    $stmt->execute([
        ':message_id' => $messageId,
        ':channel_id' => $channelId,
        ':entry_id' => $entryId,
        ':org_code' => $org_code ?? 'vatcscc'
    ]);
}

/**
 * Update advisory with Discord info
 */
function updateAdvisoryDiscordInfo($conn, $advisoryId, $messageId, $channelId) {
    global $org_code;
    $sql = "UPDATE dbo.tmi_advisories
            SET discord_message_id = :message_id,
                discord_channel_id = :channel_id,
                discord_posted_at = SYSUTCDATETIME(),
                updated_at = SYSUTCDATETIME()
            WHERE advisory_id = :advisory_id
              AND org_code = :org_code";

    $stmt = $conn->prepare($sql);
    $stmt->execute([
        ':message_id' => $messageId,
        ':channel_id' => $channelId,
        ':advisory_id' => $advisoryId,
        ':org_code' => $org_code ?? 'vatcscc'
    ]);
}

/**
 * Parse valid time from various formats to full UTC datetime
 * Input is expected to be in UTC (from datetime-local fields labeled as UTC)
 */
function parseValidTime($timeStr) {
    if (empty($timeStr)) return null;

    // If already a full datetime (e.g., "2026-01-28T14:30" from datetime-local)
    // Treat as UTC since our form fields are labeled as UTC
    if (strlen($timeStr) > 10) {
        // Parse as UTC - append 'Z' if no timezone specified
        $ts = strtotime($timeStr . ' UTC');
        if ($ts === false) {
            $ts = strtotime($timeStr);
        }
        return gmdate('Y-m-d H:i:s', $ts);
    }

    // If HH:MM format, combine with today's date (UTC)
    if (preg_match('/^(\d{2}):(\d{2})$/', $timeStr, $matches)) {
        $today = gmdate('Y-m-d');
        return $today . ' ' . $timeStr . ':00';
    }

    // If HHMM format
    if (preg_match('/^(\d{4})$/', $timeStr)) {
        $today = gmdate('Y-m-d');
        $h = substr($timeStr, 0, 2);
        $m = substr($timeStr, 2, 2);
        return "{$today} {$h}:{$m}:00";
    }

    return null;
}

/**
 * Check for existing active CONFIG entry for the same airport WITH OVERLAPPING time period
 * Only matches if the time periods overlap - allows multiple non-overlapping configs
 * @param PDO $conn Database connection
 * @param string $ctlElement Airport code
 * @param string|null $newValidFrom New config start time (ISO8601 or Y-m-d H:i:s)
 * @param string|null $newValidUntil New config end time (ISO8601 or Y-m-d H:i:s)
 * @return array|null Existing config entry or null if not found
 */
function checkExistingConfig($conn, $ctlElement, $newValidFrom = null, $newValidUntil = null) {
    global $org_code;

    // Parse new config times
    $newStart = $newValidFrom ? (new DateTime($newValidFrom, new DateTimeZone('UTC')))->format('Y-m-d H:i:s') : null;
    $newEnd = $newValidUntil ? (new DateTime($newValidUntil, new DateTimeZone('UTC')))->format('Y-m-d H:i:s') : null;

    // Build query - check for time period overlap
    // Overlap condition: newStart < existingEnd AND newEnd > existingStart
    // Handle NULL values (means unbounded - extends forever)
    $sql = "SELECT entry_id, raw_input, parsed_data, discord_message_id, status, valid_from, valid_until
            FROM dbo.tmi_entries
            WHERE entry_type = 'CONFIG'
              AND ctl_element = :ctl_element
              AND org_code = :org_code
              AND status = 'ACTIVE'
              AND (valid_until IS NULL OR valid_until > SYSUTCDATETIME())";

    // Add overlap conditions if new config has time bounds
    $params = [':ctl_element' => strtoupper($ctlElement), ':org_code' => $org_code ?? 'vatcscc'];

    if ($newStart && $newEnd) {
        // New config has both start and end - check for overlap
        $sql .= " AND (:new_start < ISNULL(valid_until, '9999-12-31')
                      AND :new_end > ISNULL(valid_from, '1900-01-01'))";
        $params[':new_start'] = $newStart;
        $params[':new_end'] = $newEnd;
    } elseif ($newStart) {
        // New config has start only (open-ended) - overlaps if existing hasn't ended
        $sql .= " AND (valid_until IS NULL OR valid_until > :new_start)";
        $params[':new_start'] = $newStart;
    } elseif ($newEnd) {
        // New config has end only - overlaps if existing started before new end
        $sql .= " AND (valid_from IS NULL OR valid_from < :new_end)";
        $params[':new_end'] = $newEnd;
    }
    // If no time bounds on new config, fall back to any active config (original behavior)

    $sql .= " ORDER BY created_at DESC";

    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/**
 * Compare CONFIG data fields to determine if content has actually changed
 * Ignores timestamp in raw_text, compares meaningful fields only
 */
function isConfigContentChanged($newData, $existingData) {
    // Fields that matter for CONFIG comparison
    $fieldsToCompare = ['weather', 'arr_runways', 'dep_runways', 'aar', 'aar_type', 'adr'];

    foreach ($fieldsToCompare as $field) {
        $newVal = strtoupper(trim($newData[$field] ?? ''));
        $oldVal = strtoupper(trim($existingData[$field] ?? ''));

        // Normalize empty values
        if ($newVal === '' || $newVal === 'N/A') $newVal = '';
        if ($oldVal === '' || $oldVal === 'N/A') $oldVal = '';

        if ($newVal !== $oldVal) {
            return true; // Content has changed
        }
    }

    return false; // No meaningful changes
}

/**
 * Get list of changed CONFIG fields (for logging)
 */
function getConfigChanges($newData, $existingData) {
    $fieldsToCompare = ['weather', 'arr_runways', 'dep_runways', 'aar', 'aar_type', 'adr'];
    $changes = [];

    foreach ($fieldsToCompare as $field) {
        $newVal = strtoupper(trim($newData[$field] ?? ''));
        $oldVal = strtoupper(trim($existingData[$field] ?? ''));

        if ($newVal !== $oldVal) {
            $changes[$field] = ['old' => $oldVal, 'new' => $newVal];
        }
    }

    return $changes;
}

/**
 * Update existing CONFIG entry instead of creating duplicate
 * @return array Result with id and flags
 */
function updateExistingConfig($conn, $entryId, $rawText, $data, $userCid, $userName, $alreadyPostedToDiscord = false) {
    global $org_code;

    $sql = "UPDATE dbo.tmi_entries SET
                raw_input = :raw_input,
                parsed_data = :parsed_data,
                updated_by = :user_cid,
                updated_by_name = :user_name,
                updated_at = SYSUTCDATETIME()
            WHERE entry_id = :entry_id
              AND org_code = :org_code";

    $stmt = $conn->prepare($sql);
    $stmt->execute([
        ':raw_input' => $rawText,
        ':parsed_data' => json_encode($data),
        ':user_cid' => $userCid,
        ':user_name' => $userName,
        ':entry_id' => $entryId,
        ':org_code' => $org_code ?? 'vatcscc'
    ]);

    tmi_debug_log('Updated existing CONFIG entry', [
        'entry_id' => $entryId,
        'updated_by' => $userName,
        'skip_discord' => $alreadyPostedToDiscord
    ]);

    // CONFIG posts only ONCE - if already posted to Discord, never re-post
    return [
        'id' => $entryId,
        'is_update' => true,
        'content_changed' => true,
        'already_posted' => $alreadyPostedToDiscord
    ];
}

// detectElementType() removed — now uses perti_detect_element_type() from load/perti_constants.php

/**
 * Queue a Discord post for async processing
 *
 * Instead of posting to Discord synchronously (which can take 1-3 seconds per request),
 * this queues the post in the tmi_discord_posts table with status='PENDING'.
 * A background worker will process the queue with rate limiting.
 *
 * @param PDO $conn Database connection
 * @param string $entityType 'ENTRY' or 'ADVISORY'
 * @param int $entityId The tmi_entries.entry_id or tmi_advisories.advisory_id
 * @param string $orgCode Discord organization code (e.g., 'vatcscc')
 * @param string $channelPurpose Channel purpose (e.g., 'ntml', 'ntml_staging')
 * @param string $messageContent The full message content to post
 * @param string|null $createdBy VATSIM CID of creator
 * @param string|null $createdByName Display name of creator
 * @return int The queue post_id
 */
function queueDiscordPost($conn, $entityType, $entityId, $orgCode, $channelPurpose, $messageContent, $createdBy = null, $createdByName = null) {
    // Generate content hash for deduplication
    $contentHash = hash('sha256', $entityType . $entityId . $orgCode . $channelPurpose . $messageContent);

    // Check for duplicate (same entity + org + purpose with pending status)
    $checkSql = "SELECT post_id FROM dbo.tmi_discord_posts
                 WHERE entity_type = :entity_type
                   AND entity_id = :entity_id
                   AND org_code = :org_code
                   AND channel_purpose = :channel_purpose
                   AND status IN ('PENDING', 'POSTED')";

    $checkStmt = $conn->prepare($checkSql);
    $checkStmt->execute([
        ':entity_type' => $entityType,
        ':entity_id' => $entityId,
        ':org_code' => $orgCode,
        ':channel_purpose' => $channelPurpose
    ]);

    $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);
    if ($existing) {
        // Already queued or posted - return existing ID
        return $existing['post_id'];
    }

    // Insert new queue entry
    $sql = "INSERT INTO dbo.tmi_discord_posts (
                entity_type, entity_id, org_code, channel_purpose,
                channel_id, status, direction,
                message_content_hash,
                created_by, created_by_name
            ) VALUES (
                :entity_type, :entity_id, :org_code, :channel_purpose,
                :channel_id, 'PENDING', 'OUTBOUND',
                :content_hash,
                :created_by, :created_by_name
            )";

    $stmt = $conn->prepare($sql);
    $stmt->execute([
        ':entity_type' => $entityType,
        ':entity_id' => $entityId,
        ':org_code' => $orgCode,
        ':channel_purpose' => $channelPurpose,
        ':channel_id' => 'PENDING', // Will be resolved by processor
        ':content_hash' => $contentHash,
        ':created_by' => $createdBy,
        ':created_by_name' => $createdByName
    ]);

    return $conn->lastInsertId();
}
