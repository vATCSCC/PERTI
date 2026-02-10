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
 * Resolve fix lat/lon from nav_fixes by fix_name
 */
function resolveFixLatLon($conn, $fixName) {
    if (!$fixName) return [null, null];

    $sql = "SELECT TOP 1 lat, lon FROM dbo.nav_fixes WHERE fix_name = ?";
    $stmt = sqlsrv_query($conn, $sql, [$fixName]);
    if ($stmt === false) return [null, null];

    $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    sqlsrv_free_stmt($stmt);

    if ($row) {
        return [$row['lat'], $row['lon']];
    }
    return [null, null];
}

/**
 * Expand a route string by resolving airways into their intermediate fixes.
 * Same algorithm as ConvertRoute() in route-maplibre.js, using the airways
 * table instead of the client-side awys[] array.
 *
 * "COATE Q436 RAAKK" → "COATE [intermediate fixes on Q436] RAAKK"
 */
function expandRouteAirways($conn, $tokens) {
    if (count($tokens) < 3) return $tokens;

    // Identify potential airway tokens (middle tokens not in nav_fixes)
    // Airways are patterns like Q436, J75, V2, T297, etc.
    $potentialAirways = [];
    for ($i = 1; $i < count($tokens) - 1; $i++) {
        if (preg_match('/^[A-Z][0-9]+$|^[A-Z]{1,2}[0-9]{1,4}$/', $tokens[$i])) {
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
 * Resolve a route string into a GeoJSON LineString.
 * Expands airways into intermediate fixes (same as route-maplibre.js
 * ConvertRoute), then resolves all fix coordinates from nav_fixes.
 *
 * Examples:
 *   "COATE Q436 RAAKK" → expands Q436 intermediate fixes → full LineString
 *   "MERIT HFD PUT"     → LineString through all 3 fixes
 */
function resolveRouteGeojson($conn, $routeString) {
    if (!$routeString) return null;

    // Split on spaces and dots, filter empty/long tokens
    $tokens = preg_split('/[\s.]+/', trim(strtoupper($routeString)));
    $tokens = array_values(array_filter($tokens, function($t) {
        return trim($t) !== '' && strlen(trim($t)) <= 16;
    }));

    if (count($tokens) < 2) return null;

    // Expand airways into intermediate fixes
    $expanded = expandRouteAirways($conn, $tokens);

    // Dedupe for the SQL query while preserving order
    $unique = array_values(array_unique($expanded));

    // Batch-resolve all fix coordinates
    $placeholders = implode(',', array_fill(0, count($unique), '?'));
    $sql = "SELECT fix_name, lat, lon FROM dbo.nav_fixes WHERE fix_name IN ($placeholders)";
    $stmt = sqlsrv_query($conn, $sql, $unique);
    if ($stmt === false) return null;

    $fixMap = [];
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $fixMap[$row['fix_name']] = [floatval($row['lon']), floatval($row['lat'])];
    }
    sqlsrv_free_stmt($stmt);

    // Build coordinates in route order (skip unresolved tokens)
    $coords = [];
    foreach ($expanded as $token) {
        if (isset($fixMap[$token])) {
            $coords[] = $fixMap[$token];
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
 * GET - List elements for config or get single element
 */
function handleGet($conn) {
    $element_id = isset($_GET['element_id']) ? intval($_GET['element_id']) : null;
    $config_id = isset($_GET['config_id']) ? intval($_GET['config_id']) : null;

    // Single element
    if ($element_id) {
        $sql = "SELECT e.*, nf.lat AS fix_lat, nf.lon AS fix_lon
                FROM dbo.facility_flow_elements e
                LEFT JOIN dbo.nav_fixes nf ON e.element_type = 'FIX' AND e.fix_name = nf.fix_name
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
            LEFT JOIN dbo.nav_fixes nf ON e.element_type = 'FIX' AND e.fix_name = nf.fix_name
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
        // Auto-resolve route string to GeoJSON LineString
        $routeGeojson = resolveRouteGeojson($conn, $input['route_string']);
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

    // Include resolved route_geojson for ROUTE elements
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
