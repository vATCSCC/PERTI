<?php
/**
 * FAA Reroute/FCA Advisory Fetcher API
 *
 * Fetches FAA ATCSCC route advisories (ROUTE RQD/RMD, FCA RQD) from fly.faa.gov,
 * extracts header metadata, and returns the raw advisory text for client-side
 * route parsing via RouteAdvisoryParser.
 *
 * GET /api/data/reroute_advisory.php?date=03212026&advn=45
 * GET /api/data/reroute_advisory.php?url=https://www.fly.faa.gov/adv/adv_otherdis?adv_date=03212026&advn=45
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
    if (preg_match('/adv_date=(\d{8})/', $url, $dm) && preg_match('/advn=(\d+)/', $url, $am)) {
        $cache_key = 'reroute_' . $dm[1] . '_' . $am[1];
    }
} elseif (!empty($_GET['date']) && !empty($_GET['advn'])) {
    $date = preg_replace('/[^0-9]/', '', $_GET['date']);
    $advn = (int)$_GET['advn'];
    $url = 'https://www.fly.faa.gov/adv/adv_otherdis?adv_date=' . $date . '&advn=' . $advn;
    $cache_key = 'reroute_' . $date . '_' . $advn;
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

// Check MySQL cache (30-min TTL) — reuses nat_track_cache table
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

// Extract advisory header from the page
// Header appears outside PRE block as HTML text with &nbsp; entities:
//   ATCSCC&nbsp;ADVZY&nbsp;045&nbsp;DCC&nbsp;03/21/2026&nbsp;FCA&nbsp;RQD
$advzy_number = null;
$advzy_date = null;
$advzy_facilities = null;
$advzy_type = null;
$header_text = str_replace(['&nbsp;', '&#160;'], ' ', $html);
if (preg_match('/ATCSCC\s+ADVZY\s+(\d+)\s+([A-Z\/]+)\s+(\d{2}\/\d{2}\/(?:\d{4}|\d{2}))\s+([^\n<]+)/i', $header_text, $hm)) {
    $advzy_number = (int)$hm[1];
    $advzy_facilities = $hm[2];
    $advzy_date = $hm[3];
    $advzy_type = trim($hm[4]);
}

// Extract PRE block
if (!preg_match('/<PRE>(.*?)<\/PRE>/si', $html, $pre_match)) {
    http_response_code(422);
    echo json_encode(['status' => 'error', 'message' => 'No PRE block found in advisory']);
    exit;
}

// Decode HTML entities inside PRE block (> and < may be encoded as &gt; &lt;)
$pre_text = html_entity_decode($pre_match[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
$pre_text = strip_tags($pre_text);

// Parse metadata fields from the PRE text
$metadata = parseRerouteMetadata($pre_text);

// Build response
$response = [
    'status' => 'success',
    'advisory' => [
        'number' => $advzy_number,
        'date' => $advzy_date,
        'type' => $advzy_type,
        'facilities' => $advzy_facilities,
        'name' => $metadata['name'] ?? null,
        'constrained_area' => $metadata['constrained_area'] ?? null,
        'reason' => $metadata['reason'] ?? null,
        'include_traffic' => $metadata['include_traffic'] ?? null,
        'facilities_included' => $metadata['facilities_included'] ?? null,
        'flight_status' => $metadata['flight_status'] ?? null,
        'valid' => $metadata['valid'] ?? null,
        'extension_probability' => $metadata['extension_probability'] ?? null,
        'remarks' => $metadata['remarks'] ?? null,
        'modifications' => $metadata['modifications'] ?? null,
        'tmi_id' => $metadata['tmi_id'] ?? null,
        'routes_text' => $pre_text,
    ],
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
// Metadata Parser
// ============================================================================

/**
 * Parse key-value metadata fields from the advisory PRE text.
 * Handles continuation lines (indented lines append to the previous key's value).
 */
function parseRerouteMetadata($text) {
    $lines = preg_split('/\r?\n/', $text);
    $fields = [];
    $current_key = null;

    $known_keys = [
        'NAME'                     => 'name',
        'CONSTRAINED AREA'         => 'constrained_area',
        'REASON'                   => 'reason',
        'INCLUDE TRAFFIC'          => 'include_traffic',
        'FACILITIES INCLUDED'      => 'facilities_included',
        'FLIGHT STATUS'            => 'flight_status',
        'VALID'                    => 'valid',
        'PROBABILITY OF EXTENSION' => 'extension_probability',
        'REMARKS'                  => 'remarks',
        'ASSOCIATED RESTRICTIONS'  => 'associated_restrictions',
        'MODIFICATIONS'            => 'modifications',
        'TMI ID'                   => 'tmi_id',
        'MESSAGE'                  => 'message',
        'EFFECTIVE TIME'           => 'effective_time',
    ];

    foreach ($lines as $line) {
        $trimmed = trim($line);

        // Stop at ROUTES: header or route table start
        if (preg_match('/^ROUTES:\s*$/i', $trimmed)) break;
        if (preg_match('/^(ORIG\s+DEST|FROM:)/i', $trimmed)) break;

        // Check for known field header
        $matched = false;
        foreach ($known_keys as $label => $key) {
            if (preg_match('/^' . preg_quote($label, '/') . ':\s*(.*)/i', $trimmed, $m)) {
                $current_key = $key;
                $fields[$key] = trim($m[1]);
                $matched = true;
                break;
            }
        }

        // Continuation line (starts with whitespace, no new field matched)
        if (!$matched && $current_key && $trimmed !== '' && preg_match('/^\s/', $line)) {
            $fields[$current_key] .= ' ' . $trimmed;
        }
    }

    // Clean up whitespace in all fields
    foreach ($fields as $k => $v) {
        $fields[$k] = preg_replace('/\s+/', ' ', trim($v));
    }

    return $fields;
}
