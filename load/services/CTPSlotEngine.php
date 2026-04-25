<?php
/**
 * CTPSlotEngine — Core slot assignment engine for CTP oceanic events.
 *
 * Orchestrates slot generation, constraint evaluation, timing chain computation,
 * and the CTOT cascade. Uses the existing GDP slot infrastructure (tmi_programs,
 * tmi_slots, sp_TMI_GenerateSlots) with CTP-specific layering.
 *
 * Architecture: flowcontrol pushes tracks + constraints, then calls request-slot
 * on demand. PERTI returns ranked candidates with advisory status. Flowcontrol
 * confirms, and PERTI runs the 9-step CTOT cascade.
 */

namespace PERTI\Services;

require_once __DIR__ . '/CTOTCascade.php';
require_once __DIR__ . '/CTPConstraintAdvisor.php';

class CTPSlotEngine
{
    private $conn_adl;
    private $conn_tmi;
    private $conn_swim;
    private ?\GISService $gisService;
    private CTPConstraintAdvisor $advisor;
    private CTOTCascade $cascade;

    public function __construct($conn_adl, $conn_tmi, $conn_swim, ?\GISService $gisService = null)
    {
        $this->conn_adl = $conn_adl;
        $this->conn_tmi = $conn_tmi;
        $this->conn_swim = $conn_swim;
        $this->gisService = $gisService;
        $this->advisor = new CTPConstraintAdvisor($conn_tmi);
        $this->cascade = new CTOTCascade($conn_adl, $conn_tmi, $conn_swim, $gisService);
    }

    /**
     * Resolve a CTP session by name or ID.
     *
     * @param string|int $nameOrId Session name (e.g. "CTPE26") or session_id
     * @return array|null          Session row or null
     */
    public function resolveSession($nameOrId): ?array
    {
        if (is_numeric($nameOrId)) {
            $stmt = sqlsrv_query($this->conn_tmi,
                "SELECT * FROM dbo.ctp_sessions WHERE session_id = ?",
                [(int)$nameOrId]
            );
        } else {
            $stmt = sqlsrv_query($this->conn_tmi,
                "SELECT * FROM dbo.ctp_sessions WHERE session_name = ?",
                [(string)$nameOrId]
            );
        }
        if (!$stmt) return null;
        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($stmt);
        return $row ?: null;
    }

    /**
     * Generate slot grid for all active tracks in a session.
     * Creates tmi_programs entries and calls sp_TMI_GenerateSlots per track.
     */
    public function generateSlotGrid(int $sessionId): array
    {
        $session = $this->resolveSession($sessionId);
        if (!$session) return ['error' => 'Session not found', 'code' => 'SESSION_NOT_FOUND'];

        // Mark generating
        sqlsrv_query($this->conn_tmi,
            "UPDATE dbo.ctp_sessions SET slot_generation_status = 'GENERATING' WHERE session_id = ?",
            [$sessionId]
        );

        // Get all active tracks
        $stmt = sqlsrv_query($this->conn_tmi,
            "SELECT * FROM dbo.ctp_session_tracks WHERE session_id = ? AND is_active = 1",
            [$sessionId]
        );
        if (!$stmt) {
            sqlsrv_query($this->conn_tmi,
                "UPDATE dbo.ctp_sessions SET slot_generation_status = 'ERROR' WHERE session_id = ?",
                [$sessionId]
            );
            return ['error' => 'Failed to query tracks', 'code' => 'QUERY_FAILED'];
        }

        $tracks = [];
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $tracks[] = $row;
        }
        sqlsrv_free_stmt($stmt);

        if (empty($tracks)) {
            sqlsrv_query($this->conn_tmi,
                "UPDATE dbo.ctp_sessions SET slot_generation_status = 'ERROR' WHERE session_id = ?",
                [$sessionId]
            );
            return ['error' => 'No active tracks configured', 'code' => 'NO_TRACKS_CONFIGURED'];
        }

        $windowStart = $session['constraint_window_start'];
        $windowEnd = $session['constraint_window_end'];
        if ($windowStart instanceof \DateTime) $windowStart = $windowStart->format('Y-m-d H:i:s');
        if ($windowEnd instanceof \DateTime) $windowEnd = $windowEnd->format('Y-m-d H:i:s');

        $totalSlots = 0;
        $tracksProcessed = 0;

