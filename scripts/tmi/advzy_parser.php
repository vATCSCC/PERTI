<?php
/**
 * ADVZY Format Parser for Historical TMI Import.
 * Parses Discord-exported vATCSCC advisory messages.
 *
 * Advisory format:
 *   [Discord Header]
 *   vATCSCC ADVZY ### [FACILITY] [MM/DD/YYYY] [TYPE]
 *   [key-value fields or free text body]
 *   [footer: DDHHMM-DDHHMM]
 *   [signature: YY/MM/DD HH:MM]
 *
 * @package PERTI
 * @subpackage Scripts/TMI
 */

/**
 * Parse an entire ADVZY log file into structured entries.
 * @param string $content Raw file content
 * @return array [{_type, _line, _raw, ctl_element, start_utc, end_utc, ...}, ...]
 */
function parseAdvzyFile(string $content): array {
    $lines = explode("\n", str_replace("\r\n", "\n", $content));
    $entries = [];
    $blockLines = [];
    $blockStart = 0;
    $header = null;

    for ($i = 0, $n = count($lines); $i < $n; $i++) {
        $raw = rtrim($lines[$i]); // preserve leading whitespace for KV continuation
        $trimmed = trim($raw);

        // Skip noise lines (garbled Unicode markers)
        if ($trimmed === '?' || $trimmed === '' || $trimmed === "\xC2\xA0") continue;

        // Discord header: "Author | Facility — MM/DD/YYYY HH:MM"
        if (preg_match('/^(.+?)\s*\|\s*(.+?)\s+(\d{2}\/\d{2}\/\d{4})\s+\d{2}:\d{2}/', $trimmed, $m)) {
            $header = ['author' => trim($m[1]), 'facility' => trim($m[2]), 'date' => $m[3]];
            continue;
        }
        // Split header: date-only line
        if (preg_match('/^\s*[\x{2014}\xE2\x80\x94\xC3\xA2\xC3\x82\x{FFFD}]+\s*(\d{2}\/\d{2}\/\d{4})\s+\d{2}:\d{2}/u', $trimmed, $m)) {
            if ($header) $header['date'] = $m[1];
            else $header = ['author' => '?', 'facility' => '?', 'date' => $m[1]];
            continue;
        }
        // Author-only line (split header)
        if (preg_match('/^([A-Za-z][\w ]+?)\s*\|\s*([A-Z].*)$/i', $trimmed, $m) && !preg_match('/\d{2}\/\d{4}/', $trimmed)) {
            $header = $header ?? ['date' => null];
            $header['author'] = trim($m[1]);
            $header['facility'] = trim($m[2]);
            continue;
        }

        // New ADVZY header starts a new block
        if (preg_match('/^vATCSCC\s+ADVZY\s+/i', $trimmed)) {
            // Flush previous block
            if (!empty($blockLines)) {
                $entry = parseAdvzyBlock($blockLines, $blockStart);
                if ($entry) $entries[] = $entry;
            }
            $blockLines = [$trimmed];
            $blockStart = $i + 1;
            // Attach Discord header context
            if ($header) {
                $blockLines['_header'] = $header;
            }
            continue;
        }

        // Accumulate lines into current block (preserve original indentation)
        if (!empty($blockLines)) {
            $blockLines[] = $raw;
        }
    }

    // Flush last block
    if (!empty($blockLines)) {
        $entry = parseAdvzyBlock($blockLines, $blockStart);
        if ($entry) $entries[] = $entry;
    }

    return $entries;
}

/**
 * Parse a single ADVZY block into structured data.
 * @param array $lines Block lines (index 0 = ADVZY header, '_header' = Discord header)
 * @param int $lineNum Line number in source file
 * @return array|null Parsed data or null
 */
