<?php
/**
 * TMI Proposal Reaction Processor (Cron Job)
 *
 * Polls Discord for reactions on pending TMI proposals and processes approvals/denials.
 * Run via cron every 1-2 minutes:
 *   * * * * * php /path/to/cron/process_tmi_proposals.php
 *
 * @package PERTI
 * @subpackage Cron/TMI
 * @version 1.0.0
 * @date 2026-01-28
 */

// Prevent browser access
if (php_sapi_name() !== 'cli' && !isset($_GET['cron_key'])) {
    http_response_code(403);
    die('CLI or cron key required');
}

// Optional cron key for HTTP access
$expectedKey = getenv('CRON_KEY') ?: 'tmi_proposal_cron_2026';
if (isset($_GET['cron_key']) && $_GET['cron_key'] !== $expectedKey) {
    http_response_code(403);
    die('Invalid cron key');
}

require_once __DIR__ . '/../load/config.php';
require_once __DIR__ . '/../load/discord/DiscordAPI.php';
require_once __DIR__ . '/../load/discord/TMIDiscord.php';

// =============================================================================
// CONSTANTS
// =============================================================================

define('COORDINATION_CHANNEL', '1466013550450577491');
define('DENY_EMOJI', '❌');
define('DCC_APPROVE_EMOJI', 'DCC');

// DCC override users
define('DCC_OVERRIDE_USERS', [
    '396865467840593930'  // jpeterson24
]);

// DCC override roles (role names)
define('DCC_OVERRIDE_ROLE_NAMES', [
    'DCC Staff',
    'NTMO'
]);

// =============================================================================
// MAIN
// =============================================================================

echo "[" . date('Y-m-d H:i:s') . "] Starting TMI proposal processing...\n";

try {
    $conn = getTmiConnection();
    if (!$conn) {
        throw new Exception('Failed to connect to TMI database');
    }

    $discord = new DiscordAPI();
    if (!$discord->isConfigured()) {
        throw new Exception('Discord API not configured');
    }

    // Get pending proposals
    $pendingProposals = getPendingProposals($conn);
    echo "Found " . count($pendingProposals) . " pending proposal(s)\n";

    foreach ($pendingProposals as $proposal) {
        processProposal($conn, $discord, $proposal);
    }

    // Check for expired proposals
    processExpiredProposals($conn);

    echo "[" . date('Y-m-d H:i:s') . "] Processing complete.\n";

} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    error_log("TMI Proposal Cron Error: " . $e->getMessage());
    exit(1);
}

// =============================================================================
// FUNCTIONS
// =============================================================================

function getTmiConnection() {
    if (!defined("TMI_SQL_HOST") || !defined("TMI_SQL_DATABASE") ||
        !defined("TMI_SQL_USERNAME") || !defined("TMI_SQL_PASSWORD")) {
        return null;
    }

    try {
        $connStr = "sqlsrv:Server=" . TMI_SQL_HOST . ";Database=" . TMI_SQL_DATABASE;
        $conn = new PDO($connStr, TMI_SQL_USERNAME, TMI_SQL_PASSWORD);
        $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        return $conn;
    } catch (Exception $e) {
        error_log("TMI DB connection failed: " . $e->getMessage());
        return null;
    }
}