        foreach ($tracks as $track) {
            // Skip if program already created for this track
            if ($track['program_id']) continue;

            // Use oceanic entry fix as ctl_element (fits NVARCHAR(8) limit)
            $ctlElement = $track['oceanic_entry_fix'] ?: strtoupper(substr($track['track_name'], 0, 8));
            $rate = (int)$track['max_acph'];
            $programName = 'CTP_' . str_replace('-', '_', $track['track_name']);

            // Create tmi_programs entry for this track
            $stmt = sqlsrv_query($this->conn_tmi,
                "INSERT INTO dbo.tmi_programs
                    (ctl_element, element_type, program_type, program_name,
                     start_utc, end_utc, status, is_proposed, is_active,
                     program_rate, delay_limit_min, target_delay_mult,
                     aircraft_type_filter, subs_enabled, adaptive_compression,
                     created_by, created_at, updated_at, is_archived,
                     compression_enabled, earliest_r_slot_min,
                     exempt_airborne, org_code, reserve_pct,
                     reopt_cycle, reopt_interval_sec, reversal_count, reversal_pct,
                     gaming_flags_count, gs_release_followon)
                 OUTPUT INSERTED.program_id
                 VALUES (?, 'FIR', 'CTP', ?,
                         ?, ?, 'ACTIVE', 1, 0,
                         ?, 180, 1.00,
                         'ALL', 1, 0,
                         'SYSTEM', SYSUTCDATETIME(), SYSUTCDATETIME(), 0,
                         1, 0,
                         1, 'VATCSCC', 0,
                         0, 120, 0, 0,
                         0, 'RELEASED')",
                [$ctlElement, $programName, $windowStart, $windowEnd, $rate]
            );

            if (!$stmt) {
                error_log("CTPSlotEngine: Failed to create program for track {$track['track_name']}: " . print_r(sqlsrv_errors(), true));
                continue;
            }

            $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
            sqlsrv_free_stmt($stmt);
            $programId = $row ? (int)$row['program_id'] : null;

            if (!$programId) continue;

            // Link program to track
            sqlsrv_query($this->conn_tmi,
                "UPDATE dbo.ctp_session_tracks SET program_id = ?, updated_at = SYSUTCDATETIME()
                 WHERE session_track_id = ?",
                [$programId, $track['session_track_id']]
            );

            // Generate slots via stored procedure
            $slotCount = 0;
            $sp = sqlsrv_query($this->conn_tmi,
                "DECLARE @count INT;
                 EXEC dbo.sp_TMI_GenerateSlots @program_id = ?, @slot_count = @count OUTPUT;
                 SELECT @count AS slot_count;",
                [$programId]
            );

            if ($sp) {
                // Consume any intermediate result sets from the SP
                do {
                    $row = sqlsrv_fetch_array($sp, SQLSRV_FETCH_ASSOC);
                    if ($row && isset($row['slot_count'])) {
                        $slotCount = (int)$row['slot_count'];
                    }
                } while (sqlsrv_next_result($sp));
                sqlsrv_free_stmt($sp);
            } else {
                error_log("CTPSlotEngine: sp_TMI_GenerateSlots failed for program $programId: " . print_r(sqlsrv_errors(), true));
            }

            $totalSlots += $slotCount;
            $tracksProcessed++;
        }

        // Mark ready
        sqlsrv_query($this->conn_tmi,
            "UPDATE dbo.ctp_sessions SET slot_generation_status = 'READY' WHERE session_id = ?",
            [$sessionId]
        );

