<?php
/**
 * NavData AIRAC Changelog
 * Displays diffs between AIRAC cycles with search, filtering, and ARTCC grouping.
 * No DB needed - loads static JSON changelogs from assets/data/logs/.
 */
include("sessions/handler.php");
include("load/config.php");
include("load/i18n.php");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php $page_title = __('navdata.page.title'); include("load/header.php"); ?>
    <link rel="stylesheet" href="assets/css/navdata.css<?= _v('assets/css/navdata.css') ?>">
</head>
<body>
    <?php include("load/nav.php"); ?>

    <div class="container-fluid mt-3 px-4">
        <!-- Page Header -->
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h4 class="mb-0">
                <i class="fas fa-database mr-2"></i><?= __('navdata.page.title') ?>
            </h4>
            <div class="d-flex align-items-center">
                <label class="mb-0 mr-2 small text-muted"><?= __('navdata.cycle') ?>:</label>
                <select id="cycle-selector" class="form-control form-control-sm" style="width:200px"></select>
            </div>
        </div>

        <!-- Summary Cards -->
        <div id="summary-cards" class="row mb-3"></div>

        <!-- Controls Bar -->
        <div class="d-flex justify-content-between align-items-center mb-3">
            <div class="d-flex align-items-center">
                <!-- Search -->
                <div class="input-group input-group-sm mr-3" style="width:280px">
                    <div class="input-group-prepend">
                        <span class="input-group-text"><i class="fas fa-search"></i></span>
                    </div>
                    <input type="text" id="search-input" class="form-control"
                           placeholder="<?= __('navdata.search.placeholder') ?>">
                    <div class="input-group-append">
                        <button id="search-clear" class="btn btn-outline-secondary" type="button" style="display:none">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                </div>
                <!-- Action Filter -->
                <div class="btn-group btn-group-sm mr-3" id="action-filter">
                    <button class="btn btn-outline-secondary active" data-action="all"><?= __('common.all') ?></button>
                    <button class="btn btn-outline-success" data-action="added"><?= __('navdata.action.added') ?></button>
                    <button class="btn btn-outline-warning" data-action="moved"><?= __('navdata.action.moved') ?></button>
                    <button class="btn btn-outline-info" data-action="changed"><?= __('navdata.action.changed') ?></button>
                    <button class="btn btn-outline-primary" data-action="superseded"><?= __('navdata.action.superseded') ?></button>
                    <button class="btn btn-outline-danger" data-action="removed"><?= __('navdata.action.removed') ?></button>
                </div>
            </div>
            <div class="d-flex align-items-center">
                <span id="result-count" class="text-muted small mr-3"></span>
            </div>
        </div>

        <!-- Type Tabs -->
        <ul class="nav nav-tabs mb-3" id="type-tabs">
            <li class="nav-item">
                <a class="nav-link active" href="#" data-type="all"><?= __('common.all') ?></a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="#" data-type="fix"><?= __('navdata.type.fixes') ?></a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="#" data-type="navaid"><?= __('navdata.type.navaids') ?></a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="#" data-type="airway"><?= __('navdata.type.airways') ?></a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="#" data-type="cdr"><?= __('navdata.type.cdrs') ?></a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="#" data-type="dp"><?= __('navdata.type.dps') ?></a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="#" data-type="star"><?= __('navdata.type.stars') ?></a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="#" data-type="playbook"><?= __('navdata.type.playbook') ?></a>
            </li>
        </ul>

        <!-- Changes Table -->
        <div class="table-responsive">
            <table class="table table-sm table-hover" id="changes-table">
                <thead class="thead-dark">
                    <tr>
                        <th style="width:180px"><?= __('navdata.col.name') ?></th>
                        <th style="width:80px"><?= __('navdata.col.type') ?></th>
                        <th style="width:100px"><?= __('navdata.col.action') ?></th>
                        <th><?= __('navdata.col.detail') ?></th>
                    </tr>
                </thead>
                <tbody id="changes-body"></tbody>
            </table>
        </div>

        <!-- Load More / Empty State -->
        <div id="load-more-row" class="text-center py-3" style="display:none">
            <button id="load-more-btn" class="btn btn-sm btn-outline-secondary">
                <?= __('navdata.loadMore') ?>
            </button>
        </div>
        <div id="empty-state" class="text-center py-5" style="display:none">
            <i class="fas fa-inbox fa-3x text-muted mb-3 d-block"></i>
            <p class="text-muted" id="empty-message"></p>
        </div>
        <div id="loading-state" class="text-center py-5">
            <i class="fas fa-spinner fa-spin fa-2x text-muted"></i>
        </div>

        <!-- Statistics Footer -->
        <div id="stats-panel" class="card mt-4 mb-4" style="display:none">
            <div class="card-body py-2">
                <div class="row text-center small text-muted" id="stats-row"></div>
            </div>
        </div>
    </div>

    <script src="assets/js/navdata.js<?= _v('assets/js/navdata.js') ?>"></script>
    <?php include("load/footer.php"); ?>
</body>
</html>
