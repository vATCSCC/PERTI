-- Quick fix: Re-deploy sp_CalculateETA with correct column name
IF OBJECT_ID('dbo.sp_CalculateETA', 'P') IS NOT NULL
    DROP PROCEDURE dbo.sp_CalculateETA;
GO

CREATE PROCEDURE dbo.sp_CalculateETA
    @flight_uid BIGINT
AS
BEGIN
    SET NOCOUNT ON;
    
    DECLARE @now DATETIME2(0) = SYSUTCDATETIME();
    
    -- Result variables
    DECLARE @eta_utc DATETIME2(0);
    DECLARE @eta_prefix NCHAR(1);
    DECLARE @time_to_dest_min INT = 0;
    DECLARE @confidence DECIMAL(3,2) = 0.90;
    
    -- Flight data
    DECLARE @phase NVARCHAR(16);
    DECLARE @current_alt INT;
    DECLARE @current_gs INT;
    DECLARE @vertical_rate INT;
    DECLARE @filed_alt INT;
    DECLARE @dist_to_dest DECIMAL(10,2);
    DECLARE @dist_flown DECIMAL(10,2);
    DECLARE @dest_icao CHAR(4);
    DECLARE @dept_icao CHAR(4);
    DECLARE @aircraft_icao NVARCHAR(8);
    DECLARE @dest_elev INT;
    DECLARE @gcd_nm DECIMAL(10,2);
    
    -- TMI data
    DECLARE @has_edct BIT = 0;
    DECLARE @edct_utc DATETIME2(0);
    DECLARE @has_cta BIT = 0;
    DECLARE @cta_utc DATETIME2(0);
    DECLARE @tmi_delay INT = 0;
    
    -- Performance data
    DECLARE @climb_speed INT;
    DECLARE @cruise_speed INT;
    DECLARE @descent_speed INT;
    DECLARE @descent_angle DECIMAL(4,2);
    
    -- Gather flight data
    SELECT 
        @phase = c.phase,
        @current_alt = p.altitude_ft,
        @current_gs = p.groundspeed_kts,
        @vertical_rate = ISNULL(p.vertical_rate_fpm, 0),
        @dist_to_dest = p.dist_to_dest_nm,
        @dist_flown = p.dist_flown_nm,
        @filed_alt = fp.fp_altitude_ft,
        @dest_icao = fp.fp_dest_icao,
        @dept_icao = fp.fp_dept_icao,
        @aircraft_icao = ac.aircraft_icao,
        @gcd_nm = fp.gcd_nm
    FROM dbo.adl_flight_core c
    LEFT JOIN dbo.adl_flight_position p ON p.flight_uid = c.flight_uid
    LEFT JOIN dbo.adl_flight_plan fp ON fp.flight_uid = c.flight_uid
    LEFT JOIN dbo.adl_flight_aircraft ac ON ac.flight_uid = c.flight_uid
    WHERE c.flight_uid = @flight_uid;
    
    IF @phase IS NULL RETURN;
    
    -- Get destination elevation (ELEV column, not Elev_ft)
    SELECT @dest_elev = ISNULL(CAST(ELEV AS INT), 0) 
    FROM dbo.apts 
    WHERE ICAO_ID = @dest_icao;
    
    SET @dest_elev = ISNULL(@dest_elev, 0);
    SET @filed_alt = ISNULL(@filed_alt, 35000);
    SET @dist_to_dest = ISNULL(@dist_to_dest, @gcd_nm);
    
    -- Get TMI delays
    SELECT 
        @has_edct = CASE WHEN edct_utc IS NOT NULL THEN 1 ELSE 0 END,
        @edct_utc = edct_utc,
        @has_cta = CASE WHEN cta_utc IS NOT NULL THEN 1 ELSE 0 END,
        @cta_utc = cta_utc
    FROM dbo.adl_flight_tmi
    WHERE flight_uid = @flight_uid;
    
    IF @has_edct = 1 AND @phase IN ('prefile', 'taxiing', 'unknown')
    BEGIN
        SET @tmi_delay = DATEDIFF(MINUTE, @now, @edct_utc);
        IF @tmi_delay < 0 SET @tmi_delay = 0;
    END
    
    -- Get aircraft performance
    SELECT TOP 1
        @climb_speed = climb_speed_kias,
        @cruise_speed = cruise_speed_ktas,
        @descent_speed = descent_speed_kias,
        @descent_angle = ISNULL(descent_angle_deg, 3.0)
    FROM dbo.aircraft_performance_profiles
    WHERE aircraft_icao = @aircraft_icao OR aircraft_icao = '_JET_L'
    ORDER BY CASE WHEN aircraft_icao = @aircraft_icao THEN 0 ELSE 1 END;
    
    SET @climb_speed = ISNULL(@climb_speed, 280);
    SET @cruise_speed = ISNULL(@cruise_speed, 450);
    SET @descent_speed = ISNULL(@descent_speed, 280);
    
    -- TOD distance (3nm per 1000ft)
    DECLARE @tod_dist DECIMAL(10,2) = (@filed_alt - @dest_elev) / 1000.0 * 3.0;
    
    -- Phase-specific ETA calculation
    IF @phase = 'arrived'
    BEGIN
        SELECT @eta_utc = ata_runway_utc FROM dbo.adl_flight_times WHERE flight_uid = @flight_uid;
        SET @eta_prefix = 'A';
        SET @confidence = 1.0;
    END
    ELSE IF @phase IN ('descending') AND @dist_to_dest < 50
    BEGIN
        SET @time_to_dest_min = @dist_to_dest / NULLIF(@current_gs, 0) * 60;
        SET @eta_prefix = 'E';
        SET @confidence = 0.95;
    END
    ELSE IF @phase IN ('descending')
    BEGIN
        SET @time_to_dest_min = @dist_to_dest / NULLIF(@descent_speed, 0) * 60;
        SET @eta_prefix = 'E';
        SET @confidence = 0.92;
    END
    ELSE IF @phase IN ('enroute', 'cruise')
    BEGIN
        IF @dist_to_dest <= @tod_dist
            SET @time_to_dest_min = @dist_to_dest / NULLIF(@descent_speed, 0) * 60;
        ELSE
        BEGIN
            DECLARE @cruise_dist DECIMAL(10,2) = @dist_to_dest - @tod_dist;
            DECLARE @cruise_time INT = @cruise_dist / NULLIF(@cruise_speed, 0) * 60;
            DECLARE @descent_time INT = @tod_dist / NULLIF(@descent_speed, 0) * 60;
            SET @time_to_dest_min = @cruise_time + @descent_time;
        END
        SET @eta_prefix = 'E';
        SET @confidence = 0.88;
    END
    ELSE IF @phase IN ('departed', 'climbing')
    BEGIN
        DECLARE @toc_dist DECIMAL(10,2) = (@filed_alt - @current_alt) / 1000.0 * 2.0;
        DECLARE @climb_time INT = @toc_dist / NULLIF(@climb_speed, 0) * 60;
        DECLARE @remaining_cruise DECIMAL(10,2) = @dist_to_dest - @toc_dist - @tod_dist;
        IF @remaining_cruise < 0 SET @remaining_cruise = 0;
        SET @cruise_time = @remaining_cruise / NULLIF(@cruise_speed, 0) * 60;
        SET @descent_time = @tod_dist / NULLIF(@descent_speed, 0) * 60;
        SET @time_to_dest_min = @climb_time + @cruise_time + @descent_time;
        SET @eta_prefix = 'E';
        SET @confidence = 0.82;
    END
    ELSE IF @phase IN ('taxiing')
    BEGIN
        DECLARE @taxi_out INT = 12;
        DECLARE @full_climb DECIMAL(10,2) = (@filed_alt - @dest_elev) / 1000.0 * 2.0;
        DECLARE @full_climb_time INT = @full_climb / NULLIF(@climb_speed, 0) * 60;
        SET @remaining_cruise = @dist_to_dest - @full_climb - @tod_dist;
        IF @remaining_cruise < 0 SET @remaining_cruise = 0;
        SET @cruise_time = @remaining_cruise / NULLIF(@cruise_speed, 0) * 60;
        SET @descent_time = @tod_dist / NULLIF(@descent_speed, 0) * 60;
        SET @time_to_dest_min = @taxi_out + @full_climb_time + @cruise_time + @descent_time + @tmi_delay;
        SET @eta_prefix = CASE WHEN @has_edct = 1 THEN 'C' ELSE 'E' END;
        SET @confidence = 0.75;
    END
    ELSE
    BEGIN
        DECLARE @taxi_estimate INT = 15;
        SET @full_climb = (@filed_alt - @dest_elev) / 1000.0 * 2.0;
        SET @full_climb_time = @full_climb / NULLIF(@climb_speed, 0) * 60;
        SET @remaining_cruise = ISNULL(@dist_to_dest, @gcd_nm) - @full_climb - @tod_dist;
        IF @remaining_cruise < 0 SET @remaining_cruise = 0;
        SET @cruise_time = @remaining_cruise / NULLIF(@cruise_speed, 0) * 60;
        SET @descent_time = @tod_dist / NULLIF(@descent_speed, 0) * 60;
        SET @time_to_dest_min = @taxi_estimate + @full_climb_time + @cruise_time + @descent_time + @tmi_delay;
        SET @eta_prefix = CASE WHEN @has_edct = 1 THEN 'C' ELSE 'P' END;
        SET @confidence = 0.65;
    END
    
    -- Calculate final ETA
    IF @eta_utc IS NULL AND @time_to_dest_min > 0
        SET @eta_utc = DATEADD(MINUTE, @time_to_dest_min, @now);
    
    IF @has_cta = 1 AND @cta_utc > @eta_utc
    BEGIN
        SET @eta_utc = @cta_utc;
        SET @eta_prefix = 'C';
    END
    
    -- TOD ETA
    DECLARE @tod_eta DATETIME2(0) = NULL;
    IF @phase IN ('enroute', 'cruise', 'climbing', 'departed') AND @dist_to_dest > @tod_dist
    BEGIN
        DECLARE @time_to_tod INT = (@dist_to_dest - @tod_dist) / NULLIF(@current_gs, 0) * 60;
        IF @time_to_tod > 0
            SET @tod_eta = DATEADD(MINUTE, @time_to_tod, @now);
    END
    
    -- Update
    UPDATE dbo.adl_flight_times
    SET eta_utc = @eta_utc, eta_runway_utc = @eta_utc, eta_prefix = @eta_prefix,
        eta_route_dist_nm = @dist_to_dest, eta_confidence = @confidence,
        eta_last_calc_utc = @now, tod_dist_nm = @tod_dist, tod_eta_utc = @tod_eta,
        times_updated_utc = @now
    WHERE flight_uid = @flight_uid;
    
    IF @@ROWCOUNT = 0
    BEGIN
        INSERT INTO dbo.adl_flight_times (flight_uid, eta_utc, eta_runway_utc, eta_prefix, eta_route_dist_nm, eta_confidence, eta_last_calc_utc, tod_dist_nm, tod_eta_utc)
        VALUES (@flight_uid, @eta_utc, @eta_utc, @eta_prefix, @dist_to_dest, @confidence, @now, @tod_dist, @tod_eta);
    END
END
GO

PRINT 'Created sp_CalculateETA (fixed ELEV column)';
GO
