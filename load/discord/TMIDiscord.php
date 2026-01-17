<?php
/**
 * TMI Discord Integration Module
 * 
 * Handles Discord notifications for Traffic Management Initiatives:
 * - NTML entries (MIT, MINIT, DELAY, etc.)
 * - Advisories (GS, GDP, AFP, Reroutes)
 * - GDT Programs (activation, extension, purge)
 * - Reroute announcements
 * 
 * @package PERTI
 * @subpackage TMI/Discord
 * @version 1.0.0
 */

require_once __DIR__ . '/../../load/discord/DiscordAPI.php';

class TMIDiscord {
    
    private $discord;
    private $channels;
    
    // Color palette for embeds
    const COLOR_INFO = 0x3498db;       // Blue - informational
    const COLOR_WARNING = 0xf39c12;    // Orange - caution
    const COLOR_DANGER = 0xe74c3c;     // Red - critical/GS
    const COLOR_SUCCESS = 0x2ecc71;    // Green - completion
    const COLOR_PURPLE = 0x9b59b6;     // Purple - GDP
    const COLOR_GRAY = 0x95a5a6;       // Gray - cancelled
    
    // TMI Type to emoji mapping
    const EMOJI_MAP = [
        'MIT' => '🔴',
        'MINIT' => '🟠',
        'DELAY' => '🟡',
        'APREQ' => '🔵',
        'CONFIG' => '⚙️',
        'CONTINGENCY' => '⚠️',
        'REROUTE' => '↩️',
        'MISC' => '📋',
        'GS' => '🛑',
        'GDP' => '⏱️',
        'AFP' => '🌐',
        'CTOP' => '🔷',
        'OPS_PLAN' => '📝',
        'GENERAL' => '📢'
    ];
    
    /**
     * Constructor
     * 
     * @param DiscordAPI|null $discord Discord API instance (creates new if null)
     */
    public function __construct($discord = null) {
        $this->discord = $discord ?? new DiscordAPI();
        $this->channels = $this->discord->getConfiguredChannels();
    }
    
    /**
     * Check if Discord integration is available
     */
    public function isAvailable(): bool {
        return $this->discord->isConfigured();
    }
    
    /**
     * Get the Discord API instance
     */
    public function getAPI(): DiscordAPI {
        return $this->discord;
    }
    
    // =========================================
    // NTML ENTRY NOTIFICATIONS
    // =========================================
    
    /**
     * Post an NTML entry to Discord
     * 
     * @param array $entry NTML entry data from tmi_entries table
     * @param string $channel Channel purpose (default: 'ntml_staging')
     * @return array|null Message object or null on error
     */
    public function postNtmlEntry(array $entry, string $channel = 'ntml_staging'): ?array {
        $type = $entry['entry_type'] ?? 'MISC';
        $emoji = self::EMOJI_MAP[$type] ?? '📋';
        $color = $this->getNtmlColor($type, $entry['status'] ?? 'ACTIVE');
        
        // Build title
        $title = "{$emoji} {$type}";
        if (!empty($entry['ctl_element'])) {
            $title .= " - {$entry['ctl_element']}";
        }
        
        // Build description
        $description = $this->buildNtmlDescription($entry);
        
        // Build fields
        $fields = [];
        
        // Restriction details
        if (!empty($entry['restriction_value'])) {
            $unit = $entry['restriction_unit'] ?? 'MIT';
            $fields[] = [
                'name' => 'Restriction',
                'value' => "{$entry['restriction_value']} {$unit}",
                'inline' => true
            ];
        }
        
        // Facilities
        if (!empty($entry['requesting_facility'])) {
            $fields[] = [
                'name' => 'Requesting',
                'value' => $entry['requesting_facility'],
                'inline' => true
            ];
        }
        if (!empty($entry['providing_facility'])) {
            $fields[] = [
                'name' => 'Providing',
                'value' => $entry['providing_facility'],
                'inline' => true
            ];
        }
        
        // Time
        if (!empty($entry['valid_from']) || !empty($entry['valid_until'])) {
            $timeStr = $this->formatTimeRange($entry['valid_from'] ?? null, $entry['valid_until'] ?? null);
            $fields[] = [
                'name' => 'Valid',
                'value' => $timeStr,
                'inline' => false
            ];
        }
        
        // Reason
        if (!empty($entry['reason_code'])) {
            $reason = $entry['reason_code'];
            if (!empty($entry['reason_detail'])) {
                $reason .= ": {$entry['reason_detail']}";
            }
            $fields[] = [
                'name' => 'Reason',
                'value' => $reason,
                'inline' => false
            ];
        }
        
        // Build embed
        $embed = DiscordAPI::buildEmbed([
            'title' => $title,
            'description' => $description,
            'color' => $color,
            'timestamp' => gmdate('c'),
            'fields' => $fields,
            'footer' => [
                'text' => "NTML Entry #{$entry['entry_id']} • {$entry['status']}"
            ]
        ]);
        
        return $this->discord->createMessage($channel, [
            'embeds' => [$embed]
        ]);
    }
    
