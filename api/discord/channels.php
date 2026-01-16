<?php
/**
 * Discord Channels API Endpoint
 *
 * Provides operations for managing Discord channel configuration.
 *
 * Endpoints:
 *   GET    - List configured channels or get channel info
 *   POST   - Configure/update channel purpose mapping
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

// Route request
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    switch ($method) {
        case 'GET':
            handleGet($discord);
            break;
        case 'POST':
            handlePost($discord);
            break;
        default:
            sendError(405, 'Method not allowed');
    }
} catch (Exception $e) {
    sendError(500, 'Internal server error', ['details' => $e->getMessage()]);
}

/**
 * Handle GET requests
 *
 * Query params:
 *   - purpose: Filter by purpose (tmi, advisories, operations, alerts, general)
 *   - channel_id: Get specific channel info from Discord
 *   - sync: If "true", sync channels from Discord guild
 *   - list_guild: If "true", list all channels in the guild
 */
function handleGet($discord) {
    global $conn_adl;

    $purpose = $_GET['purpose'] ?? null;
    $channelId = $_GET['channel_id'] ?? null;
    $sync = ($_GET['sync'] ?? '') === 'true';
    $listGuild = ($_GET['list_guild'] ?? '') === 'true';

    // Get specific channel info from Discord
    if ($channelId) {
        if (!$discord->isConfigured()) {
            sendError(503, 'Discord not configured');
        }

        $channel = $discord->getChannel($channelId);

        if ($channel === null) {
            sendError(404, 'Channel not found', ['error' => $discord->getLastError()]);
        }

        sendSuccess(['channel' => $channel]);
    }

    // List all guild channels from Discord
    if ($listGuild) {
        if (!$discord->isConfigured()) {
            sendError(503, 'Discord not configured');
        }

        $channels = $discord->getGuildChannels();

        if ($channels === null) {
            sendError(500, 'Failed to fetch guild channels', ['error' => $discord->getLastError()]);
        }

        // Format for easier reading
        $formatted = array_map(function($ch) {
            return [
                'id' => $ch['id'],
                'name' => $ch['name'],
                'type' => getChannelTypeName($ch['type']),
                'type_id' => $ch['type'],
                'position' => $ch['position'] ?? 0,
                'parent_id' => $ch['parent_id'] ?? null
            ];
        }, $channels);

        // Sort by position
        usort($formatted, function($a, $b) {
            return $a['position'] - $b['position'];
        });

        sendSuccess([
            'channels' => $formatted,
            'count' => count($formatted)
        ]);
    }

    // Sync channels from Discord (fetch and update database)
    if ($sync) {
        syncChannelsFromDiscord($discord, $conn_adl);
        // Continue to return configured channels below
    }

    // Get configured channels
    $configuredChannels = $discord->getConfiguredChannels();

    // Filter by purpose if specified
    if ($purpose && isset($configuredChannels[$purpose])) {
        $channelId = $configuredChannels[$purpose];

        // Try to get additional info from Discord if configured
        $channelInfo = null;
        if ($discord->isConfigured() && $channelId) {
            $channelInfo = $discord->getChannel($channelId);
        }

        sendSuccess([
            'purpose' => $purpose,
            'channel_id' => $channelId,
            'configured' => !empty($channelId),
            'channel_info' => $channelInfo
        ]);
    }

    // Return all configured channels
    $result = [];
    foreach ($configuredChannels as $purpose => $channelId) {
        $result[$purpose] = [
            'channel_id' => $channelId,
            'configured' => !empty($channelId)
        ];
    }

    // Also get database-stored channels if available
    $dbChannels = getDbChannels($conn_adl);

    sendSuccess([
        'configured_channels' => $result,
        'database_channels' => $dbChannels,
        'discord_configured' => $discord->isConfigured()
    ]);
}

/**
 * Handle POST requests - Configure channel mapping
 *
 * Body:
 *   {
 *     "channel_id": "123456789",
 *     "purpose": "tmi",
 *     "channel_name": "tmi-alerts",
 *     "is_active": true
 *   }
 */
