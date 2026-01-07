-- ============================================================================
-- sp_ParseRoute.sql (v3 - Full Expansion with Deduplication)
-- Full GIS Route Parsing for ADL Normalized Schema
--
-- v3 Changes (2026-01-06):
--   - Added fix deduplication to handle multiple sources (points.csv, XPLANE)
--   - Uses temp table #unique_fixes to prevent duplicate waypoints
--   - Priority: points.csv > navaids.csv > XPLANE > other
--
-- Parses flight plan route strings into:
--   1. Geographic LineString (route_geometry in adl_flight_plan)
--   2. Individual waypoint records (adl_flight_waypoints)
--
-- Handles: 
--   - Fixes/Waypoints
--   - Airways (worldwide - J, V, Q, T, A, L, M, N, B, G, R, H, W, Y, UL, UM, UN, etc.)
--   - SIDs/DPs
--   - STARs
--   - CDRs
--   - DCT (explicit direct, FAA implicit)
--   - Lat/Lon coordinates (multiple formats)
--   - Radial/DME fixes (e.g., RIC264111 = RIC 264°/111nm)
-- ============================================================================

SET ANSI_NULLS ON;
GO
SET QUOTED_IDENTIFIER ON;
GO

-- ============================================================================
-- Helper Function: Calculate point from navaid + radial + distance
-- Returns lat/lon given a starting point, bearing (degrees), and distance (nm)
-- ============================================================================
IF OBJECT_ID('dbo.fn_PointFromRadialDME', 'TF') IS NOT NULL
    DROP FUNCTION dbo.fn_PointFromRadialDME;
GO

CREATE FUNCTION dbo.fn_PointFromRadialDME (
    @navaid_lat FLOAT,
    @navaid_lon FLOAT,
    @radial FLOAT,      -- degrees (magnetic heading FROM navaid)
    @distance_nm FLOAT  -- nautical miles
)
RETURNS @result TABLE (lat FLOAT, lon FLOAT)
AS
BEGIN
    -- Earth radius in nautical miles
    DECLARE @R FLOAT = 3440.065;
    
    -- Convert to radians
    DECLARE @lat1 FLOAT = RADIANS(@navaid_lat);
    DECLARE @lon1 FLOAT = RADIANS(@navaid_lon);
    DECLARE @brng FLOAT = RADIANS(@radial);
    DECLARE @d FLOAT = @distance_nm / @R;
    
    -- Calculate new position
    DECLARE @lat2 FLOAT = ASIN(SIN(@lat1) * COS(@d) + COS(@lat1) * SIN(@d) * COS(@brng));
    DECLARE @lon2 FLOAT = @lon1 + ATN2(SIN(@brng) * SIN(@d) * COS(@lat1), COS(@d) - SIN(@lat1) * SIN(@lat2));
    
    INSERT INTO @result (lat, lon)
    VALUES (DEGREES(@lat2), DEGREES(@lon2));
    
    RETURN;
END;
GO

-- ============================================================================
-- Helper Function: Parse Radial/DME fix (e.g., RIC264111, JFK090020)
-- Format: NAVAID (2-4 chars) + RADIAL (3 digits) + DME (2-3 digits)
-- ============================================================================
IF OBJECT_ID('dbo.fn_ParseRadialDME', 'TF') IS NOT NULL
    DROP FUNCTION dbo.fn_ParseRadialDME;
GO

CREATE FUNCTION dbo.fn_ParseRadialDME (
    @token NVARCHAR(50)
)
RETURNS @result TABLE (
    navaid NVARCHAR(10),
    radial INT,
    distance_nm INT,
    lat FLOAT,
    lon FLOAT,
    is_valid BIT
)
AS
BEGIN
    DECLARE @len INT = LEN(@token);
    DECLARE @navaid NVARCHAR(10);
    DECLARE @radial INT;
    DECLARE @dme INT;
    DECLARE @navaid_lat FLOAT;
    DECLARE @navaid_lon FLOAT;
    
    -- Must be 8-10 characters: 2-4 char navaid + 3 digit radial + 2-3 digit DME
    IF @len < 8 OR @len > 10
    BEGIN
        INSERT INTO @result VALUES (NULL, NULL, NULL, NULL, NULL, 0);
        RETURN;
    END
    
    -- Try different navaid lengths (2, 3, or 4 characters)
    DECLARE @navaid_len INT = @len - 6;  -- Assume 3-digit radial + 3-digit DME first
    IF @navaid_len < 2 SET @navaid_len = @len - 5;  -- Try 2-digit DME
    
    -- Extract components
    SET @navaid = UPPER(LEFT(@token, @navaid_len));
    
    -- Remaining should be radial (3 digits) + DME (2-3 digits)
    DECLARE @remainder NVARCHAR(10) = SUBSTRING(@token, @navaid_len + 1, 100);
    
    -- Radial is always 3 digits
    IF LEN(@remainder) >= 5 AND ISNUMERIC(LEFT(@remainder, 3)) = 1
    BEGIN
        SET @radial = CAST(LEFT(@remainder, 3) AS INT);
        SET @dme = CAST(SUBSTRING(@remainder, 4, 100) AS INT);
        
        -- Validate radial (0-360)
        IF @radial < 0 OR @radial > 360
        BEGIN
            INSERT INTO @result VALUES (NULL, NULL, NULL, NULL, NULL, 0);
            RETURN;
        END
        
        -- Look up navaid
        SELECT TOP 1 @navaid_lat = lat, @navaid_lon = lon
        FROM dbo.nav_fixes
        WHERE fix_name = @navaid AND fix_type IN ('VOR', 'NAVAID', 'VORTAC', 'NDB')
        ORDER BY fix_id;
        
        -- If not found with exact match, try as any fix
        IF @navaid_lat IS NULL
        BEGIN
            SELECT TOP 1 @navaid_lat = lat, @navaid_lon = lon
            FROM dbo.nav_fixes
            WHERE fix_name = @navaid
            ORDER BY fix_id;
        END
        
        IF @navaid_lat IS NOT NULL
        BEGIN
            -- Calculate position
            DECLARE @calc_lat FLOAT, @calc_lon FLOAT;
            SELECT @calc_lat = lat, @calc_lon = lon
            FROM dbo.fn_PointFromRadialDME(@navaid_lat, @navaid_lon, @radial, @dme);
            
            INSERT INTO @result VALUES (@navaid, @radial, @dme, @calc_lat, @calc_lon, 1);
            RETURN;
        END
    END
    
    INSERT INTO @result VALUES (NULL, NULL, NULL, NULL, NULL, 0);
    RETURN;
END;
GO

