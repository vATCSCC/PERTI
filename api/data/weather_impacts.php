<?php

// api/data/weather_impacts.php
// Returns list of airports that have weather impact rules defined

if (session_status() == PHP_SESSION_NONE) {
    session_start();
    ob_start();
}

include("../../load/config.php");
include("../../load/connect.php");

header('Content-Type: application/json');

// Check if ADL connection is available
if (!$conn_adl) {
    echo json_encode(['error' => 'ADL database not available', 'airports' => []]);
    exit();
}

// Get airports with weather impact rules
$sql = "
    SELECT DISTINCT
        airport_icao,
        MAX(COALESCE(wind_cat, 0)) as max_wind_cat,
        MAX(COALESCE(cig_cat, 0)) as max_cig_cat,
        MAX(COALESCE(vis_cat, 0)) as max_vis_cat,
        MAX(COALESCE(wx_cat, 0)) as max_wx_cat,
        COUNT(*) as rule_count
    FROM dbo.airport_weather_impact
    WHERE is_active = 1
    GROUP BY airport_icao
    ORDER BY airport_icao
";

$stmt = sqlsrv_query($conn_adl, $sql);

if ($stmt === false) {
    echo json_encode(['error' => adl_sql_error_message(), 'airports' => []]);
    exit();
}

$airports = [];
while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
    // Calculate max impact across all categories
    $maxImpact = max(
        $row['max_wind_cat'] ?? 0,
        $row['max_cig_cat'] ?? 0,
        $row['max_vis_cat'] ?? 0,
        $row['max_wx_cat'] ?? 0
    );

    $airports[$row['airport_icao']] = [
        'icao' => $row['airport_icao'],
        'max_impact' => $maxImpact,
        'rule_count' => $row['rule_count'],
        'wind_impact' => $row['max_wind_cat'] ?? 0,
        'cig_impact' => $row['max_cig_cat'] ?? 0,
        'vis_impact' => $row['max_vis_cat'] ?? 0,
        'wx_impact' => $row['max_wx_cat'] ?? 0
    ];
}

sqlsrv_free_stmt($stmt);

echo json_encode([
    'success' => true,
    'count' => count($airports),
    'airports' => $airports
]);

?>
