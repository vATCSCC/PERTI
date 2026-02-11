<?php
/**
 * NOD Flow Elements API
 *
 * GET    - List elements for config, or get single element
 * POST   - Create new element
 * PUT    - Update element
 * DELETE - Delete element
 */

header('Content-Type: application/json');

$config_path = realpath(__DIR__ . '/../../../load/config.php');
$connect_path = realpath(__DIR__ . '/../../../load/connect.php');

if ($config_path) include($config_path);
if ($connect_path) include($connect_path);

require_once(__DIR__ . '/../../../load/services/GISService.php');

$conn = get_conn_adl();
if (!$conn) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection not available']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    switch ($method) {
        case 'GET':
            handleGet($conn);
            break;
        case 'POST':
            handlePost($conn);
            break;
        case 'PUT':
            handlePut($conn);
            break;
        case 'DELETE':
            handleDelete($conn);
            break;
        default:
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}

/**
 * Format a DateTime value from sqlsrv to ISO 8601 string
 */
function formatDateTime($val) {
    if ($val instanceof \DateTime) return $val->format('Y-m-d\TH:i:s\Z');
    return $val;
}

/**
 * Format an element row for JSON output
 */
function formatElement($row) {
    $dateFields = ['created_at', 'updated_at'];
    foreach ($dateFields as $field) {
        if (isset($row[$field])) {
            $row[$field] = formatDateTime($row[$field]);
        }
    }
    if (isset($row['route_geojson']) && is_string($row['route_geojson'])) {
        $row['route_geojson'] = json_decode($row['route_geojson'], true);
    }
    return $row;
}

/**
 * Resolve fix lat/lon from nav_fixes by fix_name.
 * Prefers CONUS fixes and points.csv source to avoid picking
 * international duplicates (e.g., ENO in Argentina vs New Jersey).
 */
function resolveFixLatLon($conn, $fixName) {
    if (!$fixName) return [null, null];

    $sql = "SELECT TOP 1 lat, lon FROM dbo.nav_fixes WHERE fix_name = ?
            ORDER BY CASE WHEN lat BETWEEN 24.0 AND 50.0 AND lon BETWEEN -130.0 AND -65.0 THEN 0 ELSE 1 END,
                     CASE source WHEN 'points.csv' THEN 0 WHEN 'navaids.csv' THEN 1 ELSE 2 END";
    $stmt = sqlsrv_query($conn, $sql, [$fixName]);
    if ($stmt === false) return [null, null];

    $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    sqlsrv_free_stmt($stmt);

    if ($row) {
        return [floatval($row['lat']), floatval($row['lon'])];
    }
    return [null, null];
}

/**
 * Expand a route string by resolving airways into their intermediate fixes.
 * Same algorithm as ConvertRoute() in route-maplibre.js, using the airways
 * table instead of the client-side awys[] array.
 *
 * "COATE Q436 RAAKK" -> "COATE [intermediate fixes on Q436] RAAKK"
 */
function expandRouteAirways($conn, $tokens) {
    if (count($tokens) < 3) return $tokens;

    // Identify potential airway tokens (middle tokens matching airway patterns)
    $potentialAirways = [];
    for ($i = 1; $i < count($tokens) - 1; $i++) {
        if (preg_match('/^[A-Z]{1,2}[0-9]{1,4}$/', $tokens[$i])) {
            $potentialAirways[] = $tokens[$i];
        }
    }

    if (empty($potentialAirways)) return $tokens;

    // Batch-fetch airway fix sequences
    $placeholders = implode(',', array_fill(0, count($potentialAirways), '?'));
    $sql = "SELECT airway_name, fix_sequence FROM dbo.airways WHERE airway_name IN ($placeholders)";
    $stmt = sqlsrv_query($conn, $sql, $potentialAirways);
    if ($stmt === false) return $tokens;

    $airwayMap = [];
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $fixes = array_filter(explode(' ', trim($row['fix_sequence'])));
        $fixPosMap = [];
        foreach (array_values($fixes) as $idx => $fix) {
            if (!isset($fixPosMap[$fix])) $fixPosMap[$fix] = $idx;
        }
        $airwayMap[$row['airway_name']] = [
            'fixes' => array_values($fixes),
            'fixPosMap' => $fixPosMap,
        ];
    }
    sqlsrv_free_stmt($stmt);

    if (empty($airwayMap)) return $tokens;

    // Expand airways (mirrors ConvertRoute logic)
    $expanded = [];
    for ($i = 0; $i < count($tokens); $i++) {
        $token = $tokens[$i];

        if ($i > 0 && $i < count($tokens) - 1 && isset($airwayMap[$token])) {
            $prev = $tokens[$i - 1];
            $next = $tokens[$i + 1];
            $awy = $airwayMap[$token];

            $fromIdx = $awy['fixPosMap'][$prev] ?? null;
            $toIdx = $awy['fixPosMap'][$next] ?? null;

            if ($fromIdx !== null && $toIdx !== null && abs($fromIdx - $toIdx) > 1) {
                if ($fromIdx < $toIdx) {
                    $middle = array_slice($awy['fixes'], $fromIdx + 1, $toIdx - $fromIdx - 1);
                } else {
                    $middle = array_reverse(array_slice($awy['fixes'], $toIdx + 1, $fromIdx - $toIdx - 1));
                }
                array_push($expanded, ...$middle);
                continue;
            }
        }
        $expanded[] = $token;
    }

    return $expanded;
}

