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
    <?php $page_title = "vATCSCC JATOC"; include("load/header.php"); ?>
    <link href="https://unpkg.com/maplibre-gl@4.5.0/dist/maplibre-gl.css" rel="stylesheet" />
    <link rel="stylesheet" href="assets/css/info-bar.css">
    <script src="https://unpkg.com/maplibre-gl@4.5.0/dist/maplibre-gl.js"></script>
    <style>
    /* Dark mode base */
    body { background-color: #0f172a !important; }
    .container-fluid { background-color: transparent; }
    section { background-color: #0f172a !important; }
    .min-vh-25 { background-color: #0f172a !important; }

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
<body>
<?php include("load/nav_public.php"); ?>

<section class="d-flex align-items-center position-relative" style="background: #0f172a; min-height: 80px; margin-top: 60px;" data-jarallax data-speed="0.3">
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
            <div class="col-md-4"></div>
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
                                <option value="ECFMP"><?= __('jatoc.page.ecfmpEurope') ?></option>
                                <option value="CTP"><?= __('jatoc.page.ctpCrossThePond') ?></option>
                                <option value="WF"><?= __('jatoc.page.worldFlight') ?></option>
                                <option value="VATUSA">VATUSA</option>
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

// Facility data for JATOC profile
window.JATOC_FACILITY_DATA = {
    // ARTCC facilities (US Centers)
    ARTCC: [
        { code: 'ZAB', name: 'Albuquerque ARTCC' },
        { code: 'ZAU', name: 'Chicago ARTCC' },
        { code: 'ZBW', name: 'Boston ARTCC' },
        { code: 'ZDC', name: 'Washington ARTCC' },
        { code: 'ZDV', name: 'Denver ARTCC' },
        { code: 'ZFW', name: 'Fort Worth ARTCC' },
        { code: 'ZHU', name: 'Houston ARTCC' },
        { code: 'ZID', name: 'Indianapolis ARTCC' },
        { code: 'ZJX', name: 'Jacksonville ARTCC' },
        { code: 'ZKC', name: 'Kansas City ARTCC' },
        { code: 'ZLA', name: 'Los Angeles ARTCC' },
        { code: 'ZLC', name: 'Salt Lake City ARTCC' },
        { code: 'ZMA', name: 'Miami ARTCC' },
        { code: 'ZME', name: 'Memphis ARTCC' },
        { code: 'ZMP', name: 'Minneapolis ARTCC' },
        { code: 'ZNY', name: 'New York ARTCC' },
        { code: 'ZOA', name: 'Oakland ARTCC' },
        { code: 'ZOB', name: 'Cleveland ARTCC' },
        { code: 'ZSE', name: 'Seattle ARTCC' },
        { code: 'ZTL', name: 'Atlanta ARTCC' },
        { code: 'ZAN', name: 'Anchorage ARTCC' },
        { code: 'ZHN', name: 'Honolulu Control Facility' }
    ],
    
    // TRACON facilities - FAA official list (ATCT/TRACON + Standalone)
    // Source: https://www.faa.gov/about/office_org/headquarters_offices/ato/service_units/air_traffic_services/tracon
    TRACON: [
        // Standalone TRACONs
        { code: 'A11', name: 'Anchorage TRACON' },
        { code: 'A80', name: 'Atlanta TRACON' },
        { code: 'A90', name: 'Boston TRACON' },
        { code: 'C90', name: 'Chicago TRACON' },
        { code: 'D01', name: 'Denver TRACON' },
        { code: 'D10', name: 'Dallas-Fort Worth TRACON' },
        { code: 'D21', name: 'Detroit TRACON' },
        { code: 'F11', name: 'Central Florida TRACON' },
        { code: 'I90', name: 'Houston TRACON' },
        { code: 'L30', name: 'Las Vegas TRACON' },
        { code: 'M03', name: 'Memphis TRACON' },
        { code: 'M98', name: 'Minneapolis TRACON' },
        { code: 'N90', name: 'New York TRACON' },
        { code: 'NCT', name: 'Northern California TRACON' },
        { code: 'NMM', name: 'Meridian TRACON' },
        { code: 'P31', name: 'Pensacola TRACON' },
        { code: 'P50', name: 'Phoenix TRACON' },
        { code: 'P80', name: 'Portland TRACON' },
        { code: 'PCT', name: 'Potomac TRACON' },
        { code: 'R90', name: 'Omaha TRACON' },
        { code: 'S46', name: 'Seattle TRACON' },
        { code: 'S56', name: 'Salt Lake City TRACON' },
        { code: 'SCT', name: 'Southern California TRACON' },
        { code: 'T75', name: 'St. Louis TRACON' },
        { code: 'U90', name: 'Tucson TRACON' },
        { code: 'Y90', name: 'Yankee TRACON' },
        // Combined ATCT/TRACON facilities
        { code: 'ABE', name: 'Allentown ATCT/TRACON' },
        { code: 'ABI', name: 'Abilene ATCT/TRACON' },
        { code: 'ABQ', name: 'Albuquerque ATCT/TRACON' },
        { code: 'ACT', name: 'Waco ATCT/TRACON' },
        { code: 'ACY', name: 'Atlantic City ATCT/TRACON' },
        { code: 'AGS', name: 'Augusta ATCT/TRACON' },
        { code: 'ALB', name: 'Albany ATCT/TRACON' },
        { code: 'ALO', name: 'Waterloo ATCT/TRACON' },
        { code: 'AMA', name: 'Amarillo ATCT/TRACON' },
        { code: 'ASE', name: 'Aspen ATCT/TRACON' },
        { code: 'AUS', name: 'Austin ATCT/TRACON' },
        { code: 'AVL', name: 'Asheville ATCT/TRACON' },
        { code: 'AVP', name: 'Wilkes-Barre ATCT/TRACON' },
        { code: 'AZO', name: 'Kalamazoo ATCT/TRACON' },
        { code: 'BFL', name: 'Bakersfield ATCT/TRACON' },
        { code: 'BGM', name: 'Binghamton ATCT/TRACON' },
        { code: 'BGR', name: 'Bangor ATCT/TRACON' },
        { code: 'BHM', name: 'Birmingham ATCT/TRACON' },
        { code: 'BIL', name: 'Billings ATCT/TRACON' },
        { code: 'BIS', name: 'Bismarck ATCT/TRACON' },
        { code: 'BNA', name: 'Nashville ATCT/TRACON' },
        { code: 'BOI', name: 'Boise ATCT/TRACON' },
        { code: 'BTR', name: 'Baton Rouge ATCT/TRACON' },
        { code: 'BTV', name: 'Burlington ATCT/TRACON' },
        { code: 'BUF', name: 'Buffalo ATCT/TRACON' },
        { code: 'CAE', name: 'Columbia ATCT/TRACON' },
        { code: 'CAK', name: 'Akron-Canton ATCT/TRACON' },
        { code: 'CHA', name: 'Chattanooga ATCT/TRACON' },
        { code: 'CHS', name: 'Charleston ATCT/TRACON' },
        { code: 'CID', name: 'Cedar Rapids ATCT/TRACON' },
        { code: 'CKB', name: 'Clarksburg ATCT/TRACON' },
        { code: 'CLE', name: 'Cleveland ATCT/TRACON' },
        { code: 'CLT', name: 'Charlotte ATCT/TRACON' },
        { code: 'CMH', name: 'Columbus ATCT/TRACON' },
        { code: 'CMI', name: 'Champaign ATCT/TRACON' },
        { code: 'COS', name: 'Colorado Springs ATCT/TRACON' },
        { code: 'CPR', name: 'Casper ATCT/TRACON' },
        { code: 'CRP', name: 'Corpus Christi ATCT/TRACON' },
        { code: 'CRW', name: 'Charleston WV ATCT/TRACON' },
        { code: 'CVG', name: 'Cincinnati ATCT/TRACON' },
        { code: 'DAB', name: 'Daytona Beach ATCT/TRACON' },
        { code: 'DAY', name: 'Dayton ATCT/TRACON' },
        { code: 'DLH', name: 'Duluth ATCT/TRACON' },
        { code: 'DSM', name: 'Des Moines ATCT/TRACON' },
        { code: 'ELM', name: 'Elmira ATCT/TRACON' },
        { code: 'ELP', name: 'El Paso ATCT/TRACON' },
        { code: 'ERI', name: 'Erie ATCT/TRACON' },
        { code: 'EUG', name: 'Eugene ATCT/TRACON' },
        { code: 'EVV', name: 'Evansville ATCT/TRACON' },
        { code: 'FAI', name: 'Fairbanks ATCT/TRACON' },
        { code: 'FAR', name: 'Fargo ATCT/TRACON' },
        { code: 'FAT', name: 'Fresno ATCT/TRACON' },
        { code: 'FAY', name: 'Fayetteville ATCT/TRACON' },
        { code: 'FLO', name: 'Florence ATCT/TRACON' },
        { code: 'FNT', name: 'Flint ATCT/TRACON' },
        { code: 'FSD', name: 'Sioux Falls ATCT/TRACON' },
        { code: 'FSM', name: 'Fort Smith ATCT/TRACON' },
        { code: 'FWA', name: 'Fort Wayne ATCT/TRACON' },
        { code: 'GEG', name: 'Spokane ATCT/TRACON' },
        { code: 'GGG', name: 'Longview ATCT/TRACON' },
        { code: 'GPT', name: 'Gulfport ATCT/TRACON' },
        { code: 'GRB', name: 'Green Bay ATCT/TRACON' },
        { code: 'GRR', name: 'Grand Rapids ATCT/TRACON' },
        { code: 'GSO', name: 'Greensboro ATCT/TRACON' },
        { code: 'GSP', name: 'Greer ATCT/TRACON' },
        { code: 'GTF', name: 'Great Falls ATCT/TRACON' },
        { code: 'HLN', name: 'Helena ATCT/TRACON' },
        { code: 'HSV', name: 'Huntsville ATCT/TRACON' },
        { code: 'HTS', name: 'Huntington ATCT/TRACON' },
        { code: 'HUF', name: 'Terre Haute ATCT/TRACON' },
        { code: 'ICT', name: 'Wichita ATCT/TRACON' },
        { code: 'ILM', name: 'Wilmington ATCT/TRACON' },
        { code: 'IND', name: 'Indianapolis ATCT/TRACON' },
        { code: 'ITO', name: 'Hilo ATCT/TRACON' },
        { code: 'JAN', name: 'Jackson ATCT/TRACON' },
        { code: 'JAX', name: 'Jacksonville ATCT/TRACON' },
        { code: 'LAN', name: 'Lansing ATCT/TRACON' },
        { code: 'LBB', name: 'Lubbock ATCT/TRACON' },
        { code: 'LCH', name: 'Lake Charles ATCT/TRACON' },
        { code: 'LEX', name: 'Lexington ATCT/TRACON' },
        { code: 'LFT', name: 'Lafayette ATCT/TRACON' },
        { code: 'LIT', name: 'Little Rock ATCT/TRACON' },
        { code: 'MAF', name: 'Midland ATCT/TRACON' },
        { code: 'MBS', name: 'Saginaw ATCT/TRACON' },
        { code: 'MCI', name: 'Kansas City ATCT/TRACON' },
        { code: 'MDT', name: 'Harrisburg ATCT/TRACON' },
        { code: 'MFD', name: 'Mansfield ATCT/TRACON' },
        { code: 'MGM', name: 'Montgomery ATCT/TRACON' },
        { code: 'MIA', name: 'Miami ATCT/TRACON' },
        { code: 'MKE', name: 'Milwaukee ATCT/TRACON' },
        { code: 'MKG', name: 'Muskegon ATCT/TRACON' },
        { code: 'MLI', name: 'Quad City ATCT/TRACON' },
        { code: 'MLU', name: 'Monroe ATCT/TRACON' },
        { code: 'MOB', name: 'Mobile ATCT/TRACON' },
        { code: 'MSN', name: 'Madison ATCT/TRACON' },
        { code: 'MSY', name: 'New Orleans ATCT/TRACON' },
        { code: 'MWH', name: 'Grant County ATCT/TRACON' },
        { code: 'MYR', name: 'Myrtle Beach ATCT/TRACON' },
        { code: 'OKC', name: 'Oklahoma City ATCT/TRACON' },
        { code: 'ORF', name: 'Norfolk ATCT/TRACON' },
        { code: 'PBI', name: 'Palm Beach ATCT/TRACON' },
        { code: 'PHL', name: 'Philadelphia ATCT/TRACON' },
        { code: 'PIA', name: 'Peoria ATCT/TRACON' },
        { code: 'PIT', name: 'Pittsburgh ATCT/TRACON' },
        { code: 'PSC', name: 'Pasco ATCT/TRACON' },
        { code: 'PVD', name: 'Providence ATCT/TRACON' },
        { code: 'PWM', name: 'Portland ME ATCT/TRACON' },
        { code: 'RDG', name: 'Reading ATCT/TRACON' },
        { code: 'RDU', name: 'Raleigh-Durham ATCT/TRACON' },
        { code: 'RFD', name: 'Rockford ATCT/TRACON' },
        { code: 'ROA', name: 'Roanoke ATCT/TRACON' },
        { code: 'ROC', name: 'Rochester NY ATCT/TRACON' },
        { code: 'ROW', name: 'Roswell ATCT/TRACON' },
        { code: 'RST', name: 'Rochester MN ATCT/TRACON' },
        { code: 'RSW', name: 'Fort Myers ATCT/TRACON' },
        { code: 'SAT', name: 'San Antonio ATCT/TRACON' },
        { code: 'SAV', name: 'Savannah ATCT/TRACON' },
        { code: 'SBA', name: 'Santa Barbara ATCT/TRACON' },
        { code: 'SBN', name: 'South Bend ATCT/TRACON' },
        { code: 'SDF', name: 'Louisville ATCT/TRACON' },
        { code: 'SGF', name: 'Springfield MO ATCT/TRACON' },
        { code: 'SHV', name: 'Shreveport ATCT/TRACON' },
        { code: 'SPI', name: 'Springfield IL ATCT/TRACON' },
        { code: 'SUX', name: 'Sioux Gateway ATCT/TRACON' },
        { code: 'SYR', name: 'Syracuse ATCT/TRACON' },
        { code: 'TLH', name: 'Tallahassee ATCT/TRACON' },
        { code: 'TOL', name: 'Toledo ATCT/TRACON' },
        { code: 'TPA', name: 'Tampa ATCT/TRACON' },
        { code: 'TRI', name: 'Tri-Cities ATCT/TRACON' },
        { code: 'TUL', name: 'Tulsa ATCT/TRACON' },
        { code: 'TWF', name: 'Twin Falls ATCT/TRACON' },
        { code: 'TYS', name: 'Knoxville ATCT/TRACON' },
        { code: 'YNG', name: 'Youngstown ATCT/TRACON' }
    ],
    
    // FIR facilities - Comprehensive list from Wikipedia
    // Source: https://en.wikipedia.org/wiki/List_of_flight_information_regions_and_area_control_centers
    // Sorted: Canadian, Mexican, Caribbean first, then alphabetical by region
    FIR: [
        // Canadian FIRs
        { code: 'CZEG', name: 'Edmonton FIR' },
        { code: 'CZQM', name: 'Moncton FIR' },
        { code: 'CZQX', name: 'Gander Oceanic FIR' },
        { code: 'CZUL', name: 'Montreal FIR' },
        { code: 'CZVR', name: 'Vancouver FIR' },
        { code: 'CZWG', name: 'Winnipeg FIR' },
        { code: 'CZYZ', name: 'Toronto FIR' },
        // Mexican FIRs
        { code: 'MMFO', name: 'Mazatlán Oceanic FIR' },
        { code: 'MMFR', name: 'Mexico FIR' },
        // Caribbean & Central American FIRs
        { code: 'MDCS', name: 'Santo Domingo FIR' },
        { code: 'MHCC', name: 'Central American FIR' },
        { code: 'MKJK', name: 'Kingston FIR' },
        { code: 'MPZL', name: 'Panama FIR' },
        { code: 'MTEG', name: 'Port-au-Prince FIR' },
        { code: 'MUFH', name: 'Havana FIR' },
        { code: 'MYNA', name: 'Nassau FIR' },
        { code: 'TJZS', name: 'San Juan FIR' },
        { code: 'TNCF', name: 'Curaçao FIR' },
        { code: 'TTZP', name: 'Piarco FIR' },
        // South American FIRs
        { code: 'SACF', name: 'Córdoba FIR' },
        { code: 'SAEF', name: 'Ezeiza FIR' },
        { code: 'SAMF', name: 'Mendoza FIR' },
        { code: 'SARR', name: 'Resistencia FIR' },
        { code: 'SAVF', name: 'Comodoro Rivadavia FIR' },
        { code: 'SBAO', name: 'Atlântico FIR' },
        { code: 'SBAZ', name: 'Amazônica FIR' },
        { code: 'SBBS', name: 'Brasília FIR' },
        { code: 'SBCW', name: 'Curitiba FIR' },
        { code: 'SBRE', name: 'Recife FIR' },
        { code: 'SCCZ', name: 'Punta Arenas FIR' },
        { code: 'SCEZ', name: 'Santiago FIR' },
        { code: 'SCFZ', name: 'Antofagasta FIR' },
        { code: 'SCIZ', name: 'Easter Island FIR' },
        { code: 'SCTZ', name: 'Puerto Montt FIR' },
        { code: 'SEFG', name: 'Guayaquil FIR' },
        { code: 'SGFA', name: 'Asunción FIR' },
        { code: 'SKEC', name: 'Barranquilla FIR' },
        { code: 'SKED', name: 'Bogotá FIR' },
        { code: 'SLLF', name: 'La Paz FIR' },
        { code: 'SMPM', name: 'Paramaribo FIR' },
        { code: 'SOOO', name: 'Rochambeau FIR' },
        { code: 'SPIM', name: 'Lima FIR' },
        { code: 'SUEO', name: 'Montevideo FIR' },
        { code: 'SVZM', name: 'Maiquetía FIR' },
        { code: 'SYGC', name: 'Georgetown FIR' },
        // North Atlantic FIRs
        { code: 'BGGL', name: 'Nuuk FIR' },
        { code: 'BIRD', name: 'Reykjavík FIR' },
        { code: 'EGGX', name: 'Shanwick Oceanic FIR' },
        { code: 'KZWY', name: 'New York Oceanic FIR' },
        { code: 'LPPO', name: 'Santa Maria Oceanic FIR' },
        // European FIRs
        { code: 'EBBU', name: 'Brussels FIR' },
        { code: 'EDGG', name: 'Langen FIR' },
        { code: 'EDMM', name: 'München FIR' },
        { code: 'EDUU', name: 'Rhein UIR' },
        { code: 'EDWW', name: 'Bremen FIR' },
        { code: 'EDYY', name: 'Maastricht UAC' },
        { code: 'EETT', name: 'Tallinn FIR' },
        { code: 'EFIN', name: 'Finland FIR' },
        { code: 'EGPX', name: 'Scottish FIR' },
        { code: 'EGTT', name: 'London FIR' },
        { code: 'EHAA', name: 'Amsterdam FIR' },
        { code: 'EISN', name: 'Shannon FIR' },
        { code: 'EKDK', name: 'Copenhagen FIR' },
        { code: 'ENOB', name: 'Bodø Oceanic FIR' },
        { code: 'ENOR', name: 'Norway FIR' },
        { code: 'EPWW', name: 'Warsaw FIR' },
        { code: 'ESAA', name: 'Sweden FIR' },
        { code: 'ESMM', name: 'Malmö FIR' },
        { code: 'EVRR', name: 'Riga FIR' },
        { code: 'EYVL', name: 'Vilnius FIR' },
        { code: 'GCCC', name: 'Canarias FIR' },
        { code: 'LAAA', name: 'Tirana FIR' },
        { code: 'LBSR', name: 'Sofia FIR' },
        { code: 'LCCC', name: 'Nicosia FIR' },
        { code: 'LDZO', name: 'Zagreb FIR' },
        { code: 'LECB', name: 'Barcelona FIR' },
        { code: 'LECM', name: 'Madrid FIR' },
        { code: 'LECS', name: 'Sevilla FIR' },
        { code: 'LFBB', name: 'Bordeaux FIR' },
        { code: 'LFEE', name: 'Reims FIR' },
        { code: 'LFFF', name: 'Paris FIR' },
        { code: 'LFMM', name: 'Marseille FIR' },
        { code: 'LFRR', name: 'Brest FIR' },
        { code: 'LGGG', name: 'Athens FIR' },
        { code: 'LHCC', name: 'Budapest FIR' },
        { code: 'LIBB', name: 'Brindisi FIR' },
        { code: 'LIMM', name: 'Milano FIR' },
        { code: 'LIRR', name: 'Roma FIR' },
        { code: 'LJLA', name: 'Ljubljana FIR' },
        { code: 'LKAA', name: 'Praha FIR' },
        { code: 'LMMM', name: 'Malta FIR' },
        { code: 'LOVV', name: 'Wien FIR' },
        { code: 'LPPC', name: 'Lisboa FIR' },
        { code: 'LQSB', name: 'Sarajevo FIR' },
        { code: 'LRBB', name: 'Bucureşti FIR' },
        { code: 'LSAG', name: 'Geneva FIR' },
        { code: 'LSAS', name: 'Switzerland FIR' },
        { code: 'LSAZ', name: 'Zurich FIR' },
        { code: 'LTAA', name: 'Ankara FIR' },
        { code: 'LTBB', name: 'Istanbul FIR' },
        { code: 'LUUU', name: 'Chişinău FIR' },
        { code: 'LWSS', name: 'Skopje FIR' },
        { code: 'LYBA', name: 'Beograd FIR' },
        { code: 'LZBB', name: 'Bratislava FIR' },
        // Middle East FIRs
        { code: 'LLLL', name: 'Tel Aviv FIR' },
        { code: 'OBBB', name: 'Bahrain FIR' },
        { code: 'OEJD', name: 'Jeddah FIR' },
        { code: 'OIIX', name: 'Tehran FIR' },
        { code: 'OJAC', name: 'Amman FIR' },
        { code: 'OKAC', name: 'Kuwait FIR' },
        { code: 'OLBB', name: 'Beirut FIR' },
        { code: 'OMAE', name: 'Emirates FIR' },
        { code: 'OOMM', name: 'Muscat FIR' },
        { code: 'ORBB', name: 'Baghdad FIR' },
        { code: 'OSTT', name: 'Damascus FIR' },
        { code: 'OYSC', name: 'Sana\'a FIR' },
        // African FIRs
        { code: 'DAAA', name: 'Alger FIR' },
        { code: 'DGAC', name: 'Accra FIR' },
        { code: 'DIII', name: 'Abidjan Oceanic FIR' },
        { code: 'DNKK', name: 'Kano FIR' },
        { code: 'DRRR', name: 'Niamey FIR' },
        { code: 'DTTC', name: 'Tunis FIR' },
        { code: 'FACA', name: 'Cape Town FIR' },
        { code: 'FAJA', name: 'Johannesburg FIR' },
        { code: 'FAJO', name: 'Johannesburg Oceanic FIR' },
        { code: 'FBGR', name: 'Gaborone FIR' },
        { code: 'FCCC', name: 'Brazzaville FIR' },
        { code: 'FIMM', name: 'Mauritius FIR' },
        { code: 'FLFI', name: 'Lusaka FIR' },
        { code: 'FMMM', name: 'Antananarivo FIR' },
        { code: 'FNAN', name: 'Luanda FIR' },
        { code: 'FOOO', name: 'Libreville FIR' },
        { code: 'FQBE', name: 'Beira FIR' },
        { code: 'FSSS', name: 'Seychelles FIR' },
        { code: 'FTTT', name: 'N\'Djamena FIR' },
        { code: 'FVHF', name: 'Harare FIR' },
        { code: 'FWLL', name: 'Lilongwe FIR' },
        { code: 'FYWF', name: 'Windhoek FIR' },
        { code: 'FZZA', name: 'Kinshasa FIR' },
        { code: 'GLRB', name: 'Roberts FIR' },
        { code: 'GMMM', name: 'Casablanca FIR' },
        { code: 'GOOO', name: 'Dakar FIR' },
        { code: 'GVSC', name: 'Sal Oceanic FIR' },
        { code: 'HAAA', name: 'Addis Ababa FIR' },
        { code: 'HBBA', name: 'Bujumbura FIR' },
        { code: 'HCSM', name: 'Mogadishu FIR' },
        { code: 'HECC', name: 'Cairo FIR' },
        { code: 'HHAA', name: 'Asmara FIR' },
        { code: 'HKNA', name: 'Nairobi FIR' },
        { code: 'HLLL', name: 'Tripoli FIR' },
        { code: 'HRYR', name: 'Kigali FIR' },
        { code: 'HSSX', name: 'Khartoum FIR' },
        { code: 'HTDC', name: 'Dar es Salaam FIR' },
        { code: 'HUEC', name: 'Entebbe FIR' },
        // Central Asian FIRs
        { code: 'UAAA', name: 'Almaty FIR' },
        { code: 'UACN', name: 'Astana FIR' },
        { code: 'UAII', name: 'Shymkent FIR' },
        { code: 'UATT', name: 'Aktobe FIR' },
        { code: 'UBBA', name: 'Baku FIR' },
        { code: 'UCFM', name: 'Bishkek FIR' },
        { code: 'UCFO', name: 'Osh FIR' },
        { code: 'UDDD', name: 'Yerevan FIR' },
        { code: 'UGGG', name: 'Tbilisi FIR' },
        { code: 'UTAA', name: 'Ashgabat FIR' },
        { code: 'UTAK', name: 'Turkmenbashi FIR' },
        { code: 'UTAT', name: 'Dashoguz FIR' },
        { code: 'UTAV', name: 'Turkmenabat FIR' },
        { code: 'UTDD', name: 'Dushanbe FIR' },
        { code: 'UTSD', name: 'Samarkand FIR' },
        { code: 'UTTR', name: 'Tashkent FIR' },
        // Russian FIRs
        { code: 'UEEE', name: 'Yakutsk FIR' },
        { code: 'UHHH', name: 'Khabarovsk FIR' },
        { code: 'UHMM', name: 'Magadan FIR' },
        { code: 'UHPP', name: 'Petropavlovsk-Kamchatsky FIR' },
        { code: 'UIII', name: 'Irkutsk FIR' },
        { code: 'ULLL', name: 'Sankt-Peterburg FIR' },
        { code: 'UMKK', name: 'Kaliningrad FIR' },
        { code: 'UNKL', name: 'Krasnoyarsk FIR' },
        { code: 'UNNT', name: 'Novosibirsk FIR' },
        { code: 'URRV', name: 'Rostov-na-Donu FIR' },
        { code: 'USSV', name: 'Yekaterinburg FIR' },
        { code: 'USTV', name: 'Tyumen FIR' },
        { code: 'UUWV', name: 'Moscow FIR' },
        { code: 'UWWW', name: 'Samara FIR' },
        // Eastern European FIRs
        { code: 'UKBV', name: 'Kyiv FIR' },
        { code: 'UKDV', name: 'Dnipro FIR' },
        { code: 'UKFV', name: 'Simferopol FIR' },
        { code: 'UKLV', name: 'Lviv FIR' },
        { code: 'UKOV', name: 'Odesa FIR' },
        { code: 'UMMV', name: 'Minsk FIR' },
        // South Asian FIRs
        { code: 'OAKX', name: 'Kabul FIR' },
        { code: 'OPKR', name: 'Karachi FIR' },
        { code: 'OPLR', name: 'Lahore FIR' },
        { code: 'VABF', name: 'Mumbai FIR' },
        { code: 'VCCC', name: 'Colombo FIR' },
        { code: 'VECF', name: 'Kolkata FIR' },
        { code: 'VGFR', name: 'Dhaka FIR' },
        { code: 'VIDF', name: 'Delhi FIR' },
        { code: 'VNSM', name: 'Kathmandu FIR' },
        { code: 'VOMF', name: 'Chennai FIR' },
        { code: 'VRMF', name: 'Malé FIR' },
        // Southeast Asian FIRs
        { code: 'VDPF', name: 'Phnom Penh FIR' },
        { code: 'VLVT', name: 'Vientiane FIR' },
        { code: 'VTBB', name: 'Bangkok FIR' },
        { code: 'VVHM', name: 'Ho Chi Minh FIR' },
        { code: 'VVHN', name: 'Hanoi FIR' },
        { code: 'VYYF', name: 'Yangon FIR' },
        { code: 'WAAF', name: 'Ujung Pandang FIR' },
        { code: 'WBFC', name: 'Kota Kinabalu FIR' },
        { code: 'WIIF', name: 'Jakarta FIR' },
        { code: 'WMFC', name: 'Kuala Lumpur FIR' },
        { code: 'WSJC', name: 'Singapore FIR' },
        // East Asian FIRs
        { code: 'RCAA', name: 'Taipei FIR' },
        { code: 'RJJJ', name: 'Tokyo FIR' },
        { code: 'RKRR', name: 'Incheon FIR' },
        { code: 'RPHI', name: 'Manila FIR' },
        { code: 'VHHK', name: 'Hong Kong FIR' },
        { code: 'ZBPE', name: 'Beijing FIR' },
        { code: 'ZGZU', name: 'Guangzhou FIR' },
        { code: 'ZHWH', name: 'Wuhan FIR' },
        { code: 'ZJSA', name: 'Sanya FIR' },
        { code: 'ZKKP', name: 'Pyongyang FIR' },
        { code: 'ZLHW', name: 'Lanzhou FIR' },
        { code: 'ZMUB', name: 'Ulaanbaatar FIR' },
        { code: 'ZPKM', name: 'Kunming FIR' },
        { code: 'ZSHA', name: 'Shanghai FIR' },
        { code: 'ZWUQ', name: 'Ürümqi FIR' },
        { code: 'ZYSH', name: 'Shenyang FIR' },
        // Pacific/Oceanic FIRs
        { code: 'AGGG', name: 'Honiara FIR' },
        { code: 'ANAU', name: 'Nauru FIR' },
        { code: 'AYPM', name: 'Port Moresby FIR' },
        { code: 'KZAK', name: 'Oakland Oceanic FIR' },
        { code: 'NFFF', name: 'Nadi FIR' },
        { code: 'NTTT', name: 'Tahiti FIR' },
        { code: 'NZZC', name: 'New Zealand FIR' },
        { code: 'NZZO', name: 'Auckland Oceanic FIR' },
        { code: 'PAZA', name: 'Anchorage FIR' },
        { code: 'PAZN', name: 'Anchorage Oceanic FIR' },
        { code: 'PHZH', name: 'Honolulu FIR' },
        { code: 'YBBB', name: 'Brisbane FIR' },
        { code: 'YMMM', name: 'Melbourne FIR' }
    ],
    
    // Roles by facility type - original structure
    ROLES: {
        // DCC roles are now selected based on DCC_SERVICES organization
        DCC: [], // Placeholder - actual roles come from DCC_ROLES based on selected service
        ECFMP: [
            { code: 'LEAD', name: 'Leadership' },
            { code: 'NMT', name: 'Network Management Team' },
            { code: 'SFM', name: 'Senior Flow Manager' },
            { code: 'FM', name: 'Flow Manager' },
            { code: 'EVENT', name: 'Event Staff' },
            { code: 'ATC', name: 'Air Traffic Controller' },
            { code: 'OTHER', name: 'Other' }
        ],
        CTP: [
            { code: 'LEAD', name: 'Leadership' },
            { code: 'COORD', name: 'Coordination' },
            { code: 'PLAN', name: 'Planning' },
            { code: 'RTE', name: 'Routes' },
            { code: 'FLOW', name: 'Flow' },
            { code: 'OCN', name: 'Oceanic' },
            { code: 'OTHER', name: 'Other' }
        ],
        WF: [
            { code: 'LEAD', name: 'Leadership' },
            { code: 'AFF', name: 'Affiliate' },
            { code: 'TEAM', name: 'Team Member' },
            { code: 'SM', name: 'Social Media' },
            { code: 'OTHER', name: 'Other' }
        ],
        // ATC Facility roles (ARTCC, TRACON, LOCAL, FIR)
        FACILITY: [
            { code: 'STMC', name: 'Supervisory Traffic Management Coordinator' },
            { code: 'TMC', name: 'Traffic Management Coordinator' },
            { code: 'TMU', name: 'Traffic Management Unit' },
            { code: 'DEP', name: 'Departure Coordinator' },
            { code: 'ENR', name: 'En Route Coordinator' },
            { code: 'ARR', name: 'Arrival Coordinator' },
            { code: 'PIT', name: 'ZNY PIT' },
            { code: 'RR', name: 'Reroute Coordinator' },
            { code: 'MIL', name: 'Military Coordinator' },
            { code: 'LEAD', name: 'Leadership' },
            { code: 'EVENT', name: 'Events' },
            { code: 'ATC', name: 'Air Traffic Controller' },
            { code: 'OTHER', name: 'Other' }
        ],
        VATUSA: [
            { code: 'LEAD', name: 'Leadership' },
            { code: 'BOG', name: 'Board of Governors' },
            { code: 'REGL', name: 'Region Leadership' },
            { code: 'DIVL', name: 'Division Leadership' },
            { code: 'EVENT', name: 'Events' },
            { code: 'OTHER', name: 'Other' }
        ],
        VATSIM: [
            { code: 'LEAD', name: 'Leadership' },
            { code: 'BOG', name: 'Board of Governors' },
            { code: 'REGL', name: 'Region Leadership' },
            { code: 'DIVL', name: 'Division Leadership' },
            { code: 'EVENT', name: 'Events' },
            { code: 'OTHER', name: 'Other' }
        ],
        VA: [
            { code: 'LEAD', name: 'Leadership' },
            { code: 'AOC', name: 'Operations' },
            { code: 'OTHER', name: 'Other' }
        ],
        VSO: [
            { code: 'LEAD', name: 'Leadership' },
            { code: 'CMD', name: 'Command Staff' },
            { code: 'ATC', name: 'ATC Coordination' },
            { code: 'OTHER', name: 'Other' }
        ],
        APT_AUTH: [
            { code: 'LEAD', name: 'Leadership' },
            { code: 'OPS', name: 'Operations' },
            { code: 'OTHER', name: 'Other' }
        ],
        OTHER: [
            { code: 'REP', name: 'Representative' },
            { code: 'OTHER', name: 'Other' }
        ]
    },
    
    // Types that need no facility select dropdown (single org)
    SINGLE_ORG_TYPES: ['ECFMP', 'CTP', 'WF'],
    
    // DCC is now a hierarchical type with services/organizations
    DCC_SERVICES_TYPE: ['DCC'],
    
    // DCC Services - Organizations under the DCC Command Center
    // Source: FAA Organizational Chart 2025 + JATOC structure
    DCC_SERVICES: [
        // TMU - Traffic Management Unit
        { 
            code: 'TMU', 
            name: 'Traffic Management Unit (TMU)',
            group: 'TMU'
        },
        // JATOC - Joint Air Traffic Operations Command
        { 
            code: 'JATOC', 
            name: 'Joint Air Traffic Operations Command (AJO-02)',
            group: 'JATOC'
        },
        // Operations Services
        { 
            code: 'AJR-1', 
            name: 'System Operations Services - NAS Operations (AJR-1)',
            group: 'Operations'
        },
        { 
            code: 'AJW', 
            name: 'Technical Operations (AJW)',
            group: 'Operations'
        },
        { 
            code: 'AJT', 
            name: 'Air Traffic Services (AJT)',
            group: 'Operations'
        },
        { 
            code: 'AJF', 
            name: 'Flight Program Operations (AJF)',
            group: 'Operations'
        },
        { 
            code: 'PMO-ATS', 
            name: 'Program Management Organization - Air Traffic Services',
            group: 'Operations'
        },
        { 
            code: 'AOC', 
            name: 'Office of Communications (AOC)',
            group: 'Operations'
        },
        { 
            code: 'AJV', 
            name: 'Mission Support Services (AJV)',
            group: 'Operations'
        },
        { 
            code: 'AJR-B', 
            name: 'Flight Service (AJR-B)',
            group: 'Operations'
        },
        // Security Services
        { 
            code: 'AJR-2000', 
            name: 'Security (AJR-2000)',
            group: 'Security'
        },
        // NAS Security & Enterprise Ops
        { 
            code: 'AJW-B', 
            name: 'NAS Security & Enterprise Operations (AJW-B)',
            group: 'Security'
        },
        // Safety & Technical Training
        { 
            code: 'AJI', 
            name: 'Safety & Technical Training (AJI)',
            group: 'Safety'
        },
        // Aviation Safety
        { 
            code: 'AVS', 
            name: 'Aviation Safety (AVS)',
            group: 'Safety'
        },
        // Security & Hazmat
        { 
            code: 'ASH', 
            name: 'Security & Hazardous Materials Safety (ASH)',
            group: 'Safety'
        },
        // Other FAA Offices
        { 
            code: 'ANG', 
            name: 'NextGen (ANG)',
            group: 'Other FAA'
        },
        { 
            code: 'AFN', 
            name: 'Finance & Management (AFN)',
            group: 'Other FAA'
        },
        { 
            code: 'APL', 
            name: 'Policy, International Affairs & Environment (APL)',
            group: 'Other FAA'
        },
        { 
            code: 'AHR', 
            name: 'Human Resource Management (AHR)',
            group: 'Other FAA'
        }
    ],
    
    // DCC Roles by Service/Organization
    DCC_ROLES: {
        // TMU Roles
        'TMU': [
            { code: 'OP', name: 'Operations Planner' },
            { code: 'NOM', name: 'National Operations Manager' },
            { code: 'NTMO', name: 'National Traffic Management Officer' },
            { code: 'NTMS', name: 'National Traffic Management Specialist' },
            { code: 'OTHER', name: 'Other' }
        ],
        // JATOC Roles
        'JATOC': [
            { code: 'AID', name: 'ATO Incident Director' },
            { code: 'GM', name: 'JATOC General Manager' },
            { code: 'AWO', name: 'JATOC ATO Watch Officer' },
            { code: 'AJT-AT', name: 'Air Traffic Services Representative' },
            { code: 'J-CAT', name: 'JATOC Crisis Action Team' },
            { code: 'AJR-2410', name: 'Tactical Security Operations Team' },
            { code: 'OG', name: 'ATO Officer Group' },
            { code: 'EMC', name: 'Event Management Center' },
            { code: 'OP', name: 'Operations Planner' },
            { code: 'NOM', name: 'National Operations Manager' },
            { code: 'NTMO', name: 'National Traffic Management Officer' },
            { code: 'NTMS', name: 'National Traffic Management Specialist' },
            { code: 'DCOO', name: 'Deputy COO Operations (AJO-02A0)' },
            { code: 'COS', name: 'Chief of Staff ATO (AJO-03)' },
            { code: 'OTHER', name: 'Other' }
        ],
        // NAS Operations Roles
        'AJR-1': [
            { code: 'DIR', name: 'Director NAS Operations' },
            { code: 'NOCC', name: 'National Operations Control Center' },
            { code: 'STOWO', name: 'Senior Technical Operations Watch Officer' },
            { code: 'NOM', name: 'National Operations Manager' },
            { code: 'NTMO', name: 'National Traffic Management Officer' },
            { code: 'NTMS', name: 'National Traffic Management Specialist' },
            { code: 'OP', name: 'Operations Planner' },
            { code: 'OTHER', name: 'Other' }
        ],
        // Technical Operations Roles
        'AJW': [
            { code: 'DIR', name: 'Director Technical Operations' },
            { code: 'AJW-1', name: 'Operations Support' },
            { code: 'AJW-1110', name: 'Program Operations Support Team' },
            { code: 'AJW-1120', name: 'Business Integration Team' },
            { code: 'AJW-1210', name: 'Advanced Systems Design Service Team' },
            { code: 'AJW-1220', name: 'NAS Resiliency Team' },
            { code: 'AJW-1230', name: 'NAS Strategic Operations Team' },
            { code: 'AJW-1240', name: 'UAS Operations Team' },
            { code: 'AJW-1310', name: 'Maintenance Automation Team' },
            { code: 'AJW-1320', name: 'NAS Technical Performance & Analysis Team' },
            { code: 'AJW-1330', name: 'NAV/COMM Support Team' },
            { code: 'AJW-1340', name: 'Automation Support Team' },
            { code: 'AJW-1350', name: 'Surveillance & Weather Support Team' },
            { code: 'AJW-1410', name: 'Weather Systems Team' },
            { code: 'AJW-1420', name: 'En Route Surveillance Team' },
            { code: 'AJW-1430', name: 'Beacon & Terminal Surveillance Team' },
            { code: 'AJW-1440', name: 'Surface Surveillance Team' },
            { code: 'AJW-1510', name: 'Power & Environmental Systems Team' },
            { code: 'AJW-1520', name: 'Ground Based & Satellite Navigation Team' },
            { code: 'AJW-1530', name: 'NAS Communications Team' },
            { code: 'AJW-1540', name: 'Air Traffic Voice Switch System Team' },
            { code: 'AJW-1610', name: 'NAS Engineering Support Processes & Tools Team' },
            { code: 'AJW-1620', name: 'Supply Chain System Team' },
            { code: 'AJW-1630', name: 'NAS Monitoring Team' },
            { code: 'AJW-1710', name: 'Weather, Flight Service & Aero Info Svcs' },
            { code: 'AJW-1720', name: 'NAS Enterprise Services Team' },
            { code: 'AJW-1810', name: 'NAS Configuration Management Team' },
            { code: 'AJW-1820', name: 'NAS Operational Risk Management & QC Team' },
            { code: 'AJW-1830', name: 'NAS Operations Policy Team' },
            { code: 'AJW-1910', name: 'Spectrum Assignment & Engineering Team' },
            { code: 'AJW-1920', name: 'Spectrum Planning & International Team' },
            { code: 'AJW-1930', name: 'Spectrum Testing & Engineering Analysis Team' },
            { code: 'AJW-1940', name: 'Spectrum Engineering National Security Team' },
            { code: 'AJW-2', name: 'Facilities & Engineering Services' },
            { code: 'AJW-2210', name: 'Power Systems Team' },
            { code: 'AJW-2220', name: 'Power Implementation Team' },
            { code: 'AJW-2310', name: 'EOSH Compliance Team' },
            { code: 'AJW-2320', name: 'EOSH Operations Team' },
            { code: 'AJW-2410', name: 'Program Integration & Support Team' },
            { code: 'AJW-2420', name: 'Unstaffed Infrastructure Team' },
            { code: 'AJW-2450', name: 'Facility Security Team' },
            { code: 'AJW-2500', name: 'DASHO Group' },
            { code: 'AJW-2610', name: 'Corporate Tools Team' },
            { code: 'AJW-2620', name: 'Air Traffic Systems Team' },
            { code: 'AJW-2630', name: 'Enterprise Systems Team' },
            { code: 'OTHER', name: 'Other' }
        ],
        // Air Traffic Services Roles
        'AJT': [
            { code: 'DIR', name: 'Director Air Traffic Services' },
            { code: 'AJT-1', name: 'Director Strategic Operations' },
            { code: 'AJT-1110', name: 'Tools & Applications Team' },
            { code: 'AJT-1120', name: 'Workforce & Training Team' },
            { code: 'AJT-1130', name: 'Resource Utilization Team' },
            { code: 'AJT-1210', name: 'DOD Team' },
            { code: 'AJT-1220', name: 'Domestic Team' },
            { code: 'AJT-1230', name: 'Emerging/New Technologies Team' },
            { code: 'AJT-1310', name: 'Comm, Weather, Surveillance Team' },
            { code: 'AJT-1320', name: 'Oceanic/International Team' },
            { code: 'AJT-1330', name: 'Special Projects Team' },
            { code: 'AJT-1410', name: 'Enroute Team' },
            { code: 'AJT-1420', name: 'Terminal Team' },
            { code: 'AJT-1430', name: 'Flow Management Team' },
            { code: 'AJT-ACMD', name: 'AT Centralized Hiring Team' },
            { code: 'OTHER', name: 'Other' }
        ],
        // Flight Program Operations
        'AJF': [
            { code: 'DIR', name: 'Director Flight Program Operations' },
            { code: 'FPO', name: 'Flight Program Officer' },
            { code: 'OTHER', name: 'Other' }
        ],
        // PMO Air Traffic Services
        'PMO-ATS': [
            { code: 'DIR', name: 'Director PMO-ATS' },
            { code: 'PM', name: 'Program Manager' },
            { code: 'OTHER', name: 'Other' }
        ],
        // Office of Communications
        'AOC': [
            { code: 'DIR', name: 'Assistant Administrator Communications' },
            { code: 'DEP', name: 'Deputy Assistant Administrator' },
            { code: 'OTHER', name: 'Other' }
        ],
        // Mission Support Services
        'AJV': [
            { code: 'DIR', name: 'Director Mission Support Services' },
            { code: 'AJV-A', name: 'Aeronautical Information Services' },
            { code: 'AJV-A3', name: 'Aeronautical Information Group' },
            { code: 'USNOF', name: 'US NOTAM Office' },
            { code: 'DNO', name: 'Defense NOTAM Office' },
            { code: 'OTHER', name: 'Other' }
        ],
        // Flight Service
        'AJR-B': [
            { code: 'DIR', name: 'Director Flight Service' },
            { code: 'AJR-B300', name: 'US NOTAM Governance & Operations Group' },
            { code: 'AJR-BAL', name: 'Alaska Flight Services' },
            { code: 'AFSS', name: 'Automated Flight Service Station' },
            { code: 'FSS', name: 'Flight Service Station' },
            { code: 'OTHER', name: 'Other' }
        ],
        // Security (AJR-2000)
        'AJR-2000': [
            { code: 'DIR', name: 'Director Security' },
            { code: 'AJR-2210', name: 'Significant Incident Management Ops Team' },
            { code: 'AJR-2220', name: 'Operations Security Plans & Procedures Team' },
            { code: 'AJR-2230', name: 'Operations Security Strategic Initiatives Team' },
            { code: 'AJR-2410', name: 'JATOC Tactical Security Operations Team' },
            { code: 'AJR-2420', name: 'NCRCC Air Traffic Security Coordinator' },
            { code: 'AJR-2430', name: 'NORAD-NORTHCOM ATSC Team' },
            { code: 'AJR-2440', name: 'CONR ATSC Team' },
            { code: 'AJR-2610', name: 'UAS Security Mission Planning Team' },
            { code: 'AJR-2620', name: 'UAS Security Detection & Mitigation Team' },
            { code: 'AJR-2630', name: 'UAS Security Integration Team' },
            { code: 'SOS', name: 'Strategic Operations Security' },
            { code: 'SOSC', name: 'System Operations Support Center' },
            { code: 'SpOS', name: 'Special Operations Security' },
            { code: 'AJR-24', name: 'Tactical Operations Security' },
            { code: 'NTSO', name: 'National Tactical Security Operations' },
            { code: 'ATSC', name: 'Air Traffic Security Coordinator' },
            { code: 'ATSC-DEN', name: 'DEN Air Traffic Security Coordinator' },
            { code: 'SIRG', name: 'Safety Intelligence and Response Group' },
            { code: 'OTHER', name: 'Other' }
        ],
        // NAS Security & Enterprise Operations
        'AJW-B': [
            { code: 'DIR', name: 'Director NAS Security & Enterprise Ops' },
            { code: 'NEMC', name: 'NEMC SSC' },
            { code: 'AJW-B130', name: 'Enterprise Data Services Team' },
            { code: 'AJW-B160', name: 'WAAS Operations Team' },
            { code: 'AJW-B170', name: 'TFMS/NAIMES Services Team' },
            { code: 'AJW-B210', name: 'Telecom Network Services Team' },
            { code: 'NOCC-A', name: 'NOCC Subteam A' },
            { code: 'NOCC-B', name: 'NOCC Subteam B' },
            { code: 'NOCC-C', name: 'NOCC Subteam C' },
            { code: 'NOCC-D', name: 'NOCC Subteam D' },
            { code: 'OCC', name: 'Operations Control Center' },
            { code: 'AJW-B330', name: 'National Operations Support Team' },
            { code: 'AJW-B340', name: 'NAS Cyber Operations Team' },
            { code: 'ECC', name: 'Enterprise Control Center' },
            { code: 'AJW-B410', name: 'Program Control & Governance Team' },
            { code: 'AJW-B420', name: 'Enterprise Cyber Architecture Team' },
            { code: 'AJW-B430', name: 'Cyber Engineering Team' },
            { code: 'AJW-B440', name: 'Cyber Testing Team' },
            { code: 'AJW-B620', name: 'Tactical Operations Programs Team' },
            { code: 'NDP', name: 'NAS Defense Programs' },
            { code: 'OTHER', name: 'Other' }
        ],
        // Safety & Technical Training
        'AJI': [
            { code: 'DIR', name: 'Director Safety & Technical Training' },
            { code: 'AJI-1310', name: 'Safety Event Team' },
            { code: 'AJI-1320', name: 'Safety Investigations Team' },
            { code: 'AJI-1330', name: 'Safety Investigations & Response Team' },
            { code: 'SIRG', name: 'Safety Intelligence & Response Group' },
            { code: 'OTHER', name: 'Other' }
        ],
        // Aviation Safety
        'AVS': [
            { code: 'DIR', name: 'Associate Administrator Aviation Safety' },
            { code: 'ODA', name: 'Organization Designation Authorization' },
            { code: 'AAM', name: 'Aviation Medicine' },
            { code: 'CAMI', name: 'Civil Aerospace Medical Institute' },
            { code: 'OTHER', name: 'Other' }
        ],
        // Security & Hazmat
        'ASH': [
            { code: 'DIR', name: 'Associate Administrator Security & Hazmat' },
            { code: 'ASH-100', name: 'UAS Testing & Evaluation' },
            { code: 'ASH-200', name: 'UAS Policy & Regulation' },
            { code: 'ASH-300', name: 'UAS Program Design & Analytics' },
            { code: 'AXE', name: 'National Security Programs & Incident Response' },
            { code: 'AXF', name: 'Office of Infrastructure Protection' },
            { code: 'AXH', name: 'Office of Hazardous Materials Safety' },
            { code: 'AXI', name: 'Office of Investigations & Prof Responsibility' },
            { code: 'AXM', name: 'Office of Business & Mission Services' },
            { code: 'AXP', name: 'Office of Personnel Security' },
            { code: 'OTHER', name: 'Other' }
        ],
        // NextGen
        'ANG': [
            { code: 'DIR', name: 'Assistant Administrator NextGen' },
            { code: 'ANG-A', name: 'Procurement Services & Grants Management' },
            { code: 'ANG-B', name: 'NAS Systems Engineering & Integration' },
            { code: 'ANG-C', name: 'Portfolio Management & Technology Development' },
            { code: 'ANG-E', name: 'William J. Hughes Technical Center' },
            { code: 'OTHER', name: 'Other' }
        ],
        // Finance & Management
        'AFN': [
            { code: 'DIR', name: 'Assistant Administrator Finance & Management' },
            { code: 'ABA', name: 'Financial Services' },
            { code: 'ACQ', name: 'Acquisitions & Business Services' },
            { code: 'AIT', name: 'Information & Technology Services' },
            { code: 'ASP', name: 'Strategy & Performance Services' },
            { code: 'AMC', name: 'Mike Monroney Aeronautical Center' },
            { code: 'AMA', name: 'FAA Academy' },
            { code: 'AMK', name: 'Enterprise Services Center' },
            { code: 'OTHER', name: 'Other' }
        ],
        // Policy, International Affairs & Environment
        'APL': [
            { code: 'DIR', name: 'Assistant Administrator Policy & International' },
            { code: 'AEE', name: 'Environment & Energy' },
            { code: 'API', name: 'International Affairs' },
            { code: 'APO', name: 'Aviation Policy & Plans' },
            { code: 'ARA', name: 'National Engagement & Regional Administration' },
            { code: 'OTHER', name: 'Other' }
        ],
        // Human Resource Management
        'AHR': [
            { code: 'DIR', name: 'Assistant Administrator Human Resources' },
            { code: 'AHF', name: 'Office of Human Resource Services' },
            { code: 'AHD', name: 'Office of Career & Leadership Development' },
            { code: 'AHB', name: 'Office of Compensation Benefits & Worklife' },
            { code: 'AHA', name: 'Office of Accountability & Strategic Business' },
            { code: 'AHL', name: 'Office of Labor & Employee Relations' },
            { code: 'OTHER', name: 'Other' }
        ]
    },
    
    // Types that need identifier input instead of facility dropdown
    IDENTIFIER_TYPES: ['VATUSA', 'VATSIM'],
    
    // Types that need custom facility input
    CUSTOM_TYPES: ['VA', 'VSO', 'APT_AUTH', 'OTHER'],
    
    // Types that need airport ICAO input
    LOCAL_TYPES: ['LOCAL'],
    
    // ATC facility types that use FACILITY roles
    ATC_FACILITY_TYPES: ['ARTCC', 'TRACON', 'LOCAL', 'FIR']
};
</script>
<script src="assets/js/jatoc.js"></script>
<script src="assets/js/jatoc-facility-patch.js"></script>

<?php include("load/footer.php"); ?>
</body>
</html>
