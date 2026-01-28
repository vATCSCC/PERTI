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

// Coordination log channel ID (for audit trail)
define('DISCORD_COORDINATION_LOG_CHANNEL', '1466038410962796672');

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

// Known facility role patterns (Discord role names that indicate facility affiliation)
// Matches assets/js/facility-hierarchy.js ARTCCS constant
define('FACILITY_ROLE_PATTERNS', [
    // US ARTCCs (CONUS)
    'ZAB', 'ZAU', 'ZBW', 'ZDC', 'ZDV', 'ZFW', 'ZHU', 'ZID', 'ZJX', 'ZKC',
    'ZLA', 'ZLC', 'ZMA', 'ZME', 'ZMP', 'ZNY', 'ZOA', 'ZOB', 'ZSE', 'ZTL',
    // US Alaska/Hawaii/Oceanic
    'ZAN', 'ZHN', 'ZAK', 'ZAP', 'ZWY', 'ZHO', 'ZMO', 'ZUA',
    // Canadian FIRs (from facility-hierarchy.js)
    'CZEG', 'CZVR', 'CZWG', 'CZYZ', 'CZQM', 'CZQX', 'CZQO', 'CZUL'
]);

// Regional indicator emoji mapping for non-Nitro users (unique per facility)
// Uses :regional_indicator_{letter}: format - standard Unicode, no Nitro required
// Letters chosen to be intuitive where possible, alphabetical fallback for conflicts
define('FACILITY_REGIONAL_EMOJI_MAP', [
    // US ARTCCs
    'ZAB' => '🇦',  // A - Albuquerque
    'ZAN' => '🇬',  // G - anchoraGe (A taken, N reserved for NY)
    'ZAU' => '🇺',  // U - Chicago (zaU)
    'ZBW' => '🇧',  // B - Boston
    'ZDC' => '🇩',  // D - Washington DC
    'ZDV' => '🇻',  // V - DenVer (D taken)
    'ZFW' => '🇫',  // F - Fort Worth
    'ZHN' => '🇭',  // H - Honolulu
    'ZHU' => '🇼',  // W - Houston (H taken)
    'ZID' => '🇮',  // I - Indianapolis
    'ZJX' => '🇯',  // J - Jacksonville
    'ZKC' => '🇰',  // K - Kansas City
    'ZLA' => '🇱',  // L - Los Angeles
    'ZLC' => '🇨',  // C - Salt Lake City (L taken)
    'ZMA' => '🇲',  // M - Miami
    'ZME' => '🇪',  // E - mEmphis (M taken)
    'ZMP' => '🇵',  // P - minneaPolis (M taken)
    'ZNY' => '🇳',  // N - New York
    'ZOA' => '🇴',  // O - Oakland
    'ZOB' => '🇷',  // R - cleveland (O taken)
    'ZSE' => '🇸',  // S - Seattle
    'ZTL' => '🇹',  // T - aTlanta
    // Canadian FIRs (using number emojis)
    'CZEG' => '1️⃣',  // 1 - Edmonton
    'CZVR' => '2️⃣',  // 2 - Vancouver
    'CZWG' => '3️⃣',  // 3 - Winnipeg
    'CZYZ' => '4️⃣',  // 4 - Toronto
    'CZQM' => '5️⃣',  // 5 - Moncton
    'CZQX' => '6️⃣',  // 6 - Gander Domestic
    'CZQO' => '7️⃣',  // 7 - Gander Oceanic
    'CZUL' => '8️⃣',  // 8 - Montreal
]);

// Reverse mapping: emoji to facility code
define('REGIONAL_EMOJI_TO_FACILITY', array_flip(FACILITY_REGIONAL_EMOJI_MAP));

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

/**
 * Extract facility code from user's Discord roles
 * Checks for role names that match known facility patterns (ZDC, ZNY, etc.)
 */
