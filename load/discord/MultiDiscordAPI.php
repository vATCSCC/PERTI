<?php
/**
 * Multi-Discord API Manager
 * 
 * Manages Discord operations across multiple organizations (vATCSCC, VATCAN, ECFMP, etc.)
 * using a single bot token invited to all servers.
 * 
 * Features:
 * - Single bot, multiple servers architecture
 * - Organization-aware channel routing
 * - Multi-target posting with per-org result tracking
 * - Cross-border TMI detection for automatic org selection
 * - Staging/production channel support per org
 * 
 * @package PERTI
 * @subpackage Discord
 * @version 1.0.0
 * @date 2026-01-27
 */

require_once __DIR__ . '/DiscordAPI.php';

class MultiDiscordAPI {
    
    /** @var DiscordAPI Single Discord API instance (one bot) */
    private $discord;
    
    /** @var array Organization configurations */
    private $organizations = [];
    
    /** @var array Enabled organizations cache */
    private $enabledOrgs = [];
    
    /** @var array Last operation results per org */
    private $lastResults = [];
    
    /** @var array Cross-border facility mappings */
    private static $crossBorderFacilities = [
        // US facilities near Canadian border
        'ZBW' => ['region' => 'US', 'border_partner' => 'CA'],
        'ZMP' => ['region' => 'US', 'border_partner' => 'CA'],
        'ZSE' => ['region' => 'US', 'border_partner' => 'CA'],
        'ZLC' => ['region' => 'US', 'border_partner' => 'CA'],
        'ZOB' => ['region' => 'US', 'border_partner' => 'CA'],
        // Canadian FIRs near US border
        'CZYZ' => ['region' => 'CA', 'border_partner' => 'US'],
        'CZWG' => ['region' => 'CA', 'border_partner' => 'US'],
        'CZVR' => ['region' => 'CA', 'border_partner' => 'US'],
        'CZEG' => ['region' => 'CA', 'border_partner' => 'US'],
        // European facilities (for future ECFMP integration)
        'EGTT' => ['region' => 'EU', 'border_partner' => null],
        'LFFF' => ['region' => 'EU', 'border_partner' => null],
        'EDGG' => ['region' => 'EU', 'border_partner' => null],
    ];
    
    /** @var array Region to org code mappings */
    private static $regionOrgMap = [
        'US' => 'vatcscc',
        'CA' => 'vatcan',
        'EU' => 'ecfmp',
    ];
    
    /**
     * Constructor
     * 
     * @param string|null $botToken Bot token (uses DISCORD_BOT_TOKEN if not provided)
     */
    public function __construct(?string $botToken = null) {
        // Create single Discord API instance
        $this->discord = new DiscordAPI($botToken);
        
        // Load organization configurations
        $this->loadOrganizations();
    }
    
    /**
     * Load organization configurations from config constant
     */
    private function loadOrganizations(): void {
        if (defined('DISCORD_ORGANIZATIONS')) {
            $orgs = json_decode(DISCORD_ORGANIZATIONS, true);
            if (is_array($orgs)) {
                $this->organizations = $orgs;
                
                // Build enabled orgs cache
                foreach ($orgs as $code => $config) {
                    if (!empty($config['enabled'])) {
                        $this->enabledOrgs[$code] = $config;
                    }
                }
            }
        }
        
        // Fallback: Build from legacy constants for backwards compatibility
        if (empty($this->organizations) && defined('DISCORD_GUILD_ID')) {
            $this->organizations['vatcscc'] = [
                'name' => 'vATCSCC',
                'region' => 'US',
                'guild_id' => DISCORD_GUILD_ID,
                'channels' => [
                    'ntml' => defined('DISCORD_CHANNEL_NTML') ? DISCORD_CHANNEL_NTML : null,
                    'advisories' => defined('DISCORD_CHANNEL_ADVISORIES') ? DISCORD_CHANNEL_ADVISORIES : null,
                    'ntml_staging' => defined('DISCORD_CHANNEL_NTML_STAGING') ? DISCORD_CHANNEL_NTML_STAGING : null,
                    'advzy_staging' => defined('DISCORD_CHANNEL_ADVZY_STAGING') ? DISCORD_CHANNEL_ADVZY_STAGING : null,
                ],
                'enabled' => true,
                'default' => true,
            ];
            $this->enabledOrgs['vatcscc'] = $this->organizations['vatcscc'];
        }
    }
    
