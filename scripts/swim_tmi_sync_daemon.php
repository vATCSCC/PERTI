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
        'swim_tmi_programs' => [
            'swim_table' => 'swim_tmi_programs',
            'pk' => 'program_id',
            'watermark' => 'updated_at',
            'source_query' => 'SELECT program_id, program_type, airport_icao, reason, scope, direction, rate_aar, rate_adr, rate_combined, start_time, end_time, status, severity, delay_avg, delay_max, created_by, updated_by, coordination_status, coordination_notes, discord_message_id, discord_channel_id, simulation_id, event_id, is_historical, source_type, plan_id, original_program_id, revision_number, auto_cancel_utc, compression_ratio, pop_count, exempt_count, slot_count, flight_count, edct_compliance_pct, advisory_id, ntml_id, gs_type, operating_mode, tier_config, delay_assignment_mode, reserve_ratio, max_delay_minutes, created_at, updated_at FROM dbo.tmi_programs',
            'columns' => [
                'program_id' => 'INT', 'program_type' => 'NVARCHAR(20)', 'airport_icao' => 'NVARCHAR(8)',
                'reason' => 'NVARCHAR(MAX)', 'scope' => 'NVARCHAR(MAX)', 'direction' => 'NVARCHAR(20)',
                'rate_aar' => 'INT', 'rate_adr' => 'INT', 'rate_combined' => 'INT',
                'start_time' => 'DATETIME2(0)', 'end_time' => 'DATETIME2(0)', 'status' => 'NVARCHAR(20)',
                'severity' => 'NVARCHAR(20)', 'delay_avg' => 'INT', 'delay_max' => 'INT',
                'created_by' => 'NVARCHAR(64)', 'updated_by' => 'NVARCHAR(64)',
                'coordination_status' => 'NVARCHAR(20)', 'coordination_notes' => 'NVARCHAR(MAX)',
                'discord_message_id' => 'NVARCHAR(64)', 'discord_channel_id' => 'NVARCHAR(64)',
                'simulation_id' => 'INT', 'event_id' => 'INT', 'is_historical' => 'BIT',
                'source_type' => 'NVARCHAR(20)', 'plan_id' => 'INT', 'original_program_id' => 'INT',
                'revision_number' => 'INT', 'auto_cancel_utc' => 'DATETIME2(0)',
                'compression_ratio' => 'DECIMAL(5,2)', 'pop_count' => 'INT', 'exempt_count' => 'INT',
                'slot_count' => 'INT', 'flight_count' => 'INT', 'edct_compliance_pct' => 'DECIMAL(5,2)',
                'advisory_id' => 'INT', 'ntml_id' => 'INT', 'gs_type' => 'NVARCHAR(20)',
                'operating_mode' => 'NVARCHAR(20)', 'tier_config' => 'NVARCHAR(MAX)',
                'delay_assignment_mode' => 'NVARCHAR(20)', 'reserve_ratio' => 'DECIMAL(5,2)',
                'max_delay_minutes' => 'INT', 'created_at' => 'DATETIME2(0)', 'updated_at' => 'DATETIME2(0)',
            ],
        ],

        'swim_tmi_entries' => [
            'swim_table' => 'swim_tmi_entries',
            'pk' => 'entry_id',
            'watermark' => 'updated_at',
            'source_query' => 'SELECT entry_id, entry_type, sub_type, airport_icao, direction, effective_from, effective_until, status, scope_airports, scope_artccs, scope_fixes, rate_value, rate_unit, delay_minutes, reason, text_description, source_program_id, artcc, created_by, updated_by, discord_message_id, discord_channel_id, ntml_id, plan_id, event_id, is_historical, source_type, coordination_status, coordination_notes, created_at, updated_at FROM dbo.tmi_entries',
            'columns' => [
                'entry_id' => 'INT', 'entry_type' => 'NVARCHAR(20)', 'sub_type' => 'NVARCHAR(20)',
                'airport_icao' => 'NVARCHAR(8)', 'direction' => 'NVARCHAR(20)',
                'effective_from' => 'DATETIME2(0)', 'effective_until' => 'DATETIME2(0)',
                'status' => 'NVARCHAR(20)', 'scope_airports' => 'NVARCHAR(MAX)',
                'scope_artccs' => 'NVARCHAR(MAX)', 'scope_fixes' => 'NVARCHAR(MAX)',
                'rate_value' => 'INT', 'rate_unit' => 'NVARCHAR(20)', 'delay_minutes' => 'INT',
                'reason' => 'NVARCHAR(MAX)', 'text_description' => 'NVARCHAR(MAX)',
                'source_program_id' => 'INT', 'artcc' => 'NVARCHAR(8)',
                'created_by' => 'NVARCHAR(64)', 'updated_by' => 'NVARCHAR(64)',
                'discord_message_id' => 'NVARCHAR(64)', 'discord_channel_id' => 'NVARCHAR(64)',
                'ntml_id' => 'INT', 'plan_id' => 'INT', 'event_id' => 'INT',
                'is_historical' => 'BIT', 'source_type' => 'NVARCHAR(20)',
                'coordination_status' => 'NVARCHAR(20)', 'coordination_notes' => 'NVARCHAR(MAX)',
                'created_at' => 'DATETIME2(0)', 'updated_at' => 'DATETIME2(0)',
            ],
        ],

        'swim_tmi_advisories' => [
            'swim_table' => 'swim_tmi_advisories',
            'pk' => 'advisory_id',
            'watermark' => 'updated_at',
            'source_query' => 'SELECT advisory_id, advisory_type, priority, title, body, airport_icao, artcc, facility_type, scope_airports, scope_artccs, effective_from, effective_until, status, source_type, source_reference, discord_message_id, discord_channel_id, published_utc, acknowledged_by, acknowledged_utc, cancelled_by, cancelled_utc, cancel_reason, program_id, ntml_id, plan_id, event_id, is_historical, attachments, tags, visibility, auto_cancel_utc, reminder_utc, superseded_by, supersedes, parent_advisory_id, revision_number, created_by, created_at, updated_at FROM dbo.tmi_advisories',
            'columns' => [
                'advisory_id' => 'INT', 'advisory_type' => 'NVARCHAR(20)', 'priority' => 'NVARCHAR(20)',
                'title' => 'NVARCHAR(MAX)', 'body' => 'NVARCHAR(MAX)', 'airport_icao' => 'NVARCHAR(8)',
                'artcc' => 'NVARCHAR(8)', 'facility_type' => 'NVARCHAR(20)',
                'scope_airports' => 'NVARCHAR(MAX)', 'scope_artccs' => 'NVARCHAR(MAX)',
                'effective_from' => 'DATETIME2(0)', 'effective_until' => 'DATETIME2(0)',
                'status' => 'NVARCHAR(20)', 'source_type' => 'NVARCHAR(20)',
                'source_reference' => 'NVARCHAR(MAX)',
                'discord_message_id' => 'NVARCHAR(64)', 'discord_channel_id' => 'NVARCHAR(64)',
                'published_utc' => 'DATETIME2(0)', 'acknowledged_by' => 'NVARCHAR(64)',
                'acknowledged_utc' => 'DATETIME2(0)', 'cancelled_by' => 'NVARCHAR(64)',
                'cancelled_utc' => 'DATETIME2(0)', 'cancel_reason' => 'NVARCHAR(MAX)',
                'program_id' => 'INT', 'ntml_id' => 'INT', 'plan_id' => 'INT', 'event_id' => 'INT',
                'is_historical' => 'BIT', 'attachments' => 'NVARCHAR(MAX)', 'tags' => 'NVARCHAR(MAX)',
                'visibility' => 'NVARCHAR(20)', 'auto_cancel_utc' => 'DATETIME2(0)',
                'reminder_utc' => 'DATETIME2(0)', 'superseded_by' => 'INT', 'supersedes' => 'INT',
                'parent_advisory_id' => 'INT', 'revision_number' => 'INT',
                'created_by' => 'NVARCHAR(64)', 'created_at' => 'DATETIME2(0)', 'updated_at' => 'DATETIME2(0)',
            ],
        ],

        'swim_tmi_reroutes' => [
            'swim_table' => 'swim_tmi_reroutes',
            'pk' => 'reroute_id',
            'watermark' => 'updated_at',
            'source_query' => 'SELECT reroute_id, reroute_name, reroute_type, trigger_type, trigger_value, origin_artcc, origin_airport, origin_fix, dest_artcc, dest_airport, dest_fix, via_artcc, via_fix, original_route, reroute_route, route_description, advisory_text, status, priority, reason, weather_type, effective_from, effective_until, scope_airports_dept, scope_airports_dest, scope_artccs, scope_altitude_min, scope_altitude_max, scope_aircraft_types, compliance_target_pct, compliance_current_pct, total_affected, total_compliant, total_noncompliant, total_exempt, discord_message_id, discord_channel_id, ntml_id, advisory_id, plan_id, event_id, is_historical, source_type, auto_cancel_utc, reminder_utc, program_id, original_reroute_id, revision_number, coordination_status, coordination_notes, created_by, created_at, updated_at FROM dbo.tmi_reroutes',
            'columns' => [
                'reroute_id' => 'INT', 'reroute_name' => 'NVARCHAR(64)', 'reroute_type' => 'NVARCHAR(20)',
                'trigger_type' => 'NVARCHAR(20)', 'trigger_value' => 'NVARCHAR(MAX)',
                'origin_artcc' => 'NVARCHAR(8)', 'origin_airport' => 'NVARCHAR(8)',
                'origin_fix' => 'NVARCHAR(16)', 'dest_artcc' => 'NVARCHAR(8)',
                'dest_airport' => 'NVARCHAR(8)', 'dest_fix' => 'NVARCHAR(16)',
                'via_artcc' => 'NVARCHAR(8)', 'via_fix' => 'NVARCHAR(16)',
                'original_route' => 'NVARCHAR(MAX)', 'reroute_route' => 'NVARCHAR(MAX)',
                'route_description' => 'NVARCHAR(MAX)', 'advisory_text' => 'NVARCHAR(MAX)',
                'status' => 'INT', 'priority' => 'NVARCHAR(20)', 'reason' => 'NVARCHAR(MAX)',
                'weather_type' => 'NVARCHAR(20)', 'effective_from' => 'DATETIME2(0)',
                'effective_until' => 'DATETIME2(0)', 'scope_airports_dept' => 'NVARCHAR(MAX)',
                'scope_airports_dest' => 'NVARCHAR(MAX)', 'scope_artccs' => 'NVARCHAR(MAX)',
                'scope_altitude_min' => 'INT', 'scope_altitude_max' => 'INT',
                'scope_aircraft_types' => 'NVARCHAR(MAX)', 'compliance_target_pct' => 'DECIMAL(5,2)',
                'compliance_current_pct' => 'DECIMAL(5,2)', 'total_affected' => 'INT',
                'total_compliant' => 'INT', 'total_noncompliant' => 'INT', 'total_exempt' => 'INT',
                'discord_message_id' => 'NVARCHAR(64)', 'discord_channel_id' => 'NVARCHAR(64)',
                'ntml_id' => 'INT', 'advisory_id' => 'INT', 'plan_id' => 'INT', 'event_id' => 'INT',
                'is_historical' => 'BIT', 'source_type' => 'NVARCHAR(20)',
                'auto_cancel_utc' => 'DATETIME2(0)', 'reminder_utc' => 'DATETIME2(0)',
                'program_id' => 'INT', 'original_reroute_id' => 'INT', 'revision_number' => 'INT',
                'coordination_status' => 'NVARCHAR(20)', 'coordination_notes' => 'NVARCHAR(MAX)',
                'created_by' => 'NVARCHAR(64)', 'created_at' => 'DATETIME2(0)', 'updated_at' => 'DATETIME2(0)',
            ],
        ],

        'swim_tmi_reroute_routes' => [
            'swim_table' => 'swim_tmi_reroute_routes',
            'pk' => 'route_id',
            'watermark' => 'updated_at',
            'source_query' => 'SELECT route_id, reroute_id, route_string, route_type, origin_fix, dest_fix, via_fixes, sequence_order, is_preferred, created_at, updated_at FROM dbo.tmi_reroute_routes',
            'columns' => [
                'route_id' => 'INT', 'reroute_id' => 'INT', 'route_string' => 'NVARCHAR(MAX)',
                'route_type' => 'NVARCHAR(20)', 'origin_fix' => 'NVARCHAR(16)',
                'dest_fix' => 'NVARCHAR(16)', 'via_fixes' => 'NVARCHAR(MAX)',
                'sequence_order' => 'INT', 'is_preferred' => 'BIT',
                'created_at' => 'DATETIME2(0)', 'updated_at' => 'DATETIME2(0)',
            ],
        ],

        'swim_tmi_reroute_flights' => [
            'swim_table' => 'swim_tmi_reroute_flights',
            'pk' => 'id',
            'watermark' => 'updated_at',
            'source_query' => 'SELECT id, reroute_id, flight_uid, callsign, cid, fp_dept_icao, fp_dest_icao, fp_route, current_route, assigned_route_id, compliance_status, compliance_pct, compliance_checked_utc, is_exempt, exempt_reason, exempt_by, is_acknowledged, acknowledged_utc, acknowledged_by, notification_sent, notification_sent_utc, original_route, route_amended_utc, pilot_contacted, pilot_response, notes, flight_phase, altitude_ft, groundspeed_kts, current_artcc, current_fix, eta_dest_utc, created_at, updated_at FROM dbo.tmi_reroute_flights',
            'columns' => [
                'id' => 'INT', 'reroute_id' => 'INT', 'flight_uid' => 'BIGINT',
                'callsign' => 'NVARCHAR(16)', 'cid' => 'INT',
                'fp_dept_icao' => 'NVARCHAR(8)', 'fp_dest_icao' => 'NVARCHAR(8)',
                'fp_route' => 'NVARCHAR(MAX)', 'current_route' => 'NVARCHAR(MAX)',
                'assigned_route_id' => 'INT', 'compliance_status' => 'NVARCHAR(20)',
                'compliance_pct' => 'DECIMAL(5,2)', 'compliance_checked_utc' => 'DATETIME2(0)',
                'is_exempt' => 'BIT', 'exempt_reason' => 'NVARCHAR(MAX)',
                'exempt_by' => 'NVARCHAR(64)', 'is_acknowledged' => 'BIT',
                'acknowledged_utc' => 'DATETIME2(0)', 'acknowledged_by' => 'NVARCHAR(64)',
                'notification_sent' => 'BIT', 'notification_sent_utc' => 'DATETIME2(0)',
                'original_route' => 'NVARCHAR(MAX)', 'route_amended_utc' => 'DATETIME2(0)',
                'pilot_contacted' => 'BIT', 'pilot_response' => 'NVARCHAR(MAX)',
                'notes' => 'NVARCHAR(MAX)', 'flight_phase' => 'NVARCHAR(20)',
                'altitude_ft' => 'INT', 'groundspeed_kts' => 'INT',
                'current_artcc' => 'NVARCHAR(8)', 'current_fix' => 'NVARCHAR(16)',
                'eta_dest_utc' => 'DATETIME2(0)',
                'created_at' => 'DATETIME2(0)', 'updated_at' => 'DATETIME2(0)',
            ],
        ],

        'swim_tmi_reroute_compliance_log' => [
            'swim_table' => 'swim_tmi_reroute_compliance_log',
            'pk' => 'log_id',
            'watermark' => 'checked_utc',
            'source_query' => 'SELECT log_id, reroute_flight_id, reroute_id, flight_uid, compliance_status, compliance_pct, route_at_check, fix_sequence_matched, total_fixes_checked, checked_utc FROM dbo.tmi_reroute_compliance_log',
            'columns' => [
                'log_id' => 'INT', 'reroute_flight_id' => 'INT', 'reroute_id' => 'INT',
                'flight_uid' => 'BIGINT', 'compliance_status' => 'NVARCHAR(20)',
                'compliance_pct' => 'DECIMAL(5,2)', 'route_at_check' => 'NVARCHAR(MAX)',
                'fix_sequence_matched' => 'NVARCHAR(MAX)', 'total_fixes_checked' => 'INT',
                'checked_utc' => 'DATETIME2(0)',
            ],
        ],

        'swim_tmi_public_routes' => [
            'swim_table' => 'swim_tmi_public_routes',
            'pk' => 'route_id',
            'watermark' => 'updated_at',
            'source_query' => 'SELECT route_id, route_name, route_type, origin_airport, origin_fix, dest_airport, dest_fix, route_string, route_description, status, valid_start_utc, valid_end_utc, priority, artcc, direction, advisory_text, weather_type, discord_message_id, discord_channel_id, ntml_id, plan_id, event_id, created_by, created_at, updated_at FROM dbo.tmi_public_routes',
            'columns' => [
                'route_id' => 'INT', 'route_name' => 'NVARCHAR(64)', 'route_type' => 'NVARCHAR(20)',
                'origin_airport' => 'NVARCHAR(8)', 'origin_fix' => 'NVARCHAR(16)',
                'dest_airport' => 'NVARCHAR(8)', 'dest_fix' => 'NVARCHAR(16)',
                'route_string' => 'NVARCHAR(MAX)', 'route_description' => 'NVARCHAR(MAX)',
                'status' => 'INT', 'valid_start_utc' => 'DATETIME2(0)', 'valid_end_utc' => 'DATETIME2(0)',
                'priority' => 'INT', 'artcc' => 'NVARCHAR(8)', 'direction' => 'NVARCHAR(20)',
                'advisory_text' => 'NVARCHAR(MAX)', 'weather_type' => 'NVARCHAR(20)',
                'discord_message_id' => 'NVARCHAR(64)', 'discord_channel_id' => 'NVARCHAR(64)',
                'ntml_id' => 'INT', 'plan_id' => 'INT', 'event_id' => 'INT',
                'created_by' => 'NVARCHAR(64)', 'created_at' => 'DATETIME2(0)', 'updated_at' => 'DATETIME2(0)',
            ],
        ],

        'swim_tmi_flight_control' => [
            'swim_table' => 'swim_tmi_flight_control',
            'pk' => 'id',
            'watermark' => 'updated_at',
            'source_query' => 'SELECT id, flight_uid, callsign, cid, program_id, program_type, airport_icao, ctl_type, ctl_element, ctd_utc, cta_utc, edct_utc, slot_time_utc, slot_id, delay_minutes, delay_status, is_exempt, exempt_reason, gs_held, gs_release_utc, compliance_status, compliance_checked_utc, original_etd, original_eta, is_popup, popup_detected_utc, assigned_utc, released_utc, released_reason, created_at, updated_at FROM dbo.tmi_flight_control',
            'columns' => [
                'id' => 'INT', 'flight_uid' => 'BIGINT', 'callsign' => 'NVARCHAR(16)', 'cid' => 'INT',
                'program_id' => 'INT', 'program_type' => 'NVARCHAR(20)', 'airport_icao' => 'NVARCHAR(8)',
                'ctl_type' => 'NVARCHAR(8)', 'ctl_element' => 'NVARCHAR(8)',
                'ctd_utc' => 'DATETIME2(0)', 'cta_utc' => 'DATETIME2(0)', 'edct_utc' => 'DATETIME2(0)',
                'slot_time_utc' => 'DATETIME2(0)', 'slot_id' => 'BIGINT', 'delay_minutes' => 'INT',
                'delay_status' => 'NVARCHAR(16)', 'is_exempt' => 'BIT', 'exempt_reason' => 'NVARCHAR(64)',
                'gs_held' => 'BIT', 'gs_release_utc' => 'DATETIME2(0)',
                'compliance_status' => 'NVARCHAR(20)', 'compliance_checked_utc' => 'DATETIME2(0)',
                'original_etd' => 'DATETIME2(0)', 'original_eta' => 'DATETIME2(0)',
                'is_popup' => 'BIT', 'popup_detected_utc' => 'DATETIME2(0)',
                'assigned_utc' => 'DATETIME2(0)', 'released_utc' => 'DATETIME2(0)',
                'released_reason' => 'NVARCHAR(64)',
                'created_at' => 'DATETIME2(0)', 'updated_at' => 'DATETIME2(0)',
            ],
        ],
    ];
}

