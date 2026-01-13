-- ============================================================================
-- FIX: sp_GS_GetFlights - Comprehensive ETD fallback chain + gs_status
-- 
-- Problem: ETD is null because etd_runway_utc isn't populated for VATSIM flights
-- Solution: Use full fallback chain: etd_runway_utc -> departure_bucket_utc -> epoch conversion
-- Note: etd_epoch/eta_epoch are Unix timestamps (SECONDS since 1970), not milliseconds
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
    
    -- Get program status for gs_status field
    DECLARE @program_status NVARCHAR(16);
    SELECT @program_status = status FROM dbo.ntml WHERE program_id = @program_id;
    
    SELECT
        c.flight_uid,
        c.callsign,
        c.phase,

        -- Origin/Destination
        fp.fp_dept_icao AS orig,
        fp.fp_dest_icao AS dest,
        fp.fp_dept_artcc,
        fp.fp_dest_artcc,
        
        -- ==================================================================
        -- ETD: Best available departure time using full fallback chain
        -- Priority: etd_runway_utc > departure_bucket_utc > etd_epoch conversion
        -- ==================================================================
        COALESCE(
            ft.etd_runway_utc, 
            ft.departure_bucket_utc,
            -- Convert Unix timestamp (seconds since 1970) to datetime
            CASE WHEN ft.etd_epoch IS NOT NULL AND ft.etd_epoch > 0
                 THEN DATEADD(SECOND, ft.etd_epoch, '1970-01-01T00:00:00Z')
                 ELSE NULL 
            END
        ) AS oetd_utc,
        
        -- ETA: Best available arrival time
        COALESCE(
            ft.eta_runway_utc,
            ft.arrival_bucket_utc,
            CASE WHEN ft.eta_epoch IS NOT NULL AND ft.eta_epoch > 0
                 THEN DATEADD(SECOND, ft.eta_epoch, '1970-01-01T00:00:00Z')
                 ELSE NULL 
            END
        ) AS oeta_utc,
        
        -- Current times WITH _utc suffix (JS compatibility)
        COALESCE(
            ft.etd_runway_utc, 
            ft.departure_bucket_utc,
            CASE WHEN ft.etd_epoch IS NOT NULL AND ft.etd_epoch > 0
                 THEN DATEADD(SECOND, ft.etd_epoch, '1970-01-01T00:00:00Z')
                 ELSE NULL 
            END
        ) AS etd_utc,
        
        COALESCE(
            ft.eta_runway_utc,
            ft.arrival_bucket_utc,
            CASE WHEN ft.eta_epoch IS NOT NULL AND ft.eta_epoch > 0
                 THEN DATEADD(SECOND, ft.eta_epoch, '1970-01-01T00:00:00Z')
                 ELSE NULL 
            END
        ) AS eta_utc,
        
        -- Non-suffixed versions for backward compatibility
        COALESCE(
            ft.etd_runway_utc, 
            ft.departure_bucket_utc,
            CASE WHEN ft.etd_epoch IS NOT NULL AND ft.etd_epoch > 0
                 THEN DATEADD(SECOND, ft.etd_epoch, '1970-01-01T00:00:00Z')
                 ELSE NULL 
            END
        ) AS etd,
        
        COALESCE(
            ft.eta_runway_utc,
            ft.arrival_bucket_utc,
            CASE WHEN ft.eta_epoch IS NOT NULL AND ft.eta_epoch > 0
                 THEN DATEADD(SECOND, ft.eta_epoch, '1970-01-01T00:00:00Z')
                 ELSE NULL 
            END
        ) AS eta,
        
        ft.ete_minutes,
        
        -- ==================================================================
        -- Controlled Times (GS-specific)
        -- CTD = Release time for held flights, else original ETD
        -- ==================================================================
        CASE 
            WHEN tmi.gs_held = 1 AND tmi.gs_release_utc IS NOT NULL 
            THEN tmi.gs_release_utc
            ELSE COALESCE(
                ft.etd_runway_utc, 
                ft.departure_bucket_utc,
                CASE WHEN ft.etd_epoch IS NOT NULL AND ft.etd_epoch > 0
                     THEN DATEADD(SECOND, ft.etd_epoch, '1970-01-01T00:00:00Z')
                     ELSE NULL 
                END
            )
        END AS ctd_utc,
        
        -- CTA = Original ETA + delay minutes (if held)
        CASE 
            WHEN tmi.gs_held = 1 AND tmi.gs_release_utc IS NOT NULL 
            THEN DATEADD(MINUTE, 
                    DATEDIFF(MINUTE, 
                        COALESCE(ft.etd_runway_utc, ft.departure_bucket_utc,
                            CASE WHEN ft.etd_epoch IS NOT NULL AND ft.etd_epoch > 0
                                 THEN DATEADD(SECOND, ft.etd_epoch, '1970-01-01T00:00:00Z')
                                 ELSE NULL END), 
                        tmi.gs_release_utc),
                    COALESCE(ft.eta_runway_utc, ft.arrival_bucket_utc,
                        CASE WHEN ft.eta_epoch IS NOT NULL AND ft.eta_epoch > 0
                             THEN DATEADD(SECOND, ft.eta_epoch, '1970-01-01T00:00:00Z')
                             ELSE NULL END))
            ELSE COALESCE(ft.eta_runway_utc, ft.arrival_bucket_utc,
                CASE WHEN ft.eta_epoch IS NOT NULL AND ft.eta_epoch > 0
                     THEN DATEADD(SECOND, ft.eta_epoch, '1970-01-01T00:00:00Z')
                     ELSE NULL END)
        END AS cta_utc,
        
        -- EDCT = CTD for GS (controlled departure time)
        CASE 
            WHEN tmi.gs_held = 1 AND tmi.gs_release_utc IS NOT NULL 
            THEN tmi.gs_release_utc
            ELSE NULL
        END AS edct_utc,
        
        -- ==================================================================
        -- Delay Calculation
        -- ==================================================================
        CASE 
            WHEN tmi.gs_held = 1 AND tmi.gs_release_utc IS NOT NULL 
                 AND COALESCE(ft.etd_runway_utc, ft.departure_bucket_utc,
                     CASE WHEN ft.etd_epoch IS NOT NULL AND ft.etd_epoch > 0
                          THEN DATEADD(SECOND, ft.etd_epoch, '1970-01-01T00:00:00Z')
                          ELSE NULL END) IS NOT NULL
            THEN DATEDIFF(MINUTE, 
                    COALESCE(ft.etd_runway_utc, ft.departure_bucket_utc,
                        CASE WHEN ft.etd_epoch IS NOT NULL AND ft.etd_epoch > 0
                             THEN DATEADD(SECOND, ft.etd_epoch, '1970-01-01T00:00:00Z')
                             ELSE NULL END), 
                    tmi.gs_release_utc)
            ELSE 0
        END AS program_delay_min,
        
        -- Delay status for UI
        CASE 
            WHEN tmi.ctl_exempt = 1 THEN 'EXEMPT'
            WHEN tmi.gs_held = 1 THEN 'GS'
            ELSE 'UNCONTROLLED'
        END AS delay_status,
        
        -- ==================================================================
        -- GS Status for demand chart (reflects program phase)
        -- ==================================================================
        CASE 
            WHEN tmi.gs_held = 1 THEN
                CASE @program_status
                    WHEN 'PROPOSED' THEN 'PROPOSED_GS'
                    WHEN 'ACTIVE' THEN 
                        CASE WHEN tmi.assigned_utc IS NOT NULL THEN 'ACTUAL_GS' ELSE 'SIMULATED_GS' END
                    ELSE 'GS'
                END
            WHEN tmi.ctl_exempt = 1 THEN 'EXEMPT'
            ELSE c.phase
        END AS gs_status,
        
        -- TMI Status fields
        tmi.program_id,
        tmi.ctl_type,
        tmi.gs_held,
        tmi.gs_release_utc,
        tmi.ctl_exempt,
        tmi.ctl_exempt_reason,
        tmi.assigned_utc,
        
        -- Aircraft info
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
        tmi.gs_held DESC,
        COALESCE(ft.eta_runway_utc, ft.arrival_bucket_utc,
            CASE WHEN ft.eta_epoch IS NOT NULL AND ft.eta_epoch > 0
                 THEN DATEADD(SECOND, ft.eta_epoch, '1970-01-01T00:00:00Z')
                 ELSE NULL END) ASC;
END
GO

PRINT 'Updated sp_GS_GetFlights with comprehensive ETD fallbacks (epoch=seconds) and gs_status';
GO
