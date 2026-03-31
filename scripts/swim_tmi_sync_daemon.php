<?php
/**
 * SWIM TMI Sync Daemon
 *
 * Syncs TMI, CDM, and reference data from internal databases into SWIM_API mirror
 * tables for external API consumption. This achieves data isolation: SWIM endpoints
 * query only SWIM_API, never touching operational databases directly.
 *
 * Tier 1 (Operational, every 5 minutes):
 *   - NTML, TMI programs, entries, advisories, reroutes, flow events, CDM tables
 *   - TMI analytics: log (core/scope/parameters/impact/references), delay attribution, facility stats
 *   - Delta sync via updated_at/created_at watermark
 *   - Offset by 1 minute from flight sync to avoid DTU contention
 *
 * Tier 2 (Reference, daily 0601-0801Z):
 *   - Airports, taxi references, playbook throughput
 *   - Full replace for small tables, incremental upsert for large
 *
 * Usage:
 *   php swim_tmi_sync_daemon.php [--loop] [--interval=300] [--debug]
 *
 * @package PERTI
 * @subpackage SWIM
 * @version 1.0.0
 * @since 2026-03-14
 */

set_time_limit(0);

// Parse command line arguments
$options = getopt('', ['loop', 'interval:', 'debug']);
$runLoop = isset($options['loop']);
$syncInterval = isset($options['interval']) ? (int)$options['interval'] : 300;
$debug = isset($options['debug']);

// Ensure minimum interval
$syncInterval = max(60, $syncInterval);

// Load dependencies
define('PERTI_LOADED', true);
require_once __DIR__ . '/../load/config.php';
require_once __DIR__ . '/../load/connect.php';

// ============================================================================
// Constants
// ============================================================================

define('TMI_SYNC_PID_FILE', sys_get_temp_dir() . '/swim_tmi_sync_daemon.pid');
define('TMI_SYNC_HEARTBEAT_FILE', sys_get_temp_dir() . '/swim_tmi_sync_daemon.heartbeat');
define('TMI_SYNC_BATCH_SIZE', 500);

// Reference sync window: 0601-0801 UTC
define('REFDATA_WINDOW_START_HOUR', 6);
define('REFDATA_WINDOW_START_MIN', 1);
define('REFDATA_WINDOW_END_HOUR', 8);
define('REFDATA_WINDOW_END_MIN', 1);

// ============================================================================
// Logging & Process Management (same pattern as swim_sync_daemon.php)
// ============================================================================

function tmi_log(string $message, string $level = 'INFO'): void {
    $timestamp = gmdate('Y-m-d H:i:s');
    echo "[$timestamp UTC] [$level] $message\n";
}

function tmi_write_heartbeat(string $status, array $extra = []): void {
    $payload = array_merge([
        'pid' => getmypid(),
        'status' => $status,
        'updated_utc' => gmdate('Y-m-d H:i:s'),
        'unix_ts' => time(),
    ], $extra);
    @file_put_contents(TMI_SYNC_HEARTBEAT_FILE, json_encode($payload), LOCK_EX);
}

function tmi_write_pid(): void {
    file_put_contents(TMI_SYNC_PID_FILE, getmypid());
    register_shutdown_function(function () {
        @unlink(TMI_SYNC_PID_FILE);
    });
}

function tmi_check_existing_instance(): bool {
    if (!file_exists(TMI_SYNC_PID_FILE)) return false;
    $pid = (int)file_get_contents(TMI_SYNC_PID_FILE);
    if ($pid <= 0) return false;
    if (PHP_OS_FAMILY === 'Windows') {
        exec("tasklist /FI \"PID eq $pid\" 2>NUL", $output);
        return count($output) > 1;
    }
    return posix_kill($pid, 0);
}

// ============================================================================
// Generic Table Sync Engine
// ============================================================================

/**
 * Sync a single table from source to SWIM mirror using JSON + OPENJSON MERGE.
 *
 * @param resource $conn_source Source sqlsrv connection (VATSIM_TMI or VATSIM_ADL)
 * @param resource $conn_swim   SWIM_API sqlsrv connection
 * @param array    $config      Table sync configuration
 * @param string|null $lastSync Last sync timestamp (ISO format) or null for full sync
 * @return array Stats: [rows_read, inserted, updated, deleted, duration_ms, error]
 */
function syncTableToSwim($conn_source, $conn_swim, array $config, ?string $lastSync): array {
    $start = microtime(true);
    $stats = ['rows_read' => 0, 'inserted' => 0, 'updated' => 0, 'deleted' => 0, 'duration_ms' => 0, 'error' => null];

    $swimTable = $config['swim_table'];
    $pk = $config['pk']; // string or array for composite
    $columns = $config['columns']; // ['col_name' => 'SQL_TYPE', ...]
    $watermarkCol = $config['watermark'] ?? 'updated_at';
    $sourceQuery = $config['source_query']; // full SELECT (may include WHERE)

    // Build source query with optional watermark filter
    if ($lastSync !== null && !empty($watermarkCol)) {
        // Append watermark condition
        $whereClause = " WHERE $watermarkCol > ?";
        // Check if query already has WHERE
        if (stripos($sourceQuery, 'WHERE') !== false) {
            $whereClause = " AND $watermarkCol > ?";
        }
        $sql = $sourceQuery . $whereClause;
        $params = [$lastSync];
    } else {
        $sql = $sourceQuery;
        $params = [];
    }

    // Step 1: Read from source
    $stmt = @sqlsrv_query($conn_source, $sql, $params);
    if ($stmt === false) {
        $stats['error'] = 'Source query failed: ' . json_encode(sqlsrv_errors());
        $stats['duration_ms'] = (int)round((microtime(true) - $start) * 1000);
        return $stats;
    }

    $rows = [];
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        // Convert DateTime objects to strings for JSON
        foreach ($row as $k => $v) {
            if ($v instanceof DateTime) {
                $row[$k] = $v->format('Y-m-d H:i:s');
            }
        }
        $rows[] = $row;
    }
    sqlsrv_free_stmt($stmt);
    $stats['rows_read'] = count($rows);

    if (empty($rows)) {
        $stats['duration_ms'] = (int)round((microtime(true) - $start) * 1000);
        return $stats;
    }

    // Step 2: Process in batches
    foreach (array_chunk($rows, TMI_SYNC_BATCH_SIZE) as $batch) {
        $json = json_encode($batch, JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            $stats['error'] = 'JSON encode failed: ' . json_last_error_msg();
            break;
        }

        // Build OPENJSON WITH clause
        $withCols = [];
        foreach ($columns as $colName => $sqlType) {
            $withCols[] = "[$colName] $sqlType '\$.$colName'";
        }
        $withClause = implode(",\n                ", $withCols);

        // Build PK join condition
        $pkCols = is_array($pk) ? $pk : [$pk];
        $onConditions = [];
        foreach ($pkCols as $pkCol) {
            $onConditions[] = "t.[$pkCol] = s.[$pkCol]";
        }
        $onClause = implode(' AND ', $onConditions);

        // Build UPDATE SET (all non-PK columns)
        $updateSets = [];
        foreach ($columns as $colName => $sqlType) {
            if (!in_array($colName, $pkCols)) {
                $updateSets[] = "t.[$colName] = s.[$colName]";
            }
        }
        // Always update synced_utc
        $updateSets[] = "t.[synced_utc] = SYSUTCDATETIME()";
        $updateSetClause = implode(', ', $updateSets);

        // Build INSERT columns and values
        $allCols = array_keys($columns);
        $insertCols = implode(', ', array_map(fn($c) => "[$c]", $allCols));
        $insertVals = implode(', ', array_map(fn($c) => "s.[$c]", $allCols));

        $mergeSql = "
            MERGE dbo.$swimTable AS t
            USING (
                SELECT * FROM OPENJSON(?) WITH (
                    $withClause
                )
            ) AS s ON $onClause
            WHEN MATCHED THEN UPDATE SET $updateSetClause
            WHEN NOT MATCHED THEN INSERT ($insertCols, synced_utc) VALUES ($insertVals, SYSUTCDATETIME());

            SELECT
                @@ROWCOUNT AS affected,
                (SELECT COUNT(*) FROM dbo.$swimTable) AS total_rows;
        ";

        $result = @sqlsrv_query($conn_swim, $mergeSql, [&$json], ['QueryTimeout' => 60]);
        if ($result === false) {
            $stats['error'] = "MERGE $swimTable failed: " . json_encode(sqlsrv_errors());
            break;
        }

        // Try to get row counts
        $row = sqlsrv_fetch_array($result, SQLSRV_FETCH_ASSOC);
        if ($row) {
            $stats['updated'] += ($row['affected'] ?? 0);
        }
        sqlsrv_free_stmt($result);
    }

    $stats['duration_ms'] = (int)round((microtime(true) - $start) * 1000);
    return $stats;
}

/**
 * Full table replace: TRUNCATE + batch INSERT for small reference tables.
 */
function syncTableFullReplace($conn_source, $conn_swim, array $config): array {
    $start = microtime(true);
    $stats = ['rows_read' => 0, 'inserted' => 0, 'duration_ms' => 0, 'error' => null];

    $swimTable = $config['swim_table'];
    $columns = $config['columns'];
    $sourceQuery = $config['source_query'];

    // Step 1: Read all from source
    $stmt = @sqlsrv_query($conn_source, $sourceQuery);
    if ($stmt === false) {
        $stats['error'] = 'Source query failed: ' . json_encode(sqlsrv_errors());
        $stats['duration_ms'] = (int)round((microtime(true) - $start) * 1000);
        return $stats;
    }

    $rows = [];
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        foreach ($row as $k => $v) {
            if ($v instanceof DateTime) {
                $row[$k] = $v->format('Y-m-d H:i:s');
            }
        }
        $rows[] = $row;
    }
    sqlsrv_free_stmt($stmt);
    $stats['rows_read'] = count($rows);

    if (empty($rows)) {
        $stats['duration_ms'] = (int)round((microtime(true) - $start) * 1000);
        return $stats;
    }

    // Step 2: Truncate target
    $truncResult = @sqlsrv_query($conn_swim, "TRUNCATE TABLE dbo.$swimTable");
    if ($truncResult === false) {
        // TRUNCATE may fail if there are FK refs; try DELETE
        $delResult = @sqlsrv_query($conn_swim, "DELETE FROM dbo.$swimTable");
        if ($delResult === false) {
            $stats['error'] = "Cannot clear $swimTable: " . json_encode(sqlsrv_errors());
            $stats['duration_ms'] = (int)round((microtime(true) - $start) * 1000);
            return $stats;
        }
        sqlsrv_free_stmt($delResult);
    } else {
        sqlsrv_free_stmt($truncResult);
    }

    // Step 3: Batch insert via OPENJSON
    foreach (array_chunk($rows, TMI_SYNC_BATCH_SIZE) as $batch) {
        $json = json_encode($batch, JSON_UNESCAPED_UNICODE);
        if ($json === false) continue;

        $withCols = [];
        foreach ($columns as $colName => $sqlType) {
            $withCols[] = "[$colName] $sqlType '\$.$colName'";
        }
        $withClause = implode(",\n                ", $withCols);

        $allCols = array_keys($columns);
        $insertCols = implode(', ', array_map(fn($c) => "[$c]", $allCols));

        $insertSql = "
            INSERT INTO dbo.$swimTable ($insertCols, synced_utc)
            SELECT $insertCols, SYSUTCDATETIME()
            FROM OPENJSON(?) WITH (
                $withClause
            )
        ";

        $result = @sqlsrv_query($conn_swim, $insertSql, [&$json], ['QueryTimeout' => 60]);
        if ($result === false) {
            $stats['error'] = "INSERT $swimTable failed: " . json_encode(sqlsrv_errors());
            break;
        }
        $stats['inserted'] += sqlsrv_rows_affected($result);
        sqlsrv_free_stmt($result);

        // Brief pause between batches to reduce DTU spike
        if (count($rows) > TMI_SYNC_BATCH_SIZE) {
            usleep(500000); // 500ms
        }
    }

    $stats['duration_ms'] = (int)round((microtime(true) - $start) * 1000);
    return $stats;
}

