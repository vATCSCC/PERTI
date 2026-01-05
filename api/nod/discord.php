<?php
/**
 * NOD Discord TMI Integration API
 * 
 * PLACEHOLDER - Configure with Discord webhook/bot credentials
 * 
 * This endpoint handles:
 * - Webhook receiver for Discord bot messages
 * - Manual refresh from Discord channel
 * - Status query for Discord TMI data
 * 
 * Configuration required in load/config.php:
 *   define('DISCORD_BOT_TOKEN', 'your-bot-token');
 *   define('DISCORD_GUILD_ID', 'your-guild-id');
 *   define('DISCORD_TMI_CHANNEL_ID', 'your-channel-id');
 *   define('DISCORD_WEBHOOK_SECRET', 'your-webhook-secret');
 */

header('Content-Type: application/json');

// Include database connections
$config_path = realpath(__DIR__ . '/../../load/config.php');
$connect_path = realpath(__DIR__ . '/../../load/connect.php');

if ($config_path) include($config_path);
if ($connect_path) include($connect_path);

// =========================================
// Configuration Check
// =========================================
$DISCORD_CONFIGURED = defined('DISCORD_BOT_TOKEN') && DISCORD_BOT_TOKEN !== '';

// Placeholder configuration values
$CONFIG = [
    'bot_token' => defined('DISCORD_BOT_TOKEN') ? DISCORD_BOT_TOKEN : null,
    'guild_id' => defined('DISCORD_GUILD_ID') ? DISCORD_GUILD_ID : null,
    'channel_id' => defined('DISCORD_TMI_CHANNEL_ID') ? DISCORD_TMI_CHANNEL_ID : null,
    'webhook_secret' => defined('DISCORD_WEBHOOK_SECRET') ? DISCORD_WEBHOOK_SECRET : null,
];

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? 'status';

try {
    switch ($method) {
        case 'GET':
            handleGet($action, $CONFIG, $DISCORD_CONFIGURED, $conn_adl ?? null);
            break;
        case 'POST':
            handlePost($action, $CONFIG, $DISCORD_CONFIGURED, $conn_adl ?? null);
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
 * - action=status: Check Discord integration status
 * - action=list: List Discord TMI entries from database
 * - action=refresh: Trigger manual refresh from Discord
 */
function handleGet($action, $config, $configured, $conn) {
    switch ($action) {
        case 'status':
            echo json_encode([
                'configured' => $configured,
                'status' => $configured ? 'READY' : 'NOT_CONFIGURED',
                'message' => $configured 
                    ? 'Discord integration is configured and ready'
                    : 'Discord integration not configured. Add DISCORD_BOT_TOKEN, DISCORD_GUILD_ID, and DISCORD_TMI_CHANNEL_ID to load/config.php',
                'config_check' => [
                    'bot_token' => !empty($config['bot_token']),
                    'guild_id' => !empty($config['guild_id']),
                    'channel_id' => !empty($config['channel_id']),
                    'webhook_secret' => !empty($config['webhook_secret'])
                ],
                'documentation' => [
                    'setup' => 'See /api/nod/discord.php source for configuration instructions',
                    'endpoints' => [
                        'GET ?action=status' => 'Check integration status',
                        'GET ?action=list' => 'List Discord TMI entries',
                        'GET ?action=refresh' => 'Trigger manual refresh',
                        'POST ?action=webhook' => 'Receive Discord webhook events',
                        'POST ?action=parse' => 'Parse TMI message manually'
                    ]
                ]
            ]);
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
            refreshFromDiscord($config, $conn);
            break;
            
        default:
            http_response_code(400);
            echo json_encode(['error' => 'Unknown action: ' . $action]);
    }
}

/**
 * POST requests
 * - action=webhook: Receive Discord webhook events
 * - action=parse: Parse a TMI message manually
 * - action=end: Mark a Discord TMI as ended
 */
function handlePost($action, $config, $configured, $conn) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    switch ($action) {
        case 'webhook':
            handleWebhook($input, $config, $conn);
            break;
            
        case 'parse':
            parseMessage($input, $conn);
            break;
            
        case 'end':
            endDiscordTMI($input, $conn);
            break;
            
        default:
            http_response_code(400);
            echo json_encode(['error' => 'Unknown action: ' . $action]);
    }
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
        // Table might not exist yet
        echo json_encode([
            'tmis' => [],
            'message' => 'No Discord TMI data available (table may not exist yet)'
        ]);
        return;
    }
    
    $tmis = [];
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        // Format datetime fields
        foreach (['received_at', 'parsed_at', 'ended_at', 'start_time_utc', 'end_time_utc'] as $field) {
            if (isset($row[$field]) && $row[$field] instanceof DateTime) {
                $row[$field] = $row[$field]->format('Y-m-d\TH:i:s\Z');
            }
        }
        $tmis[] = $row;
    }
    
    echo json_encode([
        'tmis' => $tmis,
        'count' => count($tmis)
    ]);
}

/**
 * Refresh TMI data from Discord API
 * PLACEHOLDER - Implement actual Discord API calls
 */
