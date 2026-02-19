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

// Prevent direct browser access (allow CLI, scheduler include, or valid cron key)
if (php_sapi_name() !== 'cli' && !isset($_GET['cron_key'])) {
    http_response_code(403);
    echo 'CLI or cron key required';
    return; // Don't die() - allow graceful return when included
}

// Optional cron key for HTTP access
$expectedKey = getenv('CRON_KEY') ?: 'tmi_proposal_cron_2026';
if (isset($_GET['cron_key']) && $_GET['cron_key'] !== $expectedKey) {
    http_response_code(403);
    echo 'Invalid cron key';
    return; // Don't die() - allow graceful return when included
}

require_once __DIR__ . '/../load/config.php';
require_once __DIR__ . '/../load/discord/DiscordAPI.php';
require_once __DIR__ . '/../load/discord/TMIDiscord.php';

// =============================================================================
// CONSTANTS
// =============================================================================

define('COORDINATION_CHANNEL', '1466013550450577491');
define('DENY_EMOJI', '❌');
define('DENY_EMOJI_ALT', '🚫');
define('DCC_APPROVE_EMOJI', 'DCC');

// DCC override users
define('DCC_OVERRIDE_USERS', [
    '396865467840593930'  // jpeterson24
]);

// DCC override roles (role IDs - preferred)
define('DCC_OVERRIDE_ROLE_IDS', [
    '1268395552496816231',  // @DCC Staff
    '1268395359714021396'   // @NTMO
]);

// DCC override roles (role names - fallback)
define('DCC_OVERRIDE_ROLE_NAMES', [
    'DCC Staff',
    'NTMO'
]);

// Regional indicator emoji mapping for alternate approval method
// Must match coordinate.php FACILITY_REGIONAL_EMOJI_MAP
define('FACILITY_EMOJI_MAP', [
    // US ARTCCs
    'ZAB' => '🇦',  // A - Albuquerque
    'ZAN' => '🇬',  // G - anchoraGe
    'ZAU' => '🇺',  // U - Chicago
    'ZBW' => '🇧',  // B - Boston
    'ZDC' => '🇩',  // D - Washington DC
    'ZDV' => '🇻',  // V - DenVer
    'ZFW' => '🇫',  // F - Fort Worth
    'ZHN' => '🇭',  // H - Honolulu
    'ZHU' => '🇼',  // W - Houston
    'ZID' => '🇮',  // I - Indianapolis
    'ZJX' => '🇯',  // J - Jacksonville
    'ZKC' => '🇰',  // K - Kansas City
    'ZLA' => '🇱',  // L - Los Angeles
    'ZLC' => '🇨',  // C - Salt Lake City
    'ZMA' => '🇲',  // M - Miami
    'ZME' => '🇪',  // E - mEmphis
    'ZMP' => '🇵',  // P - minneaPolis
    'ZNY' => '🇳',  // N - New York
    'ZOA' => '🇴',  // O - Oakland
    'ZOB' => '🇷',  // R - cleveland
    'ZSE' => '🇸',  // S - Seattle
    'ZTL' => '🇹',  // T - aTlanta
    // Canadian FIRs
    'CZEG' => '1️⃣',  // 1 - Edmonton
    'CZVR' => '2️⃣',  // 2 - Vancouver
    'CZWG' => '3️⃣',  // 3 - Winnipeg
    'CZYZ' => '4️⃣',  // 4 - Toronto
    'CZQM' => '5️⃣',  // 5 - Moncton
    'CZQX' => '6️⃣',  // 6 - Gander Domestic
    'CZQO' => '7️⃣',  // 7 - Gander Oceanic
    'CZUL' => '8️⃣',  // 8 - Montreal
]);

// Reverse mapping: emoji to facility
define('EMOJI_TO_FACILITY', array_flip(FACILITY_EMOJI_MAP));

// Fallback emojis for non-ARTCC facilities
define('FALLBACK_EMOJIS', [
    '🟥', '🟧', '🟨', '🟩', '🟦', '🟪', '🟫', '⬛', '⬜',
    '🔴', '🟠', '🟡', '🟢', '🔵', '🟣', '🟤', '⚫', '⚪'
]);

