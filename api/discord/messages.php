<?php
/**
 * Discord Messages API Endpoint
 *
 * Provides CRUD operations for Discord messages.
 *
 * Endpoints:
 *   GET    ?channel={id|purpose}&limit=50  - Fetch messages from channel
 *   GET    ?channel={id|purpose}&message_id={id} - Get specific message
 *   POST   - Send a new message
 *   PUT    - Edit an existing message
 *   DELETE ?channel_id={id}&message_id={id} - Delete a message
 */

header('Content-Type: application/json; charset=utf-8');

// Include dependencies
$config_path = realpath(__DIR__ . '/../../load/config.php');
$connect_path = realpath(__DIR__ . '/../../load/connect.php');
$discord_api_path = realpath(__DIR__ . '/../../load/discord/DiscordAPI.php');

if ($config_path) require_once($config_path);
if ($connect_path) require_once($connect_path);
if ($discord_api_path) require_once($discord_api_path);

// Initialize Discord API
$discord = new DiscordAPI();

// Check configuration
if (!$discord->isConfigured()) {
    sendError(503, 'Discord integration not configured', [
        'message' => 'Please configure DISCORD_BOT_TOKEN in load/config.php'
    ]);
}

// Route request
$method = $_SERVER['REQUEST_METHOD'];

try {
    switch ($method) {
        case 'GET':
            handleGet($discord);
            break;
        case 'POST':
            handlePost($discord);
            break;
        case 'PUT':
            handlePut($discord);
            break;
        case 'DELETE':
            handleDelete($discord);
            break;
        default:
            sendError(405, 'Method not allowed');
    }
} catch (Exception $e) {
    sendError(500, 'Internal server error', ['details' => $e->getMessage()]);
}

/**
 * Handle GET requests - Fetch messages
 */
function handleGet($discord) {
    $channel = $_GET['channel'] ?? null;
    $messageId = $_GET['message_id'] ?? null;

    if (!$channel) {
        sendError(400, 'Missing required parameter: channel');
    }

    // Get specific message
    if ($messageId) {
        $message = $discord->getMessage($channel, $messageId);

        if ($message === null) {
            sendError(404, 'Message not found', ['error' => $discord->getLastError()]);
        }

        sendSuccess($message);
    }

    // Get multiple messages
    $options = [
        'limit' => min(100, max(1, (int)($_GET['limit'] ?? 50))),
        'before' => $_GET['before'] ?? null,
        'after' => $_GET['after'] ?? null,
        'around' => $_GET['around'] ?? null,
    ];

    $messages = $discord->getMessages($channel, array_filter($options));

    if ($messages === null) {
        sendError(500, 'Failed to fetch messages', ['error' => $discord->getLastError()]);
    }

    sendSuccess([
        'messages' => $messages,
        'count' => count($messages),
        'channel' => $channel
    ]);
}

/**
 * Handle POST requests - Send a new message
 *
 * Body:
 *   {
 *     "channel": "tmi" or channel ID,
 *     "content": "Message text",
 *     "embeds": [...],
 *     "mentions": {
 *       "users": ["user_id"],
 *       "roles": ["role_id"]
 *     },
 *     "crosspost": true
 *   }
 */
function handlePost($discord) {
    global $conn_adl;

    $input = json_decode(file_get_contents('php://input'), true);

    if (!$input) {
        sendError(400, 'Invalid JSON body');
    }

    $channel = $input['channel'] ?? null;
    $content = $input['content'] ?? null;
    $embeds = $input['embeds'] ?? null;

    if (!$channel) {
        sendError(400, 'Missing required field: channel');
    }

    if (empty($content) && empty($embeds)) {
        sendError(400, 'Message must have content or embeds');
    }

    // Build message data
    $messageData = [];

    if ($content) {
        // Process mentions in content
        if (isset($input['mentions'])) {
            $mentions = $input['mentions'];

            // Prepend user mentions
            if (!empty($mentions['users'])) {
                $userMentions = array_map(function($id) {
                    return DiscordAPI::mentionUser($id);
                }, $mentions['users']);
                $content = implode(' ', $userMentions) . ' ' . $content;
            }

            // Prepend role mentions
            if (!empty($mentions['roles'])) {
                $roleMentions = array_map(function($id) {
                    return DiscordAPI::mentionRole($id);
                }, $mentions['roles']);
                $content = implode(' ', $roleMentions) . ' ' . $content;
            }
        }

        $messageData['content'] = $content;
    }

    if ($embeds) {
        $messageData['embeds'] = $embeds;
    }

    // Set allowed mentions to control what can be mentioned
    if (isset($input['mentions'])) {
        $messageData['allowed_mentions'] = DiscordAPI::buildAllowedMentions([
            'users' => $input['mentions']['users'] ?? [],
            'roles' => $input['mentions']['roles'] ?? [],
            'parse' => [] // Don't parse @everyone/@here
        ]);
    } else {
        // Default: don't allow any special mentions
        $messageData['allowed_mentions'] = ['parse' => []];
    }

    // Reply reference
    if (isset($input['reply_to'])) {
        $messageData['message_reference'] = [
            'message_id' => $input['reply_to']
        ];
    }

    // Send message
    $result = $discord->createMessage($channel, $messageData);

    if ($result === null) {
        sendError(500, 'Failed to send message', ['error' => $discord->getLastError()]);
    }

    // Track sent message in database
    $sentId = trackSentMessage($conn_adl, $result, $channel, $input);

    // Crosspost if requested and channel is announcement channel
    $crossposted = false;
    if (!empty($input['crosspost']) && isset($result['id'])) {
        $crosspostResult = $discord->crosspostMessage($channel, $result['id']);
        $crossposted = $crosspostResult !== null;

        if ($crossposted && $conn_adl) {
            // Update database with crosspost status
            $sql = "UPDATE dbo.discord_sent_messages SET is_crossposted = 1, crossposted_at = GETUTCDATE() WHERE id = ?";
            @sqlsrv_query($conn_adl, $sql, [$sentId]);
        }
    }

    sendSuccess([
        'message' => $result,
        'message_id' => $result['id'] ?? null,
        'crossposted' => $crossposted,
        'tracked_id' => $sentId
    ]);
}

