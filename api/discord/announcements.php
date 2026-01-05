<?php
/**
 * Discord Announcements API Endpoint
 *
 * Handles crossposting messages to announcement channel subscribers.
 *
 * Endpoints:
 *   POST - Crosspost a message to announcement channel subscribers
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
        case 'POST':
            handlePost($discord);
            break;
        case 'GET':
            handleGet($discord);
            break;
        default:
            sendError(405, 'Method not allowed');
    }
} catch (Exception $e) {
    sendError(500, 'Internal server error', ['details' => $e->getMessage()]);
}

/**
 * Handle GET requests - Get info about announcement capabilities
 */
function handleGet($discord) {
    $channelId = $_GET['channel_id'] ?? null;

    if ($channelId) {
        // Check if channel is an announcement channel
        $channel = $discord->getChannel($channelId);

        if ($channel === null) {
            sendError(404, 'Channel not found', ['error' => $discord->getLastError()]);
        }

        // Channel type 5 is ANNOUNCEMENT
        $isAnnouncement = ($channel['type'] ?? 0) === 5;

        sendSuccess([
            'channel_id' => $channelId,
            'channel_name' => $channel['name'] ?? null,
            'is_announcement_channel' => $isAnnouncement,
            'can_crosspost' => $isAnnouncement,
            'channel_type' => $channel['type'] ?? null
        ]);
    }

    // Return general info about crossposting
    sendSuccess([
        'info' => 'Crossposting allows messages in announcement channels to be published to all servers following that channel.',
        'usage' => [
            'POST' => [
                'channel_id' => 'The announcement channel ID',
                'message_id' => 'The message ID to crosspost'
            ]
        ],
        'requirements' => [
            'Channel must be an announcement channel (type 5)',
            'Bot must have SEND_MESSAGES permission in the channel',
            'Message must be in the specified channel'
        ]
    ]);
}

/**
 * Handle POST requests - Crosspost a message
 *
 * Body:
 *   {
 *     "channel_id": "...",
 *     "message_id": "..."
 *   }
 *
 * Or send a new message and crosspost it:
 *   {
 *     "channel_id": "...",
 *     "content": "Message to post and crosspost",
 *     "embeds": [...]
 *   }
 */
function handlePost($discord) {
    global $conn_adl;

    $input = json_decode(file_get_contents('php://input'), true);

    if (!$input) {
        sendError(400, 'Invalid JSON body');
    }

    $channelId = $input['channel_id'] ?? $input['channel'] ?? null;
    $messageId = $input['message_id'] ?? null;
    $content = $input['content'] ?? null;
    $embeds = $input['embeds'] ?? null;

    if (!$channelId) {
        sendError(400, 'Missing required field: channel_id');
    }

    // First check if channel is an announcement channel
    $channel = $discord->getChannel($channelId);

    if ($channel === null) {
        sendError(404, 'Channel not found', ['error' => $discord->getLastError()]);
    }

    if (($channel['type'] ?? 0) !== 5) {
        sendError(400, 'Channel is not an announcement channel. Only announcement channels (type 5) support crossposting.', [
            'channel_type' => $channel['type'] ?? null,
            'channel_name' => $channel['name'] ?? null
        ]);
    }

    // If content provided, send a new message first
    if ($content || $embeds) {
        $messageData = [];

        if ($content) {
            // Handle mentions if provided
            if (isset($input['mentions'])) {
                $mentions = $input['mentions'];

                if (!empty($mentions['roles'])) {
                    $roleMentions = array_map(function($id) {
                        return DiscordAPI::mentionRole($id);
                    }, $mentions['roles']);
                    $content = implode(' ', $roleMentions) . ' ' . $content;
                }

                if (!empty($mentions['users'])) {
                    $userMentions = array_map(function($id) {
                        return DiscordAPI::mentionUser($id);
                    }, $mentions['users']);
                    $content = implode(' ', $userMentions) . ' ' . $content;
                }
            }

            $messageData['content'] = $content;
        }

        if ($embeds) {
            $messageData['embeds'] = $embeds;
        }

        // Set allowed mentions
        if (isset($input['mentions'])) {
            $messageData['allowed_mentions'] = DiscordAPI::buildAllowedMentions([
                'users' => $input['mentions']['users'] ?? [],
                'roles' => $input['mentions']['roles'] ?? [],
                'parse' => []
            ]);
        }

        // Send the message
        $sentMessage = $discord->createMessage($channelId, $messageData);

        if ($sentMessage === null) {
            sendError(500, 'Failed to send message', ['error' => $discord->getLastError()]);
        }

        $messageId = $sentMessage['id'];

        // Track in database
        if ($conn_adl) {
            $sql = "INSERT INTO dbo.discord_sent_messages
                    (message_id, channel_id, content, embeds_json, source_type, status, sent_at, created_by)
                    VALUES (?, ?, ?, ?, 'ANNOUNCEMENT', 'SENT', GETUTCDATE(), ?)";

            @sqlsrv_query($conn_adl, $sql, [
                $messageId,
                $channelId,
                $content,
                $embeds ? json_encode($embeds) : null,
                $_SESSION['VATSIM_CID'] ?? 'API'
            ]);
        }
    }

    // Now crosspost
    if (!$messageId) {
        sendError(400, 'Missing message_id (either provide message_id or content to send a new message)');
    }

    $result = $discord->crosspostMessage($channelId, $messageId);

    if ($result === null) {
        sendError(500, 'Failed to crosspost message', [
            'error' => $discord->getLastError(),
            'message_id' => $messageId,
            'note' => 'The message may have been sent but crossposting failed. Check if the message already exists.'
        ]);
    }

    // Update database
    if ($conn_adl) {
        $sql = "UPDATE dbo.discord_sent_messages
                SET is_crossposted = 1, crossposted_at = GETUTCDATE()
                WHERE message_id = ?";
        @sqlsrv_query($conn_adl, $sql, [$messageId]);
    }

    sendSuccess([
        'crossposted' => true,
        'message_id' => $messageId,
        'channel_id' => $channelId,
        'channel_name' => $channel['name'] ?? null,
        'message' => $result
    ]);
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
