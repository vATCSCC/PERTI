<?php
/**
 * Unified TMI Publisher
 * 
 * Combined NTML Entry + Advisory Builder with multi-Discord posting support.
 * Supports staging→production workflow and cross-border TMI detection.
 * 
 * NTML Entry Types: MIT, MINIT, DELAY, CONFIG, STOP, APREQ/CFR, TBM, CANCEL
 * Advisory Types: Operations Plan, Free Form, Hotline, SWAP Implementation
 * 
 * v1.7.0 - FAA-style Active TMI Display
 *   - Enhanced restrictions table (FAA format: REQUESTING | PROVIDING | RESTRICTION | START TIME | STOP TIME)
 *   - Filter controls (Requesting/Providing Facility, Type, Status)
 *   - Auto-refresh with countdown timer (60 seconds)
 *   - Status summary cards (Active, Scheduled, Cancelled, Advisories)
 *   - Expandable advisory cards
 *   - Cancel action support
 * 
 * Note: GS/GDP → GDT page, Reroutes/AFP → route.php
 * 
 * @package PERTI
 * @subpackage TMI
 * @version 1.8.0
 * @date 2026-01-28
 */

include("sessions/handler.php");
if (session_status() == PHP_SESSION_NONE) {
    session_start();
    ob_start();
}

include("load/config.php");
include("load/connect.php");

// Check Permissions - TMI Publisher is open to all, but profile must be set
// Profile info (Operating Initials, Home Facility) is stored in browser localStorage
$perm = true; // Always allow access - JS will check if profile is set before posting
$userCid = null;
$userName = null;
$userPrivileged = false;
$userHomeOrg = 'vatcscc';

// If logged in via VATSIM, use that info
if (isset($_SESSION['VATSIM_CID'])) {
    $userCid = session_get('VATSIM_CID', '');
    $userName = session_get('VATSIM_FIRST_NAME', '') . ' ' . session_get('VATSIM_LAST_NAME', '');

    // Check for privileged role
    $p_check = $conn_sqli->query("SELECT * FROM users WHERE cid='$userCid'");
    if ($p_check) {
        $row = $p_check->fetch_assoc();
        $privilegedRoles = ['Admin', 'NAS Ops', 'NTMO', 'NTMS'];
        if ($row && isset($row['role']) && in_array($row['role'], $privilegedRoles)) {
            $userPrivileged = true;
        }
    }
} elseif (defined('DEV')) {
    // Dev mode defaults
    $userPrivileged = true;
    $userCid = $_SESSION['VATSIM_CID'] = 0;
    $userName = $_SESSION['VATSIM_FIRST_NAME'] = 'Dev';
    $_SESSION['VATSIM_LAST_NAME'] = 'User';
}
// If not logged in and not dev mode, name/cid will be null - JS profile will be used

