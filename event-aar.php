<?php
/**
 * Event AAR/ADR Entry Page
 *
 * Allows users to manually enter hourly AAR/ADR data for events
 * that couldn't be automatically determined.
 */

include("sessions/handler.php");

if (session_status() == PHP_SESSION_NONE) {
    session_start();
    ob_start();
}

include("load/config.php");
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

// Get event details if editing
$event = null;
$hourly_data = [];
$configs = [];
$edit_mode = false;

if (isset($_GET['event_idx']) && isset($_GET['airport']) && $conn_adl) {
    $edit_mode = true;
    $event_idx = $_GET['event_idx'];
    $airport_icao = strtoupper($_GET['airport']);

    // Get event details
    $sql = "SELECT
                ea.event_idx,
                ea.airport_icao,
                e.event_name,
                e.event_type,
                e.start_utc,
                e.end_utc,
                e.duration_hours,
                ea.total_arrivals,
                ea.total_departures,
                ea.total_operations,
                ea.peak_vatsim_aar,
                ea.avg_vatsim_aar,
                ea.avg_vatsim_adr,
                ea.aar_source
            FROM dbo.vatusa_event_airport ea
            JOIN dbo.vatusa_event e ON ea.event_idx = e.event_idx
            WHERE ea.event_idx = ? AND ea.airport_icao = ?";

    $params = [$event_idx, $airport_icao];
    $stmt = sqlsrv_query($conn_adl, $sql, $params);

    if ($stmt) {
        $event = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($stmt);
    }

    // Get hourly data
    if ($event) {
        $sql = "SELECT hour_utc, hour_offset, arrivals, departures, throughput, vatsim_aar, vatsim_adr
                FROM dbo.vatusa_event_hourly
                WHERE event_idx = ? AND airport_icao = ?
                ORDER BY hour_offset";
        $stmt = sqlsrv_query($conn_adl, $sql, $params);
        if ($stmt) {
            while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
                $hourly_data[$row['hour_utc']] = $row;
            }
            sqlsrv_free_stmt($stmt);
        }

        // Get airport configs with distinct weather conditions
        $sql = "SELECT
                    c.config_id,
                    c.config_name,
                    COALESCE(arr.weather, dep.weather, 'VMC') as weather,
                    arr.rate_value as vatsim_aar,
                    dep.rate_value as vatsim_adr,
                    arr_rw.rate_value as rw_aar,
                    dep_rw.rate_value as rw_adr
                FROM dbo.airport_config c
                LEFT JOIN dbo.airport_config_rate arr
                    ON c.config_id = arr.config_id
                    AND arr.source = 'VATSIM' AND arr.rate_type = 'ARR'
                LEFT JOIN dbo.airport_config_rate dep
                    ON c.config_id = dep.config_id
                    AND dep.source = 'VATSIM' AND dep.rate_type = 'DEP'
                    AND dep.weather = COALESCE(arr.weather, dep.weather)
                LEFT JOIN dbo.airport_config_rate arr_rw
                    ON c.config_id = arr_rw.config_id
                    AND arr_rw.source = 'RW' AND arr_rw.rate_type = 'ARR'
                    AND arr_rw.weather = COALESCE(arr.weather, dep.weather)
                LEFT JOIN dbo.airport_config_rate dep_rw
                    ON c.config_id = dep_rw.config_id
                    AND dep_rw.source = 'RW' AND dep_rw.rate_type = 'DEP'
                    AND dep_rw.weather = COALESCE(arr.weather, dep.weather)
                WHERE c.airport_icao = ?
                  AND (arr.rate_value IS NOT NULL OR dep.rate_value IS NOT NULL)
                ORDER BY c.config_name,
                    CASE COALESCE(arr.weather, dep.weather)
                        WHEN 'VMC' THEN 1
                        WHEN 'LVMC' THEN 2
                        WHEN 'LIMC' THEN 3
                        WHEN 'IMC' THEN 4
                        ELSE 5
                    END";
        $stmt = sqlsrv_query($conn_adl, $sql, [$airport_icao]);
        if ($stmt) {
            while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
                $configs[] = $row;
            }
            sqlsrv_free_stmt($stmt);
        }
    }
}

// Generate hour slots
function generateHourSlots($start_utc, $end_utc) {
    $slots = [];
    $current = clone $start_utc;
    $current->setTime($current->format('H'), 0, 0);

    if ($start_utc->format('i') > 0) {
        $current->modify('+1 hour');
    }

    $offset = 0;
    while ($current <= $end_utc) {
        $hour_str = $current->format('H') . '00Z';
        $slots[] = [
            'hour_utc' => $hour_str,
            'hour_offset' => $offset,
            'datetime' => clone $current
        ];
        $current->modify('+1 hour');
        $offset++;
    }
    return $slots;
}

