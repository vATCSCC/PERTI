<?php
/**
 * TMI Unified Log Helper
 *
 * Provides log_tmi_action() for all TMI endpoints to write to the
 * tmi_log_core + satellite tables. Supports both sqlsrv and PDO connections.
 *
 * @package PERTI
 * @subpackage Load
 * @version 1.0.0
 * @date 2026-03-30
 */

/**
 * Log a TMI action to the unified log tables.
 *
 * @param mixed $conn  Database connection — sqlsrv resource OR PDO instance
 * @param array $core  Required. Keys: action_category, action_type, summary.
 *                     Optional: program_type, severity, source_system, event_utc,
 *                     user_cid, user_name, user_position, user_oi, session_id,
 *                     issuing_facility, issuing_org
 * @param array|null $scope     Optional tmi_log_scope fields
 * @param array|null $params    Optional tmi_log_parameters fields
 * @param array|null $impact    Optional tmi_log_impact fields
 * @param array|null $refs      Optional tmi_log_references fields
 * @return string|null The log_id UUID on success, null on failure
 */
function log_tmi_action($conn, array $core, ?array $scope = null,
                        ?array $params = null, ?array $impact = null,
                        ?array $refs = null): ?string
{
    if (!$conn) {
        error_log('[tmi_log] No connection provided');
        return null;
    }

    $is_pdo = ($conn instanceof PDO);

    try {
        // Generate UUID for log_id
        $log_id = _tmi_log_uuid($conn, $is_pdo);
        if (!$log_id) {
            error_log('[tmi_log] Failed to generate UUID');
            return null;
        }

        // Auto-populate authoring from session if not provided
        if (empty($core['user_cid']) && isset($_SESSION['VATSIM_CID'])) {
            $core['user_cid'] = $_SESSION['VATSIM_CID'];
        }
        if (empty($core['user_name']) && isset($_SESSION['VATSIM_NAME'])) {
            $core['user_name'] = $_SESSION['VATSIM_NAME'];
        }
        if (empty($core['session_id']) && session_id()) {
            $core['session_id'] = session_id();
        }
        if (empty($core['source_system'])) {
            $core['source_system'] = 'PERTI_WEB';
        }

        // Insert core row (required)
        $core_sql = "INSERT INTO dbo.tmi_log_core
            (log_id, action_category, action_type, program_type, severity,
             source_system, summary, event_utc,
             user_cid, user_name, user_position, user_oi,
             session_id, issuing_facility, issuing_org)
            VALUES (?, ?, ?, ?, ?,
                    ?, ?, ISNULL(?, SYSUTCDATETIME()),
                    ?, ?, ?, ?,
                    ?, ?, ?)";
        $core_params = [
            $log_id,
            $core['action_category'],
            $core['action_type'],
            $core['program_type'] ?? null,
            $core['severity'] ?? 'INFO',
            $core['source_system'],
            $core['summary'],
            $core['event_utc'] ?? null,
            $core['user_cid'] ?? null,
            $core['user_name'] ?? null,
            $core['user_position'] ?? null,
            $core['user_oi'] ?? null,
            $core['session_id'] ?? null,
            $core['issuing_facility'] ?? null,
            $core['issuing_org'] ?? null
        ];
        _tmi_log_exec($conn, $is_pdo, $core_sql, $core_params);

        // Insert scope row (optional)
        if ($scope) {
            $scope_sql = "INSERT INTO dbo.tmi_log_scope
                (log_id, ctl_element, element_type, facility, traffic_flow,
                 via_fix, scope_airports, scope_tiers, scope_altitude,
                 scope_aircraft_type, scope_carriers, scope_equipment,
                 exclusions, flt_incl_type, affected_facilities,
                 dep_facilities, dep_scope, filters_json)
                VALUES (?, ?, ?, ?, ?,
                        ?, ?, ?, ?,
                        ?, ?, ?,
                        ?, ?, ?,
                        ?, ?, ?)";
            $scope_params = [
                $log_id,
                $scope['ctl_element'] ?? null,
                $scope['element_type'] ?? null,
                $scope['facility'] ?? null,
                $scope['traffic_flow'] ?? null,
                $scope['via_fix'] ?? null,
                $scope['scope_airports'] ?? null,
                $scope['scope_tiers'] ?? null,
                $scope['scope_altitude'] ?? null,
                $scope['scope_aircraft_type'] ?? null,
                $scope['scope_carriers'] ?? null,
                $scope['scope_equipment'] ?? null,
                $scope['exclusions'] ?? null,
                $scope['flt_incl_type'] ?? null,
                $scope['affected_facilities'] ?? null,
                $scope['dep_facilities'] ?? null,
                $scope['dep_scope'] ?? null,
                $scope['filters_json'] ?? null
            ];
            _tmi_log_exec($conn, $is_pdo, $scope_sql, $scope_params);
        }

        // Insert parameters row (optional)
        if ($params) {
            $params_sql = "INSERT INTO dbo.tmi_log_parameters
                (log_id, effective_start_utc, effective_end_utc,
                 rate_value, rate_unit, spacing_type, program_rate,
                 rates_hourly_json, rates_quarter_json, delay_cap,
                 cause_category, cause_detail, impacting_condition, prob_extension,
                 delay_type, delay_minutes, delay_trend,
                 holding_status, holding_fix, aircraft_holding,
                 weather_conditions, arrival_runways, departure_runways, config_name,
                 gs_probability, gs_release_rate,
                 cancellation_reason, cancellation_edct_action, cancellation_notes,
                 meter_point, freeze_horizon, compression_enabled,
                 ntml_formatted, remarks, detail_json, qualifiers)
                VALUES (?, ?, ?,
                        ?, ?, ?, ?,
                        ?, ?, ?,
                        ?, ?, ?, ?,
                        ?, ?, ?,
                        ?, ?, ?,
                        ?, ?, ?, ?,
                        ?, ?,
                        ?, ?, ?,
                        ?, ?, ?,
                        ?, ?, ?, ?)";
            $params_vals = [
                $log_id,
                $params['effective_start_utc'] ?? null,
                $params['effective_end_utc'] ?? null,
                $params['rate_value'] ?? null,
                $params['rate_unit'] ?? null,
                $params['spacing_type'] ?? null,
                $params['program_rate'] ?? null,
                $params['rates_hourly_json'] ?? null,
                $params['rates_quarter_json'] ?? null,
                $params['delay_cap'] ?? null,
                $params['cause_category'] ?? null,
                $params['cause_detail'] ?? null,
                $params['impacting_condition'] ?? null,
                $params['prob_extension'] ?? null,
                $params['delay_type'] ?? null,
                $params['delay_minutes'] ?? null,
                $params['delay_trend'] ?? null,
                $params['holding_status'] ?? null,
                $params['holding_fix'] ?? null,
                $params['aircraft_holding'] ?? null,
                $params['weather_conditions'] ?? null,
                $params['arrival_runways'] ?? null,
                $params['departure_runways'] ?? null,
                $params['config_name'] ?? null,
                $params['gs_probability'] ?? null,
                $params['gs_release_rate'] ?? null,
                $params['cancellation_reason'] ?? null,
                $params['cancellation_edct_action'] ?? null,
                $params['cancellation_notes'] ?? null,
                $params['meter_point'] ?? null,
                $params['freeze_horizon'] ?? null,
                $params['compression_enabled'] ?? null,
                $params['ntml_formatted'] ?? null,
                $params['remarks'] ?? null,
                $params['detail_json'] ?? null,
                $params['qualifiers'] ?? null
            ];
            _tmi_log_exec($conn, $is_pdo, $params_sql, $params_vals);
        }

        // Insert impact row (optional)
        if ($impact) {
            $impact_sql = "INSERT INTO dbo.tmi_log_impact
                (log_id, total_flights, controlled_flights, exempt_flights,
                 airborne_flights, popup_flights,
                 avg_delay_min, max_delay_min, total_delay_min,
                 cumulative_total_delay, cumulative_max_delay,
                 demand_rate, capacity_rate,
                 reversal_count, reversal_pct, gaming_flags_count,
                 compliance_rate, comments)
                VALUES (?, ?, ?, ?,
                        ?, ?,
                        ?, ?, ?,
                        ?, ?,
                        ?, ?,
                        ?, ?, ?,
                        ?, ?)";
            $impact_params = [
                $log_id,
                $impact['total_flights'] ?? null,
                $impact['controlled_flights'] ?? null,
                $impact['exempt_flights'] ?? null,
                $impact['airborne_flights'] ?? null,
                $impact['popup_flights'] ?? null,
                $impact['avg_delay_min'] ?? null,
                $impact['max_delay_min'] ?? null,
                $impact['total_delay_min'] ?? null,
                $impact['cumulative_total_delay'] ?? null,
                $impact['cumulative_max_delay'] ?? null,
                $impact['demand_rate'] ?? null,
                $impact['capacity_rate'] ?? null,
                $impact['reversal_count'] ?? null,
                $impact['reversal_pct'] ?? null,
                $impact['gaming_flags_count'] ?? null,
                $impact['compliance_rate'] ?? null,
                $impact['comments'] ?? null
            ];
            _tmi_log_exec($conn, $is_pdo, $impact_sql, $impact_params);
        }

        // Insert references row (optional)
        if ($refs) {
            $refs_sql = "INSERT INTO dbo.tmi_log_references
                (log_id, program_id, entry_id, advisory_id, reroute_id,
                 slot_id, flight_uid, proposal_id, flow_measure_id,
                 delay_entry_id, airport_config_id,
                 parent_log_id, supersedes_log_id, supersedes_entry_id,
                 advisory_number, cancel_advisory_num, revision_number,
                 source_type, source_id, source_channel, content_hash,
                 discord_message_id, discord_channel_id, discord_channel_purpose,
                 coordination_log_id)
                VALUES (?, ?, ?, ?, ?,
                        ?, ?, ?, ?,
                        ?, ?,
                        ?, ?, ?,
                        ?, ?, ?,
                        ?, ?, ?, ?,
                        ?, ?, ?,
                        ?)";
            $refs_params = [
                $log_id,
                $refs['program_id'] ?? null,
                $refs['entry_id'] ?? null,
                $refs['advisory_id'] ?? null,
                $refs['reroute_id'] ?? null,
                $refs['slot_id'] ?? null,
                $refs['flight_uid'] ?? null,
                $refs['proposal_id'] ?? null,
                $refs['flow_measure_id'] ?? null,
                $refs['delay_entry_id'] ?? null,
                $refs['airport_config_id'] ?? null,
                $refs['parent_log_id'] ?? null,
                $refs['supersedes_log_id'] ?? null,
                $refs['supersedes_entry_id'] ?? null,
                $refs['advisory_number'] ?? null,
                $refs['cancel_advisory_num'] ?? null,
                $refs['revision_number'] ?? null,
                $refs['source_type'] ?? null,
                $refs['source_id'] ?? null,
                $refs['source_channel'] ?? null,
                $refs['content_hash'] ?? null,
                $refs['discord_message_id'] ?? null,
                $refs['discord_channel_id'] ?? null,
                $refs['discord_channel_purpose'] ?? null,
                $refs['coordination_log_id'] ?? null
            ];
            _tmi_log_exec($conn, $is_pdo, $refs_sql, $refs_params);
        }

        return $log_id;

    } catch (Exception $e) {
        error_log('[tmi_log] Failed to log action: ' . $e->getMessage());
        return null;
    }
}

