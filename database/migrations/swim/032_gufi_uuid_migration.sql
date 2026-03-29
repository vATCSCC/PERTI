-- ============================================================================
-- Migration 032: GUFI UUID Migration
-- Database: SWIM_API
-- Run as: jpeterson (DDL admin)
--
-- Purpose: Migrate GUFI from NVARCHAR(64) to UNIQUEIDENTIFIER (UUID v4)
--
-- Changes:
-- PART 1 - swim_flights column changes:
--   - Add gufi_legacy NVARCHAR(64) NULL
--   - Add gufi_created_utc DATETIME2(3) NULL with DEFAULT SYSUTCDATETIME()
--   - Backfill gufi_legacy from existing gufi
--   - Backfill gufi_created_utc from COALESCE(first_seen_utc, SYSUTCDATETIME())
--   - Drop index IX_swim_flights_gufi
--   - Drop column gufi
--   - Add column gufi UNIQUEIDENTIFIER NOT NULL with DEFAULT NEWID()
--   - Create unique index IX_swim_flights_gufi on new gufi
--   - Create filtered index IX_swim_flights_gufi_legacy on gufi_legacy WHERE NOT NULL
--
-- PART 2 - swim_handoff_log:
--   - Add gufi_legacy NVARCHAR(64) NULL
--   - Backfill from existing gufi
--
-- PART 3 - swim_acars_messages:
--   - Add gufi_legacy NVARCHAR(64) NULL
--   - Backfill from existing gufi WHERE NOT NULL
--
-- PART 4 - Recreate views:
--   - vw_swim_active_flights: Add gufi_legacy, gufi_created_utc
--   - vw_swim_tmi_controlled: Add gufi_legacy, gufi_created_utc
--
-- PART 5 - sp_Swim_GetFlightByGufi:
--   - Auto-detect UUID vs legacy format
--   - Use TRY_CONVERT(UNIQUEIDENTIFIER, @identifier)
--
-- PART 6 - sp_Swim_BulkUpsert v4:
--   - Replace gufi NVARCHAR(64) with gufi_legacy NVARCHAR(64) in OPENJSON
--   - Replace t.gufi = s.gufi with t.gufi_legacy = s.gufi_legacy in UPDATE
--   - Replace gufi with gufi_legacy in INSERT (gufi omitted for DEFAULT NEWID())
-- ============================================================================

-- ============================================================================
-- PART 1: swim_flights Column Changes
-- ============================================================================

-- Step 1.1: Add new columns
IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID('dbo.swim_flights') AND name = 'gufi_legacy')
BEGIN
    ALTER TABLE dbo.swim_flights
    ADD gufi_legacy NVARCHAR(64) NULL;
    PRINT 'Added column gufi_legacy to swim_flights';
END
ELSE
BEGIN
    PRINT 'Column gufi_legacy already exists in swim_flights - skipping';
END
GO

IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID('dbo.swim_flights') AND name = 'gufi_created_utc')
BEGIN
    ALTER TABLE dbo.swim_flights
    ADD gufi_created_utc DATETIME2(3) NULL DEFAULT SYSUTCDATETIME();
    PRINT 'Added column gufi_created_utc to swim_flights';
END
ELSE
BEGIN
    PRINT 'Column gufi_created_utc already exists in swim_flights - skipping';
END
GO

-- Step 1.2: Backfill gufi_legacy from existing gufi
UPDATE dbo.swim_flights
SET gufi_legacy = gufi
WHERE gufi IS NOT NULL
  AND gufi_legacy IS NULL;
PRINT 'Backfilled gufi_legacy from gufi column';
GO

-- Step 1.3: Backfill gufi_created_utc
UPDATE dbo.swim_flights
SET gufi_created_utc = COALESCE(first_seen_utc, SYSUTCDATETIME())
WHERE gufi_created_utc IS NULL
  AND gufi IS NOT NULL;
PRINT 'Backfilled gufi_created_utc';
GO

-- Step 1.4: Drop old gufi index
IF EXISTS (SELECT * FROM sys.indexes WHERE name = 'IX_swim_flights_gufi' AND object_id = OBJECT_ID('dbo.swim_flights'))
BEGIN
    DROP INDEX IX_swim_flights_gufi ON dbo.swim_flights;
    PRINT 'Dropped index IX_swim_flights_gufi';
END
ELSE
BEGIN
    PRINT 'Index IX_swim_flights_gufi does not exist - skipping';
END
GO

-- Step 1.5: Drop old gufi column and add new UUID column
-- Note: We must do this in one ALTER TABLE to avoid issues
ALTER TABLE dbo.swim_flights
DROP COLUMN gufi;
PRINT 'Dropped old gufi column (NVARCHAR)';
GO

