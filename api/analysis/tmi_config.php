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

        // Parse all entries - unified parser detects NTML vs ADVZY format
        $config['parsed_tmis'] = parse_tmi_text($config['ntml_text'], $config['event_start']);

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

/**
 * Unified parser for TMI text - auto-detects NTML vs ADVZY format
 *
 * @param string $text Combined NTML and/or ADVZY text
 * @param string $event_start Event start time (for date context)
 * @return array Parsed TMI entries
 */
function parse_tmi_text($text, $event_start = null) {
    $tmis = [];
    $lines = explode("\n", $text);
    $i = 0;

    while ($i < count($lines)) {
        $line = trim($lines[$i]);

        if (empty($line)) {
            $i++;
            continue;
        }

        // Detect ADVZY header: "vATCSCC ADVZY 001 ..." or "vATCSCC ADVZY ADVZY 001 ..."
        if (preg_match('/^vATCSCC\s+ADVZY\b/i', $line)) {
            // Parse ADVZY block
            $advzy_result = parse_advzy_block($lines, $i, $event_start);
            if ($advzy_result['tmi']) {
                $tmis[] = $advzy_result['tmi'];
            }
            $i += $advzy_result['lines_consumed'];
            continue;
        }

        // Otherwise, parse as NTML line
        $tmi = parse_ntml_line($line);
        if ($tmi && $tmi['type']) {
            $tmis[] = $tmi;
        }
        $i++;
    }

    // Build program chains from parsed TMIs
    $gs_programs = build_gs_programs($tmis);
    $reroute_programs = build_reroute_programs($tmis);

    // Return flat TMI list for backward compatibility (Python consumer iterates this)
    // Programs are attached as special entries at the end
    $result = $tmis;
    if (!empty($gs_programs)) {
        foreach ($gs_programs as $prog) {
            $result[] = $prog;
        }
    }
    if (!empty($reroute_programs)) {
        foreach ($reroute_programs as $prog) {
            $result[] = $prog;
        }
    }

    return $result;
}

/**
 * Parse a single NTML line
 */
function parse_ntml_line($line) {
    $line = trim($line);
    if (empty($line)) return null;

    $tmi = ['raw' => $line, 'type' => null];

    // MIT pattern: "LAS via FLCHR 20MIT ZLA:ZOA 2359Z-0400Z"
    if (preg_match('/(\w+)\s+via\s+(\w+)\s+(\d+)\s*(MIT|MINIT)/i', $line, $matches)) {
        $tmi['type'] = strtoupper($matches[4]);
        $tmi['dest'] = strtoupper($matches[1]);
        $tmi['fix'] = strtoupper($matches[2]);
        $tmi['value'] = intval($matches[3]);

        $facilities = parseFacilities($line);
        $tmi['requestor'] = $facilities['requestor'];
        $tmi['provider'] = $facilities['provider'];
        $tmi['is_multiple'] = $facilities['is_multiple'];

        if (preg_match('/(\d{4})Z?\s*-\s*(\d{4})Z?/', $line, $timeMatches)) {
            $tmi['start_time'] = $timeMatches[1];
            $tmi['end_time'] = $timeMatches[2];
        }
    }
    // APREQ/CFR pattern
    elseif (preg_match('/(APREQ|CFR)/i', $line) && !preg_match('/CANCEL\s+RESTR/i', $line)) {
        $tmi['type'] = 'APREQ';

        if (preg_match('/^\d+\/\d+\s+([A-Z, ]+)\s+via\s+/i', $line, $destMatch)) {
            $dest_str = trim($destMatch[1]);
            $dests = array_map('trim', explode(',', $dest_str));
            $dests = array_filter($dests);

            if (count($dests) > 1) {
                $tmi['destinations'] = $dests;
                $tmi['dest'] = implode(',', $dests);
            } else {
                $tmi['dest'] = strtoupper($dests[0] ?? '');
            }
        }

        if (preg_match('/via\s+([A-Z0-9]+(?:\s+Departures)?)\s+(APREQ|CFR)/i', $line, $fixMatch)) {
            $fix_str = trim($fixMatch[1]);
            if (preg_match('/([A-Z]{3})\s+Departures/i', $fix_str, $origMatch)) {
                $tmi['origin'] = strtoupper($origMatch[1]);
                $tmi['fix'] = 'ALL';
            } else {
                $tmi['fix'] = strtoupper($fix_str);
            }
        }

        $facilities = parseFacilities($line);
        $tmi['requestor'] = $facilities['requestor'];
        $tmi['provider'] = $facilities['provider'];
        $tmi['is_multiple'] = $facilities['is_multiple'];

        if (preg_match('/(\d{4})Z?\s*-\s*(\d{4})Z?/', $line, $timeMatches)) {
            $tmi['start_time'] = $timeMatches[1];
            $tmi['end_time'] = $timeMatches[2];
        }
    }
    // CANCEL pattern
    elseif (preg_match('/CANCEL\s+RESTR/i', $line)) {
        $tmi['type'] = 'CANCEL';
        $tmi['cancelled'] = true;

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

        $facilities = parseFacilities($line);
        $tmi['requestor'] = $facilities['requestor'];
        $tmi['provider'] = $facilities['provider'];
        $tmi['is_multiple'] = $facilities['is_multiple'];
    }

    return $tmi['type'] ? $tmi : null;
}

