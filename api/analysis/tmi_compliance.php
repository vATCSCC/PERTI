<?php
/**
 * TMI Compliance Analysis API
 *
 * Retrieves and manages TMI compliance analysis results for PERTI reviews.
 * Analysis is performed by local Python script (scripts/tmi_compliance/run.py).
 *
 * Endpoints:
 *   GET  ?p_id={plan_id}           - Get cached compliance results for a plan
 *   GET  ?p_id={plan_id}&run=true  - Run analysis and return results
 *   POST                           - Save compliance results for a plan
 */

header('Content-Type: application/json');

// Large compliance datasets (trajectories) need more than default 128MB
ini_set('memory_limit', '512M');

include("../../load/config.php");

// ADL Database connection
$adl_server = ADL_SQL_HOST;
$adl_db = ADL_SQL_DATABASE;
$adl_user = ADL_SQL_USERNAME;
$adl_pass = ADL_SQL_PASSWORD;

$connectionInfo = [
    "Database" => $adl_db,
    "UID" => $adl_user,
    "PWD" => $adl_pass,
    "TrustServerCertificate" => true,
    "LoginTimeout" => 30
];

try {
    $conn = sqlsrv_connect($adl_server, $connectionInfo);
    if ($conn === false) {
        throw new Exception("ADL connection failed: " . print_r(sqlsrv_errors(), true));
    }

    $response = [
        'success' => true,
        'data' => null,
        'message' => ''
    ];

    // Handle GET requests
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $plan_id = isset($_GET['p_id']) ? intval($_GET['p_id']) : 0;

        if ($plan_id <= 0) {
            throw new Exception("Invalid or missing plan ID");
        }

        // Check if we should run analysis via Azure Function
        if (isset($_GET['run']) && $_GET['run'] === 'true') {
            $result = call_azure_function($plan_id);

            if ($result['success']) {
                // Save results to file for caching
                $base_path = realpath(__DIR__ . '/../../data/tmi_compliance');
                if (!$base_path) {
                    $base_path = __DIR__ . '/../../data/tmi_compliance';
                }
                $results_path = $base_path . '/tmi_compliance_results_' . $plan_id . '.json';

                // Ensure directory exists
                if (!is_dir($base_path)) {
                    mkdir($base_path, 0755, true);
                }

                file_put_contents($results_path, json_encode($result['data'], JSON_PRETTY_PRINT));

                $response['data'] = format_results($result['data']);
                try {
                    $response['data'] = enhance_with_branches($response['data'], $conn);
                } catch (\Throwable $e) {
                    // Branch analysis is non-fatal; log and continue
                    error_log("Branch analysis failed: " . $e->getMessage());
                }
                $response['data']['plan_specific'] = true;
                $response['message'] = 'Analysis completed successfully';
            } else {
                // Return error as normal response (not 500) for user-facing errors
                $response['success'] = false;
                $response['message'] = $result['error'] ?? 'Analysis failed';
            }
        } else {
            // Load cached results from file
            $base_path = realpath(__DIR__ . '/../../data/tmi_compliance');
            if (!$base_path) {
                $base_path = __DIR__ . '/../../data/tmi_compliance';
            }

            $plan_json_path = $base_path . '/tmi_compliance_results_' . $plan_id . '.json';
            $json_path = $plan_json_path;
            $using_plan_specific = true;

            if (file_exists($json_path)) {
                $json_content = file_get_contents($json_path);
                $results = json_decode($json_content, true);

                if ($results) {
                    $response['data'] = format_results($results);
                    try {
                        $response['data'] = enhance_with_branches($response['data'], $conn);
                    } catch (\Throwable $e) {
                        error_log("Branch analysis failed: " . $e->getMessage());
                    }
                    $response['data']['plan_specific'] = $using_plan_specific;
                    $response['message'] = "Results loaded for plan $plan_id";
                } else {
                    throw new Exception("Failed to parse results JSON");
                }
            } else {
                $response['data'] = null;
                $response['message'] = "No analysis results found for plan $plan_id. Click 'Run Analysis' to generate.";
            }
        }
    }

    // Handle POST requests (save results to database)
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        $plan_id = isset($input['p_id']) ? intval($input['p_id']) : 0;
        $results = isset($input['results']) ? $input['results'] : null;

        if ($plan_id <= 0) {
            throw new Exception("Invalid or missing plan ID");
        }

        if (!$results) {
            throw new Exception("No results data provided");
        }

        // In future: Save to database
        // For now, acknowledge receipt
        $response['message'] = 'Results saved for plan ' . $plan_id;
        $response['data'] = ['plan_id' => $plan_id];
    }

    sqlsrv_close($conn);

    echo json_encode($response, JSON_PRETTY_PRINT);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}

