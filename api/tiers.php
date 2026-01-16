<?php

// api/tiers.php
// Returns ARTCC tier configurations from the database
// Replaces static TierInfo.csv and artcc_tiers.json files
//
// Usage:
//   GET /api/tiers.php                    - All tier data (facilities, configs, groups)
//   GET /api/tiers.php?facility=ZAB       - Tier configs for specific facility
//   GET /api/tiers.php?config=ZAB1        - Get ARTCCs for a specific config code
//   GET /api/tiers.php?group=ALL          - Get ARTCCs for a named tier group
//   GET /api/tiers.php?format=legacy      - Return data in legacy JSON format (artcc_tiers.json compatible)
//   GET /api/tiers.php?format=csv         - Return data in CSV format (TierInfo.csv compatible)

if (session_status() == PHP_SESSION_NONE) {
    session_start();
    ob_start();
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=300'); // Cache for 5 minutes

require_once(__DIR__ . "/../load/config.php");
require_once(__DIR__ . "/../load/input.php");

// Check ADL database configuration
if (!defined("ADL_SQL_HOST") || !defined("ADL_SQL_DATABASE") ||
    !defined("ADL_SQL_USERNAME") || !defined("ADL_SQL_PASSWORD")) {
    http_response_code(500);
    echo json_encode(["success" => false, "error" => "ADL_SQL_* constants are not defined."]);
    exit;
}

// Helper function for SQL Server error messages
function adl_sql_error_message() {
    $errs = sqlsrv_errors(SQLSRV_ERR_ERRORS);
    if (!$errs) return "";
    $msgs = [];
    foreach ($errs as $e) {
        $msgs[] = (isset($e['SQLSTATE']) ? $e['SQLSTATE'] : '') . " " .
                  (isset($e['code']) ? $e['code'] : '') . " " .
                  (isset($e['message']) ? trim($e['message']) : '');
    }
    return implode(" | ", $msgs);
}

// Check sqlsrv extension
if (!function_exists('sqlsrv_connect')) {
    http_response_code(500);
    echo json_encode(["success" => false, "error" => "sqlsrv extension not available."]);
    exit;
}

// Connect to ADL database
$connectionInfo = [
    "Database" => ADL_SQL_DATABASE,
    "UID"      => ADL_SQL_USERNAME,
    "PWD"      => ADL_SQL_PASSWORD
];

$conn = sqlsrv_connect(ADL_SQL_HOST, $connectionInfo);
if ($conn === false) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "error" => "Unable to connect to ADL database.",
        "sql_error" => adl_sql_error_message()
    ]);
    exit;
}

// Get request parameters
$facility = isset($_GET['facility']) ? get_upper('facility') : '';
$config = isset($_GET['config']) ? get_upper('config') : '';
$group = isset($_GET['group']) ? get_upper('group') : '';
$format = isset($_GET['format']) ? get_lower('format') : 'standard';