/**
 * Expand CDR (Coded Departure Route) codes in route tokens.
 * If a token matches a cdr_code in coded_departure_routes, replace it
 * with the tokenized full_route.
 *
 * ["JFKMIA1"] -> ["KJFK", "MERIT", "J584", ..., "KMIA"]
 * ["ABECLTGV"] -> ["KABE", "LRP", "EMI", "GVE", "AIROW", "CHSLY6", "KCLT"]
 * ["ENO", "V213", "DAVYS"] -> unchanged (no CDR matches)
 */
function expandCDRTokens($conn, $tokens) {
    if (empty($tokens)) return $tokens;

    // Strip mandatory markers (<>) for lookup
    $cleanTokens = array_map(function($t) {
        return preg_replace('/[<>]/', '', $t);
    }, $tokens);

    // Collect unique tokens for batch lookup
    $unique = array_values(array_unique($cleanTokens));
    if (empty($unique)) return $tokens;

    $placeholders = implode(',', array_fill(0, count($unique), '?'));
    $sql = "SELECT cdr_code, full_route FROM dbo.coded_departure_routes
            WHERE cdr_code IN ($placeholders) AND is_active = 1";
    $stmt = sqlsrv_query($conn, $sql, $unique);
    if ($stmt === false) return $tokens;

    $cdrMap = [];
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $cdrMap[$row['cdr_code']] = trim($row['full_route']);
    }
    sqlsrv_free_stmt($stmt);

    if (empty($cdrMap)) return $tokens;

    // Replace matching tokens with expanded route tokens
    $expanded = [];
    foreach ($cleanTokens as $token) {
        if (isset($cdrMap[$token])) {
            $routeTokens = preg_split('/\s+/', strtoupper($cdrMap[$token]));
            $routeTokens = array_values(array_filter($routeTokens, function($t) {
                return $t !== '';
            }));
            array_push($expanded, ...$routeTokens);
        } else {
            $expanded[] = $token;
        }
    }

    return $expanded;
}

/**
 * Expand playbook route play names.
 * Only applies when the route is a single token matching a play_name.
 * Supports dot notation: PLAY_NAME.ORIGIN.DEST
 *
 * ["BURNN1_NORTH"] -> ["KJFK", "MERIT", ...]
 * ["ABI_old_2601.KBWI.KBUR"] -> ["KBWI", "TERPZ8.MAULS", "Q40", ...]
 */
