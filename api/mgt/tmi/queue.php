<?php
/**
 * TMI Discord Queue Status & Trigger API
 *
 * GET  /api/mgt/tmi/queue.php         - Get queue statistics
 * POST /api/mgt/tmi/queue.php         - Process pending posts (trigger)
 * POST /api/mgt/tmi/queue.php?retry=1 - Retry failed posts
 *
 * @package PERTI
 * @subpackage API/TMI
 */

header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../../../load/config.php';

// Connect to TMI database
$tmiConn = null;
try {
    if (defined('TMI_SQL_HOST') && TMI_SQL_HOST) {
        $connStr = "sqlsrv:Server=" . TMI_SQL_HOST . ";Database=" . TMI_SQL_DATABASE;
        $tmiConn = new PDO($connStr, TMI_SQL_USERNAME, TMI_SQL_PASSWORD);
        $tmiConn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database connection failed']);
    exit;
}

if (!$tmiConn) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'TMI database not configured']);
    exit;
}

// GET: Return queue statistics
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    try {
        $stats = [];

        // Get counts by status
        $sql = "SELECT
                    status,
                    COUNT(*) as count,
                    MIN(requested_at) as oldest,
                    MAX(requested_at) as newest
                FROM dbo.tmi_discord_posts
                GROUP BY status";

        $stmt = $tmiConn->query($sql);
        $statusCounts = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $statusCounts[$row['status']] = [
                'count' => (int)$row['count'],
                'oldest' => $row['oldest'],
                'newest' => $row['newest']
            ];
        }

        // Get pending posts count (actionable)
        $pendingSql = "SELECT COUNT(*) as cnt FROM dbo.tmi_discord_posts
                       WHERE status IN ('PENDING', 'FAILED') AND retry_count < 3";
        $stmt = $tmiConn->query($pendingSql);
        $pending = $stmt->fetch(PDO::FETCH_ASSOC)['cnt'];

        // Get processing rate (last 5 minutes)
        $rateSql = "SELECT COUNT(*) as cnt FROM dbo.tmi_discord_posts
                    WHERE status = 'POSTED'
                      AND posted_at >= DATEADD(MINUTE, -5, SYSUTCDATETIME())";
        $stmt = $tmiConn->query($rateSql);
        $recentPosted = (int)$stmt->fetch(PDO::FETCH_ASSOC)['cnt'];
        $postsPerMinute = round($recentPosted / 5, 1);

        // Get failed posts that exceeded retries
        $exhaustedSql = "SELECT COUNT(*) as cnt FROM dbo.tmi_discord_posts
                         WHERE status = 'FAILED' AND retry_count >= 3";
        $stmt = $tmiConn->query($exhaustedSql);
        $exhausted = (int)$stmt->fetch(PDO::FETCH_ASSOC)['cnt'];

        // Get recent errors
        $errorsSql = "SELECT TOP 5 post_id, org_code, channel_purpose, error_message, retry_count, requested_at
                      FROM dbo.tmi_discord_posts
                      WHERE status = 'FAILED'
                      ORDER BY requested_at DESC";
        $stmt = $tmiConn->query($errorsSql);
        $recentErrors = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'success' => true,
            'queue' => [
                'pending_actionable' => (int)$pending,
                'exhausted_retries' => $exhausted,
                'posts_per_minute' => $postsPerMinute,
                'by_status' => $statusCounts
            ],
            'recent_errors' => $recentErrors,
            'timestamp' => gmdate('c')
        ]);

    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// POST: Process pending posts
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $maxBatch = isset($_GET['batch']) ? min(50, (int)$_GET['batch']) : 10;
    $retryExhausted = isset($_GET['retry']) && $_GET['retry'];

    // Load Discord APIs
    require_once __DIR__ . '/../../../load/discord/DiscordAPI.php';
    $multiDiscordPath = __DIR__ . '/../../../load/discord/MultiDiscordAPI.php';

    $discord = null;
    $multiDiscord = null;

    try {
        $discord = new DiscordAPI();
        if (file_exists($multiDiscordPath)) {
            require_once $multiDiscordPath;
            $multiDiscord = new MultiDiscordAPI();
        }
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Discord initialization failed: ' . $e->getMessage()]);
        exit;
    }

    // Fetch pending posts
    $whereClause = $retryExhausted
        ? "status = 'FAILED'"
        : "status IN ('PENDING', 'FAILED') AND retry_count < 3";

    $sql = "SELECT TOP {$maxBatch}
                post_id, entity_type, entity_id,
                org_code, channel_purpose, retry_count
            FROM dbo.tmi_discord_posts
            WHERE {$whereClause}
            ORDER BY requested_at ASC";

    $stmt = $tmiConn->query($sql);
    $posts = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $results = [];
    $success = 0;
    $failed = 0;

    foreach ($posts as $post) {
        // Get message content
        $isStaging = strpos($post['channel_purpose'], 'staging') !== false;
        $prefix = $isStaging ? '🧪 **[STAGING]** ' : '';

        if ($post['entity_type'] === 'ENTRY') {
            $contentSql = "SELECT raw_input FROM dbo.tmi_entries WHERE entry_id = :id";
        } else {
            $contentSql = "SELECT body_text AS raw_input FROM dbo.tmi_advisories WHERE advisory_id = :id";
        }

        $contentStmt = $tmiConn->prepare($contentSql);
        $contentStmt->execute([':id' => $post['entity_id']]);
        $contentRow = $contentStmt->fetch(PDO::FETCH_ASSOC);

        if (!$contentRow || empty($contentRow['raw_input'])) {
            // Mark as permanently failed
            $updateSql = "UPDATE dbo.tmi_discord_posts SET status = 'FAILED', error_message = 'Entity not found', retry_count = 99 WHERE post_id = :id";
            $updateStmt = $tmiConn->prepare($updateSql);
            $updateStmt->execute([':id' => $post['post_id']]);
            $results[] = ['post_id' => $post['post_id'], 'success' => false, 'error' => 'Entity not found'];
            $failed++;
            continue;
        }

        $content = $prefix . "```\n" . $contentRow['raw_input'] . "\n```";

        // Post to Discord
        $postResult = null;
        if ($multiDiscord && $multiDiscord->isConfigured()) {
            $postResult = $multiDiscord->postToChannel($post['org_code'], $post['channel_purpose'], ['content' => $content]);
        } elseif ($discord && $discord->isConfigured()) {
            $channelId = $discord->getChannelByPurpose($post['channel_purpose']);
            if ($channelId) {
                $response = $discord->createMessage($channelId, ['content' => $content]);
                $postResult = [
                    'success' => ($response && isset($response['id'])),
                    'message_id' => $response['id'] ?? null,
                    'channel_id' => $channelId,
                    'error' => $discord->getLastError()
                ];
            }
        }

        if ($postResult && $postResult['success']) {
            // Update queue entry
            $updateSql = "UPDATE dbo.tmi_discord_posts SET
                            status = 'POSTED',
                            message_id = :msg_id,
                            channel_id = :ch_id,
                            posted_at = SYSUTCDATETIME(),
                            error_message = NULL
                          WHERE post_id = :post_id";
            $updateStmt = $tmiConn->prepare($updateSql);
            $updateStmt->execute([
                ':msg_id' => $postResult['message_id'],
                ':ch_id' => $postResult['channel_id'],
                ':post_id' => $post['post_id']
            ]);

            // Update parent entity
            if ($post['entity_type'] === 'ENTRY') {
                $entitySql = "UPDATE dbo.tmi_entries SET discord_message_id = :msg, discord_channel_id = :ch, discord_posted_at = SYSUTCDATETIME() WHERE entry_id = :id AND discord_message_id IS NULL";
            } else {
                $entitySql = "UPDATE dbo.tmi_advisories SET discord_message_id = :msg, discord_channel_id = :ch, discord_posted_at = SYSUTCDATETIME() WHERE advisory_id = :id AND discord_message_id IS NULL";
            }
            $entityStmt = $tmiConn->prepare($entitySql);
            $entityStmt->execute([':msg' => $postResult['message_id'], ':ch' => $postResult['channel_id'], ':id' => $post['entity_id']]);

            $results[] = ['post_id' => $post['post_id'], 'success' => true, 'message_id' => $postResult['message_id']];
            $success++;
        } else {
            // Update retry count
            $error = $postResult['error'] ?? 'Discord post failed';
            $updateSql = "UPDATE dbo.tmi_discord_posts SET
                            status = 'FAILED',
                            retry_count = retry_count + 1,
                            error_message = :error
                          WHERE post_id = :post_id";
            $updateStmt = $tmiConn->prepare($updateSql);
            $updateStmt->execute([':error' => substr($error, 0, 512), ':post_id' => $post['post_id']]);

            $results[] = ['post_id' => $post['post_id'], 'success' => false, 'error' => $error];
            $failed++;
        }

        // Rate limiting - 100ms delay
        usleep(100000);
    }

    echo json_encode([
        'success' => true,
        'processed' => count($posts),
        'successful' => $success,
        'failed' => $failed,
        'results' => $results
    ]);
    exit;
}