/**
 * Parse an ADVZY block (Ground Stop, GDP, etc.)
 *
 * Format:
 *   vATCSCC ADVZY 001 LAS/ZLA 01/18/2026 CDM GROUND STOP
 *   CTL ELEMENT: LAS
 *   ELEMENT TYPE: APT
 *   ADL TIME: 0244Z
 *   GROUND STOP PERIOD: 18/0230Z – 18/0315Z
 *   DEP FACILITIES INCLUDED: (Manual) ZOA
 *
 * @param array $lines All lines
 * @param int $start_idx Starting index of ADVZY header
 * @param string $event_start Event start for date context
 * @return array ['tmi' => TMI or null, 'lines_consumed' => int]
 */
function parse_advzy_block($lines, $start_idx, $event_start = null) {
    $header = trim($lines[$start_idx]);
    $lines_consumed = 1;

    // Detect ADVZY type from header
    $advzy_type = null;
    $is_mandatory = false;
    $route_type = null;
    $action = null;
    $advzy_number = null;

    // Extract advisory number
    if (preg_match('/ADVZY\s+(\d+)/i', $header, $advMatch)) {
        $advzy_number = $advMatch[1];
    }

    if (stripos($header, 'CDM GS CNX') !== false || stripos($header, 'GS CNX') !== false) {
        $advzy_type = 'GS_CNX';
    } elseif (stripos($header, 'GROUND STOP') !== false || stripos($header, 'CDM GROUND STOP') !== false) {
        $advzy_type = 'GS';
    } elseif (stripos($header, 'REROUTE CANCELLATION') !== false) {
        $advzy_type = 'REROUTE_CNX';
    } elseif (stripos($header, 'GDP') !== false || stripos($header, 'GROUND DELAY') !== false) {
        $advzy_type = 'GDP';
    } elseif (preg_match('/\b(ROUTE|FEA|FCA|ICR)\s+(RQD|RMD|PLN|FYI)\b/i', $header, $typeMatch)) {
        $advzy_type = 'REROUTE';
        $route_type = strtoupper($typeMatch[1]);
        $action = strtoupper($typeMatch[2]);
        $is_mandatory = ($action === 'RQD');
    }

    if (!$advzy_type) {
        // Unknown ADVZY type - skip this block
        return ['tmi' => null, 'lines_consumed' => 1];
    }

    // Handle GS_CNX (Ground Stop Cancellation)
    if ($advzy_type === 'GS_CNX') {
        $tmi = [
            'raw' => $header,
            'type' => 'GS_CNX',
            'advzy_number' => $advzy_number,
            'dest' => null,
            'issued_time' => null,
            'cnx_period_start' => null,
            'cnx_period_end' => null,
            'comments' => ''
        ];

        // Extract airport from header
        if (preg_match('/ADVZY\s+\d+\s+([A-Z]{3})[\s\/]/i', $header, $m)) {
            $tmi['dest'] = strtoupper($m[1]);
        }

        for ($i = $start_idx + 1; $i < min($start_idx + 20, count($lines)); $i++) {
            $line = trim($lines[$i]);
            if (empty($line)) { $lines_consumed++; continue; }
            if (preg_match('/^vATCSCC\s+ADVZY/i', $line) || preg_match('/^\d{2}\/\d{4}\s+\w+\s+via\s+/i', $line)) break;
            $lines_consumed++;

            if (preg_match('/^CTL\s+ELEMENT:\s*(\w+)/i', $line, $m)) { $tmi['dest'] = strtoupper($m[1]); continue; }
            if (preg_match('/^ADL\s+TIME:\s*(\d{4})Z?/i', $line, $m)) { $tmi['issued_time'] = $m[1]; continue; }
            if (preg_match('/GS\s+CNX\s+PERIOD:\s*\d{2}\/(\d{4})Z?\s*[-\x{2013}\x{2014}]\s*\d{2}\/(\d{4})Z?/iu', $line, $m)) {
                $tmi['cnx_period_start'] = $m[1];
                $tmi['cnx_period_end'] = $m[2];
                continue;
            }
            if (preg_match('/^COMMENTS?:\s*(.*)/i', $line, $m)) { $tmi['comments'] = trim($m[1]); continue; }
        }

        return ['tmi' => $tmi, 'lines_consumed' => $lines_consumed];
    }

    // Handle REROUTE_CNX (Reroute Cancellation)
    if ($advzy_type === 'REROUTE_CNX') {
        $tmi = [
            'raw' => $header,
            'type' => 'REROUTE_CNX',
            'advzy_number' => $advzy_number,
            'cancelled_name' => null,
            'cancelled_time' => null
        ];

        for ($i = $start_idx + 1; $i < min($start_idx + 10, count($lines)); $i++) {
            $line = trim($lines[$i]);
            if (empty($line)) { $lines_consumed++; continue; }
            if (preg_match('/^vATCSCC\s+ADVZY/i', $line)) break;
            $lines_consumed++;

            // "MCO_NO_GRNCH_PRICY HAS BEEN CANCELLED AT 2108Z"
            if (preg_match('/^(\S+)\s+HAS\s+BEEN\s+CANCELL?ED(?:\s+AT\s+(\d{4})Z?)?/i', $line, $m)) {
                $tmi['cancelled_name'] = trim($m[1]);
                $tmi['cancelled_time'] = isset($m[2]) ? $m[2] : null;
            }
        }

        return ['tmi' => $tmi, 'lines_consumed' => $lines_consumed];
    }

    // For GS/GDP: Extract airport from header: "vATCSCC ADVZY 001 LAS/ZLA 01/18/2026"
    $dest = null;
    if ($advzy_type === 'GS' || $advzy_type === 'GDP') {
        if (preg_match('/ADVZY\s+\d+\s+([A-Z]{3})(?:\/|\s)/i', $header, $m)) {
            $dest = strtoupper($m[1]);
        }
    }

    $tmi = [
        'raw' => $header,
        'type' => $advzy_type,
        'advzy_number' => $advzy_number,
        'dest' => $dest,
        'provider' => null,
        'start_time' => null,
        'end_time' => null,
        'issued_time' => null
    ];

    // Reroute-specific fields
    if ($advzy_type === 'REROUTE') {
        $tmi['mandatory'] = $is_mandatory;
        $tmi['route_type'] = $route_type;
        $tmi['action'] = $action;
        $tmi['name'] = null;
        $tmi['constrained_area'] = null;
        $tmi['reason'] = null;
        $tmi['origins'] = [];
        $tmi['destinations'] = [];
        $tmi['facilities'] = [];
        $tmi['routes'] = [];
        $tmi['tmi_id'] = null;
    }

    // Parse subsequent lines for ADVZY fields
    $in_routes_section = false;
    $routes_buffer = [];
    $current_route_orig = null;

    for ($i = $start_idx + 1; $i < min($start_idx + 100, count($lines)); $i++) {
        $line = trim($lines[$i]);

        if (empty($line)) {
            $lines_consumed++;
            continue;
        }

        // End of ADVZY block - hit another ADVZY or NTML entry
        if (preg_match('/^vATCSCC\s+ADVZY/i', $line) ||
            preg_match('/^\d{2}\/\d{4}\s+\w+\s+via\s+/i', $line)) {
            break;
        }

        $lines_consumed++;

        // === GS/GDP specific fields ===

        // CTL ELEMENT: LAS
        if (preg_match('/^CTL\s+ELEMENT:\s*(\w+)/i', $line, $m)) {
            $tmi['dest'] = strtoupper($m[1]);
            continue;
        }

        // GROUND STOP PERIOD: 18/0230Z - 18/0315Z (handles various dash types)
        if (preg_match('/GROUND\s+STOP\s+PERIOD:\s*\d{2}\/(\d{4})Z?\s*[-\x{2013}\x{2014}]\s*\d{2}\/(\d{4})Z?/iu', $line, $m)) {
            $tmi['start_time'] = $m[1];
            $tmi['end_time'] = $m[2];
            continue;
        }

        // ADL TIME: 0244Z (issued time)
        if (preg_match('/^ADL\s+TIME:\s*(\d{4})Z?/i', $line, $m)) {
            $tmi['issued_time'] = $m[1];
            continue;
        }

        // DEP FACILITIES INCLUDED: (1stTier) ZAB, ZKC, ZMP or (Manual) ZOA, ZLA
        if (preg_match('/DEP\s+FACILITIES\s+INCLUDED:\s*(.*)/i', $line, $m)) {
            $fac_str = $m[1];
            // Extract tier notation
            if (preg_match('/\((1stTier|2ndTier|Manual)\)/i', $fac_str, $tierM)) {
                $tmi['dep_facility_tier'] = $tierM[1];
            }
            // Extract all 3-letter facility codes
            preg_match_all('/\b([A-Z]{3})\b/', strtoupper($fac_str), $facMatches);
            $tmi['dep_facilities'] = $facMatches[1] ?? [];
            $tmi['provider'] = $tmi['dep_facilities'][0] ?? null;
            continue;
        }

        // CUMULATIVE PROGRAM PERIOD: 18/0230Z - 18/0315Z
        if (preg_match('/CUMULATIVE\s+(?:PROGRAM\s+)?PERIOD:\s*\d{2}\/(\d{4})Z?\s*[-\x{2013}\x{2014}]\s*\d{2}\/(\d{4})Z?/iu', $line, $m)) {
            $tmi['cumulative_start'] = $m[1];
            $tmi['cumulative_end'] = $m[2];
            continue;
        }

        // IMPACTING CONDITION: LOW CEILINGS
        if (preg_match('/^IMPACTING\s+CONDITION:\s*(.+)/i', $line, $m)) {
            $tmi['impacting_condition'] = trim($m[1]);
            continue;
        }

        // PROBABILITY OF EXTENSION: HIGH
        if (preg_match('/^PROBABILITY\s+OF\s+EXTENSION:\s*(.+)/i', $line, $m)) {
            $tmi['prob_extension'] = trim($m[1]);
            continue;
        }

        // FLT INCL: (pattern varies)
        if (preg_match('/^FLT\s+INCL:\s*(.+)/i', $line, $m)) {
            $tmi['flt_incl'] = trim($m[1]);
            continue;
        }

        // NEW TOTAL, MAXIMUM, AVERAGE DELAYS: X, Y, Z
        if (preg_match('/NEW\s+TOTAL.*DELAYS?:\s*(.+)/i', $line, $m)) {
            $tmi['delay_new'] = trim($m[1]);
            continue;
        }

        // PREVIOUS TOTAL, MAXIMUM, AVERAGE DELAYS: X, Y, Z
        if (preg_match('/PREVIOUS\s+TOTAL.*DELAYS?:\s*(.+)/i', $line, $m)) {
            $tmi['delay_prev'] = trim($m[1]);
            continue;
        }

        // COMMENTS: (may be multi-line for GS)
        if (preg_match('/^COMMENTS?:\s*(.*)/i', $line, $m)) {
            $comments = trim($m[1]);
            // Accumulate continuation lines
            while ($i + 1 < count($lines)) {
                $next = trim($lines[$i + 1]);
                if (empty($next) || preg_match('/^[A-Z\s]+:/i', $next) || preg_match('/^vATCSCC/i', $next)) break;
                $i++;
                $lines_consumed++;
                $comments .= ' ' . $next;
            }
            $tmi['comments'] = $comments;
            continue;
        }

        // GDP-specific: PROGRAM RATE: 30
        if (preg_match('/PROGRAM\s+RATE:\s*(\d+)/i', $line, $m)) {
            $tmi['program_rate'] = intval($m[1]);
            continue;
        }

        // === REROUTE specific fields ===

        if ($advzy_type === 'REROUTE') {
            // NAME: FLORIDA TO NE 2_PARTIAL
            if (preg_match('/^NAME:\s*(.+)/i', $line, $m)) {
                $tmi['name'] = trim($m[1]);
                continue;
            }

            // CONSTRAINED AREA: ZJX
            if (preg_match('/^CONSTRAINED\s+AREA:\s*(.+)/i', $line, $m)) {
                $tmi['constrained_area'] = trim($m[1]);
                continue;
            }

            // REASON: VOLUME
            if (preg_match('/^REASON:\s*(.+)/i', $line, $m)) {
                $tmi['reason'] = trim($m[1]);
                continue;
            }

            // INCLUDE TRAFFIC: KAPF/KFMY/KMKY... DEPARTURES TO KBOS
            if (preg_match('/^INCLUDE\s+TRAFFIC:\s*(.+)/i', $line, $m)) {
                $traffic_str = trim($m[1]);
                // Handle continuation lines
                while ($i + 1 < count($lines) &&
                       preg_match('/^\s{2,}[A-Z]/', $lines[$i + 1]) &&
                       !preg_match('/^[A-Z]+:/', trim($lines[$i + 1]))) {
                    $i++;
                    $lines_consumed++;
                    $traffic_str .= ' ' . trim($lines[$i]);
                }

                // Parse "ORIGINS DEPARTURES TO DESTINATIONS"
                if (preg_match('/(.+?)\s+DEPARTURES?\s+TO\s+(.+)/i', $traffic_str, $tm)) {
                    $orig_str = trim($tm[1]);
                    $dest_str = trim($tm[2]);

                    // Parse origins (may be / or space separated)
                    $origins = preg_split('/[\/\s]+/', $orig_str);
                    $tmi['origins'] = array_values(array_filter(array_map(function($o) {
                        $o = trim($o);
                        // Skip facility codes like KZMA
                        return (strlen($o) === 4 && $o[0] === 'K') ? $o : null;
                    }, $origins)));

                    // Parse destinations
                    $dests = preg_split('/[\/\s]+/', $dest_str);
                    $tmi['destinations'] = array_values(array_filter(array_map(function($d) {
                        $d = trim($d);
                        return (strlen($d) === 4 && $d[0] === 'K') ? $d : null;
                    }, $dests)));
                }
                continue;
            }

            // FACILITIES INCLUDED: ZBW/ZDC/ZJX/ZMA/ZTL
            if (preg_match('/^FACILITIES\s+INCLUDED:\s*(.+)/i', $line, $m)) {
                $fac_str = trim($m[1]);
                $tmi['facilities'] = array_filter(preg_split('/[\/\s]+/', $fac_str));
                continue;
            }

            // VALID: ETA 311500 TO 311900 or ETD 301430 TO 301900
            if (preg_match('/^VALID:\s*(ETA|ETD)\s*(\d{6})\s+TO\s+(\d{6})/i', $line, $m)) {
                $time_type = strtoupper($m[1]);  // ETA or ETD
                $start_ddhhmm = $m[2];
                $end_ddhhmm = $m[3];

                // Extract HHMM from DDHHMM
                $tmi['start_time'] = substr($start_ddhhmm, 2, 4);
                $tmi['end_time'] = substr($end_ddhhmm, 2, 4);
                $tmi['time_type'] = $time_type;  // ETA = arrival time, ETD = departure time
                continue;
            }

            // VALID: ETD 1900-2359 (simple HHMM-HHMM format)
            if (!isset($tmi['start_time']) && preg_match('/^VALID:\s*(ETA|ETD)\s*(\d{4})\s*[-\x{2013}\x{2014}]\s*(\d{4})/iu', $line, $m)) {
                $tmi['start_time'] = $m[2];
                $tmi['end_time'] = $m[3];
                $tmi['time_type'] = strtoupper($m[1]);
                continue;
            }

            // REMARKS: REPLACES ADVZY 020
            if (preg_match('/^REMARKS?:\s*(.*)/i', $line, $m)) {
                $remarks = trim($m[1]);
                $tmi['remarks'] = $remarks;
                if (preg_match('/REPLACES?\s+(?:\/\s*)?ADVZY\s+(\d+)/i', $remarks, $replM)) {
                    $tmi['replaces_advzy'] = $replM[1];
                }
                continue;
            }

            if (preg_match('/^MODIFICATIONS?:\s*(.*)/i', $line, $m)) { $tmi['modifications'] = trim($m[1]); continue; }
            if (preg_match('/^ASSOCIATED\s+RESTRICTIONS?:\s*(.*)/i', $line, $m)) { $tmi['associated_restrictions'] = trim($m[1]); continue; }
            if (preg_match('/^EXEMPTIONS?:\s*(.*)/i', $line, $m)) { $tmi['exemptions'] = trim($m[1]); continue; }
            if (preg_match('/^PROBABILITY\s+OF\s+EXTENSION:\s*(.*)/i', $line, $m)) { $tmi['prob_extension'] = trim($m[1]); continue; }

            // TMI ID: RRDCCADVZY 003
            if (preg_match('/^TMI\s+ID:\s*(.+)/i', $line, $m)) {
                $tmi['tmi_id'] = trim($m[1]);
                continue;
            }

            // ROUTES: marker
            if (preg_match('/^ROUTES:\s*$/i', $line)) {
                $in_routes_section = true;
                continue;
            }

            // Parse route table rows (after ROUTES: section)
            if ($in_routes_section) {
                // Skip header lines like "ORIG  DEST  ROUTE" or "----  ----  -----"
                if (preg_match('/^(ORIG|FROM|TO|----)/i', $line)) {
                    continue;
                }

                // Route row: KPHL  KBOS  >DITCH LUIGI HNNAH MERIT< ROBUC3
                // Or continuation line starting with spaces
                if (preg_match('/^([A-Z0-9\/\s\-\(\)]+?)\s{2,}(K[A-Z]{3})?\s*(.*)/i', $line, $rm)) {
                    $orig_part = trim($rm[1]);
                    $dest_part = isset($rm[2]) ? trim($rm[2]) : '';
                    $route_part = isset($rm[3]) ? trim($rm[3]) : '';

                    if ($orig_part && !preg_match('/^-+$/', $orig_part)) {
                        $current_route_orig = $orig_part;
                    }

                    if ($route_part) {
                        $routes_buffer[] = [
                            'orig' => $current_route_orig,
                            'dest' => $dest_part ?: ($tmi['destinations'][0] ?? ''),
                            'route' => $route_part
                        ];
                    }
                }
            }
        }
    }

    // Store parsed routes
    if (!empty($routes_buffer)) {
        $tmi['routes'] = $routes_buffer;
    }

    return ['tmi' => $tmi, 'lines_consumed' => $lines_consumed];
}