function expandPlaybookTokens($conn, $tokens) {
    // Only expand single-token routes (a bare playbook name)
    if (empty($tokens) || count($tokens) > 1) return $tokens;

    $token = $tokens[0];

    // Parse dot notation: PLAY_NAME or PLAY_NAME.ORIGIN or PLAY_NAME.ORIGIN.DEST
    // But don't treat SID/STAR notation (short.short) as playbook
    $parts = explode('.', $token);
    $playPart = $parts[0];

    // If the first part is short (<=5 chars), it's likely a SID/STAR transition, not a playbook
    if (strlen($playPart) <= 5 && count($parts) > 1) return $tokens;

    $originFilter = count($parts) > 1 ? strtoupper($parts[1]) : null;
    $destFilter = count($parts) > 2 ? strtoupper($parts[2]) : null;

    // Try exact match first
    $sql = "SELECT TOP 5 play_name, full_route, origin_airports, dest_airports
            FROM dbo.playbook_routes
            WHERE play_name = ? AND is_active = 1
            ORDER BY playbook_id";
    $stmt = sqlsrv_query($conn, $sql, [$playPart]);
    if ($stmt === false) return $tokens;

    $candidates = [];
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $candidates[] = $row;
    }
    sqlsrv_free_stmt($stmt);

    // If no exact match, try normalized match (strip non-alphanumeric)
    if (empty($candidates)) {
        $playNorm = preg_replace('/[^A-Z0-9]/', '', strtoupper($playPart));
        $sql = "SELECT TOP 5 play_name, full_route, origin_airports, dest_airports
                FROM dbo.playbook_routes
                WHERE UPPER(REPLACE(REPLACE(REPLACE(play_name, '_', ''), '-', ''), ' ', '')) = ?
                AND is_active = 1
                ORDER BY playbook_id";
        $stmt = sqlsrv_query($conn, $sql, [$playNorm]);
        if ($stmt === false) return $tokens;

        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $candidates[] = $row;
        }
        sqlsrv_free_stmt($stmt);
    }

    if (empty($candidates)) return $tokens;

    // Filter by origin/dest if provided
    $match = null;
    if ($originFilter || $destFilter) {
        foreach ($candidates as $cand) {
            if ($originFilter) {
                $origins = preg_split('/[\s,]+/', strtoupper($cand['origin_airports'] ?? ''));
                $origins = array_filter($origins);
                // Normalize 3-letter code to K-prefix ICAO
                $originNorm = (strlen($originFilter) === 3 && $originFilter[0] !== 'Z')
                    ? 'K' . $originFilter : $originFilter;
                if (!in_array($originFilter, $origins) && !in_array($originNorm, $origins)) {
                    continue;
                }
            }
            if ($destFilter) {
                $dests = preg_split('/[\s,]+/', strtoupper($cand['dest_airports'] ?? ''));
                $dests = array_filter($dests);
                $destNorm = (strlen($destFilter) === 3 && $destFilter[0] !== 'Z')
                    ? 'K' . $destFilter : $destFilter;
                if (!in_array($destFilter, $dests) && !in_array($destNorm, $dests)) {
                    continue;
                }
            }
            $match = $cand;
            break;
        }
    }

    // Use first candidate if no filtered match
    if (!$match) $match = $candidates[0];

    $routeTokens = preg_split('/\s+/', strtoupper(trim($match['full_route'])));
    $routeTokens = array_values(array_filter($routeTokens, function($t) {
        return $t !== '';
    }));

    return !empty($routeTokens) ? $routeTokens : $tokens;
}

/**
 * Pre-process route tokens: strip SID/STAR procedure notation,
 * remove airport codes (4-letter ICAO at start/end), and normalize.
 *
 * "SIE.CAMRN3 MERIT J584 YNKEE" -> ["SIE", "MERIT", "J584", "YNKEE"]
 * "KAYLN3.SMUUV MERIT"          -> ["SMUUV", "MERIT"]
 * "KJFK MERIT J80 SIE KMIA"     -> ["MERIT", "J80", "SIE"]
 */
function preprocessRouteTokens($tokens) {
    $result = [];

    foreach ($tokens as $i => $token) {
        // Skip 4-letter ICAO airport codes at start/end of route
        if (preg_match('/^K[A-Z]{3}$/', $token) && ($i === 0 || $i === count($tokens) - 1)) {
            continue;
        }

        // Handle SID/STAR dot notation (transition.procedure or procedure.transition)
        if (strpos($token, '.') !== false) {
            $parts = explode('.', $token, 2);
            $clean0 = preg_replace('/[0-9#]+$/', '', $parts[0]);
            $clean1 = preg_replace('/[0-9#]+$/', '', $parts[1]);
            $hasNum0 = preg_match('/[0-9#]+$/', $parts[0]);
            $hasNum1 = preg_match('/[0-9#]+$/', $parts[1]);

            // The part WITH trailing digits is the procedure name.
            // The part WITHOUT is the transition fix we want to keep.
            // SIE.CAMRN3 -> CAMRN3 has digits -> keep SIE (transition)
            // KAYLN3.SMUUV -> KAYLN3 has digits -> keep SMUUV (transition)
            // TERPZ8.MAULS -> TERPZ8 has digits -> keep MAULS (transition)
            if ($hasNum1 && !$hasNum0 && strlen($clean0) <= 5 && preg_match('/^[A-Z]+$/', $clean0)) {
                $result[] = $clean0; // TRANSITION.STAR# -> keep transition
            } elseif ($hasNum0 && !$hasNum1 && strlen($clean1) <= 5 && preg_match('/^[A-Z]+$/', $clean1)) {
                $result[] = $clean1; // DP#.TRANSITION -> keep transition
            } elseif (strlen($clean0) <= 5 && preg_match('/^[A-Z]+$/', $clean0)) {
                $result[] = $clean0; // Ambiguous: prefer part[0]
            } elseif (strlen($clean1) <= 5 && preg_match('/^[A-Z]+$/', $clean1)) {
                $result[] = $clean1;
            }
            continue;
        }

        // Strip trailing # or numeric suffix from standalone procedure names
        // But preserve fix names that happen to end in digits (these are short, like ENO)
        if (preg_match('/^[A-Z]{3,}[0-9#]+$/', $token) && strlen($token) > 5) {
            // Likely a procedure name (CAMRN3, RPTOR1) - skip it
            continue;
        }

        $result[] = $token;
    }

    return $result;
}

