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
            // Could be TRANSITION.STAR (e.g., SIE.CAMRN3) or DP.TRANSITION (e.g., KAYLN3.SMUUV)
            $parts = explode('.', $token, 2);
            // Remove trailing # or digits-only suffixes from procedure names
            $clean0 = preg_replace('/[0-9#]+$/', '', $parts[0]);
            $clean1 = preg_replace('/[0-9#]+$/', '', $parts[1]);

            // For a STAR: TRANSITION.STAR -> keep transition fix
            // For a DP: DP.TRANSITION -> keep transition fix
            // Heuristic: if part[0] looks like a fix (all alpha, <= 5 chars), use it as the fix
            // If part[1] looks like a fix, use it instead
            if (strlen($clean1) <= 5 && preg_match('/^[A-Z]+$/', $clean1)) {
                $result[] = $clean1; // DP.TRANSITION -> keep transition
            } elseif (strlen($clean0) <= 5 && preg_match('/^[A-Z]+$/', $clean0)) {
                $result[] = $clean0; // TRANSITION.STAR -> keep transition
            }
            // else skip entirely (unrecognized notation)
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
 * Handles airways, SID/STAR notation, and duplicate fix disambiguation.
 *
 * Pipeline (mirrors route-maplibre.js):
 *   1. Tokenize and preprocess (strip procedures, airports)
 *   2. Expand airways into intermediate fixes
 *   3. Batch-resolve fix coordinates with CONUS preference
 *   4. Context-based disambiguation for duplicate fix names
 *   5. Distance validation (reject fixes too far from route path)
 */
function resolveRouteGeojson($conn, $routeString) {
    if (!$routeString) return null;

    // Tokenize: split on spaces, filter empty/long tokens
    $tokens = preg_split('/\s+/', trim(strtoupper($routeString)));
    $tokens = array_values(array_filter($tokens, function($t) {
        return trim($t) !== '' && strlen(trim($t)) <= 16;
    }));

    if (count($tokens) < 2) return null;

    // Preprocess: strip SID/STAR notation, airport codes
    $tokens = preprocessRouteTokens($tokens);
    if (count($tokens) < 2) return null;

    // Expand airways into intermediate fixes
    $expanded = expandRouteAirways($conn, $tokens);

    // Dedupe for the SQL query while preserving order
    $unique = array_values(array_unique($expanded));
    if (empty($unique)) return null;

    // Batch-resolve ALL candidate coordinates (not just one per fix_name)
    // Use ROW_NUMBER to get the best candidate per fix, preferring CONUS
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
    foreach ($expanded as $idx => $token) {
        if (!isset($fixMap[$token])) continue;

        $coord = $fixMap[$token];

        // Distance validation: reject fixes too far from the route path
        // (mirrors confirmReasonableDistance in route-maplibre.js)
        if ($prevCoord !== null) {
            $dlat = abs($coord[1] - $prevCoord[1]);
            $dlon = abs($coord[0] - $prevCoord[0]);
            $dist = $dlat + $dlon; // Manhattan distance in degrees (~60nm per degree)

            // Max 40 degrees (~2400nm) between consecutive fixes
            if ($dist > 40) continue;
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
 * Resolve a PROCEDURE element into a GeoJSON LineString.
 * Accepts formats like:
 *   "SIE.CAMRN3" (transition.STAR)
 *   "CAMRN3" (just STAR name, no transition)
 *   "KAYLN3.SMUUV" (DP.transition)
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
        // We'll search for both parts
        $transition = $parts[0];
        $procName = $parts[1];
    }

    // Strip trailing # from procedure names (CAMRN# -> CAMRN)
    $procNameClean = rtrim($procName, '#');

    // Search for matching procedure
    $sql = "SELECT TOP 1 procedure_id, procedure_type, procedure_name, computer_code, full_route
            FROM dbo.nav_procedures
            WHERE (procedure_name LIKE ? OR computer_code LIKE ?)";
    $params = [$procNameClean . '%', $procNameClean . '%'];

    if ($airportIcao) {
        $sql .= " AND airport_icao = ?";
        $params[] = $airportIcao;
    }

    if ($transition) {
        $sql .= " AND (transition_name = ? OR transition_name LIKE ?)";
        $params[] = $transition;
        $params[] = $transition . '%';
    }

    $sql .= " ORDER BY CASE WHEN procedure_name = ? THEN 0 WHEN computer_code = ? THEN 0 ELSE 1 END";
    $params[] = $procNameClean;
    $params[] = $procNameClean;

    $stmt = sqlsrv_query($conn, $sql, $params);
    if ($stmt === false) return null;

    $proc = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    sqlsrv_free_stmt($stmt);

    if (!$proc) return null;

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
    } elseif (strtoupper($input['element_type']) === 'PROCEDURE') {
        // Resolve procedure into route geometry
        $airportIcao = $input['airport_icao'] ?? null;
        $routeGeojson = resolveProcedureGeojson($conn, $input['element_name'], $airportIcao);
        if (!$routeGeojson && !empty($input['route_string'])) {
            // Fallback: try resolving as a route string
            $routeGeojson = resolveRouteGeojson($conn, $input['route_string']);
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
