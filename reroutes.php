<?php
/**
 * reroutes.php - Reroute Management
 */
require_once 'sessions/handler.php';
require_once 'load/connect.php';

$rerouteId = isset($_GET['id']) ? get_int('id') : null;
$reroute = null;
$pageMode = 'new';

if ($rerouteId) {
    $sql = "SELECT * FROM dbo.tmi_reroutes WHERE id = ?";
    $stmt = sqlsrv_query($conn_adl, $sql, [$rerouteId]);
    if ($stmt) {
        $reroute = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($stmt);
        if ($reroute) {
            $pageMode = $reroute['status'] >= 2 ? 'monitor' : 'edit';
            foreach (['start_utc', 'end_utc', 'created_utc', 'updated_utc', 'activated_utc'] as $f) {
                if (isset($reroute[$f]) && $reroute[$f] instanceof DateTime) {
                    $reroute[$f] = $reroute[$f]->format('Y-m-d\TH:i');
                }
            }
        }
    }
}

$STATUS_LABELS = [
    0 => ['label' => 'DRAFT', 'class' => 'secondary'],
    1 => ['label' => 'PROPOSED', 'class' => 'info'],
    2 => ['label' => 'ACTIVE', 'class' => 'success'],
    3 => ['label' => 'MONITORING', 'class' => 'warning'],
    4 => ['label' => 'EXPIRED', 'class' => 'dark'],
    5 => ['label' => 'CANCELLED', 'class' => 'danger']
];

$page_title = "vATCSCC Reroutes";
require_once 'load/header.php';
require_once 'load/nav.php';
?>

