-- ============================================================================
-- Ground Stop Stored Procedures
-- 
-- Part of the unified GDT system with NTML tables
-- 
-- Procedures:
--   sp_GS_Create        - Create a proposed ground stop
--   sp_GS_Model         - Model the ground stop (identify affected flights)
--   sp_GS_IssueEDCTs    - Activate the ground stop
--   sp_GS_Extend        - Extend GS end time
--   sp_GS_Purge         - Cancel/purge the ground stop
--   sp_GS_GetFlights    - Get flights affected by a GS
--   sp_GS_DetectPopups  - Detect new pop-up flights during active GS
--
-- Helper Functions:
--   fn_HaversineNM      - Calculate great circle distance in nautical miles
-- ============================================================================

SET ANSI_NULLS ON;
SET QUOTED_IDENTIFIER ON;
GO

-- ============================================================================
-- Helper: fn_HaversineNM - Calculate great circle distance in nautical miles
-- ============================================================================

IF NOT EXISTS (SELECT * FROM sys.objects WHERE object_id = OBJECT_ID(N'dbo.fn_HaversineNM') AND type = 'FN')
BEGIN
    EXEC('
    CREATE FUNCTION dbo.fn_HaversineNM (
        @lat1 DECIMAL(10,7),
        @lon1 DECIMAL(11,7),
        @lat2 DECIMAL(10,7),
        @lon2 DECIMAL(11,7)
    )
    RETURNS DECIMAL(10,2)
    AS
    BEGIN
        DECLARE @earth_radius_nm DECIMAL(10,2) = 3440.065;
        DECLARE @lat1_rad FLOAT = RADIANS(@lat1);
        DECLARE @lat2_rad FLOAT = RADIANS(@lat2);
        DECLARE @delta_lat FLOAT = RADIANS(@lat2 - @lat1);
        DECLARE @delta_lon FLOAT = RADIANS(@lon2 - @lon1);
        
        DECLARE @a FLOAT = SIN(@delta_lat / 2) * SIN(@delta_lat / 2) +
                          COS(@lat1_rad) * COS(@lat2_rad) *
                          SIN(@delta_lon / 2) * SIN(@delta_lon / 2);
        DECLARE @c FLOAT = 2 * ATN2(SQRT(@a), SQRT(1 - @a));
        
        RETURN @earth_radius_nm * @c;
    END');
    PRINT 'Created function dbo.fn_HaversineNM';
END
GO

-- ============================================================================
-- sp_GS_Create - Create a proposed Ground Stop
-- ============================================================================

CREATE OR ALTER PROCEDURE dbo.sp_GS_Create
    @ctl_element        NVARCHAR(8),                     -- Destination airport (KJFK)
    @start_utc          DATETIME2(0),
    @end_utc            DATETIME2(0),
    @scope_type         NVARCHAR(16) = 'TIER',           -- TIER, DISTANCE, MANUAL
    @scope_tier         TINYINT = 1,                     -- 1, 2, or 3
    @scope_distance_nm  INT = NULL,                      -- For DISTANCE scope
    @scope_json         NVARCHAR(MAX) = NULL,            -- Detailed scope definition
    @exemptions_json    NVARCHAR(MAX) = NULL,            -- Exemption rules
    @exempt_airborne    BIT = 1,                         -- Exempt airborne flights
    @exempt_within_min  INT = 45,                        -- Exempt flights departing within X min
    @flt_incl_carrier   NVARCHAR(512) = NULL,            -- Carrier filter
    @flt_incl_type      NVARCHAR(8) = 'ALL',             -- ALL, JET, PROP
    @impacting_condition NVARCHAR(64) = NULL,            -- WEATHER, VOLUME, RUNWAY, EQUIPMENT, OTHER
    @cause_text         NVARCHAR(512) = NULL,
    @comments           NVARCHAR(MAX) = NULL,
    @prob_extension     NVARCHAR(8) = 'MEDIUM',          -- LOW, MEDIUM, HIGH
    @created_by         NVARCHAR(64) = NULL,
    @program_id         INT OUTPUT
AS
BEGIN
    SET NOCOUNT ON;
    
    -- Validate inputs
    IF @ctl_element IS NULL OR LEN(TRIM(@ctl_element)) = 0
    BEGIN
        RAISERROR('ctl_element (destination airport) is required.', 16, 1);
        RETURN -1;
    END
    
    IF @start_utc IS NULL OR @end_utc IS NULL
    BEGIN
        RAISERROR('start_utc and end_utc are required.', 16, 1);
        RETURN -1;
    END
    
    IF @start_utc >= @end_utc
    BEGIN
        RAISERROR('end_utc must be after start_utc.', 16, 1);
        RETURN -1;
    END
    
    -- Generate advisory number (sequential for this airport today)
    DECLARE @adv_number NVARCHAR(16);
    SELECT @adv_number = 'ADVZY ' + RIGHT('000' + CAST(
        ISNULL(MAX(CAST(REPLACE(adv_number, 'ADVZY ', '') AS INT)), 0) + 1 
        AS NVARCHAR), 3)
    FROM dbo.ntml
    WHERE ctl_element = @ctl_element
      AND CAST(created_utc AS DATE) = CAST(SYSUTCDATETIME() AS DATE);
    
    -- Insert the program
    INSERT INTO dbo.ntml (
        ctl_element, element_type, program_type, adv_number,
        start_utc, end_utc, cumulative_start, cumulative_end, model_time_utc,
        status, is_proposed, is_active,
        scope_type, scope_tier, scope_distance_nm, scope_json,
        exemptions_json, exempt_airborne, exempt_within_min,
        flt_incl_carrier, flt_incl_type,
        impacting_condition, cause_text, comments, prob_extension,
        created_by, created_utc, modified_utc
    )
    VALUES (
        UPPER(TRIM(@ctl_element)), 'APT', 'GS', @adv_number,
        @start_utc, @end_utc, @start_utc, @end_utc, SYSUTCDATETIME(),
        'PROPOSED', 1, 0,
        @scope_type, @scope_tier, @scope_distance_nm, @scope_json,
        @exemptions_json, @exempt_airborne, @exempt_within_min,
        @flt_incl_carrier, @flt_incl_type,
        @impacting_condition, @cause_text, @comments, @prob_extension,
        @created_by, SYSUTCDATETIME(), SYSUTCDATETIME()
    );
    
    SET @program_id = SCOPE_IDENTITY();
    
    -- Log event
    INSERT INTO dbo.ntml_info (program_id, event_type, event_message, performed_by)
    VALUES (@program_id, 'PROGRAM_CREATED', 
            'Ground Stop created for ' + @ctl_element + ' from ' + 
            FORMAT(@start_utc, 'ddHHmm') + 'Z to ' + FORMAT(@end_utc, 'ddHHmm') + 'Z',
            @created_by);
    
    RETURN 0;
END
GO

-- ============================================================================
-- sp_GS_Model - Model a Ground Stop (identify affected flights)
-- 
-- This procedure identifies flights in scope and marks them for ground stop.
-- It uses the normalized ADL tables and scope/tier information.
-- 
-- The @dep_facilities parameter allows passing pre-expanded ARTCC list from
-- the JS tier lookup (matching current gdt.js behavior).
-- ============================================================================

CREATE OR ALTER PROCEDURE dbo.sp_GS_Model
    @program_id         INT,
    @dep_facilities     NVARCHAR(MAX) = NULL,            -- Space-delimited ARTCC list (from JS tier expansion)
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
    
    -- Parse dep_facilities into a table variable
    DECLARE @included_centers TABLE (artcc NVARCHAR(4));
    IF @dep_facilities IS NOT NULL AND LEN(TRIM(@dep_facilities)) > 0
    BEGIN
        INSERT INTO @included_centers (artcc)
        SELECT UPPER(TRIM(value))
        FROM STRING_SPLIT(@dep_facilities, ' ')
        WHERE LEN(TRIM(value)) > 0;
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
            ft.eta_runway_utc,
            ft.etd_runway_utc,
            a.weight_class,
            a.airline_icao,
            pos.lat AS current_lat,
            pos.lon AS current_lon,
            -- Determine if airborne
            CASE
                WHEN c.phase IN ('departed', 'enroute', 'descending') THEN 1
                ELSE 0
            END AS is_airborne,
            -- Minutes until ETD
            CASE 
                WHEN ft.etd_runway_utc IS NOT NULL 
                THEN DATEDIFF(MINUTE, SYSUTCDATETIME(), ft.etd_runway_utc)
                ELSE NULL
            END AS minutes_to_etd
        FROM dbo.adl_flight_core c
        INNER JOIN dbo.adl_flight_plan fp ON fp.flight_uid = c.flight_uid
        INNER JOIN dbo.adl_flight_times ft ON ft.flight_uid = c.flight_uid
        LEFT JOIN dbo.adl_flight_aircraft a ON a.flight_uid = c.flight_uid
        LEFT JOIN dbo.adl_flight_position pos ON pos.flight_uid = c.flight_uid
        WHERE c.is_active = 1
          AND fp.fp_dest_icao = @ctl_element
          AND ft.eta_runway_utc >= @start_utc
          AND ft.eta_runway_utc <= @end_utc
    ),
    flights_with_scope AS (
        SELECT 
            cf.*,
            -- Determine if in scope based on provided centers or distance
            CASE
                -- If dep_facilities provided, use that list
                WHEN EXISTS (SELECT 1 FROM @included_centers) THEN
                    CASE WHEN cf.fp_dept_artcc IN (SELECT artcc FROM @included_centers) THEN 1 ELSE 0 END
                -- Distance-based scope
                WHEN @scope_type = 'DISTANCE' AND @scope_distance_nm IS NOT NULL THEN
                    CASE WHEN dbo.fn_HaversineNM(@apt_lat, @apt_lon, 
                        (SELECT LAT_DECIMAL FROM dbo.apts WHERE ICAO_ID = cf.fp_dept_icao),
                        (SELECT LONG_DECIMAL FROM dbo.apts WHERE ICAO_ID = cf.fp_dept_icao)
                    ) <= @scope_distance_nm THEN 1 ELSE 0 END
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
           CAST(exempt_flights AS NVARCHAR) + ' exempt',
           (SELECT 
               total_flights AS total_flights,
               controlled_flights AS controlled_flights,
               exempt_flights AS exempt_flights,
               airborne_flights AS airborne_flights
            FOR JSON PATH, WITHOUT_ARRAY_WRAPPER),
           @performed_by
    FROM dbo.ntml WHERE program_id = @program_id;
    
    COMMIT;
    
    RETURN 0;
END
GO

-- ============================================================================
-- sp_GS_IssueEDCTs - Activate a Ground Stop
-- ============================================================================

CREATE OR ALTER PROCEDURE dbo.sp_GS_IssueEDCTs
    @program_id         INT,
    @activated_by       NVARCHAR(64) = NULL
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;
    
    -- Verify program exists and is in PROPOSED state
    DECLARE @status NVARCHAR(16), @program_type NVARCHAR(16);
    DECLARE @ctl_element NVARCHAR(8), @end_utc DATETIME2(0);
    
    SELECT @status = status, @program_type = program_type,
           @ctl_element = ctl_element, @end_utc = end_utc
    FROM dbo.ntml
    WHERE program_id = @program_id;
    
    IF @status IS NULL
    BEGIN
        RAISERROR('Program not found.', 16, 1);
        RETURN -1;
    END
    
    IF @status <> 'PROPOSED'
    BEGIN
        RAISERROR('Program must be in PROPOSED state to activate. Current status: %s', 16, 1, @status);
        RETURN -1;
    END
    
    IF @program_type <> 'GS'
    BEGIN
        RAISERROR('This procedure is for Ground Stops only. Use appropriate procedure for program type: %s', 16, 1, @program_type);
        RETURN -1;
    END
    
    BEGIN TRANSACTION;
    
    -- Update program status
    UPDATE dbo.ntml
    SET status = 'ACTIVE',
        is_proposed = 0,
        is_active = 1,
        activated_utc = SYSUTCDATETIME(),
        activated_by = @activated_by,
        modified_by = @activated_by,
        modified_utc = SYSUTCDATETIME()
    WHERE program_id = @program_id;
    
    -- Set assignment timestamp for all controlled flights
    UPDATE dbo.adl_flight_tmi
    SET assigned_utc = SYSUTCDATETIME()
    WHERE program_id = @program_id
      AND gs_held = 1;
    
    -- Log individual flight control events
    INSERT INTO dbo.ntml_info (program_id, flight_uid, event_type, event_message, performed_by)
    SELECT @program_id, t.flight_uid, 'FLIGHT_CONTROLLED',
           c.callsign + ' ground stopped until ' + FORMAT(@end_utc, 'ddHHmm') + 'Z',
           @activated_by
    FROM dbo.adl_flight_tmi t
    INNER JOIN dbo.adl_flight_core c ON c.flight_uid = t.flight_uid
    WHERE t.program_id = @program_id
      AND t.gs_held = 1;
    
    -- Log activation event
    INSERT INTO dbo.ntml_info (program_id, event_type, event_message, performed_by)
    SELECT @program_id, 'PROGRAM_ACTIVATED',
           'Ground Stop activated for ' + ctl_element + '. ' +
           CAST(controlled_flights AS NVARCHAR) + ' flights held.',
           @activated_by
    FROM dbo.ntml WHERE program_id = @program_id;
    
    COMMIT;
    
    RETURN 0;
END
GO

-- ============================================================================
-- sp_GS_Extend - Extend Ground Stop end time
-- ============================================================================

CREATE OR ALTER PROCEDURE dbo.sp_GS_Extend
    @program_id         INT,
    @new_end_utc        DATETIME2(0),
    @extended_by        NVARCHAR(64) = NULL,
    @comments           NVARCHAR(MAX) = NULL
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;
    
    DECLARE @current_end DATETIME2(0), @status NVARCHAR(16);
    
    SELECT @current_end = end_utc, @status = status
    FROM dbo.ntml
    WHERE program_id = @program_id AND program_type = 'GS';
    
    IF @current_end IS NULL
    BEGIN
        RAISERROR('Ground Stop program not found.', 16, 1);
        RETURN -1;
    END
    
    IF @status NOT IN ('PROPOSED', 'ACTIVE')
    BEGIN
        RAISERROR('Can only extend PROPOSED or ACTIVE programs.', 16, 1);
        RETURN -1;
    END
    
    IF @new_end_utc <= @current_end
    BEGIN
        RAISERROR('New end time must be after current end time.', 16, 1);
        RETURN -1;
    END
    
    BEGIN TRANSACTION;
    
    -- Update program
    UPDATE dbo.ntml
    SET end_utc = @new_end_utc,
        cumulative_end = @new_end_utc,
        comments = CASE WHEN @comments IS NOT NULL 
                        THEN ISNULL(comments + CHAR(13) + CHAR(10), '') + @comments
                        ELSE comments END,
        revision_number = revision_number + 1,
        modified_by = @extended_by,
        modified_utc = SYSUTCDATETIME()
    WHERE program_id = @program_id;
    
    -- Update all controlled flights with new release time
    UPDATE dbo.adl_flight_tmi
    SET gs_release_utc = @new_end_utc,
        tmi_updated_utc = SYSUTCDATETIME()
    WHERE program_id = @program_id
      AND gs_held = 1;
    
    -- Log event
    INSERT INTO dbo.ntml_info (program_id, event_type, event_message, 
                               event_details_json, performed_by)
    VALUES (@program_id, 'PROGRAM_EXTENDED',
            'Ground Stop extended from ' + FORMAT(@current_end, 'ddHHmm') + 'Z to ' + 
            FORMAT(@new_end_utc, 'ddHHmm') + 'Z',
            (SELECT @current_end AS previous_end_utc, @new_end_utc AS new_end_utc FOR JSON PATH, WITHOUT_ARRAY_WRAPPER),
            @extended_by);
    
    COMMIT;
    
    RETURN 0;
END
GO

-- ============================================================================
-- sp_GS_Purge - Cancel/Purge a Ground Stop
-- ============================================================================

CREATE OR ALTER PROCEDURE dbo.sp_GS_Purge
    @program_id         INT,
    @purged_by          NVARCHAR(64) = NULL,
    @purge_reason       NVARCHAR(256) = NULL
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;
    
    DECLARE @status NVARCHAR(16), @ctl_element NVARCHAR(8);
    
    SELECT @status = status, @ctl_element = ctl_element
    FROM dbo.ntml
    WHERE program_id = @program_id AND program_type = 'GS';
    
    IF @status IS NULL
    BEGIN
        RAISERROR('Ground Stop program not found.', 16, 1);
        RETURN -1;
    END
    
    IF @status IN ('PURGED', 'COMPLETED')
    BEGIN
        RAISERROR('Program is already %s.', 16, 1, @status);
        RETURN -1;
    END
    
    BEGIN TRANSACTION;
    
    -- Update program status
    UPDATE dbo.ntml
    SET status = 'PURGED',
        is_proposed = 0,
        is_active = 0,
        purged_utc = SYSUTCDATETIME(),
        purged_by = @purged_by,
        modified_by = @purged_by,
        modified_utc = SYSUTCDATETIME()
    WHERE program_id = @program_id;
    
    -- Release all controlled flights
    UPDATE dbo.adl_flight_tmi
    SET gs_held = 0,
        gs_release_utc = NULL,
        tmi_updated_utc = SYSUTCDATETIME()
    WHERE program_id = @program_id;
    
    -- Log event
    INSERT INTO dbo.ntml_info (program_id, event_type, event_message, 
                               event_details_json, performed_by)
    VALUES (@program_id, 'PROGRAM_PURGED',
            'Ground Stop for ' + @ctl_element + ' has been purged.',
            (SELECT @purge_reason AS reason FOR JSON PATH, WITHOUT_ARRAY_WRAPPER),
            @purged_by);
    
    COMMIT;
    
    RETURN 0;
END
GO

-- ============================================================================
-- sp_GS_GetFlights - Get flights affected by a Ground Stop
-- ============================================================================

CREATE OR ALTER PROCEDURE dbo.sp_GS_GetFlights
    @program_id         INT,
    @include_exempt     BIT = 1,
    @include_airborne   BIT = 1
AS
BEGIN
    SET NOCOUNT ON;
    
    SELECT
        c.flight_uid,
        c.callsign,
        c.phase,

        -- Origin/Destination
        fp.fp_dept_icao AS orig,
        fp.fp_dest_icao AS dest,
        fp.fp_dept_artcc,
        fp.fp_dest_artcc,
        
        -- Times
        ft.etd_runway_utc AS etd,
        ft.eta_runway_utc AS eta,
        ft.ete_minutes,
        
        -- TMI Status
        tmi.program_id,
        tmi.ctl_type,
        tmi.gs_held,
        tmi.gs_release_utc,
        tmi.ctl_exempt,
        tmi.ctl_exempt_reason,
        tmi.assigned_utc,
        
        -- Aircraft
        a.aircraft_icao,
        a.weight_class,
        a.airline_icao,
        a.airline_name,
        
        -- Position
        pos.lat,
        pos.lon,
        pos.dist_to_dest_nm
        
    FROM dbo.adl_flight_tmi tmi
    INNER JOIN dbo.adl_flight_core c ON c.flight_uid = tmi.flight_uid
    INNER JOIN dbo.adl_flight_plan fp ON fp.flight_uid = tmi.flight_uid
    INNER JOIN dbo.adl_flight_times ft ON ft.flight_uid = tmi.flight_uid
    LEFT JOIN dbo.adl_flight_aircraft a ON a.flight_uid = tmi.flight_uid
    LEFT JOIN dbo.adl_flight_position pos ON pos.flight_uid = tmi.flight_uid
    WHERE tmi.program_id = @program_id
      AND (@include_exempt = 1 OR tmi.ctl_exempt = 0)
      AND (@include_airborne = 1 OR tmi.ctl_exempt_reason <> 'AIRBORNE' OR tmi.ctl_exempt_reason IS NULL)
    ORDER BY 
        tmi.gs_held DESC,           -- Controlled flights first
        ft.eta_runway_utc ASC;      -- Then by ETA
END
GO

-- ============================================================================
-- sp_GS_DetectPopups - Detect new pop-up flights during active GS
-- Called by VATSIM daemon during active GS programs
-- ============================================================================

CREATE OR ALTER PROCEDURE dbo.sp_GS_DetectPopups
    @program_id         INT,
    @dep_facilities     NVARCHAR(MAX) = NULL              -- Space-delimited ARTCC list
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;
    
    DECLARE @ctl_element NVARCHAR(8), @start_utc DATETIME2(0), @end_utc DATETIME2(0);
    DECLARE @status NVARCHAR(16), @exempt_airborne BIT;
    
    SELECT 
        @ctl_element = ctl_element,
        @start_utc = start_utc,
        @end_utc = end_utc,
        @status = status,
        @exempt_airborne = exempt_airborne
    FROM dbo.ntml
    WHERE program_id = @program_id AND program_type = 'GS';
    
    IF @status <> 'ACTIVE'
    BEGIN
        -- Not an active program, nothing to do
        RETURN 0;
    END
    
    -- Parse dep_facilities into a table variable
    DECLARE @included_centers TABLE (artcc NVARCHAR(4));
    IF @dep_facilities IS NOT NULL AND LEN(TRIM(@dep_facilities)) > 0
    BEGIN
        INSERT INTO @included_centers (artcc)
        SELECT UPPER(TRIM(value))
        FROM STRING_SPLIT(@dep_facilities, ' ')
        WHERE LEN(TRIM(value)) > 0;
    END
    
    -- Find new flights not yet assigned to this program
    ;WITH new_flights AS (
        SELECT 
            c.flight_uid,
            c.callsign,
            fp.fp_dept_artcc,
            ft.eta_runway_utc,
            CASE 
                WHEN c.phase IN ('departed', 'enroute', 'descending') THEN 1
                ELSE 0
            END AS is_airborne
        FROM dbo.adl_flight_core c
        INNER JOIN dbo.adl_flight_plan fp ON fp.flight_uid = c.flight_uid
        INNER JOIN dbo.adl_flight_times ft ON ft.flight_uid = c.flight_uid
        LEFT JOIN dbo.adl_flight_tmi tmi ON tmi.flight_uid = c.flight_uid
        WHERE c.is_active = 1
          AND fp.fp_dest_icao = @ctl_element
          AND ft.eta_runway_utc >= SYSUTCDATETIME()
          AND ft.eta_runway_utc <= @end_utc
          AND (tmi.program_id IS NULL OR tmi.program_id <> @program_id)  -- Not already assigned
          AND c.first_seen_utc > @start_utc                               -- Appeared after GS start
    )
    INSERT INTO dbo.adl_flight_tmi (
        flight_uid, program_id, ctl_type, ctl_element, ctl_prgm,
        gs_held, gs_release_utc, is_popup, popup_detected_utc,
        ctl_exempt, ctl_exempt_reason, tmi_updated_utc
    )
    SELECT 
        nf.flight_uid, @program_id, 'GS', @ctl_element,
        (SELECT program_name FROM dbo.ntml WHERE program_id = @program_id),
        CASE WHEN nf.is_airborne = 1 AND @exempt_airborne = 1 THEN 0 ELSE 1 END,
        CASE WHEN nf.is_airborne = 1 AND @exempt_airborne = 1 THEN NULL ELSE @end_utc END,
        1,  -- is_popup
        SYSUTCDATETIME(),
        CASE WHEN nf.is_airborne = 1 AND @exempt_airborne = 1 THEN 1 ELSE 0 END,
        CASE WHEN nf.is_airborne = 1 AND @exempt_airborne = 1 THEN 'AIRBORNE' ELSE NULL END,
        SYSUTCDATETIME()
    FROM new_flights nf
    WHERE (NOT EXISTS (SELECT 1 FROM @included_centers) OR nf.fp_dept_artcc IN (SELECT artcc FROM @included_centers));
    
    DECLARE @popups_found INT = @@ROWCOUNT;
    
    IF @popups_found > 0
    BEGIN
        -- Update program metrics
        UPDATE p SET
            total_flights = total_flights + @popups_found,
            controlled_flights = controlled_flights + 
                (SELECT COUNT(*) FROM dbo.adl_flight_tmi 
                 WHERE program_id = @program_id AND is_popup = 1 AND gs_held = 1 
                   AND popup_detected_utc >= DATEADD(SECOND, -5, SYSUTCDATETIME())),
            modified_utc = SYSUTCDATETIME()
        FROM dbo.ntml p
        WHERE p.program_id = @program_id;
        
        -- Log popup detection
        INSERT INTO dbo.ntml_info (program_id, event_type, event_message,
                                   event_details_json, performed_by)
        VALUES (@program_id, 'FLIGHT_POPUP',
                CAST(@popups_found AS NVARCHAR) + ' pop-up flight(s) detected and controlled.',
                (SELECT @popups_found AS count FOR JSON PATH, WITHOUT_ARRAY_WRAPPER),
                'SYSTEM');
    END
    
    RETURN @popups_found;
END
GO

PRINT '';
PRINT '=== Ground Stop Procedures Created ===';
PRINT 'Finished at: ' + CONVERT(VARCHAR, GETUTCDATE(), 120);
GO
