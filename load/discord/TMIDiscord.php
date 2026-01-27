<?php
/**
 * TMI Discord Integration Module
 * 
 * Handles Discord notifications for Traffic Management Initiatives.
 * 
 * NTML Format: Based on TMIs.pdf vATCSCC NTML Guide
 * Advisory Format: Based on Advisories_and_General_Messages_v1_3.pdf
 *                  and real-world ADVZY_2020.txt patterns (2020-2025)
 * 
 * @package PERTI
 * @subpackage TMI/Discord
 * @version 3.5.0
 * 
 * Changelog v3.5.0 (2026-01-27):
 * - Added MultiDiscordAPI integration for multi-organization posting
 * - New methods: postNtmlEntryToOrgs(), postAdvisoryToOrgs()
 * - New methods: promoteNtmlEntry(), promoteAdvisory()
 * - Added cross-border TMI detection via determineTargetOrgs()
 * - Public message builders: buildNTMLMessageFromEntry(), buildAdvisoryMessage()
 * 
 * Changelog v3.4.0 (2026-01-18):
 * - Fixed delay entry formatting for all three types:
 *   - D/D (Departure Delay): "D/D from {location}, {delay}/{time}"
 *   - E/D (En Route Delay): "{ARTCC} E/D for {dest}, {delay}/{time}/{count} ACFT"
 *   - A/D (Arrival Delay): "{sector} A/D to {dest}, {delay}/{time} NAVAID:{fix}"
 * - Fixed NTML restriction entry field ordering (EXCL before times)
 * - Added support for RUNWAY and OTHER reason codes
 * - Improved reason format handling (REASON:DETAIL pattern)
 * - Added TBM zone support in restriction entries
 * 
 * Changelog v3.3.0 (2026-01-17):
 * - Updated Route Advisory to 2025 conventions
 * - Added FCA, Operations Plan, Reroute Cancellation, Informational/Hotline formatters
 */

require_once __DIR__ . '/DiscordAPI.php';
require_once __DIR__ . '/MultiDiscordAPI.php';

class TMIDiscord {
    
    private $discord;
    private $multiDiscord;
    private $channels;
    
    /** @var int Maximum line length per IATA Type B message format */
    private const MAX_LINE_LENGTH = 68;
    
    /**
     * Constructor
     * 
     * @param DiscordAPI|null $discord Discord API instance (creates new if null)
     * @param MultiDiscordAPI|null $multiDiscord Multi-org Discord API (creates new if null)
     */
    public function __construct($discord = null, $multiDiscord = null) {
        $this->discord = $discord ?? new DiscordAPI();
        $this->multiDiscord = $multiDiscord ?? new MultiDiscordAPI();
        $this->channels = $this->discord->getConfiguredChannels();
    }
    
    /**
     * Check if Discord integration is available
     */
    public function isAvailable(): bool {
        return $this->discord->isConfigured();
    }
    
    /**
     * Check if multi-org Discord is available
     */
    public function isMultiOrgAvailable(): bool {
        return $this->multiDiscord->isConfigured();
    }
    
    /**
     * Get the Discord API instance
     */
    public function getAPI(): DiscordAPI {
        return $this->discord;
    }
    
    /**
     * Get the Multi-Discord API instance
     */
    public function getMultiDiscordAPI(): MultiDiscordAPI {
        return $this->multiDiscord;
    }
    
    // =========================================
    // MULTI-ORG POSTING METHODS
    // =========================================
    
    /**
     * Post an NTML entry to multiple organizations
     * 
     * @param array $entry NTML entry data
     * @param array $orgCodes Organization codes to post to
     * @param bool $staging Post to staging channels (true) or production (false)
     * @return array Results keyed by org code
     */
    public function postNtmlEntryToOrgs(array $entry, array $orgCodes, bool $staging = true): array {
        $message = $this->formatNtmlMessage($entry);
        $messageData = ['content' => "```\n{$message}\n```"];
        
        if ($staging) {
            return $this->multiDiscord->postToStaging($orgCodes, 'ntml', $messageData);
        } else {
            return $this->multiDiscord->postToProduction($orgCodes, 'ntml', $messageData);
        }
    }
    
    /**
     * Post an advisory to multiple organizations
     * 
     * @param array $advisory Advisory data
     * @param array $orgCodes Organization codes to post to
     * @param bool $staging Post to staging channels (true) or production (false)
     * @return array Results keyed by org code
     */
    public function postAdvisoryToOrgs(array $advisory, array $orgCodes, bool $staging = true): array {
        $message = $this->formatAdvisoryMessage($advisory);
        $messageData = ['content' => "```\n{$message}\n```"];
        
        if ($staging) {
            return $this->multiDiscord->postToStaging($orgCodes, 'advisory', $messageData);
        } else {
            return $this->multiDiscord->postToProduction($orgCodes, 'advisory', $messageData);
        }
    }
    
    /**
     * Promote NTML entry from staging to production
     * 
     * @param array $entry NTML entry data
     * @param array $orgCodes Organization codes to promote to
     * @param array|null $stagingMessageIds Optional staging message IDs to delete
     * @return array Results keyed by org code
     */
    public function promoteNtmlEntry(array $entry, array $orgCodes, ?array $stagingMessageIds = null): array {
        $message = $this->formatNtmlMessage($entry);
        $messageData = ['content' => "```\n{$message}\n```"];
        
        return $this->multiDiscord->promoteToProduction($orgCodes, 'ntml', $messageData, $stagingMessageIds);
    }
    
    /**
     * Promote advisory from staging to production
     * 
     * @param array $advisory Advisory data
     * @param array $orgCodes Organization codes to promote to
     * @param array|null $stagingMessageIds Optional staging message IDs to delete
     * @return array Results keyed by org code
     */
    public function promoteAdvisory(array $advisory, array $orgCodes, ?array $stagingMessageIds = null): array {
        $message = $this->formatAdvisoryMessage($advisory);
        $messageData = ['content' => "```\n{$message}\n```"];
        
        return $this->multiDiscord->promoteToProduction($orgCodes, 'advisory', $messageData, $stagingMessageIds);
    }
    
    /**
     * Build NTML message from entry data (public for external use)
     * 
     * @param array $entry Entry data
     * @return string Formatted NTML message
     */
    public function buildNTMLMessageFromEntry(array $entry): string {
        return $this->formatNtmlMessage($entry);
    }
    
    /**
     * Build advisory message from data (public for external use)
     * 
     * @param array $advisory Advisory data
     * @return string Formatted advisory message
     */
    public function buildAdvisoryMessage(array $advisory): string {
        return $this->formatAdvisoryMessage($advisory);
    }
    
    /**
     * Get target organizations for entry based on cross-border detection
     * 
     * @param array $entry Entry data
     * @param string $userHomeOrg User's home organization
     * @param bool $isPrivileged Whether user has privileged posting rights
     * @return array Target organization codes
     */
    public function determineTargetOrgs(array $entry, string $userHomeOrg, bool $isPrivileged = false): array {
        return $this->multiDiscord->determineTargetOrgs($entry, $userHomeOrg, $isPrivileged);
    }
    
    /**
     * Get enabled organizations for UI
     * 
     * @return array Organization summaries
     */
    public function getAvailableOrgs(): array {
        return $this->multiDiscord->getOrgSummary();
    }
    