$hour_slots = [];
if ($event && $event['start_utc'] && $event['end_utc']) {
    $hour_slots = generateHourSlots($event['start_utc'], $event['end_utc']);
}
?>

<!DOCTYPE html>
<html>
<head>
    <?php $page_title = "vATCSCC Events Catch-Up"; include("load/header.php"); ?>
    <style>
        .hourly-grid {
            border: 1px solid #dee2e6;
            border-radius: 8px;
            overflow: hidden;
            max-height: 500px;
            overflow-y: auto;
        }
        .hourly-header {
            display: grid;
            grid-template-columns: 100px 80px 80px 1fr 1fr;
            gap: 10px;
            padding: 12px 15px;
            background: #343a40;
            color: white;
            font-weight: 500;
            font-size: 0.85rem;
            position: sticky;
            top: 0;
            z-index: 10;
        }
        .hourly-row {
            display: grid;
            grid-template-columns: 100px 80px 80px 1fr 1fr;
            gap: 10px;
            padding: 8px 15px;
            border-bottom: 1px solid #dee2e6;
            align-items: center;
        }
        .hourly-row:nth-child(even) {
            background: #f8f9fa;
        }
        .hourly-row:hover {
            background: #e9ecef;
        }
        .hour-label {
            font-family: monospace;
            font-weight: 600;
            color: #495057;
        }
        .rate-input {
            width: 100%;
            padding: 6px 8px;
            border: 2px solid #dee2e6;
            border-radius: 4px;
            font-size: 0.9rem;
            font-family: monospace;
            text-align: center;
        }
        .rate-input:focus {
            outline: none;
            border-color: #80bdff;
            background: #fff;
        }
        .config-badge {
            display: inline-block;
            padding: 4px 12px;
            background: #28a745;
            color: white;
            border-radius: 4px;
            font-size: 0.85rem;
            cursor: pointer;
            margin: 2px;
        }
        .config-badge:hover {
            opacity: 0.85;
        }
        .config-badge.weather-vmc {
            background: #28a745;
        }
        .config-badge.weather-lvmc {
            background: #fd7e14;
        }
        .config-badge.weather-limc {
            background: #17a2b8;
        }
        .config-badge.weather-imc {
            background: #dc3545;
        }
        .weather-label {
            font-size: 0.7rem;
            opacity: 0.9;
            margin-left: 4px;
            font-weight: normal;
        }
        .config-group {
            margin-bottom: 10px;
        }
        .config-group-name {
            font-weight: 600;
            margin-bottom: 4px;
            color: #495057;
            font-size: 0.9rem;
        }
        .info-card {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 15px;
        }
        .info-card label {
            font-size: 0.75rem;
            color: #6c757d;
            text-transform: uppercase;
            margin-bottom: 4px;
        }
        .info-card .value {
            font-size: 1.2rem;
            font-weight: 600;
            color: #212529;
        }
        .hourly-badge {
            display: inline-block;
            padding: 2px 8px;
            background: #17a2b8;
            color: white;
            border-radius: 10px;
            font-size: 0.75rem;
        }
        .hourly-badge.empty {
            background: #dc3545;
        }
    </style>
</head>

<body>

<?php include('load/nav.php'); ?>

<section class="d-flex align-items-center position-relative bg-position-center overflow-hidden pt-6 bg-dark text-light" style="min-height: 120px;">
    <div class="container-fluid pt-2 pb-3">
        <center>
            <h1>Event <span class="text-info">AAR/ADR</span> Entry</h1>
            <p class="mb-0">Enter hourly arrival/departure rates for events</p>
        </center>
    </div>
</section>

