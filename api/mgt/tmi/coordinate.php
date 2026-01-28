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

// Deny emoji (primary and alternate)
define('DENY_EMOJI', '❌');
define('DENY_EMOJI_ALT', '🚫');  // :no_entry: - alternate for non-Nitro

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

// Regional indicator emoji mapping for alternate approval method (unique per facility)
// Uses :regional_indicator_{letter}: format - standard Unicode emojis
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

// Fallback emojis for facilities without standard mappings
// Used when: (1) facility isn't an ARTCC/FIR, (2) parent ARTCC can't be determined
// Order matters - assigns sequentially for uniqueness within a proposal
define('FALLBACK_EMOJIS', [
    // Colored squares
    '🟥',  // red_square
    '🟧',  // orange_square
    '🟨',  // yellow_square
    '🟩',  // green_square
    '🟦',  // blue_square
    '🟪',  // purple_square
    '🟫',  // brown_square
    '⬛',  // black_large_square
    '⬜',  // white_large_square
    // Colored circles
    '🔴',  // red_circle
    '🟠',  // orange_circle
    '🟡',  // yellow_circle
    '🟢',  // green_circle
    '🔵',  // blue_circle
    '🟣',  // purple_circle
    '🟤',  // brown_circle
    '⚫',  // black_circle
    '⚪',  // white_circle
]);

// TRACON/Terminal → Parent ARTCC mapping
// When a provider isn't an ARTCC/FIR, their parent ARTCC is responsible for approval
define('TRACON_TO_ARTCC', [
    // Northeast
    'N90' => 'ZNY',   // New York TRACON
    'PCT' => 'ZDC',   // Potomac TRACON (DC area)
    'PHL' => 'ZNY',   // Philadelphia TRACON
    'A90' => 'ZBW',   // Boston TRACON
    // Southeast
    'A80' => 'ZTL',   // Atlanta TRACON
    'MIA' => 'ZMA',   // Miami TRACON
    'JAX' => 'ZJX',   // Jacksonville TRACON
    // Central
    'C90' => 'ZAU',   // Chicago TRACON
    'D10' => 'ZFW',   // Dallas/Fort Worth TRACON
    'I90' => 'ZHU',   // Houston TRACON
    'M98' => 'ZME',   // Memphis TRACON
    'D01' => 'ZDV',   // Denver TRACON
    'MCI' => 'ZKC',   // Kansas City TRACON
    'IND' => 'ZID',   // Indianapolis TRACON
    'MSP' => 'ZMP',   // Minneapolis TRACON
    // West
    'SCT' => 'ZLA',   // Southern California TRACON
    'NCT' => 'ZOA',   // Northern California TRACON
    'S46' => 'ZSE',   // Seattle TRACON
    'P50' => 'ZLA',   // Phoenix TRACON (under ZAB, but close to ZLA)
    'ABQ' => 'ZAB',   // Albuquerque TRACON
    'SLC' => 'ZLC',   // Salt Lake City TRACON
    // Honolulu
    'HCF' => 'ZHN',   // Honolulu Control Facility
]);

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

/**
 * Get the parent ARTCC for a facility code
 * - If already an ARTCC/FIR, returns itself
 * - If TRACON, returns mapped ARTCC
 * - If airport (K***, P***, etc.), attempts to determine from known mappings
 * @param string $facilityCode Facility code (ARTCC, TRACON, or airport)
 * @return string|null Parent ARTCC code or null if unknown
 */
function getParentArtcc($facilityCode) {
    if (empty($facilityCode)) return null;

    $facilityCode = strtoupper(trim($facilityCode));

    // If it's already an ARTCC/FIR, return itself
    if (in_array($facilityCode, FACILITY_ROLE_PATTERNS)) {
        return $facilityCode;
    }

    // Check TRACON mapping
    if (isset(TRACON_TO_ARTCC[$facilityCode])) {
        return TRACON_TO_ARTCC[$facilityCode];
    }

    // Common airport to ARTCC mappings
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

    if (isset($airportToArtcc[$facilityCode])) {
        return $airportToArtcc[$facilityCode];
    }

    // Try without K/C/P prefix for US/Canada airports
    $withK = 'K' . $facilityCode;
    $withC = 'C' . $facilityCode;
    if (isset($airportToArtcc[$withK])) return $airportToArtcc[$withK];
    if (isset($airportToArtcc[$withC])) return $airportToArtcc[$withC];

    return null;
}

/**
 * Check if an ARTCC is the parent of a given facility
 * @param string $artcc ARTCC code
 * @param string $facility Facility code (may be TRACON, airport, or ARTCC)
 * @return bool True if ARTCC is the parent of the facility
 */
function isArtccParentOf($artcc, $facility) {
    if (empty($artcc) || empty($facility)) return false;

    $artcc = strtoupper(trim($artcc));
    $facility = strtoupper(trim($facility));

    // Direct match
    if ($artcc === $facility) return true;

    // Check if facility's parent is this ARTCC
    $parentArtcc = getParentArtcc($facility);
    return $parentArtcc === $artcc;
}

/**
 * Get the appropriate emoji for a facility
 * Priority:
 *   1. If facility is an ARTCC/FIR with a mapped emoji, use it
 *   2. If facility has a parent ARTCC with a mapped emoji, use that
 *   3. Use a fallback emoji (colored square/circle)
 *
 * @param string $facilityCode Facility code
 * @param array &$usedEmojis Array of already-used emojis (passed by reference to track uniqueness)
 * @return array ['emoji' => string, 'type' => 'artcc'|'parent'|'fallback', 'parent' => string|null]
 */
