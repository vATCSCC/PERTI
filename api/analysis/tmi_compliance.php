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

// Results are ~5MB (trajectories split to separate file); 256M is generous headroom
ini_set('memory_limit', '256M');
// Status checks are fast; only legacy sync path needs long timeout
set_time_limit(60);

header('Content-Type: application/json');

include("../../load/config.php");

// Base path for analysis data files
$analysis_base_path = realpath(__DIR__ . '/../../data/tmi_compliance') ?: __DIR__ . '/../../data/tmi_compliance';
if (!is_dir($analysis_base_path)) {
    mkdir($analysis_base_path, 0755, true);
}

// Trajectory-only endpoint: streams pre-built trajectory JSON with zero json_decode
// This handles the bulk data (~22MB) without PHP memory overhead
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['trajectories']) && $_GET['trajectories'] === 'true') {
    $plan_id = isset($_GET['p_id']) ? intval($_GET['p_id']) : 0;
    if ($plan_id <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid plan ID']);
        exit;
    }

    $base_path = realpath(__DIR__ . '/../../data/tmi_compliance') ?: __DIR__ . '/../../data/tmi_compliance';

    // Try split trajectory file first (new format)
    $traj_path = $base_path . '/tmi_compliance_trajectories_' . $plan_id . '.json';
    if (file_exists($traj_path)) {
        header('Content-Length: ' . filesize($traj_path));
        header('Cache-Control: private, max-age=300');
        readfile($traj_path);
        exit;
    }

    // Fallback: extract from old combined results file (backwards compat)
    $json_path = $base_path . '/tmi_compliance_results_' . $plan_id . '.json';
    if (file_exists($json_path)) {
        ini_set('memory_limit', '512M'); // Old format needs full decode
        $results = json_decode(file_get_contents($json_path), true);
        $trajectories = [];
        if (isset($results['mit_results'])) {
            foreach ($results['mit_results'] as $key => $r) {
                if (!empty($r['trajectories'])) {
                    $trajectories[$key] = $r['trajectories'];
                }
            }
        }
        echo json_encode($trajectories);
        exit;
    }

    http_response_code(404);
    echo json_encode(['error' => 'No trajectory data found']);
    exit;
}

