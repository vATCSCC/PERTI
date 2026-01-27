<?php
/**
 * Unified TMI Publisher
 * 
 * Combined NTML Entry + Advisory Builder with multi-Discord posting support.
 * Supports staging→production workflow and cross-border TMI detection.
 * 
 * @package PERTI
 * @subpackage TMI
 * @version 1.0.0
 * @date 2026-01-27
 */

include("sessions/handler.php");
if (session_status() == PHP_SESSION_NONE) {
    session_start();
    ob_start();
}

include("load/config.php");
include("load/connect.php");

// Check Permissions
$perm = false;
$userCid = null;
$userName = null;
$userPrivileged = false;
$userHomeOrg = 'vatcscc'; // Default

if (!defined('DEV')) {
    if (isset($_SESSION['VATSIM_CID'])) {
        $userCid = session_get('VATSIM_CID', '');
        $p_check = $conn_sqli->query("SELECT * FROM users WHERE cid='$userCid'");
        if ($p_check) {
            $perm = true;
            $userName = session_get('VATSIM_FIRST_NAME', '') . ' ' . session_get('VATSIM_LAST_NAME', '');
            
            // Check for privileged roles (Admin, NAS Ops, NTMO, NTMS)
            $row = $p_check->fetch_assoc();
            $privilegedRoles = ['Admin', 'NAS Ops', 'NTMO', 'NTMS'];
            if ($row && isset($row['role']) && in_array($row['role'], $privilegedRoles)) {
                $userPrivileged = true;
            }
        }
    }
} else {
    $perm = true;
    $userPrivileged = true;
    $userCid = $_SESSION['VATSIM_CID'] = 0;
    $userName = $_SESSION['VATSIM_FIRST_NAME'] = 'Dev';
    $_SESSION['VATSIM_LAST_NAME'] = 'User';
}

// Load Discord organization config for the UI
$discordOrgs = [];
if (defined('DISCORD_ORGANIZATIONS')) {
    $allOrgs = json_decode(DISCORD_ORGANIZATIONS, true);
    if (is_array($allOrgs)) {
        foreach ($allOrgs as $code => $config) {
            if (!empty($config['enabled'])) {
                // In non-dev mode, skip testing_only orgs
                if (!defined('DEV') && !empty($config['testing_only'])) {
                    continue;
                }
                $discordOrgs[$code] = [
                    'name' => $config['name'] ?? strtoupper($code),
                    'region' => $config['region'] ?? 'US',
                    'default' => !empty($config['default']),
                    'testing_only' => !empty($config['testing_only']),
                ];
            }
        }
    }
}

