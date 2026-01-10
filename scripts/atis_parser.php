<?php
/**
 * ATIS Parser for Runway Assignments
 *
 * Parses ATIS text to extract runway assignments.
 * Based on parsing logic from vatsim_control_recs.
 */

declare(strict_types=1);

/**
 * Parse ATIS text to extract landing and departing runways.
 *
 * @param string $atisText Full ATIS text
 * @return array ['landing' => [...], 'departing' => [...], 'approaches' => [...]]
 */
function parseAtisRunways(string $atisText): array {
    $landing = [];
    $departing = [];
    $approaches = [];

    if (empty($atisText)) {
        return ['landing' => $landing, 'departing' => $departing, 'approaches' => $approaches];
    }

    $text = filterAtisText($atisText);

    // Pattern helpers
    $rwyNum = '([0-3]?\d[LRC]?)';
    $rwyList = '([0-3]?\d[LRC]?(?:\s*(?:AND|,|\/|&)\s+[0-3]?\d?[LRC]?|\s+[0-3]\d[LRC]?)*)';  // Added & and space separators

    // Pattern 1: US standard - "LDG RWY 27L", "DEP RWY 28R"
    if (preg_match_all('/(?:LDG|LNDG|LANDING|ARR(?:IV(?:ING|AL))?)\s+(?:RWYS?\s+)?'.$rwyList.'/i', $text, $m)) {
        foreach ($m[1] as $rwyText) {
            $landing = array_merge($landing, extractRunwayNumbers($rwyText));
        }
    }

    if (preg_match_all('/(?:DEP(?:ART(?:ING|URES?)?)?|DEPTG|DEPG|DPTG|DEPARTING|TKOF|TAKEOFF)\s+(?:RWYS?\s+)?'.$rwyList.'/i', $text, $m)) {
        foreach ($m[1] as $rwyText) {
            $departing = array_merge($departing, extractRunwayNumbers($rwyText));
        }
    }

    // Pattern 2: Compound - "LDG/DEPTG RWY 27"
    if (preg_match_all('/(?:LDG\s*(?:AND|\/|&)\s*(?:DEP(?:TG)?|DPTG)|(?:DEP(?:TG)?|DPTG)\s*(?:AND|\/|&)\s*LDG)\s+(?:RWYS?\s+)?'.$rwyList.'/i', $text, $m)) {
        foreach ($m[1] as $rwyText) {
            $rwys = extractRunwayNumbers($rwyText);
            $landing = array_merge($landing, $rwys);
            $departing = array_merge($departing, $rwys);
        }
    }

    // Pattern 3: RUNWAY(S) IN USE
    if (preg_match_all('/RUNWAYS?\s+IN\s+USE\s+'.$rwyList.'/i', $text, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
        foreach ($m as $match) {
            $rwys = extractRunwayNumbers($match[1][0]);
            $context = substr($text, max(0, $match[0][1] - 100), 100);

            if (stripos($context, 'ARRIVAL') !== false || stripos($context, 'ARR ') !== false) {
                $landing = array_merge($landing, $rwys);
            } elseif (stripos($context, 'DEPARTURE') !== false || stripos($context, 'DEP ') !== false) {
                $departing = array_merge($departing, $rwys);
            } else {
                $landing = array_merge($landing, $rwys);
                $departing = array_merge($departing, $rwys);
            }
        }
    }

    // Pattern 4: RWY/RUNWAY XX IN USE
    if (preg_match_all('/(?:ILS\s+)?(?:RWY|RUNWAY)\s+'.$rwyNum.'\s+IN\s+USE/i', $text, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
        foreach ($m as $match) {
            $rwys = extractRunwayNumbers($match[1][0]);
            $hasIls = stripos($match[0][0], 'ILS') !== false;
            $context = substr($text, max(0, $match[0][1] - 100), 100);

            if ($hasIls || stripos($context, 'ARRIVAL') !== false || stripos($context, 'APCH') !== false) {
                $landing = array_merge($landing, $rwys);
            } elseif (stripos($context, 'DEPARTURE') !== false) {
                $departing = array_merge($departing, $rwys);
            } else {
                $landing = array_merge($landing, $rwys);
                $departing = array_merge($departing, $rwys);
            }
        }
    }

    // Pattern 5: Australian bracket format [RWY] 11
    if (preg_match_all('/\[RWY\]\s*'.$rwyNum.'/i', $text, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
        foreach ($m as $match) {
            $rwys = extractRunwayNumbers($match[1][0]);
            $context = substr($text, max(0, $match[0][1] - 50), 50);

            if (stripos($context, 'ARR') !== false || stripos($context, 'APCH') !== false) {
                $landing = array_merge($landing, $rwys);
            } elseif (stripos($context, 'DEP') !== false) {
                $departing = array_merge($departing, $rwys);
            } else {
                $landing = array_merge($landing, $rwys);
                $departing = array_merge($departing, $rwys);
            }
        }
    }

    // Pattern 6: Vietnamese "LDG RWY XX AND DPTG RWY YY"
    if (preg_match_all('/LDG\s+(?:RWY\s+)?'.$rwyNum.'\s+AND\s+DPT?G\s+(?:RWY\s+)?'.$rwyNum.'/i', $text, $m)) {
        for ($i = 0; $i < count($m[0]); $i++) {
            $landing = array_merge($landing, extractRunwayNumbers($m[1][$i]));
            $departing = array_merge($departing, extractRunwayNumbers($m[2][$i]));
        }
    }

    // Pattern 7: Middle East "ARRDEP RWY"
    if (preg_match_all('/ARR\s*DEP\s+(?:RWY\s*)?'.$rwyList.'/i', $text, $m)) {
        foreach ($m[1] as $rwyText) {
            $rwys = extractRunwayNumbers($rwyText);
            $landing = array_merge($landing, $rwys);
            $departing = array_merge($departing, $rwys);
        }
    }

    // Pattern 8: SIMUL ... IN USE RWY
    if (preg_match_all('/SIMUL(?:TANEOUS)?\s+(?:VIS\s+)?(?:APCHS?|APPROACHES?|DEPS?|DEPARTURES?)\s+IN\s+USE\s+(?:RWY\s+)?'.$rwyList.'/i', $text, $m, PREG_SET_ORDER)) {
        foreach ($m as $match) {
            $rwys = extractRunwayNumbers($match[1]);
            if (stripos($match[0], 'APCH') !== false || stripos($match[0], 'APPROACH') !== false) {
                $landing = array_merge($landing, $rwys);
            } elseif (stripos($match[0], 'DEP') !== false) {
                $departing = array_merge($departing, $rwys);
            } else {
                $landing = array_merge($landing, $rwys);
                $departing = array_merge($departing, $rwys);
            }
        }
    }

    // Pattern 9: EXPECT RADAR VECTORS RWY
    if (preg_match_all('/EXPECT\s+(?:RADAR\s+)?VECTORS?\s+(?:FOR\s+)?.*?RWY\s+'.$rwyNum.'/i', $text, $m)) {
        $landing = array_merge($landing, extractRunwayNumbers($m[1][0] ?? ''));
    }

    // Pattern 10: EXPECT ... APPROACH RUNWAY
    if (preg_match_all('/EXPECT\s+.*?APPROACH\s+RUNWAY\s+'.$rwyList.'/i', $text, $m)) {
        foreach ($m[1] as $rwyText) {
            $landing = array_merge($landing, extractRunwayNumbers($rwyText));
        }
    }

    // Pattern 11: DEPARTURE RUNWAY XX
    if (preg_match_all('/DEPARTURES?\s+RUNWAY\s+'.$rwyNum.'/i', $text, $m)) {
        foreach ($m[1] as $rwy) {
            $departing = array_merge($departing, extractRunwayNumbers($rwy));
        }
    }

    // Pattern 12: Japanese style - "RWY32L" (no space), "USING RWY 32L/32R"
    if (preg_match_all('/(?:USING|USE)\s+RWYS?\s*'.$rwyList.'/i', $text, $m)) {
        foreach ($m[1] as $rwyText) {
            $rwys = extractRunwayNumbers($rwyText);
            // Check context for arrival/departure
            $landing = array_merge($landing, $rwys);
        }
    }

    // Pattern 13: ILS/approach with no space before runway - "(APCH)ILS RWY32L"
    if (preg_match_all('/(?:APCH|APPROACH).*?(?:ILS|RNAV|VOR)\s+RWY\s*'.$rwyNum.'/i', $text, $m)) {
        $landing = array_merge($landing, extractRunwayNumbers($m[1][0] ?? ''));
    }

    // Pattern 14: European "APCH RWY IN USE XX" or "ILS DME APCH RWY IN USE XX"
    if (preg_match_all('/(?:ILS|RNAV|VOR|DME)?\s*APCHS?\s+RWYS?\s+IN\s+USE\s+'.$rwyList.'/i', $text, $m)) {
        foreach ($m[1] as $rwyText) {
            $landing = array_merge($landing, extractRunwayNumbers($rwyText));
        }
    }

    // Pattern 15: Generic "RWY IN USE XX" without prefix
    if (preg_match_all('/\bRWYS?\s+IN\s+USE\s+'.$rwyList.'/i', $text, $m)) {
        foreach ($m[1] as $rwyText) {
            $rwys = extractRunwayNumbers($rwyText);
            // If no specific landing/departing found yet, assume both
            if (empty($landing) && empty($departing)) {
                $landing = array_merge($landing, $rwys);
                $departing = array_merge($departing, $rwys);
            }
        }
    }

    // Pattern 16: "FOR ILS ... APPROACH RUNWAY XX" (Polish style)
    if (preg_match_all('/FOR\s+(?:ILS|RNAV|VOR).*?APPROACH\s+RUNWAY\s+'.$rwyNum.'/i', $text, $m)) {
        foreach ($m[1] as $rwy) {
            $landing = array_merge($landing, extractRunwayNumbers($rwy));
        }
    }

    // Pattern 17: Simple "ARRIVAL ... RUNWAY XX" or "ARRIVAL RWY XX"
    if (preg_match_all('/ARRIVALS?\s+(?:FOR\s+)?(?:.*?\s+)?RUNWAY\s+'.$rwyNum.'/i', $text, $m)) {
        foreach ($m[1] as $rwy) {
            $landing = array_merge($landing, extractRunwayNumbers($rwy));
        }
    }

    // Extract approach types
    if (preg_match_all('/(ILS|RNAV|GPS|RNP|VISUAL|VOR|NDB|LOC|LDA)\s+(?:APPROACH(?:ES)?\s+)?(?:RWY\s+)?'.$rwyNum.'/i', $text, $m)) {
        for ($i = 0; $i < count($m[0]); $i++) {
            $type = strtoupper($m[1][$i]);
            $rwy = normalizeRunway($m[2][$i]);
            if (!isset($approaches[$rwy])) {
                $approaches[$rwy] = [];
            }
            if (!in_array($type, $approaches[$rwy])) {
                $approaches[$rwy][] = $type;
            }
        }
    }

    // Dedupe and sort
    $landing = array_values(array_unique($landing));
    $departing = array_values(array_unique($departing));
    sort($landing);
    sort($departing);

    return [
        'landing' => $landing,
        'departing' => $departing,
        'approaches' => $approaches
    ];
}

/**
 * Filter ATIS text to remove METAR data.
 */
function filterAtisText(string $text): string {
    $text = strtoupper($text);

    // Remove inline METAR markers (but not header METAR)
    $text = preg_replace('/\.\s*(?:METAR|SPECI)\s+\d{6}Z.*$/i', ' ', $text);
    $text = preg_replace('/\s+RMK\s+.*/i', ' ', $text);

    // Remove weather elements
    $text = preg_replace('/\b[AQ]\s*\d{4}\b/', '', $text);  // Altimeter
    $text = preg_replace('/\bM?\d{2}\/M?\d{2}\b/', '', $text);  // Temp/dew
    $text = preg_replace('/\bP?\d+SM\b/', '', $text);  // Visibility
    $text = preg_replace('/\b(?:VRB|\d{3})\d{2}(?:G\d{2})?KT\b/', '', $text);  // Wind

    return $text;
}

/**
 * Extract runway numbers from text.
 */
function extractRunwayNumbers(string $text): array {
    $runways = [];
    $text = strtoupper(trim($text));
    $text = preg_replace('/^(?:RWYS?|RUNWAYS?)\s*/', '', $text);

    // Split on separators (AND, comma, slash, ampersand, or multiple spaces)
    $parts = preg_split('/\s*(?:AND|,|\/|&)\s*|\s{2,}/', $text);
    $lastNumber = null;

    foreach ($parts as $part) {
        $part = trim($part);
        if (empty($part)) continue;

        // Handle space-separated runways within a part (e.g., "27 36")
        $subParts = preg_split('/\s+/', $part);
        foreach ($subParts as $subPart) {
            $subPart = trim($subPart);
            if (empty($subPart)) continue;

            if (preg_match('/^([0-3]?\d)\s*(L(?:EFT)?|R(?:IGHT)?|C(?:ENTER)?)?$/', $subPart, $m)) {
                $runway = normalizeRunway($m[1] . ($m[2] ?? ''));
                $runways[] = $runway;
                $lastNumber = $m[1];
            } elseif ($lastNumber && preg_match('/^(L(?:EFT)?|R(?:IGHT)?|C(?:ENTER)?)$/', $subPart, $m)) {
                // Handle "17R AND LEFT" -> 17R and 17L
                $runway = normalizeRunway($lastNumber . $m[1]);
                $runways[] = $runway;
            }
        }
    }

    return $runways;
}

/**
 * Normalize runway designator.
 */
function normalizeRunway(string $rwy): string {
    $rwy = strtoupper(trim($rwy));

    if (preg_match('/^([0-3]?\d)\s*(L(?:EFT)?|R(?:IGHT)?|C(?:ENTER)?)?$/', $rwy, $m)) {
        $num = str_pad($m[1], 2, '0', STR_PAD_LEFT);
        $suffix = '';
        if (!empty($m[2])) {
            $s = strtoupper($m[2]);
            if ($s[0] === 'L') $suffix = 'L';
            elseif ($s[0] === 'R') $suffix = 'R';
            elseif ($s[0] === 'C') $suffix = 'C';
        }
        return $num . $suffix;
    }

    return $rwy;
}

/**
 * Format runway summary string.
 */
function formatRunwaySummary(array $landing, array $departing): string {
    $landStr = !empty($landing) ? implode('/', $landing) : '-';
    $depStr = !empty($departing) ? implode('/', $departing) : '-';
    return "L:{$landStr} D:{$depStr}";
}

/**
 * Convert parsed runways to JSON for database import.
 */
function runwaysToJson(array $landing, array $departing, array $approaches = []): string {
    $all = array_unique(array_merge($landing, $departing));
    $records = [];

    foreach ($all as $rwy) {
        $isLanding = in_array($rwy, $landing);
        $isDeparting = in_array($rwy, $departing);

        $use = 'BOTH';
        if ($isLanding && !$isDeparting) $use = 'ARR';
        elseif (!$isLanding && $isDeparting) $use = 'DEP';

        $approachType = $approaches[$rwy][0] ?? null;

        $records[] = [
            'runway_id' => $rwy,
            'runway_use' => $use,
            'approach_type' => $approachType
        ];
    }

    return json_encode($records);
}
