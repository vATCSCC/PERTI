<?php
/**
 * CDM Service — Core Orchestrator
 *
 * Central service for Collaborative Decision Making operations.
 * Coordinates EDCT delivery, pilot readiness tracking, TSAT/TTOT computation,
 * and compliance evaluation.
 *
 * Adapted from:
 *   - FAA CDM (EDCT delivery + ACK via AOCnet)
 *   - EUROCONTROL A-CDM (milestone tracking, TSAT/TTOT computation)
 *   - VATSIM-specific: direct pilot delivery, voluntary compliance, auto-detection
 *
 * Hibernation-aware: All operations check HIBERNATION_MODE and queue data
 * for post-processing when active.
 *
 * @package PERTI
 * @subpackage CDM
 * @version 1.0.0
 */

class CDMService
{
    private $conn_tmi;   // sqlsrv connection to VATSIM_TMI
    private $conn_adl;   // sqlsrv connection to VATSIM_ADL
    private bool $is_hibernation;
    private bool $verbose;

    // Readiness state constants
    const STATE_PLANNING  = 'PLANNING';
    const STATE_BOARDING  = 'BOARDING';
    const STATE_READY     = 'READY';
    const STATE_TAXIING   = 'TAXIING';
    const STATE_CANCELLED = 'CANCELLED';

    // Message type constants
    const MSG_EDCT         = 'EDCT';
    const MSG_GATE_HOLD    = 'GATE_HOLD';
    const MSG_GATE_RELEASE = 'GATE_RELEASE';
    const MSG_SLOT_UPDATE  = 'SLOT_UPDATE';
    const MSG_CANCEL       = 'CANCEL';
    const MSG_INFO         = 'INFO';

    // Delivery channel constants
    const CHANNEL_CPDLC   = 'cpdlc';
    const CHANNEL_VPILOT  = 'vpilot';
    const CHANNEL_WEB     = 'web';
    const CHANNEL_DISCORD = 'discord';

    // VATSIM CDM tolerance (relaxed from EUROCONTROL -5/+10)
    const TOLERANCE_EARLY_MIN = -5;
    const TOLERANCE_LATE_MIN  = 15;

    /**
     * @param resource $conn_tmi  sqlsrv connection to VATSIM_TMI
     * @param resource $conn_adl  sqlsrv connection to VATSIM_ADL
     * @param bool $verbose       Enable debug logging
     */
    public function __construct($conn_tmi, $conn_adl, bool $verbose = false)
    {
        $this->conn_tmi = $conn_tmi;
        $this->conn_adl = $conn_adl;
        $this->is_hibernation = defined('HIBERNATION_MODE') && HIBERNATION_MODE;
        $this->verbose = $verbose;
    }

    // =========================================================================
    // PILOT READINESS TRACKING
    // =========================================================================

