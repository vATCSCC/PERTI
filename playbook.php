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

    // Check Perms
    $perm = false;
    if (!defined('DEV')) {
        if (isset($_SESSION['VATSIM_CID'])) {
            $cid = session_get('VATSIM_CID', '');
            $p_check = $conn_sqli->query("SELECT * FROM users WHERE cid='$cid'");
            if ($p_check) {
                $perm = true;
            }
        }
    } else {
        $perm = true;
        $_SESSION['VATSIM_FIRST_NAME'] = $_SESSION['VATSIM_LAST_NAME'] = $_SESSION['VATSIM_CID'] = 0;
    }
?>
<!DOCTYPE html>
<html>
<head>
    <?php
        $page_title = "vATCSCC Playbook";
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

<!-- Map hero (full-width, dark bg provides contrast for floating navbar) -->
<div class="pb-map-section" id="pb_map_section">
    <textarea id="routeSearch" style="display:none;"></textarea>
    <button id="plot_r" style="display:none;"></button>
    <div id="map_wrapper">
        <div id="placeholder"></div>
        <div id="graphic"></div>
    </div>
</div>

<div class="container-fluid pb-page px-2 px-lg-3 pb-2">

    <!-- Catalog Header: title + search + category pills -->
    <div class="pb-catalog-header" id="pb_catalog_header">
        <div class="d-flex justify-content-between align-items-center">
            <div class="d-flex align-items-center" style="gap:0.5rem;">
                <span class="pb-title">
                    <i class="fas fa-book" style="color:#239BCD;"></i>
                    <?= __('playbook.title') ?>
                </span>
                <span class="pb-catalog-stats" id="pb_stats"></span>
            </div>
            <div class="d-flex align-items-center" style="gap:0.35rem;">
                <input type="text" id="pb_search" class="form-control form-control-sm pb-search"
                       placeholder="<?= __('playbook.searchPlaceholder') ?>">
                <div class="btn-group btn-group-sm" role="group">
                    <button class="btn btn-outline-secondary pb-src-btn active" data-source=""><?= __('common.all') ?></button>
                    <button class="btn btn-outline-secondary pb-src-btn" data-source="FAA">FAA</button>
                    <button class="btn btn-outline-secondary pb-src-btn" data-source="DCC">DCC</button>
                    <button class="btn btn-outline-secondary pb-src-btn" data-source="ECFMP">ECFMP</button>
                    <button class="btn btn-outline-secondary pb-src-btn" data-source="CANOC">CANOC</button>
                </div>
                <label class="pb-legacy-toggle mb-0" title="<?= __('playbook.showLegacy') ?>">
                    <input type="checkbox" id="pb_legacy_toggle">
                    <span class="small"><?= __('playbook.showLegacy') ?></span>
                </label>
                <?php if ($perm): ?>
                <button class="btn btn-sm btn-success" id="pb_create_btn" title="<?= __('playbook.createPlay') ?>">
                    <i class="fas fa-plus"></i>
                </button>
                <?php endif; ?>
            </div>
        </div>

        <!-- Category pills -->
        <div class="pb-pills" id="pb_category_pills"></div>
    </div>

    <!-- Two-column master-detail layout -->
    <div class="pb-columns">
        <!-- Left: Play List -->
        <div class="pb-col-left">
            <div class="pb-play-list-wrap" id="pb_play_list_wrap">
                <div id="pb_play_list_container">
                    <div class="pb-loading">
                        <div class="spinner-border text-primary" role="status"></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Right: Detail Panel (always visible, placeholder when empty) -->
        <div class="pb-col-right" id="pb_detail_panel">
            <div id="pb_detail_content">
                <div class="pb-detail-placeholder">
                    <i class="fas fa-hand-pointer"></i>
                    <div><?= __('playbook.selectPlayPrompt') ?></div>
                </div>
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
                    <div class="col-md-4">
                        <div class="form-group">
                            <label><?= __('playbook.routeFormat') ?></label>
                            <select id="pb_edit_route_format" class="form-control form-control-sm">
                                <option value="standard"><?= __('playbook.formatStandard') ?></option>
                                <option value="split"><?= __('playbook.formatSplit') ?></option>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="form-group">
                    <label><?= __('playbook.description') ?></label>
                    <textarea id="pb_edit_description" class="form-control form-control-sm" rows="2"></textarea>
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
                </div>

                <hr>
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <strong style="font-size:0.9rem;"><?= __('playbook.routes') ?></strong>
                    <div>
                        <button class="btn btn-sm btn-outline-info mr-1" id="pb_bulk_paste_btn">
                            <i class="fas fa-paste mr-1"></i><?= __('playbook.bulkPaste') ?>
                        </button>
                        <button class="btn btn-sm btn-outline-success" id="pb_add_route_btn">
                            <i class="fas fa-plus mr-1"></i><?= __('playbook.addRoute') ?>
                        </button>
                    </div>
                </div>
                <!-- Bulk paste area (hidden by default) -->
                <div id="pb_bulk_paste_area" style="display:none;" class="mb-2">
                    <textarea id="pb_bulk_paste_text" class="form-control form-control-sm" rows="4"
                              placeholder="<?= __('playbook.bulkPasteHint') ?>" style="font-family:'Inconsolata',monospace;"></textarea>
                    <button class="btn btn-sm btn-info mt-1" id="pb_bulk_paste_apply">
                        <i class="fas fa-check mr-1"></i><?= __('common.apply') ?>
                    </button>
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
                                <th style="width:100px;">Remarks</th>
                                <th style="width:32px;"></th>
                            </tr>
                        </thead>
                        <tbody id="pb_route_edit_body"></tbody>
                    </table>
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
<script src="assets/js/route-maplibre.js<?= _v('assets/js/route-maplibre.js') ?>"></script>
<script src="assets/js/playbook-dcc-loader.js<?= _v('assets/js/playbook-dcc-loader.js') ?>"></script>

<!-- Playbook Module -->
<script>
window.PERTI_PLAYBOOK_PERM = <?= $perm ? 'true' : 'false' ?>;
</script>
<script src="assets/js/playbook.js<?= _v('assets/js/playbook.js') ?>"></script>

</body>
</html>
