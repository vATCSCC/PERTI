<?php
/**
 * CTPConstraintAdvisor — Multi-constraint advisory checker for CTP slot assignment.
 *
 * All constraints are advisory (WARN severity, never block). The advisor evaluates
 * 6 constraint types ordered cheapest-first and returns a list of advisories.
 *
 * Checks:
 *   1. Destination arrival rate (ctp_facility_constraints type='airport')
 *   2. FIR capacity (ctp_facility_constraints type='fir')
 *   3. Fix throughput (ctp_facility_constraints type='fix')
 *   4. Sector capacity (ctp_facility_constraints type='sector')
 *   5. ECFMP regulations (tmi_flow_measures)
 */

namespace PERTI\Services;

class CTPConstraintAdvisor
{
    private $conn_tmi;

    public function __construct($conn_tmi)
    {
        $this->conn_tmi = $conn_tmi;
    }

    /**
     * Evaluate all constraints for a candidate slot assignment.
     *
     * @param int    $sessionId CTP session ID
     * @param string $dest      Destination ICAO code
     * @param array  $timing    Timing chain with keys: oep_utc, exit_utc, cta_utc
     * @param array  $track     Track info with keys: oceanic_entry_fix, oceanic_exit_fix, track_name
     * @return array            List of advisory objects [{type, facility, detail, severity, current?, limit?}]
     */
    public function evaluate(int $sessionId, string $dest, array $timing, array $track): array
    {
        $advisories = [];

        $check = $this->checkDestRate($sessionId, $dest, $timing['cta_utc'] ?? '');
        if ($check) $advisories[] = $check;

        $check = $this->checkFIRCapacity($sessionId, $timing['oep_utc'] ?? '', $timing['exit_utc'] ?? '');
        if ($check) $advisories[] = $check;

        $entryFix = $track['oceanic_entry_fix'] ?? '';
        if ($entryFix) {
            $check = $this->checkFixThroughput($sessionId, $entryFix, $timing['oep_utc'] ?? '');
            if ($check) $advisories[] = $check;
        }

        $exitFix = $track['oceanic_exit_fix'] ?? '';
        if ($exitFix && $exitFix !== $entryFix) {
            $check = $this->checkFixThroughput($sessionId, $exitFix, $timing['exit_utc'] ?? '');
            if ($check) $advisories[] = $check;
        }

        $check = $this->checkSectorCapacity($sessionId, $timing['oep_utc'] ?? '', $timing['exit_utc'] ?? '');
        if ($check) $advisories[] = $check;

        $check = $this->checkECFMP($dest);
        if ($check) $advisories[] = $check;

        return $advisories;
    }

    /**
     * Check destination arrival rate.
     * Count assigned flights arriving at the same destination within +/-30min of candidate CTA.
     */
    public function checkDestRate(int $sessionId, string $airport, string $ctaUtc): ?array
    {
        if (!$airport || !$ctaUtc) return null;

        $stmt = sqlsrv_query($this->conn_tmi,
            "SELECT max_acph FROM dbo.ctp_facility_constraints
             WHERE session_id = ? AND facility_name = ? AND facility_type = 'airport'",
            [$sessionId, $airport]
        );
        if (!$stmt) return null;
        $constraint = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($stmt);
        if (!$constraint) return null;

        $maxAcph = (int)$constraint['max_acph'];

        // Count assigned flights arriving at same destination within +/-30min of candidate CTA
        // JOIN tmi_flight_control for actual CTA (populated by CTOTCascade)
        $stmt = sqlsrv_query($this->conn_tmi,
            "SELECT COUNT(*) AS cnt
             FROM dbo.ctp_flight_control fc
             JOIN dbo.tmi_flight_control tc ON tc.flight_uid = fc.flight_uid
             WHERE fc.session_id = ? AND fc.arr_airport = ?
               AND fc.slot_status IN ('ASSIGNED','FROZEN')
               AND tc.cta_utc IS NOT NULL
               AND ABS(DATEDIFF(MINUTE, tc.cta_utc, ?)) <= 30",
            [$sessionId, $airport, $ctaUtc]
        );
        if (!$stmt) return null;
        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($stmt);
        $current = $row ? (int)$row['cnt'] : 0;

        if ($current >= $maxAcph) {
            return [
                'type' => 'DEST_RATE',
                'facility' => $airport,
                'detail' => "$current/$maxAcph arrivals per hour",
                'severity' => 'WARN',
                'current' => $current,
                'limit' => $maxAcph,
            ];
        }
        return null;
    }

    /**
     * Check FIR capacity.
     * Count flights crossing each constrained FIR in the same hourly window.
     */
    public function checkFIRCapacity(int $sessionId, string $oepUtc, string $exitUtc): ?array
    {
        if (!$oepUtc || !$exitUtc) return null;

        $stmt = sqlsrv_query($this->conn_tmi,
            "SELECT facility_name, max_acph FROM dbo.ctp_facility_constraints
             WHERE session_id = ? AND facility_type = 'fir'",
            [$sessionId]
        );
        if (!$stmt) return null;

        $firs = [];
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $firs[$row['facility_name']] = (int)$row['max_acph'];
        }
        sqlsrv_free_stmt($stmt);
        if (empty($firs)) return null;

