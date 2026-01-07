-- ============================================================================
-- sp_ParseSimBriefData.sql (v1.0)
-- SimBrief & ICAO Flight Plan Data Extraction
--
-- Parses VATSIM flight plan remarks and route strings to extract:
--   1. SimBrief identification (is_simbrief flag)
--   2. ICAO Item 18 indicators (DOF, REG, OPR, PBN, etc.)
--   3. Step climb waypoints with altitude/speed changes
--   4. Runway hints from SID/STAR names
--   5. Cost Index (if present)
--
-- Data Sources:
--   - Remarks field (ICAO Item 18)
--   - Route string (speed/altitude changes at waypoints)
--
-- Usage:
--   EXEC sp_ParseSimBriefData @flight_uid = 12345, @debug = 1;
--   EXEC sp_ParseSimBriefDataBatch @batch_size = 100;
-- ============================================================================

SET ANSI_NULLS ON;
GO
SET QUOTED_IDENTIFIER ON;
GO

-- ============================================================================
-- Helper Function: Parse ICAO Item 18 Indicators from Remarks
-- Returns table of indicator/value pairs
-- ============================================================================
IF OBJECT_ID('dbo.fn_ParseICAORemarks', 'TF') IS NOT NULL
    DROP FUNCTION dbo.fn_ParseICAORemarks;
GO

CREATE FUNCTION dbo.fn_ParseICAORemarks (
    @remarks NVARCHAR(MAX)
)
RETURNS @result TABLE (
    indicator NVARCHAR(16),
    value NVARCHAR(256)
)
AS
BEGIN
    IF @remarks IS NULL OR LEN(LTRIM(RTRIM(@remarks))) = 0
        RETURN;
    
    DECLARE @clean NVARCHAR(MAX) = UPPER(LTRIM(RTRIM(@remarks)));
    
    -- Common ICAO Item 18 indicators to extract
    -- Format: INDICATOR/VALUE (space or end of string terminates value)
    -- Some indicators: DOF, REG, OPR, PER, PBN, NAV, COM, DAT, SUR, DEP, DEST, 
    --                  ALTN, RALT, TALT, EET, SEL, TYP, CODE, DLE, RVR, RMK, STS
    
    DECLARE @indicators TABLE (ind NVARCHAR(16));
    INSERT INTO @indicators VALUES 
        ('DOF'), ('REG'), ('OPR'), ('PER'), ('PBN'), ('NAV'), ('COM'), ('DAT'), 
        ('SUR'), ('DEP'), ('DEST'), ('ALTN'), ('RALT'), ('TALT'), ('EET'), 
        ('SEL'), ('TYP'), ('CODE'), ('DLE'), ('RVR'), ('RMK'), ('STS'),
        ('OPR'), ('ORGN'), ('RIF'), ('APTS');
    
    DECLARE @ind NVARCHAR(16);
    DECLARE @pos INT;
    DECLARE @end_pos INT;
    DECLARE @val NVARCHAR(256);
    
    DECLARE ind_cursor CURSOR LOCAL FAST_FORWARD FOR
        SELECT ind FROM @indicators;
    
    OPEN ind_cursor;
    FETCH NEXT FROM ind_cursor INTO @ind;
    
    WHILE @@FETCH_STATUS = 0
    BEGIN
        SET @pos = CHARINDEX(@ind + '/', @clean);
        
        IF @pos > 0
        BEGIN
            -- Find end of value (next space or end of string)
            SET @end_pos = CHARINDEX(' ', @clean, @pos + LEN(@ind) + 1);
            IF @end_pos = 0 SET @end_pos = LEN(@clean) + 1;
            
            SET @val = SUBSTRING(@clean, @pos + LEN(@ind) + 1, @end_pos - @pos - LEN(@ind) - 1);
            
            -- Clean up value
            SET @val = LTRIM(RTRIM(@val));
            
            IF LEN(@val) > 0
                INSERT INTO @result (indicator, value) VALUES (@ind, @val);
        END
        
        FETCH NEXT FROM ind_cursor INTO @ind;
    END
    
    CLOSE ind_cursor;
    DEALLOCATE ind_cursor;
    
    RETURN;
END;
GO

