-- ============================================================================
-- VATSIM_TMI Migration 015: Update Ground Stop Procedure for EDCT/CTD Calculation
-- Calculates CTD, CTA, and delay metrics for Ground Stop simulations
-- ============================================================================
-- Version: 1.0.0
-- Date: 2026-01-28
-- Author: HP/Claude
--
-- Issue: GS simulate was not assigning EDCTs to flights
-- Fix: Calculate CTD based on GS end time and delay as difference from original ETD
-- ============================================================================

USE VATSIM_TMI;
GO

-- ============================================================================
-- sp_TMI_ApplyGroundStop (Updated)
-- Apply ground stop to flights destined for control element
-- Now calculates CTD, CTA, and delay metrics
-- ============================================================================
IF OBJECT_ID('dbo.sp_TMI_ApplyGroundStop', 'P') IS NOT NULL
    DROP PROCEDURE dbo.sp_TMI_ApplyGroundStop;
GO

CREATE PROCEDURE dbo.sp_TMI_ApplyGroundStop
    @program_id         INT,
    @flights            dbo.FlightListType READONLY,
    @held_count         INT OUTPUT,
    @exempt_count       INT OUTPUT
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @ctl_element NVARCHAR(8);
    DECLARE @program_type NVARCHAR(16);
    DECLARE @start_utc DATETIME2(0);
    DECLARE @end_utc DATETIME2(0);

    SELECT
        @ctl_element = ctl_element,
        @program_type = program_type,
        @start_utc = start_utc,
        @end_utc = end_utc
    FROM dbo.tmi_programs
    WHERE program_id = @program_id;

    IF @program_type != 'GS'
    BEGIN
        RAISERROR('Program is not a Ground Stop: %d', 16, 1, @program_id);
        RETURN 1;
    END

    SET @held_count = 0;
    SET @exempt_count = 0;

    -- Clear existing assignments
    DELETE FROM dbo.tmi_flight_control WHERE program_id = @program_id;

    -- Insert ground-stopped flights with CTD/CTA calculations
    -- For held flights: CTD = GS end time (when they can actually depart)
    -- For exempt flights: CTD = original ETD (no change)
    -- Delay = difference between CTD and original ETD
    INSERT INTO dbo.tmi_flight_control (
        flight_uid, callsign, program_id,
        ctl_elem, ctl_type,
        ctl_exempt, ctl_exempt_reason,
        gs_held, gs_release_utc,
        orig_eta_utc, orig_etd_utc,
        ctd_utc, cta_utc,
        program_delay_min,
        dep_airport, arr_airport, dep_center, arr_center,
        flight_status_at_ctl, control_assigned_utc
    )
    SELECT
        f.flight_uid,
        f.callsign,
        @program_id,
        @ctl_element,
        'GS',
        f.is_exempt,
        f.exempt_reason,
        CASE WHEN f.is_exempt = 1 THEN 0 ELSE 1 END,
        @end_utc,  -- Release at GS end
        f.eta_utc,
        f.etd_utc,
        -- CTD: For held flights, use GS end time; for exempt, use original ETD
        CASE
            WHEN f.is_exempt = 1 THEN f.etd_utc
            WHEN f.etd_utc >= @end_utc THEN f.etd_utc  -- ETD already after GS ends
            ELSE @end_utc  -- ETD during GS, hold until GS ends
        END AS ctd_utc,
        -- CTA: CTD + en-route time (approximate using ETA-ETD difference)
        CASE
            WHEN f.is_exempt = 1 THEN f.eta_utc
            WHEN f.etd_utc >= @end_utc THEN f.eta_utc  -- No delay
            ELSE DATEADD(MINUTE, DATEDIFF(MINUTE, f.etd_utc, f.eta_utc), @end_utc)
        END AS cta_utc,
        -- Delay: Difference between CTD and original ETD (in minutes)
        CASE
            WHEN f.is_exempt = 1 THEN 0
            WHEN f.etd_utc >= @end_utc THEN 0  -- No delay if ETD already after GS
            ELSE DATEDIFF(MINUTE, f.etd_utc, @end_utc)
        END AS program_delay_min,
        f.dep_airport,
        f.arr_airport,
        f.dep_center,
        f.arr_center,
        f.flight_status,
        SYSUTCDATETIME()
    FROM @flights f
    WHERE f.eta_utc BETWEEN @start_utc AND DATEADD(HOUR, 6, @end_utc);  -- Include flights ETA within 6hrs after GS end

    -- Count results
    SELECT
        @held_count = SUM(CASE WHEN gs_held = 1 THEN 1 ELSE 0 END),
        @exempt_count = SUM(CASE WHEN ctl_exempt = 1 THEN 1 ELSE 0 END)
    FROM dbo.tmi_flight_control
    WHERE program_id = @program_id;

    -- Calculate delay metrics
    DECLARE @avg_delay DECIMAL(8,2);
    DECLARE @max_delay INT;
    DECLARE @total_delay INT;

    SELECT
        @avg_delay = ISNULL(AVG(CAST(program_delay_min AS DECIMAL(8,2))), 0),
        @max_delay = ISNULL(MAX(program_delay_min), 0),
        @total_delay = ISNULL(SUM(program_delay_min), 0)
    FROM dbo.tmi_flight_control
    WHERE program_id = @program_id
      AND ctl_exempt = 0
      AND program_delay_min IS NOT NULL;

    -- Update program metrics
    UPDATE dbo.tmi_programs
    SET total_flights = @held_count + @exempt_count,
        controlled_flights = @held_count,
        exempt_flights = @exempt_count,
        avg_delay_min = @avg_delay,
        max_delay_min = @max_delay,
        total_delay_min = @total_delay,
        modified_utc = SYSUTCDATETIME()
    WHERE program_id = @program_id;

    -- Event logging removed - tmi_events table may not exist in current schema

    RETURN 0;
END;
GO

PRINT 'Migration 015: Updated sp_TMI_ApplyGroundStop with EDCT/CTD calculation';
GO
