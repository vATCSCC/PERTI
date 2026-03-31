# GUFI UUID Migration Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Migrate SWIM GUFI from mutable human-readable format (`VAT-YYYYMMDD-CALLSIGN-DEPT-DEST`) to immutable UUID v4 per ICAO Doc 9965 / FIXM 4.2+.

**Architecture:** Database column changes first (UNIQUEIDENTIFIER with DEFAULT NEWID()), then SP v4, then PHP sync/API changes, then SDK + OpenAPI updates. Big-bang deployment — DB migration in SSMS, then PHP push to main. Legacy format preserved in `gufi_legacy` column for backward compatibility.

**Tech Stack:** Azure SQL (UNIQUEIDENTIFIER, OPENJSON, MERGE), PHP 8.2 (sqlsrv), UUID v4 regex auto-detection for backward-compatible lookups.

**Spec:** `docs/superpowers/specs/2026-03-29-gufi-uuid-migration-design.md`

---

## File Structure

### New files
| File | Responsibility |
|------|---------------|
| `database/migrations/swim/032_gufi_uuid_migration.sql` | Schema changes: columns, indexes, views, SP v4, SP rewrite |

### Modified files
| File | Responsibility |
|------|---------------|
| `load/swim_config.php` | Rename GUFI generator, add format helper + lookup helper |
| `scripts/swim_sync.php` | Pass `gufi_legacy` instead of `gufi` in JSON to SP |
| `api/swim/v1/flight.php` | Auto-detect UUID vs legacy format for lookup |
| `api/swim/v1/flights.php` | Update response formatting (FIXM metadata + legacy) |
| `api/swim/v1/ingest/adl.php` | Replace GUFI-based existence check with flight_uid |
| `api/swim/v1/ingest/cdm.php` | Auto-detect GUFI format for lookup |
| `api/swim/v1/ingest/simtraffic.php` | Auto-detect GUFI format for lookup |
| `api/swim/v1/ingest/vnas/track.php` | Auto-detect GUFI format for lookup |
| `api/swim/v1/ingest/vnas/handoff.php` | Auto-detect GUFI format + store gufi_legacy in log |
| `api/swim/v1/ingest/vnas/tags.php` | Auto-detect GUFI format for lookup |
| `api/swim/v1/ingest/metering.php` | Auto-detect GUFI format for lookup |
| `scripts/viff_cdm_poll_daemon.php` | Query gufi_legacy column instead of gufi |
| `sdk/php/src/Models/Flight.php` | Add gufi_legacy, gufi_created_utc properties |
| `sdk/python/swim_client/models.py` | Add optional gufi_legacy, gufi_created_utc fields |
| `sdk/javascript/src/types.ts` | Add GufiMetadata interface, optional fields |
| `sdk/csharp/SwimClient/Models/Flight.cs` | Add nullable GufiLegacy, GufiCreatedUtc properties |
| `sdk/java/swim-client/src/main/java/org/vatsim/swim/model/Flight.java` | Add gufiLegacy, gufiCreatedUtc fields + getters/setters |
| `docs/swim/openapi.yaml` | Update GUFI description, Flight schema, add GufiMetadata |

---

### Task 1: Database Migration — Schema + Columns + Indexes

**Files:**
- Create: `database/migrations/swim/032_gufi_uuid_migration.sql`

This task creates the entire migration SQL file. It will be run manually in SSMS with `jpeterson` admin credentials against `SWIM_API` database.

- [ ] **Step 1: Write the migration SQL file**

