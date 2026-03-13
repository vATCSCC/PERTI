<?php

include("sessions/handler.php");
    // Session Start (S)
    if (session_status() == PHP_SESSION_NONE) {
        session_start();
        ob_start();
    }
    // Session Start (E)

    include("load/config.php");
    define('PERTI_MYSQL_ONLY', true);
    include("load/connect.php");
    include("load/playbook_visibility.php");

    // Check Perms
    $perm = false;
    $pb_cid = null;
    $pb_admin = false;
    if (!defined('DEV')) {
        if (isset($_SESSION['VATSIM_CID'])) {
            $cid = session_get('VATSIM_CID', '');
            $p_check = $conn_sqli->query("SELECT * FROM users WHERE cid='$cid'");
            if ($p_check) {
                $perm = true;
                $pb_cid = (int)$cid;
                $pb_admin = is_playbook_admin($conn_sqli);
            }
        }
    } else {
        $perm = true;
        $pb_admin = true;
        $_SESSION['VATSIM_FIRST_NAME'] = $_SESSION['VATSIM_LAST_NAME'] = $_SESSION['VATSIM_CID'] = 0;
        $pb_cid = 0;
    }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php
        $page_title = "Playbook";
        include("load/header.php");
    ?>

    <!-- MapLibre GL JS -->
    <script>window.PERTI_USE_MAPLIBRE = true;</script>
    <link href="https://unpkg.com/maplibre-gl@4.5.0/dist/maplibre-gl.css" rel="stylesheet" />
    <script src="https://unpkg.com/maplibre-gl@4.5.0/dist/maplibre-gl.js"></script>
    <script src="https://unpkg.com/@turf/turf@6/turf.min.js"></script>

    <link rel="stylesheet" href="assets/css/playbook.css<?= _v('assets/css/playbook.css') ?>">
</head>

<body>

<?php
include("load/nav.php");
?>