        return [
            'tracks_processed' => $tracksProcessed,
            'total_slots' => $totalSlots,
            'status' => 'READY',
        ];
    }

    /**
     * Request ranked slot candidates for a flight.
     *
     * @param array $params Keys: session_name|session_id, callsign, origin, destination,
     *                      aircraft_type, preferred_track, tobt, is_airborne, na_route, eu_route
     * @return array        {recommended: {...}, alternatives: [...]} or {error, code}
     */
    public function requestSlot(array $params): array
    {
        $session = $this->resolveSession($params['session_name'] ?? $params['session_id'] ?? '');
        if (!$session) return ['error' => 'Session not found', 'code' => 'SESSION_NOT_FOUND'];

        $status = $session['status'] ?? '';
        if ($status !== 'ACTIVE') return ['error' => 'Session not active', 'code' => 'SESSION_NOT_ACTIVE'];

        $slotGenStatus = $session['slot_generation_status'] ?? 'PENDING';
        if ($slotGenStatus !== 'READY') return ['error' => 'Slot grid not generated', 'code' => 'SLOTS_NOT_READY'];

        // If FC specified a track, try only that track first
        $requestedTrack = $params['track'] ?? '';
        $preferredTrack = $params['preferred_track'] ?? '';
        $singleTrackMode = false;

        if ($requestedTrack) {
            $track = $this->getTrackByName((int)$session['session_id'], $requestedTrack);
            if ($track && $track['program_id'] && $track['is_active']) {
                $tracks = [$track];
                $singleTrackMode = true;
            } else {
                // Requested track not found/usable — fall back to all tracks
                $tracks = $this->getActiveTracks($session['session_id'], $requestedTrack);
            }
        } else {
            $tracks = $this->getActiveTracks($session['session_id'], $preferredTrack);
        }
        if (empty($tracks)) return ['error' => 'No tracks configured', 'code' => 'NO_TRACKS_CONFIGURED'];

        // Lookup flight
        $flight = CTOTCascade::findFlight($this->conn_swim, $params['callsign'] ?? '');
        if (!$flight) return ['error' => 'Flight not found in ADL', 'code' => 'FLIGHT_NOT_FOUND'];

        // Get taxi reference for origin
        $taxiSec = CTOTCascade::getTaxiReference($this->conn_adl, $params['origin'] ?? $flight['fp_dept_icao']);
        $taxiMin = (int)round($taxiSec / 60);

        $isAirborne = (bool)($params['is_airborne'] ?? false);
        $candidates = [];
        $eteCache = [];

        // Determine flight's baseline departure time for OEP projection
        $tobtStr = $params['tobt'] ?? null;
        if (!$tobtStr) {
            // Fall back to flight's planned departure from ADL (etd_utc or std_utc)
            $stmt = sqlsrv_query($this->conn_adl,
                "SELECT etd_utc, std_utc FROM dbo.adl_flight_times WHERE flight_uid = ?",
                [(int)$flight['flight_uid']]
            );
            if ($stmt) {
                $trow = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
                sqlsrv_free_stmt($stmt);
                if ($trow) {
                    $tobtStr = $trow['etd_utc'] ?? $trow['std_utc'] ?? null;
                    if ($tobtStr instanceof \DateTime) $tobtStr = $tobtStr->format('Y-m-d H:i:s');
                }
            }
            if (!$tobtStr) $tobtStr = gmdate('Y-m-d H:i:s');
        }
        $tobtTs = strtotime($tobtStr . ' UTC') ?: time();

        foreach ($tracks as $track) {
            if (!$track['program_id']) continue;

            // Compute or cache segment ETEs for this track
            $cacheKey = $track['track_name'];
            if (!isset($eteCache[$cacheKey])) {
                $eteCache[$cacheKey] = $this->computeSegmentETEs(
                    $flight, $track,
                    $tobtStr,
                    $params['na_route'] ?? '',
                    $params['eu_route'] ?? ''
                );
            }
            $etes = $eteCache[$cacheKey];

            if (!$etes || !isset($etes['na_ete_min'])) continue;

            $naEteSec = $etes['na_ete_min'] * 60;
            $ocaEteSec = $etes['oca_ete_min'] * 60;
            $euEteSec = $etes['eu_ete_min'] * 60;

            // Project when this flight would reach the oceanic entry point:
            // For ground flights: TOBT + taxi + NA segment ETE
            // For airborne flights: use current position ETA to entry fix if available,
            // otherwise TOBT + NA ETE (no taxi)
            if ($isAirborne) {
                $projectedOepTs = $tobtTs + $naEteSec;
            } else {
                $projectedOepTs = $tobtTs + $taxiSec + $naEteSec;
            }

            // Find the nearest open slot at or after the projected OEP
            $slot = $this->getSlotAtOrAfter((int)$track['program_id'], $projectedOepTs);
            if (!$slot) continue;

            $slotTimeStr = $slot['slot_time_utc'];
            if ($slotTimeStr instanceof \DateTime) $slotTimeStr = $slotTimeStr->format('Y-m-d H:i:s');
            $slotTs = strtotime($slotTimeStr . ' UTC');

            $timing = [
                'ctot_utc' => gmdate('Y-m-d\TH:i:s\Z', $slotTs - $naEteSec - $taxiSec),
                'off_utc' => gmdate('Y-m-d\TH:i:s\Z', $slotTs - $naEteSec),
                'oep_utc' => gmdate('Y-m-d\TH:i:s\Z', $slotTs),
                'exit_utc' => gmdate('Y-m-d\TH:i:s\Z', $slotTs + $ocaEteSec),
                'cta_utc' => gmdate('Y-m-d\TH:i:s\Z', $slotTs + $ocaEteSec + $euEteSec),
                'taxi_min' => $taxiMin,
                'na_ete_min' => $etes['na_ete_min'],
                'oca_ete_min' => $etes['oca_ete_min'],
                'eu_ete_min' => $etes['eu_ete_min'],
                'total_ete_min' => $etes['na_ete_min'] + $etes['oca_ete_min'] + $etes['eu_ete_min'],
                'cruise_speed_kts' => $etes['cruise_speed_kts'] ?? 0,
                'oceanic_entry_fix' => $track['oceanic_entry_fix'],
                'oceanic_exit_fix' => $track['oceanic_exit_fix'],
            ];

            if ($isAirborne) {
                unset($timing['ctot_utc']);
            }

            // Run constraint checks
            $advisories = $this->advisor->evaluate(
                (int)$session['session_id'],
                $params['destination'] ?? $flight['fp_dest_icao'],
                $timing,
                $track
            );

            $candidates[] = [
                'track' => $track['track_name'],
                'slot_time_utc' => gmdate('Y-m-d\TH:i:s\Z', $slotTs),
                'slot_id' => (int)$slot['slot_id'],
                'timing_chain' => $timing,
                'advisories' => $advisories,
                'advisory_count' => count($advisories),
                'is_preferred' => ($track['track_name'] === ($requestedTrack ?: $preferredTrack)),
            ];
        }

        // Single-track mode fallback: if the requested track produced no candidates,
        // retry with all tracks (revert to default behavior)
        if (empty($candidates) && $singleTrackMode) {
            $tracks = $this->getActiveTracks($session['session_id'], $requestedTrack);
            if (!empty($tracks)) {
                foreach ($tracks as $track) {
                    if (!$track['program_id']) continue;

                    $cacheKey = $track['track_name'];
                    if (!isset($eteCache[$cacheKey])) {
                        $eteCache[$cacheKey] = $this->computeSegmentETEs(
                            $flight, $track, $tobtStr,
                            $params['na_route'] ?? '', $params['eu_route'] ?? ''
                        );
                    }
                    $etes = $eteCache[$cacheKey];
                    if (!$etes || !isset($etes['na_ete_min'])) continue;

                    $naEteSec = $etes['na_ete_min'] * 60;
                    $ocaEteSec = $etes['oca_ete_min'] * 60;
                    $euEteSec = $etes['eu_ete_min'] * 60;

                    if ($isAirborne) {
                        $projectedOepTs = $tobtTs + $naEteSec;
                    } else {
                        $projectedOepTs = $tobtTs + $taxiSec + $naEteSec;
                    }

                    $slot = $this->getSlotAtOrAfter((int)$track['program_id'], $projectedOepTs);
                    if (!$slot) continue;

                    $slotTimeStr = $slot['slot_time_utc'];
                    if ($slotTimeStr instanceof \DateTime) $slotTimeStr = $slotTimeStr->format('Y-m-d H:i:s');
                    $slotTs = strtotime($slotTimeStr . ' UTC');

                    $timing = [
                        'ctot_utc' => gmdate('Y-m-d\TH:i:s\Z', $slotTs - $naEteSec - $taxiSec),
                        'off_utc' => gmdate('Y-m-d\TH:i:s\Z', $slotTs - $naEteSec),
                        'oep_utc' => gmdate('Y-m-d\TH:i:s\Z', $slotTs),
                        'exit_utc' => gmdate('Y-m-d\TH:i:s\Z', $slotTs + $ocaEteSec),
                        'cta_utc' => gmdate('Y-m-d\TH:i:s\Z', $slotTs + $ocaEteSec + $euEteSec),
                        'taxi_min' => $taxiMin,
                        'na_ete_min' => $etes['na_ete_min'],
                        'oca_ete_min' => $etes['oca_ete_min'],
                        'eu_ete_min' => $etes['eu_ete_min'],
                        'total_ete_min' => $etes['na_ete_min'] + $etes['oca_ete_min'] + $etes['eu_ete_min'],
                        'cruise_speed_kts' => $etes['cruise_speed_kts'] ?? 0,
                        'oceanic_entry_fix' => $track['oceanic_entry_fix'],
                        'oceanic_exit_fix' => $track['oceanic_exit_fix'],
                    ];
                    if ($isAirborne) unset($timing['ctot_utc']);

                    $advisories = $this->advisor->evaluate(
                        (int)$session['session_id'],
                        $params['destination'] ?? $flight['fp_dest_icao'],
                        $timing, $track
                    );

                    $candidates[] = [
                        'track' => $track['track_name'],
                        'slot_time_utc' => gmdate('Y-m-d\TH:i:s\Z', $slotTs),
                        'slot_id' => (int)$slot['slot_id'],
                        'timing_chain' => $timing,
                        'advisories' => $advisories,
                        'advisory_count' => count($advisories),
                        'is_preferred' => ($track['track_name'] === $requestedTrack),
                    ];
                }
            }
        }

        if (empty($candidates)) {
            return ['recommended' => null, 'alternatives' => []];
        }

        // Rank: preferred first, fewest advisories, earliest slot
        usort($candidates, function ($a, $b) {
            if ($a['is_preferred'] !== $b['is_preferred']) return $b['is_preferred'] - $a['is_preferred'];
            if ($a['advisory_count'] !== $b['advisory_count']) return $a['advisory_count'] - $b['advisory_count'];
            return strcmp($a['slot_time_utc'], $b['slot_time_utc']);
        });

        // Strip internal fields from response
        foreach ($candidates as &$c) { unset($c['is_preferred']); }
        unset($c);

        return [
            'recommended' => $candidates[0],
            'alternatives' => array_slice($candidates, 1, 5),
        ];
    }

    /**
     * Confirm a slot assignment — assign slot + run 9-step cascade.
     *
     * @param array $params Keys: session_name|session_id, callsign, track, slot_time_utc
     * @return array        {status, ctot_utc, cta_utc, slot_id, cascade_status} or {error, code}
     */
    public function confirmSlot(array $params): array
    {
        $session = $this->resolveSession($params['session_name'] ?? $params['session_id'] ?? '');
        if (!$session) return ['error' => 'Session not found', 'code' => 'SESSION_NOT_FOUND'];
        if (($session['status'] ?? '') !== 'ACTIVE') return ['error' => 'Session not active', 'code' => 'SESSION_NOT_ACTIVE'];

        $sessionId = (int)$session['session_id'];
        $callsign = $params['callsign'] ?? '';
        $trackName = $params['track'] ?? '';
        $slotTimeUtc = $params['slot_time_utc'] ?? '';

        // Get track info
        $track = $this->getTrackByName($sessionId, $trackName);
        if (!$track || !$track['program_id']) {
            return ['error' => 'Track not found or not configured', 'code' => 'NO_TRACKS_CONFIGURED'];
        }

        // Atomically claim the slot
        $stmt = sqlsrv_query($this->conn_tmi,
            "UPDATE dbo.tmi_slots SET
                slot_status = 'ASSIGNED',
                assigned_callsign = ?,
                assigned_at = SYSUTCDATETIME(),
                updated_at = SYSUTCDATETIME()
             OUTPUT INSERTED.slot_id, INSERTED.slot_time_utc
             WHERE program_id = ? AND slot_time_utc = ? AND slot_status = 'OPEN'",
            [$callsign, (int)$track['program_id'], $slotTimeUtc]
        );

        if (!$stmt) {
            error_log("CTPSlotEngine: confirmSlot UPDATE failed: " . print_r(sqlsrv_errors(), true));
            return ['error' => 'Failed to claim slot', 'code' => 'SLOT_TAKEN'];
        }

        $slotRow = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($stmt);

        if (!$slotRow) {
            return ['error' => 'Slot already taken or not found', 'code' => 'SLOT_TAKEN'];
        }

        $slotId = (int)$slotRow['slot_id'];
        $slotTime = $slotRow['slot_time_utc'];
        if ($slotTime instanceof \DateTime) $slotTime = $slotTime->format('Y-m-d H:i:s');
        $slotTs = strtotime($slotTime . ' UTC');

        // Lookup flight
        $flight = CTOTCascade::findFlight($this->conn_swim, $callsign);
        if (!$flight) {
            // Rollback slot
            sqlsrv_query($this->conn_tmi,
                "UPDATE dbo.tmi_slots SET slot_status = 'OPEN', assigned_callsign = NULL, assigned_flight_uid = NULL, assigned_origin = NULL, assigned_dest = NULL, assigned_at = NULL, updated_at = SYSUTCDATETIME() WHERE slot_id = ?",
                [$slotId]
            );
            return ['error' => 'Flight not found', 'code' => 'FLIGHT_NOT_FOUND'];
        }

        $flightUid = (int)$flight['flight_uid'];
        $isAirborne = (bool)($params['is_airborne'] ?? false);

        // Compute timing from slot
        $taxiSec = CTOTCascade::getTaxiReference($this->conn_adl, $flight['fp_dept_icao']);
        $etes = $this->computeSegmentETEs(
            $flight, $track,
            $params['tobt'] ?? gmdate('Y-m-d H:i:s'),
            $params['na_route'] ?? '',
            $params['eu_route'] ?? ''
        );

        if (!$etes || !isset($etes['na_ete_min'])) {
            // Rollback slot claim
            sqlsrv_query($this->conn_tmi,
                "UPDATE dbo.tmi_slots SET slot_status = 'OPEN', assigned_callsign = NULL,
                    assigned_flight_uid = NULL, assigned_origin = NULL, assigned_dest = NULL,
                    assigned_at = NULL, updated_at = SYSUTCDATETIME()
                 WHERE slot_id = ?",
                [$slotId]
            );
            return ['error' => 'Unable to compute timing chain — no waypoint or track distance data', 'code' => 'ETE_UNAVAILABLE'];
        }

        $naEteSec = ($etes['na_ete_min']) * 60;
        $ocaEteSec = ($etes['oca_ete_min']) * 60;
        $euEteSec = ($etes['eu_ete_min']) * 60;

        $ctotTs = $slotTs - $naEteSec - $taxiSec;
        $ctotStr = gmdate('Y-m-d H:i:s', $ctotTs);
        $ctaStr = gmdate('Y-m-d H:i:s', $slotTs + $ocaEteSec + $euEteSec);

        $slotStatus = $isAirborne ? 'FROZEN' : 'ASSIGNED';

        // Update tmi_slots with flight info
        sqlsrv_query($this->conn_tmi,
            "UPDATE dbo.tmi_slots SET
                assigned_flight_uid = ?, assigned_origin = ?, assigned_dest = ?
             WHERE slot_id = ?",
            [$flightUid, $flight['fp_dept_icao'], $flight['fp_dest_icao'], $slotId]
        );

        // Run 9-step CTOT cascade
        $cascadeResult = $this->cascade->apply($flight, $ctotStr, [
            'cta_utc' => $ctaStr,
            'program_name' => 'CTP-' . $session['session_name'],
            'program_id' => (int)$track['program_id'],
            'assigned_track' => $trackName,
            'route_segments' => [
                'na' => $params['na_route'] ?? null,
                'oceanic' => $track['route_string'],
                'eu' => $params['eu_route'] ?? null,
            ],
        ]);

        // Update ctp_flight_control with slot assignment
        $stmt = sqlsrv_query($this->conn_tmi,
            "UPDATE dbo.ctp_flight_control SET
                slot_status = ?, slot_id = ?,
                assigned_nat_track = ?,
                is_airborne = ?,
                oceanic_entry_fix = ?, oceanic_exit_fix = ?,
                oceanic_entry_utc = ?,
                projected_oep_utc = ?,
                updated_at = SYSUTCDATETIME()
             WHERE session_id = ? AND flight_uid = ?",
            [$slotStatus, $slotId, $trackName,
             $isAirborne ? 1 : 0,
             $track['oceanic_entry_fix'], $track['oceanic_exit_fix'],
             $slotTime, $slotTime,
             $sessionId, $flightUid]
        );

        if (!$stmt) {
            error_log("CTPSlotEngine: ctp_flight_control update failed: " . print_r(sqlsrv_errors(), true));
        } else {
            sqlsrv_free_stmt($stmt);
        }

        // Audit log
        $this->logAudit($sessionId, $flightUid, 'SLOT_ASSIGNED', [
            'track' => $trackName,
            'slot_time' => gmdate('Y-m-d\TH:i:s\Z', $slotTs),
            'ctot' => gmdate('Y-m-d\TH:i:s\Z', $ctotTs),
            'slot_id' => $slotId,
        ]);

        // WebSocket broadcast
        $this->broadcastEvent('ctp_slot_assigned', [
            'session_name' => $session['session_name'],
            'callsign' => $callsign,
            'track' => $trackName,
            'slot_time' => gmdate('Y-m-d\TH:i:s\Z', $slotTs),
            'ctot' => gmdate('Y-m-d\TH:i:s\Z', $ctotTs),
            'cta' => gmdate('Y-m-d\TH:i:s\Z', $slotTs + $ocaEteSec + $euEteSec),
        ]);

        return [
            'status' => $slotStatus,
            'ctot_utc' => $isAirborne ? null : gmdate('Y-m-d\TH:i:s\Z', $ctotTs),
            'cta_utc' => gmdate('Y-m-d\TH:i:s\Z', $slotTs + $ocaEteSec + $euEteSec),
            'slot_id' => $slotId,
            'cascade_status' => $cascadeResult['recalc_status'] ?? 'complete',
        ];
    }

    /**
     * Release a slot assignment.
     *
     * @param array $params Keys: session_name|session_id, callsign, reason
     * @return array        {released_slot_time_utc, released_track, slot_status} or {error, code}
     */
    public function releaseSlot(array $params): array
    {
        $session = $this->resolveSession($params['session_name'] ?? $params['session_id'] ?? '');
        if (!$session) return ['error' => 'Session not found', 'code' => 'SESSION_NOT_FOUND'];

        $sessionId = (int)$session['session_id'];
        $callsign = $params['callsign'] ?? '';
        $reason = $params['reason'] ?? 'COORDINATOR_RELEASE';

        // Find flight's current slot
        $stmt = sqlsrv_query($this->conn_tmi,
            "SELECT ctp_control_id, flight_uid, slot_id, slot_status, assigned_nat_track
             FROM dbo.ctp_flight_control
             WHERE session_id = ? AND callsign = ? AND slot_status NOT IN ('NONE','RELEASED')",
            [$sessionId, $callsign]
        );
        if (!$stmt) return ['error' => 'Query failed', 'code' => 'QUERY_FAILED'];
        $record = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($stmt);

        if (!$record) {
            return ['error' => 'No active slot assignment found', 'code' => 'NO_SLOT'];
        }

        if ($record['slot_status'] === 'FROZEN' && $reason !== 'DISCONNECT') {
            return ['error' => 'Cannot release frozen slot unless disconnect', 'code' => 'SLOT_FROZEN'];
        }

        $releasedSlotTime = null;

        // Free the tmi_slot
        if ($record['slot_id']) {
            $stmt = sqlsrv_query($this->conn_tmi,
                "SELECT slot_time_utc FROM dbo.tmi_slots WHERE slot_id = ?",
                [$record['slot_id']]
            );
            if ($stmt) {
                $slotRow = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
                sqlsrv_free_stmt($stmt);
                if ($slotRow) {
                    $st = $slotRow['slot_time_utc'];
                    if ($st instanceof \DateTime) $st = $st->format('Y-m-d\TH:i:s\Z');
                    $releasedSlotTime = $st;
                }
            }

            sqlsrv_query($this->conn_tmi,
                "UPDATE dbo.tmi_slots SET
                    slot_status = 'OPEN', assigned_callsign = NULL,
                    assigned_flight_uid = NULL, assigned_origin = NULL,
                    assigned_dest = NULL, assigned_at = NULL,
                    updated_at = SYSUTCDATETIME()
                 WHERE slot_id = ?",
                [$record['slot_id']]
            );
        }

        // Update ctp_flight_control
        sqlsrv_query($this->conn_tmi,
            "UPDATE dbo.ctp_flight_control SET
                slot_status = 'RELEASED', slot_id = NULL,
                miss_reason = ?, updated_at = SYSUTCDATETIME()
             WHERE ctp_control_id = ?",
            [$reason, $record['ctp_control_id']]
        );

        // Audit + broadcast
        $this->logAudit($sessionId, (int)$record['flight_uid'], 'SLOT_RELEASED', [
            'callsign' => $callsign,
            'reason' => $reason,
            'track' => $record['assigned_nat_track'],
        ]);

        $this->broadcastEvent('ctp_slot_released', [
            'session_name' => $session['session_name'],
            'callsign' => $callsign,
            'track' => $record['assigned_nat_track'],
            'reason' => $reason,
        ]);

        return [
            'released_slot_time_utc' => $releasedSlotTime,
            'released_track' => $record['assigned_nat_track'],
            'slot_status' => 'OPEN',
        ];
    }

    /**
     * Get session status with track utilization and flight summary.
     */
    public function getSessionStatus($nameOrId): array
    {
        $session = $this->resolveSession($nameOrId);
        if (!$session) return ['error' => 'Session not found', 'code' => 'SESSION_NOT_FOUND'];

        $sessionId = (int)$session['session_id'];

        // Track utilization from tmi_slots
        $stmt = sqlsrv_query($this->conn_tmi,
            "SELECT t.track_name,
                    COUNT(s.slot_id) AS total_slots,
                    SUM(CASE WHEN s.slot_status = 'ASSIGNED' THEN 1 ELSE 0 END) AS assigned,
                    SUM(CASE WHEN s.slot_status IN ('OPEN','COMPRESSED') THEN 1 ELSE 0 END) AS [open],
                    SUM(CASE WHEN s.slot_status IN ('BRIDGED','HELD','CANCELLED') THEN 1 ELSE 0 END) AS other
             FROM dbo.ctp_session_tracks t
             LEFT JOIN dbo.tmi_slots s ON s.program_id = t.program_id
             WHERE t.session_id = ? AND t.is_active = 1
             GROUP BY t.track_name
             ORDER BY t.track_name",
            [$sessionId]
        );

        $tracks = [];
        if ($stmt) {
            while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
                $total = (int)$row['total_slots'];
                $assigned = (int)$row['assigned'];
                $open = (int)$row['open'];
                $other = (int)$row['other'];
                $used = $total - $open;
                $tracks[] = [
                    'track_name' => $row['track_name'],
                    'total_slots' => $total,
                    'assigned' => $assigned,
                    'frozen' => 0,
                    'open' => $open,
                    'utilization_pct' => $total > 0 ? round($used / $total * 100, 1) : 0,
                ];
            }
            sqlsrv_free_stmt($stmt);
        }

        // Overlay frozen counts from ctp_flight_control per track
        $frozenStmt = sqlsrv_query($this->conn_tmi,
            "SELECT assigned_nat_track, COUNT(*) AS cnt
             FROM dbo.ctp_flight_control
             WHERE session_id = ? AND slot_status = 'FROZEN'
             GROUP BY assigned_nat_track",
            [$sessionId]
        );
        if ($frozenStmt) {
            $frozenMap = [];
            while ($row = sqlsrv_fetch_array($frozenStmt, SQLSRV_FETCH_ASSOC)) {
                $frozenMap[$row['assigned_nat_track']] = (int)$row['cnt'];
            }
            sqlsrv_free_stmt($frozenStmt);
            foreach ($tracks as &$t) {
                $t['frozen'] = $frozenMap[$t['track_name']] ?? 0;
            }
            unset($t);
        }

        // Constraint status
        $overRate = [];
        $stmt = sqlsrv_query($this->conn_tmi,
            "SELECT facility_name, facility_type, max_acph FROM dbo.ctp_facility_constraints
             WHERE session_id = ?",
            [$sessionId]
        );
        if ($stmt) {
            while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
                $overRate[] = [
                    'facility' => $row['facility_name'],
                    'facility_type' => $row['facility_type'],
                    'limit' => (int)$row['max_acph'],
                ];
            }
            sqlsrv_free_stmt($stmt);
        }

        // Flight summary
        $stmt = sqlsrv_query($this->conn_tmi,
            "SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN slot_status = 'ASSIGNED' THEN 1 ELSE 0 END) AS assigned,
                SUM(CASE WHEN slot_status = 'FROZEN' THEN 1 ELSE 0 END) AS frozen,
                SUM(CASE WHEN slot_status = 'AT_RISK' THEN 1 ELSE 0 END) AS at_risk,
                SUM(CASE WHEN slot_status = 'MISSED' THEN 1 ELSE 0 END) AS missed,
                SUM(CASE WHEN slot_status = 'RELEASED' THEN 1 ELSE 0 END) AS released,
                SUM(CASE WHEN slot_status = 'NONE' THEN 1 ELSE 0 END) AS unassigned
             FROM dbo.ctp_flight_control WHERE session_id = ?",
            [$sessionId]
        );

        $flights = ['total' => 0, 'assigned' => 0, 'frozen' => 0, 'at_risk' => 0,
                     'missed' => 0, 'released' => 0, 'unassigned' => 0];
        if ($stmt) {
            $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
            sqlsrv_free_stmt($stmt);
            if ($row) {
                foreach ($flights as $k => &$v) { $v = (int)($row[$k] ?? 0); }
                unset($v);
            }
        }

        $ecfmpCount = 0;
        $ecStmt = sqlsrv_query($this->conn_tmi,
            "SELECT COUNT(*) AS cnt FROM dbo.tmi_flow_measures
             WHERE status = 'ACTIVE' AND end_utc > SYSUTCDATETIME()"
        );
        if ($ecStmt) {
            $ecRow = sqlsrv_fetch_array($ecStmt, SQLSRV_FETCH_ASSOC);
            $ecfmpCount = $ecRow ? (int)$ecRow['cnt'] : 0;
            sqlsrv_free_stmt($ecStmt);
        }

        return [
            'session_name' => $session['session_name'],
            'status' => $session['status'],
            'slot_generation_status' => $session['slot_generation_status'] ?? 'PENDING',
            'tracks' => $tracks,
            'constraint_status' => [
                'configured' => $overRate,
                'ecfmp_active_regulations' => $ecfmpCount,
            ],
            'flights' => $flights,
        ];
    }

    // ========================================================================
    // Internal helpers
    // ========================================================================

    private function getActiveTracks(int $sessionId, string $preferredTrack = ''): array
    {
        $stmt = sqlsrv_query($this->conn_tmi,
            "SELECT * FROM dbo.ctp_session_tracks
             WHERE session_id = ? AND is_active = 1
             ORDER BY CASE WHEN track_name = ? THEN 0 ELSE 1 END, track_name",
            [$sessionId, $preferredTrack]
        );
        if (!$stmt) return [];
        $tracks = [];
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $tracks[] = $row;
        }
        sqlsrv_free_stmt($stmt);
        return $tracks;
    }

    private function getTrackByName(int $sessionId, string $trackName): ?array
    {
        $stmt = sqlsrv_query($this->conn_tmi,
            "SELECT * FROM dbo.ctp_session_tracks
             WHERE session_id = ? AND track_name = ?",
            [$sessionId, $trackName]
        );
        if (!$stmt) return null;
        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($stmt);
        return $row ?: null;
    }

    private function getEarliestOpenSlot(int $programId): ?array
    {
        $stmt = sqlsrv_query($this->conn_tmi,
            "SELECT TOP 1 slot_id, slot_time_utc, slot_name
             FROM dbo.tmi_slots
             WHERE program_id = ? AND slot_status = 'OPEN'
             ORDER BY slot_time_utc ASC",
            [$programId]
        );
        if (!$stmt) return null;
        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($stmt);
        return $row ?: null;
    }

    /**
     * Find the nearest open slot at or after a projected OEP timestamp.
     * Falls back to the earliest open slot if all slots are before the projection
     * (e.g. flight departs after the last slot).
     */
    private function getSlotAtOrAfter(int $programId, int $projectedTs): ?array
    {
        $projectedUtc = gmdate('Y-m-d H:i:s', $projectedTs);

        // First: nearest open slot at or after the projected OEP
        $stmt = sqlsrv_query($this->conn_tmi,
            "SELECT TOP 1 slot_id, slot_time_utc, slot_name
             FROM dbo.tmi_slots
             WHERE program_id = ? AND slot_status = 'OPEN'
               AND slot_time_utc >= ?
             ORDER BY slot_time_utc ASC",
            [$programId, $projectedUtc]
        );
        if ($stmt) {
            $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
            sqlsrv_free_stmt($stmt);
            if ($row) return $row;
        }

        // Fallback: if projected OEP is beyond all slots, return the last open slot
        // (flight will have delay absorbed)
        $stmt = sqlsrv_query($this->conn_tmi,
            "SELECT TOP 1 slot_id, slot_time_utc, slot_name
             FROM dbo.tmi_slots
             WHERE program_id = ? AND slot_status = 'OPEN'
             ORDER BY slot_time_utc DESC",
            [$programId]
        );
        if (!$stmt) return null;
        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($stmt);
        return $row ?: null;
    }

    /**
     * Compute segment ETEs using sp_CalculateETA.
     * Returns [na_ete_min, oca_ete_min, eu_ete_min, cruise_speed_kts].
     *
     * Fallback: If sp_CalculateETA or waypoints aren't available, use great-circle
     * distance / cruise speed estimate.
     */
    private function computeSegmentETEs(array $flight, array $track, string $tobt, string $naRoute = '', string $euRoute = ''): ?array
    {
        $flightUid = (int)$flight['flight_uid'];
        $entryFix = $track['oceanic_entry_fix'];
        $exitFix = $track['oceanic_exit_fix'];

        // Get cruise speed from BADA performance data
        $perf = CTOTCascade::getPerformance($this->conn_adl, $flight);
        $cruiseSpeed = $perf ? (int)$perf['cruise_speed_ktas'] : 0;
        if ($cruiseSpeed <= 0) {
            $cruiseSpeed = 450;
        }

        // Try to get waypoint-based ETEs from existing parsed route
        $stmt = sqlsrv_query($this->conn_adl,
            "SELECT fix_name, eta_utc, cum_dist_nm
             FROM dbo.adl_flight_waypoints
             WHERE flight_uid = ? AND fix_name IN (?, ?)
             ORDER BY sequence_num",
            [$flightUid, $entryFix, $exitFix]
        );

        $entryEta = null;
        $exitEta = null;
        $entryDist = null;
        $exitDist = null;

        if ($stmt) {
            while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
                if ($row['fix_name'] === $entryFix) {
                    $entryEta = $row['eta_utc'];
                    $entryDist = (float)$row['cum_dist_nm'];
                }
                if ($row['fix_name'] === $exitFix) {
                    $exitEta = $row['eta_utc'];
                    $exitDist = (float)$row['cum_dist_nm'];
                }
            }
            sqlsrv_free_stmt($stmt);
        }

        // Read flight departure and arrival ETAs
        $times = CTOTCascade::readFlightTimes($this->conn_adl, $flightUid);
        $etaUtc = $times['eta_utc'] ?? null;

        $depTs = strtotime($tobt . ' UTC');

        if ($entryDist && $exitDist && $cruiseSpeed > 0) {
            // Distance-based computation
            $totalDist = 0;
            if ($etaUtc) {
                $stmt2 = sqlsrv_query($this->conn_adl,
                    "SELECT MAX(cum_dist_nm) AS total_dist FROM dbo.adl_flight_waypoints WHERE flight_uid = ?",
                    [$flightUid]
                );
                if ($stmt2) {
                    $r = sqlsrv_fetch_array($stmt2, SQLSRV_FETCH_ASSOC);
                    sqlsrv_free_stmt($stmt2);
                    $totalDist = $r ? (float)$r['total_dist'] : 0;
                }
            }

            $naEteMin = (int)round($entryDist / $cruiseSpeed * 60);
            $ocaEteMin = (int)round(($exitDist - $entryDist) / $cruiseSpeed * 60);
            $euEteMin = $totalDist > 0
                ? (int)round(($totalDist - $exitDist) / $cruiseSpeed * 60)
                : 0;
            // If EU distance is unknown, estimate from entry dist ratio
            if ($euEteMin <= 0 && $naEteMin > 0 && $ocaEteMin > 0) {
                $euEteMin = $naEteMin;
            }

            return [
                'na_ete_min' => max(0, $naEteMin),
                'oca_ete_min' => max(0, $ocaEteMin),
                'eu_ete_min' => max(0, $euEteMin),
                'cruise_speed_kts' => $cruiseSpeed,
            ];
        }

        // Fallback: calculate from track route_distance_nm if available
        $trackDistNm = isset($track['route_distance_nm']) ? (float)$track['route_distance_nm'] : 0;
        if ($trackDistNm > 0 && $cruiseSpeed > 0) {
            $ocaEteMin = (int)round($trackDistNm / $cruiseSpeed * 60);
            // NA/EU segments: estimate proportionally from total route vs oceanic
            $naEteMin = (int)round($ocaEteMin * 0.375);
            $euEteMin = (int)round($ocaEteMin * 0.5);
            return [
                'na_ete_min' => max(0, $naEteMin),
                'oca_ete_min' => max(0, $ocaEteMin),
                'eu_ete_min' => max(0, $euEteMin),
                'cruise_speed_kts' => $cruiseSpeed,
            ];
        }

        error_log("CTPSlotEngine: No waypoints or route_distance_nm for ETE calculation, flight_uid=$flightUid");
        return null;
    }

    private function logAudit(int $sessionId, ?int $flightUid, string $actionType, array $detail): void
    {
        // Look up ctp_control_id if we have a flight_uid
        $ctpControlId = null;
        if ($flightUid) {
            $stmt = sqlsrv_query($this->conn_tmi,
                "SELECT ctp_control_id FROM dbo.ctp_flight_control WHERE session_id = ? AND flight_uid = ?",
                [$sessionId, $flightUid]
            );
            if ($stmt) {
                $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
                sqlsrv_free_stmt($stmt);
                $ctpControlId = $row ? (int)$row['ctp_control_id'] : null;
            }
        }

        sqlsrv_query($this->conn_tmi,
            "INSERT INTO dbo.ctp_audit_log
                (session_id, ctp_control_id, action_type, action_detail_json, performed_by)
             VALUES (?, ?, ?, ?, 'SYSTEM')",
            [$sessionId, $ctpControlId, $actionType, json_encode($detail)]
        );
    }

    private function broadcastEvent(string $type, array $data): void
    {
        $eventFile = sys_get_temp_dir() . '/swim_ws_events.json';
        $existing = [];
        if (file_exists($eventFile)) {
            $content = @file_get_contents($eventFile);
            if ($content) $existing = json_decode($content, true) ?: [];
        }
        $existing[] = array_merge(['type' => $type], $data, [
            '_received_at' => gmdate('Y-m-d\TH:i:s.v\Z'),
        ]);
        if (count($existing) > 10000) $existing = array_slice($existing, -5000);
        $tmp = $eventFile . '.tmp.' . getmypid();
        if (@file_put_contents($tmp, json_encode($existing), LOCK_EX) !== false) {
            @rename($tmp, $eventFile);
        }
    }
}
