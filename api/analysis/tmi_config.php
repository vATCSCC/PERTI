<?php
/**
 * TMI Configuration API
 *
 * Save and load NTML/TMI configuration for PERTI events
 *
 * Endpoints:
 *   GET  ?p_id={plan_id}  - Get saved TMI config for a plan
 *   POST                  - Save TMI config for a plan
 */

header('Content-Type: application/json');

include("../../load/config.php");

$response = [
    'success' => true,
    'data' => null,
    'message' => ''
];

try {
    $data_path = realpath(__DIR__ . '/../../data/tmi_compliance');
    if (!$data_path) {
        // Create directory if it doesn't exist
        $data_path = __DIR__ . '/../../data/tmi_compliance';
        if (!is_dir($data_path)) {
            mkdir($data_path, 0755, true);
        }
        $data_path = realpath($data_path);
    }

    // Handle GET - Load config
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $plan_id = isset($_GET['p_id']) ? intval($_GET['p_id']) : 0;

        if ($plan_id <= 0) {
            throw new Exception("Invalid or missing plan ID");
        }

        $config_path = $data_path . '/tmi_config_' . $plan_id . '.json';

        if (file_exists($config_path)) {
            $config = json_decode(file_get_contents($config_path), true);
            $response['data'] = $config;
            $response['message'] = 'Configuration loaded';
        } else {
            $response['data'] = null;
            $response['message'] = 'No saved configuration found';
        }
    }

    // Handle POST - Save config
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);

        $plan_id = isset($input['p_id']) ? intval($input['p_id']) : 0;

        if ($plan_id <= 0) {
            throw new Exception("Invalid or missing plan ID");
        }

        $config = [
            'plan_id' => $plan_id,
            'destinations' => $input['destinations'] ?? '',
            'event_start' => $input['event_start'] ?? '',
            'event_end' => $input['event_end'] ?? '',
            'ntml_text' => $input['ntml_text'] ?? '',
            'saved_utc' => gmdate('Y-m-d H:i:s')
        ];

        // Parse NTML entries
        $config['parsed_tmis'] = parse_ntml($config['ntml_text']);

        $config_path = $data_path . '/tmi_config_' . $plan_id . '.json';

        if (file_put_contents($config_path, json_encode($config, JSON_PRETTY_PRINT))) {
            $response['data'] = $config;
            $response['message'] = 'Configuration saved';
        } else {
            throw new Exception("Failed to save configuration");
        }
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
 * Parse NTML text into structured TMI entries
 */
function parse_ntml($ntml_text) {
    $tmis = [];
    $lines = explode("\n", $ntml_text);

    foreach ($lines as $line) {
        $line = trim($line);
        if (empty($line)) continue;

        $tmi = ['raw' => $line, 'type' => null];

        // MIT pattern: "LAS via FLCHR 20MIT ZLA:ZOA 2359Z-0400Z"
        if (preg_match('/(\w+)\s+via\s+(\w+)\s+(\d+)\s*(MIT|MINIT)/i', $line, $matches)) {
            $tmi['type'] = strtoupper($matches[4]);
            $tmi['dest'] = strtoupper($matches[1]);
            $tmi['fix'] = strtoupper($matches[2]);
            $tmi['value'] = intval($matches[3]);

            // Parse provider:requestor
            if (preg_match('/(\w+):(\w+)/', $line, $facMatches)) {
                $tmi['requestor'] = $facMatches[1];
                $tmi['provider'] = $facMatches[2];
            }

            // Parse time window
            if (preg_match('/(\d{4})Z\s*-\s*(\d{4})Z/', $line, $timeMatches)) {
                $tmi['start_time'] = $timeMatches[1];
                $tmi['end_time'] = $timeMatches[2];
            }
        }
        // Ground Stop pattern: "LAS GS (NCT) 0230Z-0315Z issued 0244Z"
        elseif (preg_match('/(\w+)\s+GS\s*\(([^)]+)\)/i', $line, $matches)) {
            $tmi['type'] = 'GS';
            $tmi['dest'] = strtoupper($matches[1]);
            $tmi['scope'] = trim($matches[2]);

            // Parse time window
            if (preg_match('/(\d{4})Z\s*-\s*(\d{4})Z/', $line, $timeMatches)) {
                $tmi['start_time'] = $timeMatches[1];
                $tmi['end_time'] = $timeMatches[2];
            }

            // Parse issued time
            if (preg_match('/issued\s+(\d{4})Z/i', $line, $issuedMatch)) {
                $tmi['issued_time'] = $issuedMatch[1];
            }
        }
        // APREQ/CFR pattern
        elseif (preg_match('/(APREQ|CFR)/i', $line)) {
            $tmi['type'] = 'APREQ';
            // Further parsing can be added
        }
        // Cancelled pattern
        elseif (preg_match('/CXLD?\s+(\d{4})Z/i', $line, $cxlMatch)) {
            $tmi['cancelled'] = true;
            $tmi['cancelled_time'] = $cxlMatch[1];
        }

        if ($tmi['type']) {
            $tmis[] = $tmi;
        }
    }

    return $tmis;
}