// DELETE: Clear queue entries
// DELETE /api/mgt/tmi/queue.php                    - Clear all PENDING/FAILED entries
// DELETE /api/mgt/tmi/queue.php?status=PENDING     - Clear only PENDING
// DELETE /api/mgt/tmi/queue.php?entity_type=ENTRY  - Clear only ENTRY type in queue (vs ADVISORY)
// DELETE /api/mgt/tmi/queue.php?entry_type=CONFIG  - Clear only CONFIG entries (from tmi_entries)
// DELETE /api/mgt/tmi/queue.php?element=JFK        - Clear entries for specific element
// DELETE /api/mgt/tmi/queue.php?all=1              - Clear ALL entries (including POSTED)
if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    try {
        $status = $_GET['status'] ?? null;
        $entityType = $_GET['entity_type'] ?? null;
        $entryType = $_GET['entry_type'] ?? null;
        $element = $_GET['element'] ?? null;
        $clearAll = isset($_GET['all']) && $_GET['all'];

        // Build WHERE clause
        $conditions = [];
        $params = [];

        if (!$clearAll) {
            // By default, only clear PENDING and FAILED entries
            $conditions[] = "status IN ('PENDING', 'FAILED')";
        }

        if ($status) {
            $conditions[] = "status = :status";
            $params[':status'] = strtoupper($status);
        }

        if ($entityType) {
            // Filter by entity_type column in queue (ENTRY vs ADVISORY)
            $conditions[] = "entity_type = :entity_type";
            $params[':entity_type'] = strtoupper($entityType);
        }

        if ($entryType) {
            // Filter by entry_type from tmi_entries (CONFIG, MIT, MINIT, etc.)
            $conditions[] = "entity_type = 'ENTRY' AND entity_id IN (SELECT entry_id FROM dbo.tmi_entries WHERE entry_type = :entry_type)";
            $params[':entry_type'] = strtoupper($entryType);
        }

        if ($element) {
            // Filter by element in the related entry
            $conditions[] = "entity_id IN (SELECT entry_id FROM dbo.tmi_entries WHERE ctl_element LIKE :element)";
            $params[':element'] = '%' . strtoupper($element) . '%';
        }

        // Get count first
        $whereClause = count($conditions) > 0 ? 'WHERE ' . implode(' AND ', $conditions) : '';
        $countSql = "SELECT COUNT(*) as cnt FROM dbo.tmi_discord_posts {$whereClause}";
        $countStmt = $tmiConn->prepare($countSql);
        $countStmt->execute($params);
        $count = (int)$countStmt->fetch(PDO::FETCH_ASSOC)['cnt'];

        if ($count === 0) {
            echo json_encode([
                'success' => true,
                'message' => 'No matching entries to clear',
                'deleted' => 0
            ]);
            exit;
        }

        // Delete entries
        $deleteSql = "DELETE FROM dbo.tmi_discord_posts {$whereClause}";
        $deleteStmt = $tmiConn->prepare($deleteSql);
        $deleteStmt->execute($params);

        echo json_encode([
            'success' => true,
            'message' => "Cleared {$count} queue entries",
            'deleted' => $count,
            'filters' => [
                'status' => $status,
                'entity_type' => $entityType,
                'entry_type' => $entryType,
                'element' => $element,
                'all' => $clearAll
            ]
        ]);

    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

http_response_code(405);
echo json_encode(['success' => false, 'error' => 'Method not allowed']);
