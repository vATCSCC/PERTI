-- ============================================================================
-- sp_ParseRoute.sql (v4.3 - Enhanced Fix/Runway Extraction)
-- Full GIS Route Parsing for ADL Normalized Schema
--
-- v4.3 Changes (2026-01-31):
--   - NEW: Extract dep_runway and arr_runway from route tokens (e.g., /07C, /02L)
--   - NEW: Extract dfix/afix for ALL routes, not just those with SID/STAR
--   - FIX: Recognize SID/STAR patterns ending with letters (ABTAN2W, DUMEP1T)
--   - FIX: Strip runway suffixes before SID/STAR pattern matching
--
-- v4.2 Changes (2026-01-17):
--   - FIX: Populate waypoints_json column with on_airway field for FEA filtering
--   - Enables airway-based monitor matching in NOD demand layer
--
-- v4.1 Changes (2026-01-10):
--   - FIX: Added dfix (departure fix) population - captures first fix after SID
--   - If SID is followed by airway, dfix is left NULL (no explicit departure fix)
--
-- v4 Changes (2026-01-07):
--   - Consolidated all fix lookups into single proximity-based resolver
--   - Airway-aware disambiguation: prefers fixes actually on the airway
--   - Batch candidate fetch: ONE query to nav_fixes, then in-memory resolution
--   - Proper sequential resolution: each fix uses previous fix's coordinates
--   - Fixes zigzag problem with duplicate fix names across regions
--
-- Performance: O(1) database queries for candidates, O(n) in-memory resolution
-- ============================================================================

SET ANSI_NULLS ON;
GO
SET QUOTED_IDENTIFIER ON;
GO

-- ============================================================================
-- Helper Function: Expand airway to fix NAMES only (no coordinate lookups)
-- Returns just the sequence of fix names between entry and exit
-- Coordinates resolved later using proximity in main procedure
-- ============================================================================
IF OBJECT_ID('dbo.fn_ExpandAirwayNames', 'TF') IS NOT NULL
    DROP FUNCTION dbo.fn_ExpandAirwayNames;
GO

CREATE FUNCTION dbo.fn_ExpandAirwayNames (
    @airway_name NVARCHAR(10),
    @entry_fix NVARCHAR(50),
    @exit_fix NVARCHAR(50)
)
RETURNS @result TABLE (
    seq INT,
    fix_name NVARCHAR(50)
)
AS
BEGIN
    DECLARE @fix_sequence NVARCHAR(MAX);
    DECLARE @fixes TABLE (pos INT IDENTITY(1,1), fix NVARCHAR(50));

    -- Get the airway's fix sequence
    SELECT TOP 1 @fix_sequence = fix_sequence
    FROM dbo.airways
    WHERE airway_name = @airway_name;

    IF @fix_sequence IS NULL
        RETURN;

    -- Split into individual fixes
    INSERT INTO @fixes (fix)
    SELECT value FROM STRING_SPLIT(@fix_sequence, ' ') WHERE LEN(LTRIM(RTRIM(value))) > 0;

    -- Find entry and exit positions
    DECLARE @entry_pos INT, @exit_pos INT;
    SELECT @entry_pos = MIN(pos) FROM @fixes WHERE fix = @entry_fix;
    SELECT @exit_pos = MIN(pos) FROM @fixes WHERE fix = @exit_fix;

    IF @entry_pos IS NULL OR @exit_pos IS NULL
        RETURN;

    -- Return fix names in order (forward or reverse)
    IF @entry_pos < @exit_pos
    BEGIN
        INSERT INTO @result (seq, fix_name)
        SELECT ROW_NUMBER() OVER (ORDER BY pos), fix
        FROM @fixes
        WHERE pos >= @entry_pos AND pos <= @exit_pos;
    END
    ELSE
    BEGIN
        INSERT INTO @result (seq, fix_name)
        SELECT ROW_NUMBER() OVER (ORDER BY pos DESC), fix
        FROM @fixes
        WHERE pos >= @exit_pos AND pos <= @entry_pos;
    END

    RETURN;
END;
GO

-- ============================================================================
-- Helper Function: Parse Lat/Lon coordinate strings
-- ============================================================================
IF OBJECT_ID('dbo.fn_ParseCoordinate', 'TF') IS NOT NULL
    DROP FUNCTION dbo.fn_ParseCoordinate;
GO

CREATE FUNCTION dbo.fn_ParseCoordinate (
    @token NVARCHAR(50)
)
RETURNS @result TABLE (lat FLOAT, lon FLOAT, is_valid BIT)
AS
BEGIN
    DECLARE @clean NVARCHAR(50) = UPPER(REPLACE(@token, '/', ''));
    DECLARE @lat FLOAT = NULL;
    DECLARE @lon FLOAT = NULL;
    DECLARE @lat_str NVARCHAR(20);
    DECLARE @lon_str NVARCHAR(20);
    DECLARE @lat_dir CHAR(1);
    DECLARE @lon_dir CHAR(1);
    DECLARE @n_pos INT = CHARINDEX('N', @clean);
    DECLARE @s_pos INT = CHARINDEX('S', @clean);
    DECLARE @e_pos INT = CHARINDEX('E', @clean);
    DECLARE @w_pos INT = CHARINDEX('W', @clean);
    DECLARE @lat_pos INT;
    DECLARE @lon_pos INT;
    
    IF @n_pos > 0 
        BEGIN SET @lat_pos = @n_pos; SET @lat_dir = 'N'; END
    ELSE IF @s_pos > 0
        BEGIN SET @lat_pos = @s_pos; SET @lat_dir = 'S'; END
    ELSE
        BEGIN INSERT INTO @result VALUES (NULL, NULL, 0); RETURN; END
    
    IF @w_pos > 0
        BEGIN SET @lon_pos = @w_pos; SET @lon_dir = 'W'; END
    ELSE IF @e_pos > 0
        BEGIN SET @lon_pos = @e_pos; SET @lon_dir = 'E'; END
    ELSE
        BEGIN INSERT INTO @result VALUES (NULL, NULL, 0); RETURN; END
    
    IF @lat_pos > 1 AND ISNUMERIC(LEFT(@clean, @lat_pos - 1)) = 1
    BEGIN
        SET @lat_str = LEFT(@clean, @lat_pos - 1);
        IF @lon_pos > @lat_pos + 1
            SET @lon_str = SUBSTRING(@clean, @lat_pos + 1, @lon_pos - @lat_pos - 1);
        ELSE
            SET @lon_str = SUBSTRING(@clean, @lon_pos + 1, 100);
    END
    ELSE IF @lat_pos = 1
    BEGIN
        DECLARE @mid_pos INT = CASE WHEN @w_pos > 1 THEN @w_pos WHEN @e_pos > 1 THEN @e_pos ELSE 0 END;
        IF @mid_pos > 2
        BEGIN
            SET @lat_str = SUBSTRING(@clean, 2, @mid_pos - 2);
            SET @lon_str = SUBSTRING(@clean, @mid_pos + 1, 100);
        END
    END
    
    IF @lat_str IS NOT NULL AND LEN(@lat_str) > 0 AND ISNUMERIC(@lat_str) = 1
    BEGIN
        IF LEN(@lat_str) <= 2 SET @lat = CAST(@lat_str AS FLOAT);
        ELSE IF LEN(@lat_str) = 3 SET @lat = CAST(LEFT(@lat_str, 2) AS FLOAT) + CAST(RIGHT(@lat_str, 1) AS FLOAT) * 10 / 60.0;
        ELSE IF LEN(@lat_str) = 4 SET @lat = CAST(LEFT(@lat_str, 2) AS FLOAT) + CAST(RIGHT(@lat_str, 2) AS FLOAT) / 60.0;
        ELSE IF LEN(@lat_str) >= 5 SET @lat = CAST(LEFT(@lat_str, 2) AS FLOAT) + CAST(SUBSTRING(@lat_str, 3, 2) AS FLOAT) / 60.0 + CAST(SUBSTRING(@lat_str, 5, 100) AS FLOAT) / 3600.0;
        IF @lat_dir = 'S' SET @lat = -@lat;
    END
    
    IF @lon_str IS NOT NULL AND LEN(@lon_str) > 0 AND ISNUMERIC(@lon_str) = 1
    BEGIN
        IF LEN(@lon_str) <= 3 SET @lon = CAST(@lon_str AS FLOAT);
        ELSE IF LEN(@lon_str) = 4 SET @lon = CAST(LEFT(@lon_str, 3) AS FLOAT) + CAST(RIGHT(@lon_str, 1) AS FLOAT) * 10 / 60.0;
        ELSE IF LEN(@lon_str) = 5 SET @lon = CAST(LEFT(@lon_str, 3) AS FLOAT) + CAST(RIGHT(@lon_str, 2) AS FLOAT) / 60.0;
        ELSE IF LEN(@lon_str) >= 6 SET @lon = CAST(LEFT(@lon_str, 3) AS FLOAT) + CAST(SUBSTRING(@lon_str, 4, 2) AS FLOAT) / 60.0 + CAST(SUBSTRING(@lon_str, 6, 100) AS FLOAT) / 3600.0;
        IF @lon_dir = 'W' SET @lon = -@lon;
    END
    
    IF @lat IS NOT NULL AND @lon IS NOT NULL AND ABS(@lat) <= 90 AND ABS(@lon) <= 180
        INSERT INTO @result VALUES (@lat, @lon, 1);
    ELSE
        INSERT INTO @result VALUES (NULL, NULL, 0);
    
    RETURN;
