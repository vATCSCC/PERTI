<?php
/**
 * PERTI System Status Dashboard
 *
 * Live operational status including:
 * - Database metrics (queue counts, flight counts, refresh times)
 * - External API health checks (VATSIM, Aviation Weather, NOAA)
 * - Recent activity counts (parse/ETA/zone detection)
 * - Resource tree visualization
 * - Stored procedures and migration status
 */

include("sessions/handler.php");
if (session_status() == PHP_SESSION_NONE) {
    session_start();
    ob_start();
}

// Start page timing
$pageStartTime = microtime(true);

include("load/config.php");
include("load/connect.php");

// Check permissions
$perm = false;
if (!defined('DEV')) {
    if (isset($_SESSION['VATSIM_CID'])) {
        $cid = session_get('VATSIM_CID', '');
        $p_check = $conn_sqli->query("SELECT * FROM users WHERE cid='$cid'");
        if ($p_check) {
            $perm = true;
        }
    }
} else {
    $perm = true;
}

// Current timestamp
$current_time = gmdate('d M Y H:i');

// =============================================================================
// LIVE DATA COLLECTION
// =============================================================================

$liveData = [
    'adl_connected' => false,
    'mysql_connected' => false,
    'active_flights' => 0,
    'total_flights_today' => 0,
    'queue_pending' => 0,
    'queue_processing' => 0,
    'queue_complete_1h' => 0,
    'queue_failed_1h' => 0,
    'queue_total' => 0,
    'avg_parse_ms' => 0,
    'last_vatsim_refresh' => null,
    'trajectories_1h' => 0,
    'trajectories_total' => 0,
    'zone_transitions_1h' => 0,
    // Boundary Detection (Background Job)
    'boundary_crossings_1h' => 0,
    'boundary_crossings_24h' => 0,
    'boundary_artcc_1h' => 0,
    'boundary_tracon_1h' => 0,
    'boundary_pending' => 0,           // Flights needing boundary detection
    'last_boundary_detection' => null,
    'flights_with_artcc' => 0,
    'flights_with_tracon' => 0,
    // Planned Crossings (Background Job)
    'planned_crossings_1h' => 0,
    'planned_crossings_24h' => 0,
    'crossings_pending' => 0,          // Flights with needs_recalc or no calc
    'last_crossing_calc' => null,
    'flights_with_crossings' => 0,
    'crossing_tiers' => [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0, 6 => 0, 7 => 0],
    // ETA Calculation Stats
    'etas_calculated_24h' => 0,
    'etas_pending' => 0,
    'flights_with_eta' => 0,
    'weather_alerts_active' => 0,
    'atis_updates_1h' => 0,
    'atis_pending' => 0,
    'atis_parsed' => 0,
    'atis_failed' => 0,
    'atis_today' => 0,
    'atis_airports_active' => 0,
    'waypoints_total' => 0,
    'boundaries_total' => 0,
];

// Runtime tracking
$runtimes = [
    'adl_queries' => 0,
    'mysql_queries' => 0,
    'api_checks' => 0,
    'total' => 0,
];

$apiHealth = [
    'vatsim' => ['status' => 'unknown', 'latency' => null, 'message' => 'Not checked'],
    'aviationweather' => ['status' => 'unknown', 'latency' => null, 'message' => 'Not checked'],
    'noaa' => ['status' => 'unknown', 'latency' => null, 'message' => 'Not checked'],
];

$overallStatus = 'operational';
$statusIssues = [];

// -----------------------------------------------------------------------------
// MySQL Connection Check
// -----------------------------------------------------------------------------
if (isset($conn_sqli) && $conn_sqli) {
    $liveData['mysql_connected'] = true;
} else {
    $statusIssues[] = 'MySQL connection failed';
    $overallStatus = 'degraded';
}

// -----------------------------------------------------------------------------
// ADL (Azure SQL) Live Metrics
// -----------------------------------------------------------------------------
$adlQueryStart = microtime(true);

