<?php
/**
 * TMI Historical Import Tool
 *
 * Parses Discord-formatted TMI messages (advisories, NTML entries, GS/GDP programs)
 * and inserts them into the correct VATSIM_TMI database tables.
 *
 * Usage:
 *   POST /scripts/tmi/import_historical.php
 *   Content-Type: application/json
 *
 *   Body (option A - pre-split entries):
 *     { "entries": ["```\nATCSCC ADVZY 003...\n```", "```\n...\n```"] }
 *
 *   Body (option B - raw paste, auto-split):
 *     { "raw": "```\nATCSCC ADVZY 003...\n```\n```\n...\n```" }
 *
 *   Optional flags:
 *     "dry_run": true     - Parse only, no database writes
 *     "force": true       - Skip deduplication checks
 *     "created_by": "123" - Override created_by CID
 *
 * @package PERTI
 * @subpackage Scripts/TMI
 * @date 2026-02-09
 */

if (php_sapi_name() !== 'cli') {
    header('Content-Type: application/json');
}

// =============================================================================
// Bootstrap - Read input first to determine dry_run before loading config
// =============================================================================

require_once __DIR__ . '/../../load/perti_constants.php';
require_once __DIR__ . '/ntml_parser.php';
require_once __DIR__ . '/advzy_parser.php';

// =============================================================================
// Configuration
// =============================================================================

$DRY_RUN = false;
$FORCE = false;
$CREATED_BY = null;
$CREATED_BY_NAME = 'Historical Import';

// =============================================================================
// Input Handling
// =============================================================================

// Accept both POST and CLI
if (php_sapi_name() === 'cli') {
    $input = file_get_contents('php://stdin');
    if (empty($input)) {
        echo json_encode(['error' => 'No input provided. Pipe JSON to stdin.']);
        exit(1);
    }
} else {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['error' => 'POST required']);
        exit;
    }
    $input = file_get_contents('php://input');
}

$payload = json_decode($input, true);
if (!$payload) {
    echo json_encode(['error' => 'Invalid JSON input']);
    exit(1);
}

$DRY_RUN = !empty($payload['dry_run']);
$FORCE = !empty($payload['force']);
$CREATED_BY = $payload['created_by'] ?? null;
$CREATED_BY_NAME = $payload['created_by_name'] ?? 'Historical Import';

// Detect input mode: ntml_raw, advzy_raw, entries, or raw
$INPUT_MODE = 'tfms'; // default: TFMS code-block format
$rawEntries = [];
$preParsedEntries = [];

if (!empty($payload['ntml_raw'])) {
    $INPUT_MODE = 'ntml';
    $preParsedEntries = parseNtmlFile($payload['ntml_raw']);
} elseif (!empty($payload['ntml_file'])) {
    $INPUT_MODE = 'ntml';
    $fileContent = @file_get_contents($payload['ntml_file']);
    if ($fileContent === false) {
        echo json_encode(['error' => 'Cannot read ntml_file: ' . $payload['ntml_file']]);
        exit(1);
    }
    $preParsedEntries = parseNtmlFile($fileContent);
} elseif (!empty($payload['advzy_raw'])) {
    $INPUT_MODE = 'advzy';
    $preParsedEntries = parseAdvzyFile($payload['advzy_raw']);
} elseif (!empty($payload['advzy_file'])) {
    $INPUT_MODE = 'advzy';
    $fileContent = @file_get_contents($payload['advzy_file']);
    if ($fileContent === false) {
        echo json_encode(['error' => 'Cannot read advzy_file: ' . $payload['advzy_file']]);
        exit(1);
    }
    $preParsedEntries = parseAdvzyFile($fileContent);
} elseif (!empty($payload['entries'])) {
    $rawEntries = $payload['entries'];
} elseif (!empty($payload['raw'])) {
    $rawEntries = splitRawPaste($payload['raw']);
} else {
    echo json_encode(['error' => 'Provide "entries", "raw", "ntml_raw", "ntml_file", "advzy_raw", or "advzy_file"']);
    exit(1);
}

// =============================================================================
// Database Connection (only needed for non-dry-run)
// =============================================================================

$conn = null;
if (!$DRY_RUN) {
    // Load config only when we need database access
    require_once __DIR__ . '/../../load/config.php';

    try {
        $connStr = "sqlsrv:Server=" . TMI_SQL_HOST . ";Database=" . TMI_SQL_DATABASE;
        $conn = new PDO($connStr, TMI_SQL_USERNAME, TMI_SQL_PASSWORD);
        $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    } catch (Exception $e) {
        echo json_encode(['error' => 'Database connection failed: ' . $e->getMessage()]);
        exit(1);
    }
}

// =============================================================================
// Build Unified Entry List
// =============================================================================

$entriesToProcess = []; // [{type, data, line?} or {skip_reason, preview?}]