function getEmojiForFacility($facilityCode, &$usedEmojis = []) {
    $facilityCode = strtoupper(trim($facilityCode));

    // 1. Check if facility itself has an emoji mapping (ARTCC/FIR)
    if (isset(FACILITY_REGIONAL_EMOJI_MAP[$facilityCode])) {
        $emoji = FACILITY_REGIONAL_EMOJI_MAP[$facilityCode];
        if (!in_array($emoji, $usedEmojis)) {
            $usedEmojis[] = $emoji;
            return ['emoji' => $emoji, 'type' => 'artcc', 'parent' => null];
        }
    }

    // 2. Check if facility has a parent ARTCC with an emoji
    $parentArtcc = getParentArtcc($facilityCode);
    if ($parentArtcc && isset(FACILITY_REGIONAL_EMOJI_MAP[$parentArtcc])) {
        $emoji = FACILITY_REGIONAL_EMOJI_MAP[$parentArtcc];
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

    // No emoji available (should never happen with enough fallbacks)
    return ['emoji' => '❓', 'type' => 'none', 'parent' => null];
}

// =============================================================================
// ROUTE HANDLING
// =============================================================================

$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'POST':
        // Check for publish action
        $postInput = json_decode(file_get_contents('php://input'), true);
        if (($postInput['action'] ?? '') === 'PUBLISH') {
            handlePublishApprovedProposal($postInput);
        } else {
            handleSubmitForCoordination();
        }
        break;
    case 'GET':
        handleGetProposalStatus();
        break;
    case 'PUT':
        handleProcessReaction();
        break;
    case 'PATCH':
        // Check action type: EDIT_PROPOSAL or extend deadline
        $patchInput = json_decode(file_get_contents('php://input'), true);
        if (($patchInput['action'] ?? '') === 'EDIT_PROPOSAL') {
            handleEditProposal($patchInput);
        } else {
            handleExtendDeadline();
        }
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
        $facilityCodes = [];
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
            $facilityCodes[] = strtoupper($facCode);
        }

        // ===================================================
        // CHECK FOR INTERNAL TMI (same responsible ARTCC)
        // If all providers share the same responsible ARTCC as the requester,
        // auto-approve immediately without coordination
        // ===================================================
        $isInternalTmi = false;
        $reqArtcc = getParentArtcc($requestingFacility);

        if ($reqArtcc) {
            // Check if ALL facility codes share the same responsible ARTCC
            $allSameArtcc = true;
            foreach ($facilityCodes as $facCode) {
                $facArtcc = getParentArtcc($facCode);
                if ($facArtcc !== $reqArtcc) {
                    $allSameArtcc = false;
                    break;
                }
            }
            $isInternalTmi = $allSameArtcc;
        }

        if ($isInternalTmi) {
            // Auto-approve all facilities
            $autoApproveSql = "UPDATE dbo.tmi_proposal_facilities SET
                                   approval_status = 'APPROVED',
                                   reacted_at = SYSUTCDATETIME(),
                                   reacted_by_user_id = 'AUTO',
                                   reacted_by_username = 'Internal TMI Auto-Approve'
                               WHERE proposal_id = :prop_id";
            $conn->prepare($autoApproveSql)->execute([':prop_id' => $proposalId]);

            $conn->commit();

            // Log auto-approval
            logCoordinationActivity($conn, $proposalId, 'AUTO_APPROVED', [
                'entry_type' => $entryType,
                'ctl_element' => $ctlElement,
                'created_by' => $userCid,
                'created_by_name' => $userName,
                'reason' => "Internal {$reqArtcc} TMI - all facilities under same ARTCC",
                'facilities' => $facilityCodes
            ]);

            // Activate the proposal immediately
            $activationResult = activateProposal($conn, $proposalId);

            echo json_encode([
                'success' => true,
                'proposal_id' => $proposalId,
                'proposal_guid' => $proposalGuid,
                'auto_approved' => true,
                'reason' => "Internal {$reqArtcc} TMI - no external coordination required",
                'activation' => $activationResult
            ]);
            return;
        }

        // ===================================================
        // EXTERNAL TMI - Requires coordination
        // Post to Discord coordination channel
        // ===================================================
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
            // Include both PENDING and APPROVED proposals
            // APPROVED proposals are ready for publication but not yet activated
            $sql = "SELECT p.*,
                        (SELECT COUNT(*) FROM dbo.tmi_proposal_facilities f WHERE f.proposal_id = p.proposal_id) as facility_count,
                        (SELECT COUNT(*) FROM dbo.tmi_proposal_facilities f WHERE f.proposal_id = p.proposal_id AND f.approval_status = 'APPROVED') as approved_count
                    FROM dbo.tmi_proposals p
                    WHERE p.status IN ('PENDING', 'APPROVED')
                    ORDER BY
                        CASE WHEN p.status = 'APPROVED' THEN 0 ELSE 1 END,
                        p.approval_deadline_utc ASC";
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

    // SECURITY: Web-based DCC override requires login (valid VATSIM CID)
    if ($isWebDccOverride) {
        // Check for valid CID - must be numeric and positive
        if (!$discordUserId || !is_numeric($discordUserId) || intval($discordUserId) <= 0) {
            http_response_code(401);
            echo json_encode(['success' => false, 'error' => 'Authentication required: You must be logged in to perform DCC override actions']);
            return;
        }
    }

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
                } elseif ($emoji === DENY_EMOJI || $emoji === DENY_EMOJI_ALT || $emoji === '❌' || $emoji === '🚫') {
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

            // Check for alternate emoji (regional indicator, number, or fallback)
            // Re-calculate emoji mapping for this proposal to match what was posted
            if ($reactionType === 'OTHER') {
                // Build the emoji -> facility mapping for this proposal
                $usedEmojis = [];
                $proposalEmojiMap = [];
                foreach ($proposalFacilityCodes as $facCode) {
                    $emojiInfo = getEmojiForFacility($facCode, $usedEmojis);
                    $proposalEmojiMap[$emojiInfo['emoji']] = [
                        'facility' => $facCode,
                        'type' => $emojiInfo['type'],
                        'parent' => $emojiInfo['parent']
                    ];
                }

                // Check if the reaction emoji matches any facility's alternate emoji
                if (isset($proposalEmojiMap[$emoji])) {
                    $matchedFacility = $proposalEmojiMap[$emoji]['facility'];
                    $reactionType = 'FACILITY_APPROVE';
                    $facilityCode = $matchedFacility;
                } else {
                    // Also check global ARTCC emoji -> child facility approval
                    // (e.g., ZDC reacting can approve for PCT even if PCT has a different emoji)
                    $regionalFacility = REGIONAL_EMOJI_TO_FACILITY[$emoji] ?? null;
                    if ($regionalFacility) {
                        foreach ($proposalFacilityCodes as $reqFacility) {
                            if (isArtccParentOf($regionalFacility, $reqFacility)) {
                                $reactionType = 'FACILITY_APPROVE';
                                $facilityCode = $reqFacility;
                                break;
                            }
                        }
                    }
                }
            }

            // Check for deny emoji (primary ❌ or alternate 🚫)
            if ($emoji === DENY_EMOJI || $emoji === DENY_EMOJI_ALT || $emoji === '❌' || $emoji === '🚫') {
                $reactionType = 'FACILITY_DENY';
                // Determine which facility from user roles
                $userFacility = getFacilityFromRoles($userRoles);
                if ($userFacility) {
                    // Direct match
                    if (in_array($userFacility, $proposalFacilityCodes)) {
                        $facilityCode = $userFacility;
                    } else {
                        // ARTCC denying for child facility
                        foreach ($proposalFacilityCodes as $reqFacility) {
                            if (isArtccParentOf($userFacility, $reqFacility)) {
                                $facilityCode = $reqFacility;
                                break;
                            }
                        }
                    }
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

            // Log DCC override action
            logCoordinationActivity($conn, $proposalId, 'DCC_' . $dccAction, [
                'user_cid' => $discordUserId,
                'user_name' => $discordUsername,
                'emoji' => $emoji,
                'via' => $isWebDccOverride ? 'web' : 'discord',
                'entry_type' => $proposal['entry_type'] ?? '',
                'ctl_element' => $proposal['ctl_element'] ?? ''
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

            // Log facility approval
            logCoordinationActivity($conn, $proposalId, 'FACILITY_APPROVE', [
                'facility' => $facilityCode,
                'user_cid' => $discordUserId,
                'user_name' => $discordUsername,
                'emoji' => $emoji,
                'entry_type' => $proposal['entry_type'] ?? '',
                'ctl_element' => $proposal['ctl_element'] ?? ''
            ]);
        } elseif ($facilityCode && $reactionType === 'FACILITY_DENY') {
            // Update facility denial status
            $updateFacSql = "UPDATE dbo.tmi_proposal_facilities SET
                                 approval_status = 'DENIED',
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

            // Log facility denial
            logCoordinationActivity($conn, $proposalId, 'FACILITY_DENY', [
                'facility' => $facilityCode,
                'user_cid' => $discordUserId,
                'user_name' => $discordUsername,
                'emoji' => $emoji,
                'entry_type' => $proposal['entry_type'] ?? '',
                'ctl_element' => $proposal['ctl_element'] ?? ''
            ]);
        }

        // Check if proposal should be approved/denied
        $checkSql = "EXEC dbo.sp_CheckProposalApproval @proposal_id = :prop_id";
        $checkStmt = $conn->prepare($checkSql);
        $checkStmt->execute([':prop_id' => $proposalId]);
        $result = $checkStmt->fetch(PDO::FETCH_ASSOC);

        // If approved, log it and notify - do NOT auto-activate
        // User must manually publish from the queue
        if ($result && $result['status'] === 'APPROVED') {
            // Log the approval
            logCoordinationActivity($conn, $proposalId, 'PROPOSAL_APPROVED', [
                'entry_type' => $proposal['entry_type'] ?? '',
                'ctl_element' => $proposal['ctl_element'] ?? '',
                'facilities' => array_column($proposal['facilities'] ?? [], 'facility_code')
            ]);
            $result['ready_for_publication'] = true;

            // Edit the Discord coordination message to indicate approval
            updateCoordinationMessageOnApproval($conn, $proposalId, $proposal);
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

        // Log the deadline extension
        logCoordinationActivity($conn, $proposalId, 'DEADLINE_EXTENDED', [
            'old_deadline' => $oldDeadline,
            'new_deadline' => $newDeadline->format('Y-m-d H:i:s'),
            'user_cid' => $userCid,
            'user_name' => $userName
        ]);

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
// POST: Publish Approved Proposal (manual activation from queue)
// =============================================================================

function handlePublishApprovedProposal($input) {
    $proposalId = $input['proposal_id'] ?? null;
    $userCid = $input['user_cid'] ?? null;
    $userName = $input['user_name'] ?? 'Unknown';

    if (!$proposalId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'proposal_id is required']);
        return;
    }

    // Security: Require login for publishing
    if (!$userCid || !is_numeric($userCid) || intval($userCid) <= 0) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Authentication required: You must be logged in to publish']);
        return;
    }

    $conn = getTmiConnection();
    if (!$conn) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Database connection failed']);
        return;
    }

    try {
        // Verify proposal exists and is APPROVED
        $sql = "SELECT * FROM dbo.tmi_proposals WHERE proposal_id = :id";
        $stmt = $conn->prepare($sql);
        $stmt->execute([':id' => $proposalId]);
        $proposal = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$proposal) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Proposal not found']);
            return;
        }

        if ($proposal['status'] !== 'APPROVED') {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'error' => 'Only APPROVED proposals can be published',
                'current_status' => $proposal['status']
            ]);
            return;
        }

        // Call the activation function
        $activationResult = activateProposal($conn, $proposalId);

        if ($activationResult['success'] ?? false) {
            // Log the manual publication
            logCoordinationActivity($conn, $proposalId, 'PUBLISHED', [
                'entry_type' => $proposal['entry_type'] ?? '',
                'ctl_element' => $proposal['ctl_element'] ?? '',
                'tmi_entry_id' => $activationResult['tmi_entry_id'] ?? null,
                'user_cid' => $userCid,
                'user_name' => $userName
            ]);

            echo json_encode([
                'success' => true,
                'proposal_id' => $proposalId,
                'activation' => $activationResult,
                'published_by' => $userName
            ]);
        } else {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'error' => 'Activation failed: ' . ($activationResult['error'] ?? 'Unknown error'),
                'activation_result' => $activationResult
            ]);
        }

    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
    }
}