/**
 * Run TMI compliance analysis via local Python script
 */
function call_azure_function($plan_id) {
    // Path to Python script (relative to web root)
    $script_dir = realpath(__DIR__ . '/../../scripts/tmi_compliance');
    $script_path = $script_dir . '/run.py';

    if (!file_exists($script_path)) {
        return [
            'success' => false,
            'error' => "TMI Analysis script not found at: $script_path"
        ];
    }

    // Set environment variables for database connections and Python path
    $env_vars = [
        'ADL_SQL_HOST' => defined('ADL_SQL_HOST') ? ADL_SQL_HOST : 'vatsim.database.windows.net',
        'ADL_SQL_DATABASE' => defined('ADL_SQL_DATABASE') ? ADL_SQL_DATABASE : 'VATSIM_ADL',
        'ADL_SQL_USERNAME' => defined('ADL_SQL_USERNAME') ? ADL_SQL_USERNAME : 'adl_api_user',
        'ADL_SQL_PASSWORD' => defined('ADL_SQL_PASSWORD') ? ADL_SQL_PASSWORD : '',
        'GIS_SQL_HOST' => defined('GIS_SQL_HOST') ? GIS_SQL_HOST : 'vatcscc-gis.postgres.database.azure.com',
        'GIS_SQL_DATABASE' => defined('GIS_SQL_DATABASE') ? GIS_SQL_DATABASE : 'VATSIM_GIS',
        'GIS_SQL_USERNAME' => defined('GIS_SQL_USERNAME') ? GIS_SQL_USERNAME : 'GIS_admin',
        'GIS_SQL_PASSWORD' => defined('GIS_SQL_PASSWORD') ? GIS_SQL_PASSWORD : '',
        'PERTI_API_URL' => 'https://perti.vatcscc.org/api',
        // Include user site-packages where pip installed dependencies
        'PYTHONUSERBASE' => '/home/.local',
        'PYTHONPATH' => '/home/.local/lib/python3.9/site-packages',
        'PATH' => '/home/.local/bin:/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin'
    ];

    // Build environment string for command
    $env_prefix = '';
    foreach ($env_vars as $key => $value) {
        putenv("$key=$value");
    }

    // Find Python executable
    $python = 'python3';
    if (PHP_OS_FAMILY === 'Windows') {
        $python = 'python';
    }

    // Build command with environment variables inline (for Linux)
    // This ensures pip-installed packages in /home/.local are found
    $env_prefix = '';
    if (PHP_OS_FAMILY !== 'Windows') {
        $env_parts = ['PYTHONPATH=/home/.local/lib/python3.9/site-packages'];
        foreach ($env_vars as $key => $value) {
            if (!in_array($key, ['PYTHONUSERBASE', 'PYTHONPATH', 'PATH'])) {
                $env_parts[] = sprintf('%s=%s', $key, escapeshellarg($value));
            }
        }
        $env_prefix = implode(' ', $env_parts) . ' ';
    }

    $cmd = sprintf(
        '%s%s %s --plan_id %d 2>&1',
        $env_prefix,
        escapeshellcmd($python),
        escapeshellarg($script_path),
        intval($plan_id)
    );

    // Execute with timeout
    $output = [];
    $return_code = 0;
    exec($cmd, $output, $return_code);

    $json_output = implode("\n", $output);

    // Try to extract JSON from output (skip log lines)
    $lines = explode("\n", $json_output);
    $json_line = '';
    foreach (array_reverse($lines) as $line) {
        $line = trim($line);
        if (str_starts_with($line, '{') || str_starts_with($line, '[')) {
            $json_line = $line;
            break;
        }
    }

    if (empty($json_line)) {
        return [
            'success' => false,
            'error' => "No JSON output from Python script. Output: " . substr($json_output, 0, 500)
        ];
    }

    $data = json_decode($json_line, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        return [
            'success' => false,
            'error' => 'Invalid JSON from Python: ' . json_last_error_msg() . '. Output: ' . substr($json_line, 0, 200)
        ];
    }

    if (isset($data['error'])) {
        return ['success' => false, 'error' => $data['error']];
    }

    return ['success' => true, 'data' => $data];
}

