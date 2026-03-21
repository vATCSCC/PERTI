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
    <link rel="stylesheet" href="assets/css/route-analysis.css<?= _v('assets/css/route-analysis.css') ?>">
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
                    <button class="btn btn-sm btn-link pb-builder-toggle-btn" id="pb_builder_toggle" title="<?= __('playbook.builder.toggle') ?>">
                        <i class="fas fa-project-diagram"></i>
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

    <!-- Query Builder overlay -->
    <div class="pb-builder-overlay" id="pb_builder_overlay" style="display:none;">
        <div class="pb-overlay-titlebar" id="pb_builder_titlebar">
            <span class="pb-overlay-title">
                <i class="fas fa-project-diagram" style="color:#f0ad4e;"></i>
                <?= __('playbook.builder.title') ?>
            </span>
            <div class="pb-overlay-controls">
                <button class="pb-overlay-minimize" id="pb_builder_minimize" title="Minimize">
                    <i class="fas fa-chevron-up"></i>
                </button>
                <button id="pb_builder_close" title="Close">
                    <i class="fas fa-times"></i>
                </button>
            </div>
        </div>
        <div class="pb-builder-content" id="pb_builder_content">
            <!-- Populated by playbook-query-builder.js -->
        </div>
    </div>
</div>

<!-- Section toggle bar -->
<div class="pb-section-bar" id="pb_section_bar">
    <button class="pb-section-toggle active" id="pb_toggle_map" title="<?= __('playbook.toggleMap') ?>">
        <i class="fas fa-map"></i> <span><?= __('playbook.map') ?></span>
    </button>
    <button class="pb-section-toggle active" id="pb_toggle_routes" title="<?= __('playbook.toggleRoutes') ?>">
        <i class="fas fa-list-alt"></i> <span><?= __('playbook.routeDetail') ?></span>
    </button>
</div>

