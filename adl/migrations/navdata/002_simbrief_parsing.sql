-- ============================================================================
-- Migration 048: SimBrief Parsing Support
-- 
-- Adds columns and deploys procedures for SimBrief/ICAO flight plan parsing
-- 
-- Features:
--   - SimBrief flight detection
--   - ICAO Item 18 indicator extraction
--   - Step climb parsing from route string
--   - Runway extraction from SID/STAR names
--
-- Run Order: After 047_openap_aircraft_import.sql
-- ============================================================================

SET ANSI_NULLS ON;
SET QUOTED_IDENTIFIER ON;
GO

PRINT '=== Migration 048: SimBrief Parsing Support ===';
PRINT 'Started at: ' + CONVERT(VARCHAR, GETUTCDATE(), 120);
GO

-- ============================================================================
-- 1. Ensure adl_flight_plan has all required columns
-- ============================================================================

-- is_simbrief flag (may already exist)
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.adl_flight_plan') AND name = 'is_simbrief')
BEGIN
    ALTER TABLE dbo.adl_flight_plan ADD is_simbrief BIT NULL;
    PRINT 'Added column: is_simbrief';
END
ELSE
    PRINT 'Column is_simbrief already exists';
GO

-- simbrief_id (may already exist)
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.adl_flight_plan') AND name = 'simbrief_id')
BEGIN
    ALTER TABLE dbo.adl_flight_plan ADD simbrief_id NVARCHAR(32) NULL;
    PRINT 'Added column: simbrief_id';
END
ELSE
    PRINT 'Column simbrief_id already exists';
GO

-- cost_index (may already exist)
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.adl_flight_plan') AND name = 'cost_index')
BEGIN
    ALTER TABLE dbo.adl_flight_plan ADD cost_index INT NULL;
    PRINT 'Added column: cost_index';
END
ELSE
    PRINT 'Column cost_index already exists';
GO

-- dep_runway (may already exist)
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.adl_flight_plan') AND name = 'dep_runway')
BEGIN
    ALTER TABLE dbo.adl_flight_plan ADD dep_runway NVARCHAR(4) NULL;
    PRINT 'Added column: dep_runway';
END
ELSE
    PRINT 'Column dep_runway already exists';
GO

-- dep_runway_source
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.adl_flight_plan') AND name = 'dep_runway_source')
BEGIN
    ALTER TABLE dbo.adl_flight_plan ADD dep_runway_source NVARCHAR(16) NULL;
    PRINT 'Added column: dep_runway_source';
END
ELSE
    PRINT 'Column dep_runway_source already exists';
GO

-- arr_runway (may already exist)
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.adl_flight_plan') AND name = 'arr_runway')
BEGIN
    ALTER TABLE dbo.adl_flight_plan ADD arr_runway NVARCHAR(4) NULL;
    PRINT 'Added column: arr_runway';
END
ELSE
    PRINT 'Column arr_runway already exists';
GO

-- arr_runway_source
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.adl_flight_plan') AND name = 'arr_runway_source')
BEGIN
    ALTER TABLE dbo.adl_flight_plan ADD arr_runway_source NVARCHAR(16) NULL;
    PRINT 'Added column: arr_runway_source';
END
ELSE
    PRINT 'Column arr_runway_source already exists';
GO

-- initial_alt_ft (initial cruise altitude)
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.adl_flight_plan') AND name = 'initial_alt_ft')
BEGIN
    ALTER TABLE dbo.adl_flight_plan ADD initial_alt_ft INT NULL;
    PRINT 'Added column: initial_alt_ft';
END
ELSE
    PRINT 'Column initial_alt_ft already exists';
GO

-- final_alt_ft (final cruise altitude after step climbs)
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.adl_flight_plan') AND name = 'final_alt_ft')
BEGIN
    ALTER TABLE dbo.adl_flight_plan ADD final_alt_ft INT NULL;
    PRINT 'Added column: final_alt_ft';
END
ELSE
    PRINT 'Column final_alt_ft already exists';
GO

-- stepclimb_count
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.adl_flight_plan') AND name = 'stepclimb_count')
BEGIN
    ALTER TABLE dbo.adl_flight_plan ADD stepclimb_count INT NULL;
    PRINT 'Added column: stepclimb_count';
END
ELSE
    PRINT 'Column stepclimb_count already exists';
GO