/**
 * Format results for frontend display
 */
function format_results($results) {
    $formatted = [
        'event' => $results['event'] ?? 'Unknown Event',
        'event_start' => $results['event_start'] ?? null,
        'event_end' => $results['event_end'] ?? null,
        'generated_utc' => $results['generated_utc'] ?? null,
        'summary' => $results['summary'] ?? [],
        'mit_results' => [],
        'gs_results' => [],
        'apreq_results' => []
    ];

    // Process MIT results
    if (isset($results['mit_results'])) {
        foreach ($results['mit_results'] as $key => $r) {
            $formatted['mit_results'][] = [
                'fix' => $r['fix'] ?? $key,
                'required' => $r['required'] ?? 0,
                'unit' => $r['unit'] ?? 'nm',
                'tmi_start' => $r['tmi_start'] ?? '',
                'tmi_end' => $r['tmi_end'] ?? '',
                'crossings' => $r['total_crossings'] ?? 0,
                'valid_crossings' => $r['valid_crossings'] ?? 0,
                'pairs' => $r['pairs'] ?? 0,
                'compliance_pct' => $r['compliance_pct'] ?? 0,
                'distribution' => $r['distribution'] ?? [],
                'violations' => $r['violations'] ?? [],
                'spacing_stats' => [
                    'min' => $r['spacing_stats']['min'] ?? ($r['min_spacing'] ?? 0),
                    'avg' => $r['spacing_stats']['avg'] ?? ($r['avg_spacing'] ?? 0),
                    'max' => $r['spacing_stats']['max'] ?? ($r['max_spacing'] ?? 0)
                ],
                'cancelled' => $r['cancelled'] ?? false,
                // Include detailed pair data for flight-level analysis
                'all_pairs' => $r['all_pairs'] ?? [],
                // Measurement metadata (boundary vs fix)
                'measurement_type' => $r['measurement_type'] ?? 'FIX',
                'measurement_point' => $r['measurement_point'] ?? ($r['fix'] ?? ''),
                // Airway identifier if fix is an airway (for map display)
                'airway' => preg_match('/^(J|V|Q|T|Y|A|UL|UA|UB|UM|UN|L|M|N|AR|G|B|W|R)\d+$/i', $r['fix'] ?? '') ? strtoupper($r['fix']) : null,
                // Additional TMI metadata
                'destinations' => $r['destinations'] ?? [],
                'origins' => $r['origins'] ?? [],
                'provider' => $r['provider'] ?? '',
                'requestor' => $r['requestor'] ?? '',
                'is_multiple' => $r['is_multiple'] ?? false,
                // Flight trajectory data for map rendering
                'trajectories' => $r['trajectories'] ?? [],
                // Traffic flow sector data (for flow cone visualization)
                'traffic_sector' => $r['traffic_sector'] ?? null,
                // Fix coordinate data (for measurement point marker)
                'fix_info' => $r['fix_info'] ?? null
            ];
        }
    }

    // Process GS results
    if (isset($results['gs_results'])) {
        foreach ($results['gs_results'] as $key => $r) {
            $formatted['gs_results'][] = [
                'destinations' => $r['destinations'] ?? [],
                'origins' => $r['origins'] ?? [],
                'gs_start' => $r['gs_start'] ?? '',
                'gs_end' => $r['gs_end'] ?? '',
                'gs_issued' => $r['gs_issued'] ?? '',
                'total_flights' => $r['total_flights'] ?? 0,
                'exempt_count' => is_array($r['exempt'] ?? null) ? count($r['exempt']) : ($r['exempt'] ?? 0),
                'compliant_count' => is_array($r['compliant'] ?? null) ? count($r['compliant']) : ($r['compliant'] ?? 0),
                'non_compliant_count' => is_array($r['non_compliant'] ?? null) ? count($r['non_compliant']) : ($r['non_compliant'] ?? 0),
                'compliance_pct' => $r['compliance_pct'] ?? 0,
                'violations' => $r['violations'] ?? [],
                // Include full flight lists for detailed analysis
                'exempt_flights' => is_array($r['exempt'] ?? null) ? $r['exempt'] : [],
                'compliant_flights' => is_array($r['compliant'] ?? null) ? $r['compliant'] : [],
                'non_compliant_flights' => is_array($r['non_compliant'] ?? null) ? $r['non_compliant'] : []
            ];
        }
    }

    // Process APREQ results
    if (isset($results['apreq_results'])) {
        foreach ($results['apreq_results'] as $key => $r) {
            $formatted['apreq_results'][] = [
                'fix' => $r['fix'] ?? 'ALL',
                'destinations' => $r['destinations'] ?? [],
                'origins' => $r['origins'] ?? [],
                'tmi_start' => $r['tmi_start'] ?? '',
                'tmi_end' => $r['tmi_end'] ?? '',
                'issued_utc' => $r['issued_utc'] ?? null,
                'cancelled' => $r['cancelled'] ?? false,
                'total_flights' => $r['total_flights'] ?? 0,
                'exempt_count' => $r['exempt_count'] ?? 0,
                'affected_count' => $r['affected_count'] ?? 0,
                'post_tmi_count' => $r['post_tmi_count'] ?? 0,
                'exempt_flights' => $r['exempt_flights'] ?? [],
                'affected_flights' => $r['affected_flights'] ?? [],
                'post_tmi_flights' => $r['post_tmi_flights'] ?? [],
                'provider' => $r['provider'] ?? '',
                'requestor' => $r['requestor'] ?? '',
                'is_multiple' => $r['is_multiple'] ?? false,
                'note' => $r['note'] ?? 'APREQ/CFR requires coordination verification'
            ];
        }
    }

    return $formatted;
}

