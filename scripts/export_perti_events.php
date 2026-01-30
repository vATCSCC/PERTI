<?php
/**
 * Export PERTI Events to JSON
 *
 * CLI script to export PERTI events for batch data fetching.
 *
 * Usage:
 *   php scripts/export_perti_events.php [min_level] [start_date]
 *   php scripts/export_perti_events.php 3 2026-01-01
 */

// Change to project root
chdir(__DIR__ . '/..');

include("load/config.php");
include("load/connect.php");

// Get parameters from command line
$min_level = isset($argv[1]) ? intval($argv[1]) : 3;
$start_date = isset($argv[2]) ? $argv[2] : '2026-01-01';

echo "Exporting PERTI events...\n";
echo "  Min OpLevel: $min_level\n";
echo "  Start Date: $start_date\n\n";

$events = [];

// Query events with OpLevel >= min_level
$query = "
    SELECT
        p.id,
        p.event_name,
        p.event_date,
        p.event_start,
        p.event_end_date,
        p.event_end_time,
        p.oplevel,
        p.hotline,
        GROUP_CONCAT(DISTINCT c.airport) as destinations
    FROM p_plans p
    LEFT JOIN p_configs c ON p.id = c.p_id
    WHERE p.oplevel >= ?
      AND p.event_date >= ?
    GROUP BY p.id, p.event_name, p.event_date, p.event_start,
             p.event_end_date, p.event_end_time, p.oplevel, p.hotline
    ORDER BY p.event_date DESC
";

$stmt = $mysqli->prepare($query);
$stmt->bind_param('is', $min_level, $start_date);
$stmt->execute();
$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $events[] = [
        'id' => intval($row['id']),
        'event_name' => $row['event_name'],
        'event_date' => $row['event_date'],
        'event_start' => $row['event_start'],
        'event_end_date' => $row['event_end_date'],
        'event_end_time' => $row['event_end_time'],
        'oplevel' => intval($row['oplevel']),
        'hotline' => $row['hotline'],
        'destinations' => $row['destinations'] ? $row['destinations'] : ''
    ];
}

$stmt->close();

echo "Found " . count($events) . " events:\n\n";

foreach ($events as $event) {
    echo "  #{$event['id']} {$event['event_name']}\n";
    echo "    Date: {$event['event_date']} {$event['event_start']}Z\n";
    echo "    OpLevel: {$event['oplevel']}\n";
    echo "    Destinations: {$event['destinations']}\n\n";
}

// Save to JSON file
$output = [
    'generated_utc' => gmdate('Y-m-d H:i:s'),
    'min_level' => $min_level,
    'start_date' => $start_date,
    'events' => $events
];

$output_path = 'data/tmi_compliance/perti_events.json';

// Ensure directory exists
if (!is_dir('data/tmi_compliance')) {
    mkdir('data/tmi_compliance', 0755, true);
}

file_put_contents($output_path, json_encode($output, JSON_PRETTY_PRINT));
echo "Saved to: $output_path\n";
