<?php
/**
 * TMI Discord Integration Module
 * 
 * Handles Discord notifications for Traffic Management Initiatives.
 * 
 * NTML Format: Based on TMIs.pdf vATCSCC NTML Guide
 * Advisory Format: Based on Advisories_and_General_Messages_v1_3.pdf
 * 
 * @package PERTI
 * @subpackage TMI/Discord
 * @version 3.0.0
 */

require_once __DIR__ . '/DiscordAPI.php';

class TMIDiscord {
    
    private $discord;
    private $channels;
    
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
    // Format per TMIs.pdf NTML Guide
    // DD/HHMM APT arrivals/departures via FIX ##MIT QUALIFIERS TYPE:x REASON:x HHMM-HHMM REQ:PROV
    // =========================================
    
    /**
     * Post an NTML entry to Discord
     * Uses standard NTML text format (not embeds)
     * 
     * @param array $entry NTML entry data
     * @param string $channel Channel purpose (default: 'ntml_staging')
     * @return array|null Message object or null on error
     */
    public function postNtmlEntry(array $entry, string $channel = 'ntml_staging'): ?array {
        $message = $this->formatNtmlMessage($entry);
        
        return $this->discord->createMessage($channel, [
            'content' => "```\n{$message}\n```"
        ]);
    }
    
    /**
     * Format NTML entry per TMIs.pdf specification
     */
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
     * Format MIT/MINIT/STOP restriction entry
     * Format per TMIs.pdf:
     * DD/HHMM APT arrivals/departures via FIX ##MIT QUALIFIERS TYPE:x SPD:x ALT:x VOLUME:x WEATHER:x EXCL:x HHMM-HHMM REQ:PROV
     */
    private function formatRestrictionEntry(array $data): string {
        $logTime = $this->formatLogTime();
        
        // Determine flow type (arrivals/departures/via)
        $flowType = strtolower($data['flow_type'] ?? 'arrivals');
        $airport = strtoupper($data['airport'] ?? $data['ctl_element'] ?? '');
        $fix = strtoupper($data['fix'] ?? $data['condition_text'] ?? '');
        
        // Restriction value and type
        $restrictionType = strtoupper($data['entry_type'] ?? 'MIT');
        $restrictionValue = $data['restriction_value'] ?? $data['distance'] ?? $data['minutes'] ?? '';
        
        // Build restriction string
        if ($restrictionType === 'STOP') {
            $restriction = 'STOP';
        } else {
            $restriction = "{$restrictionValue}{$restrictionType}";
        }
        
        // Qualifiers (NO STACKS, PER FIX, PER AIRPORT, etc.)
        $qualifiers = $this->formatNtmlQualifiers($data['qualifiers'] ?? '');
        
        // Optional fields - order per spec: TYPE, SPD, ALT, VOLUME, WEATHER, EXCL
        $parts = [];
        
        // Aircraft type (TYPE:ALL/JET/PROP/TURBOPROP)
        if (!empty($data['aircraft_type'])) {
            $parts[] = "TYPE:" . strtoupper($data['aircraft_type']);
        }
        
        // Speed restriction (SPD:=/≤/≥###)
        if (!empty($data['speed'])) {
            $spdOp = $data['speed_operator'] ?? '';
            $parts[] = "SPD:{$spdOp}" . $data['speed'];
        }
        
        // Altitude restriction (ALT:AT/AOB/AOA###)
        if (!empty($data['altitude'])) {
            $altType = strtoupper($data['alt_type'] ?? 'AT');
            $parts[] = "ALT:{$altType}" . strtoupper($data['altitude']);
        }
        
        // Volume condition (VOLUME:text)
        if (!empty($data['volume']) || !empty($data['reason_code']) && strtoupper($data['reason_code']) === 'VOLUME') {
            $volText = strtoupper($data['volume'] ?? 'VOLUME');
            $parts[] = "VOLUME:{$volText}";
        }
        
        // Weather condition (WEATHER:reason)
        if (!empty($data['weather']) || (!empty($data['reason_code']) && strtoupper($data['reason_code']) === 'WEATHER')) {
            $wxText = strtoupper($data['weather'] ?? $data['reason_detail'] ?? 'WEATHER');
            $parts[] = "WEATHER:{$wxText}";
        }
        
        // Exclusions (EXCL:facilities)
        if (!empty($data['exclusions'])) {
            $parts[] = "EXCL:" . strtoupper($data['exclusions']);
        }
        
        // Valid time range (HHMM-HHMM, no day prefix)
        $validFrom = $this->formatTimeHHMM($data['valid_from'] ?? null);
        $validUntil = $this->formatTimeHHMM($data['valid_until'] ?? null);
        $parts[] = "{$validFrom}-{$validUntil}";
        
        // Requesting:Providing facilities
        $reqFac = strtoupper($data['requesting_facility'] ?? $data['req_facility_id'] ?? '');
        $provFac = strtoupper($data['providing_facility'] ?? $data['prov_facility_id'] ?? '');
        if ($reqFac && $provFac) {
            $parts[] = "{$reqFac}:{$provFac}";
        }
        
        // Build the line
        $optionalStr = implode(' ', $parts);
        
        if ($fix) {
            $line = "{$logTime} {$airport} {$flowType} via {$fix} {$restriction}{$qualifiers} {$optionalStr}";
        } else {
            $line = "{$logTime} {$airport} {$flowType} {$restriction}{$qualifiers} {$optionalStr}";
        }
        
        return trim($line);
    }
    
