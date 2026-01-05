<?php
/**
 * Discord Webhook Handler
 *
 * Processes incoming webhook events from Discord Gateway/Interactions.
 * Implements Ed25519 signature verification for security.
 *
 * Discord Interactions Documentation:
 * https://discord.com/developers/docs/interactions/receiving-and-responding
 */

class DiscordWebhookHandler {
    private $publicKey;
    private $conn;
    private $lastError = null;

    // Event type constants
    const EVENT_PING = 1;
    const EVENT_APPLICATION_COMMAND = 2;
    const EVENT_MESSAGE_COMPONENT = 3;
    const EVENT_APPLICATION_COMMAND_AUTOCOMPLETE = 4;
    const EVENT_MODAL_SUBMIT = 5;

    // Gateway event types
    const GATEWAY_MESSAGE_CREATE = 'MESSAGE_CREATE';
    const GATEWAY_MESSAGE_UPDATE = 'MESSAGE_UPDATE';
    const GATEWAY_MESSAGE_DELETE = 'MESSAGE_DELETE';
    const GATEWAY_MESSAGE_REACTION_ADD = 'MESSAGE_REACTION_ADD';
    const GATEWAY_MESSAGE_REACTION_REMOVE = 'MESSAGE_REACTION_REMOVE';
    const GATEWAY_MESSAGE_REACTION_REMOVE_ALL = 'MESSAGE_REACTION_REMOVE_ALL';

    /**
     * Constructor
     *
     * @param string|null $publicKey Discord application public key
     * @param mixed $dbConnection Database connection (sqlsrv)
     */
    public function __construct($publicKey = null, $dbConnection = null) {
        $this->publicKey = $publicKey ?? (defined('DISCORD_PUBLIC_KEY') ? DISCORD_PUBLIC_KEY : '');
        $this->conn = $dbConnection;
    }

    /**
     * Get last error message
     *
     * @return string|null
     */
    public function getLastError() {
        return $this->lastError;
    }

    /**
     * Verify webhook signature using Ed25519
     *
     * @param string $signature X-Signature-Ed25519 header (hex encoded)
     * @param string $timestamp X-Signature-Timestamp header
     * @param string $body Raw request body
     * @return bool True if signature is valid
     */
    public function verifySignature($signature, $timestamp, $body) {
        if (empty($this->publicKey)) {
            $this->lastError = 'Public key not configured';
            return false;
        }

        if (empty($signature) || empty($timestamp)) {
            $this->lastError = 'Missing signature or timestamp header';
            return false;
        }

        try {
            // Convert hex strings to binary
            $publicKeyBin = hex2bin($this->publicKey);
            $signatureBin = hex2bin($signature);

            if ($publicKeyBin === false || $signatureBin === false) {
                $this->lastError = 'Invalid hex encoding in signature or public key';
                return false;
            }

            // Message to verify is timestamp + body
            $message = $timestamp . $body;

            // Verify using sodium extension (PHP 7.2+)
            if (function_exists('sodium_crypto_sign_verify_detached')) {
                return sodium_crypto_sign_verify_detached($signatureBin, $message, $publicKeyBin);
            }

            // Fallback error if sodium not available
            $this->lastError = 'Sodium extension not available for signature verification';
            return false;

        } catch (Exception $e) {
            $this->lastError = 'Signature verification error: ' . $e->getMessage();
            return false;
        }
    }

    /**
     * Process incoming webhook payload
     *
     * @param array $payload Decoded JSON payload from Discord
     * @return array Response to send back to Discord
     */
    public function handleEvent($payload) {
        // Log the incoming webhook
        $this->logWebhook($payload);

        // Handle based on type
        $type = $payload['type'] ?? null;

        switch ($type) {
            case self::EVENT_PING:
                return $this->handlePing($payload);

            case self::EVENT_APPLICATION_COMMAND:
                return $this->handleApplicationCommand($payload);

            case self::EVENT_MESSAGE_COMPONENT:
                return $this->handleMessageComponent($payload);

            default:
                // Handle gateway events (MESSAGE_CREATE, etc.)
                if (isset($payload['t'])) {
                    return $this->handleGatewayEvent($payload);
                }

                return [
                    'success' => false,
                    'error' => 'Unknown event type: ' . ($type ?? 'null')
                ];
        }
    }