    /**
     * Post NTML cancellation notice
     */
    public function postNtmlCancellation(array $entry, string $channel = 'ntml_staging'): ?array {
        $type = $entry['entry_type'] ?? 'MISC';
        $emoji = '❌';
        
        $title = "{$emoji} {$type} CANCELLED";
        if (!empty($entry['ctl_element'])) {
            $title .= " - {$entry['ctl_element']}";
        }
        
        $description = "The {$type} has been cancelled.";
        if (!empty($entry['cancel_reason'])) {
            $description .= "\n\n**Reason:** {$entry['cancel_reason']}";
        }
        
        $embed = DiscordAPI::buildEmbed([
            'title' => $title,
            'description' => $description,
            'color' => self::COLOR_GRAY,
            'timestamp' => gmdate('c'),
            'footer' => [
                'text' => "NTML Entry #{$entry['entry_id']} • CANCELLED"
            ]
        ]);
        
        return $this->discord->createMessage($channel, [
            'embeds' => [$embed]
        ]);
    }
    
    // =========================================
    // ADVISORY NOTIFICATIONS
    // =========================================
    
    /**
     * Post an advisory to Discord
     * 
     * @param array $advisory Advisory data from tmi_advisories table
     * @param string $channel Channel purpose (default: 'advzy_staging')
     * @return array|null Message object or null on error
     */
    public function postAdvisory(array $advisory, string $channel = 'advzy_staging'): ?array {
        $type = $advisory['advisory_type'] ?? 'GENERAL';
        $emoji = self::EMOJI_MAP[$type] ?? '📢';
        $color = $this->getAdvisoryColor($type, $advisory['status'] ?? 'ACTIVE');
        
        // Build title
        $advNum = $advisory['advisory_number'] ?? 'ADVZY';
        $title = "{$emoji} {$advNum}";
        if (!empty($advisory['ctl_element'])) {
            $title .= " - {$advisory['ctl_element']} {$type}";
        } else {
            $title .= " - {$type}";
        }
        
        // Build description from subject and body
        $description = '';
        if (!empty($advisory['subject'])) {
            $description = "**{$advisory['subject']}**\n\n";
        }
        if (!empty($advisory['body_text'])) {
            $description .= $advisory['body_text'];
        }
        
        // Truncate if too long
        if (strlen($description) > 4000) {
            $description = substr($description, 0, 3997) . '...';
        }
        
        // Build fields
        $fields = [];
        
        // Time
        if (!empty($advisory['effective_from']) || !empty($advisory['effective_until'])) {
            $timeStr = $this->formatTimeRange(
                $advisory['effective_from'] ?? null, 
                $advisory['effective_until'] ?? null
            );
            $fields[] = [
                'name' => 'Effective',
                'value' => $timeStr,
                'inline' => false
            ];
        }
        
        // Program rate (for GDP/AFP)
        if (!empty($advisory['program_rate'])) {
            $fields[] = [
                'name' => 'Program Rate',
                'value' => "{$advisory['program_rate']}/hr",
                'inline' => true
            ];
        }
        
        // Delay cap
        if (!empty($advisory['delay_cap'])) {
            $fields[] = [
                'name' => 'Delay Cap',
                'value' => "{$advisory['delay_cap']} min",
                'inline' => true
            ];
        }
        
        // Reroute info
        if (!empty($advisory['reroute_string'])) {
            $routeStr = $advisory['reroute_string'];
            if (strlen($routeStr) > 1000) {
                $routeStr = substr($routeStr, 0, 997) . '...';
            }
            $fields[] = [
                'name' => 'Route',
                'value' => "```{$routeStr}```",
                'inline' => false
            ];
        }
        
        // MIT info
        if (!empty($advisory['mit_miles'])) {
            $mitType = $advisory['mit_type'] ?? 'MIT';
            $fix = $advisory['mit_fix'] ?? '';
            $fields[] = [
                'name' => $mitType,
                'value' => "{$advisory['mit_miles']} miles" . ($fix ? " at {$fix}" : ''),
                'inline' => true
            ];
        }
        
        // Reason
        if (!empty($advisory['reason_code'])) {
            $reason = $advisory['reason_code'];
            if (!empty($advisory['reason_detail'])) {
                $reason .= ": {$advisory['reason_detail']}";
            }
            $fields[] = [
                'name' => 'Reason',
                'value' => $reason,
                'inline' => false
            ];
        }
        
        // Build embed
        $embed = DiscordAPI::buildEmbed([
            'title' => $title,
            'description' => $description,
            'color' => $color,
            'timestamp' => gmdate('c'),
            'fields' => $fields,
            'footer' => [
                'text' => "Advisory #{$advisory['advisory_id']} • Rev {$advisory['revision_number']}"
            ]
        ]);
        
        // Determine if we should ping anyone
        $content = '';
        if (in_array($type, ['GS', 'GDP', 'AFP', 'CTOP']) && ($advisory['status'] ?? '') === 'ACTIVE') {
            // Could add role mentions here
            // $content = DiscordAPI::mentionRole('ROLE_ID');
        }
        
        $messageData = ['embeds' => [$embed]];
        if ($content) {
            $messageData['content'] = $content;
        }
        
        return $this->discord->createMessage($channel, $messageData);
    }
    