<!-- Detail panel (full width below map) -->
<div class="container-fluid pb-page px-2 px-lg-3 pb-2" id="pb_detail_section">
    <div class="pb-detail-panel" id="pb_detail_panel">
        <div id="pb_detail_content">
            <div class="pb-detail-placeholder">
                <i class="fas fa-hand-pointer"></i>
                <div><?= __('playbook.selectPlayPrompt') ?></div>
            </div>
        </div>
    </div>

    <!-- Route Analysis Panel (shared module — route-analysis-panel.js) -->
    <div id="route-analysis-panel" class="mt-2" style="display:none;">
        <div class="ra-header" id="ra-toggle">
            <span class="ra-title"><i class="fas fa-chart-line mr-2"></i><?= __('routeAnalysis.title') ?></span>
            <span id="ra-route-label" class="ra-route-label"></span>
            <div class="ra-controls">
                <div class="ra-speed-group">
                    <label for="ra-cruise-speed"><?= __('routeAnalysis.col.speed') ?></label>
                    <input type="number" class="ra-speed-input" id="ra-cruise-speed" value="460" min="100" max="600" step="10">
                    <span class="ra-speed-sep">|</span>
                    <label for="ra-wind"><?= __('routeAnalysis.col.wind') ?></label>
                    <input type="number" class="ra-speed-input" id="ra-wind" value="0" min="-200" max="200" step="5">
                    <span class="ra-speed-sep">|</span>
                    <label for="ra-dep-time"><?= __('routeAnalysis.depTime') ?></label>
                    <input type="text" class="ra-speed-input ra-dep-time-input" id="ra-dep-time"
                           placeholder="Now" maxlength="5"
                           title="<?= __('routeAnalysis.depTimeTitle') ?>">
                    <button class="ra-recalc-btn" id="ra-recalc-btn" title="<?= __('routeAnalysis.recalculate') ?>">
                        <i class="fas fa-sync-alt"></i>
                    </button>
                    <button class="ra-recalc-btn" id="ra-time-fmt-btn" title="Toggle time format">
                        <i class="fas fa-clock"></i> <span id="ra-time-fmt-label">hh:mm:ss</span>
                    </button>
                </div>
                <div class="ra-export-dropdown">
                    <button class="ra-export-btn" id="ra-export-btn">
                        <i class="fas fa-download mr-1"></i><?= __('routeAnalysis.export.title') ?>
                    </button>
                    <div class="ra-export-menu" id="ra-export-menu">
                        <a href="#" id="ra-exp-clipboard"><i class="fas fa-clipboard"></i> <?= __('routeAnalysis.export.clipboard') ?></a>
                        <a href="#" id="ra-exp-txt"><i class="fas fa-file-alt"></i> <?= __('routeAnalysis.export.txt') ?></a>
                        <a href="#" id="ra-exp-csv"><i class="fas fa-file-csv"></i> <?= __('routeAnalysis.export.csv') ?></a>
                        <a href="#" id="ra-exp-xlsx"><i class="fas fa-file-excel"></i> <?= __('routeAnalysis.export.xlsx') ?></a>
                    </div>
                </div>
                <button class="ra-close-btn" id="ra-close-btn" title="<?= __('routeAnalysis.close') ?>">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <i class="fas fa-chevron-up ra-chevron"></i>
        </div>
        <div id="ra-body">
            <div class="ra-route-picker" id="ra-route-picker">
                <div class="ra-picker-row">
                    <input type="text" class="ra-picker-input ra-picker-icao" id="ra-picker-origin" placeholder="Origin" maxlength="4">
                    <span class="ra-picker-arrow">&rarr;</span>
                    <input type="text" class="ra-picker-input ra-picker-icao" id="ra-picker-dest" placeholder="Dest" maxlength="4">
                    <input type="text" class="ra-picker-input ra-picker-route" id="ra-picker-route" placeholder="Route string (or leave blank to use plotted routes)">
                    <button class="ra-picker-go-btn" id="ra-picker-go" title="Analyze">
                        <i class="fas fa-search"></i> Analyze
                    </button>
                </div>
                <div class="ra-picker-matches" id="ra-picker-matches"></div>
            </div>
            <div class="ra-summary" id="ra-summary"></div>
            <div class="ra-table-section ra-table-full">
                <div class="ra-table-title"><?= __('routeAnalysis.facilityTraversal') ?></div>
                <div class="ra-facility-filters" id="ra-facility-filters"></div>
                <div class="ra-table-wrap">
                    <table class="ra-table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th><?= __('routeAnalysis.col.facility') ?></th>
                                <th><?= __('routeAnalysis.col.type') ?></th>
                                <th class="text-right"><?= __('routeAnalysis.col.distNm') ?></th>
                                <th class="text-right"><?= __('routeAnalysis.col.time') ?></th>
                                <th class="text-right"><?= __('routeAnalysis.col.entryUtc') ?></th>
                                <th class="text-right"><?= __('routeAnalysis.col.exitUtc') ?></th>
                                <th class="text-right"><?= __('routeAnalysis.col.segment') ?></th>
                            </tr>
                        </thead>
                        <tbody id="ra-facility-tbody"></tbody>
                    </table>
                </div>
            </div>
            <div class="ra-tables">
                <div class="ra-table-section">
                    <div class="ra-table-title"><?= __('routeAnalysis.fixAnalysis') ?></div>
                    <div class="ra-table-wrap">
                        <table class="ra-table">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th><?= __('routeAnalysis.col.fix') ?></th>
                                    <th class="text-right"><?= __('routeAnalysis.col.cumDist') ?></th>
                                    <th class="text-right"><?= __('routeAnalysis.col.cumTime') ?></th>
                                    <th class="text-right"><?= __('routeAnalysis.col.etaUtc') ?></th>
                                    <th class="text-right"><?= __('routeAnalysis.col.segDist') ?></th>
                                    <th class="text-right"><?= __('routeAnalysis.col.segTime') ?></th>
                                    <th class="text-right"><?= __('routeAnalysis.col.remDist') ?></th>
                                    <th class="text-right"><?= __('routeAnalysis.col.remTime') ?></th>
                                </tr>
                            </thead>
                            <tbody id="ra-fix-tbody"></tbody>
                        </table>
                    </div>
                </div>
                <div class="ra-table-section">
                    <div class="ra-table-title">Segment Analysis</div>
                    <div class="ra-table-wrap">
                        <table class="ra-table">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>From</th>
                                    <th>To</th>
                                    <th class="text-right"><?= __('routeAnalysis.col.distNm') ?></th>
                                    <th class="text-right"><?= __('routeAnalysis.col.time') ?></th>
                                    <th class="text-right">Entry Dist</th>
                                    <th class="text-right">Entry (Z)</th>
                                    <th class="text-right">Exit Dist</th>
                                    <th class="text-right">Exit (Z)</th>
                                    <th class="text-right">GS (kts)</th>
                                </tr>
                            </thead>
                            <tbody id="ra-segment-tbody"></tbody>
                        </table>
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
                                            <option value="FAA">FAA</option>
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

                                    <!-- User search (CID, name, or org) -->
                                    <div class="pb-acl-add-row">
                                        <div class="pb-acl-search-wrap" style="position:relative; flex:1; max-width:280px;">
                                            <input type="text" id="pb_acl_search" class="form-control form-control-sm"
                                                   placeholder="<?= __('playbook.acl.searchPlaceholder') ?>" autocomplete="off">
                                            <div id="pb_acl_search_results" class="pb-acl-search-dropdown" style="display:none;"></div>
                                        </div>
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

                                    <!-- Org sharing (shown only for private_org) -->
                                    <div id="pb_acl_org_section" style="display:none;" class="mb-2">
                                        <div class="pb-acl-org-header">
                                            <strong class="small"><?= __('playbook.acl.orgSharing') ?></strong>
                                        </div>
                                        <div id="pb_acl_org_picker" class="pb-acl-org-picker mb-1"></div>
                                        <div id="pb_acl_org_members" class="pb-acl-org-members" style="display:none;"></div>
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
                                <div class="pb-advisory-fetch mb-2">
                                    <small class="text-muted d-block mb-1"><?= __('playbook.advisoryFetchLabel') ?></small>
                                    <div class="d-flex align-items-center mb-1">
                                        <input type="text" id="pb_advisory_url" class="form-control form-control-sm mr-1"
                                               placeholder="<?= __('playbook.advisoryUrlPlaceholder') ?>" style="flex:1;">
                                        <button class="btn btn-sm btn-warning" id="pb_advisory_fetch_url_btn">
                                            <i class="fas fa-download mr-1"></i><?= __('common.fetch') ?>
                                        </button>
                                    </div>
                                    <div class="d-flex align-items-center">
                                        <small class="text-muted mr-1"><?= __('playbook.advisoryOr') ?></small>
                                        <input type="date" id="pb_advisory_date" class="form-control form-control-sm mr-1" style="width:150px;">
                                        <input type="number" id="pb_advisory_advn" class="form-control form-control-sm mr-1"
                                               placeholder="<?= __('playbook.advisoryAdvnPlaceholder') ?>" style="width:80px;" min="1">
                                        <button class="btn btn-sm btn-warning" id="pb_advisory_fetch_date_btn">
                                            <i class="fas fa-download mr-1"></i><?= __('common.fetch') ?>
                                        </button>
                                    </div>
                                </div>
                                <hr class="my-2" style="border-color:#555;">
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

<!-- Route Analysis Panel (shared module) -->
<script src="assets/js/route-analysis-panel.js<?= _v('assets/js/route-analysis-panel.js') ?>"></script>

<!-- Playbook Filter Parser + Query Builder -->
<script src="assets/js/playbook-filter-parser.js<?= _v('assets/js/playbook-filter-parser.js') ?>"></script>
<script src="assets/js/playbook-query-builder.js<?= _v('assets/js/playbook-query-builder.js') ?>"></script>

<!-- Playbook Module -->
<script>
window.PERTI_PLAYBOOK_PERM = <?= $perm ? 'true' : 'false' ?>;
window.PERTI_PLAYBOOK_CID = <?= $pb_cid !== null ? $pb_cid : 'null' ?>;
window.PERTI_PLAYBOOK_ADMIN = <?= $pb_admin ? 'true' : 'false' ?>;
</script>
<script src="assets/js/playbook.js<?= _v('assets/js/playbook.js') ?>"></script>

</body>
</html>
