<?php

// api/data/configs.php
// Retrieves airport configuration data from ADL SQL Server

// Session Start (S)
if (session_status() == PHP_SESSION_NONE) {
    session_start();
    ob_start();
}
// Session Start (E)

include("../../load/config.php");
include("../../load/connect.php");

// Check Perms
$perm = false;
if (!defined('DEV')) {
    if (isset($_SESSION['VATSIM_CID'])) {
        $cid = session_get('VATSIM_CID', '');
        $p_check = $conn_sqli->query("SELECT * FROM users WHERE cid='$cid'");
        if ($p_check) {
            $perm = true;
        }
    }
} else {
    $perm = true;
    $_SESSION['VATSIM_FIRST_NAME'] = $_SESSION['VATSIM_LAST_NAME'] = $_SESSION['VATSIM_CID'] = 0;
}

// Helper: Get color for rate value
function getRateColor($rate) {
    if ($rate === null || $rate === '') return '';
    $r = intval($rate);
    if ($r < 12) return '#ee3e3e';
    if ($r < 25) return '#ee5f5f';
    if ($r < 36) return '#ef7f3c';
    if ($r < 46) return '#efc83c';
    if ($r < 58) return '#ecef3c';
    if ($r < 72) return '#b4ef3c';
    if ($r < 82) return '#6eef3c';
    if ($r < 96) return '#61b142';
    if ($r < 102) return '#42b168';
    if ($r < 112) return '#42b192';
    if ($r < 200) return '#428bb1';
    return '';
}

// Helper: Output rate cell with color
function rateCell($rate, $extraClass = '') {
    $color = getRateColor($rate);
    $style = $color ? " style=\"background-color: {$color}\"" : '';
    $val = ($rate !== null && $rate !== '') ? intval($rate) : '-';
    $class = "text-center" . ($extraClass ? " $extraClass" : '');
    echo "<td class=\"{$class}\"{$style}>{$val}</td>";
}

// Helper: Get modifier badge HTML
function getModifierBadge($code, $variant = null, $category = null) {
    // Category colors
    $categoryColors = [
        'PARALLEL_OPS'   => 'primary',    // Blue
        'APPROACH_TYPE'  => 'info',       // Purple/Cyan
        'TRAFFIC_BIAS'   => 'success',    // Green
        'VISIBILITY_CAT' => 'warning',    // Amber
        'SPECIAL_OPS'    => 'danger',     // Red
        'TIME_RESTRICT'  => 'secondary',  // Gray
        'WEATHER_OPS'    => 'info',       // Cyan
        'NAMED'          => 'dark',       // Dark
    ];

    $badgeClass = $categoryColors[$category] ?? 'secondary';
    $displayText = $code;
    if ($variant) {
        $displayText .= ':' . $variant;
    }
    return '<span class="badge badge-' . $badgeClass . ' badge-sm mr-1" data-toggle="tooltip" title="' . htmlspecialchars($code) . '">' . htmlspecialchars($displayText) . '</span>';
}

// Helper: Format runway with modifiers
function formatRunwayWithModifiers($runway, $modifiers) {
    $html = '<span class="runway-id">' . htmlspecialchars($runway['runway_id']);
    if (!empty($runway['intersection'])) {
        $html .= '@' . htmlspecialchars($runway['intersection']);
    }
    $html .= '</span>';

    // Add modifier badges
    if (!empty($modifiers)) {
        $html .= ' ';
        foreach ($modifiers as $mod) {
            $html .= '<span class="badge badge-outline-' . ($mod['badge_class'] ?? 'secondary') . ' badge-xs" title="' . htmlspecialchars($mod['description'] ?? '') . '">' . htmlspecialchars($mod['abbrev']) . '</span>';
        }
    }

    return $html;
}

