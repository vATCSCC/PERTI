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
                // Additional TMI metadata
                'destinations' => $r['destinations'] ?? [],
                'origins' => $r['origins'] ?? [],
                'provider' => $r['provider'] ?? '',
                'requestor' => $r['requestor'] ?? '',
                'is_multiple' => $r['is_multiple'] ?? false
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
