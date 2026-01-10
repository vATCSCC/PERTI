-- ============================================================================
-- GDT Views
-- 
-- Views for GDT API queries combining normalized ADL tables
-- ============================================================================

SET ANSI_NULLS ON;
SET QUOTED_IDENTIFIER ON;
GO

PRINT '=== GDT Views Migration ===';
GO

-- ============================================================================
-- vw_GDT_FlightList - Complete flight information for GDT displays
-- ============================================================================

CREATE OR ALTER VIEW dbo.vw_GDT_FlightList AS
SELECT
    -- Core flight info
    c.flight_uid,
    c.callsign,
    c.cid,
    c.flight_status,
    c.phase,
    c.is_active,
    c.first_seen_utc,
    c.last_seen_utc,
    
    -- Origin/Destination
    fp.fp_dept_icao AS orig,
    fp.fp_dest_icao AS dest,
    fp.fp_dept_artcc AS dept_artcc,
    fp.fp_dest_artcc AS dest_artcc,
    fp.fp_dept_tracon AS dept_tracon,
    fp.fp_dest_tracon AS dest_tracon,
    fp.dfix,
    fp.afix,
    
    -- Flight plan
    fp.fp_route,
    fp.fp_altitude_ft AS filed_alt,
    fp.aircraft_type,
    
    -- Times
    ft.std_utc,
    ft.etd_utc,
    ft.etd_runway_utc AS etd,
    ft.sta_utc,
    ft.eta_utc,
    ft.eta_runway_utc AS eta,
    ft.ete_minutes,
    ft.arrival_bucket_utc,
    ft.departure_bucket_utc,
    
    -- Actual times (OOOI)
    ft.out_utc,
    ft.off_utc,
    ft.on_utc,
    ft.in_utc,
    
    -- TMI Assignment
    tmi.program_id,
    tmi.ctl_type,
    tmi.ctl_element,
    tmi.ctl_prgm,
    tmi.aslot,
    tmi.ctd_utc AS ctd,
    tmi.cta_utc AS cta,
    tmi.octd_utc,
    tmi.octa_utc,
    tmi.edct_utc AS edct,
    tmi.program_delay_min,
    tmi.delay_capped,
    tmi.ctl_exempt,
    tmi.ctl_exempt_reason,
    
    -- Ground Stop specific
    tmi.gs_held,
    tmi.gs_release_utc,
    
    -- Slot management
    tmi.sl_hold,
    tmi.subbable,
    
    -- Pop-up tracking
    tmi.is_popup,
    tmi.is_recontrol,
    tmi.popup_detected_utc,
    
    -- ECR
    tmi.ecr_pending,
    tmi.ecr_requested_cta,
    
    -- Cancel flags
    tmi.ux_cancelled,
    tmi.fx_cancelled,
    tmi.rz_removed,
    
    tmi.assigned_utc,
    
    -- Aircraft
    a.aircraft_icao,
    a.aircraft_faa,
    a.weight_class,
    a.engine_type,
    a.wake_category,
    a.airline_icao,
    a.airline_name,
    
    -- Position
    pos.lat,
    pos.lon,
    pos.altitude_ft,
    pos.groundspeed_kts,
    pos.heading_deg,
    pos.dist_to_dest_nm,
    pos.dist_flown_nm,
    pos.pct_complete,
    
    -- Derived fields
    CASE 
        WHEN c.phase IN ('departed', 'enroute', 'descending') THEN 1
        WHEN c.flight_status = 'A' THEN 1
        ELSE 0
    END AS is_airborne,
    
    CASE
        WHEN tmi.gs_held = 1 THEN 'GS_HELD'
        WHEN tmi.ctl_type = 'GS' AND tmi.ctl_exempt = 1 THEN 'GS_EXEMPT'
        WHEN tmi.ctl_type LIKE 'GDP%' THEN 'GDP_CTRL'
        WHEN tmi.ctl_type IS NOT NULL THEN tmi.ctl_type
        ELSE 'UNCONTROLLED'
    END AS tmi_status,
    
    -- Minutes to ETD/ETA
    DATEDIFF(MINUTE, SYSUTCDATETIME(), ft.etd_runway_utc) AS min_to_etd,
    DATEDIFF(MINUTE, SYSUTCDATETIME(), ft.eta_runway_utc) AS min_to_eta

FROM dbo.adl_flight_core c
LEFT JOIN dbo.adl_flight_plan fp ON fp.flight_uid = c.flight_uid
LEFT JOIN dbo.adl_flight_times ft ON ft.flight_uid = c.flight_uid
LEFT JOIN dbo.adl_flight_tmi tmi ON tmi.flight_uid = c.flight_uid
LEFT JOIN dbo.adl_flight_aircraft a ON a.flight_uid = c.flight_uid
LEFT JOIN dbo.adl_flight_position pos ON pos.flight_uid = c.flight_uid
WHERE c.is_active = 1;
GO