-- ============================================================================
-- Helper Function: Parse Step Climbs from Route String
-- Returns table of waypoint/altitude/speed changes
-- Format: WAYPOINT/N0460F360 or WAYPOINT/M082F390
-- ============================================================================
IF OBJECT_ID('dbo.fn_ParseRouteStepClimbs', 'TF') IS NOT NULL
    DROP FUNCTION dbo.fn_ParseRouteStepClimbs;
GO

CREATE FUNCTION dbo.fn_ParseRouteStepClimbs (
    @route NVARCHAR(MAX)
)
RETURNS @result TABLE (
    sequence_num INT,
    waypoint_fix NVARCHAR(64),
    altitude_ft INT,
    flight_level INT,
    speed_kts INT,
    speed_mach DECIMAL(4,3),
    speed_type NVARCHAR(8),
    raw_text NVARCHAR(128)
)
AS
BEGIN
    IF @route IS NULL OR LEN(LTRIM(RTRIM(@route))) = 0
        RETURN;
    
    DECLARE @clean NVARCHAR(MAX) = UPPER(LTRIM(RTRIM(@route)));
    
    -- Normalize separators
    SET @clean = REPLACE(@clean, '+', ' ');
    
    -- Split into tokens
    DECLARE @tokens TABLE (seq INT IDENTITY(1,1), token NVARCHAR(128));
    INSERT INTO @tokens (token)
    SELECT value FROM STRING_SPLIT(@clean, ' ')
    WHERE LEN(LTRIM(RTRIM(value))) > 0;
    
    -- Find tokens with speed/altitude specs (contain /)
    -- Pattern: WAYPOINT/N0460F360 or WAYPOINT/M082F390 or WAYPOINT/F350
    DECLARE @seq INT;
    DECLARE @token NVARCHAR(128);
    DECLARE @slash_pos INT;
    DECLARE @waypoint NVARCHAR(64);
    DECLARE @spec NVARCHAR(64);
    DECLARE @step_seq INT = 0;
    
    -- Speed/Alt parsing vars
    DECLARE @alt_ft INT;
    DECLARE @fl INT;
    DECLARE @spd_kts INT;
    DECLARE @spd_mach DECIMAL(4,3);
    DECLARE @spd_type NVARCHAR(8);
    
    DECLARE tok_cursor CURSOR LOCAL FAST_FORWARD FOR
        SELECT seq, token FROM @tokens WHERE token LIKE '%/%' ORDER BY seq;
    
    OPEN tok_cursor;
    FETCH NEXT FROM tok_cursor INTO @seq, @token;
    
    WHILE @@FETCH_STATUS = 0
    BEGIN
        SET @slash_pos = CHARINDEX('/', @token);
        SET @waypoint = LEFT(@token, @slash_pos - 1);
        SET @spec = SUBSTRING(@token, @slash_pos + 1, LEN(@token));
        
        -- Skip if waypoint looks like an indicator (DOF, REG, etc.)
        IF @waypoint NOT IN ('DOF', 'REG', 'OPR', 'PBN', 'NAV', 'EET', 'RMK', 'STS', 'SEL', 'CODE', 'PER', 'N', 'K', 'M', 'A', 'F', 'S')
           AND LEN(@waypoint) >= 2 AND LEN(@waypoint) <= 12
           AND @waypoint NOT LIKE '[0-9]%'  -- Skip pure numeric
        BEGIN
            -- Reset
            SET @alt_ft = NULL;
            SET @fl = NULL;
            SET @spd_kts = NULL;
            SET @spd_mach = NULL;
            SET @spd_type = NULL;
            
            -- Parse speed/altitude spec
            -- N0460F360 = TAS 460kts, FL360
            -- M082F390 = Mach 0.82, FL390
            -- K0850S1200 = 850 km/h, 1200m (rare)
            -- F350 = FL350 only
            -- A050 = Altitude 5000ft
            
            -- TAS in knots: N0nnn
            IF @spec LIKE 'N0[0-9][0-9][0-9]%'
            BEGIN
                SET @spd_kts = TRY_CAST(SUBSTRING(@spec, 3, 3) AS INT);
                SET @spd_type = 'TAS';
                SET @spec = SUBSTRING(@spec, 6, LEN(@spec));
            END
            -- TAS in km/h: K0nnn (convert to kts)
            ELSE IF @spec LIKE 'K0[0-9][0-9][0-9]%'
            BEGIN
                SET @spd_kts = TRY_CAST(SUBSTRING(@spec, 3, 3) AS INT);
                IF @spd_kts IS NOT NULL SET @spd_kts = CAST(@spd_kts * 0.54 AS INT); -- km/h to kts
                SET @spd_type = 'TAS';
                SET @spec = SUBSTRING(@spec, 6, LEN(@spec));
            END
            -- Mach: M0nn
            ELSE IF @spec LIKE 'M0[0-9][0-9]%'
            BEGIN
                SET @spd_mach = TRY_CAST(SUBSTRING(@spec, 2, 3) AS INT) / 100.0;
                SET @spd_type = 'MACH';
                SET @spec = SUBSTRING(@spec, 5, LEN(@spec));
            END
            
            -- Flight Level: Fnnn
            IF @spec LIKE 'F[0-9][0-9][0-9]%'
            BEGIN
                SET @fl = TRY_CAST(SUBSTRING(@spec, 2, 3) AS INT);
                IF @fl IS NOT NULL SET @alt_ft = @fl * 100;
            END
            -- Altitude in 10m: Snnnn
            ELSE IF @spec LIKE 'S[0-9][0-9][0-9][0-9]%'
            BEGIN
                DECLARE @meters INT = TRY_CAST(SUBSTRING(@spec, 2, 4) AS INT) * 10;
                IF @meters IS NOT NULL SET @alt_ft = CAST(@meters * 3.28084 AS INT);
                SET @fl = @alt_ft / 100;
            END
            -- Altitude in 100ft: Annn
            ELSE IF @spec LIKE 'A[0-9][0-9][0-9]%'
            BEGIN
                SET @alt_ft = TRY_CAST(SUBSTRING(@spec, 2, 3) AS INT) * 100;
                SET @fl = @alt_ft / 100;
            END
            
            -- Only insert if we got altitude data
            IF @alt_ft IS NOT NULL
            BEGIN
                SET @step_seq = @step_seq + 1;
                INSERT INTO @result (sequence_num, waypoint_fix, altitude_ft, flight_level, 
                                    speed_kts, speed_mach, speed_type, raw_text)
                VALUES (@step_seq, @waypoint, @alt_ft, @fl, @spd_kts, @spd_mach, @spd_type, @token);
            END
        END
        
        FETCH NEXT FROM tok_cursor INTO @seq, @token;
    END
    
    CLOSE tok_cursor;
    DEALLOCATE tok_cursor;
    
    RETURN;
