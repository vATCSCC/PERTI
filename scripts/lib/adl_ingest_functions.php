<?php
/**
 * Shared ADL Ingest Functions
 *
 * Extracted from vatsim_adl_daemon.php so they can be reused by:
 * - vatsim_adl_daemon.php (main ingest loop)
 * - deep_hibernation_replay.php (post-processing replay)
 *
 * Contains: JSON parsing, staging table insertion, SP execution, and batch ID generation.
 */

declare(strict_types=1);

if (defined('ADL_INGEST_FUNCTIONS_LOADED')) {
    return;
}
define('ADL_INGEST_FUNCTIONS_LOADED', true);

/**
 * Parse pilots from VATSIM JSON into structured arrays.
 * @param array $vatsimData Decoded VATSIM JSON
 * @return array Array of pilot records ready for staging insert
 */
function parseVatsimPilots(array $vatsimData): array {
    $pilots = [];

    foreach ($vatsimData['pilots'] ?? [] as $p) {
        $fp = $p['flight_plan'] ?? [];

        // Build flight_key: cid|callsign|dept|dest|deptime
        $flightKey = ($p['cid'] ?? '') . '|' .
                     ($p['callsign'] ?? '') . '|' .
                     ($fp['departure'] ?? '') . '|' .
                     ($fp['arrival'] ?? '') . '|' .
                     ($fp['deptime'] ?? '');

        // Calculate route_hash (SHA2-256)
        $routeHashInput = ($fp['route'] ?? '') . '|' . ($fp['remarks'] ?? '');
        $routeHash = hash('sha256', $routeHashInput, true); // Binary

        // Extract airline ICAO from callsign
        $callsign = $p['callsign'] ?? '';
        $airlineIcao = null;
        if (strlen($callsign) >= 4 && preg_match('/^[A-Z]{3}[0-9]/', $callsign)) {
            $airlineIcao = substr($callsign, 0, 3);
        }

        $pilots[] = [
            'cid' => (int)($p['cid'] ?? 0),
            'callsign' => substr($callsign, 0, 16),
            'lat' => isset($p['latitude']) ? (float)$p['latitude'] : null,
            'lon' => isset($p['longitude']) ? (float)$p['longitude'] : null,
            'altitude_ft' => isset($p['altitude']) ? (int)$p['altitude'] : null,
            'groundspeed_kts' => isset($p['groundspeed']) ? (int)$p['groundspeed'] : null,
            'heading_deg' => isset($p['heading']) ? (int)$p['heading'] : null,
            'qnh_in_hg' => isset($p['qnh_i_hg']) ? (float)$p['qnh_i_hg'] : null,
            'qnh_mb' => isset($p['qnh_mb']) ? (int)$p['qnh_mb'] : null,
            'flight_server' => isset($p['server']) ? substr($p['server'], 0, 32) : null,
            'logon_time' => $p['logon_time'] ?? null,
            'fp_rule' => isset($fp['flight_rules']) ? substr($fp['flight_rules'], 0, 1) : null,
            'dept_icao' => isset($fp['departure']) ? substr($fp['departure'], 0, 4) : null,
            'dest_icao' => isset($fp['arrival']) ? substr($fp['arrival'], 0, 4) : null,
            'alt_icao' => isset($fp['alternate']) ? substr($fp['alternate'], 0, 4) : null,
            'route' => $fp['route'] ?? null,
            'remarks' => $fp['remarks'] ?? null,
            'altitude_filed_raw' => isset($fp['altitude']) ? substr($fp['altitude'], 0, 16) : null,
            'tas_filed_raw' => isset($fp['cruise_tas']) ? substr($fp['cruise_tas'], 0, 16) : null,
            'dep_time_z' => isset($fp['deptime']) ? substr($fp['deptime'], 0, 4) : null,
            'enroute_time_raw' => isset($fp['enroute_time']) ? substr($fp['enroute_time'], 0, 8) : null,
            'fuel_time_raw' => isset($fp['fuel_time']) ? substr($fp['fuel_time'], 0, 8) : null,
            'aircraft_faa_raw' => isset($fp['aircraft_faa']) ? substr($fp['aircraft_faa'], 0, 32) : null,
            'aircraft_short' => isset($fp['aircraft_short']) ? substr($fp['aircraft_short'], 0, 8) : null,
            'fp_dof_raw' => isset($fp['dof']) ? substr($fp['dof'], 0, 16) : null,
            'flight_key' => $flightKey,
            'route_hash' => $routeHash,
            'airline_icao' => $airlineIcao,
        ];
    }

    return $pilots;
}

/**
 * Parse prefiles from VATSIM JSON into structured arrays.
 * @param array $vatsimData Decoded VATSIM JSON
 * @return array Array of prefile records ready for staging insert
 */