/**
 * Generate a UUID via SQL Server NEWID().
 * @internal
 */
function _tmi_log_uuid($conn, bool $is_pdo): ?string
{
    if ($is_pdo) {
        $stmt = $conn->query("SELECT CAST(NEWID() AS NVARCHAR(36))");
        $row = $stmt->fetch(PDO::FETCH_NUM);
        return $row ? $row[0] : null;
    }

    $stmt = sqlsrv_query($conn, "SELECT CAST(NEWID() AS NVARCHAR(36))");
    if ($stmt === false) return null;
    $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_NUMERIC);
    sqlsrv_free_stmt($stmt);
    return $row ? $row[0] : null;
}

/**
 * Execute a parameterized INSERT via the correct driver.
 * @internal
 */
function _tmi_log_exec($conn, bool $is_pdo, string $sql, array $params): void
{
    if ($is_pdo) {
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        return;
    }

    // sqlsrv path
    $stmt = sqlsrv_query($conn, $sql, $params);
    if ($stmt === false) {
        $errors = sqlsrv_errors();
        $msg = $errors ? $errors[0]['message'] : 'Unknown error';
        throw new RuntimeException('[tmi_log] sqlsrv_query failed: ' . $msg);
    }
    sqlsrv_free_stmt($stmt);
}