function parseAdvzyBlock(array $lines, int $lineNum): ?array {
    $header = $lines['_header'] ?? null;
    unset($lines['_header']);
    $lines = array_values($lines);

    if (empty($lines)) return null;

    $advzyHeader = $lines[0];
    $raw = implode("\n", $lines);

    // Parse ADVZY header: "vATCSCC ADVZY ### [FACILITY] [MM/DD/YYYY] [TYPE...]"
    $advzyInfo = parseAdvzyHeader($advzyHeader);
    if (!$advzyInfo) return null;

    $type = $advzyInfo['type'];
    $headerDate = $advzyInfo['date'] ?? ($header['date'] ?? null);

    // Initialize data structure (compatible with import_historical.php's parseEntry output)
    $data = [
        'advisory_number' => $advzyInfo['adv_num'] ? ('ADVZY ' . $advzyInfo['adv_num']) : null,
        'ctl_element' => null,
        'element_type' => null,
        'header_date' => $headerDate,
        'start_utc' => null,
        'end_utc' => null,
        'impacting_condition' => null,
        'cause_text' => null,
        'comments' => null,
        'program_rate' => null,
        'delay_limit_min' => null,
        'scope_centers' => null,
        'scope_tiers' => null,
        'restriction_value' => null,
        'restriction_unit' => null,
        'mit_fix' => null,
        'route_string' => null,
        'route_name' => null,
        'constrained_area' => null,
        'traffic_from' => null,
        'traffic_to' => null,
        'facilities' => null,
        'subject' => null,
        'body_text' => $raw,
        'dep_airports' => null,
        'probability' => null,
        'cancel_ref_type' => null,
        'cancel_ref_number' => null,
        'fca_id' => null,
        'ctop_name' => null,
        'ctop_fcas' => null,
        'ctop_caps' => null,
        // Internal fields
        '_type' => $type,
        '_line' => $lineNum,
        '_raw' => $raw,
        '_advzy_facility' => $advzyInfo['facility'],
        '_advzy_subtype' => $advzyInfo['subtype'],
        '_ntml_author' => $header['author'] ?? null,
        '_ntml_facility' => $header['facility'] ?? null,
        '_entry_timestamp' => null,
    ];

    // Extract key-value fields from the body
    $bodyLines = array_slice($lines, 1);
    $kvPairs = advzyParseKV($bodyLines);

    // Apply key-value fields
    advzyApplyKV($data, $kvPairs);

    // Type-specific parsing
    switch ($type) {
        case 'GS':
            advzyParseGS($data, $kvPairs, $bodyLines);
            break;
        case 'GDP':
            advzyParseGDP($data, $kvPairs, $bodyLines);
            break;
        case 'CNX':
            advzyParseCNX($data, $kvPairs, $bodyLines);
            break;
        case 'REROUTE':
            advzyParseReroute($data, $kvPairs, $bodyLines, $advzyInfo);
            break;
        case 'ATCSCC':
            advzyParseATCSCC($data, $kvPairs, $bodyLines, $advzyInfo);
            break;
    }

    // Parse time range from footer
    advzyParseFooterTime($data, $bodyLines, $headerDate);

    // Detect element_type if not set
    if (!$data['element_type'] && $data['ctl_element']) {
        $data['element_type'] = perti_detect_element_type($data['ctl_element']);
    }

    return $data;
}

/**
 * Parse the vATCSCC ADVZY header line.
 * Format: "vATCSCC ADVZY ### [FACILITY] [MM/DD/YYYY] [TYPE...]"
 * @return array|null {adv_num, facility, date, type, subtype}
 */
