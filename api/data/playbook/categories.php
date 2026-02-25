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
$category_counts = [];
$result = $conn_sqli->query("SELECT IFNULL(category,'') as category, COUNT(*) as cnt FROM playbook_plays WHERE status != 'archived' GROUP BY category ORDER BY category");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $cat = $row['category'];
        if ($cat !== '') {
            $categories[] = $cat;
            $category_counts[$cat] = (int)$row['cnt'];
        } else {
            $category_counts['_uncategorized'] = (int)$row['cnt'];
        }
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

// Count legacy plays (containing _old_)
$legacy_count = 0;
$result = $conn_sqli->query("SELECT COUNT(*) as cnt FROM playbook_plays WHERE play_name LIKE '%\\_old\\_%' AND status != 'archived'");
if ($result) {
    $legacy_count = (int)$result->fetch_assoc()['cnt'];
}

echo json_encode([
    'success' => true,
    'categories' => $categories,
    'category_counts' => $category_counts,
    'sources' => $sources,
    'scenario_types' => $scenario_types,
    'legacy_count' => $legacy_count
]);
