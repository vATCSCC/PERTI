<?php
if (session_status() == PHP_SESSION_NONE) session_start();
include("load/config.php");
include("load/input.php");
include("load/connect.php");
$pageTitle = "NAS Event Log";
$pageId = "ntml-log";
include("load/header.php");
include("load/nav.php");
?>

<div class="container-fluid mt-3">
    <div class="row mb-3">
        <div class="col-12">
            <h4>NAS Event Log</h4>
            <p class="text-muted">Chronological log of all TMI actions across the NAS.</p>
        </div>
    </div>

    <!-- Filters -->
    <div class="row mb-3">
        <div class="col-md-2">
            <label>Time Range</label>
            <select id="log-hours" class="form-control form-control-sm">
                <option value="1">Last 1h</option>
                <option value="2">Last 2h</option>
                <option value="4" selected>Last 4h</option>
                <option value="8">Last 8h</option>
                <option value="24">Last 24h</option>
            </select>
        </div>
        <div class="col-md-2">
            <label>Category</label>
            <select id="log-category" class="form-control form-control-sm">
                <option value="">All</option>
                <option value="PROGRAM">Program</option>
                <option value="ENTRY">Entry</option>
                <option value="ADVISORY">Advisory</option>
                <option value="REROUTE">Reroute</option>
                <option value="DELAY_REPORT">Delay Report</option>
                <option value="CONFIG_CHANGE">Config Change</option>
                <option value="FLOW_MEASURE">Flow Measure</option>
                <option value="SLOT">Slot</option>
                <option value="COORDINATION">Coordination</option>
                <option value="SYSTEM">System</option>
            </select>
        </div>
        <div class="col-md-2">
            <label>Facility</label>
            <input type="text" id="log-facility" class="form-control form-control-sm" placeholder="e.g. KJFK">
        </div>
        <div class="col-md-2">
            <label>Organization</label>
            <select id="log-org" class="form-control form-control-sm">
                <option value="">All</option>
                <option value="vatcscc">vATCSCC</option>
                <option value="canoc">CANOC</option>
                <option value="ecfmp">ECFMP</option>
            </select>
        </div>
        <div class="col-md-2">
            <label>&nbsp;</label>
            <div>
                <button id="log-refresh" class="btn btn-sm btn-primary">Refresh</button>
                <label class="ml-2"><input type="checkbox" id="log-auto"> Auto (30s)</label>
            </div>
        </div>
        <div class="col-md-2">
            <label>&nbsp;</label>
            <div>
                <span id="log-count" class="text-muted"></span>
            </div>
        </div>
    </div>

    <!-- Log Table -->
    <div class="row">
        <div class="col-12">
            <div class="table-responsive">
                <table class="table table-sm table-striped" id="log-table">
                    <thead>
                        <tr>
                            <th style="width:140px">Time (UTC)</th>
                            <th style="width:30px"></th>
                            <th style="width:100px">Category</th>
                            <th style="width:90px">Type</th>
                            <th style="width:80px">Program</th>
                            <th style="width:80px">Element</th>
                            <th>Summary</th>
                            <th style="width:80px">Facility</th>
                            <th style="width:80px">User</th>
                        </tr>
                    </thead>
                    <tbody id="log-body">
                        <tr><td colspan="9" class="text-center text-muted">Loading...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Pagination -->
    <div class="row">
        <div class="col-12 text-center">
            <button id="log-prev" class="btn btn-sm btn-outline-secondary" disabled>Previous</button>
            <span id="log-page-info" class="mx-2"></span>
            <button id="log-next" class="btn btn-sm btn-outline-secondary" disabled>Next</button>
        </div>
    </div>
</div>

<script src="assets/js/ntml-log.js"></script>

<?php include("load/footer.php"); ?>