function parseAdvzyHeader(string $line): ?array {
    // Pattern: vATCSCC ADVZY NNN FACILITY [DATE] TYPE
    if (!preg_match('/^vATCSCC\s+ADVZY\s+(\d{3})\s+(\S+)\s+(?:(\d{2}\/\d{2}\/\d{4})\s+)?(.+)$/i', $line, $m)) {
        return null;
    }

    $advNum = $m[1];
    $facility = $m[2];
    $date = $m[3] ?: null;
    $typeStr = strtoupper(trim($m[4]));

    // Classify type
    $type = null;
    $subtype = $typeStr;

    if (strpos($typeStr, 'CANCEL GROUND STOP') !== false || strpos($typeStr, 'CDM GS CNX') !== false || strpos($typeStr, 'CDM GROUND DELAY PROGRAM CNX') !== false) {
        $type = 'CNX';
    } elseif (strpos($typeStr, 'CDM GROUND STOP') !== false || strpos($typeStr, 'GROUND STOP') !== false) {
        $type = 'GS';
    } elseif (strpos($typeStr, 'CDM GROUND DELAY') !== false || strpos($typeStr, 'CDM PROPOSED GROUND DELAY') !== false || strpos($typeStr, 'GDP') !== false) {
        $type = 'GDP';
    } elseif (strpos($typeStr, 'CDM AIRSPACE FLOW') !== false || strpos($typeStr, 'AFP') !== false) {
        $type = 'GDP'; // AFP uses same program structure
    } elseif (strpos($typeStr, 'ROUTE') !== false || strpos($typeStr, 'FCA') !== false) {
        $type = 'REROUTE';
    } elseif (strpos($typeStr, 'OPERATIONS PLAN') !== false) {
        $type = 'ATCSCC';
    } elseif (strpos($typeStr, 'HOTLINE') !== false) {
        $type = 'ATCSCC';
    } elseif (strpos($typeStr, 'INFORMATIONAL') !== false) {
        $type = 'ATCSCC';
    } elseif (strpos($typeStr, 'SWAP') !== false) {
        $type = 'ATCSCC';
    } else {
        $type = 'ATCSCC';
    }

    return [
        'adv_num' => $advNum,
        'facility' => $facility,
        'date' => $date,
        'type' => $type,
        'subtype' => $subtype,
    ];
}

/**
 * Parse key-value lines from ADVZY body.
 * Handles continuation lines (indented lines append to previous value).
 */
function advzyParseKV(array $lines): array {
    $pairs = [];
    $lastKey = null;

    foreach ($lines as $line) {
        // Skip empty, separator, noise
        if (trim($line) === '' || preg_match('/^[-_=]{3,}/', $line)) continue;
        if (trim($line) === '?') continue;

        // Key-value line: "KEY: VALUE" or "KEY...: VALUE"
        if (preg_match('/^([A-Z][A-Z\s\-\/,()]+?)[\s.]*:\s*(.*)$/i', $line, $m)) {
            $key = strtoupper(trim($m[1]));
            $value = trim($m[2]);
            $pairs[$key] = $value;
            $lastKey = $key;
            continue;
        }

        // Continuation: indented line appends to last KV
        if ($lastKey && preg_match('/^\s{2,}(.+)$/', $line, $m)) {
            $pairs[$lastKey] .= ' ' . trim($m[1]);
            continue;
        }

        // Non-KV line: stop KV parsing but don't break (might be body text)
        $lastKey = null;
    }

    return $pairs;
}

/**
 * Apply standard key-value fields to data array.
 */
function advzyApplyKV(array &$data, array $kvPairs): void {
    foreach ($kvPairs as $key => $value) {
        if ($value === '') continue;

        switch (true) {
            case $key === 'CTL ELEMENT':
                $data['ctl_element'] = strtoupper(trim($value));
                break;
            case $key === 'ELEMENT TYPE':
                $et = strtoupper(trim($value));
                if ($et === 'APT' || $et === 'ARPT') $data['element_type'] = 'APT';
                else $data['element_type'] = $et;
                break;
            case $key === 'NAME':
                $data['route_name'] = trim($value);
                break;
            case strpos($key, 'IMPACTING CONDITION') !== false:
                $data['impacting_condition'] = strtoupper(trim($value));
                break;
            case strpos($key, 'PROBABILITY') !== false:
                $data['probability'] = strtoupper(trim($value));
                break;
            case $key === 'PROGRAM RATE' || $key === 'ANTICIPATED PROGRAM RATE':
                // May be multi-hour: "42 / 42 / 48 / 48 / 55" — take first value
                if (preg_match('/(\d+)/', $value, $rm)) {
                    $data['program_rate'] = intval($rm[1]);
                }
                // Store full hourly rates if multi-value
                if (strpos($value, '/') !== false) {
                    $data['rates_hourly'] = array_map('intval', array_map('trim', explode('/', $value)));
                }
                break;
            case strpos($key, 'DELAY LIMIT') !== false || strpos($key, 'MAXIMUM DELAY') !== false || strpos($key, 'ANTICIPATED MAXIMUM DELAY') !== false:
                $val = intval(preg_replace('/[^0-9]/', '', $value));
                if ($val > 0) $data['delay_limit_min'] = $val;
                break;
            case $key === 'COMMENTS':
                $data['comments'] = $value;
                break;
            case strpos($key, 'IMPACTED AREA') !== false || strpos($key, 'CONSTRAINED AREA') !== false:
                $data['constrained_area'] = strtoupper(trim($value));
                break;
            case $key === 'REASON':
                $data['impacting_condition'] = $data['impacting_condition'] ?? strtoupper(trim($value));
                break;
            case strpos($key, 'FACILITIES INCLUDED') !== false:
                $data['facilities'] = strtoupper(str_replace(' ', '', trim($value)));
                break;
            case strpos($key, 'DEP FACILITIES') !== false || strpos($key, 'DEPARTURE SCOPE') !== false:
                $data['dep_airports'] = trim($value);
                break;
            case $key === 'FLT INCL' || $key === 'FLIGHT STATUS':
                $data['scope_text'] = trim($value);
                break;
            case strpos($key, 'CONSTRAINED FACILITIES') !== false:
                $data['constrained_area'] = $data['constrained_area'] ?? strtoupper(trim($value));
                break;
            case strpos($key, 'ASSOCIATED RESTRICTIONS') !== false:
                if ($value && $value !== 'SEE NTML FOR RESTRICTIONS') {
                    $data['_restrictions'] = trim($value);
                }
                break;
            case $key === 'REMARKS':
                if ($value) $data['_remarks'] = trim($value);
                break;
            case $key === 'MODIFICATIONS':
                if ($value) $data['_modifications'] = trim($value);
                break;
        }
    }
}

