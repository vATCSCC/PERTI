<?php
/**
 * VATSWIM API v1 - vNAS Restrictions & Auto ATC Rules Ingest Endpoint
 *
 * Receives a JSON payload containing all restrictions and Auto ATC rules
 * for a single ARTCC, and imports them into VATSIM_ADL database tables
 * using a DELETE+INSERT pattern per ARTCC.
 *
 * Also updates restriction/rule counts on vnas_sync_metadata.
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

// Validate expected arrays exist (may be empty)
$expected_keys = ['restrictions', 'auto_atc_rules'];
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
 * @param string   $table     Fully-qualified table name (e.g. dbo.vnas_restrictions)
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
    // 1. DELETE existing data for this ARTCC
    // ==================================================================
    $delete_tables = [
        'dbo.vnas_auto_atc_rules' => 'parent_artcc',
        'dbo.vnas_restrictions'   => 'parent_artcc',
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
    // 2. INSERT restrictions
    // ==================================================================
    $restriction_columns = [
        'restriction_id', 'parent_artcc', 'owning_facility_id', 'owning_sector_ids',
        'requesting_facility_id', 'requesting_sector_ids', 'route', 'applicable_airports',
        'applicable_aircraft_types', 'flight_type', 'flow', 'group_name',
        'altitude_type', 'altitude_values', 'speed_type', 'speed_values',
        'speed_units', 'heading_type', 'heading_values', 'location_type',
        'location_value', 'notes_json', 'display_order'
    ];
    $counts['restrictions'] = vnasBatchInsert(
        $conn_adl, 'dbo.vnas_restrictions', $restriction_columns, $body['restrictions'], 50
    );

    // ==================================================================
    // 3. INSERT auto ATC rules
    // ==================================================================
    $rule_columns = [
        'rule_id', 'parent_artcc', 'rule_name', 'status', 'position_ulid',
        'route_substrings', 'exclude_route_substrings', 'departure_airports',
        'destination_airports', 'min_altitude', 'max_altitude',
        'applicable_jets', 'applicable_turboprops', 'applicable_props',
        'descent_crossing_line_json', 'descent_altitude_value', 'descent_altitude_type',
        'descent_transition_level', 'descent_is_lufl', 'descent_lufl_station_id',
        'descent_altimeter_station', 'descent_altimeter_name',
        'descent_speed_value', 'descent_speed_is_mach', 'descent_speed_type',
        'crossing_fix', 'crossing_fix_name', 'crossing_altitude_value',
        'crossing_altitude_type', 'crossing_transition_level', 'crossing_is_lufl',
        'crossing_altimeter_station', 'crossing_altimeter_name',
        'descend_via_star_name', 'descend_via_crossing_line_json',
        'descend_via_altimeter_station', 'descend_via_altimeter_name',
        'precursor_rule_ids', 'exclusionary_rule_ids'
    ];
    $counts['auto_atc_rules'] = vnasBatchInsert(
        $conn_adl, 'dbo.vnas_auto_atc_rules', $rule_columns, $body['auto_atc_rules'], 40
    );

    // ==================================================================
    // 4. Update sync metadata counts
    // ==================================================================
    $meta_sql = "UPDATE dbo.vnas_sync_metadata
                 SET restrictions_count = ?,
                     auto_atc_rules_count = ?
                 WHERE artcc_code = ?";
    $stmt = sqlsrv_query($conn_adl, $meta_sql, [
        $counts['restrictions'],
        $counts['auto_atc_rules'],
        $artcc_code
    ]);
    if ($stmt === false) {
        $errors = sqlsrv_errors();
        throw new Exception("Sync metadata update failed: " . ($errors[0]['message'] ?? 'Unknown'));
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
    'artcc_code'     => $artcc_code,
    'restrictions'   => $counts['restrictions'],
    'auto_atc_rules' => $counts['auto_atc_rules'],
    'duration_ms'    => $duration_ms,
], [
    'source' => 'vnas_config'
]);