/**
 * Resolve a route string into a GeoJSON LineString.
 * Handles CDRs, playbook routes, airways, SIDs/STARs, international
 * airways/procedures, and proper global fix disambiguation.
 *
 * Uses PostGIS GISService for full route expansion (handles international
 * routes, proper fix disambiguation, etc.), with ADL-only fallback if
 * the GIS connection is unavailable.
 *
 * CDR and playbook expansion still use Azure SQL (ADL) since the PostGIS
 * functions don't handle those.
 */
function resolveRouteGeojson($conn, $routeString) {
    if (!$routeString) return null;

    // Tokenize: split on spaces, strip mandatory markers, filter empty/long tokens
    $routeString = str_replace(['<', '>'], '', $routeString);
    $tokens = preg_split('/\s+/', trim(strtoupper($routeString)));
    $tokens = array_values(array_filter($tokens, function($t) {
        return trim($t) !== '' && strlen(trim($t)) <= 64;
    }));

    if (empty($tokens)) return null;

    // CDR expansion: replace coded departure route codes with full routes
    // (GISService doesn't handle CDR codes)
    $tokens = expandCDRTokens($conn, $tokens);

    // Playbook expansion: replace single-token play names with full routes
    // (GISService doesn't handle playbook names)
    $tokens = expandPlaybookTokens($conn, $tokens);

    if (count($tokens) < 2) return null;

    // Rejoin into a route string for GIS processing
    $expandedRouteString = implode(' ', $tokens);

    // Primary: Use PostGIS GISService for full route expansion
    // Handles international airways, procedures, global fix disambiguation
    $gis = GISService::getInstance();
    if ($gis) {
        $result = $gis->expandRoute($expandedRouteString);
        if ($result && !empty($result['geojson'])) {
            $geojson = $result['geojson'];
            // expandRoute returns a parsed GeoJSON geometry object
            if (is_array($geojson) && !empty($geojson['coordinates'])) {
                return json_encode($geojson);
            }
        }
    } else {
        error_log('[NOD Flows] GIS service unavailable, falling back to ADL-only route resolution');
    }

    // Fallback: ADL-only processing (if GIS unavailable or failed)
    return resolveRouteGeojsonADL($conn, $tokens);
}

/**
 * Fallback route resolution using only Azure SQL (ADL).
 * Used when PostGIS is unavailable. Handles CONUS routes well
 * but has limited international support.
 */
