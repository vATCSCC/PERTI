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
        /* SWIM Reference Page - Technical/Functional Styling */

        /* Section styling */
        .section-header {
            margin-bottom: 30px;
        }
        .section-header h2 {
            color: #1a1a2e;
            font-weight: 600;
            margin-bottom: 8px;
            font-size: 1.5rem;
        }
        .section-header p {
            color: #6c757d;
            font-size: 0.95rem;
        }
        .section-divider {
            border-top: 1px solid #dee2e6;
            margin: 40px 0;
        }

        /* Feature cards - simpler */
        .feature-card {
            background: #fff;
            border: 1px solid #dee2e6;
            border-radius: 6px;
            padding: 20px;
            height: 100%;
        }
        .feature-card h5 {
            color: #1a1a2e;
            font-weight: 600;
            font-size: 1rem;
            margin-bottom: 8px;
        }
        .feature-card h5 i {
            color: #5dade2;
            margin-right: 8px;
        }
        .feature-card p {
            color: #6c757d;
            font-size: 0.85rem;
            margin-bottom: 0;
        }

        /* Getting started cards */
        .getting-started-step {
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 6px;
            padding: 20px;
            height: 100%;
        }
        .step-number {
            display: inline-block;
            width: 28px;
            height: 28px;
            background: #5dade2;
            border-radius: 50%;
            text-align: center;
            line-height: 28px;
            font-weight: 600;
            color: #fff;
            font-size: 0.9rem;
            margin-bottom: 12px;
        }
        .getting-started-step h5 {
            color: #1a1a2e;
            font-weight: 600;
            font-size: 1rem;
            margin-bottom: 10px;
        }
        .getting-started-step p {
            color: #6c757d;
            font-size: 0.85rem;
        }

        /* Code blocks - dark terminal style */
        .code-block {
            background: #1a1a2e !important;
            border: 1px solid #333 !important;
            border-radius: 4px;
            padding: 15px;
            overflow-x: auto;
            margin: 12px 0;
        }
        .code-block pre {
            margin: 0;
            background: transparent !important;
            color: #e0e0e0 !important;
            font-family: 'Inconsolata', 'Courier New', monospace;
            font-size: 0.8rem;
            line-height: 1.4;
        }
        .code-block .keyword { color: #c792ea; }
        .code-block .string { color: #c3e88d; }
        .code-block .comment { color: #6a9955; }
        .code-block .variable { color: #f07178; }
        .code-block .function { color: #82aaff; }

        /* Use case tabs - simpler */
        .use-case-tabs .nav-link {
            color: #495057;
            border: 1px solid #dee2e6;
            border-bottom: none;
            padding: 10px 18px;
            font-weight: 500;
            font-size: 0.9rem;
            border-radius: 4px 4px 0 0;
            background: #f8f9fa;
            margin-right: 2px;
        }
        .use-case-tabs .nav-link:hover {
            color: #5dade2;
            background: #e9ecef;
        }
        .use-case-tabs .nav-link.active {
            color: #fff;
            background: #5dade2;
            border-color: #5dade2;
        }
        .use-case-content {
            background: #fff;
            border: 1px solid #dee2e6;
            border-radius: 0 4px 4px 4px;
            padding: 25px;
        }
        .use-case-content h5 {
            color: #1a1a2e;
            font-weight: 600;
            font-size: 0.95rem;
            margin-bottom: 12px;
        }
        .use-case-content ul {
            padding-left: 20px;
            margin-bottom: 0;
        }
        .use-case-content li {
            margin-bottom: 6px;
            color: #495057;
            font-size: 0.9rem;
        }

        /* Endpoint table - TBFM style */
        .endpoint-table {
            background: #fff;
            border: 1px solid #dee2e6;
            border-radius: 4px;
            overflow: hidden;
        }
        .endpoint-table th {
            background: linear-gradient(180deg, #3a4a5c 0%, #2c3e50 100%);
            color: #fff;
            font-weight: 600;
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.03em;
            padding: 10px 12px;
        }
        .endpoint-table td {
            padding: 10px 12px;
            border-bottom: 1px solid #e9ecef;
            vertical-align: middle;
            font-size: 0.9rem;
        }
        .endpoint-table code {
            background: #e9ecef;
            color: #1a1a2e;
            padding: 2px 6px;
            border-radius: 3px;
            font-size: 0.8rem;
        }
        .method-badge {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 3px;
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
        }
        .method-get { background: #5dade2; color: #fff; }
        .method-post { background: #28a745; color: #fff; }

        /* Tier comparison table */
        .tier-table {
            width: 100%;
            background: #fff;
            border: 1px solid #dee2e6;
            border-radius: 4px;
            overflow: hidden;
        }
        .tier-table th, .tier-table td {
            padding: 10px 12px;
            text-align: center;
            border-bottom: 1px solid #e9ecef;
            font-size: 0.85rem;
        }
        .tier-table thead th {
            background: #f8f9fa;
            font-weight: 600;
            color: #1a1a2e;
        }
        .tier-table th:first-child, .tier-table td:first-child {
            text-align: left;
        }
        .tier-header {
            padding: 4px 10px;
            border-radius: 3px;
            font-weight: 600;
            font-size: 0.75rem;
        }
        .tier-public { background: #d4edda; color: #155724; }
        .tier-developer { background: #cce5ff; color: #004085; }
        .tier-partner { background: #fff3cd; color: #856404; }
        .tier-system { background: #f8d7da; color: #721c24; }

        /* SDK cards - mark as coming soon */
        .sdk-card {
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 4px;
            padding: 15px;
            text-align: center;
            opacity: 0.6;
        }
        .sdk-card i {
            font-size: 2rem;
            margin-bottom: 8px;
            color: #6c757d;
        }
        .sdk-card h6 {
            color: #495057;
            font-weight: 600;
            font-size: 0.9rem;
            margin-bottom: 4px;
        }
        .sdk-card small {
            color: #6c757d;
            font-size: 0.75rem;
        }

        /* CTA section */
        .cta-section {
            background: #252540;
            border-radius: 6px;
            padding: 35px 30px;
            text-align: center;
            color: #fff;
        }
        .cta-section h3 {
            font-weight: 600;
            font-size: 1.3rem;
            margin-bottom: 10px;
        }
        .cta-section p {
            color: #b0bec5;
            font-size: 0.95rem;
            margin-bottom: 20px;
        }
        .cta-section .btn {
            padding: 10px 25px;
            font-weight: 500;
            border-radius: 4px;
            margin: 4px;
        }

        /* Resources grid */
        .resource-link {
            display: flex;
            align-items: center;
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 4px;
            padding: 15px;
            text-decoration: none;
            color: #1a1a2e;
            height: 100%;
        }
        .resource-link:hover {
            background: #e9ecef;
            text-decoration: none;
            color: #1a1a2e;
            border-color: #5dade2;
        }
        .resource-link i {
            font-size: 1.3rem;
            color: #5dade2;
            margin-right: 12px;
            width: 30px;
            text-align: center;
        }
        .resource-link span {
            font-weight: 500;
            font-size: 0.9rem;
        }
        .resource-link small {
            display: block;
            color: #6c757d;
            font-size: 0.75rem;
        }

        /* Responsive adjustments */
        @media (max-width: 768px) {
            .feature-card, .getting-started-step {
                margin-bottom: 15px;
            }
        }
    </style>
</head>

<body>

<?php include('load/nav.php'); ?>

<!-- Hero Section -->
<section class="d-flex align-items-center position-relative min-vh-25 py-4" data-jarallax data-speed="0.3" style="pointer-events: all;">
    <div class="container-fluid pt-2 pb-4 py-lg-5">
        <img class="jarallax-img" src="assets/img/jumbotron/main.png" alt="" style="opacity: 50%;">
        <center>
            <h1><i class="fas fa-database mr-2"></i>VATSIM SWIM API</h1>
            <h4 class="text-white pl-1">System Wide Information Management</h4>
            <p class="text-muted mb-3">Version 1.0.0 &bull; Production Ready</p>
            <div>
                <a href="swim-keys" class="btn btn-success mr-2">
                    <i class="fas fa-key mr-1"></i>Get API Key
                </a>
                <a href="docs/swim/" class="btn btn-outline-light" target="_blank">
                    <i class="fas fa-book mr-1"></i>Documentation
                </a>
            </div>
        </center>
    </div>
</section>

<!-- Main Content -->
<div class="container py-4">

    <!-- What is SWIM Section -->
    <div class="section-header">
        <h2>What is VATSIM SWIM?</h2>
        <p>A centralized real-time data exchange hub for flight information across the VATSIM network.</p>
    </div>

    <div class="row mb-4">
        <div class="col-md-6 col-lg-3 mb-3">
            <div class="feature-card">
                <h5><i class="fas fa-bolt"></i>REST API</h5>
                <p>Query flights, positions, and TMI data with filtering and pagination.</p>
            </div>
        </div>
        <div class="col-md-6 col-lg-3 mb-3">
            <div class="feature-card">
                <h5><i class="fas fa-broadcast-tower"></i>WebSocket</h5>
                <p>Real-time event streaming for departures, arrivals, and position updates.</p>
            </div>
        </div>
        <div class="col-md-6 col-lg-3 mb-3">
            <div class="feature-card">
                <h5><i class="fas fa-map-marked-alt"></i>GeoJSON</h5>
                <p>Position data ready for MapLibre, Leaflet, or Mapbox rendering.</p>
            </div>
        </div>
        <div class="col-md-6 col-lg-3 mb-3">
            <div class="feature-card">
                <h5><i class="fas fa-traffic-light"></i>TMI Data</h5>
                <p>Ground Stops, GDPs, EDCTs, and controlled flight information.</p>
            </div>
        </div>
    </div>

    <div class="section-divider"></div>

    <!-- Getting Started Section -->
    <div class="section-header">
        <h2>Getting Started</h2>
        <p>Start integrating with SWIM in three steps.</p>
    </div>

    <div class="row mb-4">
        <div class="col-md-4 mb-3">
            <div class="getting-started-step">
                <div class="step-number">1</div>
                <h5>Create Your API Key</h5>
                <p>Log in with your VATSIM account at the <a href="swim-keys">API Key Portal</a> to create a key instantly.</p>
            </div>
        </div>
        <div class="col-md-4 mb-3">
            <div class="getting-started-step">
                <div class="step-number">2</div>
                <h5>Make a Request</h5>
                <p>Include your API key in the Authorization header:</p>
                <div class="code-block">
                    <pre><span class="keyword">curl</span> -H <span class="string">"Authorization: Bearer YOUR_KEY"</span> \
  https://perti.vatcscc.org/api/swim/v1/flights</pre>
                </div>
            </div>
        </div>
        <div class="col-md-4 mb-3">
            <div class="getting-started-step">
                <div class="step-number">3</div>
                <h5>Explore the API</h5>
                <p>Read the <a href="docs/swim/" target="_blank">full documentation</a> to discover all endpoints, filters, and WebSocket events.</p>
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
                    <td>100 req/min</td>
                    <td>300 req/min</td>
                    <td>3,000 req/min</td>
                    <td>30,000 req/min</td>
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
        <p>Official client libraries (coming soon).</p>
    </div>

    <div class="row mb-4">
        <div class="col-6 col-md-3 mb-3">
            <div class="sdk-card">
                <i class="fab fa-python"></i>
                <h6>Python</h6>
                <small>Coming Soon</small>
            </div>
        </div>
        <div class="col-6 col-md-3 mb-3">
            <div class="sdk-card">
                <i class="fab fa-js-square"></i>
                <h6>JavaScript</h6>
                <small>Coming Soon</small>
            </div>
        </div>
        <div class="col-6 col-md-3 mb-3">
            <div class="sdk-card">
                <i class="fab fa-microsoft"></i>
                <h6>C# / .NET</h6>
                <small>Coming Soon</small>
            </div>
        </div>
        <div class="col-6 col-md-3 mb-3">
            <div class="sdk-card">
                <i class="fab fa-java"></i>
                <h6>Java</h6>
                <small>Coming Soon</small>
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
            <a href="docs/swim/openapi.yaml" class="resource-link" target="_blank">
                <i class="fas fa-code"></i>
                <div>
                    <span>OpenAPI Spec</span>
                    <small>Machine-readable API definition</small>
                </div>
            </a>
        </div>
        <div class="col-md-6 col-lg-4 mb-3">
            <a href="https://vats.im/CommandCenter" class="resource-link" target="_blank">
                <i class="fab fa-discord"></i>
                <div>
                    <span>Discord Community</span>
                    <small>Get help and share projects</small>
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
    // Smooth scroll for anchor links (exclude tabs which use data-toggle)
    $('a[href^="#"]:not([data-toggle])').on('click', function(e) {
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