function getFacilityFromRoles($roles) {
    if (empty($roles) || !is_array($roles)) {
        return null;
    }

    foreach ($roles as $role) {
        $roleUpper = strtoupper(trim($role));
        // Check if role matches a known facility pattern
        foreach (FACILITY_ROLE_PATTERNS as $pattern) {
            if ($roleUpper === $pattern || strpos($roleUpper, $pattern . ' ') === 0 || strpos($roleUpper, $pattern . '-') === 0) {
                return $pattern;
            }
        }
    }
    return null;
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
    case 'PATCH':
        handleExtendDeadline();
        break;
    case 'DELETE':
        handleRescindProposal();
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

        // Log the submission to coordination log
        logCoordinationActivity($conn, $proposalId, 'SUBMITTED', [
            'entry_type' => $entryType,
            'ctl_element' => $ctlElement,
            'created_by' => $userCid,
            'created_by_name' => $userName,
            'deadline' => $deadline->format('Y-m-d H:i') . 'Z',
            'facilities' => array_map(fn($f) => is_array($f) ? $f['code'] : $f, $facilities),
            'discord_posted' => isset($discordResult['id'])
        ]);

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
// GET: Get Proposal Status or List Pending
// =============================================================================

function handleGetProposalStatus() {
    $proposalId = $_GET['proposal_id'] ?? null;
    $messageId = $_GET['message_id'] ?? null;
    $listPending = isset($_GET['list']) && $_GET['list'] === 'pending';
    $listAll = isset($_GET['list']) && $_GET['list'] === 'all';

    // If listing pending proposals
    if ($listPending || $listAll) {
        handleListProposals($listAll);
        return;
    }

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

/**
 * List pending or all proposals
 */
function handleListProposals($includeAll = false) {
    $conn = getTmiConnection();
    if (!$conn) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Database connection failed']);
        return;
    }

    try {
        // Build query based on filter
        if ($includeAll) {
            $sql = "SELECT p.*,
                        (SELECT COUNT(*) FROM dbo.tmi_proposal_facilities f WHERE f.proposal_id = p.proposal_id) as facility_count,
                        (SELECT COUNT(*) FROM dbo.tmi_proposal_facilities f WHERE f.proposal_id = p.proposal_id AND f.approval_status = 'APPROVED') as approved_count
                    FROM dbo.tmi_proposals p
                    ORDER BY p.created_at DESC";
        } else {
            $sql = "SELECT p.*,
                        (SELECT COUNT(*) FROM dbo.tmi_proposal_facilities f WHERE f.proposal_id = p.proposal_id) as facility_count,
                        (SELECT COUNT(*) FROM dbo.tmi_proposal_facilities f WHERE f.proposal_id = p.proposal_id AND f.approval_status = 'APPROVED') as approved_count
                    FROM dbo.tmi_proposals p
                    WHERE p.status = 'PENDING'
                    ORDER BY p.approval_deadline_utc ASC";
        }

        $stmt = $conn->query($sql);
        $proposals = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Format proposals for response
        $result = [];
        foreach ($proposals as $prop) {
            // Parse entry data for display
            $entryData = json_decode($prop['entry_data_json'], true);

            $result[] = [
                'proposal_id' => (int)$prop['proposal_id'],
                'proposal_guid' => $prop['proposal_guid'],
                'status' => $prop['status'],
                'entry_type' => $prop['entry_type'],
                'requesting_facility' => $prop['requesting_facility'],
                'providing_facility' => $prop['providing_facility'],
                'ctl_element' => $prop['ctl_element'],
                'raw_text' => $prop['raw_text'],
                'approval_deadline_utc' => $prop['approval_deadline_utc'],
                'valid_from' => $prop['valid_from'],
                'valid_until' => $prop['valid_until'],
                'facility_count' => (int)$prop['facility_count'],
                'approved_count' => (int)$prop['approved_count'],
                'dcc_override' => (bool)$prop['dcc_override'],
                'dcc_override_action' => $prop['dcc_override_action'],
                'discord_message_id' => $prop['discord_message_id'],
                'created_by' => $prop['created_by'],
                'created_by_name' => $prop['created_by_name'],
                'created_at' => $prop['created_at'],
                'entry_data' => $entryData
            ];
        }

        echo json_encode([
            'success' => true,
            'proposals' => $result,
            'count' => count($result),
            'filter' => $includeAll ? 'all' : 'pending'
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

    // Web-based DCC override fields
    $webReactionType = $input['reaction_type'] ?? null;
    $webDccAction = $input['dcc_action'] ?? null;

    // Allow web-based DCC override (reaction_type=DCC_OVERRIDE with dcc_action)
    $isWebDccOverride = ($webReactionType === 'DCC_OVERRIDE' && in_array($webDccAction, ['APPROVE', 'DENY']));

    // Validation: need proposal/message ID and either emoji (Discord) or web DCC override
    if ((!$proposalId && !$messageId) || (!$emoji && !$isWebDccOverride) || !$discordUserId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Missing required fields: need proposal_id, discord_user_id, and either emoji or DCC_OVERRIDE action']);
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

        // Check for web-based DCC override first (from TMI Publisher UI)
        if ($isWebDccOverride) {
            $isDccOverride = true;
            $dccAction = $webDccAction;
            $reactionType = ($webDccAction === 'APPROVE') ? 'DCC_APPROVE' : 'DCC_DENY';
            $emoji = ($webDccAction === 'APPROVE') ? 'WEB_APPROVE' : 'WEB_DENY'; // Placeholder for logging
        } else {
            // Check for Discord emoji-based DCC override
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
        }

        // Check for facility approval/denial
        if (!$isDccOverride) {
            // Get facilities for this proposal
            $facSql = "SELECT * FROM dbo.tmi_proposal_facilities WHERE proposal_id = :id";
            $facStmt = $conn->prepare($facSql);
            $facStmt->execute([':id' => $proposalId]);
            $facilities = $facStmt->fetchAll(PDO::FETCH_ASSOC);
            $proposalFacilityCodes = array_column($facilities, 'facility_code');

            // Check if emoji matches a facility (custom emoji method - requires Nitro)
            foreach ($facilities as $fac) {
                if ($fac['approval_emoji'] && strpos($emoji, $fac['facility_code']) !== false) {
                    $reactionType = 'FACILITY_APPROVE';
                    $facilityCode = $fac['facility_code'];
                    break;
                }
            }

            // Check for regional indicator emoji (non-Nitro fallback)
            // Maps unique letters to facilities (e.g., 🇨 = ZDC, 🇾 = ZNY)
            if ($reactionType === 'OTHER') {
                $regionalFacility = REGIONAL_EMOJI_TO_FACILITY[$emoji] ?? null;
                if ($regionalFacility && in_array($regionalFacility, $proposalFacilityCodes)) {
                    $reactionType = 'FACILITY_APPROVE';
                    $facilityCode = $regionalFacility;
                }
            }

            // Check for deny emoji
            if ($emoji === DENY_EMOJI || $emoji === '❌') {
                $reactionType = 'FACILITY_DENY';
                // Determine which facility from user roles
                $userFacility = getFacilityFromRoles($userRoles);
                if ($userFacility && in_array($userFacility, $proposalFacilityCodes)) {
                    $facilityCode = $userFacility;
                }
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
// PATCH: Extend Proposal Deadline
// =============================================================================

function handleExtendDeadline() {
    $input = json_decode(file_get_contents('php://input'), true);

    $proposalId = $input['proposal_id'] ?? null;
    $newDeadlineUtc = $input['new_deadline_utc'] ?? null;
    $userCid = $input['user_cid'] ?? null;
    $userName = $input['user_name'] ?? 'Unknown';

    if (!$proposalId || !$newDeadlineUtc) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'proposal_id and new_deadline_utc are required']);
        return;
    }

    // Parse new deadline
    $newDeadline = new DateTime($newDeadlineUtc, new DateTimeZone('UTC'));
    if (!$newDeadline) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid deadline format']);
        return;
    }

    // New deadline must be in the future
    $now = new DateTime('now', new DateTimeZone('UTC'));
    if ($newDeadline <= $now) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'New deadline must be in the future']);
        return;
    }

    $conn = getTmiConnection();
    if (!$conn) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Database connection failed']);
        return;
    }

    try {
        // Verify proposal exists and is still pending
        $checkSql = "SELECT proposal_id, status, approval_deadline_utc, discord_message_id
                     FROM dbo.tmi_proposals
                     WHERE proposal_id = :prop_id";
        $checkStmt = $conn->prepare($checkSql);
        $checkStmt->execute([':prop_id' => $proposalId]);
        $proposal = $checkStmt->fetch(PDO::FETCH_ASSOC);

        if (!$proposal) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Proposal not found']);
            return;
        }

        if ($proposal['status'] !== 'PENDING') {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Can only extend deadline for PENDING proposals']);
            return;
        }

        $oldDeadline = $proposal['approval_deadline_utc'];

        // Update deadline
        $updateSql = "UPDATE dbo.tmi_proposals SET
                          approval_deadline_utc = :new_deadline,
                          updated_at = SYSUTCDATETIME()
                      WHERE proposal_id = :prop_id";
        $updateStmt = $conn->prepare($updateSql);
        $updateStmt->execute([
            ':new_deadline' => $newDeadline->format('Y-m-d H:i:s'),
            ':prop_id' => $proposalId
        ]);

        // Optionally update Discord message if it exists
        if (!empty($proposal['discord_message_id'])) {
            // TODO: Edit Discord message to show new deadline
            // This would require retrieving the original message content and updating it
        }

        echo json_encode([
            'success' => true,
            'proposal_id' => $proposalId,
            'old_deadline' => $oldDeadline,
            'new_deadline' => $newDeadline->format('Y-m-d H:i:s'),
            'extended_by' => $userName
        ]);

    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
    }
}

