<?php
/**
 * TMI Reroute Drafts API
 *
 * Manages persistent reroute advisory drafts for the hybrid storage approach.
 * Drafts are created from Route Plotter and can be resumed later in TMI Publisher.
 *
 * Endpoints:
 *   GET    /api/mgt/tmi/reroute-drafts.php              - List user's drafts
 *   GET    /api/mgt/tmi/reroute-drafts.php?draft_id=X   - Get specific draft
 *   GET    /api/mgt/tmi/reroute-drafts.php?draft_guid=X - Get draft by GUID
 *   POST   /api/mgt/tmi/reroute-drafts.php              - Create new draft
 *   PUT    /api/mgt/tmi/reroute-drafts.php?draft_id=X   - Update draft
 *   DELETE /api/mgt/tmi/reroute-drafts.php?draft_id=X   - Delete draft
 *
 * @package PERTI
 * @subpackage API/TMI
 * @version 1.0.0
 * @date 2026-01-30
 */

header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle CORS preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Load dependencies
try {
    require_once __DIR__ . '/../../../load/config.php';
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Config load error']);
    exit;
}

// Connect to TMI database
$conn = null;
try {
    if (defined('TMI_SQL_HOST') && TMI_SQL_HOST) {
        $conn = new PDO(
            "sqlsrv:Server=" . TMI_SQL_HOST . ";Database=" . TMI_SQL_DATABASE,
            TMI_SQL_USERNAME,
            TMI_SQL_PASSWORD
        );
        $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database connection failed']);
    exit;
}

if (!$conn) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database not configured']);
    exit;
}

// Route based on method
$method = $_SERVER['REQUEST_METHOD'];

try {
    switch ($method) {
        case 'GET':
            handleGet($conn);
            break;
        case 'POST':
            handlePost($conn);
            break;
        case 'PUT':
            handlePut($conn);
            break;
        case 'DELETE':
            handleDelete($conn);
            break;
        default:
            http_response_code(405);
            echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error: ' . $e->getMessage()]);
}

// ===========================================
// Request Handlers
// ===========================================

/**
 * GET - List drafts or get specific draft
 */
function handleGet($conn) {
    // Get specific draft by ID
    if (isset($_GET['draft_id'])) {
        $draftId = intval($_GET['draft_id']);
        $draft = getDraftById($conn, $draftId);

        if (!$draft) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Draft not found']);
            return;
        }

        echo json_encode(['success' => true, 'draft' => $draft]);
        return;
    }

    // Get specific draft by GUID
    if (isset($_GET['draft_guid'])) {
        $draftGuid = $_GET['draft_guid'];
        $draft = getDraftByGuid($conn, $draftGuid);

        if (!$draft) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Draft not found']);
            return;
        }

        echo json_encode(['success' => true, 'draft' => $draft]);
        return;
    }

    // List drafts for user
    $userCid = $_GET['user_cid'] ?? null;
    if (!$userCid) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'user_cid is required']);
        return;
    }

    $includeSubmitted = isset($_GET['include_submitted']) && $_GET['include_submitted'] === '1';
    $limit = min(intval($_GET['limit'] ?? 20), 50);

    $drafts = listDraftsForUser($conn, $userCid, $includeSubmitted, $limit);

    echo json_encode([
        'success' => true,
        'count' => count($drafts),
        'drafts' => $drafts
    ]);
}

/**
 * POST - Create new draft
 */