// ===========================================================================
// CSV Format: Return data in TierInfo.csv format for gdt.js compatibility
// ===========================================================================
if ($format === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: inline; filename="TierInfo.csv"');

    // Start with header
    echo "code,facility,select,departureFacilitiesIncluded\n";

    // Get global tier groups first (ALL, ALL+CANADA, Manual)
    $globalGroups = [
        ['code' => 'ALL', 'label' => '(ALL)'],
        ['code' => 'ALL+Canada', 'label' => '(ALL+Canada)'],
        ['code' => 'Manual', 'label' => '(Manual)']
    ];

    foreach ($globalGroups as $grp) {
        $artccs = [];
        if ($grp['code'] !== 'Manual') {
            $groupCode = strtoupper(str_replace('+', '+', $grp['code']));
            $sql = "
                SELECT f.facility_code
                FROM dbo.artcc_tier_groups tg
                INNER JOIN dbo.artcc_tier_group_members tgm ON tg.tier_group_id = tgm.tier_group_id
                INNER JOIN dbo.artcc_facilities f ON tgm.facility_id = f.facility_id
                WHERE tg.tier_group_code = ? AND tg.is_active = 1
                ORDER BY tgm.display_order
            ";
            $stmt = sqlsrv_query($conn, $sql, [$groupCode]);
            if ($stmt !== false) {
                while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
                    $artccs[] = $row['facility_code'];
                }
                sqlsrv_free_stmt($stmt);
            }
        }
        echo $grp['code'] . ",," . $grp['label'] . "," . implode(' ', $artccs) . "\n";
    }

    // Get all facility configs
    $sql = "
        SELECT
            ff.facility_code AS owner_facility,
            fc.config_code,
            fc.config_label,
            fc.tier_group_id,
            fc.config_id
        FROM dbo.facility_tier_configs fc
        INNER JOIN dbo.artcc_facilities ff ON fc.facility_id = ff.facility_id
        WHERE fc.is_active = 1 AND ff.is_active = 1
        ORDER BY ff.facility_code, fc.display_order
    ";

    $stmt = sqlsrv_query($conn, $sql);
    if ($stmt === false) {
        sqlsrv_close($conn);
        exit;
    }

    $configs = [];
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $configs[] = $row;
    }
    sqlsrv_free_stmt($stmt);

    // For each config, get its ARTCCs and output CSV line
    foreach ($configs as $cfg) {
        $artccs = [];
        if (!empty($cfg['tier_group_id'])) {
            // From tier group
            $sql = "
                SELECT f.facility_code
                FROM dbo.artcc_tier_group_members tgm
                INNER JOIN dbo.artcc_facilities f ON tgm.facility_id = f.facility_id
                WHERE tgm.tier_group_id = ? AND f.is_active = 1
                ORDER BY tgm.display_order
            ";
            $stmt2 = sqlsrv_query($conn, $sql, [$cfg['tier_group_id']]);
        } else {
            // From config members
            $sql = "
                SELECT f.facility_code
                FROM dbo.facility_tier_config_members fcm
                INNER JOIN dbo.artcc_facilities f ON fcm.facility_id = f.facility_id
                WHERE fcm.config_id = ? AND f.is_active = 1
                ORDER BY fcm.display_order
            ";
            $stmt2 = sqlsrv_query($conn, $sql, [$cfg['config_id']]);
        }

        if ($stmt2 !== false) {
            while ($row2 = sqlsrv_fetch_array($stmt2, SQLSRV_FETCH_ASSOC)) {
                $artccs[] = $row2['facility_code'];
            }
            sqlsrv_free_stmt($stmt2);
        }

        echo $cfg['config_code'] . "," . $cfg['owner_facility'] . "," . $cfg['config_label'] . "," . implode(' ', $artccs) . "\n";
    }

    sqlsrv_close($conn);
    exit;
}

// ===========================================================================
// Case 1: Get ARTCCs for a specific config code (e.g., ZAB1, ZBWEC)
// ===========================================================================
if (!empty($config)) {
    // First check if it references a tier group
    $sql = "
        SELECT
            fc.config_code,
            fc.config_label,
            fc.tier_group_id,
            tg.tier_group_code,
            ff.facility_code AS owner_facility
        FROM dbo.facility_tier_configs fc
        INNER JOIN dbo.artcc_facilities ff ON fc.facility_id = ff.facility_id
        LEFT JOIN dbo.artcc_tier_groups tg ON fc.tier_group_id = tg.tier_group_id
        WHERE fc.config_code = ? AND fc.is_active = 1
    ";

    $stmt = sqlsrv_query($conn, $sql, [$config]);
    if ($stmt === false) {
        http_response_code(500);
        echo json_encode(["success" => false, "error" => "Query error", "sql_error" => adl_sql_error_message()]);
        sqlsrv_close($conn);
        exit;
    }

    $configInfo = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    sqlsrv_free_stmt($stmt);

    if (!$configInfo) {
        echo json_encode(["success" => true, "config" => $config, "artccs" => [], "message" => "Config not found"]);
        sqlsrv_close($conn);
        exit;
    }

    // Get ARTCCs either from tier group or config members
    $artccs = [];
    if (!empty($configInfo['tier_group_id'])) {
        // Get from tier group
        $sql = "
            SELECT f.facility_code
            FROM dbo.artcc_tier_group_members tgm
            INNER JOIN dbo.artcc_facilities f ON tgm.facility_id = f.facility_id
            WHERE tgm.tier_group_id = ? AND f.is_active = 1
            ORDER BY tgm.display_order
        ";
        $stmt = sqlsrv_query($conn, $sql, [$configInfo['tier_group_id']]);
    } else {
        // Get from config members
        $sql = "
            SELECT f.facility_code
            FROM dbo.facility_tier_config_members fcm
            INNER JOIN dbo.facility_tier_configs fc ON fcm.config_id = fc.config_id
            INNER JOIN dbo.artcc_facilities f ON fcm.facility_id = f.facility_id
            WHERE fc.config_code = ? AND fc.is_active = 1 AND f.is_active = 1
            ORDER BY fcm.display_order
        ";
        $stmt = sqlsrv_query($conn, $sql, [$config]);
    }

    if ($stmt !== false) {
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $artccs[] = $row['facility_code'];
        }
        sqlsrv_free_stmt($stmt);
    }

    echo json_encode([
        "success" => true,
        "config" => $config,
        "label" => $configInfo['config_label'],
        "owner_facility" => $configInfo['owner_facility'],
        "tier_group" => $configInfo['tier_group_code'],
        "artccs" => $artccs
    ]);
    sqlsrv_close($conn);
    exit;
}