    // =========================================
    // ORGANIZATION MANAGEMENT
    // =========================================
    
    /**
     * Get all configured organizations
     * 
     * @param bool $enabledOnly Only return enabled organizations
     * @return array Organization configurations
     */
    public function getOrganizations(bool $enabledOnly = false): array {
        return $enabledOnly ? $this->enabledOrgs : $this->organizations;
    }
    
    /**
     * Get organization configuration by code
     * 
     * @param string $orgCode Organization code (e.g., 'vatcscc', 'vatcan')
     * @return array|null Organization config or null if not found
     */
    public function getOrganization(string $orgCode): ?array {
        return $this->organizations[$orgCode] ?? null;
    }
    
    /**
     * Check if an organization is enabled
     * 
     * @param string $orgCode Organization code
     * @return bool
     */
    public function isOrgEnabled(string $orgCode): bool {
        return isset($this->enabledOrgs[$orgCode]);
    }
    
    /**
     * Get the default organization code
     * 
     * @return string|null Default org code or null if none set
     */
    public function getDefaultOrg(): ?string {
        foreach ($this->organizations as $code => $config) {
            if (!empty($config['default']) && !empty($config['enabled'])) {
                return $code;
            }
        }
        return array_key_first($this->enabledOrgs);
    }
    
    /**
     * Get channel ID for an organization and purpose
     * 
     * @param string $orgCode Organization code
     * @param string $purpose Channel purpose (ntml, advisories, ntml_staging, advzy_staging)
     * @return string|null Channel ID or null if not configured
     */
    public function getChannelId(string $orgCode, string $purpose): ?string {
        return $this->organizations[$orgCode]['channels'][$purpose] ?? null;
    }
    
    /**
     * Get organizations by region
     * 
     * @param string $region Region code (US, CA, EU)
     * @return array Matching organization codes
     */
    public function getOrgsByRegion(string $region): array {
        $matches = [];
        foreach ($this->enabledOrgs as $code => $config) {
            if (($config['region'] ?? '') === $region) {
                $matches[] = $code;
            }
        }
        return $matches;
    }
    
    // =========================================
    // CROSS-BORDER TMI DETECTION
    // =========================================
    
    /**
     * Determine target organizations for a TMI entry
     * 
     * @param array $entry TMI entry data
     * @param string $userHomeOrg User's home organization
     * @param bool $isPrivileged Whether user has privileged posting rights
     * @return array Target organization codes
     */
    public function determineTargetOrgs(array $entry, string $userHomeOrg, bool $isPrivileged = false): array {
        // Privileged users can post to any enabled org
        if ($isPrivileged) {
            return array_keys($this->enabledOrgs);
        }
        
        // Check for cross-border TMI
        $crossBorderOrgs = $this->detectCrossBorderOrgs($entry);
        
        if (!empty($crossBorderOrgs)) {
            // Include user's home org plus cross-border partners
            $targets = array_unique(array_merge([$userHomeOrg], $crossBorderOrgs));
            // Filter to only enabled orgs
            return array_values(array_filter($targets, fn($org) => $this->isOrgEnabled($org)));
        }
        
        // Default: user's home org only
        return $this->isOrgEnabled($userHomeOrg) ? [$userHomeOrg] : [];
    }
    