// Helper: Format config name for better readability
function formatConfigName($configName, $arrRunways = null, $depRunways = null, $configCode = null) {
    // Check if it's a simple descriptive name (no slashes, not all caps with underscores)
    $isSimpleName = (strpos($configName, ' / ') === false && strpos($configName, '/') === false)
                    || preg_match('/^[A-Za-z]+ Flow$/i', $configName)
                    || preg_match('/^(North|South|East|West|Mixed|Balanced)/i', $configName);

    // Common flow direction keywords to detect simple names
    $flowKeywords = ['Flow', 'Config', 'Standard', 'Primary', 'Secondary', 'Alternate'];
    foreach ($flowKeywords as $keyword) {
        if (stripos($configName, $keyword) !== false) {
            $isSimpleName = true;
            break;
        }
    }

    if ($isSimpleName) {
        // Return simple name as-is with optional code
        $display = htmlspecialchars($configName);
        if ($configCode) {
            $display .= ' <small class="text-muted">(' . htmlspecialchars($configCode) . ')</small>';
        }
        return $display;
    }

    // Check for "ARR / DEP" pattern (runway config format)
    if (preg_match('/^(.+?)\s*\/\s*(.+)$/', $configName, $matches)) {
        $arrPart = trim($matches[1]);
        $depPart = trim($matches[2]);

        // Format with explicit labels
        $html = '<span class="config-formatted">';
        $html .= '<span class="config-arr"><span class="config-label">ARR:</span> ' . formatRunwayList($arrPart) . '</span>';
        $html .= '<span class="config-sep">|</span>';
        $html .= '<span class="config-dep"><span class="config-label">DEP:</span> ' . formatRunwayList($depPart) . '</span>';
        $html .= '</span>';

        // Add config code if present
        if ($configCode) {
            $html .= ' <small class="text-muted">(' . htmlspecialchars($configCode) . ')</small>';
        }

        return $html;
    }

    // Fallback: return original with code
    $display = htmlspecialchars($configName);
    if ($configCode) {
        $display .= ' <small class="text-muted">(' . htmlspecialchars($configCode) . ')</small>';
    }
    return $display;
}

// Helper: Format runway list for display (e.g., "04R/04L" -> "04R, 04L")
function formatRunwayList($runwayStr) {
    // Handle empty or dash
    if (empty($runwayStr) || $runwayStr === '-') {
        return '-';
    }

    // Split by common delimiters
    $runways = preg_split('/[\/,]/', $runwayStr);
    $formatted = [];

    foreach ($runways as $rwy) {
        $rwy = trim($rwy);
        if (!empty($rwy)) {
            // Clean up any embedded modifiers (they're now in the modifiers column)
            // e.g., "04R_ILS" -> "04R"
            if (preg_match('/^(\d{1,2}[LCR]?)(?:_|$)/', $rwy, $m)) {
                $formatted[] = '<span class="runway-id">' . htmlspecialchars($m[1]) . '</span>';
            } else {
                $formatted[] = '<span class="runway-id">' . htmlspecialchars($rwy) . '</span>';
            }
        }
    }

    return implode(' ', $formatted);
}

$search = isset($_GET['search']) ? get_input('search') : '';

