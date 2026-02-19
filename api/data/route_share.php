<?php
/**
 * Route Share API
 *
 * POST           — Create a new share (public, stores CID if logged in)
 * GET ?code=X    — Retrieve a share by code (public)
 * GET ?mine=1    — List shares for the logged-in user (requires session)
 * DELETE ?code=X — Delete a share (owner only)
 */

// Session Start (S)
if (session_status() == PHP_SESSION_NONE) {
    session_start();
    ob_start();
}
// Session Start (E)

include("../../load/config.php");
define('PERTI_MYSQL_ONLY', true);
include("../../load/connect.php");

header('Content-Type: application/json; charset=utf-8');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// ---------------------------------------------------------------------------
// Helper: generate a random alphanumeric suffix
// ---------------------------------------------------------------------------
function generate_suffix(int $length = 4): string {
    $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    $suffix = '';
    for ($i = 0; $i < $length; $i++) {
        $suffix .= $chars[random_int(0, strlen($chars) - 1)];
    }
    return $suffix;
}

// ---------------------------------------------------------------------------
// Helper: sanitize user label to URL-safe slug
// ---------------------------------------------------------------------------
function slugify(string $text): string {
    $text = mb_strtolower(trim($text), 'UTF-8');
    // Replace non-alphanumeric with hyphens
    $text = preg_replace('/[^a-z0-9]+/', '-', $text);
    // Collapse multiple hyphens
    $text = preg_replace('/-{2,}/', '-', $text);
    // Trim leading/trailing hyphens
    $text = trim($text, '-');
    // Max 60 chars
    if (strlen($text) > 60) {
        $text = substr($text, 0, 60);
        $text = rtrim($text, '-');
    }
    return $text;
}

// ===========================================================================
// POST — Create share
// ===========================================================================
if ($method === 'POST') {
    // Simple session-based rate limit: 20 creates per hour
    if (!isset($_SESSION['route_share_count'])) { $_SESSION['route_share_count'] = 0; }
    if (!isset($_SESSION['route_share_window'])) { $_SESSION['route_share_window'] = time(); }
    if (time() - $_SESSION['route_share_window'] > 3600) {
        $_SESSION['route_share_count'] = 0;
        $_SESSION['route_share_window'] = time();
    }
    if ($_SESSION['route_share_count'] >= 20) {
        http_response_code(429);
        echo json_encode(['success' => false, 'error' => 'Rate limit exceeded, try again later']);
        exit;
    }

    $body = json_decode(file_get_contents('php://input'), true);
    if (!$body || empty($body['label']) || empty($body['route_text'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'label and route_text are required']);
        exit;
    }

    $raw_label  = trim($body['label']);
    $route_text = $body['route_text'];

    if (mb_strlen($raw_label) > 80) {
        $raw_label = mb_substr($raw_label, 0, 80);
    }

    $slug = slugify($raw_label);
    if ($slug === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Label must contain at least one alphanumeric character']);
        exit;
    }

    // Route text size guard (64KB max)
    if (strlen($route_text) > 65536) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Route text too large (64KB max)']);
        exit;
    }

    $cid = isset($_SESSION['VATSIM_CID']) ? (string)$_SESSION['VATSIM_CID'] : null;

    // Generate unique code with retry (INSERT directly, retry on duplicate key)
    $code = null;
    $inserted = false;
    for ($attempt = 0; $attempt < 5; $attempt++) {
        $suffix = generate_suffix(4);
        $candidate = $slug . '-' . $suffix;

        $stmt = $conn_sqli->prepare(
            "INSERT INTO route_shares (code, label, suffix, route_text, created_by_cid) VALUES (?, ?, ?, ?, ?)"
        );
        $stmt->bind_param('sssss', $candidate, $raw_label, $suffix, $route_text, $cid);

        if ($stmt->execute()) {
            $code = $candidate;
            $inserted = true;
            $stmt->close();
            break;
        }
        // Duplicate key (errno 1062) — retry with new suffix
        $stmt->close();
    }

    if (!$inserted) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Failed to generate unique code']);
        exit;
    }

    $_SESSION['route_share_count']++;

    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'perti.vatcscc.org';
    $url = $protocol . '://' . $host . '/route.php?s=' . urlencode($code);

    echo json_encode([
        'success' => true,
        'code'    => $code,
        'url'     => $url,
    ]);
    exit;
}

// ===========================================================================
// GET — Retrieve or list
// ===========================================================================
if ($method === 'GET') {
    // List user's shares
    if (isset($_GET['mine'])) {
        $cid = isset($_SESSION['VATSIM_CID']) ? (string)$_SESSION['VATSIM_CID'] : '';
        if ($cid === '' || $cid === '0') {
            http_response_code(401);
            echo json_encode(['success' => false, 'error' => 'Login required']);
            exit;
        }

        $stmt = $conn_sqli->prepare(
            "SELECT code, label, created_at, view_count FROM route_shares WHERE created_by_cid = ? ORDER BY created_at DESC LIMIT 50"
        );
        $stmt->bind_param('s', $cid);
        $stmt->execute();
        $result = $stmt->get_result();

        $shares = [];
        while ($row = $result->fetch_assoc()) {
            $shares[] = $row;
        }
        $stmt->close();

        echo json_encode(['success' => true, 'shares' => $shares]);
        exit;
    }

    // Retrieve single share by code
    $code = get_input('code');
    if ($code === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'code parameter required']);
        exit;
    }

    $stmt = $conn_sqli->prepare(
        "SELECT route_text, label, created_at FROM route_shares WHERE code = ?"
    );
    $stmt->bind_param('s', $code);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();

    if (!$row) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Share not found']);
        exit;
    }

    // Increment view count (fire-and-forget)
    $upd = $conn_sqli->prepare("UPDATE route_shares SET view_count = view_count + 1 WHERE code = ?");
    $upd->bind_param('s', $code);
    $upd->execute();
    $upd->close();

    echo json_encode([
        'success'    => true,
        'route_text' => $row['route_text'],
        'label'      => $row['label'],
        'created_at' => $row['created_at'],
    ]);
    exit;
}

// ===========================================================================
// DELETE — Remove a share (owner only)
// ===========================================================================
if ($method === 'DELETE') {
    $code = get_input('code');
    $cid = isset($_SESSION['VATSIM_CID']) ? (string)$_SESSION['VATSIM_CID'] : '';

    if ($code === '' || $cid === '' || $cid === '0') {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'code and login required']);
        exit;
    }

    $stmt = $conn_sqli->prepare("DELETE FROM route_shares WHERE code = ? AND created_by_cid = ?");
    $stmt->bind_param('ss', $code, $cid);
    $stmt->execute();

    if ($stmt->affected_rows > 0) {
        echo json_encode(['success' => true]);
    } else {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Share not found or not owned by you']);
    }
    $stmt->close();
    exit;
}

http_response_code(405);
echo json_encode(['success' => false, 'error' => 'Method not allowed']);
