<?php
/**
 * NOD Discord TMI Integration API
 *
 * This endpoint provides a simplified interface for Discord TMI operations,
 * built on top of the core Discord integration services.
 *
 * Endpoints:
 *   GET ?action=status  - Check Discord integration status
 *   GET ?action=list    - List Discord TMI entries from database
 *   GET ?action=refresh - Trigger manual refresh from Discord channel
 *   POST ?action=webhook - Receive Discord webhook events (legacy)
 *   POST ?action=parse   - Parse TMI message manually
 *   POST ?action=end     - Mark a Discord TMI as ended
 *   POST ?action=send    - Send a TMI message to Discord
 *
 * For full Discord functionality, see the /api/discord/ endpoints.
 */

header('Content-Type: application/json');

// Include database connections
$config_path = realpath(__DIR__ . '/../../load/config.php');
$connect_path = realpath(__DIR__ . '/../../load/connect.php');

if ($config_path) include($config_path);
if ($connect_path) include($connect_path);

// Include Discord services
$discord_api_path = realpath(__DIR__ . '/../../load/discord/DiscordAPI.php');
$discord_parser_path = realpath(__DIR__ . '/../../load/discord/DiscordMessageParser.php');
$discord_webhook_path = realpath(__DIR__ . '/../../load/discord/DiscordWebhookHandler.php');

if ($discord_api_path) require_once($discord_api_path);
if ($discord_parser_path) require_once($discord_parser_path);
if ($discord_webhook_path) require_once($discord_webhook_path);

// =========================================
// Configuration Check
// =========================================
$DISCORD_CONFIGURED = defined('DISCORD_BOT_TOKEN') && DISCORD_BOT_TOKEN !== '';

// Initialize services
$discordApi = new DiscordAPI();
$messageParser = new DiscordMessageParser();

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? 'status';