    /**
     * Format Delay entry per TMIs.pdf
     * Format: DD/HHMM TYPE from/for/to LOCATION, +/-##/HHMM/## ACFT [VOLUME:text] [FIX/NAVAID:fix]
     * Types: D/D (Departure Delay), E/D (Enroute Delay), A/D (Arrival Delay)
     * Value: +## (increasing), -## (decreasing), +Holding (entering), -Holding (exiting)
     */
    private function formatDelayEntry(array $data): string {
        $logTime = $this->formatLogTime();
        
        // Delay type
        $delayType = strtoupper($data['delay_type'] ?? 'D/D');
        
        // Preposition based on type per spec
        $prep = 'from'; // D/D = from [departure airport]
        if ($delayType === 'E/D') $prep = 'for';  // E/D = for [destination]
        if ($delayType === 'A/D') $prep = 'to';   // A/D = to [arrival airport]
        
        // Location (facility or airport)
        $location = strtoupper($data['location'] ?? $data['delay_facility'] ?? $data['ctl_element'] ?? '');
        
        // Delay value (+XX increasing, -XX decreasing, +/-Holding)
        $delayValue = $data['delay_value'] ?? $data['longest_delay'] ?? '';
        $trend = strtolower($data['delay_trend'] ?? 'steady');
        if ($trend === 'increasing' || $trend === 'inc') {
            $delaySign = '+';
        } elseif ($trend === 'decreasing' || $trend === 'dec') {
            $delaySign = '-';
        } else {
            $delaySign = '';
        }
        
        // Holding indicator per spec: +Holding or -Holding
        if (!empty($data['holding']) && $data['holding'] !== 'no') {
            $delayValue = ($delaySign ?: '+') . 'Holding';
        } else {
            $delayValue = "{$delaySign}{$delayValue}";
        }
        
        // Time of observation and aircraft count
        $time = $this->formatTimeHHMM($data['report_time'] ?? null);
        $acftCount = $data['flights_delayed'] ?? $data['aircraft_count'] ?? '';
        
        // Optional fields
        $optParts = [];
        
        // VOLUME condition (optional)
        if (!empty($data['volume']) || !empty($data['reason_code'])) {
            $volText = strtoupper($data['volume'] ?? $data['reason_code'] ?? 'VOLUME');
            $optParts[] = "VOLUME:{$volText}";
        }
        
        // FIX/NAVAID (optional)
        if (!empty($data['fix'])) {
            $optParts[] = "FIX/NAVAID:" . strtoupper($data['fix']);
        }
        
        $optStr = !empty($optParts) ? ' ' . implode(' ', $optParts) : '';
        
        $line = "{$logTime} {$delayType} {$prep} {$location}, {$delayValue}/{$time}/{$acftCount} ACFT{$optStr}";
        
        return trim($line);
    }
    