// Check if ADL connection is available
if (!$conn_adl) {
    // Fallback to MySQL (legacy) - simplified output
    $query = mysqli_query($conn_sqli, "SELECT * FROM config_data WHERE airport LIKE '%$search%' ORDER BY airport ASC LIMIT 50");

    while ($data = mysqli_fetch_array($query)) {
        echo '<tr>';
        echo '<td class="text-center">' . htmlspecialchars($data['airport']) . '</td>';
        echo '<td class="text-center">K' . htmlspecialchars($data['airport']) . '</td>';
        echo '<td class="text-center">-</td>';
        echo '<td class="text-center">-</td>'; // Modifiers
        echo '<td class="text-center">' . htmlspecialchars($data['arr']) . '</td>';
        echo '<td class="text-center">' . htmlspecialchars($data['dep']) . '</td>';

        rateCell($data['vmc_aar'], 'section-divider');
        rateCell($data['lvmc_aar']);
        rateCell($data['imc_aar']);
        rateCell($data['limc_aar']);
        rateCell(null); // VLIMC not in legacy
        rateCell($data['vmc_adr']);
        rateCell($data['imc_adr']);
        // RW rates not available in legacy
        rateCell(null, 'section-divider');
        rateCell(null);
        rateCell(null);
        rateCell(null);
        rateCell(null);
        rateCell(null);

        if ($perm == true) {
            echo '<td><center>';
            echo '<a href="javascript:void(0)" data-toggle="tooltip" title="Update Field Configuration">';
            echo '<span class="badge badge-warning" data-toggle="modal" data-target="#updateconfigModal" ';
            echo 'data-id="' . $data['id'] . '" ';
            echo 'data-airport="' . htmlspecialchars($data['airport']) . '" ';
            echo 'data-arr="' . htmlspecialchars($data['arr']) . '" ';
            echo 'data-dep="' . htmlspecialchars($data['dep']) . '" ';
            echo 'data-vmc_aar="' . $data['vmc_aar'] . '" ';
            echo 'data-lvmc_aar="' . $data['lvmc_aar'] . '" ';
            echo 'data-imc_aar="' . $data['imc_aar'] . '" ';
            echo 'data-limc_aar="' . $data['limc_aar'] . '" ';
            echo 'data-vmc_adr="' . $data['vmc_adr'] . '" ';
            echo 'data-imc_adr="' . $data['imc_adr'] . '">';
            echo '<i class="fas fa-pencil-alt"></i> Update</span></a>';
            echo ' ';
            echo '<a href="javascript:void(0)" onclick="deleteConfig(' . $data['id'] . ')" data-toggle="tooltip" title="Delete Field Configuration">';
            echo '<span class="badge badge-danger"><i class="fas fa-times"></i> Delete</span></a>';
            echo '</center></td>';
        }
        echo '</tr>';
    }
} else {
    // Get active filter
    $activeFilter = isset($_GET['active']) ? $_GET['active'] : 'active';
    // Get modifier filter
    $modifierFilter = isset($_GET['modifier']) ? $_GET['modifier'] : '';

    // Use ADL SQL Server - Join summary and rates views
    // Include weather impact info and modifiers
    $sql = "
        SELECT
            s.config_id,
            s.airport_faa,
            s.airport_icao,
            s.config_name,
            s.config_code,
            s.is_active,
            s.arr_runways,
            s.dep_runways,
            r.vatsim_vmc_aar,
            r.vatsim_lvmc_aar,
            r.vatsim_imc_aar,
            r.vatsim_limc_aar,
            r.vatsim_vlimc_aar,
            r.vatsim_vmc_adr,
            r.vatsim_lvmc_adr,
            r.vatsim_imc_adr,
            r.vatsim_limc_adr,
            r.vatsim_vlimc_adr,
            r.rw_vmc_aar,
            r.rw_lvmc_aar,
            r.rw_imc_aar,
            r.rw_limc_aar,
            r.rw_vlimc_aar,
            r.rw_vmc_adr,
            r.rw_lvmc_adr,
            r.rw_imc_adr,
            r.rw_limc_adr,
            r.rw_vlimc_adr,
            -- Weather impact info
            (SELECT COUNT(*) FROM dbo.airport_weather_impact wi
             WHERE wi.airport_icao = s.airport_icao AND wi.is_active = 1) AS weather_rule_count,
            (SELECT MAX(COALESCE(wi.wind_cat, 0) + COALESCE(wi.cig_cat, 0) + COALESCE(wi.vis_cat, 0) + COALESCE(wi.wx_cat, 0))
             FROM dbo.airport_weather_impact wi
             WHERE wi.airport_icao = s.airport_icao AND wi.is_active = 1) AS max_weather_impact
        FROM dbo.vw_airport_config_summary s
        LEFT JOIN dbo.vw_airport_config_rates r ON s.config_id = r.config_id
        WHERE (s.airport_faa LIKE ? OR s.airport_icao LIKE ? OR s.config_name LIKE ?)
    ";

    // Add active filter
    if ($activeFilter === 'active') {
        $sql .= " AND s.is_active = 1";
    } elseif ($activeFilter === 'inactive') {
        $sql .= " AND s.is_active = 0";
    }

    // Add modifier filter if specified
    if ($modifierFilter) {
        $sql .= " AND EXISTS (SELECT 1 FROM dbo.config_modifier cm WHERE cm.config_id = s.config_id AND cm.modifier_code = ?)";
    }

    $sql .= " ORDER BY s.airport_faa ASC, s.config_name ASC";

    $searchParam = '%' . $search . '%';
    $params = [$searchParam, $searchParam, $searchParam];
    if ($modifierFilter) {
        $params[] = $modifierFilter;
    }

    $stmt = sqlsrv_query($conn_adl, $sql, $params);

    if ($stmt === false) {
        error_log("ADL configs query failed: " . adl_sql_error_message());
        echo '<tr><td colspan="20" class="text-center text-danger">Error loading configurations</td></tr>';
    } else {
        // Fetch modifiers for all configs (batch query for efficiency)
        $modifiersQuery = "
            SELECT
                cm.config_id,
                cm.runway_id,
                cm.modifier_code,
                cm.original_value,
                cm.variant_value,
                mt.display_name,
                mt.abbrev,
                mt.description,
                mc.category_code,
                mc.category_name,
                mc.color_hex
            FROM dbo.config_modifier cm
            JOIN dbo.modifier_type mt ON cm.modifier_code = mt.modifier_code
            JOIN dbo.modifier_category mc ON mt.category_code = mc.category_code
            ORDER BY cm.config_id, mc.display_order, cm.runway_id
        ";
        $modifiersStmt = sqlsrv_query($conn_adl, $modifiersQuery);
        $allModifiers = [];
        if ($modifiersStmt) {
            while ($mod = sqlsrv_fetch_array($modifiersStmt, SQLSRV_FETCH_ASSOC)) {
                $configId = $mod['config_id'];
                if (!isset($allModifiers[$configId])) {
                    $allModifiers[$configId] = ['config' => [], 'runways' => []];
                }
                if ($mod['runway_id'] === null) {
                    $allModifiers[$configId]['config'][] = $mod;
                } else {
                    if (!isset($allModifiers[$configId]['runways'][$mod['runway_id']])) {
                        $allModifiers[$configId]['runways'][$mod['runway_id']] = [];
                    }
                    $allModifiers[$configId]['runways'][$mod['runway_id']][] = $mod;
                }
            }
            sqlsrv_free_stmt($modifiersStmt);
        }

        // Fetch runway details with intersections
        $runwaysQuery = "
            SELECT
                r.config_id,
                r.runway_id,
                r.runway_use,
                r.priority,
                r.intersection
            FROM dbo.airport_config_runway r
            ORDER BY r.config_id, r.runway_use, r.priority
        ";
        $runwaysStmt = sqlsrv_query($conn_adl, $runwaysQuery);
        $allRunways = [];
        if ($runwaysStmt) {
            while ($rwy = sqlsrv_fetch_array($runwaysStmt, SQLSRV_FETCH_ASSOC)) {
                $configId = $rwy['config_id'];
                if (!isset($allRunways[$configId])) {
                    $allRunways[$configId] = ['ARR' => [], 'DEP' => []];
                }
                $use = $rwy['runway_use'];
                if ($use === 'ARR' || $use === 'BOTH') {
                    $allRunways[$configId]['ARR'][] = $rwy;
                }
                if ($use === 'DEP' || $use === 'BOTH') {
                    $allRunways[$configId]['DEP'][] = $rwy;
                }
            }
            sqlsrv_free_stmt($runwaysStmt);
        }

        while ($data = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $configId = $data['config_id'];
            $isActive = $data['is_active'] ?? true;
            $rowClass = $isActive ? '' : 'config-inactive';

            $configMods = $allModifiers[$configId]['config'] ?? [];
            $runwayMods = $allModifiers[$configId]['runways'] ?? [];
            $runways = $allRunways[$configId] ?? ['ARR' => [], 'DEP' => []];
            $configModifierCodes = [];
            foreach ($configMods as $mod) {
                $modCode = strtoupper(trim($mod['modifier_code'] ?? ''));
                if ($modCode !== '') {
                    $configModifierCodes[$modCode] = true;
                }
            }
            $configModifierCsv = implode(',', array_keys($configModifierCodes));

            echo '<tr class="' . $rowClass . '" data-active="' . ($isActive ? 'true' : 'false') . '" data-config-id="' . $configId . '">';

            // Checkbox column for bulk selection (admin only)
            if ($perm == true) {
                echo '<td class="text-center"><input type="checkbox" class="row-checkbox bulk-checkbox" data-id="' . $configId . '"></td>';
            }

            // Airport FAA
            echo '<td class="text-center font-weight-bold">' . htmlspecialchars($data['airport_faa']) . '</td>';

            // Airport ICAO with weather impact indicator
            $icaoHtml = htmlspecialchars($data['airport_icao']);
            $weatherRules = $data['weather_rule_count'] ?? 0;
            if ($weatherRules > 0) {
                $maxImpact = min(3, intval($data['max_weather_impact'] ?? 0));
                $impactTitles = ['No impact', 'Minor impact rules', 'Moderate impact rules', 'Major impact rules'];
                $icaoHtml .= ' <span class="weather-impact weather-impact-' . $maxImpact . '" '
                          . 'data-toggle="tooltip" title="' . $weatherRules . ' weather rule(s) - ' . $impactTitles[$maxImpact] . '"></span>';
            }
            echo '<td class="text-center">' . $icaoHtml . '</td>';

            // Config Name (formatted for readability)
            $configDisplay = formatConfigName(
                $data['config_name'],
                $data['arr_runways'] ?? null,
                $data['dep_runways'] ?? null,
                $data['config_code'] ?? null
            );
            echo '<td class="text-center config-name-cell">' . $configDisplay . '</td>';

            // Modifiers column (config-level and runway-level combined as badges)
            echo '<td class="text-center modifiers-cell">';
            $badgeHtml = '';

            // Config-level modifiers first
            foreach ($configMods as $mod) {
                $categoryColors = [
                    'PARALLEL_OPS'   => 'primary',
                    'APPROACH_TYPE'  => 'info',
                    'TRAFFIC_BIAS'   => 'success',
                    'VISIBILITY_CAT' => 'warning',
                    'SPECIAL_OPS'    => 'danger',
                    'TIME_RESTRICT'  => 'secondary',
                    'WEATHER_OPS'    => 'info',
                    'NAMED'          => 'dark',
                ];
                $badgeClass = $categoryColors[$mod['category_code']] ?? 'secondary';
                $label = $mod['abbrev'];
                if ($mod['variant_value']) {
                    $label .= ':' . $mod['variant_value'];
                }
                $badgeHtml .= '<span class="badge badge-' . $badgeClass . ' mr-1" data-toggle="tooltip" title="' . htmlspecialchars($mod['display_name'] . ': ' . $mod['description']) . '">' . htmlspecialchars($label) . '</span>';
            }

            // Runway-level modifiers (unique only)
            $seenMods = [];
            foreach ($runwayMods as $rwyId => $mods) {
                foreach ($mods as $mod) {
                    $key = $mod['modifier_code'] . ':' . ($mod['variant_value'] ?? '');
                    if (!isset($seenMods[$key])) {
                        $seenMods[$key] = true;
                        $categoryColors = [
                            'PARALLEL_OPS'   => 'primary',
                            'APPROACH_TYPE'  => 'info',
                            'TRAFFIC_BIAS'   => 'success',
                            'VISIBILITY_CAT' => 'warning',
                            'SPECIAL_OPS'    => 'danger',
                            'TIME_RESTRICT'  => 'secondary',
                            'WEATHER_OPS'    => 'info',
                            'NAMED'          => 'dark',
                        ];
                        $badgeClass = $categoryColors[$mod['category_code']] ?? 'secondary';
                        $label = $mod['abbrev'];
                        if ($mod['variant_value']) {
                            $label .= ':' . $mod['variant_value'];
                        }
                        $badgeHtml .= '<span class="badge badge-outline-' . $badgeClass . ' mr-1" data-toggle="tooltip" title="' . htmlspecialchars($mod['display_name'] . ' (' . $rwyId . '): ' . $mod['description']) . '">' . htmlspecialchars($label) . '</span>';
                    }
                }
            }

            echo $badgeHtml ?: '<span class="text-muted">-</span>';
            echo '</td>';

            // Arrival Runways (formatted with intersections)
            echo '<td class="text-center runway-cell">';
            $arrHtml = [];
            foreach ($runways['ARR'] as $rwy) {
                $rwyText = $rwy['runway_id'];
                if (!empty($rwy['intersection'])) {
                    $rwyText .= '@' . $rwy['intersection'];
                }
                // Add runway-specific modifier indicators
                if (isset($runwayMods[$rwy['runway_id']])) {
                    $rwyMods = $runwayMods[$rwy['runway_id']];
                    $modAbbrevs = [];
                    foreach ($rwyMods as $m) {
                        if (in_array($m['category_code'], ['APPROACH_TYPE', 'PARALLEL_OPS'])) {
                            $modAbbrevs[] = $m['abbrev'];
                        }
                    }
                    if ($modAbbrevs) {
                        $rwyText .= ' <small class="text-info">(' . implode(',', $modAbbrevs) . ')</small>';
                    }
                }
                $arrHtml[] = $rwyText;
            }
            echo implode('<br>', $arrHtml) ?: '-';
            echo '</td>';

            // Departure Runways
            echo '<td class="text-center runway-cell">';
            $depHtml = [];
            foreach ($runways['DEP'] as $rwy) {
                $rwyText = $rwy['runway_id'];
                if (!empty($rwy['intersection'])) {
                    $rwyText .= '@' . $rwy['intersection'];
                }
                $depHtml[] = $rwyText;
            }
            echo implode('<br>', $depHtml) ?: '-';
            echo '</td>';

            // VATSIM Rates (ARR: VMC, LVMC, IMC, LIMC, VLIMC)
            rateCell($data['vatsim_vmc_aar'], 'section-divider');
            rateCell($data['vatsim_lvmc_aar']);
            rateCell($data['vatsim_imc_aar']);
            rateCell($data['vatsim_limc_aar']);
            rateCell($data['vatsim_vlimc_aar']);

            // VATSIM Rates (DEP: VMC, IMC)
            rateCell($data['vatsim_vmc_adr']);
            rateCell($data['vatsim_imc_adr']);

            // Real-World Rates (ARR: VMC, LVMC, IMC, LIMC)
            rateCell($data['rw_vmc_aar'], 'section-divider');
            rateCell($data['rw_lvmc_aar']);
            rateCell($data['rw_imc_aar']);
            rateCell($data['rw_limc_aar']);

            // Real-World Rates (DEP: VMC, IMC)
            rateCell($data['rw_vmc_adr']);
            rateCell($data['rw_imc_adr']);

            // Actions
            if ($perm == true) {
                echo '<td><center>';

                // Build data attributes for modal
                echo '<a href="javascript:void(0)" data-toggle="tooltip" title="Update Field Configuration">';
                echo '<span class="badge badge-warning" data-toggle="modal" data-target="#updateconfigModal" ';
                echo 'data-config_id="' . $configId . '" ';
                echo 'data-airport_faa="' . htmlspecialchars($data['airport_faa']) . '" ';
                echo 'data-airport_icao="' . htmlspecialchars($data['airport_icao']) . '" ';
                echo 'data-config_name="' . htmlspecialchars($data['config_name']) . '" ';
                echo 'data-config_code="' . htmlspecialchars($data['config_code'] ?? '') . '" ';
                echo 'data-arr_runways="' . htmlspecialchars($data['arr_runways'] ?? '') . '" ';
                echo 'data-dep_runways="' . htmlspecialchars($data['dep_runways'] ?? '') . '" ';
                echo 'data-config_modifiers="' . htmlspecialchars($configModifierCsv) . '" ';

                // VATSIM rates
                echo 'data-vatsim_vmc_aar="' . ($data['vatsim_vmc_aar'] ?? '') . '" ';
                echo 'data-vatsim_lvmc_aar="' . ($data['vatsim_lvmc_aar'] ?? '') . '" ';
                echo 'data-vatsim_imc_aar="' . ($data['vatsim_imc_aar'] ?? '') . '" ';
                echo 'data-vatsim_limc_aar="' . ($data['vatsim_limc_aar'] ?? '') . '" ';
                echo 'data-vatsim_vlimc_aar="' . ($data['vatsim_vlimc_aar'] ?? '') . '" ';
                echo 'data-vatsim_vmc_adr="' . ($data['vatsim_vmc_adr'] ?? '') . '" ';
                echo 'data-vatsim_lvmc_adr="' . ($data['vatsim_lvmc_adr'] ?? '') . '" ';
                echo 'data-vatsim_imc_adr="' . ($data['vatsim_imc_adr'] ?? '') . '" ';
                echo 'data-vatsim_limc_adr="' . ($data['vatsim_limc_adr'] ?? '') . '" ';
                echo 'data-vatsim_vlimc_adr="' . ($data['vatsim_vlimc_adr'] ?? '') . '" ';

                // Real-World rates
                echo 'data-rw_vmc_aar="' . ($data['rw_vmc_aar'] ?? '') . '" ';
                echo 'data-rw_lvmc_aar="' . ($data['rw_lvmc_aar'] ?? '') . '" ';
                echo 'data-rw_imc_aar="' . ($data['rw_imc_aar'] ?? '') . '" ';
                echo 'data-rw_limc_aar="' . ($data['rw_limc_aar'] ?? '') . '" ';
                echo 'data-rw_vlimc_aar="' . ($data['rw_vlimc_aar'] ?? '') . '" ';
                echo 'data-rw_vmc_adr="' . ($data['rw_vmc_adr'] ?? '') . '" ';
                echo 'data-rw_lvmc_adr="' . ($data['rw_lvmc_adr'] ?? '') . '" ';
                echo 'data-rw_imc_adr="' . ($data['rw_imc_adr'] ?? '') . '" ';
                echo 'data-rw_limc_adr="' . ($data['rw_limc_adr'] ?? '') . '" ';
                echo 'data-rw_vlimc_adr="' . ($data['rw_vlimc_adr'] ?? '') . '">';

                echo '<i class="fas fa-pencil-alt"></i> Update</span></a>';
                echo ' ';
                echo '<a href="javascript:void(0)" onclick="showHistory(' . $configId . ', \'' . htmlspecialchars($data['airport_faa'], ENT_QUOTES) . '\', \'' . htmlspecialchars($data['config_name'], ENT_QUOTES) . '\')" data-toggle="tooltip" title="View Rate Change History">';
                echo '<span class="badge badge-info"><i class="fas fa-history"></i> History</span></a>';
                echo ' ';
                echo '<a href="javascript:void(0)" onclick="deleteConfig(' . $configId . ')" data-toggle="tooltip" title="Delete Field Configuration">';
                echo '<span class="badge badge-danger"><i class="fas fa-times"></i> Delete</span></a>';
                echo '</center></td>';
            }

            echo '</tr>';
        }

        sqlsrv_free_stmt($stmt);
    }
}

?>