-- ============================================================================
-- 2. Ensure adl_flight_waypoints has required columns
-- ============================================================================

-- on_dp (DP/SID name)
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.adl_flight_waypoints') AND name = 'on_dp')
BEGIN
    ALTER TABLE dbo.adl_flight_waypoints ADD on_dp NVARCHAR(20) NULL;
    PRINT 'Added column: on_dp to adl_flight_waypoints';
END
ELSE
    PRINT 'Column on_dp already exists in adl_flight_waypoints';
GO

-- on_star (STAR name)
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.adl_flight_waypoints') AND name = 'on_star')
BEGIN
    ALTER TABLE dbo.adl_flight_waypoints ADD on_star NVARCHAR(20) NULL;
    PRINT 'Added column: on_star to adl_flight_waypoints';
END
ELSE
    PRINT 'Column on_star already exists in adl_flight_waypoints';
GO

-- ============================================================================
-- 3. Deploy Helper Functions
-- ============================================================================

-- fn_ParseICAORemarks
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
            SET @end_pos = CHARINDEX(' ', @clean, @pos + LEN(@ind) + 1);
            IF @end_pos = 0 SET @end_pos = LEN(@clean) + 1;
            
            SET @val = SUBSTRING(@clean, @pos + LEN(@ind) + 1, @end_pos - @pos - LEN(@ind) - 1);
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
PRINT 'Created function: fn_ParseICAORemarks';
GO

-- fn_ParseRouteStepClimbs
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
    SET @clean = REPLACE(@clean, '+', ' ');
    
    DECLARE @tokens TABLE (seq INT IDENTITY(1,1), token NVARCHAR(128));
    INSERT INTO @tokens (token)
    SELECT value FROM STRING_SPLIT(@clean, ' ')
    WHERE LEN(LTRIM(RTRIM(value))) > 0;
    
    DECLARE @seq INT, @token NVARCHAR(128), @slash_pos INT;
    DECLARE @waypoint NVARCHAR(64), @spec NVARCHAR(64), @step_seq INT = 0;
    DECLARE @alt_ft INT, @fl INT, @spd_kts INT, @spd_mach DECIMAL(4,3), @spd_type NVARCHAR(8);
    
    DECLARE tok_cursor CURSOR LOCAL FAST_FORWARD FOR
        SELECT seq, token FROM @tokens WHERE token LIKE '%/%' ORDER BY seq;
    
    OPEN tok_cursor;
    FETCH NEXT FROM tok_cursor INTO @seq, @token;
    
    WHILE @@FETCH_STATUS = 0
    BEGIN
        SET @slash_pos = CHARINDEX('/', @token);
        SET @waypoint = LEFT(@token, @slash_pos - 1);
        SET @spec = SUBSTRING(@token, @slash_pos + 1, LEN(@token));
        
        IF @waypoint NOT IN ('DOF', 'REG', 'OPR', 'PBN', 'NAV', 'EET', 'RMK', 'STS', 'SEL', 'CODE', 'PER', 'N', 'K', 'M', 'A', 'F', 'S')
           AND LEN(@waypoint) >= 2 AND LEN(@waypoint) <= 12
           AND @waypoint NOT LIKE '[0-9]%'
        BEGIN
            SET @alt_ft = NULL; SET @fl = NULL; SET @spd_kts = NULL; SET @spd_mach = NULL; SET @spd_type = NULL;
            
            IF @spec LIKE 'N0[0-9][0-9][0-9]%'
            BEGIN
                SET @spd_kts = TRY_CAST(SUBSTRING(@spec, 3, 3) AS INT);
                SET @spd_type = 'TAS';
                SET @spec = SUBSTRING(@spec, 6, LEN(@spec));
            END
            ELSE IF @spec LIKE 'K0[0-9][0-9][0-9]%'
            BEGIN
                SET @spd_kts = TRY_CAST(SUBSTRING(@spec, 3, 3) AS INT);
                IF @spd_kts IS NOT NULL SET @spd_kts = CAST(@spd_kts * 0.54 AS INT);
                SET @spd_type = 'TAS';
                SET @spec = SUBSTRING(@spec, 6, LEN(@spec));
            END
            ELSE IF @spec LIKE 'M0[0-9][0-9]%'
            BEGIN
                SET @spd_mach = TRY_CAST(SUBSTRING(@spec, 2, 3) AS INT) / 100.0;
                SET @spd_type = 'MACH';
                SET @spec = SUBSTRING(@spec, 5, LEN(@spec));
            END
            
            IF @spec LIKE 'F[0-9][0-9][0-9]%'
            BEGIN
                SET @fl = TRY_CAST(SUBSTRING(@spec, 2, 3) AS INT);
                IF @fl IS NOT NULL SET @alt_ft = @fl * 100;
            END
            ELSE IF @spec LIKE 'S[0-9][0-9][0-9][0-9]%'
            BEGIN
                DECLARE @meters INT = TRY_CAST(SUBSTRING(@spec, 2, 4) AS INT) * 10;
                IF @meters IS NOT NULL SET @alt_ft = CAST(@meters * 3.28084 AS INT);
                SET @fl = @alt_ft / 100;
            END
            ELSE IF @spec LIKE 'A[0-9][0-9][0-9]%'
            BEGIN
                SET @alt_ft = TRY_CAST(SUBSTRING(@spec, 2, 3) AS INT) * 100;
                SET @fl = @alt_ft / 100;
            END
            
            IF @alt_ft IS NOT NULL
            BEGIN
                SET @step_seq = @step_seq + 1;
                INSERT INTO @result VALUES (@step_seq, @waypoint, @alt_ft, @fl, @spd_kts, @spd_mach, @spd_type, @token);
            END
        END
        
        FETCH NEXT FROM tok_cursor INTO @seq, @token;
    END
    
    CLOSE tok_cursor;
    DEALLOCATE tok_cursor;
    
    RETURN;