    /**
     * Detect cross-border organizations from TMI entry
     * 
     * @param array $entry TMI entry data
     * @return array Cross-border organization codes
     */
    public function detectCrossBorderOrgs(array $entry): array {
        $crossBorderOrgs = [];
        $regionsInvolved = [];
        
        // Collect all facilities mentioned in the entry
        $facilities = array_filter([
            $entry['requesting_facility'] ?? null,
            $entry['providing_facility'] ?? null,
            $entry['from_facility'] ?? null,
            $entry['to_facility'] ?? null,
        ]);
        
        // Add scope facilities if present (comma-separated)
        if (!empty($entry['scope_facilities'])) {
            $facilities = array_merge($facilities, explode(',', $entry['scope_facilities']));
        }
        
        // Determine regions from facilities
        foreach ($facilities as $fac) {
            $fac = strtoupper(trim($fac));
            
            // Check direct facility mapping
            if (isset(self::$crossBorderFacilities[$fac])) {
                $info = self::$crossBorderFacilities[$fac];
                $regionsInvolved[$info['region']] = true;
                
                // If this is a border facility, include partner region
                if (!empty($info['border_partner'])) {
                    $regionsInvolved[$info['border_partner']] = true;
                }
            } else {
                // Infer region from facility prefix
                if (preg_match('/^Z[A-Z]{2}$/', $fac)) {
                    $regionsInvolved['US'] = true;
                } elseif (preg_match('/^CZ[A-Z]{2}$/', $fac)) {
                    $regionsInvolved['CA'] = true;
                } elseif (preg_match('/^[EL][A-Z]{3}$/', $fac)) {
                    $regionsInvolved['EU'] = true;
                }
            }
        }
        
        // Check airports
        $airports = array_filter([
            $entry['airport'] ?? null,
            $entry['ctl_element'] ?? null,
            $entry['condition'] ?? null,
        ]);
        
        foreach ($airports as $apt) {
            $apt = strtoupper(trim($apt));
            if (preg_match('/^K[A-Z]{3}$/', $apt)) {
                $regionsInvolved['US'] = true;
            } elseif (preg_match('/^C[A-Z]{3}$/', $apt)) {
                $regionsInvolved['CA'] = true;
            }
        }
        
        // If multiple regions involved, this is cross-border
        if (count($regionsInvolved) > 1) {
            foreach (array_keys($regionsInvolved) as $region) {
                if (isset(self::$regionOrgMap[$region])) {
                    $orgCode = self::$regionOrgMap[$region];
                    if ($this->isOrgEnabled($orgCode)) {
                        $crossBorderOrgs[] = $orgCode;
                    }
                }
            }
        }
        
        return array_unique($crossBorderOrgs);
    }
    
    // =========================================
    // MULTI-TARGET POSTING
    // =========================================
    
    /**
     * Post a message to multiple organizations
     * 
     * @param array $orgCodes Organization codes to post to
     * @param string $channelPurpose Channel purpose (ntml, advisories, ntml_staging, advzy_staging)
     * @param array $messageData Discord message data (content, embeds, etc.)
     * @return array Results keyed by org code
     */
    public function postToOrgs(array $orgCodes, string $channelPurpose, array $messageData): array {
        $results = [];
        
        foreach ($orgCodes as $orgCode) {
            $results[$orgCode] = $this->postToOrg($orgCode, $channelPurpose, $messageData);
        }
        
        $this->lastResults = $results;
        return $results;
    }
    
    /**
     * Post a message to a single organization
     * 
     * @param string $orgCode Organization code
     * @param string $channelPurpose Channel purpose
     * @param array $messageData Discord message data
     * @return array Result with success status, message_id, error, etc.
     */
    public function postToOrg(string $orgCode, string $channelPurpose, array $messageData): array {
        $result = [
            'org_code' => $orgCode,
            'channel_purpose' => $channelPurpose,
            'success' => false,
            'message_id' => null,
            'message_url' => null,
            'error' => null,
            'channel_id' => null,
        ];
        
        // Check if org exists and is enabled
        if (!$this->isOrgEnabled($orgCode)) {
            $result['error'] = "Organization '{$orgCode}' is not enabled";
            return $result;
        }
        
        // Get channel ID
        $channelId = $this->getChannelId($orgCode, $channelPurpose);
        if (empty($channelId)) {
            $result['error'] = "Channel '{$channelPurpose}' not configured for {$orgCode}";
            return $result;
        }
        
        $result['channel_id'] = $channelId;
        
        // Post message
        $response = $this->discord->createMessage($channelId, $messageData);
        
        if ($response && isset($response['id'])) {
            $result['success'] = true;
            $result['message_id'] = $response['id'];
            
            // Build message URL
            $guildId = $this->organizations[$orgCode]['guild_id'] ?? null;
            if ($guildId) {
                $result['message_url'] = "https://discord.com/channels/{$guildId}/{$channelId}/{$response['id']}";
            }
        } else {
            $result['error'] = $this->discord->getLastError() ?? 'Unknown error';
        }
        
        return $result;
    }
    