// TRACON to ARTCC mapping (for parent facility lookup)
define('TRACON_TO_ARTCC', [
    'N90' => 'ZNY', 'PCT' => 'ZDC', 'PHL' => 'ZNY', 'A90' => 'ZBW',
    'A80' => 'ZTL', 'MIA' => 'ZMA', 'JAX' => 'ZJX',
    'C90' => 'ZAU', 'D10' => 'ZFW', 'I90' => 'ZHU', 'M98' => 'ZME',
    'D01' => 'ZDV', 'MCI' => 'ZKC', 'IND' => 'ZID', 'MSP' => 'ZMP',
    'SCT' => 'ZLA', 'NCT' => 'ZOA', 'S46' => 'ZSE', 'P50' => 'ZLA',
    'ABQ' => 'ZAB', 'SLC' => 'ZLC', 'HCF' => 'ZHN',
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
    // Don't exit - allow scheduler to continue if included from there
    if (php_sapi_name() === 'cli') {
        exit(1);
    }
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
    $facilityCodes = array_keys($facilityEmojis);

    // Build emoji-to-facility mapping for this proposal
    // Track which emojis we need to check and what facility they map to
    $emojiToFacility = [];
    $usedEmojis = [];

    foreach ($facilityCodes as $facCode) {
        // Custom emoji (e.g., :ZDC:)
        $customEmoji = $facilityEmojis[$facCode];
        $emojiToFacility[$customEmoji] = $facCode;

        // Alternate emoji (regional indicator or fallback)
        $altEmojiInfo = getEmojiForFacility($facCode, $usedEmojis);
        if ($altEmojiInfo['emoji']) {
            $emojiToFacility[$altEmojiInfo['emoji']] = $facCode;
        }
    }

    // Check each emoji for reactions
    foreach ($emojiToFacility as $emoji => $facCode) {
        $emojiQuery = normalizeReactionEmojiForApi($emoji);
        if ($emojiQuery === null) {
            continue;
        }

        $users = $discord->getReactions($channelId, $messageId, $emojiQuery, ['limit' => 100]);

        if ($users && is_array($users)) {
            foreach ($users as $user) {
                $reactions[] = [
                    'emoji' => $emoji,
                    'emoji_name' => $emojiQuery,
                    'facility_code' => $facCode,
                    'type' => 'FACILITY_APPROVE',
                    'user_id' => $user['id'],
                    'username' => $user['username'] ?? null
                ];
            }
        }
    }

    // Get deny emoji reactions (primary ❌)
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

    // Get alternate deny emoji reactions (🚫)
    $denyAltUsers = $discord->getReactions($channelId, $messageId, DENY_EMOJI_ALT, ['limit' => 100]);
    if ($denyAltUsers && is_array($denyAltUsers)) {
        foreach ($denyAltUsers as $user) {
            $reactions[] = [
                'emoji' => DENY_EMOJI_ALT,
                'emoji_name' => DENY_EMOJI_ALT,
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

/**
 * Get the appropriate emoji for a facility (matches coordinate.php logic)
 */
function getEmojiForFacility($facilityCode, &$usedEmojis) {
    $facilityCode = strtoupper(trim($facilityCode));

    // 1. Check if facility itself has an emoji mapping (ARTCC/FIR)
    if (isset(FACILITY_EMOJI_MAP[$facilityCode])) {
        $emoji = FACILITY_EMOJI_MAP[$facilityCode];
        if (!in_array($emoji, $usedEmojis)) {
            $usedEmojis[] = $emoji;
            return ['emoji' => $emoji, 'type' => 'artcc', 'parent' => null];
        }
    }

    // 2. Check if facility has a parent ARTCC with an emoji
    $parentArtcc = getParentArtcc($facilityCode);
    if ($parentArtcc && isset(FACILITY_EMOJI_MAP[$parentArtcc])) {
        $emoji = FACILITY_EMOJI_MAP[$parentArtcc];
        if (!in_array($emoji, $usedEmojis)) {
            $usedEmojis[] = $emoji;
            return ['emoji' => $emoji, 'type' => 'parent', 'parent' => $parentArtcc];
        }
    }

    // 3. Use a fallback emoji
    foreach (FALLBACK_EMOJIS as $fallbackEmoji) {
        if (!in_array($fallbackEmoji, $usedEmojis)) {
            $usedEmojis[] = $fallbackEmoji;
            return ['emoji' => $fallbackEmoji, 'type' => 'fallback', 'parent' => $parentArtcc];
        }
    }

    return ['emoji' => null, 'type' => 'none', 'parent' => null];
}

/**
 * Get the parent ARTCC for a facility code
 * Matches coordinate.php logic - checks TRACONs and airports
 */
function getParentArtcc($facilityCode) {
    if (empty($facilityCode)) return null;
    $facilityCode = strtoupper(trim($facilityCode));

    // If already an ARTCC/FIR, return itself
    if (isset(FACILITY_EMOJI_MAP[$facilityCode])) {
        return $facilityCode;
    }

    // Check TRACON mapping
    if (isset(TRACON_TO_ARTCC[$facilityCode])) {
        return TRACON_TO_ARTCC[$facilityCode];
    }

    // Common airport to ARTCC mappings (matches coordinate.php)
    $airportToArtcc = [
        // Major Northeast
        'KJFK' => 'ZNY', 'KLGA' => 'ZNY', 'KEWR' => 'ZNY', 'KTEB' => 'ZNY',
        'KPHL' => 'ZNY', 'KBOS' => 'ZBW', 'KBDL' => 'ZBW',
        'KDCA' => 'ZDC', 'KIAD' => 'ZDC', 'KBWI' => 'ZDC',
        // Major Southeast
        'KATL' => 'ZTL', 'KCLT' => 'ZTL', 'KMCO' => 'ZJX', 'KTPA' => 'ZJX',
        'KMIA' => 'ZMA', 'KFLL' => 'ZMA', 'KPBI' => 'ZMA',
        // Major Central
        'KORD' => 'ZAU', 'KMDW' => 'ZAU', 'KDFW' => 'ZFW', 'KDAL' => 'ZFW',
        'KIAH' => 'ZHU', 'KHOU' => 'ZHU', 'KMEM' => 'ZME', 'KBNA' => 'ZME',
        'KDEN' => 'ZDV', 'KCOS' => 'ZDV', 'KMCI' => 'ZKC', 'KSTL' => 'ZKC',
        'KIND' => 'ZID', 'KCVG' => 'ZID', 'KMSP' => 'ZMP', 'KDTW' => 'ZOB',
        'KCLE' => 'ZOB', 'KPIT' => 'ZOB',
        // Major West
        'KLAX' => 'ZLA', 'KSAN' => 'ZLA', 'KLAS' => 'ZLA', 'KPHX' => 'ZAB',
        'KSFO' => 'ZOA', 'KOAK' => 'ZOA', 'KSJC' => 'ZOA',
        'KSEA' => 'ZSE', 'KPDX' => 'ZSE', 'KSLC' => 'ZLC',
        'KABQ' => 'ZAB', 'KHNL' => 'ZHN', 'PANC' => 'ZAN',
        // Canada
        'CYYZ' => 'CZYZ', 'CYUL' => 'CZUL', 'CYVR' => 'CZVR', 'CYYC' => 'CZEG',
        'CYEG' => 'CZEG', 'CYWG' => 'CZWG', 'CYOW' => 'CZUL', 'CYQB' => 'CZUL',
    ];

    // Check direct airport mapping
    if (isset($airportToArtcc[$facilityCode])) {
        return $airportToArtcc[$facilityCode];
    }

    // Try with K/C prefix for US/Canada airports
    $withK = 'K' . $facilityCode;
    $withC = 'C' . $facilityCode;
    if (isset($airportToArtcc[$withK])) return $airportToArtcc[$withK];
    if (isset($airportToArtcc[$withC])) return $airportToArtcc[$withC];

    return null;
}

/**
 * Normalize emoji query input for DiscordAPI::getReactions().
 * - Unicode emoji: pass raw string (DiscordAPI encodes it once).
 * - Custom emoji with id (name:id): pass through.
 * - Placeholder custom emoji (:NAME:): cannot be queried without id; skip.
 *
 * @param string $emoji
 * @return string|null
 */
function normalizeReactionEmojiForApi($emoji) {
    $emoji = trim((string)$emoji);
    if ($emoji === '') {
        return null;
    }

    if (preg_match('/^[A-Za-z0-9_]+:\d+$/', $emoji)) {
        return $emoji;
    }

    if (preg_match('/^:[A-Za-z0-9_]+:$/', $emoji)) {
        return null;
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

    // Check role IDs
    if (!empty(array_intersect($userRoles, DCC_OVERRIDE_ROLE_IDS))) {
        return true;
    }

    // Check role names (fallback)
    if (!empty(array_intersect($userRoles, DCC_OVERRIDE_ROLE_NAMES))) {
        return true;
    }

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
            // Don't auto-activate - proposals stay in APPROVED status
            // User must manually publish from the TMI Publisher queue
            echo "    Proposal #{$proposalId} is APPROVED - awaiting manual publication\n";
            postApprovalNotification($conn, $proposalId);
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

/**
 * Post notification that proposal is approved and awaiting manual publication
 */
function postApprovalNotification($conn, $proposalId) {
    try {
        // Get proposal data
        $sql = "SELECT * FROM dbo.tmi_proposals WHERE proposal_id = :id";
        $stmt = $conn->prepare($sql);
        $stmt->execute([':id' => $proposalId]);
        $proposal = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$proposal) return;

        $discord = new DiscordAPI();
        $channelId = $proposal['discord_channel_id'] ?: COORDINATION_CHANNEL;
        $messageId = $proposal['discord_message_id'];

        // Reply to the original message
        $entryType = $proposal['entry_type'] ?? 'TMI';
        $ctlElement = $proposal['ctl_element'] ?? '';
        $content = "✅ **Proposal #{$proposalId} FULLY APPROVED** - {$entryType} {$ctlElement}\n";
        $content .= "📋 Ready for publication in [TMI Publisher](https://perti.vatcscc.net/tmi-publish)";

        $discord->createMessage($channelId, [
            'content' => $content,
            'message_reference' => ['message_id' => $messageId]
        ]);

        echo "    Posted approval notification to Discord\n";

    } catch (Exception $e) {
        echo "    Failed to post approval notification: " . $e->getMessage() . "\n";
        error_log("Failed to post approval notification: " . $e->getMessage());
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