    /**
     * Handle Discord PING (verification challenge)
     *
     * @param array $payload
     * @return array Response with type 1
     */
    private function handlePing($payload) {
        // Discord requires a type: 1 response for PING
        return ['type' => 1];
    }

    /**
     * Handle application command (slash commands)
     *
     * @param array $payload
     * @return array
     */
    private function handleApplicationCommand($payload) {
        // Placeholder for slash command handling
        // Implement specific commands as needed
        return [
            'type' => 4, // CHANNEL_MESSAGE_WITH_SOURCE
            'data' => [
                'content' => 'Command received but not implemented.'
            ]
        ];
    }

    /**
     * Handle message component interaction (buttons, selects)
     *
     * @param array $payload
     * @return array
     */
    private function handleMessageComponent($payload) {
        // Placeholder for component handling
        return [
            'type' => 6 // DEFERRED_UPDATE_MESSAGE
        ];
    }

    /**
     * Handle gateway events (MESSAGE_CREATE, etc.)
     *
     * @param array $payload Gateway event payload
     * @return array
     */
    private function handleGatewayEvent($payload) {
        $eventType = $payload['t'] ?? 'UNKNOWN';
        $data = $payload['d'] ?? [];

        switch ($eventType) {
            case self::GATEWAY_MESSAGE_CREATE:
                return $this->handleMessageCreate($data);

            case self::GATEWAY_MESSAGE_UPDATE:
                return $this->handleMessageUpdate($data);

            case self::GATEWAY_MESSAGE_DELETE:
                return $this->handleMessageDelete($data);

            case self::GATEWAY_MESSAGE_REACTION_ADD:
                return $this->handleReactionAdd($data);

            case self::GATEWAY_MESSAGE_REACTION_REMOVE:
                return $this->handleReactionRemove($data);

            case self::GATEWAY_MESSAGE_REACTION_REMOVE_ALL:
                return $this->handleReactionRemoveAll($data);

            default:
                return [
                    'success' => true,
                    'message' => "Event {$eventType} acknowledged but not processed"
                ];
        }
    }

    /**
     * Handle MESSAGE_CREATE event
     *
     * @param array $data Message data
     * @return array
     */
    private function handleMessageCreate($data) {
        // Store message in database
        $messageId = $this->storeMessage($data);

        // Parse for TMI content
        $tmiData = $this->parseTMIContent($data['content'] ?? '');

        if ($tmiData) {
            $this->storeTMI($data, $tmiData);
        }

        return [
            'success' => true,
            'event' => 'MESSAGE_CREATE',
            'message_id' => $data['id'] ?? null,
            'stored_id' => $messageId,
            'tmi_detected' => $tmiData !== null
        ];
    }

    /**
     * Handle MESSAGE_UPDATE event
     *
     * @param array $data Updated message data
     * @return array
     */
    private function handleMessageUpdate($data) {
        // Update stored message
        $updated = $this->updateStoredMessage($data);

        // Re-parse TMI content if message content changed
        if (isset($data['content'])) {
            $tmiData = $this->parseTMIContent($data['content']);
            if ($tmiData) {
                $this->updateTMI($data, $tmiData);
            }
        }

        return [
            'success' => true,
            'event' => 'MESSAGE_UPDATE',
            'message_id' => $data['id'] ?? null,
            'updated' => $updated
        ];
    }

    /**
     * Handle MESSAGE_DELETE event
     *
     * @param array $data Deleted message data
     * @return array
     */
    private function handleMessageDelete($data) {
        // Mark message as deleted in database
        $deleted = $this->markMessageDeleted($data['id'] ?? null);

        return [
            'success' => true,
            'event' => 'MESSAGE_DELETE',
            'message_id' => $data['id'] ?? null,
            'marked_deleted' => $deleted
        ];
    }