END;
GO

-- ============================================================================
-- Helper Function: Detect SimBrief Flight Plan
-- Returns 1 if remarks/route patterns indicate SimBrief generation
-- ============================================================================
IF OBJECT_ID('dbo.fn_IsSimBriefFlight', 'FN') IS NOT NULL
    DROP FUNCTION dbo.fn_IsSimBriefFlight;
GO

CREATE FUNCTION dbo.fn_IsSimBriefFlight (
    @remarks NVARCHAR(MAX),
    @route NVARCHAR(MAX)
)
RETURNS BIT
AS
BEGIN
    DECLARE @upper_remarks NVARCHAR(MAX) = UPPER(ISNULL(@remarks, ''));
    
    -- Explicit SimBrief markers
    IF @upper_remarks LIKE '%SIMBRIEF%' RETURN 1;
    IF @upper_remarks LIKE '%SB/%' RETURN 1;  -- Some use SB/ shorthand
    
    -- SimBrief typically includes these together (strong indicator)
    IF @upper_remarks LIKE '%PBN/%' AND @upper_remarks LIKE '%DOF/%' AND @upper_remarks LIKE '%RMK/%'
        RETURN 1;
    
    -- SimBrief always includes TCAS in RMK
    IF @upper_remarks LIKE '%RMK/TCAS%' RETURN 1;
    
    -- Check for detailed Item 18 pattern that's typical of SimBrief
    IF @upper_remarks LIKE '%PBN/A1B1%' AND @upper_remarks LIKE '%NAV/%' RETURN 1;
    
    RETURN 0;
END;
GO

-- ============================================================================
-- Helper Function: Extract Runway from SID/STAR Name
-- Pattern: Procedure names often include runway (RNAV departures especially)
-- Examples: RNP28L, RNAV28R, ILS09L
-- ============================================================================
IF OBJECT_ID('dbo.fn_ExtractRunwayFromProcedure', 'FN') IS NOT NULL
    DROP FUNCTION dbo.fn_ExtractRunwayFromProcedure;
