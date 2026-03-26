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
    <link rel="stylesheet" href="assets/css/route-analysis.css<?= _v('assets/css/route-analysis.css') ?>">
    <!-- MapLibre GL JS -->
    <script>window.PERTI_USE_MAPLIBRE = true;</script>
    <link href="https://unpkg.com/maplibre-gl@4.5.0/dist/maplibre-gl.css" rel="stylesheet" />
    <script src="https://unpkg.com/maplibre-gl@4.5.0/dist/maplibre-gl.js"></script>
    <!-- ECharts CDN -->
    <script src="https://cdn.jsdelivr.net/npm/echarts@5.4.3/dist/echarts.min.js"></script>
</head>
<body class="routes-page">
<?php include("load/nav.php"); ?>

<div class="routes-container">
    <!-- Left Panel: Filters + Route List -->
    <div class="routes-left-panel">
        <!-- Filter Panel Header (always visible) -->
        <div class="routes-filters-header">
            <h5><?= __('routes.filters.title') ?></h5>
            <button id="routes_filters_toggle" class="routes-filters-toggle" title="<?= __('common.collapse') ?? 'Collapse' ?>">
                <i class="fas fa-chevron-up"></i>
            </button>
        </div>
        <!-- Filter Accordion (collapsible) -->
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

                        <!-- DCC Region quick-select pills -->
                        <div class="routes-filter-group">
                            <label class="routes-filter-label"><?= __('routes.filters.dccRegion') ?></label>
                            <div class="routes-region-target-toggle">
                                <span class="routes-region-target" data-target="origin"><?= __('routes.filters.regionTargetOrigin') ?></span>
                                <span class="routes-region-target active" data-target="dest"><?= __('routes.filters.regionTargetDest') ?></span>
                            </div>
                            <div class="routes-region-pills" id="dcc_region_pills"></div>
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

        <!-- Search Row (collapsible with filters) -->
        <div class="routes-search-row">
            <button id="routes_search_btn" class="routes-search-btn">
                <i class="fas fa-search"></i> <?= __('routes.filters.search') ?>
            </button>
            <button id="routes_clear_btn" class="routes-clear-btn" style="display: none;">
                <i class="fas fa-times"></i> <?= __('routes.filters.clearAll') ?>
            </button>
        </div>

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
        <!-- RouteAnalysisPanel container (shown only on Analysis tab) -->
        <div id="routes_analysis_container" style="display:none; overflow-y: auto; height: calc(100% - 40px);">
            <div id="route-analysis-panel" style="display:none;">
                <div class="ra-header" id="ra-toggle">
                    <span class="ra-title"><i class="fas fa-chart-line mr-2"></i>Route Analysis</span>
                    <span id="ra-route-label" class="ra-route-label"></span>
                    <div class="ra-controls">
                        <div class="ra-speed-group">
                            <label for="ra-cruise-speed">Speed</label>
                            <input type="number" class="ra-speed-input" id="ra-cruise-speed" value="460" min="100" max="600" step="10">
                            <span class="ra-speed-sep">|</span>
                            <label for="ra-wind">Wind</label>
                            <input type="number" class="ra-speed-input" id="ra-wind" value="0" min="-200" max="200" step="5">
                            <span class="ra-speed-sep">|</span>
                            <label for="ra-dep-time">Dep Time</label>
                            <input type="text" class="ra-speed-input ra-dep-time-input" id="ra-dep-time" placeholder="Now" maxlength="5" title="Departure time in UTC (HH:MM)">
                            <button class="ra-recalc-btn" id="ra-recalc-btn" title="Recalculate"><i class="fas fa-sync-alt"></i></button>
                            <button class="ra-recalc-btn" id="ra-time-fmt-btn" title="Toggle time format"><i class="fas fa-clock"></i> <span id="ra-time-fmt-label">hh:mm:ss</span></button>
                        </div>
                        <div class="ra-export-dropdown">
                            <button class="ra-export-btn" id="ra-export-btn"><i class="fas fa-download mr-1"></i>Export</button>
                            <div class="ra-export-menu" id="ra-export-menu">
                                <a href="#" id="ra-exp-clipboard"><i class="fas fa-clipboard"></i> Clipboard</a>
                                <a href="#" id="ra-exp-txt"><i class="fas fa-file-alt"></i> TXT</a>
                                <a href="#" id="ra-exp-csv"><i class="fas fa-file-csv"></i> CSV</a>
                                <a href="#" id="ra-exp-xlsx"><i class="fas fa-file-excel"></i> XLSX</a>
                            </div>
                        </div>
                    </div>
                    <i class="fas fa-chevron-up ra-chevron"></i>
                </div>
                <div id="ra-body">
                    <div class="ra-route-picker" id="ra-route-picker" style="display:none;">
                        <div class="ra-picker-row">
                            <input type="text" class="ra-picker-input ra-picker-icao" id="ra-picker-origin" placeholder="Origin" maxlength="4">
                            <span class="ra-picker-arrow">&rarr;</span>
                            <input type="text" class="ra-picker-input ra-picker-icao" id="ra-picker-dest" placeholder="Dest" maxlength="4">
                            <input type="text" class="ra-picker-input ra-picker-route" id="ra-picker-route" placeholder="Route string">
                            <button class="ra-picker-go-btn" id="ra-picker-go" title="Analyze"><i class="fas fa-search"></i> Analyze</button>
                        </div>
                        <div class="ra-picker-matches" id="ra-picker-matches"></div>
                    </div>
                    <div class="ra-summary" id="ra-summary"></div>
                    <div class="ra-table-section ra-table-full">
                        <div class="ra-table-title">Facility Traversal</div>
                        <div class="ra-facility-filters" id="ra-facility-filters"></div>
                        <div class="ra-table-wrap">
                            <table class="ra-table">
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>Facility</th>
                                        <th>Type</th>
                                        <th class="text-right">Dist (nm)</th>
                                        <th class="text-right">Time</th>
                                        <th class="text-right">Entry (Z)</th>
                                        <th class="text-right">Exit (Z)</th>
                                        <th class="text-right">Segment</th>
                                    </tr>
                                </thead>
                                <tbody id="ra-facility-tbody"></tbody>
                            </table>
                        </div>
                    </div>
                    <div class="ra-tables">
                        <div class="ra-table-section">
                            <div class="ra-table-title">Fix Analysis</div>
                            <div class="ra-table-wrap">
                                <table class="ra-table">
                                    <thead>
                                        <tr>
                                            <th>#</th>
                                            <th>Fix</th>
                                            <th class="text-right">Cum Dist</th>
                                            <th class="text-right">Cum Time</th>
                                            <th class="text-right">ETA (Z)</th>
                                            <th class="text-right">Seg Dist</th>
                                            <th class="text-right">Seg Time</th>
                                            <th class="text-right">Rem Dist</th>
                                            <th class="text-right">Rem Time</th>
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
                                            <th class="text-right">Dist (nm)</th>
                                            <th class="text-right">Time</th>
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
        </div>
    </div>
</div>

<?php include("load/footer.php"); ?>
<script src="assets/js/routes-map.js<?= _v('assets/js/routes-map.js') ?>"></script>
<script src="assets/js/route-analysis-panel.js<?= _v('assets/js/route-analysis-panel.js') ?>"></script>
<script src="assets/js/routes.js<?= _v('assets/js/routes.js') ?>"></script>
</body>
</html>