PRINT 'Created view dbo.vw_GDT_FlightList';
GO

-- ============================================================================
-- vw_GDT_DemandByQuarter - Demand by 15-minute bins for bar graphs
-- ============================================================================

CREATE OR ALTER VIEW dbo.vw_GDT_DemandByQuarter AS
SELECT
    fp.fp_dest_icao AS airport,
    ft.arrival_bucket_utc AS bucket_utc,
    DATEPART(HOUR, ft.arrival_bucket_utc) AS bucket_hour,
    DATEPART(MINUTE, ft.arrival_bucket_utc) AS bucket_quarter,
    
    -- Demand counts
    COUNT(*) AS total_demand,
    
    -- By control status
    SUM(CASE WHEN tmi.ctl_type IS NOT NULL AND tmi.ctl_exempt = 0 THEN 1 ELSE 0 END) AS controlled,
    SUM(CASE WHEN tmi.ctl_exempt = 1 THEN 1 ELSE 0 END) AS exempt,
    SUM(CASE WHEN tmi.gs_held = 1 THEN 1 ELSE 0 END) AS ground_stopped,
    SUM(CASE WHEN tmi.ctl_type IS NULL THEN 1 ELSE 0 END) AS uncontrolled,
    
    -- By flight status
    SUM(CASE WHEN c.phase IN ('departed', 'enroute', 'descending') THEN 1 ELSE 0 END) AS airborne,
    SUM(CASE WHEN c.phase IN ('prefile', 'taxiing') OR c.phase IS NULL THEN 1 ELSE 0 END) AS on_ground,
    
    -- By weight class
    SUM(CASE WHEN a.weight_class = 'H' OR a.weight_class = 'J' THEN 1 ELSE 0 END) AS heavy,
    SUM(CASE WHEN a.weight_class = 'L' THEN 1 ELSE 0 END) AS large,
    SUM(CASE WHEN a.weight_class = 'S' THEN 1 ELSE 0 END) AS small,
    
    -- Delay metrics (for GDP)
    AVG(CAST(tmi.program_delay_min AS FLOAT)) AS avg_delay_min,
    MAX(tmi.program_delay_min) AS max_delay_min,
    SUM(CAST(tmi.program_delay_min AS BIGINT)) AS total_delay_min

FROM dbo.adl_flight_core c
INNER JOIN dbo.adl_flight_plan fp ON fp.flight_uid = c.flight_uid
INNER JOIN dbo.adl_flight_times ft ON ft.flight_uid = c.flight_uid
LEFT JOIN dbo.adl_flight_tmi tmi ON tmi.flight_uid = c.flight_uid
LEFT JOIN dbo.adl_flight_aircraft a ON a.flight_uid = c.flight_uid
WHERE c.is_active = 1
  AND ft.arrival_bucket_utc IS NOT NULL
GROUP BY 
    fp.fp_dest_icao, 
    ft.arrival_bucket_utc,
    DATEPART(HOUR, ft.arrival_bucket_utc),
    DATEPART(MINUTE, ft.arrival_bucket_utc);
GO

PRINT 'Created view dbo.vw_GDT_DemandByQuarter';
GO

-- ============================================================================
-- vw_GDT_DemandByHour - Hourly demand aggregation
-- ============================================================================

CREATE OR ALTER VIEW dbo.vw_GDT_DemandByHour AS
SELECT
    fp.fp_dest_icao AS airport,
    DATEADD(HOUR, DATEDIFF(HOUR, 0, ft.eta_runway_utc), 0) AS hour_utc,
    DATEPART(HOUR, ft.eta_runway_utc) AS hour_of_day,
    
    COUNT(*) AS total_demand,
    SUM(CASE WHEN tmi.ctl_type IS NOT NULL AND tmi.ctl_exempt = 0 THEN 1 ELSE 0 END) AS controlled,
    SUM(CASE WHEN tmi.ctl_exempt = 1 THEN 1 ELSE 0 END) AS exempt,
    SUM(CASE WHEN tmi.gs_held = 1 THEN 1 ELSE 0 END) AS ground_stopped,
    SUM(CASE WHEN c.phase IN ('departed', 'enroute', 'descending') THEN 1 ELSE 0 END) AS airborne,
    SUM(CASE WHEN c.phase IN ('prefile', 'taxiing') OR c.phase IS NULL THEN 1 ELSE 0 END) AS on_ground,
    
    AVG(CAST(tmi.program_delay_min AS FLOAT)) AS avg_delay_min,
    MAX(tmi.program_delay_min) AS max_delay_min