function resolveRouteGeojsonADL($conn, $tokens) {
    // Preprocess: strip SID/STAR notation, airport codes
    $tokens = preprocessRouteTokens($tokens);
    if (count($tokens) < 2) return null;

    // Expand airways into intermediate fixes
    $expanded = expandRouteAirways($conn, $tokens);

    // Dedupe for the SQL query while preserving order
    $unique = array_values(array_unique($expanded));
    if (empty($unique)) return null;

    // Batch-resolve fix coordinates, preferring CONUS
    $placeholders = implode(',', array_fill(0, count($unique), '?'));
    $sql = ";WITH ranked AS (
                SELECT fix_name, lat, lon,
                    ROW_NUMBER() OVER (
                        PARTITION BY fix_name
                        ORDER BY
                            CASE WHEN lat BETWEEN 24.0 AND 50.0 AND lon BETWEEN -130.0 AND -65.0 THEN 0 ELSE 1 END,
                            CASE source WHEN 'points.csv' THEN 0 WHEN 'navaids.csv' THEN 1 ELSE 2 END
                    ) AS rn
                FROM dbo.nav_fixes
                WHERE fix_name IN ($placeholders)
            )
            SELECT fix_name, lat, lon FROM ranked WHERE rn = 1";
    $stmt = sqlsrv_query($conn, $sql, $unique);
    if ($stmt === false) return null;

    $fixMap = [];
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $fixMap[$row['fix_name']] = [floatval($row['lon']), floatval($row['lat'])];
    }
    sqlsrv_free_stmt($stmt);

    // Build coordinates in route order, with distance validation
    $coords = [];
    $prevCoord = null;
    foreach ($expanded as $token) {
        if (!isset($fixMap[$token])) continue;

        $coord = $fixMap[$token];

        // Distance validation: reject fixes too far from the route path
        if ($prevCoord !== null) {
            $dlat = abs($coord[1] - $prevCoord[1]);
            $dlon = abs($coord[0] - $prevCoord[0]);
            if ($dlat + $dlon > 40) continue;
        }

        $coords[] = $coord;
        $prevCoord = $coord;
    }

    if (count($coords) < 2) return null;

    return json_encode([
        'type' => 'LineString',
        'coordinates' => $coords,
    ]);
}

/**
 * Search nav_procedures for a matching procedure.
 * Options:
 *   transition       - filter by transition_name
 *   routeStartsWith  - filter by full_route starting with this fix
 *   preferShortest   - order by shortest full_route (most specific)
 */
function findProcedure($conn, $procNameClean, $airportIcao = null, $transition = null, $options = []) {
    $sql = "SELECT TOP 1 procedure_id, procedure_type, procedure_name, computer_code, full_route
            FROM dbo.nav_procedures
            WHERE (procedure_name LIKE ? OR computer_code LIKE ?)";
    $params = [$procNameClean . '%', $procNameClean . '%'];

    if ($airportIcao) {
        $sql .= " AND airport_icao = ?";
        $params[] = $airportIcao;
    }

    if ($transition) {
        $transClean = preg_replace('/[0-9#]+$/', '', $transition);
        $sql .= " AND (transition_name = ? OR transition_name LIKE ?)";
        $params[] = $transClean;
        $params[] = $transClean . '%';
    }

    // Filter by full_route starting with a specific fix
    if (!empty($options['routeStartsWith'])) {
        $sql .= " AND full_route LIKE ?";
        $params[] = $options['routeStartsWith'] . ' %';
    }

    // Order: prefer exact name match, then shortest route (most specific)
    $sql .= " ORDER BY CASE WHEN procedure_name = ? THEN 0 WHEN computer_code = ? THEN 0 ELSE 1 END,
              LEN(ISNULL(full_route, '')) ASC";
    $params[] = $procNameClean;
    $params[] = $procNameClean;

    $stmt = sqlsrv_query($conn, $sql, $params);
    if ($stmt === false) return null;

    $proc = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    sqlsrv_free_stmt($stmt);

    return $proc ?: null;
}

/**
 * Resolve a PROCEDURE element into a GeoJSON LineString.
 * Accepts formats like:
 *   "SIE.CAMRN3" (transition.STAR)
 *   "CAMRN3" (just STAR name, no transition)
 *   "KAYLN3.SMUUV" (DP.transition)
 *   "ENO.PROUD# KLGA" (transition.STAR airport — airport extracted by caller)
 *
 * Looks up nav_procedures + nav_procedure_legs to build the route.
 */