// =============================================================================
// PATCH: Edit Proposal (clears approvals, restarts coordination)
// =============================================================================

function handleEditProposal($input) {
    $proposalId = $input['proposal_id'] ?? null;
    $updates = $input['updates'] ?? [];
    $userCid = $input['user_cid'] ?? null;
    $userName = $input['user_name'] ?? 'Unknown';

    if (!$proposalId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'proposal_id is required']);
        return;
    }

    if (empty($updates)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'No updates provided']);
        return;
    }

    // Security: Require login for editing
    if (!$userCid || !is_numeric($userCid) || intval($userCid) <= 0) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Authentication required: You must be logged in to edit proposals']);
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

        // Only allow editing PENDING or APPROVED proposals
        // Editing APPROVED proposals will reset them to PENDING and restart coordination
        if (!in_array($proposal['status'], ['PENDING', 'APPROVED'])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Can only edit PENDING or APPROVED proposals']);
            return;
        }

        $wasApproved = ($proposal['status'] === 'APPROVED');

        // Store old values for diff display
        $oldData = [
            'ctl_element' => $proposal['ctl_element'],
            'requesting_facility' => $proposal['requesting_facility'],
            'providing_facility' => $proposal['providing_facility'],
            'valid_from' => $proposal['valid_from'],
            'valid_until' => $proposal['valid_until'],
            'raw_text' => $proposal['raw_text']
        ];

        // Build update query
        $setClauses = ['updated_at = SYSUTCDATETIME()'];
        $params = [':prop_id' => $proposalId];

        if (isset($updates['ctl_element'])) {
            $setClauses[] = 'ctl_element = :ctl_element';
            $params[':ctl_element'] = $updates['ctl_element'];
        }

        if (isset($updates['requesting_facility'])) {
            $setClauses[] = 'requesting_facility = :req_fac';
            $params[':req_fac'] = $updates['requesting_facility'];
        }

        if (isset($updates['providing_facility'])) {
            $setClauses[] = 'providing_facility = :prov_fac';
            $params[':prov_fac'] = $updates['providing_facility'];
        }

        if (isset($updates['valid_from']) && $updates['valid_from']) {
            $setClauses[] = 'valid_from = :valid_from';
            // Parse datetime-local format (2026-01-28T03:45) and convert to SQL Server format
            $validFromDt = new DateTime($updates['valid_from'], new DateTimeZone('UTC'));
            $params[':valid_from'] = $validFromDt->format('Y-m-d H:i:s');
        }

        if (isset($updates['valid_until']) && $updates['valid_until']) {
            $setClauses[] = 'valid_until = :valid_until';
            // Parse datetime-local format (2026-01-28T03:45) and convert to SQL Server format
            $validUntilDt = new DateTime($updates['valid_until'], new DateTimeZone('UTC'));
            $params[':valid_until'] = $validUntilDt->format('Y-m-d H:i:s');
        }

        // Update entry_data_json with new values
        $entryData = json_decode($proposal['entry_data_json'], true) ?: [];
        if (isset($updates['restriction_value'])) {
            $entryData['restriction_value'] = $updates['restriction_value'];
            $entryData['value'] = $updates['restriction_value'];
        }
        if (isset($updates['restriction_unit'])) {
            $entryData['restriction_unit'] = $updates['restriction_unit'];
            $entryData['unit'] = $updates['restriction_unit'];
        }
        $setClauses[] = 'entry_data_json = :entry_data';
        $params[':entry_data'] = json_encode($entryData);

        // Auto-update raw_text if restriction value or unit changed
        $rawText = $updates['raw_text'] ?? $proposal['raw_text'] ?? '';
        $oldValue = $entryData['restriction_value'] ?? $entryData['value'] ?? null;
        $oldUnit = $entryData['restriction_unit'] ?? $entryData['unit'] ?? null;

        // Get original values from the proposal before edit
        $origEntryData = json_decode($proposal['entry_data_json'], true) ?: [];
        $origValue = $origEntryData['restriction_value'] ?? $origEntryData['value'] ?? null;
        $origUnit = $origEntryData['restriction_unit'] ?? $origEntryData['unit'] ?? null;
        $newValue = $updates['restriction_value'] ?? $origValue;
        $newUnit = $updates['restriction_unit'] ?? $origUnit;

        // If value or unit changed, update the raw_text pattern
        if (($newValue !== $origValue || $newUnit !== $origUnit) && $rawText) {
            // Pattern matches: 4MINIT, 20MIT, etc.
            $rawText = preg_replace('/(\d+)(MIT|MINIT)/i', $newValue . $newUnit, $rawText);
        }

        $setClauses[] = 'raw_text = :raw_text';
        $params[':raw_text'] = $rawText;

        // Store edit metadata (only if column exists)
        try {
            $colCheck = $conn->query("SELECT TOP 1 edit_history FROM dbo.tmi_proposals WHERE 1=0");
            // Column exists, add edit history
            $editHistory = json_decode($proposal['edit_history'] ?? '[]', true) ?: [];
            $editHistory[] = [
                'edited_at' => gmdate('Y-m-d H:i:s') . 'Z',
                'edited_by_cid' => $userCid,
                'edited_by_name' => $userName,
                'reason' => $updates['edit_reason'] ?? 'No reason provided',
                'old_values' => $oldData
            ];
            $setClauses[] = 'edit_history = :edit_history';
            $params[':edit_history'] = json_encode($editHistory);
        } catch (Exception $e) {
            // Column doesn't exist, that's fine - skip it
        }

        // Update proposal
        $updateSql = "UPDATE dbo.tmi_proposals SET " . implode(', ', $setClauses) . " WHERE proposal_id = :prop_id";
        $updateStmt = $conn->prepare($updateSql);
        $updateStmt->execute($params);

        // Clear all facility approvals (restart coordination)
        $resetSql = "UPDATE dbo.tmi_proposal_facilities SET
                         approval_status = 'PENDING',
                         reacted_at = NULL,
                         reacted_by_user_id = NULL,
                         reacted_by_username = NULL
                     WHERE proposal_id = :prop_id";
        $conn->prepare($resetSql)->execute([':prop_id' => $proposalId]);

        // Reset proposal status to PENDING (in case it was APPROVED)
        $resetStatusSql = "UPDATE dbo.tmi_proposals SET status = 'PENDING' WHERE proposal_id = :prop_id";
        $conn->prepare($resetStatusSql)->execute([':prop_id' => $proposalId]);

        // Log the edit action
        logCoordinationActivity($conn, $proposalId, 'PROPOSAL_EDITED', [
            'user_cid' => $userCid,
            'user_name' => $userName,
            'reason' => $updates['edit_reason'] ?? 'No reason provided',
            'old_values' => $oldData,
            'new_values' => $updates,
            'entry_type' => $proposal['entry_type'] ?? '',
            'ctl_element' => $proposal['ctl_element'] ?? ''
        ]);

        // Re-post to Discord coordination channel (restart coordination)
        // This posts a NEW message marked as EDITED for re-approval
        $facSql = "SELECT facility_code FROM dbo.tmi_proposal_facilities WHERE proposal_id = :id";
        $facStmt = $conn->prepare($facSql);
        $facStmt->execute([':id' => $proposalId]);
        $facilityCodes = $facStmt->fetchAll(PDO::FETCH_COLUMN);
        $facilityList = array_map(function($f) { return ['code' => $f]; }, $facilityCodes);

        // Get updated entry data
        $updatedEntryData = json_decode($params[':entry_data'], true) ?: $entryData;

        // Set new deadline (6 hours from now)
        $newDeadline = new DateTime('now', new DateTimeZone('UTC'));
        $newDeadline->modify('+6 hours');

        // Post new coordination message marked as EDITED
        $discordResult = postProposalToDiscord(
            $proposalId,
            $updatedEntryData,
            $newDeadline,
            $facilityList,
            $userName . ' (EDITED)'
        );

        // Update proposal with new Discord message ID and deadline
        if ($discordResult && isset($discordResult['id'])) {
            $updateDiscordSql = "UPDATE dbo.tmi_proposals SET
                                     discord_message_id = :msg_id,
                                     approval_deadline_utc = :deadline
                                 WHERE proposal_id = :prop_id";
            $conn->prepare($updateDiscordSql)->execute([
                ':msg_id' => $discordResult['id'],
                ':deadline' => $newDeadline->format('Y-m-d H:i:s'),
                ':prop_id' => $proposalId
            ]);
        }

        echo json_encode([
            'success' => true,
            'proposal_id' => $proposalId,
            'message' => 'Proposal updated and coordination restarted',
            'approvals_cleared' => true,
            'new_discord_message' => isset($discordResult['id'])
        ]);

    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
    }
}

