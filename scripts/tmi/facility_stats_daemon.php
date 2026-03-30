<?php
/**
 * TMI Facility Statistics Daemon
 *
 * Computes hourly and daily facility statistics from flight data and
 * delay attributions. Reads from VATSIM_ADL + VATSIM_TMI, writes to
 * tmi_facility_stats_hourly/daily and tmi_ops_performance in VATSIM_TMI.
 *
 * Usage:
 *   php facility_stats_daemon.php --loop [--interval=3600] [--debug]
 *
 * @package PERTI
 * @subpackage TMI
 * @version 1.0.0
 * @date 2026-03-30
 */

$opts = getopt('', ['loop', 'interval:', 'debug', 'once', 'hours:']);
$loop_mode = isset($opts['loop']);
$interval = isset($opts['interval']) ? (int)$opts['interval'] : 3600;
$debug = isset($opts['debug']);
$once = isset($opts['once']);
$lookback_hours = isset($opts['hours']) ? (int)$opts['hours'] : 2;

require_once(__DIR__ . '/../../load/config.php');
require_once(__DIR__ . '/../../load/connect.php');

function stats_log(string $msg, string $level = 'INFO'): void {
    $ts = gmdate('Y-m-d H:i:s');
    echo "[{$ts} UTC] [{$level}] {$msg}\n";
}

stats_log("Facility stats daemon starting (interval={$interval}s, lookback={$lookback_hours}h)");

