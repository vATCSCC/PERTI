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
    $timestamp = date('Y-m-d H:i:s');
    $entry = "[$timestamp] $message";
    if ($data !== null) {
        $entry .= " | " . json_encode($data, JSON_UNESCAPED_SLASHES);
    }
    @file_put_contents($logFile, $entry . "\n", FILE_APPEND);
}

tmi_debug_log('=== TMI Publish Request Started (v2.0) ===');

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
$userName = $payload['userName'] ?? 'Unknown';
$asyncDiscord = $payload['async'] ?? false; // DISABLED: Queue processor not implemented - post directly to Discord

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
        $fullMessage = $prefix . "```\n{$messageContent}\n```";

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
            tmi_debug_log("Queueing Discord posts for async processing");

            foreach ($targetOrgs as $orgCode) {
                $channelPurpose = $production
                    ? ($isAdvisory ? 'advisories' : 'ntml')
                    : ($isAdvisory ? 'advzy_staging' : 'ntml_staging');

                try {
                    // Queue the Discord post
                    $queueResult = queueDiscordPost(
                        $tmiConn,
                        $isAdvisory ? 'ADVISORY' : 'ENTRY',
                        $databaseId,
                        $orgCode,
                        $channelPurpose,
                        $fullMessage,
                        $userCid,
                        $userName
                    );

                    $discordResults[$orgCode] = [
                        'org_code' => $orgCode,
                        'channel_purpose' => $channelPurpose,
                        'success' => true,
                        'queued' => true,
                        'queue_id' => $queueResult,
                        'message_id' => null, // Will be set by background processor
                        'error' => null
                    ];

                    tmi_debug_log("Queued Discord post for {$orgCode}", ['queue_id' => $queueResult]);

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

    // CONFIG deduplication: Check for existing active CONFIG for same airport
    if (strtoupper($entryType) === 'CONFIG' && $ctlElement) {
        $existingConfig = checkExistingConfig($conn, $ctlElement);
        if ($existingConfig) {
            // Compare content - if same raw text, skip the update entirely
            if (trim($existingConfig['raw_input']) === trim($rawText)) {
                tmi_debug_log('CONFIG unchanged, skipping update', [
                    'entry_id' => $existingConfig['entry_id'],
                    'ctl_element' => $ctlElement,
                    'has_discord_id' => !empty($existingConfig['discord_message_id'])
                ]);
                // Return existing ID with flags indicating no change needed
                return [
                    'id' => $existingConfig['entry_id'],
                    'is_update' => true,
                    'content_changed' => false,
                    'already_posted' => !empty($existingConfig['discord_message_id'])
                ];
            }

            // Content changed - update the existing entry
            tmi_debug_log('CONFIG content changed, updating existing entry', [
                'entry_id' => $existingConfig['entry_id'],
                'ctl_element' => $ctlElement
            ]);
            return updateExistingConfig($conn, $existingConfig['entry_id'], $rawText, $data, $userCid, $userName);
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
    
    $sql = "INSERT INTO dbo.tmi_entries (
                determinant_code, protocol_type, entry_type,
                ctl_element, element_type, requesting_facility, providing_facility,
                restriction_value, restriction_unit, condition_text, qualifiers, exclusions,
                reason_code, reason_detail,
                valid_from, valid_until,
                status, source_type, source_id,
                raw_input, parsed_data,
                created_by, created_by_name
            ) VALUES (
                :determinant_code, :protocol_type, :entry_type,
                :ctl_element, :element_type, :requesting_facility, :providing_facility,
                :restriction_value, :restriction_unit, :condition_text, :qualifiers, :exclusions,
                :reason_code, :reason_detail,
                :valid_from, :valid_until,
                :status, :source_type, :source_id,
                :raw_input, :parsed_data,
                :created_by, :created_by_name
            )";
    
    $stmt = $conn->prepare($sql);
    
    $stmt->execute([
        ':determinant_code' => $determinantCode,
        ':protocol_type' => 1, // NTML
        ':entry_type' => strtoupper($entryType),
        ':ctl_element' => strtoupper($data['ctl_element'] ?? '') ?: null,
        ':element_type' => detectElementType($data['ctl_element'] ?? ''),
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
        ':created_by_name' => $userName
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
    $advisoryNumber = null;

    try {
        $stmt = $conn->prepare("DECLARE @num NVARCHAR(16); EXEC sp_GetNextAdvisoryNumber @next_number = @num OUTPUT; SELECT @num AS num;");
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $advisoryNumber = $row['num'] ?? null;
    } catch (Exception $e) {
        tmi_debug_log('Failed to get advisory number from database', ['error' => $e->getMessage()]);
    }

    // Fallback if stored procedure failed
    if (empty($advisoryNumber)) {
        $advisoryNumber = 'ADVZY ' . date('His');
    }

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
    
    $sql = "INSERT INTO dbo.tmi_advisories (
                advisory_number, advisory_type,
                ctl_element, element_type, scope_facilities,
                effective_from, effective_until,
                subject, body_text,
                reason_code, reason_detail,
                status, is_proposed,
                source_type, source_id,
                created_by, created_by_name
            ) VALUES (
                :advisory_number, :advisory_type,
                :ctl_element, :element_type, :scope_facilities,
                :effective_from, :effective_until,
                :subject, :body_text,
                :reason_code, :reason_detail,
                :status, :is_proposed,
                :source_type, :source_id,
                :created_by, :created_by_name
            )";
    
    $stmt = $conn->prepare($sql);
    
    $ctlElement = strtoupper($data['impacted_area'] ?? $data['ctl_element'] ?? '') ?: null;
    
    $stmt->execute([
        ':advisory_number' => $advisoryNumber,
        ':advisory_type' => $advisoryType,
        ':ctl_element' => $ctlElement,
        ':element_type' => detectElementType($ctlElement),
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
        ':created_by_name' => $userName
    ]);
    
    return $conn->lastInsertId();
}

/**
 * Update NTML entry with Discord info
 */
function updateEntryDiscordInfo($conn, $entryId, $messageId, $channelId) {
    $sql = "UPDATE dbo.tmi_entries 
            SET discord_message_id = :message_id,
                discord_channel_id = :channel_id,
                discord_posted_at = SYSUTCDATETIME(),
                updated_at = SYSUTCDATETIME()
            WHERE entry_id = :entry_id";
    
    $stmt = $conn->prepare($sql);
    $stmt->execute([
        ':message_id' => $messageId,
        ':channel_id' => $channelId,
        ':entry_id' => $entryId
    ]);
}

/**
 * Update advisory with Discord info
 */
function updateAdvisoryDiscordInfo($conn, $advisoryId, $messageId, $channelId) {
    $sql = "UPDATE dbo.tmi_advisories 
            SET discord_message_id = :message_id,
                discord_channel_id = :channel_id,
                discord_posted_at = SYSUTCDATETIME(),
                updated_at = SYSUTCDATETIME()
            WHERE advisory_id = :advisory_id";
    
    $stmt = $conn->prepare($sql);
    $stmt->execute([
        ':message_id' => $messageId,
        ':channel_id' => $channelId,
        ':advisory_id' => $advisoryId
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
 * Check for existing active CONFIG entry for the same airport
 * @return array|null Existing config entry or null if not found
 */
function checkExistingConfig($conn, $ctlElement) {
    $sql = "SELECT entry_id, raw_input, discord_message_id, status
            FROM dbo.tmi_entries
            WHERE entry_type = 'CONFIG'
              AND ctl_element = :ctl_element
              AND status = 'ACTIVE'
              AND (valid_until IS NULL OR valid_until > SYSUTCDATETIME())
            ORDER BY created_at DESC";

    $stmt = $conn->prepare($sql);
    $stmt->execute([':ctl_element' => strtoupper($ctlElement)]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/**
 * Update existing CONFIG entry instead of creating duplicate
 * @return array Result with id and flags
 */
function updateExistingConfig($conn, $entryId, $rawText, $data, $userCid, $userName) {
    $sql = "UPDATE dbo.tmi_entries SET
                raw_input = :raw_input,
                parsed_data = :parsed_data,
                updated_by = :user_cid,
                updated_by_name = :user_name,
                updated_at = SYSUTCDATETIME()
            WHERE entry_id = :entry_id";

    $stmt = $conn->prepare($sql);
    $stmt->execute([
        ':raw_input' => $rawText,
        ':parsed_data' => json_encode($data),
        ':user_cid' => $userCid,
        ':user_name' => $userName,
        ':entry_id' => $entryId
    ]);

    tmi_debug_log('Updated existing CONFIG entry', [
        'entry_id' => $entryId,
        'updated_by' => $userName
    ]);

    return [
        'id' => $entryId,
        'is_update' => true,
        'content_changed' => true,
        'already_posted' => false // Content changed, so it should be re-posted
    ];
}

/**
 * Detect element type from element identifier
 */
function detectElementType($element) {
    if (empty($element)) return null;

    $element = strtoupper($element);

    // Airport (K***, C***, or 4-letter)
    if (preg_match('/^[KC][A-Z]{3}$/', $element)) {
        return 'APT';
    }

    // ARTCC (Z**)
    if (preg_match('/^Z[A-Z]{2}$/', $element)) {
        return 'ARTCC';
    }

    // FCA/FEA (flight corridor/area)
    if (preg_match('/^(FCA|FEA)/', $element)) {
        return 'FCA';
    }

    // Fix/waypoint (5-letter)
    if (preg_match('/^[A-Z]{5}$/', $element)) {
        return 'FIX';
    }

    return 'OTHER';
}

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