<div class="container-fluid mt-4 mb-5">
    <?php if (!$conn_adl): ?>
        <div class="alert alert-danger">
            <i class="fas fa-exclamation-triangle"></i> ADL database connection not available.
        </div>
    <?php elseif ($edit_mode && $event): ?>
        <!-- Edit Mode -->
        <div class="row mb-4">
            <div class="col-12">
                <a href="event-aar.php" class="btn btn-outline-secondary btn-sm">
                    <i class="fas fa-arrow-left"></i> Back to List
                </a>
            </div>
        </div>

        <div class="card mb-4">
            <div class="card-header bg-dark text-white">
                <h5 class="mb-0"><?= htmlspecialchars($event['airport_icao']) ?> - <?= htmlspecialchars($event['event_name']) ?></h5>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-2">
                        <div class="info-card">
                            <label>Event Type</label>
                            <div class="value"><?= htmlspecialchars($event['event_type'] ?? 'N/A') ?></div>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="info-card">
                            <label>Start (UTC)</label>
                            <div class="value"><?= $event['start_utc'] ? $event['start_utc']->format('Y-m-d H:i') : 'N/A' ?></div>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="info-card">
                            <label>End (UTC)</label>
                            <div class="value"><?= $event['end_utc'] ? $event['end_utc']->format('Y-m-d H:i') : 'N/A' ?></div>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="info-card">
                            <label>Total Arrivals</label>
                            <div class="value text-primary"><?= $event['total_arrivals'] ?? 0 ?></div>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="info-card">
                            <label>Total Departures</label>
                            <div class="value text-primary"><?= $event['total_departures'] ?? 0 ?></div>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="info-card">
                            <label>Duration</label>
                            <div class="value"><?= $event['duration_hours'] ? number_format($event['duration_hours'], 1) . 'h' : 'N/A' ?></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <?php if (count($configs) > 0): ?>
        <div class="card mb-4">
            <div class="card-header">
                <h6 class="mb-0">Quick Apply: Airport Configurations</h6>
            </div>
            <div class="card-body">
                <p class="text-muted small">Click a config to apply its rates to all hourly slots. Colors indicate weather:
                    <span class="config-badge weather-vmc" style="cursor:default;font-size:0.75rem;padding:2px 6px;">VMC</span>
                    <span class="config-badge weather-lvmc" style="cursor:default;font-size:0.75rem;padding:2px 6px;">LVMC</span>
                    <span class="config-badge weather-limc" style="cursor:default;font-size:0.75rem;padding:2px 6px;">LIMC</span>
                    <span class="config-badge weather-imc" style="cursor:default;font-size:0.75rem;padding:2px 6px;">IMC</span>
                </p>
                <?php
                // Group configs by config_name
                $grouped_configs = [];
                foreach ($configs as $config) {
                    if ($config['vatsim_aar'] || $config['vatsim_adr']) {
                        $name = $config['config_name'];
                        if (!isset($grouped_configs[$name])) {
                            $grouped_configs[$name] = [];
                        }
                        $grouped_configs[$name][] = $config;
                    }
                }
                ?>
                <?php foreach ($grouped_configs as $config_name => $weather_variants): ?>
                <div class="config-group">
                    <div class="config-group-name"><?= htmlspecialchars($config_name) ?></div>
                    <?php foreach ($weather_variants as $config): ?>
                        <?php $weather_class = 'weather-' . strtolower($config['weather'] ?? 'vmc'); ?>
                        <span class="config-badge <?= $weather_class ?>"
                              onclick="applyConfig(<?= $config['vatsim_aar'] ?? 'null' ?>, <?= $config['vatsim_adr'] ?? 'null' ?>, '<?= htmlspecialchars($config['config_name']) ?> [<?= $config['weather'] ?? 'VMC' ?>]')"
                              title="<?= htmlspecialchars($config['config_name']) ?> - <?= $config['weather'] ?? 'VMC' ?>">
                            <?= $config['weather'] ?? 'VMC' ?>
                            <span class="weather-label">(<?= $config['vatsim_aar'] ?? '-' ?>/<?= $config['vatsim_adr'] ?? '-' ?>)</span>
                        </span>
                    <?php endforeach; ?>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <div class="card">
            <div class="card-header">
                <h6 class="mb-0">Hourly Rate Entry</h6>
            </div>
            <div class="card-body">
                <form id="hourlyForm">
                    <input type="hidden" name="event_idx" value="<?= htmlspecialchars($event['event_idx']) ?>">
                    <input type="hidden" name="airport_icao" value="<?= htmlspecialchars($event['airport_icao']) ?>">

                    <div class="hourly-grid">
                        <div class="hourly-header">
                            <span>Hour (UTC)</span>
                            <span class="text-center">Actual Arr</span>
                            <span class="text-center">Actual Dep</span>
                            <span class="text-center">VATSIM AAR</span>
                            <span class="text-center">VATSIM ADR</span>
                        </div>

                        <?php foreach ($hour_slots as $slot): ?>
                            <?php $existing = $hourly_data[$slot['hour_utc']] ?? []; ?>
                            <div class="hourly-row">
                                <span class="hour-label"><?= $slot['hour_utc'] ?></span>
                                <span class="text-center text-muted"><?= $existing['arrivals'] ?? '-' ?></span>
                                <span class="text-center text-muted"><?= $existing['departures'] ?? '-' ?></span>
                                <input type="hidden" name="hour_offset_<?= $slot['hour_utc'] ?>" value="<?= $slot['hour_offset'] ?>">
                                <input type="number" name="vatsim_aar_<?= $slot['hour_utc'] ?>"
                                       class="rate-input aar-input"
                                       value="<?= $existing['vatsim_aar'] ?? '' ?>"
                                       min="0" max="200" step="1" placeholder="AAR">
                                <input type="number" name="vatsim_adr_<?= $slot['hour_utc'] ?>"
                                       class="rate-input adr-input"
                                       value="<?= $existing['vatsim_adr'] ?? '' ?>"
                                       min="0" max="200" step="1" placeholder="ADR">
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <div class="mt-4">
                        <button type="submit" class="btn btn-success">
                            <i class="fas fa-save"></i> Save All Hourly Rates
                        </button>
                        <button type="button" class="btn btn-warning" onclick="fillAllEmpty()">
                            <i class="fas fa-fill"></i> Fill All Empty
                        </button>
                        <a href="event-aar.php" class="btn btn-secondary">Cancel</a>
                    </div>
                </form>
            </div>
        </div>

    <?php else: ?>
        <!-- List Mode -->
        <div class="card">
            <div class="card-header bg-dark text-white">
                <h5 class="mb-0">Events Missing AAR/ADR Data</h5>
            </div>
            <div class="card-body">
                <table class="table table-sm table-striped table-bordered" id="eventsTable">
                    <thead class="table-dark">
                        <tr>
                            <th>Date</th>
                            <th>Airport</th>
                            <th>Event Name</th>
                            <th>Type</th>
                            <th class="text-center">Arrivals</th>
                            <th class="text-center">Departures</th>
                            <th class="text-center">Duration</th>
                            <th class="text-center">Hourly</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody id="eventsBody">
                        <tr><td colspan="9" class="text-center">Loading...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php include('load/footer.php'); ?>