/**
 * Sync from MySQL source to SWIM_API (for playbook throughput).
 */
function syncMysqlTableToSwim($conn_pdo, $conn_swim, array $config): array {
    $start = microtime(true);
    $stats = ['rows_read' => 0, 'inserted' => 0, 'duration_ms' => 0, 'error' => null];

    $swimTable = $config['swim_table'];
    $columns = $config['columns'];

    try {
        $stmt = $conn_pdo->query($config['source_query']);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {
        $stats['error'] = 'MySQL query failed: ' . $e->getMessage();
        $stats['duration_ms'] = (int)round((microtime(true) - $start) * 1000);
        return $stats;
    }

    $stats['rows_read'] = count($rows);

    if (empty($rows)) {
        $stats['duration_ms'] = (int)round((microtime(true) - $start) * 1000);
        return $stats;
    }

    // Truncate + batch insert
    @sqlsrv_query($conn_swim, "TRUNCATE TABLE dbo.$swimTable");

    foreach (array_chunk($rows, TMI_SYNC_BATCH_SIZE) as $batch) {
        $json = json_encode($batch, JSON_UNESCAPED_UNICODE);
        if ($json === false) continue;

        $withCols = [];
        foreach ($columns as $colName => $sqlType) {
            $withCols[] = "[$colName] $sqlType '\$.$colName'";
        }
        $withClause = implode(",\n                ", $withCols);
        $allCols = array_keys($columns);
        $insertCols = implode(', ', array_map(fn($c) => "[$c]", $allCols));

        $insertSql = "
            INSERT INTO dbo.$swimTable ($insertCols, synced_utc)
            SELECT $insertCols, SYSUTCDATETIME()
            FROM OPENJSON(?) WITH ($withClause)
        ";

        $result = @sqlsrv_query($conn_swim, $insertSql, [&$json], ['QueryTimeout' => 60]);
        if ($result === false) {
            $stats['error'] = "INSERT $swimTable failed: " . json_encode(sqlsrv_errors());
            break;
        }
        $stats['inserted'] += sqlsrv_rows_affected($result);
        sqlsrv_free_stmt($result);
    }

    $stats['duration_ms'] = (int)round((microtime(true) - $start) * 1000);
    return $stats;
}

/**
 * Get last sync time for a table from swim_sync_state.
 */
function getLastSyncTime($conn_swim, string $tableName): ?string {
    $sql = "SELECT last_sync_utc FROM dbo.swim_sync_state WHERE table_name = ?";
    $stmt = @sqlsrv_query($conn_swim, $sql, [$tableName]);
    if ($stmt === false) return null;

    $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    sqlsrv_free_stmt($stmt);

    if (!$row || !$row['last_sync_utc']) return null;

    $val = $row['last_sync_utc'];
    if ($val instanceof DateTime) {
        return $val->format('Y-m-d H:i:s');
    }
    return $val;
}

/**
 * Update sync state after successful sync.
 */
function updateSyncState($conn_swim, string $tableName, int $rowCount, int $durationMs, string $mode, ?string $error = null): void {
    $sql = "
        MERGE dbo.swim_sync_state AS t
        USING (SELECT ? AS table_name) AS s ON t.table_name = s.table_name
        WHEN MATCHED THEN UPDATE SET
            last_sync_utc = SYSUTCDATETIME(),
            last_row_count = ?,
            last_duration_ms = ?,
            sync_mode = ?,
            error_count = CASE WHEN ? IS NOT NULL THEN t.error_count + 1 ELSE 0 END,
            last_error = ?,
            updated_at = SYSUTCDATETIME()
        WHEN NOT MATCHED THEN INSERT (table_name, last_sync_utc, last_row_count, last_duration_ms, sync_mode, error_count, last_error)
            VALUES (?, SYSUTCDATETIME(), ?, ?, ?, CASE WHEN ? IS NOT NULL THEN 1 ELSE 0 END, ?);
    ";
    @sqlsrv_query($conn_swim, $sql, [
        $tableName, $rowCount, $durationMs, $mode, $error, $error,
        $tableName, $rowCount, $durationMs, $mode, $error, $error,
    ]);
}

// ============================================================================
// Table Configurations — Tier 1 (Operational, from VATSIM_TMI)
// ============================================================================

function getTier1TmiConfigs(): array {
    return [
        // Source: VATSIM_TMI.dbo.tmi_programs (migration 001 + 003 + 035-041)
        // Mirror and source column names match exactly
        'swim_tmi_programs' => [
            'swim_table' => 'swim_tmi_programs',
            'pk' => 'program_id',
            'watermark' => 'updated_at',
            'source_query' => 'SELECT program_id, program_guid, ctl_element, element_type, program_type, program_name, adv_number, start_utc, end_utc, cumulative_start, cumulative_end, status, is_proposed, is_active, program_rate, reserve_rate, delay_limit_min, target_delay_mult, rates_hourly_json, reserve_hourly_json, scope_json, exemptions_json, arrival_fix_filter, aircraft_type_filter, carrier_filter, impacting_condition, cause_text, comments, revision_number, parent_program_id, total_flights, controlled_flights, exempt_flights, avg_delay_min, max_delay_min, total_delay_min, subs_enabled, adaptive_compression, source_type, source_id, discord_message_id, discord_channel_id, created_by, created_at, updated_at, activated_by, activated_at, purged_by, purged_at FROM dbo.tmi_programs',
            'columns' => [
                'program_id' => 'INT', 'program_guid' => 'UNIQUEIDENTIFIER',
                'ctl_element' => 'NVARCHAR(8)', 'element_type' => 'NVARCHAR(16)',
                'program_type' => 'NVARCHAR(8)', 'program_name' => 'NVARCHAR(64)',
                'adv_number' => 'INT', 'start_utc' => 'DATETIME2', 'end_utc' => 'DATETIME2',
                'cumulative_start' => 'DATETIME2', 'cumulative_end' => 'DATETIME2',
                'status' => 'NVARCHAR(16)', 'is_proposed' => 'BIT', 'is_active' => 'BIT',
                'program_rate' => 'INT', 'reserve_rate' => 'INT',
                'delay_limit_min' => 'INT', 'target_delay_mult' => 'DECIMAL(5,2)',
                'rates_hourly_json' => 'NVARCHAR(MAX)', 'reserve_hourly_json' => 'NVARCHAR(MAX)',
                'scope_json' => 'NVARCHAR(MAX)', 'exemptions_json' => 'NVARCHAR(MAX)',
                'arrival_fix_filter' => 'NVARCHAR(256)', 'aircraft_type_filter' => 'NVARCHAR(256)',
                'carrier_filter' => 'NVARCHAR(256)',
                'impacting_condition' => 'NVARCHAR(128)', 'cause_text' => 'NVARCHAR(256)',
                'comments' => 'NVARCHAR(MAX)', 'revision_number' => 'INT', 'parent_program_id' => 'INT',
                'total_flights' => 'INT', 'controlled_flights' => 'INT', 'exempt_flights' => 'INT',
                'avg_delay_min' => 'DECIMAL(8,1)', 'max_delay_min' => 'INT', 'total_delay_min' => 'INT',
                'subs_enabled' => 'BIT', 'adaptive_compression' => 'BIT',
                'source_type' => 'NVARCHAR(16)', 'source_id' => 'NVARCHAR(64)',
                'discord_message_id' => 'NVARCHAR(32)', 'discord_channel_id' => 'NVARCHAR(32)',
                'created_by' => 'NVARCHAR(32)', 'created_at' => 'DATETIME2',
                'updated_at' => 'DATETIME2', 'activated_by' => 'NVARCHAR(32)',
                'activated_at' => 'DATETIME2', 'purged_by' => 'NVARCHAR(32)', 'purged_at' => 'DATETIME2',
            ],
        ],

        // Source: VATSIM_TMI.dbo.tmi_entries (migration 001)
        // Column names match exactly between source and mirror
        'swim_tmi_entries' => [
            'swim_table' => 'swim_tmi_entries',
            'pk' => 'entry_id',
            'watermark' => 'updated_at',
            'source_query' => 'SELECT entry_id, entry_guid, determinant_code, protocol_type, entry_type, ctl_element, element_type, requesting_facility, providing_facility, restriction_value, restriction_unit, condition_text, qualifiers, exclusions, reason_code, reason_detail, aircraft_type, altitude, alt_type, speed, speed_operator, flow_type, valid_from, valid_until, status, source_type, source_id, source_channel, discord_message_id, discord_posted_at, discord_channel_id, raw_input, parsed_data, created_by, created_by_name, created_at, updated_at, cancelled_by, cancelled_at, cancel_reason, content_hash, supersedes_entry_id FROM dbo.tmi_entries',
            'columns' => [
                'entry_id' => 'INT', 'entry_guid' => 'UNIQUEIDENTIFIER',
                'determinant_code' => 'NVARCHAR(16)', 'protocol_type' => 'NVARCHAR(16)',
                'entry_type' => 'NVARCHAR(16)', 'ctl_element' => 'NVARCHAR(8)',
                'element_type' => 'NVARCHAR(16)',
                'requesting_facility' => 'NVARCHAR(8)', 'providing_facility' => 'NVARCHAR(8)',
                'restriction_value' => 'NVARCHAR(32)', 'restriction_unit' => 'NVARCHAR(16)',
                'condition_text' => 'NVARCHAR(MAX)', 'qualifiers' => 'NVARCHAR(MAX)',
                'exclusions' => 'NVARCHAR(MAX)',
                'reason_code' => 'NVARCHAR(16)', 'reason_detail' => 'NVARCHAR(256)',
                'aircraft_type' => 'NVARCHAR(16)', 'altitude' => 'NVARCHAR(16)',
                'alt_type' => 'NVARCHAR(8)', 'speed' => 'NVARCHAR(16)',
                'speed_operator' => 'NVARCHAR(8)', 'flow_type' => 'NVARCHAR(16)',
                'valid_from' => 'DATETIME2', 'valid_until' => 'DATETIME2',
                'status' => 'NVARCHAR(16)', 'source_type' => 'NVARCHAR(16)',
                'source_id' => 'NVARCHAR(64)', 'source_channel' => 'NVARCHAR(32)',
                'discord_message_id' => 'NVARCHAR(32)', 'discord_posted_at' => 'DATETIME2',
                'discord_channel_id' => 'NVARCHAR(32)',
                'raw_input' => 'NVARCHAR(MAX)', 'parsed_data' => 'NVARCHAR(MAX)',
                'created_by' => 'NVARCHAR(32)', 'created_by_name' => 'NVARCHAR(64)',
                'created_at' => 'DATETIME2', 'updated_at' => 'DATETIME2',
                'cancelled_by' => 'NVARCHAR(32)', 'cancelled_at' => 'DATETIME2',
                'cancel_reason' => 'NVARCHAR(256)', 'content_hash' => 'NVARCHAR(64)',
                'supersedes_entry_id' => 'INT',
            ],
        ],

        // Source: VATSIM_TMI.dbo.tmi_advisories (migration 001)
        // Column names match exactly between source and mirror
        'swim_tmi_advisories' => [
            'swim_table' => 'swim_tmi_advisories',
            'pk' => 'advisory_id',
            'watermark' => 'updated_at',
            'source_query' => 'SELECT advisory_id, advisory_guid, advisory_number, advisory_type, ctl_element, element_type, scope_facilities, program_id, program_rate, delay_cap, effective_from, effective_until, subject, body_text, reason_code, reason_detail, reroute_id, reroute_name, reroute_area, reroute_string, reroute_from, reroute_to, mit_miles, mit_type, mit_fix, status, is_proposed, source_type, source_id, discord_message_id, discord_posted_at, discord_channel_id, created_by, created_by_name, created_at, updated_at, approved_by, approved_at, cancelled_by, cancelled_at, cancel_reason, revision_number, supersedes_advisory_id FROM dbo.tmi_advisories',
            'columns' => [
                'advisory_id' => 'INT', 'advisory_guid' => 'UNIQUEIDENTIFIER',
                'advisory_number' => 'NVARCHAR(16)', 'advisory_type' => 'NVARCHAR(16)',
                'ctl_element' => 'NVARCHAR(8)', 'element_type' => 'NVARCHAR(16)',
                'scope_facilities' => 'NVARCHAR(256)', 'program_id' => 'INT',
                'program_rate' => 'INT', 'delay_cap' => 'INT',
                'effective_from' => 'DATETIME2', 'effective_until' => 'DATETIME2',
                'subject' => 'NVARCHAR(256)', 'body_text' => 'NVARCHAR(MAX)',
                'reason_code' => 'NVARCHAR(16)', 'reason_detail' => 'NVARCHAR(256)',
                'reroute_id' => 'INT', 'reroute_name' => 'NVARCHAR(64)',
                'reroute_area' => 'NVARCHAR(128)', 'reroute_string' => 'NVARCHAR(MAX)',
                'reroute_from' => 'NVARCHAR(64)', 'reroute_to' => 'NVARCHAR(64)',
                'mit_miles' => 'INT', 'mit_type' => 'NVARCHAR(16)', 'mit_fix' => 'NVARCHAR(8)',
                'status' => 'NVARCHAR(16)', 'is_proposed' => 'BIT',
                'source_type' => 'NVARCHAR(16)', 'source_id' => 'NVARCHAR(64)',
                'discord_message_id' => 'NVARCHAR(32)', 'discord_posted_at' => 'DATETIME2',
                'discord_channel_id' => 'NVARCHAR(32)',
                'created_by' => 'NVARCHAR(32)', 'created_by_name' => 'NVARCHAR(64)',
                'created_at' => 'DATETIME2', 'updated_at' => 'DATETIME2',
                'approved_by' => 'NVARCHAR(32)', 'approved_at' => 'DATETIME2',
                'cancelled_by' => 'NVARCHAR(32)', 'cancelled_at' => 'DATETIME2',
                'cancel_reason' => 'NVARCHAR(256)', 'revision_number' => 'INT',
                'supersedes_advisory_id' => 'INT',
            ],
        ],

        // Source: VATSIM_TMI.dbo.tmi_reroutes (migration 001)
        // Column names match exactly between source and mirror
        'swim_tmi_reroutes' => [
            'swim_table' => 'swim_tmi_reroutes',
            'pk' => 'reroute_id',
            'watermark' => 'updated_at',
            'source_query' => 'SELECT reroute_id, reroute_guid, status, name, adv_number, start_utc, end_utc, time_basis, protected_segment, protected_fixes, avoid_fixes, route_type, origin_airports, origin_tracons, origin_centers, dest_airports, dest_tracons, dest_centers, departure_fix, arrival_fix, thru_centers, thru_fixes, use_airway, include_ac_cat, include_ac_types, include_carriers, weight_class, altitude_min, altitude_max, rvsm_filter, exempt_airports, exempt_carriers, exempt_flights, exempt_active_only, airborne_filter, comments, impacting_condition, advisory_text, color, line_weight, line_style, route_geojson, total_assigned, compliant_count, non_compliant_count, compliance_rate, source_type, source_id, discord_message_id, discord_channel_id, created_by, created_at, updated_at, activated_at FROM dbo.tmi_reroutes',
            'columns' => [
                'reroute_id' => 'INT', 'reroute_guid' => 'UNIQUEIDENTIFIER',
                'status' => 'INT', 'name' => 'NVARCHAR(64)', 'adv_number' => 'NVARCHAR(16)',
                'start_utc' => 'DATETIME2', 'end_utc' => 'DATETIME2',
                'time_basis' => 'NVARCHAR(16)', 'protected_segment' => 'NVARCHAR(256)',
                'protected_fixes' => 'NVARCHAR(MAX)', 'avoid_fixes' => 'NVARCHAR(MAX)',
                'route_type' => 'NVARCHAR(16)',
                'origin_airports' => 'NVARCHAR(MAX)', 'origin_tracons' => 'NVARCHAR(MAX)',
                'origin_centers' => 'NVARCHAR(MAX)', 'dest_airports' => 'NVARCHAR(MAX)',
                'dest_tracons' => 'NVARCHAR(MAX)', 'dest_centers' => 'NVARCHAR(MAX)',
                'departure_fix' => 'NVARCHAR(16)', 'arrival_fix' => 'NVARCHAR(16)',
                'thru_centers' => 'NVARCHAR(MAX)', 'thru_fixes' => 'NVARCHAR(MAX)',
                'use_airway' => 'NVARCHAR(32)',
                'include_ac_cat' => 'NVARCHAR(32)', 'include_ac_types' => 'NVARCHAR(MAX)',
                'include_carriers' => 'NVARCHAR(MAX)', 'weight_class' => 'NVARCHAR(16)',
                'altitude_min' => 'INT', 'altitude_max' => 'INT', 'rvsm_filter' => 'NVARCHAR(16)',
                'exempt_airports' => 'NVARCHAR(MAX)', 'exempt_carriers' => 'NVARCHAR(MAX)',
                'exempt_flights' => 'NVARCHAR(MAX)', 'exempt_active_only' => 'BIT',
                'airborne_filter' => 'NVARCHAR(16)',
                'comments' => 'NVARCHAR(MAX)', 'impacting_condition' => 'NVARCHAR(128)',
                'advisory_text' => 'NVARCHAR(MAX)',
                'color' => 'NVARCHAR(16)', 'line_weight' => 'INT', 'line_style' => 'NVARCHAR(16)',
                'route_geojson' => 'NVARCHAR(MAX)',
                'total_assigned' => 'INT', 'compliant_count' => 'INT',
                'non_compliant_count' => 'INT', 'compliance_rate' => 'DECIMAL(5,2)',
                'source_type' => 'NVARCHAR(16)', 'source_id' => 'NVARCHAR(64)',
                'discord_message_id' => 'NVARCHAR(32)', 'discord_channel_id' => 'NVARCHAR(32)',
                'created_by' => 'NVARCHAR(32)', 'created_at' => 'DATETIME2',
                'updated_at' => 'DATETIME2', 'activated_at' => 'DATETIME2',
            ],
        ],

        // Source: VATSIM_TMI.dbo.tmi_reroute_routes (migration 001)
        // Column names match exactly
        'swim_tmi_reroute_routes' => [
            'swim_table' => 'swim_tmi_reroute_routes',
            'pk' => 'route_id',
            'watermark' => 'updated_at',
            'source_query' => 'SELECT route_id, reroute_id, origin, destination, route_string, sort_order, origin_filter, dest_filter, created_at, updated_at FROM dbo.tmi_reroute_routes',
            'columns' => [
                'route_id' => 'INT', 'reroute_id' => 'INT',
                'origin' => 'NVARCHAR(64)', 'destination' => 'NVARCHAR(64)',
                'route_string' => 'NVARCHAR(MAX)', 'sort_order' => 'INT',
                'origin_filter' => 'NVARCHAR(128)', 'dest_filter' => 'NVARCHAR(128)',
                'created_at' => 'DATETIME2', 'updated_at' => 'DATETIME2',
            ],
        ],

        // Source: VATSIM_TMI.dbo.tmi_reroute_flights (migration 001)
        // No updated_at on source — full sync each cycle (~500 active rows)
        'swim_tmi_reroute_flights' => [
            'swim_table' => 'swim_tmi_reroute_flights',
            'pk' => 'id',
            'watermark' => '',
            'source_query' => 'SELECT id, reroute_id, flight_key, callsign, flight_uid, dep_icao, dest_icao, ac_type, filed_altitude, route_at_assign, assigned_route, current_route, current_route_utc, final_route, last_lat, last_lon, last_altitude, last_position_utc, compliance_status, protected_fixes_crossed, avoid_fixes_crossed, compliance_pct, compliance_notes, assigned_at, departed_utc, arrived_utc, route_distance_orig_nm, route_distance_new_nm, route_delta_nm, ete_original_min, ete_assigned_min, ete_delta_min, manual_status, override_by, override_utc, override_reason FROM dbo.tmi_reroute_flights',
            'columns' => [
                'id' => 'INT', 'reroute_id' => 'INT', 'flight_key' => 'NVARCHAR(64)',
                'callsign' => 'NVARCHAR(16)', 'flight_uid' => 'BIGINT',
                'dep_icao' => 'NVARCHAR(8)', 'dest_icao' => 'NVARCHAR(8)',
                'ac_type' => 'NVARCHAR(8)', 'filed_altitude' => 'INT',
                'route_at_assign' => 'NVARCHAR(MAX)', 'assigned_route' => 'NVARCHAR(MAX)',
                'current_route' => 'NVARCHAR(MAX)', 'current_route_utc' => 'DATETIME2',
                'final_route' => 'NVARCHAR(MAX)',
                'last_lat' => 'DECIMAL(10,7)', 'last_lon' => 'DECIMAL(11,7)',
                'last_altitude' => 'INT', 'last_position_utc' => 'DATETIME2',
                'compliance_status' => 'NVARCHAR(16)',
                'protected_fixes_crossed' => 'NVARCHAR(MAX)', 'avoid_fixes_crossed' => 'NVARCHAR(MAX)',
                'compliance_pct' => 'DECIMAL(5,2)', 'compliance_notes' => 'NVARCHAR(MAX)',
                'assigned_at' => 'DATETIME2', 'departed_utc' => 'DATETIME2', 'arrived_utc' => 'DATETIME2',
                'route_distance_orig_nm' => 'DECIMAL(8,1)', 'route_distance_new_nm' => 'DECIMAL(8,1)',
                'route_delta_nm' => 'DECIMAL(8,1)',
                'ete_original_min' => 'DECIMAL(8,1)', 'ete_assigned_min' => 'DECIMAL(8,1)',
                'ete_delta_min' => 'DECIMAL(8,1)',
                'manual_status' => 'NVARCHAR(16)', 'override_by' => 'NVARCHAR(32)',
                'override_utc' => 'DATETIME2', 'override_reason' => 'NVARCHAR(256)',
            ],
        ],

        // Source: VATSIM_TMI.dbo.tmi_reroute_compliance_log (migration 001)
        // Column names match exactly
        'swim_tmi_reroute_compliance_log' => [
            'swim_table' => 'swim_tmi_reroute_compliance_log',
            'pk' => 'log_id',
            'watermark' => 'snapshot_utc',
            'source_query' => 'SELECT log_id, reroute_flight_id, snapshot_utc, compliance_status, compliance_pct, lat, lon, altitude, route_string, fixes_crossed FROM dbo.tmi_reroute_compliance_log',
            'columns' => [
                'log_id' => 'BIGINT', 'reroute_flight_id' => 'INT',
                'snapshot_utc' => 'DATETIME2', 'compliance_status' => 'NVARCHAR(16)',
                'compliance_pct' => 'DECIMAL(5,2)',
                'lat' => 'DECIMAL(10,7)', 'lon' => 'DECIMAL(11,7)', 'altitude' => 'INT',
                'route_string' => 'NVARCHAR(MAX)', 'fixes_crossed' => 'NVARCHAR(MAX)',
            ],
        ],

        // Source: VATSIM_TMI.dbo.tmi_public_routes (migration 001)
        // Column names match exactly
        'swim_tmi_public_routes' => [
            'swim_table' => 'swim_tmi_public_routes',
            'pk' => 'route_id',
            'watermark' => 'updated_at',
            'source_query' => 'SELECT route_id, route_guid, status, name, adv_number, advisory_id, reroute_id, route_string, advisory_text, color, line_weight, line_style, valid_start_utc, valid_end_utc, constrained_area, reason, origin_filter, dest_filter, facilities, route_geojson, created_by, created_at, updated_at, coordination_status, coordination_proposal_id, discord_message_id, discord_channel_id, discord_posted_at FROM dbo.tmi_public_routes',
            'columns' => [
                'route_id' => 'INT', 'route_guid' => 'UNIQUEIDENTIFIER',
                'status' => 'INT', 'name' => 'NVARCHAR(64)', 'adv_number' => 'NVARCHAR(16)',
                'advisory_id' => 'INT', 'reroute_id' => 'INT',
                'route_string' => 'NVARCHAR(MAX)', 'advisory_text' => 'NVARCHAR(MAX)',
                'color' => 'NVARCHAR(16)', 'line_weight' => 'INT', 'line_style' => 'NVARCHAR(16)',
                'valid_start_utc' => 'DATETIME2', 'valid_end_utc' => 'DATETIME2',
                'constrained_area' => 'NVARCHAR(128)', 'reason' => 'NVARCHAR(256)',
                'origin_filter' => 'NVARCHAR(256)', 'dest_filter' => 'NVARCHAR(256)',
                'facilities' => 'NVARCHAR(256)', 'route_geojson' => 'NVARCHAR(MAX)',
                'created_by' => 'NVARCHAR(32)', 'created_at' => 'DATETIME2', 'updated_at' => 'DATETIME2',
                'coordination_status' => 'NVARCHAR(16)', 'coordination_proposal_id' => 'INT',
                'discord_message_id' => 'NVARCHAR(32)', 'discord_channel_id' => 'NVARCHAR(32)',
                'discord_posted_at' => 'DATETIME2',
            ],
        ],

        // Source: VATSIM_TMI.dbo.tmi_flight_control (migration 003)
        // Source has 56+ cols; SELECT only the 33 that exist on the mirror
        'swim_tmi_flight_control' => [
            'swim_table' => 'swim_tmi_flight_control',
            'pk' => 'control_id',
            'watermark' => 'updated_at',
            'source_query' => 'SELECT control_id, flight_uid, callsign, program_id, slot_id, ctd_utc, cta_utc, octd_utc, octa_utc, aslot, ctl_elem, ctl_prgm, ctl_type, ctl_exempt, ctl_exempt_reason, program_delay_min, delay_capped, gs_held, gs_release_utc, is_popup, popup_detected_utc, popup_lead_time_min, sl_hold, subbable, compliance_status, actual_dep_utc, actual_arr_utc, compliance_delta_min, dep_airport, arr_airport, is_archived, created_at AS created_utc, updated_at AS modified_utc FROM dbo.tmi_flight_control',
            'columns' => [
                'control_id' => 'BIGINT', 'flight_uid' => 'BIGINT', 'callsign' => 'NVARCHAR(16)',
                'program_id' => 'INT', 'slot_id' => 'BIGINT',
                'ctd_utc' => 'DATETIME2', 'cta_utc' => 'DATETIME2',
                'octd_utc' => 'DATETIME2', 'octa_utc' => 'DATETIME2',
                'aslot' => 'NVARCHAR(16)', 'ctl_elem' => 'NVARCHAR(8)', 'ctl_prgm' => 'NVARCHAR(16)',
                'ctl_type' => 'NVARCHAR(8)', 'ctl_exempt' => 'BIT', 'ctl_exempt_reason' => 'NVARCHAR(64)',
                'program_delay_min' => 'INT', 'delay_capped' => 'BIT',
                'gs_held' => 'BIT', 'gs_release_utc' => 'DATETIME2',
                'is_popup' => 'BIT', 'popup_detected_utc' => 'DATETIME2', 'popup_lead_time_min' => 'INT',
                'sl_hold' => 'BIT', 'subbable' => 'BIT',
                'compliance_status' => 'NVARCHAR(16)',
                'actual_dep_utc' => 'DATETIME2', 'actual_arr_utc' => 'DATETIME2',
                'compliance_delta_min' => 'INT',
                'dep_airport' => 'NVARCHAR(8)', 'arr_airport' => 'NVARCHAR(8)',
                'is_archived' => 'BIT', 'created_utc' => 'DATETIME2', 'modified_utc' => 'DATETIME2',
            ],
        ],

        // Source: VATSIM_TMI.dbo.rad_amendments (migration 057)
        // Route Amendment Dialogue mirror
        'swim_rad_amendments' => [
            'swim_table' => 'swim_rad_amendments',
            'pk' => 'id',
            'watermark' => 'created_utc',
            'source_query' => 'SELECT id, gufi, callsign, origin, destination, original_route, assigned_route, assigned_route_geojson, status, rrstat, tmi_reroute_id, tmi_id_label, delivery_channels, route_color, created_by, created_utc, sent_utc, delivered_utc, resolved_utc, expires_utc, notes FROM dbo.rad_amendments',
            'columns' => [
                'id' => 'INT', 'gufi' => 'UNIQUEIDENTIFIER',
                'callsign' => 'VARCHAR(10)', 'origin' => 'CHAR(4)', 'destination' => 'CHAR(4)',
                'original_route' => 'VARCHAR(MAX)', 'assigned_route' => 'VARCHAR(MAX)',
                'assigned_route_geojson' => 'VARCHAR(MAX)',
                'status' => 'VARCHAR(10)', 'rrstat' => 'VARCHAR(10)',
                'tmi_reroute_id' => 'INT', 'tmi_id_label' => 'VARCHAR(20)',
                'delivery_channels' => 'VARCHAR(50)', 'route_color' => 'VARCHAR(10)',
                'created_by' => 'INT',
                'created_utc' => 'DATETIME2', 'sent_utc' => 'DATETIME2',
                'delivered_utc' => 'DATETIME2', 'resolved_utc' => 'DATETIME2',
                'expires_utc' => 'DATETIME2', 'notes' => 'VARCHAR(500)',
            ],
        ],
    ];
}

// ============================================================================
// Table Configurations — Tier 1 (NTML from VATSIM_ADL)
// ============================================================================

function getTier1NtmlConfigs(): array {
    return [
        // Source: VATSIM_ADL.dbo.ntml (migration adl/tmi/001_ntml_schema.sql)
        // program_name is a COMPUTED column on source — can still be SELECTed
        // is_archived exists on mirror but NOT on source — omitted (stays NULL)
        // prob_extension is NVARCHAR(8) on source, INT on mirror — lax OPENJSON returns NULL for non-numeric
        'swim_ntml' => [
            'swim_table' => 'swim_ntml',
            'pk' => 'program_id',
            'watermark' => 'modified_utc',
            'source_query' => 'SELECT program_id, program_guid, ctl_element, element_type, program_type, program_name, adv_number, start_utc, end_utc, cumulative_start, cumulative_end, model_time_utc, status, is_proposed, is_active, program_rate, reserve_rate, delay_limit_min, target_delay_mult, rates_hourly_json, reserve_hourly_json, scope_type, scope_tier, scope_distance_nm, scope_json, exemptions_json, exempt_airborne, exempt_within_min, flt_incl_carrier, flt_incl_type, flt_incl_fix, impacting_condition, cause_text, comments, prob_extension, revision_number, parent_program_id, successor_program_id, total_flights, controlled_flights, exempt_flights, airborne_flights, avg_delay_min, max_delay_min, total_delay_min, created_by, created_utc, modified_by, modified_utc, activated_utc, activated_by, purged_utc, purged_by FROM dbo.ntml',
            'columns' => [
                'program_id' => 'INT', 'program_guid' => 'UNIQUEIDENTIFIER',
                'ctl_element' => 'NVARCHAR(8)', 'element_type' => 'NVARCHAR(16)',
                'program_type' => 'NVARCHAR(8)', 'program_name' => 'NVARCHAR(32)',
                'adv_number' => 'INT',
                'start_utc' => 'DATETIME2', 'end_utc' => 'DATETIME2',
                'cumulative_start' => 'DATETIME2', 'cumulative_end' => 'DATETIME2',
                'model_time_utc' => 'DATETIME2',
                'status' => 'NVARCHAR(16)', 'is_proposed' => 'BIT', 'is_active' => 'BIT',
                'program_rate' => 'INT', 'reserve_rate' => 'INT',
                'delay_limit_min' => 'INT', 'target_delay_mult' => 'DECIMAL(5,2)',
                'rates_hourly_json' => 'NVARCHAR(MAX)', 'reserve_hourly_json' => 'NVARCHAR(MAX)',
                'scope_type' => 'NVARCHAR(16)', 'scope_tier' => 'INT', 'scope_distance_nm' => 'INT',
                'scope_json' => 'NVARCHAR(MAX)', 'exemptions_json' => 'NVARCHAR(MAX)',
                'exempt_airborne' => 'BIT', 'exempt_within_min' => 'INT',
                'flt_incl_carrier' => 'NVARCHAR(MAX)', 'flt_incl_type' => 'NVARCHAR(MAX)',
                'flt_incl_fix' => 'NVARCHAR(MAX)',
                'impacting_condition' => 'NVARCHAR(128)', 'cause_text' => 'NVARCHAR(256)',
                'comments' => 'NVARCHAR(MAX)', 'prob_extension' => 'INT',
                'revision_number' => 'INT', 'parent_program_id' => 'INT', 'successor_program_id' => 'INT',
                'total_flights' => 'INT', 'controlled_flights' => 'INT',
                'exempt_flights' => 'INT', 'airborne_flights' => 'INT',
                'avg_delay_min' => 'DECIMAL(8,1)', 'max_delay_min' => 'INT', 'total_delay_min' => 'INT',
                'created_by' => 'NVARCHAR(32)', 'created_utc' => 'DATETIME2',
                'modified_by' => 'NVARCHAR(32)', 'modified_utc' => 'DATETIME2',
                'activated_utc' => 'DATETIME2', 'activated_by' => 'NVARCHAR(32)',
                'purged_utc' => 'DATETIME2', 'purged_by' => 'NVARCHAR(32)',
            ],
        ],
    ];
}

// ============================================================================
// Table Configurations — Tier 1 (Flow tables from VATSIM_TMI)
// ============================================================================

function getTier1FlowConfigs(): array {
    return [
        // Source: VATSIM_TMI.dbo.tmi_flow_providers (sql/migrations/20260117_add_flow_tables.sql)
        // Column names match exactly between source and mirror
        'swim_tmi_flow_providers' => [
            'swim_table' => 'swim_tmi_flow_providers',
            'pk' => 'provider_id',
            'watermark' => 'updated_at',
            'source_query' => 'SELECT provider_id, provider_guid, provider_code, provider_name, api_base_url, api_version, auth_type, auth_config_json, region_codes_json, fir_codes_json, sync_interval_sec, sync_enabled, last_sync_utc, last_sync_status, last_sync_message, is_active, priority, created_at, updated_at FROM dbo.tmi_flow_providers',
            'columns' => [
                'provider_id' => 'INT', 'provider_guid' => 'UNIQUEIDENTIFIER',
                'provider_code' => 'NVARCHAR(16)', 'provider_name' => 'NVARCHAR(64)',
                'api_base_url' => 'NVARCHAR(256)', 'api_version' => 'NVARCHAR(16)',
                'auth_type' => 'NVARCHAR(16)', 'auth_config_json' => 'NVARCHAR(MAX)',
                'region_codes_json' => 'NVARCHAR(MAX)', 'fir_codes_json' => 'NVARCHAR(MAX)',
                'sync_interval_sec' => 'INT', 'sync_enabled' => 'BIT',
                'last_sync_utc' => 'DATETIME2', 'last_sync_status' => 'NVARCHAR(16)',
                'last_sync_message' => 'NVARCHAR(256)', 'is_active' => 'BIT', 'priority' => 'INT',
                'created_at' => 'DATETIME2', 'updated_at' => 'DATETIME2',
            ],
        ],

        // Source: VATSIM_TMI.dbo.tmi_flow_events
        // Column names match exactly
        'swim_tmi_flow_events' => [
            'swim_table' => 'swim_tmi_flow_events',
            'pk' => 'event_id',
            'watermark' => 'updated_at',
            'source_query' => 'SELECT event_id, event_guid, provider_id, external_id, event_code, event_name, event_type, fir_ids_json, start_utc, end_utc, gs_exempt, gdp_priority, status, participant_count, synced_at, raw_data_json, created_at, updated_at FROM dbo.tmi_flow_events',
            'columns' => [
                'event_id' => 'INT', 'event_guid' => 'UNIQUEIDENTIFIER',
                'provider_id' => 'INT', 'external_id' => 'NVARCHAR(64)',
                'event_code' => 'NVARCHAR(32)', 'event_name' => 'NVARCHAR(128)',
                'event_type' => 'NVARCHAR(16)', 'fir_ids_json' => 'NVARCHAR(MAX)',
                'start_utc' => 'DATETIME2', 'end_utc' => 'DATETIME2',
                'gs_exempt' => 'BIT', 'gdp_priority' => 'INT',
                'status' => 'NVARCHAR(16)', 'participant_count' => 'INT',
                'synced_at' => 'DATETIME2', 'raw_data_json' => 'NVARCHAR(MAX)',
                'created_at' => 'DATETIME2', 'updated_at' => 'DATETIME2',
            ],
        ],

        // Source: VATSIM_TMI.dbo.tmi_flow_event_participants
        // No updated_at — use synced_at as watermark (populated on INSERT)
        'swim_tmi_flow_event_participants' => [
            'swim_table' => 'swim_tmi_flow_event_participants',
            'pk' => 'id',
            'watermark' => 'synced_at',
            'source_query' => 'SELECT id, event_id, pilot_cid, callsign, dep_aerodrome, arr_aerodrome, external_id, flight_uid, matched_at, synced_at FROM dbo.tmi_flow_event_participants',
            'columns' => [
                'id' => 'INT', 'event_id' => 'INT', 'pilot_cid' => 'INT',
                'callsign' => 'NVARCHAR(16)',
                'dep_aerodrome' => 'NVARCHAR(8)', 'arr_aerodrome' => 'NVARCHAR(8)',
                'external_id' => 'NVARCHAR(64)', 'flight_uid' => 'BIGINT',
                'matched_at' => 'DATETIME2', 'synced_at' => 'DATETIME2',
            ],
        ],

        // Source: VATSIM_TMI.dbo.tmi_flow_measures
        // Column names match exactly
        'swim_tmi_flow_measures' => [
            'swim_table' => 'swim_tmi_flow_measures',
            'pk' => 'measure_id',
            'watermark' => 'updated_at',
            'source_query' => 'SELECT measure_id, measure_guid, provider_id, external_id, ident, revision, event_id, ctl_element, element_type, measure_type, measure_value, measure_unit, reason, filters_json, exemptions_json, mandatory_route_json, start_utc, end_utc, status, withdrawn_at, synced_at, raw_data_json, created_at, updated_at FROM dbo.tmi_flow_measures',
            'columns' => [
                'measure_id' => 'INT', 'measure_guid' => 'UNIQUEIDENTIFIER',
                'provider_id' => 'INT', 'external_id' => 'NVARCHAR(64)',
                'ident' => 'NVARCHAR(32)', 'revision' => 'INT', 'event_id' => 'INT',
                'ctl_element' => 'NVARCHAR(8)', 'element_type' => 'NVARCHAR(16)',
                'measure_type' => 'NVARCHAR(16)', 'measure_value' => 'NVARCHAR(32)',
                'measure_unit' => 'NVARCHAR(16)',
                'reason' => 'NVARCHAR(256)', 'filters_json' => 'NVARCHAR(MAX)',
                'exemptions_json' => 'NVARCHAR(MAX)', 'mandatory_route_json' => 'NVARCHAR(MAX)',
                'start_utc' => 'DATETIME2', 'end_utc' => 'DATETIME2',
                'status' => 'NVARCHAR(16)', 'withdrawn_at' => 'DATETIME2',
                'synced_at' => 'DATETIME2', 'raw_data_json' => 'NVARCHAR(MAX)',
                'created_at' => 'DATETIME2', 'updated_at' => 'DATETIME2',
            ],
        ],
    ];
}

// ============================================================================
// Table Configurations — Tier 1 (Analytics tables from VATSIM_TMI)
// ============================================================================

function getTier1AnalyticsConfigs(): array {
    return [
        // Source: VATSIM_TMI.dbo.tmi_log_core
        // PK: log_id (UNIQUEIDENTIFIER), watermark: event_utc
        'swim_tmi_log_core' => [
            'swim_table' => 'swim_tmi_log_core',
            'pk' => 'log_id',
            'watermark' => 'event_utc',
            'source_query' => 'SELECT log_id, log_seq, action_category, action_type, program_type, severity, source_system, summary, event_utc, user_cid, user_name, issuing_facility, issuing_org FROM dbo.tmi_log_core',
            'columns' => [
                'log_id' => 'UNIQUEIDENTIFIER', 'log_seq' => 'BIGINT',
                'action_category' => 'NVARCHAR(32)', 'action_type' => 'NVARCHAR(32)',
                'program_type' => 'NVARCHAR(32)', 'severity' => 'NVARCHAR(16)',
                'source_system' => 'NVARCHAR(32)', 'summary' => 'NVARCHAR(512)',
                'event_utc' => 'DATETIME2',
                'user_cid' => 'NVARCHAR(16)', 'user_name' => 'NVARCHAR(128)',
                'issuing_facility' => 'NVARCHAR(64)', 'issuing_org' => 'NVARCHAR(64)',
            ],
        ],

        // Source: VATSIM_TMI.dbo.tmi_log_scope
        // Satellite of tmi_log_core — JOIN to core for event_utc watermark
        'swim_tmi_log_scope' => [
            'swim_table' => 'swim_tmi_log_scope',
            'pk' => 'log_id',
            'watermark' => 'c.event_utc',
            'source_query' => 'SELECT s.log_id, s.ctl_element, s.element_type, s.facility, s.traffic_flow, s.via_fix, s.scope_airports, s.scope_tiers FROM dbo.tmi_log_scope s INNER JOIN dbo.tmi_log_core c ON c.log_id = s.log_id',
            'columns' => [
                'log_id' => 'UNIQUEIDENTIFIER',
                'ctl_element' => 'NVARCHAR(64)', 'element_type' => 'NVARCHAR(16)',
                'facility' => 'NVARCHAR(64)', 'traffic_flow' => 'NVARCHAR(32)',
                'via_fix' => 'NVARCHAR(64)',
                'scope_airports' => 'NVARCHAR(MAX)', 'scope_tiers' => 'NVARCHAR(MAX)',
            ],
        ],

        // Source: VATSIM_TMI.dbo.tmi_log_parameters
        // Satellite of tmi_log_core — JOIN to core for event_utc watermark
        'swim_tmi_log_parameters' => [
            'swim_table' => 'swim_tmi_log_parameters',
            'pk' => 'log_id',
            'watermark' => 'c.event_utc',
            'source_query' => 'SELECT s.log_id, s.effective_start_utc, s.effective_end_utc, s.rate_value, s.rate_unit, s.program_rate, s.cause_category, s.cause_detail, s.cancellation_reason, s.ntml_formatted, s.detail_json FROM dbo.tmi_log_parameters s INNER JOIN dbo.tmi_log_core c ON c.log_id = s.log_id',
            'columns' => [
                'log_id' => 'UNIQUEIDENTIFIER',
                'effective_start_utc' => 'DATETIME2', 'effective_end_utc' => 'DATETIME2',
                'rate_value' => 'INT', 'rate_unit' => 'NVARCHAR(16)', 'program_rate' => 'INT',
                'cause_category' => 'NVARCHAR(32)', 'cause_detail' => 'NVARCHAR(256)',
                'cancellation_reason' => 'NVARCHAR(256)',
                'ntml_formatted' => 'NVARCHAR(MAX)', 'detail_json' => 'NVARCHAR(MAX)',
            ],
        ],

        // Source: VATSIM_TMI.dbo.tmi_log_impact
        // Satellite of tmi_log_core — JOIN to core for event_utc watermark
        'swim_tmi_log_impact' => [
            'swim_table' => 'swim_tmi_log_impact',
            'pk' => 'log_id',
            'watermark' => 'c.event_utc',
            'source_query' => 'SELECT s.log_id, s.total_flights, s.controlled_flights, s.avg_delay_min, s.max_delay_min, s.total_delay_min, s.demand_rate, s.capacity_rate, s.compliance_rate FROM dbo.tmi_log_impact s INNER JOIN dbo.tmi_log_core c ON c.log_id = s.log_id',
            'columns' => [
                'log_id' => 'UNIQUEIDENTIFIER',
                'total_flights' => 'INT', 'controlled_flights' => 'INT',
                'avg_delay_min' => 'DECIMAL(8,1)', 'max_delay_min' => 'DECIMAL(8,1)',
                'total_delay_min' => 'DECIMAL(12,1)',
                'demand_rate' => 'INT', 'capacity_rate' => 'INT',
                'compliance_rate' => 'DECIMAL(5,2)',
            ],
        ],

        // Source: VATSIM_TMI.dbo.tmi_log_references
        // Satellite of tmi_log_core — JOIN to core for event_utc watermark
        'swim_tmi_log_references' => [
            'swim_table' => 'swim_tmi_log_references',
            'pk' => 'log_id',
            'watermark' => 'c.event_utc',
            'source_query' => 'SELECT s.log_id, s.program_id, s.entry_id, s.advisory_id, s.reroute_id, s.slot_id, s.flight_uid, s.advisory_number FROM dbo.tmi_log_references s INNER JOIN dbo.tmi_log_core c ON c.log_id = s.log_id',
            'columns' => [
                'log_id' => 'UNIQUEIDENTIFIER',
                'program_id' => 'INT', 'entry_id' => 'INT',
                'advisory_id' => 'INT', 'reroute_id' => 'INT',
                'slot_id' => 'BIGINT', 'flight_uid' => 'BIGINT',
                'advisory_number' => 'NVARCHAR(32)',
            ],
        ],

        // Source: VATSIM_TMI.dbo.tmi_delay_attribution
        // PK: attribution_id (BIGINT), watermark: computed_utc
        'swim_tmi_delay_attribution' => [
            'swim_table' => 'swim_tmi_delay_attribution',
            'pk' => 'attribution_id',
            'watermark' => 'computed_utc',
            'source_query' => 'SELECT attribution_id, flight_uid, callsign, dep_icao, arr_icao, delay_phase, delay_minutes, cause_category, cause_subcategory, attributed_program_id, attributed_facility, computation_method, computed_utc, is_current FROM dbo.tmi_delay_attribution',
            'columns' => [
                'attribution_id' => 'BIGINT', 'flight_uid' => 'BIGINT',
                'callsign' => 'NVARCHAR(16)',
                'dep_icao' => 'NVARCHAR(8)', 'arr_icao' => 'NVARCHAR(8)',
                'delay_phase' => 'NVARCHAR(16)', 'delay_minutes' => 'DECIMAL(8,1)',
                'cause_category' => 'NVARCHAR(32)', 'cause_subcategory' => 'NVARCHAR(32)',
                'attributed_program_id' => 'INT', 'attributed_facility' => 'NVARCHAR(64)',
                'computation_method' => 'NVARCHAR(32)', 'computed_utc' => 'DATETIME2',
                'is_current' => 'BIT',
            ],
        ],

        // Source: VATSIM_TMI.dbo.tmi_facility_stats_hourly
        // PK: stat_id (BIGINT), watermark: computed_utc
        'swim_tmi_facility_stats_hourly' => [
            'swim_table' => 'swim_tmi_facility_stats_hourly',
            'pk' => 'stat_id',
            'watermark' => 'computed_utc',
            'source_query' => 'SELECT stat_id, facility, facility_type, airport_icao, hour_utc, total_operations, total_arrivals, total_departures, ontime_arrivals, delayed_arrivals, avg_arr_delay_min, max_arr_delay_min, delay_min_total, computed_utc FROM dbo.tmi_facility_stats_hourly',
            'columns' => [
                'stat_id' => 'BIGINT',
                'facility' => 'NVARCHAR(64)', 'facility_type' => 'NVARCHAR(16)',
                'airport_icao' => 'NVARCHAR(8)', 'hour_utc' => 'DATETIME2',
                'total_operations' => 'INT', 'total_arrivals' => 'INT', 'total_departures' => 'INT',
                'ontime_arrivals' => 'INT', 'delayed_arrivals' => 'INT',
                'avg_arr_delay_min' => 'DECIMAL(8,1)', 'max_arr_delay_min' => 'DECIMAL(8,1)',
                'delay_min_total' => 'DECIMAL(12,1)', 'computed_utc' => 'DATETIME2',
            ],
        ],

        // Source: VATSIM_TMI.dbo.tmi_facility_stats_daily
        // PK: stat_id (BIGINT), watermark: computed_utc
        'swim_tmi_facility_stats_daily' => [
            'swim_table' => 'swim_tmi_facility_stats_daily',
            'pk' => 'stat_id',
            'watermark' => 'computed_utc',
            'source_query' => 'SELECT stat_id, facility, facility_type, airport_icao, date_utc, total_operations, total_arrivals, total_departures, ontime_arr_pct, avg_arr_delay_min, delay_min_total, programs_issued, computed_utc FROM dbo.tmi_facility_stats_daily',
            'columns' => [
                'stat_id' => 'BIGINT',
                'facility' => 'NVARCHAR(64)', 'facility_type' => 'NVARCHAR(16)',
                'airport_icao' => 'NVARCHAR(8)', 'date_utc' => 'DATE',
                'total_operations' => 'INT', 'total_arrivals' => 'INT', 'total_departures' => 'INT',
                'ontime_arr_pct' => 'DECIMAL(5,2)', 'avg_arr_delay_min' => 'DECIMAL(8,1)',
                'delay_min_total' => 'DECIMAL(12,1)', 'programs_issued' => 'INT',
                'computed_utc' => 'DATETIME2',
            ],
        ],
    ];
}

// ============================================================================
// Table Configurations — Tier 2 (Reference, daily)
// ============================================================================

function getTier2Configs(): array {
    return [
        // Source: VATSIM_ADL.dbo.apts — FAA NASR data with uppercase column names
        // Must alias to lowercase to match swim_airports mirror columns
        // Only sync columns that exist on both source and mirror
        'swim_airports' => [
            'swim_table' => 'swim_airports',
            'source_query' => "SELECT ICAO_ID AS icao_id, ARPT_NAME AS arpt_name, LAT_DECIMAL AS lat_decimal, LONG_DECIMAL AS long_decimal, RESP_ARTCC_ID AS resp_artcc_id, Approach_ID AS approach_id, Approach_Departure_ID AS approach_departure_id, RESP_FIR_ID AS resp_fir_id FROM dbo.apts WHERE ICAO_ID IS NOT NULL AND ICAO_ID <> ''",
            'columns' => [
                'icao_id' => 'VARCHAR(4)', 'arpt_name' => 'NVARCHAR(64)',
                'lat_decimal' => 'DECIMAL(10,7)', 'long_decimal' => 'DECIMAL(11,7)',
                'resp_artcc_id' => 'NVARCHAR(8)', 'approach_id' => 'NVARCHAR(64)',
                'approach_departure_id' => 'NVARCHAR(64)', 'resp_fir_id' => 'NVARCHAR(8)',
            ],
        ],

        // Source: VATSIM_ADL.dbo.airport_taxi_reference (migration adl/oooi/010)
        // Column names match exactly between source and mirror
        'swim_airport_taxi_reference' => [
            'swim_table' => 'swim_airport_taxi_reference',
            'source_query' => 'SELECT airport_icao, unimpeded_taxi_sec, sample_size, window_days, p05_taxi_sec, p10_taxi_sec, p15_taxi_sec, p25_taxi_sec, median_taxi_sec, p75_taxi_sec, p90_taxi_sec, avg_taxi_sec, min_taxi_sec, max_taxi_sec, stddev_taxi_sec, confidence, last_refreshed_utc, created_utc FROM dbo.airport_taxi_reference',
            'columns' => [
                'airport_icao' => 'VARCHAR(4)', 'unimpeded_taxi_sec' => 'INT',
                'sample_size' => 'INT', 'window_days' => 'INT',
                'p05_taxi_sec' => 'INT', 'p10_taxi_sec' => 'INT',
                'p15_taxi_sec' => 'INT', 'p25_taxi_sec' => 'INT',
                'median_taxi_sec' => 'INT', 'p75_taxi_sec' => 'INT', 'p90_taxi_sec' => 'INT',
                'avg_taxi_sec' => 'INT', 'min_taxi_sec' => 'INT', 'max_taxi_sec' => 'INT',
                'stddev_taxi_sec' => 'INT', 'confidence' => 'VARCHAR(8)',
                'last_refreshed_utc' => 'DATETIME2', 'created_utc' => 'DATETIME2',
            ],
        ],

        // Source: VATSIM_ADL.dbo.airport_taxi_reference_detail (migration adl/oooi/010)
        // Column names match exactly between source and mirror
        'swim_airport_taxi_reference_detail' => [
            'swim_table' => 'swim_airport_taxi_reference_detail',
            'source_query' => 'SELECT airport_icao, dimension, dimension_value, unimpeded_taxi_sec, sample_size, p05_taxi_sec, p15_taxi_sec, median_taxi_sec, avg_taxi_sec, last_refreshed_utc FROM dbo.airport_taxi_reference_detail',
            'columns' => [
                'airport_icao' => 'VARCHAR(4)', 'dimension' => 'VARCHAR(32)',
                'dimension_value' => 'VARCHAR(32)', 'unimpeded_taxi_sec' => 'INT',
                'sample_size' => 'INT', 'p05_taxi_sec' => 'INT', 'p15_taxi_sec' => 'INT',
                'median_taxi_sec' => 'INT', 'avg_taxi_sec' => 'INT',
                'last_refreshed_utc' => 'DATETIME2',
            ],
        ],
    ];
}

// ============================================================================
// Table Configurations — Splits (Tier 1: configs/positions, Tier 2: presets/areas)
// ============================================================================

/**
 * Tier 1: Splits configs and positions (delta sync every 5 min).
 * Source: VATSIM_ADL splits_configs + splits_positions (schema migration 010, swim migration 033)
 */
function getTier1SplitsConfigs(): array {
    return [
        // Active/scheduled/draft/inactive configs (excludes archived)
        'splits_configs_swim' => [
            'swim_table' => 'splits_configs_swim',
            'pk' => 'id',
            'watermark' => 'updated_at',
            'source_query' => "SELECT id, artcc, config_name, status, start_time_utc, end_time_utc,
                                      sector_type, [source], source_id, created_by, activated_at,
                                      created_at, updated_at
                               FROM dbo.splits_configs
                               WHERE status NOT IN ('archived')",
            'columns' => [
                'id' => 'INT', 'artcc' => 'NVARCHAR(4)', 'config_name' => 'NVARCHAR(100)',
                'status' => 'NVARCHAR(20)', 'start_time_utc' => 'DATETIME2',
                'end_time_utc' => 'DATETIME2', 'sector_type' => 'NVARCHAR(10)',
                'source' => 'NVARCHAR(50)', 'source_id' => 'NVARCHAR(100)',
                'created_by' => 'NVARCHAR(50)', 'activated_at' => 'DATETIME2',
                'created_at' => 'DATETIME2', 'updated_at' => 'DATETIME2',
            ],
        ],

        // Positions for all non-archived configs
        'splits_positions_swim' => [
            'swim_table' => 'splits_positions_swim',
            'pk' => 'id',
            'watermark' => '', // No watermark on positions — always sync with config
            'source_query' => "SELECT p.id, p.config_id, p.position_name, p.sectors, p.color,
                                      p.sort_order, p.frequency, p.controller_oi, p.strata_filter,
                                      p.start_time_utc, p.end_time_utc
                               FROM dbo.splits_positions p
                               INNER JOIN dbo.splits_configs c ON c.id = p.config_id
                               WHERE c.status NOT IN ('archived')",
            'columns' => [
                'id' => 'INT', 'config_id' => 'INT', 'position_name' => 'NVARCHAR(50)',
                'sectors' => 'NVARCHAR(MAX)', 'color' => 'NVARCHAR(10)',
                'sort_order' => 'INT', 'frequency' => 'NVARCHAR(20)',
                'controller_oi' => 'NVARCHAR(50)', 'strata_filter' => 'NVARCHAR(100)',
                'start_time_utc' => 'DATETIME2', 'end_time_utc' => 'DATETIME2',
            ],
        ],
    ];
}

/**
 * Tier 2: Splits presets and areas (full replace daily).
 * Source: VATSIM_ADL splits_presets/preset_positions/areas
 */
function getTier2SplitsConfigs(): array {
    return [
        'splits_presets_swim' => [
            'swim_table' => 'splits_presets_swim',
            'source_query' => 'SELECT id, preset_name, artcc, description, created_at, updated_at FROM dbo.splits_presets',
            'columns' => [
                'id' => 'INT', 'preset_name' => 'NVARCHAR(100)', 'artcc' => 'NVARCHAR(4)',
                'description' => 'NVARCHAR(500)', 'created_at' => 'DATETIME2',
                'updated_at' => 'DATETIME2',
            ],
        ],

        'splits_preset_positions_swim' => [
            'swim_table' => 'splits_preset_positions_swim',
            'source_query' => 'SELECT id, preset_id, position_name, sectors, color, sort_order, frequency, strata_filter FROM dbo.splits_preset_positions',
            'columns' => [
                'id' => 'INT', 'preset_id' => 'INT', 'position_name' => 'NVARCHAR(50)',
                'sectors' => 'NVARCHAR(MAX)', 'color' => 'NVARCHAR(10)',
                'sort_order' => 'INT', 'frequency' => 'NVARCHAR(20)',
                'strata_filter' => 'NVARCHAR(100)',
            ],
        ],

        'splits_areas_swim' => [
            'swim_table' => 'splits_areas_swim',
            'source_query' => 'SELECT id, artcc, area_name, sectors, description, color, created_at, updated_at FROM dbo.splits_areas',
            'columns' => [
                'id' => 'INT', 'artcc' => 'NVARCHAR(4)', 'area_name' => 'NVARCHAR(100)',
                'sectors' => 'NVARCHAR(MAX)', 'description' => 'NVARCHAR(500)',
                'color' => 'NVARCHAR(10)', 'created_at' => 'DATETIME2',
                'updated_at' => 'DATETIME2',
            ],
        ],
    ];
}

/**
 * Detect splits state transitions and log to splits_history_swim.
 *
 * Compares current ADL state with SWIM mirror to detect:
 * - Configs that became 'active' (were not active before)
 * - Configs that became 'inactive' (were active before)
 * - Active configs with updated_at > synced_utc (modified)
 */
function syncSplitsHistory($conn_adl, $conn_swim): void {
    // Get current active configs from ADL
    $sql = "SELECT c.id, c.artcc, c.config_name, c.status, c.sector_type,
                   c.[source], c.updated_at
            FROM dbo.splits_configs c
            WHERE c.status IN ('active', 'inactive')
              AND c.updated_at > DATEADD(MINUTE, -10, GETUTCDATE())";
    $stmt = @sqlsrv_query($conn_adl, $sql);
    if ($stmt === false) return;

    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        // Check if we already logged this transition
        $config_id = $row['id'];
        $status = $row['status'];
        $event_type = $status === 'active' ? 'activated' : 'deactivated';
        $facility = $row['artcc'];

        foreach ($row as $k => $v) {
            if ($v instanceof DateTime) {
                $row[$k] = $v->format('Y-m-d H:i:s');
            }
        }

        // Check if this exact transition was already logged in the last 10 minutes
        $checkSql = "SELECT TOP 1 id FROM dbo.splits_history_swim
                     WHERE config_id = ? AND event_type = ? AND event_at > DATEADD(MINUTE, -10, SYSUTCDATETIME())";
        $checkStmt = @sqlsrv_query($conn_swim, $checkSql, [$config_id, $event_type]);
        if ($checkStmt !== false) {
            $exists = sqlsrv_fetch_array($checkStmt, SQLSRV_FETCH_ASSOC);
            sqlsrv_free_stmt($checkStmt);
            if ($exists) continue; // Already logged
        }

        // Build snapshot
        $snapshot = json_encode($row, JSON_UNESCAPED_UNICODE);

        // Insert history record
        $insertSql = "INSERT INTO dbo.splits_history_swim
                         (config_id, facility, event_type, config_snapshot, [source], event_at, synced_utc)
                      VALUES (?, ?, ?, ?, ?, SYSUTCDATETIME(), SYSUTCDATETIME())";
        @sqlsrv_query($conn_swim, $insertSql, [
            $config_id, $facility, $event_type, $snapshot, $row['source'] ?? 'perti'
        ]);
    }
    sqlsrv_free_stmt($stmt);

    // Purge old history (> 30 days)
    @sqlsrv_query($conn_swim,
        "DELETE FROM dbo.splits_history_swim WHERE event_at < DATEADD(DAY, -30, SYSUTCDATETIME())");
}