<!-- Map hero with floating catalog overlay -->
<div class="pb-map-section" id="pb_map_section">
    <textarea id="routeSearch" style="display:none;"></textarea>
    <button id="plot_r" style="display:none;"></button>
    <div id="map_wrapper">
        <div id="placeholder"></div>
        <div id="graphic"></div>
        <div class="pb-map-legend" id="pb_map_legend">
            <div class="pb-map-legend-title"><?= __('playbook.legendTitle') ?></div>
            <div class="pb-legend-item"><span class="pb-legend-swatch" style="background:#2196F3;opacity:0.5;"></span> <?= __('playbook.legendOrigin') ?></div>
            <div class="pb-legend-item"><span class="pb-legend-swatch" style="background:#FF9800;opacity:0.5;"></span> <?= __('playbook.legendDest') ?></div>
            <div class="pb-legend-item"><span class="pb-legend-swatch" style="background:#9C27B0;opacity:0.4;"></span> <?= __('playbook.legendTraversed') ?></div>
            <div class="pb-legend-item"><span class="pb-legend-swatch-border" style="border-color:#28a745;"></span> <?= __('playbook.legendIncluded') ?></div>
            <div class="pb-legend-item"><span class="pb-legend-swatch-border" style="border-color:#dc3545;"></span> <?= __('playbook.legendExcluded') ?></div>
            <div class="pb-legend-divider"></div>
            <div class="pb-map-legend-title">Play Highlights</div>
            <label class="pb-legend-toggle"><input type="checkbox" data-hl-toggle="playOrigin" checked> <?= __('playbook.legendOrigin') ?></label>
            <label class="pb-legend-toggle"><input type="checkbox" data-hl-toggle="playDest" checked> <?= __('playbook.legendDest') ?></label>
            <label class="pb-legend-toggle"><input type="checkbox" data-hl-toggle="playTraversed" checked> <?= __('playbook.legendTraversed') ?></label>
            <div class="pb-legend-divider"></div>
            <div class="pb-map-legend-title"><?= __('playbook.legendHighlightLayers') ?></div>
            <label class="pb-legend-toggle"><input type="checkbox" data-hl-toggle="artcc" checked> <?= __('playbook.legendArtcc') ?></label>
            <div class="pb-hierarchy-controls" style="margin-left:18px; margin-bottom:2px;">
                <div style="color:#999; font-size:9px; margin-bottom:1px;"><?= __('common.hierarchy.label') ?></div>
                <label class="pb-legend-toggle" style="font-size:11px;"><input type="checkbox" data-hier-toggle="super"> <span class="pb-legend-swatch" style="background:#F0C946; width:8px; height:8px;"></span> <?= __('common.hierarchy.superCenters') ?></label>
                <label class="pb-legend-toggle" style="font-size:11px;"><input type="checkbox" data-hier-toggle="fir" checked> <span class="pb-legend-swatch" style="background:#4A90D9; width:8px; height:8px;"></span> <?= __('common.hierarchy.firs') ?></label>
                <label class="pb-legend-toggle" style="font-size:11px;"><input type="checkbox" data-hier-toggle="sub"> <span class="pb-legend-swatch" style="background:#2E6AAD; width:8px; height:8px; border:1px dashed #5a9ad5;"></span> <?= __('common.hierarchy.subAreas') ?></label>
                <label class="pb-legend-toggle" style="font-size:11px;"><input type="checkbox" data-hier-toggle="deep"> <span class="pb-legend-swatch" style="background:#1E4A7A; width:8px; height:8px; border:1px dashed #4a7ab0;"></span> <?= __('common.hierarchy.deepSubAreas') ?></label>
            </div>
            <label class="pb-legend-toggle"><input type="checkbox" data-hl-toggle="tracon" checked> <?= __('playbook.legendTracon') ?></label>
            <label class="pb-legend-toggle"><input type="checkbox" data-hl-toggle="sectorSuperhigh" checked> <?= __('playbook.legendSectorSuperhigh') ?></label>
            <label class="pb-legend-toggle"><input type="checkbox" data-hl-toggle="sectorHigh" checked> <?= __('playbook.legendSectorHigh') ?></label>
            <label class="pb-legend-toggle"><input type="checkbox" data-hl-toggle="sectorLow" checked> <?= __('playbook.legendSectorLow') ?></label>
        </div>
    </div>

    <!-- Floating catalog overlay (inside map section) -->
    <div class="pb-catalog-overlay" id="pb_catalog_overlay">
        <div class="pb-overlay-titlebar" id="pb_catalog_titlebar">
            <span class="pb-overlay-title">
                <i class="fas fa-book" style="color:#239BCD;"></i>
                <?= __('playbook.title') ?>
                <span class="pb-catalog-stats" id="pb_stats"></span>
            </span>
            <div class="pb-overlay-controls">
                <?php if ($perm): ?>
                <button id="pb_create_btn" title="<?= __('playbook.createPlay') ?>">
                    <i class="fas fa-plus" style="color:#28a745;"></i>
                </button>
                <?php endif; ?>
                <button class="pb-overlay-minimize" id="pb_catalog_minimize" title="Minimize">
                    <i class="fas fa-chevron-up"></i>
                </button>
            </div>
        </div>
        <div class="pb-catalog-body" id="pb_catalog_body">
            <div class="pb-catalog-header" id="pb_catalog_header">
                <div class="d-flex align-items-center" style="gap:0.3rem;">
                    <input type="text" id="pb_search" class="form-control form-control-sm pb-search"
                           placeholder="<?= __('playbook.searchPlaceholder') ?>">
                    <button class="btn btn-sm btn-link pb-search-help-btn" id="pb_search_help" title="<?= __('playbook.searchHelp.title') ?>">
                        <i class="fas fa-question-circle"></i>
                    </button>
                </div>
                <div class="pb-filter-badges" id="pb_filter_badges"></div>
                <div class="d-flex align-items-center mt-1" style="gap:0.25rem;flex-wrap:wrap;">
                    <div class="btn-group btn-group-sm" role="group">
                        <button class="btn btn-outline-secondary pb-src-btn active" data-source=""><?= __('common.all') ?></button>
                        <button class="btn btn-outline-secondary pb-src-btn" data-source="FAA">FAA</button>
                        <button class="btn btn-outline-secondary pb-src-btn" data-source="DCC">DCC</button>
                        <button class="btn btn-outline-secondary pb-src-btn" data-source="ECFMP">ECFMP</button>
                        <button class="btn btn-outline-secondary pb-src-btn" data-source="CANOC">CANOC</button>
                        <button class="btn btn-outline-secondary pb-src-btn" data-source="FAA_HISTORICAL">Legacy</button>
                    </div>
                    <label class="pb-legacy-toggle mb-0" title="<?= __('playbook.showLegacy') ?>">
                        <input type="checkbox" id="pb_legacy_toggle">
                        <span class="small"><?= __('playbook.showLegacy') ?></span>
                    </label>
                </div>
            </div>
            <div class="pb-pills" id="pb_category_pills"></div>
            <div class="pb-play-list-wrap" id="pb_play_list_wrap">
                <div id="pb_play_list_container">
                    <div class="pb-loading">
                        <div class="spinner-border text-primary" role="status"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Play info overlay (right side, hidden until a play is selected) -->
    <div class="pb-info-overlay" id="pb_info_overlay" style="display:none;">
        <div class="pb-overlay-titlebar" id="pb_info_titlebar">
            <span class="pb-overlay-title" id="pb_info_title"></span>
            <div class="pb-overlay-controls">
                <button class="pb-overlay-minimize" id="pb_info_minimize" title="Minimize">
                    <i class="fas fa-chevron-up"></i>
                </button>
                <button id="pb_info_close" title="Close">
                    <i class="fas fa-times"></i>
                </button>
            </div>
        </div>
        <div class="pb-info-content" id="pb_info_content"></div>
    </div>
