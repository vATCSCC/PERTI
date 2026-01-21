-- ============================================================================
-- 009_add_gs_flag_eligibility.sql
--
-- Add GS eligibility flag (gs_flag) to vw_adl_flights view
--
-- Problem: The GDT frontend (gdt.js) expects a `gs_flag` field to determine
-- which flights are eligible for Ground Stop/GDP EDCT assignment. Without this,
-- the filtering logic fails and shows ineligible flights (arrived, enroute, etc.)
-- in the preview/simulate results.
--
-- Solution: Add computed `gs_flag` column based on flight phase:
--   - gs_flag = 1: Eligible for TMI control (pre-departure flights)
--   - gs_flag = 0: Not eligible (already airborne, arrived, or disconnected)
--
-- Eligible phases (gs_flag = 1):
--   - 'prefile'   : Flight plan filed, pilot not yet connected
--   - 'taxiing'   : On ground at departure airport, taxiing
--   - 'scheduled' : Scheduled flight, not yet active
--
-- Ineligible phases (gs_flag = 0):
--   - 'departed'     : Just taken off, climbing
--   - 'enroute'      : Cruising at altitude
--   - 'descending'   : On approach to destination
--   - 'arrived'      : Landed at destination
--   - 'disconnected' : Pilot disconnected mid-flight
--   - NULL/other     : Unknown status
--
-- FSM Reference: Chapter 19 - Ground Stops only apply to flights that have
-- not yet departed. Once airborne, a flight cannot receive an EDCT.
--
-- Run after: 008_view_compute_missing_times.sql
-- ============================================================================

SET ANSI_NULLS ON;
SET QUOTED_IDENTIFIER ON;
GO

PRINT '=== Adding GS Eligibility Flag (gs_flag) to vw_adl_flights ===';
PRINT 'Started at: ' + CONVERT(VARCHAR, GETUTCDATE(), 120);
GO

-- Drop and recreate the view with gs_flag column
IF EXISTS (SELECT * FROM sys.views WHERE object_id = OBJECT_ID(N'dbo.vw_adl_flights'))
BEGIN
    DROP VIEW dbo.vw_adl_flights;
    PRINT 'Dropped existing view dbo.vw_adl_flights';
END
GO

CREATE VIEW dbo.vw_adl_flights AS
SELECT
    -- Core identifiers
    c.flight_uid,
    c.flight_key,
    c.cid,
    c.callsign,
    c.flight_id,

    -- Lifecycle
    c.phase,
    c.last_source,
    c.is_active,

    -- =========================================================================
    -- GS ELIGIBILITY FLAG (NEW)
    -- 
    -- Determines if flight can receive EDCT/TMI control based on phase.
    -- Pre-departure flights (prefile, taxiing, scheduled) are eligible.
    -- Airborne and completed flights are NOT eligible.
    -- =========================================================================
    CASE 
        WHEN c.phase IN ('prefile', 'taxiing', 'scheduled') THEN 1 
        ELSE 0 
    END AS gs_flag,

    -- Timestamps (core)
    c.first_seen_utc,
    c.last_seen_utc,
    c.logon_time_utc,
    c.adl_date,
    c.adl_time,
    c.snapshot_utc,

    -- Position
    p.lat,
    p.lon,
    p.position_geo,
    p.altitude_ft,
    p.altitude_assigned,
    p.altitude_cleared,
    p.groundspeed_kts,
    p.true_airspeed_kts,
    p.mach,
    p.vertical_rate_fpm,
    p.heading_deg AS heading,
    p.track_deg AS track,
    p.qnh_in_hg,
    p.qnh_mb,
    p.dist_to_dest_nm,
    p.dist_flown_nm,
    p.pct_complete,

    -- Flight Plan
    fp.fp_rule,
    fp.fp_dept_icao,
    fp.fp_dest_icao,
    fp.fp_alt_icao,
    fp.fp_dept_tracon,
    fp.fp_dept_artcc,
    fp.dfix,
    fp.dp_name,
    fp.dtrsn,
    fp.fp_dest_tracon,
    fp.fp_dest_artcc,
    fp.afix,
    fp.star_name,
    fp.strsn,
    fp.approach,
    fp.runway,
    fp.eaft_utc,
    fp.fp_route,
    fp.fp_route_expanded,
    fp.route_geometry,
    fp.waypoints_json,
    fp.waypoint_count,
    fp.parse_status,
    fp.parse_tier,
    fp.dep_runway,
    fp.arr_runway,
    fp.initial_alt_ft,
    fp.final_alt_ft,
    fp.stepclimb_count,
    fp.is_simbrief,
    fp.simbrief_id,
    fp.cost_index,
    fp.fp_dept_time_z,
    fp.fp_altitude_ft,
    fp.fp_tas_kts,
    fp.fp_enroute_minutes,
    fp.fp_fuel_minutes,
    fp.fp_remarks,
    fp.gcd_nm,
    fp.aircraft_type,
    fp.aircraft_equip,
    fp.artccs_traversed,
    fp.tracons_traversed,

    -- Aircraft
    ac.aircraft_icao,
    ac.weight_class,
    ac.engine_type,
    ac.wake_category,
    ac.airline_icao,
    ac.airline_name,
    -- Derived columns for TMI compatibility
    ac.airline_icao AS major_carrier,
    CASE
        WHEN ac.engine_type IN ('JET') THEN 'JET'
        WHEN ac.engine_type IN ('TURBOPROP', 'PISTON') THEN 'PROP'
        ELSE ac.engine_type
    END AS ac_cat,

    -- Times (standard)
    t.std_utc,
    t.etd_utc,
    -- etd_runway_utc: use actual column if exists, otherwise fallback to etd_utc
    COALESCE(t.etd_runway_utc, t.etd_utc) AS etd_runway_utc,
    t.atd_utc,
    t.atd_runway_utc,
    t.sta_utc,
    t.eta_utc,
    -- eta_runway_utc: CRITICAL FIX - compute from etd + ete when both eta columns are NULL
    -- Priority: 1) eta_runway_utc, 2) eta_utc, 3) computed from etd + ete
    COALESCE(
        t.eta_runway_utc,
        t.eta_utc,
        DATEADD(MINUTE, COALESCE(t.ete_minutes, fp.fp_enroute_minutes), COALESCE(t.etd_runway_utc, t.etd_utc))
    ) AS eta_runway_utc,
    t.ata_utc,
    t.ata_runway_utc,
    t.eta_epoch,
    t.etd_epoch,
    t.arrival_bucket_utc,
    t.departure_bucket_utc,
    -- ete_minutes: fallback to fp_enroute_minutes from flight plan
    COALESCE(t.ete_minutes, fp.fp_enroute_minutes) AS ete_minutes,
    t.delay_minutes,
    t.edct_utc,

    -- Times (GDT controlled - from adl_flight_times)
    t.ctd_utc,
    t.cta_utc,
    t.octd_utc,
    t.octa_utc,
    t.oetd_utc,
    t.betd_utc,
    t.oeta_utc,
    t.beta_utc,
    t.oete_minutes,
    t.cete_minutes,
    t.igta_utc,
    t.eta_prefix,

    -- OOOI Times
    t.out_utc,
    t.off_utc,
    t.on_utc,
    t.in_utc,

    -- TMI (standard)
    tmi.ctl_type,
    tmi.ctl_element,
    tmi.delay_status,
    tmi.slot_time_utc,
    tmi.slot_status,
    tmi.is_exempt,
    tmi.exempt_reason,
    tmi.reroute_status,
    tmi.reroute_id,

    -- TMI (GDT metrics - from adl_flight_tmi)
    tmi.program_delay_min,
    tmi.absolute_delay_min,
    tmi.schedule_variation_min,
    tmi.ctl_prgm,
    tmi.delay_capped,
    tmi.gs_held,
    tmi.gs_release_utc,
    tmi.ctl_exempt,
    tmi.ctl_exempt_reason,
    tmi.program_id,
    tmi.aslot,
    tmi.sl_hold,
    tmi.subbable,
    tmi.is_popup,
    tmi.popup_detected_utc,
    tmi.is_recontrol,
    tmi.ecr_pending,
    tmi.ecr_requested_cta,
    tmi.ux_cancelled,
    tmi.fx_cancelled,
    tmi.rz_removed,
    tmi.assigned_utc