// ============================================================================
// Tier 1 Sync Runner
// ============================================================================

function runTier1Sync($conn_tmi, $conn_adl, $conn_swim, bool $debug): array {
    $results = [];
    $totalStart = microtime(true);

    // TMI tables from VATSIM_TMI
    $tmiConfigs = getTier1TmiConfigs();
    foreach ($tmiConfigs as $name => $config) {
        $lastSync = getLastSyncTime($conn_swim, $name);
        $stats = syncTableToSwim($conn_tmi, $conn_swim, $config, $lastSync);
        $results[$name] = $stats;

        if ($stats['error']) {
            tmi_log("  $name: ERROR - {$stats['error']}", 'ERROR');
        } elseif ($stats['rows_read'] > 0 || $debug) {
            tmi_log("  $name: {$stats['rows_read']} read, {$stats['updated']} merged in {$stats['duration_ms']}ms");
        }

        updateSyncState($conn_swim, $name, $stats['rows_read'], $stats['duration_ms'],
            $lastSync ? 'delta' : 'full', $stats['error']);
    }

    // NTML from VATSIM_ADL
    $ntmlConfigs = getTier1NtmlConfigs();
    foreach ($ntmlConfigs as $name => $config) {
        $lastSync = getLastSyncTime($conn_swim, $name);
        $stats = syncTableToSwim($conn_adl, $conn_swim, $config, $lastSync);
        $results[$name] = $stats;

        if ($stats['error']) {
            tmi_log("  $name: ERROR - {$stats['error']}", 'ERROR');
        } elseif ($stats['rows_read'] > 0 || $debug) {
            tmi_log("  $name: {$stats['rows_read']} read, {$stats['updated']} merged in {$stats['duration_ms']}ms");
        }

        updateSyncState($conn_swim, $name, $stats['rows_read'], $stats['duration_ms'],
            $lastSync ? 'delta' : 'full', $stats['error']);
    }

    // Flow tables from VATSIM_TMI
    $flowConfigs = getTier1FlowConfigs();
    foreach ($flowConfigs as $name => $config) {
        $lastSync = getLastSyncTime($conn_swim, $name);
        $stats = syncTableToSwim($conn_tmi, $conn_swim, $config, $lastSync);
        $results[$name] = $stats;

        if ($stats['error']) {
            tmi_log("  $name: ERROR - {$stats['error']}", 'ERROR');
        } elseif ($stats['rows_read'] > 0 || $debug) {
            tmi_log("  $name: {$stats['rows_read']} read, {$stats['updated']} merged in {$stats['duration_ms']}ms");
        }

        updateSyncState($conn_swim, $name, $stats['rows_read'], $stats['duration_ms'],
            $lastSync ? 'delta' : 'full', $stats['error']);
    }

    // Analytics tables from VATSIM_TMI
    $analyticsConfigs = getTier1AnalyticsConfigs();
    foreach ($analyticsConfigs as $name => $config) {
        $lastSync = getLastSyncTime($conn_swim, $name);
        $stats = syncTableToSwim($conn_tmi, $conn_swim, $config, $lastSync);
        $results[$name] = $stats;

        if ($stats['error']) {
            tmi_log("  $name: ERROR - {$stats['error']}", 'ERROR');
        } elseif ($stats['rows_read'] > 0 || $debug) {
            tmi_log("  $name: {$stats['rows_read']} read, {$stats['updated']} merged in {$stats['duration_ms']}ms");
        }

        updateSyncState($conn_swim, $name, $stats['rows_read'], $stats['duration_ms'],
            $lastSync ? 'delta' : 'full', $stats['error']);
    }

    // Splits configs + positions from VATSIM_ADL (migration schema/010 + swim/033)
    $splitsConfigs = getTier1SplitsConfigs();
    foreach ($splitsConfigs as $name => $config) {
        $lastSync = getLastSyncTime($conn_swim, $name);
        $stats = syncTableToSwim($conn_adl, $conn_swim, $config, $lastSync);
        $results[$name] = $stats;

        if ($stats['error']) {
            tmi_log("  $name: ERROR - {$stats['error']}", 'ERROR');
        } elseif ($stats['rows_read'] > 0 || $debug) {
            tmi_log("  $name: {$stats['rows_read']} read, {$stats['updated']} merged in {$stats['duration_ms']}ms");
        }

        updateSyncState($conn_swim, $name, $stats['rows_read'], $stats['duration_ms'],
            $lastSync ? 'delta' : 'full', $stats['error']);
    }

    // Detect and log splits state transitions for history table
    syncSplitsHistory($conn_adl, $conn_swim);

    $totalMs = (int)round((microtime(true) - $totalStart) * 1000);
    $totalRows = array_sum(array_column($results, 'rows_read'));
    $errorCount = count(array_filter($results, fn($s) => $s['error'] !== null));

    return [
        'tables' => count($results),
        'total_rows' => $totalRows,
        'errors' => $errorCount,
        'duration_ms' => $totalMs,
        'details' => $results,
    ];
}