    /**
     * Format Airport Config entry per TMIs.pdf
     * Format: DD/HHMM APT WX ARR:rwys DEP:rwys AAR(type):## [AAR Adjustment:note] ADR:##
     * Runway format can include approach type: ILS_31R_VAP_31L, LOC_31, RNAV_X_29
     */
    private function formatConfigEntry(array $data): string {
        $logTime = $this->formatLogTime();
        
        $airport = strtoupper($data['airport'] ?? $data['ctl_element'] ?? '');
        $weather = strtoupper($data['weather'] ?? 'VMC'); // VMC or IMC
        $arrRwys = strtoupper($data['arr_runways'] ?? '');
        $depRwys = strtoupper($data['dep_runways'] ?? '');
        $aar = $data['aar'] ?? '';
        $adr = $data['adr'] ?? '';
        
        // AAR type: Strat (strategic) or Dyn (dynamic)
        $aarType = $data['aar_type'] ?? 'Strat';
        
        // AAR Adjustment (optional, e.g., XW-TLWD for crosswind/tailwind)
        $aarAdjust = '';
        if (!empty($data['aar_adjustment'])) {
            $aarAdjust = " AAR Adjustment:" . strtoupper($data['aar_adjustment']);
        }
        
        $line = "{$logTime} {$airport} {$weather} ARR:{$arrRwys} DEP:{$depRwys} AAR({$aarType}):{$aar}{$aarAdjust} ADR:{$adr}";
        
        return trim($line);
    }
    
    /**
     * Format generic NTML entry
     */
    private function formatGenericEntry(array $data): string {
        $logTime = $this->formatLogTime();
        $type = strtoupper($data['entry_type'] ?? 'TXT');
        $text = $data['text'] ?? $data['condition_text'] ?? '';
        
        return "{$logTime} {$type} {$text}";
    }
    
    /**
     * Format NTML qualifiers
     */
    private function formatNtmlQualifiers($qualifiers): string {
        if (empty($qualifiers)) {
            return '';
        }
        
        if (is_string($qualifiers)) {
            $quals = explode(',', $qualifiers);
        } else {
            $quals = (array)$qualifiers;
        }
        
        $formatted = array_map(function($q) {
            $q = strtoupper(trim($q));
            // Convert underscore format to space format
            return str_replace('_', ' ', $q);
        }, $quals);
        
        return ' ' . implode(' ', $formatted);
    }
    
    /**
     * Post NTML cancellation notice
     */
    public function postNtmlCancellation(array $entry, string $channel = 'ntml_staging'): ?array {
        $logTime = $this->formatLogTime();
        $type = strtoupper($entry['entry_type'] ?? 'MIT');
        $airport = strtoupper($entry['airport'] ?? $entry['ctl_element'] ?? '');
        $cancelReason = $entry['cancel_reason'] ?? '';
        
        $message = "{$logTime} {$airport} {$type} CANCELLED";
        if ($cancelReason) {
            $message .= " - {$cancelReason}";
        }
        
        return $this->discord->createMessage($channel, [
            'content' => "```\n{$message}\n```"
        ]);
    }
    
    // =========================================
    // ADVISORY NOTIFICATIONS
    // Format per Advisories_and_General_Messages_v1_3.pdf
    // vATCSCC ADVZY ### APT/CTR mm/dd/yyyy TYPE
    // =========================================
    
    /**
     * Post a Ground Stop advisory
     */
    public function postGroundStopAdvisory(array $data, string $channel = 'advzy_staging'): ?array {
        $message = $this->formatGroundStopAdvisory($data);
        
        return $this->discord->createMessage($channel, [
            'content' => "```\n{$message}\n```"
        ]);
    }
    
