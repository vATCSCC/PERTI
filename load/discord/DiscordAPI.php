<?php
/**
 * Discord REST API Client
 *
 * Handles all Discord API operations with proper error handling,
 * rate limiting, and authentication.
 *
 * Discord API Documentation: https://discord.com/developers/docs/reference
 */

class DiscordAPI {
    private $botToken;
    private $baseUrl;
    private $guildId;
    private $channels;

    // Rate limiting
    private $rateLimitRemaining = 50;
    private $rateLimitResetAt = 0;
    private $rateLimitBucket = null;

    // Last response metadata
    private $lastResponse = null;
    private $lastHttpCode = null;
    private $lastError = null;

    /**
     * Constructor
     *
     * @param string|null $botToken Bot token (defaults to config constant)
     * @param string|null $guildId Guild ID (defaults to config constant)
     */
    public function __construct($botToken = null, $guildId = null) {
        $this->botToken = $botToken ?? (defined('DISCORD_BOT_TOKEN') ? DISCORD_BOT_TOKEN : '');
        $this->guildId = $guildId ?? (defined('DISCORD_GUILD_ID') ? DISCORD_GUILD_ID : '');
        $this->baseUrl = defined('DISCORD_API_BASE') ? DISCORD_API_BASE : 'https://discord.com/api/v10';

        // Load channel configuration
        if (defined('DISCORD_CHANNELS')) {
            $this->channels = json_decode(DISCORD_CHANNELS, true) ?? [];
        } else {
            $this->channels = [];
        }
    }

    // =========================================
    // CONFIGURATION HELPERS
    // =========================================

    /**
     * Check if Discord integration is configured
     *
     * @return bool
     */
    public function isConfigured() {
        return !empty($this->botToken);
    }

    /**
     * Get channel ID by purpose name
     *
     * @param string $purpose Channel purpose (tmi, advisories, operations, alerts, general)
     * @return string|null Channel ID or null if not found
     */
    public function getChannelByPurpose($purpose) {
        return $this->channels[$purpose] ?? null;
    }

