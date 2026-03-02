<?php
/**
 * Hibernation Mode Info Page
 *
 * Displays information about the current hibernation status,
 * paused features, and what continues to run.
 *
 * Stats section fetches from /api/data/hibernation_stats.php via AJAX
 */

include("sessions/handler.php");
include("load/config.php");
include("load/i18n.php");
?>
<!DOCTYPE html>
<html lang="<?= substr(PERTII18nPHP::getLocale(), 0, 2) ?>">
<head>
    <?php $page_title = __('hibernation.title'); include("load/header.php"); ?>
    <style>
        .hibernation-hero {
            background: linear-gradient(135deg, #1a1a2e 0%, #16213e 50%, #0f3460 100%);
            padding: 80px 0 60px;
            text-align: center;
            position: relative;
            overflow: hidden;
        }
        .hibernation-hero::before {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0; bottom: 0;
            background: radial-gradient(circle at 30% 50%, rgba(93,173,226,0.08) 0%, transparent 50%),
                        radial-gradient(circle at 70% 30%, rgba(52,152,219,0.06) 0%, transparent 50%);
        }
        .hibernation-hero h1 {
            font-size: 2.5rem;
            font-weight: 700;
            color: #fff;
            margin-bottom: 10px;
        }
        .hibernation-hero .snowflake-icon {
            font-size: 3rem;
            color: #5dade2;
            margin-bottom: 20px;
            display: block;
            opacity: 0.8;
        }
        .hibernation-hero .subtitle {
            font-size: 1.15rem;
            color: rgba(255,255,255,0.7);
            max-width: 600px;
            margin: 0 auto;
        }
        .hibernation-card {
            background: #fff;
            border: 1px solid #dee2e6;
            border-radius: 10px;
            padding: 30px;
            margin-bottom: 30px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
        }
        .hibernation-card h3 {
            color: #2c3e50;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid #e9ecef;
        }
        .hibernation-card h3 i {
            margin-right: 8px;
        }
        .feature-list {
            list-style: none;
            padding: 0;
            margin: 0;
        }
        .feature-list li {
            padding: 8px 0;
            border-bottom: 1px solid #f1f3f5;
            display: flex;
            align-items: center;
        }
        .feature-list li:last-child {
            border-bottom: none;
        }
        .feature-list .status-icon {
            width: 28px;
            text-align: center;
            margin-right: 12px;
            flex-shrink: 0;
        }
        .feature-list .paused {
            color: #e74c3c;
        }
        .feature-list .active {
            color: #27ae60;
        }
        .timeframe-badge {
            display: inline-block;
            background: #2c3e50;
            color: #5dade2;
            padding: 6px 16px;
            border-radius: 20px;
            font-size: 0.9rem;
            font-weight: 600;
            margin-top: 15px;
        }
        .data-note {
            background: #e8f8f5;
            border-left: 4px solid #27ae60;
            padding: 15px 20px;
            border-radius: 0 8px 8px 0;
            margin-top: 20px;
        }
        .data-note i {
            color: #27ae60;
            margin-right: 8px;
        }
        .stats-summary {
            display: flex;
            gap: 20px;
            margin-bottom: 25px;
        }
        .stat-box {
            flex: 1;
            background: #f8f9fa;
            border-radius: 8px;
            padding: 20px;
            text-align: center;
        }
        .stat-box .stat-value {
            font-size: 2rem;
            font-weight: 700;
            color: #2c3e50;
            line-height: 1.2;
        }
        .stat-box .stat-label {
            font-size: 0.85rem;
            color: #6c757d;
            margin-top: 4px;
        }
        .stats-table {
            width: 100%;
            border-collapse: collapse;
        }
        .stats-table th {
            background: #f8f9fa;
            padding: 10px 12px;
            text-align: left;
            font-size: 0.8rem;
            text-transform: uppercase;
            color: #6c757d;
            border-bottom: 2px solid #dee2e6;
        }
        .stats-table td {
            padding: 8px 12px;
            border-bottom: 1px solid #f1f3f5;
            font-size: 0.9rem;
        }
        .stats-table tr:last-child td {
            border-bottom: none;
        }
        .stats-table .type-badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 10px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        .stats-table .type-page { background: #e3f2fd; color: #1565c0; }
        .stats-table .type-api { background: #fce4ec; color: #c62828; }
        .stats-spinner {
            text-align: center;
            padding: 30px;
            color: #6c757d;
        }
    </style>
</head>
<body>

<?php include('load/nav_public.php'); ?>

<!-- Hero Section -->
<div class="hibernation-hero">
    <div class="container position-relative">
        <span class="snowflake-icon"><i class="fas fa-snowflake"></i></span>
        <h1><?= __('hibernation.heroTitle') ?></h1>
        <p class="subtitle">
            <?= __('hibernation.heroSubtitle') ?>
        </p>
        <span class="timeframe-badge"><i class="fas fa-clock mr-1"></i> <?= __('hibernation.timeframeBadge') ?></span>
    </div>
</div>

<!-- Main Content -->
<div class="container mt-5 mb-5">

    <div class="row">
        <!-- Paused Features -->
        <div class="col-lg-6">
            <div class="hibernation-card">
                <h3><i class="fas fa-pause-circle" style="color:#e74c3c;"></i> <?= __('hibernation.pausedFeatures') ?></h3>
                <ul class="feature-list">
                    <?php
                    $paused_keys = [
                        'demandCharts', 'nodDisplay', 'gdtDisplay', 'jatocIncident',
                        'postEventReview', 'vatswimApi', 'atcSimulator', 'suaDisplay',
                        'eventAarConfig', 'routeParsingBoundary', 'discordTmiPosting'
                    ];
                    foreach ($paused_keys as $k): ?>
                    <li>
                        <span class="status-icon paused"><i class="fas fa-times-circle"></i></span>
                        <span><?= __('hibernation.' . $k) ?></span>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>

        <!-- Still Active -->
        <div class="col-lg-6">
            <div class="hibernation-card">
                <h3><i class="fas fa-check-circle" style="color:#27ae60;"></i> <?= __('hibernation.stillActive') ?></h3>
                <ul class="feature-list">
                    <?php
                    $active_keys = [
                        'plansSheets', 'airportConfig', 'routeVisualization', 'playbookCdr',
                        'sectorSplits', 'navigationData', 'eventSchedule', 'tmiPublisher',
                        'systemStatus', 'loginAuth'
                    ];
                    foreach ($active_keys as $k): ?>
                    <li>
                        <span class="status-icon active"><i class="fas fa-check-circle"></i></span>
                        <span><?= __('hibernation.' . $k) ?></span>
                    </li>
                    <?php endforeach; ?>
                </ul>

                <div class="data-note">
                    <i class="fas fa-database"></i>
                    <strong><?= __('hibernation.dataNote') ?></strong>
                    <?= __('hibernation.dataNoteDetail') ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Access Attempt Stats -->
    <div class="row mt-2">
        <div class="col-12">
            <div class="hibernation-card">
                <h3><i class="fas fa-chart-bar" style="color:#3498db;"></i> <?= __('hibernation.statsTitle') ?></h3>
                <p style="color:#6c757d;margin-top:-10px;margin-bottom:20px;"><?= __('hibernation.statsSubtitle') ?></p>

                <div id="hib-stats-loading" class="stats-spinner">
                    <i class="fas fa-spinner fa-spin fa-lg"></i>
                    <div style="margin-top:8px;"><?= __('hibernation.statsLoading') ?></div>
                </div>

                <div id="hib-stats-content" style="display:none;">
                    <div class="stats-summary">
                        <div class="stat-box">
                            <div class="stat-value" id="stat-total-hits">0</div>
                            <div class="stat-label"><?= __('hibernation.statsTotalHits') ?></div>
                        </div>
                        <div class="stat-box">
                            <div class="stat-value" id="stat-unique-ips">0</div>
                            <div class="stat-label"><?= __('hibernation.statsUniqueVisitors') ?></div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-lg-7">
                            <h6 style="color:#6c757d;text-transform:uppercase;font-size:0.8rem;margin-bottom:10px;">
                                <?= __('hibernation.statsColPage') ?>
                            </h6>
                            <table class="stats-table">
                                <thead>
                                    <tr>
                                        <th><?= __('hibernation.statsColPage') ?></th>
                                        <th><?= __('hibernation.statsColType') ?></th>
                                        <th><?= __('hibernation.statsColHits') ?></th>
                                        <th><?= __('hibernation.statsColUnique') ?></th>
                                    </tr>
                                </thead>
                                <tbody id="stats-by-page"></tbody>
                            </table>
                            <div id="stats-no-data" style="display:none;text-align:center;padding:20px;color:#6c757d;">
                                <?= __('hibernation.statsNoData') ?>
                            </div>
                        </div>
                        <div class="col-lg-5">
                            <h6 style="color:#6c757d;text-transform:uppercase;font-size:0.8rem;margin-bottom:10px;">
                                <?= __('hibernation.statsDailyTrend') ?>
                            </h6>
                            <table class="stats-table">
                                <thead>
                                    <tr>
                                        <th><?= __('hibernation.statsColDate') ?></th>
                                        <th><?= __('hibernation.statsColHits') ?></th>
                                        <th><?= __('hibernation.statsColUnique') ?></th>
                                    </tr>
                                </thead>
                                <tbody id="stats-by-day"></tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <div id="hib-stats-error" style="display:none;text-align:center;padding:20px;color:#e74c3c;">
                    <i class="fas fa-exclamation-triangle"></i> <?= __('hibernation.statsError') ?>
                </div>
            </div>
        </div>
    </div>

</div>

<script>
(function() {
    var pageLabel = <?= json_encode(__('hibernation.statsTypePage')) ?>;
    var apiLabel = <?= json_encode(__('hibernation.statsTypeApi')) ?>;

    $.getJSON('/api/data/hibernation_stats.php')
        .done(function(data) {
            $('#hib-stats-loading').hide();
            $('#hib-stats-content').show();

            $('#stat-total-hits').text(data.totals.hits.toLocaleString());
            $('#stat-unique-ips').text(data.totals.unique_ips.toLocaleString());

            var pageBody = $('#stats-by-page');
            if (data.by_page && data.by_page.length > 0) {
                $.each(data.by_page, function(i, row) {
                    var isApi = row.hit_type === 'api';
                    var typeClass = isApi ? 'type-api' : 'type-page';
                    var typeText = isApi ? apiLabel : pageLabel;
                    pageBody.append(
                        '<tr>' +
                        '<td>' + $('<span>').text(row.page).html() + '</td>' +
                        '<td><span class="type-badge ' + typeClass + '">' + typeText + '</span></td>' +
                        '<td>' + parseInt(row.hits).toLocaleString() + '</td>' +
                        '<td>' + parseInt(row.unique_ips).toLocaleString() + '</td>' +
                        '</tr>'
                    );
                });
            } else {
                $('#stats-no-data').show();
            }

            var dayBody = $('#stats-by-day');
            if (data.by_day && data.by_day.length > 0) {
                $.each(data.by_day, function(i, row) {
                    dayBody.append(
                        '<tr>' +
                        '<td>' + row.date + '</td>' +
                        '<td>' + parseInt(row.hits).toLocaleString() + '</td>' +
                        '<td>' + parseInt(row.unique_ips).toLocaleString() + '</td>' +
                        '</tr>'
                    );
                });
            }
        })
        .fail(function() {
            $('#hib-stats-loading').hide();
            $('#hib-stats-error').show();
        });
})();
</script>

</body>
<?php include('load/footer.php'); ?>
</html>
