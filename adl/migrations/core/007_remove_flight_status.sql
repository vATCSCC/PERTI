-- ============================================================================
-- Migration: Remove flight_status column (unified to phase)
--
-- The flight_status column was redundant with phase and had inconsistent usage.
-- All status tracking now uses the 'phase' column exclusively.
--
-- Changes:
-- 1. Drop flight_status column from adl_flight_core
-- 2. Update vw_adl_flights view to remove flight_status reference
-- ============================================================================

PRINT '============================================================================';
PRINT 'Migration: Remove flight_status column';
PRINT '============================================================================';

-- ============================================================================
-- Step 1: Update the view first (remove flight_status reference)
-- ============================================================================

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

    -- Times
    t.std_utc,
    t.etd_utc,
    t.etd_runway_utc,
    t.atd_utc,
    t.atd_runway_utc,
    t.ctd_utc,
    t.edct_utc,
    t.sta_utc,
    t.eta_utc,
    t.eta_runway_utc,
    t.ata_utc,
    t.ata_runway_utc,
    t.cta_utc,
    t.eta_epoch,
    t.etd_epoch,
    t.arrival_bucket_utc,
    t.departure_bucket_utc,
    t.ete_minutes,
    t.delay_minutes,

    -- TMI
    tmi.ctl_type,
    tmi.ctl_element,
    tmi.delay_status,
    tmi.slot_time_utc,
    tmi.slot_status,
    tmi.is_exempt,
    tmi.exempt_reason,
    tmi.reroute_status,
    tmi.reroute_id

FROM dbo.adl_flight_core c
LEFT JOIN dbo.adl_flight_position p ON p.flight_uid = c.flight_uid
LEFT JOIN dbo.adl_flight_plan fp ON fp.flight_uid = c.flight_uid
LEFT JOIN dbo.adl_flight_aircraft ac ON ac.flight_uid = c.flight_uid
LEFT JOIN dbo.adl_flight_times t ON t.flight_uid = c.flight_uid
LEFT JOIN dbo.adl_flight_tmi tmi ON tmi.flight_uid = c.flight_uid;
GO

PRINT 'Recreated view dbo.vw_adl_flights (without flight_status)';
GO

-- ============================================================================
-- Step 2: Drop flight_status column from adl_flight_core
-- ============================================================================

IF EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.adl_flight_core') AND name = 'flight_status')
BEGIN
    ALTER TABLE dbo.adl_flight_core DROP COLUMN flight_status;
    PRINT 'Dropped column flight_status from adl_flight_core';
END
ELSE
BEGIN
    PRINT 'Column flight_status does not exist (already removed)';
END
GO

PRINT '============================================================================';
PRINT 'Migration complete: flight_status column removed';
PRINT '============================================================================';
GO