    /**
     * Handle MESSAGE_REACTION_ADD event
     *
     * @param array $data Reaction data
     * @return array
     */
    private function handleReactionAdd($data) {
        $stored = $this->storeReaction($data);

        return [
            'success' => true,
            'event' => 'MESSAGE_REACTION_ADD',
            'message_id' => $data['message_id'] ?? null,
            'emoji' => $data['emoji']['name'] ?? null,
            'stored' => $stored
        ];
    }

    /**
     * Handle MESSAGE_REACTION_REMOVE event
     *
     * @param array $data Reaction data
     * @return array
     */
    private function handleReactionRemove($data) {
        $removed = $this->removeReaction($data);

        return [
            'success' => true,
            'event' => 'MESSAGE_REACTION_REMOVE',
            'message_id' => $data['message_id'] ?? null,
            'emoji' => $data['emoji']['name'] ?? null,
            'removed' => $removed
        ];
    }

    /**
     * Handle MESSAGE_REACTION_REMOVE_ALL event
     *
     * @param array $data
     * @return array
     */
    private function handleReactionRemoveAll($data) {
        $removed = $this->removeAllReactions($data['message_id'] ?? null);

        return [
            'success' => true,
            'event' => 'MESSAGE_REACTION_REMOVE_ALL',
            'message_id' => $data['message_id'] ?? null,
            'removed' => $removed
        ];
    }

    // =========================================
    // DATABASE OPERATIONS
    // =========================================

    /**
     * Log incoming webhook to database
     *
     * @param array $payload Full webhook payload
     * @return int|null Inserted ID
     */
    private function logWebhook($payload) {
        if (!$this->conn) {
            return null;
        }

        $eventType = $payload['t'] ?? ($payload['type'] === 1 ? 'PING' : 'INTERACTION');
        $eventId = $payload['id'] ?? null;

        $sql = "INSERT INTO dbo.discord_webhook_log
                (event_type, event_id, payload_json, signature_valid, received_at)
                VALUES (?, ?, ?, 1, GETUTCDATE())";

        $stmt = @sqlsrv_query($this->conn, $sql, [
            $eventType,
            $eventId,
            json_encode($payload)
        ]);

        if ($stmt === false) {
            return null;
        }

        // Get inserted ID
        $idResult = sqlsrv_query($this->conn, "SELECT SCOPE_IDENTITY() AS id");
        if ($idResult && $row = sqlsrv_fetch_array($idResult, SQLSRV_FETCH_ASSOC)) {
            return (int)$row['id'];
        }

        return null;
    }

    /**
     * Store message in database
     *
     * @param array $messageData Discord message object
     * @return int|null Inserted ID
     */
    private function storeMessage($messageData) {
        if (!$this->conn) {
            return null;
        }

        $sql = "INSERT INTO dbo.discord_messages
                (message_id, channel_id, guild_id, author_id, author_username, author_bot,
                 content, embeds_json, message_type, reference_message_id,
                 discord_timestamp, received_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, GETUTCDATE())";

        $author = $messageData['author'] ?? [];
        $timestamp = $messageData['timestamp'] ?? null;

        // Convert ISO timestamp to datetime
        $discordTime = $timestamp ? date('Y-m-d H:i:s', strtotime($timestamp)) : date('Y-m-d H:i:s');

        $stmt = @sqlsrv_query($this->conn, $sql, [
            $messageData['id'] ?? null,
            $messageData['channel_id'] ?? null,
            $messageData['guild_id'] ?? null,
            $author['id'] ?? null,
            $author['username'] ?? null,
            isset($author['bot']) && $author['bot'] ? 1 : 0,
            $messageData['content'] ?? '',
            !empty($messageData['embeds']) ? json_encode($messageData['embeds']) : null,
            $messageData['type'] ?? 0,
            $messageData['message_reference']['message_id'] ?? null,
            $discordTime
        ]);

        if ($stmt === false) {
            return null;
        }

        // Get inserted ID
        $idResult = sqlsrv_query($this->conn, "SELECT SCOPE_IDENTITY() AS id");
        if ($idResult && $row = sqlsrv_fetch_array($idResult, SQLSRV_FETCH_ASSOC)) {
            return (int)$row['id'];
        }

        return null;
    }

