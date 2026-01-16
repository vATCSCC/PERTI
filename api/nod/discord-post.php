<?php
/**
 * Discord Advisory Posting API
 *
 * POST - Post formatted advisory to Discord webhook
 * GET ?action=status - Check webhook configuration status
 *
 * Configuration required in load/config.php:
 *   define('DISCORD_WEBHOOK_ADVISORIES', 'https://discord.com/api/webhooks/...');
 */

header('Content-Type: application/json');

// Include database connections
$config_path = realpath(__DIR__ . '/../../load/config.php');
$connect_path = realpath(__DIR__ . '/../../load/connect.php');

if ($config_path) include($config_path);
if ($connect_path) include($connect_path);

// =========================================
// Constants
// =========================================
define('DISCORD_MAX_LENGTH', 2000);

// Check configuration
$WEBHOOK_CONFIGURED = defined('DISCORD_WEBHOOK_ADVISORIES') && DISCORD_WEBHOOK_ADVISORIES !== '';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = $_GET['action'] ?? 'post';

try {
    switch ($method) {
        case 'GET':
            handleGet($action, $WEBHOOK_CONFIGURED);
            break;
        case 'POST':
            handlePost($action, $WEBHOOK_CONFIGURED, $conn_adl ?? null);
            break;
        default:
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}

/**
 * GET requests
 */
function handleGet($action, $configured) {
    switch ($action) {
        case 'status':
            echo json_encode([
                'configured' => $configured,
                'status' => $configured ? 'READY' : 'NOT_CONFIGURED',
                'message' => $configured
                    ? 'Discord webhook is configured and ready'
                    : 'Discord webhook not configured. Add DISCORD_WEBHOOK_ADVISORIES to load/config.php'
            ]);
            break;

        default:
            http_response_code(400);
            echo json_encode(['error' => 'Unknown action: ' . $action]);
    }
}

/**
 * POST requests
 */
function handlePost($action, $configured, $conn) {
    $input = json_decode(file_get_contents('php://input'), true);

    if (!$input) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid JSON input']);
        return;
    }

    switch ($action) {
        case 'post':
            postToDiscord($input, $configured, $conn);
            break;

        default:
            http_response_code(400);
            echo json_encode(['error' => 'Unknown action: ' . $action]);
    }
}

/**
 * Post advisory to Discord webhook
 */
function postToDiscord($input, $configured, $conn) {
    if (!$configured) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => 'Discord webhook not configured',
            'help' => 'Add DISCORD_WEBHOOK_ADVISORIES to load/config.php'
        ]);
        return;
    }

    // Get webhook URL
    $webhookUrl = DISCORD_WEBHOOK_ADVISORIES;

    // Check for pre-split parts or single text
    $parts = [];
    if (!empty($input['formatted_parts']) && is_array($input['formatted_parts'])) {
        $parts = $input['formatted_parts'];
    } elseif (!empty($input['formatted_text'])) {
        // Split if needed
        $text = $input['formatted_text'];
        if (strlen($text) < DISCORD_MAX_LENGTH) {
            $parts = [$text];
        } else {
            $parts = splitMessage($text);
        }
    } else {
        http_response_code(400);
        echo json_encode(['error' => 'Missing formatted_text or formatted_parts']);
        return;
    }

    if (empty($parts)) {
        http_response_code(400);
        echo json_encode(['error' => 'No message content to post']);
        return;
    }

    // Post each part to Discord
    $messageIds = [];
    $errors = [];

    foreach ($parts as $index => $part) {
        // Wrap in code block for formatting
        $content = "```\n" . $part . "\n```";

        // Ensure we don't exceed limit with code block wrapper
        if (strlen($content) > DISCORD_MAX_LENGTH) {
            $content = substr($content, 0, DISCORD_MAX_LENGTH - 10) . "...\n```";
        }

        $payload = json_encode([
            'content' => $content,
            'username' => 'vATCSCC Advisory Bot'
        ]);

        $result = sendToWebhook($webhookUrl, $payload);

        if ($result['success']) {
            if (isset($result['message_id'])) {
                $messageIds[] = $result['message_id'];
            }
        } else {
            $errors[] = "Part " . ($index + 1) . ": " . ($result['error'] ?? 'Unknown error');
        }

        // Rate limiting - brief pause between messages
        if (count($parts) > 1 && $index < count($parts) - 1) {
            usleep(500000); // 0.5 seconds
        }
    }

    // Save to database if connection available
    $advisoryId = null;
    if ($conn && !empty($input['advisory_type'])) {
        $advisoryId = saveAdvisory($conn, $input, $messageIds);
    }

    // Response
    if (empty($errors)) {
        echo json_encode([
            'success' => true,
            'message_count' => count($parts),
            'discord_message_ids' => $messageIds,
            'advisory_id' => $advisoryId
        ]);
    } else {
        http_response_code(207); // Multi-status
        echo json_encode([
            'success' => count($messageIds) > 0,
            'partial' => true,
            'message_count' => count($messageIds),
            'errors' => $errors,
            'discord_message_ids' => $messageIds,
            'advisory_id' => $advisoryId
        ]);
    }
}