/**
 * Enhance formatted results with branch corridor analysis.
 *
 * For each MIT result with sufficient trajectory data, calls the GIS
 * branch_analysis API to identify upstream traffic branches, then computes
 * per-branch compliance metrics from all_pairs data.
 *
 * Adds a 'branch_corridors' object to each MIT result containing:
 *   - branches: Array of identified branches with metadata
 *   - flight_assignments: Callsign → branch_id mapping
 *   - branch_metrics: Per-branch compliance stats
 */
function enhance_with_branches(array $formatted, $conn): array
{
    if (empty($formatted['mit_results'])) {
        return $formatted;
    }

    // Skip if memory is already above 75% of limit to avoid OOM
    $memLimitStr = ini_get('memory_limit');
    $memLimitBytes = (int)$memLimitStr;
    if (stripos($memLimitStr, 'G') !== false) $memLimitBytes *= 1024 * 1024 * 1024;
    elseif (stripos($memLimitStr, 'M') !== false) $memLimitBytes *= 1024 * 1024;
    elseif (stripos($memLimitStr, 'K') !== false) $memLimitBytes *= 1024;
    if ($memLimitBytes > 0 && memory_get_usage(true) > $memLimitBytes * 0.75) {
        error_log("Branch analysis skipped: memory usage too high (" . round(memory_get_usage(true) / 1024 / 1024) . "MB)");
        return $formatted;
    }

    foreach ($formatted['mit_results'] as &$mit) {
        $trajectories = $mit['trajectories'] ?? [];
        if (empty($trajectories) || count($trajectories) < 3) {
            continue; // Need at least 3 flights for meaningful branching
        }

        // Get fix coordinates from fix_info
        $fixInfo = $mit['fix_info'] ?? null;
        if (!$fixInfo || !isset($fixInfo['lat'], $fixInfo['lon'])) {
            continue;
        }

        // Look up flight O/D metadata from ADL (graceful fallback)
        $callsigns = [];
        foreach ($trajectories as $key => $traj) {
            $callsigns[] = $traj['callsign'] ?? $key;
        }
        $flightMeta = lookup_flight_meta($conn, $callsigns);

        // Build trajectories array for GIS API (ensure callsign + coordinates format)
        $gisTrajectories = [];
        foreach ($trajectories as $key => $traj) {
            $cs = $traj['callsign'] ?? $key;
            $coords = $traj['coordinates'] ?? [];
            if (count($coords) < 2) continue;
            $gisTrajectories[] = ['callsign' => $cs, 'coordinates' => $coords];
        }

        if (count($gisTrajectories) < 3) continue;

        // Build known fixes from fix_info (the measurement point itself)
        $knownFixes = [];
        $fixName = $mit['fix'] ?? $mit['measurement_point'] ?? '';
        if ($fixName && isset($fixInfo['lat'], $fixInfo['lon'])) {
            $knownFixes[] = [
                'id' => $fixName,
                'lat' => (float)$fixInfo['lat'],
                'lon' => (float)$fixInfo['lon'],
            ];
        }

        // Call GIS branch_analysis API
        $gisPayload = [
            'trajectories' => $gisTrajectories,
            'fix_point' => [(float)$fixInfo['lon'], (float)$fixInfo['lat']],
            'mit_distance_nm' => (float)($mit['required'] ?? 15),
            'max_distance_nm' => 250,
            'flight_meta' => $flightMeta,
            'tmi_type' => 'arrival',
            'cluster_eps_nm' => 3,
            'cluster_min_points' => 3,
            'known_fixes' => $knownFixes,
        ];

        $gisResult = call_gis_branch_analysis($gisPayload);
        if (!$gisResult || empty($gisResult['branches'])) {
            continue;
        }

        // Compute per-branch compliance metrics from all_pairs
        $branchMetrics = compute_branch_metrics(
            $mit['all_pairs'] ?? [],
            $gisResult['flight_assignments'] ?? []
        );

        // Add branch_corridors to this MIT result
        $mit['branch_corridors'] = [
            'branches' => $gisResult['branches'],
            'flight_assignments' => $gisResult['flight_assignments'],
            'branch_metrics' => $branchMetrics,
            'total_flights' => $gisResult['total_flights'] ?? count($callsigns),
            'branch_count' => $gisResult['branch_count'] ?? count($gisResult['branches']),
            'ungrouped_flights' => $gisResult['ungrouped_flights'] ?? 0,
        ];
    }

    return $formatted;
}

