# TMI Trajectory System Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Create a high-resolution trajectory table (`adl_tmi_trajectory`) that preserves 15-30-60 second position data for TMI-relevant flights, enabling accurate post-event compliance analysis for up to 90 days.

**Architecture:** Supplement the existing archive flow with a TMI-aware extraction step. Before positions are downsampled by the archive daemon, an atomic procedure extracts TMI-relevant rows to a dedicated table with tier-based resolution. A unified view provides seamless querying across both tables.

**Tech Stack:** Azure SQL Server (VATSIM_ADL), T-SQL stored procedures, PostGIS (VATSIM_GIS) for ARTCC tier lookups

---

## Background Context

### Tier Definitions

| Tier | Resolution | Scope |
|------|------------|-------|
| T-0 | 15 sec | Event traffic: arr/dep from featured facility OR traverses parent ARTCC of featured facilities |
| T-1 | 30 sec | Adjacent: arr/dep/overflight through Tier 1 or Tier 2 neighboring ARTCCs |
| T-2 | 1 min | Within US/CA/MX/LATAM/CAR coverage (incl. oceanic) but not T-0/T-1 |

### Key Tables Reference

- `adl_flight_trajectory` - Live positions (HOT, ~24h retention)
- `adl_trajectory_archive` - Historical positions (WARM 60s/7d, COLD 5min/90d)
- `perti_events` - Event definitions with `featured_airports` JSON and computed `logging_start_utc`/`logging_end_utc`
- `adl_flight_core` - Flight master with `flight_uid`
- `adl_flight_plan` - Route info with `fp_dept_icao`, `fp_dest_icao`
- `adl_flight_position` - Current position with `current_artcc`

### Existing Archive Flow

The archive daemon runs periodically and:
1. Moves rows from `adl_flight_trajectory` to `adl_trajectory_archive`
2. Downsamples based on age (WARM → COLD)
3. Purges data older than 90 days

**Our change:** Insert a TMI extraction step BEFORE archiving so high-res data is preserved.

---

## Task 1: Create TMI Trajectory Table

**Files:**
- Create: `database/migrations/adl/010_create_tmi_trajectory.sql`

**Step 1: Write the migration script**

```sql
-- ============================================================================
-- ADL Migration 010: TMI High-Resolution Trajectory Table
--
-- Purpose: Store high-resolution trajectory data for TMI compliance analysis
-- Retention: 90 days at tier-specific resolution (15s/30s/60s)
--
-- Target Database: VATSIM_ADL
-- Depends on: adl_flight_core, perti_events
-- ============================================================================

SET ANSI_NULLS ON;
SET QUOTED_IDENTIFIER ON;
GO

PRINT '=== ADL Migration 010: TMI Trajectory Table ===';
PRINT 'Started at: ' + CONVERT(VARCHAR, GETUTCDATE(), 120);
GO

-- ============================================================================
-- 1. Create TMI Trajectory Table
-- ============================================================================

IF NOT EXISTS (SELECT * FROM sys.objects WHERE object_id = OBJECT_ID(N'dbo.adl_tmi_trajectory') AND type = 'U')
BEGIN
    CREATE TABLE dbo.adl_tmi_trajectory (
        tmi_trajectory_id   BIGINT IDENTITY(1,1) NOT NULL,
        flight_uid          BIGINT NOT NULL,

        -- Position data (matches adl_flight_trajectory)
        timestamp_utc       DATETIME2(0) NOT NULL,
        lat                 DECIMAL(10,7) NOT NULL,
        lon                 DECIMAL(11,7) NOT NULL,
        altitude_ft         INT NULL,
        groundspeed_kts     INT NULL,
        track_deg           SMALLINT NULL,
        vertical_rate_fpm   INT NULL,

        -- TMI-specific metadata
        tmi_tier            TINYINT NOT NULL,          -- 0=15s, 1=30s, 2=1min
        perti_event_id      INT NULL,                  -- FK to perti_events (for T-0)

        CONSTRAINT PK_adl_tmi_trajectory PRIMARY KEY CLUSTERED (tmi_trajectory_id),
        CONSTRAINT FK_tmi_traj_core FOREIGN KEY (flight_uid)
            REFERENCES dbo.adl_flight_core(flight_uid) ON DELETE CASCADE,
        CONSTRAINT FK_tmi_traj_event FOREIGN KEY (perti_event_id)
            REFERENCES dbo.perti_events(event_id) ON DELETE SET NULL,
        CONSTRAINT CK_tmi_tier CHECK (tmi_tier IN (0, 1, 2))
    ) WITH (DATA_COMPRESSION = PAGE);

    PRINT 'Created table dbo.adl_tmi_trajectory with PAGE compression';
END
ELSE
BEGIN
    PRINT 'Table dbo.adl_tmi_trajectory already exists - skipping';
END
GO

-- ============================================================================
-- 2. Create Indexes
-- ============================================================================

-- Primary query pattern: flight-based trajectory retrieval
IF NOT EXISTS (SELECT * FROM sys.indexes WHERE object_id = OBJECT_ID(N'dbo.adl_tmi_trajectory') AND name = 'IX_tmi_traj_flight_time')
BEGIN
    CREATE NONCLUSTERED INDEX IX_tmi_traj_flight_time
        ON dbo.adl_tmi_trajectory (flight_uid, timestamp_utc DESC)
        INCLUDE (lat, lon, groundspeed_kts, altitude_ft);
    PRINT 'Created index IX_tmi_traj_flight_time';
END
GO

-- Event-based analysis
IF NOT EXISTS (SELECT * FROM sys.indexes WHERE object_id = OBJECT_ID(N'dbo.adl_tmi_trajectory') AND name = 'IX_tmi_traj_event_time')
BEGIN
    CREATE NONCLUSTERED INDEX IX_tmi_traj_event_time
        ON dbo.adl_tmi_trajectory (perti_event_id, timestamp_utc DESC)
        WHERE perti_event_id IS NOT NULL;
    PRINT 'Created filtered index IX_tmi_traj_event_time';
END
GO

-- Purge + time-range queries
IF NOT EXISTS (SELECT * FROM sys.indexes WHERE object_id = OBJECT_ID(N'dbo.adl_tmi_trajectory') AND name = 'IX_tmi_traj_timestamp')
BEGIN
    CREATE NONCLUSTERED INDEX IX_tmi_traj_timestamp
        ON dbo.adl_tmi_trajectory (timestamp_utc DESC);
    PRINT 'Created index IX_tmi_traj_timestamp';
END
GO

-- Duplicate prevention (idempotent inserts)
IF NOT EXISTS (SELECT * FROM sys.indexes WHERE object_id = OBJECT_ID(N'dbo.adl_tmi_trajectory') AND name = 'UX_tmi_traj_flight_time')
BEGIN
    CREATE UNIQUE NONCLUSTERED INDEX UX_tmi_traj_flight_time
        ON dbo.adl_tmi_trajectory (flight_uid, timestamp_utc)
        WITH (IGNORE_DUP_KEY = ON);
    PRINT 'Created unique index UX_tmi_traj_flight_time with IGNORE_DUP_KEY';
END
GO

PRINT '';
PRINT '=== ADL Migration 010 Complete ===';
PRINT 'Finished at: ' + CONVERT(VARCHAR, GETUTCDATE(), 120);
GO
```