    /**
     * Post to staging channels for specified orgs
     * 
     * @param array $orgCodes Organization codes
     * @param string $tmiType 'ntml' or 'advisory'
     * @param array $messageData Discord message data
     * @return array Results keyed by org code
     */
    public function postToStaging(array $orgCodes, string $tmiType, array $messageData): array {
        $channelPurpose = ($tmiType === 'advisory') ? 'advzy_staging' : 'ntml_staging';
        return $this->postToOrgs($orgCodes, $channelPurpose, $messageData);
    }
    
    /**
     * Post to production channels for specified orgs
     * 
     * @param array $orgCodes Organization codes
     * @param string $tmiType 'ntml' or 'advisory'
     * @param array $messageData Discord message data
     * @return array Results keyed by org code
     */
    public function postToProduction(array $orgCodes, string $tmiType, array $messageData): array {
        $channelPurpose = ($tmiType === 'advisory') ? 'advisories' : 'ntml';
        return $this->postToOrgs($orgCodes, $channelPurpose, $messageData);
    }
    
    /**
     * Promote a message from staging to production
     * 
     * This posts the same content to production channels for the specified orgs.
     * 
     * @param array $orgCodes Organization codes
     * @param string $tmiType 'ntml' or 'advisory'
     * @param array $messageData Discord message data
     * @param array|null $stagingMessageIds Optional: staging message IDs to delete after promotion
     * @return array Results keyed by org code
     */
    public function promoteToProduction(array $orgCodes, string $tmiType, array $messageData, ?array $stagingMessageIds = null): array {
        // Post to production
        $results = $this->postToProduction($orgCodes, $tmiType, $messageData);
        
        // Optionally delete staging messages
        if ($stagingMessageIds) {
            $stagingPurpose = ($tmiType === 'advisory') ? 'advzy_staging' : 'ntml_staging';
            
            foreach ($stagingMessageIds as $orgCode => $messageId) {
                if ($messageId && $this->isOrgEnabled($orgCode)) {
                    $channelId = $this->getChannelId($orgCode, $stagingPurpose);
                    if ($channelId) {
                        $deleted = $this->discord->deleteMessage($channelId, $messageId);
                        $results[$orgCode]['staging_deleted'] = $deleted;
                    }
                }
            }
        }
        
        return $results;
    }
    
    // =========================================
    // MESSAGE MANAGEMENT
    // =========================================
    
    /**
     * Edit a message in a specific org's channel
     * 
     * @param string $orgCode Organization code
     * @param string $channelPurpose Channel purpose
     * @param string $messageId Message ID to edit
     * @param array $messageData New message data
     * @return array Result with success status
     */
    public function editMessage(string $orgCode, string $channelPurpose, string $messageId, array $messageData): array {
        $result = [
            'org_code' => $orgCode,
            'success' => false,
            'error' => null,
        ];
        
        $channelId = $this->getChannelId($orgCode, $channelPurpose);
        if (!$channelId) {
            $result['error'] = "Channel not configured";
            return $result;
        }
        
        $response = $this->discord->editMessage($channelId, $messageId, $messageData);
        $result['success'] = ($response !== null);
        if (!$result['success']) {
            $result['error'] = $this->discord->getLastError();
        }
        
        return $result;
    }
    
    /**
     * Delete a message from a specific org's channel
     * 
     * @param string $orgCode Organization code
     * @param string $channelPurpose Channel purpose
     * @param string $messageId Message ID to delete
     * @return bool Success status
     */
    public function deleteMessage(string $orgCode, string $channelPurpose, string $messageId): bool {
        $channelId = $this->getChannelId($orgCode, $channelPurpose);
        if (!$channelId) {
            return false;
        }
        
        return $this->discord->deleteMessage($channelId, $messageId);
    }
    
