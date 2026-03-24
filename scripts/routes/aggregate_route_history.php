<?php
/**
 * Historical Route Aggregation Job
 *
 * Reads completed flights from Azure SQL (VATSIM_ADL) and populates
 * the star schema in MySQL (perti_site) for the Historical Routes page.
 *
 * Usage:
 *   php scripts/routes/aggregate_route_history.php [--batch=5000] [--limit=0] [--dry-run]
 *
 * Options:
 *   --batch=N   Batch size (default 5000)
 *   --limit=N   Max total rows to process (0 = unlimited, default 0)
 *   --dry-run   Print stats without writing to MySQL
 *
 * Schedule: Nightly via cron or manual invocation.
 * Data flow: Azure SQL (VATSIM_ADL) → MySQL (perti_site)
 */

// Parse CLI args
$options = getopt('', ['batch::', 'limit::', 'dry-run', 'help']);
if (isset($options['help'])) {
    echo "Usage: php aggregate_route_history.php [--batch=5000] [--limit=0] [--dry-run]\n";
    exit(0);
}
$batchSize = isset($options['batch']) ? (int)$options['batch'] : 5000;
$maxLimit  = isset($options['limit']) ? (int)$options['limit'] : 0;
$dryRun    = isset($options['dry-run']);

// Bootstrap
require_once __DIR__ . '/../../load/config.php';
require_once __DIR__ . '/../../load/connect.php';
require_once __DIR__ . '/normalize_route.php';

function job_log(string $msg): void {
    echo '[' . gmdate('Y-m-d H:i:s') . ' UTC] ' . $msg . "\n";
}

// ──────────────────────────────────────────────────────────
// 1. MySQL connection (already available as $conn_pdo)
// ──────────────────────────────────────────────────────────
if (!$conn_pdo) {
    job_log('ERROR: MySQL PDO connection not available');
    exit(1);
}

// ──────────────────────────────────────────────────────────
// 2. Azure SQL connection
// ──────────────────────────────────────────────────────────
$conn_adl = get_conn_adl();
if (!$conn_adl) {
    job_log('ERROR: Azure SQL (ADL) connection not available');
    exit(1);
}

// ──────────────────────────────────────────────────────────
// 3. Load sync state
// ──────────────────────────────────────────────────────────
$stateStmt = $conn_pdo->query("SELECT * FROM route_history_sync_state WHERE id = 1");
$state = $stateStmt->fetch(PDO::FETCH_ASSOC);
if (!$state) {
    job_log('ERROR: route_history_sync_state row missing');
    exit(1);
}
if ($state['status'] === 'running') {
    job_log('WARNING: Previous run still marked as running. Resetting to idle.');
}

$lastFlightUid = (int)$state['last_flight_uid'];
job_log("Starting from flight_uid > $lastFlightUid");

// Mark as running
$conn_pdo->exec("UPDATE route_history_sync_state SET status = 'running', last_run_utc = UTC_TIMESTAMP() WHERE id = 1");

// ──────────────────────────────────────────────────────────
// 4. Cache ACD_Data from Azure SQL (~2,500 rows)
// ──────────────────────────────────────────────────────────
$acdCache = [];
$acdResult = sqlsrv_query($conn_adl, "SELECT ICAO_Code, FAA_Designator, Manufacturer, Model_FAA, FAA_Weight, ICAO_WTC, AAC, Physical_Class_Engine, Num_Engines FROM ACD_Data WHERE ICAO_Code IS NOT NULL");
if ($acdResult) {
    while ($row = sqlsrv_fetch_array($acdResult, SQLSRV_FETCH_ASSOC)) {
        $acdCache[strtoupper(trim($row['ICAO_Code']))] = $row;
    }
    sqlsrv_free_stmt($acdResult);
}
job_log("Cached " . count($acdCache) . " ACD_Data entries");