    // =========================================
    // NTML ENTRY NOTIFICATIONS
    // Format per TMIs.pdf NTML Guide
    // =========================================
    
    /**
     * Post an NTML entry to Discord
     */
    public function postNtmlEntry(array $entry, string $channel = 'ntml_staging'): ?array {
        $message = $this->formatNtmlMessage($entry);
        return $this->discord->createMessage($channel, [
            'content' => "```\n{$message}\n```"
        ]);
    }
    
    private function formatNtmlMessage(array $entry): string {
        $type = strtoupper($entry['entry_type'] ?? 'MIT');
        switch ($type) {
            case 'MIT':
            case 'MINIT':
            case 'STOP':
            case 'DSP':
            case 'APREQ':
            case 'TBM':
            case 'CFR':
                return $this->formatRestrictionEntry($entry);
            case 'DELAY':
                return $this->formatDelayEntry($entry);
            case 'CONFIG':
                return $this->formatConfigEntry($entry);
            default:
                return $this->formatGenericEntry($entry);
        }
    }
    
    /**
     * Format NTML restriction entry (MIT, MINIT, STOP, CFR, TBM, APREQ, DSP)
     * 
     * Real-world patterns from NTML_2020.txt:
     * - "17/2344    BOS via MERIT 15MIT VOLUME:VOLUME EXCL:NONE 2345-0000 ZBW:ZNY"
     * - "21/2325   PHX via SCOLE, HOGGZ 30 MIT PER STREAM EXCL:PROPS VOLUME:VOLUME 0030-0400 ZAB:ZLA"
     * - "18/2206    ATL TBM 3_WEST VOLUME:VOLUME EXCL:NONE 2230-0400 ZTL:ZJX,ZME,ZID,ZHU"
     * - "25/0114    MIA STOP RUNWAY:CONFIG CHG EXCL:NONE 0115-0130 ZMA:ZJX"
     */
    private function formatRestrictionEntry(array $data): string {
        $logTime = $this->formatLogTime();
        $flowType = strtolower($data['flow_type'] ?? 'arrivals');
        $airport = strtoupper($data['airport'] ?? $data['ctl_element'] ?? '');
        $fix = strtoupper($data['fix'] ?? $data['condition_text'] ?? '');
        $restrictionType = strtoupper($data['entry_type'] ?? 'MIT');
        $restrictionValue = $data['restriction_value'] ?? $data['distance'] ?? $data['minutes'] ?? '';
        
        // Build restriction string
        if ($restrictionType === 'STOP') {
            $restriction = 'STOP';
        } elseif ($restrictionType === 'TBM' && !empty($data['tbm_zone'])) {
            // TBM entries: "ATL TBM 3_WEST"
            $restriction = "TBM " . strtoupper($data['tbm_zone']);
        } else {
            $restriction = "{$restrictionValue}{$restrictionType}";
        }
        
        $qualifiers = $this->formatNtmlQualifiers($data['qualifiers'] ?? '');
        
        // Build optional parts in correct order
        $parts = [];
        
        // Aircraft type filter
        if (!empty($data['aircraft_type'])) {
            $parts[] = "TYPE:" . strtoupper($data['aircraft_type']);
        }
        
        // Speed restriction
        if (!empty($data['speed'])) {
            $parts[] = "SPD:" . ($data['speed_operator'] ?? '') . $data['speed'];
        }
        
        // Altitude restriction
        if (!empty($data['altitude'])) {
            $parts[] = "ALT:" . strtoupper($data['alt_type'] ?? 'AT') . strtoupper($data['altitude']);
        }
        
        // Exclusions come BEFORE reason (per real-world patterns)
        if (!empty($data['exclusions'])) {
            $parts[] = "EXCL:" . strtoupper($data['exclusions']);
        }
        
        // Reason code with optional detail (REASON:DETAIL format)
        $reasonCode = strtoupper($data['reason_code'] ?? '');
        $reasonDetail = strtoupper($data['reason_detail'] ?? '');
        if ($reasonCode) {
            $reasonStr = $reasonDetail ? "{$reasonCode}:{$reasonDetail}" : "{$reasonCode}:{$reasonCode}";
            $parts[] = $reasonStr;
        } elseif (!empty($data['volume'])) {
            $parts[] = "VOLUME:" . strtoupper($data['volume']);
        } elseif (!empty($data['weather'])) {
            $parts[] = "WEATHER:" . strtoupper($data['weather']);
        }
        
        // Valid times
        $validFrom = $this->formatTimeHHMM($data['valid_from'] ?? null);
        $validUntil = $this->formatTimeHHMM($data['valid_until'] ?? null);
        $parts[] = "{$validFrom}-{$validUntil}";
        
        // Facility pair
        $reqFac = strtoupper($data['requesting_facility'] ?? $data['req_facility_id'] ?? '');
        $provFac = strtoupper($data['providing_facility'] ?? $data['prov_facility_id'] ?? '');
        if ($reqFac && $provFac) {
            $parts[] = "{$reqFac}:{$provFac}";
        }
        
        $optionalStr = implode(' ', $parts);
        
        // Build final line based on entry type
        if ($restrictionType === 'TBM') {
            // TBM format: "ATL TBM 3_WEST VOLUME:VOLUME EXCL:NONE 2230-0400 ZTL:ZJX"
            $line = "{$logTime}    {$airport} {$restriction} {$optionalStr}";
        } elseif ($fix) {
            // Standard with fix: "BOS via MERIT 15MIT..."
            $line = "{$logTime}    {$airport} {$flowType} via {$fix} {$restriction}{$qualifiers} {$optionalStr}";
        } else {
            // Without fix: "BOS arrivals 15MIT..."
            $line = "{$logTime}    {$airport} {$flowType} {$restriction}{$qualifiers} {$optionalStr}";
        }
        
        return trim($line);
    }
    