// =============================================================================
// DELETE: Rescind/Reopen Proposal (DCC Only)
// =============================================================================

function handleRescindProposal() {
    $input = json_decode(file_get_contents('php://input'), true);

    $proposalId = $input['proposal_id'] ?? null;
    $action = $input['action'] ?? 'REOPEN'; // REOPEN, CANCEL, CHANGE_TO_APPROVED, CHANGE_TO_DENIED
    $userCid = $input['user_cid'] ?? null;
    $userName = $input['user_name'] ?? 'DCC';
    $reason = $input['reason'] ?? null;

    if (!$proposalId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'proposal_id is required']);
        return;
    }

    $conn = getTmiConnection();
    if (!$conn) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Database connection failed']);
        return;
    }

    try {
        // Get current proposal
        $sql = "SELECT * FROM dbo.tmi_proposals WHERE proposal_id = :id";
        $stmt = $conn->prepare($sql);
        $stmt->execute([':id' => $proposalId]);
        $proposal = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$proposal) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Proposal not found']);
            return;
        }

        $oldStatus = $proposal['status'];
        $newStatus = null;

        switch ($action) {
            case 'REOPEN':
                // Reopen for coordination (back to PENDING)
                $newStatus = 'PENDING';
                // Reset facility approvals
                $resetSql = "UPDATE dbo.tmi_proposal_facilities SET
                                 approval_status = 'PENDING',
                                 reacted_at = NULL,
                                 reacted_by_user_id = NULL,
                                 reacted_by_username = NULL
                             WHERE proposal_id = :prop_id";
                $conn->prepare($resetSql)->execute([':prop_id' => $proposalId]);
                break;

            case 'CANCEL':
                $newStatus = 'CANCELLED';
                break;

            case 'CHANGE_TO_APPROVED':
                $newStatus = 'APPROVED';
                break;

            case 'CHANGE_TO_DENIED':
                $newStatus = 'DENIED';
                break;

            default:
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Invalid action: ' . $action]);
                return;
        }

        // Update proposal
        $updateSql = "UPDATE dbo.tmi_proposals SET
                          status = :status,
                          dcc_override = 1,
                          dcc_override_action = :action,
                          updated_at = SYSUTCDATETIME()
                      WHERE proposal_id = :prop_id";
        $updateStmt = $conn->prepare($updateSql);
        $updateStmt->execute([
            ':status' => $newStatus,
            ':action' => $action,
            ':prop_id' => $proposalId
        ]);

        // Log the action
        logCoordinationActivity($conn, $proposalId, 'DCC_' . $action, [
            'old_status' => $oldStatus,
            'new_status' => $newStatus,
            'user_cid' => $userCid,
            'user_name' => $userName,
            'reason' => $reason
        ]);

        echo json_encode([
            'success' => true,
            'proposal_id' => $proposalId,
            'old_status' => $oldStatus,
            'new_status' => $newStatus,
            'action' => $action
        ]);

    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
    }
}

