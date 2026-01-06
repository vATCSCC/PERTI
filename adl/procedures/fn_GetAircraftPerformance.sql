-- ============================================================================
-- fn_GetAircraftPerformance.sql
-- 
-- Returns aircraft performance values for ETA calculation
-- Falls back to category defaults if specific aircraft not found
-- ============================================================================

IF OBJECT_ID('dbo.fn_GetAircraftPerformance', 'TF') IS NOT NULL
    DROP FUNCTION dbo.fn_GetAircraftPerformance;
GO

CREATE FUNCTION dbo.fn_GetAircraftPerformance(
    @aircraft_icao      NVARCHAR(8),
    @weight_class       NCHAR(1),
    @engine_type        NVARCHAR(8)
)
RETURNS @result TABLE (
    climb_rate_fpm      INT,
    climb_speed_kias    INT,
    climb_speed_mach    DECIMAL(3,2),
    cruise_speed_ktas   INT,
    cruise_mach         DECIMAL(3,2),
    descent_rate_fpm    INT,
    descent_speed_kias  INT,
    optimal_fl          INT,
    source              NVARCHAR(32)
)
AS
BEGIN
    -- Try exact aircraft match first
    IF EXISTS (SELECT 1 FROM dbo.aircraft_performance_profiles WHERE aircraft_icao = @aircraft_icao)
    BEGIN
        INSERT INTO @result
        SELECT 
            climb_rate_fpm,
            climb_speed_kias,
            climb_speed_mach,
            cruise_speed_ktas,
            cruise_mach,
            descent_rate_fpm,
            descent_speed_kias,
            optimal_fl,
            'EXACT'
        FROM dbo.aircraft_performance_profiles
        WHERE aircraft_icao = @aircraft_icao;
        
        RETURN;
    END
    
    -- Build default key based on weight class and engine type
    DECLARE @default_key NVARCHAR(8);
    
    SET @default_key = CASE
        -- Jet defaults
        WHEN @engine_type LIKE 'JET%' OR @engine_type LIKE 'Jet%' THEN
            CASE @weight_class
                WHEN 'J' THEN '_DEF_JJ'  -- Super/Jumbo
                WHEN 'H' THEN '_DEF_JH'  -- Heavy
                WHEN 'L' THEN '_DEF_JL'  -- Large
                WHEN 'S' THEN '_DEF_JS'  -- Small
                ELSE '_DEF_JL'           -- Default to large
            END
        -- Turboprop
        WHEN @engine_type LIKE 'TURBO%' OR @engine_type LIKE 'Turbo%' THEN '_DEF_TP'
        -- Piston
        WHEN @engine_type LIKE 'PISTON%' OR @engine_type LIKE 'Piston%' THEN '_DEF_PS'
        -- Helicopter
        WHEN @engine_type LIKE 'HELO%' OR @engine_type LIKE 'Heli%' THEN '_DEF_HE'
        -- Default based on weight class only
        ELSE CASE @weight_class
            WHEN 'J' THEN '_DEF_JJ'
            WHEN 'H' THEN '_DEF_JH'
            WHEN 'L' THEN '_DEF_JL'
            WHEN 'S' THEN '_DEF_JS'
            ELSE '_DEF_JL'
        END
    END;
    
    -- Try default profile
    IF EXISTS (SELECT 1 FROM dbo.aircraft_performance_profiles WHERE aircraft_icao = @default_key)
    BEGIN
        INSERT INTO @result
        SELECT 
            climb_rate_fpm,
            climb_speed_kias,
            climb_speed_mach,
            cruise_speed_ktas,
            cruise_mach,
            descent_rate_fpm,
            descent_speed_kias,
            optimal_fl,
            'DEFAULT'
        FROM dbo.aircraft_performance_profiles
        WHERE aircraft_icao = @default_key;
        
        RETURN;
    END
    
    -- Ultimate fallback - hardcoded large jet values
    INSERT INTO @result VALUES (
        2200,   -- climb_rate_fpm
        280,    -- climb_speed_kias
        0.78,   -- climb_speed_mach
        460,    -- cruise_speed_ktas
        0.80,   -- cruise_mach
        2200,   -- descent_rate_fpm
        280,    -- descent_speed_kias
        370,    -- optimal_fl
        'HARDCODED'
    );
    
    RETURN;
END;
GO

PRINT 'Created function dbo.fn_GetAircraftPerformance';
GO
