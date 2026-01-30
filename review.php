<?php

include("sessions/handler.php");
    // Session Start (S)
    if (session_status() == PHP_SESSION_NONE) {
        session_start();
        ob_start();
    }
    // Session Start (E)
    
    include("load/config.php");
    include("load/connect.php");

    $uri = explode('?', $_SERVER['REQUEST_URI']);
    $id = $uri[1];

    // Check Perms
    $perm = false;
    if (!defined('DEV')) {
        if (isset($_SESSION['VATSIM_CID'])) {

            // Getting CID Value
            $cid = session_get('VATSIM_CID', '');
    
            $p_check = $conn_sqli->query("SELECT * FROM users WHERE cid='$cid'");
    
            if ($p_check) {
                $perm = true;
            }
    
        }
    } else {
        $perm = true;
        $_SESSION['VATSIM_FIRST_NAME'] = $_SESSION['VATSIM_LAST_NAME'] = $_SESSION['VATSIM_CID'] = 0;
    }

    $plan_info = $conn_sqli->query("SELECT * FROM p_plans WHERE id=$id")->fetch_assoc();
?>

<!DOCTYPE html>
<html>

<head>

    <!-- Import CSS -->
    <?php
        $page_title = "PERTI TMR";
        include("load/header.php");
    ?>

    <script>
        function tooltips() {
            $('[data-toggle="tooltip"]').tooltip('dispose');

            $(function () {
                $('[data-toggle="tooltip"]').tooltip()
            }); 
        }
    </script>
    
    <style>
        /* Statsim Section Styles */
        .statsim-section {
            border: 1px solid #333;
            border-radius: 4px;
            padding: 15px;
            margin-bottom: 20px;
        }
        .statsim-section h6 {
            color: #ffc107;
            margin-bottom: 15px;
            font-weight: bold;
        }
        .statsim-form-row {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-bottom: 10px;
        }
        .statsim-form-row .form-group {
            flex: 1;
            min-width: 150px;
            margin-bottom: 0;
        }
        .statsim-form-row label {
            font-size: 0.75rem;
            text-transform: uppercase;
            color: #888;
            margin-bottom: 2px;
        }
        .statsim-results {
            margin-top: 15px;
        }
        .statsim-results table {
            font-size: 0.85rem;
        }
        .statsim-results th {
            text-transform: uppercase;
            font-size: 0.75rem;
            background: #e6e6e6;
        }
        .statsim-loading {
            text-align: center;
            padding: 20px;
            color: #888;
        }
        .statsim-url-display {
            font-size: 0.8rem;
            word-break: break-all;
            background: #0a0a15;
            padding: 8px;
            border-radius: 4px;
            margin-top: 10px;
        }
        .statsim-totals {
            background: #e6e6e6;
            padding: 10px;
            border-radius: 4px;
            margin-top: 10px;
        }
        .statsim-totals .total-item {
            display: inline-block;
            margin-right: 20px;
        }
        .statsim-totals .total-label {
            font-size: 0.7rem;
            text-transform: uppercase;
            color: #888;
        }
        .statsim-totals .total-value {
            font-size: 1.2rem;
            font-weight: bold;
        }
        .statsim-totals .total-value.arrivals {
            color: #f00;
        }
        .statsim-totals .total-value.departures {
            color: #0f0;
        }
        
        /* FSM-style Color Scheme:
         * Chart Bars: Bright red (#f00) / green (#0f0)
         * Table Text: Readable red (#c00) / green (#080) against light bg
         * VATSIM AAR = white solid line (#fff)
         * VATSIM ADR = white dashed line (#fff)
         * RW AAR = cyan solid line (#0ff)
         * RW ADR = cyan dashed line (#0ff)
         */
        
        /* General text color classes - readable shades for body text */
        .text-arr { color: #c00 !important; }
        .text-dep { color: #080 !important; }
        
        /* Badge styles - bright colors for small badges */
        .badge-arr {
            background-color: #f00;
            color: #fff;
        }
        .badge-dep {
            background-color: #0f0;
            color: #000;
        }
        
        /* Cyan button for RW */
        .btn-cyan {
            background-color: #0cc;
            border-color: #0bb;
            color: #000;
        }
        .btn-cyan:hover {
            background-color: #0bb;
            border-color: #0aa;
            color: #000;
        }
        
        /* Airport rates card */
        .airport-rates-card {
            background-color: #c6c6c6;
            border: 1px solid #333;
            border-radius: 4px;
            margin-bottom: 20px;
        }
        .airport-rates-header {
            padding: 10px 15px;
            border-bottom: 1px solid #333;
            background: navy;
            color: #ffc107;
            font-size: 0.95rem;
        }
        .airport-rates-header .badge {
            font-size: 0.75rem;
            padding: 4px 8px;
        }
        
        /* Hourly Rates Section */
        .hourly-rates-section {
            border: 1px solid #333;
            border-radius: 4px;
            padding: 15px;
            margin-top: 20px;
        }
        .hourly-rates-section h6 {
            color: #17a2b8;
            margin-bottom: 15px;
            font-weight: bold;
        }
        .quick-fill-section {
            margin-bottom: 15px;
        }
        .quick-fill-row {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            margin-bottom: 10px;
            padding: 8px 10px;
            border-radius: 4px;
            background: #e8e8e8;
        }
        .quick-fill-group {
            display: flex;
            align-items: center;
            gap: 4px;
        }
        .quick-fill-group label {
            font-size: 0.7rem;
            text-transform: uppercase;
            color: #555;
            margin: 0;
            white-space: nowrap;
        }
        .quick-fill-group input {
            width: 48px;
            text-align: center;
            padding: 2px 4px;
            font-size: 0.8rem;
        }
        .quick-fill-group .btn {
            padding: 0.15rem 0.4rem;
            font-size: 0.7rem;
        }
        
        /* Chart container */
        .chart-container {
            border-radius: 4px;
            padding: 15px;
            height: 250px;
        }
        
        /* Airport table section */
        .airport-table-section {
            margin-bottom: 20px;
        }
        .airport-table-header {
            background: #2a2a3e;
            padding: 8px 12px;
            margin-bottom: 0;
            border-radius: 4px 4px 0 0;
            color: #ffc107;
        }
        
        .hourly-rates-table {
            font-size: 0.8rem;
            margin-top: 0;
        }
        .hourly-rates-table th {
            text-transform: uppercase;
            font-size: 0.65rem;
            font-weight: bold;
            background: #999999;
            text-align: center;
            padding: 6px 4px;
        }
        .hourly-rates-table .statsim-header { background: #333; color: #aaa; }
        .hourly-rates-table .vatsim-header { background: #444; color: #fff; }
        .hourly-rates-table .rw-header { background: #17525d; color: #0ff; }
        
        .hourly-rates-table td {
            padding: 3px 2px;
            vertical-align: middle;
            text-align: center;
        }
        .hourly-rates-table input {
            width: 42px;
            text-align: center;
            padding: 2px 2px;
            font-size: 0.75rem;
        }
        .hourly-rates-table .time-cell {
            font-family: 'Consolas', 'Monaco', monospace;
            font-weight: bold;
            font-size: 0.8rem;
            white-space: nowrap;
            text-align: center;
        }
        
        /* Column styling - Table text uses readable colors, chart bars use bright colors */
        .hourly-rates-table .statsim-col { background: rgba(100,100,100,0.1); }
        .hourly-rates-table .statsim-col.col-arr { color: #c00; font-weight: bold; }
        .hourly-rates-table .statsim-col.col-dep { color: #080; font-weight: bold; }
        .hourly-rates-table .vatsim-col { background: rgba(255,255,255,0.08); }
        .hourly-rates-table .rw-col { background: rgba(0,255,255,0.08); }
        
        .hourly-rates-table tfoot td {
            font-weight: bold;
            color: #000;
        }
        .hourly-rates-table .totals-row td {
            border-top: 2px solid #444;
        }
        .hourly-rates-table .totals-row .col-arr { color: #c00; }
        .hourly-rates-table .totals-row .col-dep { color: #080; }
        
        .rates-actions {
            margin-top: 15px;
            display: flex;
            gap: 10px;
        }
        
        /* Chart Export Bar */
        .chart-export-bar {
            display: flex;
            gap: 5px;
            padding: 4px 8px;
            background: #444;
            border: 1px solid #333;
        }
        .chart-export-bar .btn {
            font-size: 0.7rem;
            padding: 2px 8px;
        }
        
        /* FSM-style Demand Chart Container - Title & Legend on canvas */
        .demand-chart-container {
            background: #c0c0c0;
            border: 2px inset #999;
            padding: 4px;
            height: 400px;
        }
        
        /* Table - Relative time column */
        .hourly-rates-table .rel-cell {
            font-family: 'Consolas', 'Monaco', monospace;
            font-size: 0.75rem;
            font-weight: bold;
            text-align: center;
            white-space: nowrap;
            color: #666;
        }
        .hourly-rates-table tr.rel-zero {
            background: rgba(255,255,0,0.15);
        }
        .hourly-rates-table tr.rel-zero .rel-cell {
            color: #000;
            background: rgba(255,255,0,0.3);
        }

        /* TMI Compliance Section Styles */
        .tmi-compliance-section {
            padding: 15px;
        }
        .tmi-card {
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 6px;
            padding: 15px;
            margin-bottom: 15px;
        }
        .tmi-card.mit-card { border-left: 4px solid #007bff; }
        .tmi-card.gs-card { border-left: 4px solid #dc3545; }
        .tmi-card.apreq-card { border-left: 4px solid #6c757d; }
        .tmi-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
        }
        .tmi-fix-name {
            font-size: 1.1rem;
            font-weight: bold;
        }
        .compliance-badge {
            padding: 4px 12px;
            border-radius: 20px;
            font-weight: bold;
            font-size: 0.9rem;
        }
        .compliance-badge.good { background: #28a745; color: white; }
        .compliance-badge.warn { background: #ffc107; color: black; }
        .compliance-badge.bad { background: #dc3545; color: white; }
        .tmi-stats {
            display: flex;
            gap: 20px;
            flex-wrap: wrap;
        }
        .tmi-stat {
            text-align: center;
        }
        .tmi-stat-value {
            font-size: 1.4rem;
            font-weight: bold;
        }
        .tmi-stat-label {
            font-size: 0.75rem;
            text-transform: uppercase;
            color: #6c757d;
        }
        .tmi-distribution {
            display: flex;
            gap: 10px;
            margin-top: 10px;
        }
        .dist-item {
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 0.8rem;
        }
        .dist-under { background: #dc3545; color: white; }
        .dist-within { background: #28a745; color: white; }
        .dist-over { background: #17a2b8; color: white; }
        .dist-gap { background: #6c757d; color: white; }
        .tmi-summary-card {
            background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%);
            color: white;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 20px;
        }
        .summary-stats {
            display: flex;
            justify-content: space-around;
            flex-wrap: wrap;
        }
        .summary-stat {
            text-align: center;
            padding: 10px;
        }
        .summary-stat-value {
            font-size: 2rem;
            font-weight: bold;
        }
        .summary-stat-value.good { color: #28a745; }
        .summary-stat-value.warn { color: #ffc107; }
        .summary-stat-value.bad { color: #dc3545; }
    </style>
    
    <!-- Chart.js CDN -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
    
    <!-- Statsim & Hourly Rates JS -->
    <script src="assets/js/statsim_rates.js" defer></script>
</head>

<body>

<?php
include('load/nav.php');
?>

    <section class="d-flex align-items-center position-relative bg-position-center overflow-hidden pt-6 jarallax bg-dark text-light" style="min-height: 250px" data-jarallax data-speed="0.3" style="pointer-events: all;">
        <div class="container-fluid pt-2 pb-5 py-lg-6">
            <img class="jarallax-img" src="assets/img/jumbotron/main.png" alt="" style="opacity: 50%; height: 100vh;">

            <center>
                <h1><b><span class="text-danger"><?= $plan_info['event_name']; ?></span> Review</b></h1>
                <h5><a class="text-light" href="plan?<?= $plan_info['id']; ?>"><i class="fas fa-eye text-primary"></i> View PERTI Plan</a></h5>
            </center>

        </div>       
    </section>

    <div class="container-fluid mt-3 mb-3">
        <div class="row">
            <div class="col-2">
                <ul class="nav flex-column nav-pills" aria-orientation="vertical">
                    <li><a class="nav-link active rounded" data-toggle="tab" href="#scoring">Scoring</a></li>
                    <li><a class="nav-link rounded" data-toggle="tab" href="#event_data">Event Data</a></li>
                    <li><a class="nav-link rounded" data-toggle="tab" href="#tmi_compliance">TMI Compliance</a></li>
                </ul>
            </div>
            
            <div class="col-10">
                <div class="tab-content">

                    <!-- Tab: Scoring -->
                    <div class="tab-pane fade show active" id="scoring">
                        <div class="row">
                            <div class="col-4">
                                <!-- Scoring -->

                                <?php if ($perm == true) { ?>
                                    <button class="btn btn-sm btn-success" data-toggle="modal" data-target="#addscoreModal"><i class="fas fa-plus"></i> Add Score</button>      

                                    <hr>
                                <?php } ?>

                                <table class="table table-bordered">
                                    <thead class="text-center bg-secondary">
                                        <th>Category</th>
                                        <th>Score</th>
                                    </thead>
                                    <tbody id="scores"></tbody>
                                </table>
                            </div>

                            <div class="col-8">
                                <!-- Comments -->

                                <?php if ($perm == true) { ?>
                                    <button class="btn btn-sm btn-success" data-toggle="modal" data-target="#addcommentModal"><i class="fas fa-plus"></i> Add Comment</button>      

                                    <hr>
                                <?php } ?>

                                <table class="table table-bordered">
                                    <thead class="text-center bg-secondary">
                                        <th>Category</th>
                                        <th>Comments</th>
                                    </thead>
                                    <tbody id="comments"></tbody>
                                </table>
                            </div>

                        </div>
                    </div>

                    <!-- Tab: Event Data -->
                    <div class="tab-pane fade" id="event_data">
                        
                        <!-- Plan ID for JS -->
                        <input type="hidden" id="plan_id" value="<?= $plan_info['id']; ?>">

                        <!-- Statsim Traffic Data Section -->
                        <div class="statsim-section">
                            <h6><i class="fas fa-chart-bar"></i> STATSIM TRAFFIC DATA</h6>
                            
                            <div class="statsim-form-row">
                                <div class="form-group">
                                    <label>Airports (ICAO)</label>
                                    <input type="text" class="form-control form-control-sm" id="statsim_airports" 
                                           placeholder="KJFK, KLAX, KEWR">
                                </div>
                                <div class="form-group">
                                    <label>From (UTC)</label>
                                    <input type="text" class="form-control form-control-sm" id="statsim_from" 
                                           placeholder="2025-11-28 18:00">
                                </div>
                                <div class="form-group">
                                    <label>To (UTC)</label>
                                    <input type="text" class="form-control form-control-sm" id="statsim_to" 
                                           placeholder="2025-11-29 01:00">
                                </div>
                                <div class="form-group" style="flex: 0 0 auto; align-self: flex-end;">
                                    <button class="btn btn-sm btn-primary" id="statsim_fetch" title="Fetch from Statsim">
                                        <i class="fas fa-download"></i> Fetch
                                    </button>
                                    <button class="btn btn-sm btn-secondary" id="statsim_open_url" title="Open Statsim URL">
                                        <i class="fas fa-external-link-alt"></i>
                                    </button>
                                    <button class="btn btn-sm btn-outline-warning" id="statsim_reset_defaults" title="Reset to Plan Defaults">
                                        <i class="fas fa-undo"></i>
                                    </button>
                                </div>
                            </div>
                            
                            <div class="small text-muted mb-2">
                                <i class="fas fa-info-circle"></i> 
                                Defaults: T-1hr to max(T+6hr, event end +2hr). Snaps to :00 times.
                            </div>
                            
                            <div id="statsim_url_display" class="statsim-url-display" style="display: none;">
                                <a href="#" target="_blank" id="statsim_url_link"></a>
                            </div>
                            
                            <div id="statsim_results" class="statsim-results">
                                <!-- Results will be populated here -->
                            </div>
                        </div>
                        
                        <!-- Hourly Rates Section -->
                        <div class="hourly-rates-section">
                            <h6><i class="fas fa-tachometer-alt"></i> HOURLY RATES (AAR/ADR)</h6>
                            
                            <div id="hourly_rates_container">
                                <div class="text-muted text-center py-3">
                                    <i class="fas fa-info-circle"></i> 
                                    Fetch Statsim data to populate airport-specific hourly rate inputs.
                                </div>
                            </div>
                            
                            <div class="rates-actions" id="rates_actions" style="display: none;">
                                <button class="btn btn-sm btn-success" onclick="HourlyRates.saveRates()">
                                    <i class="fas fa-save"></i> Save Rates
                                </button>
                                <button class="btn btn-sm btn-secondary" onclick="HourlyRates.exportCSV()">
                                    <i class="fas fa-file-csv"></i> Export CSV
                                </button>
                                <button class="btn btn-sm btn-outline-danger ml-2" onclick="HourlyRates.clearAll()">
                                    <i class="fas fa-eraser"></i> Clear All
                                </button>
                            </div>
                        </div>
                        
                        <hr>

                        <?php if ($perm == true) { ?>
                            <button class="btn btn-sm btn-success" data-toggle="modal" data-target="#adddataModal"><i class="fas fa-plus"></i> Add Data</button>      

                            <hr>
                        <?php } ?>

                        <div class="row gutters-tiny py-20" id="data"></div>
                    </div>

                    <!-- Tab: TMI Compliance -->
                    <div class="tab-pane fade" id="tmi_compliance">
                        <div class="tmi-compliance-section">
                            <h5 class="text-warning mb-3"><i class="fas fa-chart-line"></i> TMI Compliance Analysis</h5>

                            <div class="mb-3">
                                <button class="btn btn-sm btn-primary" id="load_tmi_results">
                                    <i class="fas fa-download"></i> Load Results
                                </button>
                                <button class="btn btn-sm btn-success" id="run_tmi_analysis" disabled>
                                    <i class="fas fa-play"></i> Run Analysis
                                </button>
                                <span class="text-muted small ml-2" id="tmi_status"></span>
                            </div>

                            <div id="tmi_results_container">
                                <div class="text-muted text-center py-4">
                                    <i class="fas fa-info-circle"></i>
                                    Click "Load Results" to view TMI compliance analysis for this event.
                                </div>
                            </div>
                        </div>
                    </div>

                </div>
            </div>
        </div>
    </div>

</body>
<?php include('load/footer.php'); ?>


<?php if ($perm == true) { ?>

<!-- Add Score Modal -->
<div class="modal fade" id="addscoreModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Add Score</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>

            <form method="post" id="addscore">

                <div class="modal-body">

                    <input type="hidden" name="p_id" value="<?= $id; ?>">

                    Staffing:
                    <input type="number" class="form-control" name="staffing" min="1" max="5">

                    Tactical (Real-Time):
                    <input type="number" class="form-control" name="tactical" min="1" max="5">

                    Other Coordination:
                    <input type="number" class="form-control" name="other" min="1" max="5">

                    PERTI Plan:
                    <input type="number" class="form-control" name="perti" min="1" max="5">

                    NTML/Advisory Usage:
                    <input type="number" class="form-control" name="ntml" min="1" max="5">

                    TMI:
                    <input type="number" class="form-control" name="tmi" min="1" max="5">

                    ACE Team Implementation:
                    <input type="number" class="form-control" name="ace" min="1" max="5">

                </div>
                <div class="modal-footer">
                    <input type="submit" class="btn btn-sm btn-success" value="Add">
                    <button type="button" class="btn btn-sm btn-danger" data-dismiss="modal">Close</button>
                </div>
        </div>

        </form>

    </div>
</div>

<!-- Edit Score Modal -->
<div class="modal fade" id="editscoreModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Edit Score</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>

            <form method="post" id="editscore">

                <div class="modal-body">

                    <input type="hidden" name="id" id="id">

                    Staffing:
                    <input type="number" class="form-control" name="staffing" id="staffing" min="1" max="5">

                    Tactical (Real-Time):
                    <input type="number" class="form-control" name="tactical" id="tactical" min="1" max="5">

                    Other Coordination:
                    <input type="number" class="form-control" name="other" id="other" min="1" max="5">

                    PERTI Plan:
                    <input type="number" class="form-control" name="perti" id="perti" min="1" max="5">

                    NTML/Advisory Usage:
                    <input type="number" class="form-control" name="ntml" id="ntml" min="1" max="5">

                    TMI:
                    <input type="number" class="form-control" name="tmi" id="tmi" min="1" max="5">

                    ACE Team Implementation:
                    <input type="number" class="form-control" name="ace" id="ace" min="1" max="5">

                </div>
                <div class="modal-footer">
                    <input type="submit" class="btn btn-sm btn-warning" value="Edit">
                    <button type="button" class="btn btn-sm btn-danger" data-dismiss="modal">Close</button>
                </div>
        </div>

        </form>

    </div>
</div>

<!-- Add Comment Modal -->
<div class="modal fade" id="addcommentModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Add Comment</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>

            <form method="post" id="addcomment">

                <div class="modal-body">

                    <input type="hidden" name="p_id" value="<?= $id; ?>">

                    Staffing:
                    <textarea class="form-control rounded-0" name="staffing" id="a_staffing" rows="5"></textarea><hr>

                    Tactical (Real-Time):
                    <textarea class="form-control rounded-0" name="tactical" id="a_tactical" rows="5"></textarea><hr>

                    Other Coordination:
                    <textarea class="form-control rounded-0" name="other" id="a_other" rows="5"></textarea><hr>

                    PERTI Plan:
                    <textarea class="form-control rounded-0" name="perti" id="a_perti" rows="5"></textarea><hr>

                    NTML/Advisory Usage:
                    <textarea class="form-control rounded-0" name="ntml" id="a_ntml" rows="5"></textarea><hr>

                    TMI:
                    <textarea class="form-control rounded-0" name="tmi" id="a_tmi" rows="5"></textarea><hr>

                    ACE Team Implementation:
                    <textarea class="form-control rounded-0" name="ace" id="a_ace" rows="5"></textarea>

                </div>
                <div class="modal-footer">
                    <input type="submit" class="btn btn-sm btn-success" value="Add">
                    <button type="button" class="btn btn-sm btn-danger" data-dismiss="modal">Close</button>
                </div>
        </div>

        </form>

    </div>
</div>

<!-- Edit Comment Modal -->
<div class="modal fade" id="editcommentModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Edit Comment</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>

            <form method="post" id="editcomment">

                <div class="modal-body">

                    <input type="hidden" name="id" id="id">

                    Staffing:
                    <textarea class="form-control rounded-0" name="staffing" id="e_staffing" rows="5"></textarea><hr>

                    Tactical (Real-Time):
                    <textarea class="form-control rounded-0" name="tactical" id="e_tactical" rows="5"></textarea><hr>

                    Other Coordination:
                    <textarea class="form-control rounded-0" name="other" id="e_other" rows="5"></textarea><hr>

                    PERTI Plan:
                    <textarea class="form-control rounded-0" name="perti" id="e_perti" rows="5"></textarea><hr>

                    NTML/Advisory Usage:
                    <textarea class="form-control rounded-0" name="ntml" id="e_ntml" rows="5"></textarea><hr>

                    TMI:
                    <textarea class="form-control rounded-0" name="tmi" id="e_tmi" rows="5"></textarea><hr>

                    ACE Team Implementation:
                    <textarea class="form-control rounded-0" name="ace" id="e_ace" rows="5"></textarea>

                </div>
                <div class="modal-footer">
                    <input type="submit" class="btn btn-sm btn-warning" value="Edit">
                    <button type="button" class="btn btn-sm btn-danger" data-dismiss="modal">Close</button>
                </div>
        </div>

        </form>

    </div>
</div>

<!-- Add Event Data Modal -->
<div class="modal fade" id="adddataModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Add Event Data</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>

            <form method="post" id="adddata">

                <div class="modal-body">

                    <input type="hidden" name="p_id" value="<?= $id; ?>">

                    Summary:
                    <textarea class="form-control" name="summary" rows="3"></textarea>

                    <hr>

                    Image (URL):
                    <input type="text" class="form-control" name="image_url">

                    Source (URL):
                    <input type="text" class="form-control" name="source_url">

                </div>
                <div class="modal-footer">
                    <input type="submit" class="btn btn-sm btn-success" value="Add">
                    <button type="button" class="btn btn-sm btn-danger" data-dismiss="modal">Close</button>
                </div>
        </div>

        </form>

    </div>
</div>

<!-- Edit Event Data Modal -->
<div class="modal fade" id="editdataModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Edit Event Data</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>

            <form method="post" id="editdata">

                <div class="modal-body">

                    <input type="hidden" name="id" id="id">

                    Summary:
                    <textarea class="form-control" name="summary" id="summary" rows="3"></textarea>

                    <hr>

                    Image (URL):
                    <input type="text" class="form-control" name="image_url" id="image_url">

                    Source (URL):
                    <input type="text" class="form-control" name="source_url" id="source_url">

                </div>
                <div class="modal-footer">
                    <input type="submit" class="btn btn-sm btn-warning" value="Edit">
                    <button type="button" class="btn btn-sm btn-danger" data-dismiss="modal">Close</button>
                </div>
        </div>

        </form>

    </div>
</div>


<?php } ?>

<!-- Insert review.js Script -->
<script src="assets/js/review.js"></script>

</html>