    /**
     * Post advisory cancellation notice
     */
    public function postAdvisoryCancellation(array $advisory, string $channel = 'advzy_staging'): ?array {
        $type = $advisory['advisory_type'] ?? 'GENERAL';
        $advNum = $advisory['advisory_number'] ?? 'ADVZY';
        
        $title = "❌ {$advNum} CANCELLED";
        if (!empty($advisory['ctl_element'])) {
            $title .= " - {$advisory['ctl_element']} {$type}";
        }
        
        $description = "The advisory has been cancelled.";
        if (!empty($advisory['cancel_reason'])) {
            $description .= "\n\n**Reason:** {$advisory['cancel_reason']}";
        }
        
        $embed = DiscordAPI::buildEmbed([
            'title' => $title,
            'description' => $description,
            'color' => self::COLOR_GRAY,
            'timestamp' => gmdate('c'),
            'footer' => [
                'text' => "Advisory #{$advisory['advisory_id']} • CANCELLED"
            ]
        ]);
        
        return $this->discord->createMessage($channel, [
            'embeds' => [$embed]
        ]);
    }
    
    // =========================================
    // GDT PROGRAM NOTIFICATIONS
    // =========================================
    
    /**
     * Post GDT program activation
     */
    public function postGdtActivation(array $program, string $channel = 'advzy_staging'): ?array {
        $type = $program['program_type'] ?? 'GS';
        $baseType = explode('-', $type)[0]; // GS, GDP, or AFP
        $emoji = self::EMOJI_MAP[$baseType] ?? '⏱️';
        
        // Different colors for different types
        $color = match($baseType) {
            'GS' => self::COLOR_DANGER,
            'GDP' => self::COLOR_PURPLE,
            'AFP' => self::COLOR_INFO,
            default => self::COLOR_WARNING
        };
        
        $title = "{$emoji} {$type} ACTIVATED - {$program['ctl_element']}";
        
        // Build description
        $description = match($baseType) {
            'GS' => "A Ground Stop has been activated for **{$program['ctl_element']}**.",
            'GDP' => "A Ground Delay Program has been activated for **{$program['ctl_element']}**.",
            'AFP' => "An Airspace Flow Program has been activated for **{$program['ctl_element']}**.",
            default => "A program has been activated for **{$program['ctl_element']}**."
        };
        
        if (!empty($program['cause_text'])) {
            $description .= "\n\n**Cause:** {$program['cause_text']}";
        }
        
        // Build fields
        $fields = [];
        
        // Time range
        $fields[] = [
            'name' => 'Program Window',
            'value' => $this->formatTimeRange($program['start_utc'], $program['end_utc']),
            'inline' => false
        ];
        
        // Rate (GDP/AFP only)
        if (!empty($program['program_rate']) && $baseType !== 'GS') {
            $fields[] = [
                'name' => 'Arrival Rate',
                'value' => "{$program['program_rate']}/hr",
                'inline' => true
            ];
        }
        
        // Delay limit
        if (!empty($program['delay_limit_min']) && $baseType !== 'GS') {
            $fields[] = [
                'name' => 'Delay Limit',
                'value' => "{$program['delay_limit_min']} min",
                'inline' => true
            ];
        }
        
        // Scope
        if (!empty($program['scope_json'])) {
            $scope = json_decode($program['scope_json'], true);
            if ($scope) {
                $scopeStr = $this->formatScope($scope);
                $fields[] = [
                    'name' => 'Scope',
                    'value' => $scopeStr,
                    'inline' => false
                ];
            }
        }
        
        // Flight counts (if available)
        if (!empty($program['total_flights'])) {
            $controlled = $program['controlled_flights'] ?? 0;
            $exempt = $program['exempt_flights'] ?? 0;
            $fields[] = [
                'name' => 'Affected Flights',
                'value' => "Total: {$program['total_flights']} | Controlled: {$controlled} | Exempt: {$exempt}",
                'inline' => false
            ];
        }
        
        $embed = DiscordAPI::buildEmbed([
            'title' => $title,
            'description' => $description,
            'color' => $color,
            'timestamp' => gmdate('c'),
            'fields' => $fields,
            'footer' => [
                'text' => "Program #{$program['program_id']} • {$program['adv_number']}"
            ]
        ]);
        
        return $this->discord->createMessage($channel, [
            'embeds' => [$embed]
        ]);
    }
    
