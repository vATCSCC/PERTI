-- ============================================================================
-- Fix vw_adl_flights to compute ETA when missing and add missing CTD/CTA columns
--
-- Problem 1: eta_runway_utc and eta_utc are often NULL because:
-- 1. eta_utc is only populated by async trajectory batch
-- 2. eta_runway_utc is never explicitly set during ingestion
--
-- Problem 2: ctd_utc and cta_utc columns were added to adl_flight_tmi but
-- the view references them from adl_flight_times (which doesn't have them).
-- This causes GDT to not display any controlled times in the UI.
--
-- This migration:
-- 1. Adds ctd_utc and cta_utc columns to adl_flight_times
-- 2. Updates the view to compute ETA fallbacks
-- 3. ete_minutes: fallback to fp_enroute_minutes from flight plan
-- 4. eta_runway_utc: compute from etd_utc + ete_minutes when NULL
--
-- Run after: 007_update_view_gdt_columns.sql
-- ============================================================================

SET ANSI_NULLS ON;
SET QUOTED_IDENTIFIER ON;
GO

PRINT '=== Fixing vw_adl_flights to compute missing time values ===';
PRINT 'Started at: ' + CONVERT(VARCHAR, GETUTCDATE(), 120);
GO

-- ============================================================================
-- CRITICAL FIX: Add missing ctd_utc and cta_utc columns to adl_flight_times
-- These were referenced in the view but never added to the table!
-- ============================================================================

PRINT '';
PRINT 'Adding missing CTD/CTA columns to adl_flight_times...';

-- Controlled Time of Departure (CTD)
IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID('dbo.adl_flight_times') AND name = 'ctd_utc')
BEGIN
    ALTER TABLE dbo.adl_flight_times ADD ctd_utc DATETIME2(0) NULL;
    PRINT 'Added column ctd_utc to adl_flight_times';
END
ELSE
BEGIN
    PRINT 'Column ctd_utc already exists in adl_flight_times';
END
GO

-- Controlled Time of Arrival (CTA)
IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID('dbo.adl_flight_times') AND name = 'cta_utc')
BEGIN
    ALTER TABLE dbo.adl_flight_times ADD cta_utc DATETIME2(0) NULL;
    PRINT 'Added column cta_utc to adl_flight_times';
END
ELSE
BEGIN
    PRINT 'Column cta_utc already exists in adl_flight_times';
END
GO

-- Drop and recreate the view with computed fallbacks
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

PRINT 'Created updated view dbo.vw_adl_flights with computed fallbacks';
GO

-- Verify column count
PRINT '';
PRINT 'View column count:';
SELECT COUNT(*) AS column_count
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_NAME = 'vw_adl_flights';
GO

-- Verify computed columns are working
PRINT '';
PRINT 'Sample of flights with computed times:';
SELECT TOP 5
    callsign,
    fp_dept_icao,
    fp_dest_icao,
    etd_utc,
    etd_runway_utc,
    eta_utc,
    eta_runway_utc,
    ete_minutes,
    fp_enroute_minutes
FROM dbo.vw_adl_flights
WHERE is_active = 1
  AND etd_runway_utc IS NOT NULL
ORDER BY etd_runway_utc;
GO

-- Verify CTD/CTA columns are now in the view
PRINT '';
PRINT 'Verifying CTD/CTA columns in view:';
SELECT COLUMN_NAME, DATA_TYPE
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_NAME = 'vw_adl_flights'
  AND COLUMN_NAME IN ('ctd_utc', 'cta_utc')
ORDER BY COLUMN_NAME;
GO

-- Verify CTD/CTA columns are in adl_flight_times table
PRINT '';
PRINT 'Verifying CTD/CTA columns in adl_flight_times:';
SELECT COLUMN_NAME, DATA_TYPE
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_NAME = 'adl_flight_times'
  AND COLUMN_NAME IN ('ctd_utc', 'cta_utc')
ORDER BY COLUMN_NAME;
GO

PRINT '';
PRINT '=== Migration 008 Complete ===';
PRINT 'Finished at: ' + CONVERT(VARCHAR, GETUTCDATE(), 120);
PRINT '';
PRINT 'IMPORTANT: After running this migration:';
PRINT '1. The vw_adl_flights view now includes ctd_utc and cta_utc columns';
PRINT '2. GDT simulation should now properly show controlled times';
PRINT '3. Re-run any GS/GDP simulations to verify times appear in the UI';
GO
