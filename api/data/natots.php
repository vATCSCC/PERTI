<?php
/**
 * NATOTs Advisory Parser API
 *
 * Fetches and parses FAA NATOTs (North Atlantic Organized Track System) advisories
 * from fly.faa.gov. Returns structured JSON with departure routes grouped by facility.
 *
 * GET /api/data/natots.php?date=03202026&advn=45
 * GET /api/data/natots.php?url=https://www.fly.faa.gov/adv/adv_otherdis?adv_date=03202026&advn=45
 *
 * @version 1.0.0
 */

include("../../load/config.php");
define('PERTI_MYSQL_ONLY', true);
include("../../load/connect.php");

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('Access-Control-Allow-Methods: GET, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
    exit;
}

// Build the FAA URL from parameters
$url = null;
$cache_key = null;

if (!empty($_GET['url'])) {
    $url = trim($_GET['url']);
    // Extract date and advn from URL for cache key
    if (preg_match('/adv_date=(\d{8})/', $url, $dm) && preg_match('/advn=(\d+)/', $url, $am)) {
        $cache_key = 'natots_' . $dm[1] . '_' . $am[1];
    }
} elseif (!empty($_GET['date']) && !empty($_GET['advn'])) {
    $date = preg_replace('/[^0-9]/', '', $_GET['date']);
    $advn = (int)$_GET['advn'];
    $url = 'https://www.fly.faa.gov/adv/adv_otherdis?adv_date=' . $date . '&advn=' . $advn;
    $cache_key = 'natots_' . $date . '_' . $advn;
} else {
    http_response_code(400);
    echo json_encode([
        'status' => 'error',
        'message' => 'Required: ?date=MMDDYYYY&advn=NN or ?url=...'
    ]);
    exit;
}

// Validate URL domain
if (!preg_match('#^https?://(www\.)?fly\.faa\.gov/#i', $url)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'URL must be from fly.faa.gov']);
    exit;
}

// Check MySQL cache (30-min TTL)
if ($cache_key) {
    try {
        $stmt = $conn_pdo->prepare(
            "SELECT cache_data, fetched_at FROM nat_track_cache WHERE cache_key = ? AND fetched_at > DATE_SUB(NOW(), INTERVAL 30 MINUTE) LIMIT 1"
        );
        $stmt->execute([$cache_key]);
        $cached = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($cached) {
            $data = json_decode($cached['cache_data'], true);
            if ($data !== null) {
                echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
                exit;
            }
        }
    } catch (Exception $e) {
        // Cache miss — proceed to fetch
    }
}

// Fetch advisory HTML from FAA
$ctx = stream_context_create([
    'http' => [
        'timeout' => 15,
        'header'  => "Accept: text/html\r\nUser-Agent: PERTI/2.0 (perti.vatcscc.org)\r\n",
    ],
    'ssl' => ['verify_peer' => true],
]);

$html = @file_get_contents($url, false, $ctx);
if ($html === false) {
    http_response_code(502);
    echo json_encode(['status' => 'error', 'message' => 'Failed to fetch advisory from FAA']);
    exit;
}

// Extract advisory header (ATCSCC ADVZY 045 DCC/ZBW 03/20/2026 NATOTS_RQD)
$advzy_number = null;
$advzy_date = null;
$advzy_facilities = null;
$advzy_type = null;
if (preg_match('/ATCSCC\s+ADVZY\s+(\d+)\s+([A-Z\/]+)\s+(\d{2}\/\d{2}\/\d{4})\s+(\S+)/i', $html, $hm)) {
    $advzy_number = (int)$hm[1];
    $advzy_facilities = $hm[2];
    $advzy_date = $hm[3];
    $advzy_type = $hm[4];
}

// Extract PRE block
if (!preg_match('/<PRE>(.*?)<\/PRE>/si', $html, $pre_match)) {
    http_response_code(422);
    echo json_encode(['status' => 'error', 'message' => 'No PRE block found in advisory']);
    exit;
}