ALTER TABLE dbo.swim_flights
ADD gufi UNIQUEIDENTIFIER NOT NULL DEFAULT NEWID();
PRINT 'Added new gufi column as UNIQUEIDENTIFIER with DEFAULT NEWID()';
GO

-- Step 1.6: Create unique index on new gufi UUID column
CREATE UNIQUE INDEX IX_swim_flights_gufi
ON dbo.swim_flights (gufi);
PRINT 'Created unique index IX_swim_flights_gufi on new gufi column';
GO

-- Step 1.7: Create filtered index on gufi_legacy
CREATE NONCLUSTERED INDEX IX_swim_flights_gufi_legacy
ON dbo.swim_flights (gufi_legacy)
WHERE gufi_legacy IS NOT NULL;
PRINT 'Created filtered index IX_swim_flights_gufi_legacy';
GO

-- ============================================================================
-- PART 2: swim_handoff_log Column Changes
-- ============================================================================

IF EXISTS (SELECT * FROM sys.tables WHERE name = 'swim_handoff_log')
BEGIN
    IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID('dbo.swim_handoff_log') AND name = 'gufi_legacy')
    BEGIN
        ALTER TABLE dbo.swim_handoff_log
        ADD gufi_legacy NVARCHAR(64) NULL;
        PRINT 'Added column gufi_legacy to swim_handoff_log';

        -- Backfill from existing gufi
        UPDATE dbo.swim_handoff_log
        SET gufi_legacy = gufi
        WHERE gufi IS NOT NULL
          AND gufi_legacy IS NULL;
        PRINT 'Backfilled gufi_legacy in swim_handoff_log from gufi column';
    END
    ELSE
    BEGIN
        PRINT 'Column gufi_legacy already exists in swim_handoff_log - skipping';
    END
END
ELSE
BEGIN
    PRINT 'Table swim_handoff_log does not exist - skipping PART 2';
END
GO

-- ============================================================================
-- PART 3: swim_acars_messages Column Changes
-- ============================================================================

IF EXISTS (SELECT * FROM sys.tables WHERE name = 'swim_acars_messages')
BEGIN
    IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID('dbo.swim_acars_messages') AND name = 'gufi_legacy')
    BEGIN
        ALTER TABLE dbo.swim_acars_messages
        ADD gufi_legacy NVARCHAR(64) NULL;
        PRINT 'Added column gufi_legacy to swim_acars_messages';

        -- Backfill from existing gufi WHERE NOT NULL
        UPDATE dbo.swim_acars_messages
        SET gufi_legacy = gufi
        WHERE gufi IS NOT NULL
          AND gufi_legacy IS NULL;
        PRINT 'Backfilled gufi_legacy in swim_acars_messages from gufi column';
    END
    ELSE
    BEGIN
        PRINT 'Column gufi_legacy already exists in swim_acars_messages - skipping';
    END
END
ELSE
BEGIN
    PRINT 'Table swim_acars_messages does not exist - skipping PART 3';
END
GO

-- ============================================================================
-- PART 4: Recreate Views
-- ============================================================================

CREATE OR ALTER VIEW dbo.vw_swim_active_flights AS
SELECT
    flight_uid,
    gufi,
    gufi_legacy,
    gufi_created_utc,
    callsign,
    fp_dept_icao,
    fp_dest_icao,
    fp_dest_artcc,
    phase,
    lat,
    lon,
    altitude_ft,
    groundspeed_kts,
    eta_runway_utc,
    gs_held,
    ctl_type,
    aircraft_type,
    weight_class
FROM dbo.swim_flights
WHERE is_active = 1;
GO

PRINT 'Recreated view vw_swim_active_flights with gufi_legacy + gufi_created_utc';
GO

CREATE OR ALTER VIEW dbo.vw_swim_tmi_controlled AS
SELECT
    flight_uid,
    gufi,
    gufi_legacy,
    gufi_created_utc,
    callsign,
    fp_dept_icao,
    fp_dest_icao,
    phase,
    gs_held,
    ctl_type,
    ctl_prgm,
    slot_time_utc,
    delay_minutes,
    is_exempt,
    exempt_reason
FROM dbo.swim_flights
WHERE is_active = 1
  AND (gs_held = 1 OR ctl_type IS NOT NULL);
GO

PRINT 'Recreated view vw_swim_tmi_controlled with gufi_legacy + gufi_created_utc';
GO

-- ============================================================================
-- PART 5: Rewrite sp_Swim_GetFlightByGufi
-- ============================================================================

