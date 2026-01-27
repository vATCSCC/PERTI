<?php
/**
 * Unified TMI Publisher
 * 
 * One-stop interface for NTML entries, Advisories, and GDT Programs.
 * Features multi-Discord posting, staging/production workflow, and cross-border detection.
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
require_once("load/discord/MultiDiscordAPI.php");

// Check Permissions
$perm = false;
$isPrivileged = false;
$userOrg = 'vatcscc'; // Default
$userCID = '';
$userName = '';

if (!defined('DEV')) {
    if (isset($_SESSION['VATSIM_CID'])) {
        $userCID = session_get('VATSIM_CID', '');
        $userName = session_get('VATSIM_FIRST_NAME', '') . ' ' . session_get('VATSIM_LAST_NAME', '');
        
        $p_check = $conn_sqli->query("SELECT * FROM users WHERE cid='$userCID'");
        if ($p_check && $p_check->num_rows > 0) {
            $perm = true;
            $user = $p_check->fetch_assoc();
            
            // Check for privileged roles (Admin, NTMO, NTMS, NAS Ops)
            $privilegedRoles = ['Admin', 'NTMO', 'NTMS', 'NAS Operations'];
            if (!empty($user['role']) && in_array($user['role'], $privilegedRoles)) {
                $isPrivileged = true;
            }
            
            // Determine user's home org from their facility
            if (!empty($user['facility'])) {
                if (preg_match('/^CZ/', $user['facility'])) {
                    $userOrg = 'vatcan';
                }
            }
        }
    }
} else {
    // Dev mode
    $perm = true;
    $isPrivileged = true;
    $userCID = 'DEV';
    $userName = 'Developer';
    $_SESSION['VATSIM_FIRST_NAME'] = 'Dev';
    $_SESSION['VATSIM_LAST_NAME'] = 'User';
    $_SESSION['VATSIM_CID'] = 0;
}

// Load Discord organizations
$multiDiscord = new MultiDiscordAPI();
$availableOrgs = $multiDiscord->getOrgSummary();
$defaultOrg = $multiDiscord->getDefaultOrg();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php $page_title = "TMI Publisher"; include("load/header.php"); ?>
    
    <!-- Info Bar Shared Styles -->
    <link rel="stylesheet" href="assets/css/info-bar.css">
    
    <style>
        :root {
            --ntml-green: #00ff00;
            --ntml-dark: #1a1a2e;
            --advisory-blue: #007bff;
        }
        
        /* Tab Styling */
        .tmi-tabs {
            background: #1a1a2e;
            border-radius: 12px 12px 0 0;
            padding: 0;
            margin: 0;
        }
        
        .tmi-tabs .nav-link {
            color: #888;
            border: none;
            border-radius: 0;
            padding: 15px 30px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            transition: all 0.2s;
        }
        
        .tmi-tabs .nav-link:hover {
            color: #fff;
            background: rgba(255,255,255,0.05);
        }
        
        .tmi-tabs .nav-link.active {
            color: #fff;
            background: #2a2a4a;
            border-bottom: 3px solid var(--advisory-blue);
        }
        
        .tmi-tabs .nav-link i {
            margin-right: 8px;
        }
        
        /* Tab Content Container */
        .tmi-content {
            background: #2a2a4a;
            border-radius: 0 0 12px 12px;
            padding: 25px;
            min-height: 400px;
        }
        
        /* Discord Target Selection */
        .discord-targets {
            background: linear-gradient(135deg, #1a1a2e 0%, #252545 100%);
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 20px;
        }
        
        .discord-org-check {
            display: inline-flex;
            align-items: center;
            background: #0d0d1a;
            border: 2px solid #333;
            border-radius: 25px;
            padding: 8px 16px;
            margin: 4px;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .discord-org-check:hover {
            border-color: #555;
        }
        
        .discord-org-check.checked {
            border-color: #7289DA;
            background: rgba(114, 137, 218, 0.15);
        }
        
        .discord-org-check.disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }
        
        .discord-org-check input {
            display: none;
        }
        
        .discord-org-check .org-icon {
            width: 24px;
            height: 24px;
            border-radius: 50%;
            background: #7289DA;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 10px;
            font-size: 12px;
            color: white;
        }
        
        .discord-org-check .org-name {
            color: #ccc;
            font-weight: 500;
        }
        
        .discord-org-check .org-region {
            color: #666;
            font-size: 0.75rem;
            margin-left: 8px;
        }
        
        .discord-org-check.checked .org-name {
            color: #fff;
        }
        
        /* Cross-border indicator */
        .cross-border-badge {
            background: linear-gradient(135deg, #007bff, #28a745);
            color: white;
            padding: 4px 12px;
            border-radius: 15px;
            font-size: 0.75rem;
            font-weight: 600;
            display: none;
        }
        
        .cross-border-badge.show {
            display: inline-block;
        }
        
        /* Staging/Production Toggle */
        .publish-mode-toggle {
            background: #1a1a2e;
            border-radius: 25px;
            padding: 4px;
            display: inline-flex;
        }
        
        .publish-mode-btn {
            background: none;
            border: none;
            color: #888;
            padding: 10px 24px;
            border-radius: 20px;
            cursor: pointer;
            transition: all 0.2s;
            font-weight: 500;
        }
        
        .publish-mode-btn.active {
            color: white;
        }
        
        .publish-mode-btn.staging.active {
            background: #ffc107;
            color: #000;
        }
        
        .publish-mode-btn.production.active {
            background: #dc3545;
            color: white;
        }
        
        /* Production Warning Banner */
        .production-banner {
            background: linear-gradient(135deg, #dc3545 0%, #c82333 100%);
            color: white;
            padding: 12px 20px;
            border-radius: 8px;
            display: none;
            margin-bottom: 15px;
        }
        
        .production-banner.show {
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        
        /* Quick Entry Styling (from ntml.php) */
        .quick-entry-container {
            background: var(--ntml-dark);
            border-radius: 12px;
            padding: 25px;
            margin-bottom: 20px;
        }
        
        .quick-input {
            font-family: 'Consolas', 'Monaco', monospace;
            font-size: 1.1rem;
            background: #0d0d1a;
            border: 2px solid #333;
            color: var(--ntml-green);
            padding: 15px;
            border-radius: 8px;
            transition: all 0.2s;
        }
        
        .quick-input:focus {
            border-color: var(--ntml-green);
            box-shadow: 0 0 20px rgba(0, 255, 0, 0.2);
            outline: none;
        }
        
        .quick-input::placeholder {
            color: #555;
        }
        
        /* Preview Cards */
        .preview-card {
            background: #0d0d1a;
            border: 1px solid #333;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 10px;
            font-family: monospace;
            position: relative;
        }
        
        .preview-card.valid {
            border-color: #28a745;
        }
        
        .preview-card.invalid {
            border-color: #dc3545;
            background: rgba(220, 53, 69, 0.1);
        }
        
        .preview-card .determinant {
            color: var(--ntml-green);
            font-weight: bold;
            font-size: 1.1rem;
        }
        
        .preview-card .details {
            color: #aaa;
            font-size: 0.9rem;
            margin-top: 5px;
        }
        
        .preview-card .remove-btn {
            position: absolute;
            top: 10px;
            right: 10px;
            background: none;
            border: none;
            color: #666;
            cursor: pointer;
            padding: 5px;
        }
        
        .preview-card .remove-btn:hover {
            color: #dc3545;
        }
        
        /* Templates */
        .template-btn {
            background: #2a2a4a;
            border: 1px solid #444;
            color: #ccc;
            padding: 8px 15px;
            border-radius: 20px;
            font-size: 0.85rem;
            cursor: pointer;
            transition: all 0.2s;
            margin: 3px;
        }
        
        .template-btn:hover {
            background: #3a3a5a;
            border-color: #007bff;
            color: #fff;
        }
        
        .template-btn.active {
            background: #007bff;
            border-color: #007bff;
        }
        
        /* Mode Toggle */
        .mode-toggle {
            background: #1a1a2e;
            border-radius: 25px;
            padding: 4px;
            display: inline-flex;
        }
        
        .mode-toggle .mode-btn {
            background: none;
            border: none;
            color: #888;
            padding: 8px 20px;
            border-radius: 20px;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .mode-toggle .mode-btn.active {
            background: #007bff;
            color: white;
        }
        
        /* Syntax Help */
        .syntax-help {
            background: #1a1a2e;
            border-radius: 8px;
            padding: 15px;
            font-size: 0.85rem;
            color: #aaa;
        }
        
        .syntax-help code {
            background: #0d0d1a;
            padding: 2px 6px;
            border-radius: 4px;
            color: var(--ntml-green);
        }
        
        /* Submit Area */
        .submit-area {
            background: linear-gradient(135deg, #1a1a2e 0%, #2a2a4a 100%);
            border-radius: 12px;
            padding: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .entry-count {
            font-size: 1.5rem;
            font-weight: bold;
            color: var(--ntml-green);
        }
        
        .entry-count small {
            font-size: 0.9rem;
            color: #888;
            font-weight: normal;
        }
        
        /* Keyboard shortcuts hint */
        .kbd {
            background: #333;
            border: 1px solid #555;
            border-radius: 4px;
            padding: 2px 6px;
            font-family: monospace;
            font-size: 0.8rem;
            color: #ccc;
        }
        
        /* Advisory Builder Styles */
        .tmi-label {
            font-size: 0.7rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            font-weight: 600;
            color: #aaa;
        }
        
        .advisory-type-card {
            cursor: pointer;
            transition: all 0.2s ease;
            border: 2px solid transparent;
            background: #1a1a2e;
        }
        
        .advisory-type-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.3);
        }
        
        .advisory-type-card.selected {
            border-color: #007bff;
            background: rgba(0, 123, 255, 0.1);
        }
        
        .advisory-type-icon {
            font-size: 1.5rem;
            margin-bottom: 5px;
        }
        
        .section-card {
            display: none;
            background: #1a1a2e;
            border: 1px solid #333;
        }
        
        .section-card.active {
            display: block;
        }
        
        .section-card .card-header {
            background: #252545;
            border-bottom: 1px solid #333;
        }
        
        .section-card .card-body {
            background: #1a1a2e;
        }
        
        /* Type-specific header colors */
        .adv-header-gdp { background: #ffc107 !important; }
        .adv-header-gdp .tmi-section-title { color: #000 !important; }
        .adv-header-gs { background: #dc3545 !important; }
        .adv-header-afp { background: #17a2b8 !important; }
        .adv-header-ctop { background: #6f42c1 !important; }
        .adv-header-reroute { background: #28a745 !important; }
        .adv-header-atcscc { background: #6c757d !important; }
        .adv-header-mit { background: #fd7e14 !important; }
        .adv-header-cnx { background: #343a40 !important; }
        
        .tmi-section-title {
            font-weight: 600;
            font-size: 0.9rem;
            text-transform: uppercase;
            color: #fff;
        }
        
        /* Advisory Preview */
        .tmi-advisory-preview {
            white-space: pre;
            font-family: "Inconsolata", "Consolas", monospace;
            font-size: 0.8rem;
            color: var(--ntml-green);
            background: #0d0d1a;
            padding: 15px;
            border-radius: 4px;
            max-height: 400px;
            overflow-y: auto;
            border: 1px solid #333;
        }
        
        /* Form Controls in Dark Mode */
        .tmi-content .form-control {
            background: #0d0d1a;
            border: 1px solid #444;
            color: #fff;
        }
        
        .tmi-content .form-control:focus {
            background: #0d0d1a;
            border-color: #007bff;
            color: #fff;
            box-shadow: 0 0 0 0.2rem rgba(0,123,255,.25);
        }
        
        .tmi-content .form-control::placeholder {
            color: #666;
        }
        
        .tmi-content select.form-control {
            background: #0d0d1a;
            color: #fff;
        }
        
        .tmi-content select.form-control option {
            background: #1a1a2e;
            color: #fff;
        }
        
        /* Batch Entry Textarea */
        .batch-textarea {
            font-family: 'Consolas', 'Monaco', monospace;
            font-size: 0.95rem;
            background: #0d0d1a;
            border: 2px solid #333;
            color: var(--ntml-green);
            min-height: 150px;
            resize: vertical;
        }
        
        .batch-textarea:focus {
            border-color: var(--ntml-green);
            box-shadow: 0 0 20px rgba(0, 255, 0, 0.2);
        }
        
        /* Autocomplete */
        .autocomplete-dropdown {
            position: absolute;
            background: #1a1a2e;
            border: 1px solid #444;
            border-radius: 8px;
            max-height: 200px;
            overflow-y: auto;
            z-index: 1000;
            width: 100%;
            display: none;
        }
        
        .autocomplete-dropdown.show {
            display: block;
        }
        
        .autocomplete-item {
            padding: 10px 15px;
            cursor: pointer;
            border-bottom: 1px solid #333;
            color: #ccc;
        }
        
        .autocomplete-item:hover, .autocomplete-item.selected {
            background: #2a2a4a;
            color: white;
        }
        
        .autocomplete-item .facility-id {
            font-family: monospace;
            color: var(--ntml-green);
            font-weight: bold;
        }
        
        .autocomplete-item .facility-name {
            font-size: 0.85rem;
            color: #888;
        }
        
        /* Results Summary */
        .publish-results {
            background: #1a1a2e;
            border-radius: 12px;
            padding: 20px;
            margin-top: 20px;
        }
        
        .publish-result-item {
            display: flex;
            align-items: center;
            padding: 10px;
            border-bottom: 1px solid #333;
        }
        
        .publish-result-item:last-child {
            border-bottom: none;
        }
        
        .publish-result-item .org-badge {
            background: #7289DA;
            color: white;
            padding: 4px 12px;
            border-radius: 15px;
            font-size: 0.8rem;
            margin-right: 15px;
        }
        
        .publish-result-item.success .status-icon {
            color: #28a745;
        }
        
        .publish-result-item.error .status-icon {
            color: #dc3545;
        }
        
        .publish-result-item .message-link {
            color: #7289DA;
            text-decoration: none;
        }
        
        .publish-result-item .message-link:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>

<?php include('load/nav.php'); ?>

<section class="d-flex align-items-center position-relative bg-position-center fh-section overflow-hidden pt-6 jarallax bg-dark text-light" data-jarallax data-speed="0.3">
    <div class="container-fluid pt-2 pb-4 py-lg-5">
        <img class="jarallax-img" src="assets/img/jumbotron/main.png" alt="" style="opacity: 50%;">
        <center>
            <h1><i class="fas fa-broadcast-tower"></i> TMI Publisher</h1>
            <p class="text-white-50 mb-0">Unified NTML & Advisory Publishing System</p>
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
                <div class="card shadow-sm perti-info-card h-100">
                    <div class="card-body d-flex justify-content-between align-items-center">
                        <div>
                            <div class="perti-info-label">Logged in as</div>
                            <div class="perti-clock-display perti-clock-display-sm"><?= htmlspecialchars($userName) ?></div>
                        </div>
                        <div class="ml-3">
                            <?php if ($isPrivileged): ?>
                            <span class="badge badge-warning">PRIVILEGED</span>
                            <?php else: ?>
                            <span class="badge badge-secondary"><?= strtoupper($userOrg) ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col"></div>
            
            <!-- Publish Mode Toggle -->
            <div class="col-auto px-1">
                <div class="card shadow-sm perti-info-card h-100">
                    <div class="card-body d-flex align-items-center py-2 px-3">
                        <div class="publish-mode-toggle">
                            <button class="publish-mode-btn staging active" data-mode="staging">
                                <i class="fas fa-flask mr-1"></i> Staging
                            </button>
                            <button class="publish-mode-btn production" data-mode="production">
                                <i class="fas fa-broadcast-tower mr-1"></i> Production
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Production Warning Banner -->
    <div class="production-banner" id="productionBanner">
        <div>
            <i class="fas fa-exclamation-triangle mr-2"></i>
            <strong>Production Mode Active</strong> — Entries will post to LIVE Discord channels
        </div>
        <button class="btn btn-sm btn-outline-light" onclick="TMIPublisher.setMode('staging')">
            Switch to Staging
        </button>
    </div>
    
    <!-- Discord Targets Section -->
    <div class="discord-targets">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <div>
                <h5 class="text-white mb-0">
                    <i class="fab fa-discord mr-2"></i> Post To Discord
                </h5>
                <small class="text-muted">Select target organization(s)</small>
            </div>
            <div>
                <span class="cross-border-badge" id="crossBorderBadge">
                    <i class="fas fa-globe-americas mr-1"></i> Cross-Border TMI Detected
                </span>
            </div>
        </div>
        
        <div class="discord-org-list" id="discordOrgList">
            <?php foreach ($availableOrgs as $org): ?>
            <?php 
                $isDefault = ($org['code'] === $defaultOrg);
                $canPost = $isPrivileged || ($org['code'] === $userOrg);
                $testingOnly = !empty($org['testing_only']);
            ?>
            <label class="discord-org-check <?= $isDefault ? 'checked' : '' ?> <?= !$canPost ? 'disabled' : '' ?>"
                   data-org="<?= $org['code'] ?>"
                   data-region="<?= $org['region'] ?>"
                   <?= $testingOnly ? 'data-testing="true"' : '' ?>>
                <input type="checkbox" 
                       name="discord_orgs[]" 
                       value="<?= $org['code'] ?>" 
                       <?= $isDefault ? 'checked' : '' ?>
                       <?= !$canPost ? 'disabled' : '' ?>>
                <div class="org-icon">
                    <i class="fab fa-discord"></i>
                </div>
                <span class="org-name"><?= htmlspecialchars($org['name']) ?></span>
                <span class="org-region"><?= $org['region'] ?></span>
                <?php if ($testingOnly): ?>
                <span class="badge badge-warning ml-2" style="font-size: 0.6rem;">TEST</span>
                <?php endif; ?>
            </label>
            <?php endforeach; ?>
        </div>
    </div>
    
    <!-- Main Tabs -->
    <ul class="nav tmi-tabs" id="tmiTabs" role="tablist">
        <li class="nav-item">
            <a class="nav-link active" id="ntml-tab" data-toggle="tab" href="#ntmlPanel" role="tab">
                <i class="fas fa-terminal"></i> NTML Quick Entry
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link" id="advisory-tab" data-toggle="tab" href="#advisoryPanel" role="tab">
                <i class="fas fa-bullhorn"></i> Advisory Builder
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link" id="program-tab" data-toggle="tab" href="#programPanel" role="tab">
                <i class="fas fa-clock"></i> GDT Program
            </a>
        </li>
    </ul>
    
    <!-- Tab Content -->
    <div class="tab-content tmi-content" id="tmiTabContent">
        
        <!-- NTML Quick Entry Panel -->
        <div class="tab-pane fade show active" id="ntmlPanel" role="tabpanel">
            <!-- Header Controls -->
            <div class="d-flex justify-content-between align-items-center mb-3">
                <div class="mode-toggle">
                    <button class="mode-btn active" data-mode="single">Single Entry</button>
                    <button class="mode-btn" data-mode="batch">Batch Entry</button>
                </div>
            </div>
            
            <!-- Quick Entry Container -->
            <div class="quick-entry-container">
                <!-- Single Entry Mode -->
                <div id="singleMode">
                    <div class="row">
                        <div class="col-lg-8">
                            <label class="text-white-50 mb-2">
                                <i class="fas fa-terminal"></i> Quick Entry 
                                <small class="text-muted ml-2">Press <span class="kbd">Enter</span> to add, <span class="kbd">Ctrl+Enter</span> to submit all</small>
                            </label>
                            <div class="position-relative">
                                <input type="text" class="form-control quick-input" id="quickInput" 
                                       placeholder="20MIT ZBW→ZNY JFK LENDY VOLUME" autocomplete="off">
                                <div class="autocomplete-dropdown" id="autocompleteDropdown"></div>
                            </div>
                        </div>
                        <div class="col-lg-4">
                            <label class="text-white-50 mb-2">Validity Period</label>
                            <div class="d-flex align-items-center">
                                <input type="text" class="form-control quick-input mr-2" id="validFrom" placeholder="1400" maxlength="4" style="width: 80px;">
                                <span class="text-white-50 mx-2">→</span>
                                <input type="text" class="form-control quick-input" id="validUntil" placeholder="1800" maxlength="4" style="width: 80px;">
                                <span class="text-white-50 ml-2">Z</span>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Batch Entry Mode -->
                <div id="batchMode" style="display: none;">
                    <label class="text-white-50 mb-2">
                        <i class="fas fa-layer-group"></i> Batch Entry 
                        <small class="text-muted ml-2">One entry per line</small>
                    </label>
                    <textarea class="form-control batch-textarea" id="batchInput" rows="6" 
                              placeholder="20MIT ZBW→ZNY JFK LENDY VOLUME 1400-1800
15MIT ZDC→ZNY EWR VOLUME 1400-1800
30MINIT ZOB→ZNY LGA WEATHER 1500-1900"></textarea>
                </div>
                
                <!-- Templates -->
                <div class="mt-3">
                    <span class="text-white-50 mr-2"><i class="fas fa-magic"></i> Templates:</span>
                    <button class="template-btn" data-template="mit-arr">MIT Arrival</button>
                    <button class="template-btn" data-template="mit-dep">MIT Departure</button>
                    <button class="template-btn" data-template="minit">MINIT</button>
                    <button class="template-btn" data-template="stop">Ground Stop</button>
                    <button class="template-btn" data-template="delay">Delay</button>
                    <button class="template-btn" data-template="config">Airport Config</button>
                    <button class="template-btn" data-template="cancel">Cancel TMI</button>
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
                                    <code>[distance]MIT [from]→[to] [airport/fix] [reason]</code><br>
                                    <small>Example: <code>20MIT ZBW→ZNY JFK LENDY VOLUME</code></small>
                                    <hr class="my-2 border-secondary">
                                    <strong>Delay:</strong><br>
                                    <code>DELAY [facility] [minutes]min [trend] [flights]flt</code><br>
                                    <small>Example: <code>DELAY JFK 45min INC 12flt WEATHER</code></small>
                                </div>
                                <div class="col-md-6">
                                    <strong>Airport Config:</strong><br>
                                    <code>CONFIG [airport] [wx] ARR:[rwys] DEP:[rwys] AAR:[n] ADR:[n]</code><br>
                                    <small>Example: <code>CONFIG JFK IMC ARR:22L/22R DEP:31L AAR:40 ADR:45</code></small>
                                    <hr class="my-2 border-secondary">
                                    <strong>Ground Stop:</strong><br>
                                    <code>STOP [airport] [reason] [time range] [scope]</code><br>
                                    <small>Example: <code>STOP JFK WEATHER 1400-1600 ZNY:ZDC</code></small>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Entry Queue Preview -->
            <div class="row">
                <div class="col-12">
                    <h5 class="text-white mb-3">
                        <i class="fas fa-list"></i> Entry Queue 
                        <span class="badge badge-secondary" id="queueCount">0</span>
                        <button class="btn btn-sm btn-outline-danger float-right" id="clearQueue" style="display: none;">
                            <i class="fas fa-trash"></i> Clear All
                        </button>
                    </h5>
                    <div id="entryQueue">
                        <div class="text-center text-muted py-4" id="emptyQueueMsg">
                            <i class="fas fa-inbox fa-2x mb-2"></i><br>
                            No entries queued. Type above to add entries.
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Submit Area -->
            <div class="submit-area mt-4" id="ntmlSubmitArea" style="display: none;">
                <div class="entry-count">
                    <span id="submitCount">0</span> <small>entries ready</small>
                </div>
                <div>
                    <button class="btn btn-outline-secondary mr-2" id="previewBtn">
                        <i class="fas fa-eye"></i> Preview Messages
                    </button>
                    <button class="btn btn-success btn-lg" id="submitAllBtn">
                        <i class="fas fa-paper-plane"></i> Submit All to NTML
                    </button>
                </div>
            </div>
        </div>
        
        <!-- Advisory Builder Panel -->
        <div class="tab-pane fade" id="advisoryPanel" role="tabpanel">
            <div class="row">
                <!-- Left Column: Advisory Form -->
                <div class="col-lg-6 mb-4">
                    <!-- Advisory Type Selector -->
                    <div class="card shadow-sm mb-3" style="background: #1a1a2e; border: 1px solid #333;">
                        <div class="card-header" style="background: #252545; border-bottom: 1px solid #333;">
                            <span class="tmi-section-title">
                                <i class="fas fa-list-alt mr-1"></i> Select Advisory Type
                            </span>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <!-- GDP -->
                                <div class="col-md-3 col-6 mb-2">
                                    <div class="card advisory-type-card text-center p-2" data-type="GDP">
                                        <div class="advisory-type-icon text-warning"><i class="fas fa-hourglass-half"></i></div>
                                        <div class="small font-weight-bold text-white">GDP</div>
                                        <div class="text-muted" style="font-size: 0.65rem;">Ground Delay</div>
                                    </div>
                                </div>
                                <!-- GS -->
                                <div class="col-md-3 col-6 mb-2">
                                    <div class="card advisory-type-card text-center p-2" data-type="GS">
                                        <div class="advisory-type-icon text-danger"><i class="fas fa-ban"></i></div>
                                        <div class="small font-weight-bold text-white">GS</div>
                                        <div class="text-muted" style="font-size: 0.65rem;">Ground Stop</div>
                                    </div>
                                </div>
                                <!-- AFP -->
                                <div class="col-md-3 col-6 mb-2">
                                    <div class="card advisory-type-card text-center p-2" data-type="AFP">
                                        <div class="advisory-type-icon text-info"><i class="fas fa-vector-square"></i></div>
                                        <div class="small font-weight-bold text-white">AFP</div>
                                        <div class="text-muted" style="font-size: 0.65rem;">Airspace Flow</div>
                                    </div>
                                </div>
                                <!-- Reroute -->
                                <div class="col-md-3 col-6 mb-2">
                                    <div class="card advisory-type-card text-center p-2" data-type="REROUTE">
                                        <div class="advisory-type-icon text-success"><i class="fas fa-directions"></i></div>
                                        <div class="small font-weight-bold text-white">Reroute</div>
                                        <div class="text-muted" style="font-size: 0.65rem;">Route Advisory</div>
                                    </div>
                                </div>
                                <!-- Free-Form -->
                                <div class="col-md-3 col-6 mb-2">
                                    <div class="card advisory-type-card text-center p-2" data-type="ATCSCC">
                                        <div class="advisory-type-icon text-secondary"><i class="fas fa-file-alt"></i></div>
                                        <div class="small font-weight-bold text-white">Free-Form</div>
                                        <div class="text-muted" style="font-size: 0.65rem;">ATCSCC Advisory</div>
                                    </div>
                                </div>
                                <!-- MIT -->
                                <div class="col-md-3 col-6 mb-2">
                                    <div class="card advisory-type-card text-center p-2" data-type="MIT">
                                        <div class="advisory-type-icon" style="color: #fd7e14;"><i class="fas fa-ruler-horizontal"></i></div>
                                        <div class="small font-weight-bold text-white">MIT/MINIT</div>
                                        <div class="text-muted" style="font-size: 0.65rem;">Miles-in-Trail</div>
                                    </div>
                                </div>
                                <!-- Cancel -->
                                <div class="col-md-3 col-6 mb-2">
                                    <div class="card advisory-type-card text-center p-2" data-type="CNX">
                                        <div class="advisory-type-icon text-white"><i class="fas fa-times-circle"></i></div>
                                        <div class="small font-weight-bold text-white">Cancel</div>
                                        <div class="text-muted" style="font-size: 0.65rem;">CNX Advisory</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Basic Information -->
                    <div class="card shadow-sm mb-3 section-card active" id="section_basic" style="background: #1a1a2e; border: 1px solid #333;">
                        <div class="card-header" style="background: #252545; border-bottom: 1px solid #333;">
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
                    <div class="card shadow-sm mb-3 section-card active" id="section_timing" style="background: #1a1a2e; border: 1px solid #333;">
                        <div class="card-header" style="background: #252545; border-bottom: 1px solid #333;">
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
                            <div class="form-row">
                                <div class="col-12">
                                    <small class="text-muted">
                                        Effective: <span id="adv_effective_display" class="font-weight-bold text-primary">--/----Z - --/----Z</span>
                                    </small>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Type-Specific Sections will be loaded here -->
                    <div id="advisoryTypeSections">
                        <!-- Dynamic content based on selected type -->
                    </div>
                    
                    <!-- Comments Section -->
                    <div class="card shadow-sm mb-3" id="section_comments" style="background: #1a1a2e; border: 1px solid #333;">
                        <div class="card-header" style="background: #252545; border-bottom: 1px solid #333;">
                            <span class="tmi-section-title">
                                <i class="fas fa-comment mr-1"></i> Comments
                            </span>
                        </div>
                        <div class="card-body">
                            <div class="form-group mb-0">
                                <label class="tmi-label mb-0" for="adv_comments">Additional Comments</label>
                                <textarea class="form-control form-control-sm" id="adv_comments" rows="2" placeholder="Additional notes or comments..."></textarea>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Right Column: Preview -->
                <div class="col-lg-6 mb-4">
                    <!-- Advisory Preview -->
                    <div class="card shadow-sm mb-3" style="background: #1a1a2e; border: 1px solid #333;">
                        <div class="card-header d-flex justify-content-between align-items-center" style="background: #252545; border-bottom: 1px solid #333;">
                            <span class="tmi-section-title">
                                <i class="fas fa-eye mr-1"></i> Advisory Preview
                            </span>
                            <span class="char-count text-muted" id="preview_char_count">0 / 2000</span>
                        </div>
                        <div class="card-body">
                            <pre id="adv_preview" class="tmi-advisory-preview" style="user-select: all;">Select an advisory type to begin...</pre>
                        </div>
                        <div class="card-footer d-flex justify-content-between" style="background: #252545; border-top: 1px solid #333;">
                            <button class="btn btn-sm btn-outline-secondary" id="btn_copy" type="button">
                                <i class="fas fa-copy mr-1"></i> Copy
                            </button>
                            <button class="btn btn-sm btn-success" id="btn_publish_advisory" type="button">
                                <i class="fas fa-paper-plane mr-1"></i> Publish Advisory
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- GDT Program Panel -->
        <div class="tab-pane fade" id="programPanel" role="tabpanel">
            <div class="text-center py-5">
                <i class="fas fa-clock fa-4x text-muted mb-3"></i>
                <h4 class="text-white">GDT Program Management</h4>
                <p class="text-muted">Create and manage Ground Stop and Ground Delay Programs.</p>
                <a href="gdt.php" class="btn btn-primary">
                    <i class="fas fa-external-link-alt mr-2"></i> Open GDT Manager
                </a>
            </div>
        </div>
    </div>
    
    <!-- Publish Results (shown after submission) -->
    <div class="publish-results" id="publishResults" style="display: none;">
        <h5 class="text-white mb-3">
            <i class="fas fa-check-circle text-success mr-2"></i> Publish Results
        </h5>
        <div id="publishResultsList">
            <!-- Results will be populated here -->
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
            <div class="modal-body" id="previewContent" style="font-family: monospace; white-space: pre-wrap; color: #00ff00;">
            </div>
            <div class="modal-footer border-secondary">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
                <button type="button" class="btn btn-success" id="submitFromPreview">
                    <i class="fas fa-paper-plane"></i> Submit All
                </button>
            </div>
        </div>
    </div>
</div>

<?php include('load/footer.php'); ?>

<script>
// Pass PHP config to JavaScript
window.TMIPublisherConfig = {
    userCID: '<?= $userCID ?>',
    userName: '<?= addslashes($userName) ?>',
    userOrg: '<?= $userOrg ?>',
    isPrivileged: <?= $isPrivileged ? 'true' : 'false' ?>,
    defaultOrg: '<?= $defaultOrg ?>',
    availableOrgs: <?= json_encode($availableOrgs) ?>,
    csrfToken: '<?= $_SESSION['csrf_token'] ?? '' ?>'
};
</script>

<!-- Load existing NTML parser -->
<script src="assets/js/ntml.js"></script>

<!-- Load advisory config and builder -->
<script src="assets/js/advisory-config.js"></script>
<script src="assets/js/advisory-builder.js"></script>

<!-- Load unified publisher orchestrator -->
<script src="assets/js/tmi-publish.js"></script>

</body>
</html>