    /**
     * Format Ground Stop advisory per TFMS spec
     */
    private function formatGroundStopAdvisory(array $data): string {
        $advNum = str_pad($data['advisory_number'] ?? '001', 3, '0', STR_PAD_LEFT);
        $airport = strtoupper($data['ctl_element'] ?? $data['airport'] ?? 'XXX');
        $artcc = strtoupper($data['artcc'] ?? 'ZXX');
        $headerDate = $this->formatDateMMDDYYYY($data['issue_date'] ?? null);
        $adlTime = $this->formatTimeHHMM($data['adl_time'] ?? null) . 'Z';
        
        $gsStart = $this->formatProgramTime($data['start_utc'] ?? $data['gs_start'] ?? null);
        $gsEnd = $this->formatProgramTime($data['end_utc'] ?? $data['gs_end'] ?? null);
        
        // Cumulative period (optional, if GDP underlies)
        $cumPeriod = '';
        if (!empty($data['cumulative_start'])) {
            $cumStart = $this->formatProgramTime($data['cumulative_start']);
            $cumEnd = $this->formatProgramTime($data['cumulative_end'] ?? $data['end_utc']);
            $cumPeriod = "CUMULATIVE PROGRAM PERIOD: {$cumStart} - {$cumEnd}\n";
        }
        
        // Flight inclusions
        $fltIncl = $this->formatFlightInclusions($data);
        
        // Departure facilities
        $depFac = $this->formatDepFacilities($data['dep_facilities'] ?? null);
        
        // Delays
        $prevDelays = ($data['prev_total_delay'] ?? '0') . ' / ' . 
                      ($data['prev_max_delay'] ?? '0') . ' / ' . 
                      ($data['prev_avg_delay'] ?? '0');
        $newDelays = ($data['new_total_delay'] ?? '0') . ' / ' . 
                     ($data['new_max_delay'] ?? '0') . ' / ' . 
                     ($data['new_avg_delay'] ?? '0');
        
        $probExt = strtoupper($data['prob_extension'] ?? 'MEDIUM');
        $condition = strtoupper($data['impacting_condition'] ?? $data['reason_code'] ?? 'WEATHER');
        $conditionText = $data['condition_text'] ?? '';
        $comments = $data['comments'] ?? '';
        
        $validRange = $this->formatValidTimeRange($data['start_utc'] ?? null, $data['end_utc'] ?? null);
        $signature = $this->formatSignature();
        
        $lines = [
            "vATCSCC ADVZY {$advNum} {$airport}/{$artcc} {$headerDate} CDM GROUND STOP",
            "CTL ELEMENT: {$airport}",
            "ELEMENT TYPE: APT",
            "ADL TIME: {$adlTime}",
            "GROUND STOP PERIOD: {$gsStart} - {$gsEnd}",
        ];
        
        if ($cumPeriod) {
            $lines[] = trim($cumPeriod);
        }
        
        $lines = array_merge($lines, $fltIncl);
        
        if ($depFac) {
            $lines[] = "DEP FACILITIES INCLUDED: {$depFac}";
        }
        
        $lines[] = "PREVIOUS TOTAL, MAXIMUM, AVERAGE DELAYS: {$prevDelays}";
        $lines[] = "NEW TOTAL, MAXIMUM, AVERAGE DELAYS: {$newDelays}";
        $lines[] = "PROBABILITY OF EXTENSION: {$probExt}";
        $lines[] = "IMPACTING CONDITION: {$condition}" . ($conditionText ? " {$conditionText}" : '');
        if ($comments) {
            $lines[] = "COMMENTS: " . $this->wrapText($comments);
        }
        $lines[] = $validRange;
        $lines[] = $signature;
        
        return implode("\n", $lines);
    }
    
    /**
     * Post a Ground Stop cancellation advisory
     */
    public function postGroundStopCancellation(array $data, string $channel = 'advzy_staging'): ?array {
        $message = $this->formatGroundStopCancellation($data);
        
        return $this->discord->createMessage($channel, [
            'content' => "```\n{$message}\n```"
        ]);
    }
    
    /**
     * Format Ground Stop cancellation per TFMS spec
     */
    private function formatGroundStopCancellation(array $data): string {
        $advNum = str_pad($data['advisory_number'] ?? '001', 3, '0', STR_PAD_LEFT);
        $airport = strtoupper($data['ctl_element'] ?? $data['airport'] ?? 'XXX');
        $artcc = strtoupper($data['artcc'] ?? 'ZXX');
        $headerDate = $this->formatDateMMDDYYYY($data['issue_date'] ?? null);
        $adlTime = $this->formatTimeHHMM($data['adl_time'] ?? null) . 'Z';
        
        $cnxStart = $this->formatProgramTime($data['start_utc'] ?? null);
        $cnxEnd = $this->formatProgramTime($data['end_utc'] ?? null);
        
        $comments = $data['comments'] ?? $data['cancel_reason'] ?? '';
        $validRange = $this->formatValidTimeRange($data['start_utc'] ?? null, $data['end_utc'] ?? null);
        $signature = $this->formatSignature();
        
        $lines = [
            "vATCSCC ADVZY {$advNum} {$airport}/{$artcc} {$headerDate} CDM GS CNX",
            "CTL ELEMENT: {$airport}",
            "ELEMENT TYPE: APT",
            "ADL TIME: {$adlTime}",
            "GS CNX PERIOD: {$cnxStart} - {$cnxEnd}",
        ];
        
        if ($comments) {
            $lines[] = "COMMENTS: " . $this->wrapText($comments);
        }
        $lines[] = $validRange;
        $lines[] = $signature;
        
        return implode("\n", $lines);
    }
    
