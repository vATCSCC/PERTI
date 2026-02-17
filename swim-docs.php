<?php
/**
 * VATSWIM API - Technical Documentation Hub
 *
 * Organized index of all SWIM technical documentation, schema references,
 * and standards alignment documents.
 *
 * @package PERTI
 * @subpackage SWIM
 * @version 1.0.0
 */

/**
 * OPTIMIZED: Public page - no session handler or DB needed
 * Login state is handled by nav_public.php (cheap session read)
 */
include("load/config.php");
include("load/i18n.php");
?>

<!DOCTYPE html>
<html>
<head>
    <?php
        $page_title = "SWIM Technical Documentation - PERTI";
        include("load/header.php");
    ?>
    <style>
        /* Documentation Hub Styling */
        .doc-section {
            margin-bottom: 40px;
        }
        .doc-section-header {
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid #5dade2;
        }
        .doc-section-header h2 {
            color: #1a1a2e;
            font-weight: 600;
            font-size: 1.4rem;
            margin-bottom: 5px;
        }
        .doc-section-header p {
            color: #6c757d;
            font-size: 0.9rem;
            margin-bottom: 0;
        }

        .doc-card {
            background: #fff;
            border: 1px solid #dee2e6;
            border-radius: 6px;
            padding: 20px;
            height: 100%;
            transition: all 0.2s ease;
            text-decoration: none;
            display: block;
            color: inherit;
        }
        .doc-card:hover {
            border-color: #5dade2;
            box-shadow: 0 4px 12px rgba(93, 173, 226, 0.15);
            text-decoration: none;
            color: inherit;
            transform: translateY(-2px);
        }
        .doc-card h5 {
            color: #1a1a2e;
            font-weight: 600;
            font-size: 1rem;
            margin-bottom: 8px;
        }
        .doc-card h5 i {
            color: #5dade2;
            margin-right: 8px;
            width: 20px;
            text-align: center;
        }
        .doc-card p {
            color: #6c757d;
            font-size: 0.85rem;
            margin-bottom: 10px;
        }
        .doc-card .doc-meta {
            font-size: 0.75rem;
            color: #adb5bd;
        }
        .doc-card .doc-meta i {
            margin-right: 4px;
        }

        .doc-badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 3px;
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
            margin-left: 8px;
        }
        .badge-primary { background: #5dade2; color: #fff; }
        .badge-reference { background: #6c757d; color: #fff; }
        .badge-internal { background: #ffc107; color: #1a1a2e; }
        .badge-spec { background: #28a745; color: #fff; }

        /* Quick Links */
        .quick-links {
            background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%);
            border-radius: 6px;
            padding: 25px;
            margin-bottom: 30px;
        }
        .quick-links h4 {
            color: #fff;
            font-weight: 600;
            font-size: 1.1rem;
            margin-bottom: 15px;
        }
        .quick-link-btn {
            display: inline-block;
            padding: 8px 16px;
            background: rgba(93, 173, 226, 0.2);
            border: 1px solid rgba(93, 173, 226, 0.4);
            border-radius: 4px;
            color: #5dade2;
            font-size: 0.85rem;
            font-weight: 500;
            margin: 4px;
            transition: all 0.2s ease;
        }
        .quick-link-btn:hover {
            background: #5dade2;
            color: #fff;
            text-decoration: none;
        }
        .quick-link-btn i {
            margin-right: 6px;
        }

        /* Standards Table */
        .standards-table {
            background: #fff;
            border: 1px solid #dee2e6;
            border-radius: 4px;
            overflow: hidden;
            margin-bottom: 20px;
        }
        .standards-table th {
            background: #f8f9fa;
            font-weight: 600;
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 0.03em;
            padding: 10px 15px;
            color: #495057;
        }
        .standards-table td {
            padding: 10px 15px;
            border-bottom: 1px solid #e9ecef;
            font-size: 0.9rem;
        }
        .standards-table code {
            background: #e9ecef;
            padding: 2px 6px;
            border-radius: 3px;
            font-size: 0.8rem;
        }

        /* Rate limits reference */
        .tier-badge {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 3px;
            font-weight: 600;
            font-size: 0.75rem;
        }
        .tier-system { background: #f8d7da; color: #721c24; }
        .tier-partner { background: #fff3cd; color: #856404; }
        .tier-developer { background: #cce5ff; color: #004085; }
        .tier-public { background: #d4edda; color: #155724; }
    </style>
</head>

<body>

<?php include('load/nav_public.php'); ?>

<!-- Hero Section -->
<section class="d-flex align-items-center position-relative min-vh-25 py-4" data-jarallax data-speed="0.3">
    <div class="container-fluid pt-2 pb-4 py-lg-5">
        <img class="jarallax-img" src="assets/img/jumbotron/main.png" alt="" style="opacity: 50%;">
        <center>
            <h1><i class="fas fa-book-open mr-2"></i><?= __('swim.docs.title') ?></h1>
            <h4 class="text-white pl-1"><?= __('swim.docs.subtitle') ?></h4>
            <p class="text-muted mb-3"><?= __('swim.page.version') ?></p>
        </center>
    </div>
</section>

<!-- Main Content -->
<div class="container py-4">

    <!-- Quick Links -->
    <div class="quick-links">
        <h4><i class="fas fa-bolt mr-2"></i><?= __('swim.docs.quickAccess') ?></h4>
        <a href="docs/swim/" class="quick-link-btn" target="_blank">
            <i class="fas fa-play-circle"></i><?= __('swim.docs.interactiveDocs') ?>
        </a>
        <a href="docs/swim/openapi.yaml" class="quick-link-btn" target="_blank">
            <i class="fas fa-file-code"></i><?= __('swim.docs.openApiSpec') ?>
        </a>
        <a href="swim-keys" class="quick-link-btn">
            <i class="fas fa-key"></i><?= __('swim.docs.getApiKey') ?>
        </a>
        <a href="swim" class="quick-link-btn">
            <i class="fas fa-home"></i><?= __('swim.docs.swimOverview') ?>
        </a>
        <a href="https://vats.im/CommandCenter" class="quick-link-btn" target="_blank">
            <i class="fab fa-discord"></i><?= __('swim.docs.discordSupport') ?>
        </a>
    </div>

    <!-- API Reference Section -->
    <div class="doc-section">
        <div class="doc-section-header">
            <h2><i class="fas fa-code mr-2"></i><?= __('swim.docs.apiReference') ?></h2>
            <p><?= __('swim.docs.apiReferenceDesc') ?></p>
        </div>
        <div class="row">
            <div class="col-md-6 col-lg-4 mb-3">
                <a href="docs/swim/" class="doc-card" target="_blank">
                    <h5><i class="fas fa-play-circle"></i><?= __('swim.docs.interactiveDocsCard') ?> <span class="doc-badge badge-primary">Primary</span></h5>
                    <p><?= __('swim.docs.interactiveDocsCardDesc') ?></p>
                    <div class="doc-meta"><i class="fas fa-external-link-alt"></i> <?= __('swim.docs.opensNewTab') ?></div>
                </a>
            </div>
            <div class="col-md-6 col-lg-4 mb-3">
                <a href="docs/swim/openapi.yaml" class="doc-card" target="_blank">
                    <h5><i class="fas fa-file-code"></i><?= __('swim.docs.openApiSpecCard') ?> <span class="doc-badge badge-spec">YAML</span></h5>
                    <p><?= __('swim.docs.openApiSpecCardDesc') ?></p>
                    <div class="doc-meta"><i class="fas fa-download"></i> <?= __('swim.docs.downloadYaml') ?></div>
                </a>
            </div>
            <div class="col-md-6 col-lg-4 mb-3">
                <a href="swim-doc?file=README" class="doc-card">
                    <h5><i class="fas fa-list-alt"></i><?= __('swim.docs.quickReference') ?></h5>
                    <p><?= __('swim.docs.quickReferenceDesc') ?></p>
                    <div class="doc-meta"><i class="fas fa-eye"></i> <?= __('swim.docs.viewDocumentation') ?></div>
                </a>
            </div>
        </div>
    </div>

    <!-- Architecture & Design Section -->
    <div class="doc-section">
        <div class="doc-section-header">
            <h2><i class="fas fa-sitemap mr-2"></i><?= __('swim.docs.architectureDesign') ?></h2>
            <p><?= __('swim.docs.architectureDesignDesc') ?></p>
        </div>
        <div class="row">
            <div class="col-md-6 col-lg-4 mb-3">
                <a href="swim-doc?file=VATSWIM_Design_Document_v1" class="doc-card">
                    <h5><i class="fas fa-drafting-compass"></i>Design Document <span class="doc-badge badge-primary">v1.3</span></h5>
                    <p>Complete architecture overview including data flow, infrastructure, and unified flight record design.</p>
                    <div class="doc-meta"><i class="fas fa-eye"></i> View Documentation</div>
                </a>
            </div>
            <div class="col-md-6 col-lg-4 mb-3">
                <a href="swim-doc?file=VATSWIM_API_Documentation" class="doc-card">
                    <h5><i class="fas fa-book"></i>Full API Documentation</h5>
                    <p>Comprehensive API guide with examples, WebSocket events, and integration patterns.</p>
                    <div class="doc-meta"><i class="fas fa-eye"></i> View Documentation</div>
                </a>
            </div>
            <div class="col-md-6 col-lg-4 mb-3">
                <a href="swim-doc?file=VATSWIM_Release_Documentation" class="doc-card">
                    <h5><i class="fas fa-rocket"></i>Release Documentation</h5>
                    <p>Release notes, deployment guide, and configuration reference.</p>
                    <div class="doc-meta"><i class="fas fa-eye"></i> View Documentation</div>
                </a>
            </div>
        </div>
    </div>

    <!-- Schema & Standards Section -->
    <div class="doc-section">
        <div class="doc-section-header">
            <h2><i class="fas fa-ruler-combined mr-2"></i><?= __('swim.docs.schemaStandards') ?></h2>
            <p><?= __('swim.docs.schemaStandardsDesc') ?></p>
        </div>
        <div class="row">
            <div class="col-md-6 col-lg-4 mb-3">
                <a href="swim-doc?file=VATSWIM_FIXM_Field_Mapping" class="doc-card">
                    <h5><i class="fas fa-exchange-alt"></i>FIXM Field Mapping <span class="doc-badge badge-spec">FIXM 4.3</span></h5>
                    <p>Complete field mapping between VATSWIM, FIXM 4.3, and TFMS standards.</p>
                    <div class="doc-meta"><i class="fas fa-eye"></i> View Documentation</div>
                </a>
            </div>
            <div class="col-md-6 col-lg-4 mb-3">
                <a href="swim-doc?file=Aviation_Standards_Cross_Reference" class="doc-card">
                    <h5><i class="fas fa-globe"></i>Aviation Standards Cross-Reference</h5>
                    <p>Comparison of aviation data standards (FIXM, AIXM, IWXXM, etc.) and their applicability.</p>
                    <div class="doc-meta"><i class="fas fa-eye"></i> View Documentation</div>
                </a>
            </div>
            <div class="col-md-6 col-lg-4 mb-3">
                <a href="swim-doc?file=VATSWIM_API_Field_Migration" class="doc-card">
                    <h5><i class="fas fa-code-branch"></i>Field Migration Guide</h5>
                    <p>Migration guide for transitioning from legacy field names to FIXM-aligned naming.</p>
                    <div class="doc-meta"><i class="fas fa-eye"></i> View Documentation</div>
                </a>
            </div>
            <div class="col-md-6 col-lg-4 mb-3">
                <a href="swim-doc?file=ADL_FLIGHTS_SCHEMA_REFERENCE" class="doc-card">
                    <h5><i class="fas fa-database"></i>ADL Flights Schema</h5>
                    <p>Database schema reference for the adl_flights table structure.</p>
                    <div class="doc-meta"><i class="fas fa-eye"></i> View Documentation</div>
                </a>
            </div>
            <div class="col-md-6 col-lg-4 mb-3">
                <a href="swim-doc?file=ADL_NORMALIZED_SCHEMA_REFERENCE" class="doc-card">
                    <h5><i class="fas fa-layer-group"></i>Normalized Schema Reference</h5>
                    <p>Documentation for normalized/lookup tables (airports, aircraft, airlines).</p>
                    <div class="doc-meta"><i class="fas fa-eye"></i> View Documentation</div>
                </a>
            </div>
        </div>
    </div>

    <!-- Rate Limits Quick Reference -->
    <div class="doc-section">
        <div class="doc-section-header">
            <h2><i class="fas fa-tachometer-alt mr-2"></i><?= __('swim.docs.rateLimitsRef') ?></h2>
            <p><?= __('swim.docs.rateLimitsRefDesc') ?></p>
        </div>
        <div class="table-responsive">
            <table class="standards-table">
                <thead>
                    <tr>
                        <th><?= __('swim.keys.tier') ?></th>
                        <th><?= __('swim.docs.prefix') ?></th>
                        <th><?= __('swim.docs.rateLimit') ?></th>
                        <th><?= __('swim.docs.webSocket') ?></th>
                        <th><?= __('swim.docs.writeAccess') ?></th>
                        <th><?= __('swim.docs.selfService') ?></th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><span class="tier-badge tier-public">Public</span></td>
                        <td><code>swim_pub_</code></td>
                        <td>100 req/min</td>
                        <td>5 connections</td>
                        <td>No</td>
                        <td>Yes</td>
                    </tr>
                    <tr>
                        <td><span class="tier-badge tier-developer">Developer</span></td>
                        <td><code>swim_dev_</code></td>
                        <td>300 req/min</td>
                        <td>50 connections</td>
                        <td>No</td>
                        <td>Yes</td>
                    </tr>
                    <tr>
                        <td><span class="tier-badge tier-partner">Partner</span></td>
                        <td><code>swim_par_</code></td>
                        <td>3,000 req/min</td>
                        <td>500 connections</td>
                        <td>Limited</td>
                        <td>Contact Us</td>
                    </tr>
                    <tr>
                        <td><span class="tier-badge tier-system">System</span></td>
                        <td><code>swim_sys_</code></td>
                        <td>30,000 req/min</td>
                        <td>10,000 connections</td>
                        <td>Full</td>
                        <td>Contact Us</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Announcements & Guides Section -->
    <div class="doc-section">
        <div class="doc-section-header">
            <h2><i class="fas fa-bullhorn mr-2"></i><?= __('swim.docs.announcements') ?></h2>
            <p><?= __('swim.docs.announcementsDesc') ?></p>
        </div>
        <div class="row">
            <div class="col-md-6 col-lg-4 mb-3">
                <a href="swim-doc?file=VATSWIM_Announcement" class="doc-card">
                    <h5><i class="fas fa-newspaper"></i>Launch Announcement</h5>
                    <p>Official SWIM API launch announcement with feature overview and getting started guide.</p>
                    <div class="doc-meta"><i class="fas fa-eye"></i> View Documentation</div>
                </a>
            </div>
        </div>
    </div>

    <!-- Internal Development Docs (collapsed by default) -->
    <div class="doc-section">
        <div class="doc-section-header">
            <h2>
                <i class="fas fa-tools mr-2"></i><?= __('swim.docs.devNotes') ?>
                <button class="btn btn-sm btn-outline-secondary ml-3" type="button" data-toggle="collapse" data-target="#devDocs" aria-expanded="false">
                    <i class="fas fa-chevron-down"></i> Show/Hide
                </button>
            </h2>
            <p><?= __('swim.docs.devNotesDesc') ?></p>
        </div>
        <div class="collapse" id="devDocs">
            <div class="row">
                <div class="col-md-6 col-lg-4 mb-3">
                    <a href="swim-doc?file=SWIM_TODO" class="doc-card">
                        <h5><i class="fas fa-tasks"></i>TODO List <span class="doc-badge badge-internal">Internal</span></h5>
                        <p>Outstanding work items and planned features.</p>
                    </a>
                </div>
                <div class="col-md-6 col-lg-4 mb-3">
                    <a href="swim-doc?file=SWIM_Phase2_RealTime_Design" class="doc-card">
                        <h5><i class="fas fa-broadcast-tower"></i>Phase 2: Real-Time Design <span class="doc-badge badge-internal">Internal</span></h5>
                        <p>WebSocket and real-time streaming architecture design.</p>
                    </a>
                </div>
                <div class="col-md-6 col-lg-4 mb-3">
                    <a href="swim-doc?file=SWIM_Phase2_Phase3_Transition" class="doc-card">
                        <h5><i class="fas fa-exchange-alt"></i>Phase 2/3 Transition <span class="doc-badge badge-internal">Internal</span></h5>
                        <p>Phase transition planning and implementation notes.</p>
                    </a>
                </div>
            </div>
            <p class="text-muted mt-3"><small><i class="fas fa-info-circle mr-1"></i> <?= __('swim.docs.sessionTransitionNote') ?></small></p>
        </div>
    </div>

    <!-- Contact Section -->
    <div class="alert alert-info mt-4">
        <div class="row align-items-center">
            <div class="col-md-8">
                <h5 class="mb-1"><i class="fas fa-question-circle mr-2"></i><?= __('swim.docs.needHelp') ?></h5>
                <p class="mb-0"><?= __('swim.docs.needHelpDesc') ?></p>
            </div>
            <div class="col-md-4 text-md-right mt-3 mt-md-0">
                <a href="mailto:dev@vatcscc.org" class="btn btn-outline-primary btn-sm mr-2">
                    <i class="fas fa-envelope mr-1"></i> Email
                </a>
                <a href="https://vats.im/CommandCenter" class="btn btn-outline-primary btn-sm" target="_blank">
                    <i class="fab fa-discord mr-1"></i> Discord
                </a>
            </div>
        </div>
    </div>

</div>

<?php include('load/footer.php'); ?>

</body>
</html>