function handlePost($conn) {
    $input = json_decode(file_get_contents('php://input'), true);

    if (!$input) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid JSON body']);
        return;
    }

    // Validate required fields
    if (empty($input['user_cid'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'user_cid is required']);
        return;
    }

    if (empty($input['draft_data'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'draft_data is required']);
        return;
    }

    // Set default expiration (7 days from now)
    $expiresAt = isset($input['expires_days'])
        ? "DATEADD(DAY, " . intval($input['expires_days']) . ", SYSUTCDATETIME())"
        : "DATEADD(DAY, 7, SYSUTCDATETIME())";

    $sql = "INSERT INTO dbo.tmi_reroute_drafts
            (user_cid, user_name, draft_name, draft_data, expires_at)
            OUTPUT INSERTED.draft_id, INSERTED.draft_guid
            VALUES (:user_cid, :user_name, :draft_name, :draft_data, $expiresAt)";

    $stmt = $conn->prepare($sql);
    $stmt->execute([
        ':user_cid' => $input['user_cid'],
        ':user_name' => $input['user_name'] ?? null,
        ':draft_name' => $input['draft_name'] ?? generateDraftName($input['draft_data']),
        ':draft_data' => is_array($input['draft_data']) ? json_encode($input['draft_data']) : $input['draft_data']
    ]);

    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'draft_id' => intval($row['draft_id']),
        'draft_guid' => $row['draft_guid'],
        'message' => 'Draft created successfully'
    ]);
}

/**
 * PUT - Update existing draft
 */
function handlePut($conn) {
    $draftId = isset($_GET['draft_id']) ? intval($_GET['draft_id']) : null;

    if (!$draftId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'draft_id is required']);
        return;
    }

    $input = json_decode(file_get_contents('php://input'), true);

    if (!$input) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid JSON body']);
        return;
    }

    // Build update fields dynamically
    $updates = [];
    $params = [':draft_id' => $draftId];

    if (isset($input['draft_name'])) {
        $updates[] = 'draft_name = :draft_name';
        $params[':draft_name'] = $input['draft_name'];
    }

    if (isset($input['draft_data'])) {
        $updates[] = 'draft_data = :draft_data';
        $params[':draft_data'] = is_array($input['draft_data']) ? json_encode($input['draft_data']) : $input['draft_data'];
    }

    if (isset($input['is_submitted'])) {
        $updates[] = 'is_submitted = :is_submitted';
        $params[':is_submitted'] = $input['is_submitted'] ? 1 : 0;
    }

    if (isset($input['submitted_proposal_id'])) {
        $updates[] = 'submitted_proposal_id = :submitted_proposal_id';
        $params[':submitted_proposal_id'] = intval($input['submitted_proposal_id']);
    }

    if (isset($input['expires_days'])) {
        $updates[] = 'expires_at = DATEADD(DAY, ' . intval($input['expires_days']) . ', SYSUTCDATETIME())';
    }

    if (empty($updates)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'No fields to update']);
        return;
    }

    // Always update updated_at
    $updates[] = 'updated_at = SYSUTCDATETIME()';

    $sql = "UPDATE dbo.tmi_reroute_drafts SET " . implode(', ', $updates) . " WHERE draft_id = :draft_id";

    $stmt = $conn->prepare($sql);
    $stmt->execute($params);

    if ($stmt->rowCount() === 0) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Draft not found']);
        return;
    }

    echo json_encode([
        'success' => true,
        'draft_id' => $draftId,
        'message' => 'Draft updated successfully'
    ]);
}

/**
 * DELETE - Delete draft
 */
function handleDelete($conn) {
    $draftId = isset($_GET['draft_id']) ? intval($_GET['draft_id']) : null;

    if (!$draftId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'draft_id is required']);
        return;
    }

    $sql = "DELETE FROM dbo.tmi_reroute_drafts WHERE draft_id = :draft_id";
    $stmt = $conn->prepare($sql);
    $stmt->execute([':draft_id' => $draftId]);

    if ($stmt->rowCount() === 0) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Draft not found']);
        return;
    }

    echo json_encode([
        'success' => true,
        'message' => 'Draft deleted successfully'
    ]);
}

// ===========================================
// Helper Functions
// ===========================================

/**
 * Get draft by ID
 */