    /**
     * Post a Ground Delay Program advisory
     */
    public function postGDPAdvisory(array $data, string $channel = 'advzy_staging'): ?array {
        $message = $this->formatGDPAdvisory($data);
        
        return $this->discord->createMessage($channel, [
            'content' => "```\n{$message}\n```"
        ]);
    }
    
    /**
     * Format GDP advisory per TFMS spec
     */
    private function formatGDPAdvisory(array $data): string {
        $advNum = str_pad($data['advisory_number'] ?? '001', 3, '0', STR_PAD_LEFT);
        $airport = strtoupper($data['ctl_element'] ?? $data['airport'] ?? 'XXX');
        $artcc = strtoupper($data['artcc'] ?? 'ZXX');
        $headerDate = $this->formatDateMMDDYYYY($data['issue_date'] ?? null);
        $adlTime = $this->formatTimeHHMM($data['adl_time'] ?? null) . 'Z';
        
        $delayMode = strtoupper($data['delay_mode'] ?? 'DAS');
        
        $arrStart = $this->formatProgramTime($data['arr_start_utc'] ?? $data['start_utc'] ?? null);
        $arrEnd = $this->formatProgramTime($data['arr_end_utc'] ?? $data['end_utc'] ?? null);
        $cumStart = $this->formatProgramTime($data['cumulative_start'] ?? $data['start_utc'] ?? null);
        $cumEnd = $this->formatProgramTime($data['cumulative_end'] ?? $data['end_utc'] ?? null);
        
        // Program rate - can be hourly rates
        $rate = $data['program_rate'] ?? '30';
        if (is_array($rate)) {
            $rate = implode('/', $rate);
        }
        
        // Pop-up factor (optional, for GAAP)
        $popupFactor = '';
        if (!empty($data['popup_factor'])) {
            $popupFactor = "\nPOP-UP FACTOR: " . strtoupper($data['popup_factor']);
        }
        
        // Flight inclusions
        $fltIncl = $this->formatFlightInclusions($data);
        
        // Departure scope
        $depScope = $data['dep_scope'] ?? $data['departure_scope'] ?? '';
        if (is_array($depScope)) {
            $depScope = implode(' ', $depScope);
        }
        
        // Additional/Exempt facilities
        $addlDep = '';
        if (!empty($data['additional_dep_facilities'])) {
            $addlDep = "\nADDITIONAL DEP FACILITIES INCLUDED: " . strtoupper($data['additional_dep_facilities']);
        }
        $exemptDep = '';
        if (!empty($data['exempt_dep_facilities'])) {
            $exemptDep = "\nEXEMPT DEP FACILITIES: " . strtoupper($data['exempt_dep_facilities']);
        }
        
        // Delay parameters
        $delayLimit = $data['delay_limit'] ?? '';
        $maxDelay = $data['max_delay'] ?? '';
        $avgDelay = $data['avg_delay'] ?? '';
        
        $condition = strtoupper($data['impacting_condition'] ?? $data['reason_code'] ?? 'WEATHER');
        $conditionText = $data['condition_text'] ?? $data['cause_text'] ?? '';
        $comments = $data['comments'] ?? '';
        
        $validRange = $this->formatValidTimeRange($data['start_utc'] ?? null, $data['end_utc'] ?? null);
        $signature = $this->formatSignature();
        
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
        
        if ($popupFactor) {
            $lines[] = trim($popupFactor);
        }
        
        $lines = array_merge($lines, $fltIncl);
        
        if ($depScope) {
            $lines[] = "DEPARTURE SCOPE: {$depScope}";
        }
        
        if ($addlDep) {
            $lines[] = trim($addlDep);
        }
        if ($exemptDep) {
            $lines[] = trim($exemptDep);
        }
        
        if ($delayLimit) {
            $lines[] = "DELAY LIMIT: {$delayLimit}";
        }
        if ($maxDelay) {
            $lines[] = "MAXIMUM DELAY: {$maxDelay}";
        }
        if ($avgDelay) {
            $lines[] = "AVERAGE DELAY: {$avgDelay}";
        }
        
        $lines[] = "IMPACTING CONDITION: {$condition}" . ($conditionText ? " / {$conditionText}" : '');
        if ($comments) {
            $lines[] = "COMMENTS: " . $this->wrapText($comments);
        }
        $lines[] = $validRange;
        $lines[] = $signature;
        
        return implode("\n", $lines);
    }
    