$pre_text = $pre_match[1];

// Parse the PRE content
$result = parseNATOTs($pre_text, $advzy_number, $advzy_date, $advzy_facilities, $advzy_type);

// Build response
$response = [
    'status' => 'success',
    'advisory' => $result,
    'source_url' => $url,
];

// Cache the response
if ($cache_key) {
    try {
        $json = json_encode($response, JSON_UNESCAPED_UNICODE);
        $stmt = $conn_pdo->prepare(
            "INSERT INTO nat_track_cache (cache_key, cache_data, fetched_at)
             VALUES (?, ?, NOW())
             ON DUPLICATE KEY UPDATE cache_data = VALUES(cache_data), fetched_at = NOW()"
        );
        $stmt->execute([$cache_key, $json]);
    } catch (Exception $e) {
        // Cache write failed — non-fatal
    }
}

echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

// ============================================================================
// Parser
// ============================================================================

/**
 * Parse NATOTs advisory PRE block into structured data.
 */
function parseNATOTs($text, $advzy_number, $advzy_date, $advzy_facilities, $advzy_type) {
    $lines = preg_split('/\r?\n/', $text);

    // Extract metadata from first few lines
    $event_time = null;
    $constrained_facilities = null;
    $preamble_lines = [];
    $in_preamble = true;
    $section_chunks = [];
    $current_chunk = [];
    $past_first_delimiter = false;

    foreach ($lines as $line) {
        $trimmed = trim($line);

        // Parse EVENT TIME
        if ($event_time === null && preg_match('/^EVENT\s+TIME:\s*(.+)/i', $trimmed, $m)) {
            $event_time = trim($m[1]);
            continue;
        }

        // Parse CONSTRAINED FACILITIES
        if ($constrained_facilities === null && preg_match('/^CONSTRAINED\s+FACILITIES:\s*(.+)/i', $trimmed, $m)) {
            $constrained_facilities = trim($m[1]);
            continue;
        }

        // Detect section delimiters (lines of dashes)
        if (preg_match('/^-{10,}/', $trimmed)) {
            if ($past_first_delimiter && !empty($current_chunk)) {
                $section_chunks[] = $current_chunk;
            }
            $current_chunk = [];
            $past_first_delimiter = true;
            $in_preamble = false;
            continue;
        }

        if ($in_preamble) {
            // Skip metadata-only lines already parsed
            if (preg_match('/^(NATOTS|NORTH ATLANTIC ADVISORY FOR)/i', $trimmed)) {
                continue;
            }
            if ($trimmed !== '' && $event_time !== null) {
                $preamble_lines[] = $trimmed;
            }
        } else {
            $current_chunk[] = $line;
        }
    }
    // Capture last chunk if any
    if ($past_first_delimiter && !empty($current_chunk)) {
        $section_chunks[] = $current_chunk;
    }

    // Parse each section chunk
    $sections = [];
    foreach ($section_chunks as $chunk) {
        $section = parseSection($chunk);
        if ($section !== null) {
            $sections[] = $section;
        }
    }

    // Build preamble (join and clean trailing whitespace)
    $preamble = '';
    if (!empty($preamble_lines)) {
        $preamble = implode("\n", $preamble_lines);
        $preamble = preg_replace('/\t+/', '', $preamble);
        $preamble = trim($preamble);
    }

    return [
        'number' => $advzy_number,
        'date' => $advzy_date,
        'type' => $advzy_type,
        'event_time' => $event_time,
        'constrained_facilities' => $constrained_facilities,
        'preamble' => $preamble,
        'sections' => $sections,
        'track_count' => array_sum(array_map(function($s) { return count($s['tracks']); }, $sections)),
        'section_count' => count($sections),
    ];
}

/**
 * Parse a single section chunk (between delimiter lines).
 */