// =============================================================================
// HELPER FUNCTIONS
// =============================================================================

/**
 * Build NTML text from entry data
 * Uses the 'preview' field if available (already formatted by JS), otherwise rebuilds
 */
function buildNtmlText($entry) {
    // Prefer the preview field which is already correctly formatted by the JS side
    if (!empty($entry['preview'])) {
        return $entry['preview'];
    }

    // Fallback: try to rebuild via TMIDiscord
    try {
        $tmiDiscord = new TMIDiscord();
        return $tmiDiscord->buildNTMLMessageFromEntry($entry);
    } catch (Exception $e) {
        // Last resort: return JSON representation
        return json_encode($entry);
    }
}

/**
 * Post proposal to Discord coordination channel
 */
function postProposalToDiscord($proposalId, $entry, $deadline, $facilities, $userName) {
    $logFile = __DIR__ . '/coordination_debug.log';
    $log = function($msg) use ($logFile) {
        file_put_contents($logFile, date('[Y-m-d H:i:s] ') . $msg . "\n", FILE_APPEND);
    };

    $log("=== Starting Discord post for proposal #{$proposalId} ===");
    $log("Channel ID: " . DISCORD_COORDINATION_CHANNEL);

    try {
        $discord = new DiscordAPI();
        $log("DiscordAPI instantiated");

        if (!$discord->isConfigured()) {
            $log("ERROR: Discord is not configured (no bot token)");
            return null;
        }
        $log("Discord is configured");

        // Build message content
        $content = formatProposalMessage($proposalId, $entry, $deadline, $facilities, $userName);
        $log("Message content built, length: " . strlen($content));

        // Post to coordination channel
        $log("Posting to channel: " . DISCORD_COORDINATION_CHANNEL);
        $result = $discord->createMessage(DISCORD_COORDINATION_CHANNEL, [
            'content' => $content
        ]);

        $log("createMessage result: " . json_encode($result));
        $log("Last HTTP code: " . $discord->getLastHttpCode());
        $log("Last error: " . ($discord->getLastError() ?? 'none'));

        if ($result && isset($result['id'])) {
            $log("SUCCESS - Message posted with ID: " . $result['id']);

            // Add initial reactions for each facility
            foreach ($facilities as $facility) {
                $facCode = is_array($facility) ? ($facility['code'] ?? $facility) : $facility;
                $facEmoji = is_array($facility) ? ($facility['emoji'] ?? null) : null;

                // Add custom emoji (for Nitro users)
                if ($facEmoji) {
                    $log("Adding custom emoji: {$facEmoji} for facility {$facCode}");
                    $discord->createReaction(DISCORD_COORDINATION_CHANNEL, $result['id'], $facEmoji);
                }

                // Add regional indicator emoji (for non-Nitro users)
                $regionalEmoji = FACILITY_REGIONAL_EMOJI_MAP[$facCode] ?? null;
                if ($regionalEmoji) {
                    $log("Adding regional indicator emoji: {$regionalEmoji} for facility {$facCode}");
                    $discord->createReaction(DISCORD_COORDINATION_CHANNEL, $result['id'], $regionalEmoji);
                }
            }

            // Add deny reaction
            $log("Adding deny reaction");
            $discord->createReaction(DISCORD_COORDINATION_CHANNEL, $result['id'], DENY_EMOJI);
        } else {
            $log("FAILED - No message ID returned");
            $log("Full result: " . print_r($result, true));
        }

        return $result;

    } catch (Exception $e) {
        $log("EXCEPTION: " . $e->getMessage());
        $log("Stack trace: " . $e->getTraceAsString());
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

    // Build facility list and emoji legend
    $facilityList = [];
    $emojiLegend = [];
    foreach ($facilities as $fac) {
        $facCode = is_array($fac) ? ($fac['code'] ?? $fac) : $fac;
        $facEmoji = is_array($fac) ? ($fac['emoji'] ?? ":$facCode:") : ":$facCode:";
        $facilityList[] = "{$facEmoji} {$facCode}";

        // Build emoji legend for non-Nitro users
        $regionalEmoji = FACILITY_REGIONAL_EMOJI_MAP[$facCode] ?? null;
        if ($regionalEmoji) {
            $emojiLegend[] = "{$regionalEmoji} = {$facCode}";
        }
    }
    $facilityStr = implode(' | ', $facilityList);
    $emojiLegendStr = implode(' | ', $emojiLegend);

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
        "› **Approve:** React with your facility's emoji (e.g., :ZDC:)",
        "› **Approve (no Nitro):** Use letter emoji: {$emojiLegendStr}",
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
 * Activate an approved proposal - creates TMI entry and posts to Discord
 */
function activateProposal($conn, $proposalId) {
    $logFile = __DIR__ . '/coordination_debug.log';
    $log = function($msg) use ($logFile) {
        file_put_contents($logFile, date('[Y-m-d H:i:s] ') . "[ACTIVATE] " . $msg . "\n", FILE_APPEND);
    };

    try {
        $log("Activating proposal #{$proposalId}");

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
        $rawText = $proposal['raw_text'];

        // Determine if should be scheduled or activated immediately
        $now = new DateTime('now', new DateTimeZone('UTC'));
        $startTime = $validFrom ? new DateTime($validFrom) : null;
        $shouldSchedule = $startTime && $startTime > $now;
        $newStatus = $shouldSchedule ? 'SCHEDULED' : 'ACTIVATED';

        $log("Status will be: {$newStatus}");

        // ===================================================
        // STEP 1: Create TMI entry in tmi_entries table
        // ===================================================
        $tmiEntryId = createTmiEntryFromProposal($conn, $proposal, $entryData, $rawText);
        $log("Created TMI entry: " . ($tmiEntryId ?: 'FAILED'));

        // ===================================================
        // STEP 2: Post to Discord production channels
        // ===================================================
        $discordResult = null;
        if ($tmiEntryId && !$shouldSchedule) {
            $discordResult = publishTmiToDiscord($proposal, $rawText, $tmiEntryId);
            $log("Discord publish result: " . json_encode($discordResult));

            // Update TMI entry with Discord info
            if ($discordResult && !empty($discordResult['message_id'])) {
                updateTmiDiscordInfo($conn, $tmiEntryId, $discordResult['message_id'], $discordResult['channel_id'] ?? null);
            }
        }

        // ===================================================
        // STEP 3: Update proposal status
        // ===================================================
        $updateSql = "UPDATE dbo.tmi_proposals SET
                          status = :status,
                          activated_at = SYSUTCDATETIME(),
                          tmi_entry_id = :entry_id,
                          updated_at = SYSUTCDATETIME()
                      WHERE proposal_id = :prop_id";
        $updateStmt = $conn->prepare($updateSql);
        $updateStmt->execute([
            ':status' => $newStatus,
            ':entry_id' => $tmiEntryId,
            ':prop_id' => $proposalId
        ]);

        // ===================================================
        // STEP 4: Log coordination activity
        // ===================================================
        logCoordinationActivity($conn, $proposalId, 'ACTIVATED', [
            'status' => $newStatus,
            'tmi_entry_id' => $tmiEntryId,
            'discord_posted' => !empty($discordResult['success'])
        ]);

        return [
            'success' => true,
            'status' => $newStatus,
            'scheduled' => $shouldSchedule,
            'tmi_entry_id' => $tmiEntryId,
            'discord' => $discordResult
        ];

    } catch (Exception $e) {
        $log("ERROR: " . $e->getMessage());
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Create TMI entry from approved proposal
 */
function createTmiEntryFromProposal($conn, $proposal, $entryData, $rawText) {
    $data = $entryData['data'] ?? [];
    $entryType = $entryData['entryType'] ?? 'UNKNOWN';

    $sql = "INSERT INTO dbo.tmi_entries (
                entry_type, determinant_code, ctl_element, element_type,
                raw_input, parsed_data,
                valid_from, valid_until,
                status, source,
                created_by, created_by_name
            ) OUTPUT INSERTED.entry_id
            VALUES (
                :entry_type, :determinant, :ctl_element, :element_type,
                :raw_input, :parsed_data,
                :valid_from, :valid_until,
                'ACTIVE', 'COORDINATION',
                :created_by, :created_by_name
            )";

    $stmt = $conn->prepare($sql);
    $stmt->execute([
        ':entry_type' => strtoupper($entryType),
        ':determinant' => strtoupper($entryType),
        ':ctl_element' => strtoupper($data['ctl_element'] ?? ''),
        ':element_type' => detectElementType($data['ctl_element'] ?? ''),
        ':raw_input' => $rawText,
        ':parsed_data' => json_encode($data),
        ':valid_from' => $proposal['valid_from'],
        ':valid_until' => $proposal['valid_until'],
        ':created_by' => $proposal['created_by'],
        ':created_by_name' => $proposal['created_by_name']
    ]);

    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    return $result ? $result['entry_id'] : null;
}

/**
 * Detect element type (airport/fix/airway)
 */
function detectElementType($element) {
    if (empty($element)) return null;
    $element = strtoupper($element);

    // Check for comma-separated list (multiple elements)
    if (strpos($element, ',') !== false) {
        return 'MULTI';
    }
    // Airport patterns: K***, P***, TJSJ, CYYZ
    if (preg_match('/^[KPCTY][A-Z]{2,3}$/', $element)) {
        return 'AIRPORT';
    }
    // 5-letter fix
    if (preg_match('/^[A-Z]{5}$/', $element)) {
        return 'FIX';
    }
    // Airway pattern (J*, V*, Q*, T*)
    if (preg_match('/^[JVQT]\d+$/', $element)) {
        return 'AIRWAY';
    }
    return 'OTHER';
}

/**
 * Publish approved TMI to Discord production channels
 */
function publishTmiToDiscord($proposal, $rawText, $tmiEntryId) {
    try {
        require_once __DIR__ . '/../../../load/discord/MultiDiscordAPI.php';

        $multiDiscord = new MultiDiscordAPI();
        if (!$multiDiscord->isConfigured()) {
            return ['success' => false, 'error' => 'Discord not configured'];
        }

        // Format message for production
        $message = "```\n{$rawText}\n```";

        // Post to production NTML channel(s)
        $result = $multiDiscord->postToChannel('vatcscc', 'ntml', ['content' => $message]);

        return $result;

    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Update TMI entry with Discord message info
 */
function updateTmiDiscordInfo($conn, $entryId, $messageId, $channelId) {
    $sql = "UPDATE dbo.tmi_entries SET
                discord_message_id = :message_id,
                discord_channel_id = :channel_id,
                discord_posted_at = SYSUTCDATETIME()
            WHERE entry_id = :entry_id";

    $stmt = $conn->prepare($sql);
    $stmt->execute([
        ':message_id' => $messageId,
        ':channel_id' => $channelId,
        ':entry_id' => $entryId
    ]);
}

/**
 * Log coordination activity to database and Discord #coordination-log channel
 */
function logCoordinationActivity($conn, $proposalId, $action, $details = []) {
    $timestamp = gmdate('Y-m-d H:i:s') . 'Z';

    try {
        // ===================================================
        // 1. Save to database
        // ===================================================
        $sql = "IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'tmi_coordination_log')
                CREATE TABLE dbo.tmi_coordination_log (
                    log_id INT IDENTITY(1,1) PRIMARY KEY,
                    proposal_id INT NOT NULL,
                    action VARCHAR(50) NOT NULL,
                    details NVARCHAR(MAX),
                    created_at DATETIME2 DEFAULT SYSUTCDATETIME()
                )";
        $conn->exec($sql);

        $insertSql = "INSERT INTO dbo.tmi_coordination_log (proposal_id, action, details)
                      VALUES (:prop_id, :action, :details)";
        $stmt = $conn->prepare($insertSql);
        $stmt->execute([
            ':prop_id' => $proposalId,
            ':action' => $action,
            ':details' => json_encode($details)
        ]);

        // ===================================================
        // 2. Post to Discord #coordination-log channel
        // ===================================================
        $logMessage = formatCoordinationLogMessage($proposalId, $action, $details, $timestamp);
        postToCoordinationLog($logMessage);

    } catch (Exception $e) {
        // Log error but don't fail the main operation
        error_log("Failed to log coordination activity: " . $e->getMessage());
    }
}

/**
 * Format a concise coordination log message for Discord
 */
function formatCoordinationLogMessage($proposalId, $action, $details, $timestamp) {
    $userName = $details['user_name'] ?? $details['created_by_name'] ?? 'System';
    $userCid = $details['user_cid'] ?? $details['created_by'] ?? '';

    // Build concise log entry
    $parts = ["`[{$timestamp}]`"];

    switch ($action) {
        case 'SUBMITTED':
            $entryType = $details['entry_type'] ?? 'TMI';
            $element = $details['ctl_element'] ?? '';
            $parts[] = "📝 **SUBMITTED** Proposal #{$proposalId}";
            $parts[] = "| {$entryType} {$element}";
            $parts[] = "| by {$userName}";
            if (!empty($details['deadline'])) {
                $parts[] = "| deadline: {$details['deadline']}";
            }
            break;

        case 'DCC_APPROVE':
        case 'DCC_APPROVED':
            $parts[] = "✅ **DCC APPROVED** Proposal #{$proposalId}";
            $parts[] = "| by {$userName}";
            break;

        case 'DCC_DENY':
        case 'DCC_DENIED':
            $parts[] = "❌ **DCC DENIED** Proposal #{$proposalId}";
            $parts[] = "| by {$userName}";
            break;

        case 'FACILITY_APPROVE':
            $facility = $details['facility'] ?? 'Unknown';
            $parts[] = "✅ **{$facility} APPROVED** Proposal #{$proposalId}";
            $parts[] = "| by {$userName}";
            break;

        case 'FACILITY_DENY':
            $facility = $details['facility'] ?? 'Unknown';
            $parts[] = "❌ **{$facility} DENIED** Proposal #{$proposalId}";
            $parts[] = "| by {$userName}";
            break;

        case 'ACTIVATED':
            $status = $details['status'] ?? 'ACTIVATED';
            $tmiId = $details['tmi_entry_id'] ?? '';
            $parts[] = "🚀 **{$status}** Proposal #{$proposalId}";
            if ($tmiId) $parts[] = "| TMI Entry #{$tmiId}";
            $parts[] = "| Discord: " . ($details['discord_posted'] ? '✅' : '❌');
            break;

        case 'DCC_REOPEN':
            $parts[] = "🔄 **REOPENED** Proposal #{$proposalId}";
            $parts[] = "| by {$userName}";
            if (!empty($details['reason'])) $parts[] = "| reason: {$details['reason']}";
            break;

        case 'DCC_CANCEL':
            $parts[] = "🗑️ **CANCELLED** Proposal #{$proposalId}";
            $parts[] = "| by {$userName}";
            break;

        case 'DEADLINE_EXTENDED':
            $parts[] = "⏰ **DEADLINE EXTENDED** Proposal #{$proposalId}";
            $parts[] = "| new: {$details['new_deadline']}";
            $parts[] = "| by {$userName}";
            break;

        default:
            $parts[] = "📋 **{$action}** Proposal #{$proposalId}";
            if ($userName) $parts[] = "| by {$userName}";
    }

    return implode(' ', $parts);
}

/**
 * Post message to Discord #coordination-log channel
 */
function postToCoordinationLog($message) {
    try {
        $discord = new DiscordAPI();
        if (!$discord->isConfigured()) {
            return;
        }

        $discord->createMessage(DISCORD_COORDINATION_LOG_CHANNEL, [
            'content' => $message
        ]);
    } catch (Exception $e) {
        error_log("Failed to post to coordination log: " . $e->getMessage());
    }
}