    /**
     * Post a GDP cancellation advisory
     */
    public function postGDPCancellation(array $data, string $channel = 'advzy_staging'): ?array {
        $message = $this->formatGDPCancellation($data);
        
        return $this->discord->createMessage($channel, [
            'content' => "```\n{$message}\n```"
        ]);
    }
    
    /**
     * Format GDP cancellation per TFMS spec
     */
    private function formatGDPCancellation(array $data): string {
        $advNum = str_pad($data['advisory_number'] ?? '001', 3, '0', STR_PAD_LEFT);
        $airport = strtoupper($data['ctl_element'] ?? $data['airport'] ?? 'XXX');
        $artcc = strtoupper($data['artcc'] ?? 'ZXX');
        $headerDate = $this->formatDateMMDDYYYY($data['issue_date'] ?? null);
        $adlTime = $this->formatTimeHHMM($data['adl_time'] ?? null) . 'Z';
        
        $cnxStart = $this->formatProgramTime($data['start_utc'] ?? null);
        $cnxEnd = $this->formatProgramTime($data['end_utc'] ?? null);
        
        $comments = $data['comments'] ?? $data['cancel_reason'] ?? '';
        $validRange = $this->formatValidTimeRange($data['start_utc'] ?? null, $data['end_utc'] ?? null);
        $signature = $this->formatSignature();
        
        $lines = [
            "vATCSCC ADVZY {$advNum} {$airport}/{$artcc} {$headerDate} CDM GROUND DELAY PROGRAM CNX",
            "CTL ELEMENT: {$airport}",
            "ELEMENT TYPE: APT",
            "ADL TIME: {$adlTime}",
            "GDP CNX PERIOD: {$cnxStart} - {$cnxEnd}",
            "DISREGARD EDCTS FOR DEST {$airport}",
        ];
        
        if ($comments) {
            $lines[] = "COMMENTS: " . $this->wrapText($comments);
        }
        $lines[] = $validRange;
        $lines[] = $signature;
        
        return implode("\n", $lines);
    }
    
    /**
     * Post a Reroute advisory
     */
    public function postRerouteAdvisory(array $data, string $channel = 'advzy_staging'): ?array {
        $message = $this->formatRerouteAdvisory($data);
        
        return $this->discord->createMessage($channel, [
            'content' => "```\n{$message}\n```"
        ]);
    }
    