/**
 * Look up flight O/D metadata from ADL for branch grouping.
 * Returns {callsign: {dept, dest}} or empty values if not found.
 */
function lookup_flight_meta($conn, array $callsigns): array
{
    if (empty($callsigns) || !$conn) {
        return [];
    }

    $meta = [];

    // Build parameterized IN clause for sqlsrv
    $params = [];
    $placeholders = [];
    foreach ($callsigns as $i => $cs) {
        $placeholders[] = '?';
        $params[] = $cs;
    }
    $inClause = implode(',', $placeholders);

    // Query normalized tables (active flights)
    $sql = "
        SELECT c.callsign, p.fp_dept_icao, p.fp_dest_icao
        FROM adl_flight_core c
        JOIN adl_flight_plan p ON c.flight_uid = p.flight_uid
        WHERE c.callsign IN ($inClause)
    ";

    $stmt = sqlsrv_query($conn, $sql, $params);
    if ($stmt) {
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $cs = $row['callsign'];
            if (!isset($meta[$cs])) {
                $meta[$cs] = [
                    'dept' => $row['fp_dept_icao'] ?? 'UNK',
                    'dest' => $row['fp_dest_icao'] ?? 'UNK',
                ];
            }
        }
        sqlsrv_free_stmt($stmt);
    }

    // For callsigns not found in active flights, try legacy table
    $missing = array_diff($callsigns, array_keys($meta));
    if (!empty($missing)) {
        $params2 = [];
        $placeholders2 = [];
        foreach ($missing as $cs) {
            $placeholders2[] = '?';
            $params2[] = $cs;
        }
        $inClause2 = implode(',', $placeholders2);

        $sql2 = "
            SELECT callsign, fp_dept_icao, fp_dest_icao
            FROM adl_flights
            WHERE callsign IN ($inClause2)
        ";

        $stmt2 = sqlsrv_query($conn, $sql2, $params2);
        if ($stmt2) {
            while ($row = sqlsrv_fetch_array($stmt2, SQLSRV_FETCH_ASSOC)) {
                $cs = $row['callsign'];
                if (!isset($meta[$cs])) {
                    $meta[$cs] = [
                        'dept' => $row['fp_dept_icao'] ?? 'UNK',
                        'dest' => $row['fp_dest_icao'] ?? 'UNK',
                    ];
                }
            }
            sqlsrv_free_stmt($stmt2);
        }
    }

    return $meta;
}