function getPendingProposals($conn) {
    $sql = "SELECT * FROM dbo.tmi_proposals
            WHERE status = 'PENDING'
              AND discord_message_id IS NOT NULL
            ORDER BY created_at";
    $stmt = $conn->query($sql);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function processProposal($conn, $discord, $proposal) {
    $proposalId = $proposal['proposal_id'];
    $messageId = $proposal['discord_message_id'];
    $channelId = $proposal['discord_channel_id'] ?: COORDINATION_CHANNEL;

    echo "  Processing proposal #{$proposalId} (msg: {$messageId})...\n";

    // Get required facilities
    $facSql = "SELECT * FROM dbo.tmi_proposal_facilities WHERE proposal_id = :id";
    $facStmt = $conn->prepare($facSql);
    $facStmt->execute([':id' => $proposalId]);
    $facilities = $facStmt->fetchAll(PDO::FETCH_ASSOC);

    // Build facility emoji map
    $facilityEmojis = [];
    foreach ($facilities as $fac) {
        $emoji = $fac['approval_emoji'] ?: ':' . $fac['facility_code'] . ':';
        $facilityEmojis[$fac['facility_code']] = $emoji;
    }

    // Get all reactions from Discord
    $allReactions = getMessageReactions($discord, $channelId, $messageId, $facilityEmojis);

    // Process each reaction
    foreach ($allReactions as $reaction) {
        processReaction($conn, $discord, $proposal, $facilities, $reaction);
    }

    // Check approval status
    checkAndUpdateProposalStatus($conn, $proposalId);
}

function getMessageReactions($discord, $channelId, $messageId, $facilityEmojis) {
    $reactions = [];

    // Get facility emoji reactions
    foreach ($facilityEmojis as $facCode => $emoji) {
        $emojiEncoded = extractEmojiName($emoji);
        $users = $discord->getReactions($channelId, $messageId, $emojiEncoded, ['limit' => 100]);

        if ($users && is_array($users)) {
            foreach ($users as $user) {
                $reactions[] = [
                    'emoji' => $emoji,
                    'emoji_name' => $emojiEncoded,
                    'facility_code' => $facCode,
                    'type' => 'FACILITY_APPROVE',
                    'user_id' => $user['id'],
                    'username' => $user['username'] ?? null
                ];
            }
        }
    }

    // Get deny emoji reactions
    $denyUsers = $discord->getReactions($channelId, $messageId, DENY_EMOJI, ['limit' => 100]);
    if ($denyUsers && is_array($denyUsers)) {
        foreach ($denyUsers as $user) {
            $reactions[] = [
                'emoji' => DENY_EMOJI,
                'emoji_name' => DENY_EMOJI,
                'facility_code' => null,
                'type' => 'DENY',
                'user_id' => $user['id'],
                'username' => $user['username'] ?? null
            ];
        }
    }

    // Get DCC approve emoji reactions
    $dccUsers = $discord->getReactions($channelId, $messageId, DCC_APPROVE_EMOJI, ['limit' => 100]);
    if ($dccUsers && is_array($dccUsers)) {
        foreach ($dccUsers as $user) {
            $reactions[] = [
                'emoji' => DCC_APPROVE_EMOJI,
                'emoji_name' => DCC_APPROVE_EMOJI,
                'facility_code' => null,
                'type' => 'DCC_APPROVE',
                'user_id' => $user['id'],
                'username' => $user['username'] ?? null
            ];
        }
    }

    return $reactions;
}

function extractEmojiName($emoji) {
    // Handle custom emoji format <:name:id> or :name:
    if (preg_match('/<:(\w+):(\d+)>/', $emoji, $matches)) {
        return $matches[1] . ':' . $matches[2];
    }
    if (preg_match('/:(\w+):/', $emoji, $matches)) {
        return $matches[1];
    }
    return $emoji;
}

function processReaction($conn, $discord, $proposal, $facilities, $reaction) {
    $proposalId = $proposal['proposal_id'];
    $userId = $reaction['user_id'];
    $emoji = $reaction['emoji'];
    $type = $reaction['type'];
    $facCode = $reaction['facility_code'];

    // Skip bot reactions
    if ($userId === $discord->getBotUserId()) {
        return;
    }

    // Check if already logged
    $checkSql = "SELECT COUNT(*) FROM dbo.tmi_proposal_reactions
                 WHERE proposal_id = :prop_id AND discord_user_id = :user_id AND emoji = :emoji";
    $checkStmt = $conn->prepare($checkSql);
    $checkStmt->execute([':prop_id' => $proposalId, ':user_id' => $userId, ':emoji' => $emoji]);

    if ($checkStmt->fetchColumn() > 0) {
        return; // Already processed
    }

    // Get user roles
    $userRoles = getUserRoles($discord, $userId);
    $isDccUser = canDccOverride($userId, $userRoles);

    // Determine reaction type
    $reactionType = $type;
    if ($type === 'DENY' && $isDccUser) {
        $reactionType = 'DCC_DENY';
    } elseif ($type === 'DCC_APPROVE' && !$isDccUser) {
        $reactionType = 'OTHER'; // Not authorized for DCC actions
    }

    // Log reaction
    $logSql = "INSERT INTO dbo.tmi_proposal_reactions (
                   proposal_id, emoji, reaction_type, discord_user_id, discord_username, discord_roles, facility_code
               ) VALUES (
                   :prop_id, :emoji, :type, :user_id, :username, :roles, :fac_code
               )";
    $logStmt = $conn->prepare($logSql);
    $logStmt->execute([
        ':prop_id' => $proposalId,
        ':emoji' => $emoji,
        ':type' => $reactionType,
        ':user_id' => $userId,
        ':username' => $reaction['username'],
        ':roles' => json_encode($userRoles),
        ':fac_code' => $facCode
    ]);

    echo "    Logged reaction: {$emoji} by {$reaction['username']} (type: {$reactionType})\n";

    // Process based on type
    if ($reactionType === 'FACILITY_APPROVE' && $facCode) {
        // Update facility status
        $updateSql = "UPDATE dbo.tmi_proposal_facilities SET
                          approval_status = 'APPROVED',
                          reacted_at = SYSUTCDATETIME(),
                          reacted_by_user_id = :user_id,
                          reacted_by_username = :username
                      WHERE proposal_id = :prop_id AND facility_code = :fac_code AND approval_status = 'PENDING'";
        $updateStmt = $conn->prepare($updateSql);
        $updateStmt->execute([
            ':user_id' => $userId,
            ':username' => $reaction['username'],
            ':prop_id' => $proposalId,
            ':fac_code' => $facCode
        ]);
        echo "    Updated facility {$facCode} to APPROVED\n";

    } elseif ($reactionType === 'DCC_APPROVE') {
        // DCC override approve
        $updateSql = "UPDATE dbo.tmi_proposals SET
                          dcc_override = 1,
                          dcc_override_action = 'APPROVE',
                          dcc_override_by = :user_id,
                          dcc_override_at = SYSUTCDATETIME(),
                          updated_at = SYSUTCDATETIME()
                      WHERE proposal_id = :prop_id";
        $updateStmt = $conn->prepare($updateSql);
        $updateStmt->execute([':user_id' => $userId, ':prop_id' => $proposalId]);
        echo "    DCC OVERRIDE: APPROVE by {$reaction['username']}\n";

    } elseif ($reactionType === 'DCC_DENY') {
        // DCC override deny
        $updateSql = "UPDATE dbo.tmi_proposals SET
                          dcc_override = 1,
                          dcc_override_action = 'DENY',
                          dcc_override_by = :user_id,
                          dcc_override_at = SYSUTCDATETIME(),
                          updated_at = SYSUTCDATETIME()
                      WHERE proposal_id = :prop_id";
        $updateStmt = $conn->prepare($updateSql);
        $updateStmt->execute([':user_id' => $userId, ':prop_id' => $proposalId]);
        echo "    DCC OVERRIDE: DENY by {$reaction['username']}\n";

    } elseif ($reactionType === 'DENY') {
        // Facility denial - mark any matching facility as denied
        // This is tricky - we need to figure out which facility is denying
        // For now, just log it
        echo "    Denial logged (facility TBD)\n";
    }
}