function parseVatsimPrefiles(array $vatsimData): array {
    $prefiles = [];

    foreach ($vatsimData['prefiles'] ?? [] as $pf) {
        $fp = $pf['flight_plan'] ?? [];

        if (empty($pf['callsign'])) continue;

        // Build flight_key
        $flightKey = ($pf['cid'] ?? '') . '|' .
                     ($pf['callsign'] ?? '') . '|' .
                     ($fp['departure'] ?? '') . '|' .
                     ($fp['arrival'] ?? '') . '|' .
                     ($fp['deptime'] ?? '');

        // Calculate route_hash (MD5 for prefiles, matching SP)
        $routeHashInput = $fp['route'] ?? '';
        $routeHash = md5($routeHashInput, true); // Binary

        $prefiles[] = [
            'cid' => (int)($pf['cid'] ?? 0),
            'callsign' => substr($pf['callsign'] ?? '', 0, 16),
            'fp_rule' => isset($fp['flight_rules']) ? substr($fp['flight_rules'], 0, 1) : null,
            'dept_icao' => isset($fp['departure']) ? substr($fp['departure'], 0, 4) : null,
            'dest_icao' => isset($fp['arrival']) ? substr($fp['arrival'], 0, 4) : null,
            'alt_icao' => isset($fp['alternate']) ? substr($fp['alternate'], 0, 4) : null,
            'route' => $fp['route'] ?? null,
            'remarks' => $fp['remarks'] ?? null,
            'altitude_filed_raw' => isset($fp['altitude']) ? substr($fp['altitude'], 0, 16) : null,
            'tas_filed_raw' => isset($fp['cruise_tas']) ? substr($fp['cruise_tas'], 0, 16) : null,
            'dep_time_z' => isset($fp['deptime']) ? substr($fp['deptime'], 0, 4) : null,
            'enroute_time_raw' => isset($fp['enroute_time']) ? substr($fp['enroute_time'], 0, 8) : null,
            'aircraft_faa_raw' => isset($fp['aircraft_faa']) ? substr($fp['aircraft_faa'], 0, 32) : null,
            'aircraft_short' => isset($fp['aircraft_short']) ? substr($fp['aircraft_short'], 0, 8) : null,
            'flight_key' => $flightKey,
            'route_hash' => $routeHash,
        ];
    }

    return $prefiles;
}

/**
 * Clear staging tables before new batch.
 */
function clearStagingTables($conn): void {
    $stmt = sqlsrv_query($conn, "EXEC dbo.sp_ClearStagingTables");
    if ($stmt !== false) {
        sqlsrv_free_stmt($stmt);
    }
}

/**
 * Escape a string value for SQL Server literal insertion.
 * Returns N'escaped_value' or NULL.
 */
function sqlEscapeString(?string $value): string {
    if ($value === null) {
        return 'NULL';
    }
    // Escape single quotes by doubling them
    $escaped = str_replace("'", "''", $value);
    return "N'" . $escaped . "'";
}

/**
 * Format a numeric value for SQL literal insertion.
 */
function sqlEscapeNumber($value, bool $isInt = false): string {
    if ($value === null) {
        return 'NULL';
    }
    if ($isInt) {
        return (string)(int)$value;
    }
    return (string)(float)$value;
}

/**
 * Format a binary value (route_hash) for SQL insertion.
 * Uses CONVERT(VARBINARY(32), '...', 2) with hex string.
 */
function sqlEscapeBinary(?string $binaryData): string {
    if ($binaryData === null) {
        return 'NULL';
    }
    $hex = bin2hex($binaryData);
    return "CONVERT(VARBINARY(32), '{$hex}', 2)";
}

/**
 * Insert pilots to staging using bulk literal INSERT (O(1) per batch).
 * No parameters = no 2100 limit, much faster than parameterized batches.
 * @param resource $conn SQL Server connection
 * @param array $pilots Parsed pilot records
 * @param string $batchId UUID for this batch
 * @param int $batchSize Rows per INSERT statement (default 1000)
 * @return array ['inserted' => count, 'method' => 'bulk']
 */