```sql
-- ============================================================================
-- Migration 032: GUFI UUID Migration
--
-- Migrates swim_flights.gufi from NVARCHAR(64) (human-readable VAT-... format)
-- to UNIQUEIDENTIFIER (UUID v4) per ICAO Doc 9965 / FIXM 4.2+.
--
-- Preserves legacy format in gufi_legacy column for backward compatibility.
-- Adds gufi_created_utc for FIXM metadata.
--
-- Run manually in SSMS with jpeterson admin credentials.
-- Database: SWIM_API
-- ============================================================================

-- ============================================================================
-- PART 1: swim_flights — Column changes
-- ============================================================================

-- Step 1a: Add new columns
ALTER TABLE dbo.swim_flights ADD gufi_legacy NVARCHAR(64) NULL;
ALTER TABLE dbo.swim_flights ADD gufi_created_utc DATETIME2(3) NULL
    CONSTRAINT DF_swim_flights_gufi_created_utc DEFAULT SYSUTCDATETIME();
GO

-- Step 1b: Backfill gufi_legacy from existing gufi values
UPDATE dbo.swim_flights SET gufi_legacy = gufi WHERE gufi IS NOT NULL;
GO

-- Step 1c: Backfill gufi_created_utc from best available timestamp
UPDATE dbo.swim_flights
SET gufi_created_utc = COALESCE(first_seen_utc, inserted_utc, SYSUTCDATETIME())
WHERE gufi_created_utc IS NULL;
GO

-- Step 1d: Drop old filtered index on gufi
DROP INDEX IF EXISTS IX_swim_flights_gufi ON dbo.swim_flights;
GO

-- Step 1e: Drop existing gufi column (NVARCHAR with legacy values)
ALTER TABLE dbo.swim_flights DROP COLUMN gufi;
GO

-- Step 1f: Add new gufi as UNIQUEIDENTIFIER with DEFAULT NEWID()
-- This auto-generates a UUID v4 for all existing rows
ALTER TABLE dbo.swim_flights ADD gufi UNIQUEIDENTIFIER NOT NULL
    CONSTRAINT DF_swim_flights_gufi DEFAULT NEWID();
GO

-- Step 1g: Create indexes on new columns
CREATE UNIQUE INDEX IX_swim_flights_gufi ON dbo.swim_flights (gufi);
CREATE INDEX IX_swim_flights_gufi_legacy ON dbo.swim_flights (gufi_legacy)
    WHERE gufi_legacy IS NOT NULL;
GO

-- ============================================================================
-- PART 2: swim_handoff_log — Add gufi_legacy column
-- ============================================================================

ALTER TABLE dbo.swim_handoff_log ADD gufi_legacy NVARCHAR(64) NULL;
GO
UPDATE dbo.swim_handoff_log SET gufi_legacy = gufi;
GO

-- ============================================================================
-- PART 3: swim_acars_messages — Add gufi_legacy column
-- ============================================================================

ALTER TABLE dbo.swim_acars_messages ADD gufi_legacy NVARCHAR(64) NULL;
GO
UPDATE dbo.swim_acars_messages SET gufi_legacy = gufi WHERE gufi IS NOT NULL;
GO

-- ============================================================================
-- PART 4: Recreate views to include new columns
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

-- ============================================================================
-- PART 5: sp_Swim_GetFlightByGufi — Rewrite for auto-detect
-- ============================================================================

CREATE OR ALTER PROCEDURE dbo.sp_Swim_GetFlightByGufi
    @identifier NVARCHAR(100)
AS
BEGIN
    SET NOCOUNT ON;

    -- Auto-detect format: UUID or legacy
    IF TRY_CONVERT(UNIQUEIDENTIFIER, @identifier) IS NOT NULL
    BEGIN
        -- UUID lookup
        SELECT * FROM dbo.swim_flights
        WHERE gufi = TRY_CONVERT(UNIQUEIDENTIFIER, @identifier);
    END
    ELSE
    BEGIN
        -- Legacy format lookup
        SELECT * FROM dbo.swim_flights WHERE gufi_legacy = @identifier;
    END
END;
GO

-- ============================================================================
-- PART 6: sp_Swim_BulkUpsert v4 — Remove gufi, add gufi_legacy
-- ============================================================================
-- Changes from v3 (migration 031):
--   1. OPENJSON: Remove 'gufi NVARCHAR(64)', add 'gufi_legacy NVARCHAR(64)'
--   2. UPDATE SET: Remove 't.gufi = s.gufi', add 't.gufi_legacy = s.gufi_legacy'
--   3. INSERT columns: Remove 'gufi', add 'gufi_legacy' + 'gufi_created_utc'
--   4. INSERT values: Remove 's.gufi', add 's.gufi_legacy' + 'SYSUTCDATETIME()'
--   Note: gufi column has DEFAULT NEWID() so omitting it auto-generates UUID on INSERT.
--   Note: gufi is never updated — once assigned, it's immutable.
-- ============================================================================

CREATE OR ALTER PROCEDURE dbo.sp_Swim_BulkUpsert
    @Json NVARCHAR(MAX)
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;

    DECLARE @inserted INT = 0, @updated INT = 0, @unchanged INT = 0;
    DECLARE @merge_output TABLE (action NVARCHAR(10), flight_uid INT);

    BEGIN TRY
        -- =====================================================================
        -- Step 1: Parse JSON into temp table with row-hash
        -- =====================================================================
        SELECT
            j.*,
            HASHBYTES('SHA1', CONCAT(
                ISNULL(CAST(j.lat AS VARCHAR(20)), ''), '|',
                ISNULL(CAST(j.lon AS VARCHAR(20)), ''), '|',
                ISNULL(CAST(j.altitude_ft AS VARCHAR(10)), ''), '|',
                ISNULL(CAST(j.heading_deg AS VARCHAR(10)), ''), '|',
                ISNULL(CAST(j.groundspeed_kts AS VARCHAR(10)), ''), '|',
                ISNULL(j.phase, ''), '|',
                ISNULL(CAST(j.is_active AS VARCHAR(1)), ''), '|',
                ISNULL(j.current_artcc, ''), '|',
                ISNULL(j.current_tracon, ''), '|',
                ISNULL(j.current_zone, ''), '|',
                ISNULL(LEFT(CAST(j.eta_utc AS VARCHAR(30)), 19), ''), '|',
                ISNULL(LEFT(CAST(j.etd_utc AS VARCHAR(30)), 19), ''), '|',
                ISNULL(CAST(j.dist_to_dest_nm AS VARCHAR(10)), ''), '|',
                ISNULL(j.ctl_type, ''), '|',
                ISNULL(j.ctl_prgm, ''), '|',
                ISNULL(CAST(j.delay_minutes AS VARCHAR(10)), ''), '|',
                ISNULL(CAST(j.gs_held AS VARCHAR(1)), ''), '|',
                ISNULL(CAST(j.vertical_rate_fpm AS VARCHAR(10)), ''), '|',
                ISNULL(LEFT(CAST(j.out_utc AS VARCHAR(30)), 19), ''), '|',
                ISNULL(LEFT(CAST(j.off_utc AS VARCHAR(30)), 19), ''), '|',
                ISNULL(LEFT(CAST(j.on_utc AS VARCHAR(30)), 19), ''), '|',
                ISNULL(LEFT(CAST(j.in_utc AS VARCHAR(30)), 19), ''), '|',
                ISNULL(CAST(j.gs_held AS VARCHAR(1)), ''), '|',
                ISNULL(LEFT(j.fp_route, 200), ''), '|',
                ISNULL(j.resolved_nat_track, '')
            )) AS row_hash
        INTO #flights
        FROM OPENJSON(@Json) WITH (
            -- Identity (6) — gufi removed, gufi_legacy added
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
            -- Flight plan (28)
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
            dfix                NVARCHAR(16),
            dp_name             NVARCHAR(32),
            afix                NVARCHAR(16),
            star_name           NVARCHAR(32),
            dep_runway          NVARCHAR(8),
            arr_runway          NVARCHAR(8),
            equipment_qualifier NVARCHAR(4),
            approach_procedure  NVARCHAR(16),
            fp_route_expanded   NVARCHAR(MAX),
            fp_fuel_minutes     INT,
            dtrsn               NVARCHAR(32),
            strsn               NVARCHAR(32),
            waypoint_count      INT,
            parse_status        NVARCHAR(16),
            simbrief_ofp_id     INT,
            resolved_nat_track  NVARCHAR(8),
            nat_track_resolved_at NVARCHAR(30),
            nat_track_source    NVARCHAR(16),
            -- FIXM aliases (14)
            sid                 NVARCHAR(32),
            star                NVARCHAR(32),
            departure_point     NVARCHAR(16),
            arrival_point       NVARCHAR(16),
            alternate_aerodrome NVARCHAR(8),
            departure_runway    NVARCHAR(8),
            arrival_runway      NVARCHAR(8),
            current_airspace    NVARCHAR(8),
            current_sector      NVARCHAR(16),
            estimated_time_of_departure NVARCHAR(30),
            original_ctd        NVARCHAR(30),
            controlled_time_of_departure NVARCHAR(30),
            controlled_time_of_arrival NVARCHAR(30),
            slot_time           NVARCHAR(30),
            control_type        NVARCHAR(16),
            control_element     NVARCHAR(16),
            program_name        NVARCHAR(32),
            delay_value         INT,
            ground_stop_held    BIT,
            exempt_indicator    BIT,
            -- Phase/Status (12)
            phase               NVARCHAR(16),
            is_active           BIT,
            dist_to_dest_nm     DECIMAL(8,1),
            dist_flown_nm       DECIMAL(8,1),
            pct_complete        DECIMAL(5,2),
            gcd_nm              DECIMAL(8,1),
            route_total_nm      DECIMAL(8,1),
            current_artcc       NVARCHAR(8),
            current_tracon      NVARCHAR(8),
            current_zone        NVARCHAR(16),
            current_zone_airport NVARCHAR(8),
            current_sector_low  NVARCHAR(16),
            current_sector_high NVARCHAR(16),
            weather_impact      NVARCHAR(64),
            weather_alert_ids   NVARCHAR(MAX),
            -- Times (34)
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
            ete_minutes         DECIMAL(8,1),
            ctd_utc             DATETIME2(0),
            cta_utc             DATETIME2(0),
            edct_utc            DATETIME2(0),
            sta_utc             DATETIME2(0),
            etd_runway_utc      DATETIME2(0),
            etd_source          NVARCHAR(16),
            octa_utc            DATETIME2(0),
            octd_utc            DATETIME2(0),
            ate_minutes         DECIMAL(8,1),
            eta_confidence      DECIMAL(5,2),
            eta_wind_component_kts SMALLINT,
            gs_held             BIT,
            gs_release_utc      DATETIME2(0),
            ctl_type            NVARCHAR(16),
            ctl_prgm            NVARCHAR(32),
            ctl_element         NVARCHAR(16),
            is_exempt           BIT,
            exempt_reason       NVARCHAR(32),
            slot_time_utc       DATETIME2(0),
            slot_status         NVARCHAR(16),
            program_id          INT,
            slot_id             INT,
            delay_minutes       INT,
            delay_status        NVARCHAR(16),
            ctl_exempt          BIT,
            ctl_exempt_reason   NVARCHAR(32),
            aslot               NVARCHAR(32),
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

        -- =====================================================================
        -- Step 2: MERGE with row-hash comparison
        -- =====================================================================
        MERGE dbo.swim_flights AS t
        USING #flights AS s ON t.flight_uid = s.flight_uid

        WHEN MATCHED AND (t.row_hash IS NULL OR t.row_hash <> s.row_hash) THEN UPDATE SET
            -- Identity (gufi NEVER updated — immutable once assigned)
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
            t.fp_alt_icao = s.fp_alt_icao,
            t.fp_altitude_ft = s.fp_altitude_ft, t.fp_tas_kts = s.fp_tas_kts,
            t.fp_route = s.fp_route, t.fp_remarks = s.fp_remarks, t.fp_rule = s.fp_rule,
            t.fp_dept_artcc = s.fp_dept_artcc, t.fp_dest_artcc = s.fp_dest_artcc,
            t.fp_dept_tracon = s.fp_dept_tracon, t.fp_dest_tracon = s.fp_dest_tracon,
            t.dfix = s.dfix, t.dp_name = s.dp_name, t.afix = s.afix, t.star_name = s.star_name,
            t.dep_runway = s.dep_runway, t.arr_runway = s.arr_runway,
            t.equipment_qualifier = s.equipment_qualifier,
            t.approach_procedure = s.approach_procedure,
            t.fp_route_expanded = s.fp_route_expanded, t.fp_fuel_minutes = s.fp_fuel_minutes,
            t.dtrsn = s.dtrsn, t.strsn = s.strsn,
            t.waypoint_count = s.waypoint_count, t.parse_status = s.parse_status,
            t.simbrief_ofp_id = s.simbrief_ofp_id,
            t.resolved_nat_track = s.resolved_nat_track,
            t.nat_track_resolved_at = TRY_CAST(s.nat_track_resolved_at AS DATETIME2(0)),
            t.nat_track_source = s.nat_track_source,
            -- FIXM aliases
            t.sid = s.dp_name, t.star = s.star_name,
            t.departure_point = s.dfix, t.arrival_point = s.afix,
            t.alternate_aerodrome = s.fp_alt_icao,
            t.departure_runway = s.dep_runway, t.arrival_runway = s.arr_runway,
            t.current_airspace = s.current_artcc, t.current_sector = s.current_sector_low,
            t.estimated_time_of_departure = s.etd_utc,
            t.original_ctd = s.octd_utc,
            t.controlled_time_of_departure = s.ctd_utc,
            t.controlled_time_of_arrival = s.cta_utc,
            t.slot_time = s.slot_time_utc,
            t.control_type = s.ctl_type, t.control_element = s.ctl_element,
            t.program_name = s.ctl_prgm,
            t.delay_value = s.delay_minutes,
            t.ground_stop_held = s.gs_held, t.exempt_indicator = s.is_exempt,
            -- Phase/Status
            t.phase = s.phase, t.is_active = s.is_active,
            t.dist_to_dest_nm = s.dist_to_dest_nm, t.dist_flown_nm = s.dist_flown_nm,
            t.pct_complete = s.pct_complete,
            t.gcd_nm = s.gcd_nm, t.route_total_nm = s.route_total_nm,
            t.current_artcc = s.current_artcc, t.current_tracon = s.current_tracon,
            t.current_zone = s.current_zone,
            t.current_zone_airport = s.current_zone_airport,
            t.current_sector_low = s.current_sector_low, t.current_sector_high = s.current_sector_high,
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
            t.etd_source = s.etd_source, t.octa_utc = s.octa_utc,
            t.ate_minutes = s.ate_minutes,
            t.eta_confidence = s.eta_confidence, t.eta_wind_component_kts = s.eta_wind_component_kts,
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
            -- gufi omitted — DEFAULT NEWID() generates UUID automatically
            -- gufi_created_utc omitted — DEFAULT SYSUTCDATETIME() generates timestamp
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
            s.dp_name, s.star_name, s.dfix, s.afix,
            s.fp_alt_icao, s.dep_runway, s.arr_runway,
            s.current_artcc, s.current_sector_low,
            s.etd_utc, s.octd_utc,
            s.ctd_utc, s.cta_utc,
            s.slot_time_utc, s.ctl_type, s.ctl_element, s.ctl_prgm,
            s.delay_minutes, s.gs_held, s.is_exempt,
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

        SET @unchanged = (SELECT COUNT(*) FROM #flights) - @inserted - @updated;

        SELECT @inserted AS inserted, @updated AS updated, @unchanged AS unchanged;
    END TRY
    BEGIN CATCH
        IF @@TRANCOUNT > 0 ROLLBACK;
        THROW;
    END CATCH
END;
GO

-- ============================================================================
-- Verification queries (run after migration)
-- ============================================================================
-- SELECT TOP 10 gufi, gufi_legacy, gufi_created_utc FROM dbo.swim_flights;
-- SELECT COUNT(*) AS total, COUNT(gufi) AS has_uuid, COUNT(gufi_legacy) AS has_legacy FROM dbo.swim_flights;
```

