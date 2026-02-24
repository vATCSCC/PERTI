<?php
/**
 * Playbook Categories API
 * Returns distinct category values for filter dropdowns.
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

if (session_status() == PHP_SESSION_NONE) {
    session_start();
    ob_start();
}

include("../../../load/config.php");
define('PERTI_MYSQL_ONLY', true);
include("../../../load/connect.php");

$categories = [];
$result = $conn_sqli->query("SELECT DISTINCT category FROM playbook_plays WHERE category IS NOT NULL AND category != '' ORDER BY category");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $categories[] = $row['category'];
    }
}

$sources = [];
$result = $conn_sqli->query("SELECT DISTINCT source FROM playbook_plays ORDER BY source");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $sources[] = $row['source'];
    }
}

$scenario_types = [];
$result = $conn_sqli->query("SELECT DISTINCT scenario_type FROM playbook_plays WHERE scenario_type IS NOT NULL AND scenario_type != '' ORDER BY scenario_type");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $scenario_types[] = $row['scenario_type'];
    }
}

echo json_encode([
    'success' => true,
    'categories' => $categories,
    'sources' => $sources,
    'scenario_types' => $scenario_types
]);