END;
GO

-- ============================================================================
-- Helper Function: Identify token type
-- ============================================================================
IF OBJECT_ID('dbo.fn_GetTokenType', 'FN') IS NOT NULL
    DROP FUNCTION dbo.fn_GetTokenType;
GO

CREATE FUNCTION dbo.fn_GetTokenType (
    @token NVARCHAR(100)
)
RETURNS NVARCHAR(20)
AS
BEGIN
    DECLARE @upper NVARCHAR(100) = UPPER(LTRIM(RTRIM(@token)));
    
    IF @upper IS NULL OR @upper = '' OR @upper = '.' RETURN 'SKIP';
    IF @upper = 'DCT' OR @upper = 'DIRECT' RETURN 'SKIP';
    IF @upper = 'IFR' OR @upper = 'VFR' RETURN 'SKIP';
    
    -- Speed/Altitude specs (but NOT A-airways which can look similar)
    -- A### is altitude only if <= A450 (45000ft max realistic)
    IF @upper LIKE 'N0[0-9][0-9][0-9]%' OR @upper LIKE '/N0%' OR @upper LIKE 'M0[0-9][0-9]%' OR
       @upper LIKE 'K0[0-9][0-9][0-9]%' OR @upper LIKE 'F[0-9][0-9][0-9]' OR 
       @upper LIKE 'FL[0-9][0-9][0-9]' OR 
       @upper LIKE 'S[0-9][0-9][0-9][0-9]' OR @upper LIKE 'VFR/[0-9]%' OR @upper LIKE 'IFR/[0-9]%'
        RETURN 'SPEED_ALT';
    
    -- A### - only altitude if exactly 4 chars and number <= 450
    IF @upper LIKE 'A[0-9][0-9][0-9]' AND LEN(@upper) = 4
    BEGIN
        DECLARE @alt_num INT = CAST(SUBSTRING(@upper, 2, 3) AS INT);
        IF @alt_num <= 450
            RETURN 'SPEED_ALT';
        -- Otherwise it's an airway (A501, etc)
        RETURN 'AIRWAY';
    END

    -- Fix/Bearing/Distance (e.g., BDR228018 = BDR VOR, 228 bearing, 18nm)
    -- 2-5 alpha chars followed by exactly 6 digits. Must come before SID/STAR.
    IF LEN(@upper) BETWEEN 8 AND 11
    BEGIN
        DECLARE @fbd_alpha INT = 0;
        DECLARE @fbd_i INT = 1;
        WHILE @fbd_i <= LEN(@upper) AND SUBSTRING(@upper, @fbd_i, 1) LIKE '[A-Z]'
            SET @fbd_i = @fbd_i + 1;
        SET @fbd_alpha = @fbd_i - 1;
        IF @fbd_alpha BETWEEN 2 AND 5 AND LEN(@upper) - @fbd_alpha = 6
           AND SUBSTRING(@upper, @fbd_alpha + 1, 6) LIKE '[0-9][0-9][0-9][0-9][0-9][0-9]'
            RETURN 'FBD';
    END

    -- SID/STAR with dot
    IF @upper LIKE '%.%' AND LEN(@upper) >= 5
    BEGIN
        IF @upper LIKE '%[0-9].%' AND CHARINDEX('.', @upper) > 2 RETURN 'SID';
        IF @upper LIKE '%.%[0-9]' AND CHARINDEX('.', @upper) > 1 RETURN 'STAR';
    END
    
    -- SID/STAR without dot
    -- Pattern: 3+ letters followed by single digit, optionally followed by single letter
    -- Examples: KRSTA4, WYNDE3, ABTAN2W, DUMEP1T, RAGUL3A
    IF LEN(@upper) BETWEEN 4 AND 8
       AND (
           -- Ends with digit: KRSTA4, PARCH4
           @upper LIKE '[A-Z][A-Z][A-Z]%[0-9]'
           -- Ends with digit+letter: ABTAN2W, DUMEP1T, RAGUL3A
           OR @upper LIKE '[A-Z][A-Z][A-Z]%[0-9][A-Z]'
       )
       AND @upper NOT LIKE '%[0-9][0-9]%'  -- No consecutive digits
       AND @upper LIKE '[A-Z][A-Z][A-Z][A-Z0-9]%'  -- 4th char is alphanumeric
        RETURN 'SID_OR_STAR';
    
    -- Lat/Lon coordinates
    IF @upper LIKE '%[0-9]N%[0-9]W' OR @upper LIKE '%[0-9]S%[0-9]E' OR
       @upper LIKE '%[0-9]N%[0-9]E' OR @upper LIKE '%[0-9]S%[0-9]W' OR
       @upper LIKE 'N[0-9]%W[0-9]%' OR @upper LIKE 'S[0-9]%E[0-9]%' OR
       @upper LIKE 'N[0-9]%E[0-9]%' OR @upper LIKE 'S[0-9]%W[0-9]%'
        RETURN 'LATLON';
    
    -- Airways (comprehensive)
    -- A-airways: A1-A9 (2 chars), A10-A99 (3 chars), A100+ (4+ chars but handled above if 3-digit)
    IF @upper LIKE 'J[0-9]%' OR @upper LIKE 'V[0-9]%' OR @upper LIKE 'Q[0-9]%' OR @upper LIKE 'T[0-9]%' OR
       (@upper LIKE 'A[0-9]' AND LEN(@upper) = 2) OR  -- A1-A9
       (@upper LIKE 'A[0-9][0-9]' AND LEN(@upper) = 3) OR  -- A10-A99
       (@upper LIKE 'A[0-9][0-9][0-9][0-9]%') OR  -- A1000+ (rare but exists)
       @upper LIKE 'L[0-9]%' OR @upper LIKE 'M[0-9]%' OR @upper LIKE 'N[0-9]%' OR
       @upper LIKE 'B[0-9]%' OR @upper LIKE 'G[0-9]%' OR @upper LIKE 'R[0-9]%' OR @upper LIKE 'H[0-9]%' OR 
       @upper LIKE 'W[0-9]%' OR @upper LIKE 'Y[0-9]%' OR @upper LIKE 'Z[0-9]%' OR @upper LIKE 'P[0-9]%' OR
       @upper LIKE 'UL[0-9]%' OR @upper LIKE 'UM[0-9]%' OR @upper LIKE 'UN[0-9]%' OR @upper LIKE 'UP[0-9]%' OR 
       @upper LIKE 'UR[0-9]%' OR @upper LIKE 'UT[0-9]%' OR @upper LIKE 'UW[0-9]%' OR @upper LIKE 'UY[0-9]%' OR 
       @upper LIKE 'UZ[0-9]%' OR @upper LIKE 'UA[0-9]%' OR @upper LIKE 'UB[0-9]%' OR @upper LIKE 'UG[0-9]%' OR
       @upper LIKE 'UH[0-9]%'
        RETURN 'AIRWAY';
    
    -- Airport (4 letters with common ICAO prefix)
    IF LEN(@upper) = 4 AND @upper LIKE '[A-Z][A-Z][A-Z][A-Z]'
       AND LEFT(@upper, 1) IN ('K','C','E','L','O','U','Z','R','V','W','Y','P','M','T','S','F','D','H','G','B','N')
        RETURN 'AIRPORT';
    
    -- Fix/Waypoint
    IF LEN(@upper) BETWEEN 2 AND 5 AND @upper LIKE '[A-Z]%' AND @upper NOT LIKE '%.%'
        RETURN 'FIX';
    IF LEN(@upper) BETWEEN 6 AND 12 AND @upper LIKE '[A-Z][A-Z][A-Z][A-Z][A-Z]%' AND @upper NOT LIKE '%.%'
        RETURN 'FIX';
    IF LEN(@upper) = 3 AND @upper LIKE '[A-Z][A-Z][A-Z]'
        RETURN 'FIX';
    
    RETURN 'UNKNOWN';
END;
GO

-- ============================================================================
-- Helper Function: Resolve Fix/Bearing/Distance token
-- ============================================================================
-- Input: FBD token like 'BDR228018' (BDR VOR, 228 bearing, 18nm distance)
--        Optional prev/next coordinates for base fix disambiguation
-- Output: fix_name, lat, lon, base_fix, bearing, distance_nm
--
-- Uses route context (prev + next waypoints) to disambiguate duplicate base
-- fixes globally. NOT US-centric.
-- ============================================================================
IF OBJECT_ID('dbo.fn_ResolveFBD', 'TF') IS NOT NULL
    DROP FUNCTION dbo.fn_ResolveFBD;
GO

