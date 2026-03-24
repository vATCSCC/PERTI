<?php
/**
 * Historical Routes Page
 * Search and analyze historically filed flight plan routes
 */
include("sessions/handler.php");
include("load/config.php");
include("load/i18n.php");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php $page_title = __('routes.title'); include("load/header.php"); ?>
    <link rel="stylesheet" href="assets/css/routes.css<?= _v('assets/css/routes.css') ?>">
    <!-- MapLibre GL JS -->
    <script>window.PERTI_USE_MAPLIBRE = true;</script>
    <link href="https://unpkg.com/maplibre-gl@4.5.0/dist/maplibre-gl.css" rel="stylesheet" />
    <script src="https://unpkg.com/maplibre-gl@4.5.0/dist/maplibre-gl.js"></script>
    <!-- ECharts CDN -->
    <script src="https://cdn.jsdelivr.net/npm/echarts@5.4.3/dist/echarts.min.js"></script>
</head>
<body>
<?php include("load/nav.php"); ?>

<div class="routes-container">
    <!-- Left Panel: Filters + Route List -->
    <div class="routes-left-panel">
        <!-- Filter Accordion -->
        <div class="routes-filters">
            <!-- Location Filter Section -->
            <div class="routes-filter-section">
                <div class="routes-filter-header" data-toggle="collapse" data-target="#filter_location" aria-expanded="true">
                    <h5><?= __('routes.filters.location') ?></h5>
                    <i class="fas fa-chevron-down"></i>
                </div>
                <div id="filter_location" class="collapse show">
                    <div class="routes-filter-body">
                        <!-- Origin -->
                        <div class="routes-filter-group">
                            <label class="routes-filter-label"><?= __('routes.filters.origin') ?></label>
                            <div class="routes-tags-input" id="origin_tags_container">
                                <input type="text" id="origin_input" placeholder="<?= __('routes.filters.originPlaceholder') ?>" autocomplete="off">
                            </div>
                            <div class="routes-mode-pills">
                                <div class="routes-mode-pill active" data-mode="airport" data-target="origin">
                                    <?= __('routes.filters.modeAirport') ?>
                                </div>
                                <div class="routes-mode-pill" data-mode="tracon" data-target="origin">
                                    <?= __('routes.filters.modeTracon') ?>
                                </div>
                                <div class="routes-mode-pill" data-mode="artcc" data-target="origin">
                                    <?= __('routes.filters.modeArtcc') ?>
                                </div>
                            </div>
                        </div>

                        <!-- Destination -->
                        <div class="routes-filter-group">
                            <label class="routes-filter-label"><?= __('routes.filters.destination') ?></label>
                            <div class="routes-tags-input" id="dest_tags_container">
                                <input type="text" id="dest_input" placeholder="<?= __('routes.filters.destPlaceholder') ?>" autocomplete="off">
                            </div>
                            <div class="routes-mode-pills">
                                <div class="routes-mode-pill active" data-mode="airport" data-target="dest">
                                    <?= __('routes.filters.modeAirport') ?>
                                </div>
                                <div class="routes-mode-pill" data-mode="tracon" data-target="dest">
                                    <?= __('routes.filters.modeTracon') ?>
                                </div>
                                <div class="routes-mode-pill" data-mode="artcc" data-target="dest">
                                    <?= __('routes.filters.modeArtcc') ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Aircraft Filter Section (Stub) -->
            <div class="routes-filter-section">
                <div class="routes-filter-header" data-toggle="collapse" data-target="#filter_aircraft">
                    <h5><?= __('routes.filters.aircraft') ?></h5>
                    <i class="fas fa-chevron-down"></i>
                </div>
                <div id="filter_aircraft" class="collapse">
                    <div class="routes-filter-body">
                        <p style="color: #888; font-size: 0.85rem; text-align: center; padding: 20px;">
                            Aircraft filters coming soon
                        </p>
                    </div>
                </div>
            </div>

            <!-- Operator Filter Section (Stub) -->
            <div class="routes-filter-section">
                <div class="routes-filter-header" data-toggle="collapse" data-target="#filter_operator">
                    <h5><?= __('routes.filters.operator') ?></h5>
                    <i class="fas fa-chevron-down"></i>
                </div>
                <div id="filter_operator" class="collapse">
                    <div class="routes-filter-body">
                        <p style="color: #888; font-size: 0.85rem; text-align: center; padding: 20px;">
                            Operator filters coming soon
                        </p>
                    </div>
                </div>
            </div>

            <!-- Time Period Filter Section (Stub) -->
            <div class="routes-filter-section">
                <div class="routes-filter-header" data-toggle="collapse" data-target="#filter_time">
                    <h5><?= __('routes.filters.timePeriod') ?></h5>
                    <i class="fas fa-chevron-down"></i>
                </div>
                <div id="filter_time" class="collapse">
                    <div class="routes-filter-body">
                        <p style="color: #888; font-size: 0.85rem; text-align: center; padding: 20px;">
                            Time filters coming soon
                        </p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Filter Chips Bar -->
        <div id="routes_filter_chips" class="routes-filter-chips"></div>

        <!-- Search Button -->
        <button id="routes_search_btn" class="routes-search-btn">
            <i class="fas fa-search"></i> <?= __('routes.filters.search') ?>
        </button>

        <!-- Clear All Button -->
        <button id="routes_clear_btn" class="routes-clear-btn" style="display: none;">
            <i class="fas fa-times"></i> <?= __('routes.filters.clearAll') ?>
        </button>

        <!-- Route List -->
        <div id="routes_list" class="routes-list">
            <!-- Empty state shown by default -->
            <div class="routes-empty-state">
                <i class="fas fa-route"></i>
                <h3><?= __('routes.title') ?></h3>
                <p><?= __('routes.results.noFilters') ?></p>
            </div>
        </div>
    </div>

    <!-- Splitter -->
    <div class="routes-splitter" id="routes_splitter"></div>

    <!-- Right Panel: Map -->
    <div class="routes-right-panel">
        <div id="routes_map" style="width: 100%; height: 100%;"></div>
    </div>

    <!-- Bottom Panel: Detail Panel (hidden by default) -->
    <div class="routes-bottom-panel" id="routes_bottom_panel" style="display:none;">
        <div style="padding: 20px; text-align: center; color: #888;">
            Detail panel will appear here when a route is selected
        </div>
    </div>
</div>

<?php include("load/footer.php"); ?>
<script src="assets/js/routes-map.js<?= _v('assets/js/routes-map.js') ?>"></script>
<script src="assets/js/routes.js<?= _v('assets/js/routes.js') ?>"></script>
</body>
</html>