if ($INPUT_MODE === 'ntml' || $INPUT_MODE === 'advzy') {
    // Pre-parsed entries from file parsers
    foreach ($preParsedEntries as $pe) {
        $entriesToProcess[] = [
            'type' => $pe['_type'],
            'data' => $pe,
            'line' => $pe['_line'] ?? null,
        ];
    }
} else {
    // TFMS code-block entries (existing path)
    foreach ($rawEntries as $rawText) {
        $cleanText = cleanDiscordMessage($rawText);
        if (empty(trim($cleanText))) {
            $entriesToProcess[] = ['type' => null, 'data' => null, 'skip_reason' => 'Empty after cleaning'];
            continue;
        }
        $type = detectEntryType($cleanText);
        if (!$type) {
            $entriesToProcess[] = ['type' => null, 'data' => null, 'skip_reason' => 'Unrecognized type', 'preview' => substr($cleanText, 0, 100)];
            continue;
        }
        $parsed = parseEntry($cleanText, $type);
        $parsed['_raw'] = $cleanText;
        $parsed['_type'] = $type;
        $entriesToProcess[] = ['type' => $type, 'data' => $parsed];
    }
}

// =============================================================================
// Process Each Entry
// =============================================================================

$results = [];
$counts = ['total' => 0, 'inserted' => 0, 'skipped' => 0, 'errors' => 0];

foreach ($entriesToProcess as $i => $entry) {
    $counts['total']++;

    // Handle pre-skipped entries
    if (isset($entry['skip_reason'])) {
        $results[] = ['index' => $i, 'status' => 'skipped', 'reason' => $entry['skip_reason'], 'preview' => $entry['preview'] ?? null];
        $counts['skipped']++;
        continue;
    }

    $type = $entry['type'];
    $parsed = $entry['data'];

    try {
        if ($DRY_RUN) {
            $results[] = ['index' => $i, 'status' => 'dry_run', 'type' => $type, 'parsed' => $parsed, 'line' => $entry['line'] ?? null];
            continue;
        }

        // Check for duplicates (unless forced)
        if (!$FORCE) {
            $dup = checkDuplicate($conn, $type, $parsed);
            if ($dup) {
                $results[] = ['index' => $i, 'status' => 'skipped', 'reason' => 'Duplicate: ' . $dup, 'type' => $type];
                $counts['skipped']++;
                continue;
            }
        }

        // Insert based on type
        $insertResult = insertEntry($conn, $type, $parsed);
        $results[] = ['index' => $i, 'status' => 'inserted', 'type' => $type, 'ids' => $insertResult];
        $counts['inserted']++;

    } catch (Exception $e) {
        $results[] = ['index' => $i, 'status' => 'error', 'reason' => $e->getMessage(), 'line' => $entry['line'] ?? null];
        $counts['errors']++;
    }
}

// =============================================================================
// Output
// =============================================================================

