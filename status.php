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
        $cid = strip_tags($_SESSION['VATSIM_CID']);
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
    'boundary_crossings_1h' => 0,
    'weather_alerts_active' => 0,
    'atis_updates_1h' => 0,
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

    // Boundary crossings (last hour) and total boundaries
    $sql = "SELECT COUNT(*) AS cnt FROM dbo.adl_flight_boundary_log WHERE entry_time > DATEADD(HOUR, -1, SYSUTCDATETIME())";
    $stmt = @sqlsrv_query($conn_adl, $sql);
    if ($stmt) {
        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        $liveData['boundary_crossings_1h'] = $row['cnt'] ?? 0;
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
    // -------------------------------------------------------------------------
    $liveData['parse_tiers'] = [0 => 0, 1 => 0, 2 => 0, 3 => 0, 4 => 0];
    $sql = "SELECT parse_tier, COUNT(*) AS cnt
            FROM dbo.adl_flight_plan fp
            INNER JOIN dbo.adl_flight_core c ON fp.flight_uid = c.flight_uid
            WHERE c.is_active = 1 AND fp.parse_tier IS NOT NULL
            GROUP BY parse_tier
            ORDER BY parse_tier";
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
    // Tier Tracking: Parse Queue by Priority Tier
    // -------------------------------------------------------------------------
    $liveData['queue_by_tier'] = [0 => 0, 1 => 0, 2 => 0, 3 => 0, 4 => 0];
    $sql = "SELECT priority_tier, COUNT(*) AS cnt
            FROM dbo.adl_parse_queue
            WHERE status = 'PENDING'
            GROUP BY priority_tier
            ORDER BY priority_tier";
    $stmt = @sqlsrv_query($conn_adl, $sql);
    if ($stmt) {
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $tier = (int)$row['priority_tier'];
            if (isset($liveData['queue_by_tier'][$tier])) {
                $liveData['queue_by_tier'][$tier] = (int)$row['cnt'];
            }
        }
        sqlsrv_free_stmt($stmt);
    }

    // -------------------------------------------------------------------------
    // Daily Stats: Routes Parsed Today by Tier
    // -------------------------------------------------------------------------
    $liveData['daily_parsed_by_tier'] = [0 => 0, 1 => 0, 2 => 0, 3 => 0, 4 => 0];
    $liveData['daily_parsed_total'] = 0;
    $sql = "SELECT priority_tier, COUNT(*) AS cnt
            FROM dbo.adl_parse_queue
            WHERE status = 'COMPLETE'
              AND completed_utc >= CAST(SYSUTCDATETIME() AS DATE)
            GROUP BY priority_tier
            ORDER BY priority_tier";
    $stmt = @sqlsrv_query($conn_adl, $sql);
    if ($stmt) {
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $tier = (int)$row['priority_tier'];
            $cnt = (int)$row['cnt'];
            if (isset($liveData['daily_parsed_by_tier'][$tier])) {
                $liveData['daily_parsed_by_tier'][$tier] = $cnt;
            }
            $liveData['daily_parsed_total'] += $cnt;
        }
        sqlsrv_free_stmt($stmt);
    }

    // -------------------------------------------------------------------------
    // Daily Stats: Trajectory Points Logged Today by Tier
    // -------------------------------------------------------------------------
    $liveData['daily_trajectory_by_tier'] = [0 => 0, 1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0, 6 => 0, 7 => 0];
    $liveData['daily_trajectory_total'] = 0;
    $sql = "SELECT trajectory_tier, COUNT(*) AS cnt
            FROM dbo.adl_flight_trajectory
            WHERE recorded_utc >= CAST(SYSUTCDATETIME() AS DATE)
            GROUP BY trajectory_tier
            ORDER BY trajectory_tier";
    $stmt = @sqlsrv_query($conn_adl, $sql);
    if ($stmt) {
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $tier = (int)$row['trajectory_tier'];
            $cnt = (int)$row['cnt'];
            if (isset($liveData['daily_trajectory_by_tier'][$tier])) {
                $liveData['daily_trajectory_by_tier'][$tier] = $cnt;
            }
            $liveData['daily_trajectory_total'] += $cnt;
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
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            margin-bottom: 20px;
            overflow: hidden;
        }

        .status-section-header {
            background: #37384e;
            color: #fff;
            padding: 12px 16px;
            font-weight: 600;
            font-size: 0.9rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .status-section-header .cycle-badge {
            background: rgba(255,255,255,0.15);
            padding: 3px 10px;
            border-radius: 3px;
            font-size: 0.75rem;
            font-weight: 500;
        }

        .status-table {
            width: 100%;
            margin: 0;
            font-size: 0.85rem;
        }

        .status-table thead th {
            background: #f8f9fa;
            padding: 10px 12px;
            font-weight: 600;
            text-transform: uppercase;
            font-size: 0.7rem;
            letter-spacing: 0.5px;
            color: #666;
            border-bottom: 2px solid #dee2e6;
        }

        .status-table tbody td {
            padding: 10px 12px;
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
            color: #333;
        }

        .component-desc {
            font-size: 0.8rem;
            color: #666;
        }

        .status-badge {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 3px;
            font-size: 0.7rem;
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
            gap: 15px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }

        .metric-card {
            flex: 1;
            min-width: 140px;
            background: #fff;
            border-radius: 8px;
            padding: 20px;
            text-align: center;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            border-left: 4px solid var(--status-complete);
        }

        .metric-card.warning { border-left-color: var(--status-warning); }
        .metric-card.info { border-left-color: var(--status-running); }
        .metric-card.primary { border-left-color: var(--status-modified); }
        .metric-card.error { border-left-color: var(--status-error); }

        .metric-value {
            font-size: 2rem;
            font-weight: 700;
            color: #333;
            line-height: 1;
            font-family: 'Inconsolata', monospace;
        }

        .metric-label {
            font-size: 0.7rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #888;
            margin-top: 8px;
        }

        .metric-sublabel {
            font-size: 0.65rem;
            color: #aaa;
            margin-top: 2px;
        }

        .legend-section {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 16px;
            margin-top: 20px;
        }

        .legend-title {
            font-weight: 600;
            font-size: 0.85rem;
            margin-bottom: 12px;
            color: #333;
        }

        .legend-items {
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
        }

        .legend-item {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 0.8rem;
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
            padding: 15px;
            font-family: 'Inconsolata', monospace;
            font-size: 0.85rem;
            line-height: 1.6;
        }

        .tree-node {
            margin-left: 0;
        }

        .tree-node .tree-node {
            margin-left: 20px;
            border-left: 1px dashed #ccc;
            padding-left: 15px;
        }

        .tree-item {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 4px 0;
        }

        .tree-icon {
            width: 18px;
            text-align: center;
            color: #666;
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
            font-size: 0.7rem;
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
            border-radius: 8px;
            padding: 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            margin-bottom: 20px;
            overflow-x: auto;
        }

        .pipeline-flow {
            display: flex;
            align-items: center;
            justify-content: space-between;
            min-width: 800px;
            gap: 8px;
        }

        .pipeline-stage {
            flex: 1;
            text-align: center;
            padding: 15px 10px;
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            border-radius: 8px;
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

        .pipeline-stage-name {
            font-weight: 600;
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #333;
            margin-bottom: 4px;
        }

        .pipeline-stage-count {
            font-family: 'Inconsolata', monospace;
            font-size: 1.4rem;
            font-weight: 700;
            color: #333;
        }

        .pipeline-stage-label {
            font-size: 0.7rem;
            color: #888;
        }

        .pipeline-arrow {
            color: #ccc;
            font-size: 1.2rem;
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
            border-radius: 8px;
            padding: 15px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            margin-bottom: 20px;
            overflow: hidden;
        }

        .chart-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
        }

        .chart-title {
            font-weight: 600;
            font-size: 0.85rem;
            color: #333;
        }

        .chart-wrapper {
            position: relative;
            height: 120px;
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
            padding: 10px 15px;
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
            padding-left: 35px;
            background: #fafbfc;
        }

        .procedure-step.sub-step:hover {
            background: #f0f2f4;
        }

        .step-number {
            min-width: 36px;
            height: 24px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #37384e;
            color: #fff;
            border-radius: 4px;
            font-size: 0.7rem;
            font-weight: 700;
            font-family: 'Inconsolata', monospace;
            margin-right: 12px;
        }

        .procedure-step.sub-step .step-number {
            background: #6c757d;
            font-size: 0.65rem;
        }

        .step-content {
            flex: 1;
        }

        .step-name {
            font-weight: 600;
            font-size: 0.85rem;
            color: #333;
            margin-bottom: 2px;
        }

        .step-desc {
            font-size: 0.75rem;
            color: #888;
        }

        .step-output {
            font-family: 'Inconsolata', monospace;
            font-size: 0.7rem;
            color: var(--status-running);
            margin-top: 2px;
        }

        .step-category {
            display: inline-block;
            padding: 2px 6px;
            border-radius: 3px;
            font-size: 0.6rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            margin-left: 8px;
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
            gap: 6px;
            margin-left: auto;
            padding-left: 10px;
        }

        .step-metric-value {
            font-family: 'Inconsolata', monospace;
            font-size: 0.9rem;
            font-weight: 700;
            color: var(--status-complete);
            background: rgba(22, 201, 149, 0.1);
            padding: 2px 8px;
            border-radius: 4px;
            min-width: 50px;
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
            font-size: 0.65rem;
            color: #888;
            text-transform: uppercase;
        }

        .procedure-header-stats {
            display: flex;
            gap: 15px;
            padding: 10px 15px;
            background: #f8f9fa;
            border-bottom: 1px solid #eee;
            font-size: 0.8rem;
        }

        .procedure-header-stat {
            display: flex;
            align-items: center;
            gap: 6px;
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
            width: 8px;
            height: 8px;
            background: var(--status-complete);
            border-radius: 50%;
            animation: pulse 2s infinite;
        }

        /* Tier Tracking Styles */
        .tier-tracking-container {
            padding: 15px;
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

        .tier-section {
            margin-bottom: 20px;
        }

        .tier-section:last-child {
            margin-bottom: 0;
        }

        .tier-section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px 12px;
            background: #37384e;
            color: #fff;
            border-radius: 6px 6px 0 0;
            cursor: pointer;
            user-select: none;
        }

        .tier-section-header:hover {
            background: #454660;
        }

        .tier-section-header .section-title {
            font-weight: 600;
            font-size: 0.85rem;
        }

        .tier-section-header .section-toggle {
            transition: transform 0.2s ease;
        }

        .tier-section-header.collapsed .section-toggle {
            transform: rotate(-90deg);
        }

        .tier-section-content {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 15px;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 0 0 6px 6px;
            border: 1px solid #e0e0e0;
            border-top: none;
        }

        .tier-section-content.collapsed {
            display: none;
        }

        @media (max-width: 900px) {
            .tier-section-content {
                grid-template-columns: 1fr;
            }
        }

        .tier-group {
            background: #fff;
            border-radius: 6px;
            padding: 12px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.08);
        }

        .tier-group.full-width {
            grid-column: 1 / -1;
        }

        .tier-group-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 10px;
            padding-bottom: 8px;
            border-bottom: 1px solid #e0e0e0;
        }

        .tier-group-header-left {
            display: flex;
            flex-direction: column;
            gap: 2px;
        }

        .tier-group-title {
            font-weight: 600;
            font-size: 0.85rem;
            color: #333;
        }

        .tier-group-desc {
            font-size: 0.7rem;
            color: #888;
        }

        .tier-group-total {
            font-family: 'Inconsolata', monospace;
            font-size: 1.1rem;
            font-weight: 700;
            color: var(--status-complete);
            background: rgba(22, 201, 149, 0.1);
            padding: 4px 10px;
            border-radius: 4px;
        }

        .tier-bars {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .tier-bar-row {
            display: grid;
            grid-template-columns: 140px 1fr 60px;
            align-items: center;
            gap: 8px;
        }

        .tier-label {
            font-size: 0.75rem;
            color: #555;
            font-family: 'Inconsolata', monospace;
        }

        .tier-bar-container {
            height: 16px;
            background: #e0e0e0;
            border-radius: 3px;
            overflow: hidden;
        }

        .tier-bar {
            height: 100%;
            border-radius: 3px;
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
            font-size: 0.8rem;
            font-weight: 600;
            text-align: right;
            color: #333;
        }

        /* ATIS Tier Info Grid */
        .tier-info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 8px;
        }

        .tier-info-item {
            display: grid;
            grid-template-columns: 30px 1fr 45px;
            align-items: center;
            gap: 6px;
            padding: 6px 8px;
            background: #f8f9fa;
            border-radius: 4px;
            border-left: 3px solid #06b6d4;
        }

        .tier-info-item:nth-child(1) { border-left-color: #dc2626; }
        .tier-info-item:nth-child(2) { border-left-color: #f97316; }
        .tier-info-item:nth-child(3) { border-left-color: #eab308; }
        .tier-info-item:nth-child(4) { border-left-color: #22c55e; }
        .tier-info-item:nth-child(5) { border-left-color: #06b6d4; }

        .tier-info-tier {
            font-family: 'Inconsolata', monospace;
            font-size: 0.75rem;
            font-weight: 700;
            color: #555;
        }

        .tier-info-desc {
            font-size: 0.7rem;
            color: #666;
        }

        .tier-info-interval {
            font-family: 'Inconsolata', monospace;
            font-size: 0.8rem;
            font-weight: 600;
            text-align: right;
            color: #333;
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
    <script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-annotation@3.0.1/dist/chartjs-plugin-annotation.min.js"></script>
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
                    <span class="runtime-badge <?= $runtimes['total'] < 1000 ? 'fast' : ($runtimes['total'] < 3000 ? 'medium' : 'slow') ?>">
                        Page: <?= $runtimes['total'] ?>ms
                    </span>
                    <span class="runtime-badge <?= $runtimes['adl_queries'] < 500 ? 'fast' : ($runtimes['adl_queries'] < 1500 ? 'medium' : 'slow') ?>">
                        DB: <?= $runtimes['adl_queries'] ?>ms
                    </span>
                    <span class="runtime-badge <?= $runtimes['api_checks'] < 5000 ? 'fast' : 'medium' ?>">
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

        <!-- 24-Hour Flight Phase Chart -->
        <div class="row mb-4">
            <div class="col-12">
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
                                <td class="timing-info <?= ($apiHealth['vatsim']['latency'] ?? 999) < 500 ? 'latency-good' : (($apiHealth['vatsim']['latency'] ?? 999) < 1500 ? 'latency-ok' : 'latency-bad') ?>">
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
                                <td class="timing-info <?= ($apiHealth['aviationweather']['latency'] ?? 999) < 500 ? 'latency-good' : (($apiHealth['aviationweather']['latency'] ?? 999) < 1500 ? 'latency-ok' : 'latency-bad') ?>">
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
                                <td class="timing-info <?= ($apiHealth['noaa']['latency'] ?? 999) < 500 ? 'latency-good' : (($apiHealth['noaa']['latency'] ?? 999) < 1500 ? 'latency-ok' : 'latency-bad') ?>">
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
                                <td class="component-name">Boundary Crossings</td>
                                <td class="timing-info"><?= number_format($liveData['boundary_crossings_1h']) ?></td>
                                <td><span class="status-badge <?= $liveData['boundary_crossings_1h'] > 0 ? 'complete' : 'scheduled' ?>">
                                    <?= $liveData['boundary_crossings_1h'] > 0 ? 'Logged' : 'None' ?>
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
                                    <div class="component-name">Boundary Detection</div>
                                    <div class="component-desc"><?= number_format($liveData['boundary_crossings_1h']) ?> crossings/hr</div>
                                </td>
                                <td class="timing-info">Continuous</td>
                                <td><span class="status-badge complete">Active</span></td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- ADL Refresh Procedure Steps -->
                <div class="status-section">
                    <div class="status-section-header">
                        <span><i class="fas fa-sync-alt mr-2"></i>ADL Refresh Procedure</span>
                        <span class="cycle-badge">V8.6 &bull; 15s Cycle</span>
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
                        <!-- Step 8c -->
                        <div class="procedure-step sub-step">
                            <span class="step-number">8c</span>
                            <div class="step-content">
                                <div class="step-name">Waypoint ETA Calculation</div>
                                <div class="step-desc">Calculate ETA at each route waypoint</div>
                            </div>
                            <div class="step-metric">
                                <span class="step-metric-value <?= ($liveData['waypoint_etas_total'] ?? 0) > 0 ? 'high' : 'zero' ?>"><?= number_format($liveData['waypoint_etas_total'] ?? 0) ?></span>
                                <span class="step-metric-label">w/eta</span>
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
                        <!-- Step 10 -->
                        <div class="procedure-step">
                            <span class="step-number">10</span>
                            <div class="step-content">
                                <div class="step-name">Boundary Detection <span class="step-category detect">Detect</span></div>
                                <div class="step-desc">Detect ARTCC/Sector/TRACON boundary crossings</div>
                            </div>
                            <div class="step-metric">
                                <span class="step-metric-value <?= $liveData['boundary_crossings_1h'] > 0 ? '' : 'zero' ?>"><?= number_format($liveData['boundary_crossings_1h']) ?></span>
                                <span class="step-metric-label">cross/1h</span>
                            </div>
                        </div>
                        <!-- Step 11 -->
                        <div class="procedure-step">
                            <span class="step-number">11</span>
                            <div class="step-content">
                                <div class="step-name">Log Trajectory Positions <span class="step-category archive">Archive</span></div>
                                <div class="step-desc">Archive flight positions to trajectory history</div>
                            </div>
                            <div class="step-metric">
                                <span class="step-metric-value <?= $liveData['trajectories_1h'] > 0 ? 'high' : 'zero' ?>"><?= number_format($liveData['trajectories_1h']) ?></span>
                                <span class="step-metric-label">logged/1h</span>
                            </div>
                        </div>
                        <!-- Step 12 -->
                        <div class="procedure-step">
                            <span class="step-number">12</span>
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
                                            <span class="tier-group-title">Routes Parsed Today</span>
                                            <span class="tier-group-desc">Completed parses since 00:00Z</span>
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

                        <!-- Trajectory Logging Section -->
                        <div class="tier-section">
                            <div class="tier-section-header" onclick="toggleTierSection('trajectoryLogging')">
                                <span class="section-title"><i class="fas fa-map-marker-alt mr-2"></i>Trajectory Logging</span>
                                <i class="fas fa-chevron-down section-toggle" id="trajectoryLogging-toggle"></i>
                            </div>
                            <div class="tier-section-content" id="trajectoryLogging-content">
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
                                            <span class="tier-group-title">Trajectory Points Today</span>
                                            <span class="tier-group-desc">Positions logged since 00:00Z</span>
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

                        <!-- ATIS Refresh Section -->
                        <div class="tier-section">
                            <div class="tier-section-header" onclick="toggleTierSection('atisRefresh')">
                                <span class="section-title"><i class="fas fa-broadcast-tower mr-2"></i>ATIS Refresh Intervals</span>
                                <i class="fas fa-chevron-down section-toggle" id="atisRefresh-toggle"></i>
                            </div>
                            <div class="tier-section-content" id="atisRefresh-content">
                                <div class="tier-group full-width">
                                    <div class="tier-group-header">
                                        <div class="tier-group-header-left">
                                            <span class="tier-group-title">Daemon-Managed ATIS Update Tiers</span>
                                            <span class="tier-group-desc">Airport ATIS polling frequency based on priority and weather</span>
                                        </div>
                                    </div>
                                    <div class="tier-info-grid">
                                        <div class="tier-info-item">
                                            <span class="tier-info-tier">T0</span>
                                            <span class="tier-info-desc">METAR Update / Bad Wx</span>
                                            <span class="tier-info-interval">15s</span>
                                        </div>
                                        <div class="tier-info-item">
                                            <span class="tier-info-tier">T1</span>
                                            <span class="tier-info-desc">ASPM77 Normal Wx</span>
                                            <span class="tier-info-interval">1min</span>
                                        </div>
                                        <div class="tier-info-item">
                                            <span class="tier-info-tier">T2</span>
                                            <span class="tier-info-desc">Non-ASPM77 + CAN/LAT/CAR</span>
                                            <span class="tier-info-interval">5min</span>
                                        </div>
                                        <div class="tier-info-item">
                                            <span class="tier-info-tier">T3</span>
                                            <span class="tier-info-desc">Other Airports</span>
                                            <span class="tier-info-interval">30min</span>
                                        </div>
                                        <div class="tier-info-item">
                                            <span class="tier-info-tier">T4</span>
                                            <span class="tier-info-desc">Clear Wx Non-Priority</span>
                                            <span class="tier-info-interval">60min</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

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
        function toggleSection(sectionId) {
            const content = document.getElementById(sectionId + '-content');
            const toggle = document.getElementById(sectionId + '-toggle');
            if (content && toggle) {
                content.classList.toggle('collapsed');
                toggle.style.transform = content.classList.contains('collapsed') ? 'rotate(-90deg)' : 'rotate(0deg)';
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
            }
        }

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
                fetch('/api/stats/flight_phase_history.php?hours=24&interval=15')
                    .then(response => response.json())
                    .then(result => {
                        if (result.success && result.data) {
                            const data = result.data;
                            const currentTimeLabel = result.current_time_label;
                            const emphasizeIndices = data.emphasize_indices || [];

                            // Find index of current time in labels (or closest match)
                            let currentTimeIndex = data.labels.length - 1; // Default to last
                            for (let i = 0; i < data.labels.length; i++) {
                                if (data.labels[i] === currentTimeLabel) {
                                    currentTimeIndex = i;
                                    break;
                                }
                                // Find closest match if exact not found
                                if (data.labels[i] > currentTimeLabel && i > 0) {
                                    currentTimeIndex = i - 1;
                                    break;
                                }
                            }

                            window.phaseChartInstance = new Chart(phaseCtx, {
                                type: 'line',
                                data: {
                                    labels: data.labels,
                                    datasets: [
                                        {
                                            label: 'Arrived',
                                            data: data.datasets.arrived,
                                            borderColor: '#1a1a1a',
                                            backgroundColor: 'rgba(26, 26, 26, 0.8)',
                                            fill: true,
                                            tension: 0.3,
                                            pointRadius: 0
                                        },
                                        {
                                            label: 'Descending',
                                            data: data.datasets.descending,
                                            borderColor: '#991b1b',
                                            backgroundColor: 'rgba(153, 27, 27, 0.8)',
                                            fill: true,
                                            tension: 0.3,
                                            pointRadius: 0
                                        },
                                        {
                                            label: 'En Route',
                                            data: data.datasets.enroute,
                                            borderColor: '#dc2626',
                                            backgroundColor: 'rgba(220, 38, 38, 0.8)',
                                            fill: true,
                                            tension: 0.3,
                                            pointRadius: 0
                                        },
                                        {
                                            label: 'Departed',
                                            data: data.datasets.departed,
                                            borderColor: '#f87171',
                                            backgroundColor: 'rgba(248, 113, 113, 0.8)',
                                            fill: true,
                                            tension: 0.3,
                                            pointRadius: 0
                                        },
                                        {
                                            label: 'Taxiing',
                                            data: data.datasets.taxiing,
                                            borderColor: '#22c55e',
                                            backgroundColor: 'rgba(34, 197, 94, 0.8)',
                                            fill: true,
                                            tension: 0.3,
                                            pointRadius: 0
                                        },
                                        {
                                            label: 'Unknown',
                                            data: data.datasets.unknown || [],
                                            borderColor: '#eab308',
                                            backgroundColor: 'rgba(234, 179, 8, 0.8)',
                                            fill: true,
                                            tension: 0.3,
                                            pointRadius: 0
                                        },
                                        {
                                            label: 'Prefile (shadow)',
                                            data: data.datasets.prefile,
                                            borderColor: '#000000',
                                            backgroundColor: 'transparent',
                                            fill: false,
                                            tension: 0.3,
                                            pointRadius: 0,
                                            borderDash: [5, 5],
                                            borderWidth: 4,
                                            yAxisID: 'y2',
                                            hidden: false,
                                            order: -2
                                        },
                                        {
                                            label: 'Prefile',
                                            data: data.datasets.prefile,
                                            borderColor: '#06b6d4',
                                            backgroundColor: 'transparent',
                                            fill: false,
                                            tension: 0.3,
                                            pointRadius: 0,
                                            borderDash: [5, 5],
                                            borderWidth: 2,
                                            yAxisID: 'y2',
                                            order: -1
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
                                                font: { size: 10 },
                                                filter: function(item) {
                                                    return !item.text.includes('shadow');
                                                }
                                            }
                                        },
                                        tooltip: {
                                            mode: 'index',
                                            intersect: false,
                                            filter: function(item) {
                                                return !item.dataset.label.includes('shadow');
                                            }
                                        },
                                        annotation: {
                                            annotations: {
                                                currentTimeLine: {
                                                    type: 'line',
                                                    xMin: currentTimeIndex,
                                                    xMax: currentTimeIndex,
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
                                            display: true,
                                            title: {
                                                display: true,
                                                text: 'Time (UTC)',
                                                font: { size: 11, weight: 'bold' }
                                            },
                                            grid: { display: false },
                                            ticks: {
                                                font: { size: 8 },
                                                maxRotation: 45,
                                                minRotation: 45,
                                                callback: function(value, index) {
                                                    const label = this.getLabelForValue(value);
                                                    return label;
                                                },
                                                color: function(context) {
                                                    return emphasizeIndices.includes(context.index) ? '#000000' : '#666666';
                                                },
                                                font: function(context) {
                                                    if (emphasizeIndices.includes(context.index)) {
                                                        return { size: 10, weight: 'bold' };
                                                    }
                                                    return { size: 8 };
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
                                            beginAtZero: true
                                        },
                                        y2: {
                                            display: true,
                                            stacked: false,
                                            position: 'right',
                                            title: {
                                                display: true,
                                                text: 'Prefiles',
                                                font: { size: 11, weight: 'bold' },
                                                color: '#06b6d4'
                                            },
                                            grid: { display: false },
                                            ticks: { font: { size: 10 }, color: '#06b6d4' },
                                            beginAtZero: true
                                        }
                                    },
                                    interaction: {
                                        mode: 'nearest',
                                        axis: 'x',
                                        intersect: false
                                    }
                                }
                            });

                            // Populate summary statistics table
                            if (result.summary) {
                                const s = result.summary;
                                const phases = [
                                    { key: 'total_active', label: 'Total Active', color: '#333' },
                                    { key: 'prefile', label: 'Prefile', color: '#06b6d4' },
                                    { key: 'taxiing', label: 'Taxiing', color: '#22c55e' },
                                    { key: 'departed', label: 'Departed', color: '#f87171' },
                                    { key: 'enroute', label: 'En Route', color: '#dc2626' },
                                    { key: 'descending', label: 'Descending', color: '#991b1b' },
                                    { key: 'arrived', label: 'Arrived', color: '#1a1a1a' },
                                    { key: 'unknown', label: 'Unknown', color: '#eab308' }
                                ];
                                let tableHtml = '';
                                phases.forEach(p => {
                                    const stats = s[p.key] || {};
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
                                    window.phaseChartInstance.options.scales.y2.type = isLog ? 'logarithmic' : 'linear';
                                    if (isLog) {
                                        window.phaseChartInstance.options.scales.y.min = 1;
                                        window.phaseChartInstance.options.scales.y2.min = 1;
                                    } else {
                                        delete window.phaseChartInstance.options.scales.y.min;
                                        delete window.phaseChartInstance.options.scales.y2.min;
                                    }
                                    window.phaseChartInstance.update();
                                });
                            }
                        }
                    })
                    .catch(err => console.error('Failed to load phase history:', err));
            }
        });
    </script>
</body>
</html>
