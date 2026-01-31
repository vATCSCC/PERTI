<?php
/**
 * api/demand/tmi_programs.php
 * Returns GS (Ground Stop) and GDP programs overlapping a time range for an airport
 * Used by demand page to render vertical markers on the chart
 */

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

// Query for GS and GDP programs overlapping the time range
// Use cumulative_start/cumulative_end if available (for tracking full program history)
// Fall back to start_utc/end_utc
$sql = "
    SELECT
        program_id,
        program_guid,
        ctl_element,
        program_type,
        program_name,
        adv_number,
        start_utc,
        end_utc,
        cumulative_start,
        cumulative_end,
        status,
        is_active,
        created_at,
        updated_at,
        activated_at,
        purged_at,
        comments
    FROM dbo.tmi_programs
    WHERE (program_type = 'GS' OR program_type LIKE 'GDP%')
      AND (UPPER(ctl_element) = :airport1 OR UPPER(ctl_element) = :airport2 OR UPPER(ctl_element) = :airport3)
      AND (
          -- Use cumulative times if available, otherwise use regular start/end
          (cumulative_start IS NOT NULL AND cumulative_start < :range_end AND (cumulative_end IS NULL OR cumulative_end > :range_start))
          OR (cumulative_start IS NULL AND start_utc < :range_end2 AND (end_utc IS NULL OR end_utc > :range_start2))
      )
    ORDER BY COALESCE(cumulative_start, start_utc) ASC
";

try {
    $stmt = $tmiConn->prepare($sql);
    $stmt->execute([
        ':airport1' => strtoupper($airport),
        ':airport2' => strtoupper($airportNormalized),
        ':airport3' => strtoupper($airportFaa),
        ':range_start' => $startDt->format('Y-m-d H:i:s'),
        ':range_end' => $endDt->format('Y-m-d H:i:s'),
        ':range_start2' => $startDt->format('Y-m-d H:i:s'),
        ':range_end2' => $endDt->format('Y-m-d H:i:s')
    ]);

    $programs = [];

    // Helper function to format datetime as ISO8601
    $formatDt = function($value) {
        if (!$value) return null;
        if ($value instanceof DateTime) {
            return $value->format('Y-m-d\TH:i:s\Z');
        }
        try {
            $dt = new DateTime($value, new DateTimeZone('UTC'));
            return $dt->format('Y-m-d\TH:i:s\Z');
        } catch (Exception $e) {
            return null;
        }
    };

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        // Use cumulative times for display (these track extensions/updates)
        $startTime = $row['cumulative_start'] ?? $row['start_utc'];
        $endTime = $row['cumulative_end'] ?? $row['end_utc'];

        $programs[] = [
            "program_id" => (int)$row['program_id'],
            "program_guid" => $row['program_guid'],
            "airport" => strtoupper($row['ctl_element']),
            "program_type" => $row['program_type'],  // 'GS' or 'GDP-DAS', 'GDP-GAAP', etc.
            "program_name" => $row['program_name'],
            "adv_number" => $row['adv_number'],

            // Start/End times (use cumulative for full history)
            "start_utc" => $formatDt($startTime),
            "end_utc" => $formatDt($endTime),

            // Original start/end (before any extensions)
            "original_start" => $formatDt($row['start_utc']),
            "original_end" => $formatDt($row['end_utc']),

            // Status
            "status" => $row['status'],  // PROPOSED, ACTIVE, COMPLETED, PURGED
            "is_active" => (bool)$row['is_active'],

            // Timestamps for markers
            "created_at" => $formatDt($row['created_at']),
            "updated_at" => $formatDt($row['updated_at']),
            "activated_at" => $formatDt($row['activated_at']),
            "purged_at" => $formatDt($row['purged_at']),  // Cancellation time

            // Whether this was updated (extended/modified)
            "was_updated" => $row['updated_at'] !== null && $row['updated_at'] !== $row['created_at'],

            "comments" => $row['comments']
        ];
    }

    echo json_encode([
        "success" => true,
        "airport_icao" => $airportNormalized,
        "programs" => $programs,
        "count" => count($programs),
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