echo json_encode([
    'success' => $counts['errors'] === 0 && $counts['inserted'] > 0,
    'dry_run' => $DRY_RUN,
    'counts' => $counts,
    'results' => $results
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);


// =============================================================================
// Parsing Functions
// =============================================================================

/**
 * Split a raw paste into individual entries.
 * Splits on ``` code block boundaries.
 */
function splitRawPaste(string $raw): array {
    $entries = [];

    // Split on code block boundaries
    if (preg_match_all('/```[\s\S]*?```/m', $raw, $matches)) {
        foreach ($matches[0] as $block) {
            $entries[] = $block;
        }
    }

    // If no code blocks found, try splitting on double blank lines
    if (empty($entries)) {
        $parts = preg_split('/\n\s*\n\s*\n/', $raw);
        foreach ($parts as $part) {
            $part = trim($part);
            if (!empty($part)) {
                $entries[] = $part;
            }
        }
    }

    // If still nothing, treat the whole thing as one entry
    if (empty($entries)) {
        $entries[] = trim($raw);
    }

    return $entries;
}

/**
 * Clean Discord message formatting.
 * Strips code block markers, staging prefixes, and normalizes whitespace.
 */
function cleanDiscordMessage(string $text): string {
    // Strip staging prefix FIRST (appears before code block markers)
    $text = preg_replace('/^🧪\s*\*\*\[STAGING\]\*\*\s*/m', '', $text);

    // Strip code block markers
    $text = preg_replace('/^```\w*\s*\n?/', '', $text);
    $text = preg_replace('/\n?```\s*$/', '', $text);

    // Strip Discord message part indicators like (1/3) (2/3) etc.
    $text = preg_replace('/\s*\(\d+\/\d+\)\s*$/', '', $text);

    // Normalize line endings
    $text = str_replace("\r\n", "\n", $text);

    return trim($text);
}

/**
 * Detect the type of a TMI entry from its text content.
 *
 * @return string|null One of: GS, GDP, AFP, CTOP, REROUTE, MIT, MINIT, ATCSCC, CNX, NTML
 */
function detectEntryType(string $text): ?string {
    $firstLine = strtoupper(trim(strtok($text, "\n")));

    // Program types (from advisory header)
    if (strpos($firstLine, 'CDM GROUND STOP') !== false || strpos($firstLine, 'GROUND STOP') !== false) {
        // Check it's not a cancellation
        if (strpos($firstLine, 'CANCELLATION') !== false) return 'CNX';
        return 'GS';
    }
    if (strpos($firstLine, 'CDM GROUND DELAY PROGRAM') !== false || strpos($firstLine, 'GROUND DELAY PROGRAM') !== false) {
        return 'GDP';
    }
    if (strpos($firstLine, 'CDM AIRSPACE FLOW PROGRAM') !== false || strpos($firstLine, 'AIRSPACE FLOW PROGRAM') !== false) {
        return 'AFP';
    }
    if (strpos($firstLine, 'ACTUAL CTOP') !== false || strpos($firstLine, 'CTOP') !== false) {
        return 'CTOP';
    }
    if (strpos($firstLine, 'PLAYBOOK ROUTE') !== false || strpos($firstLine, 'REROUTE') !== false) {
        return 'REROUTE';
    }
    if (strpos($firstLine, 'CANCELLATION') !== false) {
        return 'CNX';
    }
    if (strpos($firstLine, 'GENERAL MESSAGE') !== false) {
        return 'ATCSCC';
    }

    // MIT/MINIT: check header for standalone MIT/MINIT
    if (preg_match('/\bMINIT\b/', $firstLine)) {
        return 'MINIT';
    }
    if (preg_match('/\bMIT\b/', $firstLine)) {
        return 'MIT';
    }

    // Check body for NTML-style entries (RESTRICTION, AT FIX, etc.)
    $upper = strtoupper($text);
    if (strpos($upper, 'RESTRICTION') !== false && strpos($upper, 'MIT') !== false) {
        return 'MIT';
    }
    if (strpos($upper, 'RESTRICTION') !== false && strpos($upper, 'MINIT') !== false) {
        return 'MINIT';
    }

    // If it has an ADVZY header, treat as general advisory
    if (strpos($firstLine, 'ADVZY') !== false) {
        return 'ATCSCC';
    }

    // Check for NTML-style content (CTL ELEMENT, facility lines, etc.)
    if (strpos($upper, 'CTL ELEMENT') !== false || strpos($upper, 'ELEMENT TYPE') !== false) {
        return 'NTML';
    }

    return null;
}

/**
 * Parse a TMI entry into structured data.
 */
function parseEntry(string $text, string $type): array {
    $data = [
        'advisory_number' => null,
        'ctl_element' => null,
        'element_type' => null,
        'header_date' => null,
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
        'body_text' => null,
        'dep_airports' => null,
        'probability' => null,
        'cancel_ref_type' => null,
        'cancel_ref_number' => null,
        'fca_id' => null,
        'ctop_name' => null,
        'ctop_fcas' => null,
        'ctop_caps' => null,
    ];

    // Extract advisory number from header
    if (preg_match('/ADVZY\s+(\d{3})/i', $text, $m)) {
        $data['advisory_number'] = 'ADVZY ' . $m[1];
    }

    // Extract header date (MM/DD/YYYY)
    if (preg_match('#(\d{2}/\d{2}/\d{4})#', $text, $m)) {
        $data['header_date'] = $m[1];
    }

    // Parse key-value lines
    $kvPairs = parseKeyValueLines($text);
    foreach ($kvPairs as $key => $value) {
        $keyNorm = strtoupper(trim($key));

        switch (true) {
            case $keyNorm === 'CTL ELEMENT':
                $data['ctl_element'] = strtoupper(trim($value));
                break;
            case $keyNorm === 'ELEMENT TYPE':
                $data['element_type'] = strtoupper(trim($value));
                break;
            case strpos($keyNorm, 'PROGRAM RATE') !== false:
                $data['program_rate'] = intval(preg_replace('/[^0-9]/', '', $value));
                break;
            case strpos($keyNorm, 'MAX DELAY') !== false:
                $data['delay_limit_min'] = intval(preg_replace('/[^0-9]/', '', $value));
                break;
            case strpos($keyNorm, 'IMPACTING CONDITION') !== false:
                $data['impacting_condition'] = strtoupper(trim($value));
                break;
            case strpos($keyNorm, 'SCOPE - CENTERS') !== false || strpos($keyNorm, 'SCOPE CENTERS') !== false:
                $data['scope_centers'] = preg_split('/\s+/', strtoupper(trim($value)));
                break;
            case strpos($keyNorm, 'SCOPE - TIERS') !== false || strpos($keyNorm, 'SCOPE TIERS') !== false:
                $data['scope_tiers'] = trim($value);
                break;
            case strpos($keyNorm, 'SCOPE') !== false && strpos($keyNorm, 'CENTER') === false && strpos($keyNorm, 'TIER') === false:
                $data['scope_text'] = trim($value);
                break;
            case strpos($keyNorm, 'RESTRICTION') !== false:
                // Parse "20 NM MIT" or "5 MIN MINIT"
                if (preg_match('/(\d+)\s*(NM|MIN|MINIT|MIT)/i', $value, $rm)) {
                    $data['restriction_value'] = intval($rm[1]);
                    $unit = strtoupper($rm[2]);
                    $data['restriction_unit'] = in_array($unit, ['NM', 'MIT']) ? 'NM' : 'MIN';
                }
                break;
            case strpos($keyNorm, 'AT FIX') !== false:
                $data['mit_fix'] = strtoupper(trim($value));
                break;
            case strpos($keyNorm, 'DEP ARPTS') !== false || strpos($keyNorm, 'DEPARTURE AIRPORTS') !== false:
                $data['dep_airports'] = strtoupper(trim($value));
                break;
            case strpos($keyNorm, 'PROBABILITY') !== false:
                $data['probability'] = trim(str_replace('%', '', $value));
                break;
            case strpos($keyNorm, 'ROUTE DESIGNATOR') !== false:
                $data['route_name'] = strtoupper(trim($value));
                break;
            case strpos($keyNorm, 'CONSTRAINED AREA') !== false:
                $data['constrained_area'] = strtoupper(trim($value));
                break;
            case strpos($keyNorm, 'TRAFFIC FROM') !== false:
                $data['traffic_from'] = strtoupper(trim($value));
                break;
            case strpos($keyNorm, 'TRAFFIC TO') !== false:
                $data['traffic_to'] = strtoupper(trim($value));
                break;
            case strpos($keyNorm, 'PARTICIPATING FAC') !== false:
                $data['facilities'] = strtoupper(trim($value));
                break;
            case $keyNorm === 'FACILITY':
                if (!$data['ctl_element']) {
                    $data['ctl_element'] = strtoupper(trim($value));
                }
                break;
            case $keyNorm === 'SUBJECT':
                $data['subject'] = trim($value);
                break;
            case strpos($keyNorm, 'CANCEL ADVISORY') !== false:
                // Parse "GDP 003" or "GS ADVZY 001"
                if (preg_match('/(\w+)\s+(?:ADVZY\s+)?(\d{3})/i', $value, $cm)) {
                    $data['cancel_ref_type'] = strtoupper($cm[1]);
                    $data['cancel_ref_number'] = 'ADVZY ' . $cm[2];
                }
                break;
            case strpos($keyNorm, 'ASSIGNED FCAS') !== false:
                $data['ctop_fcas'] = strtoupper(trim($value));
                break;
            case strpos($keyNorm, 'CAPACITY VALUES') !== false:
                $data['ctop_caps'] = trim($value);
                break;
            case strpos($keyNorm, 'DELAY ASSIGNMENT') !== false:
                // Ignore — informational only
                break;
            case strpos($keyNorm, 'ADL TIME') !== false:
                // Ignore — snapshot time, not stored
                break;
        }
    }

    // Extract ROUTE: section (multi-line after "ROUTE:" label)
    if (preg_match('/\nROUTE:\s*\n([\s\S]*?)(?=\n[A-Z]{2,}[\s.]*:|\n\n|\nEND|\n\d{6}-)/i', $text, $rm)) {
        $data['route_string'] = trim($rm[1]);
    }

    // Extract COMMENTS: section
    if (preg_match('/\nCOMMENTS:\s*\n([\s\S]*?)(?=\n\d{6}-|\nEND|\n$)/i', $text, $cm)) {
        $data['comments'] = trim($cm[1]);
    }

    // Extract REASON: section (for cancellations)
    if (preg_match('/\nREASON:\s*\n([\s\S]*?)(?=\nEND|\n$)/i', $text, $rm)) {
        $data['cause_text'] = trim($rm[1]);
    }

    // Extract general body text (for ATCSCC / general messages)
    if ($type === 'ATCSCC' && !$data['body_text']) {
        // Body is everything after the SUBJECT line and before COMMENTS or time footer
        if (preg_match('/\nSUBJECT[.\s]*:.*?\n\s*(?:ADL TIME[.\s]*:.*?\n\s*)?\n([\s\S]*?)(?=\nCOMMENTS:|\n\d{6}-|\nEND)/i', $text, $bm)) {
            $data['body_text'] = trim($bm[1]);
        }
    }

    // Extract FCA ID for AFP
    if ($type === 'AFP') {
        $firstLine = strtoupper(trim(strtok($text, "\n")));
        if (preg_match('/\b(FCA\d+)\b/', $firstLine, $fm)) {
            $data['fca_id'] = $fm[1];
        }
        if (!$data['ctl_element']) {
            $data['ctl_element'] = $data['fca_id'];
        }
    }

    // Extract CTOP name
    if ($type === 'CTOP') {
        $firstLine = strtoupper(trim(strtok($text, "\n")));
        if (preg_match('/\b(CTP\d+)\b/', $firstLine, $ctm)) {
            $data['ctop_name'] = $ctm[1];
        }
        if (!$data['ctl_element']) {
            $data['ctl_element'] = $data['ctop_name'];
        }
    }

    // Parse time range from footer (ddHHmm-ddHHmm)
    if (preg_match('/(\d{6})\s*-\s*(\d{6})/', $text, $tm)) {
        $baseDate = null;
        if ($data['header_date']) {
            $parts = explode('/', $data['header_date']);
            if (count($parts) === 3) {
                $baseDate = $parts[2] . '-' . $parts[0] . '-' . $parts[1]; // YYYY-MM-DD
            }
        }
        $data['start_utc'] = parseTfmsTime($tm[1], $baseDate);
        $data['end_utc'] = parseTfmsTime($tm[2], $baseDate);
    }

    // Detect element_type if not parsed
    if (!$data['element_type'] && $data['ctl_element']) {
        $data['element_type'] = perti_detect_element_type($data['ctl_element']);
    }

    // Store full body text
    $data['body_text'] = $data['body_text'] ?? $text;

    return $data;
}

/**
 * Parse TFMS key-value lines (KEY...: VALUE format).
 * Returns associative array of key => value.
 */
function parseKeyValueLines(string $text): array {
    $pairs = [];
    $lines = explode("\n", $text);

    foreach ($lines as $line) {
        // Match lines like "CTL ELEMENT...............: KJFK"
        // The key is everything before the dots/colons, value is after the colon
        if (preg_match('/^([A-Z][A-Z\s\-\/]+?)[\s.]*:\s*(.+)$/i', $line, $m)) {
            $key = trim($m[1]);
            $value = trim($m[2]);
            if (!empty($key) && !empty($value)) {
                $pairs[$key] = $value;
            }
        }
    }

    return $pairs;
}

/**
 * Parse a TFMS time string (ddHHmm) into a full UTC datetime string.
 *
 * @param string $tfmsTime 6-digit time string (ddHHmm)
 * @param string|null $baseDate Optional base date (YYYY-MM-DD) for month/year context
 * @return string|null UTC datetime string (Y-m-d H:i:s) or null
 */
function parseTfmsTime(string $tfmsTime, ?string $baseDate = null): ?string {
    if (strlen($tfmsTime) !== 6) return null;

    $dd = substr($tfmsTime, 0, 2);
    $HH = substr($tfmsTime, 2, 2);
    $mm = substr($tfmsTime, 4, 2);

    if ($baseDate) {
        // Use month/year from the base date, but day from tfmsTime
        $baseParts = explode('-', $baseDate);
        $year = $baseParts[0];
        $month = $baseParts[1];
    } else {
        // Fall back to current month/year
        $year = gmdate('Y');
        $month = gmdate('m');
    }

    $dateStr = sprintf('%s-%s-%s %s:%s:00', $year, $month, $dd, $HH, $mm);

    // Validate the date
    $ts = strtotime($dateStr . ' UTC');
    if ($ts === false) return null;

    return gmdate('Y-m-d H:i:s', $ts);
}


// =============================================================================
// Deduplication
// =============================================================================

/**
 * Check for duplicate entries.
 * @return string|null Description of duplicate if found, null if not
 */
function checkDuplicate(PDO $conn, string $type, array $data): ?string {
    // For programs (GS, GDP, AFP, CTOP)
    if (in_array($type, ['GS', 'GDP', 'AFP', 'CTOP'])) {
        if ($data['ctl_element'] && $data['start_utc']) {
            $programType = mapProgramType($type);
            $sql = "SELECT program_id FROM dbo.tmi_programs
                    WHERE ctl_element = ? AND program_type = ? AND start_utc = ?";
            $stmt = $conn->prepare($sql);
            $stmt->execute([$data['ctl_element'], $programType, $data['start_utc']]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                return "program_id={$row['program_id']}";
            }
        }
    }

    // For advisories
    if ($data['advisory_number'] && $data['start_utc']) {
        $date = substr($data['start_utc'], 0, 10); // YYYY-MM-DD
        $sql = "SELECT advisory_id FROM dbo.tmi_advisories
                WHERE advisory_number = ? AND CAST(created_at AS DATE) = ?";
        $stmt = $conn->prepare($sql);
        $stmt->execute([$data['advisory_number'], $date]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            return "advisory_id={$row['advisory_id']}";
        }
    }

    return null;
}


// =============================================================================
// Insert Functions
// =============================================================================

/**
 * Route entry to the correct insert function based on type.
 * @return array Inserted IDs
 */
function insertEntry(PDO $conn, string $type, array $data): array {
    global $CREATED_BY, $CREATED_BY_NAME;
    $ids = [];

    switch ($type) {
        case 'GS':
        case 'GDP':
        case 'AFP':
        case 'CTOP':
            $programId = insertProgram($conn, $type, $data);
            $ids['program_id'] = $programId;

            // Also create linked advisory
            $advisoryId = insertAdvisory($conn, $type, $data, $programId);
            $ids['advisory_id'] = $advisoryId;
            break;

        case 'MIT':
        case 'MINIT':
            $entryId = insertNtmlEntry($conn, $type, $data);
            $ids['entry_id'] = $entryId;

            // Also create advisory if it has an ADVZY number
            if ($data['advisory_number']) {
                $advisoryId = insertAdvisory($conn, $type, $data, null);
                $ids['advisory_id'] = $advisoryId;
            }
            break;

        case 'REROUTE':
            $advisoryId = insertAdvisory($conn, $type, $data, null);
            $ids['advisory_id'] = $advisoryId;

            // Create reroute record if we have route details
            if ($data['route_string'] || $data['route_name']) {
                $rerouteId = insertReroute($conn, $data, $advisoryId);
                $ids['reroute_id'] = $rerouteId;
            }
            break;

        case 'ATCSCC':
        case 'CNX':
            $advisoryId = insertAdvisory($conn, $type, $data, null);
            $ids['advisory_id'] = $advisoryId;
            break;

        case 'NTML':
            $entryId = insertNtmlEntry($conn, 'MISC', $data);
            $ids['entry_id'] = $entryId;
            break;

        // NTML compact format types
        case 'STOP':
            $entryId = insertNtmlEntry($conn, 'CONTINGENCY', $data);
            $ids['entry_id'] = $entryId;
            break;

        case 'CONFIG':
            $entryId = insertNtmlEntry($conn, 'CONFIG', $data);
            $ids['entry_id'] = $entryId;
            break;

        case 'DD':
        case 'ED':
        case 'AD':
            $entryId = insertNtmlEntry($conn, 'DELAY', $data);
            $ids['entry_id'] = $entryId;
            break;

        case 'CFR':
        case 'APREQ':
            $entryId = insertNtmlEntry($conn, 'APREQ', $data);
            $ids['entry_id'] = $entryId;
            break;

        case 'TBM':
        case 'CANCEL':
        case 'PLANNING':
            $entryId = insertNtmlEntry($conn, 'MISC', $data);
            $ids['entry_id'] = $entryId;
            break;
    }

    return $ids;
}

/**
 * Map detected type to tmi_programs.program_type value.
 */
function mapProgramType(string $type): string {
    return match ($type) {
        'GS' => 'GS',
        'GDP' => 'GDP-DAS',
        'AFP' => 'AFP-DAS',
        'CTOP' => 'CTOP',
        default => $type,
    };
}

/**
 * Map detected type to tmi_advisories.advisory_type value.
 */
function mapAdvisoryType(string $type): string {
    return match ($type) {
        'GS' => 'GS',
        'GDP' => 'GDP',
        'AFP' => 'AFP',
        'CTOP' => 'CTOP',
        'REROUTE' => 'REROUTE',
        'MIT' => 'MIT',
        'MINIT' => 'MIT',
        'ATCSCC' => 'ATCSCC',
        'CNX' => 'INFORMATIONAL',
        default => 'INFORMATIONAL',
    };
}

/**
 * Insert a program record into tmi_programs.
 * @return int Inserted program_id
 */
function insertProgram(PDO $conn, string $type, array $data): int {
    global $CREATED_BY, $CREATED_BY_NAME;

    $programType = mapProgramType($type);
    $scopeJson = null;
    if (!empty($data['scope_centers'])) {
        $scopeJson = json_encode(['centers' => $data['scope_centers']]);
    }
    $ratesJson = null;
    if (!empty($data['rates_hourly'])) {
        $ratesJson = json_encode($data['rates_hourly']);
    }

    $sql = "INSERT INTO dbo.tmi_programs (
                ctl_element, element_type, program_type, adv_number,
                start_utc, end_utc,
                status, is_proposed, is_active,
                program_rate, delay_limit_min,
                rates_hourly_json, scope_json,
                impacting_condition, cause_text, comments,
                source_type,
                created_by, created_at, updated_at
            ) VALUES (
                :ctl_element, :element_type, :program_type, :adv_number,
                :start_utc, :end_utc,
                'COMPLETED', 0, 0,
                :program_rate, :delay_limit_min,
                :rates_hourly_json, :scope_json,
                :impacting_condition, :cause_text, :comments,
                'IMPORT',
                :created_by, :created_at, :updated_at
            );
            SELECT SCOPE_IDENTITY() AS id";

    $stmt = $conn->prepare($sql);

    $createdAt = $data['start_utc'] ?? $data['_entry_timestamp'] ?? gmdate('Y-m-d H:i:s');

    $stmt->execute([
        ':ctl_element' => $data['ctl_element'] ?? 'UNKN',
        ':element_type' => $data['element_type'] ?? perti_detect_element_type($data['ctl_element'] ?? '') ?? 'APT',
        ':program_type' => $programType,
        ':adv_number' => $data['advisory_number'] ?? null,
        ':start_utc' => $data['start_utc'],
        ':end_utc' => $data['end_utc'] ?? $data['start_utc'],
        ':program_rate' => $data['program_rate'],
        ':delay_limit_min' => $data['delay_limit_min'],
        ':rates_hourly_json' => $ratesJson,
        ':scope_json' => $scopeJson,
        ':impacting_condition' => $data['impacting_condition'],
        ':cause_text' => $data['cause_text'],
        ':comments' => $data['comments'],
        ':created_by' => $CREATED_BY,
        ':created_at' => $createdAt,
        ':updated_at' => $createdAt,
    ]);
    $stmt->nextRowset();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return (int) ($row['id'] ?? 0);
}