    /**
     * Format Reroute advisory per TFMS spec
     */
    private function formatRerouteAdvisory(array $data): string {
        $advNum = str_pad($data['advisory_number'] ?? '001', 3, '0', STR_PAD_LEFT);
        $facility = strtoupper($data['facility'] ?? 'DCC');
        $headerDate = $this->formatDateMMDDYYYY($data['issue_date'] ?? null);
        $action = strtoupper($data['action'] ?? 'RQD');
        $routeType = strtoupper($data['route_type'] ?? 'ROUTE');
        
        // Flight list indicator
        $flIndicator = !empty($data['has_flight_list']) ? '/FL' : '';
        
        $routeName = strtoupper($data['route_name'] ?? $data['name'] ?? '');
        $impactedArea = strtoupper($data['impacted_area'] ?? '');
        $reason = strtoupper($data['reason'] ?? 'WEATHER');
        $reasonDetail = $data['reason_detail'] ?? '';
        $includeTraffic = strtoupper($data['include_traffic'] ?? '');
        
        // Valid time format depends on type
        $validType = strtoupper($data['valid_type'] ?? 'ETD');
        $startTime = $this->formatTimeDDHHMM($data['start_utc'] ?? $data['valid_from'] ?? null);
        $endTime = $this->formatTimeDDHHMM($data['end_utc'] ?? $data['valid_until'] ?? null);
        
        if ($validType === 'FCA') {
            $validLine = "VALID: FCA ENTRY TIME FROM {$startTime} TO {$endTime}";
        } else {
            $validLine = "VALID: ETD {$startTime} TO {$endTime}";
        }
        
        // Facilities
        $facilities = $data['facilities'] ?? 'ALL_FLIGHTS';
        if (is_array($facilities)) {
            $facilities = implode(' ', $facilities);
        }
        
        $probExt = strtoupper($data['prob_extension'] ?? 'NONE');
        $remarks = $data['remarks'] ?? '';
        $restrictions = $data['associated_restrictions'] ?? '';
        $modifications = $data['modifications'] ?? '';
        
        // Route table
        $routeTable = $this->formatRouteTable($data['routes'] ?? []);
        
        $tmiId = 'RR' . $facility . $advNum;
        $validRange = "{$startTime}-{$endTime}";
        $signature = $this->formatSignature();
        
        $lines = [
            "vATCSCC ADVZY {$advNum} {$facility} {$headerDate} {$routeType} {$action}{$flIndicator}",
            "NAME: {$routeName}",
            "IMPACTED AREA: {$impactedArea}",
            "REASON: {$reason}" . ($reasonDetail ? " / {$reasonDetail}" : ''),
            "INCLUDE TRAFFIC: {$includeTraffic}",
            $validLine,
            "FACILITIES INCLUDED: {$facilities}",
            "PROBABILITY OF EXTENSION: {$probExt}",
        ];
        
        // Optional fields - only include if populated
        if ($remarks) {
            $lines[] = "REMARKS: " . $this->wrapText($remarks);
        } else {
            $lines[] = "REMARKS:";
        }
        
        if ($restrictions) {
            $lines[] = "ASSOCIATED RESTRICTIONS: " . $this->wrapText($restrictions);
        } else {
            $lines[] = "ASSOCIATED RESTRICTIONS:";
        }
        
        if ($modifications) {
            $lines[] = "MODIFICATIONS: " . $this->wrapText($modifications);
        } else {
            $lines[] = "MODIFICATIONS:";
        }
        
        $lines[] = "ROUTE:";
        $lines[] = $routeTable;
        $lines[] = "";
        $lines[] = "TMI ID: {$tmiId}";
        $lines[] = $validRange;
        $lines[] = $signature;
        
        return implode("\n", $lines);
    }
    
    /**
     * Format route table for reroute advisory per TFMS spec
     * Uses >< to bracket mandatory (protected) segments
     * Column spacing: 5 spaces between ORIG, DEST, and ROUTE per spec
     */
    private function formatRouteTable(array $routes): string {
        if (empty($routes)) {
            return "ORIG     DEST     ROUTE\n----     ----     -----\n(No routes specified)";
        }
        
        // Header per spec: 5 spaces between columns
        $output = "ORIG     DEST     ROUTE\n";
        
        foreach ($routes as $route) {
            // Origin format: can be facility prefix like "---ZBW" for "all ZBW departures"
            $orig = strtoupper($route['origin'] ?? '---');
            // Pad to 8 chars (3 for ID + 5 spacing)
            $orig = str_pad($orig, 8);
            
            // Destination format: can be facility prefix like "---MCO" for "all MCO arrivals"
            $dest = strtoupper($route['dest'] ?? $route['destination'] ?? '---');
            // Pad to 8 chars (3 for ID + 5 spacing)
            $dest = str_pad($dest, 8);
            
            // Route string - may contain >protected segment<
            $routeStr = strtoupper($route['route'] ?? '');
            
            $output .= "{$orig}{$dest}{$routeStr}\n";
        }
        
        return rtrim($output);
    }
    
