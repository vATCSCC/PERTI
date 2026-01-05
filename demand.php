<?php

include("sessions/handler.php");
if (session_status() == PHP_SESSION_NONE) {
    session_start();
    ob_start();
}

include("load/config.php");
include("load/connect.php");

?>
<!DOCTYPE html>
<html lang="en">
<head>

    <?php include("load/header.php"); ?>

    <title>Demand Visualization | PERTI</title>

    <!-- ECharts CDN -->
    <script src="https://cdn.jsdelivr.net/npm/echarts@5.4.3/dist/echarts.min.js"></script>

    <style>
        .demand-filter-label {
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.03em;
            font-weight: 600;
            color: #555;
            margin-bottom: 4px;
        }

        .demand-chart-container {
            width: 100%;
            height: 500px;
            min-height: 400px;
        }

        .demand-status-indicator {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 4px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .demand-status-active {
            background-color: #28a745;
            color: #fff;
        }

        .demand-status-paused {
            background-color: #ffc107;
            color: #333;
        }

        .demand-info-bar {
            background-color: #f8f9fa;
            border-bottom: 1px solid #dee2e6;
            padding: 8px 15px;
            font-size: 0.85rem;
        }

        .demand-filter-card .card-body {
            padding: 15px;
        }

        .demand-granularity-toggle .btn {
            font-size: 0.8rem;
            padding: 5px 15px;
        }

        .demand-direction-toggle .btn {
            font-size: 0.8rem;
            padding: 5px 12px;
        }

        .demand-last-update {
            font-size: 0.8rem;
            color: #666;
        }

        .demand-legend-item {
            display: inline-block;
            margin-right: 15px;
            font-size: 0.75rem;
        }

        .demand-legend-color {
            display: inline-block;
            width: 14px;
            height: 14px;
            border-radius: 2px;
            margin-right: 5px;
            vertical-align: middle;
        }
    </style>

</head>
<body>

    <?php include('load/nav.php'); ?>

    <!-- Page Header -->
    <section class="py-4 bg-light border-bottom">
        <div class="container-fluid">
            <div class="row align-items-center">
                <div class="col-md-6">
                    <h4 class="mb-0"><i class="fas fa-chart-bar mr-2"></i>Demand Visualization</h4>
                    <small class="text-muted">Airport arrival and departure demand analysis</small>
                </div>
                <div class="col-md-6 text-md-right">
                    <span class="demand-last-update" id="demand_last_update">--</span>
                    <button type="button" class="btn btn-sm btn-outline-primary ml-2" id="demand_refresh_btn" title="Manual Refresh">
                        <i class="fas fa-sync-alt"></i>
                    </button>
                </div>
            </div>
        </div>
    </section>

    <!-- Filter Panel -->
    <section class="py-3 bg-white border-bottom">
        <div class="container-fluid">
            <div class="row">
                <!-- Airport Selection -->
                <div class="col-md-2 mb-2">
                    <label class="demand-filter-label">Airport</label>
                    <select class="form-control form-control-sm" id="demand_airport">
                        <option value="">-- Select Airport --</option>
                    </select>
                </div>

                <!-- Category Filter -->
                <div class="col-md-2 mb-2">
                    <label class="demand-filter-label">Category</label>
                    <select class="form-control form-control-sm" id="demand_category">
                        <option value="all">All Airports</option>
                        <option value="core30">Core30</option>
                        <option value="oep35">OEP35</option>
                        <option value="aspm77">ASPM77</option>
                    </select>
                </div>

                <!-- ARTCC Filter -->
                <div class="col-md-2 mb-2">
                    <label class="demand-filter-label">ARTCC</label>
                    <select class="form-control form-control-sm" id="demand_artcc">
                        <option value="">All ARTCCs</option>
                    </select>
                </div>

                <!-- Tier Filter -->
                <div class="col-md-2 mb-2">
                    <label class="demand-filter-label">Tier</label>
                    <select class="form-control form-control-sm" id="demand_tier">
                        <option value="all">All Tiers</option>
                    </select>
                </div>

                <!-- Time Range -->
                <div class="col-md-2 mb-2">
                    <label class="demand-filter-label">Time Range</label>
                    <select class="form-control form-control-sm" id="demand_time_range">
                        <option value="6">+/- 6H</option>
                    </select>
                </div>

                <!-- Auto-Refresh Toggle -->
                <div class="col-md-2 mb-2">
                    <label class="demand-filter-label">Auto-Refresh</label>
                    <div class="custom-control custom-switch mt-1">
                        <input type="checkbox" class="custom-control-input" id="demand_auto_refresh" checked>
                        <label class="custom-control-label" for="demand_auto_refresh">
                            <span class="demand-status-indicator demand-status-active" id="refresh_status">15s</span>
                        </label>
                    </div>
                </div>
            </div>

            <!-- Second Row: Granularity and Direction -->
            <div class="row mt-2">
                <!-- Granularity Toggle -->
                <div class="col-md-3">
                    <label class="demand-filter-label">Granularity</label>
                    <div class="btn-group demand-granularity-toggle" role="group">
                        <input type="radio" class="btn-check" name="demand_granularity" id="granularity_15min" value="15min" autocomplete="off">
                        <label class="btn btn-outline-secondary" for="granularity_15min">15-min</label>

                        <input type="radio" class="btn-check" name="demand_granularity" id="granularity_hourly" value="hourly" autocomplete="off" checked>
                        <label class="btn btn-outline-secondary" for="granularity_hourly">Hourly</label>
                    </div>
                </div>

                <!-- Direction Toggle -->
                <div class="col-md-4">
                    <label class="demand-filter-label">Direction</label>
                    <div class="btn-group demand-direction-toggle" role="group">
                        <input type="radio" class="btn-check" name="demand_direction" id="direction_both" value="both" autocomplete="off" checked>
                        <label class="btn btn-outline-secondary" for="direction_both">Both</label>

                        <input type="radio" class="btn-check" name="demand_direction" id="direction_arr" value="arr" autocomplete="off">
                        <label class="btn btn-outline-secondary" for="direction_arr">Arrivals</label>

                        <input type="radio" class="btn-check" name="demand_direction" id="direction_dep" value="dep" autocomplete="off">
                        <label class="btn btn-outline-secondary" for="direction_dep">Departures</label>
                    </div>
                </div>

                <!-- Legend -->
                <div class="col-md-5 text-md-right pt-3">
                    <div class="demand-legend">
                        <span class="demand-legend-item">
                            <span class="demand-legend-color" style="background-color: #FF0000;"></span>Active
                        </span>
                        <span class="demand-legend-item">
                            <span class="demand-legend-color" style="background-color: #90EE90;"></span>Scheduled
                        </span>
                        <span class="demand-legend-item">
                            <span class="demand-legend-color" style="background-color: #0066FF;"></span>Proposed
                        </span>
                        <span class="demand-legend-item">
                            <span class="demand-legend-color" style="background-color: #000000;"></span>Arrived
                        </span>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Main Chart Area -->
    <section class="py-4">
        <div class="container-fluid">
            <div class="card">
                <div class="card-body p-0">
                    <div id="demand_chart" class="demand-chart-container"></div>
                </div>
            </div>
        </div>
    </section>

    <!-- Demand JavaScript -->
    <script src="assets/js/demand.js"></script>

    <script>
        // Update refresh status indicator when toggle changes
        $('#demand_auto_refresh').on('change', function() {
            const statusEl = $('#refresh_status');
            if ($(this).is(':checked')) {
                statusEl.text('15s').removeClass('demand-status-paused').addClass('demand-status-active');
            } else {
                statusEl.text('Paused').removeClass('demand-status-active').addClass('demand-status-paused');
            }
        });
    </script>

</body>
</html>