// ============================================================================
// Tier 2 Reference Sync Runner
// ============================================================================

function runTier2Sync($conn_adl, $conn_swim, bool $debug): array {
    global $conn_pdo;

    $results = [];
    $totalStart = microtime(true);

    // Reference tables from VATSIM_ADL (full replace)
    $refConfigs = getTier2Configs();
    foreach ($refConfigs as $name => $config) {
        tmi_log("  Syncing reference: $name ...");
        $stats = syncTableFullReplace($conn_adl, $conn_swim, $config);
        $results[$name] = $stats;

        if ($stats['error']) {
            tmi_log("  $name: ERROR - {$stats['error']}", 'ERROR');
        } else {
            tmi_log("  $name: {$stats['inserted']} inserted in {$stats['duration_ms']}ms");
        }

        updateSyncState($conn_swim, $name, $stats['rows_read'] ?? $stats['inserted'],
            $stats['duration_ms'], 'full', $stats['error']);

        // Brief pause between tables to stay within DTU budget
        sleep(2);
    }

    // Splits presets + areas from VATSIM_ADL (full replace — rarely change)
    $splitsRefConfigs = getTier2SplitsConfigs();
    foreach ($splitsRefConfigs as $name => $config) {
        tmi_log("  Syncing reference: $name ...");
        $stats = syncTableFullReplace($conn_adl, $conn_swim, $config);
        $results[$name] = $stats;

        if ($stats['error']) {
            tmi_log("  $name: ERROR - {$stats['error']}", 'ERROR');
        } else {
            tmi_log("  $name: {$stats['inserted']} inserted in {$stats['duration_ms']}ms");
        }

        updateSyncState($conn_swim, $name, $stats['inserted'], $stats['duration_ms'], 'full', $stats['error']);
        sleep(2);
    }

    // Playbook throughput from MySQL (if connection available)
    if ($conn_pdo) {
        $name = 'swim_playbook_route_throughput';
        tmi_log("  Syncing reference: $name (MySQL) ...");
        // Source: MySQL perti_site.playbook_route_throughput
        // Mirror: SWIM_API.dbo.swim_playbook_route_throughput
        $mysqlConfig = [
            'swim_table' => $name,
            'source_query' => 'SELECT throughput_id, route_id, play_id, source, planned_count, slot_count, peak_rate_hr, avg_rate_hr, period_start, period_end, metadata_json, updated_by, updated_at, created_at FROM playbook_route_throughput',
            'columns' => [
                'throughput_id' => 'INT', 'route_id' => 'INT', 'play_id' => 'INT',
                'source' => 'NVARCHAR(50)', 'planned_count' => 'INT', 'slot_count' => 'INT',
                'peak_rate_hr' => 'INT', 'avg_rate_hr' => 'DECIMAL(6,1)',
                'period_start' => 'DATETIME2', 'period_end' => 'DATETIME2',
                'metadata_json' => 'NVARCHAR(MAX)', 'updated_by' => 'NVARCHAR(20)',
                'updated_at' => 'DATETIME2', 'created_at' => 'DATETIME2',
            ],
        ];
        $stats = syncMysqlTableToSwim($conn_pdo, $conn_swim, $mysqlConfig);
        $results[$name] = $stats;

        if ($stats['error']) {
            tmi_log("  $name: ERROR - {$stats['error']}", 'ERROR');
        } else {
            tmi_log("  $name: {$stats['inserted']} inserted in {$stats['duration_ms']}ms");
        }

        updateSyncState($conn_swim, $name, $stats['inserted'], $stats['duration_ms'], 'full', $stats['error']);
    }

    // Route history stats from MySQL (daily aggregation)
    if ($conn_pdo) {
        $name = 'swim_route_stats';
        tmi_log("  Syncing reference: $name (MySQL route history) ...");
        $stats = syncRouteStats($conn_pdo, $conn_swim, $debug);
        $results[$name] = $stats;

        if ($stats['error']) {
            tmi_log("  $name: ERROR - {$stats['error']}", 'ERROR');
        } else {
            tmi_log("  $name: {$stats['inserted']} inserted in {$stats['duration_ms']}ms");
        }

        updateSyncState($conn_swim, $name, $stats['inserted'], $stats['duration_ms'], 'full', $stats['error']);
    }

    $totalMs = (int)round((microtime(true) - $totalStart) * 1000);
    return [
        'tables' => count($results),
        'total_rows' => array_sum(array_column($results, 'inserted')) + array_sum(array_column($results, 'rows_read')),
        'errors' => count(array_filter($results, fn($s) => $s['error'] !== null)),
        'duration_ms' => $totalMs,
    ];
}