-- ============================================================================
-- Helper Function: Parse Lat/Lon coordinate strings
-- Formats: 40N073W, 4000N07300W, 40N/073W, 4000N/07300W, N40W073, 
--          5530N020W, 55N020W, etc.
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
    
    -- Determine lat direction position
    IF @n_pos > 0 
    BEGIN
        SET @lat_pos = @n_pos;
        SET @lat_dir = 'N';
    END
    ELSE IF @s_pos > 0
    BEGIN
        SET @lat_pos = @s_pos;
        SET @lat_dir = 'S';
    END
    ELSE
    BEGIN
        INSERT INTO @result VALUES (NULL, NULL, 0);
        RETURN;
    END
    
    -- Determine lon direction position
    IF @w_pos > 0
    BEGIN
        SET @lon_pos = @w_pos;
        SET @lon_dir = 'W';
    END
    ELSE IF @e_pos > 0
    BEGIN
        SET @lon_pos = @e_pos;
        SET @lon_dir = 'E';
    END
    ELSE
    BEGIN
        INSERT INTO @result VALUES (NULL, NULL, 0);
        RETURN;
    END
    
    -- Pattern 1: Numbers before direction (e.g., 5530N02000W, 40N073W)
    IF @lat_pos > 1 AND ISNUMERIC(LEFT(@clean, @lat_pos - 1)) = 1
    BEGIN
        SET @lat_str = LEFT(@clean, @lat_pos - 1);
        
        -- Lon is between lat direction and lon direction
        IF @lon_pos > @lat_pos + 1
            SET @lon_str = SUBSTRING(@clean, @lat_pos + 1, @lon_pos - @lat_pos - 1);
        ELSE
            SET @lon_str = SUBSTRING(@clean, @lon_pos + 1, 100);
    END
    -- Pattern 2: Direction prefix (e.g., N5530W02000)
    ELSE IF @lat_pos = 1
    BEGIN
        -- Find where lon direction is
        DECLARE @mid_pos INT = CASE WHEN @w_pos > 1 THEN @w_pos WHEN @e_pos > 1 THEN @e_pos ELSE 0 END;
        IF @mid_pos > 2
        BEGIN
            SET @lat_str = SUBSTRING(@clean, 2, @mid_pos - 2);
            SET @lon_str = SUBSTRING(@clean, @mid_pos + 1, 100);
        END
    END
    
    -- Parse latitude
    IF @lat_str IS NOT NULL AND LEN(@lat_str) > 0 AND ISNUMERIC(@lat_str) = 1
    BEGIN
        IF LEN(@lat_str) <= 2
            SET @lat = CAST(@lat_str AS FLOAT);
        ELSE IF LEN(@lat_str) = 3
            SET @lat = CAST(LEFT(@lat_str, 2) AS FLOAT) + CAST(RIGHT(@lat_str, 1) AS FLOAT) * 10 / 60.0;
        ELSE IF LEN(@lat_str) = 4
            SET @lat = CAST(LEFT(@lat_str, 2) AS FLOAT) + CAST(RIGHT(@lat_str, 2) AS FLOAT) / 60.0;
        ELSE IF LEN(@lat_str) >= 5
            SET @lat = CAST(LEFT(@lat_str, 2) AS FLOAT) + CAST(SUBSTRING(@lat_str, 3, 2) AS FLOAT) / 60.0 + CAST(SUBSTRING(@lat_str, 5, 100) AS FLOAT) / 3600.0;
        
        IF @lat_dir = 'S' SET @lat = -@lat;
    END
    
    -- Parse longitude
    IF @lon_str IS NOT NULL AND LEN(@lon_str) > 0 AND ISNUMERIC(@lon_str) = 1
    BEGIN
        IF LEN(@lon_str) <= 3
            SET @lon = CAST(@lon_str AS FLOAT);
        ELSE IF LEN(@lon_str) = 4
            SET @lon = CAST(LEFT(@lon_str, 3) AS FLOAT) + CAST(RIGHT(@lon_str, 1) AS FLOAT) * 10 / 60.0;
        ELSE IF LEN(@lon_str) = 5
            SET @lon = CAST(LEFT(@lon_str, 3) AS FLOAT) + CAST(RIGHT(@lon_str, 2) AS FLOAT) / 60.0;
        ELSE IF LEN(@lon_str) >= 6
            SET @lon = CAST(LEFT(@lon_str, 3) AS FLOAT) + CAST(SUBSTRING(@lon_str, 4, 2) AS FLOAT) / 60.0 + CAST(SUBSTRING(@lon_str, 6, 100) AS FLOAT) / 3600.0;
        
        IF @lon_dir = 'W' SET @lon = -@lon;
    END
    
    -- Validate
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
    
    -- Skip empty
    IF @upper IS NULL OR @upper = '' OR @upper = '.'
        RETURN 'SKIP';
    
    -- DCT = Direct (skip, implicit in FAA)
    IF @upper = 'DCT' OR @upper = 'DIRECT'
        RETURN 'SKIP';
    
    -- IFR = instrument flight rules marker
    IF @upper = 'IFR' OR @upper = 'VFR'
        RETURN 'SKIP';
    
    -- Speed/Altitude specs: N0450F350, /N0450F350, M082F390, F350, FL350, A050
    IF @upper LIKE 'N0[0-9][0-9][0-9]%' OR @upper LIKE '/N0%' OR @upper LIKE 'M0[0-9][0-9]%' OR
       @upper LIKE 'K0[0-9][0-9][0-9]%' OR  -- Km/h speed
       @upper LIKE 'F[0-9][0-9][0-9]' OR @upper LIKE 'FL[0-9][0-9][0-9]' OR
       @upper LIKE 'A[0-9][0-9][0-9]' OR @upper LIKE 'S[0-9][0-9][0-9][0-9]' OR
       @upper LIKE 'VFR/[0-9]%' OR @upper LIKE 'IFR/[0-9]%'
        RETURN 'SPEED_ALT';
    
    -- SID/STAR combined format with dot
    IF @upper LIKE '%.%' AND LEN(@upper) >= 5
    BEGIN
        -- DP format: NAME#.TRANSITION (e.g., KAYLN3.SMUUV)
        IF @upper LIKE '%[0-9].%' AND CHARINDEX('.', @upper) > 2
            RETURN 'SID';
        -- STAR format: TRANSITION.NAME# (e.g., SMUUV.WYNDE3)
        IF @upper LIKE '%.%[0-9]' AND CHARINDEX('.', @upper) > 1
            RETURN 'STAR';
    END
    
    -- SID/STAR without dot - NAME followed by single digit and optional letter
    -- Pattern: 3-5 letters + 1 digit + 0-1 letter (e.g., SKORR5, ANJLL4, ABC1, RNAV1A)
    -- Total length: 4-7 characters
    IF LEN(@upper) BETWEEN 4 AND 7 
       AND @upper LIKE '[A-Z][A-Z][A-Z]%[0-9]' 
       AND @upper NOT LIKE '%[0-9][0-9]%'  -- Not multiple digits
       AND @upper LIKE '[A-Z][A-Z][A-Z][A-Z0-9]%'  -- At least 3 letters at start
        RETURN 'SID_OR_STAR';
    
    -- Lat/Lon coordinates - check before radial/DME
    IF @upper LIKE '%[0-9]N%[0-9]W' OR @upper LIKE '%[0-9]S%[0-9]E' OR
       @upper LIKE '%[0-9]N%[0-9]E' OR @upper LIKE '%[0-9]S%[0-9]W' OR
       @upper LIKE 'N[0-9]%W[0-9]%' OR @upper LIKE 'S[0-9]%E[0-9]%' OR
       @upper LIKE 'N[0-9]%E[0-9]%' OR @upper LIKE 'S[0-9]%W[0-9]%'
        RETURN 'LATLON';
    
    -- Radial/DME fix: 2-4 letter navaid + 3 digit radial + 2-3 digit DME
    -- Examples: RIC264111, JFK090020, DCA180015
    IF LEN(@upper) BETWEEN 8 AND 10 
       AND @upper LIKE '[A-Z][A-Z][0-9][0-9][0-9][0-9][0-9]%'  -- 2-char navaid
       OR @upper LIKE '[A-Z][A-Z][A-Z][0-9][0-9][0-9][0-9][0-9]%'  -- 3-char navaid
    BEGIN
        -- Verify the numeric portion looks like radial + DME
        DECLARE @test_navaid INT = PATINDEX('%[0-9]%', @upper) - 1;
        IF @test_navaid >= 2 AND @test_navaid <= 4
        BEGIN
            DECLARE @test_numeric NVARCHAR(20) = SUBSTRING(@upper, @test_navaid + 1, 100);
            IF ISNUMERIC(@test_numeric) = 1 AND LEN(@test_numeric) >= 5
                RETURN 'RADIAL_DME';
        END
    END
    
    -- Airways - comprehensive worldwide coverage
    -- US: J (Jet), V (Victor), Q (RNAV High), T (RNAV Low)
    -- Oceanic: A (Atlantic/Pacific)
    -- European: L, M, N, B, G, R, W, Y, UL, UM, UN, UP, UR, UT, UW, UY, UZ
    -- Others: H (helicopter), Z
    IF @upper LIKE 'J[0-9]%' OR @upper LIKE 'V[0-9]%' OR 
       @upper LIKE 'Q[0-9]%' OR @upper LIKE 'T[0-9]%' OR
       @upper LIKE 'A[0-9][0-9]%' OR  -- A followed by 2+ digits (oceanic)
       @upper LIKE 'L[0-9]%' OR @upper LIKE 'M[0-9]%' OR @upper LIKE 'N[0-9]%' OR
       @upper LIKE 'B[0-9]%' OR @upper LIKE 'G[0-9]%' OR @upper LIKE 'R[0-9]%' OR
       @upper LIKE 'H[0-9]%' OR @upper LIKE 'W[0-9]%' OR @upper LIKE 'Y[0-9]%' OR
       @upper LIKE 'Z[0-9]%' OR @upper LIKE 'P[0-9]%' OR
       -- Upper airspace (European UIR)
       @upper LIKE 'UL[0-9]%' OR @upper LIKE 'UM[0-9]%' OR @upper LIKE 'UN[0-9]%' OR
       @upper LIKE 'UP[0-9]%' OR @upper LIKE 'UR[0-9]%' OR @upper LIKE 'UT[0-9]%' OR
       @upper LIKE 'UW[0-9]%' OR @upper LIKE 'UY[0-9]%' OR @upper LIKE 'UZ[0-9]%' OR
       @upper LIKE 'UA[0-9]%' OR @upper LIKE 'UB[0-9]%' OR @upper LIKE 'UG[0-9]%' OR
       @upper LIKE 'UH[0-9]%'
        RETURN 'AIRWAY';
    
    -- Airport: 4 letters (ICAO) - but exclude things that look like fixes
    IF LEN(@upper) = 4 AND @upper LIKE '[A-Z][A-Z][A-Z][A-Z]'
    BEGIN
        -- Common ICAO prefixes
        IF LEFT(@upper, 1) IN ('K', 'C', 'E', 'L', 'O', 'U', 'Z', 'R', 'V', 'W', 'Y', 'P', 'M', 'T', 'S', 'F', 'D', 'H', 'G', 'B', 'N')
            RETURN 'AIRPORT';
    END
    
    -- 3-letter airport (FAA codes, often in route strings)
    IF LEN(@upper) = 3 AND @upper LIKE '[A-Z][A-Z][A-Z]'
        RETURN 'FIX';  -- Treat as fix, could be VOR or airport
    
    -- Fix/Waypoint: 2-5 letters/numbers (most common)
    IF LEN(@upper) BETWEEN 2 AND 5 AND @upper LIKE '[A-Z]%' AND @upper NOT LIKE '%.%'
        RETURN 'FIX';
    
    -- Longer waypoints (some international fixes, RNAV waypoints)
    IF LEN(@upper) BETWEEN 6 AND 12 AND @upper LIKE '[A-Z][A-Z][A-Z][A-Z][A-Z]%' AND @upper NOT LIKE '%.%'
        RETURN 'FIX';
    
    RETURN 'UNKNOWN';
