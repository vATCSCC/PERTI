<?php
/**
 * NOD FEA Bridge API
 *
 * Creates/removes demand monitors linked to flow elements or TMI entries.
 *
 * POST — Create demand monitor from element or TMI
 * DELETE — Remove linked demand monitor
 */

header('Content-Type: application/json');

$config_path = realpath(__DIR__ . '/../../load/config.php');
$connect_path = realpath(__DIR__ . '/../../load/connect.php');
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
        case 'POST':
            handlePost($conn);
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
 * POST — Create demand monitor(s) from flow element or TMI entry.
 *
 * Body: {source_type, element_id|entry_id|config_id}
 * source_type: "flow_element", "tmi_entry", "bulk"
 */
function handlePost($conn) {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input || !isset($input['source_type'])) {
        http_response_code(400);
        echo json_encode(['error' => 'source_type required']);
        return;
    }

    $sourceType = $input['source_type'];

    switch ($sourceType) {
        case 'flow_element':
            handleCreateFromElement($conn, $input);
            break;
        case 'tmi_entry':
            handleCreateFromTMI($conn, $input);
            break;
        case 'bulk':
            handleBulkCreate($conn, $input);
            break;
        default:
            http_response_code(400);
            echo json_encode(['error' => 'Invalid source_type: ' . $sourceType]);
    }
}

/**
 * Create a demand monitor from a flow element.
 */
function handleCreateFromElement($conn, $input) {
    $elementId = intval($input['element_id'] ?? 0);
    if (!$elementId) {
        http_response_code(400);
        echo json_encode(['error' => 'element_id required']);
        return;
    }

    // Fetch the element
    $sql = "SELECT e.*, c.facility_code, c.facility_type
            FROM dbo.facility_flow_elements e
            JOIN dbo.facility_flow_configs c ON e.config_id = c.config_id
            WHERE e.element_id = ?";
    $stmt = sqlsrv_query($conn, $sql, [$elementId]);
    if ($stmt === false) {
        throw new Exception('Query failed: ' . print_r(sqlsrv_errors(), true));
    }
    $element = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    if (!$element) {
        http_response_code(404);
        echo json_encode(['error' => 'Element not found']);
        return;
    }

    // Check if already has a monitor
    if ($element['demand_monitor_id']) {
        echo json_encode([
            'monitor_id' => $element['demand_monitor_id'],
            'already_exists' => true,
        ]);
        return;
    }

    // Build monitor definition based on element type
    $monitorType = null;
    $monitorKey = null;
    $definition = [];

    switch ($element['element_type']) {
        case 'FIX':
            $monitorType = 'via_fix';
            $fixName = $element['fix_name'] ?: $element['element_name'];
            $monitorKey = "via_fix_{$fixName}";
            $definition = [
                'via' => $fixName,
                'filter' => [
                    'type' => 'airport',
                    'code' => $element['facility_code'],
                    'direction' => strtolower($element['direction']),
                ],
            ];
            break;

        case 'ROUTE':
            $monitorType = 'segment';
            $monitorKey = "route_elem_{$elementId}";
            $definition = [
                'route_string' => $element['route_string'],
                'route_geojson' => $element['route_geojson'],
            ];
            break;

        case 'PROCEDURE':
            $monitorType = 'via_fix';
            $procName = $element['element_name'];
            $monitorKey = "proc_{$procName}_{$elementId}";
            // Use element name as a fix reference (terminal fix)
            $definition = [
                'via' => $procName,
                'filter' => [
                    'type' => 'airport',
                    'code' => $element['facility_code'],
                    'direction' => strtolower($element['direction']),
                ],
            ];
            break;

        default:
            http_response_code(400);
            echo json_encode(['error' => 'Cannot create FEA for type: ' . $element['element_type']]);
            return;
    }

    // Insert into demand_monitors
    $insertSql = "INSERT INTO dbo.demand_monitors (monitor_key, monitor_type, definition, display_label, is_active)
                  VALUES (?, ?, ?, ?, 1)";
    $displayLabel = $element['element_name'];
    $definitionJson = json_encode($definition);
    $insertStmt = sqlsrv_query($conn, $insertSql, [$monitorKey, $monitorType, $definitionJson, $displayLabel]);
    if ($insertStmt === false) {
        throw new Exception('Failed to create monitor: ' . print_r(sqlsrv_errors(), true));
    }

    // Get the new monitor ID
    $idStmt = sqlsrv_query($conn, "SELECT SCOPE_IDENTITY() AS monitor_id");
    $idRow = sqlsrv_fetch_array($idStmt, SQLSRV_FETCH_ASSOC);
    $monitorId = intval($idRow['monitor_id']);

    // Link element to monitor
    $updateSql = "UPDATE dbo.facility_flow_elements SET demand_monitor_id = ?, updated_at = GETUTCDATE() WHERE element_id = ?";
    sqlsrv_query($conn, $updateSql, [$monitorId, $elementId]);

    echo json_encode([
        'monitor_id' => $monitorId,
        'monitor_key' => $monitorKey,
        'monitor_type' => $monitorType,
        'definition' => $definition,
    ]);
}