// Fallback if no orgs configured
if (empty($discordOrgs)) {
    $discordOrgs['vatcscc'] = [
        'name' => 'vATCSCC',
        'region' => 'US',
        'default' => true,
        'testing_only' => false,
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php $page_title = "TMI Publisher"; include("load/header.php"); ?>
    <link rel="stylesheet" href="assets/css/info-bar.css">
    <link rel="stylesheet" href="assets/css/tmi-publish.css">
</head>
<body>

<?php include('load/nav.php'); ?>

<section class="d-flex align-items-center position-relative bg-position-center fh-section overflow-hidden pt-6 jarallax bg-dark text-light" data-jarallax data-speed="0.3">
    <div class="container-fluid pt-2 pb-4 py-lg-5">
        <img class="jarallax-img" src="assets/img/jumbotron/main.png" alt="" style="opacity: 50%;">
        <center>
            <h1><i class="fas fa-broadcast-tower"></i> TMI Publisher</h1>
            <p class="text-white-50 mb-0">Unified NTML &amp; Advisory Publishing System</p>
        </center>
    </div>
</section>

<div class="container-fluid mt-4 mb-5">
    
    <?php if ($perm): ?>
    
    <!-- Info Bar: UTC Clock & Mode Indicators -->
    <div class="perti-info-bar mb-3">
        <div class="row d-flex flex-wrap align-items-stretch" style="gap: 8px; margin: 0 -4px;">
            <!-- Current Time (UTC) -->
            <div class="col-auto px-1">
                <div class="card shadow-sm perti-info-card perti-card-utc h-100">
                    <div class="card-body d-flex justify-content-between align-items-center">
                        <div>
                            <div class="perti-info-label">Current UTC</div>
                            <div id="utc_clock" class="perti-clock-display perti-clock-display-lg"></div>
                        </div>
                        <div class="ml-3">
                            <i class="far fa-clock fa-lg text-primary"></i>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- User Info -->
            <div class="col-auto px-1">
                <div class="card shadow-sm perti-info-card h-100">
                    <div class="card-body d-flex justify-content-between align-items-center py-2 px-3">
                        <div>
                            <div class="perti-info-label">Logged In As</div>
                            <div class="font-weight-bold"><?= htmlspecialchars($userName) ?></div>
                        </div>
                        <?php if ($userPrivileged): ?>
                        <span class="badge badge-warning ml-2" data-toggle="tooltip" title="Privileged: Can post to all organizations">PRIV</span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <!-- Spacer -->
            <div class="col"></div>
            
            <!-- Mode Controls -->
            <div class="col-auto px-1">
                <div class="card shadow-sm perti-info-card h-100">
                    <div class="card-body d-flex align-items-center py-2 px-3">
                        <div class="custom-control custom-switch mr-3">
                            <input type="checkbox" class="custom-control-input" id="productionMode">
                            <label class="custom-control-label" for="productionMode">Production</label>
                        </div>
                        <span class="badge" id="modeIndicator">STAGING</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Production Warning -->
    <div class="alert alert-danger production-warning mb-3" id="prodWarning" style="display: none;">
        <i class="fas fa-exclamation-triangle"></i> 
        <strong>Production Mode Active</strong> - Entries will post directly to LIVE Discord channels
    </div>

    <!-- Main Content Tabs -->
    <ul class="nav nav-tabs nav-tabs-publisher mb-3" id="publisherTabs" role="tablist">
        <li class="nav-item">
            <a class="nav-link active" id="ntml-tab" data-toggle="tab" href="#ntmlPanel" role="tab">
                <i class="fas fa-clipboard-list mr-1"></i> NTML Quick Entry
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link" id="advisory-tab" data-toggle="tab" href="#advisoryPanel" role="tab">
                <i class="fas fa-bullhorn mr-1"></i> Advisory Builder
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link" id="queue-tab" data-toggle="tab" href="#queuePanel" role="tab">
                <i class="fas fa-list mr-1"></i> Queue 
                <span class="badge badge-secondary" id="queueBadge">0</span>
            </a>
        </li>
    </ul>

    <div class="tab-content" id="publisherTabContent">
        
        <!-- NTML Quick Entry Panel -->
        <div class="tab-pane fade show active" id="ntmlPanel" role="tabpanel">
            <div class="row">
                <div class="col-lg-8">
                    <!-- Quick Entry Input -->
                    <div class="quick-entry-container">
                        <div class="row">
                            <div class="col-12">
                                <label class="text-white-50 mb-2">
                                    <i class="fas fa-terminal"></i> Quick Entry 
                                    <small class="text-muted ml-2">Press <span class="kbd">Enter</span> to add to queue</small>
                                </label>
                                <input type="text" class="form-control quick-input" id="quickInput" 
                                       placeholder="20MIT ZBW→ZNY JFK LENDY VOLUME 1400-1800" autocomplete="off">
                                <div class="autocomplete-dropdown" id="autocompleteDropdown"></div>
                            </div>
                        </div>
                        
                        <!-- Templates -->
                        <div class="mt-3">
                            <span class="text-white-50 mr-2"><i class="fas fa-magic"></i> Templates:</span>
                            <button class="template-btn" data-template="mit-arr">MIT Arrival</button>
                            <button class="template-btn" data-template="mit-dep">MIT Departure</button>
                            <button class="template-btn" data-template="minit">MINIT</button>
                            <button class="template-btn" data-template="delay">Delay</button>
                            <button class="template-btn" data-template="config">Airport Config</button>
                            <button class="template-btn" data-template="gs">Ground Stop</button>
                        </div>
                        
                        <!-- Syntax Help -->
                        <div class="mt-3">
                            <a class="text-white-50" data-toggle="collapse" href="#syntaxHelp" style="text-decoration: none;">
                                <i class="fas fa-question-circle"></i> Syntax Help <i class="fas fa-chevron-down ml-1"></i>
                            </a>
                            <div class="collapse mt-2" id="syntaxHelp">
                                <div class="syntax-help">
                                    <div class="row">
                                        <div class="col-md-6">
                                            <strong>MIT/MINIT:</strong><br>
                                            <code>[distance]MIT [from]→[to] [airport/fix] [reason] [times]</code><br>
                                            <small>Example: <code>20MIT ZBW→ZNY JFK LENDY VOLUME 1400-1800</code></small>
                                        </div>
                                        <div class="col-md-6">
                                            <strong>Delay:</strong><br>
                                            <code>DELAY [facility] [minutes]min [trend] [reason]</code><br>
                                            <small>Example: <code>DELAY JFK 45min INC WEATHER</code></small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="col-lg-4">
                    <!-- Discord Targets -->
                    <div class="discord-targets-card">
                        <h6 class="mb-3">
                            <i class="fab fa-discord"></i> Post To Organizations
                        </h6>
                        
                        <?php foreach ($discordOrgs as $code => $org): ?>
                        <div class="custom-control custom-checkbox mb-2">
                            <input type="checkbox" class="custom-control-input discord-org-checkbox" 
                                   id="org_<?= $code ?>" value="<?= $code ?>" 
                                   data-region="<?= $org['region'] ?>"
                                   <?= $org['default'] ? 'checked' : '' ?>>
                            <label class="custom-control-label" for="org_<?= $code ?>">
                                <?= htmlspecialchars($org['name']) ?>
                                <small class="text-muted">(<?= $org['region'] ?>)</small>
                                <?php if ($org['testing_only']): ?>
                                <span class="badge badge-secondary badge-sm">TEST</span>
                                <?php endif; ?>
                            </label>
                        </div>
                        <?php endforeach; ?>
                        
                        <hr class="my-2">
                        <div class="small text-muted">
                            <i class="fas fa-info-circle"></i>
                            <span id="crossBorderHint">Cross-border TMIs auto-enable partner orgs</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Advisory Builder Panel -->
        <div class="tab-pane fade" id="advisoryPanel" role="tabpanel">
            <div class="row">
                <!-- Left Column: Advisory Form -->
                <div class="col-lg-6 mb-4">
                    <!-- Advisory Type Selector -->
                    <div class="card shadow-sm mb-3">
                        <div class="card-header">
                            <span class="tmi-section-title">
                                <i class="fas fa-list-alt mr-1"></i> Select Advisory Type
                            </span>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-3 col-6 mb-2">
                                    <div class="card advisory-type-card text-center p-2" data-type="GDP">
                                        <div class="advisory-type-icon text-warning"><i class="fas fa-hourglass-half"></i></div>
                                        <div class="small font-weight-bold">GDP</div>
                                    </div>
                                </div>
                                <div class="col-md-3 col-6 mb-2">
                                    <div class="card advisory-type-card text-center p-2" data-type="GS">
                                        <div class="advisory-type-icon text-danger"><i class="fas fa-ban"></i></div>
                                        <div class="small font-weight-bold">GS</div>
                                    </div>
                                </div>
                                <div class="col-md-3 col-6 mb-2">
                                    <div class="card advisory-type-card text-center p-2" data-type="AFP">
                                        <div class="advisory-type-icon text-info"><i class="fas fa-vector-square"></i></div>
                                        <div class="small font-weight-bold">AFP</div>
                                    </div>
                                </div>
                                <div class="col-md-3 col-6 mb-2">
                                    <div class="card advisory-type-card text-center p-2" data-type="REROUTE">
                                        <div class="advisory-type-icon text-success"><i class="fas fa-directions"></i></div>
                                        <div class="small font-weight-bold">Reroute</div>
                                    </div>
                                </div>
                                <div class="col-md-3 col-6 mb-2">
                                    <div class="card advisory-type-card text-center p-2" data-type="MIT">
                                        <div class="advisory-type-icon" style="color: #fd7e14;"><i class="fas fa-ruler-horizontal"></i></div>
                                        <div class="small font-weight-bold">MIT/MINIT</div>
                                    </div>
                                </div>
                                <div class="col-md-3 col-6 mb-2">
                                    <div class="card advisory-type-card text-center p-2" data-type="ATCSCC">
                                        <div class="advisory-type-icon text-secondary"><i class="fas fa-file-alt"></i></div>
                                        <div class="small font-weight-bold">Free-Form</div>
                                    </div>
                                </div>
                                <div class="col-md-3 col-6 mb-2">
                                    <div class="card advisory-type-card text-center p-2" data-type="CNX">
                                        <div class="advisory-type-icon text-dark"><i class="fas fa-times-circle"></i></div>
                                        <div class="small font-weight-bold">Cancel</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Basic Information -->
                    <div class="card shadow-sm mb-3" id="adv_section_basic">
                        <div class="card-header">
                            <span class="tmi-section-title">
                                <i class="fas fa-info-circle mr-1"></i> Basic Information
                            </span>
                        </div>
                        <div class="card-body">
                            <div class="form-row">
                                <div class="form-group col-md-3">
                                    <label class="tmi-label mb-0" for="adv_number">Advisory #</label>
                                    <input type="text" class="form-control form-control-sm" id="adv_number" placeholder="001">
                                </div>
                                <div class="form-group col-md-3">
                                    <label class="tmi-label mb-0" for="adv_facility">Facility</label>
                                    <input type="text" class="form-control form-control-sm" id="adv_facility" value="DCC">
                                </div>
                                <div class="form-group col-md-3">
                                    <label class="tmi-label mb-0" for="adv_ctl_element">CTL Element</label>
                                    <input type="text" class="form-control form-control-sm" id="adv_ctl_element" placeholder="KATL">
                                </div>
                                <div class="form-group col-md-3">
                                    <label class="tmi-label mb-0" for="adv_priority">Priority</label>
                                    <select class="form-control form-control-sm" id="adv_priority">
                                        <option value="1">HIGH</option>
                                        <option value="2" selected>NORMAL</option>
                                        <option value="3">LOW</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Timing Section -->
                    <div class="card shadow-sm mb-3" id="adv_section_timing">
                        <div class="card-header">
                            <span class="tmi-section-title">
                                <i class="fas fa-clock mr-1"></i> Timing
                            </span>
                        </div>
                        <div class="card-body">
                            <div class="form-row">
                                <div class="form-group col-md-6">
                                    <label class="tmi-label mb-0" for="adv_start">Start (UTC)</label>
                                    <input type="datetime-local" class="form-control form-control-sm" id="adv_start">
                                </div>
                                <div class="form-group col-md-6">
                                    <label class="tmi-label mb-0" for="adv_end">End (UTC)</label>
                                    <input type="datetime-local" class="form-control form-control-sm" id="adv_end">
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Dynamic Sections Placeholder (loaded by JS based on type) -->
                    <div id="adv_dynamic_sections"></div>

                    <!-- Comments Section -->
                    <div class="card shadow-sm mb-3" id="adv_section_comments">
                        <div class="card-header">
                            <span class="tmi-section-title">
                                <i class="fas fa-comment mr-1"></i> Comments
                            </span>
                        </div>
                        <div class="card-body">
                            <textarea class="form-control form-control-sm" id="adv_comments" rows="2" placeholder="Additional notes..."></textarea>
                        </div>
                    </div>

                    <!-- Action Buttons -->
                    <div class="d-flex justify-content-between">
                        <button class="btn btn-outline-secondary" id="adv_reset" type="button">
                            <i class="fas fa-undo mr-1"></i> Reset
                        </button>
                        <button class="btn btn-primary" id="adv_add_to_queue" type="button">
                            <i class="fas fa-plus mr-1"></i> Add to Queue
                        </button>
                    </div>
                </div>

                <!-- Right Column: Preview -->
                <div class="col-lg-6 mb-4">
                    <!-- Advisory Preview -->
                    <div class="card shadow-sm mb-3">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <span class="tmi-section-title">
                                <i class="fas fa-eye mr-1"></i> Advisory Preview
                            </span>
                            <span class="char-count" id="preview_char_count">0 / 2000</span>
                        </div>
                        <div class="card-body">
                            <pre id="adv_preview" class="tmi-advisory-preview">Select an advisory type to begin...</pre>
                        </div>
                        <div class="card-footer">
                            <button class="btn btn-sm btn-outline-secondary" id="adv_copy" type="button">
                                <i class="fas fa-copy mr-1"></i> Copy
                            </button>
                        </div>
                    </div>
                    
                    <!-- Discord Targets for Advisory -->
                    <div class="card shadow-sm">
                        <div class="card-header">
                            <span class="tmi-section-title">
                                <i class="fab fa-discord mr-1"></i> Post To Organizations
                            </span>
                        </div>
                        <div class="card-body">
                            <?php foreach ($discordOrgs as $code => $org): ?>
                            <div class="custom-control custom-checkbox custom-control-inline">
                                <input type="checkbox" class="custom-control-input discord-org-checkbox-adv" 
                                       id="adv_org_<?= $code ?>" value="<?= $code ?>" 
                                       <?= $org['default'] ? 'checked' : '' ?>>
                                <label class="custom-control-label" for="adv_org_<?= $code ?>">
                                    <?= htmlspecialchars($org['name']) ?>
                                </label>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Queue Panel -->
        <div class="tab-pane fade" id="queuePanel" role="tabpanel">
            <div class="row">
                <div class="col-lg-8">
                    <div class="card shadow-sm">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <span class="tmi-section-title">
                                <i class="fas fa-list mr-1"></i> Entry Queue
                            </span>
                            <button class="btn btn-sm btn-outline-danger" id="clearQueue">
                                <i class="fas fa-trash mr-1"></i> Clear All
                            </button>
                        </div>
                        <div class="card-body p-0">
                            <div id="entryQueueList">
                                <div class="text-center text-muted py-4" id="emptyQueueMsg">
                                    <i class="fas fa-inbox fa-2x mb-2"></i><br>
                                    No entries queued. Add entries from NTML or Advisory tabs.
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="col-lg-4">
                    <!-- Submit Controls -->
                    <div class="card shadow-sm mb-3">
                        <div class="card-header">
                            <span class="tmi-section-title">
                                <i class="fas fa-paper-plane mr-1"></i> Publish
                            </span>
                        </div>
                        <div class="card-body">
                            <div class="mb-3">
                                <div class="h3 text-center mb-0">
                                    <span id="submitCount">0</span>
                                    <small class="text-muted">entries ready</small>
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <strong>Target Mode:</strong>
                                <div id="targetModeDisplay" class="badge badge-warning">STAGING</div>
                            </div>
                            
                            <div class="mb-3">
                                <strong>Target Organizations:</strong>
                                <div id="targetOrgsDisplay" class="small text-muted">vATCSCC</div>
                            </div>
                            
                            <button class="btn btn-success btn-lg btn-block" id="submitAllBtn" disabled>
                                <i class="fas fa-paper-plane mr-1"></i>
                                <span id="submitBtnText">Submit to Staging</span>
                            </button>
                            
                            <div class="mt-2 small text-muted text-center" id="submitHint">
                                Entries will post to staging channels for review
                            </div>
                        </div>
                    </div>
                    
                    <!-- Recent Activity -->
                    <div class="card shadow-sm">
                        <div class="card-header">
                            <span class="tmi-section-title">
                                <i class="fas fa-history mr-1"></i> Recent Posts
                            </span>
                        </div>
                        <div class="card-body p-0" style="max-height: 250px; overflow-y: auto;">
                            <div id="recentPostsList" class="list-group list-group-flush">
                                <div class="list-group-item text-center text-muted py-3">
                                    <i class="fas fa-clock"></i> No recent posts
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
    </div>
    
    <?php else: ?>
    
    <div class="alert alert-danger">
        <h4><i class="fas fa-lock"></i> Access Denied</h4>
        <p>You must be logged in with appropriate permissions to access the TMI Publisher.</p>
        <a href="login/" class="btn btn-primary">Login with VATSIM</a>
    </div>
    
    <?php endif; ?>

</div>

<!-- Preview Modal -->
<div class="modal fade" id="previewModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content bg-dark text-white">
            <div class="modal-header border-secondary">
                <h5 class="modal-title"><i class="fas fa-eye"></i> Message Preview</h5>
                <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
            </div>
            <div class="modal-body" id="previewModalContent" style="font-family: monospace; white-space: pre-wrap;"></div>
            <div class="modal-footer border-secondary">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- Post Result Modal -->
<div class="modal fade" id="resultModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-check-circle text-success"></i> Post Results</h5>
                <button type="button" class="close" data-dismiss="modal">&times;</button>
            </div>
            <div class="modal-body" id="resultModalContent"></div>
            <div class="modal-footer">
                <button type="button" class="btn btn-primary" data-dismiss="modal">OK</button>
            </div>
        </div>
    </div>
</div>

<?php include('load/footer.php'); ?>

<!-- Pass config to JS -->
<script>
window.TMI_PUBLISHER_CONFIG = {
    userCid: <?= json_encode($userCid) ?>,
    userName: <?= json_encode($userName) ?>,
    userPrivileged: <?= json_encode($userPrivileged) ?>,
    userHomeOrg: <?= json_encode($userHomeOrg) ?>,
    discordOrgs: <?= json_encode($discordOrgs) ?>,
    stagingRequired: <?= defined('TMI_STAGING_REQUIRED') && TMI_STAGING_REQUIRED ? 'true' : 'false' ?>,
    crossBorderAutoDetect: <?= defined('TMI_CROSS_BORDER_AUTO_DETECT') && TMI_CROSS_BORDER_AUTO_DETECT ? 'true' : 'false' ?>
};
</script>
<script src="assets/js/tmi-publish.js"></script>
</body>
</html>
