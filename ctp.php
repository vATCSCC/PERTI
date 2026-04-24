<?php
include("sessions/handler.php");
include("load/config.php");
include("load/i18n.php");
include("load/connect.php");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php $page_title = __('ctp.page.title'); include("load/header.php"); ?>

    <!-- MapLibre GL JS -->
    <script>window.PERTI_USE_MAPLIBRE = true;</script>
    <link href="https://unpkg.com/maplibre-gl@4.5.0/dist/maplibre-gl.css" rel="stylesheet" />
    <script src="https://unpkg.com/maplibre-gl@4.5.0/dist/maplibre-gl.js"></script>
    <script src="https://unpkg.com/@turf/turf@6/turf.min.js"></script>

    <!-- Info Bar Shared Styles -->
    <link rel="stylesheet" href="assets/css/info-bar.css<?= _v('assets/css/info-bar.css') ?>">
    <!-- CTP Styles -->
    <link rel="stylesheet" href="assets/css/ctp.css<?= _v('assets/css/ctp.css') ?>">
</head>

<body>

<?php include("load/nav.php"); ?>

<!-- CTP Container -->
<div class="ctp-container" id="ctp_container">

    <!-- Top Bar: Session Selector + Stats -->
    <div class="ctp-top-bar">
        <div class="d-flex align-items-center flex-wrap gap-2">
            <!-- Session Selector -->
            <div class="ctp-session-selector mr-2">
                <select class="form-control form-control-sm" id="ctp_session_select" title="<?= __('ctp.session.select') ?>">
                    <option value=""><?= __('ctp.session.selectPlaceholder') ?></option>
                </select>
            </div>
            <button class="btn btn-sm btn-primary mr-3" id="ctp_btn_new_session" title="<?= __('ctp.session.createTitle') ?>">
                <i class="fas fa-plus mr-1"></i><?= __('ctp.session.create') ?>
            </button>

            <!-- Status Badge -->
            <span class="badge badge-secondary ctp-status-badge" id="ctp_status_badge"><?= __('ctp.session.noSession') ?></span>

            <!-- Direction Badge -->
            <span class="badge badge-outline-info ctp-direction-badge d-none" id="ctp_direction_badge"></span>

            <!-- Session Actions -->
            <div class="btn-group btn-group-sm ml-2" id="ctp_session_actions" style="display:none;">
                <button class="btn btn-outline-primary btn-sm" id="ctp_btn_detect" title="<?= __('ctp.flights.detectTooltip') ?>">
                    <i class="fas fa-satellite-dish"></i> <?= __('ctp.flights.detect') ?>
                </button>
                <button class="btn btn-outline-success btn-sm" id="ctp_btn_bulk_edct" title="<?= __('ctp.edct.bulkAssignTooltip') ?>">
                    <i class="fas fa-clock"></i> <?= __('ctp.edct.bulkAssign') ?>
                </button>
                <button class="btn btn-outline-warning btn-sm" id="ctp_btn_auto_assign" title="<?= __('ctp.edct.autoAssignTooltip') ?>">
                    <i class="fas fa-magic"></i> <?= __('ctp.edct.autoAssign') ?>
                </button>
                <button class="btn btn-outline-info btn-sm" id="ctp_btn_check_compliance" title="<?= __('ctp.compliance.checkTooltip') ?>">
                    <i class="fas fa-check-double"></i> <?= __('ctp.compliance.check') ?>
                </button>
                <button class="btn btn-outline-danger btn-sm" id="ctp_btn_exclude" title="<?= __('ctp.exclude.excludeTooltip') ?>">
                    <i class="fas fa-ban"></i> <?= __('ctp.exclude.exclude') ?>
                </button>
                <button class="btn btn-outline-secondary btn-sm" id="ctp_btn_include" title="<?= __('ctp.exclude.includeTooltip') ?>">
                    <i class="fas fa-undo"></i> <?= __('ctp.exclude.include') ?>
                </button>
                <button class="btn btn-outline-secondary btn-sm" id="ctp_btn_session_settings" title="<?= __('ctp.session.settings') ?>">
                    <i class="fas fa-cog"></i>
                </button>
                <button class="btn btn-outline-dark btn-sm" id="ctp_btn_complete_session" title="<?= __('ctp.session.completeTooltip') ?>">
                    <i class="fas fa-flag-checkered"></i> <?= __('ctp.session.complete') ?>
                </button>
                <button class="btn btn-outline-danger btn-sm" id="ctp_btn_cancel_session" title="<?= __('ctp.session.cancelTooltip') ?>">
                    <i class="fas fa-times-circle"></i> <?= __('ctp.session.cancel') ?>
                </button>
            </div>
            <span class="badge badge-success ml-1" id="ctp_compliance_badge" style="display:none;"></span>
        </div>

        <!-- Stats Bar -->
        <div class="ctp-stats-bar" id="ctp_stats_bar" style="display:none;">
            <span class="ctp-stat" id="ctp_stat_total" title="<?= __('ctp.stats.totalTooltip') ?>">
                <i class="fas fa-plane"></i> <span class="ctp-stat-value">0</span> <?= __('ctp.stats.total') ?>
            </span>
            <span class="ctp-stat ctp-stat-slotted" id="ctp_stat_slotted" title="<?= __('ctp.stats.slottedTooltip') ?>">
                <i class="fas fa-clock"></i> <span class="ctp-stat-value">0</span> <?= __('ctp.stats.slotted') ?>
            </span>
            <span class="ctp-stat ctp-stat-modified" id="ctp_stat_modified" title="<?= __('ctp.stats.modifiedTooltip') ?>">
                <i class="fas fa-route"></i> <span class="ctp-stat-value">0</span> <?= __('ctp.stats.modified') ?>
            </span>
            <span class="ctp-stat ctp-stat-excluded" id="ctp_stat_excluded" title="<?= __('ctp.stats.excludedTooltip') ?>">
                <i class="fas fa-ban"></i> <span class="ctp-stat-value">0</span> <?= __('ctp.stats.excluded') ?>
            </span>
        </div>
    </div>

    <!-- Map Section -->
    <div class="ctp-map-section" id="ctp_map_section">
        <div id="ctp_map" class="ctp-map"></div>
        <div class="ctp-map-placeholder" id="ctp_map_placeholder">
            <i class="fas fa-globe-americas fa-3x text-muted mb-2"></i>
            <div class="text-muted"><?= __('ctp.map.placeholder') ?></div>
        </div>
        <!-- Map collapse toggle -->
        <button class="btn btn-sm ctp-map-toggle" id="ctp_map_toggle" title="<?= __('ctp.layout.toggleMap') ?>">
            <i class="fas fa-chevron-up"></i>
        </button>
    </div>

    <!-- Resize Handle -->
    <div class="ctp-resize-handle" id="ctp_resize_handle" title="<?= __('ctp.layout.resizeHandle') ?>">
        <i class="fas fa-grip-lines"></i>
    </div>

    <!-- Table Section -->
    <div class="ctp-table-section" id="ctp_table_section">
        <!-- Filter Bar -->
        <div class="ctp-filter-bar">
            <div class="input-group input-group-sm ctp-search-group">
                <div class="input-group-prepend">
                    <span class="input-group-text"><i class="fas fa-search"></i></span>
                </div>
                <input type="text" class="form-control" id="ctp_search"
                       placeholder="<?= __('ctp.search.placeholder') ?>"
                       title="<?= __('ctp.search.help') ?>">
            </div>

            <div class="btn-group btn-group-sm ml-2">
                <button class="btn btn-outline-secondary dropdown-toggle" data-toggle="dropdown" id="ctp_filter_btn">
                    <i class="fas fa-filter"></i> <?= __('ctp.search.filters') ?>
                </button>
                <div class="dropdown-menu dropdown-menu-right ctp-filter-dropdown p-3" id="ctp_filter_dropdown">
                    <div class="form-group mb-2">
                        <label class="small font-weight-bold"><?= __('ctp.search.edctStatus') ?></label>
                        <div class="ctp-filter-checks" id="ctp_filter_edct_status">
                            <label class="ctp-filter-check"><input type="checkbox" value="NONE" checked> <?= __('ctp.edct.none') ?></label>
                            <label class="ctp-filter-check"><input type="checkbox" value="ASSIGNED" checked> <?= __('ctp.edct.assigned') ?></label>
                            <label class="ctp-filter-check"><input type="checkbox" value="DELIVERED" checked> <?= __('ctp.edct.delivered') ?></label>
                            <label class="ctp-filter-check"><input type="checkbox" value="COMPLIANT" checked> <?= __('ctp.edct.compliant') ?></label>
                            <label class="ctp-filter-check"><input type="checkbox" value="NON_COMPLIANT" checked> <?= __('ctp.edct.nonCompliant') ?></label>
                        </div>
                    </div>
                    <div class="form-group mb-2">
                        <label class="small font-weight-bold"><?= __('ctp.search.routeStatus') ?></label>
                        <div class="ctp-filter-checks" id="ctp_filter_route_status">
                            <label class="ctp-filter-check"><input type="checkbox" value="FILED" checked> <?= __('ctp.route.filed') ?></label>
                            <label class="ctp-filter-check"><input type="checkbox" value="MODIFIED" checked> <?= __('ctp.route.modified') ?></label>
                            <label class="ctp-filter-check"><input type="checkbox" value="VALIDATED" checked> <?= __('ctp.route.validated') ?></label>
                            <label class="ctp-filter-check"><input type="checkbox" value="REJECTED" checked> <?= __('ctp.route.rejected') ?></label>
                        </div>
                    </div>
                    <div class="form-group mb-0">
                        <label class="ctp-filter-check">
                            <input type="checkbox" id="ctp_filter_hide_excluded"> <?= __('ctp.search.hideExcluded') ?>
                        </label>
                    </div>
                </div>
            </div>

            <!-- Perspective Tabs -->
            <div class="btn-group btn-group-sm ml-2" id="ctp_perspective_tabs">
                <button class="btn btn-outline-info btn-sm active" data-perspective="ALL"><?= __('ctp.perspective.all') ?></button>
                <button class="btn btn-outline-info btn-sm" data-perspective="NA"><?= __('ctp.perspective.na') ?></button>
                <button class="btn btn-outline-info btn-sm" data-perspective="OCEANIC"><?= __('ctp.perspective.oceanic') ?></button>
                <button class="btn btn-outline-info btn-sm" data-perspective="EU"><?= __('ctp.perspective.eu') ?></button>
            </div>

            <div class="ml-auto ctp-page-info small text-muted" id="ctp_page_info"></div>
        </div>

        <!-- Flight Table -->
        <div class="ctp-table-wrapper">
            <table class="table table-sm table-striped table-hover ctp-flight-table" id="ctp_flight_table">
                <thead>
                    <tr>
                        <th class="ctp-col-check"><input type="checkbox" id="ctp_check_all" title="<?= __('ctp.flights.selectAll') ?>"></th>
                        <th class="ctp-col-cs ctp-sortable" data-sort="callsign"><?= __('ctp.flights.callsign') ?></th>
                        <th class="ctp-col-apt ctp-sortable" data-sort="dep_airport"><?= __('ctp.flights.dep') ?></th>
                        <th class="ctp-col-apt ctp-sortable" data-sort="arr_airport"><?= __('ctp.flights.arr') ?></th>
                        <th class="ctp-col-type ctp-sortable" data-sort="aircraft_type"><?= __('ctp.flights.type') ?></th>
                        <th class="ctp-col-fix"><?= __('ctp.flights.entryFix') ?></th>
                        <th class="ctp-col-time ctp-sortable" data-sort="oceanic_entry_utc"><?= __('ctp.flights.entryUtc') ?></th>
                        <th class="ctp-col-time ctp-sortable" data-sort="edct_utc"><?= __('ctp.flights.edct') ?></th>
                        <th class="ctp-col-seg"><?= __('ctp.perspective.naShort') ?></th>
                        <th class="ctp-col-seg"><?= __('ctp.perspective.ocaShort') ?></th>
                        <th class="ctp-col-seg"><?= __('ctp.perspective.euShort') ?></th>
                        <th class="ctp-col-status ctp-sortable" data-sort="route_status"><?= __('ctp.flights.overall') ?></th>
                    </tr>
                </thead>
                <tbody id="ctp_flight_tbody">
                    <tr class="ctp-empty-row">
                        <td colspan="12" class="text-center text-muted py-4">
                            <i class="fas fa-globe-americas fa-2x mb-2 d-block"></i>
                            <?= __('ctp.flights.selectSession') ?>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <div class="ctp-pagination" id="ctp_pagination">
            <button class="btn btn-sm btn-outline-secondary" id="ctp_page_prev" disabled>
                <i class="fas fa-chevron-left"></i>
            </button>
            <span class="ctp-page-label mx-2" id="ctp_page_label"></span>
            <button class="btn btn-sm btn-outline-secondary" id="ctp_page_next" disabled>
                <i class="fas fa-chevron-right"></i>
            </button>
        </div>
    </div>

    <!-- Bottom Panel Resize Handle -->
    <div class="ctp-bottom-resize-handle" id="ctp_bottom_resize_handle" style="display:none;" title="<?= __('ctp.layout.resizeHandle') ?>">
        <i class="fas fa-grip-lines" style="font-size:0.5rem;color:#6c757d;"></i>
    </div>

    <!-- Bottom Management Tabs (own flex area, outside table section) -->
    <div class="ctp-bottom-tabs" id="ctp_bottom_tabs" style="display:none;">
        <div class="ctp-bottom-tabs-header">
            <ul class="nav nav-tabs nav-sm px-2 pt-1 mb-0" id="ctp_mgmt_tabs">
                <li class="nav-item">
                    <a class="nav-link active" data-toggle="tab" href="#ctp_demand_panel">
                        <i class="fas fa-chart-bar mr-1"></i><?= __('ctp.demand.title') ?>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" data-toggle="tab" href="#ctp_throughput_panel">
                        <i class="fas fa-tachometer-alt mr-1"></i><?= __('ctp.throughput.title') ?>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" data-toggle="tab" href="#ctp_planning_panel">
                        <i class="fas fa-calculator mr-1"></i><?= __('ctp.planning.title') ?>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" data-toggle="tab" href="#ctp_routes_panel">
                        <i class="fas fa-route mr-1"></i><?= __('ctp.routes.title') ?>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" data-toggle="tab" href="#ctp_slot_engine_panel">
                        <i class="fas fa-cogs mr-1"></i><?= __('ctp.slotEngine.title') ?>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" data-toggle="tab" href="#ctp_stats_panel">
                        <i class="fas fa-chart-pie mr-1"></i><?= __('ctp.stats.title') ?>
                    </a>
                </li>
            </ul>
            <button class="btn btn-sm btn-link ctp-panel-toggle" id="ctp_panel_toggle" title="<?= __('ctp.layout.togglePanel') ?>">
                <i class="fas fa-chevron-down"></i>
            </button>
        </div>
        <div class="ctp-bottom-tabs-body">
            <div class="tab-content">
                <!-- Demand Chart Tab -->
                <div class="tab-pane fade show active" id="ctp_demand_panel">
                    <div class="d-flex align-items-center px-3 py-1">
                        <select id="ctp_demand_group_by" class="form-control form-control-sm d-inline-block" style="width:auto;">
                            <option value="status"><?= __('ctp.demand.groupByStatus') ?></option>
                            <option value="nat_track"><?= __('ctp.demand.groupByTrack') ?></option>
                        </select>
                    </div>
                    <div class="ctp-demand-chart-wrapper" id="ctp_demand_chart_wrapper">
                        <div id="ctp_demand_chart_container" style="height:300px"></div>
                    </div>
                </div>

                <!-- Throughput Config Tab -->
                <div class="tab-pane fade" id="ctp_throughput_panel">
                    <div class="d-flex align-items-center justify-content-between px-3 py-2">
                        <span class="small font-weight-bold"><?= __('ctp.throughput.configManager') ?></span>
                        <button class="btn btn-sm btn-outline-primary" id="ctp_throughput_create">
                            <i class="fas fa-plus mr-1"></i><?= __('ctp.throughput.createConfig') ?>
                        </button>
                    </div>
                    <div class="px-3 pb-2">
                        <table class="table table-sm table-striped table-hover mb-0" id="ctp_throughput_table">
                            <thead>
                                <tr>
                                    <th><?= __('ctp.throughput.configLabel') ?></th>
                                    <th><?= __('ctp.throughput.tracks') ?></th>
                                    <th><?= __('ctp.throughput.origins') ?></th>
                                    <th><?= __('ctp.throughput.destinations') ?></th>
                                    <th><?= __('ctp.throughput.maxAcph') ?></th>
                                    <th><?= __('ctp.throughput.priority') ?></th>
                                    <th style="width:120px;"><?= __('common.actions') ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr><td colspan="7" class="text-center text-muted py-3"><?= __('ctp.throughput.noConfigs') ?></td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Planning Simulator Tab -->
                <div class="tab-pane fade" id="ctp_planning_panel">
                    <div class="d-flex align-items-center justify-content-between px-3 py-2">
                        <span class="small font-weight-bold"><?= __('ctp.planning.simulator') ?></span>
                        <button class="btn btn-sm btn-outline-primary" id="ctp_planning_create">
                            <i class="fas fa-plus mr-1"></i><?= __('ctp.planning.createScenario') ?>
                        </button>
                    </div>
                    <div class="px-3 pb-2">
                        <div id="ctp_planning_scenario_list" class="list-group list-group-flush mb-2">
                            <div class="text-center text-muted py-3"><?= __('ctp.planning.noScenarios') ?></div>
                        </div>
                        <div id="ctp_planning_results" style="display:none;"></div>
                    </div>
                    <hr class="my-2">
                    <div class="d-flex align-items-center justify-content-between px-3 py-2">
                        <span class="small font-weight-bold"><?= __('ctp.planning.trackConstraints') ?></span>
                        <button class="btn btn-sm btn-outline-primary" id="ctp_track_constraint_add">
                            <i class="fas fa-plus mr-1"></i><?= __('ctp.planning.addConstraint') ?>
                        </button>
                    </div>
                    <div class="px-3 pb-2">
                        <div id="ctp_track_constraints_table"></div>
                    </div>
                </div>

                <!-- Route Templates Tab -->
                <div class="tab-pane fade" id="ctp_routes_panel">
                    <div class="d-flex align-items-center justify-content-between px-3 py-2">
                        <span class="small font-weight-bold"><?= __('ctp.routes.templateManager') ?></span>
                        <button class="btn btn-sm btn-outline-primary" id="ctp_routes_create">
                            <i class="fas fa-plus mr-1"></i><?= __('ctp.routes.createTemplate') ?>
                        </button>
                    </div>
                    <div class="px-3 pb-2">
                        <table class="table table-sm table-striped table-hover mb-0" id="ctp_routes_table">
                            <thead>
                                <tr>
                                    <th><?= __('common.name') ?></th>
                                    <th><?= __('ctp.routes.segment') ?></th>
                                    <th><?= __('ctp.routes.route') ?></th>
                                    <th><?= __('ctp.throughput.priority') ?></th>
                                    <th><?= __('ctp.routes.eventOnly') ?></th>
                                    <th style="width:100px;"><?= __('common.actions') ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr><td colspan="6" class="text-center text-muted py-3"><?= __('ctp.routes.noTemplates') ?></td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Slot Engine Tab -->
                <div class="tab-pane fade" id="ctp_slot_engine_panel">
                    <div class="d-flex align-items-center justify-content-between px-3 py-2">
                        <span class="small font-weight-bold"><?= __('ctp.slotEngine.sessionStatus') ?></span>
                        <button class="btn btn-sm btn-outline-secondary" id="ctp_slot_engine_refresh">
                            <i class="fas fa-sync-alt mr-1"></i><?= __('common.refresh') ?>
                        </button>
                    </div>
                    <div id="ctp_slot_engine_content" class="px-3 pb-2">
                        <div class="text-center text-muted py-3"><?= __('ctp.slotEngine.selectSession') ?></div>
                    </div>
                </div>

                <!-- Stats Tab -->
                <div class="tab-pane fade" id="ctp_stats_panel">
                    <div id="ctp_stats_content" class="py-2">
                        <div class="text-center text-muted py-3"><?= __('ctp.stats.selectSession') ?></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Create Session Modal -->
