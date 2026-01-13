<?php
/**
 * ADL Timing Analysis Endpoint
 *
 * Returns detailed analysis of timing consistency, accuracy, and coverage.
 *
 * Usage: GET /api/adl/timing-analysis.php
 *
 * Returns JSON with:
 *   - coverage: ETA/ETD coverage by phase
 *   - accuracy: Predicted vs actual ETA for arrived flights
 *   - consistency: ETA update frequency and stability
 *   - oooi: OOOI time capture rates
 *   - distribution: ETA prefix, confidence, distance source breakdown
 */

header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store, must-revalidate');

require_once __DIR__ . '/../../load/connect.php';

$response = [
    'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
    'analysis_period_hours' => 24,
    'database_connected' => false,
    'data' => []
];

// Check ADL connection
if (!isset($conn_adl) || $conn_adl === false) {
    $response['error'] = 'ADL database connection not available';
    echo json_encode($response, JSON_PRETTY_PRINT);
    exit;
}

$response['database_connected'] = true;

try {
    // =========================================================================
    // 1. DATASET OVERVIEW
    // =========================================================================
    $sql = "SELECT
                (SELECT COUNT(*) FROM dbo.adl_flight_core WHERE is_active = 1) AS active_flights,
                (SELECT COUNT(*) FROM dbo.adl_flight_times WHERE eta_utc IS NOT NULL) AS flights_with_eta,
                (SELECT COUNT(*) FROM dbo.adl_flight_times WHERE etd_utc IS NOT NULL) AS flights_with_etd,
                (SELECT COUNT(*) FROM dbo.adl_flight_core c
                 JOIN dbo.adl_flight_times t ON t.flight_uid = c.flight_uid
                 WHERE c.phase = 'arrived' AND t.ata_runway_utc >= DATEADD(HOUR, -24, SYSUTCDATETIME())) AS arrived_24h";

    $stmt = sqlsrv_query($conn_adl, $sql);
    if ($stmt && $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $response['data']['overview'] = $row;
        sqlsrv_free_stmt($stmt);
    }

    // =========================================================================
    // 2. ETA COVERAGE BY PHASE
    // =========================================================================
    $sql = "SELECT
                c.phase,
                COUNT(*) AS total_flights,
                SUM(CASE WHEN t.eta_utc IS NOT NULL THEN 1 ELSE 0 END) AS has_eta,
                SUM(CASE WHEN t.etd_utc IS NOT NULL THEN 1 ELSE 0 END) AS has_etd,
                CAST(100.0 * SUM(CASE WHEN t.eta_utc IS NOT NULL THEN 1 ELSE 0 END) / NULLIF(COUNT(*), 0) AS DECIMAL(5,1)) AS eta_pct,
                AVG(t.eta_confidence) AS avg_confidence
            FROM dbo.adl_flight_core c
            LEFT JOIN dbo.adl_flight_times t ON t.flight_uid = c.flight_uid
            WHERE c.is_active = 1
            GROUP BY c.phase
            ORDER BY total_flights DESC";

    $stmt = sqlsrv_query($conn_adl, $sql);
    $coverage = [];
    if ($stmt) {
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $coverage[] = $row;
        }
        sqlsrv_free_stmt($stmt);
    }
    $response['data']['coverage_by_phase'] = $coverage;

    // =========================================================================
    // 3. ETA ACCURACY (Predicted vs Actual)
    // =========================================================================
    $sql = "WITH ArrivedFlights AS (
                SELECT
                    DATEDIFF(SECOND, t.eta_utc, t.ata_runway_utc) / 60.0 AS error_minutes,
                    t.eta_dist_source,
                    t.eta_confidence
                FROM dbo.adl_flight_core c
                JOIN dbo.adl_flight_times t ON t.flight_uid = c.flight_uid
                WHERE c.phase = 'arrived'
                  AND t.ata_runway_utc IS NOT NULL
                  AND t.eta_utc IS NOT NULL
                  AND t.ata_runway_utc >= DATEADD(HOUR, -24, SYSUTCDATETIME())
            )
            SELECT
                COUNT(*) AS sample_size,
                AVG(error_minutes) AS mean_error_min,
                STDEV(error_minutes) AS stddev_min,
                MIN(error_minutes) AS min_error_min,
                MAX(error_minutes) AS max_error_min,
                AVG(CASE WHEN ABS(error_minutes) <= 5 THEN 1.0 ELSE 0.0 END) * 100 AS pct_within_5min,
                AVG(CASE WHEN ABS(error_minutes) <= 10 THEN 1.0 ELSE 0.0 END) * 100 AS pct_within_10min,
                AVG(CASE WHEN ABS(error_minutes) <= 15 THEN 1.0 ELSE 0.0 END) * 100 AS pct_within_15min
            FROM ArrivedFlights";

    $stmt = sqlsrv_query($conn_adl, $sql);
    if ($stmt && $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $response['data']['eta_accuracy'] = $row;
        sqlsrv_free_stmt($stmt);
    }

    // Accuracy by distance source
    $sql = "WITH ArrivedFlights AS (
                SELECT
                    t.eta_dist_source,
                    DATEDIFF(SECOND, t.eta_utc, t.ata_runway_utc) / 60.0 AS error_minutes
                FROM dbo.adl_flight_core c
                JOIN dbo.adl_flight_times t ON t.flight_uid = c.flight_uid
                WHERE c.phase = 'arrived'
                  AND t.ata_runway_utc IS NOT NULL
                  AND t.eta_utc IS NOT NULL
                  AND t.ata_runway_utc >= DATEADD(HOUR, -24, SYSUTCDATETIME())
            )
            SELECT
                COALESCE(eta_dist_source, 'NOT_SET') AS distance_source,
                COUNT(*) AS sample_size,
                AVG(error_minutes) AS mean_error_min,
                STDEV(error_minutes) AS stddev_min,
                AVG(CASE WHEN ABS(error_minutes) <= 5 THEN 1.0 ELSE 0.0 END) * 100 AS pct_within_5min
            FROM ArrivedFlights
            GROUP BY eta_dist_source
            ORDER BY sample_size DESC";

    $stmt = sqlsrv_query($conn_adl, $sql);
    $accuracy_by_source = [];
    if ($stmt) {
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $accuracy_by_source[] = $row;
        }
        sqlsrv_free_stmt($stmt);
    }
    $response['data']['eta_accuracy_by_source'] = $accuracy_by_source;

    // =========================================================================
    // 4. ETA UPDATE FREQUENCY
    // =========================================================================
    $sql = "SELECT
                CASE
                    WHEN DATEDIFF(SECOND, t.eta_last_calc_utc, SYSUTCDATETIME()) < 30 THEN '< 30 sec'
                    WHEN DATEDIFF(SECOND, t.eta_last_calc_utc, SYSUTCDATETIME()) < 60 THEN '30-60 sec'
                    WHEN DATEDIFF(SECOND, t.eta_last_calc_utc, SYSUTCDATETIME()) < 120 THEN '1-2 min'
                    WHEN DATEDIFF(SECOND, t.eta_last_calc_utc, SYSUTCDATETIME()) < 300 THEN '2-5 min'
                    WHEN DATEDIFF(SECOND, t.eta_last_calc_utc, SYSUTCDATETIME()) < 600 THEN '5-10 min'
                    ELSE '> 10 min'
                END AS time_since_calc,
                COUNT(*) AS flight_count
            FROM dbo.adl_flight_times t
            JOIN dbo.adl_flight_core c ON c.flight_uid = t.flight_uid
            WHERE c.is_active = 1 AND t.eta_last_calc_utc IS NOT NULL
            GROUP BY
                CASE
                    WHEN DATEDIFF(SECOND, t.eta_last_calc_utc, SYSUTCDATETIME()) < 30 THEN '< 30 sec'
                    WHEN DATEDIFF(SECOND, t.eta_last_calc_utc, SYSUTCDATETIME()) < 60 THEN '30-60 sec'
                    WHEN DATEDIFF(SECOND, t.eta_last_calc_utc, SYSUTCDATETIME()) < 120 THEN '1-2 min'
                    WHEN DATEDIFF(SECOND, t.eta_last_calc_utc, SYSUTCDATETIME()) < 300 THEN '2-5 min'
                    WHEN DATEDIFF(SECOND, t.eta_last_calc_utc, SYSUTCDATETIME()) < 600 THEN '5-10 min'
                    ELSE '> 10 min'
                END";

    $stmt = sqlsrv_query($conn_adl, $sql);
    $update_freq = [];
    if ($stmt) {
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $update_freq[] = $row;
        }
        sqlsrv_free_stmt($stmt);
    }
    $response['data']['eta_update_frequency'] = $update_freq;

    // =========================================================================
    // 5. OOOI CAPTURE RATES
    // =========================================================================
    $sql = "SELECT
                COUNT(*) AS arrived_flights,
                SUM(CASE WHEN t.out_utc IS NOT NULL THEN 1 ELSE 0 END) AS has_out,
                SUM(CASE WHEN t.off_utc IS NOT NULL THEN 1 ELSE 0 END) AS has_off,
                SUM(CASE WHEN t.on_utc IS NOT NULL THEN 1 ELSE 0 END) AS has_on,
                SUM(CASE WHEN t.in_utc IS NOT NULL THEN 1 ELSE 0 END) AS has_in,
                CAST(100.0 * SUM(CASE WHEN t.out_utc IS NOT NULL THEN 1 ELSE 0 END) / NULLIF(COUNT(*), 0) AS DECIMAL(5,1)) AS out_pct,
                CAST(100.0 * SUM(CASE WHEN t.off_utc IS NOT NULL THEN 1 ELSE 0 END) / NULLIF(COUNT(*), 0) AS DECIMAL(5,1)) AS off_pct,
                CAST(100.0 * SUM(CASE WHEN t.on_utc IS NOT NULL THEN 1 ELSE 0 END) / NULLIF(COUNT(*), 0) AS DECIMAL(5,1)) AS on_pct,
                CAST(100.0 * SUM(CASE WHEN t.in_utc IS NOT NULL THEN 1 ELSE 0 END) / NULLIF(COUNT(*), 0) AS DECIMAL(5,1)) AS in_pct
            FROM dbo.adl_flight_core c
            JOIN dbo.adl_flight_times t ON t.flight_uid = c.flight_uid
            WHERE c.phase = 'arrived'
              AND t.ata_runway_utc >= DATEADD(HOUR, -24, SYSUTCDATETIME())";

    $stmt = sqlsrv_query($conn_adl, $sql);
    if ($stmt && $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $response['data']['oooi_capture_rates'] = $row;
        sqlsrv_free_stmt($stmt);
    }

    // =========================================================================
    // 6. ETA PREFIX DISTRIBUTION
    // =========================================================================
    $sql = "SELECT
                COALESCE(t.eta_prefix, 'NULL') AS eta_prefix,
                CASE t.eta_prefix
                    WHEN 'A' THEN 'Actual (arrived)'
                    WHEN 'E' THEN 'Estimated (calculated)'
                    WHEN 'C' THEN 'Controlled (TMI applied)'
                    WHEN 'P' THEN 'Proposed (prefile)'
                    ELSE 'Not set'
                END AS description,
                COUNT(*) AS flight_count,
                AVG(t.eta_confidence) AS avg_confidence
            FROM dbo.adl_flight_times t
            JOIN dbo.adl_flight_core c ON c.flight_uid = t.flight_uid
            WHERE c.is_active = 1
            GROUP BY t.eta_prefix
            ORDER BY flight_count DESC";

    $stmt = sqlsrv_query($conn_adl, $sql);
    $prefix_dist = [];
    if ($stmt) {
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $prefix_dist[] = $row;
        }
        sqlsrv_free_stmt($stmt);
    }
    $response['data']['eta_prefix_distribution'] = $prefix_dist;

    // =========================================================================
    // 7. ETD SOURCE DISTRIBUTION
    // =========================================================================
    $sql = "SELECT
                COALESCE(t.etd_source, 'NULL') AS etd_source,
                CASE t.etd_source
                    WHEN 'D' THEN 'DOF from flight plan'
                    WHEN 'P' THEN 'Position-inferred'
                    WHEN 'N' THEN 'No valid estimate'
                    ELSE 'Not set'
                END AS description,
                COUNT(*) AS flight_count
            FROM dbo.adl_flight_times t
            JOIN dbo.adl_flight_core c ON c.flight_uid = t.flight_uid
            WHERE c.is_active = 1
            GROUP BY t.etd_source
            ORDER BY flight_count DESC";

    $stmt = sqlsrv_query($conn_adl, $sql);
    $etd_source = [];
    if ($stmt) {
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $etd_source[] = $row;
        }
        sqlsrv_free_stmt($stmt);
    }
    $response['data']['etd_source_distribution'] = $etd_source;

    // =========================================================================
    // 8. CONFIDENCE DISTRIBUTION
    // =========================================================================
    $sql = "SELECT
                CASE
                    WHEN t.eta_confidence >= 0.95 THEN '95-100%'
                    WHEN t.eta_confidence >= 0.90 THEN '90-95%'
                    WHEN t.eta_confidence >= 0.85 THEN '85-90%'
                    WHEN t.eta_confidence >= 0.80 THEN '80-85%'
                    WHEN t.eta_confidence >= 0.70 THEN '70-80%'
                    WHEN t.eta_confidence >= 0.65 THEN '65-70%'
                    ELSE '< 65%'
                END AS confidence_band,
                COUNT(*) AS flight_count,
                AVG(t.eta_confidence) AS avg_confidence
            FROM dbo.adl_flight_times t
            JOIN dbo.adl_flight_core c ON c.flight_uid = t.flight_uid
            WHERE c.is_active = 1 AND t.eta_confidence IS NOT NULL
            GROUP BY
                CASE
                    WHEN t.eta_confidence >= 0.95 THEN '95-100%'
                    WHEN t.eta_confidence >= 0.90 THEN '90-95%'
                    WHEN t.eta_confidence >= 0.85 THEN '85-90%'
                    WHEN t.eta_confidence >= 0.80 THEN '80-85%'
                    WHEN t.eta_confidence >= 0.70 THEN '70-80%'
                    WHEN t.eta_confidence >= 0.65 THEN '65-70%'
                    ELSE '< 65%'
                END
            ORDER BY avg_confidence DESC";

    $stmt = sqlsrv_query($conn_adl, $sql);
    $confidence = [];
    if ($stmt) {
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $confidence[] = $row;
        }
        sqlsrv_free_stmt($stmt);
    }
    $response['data']['confidence_distribution'] = $confidence;

    // =========================================================================
    // 9. SAMPLE ARRIVED FLIGHTS (for inspection)
    // =========================================================================
    $sql = "SELECT TOP 10
                c.callsign,
                fp.fp_dept_icao AS origin,
                fp.fp_dest_icao AS dest,
                t.etd_utc,
                t.off_utc,
                t.eta_utc AS final_eta,
                t.on_utc,
                t.ata_runway_utc AS actual_arrival,
                DATEDIFF(MINUTE, t.eta_utc, t.ata_runway_utc) AS eta_error_min,
                t.eta_confidence,
                t.eta_prefix,
                t.eta_dist_source
            FROM dbo.adl_flight_core c
            JOIN dbo.adl_flight_times t ON t.flight_uid = c.flight_uid
            JOIN dbo.adl_flight_plan fp ON fp.flight_uid = c.flight_uid
            WHERE c.phase = 'arrived'
              AND t.ata_runway_utc IS NOT NULL
              AND t.ata_runway_utc >= DATEADD(HOUR, -24, SYSUTCDATETIME())
            ORDER BY t.ata_runway_utc DESC";

    $stmt = sqlsrv_query($conn_adl, $sql);
    $samples = [];
    if ($stmt) {
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            // Format datetime objects
            foreach ($row as $key => $val) {
                if ($val instanceof DateTimeInterface) {
                    $row[$key] = $val->format('Y-m-d\TH:i:s\Z');
                }
            }
            $samples[] = $row;
        }
        sqlsrv_free_stmt($stmt);
    }
    $response['data']['sample_arrived_flights'] = $samples;

} catch (Exception $e) {
    $response['error'] = 'Query error: ' . $e->getMessage();
}

echo json_encode($response, JSON_PRETTY_PRINT);
