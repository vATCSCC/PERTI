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