// ===========================================================================
// Case 2: Get ARTCCs for a named tier group (e.g., ALL, ALL+CANADA, 6WEST)
// ===========================================================================
if (!empty($group)) {
    $sql = "
        SELECT f.facility_code
        FROM dbo.artcc_tier_groups tg
        INNER JOIN dbo.artcc_tier_group_members tgm ON tg.tier_group_id = tgm.tier_group_id
        INNER JOIN dbo.artcc_facilities f ON tgm.facility_id = f.facility_id
        WHERE tg.tier_group_code = ? AND tg.is_active = 1 AND f.is_active = 1
        ORDER BY tgm.display_order
    ";

    $stmt = sqlsrv_query($conn, $sql, [$group]);
    if ($stmt === false) {
        http_response_code(500);
        echo json_encode(["success" => false, "error" => "Query error", "sql_error" => adl_sql_error_message()]);
        sqlsrv_close($conn);
        exit;
    }

    $artccs = [];
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $artccs[] = $row['facility_code'];
    }
    sqlsrv_free_stmt($stmt);

    // Get group info
    $sql = "SELECT tier_group_name, description FROM dbo.artcc_tier_groups WHERE tier_group_code = ?";
    $stmt = sqlsrv_query($conn, $sql, [$group]);
    $groupInfo = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    sqlsrv_free_stmt($stmt);

    echo json_encode([
        "success" => true,
        "group" => $group,
        "name" => $groupInfo ? $groupInfo['tier_group_name'] : $group,
        "description" => $groupInfo ? $groupInfo['description'] : null,
        "artccs" => $artccs
    ]);
    sqlsrv_close($conn);
    exit;
}

// ===========================================================================
// Case 3: Get configs for a specific facility (e.g., ZAB)
// ===========================================================================
if (!empty($facility)) {
    $sql = "
        SELECT
            fc.config_code,
            fc.config_label,
            tt.tier_type_code,
            tg.tier_group_code,
            fc.is_default,
            fc.display_order
        FROM dbo.facility_tier_configs fc
        INNER JOIN dbo.artcc_facilities ff ON fc.facility_id = ff.facility_id
        LEFT JOIN dbo.artcc_tier_types tt ON fc.tier_type_id = tt.tier_type_id
        LEFT JOIN dbo.artcc_tier_groups tg ON fc.tier_group_id = tg.tier_group_id
        WHERE ff.facility_code = ? AND fc.is_active = 1
        ORDER BY fc.display_order
    ";

    $stmt = sqlsrv_query($conn, $sql, [$facility]);
    if ($stmt === false) {
        http_response_code(500);
        echo json_encode(["success" => false, "error" => "Query error", "sql_error" => adl_sql_error_message()]);
        sqlsrv_close($conn);
        exit;
    }

    $configs = [];
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $configs[] = [
            "code" => $row['config_code'],
            "label" => $row['config_label'],
            "tier_type" => $row['tier_type_code'],
            "tier_group" => $row['tier_group_code'],
            "is_default" => $row['is_default'] == 1
        ];
    }
    sqlsrv_free_stmt($stmt);

    echo json_encode([
        "success" => true,
        "facility" => $facility,
        "configs" => $configs
    ]);
    sqlsrv_close($conn);
    exit;
}