CREATE FUNCTION dbo.fn_ResolveFBD (
    @token NVARCHAR(100),
    @prev_lat FLOAT = NULL,
    @prev_lon FLOAT = NULL,
    @next_lat FLOAT = NULL,
    @next_lon FLOAT = NULL
)
RETURNS @result TABLE (
    fix_name NVARCHAR(50),
    lat FLOAT,
    lon FLOAT,
    base_fix NVARCHAR(10),
    bearing INT,
    distance_nm INT
)
AS
BEGIN
    DECLARE @upper NVARCHAR(100) = UPPER(LTRIM(RTRIM(@token)));
    DECLARE @alpha_len INT = 0;
    DECLARE @base_fix NVARCHAR(10);
    DECLARE @bearing_deg INT;
    DECLARE @distance_nm INT;
    DECLARE @base_lat FLOAT;
    DECLARE @base_lon FLOAT;
    DECLARE @mag_var FLOAT;
    DECLARE @true_bearing FLOAT;
    DECLARE @ref_lat FLOAT;
    DECLARE @ref_lon FLOAT;

    -- Count leading alpha characters
    DECLARE @ci INT = 1;
    WHILE @ci <= LEN(@upper) AND SUBSTRING(@upper, @ci, 1) LIKE '[A-Z]'
        SET @ci = @ci + 1;
    SET @alpha_len = @ci - 1;

    -- Validate: 2-5 alpha + exactly 6 digits remaining
    IF @alpha_len < 2 OR @alpha_len > 5 RETURN;
    IF LEN(@upper) - @alpha_len != 6 RETURN;
    IF SUBSTRING(@upper, @alpha_len + 1, 6) NOT LIKE '[0-9][0-9][0-9][0-9][0-9][0-9]' RETURN;

    SET @base_fix = LEFT(@upper, @alpha_len);
    SET @bearing_deg = CAST(SUBSTRING(@upper, @alpha_len + 1, 3) AS INT);
    SET @distance_nm = CAST(SUBSTRING(@upper, @alpha_len + 4, 3) AS INT);

    -- Validate ranges
    IF @bearing_deg > 360 OR @distance_nm < 1 OR @distance_nm > 999 RETURN;

    -- Determine reference point for proximity disambiguation
    -- Strategy: both prev+next -> midpoint; one side -> that side; neither -> first result
    IF @prev_lat IS NOT NULL AND @next_lat IS NOT NULL
    BEGIN
        SET @ref_lat = (@prev_lat + @next_lat) / 2.0;
        SET @ref_lon = (@prev_lon + @next_lon) / 2.0;
    END
    ELSE IF @prev_lat IS NOT NULL
    BEGIN
        SET @ref_lat = @prev_lat;
        SET @ref_lon = @prev_lon;
    END
    ELSE IF @next_lat IS NOT NULL
    BEGIN
        SET @ref_lat = @next_lat;
        SET @ref_lon = @next_lon;
    END

    -- Resolve base fix with proximity disambiguation (also fetch mag_var for bearing correction)
    IF @ref_lat IS NOT NULL
    BEGIN
        SELECT TOP 1 @base_lat = nf.lat, @base_lon = nf.lon, @mag_var = nf.mag_var
        FROM dbo.nav_fixes nf
        WHERE nf.fix_name = @base_fix
          AND nf.lat IS NOT NULL AND nf.lon IS NOT NULL
        ORDER BY geography::Point(@ref_lat, @ref_lon, 4326).STDistance(
            geography::Point(nf.lat, nf.lon, 4326)
        );
    END
    ELSE
    BEGIN
        SELECT TOP 1 @base_lat = nf.lat, @base_lon = nf.lon, @mag_var = nf.mag_var
        FROM dbo.nav_fixes nf
        WHERE nf.fix_name = @base_fix
          AND nf.lat IS NOT NULL AND nf.lon IS NOT NULL;
    END

    IF @base_lat IS NULL RETURN;

    -- If mag_var wasn't set from the resolved fix, look for it from a co-located VOR record
    IF @mag_var IS NULL OR @mag_var = 0
    BEGIN
        SELECT TOP 1 @mag_var = nf.mag_var
        FROM dbo.nav_fixes nf
        WHERE nf.fix_name = @base_fix
          AND nf.mag_var IS NOT NULL AND nf.mag_var <> 0
          AND ABS(nf.lat - @base_lat) < 0.1 AND ABS(nf.lon - @base_lon) < 0.1;
    END

    -- Convert magnetic bearing to true bearing
    -- mag_var: positive = East, negative = West; True = Magnetic + mag_var
    SET @true_bearing = CAST(@bearing_deg AS FLOAT) + ISNULL(@mag_var, 0);
    -- Normalize to 0-360
    IF @true_bearing < 0 SET @true_bearing = @true_bearing + 360;
    IF @true_bearing >= 360 SET @true_bearing = @true_bearing - 360;

    -- Forward geodesic projection using spherical Earth formula
    -- phi2 = asin(sin(phi1)*cos(d/R) + cos(phi1)*sin(d/R)*cos(theta))
    -- lambda2 = lambda1 + atan2(sin(theta)*sin(d/R)*cos(phi1), cos(d/R) - sin(phi1)*sin(phi2))
    DECLARE @R FLOAT = 6371000.0;  -- Earth radius in meters
    DECLARE @d FLOAT = @distance_nm * 1852.0;  -- distance in meters
    DECLARE @phi1 FLOAT = RADIANS(@base_lat);
    DECLARE @lam1 FLOAT = RADIANS(@base_lon);
    DECLARE @theta FLOAT = RADIANS(@true_bearing);
    DECLARE @phi2 FLOAT;
    DECLARE @lam2 FLOAT;

    SET @phi2 = ASIN(SIN(@phi1) * COS(@d / @R) + COS(@phi1) * SIN(@d / @R) * COS(@theta));
    SET @lam2 = @lam1 + ATN2(SIN(@theta) * SIN(@d / @R) * COS(@phi1), COS(@d / @R) - SIN(@phi1) * SIN(@phi2));

    INSERT INTO @result (fix_name, lat, lon, base_fix, bearing, distance_nm)
    VALUES (@upper, DEGREES(@phi2), DEGREES(@lam2), @base_fix, @bearing_deg, @distance_nm);

    RETURN;
END;
GO

-- ============================================================================
-- Main Procedure: sp_ParseRoute
-- ============================================================================
IF OBJECT_ID('dbo.sp_ParseRoute', 'P') IS NOT NULL
    DROP PROCEDURE dbo.sp_ParseRoute;
GO