END;
GO
PRINT 'Created function: fn_ParseRouteStepClimbs';
GO

-- fn_IsSimBriefFlight
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
    
    IF @upper_remarks LIKE '%SIMBRIEF%' RETURN 1;
    IF @upper_remarks LIKE '%SB/%' RETURN 1;
    IF @upper_remarks LIKE '%PBN/%' AND @upper_remarks LIKE '%DOF/%' AND @upper_remarks LIKE '%RMK/%' RETURN 1;
    IF @upper_remarks LIKE '%RMK/TCAS%' RETURN 1;
    IF @upper_remarks LIKE '%PBN/A1B1%' AND @upper_remarks LIKE '%NAV/%' RETURN 1;
    
    RETURN 0;
END;
GO
PRINT 'Created function: fn_IsSimBriefFlight';
GO

-- fn_ExtractRunwayFromProcedure
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
    
    IF @upper LIKE '%[0-3][0-9]' AND LEN(@upper) >= 2
    BEGIN
        SET @runway = RIGHT(@upper, 2);
        IF TRY_CAST(@runway AS INT) > 36 SET @runway = NULL;
    END
    ELSE IF @upper LIKE '%[0-3][0-9][LCR]' AND LEN(@upper) >= 3
    BEGIN
        SET @runway = RIGHT(@upper, 3);
        IF TRY_CAST(LEFT(@runway, 2) AS INT) > 36 SET @runway = NULL;
    END
    ELSE IF @upper LIKE '%RW[0-3][0-9]%'
    BEGIN
        DECLARE @rw_pos INT = PATINDEX('%RW[0-3][0-9]%', @upper);
        IF @rw_pos > 0
        BEGIN
            SET @runway = SUBSTRING(@upper, @rw_pos + 2, 2);
            IF LEN(@upper) >= @rw_pos + 4 AND SUBSTRING(@upper, @rw_pos + 4, 1) IN ('L', 'C', 'R')
                SET @runway = @runway + SUBSTRING(@upper, @rw_pos + 4, 1);
        END
    END
    
    RETURN @runway;
END;
GO
PRINT 'Created function: fn_ExtractRunwayFromProcedure';
GO

-- ============================================================================
-- 4. Deploy Main Procedures
-- ============================================================================

-- sp_ParseSimBriefData
IF OBJECT_ID('dbo.sp_ParseSimBriefData', 'P') IS NOT NULL
    DROP PROCEDURE dbo.sp_ParseSimBriefData;
GO

CREATE PROCEDURE dbo.sp_ParseSimBriefData
    @flight_uid BIGINT,
    @debug BIT = 0