// ──────────────────────────────────────────────────────────
// 5. Cache operator group mappings from MySQL
// ──────────────────────────────────────────────────────────
$opGroups = [];
$ogStmt = $conn_pdo->query("SELECT airline_icao, operator_group FROM route_operator_groups");
while ($row = $ogStmt->fetch(PDO::FETCH_ASSOC)) {
    $opGroups[$row['airline_icao']] = $row['operator_group'];
}
job_log("Cached " . count($opGroups) . " operator group mappings");

// ──────────────────────────────────────────────────────────
// 6. Prepare MySQL statements
// ──────────────────────────────────────────────────────────
$stmtDimRoute = $conn_pdo->prepare("
    INSERT INTO dim_route (normalized_route, route_hash, sample_raw_route, waypoint_count, first_seen, last_seen)
    VALUES (:normalized, :hash, :sample, :wpc, :first, :last)
    ON DUPLICATE KEY UPDATE
        first_seen = LEAST(first_seen, VALUES(first_seen)),
        last_seen = GREATEST(last_seen, VALUES(last_seen)),
        row_updated_utc = CURRENT_TIMESTAMP
");
$stmtDimRouteId = $conn_pdo->prepare("SELECT route_dim_id FROM dim_route WHERE route_hash = :hash");

$stmtDimAircraft = $conn_pdo->prepare("
    INSERT INTO dim_aircraft_type (icao_code, faa_designator, manufacturer, model, weight_class, wake_category, faa_weight, icao_wtc, engine_type, engine_count, aac)
    VALUES (:icao, :faa, :mfr, :model, :wc, :wake, :faa_wt, :icao_wtc, :eng, :eng_cnt, :aac)
    ON DUPLICATE KEY UPDATE row_updated_utc = CURRENT_TIMESTAMP
");
$stmtDimAircraftId = $conn_pdo->prepare("SELECT aircraft_dim_id FROM dim_aircraft_type WHERE icao_code = :icao");

$stmtDimOperator = $conn_pdo->prepare("
    INSERT INTO dim_operator (airline_icao, airline_name, callsign_prefix, operator_group)
    VALUES (:icao, :name, :prefix, :grp)
    ON DUPLICATE KEY UPDATE airline_name = VALUES(airline_name), row_updated_utc = CURRENT_TIMESTAMP
");
$stmtDimOperatorId = $conn_pdo->prepare("SELECT operator_dim_id FROM dim_operator WHERE airline_icao = :icao");

$stmtDimTime = $conn_pdo->prepare("
    INSERT IGNORE INTO dim_time (time_dim_id, flight_date, year_val, month_val, day_of_week, hour_utc, season, is_weekend)
    VALUES (:id, :date, :year, :month, :dow, :hour, :season, :weekend)
");

$stmtFact = $conn_pdo->prepare("
    INSERT IGNORE INTO route_history_facts
    (flight_uid, route_dim_id, aircraft_dim_id, operator_dim_id, time_dim_id,
     origin_icao, dest_icao, origin_tracon, origin_artcc, dest_tracon, dest_artcc,
     raw_route, gcd_nm, ete_minutes, altitude_ft, partition_month)
    VALUES
    (:uid, :rdim, :adim, :odim, :tdim,
     :orig, :dest, :ot, :oa, :dt, :da,
     :raw, :gcd, :ete, :alt, :pm)
");

// ──────────────────────────────────────────────────────────
// 7. Batch processing loop
// ──────────────────────────────────────────────────────────
$totalInserted = 0;
$totalProcessed = 0;
$batchNum = 0;

// In-memory dim caches to avoid repeated SELECT lookups
$routeHashCache = [];    // route_hash_hex => route_dim_id
$aircraftCache = [];     // icao_code => aircraft_dim_id
$operatorCache = [];     // airline_icao => operator_dim_id

// Azure SQL doesn't support LIMIT — use TOP with offset via flight_uid
$currentUid = $lastFlightUid;

$batchQuery = "
    SELECT TOP " . (int)$batchSize . "
        c.flight_uid,
        c.callsign,
        c.first_seen_utc,
        p.fp_dept_icao,
        p.fp_dest_icao,
        p.fp_dept_tracon,
        p.fp_dept_artcc,
        p.fp_dest_tracon,
        p.fp_dest_artcc,
        p.fp_route,
        p.gcd_nm,
        p.fp_enroute_minutes,
        p.fp_altitude_ft,
        a.aircraft_icao,
        a.weight_class,
        a.wake_category,
        a.engine_type,
        a.engine_count,
        a.airline_icao,
        a.airline_name
    FROM adl_flight_core c
    JOIN adl_flight_plan p ON p.flight_uid = c.flight_uid
    LEFT JOIN adl_flight_aircraft a ON a.flight_uid = c.flight_uid
    WHERE c.flight_uid > ?
      AND p.fp_route IS NOT NULL
      AND LEN(p.fp_route) > 10
    ORDER BY c.flight_uid ASC
";

while (true) {
    $batchNum++;
    $batchResult = sqlsrv_query($conn_adl, $batchQuery, [$currentUid]);
    if ($batchResult === false) {
        $errors = sqlsrv_errors();
        job_log("ERROR: Azure SQL query failed: " . json_encode($errors));
        break;
    }

    $rows = [];
    while ($row = sqlsrv_fetch_array($batchResult, SQLSRV_FETCH_ASSOC)) {
        $rows[] = $row;
    }
    sqlsrv_free_stmt($batchResult);

    if (empty($rows)) {
        job_log("No more rows to process");
        break;
    }

    job_log("Batch $batchNum: processing " . count($rows) . " flights (uid range " . $rows[0]['flight_uid'] . " - " . end($rows)['flight_uid'] . ")");

    if ($dryRun) {
        $currentUid = (int)end($rows)['flight_uid'];
        $totalProcessed += count($rows);
        if ($maxLimit > 0 && $totalProcessed >= $maxLimit) break;
        continue;
    }

    // Wrap batch in transaction
    $conn_pdo->beginTransaction();
    $batchInserted = 0;

    try {
        foreach ($rows as $row) {
            $flightUid = (int)$row['flight_uid'];
            $rawRoute = trim($row['fp_route']);

            // Normalize route
            $norm = normalize_route($rawRoute);
            $routeHashHex = bin2hex($norm['hash']);

            // ── dim_route ──
            if (!isset($routeHashCache[$routeHashHex])) {
                // First seen date from first_seen_utc
                $firstSeen = ($row['first_seen_utc'] instanceof DateTime)
                    ? $row['first_seen_utc']->format('Y-m-d')
                    : substr($row['first_seen_utc'], 0, 10);

                $stmtDimRoute->execute([
                    ':normalized' => $norm['normalized'],
                    ':hash'       => $norm['hash'],
                    ':sample'     => $rawRoute,
                    ':wpc'        => $norm['waypoint_count'],
                    ':first'      => $firstSeen,
                    ':last'       => $firstSeen,
                ]);
                $stmtDimRouteId->execute([':hash' => $norm['hash']]);
                $dimRow = $stmtDimRouteId->fetch(PDO::FETCH_ASSOC);
                $routeHashCache[$routeHashHex] = (int)$dimRow['route_dim_id'];
            }
            $routeDimId = $routeHashCache[$routeHashHex];

            // ── dim_aircraft_type ──
            $aircraftDimId = null;
            $aircraftIcao = trim($row['aircraft_icao'] ?? '');
            if ($aircraftIcao !== '') {
                if (!isset($aircraftCache[$aircraftIcao])) {
                    $acd = $acdCache[strtoupper($aircraftIcao)] ?? [];
                    $stmtDimAircraft->execute([
                        ':icao'     => $aircraftIcao,
                        ':faa'      => $acd['FAA_Designator'] ?? null,
                        ':mfr'      => $acd['Manufacturer'] ?? null,
                        ':model'    => $acd['Model_FAA'] ?? null,
                        ':wc'       => trim($row['weight_class'] ?? '') ?: null,
                        ':wake'     => trim($row['wake_category'] ?? '') ?: null,
                        ':faa_wt'   => $acd['FAA_Weight'] ?? null,
                        ':icao_wtc' => $acd['ICAO_WTC'] ?? null,
                        ':eng'      => trim($row['engine_type'] ?? '') ?: null,
                        ':eng_cnt'  => $row['engine_count'] ?? null,
                        ':aac'      => $acd['AAC'] ?? null,
                    ]);
                    $stmtDimAircraftId->execute([':icao' => $aircraftIcao]);
                    $dimRow = $stmtDimAircraftId->fetch(PDO::FETCH_ASSOC);
                    $aircraftCache[$aircraftIcao] = (int)$dimRow['aircraft_dim_id'];
                }
                $aircraftDimId = $aircraftCache[$aircraftIcao];
            }

            // ── dim_operator ──
            $operatorDimId = null;
            $airlineIcao = trim($row['airline_icao'] ?? '');
            if ($airlineIcao !== '') {
                if (!isset($operatorCache[$airlineIcao])) {
                    $callsign = trim($row['callsign'] ?? '');
                    $prefix = $callsign !== '' ? substr($callsign, 0, 3) : null;
                    $group = $opGroups[$airlineIcao] ?? 'other';
                    $stmtDimOperator->execute([
                        ':icao'   => $airlineIcao,
                        ':name'   => trim($row['airline_name'] ?? '') ?: null,
                        ':prefix' => $prefix,
                        ':grp'    => $group,
                    ]);
                    $stmtDimOperatorId->execute([':icao' => $airlineIcao]);
                    $dimRow = $stmtDimOperatorId->fetch(PDO::FETCH_ASSOC);
                    $operatorCache[$airlineIcao] = (int)$dimRow['operator_dim_id'];
                }
                $operatorDimId = $operatorCache[$airlineIcao];
            }

            // ── dim_time ──
            // first_seen_utc may be DateTime object (sqlsrv) or string
            if ($row['first_seen_utc'] instanceof DateTime) {
                $dt = $row['first_seen_utc'];
            } else {
                $dt = new DateTime($row['first_seen_utc'], new DateTimeZone('UTC'));
            }
            $yearVal  = (int)$dt->format('Y');
            $monthVal = (int)$dt->format('n');
            $dayOfWeek = (int)$dt->format('N'); // 1=Mon..7=Sun ISO 8601
            $hourUtc   = (int)$dt->format('G');
            $timeDimId = (int)$dt->format('YmdH');
            $flightDate = $dt->format('Y-m-d');
            $isWeekend = ($dayOfWeek >= 6) ? 1 : 0;

            // Season: meteorological (northern hemisphere)
            if ($monthVal >= 3 && $monthVal <= 5) $season = 'spring';
            elseif ($monthVal >= 6 && $monthVal <= 8) $season = 'summer';
            elseif ($monthVal >= 9 && $monthVal <= 11) $season = 'fall';
            else $season = 'winter';

            $stmtDimTime->execute([
                ':id'      => $timeDimId,
                ':date'    => $flightDate,
                ':year'    => $yearVal,
                ':month'   => $monthVal,
                ':dow'     => $dayOfWeek,
                ':hour'    => $hourUtc,
                ':season'  => $season,
                ':weekend' => $isWeekend,
            ]);

            // ── route_history_facts ──
            $partitionMonth = (int)$dt->format('Ym');

            $stmtFact->execute([
                ':uid'  => $flightUid,
                ':rdim' => $routeDimId,
                ':adim' => $aircraftDimId,
                ':odim' => $operatorDimId,
                ':tdim' => $timeDimId,
                ':orig' => $row['fp_dept_icao'],
                ':dest' => $row['fp_dest_icao'],
                ':ot'   => $row['fp_dept_tracon'] ?: null,
                ':oa'   => $row['fp_dept_artcc'] ?: null,
                ':dt'   => $row['fp_dest_tracon'] ?: null,
                ':da'   => $row['fp_dest_artcc'] ?: null,
                ':raw'  => $rawRoute,
                ':gcd'  => $row['gcd_nm'],
                ':ete'  => $row['fp_enroute_minutes'],
                ':alt'  => $row['fp_altitude_ft'],
                ':pm'   => $partitionMonth,
            ]);

            $batchInserted++;
            $currentUid = $flightUid;
        }

        // Update sync state within the same transaction
        $conn_pdo->exec("UPDATE route_history_sync_state SET last_flight_uid = $currentUid, rows_inserted = $batchInserted WHERE id = 1");
        $conn_pdo->commit();
        $totalInserted += $batchInserted;
        $totalProcessed += count($rows);

        job_log("  Committed: $batchInserted facts (total so far: $totalInserted)");

    } catch (Exception $e) {
        $conn_pdo->rollBack();
        job_log("ERROR: Batch $batchNum failed, rolled back: " . $e->getMessage());
        // Continue to next batch — the sync state was not updated so these will be retried
    }

    // Check limit
    if ($maxLimit > 0 && $totalProcessed >= $maxLimit) {
        job_log("Reached limit of $maxLimit rows");
        break;
    }

    // Small sleep between batches to avoid hammering Azure SQL
    usleep(100000); // 100ms
}

// ──────────────────────────────────────────────────────────
// 8. Auto-add new partition if needed
// ──────────────────────────────────────────────────────────
// Auto-add partition for 2 months ahead (proper date arithmetic for year rollover)
$twoMonthsAhead = new DateTime('now', new DateTimeZone('UTC'));
$twoMonthsAhead->modify('+2 months');
$nextMonth = (int)$twoMonthsAhead->format('Ym');  // e.g., 202701 (not 202613)

$partCheck = $conn_pdo->query("
    SELECT PARTITION_NAME FROM information_schema.PARTITIONS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'route_history_facts'
      AND PARTITION_NAME != 'p_future'
    ORDER BY PARTITION_ORDINAL_POSITION DESC LIMIT 1
");
$lastPartition = $partCheck->fetchColumn();
if ($lastPartition) {
    // Extract YYYYMM from partition name (e.g., p202604 → 202604)
    $lastPartMonth = (int)str_replace('p', '', $lastPartition);

    // Calculate the LESS THAN value of the last real partition (next month after it)
    $lastDt = DateTime::createFromFormat('Ym', (string)$lastPartMonth);
    $lastDt->modify('+1 month');
    $lastPartBound = (int)$lastDt->format('Ym');

    // If we need a partition for a month beyond what exists, add it
    if ($nextMonth >= $lastPartBound) {
        $newPartName = 'p' . $nextMonth;
        // Calculate bound: the month AFTER $nextMonth
        $boundDt = DateTime::createFromFormat('Ym', (string)$nextMonth);
        $boundDt->modify('+1 month');
        $newBound = (int)$boundDt->format('Ym');
        $alterSql = "ALTER TABLE route_history_facts REORGANIZE PARTITION p_future INTO (
            PARTITION $newPartName VALUES LESS THAN ($newBound),
            PARTITION p_future VALUES LESS THAN MAXVALUE
        )";
        try {
            $conn_pdo->exec($alterSql);
            job_log("Added partition $newPartName");
        } catch (Exception $e) {
            job_log("WARNING: Failed to add partition: " . $e->getMessage());
        }
    }
}

// ──────────────────────────────────────────────────────────
// 9. Finalize
// ──────────────────────────────────────────────────────────
$conn_pdo->exec("UPDATE route_history_sync_state SET status = 'idle', rows_inserted = $totalInserted WHERE id = 1");

job_log("Done. Processed: $totalProcessed, Inserted: $totalInserted");
