<?php
/**
 * NTML Compact Format Parser for Historical TMI Import.
 * Parses Discord-exported NTML log entries (MIT, STOP, CONFIG, D/D, etc.)
 *
 * @package PERTI
 * @subpackage Scripts/TMI
 */

/**
 * Parse an entire NTML log file into structured entries.
 * @param string $content Raw file content
 * @return array [{_type, _line, _raw, ctl_element, start_utc, end_utc, ...}, ...]
 */
function parseNtmlFile(string $content): array {
    $lines = explode("\n", str_replace("\r\n", "\n", $content));
    $entries = [];
    $header = null;

    for ($i = 0, $n = count($lines); $i < $n; $i++) {
        $trimmed = trim($lines[$i]);
        if ($trimmed === '' || $trimmed === "\xC2\xA0") continue;

        // Full header: "AuthorName | Facility — MM/DD/YYYY HH:MM"
        if (preg_match('/^(.+?)\s*\|\s*(.+?)\s+(\d{2}\/\d{2}\/\d{4})\s+\d{2}:\d{2}/', $trimmed, $m)) {
            $fac = preg_replace('/\s*[\x{2014}\xE2\x80\x94\xC3\xA2]+\s*$/u', '', trim($m[2]));
            $header = ['author' => trim($m[1]), 'facility' => $fac, 'date' => $m[3]];
            continue;
        }
        // Partial header: date-only line for split headers (2024+)
        if (preg_match('/^\s*[\x{2014}\xE2\x80\x94\xC3\xA2\xC3\x82�]+\s*(\d{2}\/\d{2}\/\d{4})\s+\d{2}:\d{2}/u', $trimmed, $m)) {
            if ($header) $header['date'] = $m[1];
            else $header = ['author' => '?', 'facility' => '?', 'date' => $m[1]];
            continue;
        }
        // Author-only line (no date) for split headers
        if (preg_match('/^([A-Za-z][\w ]+?)\s*\|\s*([A-Z].*)$/i', $trimmed, $m) && !preg_match('/\d{2}\/\d{4}/', $trimmed)) {
            $header = $header ?? ['date' => null];
            $header['author'] = trim($m[1]);
            $header['facility'] = trim($m[2]);
            continue;
        }
        // Skip noise
        if (isNtmlNoise($trimmed)) continue;

        // Entry line: DD/HHMM
        if (preg_match('/^\d{2}\/\d{4}\s/', $trimmed)) {
            $fullLine = $trimmed;
            // Join comma-continuation lines
            while (preg_match('/,\s*$/', $fullLine) && $i + 1 < $n) {
                $i++;
                $next = trim($lines[$i]);
                if ($next === '') break;
                $fullLine .= ' ' . $next;
            }
            $entry = parseNtmlEntryLine($fullLine, $header);
            if ($entry) {
                $entry['_line'] = $i + 1;
                $entries[] = $entry;
            }
        }
    }
    return $entries;
}

/** Detect noise/non-entry lines. */
function isNtmlNoise(string $line): bool {
    if (in_array($line, ['MIT / MINIT', 'MIT/MINIT', 'Airport Configuration', 'APP', 'Delay'])) return true;
    if (strlen($line) < 4) return true;
    if (preg_match('/^(disregard|please stop|ATL no more|MIT over|PLEASE STOP)/i', $line)) return true;
    if (strpos($line, '(Notification:') !== false) return true;
    if (preg_match('/^Departure delay/i', $line)) return true;
    return false;
}

/** Classify an NTML entry line body (after DD/HHMM prefix stripped). */
function classifyNtmlEntry(string $body): ?string {
    $u = strtoupper($body);
    if (preg_match('/\b(VMC|IMC|LVMC|LIMC)\b/', $u) && strpos($u, 'ARR:') !== false) return 'CONFIG';
    if (preg_match('/\bD\/D\b/', $u)) return 'DD';
    if (preg_match('/\bE\/D\b/', $u)) return 'ED';
    if (preg_match('/\bA\/D\b/', $u)) return 'AD';
    if (preg_match('/\bCFR\b/', $u)) return 'CFR';
    if (preg_match('/\bAPREQ\b/', $u)) return 'APREQ';
    if (preg_match('/\bTBM\b/', $u)) return 'TBM';
    if (preg_match('/\bCANCELL?\b/', $u)) return 'CANCEL';
    if (strpos($u, 'TYPE:PLANNING') !== false) return 'PLANNING';
    if (preg_match('/\bSTOP\b/', $u) && !preg_match('/\bTHUNDERSTOP\b/', $u)) return 'STOP';
    if (preg_match('/\d+\s*MINIT\b/', $u)) return 'MINIT';
    if (preg_match('/\d+\s*MIT\b/', $u)) return 'MIT';
    if (preg_match('/\b(AOB|SPD:|ALT:)/', $u)) return 'MIT';
    return null;
}