/**
 * Split message into Discord-safe parts
 */
function splitMessage($text) {
    $maxLen = DISCORD_MAX_LENGTH - 20; // Reserve space for code block and part header

    if (strlen($text) <= $maxLen) {
        return [$text];
    }

    $lines = explode("\n", $text);
    $parts = [];
    $currentPart = '';

    // First pass: count parts
    $tempPart = '';
    $partCount = 0;
    foreach ($lines as $line) {
        if (strlen($tempPart . "\n" . $line) >= $maxLen - 30) {
            $partCount++;
            $tempPart = $line;
        } else {
            $tempPart .= ($tempPart ? "\n" : '') . $line;
        }
    }
    $partCount++;

    // Second pass: build parts with headers
    $partNum = 1;
    foreach ($lines as $line) {
        $testLength = strlen($currentPart) + strlen($line) + 1;

        if ($testLength >= $maxLen - 30) {
            if ($currentPart) {
                $parts[] = "[PART {$partNum} OF {$partCount}]\n\n" . $currentPart;
                $partNum++;
            }
            $currentPart = $line;
        } else {
            $currentPart .= ($currentPart ? "\n" : '') . $line;
        }
    }

    if ($currentPart) {
        $parts[] = "[PART {$partNum} OF {$partCount}]\n\n" . $currentPart;
    }

    return $parts;
}

/**
 * Send payload to Discord webhook
 */
function sendToWebhook($url, $payload) {
    $ch = curl_init($url . '?wait=true');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Content-Length: ' . strlen($payload)
        ],
        CURLOPT_TIMEOUT => 30
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) {
        return ['success' => false, 'error' => 'cURL error: ' . $error];
    }

    if ($httpCode >= 200 && $httpCode < 300) {
        $responseData = json_decode($response, true);
        return [
            'success' => true,
            'message_id' => $responseData['id'] ?? null
        ];
    } else {
        return [
            'success' => false,
            'error' => "HTTP {$httpCode}",
            'response' => $response
        ];
    }
}

/**
 * Save advisory to database
 */
function saveAdvisory($conn, $input, $messageIds) {
    $data = $input['advisory_data'] ?? [];

    $advType = $input['advisory_type'] ?? 'GENERAL';
    $advNumber = $data['advNumber'] ?? null;
    $subject = $data['atcsccSubject'] ?? ($advType . ' - ' . ($data['ctlElement'] ?? 'ADVISORY'));
    $bodyText = $input['formatted_text'] ?? '';
    $validStart = !empty($data['startTime']) ? str_replace('T', ' ', $data['startTime']) . ':00' : null;
    $validEnd = !empty($data['endTime']) ? str_replace('T', ' ', $data['endTime']) . ':00' : null;
    $discordMsgIds = implode(',', $messageIds);

    // Build facilities and airports arrays
    $facilities = [];
    if (!empty($data['gdpScopeCenters'])) {
        $facilities = is_array($data['gdpScopeCenters']) ? $data['gdpScopeCenters'] : explode(' ', $data['gdpScopeCenters']);
    } elseif (!empty($data['gsScopeCenters'])) {
        $facilities = is_array($data['gsScopeCenters']) ? $data['gsScopeCenters'] : explode(' ', $data['gsScopeCenters']);
    }

    $airports = [];
    if (!empty($data['ctlElement'])) {
        $airports[] = $data['ctlElement'];
    }

    $sql = "INSERT INTO dbo.dcc_advisories (
                adv_number, adv_type, subject, body_text,
                valid_start_utc, valid_end_utc,
                impacted_facilities, impacted_airports,
                source, status, priority,
                discord_message_id, discord_posted_at,
                created_at
            ) VALUES (
                ?, ?, ?, ?,
                ?, ?,
                ?, ?,
                'ADVISORY_BUILDER', 'ACTIVE', ?,
                ?, GETUTCDATE(),
                GETUTCDATE()
            )";

    $params = [
        $advNumber,
        $advType,
        $subject,
        $bodyText,
        $validStart,
        $validEnd,
        json_encode($facilities),
        json_encode($airports),
        intval($data['priority'] ?? 2),
        $discordMsgIds
    ];

    $stmt = @sqlsrv_query($conn, $sql, $params);

    if ($stmt === false) {
        // Log error but don't fail the Discord post
        error_log('Failed to save advisory to database: ' . print_r(sqlsrv_errors(), true));
        return null;
    }

    // Get inserted ID
    $idResult = sqlsrv_query($conn, "SELECT SCOPE_IDENTITY() as id");
    if ($idResult && $row = sqlsrv_fetch_array($idResult, SQLSRV_FETCH_ASSOC)) {
        return $row['id'];
    }

    return null;
}