try {
    switch ($method) {
        case 'GET':
            handleGet($action, $discordApi, $messageParser, $DISCORD_CONFIGURED, $conn_adl ?? null);
            break;
        case 'POST':
            handlePost($action, $discordApi, $messageParser, $DISCORD_CONFIGURED, $conn_adl ?? null);
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
 * GET request handlers
 */
function handleGet($action, $discordApi, $messageParser, $configured, $conn) {
    switch ($action) {
        case 'status':
            showStatus($discordApi, $configured);
            break;

        case 'list':
            listDiscordTMIs($conn);
            break;

        case 'refresh':
            if (!$configured) {
                echo json_encode([
                    'success' => false,
                    'error' => 'Discord not configured'
                ]);
                return;
            }
            refreshFromDiscord($discordApi, $messageParser, $conn);
            break;

        case 'active':
            listActiveTMIs($conn);
            break;

        default:
            http_response_code(400);
            echo json_encode(['error' => 'Unknown action: ' . $action]);
    }
}

/**
 * POST request handlers
 */
function handlePost($action, $discordApi, $messageParser, $configured, $conn) {
    $input = json_decode(file_get_contents('php://input'), true);

    switch ($action) {
        case 'webhook':
            handleWebhook($input, $conn);
            break;

        case 'parse':
            parseMessage($input, $messageParser);
            break;

        case 'end':
            endDiscordTMI($input, $conn);
            break;

        case 'send':
            if (!$configured) {
                echo json_encode([
                    'success' => false,
                    'error' => 'Discord not configured'
                ]);
                return;
            }
            sendTMIMessage($input, $discordApi, $conn);
            break;

        default:
            http_response_code(400);
            echo json_encode(['error' => 'Unknown action: ' . $action]);
    }
}

/**
 * Show Discord integration status
 */
function showStatus($discordApi, $configured) {
    $channels = $discordApi->getConfiguredChannels();
    $tmiChannel = $channels['tmi'] ?? null;

    echo json_encode([
        'configured' => $configured,
        'status' => $configured ? 'READY' : 'NOT_CONFIGURED',
        'message' => $configured
            ? 'Discord integration is configured and ready'
            : 'Discord integration not configured. Add DISCORD_BOT_TOKEN and other credentials to load/config.php',
        'config_check' => [
            'bot_token' => defined('DISCORD_BOT_TOKEN') && DISCORD_BOT_TOKEN !== '',
            'guild_id' => defined('DISCORD_GUILD_ID') && DISCORD_GUILD_ID !== '',
            'public_key' => defined('DISCORD_PUBLIC_KEY') && DISCORD_PUBLIC_KEY !== '',
            'tmi_channel' => !empty($tmiChannel)
        ],
        'channels' => $channels,
        'endpoints' => [
            'Full Discord API' => '/api/discord/',
            'Messages' => '/api/discord/messages.php',
            'Reactions' => '/api/discord/reactions.php',
            'Channels' => '/api/discord/channels.php',
            'Announcements' => '/api/discord/announcements.php',
            'Webhook' => '/api/discord/webhook.php'
        ],
        'documentation' => [
            'GET ?action=status' => 'Check integration status',
            'GET ?action=list' => 'List Discord TMI entries',
            'GET ?action=active' => 'List active TMIs only',
            'GET ?action=refresh' => 'Trigger manual refresh from Discord',
            'POST ?action=webhook' => 'Receive Discord webhook events',
            'POST ?action=parse' => 'Parse TMI message manually',
            'POST ?action=end' => 'Mark TMI as ended',
            'POST ?action=send' => 'Send TMI message to Discord'
        ]
    ], JSON_PRETTY_PRINT);
}

/**
 * List Discord TMI entries from database
 */
function listDiscordTMIs($conn) {
    if (!$conn) {
        echo json_encode([
            'tmis' => [],
            'error' => 'Database not connected'
        ]);
        return;
    }

    $sql = "SELECT TOP 50 * FROM dbo.dcc_discord_tmi ORDER BY received_at DESC";
    $stmt = @sqlsrv_query($conn, $sql);

    if ($stmt === false) {
        echo json_encode([
            'tmis' => [],
            'message' => 'No Discord TMI data available (table may not exist yet)'
        ]);
        return;
    }

    $tmis = [];
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        // Format datetime fields
        foreach (['received_at', 'ended_at', 'updated_at', 'start_time_utc', 'end_time_utc'] as $field) {
            if (isset($row[$field]) && $row[$field] instanceof DateTime) {
                $row[$field] = $row[$field]->format('Y-m-d\TH:i:s\Z');
            }
        }
        $tmis[] = $row;
    }

    echo json_encode([
        'tmis' => $tmis,
        'count' => count($tmis)
    ], JSON_PRETTY_PRINT);
}

/**
 * List only active TMIs
 */
function listActiveTMIs($conn) {
    if (!$conn) {
        echo json_encode([
            'tmis' => [],
            'error' => 'Database not connected'
        ]);
        return;
    }

    $sql = "SELECT * FROM dbo.dcc_discord_tmi WHERE status = 'ACTIVE' ORDER BY received_at DESC";
    $stmt = @sqlsrv_query($conn, $sql);

    if ($stmt === false) {
        echo json_encode([
            'tmis' => [],
            'message' => 'No active TMI data available'
        ]);
        return;
    }

    $tmis = [];
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        foreach (['received_at', 'ended_at', 'updated_at', 'start_time_utc', 'end_time_utc'] as $field) {
            if (isset($row[$field]) && $row[$field] instanceof DateTime) {
                $row[$field] = $row[$field]->format('Y-m-d\TH:i:s\Z');
            }
        }
        $tmis[] = $row;
    }

    echo json_encode([
        'tmis' => $tmis,
        'count' => count($tmis),
        'status_filter' => 'ACTIVE'
    ], JSON_PRETTY_PRINT);
}

/**
 * Refresh TMI data from Discord API
 */