if (isset($conn_adl) && $conn_adl !== null && $conn_adl !== false) {
    $liveData['adl_connected'] = true;

    // Flight counts - active and today's total (use view for normalized schema)
    $sql = "SELECT
                COUNT(CASE WHEN is_active = 1 THEN 1 END) AS active_cnt,
                COUNT(*) AS total_cnt
            FROM dbo.vw_adl_flights
            WHERE snapshot_utc > DATEADD(DAY, -1, SYSUTCDATETIME())";
    $stmt = @sqlsrv_query($conn_adl, $sql);
    if ($stmt) {
        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        $liveData['active_flights'] = $row['active_cnt'] ?? 0;
        $liveData['total_flights_today'] = $row['total_cnt'] ?? 0;
        sqlsrv_free_stmt($stmt);
    }

    // Parse queue stats (comprehensive)
    $sql = "
        SELECT
            COUNT(*) AS total,
            COUNT(CASE WHEN status = 'PENDING' THEN 1 END) AS pending,
            COUNT(CASE WHEN status = 'PROCESSING' THEN 1 END) AS processing,
            COUNT(CASE WHEN status = 'COMPLETE' AND queued_utc > DATEADD(HOUR, -1, SYSUTCDATETIME()) THEN 1 END) AS complete_1h,
            COUNT(CASE WHEN status = 'FAILED' AND queued_utc > DATEADD(HOUR, -1, SYSUTCDATETIME()) THEN 1 END) AS failed_1h,
            AVG(CASE WHEN status = 'COMPLETE' THEN DATEDIFF(MILLISECOND, started_utc, completed_utc) END) AS avg_parse_ms
        FROM dbo.adl_parse_queue
    ";
    $stmt = @sqlsrv_query($conn_adl, $sql);
    if ($stmt) {
        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        $liveData['queue_total'] = $row['total'] ?? 0;
        $liveData['queue_pending'] = $row['pending'] ?? 0;
        $liveData['queue_processing'] = $row['processing'] ?? 0;
        $liveData['queue_complete_1h'] = $row['complete_1h'] ?? 0;
        $liveData['queue_failed_1h'] = $row['failed_1h'] ?? 0;
        $liveData['avg_parse_ms'] = round($row['avg_parse_ms'] ?? 0);
        sqlsrv_free_stmt($stmt);
    }

    // Last VATSIM refresh time (use view for normalized schema)
    $sql = "SELECT TOP 1 snapshot_utc FROM dbo.vw_adl_flights ORDER BY snapshot_utc DESC";
    $stmt = @sqlsrv_query($conn_adl, $sql);
    if ($stmt) {
        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        if ($row && isset($row['snapshot_utc'])) {
            $dt = $row['snapshot_utc'];
            if ($dt instanceof DateTimeInterface) {
                $liveData['last_vatsim_refresh'] = $dt->format('Y-m-d H:i:s') . ' UTC';
            } else {
                $liveData['last_vatsim_refresh'] = $dt;
            }
        }
        sqlsrv_free_stmt($stmt);
    }

    // Trajectory counts
    $sql = "SELECT
                COUNT(CASE WHEN recorded_utc > DATEADD(HOUR, -1, SYSUTCDATETIME()) THEN 1 END) AS cnt_1h,
                COUNT(*) AS cnt_total
            FROM dbo.adl_flight_trajectory";
    $stmt = @sqlsrv_query($conn_adl, $sql);
    if ($stmt) {
        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        $liveData['trajectories_1h'] = $row['cnt_1h'] ?? 0;
        $liveData['trajectories_total'] = $row['cnt_total'] ?? 0;
        sqlsrv_free_stmt($stmt);
    }

    // Waypoints count (parsed route data)
    $sql = "SELECT COUNT(*) AS cnt FROM dbo.adl_flight_waypoints";
    $stmt = @sqlsrv_query($conn_adl, $sql);
    if ($stmt) {
        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        $liveData['waypoints_total'] = $row['cnt'] ?? 0;
        sqlsrv_free_stmt($stmt);
    }

    // Zone transitions (last hour)
    $sql = "SELECT COUNT(*) AS cnt FROM dbo.adl_zone_events WHERE event_utc > DATEADD(HOUR, -1, SYSUTCDATETIME())";
    $stmt = @sqlsrv_query($conn_adl, $sql);
    if ($stmt) {
        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        $liveData['zone_transitions_1h'] = $row['cnt'] ?? 0;
        sqlsrv_free_stmt($stmt);
    }

    // -------------------------------------------------------------------------
    // Boundary Detection Stats (Background Job)
    // -------------------------------------------------------------------------

    // Boundary crossings by type (last hour)
    $sql = "SELECT
                b.boundary_type,
                COUNT(*) AS cnt
            FROM dbo.adl_flight_boundary_log bl
            JOIN dbo.adl_boundary b ON b.boundary_id = bl.boundary_id
            WHERE bl.entry_time > DATEADD(HOUR, -1, SYSUTCDATETIME())
            GROUP BY b.boundary_type";
    $stmt = @sqlsrv_query($conn_adl, $sql);
    if ($stmt) {
        $total = 0;
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $type = $row['boundary_type'];
            $cnt = (int)($row['cnt'] ?? 0);
            $total += $cnt;
            if ($type === 'ARTCC') $liveData['boundary_artcc_1h'] = $cnt;
            if ($type === 'TRACON') $liveData['boundary_tracon_1h'] = $cnt;
        }
        $liveData['boundary_crossings_1h'] = $total;
        sqlsrv_free_stmt($stmt);
    }

    // Boundary crossings (last 24 hours) - use JOIN for consistency with 1h query
    $sql = "SELECT COUNT(*) AS cnt
            FROM dbo.adl_flight_boundary_log bl
            JOIN dbo.adl_boundary b ON b.boundary_id = bl.boundary_id
            WHERE bl.entry_time > DATEADD(HOUR, -24, SYSUTCDATETIME())";
    $stmt = @sqlsrv_query($conn_adl, $sql);
    if ($stmt) {
        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        $liveData['boundary_crossings_24h'] = $row['cnt'] ?? 0;
        sqlsrv_free_stmt($stmt);
    }

    // Flights pending boundary detection (grid changed or no ARTCC)
    $sql = "SELECT COUNT(*) AS cnt
            FROM dbo.adl_flight_core c
            JOIN dbo.adl_flight_position p ON p.flight_uid = c.flight_uid
            WHERE c.is_active = 1
              AND p.lat IS NOT NULL
              AND (
                  c.current_artcc_id IS NULL
                  OR c.last_grid_lat IS NULL
                  OR c.last_grid_lat != CAST(FLOOR(p.lat / 0.5) AS SMALLINT)
                  OR c.last_grid_lon != CAST(FLOOR(p.lon / 0.5) AS SMALLINT)
              )";
    $stmt = @sqlsrv_query($conn_adl, $sql);
    if ($stmt) {
        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        $liveData['boundary_pending'] = $row['cnt'] ?? 0;
        sqlsrv_free_stmt($stmt);
    }

    // Last boundary detection time
    $sql = "SELECT TOP 1 entry_time FROM dbo.adl_flight_boundary_log ORDER BY entry_time DESC";
    $stmt = @sqlsrv_query($conn_adl, $sql);
    if ($stmt) {
        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        if ($row && $row['entry_time'] instanceof DateTime) {
            $liveData['last_boundary_detection'] = $row['entry_time']->format('H:i:s') . 'Z';
        }
        sqlsrv_free_stmt($stmt);
    }

    // Flights with ARTCC/TRACON assigned
    $sql = "SELECT
                SUM(CASE WHEN current_artcc IS NOT NULL THEN 1 ELSE 0 END) AS with_artcc,
                SUM(CASE WHEN current_tracon IS NOT NULL THEN 1 ELSE 0 END) AS with_tracon
            FROM dbo.adl_flight_core WHERE is_active = 1";
    $stmt = @sqlsrv_query($conn_adl, $sql);
    if ($stmt) {
        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        $liveData['flights_with_artcc'] = $row['with_artcc'] ?? 0;
        $liveData['flights_with_tracon'] = $row['with_tracon'] ?? 0;
        sqlsrv_free_stmt($stmt);
    }

    // -------------------------------------------------------------------------
    // Planned Crossings Stats (Background Job)
    // -------------------------------------------------------------------------

    // Planned crossings calculated (last hour)
    $sql = "SELECT COUNT(*) AS cnt FROM dbo.adl_flight_planned_crossings WHERE calculated_at > DATEADD(HOUR, -1, SYSUTCDATETIME())";
    $stmt = @sqlsrv_query($conn_adl, $sql);
    if ($stmt) {
        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        $liveData['planned_crossings_1h'] = $row['cnt'] ?? 0;
        sqlsrv_free_stmt($stmt);
    }

    // Planned crossings calculated (last 24 hours)
    $sql = "SELECT COUNT(*) AS cnt FROM dbo.adl_flight_planned_crossings WHERE calculated_at > DATEADD(HOUR, -24, SYSUTCDATETIME())";
    $stmt = @sqlsrv_query($conn_adl, $sql);
    if ($stmt) {
        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        $liveData['planned_crossings_24h'] = $row['cnt'] ?? 0;
        sqlsrv_free_stmt($stmt);
    }

    // Flights pending crossing calculation
    $sql = "SELECT COUNT(*) AS cnt
            FROM dbo.adl_flight_core
            WHERE is_active = 1
              AND crossing_region_flags IS NOT NULL
              AND (crossing_last_calc_utc IS NULL OR crossing_needs_recalc = 1)";
    $stmt = @sqlsrv_query($conn_adl, $sql);
    if ($stmt) {
        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        $liveData['crossings_pending'] = $row['cnt'] ?? 0;
        sqlsrv_free_stmt($stmt);
    }

    // Last crossing calc time
    $sql = "SELECT TOP 1 calculated_at FROM dbo.adl_flight_planned_crossings ORDER BY calculated_at DESC";
    $stmt = @sqlsrv_query($conn_adl, $sql);
    if ($stmt) {
        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        if ($row && $row['calculated_at'] instanceof DateTime) {
            $liveData['last_crossing_calc'] = $row['calculated_at']->format('H:i:s') . 'Z';
        }
        sqlsrv_free_stmt($stmt);
    }

    // Flights with crossings calculated (count distinct flights in crossings table)
    $sql = "SELECT COUNT(DISTINCT pc.flight_uid) AS with_crossings
            FROM dbo.adl_flight_planned_crossings pc
            JOIN dbo.adl_flight_core c ON c.flight_uid = pc.flight_uid
            WHERE c.is_active = 1";
    $stmt = @sqlsrv_query($conn_adl, $sql);
    if ($stmt) {
        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        $liveData['flights_with_crossings'] = $row['with_crossings'] ?? 0;
        sqlsrv_free_stmt($stmt);
    }

    // Crossing tier distribution from core table
    $sql = "SELECT
                SUM(CASE WHEN crossing_tier = 1 THEN 1 ELSE 0 END) AS tier1,
                SUM(CASE WHEN crossing_tier = 2 THEN 1 ELSE 0 END) AS tier2,
                SUM(CASE WHEN crossing_tier = 3 THEN 1 ELSE 0 END) AS tier3,
                SUM(CASE WHEN crossing_tier = 4 THEN 1 ELSE 0 END) AS tier4,
                SUM(CASE WHEN crossing_tier = 5 THEN 1 ELSE 0 END) AS tier5,
                SUM(CASE WHEN crossing_tier = 6 THEN 1 ELSE 0 END) AS tier6,
                SUM(CASE WHEN crossing_tier = 7 THEN 1 ELSE 0 END) AS tier7
            FROM dbo.adl_flight_core
            WHERE is_active = 1 AND crossing_tier IS NOT NULL";
    $stmt = @sqlsrv_query($conn_adl, $sql);
    if ($stmt) {
        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        $liveData['crossing_tiers'] = [
            1 => (int)($row['tier1'] ?? 0),
            2 => (int)($row['tier2'] ?? 0),
            3 => (int)($row['tier3'] ?? 0),
            4 => (int)($row['tier4'] ?? 0),
            5 => (int)($row['tier5'] ?? 0),
            6 => (int)($row['tier6'] ?? 0),
            7 => (int)($row['tier7'] ?? 0),
        ];
        sqlsrv_free_stmt($stmt);
    }

    // Total boundaries defined
    $sql = "SELECT COUNT(*) AS cnt FROM dbo.adl_boundary";
    $stmt = @sqlsrv_query($conn_adl, $sql);
    if ($stmt) {
        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        $liveData['boundaries_total'] = $row['cnt'] ?? 0;
        sqlsrv_free_stmt($stmt);
    }

    // Active weather alerts
    $sql = "SELECT COUNT(*) AS cnt FROM dbo.weather_alerts WHERE valid_time_to > SYSUTCDATETIME()";
    $stmt = @sqlsrv_query($conn_adl, $sql);
    if ($stmt) {
        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        $liveData['weather_alerts_active'] = $row['cnt'] ?? 0;
        sqlsrv_free_stmt($stmt);
    }

    // ADL Refresh Procedure Step Metrics
    // Step 2: New flights in last 15 minutes
    $sql = "SELECT COUNT(*) AS cnt FROM dbo.adl_flight_core WHERE first_seen_utc > DATEADD(MINUTE, -15, SYSUTCDATETIME())";
    $stmt = @sqlsrv_query($conn_adl, $sql);
    if ($stmt) {
        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        $liveData['new_flights_15m'] = $row['cnt'] ?? 0;
        sqlsrv_free_stmt($stmt);
    }

    // Step 2: Updated flights in last 15 minutes
    $sql = "SELECT COUNT(*) AS cnt FROM dbo.adl_flight_core WHERE last_seen_utc > DATEADD(MINUTE, -15, SYSUTCDATETIME()) AND first_seen_utc < DATEADD(MINUTE, -15, SYSUTCDATETIME())";
    $stmt = @sqlsrv_query($conn_adl, $sql);
    if ($stmt) {
        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        $liveData['updated_flights_15m'] = $row['cnt'] ?? 0;
        sqlsrv_free_stmt($stmt);
    }

    // Step 4: Routes queued in last 15 minutes
    $sql = "SELECT COUNT(*) AS cnt FROM dbo.adl_parse_queue WHERE queued_utc > DATEADD(MINUTE, -15, SYSUTCDATETIME())";
    $stmt = @sqlsrv_query($conn_adl, $sql);
    if ($stmt) {
        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        $liveData['routes_queued_15m'] = $row['cnt'] ?? 0;
        sqlsrv_free_stmt($stmt);
    }

    // Step 4b: ETDs calculated (flights with etd_utc set recently)
    $sql = "SELECT COUNT(*) AS cnt FROM dbo.adl_flight_times WHERE times_updated_utc > DATEADD(MINUTE, -15, SYSUTCDATETIME()) AND etd_utc IS NOT NULL";
    $stmt = @sqlsrv_query($conn_adl, $sql);
    if ($stmt) {
        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        $liveData['etds_calculated_15m'] = $row['cnt'] ?? 0;
        sqlsrv_free_stmt($stmt);
    }

    // Step 4c: SimBrief parsed flights
    $sql = "SELECT COUNT(*) AS cnt FROM dbo.adl_flight_plan WHERE is_simbrief = 1";
    $stmt = @sqlsrv_query($conn_adl, $sql);
    if ($stmt) {
        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        $liveData['simbrief_flights'] = $row['cnt'] ?? 0;
        sqlsrv_free_stmt($stmt);
    }

    // Step 8: ETAs calculated in last 15 minutes
    $sql = "SELECT COUNT(*) AS cnt FROM dbo.adl_flight_times WHERE times_updated_utc > DATEADD(MINUTE, -15, SYSUTCDATETIME()) AND eta_utc IS NOT NULL";
    $stmt = @sqlsrv_query($conn_adl, $sql);
    if ($stmt) {
        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        $liveData['etas_calculated_15m'] = $row['cnt'] ?? 0;
        sqlsrv_free_stmt($stmt);
    }

    // ETAs calculated in last 24 hours
    $sql = "SELECT COUNT(*) AS cnt FROM dbo.adl_flight_times WHERE times_updated_utc > DATEADD(HOUR, -24, SYSUTCDATETIME()) AND eta_utc IS NOT NULL";
    $stmt = @sqlsrv_query($conn_adl, $sql);
    if ($stmt) {
        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        $liveData['etas_calculated_24h'] = $row['cnt'] ?? 0;
        sqlsrv_free_stmt($stmt);
    }

    // Flights with ETA (active flights that have eta_utc calculated)
    $sql = "SELECT COUNT(*) AS cnt FROM dbo.adl_flight_times t
            JOIN dbo.adl_flight_core c ON c.flight_uid = t.flight_uid
            WHERE c.is_active = 1 AND t.eta_utc IS NOT NULL";
    $stmt = @sqlsrv_query($conn_adl, $sql);
    if ($stmt) {
        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        $liveData['flights_with_eta'] = $row['cnt'] ?? 0;
        sqlsrv_free_stmt($stmt);
    }

    // Flights pending ETA calculation (active flights without eta_utc)
    $sql = "SELECT COUNT(*) AS cnt FROM dbo.adl_flight_core c
            LEFT JOIN dbo.adl_flight_times t ON t.flight_uid = c.flight_uid
            WHERE c.is_active = 1 AND (t.eta_utc IS NULL OR t.flight_uid IS NULL)";
    $stmt = @sqlsrv_query($conn_adl, $sql);
    if ($stmt) {
        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        $liveData['etas_pending'] = $row['cnt'] ?? 0;
        sqlsrv_free_stmt($stmt);
    }

    // Step 8c: Waypoint ETAs (total waypoints with ETA)
    $sql = "SELECT COUNT(*) AS cnt FROM dbo.adl_flight_waypoints WHERE eta_utc IS NOT NULL";
    $stmt = @sqlsrv_query($conn_adl, $sql);
    if ($stmt) {
        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        $liveData['waypoint_etas_total'] = $row['cnt'] ?? 0;
        sqlsrv_free_stmt($stmt);
    }

    // Step 7: Inactive flights marked recently
    $sql = "SELECT COUNT(*) AS cnt FROM dbo.adl_flight_core WHERE is_active = 0 AND last_seen_utc > DATEADD(HOUR, -1, SYSUTCDATETIME())";
    $stmt = @sqlsrv_query($conn_adl, $sql);
    if ($stmt) {
        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        $liveData['inactive_flights_1h'] = $row['cnt'] ?? 0;
        sqlsrv_free_stmt($stmt);
    }

    // Last refresh timestamp from snapshot
    $sql = "SELECT MAX(snapshot_utc) AS last_refresh FROM dbo.adl_flight_core WHERE is_active = 1";
    $stmt = @sqlsrv_query($conn_adl, $sql);
    if ($stmt) {
        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        if ($row && isset($row['last_refresh'])) {
            $dt = $row['last_refresh'];
            if ($dt instanceof DateTimeInterface) {
                $liveData['last_refresh_utc'] = $dt->format('H:i:s');
                $liveData['last_refresh_ago'] = round((time() - $dt->getTimestamp()));
            }
        }
        sqlsrv_free_stmt($stmt);
    }

    // Phase snapshots in last hour
    $sql = "SELECT COUNT(*) AS cnt FROM dbo.flight_phase_snapshot WHERE snapshot_utc > DATEADD(HOUR, -1, SYSUTCDATETIME())";
    $stmt = @sqlsrv_query($conn_adl, $sql);
    if ($stmt) {
        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        $liveData['phase_snapshots_1h'] = $row['cnt'] ?? 0;
        sqlsrv_free_stmt($stmt);
    }

    // -------------------------------------------------------------------------
    // Tier Tracking: Parse Tiers (0-4) for route parsing priority
    // Uses adl_parse_queue which has the actual tier assignments
    // -------------------------------------------------------------------------
    $liveData['parse_tiers'] = [0 => 0, 1 => 0, 2 => 0, 3 => 0, 4 => 0];
    $sql = "SELECT q.parse_tier, COUNT(*) AS cnt
            FROM dbo.adl_parse_queue q
            INNER JOIN dbo.adl_flight_core c ON q.flight_uid = c.flight_uid
            WHERE c.is_active = 1 AND q.parse_tier IS NOT NULL
            GROUP BY q.parse_tier
            ORDER BY q.parse_tier";
    $stmt = @sqlsrv_query($conn_adl, $sql);
    if ($stmt) {
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $tier = (int)$row['parse_tier'];
            if (isset($liveData['parse_tiers'][$tier])) {
                $liveData['parse_tiers'][$tier] = (int)$row['cnt'];
            }
        }
        sqlsrv_free_stmt($stmt);
    }

    // -------------------------------------------------------------------------
    // Tier Tracking: Trajectory Tiers (0-7) for position logging frequency
    // -------------------------------------------------------------------------
    $liveData['trajectory_tiers'] = [0 => 0, 1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0, 6 => 0, 7 => 0];
    $sql = "SELECT last_trajectory_tier, COUNT(*) AS cnt
            FROM dbo.adl_flight_core
            WHERE is_active = 1 AND last_trajectory_tier IS NOT NULL
            GROUP BY last_trajectory_tier
            ORDER BY last_trajectory_tier";
    $stmt = @sqlsrv_query($conn_adl, $sql);
    if ($stmt) {
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $tier = (int)$row['last_trajectory_tier'];
            if (isset($liveData['trajectory_tiers'][$tier])) {
                $liveData['trajectory_tiers'][$tier] = (int)$row['cnt'];
            }
        }
        sqlsrv_free_stmt($stmt);
    }

    // -------------------------------------------------------------------------
    // Tier Tracking: Parse Queue by Parse Tier
    // -------------------------------------------------------------------------
    $liveData['queue_by_tier'] = [0 => 0, 1 => 0, 2 => 0, 3 => 0, 4 => 0];
    $sql = "SELECT parse_tier, COUNT(*) AS cnt
            FROM dbo.adl_parse_queue
            WHERE status = 'PENDING'
            GROUP BY parse_tier
            ORDER BY parse_tier";
    $stmt = @sqlsrv_query($conn_adl, $sql);
    if ($stmt) {
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $tier = (int)$row['parse_tier'];
            if (isset($liveData['queue_by_tier'][$tier])) {
                $liveData['queue_by_tier'][$tier] = (int)$row['cnt'];
            }
        }
        sqlsrv_free_stmt($stmt);
    }

    // -------------------------------------------------------------------------
    // Daily Stats: Routes Parsed in Last 24 Hours by Tier
    // Queue cleanup retains 24h for full daily tier breakdown
    // -------------------------------------------------------------------------
    $liveData['daily_parsed_by_tier'] = [0 => 0, 1 => 0, 2 => 0, 3 => 0, 4 => 0];
    $liveData['daily_parsed_total'] = 0;

    // Get tier breakdown from last 24 hours of completed queue entries
    $sql = "SELECT q.parse_tier, COUNT(*) AS cnt
            FROM dbo.adl_parse_queue q
            WHERE q.status = 'COMPLETE'
              AND q.completed_utc >= DATEADD(HOUR, -24, SYSUTCDATETIME())
            GROUP BY q.parse_tier
            ORDER BY q.parse_tier";
    $stmt = @sqlsrv_query($conn_adl, $sql);
    if ($stmt) {
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $tier = (int)$row['parse_tier'];
            $cnt = (int)$row['cnt'];
            if (isset($liveData['daily_parsed_by_tier'][$tier])) {
                $liveData['daily_parsed_by_tier'][$tier] = $cnt;
            }
            $liveData['daily_parsed_total'] += $cnt;
        }
        sqlsrv_free_stmt($stmt);
    }

    // -------------------------------------------------------------------------
    // Daily Stats: Trajectory Points Logged in Last 24 Hours by Tier
    // -------------------------------------------------------------------------
    $liveData['daily_trajectory_by_tier'] = [0 => 0, 1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0, 6 => 0, 7 => 0];
    $liveData['daily_trajectory_total'] = 0;
    $sql = "SELECT tier, COUNT(*) AS cnt
            FROM dbo.adl_flight_trajectory
            WHERE recorded_utc >= DATEADD(HOUR, -24, SYSUTCDATETIME())
            GROUP BY tier
            ORDER BY tier";
    $stmt = @sqlsrv_query($conn_adl, $sql);
    if ($stmt) {
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $tier = (int)$row['tier'];
            $cnt = (int)$row['cnt'];
            if (isset($liveData['daily_trajectory_by_tier'][$tier])) {
                $liveData['daily_trajectory_by_tier'][$tier] = $cnt;
            }
            $liveData['daily_trajectory_total'] += $cnt;
        }
        sqlsrv_free_stmt($stmt);
    }

    // -------------------------------------------------------------------------
    // Parse Success Rate (24h)
    // -------------------------------------------------------------------------
    $liveData['parse_success_24h'] = 0;
    $liveData['parse_failed_24h'] = 0;
    $liveData['parse_success_rate'] = 0;
    $sql = "SELECT
                COUNT(CASE WHEN status = 'COMPLETE' THEN 1 END) AS success_cnt,
                COUNT(CASE WHEN status = 'FAILED' THEN 1 END) AS failed_cnt
            FROM dbo.adl_parse_queue
            WHERE queued_utc > DATEADD(HOUR, -24, SYSUTCDATETIME())";
    $stmt = @sqlsrv_query($conn_adl, $sql);
    if ($stmt) {
        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        $liveData['parse_success_24h'] = (int)($row['success_cnt'] ?? 0);
        $liveData['parse_failed_24h'] = (int)($row['failed_cnt'] ?? 0);
        $total = $liveData['parse_success_24h'] + $liveData['parse_failed_24h'];
        if ($total > 0) {
            $liveData['parse_success_rate'] = round(($liveData['parse_success_24h'] / $total) * 100, 1);
        }
        sqlsrv_free_stmt($stmt);
    }

    // -------------------------------------------------------------------------
    // Top Active Airports (departures + arrivals for active flights)
    // -------------------------------------------------------------------------
    $liveData['top_airports'] = [];
    $sql = "SELECT TOP 5 airport, SUM(cnt) AS total
            FROM (
                SELECT fp.fp_dept_icao AS airport, COUNT(*) AS cnt
                FROM dbo.adl_flight_core c
                INNER JOIN dbo.adl_flight_plan fp ON c.flight_uid = fp.flight_uid
                WHERE c.is_active = 1 AND fp.fp_dept_icao IS NOT NULL
                GROUP BY fp.fp_dept_icao
                UNION ALL
                SELECT fp.fp_dest_icao AS airport, COUNT(*) AS cnt
                FROM dbo.adl_flight_core c
                INNER JOIN dbo.adl_flight_plan fp ON c.flight_uid = fp.flight_uid
                WHERE c.is_active = 1 AND fp.fp_dest_icao IS NOT NULL
                GROUP BY fp.fp_dest_icao
            ) combined
            GROUP BY airport
            ORDER BY total DESC";
    $stmt = @sqlsrv_query($conn_adl, $sql);
    if ($stmt) {
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $liveData['top_airports'][] = [
                'icao' => $row['airport'],
                'count' => (int)$row['total']
            ];
        }
        sqlsrv_free_stmt($stmt);
    }

    // -------------------------------------------------------------------------
    // Queue Age Breakdown
    // -------------------------------------------------------------------------
    $liveData['queue_age'] = ['under_1m' => 0, '1_to_5m' => 0, 'over_5m' => 0];
    $sql = "SELECT
                COUNT(CASE WHEN DATEDIFF(SECOND, queued_utc, SYSUTCDATETIME()) < 60 THEN 1 END) AS under_1m,
                COUNT(CASE WHEN DATEDIFF(SECOND, queued_utc, SYSUTCDATETIME()) BETWEEN 60 AND 300 THEN 1 END) AS m1_to_5,
                COUNT(CASE WHEN DATEDIFF(SECOND, queued_utc, SYSUTCDATETIME()) > 300 THEN 1 END) AS over_5m
            FROM dbo.adl_parse_queue
            WHERE status = 'PENDING'";
    $stmt = @sqlsrv_query($conn_adl, $sql);
    if ($stmt) {
        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        $liveData['queue_age']['under_1m'] = (int)($row['under_1m'] ?? 0);
        $liveData['queue_age']['1_to_5m'] = (int)($row['m1_to_5'] ?? 0);
        $liveData['queue_age']['over_5m'] = (int)($row['over_5m'] ?? 0);
        sqlsrv_free_stmt($stmt);
    }

    // -------------------------------------------------------------------------
    // SimBrief Stats
    // -------------------------------------------------------------------------
    $liveData['simbrief_active'] = 0;
    $liveData['simbrief_total_active'] = 0;
    $liveData['simbrief_rate'] = 0;
    $sql = "SELECT
                COUNT(CASE WHEN fp.is_simbrief = 1 THEN 1 END) AS simbrief_cnt,
                COUNT(*) AS total_cnt
            FROM dbo.adl_flight_core c
            LEFT JOIN dbo.adl_flight_plan fp ON c.flight_uid = fp.flight_uid
            WHERE c.is_active = 1";
    $stmt = @sqlsrv_query($conn_adl, $sql);
    if ($stmt) {
        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        $liveData['simbrief_active'] = (int)($row['simbrief_cnt'] ?? 0);
        $liveData['simbrief_total_active'] = (int)($row['total_cnt'] ?? 0);
        if ($liveData['simbrief_total_active'] > 0) {
            $liveData['simbrief_rate'] = round(($liveData['simbrief_active'] / $liveData['simbrief_total_active']) * 100, 1);
        }
        sqlsrv_free_stmt($stmt);
    }

    // SimBrief parse success comparison
    $liveData['simbrief_parse_success'] = 0;
    $liveData['manual_parse_success'] = 0;
    $sql = "SELECT
                fp.is_simbrief,
                COUNT(CASE WHEN pq.status = 'COMPLETE' THEN 1 END) * 100.0 / NULLIF(COUNT(*), 0) AS success_rate
            FROM dbo.adl_parse_queue pq
            INNER JOIN dbo.adl_flight_plan fp ON pq.flight_uid = fp.flight_uid
            WHERE pq.queued_utc > DATEADD(HOUR, -24, SYSUTCDATETIME())
              AND pq.status IN ('COMPLETE', 'FAILED')
            GROUP BY fp.is_simbrief";
    $stmt = @sqlsrv_query($conn_adl, $sql);
    if ($stmt) {
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            if ($row['is_simbrief'] == 1) {
                $liveData['simbrief_parse_success'] = round($row['success_rate'] ?? 0, 1);
            } else {
                $liveData['manual_parse_success'] = round($row['success_rate'] ?? 0, 1);
            }
        }
        sqlsrv_free_stmt($stmt);
    }

    // SimBrief today vs yesterday
    $liveData['simbrief_today'] = 0;
    $liveData['simbrief_yesterday'] = 0;
    $sql = "SELECT
                COUNT(CASE WHEN c.first_seen_utc >= CAST(SYSUTCDATETIME() AS DATE) THEN 1 END) AS today_cnt,
                COUNT(CASE WHEN c.first_seen_utc >= DATEADD(DAY, -1, CAST(SYSUTCDATETIME() AS DATE))
                           AND c.first_seen_utc < CAST(SYSUTCDATETIME() AS DATE) THEN 1 END) AS yesterday_cnt
            FROM dbo.adl_flight_core c
            INNER JOIN dbo.adl_flight_plan fp ON c.flight_uid = fp.flight_uid
            WHERE fp.is_simbrief = 1
              AND c.first_seen_utc >= DATEADD(DAY, -1, CAST(SYSUTCDATETIME() AS DATE))";
    $stmt = @sqlsrv_query($conn_adl, $sql);
    if ($stmt) {
        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        $liveData['simbrief_today'] = (int)($row['today_cnt'] ?? 0);
        $liveData['simbrief_yesterday'] = (int)($row['yesterday_cnt'] ?? 0);
        sqlsrv_free_stmt($stmt);
    }

    // -------------------------------------------------------------------------
    // Data Freshness Indicators
    // -------------------------------------------------------------------------
    $liveData['oldest_pending_queue'] = null;
    $sql = "SELECT TOP 1 DATEDIFF(SECOND, queued_utc, SYSUTCDATETIME()) AS age_seconds
            FROM dbo.adl_parse_queue
            WHERE status = 'PENDING'
            ORDER BY queued_utc ASC";
    $stmt = @sqlsrv_query($conn_adl, $sql);
    if ($stmt) {
        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        $liveData['oldest_pending_queue'] = $row['age_seconds'] ?? null;
        sqlsrv_free_stmt($stmt);
    }

    $liveData['last_trajectory_age'] = null;
    $sql = "SELECT TOP 1 DATEDIFF(SECOND, recorded_utc, SYSUTCDATETIME()) AS age_seconds
            FROM dbo.adl_flight_trajectory
            ORDER BY recorded_utc DESC";
    $stmt = @sqlsrv_query($conn_adl, $sql);
    if ($stmt) {
        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        $liveData['last_trajectory_age'] = $row['age_seconds'] ?? null;
        sqlsrv_free_stmt($stmt);
    }

    // -------------------------------------------------------------------------
    // Recent Parse Errors (last 5)
    // -------------------------------------------------------------------------
    $liveData['recent_errors'] = [];
    $sql = "SELECT TOP 5
                pq.flight_uid,
                c.callsign,
                pq.error_message,
                pq.completed_utc
            FROM dbo.adl_parse_queue pq
            LEFT JOIN dbo.adl_flight_core c ON pq.flight_uid = c.flight_uid
            WHERE pq.status = 'FAILED'
            ORDER BY pq.completed_utc DESC";
    $stmt = @sqlsrv_query($conn_adl, $sql);
    if ($stmt) {
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $dt = $row['completed_utc'];
            $timeStr = ($dt instanceof DateTimeInterface) ? $dt->format('H:i:s') : ($dt ? substr($dt, 11, 8) : '');
            $liveData['recent_errors'][] = [
                'callsign' => $row['callsign'] ?? 'Unknown',
                'error' => substr($row['error_message'] ?? 'No message', 0, 50),
                'time' => $timeStr
            ];
        }
        sqlsrv_free_stmt($stmt);
    }

    // -------------------------------------------------------------------------
    // Most Crossed Boundaries (last hour) - by type
    // -------------------------------------------------------------------------
    $liveData['top_boundaries'] = [];
    $liveData['top_boundaries_by_type'] = [
        'ARTCC' => [], 'TRACON' => [], 'SECTOR_HIGH' => [], 'SECTOR_LOW' => [], 'SECTOR_SUPERHIGH' => []
    ];

    // Get top 3 boundaries per type using ROW_NUMBER
    $sql = "WITH RankedBoundaries AS (
                SELECT
                    b.boundary_name,
                    b.boundary_type,
                    COUNT(*) AS crossing_cnt,
                    ROW_NUMBER() OVER (PARTITION BY b.boundary_type ORDER BY COUNT(*) DESC) AS rn
                FROM dbo.adl_flight_boundary_log bl
                INNER JOIN dbo.adl_boundary b ON bl.boundary_id = b.boundary_id
                WHERE bl.entry_time > DATEADD(HOUR, -1, SYSUTCDATETIME())
                GROUP BY b.boundary_name, b.boundary_type
            )
            SELECT boundary_name, boundary_type, crossing_cnt
            FROM RankedBoundaries
            WHERE rn <= 5
            ORDER BY boundary_type, crossing_cnt DESC";
    $stmt = @sqlsrv_query($conn_adl, $sql);
    if ($stmt) {
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $type = $row['boundary_type'];
            if (isset($liveData['top_boundaries_by_type'][$type])) {
                $liveData['top_boundaries_by_type'][$type][] = [
                    'name' => $row['boundary_name'],
                    'count' => (int)$row['crossing_cnt']
                ];
            }
            // Also keep overall top for summary
            $liveData['top_boundaries'][] = [
                'name' => $row['boundary_name'],
                'type' => $type,
                'count' => (int)$row['crossing_cnt']
            ];
        }
        sqlsrv_free_stmt($stmt);
        // Sort overall by count and keep top 5
        usort($liveData['top_boundaries'], fn($a, $b) => $b['count'] - $a['count']);
        $liveData['top_boundaries'] = array_slice($liveData['top_boundaries'], 0, 5);
    }

    // Boundary crossings by type totals (last hour)
    $liveData['boundary_by_type'] = ['ARTCC' => 0, 'TRACON' => 0, 'SECTOR_HIGH' => 0, 'SECTOR_LOW' => 0, 'SECTOR_SUPERHIGH' => 0];
    $sql = "SELECT b.boundary_type, COUNT(*) AS cnt
            FROM dbo.adl_flight_boundary_log bl
            INNER JOIN dbo.adl_boundary b ON bl.boundary_id = b.boundary_id
            WHERE bl.entry_time > DATEADD(HOUR, -1, SYSUTCDATETIME())
            GROUP BY b.boundary_type";
    $stmt = @sqlsrv_query($conn_adl, $sql);
    if ($stmt) {
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $type = $row['boundary_type'];
            if (isset($liveData['boundary_by_type'][$type])) {
                $liveData['boundary_by_type'][$type] = (int)$row['cnt'];
            }
        }
        sqlsrv_free_stmt($stmt);
    }

    // -------------------------------------------------------------------------
    // ATIS Stats
    // -------------------------------------------------------------------------
    // ATIS updates in last hour
    $sql = "SELECT COUNT(*) AS cnt FROM dbo.vatsim_atis WHERE fetched_utc > DATEADD(HOUR, -1, SYSUTCDATETIME())";
    $stmt = @sqlsrv_query($conn_adl, $sql);
    if ($stmt) {
        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        $liveData['atis_updates_1h'] = (int)($row['cnt'] ?? 0);
        sqlsrv_free_stmt($stmt);
    }

    // ATIS by parse status
    $sql = "SELECT parse_status, COUNT(*) AS cnt
            FROM dbo.vatsim_atis
            WHERE fetched_utc > DATEADD(DAY, -1, SYSUTCDATETIME())
            GROUP BY parse_status";
    $stmt = @sqlsrv_query($conn_adl, $sql);
    if ($stmt) {
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $status = strtolower($row['parse_status'] ?? '');
            if ($status === 'pending') $liveData['atis_pending'] = (int)$row['cnt'];
            elseif ($status === 'parsed') $liveData['atis_parsed'] = (int)$row['cnt'];
            elseif ($status === 'failed') $liveData['atis_failed'] = (int)$row['cnt'];
        }
        sqlsrv_free_stmt($stmt);
    }

    // ATIS today total
    $sql = "SELECT COUNT(*) AS cnt FROM dbo.vatsim_atis WHERE fetched_utc >= CAST(SYSUTCDATETIME() AS DATE)";
    $stmt = @sqlsrv_query($conn_adl, $sql);
    if ($stmt) {
        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        $liveData['atis_today'] = (int)($row['cnt'] ?? 0);
        sqlsrv_free_stmt($stmt);
    }

    // Active airports with current ATIS
    $sql = "SELECT COUNT(DISTINCT airport_icao) AS cnt
            FROM dbo.vatsim_atis
            WHERE fetched_utc > DATEADD(MINUTE, -30, SYSUTCDATETIME())";
    $stmt = @sqlsrv_query($conn_adl, $sql);
    if ($stmt) {
        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        $liveData['atis_airports_active'] = (int)($row['cnt'] ?? 0);
        sqlsrv_free_stmt($stmt);
    }

    // -------------------------------------------------------------------------
    // Peak Hour Heatmap Data (flights by hour, last 7 days)
    // -------------------------------------------------------------------------
    $liveData['peak_hours'] = [];
    $sql = "SELECT
                DATEPART(WEEKDAY, first_seen_utc) AS day_of_week,
                DATEPART(HOUR, first_seen_utc) AS hour_of_day,
                COUNT(*) AS flight_cnt
            FROM dbo.adl_flight_core
            WHERE first_seen_utc > DATEADD(DAY, -7, SYSUTCDATETIME())
            GROUP BY DATEPART(WEEKDAY, first_seen_utc), DATEPART(HOUR, first_seen_utc)
            ORDER BY day_of_week, hour_of_day";
    $stmt = @sqlsrv_query($conn_adl, $sql);
    if ($stmt) {
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $liveData['peak_hours'][] = [
                'day' => (int)$row['day_of_week'],
                'hour' => (int)$row['hour_of_day'],
                'count' => (int)$row['flight_cnt']
            ];
        }
        sqlsrv_free_stmt($stmt);
    }

} else {
    $statusIssues[] = 'ADL database connection unavailable';
    $overallStatus = 'degraded';
}

