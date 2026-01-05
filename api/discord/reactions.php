<?php
/**
 * Discord Reactions API Endpoint
 *
 * Provides operations for managing reactions on Discord messages.
 *
 * Endpoints:
 *   GET    ?channel_id={id}&message_id={id}&emoji={emoji} - Get users who reacted
 *   POST   - Add a reaction
 *   DELETE ?channel_id={id}&message_id={id}&emoji={emoji} - Remove reaction(s)
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
 * Handle GET requests - Get users who reacted with an emoji
 */
function handleGet($discord) {
    $channelId = $_GET['channel_id'] ?? $_GET['channel'] ?? null;
    $messageId = $_GET['message_id'] ?? null;
    $emoji = $_GET['emoji'] ?? null;

    if (!$channelId || !$messageId || !$emoji) {
        sendError(400, 'Missing required parameters: channel_id, message_id, emoji');
    }

    $options = [
        'limit' => min(100, max(1, (int)($_GET['limit'] ?? 25))),
        'after' => $_GET['after'] ?? null,
    ];

    $users = $discord->getReactions($channelId, $messageId, $emoji, array_filter($options));

    if ($users === null) {
        sendError(500, 'Failed to get reactions', ['error' => $discord->getLastError()]);
    }

    sendSuccess([
        'users' => $users,
        'count' => count($users),
        'emoji' => $emoji,
        'message_id' => $messageId
    ]);
}

/**
 * Handle POST requests - Add a reaction
 *
 * Body:
 *   {
 *     "channel_id": "...",
 *     "message_id": "...",
 *     "emoji": ":thumbsup:" or unicode emoji
 *   }
 */
function handlePost($discord) {
    $input = json_decode(file_get_contents('php://input'), true);

    if (!$input) {
        sendError(400, 'Invalid JSON body');
    }

    $channelId = $input['channel_id'] ?? $input['channel'] ?? null;
    $messageId = $input['message_id'] ?? null;
    $emoji = $input['emoji'] ?? null;

    if (!$channelId || !$messageId || !$emoji) {
        sendError(400, 'Missing required fields: channel_id, message_id, emoji');
    }

    // Handle common emoji aliases
    $emoji = normalizeEmoji($emoji);

    $result = $discord->createReaction($channelId, $messageId, $emoji);

    if (!$result) {
        sendError(500, 'Failed to add reaction', ['error' => $discord->getLastError()]);
    }

    sendSuccess([
        'added' => true,
        'emoji' => $emoji,
        'message_id' => $messageId
    ]);
}

/**
 * Handle DELETE requests - Remove reaction(s)
 *
 * Query params:
 *   channel_id - Channel ID
 *   message_id - Message ID
 *   emoji - Emoji to remove
 *   user_id - (optional) Specific user's reaction to remove
 *   all - (optional) If "true", remove all reactions from message
 *   all_emoji - (optional) If "true", remove all reactions of this emoji
 */
function handleDelete($discord) {
    $channelId = $_GET['channel_id'] ?? $_GET['channel'] ?? null;
    $messageId = $_GET['message_id'] ?? null;
    $emoji = $_GET['emoji'] ?? null;
    $userId = $_GET['user_id'] ?? null;
    $removeAll = ($_GET['all'] ?? '') === 'true';
    $removeAllEmoji = ($_GET['all_emoji'] ?? '') === 'true';

    if (!$channelId || !$messageId) {
        sendError(400, 'Missing required parameters: channel_id, message_id');
    }

    // Remove ALL reactions from message
    if ($removeAll) {
        $result = $discord->deleteAllReactions($channelId, $messageId);

        if (!$result) {
            sendError(500, 'Failed to remove all reactions', ['error' => $discord->getLastError()]);
        }

        sendSuccess([
            'removed' => true,
            'type' => 'all',
            'message_id' => $messageId
        ]);
    }

    // Emoji required for other operations
    if (!$emoji) {
        sendError(400, 'Missing required parameter: emoji (or use all=true)');
    }

    $emoji = normalizeEmoji($emoji);

    // Remove all reactions of a specific emoji
    if ($removeAllEmoji) {
        $result = $discord->deleteAllReactionsForEmoji($channelId, $messageId, $emoji);

        if (!$result) {
            sendError(500, 'Failed to remove emoji reactions', ['error' => $discord->getLastError()]);
        }

        sendSuccess([
            'removed' => true,
            'type' => 'all_emoji',
            'emoji' => $emoji,
            'message_id' => $messageId
        ]);
    }

    // Remove specific user's reaction
    if ($userId) {
        $result = $discord->deleteUserReaction($channelId, $messageId, $emoji, $userId);

        if (!$result) {
            sendError(500, 'Failed to remove user reaction', ['error' => $discord->getLastError()]);
        }

        sendSuccess([
            'removed' => true,
            'type' => 'user',
            'user_id' => $userId,
            'emoji' => $emoji,
            'message_id' => $messageId
        ]);
    }

    // Remove bot's own reaction
    $result = $discord->deleteOwnReaction($channelId, $messageId, $emoji);

    if (!$result) {
        sendError(500, 'Failed to remove own reaction', ['error' => $discord->getLastError()]);
    }

    sendSuccess([
        'removed' => true,
        'type' => 'own',
        'emoji' => $emoji,
        'message_id' => $messageId
    ]);
}

/**
 * Normalize emoji input to Discord format
 *
 * Handles common aliases and formats:
 * - :thumbsup: -> Unicode thumbs up
 * - :custom_emoji:123456 -> custom_emoji:123456
 */
function normalizeEmoji($emoji) {
    // Common emoji aliases
    $aliases = [
        ':thumbsup:' => '👍',
        ':thumbsdown:' => '👎',
        ':heart:' => '❤️',
        ':check:' => '✅',
        ':x:' => '❌',
        ':warning:' => '⚠️',
        ':info:' => 'ℹ️',
        ':question:' => '❓',
        ':exclamation:' => '❗',
        ':star:' => '⭐',
        ':fire:' => '🔥',
        ':rocket:' => '🚀',
        ':airplane:' => '✈️',
        ':plane:' => '✈️',
        ':eyes:' => '👀',
        ':wave:' => '👋',
        ':clap:' => '👏',
        ':+1:' => '👍',
        ':-1:' => '👎',
        ':white_check_mark:' => '✅',
        ':negative_squared_cross_mark:' => '❎',
    ];

    // Check for alias
    $lowerEmoji = strtolower($emoji);
    if (isset($aliases[$lowerEmoji])) {
        return $aliases[$lowerEmoji];
    }

    // Strip colons from simple emoji names if not a custom emoji
    if (preg_match('/^:([a-zA-Z0-9_]+):$/', $emoji, $matches)) {
        // Check if it might be a standard emoji name
        return $emoji;
    }

    return $emoji;
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