    /**
     * Format NTML delay entry (D/D, E/D, A/D)
     * 
     * Real-world patterns from NTML_2020.txt:
     * - D/D (Departure Delay): "18/0010     D/D from JFK, +45/0010 VOLUME:VOLUME"
     * - E/D (En Route Delay):  "18/0019    ZDC E/D for BOS, +30/0019/13 ACFT VOLUME:VOLUME"
     * - A/D (Arrival Delay):   "25/0059    ZJX66 A/D to MIA, +Holding/0058 NAVAID:OMN STREAM VOLUME:VOLUME"
     */
    private function formatDelayEntry(array $data): string {
        $logTime = $this->formatLogTime();
        $delayType = strtoupper($data['delay_type'] ?? 'D/D');
        $location = strtoupper($data['location'] ?? $data['delay_facility'] ?? $data['ctl_element'] ?? '');
        $reportingFacility = strtoupper($data['reporting_facility'] ?? '');
        
        // Determine delay value with trend indicator
        $delayValue = $data['delay_value'] ?? $data['longest_delay'] ?? '';
        $trend = strtolower($data['delay_trend'] ?? 'steady');
        $delaySign = match($trend) {
            'increasing', 'inc', 'initiating' => '+',
            'decreasing', 'dec' => '-',
            default => ''
        };
        
        // Handle holding
        if (!empty($data['holding']) && $data['holding'] !== 'no') {
            $delayValue = ($delaySign ?: '+') . 'Holding';
        } else {
            $delayValue = "{$delaySign}{$delayValue}";
        }
        
        $time = $this->formatTimeHHMM($data['report_time'] ?? null);
        $acftCount = $data['flights_delayed'] ?? $data['aircraft_count'] ?? '';
        
        // Build reason string
        $reasonCode = strtoupper($data['reason_code'] ?? 'VOLUME');
        $reasonDetail = strtoupper($data['reason_detail'] ?? $reasonCode);
        $reasonStr = "{$reasonCode}:{$reasonDetail}";
        
        // Format based on delay type
        switch ($delayType) {
            case 'D/D':
                // Departure Delay: "D/D from JFK, +45/0010 VOLUME:VOLUME"
                // Note: D/D typically doesn't include aircraft count in real data
                if ($acftCount) {
                    return trim("{$logTime}    D/D from {$location}, {$delayValue}/{$time}/{$acftCount} ACFT {$reasonStr}");
                }
                return trim("{$logTime}    D/D from {$location}, {$delayValue}/{$time} {$reasonStr}");
                
            case 'E/D':
                // En Route Delay: "ZDC E/D for BOS, +30/0019/13 ACFT VOLUME:VOLUME"
                $prefix = $reportingFacility ? "{$reportingFacility} " : '';
                return trim("{$logTime}    {$prefix}E/D for {$location}, {$delayValue}/{$time}/{$acftCount} ACFT {$reasonStr}");
                
            case 'A/D':
                // Arrival Delay: "ZJX66 A/D to MIA, +Holding/0058 NAVAID:OMN STREAM VOLUME:VOLUME"
                $prefix = $reportingFacility ? "{$reportingFacility} " : '';
                $optParts = [];
                
                // NAVAID/fix for A/D
                if (!empty($data['fix'])) {
                    $optParts[] = "NAVAID:" . strtoupper($data['fix']);
                }
                
                // Stream indicator
                if (!empty($data['stream'])) {
                    $optParts[] = strtoupper($data['stream']);
                }
                
                $optParts[] = $reasonStr;
                $optStr = implode(' ', $optParts);
                
                return trim("{$logTime}    {$prefix}A/D to {$location}, {$delayValue}/{$time} {$optStr}");
                
            default:
                // Fallback to generic format
                return trim("{$logTime}    {$delayType} {$location}, {$delayValue}/{$time} {$reasonStr}");
        }
    }
    
    private function formatConfigEntry(array $data): string {
        $logTime = $this->formatLogTime();
        $airport = strtoupper($data['airport'] ?? $data['ctl_element'] ?? '');
        $weather = strtoupper($data['weather'] ?? 'VMC');
        $arrRwys = strtoupper($data['arr_runways'] ?? '');
        $depRwys = strtoupper($data['dep_runways'] ?? '');
        $aar = $data['aar'] ?? '';
        $adr = $data['adr'] ?? '';
        $aarType = $data['aar_type'] ?? 'Strat';
        $aarAdjust = !empty($data['aar_adjustment']) ? " AAR Adjustment:" . strtoupper($data['aar_adjustment']) : '';
        return trim("{$logTime}    {$airport} {$weather} ARR:{$arrRwys} DEP:{$depRwys} AAR({$aarType}):{$aar}{$aarAdjust} ADR:{$adr}");
    }
    
    private function formatGenericEntry(array $data): string {
        $logTime = $this->formatLogTime();
        $type = strtoupper($data['entry_type'] ?? 'TXT');
        $text = $data['text'] ?? $data['condition_text'] ?? '';
        return "{$logTime}    {$type} {$text}";
    }
    
    private function formatNtmlQualifiers($qualifiers): string {
        if (empty($qualifiers)) return '';
        $quals = is_string($qualifiers) ? explode(',', $qualifiers) : (array)$qualifiers;
        $formatted = array_map(fn($q) => str_replace('_', ' ', strtoupper(trim($q))), $quals);
        return ' ' . implode(' ', $formatted);
    }
    
    public function postNtmlCancellation(array $entry, string $channel = 'ntml_staging'): ?array {
        $logTime = $this->formatLogTime();
        $type = strtoupper($entry['entry_type'] ?? 'MIT');
        $airport = strtoupper($entry['airport'] ?? $entry['ctl_element'] ?? '');
        $cancelReason = $entry['cancel_reason'] ?? '';
        $message = "{$logTime}    {$airport} {$type} CANCELLED" . ($cancelReason ? " - {$cancelReason}" : '');
        return $this->discord->createMessage($channel, ['content' => "```\n{$message}\n```"]);
    }
    
    // =========================================
    // ADVISORY NOTIFICATIONS - Ground Programs
    // =========================================
    
    public function postGroundStopAdvisory(array $data, string $channel = 'advzy_staging'): ?array {
        return $this->discord->createMessage($channel, ['content' => "```\n" . $this->formatGroundStopAdvisory($data) . "\n```"]);
    }
    
    private function formatGroundStopAdvisory(array $data): string {
        $advNum = $this->cleanAdvisoryNumber($data['advisory_number'] ?? '001');
        $airport = strtoupper($data['ctl_element'] ?? $data['airport'] ?? 'XXX');
        $artcc = strtoupper($data['artcc'] ?? 'ZXX');
        $headerDate = $this->formatDateMMDDYYYY($data['issue_date'] ?? null);
        $adlTime = $this->formatTimeHHMM($data['adl_time'] ?? null) . 'Z';
        $gsStart = $this->formatProgramTime($data['start_utc'] ?? $data['gs_start'] ?? null);
        $gsEnd = $this->formatProgramTime($data['end_utc'] ?? $data['gs_end'] ?? null);
        
        $lines = [
            "vATCSCC ADVZY {$advNum} {$airport}/{$artcc} {$headerDate} CDM GROUND STOP",
            "CTL ELEMENT: {$airport}",
            "ELEMENT TYPE: APT",
            "ADL TIME: {$adlTime}",
            "GROUND STOP PERIOD: {$gsStart} - {$gsEnd}",
        ];
        
        if (!empty($data['cumulative_start'])) {
            $cumStart = $this->formatProgramTime($data['cumulative_start']);
            $cumEnd = $this->formatProgramTime($data['cumulative_end'] ?? $data['end_utc']);
            $lines[] = "CUMULATIVE PROGRAM PERIOD: {$cumStart} - {$cumEnd}";
        }
        
        $lines = array_merge($lines, $this->formatFlightInclusions($data));

        if (!empty($data['dep_facilities'])) {
            $lines[] = "DEP FACILITIES INCLUDED: " . $this->formatDepFacilitiesWithScope($data);
        }
        
        $currDelays = ($data['curr_total_delay'] ?? '0') . ' / ' . ($data['curr_max_delay'] ?? '0') . ' / ' . ($data['curr_avg_delay'] ?? '0');
        $prevDelays = ($data['prev_total_delay'] ?? '0') . ' / ' . ($data['prev_max_delay'] ?? '0') . ' / ' . ($data['prev_avg_delay'] ?? '0');
        $newDelays = ($data['new_total_delay'] ?? '0') . ' / ' . ($data['new_max_delay'] ?? '0') . ' / ' . ($data['new_avg_delay'] ?? '0');
        
        $lines[] = "CURRENT TOTAL, MAXIMUM, AVERAGE DELAYS: {$currDelays}";
        $lines[] = "PREVIOUS TOTAL, MAXIMUM, AVERAGE DELAYS: {$prevDelays}";
        $lines[] = "NEW TOTAL, MAXIMUM, AVERAGE DELAYS: {$newDelays}";
        $lines[] = "PROBABILITY OF EXTENSION: " . strtoupper($data['prob_extension'] ?? 'MEDIUM');
        
        $condition = strtoupper($data['impacting_condition'] ?? $data['reason_code'] ?? 'WEATHER');
        $conditionText = $data['condition_text'] ?? '';
        $conditionLine = "IMPACTING CONDITION: {$condition}" . ($conditionText ? " / {$conditionText}" : '');
        $lines[] = strlen($conditionLine) > self::MAX_LINE_LENGTH 
            ? $this->wrapFieldWithHangingIndent('IMPACTING CONDITION:', "{$condition}" . ($conditionText ? " / {$conditionText}" : ''))
            : $conditionLine;
        
        if (!empty($data['comments'])) {
            $lines[] = $this->wrapFieldWithHangingIndent('COMMENTS:', $data['comments']);
        }
        
        $lines[] = '';
        $lines[] = $this->formatValidTimeRangeWithSpaces($data['start_utc'] ?? null, $data['end_utc'] ?? null);
        $lines[] = $this->formatSignature();
        
        return implode("\n", $lines);
    }
    