- [ ] **Step 2: Verify migration SQL syntax**

Open the file in SSMS (or a SQL editor) and verify it parses without errors. Do NOT execute yet — this runs during deployment.

- [ ] **Step 3: Commit**

```bash
git add database/migrations/swim/032_gufi_uuid_migration.sql
git commit -m "feat(swim): add migration 032 — GUFI UUID schema + SP v4

Migrates swim_flights.gufi from NVARCHAR to UNIQUEIDENTIFIER (UUID v4).
Adds gufi_legacy + gufi_created_utc columns, rebuilds views and SPs."
```

---

### Task 2: PHP Helper Functions in swim_config.php

**Files:**
- Modify: `load/swim_config.php:506-507,626-654`

- [ ] **Step 1: Add swim_generate_gufi_legacy() function**

Replace the existing `swim_generate_gufi()` at line 626-637 with a renamed version, and keep the old name as a deprecated wrapper:

```php
/**
 * Helper: Generate legacy GUFI format (VAT-YYYYMMDD-CALLSIGN-DEPT-DEST)
 * Used for the gufi_legacy column only. UUID is generated by SQL Server DEFAULT NEWID().
 */
function swim_generate_gufi_legacy($callsign, $dept_icao, $dest_icao, $date = null) {
    if ($date === null) {
        $date = gmdate('Ymd');
    }
    return implode(SWIM_GUFI_SEPARATOR, [
        SWIM_GUFI_PREFIX,
        $date,
        strtoupper(trim($callsign)),
        strtoupper(trim($dept_icao)),
        strtoupper(trim($dest_icao))
    ]);
}

/** @deprecated Use swim_generate_gufi_legacy() instead */
function swim_generate_gufi($callsign, $dept_icao, $dest_icao, $date = null) {
    return swim_generate_gufi_legacy($callsign, $dept_icao, $dest_icao, $date);
}
```

