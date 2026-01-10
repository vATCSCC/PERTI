-- ============================================================================
-- GDT Columns Migration Fix
--
-- Adds missing columns required by GDT simulation scripts to the normalized
-- ADL tables (adl_flight_times and adl_flight_tmi).
--
-- The GDT PHP scripts (gs_simulate.php, gdp_simulate.php, etc.) expect these
-- columns in adl_flights, which previously was a flat table. Now that the
-- database uses a normalized schema, we need to add these columns to the
-- appropriate normalized tables.
--
-- Run after: 001_ntml_schema.sql, 002_gs_procedures.sql, 003_gdt_views.sql
-- ============================================================================

SET ANSI_NULLS ON;
SET QUOTED_IDENTIFIER ON;
GO

PRINT '=== GDT Columns Migration Fix ===';
PRINT 'Started at: ' + CONVERT(VARCHAR, GETUTCDATE(), 120);
GO

-- ============================================================================
-- 1. Add missing columns to adl_flight_times
-- ============================================================================

-- Original CTD (before any modifications)
IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID('dbo.adl_flight_times') AND name = 'octd_utc')
BEGIN
    ALTER TABLE dbo.adl_flight_times ADD octd_utc DATETIME2(0) NULL;
    PRINT 'Added column octd_utc to adl_flight_times';
END
GO

-- Original CTA (before any modifications)
IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID('dbo.adl_flight_times') AND name = 'octa_utc')
BEGIN
    ALTER TABLE dbo.adl_flight_times ADD octa_utc DATETIME2(0) NULL;
    PRINT 'Added column octa_utc to adl_flight_times';
END
GO

-- Original ETD
IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID('dbo.adl_flight_times') AND name = 'oetd_utc')
BEGIN
    ALTER TABLE dbo.adl_flight_times ADD oetd_utc DATETIME2(0) NULL;
    PRINT 'Added column oetd_utc to adl_flight_times';
END
GO

-- Baseline ETD
IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID('dbo.adl_flight_times') AND name = 'betd_utc')
BEGIN
    ALTER TABLE dbo.adl_flight_times ADD betd_utc DATETIME2(0) NULL;
    PRINT 'Added column betd_utc to adl_flight_times';
END
GO

-- Original ETA
IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID('dbo.adl_flight_times') AND name = 'oeta_utc')
BEGIN
    ALTER TABLE dbo.adl_flight_times ADD oeta_utc DATETIME2(0) NULL;
    PRINT 'Added column oeta_utc to adl_flight_times';
END
GO

-- Baseline ETA
IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID('dbo.adl_flight_times') AND name = 'beta_utc')
BEGIN
    ALTER TABLE dbo.adl_flight_times ADD beta_utc DATETIME2(0) NULL;
    PRINT 'Added column beta_utc to adl_flight_times';
END
GO

-- Original ETE (minutes)
IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID('dbo.adl_flight_times') AND name = 'oete_minutes')
BEGIN
    ALTER TABLE dbo.adl_flight_times ADD oete_minutes INT NULL;
    PRINT 'Added column oete_minutes to adl_flight_times';
END
GO

-- Controlled ETE (minutes)
IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID('dbo.adl_flight_times') AND name = 'cete_minutes')
BEGIN
    ALTER TABLE dbo.adl_flight_times ADD cete_minutes INT NULL;
    PRINT 'Added column cete_minutes to adl_flight_times';
END
GO

-- Initial Gate Time of Arrival
IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID('dbo.adl_flight_times') AND name = 'igta_utc')
BEGIN
    ALTER TABLE dbo.adl_flight_times ADD igta_utc DATETIME2(0) NULL;
    PRINT 'Added column igta_utc to adl_flight_times';
END
GO

-- ETA Prefix (C = Controlled, P = Proposed, E = Estimated, etc.)
IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID('dbo.adl_flight_times') AND name = 'eta_prefix')
BEGIN
    ALTER TABLE dbo.adl_flight_times ADD eta_prefix NCHAR(1) NULL;
    PRINT 'Added column eta_prefix to adl_flight_times';
END
GO

-- OOOI Times (Out, Off, On, In) for gate operations
IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID('dbo.adl_flight_times') AND name = 'out_utc')
BEGIN
    ALTER TABLE dbo.adl_flight_times ADD out_utc DATETIME2(0) NULL;
    PRINT 'Added column out_utc to adl_flight_times';
END
GO

IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID('dbo.adl_flight_times') AND name = 'off_utc')
BEGIN
    ALTER TABLE dbo.adl_flight_times ADD off_utc DATETIME2(0) NULL;
    PRINT 'Added column off_utc to adl_flight_times';
END
GO

IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID('dbo.adl_flight_times') AND name = 'on_utc')
BEGIN
    ALTER TABLE dbo.adl_flight_times ADD on_utc DATETIME2(0) NULL;
    PRINT 'Added column on_utc to adl_flight_times';
END
GO

IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID('dbo.adl_flight_times') AND name = 'in_utc')
BEGIN
    ALTER TABLE dbo.adl_flight_times ADD in_utc DATETIME2(0) NULL;
    PRINT 'Added column in_utc to adl_flight_times';