function getUserRoles($discord, $userId) {
    try {
        $member = $discord->getGuildMember($userId);
        if ($member && isset($member['roles'])) {
            return $member['roles'];
        }
    } catch (Exception $e) {
        // Ignore errors
    }
    return [];
}

function canDccOverride($userId, $userRoles) {
    // Check user ID
    if (in_array($userId, DCC_OVERRIDE_USERS)) {
        return true;
    }

    // Check roles - would need role ID mapping
    // For now, just check user ID
    return false;
}

function checkAndUpdateProposalStatus($conn, $proposalId) {
    // Use stored procedure
    $sql = "EXEC dbo.sp_CheckProposalApproval @proposal_id = :prop_id";
    $stmt = $conn->prepare($sql);
    $stmt->execute([':prop_id' => $proposalId]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($result) {
        echo "    Status check: {$result['status']} ({$result['action_taken']})\n";

        if ($result['status'] === 'APPROVED') {
            activateApprovedProposal($conn, $proposalId);
        }
    }
}

function activateApprovedProposal($conn, $proposalId) {
    echo "    Activating approved proposal #{$proposalId}...\n";

    // Get proposal data
    $sql = "SELECT * FROM dbo.tmi_proposals WHERE proposal_id = :id";
    $stmt = $conn->prepare($sql);
    $stmt->execute([':id' => $proposalId]);
    $proposal = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$proposal) {
        echo "    ERROR: Proposal not found\n";
        return;
    }

    // Determine if scheduled or immediate
    $validFrom = $proposal['valid_from'];
    $now = new DateTime('now', new DateTimeZone('UTC'));

    if ($validFrom) {
        $startTime = new DateTime($validFrom);
        $isScheduled = $startTime > $now;
    } else {
        $isScheduled = false;
    }

    $newStatus = $isScheduled ? 'SCHEDULED' : 'ACTIVATED';

    // TODO: Actually create the TMI entry via publish logic
    // For now, just update status

    $updateSql = "UPDATE dbo.tmi_proposals SET
                      status = :status,
                      activated_at = SYSUTCDATETIME(),
                      updated_at = SYSUTCDATETIME()
                  WHERE proposal_id = :prop_id";
    $updateStmt = $conn->prepare($updateSql);
    $updateStmt->execute([':status' => $newStatus, ':prop_id' => $proposalId]);

    echo "    Proposal set to {$newStatus}\n";

    // Post confirmation to Discord
    postApprovalConfirmation($proposal, $newStatus);
}

function postApprovalConfirmation($proposal, $status) {
    try {
        $discord = new DiscordAPI();
        $channelId = $proposal['discord_channel_id'] ?: COORDINATION_CHANNEL;
        $messageId = $proposal['discord_message_id'];

        // Reply to the original message
        $statusText = $status === 'ACTIVATED' ? 'ACTIVATED (now live)' : 'SCHEDULED';
        $content = "✅ **Proposal #{$proposal['proposal_id']} APPROVED** - Status: **{$statusText}**";

        $discord->createMessage($channelId, [
            'content' => $content,
            'message_reference' => ['message_id' => $messageId]
        ]);

    } catch (Exception $e) {
        error_log("Failed to post approval confirmation: " . $e->getMessage());
    }
}

function processExpiredProposals($conn) {
    // Find proposals past deadline without unanimous approval
    $sql = "UPDATE dbo.tmi_proposals SET
                status = 'EXPIRED',
                updated_at = SYSUTCDATETIME()
            WHERE status = 'PENDING'
              AND approval_deadline_utc < SYSUTCDATETIME()
              AND dcc_override = 0";
    $stmt = $conn->exec($sql);

    if ($stmt > 0) {
        echo "Marked {$stmt} proposal(s) as EXPIRED\n";
    }
}