Edit `load/swim_config.php` — replace lines 623-637 (the current `swim_generate_gufi` block):

Old:
```php
/**
 * Helper: Generate GUFI
 */
function swim_generate_gufi($callsign, $dept_icao, $dest_icao, $date = null) {
    if ($date === null) {
        $date = gmdate('Ymd');
    }
    return implode(SWIM_GUFI_SEPARATOR, [
        SWIM_GUFI_PREFIX,
        $date,
        strtoupper(trim($callsign)),
        strtoupper(trim($dept_icao)),
        strtoupper(trim($dest_icao))
    ]);
}
```

New:
```php
/**
 * Helper: Generate legacy GUFI format (VAT-YYYYMMDD-CALLSIGN-DEPT-DEST)
 * Used for the gufi_legacy column only. UUID is generated by SQL Server DEFAULT NEWID().
 */
function swim_generate_gufi_legacy($callsign, $dept_icao, $dest_icao, $date = null) {
    if ($date === null) {
        $date = gmdate('Ymd');
    }
    return implode(SWIM_GUFI_SEPARATOR, [
        SWIM_GUFI_PREFIX,
        $date,
        strtoupper(trim($callsign)),
        strtoupper(trim($dept_icao)),
        strtoupper(trim($dest_icao))
    ]);
}

/** @deprecated Use swim_generate_gufi_legacy() instead */
function swim_generate_gufi($callsign, $dept_icao, $dest_icao, $date = null) {
    return swim_generate_gufi_legacy($callsign, $dept_icao, $dest_icao, $date);
}
```

- [ ] **Step 2: Add swim_format_gufi_response() and swim_gufi_lookup_sql()**

Add after the `swim_parse_gufi()` function (after line 654):

```php
/**
 * Helper: Format GUFI as FIXM metadata object (EUROCONTROL NM B2B format)
 *
 * @param string $uuid UUID v4 string from UNIQUEIDENTIFIER column
 * @param string|null $legacy Legacy format string (VAT-YYYYMMDD-...)
 * @param string|null $created_utc ISO datetime when GUFI was first assigned
 * @return array FIXM-compliant GUFI metadata
 */
function swim_format_gufi_response($uuid, $legacy = null, $created_utc = null) {
    return [
        'value' => $uuid,
        'codeSpace' => 'urn:uuid',
        'creationTime' => $created_utc,
        'namespaceDomain' => 'FULLY_QUALIFIED_DOMAIN_NAME',
        'namespaceIdentifier' => 'vatcscc.org'
    ];
}

/**
 * Helper: Build SQL WHERE clause for GUFI lookup (auto-detects UUID vs legacy format)
 *
 * @param string $identifier GUFI value (UUID or legacy VAT-... format)
 * @return array [where_clause, params] for sqlsrv_query
 */
function swim_gufi_lookup_sql($identifier) {
    // UUID v4 regex: 8-4-4-4-12 hex with version 4 and variant [89ab]
    if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $identifier)) {
        return ['WHERE gufi = ? AND is_active = 1', [$identifier]];
    }
    return ['WHERE gufi_legacy = ? AND is_active = 1', [$identifier]];
}
```

- [ ] **Step 3: Commit**

```bash
git add load/swim_config.php
git commit -m "feat(swim): add GUFI UUID helper functions

Adds swim_generate_gufi_legacy(), swim_format_gufi_response(),
swim_gufi_lookup_sql(). Deprecates swim_generate_gufi()."
```

---

### Task 3: Update swim_sync.php — Pass gufi_legacy Instead of gufi

**Files:**
- Modify: `scripts/swim_sync.php:291,501,525-540,671`

- [ ] **Step 1: Update delta sync function (line 291)**

Edit `scripts/swim_sync.php` — change the `$row['gufi']` assignment at line 291:

Old (line 289-296):
```php
        // Generate GUFI
        $row['gufi'] = swim_generate_gufi_sync(
            $row['callsign'],
            $row['fp_dept_icao'],
            $row['fp_dest_icao'],
            $row['first_seen_utc']
        );
```

New:
```php
        // Generate legacy GUFI for gufi_legacy column
        // UUID gufi is generated by SQL Server DEFAULT NEWID() on INSERT
        $row['gufi_legacy'] = swim_generate_gufi_sync(
            $row['callsign'],
            $row['fp_dept_icao'],
            $row['fp_dest_icao'],
            $row['first_seen_utc']
        );
```

- [ ] **Step 2: Update full sync function (line 501)**

Edit `scripts/swim_sync.php` — change the `$row['gufi']` assignment at line 501:

Old (line 499-506):
```php
        // Generate GUFI
        $row['gufi'] = swim_generate_gufi_sync(
            $row['callsign'],
            $row['fp_dept_icao'],
            $row['fp_dest_icao'],
            $row['first_seen_utc']
        );
```

New:
```php
        // Generate legacy GUFI for gufi_legacy column
        // UUID gufi is generated by SQL Server DEFAULT NEWID() on INSERT
        $row['gufi_legacy'] = swim_generate_gufi_sync(
            $row['callsign'],
            $row['fp_dept_icao'],
            $row['fp_dest_icao'],
            $row['first_seen_utc']
        );
```

- [ ] **Step 3: Update swim_build_params (line 671)**

Edit `scripts/swim_sync.php` — change the `$f['gufi']` reference at line 671:

