<?php
/**
 * CDM Pilot Dashboard
 *
 * Collaborative Decision Making status display:
 *   - Pilot readiness board (TOBT/TSAT/TTOT milestones)
 *   - EDCT compliance status with at-risk highlighting
 *   - Airport departure queue status
 *   - CDM message delivery tracking
 *
 * Databases: VATSIM_TMI (via api/data/cdm/status.php)
 */

include("sessions/handler.php");
include("load/config.php");
include("load/i18n.php");

// CDM dashboard is paused during hibernation
if (defined('HIBERNATION_MODE') && HIBERNATION_MODE) {
    include("load/hibernation.php");
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php $page_title = __('cdm.page.title'); include("load/header.php"); ?>

    <style>
        /* =========================================
         * CDM Dashboard Layout
         * ========================================= */

        .cdm-container {
            padding: 5.5rem 1.5rem 1rem;
            max-width: 1600px;
            margin: 0 auto;
        }

        .cdm-page-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 1rem;
            padding-bottom: 0.5rem;
            border-bottom: 1px solid #dee2e6;
        }

        .cdm-page-header h4 {
            margin: 0;
            font-weight: 600;
            font-size: 1.1rem;
        }

        .cdm-page-header > div:last-child {
            flex-shrink: 0;
            white-space: nowrap;
        }

        .cdm-page-header .cdm-timestamp {
            font-size: 0.75rem;
            color: #888;
        }

        /* Summary cards row */
        #cdm-summary-row {
            display: flex;
            flex-wrap: wrap;
        }

        #cdm-summary-row > .col-2 {
            display: flex;
        }

        .cdm-summary-card {
            text-align: center;
            padding: 0.75rem 0.5rem;
            border-radius: 4px;
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            width: 100%;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }

        .cdm-summary-card .cdm-summary-value {
            font-size: 1.5rem;
            font-weight: 700;
            line-height: 1.2;
        }

        .cdm-summary-card .cdm-summary-label {
            font-size: 0.7rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: #666;
            margin-top: 2px;
            white-space: nowrap;
        }

        /* Section cards */
        .cdm-section .card-header {
            padding: 0.5rem 0.75rem;
            font-weight: 600;
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.03em;
        }

        .cdm-section .card-body {
            padding: 0;
        }

        /* Data tables */
        .cdm-table {
            font-size: 0.78rem;
            margin: 0;
        }

        .cdm-table thead th {
            font-size: 0.7rem;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            color: #555;
            border-bottom-width: 1px;
            padding: 0.4rem 0.5rem;
            white-space: nowrap;
        }

        .cdm-table tbody td {
            padding: 0.35rem 0.5rem;
            vertical-align: middle;
        }

        /* Readiness state badges */
        .cdm-state-badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 3px;
            font-size: 0.68rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.03em;
        }

        .cdm-state-ready     { background: #d4edda; color: #155724; }
        .cdm-state-boarding   { background: #fff3cd; color: #856404; }
        .cdm-state-planning   { background: #e2e3e5; color: #383d41; }
        .cdm-state-taxiing    { background: #cce5ff; color: #004085; }
        .cdm-state-cancelled  { background: #f8d7da; color: #721c24; }

        /* Compliance status badges */
        .cdm-compliance-compliant     { background: #d4edda; color: #155724; }
        .cdm-compliance-at_risk       { background: #fff3cd; color: #856404; }
        .cdm-compliance-non_compliant { background: #f8d7da; color: #721c24; }
        .cdm-compliance-pending       { background: #e2e3e5; color: #383d41; }
        .cdm-compliance-exempt        { background: #d1ecf1; color: #0c5460; }

        /* Risk level badges */
        .cdm-risk-low    { color: #28a745; }
        .cdm-risk-medium { color: #ffc107; }
        .cdm-risk-high   { color: #dc3545; font-weight: 700; }

        /* Message delivery badges */
        .cdm-msg-pending   { background: #fff3cd; color: #856404; }
        .cdm-msg-sent      { background: #cce5ff; color: #004085; }
        .cdm-msg-delivered  { background: #d4edda; color: #155724; }
        .cdm-msg-failed     { background: #f8d7da; color: #721c24; }

        /* Airport status cards */
        .cdm-airport-card {
            border: 1px solid #dee2e6;
            border-radius: 4px;
            padding: 0.6rem;
            margin-bottom: 0.5rem;
            background: #fff;
        }

        .cdm-airport-card .cdm-airport-code {
            font-weight: 700;
            font-size: 0.9rem;
        }

        .cdm-airport-card .cdm-airport-controlled {
            font-size: 0.65rem;
            padding: 1px 5px;
            border-radius: 2px;
            background: #dc3545;
            color: #fff;
            text-transform: uppercase;
            font-weight: 600;
        }

        .cdm-airport-counts {
            display: flex;
            gap: 0.75rem;
            margin-top: 0.4rem;
            font-size: 0.75rem;
        }

        .cdm-airport-counts .cdm-count-item {
            display: flex;
            align-items: center;
            gap: 0.25rem;
        }

        .cdm-airport-counts .cdm-count-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            display: inline-block;
        }

        .cdm-airport-rates {
            margin-top: 0.3rem;
            font-size: 0.7rem;
            color: #666;
        }

        /* Filter bar */
        .cdm-filter-bar {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            margin-bottom: 0.75rem;
        }

        .cdm-filter-bar input {
            font-size: 0.8rem;
            padding: 0.3rem 0.6rem;
            width: 120px;
        }

        .cdm-filter-bar .cdm-auto-refresh {
            font-size: 0.75rem;
            color: #888;
        }

        /* Main content row - equal height columns */
        .cdm-content-row {
            display: flex;
            flex-wrap: wrap;
        }

        .cdm-content-row > [class*="col-"] {
            display: flex;
            flex-direction: column;
        }

        .cdm-content-row .cdm-section:last-child {
            flex: 1;
        }

        /* Empty state */
        .cdm-empty {
            text-align: center;
            padding: 2rem 1rem;
            color: #999;
            font-size: 0.85rem;
        }

        /* Scrollable table container */
        .cdm-table-scroll {
            max-height: 400px;
            overflow-y: auto;
        }
    </style>
</head>

<body>

<?php include("load/nav.php"); ?>

<!-- Page Loading Indicator -->
<div id="perti-page-loader"><div class="bar"></div></div>
<div id="perti-loader-overlay"></div>

<div class="cdm-container">

    <!-- Page Header -->
    <div class="cdm-page-header">
        <div>
            <h4><i class="fas fa-plane-departure"></i> <?= __('cdm.page.title') ?></h4>
        </div>
        <div>
            <span class="cdm-timestamp" id="cdm-last-update"></span>
            <button class="btn btn-sm btn-outline-secondary ml-2" id="cdm-refresh-btn" title="Refresh">
                <i class="fas fa-sync-alt"></i>
            </button>
        </div>
    </div>

    <!-- Filter Bar -->
    <div class="cdm-filter-bar">
        <input type="text" id="cdm-airport-filter" class="form-control form-control-sm"
               placeholder="ICAO..." maxlength="4">
        <button class="btn btn-sm btn-primary" id="cdm-filter-apply">
            <i class="fas fa-filter"></i> <?= __('common.filter') ?>
        </button>
        <button class="btn btn-sm btn-outline-secondary" id="cdm-filter-clear">
            <?= __('common.clear') ?>
        </button>
        <span class="cdm-auto-refresh">
            <i class="fas fa-circle text-success" style="font-size: 6px; vertical-align: middle;"></i>
            <?= __('cdm.autoRefresh') ?>
        </span>
    </div>

    <!-- Summary Row -->
    <div class="row mb-3" id="cdm-summary-row">
        <div class="col-2">
            <div class="cdm-summary-card">
                <div class="cdm-summary-value" id="summary-readiness">-</div>
                <div class="cdm-summary-label"><?= __('cdm.summary.readiness') ?></div>
            </div>
        </div>
        <div class="col-2">
            <div class="cdm-summary-card">
                <div class="cdm-summary-value text-success" id="summary-compliant">-</div>
                <div class="cdm-summary-label"><?= __('cdm.summary.compliant') ?></div>
            </div>
        </div>
        <div class="col-2">
            <div class="cdm-summary-card">
                <div class="cdm-summary-value text-warning" id="summary-at-risk">-</div>
                <div class="cdm-summary-label"><?= __('cdm.summary.atRisk') ?></div>
            </div>
        </div>
        <div class="col-2">
            <div class="cdm-summary-card">
                <div class="cdm-summary-value text-danger" id="summary-non-compliant">-</div>
                <div class="cdm-summary-label"><?= __('cdm.summary.nonCompliant') ?></div>
            </div>
        </div>
        <div class="col-2">
            <div class="cdm-summary-card">
                <div class="cdm-summary-value" id="summary-messages">-</div>
                <div class="cdm-summary-label"><?= __('cdm.summary.messages') ?></div>
            </div>
        </div>
        <div class="col-2">
            <div class="cdm-summary-card">
                <div class="cdm-summary-value" id="summary-airports">-</div>
                <div class="cdm-summary-label"><?= __('cdm.summary.airports') ?></div>
            </div>
        </div>
    </div>

    <!-- Main Content Row -->
    <div class="row cdm-content-row">

        <!-- Left Column: Readiness + Messages -->
        <div class="col-lg-6">

            <!-- Pilot Readiness -->
            <div class="card cdm-section mb-3">
                <div class="card-header bg-light">
                    <i class="fas fa-user-check"></i> <?= __('cdm.readiness.title') ?>
                    <span class="badge badge-secondary float-right" id="readiness-count">0</span>
                </div>
                <div class="card-body">
                    <div class="cdm-table-scroll" id="readiness-container">
                        <table class="table table-hover cdm-table" id="readiness-table">
                            <thead>
                                <tr>
                                    <th><?= __('cdm.table.callsign') ?></th>
                                    <th><?= __('cdm.table.dep') ?></th>
                                    <th><?= __('cdm.table.arr') ?></th>
                                    <th><?= __('cdm.table.state') ?></th>
                                    <th><?= __('cdm.table.tobt') ?></th>
                                    <th><?= __('cdm.table.source') ?></th>
                                </tr>
                            </thead>
                            <tbody id="readiness-body"></tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Messages -->
            <div class="card cdm-section mb-3">
                <div class="card-header bg-light">
                    <i class="fas fa-envelope"></i> <?= __('cdm.messages.title') ?>
                    <span class="badge badge-secondary float-right" id="messages-count">0</span>
                </div>
                <div class="card-body">
                    <div class="cdm-table-scroll" id="messages-container">
                        <table class="table table-hover cdm-table" id="messages-table">
                            <thead>
                                <tr>
                                    <th><?= __('cdm.table.callsign') ?></th>
                                    <th><?= __('cdm.table.type') ?></th>
                                    <th><?= __('cdm.table.channel') ?></th>
                                    <th><?= __('cdm.table.status') ?></th>
                                    <th><?= __('cdm.table.ack') ?></th>
                                    <th><?= __('cdm.table.time') ?></th>
                                </tr>
                            </thead>
                            <tbody id="messages-body"></tbody>
                        </table>
                    </div>
                </div>
            </div>

        </div>

        <!-- Right Column: Compliance + Airport Status -->
        <div class="col-lg-6">

            <!-- EDCT Compliance -->
            <div class="card cdm-section mb-3">
                <div class="card-header bg-light">
                    <i class="fas fa-clipboard-check"></i> <?= __('cdm.compliance.title') ?>
                    <span class="badge badge-secondary float-right" id="compliance-count">0</span>
                </div>
                <div class="card-body">
                    <div class="cdm-table-scroll" id="compliance-container">
                        <table class="table table-hover cdm-table" id="compliance-table">
                            <thead>
                                <tr>
                                    <th><?= __('cdm.table.callsign') ?></th>
                                    <th><?= __('cdm.table.complianceType') ?></th>
                                    <th><?= __('cdm.table.status') ?></th>
                                    <th><?= __('cdm.table.risk') ?></th>
                                    <th><?= __('cdm.table.delta') ?></th>
                                    <th><?= __('cdm.table.evaluated') ?></th>
                                </tr>
                            </thead>
                            <tbody id="compliance-body"></tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Airport Status -->
            <div class="card cdm-section mb-3">
                <div class="card-header bg-light">
                    <i class="fas fa-building"></i> <?= __('cdm.airport.title') ?>
                    <span class="badge badge-secondary float-right" id="airport-count">0</span>
                </div>
                <div class="card-body" style="padding: 0.5rem;">
                    <div id="airport-status-grid"></div>
                </div>
            </div>

        </div>

    </div>

</div>

<?php include("load/footer.php"); ?>

<!-- CDM Dashboard Module -->
<script src="assets/js/cdm.js<?= _v('assets/js/cdm.js') ?>"></script>

</body>
</html>