// =========================================================================
// Type-Specific Parsers
// =========================================================================

function advzyParseGS(array &$data, array $kvPairs, array $lines): void {
    // GS period: "29/0030Z - 29/0115Z"
    foreach ($kvPairs as $k => $v) {
        if (strpos($k, 'GROUND STOP PERIOD') !== false && preg_match('/(\d{2})\/(\d{4})Z?\s*[\x{2013}\x{2014}–—-]+\s*(\d{2})\/(\d{4})Z?/u', $v, $m)) {
            $data['_gs_period'] = $v;
        }
        if (strpos($k, 'DELAYS') !== false && strpos($k, 'CURRENT') !== false) {
            if (preg_match('/(\d+)\/(\d+)\/(\d+)/', $v, $dm)) {
                $data['_delays'] = ['total' => (int)$dm[1], 'max' => (int)$dm[2], 'avg' => (int)$dm[3]];
            }
        }
        if (strpos($k, 'DELAYS') !== false && strpos($k, 'NEW') !== false) {
            if (preg_match('/(\d+)\s*[\/,]\s*(\d+)\s*[\/,]\s*(\d+)/', $v, $dm)) {
                $data['_delays_new'] = ['total' => (int)$dm[1], 'max' => (int)$dm[2], 'avg' => (int)$dm[3]];
            }
        }
    }
}

function advzyParseGDP(array &$data, array $kvPairs, array $lines): void {
    foreach ($kvPairs as $k => $v) {
        if (strpos($k, 'ARRIVALS ESTIMATED') !== false || strpos($k, 'CUMULATIVE PROGRAM PERIOD') !== false) {
            $data['_program_period'] = $v;
        }
        if (strpos($k, 'AVERAGE DELAY') !== false) {
            $val = intval(preg_replace('/[^0-9]/', '', $v));
            if ($val > 0) $data['_avg_delay'] = $val;
        }
        if (strpos($k, 'DELAY ASSIGNMENT TABLE') !== false) {
            $data['scope_centers'] = preg_split('/[\/\s]+/', strtoupper(trim($v)));
        }
        if (strpos($k, 'CANADIAN') !== false) {
            $data['_canadian'] = trim($v);
        }
        if ($k === 'POP-UP FACTOR') {
            $data['_popup_factor'] = strtoupper(trim($v));
        }
    }
}

function advzyParseCNX(array &$data, array $kvPairs, array $lines): void {
    // GS CNX has: GS CNX PERIOD, FLIGHTS MAY RECEIVE...
    foreach ($kvPairs as $k => $v) {
        if (strpos($k, 'CNX PERIOD') !== false) {
            $data['_cnx_period'] = $v;
        }
    }
}