/**
 * Insert an advisory record into tmi_advisories.
 * @return int Inserted advisory_id
 */
function insertAdvisory(PDO $conn, string $type, array $data, ?int $programId): int {
    global $CREATED_BY, $CREATED_BY_NAME;

    $advisoryType = mapAdvisoryType($type);
    $subject = buildSubject($type, $data);

    $sql = "INSERT INTO dbo.tmi_advisories (
                advisory_number, advisory_type,
                ctl_element, element_type, scope_facilities,
                program_id, program_rate, delay_cap,
                effective_from, effective_until,
                subject, body_text,
                reason_code,
                reroute_name, reroute_area, reroute_string, reroute_from, reroute_to,
                mit_miles, mit_type, mit_fix,
                status, is_proposed,
                source_type,
                created_by, created_by_name,
                created_at, updated_at
            ) VALUES (
                :advisory_number, :advisory_type,
                :ctl_element, :element_type, :scope_facilities,
                :program_id, :program_rate, :delay_cap,
                :effective_from, :effective_until,
                :subject, :body_text,
                :reason_code,
                :reroute_name, :reroute_area, :reroute_string, :reroute_from, :reroute_to,
                :mit_miles, :mit_type, :mit_fix,
                'EXPIRED', 0,
                'IMPORT',
                :created_by, :created_by_name,
                :created_at, :updated_at
            );
            SELECT SCOPE_IDENTITY() AS id";

    $stmt = $conn->prepare($sql);

    $createdAt = $data['start_utc'] ?? $data['_entry_timestamp'] ?? gmdate('Y-m-d H:i:s');
    $scopeFacilities = null;
    if ($data['scope_centers']) {
        $scopeFacilities = implode(' ', $data['scope_centers']);
    } elseif ($data['facilities']) {
        $scopeFacilities = $data['facilities'];
    }

    $mitType = null;
    if ($type === 'MIT') $mitType = 'MIT';
    if ($type === 'MINIT') $mitType = 'MINIT';

    $stmt->execute([
        ':advisory_number' => $data['advisory_number'] ?? 'IMPORT',
        ':advisory_type' => $advisoryType,
        ':ctl_element' => $data['ctl_element'],
        ':element_type' => $data['element_type'] ?? perti_detect_element_type($data['ctl_element'] ?? ''),
        ':scope_facilities' => $scopeFacilities,
        ':program_id' => $programId,
        ':program_rate' => $data['program_rate'],
        ':delay_cap' => $data['delay_limit_min'],
        ':effective_from' => $data['start_utc'],
        ':effective_until' => $data['end_utc'],
        ':subject' => $subject,
        ':body_text' => $data['_raw'] ?? $data['body_text'] ?? '',
        ':reason_code' => $data['impacting_condition'],
        ':reroute_name' => ($type === 'REROUTE') ? $data['route_name'] : null,
        ':reroute_area' => ($type === 'REROUTE') ? $data['constrained_area'] : null,
        ':reroute_string' => ($type === 'REROUTE') ? $data['route_string'] : null,
        ':reroute_from' => ($type === 'REROUTE') ? $data['traffic_from'] : null,
        ':reroute_to' => ($type === 'REROUTE') ? $data['traffic_to'] : null,
        ':mit_miles' => $data['restriction_value'],
        ':mit_type' => $mitType,
        ':mit_fix' => $data['mit_fix'],
        ':created_by' => $CREATED_BY,
        ':created_by_name' => $CREATED_BY_NAME,
        ':created_at' => $createdAt,
        ':updated_at' => $createdAt,
    ]);
    $stmt->nextRowset();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return (int) ($row['id'] ?? 0);
}