try {

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

        // Status check endpoint: poll for async analysis progress
        if (isset($_GET['status']) && $_GET['status'] === 'true') {
            $status = check_analysis_status($plan_id, $analysis_base_path);
            $response['status'] = $status['status'];
            $response['message'] = $status['message'] ?? '';
            if (isset($status['elapsed_seconds'])) {
                $response['elapsed_seconds'] = $status['elapsed_seconds'];
            }
            if (isset($status['error_log'])) {
                $response['error_log'] = $status['error_log'];
            }
            // If complete, include formatted results
            if ($status['status'] === 'complete') {
                $output_file = $analysis_base_path . '/tmi_compliance_results_' . $plan_id . '.json';
                $json_content = file_get_contents($output_file);
                $results = json_decode($json_content, true);
                if ($results && !isset($results['error'])) {
                    $response['data'] = format_results($results);
                    $response['data']['plan_specific'] = true;
                    $response['data']['trajectories_url'] = "api/analysis/tmi_compliance.php?p_id={$plan_id}&trajectories=true";
                } else {
                    $response['success'] = false;
                    $response['status'] = 'error';
                    $response['message'] = $results['error'] ?? 'Analysis produced invalid results';
                }
            }
            echo json_encode($response);
            exit;
        }

        // Launch async analysis
        if (isset($_GET['run']) && $_GET['run'] === 'true') {
            $result = launch_analysis_async($plan_id, $analysis_base_path);
            $response['status'] = $result['status'];
            $response['message'] = $result['message'];
            if ($result['status'] === 'error') {
                $response['success'] = false;
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
                    $response['data']['plan_specific'] = $using_plan_specific;
                    $response['data']['trajectories_url'] = "api/analysis/tmi_compliance.php?p_id={$plan_id}&trajectories=true";
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

    echo json_encode($response, JSON_PRETTY_PRINT);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}

/**
 * Launch TMI compliance analysis as a background process.
 *
 * Returns immediately so the HTTP request completes within Azure's 230s
 * load balancer timeout. The client polls ?status=true for progress.
 *
 * @param int $plan_id Plan ID
 * @param string $base_path Data directory for output/status files
 * @return array Status response
 */
function launch_analysis_async($plan_id, $base_path) {
    $status_file = $base_path . '/tmi_compliance_status_' . $plan_id . '.json';
    $output_file = $base_path . '/tmi_compliance_results_' . $plan_id . '.json';
    $log_file = $base_path . '/tmi_compliance_log_' . $plan_id . '.log';

    // Check if analysis is already running
    if (file_exists($status_file)) {
        $status = json_decode(file_get_contents($status_file), true);
        if ($status && ($status['status'] ?? '') === 'running') {
            $pid = $status['pid'] ?? 0;
            // Check if process is still alive (Linux: /proc/PID, Windows: tasklist)
            if ($pid && is_process_running($pid)) {
                $elapsed = time() - ($status['started_ts'] ?? time());
                return [
                    'status' => 'running',
                    'message' => "Analysis already in progress ({$elapsed}s elapsed)"
                ];
            }
            // Process died - check if it wrote results before dying
            if (file_exists($output_file) && filemtime($output_file) > ($status['started_ts'] ?? 0)) {
                @unlink($status_file);
                return [
                    'status' => 'complete',
                    'message' => 'Analysis completed'
                ];
            }
            // Dead process, no results - clean up stale status
            @unlink($status_file);
        }
    }

    // Path to Python script
    $script_dir = realpath(__DIR__ . '/../../scripts/tmi_compliance');
    $script_path = $script_dir . '/run.py';

    if (!file_exists($script_path)) {
        return [
            'status' => 'error',
            'message' => "TMI Analysis script not found at: $script_path"
        ];
    }

    // Environment variables for database connections
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
        'PYTHONUSERBASE' => '/home/.local',
        'PYTHONPATH' => '/home/.local/lib/python3.9/site-packages',
        'PATH' => '/home/.local/bin:/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin'
    ];

    $python = PHP_OS_FAMILY === 'Windows' ? 'python' : 'python3';

    // Build command with inline env vars (Linux)
    $env_prefix = '';
    if (PHP_OS_FAMILY !== 'Windows') {
        $env_parts = [
            'PYTHONUNBUFFERED=1',
            'PYTHONPATH=/home/.local/lib/python3.9/site-packages'
        ];
        foreach ($env_vars as $key => $value) {
            if (!in_array($key, ['PYTHONUSERBASE', 'PYTHONPATH', 'PATH'])) {
                $env_parts[] = sprintf('%s=%s', $key, escapeshellarg($value));
            }
        }
        $env_prefix = implode(' ', $env_parts) . ' ';
    } else {
        foreach ($env_vars as $key => $value) {
            putenv("$key=$value");
        }
    }

    // Build the command - redirect output to log file, run in background
    if (PHP_OS_FAMILY === 'Windows') {
        // Windows: use start /B for background
        $cmd = sprintf(
            'start /B %s %s --plan_id %d --output %s > %s 2>&1',
            escapeshellcmd($python),
            escapeshellarg($script_path),
            intval($plan_id),
            escapeshellarg($output_file),
            escapeshellarg($log_file)
        );
        $pid_cmd = $cmd;
    } else {
        // Linux: env vars BEFORE nohup (nohup treats first arg as the command)
        $cmd = sprintf(
            '%snohup %s %s --plan_id %d --output %s > %s 2>&1 & echo $!',
            $env_prefix,
            escapeshellcmd($python),
            escapeshellarg($script_path),
            intval($plan_id),
            escapeshellarg($output_file),
            escapeshellarg($log_file)
        );
    }

    // Launch background process
    $pid = 0;
    if (PHP_OS_FAMILY === 'Windows') {
        pclose(popen($cmd, 'r'));
    } else {
        $pid = intval(trim(shell_exec($cmd)));
    }

    // Write status file
    $now = time();
    file_put_contents($status_file, json_encode([
        'status' => 'running',
        'started_utc' => gmdate('Y-m-d\TH:i:s\Z', $now),
        'started_ts' => $now,
        'pid' => $pid,
        'plan_id' => $plan_id,
    ]));

    error_log("TMI analysis launched for plan $plan_id, PID=$pid");

    return [
        'status' => 'running',
        'message' => 'Analysis started. Polling for results...'
    ];
}

/**
 * Check the status of a running analysis.
 *
 * @param int $plan_id Plan ID
 * @param string $base_path Data directory
 * @return array Status info
 */
function check_analysis_status($plan_id, $base_path) {
    $status_file = $base_path . '/tmi_compliance_status_' . $plan_id . '.json';
    $output_file = $base_path . '/tmi_compliance_results_' . $plan_id . '.json';
    $log_file = $base_path . '/tmi_compliance_log_' . $plan_id . '.log';

    // No status file means no analysis running
    if (!file_exists($status_file)) {
        // Check if results exist from a previous run
        if (file_exists($output_file)) {
            return ['status' => 'complete', 'message' => 'Results available'];
        }
        return ['status' => 'idle', 'message' => 'No analysis running'];
    }

    $status = json_decode(file_get_contents($status_file), true);
    if (!$status || ($status['status'] ?? '') !== 'running') {
        @unlink($status_file);
        return ['status' => 'idle', 'message' => 'No analysis running'];
    }

    $started_ts = $status['started_ts'] ?? time();
    $pid = $status['pid'] ?? 0;
    $elapsed = time() - $started_ts;

    // Check if results file was written after the analysis started
    if (file_exists($output_file) && filemtime($output_file) > $started_ts) {
        @unlink($status_file);
        return ['status' => 'complete', 'message' => "Analysis completed in {$elapsed}s"];
    }

    // Check if process is still alive
    if ($pid && !is_process_running($pid)) {
        @unlink($status_file);
        // Read last lines of log for error info
        $error_log = '';
        if (file_exists($log_file)) {
            $log_content = file_get_contents($log_file);
            $error_log = substr($log_content, -2000);
        }
        return [
            'status' => 'error',
            'message' => "Analysis process exited without producing results after {$elapsed}s",
            'error_log' => $error_log
        ];
    }

    // Hard timeout: large events (100+ TMIs, 3+ airports) can take 20+ min
    if ($elapsed > 1800) {
        // Kill the stuck process
        if ($pid && PHP_OS_FAMILY !== 'Windows') {
            exec("kill $pid 2>/dev/null");
        }
        @unlink($status_file);
        return [
            'status' => 'error',
            'message' => "Analysis timed out after {$elapsed}s"
        ];
    }

    return [
        'status' => 'running',
        'message' => "Analysis in progress ({$elapsed}s elapsed)",
        'elapsed_seconds' => $elapsed
    ];
}

/**
 * Check if a process with the given PID is still running.
 */
function is_process_running($pid) {
    if (!$pid) return false;

    if (PHP_OS_FAMILY === 'Windows') {
        exec("tasklist /FI \"PID eq $pid\" 2>NUL", $output);
        foreach ($output as $line) {
            if (strpos($line, (string)$pid) !== false) return true;
        }
        return false;
    }

    // Linux: check /proc filesystem
    return file_exists("/proc/$pid");
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
                // Trajectory metadata (actual data served via separate endpoint for memory efficiency)
                'has_trajectories' => !empty($r['trajectories']) || ($r['has_trajectories'] ?? false),
                'trajectory_count' => !empty($r['trajectories']) ? count($r['trajectories']) : ($r['trajectory_count'] ?? 0),
                'mit_key' => $key,
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