<script>
<?php if ($edit_mode && $event): ?>
    const eventIdx = '<?= addslashes($event['event_idx']) ?>';
    const airportIcao = '<?= addslashes($event['airport_icao']) ?>';

    function applyConfig(aar, adr, configName) {
        if (!confirm(`Apply ${configName} rates (AAR: ${aar}, ADR: ${adr}) to all hours?`)) {
            return;
        }

        document.querySelectorAll('.aar-input').forEach(input => {
            if (aar !== null) input.value = aar;
        });
        document.querySelectorAll('.adr-input').forEach(input => {
            if (adr !== null) input.value = adr;
        });
    }

    function fillAllEmpty() {
        const aar = prompt('Enter AAR value for all empty slots:', '');
        if (aar === null) return;

        const adr = prompt('Enter ADR value for all empty slots:', aar);
        if (adr === null) return;

        document.querySelectorAll('.aar-input').forEach(input => {
            if (!input.value && aar) input.value = aar;
        });
        document.querySelectorAll('.adr-input').forEach(input => {
            if (!input.value && adr) input.value = adr;
        });
    }

    document.getElementById('hourlyForm').addEventListener('submit', function(e) {
        e.preventDefault();

        const formData = new FormData(this);

        fetch('api/event-aar/update.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                Swal.fire({
                    toast: true,
                    position: 'bottom-right',
                    icon: 'success',
                    title: 'Saved',
                    text: data.message,
                    timer: 3000,
                    showConfirmButton: false
                });
            } else {
                Swal.fire({
                    icon: 'error',
                    title: 'Error',
                    text: data.error || 'Failed to save'
                });
            }
        })
        .catch(err => {
            Swal.fire({
                icon: 'error',
                title: 'Error',
                text: err.message
            });
        });
    });
<?php else: ?>
    // Load events list
    function loadEvents() {
        fetch('api/event-aar/list.php')
            .then(response => response.json())
            .then(data => {
                const tbody = document.getElementById('eventsBody');
                if (data.events && data.events.length > 0) {
                    tbody.innerHTML = data.events.map(e => `
                        <tr>
                            <td>${e.start_date}</td>
                            <td><strong>${e.airport_icao}</strong></td>
                            <td title="${e.event_name}">${e.event_name.substring(0, 50)}${e.event_name.length > 50 ? '...' : ''}</td>
                            <td>${e.event_type || '-'}</td>
                            <td class="text-center">${e.total_arrivals || 0}</td>
                            <td class="text-center">${e.total_departures || 0}</td>
                            <td class="text-center">${e.duration_hours ? parseFloat(e.duration_hours).toFixed(1) + 'h' : '-'}</td>
                            <td class="text-center">
                                <span class="hourly-badge ${e.hourly_count > 0 ? '' : 'empty'}">${e.hourly_count || 0}h</span>
                            </td>
                            <td>
                                <a href="event-aar.php?event_idx=${encodeURIComponent(e.event_idx)}&airport=${e.airport_icao}"
                                   class="btn btn-sm btn-primary">Edit</a>
                            </td>
                        </tr>
                    `).join('');
                } else {
                    tbody.innerHTML = '<tr><td colspan="9" class="text-center text-success">All events have AAR/ADR data!</td></tr>';
                }
            })
            .catch(err => {
                document.getElementById('eventsBody').innerHTML =
                    `<tr><td colspan="9" class="text-center text-danger">Error loading events: ${err.message}</td></tr>`;
            });
    }

    document.addEventListener('DOMContentLoaded', loadEvents);
<?php endif; ?>
</script>

</body>
</html>