/**
 * Build GS program chains from parsed TMI entries
 *
 * Groups GS advisories by airport and matches with GS_CNX cancellations.
 *
 * @param array $tmis Parsed TMI entries
 * @return array GS program objects
 */
function build_gs_programs($tmis) {
    $gs_tmis = array_filter($tmis, fn($t) => ($t['type'] ?? '') === 'GS');
    $gs_cnx = array_filter($tmis, fn($t) => ($t['type'] ?? '') === 'GS_CNX');

    // Group by airport
    $by_airport = [];
    foreach ($gs_tmis as $t) {
        $apt = $t['dest'] ?? '';
        if ($apt) $by_airport[$apt][] = $t;
    }

    $programs = [];
    foreach ($by_airport as $airport => $advisories) {
        usort($advisories, fn($a, $b) => ($a['advzy_number'] ?? '0') <=> ($b['advzy_number'] ?? '0'));

        // Assign advisory_type based on position in chain
        foreach ($advisories as $idx => &$a) {
            if (empty($a['advisory_type'])) {
                $a['advisory_type'] = $idx === 0 ? 'INITIAL' : 'EXTENSION';
            }
        }
        unset($a);

        $last_advisory = end($advisories);
        $program = [
            'type' => 'GS_PROGRAM',
            'airport' => $airport,
            'advisories' => $advisories,
            'dep_facilities' => [],
            'effective_start' => $advisories[0]['start_time'] ?? null,
            'effective_end' => $last_advisory['end_time'] ?? null,
            'ended_by' => 'EXPIRATION',
            'impacting_condition' => '',
            'cnx_comments' => ''
        ];

        // Collect dep_facilities and metadata
        foreach ($advisories as $a) {
            if (!empty($a['dep_facilities'])) {
                $program['dep_facilities'] = array_unique(array_merge($program['dep_facilities'], $a['dep_facilities']));
            }
            if (!empty($a['impacting_condition'])) $program['impacting_condition'] = $a['impacting_condition'];
        }

        // Match CNX
        foreach ($gs_cnx as $cnx) {
            if (($cnx['dest'] ?? '') === $airport) {
                $program['ended_by'] = 'CNX';
                $program['effective_end'] = $cnx['cnx_period_end'] ?? $cnx['issued_time'] ?? $program['effective_end'];
                $program['cnx_comments'] = $cnx['comments'] ?? '';
                break;
            }
        }

        $programs[] = $program;
    }

    return $programs;
}

