<?php
/**
 * VATSWIM API v1 - vNAS Facility Configuration Ingest Endpoint
 *
 * Receives a JSON payload containing all facility/position/TCP/area/beacon/
 * transceiver/video map/airport group/URL data for a single ARTCC, and imports
 * it into VATSIM_ADL database tables using a DELETE+INSERT pattern per ARTCC.
 *
 * Also rebuilds position-sector and TCP-sector mapping tables from the
 * imported data and updates sync metadata.
 *
 * @version 1.0.0
 * @since 2026-04-07
 */

require_once __DIR__ . '/../../auth.php';

// Get ADL connection (lazy-loaded, not eagerly connected in SWIM_ONLY mode)
$conn_adl = get_conn_adl();
if (!$conn_adl) {
    SwimResponse::error('ADL database connection not available', 503, 'SERVICE_UNAVAILABLE');
}

// Require authentication with write access
$auth = swim_init_auth(true, true);

// Validate source can write vnas_config data
if (!$auth->canWriteField('vnas_config')) {
    SwimResponse::error(
        'Source "' . $auth->getSourceId() . '" is not authorized to write vNAS config data.',
        403,
        'NOT_AUTHORITATIVE'
    );
}

// Only accept POST
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    SwimResponse::error('Method not allowed. Use POST.', 405, 'METHOD_NOT_ALLOWED');
}

// Get and validate request body
$body = swim_get_json_body();
if (!$body) {
    SwimResponse::error('Request body is required', 400, 'MISSING_BODY');
}

$artcc_code = strtoupper(trim($body['artcc_code'] ?? ''));
if (empty($artcc_code) || strlen($artcc_code) > 4) {
    SwimResponse::error('artcc_code is required and must be 1-4 characters', 400, 'INVALID_ARTCC');
}

$source_updated_at = $body['source_updated_at'] ?? null;

// Validate all expected arrays exist (may be empty)
$expected_keys = [
    'facilities', 'positions', 'stars_tcps', 'stars_areas',
    'beacon_banks', 'transceivers', 'video_maps', 'airport_groups', 'common_urls'
];
foreach ($expected_keys as $key) {
    if (!isset($body[$key]) || !is_array($body[$key])) {
        SwimResponse::error("Missing or invalid \"{$key}\" array in payload", 400, 'MISSING_ARRAY');
    }
}

$start_time = microtime(true);

// ------------------------------------------------------------------
// Batch INSERT helper
// ------------------------------------------------------------------
/**
 * Insert rows into a table in batches, staying under sqlsrv's 2100 param limit.
 *
 * @param resource $conn      sqlsrv connection
 * @param string   $table     Fully-qualified table name (e.g. dbo.vnas_facilities)
 * @param string[] $columns   Ordered column names
 * @param array[]  $rows      Rows as associative arrays keyed by column name
 * @param int      $batch_size Max rows per INSERT statement
 * @return int Number of rows inserted
 * @throws Exception on sqlsrv failure
 */
function vnasBatchInsert($conn, $table, $columns, $rows, $batch_size = 50) {
    if (empty($rows)) return 0;

    $col_count = count($columns);
    $col_list = implode(', ', $columns);
    $placeholder = '(' . implode(', ', array_fill(0, $col_count, '?')) . ')';
    $total = 0;

    foreach (array_chunk($rows, $batch_size) as $chunk) {
        $placeholders = implode(', ', array_fill(0, count($chunk), $placeholder));
        $params = [];
        foreach ($chunk as $row) {
            foreach ($columns as $col) {
                $val = $row[$col] ?? null;
                // JSON-encode arrays/objects for NVARCHAR(MAX) JSON columns
                if (is_array($val) || is_object($val)) {
                    $val = json_encode($val, JSON_UNESCAPED_UNICODE);
                }
                // Convert booleans to 0/1 for BIT columns
                if (is_bool($val)) {
                    $val = $val ? 1 : 0;
                }
                $params[] = $val;
            }
        }

        $sql = "INSERT INTO {$table} ({$col_list}) VALUES {$placeholders}";
        $stmt = sqlsrv_query($conn, $sql, $params);
        if ($stmt === false) {
            $errors = sqlsrv_errors();
            throw new Exception("Batch insert to {$table} failed: " . ($errors[0]['message'] ?? 'Unknown'));
        }
        sqlsrv_free_stmt($stmt);
        $total += count($chunk);
    }

    return $total;
}