</div>

<!-- Detail panel (full width below map) -->
<div class="container-fluid pb-page px-2 px-lg-3 pb-2">
    <div class="pb-detail-panel" id="pb_detail_panel">
        <div id="pb_detail_content">
            <div class="pb-detail-placeholder">
                <i class="fas fa-hand-pointer"></i>
                <div><?= __('playbook.selectPlayPrompt') ?></div>
            </div>
        </div>
    </div>

    <!-- Route Analysis Panel (collapsible, shown when route clicked) -->
    <div class="card mt-2 d-none" id="route-analysis-panel">
        <div class="card-header py-1 px-2 d-flex justify-content-between align-items-center cursor-pointer" data-toggle="collapse" data-target="#route-analysis-body">
            <span class="font-weight-bold small">
                <i class="fas fa-chart-line mr-1"></i>
                <span data-i18n="playbook.analysis.title">Route Analysis</span>
            </span>
            <div>
                <span class="badge badge-info mr-2" id="analysis-total-dist"></span>
                <span class="badge badge-secondary" id="analysis-total-time"></span>
                <button class="btn btn-sm btn-link p-0 ml-2" id="btn-close-analysis" title="Close">
                    <i class="fas fa-times"></i>
                </button>
            </div>
        </div>
        <div class="collapse show" id="route-analysis-body">
            <div class="card-body p-2">
                <!-- Speed/Wind Config (collapsed by default) -->
                <div class="mb-2">
                    <a class="small text-muted" data-toggle="collapse" href="#analysis-speed-config">
                        <i class="fas fa-cog mr-1"></i><span data-i18n="playbook.analysis.speedConfig">Speed &amp; Wind Settings</span>
                    </a>
                    <div class="collapse mt-1" id="analysis-speed-config">
                        <div class="row no-gutters">
                            <div class="col-3 pr-1">
                                <label class="small mb-0" data-i18n="playbook.analysis.climbSpeed">Climb (kts)</label>
                                <input type="number" class="form-control form-control-sm" id="analysis-climb-kts" value="280" min="100" max="600">
                            </div>
                            <div class="col-3 pr-1">
                                <label class="small mb-0" data-i18n="playbook.analysis.cruiseSpeed">Cruise (kts)</label>
                                <input type="number" class="form-control form-control-sm" id="analysis-cruise-kts" value="460" min="100" max="600">
                            </div>
                            <div class="col-3 pr-1">
                                <label class="small mb-0" data-i18n="playbook.analysis.descentSpeed">Descent (kts)</label>
                                <input type="number" class="form-control form-control-sm" id="analysis-descent-kts" value="250" min="100" max="600">
                            </div>
                            <div class="col-3">
                                <label class="small mb-0" data-i18n="playbook.analysis.windComponent">Wind (kts)</label>
                                <input type="number" class="form-control form-control-sm" id="analysis-wind-kts" value="0" min="-200" max="200">
                            </div>
                        </div>
                    </div>
                </div>
                <!-- Route Info -->
                <div class="small mb-1" id="analysis-route-info"></div>
                <!-- Facility Traversal Table -->
                <div class="table-responsive" style="max-height:250px;overflow-y:auto">
                    <table class="table table-sm table-dark table-bordered mb-0 small" id="analysis-traversal-table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th data-i18n="playbook.analysis.facilityType">Type</th>
                                <th data-i18n="playbook.analysis.facilityId">ID</th>
                                <th data-i18n="playbook.analysis.facilityName">Name</th>
                                <th data-i18n="playbook.analysis.distWithin">Dist (nm)</th>
                                <th data-i18n="playbook.analysis.timeWithin">Time (min)</th>
                                <th data-i18n="playbook.analysis.entryDist">Entry (nm)</th>
                                <th data-i18n="playbook.analysis.exitDist">Exit (nm)</th>
                            </tr>
                        </thead>
                        <tbody></tbody>
                    </table>
                </div>
                <!-- Fix Analysis (collapsible) -->
                <div class="mt-1">
                    <a class="small text-muted" data-toggle="collapse" href="#analysis-fix-detail">
                        <i class="fas fa-map-pin mr-1"></i><span data-i18n="playbook.analysis.fixDetail">Fix Detail</span>
                    </a>
                    <div class="collapse mt-1" id="analysis-fix-detail">
                        <div class="table-responsive" style="max-height:200px;overflow-y:auto">
                            <table class="table table-sm table-dark table-bordered mb-0 small" id="analysis-fix-table">
                                <thead>
                                    <tr>
                                        <th data-i18n="playbook.analysis.fix">Fix</th>
                                        <th data-i18n="playbook.analysis.distFromOrig">From Orig</th>
                                        <th data-i18n="playbook.analysis.distToDest">To Dest</th>
                                        <th data-i18n="playbook.analysis.timeFromOrig">Time+</th>
                                        <th data-i18n="playbook.analysis.timeToDest">Time-</th>
                                        <th data-i18n="playbook.analysis.facility">Facility</th>
                                    </tr>
                                </thead>
                                <tbody></tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Changelog Panel (shown via tab/button) -->
    <div class="card mt-2 d-none" id="play-changelog-panel">
        <div class="card-header py-1 px-2 d-flex justify-content-between align-items-center">
            <span class="font-weight-bold small">
                <i class="fas fa-history mr-1"></i>
                <span data-i18n="playbook.changelog.title">Change History</span>
                <span class="badge badge-pill badge-info ml-1" id="changelog-count">0</span>
            </span>
            <button class="btn btn-sm btn-link p-0" id="btn-close-changelog" title="Close">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <div class="card-body p-2">
            <div class="table-responsive" style="max-height:300px;overflow-y:auto">
                <table class="table table-sm table-dark table-bordered mb-0 small" id="changelog-table">
                    <thead>
                        <tr>
                            <th data-i18n="playbook.changelog.time">Time</th>
                            <th data-i18n="playbook.changelog.author">Author</th>
                            <th data-i18n="playbook.changelog.action">Action</th>
                            <th data-i18n="playbook.changelog.field">Field</th>
                            <th data-i18n="playbook.changelog.oldValue">Old</th>
                            <th data-i18n="playbook.changelog.newValue">New</th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>
            <div class="text-center mt-1">
                <button class="btn btn-sm btn-outline-secondary d-none" id="changelog-load-more" data-i18n="common.loadMore">Load More</button>
            </div>
        </div>
    </div>