$runtimes['adl_queries'] = round((microtime(true) - $adlQueryStart) * 1000);

// -----------------------------------------------------------------------------
// External API Health Checks
// -----------------------------------------------------------------------------
$apiCheckStart = microtime(true);

function checkApiHealth($url, $timeout = 5) {
    $start = microtime(true);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => $timeout,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_USERAGENT => 'PERTI-StatusCheck/1.0'
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    $latency = round((microtime(true) - $start) * 1000);

    if ($error) {
        return ['status' => 'error', 'latency' => null, 'message' => $error];
    } elseif ($httpCode >= 200 && $httpCode < 400) {
        return ['status' => 'up', 'latency' => $latency, 'message' => "HTTP {$httpCode} ({$latency}ms)"];
    } else {
        return ['status' => 'error', 'latency' => $latency, 'message' => "HTTP {$httpCode}"];
    }
}

// VATSIM Data API
$apiHealth['vatsim'] = checkApiHealth('https://data.vatsim.net/v3/vatsim-data.json', 5);

// Aviation Weather API
$apiHealth['aviationweather'] = checkApiHealth('https://aviationweather.gov/api/data/airsigmet?format=json', 5);

// NOAA NOMADS (check availability page)
$apiHealth['noaa'] = checkApiHealth('https://nomads.ncep.noaa.gov/', 5);

$runtimes['api_checks'] = round((microtime(true) - $apiCheckStart) * 1000);

// Update overall status based on API health
foreach ($apiHealth as $api => $health) {
    if ($health['status'] === 'error') {
        $statusIssues[] = strtoupper($api) . ' API unreachable';
        if ($overallStatus === 'operational') {
            $overallStatus = 'degraded';
        }
    }
}

// Check for critical issues
if ($liveData['queue_pending'] > 1000) {
    $statusIssues[] = 'Parse queue backlog > 1000';
    $overallStatus = 'degraded';
}
if ($liveData['queue_failed_1h'] > 50) {
    $statusIssues[] = 'High parse failure rate';
    $overallStatus = 'degraded';
}