    public function postGroundStopCancellation(array $data, string $channel = 'advzy_staging'): ?array {
        return $this->discord->createMessage($channel, ['content' => "```\n" . $this->formatGroundStopCancellation($data) . "\n```"]);
    }
    
    private function formatGroundStopCancellation(array $data): string {
        $advNum = $this->cleanAdvisoryNumber($data['advisory_number'] ?? '001');
        $airport = strtoupper($data['ctl_element'] ?? $data['airport'] ?? 'XXX');
        $artcc = strtoupper($data['artcc'] ?? 'ZXX');
        $headerDate = $this->formatDateMMDDYYYY($data['issue_date'] ?? null);
        $adlTime = $this->formatTimeHHMM($data['adl_time'] ?? null) . 'Z';
        $cnxStart = $this->formatProgramTime($data['start_utc'] ?? null);
        $cnxEnd = $this->formatProgramTime($data['end_utc'] ?? null);
        
        $lines = [
            "vATCSCC ADVZY {$advNum} {$airport}/{$artcc} {$headerDate} CDM GS CNX",
            "CTL ELEMENT: {$airport}",
            "ELEMENT TYPE: APT",
            "ADL TIME: {$adlTime}",
            "GS CNX PERIOD: {$cnxStart} - {$cnxEnd}",
        ];
        
        if (!empty($data['active_afp'])) {
            $lines[] = "FLIGHTS MAY RECEIVE NEW EDCTS DUE TO AN ACTIVE AFP: " . strtoupper($data['active_afp']);
        }
        if (!empty($data['comments'])) {
            $lines[] = $this->wrapFieldWithHangingIndent('COMMENTS:', $data['comments']);
        }
        
        $lines[] = '';
        $lines[] = $this->formatValidTimeRangeWithSpaces($data['start_utc'] ?? null, $data['end_utc'] ?? null);
        $lines[] = $this->formatSignature();
        
        return implode("\n", $lines);
    }
    
    public function postGDPAdvisory(array $data, string $channel = 'advzy_staging'): ?array {
        return $this->discord->createMessage($channel, ['content' => "```\n" . $this->formatGDPAdvisory($data) . "\n```"]);
    }
    
    private function formatGDPAdvisory(array $data): string {
        $advNum = $this->cleanAdvisoryNumber($data['advisory_number'] ?? '001');
        $airport = strtoupper($data['ctl_element'] ?? $data['airport'] ?? 'XXX');
        $artcc = strtoupper($data['artcc'] ?? 'ZXX');
        $headerDate = $this->formatDateMMDDYYYY($data['issue_date'] ?? null);
        $adlTime = $this->formatTimeHHMM($data['adl_time'] ?? null) . 'Z';
        $delayMode = strtoupper($data['delay_mode'] ?? 'DAS');
        $arrStart = $this->formatProgramTime($data['arr_start_utc'] ?? $data['start_utc'] ?? null);
        $arrEnd = $this->formatProgramTime($data['arr_end_utc'] ?? $data['end_utc'] ?? null);
        $cumStart = $this->formatProgramTime($data['cumulative_start'] ?? $data['start_utc'] ?? null);
        $cumEnd = $this->formatProgramTime($data['cumulative_end'] ?? $data['end_utc'] ?? null);
        $rate = is_array($data['program_rate'] ?? '') ? implode('/', $data['program_rate']) : ($data['program_rate'] ?? '30');
        
        $lines = [
            "vATCSCC ADVZY {$advNum} {$airport}/{$artcc} {$headerDate} CDM GROUND DELAY PROGRAM",
            "CTL ELEMENT: {$airport}",
            "ELEMENT TYPE: APT",
            "ADL TIME: {$adlTime}",
            "DELAY ASSIGNMENT MODE: {$delayMode}",
            "ARRIVALS ESTIMATED FOR: {$arrStart} - {$arrEnd}",
            "CUMULATIVE PROGRAM PERIOD: {$cumStart} - {$cumEnd}",
            "PROGRAM RATE: {$rate}",
        ];
        
        if (!empty($data['popup_factor'])) $lines[] = "POP-UP FACTOR: " . strtoupper($data['popup_factor']);
        $lines = array_merge($lines, $this->formatFlightInclusions($data));
        if (!empty($data['dep_scope'])) $lines[] = "DEPARTURE SCOPE: (" . strtoupper(is_array($data['dep_scope']) ? implode(' ', $data['dep_scope']) : $data['dep_scope']) . ")";
        if (!empty($data['additional_dep_facilities'])) $lines[] = "ADDITIONAL DEP FACILITIES INCLUDED: " . strtoupper($data['additional_dep_facilities']);
        if (!empty($data['exempt_dep_facilities'])) $lines[] = "EXEMPT DEP FACILITIES: " . strtoupper($data['exempt_dep_facilities']);
        if (!empty($data['delay_limit'])) $lines[] = "DELAY LIMIT: {$data['delay_limit']}";
        if (!empty($data['max_delay'])) $lines[] = "MAXIMUM DELAY: {$data['max_delay']}";
        if (!empty($data['avg_delay'])) $lines[] = "AVERAGE DELAY: {$data['avg_delay']}";
        
        $condition = strtoupper($data['impacting_condition'] ?? $data['reason_code'] ?? 'WEATHER');
        $conditionText = $data['condition_text'] ?? '';
        $conditionLine = "IMPACTING CONDITION: {$condition}" . ($conditionText ? " / {$conditionText}" : '');
        $lines[] = strlen($conditionLine) > self::MAX_LINE_LENGTH 
            ? $this->wrapFieldWithHangingIndent('IMPACTING CONDITION:', "{$condition}" . ($conditionText ? " / {$conditionText}" : ''))
            : $conditionLine;
        
        if (!empty($data['comments'])) $lines[] = $this->wrapFieldWithHangingIndent('COMMENTS:', $data['comments']);
        $lines[] = '';
        $lines[] = $this->formatValidTimeRangeWithSpaces($data['start_utc'] ?? null, $data['end_utc'] ?? null);
        $lines[] = $this->formatSignature();
        
        return implode("\n", $lines);
    }
    
    public function postGDPCancellation(array $data, string $channel = 'advzy_staging'): ?array {
        return $this->discord->createMessage($channel, ['content' => "```\n" . $this->formatGDPCancellation($data) . "\n```"]);
    }
    