    /**
     * Get messages from a channel
     * 
     * @param string $orgCode Organization code
     * @param string $channelPurpose Channel purpose
     * @param array $options Options (limit, before, after, around)
     * @return array|null Messages or null on error
     */
    public function getMessages(string $orgCode, string $channelPurpose, array $options = []): ?array {
        $channelId = $this->getChannelId($orgCode, $channelPurpose);
        if (!$channelId) {
            return null;
        }
        
        return $this->discord->getMessages($channelId, $options);
    }
    
    // =========================================
    // REACTION HANDLING
    // =========================================
    
    /**
     * Add a reaction to a message
     * 
     * @param string $orgCode Organization code
     * @param string $channelPurpose Channel purpose
     * @param string $messageId Message ID
     * @param string $emoji Emoji (unicode or custom format)
     * @return bool Success status
     */
    public function addReaction(string $orgCode, string $channelPurpose, string $messageId, string $emoji): bool {
        $channelId = $this->getChannelId($orgCode, $channelPurpose);
        if (!$channelId) {
            return false;
        }
        
        return $this->discord->createReaction($channelId, $messageId, $emoji);
    }
    
    /**
     * Get users who reacted with an emoji
     * 
     * @param string $orgCode Organization code
     * @param string $channelPurpose Channel purpose
     * @param string $messageId Message ID
     * @param string $emoji Emoji
     * @return array|null Users or null on error
     */
    public function getReactions(string $orgCode, string $channelPurpose, string $messageId, string $emoji): ?array {
        $channelId = $this->getChannelId($orgCode, $channelPurpose);
        if (!$channelId) {
            return null;
        }
        
        return $this->discord->getReactions($channelId, $messageId, $emoji);
    }
    
    // =========================================
    // ORGANIZATION LOOKUP BY CHANNEL/GUILD
    // =========================================
    
    /**
     * Find organization by guild ID (for incoming webhooks)
     * 
     * @param string $guildId Discord guild/server ID
     * @return string|null Organization code or null if not found
     */
    public function findOrgByGuildId(string $guildId): ?string {
        foreach ($this->organizations as $code => $config) {
            if (($config['guild_id'] ?? '') === $guildId) {
                return $code;
            }
        }
        return null;
    }
    
    /**
     * Find organization by channel ID (for incoming webhooks)
     * 
     * @param string $channelId Discord channel ID
     * @return array|null ['org_code' => ..., 'channel_purpose' => ...] or null
     */
    public function findOrgByChannelId(string $channelId): ?array {
        foreach ($this->organizations as $code => $config) {
            if (!empty($config['channels'])) {
                foreach ($config['channels'] as $purpose => $id) {
                    if ($id === $channelId) {
                        return [
                            'org_code' => $code,
                            'channel_purpose' => $purpose,
                        ];
                    }
                }
            }
        }
        return null;
    }
    
    // =========================================
    // UTILITY METHODS
    // =========================================
    
    /**
     * Get the underlying Discord API instance
     * 
     * @return DiscordAPI
     */
    public function getDiscordAPI(): DiscordAPI {
        return $this->discord;
    }
    
    /**
     * Get results from last multi-org operation
     * 
     * @return array
     */
    public function getLastResults(): array {
        return $this->lastResults;
    }
    
    /**
     * Check if Discord is configured
     * 
     * @return bool
     */
    public function isConfigured(): bool {
        return $this->discord->isConfigured() && !empty($this->enabledOrgs);
    }
    
    /**
     * Get summary of enabled organizations for UI
     * 
     * @return array Array of ['code' => ..., 'name' => ..., 'region' => ...]
     */
    public function getOrgSummary(): array {
        $summary = [];
        foreach ($this->enabledOrgs as $code => $config) {
            $summary[] = [
                'code' => $code,
                'name' => $config['name'] ?? strtoupper($code),
                'region' => $config['region'] ?? 'UNKNOWN',
                'default' => !empty($config['default']),
                'testing_only' => !empty($config['testing_only']),
            ];
        }
        return $summary;
    }
}
