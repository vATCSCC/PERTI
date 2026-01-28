<?php
/**
 * TMI Coordination API
 *
 * Handles TMI coordination workflow:
 * - POST: Submit TMI for coordination
 * - GET: Get proposal status
 * - PUT: Process reaction/approval
 *
 * @package PERTI
 * @subpackage API/TMI
 * @version 1.0.0
 * @date 2026-01-28
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle CORS preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../../../load/config.php';
require_once __DIR__ . '/../../../load/discord/DiscordAPI.php';
require_once __DIR__ . '/../../../load/discord/TMIDiscord.php';

// =============================================================================
// CONSTANTS
// =============================================================================

// Coordination channel ID
define('DISCORD_COORDINATION_CHANNEL', '1466013550450577491');

// DCC override users (Discord user IDs)
define('DCC_OVERRIDE_USERS', [
    '396865467840593930'  // jpeterson24
]);

// DCC override roles
define('DCC_OVERRIDE_ROLES', [
    'DCC Staff',
    'NTMO'
]);

// Deny emoji
define('DENY_EMOJI', '❌');

// DCC approval emoji (custom)
define('DCC_APPROVE_EMOJI', 'DCC');

// =============================================================================
// DATABASE CONNECTION
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

// =============================================================================
// ROUTE HANDLING
// =============================================================================

$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'POST':
        handleSubmitForCoordination();
        break;
    case 'GET':
        handleGetProposalStatus();
        break;
    case 'PUT':
        handleProcessReaction();
        break;
    default:
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Method not allowed']);
}

// =============================================================================
// POST: Submit TMI for Coordination
// =============================================================================

function handleSubmitForCoordination() {
    $input = json_decode(file_get_contents('php://input'), true);

    if (!$input) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid JSON input']);
        return;
    }

    // Required fields
    $entry = $input['entry'] ?? null;
    $deadlineUtc = $input['deadlineUtc'] ?? null;
    $facilities = $input['facilities'] ?? [];
    $userCid = $input['userCid'] ?? null;
    $userName = $input['userName'] ?? 'Unknown';

    if (!$entry || !$deadlineUtc) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'entry and deadlineUtc are required']);
        return;
    }

    if (empty($facilities)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'At least one facility must be specified']);
        return;
    }

    // Parse deadline
    $deadline = new DateTime($deadlineUtc, new DateTimeZone('UTC'));
    if (!$deadline || $deadline <= new DateTime('now', new DateTimeZone('UTC'))) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Deadline must be in the future']);
        return;
    }

    // Connect to database
    $conn = getTmiConnection();
    if (!$conn) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Database connection failed']);
        return;
    }

    try {
        $conn->beginTransaction();

        // Extract entry info
        $entryType = $entry['entryType'] ?? 'UNKNOWN';
        $data = $entry['data'] ?? [];
        $requestingFacility = $data['requesting_facility'] ?? $data['req_fac'] ?? 'DCC';
        $providingFacility = $data['providing_facility'] ?? $data['prov_fac'] ?? null;
        $ctlElement = $data['ctl_element'] ?? $data['airport'] ?? null;
        $validFrom = $data['valid_from'] ?? null;
        $validUntil = $data['valid_until'] ?? null;

        // Build raw NTML text
        $rawText = buildNtmlText($entry);

        // Insert proposal
        $sql = "INSERT INTO dbo.tmi_proposals (
                    entry_type, requesting_facility, providing_facility, ctl_element,
                    entry_data_json, raw_text,
                    approval_deadline_utc, valid_from, valid_until,
                    facilities_required,
                    created_by, created_by_name
                ) OUTPUT INSERTED.proposal_id, INSERTED.proposal_guid
                VALUES (
                    :entry_type, :req_fac, :prov_fac, :ctl_element,
                    :entry_json, :raw_text,
                    :deadline, :valid_from, :valid_until,
                    :fac_count,
                    :user_cid, :user_name
                )";

        $stmt = $conn->prepare($sql);
        $stmt->execute([
            ':entry_type' => $entryType,
            ':req_fac' => $requestingFacility,
            ':prov_fac' => $providingFacility,
            ':ctl_element' => $ctlElement,
            ':entry_json' => json_encode($entry),
            ':raw_text' => $rawText,
            ':deadline' => $deadline->format('Y-m-d H:i:s'),
            ':valid_from' => $validFrom ? (new DateTime($validFrom))->format('Y-m-d H:i:s') : null,
            ':valid_until' => $validUntil ? (new DateTime($validUntil))->format('Y-m-d H:i:s') : null,
            ':fac_count' => count($facilities),
            ':user_cid' => $userCid,
            ':user_name' => $userName
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $proposalId = $row['proposal_id'];
        $proposalGuid = $row['proposal_guid'];

        // Insert required facilities
        foreach ($facilities as $facility) {
            $facCode = is_array($facility) ? ($facility['code'] ?? $facility) : $facility;
            $facName = is_array($facility) ? ($facility['name'] ?? null) : null;
            $facEmoji = is_array($facility) ? ($facility['emoji'] ?? null) : null;

            $facSql = "INSERT INTO dbo.tmi_proposal_facilities (
                           proposal_id, facility_code, facility_name, approval_emoji
                       ) VALUES (:prop_id, :code, :name, :emoji)";
            $facStmt = $conn->prepare($facSql);
            $facStmt->execute([
                ':prop_id' => $proposalId,
                ':code' => strtoupper($facCode),
                ':name' => $facName,
                ':emoji' => $facEmoji
            ]);
        }

        // Post to Discord coordination channel
        $discordResult = postProposalToDiscord($proposalId, $entry, $deadline, $facilities, $userName);

        if ($discordResult && isset($discordResult['id'])) {
            // Update proposal with Discord message ID
            $updateSql = "UPDATE dbo.tmi_proposals SET
                              discord_channel_id = :channel,
                              discord_message_id = :message_id,
                              discord_posted_at = SYSUTCDATETIME()
                          WHERE proposal_id = :prop_id";
            $updateStmt = $conn->prepare($updateSql);
            $updateStmt->execute([
                ':channel' => DISCORD_COORDINATION_CHANNEL,
                ':message_id' => $discordResult['id'],
                ':prop_id' => $proposalId
            ]);
        }

        $conn->commit();

        echo json_encode([
            'success' => true,
            'proposal_id' => $proposalId,
            'proposal_guid' => $proposalGuid,
            'discord' => $discordResult ? [
                'success' => true,
                'message_id' => $discordResult['id'] ?? null,
                'channel_id' => DISCORD_COORDINATION_CHANNEL
            ] : ['success' => false, 'error' => 'Failed to post to Discord']
        ]);

    } catch (Exception $e) {
        $conn->rollBack();
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
    }
}

// =============================================================================
// GET: Get Proposal Status
// =============================================================================

function handleGetProposalStatus() {
    $proposalId = $_GET['proposal_id'] ?? null;
    $messageId = $_GET['message_id'] ?? null;

    if (!$proposalId && !$messageId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'proposal_id or message_id required']);
        return;
    }

    $conn = getTmiConnection();
    if (!$conn) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Database connection failed']);
        return;
    }

    try {
        // Get proposal
        if ($proposalId) {
            $sql = "SELECT * FROM dbo.tmi_proposals WHERE proposal_id = :id";
            $stmt = $conn->prepare($sql);
            $stmt->execute([':id' => $proposalId]);
        } else {
            $sql = "SELECT * FROM dbo.tmi_proposals WHERE discord_message_id = :msg_id";
            $stmt = $conn->prepare($sql);
            $stmt->execute([':msg_id' => $messageId]);
        }

        $proposal = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$proposal) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Proposal not found']);
            return;
        }

        // Get facilities
        $facSql = "SELECT * FROM dbo.tmi_proposal_facilities WHERE proposal_id = :id ORDER BY facility_code";
        $facStmt = $conn->prepare($facSql);
        $facStmt->execute([':id' => $proposal['proposal_id']]);
        $facilities = $facStmt->fetchAll(PDO::FETCH_ASSOC);

        // Get reactions
        $rxnSql = "SELECT * FROM dbo.tmi_proposal_reactions WHERE proposal_id = :id ORDER BY reacted_at";
        $rxnStmt = $conn->prepare($rxnSql);
        $rxnStmt->execute([':id' => $proposal['proposal_id']]);
        $reactions = $rxnStmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'success' => true,
            'proposal' => $proposal,
            'facilities' => $facilities,
            'reactions' => $reactions
        ]);

    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Query error: ' . $e->getMessage()]);
    }
}

// =============================================================================
// PUT: Process Reaction (called by webhook or polling)
// =============================================================================

function handleProcessReaction() {
    $input = json_decode(file_get_contents('php://input'), true);

    $proposalId = $input['proposal_id'] ?? null;
    $messageId = $input['message_id'] ?? null;
    $emoji = $input['emoji'] ?? null;
    $emojiId = $input['emoji_id'] ?? null;
    $discordUserId = $input['discord_user_id'] ?? null;
    $discordUsername = $input['discord_username'] ?? null;
    $userRoles = $input['user_roles'] ?? [];

    if ((!$proposalId && !$messageId) || !$emoji || !$discordUserId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Missing required fields']);
        return;
    }

    $conn = getTmiConnection();
    if (!$conn) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Database connection failed']);
        return;
    }

    try {
        // Get proposal
        if ($proposalId) {
            $sql = "SELECT * FROM dbo.tmi_proposals WHERE proposal_id = :id";
            $stmt = $conn->prepare($sql);
            $stmt->execute([':id' => $proposalId]);
        } else {
            $sql = "SELECT * FROM dbo.tmi_proposals WHERE discord_message_id = :msg_id";
            $stmt = $conn->prepare($sql);
            $stmt->execute([':msg_id' => $messageId]);
        }

        $proposal = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$proposal) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Proposal not found']);
            return;
        }

        $proposalId = $proposal['proposal_id'];

        // Check if already processed
        if ($proposal['status'] !== 'PENDING') {
            echo json_encode(['success' => true, 'message' => 'Proposal already processed', 'status' => $proposal['status']]);
            return;
        }

        // Determine reaction type
        $reactionType = 'OTHER';
        $facilityCode = null;
        $isDccOverride = false;
        $dccAction = null;

        // Check for DCC override
        $isDccUser = in_array($discordUserId, DCC_OVERRIDE_USERS);
        $hasDccRole = !empty(array_intersect($userRoles, DCC_OVERRIDE_ROLES));

        if ($isDccUser || $hasDccRole) {
            if (strtoupper($emoji) === DCC_APPROVE_EMOJI || $emoji === ':DCC:') {
                $reactionType = 'DCC_APPROVE';
                $isDccOverride = true;
                $dccAction = 'APPROVE';
            } elseif ($emoji === DENY_EMOJI || $emoji === '❌') {
                $reactionType = 'DCC_DENY';
                $isDccOverride = true;
                $dccAction = 'DENY';
            }
        }

        // Check for facility approval/denial
        if (!$isDccOverride) {
            // Get facilities for this proposal
            $facSql = "SELECT * FROM dbo.tmi_proposal_facilities WHERE proposal_id = :id";
            $facStmt = $conn->prepare($facSql);
            $facStmt->execute([':id' => $proposalId]);
            $facilities = $facStmt->fetchAll(PDO::FETCH_ASSOC);

            // Check if emoji matches a facility
            foreach ($facilities as $fac) {
                if ($fac['approval_emoji'] && strpos($emoji, $fac['facility_code']) !== false) {
                    $reactionType = 'FACILITY_APPROVE';
                    $facilityCode = $fac['facility_code'];
                    break;
                }
            }

            // Check for deny emoji
            if ($emoji === DENY_EMOJI || $emoji === '❌') {
                $reactionType = 'FACILITY_DENY';
                // Try to determine which facility based on user roles or other context
            }
        }

        // Log the reaction
        $rxnSql = "INSERT INTO dbo.tmi_proposal_reactions (
                       proposal_id, emoji, emoji_id, reaction_type,
                       discord_user_id, discord_username, discord_roles, facility_code
                   ) VALUES (
                       :prop_id, :emoji, :emoji_id, :rxn_type,
                       :user_id, :username, :roles, :fac_code
                   )";
        $rxnStmt = $conn->prepare($rxnSql);
        $rxnStmt->execute([
            ':prop_id' => $proposalId,
            ':emoji' => $emoji,
            ':emoji_id' => $emojiId,
            ':rxn_type' => $reactionType,
            ':user_id' => $discordUserId,
            ':username' => $discordUsername,
            ':roles' => json_encode($userRoles),
            ':fac_code' => $facilityCode
        ]);

        // Process based on reaction type
        if ($isDccOverride) {
            // DCC override - update proposal
            $updateSql = "UPDATE dbo.tmi_proposals SET
                              dcc_override = 1,
                              dcc_override_action = :action,
                              dcc_override_by = :user_id,
                              dcc_override_at = SYSUTCDATETIME(),
                              updated_at = SYSUTCDATETIME()
                          WHERE proposal_id = :prop_id";
            $updateStmt = $conn->prepare($updateSql);
            $updateStmt->execute([
                ':action' => $dccAction,
                ':user_id' => $discordUserId,
                ':prop_id' => $proposalId
            ]);
        } elseif ($facilityCode && $reactionType === 'FACILITY_APPROVE') {
            // Update facility approval status
            $updateFacSql = "UPDATE dbo.tmi_proposal_facilities SET
                                 approval_status = 'APPROVED',
                                 reacted_at = SYSUTCDATETIME(),
                                 reacted_by_user_id = :user_id,
                                 reacted_by_username = :username
                             WHERE proposal_id = :prop_id AND facility_code = :fac_code";
            $updateFacStmt = $conn->prepare($updateFacSql);
            $updateFacStmt->execute([
                ':user_id' => $discordUserId,
                ':username' => $discordUsername,
                ':prop_id' => $proposalId,
                ':fac_code' => $facilityCode
            ]);
        }

        // Check if proposal should be approved/denied
        $checkSql = "EXEC dbo.sp_CheckProposalApproval @proposal_id = :prop_id";
        $checkStmt = $conn->prepare($checkSql);
        $checkStmt->execute([':prop_id' => $proposalId]);
        $result = $checkStmt->fetch(PDO::FETCH_ASSOC);

        // If approved, activate the TMI
        if ($result && $result['status'] === 'APPROVED') {
            $activationResult = activateProposal($conn, $proposalId);
            $result['activation'] = $activationResult;
        }

        echo json_encode([
            'success' => true,
            'reaction_type' => $reactionType,
            'result' => $result
        ]);

    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Processing error: ' . $e->getMessage()]);
    }
}

// =============================================================================
// HELPER FUNCTIONS
// =============================================================================

/**
 * Build NTML text from entry data
 */