END;
GO

-- ============================================================================
-- Helper Function: Expand airway between two fixes
-- Returns the sequence of fixes on the airway between entry and exit
-- NOTE: Uses ROW_NUMBER() to deduplicate fixes from multiple sources
-- ============================================================================
IF OBJECT_ID('dbo.fn_ExpandAirway', 'TF') IS NOT NULL
    DROP FUNCTION dbo.fn_ExpandAirway;
GO

CREATE FUNCTION dbo.fn_ExpandAirway (
    @airway_name NVARCHAR(10),
    @entry_fix NVARCHAR(50),
    @exit_fix NVARCHAR(50)
)
RETURNS @result TABLE (
    seq INT,
    fix_name NVARCHAR(50),
    lat FLOAT,
    lon FLOAT
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

    -- If either not found, try reverse direction
    IF @entry_pos IS NULL OR @exit_pos IS NULL
        RETURN;

    -- Determine direction and extract segment
    -- Use subquery with ROW_NUMBER to get only one fix per name (prefer points.csv > navaids.csv > XPLANE)
    IF @entry_pos < @exit_pos
    BEGIN
        -- Forward direction
        INSERT INTO @result (seq, fix_name, lat, lon)
        SELECT
            ROW_NUMBER() OVER (ORDER BY f.pos),
            f.fix,
            uf.lat,
            uf.lon
        FROM @fixes f
        LEFT JOIN (
            SELECT fix_name, lat, lon,
                   ROW_NUMBER() OVER (PARTITION BY fix_name ORDER BY
                       CASE source WHEN 'points.csv' THEN 1 WHEN 'navaids.csv' THEN 2 WHEN 'XPLANE' THEN 3 ELSE 4 END,
                       fix_id) AS rn
            FROM dbo.nav_fixes
        ) uf ON uf.fix_name = f.fix AND uf.rn = 1
        WHERE f.pos >= @entry_pos AND f.pos <= @exit_pos
        ORDER BY f.pos;
    END
    ELSE
    BEGIN
        -- Reverse direction
        INSERT INTO @result (seq, fix_name, lat, lon)
        SELECT
            ROW_NUMBER() OVER (ORDER BY f.pos DESC),
            f.fix,
            uf.lat,
            uf.lon
        FROM @fixes f
        LEFT JOIN (
            SELECT fix_name, lat, lon,
                   ROW_NUMBER() OVER (PARTITION BY fix_name ORDER BY
                       CASE source WHEN 'points.csv' THEN 1 WHEN 'navaids.csv' THEN 2 WHEN 'XPLANE' THEN 3 ELSE 4 END,
                       fix_id) AS rn
            FROM dbo.nav_fixes
        ) uf ON uf.fix_name = f.fix AND uf.rn = 1
        WHERE f.pos >= @exit_pos AND f.pos <= @entry_pos
        ORDER BY f.pos DESC;
    END

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

    -- ========================================================================
    -- Create temp table with deduplicated fixes (one per fix_name)
    -- Priority: points.csv > navaids.csv > XPLANE
    -- This prevents duplicate waypoints when nav_fixes has multiple sources
    -- ========================================================================
    IF OBJECT_ID('tempdb..#unique_fixes') IS NOT NULL
        DROP TABLE #unique_fixes;

    ;WITH ranked_fixes AS (
        SELECT fix_name, lat, lon, fix_type,
               ROW_NUMBER() OVER (PARTITION BY fix_name ORDER BY
                   CASE source WHEN 'points.csv' THEN 1 WHEN 'navaids.csv' THEN 2 WHEN 'XPLANE' THEN 3 ELSE 4 END,
                   fix_id) AS rn
        FROM dbo.nav_fixes
    )
    SELECT fix_name, lat, lon, fix_type
    INTO #unique_fixes
    FROM ranked_fixes
    WHERE rn = 1;

    -- Index for fast lookups
    CREATE NONCLUSTERED INDEX IX_unique_fixes_name ON #unique_fixes(fix_name);

    IF @debug = 1
        PRINT 'Created #unique_fixes temp table with ' + CAST(@@ROWCOUNT AS VARCHAR) + ' deduplicated fixes';

    -- Get flight plan data
    SELECT 
        @route = fp_route,
        @dept_icao = fp_dept_icao,
        @dest_icao = fp_dest_icao
    FROM dbo.adl_flight_plan
    WHERE flight_uid = @flight_uid;
    
    IF @route IS NULL OR LEN(LTRIM(RTRIM(@route))) = 0
    BEGIN
        IF @debug = 1 PRINT 'No route found for flight_uid: ' + CAST(@flight_uid AS VARCHAR);
        
        -- Mark as complete but no route
        UPDATE dbo.adl_flight_plan
        SET parse_status = 'NO_ROUTE',
            parse_utc = SYSUTCDATETIME()
        WHERE flight_uid = @flight_uid;
        
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
    -- Step 1: Tokenize route string
    -- ========================================================================
    DECLARE @tokens TABLE (
        seq INT IDENTITY(1,1),
        token NVARCHAR(100),
        token_type NVARCHAR(20)
    );
    
    -- Clean route string
    DECLARE @clean_route NVARCHAR(MAX) = UPPER(LTRIM(RTRIM(@route)));
    
    -- Normalize separators
    SET @clean_route = REPLACE(@clean_route, '+', ' ');
    SET @clean_route = REPLACE(@clean_route, CHAR(9), ' ');  -- Tab
    SET @clean_route = REPLACE(@clean_route, CHAR(10), ' '); -- LF
    SET @clean_route = REPLACE(@clean_route, CHAR(13), ' '); -- CR
    
    -- Handle SID/STAR dots - preserve NAME#.TRANS format
    -- But handle stray dots
    DECLARE @i INT = 1;
    WHILE @i <= LEN(@clean_route)
    BEGIN
        IF SUBSTRING(@clean_route, @i, 1) = '.'
        BEGIN
            -- Check if surrounded by alphanumeric (valid SID/STAR)
            DECLARE @prev_char NVARCHAR(1) = CASE WHEN @i > 1 THEN SUBSTRING(@clean_route, @i - 1, 1) ELSE ' ' END;
            DECLARE @next_char NVARCHAR(1) = CASE WHEN @i < LEN(@clean_route) THEN SUBSTRING(@clean_route, @i + 1, 1) ELSE ' ' END;
            
            IF @prev_char LIKE '[A-Z0-9]' AND @next_char LIKE '[A-Z0-9]'
            BEGIN
                -- Valid, keep dot
                SET @i = @i + 1;
            END
            ELSE
            BEGIN
                -- Stray dot, replace with space
                SET @clean_route = LEFT(@clean_route, @i - 1) + ' ' + SUBSTRING(@clean_route, @i + 1, LEN(@clean_route));
            END
        END
        ELSE
            SET @i = @i + 1;
    END
    
    -- Collapse multiple spaces
    WHILE CHARINDEX('  ', @clean_route) > 0
        SET @clean_route = REPLACE(@clean_route, '  ', ' ');
    
    -- Split by space
    INSERT INTO @tokens (token, token_type)
    SELECT 
        value,
        dbo.fn_GetTokenType(value)
    FROM STRING_SPLIT(@clean_route, ' ')
    WHERE LEN(LTRIM(RTRIM(value))) > 0;
    
    IF @debug = 1
    BEGIN
        PRINT '';
        PRINT 'Tokens:';
        DECLARE @dbg_seq INT, @dbg_token NVARCHAR(100), @dbg_type NVARCHAR(20);
        DECLARE dbg_cursor CURSOR LOCAL FAST_FORWARD FOR 
            SELECT seq, token, token_type FROM @tokens ORDER BY seq;
        OPEN dbg_cursor;
        FETCH NEXT FROM dbg_cursor INTO @dbg_seq, @dbg_token, @dbg_type;
        WHILE @@FETCH_STATUS = 0
        BEGIN
            PRINT '  ' + CAST(@dbg_seq AS VARCHAR) + ': ' + @dbg_token + ' [' + @dbg_type + ']';
            FETCH NEXT FROM dbg_cursor INTO @dbg_seq, @dbg_token, @dbg_type;
        END
        CLOSE dbg_cursor;
        DEALLOCATE dbg_cursor;
    END
    
    -- ========================================================================
    -- Step 2: Build waypoint list with coordinates
    -- ========================================================================
    DECLARE @waypoints TABLE (
        seq INT IDENTITY(1,1),
        fix_name NVARCHAR(50),
        lat FLOAT,
        lon FLOAT,
        fix_type NVARCHAR(20),
        source NVARCHAR(20),  -- ROUTE, SID, STAR, AIRWAY, COORD, RADIAL_DME
        on_airway NVARCHAR(10),
        on_dp NVARCHAR(20),      -- DP/SID procedure name (e.g., SKORR5)
        on_star NVARCHAR(20),    -- STAR procedure name (e.g., ANJLL4)
        original_token NVARCHAR(100),
        -- CIFP leg-level constraints
        leg_type CHAR(2),           -- TF, CF, IF, DF, VA, VM, etc.
        alt_restriction CHAR(1),    -- +, -, B, @
        altitude_1_ft INT,
        altitude_2_ft INT,
        speed_limit_kts SMALLINT
    );
    
    -- Add departure airport first
    IF @dept_icao IS NOT NULL
    BEGIN
        INSERT INTO @waypoints (fix_name, lat, lon, fix_type, source, original_token)
        SELECT TOP 1 @dept_icao, lat, lon, 'AIRPORT', 'ORIGIN', @dept_icao
        FROM dbo.nav_fixes
        WHERE fix_name = @dept_icao OR fix_name = 'K' + @dept_icao OR fix_name = SUBSTRING(@dept_icao, 2, 3)
        ORDER BY CASE WHEN fix_name = @dept_icao THEN 1 ELSE 2 END, fix_id;
    END
    
    -- Process each token
    DECLARE @t_seq INT;
    DECLARE @t_token NVARCHAR(100);
    DECLARE @t_type NVARCHAR(20);
    DECLARE @prev_fix NVARCHAR(50) = NULL;
    DECLARE @prev_fix_lat FLOAT = NULL;
    DECLARE @prev_fix_lon FLOAT = NULL;
    DECLARE @pending_airway NVARCHAR(10) = NULL;
    
    -- Metadata tracking
    DECLARE @dp_name NVARCHAR(16) = NULL;
    DECLARE @dfix NVARCHAR(8) = NULL;
    DECLARE @dtrsn NVARCHAR(16) = NULL;
    DECLARE @star_name NVARCHAR(16) = NULL;
    DECLARE @afix NVARCHAR(8) = NULL;
    DECLARE @strsn NVARCHAR(16) = NULL;
    DECLARE @found_sid BIT = 0;
    DECLARE @last_fix_before_star NVARCHAR(50) = NULL;
    DECLARE @next_fix_after_dp NVARCHAR(50) = NULL;

    DECLARE route_cursor CURSOR LOCAL FAST_FORWARD FOR
        SELECT seq, token, token_type 
        FROM @tokens 
        WHERE token_type NOT IN ('SKIP', 'SPEED_ALT', 'UNKNOWN')
        ORDER BY seq;
    
    OPEN route_cursor;
    FETCH NEXT FROM route_cursor INTO @t_seq, @t_token, @t_type;
    
    WHILE @@FETCH_STATUS = 0
    BEGIN
        IF @debug = 1 PRINT 'Processing: ' + @t_token + ' [' + @t_type + ']';
        
        -- ====================================================================
        -- AIRWAY: Remember it for expansion when we hit the exit fix
        -- ====================================================================
        IF @t_type = 'AIRWAY'
        BEGIN
            SET @pending_airway = @t_token;
            IF @debug = 1 PRINT '  -> Pending airway: ' + @pending_airway;
        END
        
        -- ====================================================================
        -- FIX: Look up and add, expand pending airway if any
        -- ====================================================================
        ELSE IF @t_type = 'FIX'
        BEGIN
            DECLARE @fix_lat FLOAT, @fix_lon FLOAT, @fix_type_found NVARCHAR(20);
            
            -- Look up fix
            SELECT TOP 1 
                @fix_lat = lat, 
                @fix_lon = lon,
                @fix_type_found = fix_type
            FROM dbo.nav_fixes
            WHERE fix_name = @t_token
            ORDER BY fix_id;
            
            -- If pending airway, expand it from prev_fix to this fix
            IF @pending_airway IS NOT NULL AND @prev_fix IS NOT NULL
            BEGIN
                IF @debug = 1 PRINT '  -> Expanding airway ' + @pending_airway + ' from ' + @prev_fix + ' to ' + @t_token;
                
                -- Get intermediate fixes (skip first since it's prev_fix, skip last since we'll add it)
                INSERT INTO @waypoints (fix_name, lat, lon, fix_type, source, on_airway, original_token)
                SELECT 
                    fix_name, lat, lon, 'WAYPOINT', 'AIRWAY', @pending_airway, @pending_airway
                FROM dbo.fn_ExpandAirway(@pending_airway, @prev_fix, @t_token)
                WHERE seq > 1 AND fix_name != @t_token  -- Skip entry fix (already added) and exit fix (add below)
                ORDER BY seq;
                
                SET @pending_airway = NULL;
            END
            
            -- Add this fix
            IF @fix_lat IS NOT NULL
            BEGIN
                INSERT INTO @waypoints (fix_name, lat, lon, fix_type, source, on_airway, original_token)
                VALUES (@t_token, @fix_lat, @fix_lon, ISNULL(@fix_type_found, 'WAYPOINT'), 
                        CASE WHEN @pending_airway IS NOT NULL THEN 'AIRWAY' ELSE 'ROUTE' END,
                        @pending_airway, @t_token);
                
                SET @prev_fix = @t_token;
                SET @prev_fix_lat = @fix_lat;
                SET @prev_fix_lon = @fix_lon;
                SET @last_fix_before_star = @t_token;
                
                -- If this is the first fix after a SID and we don't have DFIX yet, set it
                IF @found_sid = 1 AND @dfix IS NULL
                BEGIN
                    SET @dfix = @t_token;
                    SET @dtrsn = @t_token;
                END
            END
            ELSE IF @debug = 1
                PRINT '  -> Fix not found in nav_fixes';
            
            SET @pending_airway = NULL;
        END
        
        -- ====================================================================
        -- AIRPORT: Add if not origin/destination
        -- ====================================================================
        ELSE IF @t_type = 'AIRPORT'
        BEGIN
            IF @t_token NOT IN (ISNULL(@dept_icao, ''), ISNULL(@dest_icao, ''))
            BEGIN
                INSERT INTO @waypoints (fix_name, lat, lon, fix_type, source, original_token)
                SELECT TOP 1 @t_token, lat, lon, 'AIRPORT', 'ROUTE', @t_token
                FROM dbo.nav_fixes
                WHERE fix_name = @t_token OR fix_name = 'K' + @t_token OR fix_name = SUBSTRING(@t_token, 2, 3)
                ORDER BY fix_id;
                
                IF @@ROWCOUNT > 0
                BEGIN
                    SELECT TOP 1 @prev_fix = fix_name, @prev_fix_lat = lat, @prev_fix_lon = lon
                    FROM @waypoints ORDER BY seq DESC;
                END
            END
            SET @pending_airway = NULL;
        END
        
        -- ====================================================================
        -- LATLON: Parse coordinate
        -- ====================================================================
        ELSE IF @t_type = 'LATLON'
        BEGIN
            DECLARE @coord_lat FLOAT, @coord_lon FLOAT, @coord_valid BIT;
            
            SELECT @coord_lat = lat, @coord_lon = lon, @coord_valid = is_valid
            FROM dbo.fn_ParseCoordinate(@t_token);
            
            IF @coord_valid = 1
            BEGIN
                INSERT INTO @waypoints (fix_name, lat, lon, fix_type, source, original_token)
                VALUES (@t_token, @coord_lat, @coord_lon, 'COORD', 'COORD', @t_token);
                
                SET @prev_fix = @t_token;
                SET @prev_fix_lat = @coord_lat;
                SET @prev_fix_lon = @coord_lon;
                
                IF @debug = 1 PRINT '  -> Parsed: ' + CAST(@coord_lat AS VARCHAR) + ', ' + CAST(@coord_lon AS VARCHAR);
            END
            ELSE IF @debug = 1
                PRINT '  -> Failed to parse coordinate';
            
            SET @pending_airway = NULL;
        END
        
        -- ====================================================================
        -- RADIAL_DME: Calculate position from navaid
        -- ====================================================================
        ELSE IF @t_type = 'RADIAL_DME'
        BEGIN
            DECLARE @rdme_navaid NVARCHAR(10), @rdme_radial INT, @rdme_dme INT;
            DECLARE @rdme_lat FLOAT, @rdme_lon FLOAT, @rdme_valid BIT;
            
            SELECT @rdme_navaid = navaid, @rdme_radial = radial, @rdme_dme = distance_nm,
                   @rdme_lat = lat, @rdme_lon = lon, @rdme_valid = is_valid
            FROM dbo.fn_ParseRadialDME(@t_token);
            
            IF @rdme_valid = 1
            BEGIN
                INSERT INTO @waypoints (fix_name, lat, lon, fix_type, source, original_token)
                VALUES (@t_token, @rdme_lat, @rdme_lon, 'RADIAL_DME', 'RADIAL_DME', @t_token);
                
                SET @prev_fix = @t_token;
                SET @prev_fix_lat = @rdme_lat;
                SET @prev_fix_lon = @rdme_lon;
                
                IF @debug = 1 
                    PRINT '  -> Radial/DME: ' + @rdme_navaid + ' R' + CAST(@rdme_radial AS VARCHAR) + 
                          '/' + CAST(@rdme_dme AS VARCHAR) + ' = ' + CAST(@rdme_lat AS VARCHAR) + ', ' + CAST(@rdme_lon AS VARCHAR);
            END
            ELSE IF @debug = 1
                PRINT '  -> Failed to parse radial/DME';
            
            SET @pending_airway = NULL;
        END
        
        -- ====================================================================
        -- SID: Expand from nav_procedures (with CIFP leg support)
        -- ====================================================================
        ELSE IF @t_type = 'SID'
        BEGIN
            DECLARE @sid_route NVARCHAR(MAX);
            DECLARE @sid_proc_id INT = NULL;
            DECLARE @sid_has_legs BIT = 0;

            -- Try exact match first
            SELECT TOP 1 @sid_route = full_route, @sid_proc_id = procedure_id, @sid_has_legs = ISNULL(has_leg_detail, 0)
            FROM dbo.nav_procedures
            WHERE computer_code = @t_token AND procedure_type = 'DP';

            -- Try without transition if not found
            IF @sid_route IS NULL AND CHARINDEX('.', @t_token) > 0
            BEGIN
                DECLARE @sid_base NVARCHAR(50) = LEFT(@t_token, CHARINDEX('.', @t_token) - 1);
                SELECT TOP 1 @sid_route = full_route, @sid_proc_id = procedure_id, @sid_has_legs = ISNULL(has_leg_detail, 0)
                FROM dbo.nav_procedures
                WHERE computer_code LIKE @sid_base + '.%' AND procedure_type = 'DP';
            END

            IF @sid_proc_id IS NOT NULL
            BEGIN
                -- Check if we have CIFP leg detail
                IF @sid_has_legs = 1 AND EXISTS (SELECT 1 FROM dbo.nav_procedure_legs WHERE procedure_id = @sid_proc_id)
                BEGIN
                    IF @debug = 1 PRINT '  -> Expanding SID with CIFP legs: ' + @t_token;

                    -- Use leg-level expansion with constraints
                    INSERT INTO @waypoints (fix_name, lat, lon, fix_type, source, on_dp, original_token,
                                           leg_type, alt_restriction, altitude_1_ft, altitude_2_ft, speed_limit_kts)
                    SELECT pl.fix_name, nf.lat, nf.lon, ISNULL(nf.fix_type, 'WAYPOINT'), 'SID', @t_token, @t_token,
                           pl.leg_type, pl.alt_restriction, pl.altitude_1_ft, pl.altitude_2_ft, pl.speed_limit_kts
                    FROM dbo.nav_procedure_legs pl
                    LEFT JOIN #unique_fixes nf ON nf.fix_name = pl.fix_name
                    WHERE pl.procedure_id = @sid_proc_id
                      AND pl.fix_name IS NOT NULL  -- Skip VA/VM/CA legs
                    ORDER BY pl.sequence_num;
                END
                ELSE IF @sid_route IS NOT NULL
                BEGIN
                    IF @debug = 1 PRINT '  -> Expanding SID (text route): ' + @sid_route;

                    -- Fall back to STRING_SPLIT expansion
                    DECLARE @sid_fixes TABLE (pos INT IDENTITY(1,1), fix NVARCHAR(50));
                    INSERT INTO @sid_fixes (fix)
                    SELECT value FROM STRING_SPLIT(@sid_route, ' ')
                    WHERE LEN(LTRIM(RTRIM(value))) > 0 AND value NOT LIKE '%/%';

                    INSERT INTO @waypoints (fix_name, lat, lon, fix_type, source, on_dp, original_token)
                    SELECT sf.fix, nf.lat, nf.lon, ISNULL(nf.fix_type, 'WAYPOINT'), 'SID', @t_token, @t_token
                    FROM @sid_fixes sf
                    LEFT JOIN #unique_fixes nf ON nf.fix_name = sf.fix
                    WHERE sf.fix NOT LIKE '[A-Z][A-Z][A-Z]/%'
                    ORDER BY sf.pos;
                END

                -- Update prev_fix to last SID fix
                SELECT TOP 1 @prev_fix = fix_name, @prev_fix_lat = lat, @prev_fix_lon = lon
                FROM @waypoints WHERE source = 'SID' ORDER BY seq DESC;
            END
            ELSE IF @debug = 1
                PRINT '  -> SID not found in nav_procedures';

            SET @pending_airway = NULL;
        END
        
        -- ====================================================================
        -- STAR: Expand from nav_procedures (with CIFP leg support)
        -- ====================================================================
        ELSE IF @t_type = 'STAR'
        BEGIN
            DECLARE @star_route NVARCHAR(MAX);
            DECLARE @star_proc_id INT = NULL;
            DECLARE @star_has_legs BIT = 0;

            -- Try exact match first
            SELECT TOP 1 @star_route = full_route, @star_proc_id = procedure_id, @star_has_legs = ISNULL(has_leg_detail, 0)
            FROM dbo.nav_procedures
            WHERE computer_code = @t_token AND procedure_type = 'STAR';

            -- Try without transition if not found
            IF @star_route IS NULL AND CHARINDEX('.', @t_token) > 0
            BEGIN
                DECLARE @star_base NVARCHAR(50) = SUBSTRING(@t_token, CHARINDEX('.', @t_token) + 1, 100);
                SELECT TOP 1 @star_route = full_route, @star_proc_id = procedure_id, @star_has_legs = ISNULL(has_leg_detail, 0)
                FROM dbo.nav_procedures
                WHERE computer_code LIKE '%.' + @star_base AND procedure_type = 'STAR';
            END

            IF @star_proc_id IS NOT NULL
            BEGIN
                -- Set STAR metadata
                SET @star_name = @t_token;
                SET @afix = @last_fix_before_star;
                SET @strsn = @last_fix_before_star;

                -- Check if we have CIFP leg detail
                IF @star_has_legs = 1 AND EXISTS (SELECT 1 FROM dbo.nav_procedure_legs WHERE procedure_id = @star_proc_id)
                BEGIN
                    IF @debug = 1 PRINT '  -> Expanding STAR with CIFP legs: ' + @t_token;

                    -- Use leg-level expansion with constraints
                    INSERT INTO @waypoints (fix_name, lat, lon, fix_type, source, on_star, original_token,
                                           leg_type, alt_restriction, altitude_1_ft, altitude_2_ft, speed_limit_kts)
                    SELECT pl.fix_name, nf.lat, nf.lon, ISNULL(nf.fix_type, 'WAYPOINT'), 'STAR', @t_token, @t_token,
                           pl.leg_type, pl.alt_restriction, pl.altitude_1_ft, pl.altitude_2_ft, pl.speed_limit_kts
                    FROM dbo.nav_procedure_legs pl
                    LEFT JOIN #unique_fixes nf ON nf.fix_name = pl.fix_name
                    WHERE pl.procedure_id = @star_proc_id
                      AND pl.fix_name IS NOT NULL  -- Skip VA/VM/CA legs
                    ORDER BY pl.sequence_num;
                END
                ELSE IF @star_route IS NOT NULL
                BEGIN
                    IF @debug = 1 PRINT '  -> Expanding STAR (text route): ' + @star_route;

                    -- Fall back to STRING_SPLIT expansion
                    DECLARE @star_fixes TABLE (pos INT IDENTITY(1,1), fix NVARCHAR(50));
                    INSERT INTO @star_fixes (fix)
                    SELECT value FROM STRING_SPLIT(@star_route, ' ')
                    WHERE LEN(LTRIM(RTRIM(value))) > 0 AND value NOT LIKE '%/%';

                    INSERT INTO @waypoints (fix_name, lat, lon, fix_type, source, on_star, original_token)
                    SELECT sf.fix, nf.lat, nf.lon, ISNULL(nf.fix_type, 'WAYPOINT'), 'STAR', @t_token, @t_token
                    FROM @star_fixes sf
                    LEFT JOIN #unique_fixes nf ON nf.fix_name = sf.fix
                    WHERE sf.fix NOT LIKE '[A-Z][A-Z][A-Z]/%'
                    ORDER BY sf.pos;
                END

                SELECT TOP 1 @prev_fix = fix_name, @prev_fix_lat = lat, @prev_fix_lon = lon
                FROM @waypoints WHERE source = 'STAR' ORDER BY seq DESC;
            END
            ELSE IF @debug = 1
                PRINT '  -> STAR not found in nav_procedures';

            SET @pending_airway = NULL;
        END
        
        -- ====================================================================
        -- SID_OR_STAR: Determine based on lookup and position
        -- ====================================================================
        ELSE IF @t_type = 'SID_OR_STAR'
        BEGIN
            DECLARE @proc_route NVARCHAR(MAX) = NULL;
            DECLARE @proc_type NVARCHAR(10) = NULL;
            DECLARE @proc_code NVARCHAR(50) = NULL;
            DECLARE @proc_id INT = NULL;
            DECLARE @proc_has_legs BIT = 0;
            
            -- Database stores procedures as PROCEDURE.TRANSITION
            -- Try multiple lookup patterns
            
            -- 1. Exact match on computer_code
            SELECT TOP 1 @proc_route = full_route, @proc_type = procedure_type, @proc_code = computer_code,
                   @proc_id = procedure_id, @proc_has_legs = ISNULL(has_leg_detail, 0)
            FROM dbo.nav_procedures
            WHERE computer_code = @t_token;
            
            -- 2. Try as DP: token.% pattern (SKORR5 -> SKORR5.%)
            -- Prefer transition that matches the next fix after this DP
            IF @proc_route IS NULL
            BEGIN
                -- Look ahead to find the next FIX token after this DP
                SET @next_fix_after_dp = NULL;
                SELECT TOP 1 @next_fix_after_dp = token
                FROM @tokens
                WHERE seq > @t_seq
                  AND token_type = 'FIX'
                ORDER BY seq;

                -- First try to find a DP whose transition matches the next fix
                IF @next_fix_after_dp IS NOT NULL
                BEGIN
                    SELECT TOP 1 @proc_route = full_route, @proc_type = 'DP', @proc_code = computer_code,
                           @proc_id = procedure_id, @proc_has_legs = ISNULL(has_leg_detail, 0)
                    FROM dbo.nav_procedures
                    WHERE computer_code = @t_token + '.' + @next_fix_after_dp
                      AND procedure_type = 'DP';

                    IF @debug = 1 AND @proc_route IS NOT NULL
                        PRINT '  -> Found DP with matching transition: ' + @next_fix_after_dp;
                END

                -- If no context match, fall back to first matching DP
                IF @proc_route IS NULL
                BEGIN
                    SELECT TOP 1 @proc_route = full_route, @proc_type = 'DP', @proc_code = computer_code,
                           @proc_id = procedure_id, @proc_has_legs = ISNULL(has_leg_detail, 0)
                    FROM dbo.nav_procedures
                    WHERE computer_code LIKE @t_token + '.%' AND procedure_type = 'DP';
                END
            END
            
            -- 3. Try as STAR: %.token pattern (ANJLL4 -> %.ANJLL4)
            -- Prefer transition that matches the last fix before this STAR
            IF @proc_route IS NULL
            BEGIN
                -- First try to find a STAR whose transition matches the last fix
                IF @last_fix_before_star IS NOT NULL
                BEGIN
                    SELECT TOP 1 @proc_route = full_route, @proc_type = 'STAR', @proc_code = computer_code,
                           @proc_id = procedure_id, @proc_has_legs = ISNULL(has_leg_detail, 0)
                    FROM dbo.nav_procedures
                    WHERE computer_code = @last_fix_before_star + '.' + @t_token
                      AND procedure_type = 'STAR';

                    IF @debug = 1 AND @proc_route IS NOT NULL
                        PRINT '  -> Found STAR with matching transition: ' + @last_fix_before_star;
                END

                -- If no context match, fall back to first matching STAR
                IF @proc_route IS NULL
                BEGIN
                    SELECT TOP 1 @proc_route = full_route, @proc_type = 'STAR', @proc_code = computer_code,
                           @proc_id = procedure_id, @proc_has_legs = ISNULL(has_leg_detail, 0)
                    FROM dbo.nav_procedures
                    WHERE computer_code LIKE '%.' + @t_token AND procedure_type = 'STAR';
                END
            END
            
            -- 4. Try stripping trailing digit/letter and search again for DPs
            IF @proc_route IS NULL AND LEN(@t_token) >= 4
            BEGIN
                DECLARE @base_name NVARCHAR(50) = LEFT(@t_token, LEN(@t_token) - 1);
                -- Could be SKORR5 -> SKORR, try SKORR%.%
                SELECT TOP 1 @proc_route = full_route, @proc_type = 'DP', @proc_code = computer_code,
                       @proc_id = procedure_id, @proc_has_legs = ISNULL(has_leg_detail, 0)
                FROM dbo.nav_procedures
                WHERE computer_code LIKE @t_token + '.%' AND procedure_type = 'DP';

                IF @proc_route IS NULL
                BEGIN
                    -- Try %.BASE for STARs (ANJLL4 -> %.ANJLL% but with the 4)
                    SELECT TOP 1 @proc_route = full_route, @proc_type = 'STAR', @proc_code = computer_code,
                           @proc_id = procedure_id, @proc_has_legs = ISNULL(has_leg_detail, 0)
                    FROM dbo.nav_procedures
                    WHERE computer_code LIKE @base_name + '.%' AND procedure_type = 'STAR';
                END
            END
            
            IF @proc_route IS NOT NULL
            BEGIN
                IF @debug = 1 PRINT '  -> Found procedure: ' + @proc_code + ' [' + @proc_type + ']';
                
                IF @proc_type = 'DP'
                BEGIN
                    -- Set DP metadata - DFIX will be set when we hit the next FIX token
                    SET @dp_name = @t_token;
                    SET @found_sid = 1;

                    -- Check if we have CIFP leg detail
                    IF @proc_has_legs = 1 AND @proc_id IS NOT NULL
                       AND EXISTS (SELECT 1 FROM dbo.nav_procedure_legs WHERE procedure_id = @proc_id)
                    BEGIN
                        IF @debug = 1 PRINT '  -> Expanding DP with CIFP legs: ' + @t_token;

                        -- Use leg-level expansion with constraints
                        INSERT INTO @waypoints (fix_name, lat, lon, fix_type, source, on_dp, original_token,
                                               leg_type, alt_restriction, altitude_1_ft, altitude_2_ft, speed_limit_kts)
                        SELECT pl.fix_name, nf.lat, nf.lon, ISNULL(nf.fix_type, 'WAYPOINT'), 'SID', @t_token, @t_token,
                               pl.leg_type, pl.alt_restriction, pl.altitude_1_ft, pl.altitude_2_ft, pl.speed_limit_kts
                        FROM dbo.nav_procedure_legs pl
                        LEFT JOIN #unique_fixes nf ON nf.fix_name = pl.fix_name
                        WHERE pl.procedure_id = @proc_id
                          AND pl.fix_name IS NOT NULL  -- Skip VA/VM/CA legs
                        ORDER BY pl.sequence_num;
                    END
                    ELSE IF @proc_route IS NOT NULL
                    BEGIN
                        IF @debug = 1 PRINT '  -> Expanding DP (text route): ' + @proc_route;

                        -- Fall back to STRING_SPLIT expansion
                        DECLARE @dp_fixes TABLE (pos INT IDENTITY(1,1), fix NVARCHAR(50));
                        INSERT INTO @dp_fixes (fix)
                        SELECT value FROM STRING_SPLIT(@proc_route, ' ')
                        WHERE LEN(LTRIM(RTRIM(value))) > 0 AND value NOT LIKE '%/%';

                        INSERT INTO @waypoints (fix_name, lat, lon, fix_type, source, on_dp, original_token)
                        SELECT df.fix, nf.lat, nf.lon, ISNULL(nf.fix_type, 'WAYPOINT'), 'SID', @t_token, @t_token
                        FROM @dp_fixes df
                        LEFT JOIN #unique_fixes nf ON nf.fix_name = df.fix
                        WHERE df.fix NOT LIKE '[A-Z][A-Z][A-Z]/%'
                        ORDER BY df.pos;
                    END

                    -- Track last fix for airway expansion
                    SELECT TOP 1 @prev_fix = fix_name, @prev_fix_lat = lat, @prev_fix_lon = lon
                    FROM @waypoints WHERE source = 'SID' ORDER BY seq DESC;
                END
                ELSE -- STAR
                BEGIN
                    -- Set STAR metadata
                    SET @star_name = @t_token;
                    SET @afix = @last_fix_before_star;
                    SET @strsn = @last_fix_before_star;

                    -- Check if we have CIFP leg detail
                    IF @proc_has_legs = 1 AND @proc_id IS NOT NULL
                       AND EXISTS (SELECT 1 FROM dbo.nav_procedure_legs WHERE procedure_id = @proc_id)
                    BEGIN
                        IF @debug = 1 PRINT '  -> Expanding STAR with CIFP legs: ' + @t_token;

                        -- Use leg-level expansion with constraints
                        INSERT INTO @waypoints (fix_name, lat, lon, fix_type, source, on_star, original_token,
                                               leg_type, alt_restriction, altitude_1_ft, altitude_2_ft, speed_limit_kts)
                        SELECT pl.fix_name, nf.lat, nf.lon, ISNULL(nf.fix_type, 'WAYPOINT'), 'STAR', @t_token, @t_token,
                               pl.leg_type, pl.alt_restriction, pl.altitude_1_ft, pl.altitude_2_ft, pl.speed_limit_kts
                        FROM dbo.nav_procedure_legs pl
                        LEFT JOIN #unique_fixes nf ON nf.fix_name = pl.fix_name
                        WHERE pl.procedure_id = @proc_id
                          AND pl.fix_name IS NOT NULL  -- Skip VA/VM/CA legs
                        ORDER BY pl.sequence_num;
                    END
                    ELSE IF @proc_route IS NOT NULL
                    BEGIN
                        IF @debug = 1 PRINT '  -> Expanding STAR (text route): ' + @proc_route;

                        -- Fall back to STRING_SPLIT expansion
                        DECLARE @star_fixes2 TABLE (pos INT IDENTITY(1,1), fix NVARCHAR(50));
                        INSERT INTO @star_fixes2 (fix)
                        SELECT value FROM STRING_SPLIT(@proc_route, ' ')
                        WHERE LEN(LTRIM(RTRIM(value))) > 0 AND value NOT LIKE '%/%';

                        INSERT INTO @waypoints (fix_name, lat, lon, fix_type, source, on_star, original_token)
                        SELECT sf.fix, nf.lat, nf.lon, ISNULL(nf.fix_type, 'WAYPOINT'), 'STAR', @t_token, @t_token
                        FROM @star_fixes2 sf
                        LEFT JOIN #unique_fixes nf ON nf.fix_name = sf.fix
                        WHERE sf.fix NOT LIKE '[A-Z][A-Z][A-Z]/%'
                        ORDER BY sf.pos;
                    END

                    SELECT TOP 1 @prev_fix = fix_name, @prev_fix_lat = lat, @prev_fix_lon = lon
                    FROM @waypoints WHERE source = 'STAR' ORDER BY seq DESC;
                END
            END
            ELSE
            BEGIN
                -- Not found in procedures - treat as a FIX
                IF @debug = 1 PRINT '  -> Not found in procedures, treating as FIX';
                
                DECLARE @sos_lat FLOAT, @sos_lon FLOAT, @sos_type NVARCHAR(20);
                SELECT TOP 1 @sos_lat = lat, @sos_lon = lon, @sos_type = fix_type
                FROM dbo.nav_fixes WHERE fix_name = @t_token ORDER BY fix_id;
                
                IF @sos_lat IS NOT NULL
                BEGIN
                    INSERT INTO @waypoints (fix_name, lat, lon, fix_type, source, original_token)
                    VALUES (@t_token, @sos_lat, @sos_lon, ISNULL(@sos_type, 'WAYPOINT'), 'ROUTE', @t_token);
                    
                    SET @prev_fix = @t_token;
                    SET @prev_fix_lat = @sos_lat;
                    SET @prev_fix_lon = @sos_lon;
                    SET @last_fix_before_star = @t_token;
                END
            END
            
            SET @pending_airway = NULL;
        END
        
        FETCH NEXT FROM route_cursor INTO @t_seq, @t_token, @t_type;
    END
    
    CLOSE route_cursor;
    DEALLOCATE route_cursor;
    
    -- Add destination airport last
    IF @dest_icao IS NOT NULL
    BEGIN
        INSERT INTO @waypoints (fix_name, lat, lon, fix_type, source, original_token)
        SELECT TOP 1 @dest_icao, lat, lon, 'AIRPORT', 'DESTINATION', @dest_icao
        FROM dbo.nav_fixes
        WHERE fix_name = @dest_icao OR fix_name = 'K' + @dest_icao OR fix_name = SUBSTRING(@dest_icao, 2, 3)
        ORDER BY CASE WHEN fix_name = @dest_icao THEN 1 ELSE 2 END, fix_id;
    END
    
    IF @debug = 1
    BEGIN
        PRINT '';
        PRINT 'Waypoints before dedup:';
        SELECT seq, fix_name, lat, lon, fix_type, source, on_airway, on_dp, on_star FROM @waypoints ORDER BY seq;
    END
    
    -- ========================================================================
    -- Step 3: Remove consecutive duplicates
    -- ========================================================================
    ;WITH numbered AS (
        SELECT seq, fix_name,
               LAG(fix_name) OVER (ORDER BY seq) AS prev_fix
        FROM @waypoints
    )
    DELETE FROM @waypoints
    WHERE seq IN (SELECT seq FROM numbered WHERE fix_name = prev_fix AND fix_name IS NOT NULL);
    
    -- ========================================================================
    -- Step 4: Build expanded route string
    -- ========================================================================
    DECLARE @expanded_route NVARCHAR(MAX) = '';
    SELECT @expanded_route = @expanded_route + fix_name + ' '
    FROM @waypoints 
    WHERE fix_name IS NOT NULL
    ORDER BY seq;
    SET @expanded_route = LTRIM(RTRIM(@expanded_route));
    
    -- ========================================================================
    -- Step 5: Build GEOGRAPHY LineString
    -- ========================================================================
    DECLARE @linestring NVARCHAR(MAX) = 'LINESTRING(';
    DECLARE @point_count INT = 0;
    DECLARE @wp_lat FLOAT, @wp_lon FLOAT;
    
    DECLARE geo_cursor CURSOR LOCAL FAST_FORWARD FOR
        SELECT lat, lon FROM @waypoints 
        WHERE lat IS NOT NULL AND lon IS NOT NULL 
        ORDER BY seq;
    
    OPEN geo_cursor;
    FETCH NEXT FROM geo_cursor INTO @wp_lat, @wp_lon;
    
    WHILE @@FETCH_STATUS = 0
    BEGIN
        IF @point_count > 0
            SET @linestring = @linestring + ', ';
        
        -- Geography uses lon lat order
        SET @linestring = @linestring + 
            CAST(ROUND(@wp_lon, 6) AS VARCHAR(20)) + ' ' + 
            CAST(ROUND(@wp_lat, 6) AS VARCHAR(20));
        SET @point_count = @point_count + 1;
        
        FETCH NEXT FROM geo_cursor INTO @wp_lat, @wp_lon;
    END
    
    CLOSE geo_cursor;
    DEALLOCATE geo_cursor;
    
    SET @linestring = @linestring + ')';
    
    -- Need at least 2 points for a valid LineString
    DECLARE @route_geo GEOGRAPHY = NULL;
    IF @point_count >= 2
    BEGIN
        BEGIN TRY
            SET @route_geo = geography::STGeomFromText(@linestring, 4326);
        END TRY
        BEGIN CATCH
            IF @debug = 1 PRINT 'Error creating LineString: ' + ERROR_MESSAGE();
        END CATCH
    END
    
    IF @debug = 1
    BEGIN
        PRINT '';
        PRINT 'LineString (' + CAST(@point_count AS VARCHAR) + ' points): ';
        IF LEN(@linestring) > 500
            PRINT LEFT(@linestring, 500) + '...';
        ELSE
            PRINT @linestring;
    END
    
    -- ========================================================================
    -- Step 6: Update adl_flight_plan with route_geometry and metadata
    -- ========================================================================
    DECLARE @parse_status NVARCHAR(20) = 'COMPLETE';
    IF @point_count < 2 SET @parse_status = 'PARTIAL';
    IF @point_count = 0 SET @parse_status = 'FAILED';
    
    UPDATE dbo.adl_flight_plan
    SET route_geometry = @route_geo,
        fp_route_expanded = @expanded_route,
        parse_status = @parse_status,
        parse_utc = SYSUTCDATETIME(),
        -- Metadata
        dp_name = COALESCE(@dp_name, dp_name),
        dfix = COALESCE(@dfix, dfix),
        dtrsn = COALESCE(@dtrsn, dtrsn),
        star_name = COALESCE(@star_name, star_name),
        afix = COALESCE(@afix, afix),
        strsn = COALESCE(@strsn, strsn),
        waypoint_count = @point_count
    WHERE flight_uid = @flight_uid;
    
    -- ========================================================================
    -- Step 7: Populate adl_flight_waypoints
    -- ========================================================================
    DELETE FROM dbo.adl_flight_waypoints WHERE flight_uid = @flight_uid;

    INSERT INTO dbo.adl_flight_waypoints (
        flight_uid, sequence_num, fix_name, lat, lon, position_geo,
        fix_type, source, on_airway, on_dp, on_star,
        leg_type, alt_restriction, altitude_1_ft, altitude_2_ft, speed_limit_kts
    )
    SELECT
        @flight_uid,
        ROW_NUMBER() OVER (ORDER BY seq),
        fix_name,
        lat,
        lon,
        CASE WHEN lat IS NOT NULL AND lon IS NOT NULL
             THEN geography::Point(lat, lon, 4326)
             ELSE NULL END,
        fix_type,
        source,
        on_airway,
        on_dp,
        on_star,
        leg_type,
        alt_restriction,
        altitude_1_ft,
        altitude_2_ft,
        speed_limit_kts
    FROM @waypoints
    ORDER BY seq;
    
    -- ========================================================================
    -- Done
    -- ========================================================================
    IF @debug = 1
    BEGIN
        DECLARE @elapsed_ms INT = DATEDIFF(MILLISECOND, @parse_start, SYSUTCDATETIME());
        DECLARE @wp_count INT;
        SELECT @wp_count = COUNT(*) FROM dbo.adl_flight_waypoints WHERE flight_uid = @flight_uid;
        PRINT '';
        PRINT '================================================';
        PRINT 'Parse complete. Status: ' + @parse_status;
        PRINT 'Elapsed: ' + CAST(@elapsed_ms AS VARCHAR) + 'ms';
        PRINT 'Waypoints: ' + CAST(@wp_count AS VARCHAR);
        PRINT '';
        PRINT 'Metadata:';
        PRINT '  DP Name:    ' + ISNULL(@dp_name, '(none)');
        PRINT '  DFIX:       ' + ISNULL(@dfix, '(none)');
        PRINT '  DP Trans:   ' + ISNULL(@dtrsn, '(none)');
        PRINT '  STAR Name:  ' + ISNULL(@star_name, '(none)');
        PRINT '  AFIX:       ' + ISNULL(@afix, '(none)');
        PRINT '  STAR Trans: ' + ISNULL(@strsn, '(none)');
        PRINT '================================================';
        
        SELECT sequence_num, fix_name, ROUND(lat, 4) as lat, ROUND(lon, 4) as lon, fix_type, source, on_airway, on_dp, on_star
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
    
    DECLARE @processed INT = 0;
    DECLARE @errors INT = 0;
    DECLARE @flight_uid BIGINT;
    
    -- Get batch of flights needing parsing
    DECLARE @batch TABLE (flight_uid BIGINT);
    
    INSERT INTO @batch (flight_uid)
    SELECT TOP (@batch_size) pq.flight_uid
    FROM dbo.adl_parse_queue pq
    WHERE pq.status = 'PENDING'
      AND pq.next_eligible_utc <= SYSUTCDATETIME()
      AND (@tier IS NULL OR pq.parse_tier = @tier)
    ORDER BY pq.parse_tier, pq.queued_utc;
    
    -- Mark as processing
    UPDATE pq
    SET status = 'PROCESSING',
        attempts = attempts + 1
    FROM dbo.adl_parse_queue pq
    INNER JOIN @batch b ON pq.flight_uid = b.flight_uid;
    
    -- Process each flight
    DECLARE batch_cursor CURSOR LOCAL FAST_FORWARD FOR
        SELECT flight_uid FROM @batch;
    
    OPEN batch_cursor;
    FETCH NEXT FROM batch_cursor INTO @flight_uid;
    
    WHILE @@FETCH_STATUS = 0
    BEGIN
        BEGIN TRY
            EXEC dbo.sp_ParseRoute @flight_uid = @flight_uid, @debug = 0;
            
            -- Mark complete
            UPDATE dbo.adl_parse_queue
            SET status = 'COMPLETE',
                completed_utc = SYSUTCDATETIME()
            WHERE flight_uid = @flight_uid;
            
            SET @processed = @processed + 1;
        END TRY
        BEGIN CATCH
            -- Mark failed with error
            UPDATE dbo.adl_parse_queue
            SET status = 'FAILED',
                error_message = LEFT(ERROR_MESSAGE(), 500),
                next_eligible_utc = DATEADD(MINUTE, 5, SYSUTCDATETIME())
            WHERE flight_uid = @flight_uid;
            
            SET @errors = @errors + 1;
        END CATCH
        
        FETCH NEXT FROM batch_cursor INTO @flight_uid;
    END
    
    CLOSE batch_cursor;
    DEALLOCATE batch_cursor;
    
    -- Cleanup old completed entries (older than 1 hour)
    DELETE FROM dbo.adl_parse_queue 
    WHERE status = 'COMPLETE' 
      AND completed_utc < DATEADD(HOUR, -1, SYSUTCDATETIME());
    
    SELECT @processed AS processed, @errors AS errors;
END;
GO

PRINT 'Route parsing procedures created successfully.';
PRINT '';
PRINT 'Usage:';
PRINT '  -- Parse single flight with debug output:';
PRINT '  EXEC sp_ParseRoute @flight_uid = 12345, @debug = 1;';
PRINT '';
PRINT '  -- Batch process Tier 0 flights:';
PRINT '  EXEC sp_ParseRouteBatch @batch_size = 50, @tier = 0;';
PRINT '';
PRINT '  -- Batch process all eligible tiers:';
PRINT '  EXEC sp_ParseRouteBatch @batch_size = 100;';
GO