    private function formatGDPCancellation(array $data): string {
        $advNum = $this->cleanAdvisoryNumber($data['advisory_number'] ?? '001');
        $airport = strtoupper($data['ctl_element'] ?? $data['airport'] ?? 'XXX');
        $artcc = strtoupper($data['artcc'] ?? 'ZXX');
        $headerDate = $this->formatDateMMDDYYYY($data['issue_date'] ?? null);
        $adlTime = $this->formatTimeHHMM($data['adl_time'] ?? null) . 'Z';
        $cnxStart = $this->formatProgramTime($data['start_utc'] ?? null);
        $cnxEnd = $this->formatProgramTime($data['end_utc'] ?? null);
        
        $lines = [
            "vATCSCC ADVZY {$advNum} {$airport}/{$artcc} {$headerDate} CDM GROUND DELAY PROGRAM CNX",
            "CTL ELEMENT: {$airport}",
            "ELEMENT TYPE: APT",
            "ADL TIME: {$adlTime}",
            "GDP CNX PERIOD: {$cnxStart} - {$cnxEnd}",
            "DISREGARD EDCTS FOR DEST {$airport}",
        ];
        
        if (!empty($data['comments'])) $lines[] = $this->wrapFieldWithHangingIndent('COMMENTS:', $data['comments']);
        $lines[] = '';
        $lines[] = $this->formatValidTimeRangeWithSpaces($data['start_utc'] ?? null, $data['end_utc'] ?? null);
        $lines[] = $this->formatSignature();
        
        return implode("\n", $lines);
    }
    
    // =========================================
    // ADVISORY NOTIFICATIONS - Route/FCA
    // =========================================
    
    public function postRerouteAdvisory(array $data, string $channel = 'advzy_staging'): ?array {
        return $this->discord->createMessage($channel, ['content' => "```\n" . $this->formatRerouteAdvisory($data) . "\n```"]);
    }
    
    /**
     * Format Reroute advisory per 2025 conventions
     * - CONSTRAINED AREA (was IMPACTED AREA in 2020)
     * - FLIGHT STATUS field
     * - ROUTES: label (was ROUTE:)
     * - Footer with spaces
     */
    private function formatRerouteAdvisory(array $data): string {
        $advNum = $this->cleanAdvisoryNumber($data['advisory_number'] ?? '001');
        $facility = strtoupper($data['facility'] ?? 'DCC');
        $headerDate = $this->formatDateMMDDYYYY($data['issue_date'] ?? null);
        $action = strtoupper($data['action'] ?? 'RQD');
        $routeType = strtoupper($data['route_type'] ?? 'ROUTE');
        $flIndicator = !empty($data['has_flight_list']) ? '/FL' : '';
        $routeName = strtoupper($data['route_name'] ?? $data['name'] ?? '');
        $constrainedArea = strtoupper($data['constrained_area'] ?? $data['impacted_area'] ?? '');
        $reason = strtoupper($data['reason'] ?? 'WEATHER');
        $includeTraffic = strtoupper($data['include_traffic'] ?? '');
        $facilities = $this->formatFacilitiesList($data['facilities'] ?? $data['facilities_included'] ?? []);
        $flightStatus = strtoupper($data['flight_status'] ?? 'ALL_FLIGHTS');
        
        $validType = strtoupper($data['valid_type'] ?? 'ETD');
        $startTime = $this->formatTimeDDHHMM($data['start_utc'] ?? $data['valid_from'] ?? null);
        $endTime = $this->formatTimeDDHHMM($data['end_utc'] ?? $data['valid_until'] ?? null);
        
        $validLine = match($validType) {
            'FCA', 'FCA_ENTRY' => "VALID: FCA ENTRY TIME FROM {$startTime} TO {$endTime}",
            'SIMPLE', 'RANGE' => "VALID: {$startTime} - {$endTime}",
            default => "VALID: ETD {$startTime} TO {$endTime}",
        };
        
        $probExt = strtoupper($data['prob_extension'] ?? 'NONE');
        $tmiId = 'RR' . $facility . $advNum;
        
        $lines = ["vATCSCC ADVZY {$advNum} {$facility} {$headerDate} {$routeType} {$action}{$flIndicator}"];
        
        if ($routeName) $lines[] = "NAME: {$routeName}";
        if ($constrainedArea) {
            $areaLine = "CONSTRAINED AREA: {$constrainedArea}";
            $lines[] = strlen($areaLine) > self::MAX_LINE_LENGTH ? $this->wrapFieldWithHangingIndent('CONSTRAINED AREA:', $constrainedArea) : $areaLine;
        }
        $lines[] = "REASON: {$reason}";
        if ($includeTraffic) {
            $trafficLine = "INCLUDE TRAFFIC: {$includeTraffic}";
            $lines[] = strlen($trafficLine) > self::MAX_LINE_LENGTH ? $this->wrapFieldWithHangingIndent('INCLUDE TRAFFIC:', $includeTraffic) : $trafficLine;
        }
        if ($facilities) $lines[] = "FACILITIES INCLUDED: {$facilities}";
        $lines[] = "FLIGHT STATUS: {$flightStatus}";
        $lines[] = $validLine;
        $lines[] = "PROBABILITY OF EXTENSION: {$probExt}";
        $lines[] = !empty($data['remarks']) ? $this->wrapFieldWithHangingIndent('REMARKS:', $data['remarks']) : "REMARKS:";
        $lines[] = !empty($data['associated_restrictions']) ? $this->wrapFieldWithHangingIndent('ASSOCIATED RESTRICTIONS:', $data['associated_restrictions']) : "ASSOCIATED RESTRICTIONS:";
        $lines[] = !empty($data['modifications']) ? $this->wrapFieldWithHangingIndent('MODIFICATIONS:', $data['modifications']) : "MODIFICATIONS:";
        $lines[] = "ROUTES:";
        $lines[] = "";
        $lines[] = $this->formatRouteTable($data['routes'] ?? []);
        $lines[] = "";
        $lines[] = "TMI ID: {$tmiId}";
        $lines[] = "{$startTime} - {$endTime}";
        $lines[] = $this->formatSignature();
        
        return implode("\n", $lines);
    }
    
    public function postRerouteCancellation(array $data, string $channel = 'advzy_staging'): ?array {
        return $this->discord->createMessage($channel, ['content' => "```\n" . $this->formatRerouteCancellation($data) . "\n```"]);
    }
    
    private function formatRerouteCancellation(array $data): string {
        $advNum = $this->cleanAdvisoryNumber($data['advisory_number'] ?? '001');
        $facility = strtoupper($data['facility'] ?? 'DCC');
        $headerDate = $this->formatDateMMDDYYYY($data['issue_date'] ?? null);
        $startTime = $this->formatTimeDDHHMM($data['start_utc'] ?? $data['valid_from'] ?? null);
        $endTime = $this->formatTimeDDHHMM($data['end_utc'] ?? $data['valid_until'] ?? null);
        $routeName = strtoupper($data['route_name'] ?? $data['name'] ?? 'ROUTE');
        $cancelText = $data['cancel_text'] ?? "{$routeName} HAS BEEN CANCELLED";
        
        return implode("\n", [
            "vATCSCC ADVZY {$advNum} {$facility} {$headerDate} REROUTE CANCELLATION",
            "VALID FOR {$startTime} THROUGH {$endTime}",
            strtoupper($cancelText),
            "",
            "{$startTime} - {$endTime}",
            $this->formatSignature(),
        ]);
    }
    
