<?php
include("load/i18n.php");
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

<section class="d-flex align-items-center position-relative bg-position-center overflow-hidden pt-6 jarallax bg-dark text-light" style="min-height: 120px" data-jarallax data-speed="0.3" style="pointer-events: all;">
    <div class="container-fluid pt-2 pb-3">
        <img class="jarallax-img" src="assets/img/jumbotron/main.png" alt="" style="opacity: 50%; height: 100vh;">
    </div>
</section>

<div class="container-fluid mt-3 px-3 px-lg-4 pb-2">

    <div class="row">
        <!-- Left Panel: Catalog Browser -->
        <div class="col-12 col-lg-4 col-xl-3">
            <div class="pb-catalog-panel">
                <div class="pb-catalog-header">
                    <h5 class="mb-2" style="font-weight: 700;">
                        <i class="fas fa-book mr-1" style="color: #239BCD;"></i>
                        <?= __('playbook.title') ?>
                    </h5>

                    <!-- Search -->
                    <div class="pb-search-bar">
                        <input type="text" id="pb_search" class="form-control form-control-sm"
                               placeholder="<?= __('playbook.searchPlaceholder') ?>">
                        <?php if ($perm): ?>
                        <button class="btn btn-sm btn-success" id="pb_create_btn" title="<?= __('playbook.createPlay') ?>">
                            <i class="fas fa-plus"></i>
                        </button>
                        <?php endif; ?>
                    </div>

                    <!-- Filters -->
                    <div class="pb-filter-row">
                        <select id="pb_filter_category" class="form-control form-control-sm">
                            <option value=""><?= __('playbook.allCategories') ?></option>
                        </select>
                        <select id="pb_filter_source" class="form-control form-control-sm">
                            <option value=""><?= __('playbook.allSources') ?></option>
                            <option value="FAA">FAA</option>
                            <option value="DCC">DCC</option>
                        </select>
                        <select id="pb_filter_status" class="form-control form-control-sm">
                            <option value="active"><?= __('playbook.statusActive') ?></option>
                            <option value=""><?= __('common.all') ?></option>
                            <option value="draft"><?= __('playbook.statusDraft') ?></option>
                            <option value="archived"><?= __('playbook.statusArchived') ?></option>
                        </select>
                    </div>

                    <div class="pb-catalog-stats" id="pb_stats"></div>
                </div>

                <!-- Play List -->
                <div id="pb_play_list_container">
                    <div class="pb-loading">
                        <div class="spinner-border text-primary" role="status"></div>
                        <div class="mt-1" style="font-size:0.8rem;"><?= __('common.loading') ?></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Right Panel: Map -->
        <div class="col-12 col-lg-8 col-xl-9 pb-map-section">
            <!-- Hidden textarea + button for route-maplibre integration -->
            <textarea id="routeSearch" style="display:none;"></textarea>
            <button id="plot_r" style="display:none;"></button>

            <!-- Map Container -->
            <div id="map_wrapper">
                <div id="placeholder"></div>
                <div id="graphic"></div>
            </div>
        </div>
    </div>

</div>

<!-- Create/Edit Play Modal -->
<div class="modal fade pb-modal" id="pb_play_modal" tabindex="-1">
    <div class="modal-dialog modal-lg">
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
                            <input type="text" id="pb_edit_category" class="form-control form-control-sm"
                                   placeholder="<?= __('playbook.categoryPlaceholder') ?>">
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

                <div class="form-group">
                    <label><?= __('playbook.status') ?></label>
                    <select id="pb_edit_status" class="form-control form-control-sm" style="max-width:200px;">
                        <option value="active"><?= __('playbook.statusActive') ?></option>
                        <option value="draft"><?= __('playbook.statusDraft') ?></option>
                    </select>
                </div>

                <hr>
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <strong style="font-size:0.9rem;"><?= __('playbook.routes') ?></strong>
                    <button class="btn btn-sm btn-outline-success" id="pb_add_route_btn">
                        <i class="fas fa-plus mr-1"></i><?= __('playbook.addRoute') ?>
                    </button>
                </div>
                <div class="table-responsive">
                    <table class="pb-route-edit-table" id="pb_route_edit_table">
                        <thead>
                            <tr>
                                <th style="width:25%;"><?= __('playbook.routeString') ?></th>
                                <th><?= __('playbook.origin') ?></th>
                                <th><?= __('playbook.originFilter') ?></th>
                                <th><?= __('playbook.destination') ?></th>
                                <th><?= __('playbook.destFilter') ?></th>
                                <th style="width:40px;"></th>
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

<!-- Playbook Module -->
<script src="assets/js/playbook.js<?= _v('assets/js/playbook.js') ?>"></script>

<script>
window.PERTI_PLAYBOOK_PERM = <?= $perm ? 'true' : 'false' ?>;
</script>

</body>
</html>