Old:
```php
        $f['gufi'],
```

New:
```php
        $f['gufi_legacy'],
```

- [ ] **Step 4: Commit**

```bash
git add scripts/swim_sync.php
git commit -m "feat(swim): sync passes gufi_legacy instead of gufi

SP v4 generates UUID via DEFAULT NEWID() on INSERT. PHP only provides
the legacy human-readable identifier for the gufi_legacy column."
```

---

### Task 4: Update flight.php — Auto-detect GUFI Format for Lookup

**Files:**
- Modify: `api/swim/v1/flight.php:57-80`

- [ ] **Step 1: Replace GUFI parsing with auto-detect lookup**

Edit `api/swim/v1/flight.php` — replace the GUFI handling block (lines 57-80):

Old:
```php
} elseif ($gufi) {
    $gufi_parts = swim_parse_gufi($gufi);

    if (!$gufi_parts) {
        SwimResponse::error('Invalid GUFI format. Expected: VAT-YYYYMMDD-CALLSIGN-DEPT-DEST', 400, 'INVALID_GUFI');
    }

    $where_clause = 'f.callsign = ? AND f.fp_dept_icao = ? AND f.fp_dest_icao = ?';
    $params[] = $gufi_parts['callsign'];
    $params[] = $gufi_parts['dept'];
    $params[] = $gufi_parts['dest'];

    if (!$include_history) {
        $gufi_date = $gufi_parts['date'];
        if (strlen($gufi_date) === 8) {
            $year = substr($gufi_date, 0, 4);
            $month = substr($gufi_date, 4, 2);
            $day = substr($gufi_date, 6, 2);
            $date_str = "$year-$month-$day";
            $where_clause .= ' AND CAST(f.first_seen_utc AS DATE) = ?';
            $params[] = $date_str;
        }
    }
}
```

New:
```php
} elseif ($gufi) {
    // Auto-detect UUID vs legacy format
    if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $gufi)) {
        $where_clause = 'f.gufi = ?';
        $params[] = $gufi;
    } else {
        $where_clause = 'f.gufi_legacy = ?';
        $params[] = $gufi;
    }
}
```

- [ ] **Step 2: Verify flight.php query includes new columns**

The SELECT at line 89 already uses `f.gufi` which will now return the UUID. Verify the query also needs `gufi_legacy` and `gufi_created_utc`. Add them to the SELECT list after `f.gufi`:

At line 89, after `f.gufi,` add:
```php
        f.gufi_legacy, f.gufi_created_utc,
```

- [ ] **Step 3: Commit**

```bash
git add api/swim/v1/flight.php
git commit -m "feat(swim): flight.php auto-detects UUID vs legacy GUFI

Accepts both UUID and VAT-... format in ?gufi= parameter.
Routes to gufi or gufi_legacy column accordingly."
```

---

### Task 5: Update flights.php — Response Formatting

**Files:**
- Modify: `api/swim/v1/flights.php:306-308,449-462`

- [ ] **Step 1: Update formatFlightRecord() (line 306-319)**

Edit `api/swim/v1/flights.php` — update the GUFI line at 308 and add gufi_legacy:

Old (line 308):
```php
    $gufi = $row['gufi'] ?? swim_generate_gufi($row['callsign'], $row['fp_dept_icao'], $row['fp_dest_icao']);
```

New:
```php
    $gufi = $row['gufi'] ?? '';
```

Old (line 319, the `$result` array):
```php
    $result = [
        'gufi' => $gufi,
```

New:
```php
    $result = [
        'gufi' => $gufi,
        'gufi_legacy' => $row['gufi_legacy'] ?? null,
```

- [ ] **Step 2: Update formatFlightRecordFIXM() (line 449-462)**

Edit `api/swim/v1/flights.php` — update the GUFI line at 450 and the result array at 462:

Old (line 450):
```php
    $gufi = $row['gufi'] ?? swim_generate_gufi($row['callsign'], $row['fp_dept_icao'], $row['fp_dest_icao']);
```

New:
```php
    $gufi = $row['gufi'] ?? '';
```

Old (line 460-462):
```php
    $result = [
        // Root level - FIXM aligned
        'gufi' => $gufi,
```

New:
```php
    $result = [
        // Root level - FIXM aligned (GUFI as metadata object per EUROCONTROL NM B2B)
        'gufi' => swim_format_gufi_response(
            $gufi,
            $row['gufi_legacy'] ?? null,
            formatDT($row['gufi_created_utc'] ?? null)
        ),
```

- [ ] **Step 3: Ensure SELECT queries include new columns**

Check that the SQL query fetching flights includes `gufi_legacy` and `gufi_created_utc` columns. The main query uses `SELECT ... f.*` or lists columns explicitly — verify and add if missing.

- [ ] **Step 4: Commit**

```bash
git add api/swim/v1/flights.php
git commit -m "feat(swim): flights.php returns UUID + FIXM metadata object

FIXM format returns gufi as rich metadata object with value/codeSpace/
creationTime. Legacy format returns UUID string + gufi_legacy field."
```

---

### Task 6: Update ingest/adl.php — Replace GUFI Existence Check

**Files:**
- Modify: `api/swim/v1/ingest/adl.php:88-92`

This is the **most critical** change. The current code reconstructs a GUFI from input attributes to check if a flight exists. After migration, the gufi column contains UUIDs so this would never match, causing duplicate inserts.

- [ ] **Step 1: Replace GUFI-based existence check with flight_uid + attribute lookup**

Edit `api/swim/v1/ingest/adl.php` — replace lines 88-92:

Old:
```php
    $gufi = swim_generate_gufi($callsign, $dept_icao, $dest_icao);

    // Check if flight exists in swim_flights
    $check_sql = "SELECT flight_uid FROM dbo.swim_flights WHERE gufi = ?";
    $check_stmt = sqlsrv_query($conn, $check_sql, [$gufi]);
```

New:
```php
    // Check if flight exists in swim_flights
    // Primary: flight_uid (most reliable, available from ADL source)
    $flight_uid = isset($flight['flight_uid']) ? (int)$flight['flight_uid'] : 0;
    if ($flight_uid > 0) {
        $check_sql = "SELECT flight_uid FROM dbo.swim_flights WHERE flight_uid = ?";
        $check_stmt = sqlsrv_query($conn, $check_sql, [$flight_uid]);
    } else {
        // Fallback: callsign + dept + dest + active (for external ingest without flight_uid)
        $check_sql = "SELECT flight_uid FROM dbo.swim_flights WHERE callsign = ? AND fp_dept_icao = ? AND fp_dest_icao = ? AND is_active = 1";
        $check_stmt = sqlsrv_query($conn, $check_sql, [$callsign, $dept_icao, $dest_icao]);
    }
```