    /**
     * Format flight inclusions for GDP/GS advisories
     */
    private function formatFlightInclusions(array $data): array {
        $lines = [];
        
        // Main flight inclusion
        if (!empty($data['flt_incl'])) {
            $fltIncl = $data['flt_incl'];
            if (is_array($fltIncl)) {
                foreach ($fltIncl as $incl) {
                    $lines[] = "FLT INCL: " . strtoupper($incl);
                }
            } else {
                $lines[] = "FLT INCL: " . strtoupper($fltIncl);
            }
        } elseif (!empty($data['scope_tier'])) {
            $lines[] = "FLT INCL: " . strtoupper($data['scope_tier']);
        }
        
        // Carrier-specific inclusions
        if (!empty($data['carrier_incl'])) {
            $lines[] = "FLT INCL: " . strtoupper($data['carrier_incl']);
        }
        
        return $lines;
    }
    
    /**
     * Format departure facilities
     */
    private function formatDepFacilities($facilities): string {
        if (empty($facilities)) {
            return '';
        }
        
        if (is_array($facilities)) {
            return '(Manual) ' . implode(' ', array_map('strtoupper', $facilities));
        }
        
        return strtoupper($facilities);
    }
    
    // =========================================
    // FORMATTING HELPERS
    // =========================================
    
    /** @var int Maximum line length per IATA Type B message format */
    private const MAX_LINE_LENGTH = 68;
    
    /**
     * Wrap text to 68 characters per IATA Type B message format
     * Used for advisory free-form text fields
     */
    private function wrapText(string $text, int $maxLen = self::MAX_LINE_LENGTH): string {
        if (empty($text)) return '';
        
        $lines = [];
        $paragraphs = explode("\n", $text);
        
        foreach ($paragraphs as $para) {
            $para = trim($para);
            if (strlen($para) <= $maxLen) {
                $lines[] = $para;
            } else {
                // Word wrap
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
    
    /**
     * Format log time as DD/HHMM
     */
    private function formatLogTime(): string {
        return gmdate('d/Hi');
    }
    
    /**
     * Format date as mm/dd/yyyy
     */
    private function formatDateMMDDYYYY(?string $datetime): string {
        if (!$datetime) {
            return gmdate('m/d/Y');
        }
        $ts = strtotime($datetime);
        return $ts ? gmdate('m/d/Y', $ts) : gmdate('m/d/Y');
    }
    
    /**
     * Format time as HHMM
     */
    private function formatTimeHHMM(?string $datetime): string {
        if (!$datetime) {
            return gmdate('Hi');
        }
        $ts = strtotime($datetime);
        return $ts ? gmdate('Hi', $ts) : gmdate('Hi');
    }
    
    /**
     * Format time as DDHHMM
     */
    private function formatTimeDDHHMM(?string $datetime): string {
        if (!$datetime) {
            return gmdate('dHi');
        }
        $ts = strtotime($datetime);
        return $ts ? gmdate('dHi', $ts) : gmdate('dHi');
    }
    
    /**
     * Format program time as DD/HHMMZ
     */
    private function formatProgramTime(?string $datetime): string {
        if (!$datetime) {
            return gmdate('d/Hi') . 'Z';
        }
        $ts = strtotime($datetime);
        return $ts ? gmdate('d/Hi', $ts) . 'Z' : gmdate('d/Hi') . 'Z';
    }
    
    /**
     * Format valid time range as DDHHMM-DDHHMM
     */
    private function formatValidTimeRange(?string $start, ?string $end): string {
        $startStr = $this->formatTimeDDHHMM($start);
        $endStr = $this->formatTimeDDHHMM($end);
        return "{$startStr}-{$endStr}";
    }
    
    /**
     * Format signature timestamp as yy/mm/dd hh:mm
     */
    private function formatSignature(): string {
        return gmdate('y/m/d H:i');
    }
    
    /**
     * Update an existing Discord message
     */
    public function updateMessage(string $channelId, string $messageId, string $content): ?array {
        return $this->discord->editMessage($channelId, $messageId, [
            'content' => $content
        ]);
    }
    
    /**
     * Delete a Discord message
     */
    public function deleteMessage(string $channelId, string $messageId): bool {
        return $this->discord->deleteMessage($channelId, $messageId);
    }
}
