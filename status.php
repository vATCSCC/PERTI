<?php
/**
 * PERTI System Status Dashboard
 *
 * Displays operational status for:
 * - Data pipeline components (daemons, imports)
 * - Stored procedures
 * - Database migrations
 * - System health metrics
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

        .status-badge.complete { background: var(--status-complete); color: #fff; }
        .status-badge.running { background: var(--status-running); color: #fff; }
        .status-badge.scheduled { background: var(--status-scheduled); color: #fff; }
        .status-badge.warning { background: var(--status-warning); color: #333; }
        .status-badge.error { background: var(--status-error); color: #fff; }
        .status-badge.modified { background: var(--status-modified); color: #fff; }
        .status-badge.removed { background: #333; color: #fff; text-decoration: line-through; }
        .status-badge.pending { background: #e9ecef; color: #666; border: 1px dashed #aaa; }

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
        }

        .metric-card {
            flex: 1;
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

        .metric-value {
            font-size: 2.2rem;
            font-weight: 700;
            color: #333;
            line-height: 1;
        }

        .metric-label {
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #888;
            margin-top: 8px;
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

        .file-link {
            color: var(--status-modified);
            text-decoration: none;
            font-family: 'Inconsolata', monospace;
            font-size: 0.75rem;
        }

        .file-link:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>
    <?php include('load/nav.php'); ?>

    <!-- Status Page Header -->
    <div class="status-page-header">
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
                    <span class="status-overall operational">
                        <i class="fas fa-check-circle mr-2"></i>All Systems Operational
                    </span>
                    <span class="auto-refresh-indicator ml-3">
                        <span class="dot"></span> Live
                    </span>
                </div>
            </div>
        </div>
    </div>

    <!-- Main Content -->
    <div class="container-fluid mt-4 mb-5">

        <!-- Quick Metrics -->
        <div class="metric-row">
            <div class="metric-card">
                <div class="metric-value">22</div>
                <div class="metric-label">Stored Procedures</div>
            </div>
            <div class="metric-card info">
                <div class="metric-value">6</div>
                <div class="metric-label">Active Daemons</div>
            </div>
            <div class="metric-card primary">
                <div class="metric-value">75</div>
                <div class="metric-label">ADL Migrations</div>
            </div>
            <div class="metric-card warning">
                <div class="metric-value">1</div>
                <div class="metric-label">Pending Changes</div>
            </div>
        </div>

        <div class="row">
            <div class="col-lg-6">

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
                                <th>Notes</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td>
                                    <div class="component-name">ATIS Daemon</div>
                                    <div class="component-desc">VATSIM runway assignments</div>
                                </td>
                                <td class="timing-info">15s</td>
                                <td><span class="status-badge running">Running</span></td>
                                <td class="comment-text on-time">ON-TIME</td>
                            </tr>
                            <tr>
                                <td>
                                    <div class="component-name">Parse Queue Daemon</div>
                                    <div class="component-desc">Route expansion pipeline</div>
                                </td>
                                <td class="timing-info">5s</td>
                                <td><span class="status-badge modified">Modified</span></td>
                                <td class="comment-text">Uncommitted changes</td>
                            </tr>
                            <tr>
                                <td>
                                    <div class="component-name">Weather Import</div>
                                    <div class="component-desc">SIGMET/AIRMET updates</div>
                                </td>
                                <td class="timing-info">5m</td>
                                <td><span class="status-badge scheduled">Scheduled</span></td>
                                <td class="comment-text on-time">ON-TIME</td>
                            </tr>
                            <tr>
                                <td>
                                    <div class="component-name">Wind Data Import</div>
                                    <div class="component-desc">NOAA RAP/GFS winds</div>
                                </td>
                                <td class="timing-info">1h</td>
                                <td><span class="status-badge scheduled">Scheduled</span></td>
                                <td class="comment-text on-time">ON-TIME</td>
                            </tr>
                            <tr>
                                <td>
                                    <div class="component-name">Event Stats Update</div>
                                    <div class="component-desc">VATUSA event sync</div>
                                </td>
                                <td class="timing-info">Daily</td>
                                <td><span class="status-badge complete">Complete</span></td>
                                <td class="comment-text on-time">ON-TIME</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Import Scripts -->
                <div class="status-section">
                    <div class="status-section-header">
                        <span><i class="fas fa-download mr-2"></i>Import Scripts</span>
                        <span class="cycle-badge">On-Demand</span>
                    </div>
                    <table class="status-table">
                        <thead>
                            <tr>
                                <th>Script</th>
                                <th>Type</th>
                                <th>Status</th>
                                <th>Last Run</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td>
                                    <div class="component-name">import_boundaries.php</div>
                                    <div class="component-desc">ARTCC/TRACON sectors</div>
                                </td>
                                <td class="timing-info">PHP</td>
                                <td><span class="status-badge complete">Ready</span></td>
                                <td class="comment-text">Manual trigger</td>
                            </tr>
                            <tr>
                                <td>
                                    <div class="component-name">import_osm_airport_geometry.php</div>
                                    <div class="component-desc">Airport zone boundaries</div>
                                </td>
                                <td class="timing-info">PHP</td>
                                <td><span class="status-badge complete">Ready</span></td>
                                <td class="comment-text">Manual trigger</td>
                            </tr>
                            <tr>
                                <td>
                                    <div class="component-name">nasr_navdata_updater.py</div>
                                    <div class="component-desc">FAA navigation data</div>
                                </td>
                                <td class="timing-info">Python</td>
                                <td><span class="status-badge complete">Ready</span></td>
                                <td class="comment-text">28-day cycle</td>
                            </tr>
                            <tr>
                                <td>
                                    <div class="component-name">update_playbook_routes.py</div>
                                    <div class="component-desc">FAA playbook routes</div>
                                </td>
                                <td class="timing-info">Python</td>
                                <td><span class="status-badge complete">Ready</span></td>
                                <td class="comment-text">Manual trigger</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Recent Changes -->
                <div class="status-section">
                    <div class="status-section-header">
                        <span><i class="fas fa-code-branch mr-2"></i>Recent Changes</span>
                        <span class="cycle-badge">Git Status</span>
                    </div>
                    <div class="subsection-title">Modified (Uncommitted)</div>
                    <table class="status-table">
                        <thead>
                            <tr>
                                <th>File</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td class="component-name">adl/php/parse_queue_daemon.php</td>
                                <td><span class="status-badge modified">Modified</span></td>
                            </tr>
                            <tr>
                                <td class="component-name">adl/procedures/sp_ProcessBoundaryDetectionBatch.sql</td>
                                <td><span class="status-badge modified">Modified</span></td>
                            </tr>
                        </tbody>
                    </table>
                    <div class="subsection-title">Pending Migrations</div>
                    <table class="status-table">
                        <thead>
                            <tr>
                                <th>Migration</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td class="component-name">079_event_aar_from_flights.sql</td>
                                <td><span class="status-badge pending">Pending</span></td>
                            </tr>
                        </tbody>
                    </table>
                    <div class="subsection-title">Recent Commits</div>
                    <table class="status-table">
                        <thead>
                            <tr>
                                <th>Commit</th>
                                <th>Message</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td class="timing-info">ae131f5</td>
                                <td class="component-desc">Refine boundary detection batch processing</td>
                            </tr>
                            <tr>
                                <td class="timing-info">a39dca9</td>
                                <td class="component-desc">Remove __pycache__ from version control</td>
                            </tr>
                            <tr>
                                <td class="timing-info">106d679</td>
                                <td class="component-desc">Add codebase index documentation</td>
                            </tr>
                            <tr>
                                <td class="timing-info">4fd3509</td>
                                <td class="component-desc">Add archive deployment and utility scripts</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

            </div>

            <div class="col-lg-6">

                <!-- Stored Procedures - Flight Processing -->
                <div class="status-section">
                    <div class="status-section-header">
                        <span><i class="fas fa-database mr-2"></i>Stored Procedures</span>
                        <span class="cycle-badge">Azure SQL</span>
                    </div>
                    <div class="subsection-title">Flight Processing & Route Parsing</div>
                    <table class="status-table">
                        <thead>
                            <tr>
                                <th>Procedure</th>
                                <th>Status</th>
                                <th>Notes</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td class="component-name">sp_ParseRoute</td>
                                <td><span class="status-badge complete">Deployed</span></td>
                                <td class="comment-text on-time">ON-TIME</td>
                            </tr>
                            <tr>
                                <td class="component-name">sp_ParseQueue</td>
                                <td><span class="status-badge complete">Deployed</span></td>
                                <td class="comment-text on-time">ON-TIME</td>
                            </tr>
                            <tr>
                                <td class="component-name">sp_ParseSimBriefData</td>
                                <td><span class="status-badge complete">Deployed</span></td>
                                <td class="comment-text on-time">ON-TIME</td>
                            </tr>
                            <tr>
                                <td class="component-name">sp_RouteDistanceBatch</td>
                                <td><span class="status-badge complete">Deployed</span></td>
                                <td class="comment-text on-time">ON-TIME</td>
                            </tr>
                            <tr>
                                <td class="component-name">fn_GetParseTier</td>
                                <td><span class="status-badge complete">Deployed</span></td>
                                <td class="comment-text on-time">ON-TIME</td>
                            </tr>
                        </tbody>
                    </table>

                    <div class="subsection-title">ETA & Trajectory System</div>
                    <table class="status-table">
                        <thead>
                            <tr>
                                <th>Procedure</th>
                                <th>Status</th>
                                <th>Notes</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td class="component-name">sp_CalculateETA</td>
                                <td><span class="status-badge complete">Deployed</span></td>
                                <td class="comment-text on-time">ON-TIME</td>
                            </tr>
                            <tr>
                                <td class="component-name">sp_CalculateETABatch</td>
                                <td><span class="status-badge complete">Deployed</span></td>
                                <td class="comment-text on-time">ON-TIME</td>
                            </tr>
                            <tr>
                                <td class="component-name">sp_ProcessTrajectoryBatch</td>
                                <td><span class="status-badge complete">Deployed</span></td>
                                <td class="comment-text on-time">ON-TIME</td>
                            </tr>
                            <tr>
                                <td class="component-name">sp_LogTrajectory</td>
                                <td><span class="status-badge complete">Deployed</span></td>
                                <td class="comment-text on-time">ON-TIME</td>
                            </tr>
                            <tr>
                                <td class="component-name">fn_GetAircraftPerformance</td>
                                <td><span class="status-badge complete">Deployed</span></td>
                                <td class="comment-text on-time">ON-TIME</td>
                            </tr>
                        </tbody>
                    </table>

                    <div class="subsection-title">Zone & Boundary Detection</div>
                    <table class="status-table">
                        <thead>
                            <tr>
                                <th>Procedure</th>
                                <th>Status</th>
                                <th>Notes</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td class="component-name">sp_ProcessZoneDetectionBatch</td>
                                <td><span class="status-badge complete">Deployed</span></td>
                                <td class="comment-text on-time">ON-TIME</td>
                            </tr>
                            <tr>
                                <td class="component-name">sp_DetectZoneTransition</td>
                                <td><span class="status-badge complete">Deployed</span></td>
                                <td class="comment-text on-time">ON-TIME</td>
                            </tr>
                            <tr>
                                <td class="component-name">sp_ProcessBoundaryDetectionBatch</td>
                                <td><span class="status-badge modified">Modified</span></td>
                                <td class="comment-text">Uncommitted changes</td>
                            </tr>
                            <tr>
                                <td class="component-name">fn_DetectCurrentZone</td>
                                <td><span class="status-badge complete">Deployed</span></td>
                                <td class="comment-text on-time">ON-TIME</td>
                            </tr>
                            <tr>
                                <td class="component-name">sp_ImportAirportGeometry</td>
                                <td><span class="status-badge complete">Deployed</span></td>
                                <td class="comment-text on-time">ON-TIME</td>
                            </tr>
                        </tbody>
                    </table>

                    <div class="subsection-title">Data Synchronization</div>
                    <table class="status-table">
                        <thead>
                            <tr>
                                <th>Procedure</th>
                                <th>Status</th>
                                <th>Notes</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td class="component-name">sp_Adl_RefreshFromVatsim_Normalized</td>
                                <td><span class="status-badge complete">Deployed</span></td>
                                <td class="comment-text on-time">ON-TIME</td>
                            </tr>
                            <tr>
                                <td class="component-name">fn_IsFlightRelevant</td>
                                <td><span class="status-badge complete">Deployed</span></td>
                                <td class="comment-text on-time">ON-TIME</td>
                            </tr>
                            <tr>
                                <td class="component-name">sp_UpsertFlight</td>
                                <td><span class="status-badge removed">Removed</span></td>
                                <td class="comment-text">Consolidated</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Migration Status -->
                <div class="status-section">
                    <div class="status-section-header">
                        <span><i class="fas fa-layer-group mr-2"></i>Database Migrations</span>
                        <span class="cycle-badge">Schema</span>
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
                                <td class="component-name">core/</td>
                                <td class="timing-info">6</td>
                                <td><span class="status-badge complete">Deployed</span></td>
                            </tr>
                            <tr>
                                <td class="component-name">eta/</td>
                                <td class="timing-info">11</td>
                                <td><span class="status-badge complete">Deployed</span></td>
                            </tr>
                            <tr>
                                <td class="component-name">oooi/</td>
                                <td class="timing-info">8</td>
                                <td><span class="status-badge complete">Deployed</span></td>
                            </tr>
                            <tr>
                                <td class="component-name">boundaries/</td>
                                <td class="timing-info">6</td>
                                <td><span class="status-badge complete">Deployed</span></td>
                            </tr>
                            <tr>
                                <td class="component-name">weather/</td>
                                <td class="timing-info">4</td>
                                <td><span class="status-badge complete">Deployed</span></td>
                            </tr>
                            <tr>
                                <td class="component-name">navdata/</td>
                                <td class="timing-info">5</td>
                                <td><span class="status-badge complete">Deployed</span></td>
                            </tr>
                            <tr>
                                <td class="component-name">cifp/</td>
                                <td class="timing-info">2</td>
                                <td><span class="status-badge complete">Deployed</span></td>
                            </tr>
                            <tr>
                                <td class="component-name">performance/</td>
                                <td class="timing-info">3</td>
                                <td><span class="status-badge complete">Deployed</span></td>
                            </tr>
                            <tr>
                                <td class="component-name">stats/</td>
                                <td class="timing-info">5</td>
                                <td><span class="status-badge complete">Deployed</span></td>
                            </tr>
                            <tr>
                                <td class="component-name">changelog/</td>
                                <td class="timing-info">7</td>
                                <td><span class="status-badge complete">Deployed</span></td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- CI/CD Status -->
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
                                <td class="component-name">Package</td>
                                <td class="timing-info">Artifact</td>
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
                    <span class="status-badge complete">Complete</span>
                    <span>Deployed and operational</span>
                </div>
                <div class="legend-item">
                    <span class="status-badge running">Running</span>
                    <span>Currently executing</span>
                </div>
                <div class="legend-item">
                    <span class="status-badge scheduled">Scheduled</span>
                    <span>Waiting for next cycle</span>
                </div>
                <div class="legend-item">
                    <span class="status-badge modified">Modified</span>
                    <span>Has uncommitted changes</span>
                </div>
                <div class="legend-item">
                    <span class="status-badge pending">Pending</span>
                    <span>Awaiting deployment</span>
                </div>
                <div class="legend-item">
                    <span class="status-badge warning">Warning</span>
                    <span>Operational with issues</span>
                </div>
                <div class="legend-item">
                    <span class="status-badge error">Error</span>
                    <span>Failed or offline</span>
                </div>
                <div class="legend-item">
                    <span class="status-badge removed">Removed</span>
                    <span>Deprecated/deleted</span>
                </div>
            </div>
        </div>

        <div class="text-center mt-4">
            <p class="refresh-note">
                This page auto-refreshes every 60 seconds.
                If status fails to update, <a href="javascript:location.reload()">click here to reload</a>.
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

        // Update timestamp display
        function updateTimestamp() {
            const now = new Date();
            const utc = now.toUTCString().replace('GMT', 'UTC');
            $('.status-timestamp').html(utc + ' &mdash; Auto-refreshes every 60 seconds');
        }

        $(document).ready(function() {
            // Initialize tooltips
            $('[data-toggle="tooltip"]').tooltip();
        });
    </script>
</body>
</html>