/**
 * Sync route history stats from MySQL star schema to swim_route_stats.
 * Runs as part of Tier 2 (daily reference sync).
 *
 * Source: MySQL perti_site.route_history_facts + dim_route + dim_aircraft_type + dim_operator
 * Target: SWIM_API.dbo.swim_route_stats
 */
function syncRouteStats($conn_pdo, $conn_swim, bool $debug): array {
    $start = microtime(true);
    $stats = ['rows_read' => 0, 'inserted' => 0, 'duration_ms' => 0, 'error' => null];

    if (!$conn_pdo) {
        $stats['error'] = 'MySQL connection not available';
        $stats['duration_ms'] = (int)round((microtime(true) - $start) * 1000);
        return $stats;
    }

    try {
        // Aggregate route statistics per city pair per normalized route
        // Minimum 5 flights to be included
        $sql = "
            SELECT
                f.origin_icao,
                f.dest_icao,
                d.route_hash,
                d.normalized_route,
                COUNT(*) AS flight_count,
                ROUND(COUNT(*) * 100.0 / pair_totals.pair_count, 2) AS usage_pct,
                ROUND(AVG(f.altitude_ft) / 100) * 100 AS avg_altitude_ft,
                MIN(t.flight_date) AS first_seen,
                MAX(t.flight_date) AS last_seen
            FROM route_history_facts f
            JOIN dim_route d ON f.route_dim_id = d.route_dim_id
            JOIN dim_time t ON f.time_dim_id = t.time_dim_id
            JOIN (
                SELECT origin_icao, dest_icao, COUNT(*) AS pair_count
                FROM route_history_facts
                GROUP BY origin_icao, dest_icao
            ) pair_totals ON f.origin_icao = pair_totals.origin_icao AND f.dest_icao = pair_totals.dest_icao
            GROUP BY f.origin_icao, f.dest_icao, d.route_hash, d.normalized_route, pair_totals.pair_count
            HAVING COUNT(*) >= 5
            ORDER BY f.origin_icao, f.dest_icao, flight_count DESC
        ";

        $stmt = $conn_pdo->query($sql);
        $routes = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $stats['rows_read'] = count($routes);

        if ($debug) {
            tmi_log("  Route stats: {$stats['rows_read']} aggregated routes from MySQL");
        }

        if (empty($routes)) {
            $stats['duration_ms'] = (int)round((microtime(true) - $start) * 1000);
            return $stats;
        }

        // Now get top-5 aircraft and operators per route
        // This is a separate query to avoid massive GROUP_CONCAT in the main aggregate
        $topAircraftSql = "
            SELECT f.origin_icao, f.dest_icao, d.route_hash,
                   GROUP_CONCAT(a.icao_code ORDER BY cnt DESC SEPARATOR ',') AS top_aircraft
            FROM (
                SELECT f2.origin_icao, f2.dest_icao, f2.route_dim_id, f2.aircraft_dim_id, COUNT(*) AS cnt
                FROM route_history_facts f2
                WHERE f2.aircraft_dim_id IS NOT NULL
                GROUP BY f2.origin_icao, f2.dest_icao, f2.route_dim_id, f2.aircraft_dim_id
            ) f
            JOIN dim_route d ON f.route_dim_id = d.route_dim_id
            JOIN dim_aircraft_type a ON f.aircraft_dim_id = a.aircraft_dim_id
            GROUP BY f.origin_icao, f.dest_icao, d.route_hash
        ";
        $acStmt = $conn_pdo->query($topAircraftSql);
        $acMap = [];
        while ($row = $acStmt->fetch(\PDO::FETCH_ASSOC)) {
            $key = $row['origin_icao'] . '|' . $row['dest_icao'] . '|' . bin2hex($row['route_hash']);
            $codes = explode(',', $row['top_aircraft']);
            $acMap[$key] = implode(',', array_slice($codes, 0, 5));
        }

        $topOperatorsSql = "
            SELECT f.origin_icao, f.dest_icao, d.route_hash,
                   GROUP_CONCAT(o.airline_icao ORDER BY cnt DESC SEPARATOR ',') AS top_operators
            FROM (
                SELECT f2.origin_icao, f2.dest_icao, f2.route_dim_id, f2.operator_dim_id, COUNT(*) AS cnt
                FROM route_history_facts f2
                WHERE f2.operator_dim_id IS NOT NULL
                GROUP BY f2.origin_icao, f2.dest_icao, f2.route_dim_id, f2.operator_dim_id
            ) f
            JOIN dim_route d ON f.route_dim_id = d.route_dim_id
            JOIN dim_operator o ON f.operator_dim_id = o.operator_dim_id
            GROUP BY f.origin_icao, f.dest_icao, d.route_hash
        ";
        $opStmt = $conn_pdo->query($topOperatorsSql);
        $opMap = [];
        while ($row = $opStmt->fetch(\PDO::FETCH_ASSOC)) {
            $key = $row['origin_icao'] . '|' . $row['dest_icao'] . '|' . bin2hex($row['route_hash']);
            $codes = explode(',', $row['top_operators']);
            $opMap[$key] = implode(',', array_slice($codes, 0, 5));
        }

        // Enrich routes with top aircraft/operators
        foreach ($routes as &$route) {
            $key = $route['origin_icao'] . '|' . $route['dest_icao'] . '|' . bin2hex($route['route_hash']);
            $route['common_aircraft'] = $acMap[$key] ?? null;
            $route['common_operators'] = $opMap[$key] ?? null;
        }
        unset($route);

        // Truncate + batch insert into SWIM_API
        @sqlsrv_query($conn_swim, "TRUNCATE TABLE dbo.swim_route_stats");

        $columns = [
            'origin_icao' => 'NVARCHAR(4)',
            'dest_icao' => 'NVARCHAR(4)',
            'route_hash' => 'VARBINARY(16)',
            'normalized_route' => 'NVARCHAR(MAX)',
            'flight_count' => 'INT',
            'usage_pct' => 'DECIMAL(5,2)',
            'avg_altitude_ft' => 'INT',
            'common_aircraft' => 'NVARCHAR(200)',
            'common_operators' => 'NVARCHAR(200)',
            'first_seen' => 'DATE',
            'last_seen' => 'DATE',
        ];

        // Convert route_hash from binary to hex string for JSON transport
        $jsonRows = array_map(function ($r) {
            $r['route_hash'] = '0x' . bin2hex($r['route_hash']);
            $r['first_seen'] = ($r['first_seen'] instanceof \DateTime) ? $r['first_seen']->format('Y-m-d') : $r['first_seen'];
            $r['last_seen'] = ($r['last_seen'] instanceof \DateTime) ? $r['last_seen']->format('Y-m-d') : $r['last_seen'];
            return $r;
        }, $routes);

        foreach (array_chunk($jsonRows, 500) as $batch) {
            $json = json_encode($batch, JSON_UNESCAPED_UNICODE);
            if ($json === false) continue;

            $withCols = [];
            foreach ($columns as $colName => $sqlType) {
                if ($colName === 'route_hash') {
                    // VARBINARY needs special handling — use CONVERT
                    $withCols[] = "[$colName] NVARCHAR(34) '\$.$colName'";
                } else {
                    $withCols[] = "[$colName] $sqlType '\$.$colName'";
                }
            }
            $withClause = implode(",\n                ", $withCols);

            $insertCols = implode(', ', array_map(fn($c) => "[$c]", array_keys($columns)));

            // For route_hash, convert from hex string to binary
            $selectCols = [];
            foreach (array_keys($columns) as $col) {
                if ($col === 'route_hash') {
                    $selectCols[] = "CONVERT(VARBINARY(16), [$col], 1) AS [$col]";
                } else {
                    $selectCols[] = "[$col]";
                }
            }
            $selectClause = implode(', ', $selectCols);

            $insertSql = "
                INSERT INTO dbo.swim_route_stats ($insertCols, last_sync_utc)
                SELECT $selectClause, SYSUTCDATETIME()
                FROM OPENJSON(?) WITH ($withClause)
            ";

            $result = @sqlsrv_query($conn_swim, $insertSql, [&$json], ['QueryTimeout' => 120]);
            if ($result === false) {
                $stats['error'] = "INSERT swim_route_stats failed: " . json_encode(sqlsrv_errors());
                break;
            }
            $stats['inserted'] += sqlsrv_rows_affected($result);
            sqlsrv_free_stmt($result);
        }

    } catch (\Throwable $e) {
        $stats['error'] = 'Route stats sync error: ' . $e->getMessage();
    }

    $stats['duration_ms'] = (int)round((microtime(true) - $start) * 1000);
    return $stats;
}