CREATE OR ALTER PROCEDURE dbo.sp_Swim_GetFlightByGufi
    @identifier NVARCHAR(MAX)
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @uuid_guid UNIQUEIDENTIFIER;

    -- Try to convert identifier to UUID
    SET @uuid_guid = TRY_CONVERT(UNIQUEIDENTIFIER, @identifier);

    -- If conversion succeeded, search by UUID; otherwise search by legacy format
    IF @uuid_guid IS NOT NULL
    BEGIN
        -- UUID format (new)
        SELECT
            flight_uid,
            gufi,
            gufi_legacy,
            gufi_created_utc,
            callsign,
            cid,
            flight_id,
            fp_dept_icao,
            fp_dest_icao,
            phase,
            lat,
            lon,
            altitude_ft,
            groundspeed_kts,
            eta_utc,
            eta_runway_utc,
            etd_utc,
            out_utc,
            off_utc,
            on_utc,
            in_utc,
            is_active,
            gs_held,
            ctl_type,
            ctl_prgm,
            slot_time_utc,
            delay_minutes,
            is_exempt,
            exempt_reason,
            aircraft_type,
            airline_icao,
            airline_name,
            last_sync_utc
        FROM dbo.swim_flights
        WHERE gufi = @uuid_guid;
    END
    ELSE
    BEGIN
        -- Legacy format (NVARCHAR)
        SELECT
            flight_uid,
            gufi,
            gufi_legacy,
            gufi_created_utc,
            callsign,
            cid,
            flight_id,
            fp_dept_icao,
            fp_dest_icao,
            phase,
            lat,
            lon,
            altitude_ft,
            groundspeed_kts,
            eta_utc,
            eta_runway_utc,
            etd_utc,
            out_utc,
            off_utc,
            on_utc,
            in_utc,
            is_active,
            gs_held,
            ctl_type,
            ctl_prgm,
            slot_time_utc,
            delay_minutes,
            is_exempt,
            exempt_reason,
            aircraft_type,
            airline_icao,
            airline_name,
            last_sync_utc
        FROM dbo.swim_flights
        WHERE gufi_legacy = @identifier;
    END
END;
GO

PRINT 'Recreated sp_Swim_GetFlightByGufi with auto-detection of UUID vs legacy format';
GO

-- ============================================================================
-- PART 6: Recreate sp_Swim_BulkUpsert v4
-- ============================================================================