function refreshFromDiscord($config, $conn) {
    // =========================================
    // PLACEHOLDER IMPLEMENTATION
    // Replace with actual Discord API integration
    // =========================================
    
    /*
    // Example Discord API call to fetch channel messages:
    
    $url = "https://discord.com/api/v10/channels/{$config['channel_id']}/messages?limit=50";
    
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            "Authorization: Bot {$config['bot_token']}",
            "Content-Type: application/json"
        ]
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode === 200) {
        $messages = json_decode($response, true);
        foreach ($messages as $msg) {
            $parsed = parseTMIMessage($msg['content']);
            if ($parsed) {
                saveDiscordTMI($conn, $msg, $parsed);
            }
        }
    }
    */
    
    echo json_encode([
        'success' => false,
        'message' => 'Discord refresh not implemented. Configure Discord API credentials and implement refreshFromDiscord() function.',
        'placeholder' => true,
        'required_config' => [
            'DISCORD_BOT_TOKEN' => 'Your Discord bot token',
            'DISCORD_GUILD_ID' => 'Your Discord server ID',
            'DISCORD_TMI_CHANNEL_ID' => 'Channel ID where TMI messages are posted'
        ],
        'example_message_formats' => [
            'Ground Stop' => 'GS KJFK - Weather - 1400Z-1600Z',
            'GDP' => 'GDP KORD - Volume - Max Delay 90min',
            'Reroute' => 'REROUTE: ZNY deps to KATL via J75 MPASS'
        ]
    ]);
}

/**
 * Handle incoming Discord webhook
 * PLACEHOLDER - Implement webhook validation and processing
 */
function handleWebhook($input, $config, $conn) {
    // Validate webhook secret if configured
    if (!empty($config['webhook_secret'])) {
        $signature = $_SERVER['HTTP_X_SIGNATURE_ED25519'] ?? '';
        $timestamp = $_SERVER['HTTP_X_SIGNATURE_TIMESTAMP'] ?? '';
        
        // PLACEHOLDER: Implement Ed25519 signature verification
        // See Discord documentation for webhook verification
    }
    
    echo json_encode([
        'success' => false,
        'message' => 'Webhook handler not fully implemented. This is a placeholder.',
        'received' => $input,
        'instructions' => [
            'step1' => 'Create a Discord bot at https://discord.com/developers/applications',
            'step2' => 'Add bot to your server with MESSAGE_CONTENT intent',
            'step3' => 'Configure webhook URL to point to this endpoint',
            'step4' => 'Add DISCORD_WEBHOOK_SECRET to config for signature verification'
        ]
    ]);
}

/**
 * Parse a TMI message manually
 */
function parseMessage($input, $conn) {
    $content = $input['content'] ?? '';
    
    if (empty($content)) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing message content']);
        return;
    }
    
    $parsed = parseTMIMessage($content);
    
    echo json_encode([
        'original' => $content,
        'parsed' => $parsed,
        'saved' => false,
        'message' => $parsed ? 'Message parsed successfully' : 'Could not parse TMI from message'
    ]);
}

/**
 * Parse TMI information from message content
 * PLACEHOLDER - Implement actual parsing logic based on your Discord message format
 */
function parseTMIMessage($content) {
    $result = [
        'tmi_type' => null,
        'airport' => null,
        'reason' => null,
        'start_time' => null,
        'end_time' => null,
        'details' => null
    ];
    
    $content = trim($content);
    $upperContent = strtoupper($content);
    
    // Example parsing patterns - customize based on your actual message format
    
    // Ground Stop: "GS KJFK - Weather - 1400Z-1600Z"
    if (preg_match('/^GS\s+([A-Z]{4})\s*[-:]\s*(.+?)(?:\s*[-:]\s*(\d{4}Z?)\s*[-to]+\s*(\d{4}Z?))?$/i', $content, $matches)) {
        $result['tmi_type'] = 'GS';
        $result['airport'] = strtoupper($matches[1]);
        $result['reason'] = trim($matches[2]);
        $result['start_time'] = $matches[3] ?? null;
        $result['end_time'] = $matches[4] ?? null;
        return $result;
    }
    
    // Ground Stop: "Ground Stop KJFK"
    if (preg_match('/GROUND\s*STOP\s+([A-Z]{4})/i', $content, $matches)) {
        $result['tmi_type'] = 'GS';
        $result['airport'] = strtoupper($matches[1]);
        $result['reason'] = 'See message for details';
        return $result;
    }
    
    // GDP: "GDP KORD - Volume - Max Delay 90min"
    if (preg_match('/^GDP\s+([A-Z]{4})\s*[-:]\s*(.+)/i', $content, $matches)) {
        $result['tmi_type'] = 'GDP';
        $result['airport'] = strtoupper($matches[1]);
        $result['reason'] = trim($matches[2]);
        return $result;
    }
    
    // Reroute: "REROUTE: ..."
    if (preg_match('/^REROUTE[S]?\s*[:]\s*(.+)/i', $content, $matches)) {
        $result['tmi_type'] = 'REROUTE';
        $result['details'] = trim($matches[1]);
        return $result;
    }
    
    // Stop/End: "GS KJFK CANCELLED" or "GDP KORD ENDED"
    if (preg_match('/(GS|GDP)\s+([A-Z]{4})\s+(CANCEL|END|STOP|PURGE)/i', $content, $matches)) {
        $result['tmi_type'] = strtoupper($matches[1]) . '_END';
        $result['airport'] = strtoupper($matches[2]);
        return $result;
    }
    
    // Could not parse
    return null;
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
    
    if (!$id && !$messageId) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing id or discord_message_id']);
        return;
    }
    
    $sql = "UPDATE dbo.dcc_discord_tmi 
            SET status = 'ENDED', ended_at = GETUTCDATE() 
            WHERE " . ($id ? "id = ?" : "discord_message_id = ?");
    
    $stmt = sqlsrv_query($conn, $sql, [$id ?? $messageId]);
    
    if ($stmt === false) {
        echo json_encode(['error' => 'Database error']);
        return;
    }
    
    $affected = sqlsrv_rows_affected($stmt);
    
    echo json_encode([
        'success' => $affected > 0,
        'affected' => $affected
    ]);
}
