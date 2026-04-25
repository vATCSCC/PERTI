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

    // Per-request caches (session-level data that doesn't change across tracks)
    private array $destRateCache = [];     // keyed by "sessionId:airport" → max_acph
    private array $firListCache = [];      // keyed by sessionId → [fir => max_acph]
    private array $fixRateCache = [];      // keyed by "sessionId:fix" → max_acph
    private ?array $ecfmpCache = null;     // cached ECFMP result for dest
    private ?string $ecfmpCacheDest = null;

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

        $firAdvisories = $this->checkFIRCapacity($sessionId, $timing['oep_utc'] ?? '', $timing['exit_utc'] ?? '');
        if ($firAdvisories) $advisories = array_merge($advisories, $firAdvisories);

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

        // Cache the constraint lookup (session-level, doesn't change across tracks)
        $cacheKey = "$sessionId:$airport";
        if (array_key_exists($cacheKey, $this->destRateCache)) {
            $maxAcph = $this->destRateCache[$cacheKey];
        } else {
            $stmt = sqlsrv_query($this->conn_tmi,
                "SELECT max_acph FROM dbo.ctp_facility_constraints
                 WHERE session_id = ? AND facility_name = ? AND facility_type = 'airport'",
                [$sessionId, $airport]
            );
            if (!$stmt) return null;
            $constraint = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
            sqlsrv_free_stmt($stmt);
            if (!$constraint) {
                $this->destRateCache[$cacheKey] = null;
                return null;
            }
            $maxAcph = (int)$constraint['max_acph'];
            $this->destRateCache[$cacheKey] = $maxAcph;
        }
        if ($maxAcph === null) return null;

        // Count assigned flights arriving at same destination within +/-30min of candidate CTA
        // JOIN tmi_flight_control for actual CTA (populated by CTOTCascade)
        // Uses BETWEEN for SARGable index seeks on tc.cta_utc
        $stmt = sqlsrv_query($this->conn_tmi,
            "SELECT COUNT(*) AS cnt
             FROM dbo.ctp_flight_control fc
             JOIN dbo.tmi_flight_control tc ON tc.flight_uid = fc.flight_uid
             WHERE fc.session_id = ? AND fc.arr_airport = ?
               AND fc.slot_status IN ('ASSIGNED','FROZEN')
               AND tc.cta_utc IS NOT NULL
               AND tc.cta_utc BETWEEN DATEADD(MINUTE, -30, ?) AND DATEADD(MINUTE, 30, ?)",
            [$sessionId, $airport, $ctaUtc, $ctaUtc]
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
     * Returns array of advisories (0-N) for all FIRs exceeding their limit.
     */
    public function checkFIRCapacity(int $sessionId, string $oepUtc, string $exitUtc): array
    {
        if (!$oepUtc || !$exitUtc) return [];

        // Cache FIR constraint list (session-level, doesn't change across tracks)
        if (array_key_exists($sessionId, $this->firListCache)) {
            $firs = $this->firListCache[$sessionId];
        } else {
            $stmt = sqlsrv_query($this->conn_tmi,
                "SELECT facility_name, max_acph FROM dbo.ctp_facility_constraints
                 WHERE session_id = ? AND facility_type = 'fir'",
                [$sessionId]
            );
            if (!$stmt) return [];
            $firs = [];
            while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
                $firs[$row['facility_name']] = (int)$row['max_acph'];
            }
            sqlsrv_free_stmt($stmt);
            $this->firListCache[$sessionId] = $firs;
        }
        if (empty($firs)) return [];

        // Batch: single GROUP BY query for all FIRs instead of N individual queries
        $firNames = array_keys($firs);
        $n = count($firNames);
        $entryPlaceholders = implode(',', array_fill(0, $n, '?'));
        $exitPlaceholders = implode(',', array_fill(0, $n, '?'));

        // Params order matches SQL: entry FIR IN(?...), exit FIR IN(?...), sessionId, exitUtc, oepUtc
        $params = array_merge($firNames, $firNames, [$sessionId, $exitUtc, $oepUtc]);

        $stmt = sqlsrv_query($this->conn_tmi,
            "SELECT fir_name, COUNT(*) AS cnt
             FROM (
                 SELECT CASE
                     WHEN fc.oceanic_entry_fir IN ($entryPlaceholders) THEN fc.oceanic_entry_fir
                     WHEN fc.oceanic_exit_fir IN ($exitPlaceholders) THEN fc.oceanic_exit_fir
                 END AS fir_name
                 FROM dbo.ctp_flight_control fc
                 LEFT JOIN dbo.ctp_session_tracks st
                     ON st.session_id = fc.session_id AND st.track_name = fc.assigned_nat_track
                 WHERE fc.session_id = ? AND fc.slot_status IN ('ASSIGNED','FROZEN')
                   AND fc.oceanic_entry_utc IS NOT NULL
                   AND fc.oceanic_entry_utc <= DATEADD(MINUTE, 30, ?)
                   AND COALESCE(
                       fc.oceanic_exit_utc,
                       DATEADD(SECOND, CAST(ISNULL(st.route_distance_nm, 1800) / 480.0 * 3600 AS INT), fc.oceanic_entry_utc)
                   ) >= DATEADD(MINUTE, -30, ?)
             ) sub
             WHERE fir_name IS NOT NULL
             GROUP BY fir_name",
            $params
        );

        $advisories = [];
        if ($stmt) {
            while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
                $fir = $row['fir_name'];
                $current = (int)$row['cnt'];
                $maxAcph = $firs[$fir] ?? 0;
                if ($maxAcph > 0 && $current >= $maxAcph) {
                    $advisories[] = [
                        'type' => 'FIR_CAPACITY',
                        'facility' => $fir,
                        'detail' => "$current/$maxAcph flights in FIR",
                        'severity' => 'WARN',
                        'current' => $current,
                        'limit' => $maxAcph,
                    ];
                }
            }
            sqlsrv_free_stmt($stmt);
        }
        return $advisories;
    }

    /**
     * Check fix throughput.
     * Count flights using a constrained fix in the same hourly window.
     */
    public function checkFixThroughput(int $sessionId, string $fix, string $transitUtc): ?array
    {
        if (!$fix || !$transitUtc) return null;

        // Cache the fix constraint lookup (session-level)
        $cacheKey = "$sessionId:$fix";
        if (array_key_exists($cacheKey, $this->fixRateCache)) {
            $maxAcph = $this->fixRateCache[$cacheKey];
        } else {
            $stmt = sqlsrv_query($this->conn_tmi,
                "SELECT max_acph FROM dbo.ctp_facility_constraints
                 WHERE session_id = ? AND facility_name = ? AND facility_type = 'fix'",
                [$sessionId, $fix]
            );
            if (!$stmt) return null;
            $constraint = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
            sqlsrv_free_stmt($stmt);
            if (!$constraint) {
                $this->fixRateCache[$cacheKey] = null;
                return null;
            }
            $maxAcph = (int)$constraint['max_acph'];
            $this->fixRateCache[$cacheKey] = $maxAcph;
        }
        if ($maxAcph === null) return null;

        // Uses BETWEEN for SARGable index seeks on oceanic_entry_utc
        $stmt = sqlsrv_query($this->conn_tmi,
            "SELECT COUNT(*) AS cnt FROM dbo.ctp_flight_control
             WHERE session_id = ? AND slot_status IN ('ASSIGNED','FROZEN')
               AND (oceanic_entry_fix = ? OR oceanic_exit_fix = ?)
               AND oceanic_entry_utc IS NOT NULL
               AND oceanic_entry_utc BETWEEN DATEADD(MINUTE, -30, ?) AND DATEADD(MINUTE, 30, ?)",
            [$sessionId, $fix, $fix, $transitUtc, $transitUtc]
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

        // Cache ECFMP result per destination (only one dest per request)
        if ($this->ecfmpCacheDest === $dest) {
            return $this->ecfmpCache;
        }

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

        $this->ecfmpCacheDest = $dest;

        if ($row) {
            $this->ecfmpCache = [
                'type' => 'ECFMP',
                'facility' => $row['ident'],
                'detail' => 'Active ECFMP regulation: ' . ($row['reason'] ?? $row['ident']),
                'severity' => 'WARN',
            ];
            return $this->ecfmpCache;
        }
        $this->ecfmpCache = null;
        return null;
    }
}