/**
 * Create a demand monitor from a TMI entry (MIT fix).
 */
function handleCreateFromTMI($conn, $input) {
    $entryId = intval($input['entry_id'] ?? 0);
    if (!$entryId) {
        http_response_code(400);
        echo json_encode(['error' => 'entry_id required']);
        return;
    }

    // Fetch TMI entry from VATSIM_TMI
    $connTmi = get_conn_tmi();
    if (!$connTmi) {
        http_response_code(500);
        echo json_encode(['error' => 'TMI database not available']);
        return;
    }

    $sql = "SELECT entry_id, entry_type, ctl_element, restriction_value, restriction_unit,
                   requesting_facility, providing_facility
            FROM dbo.tmi_entries WHERE entry_id = ?";
    $stmt = sqlsrv_query($connTmi, $sql, [$entryId]);
    if ($stmt === false) {
        throw new Exception('TMI query failed');
    }
    $entry = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    if (!$entry) {
        http_response_code(404);
        echo json_encode(['error' => 'TMI entry not found']);
        return;
    }

    $fixName = $entry['ctl_element'];
    $monitorKey = "tmi_mit_{$fixName}_{$entryId}";
    $monitorType = 'via_fix';
    $definition = [
        'via' => $fixName,
        'filter' => [
            'type' => 'facility',
            'code' => $entry['providing_facility'] ?: $entry['requesting_facility'],
            'direction' => 'arrival',
        ],
    ];

    // Insert into demand_monitors (ADL database)
    $insertSql = "INSERT INTO dbo.demand_monitors (monitor_key, monitor_type, definition, display_label, is_active)
                  VALUES (?, ?, ?, ?, 1)";
    $label = ($entry['restriction_value'] ?: '') . ' ' . $entry['entry_type'] . ' ' . $fixName;
    $insertStmt = sqlsrv_query($conn, $insertSql, [$monitorKey, $monitorType, json_encode($definition), trim($label)]);
    if ($insertStmt === false) {
        throw new Exception('Failed to create monitor: ' . print_r(sqlsrv_errors(), true));
    }

    $idStmt = sqlsrv_query($conn, "SELECT SCOPE_IDENTITY() AS monitor_id");
    $idRow = sqlsrv_fetch_array($idStmt, SQLSRV_FETCH_ASSOC);
    $monitorId = intval($idRow['monitor_id']);

    echo json_encode([
        'monitor_id' => $monitorId,
        'monitor_key' => $monitorKey,
    ]);
}

/**
 * Bulk create monitors for all visible elements in a config.
 */