        foreach ($firs as $fir => $maxAcph) {
            // JOIN ctp_session_tracks to compute exit time from track distance when oceanic_exit_utc is NULL
            $stmt = sqlsrv_query($this->conn_tmi,
                "SELECT COUNT(*) AS cnt
                 FROM dbo.ctp_flight_control fc
                 LEFT JOIN dbo.ctp_session_tracks st
                    ON st.session_id = fc.session_id AND st.track_name = fc.assigned_nat_track
                 WHERE fc.session_id = ? AND fc.slot_status IN ('ASSIGNED','FROZEN')
                   AND (fc.oceanic_entry_fir = ? OR fc.oceanic_exit_fir = ?)
                   AND fc.oceanic_entry_utc IS NOT NULL
                   AND fc.oceanic_entry_utc <= DATEADD(MINUTE, 30, ?)
                   AND COALESCE(
                       fc.oceanic_exit_utc,
                       DATEADD(SECOND, CAST(ISNULL(st.route_distance_nm, 1800) / 480.0 * 3600 AS INT), fc.oceanic_entry_utc)
                   ) >= DATEADD(MINUTE, -30, ?)",
                [$sessionId, $fir, $fir, $exitUtc, $oepUtc]
            );
            if (!$stmt) continue;
            $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
            sqlsrv_free_stmt($stmt);
            $current = $row ? (int)$row['cnt'] : 0;

            if ($current >= $maxAcph) {
                return [
                    'type' => 'FIR_CAPACITY',
                    'facility' => $fir,
                    'detail' => "$current/$maxAcph flights in FIR",
                    'severity' => 'WARN',
                    'current' => $current,
                    'limit' => $maxAcph,
                ];
            }
        }
        return null;
    }

    /**
     * Check fix throughput.
     * Count flights using a constrained fix in the same hourly window.
     */
    public function checkFixThroughput(int $sessionId, string $fix, string $transitUtc): ?array
    {
        if (!$fix || !$transitUtc) return null;

        $stmt = sqlsrv_query($this->conn_tmi,
            "SELECT max_acph FROM dbo.ctp_facility_constraints
             WHERE session_id = ? AND facility_name = ? AND facility_type = 'fix'",
            [$sessionId, $fix]
        );
        if (!$stmt) return null;
        $constraint = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($stmt);
        if (!$constraint) return null;

        $maxAcph = (int)$constraint['max_acph'];

        $stmt = sqlsrv_query($this->conn_tmi,
            "SELECT COUNT(*) AS cnt FROM dbo.ctp_flight_control
             WHERE session_id = ? AND slot_status IN ('ASSIGNED','FROZEN')
               AND (oceanic_entry_fix = ? OR oceanic_exit_fix = ?)
               AND oceanic_entry_utc IS NOT NULL
               AND ABS(DATEDIFF(MINUTE, oceanic_entry_utc, ?)) <= 30",
            [$sessionId, $fix, $fix, $transitUtc]
        );
        if (!$stmt) return null;
        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($stmt);
        $current = $row ? (int)$row['cnt'] : 0;

        if ($current >= $maxAcph) {
            return [
                'type' => 'FIX_THROUGHPUT',
                'facility' => $fix,
                'detail' => "$current/$maxAcph flights at fix",
                'severity' => 'WARN',
                'current' => $current,
                'limit' => $maxAcph,
            ];
        }
        return null;
    }

    /**
     * Check sector capacity.
     * V1: Simplified — sector assignment not directly available on ctp_flight_control.
     * Returns null (no advisory). Full implementation requires cross-referencing
     * adl_flight_planned_crossings with sector boundaries.
     */
    public function checkSectorCapacity(int $sessionId, string $oepUtc, string $exitUtc): ?array
    {
        // V1: sector capacity check deferred — requires adl_flight_planned_crossings
        // cross-reference which is expensive and not yet integrated with CTP flow.
        return null;
    }

    /**
     * Check ECFMP regulations.
     * Look for active flow measures affecting the flight's destination or FIRs.
     */
    public function checkECFMP(string $dest): ?array
    {
        if (!$dest) return null;

        // tmi_flow_measures uses filters_json with "ades" array for destination airports
        $stmt = sqlsrv_query($this->conn_tmi,
            "SELECT TOP 1 measure_id, ident, reason, filters_json
             FROM dbo.tmi_flow_measures
             WHERE status = 'ACTIVE'
               AND end_utc > SYSUTCDATETIME()
               AND (
                   ctl_element = ?
                   OR filters_json LIKE '%\"' + ? + '\"%'
               )",
            [$dest, $dest]
        );
        if (!$stmt) return null;
        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($stmt);

        if ($row) {
            return [
                'type' => 'ECFMP',
                'facility' => $row['ident'],
                'detail' => 'Active ECFMP regulation: ' . ($row['reason'] ?? $row['ident']),
                'severity' => 'WARN',
            ];
        }
        return null;
    }
}