GO

CREATE FUNCTION dbo.fn_ExtractRunwayFromProcedure (
    @procedure NVARCHAR(32)
)
RETURNS NVARCHAR(4)
AS
BEGIN
    IF @procedure IS NULL RETURN NULL;
    
    DECLARE @upper NVARCHAR(32) = UPPER(LTRIM(RTRIM(@procedure)));
    DECLARE @runway NVARCHAR(4) = NULL;
    
    -- Pattern: ends with runway number + optional L/C/R
    -- Check for 2-digit runway at end
    IF @upper LIKE '%[0-3][0-9]' AND LEN(@upper) >= 2
    BEGIN
        SET @runway = RIGHT(@upper, 2);
        -- Validate: runways are 01-36
        IF TRY_CAST(@runway AS INT) > 36 SET @runway = NULL;
    END
    -- Check for runway + L/C/R
    ELSE IF @upper LIKE '%[0-3][0-9][LCR]' AND LEN(@upper) >= 3
    BEGIN
        SET @runway = RIGHT(@upper, 3);
        -- Validate
        IF TRY_CAST(LEFT(@runway, 2) AS INT) > 36 SET @runway = NULL;
    END
    -- Pattern: RWnn or RWnnX anywhere
    ELSE IF @upper LIKE '%RW[0-3][0-9]%'
    BEGIN
        DECLARE @rw_pos INT = PATINDEX('%RW[0-3][0-9]%', @upper);
        IF @rw_pos > 0
        BEGIN
            SET @runway = SUBSTRING(@upper, @rw_pos + 2, 2);
            -- Check for L/C/R suffix
            IF LEN(@upper) >= @rw_pos + 4 AND SUBSTRING(@upper, @rw_pos + 4, 1) IN ('L', 'C', 'R')
                SET @runway = @runway + SUBSTRING(@upper, @rw_pos + 4, 1);
        END
    END
    
    RETURN @runway;
END;
GO

-- ============================================================================
-- Main Procedure: sp_ParseSimBriefData
-- ============================================================================
IF OBJECT_ID('dbo.sp_ParseSimBriefData', 'P') IS NOT NULL
    DROP PROCEDURE dbo.sp_ParseSimBriefData;
GO

CREATE PROCEDURE dbo.sp_ParseSimBriefData
    @flight_uid BIGINT,
    @debug BIT = 0