AS
BEGIN
    SET NOCOUNT ON;
    
    DECLARE @route NVARCHAR(MAX), @remarks NVARCHAR(MAX);
    DECLARE @dept_icao NVARCHAR(10), @dest_icao NVARCHAR(10);
    DECLARE @dp_name NVARCHAR(16), @star_name NVARCHAR(16), @approach NVARCHAR(16);
    
    SELECT 
        @route = fp_route, @remarks = fp_remarks,
        @dept_icao = fp_dept_icao, @dest_icao = fp_dest_icao,
        @dp_name = dp_name, @star_name = star_name, @approach = approach
    FROM dbo.adl_flight_plan WHERE flight_uid = @flight_uid;
    
    IF @route IS NULL AND @remarks IS NULL RETURN;
    
    DECLARE @is_simbrief BIT = dbo.fn_IsSimBriefFlight(@remarks, @route);
    
    -- Parse step climbs
    DECLARE @step_climbs TABLE (
        sequence_num INT, waypoint_fix NVARCHAR(64), altitude_ft INT, flight_level INT,
        speed_kts INT, speed_mach DECIMAL(4,3), speed_type NVARCHAR(8), raw_text NVARCHAR(128)
    );
    INSERT INTO @step_climbs SELECT * FROM dbo.fn_ParseRouteStepClimbs(@route);
    
    DECLARE @step_count INT = (SELECT COUNT(*) FROM @step_climbs);
    DECLARE @initial_alt_ft INT = NULL, @final_alt_ft INT = NULL;
    
    IF @step_count > 0
    BEGIN
        SELECT TOP 1 @initial_alt_ft = altitude_ft FROM @step_climbs ORDER BY sequence_num;
        SELECT TOP 1 @final_alt_ft = altitude_ft FROM @step_climbs ORDER BY sequence_num DESC;
    END
    
    -- Extract runways
    DECLARE @dep_runway NVARCHAR(4) = dbo.fn_ExtractRunwayFromProcedure(@dp_name);
    DECLARE @arr_runway NVARCHAR(4) = COALESCE(
        dbo.fn_ExtractRunwayFromProcedure(@star_name),
        dbo.fn_ExtractRunwayFromProcedure(@approach)
    );
    
    -- Update flight plan
    UPDATE dbo.adl_flight_plan
    SET 
        is_simbrief = @is_simbrief,
        dep_runway = COALESCE(dep_runway, @dep_runway),
        dep_runway_source = CASE WHEN dep_runway IS NULL AND @dep_runway IS NOT NULL THEN 'DP_PARSE' ELSE dep_runway_source END,
        arr_runway = COALESCE(arr_runway, @arr_runway),
        arr_runway_source = CASE WHEN arr_runway IS NULL AND @arr_runway IS NOT NULL THEN 'STAR_PARSE' ELSE arr_runway_source END,
        stepclimb_count = @step_count,
        initial_alt_ft = COALESCE(@initial_alt_ft, initial_alt_ft),
        final_alt_ft = COALESCE(@final_alt_ft, final_alt_ft)
    WHERE flight_uid = @flight_uid;
    
    -- Insert step climbs
    IF @step_count > 0
    BEGIN
        DELETE FROM dbo.adl_flight_stepclimbs WHERE flight_uid = @flight_uid;
        
        INSERT INTO dbo.adl_flight_stepclimbs (
            flight_uid, step_sequence, waypoint_fix, altitude_ft, 
            speed_kts, speed_mach, speed_type, source, raw_text
        )
        SELECT @flight_uid, sequence_num, waypoint_fix, altitude_ft,
               speed_kts, speed_mach, speed_type, 'ROUTE', raw_text
        FROM @step_climbs ORDER BY sequence_num;
    END
    
    -- Update waypoints with step climb flags
    IF @step_count > 0 AND EXISTS (SELECT 1 FROM dbo.adl_flight_waypoints WHERE flight_uid = @flight_uid)
    BEGIN
        UPDATE w
        SET w.is_step_climb_point = 1,
            w.planned_alt_ft = sc.altitude_ft,
            w.planned_speed_kts = sc.speed_kts,
            w.planned_speed_mach = sc.speed_mach
        FROM dbo.adl_flight_waypoints w
        INNER JOIN @step_climbs sc ON w.fix_name = sc.waypoint_fix
        WHERE w.flight_uid = @flight_uid;
    END
END;
GO
PRINT 'Created procedure: sp_ParseSimBriefData';
GO