</div>

<!-- Create/Edit Play Modal -->
<div class="modal fade pb-modal" id="pb_play_modal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="pb_modal_title"><?= __('playbook.createPlay') ?></h5>
                <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="pb_edit_play_id" value="0">

                <!-- Collapsible: Play Information -->
                <div class="pb-edit-section">
                    <div class="pb-edit-section-header" data-toggle="collapse" data-target="#pb_section_info" aria-expanded="true">
                        <i class="fas fa-chevron-down pb-section-icon"></i>
                        <?= __('playbook.playInformation') ?>
                    </div>
                    <div class="collapse show" id="pb_section_info">
                        <div class="pb-edit-section-body">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label><?= __('playbook.playName') ?></label>
                                        <input type="text" id="pb_edit_play_name" class="form-control form-control-sm"
                                               placeholder="<?= __('playbook.playNamePlaceholder') ?>">
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label><?= __('playbook.displayName') ?></label>
                                        <input type="text" id="pb_edit_display_name" class="form-control form-control-sm"
                                               placeholder="<?= __('playbook.displayNamePlaceholder') ?>">
                                    </div>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-4">
                                    <div class="form-group">
                                        <label><?= __('playbook.category') ?></label>
                                        <select id="pb_edit_category" class="form-control form-control-sm">
                                            <option value="">-- Select --</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="form-group">
                                        <label><?= __('playbook.scenarioType') ?></label>
                                        <select id="pb_edit_scenario_type" class="form-control form-control-sm">
                                            <option value=""><?= __('common.none') ?></option>
                                            <option value="WEATHER"><?= __('playbook.scenarioWeather') ?></option>
                                            <option value="VOLUME"><?= __('playbook.scenarioVolume') ?></option>
                                            <option value="CONSTRUCTION"><?= __('playbook.scenarioConstruction') ?></option>
                                            <option value="GENERAL"><?= __('playbook.scenarioGeneral') ?></option>
                                        </select>
                                    </div>
                                </div>
                            </div>

                            <div class="form-group">
                                <label><?= __('playbook.description') ?></label>
                                <textarea id="pb_edit_description" class="form-control form-control-sm" rows="2"></textarea>
                            </div>

                            <div class="form-group">
                                <label><?= __('playbook.remarks') ?></label>
                                <textarea id="pb_edit_remarks" class="form-control form-control-sm" rows="2" placeholder="Notes for TMU personnel..."></textarea>
                            </div>

                            <div class="row">
                                <div class="col-md-4">
                                    <div class="form-group">
                                        <label><?= __('playbook.status') ?></label>
                                        <select id="pb_edit_status" class="form-control form-control-sm">
                                            <option value="active"><?= __('playbook.statusActive') ?></option>
                                            <option value="draft"><?= __('playbook.statusDraft') ?></option>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="form-group">
                                        <label>Source</label>
                                        <select id="pb_edit_source" class="form-control form-control-sm">
                                            <option value="DCC">DCC</option>
                                            <option value="ECFMP">ECFMP</option>
                                            <option value="CANOC">CANOC</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="form-group">
                                        <label><?= __('playbook.visibility.label') ?></label>
                                        <select id="pb_edit_visibility" class="form-control form-control-sm">
                                            <option value="public"><?= __('playbook.visibility.public') ?></option>
                                            <option value="local"><?= __('playbook.visibility.local') ?></option>
                                            <option value="private_users"><?= __('playbook.visibility.privateUsers') ?></option>
                                            <option value="private_org"><?= __('playbook.visibility.privateOrg') ?></option>
                                        </select>
                                        <small class="form-text text-muted pb-visibility-desc" id="pb_visibility_desc"></small>
                                    </div>
                                </div>
                            </div>

                            <!-- ACL Management (shown for private visibility modes) -->
                            <div id="pb_acl_section" style="display:none;">
                                <div class="pb-acl-panel">
                                    <div class="pb-acl-header">
                                        <strong><?= __('playbook.acl.title') ?></strong>
                                        <small class="text-muted ml-2"><?= __('playbook.acl.ownerNote') ?></small>
                                    </div>
                                    <div class="pb-acl-add-row">
                                        <input type="text" id="pb_acl_add_cid" class="form-control form-control-sm"
                                               placeholder="<?= __('playbook.acl.addUserPlaceholder') ?>" style="max-width:120px;">
                                        <button class="btn btn-sm btn-outline-primary" id="pb_acl_add_btn">
                                            <i class="fas fa-plus mr-1"></i><?= __('playbook.acl.addUserBtn') ?>
                                        </button>
                                        <button class="btn btn-sm btn-outline-secondary" id="pb_acl_bulk_btn">
                                            <i class="fas fa-users mr-1"></i><?= __('playbook.acl.bulkAdd') ?>
                                        </button>
                                    </div>
                                    <div id="pb_acl_bulk_area" style="display:none;" class="mb-2">
                                        <input type="text" id="pb_acl_bulk_input" class="form-control form-control-sm"
                                               placeholder="<?= __('playbook.acl.bulkAddPlaceholder') ?>">
                                        <button class="btn btn-sm btn-primary mt-1" id="pb_acl_bulk_apply">
                                            <i class="fas fa-check mr-1"></i><?= __('common.apply') ?>
                                        </button>
                                    </div>
                                    <div id="pb_acl_list" class="pb-acl-list"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Collapsible: Route Strings -->
                <div class="pb-edit-section">
                    <div class="pb-edit-section-header" data-toggle="collapse" data-target="#pb_section_routes" aria-expanded="true">
                        <i class="fas fa-chevron-down pb-section-icon"></i>
                        <?= __('playbook.routes') ?>
                    </div>
                    <div class="collapse show" id="pb_section_routes">
                        <div class="pb-edit-section-body">
                            <div class="d-flex justify-content-end mb-2">
                                <button class="btn btn-sm btn-outline-warning mr-1" id="pb_parse_advisory_btn">
                                    <i class="fas fa-file-import mr-1"></i><?= __('playbook.parseAdvisory') ?>
                                </button>
                                <button class="btn btn-sm btn-outline-info mr-1" id="pb_bulk_paste_btn">
                                    <i class="fas fa-paste mr-1"></i><?= __('playbook.bulkPaste') ?>
                                </button>
                                <button class="btn btn-sm btn-outline-secondary mr-1" id="pb_auto_filters_btn" title="<?= __('playbook.autoFiltersDesc') ?>">
                                    <i class="fas fa-filter mr-1"></i><?= __('playbook.autoFilters') ?>
                                </button>
                                <button class="btn btn-sm btn-outline-success" id="pb_add_route_btn">
                                    <i class="fas fa-plus mr-1"></i><?= __('playbook.addRoute') ?>
                                </button>
                            </div>
                            <!-- Bulk paste area (hidden by default) -->
                            <div id="pb_bulk_paste_area" style="display:none;" class="mb-2">
                                <textarea id="pb_bulk_paste_text" class="form-control form-control-sm" rows="4"
                                          placeholder="<?= __('playbook.bulkPasteHint') ?>" style="font-family:'Inconsolata',monospace;"></textarea>
                                <button class="btn btn-sm btn-info mt-1" id="pb_bulk_paste_apply">
                                    <i class="fas fa-check mr-1"></i><?= __('common.apply') ?>
                                </button>
                            </div>
                            <!-- Advisory parse area (hidden by default) -->
                            <div id="pb_advisory_parse_area" style="display:none;" class="mb-2">
                                <textarea id="pb_advisory_parse_text" class="form-control form-control-sm" rows="8"
                                          placeholder="<?= __('playbook.advisoryParseHint') ?>" style="font-family:'Inconsolata',monospace; white-space:pre;"></textarea>
                                <div class="d-flex align-items-center mt-1">
                                    <button class="btn btn-sm btn-warning mr-2" id="pb_advisory_parse_apply">
                                        <i class="fas fa-cogs mr-1"></i><?= __('playbook.parseRoutes') ?>
                                    </button>
                                    <small class="text-muted"><?= __('playbook.advisoryParseHelp') ?></small>
                                </div>
                            </div>
                            <div class="table-responsive">
                                <table class="pb-route-edit-table" id="pb_route_edit_table">
                                    <thead>
                                        <tr>
                                            <th><?= __('playbook.origin') ?></th>
                                            <th><?= __('playbook.originFilter') ?></th>
                                            <th><?= __('playbook.destination') ?></th>
                                            <th><?= __('playbook.destFilter') ?></th>
                                            <th class="pb-re-th-route"><?= __('playbook.routeString') ?></th>
                                            <th class="pb-re-th-remarks" title="<?= __('playbook.remarks') ?>"><i class="fas fa-sticky-note"></i></th>
                                            <th class="pb-re-th-action"></th>
                                        </tr>
                                    </thead>
                                    <tbody id="pb_route_edit_body"></tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary btn-sm" data-dismiss="modal"><?= __('common.cancel') ?></button>
                <button type="button" class="btn btn-primary btn-sm" id="pb_save_play_btn">
                    <i class="fas fa-save mr-1"></i><?= __('common.save') ?>
                </button>
            </div>
        </div>
    </div>
