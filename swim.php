<?php
/**
 * VATSIM SWIM API - Reference & Announcement Page
 *
 * Landing page for the SWIM (System Wide Information Management) API.
 * Includes release announcement, capabilities overview, getting started guide,
 * and use case documentation by role.
 *
 * @package PERTI
 * @subpackage SWIM
 * @version 1.0.0
 */

include("sessions/handler.php");

if (session_status() == PHP_SESSION_NONE) {
    session_start();
    ob_start();
}

include("load/config.php");
include("load/connect.php");

// Check if user is logged in
$logged_in = isset($_SESSION['VATSIM_CID']) && !empty($_SESSION['VATSIM_CID']);
?>

<!DOCTYPE html>
<html>
<head>
    <?php
        $page_title = "SWIM API - PERTI";
        include("load/header.php");
    ?>
    <style>
        /* SWIM Reference Page Styling */

        /* Hero section enhancement */
        .swim-hero {
            background: linear-gradient(135deg, #1a1a2e 0%, #16213e 50%, #0f3460 100%);
            position: relative;
            overflow: hidden;
        }
        .swim-hero::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><circle cx="50" cy="50" r="1" fill="%234a9eff" opacity="0.3"/></svg>') repeat;
            background-size: 50px 50px;
            animation: moveBackground 20s linear infinite;
            opacity: 0.5;
        }
        @keyframes moveBackground {
            0% { transform: translate(0, 0); }
            100% { transform: translate(50px, 50px); }
        }
        .swim-hero-content {
            position: relative;
            z-index: 1;
        }
        .swim-badge {
            display: inline-block;
            background: linear-gradient(135deg, #00ff88, #00cc6a);
            color: #1a1a2e;
            padding: 6px 16px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            margin-bottom: 15px;
        }
        .swim-title {
            font-size: 3rem;
            font-weight: 700;
            color: #fff;
            margin-bottom: 10px;
            text-shadow: 0 2px 10px rgba(0,0,0,0.3);
        }
        .swim-title span {
            color: #4a9eff;
        }
        .swim-subtitle {
            font-size: 1.3rem;
            color: #b0bec5;
            margin-bottom: 25px;
        }
        .swim-version {
            color: #78909c;
            font-size: 0.9rem;
        }
        .swim-version strong {
            color: #4a9eff;
        }

        /* Announcement banner */
        .announcement-banner {
            background: linear-gradient(90deg, #00c853, #00e676);
            color: #1a1a2e;
            padding: 12px 20px;
            border-radius: 8px;
            margin-bottom: 30px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 12px;
            box-shadow: 0 4px 15px rgba(0,200,83,0.3);
        }
        .announcement-banner i {
            font-size: 1.4rem;
        }
        .announcement-banner strong {
            font-size: 1.05rem;
        }

        /* Feature cards */
        .feature-card {
            background: #fff;
            border-radius: 12px;
            padding: 25px;
            height: 100%;
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
            border: 1px solid #e9ecef;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .feature-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.12);
        }
        .feature-icon {
            width: 60px;
            height: 60px;
            background: linear-gradient(135deg, #4a9eff 0%, #667eea 100%);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 18px;
        }
        .feature-icon i {
            font-size: 1.6rem;
            color: #fff;
        }
        .feature-card h5 {
            color: #1a1a2e;
            font-weight: 600;
            margin-bottom: 10px;
        }
        .feature-card p {
            color: #6c757d;
            font-size: 0.9rem;
            margin-bottom: 0;
        }

        /* Section styling */
        .section-header {
            text-align: center;
            margin-bottom: 40px;
        }
        .section-header h2 {
            color: #1a1a2e;
            font-weight: 700;
            margin-bottom: 10px;
        }
        .section-header p {
            color: #6c757d;
            font-size: 1.1rem;
            max-width: 600px;
            margin: 0 auto;
        }
        .section-divider {
            border-top: 2px solid #e9ecef;
            margin: 50px 0;
        }

        /* Getting started cards */
        .getting-started-step {
            background: #f8f9fa;
            border-radius: 12px;
            padding: 25px;
            position: relative;
            height: 100%;
        }
        .step-number {
            position: absolute;
            top: -15px;
            left: 20px;
            width: 40px;
            height: 40px;
            background: linear-gradient(135deg, #4a9eff 0%, #667eea 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            color: #fff;
            font-size: 1.1rem;
            box-shadow: 0 4px 10px rgba(74,158,255,0.3);
        }
        .getting-started-step h5 {
            margin-top: 15px;
            color: #1a1a2e;
            font-weight: 600;
        }
        .getting-started-step p {
            color: #6c757d;
            font-size: 0.9rem;
        }

        /* Code blocks */
        .code-block {
            background: #1a1a2e;
            border-radius: 8px;
            padding: 20px;
            overflow-x: auto;
            margin: 15px 0;
        }
        .code-block pre {
            margin: 0;
            color: #e0e0e0;
            font-family: 'Inconsolata', 'Courier New', monospace;
            font-size: 0.85rem;
            line-height: 1.5;
        }
        .code-block .keyword { color: #c792ea; }
        .code-block .string { color: #c3e88d; }
        .code-block .comment { color: #546e7a; }
        .code-block .variable { color: #f07178; }
        .code-block .function { color: #82aaff; }

        /* Use case tabs */
        .use-case-tabs .nav-link {
            color: #6c757d;
            border: none;
            padding: 12px 20px;
            font-weight: 500;
            border-radius: 8px 8px 0 0;
            background: #f8f9fa;
            margin-right: 4px;
        }
        .use-case-tabs .nav-link:hover {
            color: #4a9eff;
            background: #e9ecef;
        }
        .use-case-tabs .nav-link.active {
            color: #fff;
            background: linear-gradient(135deg, #4a9eff 0%, #667eea 100%);
        }
        .use-case-content {
            background: #fff;
            border: 1px solid #e9ecef;
            border-radius: 0 12px 12px 12px;
            padding: 30px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.05);
        }
        .use-case-content h5 {
            color: #1a1a2e;
            font-weight: 600;
            margin-bottom: 15px;
        }
        .use-case-content ul {
            padding-left: 20px;
        }
        .use-case-content li {
            margin-bottom: 8px;
            color: #495057;
        }

        /* Endpoint table */
        .endpoint-table {
            background: #fff;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 4px 15px rgba(0,0,0,0.05);
        }
        .endpoint-table th {
            background: linear-gradient(180deg, #3a4a5c 0%, #2c3e50 100%);
            color: #fff;
            font-weight: 600;
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 0.03em;
            padding: 12px 15px;
        }
        .endpoint-table td {
            padding: 12px 15px;
            border-bottom: 1px solid #e9ecef;
            vertical-align: middle;
        }
        .endpoint-table code {
            background: #e9ecef;
            color: #1a1a2e;
            padding: 3px 8px;
            border-radius: 4px;
            font-size: 0.85rem;
        }
        .method-badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 4px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
        }
        .method-get { background: #4a9eff; color: #fff; }
        .method-post { background: #00c853; color: #fff; }

        /* Tier comparison table */
        .tier-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
        }
        .tier-table th, .tier-table td {
            padding: 12px 15px;
            text-align: center;
            border-bottom: 1px solid #e9ecef;
        }
        .tier-table th {
            background: #f8f9fa;
            font-weight: 600;
            color: #1a1a2e;
        }
        .tier-table th:first-child, .tier-table td:first-child {
            text-align: left;
        }
        .tier-header {
            padding: 8px 12px;
            border-radius: 6px;
            font-weight: 600;
            font-size: 0.85rem;
        }
        .tier-public { background: #e8f5e9; color: #2e7d32; }
        .tier-developer { background: #e3f2fd; color: #1565c0; }
        .tier-partner { background: #fff3e0; color: #ef6c00; }
        .tier-system { background: #ffebee; color: #c62828; }

        /* SDK cards */
        .sdk-card {
            background: #fff;
            border: 1px solid #e9ecef;
            border-radius: 10px;
            padding: 20px;
            text-align: center;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .sdk-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 20px rgba(0,0,0,0.1);
        }
        .sdk-card i {
            font-size: 2.5rem;
            margin-bottom: 12px;
        }
        .sdk-card h6 {
            color: #1a1a2e;
            font-weight: 600;
            margin-bottom: 5px;
        }
        .sdk-card small {
            color: #6c757d;
        }
        .sdk-python i { color: #3776ab; }
        .sdk-javascript i { color: #f7df1e; }
        .sdk-csharp i { color: #68217a; }
        .sdk-java i { color: #ed8b00; }

        /* CTA section */
        .cta-section {
            background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%);
            border-radius: 16px;
            padding: 50px 40px;
            text-align: center;
            color: #fff;
        }
        .cta-section h3 {
            font-weight: 700;
            margin-bottom: 15px;
        }
        .cta-section p {
            color: #b0bec5;
            font-size: 1.1rem;
            margin-bottom: 25px;
        }
        .cta-section .btn {
            padding: 12px 30px;
            font-weight: 600;
            border-radius: 8px;
            margin: 5px;
        }

        /* Resources grid */
        .resource-link {
            display: flex;
            align-items: center;
            background: #f8f9fa;
            border-radius: 10px;
            padding: 18px 20px;
            text-decoration: none;
            color: #1a1a2e;
            transition: background 0.2s, transform 0.2s;
            height: 100%;
        }
        .resource-link:hover {
            background: #e9ecef;
            transform: translateX(5px);
            text-decoration: none;
            color: #1a1a2e;
        }
        .resource-link i {
            font-size: 1.5rem;
            color: #4a9eff;
            margin-right: 15px;
            width: 40px;
            text-align: center;
        }
        .resource-link span {
            font-weight: 500;
        }
        .resource-link small {
            display: block;
            color: #6c757d;
            font-size: 0.8rem;
        }

        /* Responsive adjustments */
        @media (max-width: 768px) {
            .swim-title {
                font-size: 2rem;
            }
            .swim-subtitle {
                font-size: 1rem;
            }
            .feature-card {
                margin-bottom: 20px;
            }
        }
    </style>
</head>

<body>

<?php include('load/nav.php'); ?>

<!-- Hero Section -->
<section class="swim-hero py-5">
    <div class="container swim-hero-content">
        <div class="row align-items-center">
            <div class="col-lg-8 offset-lg-2 text-center py-5">
                <div class="swim-badge">
                    <i class="fas fa-rocket mr-1"></i> Now Available
                </div>
                <h1 class="swim-title">VATSIM <span>SWIM</span> API</h1>
                <p class="swim-subtitle">System Wide Information Management for the VATSIM Network</p>
                <p class="swim-version">
                    <strong>Version 1.0.0</strong> &bull; Released January 2026 &bull; Production Ready
                </p>
                <div class="mt-4">
                    <a href="swim-keys" class="btn btn-success btn-lg mr-2">
                        <i class="fas fa-key mr-2"></i>Get API Key
                    </a>
                    <a href="docs/swim/" class="btn btn-outline-light btn-lg" target="_blank">
                        <i class="fas fa-book mr-2"></i>View Documentation
                    </a>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Main Content -->
<div class="container py-5">

    <!-- Announcement Banner -->
    <div class="announcement-banner">
        <i class="fas fa-bullhorn"></i>
        <strong>VATSIM SWIM API v1.0.0 is now publicly available!</strong>
        <span>Real-time flight data, positions, and TMI information for the entire VATSIM network.</span>
    </div>

    <!-- What is SWIM Section -->
    <div class="section-header">
        <h2>What is VATSIM SWIM?</h2>
        <p>A centralized real-time data exchange hub providing unified access to flight information across the entire VATSIM network.</p>
    </div>

    <div class="row mb-5">
        <div class="col-md-6 col-lg-3 mb-4">
            <div class="feature-card">
                <div class="feature-icon">
                    <i class="fas fa-bolt"></i>
                </div>
                <h5>REST API</h5>
                <p>Query flights, positions, and TMI data with powerful filtering and pagination support.</p>
            </div>
        </div>
        <div class="col-md-6 col-lg-3 mb-4">
            <div class="feature-card">
                <div class="feature-icon">
                    <i class="fas fa-broadcast-tower"></i>
                </div>
                <h5>WebSocket</h5>
                <p>Real-time event streaming for departures, arrivals, and position updates with sub-second latency.</p>
            </div>
        </div>
        <div class="col-md-6 col-lg-3 mb-4">
            <div class="feature-card">
                <div class="feature-icon">
                    <i class="fas fa-map-marked-alt"></i>
                </div>
                <h5>GeoJSON Output</h5>
                <p>Position data ready for direct rendering on MapLibre, Leaflet, or Mapbox maps.</p>
            </div>
        </div>
        <div class="col-md-6 col-lg-3 mb-4">
            <div class="feature-card">
                <div class="feature-icon">
                    <i class="fas fa-traffic-light"></i>
                </div>
                <h5>TMI Data</h5>
                <p>Access Ground Stops, GDPs, EDCT assignments, and controlled flight information.</p>
            </div>
        </div>
    </div>

    <div class="section-divider"></div>

    <!-- Getting Started Section -->
    <div class="section-header">
        <h2>Getting Started</h2>
        <p>Start integrating with SWIM in three simple steps.</p>
    </div>

    <div class="row mb-5">
        <div class="col-md-4 mb-4">
            <div class="getting-started-step">
                <div class="step-number">1</div>
                <h5>Create Your API Key</h5>
                <p>Visit the <a href="swim-keys">API Key Management Portal</a> and log in with your VATSIM account to create a key instantly.</p>
                <a href="swim-keys" class="btn btn-primary btn-sm mt-2">
                    <i class="fas fa-key mr-1"></i> Get API Key
                </a>
            </div>
        </div>
        <div class="col-md-4 mb-4">
            <div class="getting-started-step">
                <div class="step-number">2</div>
                <h5>Make Your First Request</h5>
                <p>Include your API key in the Authorization header and query the flights endpoint.</p>
                <div class="code-block mt-3" style="font-size: 11px;">
                    <pre><span class="keyword">curl</span> -H <span class="string">"Authorization: Bearer YOUR_KEY"</span> \
  https://perti.vatcscc.org/api/swim/v1/flights</pre>
                </div>
            </div>
        </div>
        <div class="col-md-4 mb-4">
            <div class="getting-started-step">
                <div class="step-number">3</div>
                <h5>Explore the Docs</h5>
                <p>Read the full API documentation to discover all available endpoints, filters, and WebSocket events.</p>
                <a href="docs/swim/" class="btn btn-outline-primary btn-sm mt-2" target="_blank">
                    <i class="fas fa-book mr-1"></i> View Documentation
                </a>
            </div>
        </div>
    </div>

    <div class="section-divider"></div>

    <!-- API Access Tiers -->
    <div class="section-header">
        <h2>API Access Tiers</h2>
        <p>Choose the tier that fits your needs. Public and Developer keys are available instantly.</p>
    </div>

    <div class="table-responsive mb-5">
        <table class="tier-table">
            <thead>
                <tr>
                    <th>Feature</th>
                    <th><span class="tier-header tier-public">Public</span></th>
                    <th><span class="tier-header tier-developer">Developer</span></th>
                    <th><span class="tier-header tier-partner">Partner</span></th>
                    <th><span class="tier-header tier-system">System</span></th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td><strong>Rate Limit</strong></td>
                    <td>30 req/min</td>
                    <td>100 req/min</td>
                    <td>1,000 req/min</td>
                    <td>10,000 req/min</td>
                </tr>
                <tr>
                    <td><strong>WebSocket Connections</strong></td>
                    <td>5</td>
                    <td>50</td>
                    <td>500</td>
                    <td>10,000</td>
                </tr>
                <tr>
                    <td><strong>Write Access</strong></td>
                    <td><i class="fas fa-times text-danger"></i></td>
                    <td><i class="fas fa-times text-danger"></i></td>
                    <td><i class="fas fa-check text-warning"></i> Limited</td>
                    <td><i class="fas fa-check text-success"></i> Full</td>
                </tr>
                <tr>
                    <td><strong>Self-Service</strong></td>
                    <td><i class="fas fa-check text-success"></i></td>
                    <td><i class="fas fa-check text-success"></i></td>
                    <td><i class="fas fa-times text-muted"></i> Contact Us</td>
                    <td><i class="fas fa-times text-muted"></i> Contact Us</td>
                </tr>
                <tr>
                    <td><strong>Best For</strong></td>
                    <td>Personal projects</td>
                    <td>Development & testing</td>
                    <td>Virtual airlines</td>
                    <td>vNAS, CRC, SimTraffic</td>
                </tr>
            </tbody>
        </table>
    </div>

    <div class="section-divider"></div>

    <!-- API Endpoints -->
    <div class="section-header">
        <h2>API Endpoints</h2>
        <p>Complete REST API for querying flight data and ingesting updates.</p>
    </div>

    <div class="endpoint-table mb-5">
        <table class="table mb-0">
            <thead>
                <tr>
                    <th style="width: 100px;">Method</th>
                    <th>Endpoint</th>
                    <th>Description</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td><span class="method-badge method-get">GET</span></td>
                    <td><code>/api/swim/v1/flights</code></td>
                    <td>List flights with filtering by airport, ARTCC, callsign, phase, and TMI status</td>
                </tr>
                <tr>
                    <td><span class="method-badge method-get">GET</span></td>
                    <td><code>/api/swim/v1/flight</code></td>
                    <td>Get single flight by GUFI, flight_uid, or flight_key</td>
                </tr>
                <tr>
                    <td><span class="method-badge method-get">GET</span></td>
                    <td><code>/api/swim/v1/positions</code></td>
                    <td>Bulk flight positions in GeoJSON FeatureCollection format</td>
                </tr>
                <tr>
                    <td><span class="method-badge method-get">GET</span></td>
                    <td><code>/api/swim/v1/tmi/programs</code></td>
                    <td>Active TMI programs (Ground Stops, GDPs)</td>
                </tr>
                <tr>
                    <td><span class="method-badge method-get">GET</span></td>
                    <td><code>/api/swim/v1/tmi/controlled</code></td>
                    <td>Flights under TMI control with EDCT/slot assignments</td>
                </tr>
                <tr>
                    <td><span class="method-badge method-post">POST</span></td>
                    <td><code>/api/swim/v1/ingest/adl</code></td>
                    <td>Ingest flight data (OOOI times, ETAs) — Partner+ tier</td>
                </tr>
                <tr>
                    <td><span class="method-badge method-post">POST</span></td>
                    <td><code>/api/swim/v1/ingest/track</code></td>
                    <td>Ingest track/position updates — System tier</td>
                </tr>
            </tbody>
        </table>
    </div>

    <div class="section-divider"></div>

    <!-- Use Cases by Role -->
    <div class="section-header">
        <h2>Use Cases by Role</h2>
        <p>SWIM serves different needs across the VATSIM ecosystem.</p>
    </div>

    <ul class="nav nav-tabs use-case-tabs mb-0" id="useCaseTabs" role="tablist">
        <li class="nav-item">
            <a class="nav-link active" id="va-tab" data-toggle="tab" href="#va-content" role="tab">
                <i class="fas fa-plane mr-1"></i> Virtual Airlines
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link" id="dev-tab" data-toggle="tab" href="#dev-content" role="tab">
                <i class="fas fa-code mr-1"></i> Developers
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link" id="atc-tab" data-toggle="tab" href="#atc-content" role="tab">
                <i class="fas fa-headset mr-1"></i> ATC Facilities
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link" id="pilot-tab" data-toggle="tab" href="#pilot-content" role="tab">
                <i class="fas fa-user-pilot mr-1"></i> Pilots
            </a>
        </li>
    </ul>
    <div class="tab-content use-case-content mb-5" id="useCaseTabContent">
        <!-- Virtual Airlines Tab -->
        <div class="tab-pane fade show active" id="va-content" role="tabpanel">
            <div class="row">
                <div class="col-md-6">
                    <h5><i class="fas fa-check-circle text-success mr-2"></i>Key Capabilities</h5>
                    <ul>
                        <li>Real-time fleet tracking via WebSocket</li>
                        <li>Push OOOI times from ACARS/AOC systems</li>
                        <li>Receive TMI notifications affecting your flights</li>
                        <li>Export flight data for on-time performance analysis</li>
                        <li>Integrate schedule data (STD/STA) per CDM specs</li>
                    </ul>
                </div>
                <div class="col-md-6">
                    <h5><i class="fas fa-terminal text-primary mr-2"></i>Example Queries</h5>
                    <div class="code-block">
                        <pre><span class="comment"># Track all flights with your prefix</span>
GET /flights?callsign=DAL*

<span class="comment"># Get TMI-controlled flights to your hub</span>
GET /tmi/controlled?dest=KATL

<span class="comment"># Push OOOI times (Partner tier)</span>
POST /ingest/adl
{<span class="string">"flights"</span>: [{<span class="string">"callsign"</span>: <span class="string">"DAL123"</span>, <span class="string">"off_utc"</span>: <span class="string">"..."</span>}]}</pre>
                    </div>
                </div>
            </div>
        </div>
        <!-- Developers Tab -->
        <div class="tab-pane fade" id="dev-content" role="tabpanel">
            <div class="row">
                <div class="col-md-6">
                    <h5><i class="fas fa-check-circle text-success mr-2"></i>Key Capabilities</h5>
                    <ul>
                        <li>Build flight tracking applications</li>
                        <li>Create statistics dashboards and analytics tools</li>
                        <li>Integrate live flight data into existing software</li>
                        <li>Stream real-time positions via WebSocket</li>
                        <li>Access GeoJSON-formatted data for mapping</li>
                    </ul>
                </div>
                <div class="col-md-6">
                    <h5><i class="fas fa-terminal text-primary mr-2"></i>Example Code</h5>
                    <div class="code-block">
                        <pre><span class="comment">// JavaScript - WebSocket subscription</span>
<span class="keyword">const</span> ws = <span class="keyword">new</span> <span class="function">WebSocket</span>(<span class="string">'wss://perti.vatcscc.org/api/swim/v1/ws'</span>);

ws.<span class="function">onopen</span> = () => {
  ws.<span class="function">send</span>(JSON.<span class="function">stringify</span>({
    action: <span class="string">'subscribe'</span>,
    channels: [<span class="string">'flight.positions'</span>],
    filters: { airports: [<span class="string">'KJFK'</span>] }
  }));
};</pre>
                    </div>
                </div>
            </div>
        </div>
        <!-- ATC Facilities Tab -->
        <div class="tab-pane fade" id="atc-content" role="tabpanel">
            <div class="row">
                <div class="col-md-6">
                    <h5><i class="fas fa-check-circle text-success mr-2"></i>Key Capabilities</h5>
                    <ul>
                        <li>Monitor sector traffic loads in real-time</li>
                        <li>Track TMI-controlled flights and EDCT compliance</li>
                        <li>Access demand forecasts for arrival/departure planning</li>
                        <li>Push track data from radar simulation (System tier)</li>
                        <li>Integrate with metering systems</li>
                    </ul>
                </div>
                <div class="col-md-6">
                    <h5><i class="fas fa-terminal text-primary mr-2"></i>Example Queries</h5>
                    <div class="code-block">
                        <pre><span class="comment"># All flights in your ARTCC</span>
GET /flights?artcc=ZDC

<span class="comment"># Flights destined to your airspace</span>
GET /flights?fp_dest_artcc=ZNY

<span class="comment"># Active TMI programs</span>
GET /tmi/programs

<span class="comment"># Controlled flights at your airport</span>
GET /tmi/controlled?airport=KJFK</pre>
                    </div>
                </div>
            </div>
        </div>
        <!-- Pilots Tab -->
        <div class="tab-pane fade" id="pilot-content" role="tabpanel">
            <div class="row">
                <div class="col-md-6">
                    <h5><i class="fas fa-check-circle text-success mr-2"></i>Key Capabilities</h5>
                    <ul>
                        <li>Look up any active flight on VATSIM</li>
                        <li>Check if your flight is affected by ground stops</li>
                        <li>View real-time positions and ETAs</li>
                        <li>Build personal flight tracking displays</li>
                        <li>Monitor traffic at your destination</li>
                    </ul>
                </div>
                <div class="col-md-6">
                    <h5><i class="fas fa-terminal text-primary mr-2"></i>Example Queries</h5>
                    <div class="code-block">
                        <pre><span class="comment"># Find your flight</span>
GET /flights?callsign=N172SP

<span class="comment"># Check ground stops at destination</span>
GET /tmi/programs?airport=KJFK

<span class="comment"># Traffic at your destination</span>
GET /flights?dest_icao=KORD&phase=enroute

<span class="comment"># Flights in a geographic area</span>
GET /positions?bounds=-76,39,-72,42</pre>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="section-divider"></div>

    <!-- Client SDKs -->
    <div class="section-header">
        <h2>Client SDKs</h2>
        <p>Official client libraries for popular programming languages.</p>
    </div>

    <div class="row mb-5">
        <div class="col-6 col-md-3 mb-3">
            <div class="sdk-card sdk-python">
                <i class="fab fa-python"></i>
                <h6>Python</h6>
                <small>Async WebSocket support</small>
            </div>
        </div>
        <div class="col-6 col-md-3 mb-3">
            <div class="sdk-card sdk-javascript">
                <i class="fab fa-js-square"></i>
                <h6>JavaScript</h6>
                <small>TypeScript included</small>
            </div>
        </div>
        <div class="col-6 col-md-3 mb-3">
            <div class="sdk-card sdk-csharp">
                <i class="fab fa-microsoft"></i>
                <h6>C# / .NET</h6>
                <small>NuGet package</small>
            </div>
        </div>
        <div class="col-6 col-md-3 mb-3">
            <div class="sdk-card sdk-java">
                <i class="fab fa-java"></i>
                <h6>Java</h6>
                <small>Maven artifact</small>
            </div>
        </div>
    </div>

    <div class="section-divider"></div>

    <!-- Resources -->
    <div class="section-header">
        <h2>Resources</h2>
        <p>Everything you need to get started with SWIM.</p>
    </div>

    <div class="row mb-5">
        <div class="col-md-6 col-lg-4 mb-3">
            <a href="swim-keys" class="resource-link">
                <i class="fas fa-key"></i>
                <div>
                    <span>API Key Portal</span>
                    <small>Create and manage your API keys</small>
                </div>
            </a>
        </div>
        <div class="col-md-6 col-lg-4 mb-3">
            <a href="docs/swim/" class="resource-link" target="_blank">
                <i class="fas fa-book"></i>
                <div>
                    <span>Interactive API Docs</span>
                    <small>Swagger UI with Try It Out</small>
                </div>
            </a>
        </div>
        <div class="col-md-6 col-lg-4 mb-3">
            <a href="docs/swim/VATSIM_SWIM_Release_Documentation.md" class="resource-link" target="_blank">
                <i class="fas fa-file-alt"></i>
                <div>
                    <span>Full Documentation</span>
                    <small>Comprehensive reference guide</small>
                </div>
            </a>
        </div>
        <div class="col-md-6 col-lg-4 mb-3">
            <a href="docs/swim/openapi.yaml" class="resource-link" target="_blank">
                <i class="fas fa-code"></i>
                <div>
                    <span>OpenAPI Spec</span>
                    <small>Machine-readable API definition</small>
                </div>
            </a>
        </div>
        <div class="col-md-6 col-lg-4 mb-3">
            <a href="https://github.com/vatcscc/swim-sdk" class="resource-link" target="_blank">
                <i class="fab fa-github"></i>
                <div>
                    <span>SDK Repository</span>
                    <small>Client libraries and examples</small>
                </div>
            </a>
        </div>
        <div class="col-md-6 col-lg-4 mb-3">
            <a href="mailto:dev@vatcscc.org" class="resource-link">
                <i class="fas fa-envelope"></i>
                <div>
                    <span>Contact Support</span>
                    <small>dev@vatcscc.org</small>
                </div>
            </a>
        </div>
    </div>

    <!-- CTA Section -->
    <div class="cta-section">
        <h3>Ready to Build with SWIM?</h3>
        <p>Create your API key in seconds and start integrating real-time VATSIM flight data today.</p>
        <a href="swim-keys" class="btn btn-success">
            <i class="fas fa-key mr-2"></i>Get Your API Key
        </a>
        <a href="docs/swim/" class="btn btn-outline-light" target="_blank">
            <i class="fas fa-book mr-2"></i>Read the Docs
        </a>
    </div>

</div>

<?php include('load/footer.php'); ?>

<script>
$(document).ready(function() {
    // Smooth scroll for anchor links
    $('a[href^="#"]').on('click', function(e) {
        e.preventDefault();
        var target = $(this.hash);
        if (target.length) {
            $('html, body').animate({
                scrollTop: target.offset().top - 80
            }, 500);
        }
    });
});
</script>

</body>
</html>