/**
 * Resolve date from header date (MM/DD/YYYY) + entry day (DD).
 * Handles month rollover when entry day < header day.
 */
function resolveNtmlDate(?string $headerDate, int $entryDay): ?string {
    if (!$headerDate) return null;
    $parts = explode('/', $headerDate);
    if (count($parts) !== 3) return null;
    $month = (int)$parts[0];
    $headerDay = (int)$parts[1];
    $year = (int)$parts[2];
    if ($entryDay < $headerDay - 5) {
        $month++;
        if ($month > 12) { $month = 1; $year++; }
    }
    if (!checkdate($month, $entryDay, $year)) return null;
    return sprintf('%04d-%02d-%02d', $year, $month, $entryDay);
}

/**
 * Parse a single NTML entry line into structured data.
 * @param string $line Full entry line (DD/HHMM body...)
 * @param array|null $header Current header context {author, facility, date}
 * @return array|null Parsed data array or null if unparseable
 */
function parseNtmlEntryLine(string $line, ?array $header): ?array {
    if (!preg_match('/^(\d{2})\/(\d{2})(\d{2})\s+(.+)$/', $line, $m)) return null;

    $entryDay = (int)$m[1];
    $hh = $m[2]; $mm = $m[3];
    $body = trim($m[4]);
    $baseDate = resolveNtmlDate($header['date'] ?? null, $entryDay);
    $ts = $baseDate ? "{$baseDate} {$hh}:{$mm}:00" : null;

    $type = classifyNtmlEntry($body);
    if (!$type) return null;

    $data = [
        'ctl_element' => null, 'element_type' => null,
        'start_utc' => null, 'end_utc' => null,
        'impacting_condition' => null, 'cause_text' => null,
        'restriction_value' => null, 'restriction_unit' => null,
        'mit_fix' => null,
        'requesting_facility' => null, 'providing_facility' => null,
        'exclusions' => null, 'qualifiers' => null,
        'advisory_number' => null, 'program_rate' => null,
        'delay_limit_min' => null, 'scope_centers' => null,
        'body_text' => $body, '_raw' => $line, '_type' => $type,
        '_ntml_author' => $header['author'] ?? null,
        '_ntml_facility' => $header['facility'] ?? null,
        '_entry_timestamp' => $ts,
    ];

    // Strip trailing bot code ($ XXXXX)
    $work = preg_replace('/\s*\$\s*[A-Z0-9]+\s*$/', '', $body);

    // Extract req:prov from end — e.g. "ZBW:ZNY" or "ZMA:ZJX30" or "ZJX:ZTL,ZDC"
    if (preg_match('/\s+([A-Z][A-Z0-9]{0,4}):([A-Z][A-Z0-9]{0,5}(?:[,\/]\s*[A-Z][A-Z0-9]{0,5})*)\s*$/', $work, $rp)) {
        $maybeReq = $rp[1];
        $noMatch = ['VOLUME','WEATHER','RUNWAY','OTHER','NAVAID','VOL','EQUIPMENT','TYPE','SPD','ALT','EXCL','AAR','ADR'];
        if (!in_array($maybeReq, $noMatch)) {
            $data['requesting_facility'] = $maybeReq;
            $data['providing_facility'] = preg_replace('/\s+/', '', trim($rp[2]));
            $work = substr($work, 0, -strlen($rp[0]));
        }
    }

    // Extract time range HHMM-HHMM
    if (preg_match('/\s+(\d{4})-(\d{4})\b/', $work, $tr)) {
        $sh = substr($tr[1],0,2); $sm = substr($tr[1],2,2);
        $eh = substr($tr[2],0,2); $em = substr($tr[2],2,2);
        if ($baseDate) {
            $data['start_utc'] = "{$baseDate} {$sh}:{$sm}:00";
            $endDate = ((int)$tr[2] < (int)$tr[1])
                ? date('Y-m-d', strtotime("$baseDate +1 day")) : $baseDate;
            $data['end_utc'] = "{$endDate} {$eh}:{$em}:00";
        }
        $work = str_replace($tr[0], '', $work);
    }

    // Extract EXCL:xxx
    if (preg_match('/\bEXCL:(\S+)/', $work, $ex)) {
        $data['exclusions'] = $ex[1];
        $work = str_replace($ex[0], '', $work);
    }

    // Extract REASON — CATEGORY:DETAIL (handle multi-word details like VOLUME:SUPER BOWL)
    if (preg_match('/\b(VOLUME|WEATHER|RUNWAY|OTHER|NAVAID|VOL|EQUIPMENT|EVENT):([A-Z][A-Z0-9_ ]*?)(?=\s+EXCL:|\s+\d{4}-|\s+[A-Z]{2,5}:[A-Z]|\s*$)/i', $work, $re)) {
        $cat = strtoupper($re[1]);
        if ($cat === 'VOL') $cat = 'VOLUME';
        $data['impacting_condition'] = $cat;
        $data['cause_text'] = trim($re[2]);
        $work = str_replace($re[0], '', $work);
    }

    // Also check for LATE NOTE style reason at end
    if (!$data['impacting_condition'] && preg_match('/\b(VOLUME|WEATHER|RUNWAY|OTHER|NAVAID):(\S+)/i', $work, $re2)) {
        $cat = strtoupper($re2[1]);
        $data['impacting_condition'] = $cat;
        $data['cause_text'] = trim($re2[2]);
        $work = str_replace($re2[0], '', $work);
    }

    $work = trim(preg_replace('/\s+/', ' ', $work));

    // Dispatch to type-specific parser
    switch ($type) {
        case 'MIT': case 'MINIT': ntmlParseMit($work, $data, $type); break;
        case 'STOP': ntmlParseStop($work, $data); break;
        case 'CONFIG': ntmlParseConfig($work, $data); break;
        case 'DD': case 'ED': case 'AD': ntmlParseDelay($work, $data, $type); break;
        case 'CFR': ntmlParseCfr($work, $data); break;
        case 'APREQ': ntmlParseApreq($work, $data); break;
        case 'TBM': ntmlParseTbm($work, $data); break;
        case 'CANCEL': ntmlParseCancel($work, $data); break;
        case 'PLANNING': $data['_parsed_extra'] = $work; break;
    }

    if ($data['ctl_element'] && !$data['element_type']) {
        $data['element_type'] = perti_detect_element_type($data['ctl_element']);
    }
    return $data;
}