- [ ] **Step 2: Commit**

```bash
git add api/swim/v1/ingest/adl.php
git commit -m "fix(swim): adl ingest uses flight_uid instead of GUFI for existence check

The GUFI column now stores UUIDs, so reconstructing a legacy-format
GUFI from attributes would never match. Use flight_uid (primary) or
callsign+dept+dest (fallback) instead."
```

---

### Task 7: Update ingest/cdm.php — Auto-detect GUFI Format

**Files:**
- Modify: `api/swim/v1/ingest/cdm.php:148-152`

- [ ] **Step 1: Replace direct GUFI query with auto-detect**

Edit `api/swim/v1/ingest/cdm.php` — replace lines 148-152:

Old:
```php
    if (!empty($gufi)) {
        $lookup_sql = "SELECT flight_uid, callsign, fp_dept_icao, fp_dest_icao
                       FROM dbo.swim_flights
                       WHERE gufi = ? AND is_active = 1";
        $params = [$gufi];
```

New:
```php
    if (!empty($gufi)) {
        [$gufi_where, $params] = swim_gufi_lookup_sql($gufi);
        $lookup_sql = "SELECT flight_uid, callsign, fp_dept_icao, fp_dest_icao
                       FROM dbo.swim_flights $gufi_where";
```

- [ ] **Step 2: Commit**

```bash
git add api/swim/v1/ingest/cdm.php
git commit -m "feat(swim): cdm ingest auto-detects UUID vs legacy GUFI"
```

---

### Task 8: Update ingest/simtraffic.php — Auto-detect GUFI Format

**Files:**
- Modify: `api/swim/v1/ingest/simtraffic.php:354-358`

- [ ] **Step 1: Replace direct GUFI query with auto-detect**

Edit `api/swim/v1/ingest/simtraffic.php` — replace lines 354-358:

Old:
```php
    if (!empty($gufi)) {
        $lookup_sql = "SELECT flight_uid, callsign, fp_dept_icao, fp_dest_icao
                       FROM dbo.swim_flights
                       WHERE gufi = ? AND is_active = 1";
        $params = [$gufi];
```

New:
```php
    if (!empty($gufi)) {
        [$gufi_where, $params] = swim_gufi_lookup_sql($gufi);
        $lookup_sql = "SELECT flight_uid, callsign, fp_dept_icao, fp_dest_icao
                       FROM dbo.swim_flights $gufi_where";
```

- [ ] **Step 2: Commit**

```bash
git add api/swim/v1/ingest/simtraffic.php
git commit -m "feat(swim): simtraffic ingest auto-detects UUID vs legacy GUFI"
```

---

### Task 9: Update VNAS Ingest Endpoints — Auto-detect GUFI Format

**Files:**
- Modify: `api/swim/v1/ingest/vnas/track.php:160-164`
- Modify: `api/swim/v1/ingest/vnas/handoff.php:172-176,249-268`
- Modify: `api/swim/v1/ingest/vnas/tags.php:140-144`
- Modify: `api/swim/v1/ingest/metering.php:154-158`

- [ ] **Step 1: Update track.php GUFI lookup**

Edit `api/swim/v1/ingest/vnas/track.php` — replace lines 160-164:

Old:
```php
    if (!empty($gufi)) {
        $check_sql = "SELECT TOP 1 flight_uid, gufi
                      FROM dbo.swim_flights
                      WHERE gufi = ? AND is_active = 1";
        $check_stmt = sqlsrv_query($conn, $check_sql, [$gufi]);
```

New:
```php
    if (!empty($gufi)) {
        [$gufi_where, $gufi_params] = swim_gufi_lookup_sql($gufi);
        $check_sql = "SELECT TOP 1 flight_uid, gufi
                      FROM dbo.swim_flights $gufi_where";
        $check_stmt = sqlsrv_query($conn, $check_sql, $gufi_params);
```

- [ ] **Step 2: Update tags.php GUFI lookup**

Edit `api/swim/v1/ingest/vnas/tags.php` — replace lines 140-144:

Old:
```php
    if (!empty($gufi)) {
        $check_sql = "SELECT TOP 1 flight_uid, gufi
                      FROM dbo.swim_flights
                      WHERE gufi = ? AND is_active = 1";
        $check_stmt = sqlsrv_query($conn, $check_sql, [$gufi]);
```

New:
```php
    if (!empty($gufi)) {
        [$gufi_where, $gufi_params] = swim_gufi_lookup_sql($gufi);
        $check_sql = "SELECT TOP 1 flight_uid, gufi
                      FROM dbo.swim_flights $gufi_where";
        $check_stmt = sqlsrv_query($conn, $check_sql, $gufi_params);
```

- [ ] **Step 3: Update handoff.php GUFI lookup**

Edit `api/swim/v1/ingest/vnas/handoff.php` — replace lines 172-176:

Old:
```php
    if (!empty($gufi)) {
        $check_sql = "SELECT TOP 1 flight_uid, gufi
                      FROM dbo.swim_flights
                      WHERE gufi = ? AND is_active = 1";
        $check_stmt = sqlsrv_query($conn, $check_sql, [$gufi]);
```

New:
```php
    if (!empty($gufi)) {
        [$gufi_where, $gufi_params] = swim_gufi_lookup_sql($gufi);
        $check_sql = "SELECT TOP 1 flight_uid, gufi, gufi_legacy
                      FROM dbo.swim_flights $gufi_where";
        $check_stmt = sqlsrv_query($conn, $check_sql, $gufi_params);
```

- [ ] **Step 4: Update handoff.php log INSERT to include gufi_legacy**

In handoff.php, the INSERT into `swim_handoff_log` at lines 249-268 stores `$existing['gufi']`. After migration, `$existing['gufi']` will be a UUID string (from UNIQUEIDENTIFIER → PHP string). Also store the legacy value in the new `gufi_legacy` column:

Find the INSERT column list at line 249-252 and add `gufi_legacy` to both the column list and the VALUES placeholders. In the params array, add `$existing['gufi_legacy'] ?? null` after the gufi param.

- [ ] **Step 5: Update metering.php GUFI lookup**

Edit `api/swim/v1/ingest/metering.php` — replace lines 154-158:

Old:
```php
    if (!empty($gufi)) {
        $lookup_sql = "SELECT flight_uid, callsign, fp_dest_icao
                       FROM dbo.swim_flights
                       WHERE gufi = ? AND is_active = 1";
        $params = [$gufi];
```

New:
```php
    if (!empty($gufi)) {
        [$gufi_where, $params] = swim_gufi_lookup_sql($gufi);
        $lookup_sql = "SELECT flight_uid, callsign, fp_dest_icao
                       FROM dbo.swim_flights $gufi_where";
```

- [ ] **Step 6: Commit**

