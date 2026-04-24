-- ============================================================================
-- 024_ctp_event_bookings.sql
--
-- CTP E26 Flight Tagging: booking table, flow_event_code column, view update
--
-- Database: VATSIM_ADL
-- Run as: jpeterson (DDL admin)
--
-- Creates:
--   1. dbo.ctp_event_bookings — stores Nattrak booking data for CID matching
--   2. Adds flow_event_code column to adl_flight_tmi
--   3. Updates vw_adl_flights to expose flow_event_code
--
-- Run after: 023_preferred_routes_tables.sql
-- ============================================================================

SET ANSI_NULLS ON;
SET QUOTED_IDENTIFIER ON;
GO

PRINT '=== Migration 024: CTP Event Bookings & Flow Event Code ===';
PRINT 'Started at: ' + CONVERT(VARCHAR, GETUTCDATE(), 120);
GO

-- ============================================================================
-- Step 1: Create ctp_event_bookings table
-- ============================================================================

IF NOT EXISTS (SELECT 1 FROM sys.tables WHERE name = 'ctp_event_bookings')
BEGIN
    CREATE TABLE dbo.ctp_event_bookings (
        booking_id         INT IDENTITY(1,1) PRIMARY KEY,
        event_code         NVARCHAR(32) NOT NULL,          -- e.g. 'CTPE26'
        cid                INT NOT NULL,                    -- VATSIM CID
        dep_airport        NVARCHAR(4) NOT NULL,
        arr_airport        NVARCHAR(4) NOT NULL,
        oceanic_track      VARCHAR(16) NULL,
        route              NVARCHAR(MAX) NULL,
        takeoff_time       VARCHAR(8) NULL,                 -- 'HH:MM' from Nattrak
        flight_level       INT NULL,
        selcal             VARCHAR(8) NULL,
        matched_flight_uid BIGINT NULL,
        matched_at         DATETIME2(0) NULL,
        created_at         DATETIME2(0) DEFAULT SYSUTCDATETIME(),
        updated_at         DATETIME2(0) DEFAULT SYSUTCDATETIME(),
        CONSTRAINT UQ_ctp_booking UNIQUE (event_code, cid, dep_airport, arr_airport)
    );

    CREATE INDEX IX_ctp_bookings_cid ON dbo.ctp_event_bookings (cid, event_code);

    PRINT 'Created table dbo.ctp_event_bookings';
END
ELSE
BEGIN
    PRINT 'Table dbo.ctp_event_bookings already exists - skipping';
END
GO

-- ============================================================================
-- Step 2: Add flow_event_code column to adl_flight_tmi
-- ============================================================================

IF NOT EXISTS (
    SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_NAME = 'adl_flight_tmi' AND COLUMN_NAME = 'flow_event_code'
)
BEGIN
    ALTER TABLE dbo.adl_flight_tmi ADD flow_event_code NVARCHAR(32) NULL;

    CREATE INDEX IX_adl_tmi_event ON dbo.adl_flight_tmi (flow_event_code)
        INCLUDE (flight_uid) WHERE flow_event_code IS NOT NULL;

    PRINT 'Added flow_event_code column and filtered index to adl_flight_tmi';
END
ELSE
BEGIN
    PRINT 'Column flow_event_code already exists on adl_flight_tmi - skipping';
END
GO

-- ============================================================================
-- Step 3: Recreate vw_adl_flights with flow_event_code
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

    -- GS eligibility flag (from migration 009)
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
    -- eta_runway_utc: compute from etd + ete when both eta columns are NULL
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
    tmi.assigned_utc,

    -- CTP event tagging (migration 024)
    tmi.flow_event_code

FROM dbo.adl_flight_core c
LEFT JOIN dbo.adl_flight_position p ON p.flight_uid = c.flight_uid
LEFT JOIN dbo.adl_flight_plan fp ON fp.flight_uid = c.flight_uid
LEFT JOIN dbo.adl_flight_aircraft ac ON ac.flight_uid = c.flight_uid
LEFT JOIN dbo.adl_flight_times t ON t.flight_uid = c.flight_uid
LEFT JOIN dbo.adl_flight_tmi tmi ON tmi.flight_uid = c.flight_uid;
GO

PRINT 'Created updated view dbo.vw_adl_flights with flow_event_code column';
GO

-- ============================================================================
-- Verify
-- ============================================================================

PRINT '';
PRINT 'Verifying ctp_event_bookings table:';
SELECT TABLE_NAME, COLUMN_NAME, DATA_TYPE, CHARACTER_MAXIMUM_LENGTH
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_NAME = 'ctp_event_bookings'
ORDER BY ORDINAL_POSITION;
GO

PRINT '';
PRINT 'Verifying flow_event_code in adl_flight_tmi:';
SELECT COLUMN_NAME, DATA_TYPE, CHARACTER_MAXIMUM_LENGTH
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_NAME = 'adl_flight_tmi' AND COLUMN_NAME = 'flow_event_code';
GO

PRINT '';
PRINT 'Verifying flow_event_code in vw_adl_flights:';
SELECT COLUMN_NAME, DATA_TYPE
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_NAME = 'vw_adl_flights' AND COLUMN_NAME = 'flow_event_code';
GO

PRINT '';
PRINT '=== Migration 024 Complete ===';
PRINT 'Finished at: ' + CONVERT(VARCHAR, GETUTCDATE(), 120);
GO