// ===========================================================================
// Case 4: Get all tier data (full export)
// ===========================================================================
if ($format === 'legacy') {
    // Return data in legacy artcc_tiers.json format for backwards compatibility
    $result = [
        "global" => [],
        "byFacility" => [],
        "facilityList" => [],
        "tierTypes" => []
    ];

    // Get all US ARTCC facilities
    $sql = "
        SELECT facility_code, facility_name
        FROM dbo.artcc_facilities
        WHERE facility_type = 'ARTCC' AND country_code = 'US' AND is_active = 1
        ORDER BY facility_code
    ";
    $stmt = sqlsrv_query($conn, $sql);
    if ($stmt === false) {
        http_response_code(500);
        echo json_encode(["success" => false, "error" => "Failed to query facilities", "sql_error" => adl_sql_error_message()]);
        sqlsrv_close($conn);
        exit;
    }
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $result['facilityList'][] = $row['facility_code'];
    }
    sqlsrv_free_stmt($stmt);

    // Get tier types
    $sql = "SELECT tier_type_code, tier_type_label FROM dbo.artcc_tier_types WHERE is_active = 1 ORDER BY display_order";
    $stmt = sqlsrv_query($conn, $sql);
    if ($stmt === false) {
        http_response_code(500);
        echo json_encode(["success" => false, "error" => "Failed to query tier types", "sql_error" => adl_sql_error_message()]);
        sqlsrv_close($conn);
        exit;
    }
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $result['tierTypes'][$row['tier_type_code']] = $row['tier_type_label'];
    }
    sqlsrv_free_stmt($stmt);

    // Get global tier groups (ALL, ALL+CANADA, etc.)
    $globalGroups = ['ALL', 'ALL+CANADA'];
    foreach ($globalGroups as $groupCode) {
        $sql = "
            SELECT f.facility_code
            FROM dbo.artcc_tier_groups tg
            INNER JOIN dbo.artcc_tier_group_members tgm ON tg.tier_group_id = tgm.tier_group_id
            INNER JOIN dbo.artcc_facilities f ON tgm.facility_id = f.facility_id
            WHERE tg.tier_group_code = ? AND tg.is_active = 1
            ORDER BY tgm.display_order
        ";
        $stmt = sqlsrv_query($conn, $sql, [$groupCode]);
        $artccs = [];
        if ($stmt !== false) {
            while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
                $artccs[] = $row['facility_code'];
            }
            sqlsrv_free_stmt($stmt);
        }

        $labelKey = str_replace('+', '+', $groupCode);  // ALL+CANADA -> ALL+Canada display
        $result['global'][$groupCode] = [
            "code" => $groupCode,
            "label" => "($labelKey)",
            "artccs" => $artccs
        ];
    }

    // Add Manual option
    $result['global']['Manual'] = [
        "code" => "Manual",
        "label" => "(Manual)",
        "artccs" => []
    ];

    // Get all facility configs grouped by facility
    $sql = "
        SELECT
            ff.facility_code AS owner_facility,
            fc.config_code,
            fc.config_label,
            fc.tier_group_id,
            fc.config_id
        FROM dbo.facility_tier_configs fc
        INNER JOIN dbo.artcc_facilities ff ON fc.facility_id = ff.facility_id
        WHERE fc.is_active = 1 AND ff.is_active = 1
        ORDER BY ff.facility_code, fc.display_order
    ";

    $stmt = sqlsrv_query($conn, $sql);
    if ($stmt === false) {
        http_response_code(500);
        echo json_encode(["success" => false, "error" => "Failed to query facility configs", "sql_error" => adl_sql_error_message()]);
        sqlsrv_close($conn);
        exit;
    }
    $facilityConfigs = [];
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $facilityConfigs[] = $row;
    }
    sqlsrv_free_stmt($stmt);

    // For each config, get its ARTCCs
    foreach ($facilityConfigs as $cfg) {
        $ownerFacility = $cfg['owner_facility'];
        $configCode = $cfg['config_code'];

        if (!isset($result['byFacility'][$ownerFacility])) {
            $result['byFacility'][$ownerFacility] = [];
        }

        // Get ARTCCs
        $artccs = [];
        if (!empty($cfg['tier_group_id'])) {
            // From tier group
            $sql = "
                SELECT f.facility_code
                FROM dbo.artcc_tier_group_members tgm
                INNER JOIN dbo.artcc_facilities f ON tgm.facility_id = f.facility_id
                WHERE tgm.tier_group_id = ? AND f.is_active = 1
                ORDER BY tgm.display_order
            ";
            $stmt2 = sqlsrv_query($conn, $sql, [$cfg['tier_group_id']]);
        } else {
            // From config members
            $sql = "
                SELECT f.facility_code
                FROM dbo.facility_tier_config_members fcm
                INNER JOIN dbo.artcc_facilities f ON fcm.facility_id = f.facility_id
                WHERE fcm.config_id = ? AND f.is_active = 1
                ORDER BY fcm.display_order
            ";
            $stmt2 = sqlsrv_query($conn, $sql, [$cfg['config_id']]);
        }

        if ($stmt2 !== false) {
            while ($row2 = sqlsrv_fetch_array($stmt2, SQLSRV_FETCH_ASSOC)) {
                $artccs[] = $row2['facility_code'];
            }
            sqlsrv_free_stmt($stmt2);
        }

        $result['byFacility'][$ownerFacility][$configCode] = [
            "code" => $configCode,
            "label" => $cfg['config_label'],
            "artccs" => $artccs
        ];
    }

    echo json_encode($result);
    sqlsrv_close($conn);
    exit;
}