    /**
     * Update pilot readiness state.
     * Creates a new record and supersedes the previous one.
     *
     * @param int    $flight_uid
     * @param string $callsign
     * @param string $new_state    One of STATE_* constants
     * @param string $source       cpdlc, vpilot, web, simbrief, auto, controller
     * @param int|null $cid
     * @param string|null $reported_tobt  Pilot-reported TOBT (Y-m-d H:i:s)
     * @param string|null $dep_airport
     * @param string|null $arr_airport
     * @return int|false  readiness_id or false on error
     */
    public function updateReadiness(
        int $flight_uid,
        string $callsign,
        string $new_state,
        string $source,
        ?int $cid = null,
        ?string $reported_tobt = null,
        ?string $dep_airport = null,
        ?string $arr_airport = null
    ): int|false {
        // Validate state
        $valid_states = [self::STATE_PLANNING, self::STATE_BOARDING, self::STATE_READY, self::STATE_TAXIING, self::STATE_CANCELLED];
        if (!in_array($new_state, $valid_states)) {
            $this->log("Invalid readiness state: $new_state");
            return false;
        }

        // Compute TOBT if not provided and state is READY
        $computed_tobt = null;
        if ($new_state === self::STATE_READY && $reported_tobt === null) {
            $computed_tobt = gmdate('Y-m-d H:i:s'); // NOW = ready = TOBT
        }

        // Call the SP
        $readiness_id = 0;
        $sql = "EXEC dbo.sp_CDM_UpdateReadiness
            @flight_uid = ?,
            @callsign = ?,
            @cid = ?,
            @new_state = ?,
            @source = ?,
            @reported_tobt = ?,
            @computed_tobt = ?,
            @dep_airport = ?,
            @arr_airport = ?,
            @is_hibernation = ?,
            @readiness_id = ? OUTPUT";

        $params = [
            $flight_uid,
            $callsign,
            $cid,
            $new_state,
            $source,
            $reported_tobt,
            $computed_tobt,
            $dep_airport,
            $arr_airport,
            $this->is_hibernation ? 1 : 0,
            [&$readiness_id, SQLSRV_PARAM_OUT, SQLSRV_PHPTYPE_INT]
        ];

        $stmt = sqlsrv_query($this->conn_tmi, $sql, $params);
        if ($stmt === false) {
            $this->logSqlError('updateReadiness');
            return false;
        }
        sqlsrv_free_stmt($stmt);

        // Also cache state on adl_flight_times for fast joins
        if ($readiness_id > 0) {
            $this->cacheReadinessOnADL($flight_uid, $new_state, $computed_tobt ?? $reported_tobt);
        }

        $this->log("Readiness updated: $callsign → $new_state (id=$readiness_id, src=$source)");
        return $readiness_id;
    }

    /**
     * Get current readiness for a flight.
     */
    public function getReadiness(int $flight_uid): ?array
    {
        $sql = "SELECT TOP 1 * FROM dbo.vw_cdm_current_readiness WHERE flight_uid = ?";
        $stmt = sqlsrv_query($this->conn_tmi, $sql, [$flight_uid]);
        if ($stmt === false) return null;

        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($stmt);
        return $row ?: null;
    }