    public function postFCAAdvisory(array $data, string $channel = 'advzy_staging'): ?array {
        return $this->discord->createMessage($channel, ['content' => "```\n" . $this->formatFCAAdvisory($data) . "\n```"]);
    }
    
    private function formatFCAAdvisory(array $data): string {
        $advNum = $this->cleanAdvisoryNumber($data['advisory_number'] ?? '001');
        $facility = strtoupper($data['facility'] ?? 'DCC');
        $headerDate = $this->formatDateMMDDYYYY($data['issue_date'] ?? null);
        $action = strtoupper($data['action'] ?? 'RQD');
        $flIndicator = !empty($data['has_flight_list']) ? '/FL' : '';
        $fcaName = strtoupper($data['fca_name'] ?? $data['name'] ?? '');
        if (!empty($data['fca_id']) && strpos($fcaName, 'FCA') !== 0) {
            $fcaName = "FCA" . str_pad($data['fca_id'], 3, '0', STR_PAD_LEFT) . ":" . $fcaName;
        }
        $constrainedArea = strtoupper($data['constrained_area'] ?? $data['impacted_area'] ?? '');
        $reason = strtoupper($data['reason'] ?? 'VOLUME');
        $includeTraffic = strtoupper($data['include_traffic'] ?? '');
        $facilities = $this->formatFacilitiesList($data['facilities'] ?? $data['facilities_included'] ?? []);
        $flightStatus = strtoupper($data['flight_status'] ?? 'ALL_FLIGHTS');
        $startTime = $this->formatTimeDDHHMM($data['start_utc'] ?? $data['valid_from'] ?? null);
        $endTime = $this->formatTimeDDHHMM($data['end_utc'] ?? $data['valid_until'] ?? null);
        $probExt = strtoupper($data['prob_extension'] ?? 'NONE');
        $tmiId = 'RR' . $facility . $advNum;
        
        $lines = ["vATCSCC ADVZY {$advNum} {$facility} {$headerDate} FCA {$action}{$flIndicator}"];
        if ($fcaName) $lines[] = "NAME: {$fcaName}";
        if ($constrainedArea) {
            $areaLine = "CONSTRAINED AREA: {$constrainedArea}";
            $lines[] = strlen($areaLine) > self::MAX_LINE_LENGTH ? $this->wrapFieldWithHangingIndent('CONSTRAINED AREA:', $constrainedArea) : $areaLine;
        }
        $lines[] = "REASON: {$reason}";
        if ($includeTraffic) {
            $trafficLine = "INCLUDE TRAFFIC: {$includeTraffic}";
            $lines[] = strlen($trafficLine) > self::MAX_LINE_LENGTH ? $this->wrapFieldWithHangingIndent('INCLUDE TRAFFIC:', $includeTraffic) : $trafficLine;
        }
        if ($facilities) $lines[] = "FACILITIES INCLUDED: {$facilities}";
        $lines[] = "FLIGHT STATUS: {$flightStatus}";
        $lines[] = "VALID: FCA ENTRY TIME FROM {$startTime} TO {$endTime}";
        $lines[] = "PROBABILITY OF EXTENSION: {$probExt}";
        $lines[] = !empty($data['remarks']) ? $this->wrapFieldWithHangingIndent('REMARKS:', $data['remarks']) : "REMARKS:";
        $lines[] = !empty($data['associated_restrictions']) ? $this->wrapFieldWithHangingIndent('ASSOCIATED RESTRICTIONS:', $data['associated_restrictions']) : "ASSOCIATED RESTRICTIONS:";
        $lines[] = !empty($data['modifications']) ? $this->wrapFieldWithHangingIndent('MODIFICATIONS:', $data['modifications']) : "MODIFICATIONS:";
        $lines[] = "ROUTES:";
        $lines[] = "";
        $lines[] = $this->formatRouteTable($data['routes'] ?? []);
        $lines[] = "";
        $lines[] = "TMI ID: {$tmiId}";
        $lines[] = "{$startTime} - {$endTime}";
        $lines[] = $this->formatSignature();
        
        return implode("\n", $lines);
    }
    
    // =========================================
    // ADVISORY NOTIFICATIONS - Operations Plan
    // =========================================
    
    public function postOperationsPlan(array $data, string $channel = 'advzy_staging'): ?array {
        return $this->discord->createMessage($channel, ['content' => "```\n" . $this->formatOperationsPlan($data) . "\n```"]);
    }
    
    private function formatOperationsPlan(array $data): string {
        $advNum = $this->cleanAdvisoryNumber($data['advisory_number'] ?? '001');
        $facility = strtoupper($data['facility'] ?? 'DCC');
        $headerDate = $this->formatDateMMDDYYYY($data['issue_date'] ?? null);
        $eventTime = $data['event_time'] ?? $this->formatTimeDDHHMM(null) . ' - AND LATER';
        $summary = $data['summary'] ?? '';
        
        $lines = [
            "vATCSCC ADVZY {$advNum} {$facility} {$headerDate} OPERATIONS PLAN",
            "EVENT TIME: {$eventTime}",
            str_repeat('_', 68),
        ];
        
        if ($summary) $lines[] = $this->wrapText(strtoupper($summary));
        $lines[] = str_repeat('_', 68);
        $lines[] = "";
        
        $sections = [
            ['STAFFING TRIGGER(S):', $data['staffing_triggers'] ?? null],
            ['TERMINAL CONSTRAINTS:', $data['terminal_constraints'] ?? null],
            ['TERMINAL ACTIVE:', $data['terminal_active'] ?? null],
            ['TERMINAL PLANNED:', $data['terminal_planned'] ?? null],
            ['EN ROUTE CONSTRAINTS:', $data['enroute_constraints'] ?? null],
            ['EN ROUTE ACTIVE:', $data['enroute_active'] ?? null],
            ['EN ROUTE PLANNED:', $data['enroute_planned'] ?? null],
            ['CDRS/SWAP/CAPPING/TUNNELING/HOTLINE/DIVERSION RECOVERY:', $data['cdrs_swap'] ?? null],
        ];
        
        foreach ($sections as [$label, $items]) {
            $lines[] = $label;
            if (!empty($items)) {
                foreach ((array)$items as $item) $lines[] = strtoupper($item);
            } else {
                $lines[] = "NONE";
            }
            $lines[] = "";
        }
        
        if (!empty($data['runway_equipment'])) {
            $lines[] = "RUNWAY/EQUIPMENT/POSSIBLE SYSTEM IMPACT REPORTS(SIRs):";
            foreach ((array)$data['runway_equipment'] as $item) $lines[] = strtoupper($item);
            $lines[] = "";
        }
        
        $lines[] = "AIRSPACE FLOW PROGRAM(S) ACTIVE:";
        if (!empty($data['afp_active'])) foreach ((array)$data['afp_active'] as $item) $lines[] = strtoupper($item);
        else $lines[] = "NONE";
        $lines[] = "";
        
        $lines[] = "AIRSPACE FLOW PROGRAM(S) PLANNED:";
        if (!empty($data['afp_planned'])) foreach ((array)$data['afp_planned'] as $item) $lines[] = strtoupper($item);
        else $lines[] = "NONE";
        $lines[] = "";
        
        if (!empty($data['launches'])) {
            $lines[] = "PLANNED LAUNCH/REENTRY:";
            foreach ((array)$data['launches'] as $launch) $lines[] = strtoupper($launch);
            $lines[] = "";
        }
        if (!empty($data['flight_checks'])) {
            $lines[] = "FLIGHT CHECK(S):";
            foreach ((array)$data['flight_checks'] as $check) $lines[] = strtoupper($check);
            $lines[] = "";
        }
        if (!empty($data['vip_movements'])) {
            $lines[] = "VIP MOVEMENT(S):";
            foreach ((array)$data['vip_movements'] as $vip) $lines[] = strtoupper($vip);
            $lines[] = "";
        }
        
        if (!empty($data['next_webinar'])) $lines[] = "NEXT PLANNING WEBINAR: " . strtoupper($data['next_webinar']);
        $lines[] = $this->formatValidTimeRangeWithSpaces($data['start_utc'] ?? null, $data['end_utc'] ?? null);
        $lines[] = $this->formatSignature();
        
        return implode("\n", $lines);
    }
    