function handlePost($discord) {
    global $conn_adl;

    $input = json_decode(file_get_contents('php://input'), true);

    if (!$input) {
        sendError(400, 'Invalid JSON body');
    }

    $channelId = $input['channel_id'] ?? null;
    $purpose = $input['purpose'] ?? null;

    if (!$channelId || !$purpose) {
        sendError(400, 'Missing required fields: channel_id, purpose');
    }

    // Validate purpose
    $validPurposes = ['tmi', 'advisories', 'operations', 'alerts', 'general'];
    if (!in_array($purpose, $validPurposes)) {
        sendError(400, 'Invalid purpose. Must be one of: ' . implode(', ', $validPurposes));
    }

    // Get channel info from Discord if configured
    $channelInfo = null;
    if ($discord->isConfigured()) {
        $channelInfo = $discord->getChannel($channelId);
    }

    // Store in database
    if ($conn_adl) {
        $channelName = $input['channel_name'] ?? ($channelInfo['name'] ?? 'Unknown');
        $channelType = $channelInfo ? getChannelTypeName($channelInfo['type']) : 'TEXT';
        $guildId = $channelInfo['guild_id'] ?? (defined('DISCORD_GUILD_ID') ? DISCORD_GUILD_ID : '');
        $isAnnouncement = isset($channelInfo['type']) && $channelInfo['type'] === 5;
        $isActive = $input['is_active'] ?? true;

        // Upsert channel configuration
        $sql = "MERGE INTO dbo.discord_channels AS target
                USING (SELECT ? AS channel_id) AS source
                ON target.channel_id = source.channel_id
                WHEN MATCHED THEN
                    UPDATE SET
                        channel_name = ?,
                        channel_type = ?,
                        guild_id = ?,
                        purpose = ?,
                        is_announcement = ?,
                        is_active = ?,
                        updated_at = GETUTCDATE()
                WHEN NOT MATCHED THEN
                    INSERT (channel_id, channel_name, channel_type, guild_id, purpose, is_announcement, is_active, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, GETUTCDATE());";

        $stmt = @sqlsrv_query($conn_adl, $sql, [
            $channelId,
            $channelName,
            $channelType,
            $guildId,
            $purpose,
            $isAnnouncement ? 1 : 0,
            $isActive ? 1 : 0,
            $channelId,
            $channelName,
            $channelType,
            $guildId,
            $purpose,
            $isAnnouncement ? 1 : 0,
            $isActive ? 1 : 0
        ]);

        if ($stmt === false) {
            sendError(500, 'Failed to save channel configuration');
        }
    }

    sendSuccess([
        'saved' => true,
        'channel_id' => $channelId,
        'purpose' => $purpose,
        'channel_info' => $channelInfo,
        'note' => 'Configuration saved to database. To update config.php, manually edit the DISCORD_CHANNELS constant.'
    ]);
}

/**
 * Sync channels from Discord guild
 */
function syncChannelsFromDiscord($discord, $conn) {
    if (!$discord->isConfigured() || !$conn) {
        return;
    }

    $channels = $discord->getGuildChannels();

    if (!$channels) {
        return;
    }

    foreach ($channels as $channel) {
        // Only sync text and announcement channels
        if (!in_array($channel['type'], [0, 5])) {
            continue;
        }

        $sql = "MERGE INTO dbo.discord_channels AS target
                USING (SELECT ? AS channel_id) AS source
                ON target.channel_id = source.channel_id
                WHEN MATCHED THEN
                    UPDATE SET
                        channel_name = ?,
                        channel_type = ?,
                        guild_id = ?,
                        is_announcement = ?,
                        last_sync_utc = GETUTCDATE(),
                        updated_at = GETUTCDATE()
                WHEN NOT MATCHED THEN
                    INSERT (channel_id, channel_name, channel_type, guild_id, purpose, is_announcement, is_active, last_sync_utc, created_at)
                    VALUES (?, ?, ?, ?, 'general', ?, 0, GETUTCDATE(), GETUTCDATE());";

        @sqlsrv_query($conn, $sql, [
            $channel['id'],
            $channel['name'],
            getChannelTypeName($channel['type']),
            $channel['guild_id'] ?? '',
            $channel['type'] === 5 ? 1 : 0,
            $channel['id'],
            $channel['name'],
            getChannelTypeName($channel['type']),
            $channel['guild_id'] ?? '',
            $channel['type'] === 5 ? 1 : 0
        ]);
    }
}

/**
 * Get channels from database
 */
function getDbChannels($conn) {
    if (!$conn) {
        return [];
    }

    $sql = "SELECT * FROM dbo.discord_channels WHERE is_active = 1 ORDER BY purpose";
    $stmt = @sqlsrv_query($conn, $sql);

    if ($stmt === false) {
        return [];
    }

    $channels = [];
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $channels[] = [
            'id' => $row['id'],
            'channel_id' => $row['channel_id'],
            'channel_name' => $row['channel_name'],
            'channel_type' => $row['channel_type'],
            'purpose' => $row['purpose'],
            'is_announcement' => (bool)$row['is_announcement']
        ];
    }

    return $channels;
}

/**
 * Get human-readable channel type name
 */
function getChannelTypeName($type) {
    $types = [
        0 => 'TEXT',
        1 => 'DM',
        2 => 'VOICE',
        3 => 'GROUP_DM',
        4 => 'CATEGORY',
        5 => 'ANNOUNCEMENT',
        10 => 'ANNOUNCEMENT_THREAD',
        11 => 'PUBLIC_THREAD',
        12 => 'PRIVATE_THREAD',
        13 => 'STAGE_VOICE',
        14 => 'DIRECTORY',
        15 => 'FORUM',
        16 => 'MEDIA'
    ];

    return $types[$type] ?? 'UNKNOWN';
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