function resolveProcedureGeojson($conn, $procInput, $airportIcao = null) {
    if (!$procInput) return null;

    $procInput = trim(strtoupper($procInput));
    $transition = null;
    $procName = $procInput;

    // Parse dot notation
    if (strpos($procInput, '.') !== false) {
        $parts = explode('.', $procInput, 2);
        // Could be TRANSITION.STAR or DP.TRANSITION
        $transition = $parts[0];
        $procName = $parts[1];
    }

    // Strip trailing # and digits from procedure names (CAMRN# -> CAMRN, PROUD5 -> PROUD)
    $procNameClean = preg_replace('/[0-9#]+$/', '', $procName);
    if (empty($procNameClean)) $procNameClean = $procName;

    // Also try with transition as the procedure name (dot notation may be reversed)
    $transitionClean = $transition ? preg_replace('/[0-9#]+$/', '', $transition) : null;

    $proc = findProcedure($conn, $procNameClean, $airportIcao, $transition);

    // Retry: try with transition as the procedure name (reversed notation)
    if (!$proc && $transitionClean && $transitionClean !== $procNameClean) {
        $proc = findProcedure($conn, $transitionClean, $airportIcao, $procNameClean);
    }

    // Retry: search by full_route starting with the transition fix (e.g., ENO.PROUD# → route starts with ENO)
    if (!$proc && $transitionClean) {
        $proc = findProcedure($conn, $procNameClean, $airportIcao, null, ['routeStartsWith' => $transitionClean]);
    }

    // Retry: search without any transition filter, prefer shortest route
    if (!$proc && $transition) {
        $proc = findProcedure($conn, $procNameClean, $airportIcao, null);
    }

    if (!$proc) {
        error_log("[NOD Flows] Procedure not found: {$procInput}" . ($airportIcao ? " at {$airportIcao}" : ''));
        return null;
    }

    // If the procedure has a full_route, resolve it like a route string
    if (!empty($proc['full_route'])) {
        return resolveRouteGeojson($conn, $proc['full_route']);
    }

    // Fall back to procedure legs
    $sql = "SELECT fix_name, sequence_num FROM dbo.nav_procedure_legs
            WHERE procedure_id = ? AND fix_name IS NOT NULL
            ORDER BY sequence_num ASC";
    $stmt = sqlsrv_query($conn, $sql, [$proc['procedure_id']]);
    if ($stmt === false) return null;

    $fixNames = [];
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        if (!empty($row['fix_name'])) {
            $fixNames[] = $row['fix_name'];
        }
    }
    sqlsrv_free_stmt($stmt);

    if (count($fixNames) < 2) return null;

    // Resolve fix coordinates
    $unique = array_values(array_unique($fixNames));
    $placeholders = implode(',', array_fill(0, count($unique), '?'));
    $sql = ";WITH ranked AS (
                SELECT fix_name, lat, lon,
                    ROW_NUMBER() OVER (
                        PARTITION BY fix_name
                        ORDER BY
                            CASE WHEN lat BETWEEN 24.0 AND 50.0 AND lon BETWEEN -130.0 AND -65.0 THEN 0 ELSE 1 END,
                            CASE source WHEN 'points.csv' THEN 0 WHEN 'navaids.csv' THEN 1 ELSE 2 END
                    ) AS rn
                FROM dbo.nav_fixes
                WHERE fix_name IN ($placeholders)
            )
            SELECT fix_name, lat, lon FROM ranked WHERE rn = 1";
    $stmt = sqlsrv_query($conn, $sql, $unique);
    if ($stmt === false) return null;

    $fixMap = [];
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $fixMap[$row['fix_name']] = [floatval($row['lon']), floatval($row['lat'])];
    }
    sqlsrv_free_stmt($stmt);

    $coords = [];
    foreach ($fixNames as $name) {
        if (isset($fixMap[$name])) {
            $coords[] = $fixMap[$name];
        }
    }

    if (count($coords) < 2) return null;

    return json_encode([
        'type' => 'LineString',
        'coordinates' => $coords,
    ]);
}

/**
 * Format SQL Server errors for display
 */
function formatSqlError($errors) {
    if (!$errors) return 'Unknown database error';
    $messages = [];
    foreach ($errors as $error) {
        $messages[] = $error['message'] ?? $error[2] ?? 'Unknown error';
    }
    return implode('; ', $messages);
}

/**
 * GET - List elements for config or get single element.
 * Uses OUTER APPLY instead of LEFT JOIN to avoid duplicate rows
 * when fix_name has multiple entries in nav_fixes.
 */
