-- ============================================================================
-- Update vw_adl_flights to include all GDT columns
--
-- The original view (migration 005) didn't include the GDT-specific columns
-- that were added to adl_flight_times and adl_flight_tmi in migrations 004-006.
--
-- This migration recreates the view with all necessary columns for GDT simulation.
--
-- Key fixes:
-- 1. COALESCE for etd_runway_utc/eta_runway_utc (fallback to etd_utc/eta_utc)
-- 2. Derived columns: major_carrier, ac_cat (needed by TMI filtering)
-- 3. All GDT time columns from adl_flight_times
-- 4. All TMI columns from adl_flight_tmi (only columns that exist in the table)
--
-- Run after: 006_adl_flights_gdt_columns.sql
-- ============================================================================

SET ANSI_NULLS ON;
SET QUOTED_IDENTIFIER ON;
GO

PRINT '=== Updating vw_adl_flights to include GDT columns ===';
PRINT 'Started at: ' + CONVERT(VARCHAR, GETUTCDATE(), 120);
GO

-- First, verify the GDT columns exist in adl_flight_times
PRINT '';
PRINT 'Checking GDT columns in adl_flight_times...';
IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID('dbo.adl_flight_times') AND name = 'octd_utc')
BEGIN
    PRINT 'WARNING: octd_utc column missing from adl_flight_times - run migration 004 first';
END
ELSE
BEGIN
    PRINT 'GDT columns found in adl_flight_times';
END
GO

-- Drop and recreate the view with all columns
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
    -- eta_runway_utc: use actual column if exists, otherwise fallback to eta_utc
    COALESCE(t.eta_runway_utc, t.eta_utc) AS eta_runway_utc,
    t.ata_utc,
    t.ata_runway_utc,
    t.eta_epoch,
    t.etd_epoch,
    t.arrival_bucket_utc,
    t.departure_bucket_utc,
    t.ete_minutes,
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

PRINT 'Created updated view dbo.vw_adl_flights with GDT columns';
GO

-- Verify column count
PRINT '';
PRINT 'View column count:';
SELECT COUNT(*) AS column_count
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_NAME = 'vw_adl_flights';
GO

-- List GDT-specific columns in the view
PRINT '';
PRINT 'GDT columns now in vw_adl_flights:';
SELECT COLUMN_NAME, DATA_TYPE
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_NAME = 'vw_adl_flights'
  AND COLUMN_NAME IN (
    'ctd_utc', 'cta_utc', 'octd_utc', 'octa_utc',
    'oetd_utc', 'betd_utc', 'oeta_utc', 'beta_utc',
    'oete_minutes', 'cete_minutes', 'igta_utc', 'eta_prefix',
    'ctl_type', 'ctl_element', 'delay_status',
    'program_delay_min', 'absolute_delay_min', 'schedule_variation_min',
    'etd_runway_utc', 'eta_runway_utc', 'major_carrier', 'ac_cat'
  )
ORDER BY COLUMN_NAME;
GO

PRINT '';
PRINT '=== Migration 007 Complete ===';
PRINT 'Finished at: ' + CONVERT(VARCHAR, GETUTCDATE(), 120);
GO
