<?php
/**
 * Infrastructure Transparency Page
 * Public disclosure of PERTI infrastructure costs and scaling plans
 */

include("sessions/handler.php");

if (session_status() == PHP_SESSION_NONE) {
    session_start();
    ob_start();
}

include("load/config.php");
include("load/connect.php");
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

<?php include('load/nav.php'); ?>

<!-- Hero Section -->
<section class="d-flex align-items-center position-relative bg-position-center fh-section overflow-hidden pt-6 jarallax bg-dark text-light" data-jarallax data-speed="0.3">
    <div class="container-fluid pt-2 pb-5 py-lg-6">
        <img class="jarallax-img" src="assets/img/jumbotron/main.png" alt="" style="opacity: 50%;">
        <center>
            <h1>Infrastructure Transparency</h1>
            <h4 class="text-white">Open disclosure of PERTI's operational costs and scaling strategy</h4>
        </center>
    </div>
</section>

<!-- Main Content -->
<div class="container mt-5 mb-5">

    <!-- Current Costs Section -->
    <div class="transparency-section">
        <h3><i class="fas fa-dollar-sign mr-2"></i>Current Monthly Costs</h3>

        <p class="lead">PERTI operates on Microsoft Azure infrastructure. Below is a transparent breakdown of our current operational costs.</p>

        <div class="info-card">
            <h4>Infrastructure Components</h4>
            <table class="cost-table">
                <thead>
                    <tr>
                        <th>Component</th>
                        <th>Tier/Size</th>
                        <th>Purpose</th>
                        <th>Est. Cost/Month</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><strong>Azure App Service</strong></td>
                        <td><span class="tier-badge tier-current">P1v2</span><br><small>3.5 GB RAM, 1 vCPU</small></td>
                        <td>Web hosting, PHP-FPM workers, background daemons</td>
                        <td>~$81</td>
                    </tr>
                    <tr>
                        <td><strong>VATSIM_ADL Database</strong></td>
                        <td>Serverless</td>
                        <td>Active flight data, real-time positions, parsed routes</td>
                        <td>~$5-50*</td>
                    </tr>
                    <tr>
                        <td><strong>SWIM_API Database</strong></td>
                        <td>Basic (2 GB)</td>
                        <td>SWIM API data, flight events, API key management</td>
                        <td>~$5</td>
                    </tr>
                    <tr>
                        <td><strong>VATSIM_REF Database</strong></td>
                        <td>Basic (2 GB)</td>
                        <td>Reference data (airports, airways, fixes, procedures)</td>
                        <td>~$5</td>
                    </tr>
                    <tr class="cost-total">
                        <td colspan="3"><strong>Estimated Total</strong></td>
                        <td><strong>~$96-141/month</strong></td>
                    </tr>
                </tbody>
            </table>
            <small class="text-muted">* Serverless pricing varies based on compute usage. Higher during peak VATSIM hours (1800-0200 UTC).</small>
        </div>
    </div>

    <!-- What You Get Section -->
    <div class="transparency-section">
        <h3><i class="fas fa-server mr-2"></i>What This Infrastructure Provides</h3>

        <div class="metric-grid">
            <div class="metric-box">
                <div class="metric-value">40</div>
                <div class="metric-label">PHP-FPM Workers</div>
            </div>
            <div class="metric-box">
                <div class="metric-value">15s</div>
                <div class="metric-label">Data Refresh Rate</div>
            </div>
            <div class="metric-box">
                <div class="metric-value">8</div>
                <div class="metric-label">Background Daemons</div>
            </div>
            <div class="metric-box">
                <div class="metric-value">24/7</div>
                <div class="metric-label">Availability</div>
            </div>
        </div>

        <div class="row">
            <div class="col-md-6">
                <div class="info-card">
                    <h4><i class="fas fa-cogs mr-2"></i>Background Services</h4>
                    <ul class="optimization-list">
                        <li><i class="fas fa-check"></i> VATSIM data ingestion (every 15s)</li>
                        <li><i class="fas fa-check"></i> Route parsing & validation</li>
                        <li><i class="fas fa-check"></i> ARTCC/TRACON boundary detection</li>
                        <li><i class="fas fa-check"></i> Waypoint ETA calculations</li>
                        <li><i class="fas fa-check"></i> SWIM WebSocket server</li>
                        <li><i class="fas fa-check"></i> Scheduled task automation</li>
                        <li><i class="fas fa-check"></i> Data archival & cleanup</li>
                        <li><i class="fas fa-check"></i> System health monitoring</li>
                    </ul>
                </div>
            </div>
            <div class="col-md-6">
                <div class="info-card">
                    <h4><i class="fas fa-tachometer-alt mr-2"></i>Performance Optimizations</h4>
                    <ul class="optimization-list">
                        <li><i class="fas fa-check"></i> APCu in-memory caching</li>
                        <li><i class="fas fa-check"></i> Tiered cache TTLs by API tier</li>
                        <li><i class="fas fa-check"></i> ETag support for 304 responses</li>
                        <li><i class="fas fa-check"></i> Gzip compression for API responses</li>
                        <li><i class="fas fa-check"></i> CDN-ready Cache-Control headers</li>
                        <li><i class="fas fa-check"></i> Database connection pooling</li>
                        <li><i class="fas fa-check"></i> Optimized SQL indexes</li>
                        <li><i class="fas fa-check"></i> Request coalescing</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>

    <!-- Scaling Strategy Section -->
    <div class="transparency-section">
        <h3><i class="fas fa-chart-line mr-2"></i>Scaling Strategy</h3>

        <p>As SWIM API adoption grows, we have a clear cost-optimized scaling path. Our approach prioritizes efficiency over raw capacity.</p>

        <div class="scaling-scenario">
            <h5><span class="tier-badge tier-current">Current</span> Baseline Traffic</h5>
            <p class="mb-0">Current P1v2 tier handles normal VATSIM traffic with ~1GB memory headroom. Estimated capacity: 40-80 requests/second at origin.</p>
        </div>

        <div class="scaling-scenario">
            <h5><span class="tier-badge tier-future">10x Traffic</span> Basic SWIM Adoption</h5>
            <p class="mb-1"><strong>Solution:</strong> Add Azure CDN (~$5-10/month)</p>
            <p class="mb-0"><strong>Total estimated cost:</strong> ~$105-150/month</p>
        </div>

        <div class="scaling-scenario">
            <h5><span class="tier-badge tier-future">100x Traffic</span> Full SWIM Adoption</h5>
            <p class="mb-1"><strong>Solution:</strong> CDN + Autoscaling (1-3 instances) + Reserved Instance discount</p>
            <p class="mb-0"><strong>Total estimated cost:</strong> ~$80-120/month (with 1-year reservation)</p>
        </div>

        <div class="scaling-scenario">
            <h5><span class="tier-badge tier-future">1000x Traffic</span> Heavy External Integrations</h5>
            <p class="mb-1"><strong>Solution:</strong> CDN + Upgrade to P2v2 + Autoscaling (1-5 instances) + Reserved Instance</p>
            <p class="mb-0"><strong>Total estimated cost:</strong> ~$200-400/month</p>
        </div>
    </div>

    <!-- Cost Optimization Section -->
    <div class="transparency-section">
        <h3><i class="fas fa-piggy-bank mr-2"></i>Cost Optimization Measures</h3>

        <div class="row">
            <div class="col-md-6">
                <div class="info-card">
                    <h4 class="status-implemented"><i class="fas fa-check-circle mr-2"></i>Implemented</h4>
                    <ul class="optimization-list">
                        <li><i class="fas fa-check"></i> APCu caching (80-90% cache hit rate)</li>
                        <li><i class="fas fa-check"></i> Gzip compression for large responses</li>
                        <li><i class="fas fa-check"></i> ETag support to reduce bandwidth</li>
                        <li><i class="fas fa-check"></i> CDN-friendly response headers</li>
                        <li><i class="fas fa-check"></i> Tiered cache TTLs by API access level</li>
                        <li><i class="fas fa-check"></i> Optimized PHP-FPM worker count</li>
                    </ul>
                </div>
            </div>
            <div class="col-md-6">
                <div class="info-card">
                    <h4 class="status-planned"><i class="fas fa-clock mr-2"></i>Available When Needed</h4>
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
            <strong>Note:</strong> We deliberately avoid over-provisioning. Our philosophy is to scale reactively based on actual demand rather than paying for unused capacity.
        </div>
    </div>

    <!-- Traffic Capacity Section -->
    <div class="transparency-section">
        <h3><i class="fas fa-network-wired mr-2"></i>Estimated Capacity</h3>

        <div class="info-card">
            <table class="cost-table">
                <thead>
                    <tr>
                        <th>Configuration</th>
                        <th>Est. Requests/Second</th>
                        <th>Notes</th>
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

    <!-- Open Source Note -->
    <div class="transparency-section">
        <h3><i class="fas fa-code-branch mr-2"></i>Open Development</h3>

        <div class="info-card">
            <p>PERTI is developed as a community resource for VATSIM. This transparency page reflects our commitment to open operations.</p>
            <p class="mb-0">Questions about infrastructure or costs? Contact the development team through official VATSIM channels.</p>
        </div>
    </div>

</div>

</body>
<?php include('load/footer.php'); ?>
</html>