function insertPilotsBulkLiteral($conn, array $pilots, string $batchId, int $batchSize = 1000): array {
    if (empty($pilots)) return ['inserted' => 0, 'method' => 'bulk'];

    clearStagingTables($conn);

    $inserted = 0;
    $batches = array_chunk($pilots, $batchSize);
    $escapedBatchId = sqlEscapeString($batchId);

    foreach ($batches as $batch) {
        $valuesClauses = [];

        foreach ($batch as $p) {
            $values = [
                sqlEscapeNumber($p['cid'], true),
                sqlEscapeString($p['callsign']),
                sqlEscapeNumber($p['lat'], false),
                sqlEscapeNumber($p['lon'], false),
                sqlEscapeNumber($p['altitude_ft'], true),
                sqlEscapeNumber($p['groundspeed_kts'], true),
                sqlEscapeNumber($p['heading_deg'], true),
                sqlEscapeNumber($p['qnh_in_hg'], false),
                sqlEscapeNumber($p['qnh_mb'], true),
                sqlEscapeString($p['flight_server']),
                sqlEscapeString($p['logon_time']),
                sqlEscapeString($p['fp_rule']),
                sqlEscapeString($p['dept_icao']),
                sqlEscapeString($p['dest_icao']),
                sqlEscapeString($p['alt_icao']),
                sqlEscapeString($p['route']),
                sqlEscapeString($p['remarks']),
                sqlEscapeString($p['altitude_filed_raw']),
                sqlEscapeString($p['tas_filed_raw']),
                sqlEscapeString($p['dep_time_z']),
                sqlEscapeString($p['enroute_time_raw']),
                sqlEscapeString($p['fuel_time_raw']),
                sqlEscapeString($p['aircraft_faa_raw']),
                sqlEscapeString($p['aircraft_short']),
                sqlEscapeString($p['fp_dof_raw']),
                sqlEscapeString($p['flight_key']),
                sqlEscapeBinary($p['route_hash']),
                sqlEscapeString($p['airline_icao']),
                sqlEscapeNumber($p['change_flags'] ?? 15, true),
                $escapedBatchId,
            ];

            $valuesClauses[] = '(' . implode(',', $values) . ')';
        }

        $sql = "INSERT INTO dbo.adl_staging_pilots (
            cid, callsign, lat, lon, altitude_ft, groundspeed_kts,
            heading_deg, qnh_in_hg, qnh_mb, flight_server, logon_time,
            fp_rule, dept_icao, dest_icao, alt_icao, route, remarks,
            altitude_filed_raw, tas_filed_raw, dep_time_z, enroute_time_raw,
            fuel_time_raw, aircraft_faa_raw, aircraft_short, fp_dof_raw,
            flight_key, route_hash, airline_icao, change_flags, batch_id
        ) VALUES " . implode(',', $valuesClauses);

        $stmt = sqlsrv_query($conn, $sql);

        if ($stmt === false) {
            $errors = sqlsrv_errors();
            throw new Exception("Bulk pilot insert failed: " . json_encode($errors));
        }

        $inserted += sqlsrv_rows_affected($stmt);
        sqlsrv_free_stmt($stmt);
    }

    return ['inserted' => $inserted, 'method' => 'bulk'];
}

/**
 * Insert prefiles to staging using bulk literal INSERT (O(1) per batch).
 */
function insertPrefilesBulkLiteral($conn, array $prefiles, string $batchId, int $batchSize = 1000): array {
    if (empty($prefiles)) return ['inserted' => 0, 'method' => 'bulk'];

    $inserted = 0;
    $batches = array_chunk($prefiles, $batchSize);
    $escapedBatchId = sqlEscapeString($batchId);

    foreach ($batches as $batch) {
        $valuesClauses = [];

        foreach ($batch as $pf) {
            $values = [
                sqlEscapeNumber($pf['cid'], true),
                sqlEscapeString($pf['callsign']),
                sqlEscapeString($pf['fp_rule']),
                sqlEscapeString($pf['dept_icao']),
                sqlEscapeString($pf['dest_icao']),
                sqlEscapeString($pf['alt_icao']),
                sqlEscapeString($pf['route']),
                sqlEscapeString($pf['remarks']),
                sqlEscapeString($pf['altitude_filed_raw']),
                sqlEscapeString($pf['tas_filed_raw']),
                sqlEscapeString($pf['dep_time_z']),
                sqlEscapeString($pf['enroute_time_raw']),
                sqlEscapeString($pf['aircraft_faa_raw']),
                sqlEscapeString($pf['aircraft_short']),
                sqlEscapeString($pf['flight_key']),
                sqlEscapeBinary($pf['route_hash']),
                $escapedBatchId,
            ];

            $valuesClauses[] = '(' . implode(',', $values) . ')';
        }

        $sql = "INSERT INTO dbo.adl_staging_prefiles (
            cid, callsign, fp_rule, dept_icao, dest_icao, alt_icao,
            route, remarks, altitude_filed_raw, tas_filed_raw,
            dep_time_z, enroute_time_raw, aircraft_faa_raw, aircraft_short,
            flight_key, route_hash, batch_id
        ) VALUES " . implode(',', $valuesClauses);

        $stmt = sqlsrv_query($conn, $sql);

        if ($stmt === false) {
            $errors = sqlsrv_errors();
            throw new Exception("Bulk prefile insert failed: " . json_encode($errors));
        }

        $inserted += sqlsrv_rows_affected($stmt);
        sqlsrv_free_stmt($stmt);
    }

    return ['inserted' => $inserted, 'method' => 'bulk'];
}