// =========================================================================
// Type-Specific Parsers
// =========================================================================

function ntmlParseMit(string $body, array &$data, string $mitType): void {
    $pat = ($mitType === 'MINIT') ? 'MINIT' : 'MIT';

    // Extract restriction value
    if (preg_match('/(\d+)\s*' . $pat . '\b/i', $body, $vm)) {
        $data['restriction_value'] = (int)$vm[1];
        $data['restriction_unit'] = ($mitType === 'MINIT') ? 'MIN' : 'NM';
    }

    // Collect qualifiers
    $quals = [];
    foreach (['PER AIRPORT','PER STREAM','PER ROUTE','PER RTE','AS ONE',
              'SINGLE STREAM','NO STACKS','NO COMP','EACH FIX','EACH','RALT','TUCK'] as $q) {
        if (stripos($body, $q) !== false) $quals[] = $q;
    }
    if (preg_match('/\bTYPE:(JETS?|ALL|PROPS?)\b/i', $body, $tq)) $quals[] = 'TYPE:' . strtoupper($tq[1]);
    elseif (preg_match('/\bJETS\b/i', $body)) $quals[] = 'TYPE:JETS';
    if (preg_match('/\bSPD:([=]?\d+(?:KT)?)\b/i', $body, $sq)) $quals[] = 'SPD:' . strtoupper($sq[1]);
    if (preg_match('/(?:ALT:)?(AOB\s*(?:FL)?\d+)/i', $body, $aq)) $quals[] = 'ALT:' . strtoupper(str_replace(' ','',$aq[1]));
    if ($quals) $data['qualifiers'] = $quals;

    // Get text before the MIT value
    $before = $body;
    if (preg_match('/^(.*?)(\d+)\s*' . $pat . '/i', $body, $bm)) {
        $before = trim($bm[1]);
    }
    // Clean out qualifiers and flow-direction words
    $before = preg_replace('/\b(TYPE:\S+|JETS|PROPS?|SPD:\S+|ALT:\S+|AOB\s*(?:FL)?\d+)\b/i', '', $before);
    $before = preg_replace('/\s*(arrivals?|departures?)\b/i', '', $before);
    $before = trim(preg_replace('/\s+/', ' ', $before));

    // Split on "via"
    if (preg_match('/^(.+?)\s+via\s+(.+)$/i', $before, $v)) {
        $data['ctl_element'] = ntmlFirstAirport(trim($v[1]));
        $data['_airports'] = ntmlAirportList(trim($v[1]));
        $data['mit_fix'] = strtoupper(trim($v[2]));
    } else {
        $data['ctl_element'] = ntmlFirstAirport($before);
        $data['_airports'] = ntmlAirportList($before);
    }
}

