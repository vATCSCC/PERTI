<!-- Playbook/CDR/Preferred Search Panel (shared partial) -->
<div id="pbcdr_search_panel">
    <div class="pbcdr-panel-header">
        <h6><i class="fas fa-book mr-2"></i><?= __('route.page.playbookCdrSearch') ?></h6>
        <div class="d-flex align-items-center">
            <button type="button" class="pbcdr-collapse-btn" id="pbcdr_collapse_btn" title="Collapse/Expand">
                <i class="fas fa-chevron-up"></i>
            </button>
            <button type="button" class="close text-white" id="pbcdr_panel_close" style="font-size: 1rem;">
                <span>&times;</span>
            </button>
        </div>
    </div>

    <!-- Tabs -->
    <div class="pbcdr-tabs">
        <button class="pbcdr-tab active" data-tab="playbook">
            <i class="fas fa-book mr-1"></i> <?= __('route.page.playbooks') ?>
        </button>
        <button class="pbcdr-tab" data-tab="cdr">
            <i class="fas fa-road mr-1"></i> <?= __('route.page.cdrsTab') ?>
        </button>
        <button class="pbcdr-tab" data-tab="preferred">
            <i class="fas fa-star mr-1"></i> Pref Route
        </button>
        <button class="pbcdr-tab" data-tab="all">
            <i class="fas fa-search mr-1"></i> <?= __('route.page.allTab') ?>
        </button>
    </div>

    <!-- Search Filters -->
    <div class="pbcdr-search-body">
        <!-- Play/CDR Name -->
        <div class="pbcdr-filter-row">
            <div class="pbcdr-filter-group" style="flex: 2;">
                <label><span id="pbcdr_name_label"><?= __('route.page.playName') ?></span></label>
                <input type="text" class="form-control form-control-sm" id="pbcdr_name" placeholder="e.g., SERMN, ABI, NORTHEAST..." autocomplete="off">
            </div>
            <div class="pbcdr-filter-group" style="flex: 1;">
                <label><?= __('route.page.routeContains') ?></label>
                <input type="text" class="form-control form-control-sm" id="pbcdr_route_text" placeholder="e.g., J60, BETTE" autocomplete="off">
            </div>
        </div>

        <!-- Origin filters -->
        <div class="pbcdr-filter-row">
            <div class="pbcdr-filter-group">
                <label><?= __('route.page.originAirport') ?></label>
                <input type="text" class="form-control form-control-sm" id="pbcdr_orig_apt" placeholder="KJFK, KLGA..." autocomplete="off">
            </div>
            <div class="pbcdr-filter-group">
                <label><?= __('route.page.originTracon') ?></label>
                <input type="text" class="form-control form-control-sm" id="pbcdr_orig_tracon" placeholder="N90, A80..." autocomplete="off">
            </div>
            <div class="pbcdr-filter-group">
                <label><?= __('route.page.originArtcc') ?></label>
                <input type="text" class="form-control form-control-sm" id="pbcdr_orig_artcc" placeholder="ZNY, ZDC..." autocomplete="off">
            </div>
        </div>

        <!-- Destination filters -->
        <div class="pbcdr-filter-row">
            <div class="pbcdr-filter-group">
                <label><?= __('route.page.destAirport') ?></label>
                <input type="text" class="form-control form-control-sm" id="pbcdr_dest_apt" placeholder="KMIA, KFLL..." autocomplete="off">
            </div>
            <div class="pbcdr-filter-group">
                <label><?= __('route.page.destTracon') ?></label>
                <input type="text" class="form-control form-control-sm" id="pbcdr_dest_tracon" placeholder="M98, P50..." autocomplete="off">
            </div>
            <div class="pbcdr-filter-group">
                <label><?= __('route.page.destArtcc') ?></label>
                <input type="text" class="form-control form-control-sm" id="pbcdr_dest_artcc" placeholder="ZMA, ZTL..." autocomplete="off">
            </div>
        </div>

        <!-- Search buttons -->
        <div class="d-flex justify-content-between align-items-center mt-2">
            <button class="btn btn-sm btn-outline-secondary" id="pbcdr_clear_filters">
                <i class="fas fa-eraser mr-1"></i> <?= __('route.page.clear') ?>
            </button>
            <button class="btn btn-sm btn-primary" id="pbcdr_search_btn">
                <i class="fas fa-search mr-1"></i> <?= __('route.page.search') ?>
            </button>
        </div>
    </div>

    <!-- Results Header -->
    <div class="pbcdr-results-header">
        <span class="pbcdr-results-count">
            <strong id="pbcdr_results_shown">0</strong> <?= __('route.page.results') ?>
            <span id="pbcdr_results_limited" style="display: none;" class="text-warning">
                (limited to <span id="pbcdr_results_limit">100</span>)
            </span>
        </span>
        <div class="btn-group btn-group-sm">
            <button class="btn btn-outline-primary btn-sm" id="pbcdr_add_selected" title="Add selected to textarea" disabled>
                <i class="fas fa-plus"></i> <?= __('route.page.add') ?>
            </button>
            <button class="btn btn-outline-success btn-sm" id="pbcdr_plot_selected" title="Plot selected routes" disabled>
                <i class="fas fa-pencil-alt"></i> Plot
            </button>
        </div>
    </div>

    <!-- Results List -->
    <div class="pbcdr-results-list" id="pbcdr_results_list">
        <div class="pbcdr-no-results">
            <i class="fas fa-search d-block"></i>
            <p class="mb-0"><?= __('route.page.enterSearchCriteria') ?></p>
        </div>
    </div>

    <!-- Bulk Actions Footer -->
    <div class="pbcdr-bulk-actions">
        <div class="pbcdr-select-all">
            <input type="checkbox" id="pbcdr_select_all">
            <label for="pbcdr_select_all" class="mb-0" style="cursor: pointer;"><?= __('route.page.selectAll') ?></label>
        </div>
        <div class="btn-group btn-group-sm">
            <button class="btn btn-outline-danger btn-sm" id="pbcdr_clear_routes" title="Clear routes textarea">
                <i class="fas fa-eraser"></i>
            </button>
            <button class="btn btn-outline-secondary btn-sm" id="pbcdr_copy_selected" title="Copy to clipboard" disabled>
                <i class="fas fa-copy"></i>
            </button>
        </div>
    </div>
</div>
