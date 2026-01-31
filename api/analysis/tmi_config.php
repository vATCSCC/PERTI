<?php
/**
 * TMI Configuration API
 *
 * Save and load NTML/TMI configuration for PERTI events
 *
 * Endpoints:
 *   GET  ?p_id={plan_id}  - Get saved TMI config for a plan
 *   POST                  - Save TMI config for a plan
 */

header('Content-Type: application/json');

include("../../load/config.php");

$response = [
    'success' => true,
    'data' => null,
    'message' => ''
];

try {
    $data_path = realpath(__DIR__ . '/../../data/tmi_compliance');
    if (!$data_path) {
        // Create directory if it doesn't exist
        $data_path = __DIR__ . '/../../data/tmi_compliance';
        if (!is_dir($data_path)) {
            mkdir($data_path, 0755, true);
        }
        $data_path = realpath($data_path);
    }

    // Handle GET - Load config
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $plan_id = isset($_GET['p_id']) ? intval($_GET['p_id']) : 0;

        if ($plan_id <= 0) {
            throw new Exception("Invalid or missing plan ID");
        }

        $config_path = $data_path . '/tmi_config_' . $plan_id . '.json';

        if (file_exists($config_path)) {
            $config = json_decode(file_get_contents($config_path), true);
            $response['data'] = $config;
            $response['message'] = 'Configuration loaded';
        } else {
            $response['data'] = null;
            $response['message'] = 'No saved configuration found';
        }
    }

    // Handle POST - Save config
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);

        $plan_id = isset($input['p_id']) ? intval($input['p_id']) : 0;

        if ($plan_id <= 0) {
            throw new Exception("Invalid or missing plan ID");
        }

        $config = [
            'plan_id' => $plan_id,
            'destinations' => $input['destinations'] ?? '',
            'event_start' => $input['event_start'] ?? '',
            'event_end' => $input['event_end'] ?? '',
            'ntml_text' => $input['ntml_text'] ?? '',
            'saved_utc' => gmdate('Y-m-d H:i:s')
        ];

        // Parse NTML entries
        $config['parsed_tmis'] = parse_ntml($config['ntml_text']);

        $config_path = $data_path . '/tmi_config_' . $plan_id . '.json';

        if (file_put_contents($config_path, json_encode($config, JSON_PRETTY_PRINT))) {
            $response['data'] = $config;
            $response['message'] = 'Configuration saved';
        } else {
            throw new Exception("Failed to save configuration");
        }
    }

    echo json_encode($response, JSON_PRETTY_PRINT);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}

/**
 * Parse facility pair from line, handling various formats:
 * - Simple: "ZNY:ZDC"
 * - Multiple facilities: "ZNY,N90:ZDC,ZBW"
 * - With (MULTIPLE) suffix: "ZNY:ZDC(MULTIPLE)"
 *
 * Handles ARTCC (ZNY, ZDC), TRACON (N90, A90, C90, PCT, SCT), and Airport codes.
 *
 * @param string $line The line to parse
 * @return array ['requestor' => string, 'provider' => string, 'is_multiple' => bool]
 */
function parseFacilities($line) {
    $result = ['requestor' => '', 'provider' => '', 'is_multiple' => false];

    // Check for and strip (MULTIPLE) suffix
    $cleanLine = $line;
    if (preg_match('/\(MULTIPLE\)\s*$/i', $line)) {
        $result['is_multiple'] = true;
        $cleanLine = preg_replace('/\(MULTIPLE\)\s*$/i', '', $line);
    }

    // Parse facility pair at end of line
    // Pattern matches: FACILITY(,FACILITY)*:FACILITY(,FACILITY)*
    // Handles: ZNY:ZDC, N90:ZNY, ZNY,N90:ZDC,ZBW, KJFK:ZNY
    if (preg_match('/\b([A-Z0-9]+(?:,[A-Z0-9]+)*):([A-Z0-9]+(?:,[A-Z0-9]+)*)\s*$/i', $cleanLine, $matches)) {
        $result['requestor'] = strtoupper($matches[1]);
        $result['provider'] = strtoupper($matches[2]);
    }

    return $result;
}

/**
 * Parse NTML text into structured TMI entries
 */