function ntmlParseStop(string $body, array &$data): void {
    // Normal: "[airport] STOP" or "[airport] via [fix] STOP"
    if (preg_match('/^(.+?)\s+STOP\b/i', $body, $m)) {
        $before = trim($m[1]);
        $before = preg_replace('/\s*(departures?|arrivals?)\b/i', '', $before);
        $before = trim($before);
        if (preg_match('/^(.+?)\s+via\s+(.+)$/i', $before, $v)) {
            $data['ctl_element'] = ntmlFirstAirport(trim($v[1]));
            $data['_airports'] = ntmlAirportList(trim($v[1]));
            $data['mit_fix'] = strtoupper(trim($v[2]));
        } else {
            $data['ctl_element'] = ntmlFirstAirport($before);
            $data['_airports'] = ntmlAirportList($before);
        }
    }
    // Reversed: "STOP [airport] via [fix]..."
    if (!$data['ctl_element'] && preg_match('/\bSTOP\s+([A-Z]{2,4})/i', $body, $m)) {
        $data['ctl_element'] = strtoupper($m[1]);
        if (preg_match('/STOP\s+\w+\s+via\s+(\S+)/i', $body, $vm)) {
            $data['mit_fix'] = strtoupper($vm[1]);
        }
    }
}

function ntmlParseConfig(string $body, array &$data): void {
    // Split on 2+ spaces (tab-like delimiters)
    $parts = preg_split('/\s{2,}/', $body);
    if (count($parts) < 3) $parts = preg_split('/\s+/', $body, 5);

    $data['ctl_element'] = strtoupper(trim($parts[0] ?? ''));

    $cfg = ['weather'=>null, 'arr_rwys'=>null, 'dep_rwys'=>null,
            'aar'=>null, 'aar_type'=>null, 'adr'=>null, 'aar_adj'=>null];

    $full = implode(' ', $parts);
    if (preg_match('/\b(VMC|IMC|LVMC|LIMC)\b/i', $full, $wm)) $cfg['weather'] = strtoupper($wm[1]);
    if (preg_match('/ARR:([^\s]+(?:\s*[,\/][^\s]+)*)/i', $full, $am)) $cfg['arr_rwys'] = $am[1];
    if (preg_match('/DEP:([^\s]+(?:\s*[,\/][^\s]+)*)/i', $full, $dm)) $cfg['dep_rwys'] = $dm[1];
    if (preg_match('/AAR\((\w+)\):(\d+)/i', $full, $rm)) { $cfg['aar_type']=$rm[1]; $cfg['aar']=(int)$rm[2]; }
    if (preg_match('/\bADR:(\d+)/i', $full, $rm)) $cfg['adr'] = (int)$rm[1];
    if (preg_match('/AAR Adjustment:([A-Z_ ]+)/i', $full, $rm)) $cfg['aar_adj'] = trim($rm[1]);

    $data['_config'] = $cfg;
    $data['impacting_condition'] = $data['impacting_condition'] ?? $cfg['weather'];
}