    /**
     * Get readiness summary for an airport's departures.
     */
    public function getAirportReadiness(string $airport_icao): array
    {
        $sql = "SELECT readiness_state, COUNT(*) AS cnt
                FROM dbo.vw_cdm_current_readiness
                WHERE dep_airport = ?
                GROUP BY readiness_state";
        $stmt = sqlsrv_query($this->conn_tmi, $sql, [$airport_icao]);
        if ($stmt === false) return [];

        $result = [];
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $result[$row['readiness_state']] = (int)$row['cnt'];
        }
        sqlsrv_free_stmt($stmt);
        return $result;
    }

    /**
     * Auto-detect readiness state from ADL flight data.
     * Called during ADL ingest cycle for flights at airports.
     */
    public function autoDetectReadiness(array $flight_data): ?string
    {
        $phase = $flight_data['phase'] ?? $flight_data['flight_phase'] ?? '';
        $groundspeed = (int)($flight_data['groundspeed_kts'] ?? 0);
        $altitude = (int)($flight_data['altitude_ft'] ?? 0);
        $is_active = (bool)($flight_data['is_active'] ?? false);

        // Disconnected = CANCELLED
        if (!$is_active) {
            return self::STATE_CANCELLED;
        }

        // On ground, moving = TAXIING
        if ($altitude < 500 && $groundspeed > 5 && $groundspeed < 80) {
            return self::STATE_TAXIING;
        }

        // On ground, stationary at airport = BOARDING
        if ($altitude < 500 && $groundspeed <= 5) {
            return self::STATE_BOARDING;
        }

        // Pre-filed, not connected = PLANNING
        if ($phase === 'PREFILED' || $phase === 'prefile') {
            return self::STATE_PLANNING;
        }

        return null;
    }

    // =========================================================================
    // TSAT / TTOT ENGINE (A-CDM Milestone Computation)
    // =========================================================================

    /**
     * Compute TSAT and TTOT for a flight.
     *
     * TSAT = max(TOBT, EDCT - taxi_time)
     * TTOT = TSAT + taxi_time
     *
     * Uses airport_taxi_reference for unimpeded taxi time.
     *
     * @param int    $flight_uid
     * @param string $dep_airport     ICAO code
     * @param string|null $tobt_utc   Target Off-Block Time (Y-m-d H:i:s)
     * @param string|null $edct_utc   EDCT if under GDP (Y-m-d H:i:s)
     * @return array{tsat_utc: string|null, ttot_utc: string|null, gate_hold: bool, taxi_time_sec: int}
     */
    public function computeMilestones(
        int $flight_uid,
        string $dep_airport,
        ?string $tobt_utc = null,
        ?string $edct_utc = null
    ): array {
        $result = [
            'tsat_utc' => null,
            'ttot_utc' => null,
            'gate_hold' => false,
            'gate_hold_minutes' => 0,
            'taxi_time_sec' => 600, // Default 10 min
            'tsat_source' => 'calculated'
        ];

        // Get unimpeded taxi time from airport_taxi_reference
        $taxi_sql = "SELECT unimpeded_taxi_sec FROM dbo.airport_taxi_reference WHERE airport_icao = ?";
        $taxi_stmt = sqlsrv_query($this->conn_adl, $taxi_sql, [$dep_airport]);
        if ($taxi_stmt !== false) {
            $taxi_row = sqlsrv_fetch_array($taxi_stmt, SQLSRV_FETCH_ASSOC);
            if ($taxi_row) {
                $result['taxi_time_sec'] = (int)$taxi_row['unimpeded_taxi_sec'];
            }
            sqlsrv_free_stmt($taxi_stmt);
        }

        $taxi_minutes = $result['taxi_time_sec'] / 60.0;

        // If no TOBT, can't compute
        if ($tobt_utc === null) {
            return $result;
        }

        $tobt_ts = strtotime($tobt_utc);
        if ($tobt_ts === false) return $result;

        if ($edct_utc !== null) {
            $edct_ts = strtotime($edct_utc);
            if ($edct_ts !== false) {
                // TSAT = max(TOBT, EDCT - taxi_time)
                $edct_minus_taxi = $edct_ts - $result['taxi_time_sec'];
                $tsat_ts = max($tobt_ts, $edct_minus_taxi);

                // Gate hold if there's a significant wait
                $hold_seconds = $tsat_ts - $tobt_ts;
                if ($hold_seconds > 300) { // >5 min hold
                    $result['gate_hold'] = true;
                    $result['gate_hold_minutes'] = round($hold_seconds / 60);
                }
            } else {
                $tsat_ts = $tobt_ts;
            }
        } else {
            // No EDCT — TSAT = TOBT (no delay management)
            $tsat_ts = $tobt_ts;
        }

        $result['tsat_utc'] = gmdate('Y-m-d H:i:s', $tsat_ts);
        $result['ttot_utc'] = gmdate('Y-m-d H:i:s', $tsat_ts + $result['taxi_time_sec']);

        return $result;
    }

    /**
     * Save computed milestones to adl_flight_times.
     */
    public function saveMilestones(int $flight_uid, array $milestones): bool
    {
        $sql = "UPDATE dbo.adl_flight_times SET
                    tsat_utc = ?,
                    ttot_utc = ?,
                    tsat_source = ?,
                    gate_hold_active = ?
                WHERE flight_uid = ?";

        $params = [
            $milestones['tsat_utc'],
            $milestones['ttot_utc'],
            $milestones['tsat_source'] ?? 'calculated',
            $milestones['gate_hold'] ? 1 : 0,
            $flight_uid
        ];

        $stmt = sqlsrv_query($this->conn_adl, $sql, $params);
        if ($stmt === false) {
            $this->logSqlError('saveMilestones');
            return false;
        }
        sqlsrv_free_stmt($stmt);
        return true;
    }

    // =========================================================================
    // CDM MESSAGE QUEUE
    // =========================================================================

    /**
     * Queue a CDM message for delivery to a pilot.
     *
     * @return int|false message_id or false
     */
    public function queueMessage(
        int $flight_uid,
        string $callsign,
        string $message_type,
        string $message_body,
        string $channel,
        ?int $cid = null,
        ?int $program_id = null,
        ?int $slot_id = null,
        int $expires_minutes = 120
    ): int|false {
        $message_id = 0;
        $sql = "EXEC dbo.sp_CDM_QueueMessage
            @flight_uid = ?,
            @callsign = ?,
            @cid = ?,
            @message_type = ?,
            @message_body = ?,
            @channel = ?,
            @program_id = ?,
            @slot_id = ?,
            @expires_minutes = ?,
            @is_hibernation = ?,
            @message_id = ? OUTPUT";

        $params = [
            $flight_uid,
            $callsign,
            $cid,
            $message_type,
            $message_body,
            $channel,
            $program_id,
            $slot_id,
            $expires_minutes,
            $this->is_hibernation ? 1 : 0,
            [&$message_id, SQLSRV_PARAM_OUT, SQLSRV_PHPTYPE_INT]
        ];

        $stmt = sqlsrv_query($this->conn_tmi, $sql, $params);
        if ($stmt === false) {
            $this->logSqlError('queueMessage');
            return false;
        }
        sqlsrv_free_stmt($stmt);

        $this->log("Message queued: $callsign $message_type via $channel (id=$message_id)");
        return $message_id;
    }

    /**
     * Record message delivery acknowledgment.
     */
    public function recordAck(int $message_id, string $ack_type, ?string $ack_reason = null, ?string $ack_channel = null): bool
    {
        $sql = "UPDATE dbo.cdm_messages SET
                    ack_type = ?,
                    ack_reason = ?,
                    ack_channel = ?,
                    ack_utc = GETUTCDATE(),
                    delivery_status = 'DELIVERED'
                WHERE message_id = ?";

        $stmt = sqlsrv_query($this->conn_tmi, $sql, [$ack_type, $ack_reason, $ack_channel, $message_id]);
        if ($stmt === false) {
            $this->logSqlError('recordAck');
            return false;
        }
        sqlsrv_free_stmt($stmt);

        $this->log("ACK recorded: msg=$message_id type=$ack_type");
        return true;
    }

    /**
     * Get pending messages for delivery.
     */
    public function getPendingMessages(string $channel = null, int $limit = 50): array
    {
        $sql = "SELECT TOP (?) * FROM dbo.vw_cdm_pending_messages";
        $params = [$limit];

        if ($channel) {
            $sql .= " WHERE delivery_channel = ?";
            $params[] = $channel;
        }

        $sql .= " ORDER BY created_utc ASC";

        $stmt = sqlsrv_query($this->conn_tmi, $sql, $params);
        if ($stmt === false) return [];

        $messages = [];
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $messages[] = $row;
        }
        sqlsrv_free_stmt($stmt);
        return $messages;
    }

    /**
     * Mark message as sent.
     */
    public function markSent(int $message_id): bool
    {
        $sql = "UPDATE dbo.cdm_messages SET
                    delivery_status = 'SENT',
                    sent_utc = GETUTCDATE(),
                    delivery_attempts = delivery_attempts + 1,
                    last_attempt_utc = GETUTCDATE()
                WHERE message_id = ?";

        $stmt = sqlsrv_query($this->conn_tmi, $sql, [$message_id]);
        if ($stmt === false) return false;
        sqlsrv_free_stmt($stmt);
        return true;
    }

    /**
     * Mark message delivery as failed.
     */
    public function markFailed(int $message_id): bool
    {
        $sql = "UPDATE dbo.cdm_messages SET
                    delivery_attempts = delivery_attempts + 1,
                    last_attempt_utc = GETUTCDATE(),
                    delivery_status = CASE
                        WHEN delivery_attempts + 1 >= max_retries THEN 'FAILED'
                        ELSE 'PENDING'
                    END
                WHERE message_id = ?";

        $stmt = sqlsrv_query($this->conn_tmi, $sql, [$message_id]);
        if ($stmt === false) return false;
        sqlsrv_free_stmt($stmt);
        return true;
    }

    // =========================================================================
    // CDM STATUS (Pilot-facing)
    // =========================================================================

    /**
     * Get full CDM status for a flight (pilot dashboard data).
     */
    public function getFlightCDMStatus(int $flight_uid): ?array
    {
        // Get readiness
        $readiness = $this->getReadiness($flight_uid);

        // Get milestone times from adl_flight_times
        $times_sql = "SELECT tobt_utc, tsat_utc, ttot_utc, edct_utc,
                             gate_hold_active, gate_hold_issued_utc, gate_hold_released_utc,
                             cdm_readiness_state, eta_utc, out_utc, off_utc
                      FROM dbo.adl_flight_times WHERE flight_uid = ?";
        $times_stmt = sqlsrv_query($this->conn_adl, $times_sql, [$flight_uid]);
        $times = null;
        if ($times_stmt !== false) {
            $times = sqlsrv_fetch_array($times_stmt, SQLSRV_FETCH_ASSOC);
            sqlsrv_free_stmt($times_stmt);
        }

        // Get active TMI control
        $tmi_sql = "SELECT fc.*, p.program_type, p.program_name, p.ctl_element
                    FROM dbo.tmi_flight_control fc
                    JOIN dbo.tmi_programs p ON fc.program_id = p.program_id
                    WHERE fc.flight_uid = ? AND p.is_active = 1";
        $tmi_stmt = sqlsrv_query($this->conn_tmi, $tmi_sql, [$flight_uid]);
        $tmi_control = null;
        if ($tmi_stmt !== false) {
            $tmi_control = sqlsrv_fetch_array($tmi_stmt, SQLSRV_FETCH_ASSOC);
            sqlsrv_free_stmt($tmi_stmt);
        }

        // Get latest compliance status
        $compliance_sql = "SELECT compliance_type, compliance_status, risk_level, delta_minutes
                          FROM dbo.cdm_compliance_live
                          WHERE flight_uid = ? AND is_final = 0
                          ORDER BY evaluated_utc DESC";
        $comp_stmt = sqlsrv_query($this->conn_tmi, $compliance_sql, [$flight_uid]);
        $compliance = [];
        if ($comp_stmt !== false) {
            while ($row = sqlsrv_fetch_array($comp_stmt, SQLSRV_FETCH_ASSOC)) {
                $compliance[] = $row;
            }
            sqlsrv_free_stmt($comp_stmt);
        }

        // Get pending/recent messages
        $msg_sql = "SELECT TOP 5 message_type, message_body, delivery_status, ack_type, sent_utc, ack_utc
                    FROM dbo.cdm_messages
                    WHERE flight_uid = ?
                    ORDER BY created_utc DESC";
        $msg_stmt = sqlsrv_query($this->conn_tmi, $msg_sql, [$flight_uid]);
        $messages = [];
        if ($msg_stmt !== false) {
            while ($row = sqlsrv_fetch_array($msg_stmt, SQLSRV_FETCH_ASSOC)) {
                $messages[] = $row;
            }
            sqlsrv_free_stmt($msg_stmt);
        }

        // Compute delay estimate
        $delay_minutes = null;
        if ($tmi_control && isset($tmi_control['program_delay_min'])) {
            $delay_minutes = (int)$tmi_control['program_delay_min'];
        }

        return [
            'flight_uid' => $flight_uid,
            'readiness' => $readiness,
            'times' => $times ? [
                'tobt_utc' => $this->formatDateTime($times['tobt_utc']),
                'tsat_utc' => $this->formatDateTime($times['tsat_utc']),
                'ttot_utc' => $this->formatDateTime($times['ttot_utc']),
                'edct_utc' => $this->formatDateTime($times['edct_utc']),
                'eta_utc'  => $this->formatDateTime($times['eta_utc']),
                'gate_hold_active' => (bool)($times['gate_hold_active'] ?? false),
            ] : null,
            'tmi_control' => $tmi_control ? [
                'program_type' => $tmi_control['program_type'],
                'program_name' => $tmi_control['program_name'],
                'ctl_element'  => $tmi_control['ctl_element'],
                'edct_utc'     => $this->formatDateTime($tmi_control['ctd_utc'] ?? null),
                'delay_minutes' => $delay_minutes,
            ] : null,
            'compliance' => $compliance,
            'messages' => $messages,
            'is_hibernation' => $this->is_hibernation
        ];
    }

    // =========================================================================
    // COMPLIANCE EVALUATION
    // =========================================================================

    /**
     * Evaluate EDCT compliance for a flight.
     * Called on each ADL 15s cycle for controlled flights.
     */
    public function evaluateEDCTCompliance(
        int $flight_uid,
        string $callsign,
        int $program_id,
        ?int $slot_id,
        string $edct_utc,
        ?string $actual_off_utc = null,
        ?string $ttot_utc = null
    ): void {
        $edct_ts = strtotime($edct_utc);
        if ($edct_ts === false) return;

        $delta = null;
        $actual = null;
        $is_final = false;

        if ($actual_off_utc !== null) {
            // Post-departure: final assessment
            $actual_ts = strtotime($actual_off_utc);
            if ($actual_ts !== false) {
                $delta = ($actual_ts - $edct_ts) / 60.0;
                $actual = $actual_off_utc;
                $is_final = true;
            }
        } elseif ($ttot_utc !== null) {
            // Pre-departure: assess risk based on TTOT vs EDCT
            $ttot_ts = strtotime($ttot_utc);
            if ($ttot_ts !== false) {
                $delta = ($ttot_ts - $edct_ts) / 60.0;
                $actual = "TTOT: $ttot_utc";
            }
        }

        // Call compliance SP
        $sql = "EXEC dbo.sp_CDM_EvaluateCompliance
            @flight_uid = ?,
            @callsign = ?,
            @program_id = ?,
            @slot_id = ?,
            @compliance_type = 'EDCT',
            @expected_value = ?,
            @actual_value = ?,
            @delta_minutes = ?,
            @is_final = ?,
            @is_hibernation = ?";

        $params = [
            $flight_uid, $callsign, $program_id, $slot_id,
            $edct_utc, $actual, $delta, $is_final ? 1 : 0,
            $this->is_hibernation ? 1 : 0
        ];

        $stmt = sqlsrv_query($this->conn_tmi, $sql, $params);
        if ($stmt !== false) {
            sqlsrv_free_stmt($stmt);
        }
    }

    /**
     * Get compliance summary for a program.
     */
    public function getProgramCompliance(int $program_id): array
    {
        $sql = "SELECT
                    compliance_status,
                    COUNT(*) AS cnt,
                    AVG(delta_minutes) AS avg_delta
                FROM dbo.cdm_compliance_live
                WHERE program_id = ? AND is_final = 1
                GROUP BY compliance_status";

        $stmt = sqlsrv_query($this->conn_tmi, $sql, [$program_id]);
        if ($stmt === false) return [];

        $result = [];
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $result[$row['compliance_status']] = [
                'count' => (int)$row['cnt'],
                'avg_delta_min' => round((float)$row['avg_delta'], 1)
            ];
        }
        sqlsrv_free_stmt($stmt);
        return $result;
    }

    // =========================================================================
    // AIRPORT STATUS
    // =========================================================================

    /**
     * Get latest airport CDM status snapshot.
     */
    public function getAirportStatus(string $airport_icao): ?array
    {
        $sql = "SELECT TOP 1 * FROM dbo.cdm_airport_status
                WHERE airport_icao = ?
                ORDER BY snapshot_utc DESC";
        $stmt = sqlsrv_query($this->conn_tmi, $sql, [$airport_icao]);
        if ($stmt === false) return null;

        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($stmt);
        return $row ?: null;
    }

    // =========================================================================
    // HIBERNATION POST-PROCESSING
    // =========================================================================

    /**
     * Process data queued during hibernation.
     * Called when HIBERNATION_MODE transitions from true to false.
     */
    public function processHibernationQueue(): array
    {
        $sql = "EXEC dbo.sp_CDM_ProcessHibernationQueue";
        $stmt = sqlsrv_query($this->conn_tmi, $sql);
        if ($stmt === false) {
            $this->logSqlError('processHibernationQueue');
            return ['error' => 'Failed to process hibernation queue'];
        }

        // Read print output for counts
        $result = [];
        while (sqlsrv_next_result($stmt)) {
            // SP uses PRINT statements — consume all result sets
        }
        sqlsrv_free_stmt($stmt);

        $this->log("Hibernation queue post-processing complete");
        return ['status' => 'complete'];
    }

    // =========================================================================
    // CDM METRICS
    // =========================================================================

    /**
     * Get CDM effectiveness metrics for a program.
     */
    public function getMetrics(?int $program_id = null): array
    {
        $metrics = [
            'delivery' => $this->getDeliveryMetrics($program_id),
            'compliance' => $program_id ? $this->getProgramCompliance($program_id) : [],
            'readiness' => $this->getReadinessMetrics(),
        ];

        return $metrics;
    }

    private function getDeliveryMetrics(?int $program_id): array
    {
        $where = $program_id ? "WHERE program_id = ?" : "";
        $params = $program_id ? [$program_id] : [];

        $sql = "SELECT
                    COUNT(*) AS total_messages,
                    SUM(CASE WHEN delivery_status = 'DELIVERED' THEN 1 ELSE 0 END) AS delivered,
                    SUM(CASE WHEN delivery_status = 'SENT' THEN 1 ELSE 0 END) AS sent_unacked,
                    SUM(CASE WHEN delivery_status = 'FAILED' THEN 1 ELSE 0 END) AS failed,
                    SUM(CASE WHEN ack_type = 'WILCO' THEN 1 ELSE 0 END) AS wilco_count,
                    SUM(CASE WHEN ack_type = 'UNABLE' THEN 1 ELSE 0 END) AS unable_count,
                    SUM(CASE WHEN ack_type = 'ROGER' THEN 1 ELSE 0 END) AS roger_count,
                    SUM(CASE WHEN ack_type = 'STANDBY' THEN 1 ELSE 0 END) AS standby_count,
                    AVG(DATEDIFF(SECOND, sent_utc, ack_utc)) AS avg_ack_time_sec
                FROM dbo.cdm_messages $where";

        $stmt = sqlsrv_query($this->conn_tmi, $sql, $params);
        if ($stmt === false) return [];

        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($stmt);
        return $row ?: [];
    }

    private function getReadinessMetrics(): array
    {
        $sql = "SELECT
                    source,
                    readiness_state,
                    COUNT(*) AS cnt
                FROM dbo.cdm_pilot_readiness
                WHERE reported_utc > DATEADD(HOUR, -24, GETUTCDATE())
                GROUP BY source, readiness_state
                ORDER BY source, readiness_state";

        $stmt = sqlsrv_query($this->conn_tmi, $sql);
        if ($stmt === false) return [];

        $result = [];
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $result[] = $row;
        }
        sqlsrv_free_stmt($stmt);
        return $result;
    }

    // =========================================================================
    // INTERNAL HELPERS
    // =========================================================================

    /**
     * Cache readiness state on adl_flight_times for fast joins.
     */
    private function cacheReadinessOnADL(int $flight_uid, string $state, ?string $tobt_utc): void
    {
        $sql = "UPDATE dbo.adl_flight_times SET
                    cdm_readiness_state = ?,
                    tobt_utc = COALESCE(?, tobt_utc),
                    tobt_source = CASE WHEN ? IS NOT NULL THEN 'auto' ELSE tobt_source END
                WHERE flight_uid = ?";

        $stmt = sqlsrv_query($this->conn_adl, $sql, [$state, $tobt_utc, $tobt_utc, $flight_uid]);
        if ($stmt !== false) {
            sqlsrv_free_stmt($stmt);
        }
    }

    /**
     * Format DateTime objects from sqlsrv to string.
     */
    private function formatDateTime($dt): ?string
    {
        if ($dt === null) return null;
        if ($dt instanceof \DateTime) {
            return $dt->format('Y-m-d H:i:s');
        }
        return (string)$dt;
    }

    private function log(string $msg): void
    {
        if ($this->verbose) {
            error_log("[CDM] $msg");
        }
    }

    private function logSqlError(string $context): void
    {
        $errors = sqlsrv_errors();
        error_log("[CDM] $context SQL error: " . json_encode($errors));
    }
}