```bash
git add api/swim/v1/ingest/vnas/track.php api/swim/v1/ingest/vnas/handoff.php api/swim/v1/ingest/vnas/tags.php api/swim/v1/ingest/metering.php
git commit -m "feat(swim): VNAS + metering ingest endpoints auto-detect GUFI format

All ingest endpoints now use swim_gufi_lookup_sql() for transparent
UUID vs legacy format detection. Handoff log stores gufi_legacy."
```

---

### Task 10: Update viff_cdm_poll_daemon.php — Query gufi_legacy

**Files:**
- Modify: `scripts/viff_cdm_poll_daemon.php:335,348-350`

- [ ] **Step 1: Update viff_batch_gufi_lookup() to query gufi_legacy column**

The function constructs legacy-format GUFIs from vIFF flight data and does a batch lookup. After migration, these legacy values live in `gufi_legacy`, not `gufi`.

Edit `scripts/viff_cdm_poll_daemon.php` — change line 335:

Old:
```php
        $gufi = swim_generate_gufi($callsign, $departure, $arrival, $date);
```

New:
```php
        $gufi = swim_generate_gufi_legacy($callsign, $departure, $arrival, $date);
```

Edit lines 348-350:

Old:
```php
        $sql = "SELECT flight_uid, callsign, fp_dept_icao, fp_dest_icao, gufi
                FROM dbo.swim_flights
                WHERE gufi IN ($placeholders) AND is_active = 1";
```

New:
```php
        $sql = "SELECT flight_uid, callsign, fp_dept_icao, fp_dest_icao, gufi, gufi_legacy
                FROM dbo.swim_flights
                WHERE gufi_legacy IN ($placeholders) AND is_active = 1";
```

Edit line 354 — the result mapping key needs to use gufi_legacy:

Old:
```php
                $resolved[$row['gufi']] = $row;
```

New:
```php
                $resolved[$row['gufi_legacy']] = $row;
```

- [ ] **Step 2: Commit**

```bash
git add scripts/viff_cdm_poll_daemon.php
git commit -m "feat(swim): vIFF daemon queries gufi_legacy for batch lookups

Legacy-format GUFIs now live in gufi_legacy column. Updates batch
lookup to generate legacy GUFIs and query the correct column."
```

---

### Task 11: Update SDK Models — PHP, Python, JavaScript, C#, Java

**Files:**
- Modify: `sdk/php/src/Models/Flight.php:14-15`
- Modify: `sdk/python/swim_client/models.py:192-193,205`
- Modify: `sdk/javascript/src/types.ts:93-103`
- Modify: `sdk/csharp/SwimClient/Models/Flight.cs:42-43`
- Modify: `sdk/java/swim-client/src/main/java/org/vatsim/swim/model/Flight.java:12-13,53-54`

- [ ] **Step 1: Update PHP SDK Flight model**

Edit `sdk/php/src/Models/Flight.php` — after line 15 (`public ?string $gufi = null;`), add:

```php
    public ?string $gufi_legacy = null;
    public ?string $gufi_created_utc = null;
```

Edit the mapping array at line 105 — after `'gufi' => 'gufi',` add:

```php
            'gufi_legacy' => 'gufi_legacy',
            'gufi_created_utc' => 'gufi_created_utc',
```

- [ ] **Step 2: Update Python SDK Flight model**

Edit `sdk/python/swim_client/models.py` — after line 193 (`gufi: str`), add:

```python
    gufi_legacy: Optional[str] = None
    gufi_created_utc: Optional[str] = None
```

Edit the from_dict method — after line 205 (`gufi=data.get('gufi', ''),`), add:

```python
            gufi_legacy=data.get('gufi_legacy'),
            gufi_created_utc=data.get('gufi_created_utc'),
```

- [ ] **Step 3: Update JavaScript/TypeScript SDK types**

Edit `sdk/javascript/src/types.ts` — add GufiMetadata interface before Flight (before line 93):

```typescript
export interface GufiMetadata {
  value: string;
  codeSpace: string;
  creationTime: string | null;
  namespaceDomain: string;
  namespaceIdentifier: string;
}

```

Edit the Flight interface — change line 94 and add new fields after it:

Old:
```typescript
  gufi: string;
```

New:
```typescript
  gufi: string | GufiMetadata;
  gufi_legacy?: string;
  gufi_created_utc?: string;
```

- [ ] **Step 4: Update C# SDK Flight model**

Edit `sdk/csharp/SwimClient/Models/Flight.cs` — after lines 42-43, add:

```csharp
    [JsonPropertyName("gufi_legacy")]
    public string? GufiLegacy { get; set; }

    [JsonPropertyName("gufi_created_utc")]
    public string? GufiCreatedUtc { get; set; }
```

- [ ] **Step 5: Update Java SDK Flight model**

Edit `sdk/java/swim-client/src/main/java/org/vatsim/swim/model/Flight.java` — after lines 12-13 (`private String gufi;`), add:

```java
    @JsonProperty("gufi_legacy")
    private String gufiLegacy;

    @JsonProperty("gufi_created_utc")
    private String gufiCreatedUtc;
```

After lines 53-54 (the gufi getter/setter), add:

```java
    public String getGufiLegacy() { return gufiLegacy; }
    public void setGufiLegacy(String gufiLegacy) { this.gufiLegacy = gufiLegacy; }
    public String getGufiCreatedUtc() { return gufiCreatedUtc; }
    public void setGufiCreatedUtc(String gufiCreatedUtc) { this.gufiCreatedUtc = gufiCreatedUtc; }
```

- [ ] **Step 6: Commit**

```bash
git add sdk/php/src/Models/Flight.php sdk/python/swim_client/models.py sdk/javascript/src/types.ts sdk/csharp/SwimClient/Models/Flight.cs sdk/java/swim-client/src/main/java/org/vatsim/swim/model/Flight.java
git commit -m "feat(swim): SDK models add gufi_legacy + gufi_created_utc fields

All 5 SDKs (PHP, Python, JS/TS, C#, Java) updated with new optional
fields. C++ SDK unchanged (ingest-only, no Flight model)."
```

---

### Task 12: Update OpenAPI Spec

**Files:**
- Modify: `docs/swim/openapi.yaml`

- [ ] **Step 1: Update GUFI description section**

Find the GUFI format description (around line 46-50) and update:

Old (approximate):
```yaml
## GUFI Format

Each flight is assigned a Globally Unique Flight Identifier (GUFI) in the format:
`VAT-YYYYMMDD-CALLSIGN-DEPT-DEST`
```

New:
```yaml
## GUFI Format

Globally Unique Flight Identifier (GUFI) uses UUID v4 format per ICAO Doc 9965
and FIXM 4.2+ specifications.

Example UUID: `dd056de9-0ba9-4d55-82cf-7b976b0b6d29`
Legacy format (preserved in gufi_legacy): `VAT-20260329-UAL123-KJFK-KLAX`

The `?gufi=` parameter accepts both formats — auto-detected at query time.
```