    /**
     * Get all configured channels
     *
     * @return array
     */
    public function getConfiguredChannels() {
        return $this->channels;
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
     * Get last HTTP response code
     *
     * @return int|null
     */
    public function getLastHttpCode() {
        return $this->lastHttpCode;
    }

    /**
     * Get last response data
     *
     * @return array|null
     */
    public function getLastResponse() {
        return $this->lastResponse;
    }

    // =========================================
    // MESSAGE OPERATIONS
    // =========================================

    /**
     * Get messages from a channel
     *
     * @param string $channelId Channel ID or purpose name
     * @param array $options Optional parameters:
     *   - limit: Max messages to return (1-100, default 50)
     *   - before: Get messages before this message ID
     *   - after: Get messages after this message ID
     *   - around: Get messages around this message ID
     * @return array|null Array of message objects or null on error
     */
    public function getMessages($channelId, $options = []) {
        // Resolve purpose name to channel ID
        $channelId = $this->resolveChannelId($channelId);
        if (!$channelId) {
            $this->lastError = 'Invalid channel ID or purpose';
            return null;
        }

        $query = http_build_query(array_filter([
            'limit' => min(100, max(1, $options['limit'] ?? 50)),
            'before' => $options['before'] ?? null,
            'after' => $options['after'] ?? null,
            'around' => $options['around'] ?? null,
        ]));

        $endpoint = "/channels/{$channelId}/messages" . ($query ? "?{$query}" : '');
        return $this->request('GET', $endpoint);
    }

    /**
     * Get a specific message by ID
     *
     * @param string $channelId Channel ID or purpose name
     * @param string $messageId Message ID
     * @return array|null Message object or null on error
     */
    public function getMessage($channelId, $messageId) {
        $channelId = $this->resolveChannelId($channelId);
        if (!$channelId) {
            $this->lastError = 'Invalid channel ID or purpose';
            return null;
        }

        return $this->request('GET', "/channels/{$channelId}/messages/{$messageId}");
    }

    /**
     * Send a message to a channel
     *
     * @param string $channelId Channel ID or purpose name
     * @param array $data Message data:
     *   - content: Message text content (up to 2000 chars)
     *   - embeds: Array of embed objects
     *   - components: Array of component objects
     *   - allowed_mentions: Mentions configuration
     *   - message_reference: Reply reference
     *   - tts: Text-to-speech (bool)
     * @return array|null Created message object or null on error
     */
    public function createMessage($channelId, $data) {
        $channelId = $this->resolveChannelId($channelId);
        if (!$channelId) {
            $this->lastError = 'Invalid channel ID or purpose';
            return null;
        }

        // Ensure content or embeds exist
        if (empty($data['content']) && empty($data['embeds'])) {
            $this->lastError = 'Message must have content or embeds';
            return null;
        }

        // Truncate content if too long
        if (isset($data['content']) && strlen($data['content']) > 2000) {
            $data['content'] = substr($data['content'], 0, 1997) . '...';
        }

        return $this->request('POST', "/channels/{$channelId}/messages", $data);
    }

    /**
     * Edit an existing message
     *
     * @param string $channelId Channel ID or purpose name
     * @param string $messageId Message ID to edit
     * @param array $data Updated message data (content, embeds, components, allowed_mentions)
     * @return array|null Updated message object or null on error
     */
    public function editMessage($channelId, $messageId, $data) {
        $channelId = $this->resolveChannelId($channelId);
        if (!$channelId) {
            $this->lastError = 'Invalid channel ID or purpose';
            return null;
        }

        // Truncate content if too long
        if (isset($data['content']) && strlen($data['content']) > 2000) {
            $data['content'] = substr($data['content'], 0, 1997) . '...';
        }

        return $this->request('PATCH', "/channels/{$channelId}/messages/{$messageId}", $data);
    }

    /**
     * Delete a message
     *
     * @param string $channelId Channel ID or purpose name
     * @param string $messageId Message ID to delete
     * @return bool True on success, false on error
     */
    public function deleteMessage($channelId, $messageId) {
        $channelId = $this->resolveChannelId($channelId);
        if (!$channelId) {
            $this->lastError = 'Invalid channel ID or purpose';
            return false;
        }

        $result = $this->request('DELETE', "/channels/{$channelId}/messages/{$messageId}");
        return $this->lastHttpCode === 204;
    }

    /**
     * Bulk delete messages (2-100 messages, not older than 14 days)
     *
     * @param string $channelId Channel ID or purpose name
     * @param array $messageIds Array of message IDs to delete
     * @return bool True on success, false on error
     */
    public function bulkDeleteMessages($channelId, $messageIds) {
        $channelId = $this->resolveChannelId($channelId);
        if (!$channelId) {
            $this->lastError = 'Invalid channel ID or purpose';
            return false;
        }

        if (count($messageIds) < 2 || count($messageIds) > 100) {
            $this->lastError = 'Bulk delete requires 2-100 messages';
            return false;
        }

        $result = $this->request('POST', "/channels/{$channelId}/messages/bulk-delete", [
            'messages' => $messageIds
        ]);
        return $this->lastHttpCode === 204;
    }

    /**
     * Crosspost a message to announcement channel subscribers
     *
     * @param string $channelId Announcement channel ID or purpose name
     * @param string $messageId Message ID to crosspost
     * @return array|null Crossposted message object or null on error
     */
    public function crosspostMessage($channelId, $messageId) {
        $channelId = $this->resolveChannelId($channelId);
        if (!$channelId) {
            $this->lastError = 'Invalid channel ID or purpose';
            return null;
        }

        return $this->request('POST', "/channels/{$channelId}/messages/{$messageId}/crosspost");
    }

    // =========================================
    // REACTION OPERATIONS
    // =========================================

    /**
     * Add a reaction to a message
     *
     * @param string $channelId Channel ID or purpose name
     * @param string $messageId Message ID
     * @param string $emoji Emoji (Unicode emoji or custom format name:id)
     * @return bool True on success, false on error
     */
    public function createReaction($channelId, $messageId, $emoji) {
        $channelId = $this->resolveChannelId($channelId);
        if (!$channelId) {
            $this->lastError = 'Invalid channel ID or purpose';
            return false;
        }

        $emoji = $this->encodeEmoji($emoji);
        $result = $this->request('PUT', "/channels/{$channelId}/messages/{$messageId}/reactions/{$emoji}/@me");
        return $this->lastHttpCode === 204;
    }

    /**
     * Remove own reaction from a message
     *
     * @param string $channelId Channel ID or purpose name
     * @param string $messageId Message ID
     * @param string $emoji Emoji
     * @return bool True on success, false on error
     */
    public function deleteOwnReaction($channelId, $messageId, $emoji) {
        $channelId = $this->resolveChannelId($channelId);
        if (!$channelId) {
            $this->lastError = 'Invalid channel ID or purpose';
            return false;
        }

        $emoji = $this->encodeEmoji($emoji);
        $result = $this->request('DELETE', "/channels/{$channelId}/messages/{$messageId}/reactions/{$emoji}/@me");
        return $this->lastHttpCode === 204;
    }

    /**
     * Remove a user's reaction from a message
     *
     * @param string $channelId Channel ID or purpose name
     * @param string $messageId Message ID
     * @param string $emoji Emoji
     * @param string $userId User ID
     * @return bool True on success, false on error
     */
    public function deleteUserReaction($channelId, $messageId, $emoji, $userId) {
        $channelId = $this->resolveChannelId($channelId);
        if (!$channelId) {
            $this->lastError = 'Invalid channel ID or purpose';
            return false;
        }

        $emoji = $this->encodeEmoji($emoji);
        $result = $this->request('DELETE', "/channels/{$channelId}/messages/{$messageId}/reactions/{$emoji}/{$userId}");
        return $this->lastHttpCode === 204;
    }

    /**
     * Get users who reacted with a specific emoji
     *
     * @param string $channelId Channel ID or purpose name
     * @param string $messageId Message ID
     * @param string $emoji Emoji
     * @param array $options Optional parameters:
     *   - limit: Max users to return (1-100, default 25)
     *   - after: Get users after this user ID
     * @return array|null Array of user objects or null on error
     */
    public function getReactions($channelId, $messageId, $emoji, $options = []) {
        $channelId = $this->resolveChannelId($channelId);
        if (!$channelId) {
            $this->lastError = 'Invalid channel ID or purpose';
            return null;
        }

        $emoji = $this->encodeEmoji($emoji);
        $query = http_build_query(array_filter([
            'limit' => min(100, max(1, $options['limit'] ?? 25)),
            'after' => $options['after'] ?? null,
        ]));

        $endpoint = "/channels/{$channelId}/messages/{$messageId}/reactions/{$emoji}" . ($query ? "?{$query}" : '');
        return $this->request('GET', $endpoint);
    }

    /**
     * Remove all reactions from a message
     *
     * @param string $channelId Channel ID or purpose name
     * @param string $messageId Message ID
     * @return bool True on success, false on error
     */
    public function deleteAllReactions($channelId, $messageId) {
        $channelId = $this->resolveChannelId($channelId);
        if (!$channelId) {
            $this->lastError = 'Invalid channel ID or purpose';
            return false;
        }

        $result = $this->request('DELETE', "/channels/{$channelId}/messages/{$messageId}/reactions");
        return $this->lastHttpCode === 204;
    }

    /**
     * Remove all reactions of a specific emoji from a message
     *
     * @param string $channelId Channel ID or purpose name
     * @param string $messageId Message ID
     * @param string $emoji Emoji
     * @return bool True on success, false on error
     */
    public function deleteAllReactionsForEmoji($channelId, $messageId, $emoji) {
        $channelId = $this->resolveChannelId($channelId);
        if (!$channelId) {
            $this->lastError = 'Invalid channel ID or purpose';
            return false;
        }

        $emoji = $this->encodeEmoji($emoji);
        $result = $this->request('DELETE', "/channels/{$channelId}/messages/{$messageId}/reactions/{$emoji}");
        return $this->lastHttpCode === 204;
    }

    // =========================================
    // CHANNEL OPERATIONS
    // =========================================

    /**
     * Get channel information
     *
     * @param string $channelId Channel ID or purpose name
     * @return array|null Channel object or null on error
     */
    public function getChannel($channelId) {
        $channelId = $this->resolveChannelId($channelId);
        if (!$channelId) {
            $this->lastError = 'Invalid channel ID or purpose';
            return null;
        }

        return $this->request('GET', "/channels/{$channelId}");
    }

    /**
     * Get all channels in a guild
     *
     * @param string|null $guildId Guild ID (defaults to configured guild)
     * @return array|null Array of channel objects or null on error
     */
    public function getGuildChannels($guildId = null) {
        $guildId = $guildId ?? $this->guildId;
        if (!$guildId) {
            $this->lastError = 'Guild ID not configured';
            return null;
        }

        return $this->request('GET', "/guilds/{$guildId}/channels");
    }

    // =========================================
    // USER AND ROLE OPERATIONS
    // =========================================

    /**
     * Get guild member information
     *
     * @param string $userId User ID
     * @param string|null $guildId Guild ID (defaults to configured guild)
     * @return array|null Member object or null on error
     */
    public function getGuildMember($userId, $guildId = null) {
        $guildId = $guildId ?? $this->guildId;
        if (!$guildId) {
            $this->lastError = 'Guild ID not configured';
            return null;
        }

        return $this->request('GET', "/guilds/{$guildId}/members/{$userId}");
    }

    /**
     * Get all roles in a guild
     *
     * @param string|null $guildId Guild ID (defaults to configured guild)
     * @return array|null Array of role objects or null on error
     */
    public function getGuildRoles($guildId = null) {
        $guildId = $guildId ?? $this->guildId;
        if (!$guildId) {
            $this->lastError = 'Guild ID not configured';
            return null;
        }

        return $this->request('GET', "/guilds/{$guildId}/roles");
    }

    /**
     * Search guild members by query
     *
     * @param string $query Search query (username)
     * @param int $limit Max results (1-1000, default 100)
     * @param string|null $guildId Guild ID (defaults to configured guild)
     * @return array|null Array of member objects or null on error
     */
    public function searchGuildMembers($query, $limit = 100, $guildId = null) {
        $guildId = $guildId ?? $this->guildId;
        if (!$guildId) {
            $this->lastError = 'Guild ID not configured';
            return null;
        }

        $params = http_build_query([
            'query' => $query,
            'limit' => min(1000, max(1, $limit))
        ]);

        return $this->request('GET', "/guilds/{$guildId}/members/search?{$params}");
    }

    // =========================================
    // MENTION FORMATTING HELPERS
    // =========================================

    /**
     * Format a user mention string
     *
     * @param string $userId User ID
     * @return string Formatted mention
     */
    public static function mentionUser($userId) {
        return "<@{$userId}>";
    }

    /**
     * Format a role mention string
     *
     * @param string $roleId Role ID
     * @return string Formatted mention
     */
    public static function mentionRole($roleId) {
        return "<@&{$roleId}>";
    }

    /**
     * Format a channel mention string
     *
     * @param string $channelId Channel ID
     * @return string Formatted mention
     */
    public static function mentionChannel($channelId) {
        return "<#{$channelId}>";
    }

    /**
     * Format a custom emoji string
     *
     * @param string $name Emoji name
     * @param string $id Emoji ID
     * @param bool $animated Whether the emoji is animated
     * @return string Formatted emoji
     */
    public static function formatEmoji($name, $id, $animated = false) {
        $prefix = $animated ? 'a' : '';
        return "<{$prefix}:{$name}:{$id}>";
    }

    /**
     * Format a timestamp with Discord's formatting
     *
     * @param int|string $timestamp Unix timestamp or DateTime string
     * @param string $style Style: t, T, d, D, f, F, R (relative)
     * @return string Formatted timestamp
     */
    public static function formatTimestamp($timestamp, $style = 'f') {
        if (is_string($timestamp)) {
            $timestamp = strtotime($timestamp);
        }
        return "<t:{$timestamp}:{$style}>";
    }

    /**
     * Build allowed_mentions object to control mentions
     *
     * @param array $options Options:
     *   - parse: Array of types to parse (roles, users, everyone)
     *   - roles: Array of role IDs to allow
     *   - users: Array of user IDs to allow
     *   - replied_user: Whether to mention replied user
     * @return array Allowed mentions object
     */
    public static function buildAllowedMentions($options = []) {
        $mentions = [];

        if (isset($options['parse'])) {
            $mentions['parse'] = $options['parse'];
        }
        if (isset($options['roles'])) {
            $mentions['roles'] = $options['roles'];
        }
        if (isset($options['users'])) {
            $mentions['users'] = $options['users'];
        }
        if (isset($options['replied_user'])) {
            $mentions['replied_user'] = $options['replied_user'];
        }

        return $mentions;
    }

    // =========================================
    // EMBED BUILDER HELPERS
    // =========================================

    /**
     * Build a simple embed object
     *
     * @param array $options Embed options:
     *   - title: Embed title
     *   - description: Embed description
     *   - url: Title URL
     *   - color: Color integer (decimal)
     *   - timestamp: ISO8601 timestamp
     *   - footer: ['text' => '...', 'icon_url' => '...']
     *   - author: ['name' => '...', 'url' => '...', 'icon_url' => '...']
     *   - fields: [['name' => '...', 'value' => '...', 'inline' => bool], ...]
     *   - thumbnail: ['url' => '...']
     *   - image: ['url' => '...']
     * @return array Embed object
     */
    public static function buildEmbed($options) {
        $embed = [];

        $simpleFields = ['title', 'description', 'url', 'color', 'timestamp'];
        foreach ($simpleFields as $field) {
            if (isset($options[$field])) {
                $embed[$field] = $options[$field];
            }
        }

        $objectFields = ['footer', 'author', 'thumbnail', 'image', 'fields'];
        foreach ($objectFields as $field) {
            if (isset($options[$field])) {
                $embed[$field] = $options[$field];
            }
        }

        return $embed;
    }

    // =========================================
    // INTERNAL HELPERS
    // =========================================

    /**
     * Resolve channel ID from ID or purpose name
     *
     * @param string $channelId Channel ID or purpose name
     * @return string|null Resolved channel ID
     */
    private function resolveChannelId($channelId) {
        // If it looks like a snowflake ID, return as-is
        if (preg_match('/^\d{17,20}$/', $channelId)) {
            return $channelId;
        }

        // Try to look up by purpose name
        return $this->channels[$channelId] ?? null;
    }

    /**
     * Encode emoji for URL
     *
     * @param string $emoji Emoji string
     * @return string URL-encoded emoji
     */
    private function encodeEmoji($emoji) {
        // Custom emoji format: name:id
        if (preg_match('/^[a-zA-Z0-9_]+:\d+$/', $emoji)) {
            return $emoji;
        }

        // Unicode emoji - URL encode
        return rawurlencode($emoji);
    }

    // =========================================
    // HTTP CLIENT
    // =========================================

    /**
     * Make an authenticated API request
     *
     * @param string $method HTTP method (GET, POST, PUT, PATCH, DELETE)
     * @param string $endpoint API endpoint (e.g., /channels/123/messages)
     * @param array|null $data Request body data (for POST/PUT/PATCH)
     * @return array|null Response data or null on error
     */
    private function request($method, $endpoint, $data = null) {
        // Reset state
        $this->lastError = null;
        $this->lastResponse = null;
        $this->lastHttpCode = null;

        // Check configuration
        if (!$this->isConfigured()) {
            $this->lastError = 'Discord bot token not configured';
            return null;
        }

        // Wait for rate limit if needed
        $this->waitForRateLimit();

        $url = $this->baseUrl . $endpoint;

        $ch = curl_init($url);

        $headers = [
            'Authorization: Bot ' . $this->botToken,
            'Content-Type: application/json',
            'User-Agent: PERTI-Discord-Integration/1.0'
        ];

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HEADER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);

        if ($data !== null && in_array($method, ['POST', 'PUT', 'PATCH'])) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }

        $response = curl_exec($ch);

        if (curl_errno($ch)) {
            $this->lastError = 'cURL error: ' . curl_error($ch);
            curl_close($ch);
            return null;
        }

        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $this->lastHttpCode = $httpCode;

        // Parse headers and body
        $headerStr = substr($response, 0, $headerSize);
        $body = substr($response, $headerSize);

        // Handle rate limit headers
        $this->handleRateLimitHeaders($headerStr);

        // Parse response body
        $responseData = json_decode($body, true);
        $this->lastResponse = $responseData;

        // Handle errors
        if ($httpCode >= 400) {
            if ($httpCode === 429) {
                // Rate limited - extract retry_after and wait
                $retryAfter = $responseData['retry_after'] ?? 1;
                $this->lastError = "Rate limited. Retry after {$retryAfter} seconds";

                // Sleep and retry once
                usleep((int)($retryAfter * 1000000) + 100000);
                return $this->request($method, $endpoint, $data);
            }

            $this->lastError = $responseData['message'] ?? "HTTP error {$httpCode}";
            return null;
        }

        // Success - return data (or empty array for 204 No Content)
        return $responseData ?? [];
    }

    /**
     * Handle rate limit headers from response
     *
     * @param string $headerStr Raw header string
     */
    private function handleRateLimitHeaders($headerStr) {
        $headers = [];
        foreach (explode("\r\n", $headerStr) as $line) {
            if (strpos($line, ':') !== false) {
                list($key, $value) = explode(':', $line, 2);
                $headers[strtolower(trim($key))] = trim($value);
            }
        }

        if (isset($headers['x-ratelimit-remaining'])) {
            $this->rateLimitRemaining = (int)$headers['x-ratelimit-remaining'];
        }
        if (isset($headers['x-ratelimit-reset'])) {
            $this->rateLimitResetAt = (float)$headers['x-ratelimit-reset'];
        }
        if (isset($headers['x-ratelimit-bucket'])) {
            $this->rateLimitBucket = $headers['x-ratelimit-bucket'];
        }
    }

    /**
     * Wait for rate limit reset if needed
     */
    private function waitForRateLimit() {
        if ($this->rateLimitRemaining <= 1 && $this->rateLimitResetAt > 0) {
            $now = microtime(true);
            if ($this->rateLimitResetAt > $now) {
                $waitTime = $this->rateLimitResetAt - $now + 0.1;
                usleep((int)($waitTime * 1000000));
            }
        }
    }
}