AS
BEGIN
    SET NOCOUNT ON;
    
    DECLARE @route NVARCHAR(MAX);
    DECLARE @remarks NVARCHAR(MAX);
    DECLARE @dept_icao NVARCHAR(10);
    DECLARE @dest_icao NVARCHAR(10);
    DECLARE @dp_name NVARCHAR(16);
    DECLARE @star_name NVARCHAR(16);
    DECLARE @approach NVARCHAR(16);
    
    -- Get flight plan data
    SELECT 
        @route = fp_route,
        @remarks = fp_remarks,
        @dept_icao = fp_dept_icao,
        @dest_icao = fp_dest_icao,
        @dp_name = dp_name,
        @star_name = star_name,
        @approach = approach
    FROM dbo.adl_flight_plan
    WHERE flight_uid = @flight_uid;
    
    IF @route IS NULL AND @remarks IS NULL
    BEGIN
        IF @debug = 1 PRINT 'No route or remarks for flight_uid: ' + CAST(@flight_uid AS VARCHAR);
        RETURN;
    END
    
    IF @debug = 1
    BEGIN
        PRINT '================================================';
        PRINT 'Parsing SimBrief data for flight_uid: ' + CAST(@flight_uid AS VARCHAR);
        PRINT 'Route: ' + LEFT(ISNULL(@route, '(null)'), 200);
        PRINT 'Remarks: ' + LEFT(ISNULL(@remarks, '(null)'), 200);
        PRINT '================================================';
    END
    
    -- ========================================================================
    -- Step 1: Detect SimBrief and parse ICAO Item 18 indicators
    -- ========================================================================
    DECLARE @is_simbrief BIT = dbo.fn_IsSimBriefFlight(@remarks, @route);
    
    -- Parse Item 18 indicators
    DECLARE @indicators TABLE (indicator NVARCHAR(16), value NVARCHAR(256));
    INSERT INTO @indicators SELECT * FROM dbo.fn_ParseICAORemarks(@remarks);
    
    -- Extract specific values
    DECLARE @dof NVARCHAR(16) = NULL;
    DECLARE @reg NVARCHAR(32) = NULL;
    DECLARE @opr NVARCHAR(16) = NULL;
    DECLARE @pbn NVARCHAR(64) = NULL;
    DECLARE @sel NVARCHAR(16) = NULL;
    DECLARE @per NVARCHAR(4) = NULL;
    DECLARE @rmk NVARCHAR(256) = NULL;
    
    SELECT @dof = value FROM @indicators WHERE indicator = 'DOF';
    SELECT @reg = value FROM @indicators WHERE indicator = 'REG';
    SELECT @opr = value FROM @indicators WHERE indicator = 'OPR';
    SELECT @pbn = value FROM @indicators WHERE indicator = 'PBN';
    SELECT @sel = value FROM @indicators WHERE indicator = 'SEL';
    SELECT @per = value FROM @indicators WHERE indicator = 'PER';
    SELECT @rmk = value FROM @indicators WHERE indicator = 'RMK';
    
    IF @debug = 1
    BEGIN
        PRINT '';
        PRINT 'Item 18 Indicators:';
        PRINT '  Is SimBrief: ' + CASE WHEN @is_simbrief = 1 THEN 'YES' ELSE 'NO' END;
        PRINT '  DOF: ' + ISNULL(@dof, '(null)');
        PRINT '  REG: ' + ISNULL(@reg, '(null)');
        PRINT '  OPR: ' + ISNULL(@opr, '(null)');
        PRINT '  PBN: ' + ISNULL(@pbn, '(null)');
        PRINT '  SEL: ' + ISNULL(@sel, '(null)');
        PRINT '  PER: ' + ISNULL(@per, '(null)');
        PRINT '  RMK: ' + ISNULL(@rmk, '(null)');
    END
    
    -- ========================================================================
    -- Step 2: Parse step climbs from route
    -- ========================================================================
    DECLARE @step_climbs TABLE (
        sequence_num INT,
        waypoint_fix NVARCHAR(64),
        altitude_ft INT,
        flight_level INT,
        speed_kts INT,
        speed_mach DECIMAL(4,3),
        speed_type NVARCHAR(8),
        raw_text NVARCHAR(128)
    );
    
    INSERT INTO @step_climbs
    SELECT * FROM dbo.fn_ParseRouteStepClimbs(@route);
    
    DECLARE @step_count INT = (SELECT COUNT(*) FROM @step_climbs);
    DECLARE @initial_alt_ft INT = NULL;
    DECLARE @final_alt_ft INT = NULL;
    
    IF @step_count > 0
    BEGIN
        -- First altitude in route is initial cruise
        SELECT TOP 1 @initial_alt_ft = altitude_ft FROM @step_climbs ORDER BY sequence_num;
        -- Last altitude is final cruise
        SELECT TOP 1 @final_alt_ft = altitude_ft FROM @step_climbs ORDER BY sequence_num DESC;
    END
    
    IF @debug = 1
    BEGIN
        PRINT '';
        PRINT 'Step Climbs: ' + CAST(@step_count AS VARCHAR);
        IF @step_count > 0
        BEGIN
            PRINT '  Initial: FL' + ISNULL(CAST(@initial_alt_ft / 100 AS VARCHAR), '?');
            PRINT '  Final:   FL' + ISNULL(CAST(@final_alt_ft / 100 AS VARCHAR), '?');
            SELECT * FROM @step_climbs ORDER BY sequence_num;
        END
    END
    
    -- ========================================================================
    -- Step 3: Extract runways from SID/STAR names
    -- ========================================================================
    DECLARE @dep_runway NVARCHAR(4) = NULL;
    DECLARE @arr_runway NVARCHAR(4) = NULL;
    
    -- Try to get runway from DP/SID name
    IF @dp_name IS NOT NULL
        SET @dep_runway = dbo.fn_ExtractRunwayFromProcedure(@dp_name);
    
    -- Try to get runway from STAR or approach
    IF @star_name IS NOT NULL
        SET @arr_runway = dbo.fn_ExtractRunwayFromProcedure(@star_name);
    
    IF @arr_runway IS NULL AND @approach IS NOT NULL
        SET @arr_runway = dbo.fn_ExtractRunwayFromProcedure(@approach);
    
    IF @debug = 1
    BEGIN
        PRINT '';
        PRINT 'Runway Extraction:';
        PRINT '  Dep Runway (from DP): ' + ISNULL(@dep_runway, '(none)');
        PRINT '  Arr Runway (from STAR/APP): ' + ISNULL(@arr_runway, '(none)');
    END
    
    -- ========================================================================
    -- Step 4: Update adl_flight_plan
    -- ========================================================================
    UPDATE dbo.adl_flight_plan
    SET 
        is_simbrief = @is_simbrief,
        -- Only update runways if we found them and they're not already set
        dep_runway = COALESCE(dep_runway, @dep_runway),
        dep_runway_source = CASE WHEN dep_runway IS NULL AND @dep_runway IS NOT NULL THEN 'DP_PARSE' ELSE dep_runway_source END,
        arr_runway = COALESCE(arr_runway, @arr_runway),
        arr_runway_source = CASE WHEN arr_runway IS NULL AND @arr_runway IS NOT NULL THEN 'STAR_PARSE' ELSE arr_runway_source END,
        -- Step climb summary
        stepclimb_count = @step_count,
        initial_alt_ft = COALESCE(@initial_alt_ft, initial_alt_ft),
        final_alt_ft = COALESCE(@final_alt_ft, final_alt_ft)
    WHERE flight_uid = @flight_uid;
    
    -- ========================================================================
    -- Step 5: Insert step climb records
    -- ========================================================================
    IF @step_count > 0
    BEGIN
        -- Clear existing step climbs for this flight
        DELETE FROM dbo.adl_flight_stepclimbs WHERE flight_uid = @flight_uid;
        
        -- Insert new step climbs
        INSERT INTO dbo.adl_flight_stepclimbs (
            flight_uid, step_sequence, waypoint_fix, waypoint_seq,
            altitude_ft, speed_kts, speed_mach, speed_type,
            source, raw_text
        )
        SELECT 
            @flight_uid,
            sequence_num,
            waypoint_fix,
            NULL,  -- waypoint_seq - would need to match against parsed waypoints
            altitude_ft,
            speed_kts,
            speed_mach,
            speed_type,
            'ROUTE',
            raw_text
        FROM @step_climbs
        ORDER BY sequence_num;
        
        IF @debug = 1
            PRINT 'Inserted ' + CAST(@step_count AS VARCHAR) + ' step climb records';
    END
    
    -- ========================================================================
    -- Step 6: Update waypoints with step climb flags (if route was parsed)
    -- ========================================================================
    IF @step_count > 0 AND EXISTS (SELECT 1 FROM dbo.adl_flight_waypoints WHERE flight_uid = @flight_uid)
    BEGIN
        -- Mark waypoints that are step climb points
        UPDATE w
        SET w.is_step_climb_point = 1,
            w.planned_alt_ft = sc.altitude_ft,
            w.planned_speed_kts = sc.speed_kts,
            w.planned_speed_mach = sc.speed_mach
        FROM dbo.adl_flight_waypoints w
        INNER JOIN @step_climbs sc ON w.fix_name = sc.waypoint_fix
        WHERE w.flight_uid = @flight_uid;
        
        DECLARE @wp_updated INT = @@ROWCOUNT;
        IF @debug = 1
            PRINT 'Updated ' + CAST(@wp_updated AS VARCHAR) + ' waypoints with step climb data';
    END
    
    IF @debug = 1
    BEGIN
        PRINT '';
        PRINT '================================================';
        PRINT 'SimBrief parsing complete';
        PRINT '================================================';
    END