function advzyParseReroute(array &$data, array $kvPairs, array $lines, array $advzyInfo): void {
    // Extract INCLUDE TRAFFIC for traffic_from/traffic_to
    foreach ($kvPairs as $k => $v) {
        if ($k === 'INCLUDE TRAFFIC') {
            advzyParseTraffic($data, $v);
        }
        if ($k === 'VALID') {
            advzyParseValid($data, $v, $data['header_date']);
        }
    }

    // Set ctl_element from traffic destination
    if (!$data['ctl_element'] && $data['traffic_to']) {
        $dest = preg_replace('/^K/', '', $data['traffic_to']);
        $data['ctl_element'] = $dest;
    }

    // Extract route table
    $routes = advzyParseRouteTable($lines);
    if ($routes) {
        $data['_routes'] = $routes;
        // Build single route_string from first route
        $data['route_string'] = $routes[0]['route'] ?? null;
    }

    // Check if FCA
    if (strpos($advzyInfo['subtype'], 'FCA') !== false && $data['route_name']) {
        $data['fca_id'] = $data['route_name'];
    }

    // Extract TMI ID
    $rawText = implode("\n", $lines);
    if (preg_match('/TMI ID:\s*(\S+)/i', $rawText, $m)) {
        $data['_tmi_id'] = $m[1];
    }
}

function advzyParseATCSCC(array &$data, array $kvPairs, array $lines, array $advzyInfo): void {
    // Set subtype for downstream handling
    $subtype = $advzyInfo['subtype'];

    // For HOTLINE entries, the constrained facilities and event time are key
    if (strpos($subtype, 'HOTLINE') !== false) {
        $data['subject'] = $subtype;
    }

    // For OPERATIONS PLAN, extract EVENT TIME
    foreach ($kvPairs as $k => $v) {
        if ($k === 'EVENT TIME' || strpos($k, 'EVENT TIME') !== false) {
            $data['_event_time'] = trim($v);
        }
        if (strpos($k, 'VALID FOR') !== false || $k === 'VALID') {
            advzyParseValid($data, $v, $data['header_date']);
        }
    }

    // For INFORMATIONAL, extract "VALID FOR ddhhmm THROUGH ddhhmm" from body
    foreach ($lines as $line) {
        if (preg_match('/VALID\s+FOR\s+(\d{6})\s+THROUGH\s+(\d{6})/i', $line, $m)) {
            $data['_valid_from_raw'] = $m[1];
            $data['_valid_to_raw'] = $m[2];
        }
    }

    // Store body text (everything after KV fields)
    $bodyStart = false;
    $bodyParts = [];
    foreach ($lines as $line) {
        $t = trim($line);
        if ($t === '' && !$bodyStart) { $bodyStart = true; continue; }
        if ($bodyStart && $t !== '' && !preg_match('/^\d{6}\s*-\s*\d{6}/', $t) && !preg_match('/^\d{2}\/\d{2}\/\d{2}\s+\d{2}:\d{2}/', $t)) {
            $bodyParts[] = $t;
        }
    }
    if ($bodyParts) {
        $data['body_text'] = implode("\n", $bodyParts);
    }
}

// =========================================================================
// Helpers
// =========================================================================

/**
 * Parse INCLUDE TRAFFIC field into traffic_from and traffic_to.
 * Formats: "KJFK/KEWR/KLGA DEPARTURES TO KMIA" or "ZLA/ZLC/ZSE DEPARTURES TO CYVR"
 */
function advzyParseTraffic(array &$data, string $value): void {
    $v = strtoupper(trim($value));

    if (preg_match('/^(.+?)\s+DEPARTURES?\s+TO\s+(.+)$/i', $v, $m)) {
        $data['traffic_from'] = trim($m[1]);
        $data['traffic_to'] = trim($m[2]);
    } elseif (preg_match('/^ALL\s+DEPARTURES?\s+(?:THROUGH|TO|VIA)\s+(.+)$/i', $v, $m)) {
        $data['traffic_from'] = 'ALL';
        $data['traffic_to'] = trim($m[1]);
    } else {
        $data['traffic_from'] = $v;
    }
}

/**
 * Parse VALID field into start_utc/end_utc.
 * Format: "ETD DDHHMM TO DDHHMM" or "DDHHMM TO DDHHMM"
 */