/**
 * Check if current time is within the reference sync window (0601-0801 UTC).
 */
function isInRefdataWindow(): bool {
    $hour = (int)gmdate('G');
    $min = (int)gmdate('i');
    $minuteOfDay = $hour * 60 + $min;
    $windowStart = REFDATA_WINDOW_START_HOUR * 60 + REFDATA_WINDOW_START_MIN;
    $windowEnd = REFDATA_WINDOW_END_HOUR * 60 + REFDATA_WINDOW_END_MIN;
    return $minuteOfDay >= $windowStart && $minuteOfDay <= $windowEnd;
}

// ============================================================================
// Main Daemon Loop
// ============================================================================

if (tmi_check_existing_instance()) {
    tmi_log("Another instance is already running. Exiting.", 'WARN');
    exit(1);
}

tmi_write_pid();
register_shutdown_function(function () {
    @unlink(TMI_SYNC_HEARTBEAT_FILE);
});
tmi_write_heartbeat('starting');

tmi_log("========================================");
tmi_log("SWIM TMI Sync Daemon Starting");
tmi_log("  Sync interval: {$syncInterval}s (" . round($syncInterval / 60, 1) . " min)");
tmi_log("  Mode: " . ($runLoop ? 'daemon (continuous)' : 'single run'));
tmi_log("  PID: " . getmypid());
tmi_log("========================================");

