-- ============================================================================
-- FIX: sp_GS_GetFlights - Add missing time fields and proper naming
-- 
-- Issues Fixed:
--   1. Field names now include _utc suffix (etd_utc, eta_utc) for JS compatibility
--   2. Added OETD/OETA columns (original times before control)
--   3. Added CTD (Controlled Time Departure) = gs_release_utc for GS
--   4. Added CTA (Controlled Time Arrival) = ETA + delay adjustment
--   5. Added program_delay_min calculation
--   6. Added delay_status column for UI display
--
-- Date: 2026-01-13
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

        -- Origin/Destination (keep existing names for backward compat)
        fp.fp_dept_icao AS orig,
        fp.fp_dest_icao AS dest,
        fp.fp_dept_artcc,
        fp.fp_dest_artcc,
        
        -- Original times (OETD/OETA) - times before any TMI control
        ft.etd_runway_utc AS oetd_utc,
        ft.eta_runway_utc AS oeta_utc,
        
        -- Current base times WITH _utc suffix (JS compatibility)
        ft.etd_runway_utc AS etd_utc,
        ft.eta_runway_utc AS eta_utc,
        
        -- Also keep non-suffixed versions for any other consumers
        ft.etd_runway_utc AS etd,
        ft.eta_runway_utc AS eta,
        ft.ete_minutes,
        
        -- Controlled times (GS-specific logic)
        -- CTD = for held flights, this is the gs_release_utc (when they can depart)
        --       for exempt flights, CTD = ETD (no delay)
        CASE 
            WHEN tmi.gs_held = 1 AND tmi.gs_release_utc IS NOT NULL 
            THEN tmi.gs_release_utc
            ELSE ft.etd_runway_utc  -- Exempt flights: no delay
        END AS ctd_utc,
        
        -- CTA = Original ETA + delay (if held)
        --       For exempt flights, CTA = ETA
        CASE 
            WHEN tmi.gs_held = 1 AND tmi.gs_release_utc IS NOT NULL AND ft.etd_runway_utc IS NOT NULL
            THEN DATEADD(MINUTE, 
                    DATEDIFF(MINUTE, ft.etd_runway_utc, tmi.gs_release_utc),
                    ft.eta_runway_utc)
            ELSE ft.eta_runway_utc  -- Exempt flights: no delay
        END AS cta_utc,
        
        -- EDCT - same as CTD for GS (controlled departure time)
        CASE 
            WHEN tmi.gs_held = 1 AND tmi.gs_release_utc IS NOT NULL 
            THEN tmi.gs_release_utc
            ELSE NULL  -- Exempt flights don't have EDCT
        END AS edct_utc,
        
        -- Delay calculation in minutes
        CASE 
            WHEN tmi.gs_held = 1 AND tmi.gs_release_utc IS NOT NULL AND ft.etd_runway_utc IS NOT NULL
            THEN DATEDIFF(MINUTE, ft.etd_runway_utc, tmi.gs_release_utc)
            ELSE 0
        END AS program_delay_min,
        
        -- Delay status for UI display
        CASE 
            WHEN tmi.ctl_exempt = 1 THEN 'EXEMPT'
            WHEN tmi.gs_held = 1 THEN 'GS'
            ELSE 'UNCONTROLLED'
        END AS delay_status,
        
        -- TMI Status (existing fields)
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

PRINT 'Updated sp_GS_GetFlights with proper field names and calculated times';
GO