// Calculate total runtime
$runtimes['total'] = round((microtime(true) - $pageStartTime) * 1000);

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <?php $page_title = "PERTI Status"; include("load/header.php"); ?>

    <style>
        :root {
            --status-complete: #16c995;
            --status-running: #6a9bf4;
            --status-scheduled: #8e8e93;
            --status-warning: #ffb15c;
            --status-error: #f74f78;
            --status-modified: #766df4;
        }

        .status-page-header {
            background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%);
            padding: 100px 0 20px 0;
            border-bottom: 3px solid var(--status-complete);
        }

        .status-page-header.degraded {
            border-bottom-color: var(--status-warning);
        }

        .status-page-header.critical {
            border-bottom-color: var(--status-error);
        }

        .status-timestamp {
            font-family: 'Inconsolata', monospace;
            font-size: 0.9rem;
            color: #aaa;
        }

        .status-overall {
            display: inline-flex;
            align-items: center;
            padding: 4px 12px;
            border-radius: 4px;
            font-weight: 600;
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .status-overall.operational {
            background: rgba(22, 201, 149, 0.2);
            color: var(--status-complete);
            border: 1px solid var(--status-complete);
        }

        .status-overall.degraded {
            background: rgba(255, 177, 92, 0.2);
            color: var(--status-warning);
            border: 1px solid var(--status-warning);
        }

        .status-overall.critical {
            background: rgba(247, 79, 120, 0.2);
            color: var(--status-error);
            border: 1px solid var(--status-error);
        }

        .status-section {
            background: #fff;
            border-radius: 6px;
            box-shadow: 0 1px 4px rgba(0,0,0,0.06);
            margin-bottom: 12px;
            overflow: hidden;
        }

        .status-section-header {
            background: #37384e;
            color: #fff;
            padding: 8px 12px;
            font-weight: 600;
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        /* Category Section Headers */
        .category-header {
            background: linear-gradient(135deg, #1e3a5f 0%, #2d4a6f 100%);
            color: #fff;
            padding: 10px 16px;
            margin: 20px 0 12px 0;
            border-radius: 6px;
            font-weight: 700;
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 1px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            cursor: pointer;
            box-shadow: 0 2px 6px rgba(0,0,0,0.15);
        }
        .category-header:first-of-type { margin-top: 0; }
        .category-header i { margin-right: 10px; }
        .category-header .toggle-icon { transition: transform 0.2s; }
        .category-header.collapsed .toggle-icon { transform: rotate(-90deg); }
        .category-content { transition: max-height 0.3s ease-out; }
        .category-content.collapsed { display: none; }

        .status-section-header .cycle-badge {
            background: rgba(255,255,255,0.15);
            padding: 2px 8px;
            border-radius: 3px;
            font-size: 0.7rem;
            font-weight: 500;
        }

        .status-table {
            width: 100%;
            margin: 0;
            font-size: 0.8rem;
        }

        .status-table thead th {
            background: #f8f9fa;
            padding: 6px 10px;
            font-weight: 600;
            text-transform: uppercase;
            font-size: 0.65rem;
            letter-spacing: 0.5px;
            color: #666;
            border-bottom: 1px solid #dee2e6;
        }

        .status-table tbody td {
            padding: 6px 10px;
            border-bottom: 1px solid #eee;
            vertical-align: middle;
        }

        .status-table tbody tr:hover {
            background: #f8f9fa;
        }

        .status-table tbody tr:last-child td {
            border-bottom: none;
        }

        .component-name {
            font-family: 'Inconsolata', monospace;
            font-weight: 600;
            font-size: 0.8rem;
            color: #333;
        }

        .component-desc {
            font-size: 0.7rem;
            color: #666;
        }

        .status-badge {
            display: inline-block;
            padding: 2px 6px;
            border-radius: 3px;
            font-size: 0.65rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }

        .status-badge.complete, .status-badge.up { background: var(--status-complete); color: #fff; }
        .status-badge.running { background: var(--status-running); color: #fff; }
        .status-badge.scheduled { background: var(--status-scheduled); color: #fff; }
        .status-badge.warning { background: var(--status-warning); color: #333; }
        .status-badge.error, .status-badge.down { background: var(--status-error); color: #fff; }
        .status-badge.modified { background: var(--status-modified); color: #fff; }
        .status-badge.removed { background: #333; color: #fff; text-decoration: line-through; }
        .status-badge.pending { background: #e9ecef; color: #666; border: 1px dashed #aaa; }
        .status-badge.unknown { background: #6c757d; color: #fff; }

        .timing-info {
            font-family: 'Inconsolata', monospace;
            font-size: 0.8rem;
            color: #666;
        }

        .comment-text {
            font-size: 0.8rem;
            color: #888;
        }

        .comment-text.on-time { color: var(--status-complete); font-weight: 600; }
        .comment-text.delayed { color: var(--status-warning); font-weight: 600; }

        .metric-row {
            display: flex;
            gap: 10px;
            margin-bottom: 12px;
            flex-wrap: wrap;
        }

        .metric-card {
            flex: 1;
            min-width: 110px;
            background: #fff;
            border-radius: 6px;
            padding: 10px 8px;
            text-align: center;
            box-shadow: 0 1px 4px rgba(0,0,0,0.06);
            border-left: 3px solid var(--status-complete);
        }

        .metric-card.warning { border-left-color: var(--status-warning); }
        .metric-card.info { border-left-color: var(--status-running); }
        .metric-card.primary { border-left-color: var(--status-modified); }
        .metric-card.error { border-left-color: var(--status-error); }

        .metric-value {
            font-size: 1.5rem;
            font-weight: 700;
            color: #333;
            line-height: 1;
            font-family: 'Inconsolata', monospace;
        }

        .metric-label {
            font-size: 0.65rem;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            color: #888;
            margin-top: 4px;
        }

        .metric-sublabel {
            font-size: 0.6rem;
            color: #aaa;
            margin-top: 1px;
        }

        .legend-section {
            background: #f8f9fa;
            border-radius: 6px;
            padding: 10px 12px;
            margin-top: 12px;
        }

        .legend-title {
            font-weight: 600;
            font-size: 0.75rem;
            margin-bottom: 8px;
            color: #333;
        }

        .legend-items {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
        }

        .legend-item {
            display: flex;
            align-items: center;
            gap: 4px;
            font-size: 0.7rem;
            color: #666;
        }

        .refresh-note {
            font-size: 0.75rem;
            color: #888;
            font-style: italic;
        }

        .auto-refresh-indicator {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 0.8rem;
            color: #888;
        }

        .auto-refresh-indicator .dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: var(--status-complete);
            animation: pulse 2s infinite;
        }

        .auto-refresh-indicator.degraded .dot {
            background: var(--status-warning);
        }

        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.4; }
        }

        .subsection-title {
            font-size: 0.8rem;
            font-weight: 600;
            color: #666;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            padding: 8px 12px;
            background: #f0f0f0;
            border-bottom: 1px solid #ddd;
        }

        /* Resource Tree Styles */
        .resource-tree {
            padding: 10px;
            font-family: 'Inconsolata', monospace;
            font-size: 0.75rem;
            line-height: 1.4;
        }

        .tree-node {
            margin-left: 0;
        }

        .tree-node .tree-node {
            margin-left: 14px;
            border-left: 1px dashed #ccc;
            padding-left: 10px;
        }

        .tree-item {
            display: flex;
            align-items: center;
            gap: 6px;
            padding: 2px 0;
        }

        .tree-icon {
            width: 14px;
            text-align: center;
            color: #666;
            font-size: 0.7rem;
        }

        .tree-icon.folder { color: var(--status-warning); }
        .tree-icon.database { color: var(--status-running); }
        .tree-icon.api { color: var(--status-complete); }
        .tree-icon.daemon { color: var(--status-modified); }
        .tree-icon.file { color: #888; }

        .tree-label {
            flex: 1;
        }

        .tree-status {
            font-size: 0.6rem;
        }

        .issues-list {
            background: rgba(255, 177, 92, 0.1);
            border: 1px solid var(--status-warning);
            border-radius: 4px;
            padding: 10px 15px;
            margin-bottom: 20px;
        }

        .issues-list.critical {
            background: rgba(247, 79, 120, 0.1);
            border-color: var(--status-error);
        }

        .issues-list h6 {
            margin: 0 0 8px 0;
            font-size: 0.8rem;
            text-transform: uppercase;
            color: var(--status-warning);
        }

        .issues-list.critical h6 {
            color: var(--status-error);
        }

        .issues-list ul {
            margin: 0;
            padding-left: 20px;
            font-size: 0.85rem;
        }

        .latency-good { color: var(--status-complete); }
        .latency-ok { color: var(--status-warning); }
        .latency-bad { color: var(--status-error); }

        /* Data Pipeline Visualization */
        .pipeline-container {
            background: #fff;
            border-radius: 6px;
            padding: 12px;
            box-shadow: 0 1px 4px rgba(0,0,0,0.06);
            margin-bottom: 12px;
            overflow-x: auto;
        }

        .pipeline-flow {
            display: flex;
            align-items: center;
            justify-content: space-between;
            min-width: 700px;
            gap: 6px;
        }

        .pipeline-stage {
            flex: 1;
            text-align: center;
            padding: 10px 6px;
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            border-radius: 6px;
            border: 2px solid #dee2e6;
            position: relative;
        }

        .pipeline-stage.active {
            border-color: var(--status-complete);
            background: linear-gradient(135deg, rgba(22, 201, 149, 0.1) 0%, rgba(22, 201, 149, 0.05) 100%);
        }

        .pipeline-stage.processing {
            border-color: var(--status-running);
            background: linear-gradient(135deg, rgba(106, 155, 244, 0.1) 0%, rgba(106, 155, 244, 0.05) 100%);
        }

        .pipeline-stage-icon {
            font-size: 1.5rem;
            margin-bottom: 8px;
            color: #666;
        }

        .pipeline-stage.active .pipeline-stage-icon { color: var(--status-complete); }
        .pipeline-stage.processing .pipeline-stage-icon { color: var(--status-running); }

        .pipeline-stage-icon {
            font-size: 1.1rem;
            margin-bottom: 4px;
        }

        .pipeline-stage-name {
            font-weight: 600;
            font-size: 0.65rem;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            color: #333;
            margin-bottom: 2px;
        }

        .pipeline-stage-count {
            font-family: 'Inconsolata', monospace;
            font-size: 1.1rem;
            font-weight: 700;
            color: #333;
        }

        .pipeline-stage-label {
            font-size: 0.6rem;
            color: #888;
        }

        .pipeline-arrow {
            color: #ccc;
            font-size: 1rem;
            flex-shrink: 0;
        }

        /* Runtime badges */
        .runtime-badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 10px;
            font-size: 0.7rem;
            font-family: 'Inconsolata', monospace;
            background: #e9ecef;
            color: #666;
        }

        .runtime-badge.fast { background: rgba(22, 201, 149, 0.2); color: var(--status-complete); }
        .runtime-badge.medium { background: rgba(255, 177, 92, 0.2); color: #b87a00; }
        .runtime-badge.slow { background: rgba(247, 79, 120, 0.2); color: var(--status-error); }

        /* Chart container */
        .chart-container {
            background: #fff;
            border-radius: 6px;
            padding: 10px;
            box-shadow: 0 1px 4px rgba(0,0,0,0.06);
            margin-bottom: 12px;
            overflow: hidden;
        }

        .chart-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 6px;
        }

        .chart-title {
            font-weight: 600;
            font-size: 0.75rem;
            color: #333;
        }

        .chart-wrapper {
            position: relative;
            height: 100px;
            width: 100%;
        }

        .chart-canvas {
            position: absolute;
            top: 0;
            left: 0;
            width: 100% !important;
            height: 100% !important;
        }

        /* Data size formatting */
        .data-size {
            font-family: 'Inconsolata', monospace;
            font-size: 0.85rem;
        }

        .data-size-large {
            font-size: 1.1rem;
            font-weight: 600;
        }

        /* ADL Refresh Procedure Styles */
        .procedure-steps {
            padding: 0;
        }

        .procedure-step {
            display: flex;
            align-items: flex-start;
            padding: 6px 10px;
            border-bottom: 1px solid #eee;
            transition: background-color 0.2s;
        }

        .procedure-step:hover {
            background: #f8f9fa;
        }

        .procedure-step:last-child {
            border-bottom: none;
        }

        .procedure-step.sub-step {
            padding-left: 26px;
            background: #fafbfc;
        }

        .procedure-step.sub-step:hover {
            background: #f0f2f4;
        }

        .step-number {
            min-width: 28px;
            height: 18px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #37384e;
            color: #fff;
            border-radius: 3px;
            font-size: 0.6rem;
            font-weight: 700;
            font-family: 'Inconsolata', monospace;
            margin-right: 8px;
        }

        .procedure-step.sub-step .step-number {
            background: #6c757d;
            font-size: 0.55rem;
        }

        .step-content {
            flex: 1;
        }

        .step-name {
            font-weight: 600;
            font-size: 0.75rem;
            color: #333;
            margin-bottom: 1px;
        }

        .step-desc {
            font-size: 0.65rem;
            color: #888;
        }

        .step-output {
            font-family: 'Inconsolata', monospace;
            font-size: 0.6rem;
            color: var(--status-running);
            margin-top: 1px;
        }

        .step-category {
            display: inline-block;
            padding: 1px 4px;
            border-radius: 2px;
            font-size: 0.55rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.2px;
            margin-left: 6px;
        }

        .step-category.ingest { background: rgba(106, 155, 244, 0.2); color: #4a7fd4; }
        .step-category.core { background: rgba(118, 109, 244, 0.2); color: #5a51d4; }
        .step-category.route { background: rgba(255, 177, 92, 0.2); color: #b87a00; }
        .step-category.time { background: rgba(22, 201, 149, 0.2); color: #0f9d6e; }
        .step-category.detect { background: rgba(247, 79, 120, 0.2); color: #d43a5c; }
        .step-category.archive { background: rgba(142, 142, 147, 0.2); color: #666; }

        .step-metric {
            display: flex;
            align-items: center;
            gap: 4px;
            margin-left: auto;
            padding-left: 8px;
        }

        .step-metric-value {
            font-family: 'Inconsolata', monospace;
            font-size: 0.75rem;
            font-weight: 700;
            color: var(--status-complete);
            background: rgba(22, 201, 149, 0.1);
            padding: 1px 6px;
            border-radius: 3px;
            min-width: 40px;
            text-align: center;
        }

        .step-metric-value.zero {
            color: #aaa;
            background: #f0f0f0;
        }

        .step-metric-value.high {
            color: var(--status-running);
            background: rgba(106, 155, 244, 0.1);
        }

        .step-metric-label {
            font-size: 0.55rem;
            color: #888;
            text-transform: uppercase;
        }

        .procedure-header-stats {
            display: flex;
            gap: 10px;
            padding: 6px 10px;
            background: #f8f9fa;
            border-bottom: 1px solid #eee;
            font-size: 0.7rem;
        }

        .procedure-header-stat {
            display: flex;
            align-items: center;
            gap: 4px;
        }

        .procedure-header-stat .value {
            font-family: 'Inconsolata', monospace;
            font-weight: 700;
            color: var(--status-complete);
        }

        .procedure-header-stat .label {
            color: #666;
        }

        .refresh-pulse {
            width: 6px;
            height: 6px;
            background: var(--status-complete);
            border-radius: 50%;
            animation: pulse 2s infinite;
        }

        /* Tier Tracking Styles */
        .tier-tracking-container {
            padding: 10px;
        }

        .tier-tracking-container.collapsed {
            display: none;
        }

        .collapsible-header {
            cursor: pointer;
        }

        .collapsible-header:hover {
            background: #454660;
        }

        .tier-group-total {
            padding: 2px 8px;
        }

        .tier-section {
            margin-bottom: 10px;
        }

        .tier-section:last-child {
            margin-bottom: 0;
        }

        .tier-section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 6px 10px;
            background: #37384e;
            color: #fff;
            border-radius: 4px 4px 0 0;
            cursor: pointer;
            user-select: none;
        }

        .tier-section-header:hover {
            background: #454660;
        }

        .tier-section-header .section-title {
            font-weight: 600;
            font-size: 0.8rem;
        }

        .tier-section-header .section-toggle {
            transition: transform 0.2s ease;
        }

        .tier-section-header.collapsed .section-toggle {
            transform: rotate(-90deg);
        }

        .tier-section-content {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 8px;
            padding: 8px;
            background: #f8f9fa;
            border-radius: 0 0 4px 4px;
            border: 1px solid #e0e0e0;
            border-top: none;
            align-items: stretch;
        }

        .tier-section-content.collapsed {
            display: none;
        }

        @media (max-width: 1200px) {
            .tier-section-content {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (max-width: 768px) {
            .tier-section-content {
                grid-template-columns: 1fr;
            }
        }

        .tier-group {
            background: #fff;
            border-radius: 4px;
            padding: 8px;
            box-shadow: 0 1px 2px rgba(0,0,0,0.06);
            display: flex;
            flex-direction: column;
        }

        .tier-group.full-width {
            grid-column: 1 / -1;
        }

        .tier-group-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 6px;
            padding-bottom: 4px;
            border-bottom: 1px solid #e8e8e8;
        }

        .tier-group-header-left {
            display: flex;
            flex-direction: column;
            gap: 1px;
        }

        .tier-group-title {
            font-weight: 600;
            font-size: 0.75rem;
            color: #333;
        }

        .tier-group-desc {
            font-size: 0.65rem;
            color: #999;
        }

        .tier-group-total {
            font-family: 'Inconsolata', monospace;
            font-size: 0.9rem;
            font-weight: 700;
            color: var(--status-complete);
            background: rgba(22, 201, 149, 0.1);
            padding: 4px 10px;
            border-radius: 4px;
        }

        .tier-bars {
            display: flex;
            flex-direction: column;
            gap: 3px;
        }

        .tier-bar-row {
            display: grid;
            grid-template-columns: 120px 1fr 45px;
            align-items: center;
            gap: 6px;
        }

        .tier-label {
            font-size: 0.65rem;
            color: #555;
            font-family: 'Inconsolata', monospace;
        }

        .tier-bar-container {
            height: 12px;
            background: #e8e8e8;
            border-radius: 2px;
            overflow: hidden;
        }

        .tier-bar {
            height: 100%;
            border-radius: 2px;
            transition: width 0.3s ease;
            min-width: 2px;
        }

        /* Parse Tier Colors (cooler = higher priority) */
        .tier-bar.tier-0 { background: linear-gradient(90deg, #dc2626, #ef4444); }
        .tier-bar.tier-1 { background: linear-gradient(90deg, #ea580c, #f97316); }
        .tier-bar.tier-2 { background: linear-gradient(90deg, #ca8a04, #eab308); }
        .tier-bar.tier-3 { background: linear-gradient(90deg, #16a34a, #22c55e); }
        .tier-bar.tier-4 { background: linear-gradient(90deg, #0891b2, #06b6d4); }

        /* Trajectory Tier Colors (warmer = more frequent) */
        .tier-bar.traj-tier-0 { background: linear-gradient(90deg, #dc2626, #ef4444); }
        .tier-bar.traj-tier-1 { background: linear-gradient(90deg, #ea580c, #f97316); }
        .tier-bar.traj-tier-2 { background: linear-gradient(90deg, #ca8a04, #eab308); }
        .tier-bar.traj-tier-3 { background: linear-gradient(90deg, #65a30d, #84cc16); }
        .tier-bar.traj-tier-4 { background: linear-gradient(90deg, #16a34a, #22c55e); }
        .tier-bar.traj-tier-5 { background: linear-gradient(90deg, #0d9488, #14b8a6); }
        .tier-bar.traj-tier-6 { background: linear-gradient(90deg, #0891b2, #06b6d4); }
        .tier-bar.traj-tier-7 { background: linear-gradient(90deg, #6366f1, #818cf8); }

        /* Queue Tier Colors */
        .tier-bar.queue-tier-0 { background: linear-gradient(90deg, #dc2626, #ef4444); }
        .tier-bar.queue-tier-1 { background: linear-gradient(90deg, #ea580c, #f97316); }
        .tier-bar.queue-tier-2 { background: linear-gradient(90deg, #ca8a04, #eab308); }
        .tier-bar.queue-tier-3 { background: linear-gradient(90deg, #16a34a, #22c55e); }
        .tier-bar.queue-tier-4 { background: linear-gradient(90deg, #0891b2, #06b6d4); }

        .tier-count {
            font-family: 'Inconsolata', monospace;
            font-size: 0.7rem;
            font-weight: 600;
            text-align: right;
            color: #333;
        }

        /* ATIS Tier Info Grid */
        .tier-info-grid {
            display: grid;
            grid-template-columns: repeat(5, 1fr);
            gap: 6px;
        }

        @media (max-width: 900px) {
            .tier-info-grid {
                grid-template-columns: repeat(3, 1fr);
            }
        }

        .tier-info-item {
            display: flex;
            flex-direction: column;
            align-items: center;
            text-align: center;
            gap: 2px;
            padding: 6px 4px;
            background: #fff;
            border-radius: 4px;
            border-top: 3px solid #06b6d4;
        }

        .tier-info-item:nth-child(1) { border-top-color: #dc2626; }
        .tier-info-item:nth-child(2) { border-top-color: #f97316; }
        .tier-info-item:nth-child(3) { border-top-color: #eab308; }
        .tier-info-item:nth-child(4) { border-top-color: #22c55e; }
        .tier-info-item:nth-child(5) { border-top-color: #06b6d4; }

        .tier-info-tier {
            font-family: 'Inconsolata', monospace;
            font-size: 0.8rem;
            font-weight: 700;
            color: #333;
        }

        .tier-info-desc {
            font-size: 0.6rem;
            color: #888;
            line-height: 1.2;
        }

        .tier-info-interval {
            font-family: 'Inconsolata', monospace;
            font-size: 0.85rem;
            font-weight: 700;
            color: var(--status-complete);
        }

        /* Side-by-side comparison layout */
        .tier-comparison {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
        }

        .tier-comparison-side {
            padding: 10px;
            background: #f0f0f0;
            border-radius: 4px;
        }

        .tier-comparison-side .side-label {
            font-size: 0.7rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #666;
            margin-bottom: 8px;
            font-weight: 600;
        }

        @media (max-width: 600px) {
            .tier-comparison {
                grid-template-columns: 1fr;
            }
        }
    </style>

    <!-- Chart.js for live graphs -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chartjs-adapter-date-fns@3.0.0/dist/chartjs-adapter-date-fns.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-annotation@3.0.1/dist/chartjs-plugin-annotation.min.js"></script>
    <!-- Shared Phase Color Configuration -->
    <script src="assets/js/config/phase-colors.js"></script>
</head>
<body>
    <?php include('load/nav.php'); ?>

    <!-- Status Page Header -->
    <div class="status-page-header <?= $overallStatus ?>">
        <div class="container-fluid">
            <div class="d-flex justify-content-between align-items-center flex-wrap">
                <div>
                    <h4 class="text-white mb-1">
                        <i class="fas fa-server mr-2"></i>PERTI System Status
                    </h4>
                    <div class="status-timestamp">
                        <?= $current_time ?> UTC &mdash; Auto-refreshes every 60 seconds
                    </div>
                </div>
                <div class="d-flex align-items-center gap-3">
                    <?php if ($overallStatus === 'operational'): ?>
                        <span class="status-overall operational">
                            <i class="fas fa-check-circle mr-2"></i>All Systems Operational
                        </span>
                    <?php elseif ($overallStatus === 'degraded'): ?>
                        <span class="status-overall degraded">
                            <i class="fas fa-exclamation-triangle mr-2"></i>Degraded Performance
                        </span>
                    <?php else: ?>
                        <span class="status-overall critical">
                            <i class="fas fa-times-circle mr-2"></i>System Issues
                        </span>
                    <?php endif; ?>
                    <span class="auto-refresh-indicator <?= $overallStatus ?> ml-3">
                        <span class="dot"></span> Live
                    </span>
                </div>
            </div>
        </div>
    </div>

    <!-- Main Content -->
    <div class="container-fluid mt-4 mb-5">

        <?php if (!empty($statusIssues)): ?>
        <div class="issues-list <?= $overallStatus === 'critical' ? 'critical' : '' ?>">
            <h6><i class="fas fa-exclamation-triangle mr-1"></i> Active Issues</h6>
            <ul>
                <?php foreach ($statusIssues as $issue): ?>
                    <li><?= htmlspecialchars($issue) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>

        <!-- ============================================ -->
        <!-- CATEGORY: OVERVIEW & METRICS -->
        <!-- ============================================ -->
        <div class="category-header" id="cat-overview-header" onclick="toggleCategory('cat-overview')">
            <span><i class="fas fa-tachometer-alt"></i>Overview & Metrics</span>
            <i class="fas fa-chevron-down toggle-icon"></i>
        </div>
        <div class="category-content" id="cat-overview-content">

        <!-- Live Metrics Row -->
        <div class="metric-row">
            <div class="metric-card <?= $liveData['active_flights'] > 0 ? '' : 'warning' ?>">
                <div class="metric-value"><?= number_format($liveData['active_flights']) ?></div>
                <div class="metric-label">Active Flights</div>
                <div class="metric-sublabel">Currently tracked</div>
            </div>
            <div class="metric-card info">
                <div class="metric-value"><?= number_format($liveData['queue_pending']) ?></div>
                <div class="metric-label">Queue Pending</div>
                <div class="metric-sublabel"><?= $liveData['queue_processing'] ?> processing</div>
            </div>
            <div class="metric-card primary">
                <div class="metric-value"><?= number_format($liveData['queue_complete_1h']) ?></div>
                <div class="metric-label">Parsed (1h)</div>
                <div class="metric-sublabel">Avg <?= $liveData['avg_parse_ms'] ?>ms</div>
            </div>
            <div class="metric-card <?= $liveData['queue_failed_1h'] > 10 ? 'error' : '' ?>">
                <div class="metric-value"><?= number_format($liveData['queue_failed_1h']) ?></div>
                <div class="metric-label">Failed (1h)</div>
                <div class="metric-sublabel">Parse errors</div>
            </div>
            <div class="metric-card">
                <div class="metric-value"><?= number_format($liveData['trajectories_1h']) ?></div>
                <div class="metric-label">Trajectories (1h)</div>
                <div class="metric-sublabel">ETA calculations</div>
            </div>
            <div class="metric-card info">
                <div class="metric-value"><?= number_format($liveData['weather_alerts_active']) ?></div>
                <div class="metric-label">Weather Alerts</div>
                <div class="metric-sublabel">Active SIGMETs</div>
            </div>
        </div>

        <!-- Data Processing Pipeline -->
        <div class="pipeline-container">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h6 class="mb-0"><i class="fas fa-project-diagram mr-2"></i>Flight Data Processing Pipeline</h6>
                <div>
                    <span class="runtime-badge <?= $runtimes['total'] < 800 ? 'fast' : ($runtimes['total'] < 2000 ? 'medium' : 'slow') ?>">
                        Page: <?= $runtimes['total'] ?>ms
                    </span>
                    <span class="runtime-badge <?= $runtimes['adl_queries'] < 5000 ? 'fast' : ($runtimes['adl_queries'] < 10000 ? 'medium' : 'slow') ?>">
                        DB: <?= number_format($runtimes['adl_queries'] / 1000, 1) ?>s
                    </span>
                    <span class="runtime-badge <?= $runtimes['api_checks'] < 3000 ? 'fast' : ($runtimes['api_checks'] < 6000 ? 'medium' : 'slow') ?>">
                        APIs: <?= $runtimes['api_checks'] ?>ms
                    </span>
                </div>
            </div>
            <div class="pipeline-flow">
                <div class="pipeline-stage active">
                    <div class="pipeline-stage-icon"><i class="fas fa-plane"></i></div>
                    <div class="pipeline-stage-name">VATSIM Feed</div>
                    <div class="pipeline-stage-count"><?= number_format($liveData['total_flights_today']) ?></div>
                    <div class="pipeline-stage-label">flights today</div>
                </div>
                <div class="pipeline-arrow"><i class="fas fa-chevron-right"></i></div>
                <div class="pipeline-stage <?= $liveData['active_flights'] > 0 ? 'active' : '' ?>">
                    <div class="pipeline-stage-icon"><i class="fas fa-filter"></i></div>
                    <div class="pipeline-stage-name">Active Flights</div>
                    <div class="pipeline-stage-count"><?= number_format($liveData['active_flights']) ?></div>
                    <div class="pipeline-stage-label">currently tracked</div>
                </div>
                <div class="pipeline-arrow"><i class="fas fa-chevron-right"></i></div>
                <div class="pipeline-stage <?= $liveData['queue_processing'] > 0 ? 'processing' : ($liveData['queue_pending'] > 0 ? 'active' : '') ?>">
                    <div class="pipeline-stage-icon"><i class="fas fa-cogs"></i></div>
                    <div class="pipeline-stage-name">Parse Queue</div>
                    <div class="pipeline-stage-count"><?= number_format($liveData['queue_pending']) ?></div>
                    <div class="pipeline-stage-label"><?= $liveData['queue_processing'] ?> processing &bull; <?= $liveData['avg_parse_ms'] ?>ms avg</div>
                </div>
                <div class="pipeline-arrow"><i class="fas fa-chevron-right"></i></div>
                <div class="pipeline-stage active">
                    <div class="pipeline-stage-icon"><i class="fas fa-map-marker-alt"></i></div>
                    <div class="pipeline-stage-name">Waypoints</div>
                    <div class="pipeline-stage-count"><?= number_format($liveData['waypoints_total']) ?></div>
                    <div class="pipeline-stage-label">extracted points</div>
                </div>
                <div class="pipeline-arrow"><i class="fas fa-chevron-right"></i></div>
                <div class="pipeline-stage <?= $liveData['trajectories_1h'] > 0 ? 'active' : '' ?>">
                    <div class="pipeline-stage-icon"><i class="fas fa-route"></i></div>
                    <div class="pipeline-stage-name">Trajectories</div>
                    <div class="pipeline-stage-count"><?= number_format($liveData['trajectories_total']) ?></div>
                    <div class="pipeline-stage-label"><?= number_format($liveData['trajectories_1h']) ?> this hour</div>
                </div>
                <div class="pipeline-arrow"><i class="fas fa-chevron-right"></i></div>
                <div class="pipeline-stage <?= $liveData['zone_transitions_1h'] > 0 || $liveData['boundary_crossings_1h'] > 0 ? 'active' : '' ?>">
                    <div class="pipeline-stage-icon"><i class="fas fa-border-all"></i></div>
                    <div class="pipeline-stage-name">Detection</div>
                    <div class="pipeline-stage-count"><?= number_format($liveData['zone_transitions_1h'] + $liveData['boundary_crossings_1h']) ?></div>
                    <div class="pipeline-stage-label">events this hour</div>
                </div>
            </div>
        </div>

        <!-- 24-Hour Flight Phase Chart + Peak Hours Heatmap Row -->
        <div class="row mb-4">
            <div class="col-lg-7">
                <div class="chart-container">
                    <div class="chart-header">
                        <span class="chart-title"><i class="fas fa-plane mr-1"></i> Flight Activity (24 Hours)</span>
                        <span class="runtime-badge"><?= number_format($liveData['active_flights']) ?> active</span>
                        <label class="ml-3" style="font-size: 11px; cursor: pointer;">
                            <input type="checkbox" id="phaseChartLogScale" style="margin-right: 4px;"> Log Scale
                        </label>
                    </div>
                    <div class="chart-wrapper" style="height: 220px;">
                        <canvas id="phaseChart" class="chart-canvas"></canvas>
                    </div>
                    <!-- Collapsible 24-hour summary stats -->
                    <div class="mt-2">
                        <a data-toggle="collapse" href="#phaseSummaryStats" role="button" aria-expanded="false" aria-controls="phaseSummaryStats" style="font-size: 11px; color: #666;">
                            <i class="fas fa-chevron-down mr-1"></i> 24-Hour Summary Statistics
                        </a>
                        <div class="collapse" id="phaseSummaryStats">
                            <table class="table table-sm table-bordered mt-2" style="font-size: 10px;">
                                <thead class="thead-light">
                                    <tr>
                                        <th>Phase</th>
                                        <th>Min</th>
                                        <th>Max</th>
                                        <th>Avg</th>
                                        <th>Median</th>
                                    </tr>
                                </thead>
                                <tbody id="phaseSummaryBody">
                                    <tr><td colspan="5" class="text-center text-muted">Loading...</td></tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
            <!-- Peak Hours Heatmap -->
            <div class="col-lg-5">
                <div class="status-section h-100">
                    <div class="status-section-header">
                        <span><i class="fas fa-fire mr-2"></i>Peak Hours (7 Day Heatmap)</span>
                    </div>
                    <div style="padding: 8px; overflow-x: auto;">
                        <?php
                        // Build heatmap grid
                        $heatmap = [];
                        $maxCount = 1;
                        foreach ($liveData['peak_hours'] as $ph) {
                            $heatmap[$ph['day']][$ph['hour']] = $ph['count'];
                            $maxCount = max($maxCount, $ph['count']);
                        }
                        $days = ['', 'Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
                        // Calculate explicit thresholds for legend
                        $thresh25 = (int)floor($maxCount * 0.25);
                        $thresh50 = (int)floor($maxCount * 0.50);
                        $thresh75 = (int)floor($maxCount * 0.75);
                        ?>
                        <div style="display: grid; grid-template-columns: 30px repeat(24, 1fr); gap: 1px; font-size: 0.55rem;">
                            <div></div>
                            <?php for ($h = 0; $h < 24; $h++): ?>
                            <div style="text-align: center; color: #888; font-weight: <?= $h % 6 === 0 ? '700' : '400' ?>;"><?= sprintf('%02d', $h) ?></div>
                            <?php endfor; ?>
                            <?php for ($d = 1; $d <= 7; $d++): ?>
                            <div style="color: #666; font-weight: 600; line-height: 14px;"><?= $days[$d] ?></div>
                            <?php for ($h = 0; $h < 24; $h++):
                                $count = $heatmap[$d][$h] ?? 0;
                                // Color thresholds: 0=none, <25%=low, <50%=med, <75%=high, ≥75%=peak
                                $intensity = $maxCount > 0 ? $count / $maxCount : 0;
                                $bg = $intensity === 0 ? '#f0f0f0' :
                                      ($intensity < 0.25 ? '#c6f6d5' :
                                      ($intensity < 0.5 ? '#68d391' :
                                      ($intensity < 0.75 ? '#f6ad55' : '#fc8181')));
                            ?>
                            <div style="height: 14px; background: <?= $bg ?>; border-radius: 1px;" title="<?= $days[$d] ?> <?= sprintf('%02d', $h) ?>:00 - <?= $count ?> flights"></div>
                            <?php endfor; ?>
                            <?php endfor; ?>
                        </div>
                        <div class="d-flex justify-content-end mt-1" style="font-size: 0.55rem; color: #888;">
                            <span style="display: inline-block; width: 10px; height: 10px; background: #f0f0f0; margin-right: 2px;"></span>0
                            <span style="display: inline-block; width: 10px; height: 10px; background: #c6f6d5; margin: 0 2px 0 6px;"></span>Low (1-<?= $thresh25 ?>)
                            <span style="display: inline-block; width: 10px; height: 10px; background: #68d391; margin: 0 2px 0 6px;"></span>Med (<?= $thresh25+1 ?>-<?= $thresh50 ?>)
                            <span style="display: inline-block; width: 10px; height: 10px; background: #f6ad55; margin: 0 2px 0 6px;"></span>High (<?= $thresh50+1 ?>-<?= $thresh75 ?>)
                            <span style="display: inline-block; width: 10px; height: 10px; background: #fc8181; margin: 0 2px 0 6px;"></span>Peak (<?= $thresh75+1 ?>+)
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Live Charts Row -->
        <div class="row mb-4">
            <div class="col-md-4">
                <div class="chart-container">
                    <div class="chart-header">
                        <span class="chart-title"><i class="fas fa-chart-area mr-1"></i> Processing Rate</span>
                        <span class="runtime-badge"><?= number_format($liveData['queue_complete_1h']) ?>/hr</span>
                    </div>
                    <div class="chart-wrapper">
                        <canvas id="processingChart" class="chart-canvas"></canvas>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="chart-container">
                    <div class="chart-header">
                        <span class="chart-title"><i class="fas fa-clock mr-1"></i> API Latency</span>
                        <span class="runtime-badge">Live</span>
                    </div>
                    <div class="chart-wrapper">
                        <canvas id="latencyChart" class="chart-canvas"></canvas>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="chart-container">
                    <div class="chart-header">
                        <span class="chart-title"><i class="fas fa-database mr-1"></i> Data Sizes</span>
                    </div>
                    <div class="d-flex justify-content-around align-items-center" style="height: 120px;">
                        <div class="text-center">
                            <div class="data-size data-size-large"><?= number_format($liveData['queue_total']) ?></div>
                            <div class="metric-sublabel">Queue Records</div>
                        </div>
                        <div class="text-center">
                            <div class="data-size data-size-large"><?= number_format($liveData['waypoints_total']) ?></div>
                            <div class="metric-sublabel">Waypoints</div>
                        </div>
                        <div class="text-center">
                            <div class="data-size data-size-large"><?= number_format($liveData['boundaries_total']) ?></div>
                            <div class="metric-sublabel">Boundaries</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        </div><!-- End cat-overview-content -->

        <!-- ============================================ -->
        <!-- CATEGORY: INFRASTRUCTURE & HEALTH -->
        <!-- ============================================ -->
        <div class="category-header" id="cat-infra-header" onclick="toggleCategory('cat-infra')">
            <span><i class="fas fa-server"></i>Infrastructure & Health</span>
            <i class="fas fa-chevron-down toggle-icon"></i>
        </div>
        <div class="category-content" id="cat-infra-content">

        <div class="row">
            <div class="col-lg-4">

                <!-- Database Connections -->
                <div class="status-section">
                    <div class="status-section-header">
                        <span><i class="fas fa-database mr-2"></i>Database Connections</span>
                        <span class="cycle-badge">Live</span>
                    </div>
                    <table class="status-table">
                        <thead>
                            <tr>
                                <th>Database</th>
                                <th>Status</th>
                                <th>Info</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td>
                                    <div class="component-name">Azure SQL (ADL)</div>
                                    <div class="component-desc">VATSIM_ADL flight data</div>
                                </td>
                                <td>
                                    <?php if ($liveData['adl_connected']): ?>
                                        <span class="status-badge up">Connected</span>
                                    <?php else: ?>
                                        <span class="status-badge down">Offline</span>
                                    <?php endif; ?>
                                </td>
                                <td class="timing-info"><?= $liveData['last_vatsim_refresh'] ?? 'N/A' ?></td>
                            </tr>
                            <tr>
                                <td>
                                    <div class="component-name">MySQL (PERTI)</div>
                                    <div class="component-desc">Application database</div>
                                </td>
                                <td>
                                    <?php if ($liveData['mysql_connected']): ?>
                                        <span class="status-badge up">Connected</span>
                                    <?php else: ?>
                                        <span class="status-badge down">Offline</span>
                                    <?php endif; ?>
                                </td>
                                <td class="comment-text on-time">ON-TIME</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- External API Health -->
                <div class="status-section">
                    <div class="status-section-header">
                        <span><i class="fas fa-plug mr-2"></i>External APIs</span>
                        <span class="cycle-badge">Health Check</span>
                    </div>
                    <table class="status-table">
                        <thead>
                            <tr>
                                <th>Service</th>
                                <th>Status</th>
                                <th>Latency</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td>
                                    <div class="component-name">VATSIM Data API</div>
                                    <div class="component-desc">data.vatsim.net</div>
                                </td>
                                <td>
                                    <span class="status-badge <?= $apiHealth['vatsim']['status'] ?>">
                                        <?= strtoupper($apiHealth['vatsim']['status']) ?>
                                    </span>
                                </td>
                                <td class="timing-info <?= ($apiHealth['vatsim']['latency'] ?? 9999) < 300 ? 'latency-good' : (($apiHealth['vatsim']['latency'] ?? 9999) < 800 ? 'latency-ok' : 'latency-bad') ?>">
                                    <?= $apiHealth['vatsim']['latency'] ? $apiHealth['vatsim']['latency'] . 'ms' : 'N/A' ?>
                                </td>
                            </tr>
                            <tr>
                                <td>
                                    <div class="component-name">Aviation Weather</div>
                                    <div class="component-desc">aviationweather.gov</div>
                                </td>
                                <td>
                                    <span class="status-badge <?= $apiHealth['aviationweather']['status'] ?>">
                                        <?= strtoupper($apiHealth['aviationweather']['status']) ?>
                                    </span>
                                </td>
                                <td class="timing-info <?= ($apiHealth['aviationweather']['latency'] ?? 9999) < 300 ? 'latency-good' : (($apiHealth['aviationweather']['latency'] ?? 9999) < 800 ? 'latency-ok' : 'latency-bad') ?>">
                                    <?= $apiHealth['aviationweather']['latency'] ? $apiHealth['aviationweather']['latency'] . 'ms' : 'N/A' ?>
                                </td>
                            </tr>
                            <tr>
                                <td>
                                    <div class="component-name">NOAA NOMADS</div>
                                    <div class="component-desc">Wind data source</div>
                                </td>
                                <td>
                                    <span class="status-badge <?= $apiHealth['noaa']['status'] ?>">
                                        <?= strtoupper($apiHealth['noaa']['status']) ?>
                                    </span>
                                </td>
                                <td class="timing-info <?= ($apiHealth['noaa']['latency'] ?? 9999) < 300 ? 'latency-good' : (($apiHealth['noaa']['latency'] ?? 9999) < 800 ? 'latency-ok' : 'latency-bad') ?>">
                                    <?= $apiHealth['noaa']['latency'] ? $apiHealth['noaa']['latency'] . 'ms' : 'N/A' ?>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Recent Activity -->
                <div class="status-section">
                    <div class="status-section-header">
                        <span><i class="fas fa-chart-line mr-2"></i>Recent Activity (1h)</span>
                        <span class="cycle-badge">Metrics</span>
                    </div>
                    <table class="status-table">
                        <thead>
                            <tr>
                                <th>Metric</th>
                                <th>Count</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td class="component-name">Routes Parsed</td>
                                <td class="timing-info"><?= number_format($liveData['queue_complete_1h']) ?></td>
                                <td><span class="status-badge <?= $liveData['queue_complete_1h'] > 0 ? 'complete' : 'warning' ?>">
                                    <?= $liveData['queue_complete_1h'] > 0 ? 'Active' : 'Idle' ?>
                                </span></td>
                            </tr>
                            <tr>
                                <td class="component-name">Trajectory Logs</td>
                                <td class="timing-info"><?= number_format($liveData['trajectories_1h']) ?></td>
                                <td><span class="status-badge <?= $liveData['trajectories_1h'] > 0 ? 'complete' : 'scheduled' ?>">
                                    <?= $liveData['trajectories_1h'] > 0 ? 'Active' : 'Waiting' ?>
                                </span></td>
                            </tr>
                            <tr>
                                <td class="component-name">Zone Transitions</td>
                                <td class="timing-info"><?= number_format($liveData['zone_transitions_1h']) ?></td>
                                <td><span class="status-badge <?= $liveData['zone_transitions_1h'] > 0 ? 'complete' : 'scheduled' ?>">
                                    <?= $liveData['zone_transitions_1h'] > 0 ? 'Detected' : 'None' ?>
                                </span></td>
                            </tr>
                            <tr>
                                <td class="component-name">Boundary Detection <span style="font-size: 9px; color: #6366f1;">(BG)</span>
                                    <div style="font-size: 9px; color: #888;">A:<?= number_format($liveData['boundary_artcc_1h']) ?> T:<?= number_format($liveData['boundary_tracon_1h']) ?> | <?= number_format($liveData['boundary_pending']) ?> pending</div>
                                </td>
                                <td class="timing-info"><?= number_format($liveData['boundary_crossings_1h']) ?></td>
                                <td><span class="status-badge <?= $liveData['boundary_crossings_1h'] > 0 ? 'complete' : ($liveData['boundary_pending'] > 0 ? 'warning' : 'scheduled') ?>">
                                    <?= $liveData['boundary_crossings_1h'] > 0 ? 'Active' : ($liveData['boundary_pending'] > 0 ? 'Pending' : 'Idle') ?>
                                </span></td>
                            </tr>
                            <tr>
                                <td class="component-name">Planned Crossings <span style="font-size: 9px; color: #6366f1;">(BG)</span>
                                    <div style="font-size: 9px; color: #888;"><?= number_format($liveData['flights_with_crossings']) ?> flights | <?= number_format($liveData['crossings_pending']) ?> pending</div>
                                </td>
                                <td class="timing-info"><?= number_format($liveData['planned_crossings_1h']) ?></td>
                                <td><span class="status-badge <?= $liveData['planned_crossings_1h'] > 0 ? 'complete' : ($liveData['crossings_pending'] > 0 ? 'warning' : 'scheduled') ?>">
                                    <?= $liveData['planned_crossings_1h'] > 0 ? 'Active' : ($liveData['crossings_pending'] > 0 ? 'Pending' : 'Idle') ?>
                                </span></td>
                            </tr>
                            <tr>
                                <td class="component-name">Parse Failures</td>
                                <td class="timing-info"><?= number_format($liveData['queue_failed_1h']) ?></td>
                                <td><span class="status-badge <?= $liveData['queue_failed_1h'] == 0 ? 'complete' : ($liveData['queue_failed_1h'] < 10 ? 'warning' : 'error') ?>">
                                    <?= $liveData['queue_failed_1h'] == 0 ? 'None' : $liveData['queue_failed_1h'] . ' errors' ?>
                                </span></td>
                            </tr>
                        </tbody>
                    </table>
                </div>

            </div>

            <div class="col-lg-4">

                <!-- Resource Tree -->
                <div class="status-section">
                    <div class="status-section-header">
                        <span><i class="fas fa-sitemap mr-2"></i>Resource Tree</span>
                        <span class="cycle-badge">Architecture</span>
                    </div>
                    <div class="resource-tree">
                        <div class="tree-node">
                            <div class="tree-item">
                                <span class="tree-icon folder"><i class="fas fa-server"></i></span>
                                <span class="tree-label"><strong>PERTI System</strong></span>
                            </div>
                            <div class="tree-node">
                                <div class="tree-item">
                                    <span class="tree-icon database"><i class="fas fa-database"></i></span>
                                    <span class="tree-label">Azure SQL (VATSIM_ADL)</span>
                                    <span class="status-badge <?= $liveData['adl_connected'] ? 'up' : 'down' ?> tree-status"><?= $liveData['adl_connected'] ? 'UP' : 'DOWN' ?></span>
                                </div>
                                <div class="tree-node">
                                    <div class="tree-item">
                                        <span class="tree-icon file"><i class="fas fa-table"></i></span>
                                        <span class="tree-label">adl_flights (<?= number_format($liveData['active_flights']) ?>)</span>
                                    </div>
                                    <div class="tree-item">
                                        <span class="tree-icon file"><i class="fas fa-table"></i></span>
                                        <span class="tree-label">adl_parse_queue (<?= number_format($liveData['queue_pending']) ?> pending)</span>
                                    </div>
                                    <div class="tree-item">
                                        <span class="tree-icon file"><i class="fas fa-table"></i></span>
                                        <span class="tree-label">adl_trajectories</span>
                                    </div>
                                    <div class="tree-item">
                                        <span class="tree-icon file"><i class="fas fa-table"></i></span>
                                        <span class="tree-label">adl_weather_alerts (<?= $liveData['weather_alerts_active'] ?>)</span>
                                    </div>
                                </div>
                            </div>
                            <div class="tree-node">
                                <div class="tree-item">
                                    <span class="tree-icon database"><i class="fas fa-database"></i></span>
                                    <span class="tree-label">MySQL (PERTI)</span>
                                    <span class="status-badge <?= $liveData['mysql_connected'] ? 'up' : 'down' ?> tree-status"><?= $liveData['mysql_connected'] ? 'UP' : 'DOWN' ?></span>
                                </div>
                                <div class="tree-node">
                                    <div class="tree-item">
                                        <span class="tree-icon file"><i class="fas fa-table"></i></span>
                                        <span class="tree-label">plans, configs, users</span>
                                    </div>
                                    <div class="tree-item">
                                        <span class="tree-icon file"><i class="fas fa-table"></i></span>
                                        <span class="tree-label">ground_stops, gdp</span>
                                    </div>
                                </div>
                            </div>
                            <div class="tree-node">
                                <div class="tree-item">
                                    <span class="tree-icon daemon"><i class="fas fa-cogs"></i></span>
                                    <span class="tree-label">Daemons</span>
                                </div>
                                <div class="tree-node">
                                    <div class="tree-item">
                                        <span class="tree-icon file"><i class="fab fa-php"></i></span>
                                        <span class="tree-label">vatsim_adl_daemon.php</span>
                                        <span class="status-badge running tree-status">15s</span>
                                    </div>
                                    <div class="tree-item">
                                        <span class="tree-icon file"><i class="fab fa-python"></i></span>
                                        <span class="tree-label">atis_daemon.py</span>
                                        <span class="status-badge running tree-status">15s</span>
                                    </div>
                                    <div class="tree-item">
                                        <span class="tree-icon file"><i class="fab fa-php"></i></span>
                                        <span class="tree-label">parse_queue_daemon.php</span>
                                        <span class="status-badge running tree-status">5s</span>
                                    </div>
                                    <div class="tree-item">
                                        <span class="tree-icon file"><i class="fab fa-php"></i></span>
                                        <span class="tree-label">boundary_daemon.php</span>
                                        <span class="status-badge running tree-status">30s</span>
                                    </div>
                                    <div class="tree-item">
                                        <span class="tree-icon file"><i class="fab fa-php"></i></span>
                                        <span class="tree-label">import_weather_alerts.php</span>
                                        <span class="status-badge scheduled tree-status">5m</span>
                                    </div>
                                </div>
                            </div>
                            <div class="tree-node">
                                <div class="tree-item">
                                    <span class="tree-icon api"><i class="fas fa-plug"></i></span>
                                    <span class="tree-label">External APIs</span>
                                </div>
                                <div class="tree-node">
                                    <div class="tree-item">
                                        <span class="tree-icon file"><i class="fas fa-plane"></i></span>
                                        <span class="tree-label">VATSIM Data API</span>
                                        <span class="status-badge <?= $apiHealth['vatsim']['status'] ?> tree-status"><?= strtoupper($apiHealth['vatsim']['status']) ?></span>
                                    </div>
                                    <div class="tree-item">
                                        <span class="tree-icon file"><i class="fas fa-cloud"></i></span>
                                        <span class="tree-label">Aviation Weather</span>
                                        <span class="status-badge <?= $apiHealth['aviationweather']['status'] ?> tree-status"><?= strtoupper($apiHealth['aviationweather']['status']) ?></span>
                                    </div>
                                    <div class="tree-item">
                                        <span class="tree-icon file"><i class="fas fa-wind"></i></span>
                                        <span class="tree-label">NOAA NOMADS</span>
                                        <span class="status-badge <?= $apiHealth['noaa']['status'] ?> tree-status"><?= strtoupper($apiHealth['noaa']['status']) ?></span>
                                    </div>
                                </div>
                            </div>
                            <div class="tree-node">
                                <div class="tree-item">
                                    <span class="tree-icon folder"><i class="fas fa-code"></i></span>
                                    <span class="tree-label">Stored Procedures (22)</span>
                                </div>
                                <div class="tree-node">
                                    <div class="tree-item">
                                        <span class="tree-icon file"><i class="fas fa-file-code"></i></span>
                                        <span class="tree-label">sp_Parse* (5)</span>
                                    </div>
                                    <div class="tree-item">
                                        <span class="tree-icon file"><i class="fas fa-file-code"></i></span>
                                        <span class="tree-label">sp_Calculate* (4)</span>
                                    </div>
                                    <div class="tree-item">
                                        <span class="tree-icon file"><i class="fas fa-file-code"></i></span>
                                        <span class="tree-label">sp_Process* (5)</span>
                                    </div>
                                    <div class="tree-item">
                                        <span class="tree-icon file"><i class="fas fa-file-code"></i></span>
                                        <span class="tree-label">fn_* (8)</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

            </div>

            <div class="col-lg-4">

                <!-- Data Pipeline Status -->
                <div class="status-section">
                    <div class="status-section-header">
                        <span><i class="fas fa-stream mr-2"></i>Data Pipeline</span>
                        <span class="cycle-badge">Continuous</span>
                    </div>
                    <table class="status-table">
                        <thead>
                            <tr>
                                <th>Component</th>
                                <th>Interval</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td>
                                    <div class="component-name">ATIS Daemon</div>
                                    <div class="component-desc">Runway assignments</div>
                                </td>
                                <td class="timing-info">15s</td>
                                <td><span class="status-badge running">Running</span></td>
                            </tr>
                            <tr>
                                <td>
                                    <div class="component-name">Parse Queue</div>
                                    <div class="component-desc"><?= number_format($liveData['queue_pending']) ?> pending</div>
                                </td>
                                <td class="timing-info">5s</td>
                                <td><span class="status-badge <?= $liveData['queue_pending'] > 500 ? 'warning' : 'running' ?>">
                                    <?= $liveData['queue_pending'] > 500 ? 'Backlog' : 'Running' ?>
                                </span></td>
                            </tr>
                            <tr>
                                <td>
                                    <div class="component-name">Weather Import</div>
                                    <div class="component-desc"><?= $liveData['weather_alerts_active'] ?> active alerts</div>
                                </td>
                                <td class="timing-info">5m</td>
                                <td><span class="status-badge scheduled">Scheduled</span></td>
                            </tr>
                            <tr>
                                <td>
                                    <div class="component-name">Trajectory Batch</div>
                                    <div class="component-desc"><?= number_format($liveData['trajectories_1h']) ?>/hr</div>
                                </td>
                                <td class="timing-info">Continuous</td>
                                <td><span class="status-badge <?= $liveData['trajectories_1h'] > 0 ? 'complete' : 'scheduled' ?>">
                                    <?= $liveData['trajectories_1h'] > 0 ? 'Active' : 'Idle' ?>
                                </span></td>
                            </tr>
                            <tr>
                                <td>
                                    <div class="component-name">Zone Detection</div>
                                    <div class="component-desc"><?= number_format($liveData['zone_transitions_1h']) ?> transitions/hr</div>
                                </td>
                                <td class="timing-info">Continuous</td>
                                <td><span class="status-badge complete">Active</span></td>
                            </tr>
                            <tr>
                                <td>
                                    <div class="component-name">Boundary Detection <span style="font-size: 9px; color: #6366f1;">(boundary_daemon.php)</span></div>
                                    <div class="component-desc">
                                        ARTCC: <?= number_format($liveData['boundary_artcc_1h']) ?>/hr &bull;
                                        TRACON: <?= number_format($liveData['boundary_tracon_1h']) ?>/hr &bull;
                                        <?= number_format($liveData['flights_with_artcc']) ?> tracked
                                        <?php if ($liveData['boundary_pending'] > 0): ?>
                                            <span style="color: #f59e0b;">&bull; <?= number_format($liveData['boundary_pending']) ?> pending</span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td class="timing-info"><?= $liveData['last_boundary_detection'] ?? 'N/A' ?></td>
                                <td><span class="status-badge <?= $liveData['boundary_crossings_1h'] > 0 ? 'complete' : ($liveData['boundary_pending'] > 0 ? 'warning' : 'scheduled') ?>">
                                    <?= $liveData['boundary_crossings_1h'] > 0 ? 'Active' : ($liveData['boundary_pending'] > 0 ? 'Pending' : 'Idle') ?>
                                </span></td>
                            </tr>
                            <tr>
                                <td>
                                    <div class="component-name">Planned Crossings <span style="font-size: 9px; color: #6366f1;">(boundary_daemon.php)</span></div>
                                    <div class="component-desc">
                                        <?= number_format($liveData['planned_crossings_1h']) ?> calc/hr &bull;
                                        <?= number_format($liveData['flights_with_crossings']) ?> flights
                                        <?php if ($liveData['crossings_pending'] > 0): ?>
                                            <span style="color: #f59e0b;">&bull; <?= number_format($liveData['crossings_pending']) ?> pending</span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td class="timing-info"><?= $liveData['last_crossing_calc'] ?? 'N/A' ?></td>
                                <td><span class="status-badge <?= $liveData['planned_crossings_1h'] > 0 ? 'complete' : ($liveData['crossings_pending'] > 0 ? 'warning' : 'scheduled') ?>">
                                    <?= $liveData['planned_crossings_1h'] > 0 ? 'Active' : ($liveData['crossings_pending'] > 0 ? 'Pending' : 'Idle') ?>
                                </span></td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- ADL Refresh Procedure Steps -->
                <div class="status-section">
                    <div class="status-section-header">
                        <span><i class="fas fa-sync-alt mr-2"></i>ADL Refresh Procedure</span>
                        <span class="cycle-badge">V8.9.12 &bull; 15s Cycle + Daemon Background</span>
                    </div>
                    <!-- Live Stats Header -->
                    <div class="procedure-header-stats">
                        <div class="procedure-header-stat">
                            <span class="refresh-pulse"></span>
                            <span class="label">Last:</span>
                            <span class="value"><?= $liveData['last_refresh_utc'] ?? 'N/A' ?> UTC</span>
                        </div>
                        <div class="procedure-header-stat">
                            <span class="label">Active:</span>
                            <span class="value"><?= number_format($liveData['active_flights']) ?></span>
                        </div>
                        <div class="procedure-header-stat">
                            <span class="label">Queue:</span>
                            <span class="value"><?= number_format($liveData['queue_pending']) ?></span>
                        </div>
                    </div>
                    <div class="procedure-steps">
                        <!-- Step 1 -->
                        <div class="procedure-step">
                            <span class="step-number">1</span>
                            <div class="step-content">
                                <div class="step-name">Parse JSON into Temp Table <span class="step-category ingest">Ingest</span></div>
                                <div class="step-desc">Extract pilot/flight data from VATSIM JSON feed</div>
                            </div>
                            <div class="step-metric">
                                <span class="step-metric-value <?= $liveData['active_flights'] > 0 ? 'high' : 'zero' ?>"><?= number_format($liveData['active_flights']) ?></span>
                                <span class="step-metric-label">pilots</span>
                            </div>
                        </div>
                        <!-- Step 1b -->
                        <div class="procedure-step sub-step">
                            <span class="step-number">1b</span>
                            <div class="step-content">
                                <div class="step-name">Enrich with Airport Data</div>
                                <div class="step-desc">Add coordinates, ARTCC, TRACON, GCD calculations</div>
                            </div>
                        </div>
                        <!-- Step 2 -->
                        <div class="procedure-step">
                            <span class="step-number">2</span>
                            <div class="step-content">
                                <div class="step-name">Upsert adl_flight_core <span class="step-category core">Core</span></div>
                                <div class="step-desc">Insert new flights, update existing flight status/phase</div>
                            </div>
                            <div class="step-metric">
                                <span class="step-metric-value <?= ($liveData['new_flights_15m'] ?? 0) > 0 ? '' : 'zero' ?>"><?= number_format($liveData['new_flights_15m'] ?? 0) ?></span>
                                <span class="step-metric-label">new/15m</span>
                            </div>
                        </div>
                        <!-- Step 2a -->
                        <div class="procedure-step sub-step">
                            <span class="step-number">2a</span>
                            <div class="step-content">
                                <div class="step-name">Process VATSIM Prefiles</div>
                                <div class="step-desc">Handle prefiled flight plans (phase='prefile')</div>
                            </div>
                        </div>
                        <!-- Step 2b -->
                        <div class="procedure-step sub-step">
                            <span class="step-number">2b</span>
                            <div class="step-content">
                                <div class="step-name">Create adl_flight_times</div>
                                <div class="step-desc">Initialize time tracking for new flights</div>
                            </div>
                        </div>
                        <!-- Step 3 -->
                        <div class="procedure-step">
                            <span class="step-number">3</span>
                            <div class="step-content">
                                <div class="step-name">Upsert adl_flight_position <span class="step-category core">Core</span></div>
                                <div class="step-desc">Update lat/lon, altitude, groundspeed, heading</div>
                            </div>
                            <div class="step-metric">
                                <span class="step-metric-value <?= ($liveData['updated_flights_15m'] ?? 0) > 0 ? 'high' : 'zero' ?>"><?= number_format($liveData['updated_flights_15m'] ?? 0) ?></span>
                                <span class="step-metric-label">upd/15m</span>
                            </div>
                        </div>
                        <!-- Step 4 -->
                        <div class="procedure-step">
                            <span class="step-number">4</span>
                            <div class="step-content">
                                <div class="step-name">Detect Route Changes <span class="step-category route">Route</span></div>
                                <div class="step-desc">Hash route for change detection, upsert flight plans</div>
                            </div>
                            <div class="step-metric">
                                <span class="step-metric-value <?= ($liveData['routes_queued_15m'] ?? 0) > 0 ? '' : 'zero' ?>"><?= number_format($liveData['routes_queued_15m'] ?? 0) ?></span>
                                <span class="step-metric-label">queued/15m</span>
                            </div>
                        </div>
                        <!-- Step 4b -->
                        <div class="procedure-step sub-step">
                            <span class="step-number">4b</span>
                            <div class="step-content">
                                <div class="step-name">ETD/STD Calculation</div>
                                <div class="step-desc">Calculate estimated/scheduled departure times</div>
                            </div>
                            <div class="step-metric">
                                <span class="step-metric-value <?= ($liveData['etds_calculated_15m'] ?? 0) > 0 ? '' : 'zero' ?>"><?= number_format($liveData['etds_calculated_15m'] ?? 0) ?></span>
                                <span class="step-metric-label">etd/15m</span>
                            </div>
                        </div>
                        <!-- Step 4c -->
                        <div class="procedure-step sub-step">
                            <span class="step-number">4c</span>
                            <div class="step-content">
                                <div class="step-name">SimBrief/ICAO Parsing</div>
                                <div class="step-desc">Parse step climbs, cost index from flight plans</div>
                            </div>
                            <div class="step-metric">
                                <span class="step-metric-value <?= ($liveData['simbrief_flights'] ?? 0) > 0 ? '' : 'zero' ?>"><?= number_format($liveData['simbrief_flights'] ?? 0) ?></span>
                                <span class="step-metric-label">simbrief</span>
                            </div>
                        </div>
                        <!-- Step 5 -->
                        <div class="procedure-step">
                            <span class="step-number">5</span>
                            <div class="step-content">
                                <div class="step-name">Queue Routes for Parsing <span class="step-category route">Route</span></div>
                                <div class="step-desc">Add changed routes to parse queue with tier priority</div>
                            </div>
                            <div class="step-metric">
                                <span class="step-metric-value <?= $liveData['queue_pending'] > 0 ? '' : 'zero' ?>"><?= number_format($liveData['queue_pending']) ?></span>
                                <span class="step-metric-label">pending</span>
                            </div>
                        </div>
                        <!-- Step 5b -->
                        <div class="procedure-step sub-step">
                            <span class="step-number">5b</span>
                            <div class="step-content">
                                <div class="step-name">Route Distance Calculation</div>
                                <div class="step-desc">Calculate route_distance_nm using sp_RouteDistanceBatch</div>
                            </div>
                        </div>
                        <!-- Step 6 -->
                        <div class="procedure-step">
                            <span class="step-number">6</span>
                            <div class="step-content">
                                <div class="step-name">Upsert adl_flight_aircraft <span class="step-category core">Core</span></div>
                                <div class="step-desc">Update aircraft type, weight class, engine info</div>
                            </div>
                        </div>
                        <!-- Step 7 -->
                        <div class="procedure-step">
                            <span class="step-number">7</span>
                            <div class="step-content">
                                <div class="step-name">Mark Inactive Flights <span class="step-category core">Core</span></div>
                                <div class="step-desc">Flag flights not seen in 5+ minutes as inactive</div>
                            </div>
                            <div class="step-metric">
                                <span class="step-metric-value <?= ($liveData['inactive_flights_1h'] ?? 0) > 0 ? '' : 'zero' ?>"><?= number_format($liveData['inactive_flights_1h'] ?? 0) ?></span>
                                <span class="step-metric-label">marked/1h</span>
                            </div>
                        </div>
                        <!-- Step 8 -->
                        <div class="procedure-step">
                            <span class="step-number">8</span>
                            <div class="step-content">
                                <div class="step-name">Process Trajectory & ETA <span class="step-category time">Time</span></div>
                                <div class="step-desc">Calculate estimated arrival times from trajectory</div>
                            </div>
                            <div class="step-metric">
                                <span class="step-metric-value <?= ($liveData['etas_calculated_15m'] ?? 0) > 0 ? '' : 'zero' ?>"><?= number_format($liveData['etas_calculated_15m'] ?? 0) ?></span>
                                <span class="step-metric-label">eta/15m</span>
                            </div>
                        </div>
                        <!-- Step 8b -->
                        <div class="procedure-step sub-step">
                            <span class="step-number">8b</span>
                            <div class="step-content">
                                <div class="step-name">Update Arrival Buckets</div>
                                <div class="step-desc">Calculate 15-minute arrival time buckets</div>
                            </div>
                        </div>
                        <!-- Step 8c (DISABLED - moved to waypoint_eta_daemon.php) -->
                        <div class="procedure-step sub-step disabled" style="opacity: 0.6; border-left: 3px solid #6c757d;">
                            <span class="step-number" style="background: #6c757d;">8c</span>
                            <div class="step-content">
                                <div class="step-name">Waypoint ETA Calculation <span class="step-category" style="background: #6c757d;">DAEMON</span></div>
                                <div class="step-desc">Moved to waypoint_eta_daemon.php (15s tiered)</div>
                            </div>
                            <div class="step-metric">
                                <span class="step-metric-value <?= ($liveData['waypoint_etas_total'] ?? 0) > 0 ? 'high' : 'zero' ?>"><?= number_format($liveData['waypoint_etas_total'] ?? 0) ?></span>
                                <span class="step-metric-label">w/eta</span>
                            </div>
                        </div>
                        <!-- Step 8d -->
                        <div class="procedure-step sub-step">
                            <span class="step-number">8d</span>
                            <div class="step-content">
                                <div class="step-name">Batch ETA Calculation</div>
                                <div class="step-desc">Calculate arrival ETAs via sp_CalculateETABatch</div>
                            </div>
                        </div>
                        <!-- Step 9 -->
                        <div class="procedure-step">
                            <span class="step-number">9</span>
                            <div class="step-content">
                                <div class="step-name">Zone Detection (OOOI) <span class="step-category detect">Detect</span></div>
                                <div class="step-desc">Detect Out/Off/On/In state transitions</div>
                            </div>
                            <div class="step-metric">
                                <span class="step-metric-value <?= $liveData['zone_transitions_1h'] > 0 ? '' : 'zero' ?>"><?= number_format($liveData['zone_transitions_1h']) ?></span>
                                <span class="step-metric-label">trans/1h</span>
                            </div>
                        </div>
                        <!-- Step 10 (DISABLED - moved to boundary_daemon.php) -->
                        <div class="procedure-step disabled" style="opacity: 0.6; border-left: 3px solid #6c757d;">
                            <span class="step-number" style="background: #6c757d;">10</span>
                            <div class="step-content">
                                <div class="step-name">Boundary Detection <span class="step-category" style="background: #6c757d;">DAEMON</span></div>
                                <div class="step-desc">
                                    Moved to boundary_daemon.php (15s adaptive) &bull;
                                    A:<?= number_format($liveData['boundary_artcc_1h']) ?> T:<?= number_format($liveData['boundary_tracon_1h']) ?>/hr &bull;
                                    <?= number_format($liveData['flights_with_artcc']) ?> tracked
                                    <?php if ($liveData['boundary_pending'] > 0): ?><span style="color:#f59e0b;">&bull; <?= $liveData['boundary_pending'] ?> pending</span><?php endif; ?>
                                </div>
                            </div>
                            <div class="step-metric">
                                <span class="step-metric-value <?= $liveData['boundary_crossings_1h'] > 0 ? '' : 'zero' ?>"><?= number_format($liveData['boundary_crossings_1h']) ?></span>
                                <span class="step-metric-label">cross/1h</span>
                            </div>
                        </div>
                        <!-- Step 11 (DISABLED - moved to boundary_daemon.php) -->
                        <div class="procedure-step disabled" style="opacity: 0.6; border-left: 3px solid #6c757d;">
                            <span class="step-number" style="background: #6c757d;">11</span>
                            <div class="step-content">
                                <div class="step-name">Planned Crossings <span class="step-category" style="background: #6c757d;">DAEMON</span></div>
                                <div class="step-desc">
                                    Moved to boundary_daemon.php (tiered) &bull;
                                    <?= number_format($liveData['flights_with_crossings']) ?> flights &bull;
                                    Tiers: <?php
                                        $tierParts = [];
                                        foreach ($liveData['crossing_tiers'] as $t => $cnt) {
                                            if ($cnt > 0) $tierParts[] = "T{$t}:{$cnt}";
                                        }
                                        echo $tierParts ? implode(' ', $tierParts) : 'none';
                                    ?>
                                    <?php if ($liveData['crossings_pending'] > 0): ?><span style="color:#f59e0b;">&bull; <?= $liveData['crossings_pending'] ?> pending</span><?php endif; ?>
                                </div>
                            </div>
                            <div class="step-metric">
                                <span class="step-metric-value <?= ($liveData['planned_crossings_1h'] ?? 0) > 0 ? '' : 'zero' ?>"><?= number_format($liveData['planned_crossings_1h'] ?? 0) ?></span>
                                <span class="step-metric-label">calc/1h</span>
                            </div>
                        </div>
                        <!-- Step 12 -->
                        <div class="procedure-step">
                            <span class="step-number">12</span>
                            <div class="step-content">
                                <div class="step-name">Log Trajectory Positions <span class="step-category archive">Archive</span></div>
                                <div class="step-desc">Archive flight positions to trajectory history</div>
                            </div>
                            <div class="step-metric">
                                <span class="step-metric-value <?= $liveData['trajectories_1h'] > 0 ? 'high' : 'zero' ?>"><?= number_format($liveData['trajectories_1h']) ?></span>
                                <span class="step-metric-label">logged/1h</span>
                            </div>
                        </div>
                        <!-- Step 13 -->
                        <div class="procedure-step">
                            <span class="step-number">13</span>
                            <div class="step-content">
                                <div class="step-name">Capture Phase Snapshot <span class="step-category archive">Archive</span></div>
                                <div class="step-desc">Store flight phase counts for 24hr chart</div>
                            </div>
                            <div class="step-metric">
                                <span class="step-metric-value"><?= number_format($liveData['phase_snapshots_1h'] ?? 0) ?></span>
                                <span class="step-metric-label">snaps/1h</span>
                            </div>
                        </div>
                    </div>
                </div>

            </div>
        </div>

        </div><!-- End cat-infra-content -->

        <!-- ============================================ -->
        <!-- CATEGORY: DATA PROCESSING -->
        <!-- ============================================ -->
        <div class="category-header" id="cat-processing-header" onclick="toggleCategory('cat-processing')">
            <span><i class="fas fa-cogs"></i>Data Processing</span>
            <i class="fas fa-chevron-down toggle-icon"></i>
        </div>
        <div class="category-content" id="cat-processing-content">

        <!-- Processing Tier Tracking - Full Width Section -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="status-section">
                    <div class="status-section-header collapsible-header" onclick="toggleSection('tierTracking')">
                        <span><i class="fas fa-layer-group mr-2"></i>Processing Tier Tracking</span>
                        <span>
                            <span class="cycle-badge mr-2">Real-time + Daily</span>
                            <i class="fas fa-chevron-down section-toggle" id="tierTracking-toggle"></i>
                        </span>
                    </div>
                    <div class="tier-tracking-container" id="tierTracking-content">
                        <!-- Route Parsing Section -->
                        <div class="tier-section">
                            <div class="tier-section-header" onclick="toggleTierSection('routeParsing')">
                                <span class="section-title"><i class="fas fa-route mr-2"></i>Route Parsing</span>
                                <i class="fas fa-chevron-down section-toggle" id="routeParsing-toggle"></i>
                            </div>
                            <div class="tier-section-content" id="routeParsing-content">
                                <!-- Current Flight Distribution -->
                                <div class="tier-group">
                                    <div class="tier-group-header">
                                        <div class="tier-group-header-left">
                                            <span class="tier-group-title">Current Flights by Parse Tier</span>
                                            <span class="tier-group-desc">Active flights distribution</span>
                                        </div>
                                        <span class="tier-group-total"><?= number_format(array_sum($liveData['parse_tiers'] ?? [])) ?></span>
                                    </div>
                                    <div class="tier-bars">
                                        <?php
                                        $parseTiers = $liveData['parse_tiers'] ?? [0=>0,1=>0,2=>0,3=>0,4=>0];
                                        $parseMax = max(1, max($parseTiers));
                                        $parseTierLabels = [
                                            0 => 'T0: ASPM77 Deps',
                                            1 => 'T1: ASPM77 Arrs',
                                            2 => 'T2: CAN/MEX/CAR',
                                            3 => 'T3: Other Intl',
                                            4 => 'T4: Remote/Low Pri'
                                        ];
                                        foreach ($parseTiers as $tier => $count):
                                            $pct = ($count / $parseMax) * 100;
                                        ?>
                                        <div class="tier-bar-row">
                                            <span class="tier-label"><?= $parseTierLabels[$tier] ?? "Tier $tier" ?></span>
                                            <div class="tier-bar-container">
                                                <div class="tier-bar tier-<?= $tier ?>" style="width: <?= $pct ?>%"></div>
                                            </div>
                                            <span class="tier-count"><?= number_format($count) ?></span>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                                <!-- Daily Routes Parsed -->
                                <div class="tier-group">
                                    <div class="tier-group-header">
                                        <div class="tier-group-header-left">
                                            <span class="tier-group-title">Routes Parsed (24h)</span>
                                            <span class="tier-group-desc">Completed parses in last 24 hours</span>
                                        </div>
                                        <span class="tier-group-total"><?= number_format($liveData['daily_parsed_total'] ?? 0) ?></span>
                                    </div>
                                    <div class="tier-bars">
                                        <?php
                                        $dailyParsed = $liveData['daily_parsed_by_tier'] ?? [0=>0,1=>0,2=>0,3=>0,4=>0];
                                        $dailyMax = max(1, max($dailyParsed));
                                        foreach ($dailyParsed as $tier => $count):
                                            $pct = ($count / $dailyMax) * 100;
                                        ?>
                                        <div class="tier-bar-row">
                                            <span class="tier-label"><?= $parseTierLabels[$tier] ?? "Tier $tier" ?></span>
                                            <div class="tier-bar-container">
                                                <div class="tier-bar tier-<?= $tier ?>" style="width: <?= $pct ?>%"></div>
                                            </div>
                                            <span class="tier-count"><?= number_format($count) ?></span>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                                <!-- Pending Queue -->
                                <div class="tier-group">
                                    <div class="tier-group-header">
                                        <div class="tier-group-header-left">
                                            <span class="tier-group-title">Parse Queue (Pending)</span>
                                            <span class="tier-group-desc">Awaiting processing</span>
                                        </div>
                                        <span class="tier-group-total"><?= number_format(array_sum($liveData['queue_by_tier'] ?? [])) ?></span>
                                    </div>
                                    <div class="tier-bars">
                                        <?php
                                        $queueTiers = $liveData['queue_by_tier'] ?? [0=>0,1=>0,2=>0,3=>0,4=>0];
                                        $queueMax = max(1, max($queueTiers));
                                        $queueTierLabels = [
                                            0 => 'T0: Immediate',
                                            1 => 'T1: High',
                                            2 => 'T2: Normal',
                                            3 => 'T3: Low',
                                            4 => 'T4: Background'
                                        ];
                                        foreach ($queueTiers as $tier => $count):
                                            $pct = ($count / $queueMax) * 100;
                                        ?>
                                        <div class="tier-bar-row">
                                            <span class="tier-label"><?= $queueTierLabels[$tier] ?? "Tier $tier" ?></span>
                                            <div class="tier-bar-container">
                                                <div class="tier-bar queue-tier-<?= $tier ?>" style="width: <?= $pct ?>%"></div>
                                            </div>
                                            <span class="tier-count"><?= number_format($count) ?></span>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Trajectory Logging Section (collapsed by default) -->
                        <div class="tier-section">
                            <div class="tier-section-header collapsed" onclick="toggleTierSection('trajectoryLogging')">
                                <span class="section-title"><i class="fas fa-map-marker-alt mr-2"></i>Trajectory Logging</span>
                                <i class="fas fa-chevron-down section-toggle" id="trajectoryLogging-toggle" style="transform: rotate(-90deg)"></i>
                            </div>
                            <div class="tier-section-content collapsed" id="trajectoryLogging-content">
                                <!-- Current Flight Tiers -->
                                <div class="tier-group">
                                    <div class="tier-group-header">
                                        <div class="tier-group-header-left">
                                            <span class="tier-group-title">Current Flights by Logging Tier</span>
                                            <span class="tier-group-desc">Active flight logging frequency</span>
                                        </div>
                                        <span class="tier-group-total"><?= number_format(array_sum($liveData['trajectory_tiers'] ?? [])) ?></span>
                                    </div>
                                    <div class="tier-bars">
                                        <?php
                                        $trajTiers = $liveData['trajectory_tiers'] ?? [0=>0,1=>0,2=>0,3=>0,4=>0,5=>0,6=>0,7=>0];
                                        $trajMax = max(1, max($trajTiers));
                                        $trajTierLabels = [
                                            0 => 'T0: 15s (Terminal)',
                                            1 => 'T1: 30s (Climb/Desc)',
                                            2 => 'T2: 1m (Active Enrt)',
                                            3 => 'T3: 2m (Stable Enrt)',
                                            4 => 'T4: 3m (Oceanic)',
                                            5 => 'T5: 5m (Prefile)',
                                            6 => 'T6: 10m (Parked)',
                                            7 => 'T7: 15m (Inactive)'
                                        ];
                                        foreach ($trajTiers as $tier => $count):
                                            $pct = ($count / $trajMax) * 100;
                                        ?>
                                        <div class="tier-bar-row">
                                            <span class="tier-label"><?= $trajTierLabels[$tier] ?? "Tier $tier" ?></span>
                                            <div class="tier-bar-container">
                                                <div class="tier-bar traj-tier-<?= $tier ?>" style="width: <?= $pct ?>%"></div>
                                            </div>
                                            <span class="tier-count"><?= number_format($count) ?></span>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                                <!-- Daily Points Logged -->
                                <div class="tier-group">
                                    <div class="tier-group-header">
                                        <div class="tier-group-header-left">
                                            <span class="tier-group-title">Trajectory Points (24h)</span>
                                            <span class="tier-group-desc">Positions logged in last 24 hours</span>
                                        </div>
                                        <span class="tier-group-total"><?= number_format($liveData['daily_trajectory_total'] ?? 0) ?></span>
                                    </div>
                                    <div class="tier-bars">
                                        <?php
                                        $dailyTraj = $liveData['daily_trajectory_by_tier'] ?? [0=>0,1=>0,2=>0,3=>0,4=>0,5=>0,6=>0,7=>0];
                                        $dailyTrajMax = max(1, max($dailyTraj));
                                        foreach ($dailyTraj as $tier => $count):
                                            $pct = ($count / $dailyTrajMax) * 100;
                                        ?>
                                        <div class="tier-bar-row">
                                            <span class="tier-label"><?= $trajTierLabels[$tier] ?? "Tier $tier" ?></span>
                                            <div class="tier-bar-container">
                                                <div class="tier-bar traj-tier-<?= $tier ?>" style="width: <?= $pct ?>%"></div>
                                            </div>
                                            <span class="tier-count"><?= number_format($count) ?></span>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Background Processing Stats Section -->
                        <div class="tier-section">
                            <div class="tier-section-header collapsed" onclick="toggleTierSection('backgroundProcessing')">
                                <span class="section-title"><i class="fas fa-cogs mr-2"></i>Background Processing Stats</span>
                                <i class="fas fa-chevron-down section-toggle" id="backgroundProcessing-toggle" style="transform: rotate(-90deg)"></i>
                            </div>
                            <div class="tier-section-content collapsed" id="backgroundProcessing-content">
                                <!-- Boundary Detection Stats -->
                                <div class="tier-group">
                                    <div class="tier-group-header">
                                        <div class="tier-group-header-left">
                                            <span class="tier-group-title">Boundary Detection</span>
                                            <span class="tier-group-desc">ARTCC/TRACON boundary crossing detection</span>
                                        </div>
                                        <span class="tier-group-total"><?= number_format($liveData['boundary_crossings_24h'] ?? 0) ?> (24h)</span>
                                    </div>
                                    <div class="d-flex flex-wrap" style="gap: 8px; padding: 8px 0;">
                                        <div class="stat-box" style="flex: 1; min-width: 100px; background: #f8f9fa; border-radius: 4px; padding: 8px; text-align: center;">
                                            <div style="font-size: 18px; font-weight: 600; color: #333;"><?= number_format($liveData['flights_with_artcc'] ?? 0) ?></div>
                                            <div style="font-size: 10px; color: #666;">w/ ARTCC</div>
                                        </div>
                                        <div class="stat-box" style="flex: 1; min-width: 100px; background: #f8f9fa; border-radius: 4px; padding: 8px; text-align: center;">
                                            <div style="font-size: 18px; font-weight: 600; color: #333;"><?= number_format($liveData['flights_with_tracon'] ?? 0) ?></div>
                                            <div style="font-size: 10px; color: #666;">w/ TRACON</div>
                                        </div>
                                        <div class="stat-box" style="flex: 1; min-width: 100px; background: #f8f9fa; border-radius: 4px; padding: 8px; text-align: center;">
                                            <div style="font-size: 18px; font-weight: 600; color: #333;"><?= number_format($liveData['boundary_crossings_1h'] ?? 0) ?></div>
                                            <div style="font-size: 10px; color: #666;">crossings/hr</div>
                                        </div>
                                        <div class="stat-box" style="flex: 1; min-width: 100px; background: <?= ($liveData['boundary_pending'] ?? 0) > 0 ? '#fff3cd' : '#f8f9fa' ?>; border-radius: 4px; padding: 8px; text-align: center;">
                                            <div style="font-size: 18px; font-weight: 600; color: <?= ($liveData['boundary_pending'] ?? 0) > 0 ? '#856404' : '#333' ?>;"><?= number_format($liveData['boundary_pending'] ?? 0) ?></div>
                                            <div style="font-size: 10px; color: #666;">pending</div>
                                        </div>
                                    </div>
                                </div>
                                <!-- Planned Crossings Stats -->
                                <div class="tier-group">
                                    <div class="tier-group-header">
                                        <div class="tier-group-header-left">
                                            <span class="tier-group-title">Planned Crossings</span>
                                            <span class="tier-group-desc">Route-based FIR/boundary crossing predictions</span>
                                        </div>
                                        <span class="tier-group-total"><?= number_format($liveData['planned_crossings_24h'] ?? 0) ?> (24h)</span>
                                    </div>
                                    <div class="d-flex flex-wrap" style="gap: 8px; padding: 8px 0;">
                                        <div class="stat-box" style="flex: 1; min-width: 100px; background: #f8f9fa; border-radius: 4px; padding: 8px; text-align: center;">
                                            <div style="font-size: 18px; font-weight: 600; color: #333;"><?= number_format($liveData['flights_with_crossings'] ?? 0) ?></div>
                                            <div style="font-size: 10px; color: #666;">w/ crossings</div>
                                        </div>
                                        <div class="stat-box" style="flex: 1; min-width: 100px; background: #f8f9fa; border-radius: 4px; padding: 8px; text-align: center;">
                                            <div style="font-size: 18px; font-weight: 600; color: #333;"><?= number_format($liveData['planned_crossings_1h'] ?? 0) ?></div>
                                            <div style="font-size: 10px; color: #666;">calc/hr</div>
                                        </div>
                                        <div class="stat-box" style="flex: 1; min-width: 100px; background: <?= ($liveData['crossings_pending'] ?? 0) > 0 ? '#fff3cd' : '#f8f9fa' ?>; border-radius: 4px; padding: 8px; text-align: center;">
                                            <div style="font-size: 18px; font-weight: 600; color: <?= ($liveData['crossings_pending'] ?? 0) > 0 ? '#856404' : '#333' ?>;"><?= number_format($liveData['crossings_pending'] ?? 0) ?></div>
                                            <div style="font-size: 10px; color: #666;">pending</div>
                                        </div>
                                    </div>
                                    <div class="tier-bars mt-2">
                                        <?php
                                        $crossingTiers = $liveData['crossing_tiers'] ?? [1=>0,2=>0,3=>0,4=>0,5=>0,6=>0,7=>0];
                                        $crossingMax = max(1, max($crossingTiers));
                                        $crossingTierLabels = [
                                            1 => 'T1: High Priority',
                                            2 => 'T2: Active Enroute',
                                            3 => 'T3: Stable Enroute',
                                            4 => 'T4: Oceanic',
                                            5 => 'T5: Prefile',
                                            6 => 'T6: Parked',
                                            7 => 'T7: Background'
                                        ];
                                        foreach ($crossingTiers as $tier => $count):
                                            $pct = ($count / $crossingMax) * 100;
                                        ?>
                                        <div class="tier-bar-row">
                                            <span class="tier-label"><?= $crossingTierLabels[$tier] ?? "Tier $tier" ?></span>
                                            <div class="tier-bar-container">
                                                <div class="tier-bar tier-<?= min($tier, 4) ?>" style="width: <?= $pct ?>%"></div>
                                            </div>
                                            <span class="tier-count"><?= number_format($count) ?></span>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                                <!-- ETA Calculation Stats -->
                                <div class="tier-group">
                                    <div class="tier-group-header">
                                        <div class="tier-group-header-left">
                                            <span class="tier-group-title">ETA Calculation</span>
                                            <span class="tier-group-desc">Estimated time of arrival calculations</span>
                                        </div>
                                        <span class="tier-group-total"><?= number_format($liveData['etas_calculated_24h'] ?? 0) ?> (24h)</span>
                                    </div>
                                    <div class="d-flex flex-wrap" style="gap: 8px; padding: 8px 0;">
                                        <div class="stat-box" style="flex: 1; min-width: 100px; background: #f8f9fa; border-radius: 4px; padding: 8px; text-align: center;">
                                            <div style="font-size: 18px; font-weight: 600; color: #333;"><?= number_format($liveData['flights_with_eta'] ?? 0) ?></div>
                                            <div style="font-size: 10px; color: #666;">w/ ETA</div>
                                        </div>
                                        <div class="stat-box" style="flex: 1; min-width: 100px; background: #f8f9fa; border-radius: 4px; padding: 8px; text-align: center;">
                                            <div style="font-size: 18px; font-weight: 600; color: #333;"><?= number_format($liveData['etas_calculated_15m'] ?? 0) ?></div>
                                            <div style="font-size: 10px; color: #666;">calc/15m</div>
                                        </div>
                                        <div class="stat-box" style="flex: 1; min-width: 100px; background: #f8f9fa; border-radius: 4px; padding: 8px; text-align: center;">
                                            <div style="font-size: 18px; font-weight: 600; color: #333;"><?= number_format($liveData['waypoint_etas_total'] ?? 0) ?></div>
                                            <div style="font-size: 10px; color: #666;">waypoint ETAs</div>
                                        </div>
                                        <div class="stat-box" style="flex: 1; min-width: 100px; background: <?= ($liveData['etas_pending'] ?? 0) > 0 ? '#fff3cd' : '#f8f9fa' ?>; border-radius: 4px; padding: 8px; text-align: center;">
                                            <div style="font-size: 18px; font-weight: 600; color: <?= ($liveData['etas_pending'] ?? 0) > 0 ? '#856404' : '#333' ?>;"><?= number_format($liveData['etas_pending'] ?? 0) ?></div>
                                            <div style="font-size: 10px; color: #666;">pending</div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- ATIS Refresh Section (collapsed by default) -->
                        <div class="tier-section">
                            <div class="tier-section-header collapsed" onclick="toggleTierSection('atisRefresh')">
                                <span class="section-title"><i class="fas fa-broadcast-tower mr-2"></i>ATIS Processing</span>
                                <i class="fas fa-chevron-down section-toggle" id="atisRefresh-toggle" style="transform: rotate(-90deg)"></i>
                            </div>
                            <div class="tier-section-content collapsed" id="atisRefresh-content">
                                <!-- ATIS Live Stats -->
                                <div class="tier-group">
                                    <div class="tier-group-header">
                                        <div class="tier-group-header-left">
                                            <span class="tier-group-title">ATIS Stats (24h)</span>
                                            <span class="tier-group-desc">Broadcast parsing status</span>
                                        </div>
                                        <span class="tier-group-total"><?= number_format($liveData['atis_updates_1h']) ?>/hr</span>
                                    </div>
                                    <div class="tier-bars">
                                        <?php
                                        $atisTotal = $liveData['atis_parsed'] + $liveData['atis_pending'] + $liveData['atis_failed'];
                                        $atisMax = max(1, $atisTotal);
                                        ?>
                                        <div class="tier-bar-row">
                                            <span class="tier-label">Parsed</span>
                                            <div class="tier-bar-container">
                                                <div class="tier-bar tier-4" style="width: <?= round(($liveData['atis_parsed'] / $atisMax) * 100) ?>%;"></div>
                                            </div>
                                            <span class="tier-value"><?= number_format($liveData['atis_parsed']) ?></span>
                                        </div>
                                        <div class="tier-bar-row">
                                            <span class="tier-label">Pending</span>
                                            <div class="tier-bar-container">
                                                <div class="tier-bar tier-2" style="width: <?= round(($liveData['atis_pending'] / $atisMax) * 100) ?>%;"></div>
                                            </div>
                                            <span class="tier-value"><?= number_format($liveData['atis_pending']) ?></span>
                                        </div>
                                        <div class="tier-bar-row">
                                            <span class="tier-label">Failed</span>
                                            <div class="tier-bar-container">
                                                <div class="tier-bar tier-0" style="width: <?= round(($liveData['atis_failed'] / $atisMax) * 100) ?>%;"></div>
                                            </div>
                                            <span class="tier-value"><?= number_format($liveData['atis_failed']) ?></span>
                                        </div>
                                    </div>
                                    <div style="font-size: 0.65rem; color: #888; margin-top: 6px; display: flex; justify-content: space-between;">
                                        <span><i class="fas fa-calendar-day mr-1"></i>Today: <?= number_format($liveData['atis_today']) ?></span>
                                        <span><i class="fas fa-broadcast-tower mr-1"></i>Airports: <?= $liveData['atis_airports_active'] ?></span>
                                    </div>
                                </div>
                                <!-- ATIS Tier Info -->
                                <div class="tier-group" style="grid-column: span 2;">
                                    <div class="tier-group-header">
                                        <div class="tier-group-header-left">
                                            <span class="tier-group-title">Polling Tier Schedule</span>
                                            <span class="tier-group-desc">Airport ATIS fetch frequency by priority</span>
                                        </div>
                                    </div>
                                    <div class="tier-info-grid" style="grid-template-columns: repeat(5, 1fr);">
                                        <div class="tier-info-item">
                                            <span class="tier-info-tier">T0</span>
                                            <span class="tier-info-desc">METAR/Bad Wx</span>
                                            <span class="tier-info-interval">15s</span>
                                        </div>
                                        <div class="tier-info-item">
                                            <span class="tier-info-tier">T1</span>
                                            <span class="tier-info-desc">ASPM77</span>
                                            <span class="tier-info-interval">1m</span>
                                        </div>
                                        <div class="tier-info-item">
                                            <span class="tier-info-tier">T2</span>
                                            <span class="tier-info-desc">CAN/LAT/CAR</span>
                                            <span class="tier-info-interval">5m</span>
                                        </div>
                                        <div class="tier-info-item">
                                            <span class="tier-info-tier">T3</span>
                                            <span class="tier-info-desc">Other Apt</span>
                                            <span class="tier-info-interval">30m</span>
                                        </div>
                                        <div class="tier-info-item">
                                            <span class="tier-info-tier">T4</span>
                                            <span class="tier-info-desc">Clear Wx</span>
                                            <span class="tier-info-interval">60m</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        </div><!-- End cat-processing-content -->

        <!-- ============================================ -->
        <!-- CATEGORY: SYSTEM INTERNALS -->
        <!-- ============================================ -->
        <div class="category-header" id="cat-system-header" onclick="toggleCategory('cat-system')">
            <span><i class="fas fa-microchip"></i>System Internals</span>
            <i class="fas fa-chevron-down toggle-icon"></i>
        </div>
        <div class="category-content" id="cat-system-content">

        <!-- Bottom Info Row -->
        <div class="row">
            <div class="col-lg-4">
                <!-- Stored Procedures Summary -->
                <div class="status-section">
                    <div class="status-section-header">
                        <span><i class="fas fa-code mr-2"></i>Stored Procedures</span>
                        <span class="cycle-badge">22 Total</span>
                    </div>
                    <table class="status-table">
                        <thead>
                            <tr>
                                <th>Category</th>
                                <th>Count</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td class="component-name">Route Parsing</td>
                                <td class="timing-info">5</td>
                                <td><span class="status-badge complete">Deployed</span></td>
                            </tr>
                            <tr>
                                <td class="component-name">ETA & Trajectory</td>
                                <td class="timing-info">8</td>
                                <td><span class="status-badge complete">Deployed</span></td>
                            </tr>
                            <tr>
                                <td class="component-name">Zone Detection</td>
                                <td class="timing-info">5</td>
                                <td><span class="status-badge complete">Deployed</span></td>
                            </tr>
                            <tr>
                                <td class="component-name">Data Sync</td>
                                <td class="timing-info">3</td>
                                <td><span class="status-badge complete">Deployed</span></td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Migrations Summary -->
                <div class="status-section">
                    <div class="status-section-header">
                        <span><i class="fas fa-layer-group mr-2"></i>Migrations</span>
                        <span class="cycle-badge">75 ADL + 26 PERTI</span>
                    </div>
                    <table class="status-table">
                        <thead>
                            <tr>
                                <th>Database</th>
                                <th>Categories</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td class="component-name">ADL (Azure SQL)</td>
                                <td class="timing-info">10 categories</td>
                                <td><span class="status-badge complete">75 Deployed</span></td>
                            </tr>
                            <tr>
                                <td class="component-name">PERTI (MySQL)</td>
                                <td class="timing-info">8 categories</td>
                                <td><span class="status-badge complete">26 Deployed</span></td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- CI/CD Pipeline -->
                <div class="status-section">
                    <div class="status-section-header">
                        <span><i class="fas fa-rocket mr-2"></i>CI/CD Pipeline</span>
                        <span class="cycle-badge">Azure</span>
                    </div>
                    <table class="status-table">
                        <thead>
                            <tr>
                                <th>Stage</th>
                                <th>Target</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td class="component-name">Build</td>
                                <td class="timing-info">PHP 8.2</td>
                                <td><span class="status-badge complete">Active</span></td>
                            </tr>
                            <tr>
                                <td class="component-name">Deploy</td>
                                <td class="timing-info">vatcscc</td>
                                <td><span class="status-badge complete">Active</span></td>
                            </tr>
                        </tbody>
                    </table>
                </div>

            </div>
        </div>

        </div><!-- End cat-system-content -->

        <!-- ============================================ -->
        <!-- CATEGORY: ANALYTICS & STATS -->
        <!-- ============================================ -->
        <div class="category-header" id="cat-analytics-header" onclick="toggleCategory('cat-analytics')">
            <span><i class="fas fa-chart-bar"></i>Analytics & Stats</span>
            <i class="fas fa-chevron-down toggle-icon"></i>
        </div>
        <div class="category-content" id="cat-analytics-content">

        <!-- Additional Stats Row -->
        <div class="row mb-4">
            <!-- Parse Success Rate & Queue Health -->
            <div class="col-md-3">
                <div class="status-section">
                    <div class="status-section-header">
                        <span><i class="fas fa-check-circle mr-2"></i>Parse Health (24h)</span>
                    </div>
                    <div style="padding: 10px;">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <span style="font-size: 0.75rem; color: #666;">Success Rate</span>
                            <span class="step-metric-value <?= $liveData['parse_success_rate'] >= 95 ? '' : ($liveData['parse_success_rate'] >= 80 ? 'warning' : 'error') ?>" style="font-size: 1.1rem;">
                                <?= $liveData['parse_success_rate'] ?>%
                            </span>
                        </div>
                        <div class="tier-bar-container" style="height: 8px; margin-bottom: 8px;">
                            <div class="tier-bar tier-4" style="width: <?= min(100, $liveData['parse_success_rate']) ?>%;"></div>
                        </div>
                        <div class="d-flex justify-content-between" style="font-size: 0.65rem; color: #888;">
                            <span><i class="fas fa-check text-success mr-1"></i><?= number_format($liveData['parse_success_24h']) ?> OK</span>
                            <span><i class="fas fa-times text-danger mr-1"></i><?= number_format($liveData['parse_failed_24h']) ?> Failed</span>
                        </div>
                        <hr style="margin: 8px 0;">
                        <div style="font-size: 0.7rem; font-weight: 600; margin-bottom: 6px;">Queue Age</div>
                        <div class="d-flex justify-content-between" style="font-size: 0.65rem;">
                            <span class="text-success">&lt;1m: <?= $liveData['queue_age']['under_1m'] ?></span>
                            <span class="text-warning">1-5m: <?= $liveData['queue_age']['1_to_5m'] ?></span>
                            <span class="text-danger">&gt;5m: <?= $liveData['queue_age']['over_5m'] ?></span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Top Airports -->
            <div class="col-md-3">
                <div class="status-section">
                    <div class="status-section-header">
                        <span><i class="fas fa-plane-departure mr-2"></i>Top Airports</span>
                    </div>
                    <div style="padding: 8px;">
                        <?php if (!empty($liveData['top_airports'])): ?>
                            <?php foreach ($liveData['top_airports'] as $i => $apt): ?>
                            <div class="d-flex justify-content-between align-items-center" style="padding: 3px 0; <?= $i > 0 ? 'border-top: 1px solid #eee;' : '' ?>">
                                <span style="font-family: 'Inconsolata', monospace; font-size: 0.8rem; font-weight: 600;"><?= htmlspecialchars($apt['icao']) ?></span>
                                <span class="step-metric-value" style="font-size: 0.75rem; min-width: 35px;"><?= $apt['count'] ?></span>
                            </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="text-muted text-center" style="font-size: 0.75rem; padding: 10px;">No data</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- SimBrief Stats -->
            <div class="col-md-3">
                <div class="status-section">
                    <div class="status-section-header">
                        <span><i class="fas fa-clipboard-list mr-2"></i>SimBrief Stats</span>
                    </div>
                    <div style="padding: 10px;">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <span style="font-size: 0.7rem; color: #666;">Adoption Rate</span>
                            <span style="font-family: 'Inconsolata', monospace; font-size: 1rem; font-weight: 700; color: #06b6d4;">
                                <?= $liveData['simbrief_rate'] ?>%
                            </span>
                        </div>
                        <div style="font-size: 0.65rem; color: #888; margin-bottom: 6px;">
                            <?= number_format($liveData['simbrief_active']) ?> of <?= number_format($liveData['simbrief_total_active']) ?> active flights
                        </div>
                        <hr style="margin: 6px 0;">
                        <div style="font-size: 0.65rem; margin-bottom: 4px;">
                            <span style="color: #666;">Parse Success:</span>
                        </div>
                        <div class="d-flex justify-content-between" style="font-size: 0.7rem;">
                            <span><i class="fas fa-clipboard-check mr-1" style="color: #06b6d4;"></i>SimBrief: <strong><?= $liveData['simbrief_parse_success'] ?>%</strong></span>
                            <span><i class="fas fa-edit mr-1" style="color: #888;"></i>Manual: <strong><?= $liveData['manual_parse_success'] ?>%</strong></span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Data Freshness & Errors -->
            <div class="col-md-3">
                <div class="status-section">
                    <div class="status-section-header">
                        <span><i class="fas fa-clock mr-2"></i>Data Freshness</span>
                    </div>
                    <div style="padding: 8px;">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <span style="font-size: 0.7rem;">Oldest Queue Item</span>
                            <?php
                            $queueAge = $liveData['oldest_pending_queue'];
                            $queueAgeClass = $queueAge === null ? '' : ($queueAge < 60 ? 'text-success' : ($queueAge < 300 ? 'text-warning' : 'text-danger'));
                            $queueAgeStr = $queueAge === null ? 'Empty' : ($queueAge < 60 ? $queueAge . 's' : round($queueAge / 60, 1) . 'm');
                            ?>
                            <span class="<?= $queueAgeClass ?>" style="font-family: 'Inconsolata', monospace; font-weight: 600;"><?= $queueAgeStr ?></span>
                        </div>
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <span style="font-size: 0.7rem;">Last Trajectory</span>
                            <?php
                            $trajAge = $liveData['last_trajectory_age'];
                            $trajAgeClass = $trajAge === null ? 'text-muted' : ($trajAge < 30 ? 'text-success' : ($trajAge < 120 ? 'text-warning' : 'text-danger'));
                            $trajAgeStr = $trajAge === null ? 'N/A' : ($trajAge < 60 ? $trajAge . 's ago' : round($trajAge / 60, 1) . 'm ago');
                            ?>
                            <span class="<?= $trajAgeClass ?>" style="font-family: 'Inconsolata', monospace; font-weight: 600;"><?= $trajAgeStr ?></span>
                        </div>
                        <?php if (!empty($liveData['recent_errors'])): ?>
                        <hr style="margin: 6px 0;">
                        <div style="font-size: 0.65rem; color: #dc2626; font-weight: 600; margin-bottom: 4px;">Recent Errors</div>
                        <?php foreach (array_slice($liveData['recent_errors'], 0, 2) as $err): ?>
                        <div style="font-size: 0.6rem; color: #666; padding: 2px 0; border-bottom: 1px dotted #eee;">
                            <span style="font-weight: 600;"><?= htmlspecialchars($err['callsign']) ?></span>
                            <span style="color: #888;"><?= htmlspecialchars($err['time']) ?></span>
                        </div>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Top Boundaries by Type - Full Width Row -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="status-section">
                    <div class="status-section-header">
                        <span><i class="fas fa-border-all mr-2"></i>Top Boundaries by Type (1h)</span>
                        <span class="cycle-badge"><?= number_format(array_sum($liveData['boundary_by_type'])) ?> crossings</span>
                    </div>
                    <div style="padding: 10px;">
                        <?php
                        $typeLabels = ['ARTCC' => 'ARTCC', 'TRACON' => 'TRACON', 'SECTOR_HIGH' => 'High Sectors', 'SECTOR_LOW' => 'Low Sectors', 'SECTOR_SUPERHIGH' => 'SuperHigh'];
                        $typeColors = ['ARTCC' => '#3b82f6', 'TRACON' => '#8b5cf6', 'SECTOR_HIGH' => '#ef4444', 'SECTOR_LOW' => '#22c55e', 'SECTOR_SUPERHIGH' => '#f97316'];
                        ?>
                        <div style="display: grid; grid-template-columns: repeat(5, 1fr); gap: 12px;">
                            <?php foreach ($liveData['top_boundaries_by_type'] as $type => $boundaries):
                                $totalForType = $liveData['boundary_by_type'][$type] ?? 0;
                                $maxForType = !empty($boundaries) ? max(array_column($boundaries, 'count')) : 1;
                            ?>
                            <div style="border-left: 3px solid <?= $typeColors[$type] ?? '#888' ?>; padding-left: 8px; background: <?= $typeColors[$type] ?? '#888' ?>08; border-radius: 0 4px 4px 0;">
                                <div class="d-flex justify-content-between align-items-center" style="margin-bottom: 6px; padding: 4px 0; border-bottom: 1px solid <?= $typeColors[$type] ?? '#888' ?>30;">
                                    <span style="font-weight: 700; font-size: 0.75rem; color: <?= $typeColors[$type] ?? '#888' ?>;"><?= $typeLabels[$type] ?? $type ?></span>
                                    <span style="font-size: 0.7rem; font-weight: 600; background: <?= $typeColors[$type] ?? '#888' ?>; color: #fff; padding: 1px 6px; border-radius: 3px;"><?= number_format($totalForType) ?></span>
                                </div>
                                <?php if (!empty($boundaries)): ?>
                                    <?php foreach ($boundaries as $i => $boundary): ?>
                                    <div style="padding: 2px 0; font-size: 0.7rem;">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <span style="font-family: 'Inconsolata', monospace; color: #333; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; max-width: 85%;"><?= htmlspecialchars($boundary['name']) ?></span>
                                            <span style="font-weight: 600; color: #666;"><?= $boundary['count'] ?></span>
                                        </div>
                                        <div style="height: 2px; background: #e0e0e0; border-radius: 1px; margin-top: 1px;">
                                            <div style="height: 100%; background: <?= $typeColors[$type] ?? '#888' ?>; border-radius: 1px; width: <?= round(($boundary['count'] / $maxForType) * 100) ?>%;"></div>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <div style="font-size: 0.65rem; color: #999; text-align: center; padding: 10px 0;">No data</div>
                                <?php endif; ?>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        </div><!-- End cat-analytics-content -->

        <!-- Legend -->
        <div class="legend-section">
            <div class="legend-title">Status Legend</div>
            <div class="legend-items">
                <div class="legend-item">
                    <span class="status-badge up">UP</span>
                    <span>Connected / Healthy</span>
                </div>
                <div class="legend-item">
                    <span class="status-badge running">Running</span>
                    <span>Actively processing</span>
                </div>
                <div class="legend-item">
                    <span class="status-badge complete">Complete</span>
                    <span>Deployed / Finished</span>
                </div>
                <div class="legend-item">
                    <span class="status-badge scheduled">Scheduled</span>
                    <span>Waiting for cycle</span>
                </div>
                <div class="legend-item">
                    <span class="status-badge warning">Warning</span>
                    <span>Needs attention</span>
                </div>
                <div class="legend-item">
                    <span class="status-badge error">Error</span>
                    <span>Failed / Offline</span>
                </div>
            </div>
        </div>

        <div class="text-center mt-4">
            <p class="refresh-note">
                This page auto-refreshes every 60 seconds.
                Last refresh: <?= $current_time ?> UTC
                &mdash; <a href="javascript:location.reload()">Refresh now</a>
            </p>
            <p class="refresh-note">
                <a href="docs/STATUS.md" target="_blank"><i class="fas fa-file-alt mr-1"></i>View Full Documentation</a>
            </p>
        </div>

    </div>

    <?php include('load/footer.php'); ?>

    <script>
        // Toggle main section visibility
        // LocalStorage key for saving UI state
        const UI_STATE_KEY = 'perti_status_page_state';

        // Save current UI state to localStorage
        function saveUIState() {
            const state = {
                collapsedSections: [],
                collapsedTierSections: [],
                collapsedCategories: []
            };
            // Save main sections
            document.querySelectorAll('.tier-tracking-container').forEach(el => {
                const sectionId = el.id.replace('-content', '');
                if (el.classList.contains('collapsed')) {
                    state.collapsedSections.push(sectionId);
                }
            });
            // Save tier subsections
            document.querySelectorAll('.tier-section-content').forEach(el => {
                const sectionId = el.id.replace('-content', '');
                if (el.classList.contains('collapsed')) {
                    state.collapsedTierSections.push(sectionId);
                }
            });
            // Save category states
            document.querySelectorAll('.category-content').forEach(el => {
                const categoryId = el.id.replace('-content', '');
                if (el.classList.contains('collapsed')) {
                    state.collapsedCategories.push(categoryId);
                }
            });
            localStorage.setItem(UI_STATE_KEY, JSON.stringify(state));
        }

        // Restore UI state from localStorage
        function restoreUIState() {
            try {
                const saved = localStorage.getItem(UI_STATE_KEY);
                if (!saved) return;
                const state = JSON.parse(saved);

                // Restore main sections
                if (state.collapsedSections) {
                    state.collapsedSections.forEach(sectionId => {
                        const content = document.getElementById(sectionId + '-content');
                        const toggle = document.getElementById(sectionId + '-toggle');
                        if (content && !content.classList.contains('collapsed')) {
                            content.classList.add('collapsed');
                            if (toggle) toggle.style.transform = 'rotate(-90deg)';
                        }
                    });
                }
                // Restore tier subsections
                if (state.collapsedTierSections) {
                    state.collapsedTierSections.forEach(sectionId => {
                        const content = document.getElementById(sectionId + '-content');
                        const toggle = document.getElementById(sectionId + '-toggle');
                        const header = content?.previousElementSibling;
                        if (content && !content.classList.contains('collapsed')) {
                            content.classList.add('collapsed');
                            if (toggle) toggle.style.transform = 'rotate(-90deg)';
                            if (header) header.classList.add('collapsed');
                        }
                    });
                    // Also handle sections that should be expanded (were collapsed by default)
                    document.querySelectorAll('.tier-section-content').forEach(el => {
                        const sectionId = el.id.replace('-content', '');
                        if (!state.collapsedTierSections.includes(sectionId)) {
                            const toggle = document.getElementById(sectionId + '-toggle');
                            const header = el.previousElementSibling;
                            if (el.classList.contains('collapsed')) {
                                el.classList.remove('collapsed');
                                if (toggle) toggle.style.transform = 'rotate(0deg)';
                                if (header) header.classList.remove('collapsed');
                            }
                        }
                    });
                }
                // Restore category states
                if (state.collapsedCategories) {
                    state.collapsedCategories.forEach(categoryId => {
                        const content = document.getElementById(categoryId + '-content');
                        const header = document.getElementById(categoryId + '-header');
                        if (content && !content.classList.contains('collapsed')) {
                            content.classList.add('collapsed');
                            if (header) header.classList.add('collapsed');
                        }
                    });
                }
            } catch (e) {
                console.warn('Failed to restore UI state:', e);
            }
        }

        function toggleSection(sectionId) {
            const content = document.getElementById(sectionId + '-content');
            const toggle = document.getElementById(sectionId + '-toggle');
            if (content && toggle) {
                content.classList.toggle('collapsed');
                toggle.style.transform = content.classList.contains('collapsed') ? 'rotate(-90deg)' : 'rotate(0deg)';
                saveUIState();
            }
        }

        // Toggle category visibility
        function toggleCategory(categoryId) {
            const content = document.getElementById(categoryId + '-content');
            const header = document.getElementById(categoryId + '-header');
            if (content && header) {
                content.classList.toggle('collapsed');
                header.classList.toggle('collapsed');
                saveUIState();
            }
        }

        // Toggle tier subsection visibility
        function toggleTierSection(sectionId) {
            const content = document.getElementById(sectionId + '-content');
            const toggle = document.getElementById(sectionId + '-toggle');
            const header = content?.previousElementSibling;
            if (content && toggle) {
                content.classList.toggle('collapsed');
                toggle.style.transform = content.classList.contains('collapsed') ? 'rotate(-90deg)' : 'rotate(0deg)';
                if (header) header.classList.toggle('collapsed');
                saveUIState();
            }
        }

        // Restore UI state on page load
        document.addEventListener('DOMContentLoaded', restoreUIState);

        // Auto-refresh every 60 seconds
        setTimeout(function() {
            location.reload();
        }, 60000);

        $(document).ready(function() {
            $('[data-toggle="tooltip"]').tooltip();

            // Initialize Charts
            const chartDefaults = {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false }
                },
                scales: {
                    x: {
                        display: true,
                        grid: { display: false },
                        ticks: { font: { size: 10 } }
                    },
                    y: {
                        display: true,
                        grid: { color: '#f0f0f0' },
                        ticks: { font: { size: 10 } }
                    }
                }
            };

            // Processing Rate Chart (simulated historical data)
            const processingCtx = document.getElementById('processingChart');
            if (processingCtx) {
                new Chart(processingCtx, {
                    type: 'line',
                    data: {
                        labels: ['-60m', '-50m', '-40m', '-30m', '-20m', '-10m', 'Now'],
                        datasets: [{
                            label: 'Parsed',
                            data: [
                                Math.floor(<?= $liveData['queue_complete_1h'] ?> * 0.7),
                                Math.floor(<?= $liveData['queue_complete_1h'] ?> * 0.8),
                                Math.floor(<?= $liveData['queue_complete_1h'] ?> * 0.9),
                                Math.floor(<?= $liveData['queue_complete_1h'] ?> * 0.85),
                                Math.floor(<?= $liveData['queue_complete_1h'] ?> * 0.95),
                                Math.floor(<?= $liveData['queue_complete_1h'] ?> * 1.0),
                                <?= $liveData['queue_complete_1h'] ?>
                            ],
                            borderColor: '#16c995',
                            backgroundColor: 'rgba(22, 201, 149, 0.1)',
                            fill: true,
                            tension: 0.4,
                            pointRadius: 3
                        }]
                    },
                    options: chartDefaults
                });
            }

            // API Latency Chart
            const latencyCtx = document.getElementById('latencyChart');
            if (latencyCtx) {
                new Chart(latencyCtx, {
                    type: 'bar',
                    data: {
                        labels: ['VATSIM', 'AvWx', 'NOAA'],
                        datasets: [{
                            label: 'Latency (ms)',
                            data: [
                                <?= $apiHealth['vatsim']['latency'] ?? 0 ?>,
                                <?= $apiHealth['aviationweather']['latency'] ?? 0 ?>,
                                <?= $apiHealth['noaa']['latency'] ?? 0 ?>
                            ],
                            backgroundColor: [
                                '<?= ($apiHealth['vatsim']['latency'] ?? 999) < 500 ? '#16c995' : (($apiHealth['vatsim']['latency'] ?? 999) < 1500 ? '#ffb15c' : '#f74f78') ?>',
                                '<?= ($apiHealth['aviationweather']['latency'] ?? 999) < 500 ? '#16c995' : (($apiHealth['aviationweather']['latency'] ?? 999) < 1500 ? '#ffb15c' : '#f74f78') ?>',
                                '<?= ($apiHealth['noaa']['latency'] ?? 999) < 500 ? '#16c995' : (($apiHealth['noaa']['latency'] ?? 999) < 1500 ? '#ffb15c' : '#f74f78') ?>'
                            ],
                            borderRadius: 4
                        }]
                    },
                    options: {
                        ...chartDefaults,
                        scales: {
                            ...chartDefaults.scales,
                            y: {
                                ...chartDefaults.scales.y,
                                beginAtZero: true,
                                max: Math.max(<?= max($apiHealth['vatsim']['latency'] ?? 0, $apiHealth['aviationweather']['latency'] ?? 0, $apiHealth['noaa']['latency'] ?? 0) ?> * 1.2, 1000)
                            }
                        }
                    }
                });
            }

            // 24-Hour Flight Phase Chart (stacked area)
            const phaseCtx = document.getElementById('phaseChart');
            if (phaseCtx) {
                // Fetch data from API
                fetch('/api/stats/flight_phase_history.php?hours=24&interval=5')
                    .then(response => {
                        if (!response.ok) throw new Error('API returned ' + response.status);
                        return response.json();
                    })
                    .then(result => {
                        console.log('Phase API response:', result);
                        if (!result.success) {
                            console.error('API error:', result.error || 'Unknown error');
                            return;
                        }
                        if (!result.data || !result.data.labels || result.data.labels.length === 0) {
                            console.warn('No phase data available');
                            return;
                        }
                        try {
                            const data = result.data;
                            const currentTimeIso = result.current_time_iso;
                            const displayLabels = data.display_labels || [];

                            // Calculate fixed 24-hour time bounds for true proportional time axis
                            const now = new Date();
                            const timeMax = new Date(now.getTime());
                            const timeMin = new Date(now.getTime() - 24 * 60 * 60 * 1000);

                            // Convert data to {x, y} format for time axis
                            const makeTimeData = (values) => {
                                return data.labels.map((ts, i) => ({ x: ts, y: values[i] }));
                            };

                            // Calculate combined max for stacked chart
                            // Use same order as PHASE_STACK_ORDER from phase-colors.js (all phases stacked)
                            const stackedPhases = ['arrived', 'disconnected', 'descending', 'enroute', 'departed', 'taxiing', 'prefile', 'unknown'];
                            let rawMax = 0;
                            for (let i = 0; i < data.labels.length; i++) {
                                let stackSum = 0;
                                stackedPhases.forEach(phase => {
                                    if (data.datasets[phase] && data.datasets[phase][i]) {
                                        stackSum += data.datasets[phase][i];
                                    }
                                });
                                rawMax = Math.max(rawMax, stackSum);
                            }
                            // Round up to nice interval (500 for values < 5000, 1000 for larger)
                            const interval = rawMax < 5000 ? 500 : 1000;
                            const yMax = Math.ceil(rawMax * 1.05 / interval) * interval;

                            // Helper to convert hex color to rgba with alpha
                            const hexToRgba = (hex, alpha) => {
                                const r = parseInt(hex.slice(1, 3), 16);
                                const g = parseInt(hex.slice(3, 5), 16);
                                const b = parseInt(hex.slice(5, 7), 16);
                                return `rgba(${r}, ${g}, ${b}, ${alpha})`;
                            };

                            // Use shared PHASE_COLORS config (from phase-colors.js)
                            const colors = typeof PHASE_COLORS !== 'undefined' ? PHASE_COLORS : {
                                arrived: '#1a1a1a', disconnected: '#f97316', descending: '#991b1b',
                                enroute: '#dc2626', departed: '#f87171', taxiing: '#22c55e',
                                prefile: '#3b82f6', unknown: '#9333ea'
                            };
                            const labels = typeof PHASE_LABELS !== 'undefined' ? PHASE_LABELS : {
                                arrived: 'Arrived', disconnected: 'Disconnected', descending: 'Descending',
                                enroute: 'Enroute', departed: 'Departed', taxiing: 'Taxiing',
                                prefile: 'Prefile', unknown: 'Unknown'
                            };

                            window.phaseChartInstance = new Chart(phaseCtx, {
                                type: 'line',
                                data: {
                                    datasets: [
                                        // Stacked phases in order (bottom to top): arrived, disconnected, descending, enroute, departed, taxiing, prefile, unknown
                                        {
                                            label: labels.arrived,
                                            data: makeTimeData(data.datasets.arrived),
                                            borderColor: colors.arrived,
                                            backgroundColor: hexToRgba(colors.arrived, 0.8),
                                            fill: true,
                                            tension: 0.3,
                                            pointRadius: 0
                                        },
                                        {
                                            label: labels.disconnected,
                                            data: makeTimeData(data.datasets.disconnected || []),
                                            borderColor: colors.disconnected,
                                            backgroundColor: hexToRgba(colors.disconnected, 0.8),
                                            fill: true,
                                            tension: 0.3,
                                            pointRadius: 0
                                        },
                                        {
                                            label: labels.descending,
                                            data: makeTimeData(data.datasets.descending),
                                            borderColor: colors.descending,
                                            backgroundColor: hexToRgba(colors.descending, 0.8),
                                            fill: true,
                                            tension: 0.3,
                                            pointRadius: 0
                                        },
                                        {
                                            label: labels.enroute,
                                            data: makeTimeData(data.datasets.enroute),
                                            borderColor: colors.enroute,
                                            backgroundColor: hexToRgba(colors.enroute, 0.8),
                                            fill: true,
                                            tension: 0.3,
                                            pointRadius: 0
                                        },
                                        {
                                            label: labels.departed,
                                            data: makeTimeData(data.datasets.departed),
                                            borderColor: colors.departed,
                                            backgroundColor: hexToRgba(colors.departed, 0.8),
                                            fill: true,
                                            tension: 0.3,
                                            pointRadius: 0
                                        },
                                        {
                                            label: labels.taxiing,
                                            data: makeTimeData(data.datasets.taxiing),
                                            borderColor: colors.taxiing,
                                            backgroundColor: hexToRgba(colors.taxiing, 0.8),
                                            fill: true,
                                            tension: 0.3,
                                            pointRadius: 0
                                        },
                                        {
                                            label: labels.prefile,
                                            data: makeTimeData(data.datasets.prefile || []),
                                            borderColor: colors.prefile,
                                            backgroundColor: hexToRgba(colors.prefile, 0.8),
                                            fill: true,
                                            tension: 0.3,
                                            pointRadius: 0
                                        },
                                        {
                                            label: labels.unknown,
                                            data: makeTimeData(data.datasets.unknown || []),
                                            borderColor: colors.unknown,
                                            backgroundColor: hexToRgba(colors.unknown, 0.8),
                                            fill: true,
                                            tension: 0.3,
                                            pointRadius: 0
                                        }
                                    ]
                                },
                                options: {
                                    responsive: true,
                                    maintainAspectRatio: false,
                                    plugins: {
                                        legend: {
                                            display: true,
                                            position: 'bottom',
                                            labels: {
                                                boxWidth: 12,
                                                padding: 10,
                                                font: { size: 10 }
                                            }
                                        },
                                        tooltip: {
                                            mode: 'index',
                                            intersect: false,
                                            callbacks: {
                                                title: function(items) {
                                                    if (items.length > 0) {
                                                        const d = new Date(items[0].parsed.x);
                                                        const day = String(d.getUTCDate()).padStart(2, '0');
                                                        const hr = String(d.getUTCHours()).padStart(2, '0');
                                                        const mn = String(d.getUTCMinutes()).padStart(2, '0');
                                                        return day + '/' + hr + mn + 'Z';
                                                    }
                                                    return '';
                                                }
                                            }
                                        },
                                        annotation: {
                                            annotations: {
                                                currentTimeLine: {
                                                    type: 'line',
                                                    xMin: currentTimeIso,
                                                    xMax: currentTimeIso,
                                                    borderColor: '#000000',
                                                    borderWidth: 2,
                                                    borderDash: [5, 5],
                                                    label: {
                                                        display: true,
                                                        content: 'Now',
                                                        position: 'start',
                                                        backgroundColor: '#000000',
                                                        color: '#ffffff',
                                                        font: { size: 10 }
                                                    }
                                                }
                                            }
                                        }
                                    },
                                    scales: {
                                        x: {
                                            type: 'time',
                                            display: true,
                                            min: timeMin,
                                            max: timeMax,
                                            title: {
                                                display: true,
                                                text: 'Time (UTC)',
                                                font: { size: 12, weight: 'bold' }
                                            },
                                            time: {
                                                unit: 'hour',
                                                displayFormats: {
                                                    hour: 'dd/HH\'Z\'',
                                                    minute: 'dd/HHmm\'Z\''
                                                },
                                                tooltipFormat: 'dd/HHmm\'Z\''
                                            },
                                            grid: { display: false },
                                            ticks: {
                                                maxRotation: 45,
                                                minRotation: 45,
                                                autoSkip: true,
                                                maxTicksLimit: 25,
                                                font: { size: 10 },
                                                callback: function(value, index, ticks) {
                                                    const d = new Date(value);
                                                    const day = String(d.getUTCDate()).padStart(2, '0');
                                                    const hr = String(d.getUTCHours()).padStart(2, '0');
                                                    const mn = String(d.getUTCMinutes()).padStart(2, '0');
                                                    return day + '/' + hr + mn + 'Z';
                                                },
                                                color: function(context) {
                                                    const d = new Date(context.tick.value);
                                                    // Bold for 00Z and 12Z
                                                    if (d.getUTCMinutes() === 0 && (d.getUTCHours() === 0 || d.getUTCHours() === 12)) {
                                                        return '#000000';
                                                    }
                                                    return '#666666';
                                                },
                                                font: function(context) {
                                                    const d = new Date(context.tick.value);
                                                    if (d.getUTCMinutes() === 0 && (d.getUTCHours() === 0 || d.getUTCHours() === 12)) {
                                                        return { size: 11, weight: 'bold' };
                                                    }
                                                    return { size: 10 };
                                                }
                                            }
                                        },
                                        y: {
                                            display: true,
                                            stacked: true,
                                            position: 'left',
                                            title: {
                                                display: true,
                                                text: 'Active Flights',
                                                font: { size: 11, weight: 'bold' }
                                            },
                                            grid: { color: '#f0f0f0' },
                                            ticks: { font: { size: 10 } },
                                            min: 0,
                                            max: yMax
                                        }
                                    },
                                    interaction: {
                                        mode: 'nearest',
                                        axis: 'x',
                                        intersect: false
                                    }
                                }
                            });

                            // Populate summary statistics table using shared PHASE_COLORS
                            if (result.summary) {
                                const s = result.summary;
                                // Use shared colors/labels, with fallback
                                const c = typeof PHASE_COLORS !== 'undefined' ? PHASE_COLORS : colors;
                                const l = typeof PHASE_LABELS !== 'undefined' ? PHASE_LABELS : labels;
                                const phases = [
                                    { key: 'total_active', label: 'Total Active', color: '#333' },
                                    { key: 'prefile', label: l.prefile, color: c.prefile },
                                    { key: 'taxiing', label: l.taxiing, color: c.taxiing },
                                    { key: 'departed', label: l.departed, color: c.departed },
                                    { key: 'enroute', label: l.enroute, color: c.enroute },
                                    { key: 'descending', label: l.descending, color: c.descending },
                                    { key: 'disconnected', label: l.disconnected, color: c.disconnected },
                                    { key: 'arrived', label: l.arrived, color: c.arrived },
                                    { key: 'unknown', label: l.unknown, color: c.unknown }
                                ];
                                let tableHtml = '';
                                phases.forEach(p => {
                                    const stats = s[p.key] || {};
                                    // Skip phases with no data
                                    if (p.key !== 'total_active' && !stats.max && !stats.avg) return;
                                    tableHtml += `<tr>
                                        <td style="color: ${p.color}; font-weight: bold;">${p.label}</td>
                                        <td>${stats.min || 0}</td>
                                        <td>${stats.max || 0}</td>
                                        <td>${stats.avg || 0}</td>
                                        <td>${stats.median || 0}</td>
                                    </tr>`;
                                });
                                document.getElementById('phaseSummaryBody').innerHTML = tableHtml;
                            }

                            // Log scale toggle
                            const logToggle = document.getElementById('phaseChartLogScale');
                            if (logToggle) {
                                logToggle.addEventListener('change', function() {
                                    if (!window.phaseChartInstance) return;
                                    const isLog = this.checked;
                                    window.phaseChartInstance.options.scales.y.type = isLog ? 'logarithmic' : 'linear';
                                    // Set min to 1 for log scale (can't have 0), 0 for linear
                                    window.phaseChartInstance.options.scales.y.min = isLog ? 1 : 0;
                                    // Keep max the same (yMax was computed at creation)
                                    window.phaseChartInstance.update();
                                });
                            }
                        } catch (chartErr) {
                            console.error('Chart creation error:', chartErr);
                        }
                    })
                    .catch(err => console.error('Failed to load phase history:', err));
            }
        });
    </script>
</body>
</html>