function getDraftById($conn, $draftId) {
    $sql = "SELECT
                draft_id, draft_guid, user_cid, user_name, draft_name, draft_data,
                created_at, updated_at, expires_at, is_submitted, submitted_proposal_id
            FROM dbo.tmi_reroute_drafts
            WHERE draft_id = :draft_id";

    $stmt = $conn->prepare($sql);
    $stmt->execute([':draft_id' => $draftId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ? formatDraft($row) : null;
}

/**
 * Get draft by GUID
 */
function getDraftByGuid($conn, $draftGuid) {
    $sql = "SELECT
                draft_id, draft_guid, user_cid, user_name, draft_name, draft_data,
                created_at, updated_at, expires_at, is_submitted, submitted_proposal_id
            FROM dbo.tmi_reroute_drafts
            WHERE draft_guid = :draft_guid";

    $stmt = $conn->prepare($sql);
    $stmt->execute([':draft_guid' => $draftGuid]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ? formatDraft($row) : null;
}

/**
 * List drafts for a user
 */
function listDraftsForUser($conn, $userCid, $includeSubmitted, $limit) {
    $whereClause = $includeSubmitted ? '' : 'AND is_submitted = 0';

    $sql = "SELECT
                draft_id, draft_guid, user_cid, user_name, draft_name,
                created_at, updated_at, expires_at, is_submitted, submitted_proposal_id
            FROM dbo.vw_reroute_drafts_active
            WHERE user_cid = :user_cid $whereClause
            ORDER BY updated_at DESC
            OFFSET 0 ROWS FETCH NEXT :limit ROWS ONLY";

    // Try view first, fall back to table if view doesn't exist
    try {
        $stmt = $conn->prepare($sql);
        $stmt->bindValue(':user_cid', $userCid, PDO::PARAM_STR);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
    } catch (Exception $e) {
        // View might not exist, fall back to table
        $sql = "SELECT TOP ($limit)
                    draft_id, draft_guid, user_cid, user_name, draft_name,
                    created_at, updated_at, expires_at, is_submitted, submitted_proposal_id
                FROM dbo.tmi_reroute_drafts
                WHERE user_cid = :user_cid
                  AND (expires_at IS NULL OR expires_at > SYSUTCDATETIME())
                  $whereClause
                ORDER BY updated_at DESC";

        $stmt = $conn->prepare($sql);
        $stmt->execute([':user_cid' => $userCid]);
    }

    $drafts = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $drafts[] = formatDraftSummary($row);
    }

    return $drafts;
}

/**
 * Format full draft for response
 */
function formatDraft($row) {
    return [
        'draft_id' => intval($row['draft_id']),
        'draft_guid' => $row['draft_guid'],
        'user_cid' => $row['user_cid'],
        'user_name' => $row['user_name'],
        'draft_name' => $row['draft_name'],
        'draft_data' => json_decode($row['draft_data'], true),
        'created_at' => $row['created_at'],
        'updated_at' => $row['updated_at'],
        'expires_at' => $row['expires_at'],
        'is_submitted' => (bool) $row['is_submitted'],
        'submitted_proposal_id' => $row['submitted_proposal_id'] ? intval($row['submitted_proposal_id']) : null
    ];
}

/**
 * Format draft summary for list (without full draft_data)
 */
function formatDraftSummary($row) {
    return [
        'draft_id' => intval($row['draft_id']),
        'draft_guid' => $row['draft_guid'],
        'draft_name' => $row['draft_name'],
        'created_at' => $row['created_at'],
        'updated_at' => $row['updated_at'],
        'expires_at' => $row['expires_at'],
        'is_submitted' => (bool) $row['is_submitted'],
        'submitted_proposal_id' => isset($row['submitted_proposal_id']) ? intval($row['submitted_proposal_id']) : null
    ];
}

/**
 * Generate a draft name from the draft data
 */
function generateDraftName($draftData) {
    $data = is_array($draftData) ? $draftData : json_decode($draftData, true);

    if (!$data) {
        return 'Untitled Draft';
    }

    // Try to extract a meaningful name
    if (!empty($data['advisory']['name'])) {
        return $data['advisory']['name'];
    }

    if (!empty($data['advisory']['constrainedArea'])) {
        return $data['advisory']['constrainedArea'] . ' Reroute';
    }

    if (!empty($data['routes']) && is_array($data['routes'])) {
        $firstRoute = $data['routes'][0];
        $origin = $firstRoute['origin'] ?? '';
        $dest = $firstRoute['destination'] ?? '';
        if ($origin && $dest) {
            return "$origin-$dest Reroute";
        }
    }

    $timestamp = date('M j H:i');
    return "Draft $timestamp";
}