/**
 * Insert an NTML entry into tmi_entries.
 * @return int Inserted entry_id
 */
function insertNtmlEntry(PDO $conn, string $entryType, array $data): int {
    global $CREATED_BY, $CREATED_BY_NAME;

    $determinantCode = strtoupper($entryType);

    $sql = "INSERT INTO dbo.tmi_entries (
                determinant_code, protocol_type, entry_type,
                ctl_element, element_type,
                requesting_facility, providing_facility,
                restriction_value, restriction_unit, condition_text,
                reason_code,
                valid_from, valid_until,
                status, source_type,
                raw_input, parsed_data,
                created_by, created_by_name,
                created_at, updated_at
            ) VALUES (
                :determinant_code, 1, :entry_type,
                :ctl_element, :element_type,
                :requesting_facility, :providing_facility,
                :restriction_value, :restriction_unit, :condition_text,
                :reason_code,
                :valid_from, :valid_until,
                'EXPIRED', 'IMPORT',
                :raw_input, :parsed_data,
                :created_by, :created_by_name,
                :created_at, :updated_at
            );
            SELECT SCOPE_IDENTITY() AS id";

    $stmt = $conn->prepare($sql);

    // Use _entry_timestamp as fallback for entries without explicit time ranges
    $validFrom = $data['start_utc'] ?? $data['_entry_timestamp'] ?? null;
    $createdAt = $data['start_utc'] ?? $data['_entry_timestamp'] ?? gmdate('Y-m-d H:i:s');

    $stmt->execute([
        ':determinant_code' => $determinantCode,
        ':entry_type' => $determinantCode,
        ':ctl_element' => $data['ctl_element'],
        ':element_type' => $data['element_type'] ?? perti_detect_element_type($data['ctl_element'] ?? ''),
        ':requesting_facility' => $data['requesting_facility'] ?? null,
        ':providing_facility' => $data['providing_facility'] ?? null,
        ':restriction_value' => $data['restriction_value'],
        ':restriction_unit' => $data['restriction_unit'],
        ':condition_text' => $data['mit_fix'],
        ':reason_code' => $data['impacting_condition'],
        ':valid_from' => $validFrom,
        ':valid_until' => $data['end_utc'],
        ':raw_input' => $data['_raw'] ?? '',
        ':parsed_data' => json_encode($data, JSON_UNESCAPED_SLASHES),
        ':created_by' => $CREATED_BY,
        ':created_by_name' => $CREATED_BY_NAME,
        ':created_at' => $createdAt,
        ':updated_at' => $createdAt,
    ]);
    $stmt->nextRowset();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return (int) ($row['id'] ?? 0);
}