// ============================================================================
// Table Configurations — Tier 1 (NTML from VATSIM_ADL)
// ============================================================================

function getTier1NtmlConfigs(): array {
    return [
        'swim_ntml' => [
            'swim_table' => 'swim_ntml',
            'pk' => 'ntml_id',
            'watermark' => 'updated_at',
            'source_query' => 'SELECT ntml_id, program_name, entry_type, program_type, status, airport_icao, artcc, reason, scope, direction, rate_aar, rate_adr, rate_combined, start_time, end_time, delay_avg, delay_max, text_body, advisory_text, source_reference, discord_message_id, discord_channel_id, published_utc, published_by, cancelled_utc, cancelled_by, cancel_reason, superseded_by, supersedes, parent_ntml_id, revision_number, plan_id, event_id, program_id, entry_id, advisory_id, reroute_id, is_archived, is_historical, source_type, auto_number, sequence_in_day, valid_date, effective_from, effective_until, acknowledgment_required, acknowledged_count, total_recipients, tags, visibility, priority, coordination_status, created_at, updated_at FROM dbo.ntml',
            'columns' => [
                'ntml_id' => 'INT', 'program_name' => 'NVARCHAR(100)', 'entry_type' => 'NVARCHAR(20)',
                'program_type' => 'NVARCHAR(20)', 'status' => 'NVARCHAR(20)',
                'airport_icao' => 'NVARCHAR(8)', 'artcc' => 'NVARCHAR(8)',
                'reason' => 'NVARCHAR(MAX)', 'scope' => 'NVARCHAR(MAX)', 'direction' => 'NVARCHAR(20)',
                'rate_aar' => 'INT', 'rate_adr' => 'INT', 'rate_combined' => 'INT',
                'start_time' => 'DATETIME2(0)', 'end_time' => 'DATETIME2(0)',
                'delay_avg' => 'INT', 'delay_max' => 'INT',
                'text_body' => 'NVARCHAR(MAX)', 'advisory_text' => 'NVARCHAR(MAX)',
                'source_reference' => 'NVARCHAR(MAX)',
                'discord_message_id' => 'NVARCHAR(64)', 'discord_channel_id' => 'NVARCHAR(64)',
                'published_utc' => 'DATETIME2(0)', 'published_by' => 'NVARCHAR(64)',
                'cancelled_utc' => 'DATETIME2(0)', 'cancelled_by' => 'NVARCHAR(64)',
                'cancel_reason' => 'NVARCHAR(MAX)', 'superseded_by' => 'INT', 'supersedes' => 'INT',
                'parent_ntml_id' => 'INT', 'revision_number' => 'INT',
                'plan_id' => 'INT', 'event_id' => 'INT', 'program_id' => 'INT',
                'entry_id' => 'INT', 'advisory_id' => 'INT', 'reroute_id' => 'INT',
                'is_archived' => 'BIT', 'is_historical' => 'BIT', 'source_type' => 'NVARCHAR(20)',
                'auto_number' => 'INT', 'sequence_in_day' => 'INT', 'valid_date' => 'DATE',
                'effective_from' => 'DATETIME2(0)', 'effective_until' => 'DATETIME2(0)',
                'acknowledgment_required' => 'BIT', 'acknowledged_count' => 'INT',
                'total_recipients' => 'INT', 'tags' => 'NVARCHAR(MAX)',
                'visibility' => 'NVARCHAR(20)', 'priority' => 'NVARCHAR(20)',
                'coordination_status' => 'NVARCHAR(20)',
                'created_at' => 'DATETIME2(0)', 'updated_at' => 'DATETIME2(0)',
            ],
        ],
    ];
}