function refreshFromDiscord($discordApi, $messageParser, $conn) {
    $channels = $discordApi->getConfiguredChannels();
    $tmiChannelId = $channels['tmi'] ?? null;

    if (!$tmiChannelId) {
        echo json_encode([
            'success' => false,
            'error' => 'TMI channel not configured',
            'message' => 'Set the TMI channel ID in DISCORD_CHANNELS configuration'
        ]);
        return;
    }

    // Fetch recent messages from the TMI channel
    $messages = $discordApi->getMessages($tmiChannelId, ['limit' => 50]);

    if ($messages === null) {
        echo json_encode([
            'success' => false,
            'error' => 'Failed to fetch messages from Discord',
            'details' => $discordApi->getLastError()
        ]);
        return;
    }

    $parsed = 0;
    $stored = 0;

    foreach ($messages as $msg) {
        $content = $msg['content'] ?? '';

        if (empty($content)) {
            continue;
        }

        $tmiData = $messageParser->parseTMI($content);

        if ($tmiData) {
            $parsed++;

            // Store in database
            if ($conn && storeTMI($conn, $msg, $tmiData)) {
                $stored++;
            }
        }
    }

    echo json_encode([
        'success' => true,
        'messages_fetched' => count($messages),
        'tmi_parsed' => $parsed,
        'tmi_stored' => $stored,
        'channel_id' => $tmiChannelId
    ], JSON_PRETTY_PRINT);
}

/**
 * Store TMI data in database
 */
function storeTMI($conn, $messageData, $tmiData) {
    // Check if already exists
    $checkSql = "SELECT id FROM dbo.dcc_discord_tmi WHERE discord_message_id = ?";
    $checkStmt = @sqlsrv_query($conn, $checkSql, [$messageData['id'] ?? null]);

    if ($checkStmt && sqlsrv_fetch_array($checkStmt)) {
        // Already exists, update instead
        $updateSql = "UPDATE dbo.dcc_discord_tmi
                      SET tmi_type = ?, airport = ?, reason = ?, details = ?, raw_message = ?, updated_at = GETUTCDATE()
                      WHERE discord_message_id = ?";

        $stmt = @sqlsrv_query($conn, $updateSql, [
            $tmiData['tmi_type'] ?? null,
            $tmiData['airport'] ?? null,
            $tmiData['reason'] ?? null,
            $tmiData['details'] ?? null,
            $tmiData['raw'] ?? ($messageData['content'] ?? ''),
            $messageData['id'] ?? null
        ]);

        return $stmt !== false;
    }

    // Insert new record
    $sql = "INSERT INTO dbo.dcc_discord_tmi
            (discord_message_id, tmi_type, airport, facility, reason, details, raw_message, status, received_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, GETUTCDATE())";

    $stmt = @sqlsrv_query($conn, $sql, [
        $messageData['id'] ?? null,
        $tmiData['tmi_type'] ?? null,
        $tmiData['airport'] ?? null,
        $tmiData['facility'] ?? null,
        $tmiData['reason'] ?? null,
        $tmiData['details'] ?? null,
        $tmiData['raw'] ?? ($messageData['content'] ?? ''),
        $tmiData['status'] ?? 'ACTIVE'
    ]);

    return $stmt !== false;
}

/**
 * Handle incoming Discord webhook (legacy - use /api/discord/webhook.php instead)
 */
function handleWebhook($input, $conn) {
    // Redirect to the new webhook handler
    echo json_encode([
        'success' => false,
        'message' => 'This endpoint is deprecated. Use /api/discord/webhook.php for webhook handling.',
        'redirect' => '/api/discord/webhook.php'
    ]);
}

/**
 * Parse a TMI message manually
 */
function parseMessage($input, $messageParser) {
    $content = $input['content'] ?? '';

    if (empty($content)) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing message content']);
        return;
    }

    $parsed = $messageParser->parseTMI($content);

    // Also try advisory parsing
    $advisory = $messageParser->parseAdvisory($content);

    // Extract additional info
    $airports = $messageParser->extractAirportCodes($content);
    $facilities = $messageParser->extractFacilityCodes($content);
    $mentions = $messageParser->extractMentions($content);
    $roles = $messageParser->extractRoleMentions($content);

    echo json_encode([
        'original' => $content,
        'tmi' => $parsed,
        'advisory' => $advisory,
        'extracted' => [
            'airports' => $airports,
            'facilities' => $facilities,
            'user_mentions' => $mentions,
            'role_mentions' => $roles
        ],
        'is_tmi' => $parsed !== null,
        'message' => $parsed ? 'Message parsed as TMI' : ($advisory ? 'Message parsed as advisory' : 'Could not parse TMI or advisory from message')
    ], JSON_PRETTY_PRINT);
}

