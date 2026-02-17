<?php
/**
 * Infrastructure Transparency Page
 * Public disclosure of PERTI infrastructure costs and scaling plans
 *
 * OPTIMIZED: This is a public static page - no session handler or DB needed
 */

include("load/config.php");
include("load/i18n.php");
// No session handler or database connections needed - public static content
?>

<!DOCTYPE html>
<html>
<head>
    <?php
        $page_title = "Infrastructure Transparency - PERTI";
        include("load/header.php");
    ?>
    <style>
        .transparency-section {
            margin-bottom: 40px;
        }
        .transparency-section h3 {
            color: #5dade2;
            border-bottom: 2px solid #5dade2;
            padding-bottom: 10px;
            margin-bottom: 20px;
        }
        .cost-table {
            width: 100%;
            margin-bottom: 20px;
        }
        .cost-table th {
            background: #2c3e50;
            color: #fff;
            padding: 12px 15px;
            text-align: left;
        }
        .cost-table td {
            padding: 12px 15px;
            border-bottom: 1px solid #dee2e6;
        }
        .cost-table tr:hover {
            background: #f8f9fa;
        }
        .cost-total {
            background: #e8f6fd !important;
            font-weight: bold;
        }
        .tier-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.85em;
            font-weight: 600;
        }
        .tier-current {
            background: #27ae60;
            color: #fff;
        }
        .tier-future {
            background: #3498db;
            color: #fff;
        }
        .info-card {
            background: #fff;
            border: 1px solid #dee2e6;
            border-radius: 8px;
            padding: 25px;
            margin-bottom: 25px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
        }
        .info-card h4 {
            color: #2c3e50;
            margin-bottom: 15px;
        }
        .scaling-scenario {
            background: #f8f9fa;
            border-left: 4px solid #5dade2;
            padding: 15px 20px;
            margin-bottom: 15px;
        }
        .scaling-scenario h5 {
            margin-bottom: 10px;
            color: #2c3e50;
        }
        .metric-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        .metric-box {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: #fff;
            padding: 20px;
            border-radius: 8px;
            text-align: center;
        }
        .metric-box .metric-value {
            font-size: 2em;
            font-weight: bold;
        }
        .metric-box .metric-label {
            font-size: 0.9em;
            opacity: 0.9;
        }
        .note-box {
            background: #fff3cd;
            border: 1px solid #ffc107;
            border-radius: 6px;
            padding: 15px;
            margin-top: 20px;
        }
        .note-box i {
            color: #856404;
        }
        .optimization-list {
            list-style: none;
            padding: 0;
        }
        .optimization-list li {
            padding: 10px 0;
            border-bottom: 1px solid #eee;
            display: flex;
            align-items: center;
        }
        .optimization-list li:last-child {
            border-bottom: none;
        }
        .optimization-list i {
            color: #27ae60;
            margin-right: 12px;
            font-size: 1.1em;
        }
        .status-implemented {
            color: #27ae60;
        }
        .status-planned {
            color: #f39c12;
        }
    </style>
</head>

<body>

<?php include('load/nav_public.php'); ?>

<!-- Hero Section -->
<section class="d-flex align-items-center position-relative bg-position-center fh-section overflow-hidden pt-6 jarallax bg-dark text-light" data-jarallax data-speed="0.3">
    <div class="container-fluid pt-2 pb-5 py-lg-6">
        <img class="jarallax-img" src="assets/img/jumbotron/main.png" alt="" style="opacity: 50%;">
        <center>
            <h1><?= __('transparency.title') ?></h1>
            <h4 class="text-white"><?= __('transparency.subtitle') ?></h4>
        </center>
    </div>
</section>