function parse_ntml($ntml_text) {
    $tmis = [];
    $lines = explode("\n", $ntml_text);

    foreach ($lines as $line) {
        $line = trim($line);
        if (empty($line)) continue;

        $tmi = ['raw' => $line, 'type' => null];

        // MIT pattern: "LAS via FLCHR 20MIT ZLA:ZOA 2359Z-0400Z"
        if (preg_match('/(\w+)\s+via\s+(\w+)\s+(\d+)\s*(MIT|MINIT)/i', $line, $matches)) {
            $tmi['type'] = strtoupper($matches[4]);
            $tmi['dest'] = strtoupper($matches[1]);
            $tmi['fix'] = strtoupper($matches[2]);
            $tmi['value'] = intval($matches[3]);

            // Parse provider:requestor with (MULTIPLE) support
            $facilities = parseFacilities($line);
            $tmi['requestor'] = $facilities['requestor'];
            $tmi['provider'] = $facilities['provider'];
            $tmi['is_multiple'] = $facilities['is_multiple'];

            // Parse time window
            if (preg_match('/(\d{4})Z\s*-\s*(\d{4})Z/', $line, $timeMatches)) {
                $tmi['start_time'] = $timeMatches[1];
                $tmi['end_time'] = $timeMatches[2];
            }
        }
        // Ground Stop pattern: "LAS GS (NCT) 0230Z-0315Z issued 0244Z"
        elseif (preg_match('/(\w+)\s+GS\s*\(([^)]+)\)/i', $line, $matches)) {
            $tmi['type'] = 'GS';
            $tmi['dest'] = strtoupper($matches[1]);
            $tmi['scope'] = trim($matches[2]);

            // Parse time window
            if (preg_match('/(\d{4})Z\s*-\s*(\d{4})Z/', $line, $timeMatches)) {
                $tmi['start_time'] = $timeMatches[1];
                $tmi['end_time'] = $timeMatches[2];
            }

            // Parse issued time
            if (preg_match('/issued\s+(\d{4})Z/i', $line, $issuedMatch)) {
                $tmi['issued_time'] = $issuedMatch[1];
            }
        }
        // APREQ/CFR pattern - enhanced to handle multi-destination, origins, cancellations
        // Formats:
        //   "30/2300 JFK via ALL CFR VOLUME:VOLUME 0000-0400 ZDC:PCT"
        //   "30/2353 JFK, LGA, BOS via CLT Departures CFR VOLUME:VOLUME 0000-0400 ZDC:ZTL"
        //   "31/0332  JFK, LGA, BOS via CLT Departures CFR VOLUME:VOLUME CANCEL RESTR ZDC:ZTL"
        elseif (preg_match('/(APREQ|CFR)/i', $line)) {
            $tmi['type'] = 'APREQ';

            // Check if this is a cancellation
            if (preg_match('/CANCEL\s+RESTR/i', $line)) {
                $tmi['cancelled'] = true;
            }

            // Extract destinations (may be comma-separated before "via")
            // Pattern: "JFK, LGA, BOS via ..." or "JFK via ..."
            if (preg_match('/^\d+\/\d+\s+([A-Z, ]+)\s+via\s+/i', $line, $destMatch)) {
                $dest_str = trim($destMatch[1]);
                // Split by comma and clean up
                $dests = array_map('trim', explode(',', $dest_str));
                $dests = array_filter($dests); // Remove empty entries

                if (count($dests) > 1) {
                    $tmi['destinations'] = $dests;
                    $tmi['dest'] = implode(',', $dests);
                } else {
                    $tmi['dest'] = strtoupper($dests[0] ?? '');
                }
            }

            // Extract fix/origin (after "via")
            // Pattern: "via ALL CFR" or "via CLT Departures CFR"
            if (preg_match('/via\s+([A-Z0-9]+(?:\s+Departures)?)\s+(APREQ|CFR)/i', $line, $fixMatch)) {
                $fix_str = trim($fixMatch[1]);

                // Check if this specifies origin (e.g., "CLT Departures")
                if (preg_match('/([A-Z]{3})\s+Departures/i', $fix_str, $origMatch)) {
                    $tmi['origin'] = strtoupper($origMatch[1]);
                    $tmi['fix'] = 'ALL';  // CFR applies to all fixes from this origin
                } else {
                    $tmi['fix'] = strtoupper($fix_str);
                }
            }

            // Parse requestor:provider (facility pair) with (MULTIPLE) support
            $facilities = parseFacilities($line);
            $tmi['requestor'] = $facilities['requestor'];
            $tmi['provider'] = $facilities['provider'];
            $tmi['is_multiple'] = $facilities['is_multiple'];

            // Parse time window (HHMM-HHMM format, with or without Z)
            if (preg_match('/(\d{4})Z?\s*-\s*(\d{4})Z?/', $line, $timeMatches)) {
                $tmi['start_time'] = $timeMatches[1];
                $tmi['end_time'] = $timeMatches[2];
            }
        }
        // Cancelled pattern - handles both "CXLD 0123Z" and "CANCEL RESTR" formats
        // e.g., "31/0326    BOS via RBV CANCEL RESTR ZNY:ZDC"
        elseif (preg_match('/CANCEL\s+RESTR/i', $line)) {
            $tmi['type'] = 'CANCEL';
            $tmi['cancelled'] = true;

            // Extract what's being cancelled (dest via fix)
            if (preg_match('/^\d+\/\d+\s+([A-Z, ]+)\s+via\s+([A-Z0-9]+)/i', $line, $cxlMatch)) {
                $dest_str = trim($cxlMatch[1]);
                $dests = array_map('trim', explode(',', $dest_str));
                $dests = array_filter($dests);

                if (count($dests) > 1) {
                    $tmi['destinations'] = $dests;
                    $tmi['dest'] = implode(',', $dests);
                } else {
                    $tmi['dest'] = strtoupper($dests[0] ?? '');
                }

                $tmi['fix'] = strtoupper($cxlMatch[2]);
            }

            // Parse requestor:provider for cancellation with (MULTIPLE) support
            $facilities = parseFacilities($line);
            $tmi['requestor'] = $facilities['requestor'];
            $tmi['provider'] = $facilities['provider'];
            $tmi['is_multiple'] = $facilities['is_multiple'];
        }
        // Legacy cancelled pattern: "CXLD 0123Z"
        elseif (preg_match('/CXLD?\s+(\d{4})Z/i', $line, $cxlMatch)) {
            $tmi['type'] = 'CANCEL';
            $tmi['cancelled'] = true;
            $tmi['cancelled_time'] = $cxlMatch[1];
        }

        if ($tmi['type']) {
            $tmis[] = $tmi;
        }
    }

    return $tmis;
}