/**
 * Mark a Discord TMI as ended
 */
function endDiscordTMI($input, $conn) {
    if (!$conn) {
        echo json_encode(['error' => 'Database not connected']);
        return;
    }

    $id = $input['id'] ?? null;
    $messageId = $input['discord_message_id'] ?? null;
    $airport = $input['airport'] ?? null;
    $tmiType = $input['tmi_type'] ?? null;

    // Build WHERE clause based on provided identifiers
    $whereClauses = [];
    $params = [];

    if ($id) {
        $whereClauses[] = "id = ?";
        $params[] = $id;
    }
    if ($messageId) {
        $whereClauses[] = "discord_message_id = ?";
        $params[] = $messageId;
    }
    if ($airport && $tmiType) {
        $whereClauses[] = "(airport = ? AND tmi_type = ? AND status = 'ACTIVE')";
        $params[] = $airport;
        $params[] = $tmiType;
    }

    if (empty($whereClauses)) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing identifier: provide id, discord_message_id, or airport+tmi_type']);
        return;
    }

    $sql = "UPDATE dbo.dcc_discord_tmi
            SET status = 'ENDED', ended_at = GETUTCDATE()
            WHERE " . implode(' OR ', $whereClauses);

    $stmt = sqlsrv_query($conn, $sql, $params);

    if ($stmt === false) {
        echo json_encode(['error' => 'Database error']);
        return;
    }

    $affected = sqlsrv_rows_affected($stmt);

    echo json_encode([
        'success' => $affected > 0,
        'affected' => $affected,
        'message' => $affected > 0 ? 'TMI marked as ended' : 'No matching TMI found'
    ]);
}

/**
 * Send a TMI message to Discord
 */
function sendTMIMessage($input, $discordApi, $conn) {
    $content = $input['content'] ?? null;
    $tmiType = $input['tmi_type'] ?? null;
    $airport = $input['airport'] ?? null;

    if (!$content && (!$tmiType || !$airport)) {
        http_response_code(400);
        echo json_encode(['error' => 'Provide content or tmi_type+airport']);
        return;
    }

    // Build message content if not provided
    if (!$content && $tmiType && $airport) {
        $reason = $input['reason'] ?? '';
        $content = "{$tmiType} {$airport}";
        if ($reason) {
            $content .= " - {$reason}";
        }
    }

    // Get TMI channel
    $channels = $discordApi->getConfiguredChannels();
    $tmiChannelId = $channels['tmi'] ?? null;

    if (!$tmiChannelId) {
        echo json_encode([
            'success' => false,
            'error' => 'TMI channel not configured'
        ]);
        return;
    }

    // Build message data
    $messageData = ['content' => $content];

    // Add role mentions if specified
    if (!empty($input['mention_roles'])) {
        $roleMentions = array_map(function($id) {
            return DiscordAPI::mentionRole($id);
        }, $input['mention_roles']);
        $messageData['content'] = implode(' ', $roleMentions) . ' ' . $messageData['content'];
        $messageData['allowed_mentions'] = DiscordAPI::buildAllowedMentions([
            'roles' => $input['mention_roles']
        ]);
    }

    // Send the message
    $result = $discordApi->createMessage($tmiChannelId, $messageData);

    if ($result === null) {
        echo json_encode([
            'success' => false,
            'error' => 'Failed to send message',
            'details' => $discordApi->getLastError()
        ]);
        return;
    }

    // Store TMI in database
    if ($conn) {
        $tmiData = [
            'tmi_type' => $tmiType,
            'airport' => $airport,
            'reason' => $input['reason'] ?? null,
            'details' => $input['details'] ?? null,
            'raw' => $content,
            'status' => 'ACTIVE'
        ];
        storeTMI($conn, $result, $tmiData);
    }

    echo json_encode([
        'success' => true,
        'message_id' => $result['id'] ?? null,
        'channel_id' => $tmiChannelId,
        'content' => $content
    ], JSON_PRETTY_PRINT);
}