    /**
     * Update stored message
     *
     * @param array $messageData Updated message data
     * @return bool
     */
    private function updateStoredMessage($messageData) {
        if (!$this->conn || empty($messageData['id'])) {
            return false;
        }

        $editedTimestamp = $messageData['edited_timestamp'] ?? null;
        $editedTime = $editedTimestamp ? date('Y-m-d H:i:s', strtotime($editedTimestamp)) : date('Y-m-d H:i:s');

        $sql = "UPDATE dbo.discord_messages
                SET content = ?,
                    embeds_json = ?,
                    edited_timestamp = ?
                WHERE message_id = ?";

        $stmt = @sqlsrv_query($this->conn, $sql, [
            $messageData['content'] ?? '',
            !empty($messageData['embeds']) ? json_encode($messageData['embeds']) : null,
            $editedTime,
            $messageData['id']
        ]);

        return $stmt !== false;
    }

    /**
     * Mark message as deleted
     *
     * @param string $messageId Discord message ID
     * @return bool
     */
    private function markMessageDeleted($messageId) {
        if (!$this->conn || empty($messageId)) {
            return false;
        }

        $sql = "UPDATE dbo.discord_messages
                SET is_deleted = 1, deleted_at = GETUTCDATE()
                WHERE message_id = ?";

        $stmt = @sqlsrv_query($this->conn, $sql, [$messageId]);
        return $stmt !== false;
    }

    /**
     * Store reaction in database
     *
     * @param array $reactionData Reaction event data
     * @return bool
     */
    private function storeReaction($reactionData) {
        if (!$this->conn) {
            return false;
        }

        $emoji = $reactionData['emoji'] ?? [];
        $emojiStr = $emoji['id'] ? "{$emoji['name']}:{$emoji['id']}" : $emoji['name'];

        $sql = "MERGE INTO dbo.discord_reactions AS target
                USING (SELECT ? AS message_id, ? AS channel_id, ? AS user_id, ? AS emoji, ? AS emoji_id) AS source
                ON target.message_id = source.message_id
                   AND target.user_id = source.user_id
                   AND target.emoji = source.emoji
                WHEN MATCHED THEN
                    UPDATE SET is_active = 1, removed_at = NULL
                WHEN NOT MATCHED THEN
                    INSERT (message_id, channel_id, user_id, emoji, emoji_id, added_at)
                    VALUES (source.message_id, source.channel_id, source.user_id, source.emoji, source.emoji_id, GETUTCDATE());";

        $stmt = @sqlsrv_query($this->conn, $sql, [
            $reactionData['message_id'] ?? null,
            $reactionData['channel_id'] ?? null,
            $reactionData['user_id'] ?? null,
            $emojiStr,
            $emoji['id'] ?? null
        ]);

        return $stmt !== false;
    }

    /**
     * Remove reaction from database
     *
     * @param array $reactionData Reaction event data
     * @return bool
     */
    private function removeReaction($reactionData) {
        if (!$this->conn) {
            return false;
        }

        $emoji = $reactionData['emoji'] ?? [];
        $emojiStr = $emoji['id'] ? "{$emoji['name']}:{$emoji['id']}" : $emoji['name'];

        $sql = "UPDATE dbo.discord_reactions
                SET is_active = 0, removed_at = GETUTCDATE()
                WHERE message_id = ? AND user_id = ? AND emoji = ?";

        $stmt = @sqlsrv_query($this->conn, $sql, [
            $reactionData['message_id'] ?? null,
            $reactionData['user_id'] ?? null,
            $emojiStr
        ]);

        return $stmt !== false;
    }