function ntmlParseDelay(string $body, array &$data, string $type): void {
    $patterns = ['DD' => 'D/D', 'ED' => 'E/D', 'AD' => 'A/D'];
    $preps = ['DD' => 'from', 'ED' => 'for', 'AD' => 'to'];
    $tag = $patterns[$type]; $prep = $preps[$type];

    // Facility before E/D or A/D
    if ($type !== 'DD' && preg_match('/^(\S+)\s+[EA]\/D/i', $body, $fm)) {
        $data['requesting_facility'] = $data['requesting_facility'] ?? strtoupper($fm[1]);
    }
    // Airport — extract from "D/D from JFK," or "E/D for BOS," or "A/D to MIA,"
    if (preg_match('/' . preg_quote($tag, '/') . '\s+' . $prep . '\s+(\w+)/i', $body, $am)) {
        $data['ctl_element'] = strtoupper($am[1]);
    }
    // D/D variant: "ZLC D/D FOR SLC DEPS" or "D/D BOS, 30/0100..." or "ATL D/D +15/0059"
    if (!$data['ctl_element'] && $type === 'DD') {
        if (preg_match('/D\/D\s+FOR\s+([A-Z]{2,4})\b/i', $body, $am)) {
            $data['ctl_element'] = strtoupper($am[1]);
        } elseif (preg_match('/D\/D\s+([A-Z]{2,4})\b/i', $body, $am)) {
            $data['ctl_element'] = strtoupper($am[1]);
        } elseif (preg_match('/^([A-Z]{2,4})\s+D\/D/i', $body, $am)) {
            $data['ctl_element'] = strtoupper($am[1]);
        }
    }
    // A/D variant: "A/D for JFK" (using "for" instead of "to")
    if (!$data['ctl_element'] && $type === 'AD' && preg_match('/A\/D\s+for\s+(\w+)/i', $body, $am)) {
        $data['ctl_element'] = strtoupper($am[1]);
    }
    // E/D variant: "E/D to SFO" (using "to" instead of "for")
    if (!$data['ctl_element'] && $type === 'ED' && preg_match('/E\/D\s+to\s+([A-Z]{2,4})\b/i', $body, $am)) {
        $data['ctl_element'] = strtoupper($am[1]);
    }
    // A/D or E/D without preposition — try FIX: field as context
    if (!$data['ctl_element'] && preg_match('/FIX:(\S+)/i', $body, $fm)) {
        $data['mit_fix'] = strtoupper($fm[1]);
    }
    // Delay info
    $extra = ['direction'=>null, 'value'=>null, 'measured_at'=>null, 'acft_count'=>null, 'navaid'=>null];
    if (preg_match('/([+-])(Holding|\d+)\/(\d{4})(?:\/(\d+)\s*ACFT)?/i', $body, $dm)) {
        $extra['direction'] = $dm[1];
        $extra['value'] = $dm[2];
        $extra['measured_at'] = $dm[3];
        if (isset($dm[4])) $extra['acft_count'] = (int)$dm[4];
    }
    if (preg_match('/NAVAID:(\S+)/i', $body, $nm)) $extra['navaid'] = strtoupper($nm[1]);
    $data['_delay'] = $extra;
}

function ntmlParseCfr(string $body, array &$data): void {
    // "CFR BOS departures..." or "BOS,BDL,LGA CFR..." or "DAL via ALL CFR..."
    // Also: "IND,FSM,LAN,GRB,ORD LTFC CFR..." or "All IND, DSM... ORD LTFC CFR..."
    // Also: "SFO departures CFR" or "MEM via HOBRK STAR CFR"
    if (preg_match('/\bCFR\s+([A-Z]{2,4}(?:\s*,\s*[A-Z]{2,4})*)/i', $body, $m)) {
        $data['ctl_element'] = ntmlFirstAirport(trim($m[1]));
        $data['_airports'] = ntmlAirportList(trim($m[1]));
    } elseif (preg_match('/^(?:All\s+)?([A-Z]{2,4}(?:[,\s]+[A-Z]{2,4})*)\s+(?:LTFC\s+)?CFR/i', $body, $m)) {
        $airports = trim($m[1]);
        $list = ntmlAirportList($airports);
        $data['ctl_element'] = end($list) ?: ntmlFirstAirport($airports);
        $data['_airports'] = $list;
    } elseif (preg_match('/^([A-Z]{2,4}(?:[,\/]\s*[A-Z]{2,4})*)\s+(?:departures?\s+)?via\s+(\S+?)(?:\s+(?:STAR|departures?))?\s+(?:CANCEL\s+)?CFR/i', $body, $m)) {
        $data['ctl_element'] = ntmlFirstAirport(trim($m[1]));
        $data['_airports'] = ntmlAirportList(trim($m[1]));
        $data['mit_fix'] = strtoupper($m[2]);
    } elseif (preg_match('/^([A-Z]{2,4})\s+(?:departures?\s+)?(?:via\s+\S+\s+)?(?:CANCEL\s+)?CFR/i', $body, $m)) {
        $data['ctl_element'] = strtoupper($m[1]);
    }
    // CFR to specific destination
    if (preg_match('/\bCFR\s+\w+\s+to\s+(.+)/i', $body, $tm)) {
        $data['_destinations'] = array_map('trim', explode(',', strtoupper($tm[1])));
    }
}