function handleGet($conn) {
    $element_id = isset($_GET['element_id']) ? intval($_GET['element_id']) : null;
    $config_id = isset($_GET['config_id']) ? intval($_GET['config_id']) : null;

    // Single element
    if ($element_id) {
        $sql = "SELECT e.*, nf.lat AS fix_lat, nf.lon AS fix_lon
                FROM dbo.facility_flow_elements e
                OUTER APPLY (
                    SELECT TOP 1 lat, lon FROM dbo.nav_fixes
                    WHERE e.element_type = 'FIX' AND fix_name = e.fix_name
                    ORDER BY CASE WHEN lat BETWEEN 24.0 AND 50.0 AND lon BETWEEN -130.0 AND -65.0 THEN 0 ELSE 1 END,
                             CASE source WHEN 'points.csv' THEN 0 WHEN 'navaids.csv' THEN 1 ELSE 2 END
                ) nf
                WHERE e.element_id = ?";
        $stmt = sqlsrv_query($conn, $sql, [$element_id]);
        if ($stmt === false) {
            throw new Exception(formatSqlError(sqlsrv_errors()));
        }

        $element = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        if (!$element) {
            http_response_code(404);
            echo json_encode(['error' => 'Element not found']);
            return;
        }

        echo json_encode(['element' => formatElement($element)]);
        return;
    }

    // List elements for config
    if (!$config_id) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing required parameter: config_id or element_id']);
        return;
    }

    $sql = "SELECT e.*, nf.lat AS fix_lat, nf.lon AS fix_lon
            FROM dbo.facility_flow_elements e
            OUTER APPLY (
                SELECT TOP 1 lat, lon FROM dbo.nav_fixes
                WHERE e.element_type = 'FIX' AND fix_name = e.fix_name
                ORDER BY CASE WHEN lat BETWEEN 24.0 AND 50.0 AND lon BETWEEN -130.0 AND -65.0 THEN 0 ELSE 1 END,
                         CASE source WHEN 'points.csv' THEN 0 WHEN 'navaids.csv' THEN 1 ELSE 2 END
            ) nf
            WHERE e.config_id = ?
            ORDER BY e.sort_order ASC, e.element_id ASC";
    $stmt = sqlsrv_query($conn, $sql, [$config_id]);
    if ($stmt === false) {
        throw new Exception(formatSqlError(sqlsrv_errors()));
    }

    $elements = [];
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $elements[] = formatElement($row);
    }

    echo json_encode(['elements' => $elements]);
}

/**
 * POST - Create new element
 */
function handlePost($conn) {
    $input = json_decode(file_get_contents('php://input'), true);

    if (!$input) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid JSON input']);
        return;
    }

    $required = ['config_id', 'element_type', 'element_name'];
    foreach ($required as $field) {
        if (empty($input[$field])) {
            http_response_code(400);
            echo json_encode(['error' => "Missing required field: $field"]);
            return;
        }
    }

    $routeGeojson = null;
    if (isset($input['route_geojson'])) {
        $routeGeojson = is_array($input['route_geojson'])
            ? json_encode($input['route_geojson'])
            : $input['route_geojson'];
    } elseif (strtoupper($input['element_type']) === 'ROUTE' && !empty($input['route_string'])) {
        $routeGeojson = resolveRouteGeojson($conn, $input['route_string']);
        // Fallback: if route contains SID/STAR notation (. or #), try procedure resolution
        if (!$routeGeojson && preg_match('/[.#]/', $input['route_string'])) {
            $parts = preg_split('/\s+/', trim(strtoupper($input['route_string'])));
            $airport = null;
            $procPart = null;
            foreach ($parts as $p) {
                // Pick the token with SID/STAR notation (contains . or #)
                if (preg_match('/[.#]/', $p)) {
                    $procPart = $p;
                } elseif (preg_match('/^K[A-Z]{3}$/', $p) || (preg_match('/^[A-Z]{4}$/', $p) && !$airport)) {
                    $airport = $p;
                }
            }
            if ($procPart) {
                $routeGeojson = resolveProcedureGeojson($conn, $procPart, $airport);
            }
        }
    } elseif (strtoupper($input['element_type']) === 'PROCEDURE') {
        // Resolve procedure into route geometry
        $airportIcao = $input['airport_icao'] ?? null;
        $routeGeojson = resolveProcedureGeojson($conn, $input['element_name'], $airportIcao);
        if (!$routeGeojson) {
            // Fallback: try resolving as a route string (construct from element name + airport)
            $routeStr = $input['route_string'] ?? null;
            if (!$routeStr) {
                $routeStr = $input['element_name'] . ($airportIcao ? ' ' . $airportIcao : '');
            }
            $routeGeojson = resolveRouteGeojson($conn, $routeStr);
        }
    }

    $sql = "INSERT INTO dbo.facility_flow_elements (
                config_id, element_type, element_name, fix_name, procedure_id,
                route_string, route_geojson, direction, gate_id, sort_order,
                color, line_weight, line_style, label_format, icon,
                is_visible, auto_fea
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?);
            SELECT SCOPE_IDENTITY() AS element_id;";

    $params = [
        intval($input['config_id']),
        $input['element_type'],
        $input['element_name'],
        $input['fix_name'] ?? null,
        $input['procedure_id'] ?? null,
        $input['route_string'] ?? null,
        $routeGeojson,
        $input['direction'] ?? 'ARRIVAL',
        isset($input['gate_id']) ? intval($input['gate_id']) : null,
        $input['sort_order'] ?? 0,
        $input['color'] ?? '#17a2b8',
        $input['line_weight'] ?? 2,
        $input['line_style'] ?? 'solid',
        $input['label_format'] ?? null,
        $input['icon'] ?? null,
        $input['is_visible'] ?? 1,
        $input['auto_fea'] ?? 0
    ];

    $stmt = sqlsrv_query($conn, $sql, $params);
    if ($stmt === false) {
        throw new Exception(formatSqlError(sqlsrv_errors()));
    }

    sqlsrv_next_result($stmt);
    $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    $elementId = $row['element_id'] ?? null;

    // Resolve fix lat/lon for FIX type elements
    $fixLat = null;
    $fixLon = null;
    if (strtoupper($input['element_type']) === 'FIX' && !empty($input['fix_name'])) {
        [$fixLat, $fixLon] = resolveFixLatLon($conn, $input['fix_name']);
    }

    $response = [
        'element_id' => intval($elementId),
        'fix_lat' => $fixLat,
        'fix_lon' => $fixLon,
    ];

    // Include resolved route_geojson for ROUTE/PROCEDURE elements
    if ($routeGeojson) {
        $response['route_geojson'] = json_decode($routeGeojson, true);
    } elseif (in_array(strtoupper($input['element_type']), ['ROUTE', 'PROCEDURE'])) {
        $response['resolution_warning'] = 'Could not resolve geometry for this element';
    }

    echo json_encode($response);
}

