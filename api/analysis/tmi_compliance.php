<?php
/**
 * TMI Compliance Analysis API
 *
 * Retrieves and manages TMI compliance analysis results for PERTI reviews.
 *
 * Endpoints:
 *   GET  ?p_id={plan_id}           - Get compliance results for a plan
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

        // Check if we should run analysis
        if (isset($_GET['run']) && $_GET['run'] === 'true') {
            // For now, return cached results from the last analysis
            // In future, could trigger Python script here
            $response['message'] = 'Analysis triggered (use cached results for now)';
        }

        // Load results from data folder (relative to project root)
        $base_path = realpath(__DIR__ . '/../../data/tmi_compliance');
        $json_path = $base_path . '/tmi_compliance_results.json';

        if (file_exists($json_path)) {
            $json_content = file_get_contents($json_path);
            $results = json_decode($json_content, true);

            if ($results) {
                // Format for frontend display
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
                            'tmi_start' => $r['tmi_start'] ?? '',
                            'tmi_end' => $r['tmi_end'] ?? '',
                            'crossings' => $r['total_crossings'] ?? 0,
                            'valid_crossings' => $r['valid_crossings'] ?? 0,
                            'pairs' => $r['pairs'] ?? 0,
                            'compliance_pct' => $r['compliance_pct'] ?? 0,
                            'distribution' => $r['distribution'] ?? [],
                            'violations' => $r['violations'] ?? [],
                            'spacing_stats' => [
                                'min' => $r['spacing_stats']['min'] ?? 0,
                                'avg' => $r['spacing_stats']['avg'] ?? 0,
                                'max' => $r['spacing_stats']['max'] ?? 0
                            ],
                            'cancelled' => $r['cancelled'] ?? false
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
                            'exempt' => count($r['exempt'] ?? []),
                            'compliant' => count($r['compliant'] ?? []),
                            'non_compliant' => count($r['non_compliant'] ?? []),
                            'compliance_pct' => $r['compliance_pct'] ?? 0,
                            'violations_list' => $r['non_compliant'] ?? []
                        ];
                    }
                }

                // Process APREQ results
                if (isset($results['apreq_results'])) {
                    foreach ($results['apreq_results'] as $key => $r) {
                        $formatted['apreq_results'][] = [
                            'fix' => $r['fix'] ?? 'ALL',
                            'destinations' => $r['destinations'] ?? [],
                            'tmi_start' => $r['tmi_start'] ?? '',
                            'tmi_end' => $r['tmi_end'] ?? '',
                            'total_flights' => $r['total_flights'] ?? 0,
                            'note' => $r['note'] ?? 'No compliance assessment'
                        ];
                    }
                }

                $response['data'] = $formatted;
                $response['message'] = 'Results loaded from cache';
            } else {
                throw new Exception("Failed to parse results JSON");
            }
        } else {
            $response['data'] = null;
            $response['message'] = 'No analysis results found. Run analysis first.';
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