/**
 * Update Discord message to show proposal was edited
 */
function updateProposalDiscordMessage($proposalId, $conn, $oldValues = null, $newValues = null, $editReason = null, $editedBy = null) {
    try {
        // Get proposal and facilities
        $sql = "SELECT * FROM dbo.tmi_proposals WHERE proposal_id = :id";
        $stmt = $conn->prepare($sql);
        $stmt->execute([':id' => $proposalId]);
        $proposal = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$proposal || empty($proposal['discord_message_id'])) {
            return;
        }

        $facSql = "SELECT * FROM dbo.tmi_proposal_facilities WHERE proposal_id = :id";
        $facStmt = $conn->prepare($facSql);
        $facStmt->execute([':id' => $proposalId]);
        $facilities = $facStmt->fetchAll(PDO::FETCH_ASSOC);

        // Build updated message
        $entryData = json_decode($proposal['entry_data_json'], true) ?: [];
        $deadline = new DateTime($proposal['approval_deadline_utc'], new DateTimeZone('UTC'));
        $facilityList = array_map(function($f) { return ['code' => $f['facility_code']]; }, $facilities);

        // Format with EDITED marker
        $message = formatProposalMessage(
            $proposalId,
            $entryData,
            $deadline,
            $facilityList,
            $proposal['created_by_name'] ?? 'Unknown'
        );

        // Build edit summary section
        $editSummary = [];
        $editSummary[] = "```diff";
        $editSummary[] = "- ⚠️ THIS PROPOSAL HAS BEEN EDITED";
        $editSummary[] = "- All previous approvals cleared - please re-review";
        $editSummary[] = "```";

        // Show what changed if we have the values
        if ($oldValues && $newValues) {
            $changes = [];

            // Compare key fields
            $fieldLabels = [
                'ctl_element' => 'Control Element',
                'requesting_facility' => 'Requesting Facility',
                'providing_facility' => 'Providing Facility',
                'valid_from' => 'Valid From',
                'valid_until' => 'Valid Until',
                'restriction_value' => 'Restriction Value',
                'raw_text' => 'Restriction Text'
            ];

            foreach ($fieldLabels as $field => $label) {
                $oldVal = $oldValues[$field] ?? '';
                $newVal = $newValues[$field] ?? '';

                // Normalize for comparison
                $oldNorm = is_string($oldVal) ? trim($oldVal) : $oldVal;
                $newNorm = is_string($newVal) ? trim($newVal) : $newVal;

                if ($oldNorm !== $newNorm && ($oldNorm || $newNorm)) {
                    // Truncate long values for display
                    $oldDisplay = strlen($oldNorm) > 50 ? substr($oldNorm, 0, 47) . '...' : $oldNorm;
                    $newDisplay = strlen($newNorm) > 50 ? substr($newNorm, 0, 47) . '...' : $newNorm;

                    $changes[] = "**{$label}:** `{$oldDisplay}` → `{$newDisplay}`";
                }
            }

            if (!empty($changes)) {
                $editSummary[] = "";
                $editSummary[] = "**📝 Changes Made:**";
                foreach ($changes as $change) {
                    $editSummary[] = "› " . $change;
                }
            }
        }

        // Add edit metadata
        if ($editedBy || $editReason) {
            $editSummary[] = "";
            if ($editedBy) $editSummary[] = "**Edited by:** {$editedBy}";
            if ($editReason) $editSummary[] = "**Reason:** {$editReason}";
        }

        $editSummary[] = "";
        $editSummary[] = "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━";

        // Prepend edit summary to message
        $message = implode("\n", $editSummary) . "\n" . $message;

        // Update Discord message
        $discord = new DiscordAPI();
        if ($discord->isConfigured()) {
            $discord->editMessage(DISCORD_COORDINATION_CHANNEL, $proposal['discord_message_id'], [
                'content' => $message
            ]);
        }
    } catch (Exception $e) {
        error_log("Failed to update Discord message for edited proposal: " . $e->getMessage());
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

                // Re-post to Discord coordination channel
                $entryData = json_decode($proposal['entry_data_json'], true) ?: [];
                $facSql = "SELECT facility_code FROM dbo.tmi_proposal_facilities WHERE proposal_id = :id";
                $facStmt = $conn->prepare($facSql);
                $facStmt->execute([':id' => $proposalId]);
                $facilityCodes = $facStmt->fetchAll(PDO::FETCH_COLUMN);
                $facilityList = array_map(function($f) { return ['code' => $f]; }, $facilityCodes);

                // Set new deadline (current time + original deadline duration, or 6 hours default)
                $newDeadline = new DateTime('now', new DateTimeZone('UTC'));
                $newDeadline->modify('+6 hours');

                // Post new coordination message
                $discordResult = postProposalToDiscord(
                    $proposalId,
                    $entryData,
                    $newDeadline,
                    $facilityList,
                    $userName . ' (REOPENED)'
                );

                // Update proposal with new deadline and Discord message ID
                if ($discordResult && isset($discordResult['id'])) {
                    $updateDiscordSql = "UPDATE dbo.tmi_proposals SET
                                             discord_message_id = :msg_id,
                                             approval_deadline_utc = :deadline
                                         WHERE proposal_id = :prop_id";
                    $conn->prepare($updateDiscordSql)->execute([
                        ':msg_id' => $discordResult['id'],
                        ':deadline' => $newDeadline->format('Y-m-d H:i:s'),
                        ':prop_id' => $proposalId
                    ]);
                }
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
            'reason' => $reason,
            'entry_type' => $proposal['entry_type'] ?? '',
            'ctl_element' => $proposal['ctl_element'] ?? '',
            'via' => 'web'
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
 * Update Discord coordination message when proposal is approved
 * Adds approval banner with timestamp
 */
function updateCoordinationMessageOnApproval($conn, $proposalId, $proposal) {
    try {
        // Get the original Discord message ID
        $discordMessageId = $proposal['discord_message_id'] ?? null;
        if (!$discordMessageId) {
            return; // No Discord message to update
        }

        // Build approval banner with timestamps
        $utcTime = gmdate('Y-m-d H:i:s') . 'Z';
        $unixTime = time();
        $discordLong = "<t:{$unixTime}:f>";
        $discordRelative = "<t:{$unixTime}:R>";

        $approvalBanner = [
            "╔════════════════════════════════════════════════════════════════════╗",
            "║  ✅  **PROPOSAL APPROVED** - All facilities have approved          ║",
            "║      Approved: `{$utcTime}` {$discordLong} ({$discordRelative})    ║",
            "║      Ready for publication in TMI Publisher queue                  ║",
            "╚════════════════════════════════════════════════════════════════════╝",
            ""
        ];

        // Get the original message content
        $originalContent = $proposal['raw_text'] ?? '';
        if (empty($originalContent)) {
            // Try to get from entry_data_json
            $entryData = json_decode($proposal['entry_data_json'] ?? '{}', true);
            $originalContent = formatEntryForDiscord($entryData);
        }

        // Combine approval banner with original content
        $newContent = implode("\n", $approvalBanner) . "\n" . $originalContent;

        // Update Discord message
        $discord = new DiscordAPI();
        if ($discord->isConfigured()) {
            $discord->editMessage(DISCORD_COORDINATION_CHANNEL, $discordMessageId, [
                'content' => $newContent
            ]);
        }
    } catch (Exception $e) {
        error_log("Failed to update Discord message on approval: " . $e->getMessage());
    }
}

/**
 * Post proposal to Discord coordination channel and create a thread
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

            // Create thread from the message
            $threadTitle = buildCoordinationThreadTitle($proposalId, $entry, $deadline, $facilities);
            $log("Creating thread with title: " . $threadTitle);

            $threadResult = $discord->createThreadFromMessage(
                DISCORD_COORDINATION_CHANNEL,
                $result['id'],
                $threadTitle,
                1440 // Auto-archive after 24 hours of inactivity
            );

            if ($threadResult && isset($threadResult['id'])) {
                $log("Thread created with ID: " . $threadResult['id']);
                $result['thread_id'] = $threadResult['id'];
            } else {
                $log("Thread creation failed: " . ($discord->getLastError() ?? 'unknown error'));
            }

            // Track used emojis to ensure uniqueness
            $usedEmojis = [];

            // Add initial reactions for each facility
            foreach ($facilities as $facility) {
                $facCode = is_array($facility) ? ($facility['code'] ?? $facility) : $facility;
                $facCode = strtoupper(trim($facCode));
                $facEmoji = is_array($facility) ? ($facility['emoji'] ?? null) : null;

                // Add custom emoji (for Nitro users)
                if ($facEmoji) {
                    $log("Adding custom emoji: {$facEmoji} for facility {$facCode}");
                    $discord->createReaction(DISCORD_COORDINATION_CHANNEL, $result['id'], $facEmoji);
                }

                // Add alternate emoji (ARTCC, parent ARTCC, or fallback)
                $emojiInfo = getEmojiForFacility($facCode, $usedEmojis);
                $altEmoji = $emojiInfo['emoji'];
                $emojiType = $emojiInfo['type'];

                $log("Adding alternate emoji: {$altEmoji} for facility {$facCode} (type: {$emojiType})");
                $discord->createReaction(DISCORD_COORDINATION_CHANNEL, $result['id'], $altEmoji);
            }

            // Add deny reactions (primary and alternate)
            $log("Adding deny reactions");
            $discord->createReaction(DISCORD_COORDINATION_CHANNEL, $result['id'], DENY_EMOJI);
            $discord->createReaction(DISCORD_COORDINATION_CHANNEL, $result['id'], DENY_EMOJI_ALT);
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
 * Build thread title for coordination
 * Format: "TMI Coordination | FROM {requestors} | TO {providers} | FOR {entry type} | PERIOD {valid period} | DUE {due date/time} | #{TMI ID}"
 */
function buildCoordinationThreadTitle($proposalId, $entry, $deadline, $facilities) {
    $data = $entry['data'] ?? [];
    $entryType = strtoupper($entry['entryType'] ?? 'TMI');

    // Get requestor (requesting facility)
    $requestor = strtoupper($data['req_facility'] ?? $data['requesting_facility'] ?? 'DCC');

    // Get providers (facilities that need to approve)
    $providerCodes = [];
    foreach ($facilities as $fac) {
        $facCode = is_array($fac) ? ($fac['code'] ?? $fac) : $fac;
        $providerCodes[] = strtoupper(trim($facCode));
    }
    $providers = implode(',', $providerCodes);

    // Get valid period
    $validFrom = $data['valid_from'] ?? $data['validFrom'] ?? null;
    $validUntil = $data['valid_until'] ?? $data['validUntil'] ?? null;

    $period = '';
    if ($validFrom) {
        try {
            $fromDt = new DateTime($validFrom);
            $period = $fromDt->format('Hi') . 'Z';
            if ($validUntil) {
                $untilDt = new DateTime($validUntil);
                $period .= '-' . $untilDt->format('Hi') . 'Z';
            }
        } catch (Exception $e) {
            $period = 'TBD';
        }
    } else {
        $period = 'TBD';
    }

    // Get due date/time
    $dueStr = $deadline->format('Hi') . 'Z';

    // Build title (max 100 characters for Discord thread names)
    // Format: "TMI Coord | FROM {req} | TO {prov} | {type} | {period} | DUE {due} | #{id}"
    $title = "TMI Coord | FROM {$requestor} | TO {$providers} | {$entryType} | {$period} | DUE {$dueStr} | #{$proposalId}";

    // Truncate if needed (Discord limit is 100 chars)
    if (strlen($title) > 100) {
        // Shorten version: "TMI | {req}→{prov} | {type} | DUE {due} | #{id}"
        $title = "TMI | {$requestor}→{$providers} | {$entryType} | DUE {$dueStr} | #{$proposalId}";
    }
    if (strlen($title) > 100) {
        // Even shorter: "TMI | {type} | #{id}"
        $title = "TMI Coordination | {$entryType} | #{$proposalId}";
    }

    return substr($title, 0, 100);
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

    // Build facility list with both primary and alternate emojis
    // Track used emojis to ensure uniqueness across facilities in this proposal
    $usedEmojis = [];
    $facilityApprovalList = []; // Detailed list with both emojis
    $facilityEmojiMap = []; // Track emoji -> facility for this proposal

    foreach ($facilities as $fac) {
        $facCode = is_array($fac) ? ($fac['code'] ?? $fac) : $fac;
        $facCode = strtoupper(trim($facCode));
        $primaryEmoji = is_array($fac) ? ($fac['emoji'] ?? ":$facCode:") : ":$facCode:";

        // Get appropriate alternate emoji (ARTCC, parent ARTCC, or fallback)
        $emojiInfo = getEmojiForFacility($facCode, $usedEmojis);
        $altEmoji = $emojiInfo['emoji'];

        // Build detailed facility entry with both emojis
        if ($emojiInfo['type'] === 'parent' && $emojiInfo['parent']) {
            // Show parent ARTCC relationship
            $facilityApprovalList[] = "› **{$facCode}**: {$primaryEmoji} (primary) or {$altEmoji} ({$emojiInfo['parent']})";
        } else {
            $facilityApprovalList[] = "› **{$facCode}**: {$primaryEmoji} (primary) or {$altEmoji} (alt)";
        }

        // Track this mapping for reaction processing
        $facilityEmojiMap[$altEmoji] = $facCode;
    }
    $facilityApprovalStr = implode("\n", $facilityApprovalList);

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
        "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━",
        "**Proposed TMI:**",
        "```",
        $ntmlText,
        "```",
        "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━",
        "",
        "**Facilities Required to Approve (react with primary or alt emoji):**",
        $facilityApprovalStr,
        "",
        "**How to Respond:**",
        "› **Deny:** React with ❌ or 🚫",
        "› **DCC Override:** React with :DCC: to approve or ❌ to deny",
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
                          activated_entry_id = :entry_id,
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
            'discord_posted' => !empty($discordResult['success']),
            'entry_type' => $proposal['entry_type'] ?? '',
            'ctl_element' => $proposal['ctl_element'] ?? ''
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
                entry_type, determinant_code, protocol_type, ctl_element, element_type,
                raw_input, parsed_data,
                valid_from, valid_until,
                status, source_type,
                created_by, created_by_name
            ) OUTPUT INSERTED.entry_id
            VALUES (
                :entry_type, :determinant, 1, :ctl_element, :element_type,
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
 * Includes UTC timestamp + Discord long format (:f) + relative format (:R)
 */
function formatCoordinationLogMessage($proposalId, $action, $details, $timestamp) {
    $userName = $details['user_name'] ?? $details['created_by_name'] ?? 'System';
    $userCid = $details['user_cid'] ?? $details['created_by'] ?? '';
    $via = isset($details['via']) ? " ({$details['via']})" : '';

    // Get Unix timestamp for Discord formatting
    $unixTime = time();

    // Build timestamp section: UTC + Discord :f (long) + Discord :R (relative)
    $discordLong = "<t:{$unixTime}:f>";      // e.g., "January 28, 2026 1:36 PM"
    $discordRelative = "<t:{$unixTime}:R>";  // e.g., "2 minutes ago"

    // Build concise log entry
    $parts = ["`[{$timestamp}]` {$discordLong} ({$discordRelative})"];

    switch ($action) {
        case 'SUBMITTED':
            $entryType = $details['entry_type'] ?? 'TMI';
            $element = $details['ctl_element'] ?? '';
            $facilities = $details['facilities'] ?? '';
            $parts[] = "📝 **SUBMITTED** Prop #{$proposalId}";
            $parts[] = "| {$entryType}";
            if ($element) $parts[] = "| {$element}";
            if ($facilities) $parts[] = "| To: {$facilities}";
            $parts[] = "| by {$userName}";
            break;

        case 'DCC_APPROVE':
        case 'DCC_APPROVED':
            $entryType = $details['entry_type'] ?? '';
            $element = $details['ctl_element'] ?? '';
            $parts[] = "⚡ **DCC OVERRIDE APPROVED** Prop #{$proposalId}";
            if ($entryType) $parts[] = "| {$entryType}";
            if ($element) $parts[] = "| {$element}";
            $parts[] = "| by {$userName}{$via}";
            break;

        case 'DCC_DENY':
        case 'DCC_DENIED':
            $entryType = $details['entry_type'] ?? '';
            $element = $details['ctl_element'] ?? '';
            $parts[] = "⚡ **DCC OVERRIDE DENIED** Prop #{$proposalId}";
            if ($entryType) $parts[] = "| {$entryType}";
            if ($element) $parts[] = "| {$element}";
            $parts[] = "| by {$userName}{$via}";
            break;

        case 'FACILITY_APPROVE':
            $facility = $details['facility'] ?? 'Unknown';
            $entryType = $details['entry_type'] ?? '';
            $element = $details['ctl_element'] ?? '';
            $emoji = $details['emoji'] ?? '';
            $parts[] = "✅ **{$facility} APPROVED** Prop #{$proposalId}";
            if ($entryType) $parts[] = "| {$entryType}";
            if ($element) $parts[] = "| {$element}";
            if ($emoji) $parts[] = "| via {$emoji}";
            $parts[] = "| by {$userName}";
            break;

        case 'FACILITY_DENY':
            $facility = $details['facility'] ?? 'Unknown';
            $entryType = $details['entry_type'] ?? '';
            $element = $details['ctl_element'] ?? '';
            $emoji = $details['emoji'] ?? '';
            $parts[] = "❌ **{$facility} DENIED** Prop #{$proposalId}";
            if ($entryType) $parts[] = "| {$entryType}";
            if ($element) $parts[] = "| {$element}";
            if ($emoji) $parts[] = "| via {$emoji}";
            $parts[] = "| by {$userName}";
            break;

        case 'ACTIVATED':
            $status = $details['status'] ?? 'ACTIVATED';
            $tmiId = $details['tmi_entry_id'] ?? '';
            $entryType = $details['entry_type'] ?? '';
            $element = $details['ctl_element'] ?? '';
            $parts[] = "🚀 **{$status}** Prop #{$proposalId}";
            if ($tmiId) $parts[] = "→ TMI #{$tmiId}";
            if ($entryType) $parts[] = "| {$entryType}";
            if ($element) $parts[] = "| {$element}";
            $parts[] = "| Discord: " . ($details['discord_posted'] ? '✅' : '❌');
            break;

        case 'DCC_REOPEN':
            $entryType = $details['entry_type'] ?? '';
            $element = $details['ctl_element'] ?? '';
            $parts[] = "🔄 **REOPENED** Prop #{$proposalId}";
            if ($entryType) $parts[] = "| {$entryType}";
            if ($element) $parts[] = "| {$element}";
            $parts[] = "| by {$userName}{$via}";
            if (!empty($details['reason'])) $parts[] = "| reason: {$details['reason']}";
            break;

        case 'DCC_CANCEL':
            $entryType = $details['entry_type'] ?? '';
            $element = $details['ctl_element'] ?? '';
            $parts[] = "🗑️ **CANCELLED** Prop #{$proposalId}";
            if ($entryType) $parts[] = "| {$entryType}";
            if ($element) $parts[] = "| {$element}";
            $parts[] = "| by {$userName}{$via}";
            break;

        case 'DEADLINE_EXTENDED':
            $newDeadlineUnix = strtotime($details['new_deadline'] ?? 'now');
            $parts[] = "⏰ **DEADLINE EXTENDED** Prop #{$proposalId}";
            $parts[] = "| new: <t:{$newDeadlineUnix}:f>";
            $parts[] = "| by {$userName}";
            break;

        case 'PROPOSAL_EDITED':
            $entryType = $details['entry_type'] ?? '';
            $element = $details['ctl_element'] ?? '';
            $parts[] = "✏️ **PROPOSAL EDITED** Prop #{$proposalId}";
            if ($entryType) $parts[] = "| {$entryType}";
            if ($element) $parts[] = "| {$element}";
            $parts[] = "| by {$userName}";
            $parts[] = "| ⚠️ Approvals cleared, coordination restarted";
            break;

        case 'EXPIRED':
            $entryType = $details['entry_type'] ?? '';
            $element = $details['ctl_element'] ?? '';
            $parts[] = "⏰ **EXPIRED** Prop #{$proposalId}";
            if ($entryType) $parts[] = "| {$entryType}";
            if ($element) $parts[] = "| {$element}";
            break;

        case 'AUTO_APPROVED':
            $entryType = $details['entry_type'] ?? '';
            $element = $details['ctl_element'] ?? '';
            $reason = $details['reason'] ?? 'internal TMI';
            $parts[] = "🤖 **AUTO-APPROVED** Prop #{$proposalId}";
            if ($entryType) $parts[] = "| {$entryType}";
            if ($element) $parts[] = "| {$element}";
            $parts[] = "| {$reason}";
            break;

        default:
            $parts[] = "📋 **{$action}** Prop #{$proposalId}";
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