function buildNtmlText($entry) {
    try {
        $tmiDiscord = new TMIDiscord();
        return $tmiDiscord->buildNTMLMessageFromEntry($entry);
    } catch (Exception $e) {
        return json_encode($entry);
    }
}

/**
 * Post proposal to Discord coordination channel
 */
function postProposalToDiscord($proposalId, $entry, $deadline, $facilities, $userName) {
    try {
        $discord = new DiscordAPI();
        if (!$discord->isConfigured()) {
            return null;
        }

        // Build message content
        $content = formatProposalMessage($proposalId, $entry, $deadline, $facilities, $userName);

        // Post to coordination channel
        $result = $discord->createMessage(DISCORD_COORDINATION_CHANNEL, [
            'content' => $content
        ]);

        if ($result && isset($result['id'])) {
            // Add initial reactions for each facility
            foreach ($facilities as $facility) {
                $facCode = is_array($facility) ? ($facility['code'] ?? $facility) : $facility;
                $facEmoji = is_array($facility) ? ($facility['emoji'] ?? null) : null;

                if ($facEmoji) {
                    $discord->createReaction(DISCORD_COORDINATION_CHANNEL, $result['id'], $facEmoji);
                }
            }

            // Add deny reaction
            $discord->createReaction(DISCORD_COORDINATION_CHANNEL, $result['id'], DENY_EMOJI);
        }

        return $result;

    } catch (Exception $e) {
        error_log("Discord post failed: " . $e->getMessage());
        return null;
    }
}