    /**
     * Post GDT program extension
     */
    public function postGdtExtension(array $program, string $oldEndUtc, string $channel = 'advzy_staging'): ?array {
        $type = $program['program_type'] ?? 'GS';
        $baseType = explode('-', $type)[0];
        
        $title = "⏰ {$type} EXTENDED - {$program['ctl_element']}";
        
        $description = "The {$baseType} has been extended.";
        
        $fields = [
            [
                'name' => 'Previous End',
                'value' => $this->formatTimestamp($oldEndUtc),
                'inline' => true
            ],
            [
                'name' => 'New End',
                'value' => $this->formatTimestamp($program['end_utc']),
                'inline' => true
            ]
        ];
        
        $embed = DiscordAPI::buildEmbed([
            'title' => $title,
            'description' => $description,
            'color' => self::COLOR_WARNING,
            'timestamp' => gmdate('c'),
            'fields' => $fields,
            'footer' => [
                'text' => "Program #{$program['program_id']} • {$program['adv_number']}"
            ]
        ]);
        
        return $this->discord->createMessage($channel, [
            'embeds' => [$embed]
        ]);
    }
    
    /**
     * Post GDT program purge/completion
     */
    public function postGdtPurge(array $program, string $channel = 'advzy_staging'): ?array {
        $type = $program['program_type'] ?? 'GS';
        $baseType = explode('-', $type)[0];
        
        $title = "✅ {$type} PURGED - {$program['ctl_element']}";
        
        $description = "The {$baseType} has ended and been purged.";
        
        $fields = [];
        
        // Final stats
        if (!empty($program['avg_delay_min'])) {
            $fields[] = [
                'name' => 'Average Delay',
                'value' => round($program['avg_delay_min']) . ' min',
                'inline' => true
            ];
        }
        if (!empty($program['max_delay_min'])) {
            $fields[] = [
                'name' => 'Max Delay',
                'value' => "{$program['max_delay_min']} min",
                'inline' => true
            ];
        }
        if (!empty($program['total_flights'])) {
            $fields[] = [
                'name' => 'Total Flights',
                'value' => (string)$program['total_flights'],
                'inline' => true
            ];
        }
        
        $embed = DiscordAPI::buildEmbed([
            'title' => $title,
            'description' => $description,
            'color' => self::COLOR_SUCCESS,
            'timestamp' => gmdate('c'),
            'fields' => $fields,
            'footer' => [
                'text' => "Program #{$program['program_id']} • COMPLETED"
            ]
        ]);
        
        return $this->discord->createMessage($channel, [
            'embeds' => [$embed]
        ]);
    }
    
    // =========================================
    // REROUTE NOTIFICATIONS
    // =========================================
    
    /**
     * Post reroute activation
     */
    public function postRerouteActivation(array $reroute, string $channel = 'advzy_staging'): ?array {
        $title = "↩️ REROUTE ACTIVATED - {$reroute['name']}";
        
        $description = '';
        if (!empty($reroute['adv_number'])) {
            $description = "**{$reroute['adv_number']}**\n\n";
        }
        
        // Origin/dest criteria
        $criteria = [];
        if (!empty($reroute['origin_criteria'])) {
            $criteria[] = "**From:** {$reroute['origin_criteria']}";
        }
        if (!empty($reroute['dest_criteria'])) {
            $criteria[] = "**To:** {$reroute['dest_criteria']}";
        }
        if ($criteria) {
            $description .= implode("\n", $criteria);
        }
        
        $fields = [];
        
        // Time
        if (!empty($reroute['valid_from']) || !empty($reroute['valid_until'])) {
            $fields[] = [
                'name' => 'Valid',
                'value' => $this->formatTimeRange($reroute['valid_from'], $reroute['valid_until']),
                'inline' => false
            ];
        }
        
        // Reason
        if (!empty($reroute['reason_text'])) {
            $fields[] = [
                'name' => 'Reason',
                'value' => $reroute['reason_text'],
                'inline' => false
            ];
        }
        
        $embed = DiscordAPI::buildEmbed([
            'title' => $title,
            'description' => $description,
            'color' => self::COLOR_INFO,
            'timestamp' => gmdate('c'),
            'fields' => $fields,
            'footer' => [
                'text' => "Reroute #{$reroute['reroute_id']} • ACTIVE"
            ]
        ]);
        
        return $this->discord->createMessage($channel, [
            'embeds' => [$embed]
        ]);
    }
    