FROM dbo.adl_flight_core c
LEFT JOIN dbo.adl_flight_position p ON p.flight_uid = c.flight_uid
LEFT JOIN dbo.adl_flight_plan fp ON fp.flight_uid = c.flight_uid
LEFT JOIN dbo.adl_flight_aircraft ac ON ac.flight_uid = c.flight_uid
LEFT JOIN dbo.adl_flight_times t ON t.flight_uid = c.flight_uid
LEFT JOIN dbo.adl_flight_tmi tmi ON tmi.flight_uid = c.flight_uid;
GO

PRINT 'Created updated view dbo.vw_adl_flights with gs_flag column';
GO

-- Verify gs_flag column exists
PRINT '';
PRINT 'Verifying gs_flag column in view:';
SELECT COLUMN_NAME, DATA_TYPE
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_NAME = 'vw_adl_flights'
  AND COLUMN_NAME = 'gs_flag';
GO

-- Show sample of gs_flag values by phase
PRINT '';
PRINT 'Sample of gs_flag values by flight phase:';
SELECT 
    phase,
    gs_flag,
    COUNT(*) AS flight_count
FROM dbo.vw_adl_flights
WHERE is_active = 1
GROUP BY phase, gs_flag
ORDER BY gs_flag DESC, phase;
GO

-- Show counts of eligible vs ineligible flights
PRINT '';
PRINT 'GS Eligibility Summary:';
SELECT 
    CASE WHEN gs_flag = 1 THEN 'Eligible (pre-departure)' ELSE 'Ineligible (airborne/completed)' END AS status,
    COUNT(*) AS flight_count
FROM dbo.vw_adl_flights
WHERE is_active = 1
GROUP BY gs_flag
ORDER BY gs_flag DESC;
GO

PRINT '';
PRINT '=== Migration 009 Complete ===';
PRINT 'Finished at: ' + CONVERT(VARCHAR, GETUTCDATE(), 120);
PRINT '';
PRINT 'IMPORTANT: After running this migration:';
PRINT '1. The vw_adl_flights view now includes the gs_flag column';
PRINT '2. gdt.js will use gs_flag to filter eligible flights';
PRINT '3. Only pre-departure flights (prefile, taxiing, scheduled) will show as eligible';
PRINT '4. Preview/Simulate should now correctly exclude airborne flights';
GO