/**
 * Format proposal message for Discord
 */
function formatProposalMessage($proposalId, $entry, $deadline, $facilities, $userName) {
    $entryType = $entry['entryType'] ?? 'TMI';
    $data = $entry['data'] ?? [];

    // Get NTML text
    $ntmlText = buildNtmlText($entry);

    // Format deadline timestamps
    $deadlineUnix = $deadline->getTimestamp();
    $deadlineUtcStr = $deadline->format('Y-m-d H:i') . 'Z';
    $deadlineDiscordLong = "<t:{$deadlineUnix}:F>";      // Full date/time
    $deadlineDiscordRelative = "<t:{$deadlineUnix}:R>"; // Relative (in X hours)

    // Build facility list
    $facilityList = [];
    foreach ($facilities as $fac) {
        $facCode = is_array($fac) ? ($fac['code'] ?? $fac) : $fac;
        $facEmoji = is_array($fac) ? ($fac['emoji'] ?? ":$facCode:") : ":$facCode:";
        $facilityList[] = "{$facEmoji} {$facCode}";
    }
    $facilityStr = implode(' | ', $facilityList);

    // Build message
    $lines = [
        "```",
        "╔═══════════════════════════════════════════════════════════════════╗",
        "║           TMI COORDINATION PROPOSAL - APPROVAL REQUIRED           ║",
        "╠═══════════════════════════════════════════════════════════════════╣",
        "║             THIS IS A PROPOSAL ONLY - NOT YET ACTIVE              ║",
        "╚═══════════════════════════════════════════════════════════════════╝",
        "```",
        "",
        "**Proposed by:** {$userName}",
        "**Proposal ID:** #{$proposalId}",
        "",
        "**Approval Deadline:**",
        "› UTC: `{$deadlineUtcStr}`",
        "› Local: {$deadlineDiscordLong}",
        "› {$deadlineDiscordRelative}",
        "",
        "**Facilities Required to Approve:**",
        $facilityStr,
        "",
        "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━",
        "**Proposed TMI:**",
        "```",
        $ntmlText,
        "```",
        "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━",
        "",
        "**How to Respond:**",
        "› **Approve:** React with your facility's emoji",
        "› **Deny:** React with ❌",
        "",
        "**DCC has final authority to approve or deny any proposed TMI.**",
        "› DCC Approve: React with :DCC:",
        "› DCC Deny: React with ❌",
        "",
        "```",
        "╔═══════════════════════════════════════════════════════════════════╗",
        "║    UNANIMOUS APPROVAL REQUIRED BEFORE DEADLINE - OR DCC ACTION    ║",
        "╚═══════════════════════════════════════════════════════════════════╝",
        "```"
    ];

    return implode("\n", $lines);
}