    /**
     * Remove all reactions for a message
     *
     * @param string $messageId Message ID
     * @return bool
     */
    private function removeAllReactions($messageId) {
        if (!$this->conn || empty($messageId)) {
            return false;
        }

        $sql = "UPDATE dbo.discord_reactions
                SET is_active = 0, removed_at = GETUTCDATE()
                WHERE message_id = ?";

        $stmt = @sqlsrv_query($this->conn, $sql, [$messageId]);
        return $stmt !== false;
    }

    // =========================================
    // TMI PARSING AND STORAGE
    // =========================================

    /**
     * Parse TMI content from message
     *
     * @param string $content Message content
     * @return array|null Parsed TMI data or null
     */
    private function parseTMIContent($content) {
        // Use DiscordMessageParser if available
        if (class_exists('DiscordMessageParser')) {
            $parser = new DiscordMessageParser();
            return $parser->parseTMI($content);
        }

        // Basic inline parsing as fallback
        $content = trim($content);
        $upperContent = strtoupper($content);

        // Ground Stop: "GS KJFK" or "Ground Stop KJFK"
        if (preg_match('/^GS\s+([A-Z]{4})/i', $content, $matches) ||
            preg_match('/GROUND\s*STOP\s+([A-Z]{4})/i', $content, $matches)) {
            return [
                'tmi_type' => 'GS',
                'airport' => strtoupper($matches[1]),
                'raw' => $content
            ];
        }

        // GDP: "GDP KORD"
        if (preg_match('/^GDP\s+([A-Z]{4})/i', $content, $matches)) {
            return [
                'tmi_type' => 'GDP',
                'airport' => strtoupper($matches[1]),
                'raw' => $content
            ];
        }

        // REROUTE
        if (preg_match('/^REROUTE[S]?\s*[:]\s*(.+)/i', $content, $matches)) {
            return [
                'tmi_type' => 'REROUTE',
                'details' => trim($matches[1]),
                'raw' => $content
            ];
        }

        return null;
    }

    /**
     * Store TMI data in database
     *
     * @param array $messageData Discord message
     * @param array $tmiData Parsed TMI data
     * @return bool
     */
    private function storeTMI($messageData, $tmiData) {
        if (!$this->conn) {
            return false;
        }

        $sql = "INSERT INTO dbo.dcc_discord_tmi
                (discord_message_id, tmi_type, airport, reason, details, raw_message, status, received_at)
                VALUES (?, ?, ?, ?, ?, ?, 'ACTIVE', GETUTCDATE())";

        $stmt = @sqlsrv_query($this->conn, $sql, [
            $messageData['id'] ?? null,
            $tmiData['tmi_type'] ?? null,
            $tmiData['airport'] ?? null,
            $tmiData['reason'] ?? null,
            $tmiData['details'] ?? null,
            $tmiData['raw'] ?? ($messageData['content'] ?? '')
        ]);

        return $stmt !== false;
    }

    /**
     * Update TMI data when message is edited
     *
     * @param array $messageData Updated message
     * @param array $tmiData Parsed TMI data
     * @return bool
     */
    private function updateTMI($messageData, $tmiData) {
        if (!$this->conn || empty($messageData['id'])) {
            return false;
        }

        $sql = "UPDATE dbo.dcc_discord_tmi
                SET tmi_type = ?, airport = ?, reason = ?, details = ?, raw_message = ?, updated_at = GETUTCDATE()
                WHERE discord_message_id = ?";

        $stmt = @sqlsrv_query($this->conn, $sql, [
            $tmiData['tmi_type'] ?? null,
            $tmiData['airport'] ?? null,
            $tmiData['reason'] ?? null,
            $tmiData['details'] ?? null,
            $tmiData['raw'] ?? ($messageData['content'] ?? ''),
            $messageData['id']
        ]);

        return $stmt !== false;
    }
}