/**
 * Insert a reroute record into tmi_reroutes.
 * @return int Inserted reroute_id
 */
function insertReroute(PDO $conn, array $data, ?int $advisoryId): int {
    global $CREATED_BY;

    $sql = "INSERT INTO dbo.tmi_reroutes (
                status, name, adv_number,
                start_utc, end_utc,
                origin_airports, dest_airports,
                comments, impacting_condition,
                source_type,
                created_by, created_at, updated_at
            ) VALUES (
                4, :name, :adv_number,
                :start_utc, :end_utc,
                :origin_airports, :dest_airports,
                :comments, :impacting_condition,
                'IMPORT',
                :created_by, :created_at, :updated_at
            );
            SELECT SCOPE_IDENTITY() AS id";

    $stmt = $conn->prepare($sql);

    $createdAt = $data['start_utc'] ?? $data['_entry_timestamp'] ?? gmdate('Y-m-d H:i:s');
    $name = $data['route_name'] ?? $data['constrained_area'] ?? 'Imported Route';

    $stmt->execute([
        ':name' => $name,
        ':adv_number' => $data['advisory_number'],
        ':start_utc' => $data['start_utc'],
        ':end_utc' => $data['end_utc'],
        ':origin_airports' => $data['traffic_from'],
        ':dest_airports' => $data['traffic_to'],
        ':comments' => $data['comments'],
        ':impacting_condition' => $data['impacting_condition'],
        ':created_by' => $CREATED_BY,
        ':created_at' => $createdAt,
        ':updated_at' => $createdAt,
    ]);
    $stmt->nextRowset();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $rerouteId = (int) ($row['id'] ?? 0);

    // Insert route table entries (ADVZY-parsed multi-route tables)
    if (!empty($data['_routes'])) {
        $routeSql = "INSERT INTO dbo.tmi_reroute_routes (
                        reroute_id, origin, destination, route_string
                     ) VALUES (?, ?, ?, ?)";
        $routeStmt = $conn->prepare($routeSql);
        foreach ($data['_routes'] as $route) {
            $routeStmt->execute([
                $rerouteId,
                $route['orig'] ?? $data['traffic_from'],
                $route['dest'] ?? $data['traffic_to'],
                $route['route'],
            ]);
        }
    } elseif ($data['route_string']) {
        // Single route string (TFMS format)
        $routeSql = "INSERT INTO dbo.tmi_reroute_routes (
                        reroute_id, origin, destination, route_string
                     ) VALUES (?, ?, ?, ?)";
        $routeStmt = $conn->prepare($routeSql);
        $routeStmt->execute([
            $rerouteId,
            $data['traffic_from'],
            $data['traffic_to'],
            $data['route_string'],
        ]);
    }

    // Link advisory to reroute
    if ($advisoryId) {
        $linkSql = "UPDATE dbo.tmi_advisories SET reroute_id = ? WHERE advisory_id = ?";
        $linkStmt = $conn->prepare($linkSql);
        $linkStmt->execute([$rerouteId, $advisoryId]);
    }

    return $rerouteId;
}

/**
 * Build a subject line for the advisory.
 */
function buildSubject(string $type, array $data): string {
    $ctl = $data['ctl_element'] ?? '';

    return match ($type) {
        'GS' => "Ground Stop - {$ctl}",
        'GDP' => "Ground Delay Program - {$ctl}",
        'AFP' => "Airspace Flow Program - " . ($data['fca_id'] ?? $ctl),
        'CTOP' => "CTOP - " . ($data['ctop_name'] ?? $ctl),
        'REROUTE' => "Reroute - " . ($data['route_name'] ?? $data['constrained_area'] ?? $ctl),
        'MIT' => ($data['restriction_value'] ?? '') . " MIT - " . ($data['mit_fix'] ?? $ctl),
        'MINIT' => ($data['restriction_value'] ?? '') . " MINIT - " . ($data['mit_fix'] ?? $ctl),
        'ATCSCC' => $data['subject'] ?? "General Message",
        'CNX' => "Cancellation - " . ($data['cancel_ref_type'] ?? '') . " " . ($data['cancel_ref_number'] ?? ''),
        default => $data['subject'] ?? "Imported Entry",
    };
}