// ------------------------------------------------------------------
// Batch MERGE helper (for tables with cross-ARTCC shared PKs)
// ------------------------------------------------------------------
/**
 * Insert rows that don't already exist (skip duplicates on PK).
 *
 * @param resource $conn      sqlsrv connection
 * @param string   $table     Fully-qualified table name
 * @param string   $pk_col    Primary key column name
 * @param string[] $columns   Ordered column names
 * @param array[]  $rows      Rows as associative arrays
 * @return int Number of rows inserted (excludes skipped duplicates)
 * @throws Exception on sqlsrv failure
 */
function vnasBatchMerge($conn, $table, $pk_col, $columns, $rows) {
    if (empty($rows)) return 0;

    $total = 0;
    $col_list = implode(', ', $columns);

    // Process one row at a time with INSERT...WHERE NOT EXISTS
    foreach ($rows as $row) {
        $placeholders = implode(', ', array_fill(0, count($columns), '?'));
        $sql = "INSERT INTO {$table} ({$col_list})
                SELECT {$placeholders}
                WHERE NOT EXISTS (SELECT 1 FROM {$table} WHERE {$pk_col} = ?)";
        $params = [];
        foreach ($columns as $col) {
            $val = $row[$col] ?? null;
            if (is_array($val) || is_object($val)) {
                $val = json_encode($val, JSON_UNESCAPED_UNICODE);
            }
            if (is_bool($val)) {
                $val = $val ? 1 : 0;
            }
            $params[] = $val;
        }
        // Add PK value for the WHERE NOT EXISTS check
        $params[] = $row[$pk_col] ?? null;

        $stmt = sqlsrv_query($conn, $sql, $params);
        if ($stmt === false) {
            $errors = sqlsrv_errors();
            throw new Exception("Merge insert to {$table} failed: " . ($errors[0]['message'] ?? 'Unknown'));
        }
        $total += sqlsrv_rows_affected($stmt);
        sqlsrv_free_stmt($stmt);
    }

    return $total;
}

// ------------------------------------------------------------------
// Begin transaction
// ------------------------------------------------------------------
if (sqlsrv_begin_transaction($conn_adl) === false) {
    $errors = sqlsrv_errors();
    SwimResponse::error(
        'Failed to begin transaction: ' . ($errors[0]['message'] ?? 'Unknown'),
        500,
        'TRANSACTION_ERROR'
    );
}