END
GO

-- ============================================================================
-- 2. Add missing columns to adl_flight_tmi
-- ============================================================================

-- Delay metrics
IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID('dbo.adl_flight_tmi') AND name = 'program_delay_min')
BEGIN
    ALTER TABLE dbo.adl_flight_tmi ADD program_delay_min INT NULL;
    PRINT 'Added column program_delay_min to adl_flight_tmi';
END
GO

IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID('dbo.adl_flight_tmi') AND name = 'absolute_delay_min')
BEGIN
    ALTER TABLE dbo.adl_flight_tmi ADD absolute_delay_min INT NULL;
    PRINT 'Added column absolute_delay_min to adl_flight_tmi';
END
GO

IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID('dbo.adl_flight_tmi') AND name = 'schedule_variation_min')
BEGIN
    ALTER TABLE dbo.adl_flight_tmi ADD schedule_variation_min INT NULL;
    PRINT 'Added column schedule_variation_min to adl_flight_tmi';
END
GO

-- Control program name
IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID('dbo.adl_flight_tmi') AND name = 'ctl_prgm')
BEGIN
    ALTER TABLE dbo.adl_flight_tmi ADD ctl_prgm NVARCHAR(50) NULL;
    PRINT 'Added column ctl_prgm to adl_flight_tmi';
END
GO

-- Program ID reference
IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID('dbo.adl_flight_tmi') AND name = 'program_id')
BEGIN
    ALTER TABLE dbo.adl_flight_tmi ADD program_id INT NULL;
    PRINT 'Added column program_id to adl_flight_tmi';
END
GO

-- Arrival slot number
IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID('dbo.adl_flight_tmi') AND name = 'aslot')
BEGIN
    ALTER TABLE dbo.adl_flight_tmi ADD aslot INT NULL;
    PRINT 'Added column aslot to adl_flight_tmi';
END
GO

-- Original CTD/CTA (TMI-specific backup)
IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID('dbo.adl_flight_tmi') AND name = 'octd_utc')
BEGIN
    ALTER TABLE dbo.adl_flight_tmi ADD octd_utc DATETIME2(0) NULL;
    PRINT 'Added column octd_utc to adl_flight_tmi';
END
GO

IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID('dbo.adl_flight_tmi') AND name = 'octa_utc')
BEGIN
    ALTER TABLE dbo.adl_flight_tmi ADD octa_utc DATETIME2(0) NULL;
    PRINT 'Added column octa_utc to adl_flight_tmi';
END
GO

-- CTD/CTA in TMI table (for normalization - mirrors adl_flight_times)
IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID('dbo.adl_flight_tmi') AND name = 'ctd_utc')
BEGIN
    ALTER TABLE dbo.adl_flight_tmi ADD ctd_utc DATETIME2(0) NULL;
    PRINT 'Added column ctd_utc to adl_flight_tmi';
END
GO

IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID('dbo.adl_flight_tmi') AND name = 'cta_utc')
BEGIN
    ALTER TABLE dbo.adl_flight_tmi ADD cta_utc DATETIME2(0) NULL;
    PRINT 'Added column cta_utc to adl_flight_tmi';
END
GO

-- Delay capped flag
IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID('dbo.adl_flight_tmi') AND name = 'delay_capped')
BEGIN
    ALTER TABLE dbo.adl_flight_tmi ADD delay_capped BIT NULL DEFAULT 0;
    PRINT 'Added column delay_capped to adl_flight_tmi';
END
GO

-- Control exempt fields
IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID('dbo.adl_flight_tmi') AND name = 'ctl_exempt')
BEGIN
    ALTER TABLE dbo.adl_flight_tmi ADD ctl_exempt BIT NULL DEFAULT 0;
    PRINT 'Added column ctl_exempt to adl_flight_tmi';
END
GO

IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID('dbo.adl_flight_tmi') AND name = 'ctl_exempt_reason')
BEGIN
    ALTER TABLE dbo.adl_flight_tmi ADD ctl_exempt_reason NVARCHAR(64) NULL;
    PRINT 'Added column ctl_exempt_reason to adl_flight_tmi';
END
GO

-- Ground Stop specific fields
IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID('dbo.adl_flight_tmi') AND name = 'gs_held')
BEGIN
    ALTER TABLE dbo.adl_flight_tmi ADD gs_held BIT NULL DEFAULT 0;
    PRINT 'Added column gs_held to adl_flight_tmi';
END
GO

IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID('dbo.adl_flight_tmi') AND name = 'gs_release_utc')
BEGIN
    ALTER TABLE dbo.adl_flight_tmi ADD gs_release_utc DATETIME2(0) NULL;
    PRINT 'Added column gs_release_utc to adl_flight_tmi';
END
GO

-- Slot management fields
IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID('dbo.adl_flight_tmi') AND name = 'sl_hold')
BEGIN
    ALTER TABLE dbo.adl_flight_tmi ADD sl_hold BIT NULL DEFAULT 0;
    PRINT 'Added column sl_hold to adl_flight_tmi';
END
GO

IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID('dbo.adl_flight_tmi') AND name = 'subbable')
BEGIN
    ALTER TABLE dbo.adl_flight_tmi ADD subbable BIT NULL DEFAULT 0;
    PRINT 'Added column subbable to adl_flight_tmi';
END
GO

-- Pop-up tracking
IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID('dbo.adl_flight_tmi') AND name = 'is_popup')
BEGIN
    ALTER TABLE dbo.adl_flight_tmi ADD is_popup BIT NULL DEFAULT 0;
    PRINT 'Added column is_popup to adl_flight_tmi';
END
GO

IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID('dbo.adl_flight_tmi') AND name = 'popup_detected_utc')
BEGIN
    ALTER TABLE dbo.adl_flight_tmi ADD popup_detected_utc DATETIME2(0) NULL;
    PRINT 'Added column popup_detected_utc to adl_flight_tmi';
END
GO

IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID('dbo.adl_flight_tmi') AND name = 'is_recontrol')
BEGIN
    ALTER TABLE dbo.adl_flight_tmi ADD is_recontrol BIT NULL DEFAULT 0;
    PRINT 'Added column is_recontrol to adl_flight_tmi';
END
GO

-- ECR (Expect Clearance Revision) fields
IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID('dbo.adl_flight_tmi') AND name = 'ecr_pending')
BEGIN
    ALTER TABLE dbo.adl_flight_tmi ADD ecr_pending BIT NULL DEFAULT 0;
    PRINT 'Added column ecr_pending to adl_flight_tmi';
END
GO

IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID('dbo.adl_flight_tmi') AND name = 'ecr_requested_cta')
BEGIN
    ALTER TABLE dbo.adl_flight_tmi ADD ecr_requested_cta DATETIME2(0) NULL;
    PRINT 'Added column ecr_requested_cta to adl_flight_tmi';
END
GO

-- Cancel flags
IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID('dbo.adl_flight_tmi') AND name = 'ux_cancelled')
BEGIN
    ALTER TABLE dbo.adl_flight_tmi ADD ux_cancelled BIT NULL DEFAULT 0;
    PRINT 'Added column ux_cancelled to adl_flight_tmi';
END
GO

IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID('dbo.adl_flight_tmi') AND name = 'fx_cancelled')
BEGIN
    ALTER TABLE dbo.adl_flight_tmi ADD fx_cancelled BIT NULL DEFAULT 0;
    PRINT 'Added column fx_cancelled to adl_flight_tmi';
END
GO

IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID('dbo.adl_flight_tmi') AND name = 'rz_removed')
BEGIN
    ALTER TABLE dbo.adl_flight_tmi ADD rz_removed BIT NULL DEFAULT 0;
    PRINT 'Added column rz_removed to adl_flight_tmi';
END
GO

-- Assignment timestamp
IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID('dbo.adl_flight_tmi') AND name = 'assigned_utc')
BEGIN
    ALTER TABLE dbo.adl_flight_tmi ADD assigned_utc DATETIME2(0) NULL;
    PRINT 'Added column assigned_utc to adl_flight_tmi';
END
GO

-- ============================================================================
-- 3. Update vw_adl_flights view to include new columns
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

    -- Times (standard)
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

    -- Times (GDT-specific)
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

    -- TMI (GDT-specific)
    tmi.program_id,
    tmi.ctl_prgm,
    tmi.aslot,
    tmi.program_delay_min,
    tmi.absolute_delay_min,
    tmi.schedule_variation_min,
    tmi.delay_capped,
    tmi.ctl_exempt,
    tmi.ctl_exempt_reason,
    tmi.gs_held,
    tmi.gs_release_utc,
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

-- ============================================================================
-- 4. Create/Update adl_flights as updatable view (if not exists as table)
-- ============================================================================

-- Check if adl_flights is a table or view
DECLARE @adl_flights_type NVARCHAR(10);
SELECT @adl_flights_type = type_desc
FROM sys.objects
WHERE name = 'adl_flights' AND schema_id = SCHEMA_ID('dbo');

IF @adl_flights_type IS NULL
BEGIN
    -- adl_flights doesn't exist - create a synonym to vw_adl_flights
    -- This allows existing code to work with both old table-based and new view-based schemas
    EXEC('CREATE SYNONYM dbo.adl_flights FOR dbo.vw_adl_flights');
    PRINT 'Created synonym dbo.adl_flights pointing to vw_adl_flights';
END
ELSE IF @adl_flights_type = 'VIEW'
BEGIN
    -- It's already a view, just ensure it points to the right place
    PRINT 'adl_flights already exists as a view - no action needed';
END
ELSE
BEGIN
    PRINT 'adl_flights exists as a ' + @adl_flights_type + ' - schema may need manual review';
END
GO

PRINT '';
PRINT '=== GDT Columns Migration Fix Complete ===';
PRINT 'Finished at: ' + CONVERT(VARCHAR, GETUTCDATE(), 120);
PRINT '';
PRINT 'IMPORTANT: After running this migration, you may need to:';
PRINT '1. Ensure adl_flights_gs and adl_flights_gdp sandbox tables have matching columns';
PRINT '2. Re-run the GDP/GS simulation to verify columns are being used correctly';
GO