/**
 * Build reroute program chains from parsed TMI entries
 *
 * Groups reroute advisories by name and matches with REROUTE_CNX cancellations.
 *
 * @param array $tmis Parsed TMI entries
 * @return array Reroute program objects
 */
function build_reroute_programs($tmis) {
    $reroutes = array_filter($tmis, fn($t) => ($t['type'] ?? '') === 'REROUTE');
    $cnxs = array_filter($tmis, fn($t) => ($t['type'] ?? '') === 'REROUTE_CNX');

    // Group by name
    $by_name = [];
    foreach ($reroutes as $t) {
        $name = $t['name'] ?? '';
        if ($name) $by_name[$name][] = $t;
    }

    $programs = [];
    foreach ($by_name as $name => $advisories) {
        usort($advisories, fn($a, $b) => ($a['advzy_number'] ?? '0') <=> ($b['advzy_number'] ?? '0'));
        $latest = end($advisories);

        $program = [
            'type' => 'REROUTE_PROGRAM',
            'name' => $name,
            'route_type' => $latest['route_type'] ?? 'ROUTE',
            'action' => $latest['action'] ?? 'FYI',
            'mandatory' => ($latest['action'] ?? '') === 'RQD',
            'advisories' => $advisories,
            'constrained_area' => $latest['constrained_area'] ?? '',
            'reason' => $latest['reason'] ?? '',
            'effective_start' => $advisories[0]['start_time'] ?? null,
            'effective_end' => $latest['end_time'] ?? null,
            'ended_by' => 'EXPIRATION',
            'routes' => $latest['routes'] ?? [],
            'tmi_id' => $latest['tmi_id'] ?? ''
        ];

        // Match cancellation
        foreach ($cnxs as $cnx) {
            if (($cnx['cancelled_name'] ?? '') === $name) {
                $program['ended_by'] = 'CNX';
                $program['effective_end'] = $cnx['cancelled_time'] ?? $program['effective_end'];
                break;
            }
        }

        $programs[] = $program;
    }

    return $programs;
}