    // =========================================
    // ADVISORY NOTIFICATIONS - Hotline/Info
    // =========================================
    
    public function postHotlineAdvisory(array $data, string $channel = 'advzy_staging'): ?array {
        return $this->discord->createMessage($channel, ['content' => "```\n" . $this->formatHotlineAdvisory($data) . "\n```"]);
    }
    
    private function formatHotlineAdvisory(array $data): string {
        $advNum = $this->cleanAdvisoryNumber($data['advisory_number'] ?? '001');
        $facility = strtoupper($data['facility'] ?? 'DCC');
        $headerDate = $this->formatDateMMDDYYYY($data['issue_date'] ?? null);
        $hotlineName = strtoupper($data['hotline_name'] ?? $data['name'] ?? 'HOTLINE');
        $eventTime = $data['event_time'] ?? '';
        $constrainedFacilities = strtoupper($data['constrained_facilities'] ?? '');
        $isTerminated = !empty($data['terminated']) || !empty($data['deactivated']);
        $startTime = $this->formatTimeDDHHMM($data['start_utc'] ?? $data['valid_from'] ?? null);
        $endTime = $this->formatTimeDDHHMM($data['end_utc'] ?? $data['valid_until'] ?? null);
        
        $lines = ["vATCSCC ADVZY {$advNum} {$facility} {$headerDate} {$hotlineName} HOTLINE_FYI"];
        if ($eventTime) $lines[] = "EVENT TIME: " . strtoupper($eventTime);
        if ($constrainedFacilities) $lines[] = "CONSTRAINED FACILITIES: {$constrainedFacilities}";
        
        if ($isTerminated) {
            $lines[] = "THE {$hotlineName} HOTLINE IS NOW TERMINATED.";
        } else {
            $location = strtoupper($data['location'] ?? 'THE VATUSA TEAMSPEAK, HOTLINE CHANNEL');
            $password = $data['password'] ?? '';
            $contact = strtoupper($data['contact'] ?? '');
            
            $activationText = "THE {$hotlineName} HOTLINE IS BEING ACTIVATED";
            if (!empty($data['reason'])) $activationText .= " TO ADDRESS " . strtoupper($data['reason']);
            if ($constrainedFacilities) $activationText .= " IN {$constrainedFacilities}";
            $activationText .= ".";
            
            $lines[] = $this->wrapText($activationText);
            $lines[] = "THE LOCATION IS {$location}" . ($password ? ", PASSWORD {$password}" : ", NO PIN") . ".";
            if ($constrainedFacilities) $lines[] = "PARTICIPATION IS RECOMMENDED FOR {$constrainedFacilities}.";
            $lines[] = "AFFECTED MAJOR UNDERLYING FACILITIES ARE STRONGLY ENCOURAGED TO";
            $lines[] = "ATTEND. ALL OTHER PARTICIPANTS ARE WELCOME TO JOIN.";
            if ($contact) $lines[] = "PLEASE MESSAGE {$contact} IF YOU HAVE ISSUES OR QUESTIONS.";
        }
        
        $lines[] = "";
        $lines[] = "{$startTime} - {$endTime}";
        $lines[] = $this->formatSignature();
        
        return implode("\n", $lines);
    }
    
    public function postInformationalAdvisory(array $data, string $channel = 'advzy_staging'): ?array {
        return $this->discord->createMessage($channel, ['content' => "```\n" . $this->formatInformationalAdvisory($data) . "\n```"]);
    }
    
    private function formatInformationalAdvisory(array $data): string {
        $advNum = $this->cleanAdvisoryNumber($data['advisory_number'] ?? '001');
        $facility = strtoupper($data['facility'] ?? 'DCC');
        $headerDate = $this->formatDateMMDDYYYY($data['issue_date'] ?? null);
        $advType = strtoupper($data['advisory_type'] ?? 'INFORMATIONAL');
        $startTime = $this->formatTimeDDHHMM($data['start_utc'] ?? $data['valid_from'] ?? null);
        $endTime = $this->formatTimeDDHHMM($data['end_utc'] ?? $data['valid_until'] ?? null);
        $text = $data['text'] ?? $data['message'] ?? '';
        
        $lines = [
            "vATCSCC ADVZY {$advNum} {$facility} {$headerDate} {$advType}",
            "VALID FOR {$startTime} THROUGH {$endTime}",
            "",
        ];
        if ($text) $lines[] = $this->wrapText(strtoupper($text));
        $lines[] = "";
        $lines[] = "{$startTime} - {$endTime}";
        $lines[] = $this->formatSignature();
        
        return implode("\n", $lines);
    }
    
    // =========================================
    // FORMATTING HELPERS
    // =========================================
    
    private function formatRouteTable(array $routes): string {
        if (empty($routes)) {
            return "ORIG       DEST       ROUTE\n----       ----       -----\n(No routes specified)";
        }
        
        $maxOrigLen = 4;
        $maxDestLen = 4;
        foreach ($routes as $route) {
            $maxOrigLen = max($maxOrigLen, strlen(strtoupper($route['origin'] ?? '---')));
            $maxDestLen = max($maxDestLen, strlen(strtoupper($route['dest'] ?? $route['destination'] ?? '---')));
        }
        
        $origColWidth = $maxOrigLen + 3;
        $destColWidth = $maxDestLen + 3;
        
        $output = str_pad('ORIG', $origColWidth) . str_pad('DEST', $destColWidth) . "ROUTE\n";
        $output .= str_pad('----', $origColWidth) . str_pad('----', $destColWidth) . "-----\n";
        
        foreach ($routes as $route) {
            $orig = strtoupper($route['origin'] ?? '---');
            $dest = strtoupper($route['dest'] ?? $route['destination'] ?? '---');
            $routeStr = strtoupper($route['route'] ?? '');
            $lineStart = str_pad($orig, $origColWidth) . str_pad($dest, $destColWidth);
            $routeIndent = str_repeat(' ', strlen($lineStart));
            $maxRouteLen = self::MAX_LINE_LENGTH - strlen($lineStart);
            
            if (strlen($routeStr) <= $maxRouteLen) {
                $output .= "{$lineStart}{$routeStr}\n";
            } else {
                $routeWords = preg_split('/\s+/', $routeStr);
                $currentLine = '';
                $isFirstLine = true;
                foreach ($routeWords as $word) {
                    $lineMax = $isFirstLine ? $maxRouteLen : (self::MAX_LINE_LENGTH - strlen($routeIndent));
                    $tentative = $currentLine . ($currentLine ? ' ' : '') . $word;
                    if (strlen($tentative) <= $lineMax) {
                        $currentLine = $tentative;
                    } else {
                        $output .= ($isFirstLine ? $lineStart : $routeIndent) . "{$currentLine}\n";
                        $isFirstLine = false;
                        $currentLine = $word;
                    }
                }
                $output .= ($isFirstLine ? $lineStart : $routeIndent) . "{$currentLine}\n";
            }
        }
        
        return rtrim($output);
    }
    
