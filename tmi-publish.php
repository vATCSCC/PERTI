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
require_once("load/perti_constants.php");

// Check Permissions - TMI Publisher is open to all, but profile must be set
// Profile info (Operating Initials, Home Facility) is stored in browser localStorage
$perm = true; // Always allow access - JS will check if profile is set before posting
$userCid = null;
$userName = null;
$userPrivileged = false;
$userRole = null;
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
        if ($row && isset($row['role'])) {
            $userRole = $row['role'];
            if (in_array($row['role'], $privilegedRoles)) {
                $userPrivileged = true;
            }
        }
    }
} elseif (defined('DEV')) {
    // Dev mode defaults
    $userPrivileged = true;
    $userRole = 'Admin';
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
    <link rel="stylesheet" href="assets/css/info-bar.css<?= _v('assets/css/info-bar.css') ?>">
<link rel="stylesheet" href="assets/css/tmi-publish.css<?= _v('assets/css/tmi-publish.css') ?>">
</head>
<body>

<?php include('load/nav.php'); ?>

<?php
// TMI Publisher is PUBLIC - override nav.php's permission check
// Anyone can view active TMIs and create entries (with profile set via JS)
// Only DCC override functions require authenticated login
$perm = true;
?>

<section class="d-flex align-items-center position-relative bg-position-center fh-section overflow-hidden pt-6 jarallax bg-dark text-light" data-jarallax data-speed="0.3">
    <div class="container-fluid pt-2 pb-4 py-lg-5">
        <img class="jarallax-img" src="assets/img/jumbotron/main.png" alt="" style="opacity: 50%;">
        <center>
            <h1><i class="fas fa-broadcast-tower"></i> <?= __('tmiPublish.page.title') ?></h1>
            <p class="text-white-50 mb-0"><?= __('tmiPublish.page.subtitle') ?></p>
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
                            <div class="perti-info-label"><?= __('tmiPublish.page.currentUtc') ?></div>
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
                <div class="card shadow-sm perti-info-card h-100" style="cursor: pointer;" onclick="TMIPublisher.showProfileModal()" data-toggle="tooltip" title="<?= __('tmiPublish.page.clickToEditProfile') ?>">
                    <div class="card-body d-flex justify-content-between align-items-center py-2 px-3">
                        <div>
                            <div class="perti-info-label" id="userInfoLabel"><?= $userName ? __('tmiPublish.page.loggedInAs') : __('tmiPublish.page.userProfile') ?></div>
                            <div class="font-weight-bold" id="userInfoDisplay">
                                <i class="fas fa-user-edit mr-1 small text-muted"></i>
                                <?php if ($userName): ?>
                                    <?= htmlspecialchars($userName) ?>
                                <?php else: ?>
                                    <span class="text-warning"><?= __('tmiPublish.page.setUpProfile') ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php if ($userPrivileged): ?>
                        <span class="badge badge-warning ml-2" data-toggle="tooltip" title="<?= __('tmiPublish.page.privilegedTooltip') ?>">PRIV</span>
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
                            <label class="custom-control-label" for="productionMode"><?= __('tmiPublish.page.production') ?></label>
                        </div>
                        <span class="badge" id="modeIndicator"><?= __('tmiPublish.page.staging') ?></span>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Production Warning -->
    <div class="alert alert-danger production-warning mb-3" id="prodWarning" style="display: none;">
        <i class="fas fa-exclamation-triangle"></i> 
        <strong><?= __('tmiPublish.page.productionWarning') ?></strong> - <?= __('tmiPublish.page.productionWarningText') ?>
    </div>

    <!-- Main Content Tabs -->
    <ul class="nav nav-tabs nav-tabs-publisher mb-3" id="publisherTabs" role="tablist">
        <li class="nav-item">
            <a class="nav-link active" id="ntml-tab" data-toggle="tab" href="#ntmlPanel" role="tab">
                <i class="fas fa-clipboard-list mr-1"></i> <?= __('tmiPublish.page.tabNtml') ?>
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link" id="advisory-tab" data-toggle="tab" href="#advisoryPanel" role="tab">
                <i class="fas fa-bullhorn mr-1"></i> <?= __('tmiPublish.page.tabAdvisory') ?>
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link" id="reroute-tab" data-toggle="tab" href="#reroutePanel" role="tab">
                <i class="fas fa-route mr-1"></i> <?= __('tmiPublish.page.tabReroute') ?>
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link" id="gsgdp-tab" data-toggle="tab" href="#gsgdpPanel" role="tab">
                <i class="fas fa-plane-departure mr-1"></i> <?= __('tmiPublish.page.tabGsGdp') ?>
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link" id="queue-tab" data-toggle="tab" href="#queuePanel" role="tab">
                <i class="fas fa-list mr-1"></i> <?= __('tmiPublish.page.tabQueue') ?>
                <span class="badge badge-secondary" id="queueBadge">0</span>
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link" id="active-tab" data-toggle="tab" href="#activePanel" role="tab">
                <i class="fas fa-broadcast-tower mr-1"></i> <?= __('tmiPublish.page.tabActiveTmis') ?>
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link" id="coordination-tab" data-toggle="tab" href="#coordinationPanel" role="tab">
                <i class="fas fa-handshake mr-1"></i> <?= __('tmiPublish.page.tabCoordination') ?>
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
                                <i class="fas fa-list-alt mr-1"></i> <?= __('tmiPublish.page.ntmlEntryType') ?>
                            </span>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-3 col-6 mb-2">
                                    <div class="card ntml-type-card text-center p-2 selected" data-type="MIT">
                                        <div class="ntml-type-icon"><i class="fas fa-ruler-horizontal"></i></div>
                                        <div class="small font-weight-bold"><?= __('tmiPublish.page.mit') ?></div>
                                        <div class="text-muted" style="font-size: 0.65rem;"><?= __('tmiPublish.page.mitDesc') ?></div>
                                    </div>
                                </div>
                                <div class="col-md-3 col-6 mb-2">
                                    <div class="card ntml-type-card text-center p-2" data-type="MINIT">
                                        <div class="ntml-type-icon"><i class="fas fa-stopwatch"></i></div>
                                        <div class="small font-weight-bold"><?= __('tmiPublish.page.minit') ?></div>
                                        <div class="text-muted" style="font-size: 0.65rem;"><?= __('tmiPublish.page.minitDesc') ?></div>
                                    </div>
                                </div>
                                <div class="col-md-3 col-6 mb-2">
                                    <div class="card ntml-type-card text-center p-2" data-type="DELAY">
                                        <div class="ntml-type-icon"><i class="fas fa-clock"></i></div>
                                        <div class="small font-weight-bold"><?= __('tmiPublish.page.delay') ?></div>
                                        <div class="text-muted" style="font-size: 0.65rem;"><?= __('tmiPublish.page.delayDesc') ?></div>
                                    </div>
                                </div>
                                <div class="col-md-3 col-6 mb-2">
                                    <div class="card ntml-type-card text-center p-2" data-type="CONFIG">
                                        <div class="ntml-type-icon"><i class="fas fa-plane-arrival"></i></div>
                                        <div class="small font-weight-bold"><?= __('tmiPublish.page.config') ?></div>
                                        <div class="text-muted" style="font-size: 0.65rem;"><?= __('tmiPublish.page.configDesc') ?></div>
                                    </div>
                                </div>
                                <div class="col-md-3 col-6 mb-2">
                                    <div class="card ntml-type-card text-center p-2" data-type="STOP">
                                        <div class="ntml-type-icon"><i class="fas fa-hand-paper"></i></div>
                                        <div class="small font-weight-bold"><?= __('tmiPublish.page.stop') ?></div>
                                        <div class="text-muted" style="font-size: 0.65rem;"><?= __('tmiPublish.page.stopDesc') ?></div>
                                    </div>
                                </div>
                                <div class="col-md-3 col-6 mb-2">
                                    <div class="card ntml-type-card text-center p-2" data-type="APREQ">
                                        <div class="ntml-type-icon"><i class="fas fa-phone-alt"></i></div>
                                        <div class="small font-weight-bold"><?= __('tmiPublish.page.apreqCfr') ?></div>
                                        <div class="text-muted" style="font-size: 0.65rem;"><?= __('tmiPublish.page.apreqCfrDesc') ?></div>
                                    </div>
                                </div>
                                <div class="col-md-3 col-6 mb-2">
                                    <div class="card ntml-type-card text-center p-2" data-type="TBM">
                                        <div class="ntml-type-icon"><i class="fas fa-tachometer-alt"></i></div>
                                        <div class="small font-weight-bold"><?= __('tmiPublish.page.tbm') ?></div>
                                        <div class="text-muted" style="font-size: 0.65rem;"><?= __('tmiPublish.page.tbmDesc') ?></div>
                                    </div>
                                </div>
                                <div class="col-md-3 col-6 mb-2">
                                    <div class="card ntml-type-card text-center p-2" data-type="CANCEL">
                                        <div class="ntml-type-icon"><i class="fas fa-times-circle"></i></div>
                                        <div class="small font-weight-bold"><?= __('tmiPublish.page.cancelType') ?></div>
                                        <div class="text-muted" style="font-size: 0.65rem;"><?= __('tmiPublish.page.cancelTypeDesc') ?></div>
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
                            <pre id="ntml_preview" class="ntml-preview"><?= __('tmiPublish.page.enterDetailsPreview') ?></pre>
                        </div>
                    </div>
                    
                    <!-- Discord Targets -->
                    <div class="card shadow-sm">
                        <div class="card-header">
                            <span class="tmi-section-title">
                                <i class="fab fa-discord mr-1"></i> <?= __('tmiPublish.page.postToOrgs') ?>
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
                            <div class="row" id="advisoryTypeCards">
                                <!-- Dynamically populated by tmi-publish.js based on org -->
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
                                        <label class="tmi-label mb-0"><?= __('tmiPublish.page.advisoryNumber') ?></label>
                                        <input type="text" class="form-control" id="adv_number" placeholder="001">
                                    </div>
                                    <div class="form-group col-md-4">
                                        <label class="tmi-label mb-0"><?= __('tmiPublish.page.facility') ?></label>
                                        <input type="text" class="form-control" id="adv_facility" value="DCC">
                                    </div>
                                    <div class="form-group col-md-4">
                                        <label class="tmi-label mb-0">CTL Element</label>
                                        <input type="text" class="form-control" id="adv_ctl_element" placeholder="KATL">
                                    </div>
                                </div>
                                <div class="form-row">
                                    <div class="form-group col-md-6">
                                        <label class="tmi-label mb-0"><?= __('tmiPublish.page.validFromUtc') ?></label>
                                        <input type="datetime-local" class="form-control" id="adv_start" value="<?= $defaultStartDatetime ?>">
                                    </div>
                                    <div class="form-group col-md-6">
                                        <label class="tmi-label mb-0"><?= __('tmiPublish.page.validUntilUtc') ?></label>
                                        <input type="datetime-local" class="form-control" id="adv_end" value="<?= $defaultEndDatetime ?>">
                                    </div>
                                </div>
                                <div class="form-group">
                                    <label class="tmi-label mb-0"><?= __('tmiPublish.page.planDetails') ?></label>
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
                                <i class="fas fa-eye mr-1"></i> <?= __('tmiPublish.page.advisoryPreview') ?>
                            </span>
                            <span class="char-count" id="preview_char_count">0 / 2000</span>
                        </div>
                        <div class="card-body">
                            <pre id="adv_preview" class="tmi-advisory-preview"><?= __('tmiPublish.page.selectAdvisoryType') ?></pre>
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
                                <i class="fab fa-discord mr-1"></i> <?= __('tmiPublish.page.postToOrgs') ?>
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

        <!-- Reroute Panel -->
        <div class="tab-pane fade" id="reroutePanel" role="tabpanel">
            <div class="row">
                <div class="col-lg-7">
                    <!-- Reroute Source Info -->
                    <div class="alert alert-info mb-3" id="rerouteSourceInfo" style="display: none;">
                        <i class="fas fa-info-circle mr-1"></i>
                        <strong><?= __('tmiPublish.page.routesLoadedFromPlotter') ?></strong>
                        <span id="rerouteRouteCount"></span>
                    </div>

                    <!-- Reroute Basic Info -->
                    <div class="card shadow-sm mb-3">
                        <div class="card-header bg-warning text-dark">
                            <span class="tmi-section-title">
                                <i class="fas fa-route mr-1"></i> Reroute Advisory Details
                            </span>
                        </div>
                        <div class="card-body">
                            <div class="form-row">
                                <div class="form-group col-md-2">
                                    <label class="tmi-label mb-0"><?= __('tmiPublish.page.advisoryNumber') ?></label>
                                    <input type="text" class="form-control" id="rr_adv_number"
                                           placeholder="Auto" readonly style="background: #f8f9fa;">
                                </div>
                                <div class="form-group col-md-2">
                                    <label class="tmi-label mb-0"><?= __('tmiPublish.page.facility') ?></label>
                                    <input type="text" class="form-control" id="rr_facility" value="DCC">
                                </div>
                                <div class="form-group col-md-4">
                                    <label class="tmi-label mb-0"><?= __('tmiPublish.page.routeName') ?></label>
                                    <input type="text" class="form-control" id="rr_name"
                                           placeholder="e.g., FEA:N90_TO_MIA_ARS">
                                </div>
                                <div class="form-group col-md-2">
                                    <label class="tmi-label mb-0"><?= __('tmiPublish.page.type') ?></label>
                                    <select class="form-control" id="rr_route_type">
                                        <option value="ROUTE" selected>ROUTE</option>
                                        <option value="FEA">FEA</option>
                                        <option value="FCA">FCA</option>
                                        <option value="ICR">ICR</option>
                                        <option value="CTOP">CTOP</option>
                                        <option value="TOS">TOS</option>
                                    </select>
                                </div>
                                <div class="form-group col-md-2">
                                    <label class="tmi-label mb-0"><?= __('tmiPublish.page.compliance') ?></label>
                                    <select class="form-control" id="rr_compliance">
                                        <option value="RQD" selected>RQD</option>
                                        <option value="RMD">RMD</option>
                                        <option value="PLN">PLN</option>
                                        <option value="FYI">FYI</option>
                                    </select>
                                </div>
                            </div>

                            <div class="form-row">
                                <div class="form-group col-md-4">
                                    <label class="tmi-label mb-0"><?= __('tmiPublish.page.constrainedArea') ?></label>
                                    <input type="text" class="form-control" id="rr_constrained_area"
                                           placeholder="e.g., ZNY">
                                </div>
                                <div class="form-group col-md-4">
                                    <label class="tmi-label mb-0"><?= __('tmiPublish.page.reason') ?></label>
                                    <select class="form-control" id="rr_reason">
                                        <option value="WEATHER">WEATHER</option>
                                        <option value="VOLUME">VOLUME</option>
                                        <option value="EQUIPMENT">EQUIPMENT</option>
                                        <option value="RUNWAY">RUNWAY</option>
                                        <option value="OTHER">OTHER</option>
                                    </select>
                                </div>
                                <div class="form-group col-md-4">
                                    <label class="tmi-label mb-0"><?= __('tmiPublish.page.probExtension') ?></label>
                                    <select class="form-control" id="rr_prob_extension">
                                        <option value="NONE">NONE</option>
                                        <option value="LOW">LOW</option>
                                        <option value="MEDIUM" selected>MEDIUM</option>
                                        <option value="HIGH">HIGH</option>
                                    </select>
                                </div>
                            </div>

                            <div class="form-row">
                                <div class="form-group col-md-6">
                                    <label class="tmi-label mb-0"><?= __('tmiPublish.page.validFromUtc') ?></label>
                                    <input type="datetime-local" class="form-control" id="rr_valid_from"
                                           value="<?= $defaultStartDatetime ?>">
                                </div>
                                <div class="form-group col-md-6">
                                    <label class="tmi-label mb-0"><?= __('tmiPublish.page.validUntilUtc') ?></label>
                                    <input type="datetime-local" class="form-control" id="rr_valid_until"
                                           value="<?= $defaultEndDatetime ?>">
                                </div>
                            </div>

                            <div class="form-row">
                                <div class="form-group col-md-6">
                                    <label class="tmi-label mb-0"><?= __('tmiPublish.page.includeTraffic') ?></label>
                                    <input type="text" class="form-control" id="rr_include_traffic"
                                           placeholder="e.g., KJFK/KLGA DEPARTURES TO KCLT">
                                </div>
                                <div class="form-group col-md-3">
                                    <label class="tmi-label mb-0"><?= __('tmiPublish.page.timeBasis') ?></label>
                                    <select class="form-control" id="rr_time_basis">
                                        <option value="ETD" selected>ETD</option>
                                        <option value="ETA">ETA</option>
                                    </select>
                                </div>
                                <div class="form-group col-md-3">
                                    <label class="tmi-label mb-0"><?= __('tmiPublish.page.airborneFilter') ?></label>
                                    <select class="form-control" id="rr_airborne_filter">
                                        <option value="NOT_AIRBORNE" selected><?= __('tmiPublish.page.notAirborneOnly') ?></option>
                                        <option value="ALL"><?= __('tmiPublish.page.allFlights') ?></option>
                                        <option value="AIRBORNE_ONLY"><?= __('tmiPublish.page.airborneOnly') ?></option>
                                    </select>
                                </div>
                            </div>

                            <div class="form-row">
                                <div class="form-group col-md-9">
                                    <label class="tmi-label mb-0"><?= __('tmiPublish.page.remarks') ?></label>
                                    <textarea class="form-control" id="rr_remarks" rows="2"
                                              placeholder="<?= __('tmiPublish.page.optionalRemarks') ?>"></textarea>
                                </div>
                                <div class="form-group col-md-3">
                                    <label class="tmi-label mb-0"><?= __('tmiPublish.page.routeColor') ?></label>
                                    <input type="color" class="form-control" id="rr_color" value="#e74c3c"
                                           style="height: 62px; padding: 2px; cursor: pointer;">
                                </div>
                            </div>

                            <div class="form-row">
                                <div class="form-group col-md-6">
                                    <label class="tmi-label mb-0"><?= __('tmiPublish.page.associatedRestrictions') ?></label>
                                    <input type="text" class="form-control" id="rr_restrictions"
                                           placeholder="e.g., FL310 AND ABOVE">
                                </div>
                                <div class="form-group col-md-6">
                                    <label class="tmi-label mb-0"><?= __('tmiPublish.page.modifications') ?></label>
                                    <input type="text" class="form-control" id="rr_modifications"
                                           placeholder="e.g., AMDT, CNCL, CORRN">
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Routes Table -->
                    <div class="card shadow-sm mb-3">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <span class="tmi-section-title">
                                <i class="fas fa-list mr-1"></i> Routes
                            </span>
                            <div>
                                <button class="btn btn-sm btn-outline-info mr-1" id="rr_make_mandatory"
                                        title="Add mandatory markers (><) to selected routes">
                                    <i class="fas fa-lock mr-1"></i> Make Mandatory
                                </button>
                                <button class="btn btn-sm btn-outline-primary mr-1" id="rr_group_routes"
                                        title="Group routes with same route string">
                                    <i class="fas fa-object-group mr-1"></i> Group
                                </button>
                                <button class="btn btn-sm btn-outline-warning mr-1" id="rr_auto_filters"
                                        title="Auto-detect filters for overlapping ARTCC/airport routes">
                                    <i class="fas fa-filter mr-1"></i> Auto Filters
                                </button>
                                <button class="btn btn-sm btn-outline-secondary" id="rr_add_route">
                                    <i class="fas fa-plus"></i> Add Route
                                </button>
                            </div>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-sm table-hover mb-0" id="rr_routes_table">
                                    <thead class="thead-light">
                                        <tr>
                                            <th style="width: 24px; padding: 0.25rem;">
                                                <input type="checkbox" id="rr_select_all_routes" title="Select All">
                                            </th>
                                            <th style="width: 60px; font-size: 0.7rem;">ORIG</th>
                                            <th style="width: 70px; font-size: 0.65rem;" title="Origin exclusions (e.g., -KJFK)">FILTER</th>
                                            <th style="width: 60px; font-size: 0.7rem;">DEST</th>
                                            <th style="width: 70px; font-size: 0.65rem;" title="Destination exclusions (e.g., -KATL)">FILTER</th>
                                            <th style="font-size: 0.7rem;">ROUTE</th>
                                            <th style="width: 32px;"></th>
                                        </tr>
                                    </thead>
                                    <tbody id="rr_routes_body">
                                        <tr class="rr-empty-row">
                                            <td colspan="7" class="text-center text-muted py-3">
                                                No routes loaded. Use Route Plotter to create routes, or add manually.
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <!-- Facilities for Coordination -->
                    <div class="card shadow-sm mb-3">
                        <div class="card-header">
                            <span class="tmi-section-title">
                                <i class="fas fa-building mr-1"></i> Facilities for Coordination
                            </span>
                        </div>
                        <div class="card-body">
                            <div id="rr_facilities_grid" class="d-flex flex-wrap">
                                <?php
                                foreach (PERTI_ARTCC_CONUS as $artcc):
                                ?>
                                <div class="custom-control custom-checkbox mr-3 mb-2">
                                    <input type="checkbox" class="custom-control-input rr-facility-cb"
                                           id="rr_fac_<?= $artcc ?>" value="<?= $artcc ?>">
                                    <label class="custom-control-label" for="rr_fac_<?= $artcc ?>">
                                        <?= $artcc ?>
                                    </label>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <!-- International Organizations -->
                            <hr class="my-2">
                            <div class="d-flex flex-wrap" id="rr_intl_facilities_grid">
                                <?php
                                foreach (PERTI_INTL_ORGS as $code => $name):
                                ?>
                                <div class="custom-control custom-checkbox mr-3 mb-2">
                                    <input type="checkbox" class="custom-control-input rr-facility-cb rr-intl-org"
                                           id="rr_fac_<?= $code ?>" value="<?= $code ?>">
                                    <label class="custom-control-label" for="rr_fac_<?= $code ?>"
                                           title="<?= $name ?>">
                                        <?= $code ?>
                                    </label>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <small class="text-muted mt-2 d-block">
                                Select facilities that need to approve this reroute advisory.
                                International orgs auto-checked based on route coverage.
                            </small>
                        </div>
                    </div>

                    <!-- Route Format Options -->
                    <div class="d-flex align-items-center mb-3 border rounded p-2 bg-light">
                        <span class="small font-weight-bold mr-3"><?= __('tmiPublish.page.format') ?>:</span>
                        <div class="custom-control custom-radio mr-3">
                            <input type="radio" class="custom-control-input" id="rr_format_full" name="rr_format" value="full" checked>
                            <label class="custom-control-label small" for="rr_format_full"><?= __('tmiPublish.page.fullFormat') ?></label>
                        </div>
                        <div class="custom-control custom-radio mr-3">
                            <input type="radio" class="custom-control-input" id="rr_format_split" name="rr_format" value="split">
                            <label class="custom-control-label small" for="rr_format_split"><?= __('tmiPublish.page.splitFormat') ?></label>
                        </div>
                        <button class="btn btn-sm btn-outline-info ml-auto" id="rr_detect_common" type="button"
                                title="Detect common route segments and split into ORIGIN/DESTINATION sections">
                            <i class="fas fa-code-branch mr-1"></i> Detect Common
                        </button>
                    </div>

                    <!-- Action Buttons -->
                    <div class="d-flex justify-content-between mb-3">
                        <div>
                            <button class="btn btn-outline-secondary mr-2" id="rr_reset" type="button">
                                <i class="fas fa-undo mr-1"></i> Reset
                            </button>
                            <button class="btn btn-outline-primary" id="rr_save_draft" type="button">
                                <i class="fas fa-save mr-1"></i> Save Draft
                            </button>
                        </div>
                        <div>
                            <button class="btn btn-secondary mr-2" id="rr_preview" type="button">
                                <i class="fas fa-eye mr-1"></i> Preview
                            </button>
                            <button class="btn btn-warning" id="rr_submit_coordination" type="button">
                                <i class="fas fa-handshake mr-1"></i> Submit for Coordination
                            </button>
                        </div>
                    </div>
                </div>

                <div class="col-lg-5">
                    <!-- Sticky wrapper for right panel -->
                    <div style="position: sticky; top: 1rem;">
                        <!-- Advisory Preview -->
                        <div class="card shadow-sm mb-3">
                            <div class="card-header d-flex justify-content-between align-items-center">
                                <span class="tmi-section-title">
                                    <i class="fas fa-eye mr-1"></i> <?= __('tmiPublish.page.advisoryPreview') ?>
                                </span>
                                <button class="btn btn-sm btn-outline-secondary" id="rr_copy_preview" title="Copy to clipboard">
                                    <i class="far fa-copy"></i>
                                </button>
                            </div>
                            <div class="card-body p-2">
                                <pre id="rr_preview_text" class="ntml-preview"
                                     style="max-height: calc(100vh - 380px); overflow-y: auto; font-size: 0.7rem; white-space: pre; overflow-x: auto; min-width: 520px;"><?= __('tmiPublish.page.generatePreview') ?></pre>
                            </div>
                        </div>

                        <!-- Saved Drafts -->
                        <div class="card shadow-sm mb-3">
                            <div class="card-header d-flex justify-content-between align-items-center py-2">
                                <span class="tmi-section-title">
                                    <i class="fas fa-folder-open mr-1"></i> Saved Drafts
                                </span>
                                <button class="btn btn-sm btn-outline-secondary" id="rr_refresh_drafts">
                                    <i class="fas fa-sync-alt"></i>
                                </button>
                            </div>
                            <div class="card-body p-0">
                                <div id="rr_drafts_list" class="list-group list-group-flush" style="max-height: 120px; overflow-y: auto;">
                                    <div class="list-group-item text-center text-muted small">
                                        <i class="fas fa-spinner fa-spin mr-1"></i> Loading drafts...
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Discord Targets -->
                        <div class="card shadow-sm">
                            <div class="card-header py-2">
                                <span class="tmi-section-title">
                                    <i class="fab fa-discord mr-1"></i> <?= __('tmiPublish.page.postToOrgs') ?>
                                </span>
                            </div>
                            <div class="card-body py-2">
                                <?php foreach ($discordOrgs as $code => $org): ?>
                                <div class="custom-control custom-checkbox mb-1">
                                    <input type="checkbox" class="custom-control-input discord-org-checkbox-rr"
                                           id="rr_org_<?= $code ?>" value="<?= $code ?>"
                                           <?= $org['default'] ? 'checked' : '' ?>>
                                    <label class="custom-control-label small" for="rr_org_<?= $code ?>">
                                        <?= htmlspecialchars($org['name']) ?>
                                    </label>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- GS/GDP Panel -->
        <div class="tab-pane fade" id="gsgdpPanel" role="tabpanel">

            <!-- Source Info Banner -->
            <div class="alert alert-info mb-3" id="gsgdpSourceInfo" style="display: none;">
                <i class="fas fa-info-circle mr-1"></i>
                <strong><?= __('tmiPublish.page.programDataFromGdt') ?></strong>
                <span id="gsgdpSourceDetails"></span>
            </div>

            <!-- No Handoff Warning -->
            <div class="alert alert-warning mb-3" id="gsgdpNoHandoff">
                <i class="fas fa-exclamation-triangle mr-1"></i>
                <strong><?= __('tmiPublish.page.noProgramData') ?></strong> - <?= __('tmiPublish.page.noProgramDataText') ?>
                <div class="mt-2">
                    <a href="gdt.php" class="btn btn-sm btn-primary"><i class="fas fa-arrow-left mr-1"></i> <?= __('tmiPublish.page.goToGdt') ?></a>
                </div>
            </div>

            <div class="row" id="gsgdpMainContent" style="display: none;">
                <div class="col-lg-7">

                    <!-- Program Summary Card -->
                    <div class="card shadow-sm mb-3">
                        <div class="card-header d-flex justify-content-between align-items-center" id="gsgdpProgramHeader">
                            <span class="tmi-section-title">
                                <i class="fas fa-plane-departure mr-1"></i>
                                <span id="gsgdpProgramTitle"><?= __('tmiPublish.page.programDetails') ?></span>
                            </span>
                            <span class="badge badge-lg" id="gsgdpTypeBadge">--</span>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-4 mb-2">
                                    <label class="tmi-label mb-0"><?= __('tmiPublish.page.ctlElement') ?></label>
                                    <div class="h5 mb-0" id="gsgdpCtlElement">--</div>
                                </div>
                                <div class="col-md-4 mb-2">
                                    <label class="tmi-label mb-0"><?= __('tmiPublish.page.startTimeUtc') ?></label>
                                    <div class="h5 mb-0" id="gsgdpStartTime">--</div>
                                </div>
                                <div class="col-md-4 mb-2">
                                    <label class="tmi-label mb-0"><?= __('tmiPublish.page.endTimeUtc') ?></label>
                                    <div class="h5 mb-0" id="gsgdpEndTime">--</div>
                                </div>
                            </div>

                            <!-- GDP-specific fields -->
                            <div class="row mt-2" id="gsgdpGdpFields" style="display: none;">
                                <div class="col-md-4 mb-2">
                                    <label class="tmi-label mb-0"><?= __('tmiPublish.page.programRate') ?></label>
                                    <div class="h5 mb-0" id="gsgdpProgramRate">--</div>
                                </div>
                                <div class="col-md-4 mb-2">
                                    <label class="tmi-label mb-0"><?= __('tmiPublish.page.avgDelay') ?></label>
                                    <div class="h5 mb-0" id="gsgdpAvgDelay">--</div>
                                </div>
                                <div class="col-md-4 mb-2">
                                    <label class="tmi-label mb-0"><?= __('tmiPublish.page.maxDelay') ?></label>
                                    <div class="h5 mb-0" id="gsgdpMaxDelay">--</div>
                                </div>
                            </div>

                            <!-- GS-specific fields -->
                            <div class="row mt-2" id="gsgdpGsFields" style="display: none;">
                                <div class="col-md-4 mb-2">
                                    <label class="tmi-label mb-0"><?= __('tmiPublish.page.scope') ?></label>
                                    <div class="h5 mb-0" id="gsgdpScope">--</div>
                                </div>
                                <div class="col-md-4 mb-2">
                                    <label class="tmi-label mb-0"><?= __('tmiPublish.page.affectedFlights') ?></label>
                                    <div class="h5 mb-0" id="gsgdpAffectedFlights">--</div>
                                </div>
                                <div class="col-md-4 mb-2">
                                    <label class="tmi-label mb-0"><?= __('tmiPublish.page.duration') ?></label>
                                    <div class="h5 mb-0" id="gsgdpDuration">--</div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Flight List Summary -->
                    <div class="card shadow-sm mb-3">
                        <div class="card-header d-flex justify-content-between align-items-center py-2">
                            <span class="tmi-section-title">
                                <i class="fas fa-list mr-1"></i> <?= __('tmiPublish.page.flightList') ?>
                                <span class="badge badge-secondary ml-2" id="gsgdpFlightCount">0</span>
                                <span class="badge badge-info ml-1" id="gsgdpFlightStats" style="display: none;"></span>
                            </span>
                            <div>
                                <button class="btn btn-sm btn-outline-primary mr-1" id="gsgdpRefreshFlights" title="Refresh flight list from ADL">
                                    <i class="fas fa-sync-alt"></i>
                                </button>
                                <button class="btn btn-sm btn-outline-secondary" id="gsgdpToggleFlights">
                                    <i class="fas fa-chevron-down"></i> <?= __('tmiPublish.show') ?>
                                </button>
                            </div>
                        </div>
                        <div class="card-body p-0" id="gsgdpFlightListContainer" style="display: none;">
                            <div class="table-responsive" style="max-height: 300px; overflow-y: auto;">
                                <table class="table table-sm table-hover mb-0">
                                    <thead class="thead-light">
                                        <tr>
                                            <th style="font-size: 0.7rem;">CALLSIGN</th>
                                            <th style="font-size: 0.7rem;">ORIG</th>
                                            <th style="font-size: 0.7rem;">DEST</th>
                                            <th style="font-size: 0.7rem;">TYPE</th>
                                            <th style="font-size: 0.7rem;">ETD</th>
                                            <th style="font-size: 0.7rem;">EDCT</th>
                                            <th style="font-size: 0.7rem;">DELAY</th>
                                        </tr>
                                    </thead>
                                    <tbody id="gsgdpFlightListBody">
                                        <tr>
                                            <td colspan="7" class="text-center text-muted py-3"><?= __('tmiPublish.page.noFlights') ?></td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <!-- Coordination Section -->
                    <div class="card shadow-sm mb-3">
                        <div class="card-header bg-warning">
                            <span class="tmi-section-title">
                                <i class="fas fa-handshake mr-1"></i> <?= __('tmiPublish.page.coordination') ?>
                            </span>
                        </div>
                        <div class="card-body">
                            <div class="mb-3">
                                <label class="tmi-label mb-1"><?= __('tmiPublish.page.facilitiesCoord') ?></label>
                                <div class="small text-muted mb-2"><?= __('tmiPublish.page.selectFacilitiesNotify') ?></div>
                                <div id="gsgdpFacilitiesGrid" class="d-flex flex-wrap">
                                    <?php
                                    foreach (PERTI_ARTCC_CONUS as $artcc):
                                    ?>
                                    <div class="custom-control custom-checkbox mr-3 mb-2">
                                        <input type="checkbox" class="custom-control-input gsgdp-facility-cb"
                                               id="gsgdp_fac_<?= $artcc ?>" value="<?= $artcc ?>">
                                        <label class="custom-control-label" for="gsgdp_fac_<?= $artcc ?>">
                                            <?= $artcc ?>
                                        </label>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>

                            <div class="form-row">
                                <div class="form-group col-md-6 mb-2">
                                    <label class="tmi-label mb-0"><?= __('tmiPublish.page.coordDeadline') ?></label>
                                    <select class="form-control form-control-sm" id="gsgdpCoordDeadline">
                                        <option value="15">15 minutes</option>
                                        <option value="30" selected>30 minutes</option>
                                        <option value="60">60 minutes</option>
                                    </select>
                                </div>
                                <div class="form-group col-md-6 mb-2">
                                    <label class="tmi-label mb-0"><?= __('tmiPublish.page.reason') ?></label>
                                    <select class="form-control form-control-sm" id="gsgdpReason">
                                        <option value="WEATHER">WEATHER</option>
                                        <option value="VOLUME">VOLUME</option>
                                        <option value="EQUIPMENT">EQUIPMENT</option>
                                        <option value="RUNWAY">RUNWAY</option>
                                        <option value="OTHER">OTHER</option>
                                    </select>
                                </div>
                            </div>

                            <div class="form-group mb-0">
                                <label class="tmi-label mb-0"><?= __('tmiPublish.page.remarks') ?></label>
                                <textarea class="form-control form-control-sm" id="gsgdpRemarks" rows="2" placeholder="<?= __('tmiPublish.page.optionalRemarks') ?>"></textarea>
                            </div>
                        </div>
                    </div>

                    <!-- Action Buttons -->
                    <div class="d-flex justify-content-between mb-3">
                        <div>
                            <button class="btn btn-outline-secondary mr-2" id="gsgdpBackToGdt">
                                <i class="fas fa-arrow-left mr-1"></i> <?= __('tmiPublish.page.backToGdt') ?>
                            </button>
                            <button class="btn btn-outline-danger" id="gsgdpDiscard">
                                <i class="fas fa-times mr-1"></i> <?= __('tmiPublish.page.discard') ?>
                            </button>
                        </div>
                        <div>
                            <button class="btn btn-warning mr-2" id="gsgdpSubmitCoord">
                                <i class="fas fa-handshake mr-1"></i> <?= __('tmiPublish.page.submitForCoordination') ?>
                            </button>
                            <button class="btn btn-success" id="gsgdpPublishDirect" title="<?= __('tmiPublish.page.dccOverrideTooltip') ?>">
                                <i class="fas fa-broadcast-tower mr-1"></i> <?= __('tmiPublish.page.publishDirect') ?>
                            </button>
                        </div>
                    </div>
                </div>

                <div class="col-lg-5">
                    <!-- Sticky wrapper -->
                    <div style="position: sticky; top: 1rem;">

                        <!-- Advisory Preview -->
                        <div class="card shadow-sm mb-3">
                            <div class="card-header d-flex justify-content-between align-items-center">
                                <span class="tmi-section-title">
                                    <i class="fas fa-eye mr-1"></i> <?= __('tmiPublish.page.advisoryPreview') ?>
                                </span>
                                <button class="btn btn-sm btn-outline-secondary" id="gsgdpCopyPreview" title="Copy to clipboard">
                                    <i class="far fa-copy"></i>
                                </button>
                            </div>
                            <div class="card-body p-2">
                                <pre id="gsgdpAdvisoryPreview" class="ntml-preview" style="max-height: 350px; overflow-y: auto; font-size: 0.75rem; white-space: pre-wrap;"><?= __('tmiPublish.page.noProgramDataLoaded') ?></pre>
                            </div>
                        </div>

                        <!-- Discord Targets -->
                        <div class="card shadow-sm">
                            <div class="card-header py-2">
                                <span class="tmi-section-title">
                                    <i class="fab fa-discord mr-1"></i> <?= __('tmiPublish.page.postToOrgs') ?>
                                </span>
                            </div>
                            <div class="card-body py-2">
                                <?php foreach ($discordOrgs as $code => $org): ?>
                                <div class="custom-control custom-checkbox mb-1">
                                    <input type="checkbox" class="custom-control-input discord-org-checkbox-gsgdp"
                                           id="gsgdp_org_<?= $code ?>" value="<?= $code ?>"
                                           <?= $org['default'] ? 'checked' : '' ?>>
                                    <label class="custom-control-label small" for="gsgdp_org_<?= $code ?>">
                                        <?= htmlspecialchars($org['name']) ?>
                                    </label>
                                </div>
                                <?php endforeach; ?>
                            </div>
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
                                <i class="fas fa-list mr-1"></i> <?= __('tmiPublish.page.entryQueue') ?>
                            </span>
                            <button class="btn btn-sm btn-outline-danger" id="clearQueue">
                                <i class="fas fa-trash mr-1"></i> <?= __('tmiPublish.page.clearAll') ?>
                            </button>
                        </div>
                        <div class="card-body p-0">
                            <div id="entryQueueList">
                                <div class="text-center text-muted py-4" id="emptyQueueMsg">
                                    <i class="fas fa-inbox fa-2x mb-2"></i><br>
                                    <?= __('tmiPublish.page.noEntriesQueued') ?>
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
                                <i class="fas fa-paper-plane mr-1"></i> <?= __('tmiPublish.page.publish') ?>
                            </span>
                        </div>
                        <div class="card-body">
                            <div class="mb-3 text-center">
                                <div class="h3 mb-0">
                                    <span id="submitCount">0</span>
                                    <small class="text-muted"><?= __('tmiPublish.page.entriesReady') ?></small>
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <strong><?= __('tmiPublish.page.targetMode') ?></strong>
                                <span id="targetModeDisplay" class="badge badge-warning ml-2">STAGING</span>
                            </div>
                            
                            <div class="mb-3">
                                <strong><?= __('tmiPublish.page.organizations') ?></strong>
                                <div id="targetOrgsDisplay" class="small text-muted">vATCSCC</div>
                            </div>
                            
                            <button class="btn btn-success btn-lg btn-block" id="submitAllBtn" disabled>
                                <i class="fas fa-paper-plane mr-1"></i>
                                <span id="submitBtnText"><?= __('tmiPublish.page.submitToStaging') ?></span>
                            </button>
                            
                            <div class="mt-2 small text-muted text-center" id="submitHint">
                                <?= __('tmiPublish.page.postsToStaging') ?>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Staged Entries -->
                    <div class="card shadow-sm">
                        <div class="card-header">
                            <span class="tmi-section-title">
                                <i class="fas fa-clock mr-1"></i> <?= __('tmiPublish.page.staged') ?>
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
                        <h5 class="mb-0"><i class="fas fa-broadcast-tower mr-2"></i><?= __('tmiPublish.page.currentRestrictions') ?></h5>
                    </div>
                    <div class="col-md-6 text-md-right">
                        <div class="refresh-timer d-inline-block mr-3">
                            <i class="fas fa-sync-alt mr-1"></i>
                            <span><?= __('tmiPublish.page.refreshTimer') ?> <span id="lastRefreshTime">--:--:-- UTC</span></span>
                            <span class="ml-2 countdown" id="refreshCountdown">60s</span>
                        </div>
                        <button class="btn btn-sm btn-light" id="refreshActiveTmis">
                            <i class="fas fa-sync-alt"></i> <?= __('tmiPublish.page.refreshNow') ?>
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
                            <div class="tmi-status-label"><?= __('tmiPublish.page.active') ?></div>
                        </div>
                    </div>
                </div>
                <div class="col-md-3 col-6 mb-2">
                    <div class="card bg-info text-white h-100">
                        <div class="card-body py-2 text-center">
                            <div class="tmi-status-count" id="scheduledCount">0</div>
                            <div class="tmi-status-label"><?= __('tmiPublish.page.scheduled') ?></div>
                        </div>
                    </div>
                </div>
                <div class="col-md-3 col-6 mb-2">
                    <div class="card bg-danger text-white h-100">
                        <div class="card-body py-2 text-center">
                            <div class="tmi-status-count" id="cancelledCount">0</div>
                            <div class="tmi-status-label"><?= __('tmiPublish.page.cancelledRecent') ?></div>
                        </div>
                    </div>
                </div>
                <div class="col-md-3 col-6 mb-2">
                    <div class="card bg-primary text-white h-100">
                        <div class="card-body py-2 text-center">
                            <div class="tmi-status-count" id="advisoryCount">0</div>
                            <div class="tmi-status-label"><?= __('tmiPublish.page.advisories') ?></div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Filter Controls -->
            <div id="activeTmiFilters" class="mb-3">
                <div class="row align-items-end">
                    <div class="col-md-2 col-6 mb-2">
                        <label class="small text-muted mb-0"><?= __('tmiPublish.page.source') ?></label>
                        <select class="form-control form-control-sm" id="filterSource">
                            <option value="ALL"><?= __('tmiPublish.page.allSources') ?></option>
                            <option value="PRODUCTION" selected>Production</option>
                            <option value="STAGING">Staging</option>
                        </select>
                    </div>
                    <div class="col-md-2 col-6 mb-2">
                        <label class="small text-muted mb-0"><?= __('tmiPublish.page.requesting') ?></label>
                        <select class="form-control form-control-sm facility-filter-select" id="filterReqFac" multiple="multiple" data-placeholder="All Facilities">
                        </select>
                    </div>
                    <div class="col-md-2 col-6 mb-2">
                        <label class="small text-muted mb-0"><?= __('tmiPublish.page.providing') ?></label>
                        <select class="form-control form-control-sm facility-filter-select" id="filterProvFac" multiple="multiple" data-placeholder="All Facilities">
                        </select>
                    </div>
                    <div class="col-md-2 col-6 mb-2">
                        <label class="small text-muted mb-0"><?= __('tmiPublish.page.type') ?></label>
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
                        <label class="small text-muted mb-0"><?= __('tmiPublish.page.status') ?></label>
                        <select class="form-control form-control-sm" id="filterStatus" multiple="multiple" data-placeholder="All Status">
                            <option value="ACTIVE" selected>Active</option>
                            <option value="SCHEDULED" selected>Scheduled</option>
                            <option value="CANCELLED">Cancelled</option>
                            <option value="EXPIRED">Expired</option>
                        </select>
                    </div>
                    <div class="col-md-2 col-6 mb-2">
                        <label class="small text-muted mb-0"><?= __('tmiPublish.page.date') ?></label>
                        <input type="date" class="form-control form-control-sm" id="filterDate" placeholder="Today">
                    </div>
                    <div class="col-md-1 col-6 mb-2">
                        <label class="small text-muted mb-0">&nbsp;</label>
                        <button class="btn btn-sm btn-primary btn-block" id="applyFilters">
                            <i class="fas fa-filter"></i>
                        </button>
                    </div>
                    <div class="col-md-1 col-6 mb-2">
                        <label class="small text-muted mb-0">&nbsp;</label>
                        <button class="btn btn-sm btn-outline-secondary btn-block" id="resetFilters">
                            <i class="fas fa-undo"></i>
                        </button>
                    </div>
                </div>
            </div>
            
            <!-- Restrictions Table (FAA-style) -->
            <div class="card shadow-sm mb-3">
                <div class="card-header py-2 d-flex justify-content-between align-items-center" style="background-color: #1a5276;">
                    <span class="text-white font-weight-bold">
                        <i class="fas fa-ruler-horizontal mr-1"></i> <?= __('tmiPublish.page.currentRestrictionsHeader') ?>
                        <span class="badge badge-light ml-2" id="restrictionCount">0</span>
                    </span>
                    <div id="batchCancelControls" style="display: none;">
                        <span class="text-white small mr-2"><span id="selectedCount">0</span> <?= __('tmiPublish.page.selected') ?></span>
                        <button class="btn btn-sm btn-danger" id="batchCancelBtn" title="<?= __('tmiPublish.page.cancelSelected') ?>">
                            <i class="fas fa-times-circle"></i> <?= __('tmiPublish.page.cancelSelected') ?>
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
                                        <?= __('tmiPublish.page.loadingRestrictions') ?>
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
                        <i class="fas fa-bullhorn mr-1"></i> <?= __('tmiPublish.page.activeAdvisories') ?>
                        <span class="badge badge-light ml-2" id="advisoryCountHeader">0</span>
                    </span>
                </div>
                <div class="card-body p-2" id="advisoriesContainer">
                    <div class="text-center text-muted py-4">
                        <i class="fas fa-spinner fa-spin fa-2x mb-2"></i><br>
                        <?= __('tmiPublish.page.loadingAdvisories') ?>
                    </div>
                </div>
            </div>

        </div>

        <!-- Coordination Panel -->
        <div class="tab-pane fade" id="coordinationPanel" role="tabpanel">
            <!-- Coordination Info -->
            <div class="alert alert-info mb-3">
                <i class="fas fa-info-circle mr-1"></i>
                <strong><?= __('tmiPublish.page.coordination') ?></strong> - <?= __('tmiPublish.page.coordinationInfo') ?>
                <?= __('tmiPublish.page.coordinationDiscord') ?>
            </div>

            <!-- Pending Proposals -->
            <div class="card shadow-sm mb-3">
                <div class="card-header py-2 bg-warning">
                    <span class="font-weight-bold">
                        <i class="fas fa-clock mr-1"></i> <?= __('tmiPublish.page.pendingProposals') ?>
                        <span class="badge badge-dark ml-2" id="pendingCount">0</span>
                    </span>
                    <div class="float-right">
                        <button class="btn btn-sm btn-success mr-2" id="batchPublishApproved" style="display: none;" title="Publish all approved proposals">
                            <i class="fas fa-broadcast-tower"></i> <?= __('tmiPublish.page.publishAllApproved') ?>
                        </button>
                        <button class="btn btn-sm btn-outline-dark" id="refreshProposals">
                            <i class="fas fa-sync-alt"></i> <?= __('common.refresh') ?>
                        </button>
                    </div>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-sm table-hover mb-0" id="proposalsTable">
                            <thead>
                                <tr>
                                    <th style="width: 50px;">ID</th>
                                    <th style="width: 70px;">TYPE</th>
                                    <th style="width: 80px;">ELEMENT</th>
                                    <th style="min-width: 150px;">RESTRICTION</th>
                                    <th style="width: 100px;">PROPOSED BY</th>
                                    <th style="width: 100px;">DEADLINE</th>
                                    <th style="min-width: 180px;">APPROVALS</th>
                                    <th style="width: 80px;">STATUS</th>
                                    <th style="width: 160px; min-width: 160px;">ACTIONS</th>
                                </tr>
                            </thead>
                            <tbody id="proposalsTableBody">
                                <tr>
                                    <td colspan="9" class="text-center text-muted py-4">
                                        <i class="fas fa-spinner fa-spin fa-2x mb-2"></i><br>
                                        <?= __('tmiPublish.page.loadingProposals') ?>
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
                        <i class="fas fa-history mr-1"></i> <?= __('tmiPublish.page.recentProposals') ?>
                        <span class="badge badge-light ml-2" id="recentCount">0</span>
                    </span>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-sm table-hover mb-0" id="recentProposalsTable">
                            <thead>
                                <tr>
                                    <th style="width: 50px;">ID</th>
                                    <th style="width: 70px;">TYPE</th>
                                    <th style="width: 80px;">ELEMENT</th>
                                    <th style="min-width: 150px;">RESTRICTION</th>
                                    <th style="width: 100px;">PROPOSED BY</th>
                                    <th style="width: 90px;">RESULT</th>
                                    <th style="width: 120px;">RESOLVED AT</th>
                                    <th style="width: 70px;">ACTIONS</th>
                                </tr>
                            </thead>
                            <tbody id="recentProposalsTableBody">
                                <tr>
                                    <td colspan="8" class="text-center text-muted py-3">
                                        <em><?= __('tmiPublish.page.noRecentProposals') ?></em>
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
        <h4><i class="fas fa-lock"></i> <?= __('tmiPublish.page.accessDenied') ?></h4>
        <p><?= __('tmiPublish.page.accessDeniedText') ?></p>
        <a href="login/" class="btn btn-primary"><?= __('tmiPublish.page.loginWithVatsim') ?></a>
    </div>
    
    <?php endif; ?>

</div>

<!-- Preview Modal -->
<div class="modal fade" id="previewModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content bg-dark text-white">
            <div class="modal-header border-secondary">
                <h5 class="modal-title"><i class="fas fa-eye"></i> <?= __('tmiPublish.page.messagePreview') ?></h5>
                <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
            </div>
            <div class="modal-body" id="previewModalContent" style="font-family: monospace; white-space: pre-wrap;"></div>
            <div class="modal-footer border-secondary">
                <button type="button" class="btn btn-secondary" data-dismiss="modal"><?= __('common.close') ?></button>
            </div>
        </div>
    </div>
</div>

<!-- Result Modal -->
<div class="modal fade" id="resultModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-check-circle text-success"></i> <?= __('tmiPublish.page.postResults') ?></h5>
                <button type="button" class="close" data-dismiss="modal">&times;</button>
            </div>
            <div class="modal-body" id="resultModalContent"></div>
            <div class="modal-footer">
                <button type="button" class="btn btn-primary" data-dismiss="modal"><?= __('common.ok') ?></button>
            </div>
        </div>
    </div>
</div>

<!-- User Profile Modal -->
<div class="modal fade" id="userProfileModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content bg-dark text-white">
            <div class="modal-header border-secondary">
                <h5 class="modal-title"><i class="fas fa-user mr-2"></i><?= __('tmiPublish.page.userProfileTitle') ?></h5>
                <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
            </div>
            <div class="modal-body">
                <?php if (!$userName): ?>
                <div class="alert alert-info small mb-3">
                    <i class="fas fa-info-circle mr-1"></i>
                    <?= __('tmiPublish.page.profileHint') ?>
                </div>
                <?php endif; ?>
                <div class="mb-3">
                    <label class="form-label small text-muted"><?= __('tmiPublish.page.name') ?> <span class="text-danger">*</span></label>
                    <input type="text" class="form-control <?= $userName ? 'bg-secondary text-white' : '' ?>" id="profileName" value="<?= htmlspecialchars($userName ?? '') ?>" <?= $userName ? 'readonly' : 'placeholder="Your Name"' ?>>
                </div>
                <div class="mb-3">
                    <label class="form-label small text-muted"><?= __('tmiPublish.page.cid') ?> <span class="text-danger">*</span></label>
                    <input type="text" class="form-control <?= $userCid ? 'bg-secondary text-white' : '' ?>" id="profileCid" value="<?= htmlspecialchars($userCid ?? '') ?>" <?= $userCid ? 'readonly' : 'placeholder="VATSIM CID"' ?>>
                </div>
                <div class="mb-3">
                    <label class="form-label small text-muted"><?= __('tmiPublish.page.operatingInitials') ?></label>
                    <input type="text" class="form-control text-uppercase" id="profileOI" maxlength="3" placeholder="XX" style="max-width: 80px;">
                </div>
                <div class="mb-3">
                    <label class="form-label small text-muted"><?= __('tmiPublish.page.homeFacility') ?></label>
                    <select class="form-control" id="profileFacility">
                        <option value=""><?= __('tmiPublish.page.selectFacility') ?></option>
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
                <button type="button" class="btn btn-primary" onclick="TMIPublisher.saveProfile()"><?= __('common.save') ?></button>
                <button type="button" class="btn btn-secondary" data-dismiss="modal"><?= __('common.cancel') ?></button>
            </div>
        </div>
    </div>
</div>

<!-- GS/GDP Cancellation Modal -->
<div class="modal fade" id="gsgdpCancelModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title"><i class="fas fa-times-circle mr-2"></i><?= __('tmiPublish.cancel.cancelProgram') ?></h5>
                <button type="button" class="close text-white" data-dismiss="modal">
                    <span>&times;</span>
                </button>
            </div>
            <div class="modal-body">
                <!-- Program Info -->
                <div class="alert alert-warning mb-3">
                    <div class="row">
                        <div class="col-md-4">
                            <strong><?= __('tmiPublish.cancel.programLabel') ?></strong>
                            <span id="cancelProgramType">--</span>
                        </div>
                        <div class="col-md-4">
                            <strong><?= __('tmiPublish.cancel.ctlElementLabel') ?></strong>
                            <span id="cancelCtlElement">--</span>
                        </div>
                        <div class="col-md-4">
                            <strong><?= __('tmiPublish.cancel.programIdLabel') ?></strong>
                            <span id="cancelProgramId">--</span>
                        </div>
                    </div>
                </div>

                <div class="form-group">
                    <label class="font-weight-bold"><?= __('tmiPublish.cancel.cancellationReason') ?> <span class="text-danger">*</span></label>
                    <select class="form-control" id="cancelReason">
                        <option value=""><?= __('tmiPublish.cancel.selectReason') ?></option>
                        <option value="WEATHER_IMPROVEMENT"><?= __('tmiPublish.cancel.weatherImprovement') ?></option>
                        <option value="DEMAND_DECREASE"><?= __('tmiPublish.cancel.demandDecrease') ?></option>
                        <option value="FACILITY_RESTORED"><?= __('tmiPublish.cancel.facilityRestored') ?></option>
                        <option value="TRANSITION_TO_AFP"><?= __('tmiPublish.cancel.transitionToAfp') ?></option>
                        <option value="TRANSITION_TO_GDP"><?= __('tmiPublish.cancel.transitionToGdp') ?></option>
                        <option value="OPERATIONAL_NEED"><?= __('tmiPublish.cancel.operationalNeed') ?></option>
                        <option value="USER_REQUEST"><?= __('tmiPublish.cancel.userRequest') ?></option>
                        <option value="OTHER"><?= __('common.other') ?></option>
                    </select>
                </div>

                <div class="form-group">
                    <label class="font-weight-bold"><?= __('tmiPublish.cancel.edctInstruction') ?> <span class="text-danger">*</span></label>
                    <div class="custom-control custom-radio mb-2">
                        <input type="radio" class="custom-control-input" id="edctDisregard" name="edctAction" value="DISREGARD" checked>
                        <label class="custom-control-label" for="edctDisregard">
                            <strong>DISREGARD EDCTS</strong> - <?= __('tmiPublish.cancel.disregardEdctsDesc') ?>
                        </label>
                    </div>
                    <div class="custom-control custom-radio mb-2">
                        <input type="radio" class="custom-control-input" id="edctDisregardAfter" name="edctAction" value="DISREGARD_AFTER">
                        <label class="custom-control-label" for="edctDisregardAfter">
                            <strong>DISREGARD AFTER</strong> - <?= __('tmiPublish.cancel.disregardAfterDesc') ?>
                        </label>
                    </div>
                    <div id="edctAfterTimeGroup" class="ml-4 mt-2 mb-2" style="display: none;">
                        <label class="small"><?= __('tmiPublish.cancel.disregardAfterTime') ?></label>
                        <input type="datetime-local" class="form-control form-control-sm" id="edctAfterTime" style="width: 250px;">
                    </div>
                    <div class="custom-control custom-radio">
                        <input type="radio" class="custom-control-input" id="edctAfpActive" name="edctAction" value="AFP_ACTIVE">
                        <label class="custom-control-label" for="edctAfpActive">
                            <strong>AFP ACTIVE</strong> - <?= __('tmiPublish.cancel.afpActiveDesc') ?>
                        </label>
                    </div>
                </div>

                <div class="form-group">
                    <label class="font-weight-bold"><?= __('tmiPublish.cancel.additionalNotes') ?></label>
                    <textarea class="form-control" id="cancelNotes" rows="2" placeholder="<?= __('tmiPublish.cancel.notesPlaceholder') ?>"></textarea>
                </div>

                <!-- Advisory Preview -->
                <div class="card bg-dark text-white">
                    <div class="card-header py-2">
                        <span class="font-weight-bold"><i class="fas fa-eye mr-1"></i> <?= __('tmiPublish.cancel.advisoryPreview') ?></span>
                    </div>
                    <div class="card-body">
                        <pre id="cancelAdvisoryPreview" style="font-size: 0.8rem; white-space: pre-wrap; margin: 0; color: #00ff00;"><?= __('common.loading') ?></pre>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal"><?= __('common.close') ?></button>
                <button type="button" class="btn btn-danger" id="confirmCancelBtn">
                    <i class="fas fa-times-circle mr-1"></i> <?= __('tmiPublish.cancel.cancelProgram') ?>
                </button>
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
    userRole: <?= json_encode($userRole) ?>,
    userHomeOrg: <?= json_encode($userHomeOrg) ?>,
    discordOrgs: <?= json_encode($discordOrgs) ?>,
    stagingRequired: <?= defined('TMI_STAGING_REQUIRED') && TMI_STAGING_REQUIRED ? 'true' : 'false' ?>,
    crossBorderAutoDetect: <?= defined('TMI_CROSS_BORDER_AUTO_DETECT') && TMI_CROSS_BORDER_AUTO_DETECT ? 'true' : 'false' ?>,
    defaultValidFrom: <?= json_encode($defaultStartFormatted) ?>,
    defaultValidUntil: <?= json_encode($defaultEndFormatted) ?>
};
</script>
<script src="assets/js/advisory-config.js<?= _v('assets/js/advisory-config.js') ?>"></script>
<script src="advisory-templates.js<?= _v('advisory-templates.js') ?>"></script>
<script src="assets/js/tmi-publish.js<?= _v('assets/js/tmi-publish.js') ?>"></script>
<script src="assets/js/tmi-active-display.js<?= _v('assets/js/tmi-active-display.js') ?>"></script>
<script src="assets/js/tmi-gdp.js<?= _v('assets/js/tmi-gdp.js') ?>"></script>
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
