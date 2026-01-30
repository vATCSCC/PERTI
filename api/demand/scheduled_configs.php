<?php

// api/demand/scheduled_configs.php
// Returns all TMI CONFIG entries overlapping a given time range for an airport
// Used by demand page to show time-bounded rate lines on the chart

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle CORS preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once(__DIR__ . "/../../load/config.php");

// Check TMI database configuration
if (!defined("TMI_SQL_HOST") || !defined("TMI_SQL_DATABASE") ||
    !defined("TMI_SQL_USERNAME") || !defined("TMI_SQL_PASSWORD")) {
    http_response_code(500);
    echo json_encode(["success" => false, "error" => "TMI_SQL_* constants are not defined."]);
    exit;
}

// Get parameters
$airport = isset($_GET['airport']) ? strtoupper(trim($_GET['airport'])) : '';
$start = isset($_GET['start']) ? trim($_GET['start']) : '';
$end = isset($_GET['end']) ? trim($_GET['end']) : '';

// Validate airport
if (empty($airport)) {
    http_response_code(400);
    echo json_encode(["success" => false, "error" => "Airport parameter is required."]);
    exit;
}

// Validate start/end times
if (empty($start) || empty($end)) {
    http_response_code(400);
    echo json_encode(["success" => false, "error" => "Start and end parameters are required (ISO8601 format)."]);
    exit;
}

// Parse and validate timestamps
try {
    $startDt = new DateTime($start, new DateTimeZone('UTC'));
    $endDt = new DateTime($end, new DateTimeZone('UTC'));
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(["success" => false, "error" => "Invalid date format. Use ISO8601 (e.g., 2026-01-31T14:00:00Z)."]);
    exit;
}

// Normalize airport code (add K prefix for US 3-letter codes)
$airportNormalized = $airport;
if (strlen($airport) === 3 && !preg_match('/^[PK]/', $airport)) {
    $airportNormalized = 'K' . $airport;
}

// Also check FAA code without prefix (JFK, LAX, etc.)
$airportFaa = strlen($airport) === 4 && preg_match('/^K[A-Z]{3}$/', $airport)
    ? substr($airport, 1)
    : $airport;

// Connect to TMI database
try {
    $connStr = "sqlsrv:Server=" . TMI_SQL_HOST . ";Database=" . TMI_SQL_DATABASE;
    $tmiConn = new PDO($connStr, TMI_SQL_USERNAME, TMI_SQL_PASSWORD);
    $tmiConn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "error" => "Unable to connect to TMI database.",
        "detail" => $e->getMessage()
    ]);
    exit;
}

// Query for CONFIG entries overlapping the time range
// Overlap condition: valid_from < end AND (valid_until IS NULL OR valid_until > start)
$sql = "
    SELECT
        entry_id,
        entry_guid,
        ctl_element,
        valid_from,
        valid_until,
        parsed_data,
        status,
        created_at,
        created_by_name
    FROM dbo.tmi_entries
    WHERE entry_type = 'CONFIG'
      AND status IN ('ACTIVE', 'PUBLISHED')
      AND (UPPER(ctl_element) = :airport1 OR UPPER(ctl_element) = :airport2 OR UPPER(ctl_element) = :airport3)
      AND (valid_from IS NULL OR valid_from < :range_end)
      AND (valid_until IS NULL OR valid_until > :range_start)
    ORDER BY valid_from ASC
";

try {
    $stmt = $tmiConn->prepare($sql);
    $stmt->execute([
        ':airport1' => strtoupper($airport),
        ':airport2' => strtoupper($airportNormalized),
        ':airport3' => strtoupper($airportFaa),
        ':range_start' => $startDt->format('Y-m-d H:i:s'),
        ':range_end' => $endDt->format('Y-m-d H:i:s')
    ]);

    $configs = [];

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        // Parse the stored JSON data
        $parsedData = [];
        if (!empty($row['parsed_data'])) {
            $parsedData = json_decode($row['parsed_data'], true) ?: [];
        }

        // Format valid times
        $validFrom = null;
        $validUntil = null;

        if ($row['valid_from']) {
            if ($row['valid_from'] instanceof DateTime) {
                $validFrom = $row['valid_from']->format('Y-m-d\TH:i:s\Z');
            } else {
                // Convert string to proper ISO format
                $dt = new DateTime($row['valid_from'], new DateTimeZone('UTC'));
                $validFrom = $dt->format('Y-m-d\TH:i:s\Z');
            }
        }

        if ($row['valid_until']) {
            if ($row['valid_until'] instanceof DateTime) {
                $validUntil = $row['valid_until']->format('Y-m-d\TH:i:s\Z');
            } else {
                $dt = new DateTime($row['valid_until'], new DateTimeZone('UTC'));
                $validUntil = $dt->format('Y-m-d\TH:i:s\Z');
            }
        }

        $createdAt = null;
        if ($row['created_at']) {
            if ($row['created_at'] instanceof DateTime) {
                $createdAt = $row['created_at']->format('Y-m-d\TH:i:s\Z');
            } else {
                $dt = new DateTime($row['created_at'], new DateTimeZone('UTC'));
                $createdAt = $dt->format('Y-m-d\TH:i:s\Z');
            }
        }

        $configs[] = [
            "entry_id" => (int)$row['entry_id'],
            "entry_guid" => $row['entry_guid'],
            "airport" => strtoupper($row['ctl_element']),
            "valid_from" => $validFrom,
            "valid_until" => $validUntil,

            // Rates from parsed_data
            "aar" => isset($parsedData['aar']) ? (int)$parsedData['aar'] : null,
            "adr" => isset($parsedData['adr']) ? (int)$parsedData['adr'] : null,
            "aar_type" => $parsedData['aar_type'] ?? 'Strat',

            // Config details
            "weather" => strtoupper($parsedData['weather'] ?? 'VMC'),
            "config_name" => $parsedData['config_name'] ?? null,
            "arr_runways" => strtoupper($parsedData['arr_runways'] ?? ''),
            "dep_runways" => strtoupper($parsedData['dep_runways'] ?? ''),

            // Metadata
            "status" => $row['status'],
            "created_at" => $createdAt,
            "created_by_name" => $row['created_by_name']
        ];
    }

    echo json_encode([
        "success" => true,
        "airport_icao" => $airportNormalized,
        "configs" => $configs,
        "count" => count($configs),
        "query_range" => [
            "start" => $startDt->format('Y-m-d\TH:i:s\Z'),
            "end" => $endDt->format('Y-m-d\TH:i:s\Z')
        ],
        "server_utc" => gmdate('Y-m-d\TH:i:s\Z')
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "error" => "Query failed.",
        "detail" => $e->getMessage()
    ]);
}
