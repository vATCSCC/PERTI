<?php
/**
 * JATOC - Joint Air Traffic Operations Command
 * AWO Incident Monitor - No Auth Required
 *
 * OPTIMIZED: Public page - no session handler or DB needed
 * Session state is read by nav_public.php for login display
 */
include("load/config.php");
include("load/i18n.php");

// Start session before output so JATOC_CONFIG can read login state
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
$logged_in = isset($_SESSION['VATSIM_CID']) && !empty($_SESSION['VATSIM_CID']);
$user_cid = $logged_in ? $_SESSION['VATSIM_CID'] : '';
$user_name = $logged_in ? trim(($_SESSION['VATSIM_FIRST_NAME'] ?? '') . ' ' . ($_SESSION['VATSIM_LAST_NAME'] ?? '')) : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php $page_title = "JATOC"; include("load/header.php"); ?>
    <link href="https://unpkg.com/maplibre-gl@4.5.0/dist/maplibre-gl.css" rel="stylesheet" />
    <link rel="stylesheet" href="assets/css/info-bar.css<?= _v('assets/css/info-bar.css') ?>">
    <script src="https://unpkg.com/maplibre-gl@4.5.0/dist/maplibre-gl.js"></script>
    <style>
    /* Dark mode base */
    body { background-color: #0f172a !important; }
    .container-fluid { background-color: transparent; }
    section { background-color: #0f172a !important; }

    /* Header & OpLevel */
    .jatoc-header-bar { background: linear-gradient(135deg, #166534 0%, #22c55e 100%); padding: 10px 20px; border-bottom: 3px solid #22c55e; border-radius: 4px; margin-bottom: 15px; transition: all 0.3s ease; }
    .jatoc-title { font-size: 1.4rem; font-weight: 700; letter-spacing: 2px; margin: 0; transition: color 0.3s ease; }
    .jatoc-subtitle { font-size: 0.85rem; font-weight: 500; margin: 0; transition: color 0.3s ease; }
    .ops-level-1-text .jatoc-title { color: #bbf7d0; }
    .ops-level-1-text .jatoc-subtitle { color: #dcfce7; }
    .ops-level-2-text .jatoc-title { color: #1f2937; }
    .ops-level-2-text .jatoc-subtitle { color: #374151; }
    .ops-level-3-text .jatoc-title { color: #fef2f2; }
    .ops-level-3-text .jatoc-subtitle { color: #fecaca; }
    .classification-banner { background-color: #dc2626; color: white; text-align: center; padding: 6px 0; font-weight: 700; font-size: 0.8rem; letter-spacing: 1px; margin-bottom: 15px; border-radius: 4px; }
    .ops-level-badge { font-size: 1rem; padding: 8px 20px; border-radius: 6px; font-weight: 700; border: 2px solid transparent; cursor: pointer; text-transform: uppercase; letter-spacing: 1px; }
    .ops-level-1 { background-color: #22c55e; color: white; border-color: #16a34a; }
    .ops-level-2 { background-color: #f59e0b; color: black; border-color: #d97706; }
    .ops-level-3 { background-color: #ef4444; color: white; border-color: #dc2626; }

    /* Section Headers */
    .section-header { background-color: #1e40af; color: white; padding: 6px 10px; font-weight: 600; font-size: 0.75rem; letter-spacing: 1px; text-transform: uppercase; border-radius: 4px 4px 0 0; }
    .section-header.green { background-color: #166534; }
    .section-header.slate { background-color: #334155; }
    .section-header.indigo { background-color: #4338ca; }

    /* Events Table - LIGHT TEXT */
    .events-table { width: 100%; font-size: 0.8rem; color: #e2e8f0; }
    .events-table th { background-color: #1e40af; color: white; padding: 6px 8px; text-transform: uppercase; font-size: 0.7rem; cursor: pointer; user-select: none; }
    .events-table th:first-child { padding-left: 12px; }
    .events-table th:hover { background-color: #1e3a8a; }
    .events-table th .sort-icon { margin-left: 4px; opacity: 0.5; }
    .events-table th.sort-active .sort-icon { opacity: 1; }
    .events-table td { padding: 6px 8px; border-bottom: 1px solid #374151; vertical-align: middle; color: #e2e8f0; }
    .events-table td:first-child { padding-left: 12px; }
    .events-table tr:hover { background-color: rgba(59, 130, 246, 0.15); }
    .events-table .trigger-col { max-width: 140px; white-space: normal; word-wrap: break-word; font-size: 0.7rem; color: #cbd5e1; }
    
    /* Status badges */
    .status-badge { padding: 2px 6px; border-radius: 4px; font-weight: 600; font-size: 0.7rem; }
    .status-atc-zero { background-color: #dc2626; color: white; }
    .status-atc-alert { background-color: #f59e0b; color: black; }
    .status-atc-limited { background-color: #3b82f6; color: white; }
    .status-non-responsive { background-color: #8b5cf6; color: white; }
    .status-other { background-color: #6b7280; color: white; }
    .paged-yes { color: #4ade80; font-weight: 700; font-size: 0.75rem; }
    .paged-no { color: #64748b; font-size: 0.75rem; }

    /* Filter bar */
    .filter-bar { background-color: #1e293b; padding: 10px; border-radius: 4px; margin-bottom: 10px; margin-top: 15px; }
    .filter-bar label { color: #94a3b8; }
    .btn-jatoc { background-color: #1e40af; border-color: #1e40af; color: white; font-size: 0.8rem; }
    .btn-jatoc:hover { background-color: #1e3a8a; color: white; }
    .btn-jatoc-success { background-color: #166534; border-color: #166534; color: white; font-size: 0.8rem; }
    .btn-jatoc-success:hover { background-color: #15803d; color: white; }

    /* Map */
    #jatoc-map-container { position: relative; height: 420px; border-radius: 0 0 4px 4px; overflow: hidden; }
    #jatoc-map { width: 100%; height: 100%; }
    .jatoc-map-legend { position: absolute; bottom: 10px; left: 10px; background: rgba(0,0,0,0.85); padding: 6px 10px; border-radius: 4px; font-size: 0.65rem; color: white; z-index: 1; }
    .jatoc-map-legend-item { display: flex; align-items: center; gap: 4px; margin-bottom: 2px; }
    .jatoc-map-legend-dot { width: 10px; height: 10px; border-radius: 50%; border: 1px solid white; }
    .maplibregl-popup-content { background: #1f2937; color: white; padding: 8px 12px; border-radius: 4px; font-size: 0.75rem; }
    
    /* Layer control */
    .jatoc-layer-control { position: absolute; top: 10px; right: 50px; z-index: 10; }
    #jatoc-layer-toggle { background: rgba(31, 41, 55, 0.9); border: 1px solid #4b5563; padding: 5px 10px; border-radius: 4px; color: #f9fafb; cursor: pointer; font-size: 0.75rem; }
    #jatoc-layer-panel { display: none; background: rgba(15, 23, 42, 0.95); border: 1px solid #4b5563; border-radius: 4px; padding: 8px; margin-top: 5px; min-width: 160px; }
    .layer-option { display: flex; align-items: center; gap: 6px; padding: 3px 0; cursor: pointer; color: #f1f5f9; font-size: 0.75rem; }
    .layer-option:hover { color: #ffffff; }
    .layer-option input[type="checkbox"] { accent-color: #3b82f6; width: 14px; height: 14px; }

    /* Sidebar cards */
    .ops-calendar { background: #1e293b; border-radius: 0 0 4px 4px; max-height: 130px; overflow-y: auto; }
    .ops-calendar-row { display: flex; padding: 3px 6px; border-bottom: 1px solid #334155; font-size: 0.7rem; }
    .ops-calendar-row.past { opacity: 0.5; }
    .ops-calendar-row.active { background: rgba(34, 197, 94, 0.2); border-left: 3px solid #22c55e; }
    .ops-calendar-time { width: 55px; color: #94a3b8; font-family: monospace; font-size: 0.65rem; }
    .ops-calendar-event { flex: 1; color: #e2e8f0; font-size: 0.7rem; }
    
    .vatusa-section { background: #1e293b; border-radius: 0 0 4px 4px; max-height: 130px; overflow-y: auto; padding: 6px; }
    .collapsible-header { cursor: pointer; padding: 4px 8px; background: #334155; border-radius: 3px; margin-bottom: 3px; display: flex; justify-content: space-between; color: #f1f5f9; font-weight: 500; font-size: 0.7rem; }
    .collapsible-content { display: none; padding-left: 8px; }
    .collapsible-content.show { display: block; }
    .vatusa-count-badge { background: #3b82f6; color: white; padding: 1px 6px; border-radius: 8px; font-size: 0.6rem; }
    .vatusa-event { padding: 2px 0; border-bottom: 1px solid #334155; font-size: 0.7rem; color: #cbd5e1; }
    .vatusa-event-name { color: #60a5fa; font-weight: 500; font-size: 0.7rem; }

    /* Personnel table */
    .personnel-table { font-size: 0.75rem; color: #e2e8f0; }
    .personnel-table td { padding: 4px 6px; color: #cbd5e1; }
    .personnel-table .text-info { color: #60a5fa !important; }

    /* Modals */
    .modal-jatoc .modal-header { background-color: #1e40af; color: white; padding: 10px 15px; }
    .modal-jatoc .modal-content { background-color: #0f172a; border: 1px solid #334155; }
    .modal-jatoc .modal-title { font-size: 1rem; }
    .modal-jatoc .modal-footer { background-color: #1e293b; border-top: 1px solid #334155; }
    .modal-jatoc .modal-body { color: #e2e8f0; }
    .form-control-dark { background-color: #1e293b; border-color: #475569; color: #f1f5f9; font-size: 0.85rem; }
    .form-control-dark:focus { background-color: #334155; border-color: #3b82f6; color: #f1f5f9; }
    .form-control-dark::placeholder { color: #64748b; }
    .form-control-dark option { background-color: #1e293b; color: #f1f5f9; }

    /* Update history - FIXED ALIGNMENT */
    .update-entry { background-color: #1e293b; border-left: 3px solid #3b82f6; padding: 6px 10px; margin-bottom: 6px; border-radius: 0 4px 4px 0; }
    .update-entry.ops-level { border-left-color: #f59e0b; background-color: #1c1917; }
    .update-header { display: grid; grid-template-columns: 100px 1fr 160px; gap: 8px; font-family: 'Courier New', monospace; font-size: 0.65rem; color: #94a3b8; margin-bottom: 2px; }
    .update-header .type { text-align: left; }
    .update-header .author { text-align: center; color: #60a5fa; }
    .update-header .time { text-align: right; }
    .update-content { color: #e2e8f0; font-size: 0.8rem; white-space: pre-wrap; font-family: 'Courier New', monospace; }
    .priority-text { color: #f87171 !important; font-weight: 700 !important; }
    
    #detailsRemarks { background-color: #1e293b; border: 1px solid #475569; padding: 8px; border-radius: 4px; min-height: 50px; color: #f1f5f9; font-family: 'Courier New', monospace; font-size: 0.85rem; }

    /* Quick actions - HORIZONTAL ALIGNMENT */
    .quick-actions { display: inline-flex; gap: 3px; justify-content: center; align-items: center; }
    .quick-actions .btn { padding: 3px 6px; font-size: 0.7rem; border-radius: 3px; line-height: 1; min-width: 28px; }
    .events-table td.quick-actions-cell { text-align: center; vertical-align: middle; }

    /* Numbers */
    .incident-number { font-family: monospace; color: #60a5fa; font-size: 0.7rem; }
    .report-number { font-family: monospace; color: #fbbf24; font-size: 0.75rem; }

    /* Info bar - DARK BACKGROUND OVERRIDE */
    .perti-info-bar { margin-bottom: 12px; background-color: #0f172a !important; }
    .perti-info-bar .row { background-color: #0f172a !important; }
    .perti-info-bar .card, .perti-info-bar .perti-info-card { background: #1e293b !important; background-color: #1e293b !important; border: 1px solid #334155 !important; border-radius: 4px; }
    .perti-info-bar .card-body { background: #1e293b !important; }
    .perti-info-bar .col-auto { background-color: transparent !important; }
    .perti-info-label { font-size: 0.65rem; color: #94a3b8; text-transform: uppercase; letter-spacing: 1px; }
    .perti-clock-display { font-family: 'Courier New', monospace; color: #f1f5f9; }
    .perti-clock-display-lg { font-size: 1.3rem; font-weight: 700; }
    .perti-clock-display-md { font-size: 0.8rem; }
    .perti-clock-grid { display: flex; gap: 10px; flex-wrap: wrap; }
    .perti-clock-item { text-align: center; }
    .perti-clock-tz { font-size: 0.6rem; color: #64748b; }
    .perti-stat-grid { display: flex; gap: 8px; }
    .perti-stat-item { text-align: center; }
    .perti-stat-category { font-size: 0.6rem; }
    .perti-stat-value { font-family: monospace; font-size: 0.85rem; }

    /* Layout helpers */
    .main-row { display: flex; gap: 12px; margin-bottom: 0; }
    .left-col { flex: 0 0 200px; display: flex; flex-direction: column; gap: 8px; }
    .middle-col { flex: 1; display: flex; flex-direction: column; }
    .right-col { flex: 0 0 220px; display: flex; flex-direction: column; }
    
    .refresh-indicator { display: inline-flex; align-items: center; gap: 4px; font-size: 0.7rem; color: #94a3b8; }
    .refresh-indicator .dot { width: 6px; height: 6px; border-radius: 50%; background-color: #22c55e; animation: pulse 1s infinite; }
    @keyframes pulse { 0%, 100% { opacity: 1; } 50% { opacity: 0.5; } }

    /* Details modal */
    .details-grid { display: grid; grid-template-columns: 90px 1fr 80px 1fr; gap: 4px 8px; font-size: 0.8rem; background: #1e293b; padding: 10px; border-radius: 4px; }
    .details-grid .label { color: #94a3b8; }
    .details-grid .value { color: #f1f5f9; }
    .action-buttons { display: flex; flex-direction: column; gap: 6px; }
    .action-buttons .btn { font-size: 0.8rem; padding: 6px 10px; }
    .update-form-section { background: #1e293b; padding: 10px; border-radius: 0 0 4px 4px; margin-bottom: 10px; }
    .section-header.ops-change { background-color: #475569; }
    
    /* User profile button */
    .user-profile-btn { cursor: pointer; padding: 4px 10px; background: #334155; border-radius: 4px; font-size: 0.75rem; color: #f1f5f9; border: 1px solid #475569; }
    .user-profile-btn:hover { background: #475569; }
    .user-profile-btn .fa-user { margin-right: 4px; }
    .user-not-set { color: #f87171; }
    
    /* Custom facility input */
    .custom-facility-row { display: none; margin-top: 8px; }
    .custom-facility-row.show { display: flex; gap: 8px; }
    
    /* Organization identifier input */
    .org-identifier-row { display: none; margin-top: 8px; }
    .org-identifier-row.show { display: block; }
    
    /* Local airport input */
    .local-airport-row { display: none; margin-top: 8px; }
    .local-airport-row.show { display: block; }
    </style>
</head>
<body class="perti-dark">
<?php include("load/nav_public.php"); ?>

<section class="perti-hero perti-hero--micro" data-jarallax data-speed="0.3">
    <div class="container-fluid pt-2 pb-2">
        <img class="jarallax-img" src="assets/img/jumbotron/main.png" alt="" style="opacity: 30%;">
        <center><h1 style="color: #f1f5f9; text-shadow: 2px 2px 4px rgba(0,0,0,0.5); margin-bottom: 0;"><?= __('jatoc.page.heroTitle') ?></h1><h5 class="text-white" style="margin-bottom: 0;"><i class="fas fa-broadcast-tower text-warning"></i> <?= __('jatoc.page.heroSubtitle') ?></h5></center>
    </div>
</section>

<div class="container-fluid mt-2 mb-4 px-3" style="background: #0f172a;">
    <!-- Info Bar -->
    <div class="perti-info-bar" style="background: #0f172a;">
        <div class="row d-flex flex-wrap align-items-stretch" style="gap: 6px; margin: 0 -3px; background: #0f172a;">
            <div class="col-auto px-1">
                <div class="card shadow-sm perti-info-card h-100">
                    <div class="card-body d-flex align-items-center py-2 px-3">
                        <div>
                            <div class="perti-info-label"><?= __('jatoc.page.utc') ?></div>
                            <div id="jatoc_utc_clock" class="perti-clock-display perti-clock-display-lg"></div>
                        </div>
                        <i class="far fa-clock fa-lg text-primary ml-3"></i>
                    </div>
                </div>
            </div>
            <div class="col-auto px-1">
                <div class="card shadow-sm perti-info-card h-100">
                    <div class="card-body py-2 px-3">
                        <div class="perti-info-label mb-1"><?= __('jatoc.page.usLocal') ?></div>
                        <div class="perti-clock-grid">
                            <div class="perti-clock-item"><div class="perti-clock-tz">HI</div><div id="jatoc_clock_hi" class="perti-clock-display perti-clock-display-md"></div></div>
                            <div class="perti-clock-item"><div class="perti-clock-tz">AK</div><div id="jatoc_clock_ak" class="perti-clock-display perti-clock-display-md"></div></div>
                            <div class="perti-clock-item"><div class="perti-clock-tz">PT</div><div id="jatoc_clock_pac" class="perti-clock-display perti-clock-display-md"></div></div>
                            <div class="perti-clock-item"><div class="perti-clock-tz">MT</div><div id="jatoc_clock_mtn" class="perti-clock-display perti-clock-display-md"></div></div>
                            <div class="perti-clock-item"><div class="perti-clock-tz">CT</div><div id="jatoc_clock_cent" class="perti-clock-display perti-clock-display-md"></div></div>
                            <div class="perti-clock-item"><div class="perti-clock-tz">ET</div><div id="jatoc_clock_east" class="perti-clock-display perti-clock-display-md"></div></div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-auto px-1">
                <div class="card shadow-sm perti-info-card h-100">
                    <div class="card-body py-2 px-3">
                        <div class="perti-info-label mb-1"><?= __('jatoc.page.activeIncidents') ?></div>
                        <div class="perti-stat-grid">
                            <div class="perti-stat-item"><div class="perti-stat-category" style="color:#f87171">ZERO</div><div id="statsAtcZero" class="perti-stat-value" style="color:#f87171">0</div></div>
                            <div class="perti-stat-item"><div class="perti-stat-category" style="color:#fbbf24">ALERT</div><div id="statsAtcAlert" class="perti-stat-value" style="color:#fbbf24">0</div></div>
                            <div class="perti-stat-item"><div class="perti-stat-category" style="color:#60a5fa">LTD</div><div id="statsAtcLimited" class="perti-stat-value" style="color:#60a5fa">0</div></div>
                            <div class="perti-stat-item"><div class="perti-stat-category" style="color:#4ade80">TTL</div><div id="statsActive" class="perti-stat-value" style="color:#4ade80">0</div></div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-auto px-1">
                <div class="card shadow-sm perti-info-card h-100">
                    <div class="card-body d-flex align-items-center py-2 px-3">
                        <span class="refresh-indicator"><span class="dot"></span> <span id="refreshCountdown">15</span>s</span>
                    </div>
                </div>
            </div>
            <div class="col-auto px-1 ml-auto">
                <div class="card shadow-sm perti-info-card h-100">
                    <div class="card-body d-flex align-items-center py-2 px-3">
                        <span class="user-profile-btn" onclick="JATOC.showUserProfile()" id="userProfileBtn">
                            <i class="fas fa-user"></i> <span id="userDisplayName" class="user-not-set"><?= __('jatoc.page.setProfile') ?></span>
                        </span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="classification-banner"><?= __('jatoc.page.classificationBanner') ?></div>
    
    <!-- Header Bar -->
    <div class="jatoc-header-bar" id="jatocHeaderBar">
        <div class="row align-items-center">
            <div class="col-md-4" id="jatocTitleBlock">
                <div class="jatoc-title"><?= __('jatoc.page.title') ?></div>
                <div class="jatoc-subtitle"><?= __('jatoc.page.subtitle') ?></div>
            </div>
            <div class="col-md-4 text-center">
                <select id="opsLevelSelect" class="ops-level-badge ops-level-1">
                    <option value="1"><?= __('jatoc.page.opsLevel1') ?></option>
                    <option value="2"><?= __('jatoc.page.opsLevel2') ?></option>
                    <option value="3"><?= __('jatoc.page.opsLevel3') ?></option>
                </select>
            </div>
            <div class="col-md-4 text-right">
                <a href="https://vncrcc.org" target="_blank" rel="noopener noreferrer" class="btn btn-sm" title="<?= __('jatoc.page.vncrccTooltip') ?>" style="font-size:0.75rem; background:rgba(0,0,0,0.3); border:1px solid rgba(255,255,255,0.3); color:#fff;">
                    <i class="fas fa-shield-alt"></i> <?= __('jatoc.page.vncrccLabel') ?> <i class="fas fa-external-link-alt" style="font-size:0.6rem; margin-left:2px;"></i>
                </a>
            </div>
        </div>
    </div>
    
    <!-- Main 3-Column Layout -->
    <div class="main-row">
        <!-- Left Column: POTUS, Space, VATUSA -->
        <div class="left-col">
            <div>
                <div class="section-header green"><?= __('jatoc.page.potus') ?> <button class="btn btn-sm btn-link text-white float-right py-0 px-1" onclick="JATOC.editDailyOps('POTUS')" style="font-size:0.7rem"><i class="fas fa-edit"></i></button></div>
                <div class="ops-calendar" id="potusCalendar"><div class="text-muted small p-2"><?= __('common.loading') ?></div></div>
            </div>
            <div>
                <div class="section-header green"><?= __('jatoc.page.space') ?> <button class="btn btn-sm btn-link text-white float-right py-0 px-1" onclick="JATOC.editDailyOps('SPACE')" style="font-size:0.7rem"><i class="fas fa-edit"></i></button></div>
                <div class="ops-calendar" id="spaceCalendar"><div class="text-muted small p-2"><?= __('common.loading') ?></div></div>
            </div>
            <div style="flex:1">
                <div class="section-header green"><?= __('jatoc.page.vatusaEvents') ?></div>
                <div class="vatusa-section" id="vatusaEvents" style="max-height:none; height:calc(100% - 26px);"><div class="text-muted small"><?= __('common.loading') ?></div></div>
            </div>
        </div>
        
        <!-- Middle Column: Map -->
        <div class="middle-col">
            <div class="section-header"><i class="fas fa-map-marked-alt mr-1"></i><?= __('jatoc.page.activeIncidentsMap') ?></div>
            <div id="jatoc-map-container">
                <div id="jatoc-map"></div>
                <div class="jatoc-map-legend">
                    <div class="jatoc-map-legend-item"><div class="jatoc-map-legend-dot" style="background-color: #dc2626;"></div><?= __('jatoc.page.legendAtcZero') ?></div>
                    <div class="jatoc-map-legend-item"><div class="jatoc-map-legend-dot" style="background-color: #f59e0b;"></div><?= __('jatoc.page.legendAtcAlert') ?></div>
                    <div class="jatoc-map-legend-item"><div class="jatoc-map-legend-dot" style="background-color: #3b82f6;"></div><?= __('jatoc.page.legendAtcLimited') ?></div>
                    <div class="jatoc-map-legend-item"><div class="jatoc-map-legend-dot" style="background-color: #8b5cf6;"></div><?= __('jatoc.page.legendNonResponsive') ?></div>
                    <hr style="border-color: #4b5563; margin: 4px 0;">
                    <div style="font-size: 0.55rem; color: #9ca3af;"><?= __('jatoc.page.nexradDbz') ?></div>
                    <div style="display: flex; gap: 1px;">
                        <div style="width: 12px; height: 8px; background: #02fd02;" title="20"></div>
                        <div style="width: 12px; height: 8px; background: #fdf802;" title="35"></div>
                        <div style="width: 12px; height: 8px; background: #fd9500;" title="45"></div>
                        <div style="width: 12px; height: 8px; background: #fd0000;" title="50"></div>
                        <div style="width: 12px; height: 8px; background: #f800fd;" title="65"></div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Right Column: Personnel -->
        <div class="right-col">
            <div class="section-header"><i class="fas fa-users mr-1"></i><?= __('jatoc.page.personnelOnPosition') ?></div>
            <div style="background:#1e293b; border-radius: 0 0 4px 4px; flex:1; overflow-y: auto;">
                <table class="table table-sm table-dark mb-0 personnel-table">
                    <thead><tr><th style="width:55px; color:#94a3b8"><?= __('jatoc.page.colElem') ?></th><th style="width:35px; color:#94a3b8"><?= __('jatoc.page.colOis') ?></th><th style="color:#94a3b8"><?= __('jatoc.page.colName') ?></th></tr></thead>
                    <tbody id="personnelTableBody"><tr><td colspan="3" class="text-center text-muted py-2"><?= __('common.loading') ?></td></tr></tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Full Width Filter & Events Table -->
    <div class="filter-bar">
        <div class="row align-items-end">
            <div class="col"><label class="small mb-0"><?= __('jatoc.page.lifecycle') ?></label><select id="filterStatus" class="form-control form-control-sm form-control-dark"><option value=""><?= __('jatoc.page.all') ?></option><option value="ACTIVE" selected><?= __('jatoc.page.active') ?></option><option value="PENDING"><?= __('jatoc.page.pending') ?></option><option value="MONITORING"><?= __('jatoc.page.monitoring') ?></option><option value="ESCALATED"><?= __('jatoc.page.escalated') ?></option><option value="CLOSED"><?= __('jatoc.page.closed') ?></option></select></div>
            <div class="col"><label class="small mb-0"><?= __('jatoc.page.facType') ?></label><select id="filterFacilityType" class="form-control form-control-sm form-control-dark"><option value=""><?= __('jatoc.page.all') ?></option><option value="ARTCC">ARTCC</option><option value="TRACON">TRACON</option><option value="ATCT">ATCT</option></select></div>
            <div class="col"><label class="small mb-0"><?= __('jatoc.page.incType') ?></label><select id="filterIncidentType" class="form-control form-control-sm form-control-dark"><option value=""><?= __('jatoc.page.all') ?></option><option value="ATC_ZERO"><?= __('jatoc.page.atcZero') ?></option><option value="ATC_ALERT"><?= __('jatoc.page.atcAlert') ?></option><option value="ATC_LIMITED"><?= __('jatoc.page.atcLimited') ?></option><option value="NON_RESPONSIVE"><?= __('jatoc.page.nonResponsive') ?></option></select></div>
            <div class="col"><label class="small mb-0"><?= __('jatoc.page.facility') ?></label><input type="text" id="filterFacility" class="form-control form-control-sm form-control-dark" placeholder="ZTL"></div>
            <div class="col-auto"><button class="btn btn-sm btn-jatoc" onclick="JATOC.applyFilters()" style="height:31px"><i class="fas fa-filter"></i> <?= __('jatoc.page.filter') ?></button></div>
            <div class="col-auto"><button class="btn btn-sm btn-outline-info" onclick="JATOC.showRetrieveModal()" style="height:31px"><i class="fas fa-search"></i> <?= __('jatoc.page.retrieve') ?></button></div>
            <div class="col-auto"><button class="btn btn-sm btn-jatoc-success" onclick="JATOC.showCreateModal()" style="height:31px"><i class="fas fa-plus"></i> <?= __('jatoc.page.new') ?></button></div>
        </div>
    </div>

    <div class="section-header"><?= __('jatoc.page.events') ?></div>
    <div class="table-responsive" style="background:#1e293b; border-radius: 0 0 4px 4px;">
        <table class="events-table" id="eventsTable">
            <thead><tr>
                <th data-sort="incident_number"><?= __('jatoc.page.colIncNum') ?> <i class="fas fa-sort sort-icon"></i></th>
                <th data-sort="facility"><?= __('jatoc.page.colFacility') ?> <i class="fas fa-sort sort-icon"></i></th>
                <th data-sort="incident_type"><?= __('jatoc.page.colIncType') ?> <i class="fas fa-sort sort-icon"></i></th>
                <th data-sort="trigger_code"><?= __('jatoc.page.colTrigger') ?> <i class="fas fa-sort sort-icon"></i></th>
                <th data-sort="paged"><?= __('jatoc.page.colPaged') ?> <i class="fas fa-sort sort-icon"></i></th>
                <th data-sort="start_utc"><?= __('jatoc.page.colStart') ?> <i class="fas fa-sort sort-icon"></i></th>
                <th style="width:180px; text-align:center; cursor:default"><?= __('jatoc.page.colQuickActions') ?></th>
            </tr></thead>
        <tbody id="eventsTableBody"><tr><td colspan="7" class="text-center text-muted py-4"><?= __('common.loading') ?></td></tr></tbody></table>
    </div>
</div>

<!-- User Profile Modal -->
<div class="modal fade modal-jatoc" id="userProfileModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header"><h5 class="modal-title"><i class="fas fa-user mr-2"></i><?= __('jatoc.page.userProfile') ?></h5><button type="button" class="close text-white" data-dismiss="modal">&times;</button></div>
            <div class="modal-body">
                <div class="row">
                    <div class="col-md-8">
                        <label class="small text-muted"><?= __('jatoc.page.profileName') ?></label>
                        <input type="text" id="profileName" class="form-control form-control-dark" placeholder="<?= __('jatoc.page.profileNamePlaceholder') ?>" maxlength="100">
                    </div>
                    <div class="col-md-4">
                        <label class="small text-muted"><?= __('jatoc.page.profileCid') ?></label>
                        <input type="text" id="profileCID" class="form-control form-control-dark" placeholder="<?= __('jatoc.page.profileCidPlaceholder') ?>" maxlength="10">
                    </div>
                </div>
                <div class="row mt-2">
                    <div class="col-md-4">
                        <label class="small text-muted"><?= __('jatoc.page.profileOis') ?></label>
                        <input type="text" id="profileOIs" class="form-control form-control-dark" placeholder="HP" maxlength="2" style="text-transform:uppercase">
                    </div>
                    <div class="col-md-8">
                        <label class="small text-muted"><?= __('jatoc.page.profileFacilityOrg') ?></label>
                        <select id="profileFacilityType" class="form-control form-control-dark" onchange="JATOC.onFacilityTypeChange()">
                            <option value=""><?= __('jatoc.page.selectType') ?></option>
                            <optgroup label="<?= __('jatoc.page.atcFacilities') ?>">
                                <option value="ARTCC">ARTCC</option>
                                <option value="TRACON">TRACON</option>
                                <option value="LOCAL"><?= __('jatoc.page.local') ?></option>
                                <option value="FIR"><?= __('jatoc.page.firInternational') ?></option>
                            </optgroup>
                            <optgroup label="<?= __('jatoc.page.organizations') ?>">
                                <option value="DCC"><?= __('jatoc.page.dccCommandCenter') ?></option>
                                <option value="CANOC">CANOC</option>
                                <option value="ECFMP">ECFMP</option>
                                <option value="CTP"><?= __('jatoc.page.ctpCrossThePond') ?></option>
                                <option value="WF"><?= __('jatoc.page.worldFlight') ?></option>
                                <option value="VATUSA">VATUSA</option>
                                <option value="VATCAN">VATCAN</option>
                                <option value="VATSIM">VATSIM</option>
                            </optgroup>
                            <optgroup label="<?= __('jatoc.page.custom') ?>">
                                <option value="VA"><?= __('jatoc.page.virtualAirline') ?></option>
                                <option value="VSO"><?= __('jatoc.page.virtualSpecialOps') ?></option>
                                <option value="APT_AUTH"><?= __('jatoc.page.airportAuthority') ?></option>
                                <option value="OTHER"><?= __('jatoc.page.other') ?></option>
                            </optgroup>
                        </select>
                    </div>
                </div>
                <div class="row mt-2" id="facilitySelectRow">
                    <div class="col-12">
                        <label class="small text-muted"><?= __('jatoc.page.selectFacility') ?></label>
                        <select id="profileFacility" class="form-control form-control-dark">
                            <option value=""><?= __('jatoc.page.selectFacilityFirst') ?></option>
                        </select>
                    </div>
                </div>
                <!-- Organization identifier input (for VATUSA/VATSIM) -->
                <div class="org-identifier-row" id="orgIdentifierRow">
                    <label class="small text-muted"><?= __('jatoc.page.identifier') ?></label>
                    <input type="text" id="orgIdentifier" class="form-control form-control-dark form-control-sm" placeholder="<?= __('jatoc.page.identifierPlaceholder') ?>" maxlength="20" style="text-transform:uppercase">
                    <small class="text-muted"><?= __('jatoc.page.identifierHint') ?></small>
                </div>
                <!-- Local airport input -->
                <div class="local-airport-row" id="localAirportRow">
                    <label class="small text-muted"><?= __('jatoc.page.airportIcao') ?></label>
                    <input type="text" id="localAirportIcao" class="form-control form-control-dark form-control-sm" placeholder="<?= __('jatoc.page.airportIcaoPlaceholder') ?>" maxlength="4" style="text-transform:uppercase">
                    <small class="text-muted"><?= __('jatoc.page.airportIcaoHint') ?></small>
                </div>
                <div class="custom-facility-row" id="customFacilityRow">
                    <div style="flex:1">
                        <label class="small text-muted"><?= __('jatoc.page.customName') ?></label>
                        <input type="text" id="customFacilityName" class="form-control form-control-dark form-control-sm" placeholder="<?= __('jatoc.page.customNamePlaceholder') ?>">
                    </div>
                    <div style="width:100px">
                        <label class="small text-muted"><?= __('jatoc.page.customCode') ?></label>
                        <input type="text" id="customFacilityCode" class="form-control form-control-dark form-control-sm" placeholder="<?= __('jatoc.page.customCodePlaceholder') ?>" maxlength="10" style="text-transform:uppercase">
                    </div>
                </div>
                <div class="row mt-2">
                    <div class="col-12">
                        <label class="small text-muted"><?= __('jatoc.page.role') ?></label>
                        <select id="profileRole" class="form-control form-control-dark">
                            <option value=""><?= __('jatoc.page.selectFacilityFirst') ?></option>
                        </select>
                    </div>
                </div>
                <hr class="border-secondary my-3">
                <div class="small text-muted">
                    <strong><?= __('jatoc.page.preview') ?></strong> <span id="profilePreview" class="text-info">-</span>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-danger btn-sm mr-auto" onclick="JATOC.clearProfile()"><?= __('jatoc.page.clear') ?></button>
                <button type="button" class="btn btn-secondary btn-sm" data-dismiss="modal"><?= __('jatoc.page.cancel') ?></button>
                <button type="button" class="btn btn-jatoc btn-sm" onclick="JATOC.saveProfile()"><?= __('jatoc.page.save') ?></button>
            </div>
        </div>
    </div>
</div>

<!-- Incident Create/Edit Modal -->
<div class="modal fade modal-jatoc" id="incidentModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header"><h5 class="modal-title" id="incidentModalTitle"><?= __('jatoc.page.newIncident') ?></h5><button type="button" class="close text-white" data-dismiss="modal">&times;</button></div>
            <div class="modal-body">
                <form id="incidentForm">
                    <input type="hidden" id="incidentId">
                    <div class="row">
                        <div class="col-md-4"><label class="small text-muted"><?= __('jatoc.page.incFacility') ?></label><input type="text" id="incidentFacility" class="form-control form-control-dark" placeholder="<?= __('jatoc.page.incFacilityPlaceholder') ?>" required></div>
                        <div class="col-md-4"><label class="small text-muted"><?= __('jatoc.page.incType2') ?></label><select id="incidentFacilityType" class="form-control form-control-dark"><option value=""><?= __('jatoc.page.incSelect') ?></option><option value="ARTCC">ARTCC</option><option value="TRACON">TRACON</option><option value="ATCT">ATCT</option></select></div>
                        <div class="col-md-4"><label class="small text-muted"><?= __('jatoc.page.incidentType') ?></label><select id="incidentStatus" class="form-control form-control-dark" required><option value="ATC_ZERO"><?= __('jatoc.page.atcZero') ?></option><option value="ATC_ALERT"><?= __('jatoc.page.atcAlert') ?></option><option value="ATC_LIMITED"><?= __('jatoc.page.atcLimited') ?></option><option value="NON_RESPONSIVE"><?= __('jatoc.page.nonResponsive') ?></option><option value="OTHER"><?= __('jatoc.page.other') ?></option></select></div>
                    </div>
                    <div class="row mt-2">
                        <div class="col-md-4"><label class="small text-muted"><?= __('jatoc.page.trigger') ?></label><select id="incidentTrigger" class="form-control form-control-dark"><option value=""><?= __('jatoc.page.incSelect') ?></option><option value="A"><?= __('jatoc.page.triggerA') ?></option><option value="B"><?= __('jatoc.page.triggerB') ?></option><option value="D"><?= __('jatoc.page.triggerD') ?></option><option value="E"><?= __('jatoc.page.triggerE') ?></option><option value="F"><?= __('jatoc.page.triggerF') ?></option><option value="H"><?= __('jatoc.page.triggerH') ?></option><option value="J"><?= __('jatoc.page.triggerJ') ?></option><option value="K"><?= __('jatoc.page.triggerK') ?></option><option value="M"><?= __('jatoc.page.triggerM') ?></option><option value="Q"><?= __('jatoc.page.triggerQ') ?></option><option value="R"><?= __('jatoc.page.triggerR') ?></option><option value="S"><?= __('jatoc.page.triggerS') ?></option><option value="T"><?= __('jatoc.page.triggerT') ?></option><option value="U"><?= __('jatoc.page.triggerU') ?></option><option value="V"><?= __('jatoc.page.triggerV') ?></option><option value="W"><?= __('jatoc.page.triggerW') ?></option></select></div>
                        <div class="col-md-4"><label class="small text-muted"><?= __('jatoc.page.paged') ?></label><select id="incidentPaged" class="form-control form-control-dark"><option value="0"><?= __('common.no') ?></option><option value="1"><?= __('common.yes') ?></option></select></div>
                        <div class="col-md-4"><label class="small text-muted"><?= __('jatoc.page.lifecycleLabel') ?></label><select id="incidentIncidentStatus" class="form-control form-control-dark"><option value="PENDING"><?= __('jatoc.page.pending') ?></option><option value="ACTIVE"><?= __('jatoc.page.active') ?></option><option value="MONITORING"><?= __('jatoc.page.monitoring') ?></option><option value="ESCALATED"><?= __('jatoc.page.escalated') ?></option><option value="CLOSED"><?= __('jatoc.page.closed') ?></option></select></div>
                    </div>
                    <div class="row mt-2">
                        <div class="col-md-6"><label class="small text-muted"><?= __('jatoc.page.startUtc') ?></label><input type="datetime-local" id="incidentStartUtc" class="form-control form-control-dark" required></div>
                    </div>
                    <div class="mt-2"><label class="small text-muted"><?= __('jatoc.page.remarks') ?></label><textarea id="incidentRemarks" class="form-control form-control-dark" rows="2" placeholder="<?= __('jatoc.page.remarksPlaceholder') ?>" style="font-family: 'Courier New', monospace;"></textarea></div>
                </form>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-secondary btn-sm" data-dismiss="modal"><?= __('jatoc.page.cancel') ?></button><button type="button" class="btn btn-jatoc btn-sm" onclick="JATOC.saveIncident()"><?= __('jatoc.page.save') ?></button></div>
        </div>
    </div>
</div>

<!-- Incident Details Modal -->
<div class="modal fade modal-jatoc" id="incidentDetailsModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header py-2"><h5 class="modal-title"><?= __('jatoc.page.incident') ?> <span id="detailsIncNum" class="incident-number ml-2">-</span></h5><button type="button" class="close text-white" data-dismiss="modal">&times;</button></div>
            <div class="modal-body py-2">
                <div class="row">
                    <div class="col-lg-8">
                        <div class="details-grid mb-2">
                            <div class="label"><?= __('jatoc.page.detailsFacility') ?></div><div class="value" id="detailsFacility">-</div>
                            <div class="label"><?= __('jatoc.page.detailsIncType') ?></div><div class="value" id="detailsStatus">-</div>
                            <div class="label"><?= __('jatoc.page.detailsTrigger') ?></div><div class="value" id="detailsTrigger">-</div>
                            <div class="label"><?= __('jatoc.page.detailsPaged') ?></div><div class="value" id="detailsPaged">-</div>
                            <div class="label"><?= __('jatoc.page.detailsStart') ?></div><div class="value" id="detailsStartTime">-</div>
                            <div class="label"><?= __('jatoc.page.detailsDuration') ?></div><div class="value" id="detailsDuration">-</div>
                            <div class="label"><?= __('jatoc.page.detailsCreatedBy') ?></div><div class="value" id="detailsCreatedBy">-</div>
                            <div class="label"><?= __('jatoc.page.detailsReportNum') ?></div><div class="value" id="detailsReportNum">-</div>
                        </div>
                        <div class="section-header slate" style="font-size:0.7rem"><?= __('jatoc.page.remarksHeader') ?></div>
                        <div id="detailsRemarks" class="mb-2">-</div>
                        <div class="section-header slate" style="font-size:0.7rem"><?= __('jatoc.page.updateHistory') ?></div>
                        <div id="detailsUpdates" style="max-height: 200px; overflow-y: auto; background:#0f172a; border-radius:0 0 4px 4px; padding:6px;"><div class="text-muted text-center py-2"><?= __('common.loading') ?></div></div>
                    </div>
                    <div class="col-lg-4">
                        <div class="section-header indigo" style="font-size:0.7rem"><?= __('jatoc.page.addUpdate') ?></div>
                        <div class="update-form-section">
                            <input type="hidden" id="updateIncidentId">
                            <select id="updateType" class="form-control form-control-dark form-control-sm mb-2">
                                <option value="REMARK"><?= __('jatoc.page.updateTypeRemark') ?></option>
                                <option value="STATUS_CHANGE"><?= __('jatoc.page.updateTypeStatusChange') ?></option>
                                <option value="ESCALATION"><?= __('jatoc.page.updateTypeEscalation') ?></option>
                                <option value="RESOLUTION"><?= __('jatoc.page.updateTypeResolution') ?></option>
                                <option value="COORDINATION"><?= __('jatoc.page.updateTypeCoordination') ?></option>
                            </select>
                            <textarea id="updateRemarks" class="form-control form-control-dark form-control-sm mb-2" rows="2" placeholder="<?= __('jatoc.page.updatePlaceholder') ?>" style="font-family: 'Courier New', monospace;"></textarea>
                            <button class="btn btn-block btn-jatoc btn-sm" onclick="JATOC.addUpdate()"><?= __('jatoc.page.addUpdateBtn') ?></button>
                        </div>
                        <div class="section-header ops-change" style="font-size:0.7rem"><?= __('jatoc.page.changeOpsLevel') ?></div>
                        <div class="update-form-section">
                            <select id="modalOpsLevel" class="form-control form-control-dark form-control-sm mb-2">
                                <option value="1"><?= __('jatoc.page.level1Steady') ?></option>
                                <option value="2"><?= __('jatoc.page.level2Escalated') ?></option>
                                <option value="3"><?= __('jatoc.page.level3Major') ?></option>
                            </select>
                            <input type="text" id="modalOpsReason" class="form-control form-control-dark form-control-sm mb-2" placeholder="<?= __('jatoc.page.reasonPlaceholder') ?>">
                            <button class="btn btn-block btn-secondary btn-sm" onclick="JATOC.changeOpsLevel()"><?= __('jatoc.page.changeLevelBtn') ?></button>
                        </div>
                        <div class="action-buttons mt-2">
                            <button class="btn btn-outline-warning btn-sm" onclick="JATOC.markPaged()"><i class="fas fa-bell"></i> <?= __('jatoc.page.markPaged') ?></button>
                            <button class="btn btn-outline-info btn-sm" onclick="JATOC.editFromDetails()"><i class="fas fa-edit"></i> <?= __('jatoc.page.editIncident') ?></button>
                            <button class="btn btn-outline-light btn-sm" onclick="JATOC.generateReport()"><i class="fas fa-file-alt"></i> <?= __('jatoc.page.generateReport') ?></button>
                            <button class="btn btn-outline-primary btn-sm" onclick="JATOC.viewReport()" id="btnViewReport" style="display:none"><i class="fas fa-file-invoice"></i> <?= __('jatoc.page.viewReport') ?></button>
                            <button class="btn btn-outline-success btn-sm" onclick="JATOC.closeoutIncident()" id="btnCloseOut"><i class="fas fa-check"></i> <?= __('jatoc.page.closeOut') ?></button>
                            <button class="btn btn-outline-info btn-sm" onclick="JATOC.reopenIncident()" id="btnReopen" style="display:none"><i class="fas fa-redo"></i> <?= __('jatoc.page.reopen') ?></button>
                            <button class="btn btn-outline-danger btn-sm" onclick="JATOC.deleteIncident()"><i class="fas fa-trash"></i> <?= __('jatoc.page.delete') ?></button>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer py-1"><button type="button" class="btn btn-secondary btn-sm" data-dismiss="modal"><?= __('jatoc.page.close') ?></button></div>
        </div>
    </div>
</div>

<!-- Retrieve Incident Modal -->
<div class="modal fade modal-jatoc" id="retrieveModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header py-2"><h5 class="modal-title"><i class="fas fa-search mr-2"></i><?= __('jatoc.page.retrieveIncident') ?></h5><button type="button" class="close text-white" data-dismiss="modal">&times;</button></div>
            <div class="modal-body py-2">
                <div class="row mb-3">
                    <div class="col-md-4">
                        <label class="small text-muted"><?= __('jatoc.page.incidentNum') ?></label>
                        <input type="text" id="retrieveIncNum" class="form-control form-control-dark form-control-sm" placeholder="<?= __('jatoc.page.incidentNumPlaceholder') ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="small text-muted"><?= __('jatoc.page.reportNum') ?></label>
                        <input type="text" id="retrieveReportNum" class="form-control form-control-dark form-control-sm" placeholder="<?= __('jatoc.page.reportNumPlaceholder') ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="small text-muted"><?= __('jatoc.page.facility') ?></label>
                        <input type="text" id="retrieveFacility" class="form-control form-control-dark form-control-sm" placeholder="<?= __('jatoc.page.facilityPlaceholder') ?>">
                    </div>
                </div>
                <div class="row mb-3">
                    <div class="col-md-3">
                        <label class="small text-muted"><?= __('jatoc.page.lifecycleLabel') ?></label>
                        <select id="retrieveStatus" class="form-control form-control-dark form-control-sm">
                            <option value=""><?= __('jatoc.page.all') ?></option>
                            <option value="PENDING"><?= __('jatoc.page.pending') ?></option>
                            <option value="ACTIVE"><?= __('jatoc.page.active') ?></option>
                            <option value="MONITORING"><?= __('jatoc.page.monitoring') ?></option>
                            <option value="ESCALATED"><?= __('jatoc.page.escalated') ?></option>
                            <option value="CLOSED"><?= __('jatoc.page.closed') ?></option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="small text-muted"><?= __('jatoc.page.incType') ?></label>
                        <select id="retrieveIncType" class="form-control form-control-dark form-control-sm">
                            <option value=""><?= __('jatoc.page.all') ?></option>
                            <option value="ATC_ZERO"><?= __('jatoc.page.atcZero') ?></option>
                            <option value="ATC_ALERT"><?= __('jatoc.page.atcAlert') ?></option>
                            <option value="ATC_LIMITED"><?= __('jatoc.page.atcLimited') ?></option>
                            <option value="NON_RESPONSIVE"><?= __('jatoc.page.nonResponsive') ?></option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="small text-muted"><?= __('jatoc.page.fromDate') ?></label>
                        <input type="date" id="retrieveFromDate" class="form-control form-control-dark form-control-sm">
                    </div>
                    <div class="col-md-3">
                        <label class="small text-muted"><?= __('jatoc.page.toDate') ?></label>
                        <input type="date" id="retrieveToDate" class="form-control form-control-dark form-control-sm">
                    </div>
                </div>
                <div class="row mb-3">
                    <div class="col-12">
                        <button class="btn btn-jatoc btn-sm" onclick="JATOC.searchIncidents()"><i class="fas fa-search"></i> <?= __('jatoc.page.search') ?></button>
                        <button class="btn btn-outline-secondary btn-sm ml-2" onclick="JATOC.clearRetrieveForm()"><i class="fas fa-times"></i> <?= __('jatoc.page.clear') ?></button>
                    </div>
                </div>
                <div class="section-header slate" style="font-size:0.7rem"><?= __('jatoc.page.searchResults') ?></div>
                <div id="retrieveResults" style="max-height: 300px; overflow-y: auto; background:#0f172a; border-radius:0 0 4px 4px; padding:6px;">
                    <div class="text-muted text-center py-3"><?= __('jatoc.page.enterCriteria') ?></div>
                </div>
            </div>
            <div class="modal-footer py-1"><button type="button" class="btn btn-secondary btn-sm" data-dismiss="modal"><?= __('jatoc.page.close') ?></button></div>
        </div>
    </div>
</div>

<!-- Daily Ops Edit Modal -->
<div class="modal fade modal-jatoc" id="dailyOpsModal" tabindex="-1">
    <div class="modal-dialog"><div class="modal-content">
        <div class="modal-header py-2"><h5 class="modal-title"><?= __('jatoc.page.editDailyOps') ?> <span id="dailyOpsTypeLabel">-</span></h5><button type="button" class="close text-white" data-dismiss="modal">&times;</button></div>
        <div class="modal-body py-2">
            <input type="hidden" id="dailyOpsType">
            <textarea id="dailyOpsContent" class="form-control form-control-dark" rows="5" placeholder="<?= __('jatoc.page.dailyOpsPlaceholder') ?>"></textarea>
        </div>
        <div class="modal-footer py-1"><button type="button" class="btn btn-secondary btn-sm" data-dismiss="modal"><?= __('jatoc.page.cancel') ?></button><button type="button" class="btn btn-jatoc btn-sm" onclick="JATOC.saveDailyOps()"><?= __('jatoc.page.save') ?></button></div>
    </div></div>
</div>

<!-- Personnel Edit Modal -->
<div class="modal fade modal-jatoc" id="personnelModal" tabindex="-1">
    <div class="modal-dialog modal-sm"><div class="modal-content">
        <div class="modal-header py-2"><h5 class="modal-title"><?= __('jatoc.page.editPersonnel') ?> <span id="personnelElement">-</span></h5><button type="button" class="close text-white" data-dismiss="modal">&times;</button></div>
        <div class="modal-body py-2">
            <input type="hidden" id="personnelElementInput">
            <div class="row"><div class="col-4"><label class="small text-muted"><?= __('jatoc.page.personnelInit') ?></label><input type="text" id="personnelInitials" class="form-control form-control-dark form-control-sm" maxlength="4"></div>
            <div class="col-8"><label class="small text-muted"><?= __('jatoc.page.personnelName') ?></label><input type="text" id="personnelName" class="form-control form-control-dark form-control-sm"></div></div>
        </div>
        <div class="modal-footer py-1"><button type="button" class="btn btn-outline-danger btn-sm mr-auto" onclick="JATOC.clearPersonnel()"><?= __('jatoc.page.clear') ?></button><button type="button" class="btn btn-secondary btn-sm" data-dismiss="modal"><?= __('jatoc.page.cancel') ?></button><button type="button" class="btn btn-jatoc btn-sm" onclick="JATOC.savePersonnel()"><?= __('jatoc.page.save') ?></button></div>
    </div></div>
</div>

<script>
window.JATOC_CONFIG = { 
    sessionUserName: '<?= addslashes($user_name) ?>', 
    sessionUserCid: '<?= $user_cid ?>',
    isLoggedIn: <?= $logged_in ? 'true' : 'false' ?>
};
</script>
<script src="assets/js/config/facility-roles.js<?= _v('assets/js/config/facility-roles.js') ?>"></script>
<script>
window.JATOC_FACILITY_DATA = window.PERTI_FACILITY_DATA;
</script>
<script src="assets/js/jatoc.js<?= _v('assets/js/jatoc.js') ?>"></script>
<script src="assets/js/jatoc-facility-patch.js<?= _v('assets/js/jatoc-facility-patch.js') ?>"></script>

<?php include("load/footer.php"); ?>
</body>
</html>