function advzyParseValid(array &$data, string $value, ?string $headerDate): void {
    $v = strtoupper(trim($value));

    // "ETD 242100TO 250300" or "ETD 202215 TO 210000"
    if (preg_match('/(?:ETD\s+)?(\d{6})\s*TO\s*(\d{6})/i', $v, $m)) {
        $baseDate = advzyBaseDate($headerDate);
        $data['start_utc'] = advzyResolveTfmsTime($m[1], $baseDate);
        $data['end_utc'] = advzyResolveTfmsTime($m[2], $baseDate);
    }
}

/**
 * Parse route table from body lines.
 * Returns array of [{orig, dest, route}].
 */
function advzyParseRouteTable(array $lines): array {
    $routes = [];
    $inTable = false;
    $currentOrig = null;
    $currentDest = null;
    $currentRoute = '';

    foreach ($lines as $line) {
        $t = trim($line);

        // Detect table start: header row or separator
        if (preg_match('/^ORIG\s+DEST\s+ROUTE/i', $t) || preg_match('/^-{4}\s+-{4}\s+-{4,}/', $t)) {
            $inTable = true;
            continue;
        }

        // Detect table end markers
        if ($inTable && $t === '') {
            // Blank line might separate route groups — flush current
            if ($currentRoute) {
                $routes[] = ['orig' => $currentOrig, 'dest' => $currentDest, 'route' => trim($currentRoute)];
                $currentRoute = '';
            }
            continue;
        }

        // End table at TMI ID, footer, or new KV section
        if ($inTable && (preg_match('/^TMI ID:/i', $t) || preg_match('/^\d{6}\s*-/', $t) || preg_match('/^\d{2}\/\d{2}\/\d{2}\s+\d{2}:\d{2}/', $t))) {
            if ($currentRoute) {
                $routes[] = ['orig' => $currentOrig, 'dest' => $currentDest, 'route' => trim($currentRoute)];
                $currentRoute = '';
            }
            break;
        }

        if (!$inTable) continue;

        // Route line: "ORD     MSP     >PMPKN NEATO DLLAN RONIC KAMMA< KKILR3"
        // Or continuation: "                     Q65 DOFFY JUULI SSCOT5"
        // Or exclusion lines starting with "-": "-FMY -PIE"
        if (preg_match('/^-[A-Z]{2,4}\s/', $t)) {
            // Exclusion line — append to current route
            $currentRoute .= ' ' . $t;
            continue;
        }

        // Check for new route line (starts with airport/facility code)
        if (preg_match('/^([A-Z][A-Z0-9]{1,3}(?:\s+[A-Z][A-Z0-9]{1,3})*)\s{2,}([A-Z][A-Z0-9]{1,3}(?:\s*[\/,]\s*[A-Z]{2,4})*)\s{2,}(.+)$/', $t, $rm)) {
            // Flush previous
            if ($currentRoute) {
                $routes[] = ['orig' => $currentOrig, 'dest' => $currentDest, 'route' => trim($currentRoute)];
            }
            $currentOrig = trim($rm[1]);
            $currentDest = trim($rm[2]);
            $currentRoute = trim($rm[3]);
        } elseif (preg_match('/^([A-Z][A-Z0-9]{1,3}(?:\s+[A-Z][A-Z0-9]{1,3})*)\s{2,}(.+)$/', $t, $rm2)) {
            // Two-column: origin + route (dest inherited from context or single)
            if ($currentRoute) {
                $routes[] = ['orig' => $currentOrig, 'dest' => $currentDest, 'route' => trim($currentRoute)];
            }
            $currentOrig = trim($rm2[1]);
            $currentRoute = trim($rm2[2]);
        } else {
            // Continuation line
            $currentRoute .= ' ' . $t;
        }
    }

    // Flush last
    if ($currentRoute) {
        $routes[] = ['orig' => $currentOrig, 'dest' => $currentDest, 'route' => trim($currentRoute)];
    }

    return $routes;
}

/**
 * Parse footer time range (DDHHMM-DDHHMM) from the last lines of the block.
 */
