<?php
/**
 * ETA Wind Accuracy Analysis Report
 *
 * Analyzes ETA calculation accuracy with and without wind adjustments.
 * Run via CLI: php eta_wind_accuracy_report.php
 * Or via web: https://perti.vatcscc.org/adl/analysis/eta_wind_accuracy_report.php
 */

// Load config
require_once __DIR__ . '/../../load/config.php';

// Output format
$isWeb = php_sapi_name() !== 'cli';
$nl = $isWeb ? "<br>\n" : "\n";
$hr = $isWeb ? "<hr>\n" : str_repeat('=', 80) . "\n";

if ($isWeb) {
    header('Content-Type: text/html; charset=utf-8');
    echo "<!DOCTYPE html><html><head><title>ETA Wind Accuracy Analysis</title>";
    echo "<style>
        body { font-family: 'Consolas', 'Monaco', monospace; background: #1a1a2e; color: #eee; padding: 20px; }
        h1, h2, h3 { color: #00d9ff; }
        table { border-collapse: collapse; margin: 10px 0; }
        th, td { border: 1px solid #444; padding: 8px 12px; text-align: right; }
        th { background: #2d2d44; }
        tr:nth-child(even) { background: #252538; }
        .good { color: #4caf50; }
        .warn { color: #ff9800; }
        .bad { color: #f44336; }
        .header { color: #00d9ff; font-weight: bold; }
        pre { background: #252538; padding: 15px; border-radius: 5px; overflow-x: auto; }
    </style></head><body>";
}

// Connect to database
$connectionOptions = [
    "Database" => ADL_SQL_DATABASE,
    "Uid" => ADL_SQL_USERNAME,
    "PWD" => ADL_SQL_PASSWORD,
    "Encrypt" => true,
    "TrustServerCertificate" => false,
    "LoginTimeout" => 30,
];

$conn = sqlsrv_connect(ADL_SQL_HOST, $connectionOptions);
if ($conn === false) {
    die("Database connection failed: " . print_r(sqlsrv_errors(), true));
}

// Helper functions
function query($conn, $sql) {
    $stmt = sqlsrv_query($conn, $sql);
    if ($stmt === false) {
        return ['error' => sqlsrv_errors()];
    }
    $rows = [];
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $rows[] = $row;
    }
    sqlsrv_free_stmt($stmt);
    return $rows;
}

function formatTable($rows, $title = '') {
    global $isWeb, $nl;
    if (empty($rows)) return "No data{$nl}";

    $output = '';
    if ($isWeb) {
        if ($title) $output .= "<h3>{$title}</h3>";
        $output .= "<table><tr>";
        foreach (array_keys($rows[0]) as $col) {
            $output .= "<th>{$col}</th>";
        }
        $output .= "</tr>";
        foreach ($rows as $row) {
            $output .= "<tr>";
            foreach ($row as $val) {
                $output .= "<td>" . htmlspecialchars($val ?? 'NULL') . "</td>";
            }
            $output .= "</tr>";
        }
        $output .= "</table>";
    } else {
        if ($title) $output .= "\n{$title}\n" . str_repeat('-', strlen($title)) . "\n";
        // Simple text table
        $cols = array_keys($rows[0]);
        $widths = [];
        foreach ($cols as $col) {
            $widths[$col] = strlen($col);
            foreach ($rows as $row) {
                $widths[$col] = max($widths[$col], strlen($row[$col] ?? 'NULL'));
            }
        }
        // Header
        foreach ($cols as $col) {
            $output .= str_pad($col, $widths[$col] + 2);
        }
        $output .= "\n";
        // Data
        foreach ($rows as $row) {
            foreach ($cols as $col) {
                $output .= str_pad($row[$col] ?? 'NULL', $widths[$col] + 2);
            }
            $output .= "\n";
        }
    }
    return $output;
}

// ============================================================================
// REPORT
// ============================================================================

echo $isWeb ? "<h1>ETA Wind Accuracy Analysis</h1>" : "ETA WIND ACCURACY ANALYSIS\n";
echo "Generated: " . gmdate('Y-m-d H:i:s') . " UTC{$nl}";
echo $hr;

// 1. DATA AVAILABILITY
echo $isWeb ? "<h2>1. Data Availability</h2>" : "\n1. DATA AVAILABILITY\n";

$dataAvail = query($conn, "
    SELECT
        (SELECT COUNT(*) FROM adl_flight_times) AS total_flights,
        (SELECT COUNT(*) FROM adl_flight_times WHERE eta_wind_adj_kts IS NOT NULL) AS with_wind_calc,
        (SELECT COUNT(*) FROM adl_flight_times WHERE ABS(ISNULL(eta_wind_adj_kts, 0)) > 5) AS with_significant_wind,
        (SELECT COUNT(*) FROM adl_flight_times WHERE ata_utc IS NOT NULL OR ata_runway_utc IS NOT NULL) AS with_actual_arrival,
        (SELECT COUNT(*) FROM adl_flight_core WHERE is_active = 1) AS active_flights
");
echo formatTable($dataAvail, 'Flight Data Status');

// 2. WIND ADJUSTMENT DISTRIBUTION
echo $isWeb ? "<h2>2. Wind Adjustment Distribution (Active Flights)</h2>" : "\n2. WIND ADJUSTMENT DISTRIBUTION\n";

$windDist = query($conn, "
    SELECT
        CASE
            WHEN ft.eta_wind_confidence >= 0.90 THEN '1-High (0.90+) Grid'
            WHEN ft.eta_wind_confidence >= 0.60 THEN '2-Medium (0.60-0.89) GS-Cruise'
            WHEN ft.eta_wind_confidence >= 0.40 THEN '3-Low (0.40-0.59) GS-Other'
            WHEN ft.eta_wind_confidence IS NOT NULL THEN '4-Very Low (<0.40)'
            ELSE '5-No Wind Calc'
        END AS confidence_tier,
        COUNT(*) AS flights,
        CAST(ROUND(AVG(ft.eta_wind_adj_kts), 1) AS DECIMAL(6,1)) AS avg_wind_adj,
        CAST(ROUND(AVG(ABS(ft.eta_wind_adj_kts)), 1) AS DECIMAL(6,1)) AS avg_abs_wind,
        CAST(MIN(ft.eta_wind_adj_kts) AS INT) AS min_wind,
        CAST(MAX(ft.eta_wind_adj_kts) AS INT) AS max_wind,
        CAST(ROUND(STDEV(ft.eta_wind_adj_kts), 1) AS DECIMAL(6,1)) AS stdev
    FROM adl_flight_times ft
    JOIN adl_flight_core c ON c.flight_uid = ft.flight_uid
    WHERE c.is_active = 1
    GROUP BY
        CASE
            WHEN ft.eta_wind_confidence >= 0.90 THEN '1-High (0.90+) Grid'
            WHEN ft.eta_wind_confidence >= 0.60 THEN '2-Medium (0.60-0.89) GS-Cruise'
            WHEN ft.eta_wind_confidence >= 0.40 THEN '3-Low (0.40-0.59) GS-Other'
            WHEN ft.eta_wind_confidence IS NOT NULL THEN '4-Very Low (<0.40)'
            ELSE '5-No Wind Calc'
        END
    ORDER BY 1
");
echo formatTable($windDist, 'Wind Confidence Distribution');

// 3. WIND IMPACT BY FLIGHT PHASE
echo $isWeb ? "<h2>3. Wind Adjustment by Flight Phase</h2>" : "\n3. WIND BY FLIGHT PHASE\n";

$windPhase = query($conn, "
    SELECT
        c.phase,
        COUNT(*) AS flights,
        SUM(CASE WHEN ft.eta_wind_adj_kts IS NOT NULL THEN 1 ELSE 0 END) AS with_wind,
        CAST(ROUND(AVG(ft.eta_wind_adj_kts), 1) AS DECIMAL(6,1)) AS avg_wind_adj,
        CAST(ROUND(AVG(ABS(ft.eta_wind_adj_kts)), 1) AS DECIMAL(6,1)) AS avg_abs_wind,
        CAST(ROUND(AVG(ft.eta_wind_confidence), 2) AS DECIMAL(4,2)) AS avg_confidence
    FROM adl_flight_core c
    LEFT JOIN adl_flight_times ft ON ft.flight_uid = c.flight_uid
    WHERE c.is_active = 1
    GROUP BY c.phase
    ORDER BY COUNT(*) DESC
");
echo formatTable($windPhase, 'Wind by Phase');

// 4. THEORETICAL TIME IMPACT
echo $isWeb ? "<h2>4. Theoretical ETA Impact from Wind</h2>" : "\n4. THEORETICAL ETA IMPACT\n";

$timeImpact = query($conn, "
    SELECT
        CASE
            WHEN time_impact_min IS NULL THEN '0-No calculation possible'
            WHEN time_impact_min < -10 THEN '1-Saves >10 min (strong tailwind)'
            WHEN time_impact_min < -5 THEN '2-Saves 5-10 min'
            WHEN time_impact_min < -2 THEN '3-Saves 2-5 min'
            WHEN time_impact_min <= 2 THEN '4-Minimal (±2 min)'
            WHEN time_impact_min <= 5 THEN '5-Adds 2-5 min'
            WHEN time_impact_min <= 10 THEN '6-Adds 5-10 min'
            ELSE '7-Adds >10 min (strong headwind)'
        END AS impact_category,
        COUNT(*) AS flights,
        CAST(ROUND(AVG(time_impact_min), 1) AS DECIMAL(6,1)) AS avg_impact_min,
        CAST(ROUND(AVG(wind_adj), 1) AS DECIMAL(6,1)) AS avg_wind_kts,
        CAST(ROUND(AVG(dist_nm), 0) AS INT) AS avg_dist_nm
    FROM (
        SELECT
            ft.eta_wind_adj_kts AS wind_adj,
            p.dist_to_dest_nm AS dist_nm,
            CASE
                WHEN p.dist_to_dest_nm > 50 AND ABS(ft.eta_wind_adj_kts) > 5
                     AND COALESCE(perf.cruise_speed, 450) > 0
                THEN (p.dist_to_dest_nm / NULLIF(COALESCE(perf.cruise_speed, 450), 0) -
                      p.dist_to_dest_nm / NULLIF(COALESCE(perf.cruise_speed, 450) + ft.eta_wind_adj_kts, 0)) * 60
                ELSE NULL
            END AS time_impact_min
        FROM adl_flight_times ft
        JOIN adl_flight_core c ON c.flight_uid = ft.flight_uid
        JOIN adl_flight_position p ON p.flight_uid = ft.flight_uid
        JOIN adl_flight_plan fp ON fp.flight_uid = ft.flight_uid
        LEFT JOIN aircraft_performance_profiles perf ON perf.icao_type = fp.fp_aircraft_type
        WHERE c.is_active = 1
          AND c.phase IN ('enroute', 'cruise', 'climbing')
    ) analysis
    GROUP BY
        CASE
            WHEN time_impact_min IS NULL THEN '0-No calculation possible'
            WHEN time_impact_min < -10 THEN '1-Saves >10 min (strong tailwind)'
            WHEN time_impact_min < -5 THEN '2-Saves 5-10 min'
            WHEN time_impact_min < -2 THEN '3-Saves 2-5 min'
            WHEN time_impact_min <= 2 THEN '4-Minimal (±2 min)'
            WHEN time_impact_min <= 5 THEN '5-Adds 2-5 min'
            WHEN time_impact_min <= 10 THEN '6-Adds 5-10 min'
            ELSE '7-Adds >10 min (strong headwind)'
        END
    ORDER BY 1
");
echo formatTable($timeImpact, 'Expected ETA Impact Distribution');

// 5. WIND GRID STATUS
echo $isWeb ? "<h2>5. Wind Grid Data Status</h2>" : "\n5. WIND GRID STATUS\n";

$gridStatus = query($conn, "
    IF OBJECT_ID('dbo.wind_grid', 'U') IS NOT NULL
        SELECT
            COUNT(*) AS total_records,
            COUNT(DISTINCT CONCAT(lat, ',', lon)) AS grid_points,
            COUNT(DISTINCT pressure_hpa) AS pressure_levels,
            FORMAT(MIN(valid_time_utc), 'yyyy-MM-dd HH:mm') AS earliest_forecast,
            FORMAT(MAX(valid_time_utc), 'yyyy-MM-dd HH:mm') AS latest_forecast,
            FORMAT(MAX(fetched_utc), 'yyyy-MM-dd HH:mm') AS last_fetched,
            DATEDIFF(HOUR, MAX(fetched_utc), GETUTCDATE()) AS hours_since_fetch
        FROM dbo.wind_grid
    ELSE
        SELECT 'wind_grid table not found' AS status
");
echo formatTable($gridStatus, 'Grid-Based Wind Data');

$gridAge = $gridStatus[0]['hours_since_fetch'] ?? null;
if ($gridAge !== null) {
    if ($gridAge <= 6) {
        echo $isWeb ? "<p class='good'>Grid data is FRESH (updated within 6 hours)</p>"
                    : "Status: FRESH - Grid data updated within 6 hours{$nl}";
    } elseif ($gridAge <= 12) {
        echo $isWeb ? "<p class='warn'>Grid data is STALE (>6 hours old) - fallback to GS-based</p>"
                    : "Status: STALE - Grid data >6 hours old, using GS-based fallback{$nl}";
    } else {
        echo $isWeb ? "<p class='bad'>Grid data is OLD (>12 hours) - run wind fetcher!</p>"
                    : "Status: OLD - Grid data >12 hours, run wind fetcher!{$nl}";
    }
}

// 6. SAMPLE FLIGHTS
echo $isWeb ? "<h2>6. Sample Flights with Significant Wind Impact</h2>" : "\n6. SAMPLE FLIGHTS\n";

$samples = query($conn, "
    SELECT TOP 15
        fp.fp_callsign AS callsign,
        fp.fp_dept_icao + '->' + fp.fp_dest_icao AS route,
        fp.fp_aircraft_type AS acft,
        CAST(p.altitude_ft / 100 AS VARCHAR) + '00' AS alt,
        CAST(p.groundspeed_kts AS INT) AS gs,
        CAST(COALESCE(perf.cruise_speed, 450) AS INT) AS exp_tas,
        CAST(ft.eta_wind_adj_kts AS INT) AS wind_adj,
        CAST(ft.eta_wind_confidence * 100 AS INT) AS conf_pct,
        CAST(p.dist_to_dest_nm AS INT) AS dist_nm,
        CAST(CASE
            WHEN p.dist_to_dest_nm > 50 AND COALESCE(perf.cruise_speed, 450) > 0
            THEN (p.dist_to_dest_nm / NULLIF(COALESCE(perf.cruise_speed, 450), 0) -
                  p.dist_to_dest_nm / NULLIF(COALESCE(perf.cruise_speed, 450) + ft.eta_wind_adj_kts, 0)) * 60
            ELSE 0
        END AS DECIMAL(5,1)) AS eta_impact_min,
        FORMAT(ft.eta_utc, 'HH:mm') AS eta
    FROM adl_flight_times ft
    JOIN adl_flight_core c ON c.flight_uid = ft.flight_uid
    JOIN adl_flight_position p ON p.flight_uid = ft.flight_uid
    JOIN adl_flight_plan fp ON fp.flight_uid = ft.flight_uid
    LEFT JOIN aircraft_performance_profiles perf ON perf.icao_type = fp.fp_aircraft_type
    WHERE c.is_active = 1
      AND c.phase IN ('enroute', 'cruise')
      AND ABS(ft.eta_wind_adj_kts) > 15
      AND p.dist_to_dest_nm > 100
    ORDER BY ABS(ft.eta_wind_adj_kts) DESC
");
echo formatTable($samples, 'Flights with >15kt Wind Adjustment');

// 7. SUMMARY STATISTICS
echo $isWeb ? "<h2>7. Summary Statistics</h2>" : "\n7. SUMMARY\n";

$summary = query($conn, "
    SELECT
        (SELECT COUNT(*) FROM adl_flight_core WHERE is_active = 1) AS active_flights,
        (SELECT COUNT(*) FROM adl_flight_times ft
         JOIN adl_flight_core c ON c.flight_uid = ft.flight_uid
         WHERE c.is_active = 1 AND ft.eta_wind_adj_kts IS NOT NULL) AS with_wind_calc,
        (SELECT COUNT(*) FROM adl_flight_times ft
         JOIN adl_flight_core c ON c.flight_uid = ft.flight_uid
         WHERE c.is_active = 1 AND ABS(ft.eta_wind_adj_kts) > 5) AS wind_applied_to_eta,
        (SELECT CAST(ROUND(AVG(ABS(ft.eta_wind_adj_kts)), 1) AS DECIMAL(5,1))
         FROM adl_flight_times ft
         JOIN adl_flight_core c ON c.flight_uid = ft.flight_uid
         WHERE c.is_active = 1 AND ft.eta_wind_adj_kts IS NOT NULL) AS avg_abs_wind_adj,
        (SELECT COUNT(*) FROM adl_flight_times ft
         JOIN adl_flight_core c ON c.flight_uid = ft.flight_uid
         WHERE c.is_active = 1 AND ft.eta_wind_confidence >= 0.90) AS grid_based_count,
        (SELECT COUNT(*) FROM adl_flight_times ft
         JOIN adl_flight_core c ON c.flight_uid = ft.flight_uid
         WHERE c.is_active = 1 AND ft.eta_wind_confidence >= 0.40 AND ft.eta_wind_confidence < 0.90) AS gs_based_count
");
echo formatTable($summary, 'Wind Integration Summary');

// Close
sqlsrv_close($conn);

echo $hr;
echo $isWeb ? "<h2>Analysis Notes</h2><pre>" : "\nANALYSIS NOTES\n";
echo "
Wind Adjustment Impact on ETA:
- Positive wind_adj (tailwind): Aircraft moves faster -> Earlier arrival -> ETA reduced
- Negative wind_adj (headwind): Aircraft moves slower -> Later arrival -> ETA increased
- Only adjustments >5 kts are applied to ETA calculation

Confidence Tiers:
- 0.90+: Grid-based wind from NOAA GFS data (most accurate, requires fetcher running)
- 0.60-0.89: GS-based estimate during cruise (compares groundspeed to expected TAS)
- 0.40-0.59: GS-based estimate in other phases (less reliable)
- <0.40: Insufficient data for wind calculation

ETA Impact Formula:
  time_impact = dist_nm / cruise_speed - dist_nm / (cruise_speed + wind_adj)

  Example: 500nm at 450kts with +50kt tailwind:
  - Without wind: 500/450 = 66.7 min
  - With wind: 500/500 = 60.0 min
  - Impact: -6.7 min (arrives 6.7 minutes earlier)
";
echo $isWeb ? "</pre>" : "";

if ($isWeb) echo "</body></html>";
?>