CREATE PROCEDURE dbo.sp_ParseRoute
    @flight_uid BIGINT,
    @debug BIT = 0
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @route NVARCHAR(MAX);
    DECLARE @dept_icao NVARCHAR(10);
    DECLARE @dest_icao NVARCHAR(10);
    DECLARE @parse_start DATETIME2 = SYSUTCDATETIME();

    -- Get flight plan data
    SELECT @route = fp_route, @dept_icao = fp_dept_icao, @dest_icao = fp_dest_icao
    FROM dbo.adl_flight_plan
    WHERE flight_uid = @flight_uid;
    
    IF @route IS NULL OR LEN(LTRIM(RTRIM(@route))) = 0
    BEGIN
        IF @debug = 1 PRINT 'No route found for flight_uid: ' + CAST(@flight_uid AS VARCHAR);
        UPDATE dbo.adl_flight_plan SET parse_status = 'NO_ROUTE', parse_utc = SYSUTCDATETIME() WHERE flight_uid = @flight_uid;
        RETURN;
    END
    
    IF @debug = 1 
    BEGIN
        PRINT '================================================';
        PRINT 'Parsing route for flight_uid: ' + CAST(@flight_uid AS VARCHAR);
        PRINT 'Route: ' + @route;
        PRINT 'Dept: ' + ISNULL(@dept_icao, 'NULL') + ' Dest: ' + ISNULL(@dest_icao, 'NULL');
        PRINT '================================================';
    END

    -- ========================================================================
    -- PHASE 1: Tokenize route string
    -- ========================================================================
    DECLARE @tokens TABLE (seq INT IDENTITY(1,1), token NVARCHAR(100), token_type NVARCHAR(20));
    
    DECLARE @clean_route NVARCHAR(MAX) = UPPER(LTRIM(RTRIM(@route)));
    SET @clean_route = REPLACE(REPLACE(REPLACE(REPLACE(@clean_route, '+', ' '), CHAR(9), ' '), CHAR(10), ' '), CHAR(13), ' ');

    -- Variables for runway extraction
    DECLARE @dep_runway NVARCHAR(4) = NULL;
    DECLARE @arr_runway NVARCHAR(4) = NULL;
    DECLARE @runway_match NVARCHAR(10);
    DECLARE @rwy_pos INT;

    -- Extract runway suffixes from route BEFORE stripping them
    -- Look for patterns like /07C, /02L, /RW26L at token boundaries
    -- First occurrence = departure runway, last occurrence = arrival runway

    -- Pattern 1: /##L, /##R, /##C, /## (e.g., /07C, /02L, /26)
    SET @rwy_pos = PATINDEX('%/[0-9][0-9][LRC ]%', @clean_route + ' ');
    IF @rwy_pos > 0
    BEGIN
        SET @runway_match = SUBSTRING(@clean_route, @rwy_pos + 1, 3);
        SET @runway_match = RTRIM(REPLACE(@runway_match, ' ', ''));
        SET @dep_runway = @runway_match;
    END

    -- Find last runway suffix for arrival (search from end)
    DECLARE @last_rwy_pos INT = 0;
    DECLARE @search_pos INT = 1;
    WHILE 1 = 1
    BEGIN
        SET @rwy_pos = PATINDEX('%/[0-9][0-9][LRC ]%', SUBSTRING(@clean_route + ' ', @search_pos, LEN(@clean_route) - @search_pos + 2));
        IF @rwy_pos = 0 BREAK;
        SET @last_rwy_pos = @search_pos + @rwy_pos - 1;
        SET @search_pos = @search_pos + @rwy_pos;
    END
    IF @last_rwy_pos > 0 AND @last_rwy_pos != PATINDEX('%/[0-9][0-9][LRC ]%', @clean_route + ' ')
    BEGIN
        SET @runway_match = SUBSTRING(@clean_route, @last_rwy_pos + 1, 3);
        SET @runway_match = RTRIM(REPLACE(@runway_match, ' ', ''));
        SET @arr_runway = @runway_match;
    END

    -- Also check for /RW## pattern (e.g., /RW26L)
    IF PATINDEX('%/RW[0-9][0-9]%', @clean_route) > 0
    BEGIN
        SET @rwy_pos = PATINDEX('%/RW[0-9][0-9]%', @clean_route);
        SET @runway_match = SUBSTRING(@clean_route, @rwy_pos + 3, 3);  -- Skip /RW
        SET @runway_match = REPLACE(REPLACE(@runway_match, ' ', ''), '/', '');
        IF LEN(@runway_match) >= 2
            SET @arr_runway = LEFT(@runway_match, CASE WHEN LEN(@runway_match) > 3 THEN 3 ELSE LEN(@runway_match) END);
    END

    -- Strip runway suffixes from route (e.g., TEBUN1A/02L -> TEBUN1A)
    -- Pattern: /##L, /##R, /##C, /##
    WHILE PATINDEX('%/[0-9][0-9][LRC]%', @clean_route) > 0
    BEGIN
        SET @rwy_pos = PATINDEX('%/[0-9][0-9][LRC]%', @clean_route);
        SET @clean_route = LEFT(@clean_route, @rwy_pos - 1) + SUBSTRING(@clean_route, @rwy_pos + 4, LEN(@clean_route));
    END
    WHILE PATINDEX('%/[0-9][0-9] %', @clean_route + ' ') > 0
    BEGIN
        SET @rwy_pos = PATINDEX('%/[0-9][0-9] %', @clean_route + ' ');
        SET @clean_route = LEFT(@clean_route, @rwy_pos - 1) + SUBSTRING(@clean_route, @rwy_pos + 3, LEN(@clean_route));
    END
    -- Pattern: /RW##L, /RW##R, /RW##C, /RW##
    WHILE PATINDEX('%/RW[0-9][0-9]%', @clean_route) > 0
    BEGIN
        SET @rwy_pos = PATINDEX('%/RW[0-9][0-9]%', @clean_route);
        DECLARE @rwy_end INT = @rwy_pos + 5;  -- /RW## = 5 chars minimum
        IF @rwy_end <= LEN(@clean_route) AND SUBSTRING(@clean_route, @rwy_end, 1) LIKE '[LRC]'
            SET @rwy_end = @rwy_end + 1;
        SET @clean_route = LEFT(@clean_route, @rwy_pos - 1) + SUBSTRING(@clean_route, @rwy_end, LEN(@clean_route));
    END

    -- Strip speed/altitude suffixes from fix names (e.g., ERGOM/N0481F330 -> ERGOM)
    -- Pattern: FIX/N0xxxFxxx or FIX/MxxxFxxx
    WHILE PATINDEX('%[A-Z]/[NMK]0[0-9][0-9][0-9]%', @clean_route) > 0
    BEGIN
        DECLARE @slash_pos INT = PATINDEX('%[A-Z]/[NMK]0[0-9][0-9][0-9]%', @clean_route) + 1;
        DECLARE @suffix_end INT = @slash_pos;
        -- Find end of suffix (next space or end of string)
        WHILE @suffix_end <= LEN(@clean_route) AND SUBSTRING(@clean_route, @suffix_end, 1) != ' '
            SET @suffix_end = @suffix_end + 1;
        -- Remove the suffix
        SET @clean_route = LEFT(@clean_route, @slash_pos - 1) + SUBSTRING(@clean_route, @suffix_end, LEN(@clean_route));
    END
    
    -- Handle dots (preserve SID/STAR format)
    DECLARE @i INT = 1;
    WHILE @i <= LEN(@clean_route)
    BEGIN
        IF SUBSTRING(@clean_route, @i, 1) = '.'
        BEGIN
            DECLARE @pc NVARCHAR(1) = CASE WHEN @i > 1 THEN SUBSTRING(@clean_route, @i - 1, 1) ELSE ' ' END;
            DECLARE @nc NVARCHAR(1) = CASE WHEN @i < LEN(@clean_route) THEN SUBSTRING(@clean_route, @i + 1, 1) ELSE ' ' END;
            IF NOT (@pc LIKE '[A-Z0-9]' AND @nc LIKE '[A-Z0-9]')
                SET @clean_route = LEFT(@clean_route, @i - 1) + ' ' + SUBSTRING(@clean_route, @i + 1, LEN(@clean_route));
        END
        SET @i = @i + 1;
    END
    
    WHILE CHARINDEX('  ', @clean_route) > 0 SET @clean_route = REPLACE(@clean_route, '  ', ' ');
    
    INSERT INTO @tokens (token, token_type)
    SELECT value, dbo.fn_GetTokenType(value) FROM STRING_SPLIT(@clean_route, ' ') WHERE LEN(LTRIM(RTRIM(value))) > 0;

    IF @debug = 1
    BEGIN
        PRINT 'Tokens:';
        SELECT seq, token, token_type FROM @tokens ORDER BY seq;
    END

    -- ========================================================================
    -- PHASE 2: Build waypoint list with fix names (coordinates resolved later)
    -- Expand airways inline to get complete fix name sequence
    -- ========================================================================
    DECLARE @waypoints TABLE (
        seq INT IDENTITY(1,1),
        fix_name NVARCHAR(50),
        lat FLOAT NULL,          -- Resolved in Phase 4
        lon FLOAT NULL,          -- Resolved in Phase 4
        fix_type NVARCHAR(20),
        source NVARCHAR(20),
        on_airway NVARCHAR(50),
        on_dp NVARCHAR(20),
        on_star NVARCHAR(20),
        original_token NVARCHAR(100)
    );
    
    -- Metadata
    DECLARE @dp_name NVARCHAR(16), @dfix NVARCHAR(8), @dtrsn NVARCHAR(16);
    DECLARE @star_name NVARCHAR(16), @afix NVARCHAR(8), @strsn NVARCHAR(16);
    DECLARE @pending_dfix BIT = 0;  -- Flag to capture first fix after SID
    
    -- Add departure airport
    IF @dept_icao IS NOT NULL
    BEGIN
        INSERT INTO @waypoints (fix_name, fix_type, source, original_token)
        VALUES (@dept_icao, 'AIRPORT', 'ORIGIN', @dept_icao);
    END
    
    -- Process tokens
    DECLARE @t_seq INT, @t_token NVARCHAR(100), @t_type NVARCHAR(20);
    DECLARE @prev_fix_name NVARCHAR(50) = @dept_icao;
    DECLARE @pending_airway NVARCHAR(10) = NULL;
    DECLARE @last_fix_for_star NVARCHAR(50) = NULL;
    
    DECLARE token_cursor CURSOR LOCAL FAST_FORWARD FOR
        SELECT seq, token, token_type FROM @tokens WHERE token_type NOT IN ('SKIP', 'SPEED_ALT', 'UNKNOWN') ORDER BY seq;
    
    OPEN token_cursor;
    FETCH NEXT FROM token_cursor INTO @t_seq, @t_token, @t_type;
    
    WHILE @@FETCH_STATUS = 0
    BEGIN
        IF @t_type = 'AIRWAY'
        BEGIN
            SET @pending_airway = @t_token;
            -- If SID followed directly by airway, clear pending dfix (no explicit departure fix)
            SET @pending_dfix = 0;
        END
        ELSE IF @t_type IN ('FIX', 'AIRPORT')
        BEGIN
            -- Capture departure fix (first fix after SID)
            IF @pending_dfix = 1 AND @t_type = 'FIX'
            BEGIN
                SET @dfix = @t_token;
                SET @pending_dfix = 0;
            END

            -- Track if this fix is an airway endpoint
            DECLARE @fix_on_airway NVARCHAR(50) = NULL;
            DECLARE @fix_already_exists BIT = 0;

            -- If pending airway, expand it first
            IF @pending_airway IS NOT NULL AND @prev_fix_name IS NOT NULL
            BEGIN
                -- Mark the START point of this airway (prev_fix already exists, add this airway to it)
                UPDATE @waypoints
                SET on_airway = CASE
                    WHEN on_airway IS NULL THEN @pending_airway
                    WHEN on_airway NOT LIKE '%' + @pending_airway + '%' THEN on_airway + ',' + @pending_airway
                    ELSE on_airway END
                WHERE fix_name = @prev_fix_name
                  AND seq = (SELECT MAX(seq) FROM @waypoints WHERE fix_name = @prev_fix_name);

                INSERT INTO @waypoints (fix_name, fix_type, source, on_airway, original_token)
                SELECT fix_name, 'WAYPOINT', 'AIRWAY', @pending_airway, @pending_airway
                FROM dbo.fn_ExpandAirwayNames(@pending_airway, @prev_fix_name, @t_token)
                WHERE seq > 1 AND fix_name != @t_token;  -- Skip entry (already added) and exit (add below)

                SET @fix_on_airway = @pending_airway;  -- Mark this fix as airway endpoint

                -- Check if ENDPOINT already exists as previous airway endpoint (airway-to-airway transition)
                IF EXISTS (SELECT 1 FROM @waypoints WHERE fix_name = @t_token AND on_airway IS NOT NULL)
                BEGIN
                    -- Append this airway to existing on_airway (e.g., "Q430,J48")
                    UPDATE @waypoints
                    SET on_airway = on_airway + ',' + @pending_airway
                    WHERE fix_name = @t_token
                      AND seq = (SELECT MAX(seq) FROM @waypoints WHERE fix_name = @t_token);
                    SET @fix_already_exists = 1;
                END

                SET @pending_airway = NULL;
            END

            -- Add this fix (with on_airway if it's an airway endpoint) - skip if already updated
            IF @fix_already_exists = 0 AND (@t_token NOT IN (ISNULL(@dept_icao,''), ISNULL(@dest_icao,'')) OR @t_type = 'FIX')
            BEGIN
                INSERT INTO @waypoints (fix_name, fix_type, source, on_airway, original_token)
                VALUES (@t_token, CASE @t_type WHEN 'AIRPORT' THEN 'AIRPORT' ELSE 'WAYPOINT' END, 'ROUTE', @fix_on_airway, @t_token);
            END

            SET @prev_fix_name = @t_token;
            SET @last_fix_for_star = @t_token;
            SET @pending_airway = NULL;
        END
        ELSE IF @t_type = 'LATLON'
        BEGIN
            DECLARE @coord_lat FLOAT, @coord_lon FLOAT, @coord_valid BIT;
            SELECT @coord_lat = lat, @coord_lon = lon, @coord_valid = is_valid FROM dbo.fn_ParseCoordinate(@t_token);

            IF @coord_valid = 1
            BEGIN
                INSERT INTO @waypoints (fix_name, lat, lon, fix_type, source, original_token)
                VALUES (@t_token, @coord_lat, @coord_lon, 'COORD', 'COORD', @t_token);
                SET @prev_fix_name = @t_token;
            END
            SET @pending_airway = NULL;
        END
        ELSE IF @t_type = 'FBD'
        BEGIN
            -- Fix/Bearing/Distance token (e.g., BDR228018)
            -- Resolve with route context: previous fix + look-ahead to next fix
            DECLARE @fbd_lat FLOAT, @fbd_lon FLOAT;
            DECLARE @fbd_next_lat FLOAT, @fbd_next_lon FLOAT;
            DECLARE @fbd_prev_lat FLOAT, @fbd_prev_lon FLOAT;
            DECLARE @fbd_next_token NVARCHAR(100), @fbd_next_type NVARCHAR(20);

            -- Reset for each FBD token (DECLARE only initializes on first iteration)
            SET @fbd_lat = NULL;
            SET @fbd_lon = NULL;
            SET @fbd_next_lat = NULL;
            SET @fbd_next_lon = NULL;
            SET @fbd_prev_lat = NULL;
            SET @fbd_prev_lon = NULL;
            SET @fbd_next_token = NULL;
            SET @fbd_next_type = NULL;

            -- Get previous fix coordinates from already-resolved origin or nav_fixes
            IF @prev_fix_name IS NOT NULL
            BEGIN
                -- Check if already resolved in waypoints (origin has lat/lon)
                SELECT TOP 1 @fbd_prev_lat = lat, @fbd_prev_lon = lon
                FROM @waypoints WHERE fix_name = @prev_fix_name AND lat IS NOT NULL
                ORDER BY seq DESC;

                -- If not yet resolved, try nav_fixes directly
                IF @fbd_prev_lat IS NULL
                BEGIN
                    SELECT TOP 1 @fbd_prev_lat = lat, @fbd_prev_lon = lon
                    FROM dbo.nav_fixes
                    WHERE fix_name = @prev_fix_name AND lat IS NOT NULL;
                END
            END

            -- Look ahead to next non-skip token for disambiguation context
            SELECT TOP 1 @fbd_next_token = token, @fbd_next_type = token_type
            FROM @tokens WHERE seq > @t_seq AND token_type NOT IN ('SKIP', 'SPEED_ALT') ORDER BY seq;

            IF @fbd_next_token IS NOT NULL AND @fbd_next_type IN ('FIX', 'AIRPORT')
            BEGIN
                SELECT TOP 1 @fbd_next_lat = lat, @fbd_next_lon = lon
                FROM dbo.nav_fixes
                WHERE fix_name = @fbd_next_token AND lat IS NOT NULL;
            END

            -- Resolve FBD with full route context
            SELECT TOP 1 @fbd_lat = lat, @fbd_lon = lon
            FROM dbo.fn_ResolveFBD(@t_token, @fbd_prev_lat, @fbd_prev_lon, @fbd_next_lat, @fbd_next_lon);

            IF @fbd_lat IS NOT NULL
            BEGIN
                INSERT INTO @waypoints (fix_name, lat, lon, fix_type, source, original_token)
                VALUES (@t_token, @fbd_lat, @fbd_lon, 'FBD', 'FBD', @t_token);
                SET @prev_fix_name = @t_token;
                SET @last_fix_for_star = @t_token;

                IF @debug = 1
                    PRINT 'FBD resolved: ' + @t_token + ' at ' + CAST(@fbd_lat AS VARCHAR) + ', ' + CAST(@fbd_lon AS VARCHAR);
            END
            ELSE IF @debug = 1
                PRINT 'FBD unresolved: ' + @t_token;

            SET @pending_airway = NULL;
        END
        ELSE IF @t_type IN ('SID', 'STAR', 'SID_OR_STAR')
        BEGIN
            -- Look up procedure with transition matching
            DECLARE @proc_route NVARCHAR(MAX), @proc_type NVARCHAR(10), @proc_code NVARCHAR(50);
            DECLARE @star_proc_route NVARCHAR(MAX), @transition_route NVARCHAR(MAX);
            DECLARE @next_token NVARCHAR(100), @next_type NVARCHAR(20);
            SET @proc_route = NULL;
            SET @star_proc_route = NULL;
            SET @transition_route = NULL;

            -- Peek at next token for transition matching
            SELECT TOP 1 @next_token = token, @next_type = token_type
            FROM @tokens WHERE seq > @t_seq ORDER BY seq;

            -- Strategy 1: Try transition-specific lookup for DP (next token is transition endpoint)
            IF @next_type = 'FIX' AND @next_token IS NOT NULL
            BEGIN
                -- Try DP with transition: FOLZZ3.FOLZZ where transition ends with ALYRA
                SELECT TOP 1 @proc_route = full_route, @proc_type = procedure_type, @proc_code = computer_code, @dtrsn = transition_name
                FROM dbo.nav_procedures
                WHERE computer_code LIKE @t_token + '.%'
                  AND procedure_type = 'DP'
                  AND (full_route LIKE '% ' + @next_token OR full_route = @next_token
                       OR transition_name LIKE @next_token + ' %')
                ORDER BY LEN(full_route);
            END

            -- Strategy 2: Try STAR with entry fix (previous token)
            -- Handles both TRANSITION.STAR (e.g., JJEDI.JJEDI4) and STAR.TRANSITION (e.g., LENAR7.RW07)
            IF @proc_route IS NULL AND @last_fix_for_star IS NOT NULL AND LEN(@last_fix_for_star) BETWEEN 3 AND 5
            BEGIN
                SELECT TOP 1 @proc_route = full_route, @proc_type = procedure_type, @proc_code = computer_code, @strsn = transition_name
                FROM dbo.nav_procedures
                WHERE (computer_code LIKE '%.' + @t_token
                       OR computer_code LIKE @t_token + '.%'
                       OR computer_code = @t_token)
                  AND procedure_type = 'STAR'
                  AND full_route LIKE @last_fix_for_star + ' %'
                ORDER BY LEN(full_route);
            END

            -- Strategy 3: Exact match on computer_code
            IF @proc_route IS NULL
            BEGIN
                SELECT TOP 1 @proc_route = full_route, @proc_type = procedure_type, @proc_code = computer_code
                FROM dbo.nav_procedures WHERE computer_code = @t_token;
            END

            -- Strategy 4: Wildcard match for DP
            IF @proc_route IS NULL
            BEGIN
                SELECT TOP 1 @proc_route = full_route, @proc_type = procedure_type, @proc_code = computer_code
                FROM dbo.nav_procedures WHERE computer_code LIKE @t_token + '.%' AND procedure_type = 'DP'
                ORDER BY LEN(full_route);
            END

            -- Strategy 5: Wildcard match for STAR (prefer TRANSITION.STAR over STAR.RUNWAY)
            -- TRANSITION.STAR format (e.g., JJEDI.JJEDI4) gives cleaner routes
            IF @proc_route IS NULL
            BEGIN
                SELECT TOP 1 @proc_route = full_route, @proc_type = procedure_type, @proc_code = computer_code
                FROM dbo.nav_procedures WHERE computer_code LIKE '%.' + @t_token AND procedure_type = 'STAR'
                ORDER BY LEN(full_route);
            END
            -- Fallback: STAR.RUNWAY format (e.g., JJEDI4.RW26B) - often bloated
            IF @proc_route IS NULL
            BEGIN
                SELECT TOP 1 @proc_route = full_route, @proc_type = procedure_type, @proc_code = computer_code
                FROM dbo.nav_procedures WHERE computer_code LIKE @t_token + '.%' AND procedure_type = 'STAR'
                ORDER BY LEN(full_route);
            END

            IF @proc_route IS NOT NULL
            BEGIN
                IF @proc_type = 'DP'
                BEGIN
                    SET @dp_name = @t_token;
                    SET @pending_dfix = 1;  -- Flag to capture next fix as departure fix

                    -- Expand SID waypoints (cap at 30)
                    INSERT INTO @waypoints (fix_name, fix_type, source, on_dp, original_token)
                    SELECT TOP 30 value, 'WAYPOINT', 'SID', @t_token, @t_token
                    FROM STRING_SPLIT(@proc_route, ' ')
                    WHERE LEN(LTRIM(RTRIM(value))) > 0 AND value NOT LIKE '%/%';

                    SELECT TOP 1 @prev_fix_name = fix_name FROM @waypoints WHERE on_dp = @t_token ORDER BY seq DESC;

                    IF @debug = 1
                    BEGIN
                        DECLARE @dp_wpt_count INT;
                        SELECT @dp_wpt_count = COUNT(*) FROM @waypoints WHERE on_dp = @t_token;
                        PRINT 'SID expanded: ' + @t_token + ' -> ' + ISNULL(@proc_code, '?') +
                              ' (' + CAST(@dp_wpt_count AS VARCHAR) + ' wpts)';
                    END
                END
                ELSE  -- STAR processing
                BEGIN
                    SET @star_name = @t_token;
                    SET @afix = @last_fix_for_star;

                    -- Mark the entry fix (already in waypoints) as on_star
                    -- This handles dual-membership: fix can be on_airway AND on_star
                    IF @afix IS NOT NULL
                    BEGIN
                        UPDATE @waypoints
                        SET on_star = @t_token
                        WHERE fix_name = @afix
                          AND on_star IS NULL
                          AND seq = (SELECT MAX(seq) FROM @waypoints WHERE fix_name = @afix);
                    END

                    -- Expand STAR waypoints (cap at 30), skip entry fix (already marked above)
                    INSERT INTO @waypoints (fix_name, fix_type, source, on_star, original_token)
                    SELECT TOP 30 value, 'WAYPOINT', 'STAR', @t_token, @t_token
                    FROM STRING_SPLIT(@proc_route, ' ')
                    WHERE LEN(LTRIM(RTRIM(value))) > 0
                      AND value NOT LIKE '%/%'
                      AND value != ISNULL(@afix, '');  -- Skip entry fix (already updated above)

                    SELECT TOP 1 @prev_fix_name = fix_name
                    FROM @waypoints WHERE on_star = @t_token ORDER BY seq DESC;

                    IF @debug = 1
                    BEGIN
                        DECLARE @star_wpt_count INT;
                        SELECT @star_wpt_count = COUNT(*) FROM @waypoints WHERE on_star = @t_token;
                        PRINT 'STAR expanded: ' + @t_token + ' via ' + ISNULL(@afix, 'DIRECT') +
                              ' (' + CAST(@star_wpt_count AS VARCHAR) + ' wpts)';
                    END
                END
            END
            ELSE
            BEGIN
                -- Not a procedure, treat as fix
                INSERT INTO @waypoints (fix_name, fix_type, source, original_token)
                VALUES (@t_token, 'WAYPOINT', 'ROUTE', @t_token);
                SET @prev_fix_name = @t_token;
                SET @last_fix_for_star = @t_token;
                IF @debug = 1
                    PRINT 'Procedure not found, treating as fix: ' + @t_token;
            END
            SET @pending_airway = NULL;
        END
        
        FETCH NEXT FROM token_cursor INTO @t_seq, @t_token, @t_type;
    END
    
    CLOSE token_cursor;
    DEALLOCATE token_cursor;
    
    -- Add destination
    IF @dest_icao IS NOT NULL
    BEGIN
        INSERT INTO @waypoints (fix_name, fix_type, source, original_token)
        VALUES (@dest_icao, 'AIRPORT', 'DESTINATION', @dest_icao);
    END

    -- ========================================================================
    -- Fallback: Extract dfix/afix for routes WITHOUT SID/STAR
    -- This ensures the demand chart filters work even for direct routes
    -- ========================================================================

    -- If no dfix was captured (no SID found), use first waypoint after departure
    IF @dfix IS NULL
    BEGIN
        SELECT TOP 1 @dfix = fix_name
        FROM @waypoints
        WHERE source NOT IN ('ORIGIN', 'DESTINATION', 'SID')
          AND fix_type IN ('WAYPOINT', 'FIX')
          AND LEN(fix_name) BETWEEN 2 AND 5  -- Standard fix names
        ORDER BY seq;

        IF @debug = 1 AND @dfix IS NOT NULL
            PRINT 'Fallback dfix (first route fix): ' + @dfix;
    END

    -- If no afix was captured (no STAR found), use last waypoint before destination
    IF @afix IS NULL
    BEGIN
        SELECT TOP 1 @afix = fix_name
        FROM @waypoints
        WHERE source NOT IN ('ORIGIN', 'DESTINATION', 'STAR')
          AND fix_type IN ('WAYPOINT', 'FIX')
          AND LEN(fix_name) BETWEEN 2 AND 5  -- Standard fix names
        ORDER BY seq DESC;

        IF @debug = 1 AND @afix IS NOT NULL
            PRINT 'Fallback afix (last route fix): ' + @afix;
    END

    -- Remove consecutive duplicates
    ;WITH numbered AS (
        SELECT seq, fix_name, LAG(fix_name) OVER (ORDER BY seq) AS prev_fix
        FROM @waypoints
    )
    DELETE FROM @waypoints WHERE seq IN (SELECT seq FROM numbered WHERE fix_name = prev_fix AND fix_name IS NOT NULL);

    IF @debug = 1
    BEGIN
        PRINT '';
        PRINT 'Waypoints after Phase 2 (names only):';
        SELECT seq, fix_name, source, on_airway FROM @waypoints ORDER BY seq;
    END

    -- ========================================================================
    -- PHASE 3: Build candidate lookup with airway membership
    -- ONE query to get all possible coordinates for all fix names
    -- ========================================================================
    
    -- Get distinct fix names we need to resolve
    DECLARE @fix_names TABLE (fix_name NVARCHAR(50) PRIMARY KEY);
    INSERT INTO @fix_names SELECT DISTINCT fix_name FROM @waypoints WHERE lat IS NULL;
    
    -- Build airway membership lookup (which fixes are on which airways)
    -- This helps disambiguate: if we're on UL607, prefer FERDI that's actually on UL607
    IF OBJECT_ID('tempdb..#airway_fixes') IS NOT NULL DROP TABLE #airway_fixes;
    
    SELECT DISTINCT 
        a.airway_name,
        CAST(LTRIM(RTRIM(f.value)) AS NVARCHAR(50)) AS fix_name
    INTO #airway_fixes
    FROM dbo.airways a
    CROSS APPLY STRING_SPLIT(a.fix_sequence, ' ') f
    WHERE EXISTS (SELECT 1 FROM @waypoints w WHERE w.on_airway = a.airway_name)
      AND LEN(LTRIM(RTRIM(f.value))) > 0;
    
    -- Rebuild with proper column type for indexing
    ALTER TABLE #airway_fixes ALTER COLUMN fix_name NVARCHAR(50) NOT NULL;
    ALTER TABLE #airway_fixes ALTER COLUMN airway_name NVARCHAR(10) NOT NULL;
    CREATE INDEX IX_af ON #airway_fixes(fix_name, airway_name);
    
    IF @debug = 1
    BEGIN
        DECLARE @af_count INT;
        SELECT @af_count = COUNT(*) FROM #airway_fixes;
        PRINT 'Airway fixes cached: ' + CAST(@af_count AS VARCHAR);
    END
    
    -- Get ALL candidate coordinates for ALL fix names (ONE database query)
    IF OBJECT_ID('tempdb..#candidates') IS NOT NULL DROP TABLE #candidates;
    
    SELECT 
        nf.fix_name,
        nf.lat,
        nf.lon,
        nf.fix_type,
        nf.source AS nav_source,
        -- List airways this candidate is on (for disambiguation)
        STUFF((
            SELECT ',' + af.airway_name 
            FROM #airway_fixes af 
            WHERE af.fix_name = nf.fix_name 
            FOR XML PATH('')
        ), 1, 1, '') AS on_airways
    INTO #candidates
    FROM dbo.nav_fixes nf
    WHERE nf.fix_name IN (SELECT fix_name FROM @fix_names)
      AND nf.lat IS NOT NULL AND nf.lon IS NOT NULL;
    
    CREATE INDEX IX_cand ON #candidates(fix_name);
    
    IF @debug = 1
    BEGIN
        DECLARE @cand_count INT;
        SELECT @cand_count = COUNT(*) FROM #candidates;
        PRINT 'Candidates fetched: ' + CAST(@cand_count AS VARCHAR);
    END

    -- ========================================================================
    -- PHASE 4: Sequential coordinate resolution using proximity + airway context
    -- Iterate through waypoints, resolve each using previous fix's coordinates
    -- ========================================================================
    
    DECLARE @wp_seq INT, @wp_name NVARCHAR(50), @wp_lat FLOAT, @wp_lon FLOAT;
    DECLARE @wp_on_airway NVARCHAR(50);
    DECLARE @prev_lat FLOAT = NULL, @prev_lon FLOAT = NULL;
    DECLARE @resolved_lat FLOAT, @resolved_lon FLOAT, @resolved_type NVARCHAR(20);
    
    -- Get origin coordinates first (prioritize AIRPORT type)
    SELECT TOP 1 @prev_lat = lat, @prev_lon = lon
    FROM dbo.nav_fixes
    WHERE fix_name = @dept_icao OR fix_name = 'K' + @dept_icao
    ORDER BY 
        CASE WHEN fix_type = 'AIRPORT' THEN 0 ELSE 1 END,
        CASE WHEN fix_name = @dept_icao THEN 0 ELSE 1 END;
    
    -- Update origin in waypoints
    UPDATE @waypoints SET lat = @prev_lat, lon = @prev_lon WHERE source = 'ORIGIN';
    
    IF @debug = 1 AND @prev_lat IS NOT NULL
        PRINT 'Origin resolved: ' + @dept_icao + ' at ' + CAST(@prev_lat AS VARCHAR) + ', ' + CAST(@prev_lon AS VARCHAR);
    
    -- Resolve each waypoint sequentially
    DECLARE resolve_cursor CURSOR LOCAL FAST_FORWARD FOR
        SELECT seq, fix_name, lat, lon, on_airway FROM @waypoints WHERE source != 'ORIGIN' ORDER BY seq;
    
    OPEN resolve_cursor;
    FETCH NEXT FROM resolve_cursor INTO @wp_seq, @wp_name, @wp_lat, @wp_lon, @wp_on_airway;
    
    WHILE @@FETCH_STATUS = 0
    BEGIN
        -- Skip if already has coordinates (LATLON type)
        IF @wp_lat IS NOT NULL AND @wp_lon IS NOT NULL
        BEGIN
            SET @prev_lat = @wp_lat;
            SET @prev_lon = @wp_lon;
        END
        ELSE
        BEGIN
            SET @resolved_lat = NULL;
            SET @resolved_lon = NULL;
            
            -- Resolution strategy:
            -- 1. If on an airway, prefer candidates that are actually on that airway
            -- 2. Among remaining candidates, pick closest to previous fix
            -- 3. If no previous fix, use source priority
            
            IF @prev_lat IS NOT NULL AND @prev_lon IS NOT NULL
            BEGIN
                -- Have previous coordinates - use proximity + airway context
                SELECT TOP 1 
                    @resolved_lat = c.lat,
                    @resolved_lon = c.lon,
                    @resolved_type = c.fix_type
                FROM #candidates c
                WHERE c.fix_name = @wp_name
                ORDER BY 
                    -- Prefer candidates on the same airway (if we're on an airway)
                    CASE WHEN @wp_on_airway IS NOT NULL 
                              AND c.on_airways LIKE '%' + @wp_on_airway + '%' 
                         THEN 0 ELSE 1 END,
                    -- Then by distance to previous fix
                    geography::Point(@prev_lat, @prev_lon, 4326).STDistance(
                        geography::Point(c.lat, c.lon, 4326)
                    );
            END
            ELSE
            BEGIN
                -- No previous coordinates - use source priority
                SELECT TOP 1 
                    @resolved_lat = c.lat,
                    @resolved_lon = c.lon,
                    @resolved_type = c.fix_type
                FROM #candidates c
                WHERE c.fix_name = @wp_name
                ORDER BY 
                    CASE c.nav_source 
                        WHEN 'points.csv' THEN 1 
                        WHEN 'navaids.csv' THEN 2 
                        WHEN 'XPLANE' THEN 3 
                        ELSE 4 
                    END;
            END
            
            -- Update waypoint
            IF @resolved_lat IS NOT NULL
            BEGIN
                UPDATE @waypoints 
                SET lat = @resolved_lat, lon = @resolved_lon, fix_type = ISNULL(@resolved_type, fix_type)
                WHERE seq = @wp_seq;
                
                SET @prev_lat = @resolved_lat;
                SET @prev_lon = @resolved_lon;
            END
        END
        
        FETCH NEXT FROM resolve_cursor INTO @wp_seq, @wp_name, @wp_lat, @wp_lon, @wp_on_airway;
    END
    
    CLOSE resolve_cursor;
    DEALLOCATE resolve_cursor;
    
    -- Resolve destination (prioritize AIRPORT type)
    IF @dest_icao IS NOT NULL
    BEGIN
        SELECT TOP 1 @resolved_lat = lat, @resolved_lon = lon
        FROM dbo.nav_fixes
        WHERE fix_name = @dest_icao OR fix_name = 'K' + @dest_icao
        ORDER BY 
            CASE WHEN fix_type = 'AIRPORT' THEN 0 ELSE 1 END,
            CASE WHEN fix_name = @dest_icao THEN 0 ELSE 1 END;
        
        UPDATE @waypoints SET lat = @resolved_lat, lon = @resolved_lon WHERE source = 'DESTINATION';
    END

    IF @debug = 1
    BEGIN
        PRINT '';
        PRINT 'Waypoints after Phase 4 (resolved):';
        SELECT seq, fix_name, ROUND(lat, 2) as lat, ROUND(lon, 2) as lon, source, on_airway FROM @waypoints ORDER BY seq;
    END

    -- ========================================================================
    -- PHASE 5: Build geometry and calculate distances
    -- ========================================================================
    
    -- Build expanded route string
    DECLARE @expanded_route NVARCHAR(MAX) = '';
    SELECT @expanded_route = @expanded_route + fix_name + ' '
    FROM @waypoints WHERE fix_name IS NOT NULL ORDER BY seq;
    SET @expanded_route = LTRIM(RTRIM(@expanded_route));
    
    -- Build LineString
    DECLARE @linestring NVARCHAR(MAX) = 'LINESTRING(';
    DECLARE @point_count INT = 0;
    
    DECLARE geo_cursor CURSOR LOCAL FAST_FORWARD FOR
        SELECT lat, lon FROM @waypoints WHERE lat IS NOT NULL AND lon IS NOT NULL ORDER BY seq;
    
    OPEN geo_cursor;
    FETCH NEXT FROM geo_cursor INTO @wp_lat, @wp_lon;
    
    WHILE @@FETCH_STATUS = 0
    BEGIN
        IF @point_count > 0 SET @linestring = @linestring + ', ';
        SET @linestring = @linestring + CAST(ROUND(@wp_lon, 6) AS VARCHAR(20)) + ' ' + CAST(ROUND(@wp_lat, 6) AS VARCHAR(20));
        SET @point_count = @point_count + 1;
        FETCH NEXT FROM geo_cursor INTO @wp_lat, @wp_lon;
    END
    
    CLOSE geo_cursor;
    DEALLOCATE geo_cursor;
    
    SET @linestring = @linestring + ')';
    
    DECLARE @route_geo GEOGRAPHY = NULL;
    IF @point_count >= 2
    BEGIN
        BEGIN TRY
            -- Use MakeValid() to ensure the geometry is valid for spatial operations
            SET @route_geo = geography::STGeomFromText(@linestring, 4326).MakeValid();
        END TRY
        BEGIN CATCH
            IF @debug = 1 PRINT 'Error creating LineString: ' + ERROR_MESSAGE();
        END CATCH
    END

    -- ========================================================================
    -- PHASE 6: Save to database
    -- ========================================================================
    
    DECLARE @parse_status NVARCHAR(20) = CASE 
        WHEN @point_count >= 2 THEN 'COMPLETE'
        WHEN @point_count > 0 THEN 'PARTIAL'
        ELSE 'FAILED' END;
    
    UPDATE dbo.adl_flight_plan
    SET route_geometry = @route_geo,
        fp_route_expanded = @expanded_route,
        parse_status = @parse_status,
        parse_utc = SYSUTCDATETIME(),
        dp_name = COALESCE(@dp_name, dp_name),
        dfix = COALESCE(@dfix, dfix),
        dtrsn = COALESCE(@dtrsn, dtrsn),
        star_name = COALESCE(@star_name, star_name),
        afix = COALESCE(@afix, afix),
        strsn = COALESCE(@strsn, strsn),
        -- Runway extraction from route (v4.3)
        dep_runway = COALESCE(@dep_runway, dep_runway),
        dep_runway_source = CASE WHEN @dep_runway IS NOT NULL AND dep_runway IS NULL THEN 'ROUTE' ELSE dep_runway_source END,
        arr_runway = COALESCE(@arr_runway, arr_runway),
        arr_runway_source = CASE WHEN @arr_runway IS NOT NULL AND arr_runway IS NULL THEN 'ROUTE' ELSE arr_runway_source END,
        waypoint_count = @point_count,
        -- Populate waypoints_json for FEA filtering (includes on_airway for airway-based monitors)
        waypoints_json = (
            SELECT fix_name, lat, lon, fix_type, source, on_airway, on_dp, on_star
            FROM @waypoints
            WHERE lat IS NOT NULL AND lon IS NOT NULL
            ORDER BY seq
            FOR JSON PATH
        )
    WHERE flight_uid = @flight_uid;
    
    -- Populate waypoints table (only those with coordinates)
    DELETE FROM dbo.adl_flight_waypoints WHERE flight_uid = @flight_uid;
    
    -- Log unresolved fixes in debug mode
    IF @debug = 1
    BEGIN
        DECLARE @unresolved INT;
        SELECT @unresolved = COUNT(*) FROM @waypoints WHERE lat IS NULL AND source NOT IN ('ORIGIN', 'DESTINATION');
        IF @unresolved > 0
        BEGIN
            PRINT '';
            PRINT 'WARNING: ' + CAST(@unresolved AS VARCHAR) + ' fixes could not be resolved:';
            SELECT fix_name, source, on_airway FROM @waypoints WHERE lat IS NULL ORDER BY seq;
        END
    END
    
    INSERT INTO dbo.adl_flight_waypoints (
        flight_uid, sequence_num, fix_name, lat, lon, position_geo,
        fix_type, source, on_airway, on_dp, on_star
    )
    SELECT
        @flight_uid,
        ROW_NUMBER() OVER (ORDER BY seq),
        fix_name, lat, lon,
        geography::Point(lat, lon, 4326),
        fix_type, source, on_airway, on_dp, on_star
    FROM @waypoints
    WHERE lat IS NOT NULL AND lon IS NOT NULL  -- Only insert resolved waypoints
    ORDER BY seq;
    
    -- Calculate segment distances
    ;WITH WaypointPairs AS (
        SELECT waypoint_id, sequence_num, lat, lon,
               LAG(lat) OVER (ORDER BY sequence_num) AS prev_lat,
               LAG(lon) OVER (ORDER BY sequence_num) AS prev_lon
        FROM dbo.adl_flight_waypoints WHERE flight_uid = @flight_uid
    )
    UPDATE fw
    SET segment_dist_nm = CASE 
        WHEN wp.prev_lat IS NOT NULL AND wp.lat IS NOT NULL
        THEN CAST(geography::Point(wp.prev_lat, wp.prev_lon, 4326).STDistance(
                  geography::Point(wp.lat, wp.lon, 4326)) / 1852.0 AS DECIMAL(10,2))
        ELSE 0 END
    FROM dbo.adl_flight_waypoints fw
    INNER JOIN WaypointPairs wp ON wp.waypoint_id = fw.waypoint_id;
    
    -- Calculate cumulative distances
    ;WITH CumulativeCalc AS (
        SELECT waypoint_id, SUM(ISNULL(segment_dist_nm, 0)) OVER (ORDER BY sequence_num) AS running_total
        FROM dbo.adl_flight_waypoints WHERE flight_uid = @flight_uid
    )
    UPDATE fw SET cum_dist_nm = cc.running_total
    FROM dbo.adl_flight_waypoints fw
    INNER JOIN CumulativeCalc cc ON cc.waypoint_id = fw.waypoint_id;
    
    -- Update route total
    DECLARE @route_total_nm DECIMAL(10,2);
    SELECT @route_total_nm = MAX(cum_dist_nm) FROM dbo.adl_flight_waypoints WHERE flight_uid = @flight_uid;
    UPDATE dbo.adl_flight_plan SET route_total_nm = @route_total_nm WHERE flight_uid = @flight_uid;

    -- Cleanup
    DROP TABLE IF EXISTS #candidates;
    DROP TABLE IF EXISTS #airway_fixes;

    -- Debug output
    IF @debug = 1
    BEGIN
        DECLARE @elapsed_ms INT = DATEDIFF(MILLISECOND, @parse_start, SYSUTCDATETIME());
        PRINT '';
        PRINT '================================================';
        PRINT 'Parse complete. Status: ' + @parse_status;
        PRINT 'Elapsed: ' + CAST(@elapsed_ms AS VARCHAR) + 'ms';
        PRINT 'Waypoints: ' + CAST(@point_count AS VARCHAR);
        PRINT 'Route Total: ' + ISNULL(CAST(@route_total_nm AS VARCHAR), 'NULL') + ' nm';
        PRINT '================================================';
        
        SELECT sequence_num, fix_name, ROUND(lat, 2) as lat, ROUND(lon, 2) as lon, 
               segment_dist_nm, cum_dist_nm, source, on_airway
        FROM dbo.adl_flight_waypoints
        WHERE flight_uid = @flight_uid
        ORDER BY sequence_num;
    END
END;
GO

-- ============================================================================
-- Batch processing wrapper
-- ============================================================================
IF OBJECT_ID('dbo.sp_ParseRouteBatch', 'P') IS NOT NULL
    DROP PROCEDURE dbo.sp_ParseRouteBatch;
GO

CREATE PROCEDURE dbo.sp_ParseRouteBatch
    @batch_size INT = 50,
    @tier TINYINT = NULL
AS
BEGIN
    SET NOCOUNT ON;
    
    DECLARE @processed INT = 0, @errors INT = 0, @flight_uid BIGINT;
    DECLARE @batch TABLE (flight_uid BIGINT);
    
    INSERT INTO @batch (flight_uid)
    SELECT TOP (@batch_size) pq.flight_uid
    FROM dbo.adl_parse_queue pq
    WHERE pq.status = 'PENDING' AND pq.next_eligible_utc <= SYSUTCDATETIME()
      AND (@tier IS NULL OR pq.parse_tier = @tier)
    ORDER BY pq.parse_tier, pq.queued_utc;
    
    UPDATE pq SET status = 'PROCESSING', attempts = attempts + 1
    FROM dbo.adl_parse_queue pq INNER JOIN @batch b ON pq.flight_uid = b.flight_uid;
    
    DECLARE batch_cursor CURSOR LOCAL FAST_FORWARD FOR SELECT flight_uid FROM @batch;
    OPEN batch_cursor;
    FETCH NEXT FROM batch_cursor INTO @flight_uid;
    
    WHILE @@FETCH_STATUS = 0
    BEGIN
        BEGIN TRY
            EXEC dbo.sp_ParseRoute @flight_uid = @flight_uid, @debug = 0;
            UPDATE dbo.adl_parse_queue SET status = 'COMPLETE', completed_utc = SYSUTCDATETIME() WHERE flight_uid = @flight_uid;
            SET @processed = @processed + 1;
        END TRY
        BEGIN CATCH
            UPDATE dbo.adl_parse_queue SET status = 'FAILED', error_message = LEFT(ERROR_MESSAGE(), 500),
                   next_eligible_utc = DATEADD(MINUTE, 5, SYSUTCDATETIME()) WHERE flight_uid = @flight_uid;
            SET @errors = @errors + 1;
        END CATCH
        FETCH NEXT FROM batch_cursor INTO @flight_uid;
    END
    
    CLOSE batch_cursor;
    DEALLOCATE batch_cursor;
    
    DELETE FROM dbo.adl_parse_queue WHERE status = 'COMPLETE' AND completed_utc < DATEADD(HOUR, -1, SYSUTCDATETIME());
    SELECT @processed AS processed, @errors AS errors;
END;
GO

PRINT 'sp_ParseRoute v4.3 created - Enhanced Fix/Runway Extraction';
PRINT '';
PRINT 'Key improvements:';
PRINT '  - v4.3: Extract dep_runway/arr_runway from route (e.g., TEBUN1A/02L)';
PRINT '  - v4.3: Extract dfix/afix for ALL routes (fallback for direct routes)';
PRINT '  - v4.3: Recognize SID/STAR patterns ending with letters (ABTAN2W)';
PRINT '  - v4.2: Populate waypoints_json with on_airway for FEA filtering';
PRINT '  - v4.1: Added dfix (departure fix) population';
PRINT '  - ONE database query for all candidate fixes';
PRINT '  - Sequential proximity resolution (each fix uses previous)';
PRINT '  - Airway-aware: prefers fixes actually on the airway';
GO