    // =========================================
    // HELPER METHODS
    // =========================================
    
    /**
     * Get color for NTML entry type
     */
    private function getNtmlColor(string $type, string $status): int {
        if ($status === 'CANCELLED' || $status === 'EXPIRED') {
            return self::COLOR_GRAY;
        }
        
        return match($type) {
            'MIT' => self::COLOR_DANGER,
            'MINIT' => self::COLOR_WARNING,
            'DELAY' => self::COLOR_WARNING,
            'APREQ' => self::COLOR_INFO,
            'CONTINGENCY' => self::COLOR_DANGER,
            'REROUTE' => self::COLOR_PURPLE,
            default => self::COLOR_INFO
        };
    }
    
    /**
     * Get color for advisory type
     */
    private function getAdvisoryColor(string $type, string $status): int {
        if ($status === 'CANCELLED' || $status === 'EXPIRED') {
            return self::COLOR_GRAY;
        }
        
        return match($type) {
            'GS' => self::COLOR_DANGER,
            'GDP', 'GDP-DAS', 'GDP-GAAP', 'GDP-UDP' => self::COLOR_PURPLE,
            'AFP', 'AFP-DAS', 'AFP-GAAP', 'AFP-UDP' => self::COLOR_INFO,
            'CTOP' => self::COLOR_WARNING,
            'REROUTE' => self::COLOR_INFO,
            default => self::COLOR_INFO
        };
    }
    
    /**
     * Build NTML description from entry data
     */
    private function buildNtmlDescription(array $entry): string {
        $parts = [];
        
        if (!empty($entry['condition_text'])) {
            $parts[] = $entry['condition_text'];
        }
        
        if (!empty($entry['qualifiers'])) {
            $parts[] = "**Qualifiers:** {$entry['qualifiers']}";
        }
        
        if (!empty($entry['exclusions'])) {
            $parts[] = "**Exclusions:** {$entry['exclusions']}";
        }
        
        return implode("\n", $parts) ?: 'No additional details.';
    }
    
    /**
     * Format time range for display
     */
    private function formatTimeRange(?string $from, ?string $until): string {
        $parts = [];
        
        if ($from) {
            $ts = strtotime($from);
            $parts[] = DiscordAPI::formatTimestamp($ts, 'f');
        } else {
            $parts[] = 'Now';
        }
        
        $parts[] = '→';
        
        if ($until) {
            $ts = strtotime($until);
            $parts[] = DiscordAPI::formatTimestamp($ts, 'f');
        } else {
            $parts[] = 'Until further notice';
        }
        
        return implode(' ', $parts);
    }
    
    /**
     * Format single timestamp
     */
    private function formatTimestamp(string $datetime): string {
        $ts = strtotime($datetime);
        return DiscordAPI::formatTimestamp($ts, 'f');
    }
    
    /**
     * Format scope JSON for display
     */
    private function formatScope(array $scope): string {
        $type = $scope['type'] ?? 'UNKNOWN';
        
        return match($type) {
            'TIER' => "Tier {$scope['tier']}",
            'DISTANCE' => "{$scope['distance_nm']}nm radius",
            'CENTERS' => implode(', ', $scope['centers'] ?? []),
            'MANUAL' => implode(', ', $scope['origins'] ?? []),
            default => $type
        };
    }
    
    /**
     * Update an existing Discord message (for status changes)
     */
    public function updateMessage(string $channelId, string $messageId, array $embed): ?array {
        return $this->discord->editMessage($channelId, $messageId, [
            'embeds' => [$embed]
        ]);
    }
    
    /**
     * Delete a Discord message
     */
    public function deleteMessage(string $channelId, string $messageId): bool {
        return $this->discord->deleteMessage($channelId, $messageId);
    }
}