</div>

<?php
include('load/footer.php');
?>

<!-- Map Scripts -->
<script src="assets/js/awys.js<?= _v('assets/js/awys.js') ?>"></script>
<script src="assets/js/procs_enhanced.js<?= _v('assets/js/procs_enhanced.js') ?>"></script>
<script src="assets/js/route-symbology.js<?= _v('assets/js/route-symbology.js') ?>"></script>
<script src="assets/js/playbook-cdr-search.js<?= _v('assets/js/playbook-cdr-search.js') ?>"></script>
<script src="assets/js/lib/artcc-hierarchy.js<?= _v('assets/js/lib/artcc-hierarchy.js') ?>"></script>
<script src="assets/js/lib/route-advisory-parser.js<?= _v('assets/js/lib/route-advisory-parser.js') ?>"></script>
<script src="assets/js/route-maplibre.js<?= _v('assets/js/route-maplibre.js') ?>"></script>
<script src="assets/js/playbook-dcc-loader.js<?= _v('assets/js/playbook-dcc-loader.js') ?>"></script>

<!-- Playbook Module -->
<script>
window.PERTI_PLAYBOOK_PERM = <?= $perm ? 'true' : 'false' ?>;
window.PERTI_PLAYBOOK_CID = <?= $pb_cid !== null ? $pb_cid : 'null' ?>;
window.PERTI_PLAYBOOK_ADMIN = <?= $pb_admin ? 'true' : 'false' ?>;
</script>
<script src="assets/js/playbook.js<?= _v('assets/js/playbook.js') ?>"></script>

</body>
</html>