function advzyParseFooterTime(array &$data, array $lines, ?string $headerDate): void {
    // Already have times from VALID field? Don't overwrite
    if ($data['start_utc'] && $data['end_utc']) return;

    $baseDate = advzyBaseDate($headerDate);
    $n = count($lines);

    // Check last ~10 lines for footer time pattern
    for ($i = max(0, $n - 10); $i < $n; $i++) {
        $t = trim($lines[$i]);

        // "DDHHMM-DDHHMM" or "DDHHMM - DDHHMM" or "{DDHHMM} - {DDHHMM}"
        $clean = str_replace(['{', '}'], '', $t);
        if (preg_match('/^(\d{6})\s*-\s*(\d{6})/', $clean, $m)) {
            if (!$data['start_utc']) $data['start_utc'] = advzyResolveTfmsTime($m[1], $baseDate);
            if (!$data['end_utc']) $data['end_utc'] = advzyResolveTfmsTime($m[2], $baseDate);
            break;
        }
        // "DDHHMM AND LATER" or "DDHHMM-AND LATER"
        if (preg_match('/^(\d{6})\s*[-\s]+AND\s+LATER/i', $t, $m)) {
            if (!$data['start_utc']) $data['start_utc'] = advzyResolveTfmsTime($m[1], $baseDate);
            break;
        }
        // "EFFECTIVE TIME: DDHHMM - DDHHMM"
        if (preg_match('/EFFECTIVE TIME:\s*(\d{6})\s*-\s*(\d{6})/i', $t, $m)) {
            if (!$data['start_utc']) $data['start_utc'] = advzyResolveTfmsTime($m[1], $baseDate);
            if (!$data['end_utc']) $data['end_utc'] = advzyResolveTfmsTime($m[2], $baseDate);
            break;
        }
        // "EFFECTIVE TIME: M/DD/YYYY HH:MM" (2023+ long format)
        if (preg_match('/EFFECTIVE TIME:\s*(\d{1,2})\/(\d{1,2})\/(\d{4})\s+(\d{1,2}):(\d{2})/i', $t, $m)) {
            $data['start_utc'] = sprintf('%04d-%02d-%02d %02d:%s:00', (int)$m[3], (int)$m[1], (int)$m[2], (int)$m[4], $m[5]);
            break;
        }
    }

    // Signature line: "YY/MM/DD HH:MM" — use as _entry_timestamp
    for ($i = max(0, $n - 5); $i < $n; $i++) {
        $t = trim($lines[$i]);
        if (preg_match('/^(\d{2})\/(\d{2})\/(\d{2})\s+(\d{2}):(\d{2})/', $t, $m)) {
            $y = (int)$m[1] + 2000;
            $data['_entry_timestamp'] = sprintf('%04d-%02d-%02d %s:%s:00', $y, (int)$m[2], (int)$m[3], $m[4], $m[5]);
            break;
        }
    }
}

/**
 * Convert header date MM/DD/YYYY to YYYY-MM-DD.
 */
function advzyBaseDate(?string $headerDate): ?string {
    if (!$headerDate) return null;
    $parts = explode('/', $headerDate);
    if (count($parts) !== 3) return null;
    return $parts[2] . '-' . $parts[0] . '-' . $parts[1];
}

/**
 * Resolve a 6-digit TFMS time (ddHHmm) to full UTC datetime.
 */
function advzyResolveTfmsTime(string $tfmsTime, ?string $baseDate): ?string {
    if (strlen($tfmsTime) !== 6) return null;

    $dd = substr($tfmsTime, 0, 2);
    $HH = substr($tfmsTime, 2, 2);
    $mm = substr($tfmsTime, 4, 2);

    if ($baseDate) {
        $baseParts = explode('-', $baseDate);
        $year = $baseParts[0];
        $month = $baseParts[1];
        $baseDay = (int)$baseParts[2];

        // Handle month rollover: if entry day < base day by more than 5, next month
        if ((int)$dd < $baseDay - 5) {
            $month = str_pad(((int)$month % 12) + 1, 2, '0', STR_PAD_LEFT);
            if ($month === '01') $year = (string)((int)$year + 1);
        }
    } else {
        $year = gmdate('Y');
        $month = gmdate('m');
    }

    $dateStr = sprintf('%s-%s-%s %s:%s:00', $year, $month, $dd, $HH, $mm);
    $ts = strtotime($dateStr . ' UTC');
    if ($ts === false) return null;

    return gmdate('Y-m-d H:i:s', $ts);
}