// Initial offset: sleep 60s to stagger from flight sync
if ($runLoop) {
    tmi_log("Sleeping 60s to offset from flight sync...");
    sleep(60);
}

$cycleCount = 0;
$lastRefdataDate = ''; // Track daily reference sync by date

do {
    $cycleStart = microtime(true);
    $cycleCount++;
    tmi_write_heartbeat('running', ['cycle' => $cycleCount]);

    tmi_log("--- TMI sync cycle #$cycleCount ---");

    // Get connections (lazy loaded)
    $conn_tmi = get_conn_tmi();
    $conn_adl = get_conn_adl();
    $conn_swim = get_conn_swim();

    if (!$conn_swim) {
        tmi_log("SWIM_API connection unavailable, skipping cycle", 'ERROR');
        sleep($syncInterval);
        continue;
    }

    // ========================================================================
    // Tier 1: Operational sync (every cycle)
    // ========================================================================
    if ($conn_tmi && $conn_adl) {
        try {
            $t1Result = runTier1Sync($conn_tmi, $conn_adl, $conn_swim, $debug);
            tmi_log(sprintf("Tier 1: %d tables, %d rows, %d errors in %dms",
                $t1Result['tables'], $t1Result['total_rows'], $t1Result['errors'], $t1Result['duration_ms']));
        } catch (\Throwable $e) {
            tmi_log("Tier 1 exception: " . $e->getMessage(), 'ERROR');
        }
    } else {
        tmi_log("TMI/ADL connections unavailable for Tier 1", 'WARN');
    }

    // ========================================================================
    // Tier 2: Reference sync (once per day, within window)
    // ========================================================================
    $today = gmdate('Y-m-d');
    if ($lastRefdataDate !== $today && isInRefdataWindow() && $conn_adl) {
        tmi_log("Running daily reference sync...");
        try {
            $t2Result = runTier2Sync($conn_adl, $conn_swim, $debug);
            tmi_log(sprintf("Tier 2: %d tables, %d rows, %d errors in %dms",
                $t2Result['tables'], $t2Result['total_rows'], $t2Result['errors'], $t2Result['duration_ms']));
            $lastRefdataDate = $today;
        } catch (\Throwable $e) {
            tmi_log("Tier 2 exception: " . $e->getMessage(), 'ERROR');
        }
    }

    $cycleDuration = microtime(true) - $cycleStart;
    tmi_write_heartbeat('idle', [
        'cycle' => $cycleCount,
        'cycle_ms' => (int)round($cycleDuration * 1000),
    ]);

    // ========================================================================
    // Sleep until next cycle
    // ========================================================================
    if ($runLoop) {
        $sleepSeconds = max(1, (int)ceil($syncInterval - $cycleDuration));

        if ($debug) {
            tmi_log("Cycle completed in " . round($cycleDuration * 1000) . "ms, sleeping {$sleepSeconds}s", 'DEBUG');
        }

        $sleepRemaining = $sleepSeconds;
        while ($sleepRemaining > 0) {
            sleep(1);
            $sleepRemaining--;
            if (function_exists('pcntl_signal_dispatch')) {
                pcntl_signal_dispatch();
            }
        }
    }

} while ($runLoop);

tmi_log("SWIM TMI Sync Daemon exiting");