CREATE OR ALTER PROCEDURE dbo.sp_Swim_BulkUpsert
    @Json NVARCHAR(MAX)
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;

    DECLARE @inserted INT = 0, @updated INT = 0, @deleted INT = 0, @skipped INT = 0;
    DECLARE @total INT = 0;
    DECLARE @start DATETIME2 = SYSUTCDATETIME();
    DECLARE @merge_output TABLE (action NVARCHAR(10), flight_uid BIGINT);

    BEGIN TRY
        BEGIN TRANSACTION;

        -- =====================================================================
        -- Step 1: Parse JSON into temp table with OPENJSON WITH
        -- =====================================================================
        SELECT
            j.*,
            -- Row hash on 21 key volatile columns (v4.0)
            HASHBYTES('SHA1', CONCAT(
                ISNULL(CAST(j.lat AS VARCHAR(20)), ''), '|',
                ISNULL(CAST(j.lon AS VARCHAR(20)), ''), '|',
                ISNULL(CAST(j.altitude_ft AS VARCHAR(10)), ''), '|',
                ISNULL(CAST(j.groundspeed_kts AS VARCHAR(10)), ''), '|',
                ISNULL(CAST(j.heading_deg AS VARCHAR(10)), ''), '|',
                ISNULL(j.phase, ''), '|',
                ISNULL(CAST(j.is_active AS VARCHAR(1)), ''), '|',
                ISNULL(j.current_artcc, ''), '|',
                ISNULL(CONVERT(VARCHAR(20), j.eta_utc, 126), ''), '|',
                ISNULL(CONVERT(VARCHAR(20), j.etd_utc, 126), ''), '|',
                ISNULL(CONVERT(VARCHAR(20), j.out_utc, 126), ''), '|',
                ISNULL(CONVERT(VARCHAR(20), j.off_utc, 126), ''), '|',
                ISNULL(CONVERT(VARCHAR(20), j.on_utc, 126), ''), '|',
                ISNULL(CONVERT(VARCHAR(20), j.in_utc, 126), ''), '|',
                ISNULL(CONVERT(VARCHAR(20), j.ctd_utc, 126), ''), '|',
                ISNULL(CONVERT(VARCHAR(20), j.cta_utc, 126), ''), '|',
                ISNULL(j.ctl_type, ''), '|',
                ISNULL(CAST(j.delay_minutes AS VARCHAR(10)), ''), '|',
                ISNULL(CAST(j.gs_held AS VARCHAR(1)), ''), '|',
                ISNULL(LEFT(j.fp_route, 200), ''), '|',
                ISNULL(j.resolved_nat_track, '')
            )) AS row_hash
        INTO #flights
        FROM OPENJSON(@Json) WITH (
            -- Identity (6) - NOTE: gufi_legacy replaces gufi in v4
            flight_uid          INT,
            flight_key          NVARCHAR(64),
            gufi_legacy         NVARCHAR(64),
            callsign            NVARCHAR(16),
            cid                 INT,
            flight_id           NVARCHAR(16),
            -- Position (17)
            lat                 DECIMAL(9,6),
            lon                 DECIMAL(9,6),
            altitude_ft         INT,
            heading_deg         SMALLINT,
            groundspeed_kts     SMALLINT,
            vertical_rate_fpm   SMALLINT,
            true_airspeed_kts   SMALLINT,
            mach_number         DECIMAL(4,3),
            altitude_assigned   INT,
            altitude_cleared    INT,
            track_deg           SMALLINT,
            qnh_in_hg          DECIMAL(5,2),
            qnh_mb             DECIMAL(6,1),
            route_dist_to_dest_nm DECIMAL(8,1),
            route_pct_complete  DECIMAL(5,2),
            next_waypoint_name  NVARCHAR(16),
            dist_to_next_waypoint_nm DECIMAL(8,1),
            -- Flight plan (27)
            fp_dept_icao        NVARCHAR(8),
            fp_dest_icao        NVARCHAR(8),
            fp_alt_icao         NVARCHAR(8),
            fp_altitude_ft      INT,
            fp_tas_kts          SMALLINT,
            fp_route            NVARCHAR(MAX),
            fp_remarks          NVARCHAR(MAX),
            fp_rule             NVARCHAR(4),
            fp_dept_artcc       NVARCHAR(8),
            fp_dest_artcc       NVARCHAR(8),
            fp_dept_tracon      NVARCHAR(8),
            fp_dest_tracon      NVARCHAR(8),
            dfix                NVARCHAR(8),
            dp_name             NVARCHAR(16),
            afix                NVARCHAR(8),
            star_name           NVARCHAR(16),
            dep_runway          NVARCHAR(8),
            arr_runway          NVARCHAR(8),
            equipment_qualifier NVARCHAR(8),
            approach_procedure  NVARCHAR(16),
            fp_route_expanded   NVARCHAR(MAX),
            fp_fuel_minutes     INT,
            dtrsn               NVARCHAR(32),
            strsn               NVARCHAR(32),
            waypoint_count      INT,
            parse_status        NVARCHAR(16),
            simbrief_ofp_id     NVARCHAR(32),
            resolved_nat_track  NVARCHAR(8),
            nat_track_resolved_at NVARCHAR(30),
            nat_track_source    NVARCHAR(8),
            -- State / airspace (15)
            phase               NVARCHAR(16),
            is_active           BIT,
            dist_to_dest_nm     DECIMAL(8,2),
            dist_flown_nm       DECIMAL(8,2),
            pct_complete        DECIMAL(5,2),
            gcd_nm              DECIMAL(8,2),
            route_total_nm      DECIMAL(8,2),
            current_artcc       NVARCHAR(8),
            current_tracon      NVARCHAR(8),
            current_zone        NVARCHAR(8),
            current_zone_airport NVARCHAR(8),
            current_sector_low  NVARCHAR(16),
            current_sector_high NVARCHAR(16),
            weather_impact      NVARCHAR(64),
            weather_alert_ids   NVARCHAR(MAX),
            -- Times (24)
            first_seen_utc      DATETIME2(0),
            last_seen_utc       DATETIME2(0),
            logon_time_utc      DATETIME2(0),
            eta_utc             DATETIME2(0),
            eta_runway_utc      DATETIME2(0),
            eta_source          NVARCHAR(16),
            eta_method          NVARCHAR(16),
            etd_utc             DATETIME2(0),
            out_utc             DATETIME2(0),
            off_utc             DATETIME2(0),
            on_utc              DATETIME2(0),
            in_utc              DATETIME2(0),
            ete_minutes         SMALLINT,
            ctd_utc             DATETIME2(0),
            cta_utc             DATETIME2(0),
            edct_utc            DATETIME2(0),
            sta_utc             DATETIME2(0),
            etd_runway_utc      DATETIME2(0),
            etd_source          NVARCHAR(16),
            octd_utc            DATETIME2(0),
            octa_utc            DATETIME2(0),
            ate_minutes         DECIMAL(8,1),
            eta_confidence      NVARCHAR(8),
            eta_wind_component_kts SMALLINT,
            -- TMI control (21)
            gs_held             BIT,
            gs_release_utc      DATETIME2(0),
            ctl_type            NVARCHAR(8),
            ctl_prgm            NVARCHAR(16),
            ctl_element         NVARCHAR(8),
            is_exempt           BIT,
            exempt_reason       NVARCHAR(64),
            slot_time_utc       DATETIME2(0),
            slot_status         NVARCHAR(16),
            program_id          INT,
            slot_id             INT,
            delay_minutes       SMALLINT,
            delay_status        NVARCHAR(16),
            ctl_exempt          BIT,
            ctl_exempt_reason   NVARCHAR(64),
            aslot               NVARCHAR(16),
            delay_source        NVARCHAR(16),
            is_popup            BIT,
            popup_detected_utc  DATETIME2(0),
            absolute_delay_min  INT,
            schedule_variation_min INT,
            -- Aircraft (11)
            aircraft_type       NVARCHAR(16),
            aircraft_icao       NVARCHAR(8),
            aircraft_faa        NVARCHAR(8),
            weight_class        NVARCHAR(4),
            wake_category       NVARCHAR(4),
            engine_type         NVARCHAR(4),
            airline_icao        NVARCHAR(8),
            airline_name        NVARCHAR(64),
            engine_count        SMALLINT,
            cruise_tas_kts      SMALLINT,
            ceiling_ft          INT
        ) AS j;

        SET @total = (SELECT COUNT(*) FROM #flights);

        -- =====================================================================
        -- Step 2: MERGE with row-hash comparison
        -- =====================================================================
        MERGE dbo.swim_flights AS t
        USING #flights AS s ON t.flight_uid = s.flight_uid

        WHEN MATCHED AND (t.row_hash IS NULL OR t.row_hash <> s.row_hash) THEN UPDATE SET
            -- Identity (gufi is NEVER updated - only gufi_legacy)
            t.flight_key = s.flight_key, t.gufi_legacy = s.gufi_legacy,
            t.callsign = s.callsign, t.cid = s.cid, t.flight_id = s.flight_id,
            -- Position
            t.lat = s.lat, t.lon = s.lon, t.altitude_ft = s.altitude_ft,
            t.heading_deg = s.heading_deg, t.groundspeed_kts = s.groundspeed_kts,
            t.vertical_rate_fpm = s.vertical_rate_fpm,
            t.true_airspeed_kts = s.true_airspeed_kts, t.mach_number = s.mach_number,
            t.altitude_assigned = s.altitude_assigned, t.altitude_cleared = s.altitude_cleared,
            t.track_deg = s.track_deg, t.qnh_in_hg = s.qnh_in_hg, t.qnh_mb = s.qnh_mb,
            t.route_dist_to_dest_nm = s.route_dist_to_dest_nm,
            t.route_pct_complete = s.route_pct_complete,
            t.next_waypoint_name = s.next_waypoint_name,
            t.dist_to_next_waypoint_nm = s.dist_to_next_waypoint_nm,
            -- Flight plan
            t.fp_dept_icao = s.fp_dept_icao, t.fp_dest_icao = s.fp_dest_icao,
            t.fp_alt_icao = s.fp_alt_icao, t.fp_altitude_ft = s.fp_altitude_ft,
            t.fp_tas_kts = s.fp_tas_kts, t.fp_route = s.fp_route,
            t.fp_remarks = s.fp_remarks, t.fp_rule = s.fp_rule,
            t.fp_dept_artcc = s.fp_dept_artcc, t.fp_dest_artcc = s.fp_dest_artcc,
            t.fp_dept_tracon = s.fp_dept_tracon, t.fp_dest_tracon = s.fp_dest_tracon,
            t.dfix = s.dfix, t.dp_name = s.dp_name,
            t.afix = s.afix, t.star_name = s.star_name,
            t.dep_runway = s.dep_runway, t.arr_runway = s.arr_runway,
            t.equipment_qualifier = s.equipment_qualifier,
            t.approach_procedure = s.approach_procedure,
            t.fp_route_expanded = s.fp_route_expanded,
            t.fp_fuel_minutes = s.fp_fuel_minutes,
            t.dtrsn = s.dtrsn, t.strsn = s.strsn,
            t.waypoint_count = s.waypoint_count, t.parse_status = s.parse_status,
            t.simbrief_ofp_id = s.simbrief_ofp_id,
            t.resolved_nat_track = s.resolved_nat_track,
            t.nat_track_resolved_at = TRY_CAST(s.nat_track_resolved_at AS DATETIME2(0)),
            t.nat_track_source = s.nat_track_source,
            -- FIXM aliases (from same JSON data)
            t.sid = s.dp_name, t.star = s.star_name,
            t.departure_point = s.dfix, t.arrival_point = s.afix,
            t.alternate_aerodrome = s.fp_alt_icao,
            t.departure_runway = s.dep_runway, t.arrival_runway = s.arr_runway,
            t.current_airspace = s.current_artcc,
            t.current_sector = s.current_sector_low,
            t.estimated_time_of_departure = s.etd_utc,
            t.original_ctd = s.octd_utc,
            t.controlled_time_of_departure = s.ctd_utc,
            t.controlled_time_of_arrival = s.cta_utc,
            t.slot_time = s.slot_time_utc,
            t.control_type = s.ctl_type,
            t.control_element = s.ctl_element,
            t.program_name = s.ctl_prgm,
            t.delay_value = s.delay_minutes,
            t.ground_stop_held = s.gs_held,
            t.exempt_indicator = s.is_exempt,
            -- State / airspace
            t.phase = s.phase, t.is_active = s.is_active,
            t.dist_to_dest_nm = s.dist_to_dest_nm, t.dist_flown_nm = s.dist_flown_nm,
            t.pct_complete = s.pct_complete, t.gcd_nm = s.gcd_nm,
            t.route_total_nm = s.route_total_nm,
            t.current_artcc = s.current_artcc, t.current_tracon = s.current_tracon,
            t.current_zone = s.current_zone, t.current_zone_airport = s.current_zone_airport,
            t.current_sector_low = s.current_sector_low,
            t.current_sector_high = s.current_sector_high,
            t.weather_impact = s.weather_impact, t.weather_alert_ids = s.weather_alert_ids,
            -- Times
            t.first_seen_utc = s.first_seen_utc, t.last_seen_utc = s.last_seen_utc,
            t.logon_time_utc = s.logon_time_utc,
            t.eta_utc = s.eta_utc, t.eta_runway_utc = s.eta_runway_utc,
            t.eta_source = s.eta_source, t.eta_method = s.eta_method,
            t.etd_utc = s.etd_utc,
            t.out_utc = s.out_utc, t.off_utc = s.off_utc,
            t.on_utc = s.on_utc, t.in_utc = s.in_utc,
            t.ete_minutes = s.ete_minutes,
            t.ctd_utc = s.ctd_utc, t.cta_utc = s.cta_utc, t.edct_utc = s.edct_utc,
            t.sta_utc = s.sta_utc, t.etd_runway_utc = s.etd_runway_utc,
            t.etd_source = s.etd_source,
            t.octa_utc = s.octa_utc,
            t.ate_minutes = s.ate_minutes,
            t.eta_confidence = s.eta_confidence,
            t.eta_wind_component_kts = s.eta_wind_component_kts,
            -- TMI control
            t.gs_held = s.gs_held, t.gs_release_utc = s.gs_release_utc,
            t.ctl_type = s.ctl_type, t.ctl_prgm = s.ctl_prgm, t.ctl_element = s.ctl_element,
            t.is_exempt = s.is_exempt, t.exempt_reason = s.exempt_reason,
            t.slot_time_utc = s.slot_time_utc, t.slot_status = s.slot_status,
            t.program_id = s.program_id, t.slot_id = s.slot_id,
            t.delay_minutes = s.delay_minutes, t.delay_status = s.delay_status,
            t.ctl_exempt = s.ctl_exempt, t.ctl_exempt_reason = s.ctl_exempt_reason,
            t.aslot = s.aslot, t.delay_source = s.delay_source,
            t.is_popup = s.is_popup, t.popup_detected_utc = s.popup_detected_utc,
            t.absolute_delay_min = s.absolute_delay_min,
            t.schedule_variation_min = s.schedule_variation_min,
            -- Aircraft
            t.aircraft_type = s.aircraft_type, t.aircraft_icao = s.aircraft_icao,
            t.aircraft_faa = s.aircraft_faa, t.weight_class = s.weight_class,
            t.wake_category = s.wake_category, t.engine_type = s.engine_type,
            t.airline_icao = s.airline_icao, t.airline_name = s.airline_name,
            t.engine_count = s.engine_count,
            t.cruise_tas_kts = s.cruise_tas_kts, t.ceiling_ft = s.ceiling_ft,
            -- Metadata
            t.row_hash = s.row_hash,
            t.last_sync_utc = SYSUTCDATETIME()

        WHEN NOT MATCHED BY TARGET THEN INSERT (
            flight_uid, flight_key, gufi_legacy, callsign, cid, flight_id,
            lat, lon, altitude_ft, heading_deg, groundspeed_kts, vertical_rate_fpm,
            true_airspeed_kts, mach_number, altitude_assigned, altitude_cleared,
            track_deg, qnh_in_hg, qnh_mb,
            route_dist_to_dest_nm, route_pct_complete,
            next_waypoint_name, dist_to_next_waypoint_nm,
            fp_dept_icao, fp_dest_icao, fp_alt_icao, fp_altitude_ft, fp_tas_kts,
            fp_route, fp_remarks, fp_rule,
            fp_dept_artcc, fp_dest_artcc, fp_dept_tracon, fp_dest_tracon,
            dfix, dp_name, afix, star_name, dep_runway, arr_runway,
            equipment_qualifier, approach_procedure,
            fp_route_expanded, fp_fuel_minutes, dtrsn, strsn,
            waypoint_count, parse_status, simbrief_ofp_id,
            resolved_nat_track, nat_track_resolved_at, nat_track_source,
            sid, star, departure_point, arrival_point,
            alternate_aerodrome, departure_runway, arrival_runway,
            current_airspace, current_sector,
            estimated_time_of_departure, original_ctd,
            controlled_time_of_departure, controlled_time_of_arrival,
            slot_time, control_type, control_element, program_name,
            delay_value, ground_stop_held, exempt_indicator,
            phase, is_active, dist_to_dest_nm, dist_flown_nm, pct_complete,
            gcd_nm, route_total_nm, current_artcc, current_tracon, current_zone,
            current_zone_airport, current_sector_low, current_sector_high,
            weather_impact, weather_alert_ids,
            first_seen_utc, last_seen_utc, logon_time_utc,
            eta_utc, eta_runway_utc, eta_source, eta_method, etd_utc,
            out_utc, off_utc, on_utc, in_utc, ete_minutes,
            ctd_utc, cta_utc, edct_utc,
            sta_utc, etd_runway_utc, etd_source, octa_utc,
            ate_minutes, eta_confidence, eta_wind_component_kts,
            gs_held, gs_release_utc, ctl_type, ctl_prgm, ctl_element,
            is_exempt, exempt_reason, slot_time_utc, slot_status,
            program_id, slot_id, delay_minutes, delay_status,
            ctl_exempt, ctl_exempt_reason, aslot, delay_source,
            is_popup, popup_detected_utc, absolute_delay_min, schedule_variation_min,
            aircraft_type, aircraft_icao, aircraft_faa, weight_class,
            wake_category, engine_type, airline_icao, airline_name,
            engine_count, cruise_tas_kts, ceiling_ft,
            row_hash, last_sync_utc
        ) VALUES (
            s.flight_uid, s.flight_key, s.gufi_legacy, s.callsign, s.cid, s.flight_id,
            s.lat, s.lon, s.altitude_ft, s.heading_deg, s.groundspeed_kts, s.vertical_rate_fpm,
            s.true_airspeed_kts, s.mach_number, s.altitude_assigned, s.altitude_cleared,
            s.track_deg, s.qnh_in_hg, s.qnh_mb,
            s.route_dist_to_dest_nm, s.route_pct_complete,
            s.next_waypoint_name, s.dist_to_next_waypoint_nm,
            s.fp_dept_icao, s.fp_dest_icao, s.fp_alt_icao, s.fp_altitude_ft, s.fp_tas_kts,
            s.fp_route, s.fp_remarks, s.fp_rule,
            s.fp_dept_artcc, s.fp_dest_artcc, s.fp_dept_tracon, s.fp_dest_tracon,
            s.dfix, s.dp_name, s.afix, s.star_name, s.dep_runway, s.arr_runway,
            s.equipment_qualifier, s.approach_procedure,
            s.fp_route_expanded, s.fp_fuel_minutes, s.dtrsn, s.strsn,
            s.waypoint_count, s.parse_status, s.simbrief_ofp_id,
            s.resolved_nat_track, TRY_CAST(s.nat_track_resolved_at AS DATETIME2(0)), s.nat_track_source,
            s.dp_name, s.star_name, s.dfix, s.afix,            -- FIXM aliases
            s.fp_alt_icao, s.dep_runway, s.arr_runway,          -- FIXM aliases
            s.current_artcc, s.current_sector_low,               -- FIXM aliases
            s.etd_utc, s.octd_utc,                              -- FIXM aliases
            s.ctd_utc, s.cta_utc,                               -- FIXM aliases
            s.slot_time_utc, s.ctl_type, s.ctl_element, s.ctl_prgm, -- FIXM aliases
            s.delay_minutes, s.gs_held, s.is_exempt,             -- FIXM aliases
            s.phase, s.is_active, s.dist_to_dest_nm, s.dist_flown_nm, s.pct_complete,
            s.gcd_nm, s.route_total_nm, s.current_artcc, s.current_tracon, s.current_zone,
            s.current_zone_airport, s.current_sector_low, s.current_sector_high,
            s.weather_impact, s.weather_alert_ids,
            s.first_seen_utc, s.last_seen_utc, s.logon_time_utc,
            s.eta_utc, s.eta_runway_utc, s.eta_source, s.eta_method, s.etd_utc,
            s.out_utc, s.off_utc, s.on_utc, s.in_utc, s.ete_minutes,
            s.ctd_utc, s.cta_utc, s.edct_utc,
            s.sta_utc, s.etd_runway_utc, s.etd_source, s.octa_utc,
            s.ate_minutes, s.eta_confidence, s.eta_wind_component_kts,
            s.gs_held, s.gs_release_utc, s.ctl_type, s.ctl_prgm, s.ctl_element,
            s.is_exempt, s.exempt_reason, s.slot_time_utc, s.slot_status,
            s.program_id, s.slot_id, s.delay_minutes, s.delay_status,
            s.ctl_exempt, s.ctl_exempt_reason, s.aslot, s.delay_source,
            s.is_popup, s.popup_detected_utc, s.absolute_delay_min, s.schedule_variation_min,
            s.aircraft_type, s.aircraft_icao, s.aircraft_faa, s.weight_class,
            s.wake_category, s.engine_type, s.airline_icao, s.airline_name,
            s.engine_count, s.cruise_tas_kts, s.ceiling_ft,
            s.row_hash, SYSUTCDATETIME()
        )
        OUTPUT $action, ISNULL(inserted.flight_uid, deleted.flight_uid)
        INTO @merge_output(action, flight_uid);

        -- =====================================================================
        -- Step 3: Count results
        -- =====================================================================
        SELECT @inserted = SUM(CASE WHEN action = 'INSERT' THEN 1 ELSE 0 END),
               @updated = SUM(CASE WHEN action = 'UPDATE' THEN 1 ELSE 0 END)
        FROM @merge_output;

        SET @skipped = @total - ISNULL(@inserted, 0) - ISNULL(@updated, 0);

        -- =====================================================================
        -- Step 4: Emit to change feed (append-only) with column tracking
        -- =====================================================================
        INSERT INTO dbo.swim_change_feed (event_type, entity_type, entity_id)
        SELECT
            CASE WHEN action = 'INSERT' THEN 'flight_insert' ELSE 'flight_update' END,
            'swim_flights',
            CAST(flight_uid AS NVARCHAR(100))
        FROM @merge_output;

        -- =====================================================================
        -- Step 5: Delete stale flights (inactive >2 hours)
        -- =====================================================================
        DELETE FROM dbo.swim_flights
        WHERE is_active = 0
          AND last_sync_utc < DATEADD(HOUR, -2, SYSUTCDATETIME());

        SET @deleted = @@ROWCOUNT;

        DROP TABLE #flights;

        COMMIT TRANSACTION;

        -- Return stats
        SELECT
            ISNULL(@inserted, 0) AS inserted,
            ISNULL(@updated, 0)  AS updated,
            @deleted             AS deleted,
            @skipped             AS skipped,
            @total               AS total,
            DATEDIFF(MILLISECOND, @start, SYSUTCDATETIME()) AS elapsed_ms;

    END TRY
    BEGIN CATCH
        IF @@TRANCOUNT > 0 ROLLBACK TRANSACTION;
        IF OBJECT_ID('tempdb..#flights') IS NOT NULL DROP TABLE #flights;
        THROW;
    END CATCH;
END;
GO

PRINT 'Created sp_Swim_BulkUpsert v4.0 (GUFI UUID migration)';
GO

-- ============================================================================
-- Migration Summary
-- ============================================================================

PRINT '';
PRINT '=== MIGRATION 032 SUMMARY ===';
PRINT 'Successfully completed GUFI UUID migration:';
PRINT '  ✓ PART 1: swim_flights schema updated (gufi UNIQUEIDENTIFIER, gufi_legacy NVARCHAR)';
PRINT '  ✓ PART 2: swim_handoff_log schema updated (gufi_legacy NVARCHAR)';
PRINT '  ✓ PART 3: swim_acars_messages schema updated (gufi_legacy NVARCHAR)';
PRINT '  ✓ PART 4: Views recreated (vw_swim_active_flights, vw_swim_tmi_controlled)';
PRINT '  ✓ PART 5: sp_Swim_GetFlightByGufi rewritten with UUID auto-detection';
PRINT '  ✓ PART 6: sp_Swim_BulkUpsert v4.0 created with gufi_legacy support';
PRINT '=== END MIGRATION ===';
GO