**Step 2: Verify script syntax locally**

Open in SSMS or Azure Data Studio and check for syntax errors (don't execute yet).

**Step 3: Commit the migration script**

```bash
git add database/migrations/adl/010_create_tmi_trajectory.sql
git commit -m "feat(adl): add TMI trajectory table migration script

- Creates adl_tmi_trajectory table with tier-based resolution
- PAGE compression enabled for storage efficiency
- Indexes for flight, event, and time-based queries
- IGNORE_DUP_KEY for idempotent inserts"
```

---

## Task 2: Create ARTCC Tier Lookup Table

**Files:**
- Create: `database/migrations/adl/011_artcc_tier_lookup.sql`

**Context:** We need a fast lookup to determine if an ARTCC is adjacent (Tier 1) or 2-hops away (Tier 2) from event ARTCCs. This comes from PostGIS but we cache it in ADL for performance.

**Step 1: Write the lookup table migration**

```sql
-- ============================================================================
-- ADL Migration 011: ARTCC Adjacency Lookup for TMI Tier Calculation
--
-- Purpose: Cache ARTCC neighbor relationships for fast tier determination
-- Source: Derived from PostGIS artcc_boundaries spatial relationships
--
-- Target Database: VATSIM_ADL
-- ============================================================================

SET ANSI_NULLS ON;
SET QUOTED_IDENTIFIER ON;
GO

PRINT '=== ADL Migration 011: ARTCC Tier Lookup ===';
GO

-- ============================================================================
-- 1. ARTCC Adjacency Table
-- ============================================================================

IF NOT EXISTS (SELECT * FROM sys.objects WHERE object_id = OBJECT_ID(N'dbo.ref_artcc_adjacency') AND type = 'U')
BEGIN
    CREATE TABLE dbo.ref_artcc_adjacency (
        artcc_code          CHAR(3) NOT NULL,
        neighbor_code       CHAR(3) NOT NULL,
        hop_distance        TINYINT NOT NULL,  -- 1 = adjacent, 2 = 2 hops

        CONSTRAINT PK_artcc_adjacency PRIMARY KEY (artcc_code, neighbor_code),
        CONSTRAINT CK_hop_distance CHECK (hop_distance IN (1, 2))
    );

    PRINT 'Created table dbo.ref_artcc_adjacency';
END
GO

-- ============================================================================
-- 2. Seed ARTCC Adjacency Data (CONUS + adjacent)
-- ============================================================================

-- Clear and reseed
TRUNCATE TABLE dbo.ref_artcc_adjacency;

-- Tier 1 neighbors (directly adjacent ARTCCs)
-- This data derived from PostGIS ST_Touches analysis
INSERT INTO dbo.ref_artcc_adjacency (artcc_code, neighbor_code, hop_distance) VALUES
-- ZNY (New York) neighbors
('ZNY', 'ZBW', 1), ('ZNY', 'ZOB', 1), ('ZNY', 'ZDC', 1),
-- ZDC (Washington) neighbors
('ZDC', 'ZNY', 1), ('ZDC', 'ZOB', 1), ('ZDC', 'ZID', 1), ('ZDC', 'ZTL', 1), ('ZDC', 'ZJX', 1),
-- ZTL (Atlanta) neighbors
('ZTL', 'ZDC', 1), ('ZTL', 'ZJX', 1), ('ZTL', 'ZME', 1), ('ZTL', 'ZID', 1), ('ZTL', 'ZHU', 1),
-- ZJX (Jacksonville) neighbors
('ZJX', 'ZDC', 1), ('ZJX', 'ZTL', 1), ('ZJX', 'ZMA', 1), ('ZJX', 'ZHU', 1),
-- ZMA (Miami) neighbors
('ZMA', 'ZJX', 1), ('ZMA', 'ZHU', 1),
-- ZHU (Houston) neighbors
('ZHU', 'ZTL', 1), ('ZHU', 'ZJX', 1), ('ZHU', 'ZMA', 1), ('ZHU', 'ZME', 1), ('ZHU', 'ZFW', 1), ('ZHU', 'ZAB', 1),
-- ZME (Memphis) neighbors
('ZME', 'ZTL', 1), ('ZME', 'ZID', 1), ('ZME', 'ZKC', 1), ('ZME', 'ZFW', 1), ('ZME', 'ZHU', 1),
-- ZID (Indianapolis) neighbors
('ZID', 'ZOB', 1), ('ZID', 'ZDC', 1), ('ZID', 'ZTL', 1), ('ZID', 'ZME', 1), ('ZID', 'ZKC', 1), ('ZID', 'ZAU', 1),
-- ZOB (Cleveland) neighbors
('ZOB', 'ZNY', 1), ('ZOB', 'ZDC', 1), ('ZOB', 'ZID', 1), ('ZOB', 'ZAU', 1), ('ZOB', 'ZBW', 1),
-- ZBW (Boston) neighbors
('ZBW', 'ZNY', 1), ('ZBW', 'ZOB', 1),
-- ZAU (Chicago) neighbors
('ZAU', 'ZOB', 1), ('ZAU', 'ZID', 1), ('ZAU', 'ZKC', 1), ('ZAU', 'ZMP', 1),
-- ZKC (Kansas City) neighbors
('ZKC', 'ZAU', 1), ('ZKC', 'ZID', 1), ('ZKC', 'ZME', 1), ('ZKC', 'ZFW', 1), ('ZKC', 'ZAB', 1), ('ZKC', 'ZDV', 1), ('ZKC', 'ZMP', 1),
-- ZFW (Fort Worth) neighbors
('ZFW', 'ZKC', 1), ('ZFW', 'ZME', 1), ('ZFW', 'ZHU', 1), ('ZFW', 'ZAB', 1),
-- ZAB (Albuquerque) neighbors
('ZAB', 'ZKC', 1), ('ZAB', 'ZFW', 1), ('ZAB', 'ZHU', 1), ('ZAB', 'ZDV', 1), ('ZAB', 'ZLA', 1),
-- ZDV (Denver) neighbors
('ZDV', 'ZKC', 1), ('ZDV', 'ZAB', 1), ('ZDV', 'ZLA', 1), ('ZDV', 'ZLC', 1), ('ZDV', 'ZMP', 1),
-- ZMP (Minneapolis) neighbors
('ZMP', 'ZAU', 1), ('ZMP', 'ZKC', 1), ('ZMP', 'ZDV', 1), ('ZMP', 'ZLC', 1), ('ZMP', 'ZSE', 1),
-- ZLC (Salt Lake) neighbors
('ZLC', 'ZDV', 1), ('ZLC', 'ZMP', 1), ('ZLC', 'ZLA', 1), ('ZLC', 'ZOA', 1), ('ZLC', 'ZSE', 1),
-- ZLA (Los Angeles) neighbors
('ZLA', 'ZAB', 1), ('ZLA', 'ZDV', 1), ('ZLA', 'ZLC', 1), ('ZLA', 'ZOA', 1),
-- ZOA (Oakland) neighbors
('ZOA', 'ZLA', 1), ('ZOA', 'ZLC', 1), ('ZOA', 'ZSE', 1),
-- ZSE (Seattle) neighbors
('ZSE', 'ZOA', 1), ('ZSE', 'ZLC', 1), ('ZSE', 'ZMP', 1);

PRINT 'Inserted Tier 1 (adjacent) ARTCC relationships';

-- Tier 2 neighbors (2 hops away) - computed from Tier 1
INSERT INTO dbo.ref_artcc_adjacency (artcc_code, neighbor_code, hop_distance)
SELECT DISTINCT a1.artcc_code, a2.neighbor_code, 2
FROM dbo.ref_artcc_adjacency a1
JOIN dbo.ref_artcc_adjacency a2 ON a1.neighbor_code = a2.artcc_code
WHERE a1.hop_distance = 1
  AND a2.hop_distance = 1
  AND a1.artcc_code <> a2.neighbor_code
  AND NOT EXISTS (
      SELECT 1 FROM dbo.ref_artcc_adjacency x
      WHERE x.artcc_code = a1.artcc_code
        AND x.neighbor_code = a2.neighbor_code
  );

PRINT 'Computed Tier 2 (2-hop) ARTCC relationships';

-- Report counts
SELECT hop_distance, COUNT(*) AS relationship_count
FROM dbo.ref_artcc_adjacency
GROUP BY hop_distance;

GO

PRINT '=== ADL Migration 011 Complete ===';
GO
```

**Step 2: Commit**

```bash
git add database/migrations/adl/011_artcc_tier_lookup.sql
git commit -m "feat(adl): add ARTCC adjacency lookup table for TMI tier calculation

- ref_artcc_adjacency stores neighbor relationships
- Tier 1 = adjacent ARTCCs (hop_distance=1)
- Tier 2 = 2 hops away (hop_distance=2)
- Data derived from PostGIS spatial analysis"
```

---

## Task 3: Create Coverage Area Check Function

**Files:**
- Create: `database/migrations/adl/012_tmi_coverage_functions.sql`

**Step 1: Write the coverage function**

```sql
-- ============================================================================
-- ADL Migration 012: TMI Coverage Area Functions
--
-- Purpose: Functions to determine if a flight is within TMI coverage area
-- Coverage: US, Canada, Mexico, Latin America, Caribbean (incl. oceanic)
--
-- Target Database: VATSIM_ADL
-- ============================================================================

SET ANSI_NULLS ON;
SET QUOTED_IDENTIFIER ON;
GO

PRINT '=== ADL Migration 012: TMI Coverage Functions ===';
GO

-- ============================================================================
-- 1. Coverage Area Check (Bounding Box Approximation)
-- ============================================================================

CREATE OR ALTER FUNCTION dbo.fn_IsInTmiCoverage(
    @lat DECIMAL(10,7),
    @lon DECIMAL(11,7)
)
RETURNS BIT
AS
BEGIN
    -- Coverage area: US/CA/MX/LATAM/CAR including oceanic approaches
    -- Approximate bounding box (generous to include oceanic)
    -- North: 72°N (Arctic Canada)
    -- South: -60°S (South America tip)
    -- West: -180° (includes Pacific oceanic)
    -- East: -25° (includes Atlantic oceanic approaches)

    DECLARE @inCoverage BIT = 0;

    IF @lat BETWEEN -60.0 AND 72.0
       AND @lon BETWEEN -180.0 AND -25.0
    BEGIN
        SET @inCoverage = 1;
    END

    RETURN @inCoverage;
END
GO

PRINT 'Created function dbo.fn_IsInTmiCoverage';
GO

-- ============================================================================
-- 2. Airport to Parent ARTCC Lookup
-- ============================================================================

CREATE OR ALTER FUNCTION dbo.fn_GetAirportArtcc(
    @airport_icao CHAR(4)
)
RETURNS CHAR(3)
AS
BEGIN
    DECLARE @artcc CHAR(3);

    -- Look up from reference data
    SELECT @artcc = artcc_code
    FROM dbo.ref_airports
    WHERE icao_code = @airport_icao;

    -- Fallback: derive from common patterns
    IF @artcc IS NULL
    BEGIN
        SET @artcc = CASE
            -- Major hubs with known ARTCCs
            WHEN @airport_icao IN ('KJFK', 'KLGA', 'KEWR', 'KTEB') THEN 'ZNY'
            WHEN @airport_icao IN ('KBOS', 'KPVD', 'KBDL') THEN 'ZBW'
            WHEN @airport_icao IN ('KDCA', 'KIAD', 'KBWI', 'KPHL') THEN 'ZDC'
            WHEN @airport_icao IN ('KATL', 'KCLT', 'KBNA') THEN 'ZTL'
            WHEN @airport_icao IN ('KMIA', 'KFLL', 'KPBI', 'KMCO') THEN 'ZMA'
            WHEN @airport_icao IN ('KJAX', 'KTPA', 'KRSW') THEN 'ZJX'
            WHEN @airport_icao IN ('KORD', 'KMDW', 'KMKE') THEN 'ZAU'
            WHEN @airport_icao IN ('KDTW', 'KCLE', 'KPIT', 'KCMH') THEN 'ZOB'
            WHEN @airport_icao IN ('KIND', 'KCVG', 'KSDF') THEN 'ZID'
            WHEN @airport_icao IN ('KMEM', 'KSTL', 'KLIT') THEN 'ZME'
            WHEN @airport_icao IN ('KMSP', 'KFAR') THEN 'ZMP'
            WHEN @airport_icao IN ('KMCI', 'KOMA', 'KDSM') THEN 'ZKC'
            WHEN @airport_icao IN ('KDFW', 'KDAL', 'KAUS', 'KSAT', 'KHOU') THEN 'ZFW'
            WHEN @airport_icao IN ('KIAH', 'KMSY', 'KBTR') THEN 'ZHU'
            WHEN @airport_icao IN ('KDEN', 'KCOS', 'KABQ') THEN 'ZDV'
            WHEN @airport_icao IN ('KPHX', 'KTUS', 'KELP') THEN 'ZAB'
            WHEN @airport_icao IN ('KSLC', 'KBOI') THEN 'ZLC'
            WHEN @airport_icao IN ('KLAX', 'KSAN', 'KLAS', 'KONT', 'KBURBANK') THEN 'ZLA'
            WHEN @airport_icao IN ('KSFO', 'KOAK', 'KSJC', 'KSMF') THEN 'ZOA'
            WHEN @airport_icao IN ('KSEA', 'KPDX', 'KGEG') THEN 'ZSE'
            ELSE NULL
        END;
    END

    RETURN @artcc;
END
GO

PRINT 'Created function dbo.fn_GetAirportArtcc';
GO

PRINT '=== ADL Migration 012 Complete ===';
GO
```

**Step 2: Commit**

```bash
git add database/migrations/adl/012_tmi_coverage_functions.sql
git commit -m "feat(adl): add TMI coverage area check functions

- fn_IsInTmiCoverage: bounding box check for US/CA/MX/LATAM/CAR
- fn_GetAirportArtcc: lookup airport parent ARTCC"
```

---

## Task 4: Create TMI Tier Calculation Function

**Files:**
- Create: `database/migrations/adl/013_compute_tmi_tier.sql`

**Step 1: Write the tier calculation function**

```sql
-- ============================================================================
-- ADL Migration 013: TMI Tier Calculation Function
--
-- Purpose: Compute TMI tier (0, 1, 2, or NULL) for a flight at a given time
-- Returns: Table with tmi_tier and perti_event_id
--
-- Target Database: VATSIM_ADL
-- Depends on: perti_events, ref_artcc_adjacency, fn_IsInTmiCoverage, fn_GetAirportArtcc
-- ============================================================================

SET ANSI_NULLS ON;
SET QUOTED_IDENTIFIER ON;
GO

PRINT '=== ADL Migration 013: TMI Tier Calculation ===';
GO

-- ============================================================================
-- 1. Main Tier Calculation Function (Table-Valued)
-- ============================================================================

CREATE OR ALTER FUNCTION dbo.fn_ComputeTmiTier(
    @flight_uid BIGINT,
    @timestamp_utc DATETIME2(0)
)
RETURNS @result TABLE (
    tmi_tier        TINYINT NULL,
    perti_event_id  INT NULL
)
AS
BEGIN
    DECLARE @dept_icao CHAR(4), @dest_icao CHAR(4);
    DECLARE @dept_artcc CHAR(3), @dest_artcc CHAR(3), @current_artcc CHAR(3);
    DECLARE @current_lat DECIMAL(10,7), @current_lon DECIMAL(11,7);
    DECLARE @event_id INT, @tier TINYINT;

    -- Get flight info
    SELECT
        @dept_icao = p.fp_dept_icao,
        @dest_icao = p.fp_dest_icao,
        @current_lat = pos.lat,
        @current_lon = pos.lon,
        @current_artcc = pos.current_artcc
    FROM dbo.adl_flight_core c
    LEFT JOIN dbo.adl_flight_plan p ON c.flight_uid = p.flight_uid
    LEFT JOIN dbo.adl_flight_position pos ON c.flight_uid = pos.flight_uid
    WHERE c.flight_uid = @flight_uid;

    -- Get ARTCCs for origin/destination
    SET @dept_artcc = dbo.fn_GetAirportArtcc(@dept_icao);
    SET @dest_artcc = dbo.fn_GetAirportArtcc(@dest_icao);

    -- Check if outside coverage area entirely
    IF dbo.fn_IsInTmiCoverage(@current_lat, @current_lon) = 0
    BEGIN
        INSERT INTO @result (tmi_tier, perti_event_id) VALUES (NULL, NULL);
        RETURN;
    END

    -- Find active event that matches this flight
    SELECT TOP 1 @event_id = e.event_id
    FROM dbo.perti_events e
    WHERE @timestamp_utc BETWEEN e.logging_start_utc AND e.logging_end_utc
      AND e.logging_enabled = 1
      AND (
          -- Departure from featured airport
          EXISTS (
              SELECT 1 FROM OPENJSON(e.featured_airports) fa
              WHERE fa.value IN (@dept_icao, 'K' + SUBSTRING(@dept_icao, 2, 3), SUBSTRING(@dept_icao, 2, 3))
          )
          -- OR arrival at featured airport
          OR EXISTS (
              SELECT 1 FROM OPENJSON(e.featured_airports) fa
              WHERE fa.value IN (@dest_icao, 'K' + SUBSTRING(@dest_icao, 2, 3), SUBSTRING(@dest_icao, 2, 3))
          )
          -- OR traversing parent ARTCC of featured airports
          OR EXISTS (
              SELECT 1 FROM OPENJSON(e.featured_airports) fa
              WHERE dbo.fn_GetAirportArtcc(fa.value) IN (@dept_artcc, @dest_artcc, @current_artcc)
          )
      )
    ORDER BY e.event_id;  -- Prefer earliest event if multiple overlap

    -- T-0: Direct event traffic
    IF @event_id IS NOT NULL
    BEGIN
        INSERT INTO @result (tmi_tier, perti_event_id) VALUES (0, @event_id);
        RETURN;
    END

    -- Check for T-1 (adjacent to any active event's ARTCCs)
    IF EXISTS (
        SELECT 1
        FROM dbo.perti_events e
        CROSS APPLY OPENJSON(e.featured_airports) fa
        JOIN dbo.ref_artcc_adjacency adj ON adj.artcc_code = dbo.fn_GetAirportArtcc(fa.value)
        WHERE @timestamp_utc BETWEEN e.logging_start_utc AND e.logging_end_utc
          AND e.logging_enabled = 1
          AND adj.hop_distance IN (1, 2)  -- Tier 1 or 2 neighbors
          AND adj.neighbor_code IN (@dept_artcc, @dest_artcc, @current_artcc)
    )
    BEGIN
        INSERT INTO @result (tmi_tier, perti_event_id) VALUES (1, NULL);
        RETURN;
    END

    -- T-2: In coverage area but not T-0 or T-1
    INSERT INTO @result (tmi_tier, perti_event_id) VALUES (2, NULL);
    RETURN;
END
GO

PRINT 'Created function dbo.fn_ComputeTmiTier';
GO

-- ============================================================================
-- 2. Batch Version for Archive Processing
-- ============================================================================

CREATE OR ALTER FUNCTION dbo.fn_ComputeTmiTierBatch(
    @timestamp_utc DATETIME2(0)
)
RETURNS TABLE
AS
RETURN
(
    WITH ActiveEvents AS (
        SELECT
            e.event_id,
            e.featured_airports,
            e.logging_start_utc,
            e.logging_end_utc
        FROM dbo.perti_events e
        WHERE @timestamp_utc BETWEEN e.logging_start_utc AND e.logging_end_utc
          AND e.logging_enabled = 1
    ),
    FeaturedArtccs AS (
        SELECT DISTINCT
            e.event_id,
            dbo.fn_GetAirportArtcc(fa.value) AS artcc_code
        FROM ActiveEvents e
        CROSS APPLY OPENJSON(e.featured_airports) fa
    ),
    AdjacentArtccs AS (
        SELECT DISTINCT
            adj.neighbor_code AS artcc_code,
            1 AS is_adjacent
        FROM FeaturedArtccs fa
        JOIN dbo.ref_artcc_adjacency adj ON adj.artcc_code = fa.artcc_code
        WHERE adj.hop_distance IN (1, 2)
    )
    SELECT
        c.flight_uid,
        CASE
            -- T-0: Event traffic (arr/dep from featured OR in featured ARTCC)
            WHEN EXISTS (
                SELECT 1 FROM FeaturedArtccs fa
                WHERE fa.artcc_code IN (
                    dbo.fn_GetAirportArtcc(p.fp_dept_icao),
                    dbo.fn_GetAirportArtcc(p.fp_dest_icao),
                    pos.current_artcc
                )
            ) THEN CAST(0 AS TINYINT)

            -- T-1: Adjacent to event ARTCCs
            WHEN EXISTS (
                SELECT 1 FROM AdjacentArtccs aa
                WHERE aa.artcc_code IN (
                    dbo.fn_GetAirportArtcc(p.fp_dept_icao),
                    dbo.fn_GetAirportArtcc(p.fp_dest_icao),
                    pos.current_artcc
                )
            ) THEN CAST(1 AS TINYINT)

            -- T-2: In coverage area
            WHEN dbo.fn_IsInTmiCoverage(pos.lat, pos.lon) = 1 THEN CAST(2 AS TINYINT)

            ELSE NULL  -- Outside coverage
        END AS tmi_tier,

        (SELECT TOP 1 fa.event_id FROM FeaturedArtccs fa
         WHERE fa.artcc_code IN (
             dbo.fn_GetAirportArtcc(p.fp_dept_icao),
             dbo.fn_GetAirportArtcc(p.fp_dest_icao)
         )) AS perti_event_id

    FROM dbo.adl_flight_core c
    LEFT JOIN dbo.adl_flight_plan p ON c.flight_uid = p.flight_uid
    LEFT JOIN dbo.adl_flight_position pos ON c.flight_uid = pos.flight_uid
    WHERE c.last_seen_utc > DATEADD(HOUR, -2, @timestamp_utc)  -- Recent flights only
);
GO

PRINT 'Created function dbo.fn_ComputeTmiTierBatch';
GO

PRINT '=== ADL Migration 013 Complete ===';
GO
```

**Step 2: Commit**

```bash
git add database/migrations/adl/013_compute_tmi_tier.sql
git commit -m "feat(adl): add TMI tier calculation functions

- fn_ComputeTmiTier: single flight tier calculation
- fn_ComputeTmiTierBatch: batch calculation for archive processing
- Checks event traffic, ARTCC adjacency, coverage area"
```

---

## Task 5: Create Atomic Archive Procedure

**Files:**
- Create: `database/migrations/adl/014_archive_tmi_aware.sql`

**Step 1: Write the TMI-aware archive procedure**

```sql
-- ============================================================================
-- ADL Migration 014: TMI-Aware Archive Procedure
--
-- Purpose: Extract TMI-relevant trajectory data BEFORE archive downsampling
-- Atomic: TMI extraction + archive in single transaction
--
-- Target Database: VATSIM_ADL
-- Depends on: adl_tmi_trajectory, fn_ComputeTmiTierBatch
-- ============================================================================

SET ANSI_NULLS ON;
SET QUOTED_IDENTIFIER ON;
GO

PRINT '=== ADL Migration 014: TMI-Aware Archive Procedure ===';
GO

CREATE OR ALTER PROCEDURE dbo.sp_ArchiveTrajectory_TmiAware
    @archive_threshold_hours INT = 1,  -- Archive positions older than this
    @batch_size INT = 10000            -- Process in batches
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;

    DECLARE @log_id INT;
    DECLARE @start_time DATETIME2(3) = SYSUTCDATETIME();
    DECLARE @cutoff_utc DATETIME2(0) = DATEADD(HOUR, -@archive_threshold_hours, SYSUTCDATETIME());
    DECLARE @rows_tmi INT = 0, @rows_archived INT = 0, @rows_deleted INT = 0;
    DECLARE @batch_count INT = 0;
    DECLARE @error_message NVARCHAR(MAX);

    -- Log job start
    INSERT INTO dbo.adl_archive_log (job_name, started_utc, status)
    VALUES ('TMI_TRAJECTORY_ARCHIVE', @start_time, 'RUNNING');
    SET @log_id = SCOPE_IDENTITY();

    BEGIN TRY
        -- Process in batches to avoid lock escalation
        WHILE 1 = 1
        BEGIN
            BEGIN TRANSACTION;

            -- 1. Identify batch of rows to process
            SELECT TOP (@batch_size)
                t.trajectory_id,
                t.flight_uid,
                t.timestamp_utc,
                t.lat,
                t.lon,
                t.altitude_ft,
                t.groundspeed_kts,
                t.track_deg,
                t.vertical_rate_fpm,
                tier.tmi_tier,
                tier.perti_event_id
            INTO #pending_batch
            FROM dbo.adl_flight_trajectory t
            CROSS APPLY dbo.fn_ComputeTmiTier(t.flight_uid, t.timestamp_utc) tier
            WHERE t.timestamp_utc < @cutoff_utc
            ORDER BY t.trajectory_id;

            IF @@ROWCOUNT = 0
            BEGIN
                DROP TABLE IF EXISTS #pending_batch;
                COMMIT TRANSACTION;
                BREAK;  -- No more rows to process
            END

            SET @batch_count = @batch_count + 1;

            -- 2. Extract TMI-relevant rows (T-0, T-1, T-2) to TMI table
            INSERT INTO dbo.adl_tmi_trajectory (
                flight_uid, timestamp_utc, lat, lon, altitude_ft,
                groundspeed_kts, track_deg, vertical_rate_fpm,
                tmi_tier, perti_event_id
            )
            SELECT
                flight_uid, timestamp_utc, lat, lon, altitude_ft,
                groundspeed_kts, track_deg, vertical_rate_fpm,
                tmi_tier, perti_event_id
            FROM #pending_batch
            WHERE tmi_tier IS NOT NULL;  -- In coverage area

            SET @rows_tmi = @rows_tmi + @@ROWCOUNT;

            -- 3. Move to archive (existing archive logic would go here)
            -- For now, we insert to archive with downsampling
            INSERT INTO dbo.adl_trajectory_archive (
                flight_uid, callsign, timestamp_utc, lat, lon,
                altitude_ft, groundspeed_kts, heading_deg, vertical_rate_fpm,
                sample_interval_sec, source_tier
            )
            SELECT
                p.flight_uid,
                c.callsign,
                p.timestamp_utc,
                p.lat,
                p.lon,
                p.altitude_ft,
                p.groundspeed_kts,
                p.track_deg,  -- Using track_deg as heading
                p.vertical_rate_fpm,
                60,  -- WARM tier = 60 sec
                'WARM'
            FROM #pending_batch p
            JOIN dbo.adl_flight_core c ON p.flight_uid = c.flight_uid
            WHERE p.trajectory_id % 4 = 0;  -- Downsample to ~60 sec (every 4th 15-sec point)

            SET @rows_archived = @rows_archived + @@ROWCOUNT;

            -- 4. Delete from hot table
            DELETE t
            FROM dbo.adl_flight_trajectory t
            WHERE EXISTS (
                SELECT 1 FROM #pending_batch p
                WHERE p.trajectory_id = t.trajectory_id
            );

            SET @rows_deleted = @rows_deleted + @@ROWCOUNT;

            DROP TABLE #pending_batch;

            COMMIT TRANSACTION;

            -- Brief pause between batches to reduce lock contention
            WAITFOR DELAY '00:00:00.100';  -- 100ms
        END

        -- Log success
        UPDATE dbo.adl_archive_log
        SET ended_utc = SYSUTCDATETIME(),
            duration_ms = DATEDIFF(MILLISECOND, @start_time, SYSUTCDATETIME()),
            rows_processed = @rows_deleted,
            rows_archived = @rows_archived,
            status = 'SUCCESS'
        WHERE log_id = @log_id;

        -- Return summary
        SELECT
            @batch_count AS batches_processed,
            @rows_tmi AS rows_to_tmi_table,
            @rows_archived AS rows_to_archive,
            @rows_deleted AS rows_deleted_from_hot,
            DATEDIFF(MILLISECOND, @start_time, SYSUTCDATETIME()) AS duration_ms;

    END TRY
    BEGIN CATCH
        IF @@TRANCOUNT > 0 ROLLBACK TRANSACTION;

        SET @error_message = ERROR_MESSAGE();

        UPDATE dbo.adl_archive_log
        SET ended_utc = SYSUTCDATETIME(),
            duration_ms = DATEDIFF(MILLISECOND, @start_time, SYSUTCDATETIME()),
            status = 'FAILED',
            error_message = @error_message
        WHERE log_id = @log_id;

        THROW;
    END CATCH
END
GO

PRINT 'Created procedure dbo.sp_ArchiveTrajectory_TmiAware';
GO

PRINT '=== ADL Migration 014 Complete ===';
GO
```

**Step 2: Commit**

```bash
git add database/migrations/adl/014_archive_tmi_aware.sql
git commit -m "feat(adl): add TMI-aware archive procedure

- sp_ArchiveTrajectory_TmiAware: atomic TMI extraction + archive
- Batch processing to avoid lock escalation
- Logs to adl_archive_log for monitoring
- Extracts T-0/T-1/T-2 to adl_tmi_trajectory before downsampling"
```

---

## Task 6: Create Unified Query View

**Files:**
- Create: `database/migrations/adl/015_tmi_trajectory_view.sql`

**Step 1: Write the unified view**

```sql
-- ============================================================================
-- ADL Migration 015: Unified TMI Trajectory View
--
-- Purpose: Seamless querying across TMI high-res and archive tables
-- Usage: TMI Compliance Analyzer queries this view instead of individual tables
--
-- Target Database: VATSIM_ADL
-- ============================================================================

SET ANSI_NULLS ON;
SET QUOTED_IDENTIFIER ON;
GO

PRINT '=== ADL Migration 015: Unified TMI Trajectory View ===';
GO

CREATE OR ALTER VIEW dbo.vw_trajectory_tmi_complete
AS
-- High-resolution TMI data (T-0, T-1, T-2) - preferred source
SELECT
    t.flight_uid,
    c.callsign,
    t.timestamp_utc,
    t.lat,
    t.lon,
    t.altitude_ft,
    t.groundspeed_kts,
    t.track_deg,
    t.vertical_rate_fpm,
    t.tmi_tier,
    t.perti_event_id,
    'TMI' AS source_table,
    CASE t.tmi_tier
        WHEN 0 THEN 15
        WHEN 1 THEN 30
        WHEN 2 THEN 60
    END AS resolution_sec
FROM dbo.adl_tmi_trajectory t
JOIN dbo.adl_flight_core c ON t.flight_uid = c.flight_uid

UNION ALL

-- Archive data for flights NOT in TMI table (outside coverage or pre-TMI system)
SELECT
    a.flight_uid,
    a.callsign,
    a.timestamp_utc,
    a.lat,
    a.lon,
    a.altitude_ft,
    a.groundspeed_kts,
    a.heading_deg AS track_deg,
    a.vertical_rate_fpm,
    NULL AS tmi_tier,
    NULL AS perti_event_id,
    'ARCHIVE' AS source_table,
    a.sample_interval_sec AS resolution_sec
FROM dbo.adl_trajectory_archive a
WHERE NOT EXISTS (
    -- Exclude rows that exist in TMI table (avoid duplicates)
    SELECT 1 FROM dbo.adl_tmi_trajectory t
    WHERE t.flight_uid = a.flight_uid
      AND t.timestamp_utc = a.timestamp_utc
);
GO

PRINT 'Created view dbo.vw_trajectory_tmi_complete';
GO

-- ============================================================================
-- Helper view: TMI-only data (for compliance analysis)
-- ============================================================================

CREATE OR ALTER VIEW dbo.vw_trajectory_tmi_only
AS
SELECT
    t.tmi_trajectory_id,
    t.flight_uid,
    c.callsign,
    p.fp_dept_icao,
    p.fp_dest_icao,
    t.timestamp_utc,
    t.lat,
    t.lon,
    t.altitude_ft,
    t.groundspeed_kts,
    t.track_deg,
    t.vertical_rate_fpm,
    t.tmi_tier,
    t.perti_event_id,
    e.event_name,
    e.featured_airports
FROM dbo.adl_tmi_trajectory t
JOIN dbo.adl_flight_core c ON t.flight_uid = c.flight_uid
LEFT JOIN dbo.adl_flight_plan p ON t.flight_uid = p.flight_uid
LEFT JOIN dbo.perti_events e ON t.perti_event_id = e.event_id;
GO

PRINT 'Created view dbo.vw_trajectory_tmi_only';
GO

PRINT '=== ADL Migration 015 Complete ===';
GO
```

**Step 2: Commit**

```bash
git add database/migrations/adl/015_tmi_trajectory_view.sql
git commit -m "feat(adl): add unified TMI trajectory views

- vw_trajectory_tmi_complete: combines TMI + archive for seamless queries
- vw_trajectory_tmi_only: TMI data with flight/event details
- Avoids duplicates between tables"
```

---

## Task 7: Create Purge Procedure

**Files:**
- Create: `database/migrations/adl/016_tmi_trajectory_purge.sql`

**Step 1: Write the purge procedure**

```sql
-- ============================================================================
-- ADL Migration 016: TMI Trajectory Purge Procedure
--
-- Purpose: Purge TMI trajectory data older than retention period (90 days)
-- Schedule: Run daily during off-peak hours (0300-0600 UTC)
--
-- Target Database: VATSIM_ADL
-- ============================================================================

SET ANSI_NULLS ON;
SET QUOTED_IDENTIFIER ON;
GO

PRINT '=== ADL Migration 016: TMI Trajectory Purge ===';
GO

CREATE OR ALTER PROCEDURE dbo.sp_PurgeTmiTrajectory
    @retention_days INT = 90,
    @batch_size INT = 50000
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @log_id INT;
    DECLARE @start_time DATETIME2(3) = SYSUTCDATETIME();
    DECLARE @cutoff_utc DATETIME2(0) = DATEADD(DAY, -@retention_days, SYSUTCDATETIME());
    DECLARE @total_deleted INT = 0;
    DECLARE @batch_deleted INT = 1;

    -- Log job start
    INSERT INTO dbo.adl_archive_log (job_name, started_utc, status)
    VALUES ('TMI_TRAJECTORY_PURGE', @start_time, 'RUNNING');
    SET @log_id = SCOPE_IDENTITY();

    BEGIN TRY
        -- Delete in batches to minimize lock duration
        WHILE @batch_deleted > 0
        BEGIN
            DELETE TOP (@batch_size)
            FROM dbo.adl_tmi_trajectory
            WHERE timestamp_utc < @cutoff_utc;

            SET @batch_deleted = @@ROWCOUNT;
            SET @total_deleted = @total_deleted + @batch_deleted;

            -- Brief pause between batches
            IF @batch_deleted > 0
                WAITFOR DELAY '00:00:00.050';  -- 50ms
        END

        -- Log success
        UPDATE dbo.adl_archive_log
        SET ended_utc = SYSUTCDATETIME(),
            duration_ms = DATEDIFF(MILLISECOND, @start_time, SYSUTCDATETIME()),
            rows_deleted = @total_deleted,
            status = 'SUCCESS'
        WHERE log_id = @log_id;

        SELECT @total_deleted AS rows_purged, @retention_days AS retention_days;

    END TRY
    BEGIN CATCH
        UPDATE dbo.adl_archive_log
        SET ended_utc = SYSUTCDATETIME(),
            status = 'FAILED',
            error_message = ERROR_MESSAGE()
        WHERE log_id = @log_id;

        THROW;
    END CATCH
END
GO

PRINT 'Created procedure dbo.sp_PurgeTmiTrajectory';
GO

PRINT '=== ADL Migration 016 Complete ===';
GO
```

**Step 2: Commit**

```bash
git add database/migrations/adl/016_tmi_trajectory_purge.sql
git commit -m "feat(adl): add TMI trajectory purge procedure

- sp_PurgeTmiTrajectory: batch delete with 90-day retention
- Logs to adl_archive_log for monitoring
- Designed for off-peak execution"
```

---

## Task 8: Update TMI Compliance Analyzer

**Files:**
- Modify: `scripts/tmi_compliance/core/analyzer.py` (lines 571-598)

**Step 1: Update the trajectory query to use the unified view**

Find the existing `_preload_trajectories` method and update the query:

```python
# In _preload_trajectories method, replace the UNION query with:

query = self.adl.format_query("""
    SELECT callsign, flight_uid, timestamp_utc, lat, lon, groundspeed_kts, altitude_ft,
           fp_dept_icao, fp_dest_icao, tmi_tier, source_table
    FROM (
        -- Use unified view for seamless TMI + archive access
        SELECT v.callsign, v.flight_uid, v.timestamp_utc,
               v.lat, v.lon, v.groundspeed_kts, v.altitude_ft,
               p.fp_dept_icao, p.fp_dest_icao,
               v.tmi_tier, v.source_table
        FROM dbo.vw_trajectory_tmi_complete v
        INNER JOIN dbo.adl_flight_plan p ON v.flight_uid = p.flight_uid
        WHERE v.timestamp_utc >= %s
          AND v.timestamp_utc <= %s
          AND v.callsign IN ({callsign_in})
    ) combined
    ORDER BY callsign, timestamp_utc
""")
```

**Step 2: Add logging for TMI tier usage**

After fetching trajectories, log the tier distribution:

```python
# After the trajectory fetch loop, add:
tier_counts = {0: 0, 1: 0, 2: 0, None: 0}
for cs, traj in self._trajectory_cache.items():
    if traj:
        tier = traj[0].get('tmi_tier')
        tier_counts[tier] = tier_counts.get(tier, 0) + 1

logger.info(f"  Trajectory tier distribution: T-0={tier_counts[0]}, T-1={tier_counts[1]}, T-2={tier_counts[2]}, Archive={tier_counts[None]}")
```

**Step 3: Commit**

```bash
git add scripts/tmi_compliance/core/analyzer.py
git commit -m "feat(tmi): update analyzer to use unified trajectory view

- Query vw_trajectory_tmi_complete instead of raw tables
- Log tier distribution for monitoring
- Transparent access to high-res TMI data"
```

---

## Task 9: Deploy Migrations to VATSIM_ADL

**Step 1: Run migrations in order**

Connect to VATSIM_ADL via SSMS or sqlcmd:

```bash
sqlcmd -S vatsim.database.windows.net -d VATSIM_ADL -U adl_api_user -P 'CAMRN@11000' -i database/migrations/adl/010_create_tmi_trajectory.sql
sqlcmd -S vatsim.database.windows.net -d VATSIM_ADL -U adl_api_user -P 'CAMRN@11000' -i database/migrations/adl/011_artcc_tier_lookup.sql
sqlcmd -S vatsim.database.windows.net -d VATSIM_ADL -U adl_api_user -P 'CAMRN@11000' -i database/migrations/adl/012_tmi_coverage_functions.sql
sqlcmd -S vatsim.database.windows.net -d VATSIM_ADL -U adl_api_user -P 'CAMRN@11000' -i database/migrations/adl/013_compute_tmi_tier.sql
sqlcmd -S vatsim.database.windows.net -d VATSIM_ADL -U adl_api_user -P 'CAMRN@11000' -i database/migrations/adl/014_archive_tmi_aware.sql
sqlcmd -S vatsim.database.windows.net -d VATSIM_ADL -U adl_api_user -P 'CAMRN@11000' -i database/migrations/adl/015_tmi_trajectory_view.sql
sqlcmd -S vatsim.database.windows.net -d VATSIM_ADL -U adl_api_user -P 'CAMRN@11000' -i database/migrations/adl/016_tmi_trajectory_purge.sql
```

**Step 2: Verify deployment**

```sql
-- Check table exists
SELECT COUNT(*) FROM dbo.adl_tmi_trajectory;

-- Check functions work
SELECT * FROM dbo.fn_ComputeTmiTier(1234567, GETUTCDATE());

-- Check view works
SELECT TOP 10 * FROM dbo.vw_trajectory_tmi_complete;
```

**Step 3: Commit deployment confirmation**

```bash
git add -A
git commit -m "chore: TMI trajectory system deployed to VATSIM_ADL

Migrations 010-016 successfully executed"
```

---

## Task 10: Integrate with Archive Daemon

**Files:**
- Modify: `scripts/archival_daemon.php` (or equivalent scheduled job)

**Step 1: Replace existing archive call with TMI-aware procedure**

Find the trajectory archive section and update:

```php
// Replace existing archive logic with:
$stmt = $conn->prepare("EXEC dbo.sp_ArchiveTrajectory_TmiAware @archive_threshold_hours = 1, @batch_size = 10000");
$stmt->execute();
$result = $stmt->fetch(PDO::FETCH_ASSOC);

error_log("TMI Archive: {$result['rows_to_tmi_table']} TMI rows, {$result['rows_to_archive']} archived, {$result['duration_ms']}ms");
```

**Step 2: Add purge job (daily at 0400 UTC)**

```php
// Add to daily maintenance section:
if (date('H') == '04') {
    $stmt = $conn->prepare("EXEC dbo.sp_PurgeTmiTrajectory @retention_days = 90");
    $stmt->execute();
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    error_log("TMI Purge: {$result['rows_purged']} rows deleted");
}
```

**Step 3: Commit**

```bash
git add scripts/archival_daemon.php
git commit -m "feat: integrate TMI trajectory with archive daemon

- Use sp_ArchiveTrajectory_TmiAware for TMI-aware archiving
- Daily purge at 0400 UTC with 90-day retention"
```

---

## Task 11: Validation Testing

**Step 1: Create test event**

```sql
-- Insert a test event
INSERT INTO dbo.perti_events (
    event_name, event_type, start_utc, end_utc,
    featured_airports, source, logging_enabled
)
VALUES (
    'TMI Trajectory Test Event',
    'FNO',
    DATEADD(HOUR, -1, GETUTCDATE()),
    DATEADD(HOUR, 3, GETUTCDATE()),
    '["KATL", "KJFK", "KLAX"]',
    'MANUAL',
    1
);
```

**Step 2: Run archive procedure manually**

```sql
EXEC dbo.sp_ArchiveTrajectory_TmiAware @archive_threshold_hours = 0, @batch_size = 1000;
```

**Step 3: Verify TMI data was extracted**

```sql
-- Check tier distribution
SELECT tmi_tier, COUNT(*) as row_count
FROM dbo.adl_tmi_trajectory
GROUP BY tmi_tier;

-- Check event association
SELECT TOP 10 t.*, e.event_name
FROM dbo.adl_tmi_trajectory t
LEFT JOIN dbo.perti_events e ON t.perti_event_id = e.event_id
WHERE t.perti_event_id IS NOT NULL;
```

**Step 4: Run TMI Compliance Analysis**

```bash
cd scripts/tmi_compliance
python run.py --plan_id <test_plan_id>
```

Verify output shows high-resolution trajectory data being used.

**Step 5: Clean up test event**

```sql
DELETE FROM dbo.perti_events WHERE event_name = 'TMI Trajectory Test Event';
```

**Step 6: Commit validation results**

```bash
git add -A
git commit -m "test: validate TMI trajectory system

- Test event creation and tier calculation
- Verified archive procedure extracts TMI data
- TMI Compliance Analyzer uses high-res data"
```

---

## Rollout Phases

### Phase 1: T-0 Only (Week 1-2)

Modify `sp_ArchiveTrajectory_TmiAware` to only extract T-0:

```sql
WHERE tmi_tier = 0;  -- T-0 only for Phase 1
```

Monitor storage growth and job duration.

### Phase 2: Add T-1 (Week 3-4)

```sql
WHERE tmi_tier IN (0, 1);  -- T-0 and T-1
```

### Phase 3: Full Production (Week 5+)

```sql
WHERE tmi_tier IS NOT NULL;  -- All tiers
```

---

## Monitoring Queries

```sql
-- Daily TMI trajectory growth
SELECT
    CAST(timestamp_utc AS DATE) AS date,
    tmi_tier,
    COUNT(*) AS rows
FROM dbo.adl_tmi_trajectory
GROUP BY CAST(timestamp_utc AS DATE), tmi_tier
ORDER BY date DESC, tmi_tier;

-- Archive job history
SELECT TOP 20 *
FROM dbo.adl_archive_log
WHERE job_name LIKE 'TMI%'
ORDER BY started_utc DESC;

-- Storage size
SELECT
    OBJECT_NAME(object_id) AS table_name,
    SUM(reserved_page_count) * 8 / 1024.0 AS size_mb
FROM sys.dm_db_partition_stats
WHERE OBJECT_NAME(object_id) LIKE '%tmi%trajectory%'
GROUP BY object_id;
```

---

**Plan complete and saved to `docs/plans/2026-02-02-tmi-trajectory.md`.**