END;
GO

-- ============================================================================
-- Batch Procedure: Process multiple flights
-- ============================================================================
IF OBJECT_ID('dbo.sp_ParseSimBriefDataBatch', 'P') IS NOT NULL
    DROP PROCEDURE dbo.sp_ParseSimBriefDataBatch;
GO

CREATE PROCEDURE dbo.sp_ParseSimBriefDataBatch
    @batch_size INT = 100,
    @only_unparsed BIT = 1
AS
BEGIN
    SET NOCOUNT ON;
    
    DECLARE @processed INT = 0;
    DECLARE @simbrief_count INT = 0;
    DECLARE @stepclimb_count INT = 0;
    DECLARE @flight_uid BIGINT;
    DECLARE @start_time DATETIME2 = SYSUTCDATETIME();
    
    -- Get batch of flights to process
    DECLARE @batch TABLE (flight_uid BIGINT, has_remarks BIT);
    
    IF @only_unparsed = 1
    BEGIN
        -- Only process flights that haven't been analyzed yet
        -- (is_simbrief IS NULL indicates not yet processed)
        INSERT INTO @batch (flight_uid, has_remarks)
        SELECT TOP (@batch_size) 
            fp.flight_uid,
            CASE WHEN fp.fp_remarks IS NOT NULL AND LEN(fp.fp_remarks) > 0 THEN 1 ELSE 0 END
        FROM dbo.adl_flight_plan fp
        INNER JOIN dbo.adl_flight_core c ON c.flight_uid = fp.flight_uid
        WHERE c.is_active = 1
          AND fp.is_simbrief IS NULL
          AND (fp.fp_route IS NOT NULL OR fp.fp_remarks IS NOT NULL)
        ORDER BY fp.flight_uid;
    END
    ELSE
    BEGIN
        -- Process all active flights
        INSERT INTO @batch (flight_uid, has_remarks)
        SELECT TOP (@batch_size) 
            fp.flight_uid,
            CASE WHEN fp.fp_remarks IS NOT NULL AND LEN(fp.fp_remarks) > 0 THEN 1 ELSE 0 END
        FROM dbo.adl_flight_plan fp
        INNER JOIN dbo.adl_flight_core c ON c.flight_uid = fp.flight_uid
        WHERE c.is_active = 1
          AND (fp.fp_route IS NOT NULL OR fp.fp_remarks IS NOT NULL)
        ORDER BY fp.flight_uid;
    END
    
    -- Process each flight
    DECLARE batch_cursor CURSOR LOCAL FAST_FORWARD FOR
        SELECT flight_uid FROM @batch;
    
    OPEN batch_cursor;
    FETCH NEXT FROM batch_cursor INTO @flight_uid;
    
    WHILE @@FETCH_STATUS = 0
    BEGIN
        BEGIN TRY
            EXEC dbo.sp_ParseSimBriefData @flight_uid = @flight_uid, @debug = 0;
            SET @processed = @processed + 1;
        END TRY
        BEGIN CATCH
            -- Log error but continue processing
            PRINT 'Error processing flight_uid ' + CAST(@flight_uid AS VARCHAR) + ': ' + ERROR_MESSAGE();
        END CATCH
        
        FETCH NEXT FROM batch_cursor INTO @flight_uid;
    END
    
    CLOSE batch_cursor;
    DEALLOCATE batch_cursor;
    
    -- Get counts
    SELECT @simbrief_count = COUNT(*) 
    FROM dbo.adl_flight_plan fp
    INNER JOIN @batch b ON fp.flight_uid = b.flight_uid
    WHERE fp.is_simbrief = 1;
    
    SELECT @stepclimb_count = COUNT(DISTINCT flight_uid)
    FROM dbo.adl_flight_stepclimbs sc
    INNER JOIN @batch b ON sc.flight_uid = b.flight_uid;
    
    -- Return summary
    SELECT 
        @processed AS flights_processed,
        @simbrief_count AS simbrief_flights,
        @stepclimb_count AS flights_with_stepclimbs,
        DATEDIFF(MILLISECOND, @start_time, SYSUTCDATETIME()) AS elapsed_ms;
END;
GO

PRINT 'SimBrief parsing procedures created successfully.';
PRINT '';
PRINT 'Usage:';
PRINT '  -- Parse single flight with debug:';
PRINT '  EXEC sp_ParseSimBriefData @flight_uid = 12345, @debug = 1;';
PRINT '';
PRINT '  -- Batch process unparsed flights:';
PRINT '  EXEC sp_ParseSimBriefDataBatch @batch_size = 100;';
PRINT '';
PRINT '  -- Reprocess all active flights:';
PRINT '  EXEC sp_ParseSimBriefDataBatch @batch_size = 500, @only_unparsed = 0;';
GO