/**
 * PUT - Update element
 */
function handlePut($conn) {
    $input = json_decode(file_get_contents('php://input'), true);

    if (!$input || !isset($input['element_id'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing element_id']);
        return;
    }

    $elementId = intval($input['element_id']);

    $updates = [];
    $params = [];

    $allowedFields = [
        'config_id', 'element_type', 'element_name', 'fix_name', 'procedure_id',
        'route_string', 'route_geojson', 'direction', 'gate_id', 'sort_order',
        'color', 'line_weight', 'line_style', 'label_format', 'icon',
        'is_visible', 'auto_fea'
    ];

    // If route_string is being updated without explicit route_geojson, auto-resolve
    if (array_key_exists('route_string', $input) && !array_key_exists('route_geojson', $input)) {
        $resolved = resolveRouteGeojson($conn, $input['route_string']);
        if ($resolved) {
            $input['route_geojson'] = $resolved;
        }
    }

    foreach ($allowedFields as $field) {
        if (array_key_exists($field, $input)) {
            $value = $input[$field];
            if ($field === 'route_geojson' && is_array($value)) {
                $value = json_encode($value);
            }
            $updates[] = "$field = ?";
            $params[] = $value;
        }
    }

    if (empty($updates)) {
        http_response_code(400);
        echo json_encode(['error' => 'No fields to update']);
        return;
    }

    $updates[] = "updated_at = GETUTCDATE()";
    $params[] = $elementId;

    $sql = "UPDATE dbo.facility_flow_elements SET " . implode(', ', $updates) . " WHERE element_id = ?";
    $stmt = sqlsrv_query($conn, $sql, $params);
    if ($stmt === false) {
        throw new Exception(formatSqlError(sqlsrv_errors()));
    }

    // Re-resolve fix lat/lon if fix_name was changed
    $fixLat = null;
    $fixLon = null;
    if (array_key_exists('fix_name', $input)) {
        [$fixLat, $fixLon] = resolveFixLatLon($conn, $input['fix_name']);
    }

    echo json_encode([
        'success' => true,
        'fix_lat' => $fixLat,
        'fix_lon' => $fixLon
    ]);
}

/**
 * DELETE - Delete element
 */
function handleDelete($conn) {
    $element_id = isset($_GET['element_id']) ? intval($_GET['element_id']) : null;

    if (!$element_id) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing element_id']);
        return;
    }

    $sql = "DELETE FROM dbo.facility_flow_elements WHERE element_id = ?";
    $stmt = sqlsrv_query($conn, $sql, [$element_id]);
    if ($stmt === false) {
        throw new Exception(formatSqlError(sqlsrv_errors()));
    }

    $affected = sqlsrv_rows_affected($stmt);
    if ($affected === 0) {
        http_response_code(404);
        echo json_encode(['error' => 'Element not found']);
        return;
    }

    echo json_encode(['success' => true]);
}