// ===========================================================================
// Default: Return structured tier data
// ===========================================================================
$result = [
    "success" => true,
    "timestamp" => gmdate("Y-m-d\\TH:i:s\\Z"),
    "facilities" => [],
    "tier_groups" => [],
    "tier_types" => []
];

// Get all facilities
$sql = "
    SELECT facility_code, facility_name, facility_type, country_code
    FROM dbo.artcc_facilities
    WHERE is_active = 1
    ORDER BY
        CASE WHEN facility_type = 'ARTCC' AND country_code = 'US' THEN 1
             WHEN facility_type = 'FIR' AND country_code = 'CA' THEN 2
             ELSE 3 END,
        facility_code
";
$stmt = sqlsrv_query($conn, $sql);
if ($stmt === false) {
    http_response_code(500);
    echo json_encode(["success" => false, "error" => "Failed to query facilities", "sql_error" => adl_sql_error_message()]);
    sqlsrv_close($conn);
    exit;
}
while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
    $result['facilities'][] = [
        "code" => $row['facility_code'],
        "name" => $row['facility_name'],
        "type" => $row['facility_type'],
        "country" => $row['country_code']
    ];
}
sqlsrv_free_stmt($stmt);

// Get tier types
$sql = "SELECT tier_type_code, tier_type_label, description FROM dbo.artcc_tier_types WHERE is_active = 1 ORDER BY display_order";
$stmt = sqlsrv_query($conn, $sql);
if ($stmt !== false) {
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $result['tier_types'][] = [
            "code" => $row['tier_type_code'],
            "label" => $row['tier_type_label'],
            "description" => $row['description']
        ];
    }
    sqlsrv_free_stmt($stmt);
}

// Get named tier groups with member counts
$sql = "
    SELECT
        tg.tier_group_code,
        tg.tier_group_name,
        tg.description,
        COUNT(tgm.member_id) AS member_count
    FROM dbo.artcc_tier_groups tg
    LEFT JOIN dbo.artcc_tier_group_members tgm ON tg.tier_group_id = tgm.tier_group_id
    WHERE tg.is_active = 1
    GROUP BY tg.tier_group_id, tg.tier_group_code, tg.tier_group_name, tg.description, tg.display_order
    ORDER BY tg.display_order
";
$stmt = sqlsrv_query($conn, $sql);
if ($stmt !== false) {
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $result['tier_groups'][] = [
            "code" => $row['tier_group_code'],
            "name" => $row['tier_group_name'],
            "description" => $row['description'],
            "member_count" => $row['member_count']
        ];
    }
    sqlsrv_free_stmt($stmt);
}

sqlsrv_close($conn);

echo json_encode($result);