try {
    $counts = [];

    // ==================================================================
    // 1. DELETE existing data for this ARTCC (order: mappings first, then data)
    // ==================================================================
    $delete_tables = [
        'dbo.vnas_tcp_sector_map'   => 'parent_artcc',
        'dbo.vnas_position_sector_map' => 'parent_artcc',
        'dbo.vnas_common_urls'      => 'parent_artcc',
        'dbo.vnas_airport_groups'   => 'parent_artcc',
        'dbo.vnas_video_map_index'  => 'parent_artcc',
        'dbo.vnas_transceivers'     => 'parent_artcc',
        'dbo.vnas_beacon_banks'     => 'parent_artcc',
        'dbo.vnas_stars_areas'      => 'parent_artcc',
        'dbo.vnas_stars_tcps'       => 'parent_artcc',
        'dbo.vnas_positions'        => 'parent_artcc',
        'dbo.vnas_facilities'       => 'source_artcc',
    ];

    foreach ($delete_tables as $table => $col) {
        $stmt = sqlsrv_query($conn_adl, "DELETE FROM {$table} WHERE {$col} = ?", [$artcc_code]);
        if ($stmt === false) {
            $errors = sqlsrv_errors();
            throw new Exception("DELETE from {$table} failed: " . ($errors[0]['message'] ?? 'Unknown'));
        }
        sqlsrv_free_stmt($stmt);
    }

    // ==================================================================
    // 2. INSERT facilities
    // ==================================================================
    $fac_columns = [
        'facility_id', 'facility_name', 'facility_type', 'parent_artcc',
        'parent_facility_id', 'hierarchy_depth', 'neighboring_facility_ids',
        'non_nas_facility_ids', 'has_eram', 'has_stars', 'has_flight_strips',
        'has_tower_cab', 'has_asdex', 'has_tdls', 'eram_config_json',
        'stars_config_json', 'flight_strips_json', 'tower_cab_json',
        'asdex_config_json', 'tdls_config_json', 'visibility_centers_json',
        'aliases_updated_at', 'source_artcc', 'source_updated_at'
    ];
    $counts['facilities'] = vnasBatchInsert(
        $conn_adl, 'dbo.vnas_facilities', $fac_columns, $body['facilities'], 50
    );

    // ==================================================================
    // 3. INSERT positions
    // ==================================================================
    $pos_columns = [
        'position_ulid', 'facility_id', 'parent_artcc', 'position_name',
        'callsign', 'radio_name', 'frequency_hz', 'starred',
        'eram_sector_id', 'stars_area_id', 'stars_tcp_id', 'stars_color_set',
        'transceiver_ids_json'
    ];
    $counts['positions'] = vnasBatchInsert(
        $conn_adl, 'dbo.vnas_positions', $pos_columns, $body['positions'], 100
    );

    // ==================================================================
    // 4. INSERT STARS TCPs
    // ==================================================================
    $tcp_columns = [
        'tcp_id', 'facility_id', 'parent_artcc', 'subset',
        'sector_id', 'parent_tcp_id', 'terminal_sector'
    ];
    $counts['stars_tcps'] = vnasBatchInsert(
        $conn_adl, 'dbo.vnas_stars_tcps', $tcp_columns, $body['stars_tcps'], 200
    );

    // ==================================================================
    // 5. INSERT STARS areas
    // ==================================================================
    $area_columns = [
        'area_id', 'facility_id', 'parent_artcc', 'area_name',
        'visibility_lat', 'visibility_lon', 'surveillance_range',
        'ldb_beacon_codes_inhibited', 'pdb_ground_speed_inhibited',
        'display_requested_alt_in_fdb', 'use_vfr_position_symbol',
        'show_dest_departures', 'show_dest_satellite_arrivals',
        'show_dest_primary_arrivals', 'underlying_airports_json',
        'ssa_airports_json', 'tower_list_configs_json'
    ];
    $counts['stars_areas'] = vnasBatchInsert(
        $conn_adl, 'dbo.vnas_stars_areas', $area_columns, $body['stars_areas'], 100
    );

    // ==================================================================
    // 6. INSERT beacon banks
    // ==================================================================
    $beacon_columns = [
        'bank_id', 'facility_id', 'parent_artcc', 'source_system',
        'category', 'priority', 'subset', 'start_code', 'end_code'
    ];
    $counts['beacon_banks'] = vnasBatchInsert(
        $conn_adl, 'dbo.vnas_beacon_banks', $beacon_columns, $body['beacon_banks'], 200
    );

    // ==================================================================
    // 7. UPSERT transceivers (shared across ARTCCs — same UUID may exist)
    // ==================================================================
    $xcvr_columns = [
        'transceiver_id', 'parent_artcc', 'transceiver_name',
        'lat', 'lon', 'height_msl_meters', 'height_agl_meters'
    ];
    $counts['transceivers'] = vnasBatchMerge(
        $conn_adl, 'dbo.vnas_transceivers', 'transceiver_id', $xcvr_columns, $body['transceivers']
    );

    // ==================================================================
    // 8. INSERT video map index
    // ==================================================================
    $vmap_columns = [
        'map_id', 'parent_artcc', 'map_name', 'short_name', 'stars_id',
        'tags_json', 'source_file_name', 'stars_brightness_category',
        'stars_always_visible', 'tdm_only', 'last_updated_at'
    ];
    $counts['video_maps'] = vnasBatchInsert(
        $conn_adl, 'dbo.vnas_video_map_index', $vmap_columns, $body['video_maps'], 150
    );

    // ==================================================================
    // 9. INSERT airport groups
    // ==================================================================
    $agrp_columns = [
        'group_id', 'parent_artcc', 'group_name', 'airport_ids_json'
    ];
    $counts['airport_groups'] = vnasBatchInsert(
        $conn_adl, 'dbo.vnas_airport_groups', $agrp_columns, $body['airport_groups'], 200
    );

    // ==================================================================
    // 10. INSERT common URLs
    // ==================================================================
    $url_columns = [
        'url_id', 'parent_artcc', 'url_name', 'url'
    ];
    $counts['common_urls'] = vnasBatchInsert(
        $conn_adl, 'dbo.vnas_common_urls', $url_columns, $body['common_urls'], 200
    );

    // ==================================================================
    // 11. Rebuild position-sector mapping
    // ==================================================================
    $psm_sql = "INSERT INTO dbo.vnas_position_sector_map
                    (position_ulid, boundary_id, boundary_code, parent_artcc, sector_type)
                SELECT
                    p.position_ulid,
                    b.boundary_id,
                    b.boundary_code,
                    p.parent_artcc,
                    b.boundary_type
                FROM dbo.vnas_positions p
                JOIN dbo.adl_boundary b
                    ON b.parent_artcc = p.parent_artcc
                    AND b.boundary_code = p.eram_sector_id
                WHERE p.parent_artcc = ?
                    AND p.eram_sector_id IS NOT NULL";
    $stmt = sqlsrv_query($conn_adl, $psm_sql, [$artcc_code]);
    if ($stmt === false) {
        $errors = sqlsrv_errors();
        throw new Exception("Position-sector mapping rebuild failed: " . ($errors[0]['message'] ?? 'Unknown'));
    }
    $counts['position_sector_mappings'] = sqlsrv_rows_affected($stmt);
    sqlsrv_free_stmt($stmt);

    // ==================================================================
    // 12. Rebuild TCP-sector mapping
    // ==================================================================
    $tsm_sql = "INSERT INTO dbo.vnas_tcp_sector_map
                    (tcp_id, facility_id, sector_id, boundary_id, boundary_code, parent_artcc)
                SELECT
                    t.tcp_id,
                    t.facility_id,
                    t.sector_id,
                    b.boundary_id,
                    b.boundary_code,
                    t.parent_artcc
                FROM dbo.vnas_stars_tcps t
                LEFT JOIN dbo.adl_boundary b
                    ON b.parent_artcc = t.parent_artcc
                    AND b.boundary_code = t.sector_id
                WHERE t.parent_artcc = ?";
    $stmt = sqlsrv_query($conn_adl, $tsm_sql, [$artcc_code]);
    if ($stmt === false) {
        $errors = sqlsrv_errors();
        throw new Exception("TCP-sector mapping rebuild failed: " . ($errors[0]['message'] ?? 'Unknown'));
    }
    $counts['tcp_sector_mappings'] = sqlsrv_rows_affected($stmt);
    sqlsrv_free_stmt($stmt);

    // ==================================================================
    // 13. Upsert sync metadata
    // ==================================================================
    $duration_ms = (int) round((microtime(true) - $start_time) * 1000);

    $merge_sql = "MERGE dbo.vnas_sync_metadata AS target
                  USING (SELECT ? AS artcc_code) AS source
                  ON target.artcc_code = source.artcc_code
                  WHEN MATCHED THEN
                      UPDATE SET
                          source_updated_at = ?,
                          last_import_utc = SYSUTCDATETIME(),
                          facilities_count = ?,
                          positions_count = ?,
                          import_duration_ms = ?,
                          import_status = 'success'
                  WHEN NOT MATCHED THEN
                      INSERT (artcc_code, source_updated_at, last_import_utc,
                              facilities_count, positions_count, import_duration_ms, import_status)
                      VALUES (?, ?, SYSUTCDATETIME(), ?, ?, ?, 'success');";
    $stmt = sqlsrv_query($conn_adl, $merge_sql, [
        $artcc_code,
        $source_updated_at,
        $counts['facilities'],
        $counts['positions'],
        $duration_ms,
        // WHEN NOT MATCHED params
        $artcc_code,
        $source_updated_at,
        $counts['facilities'],
        $counts['positions'],
        $duration_ms
    ]);
    if ($stmt === false) {
        $errors = sqlsrv_errors();
        throw new Exception("Sync metadata upsert failed: " . ($errors[0]['message'] ?? 'Unknown'));
    }
    sqlsrv_free_stmt($stmt);

    // ==================================================================
    // Commit
    // ==================================================================
    sqlsrv_commit($conn_adl);

} catch (Exception $e) {
    sqlsrv_rollback($conn_adl);
    SwimResponse::error('Import failed: ' . $e->getMessage(), 500, 'IMPORT_ERROR');
}

// ------------------------------------------------------------------
// Success response
// ------------------------------------------------------------------
$duration_ms = (int) round((microtime(true) - $start_time) * 1000);

SwimResponse::success([
    'artcc_code'              => $artcc_code,
    'facilities'              => $counts['facilities'],
    'positions'               => $counts['positions'],
    'stars_tcps'              => $counts['stars_tcps'],
    'stars_areas'             => $counts['stars_areas'],
    'beacon_banks'            => $counts['beacon_banks'],
    'transceivers'            => $counts['transceivers'],
    'video_maps'              => $counts['video_maps'],
    'airport_groups'          => $counts['airport_groups'],
    'common_urls'             => $counts['common_urls'],
    'position_sector_mappings' => $counts['position_sector_mappings'],
    'tcp_sector_mappings'     => $counts['tcp_sector_mappings'],
    'duration_ms'             => $duration_ms,
], [
    'source' => 'vnas_config'
]);