    private function formatFlightInclusions(array $data): array {
        $lines = [];
        if (!empty($data['flt_incl'])) {
            $fltIncl = $data['flt_incl'];
            if (is_array($fltIncl)) {
                foreach ($fltIncl as $incl) $lines[] = "FLT INCL: " . strtoupper($incl);
            } else {
                $lines[] = "FLT INCL: " . strtoupper($fltIncl);
            }
        } elseif (!empty($data['scope_tier'])) {
            $lines[] = "FLT INCL: " . strtoupper($data['scope_tier']);
        }
        if (!empty($data['carrier_incl'])) $lines[] = "FLT INCL: " . strtoupper($data['carrier_incl']);
        return $lines;
    }
    
    private function formatDepFacilities($facilities): string {
        if (empty($facilities)) return '';
        return is_array($facilities) ? implode('/', array_map('strtoupper', $facilities)) : strtoupper($facilities);
    }

    /**
     * Format departure facilities with scope/tier name
     * Example: "(Manual) ZTL ZHU ZJX ZFW ZMA ZKC ZME ZAB"
     * Tier names like Manual, Tier1, Tier2, 6West are preserved in mixed case
     *
     * Accepts either:
     * - Separate 'dep_scope' parameter + 'dep_facilities' array/string
     * - Or 'dep_facilities' string with embedded tier: "(Tier1) ZBW ZDC ZOB"
     */
    private function formatDepFacilitiesWithScope(array $data): string {
        $facilities = $data['dep_facilities'] ?? [];
        $scopeTier = $data['dep_scope'] ?? $data['scope_tier'] ?? '';

        // If facilities is a string, check if tier is embedded at start
        if (is_string($facilities) && preg_match('/^\s*\(([^)]+)\)\s*(.*)$/', $facilities, $matches)) {
            // Extract tier from embedded format: "(Tier1) ZBW ZDC..."
            if (empty($scopeTier)) {
                $scopeTier = $matches[1];
            }
            $facilities = $matches[2];
        }

        // Format scope/tier name - preserve mixed case for named groups
        $scopeStr = '';
        if (!empty($scopeTier)) {
            // Ensure parentheses, preserve original case
            $scopeTier = trim($scopeTier, '() ');
            $scopeStr = "({$scopeTier}) ";
        }

        // Format facilities as space-separated uppercase
        if (is_array($facilities)) {
            $facStr = implode(' ', array_map('strtoupper', $facilities));
        } else {
            // Parse string and uppercase individual facilities
            $facArr = preg_split('/[\s,\/]+/', trim($facilities));
            $facStr = implode(' ', array_map('strtoupper', array_filter($facArr)));
        }

        return $scopeStr . $facStr;
    }
    
    private function formatFacilitiesList($facilities): string {
        if (empty($facilities)) return '';
        if (is_array($facilities)) return implode('/', array_map('strtoupper', $facilities));
        $facArr = preg_split('/[\s,]+/', trim($facilities));
        return implode('/', array_map('strtoupper', $facArr));
    }
    
    private function wrapText(string $text, int $maxLen = self::MAX_LINE_LENGTH): string {
        if (empty($text)) return '';
        $lines = [];
        foreach (explode("\n", $text) as $para) {
            $para = trim($para);
            if (strlen($para) <= $maxLen) {
                $lines[] = $para;
            } else {
                $words = explode(' ', $para);
                $currentLine = '';
                foreach ($words as $word) {
                    if (strlen($currentLine) + strlen($word) + 1 <= $maxLen) {
                        $currentLine .= ($currentLine ? ' ' : '') . $word;
                    } else {
                        if ($currentLine) $lines[] = $currentLine;
                        $currentLine = $word;
                    }
                }
                if ($currentLine) $lines[] = $currentLine;
            }
        }
        return implode("\n", $lines);
    }
    
    private function wrapFieldWithHangingIndent(string $label, string $value, int $maxLen = self::MAX_LINE_LENGTH): string {
        if (empty($value)) return $label;
        $indent = strlen($label) + 1;
        $firstLineMax = $maxLen - strlen($label) - 1;
        $subsequentLineMax = $maxLen - $indent;
        $words = preg_split('/\s+/', $value);
        $lines = [];
        $currentLine = '';
        $isFirstLine = true;
        foreach ($words as $word) {
            $lineMax = $isFirstLine ? $firstLineMax : $subsequentLineMax;
            $tentative = $currentLine . ($currentLine ? ' ' : '') . $word;
            if (strlen($tentative) <= $lineMax) {
                $currentLine = $tentative;
            } else {
                $lines[] = ($isFirstLine ? $label . ' ' : str_repeat(' ', $indent)) . $currentLine;
                $isFirstLine = false;
                $currentLine = $word;
            }
        }
        $lines[] = ($isFirstLine ? $label . ' ' : str_repeat(' ', $indent)) . $currentLine;
        return implode("\n", $lines);
    }
    
    private function cleanAdvisoryNumber($advNum): string {
        $advNum = preg_replace('/^[^0-9]+/', '', $advNum);
        return str_pad($advNum, 3, '0', STR_PAD_LEFT);
    }
    
    private function formatLogTime(): string { return gmdate('d/Hi'); }
    private function formatDateMMDDYYYY(?string $datetime): string { return $datetime && ($ts = strtotime($datetime)) ? gmdate('m/d/Y', $ts) : gmdate('m/d/Y'); }
    private function formatTimeHHMM(?string $datetime): string { return $datetime && ($ts = strtotime($datetime)) ? gmdate('Hi', $ts) : gmdate('Hi'); }
    private function formatTimeDDHHMM(?string $datetime): string { return $datetime && ($ts = strtotime($datetime)) ? gmdate('dHi', $ts) : gmdate('dHi'); }
    private function formatProgramTime(?string $datetime): string { return ($datetime && ($ts = strtotime($datetime)) ? gmdate('d/Hi', $ts) : gmdate('d/Hi')) . 'Z'; }
    private function formatValidTimeRange(?string $start, ?string $end): string { return $this->formatTimeDDHHMM($start) . '-' . $this->formatTimeDDHHMM($end); }
    private function formatValidTimeRangeWithSpaces(?string $start, ?string $end): string { return $this->formatTimeDDHHMM($start) . ' - ' . $this->formatTimeDDHHMM($end); }
    private function formatSignature(): string { return gmdate('y/m/d H:i'); }
    
    public function updateMessage(string $channelId, string $messageId, string $content): ?array {
        return $this->discord->editMessage($channelId, $messageId, ['content' => $content]);
    }
    
    public function deleteMessage(string $channelId, string $messageId): bool {
        return $this->discord->deleteMessage($channelId, $messageId);
    }
}