function parseSection($lines) {
    $facility = null;
    $label = null;
    $tracks = [];
    $notes = [];

    foreach ($lines as $line) {
        $trimmed = trim($line);
        if ($trimmed === '') continue;

        // Parse TRACK lines: TRACK\t{LETTER}\t{ROUTE}
        if (preg_match('/^TRACK\s+([A-Z])\s+(.+)/i', $trimmed, $m)) {
            $letter = strtoupper($m[1]);
            $raw_route = trim($m[2]);
            // Clean trailing tabs/whitespace
            $raw_route = rtrim($raw_route, "\t ");

            $tracks[] = [
                'letter' => $letter,
                'raw_route' => $raw_route,
                'converted_route' => convertFAARoute($raw_route),
            ];
            continue;
        }

        // First non-empty, non-TRACK line is the section header/label
        if ($label === null) {
            $label = preg_replace('/\t+/', '', $trimmed);

            // Extract facility name from the label
            $facility = extractFacility($label);
            continue;
        }

        // Any remaining non-TRACK lines are notes
        $note = preg_replace('/\t+/', '', $trimmed);
        if ($note !== '') {
            $notes[] = $note;
        }
    }

    if ($facility === null && empty($tracks)) {
        return null;
    }

    return [
        'facility' => $facility,
        'label' => $label,
        'tracks' => $tracks,
        'notes' => !empty($notes) ? implode(' ', $notes) : null,
    ];
}

/**
 * Extract facility identifier from section header.
 * e.g., "BOS NORTH ATLANTIC DEPARTURES..." -> "BOS"
 *        "DC METRO NORTH ATLANTIC..." -> "DC METRO"
 *        "OVERFLIGHTS FROM ZFW/ZHU/ZME/ZKC..." -> "ZFW/ZHU/ZME/ZKC"
 *        "OVERFLIGHTS FROM ALL OTHER FACILITIES..." -> "OTHER"
 */
function extractFacility($label) {
    $upper = strtoupper(trim($label));

    // Overflight sections
    if (preg_match('/^OVERFLIGHTS?\s+FROM\s+ALL\s+OTHER/i', $upper)) {
        return 'OTHER';
    }
    if (preg_match('/^OVERFLIGHTS?\s+FROM\s+([\w\/]+)/i', $upper, $m)) {
        return $m[1];
    }

    // Departure sections: "BOS NORTH ATLANTIC..." or "DC METRO NORTH ATLANTIC..."
    if (preg_match('/^(.+?)\s+NORTH\s+ATLANTIC/i', $upper, $m)) {
        return trim($m[1]);
    }

    // Fallback: first word(s) before "MUST" or "DEPARTURES"
    if (preg_match('/^(.+?)\s+(?:MUST|DEPARTURES)/i', $upper, $m)) {
        return trim($m[1]);
    }

    // Last resort: first word
    $parts = preg_split('/\s+/', $upper);
    return $parts[0] ?? 'UNKNOWN';
}

/**
 * Convert FAA NATOTs route notation to PERTI route plotter format.
 *
 * FAA uses:
 *   ..  = direct (waypoint to waypoint)
 *   .   = procedure/NAR connection (SID.transition, NAR.fix, Q-route segments)
 *
 * Conversion: Replace all dots with spaces to get individual waypoint/fix tokens.
 * The route plotter resolves known fixes to coordinates and skips unknowns gracefully.
 *
 * Example:
 *   FAA:   BOS.LBSTA8.LBSTA..ALLEX.N503B.ALLRY.NATU
 *   PERTI: BOS LBSTA8 LBSTA ALLEX N503B ALLRY NATU
 */
function convertFAARoute($raw) {
    // Replace all dots (both .. and .) with spaces
    $converted = str_replace('.', ' ', $raw);
    // Collapse multiple spaces
    $converted = preg_replace('/\s+/', ' ', trim($converted));
    return $converted;
}