<!-- Main Content -->
<div class="container mt-5 mb-5">

    <p class="text-muted text-right"><small><?= __('transparency.lastUpdated', ['date' => 'February 2026']) ?></small></p>

    <!-- Current Costs Section -->
    <div class="transparency-section">
        <h3><i class="fas fa-dollar-sign mr-2"></i><?= __('transparency.currentMonthlyCosts') ?></h3>

        <p class="lead"><?= __('transparency.costsIntro') ?></p>

        <div class="info-card">
            <h4><i class="fas fa-cloud mr-2"></i><?= __('transparency.computeResources') ?></h4>
            <table class="cost-table">
                <thead>
                    <tr>
                        <th><?= __('transparency.resource') ?></th>
                        <th><?= __('transparency.tierSku') ?></th>
                        <th><?= __('transparency.purpose') ?></th>
                        <th><?= __('transparency.costPerMonth') ?></th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><strong>vatcscc</strong><br><small>App Service</small></td>
                        <td><span class="tier-badge tier-current">P1v2</span><br><small>3.5 GB RAM, 1 vCPU</small></td>
                        <td>Main PERTI website, PHP-FPM workers, 14 background daemons</td>
                        <td>~$80</td>
                    </tr>
                    <tr>
                        <td><strong>vatcscc-atfm-engine</strong><br><small>App Service</small></td>
                        <td><span class="tier-badge tier-current">P1v2</span><br><small>Shared plan</small></td>
                        <td>ATFM processing engine</td>
                        <td><?= __('transparency.included') ?></td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="info-card">
            <h4><i class="fas fa-database mr-2"></i><?= __('transparency.azureSqlDatabases') ?></h4>
            <p class="text-muted mb-3"><?= __('transparency.hostedOn', ['host' => 'vatsim.database.windows.net', 'region' => 'East US']) ?></p>
            <table class="cost-table">
                <thead>
                    <tr>
                        <th>Database</th>
                        <th>Tier/SKU</th>
                        <th>Purpose</th>
                        <th>Cost/Month</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><strong>VATSIM_ADL</strong></td>
                        <td><span class="tier-badge" style="background:#9b59b6;color:#fff;">Hyperscale</span><br><small>Serverless Gen5, 16 vCores, min 3</small></td>
                        <td>Active flight data, real-time positions, parsed routes, trajectory, ETAs, boundary crossings, statistics</td>
                        <td>~$3,200*</td>
                    </tr>
                    <tr>
                        <td><strong>VATSIM_TMI</strong></td>
                        <td><span class="tier-badge" style="background:#3498db;color:#fff;">Basic</span><br><small>5 DTU, 2 GB</small></td>
                        <td>Traffic management initiatives, NTML, advisories, GDT slots, reroutes</td>
                        <td>~$5</td>
                    </tr>
                    <tr>
                        <td><strong>SWIM_API</strong></td>
                        <td><span class="tier-badge" style="background:#3498db;color:#fff;">Basic</span><br><small>5 DTU, 2 GB</small></td>
                        <td>SWIM API keys, flight snapshots, audit log</td>
                        <td>~$5</td>
                    </tr>
                    <tr>
                        <td><strong>VATSIM_REF</strong></td>
                        <td><span class="tier-badge" style="background:#3498db;color:#fff;">Basic</span><br><small>5 DTU, 2 GB</small></td>
                        <td>Reference data (airports, airways, fixes, procedures)</td>
                        <td>~$5</td>
                    </tr>
                    <tr>
                        <td><strong>VATSIM_STATS</strong></td>
                        <td><span class="tier-badge" style="background:#95a5a6;color:#fff;">GP Serverless</span><br><small>Gen5, 1 vCore (paused)</small></td>
                        <td>Statistics &amp; analytics (currently paused)</td>
                        <td>~$0</td>
                    </tr>
                </tbody>
            </table>
            <small class="text-muted">* Hyperscale Serverless: Compute billed at ~$0.51/vCore-hour. VATSIM_ADL runs continuously with auto-pause disabled and 1 HA replica. Scales based on VATSIM traffic (peak: 1800-0200 UTC). Cost includes compute (~$2,900) + storage (~$300).</small>
        </div>

        <div class="info-card">
            <h4><i class="fas fa-database mr-2"></i><?= __('transparency.mysqlPostgresql') ?></h4>
            <table class="cost-table">
                <thead>
                    <tr>
                        <th>Database</th>
                        <th>Tier/SKU</th>
                        <th>Purpose</th>
                        <th>Cost/Month</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><strong>vatcscc-perti</strong><br><small>MySQL 8.0</small></td>
                        <td><span class="tier-badge" style="background:#e67e22;color:#fff;">General Purpose</span><br><small>D2ds_v4, 20 GB</small></td>
                        <td>Main web app (plans, users, configs, staffing, reviews)</td>
                        <td>~$125</td>
                    </tr>
                    <tr>
                        <td><strong>vatcscc-gis</strong><br><small>PostgreSQL 16 + PostGIS</small></td>
                        <td><span class="tier-badge" style="background:#2ecc71;color:#fff;">Burstable</span><br><small>B2s, 32 GB</small></td>
                        <td>Spatial queries (boundary intersection, route geometry, fix lookups)</td>
                        <td>~$55</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="info-card">
            <h4><i class="fas fa-calculator mr-2"></i><?= __('transparency.costSummary') ?></h4>
            <table class="cost-table">
                <tbody>
                    <tr>
                        <td>VATSIM_ADL Hyperscale Compute (~16 vCores avg)</td>
                        <td class="text-right">~$2,900</td>
                    </tr>
                    <tr>
                        <td>VATSIM_ADL Hyperscale Storage + HA Replica</td>
                        <td class="text-right">~$300</td>
                    </tr>
                    <tr>
                        <td>MySQL (General Purpose D2ds_v4)</td>
                        <td class="text-right">~$125</td>
                    </tr>
                    <tr>
                        <td>App Service (P1v2)</td>
                        <td class="text-right">~$80</td>
                    </tr>
                    <tr>
                        <td>PostgreSQL GIS (Burstable B2s)</td>
                        <td class="text-right">~$55</td>
                    </tr>
                    <tr>
                        <td>Basic SQL Databases (3x)</td>
                        <td class="text-right">~$15</td>
                    </tr>
                    <tr>
                        <td>Storage Accounts (4x)</td>
                        <td class="text-right">~$10</td>
                    </tr>
                    <tr>
                        <td>Other (Data Factory, Logic Apps)</td>
                        <td class="text-right">~$5</td>
                    </tr>
                    <tr class="cost-total">
                        <td><strong><?= __('transparency.monthlyTotal') ?></strong></td>
                        <td class="text-right"><strong>~$3,500 /month</strong></td>
                    </tr>
                </tbody>
            </table>
            <div class="note-box mt-3">
                <i class="fas fa-info-circle mr-2"></i>
                <strong>Hyperscale Compute:</strong> VATSIM_ADL is the primary cost driver at ~92% of total spend. It runs Hyperscale Serverless Gen5 with 16 vCores max, 3 vCores min, auto-pause disabled, and 1 HA replica. Compute scales based on query load from 14 background daemons processing flight data every 15 seconds.
            </div>
        </div>

        <div class="info-card">
            <h4><i class="fas fa-archive mr-2"></i><?= __('transparency.storageOther') ?></h4>
            <table class="cost-table">
                <thead>
                    <tr>
                        <th><?= __('transparency.resource') ?></th>
                        <th><?= __('transparency.type') ?></th>
                        <th><?= __('transparency.purpose') ?></th>
                        <th><?= __('transparency.costPerMonth') ?></th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><strong>vatsimdatastorage</strong></td>
                        <td>Storage (LRS)</td>
                        <td>General data storage, ADL archives</td>
                        <td>~$3</td>
                    </tr>
                    <tr>
                        <td><strong>pertiadlarchive</strong></td>
                        <td>Storage (LRS)</td>
                        <td>Trajectory archival, compressed flight history</td>
                        <td>~$1</td>
                    </tr>
                    <tr>
                        <td><strong>vatsimadlarchive</strong></td>
                        <td>Storage (RA-GRS)</td>
                        <td>Geo-redundant long-term archival</td>
                        <td>&lt;$1</td>
                    </tr>
                    <tr>
                        <td><strong>vatsim-adl-history</strong></td>
                        <td>Azure Data Factory</td>
                        <td>Historical data pipeline orchestration</td>
                        <td>&lt;$1</td>
                    </tr>
                    <tr>
                        <td><strong>Deployment Slots</strong></td>
                        <td>App Service</td>
                        <td>Staging &amp; backup slots for zero-downtime deploys</td>
                        <td><?= __('transparency.included') ?></td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="info-card">
            <h4><i class="fas fa-chart-area mr-2"></i><?= __('transparency.costTrend') ?></h4>
            <table class="cost-table">
                <thead>
                    <tr>
                        <th><?= __('transparency.month') ?></th>
                        <th><?= __('transparency.sqlDatabase') ?></th>
                        <th><?= __('transparency.appService') ?></th>
                        <th><?= __('transparency.mysql') ?></th>
                        <th>Other</th>
                        <th><?= __('transparency.total') ?></th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>Oct 2025</td>
                        <td>$536</td>
                        <td>$82</td>
                        <td>$15</td>
                        <td>$51</td>
                        <td><strong>$684</strong></td>
                    </tr>
                    <tr>
                        <td>Nov 2025</td>
                        <td>$524</td>
                        <td>$80</td>
                        <td>$15</td>
                        <td>$52</td>
                        <td><strong>$670</strong></td>
                    </tr>
                    <tr>
                        <td>Dec 2025</td>
                        <td>$2,020</td>
                        <td>$83</td>
                        <td>$15</td>
                        <td>$54</td>
                        <td><strong>$2,172</strong></td>
                    </tr>
                    <tr>
                        <td>Jan 2026</td>
                        <td>$3,479</td>
                        <td>$85</td>
                        <td>$26</td>
                        <td>$50</td>
                        <td><strong>$3,640</strong></td>
                    </tr>
                </tbody>
            </table>
            <small class="text-muted">
                <strong>Dec 2025:</strong> VATSIM_ADL migrated from General Purpose to Hyperscale Serverless. Geo-replicas temporarily provisioned for migration.<br>
                <strong>Jan 2026:</strong> Geo-replicas and VATSIM_Data database decommissioned. PostgreSQL GIS database added. MySQL upgraded to General Purpose tier.
            </small>
        </div>
    </div>

    <!-- What You Get Section -->
    <div class="transparency-section">
        <h3><i class="fas fa-server mr-2"></i><?= __('transparency.whatThisProvides') ?></h3>

        <div class="metric-grid">
            <div class="metric-box">
                <div class="metric-value">40</div>
                <div class="metric-label"><?= __('transparency.phpFpmWorkers') ?></div>
            </div>
            <div class="metric-box">
                <div class="metric-value">15s</div>
                <div class="metric-label"><?= __('transparency.dataRefreshRate') ?></div>
            </div>
            <div class="metric-box">
                <div class="metric-value">14</div>
                <div class="metric-label"><?= __('transparency.backgroundDaemons') ?></div>
            </div>
            <div class="metric-box">
                <div class="metric-value">24/7</div>
                <div class="metric-label"><?= __('transparency.availability') ?></div>
            </div>
            <div class="metric-box">
                <div class="metric-value">7</div>
                <div class="metric-label"><?= __('transparency.databases') ?></div>
            </div>
            <div class="metric-box">
                <div class="metric-value">3</div>
                <div class="metric-label"><?= __('transparency.dbEngines') ?></div>
            </div>
        </div>

        <div class="row">
            <div class="col-md-6">
                <div class="info-card">
                    <h4><i class="fas fa-cogs mr-2"></i><?= __('transparency.backgroundServices') ?></h4>
                    <ul class="optimization-list">
                        <li><i class="fas fa-check"></i> VATSIM data ingestion (every 15s)</li>
                        <li><i class="fas fa-check"></i> Route parsing with PostGIS spatial queries</li>
                        <li><i class="fas fa-check"></i> ARTCC/TRACON/Sector boundary detection</li>
                        <li><i class="fas fa-check"></i> Boundary crossing ETA prediction</li>
                        <li><i class="fas fa-check"></i> Waypoint ETA calculations</li>
                        <li><i class="fas fa-check"></i> SWIM WebSocket server (real-time events)</li>
                        <li><i class="fas fa-check"></i> SWIM data sync &amp; reverse sync</li>
                        <li><i class="fas fa-check"></i> SimTraffic data polling</li>
                        <li><i class="fas fa-check"></i> Scheduled task automation (splits/routes)</li>
                        <li><i class="fas fa-check"></i> Data archival &amp; trajectory tiering</li>
                        <li><i class="fas fa-check"></i> System health monitoring</li>
                        <li><i class="fas fa-check"></i> Discord TMI message queue processing</li>
                        <li><i class="fas fa-check"></i> VATSIM/VATUSA event sync</li>
                        <li><i class="fas fa-check"></i> ADL blob storage archival</li>
                    </ul>
                </div>
            </div>
            <div class="col-md-6">
                <div class="info-card">
                    <h4><i class="fas fa-tachometer-alt mr-2"></i><?= __('transparency.performanceOptimizations') ?></h4>
                    <ul class="optimization-list">
                        <li><i class="fas fa-check"></i> APCu in-memory caching</li>
                        <li><i class="fas fa-check"></i> Tiered cache TTLs by API tier</li>
                        <li><i class="fas fa-check"></i> ETag support for 304 responses</li>
                        <li><i class="fas fa-check"></i> Gzip compression for API responses</li>
                        <li><i class="fas fa-check"></i> CDN-ready Cache-Control headers</li>
                        <li><i class="fas fa-check"></i> Lazy-loaded database connections</li>
                        <li><i class="fas fa-check"></i> PERTI_MYSQL_ONLY flag (~98 endpoints skip Azure SQL)</li>
                        <li><i class="fas fa-check"></i> Parallel API loading on frontend (Promise.all)</li>
                        <li><i class="fas fa-check"></i> Optimized SQL indexes</li>
                        <li><i class="fas fa-check"></i> Tiered daemon processing (15s-5min by flight priority)</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>

    <!-- Scaling Strategy Section -->
    <div class="transparency-section">
        <h3><i class="fas fa-chart-line mr-2"></i><?= __('transparency.scalingStrategy') ?></h3>

        <p><?= __('transparency.scalingStrategyDesc') ?></p>

        <div class="info-card">
            <h4><i class="fas fa-database mr-2"></i><?= __('transparency.dbAutoScaling') ?></h4>
            <p>VATSIM_ADL uses Hyperscale Serverless with automatic scaling:</p>
            <ul>
                <li><strong>VATSIM_ADL:</strong> 3 - 16 vCores (auto-scales based on query load from 14 daemons)</li>
                <li><strong>Auto-pause:</strong> Disabled - VATSIM_ADL runs continuously to maintain real-time 15-second data freshness</li>
                <li><strong>HA Replica:</strong> 1 high-availability replica for read offloading and failover</li>
                <li><strong>VATSIM_STATS:</strong> General Purpose Serverless with auto-pause enabled (pauses when idle)</li>
            </ul>
            <p class="mb-0 text-muted">No action needed - databases automatically handle traffic spikes during CTP, FNO, and other events.</p>
        </div>

        <div class="scaling-scenario">
            <h5><span class="tier-badge tier-current">Current</span> <?= __('transparency.currentBaseline') ?></h5>
            <p class="mb-0">P1v2 App Service with 40 PHP-FPM workers handles normal VATSIM traffic. Estimated capacity: 40-80 requests/second at origin.</p>
        </div>

        <div class="scaling-scenario">
            <h5><span class="tier-badge tier-future">10x Traffic</span> <?= __('transparency.basicAdoption') ?></h5>
            <p class="mb-1"><strong>Solution:</strong> Add Azure CDN for API caching (~$5-10/month)</p>
            <p class="mb-0"><strong>Benefit:</strong> 80-90% of requests served from edge, reducing origin load</p>
        </div>

        <div class="scaling-scenario">
            <h5><span class="tier-badge tier-future">100x Traffic</span> <?= __('transparency.fullAdoption') ?></h5>
            <p class="mb-1"><strong>Solution:</strong> CDN + App Service autoscaling (1-3 instances)</p>
            <p class="mb-0"><strong>Benefit:</strong> Reserved Instance discount (30-50% savings on compute)</p>
        </div>

        <div class="scaling-scenario">
            <h5><span class="tier-badge tier-future">1000x Traffic</span> <?= __('transparency.heavyIntegrations') ?></h5>
            <p class="mb-1"><strong>Solution:</strong> CDN + Upgrade to P2v2 (7GB, 2 vCPU) + Autoscaling (1-5 instances)</p>
            <p class="mb-0"><strong>Note:</strong> Database layer already handles this scale via Hyperscale auto-scaling</p>
        </div>
    </div>

    <!-- Cost Optimization Section -->
    <div class="transparency-section">
        <h3><i class="fas fa-piggy-bank mr-2"></i><?= __('transparency.costOptimization') ?></h3>

        <div class="row">
            <div class="col-md-6">
                <div class="info-card">
                    <h4 class="status-implemented"><i class="fas fa-check-circle mr-2"></i><?= __('transparency.implemented') ?></h4>
                    <ul class="optimization-list">
                        <li><i class="fas fa-check"></i> APCu caching (80-90% cache hit rate)</li>
                        <li><i class="fas fa-check"></i> Gzip compression for large responses</li>
                        <li><i class="fas fa-check"></i> ETag support to reduce bandwidth</li>
                        <li><i class="fas fa-check"></i> CDN-friendly response headers</li>
                        <li><i class="fas fa-check"></i> Tiered cache TTLs by API access level</li>
                        <li><i class="fas fa-check"></i> Optimized PHP-FPM worker count (40)</li>
                        <li><i class="fas fa-check"></i> PERTI_MYSQL_ONLY: ~98 endpoints skip Azure SQL connections (~500-1000ms saved)</li>
                        <li><i class="fas fa-check"></i> Lazy-loaded database connections (on-demand getters)</li>
                        <li><i class="fas fa-check"></i> Parallel frontend API loading (Promise.all)</li>
                        <li><i class="fas fa-check"></i> Tiered daemon processing (15s-5min by flight priority)</li>
                        <li><i class="fas fa-check"></i> Trajectory archival tiering (live &rarr; archive &rarr; compressed)</li>
                    </ul>
                </div>
            </div>
            <div class="col-md-6">
                <div class="info-card">
                    <h4 class="status-planned"><i class="fas fa-clock mr-2"></i><?= __('transparency.availableWhenNeeded') ?></h4>
                    <ul class="optimization-list">
                        <li><i class="fas fa-hourglass-half"></i> Azure Reserved Instances (30-50% savings)</li>
                        <li><i class="fas fa-hourglass-half"></i> Azure CDN edge caching</li>
                        <li><i class="fas fa-hourglass-half"></i> Autoscaling during peak hours</li>
                        <li><i class="fas fa-hourglass-half"></i> Database query optimization indexes</li>
                    </ul>
                </div>
            </div>
        </div>

        <div class="note-box">
            <i class="fas fa-info-circle mr-2"></i>
            <strong><?= __('transparency.note') ?></strong> <?= __('transparency.costOptNote') ?>
        </div>
    </div>

    <!-- Traffic Capacity Section -->
    <div class="transparency-section">
        <h3><i class="fas fa-network-wired mr-2"></i><?= __('transparency.estimatedCapacity') ?></h3>

        <div class="info-card">
            <table class="cost-table">
                <thead>
                    <tr>
                        <th><?= __('transparency.configuration') ?></th>
                        <th><?= __('transparency.estRequestsPerSec') ?></th>
                        <th><?= __('transparency.notes') ?></th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>Origin only (current)</td>
                        <td>~40-80</td>
                        <td>Direct hits to App Service</td>
                    </tr>
                    <tr>
                        <td>With CDN (85% hit rate)</td>
                        <td>~300-500</td>
                        <td>Most requests served from edge</td>
                    </tr>
                    <tr>
                        <td>With CDN (95% hit rate)</td>
                        <td>~800-1600</td>
                        <td>Optimized caching rules</td>
                    </tr>
                </tbody>
            </table>
            <small class="text-muted">Estimates based on 40 PHP-FPM workers with 15-second data refresh cycle.</small>
        </div>
    </div>

</div>

</body>
<?php include('load/footer.php'); ?>
</html>
