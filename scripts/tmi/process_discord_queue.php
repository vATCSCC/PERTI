<?php
/**
 * Discord Queue Processor
 *
 * Processes pending Discord posts from the tmi_discord_posts queue.
 * This script should be run as a background job or cron task.
 *
 * Features:
 *   - Rate limiting to avoid Discord API limits (50 requests/second)
 *   - Batch processing with configurable batch size
 *   - Retry handling for failed posts
 *   - Graceful error handling
 *
 * Usage:
 *   php scripts/tmi/process_discord_queue.php [--batch=50] [--delay=100] [--max-retries=3] [--once]
 *
 *   --batch=N       Process N posts per batch (default: 50)
 *   --delay=N       Delay N milliseconds between posts (default: 100 = 10/sec)
 *   --max-retries=N Maximum retry attempts for failed posts (default: 3)
 *   --once          Process one batch and exit (vs continuous mode)
 *   --dry-run       Don't actually post, just log what would be posted
 *
 * @package PERTI
 * @subpackage TMI
 */

// Configuration
$config = [
    'batch_size' => 50,
    'delay_ms' => 100,        // 100ms = 10 posts/second (safe for Discord)
    'max_retries' => 3,
    'continuous' => true,
    'dry_run' => false,
    'poll_interval' => 5,     // Seconds to wait between batches when queue is empty
];

// Parse command line arguments
foreach ($argv as $arg) {
    if (preg_match('/^--batch=(\d+)$/', $arg, $m)) $config['batch_size'] = (int)$m[1];
    if (preg_match('/^--delay=(\d+)$/', $arg, $m)) $config['delay_ms'] = (int)$m[1];
    if (preg_match('/^--max-retries=(\d+)$/', $arg, $m)) $config['max_retries'] = (int)$m[1];
    if ($arg === '--once') $config['continuous'] = false;
    if ($arg === '--dry-run') $config['dry_run'] = true;
}

// Load dependencies
require_once __DIR__ . '/../../load/config.php';

echo "===========================================\n";
echo "  Discord Queue Processor\n";
echo "===========================================\n";
echo "  Batch size:    {$config['batch_size']}\n";
echo "  Delay:         {$config['delay_ms']}ms\n";
echo "  Max retries:   {$config['max_retries']}\n";
echo "  Mode:          " . ($config['continuous'] ? 'Continuous' : 'Single batch') . "\n";
echo "  Dry run:       " . ($config['dry_run'] ? 'Yes' : 'No') . "\n";
echo "===========================================\n\n";

// Connect to TMI database
$tmiConn = null;
try {
    if (defined('TMI_SQL_HOST') && TMI_SQL_HOST) {
        $connStr = "sqlsrv:Server=" . TMI_SQL_HOST . ";Database=" . TMI_SQL_DATABASE;
        $tmiConn = new PDO($connStr, TMI_SQL_USERNAME, TMI_SQL_PASSWORD);
        $tmiConn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        echo "[OK] Connected to TMI database\n";
    } else {
        die("[ERROR] TMI database not configured\n");
    }
} catch (Exception $e) {
    die("[ERROR] Database connection failed: " . $e->getMessage() . "\n");
}

// Initialize Discord APIs
require_once __DIR__ . '/../../load/discord/DiscordAPI.php';
$multiDiscordPath = __DIR__ . '/../../load/discord/MultiDiscordAPI.php';

$discord = null;
$multiDiscord = null;

try {
    $discord = new DiscordAPI();
    if (file_exists($multiDiscordPath)) {
        require_once $multiDiscordPath;
        $multiDiscord = new MultiDiscordAPI();
    }
    echo "[OK] Discord API initialized\n";
} catch (Exception $e) {
    die("[ERROR] Discord initialization failed: " . $e->getMessage() . "\n");
}

// Statistics
$stats = [
    'processed' => 0,
    'success' => 0,
    'failed' => 0,
    'skipped' => 0,
    'start_time' => microtime(true)
];

/**
 * Get the message content for a queued post
 */