/**
 * Activate an approved proposal
 */
function activateProposal($conn, $proposalId) {
    try {
        // Get proposal data
        $sql = "SELECT * FROM dbo.tmi_proposals WHERE proposal_id = :id";
        $stmt = $conn->prepare($sql);
        $stmt->execute([':id' => $proposalId]);
        $proposal = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$proposal) {
            return ['success' => false, 'error' => 'Proposal not found'];
        }

        $entryData = json_decode($proposal['entry_data_json'], true);
        $validFrom = $proposal['valid_from'];
        $validUntil = $proposal['valid_until'];

        // Determine if should be scheduled or activated immediately
        $now = new DateTime('now', new DateTimeZone('UTC'));
        $startTime = $validFrom ? new DateTime($validFrom) : null;

        $shouldSchedule = $startTime && $startTime > $now;
        $newStatus = $shouldSchedule ? 'SCHEDULED' : 'ACTIVATED';

        // Create the actual TMI entry via publish.php logic
        // For now, mark proposal as approved/scheduled
        $updateSql = "UPDATE dbo.tmi_proposals SET
                          status = :status,
                          activated_at = SYSUTCDATETIME(),
                          updated_at = SYSUTCDATETIME()
                      WHERE proposal_id = :prop_id";
        $updateStmt = $conn->prepare($updateSql);
        $updateStmt->execute([
            ':status' => $newStatus,
            ':prop_id' => $proposalId
        ]);

        // TODO: Call actual publish logic to create the TMI entry
        // For now, return success with status
        return [
            'success' => true,
            'status' => $newStatus,
            'scheduled' => $shouldSchedule
        ];

    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}