// Load Discord organization config
$discordOrgs = [];
if (defined('DISCORD_ORGANIZATIONS')) {
    $allOrgs = json_decode(DISCORD_ORGANIZATIONS, true);
    if (is_array($allOrgs)) {
        foreach ($allOrgs as $code => $config) {
            if (!empty($config['enabled'])) {
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

if (empty($discordOrgs)) {
    $discordOrgs['vatcscc'] = [
        'name' => 'vATCSCC',
        'region' => 'US',
        'default' => true,
        'testing_only' => false,
    ];
}

// Calculate default times (now to T+4h, snapped to :14/:29/:44/:59)
function snapToQuarter($timestamp, $roundUp = false) {
    $minutes = intval(date('i', $timestamp));
    $snapPoints = [14, 29, 44, 59];
    
    foreach ($snapPoints as $snap) {
        if ($minutes <= $snap) {
            $snappedMinutes = $snap;
            break;
        }
    }
    
    if (!isset($snappedMinutes)) {
        // Past 59, snap to next hour's :14
        $snappedMinutes = 14;
        $timestamp = strtotime('+1 hour', $timestamp);
    }
    
    $hour = intval(date('H', $timestamp));
    $day = intval(date('d', $timestamp));
    $month = intval(date('m', $timestamp));
    $year = intval(date('Y', $timestamp));
    
    return gmmktime($hour, $snappedMinutes, 0, $month, $day, $year);
}

$nowUtc = time();
$defaultStartTime = snapToQuarter($nowUtc);
$defaultEndTime = snapToQuarter($nowUtc + (4 * 3600));

$defaultStartFormatted = gmdate('H:i', $defaultStartTime);
$defaultEndFormatted = gmdate('H:i', $defaultEndTime);
$defaultStartDatetime = gmdate('Y-m-d\TH:i', $defaultStartTime);
$defaultEndDatetime = gmdate('Y-m-d\TH:i', $defaultEndTime);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php $page_title = "TMI Publisher"; include("load/header.php"); ?>
    <link rel="stylesheet" href="assets/css/info-bar.css">
<link rel="stylesheet" href="assets/css/tmi-publish.css?v=1.7">
</head>
<body>

<?php include('load/nav.php'); ?>

<section class="d-flex align-items-center position-relative bg-position-center fh-section overflow-hidden pt-6 jarallax bg-dark text-light" data-jarallax data-speed="0.3">
    <div class="container-fluid pt-2 pb-4 py-lg-5">
        <img class="jarallax-img" src="assets/img/jumbotron/main.png" alt="" style="opacity: 50%;">
        <center>
            <h1><i class="fas fa-broadcast-tower"></i> TMI Publisher</h1>
            <p class="text-white-50 mb-0">NTML Entries &amp; Advisories</p>
        </center>
    </div>
</section>

<div class="container-fluid mt-4 mb-5">
    
    <?php if ($perm): ?>
    
    <!-- Info Bar -->
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
                <div class="card shadow-sm perti-info-card h-100" style="cursor: pointer;" onclick="TMIPublisher.showProfileModal()" data-toggle="tooltip" title="Click to edit profile">
                    <div class="card-body d-flex justify-content-between align-items-center py-2 px-3">
                        <div>
                            <div class="perti-info-label" id="userInfoLabel"><?= $userName ? 'Logged In As' : 'User Profile' ?></div>
                            <div class="font-weight-bold" id="userInfoDisplay">
                                <i class="fas fa-user-edit mr-1 small text-muted"></i>
                                <?php if ($userName): ?>
                                    <?= htmlspecialchars($userName) ?>
                                <?php else: ?>
                                    <span class="text-warning">Set Up Profile</span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php if ($userPrivileged): ?>
                        <span class="badge badge-warning ml-2" data-toggle="tooltip" title="Privileged: Can post to all organizations">PRIV</span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
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
                <i class="fas fa-clipboard-list mr-1"></i> NTML Entry
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link" id="advisory-tab" data-toggle="tab" href="#advisoryPanel" role="tab">
                <i class="fas fa-bullhorn mr-1"></i> Advisory
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link" id="queue-tab" data-toggle="tab" href="#queuePanel" role="tab">
                <i class="fas fa-list mr-1"></i> Queue 
                <span class="badge badge-secondary" id="queueBadge">0</span>
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link" id="active-tab" data-toggle="tab" href="#activePanel" role="tab">
                <i class="fas fa-broadcast-tower mr-1"></i> Active TMIs
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link" id="coordination-tab" data-toggle="tab" href="#coordinationPanel" role="tab">
                <i class="fas fa-handshake mr-1"></i> Coordination
                <span class="badge badge-warning" id="pendingProposalsBadge" style="display: none;">0</span>
            </a>
        </li>
    </ul>

    <div class="tab-content" id="publisherTabContent">
        
        <!-- NTML Entry Panel -->
        <div class="tab-pane fade show active" id="ntmlPanel" role="tabpanel">
            <div class="row">
                <div class="col-lg-8">
                    <!-- NTML Type Selection -->
                    <div class="card shadow-sm mb-3">
                        <div class="card-header bg-dark text-white">
                            <span class="tmi-section-title">
                                <i class="fas fa-list-alt mr-1"></i> NTML Entry Type
                            </span>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-3 col-6 mb-2">
                                    <div class="card ntml-type-card text-center p-2 selected" data-type="MIT">
                                        <div class="ntml-type-icon"><i class="fas fa-ruler-horizontal"></i></div>
                                        <div class="small font-weight-bold">MIT</div>
                                        <div class="text-muted" style="font-size: 0.65rem;">Miles-In-Trail</div>
                                    </div>
                                </div>
                                <div class="col-md-3 col-6 mb-2">
                                    <div class="card ntml-type-card text-center p-2" data-type="MINIT">
                                        <div class="ntml-type-icon"><i class="fas fa-stopwatch"></i></div>
                                        <div class="small font-weight-bold">MINIT</div>
                                        <div class="text-muted" style="font-size: 0.65rem;">Minutes-In-Trail</div>
                                    </div>
                                </div>
                                <div class="col-md-3 col-6 mb-2">
                                    <div class="card ntml-type-card text-center p-2" data-type="DELAY">
                                        <div class="ntml-type-icon"><i class="fas fa-clock"></i></div>
                                        <div class="small font-weight-bold">Delay</div>
                                        <div class="text-muted" style="font-size: 0.65rem;">E/D, A/D, D/D</div>
                                    </div>
                                </div>
                                <div class="col-md-3 col-6 mb-2">
                                    <div class="card ntml-type-card text-center p-2" data-type="CONFIG">
                                        <div class="ntml-type-icon"><i class="fas fa-plane-arrival"></i></div>
                                        <div class="small font-weight-bold">Config</div>
                                        <div class="text-muted" style="font-size: 0.65rem;">Airport Config</div>
                                    </div>
                                </div>
                                <div class="col-md-3 col-6 mb-2">
                                    <div class="card ntml-type-card text-center p-2" data-type="STOP">
                                        <div class="ntml-type-icon"><i class="fas fa-hand-paper"></i></div>
                                        <div class="small font-weight-bold">STOP</div>
                                        <div class="text-muted" style="font-size: 0.65rem;">Flow Stoppage</div>
                                    </div>
                                </div>
                                <div class="col-md-3 col-6 mb-2">
                                    <div class="card ntml-type-card text-center p-2" data-type="APREQ">
                                        <div class="ntml-type-icon"><i class="fas fa-phone-alt"></i></div>
                                        <div class="small font-weight-bold">APREQ/CFR</div>
                                        <div class="text-muted" style="font-size: 0.65rem;">Call for Release</div>
                                    </div>
                                </div>
                                <div class="col-md-3 col-6 mb-2">
                                    <div class="card ntml-type-card text-center p-2" data-type="TBM">
                                        <div class="ntml-type-icon"><i class="fas fa-tachometer-alt"></i></div>
                                        <div class="small font-weight-bold">TBM</div>
                                        <div class="text-muted" style="font-size: 0.65rem;">Time-Based Metering</div>
                                    </div>
                                </div>
                                <div class="col-md-3 col-6 mb-2">
                                    <div class="card ntml-type-card text-center p-2" data-type="CANCEL">
                                        <div class="ntml-type-icon"><i class="fas fa-times-circle"></i></div>
                                        <div class="small font-weight-bold">Cancel</div>
                                        <div class="text-muted" style="font-size: 0.65rem;">Cancel TMI</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- NTML Form - Dynamic based on type -->
                    <div id="ntmlFormContainer">
                        <!-- JS will populate this -->
                        <div class="text-center text-muted py-4">
                            <i class="fas fa-spinner fa-spin"></i> Loading form...
                        </div>
                    </div>
                </div>
                
                <div class="col-lg-4">
                    <!-- NTML Preview -->
                    <div class="card shadow-sm mb-3">
                        <div class="card-header">
                            <span class="tmi-section-title">
                                <i class="fas fa-eye mr-1"></i> NTML Preview
                            </span>
                        </div>
                        <div class="card-body">
                            <pre id="ntml_preview" class="ntml-preview">Enter details to preview...</pre>
                        </div>
                    </div>
                    
                    <!-- Discord Targets -->
                    <div class="card shadow-sm">
                        <div class="card-header">
                            <span class="tmi-section-title">
                                <i class="fab fa-discord mr-1"></i> Post To Organizations
                            </span>
                        </div>
                        <div class="card-body">
                            <?php foreach ($discordOrgs as $code => $org): ?>
                            <div class="custom-control custom-checkbox mb-2">
                                <input type="checkbox" class="custom-control-input discord-org-checkbox" 
                                       id="org_<?= $code ?>" value="<?= $code ?>" 
                                       data-region="<?= $org['region'] ?>"
                                       <?= $org['default'] ? 'checked' : '' ?>>
                                <label class="custom-control-label" for="org_<?= $code ?>">
                                    <?= htmlspecialchars($org['name']) ?>
                                    <small class="text-muted">(<?= $org['region'] ?>)</small>
                                </label>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Advisory Panel -->
        <div class="tab-pane fade" id="advisoryPanel" role="tabpanel">
            <div class="row">
                <div class="col-lg-8">
                    <!-- Advisory Type Selection -->
                    <div class="card shadow-sm mb-3">
                        <div class="card-header bg-info text-white">
                            <span class="tmi-section-title">
                                <i class="fas fa-bullhorn mr-1"></i> Advisory Type
                            </span>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-3 col-6 mb-2">
                                    <div class="card advisory-type-card text-center p-2 selected" data-type="OPS_PLAN">
                                        <div class="advisory-type-icon text-primary"><i class="fas fa-calendar-alt"></i></div>
                                        <div class="small font-weight-bold">Ops Plan</div>
                                        <div class="text-muted" style="font-size: 0.65rem;">Operations Plan</div>
                                    </div>
                                </div>
                                <div class="col-md-3 col-6 mb-2">
                                    <div class="card advisory-type-card text-center p-2" data-type="FREE_FORM">
                                        <div class="advisory-type-icon text-secondary"><i class="fas fa-file-alt"></i></div>
                                        <div class="small font-weight-bold">Free-Form</div>
                                        <div class="text-muted" style="font-size: 0.65rem;">ATCSCC Advisory</div>
                                    </div>
                                </div>
                                <div class="col-md-3 col-6 mb-2">
                                    <div class="card advisory-type-card text-center p-2" data-type="HOTLINE">
                                        <div class="advisory-type-icon text-danger"><i class="fas fa-phone-volume"></i></div>
                                        <div class="small font-weight-bold">Hotline</div>
                                        <div class="text-muted" style="font-size: 0.65rem;">Activation/Term</div>
                                    </div>
                                </div>
                                <div class="col-md-3 col-6 mb-2">
                                    <div class="card advisory-type-card text-center p-2" data-type="SWAP">
                                        <div class="advisory-type-icon text-warning"><i class="fas fa-cloud-sun-rain"></i></div>
                                        <div class="small font-weight-bold">SWAP</div>
                                        <div class="text-muted" style="font-size: 0.65rem;">Implementation Plan</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Advisory Form - Dynamic -->
                    <div id="advisoryFormContainer">
                        <!-- Ops Plan Form (default) -->
                        <div class="card shadow-sm mb-3" id="adv_form_ops_plan">
                            <div class="card-header">
                                <span class="tmi-section-title">
                                    <i class="fas fa-calendar-alt mr-1"></i> Operations Plan Details
                                </span>
                            </div>
                            <div class="card-body">
                                <div class="form-row">
                                    <div class="form-group col-md-4">
                                        <label class="tmi-label mb-0">Advisory #</label>
                                        <input type="text" class="form-control" id="adv_number" placeholder="001">
                                    </div>
                                    <div class="form-group col-md-4">
                                        <label class="tmi-label mb-0">Facility</label>
                                        <input type="text" class="form-control" id="adv_facility" value="DCC">
                                    </div>
                                    <div class="form-group col-md-4">
                                        <label class="tmi-label mb-0">CTL Element</label>
                                        <input type="text" class="form-control" id="adv_ctl_element" placeholder="KATL">
                                    </div>
                                </div>
                                <div class="form-row">
                                    <div class="form-group col-md-6">
                                        <label class="tmi-label mb-0">Valid From (UTC)</label>
                                        <input type="datetime-local" class="form-control" id="adv_start" value="<?= $defaultStartDatetime ?>">
                                    </div>
                                    <div class="form-group col-md-6">
                                        <label class="tmi-label mb-0">Valid Until (UTC)</label>
                                        <input type="datetime-local" class="form-control" id="adv_end" value="<?= $defaultEndDatetime ?>">
                                    </div>
                                </div>
                                <div class="form-group">
                                    <label class="tmi-label mb-0">Plan Details</label>
                                    <textarea class="form-control" id="adv_body" rows="6" placeholder="Operations plan details..."></textarea>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="d-flex justify-content-between">
                        <button class="btn btn-outline-secondary" id="adv_reset" type="button">
                            <i class="fas fa-undo mr-1"></i> Reset
                        </button>
                        <button class="btn btn-primary" id="adv_add_to_queue" type="button">
                            <i class="fas fa-plus mr-1"></i> Add to Queue
                        </button>
                    </div>
                </div>

                <div class="col-lg-4">
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
                    
                    <!-- Discord Targets -->
                    <div class="card shadow-sm">
                        <div class="card-header">
                            <span class="tmi-section-title">
                                <i class="fab fa-discord mr-1"></i> Post To Organizations
                            </span>
                        </div>
                        <div class="card-body">
                            <?php foreach ($discordOrgs as $code => $org): ?>
                            <div class="custom-control custom-checkbox mb-2">
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
                            <div class="mb-3 text-center">
                                <div class="h3 mb-0">
                                    <span id="submitCount">0</span>
                                    <small class="text-muted">entries ready</small>
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <strong>Target Mode:</strong>
                                <span id="targetModeDisplay" class="badge badge-warning ml-2">STAGING</span>
                            </div>
                            
                            <div class="mb-3">
                                <strong>Organizations:</strong>
                                <div id="targetOrgsDisplay" class="small text-muted">vATCSCC</div>
                            </div>
                            
                            <button class="btn btn-success btn-lg btn-block" id="submitAllBtn" disabled>
                                <i class="fas fa-paper-plane mr-1"></i>
                                <span id="submitBtnText">Submit to Staging</span>
                            </button>
                            
                            <div class="mt-2 small text-muted text-center" id="submitHint">
                                Posts to staging channels for review
                            </div>
                        </div>
                    </div>
                    
                    <!-- Staged Entries -->
                    <div class="card shadow-sm">
                        <div class="card-header">
                            <span class="tmi-section-title">
                                <i class="fas fa-clock mr-1"></i> Staged (Awaiting Promotion)
                            </span>
                        </div>
                        <div class="card-body p-0" style="max-height: 250px; overflow-y: auto;">
                            <div id="recentPostsList" class="list-group list-group-flush">
                                <div class="list-group-item text-center text-muted py-3">
                                    <i class="fas fa-spinner fa-spin"></i> Loading...
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Active TMIs Panel (FAA-style) -->
        <div class="tab-pane fade" id="activePanel" role="tabpanel">
            
            <!-- Status Header -->
            <div class="tmi-status-header mb-3">
                <div class="row align-items-center">
                    <div class="col-md-6">
                        <h5 class="mb-0"><i class="fas fa-broadcast-tower mr-2"></i>Current Restrictions &amp; Advisories</h5>
                    </div>
                    <div class="col-md-6 text-md-right">
                        <div class="refresh-timer d-inline-block mr-3">
                            <i class="fas fa-sync-alt mr-1"></i>
                            <span>Refreshes every minute. Last updated: <span id="lastRefreshTime">--:--:-- UTC</span></span>
                            <span class="ml-2 countdown" id="refreshCountdown">60s</span>
                        </div>
                        <button class="btn btn-sm btn-light" id="refreshActiveTmis">
                            <i class="fas fa-sync-alt"></i> Refresh Now
                        </button>
                    </div>
                </div>
            </div>
            
            <!-- Status Summary Cards -->
            <div class="row mb-3">
                <div class="col-md-3 col-6 mb-2">
                    <div class="card bg-success text-white h-100">
                        <div class="card-body py-2 text-center">
                            <div class="tmi-status-count" id="activeCount">0</div>
                            <div class="tmi-status-label">Active</div>
                        </div>
                    </div>
                </div>
                <div class="col-md-3 col-6 mb-2">
                    <div class="card bg-info text-white h-100">
                        <div class="card-body py-2 text-center">
                            <div class="tmi-status-count" id="scheduledCount">0</div>
                            <div class="tmi-status-label">Scheduled</div>
                        </div>
                    </div>
                </div>
                <div class="col-md-3 col-6 mb-2">
                    <div class="card bg-danger text-white h-100">
                        <div class="card-body py-2 text-center">
                            <div class="tmi-status-count" id="cancelledCount">0</div>
                            <div class="tmi-status-label">Cancelled (4h)</div>
                        </div>
                    </div>
                </div>
                <div class="col-md-3 col-6 mb-2">
                    <div class="card bg-primary text-white h-100">
                        <div class="card-body py-2 text-center">
                            <div class="tmi-status-count" id="advisoryCount">0</div>
                            <div class="tmi-status-label">Advisories</div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Filter Controls -->
            <div id="activeTmiFilters" class="mb-3">
                <div class="row align-items-end">
                    <div class="col-md-2 col-6 mb-2">
                        <label class="small text-muted mb-0">Source</label>
                        <select class="form-control form-control-sm" id="filterSource">
                            <option value="ALL">All Sources</option>
                            <option value="PRODUCTION" selected>Production</option>
                            <option value="STAGING">Staging</option>
                        </select>
                    </div>
                    <div class="col-md-2 col-6 mb-2">
                        <label class="small text-muted mb-0">Requesting</label>
                        <select class="form-control form-control-sm facility-filter-select" id="filterReqFac" multiple="multiple" data-placeholder="All Facilities">
                        </select>
                    </div>
                    <div class="col-md-2 col-6 mb-2">
                        <label class="small text-muted mb-0">Providing</label>
                        <select class="form-control form-control-sm facility-filter-select" id="filterProvFac" multiple="multiple" data-placeholder="All Facilities">
                        </select>
                    </div>
                    <div class="col-md-2 col-6 mb-2">
                        <label class="small text-muted mb-0">Type</label>
                        <select class="form-control form-control-sm" id="filterType" multiple="multiple" data-placeholder="All Types">
                            <option value="MIT">MIT</option>
                            <option value="MINIT">MINIT</option>
                            <option value="STOP">STOP</option>
                            <option value="APREQ">APREQ/CFR</option>
                            <option value="TBM">TBM</option>
                            <option value="CONFIG">Config</option>
                            <option value="DELAY">Delay</option>
                            <option value="GDP">GDP</option>
                            <option value="GS">Ground Stop</option>
                            <option value="REROUTE">Reroute</option>
                        </select>
                    </div>
                    <div class="col-md-2 col-6 mb-2">
                        <label class="small text-muted mb-0">Status</label>
                        <select class="form-control form-control-sm" id="filterStatus" multiple="multiple" data-placeholder="All Status">
                            <option value="ACTIVE" selected>Active</option>
                            <option value="SCHEDULED" selected>Scheduled</option>
                            <option value="CANCELLED">Cancelled</option>
                        </select>
                    </div>
                    <div class="col-md-2 col-6 mb-2">
                        <button class="btn btn-sm btn-primary btn-block" id="applyFilters">
                            <i class="fas fa-filter"></i> Apply
                        </button>
                    </div>
                    <div class="col-md-2 col-6 mb-2">
                        <button class="btn btn-sm btn-outline-secondary btn-block" id="resetFilters">
                            <i class="fas fa-undo"></i> Reset
                        </button>
                    </div>
                </div>
            </div>
            
            <!-- Restrictions Table (FAA-style) -->
            <div class="card shadow-sm mb-3">
                <div class="card-header py-2 d-flex justify-content-between align-items-center" style="background-color: #1a5276;">
                    <span class="text-white font-weight-bold">
                        <i class="fas fa-ruler-horizontal mr-1"></i> Current Restrictions
                        <span class="badge badge-light ml-2" id="restrictionCount">0</span>
                    </span>
                    <div id="batchCancelControls" style="display: none;">
                        <span class="text-white small mr-2"><span id="selectedCount">0</span> selected</span>
                        <button class="btn btn-sm btn-danger" id="batchCancelBtn" title="Cancel Selected">
                            <i class="fas fa-times-circle"></i> Cancel Selected
                        </button>
                    </div>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-sm table-hover mb-0" id="restrictionsTable">
                            <thead>
                                <tr>
                                    <th style="width: 30px;"><input type="checkbox" id="selectAllRestrictions" title="Select All"></th>
                                    <th style="width: 100px;">REQUESTING</th>
                                    <th style="width: 100px;">PROVIDING</th>
                                    <th>RESTRICTION</th>
                                    <th style="width: 150px;">START TIME</th>
                                    <th style="width: 150px;">END TIME</th>
                                    <th style="width: 60px;"></th>
                                </tr>
                            </thead>
                            <tbody id="restrictionsTableBody">
                                <tr>
                                    <td colspan="7" class="text-center text-muted py-4">
                                        <i class="fas fa-spinner fa-spin fa-2x mb-2"></i><br>
                                        Loading restrictions...
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            
            <!-- Advisories Section -->
            <div class="card shadow-sm mb-3">
                <div class="card-header py-2 bg-primary text-white">
                    <span class="font-weight-bold">
                        <i class="fas fa-bullhorn mr-1"></i> Active Advisories
                        <span class="badge badge-light ml-2" id="advisoryCountHeader">0</span>
                    </span>
                </div>
                <div class="card-body p-2" id="advisoriesContainer">
                    <div class="text-center text-muted py-4">
                        <i class="fas fa-spinner fa-spin fa-2x mb-2"></i><br>
                        Loading advisories...
                    </div>
                </div>
            </div>

        </div>

        <!-- Coordination Panel -->
        <div class="tab-pane fade" id="coordinationPanel" role="tabpanel">
            <!-- Coordination Info -->
            <div class="alert alert-info mb-3">
                <i class="fas fa-info-circle mr-1"></i>
                <strong>TMI Coordination</strong> - TMIs submitted for coordination require approval from all specified facilities before becoming active.
                Facilities approve via Discord emoji reactions. DCC can override any proposal.
            </div>

            <!-- Pending Proposals -->
            <div class="card shadow-sm mb-3">
                <div class="card-header py-2 bg-warning">
                    <span class="font-weight-bold">
                        <i class="fas fa-clock mr-1"></i> Pending Proposals
                        <span class="badge badge-dark ml-2" id="pendingCount">0</span>
                    </span>
                    <button class="btn btn-sm btn-outline-dark float-right" id="refreshProposals">
                        <i class="fas fa-sync-alt"></i> Refresh
                    </button>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-sm table-hover mb-0" id="proposalsTable">
                            <thead>
                                <tr>
                                    <th style="width: 60px;">ID</th>
                                    <th style="width: 80px;">TYPE</th>
                                    <th style="width: 100px;">ELEMENT</th>
                                    <th>RESTRICTION</th>
                                    <th style="width: 120px;">PROPOSED BY</th>
                                    <th style="width: 140px;">DEADLINE</th>
                                    <th style="width: 100px;">APPROVALS</th>
                                    <th style="width: 80px;">STATUS</th>
                                    <th style="width: 120px;">ACTIONS</th>
                                </tr>
                            </thead>
                            <tbody id="proposalsTableBody">
                                <tr>
                                    <td colspan="9" class="text-center text-muted py-4">
                                        <i class="fas fa-spinner fa-spin fa-2x mb-2"></i><br>
                                        Loading proposals...
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Recent Proposals (approved/denied/expired) -->
            <div class="card shadow-sm mb-3">
                <div class="card-header py-2 bg-dark text-white">
                    <span class="font-weight-bold">
                        <i class="fas fa-history mr-1"></i> Recent Proposals (Last 24h)
                        <span class="badge badge-light ml-2" id="recentCount">0</span>
                    </span>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-sm table-hover mb-0" id="recentProposalsTable">
                            <thead>
                                <tr>
                                    <th style="width: 60px;">ID</th>
                                    <th style="width: 80px;">TYPE</th>
                                    <th style="width: 100px;">ELEMENT</th>
                                    <th>RESTRICTION</th>
                                    <th style="width: 120px;">PROPOSED BY</th>
                                    <th style="width: 100px;">RESULT</th>
                                    <th style="width: 140px;">RESOLVED AT</th>
                                    <th style="width: 80px;">ACTIONS</th>
                                </tr>
                            </thead>
                            <tbody id="recentProposalsTableBody">
                                <tr>
                                    <td colspan="8" class="text-center text-muted py-3">
                                        <em>No recent proposals</em>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

    </div>
    
    <!-- Facility Datalist -->
    <datalist id="facilityList">
        <option value="ZAB">Albuquerque Center</option>
        <option value="ZAU">Chicago Center</option>
        <option value="ZBW">Boston Center</option>
        <option value="ZDC">Washington Center</option>
        <option value="ZDV">Denver Center</option>
        <option value="ZFW">Fort Worth Center</option>
        <option value="ZHU">Houston Center</option>
        <option value="ZID">Indianapolis Center</option>
        <option value="ZJX">Jacksonville Center</option>
        <option value="ZKC">Kansas City Center</option>
        <option value="ZLA">Los Angeles Center</option>
        <option value="ZLC">Salt Lake Center</option>
        <option value="ZMA">Miami Center</option>
        <option value="ZME">Memphis Center</option>
        <option value="ZMP">Minneapolis Center</option>
        <option value="ZNY">New York Center</option>
        <option value="ZOA">Oakland Center</option>
        <option value="ZOB">Cleveland Center</option>
        <option value="ZSE">Seattle Center</option>
        <option value="ZTL">Atlanta Center</option>
    </datalist>
    
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

<!-- Result Modal -->
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

<!-- User Profile Modal -->
<div class="modal fade" id="userProfileModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content bg-dark text-white">
            <div class="modal-header border-secondary">
                <h5 class="modal-title"><i class="fas fa-user mr-2"></i>User Profile</h5>
                <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
            </div>
            <div class="modal-body">
                <?php if (!$userName): ?>
                <div class="alert alert-info small mb-3">
                    <i class="fas fa-info-circle mr-1"></i>
                    Set your profile to use TMI Publisher. All fields are required.
                </div>
                <?php endif; ?>
                <div class="mb-3">
                    <label class="form-label small text-muted">Name <span class="text-danger">*</span></label>
                    <input type="text" class="form-control <?= $userName ? 'bg-secondary text-white' : '' ?>" id="profileName" value="<?= htmlspecialchars($userName ?? '') ?>" <?= $userName ? 'readonly' : 'placeholder="Your Name"' ?>>
                </div>
                <div class="mb-3">
                    <label class="form-label small text-muted">CID <span class="text-danger">*</span></label>
                    <input type="text" class="form-control <?= $userCid ? 'bg-secondary text-white' : '' ?>" id="profileCid" value="<?= htmlspecialchars($userCid ?? '') ?>" <?= $userCid ? 'readonly' : 'placeholder="VATSIM CID"' ?>>
                </div>
                <div class="mb-3">
                    <label class="form-label small text-muted">Operating Initials</label>
                    <input type="text" class="form-control text-uppercase" id="profileOI" maxlength="3" placeholder="XX" style="max-width: 80px;">
                </div>
                <div class="mb-3">
                    <label class="form-label small text-muted">Home Facility</label>
                    <select class="form-control" id="profileFacility">
                        <option value="">-- Select Facility --</option>
                        <optgroup label="ARTCCs (US)">
                            <option value="ZAB">ZAB - Albuquerque Center</option>
                            <option value="ZAN">ZAN - Anchorage Center</option>
                            <option value="ZAU">ZAU - Chicago Center</option>
                            <option value="ZBW">ZBW - Boston Center</option>
                            <option value="ZDC">ZDC - Washington Center</option>
                            <option value="ZDV">ZDV - Denver Center</option>
                            <option value="ZFW">ZFW - Fort Worth Center</option>
                            <option value="ZHN">ZHN - Honolulu Center</option>
                            <option value="ZHU">ZHU - Houston Center</option>
                            <option value="ZID">ZID - Indianapolis Center</option>
                            <option value="ZJX">ZJX - Jacksonville Center</option>
                            <option value="ZKC">ZKC - Kansas City Center</option>
                            <option value="ZLA">ZLA - Los Angeles Center</option>
                            <option value="ZLC">ZLC - Salt Lake Center</option>
                            <option value="ZMA">ZMA - Miami Center</option>
                            <option value="ZME">ZME - Memphis Center</option>
                            <option value="ZMP">ZMP - Minneapolis Center</option>
                            <option value="ZNY">ZNY - New York Center</option>
                            <option value="ZOA">ZOA - Oakland Center</option>
                            <option value="ZOB">ZOB - Cleveland Center</option>
                            <option value="ZSE">ZSE - Seattle Center</option>
                            <option value="ZTL">ZTL - Atlanta Center</option>
                        </optgroup>
                        <optgroup label="TRACONs">
                            <option value="A80">A80 - Atlanta TRACON</option>
                            <option value="A90">A90 - Boston TRACON</option>
                            <option value="C90">C90 - Chicago TRACON</option>
                            <option value="D01">D01 - Denver TRACON</option>
                            <option value="D10">D10 - Dallas/Fort Worth TRACON</option>
                            <option value="D21">D21 - Detroit TRACON</option>
                            <option value="I90">I90 - Houston TRACON</option>
                            <option value="L30">L30 - Las Vegas TRACON</option>
                            <option value="M98">M98 - Minneapolis TRACON</option>
                            <option value="N90">N90 - New York TRACON</option>
                            <option value="NCT">NCT - NorCal TRACON</option>
                            <option value="P50">P50 - Phoenix TRACON</option>
                            <option value="PCT">PCT - Potomac TRACON</option>
                            <option value="S46">S46 - Seattle TRACON</option>
                            <option value="SCT">SCT - SoCal TRACON</option>
                            <option value="T75">T75 - St. Louis TRACON</option>
                            <option value="Y90">Y90 - Yankee (Cleveland) TRACON</option>
                        </optgroup>
                        <optgroup label="Major Towers">
                            <option value="ATL">ATL - Atlanta Tower</option>
                            <option value="BOS">BOS - Boston Tower</option>
                            <option value="DEN">DEN - Denver Tower</option>
                            <option value="DFW">DFW - Dallas/Fort Worth Tower</option>
                            <option value="DTW">DTW - Detroit Tower</option>
                            <option value="EWR">EWR - Newark Tower</option>
                            <option value="IAD">IAD - Dulles Tower</option>
                            <option value="IAH">IAH - Houston Intercontinental Tower</option>
                            <option value="JFK">JFK - JFK Tower</option>
                            <option value="LAX">LAX - Los Angeles Tower</option>
                            <option value="LGA">LGA - LaGuardia Tower</option>
                            <option value="MIA">MIA - Miami Tower</option>
                            <option value="MSP">MSP - Minneapolis Tower</option>
                            <option value="ORD">ORD - Chicago O'Hare Tower</option>
                            <option value="PHL">PHL - Philadelphia Tower</option>
                            <option value="PHX">PHX - Phoenix Tower</option>
                            <option value="SEA">SEA - Seattle Tower</option>
                            <option value="SFO">SFO - San Francisco Tower</option>
                        </optgroup>
                        <optgroup label="Canadian FIRs">
                            <option value="CZEG">CZEG - Edmonton FIR</option>
                            <option value="CZQM">CZQM - Moncton FIR</option>
                            <option value="CZQX">CZQX - Gander FIR</option>
                            <option value="CZVR">CZVR - Vancouver FIR</option>
                            <option value="CZWG">CZWG - Winnipeg FIR</option>
                            <option value="CZYZ">CZYZ - Toronto FIR</option>
                        </optgroup>
                        <optgroup label="International">
                            <option value="MMEX">MMEX - Mexico</option>
                            <option value="MUFH">MUFH - Havana FIR</option>
                            <option value="TJSJ">TJSJ - San Juan CERAP</option>
                        </optgroup>
                        <optgroup label="Command Center">
                            <option value="DCC">DCC - ATCSCC</option>
                        </optgroup>
                    </select>
                </div>
            </div>
            <div class="modal-footer border-secondary">
                <button type="button" class="btn btn-primary" onclick="TMIPublisher.saveProfile()">Save</button>
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
            </div>
        </div>
    </div>
</div>

<?php include('load/footer.php'); ?>

<!-- Config for JS -->
<?php
// Get user operating initials if available (from user table or session)
$userOI = null;
if (isset($row) && !empty($row['operating_initials'])) {
    $userOI = strtoupper($row['operating_initials']);
} elseif (!empty($userName)) {
    // Extract initials from name (first letter of first and last word)
    $nameParts = preg_split('/\s+/', trim($userName));
    if (count($nameParts) >= 2) {
        $userOI = strtoupper(substr($nameParts[0], 0, 1) . substr($nameParts[count($nameParts) - 1], 0, 1));
    } else {
        $userOI = strtoupper(substr($userName, 0, 2));
    }
}
?>
<script>
window.TMI_PUBLISHER_CONFIG = {
    userCid: <?= json_encode($userCid) ?>,
    userName: <?= json_encode($userName) ?>,
    userOI: <?= json_encode($userOI) ?>,
    userPrivileged: <?= json_encode($userPrivileged) ?>,
    userHomeOrg: <?= json_encode($userHomeOrg) ?>,
    discordOrgs: <?= json_encode($discordOrgs) ?>,
    stagingRequired: <?= defined('TMI_STAGING_REQUIRED') && TMI_STAGING_REQUIRED ? 'true' : 'false' ?>,
    crossBorderAutoDetect: <?= defined('TMI_CROSS_BORDER_AUTO_DETECT') && TMI_CROSS_BORDER_AUTO_DETECT ? 'true' : 'false' ?>,
    defaultValidFrom: <?= json_encode($defaultStartFormatted) ?>,
    defaultValidUntil: <?= json_encode($defaultEndFormatted) ?>
};
</script>
<script src="assets/js/tmi-publish.js?v=1.9.0"></script>
<script src="assets/js/tmi-active-display.js?v=1.2.0"></script>
<script>
// Clear potentially corrupted localStorage data on version upgrade
(function() {
    var lastVersion = localStorage.getItem('tmi_publisher_version');
    if (lastVersion !== '1.9.0') {
        localStorage.removeItem('tmi_publisher_queue');
        localStorage.setItem('tmi_publisher_version', '1.9.0');
        console.log('TMI Publisher: Cleared old queue data for version upgrade');
    }
})();
</script>
</body>
</html>