<style>
#rr_map_container { height: 280px; background: #1a1a2e; }
.section-header { font-size: 0.8rem; font-weight: 600; color: #737491; letter-spacing: 0.5px; margin-bottom: 0.75rem; }
.form-label-sm { font-size: 0.75rem; text-transform: uppercase; color: #737491; letter-spacing: 0.3px; margin-bottom: 0.25rem; }
.utc-clock { font-family: 'Courier New', monospace; font-size: 1.1rem; }
.fix-badge { display: inline-block; padding: 2px 8px; margin: 2px; border-radius: 4px; font-size: 0.75rem; }
.fix-protected { background: #d4edda; color: #155724; }
.fix-avoid { background: #f8d7da; color: #721c24; }
.flight-row-noncompliant { background-color: rgba(247, 79, 120, 0.1) !important; }
.flight-row-partial { background-color: rgba(255, 177, 92, 0.1) !important; }
.stats-box { text-align: center; padding: 0.5rem; }
.stats-box .value { font-size: 1.5rem; font-weight: 600; }
.stats-box .label { font-size: 0.7rem; text-transform: uppercase; color: #737491; }
</style>

<div class="container-fluid py-4">
    <!-- Header -->
    <div class="row mb-4 align-items-center">
        <div class="col-auto">
            <a href="reroutes_index.php" class="btn btn-link text-muted">
                <i class="fas fa-arrow-left"></i> Back to List
            </a>
        </div>
        <div class="col">
            <h4 class="mb-0">
                <i class="fas fa-route text-info"></i>
                <?= $reroute ? 'Reroute Management' : 'New Reroute' ?>
                <?php if ($reroute): ?>
                    <span class="badge badge-<?= $STATUS_LABELS[$reroute['status']]['class'] ?> ml-2" id="rr_status_badge">
                        <?= $STATUS_LABELS[$reroute['status']]['label'] ?>
                    </span>
                <?php endif; ?>
            </h4>
        </div>
        <div class="col-auto">
            <div class="border rounded px-3 py-2 d-flex align-items-center">
                <span class="text-muted small mr-2">CURRENT UTC</span>
                <span class="utc-clock" id="rr_utc_clock">--:--:--Z</span>
                <i class="far fa-clock text-info ml-2"></i>
            </div>
        </div>
    </div>

    <div class="row">
        <!-- Left: Setup Form -->
        <div class="col-lg-5 col-xl-4">
            <div class="card shadow-sm mb-4">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <span class="section-header mb-0">
                            <i class="fas fa-ban text-danger"></i> REROUTE SETUP
                        </span>
                        <span class="badge badge-secondary" id="rr_mode_badge"><?= strtoupper($pageMode) ?></span>
                    </div>
                    
                    <form id="rr_form">
                        <input type="hidden" id="rr_id" value="<?= $rerouteId ?? '' ?>">
                        
                        <div class="row">
                            <div class="col-8 mb-3">
                                <label class="form-label-sm">NAME</label>
                                <input type="text" class="form-control" id="rr_name" 
                                       value="<?= htmlspecialchars($reroute['name'] ?? '') ?>" 
                                       placeholder="e.g., ZNY East Flow">
                            </div>
                            <div class="col-4 mb-3">
                                <label class="form-label-sm">ADV #</label>
                                <input type="text" class="form-control" id="rr_adv_number"
                                       value="<?= htmlspecialchars($reroute['adv_number'] ?? '') ?>"
                                       placeholder="001">
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-6 mb-3">
                                <label class="form-label-sm">START (UTC)</label>
                                <input type="datetime-local" class="form-control" id="rr_start_utc"
                                       value="<?= $reroute['start_utc'] ?? '' ?>">
                            </div>
                            <div class="col-6 mb-3">
                                <label class="form-label-sm">END (UTC)</label>
                                <input type="datetime-local" class="form-control" id="rr_end_utc"
                                       value="<?= $reroute['end_utc'] ?? '' ?>">
                            </div>
                        </div>
                        
                        <hr>
                        
                        <label class="form-label-sm">ARRIVAL AIRPORTS</label>
                        <input type="text" class="form-control mb-3" id="rr_dest_airports"
                               value="<?= htmlspecialchars($reroute['dest_airports'] ?? '') ?>"
                               placeholder="e.g., KJFK KEWR KLGA (space-separated)">
                        
                        <div class="row">
                            <div class="col-6 mb-3">
                                <label class="form-label-sm">ORIGIN CENTERS (SCOPE)</label>
                                <input type="text" class="form-control" id="rr_origin_centers"
                                       value="<?= htmlspecialchars($reroute['origin_centers'] ?? '') ?>"
                                       placeholder="ZLA,ZOA">
                            </div>
                            <div class="col-6 mb-3">
                                <label class="form-label-sm">ORIGIN AIRPORTS</label>
                                <input type="text" class="form-control" id="rr_origin_airports"
                                       value="<?= htmlspecialchars($reroute['origin_airports'] ?? '') ?>"
                                       placeholder="optional">
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-6 mb-3">
                                <label class="form-label-sm">DEST CENTERS (SCOPE)</label>
                                <input type="text" class="form-control" id="rr_dest_centers"
                                       value="<?= htmlspecialchars($reroute['dest_centers'] ?? '') ?>"
                                       placeholder="ZNY,ZBW">
                            </div>
                            <div class="col-6 mb-3">
                                <label class="form-label-sm">ALTITUDE RANGE</label>
                                <div class="input-group">
                                    <input type="text" class="form-control" id="rr_altitude_min" 
                                           value="<?= $reroute['altitude_min'] ?? '' ?>" placeholder="FL240">
                                    <div class="input-group-append"><span class="input-group-text">-</span></div>
                                    <input type="text" class="form-control" id="rr_altitude_max"
                                           value="<?= $reroute['altitude_max'] ?? '' ?>" placeholder="FL450">
                                </div>
                            </div>
                        </div>
                        
                        <hr>
                        
                        <label class="form-label-sm">PROTECTED FIXES <small class="text-success">(MUST CROSS)</small></label>
                        <input type="text" class="form-control mb-2" id="rr_protected_fixes"
                               value="<?= htmlspecialchars($reroute['protected_fixes'] ?? '') ?>"
                               placeholder="MERIT GREKI JUDDS">
                        
                        <label class="form-label-sm">AVOID FIXES <small class="text-danger">(MUST NOT CROSS)</small></label>
                        <input type="text" class="form-control mb-3" id="rr_avoid_fixes"
                               value="<?= htmlspecialchars($reroute['avoid_fixes'] ?? '') ?>"
                               placeholder="WHITE COATE">
                        
                        <label class="form-label-sm">PROTECTED SEGMENT (ROUTE STRING)</label>
                        <textarea class="form-control mb-3" id="rr_protected_segment" rows="2"
                                  placeholder="MERIT J60 GREKI J80 JUDDS"><?= htmlspecialchars($reroute['protected_segment'] ?? '') ?></textarea>
                        
                        <!-- Hidden fields for additional filters -->
                        <input type="hidden" id="rr_include_ac_cat" value="<?= $reroute['include_ac_cat'] ?? 'ALL' ?>">
                        <input type="hidden" id="rr_include_carriers" value="<?= $reroute['include_carriers'] ?? '' ?>">
                        <input type="hidden" id="rr_time_basis" value="<?= $reroute['time_basis'] ?? 'ETD' ?>">
                        <input type="hidden" id="rr_airborne_filter" value="<?= $reroute['airborne_filter'] ?? 'NOT_AIRBORNE' ?>">
                        <input type="hidden" id="rr_exempt_airports" value="<?= $reroute['exempt_airports'] ?? '' ?>">
                        <input type="hidden" id="rr_exempt_carriers" value="<?= $reroute['exempt_carriers'] ?? '' ?>">
                        <input type="hidden" id="rr_exempt_flights" value="<?= $reroute['exempt_flights'] ?? '' ?>">
                        <input type="hidden" id="rr_departure_fix" value="<?= $reroute['departure_fix'] ?? '' ?>">
                        <input type="hidden" id="rr_arrival_fix" value="<?= $reroute['arrival_fix'] ?? '' ?>">
                        <input type="hidden" id="rr_thru_fixes" value="<?= $reroute['thru_fixes'] ?? '' ?>">
                        
                        <label class="form-label-sm">COMMENTS</label>
                        <textarea class="form-control" id="rr_comments" rows="2"
                                  placeholder="Weather, construction, etc."><?= htmlspecialchars($reroute['comments'] ?? '') ?></textarea>
                    </form>
                </div>
                
                <div class="card-footer bg-white">
                    <div class="row">
                        <div class="col-6">
                            <button class="btn btn-info btn-block" id="rr_preview_btn">
                                <i class="fas fa-search"></i> Preview
                            </button>
                        </div>
                        <div class="col-6">
                            <button class="btn btn-primary btn-block" id="rr_save_btn">
                                <i class="fas fa-save"></i> Save
                            </button>
                        </div>
                    </div>
                    <?php if ($pageMode === 'new' || $pageMode === 'edit'): ?>
                    <button class="btn btn-success btn-block mt-2" id="rr_activate_btn">
                        <i class="fas fa-play"></i> Activate & Assign Flights
                    </button>
                    <?php elseif ($pageMode === 'monitor'): ?>
                    <div class="row mt-2">
                        <div class="col-6">
                            <button class="btn btn-warning btn-block" id="rr_deactivate_btn">
                                <i class="fas fa-pause"></i> Monitor
                            </button>
                        </div>
                        <div class="col-6">
                            <button class="btn btn-outline-danger btn-block" onclick="expireReroute()">
                                <i class="fas fa-stop"></i> Expire
                            </button>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <!-- Right: Map + Flights -->
        <div class="col-lg-7 col-xl-8">
            <!-- Flights Card -->
            <div class="card shadow-sm mb-4">
                <div class="card-body pb-0">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <span class="section-header mb-0">
                            <i class="fas fa-plane text-info"></i> FLIGHTS MATCHING REROUTE FILTERS
                            <span class="badge badge-info ml-2" id="rr_affected_count">0</span>
                        </span>
                        <span class="badge badge-success" id="rr_adl_status">ADL: --</span>
                    </div>
                    
                    <!-- Action Bar -->
                    <div class="d-flex justify-content-between align-items-center mb-3 pb-2 border-bottom">
                        <div>
                            <span class="text-muted small mr-3" id="rr_preview_status">Preview loaded: 0 flights.</span>
                            <?php if ($pageMode === 'monitor'): ?>
                            <button class="btn btn-sm btn-outline-info" id="rr_refresh_compliance_btn">
                                <i class="fas fa-sync"></i> Refresh
                            </button>
                            <?php endif; ?>
                        </div>
                        <div class="btn-group btn-group-sm">
                            <button class="btn btn-outline-secondary" title="List view" onclick="toggleView('list')">
                                <i class="fas fa-list"></i>
                            </button>
                            <button class="btn btn-outline-secondary" title="Map view" onclick="toggleView('map')">
                                <i class="fas fa-map"></i>
                            </button>
                        </div>
                    </div>
                    
                    <!-- Statistics Row -->
                    <div class="row mb-3" id="rr_stats_row">
                        <div class="col"><div class="stats-box"><div class="value" id="rr_stats_total">--</div><div class="label">Total</div></div></div>
                        <div class="col"><div class="stats-box"><div class="value text-success" id="rr_stats_compliant">--</div><div class="label">Compliant</div></div></div>
                        <div class="col"><div class="stats-box"><div class="value text-warning" id="rr_stats_partial">--</div><div class="label">Partial</div></div></div>
                        <div class="col"><div class="stats-box"><div class="value text-danger" id="rr_stats_noncompliant">--</div><div class="label">Non-Comp</div></div></div>
                        <div class="col"><div class="stats-box"><div class="value text-info" id="rr_stats_monitoring">--</div><div class="label">Monitoring</div></div></div>
                        <div class="col"><div class="stats-box"><div class="value" id="rr_stats_route_delta">--</div><div class="label">Avg Δ NM</div></div></div>
                    </div>
                    
                    <!-- Map -->
                    <div id="rr_map_container" class="rounded mb-3"></div>
                    
                    <!-- Flight Table -->
                    <div class="table-responsive" style="max-height: 350px; overflow-y: auto;">
                        <table class="table table-sm table-hover mb-0">
                            <thead class="thead-light">
                                <tr>
                                    <th>ACID</th>
                                    <th>ETD</th>
                                    <th>CTD</th>
                                    <th>ETA</th>
                                    <th>ORIG</th>
                                    <th>DEST</th>
                                    <th>TYPE</th>
                                    <th>STATUS</th>
                                    <th>%</th>
                                </tr>
                            </thead>
                            <tbody id="rr_flight_table_body">
                                <tr>
                                    <td colspan="9" class="text-center text-muted py-4">
                                        Click "Preview" to see affected flights
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
                
                <!-- Exempt Tab -->
                <div class="card-footer bg-light">
                    <a class="text-muted small" data-toggle="collapse" href="#exemptCollapse">
                        <i class="fas fa-ban"></i> Exempt Flights: <span id="rr_exempt_count">0</span>
                    </a>
                    <div class="collapse mt-2" id="exemptCollapse">
                        <table class="table table-sm mb-0">
                            <thead class="thead-light"><tr><th>ACID</th><th>ORIG</th><th>DEST</th><th>Reason</th></tr></thead>
                            <tbody id="rr_exempt_table_body">
                                <tr><td colspan="4" class="text-muted">No exempt flights</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Load Modal -->
<div class="modal fade" id="loadRerouteModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Load Reroute</h5>
                <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
            </div>
            <div class="modal-body">
                <div class="btn-group btn-group-sm mb-3">
                    <input type="radio" class="btn-check" name="loadFilter" id="loadAll" value="" checked>
                    <label class="btn btn-outline-secondary" for="loadAll">All</label>
                    <input type="radio" class="btn-check" name="loadFilter" id="loadActive" value="2,3">
                    <label class="btn btn-outline-success" for="loadActive">Active</label>
                    <input type="radio" class="btn-check" name="loadFilter" id="loadDraft" value="0,1">
                    <label class="btn btn-outline-info" for="loadDraft">Drafts</label>
                </div>
                <div class="table-responsive">
                    <table class="table table-sm table-hover">
                        <thead><tr><th>Status</th><th>Name</th><th>Scope</th><th>Updated</th><th></th></tr></thead>
                        <tbody id="load_reroutes_body"><tr><td colspan="5" class="text-center">Loading...</td></tr></tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Flight Detail Modal -->
<div class="modal fade" id="flightDetailModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="flightDetailTitle">Flight Details</h5>
                <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
            </div>
            <div class="modal-body" id="flightDetailBody"></div>
        </div>
    </div>
</div>

<!-- Leaflet -->
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>

<!-- Reroute Controller -->
<script src="assets/js/reroute.js"></script>

<script>
function toggleView(mode) {
    const map = document.getElementById('rr_map_container');
    if (mode === 'map') {
        map.style.display = 'block';
        if (window.rerouteController?.state?.map) {
            window.rerouteController.state.map.invalidateSize();
        }
    } else {
        map.style.display = 'none';
    }
}

async function expireReroute() {
    const id = document.getElementById('rr_id').value;
    if (!id || !confirm('Expire this reroute?')) return;
    
    try {
        const r = await fetch('api/mgt/tmi/reroutes/activate.php', {
            method: 'POST',
            body: new URLSearchParams({ id, action: 'expire' })
        });
        const data = await r.json();
        if (data.status !== 'ok') throw new Error(data.message);
        alert('Reroute expired');
        location.reload();
    } catch (e) {
        alert('Error: ' + e.message);
    }
}
</script>

<?php require_once 'load/footer.php'; ?>