// ============================================================================
// Table Configurations — Tier 1 (Flow tables from VATSIM_TMI)
// ============================================================================

function getTier1FlowConfigs(): array {
    return [
        'swim_tmi_flow_providers' => [
            'swim_table' => 'swim_tmi_flow_providers',
            'pk' => 'provider_id',
            'watermark' => 'updated_at',
            'source_query' => 'SELECT provider_id, provider_name, provider_type, api_url, api_key_hash, region, country, fir_codes, is_active, poll_interval_seconds, last_poll_utc, last_success_utc, events_imported, measures_imported, error_count, last_error, config_json, created_at, updated_at FROM dbo.tmi_flow_providers',
            'columns' => [
                'provider_id' => 'INT', 'provider_name' => 'NVARCHAR(100)',
                'provider_type' => 'NVARCHAR(20)', 'api_url' => 'NVARCHAR(MAX)',
                'api_key_hash' => 'NVARCHAR(128)', 'region' => 'NVARCHAR(20)',
                'country' => 'NVARCHAR(8)', 'fir_codes' => 'NVARCHAR(MAX)',
                'is_active' => 'BIT', 'poll_interval_seconds' => 'INT',
                'last_poll_utc' => 'DATETIME2(0)', 'last_success_utc' => 'DATETIME2(0)',
                'events_imported' => 'INT', 'measures_imported' => 'INT',
                'error_count' => 'INT', 'last_error' => 'NVARCHAR(MAX)',
                'config_json' => 'NVARCHAR(MAX)',
                'created_at' => 'DATETIME2(0)', 'updated_at' => 'DATETIME2(0)',
            ],
        ],

        'swim_tmi_flow_events' => [
            'swim_table' => 'swim_tmi_flow_events',
            'pk' => 'event_id',
            'watermark' => 'updated_at',
            'source_query' => 'SELECT event_id, provider_id, external_id, event_type, name, reason, start_utc, end_utc, status, fir, airport_icao, notam_id, info_url, filters_json, impact_json, is_active, withdrawn_utc, created_at, updated_at FROM dbo.tmi_flow_events',
            'columns' => [
                'event_id' => 'INT', 'provider_id' => 'INT', 'external_id' => 'NVARCHAR(64)',
                'event_type' => 'NVARCHAR(20)', 'name' => 'NVARCHAR(MAX)', 'reason' => 'NVARCHAR(MAX)',
                'start_utc' => 'DATETIME2(0)', 'end_utc' => 'DATETIME2(0)',
                'status' => 'NVARCHAR(20)', 'fir' => 'NVARCHAR(8)', 'airport_icao' => 'NVARCHAR(8)',
                'notam_id' => 'NVARCHAR(32)', 'info_url' => 'NVARCHAR(MAX)',
                'filters_json' => 'NVARCHAR(MAX)', 'impact_json' => 'NVARCHAR(MAX)',
                'is_active' => 'BIT', 'withdrawn_utc' => 'DATETIME2(0)',
                'created_at' => 'DATETIME2(0)', 'updated_at' => 'DATETIME2(0)',
            ],
        ],

        'swim_tmi_flow_event_participants' => [
            'swim_table' => 'swim_tmi_flow_event_participants',
            'pk' => 'participant_id',
            'watermark' => 'created_at',
            'source_query' => 'SELECT participant_id, event_id, flight_uid, callsign, cid, fp_dept_icao, fp_dest_icao, is_exempt, exempt_reason, created_at FROM dbo.tmi_flow_event_participants',
            'columns' => [
                'participant_id' => 'INT', 'event_id' => 'INT', 'flight_uid' => 'BIGINT',
                'callsign' => 'NVARCHAR(16)', 'cid' => 'INT',
                'fp_dept_icao' => 'NVARCHAR(8)', 'fp_dest_icao' => 'NVARCHAR(8)',
                'is_exempt' => 'BIT', 'exempt_reason' => 'NVARCHAR(MAX)',
                'created_at' => 'DATETIME2(0)',
            ],
        ],

        'swim_tmi_flow_measures' => [
            'swim_table' => 'swim_tmi_flow_measures',
            'pk' => 'measure_id',
            'watermark' => 'updated_at',
            'source_query' => 'SELECT measure_id, provider_id, event_id, external_id, measure_type, value, unit, status, min_altitude_ft, max_altitude_ft, start_utc, end_utc, exempt_icao, exempt_callsigns, filters_json, affected_airports, affected_firs, notified_utc, acknowledged_utc, withdrawn_utc, is_active, reason, notes, ntml_id, created_at, updated_at FROM dbo.tmi_flow_measures',
            'columns' => [
                'measure_id' => 'INT', 'provider_id' => 'INT', 'event_id' => 'INT',
                'external_id' => 'NVARCHAR(64)', 'measure_type' => 'NVARCHAR(20)',
                'value' => 'INT', 'unit' => 'NVARCHAR(20)', 'status' => 'NVARCHAR(20)',
                'min_altitude_ft' => 'INT', 'max_altitude_ft' => 'INT',
                'start_utc' => 'DATETIME2(0)', 'end_utc' => 'DATETIME2(0)',
                'exempt_icao' => 'NVARCHAR(MAX)', 'exempt_callsigns' => 'NVARCHAR(MAX)',
                'filters_json' => 'NVARCHAR(MAX)', 'affected_airports' => 'NVARCHAR(MAX)',
                'affected_firs' => 'NVARCHAR(MAX)',
                'notified_utc' => 'DATETIME2(0)', 'acknowledged_utc' => 'DATETIME2(0)',
                'withdrawn_utc' => 'DATETIME2(0)', 'is_active' => 'BIT',
                'reason' => 'NVARCHAR(MAX)', 'notes' => 'NVARCHAR(MAX)', 'ntml_id' => 'INT',
                'created_at' => 'DATETIME2(0)', 'updated_at' => 'DATETIME2(0)',
            ],
        ],
    ];
}