/**
 * Handle PUT requests - Edit an existing message
 *
 * Body:
 *   {
 *     "channel_id": "...",
 *     "message_id": "...",
 *     "content": "Updated content",
 *     "embeds": [...]
 *   }
 */
function handlePut($discord) {
    $input = json_decode(file_get_contents('php://input'), true);

    if (!$input) {
        sendError(400, 'Invalid JSON body');
    }

    $channelId = $input['channel_id'] ?? $input['channel'] ?? null;
    $messageId = $input['message_id'] ?? null;

    if (!$channelId || !$messageId) {
        sendError(400, 'Missing required fields: channel_id, message_id');
    }

    // Build update data
    $updateData = [];

    if (isset($input['content'])) {
        $updateData['content'] = $input['content'];
    }

    if (isset($input['embeds'])) {
        $updateData['embeds'] = $input['embeds'];
    }

    if (empty($updateData)) {
        sendError(400, 'No update data provided');
    }

    $result = $discord->editMessage($channelId, $messageId, $updateData);

    if ($result === null) {
        sendError(500, 'Failed to edit message', ['error' => $discord->getLastError()]);
    }

    sendSuccess([
        'message' => $result,
        'message_id' => $result['id'] ?? null,
        'edited' => true
    ]);
}

/**
 * Handle DELETE requests - Delete a message
 */
function handleDelete($discord) {
    $channelId = $_GET['channel_id'] ?? null;
    $messageId = $_GET['message_id'] ?? null;

    if (!$channelId || !$messageId) {
        sendError(400, 'Missing required parameters: channel_id, message_id');
    }

    $result = $discord->deleteMessage($channelId, $messageId);

    if (!$result) {
        sendError(500, 'Failed to delete message', ['error' => $discord->getLastError()]);
    }

    sendSuccess([
        'deleted' => true,
        'message_id' => $messageId
    ]);
}

/**
 * Track sent message in database
 */
function trackSentMessage($conn, $result, $channel, $input) {
    if (!$conn) {
        return null;
    }

    $sql = "INSERT INTO dbo.discord_sent_messages
            (message_id, channel_id, content, embeds_json, source_type, source_id, status, sent_at, created_by)
            VALUES (?, ?, ?, ?, ?, ?, 'SENT', GETUTCDATE(), ?)";

    $createdBy = $_SESSION['VATSIM_CID'] ?? 'API';

    $stmt = @sqlsrv_query($conn, $sql, [
        $result['id'] ?? null,
        $result['channel_id'] ?? $channel,
        $input['content'] ?? null,
        !empty($input['embeds']) ? json_encode($input['embeds']) : null,
        $input['source_type'] ?? 'MANUAL',
        $input['source_id'] ?? null,
        $createdBy
    ]);

    if ($stmt === false) {
        return null;
    }

    // Get inserted ID
    $idResult = sqlsrv_query($conn, "SELECT SCOPE_IDENTITY() AS id");
    if ($idResult && $row = sqlsrv_fetch_array($idResult, SQLSRV_FETCH_ASSOC)) {
        return (int)$row['id'];
    }

    return null;
}

/**
 * Send success response
 */
function sendSuccess($data) {
    http_response_code(200);
    echo json_encode(array_merge(['success' => true], $data), JSON_PRETTY_PRINT);
    exit;
}

/**
 * Send error response
 */
function sendError($code, $message, $details = null) {
    http_response_code($code);
    $response = [
        'success' => false,
        'error' => $message,
        'error_code' => $code
    ];
    if ($details) {
        $response = array_merge($response, $details);
    }
    echo json_encode($response, JSON_PRETTY_PRINT);
    exit;
}
