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
    'queue_pending' => 0,
    'queue_processing' => 0,
    'queue_complete_1h' => 0,
    'queue_failed_1h' => 0,
    'avg_parse_ms' => 0,
    'last_vatsim_refresh' => null,
    'trajectories_1h' => 0,
    'zone_transitions_1h' => 0,
    'boundary_crossings_1h' => 0,
    'weather_alerts_active' => 0,
    'atis_updates_1h' => 0,
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
if (isset($conn_adl) && $conn_adl !== null && $conn_adl !== false) {
    $liveData['adl_connected'] = true;

    // Active flights count
    $sql = "SELECT COUNT(*) AS cnt FROM dbo.adl_flights WHERE is_active = 1";
    $stmt = @sqlsrv_query($conn_adl, $sql);
    if ($stmt) {
        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        $liveData['active_flights'] = $row['cnt'] ?? 0;
        sqlsrv_free_stmt($stmt);
    }

    // Parse queue stats (last hour)
    $sql = "
        SELECT
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
        $liveData['queue_pending'] = $row['pending'] ?? 0;
        $liveData['queue_processing'] = $row['processing'] ?? 0;
        $liveData['queue_complete_1h'] = $row['complete_1h'] ?? 0;
        $liveData['queue_failed_1h'] = $row['failed_1h'] ?? 0;
        $liveData['avg_parse_ms'] = round($row['avg_parse_ms'] ?? 0);
        sqlsrv_free_stmt($stmt);
    }

    // Last VATSIM refresh time
    $sql = "SELECT TOP 1 snapshot_utc FROM dbo.adl_flights ORDER BY snapshot_utc DESC";
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

    // Trajectory logs (last hour)
    $sql = "SELECT COUNT(*) AS cnt FROM dbo.adl_trajectories WHERE logged_utc > DATEADD(HOUR, -1, SYSUTCDATETIME())";
    $stmt = @sqlsrv_query($conn_adl, $sql);
    if ($stmt) {
        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        $liveData['trajectories_1h'] = $row['cnt'] ?? 0;
        sqlsrv_free_stmt($stmt);
    }

    // Zone transitions (last hour) - check if table exists
    $sql = "SELECT COUNT(*) AS cnt FROM dbo.adl_oooi_log WHERE detected_utc > DATEADD(HOUR, -1, SYSUTCDATETIME())";
    $stmt = @sqlsrv_query($conn_adl, $sql);
    if ($stmt) {
        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        $liveData['zone_transitions_1h'] = $row['cnt'] ?? 0;
        sqlsrv_free_stmt($stmt);
    }

    // Boundary crossings (last hour)
    $sql = "SELECT COUNT(*) AS cnt FROM dbo.adl_boundary_log WHERE crossed_utc > DATEADD(HOUR, -1, SYSUTCDATETIME())";
    $stmt = @sqlsrv_query($conn_adl, $sql);
    if ($stmt) {
        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        $liveData['boundary_crossings_1h'] = $row['cnt'] ?? 0;
        sqlsrv_free_stmt($stmt);
    }

    // Active weather alerts
    $sql = "SELECT COUNT(*) AS cnt FROM dbo.adl_weather_alerts WHERE valid_time_to > SYSUTCDATETIME()";
    $stmt = @sqlsrv_query($conn_adl, $sql);
    if ($stmt) {
        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        $liveData['weather_alerts_active'] = $row['cnt'] ?? 0;
        sqlsrv_free_stmt($stmt);
    }

} else {
    $statusIssues[] = 'ADL database connection unavailable';
    $overallStatus = 'degraded';
}

// -----------------------------------------------------------------------------
// External API Health Checks
// -----------------------------------------------------------------------------

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

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <?php include("load/header.php"); ?>
    <title>System Status | PERTI</title>

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
            padding: 20px 0;
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
    </style>
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
        // Auto-refresh every 60 seconds
        setTimeout(function() {
            location.reload();
        }, 60000);

        $(document).ready(function() {
            $('[data-toggle="tooltip"]').tooltip();
        });
    </script>
</body>
</html>