function handleBulkCreate($conn, $input) {
    $configId = intval($input['config_id'] ?? 0);
    if (!$configId) {
        http_response_code(400);
        echo json_encode(['error' => 'config_id required']);
        return;
    }

    // Get all visible elements without monitors that are FIX or ROUTE type
    $sql = "SELECT element_id, element_type, element_name, fix_name, route_string, route_geojson,
                   direction, config_id
            FROM dbo.facility_flow_elements
            WHERE config_id = ? AND is_visible = 1 AND demand_monitor_id IS NULL
              AND element_type IN ('FIX', 'ROUTE')";
    $stmt = sqlsrv_query($conn, $sql, [$configId]);
    if ($stmt === false) {
        throw new Exception('Query failed');
    }

    // Get facility info
    $cfgStmt = sqlsrv_query($conn, "SELECT facility_code FROM dbo.facility_flow_configs WHERE config_id = ?", [$configId]);
    $cfgRow = sqlsrv_fetch_array($cfgStmt, SQLSRV_FETCH_ASSOC);
    $facilityCode = $cfgRow ? $cfgRow['facility_code'] : '';

    $created = [];
    while ($el = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $monitorType = null;
        $monitorKey = null;
        $definition = [];
        $label = $el['element_name'];

        if ($el['element_type'] === 'FIX') {
            $fixName = $el['fix_name'] ?: $el['element_name'];
            $monitorType = 'via_fix';
            $monitorKey = "via_fix_{$fixName}";
            $definition = [
                'via' => $fixName,
                'filter' => ['type' => 'airport', 'code' => $facilityCode, 'direction' => strtolower($el['direction'])],
            ];
        } elseif ($el['element_type'] === 'ROUTE') {
            $monitorType = 'segment';
            $monitorKey = "route_elem_{$el['element_id']}";
            $definition = [
                'route_string' => $el['route_string'],
                'route_geojson' => $el['route_geojson'],
            ];
        }

        if (!$monitorType) continue;

        // Check if monitor_key already exists
        $checkStmt = sqlsrv_query($conn, "SELECT monitor_id FROM dbo.demand_monitors WHERE monitor_key = ? AND is_active = 1", [$monitorKey]);
        $existing = sqlsrv_fetch_array($checkStmt, SQLSRV_FETCH_ASSOC);

        $monitorId = null;
        if ($existing) {
            $monitorId = $existing['monitor_id'];
        } else {
            $insertSql = "INSERT INTO dbo.demand_monitors (monitor_key, monitor_type, definition, display_label, is_active) VALUES (?, ?, ?, ?, 1)";
            sqlsrv_query($conn, $insertSql, [$monitorKey, $monitorType, json_encode($definition), $label]);
            $idStmt = sqlsrv_query($conn, "SELECT SCOPE_IDENTITY() AS monitor_id");
            $idRow = sqlsrv_fetch_array($idStmt, SQLSRV_FETCH_ASSOC);
            $monitorId = intval($idRow['monitor_id']);
        }

        // Link element
        sqlsrv_query($conn, "UPDATE dbo.facility_flow_elements SET demand_monitor_id = ?, updated_at = GETUTCDATE() WHERE element_id = ?",
            [$monitorId, $el['element_id']]);

        $created[] = [
            'element_id' => $el['element_id'],
            'monitor_id' => $monitorId,
            'monitor_key' => $monitorKey,
            'monitor_type' => $monitorType,
            'definition' => $definition,
        ];
    }

    echo json_encode([
        'created' => count($created),
        'monitors' => $created,
    ]);
}

/**
 * DELETE — Remove demand monitor linked to a flow element or all elements in a config.
 *
 * Params: source_type=flow_element&element_id=X
 *   or:   source_type=config&config_id=X
 */
function handleDelete($conn) {
    $sourceType = $_GET['source_type'] ?? '';

    if ($sourceType === 'flow_element') {
        $elementId = intval($_GET['element_id'] ?? 0);
        if (!$elementId) {
            http_response_code(400);
            echo json_encode(['error' => 'element_id required']);
            return;
        }

        // Get the linked monitor
        $stmt = sqlsrv_query($conn, "SELECT demand_monitor_id FROM dbo.facility_flow_elements WHERE element_id = ?", [$elementId]);
        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        if (!$row || !$row['demand_monitor_id']) {
            echo json_encode(['success' => true, 'message' => 'No monitor linked']);
            return;
        }

        $monitorId = $row['demand_monitor_id'];

        // Deactivate the monitor
        sqlsrv_query($conn, "UPDATE dbo.demand_monitors SET is_active = 0 WHERE monitor_id = ?", [$monitorId]);

        // Unlink from element
        sqlsrv_query($conn, "UPDATE dbo.facility_flow_elements SET demand_monitor_id = NULL, updated_at = GETUTCDATE() WHERE element_id = ?", [$elementId]);

        echo json_encode(['success' => true, 'removed_monitor_id' => $monitorId]);

    } elseif ($sourceType === 'config') {
        $configId = intval($_GET['config_id'] ?? 0);
        if (!$configId) {
            http_response_code(400);
            echo json_encode(['error' => 'config_id required']);
            return;
        }

        // Get all linked monitors
        $stmt = sqlsrv_query($conn,
            "SELECT element_id, demand_monitor_id FROM dbo.facility_flow_elements WHERE config_id = ? AND demand_monitor_id IS NOT NULL",
            [$configId]);

        $removed = 0;
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            sqlsrv_query($conn, "UPDATE dbo.demand_monitors SET is_active = 0 WHERE monitor_id = ?", [$row['demand_monitor_id']]);
            $removed++;
        }

        // Unlink all elements
        sqlsrv_query($conn,
            "UPDATE dbo.facility_flow_elements SET demand_monitor_id = NULL, updated_at = GETUTCDATE() WHERE config_id = ? AND demand_monitor_id IS NOT NULL",
            [$configId]);

        echo json_encode(['success' => true, 'removed' => $removed]);

    } else {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid source_type: ' . $sourceType]);
    }
}