function getMessageContent($conn, $entityType, $entityId, $isStaging) {
    $prefix = $isStaging ? '🧪 **[STAGING]** ' : '';

    if ($entityType === 'ENTRY') {
        $sql = "SELECT raw_input FROM dbo.tmi_entries WHERE entry_id = :id";
    } else {
        $sql = "SELECT body_text AS raw_input FROM dbo.tmi_advisories WHERE advisory_id = :id";
    }

    $stmt = $conn->prepare($sql);
    $stmt->execute([':id' => $entityId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row || empty($row['raw_input'])) {
        return null;
    }

    return $prefix . "```\n" . $row['raw_input'] . "\n```";
}

/**
 * Post a message to Discord
 */
function postToDiscord($discord, $multiDiscord, $orgCode, $channelPurpose, $content) {
    global $config;

    if ($config['dry_run']) {
        return [
            'success' => true,
            'message_id' => 'DRY_RUN_' . uniqid(),
            'channel_id' => 'DRY_RUN',
            'message_url' => null,
            'error' => null
        ];
    }

    // Try multi-Discord first
    if ($multiDiscord && $multiDiscord->isConfigured()) {
        $result = $multiDiscord->postToChannel($orgCode, $channelPurpose, ['content' => $content]);
        return $result;
    }

    // Fallback to single Discord API
    if ($discord && $discord->isConfigured()) {
        $channelId = $discord->getChannelByPurpose($channelPurpose);

        if (!$channelId) {
            return [
                'success' => false,
                'error' => "No channel configured for: {$channelPurpose}"
            ];
        }

        $response = $discord->createMessage($channelId, ['content' => $content]);

        return [
            'success' => ($response && isset($response['id'])),
            'message_id' => $response['id'] ?? null,
            'channel_id' => $channelId,
            'message_url' => isset($response['id']) ? "https://discord.com/channels/@me/{$channelId}/{$response['id']}" : null,
            'error' => $discord->getLastError()
        ];
    }

    return [
        'success' => false,
        'error' => 'Discord not configured'
    ];
}

/**
 * Update queue entry status
 */
function updateQueueStatus($conn, $postId, $status, $messageId = null, $channelId = null, $messageUrl = null, $error = null) {
    $sql = "UPDATE dbo.tmi_discord_posts SET
                status = :status,
                message_id = COALESCE(:message_id, message_id),
                channel_id = CASE WHEN :channel_id != 'PENDING' THEN :channel_id ELSE channel_id END,
                message_url = COALESCE(:message_url, message_url),
                error_message = :error,
                posted_at = CASE WHEN :status = 'POSTED' THEN SYSUTCDATETIME() ELSE posted_at END,
                retry_count = CASE WHEN :status = 'FAILED' THEN retry_count + 1 ELSE retry_count END
            WHERE post_id = :post_id";

    $stmt = $conn->prepare($sql);
    $stmt->execute([
        ':status' => $status,
        ':message_id' => $messageId,
        ':channel_id' => $channelId,
        ':message_url' => $messageUrl,
        ':error' => $error,
        ':post_id' => $postId
    ]);
}

/**
 * Update the parent entry/advisory with Discord info
 */
function updateEntityDiscordInfo($conn, $entityType, $entityId, $messageId, $channelId) {
    if ($entityType === 'ENTRY') {
        $sql = "UPDATE dbo.tmi_entries SET
                    discord_message_id = :message_id,
                    discord_channel_id = :channel_id,
                    discord_posted_at = SYSUTCDATETIME(),
                    updated_at = SYSUTCDATETIME()
                WHERE entry_id = :entity_id
                  AND discord_message_id IS NULL"; // Only update if not already set
    } else {
        $sql = "UPDATE dbo.tmi_advisories SET
                    discord_message_id = :message_id,
                    discord_channel_id = :channel_id,
                    discord_posted_at = SYSUTCDATETIME(),
                    updated_at = SYSUTCDATETIME()
                WHERE advisory_id = :entity_id
                  AND discord_message_id IS NULL";
    }

    $stmt = $conn->prepare($sql);
    $stmt->execute([
        ':message_id' => $messageId,
        ':channel_id' => $channelId,
        ':entity_id' => $entityId
    ]);
}

/**
 * Process a batch of pending posts
 */
function processBatch($conn, $discord, $multiDiscord, $config) {
    global $stats;

    // Fetch pending posts
    $sql = "SELECT TOP {$config['batch_size']}
                post_id, entity_type, entity_id,
                org_code, channel_purpose,
                retry_count
            FROM dbo.tmi_discord_posts
            WHERE status IN ('PENDING', 'FAILED')
              AND retry_count < :max_retries
            ORDER BY
                CASE WHEN status = 'PENDING' THEN 0 ELSE 1 END,
                requested_at ASC";

    $stmt = $conn->prepare($sql);
    $stmt->execute([':max_retries' => $config['max_retries']]);
    $posts = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($posts)) {
        return 0;
    }

    $count = count($posts);
    echo "[BATCH] Processing {$count} posts...\n";

    foreach ($posts as $post) {
        $stats['processed']++;

        // Determine if staging based on channel purpose
        $isStaging = strpos($post['channel_purpose'], 'staging') !== false;

        // Get message content
        $content = getMessageContent($conn, $post['entity_type'], $post['entity_id'], $isStaging);

        if (empty($content)) {
            echo "  [{$post['post_id']}] SKIP - No content found\n";
            updateQueueStatus($conn, $post['post_id'], 'FAILED', null, null, null, 'Entity not found or empty content');
            $stats['skipped']++;
            continue;
        }

        // Post to Discord
        $result = postToDiscord(
            $discord,
            $multiDiscord,
            $post['org_code'],
            $post['channel_purpose'],
            $content
        );

        if ($result['success']) {
            echo "  [{$post['post_id']}] OK - {$post['org_code']}/{$post['channel_purpose']} -> {$result['message_id']}\n";

            updateQueueStatus(
                $conn,
                $post['post_id'],
                'POSTED',
                $result['message_id'],
                $result['channel_id'] ?? null,
                $result['message_url'] ?? null,
                null
            );

            // Update parent entity with Discord info
            if (!empty($result['message_id']) && $result['message_id'] !== 'DRY_RUN_' . substr($result['message_id'], 8)) {
                updateEntityDiscordInfo(
                    $conn,
                    $post['entity_type'],
                    $post['entity_id'],
                    $result['message_id'],
                    $result['channel_id'] ?? null
                );
            }

            $stats['success']++;
        } else {
            $error = $result['error'] ?? 'Unknown error';
            echo "  [{$post['post_id']}] FAIL - {$error}\n";

            updateQueueStatus(
                $conn,
                $post['post_id'],
                'FAILED',
                null,
                null,
                null,
                substr($error, 0, 512)
            );

            $stats['failed']++;
        }

        // Rate limiting delay
        if ($config['delay_ms'] > 0) {
            usleep($config['delay_ms'] * 1000);
        }
    }

    return $count;
}

// Main loop
echo "\n[START] Beginning queue processing...\n\n";

do {
    $processed = processBatch($tmiConn, $discord, $multiDiscord, $config);

    if ($processed === 0 && $config['continuous']) {
        echo "[WAIT] Queue empty, waiting {$config['poll_interval']}s...\n";
        sleep($config['poll_interval']);
    }

} while ($config['continuous'] || $processed > 0);

// Final statistics
$elapsed = microtime(true) - $stats['start_time'];
$rate = $stats['processed'] > 0 ? round($stats['processed'] / $elapsed, 2) : 0;

echo "\n===========================================\n";
echo "  Processing Complete\n";
echo "===========================================\n";
echo "  Total processed: {$stats['processed']}\n";
echo "  Successful:      {$stats['success']}\n";
echo "  Failed:          {$stats['failed']}\n";
echo "  Skipped:         {$stats['skipped']}\n";
echo "  Duration:        " . round($elapsed, 2) . "s\n";
echo "  Rate:            {$rate} posts/sec\n";
echo "===========================================\n";