// ============================================================================
// Table Configurations — Tier 2 (Reference, daily)
// ============================================================================

function getTier2Configs(): array {
    return [
        'swim_airports' => [
            'swim_table' => 'swim_airports',
            'source_query' => "SELECT icao, iata, name, city, state, country, lat, lon, elevation_ft, timezone, artcc, tracon, tower_type, airport_class, magnetic_var, is_towered, longest_runway_ft, fuel_types, created_at, updated_at FROM dbo.apts",
            'columns' => [
                'icao' => 'NVARCHAR(8)', 'iata' => 'NVARCHAR(4)', 'name' => 'NVARCHAR(128)',
                'city' => 'NVARCHAR(64)', 'state' => 'NVARCHAR(4)', 'country' => 'NVARCHAR(4)',
                'lat' => 'DECIMAL(10,7)', 'lon' => 'DECIMAL(11,7)', 'elevation_ft' => 'INT',
                'timezone' => 'NVARCHAR(64)', 'artcc' => 'NVARCHAR(8)', 'tracon' => 'NVARCHAR(8)',
                'tower_type' => 'NVARCHAR(20)', 'airport_class' => 'NVARCHAR(20)',
                'magnetic_var' => 'DECIMAL(5,2)', 'is_towered' => 'BIT',
                'longest_runway_ft' => 'INT', 'fuel_types' => 'NVARCHAR(64)',
                'created_at' => 'DATETIME2(0)', 'updated_at' => 'DATETIME2(0)',
            ],
        ],

        'swim_airport_taxi_reference' => [
            'swim_table' => 'swim_airport_taxi_reference',
            'source_query' => 'SELECT airport_icao, sample_count, taxi_out_median_sec, taxi_out_p5_sec, taxi_out_p15_sec, taxi_out_p85_sec, taxi_out_p95_sec, taxi_in_median_sec, taxi_in_p5_sec, taxi_in_p15_sec, taxi_in_p85_sec, taxi_in_p95_sec, unimpeded_taxi_out_sec, unimpeded_taxi_in_sec, data_start_date, data_end_date, last_calculated_utc, created_at FROM dbo.airport_taxi_reference',
            'columns' => [
                'airport_icao' => 'NVARCHAR(8)', 'sample_count' => 'INT',
                'taxi_out_median_sec' => 'INT', 'taxi_out_p5_sec' => 'INT',
                'taxi_out_p15_sec' => 'INT', 'taxi_out_p85_sec' => 'INT', 'taxi_out_p95_sec' => 'INT',
                'taxi_in_median_sec' => 'INT', 'taxi_in_p5_sec' => 'INT',
                'taxi_in_p15_sec' => 'INT', 'taxi_in_p85_sec' => 'INT', 'taxi_in_p95_sec' => 'INT',
                'unimpeded_taxi_out_sec' => 'INT', 'unimpeded_taxi_in_sec' => 'INT',
                'data_start_date' => 'DATE', 'data_end_date' => 'DATE',
                'last_calculated_utc' => 'DATETIME2(0)', 'created_at' => 'DATETIME2(0)',
            ],
        ],

        'swim_airport_taxi_reference_detail' => [
            'swim_table' => 'swim_airport_taxi_reference_detail',
            'source_query' => 'SELECT airport_icao, direction, hour_utc, sample_count, median_sec, p5_sec, p15_sec, p85_sec, p95_sec, unimpeded_sec, last_calculated_utc FROM dbo.airport_taxi_reference_detail',
            'columns' => [
                'airport_icao' => 'NVARCHAR(8)', 'direction' => 'NVARCHAR(4)',
                'hour_utc' => 'INT', 'sample_count' => 'INT',
                'median_sec' => 'INT', 'p5_sec' => 'INT', 'p15_sec' => 'INT',
                'p85_sec' => 'INT', 'p95_sec' => 'INT', 'unimpeded_sec' => 'INT',
                'last_calculated_utc' => 'DATETIME2(0)',
            ],
        ],
    ];
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

    // Playbook throughput from MySQL (if connection available)
    if ($conn_pdo) {
        $name = 'swim_playbook_route_throughput';
        tmi_log("  Syncing reference: $name (MySQL) ...");
        $mysqlConfig = [
            'swim_table' => $name,
            'source_query' => 'SELECT route_id, play_id, airport_icao, direction, time_bucket, throughput_count, avg_delay_min, compliance_pct, sample_size, period_start, period_end, created_at, updated_at FROM playbook_route_throughput',
            'columns' => [
                'route_id' => 'INT', 'play_id' => 'INT', 'airport_icao' => 'NVARCHAR(8)',
                'direction' => 'NVARCHAR(4)', 'time_bucket' => 'NVARCHAR(8)',
                'throughput_count' => 'INT', 'avg_delay_min' => 'DECIMAL(8,2)',
                'compliance_pct' => 'DECIMAL(5,2)', 'sample_size' => 'INT',
                'period_start' => 'DATE', 'period_end' => 'DATE',
                'created_at' => 'DATETIME2(0)', 'updated_at' => 'DATETIME2(0)',
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

    $totalMs = (int)round((microtime(true) - $totalStart) * 1000);
    return [
        'tables' => count($results),
        'total_rows' => array_sum(array_column($results, 'inserted')) + array_sum(array_column($results, 'rows_read')),
        'errors' => count(array_filter($results, fn($s) => $s['error'] !== null)),
        'duration_ms' => $totalMs,
    ];
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