<div class="modal fade" id="ctpCreateSessionModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-plus-circle mr-2"></i><?= __('ctp.session.createTitle') ?></h5>
                <button type="button" class="close" data-dismiss="modal">&times;</button>
            </div>
            <div class="modal-body">
                <div class="form-row">
                    <div class="form-group col-md-6">
                        <label><?= __('ctp.session.name') ?></label>
                        <input type="text" class="form-control" id="ctp_create_name" placeholder="CTP2026W-NON-EVENT">
                    </div>
                    <div class="form-group col-md-6">
                        <label><?= __('ctp.session.direction') ?></label>
                        <select class="form-control" id="ctp_create_direction">
                            <option value="WESTBOUND"><?= __('ctp.session.westbound') ?></option>
                            <option value="EASTBOUND"><?= __('ctp.session.eastbound') ?></option>
                            <option value="BOTH"><?= __('ctp.session.both') ?></option>
                        </select>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group col-md-6">
                        <label><?= __('ctp.session.windowStart') ?></label>
                        <input type="datetime-local" class="form-control" id="ctp_create_start">
                    </div>
                    <div class="form-group col-md-6">
                        <label><?= __('ctp.session.windowEnd') ?></label>
                        <input type="datetime-local" class="form-control" id="ctp_create_end">
                    </div>
                </div>
                <div class="form-group">
                    <label><?= __('ctp.session.constrainedFirs') ?></label>
                    <input type="text" class="form-control" id="ctp_create_firs" placeholder="CZQX, BIRD, EGGX, LPPO">
                    <small class="text-muted"><?= __('ctp.session.constrainedFirsHelp') ?></small>
                </div>
                <div class="form-row">
                    <div class="form-group col-md-4">
                        <label><?= __('ctp.session.slotInterval') ?></label>
                        <input type="number" class="form-control" id="ctp_create_interval" value="5" min="1" max="60">
                    </div>
                    <div class="form-group col-md-4">
                        <label><?= __('ctp.session.maxSlotsPerHour') ?></label>
                        <input type="number" class="form-control" id="ctp_create_max_slots" placeholder="<?= __('ctp.session.unlimited') ?>">
                    </div>
                    <div class="form-group col-md-4">
                        <label><?= __('ctp.session.flowEvent') ?></label>
                        <select class="form-control" id="ctp_create_event">
                            <option value=""><?= __('common.none') ?></option>
                        </select>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal"><?= __('common.cancel') ?></button>
                <button type="button" class="btn btn-primary" id="ctp_create_submit">
                    <i class="fas fa-plus-circle mr-1"></i><?= __('ctp.session.create') ?>
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Flight Detail Sidebar -->
<div class="ctp-sidebar d-none" id="ctp_sidebar">
    <div class="ctp-sidebar-header">
        <h6 class="mb-0" id="ctp_sidebar_title"></h6>
        <button class="btn btn-sm btn-link" id="ctp_sidebar_close"><i class="fas fa-times"></i></button>
    </div>
    <div class="ctp-sidebar-body" id="ctp_sidebar_body">
        <!-- Flight Info Summary -->
        <div class="mb-3" id="ctp_sidebar_flight_info"></div>

        <!-- Route Editor Tabs -->
        <ul class="nav nav-tabs nav-sm mb-2" id="ctp_route_tabs">
            <li class="nav-item">
                <a class="nav-link active" data-segment="NA" href="#"><?= __('ctp.perspective.naShort') ?></a>
            </li>
            <li class="nav-item">
                <a class="nav-link" data-segment="OCEANIC" href="#"><?= __('ctp.perspective.ocaShort') ?></a>
            </li>
            <li class="nav-item">
                <a class="nav-link" data-segment="EU" href="#"><?= __('ctp.perspective.euShort') ?></a>
            </li>
        </ul>

        <!-- Segment Editor Panel -->
        <div id="ctp_route_editor_panel">
            <div class="mb-2">
                <label class="small font-weight-bold"><?= __('ctp.route.segmentStatus') ?></label>
                <span class="badge ctp-badge ml-1" id="ctp_seg_status_badge"></span>
            </div>

            <div class="mb-2">
                <label class="small font-weight-bold"><?= __('ctp.route.original') ?></label>
                <div class="form-control form-control-sm bg-light" id="ctp_seg_original" style="font-family:'Inconsolata',monospace;font-size:0.75rem;min-height:36px;word-break:break-all;"></div>
            </div>

            <div class="mb-2">
                <label class="small font-weight-bold"><?= __('ctp.route.modified') ?></label>
                <textarea class="form-control form-control-sm" id="ctp_seg_route_input" rows="2"
                          style="font-family:'Inconsolata',monospace;font-size:0.75rem;"
                          placeholder="<?= __('ctp.route.enterRoute') ?>"></textarea>
            </div>

            <!-- Suggestions -->
            <div class="mb-2" id="ctp_route_suggestions_wrapper" style="display:none;">
                <label class="small font-weight-bold"><?= __('ctp.route.suggestions') ?></label>
                <div id="ctp_route_suggestions" class="ctp-route-suggestions"></div>
            </div>

            <!-- Actions -->
            <div class="d-flex gap-2 mb-3" id="ctp_seg_actions">
                <button class="btn btn-sm btn-outline-info" id="ctp_btn_suggest">
                    <i class="fas fa-lightbulb"></i> <?= __('ctp.route.suggest') ?>
                </button>
                <button class="btn btn-sm btn-outline-warning" id="ctp_btn_validate">
                    <i class="fas fa-check-circle"></i> <?= __('ctp.route.validate') ?>
                </button>
                <button class="btn btn-sm btn-primary" id="ctp_btn_save_segment">
                    <i class="fas fa-save"></i> <?= __('ctp.route.saveSegment') ?>
                </button>
            </div>

            <!-- Validation Result -->
            <div id="ctp_validation_result" style="display:none;" class="mb-2"></div>

            <!-- Altitude -->
            <div class="form-group mb-2">
                <label class="small font-weight-bold"><?= __('ctp.route.altitude') ?></label>
                <input type="number" class="form-control form-control-sm" id="ctp_seg_altitude"
                       placeholder="FL370" min="100" max="600" step="10">
            </div>

            <!-- Notes -->
            <div class="form-group mb-2">
                <label class="small font-weight-bold"><?= __('ctp.flights.notes') ?></label>
                <textarea class="form-control form-control-sm" id="ctp_seg_notes" rows="2"
                          placeholder="<?= __('ctp.route.notesPlaceholder') ?>"></textarea>
            </div>
        </div>

        <!-- Enhanced Audit Log -->
        <div class="mt-3 border-top pt-2" id="ctp_sidebar_audit">
            <div class="d-flex align-items-center justify-content-between cursor-pointer" id="ctp_audit_toggle">
                <label class="small font-weight-bold mb-0"><i class="fas fa-history mr-1"></i> <?= __('ctp.audit.recentActions') ?></label>
                <i class="fas fa-chevron-down small"></i>
            </div>
            <div id="ctp_audit_body">
                <div id="ctp_audit_list" class="small text-muted mt-1"></div>
                <button class="btn btn-sm btn-link btn-block mt-1" id="ctp_audit_load_more" style="display:none;">
                    <?= __('ctp.changelog.loadMore') ?>
                </button>
            </div>
        </div>

        <!-- NAT Tracks Reference -->
        <div class="mt-3 border-top pt-2" id="ctp_nat_section">
            <div class="d-flex align-items-center justify-content-between cursor-pointer" id="ctp_nat_toggle">
                <label class="small font-weight-bold mb-0"><i class="fas fa-ship mr-1"></i> <?= __('ctp.nat.tracks') ?></label>
                <i class="fas fa-chevron-down small"></i>
            </div>
            <div id="ctp_nat_body" style="display:none;">
                <div class="small text-muted mt-1 mb-1"><?= __('ctp.nat.description') ?></div>
                <table class="table table-sm table-bordered mb-0" style="font-size:0.7rem;">
                    <thead>
                        <tr class="text-uppercase">
                            <th style="width:60px;"><?= __('ctp.nat.name') ?></th>
                            <th><?= __('ctp.nat.routeString') ?></th>
                        </tr>
                    </thead>
                    <tbody id="ctp_nat_tbody">
                        <tr><td colspan="2" class="text-center text-muted py-2"><?= __('ctp.nat.loading') ?></td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Locales -->
<script src="assets/locales/index.js<?= _v('assets/locales/index.js') ?>"></script>

<!-- ECharts -->
<script src="https://cdn.jsdelivr.net/npm/echarts@5.4.3/dist/echarts.min.js"></script>

<!-- Navbar height CSS variable (dynamic) -->
<script>
(function() {
    function updateNavbarHeight() {
        var navbar = document.querySelector('.cs-header.navbar-floating');
        if (navbar) {
            document.documentElement.style.setProperty('--navbar-height', navbar.offsetHeight + 'px');
        }
    }
    document.addEventListener('DOMContentLoaded', updateNavbarHeight);
    window.addEventListener('resize', updateNavbarHeight);
})();
</script>

<!-- CTP Module -->
<script src="assets/js/ctp.js<?= _v('assets/js/ctp.js') ?>"></script>

<?php include("load/footer.php"); ?>
</body>
</html>
