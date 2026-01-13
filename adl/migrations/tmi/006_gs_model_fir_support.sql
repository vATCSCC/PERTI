-- ============================================================================
-- Migration: Add FIR Pattern Support to sp_GS_Model
-- 
-- Enhances the Ground Stop Model procedure to support international FIR-based
-- scoping in addition to ARTCC-based scoping.
--
-- When dep_facilities contains entries prefixed with "FIR:" (e.g., "FIR:EG FIR:LF"),
-- the procedure will match flights by their departure airport's RESP_FIR_ID using
-- LIKE pattern matching.
--
-- Date: 2026-01-13
-- ============================================================================

SET ANSI_NULLS ON;
SET QUOTED_IDENTIFIER ON;
GO

CREATE OR ALTER PROCEDURE dbo.sp_GS_Model
    @program_id         INT,
    @dep_facilities     NVARCHAR(MAX) = NULL,            -- Space-delimited ARTCC list or FIR: prefixed patterns
    @performed_by       NVARCHAR(64) = NULL
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;
    
    -- Get program parameters
    DECLARE @ctl_element NVARCHAR(8), @start_utc DATETIME2(0), @end_utc DATETIME2(0);
    DECLARE @scope_type NVARCHAR(16), @scope_tier TINYINT, @scope_distance_nm INT;
    DECLARE @scope_json NVARCHAR(MAX), @exemptions_json NVARCHAR(MAX);
    DECLARE @exempt_airborne BIT, @exempt_within_min INT;
    DECLARE @flt_incl_carrier NVARCHAR(512), @flt_incl_type NVARCHAR(8);
    DECLARE @status NVARCHAR(16);
    
    SELECT 
        @ctl_element = ctl_element,
        @start_utc = start_utc,
        @end_utc = end_utc,
        @scope_type = scope_type,
        @scope_tier = scope_tier,
        @scope_distance_nm = scope_distance_nm,
        @scope_json = scope_json,
        @exemptions_json = exemptions_json,
        @exempt_airborne = exempt_airborne,
        @exempt_within_min = exempt_within_min,
        @flt_incl_carrier = flt_incl_carrier,
        @flt_incl_type = flt_incl_type,
        @status = status
    FROM dbo.ntml
    WHERE program_id = @program_id AND program_type = 'GS';
    
    IF @ctl_element IS NULL
    BEGIN
        RAISERROR('Program not found or is not a Ground Stop.', 16, 1);
        RETURN -1;
    END
    
    -- Get airport coordinates for distance calculations
    DECLARE @apt_lat DECIMAL(10,7), @apt_lon DECIMAL(11,7);
    SELECT @apt_lat = LAT_DECIMAL, @apt_lon = LONG_DECIMAL
    FROM dbo.apts
    WHERE ICAO_ID = @ctl_element;
    
    -- Parse dep_facilities into separate tables for ARTCCs and FIR patterns
    DECLARE @included_centers TABLE (artcc NVARCHAR(8));
    DECLARE @fir_patterns TABLE (pattern NVARCHAR(8));
    DECLARE @has_artccs BIT = 0;
    DECLARE @has_fir_patterns BIT = 0;
    
    IF @dep_facilities IS NOT NULL AND LEN(TRIM(@dep_facilities)) > 0
    BEGIN
        -- Extract ARTCC codes (not prefixed with FIR:)
        INSERT INTO @included_centers (artcc)
        SELECT UPPER(TRIM(value))
        FROM STRING_SPLIT(@dep_facilities, ' ')
        WHERE LEN(TRIM(value)) > 0
          AND LEFT(UPPER(TRIM(value)), 4) != 'FIR:';
        
        IF EXISTS (SELECT 1 FROM @included_centers)
            SET @has_artccs = 1;
        
        -- Extract FIR patterns (prefixed with FIR:)
        INSERT INTO @fir_patterns (pattern)
        SELECT UPPER(SUBSTRING(TRIM(value), 5, 8))  -- Remove 'FIR:' prefix
        FROM STRING_SPLIT(@dep_facilities, ' ')
        WHERE LEN(TRIM(value)) > 4
          AND LEFT(UPPER(TRIM(value)), 4) = 'FIR:';
        
        IF EXISTS (SELECT 1 FROM @fir_patterns)
            SET @has_fir_patterns = 1;
    END
    
    BEGIN TRANSACTION;
    
    -- Clear any existing TMI assignments for this program (re-modeling)
    UPDATE dbo.adl_flight_tmi
    SET program_id = NULL,
        ctl_type = NULL,
        ctl_element = NULL,
        ctl_prgm = NULL,
        gs_held = 0,
        gs_release_utc = NULL,
        ctl_exempt = 0,
        ctl_exempt_reason = NULL,
        tmi_updated_utc = SYSUTCDATETIME()
    WHERE program_id = @program_id;
    
    -- Identify affected flights using normalized ADL tables
    ;WITH candidate_flights AS (
        SELECT
            c.flight_uid,
            c.callsign,
            c.phase,
            c.first_seen_utc,
            fp.fp_dept_icao,
            fp.fp_dest_icao,
            fp.fp_dept_artcc,
            -- Get FIR from apts table using RESP_FIR_ID
            apt.RESP_FIR_ID AS dep_fir,
            ft.eta_runway_utc,
            ft.etd_runway_utc,
            ft.departure_bucket_utc,
            ft.etd_epoch,
            a.weight_class,
            a.airline_icao,
            pos.lat AS current_lat,
            pos.lon AS current_lon,
            -- Determine if airborne
            CASE
                WHEN c.phase IN ('departed', 'enroute', 'descending') THEN 1
                ELSE 0
            END AS is_airborne,
            -- Minutes until ETD (using best available ETD source)
            CASE 
                WHEN ft.etd_runway_utc IS NOT NULL 
                THEN DATEDIFF(MINUTE, SYSUTCDATETIME(), ft.etd_runway_utc)
                WHEN ft.departure_bucket_utc IS NOT NULL 
                THEN DATEDIFF(MINUTE, SYSUTCDATETIME(), ft.departure_bucket_utc)
                WHEN ft.etd_epoch IS NOT NULL 
                THEN DATEDIFF(MINUTE, SYSUTCDATETIME(), DATEADD(SECOND, ft.etd_epoch, '1970-01-01'))
                ELSE NULL
            END AS minutes_to_etd
        FROM dbo.adl_flight_core c
        INNER JOIN dbo.adl_flight_plan fp ON fp.flight_uid = c.flight_uid
        INNER JOIN dbo.adl_flight_times ft ON ft.flight_uid = c.flight_uid
        LEFT JOIN dbo.adl_flight_aircraft a ON a.flight_uid = c.flight_uid
        LEFT JOIN dbo.adl_flight_position pos ON pos.flight_uid = c.flight_uid
        LEFT JOIN dbo.apts apt ON apt.ICAO_ID = fp.fp_dept_icao
        WHERE c.is_active = 1
          AND fp.fp_dest_icao = @ctl_element
          AND (
              -- ETA within GS window
              (ft.eta_runway_utc IS NOT NULL AND ft.eta_runway_utc >= @start_utc AND ft.eta_runway_utc <= @end_utc)
              -- Or arrival bucket within GS window
              OR (ft.arrival_bucket_utc IS NOT NULL AND ft.arrival_bucket_utc >= @start_utc AND ft.arrival_bucket_utc <= @end_utc)
          )
    ),
    flights_with_scope AS (
        SELECT 
            cf.*,
            -- Determine if in scope based on ARTCC list, FIR patterns, or distance
            CASE
                -- If has ARTCC list: check fp_dept_artcc
                WHEN @has_artccs = 1 AND cf.fp_dept_artcc IN (SELECT artcc FROM @included_centers) THEN 1
                
                -- If has FIR patterns: check if dep_fir or departure ICAO prefix matches any pattern
                WHEN @has_fir_patterns = 1 AND EXISTS (
                    SELECT 1 FROM @fir_patterns fp 
                    WHERE cf.dep_fir LIKE fp.pattern + '%'
                       OR cf.fp_dept_icao LIKE fp.pattern + '%'
                ) THEN 1
                
                -- Distance-based scope
                WHEN @scope_type = 'DISTANCE' AND @scope_distance_nm IS NOT NULL THEN
                    CASE WHEN dbo.fn_HaversineNM(@apt_lat, @apt_lon, 
                        (SELECT LAT_DECIMAL FROM dbo.apts WHERE ICAO_ID = cf.fp_dept_icao),
                        (SELECT LONG_DECIMAL FROM dbo.apts WHERE ICAO_ID = cf.fp_dept_icao)
                    ) <= @scope_distance_nm THEN 1 ELSE 0 END
                
                -- No scope specified but has ARTCC/FIR filters: flight is out of scope
                WHEN @has_artccs = 1 OR @has_fir_patterns = 1 THEN 0
                
                -- Default: all flights to destination
                ELSE 1
            END AS in_scope,
            -- Determine exemption status
            CASE
                -- Airborne exemption
                WHEN @exempt_airborne = 1 AND cf.is_airborne = 1 THEN 'AIRBORNE'
                -- Departing within X minutes exemption
                WHEN @exempt_within_min IS NOT NULL 
                     AND cf.minutes_to_etd IS NOT NULL 
                     AND cf.minutes_to_etd <= @exempt_within_min 
                     AND cf.minutes_to_etd >= 0 THEN 'DEP_IMMINENT'
                ELSE NULL
            END AS exempt_reason
        FROM candidate_flights cf
    ),
    -- Apply carrier and aircraft type filters
    filtered_flights AS (
        SELECT *
        FROM flights_with_scope
        WHERE in_scope = 1
          AND (@flt_incl_carrier IS NULL 
               OR @flt_incl_carrier = ''
               OR airline_icao IN (SELECT UPPER(TRIM(value)) FROM STRING_SPLIT(@flt_incl_carrier, ' ') WHERE LEN(TRIM(value)) > 0))
          AND (@flt_incl_type IS NULL 
               OR @flt_incl_type = 'ALL'
               OR (@flt_incl_type = 'JET' AND weight_class IN ('L', 'H', 'J'))
               OR (@flt_incl_type = 'PROP' AND weight_class = 'S'))
    )
    -- Insert/Update TMI assignments
    MERGE dbo.adl_flight_tmi AS tgt
    USING filtered_flights AS src
    ON tgt.flight_uid = src.flight_uid
    WHEN MATCHED THEN
        UPDATE SET
            program_id = @program_id,
            ctl_type = 'GS',
            ctl_element = @ctl_element,
            ctl_prgm = (SELECT program_name FROM dbo.ntml WHERE program_id = @program_id),
            gs_held = CASE WHEN src.exempt_reason IS NULL THEN 1 ELSE 0 END,
            gs_release_utc = CASE WHEN src.exempt_reason IS NULL THEN @end_utc ELSE NULL END,
            ctl_exempt = CASE WHEN src.exempt_reason IS NOT NULL THEN 1 ELSE 0 END,
            ctl_exempt_reason = src.exempt_reason,
            tmi_updated_utc = SYSUTCDATETIME()
    WHEN NOT MATCHED BY TARGET THEN
        INSERT (flight_uid, program_id, ctl_type, ctl_element, ctl_prgm,
                gs_held, gs_release_utc, ctl_exempt, ctl_exempt_reason, tmi_updated_utc)
        VALUES (src.flight_uid, @program_id, 'GS', @ctl_element,
                (SELECT program_name FROM dbo.ntml WHERE program_id = @program_id),
                CASE WHEN src.exempt_reason IS NULL THEN 1 ELSE 0 END,
                CASE WHEN src.exempt_reason IS NULL THEN @end_utc ELSE NULL END,
                CASE WHEN src.exempt_reason IS NOT NULL THEN 1 ELSE 0 END,
                src.exempt_reason,
                SYSUTCDATETIME());
    
    -- Update program metrics
    UPDATE p SET
        total_flights = m.total_flights,
        controlled_flights = m.controlled,
        exempt_flights = m.exempt,
        airborne_flights = m.airborne,
        model_time_utc = SYSUTCDATETIME(),
        modified_utc = SYSUTCDATETIME()
    FROM dbo.ntml p
    CROSS APPLY (
        SELECT 
            COUNT(*) AS total_flights,
            SUM(CASE WHEN gs_held = 1 THEN 1 ELSE 0 END) AS controlled,
            SUM(CASE WHEN ctl_exempt = 1 THEN 1 ELSE 0 END) AS exempt,
            SUM(CASE WHEN ctl_exempt_reason = 'AIRBORNE' THEN 1 ELSE 0 END) AS airborne
        FROM dbo.adl_flight_tmi
        WHERE program_id = @program_id
    ) m
    WHERE p.program_id = @program_id;
    
    -- Log event
    INSERT INTO dbo.ntml_info (program_id, event_type, event_message, 
                               event_details_json, performed_by)
    SELECT @program_id, 'PROGRAM_MODELED', 
           'Ground Stop modeled: ' + CAST(controlled_flights AS NVARCHAR) + ' controlled, ' +
           CAST(exempt_flights AS NVARCHAR) + ' exempt' +
           CASE WHEN @has_fir_patterns = 1 THEN ' (FIR scope)' ELSE '' END,
           (SELECT 
               total_flights AS total_flights,
               controlled_flights AS controlled_flights,
               exempt_flights AS exempt_flights,
               airborne_flights AS airborne_flights,
               @has_fir_patterns AS fir_mode
            FOR JSON PATH, WITHOUT_ARRAY_WRAPPER),
           @performed_by
    FROM dbo.ntml WHERE program_id = @program_id;
    
    COMMIT;
    
    RETURN 0;
END
GO

PRINT 'Updated sp_GS_Model with FIR pattern support';
PRINT 'FIR patterns are identified by "FIR:" prefix in dep_facilities parameter';
PRINT 'Example: dep_facilities = "FIR:EG FIR:LF" matches UK and France departures';
GO