/**
 * Call the GIS track_density API for branch analysis.
 * Uses server-side HTTP request to the same host.
 */
function call_gis_branch_analysis(array $payload): ?array
{
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost';
    $url = "$protocol://$host/api/gis/track_density.php?action=branch_analysis";

    $jsonPayload = json_encode($payload);

    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\nContent-Length: " . strlen($jsonPayload) . "\r\n",
            'content' => $jsonPayload,
            'timeout' => 30,
        ],
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false,
        ],
    ]);

    $response = @file_get_contents($url, false, $context);
    if ($response === false) {
        return null;
    }

    $data = json_decode($response, true);
    if (!$data || !($data['success'] ?? false)) {
        return null;
    }

    return $data['data'] ?? null;
}

/**
 * Compute per-branch compliance metrics from all_pairs data.
 *
 * For each branch, filters pairs where BOTH flights belong to the same
 * branch and computes compliance statistics.
 *
 * @param array $allPairs           All flight pairs with spacing data
 * @param array $flightAssignments  Callsign → branch_id mapping
 * @return array Branch_id → metrics mapping
 */
function compute_branch_metrics(array $allPairs, array $flightAssignments): array
{
    if (empty($allPairs) || empty($flightAssignments)) {
        return [];
    }

    $branchPairs = []; // branch_id => [pairs]

    foreach ($allPairs as $pair) {
        $lead = $pair['prev_callsign'] ?? $pair['lead_callsign'] ?? '';
        $trail = $pair['curr_callsign'] ?? $pair['trail_callsign'] ?? '';

        $leadBranch = $flightAssignments[$lead] ?? null;
        $trailBranch = $flightAssignments[$trail] ?? null;

        // Only count intra-branch pairs (both flights in same branch)
        if ($leadBranch && $leadBranch === $trailBranch) {
            if (!isset($branchPairs[$leadBranch])) {
                $branchPairs[$leadBranch] = [];
            }
            $branchPairs[$leadBranch][] = $pair;
        }
    }

    $metrics = [];
    foreach ($branchPairs as $branchId => $pairs) {
        $totalPairs = count($pairs);
        $compliantPairs = 0;
        $spacings = [];
        $violations = [];

        foreach ($pairs as $pair) {
            $compliance = $pair['compliance'] ?? '';
            $spacing = (float)($pair['spacing'] ?? 0);
            $spacings[] = $spacing;

            if (strtoupper($compliance) === 'COMPLIANT') {
                $compliantPairs++;
            } else {
                $violations[] = [
                    'lead' => $pair['prev_callsign'] ?? $pair['lead_callsign'] ?? '',
                    'trail' => $pair['curr_callsign'] ?? $pair['trail_callsign'] ?? '',
                    'spacing' => $spacing,
                    'shortfall_pct' => (float)($pair['shortfall_pct'] ?? 0),
                ];
            }
        }

        $compliancePct = $totalPairs > 0 ? round(($compliantPairs / $totalPairs) * 100, 1) : 100;

        $metrics[$branchId] = [
            'pairs' => $totalPairs,
            'compliant_pairs' => $compliantPairs,
            'compliance_pct' => $compliancePct,
            'violations' => $violations,
            'spacing_stats' => [
                'min' => !empty($spacings) ? round(min($spacings), 1) : 0,
                'avg' => !empty($spacings) ? round(array_sum($spacings) / count($spacings), 1) : 0,
                'max' => !empty($spacings) ? round(max($spacings), 1) : 0,
            ],
        ];
    }

    return $metrics;
}