/**
 * Execute the staged refresh SP (reads from staging tables).
 * @param resource $conn SQL Server connection
 * @param string $batchId UUID for this batch
 * @param int $timeout Query timeout in seconds
 * @param bool $skipZoneDetection Set to true when zone_daemon.php is running
 * @param bool $deferExpensive Set to true to defer ETA/snapshot steps (trajectory always captured)
 * @return array Result with stats and timings
 */
function executeStagedRefreshSP($conn, string $batchId, int $timeout, bool $skipZoneDetection = false, bool $deferExpensive = false): array {
    $startTime = microtime(true);

    $skipZone = $skipZoneDetection ? 1 : 0;
    $defer = $deferExpensive ? 1 : 0;
    $sql = "EXEC [dbo].[sp_Adl_RefreshFromVatsim_Staged] @batch_id = ?, @skip_zone_detection = ?, @defer_expensive = ?";
    $options = ['QueryTimeout' => $timeout];

    $stmt = sqlsrv_query($conn, $sql, [$batchId, $skipZone, $defer], $options);

    if ($stmt === false) {
        $errors = sqlsrv_errors();
        throw new Exception("Staged SP execution failed: " . json_encode($errors));
    }

    $result = [
        'success'    => true,
        'elapsed_ms' => 0,
        'stats'      => null,
        'steps'      => null,
    ];

    // Read result set (same structure as original SP)
    do {
        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        if ($row && isset($row['pilots_received']) && isset($row['step1_json_ms'])) {
            $result['stats'] = [
                'pilots'      => $row['pilots_received'] ?? 0,
                'heartbeat'   => $row['heartbeat_flights'] ?? 0,
                'new'         => $row['new_flights'] ?? 0,
                'updated'     => $row['updated_flights'] ?? 0,
                'pos_ins'     => $row['positions_inserted'] ?? 0,
                'pos_upd'     => $row['positions_updated'] ?? 0,
                'routes'      => $row['routes_queued'] ?? 0,
                'etds'        => $row['etds_calculated'] ?? 0,
                'etas'        => $row['etas_calculated'] ?? 0,
                'traj'        => $row['trajectories_logged'] ?? 0,
                'zones'       => $row['zone_transitions'] ?? 0,
                'boundaries'  => $row['boundary_transitions'] ?? 0,
                'crossings'   => $row['crossings_calculated'] ?? 0,
            ];

            $result['steps'] = [
                '1_staging'   => $row['step1_json_ms'] ?? 0,  // Now staging read
                '1b_enrich'   => $row['step1b_enrich_ms'] ?? 0,
                '2_core'      => $row['step2_core_ms'] ?? 0,
                '2a_prefile'  => $row['step2a_prefile_ms'] ?? 0,
                '2b_times'    => $row['step2b_times_ms'] ?? 0,
                '3_position'  => $row['step3_position_ms'] ?? 0,
                '4_flightplan'=> $row['step4_flightplan_ms'] ?? 0,
                '4b_etd'      => $row['step4b_etd_ms'] ?? 0,
                '4c_simbrief' => $row['step4c_simbrief_ms'] ?? 0,
                '5_queue'     => $row['step5_queue_ms'] ?? 0,
                '5b_routedist'=> $row['step5b_routedist_ms'] ?? 0,
                '6_aircraft'  => $row['step6_aircraft_ms'] ?? 0,
                '7_inactive'  => $row['step7_inactive_ms'] ?? 0,
                '8_trajectory'=> $row['step8_trajectory_ms'] ?? 0,
                '8b_bucket'   => $row['step8b_bucket_ms'] ?? 0,
                '8c_waypoint' => $row['step8c_waypoint_ms'] ?? 0,
                '8d_eta'      => $row['step8d_batch_eta_ms'] ?? 0,
                '9_zone'      => $row['step9_zone_ms'] ?? 0,
                '10_boundary' => $row['step10_boundary_ms'] ?? 0,
                '11_crossings'=> $row['step11_crossings_ms'] ?? 0,
                '12_log'      => $row['step12_log_ms'] ?? 0,
                '13_snapshot' => $row['step13_snapshot_ms'] ?? 0,
            ];

            $result['elapsed_ms'] = $row['elapsed_ms'] ?? 0;
            break;
        }
    } while (sqlsrv_next_result($stmt));

    while (sqlsrv_next_result($stmt)) {
        // Drain remaining results
    }

    sqlsrv_free_stmt($stmt);

    if ($result['elapsed_ms'] == 0) {
        $result['elapsed_ms'] = round((microtime(true) - $startTime) * 1000);
    }

    return $result;
}

/**
 * Generate a UUID v4 for batch tracking.
 */
function generateBatchId(): string {
    $data = random_bytes(16);
    $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
    $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}