FROM dbo.adl_flight_core c
INNER JOIN dbo.adl_flight_plan fp ON fp.flight_uid = c.flight_uid
INNER JOIN dbo.adl_flight_times ft ON ft.flight_uid = c.flight_uid
LEFT JOIN dbo.adl_flight_tmi tmi ON tmi.flight_uid = c.flight_uid
WHERE c.is_active = 1
  AND ft.eta_runway_utc IS NOT NULL
GROUP BY 
    fp.fp_dest_icao, 
    DATEADD(HOUR, DATEDIFF(HOUR, 0, ft.eta_runway_utc), 0),
    DATEPART(HOUR, ft.eta_runway_utc);
GO

PRINT 'Created view dbo.vw_GDT_DemandByHour';
GO

-- ============================================================================
-- vw_GDT_DemandByCenter - Demand by origin ARTCC (for scope analysis)
-- ============================================================================

CREATE OR ALTER VIEW dbo.vw_GDT_DemandByCenter AS
SELECT
    fp.fp_dest_icao AS airport,
    fp.fp_dept_artcc AS origin_artcc,
    
    COUNT(*) AS total_flights,
    SUM(CASE WHEN tmi.ctl_exempt = 0 OR tmi.ctl_exempt IS NULL THEN 1 ELSE 0 END) AS non_exempt,
    SUM(CASE WHEN tmi.ctl_exempt = 1 THEN 1 ELSE 0 END) AS exempt,
    SUM(CASE WHEN c.phase IN ('departed', 'enroute', 'descending') THEN 1 ELSE 0 END) AS airborne,
    
    MIN(ft.eta_runway_utc) AS earliest_eta,
    MAX(ft.eta_runway_utc) AS latest_eta

FROM dbo.adl_flight_core c
INNER JOIN dbo.adl_flight_plan fp ON fp.flight_uid = c.flight_uid
INNER JOIN dbo.adl_flight_times ft ON ft.flight_uid = c.flight_uid
LEFT JOIN dbo.adl_flight_tmi tmi ON tmi.flight_uid = c.flight_uid
WHERE c.is_active = 1
  AND fp.fp_dept_artcc IS NOT NULL
GROUP BY 
    fp.fp_dest_icao, 
    fp.fp_dept_artcc;
GO

PRINT 'Created view dbo.vw_GDT_DemandByCenter';
GO

-- ============================================================================
-- vw_NTML_Active - Active TMI programs
-- ============================================================================

CREATE OR ALTER VIEW dbo.vw_NTML_Active AS
SELECT
    p.program_id,
    p.program_guid,
    p.ctl_element,
    p.element_type,
    p.program_type,
    p.program_name,
    p.adv_number,
    p.start_utc,
    p.end_utc,
    p.cumulative_start,
    p.cumulative_end,
    p.status,
    p.scope_type,
    p.scope_tier,
    p.scope_distance_nm,
    p.program_rate,
    p.reserve_rate,
    p.delay_limit_min,
    p.impacting_condition,
    p.cause_text,
    p.prob_extension,
    p.total_flights,
    p.controlled_flights,
    p.exempt_flights,
    p.airborne_flights,
    p.avg_delay_min,
    p.max_delay_min,
    p.revision_number,
    p.created_by,
    p.created_utc,
    p.activated_by,
    p.activated_utc,
    
    -- Time remaining
    DATEDIFF(MINUTE, SYSUTCDATETIME(), p.end_utc) AS minutes_remaining,
    
    -- Time elapsed
    DATEDIFF(MINUTE, p.start_utc, SYSUTCDATETIME()) AS minutes_elapsed,
    
    -- Duration
    DATEDIFF(MINUTE, p.start_utc, p.end_utc) AS duration_minutes

FROM dbo.ntml p
WHERE p.is_active = 1
  AND p.end_utc > SYSUTCDATETIME();
GO

PRINT 'Created view dbo.vw_NTML_Active';
GO

-- ============================================================================
-- vw_NTML_Today - All TMI programs created/active today
-- ============================================================================

CREATE OR ALTER VIEW dbo.vw_NTML_Today AS
SELECT *
FROM dbo.ntml
WHERE CAST(created_utc AS DATE) = CAST(SYSUTCDATETIME() AS DATE)
   OR (is_active = 1 AND end_utc > SYSUTCDATETIME());
GO

PRINT 'Created view dbo.vw_NTML_Today';
GO

PRINT '';
PRINT '=== GDT Views Created ===';
GO