- [ ] **Step 2: Update Flight schema gufi property**

Find the Flight schema gufi property (around line 4459-4462) and update:

Old:
```yaml
    gufi:
      type: string
      description: Globally Unique Flight Identifier
```

New:
```yaml
    gufi:
      oneOf:
        - type: string
          format: uuid
          description: UUID v4 (legacy response format)
        - $ref: '#/components/schemas/GufiMetadata'
      description: Globally Unique Flight Identifier (format depends on response format)
    gufi_legacy:
      type: string
      description: Human-readable legacy identifier (VAT-YYYYMMDD-CALLSIGN-DEPT-DEST)
    gufi_created_utc:
      type: string
      format: date-time
      description: UTC timestamp when the GUFI was first assigned
```

- [ ] **Step 3: Add GufiMetadata schema**

Add to the `components/schemas` section:

```yaml
    GufiMetadata:
      type: object
      description: FIXM-compliant GUFI metadata (per EUROCONTROL NM B2B format)
      properties:
        value:
          type: string
          format: uuid
        codeSpace:
          type: string
          enum: [urn:uuid]
        creationTime:
          type: string
          format: date-time
        namespaceDomain:
          type: string
          enum: [FULLY_QUALIFIED_DOMAIN_NAME]
        namespaceIdentifier:
          type: string
          example: vatcscc.org
```

- [ ] **Step 4: Commit**

```bash
git add docs/swim/openapi.yaml
git commit -m "docs(swim): update OpenAPI spec for UUID GUFI format

Adds GufiMetadata schema, updates Flight schema with oneOf for UUID
string vs FIXM metadata object, adds gufi_legacy and gufi_created_utc."
```

---

### Task 13: Deployment and Verification

This task covers the actual deployment sequence.

- [ ] **Step 1: Create feature branch and PR**

```bash
git checkout -b feature/gufi-uuid-migration
git push -u origin feature/gufi-uuid-migration
gh pr create --title "feat(swim): GUFI UUID migration per ICAO/FIXM standards" --body "$(cat <<'EOF'
## Summary
- Migrates swim_flights.gufi from NVARCHAR (VAT-...) to UNIQUEIDENTIFIER (UUID v4)
- Adds gufi_legacy column preserving human-readable format
- All ingest endpoints auto-detect UUID vs legacy format
- FIXM response returns rich metadata object per EUROCONTROL NM B2B
- SDKs updated with new optional fields (backward-compatible)

## Test plan
- [ ] Run migration 032 in SSMS, verify UUID + legacy columns populated
- [ ] Verify swim_sync_daemon inserts new flights with UUID
- [ ] Test /flight?gufi= with both UUID and legacy format
- [ ] Test /flights?format=fixm returns GUFI metadata object
- [ ] Monitor vIFF daemon for successful gufi_legacy lookups

Generated with [Claude Code](https://claude.com/claude-code)
EOF
)"
```

- [ ] **Step 2: Run database migration in SSMS**

Connect to `SWIM_API` database with `jpeterson` admin credentials. Execute `database/migrations/swim/032_gufi_uuid_migration.sql` section by section, verifying after each PART.

Verification after PART 1:
```sql
SELECT TOP 10 gufi, gufi_legacy, gufi_created_utc FROM dbo.swim_flights;
-- gufi: UNIQUEIDENTIFIER (UUID format)
-- gufi_legacy: 'VAT-20260329-...' format
-- gufi_created_utc: datetime2 value
```

Verification after PART 6 (SP v4):
```sql
-- Test with a dummy JSON to verify SP parses correctly
EXEC dbo.sp_Swim_BulkUpsert @Json = '[]';
-- Should return: inserted=0, updated=0, unchanged=0
```

- [ ] **Step 3: Merge PR to main (triggers GitHub Actions deploy)**

```bash
gh pr merge --squash
```

- [ ] **Step 4: Restart App Service to pick up new PHP code**

```bash
az webapp restart --name vatcscc --resource-group VATSIM_RG
```

- [ ] **Step 5: Verify API responses**

```bash
# Check FIXM format
curl -s "https://perti.vatcscc.org/api/swim/v1/flights?format=fixm&per_page=1" | jq '.data.flights[0].gufi'
# Expected: {"value":"uuid-string","codeSpace":"urn:uuid",...}

# Check legacy format
curl -s "https://perti.vatcscc.org/api/swim/v1/flights?format=legacy&per_page=1" | jq '{gufi: .data.flights[0].gufi, legacy: .data.flights[0].gufi_legacy}'
# Expected: {"gufi":"uuid-string","legacy":"VAT-..."}

# Check UUID lookup
curl -s "https://perti.vatcscc.org/api/swim/v1/flight?gufi=<uuid-from-above>"
# Expected: 200 with flight data

# Check legacy lookup
curl -s "https://perti.vatcscc.org/api/swim/v1/flight?gufi=VAT-<date>-<callsign>-<dept>-<dest>"
# Expected: 200 with flight data
```

- [ ] **Step 6: Monitor swim_sync_daemon for 10 minutes**

```bash
# Check Kudu logs
curl -s "https://vatcscc.scm.azurewebsites.net/api/vfs/LogFiles/swim_sync.log" -u "$vatcscc:..." | tail -50
# Expected: no errors, normal insert/update/unchanged counts
```

- [ ] **Step 7: Monitor viff_cdm_poll_daemon**

```bash
# Check Kudu logs
curl -s "https://vatcscc.scm.azurewebsites.net/api/vfs/LogFiles/viff_cdm_poll.log" -u "$vatcscc:..." | tail -20
# Expected: successful GUFI batch lookups via gufi_legacy column
```

---

## Rollback Plan

If issues arise after deployment:

1. **PHP rollback**: `git revert HEAD && git push` (reverts PHP changes, redeploys old code)
2. **SP rollback**: Re-deploy `sp_Swim_BulkUpsert` v3 from `database/migrations/swim/031_sp_swim_bulk_upsert_v3.sql`
3. **Column rollback** (only if needed — destructive):
   ```sql
   DROP INDEX IX_swim_flights_gufi ON dbo.swim_flights;
   ALTER TABLE dbo.swim_flights DROP CONSTRAINT DF_swim_flights_gufi;
   ALTER TABLE dbo.swim_flights DROP COLUMN gufi;
   EXEC sp_rename 'dbo.swim_flights.gufi_legacy', 'gufi', 'COLUMN';
   CREATE INDEX IX_swim_flights_gufi ON dbo.swim_flights (gufi) WHERE gufi IS NOT NULL;
   ```
