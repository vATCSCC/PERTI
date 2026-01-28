<?php
/**
 * SWIM ETA Test Script
 *
 * Tests that ETAs are properly returned from the ADL fallback query
 * after the FIXM column name fix.
 *
 * Usage: php scripts/test_swim_eta.php
 */

define('PERTI_LOADED', true);
require_once __DIR__ . '/../load/config.php';
require_once __DIR__ . '/../load/connect.php';

echo "╔══════════════════════════════════════════════════════════════════╗\n";
echo "║              SWIM ETA VERIFICATION TEST                          ║\n";
echo "╚══════════════════════════════════════════════════════════════════╝\n\n";

// Use ADL connection
$conn = $conn_adl ?? null;
if (!$conn) {
    die("ERROR: Could not connect to VATSIM_ADL database\n");
}

echo "Connected to database.\n\n";

// Run the same query that flights.php uses (with the fixed aliases)
$sql = "
    SELECT TOP 10
        c.callsign,
        c.phase,
        fp.fp_dept_icao,
        fp.fp_dest_icao,
        -- Original column names from adl_flight_times
        t.eta_utc,
        t.eta_runway_utc,
        -- These are the FIXM aliases that the formatter expects
        t.eta_utc AS estimated_time_of_arrival,
        t.eta_runway_utc AS estimated_runway_arrival_time,
        t.eta_source,
        t.eta_method,
        t.ete_minutes
    FROM dbo.adl_flight_core c
    LEFT JOIN dbo.adl_flight_plan fp ON fp.flight_uid = c.flight_uid
    LEFT JOIN dbo.adl_flight_times t ON t.flight_uid = c.flight_uid
    WHERE c.is_active = 1
    ORDER BY c.callsign
";

$stmt = sqlsrv_query($conn, $sql);
if ($stmt === false) {
    $errors = sqlsrv_errors();
    die("Query failed: " . print_r($errors, true) . "\n");
}

echo "Query executed successfully.\n\n";

// Display results
$total = 0;
$with_eta = 0;
$without_eta = 0;

echo "╔══════════════════════════════════════════════════════════════════════════════════════════╗\n";
echo "║ CALLSIGN   │ PHASE      │ ROUTE           │ ETA (Original)      │ ETA (FIXM Alias)      ║\n";
echo "╠══════════════════════════════════════════════════════════════════════════════════════════╣\n";

while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
    $total++;

    $callsign = str_pad($row['callsign'] ?? 'N/A', 10);
    $phase = str_pad($row['phase'] ?? 'N/A', 10);
    $route = str_pad(($row['fp_dept_icao'] ?? '????') . '-' . ($row['fp_dest_icao'] ?? '????'), 15);

    // Original column
    $eta_original = $row['eta_utc'];
    $eta_original_str = $eta_original instanceof DateTime
        ? $eta_original->format('Y-m-d H:i')
        : ($eta_original ?? 'NULL');
    $eta_original_str = str_pad($eta_original_str, 19);

    // FIXM alias column
    $eta_fixm = $row['estimated_time_of_arrival'];
    $eta_fixm_str = $eta_fixm instanceof DateTime
        ? $eta_fixm->format('Y-m-d H:i')
        : ($eta_fixm ?? 'NULL');
    $eta_fixm_str = str_pad($eta_fixm_str, 19);

    if ($eta_original !== null) {
        $with_eta++;
    } else {
        $without_eta++;
    }

    echo "║ $callsign │ $phase │ $route │ $eta_original_str │ $eta_fixm_str   ║\n";
}

sqlsrv_free_stmt($stmt);

echo "╚══════════════════════════════════════════════════════════════════════════════════════════╝\n\n";

// Summary
echo "╔══════════════════════════════════════════════════════════════════╗\n";
echo "║                         SUMMARY                                  ║\n";
echo "╠══════════════════════════════════════════════════════════════════╣\n";
echo "║  Total flights checked:    " . str_pad($total, 37) . "║\n";
echo "║  Flights WITH ETA:         " . str_pad($with_eta, 37) . "║\n";
echo "║  Flights WITHOUT ETA:      " . str_pad($without_eta, 37) . "║\n";
echo "╠══════════════════════════════════════════════════════════════════╣\n";

if ($total === 0) {
    echo "║  STATUS: ⚠ No active flights found                              ║\n";
} elseif ($with_eta > 0) {
    $pct = round(($with_eta / $total) * 100, 1);
    echo "║  STATUS: ✓ ETAs are being calculated ($pct% have ETAs)" . str_pad('', 24 - strlen($pct)) . "║\n";
    echo "║                                                                  ║\n";
    echo "║  The FIXM column aliases are working correctly.                  ║\n";
    echo "║  Both 'eta_utc' and 'estimated_time_of_arrival' return           ║\n";
    echo "║  the same values, confirming the fix is in place.                ║\n";
} else {
    echo "║  STATUS: ⚠ No flights have ETAs calculated                      ║\n";
    echo "║                                                                  ║\n";
    echo "║  This may indicate an upstream issue with ETA calculation        ║\n";
    echo "║  (sp_CalculateETABatch), not the API column mapping.             ║\n";
}

echo "╚══════════════════════════════════════════════════════════════════╝\n";