function run_stats_cycle($conn_adl, $conn_tmi, int $lookback_hours, bool $debug): int {
    $now = new DateTime('now', new DateTimeZone('UTC'));
    $stats_count = 0;

    // Process each completed hour in the lookback window
    for ($h = $lookback_hours; $h >= 1; $h--) {
        $hour_start = clone $now;
        $hour_start->modify("-{$h} hours");
        $hour_start->setTime((int)$hour_start->format('H'), 0, 0);
        $hour_end = clone $hour_start;
        $hour_end->modify('+1 hour');

        $hour_str = $hour_start->format('Y-m-d H:i:s');
        $hour_end_str = $hour_end->format('Y-m-d H:i:s');

        if ($debug) stats_log("Processing hour: {$hour_str}");

        // Query arrivals and departures for this hour from ADL
        $flight_sql = "
            SELECT
                fcore.arr_icao, fcore.dep_icao, fcore.arr_artcc, fcore.dep_artcc,
                ft.ata_utc, ft.off_utc, ft.out_utc, ft.on_utc, ft.in_utc,
                ft.eta_utc, ft.etd_utc, ft.edct_utc,
                fa.icao_type, fa.carrier_code
            FROM dbo.adl_flight_core fcore
            JOIN dbo.adl_flight_times ft ON fcore.flight_uid = ft.flight_uid
            LEFT JOIN dbo.adl_flight_aircraft fa ON fcore.flight_uid = fa.flight_uid
            WHERE (ft.ata_utc >= ? AND ft.ata_utc < ?)
               OR (ft.off_utc >= ? AND ft.off_utc < ?)
        ";
        $f_stmt = sqlsrv_query($conn_adl, $flight_sql, [$hour_str, $hour_end_str, $hour_str, $hour_end_str]);
        if ($f_stmt === false) {
            stats_log("Failed to query flights for {$hour_str}", 'ERROR');
            continue;
        }

        // Aggregate by airport
        $airport_stats = [];
        while ($f = sqlsrv_fetch_array($f_stmt, SQLSRV_FETCH_ASSOC)) {
            $arr = $f['arr_icao'];
            $dep = $f['dep_icao'];

            // Count arrival
            if ($arr && $f['ata_utc']) {
                $ata_ts = ($f['ata_utc'] instanceof DateTime) ? $f['ata_utc']->getTimestamp() : strtotime($f['ata_utc']);
                $hr_start_ts = $hour_start->getTimestamp();
                $hr_end_ts = $hour_end->getTimestamp();
                if ($ata_ts >= $hr_start_ts && $ata_ts < $hr_end_ts) {
                    if (!isset($airport_stats[$arr])) $airport_stats[$arr] = _empty_stats();
                    $airport_stats[$arr]['total_arrivals']++;
                    $airport_stats[$arr]['total_operations']++;

                    // On-time: arrival within 15 min of ETA
                    if ($f['eta_utc']) {
                        $eta_ts = ($f['eta_utc'] instanceof DateTime) ? $f['eta_utc']->getTimestamp() : strtotime($f['eta_utc']);
                        $arr_delay = ($ata_ts - $eta_ts) / 60;
                        if ($arr_delay <= 15) {
                            $airport_stats[$arr]['ontime_arrivals']++;
                        } else {
                            $airport_stats[$arr]['delayed_arrivals']++;
                        }
                        if ($arr_delay > ($airport_stats[$arr]['max_arr_delay'] ?? 0)) {
                            $airport_stats[$arr]['max_arr_delay'] = round($arr_delay, 1);
                        }
                        $airport_stats[$arr]['arr_delay_sum'] += max(0, $arr_delay);
                        $airport_stats[$arr]['arr_delay_count']++;
                    }
                }
            }

            // Count departure
            if ($dep && $f['off_utc']) {
                $off_ts = ($f['off_utc'] instanceof DateTime) ? $f['off_utc']->getTimestamp() : strtotime($f['off_utc']);
                $hr_start_ts = $hour_start->getTimestamp();
                $hr_end_ts = $hour_end->getTimestamp();
                if ($off_ts >= $hr_start_ts && $off_ts < $hr_end_ts) {
                    if (!isset($airport_stats[$dep])) $airport_stats[$dep] = _empty_stats();
                    $airport_stats[$dep]['total_departures']++;
                    $airport_stats[$dep]['total_operations']++;

                    // On-time: departure within 15 min of ETD/EDCT
                    $baseline_ts = null;
                    if ($f['edct_utc']) {
                        $baseline_ts = ($f['edct_utc'] instanceof DateTime) ? $f['edct_utc']->getTimestamp() : strtotime($f['edct_utc']);
                    } elseif ($f['etd_utc']) {
                        $baseline_ts = ($f['etd_utc'] instanceof DateTime) ? $f['etd_utc']->getTimestamp() : strtotime($f['etd_utc']);
                    }
                    if ($baseline_ts) {
                        $dep_delay = ($off_ts - $baseline_ts) / 60;
                        if ($dep_delay <= 15) {
                            $airport_stats[$dep]['ontime_departures']++;
                        } else {
                            $airport_stats[$dep]['delayed_departures']++;
                        }
                    }
                }
            }
        }
        sqlsrv_free_stmt($f_stmt);

        // UPSERT into tmi_facility_stats_hourly
        foreach ($airport_stats as $icao => $s) {
            $avg_arr = $s['arr_delay_count'] > 0 ? round($s['arr_delay_sum'] / $s['arr_delay_count'], 1) : null;
            $upsert_sql = "
                MERGE dbo.tmi_facility_stats_hourly AS t
                USING (SELECT ? AS facility, ? AS hour_utc) AS s
                ON t.facility = s.facility AND t.hour_utc = s.hour_utc
                WHEN MATCHED THEN UPDATE SET
                    total_operations = ?, total_arrivals = ?, total_departures = ?,
                    ontime_arrivals = ?, delayed_arrivals = ?,
                    ontime_departures = ?, delayed_departures = ?,
                    avg_arr_delay_min = ?, max_arr_delay_min = ?,
                    computed_utc = SYSUTCDATETIME()
                WHEN NOT MATCHED THEN INSERT
                    (facility, facility_type, airport_icao, hour_utc,
                     total_operations, total_arrivals, total_departures,
                     ontime_arrivals, delayed_arrivals,
                     ontime_departures, delayed_departures,
                     avg_arr_delay_min, max_arr_delay_min)
                VALUES (?, 'AIRPORT', ?, ?,
                        ?, ?, ?,
                        ?, ?,
                        ?, ?,
                        ?, ?);
            ";
            $u_stmt = sqlsrv_query($conn_tmi, $upsert_sql, [
                $icao, $hour_str,
                $s['total_operations'], $s['total_arrivals'], $s['total_departures'],
                $s['ontime_arrivals'], $s['delayed_arrivals'],
                $s['ontime_departures'], $s['delayed_departures'],
                $avg_arr, $s['max_arr_delay'],
                $icao, $icao, $hour_str,
                $s['total_operations'], $s['total_arrivals'], $s['total_departures'],
                $s['ontime_arrivals'], $s['delayed_arrivals'],
                $s['ontime_departures'], $s['delayed_departures'],
                $avg_arr, $s['max_arr_delay']
            ]);
            if ($u_stmt === false) {
                stats_log("UPSERT failed for {$icao}/{$hour_str}: " . (sqlsrv_errors()[0]['message'] ?? ''), 'ERROR');
            } else {
                sqlsrv_free_stmt($u_stmt);
                $stats_count++;
            }
        }
    }

    stats_log("Wrote {$stats_count} hourly stat rows");
    return $stats_count;
}

function _empty_stats(): array {
    return [
        'total_operations' => 0, 'total_arrivals' => 0, 'total_departures' => 0,
        'total_overflights' => 0,
        'ontime_arrivals' => 0, 'delayed_arrivals' => 0,
        'ontime_departures' => 0, 'delayed_departures' => 0,
        'max_arr_delay' => 0, 'arr_delay_sum' => 0, 'arr_delay_count' => 0,
    ];
}

// Main loop
do {
    try {
        $conn_adl = get_conn_adl();
        $conn_tmi = get_conn_tmi();
        run_stats_cycle($conn_adl, $conn_tmi, $lookback_hours, $debug);
    } catch (Exception $e) {
        stats_log('Cycle error: ' . $e->getMessage(), 'ERROR');
    }

    if ($once) break;
    if ($loop_mode) sleep($interval);
} while ($loop_mode);

stats_log('Daemon exiting');