function ntmlParseApreq(string $body, array &$data): void {
    if (preg_match('/\bAPREQ\s+(.+?)\s+(?:departures?\s+)?via\s+(\S+)/i', $body, $m)) {
        $data['ctl_element'] = ntmlFirstAirport(trim($m[1]));
        $data['_airports'] = ntmlAirportList(trim($m[1]));
        $data['mit_fix'] = strtoupper(trim($m[2]));
    } elseif (preg_match('/\bAPREQ\s+(\S+)\s+to\s+(.+)/i', $body, $m)) {
        $data['ctl_element'] = strtoupper(trim($m[1]));
        $data['_destinations'] = array_map('trim', explode(',', strtoupper($m[2])));
    } elseif (preg_match('/\bAPREQ\s+([A-Z]{2,4}(?:,[A-Z]{2,4})*)\b/i', $body, $m)) {
        // "APREQ ATL LTFC" or "APREQ LGA,JFK" — airport directly after APREQ
        $data['ctl_element'] = ntmlFirstAirport(trim($m[1]));
        $data['_airports'] = ntmlAirportList(trim($m[1]));
    }
}

function ntmlParseTbm(string $body, array &$data): void {
    // "ATL TBM 3_WEST..." or "DCA,BWI,IAD departures to CLT,MCO TBM..."
    // Also: "ZJX CANCEL TBM..."
    if (preg_match('/^([A-Z]{2,4}(?:[,\s]+[A-Z]{2,4})*)\s+(?:(?:departures?|CANCEL)\s+(?:to\s+\S+\s+)?)?TBM\s*(.*)/i', $body, $m)) {
        $data['ctl_element'] = ntmlFirstAirport(trim($m[1]));
        $data['_airports'] = ntmlAirportList(trim($m[1]));
        $name = trim($m[2]);
        if ($name && !preg_match('/^(VOLUME|WEATHER|OTHER)/i', $name)) {
            $data['_tbm_name'] = $name;
        }
    }
}

function ntmlParseCancel(string $body, array &$data): void {
    // Patterns: "[airport] CANCEL ALL MIT", "BOS via MERIT CANCEL TMI",
    //           "JFK via ALL EFFECTIVE 0315 CANCEL ALL TMI", "CANCEL ALL TMI ZJX:..."
    // Try airport before "via" or before "CANCEL"
    if (preg_match('/^([A-Z]{2,4}(?:[,+\s]+[A-Z]{2,4})*)\s+via\b/i', $body, $m)) {
        $data['ctl_element'] = ntmlFirstAirport(trim($m[1]));
        $data['_airports'] = ntmlAirportList(trim($m[1]));
        // Also capture the fix being cancelled
        if (preg_match('/via\s+(\S+)/i', $body, $fm)) {
            $data['mit_fix'] = strtoupper($fm[1]);
        }
    } elseif (preg_match('/^([A-Z]{2,4})\s+CANCEL/i', $body, $m)) {
        $data['ctl_element'] = strtoupper(trim($m[1]));
    }
    if (preg_match('/CANCELL?\s+(?:ALL\s+)?(MIT|TMI|TMIS?|RESTR|RESTRICTIONS?|CFR|TBM|TBFM)/i', $body, $m)) {
        $data['_cancel_target'] = strtoupper(trim($m[1]));
    }
}

// =========================================================================
// Helpers
// =========================================================================

/** Extract first airport code from a string like "MIA,FLL,RSW" or "EWR+SATS". */
function ntmlFirstAirport(string $s): string {
    $s = strtoupper(trim($s));
    $s = preg_replace('/[+,].*/', '', $s);
    $s = preg_replace('/\s.*/', '', $s);
    return $s;
}

/** Parse airport list from string. Returns array of airport codes. */
function ntmlAirportList(string $s): array {
    $s = strtoupper(trim($s));
    if (preg_match('/^ALL\b/', $s)) return ['ALL'];
    $codes = preg_split('/[,+\s]+/', $s);
    return array_values(array_filter($codes, fn($a) => preg_match('/^[A-Z]{2,4}\d?$/', $a)));
}

/** Map NTML entry type to tmi_entries determinant_code. */
function ntmlTypeToDeterminant(string $type): string {
    return match($type) {
        'MIT' => 'MIT',
        'MINIT' => 'MINIT',
        'STOP' => 'CONTINGENCY',
        'CONFIG' => 'CONFIG',
        'DD', 'ED', 'AD' => 'DELAY',
        'CFR', 'APREQ' => 'APREQ',
        'TBM', 'CANCEL', 'PLANNING' => 'MISC',
        default => 'MISC',
    };
}