-- sp_ParseSimBriefDataBatch
IF OBJECT_ID('dbo.sp_ParseSimBriefDataBatch', 'P') IS NOT NULL
    DROP PROCEDURE dbo.sp_ParseSimBriefDataBatch;
GO

CREATE PROCEDURE dbo.sp_ParseSimBriefDataBatch
    @batch_size INT = 100,
    @only_unparsed BIT = 1
AS
BEGIN
    SET NOCOUNT ON;
    
    DECLARE @processed INT = 0, @simbrief_count INT = 0, @stepclimb_count INT = 0;
    DECLARE @flight_uid BIGINT;
    DECLARE @start_time DATETIME2 = SYSUTCDATETIME();
    
    DECLARE @batch TABLE (flight_uid BIGINT);
    
    IF @only_unparsed = 1
    BEGIN
        INSERT INTO @batch (flight_uid)
        SELECT TOP (@batch_size) fp.flight_uid
        FROM dbo.adl_flight_plan fp
        INNER JOIN dbo.adl_flight_core c ON c.flight_uid = fp.flight_uid
        WHERE c.is_active = 1 AND fp.is_simbrief IS NULL
          AND (fp.fp_route IS NOT NULL OR fp.fp_remarks IS NOT NULL)
        ORDER BY fp.flight_uid;
    END
    ELSE
    BEGIN
        INSERT INTO @batch (flight_uid)
        SELECT TOP (@batch_size) fp.flight_uid
        FROM dbo.adl_flight_plan fp
        INNER JOIN dbo.adl_flight_core c ON c.flight_uid = fp.flight_uid
        WHERE c.is_active = 1
          AND (fp.fp_route IS NOT NULL OR fp.fp_remarks IS NOT NULL)
        ORDER BY fp.flight_uid;
    END
    
    DECLARE batch_cursor CURSOR LOCAL FAST_FORWARD FOR SELECT flight_uid FROM @batch;
    OPEN batch_cursor;
    FETCH NEXT FROM batch_cursor INTO @flight_uid;
    
    WHILE @@FETCH_STATUS = 0
    BEGIN
        BEGIN TRY
            EXEC dbo.sp_ParseSimBriefData @flight_uid = @flight_uid, @debug = 0;
            SET @processed = @processed + 1;
        END TRY
        BEGIN CATCH
        END CATCH
        FETCH NEXT FROM batch_cursor INTO @flight_uid;
    END
    
    CLOSE batch_cursor;
    DEALLOCATE batch_cursor;
    
    SELECT @simbrief_count = COUNT(*) FROM dbo.adl_flight_plan fp
    INNER JOIN @batch b ON fp.flight_uid = b.flight_uid WHERE fp.is_simbrief = 1;
    
    SELECT @stepclimb_count = COUNT(DISTINCT flight_uid) FROM dbo.adl_flight_stepclimbs sc
    INNER JOIN @batch b ON sc.flight_uid = b.flight_uid;
    
    SELECT @processed AS flights_processed, @simbrief_count AS simbrief_flights,
           @stepclimb_count AS flights_with_stepclimbs,
           DATEDIFF(MILLISECOND, @start_time, SYSUTCDATETIME()) AS elapsed_ms;
END;
GO
PRINT 'Created procedure: sp_ParseSimBriefDataBatch';
GO

-- ============================================================================
-- 5. Create index for SimBrief detection queries
-- ============================================================================

IF NOT EXISTS (SELECT * FROM sys.indexes WHERE object_id = OBJECT_ID('dbo.adl_flight_plan') AND name = 'IX_flight_plan_simbrief')
BEGIN
    CREATE NONCLUSTERED INDEX IX_flight_plan_simbrief 
    ON dbo.adl_flight_plan (is_simbrief) 
    WHERE is_simbrief = 1;
    PRINT 'Created index: IX_flight_plan_simbrief';
END
GO

PRINT '';
PRINT '=== Migration 048 Complete ===';
PRINT 'Finished at: ' + CONVERT(VARCHAR, GETUTCDATE(), 120);
PRINT '';
PRINT 'Usage:';
PRINT '  -- Parse single flight:';
PRINT '  EXEC sp_ParseSimBriefData @flight_uid = 12345, @debug = 1;';
PRINT '';
PRINT '  -- Batch process unparsed flights:';
PRINT '  EXEC sp_ParseSimBriefDataBatch @batch_size = 100;';
GO
