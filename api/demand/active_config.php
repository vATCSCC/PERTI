<?php

// api/demand/active_config.php
// Returns the currently active TMI-published CONFIG entry for an airport
// Used by demand page to show active configuration from NTML

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

// Get airport parameter
$airport = isset($_GET['airport']) ? strtoupper(trim($_GET['airport'])) : '';

if (empty($airport)) {
    http_response_code(400);
    echo json_encode(["success" => false, "error" => "Airport parameter is required."]);
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

// Query for active CONFIG entries for this airport
// Match both ICAO (KJFK) and FAA (JFK) codes
$sql = "
    SELECT TOP 1
        entry_id,
        entry_guid,
        ctl_element,
        valid_from,
        valid_until,
        parsed_data,
        raw_input,
        status,
        created_at,
        created_by,
        created_by_name
    FROM dbo.tmi_entries
    WHERE entry_type = 'CONFIG'
      AND status IN ('ACTIVE', 'PUBLISHED')
      AND (UPPER(ctl_element) = :airport1 OR UPPER(ctl_element) = :airport2 OR UPPER(ctl_element) = :airport3)
      AND (valid_until IS NULL OR valid_until > SYSUTCDATETIME())
      AND (valid_from IS NULL OR valid_from <= SYSUTCDATETIME())
    ORDER BY valid_from DESC
";

try {
    $stmt = $tmiConn->prepare($sql);
    $stmt->execute([
        ':airport1' => strtoupper($airport),
        ':airport2' => strtoupper($airportNormalized),
        ':airport3' => strtoupper($airportFaa)
    ]);

    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        // No active CONFIG entry found
        echo json_encode([
            "success" => true,
            "airport_icao" => $airportNormalized,
            "has_active_config" => false,
            "config" => null,
            "message" => "No active TMI CONFIG entry for this airport"
        ]);
        exit;
    }

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
            $validFrom = $row['valid_from']->format('c');
        } else {
            $validFrom = $row['valid_from'];
        }
    }

    if ($row['valid_until']) {
        if ($row['valid_until'] instanceof DateTime) {
            $validUntil = $row['valid_until']->format('c');
        } else {
            $validUntil = $row['valid_until'];
        }
    }

    $createdAt = null;
    if ($row['created_at']) {
        if ($row['created_at'] instanceof DateTime) {
            $createdAt = $row['created_at']->format('c');
        } else {
            $createdAt = $row['created_at'];
        }
    }

    // Build response
    $config = [
        "entry_id" => (int)$row['entry_id'],
        "entry_guid" => $row['entry_guid'],
        "airport" => strtoupper($row['ctl_element']),

        // Configuration details from parsed_data
        "weather_category" => strtoupper($parsedData['weather'] ?? 'VMC'),
        "config_name" => $parsedData['config_name'] ?? null,
        "arr_runways" => strtoupper($parsedData['arr_runways'] ?? ''),
        "dep_runways" => strtoupper($parsedData['dep_runways'] ?? ''),

        // Rates
        "aar" => isset($parsedData['aar']) ? (int)$parsedData['aar'] : null,
        "aar_type" => $parsedData['aar_type'] ?? 'Strat',
        "adr" => isset($parsedData['adr']) ? (int)$parsedData['adr'] : null,

        // Validity
        "valid_from" => $validFrom,
        "valid_until" => $validUntil,

        // Metadata
        "status" => $row['status'],
        "raw_input" => $row['raw_input'],
        "created_at" => $createdAt,
        "created_by" => $row['created_by'],
        "created_by_name" => $row['created_by_name'],

        // Source indicator
        "source" => "TMI"
    ];

    echo json_encode([
        "success" => true,
        "airport_icao" => $airportNormalized,
        "has_active_config" => true,
        "config" => $config,
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
