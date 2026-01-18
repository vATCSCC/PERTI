-- ============================================================================
-- VATSIM_TMI Migration 005: Create Views
-- Common query patterns for GDT UI and API
-- ============================================================================
-- Version: 1.0.0
-- Date: 2026-01-18
-- Author: HP/Claude
-- ============================================================================

USE VATSIM_TMI;
GO

-- ============================================================================
-- View: vw_tmi_active_programs
-- Active and proposed programs for monitoring
-- ============================================================================
IF OBJECT_ID('dbo.vw_tmi_active_programs', 'V') IS NOT NULL
    DROP VIEW dbo.vw_tmi_active_programs;
GO

CREATE VIEW dbo.vw_tmi_active_programs AS
SELECT 
    p.program_id,
    p.program_guid,
    p.ctl_element,
    p.element_type,
    p.program_type,
    p.program_name,
    p.adv_number,
    p.status,
    p.start_utc,
    p.end_utc,
    p.cumulative_start_utc,
    p.cumulative_end_utc,
    p.program_rate,
    p.reserve_rate,
    p.delay_limit_min,
    p.scope_type,
    p.scope_distance_nm,
    p.impacting_condition,
    p.cause_text,
    p.revision_number,
    p.compression_enabled,
    p.adaptive_compression,
    p.total_flights,
    p.controlled_flights,
    p.exempt_flights,
    p.popup_flights,
    p.avg_delay_min,
    p.max_delay_min,
    p.created_utc,
    p.activated_utc,
    -- Calculated fields
    DATEDIFF(MINUTE, p.start_utc, p.end_utc) AS duration_min,
    CASE 
        WHEN p.status = 'ACTIVE' AND SYSUTCDATETIME() BETWEEN p.start_utc AND p.end_utc THEN 'RUNNING'
        WHEN p.status = 'ACTIVE' AND SYSUTCDATETIME() < p.start_utc THEN 'SCHEDULED'
        WHEN p.status = 'ACTIVE' AND SYSUTCDATETIME() > p.end_utc THEN 'ENDED'
        ELSE p.status
    END AS run_status,
    DATEDIFF(MINUTE, SYSUTCDATETIME(), p.start_utc) AS minutes_to_start,
    DATEDIFF(MINUTE, SYSUTCDATETIME(), p.end_utc) AS minutes_to_end
FROM dbo.tmi_programs p
WHERE p.status IN ('PROPOSED', 'MODELING', 'ACTIVE', 'PAUSED')
  AND p.is_archived = 0;
GO

-- ============================================================================
-- View: vw_tmi_flight_list
-- Complete flight list for a program with control details
-- Designed to join with VATSIM_ADL for full flight data
-- ============================================================================
IF OBJECT_ID('dbo.vw_tmi_flight_list', 'V') IS NOT NULL
    DROP VIEW dbo.vw_tmi_flight_list;
GO

CREATE VIEW dbo.vw_tmi_flight_list AS
SELECT 
    fc.control_id,
    fc.flight_uid,
    fc.callsign,
    fc.program_id,
    p.ctl_element,
    p.program_type,
    p.program_name,
    -- Control times
    fc.ctd_utc,
    fc.cta_utc,
    fc.octd_utc,
    fc.octa_utc,
    fc.aslot,
    fc.ctl_type,
    -- Exemption
    fc.ctl_exempt,
    fc.ctl_exempt_reason,
    -- Delay
    fc.program_delay_min,
    fc.delay_capped,
    fc.z_slot_delay,
    -- Original estimates
    fc.orig_etd_utc,
    fc.orig_eta_utc,
    -- Status flags
    fc.sl_hold,
    fc.subbable,
    fc.gs_held,
    fc.gs_release_utc,
    fc.is_popup,
    fc.is_recontrol,
    fc.ecr_pending,
    -- Flight info
    fc.dep_airport,
    fc.arr_airport,
    fc.dep_center,
    fc.arr_center,
    -- Compliance
    fc.compliance_status,
    fc.actual_dep_utc,
    fc.compliance_delta_min,
    -- Slot info
    s.slot_name,
    s.slot_type,
    s.slot_status,
    s.slot_time_utc,
    s.bin_hour,
    s.bin_quarter,
    -- Audit
    fc.created_utc,
    fc.control_assigned_utc
FROM dbo.tmi_flight_control fc
INNER JOIN dbo.tmi_programs p ON fc.program_id = p.program_id
LEFT JOIN dbo.tmi_slots s ON fc.slot_id = s.slot_id
WHERE fc.is_archived = 0;
GO

-- ============================================================================
-- View: vw_tmi_slot_allocation
-- Slot allocation summary for a program
-- ============================================================================
IF OBJECT_ID('dbo.vw_tmi_slot_allocation', 'V') IS NOT NULL
    DROP VIEW dbo.vw_tmi_slot_allocation;
GO

CREATE VIEW dbo.vw_tmi_slot_allocation AS
SELECT 
    s.slot_id,
    s.program_id,
    p.ctl_element,
    p.program_type,
    s.slot_name,
    s.slot_index,
    s.slot_time_utc,
    s.slot_type,
    s.slot_status,
    s.bin_date,
    s.bin_hour,
    s.bin_quarter,
    -- Assignment
    s.assigned_flight_uid,
    s.assigned_callsign,
    s.assigned_carrier,
    s.assigned_origin,
    s.slot_delay_min,
    -- Hold status
    s.sl_hold,
    s.sl_hold_carrier,
    s.subbable,
    -- Pop-up
    s.is_popup_slot,
    s.popup_lead_time_min,
    -- Bridge
    s.bridge_from_slot_id,
    s.bridge_to_slot_id,
    s.bridge_reason,
    -- Timing
    s.created_utc,
    s.assigned_utc
FROM dbo.tmi_slots s
INNER JOIN dbo.tmi_programs p ON s.program_id = p.program_id
WHERE s.is_archived = 0;
GO

-- ============================================================================
-- View: vw_tmi_demand_by_hour
-- Hourly demand summary for a control element
-- ============================================================================
IF OBJECT_ID('dbo.vw_tmi_demand_by_hour', 'V') IS NOT NULL
    DROP VIEW dbo.vw_tmi_demand_by_hour;
GO

CREATE VIEW dbo.vw_tmi_demand_by_hour AS
SELECT 
    s.program_id,
    p.ctl_element,
    p.program_type,
    p.program_rate,
    p.reserve_rate,
    s.bin_date,
    s.bin_hour,
    -- Slot counts by type
    COUNT(*) AS total_slots,
    SUM(CASE WHEN s.slot_type = 'REGULAR' THEN 1 ELSE 0 END) AS regular_slots,
    SUM(CASE WHEN s.slot_type = 'RESERVED' THEN 1 ELSE 0 END) AS reserved_slots,
    SUM(CASE WHEN s.slot_type = 'UNASSIGNED' THEN 1 ELSE 0 END) AS unassigned_slots,
    -- Assignment status
    SUM(CASE WHEN s.slot_status = 'ASSIGNED' THEN 1 ELSE 0 END) AS assigned_slots,
    SUM(CASE WHEN s.slot_status = 'OPEN' THEN 1 ELSE 0 END) AS open_slots,
    SUM(CASE WHEN s.slot_status = 'HELD' THEN 1 ELSE 0 END) AS held_slots,
    SUM(CASE WHEN s.slot_status = 'COMPRESSED' THEN 1 ELSE 0 END) AS compressed_slots,
    -- Pop-up stats
    SUM(CASE WHEN s.is_popup_slot = 1 THEN 1 ELSE 0 END) AS popup_assigned,
    -- Delay stats
    AVG(s.slot_delay_min) AS avg_delay_min,
    MAX(s.slot_delay_min) AS max_delay_min,
    SUM(s.slot_delay_min) AS total_delay_min
FROM dbo.tmi_slots s
INNER JOIN dbo.tmi_programs p ON s.program_id = p.program_id
WHERE s.is_archived = 0
GROUP BY 
    s.program_id,
    p.ctl_element,
    p.program_type,
    p.program_rate,
    p.reserve_rate,
    s.bin_date,
    s.bin_hour;
GO

-- ============================================================================
-- View: vw_tmi_demand_by_quarter
-- 15-minute demand bins (for bar graph display)
-- ============================================================================
IF OBJECT_ID('dbo.vw_tmi_demand_by_quarter', 'V') IS NOT NULL
    DROP VIEW dbo.vw_tmi_demand_by_quarter;
GO

CREATE VIEW dbo.vw_tmi_demand_by_quarter AS
SELECT 
    s.program_id,
    p.ctl_element,
    s.bin_date,
    s.bin_hour,
    s.bin_quarter,
    -- Bin time
    DATETIMEFROMPARTS(
        YEAR(s.bin_date), MONTH(s.bin_date), DAY(s.bin_date),
        s.bin_hour, s.bin_quarter, 0, 0
    ) AS bin_time_utc,
    -- Counts
    COUNT(*) AS total_slots,
    SUM(CASE WHEN s.slot_status = 'ASSIGNED' THEN 1 ELSE 0 END) AS assigned,
    SUM(CASE WHEN s.slot_status = 'OPEN' THEN 1 ELSE 0 END) AS available,
    SUM(CASE WHEN s.slot_type = 'RESERVED' AND s.slot_status = 'OPEN' THEN 1 ELSE 0 END) AS reserved_available,
    -- Delays
    AVG(s.slot_delay_min) AS avg_delay_min,
    MAX(s.slot_delay_min) AS max_delay_min
FROM dbo.tmi_slots s
INNER JOIN dbo.tmi_programs p ON s.program_id = p.program_id
WHERE s.is_archived = 0
GROUP BY 
    s.program_id,
    p.ctl_element,
    s.bin_date,
    s.bin_hour,
    s.bin_quarter;
GO

-- ============================================================================
-- View: vw_tmi_popup_pending
-- Pending pop-up flights awaiting slot assignment
-- ============================================================================
IF OBJECT_ID('dbo.vw_tmi_popup_pending', 'V') IS NOT NULL
    DROP VIEW dbo.vw_tmi_popup_pending;
GO

CREATE VIEW dbo.vw_tmi_popup_pending AS
SELECT 
    q.queue_id,
    q.flight_uid,
    q.callsign,
    q.program_id,
    p.ctl_element,
    p.program_type,
    q.detected_utc,
    q.flight_eta_utc,
    q.lead_time_min,
    q.dep_airport,
    q.arr_airport,
    q.dep_center,
    q.aircraft_type,
    q.carrier,
    q.queue_status,
    q.process_notes,
    -- Time until ETA
    DATEDIFF(MINUTE, SYSUTCDATETIME(), q.flight_eta_utc) AS minutes_to_eta
FROM dbo.tmi_popup_queue q
INNER JOIN dbo.tmi_programs p ON q.program_id = p.program_id
WHERE q.queue_status = 'PENDING';
GO

-- ============================================================================
-- View: vw_tmi_program_metrics
-- Aggregated metrics for program dashboards
-- ============================================================================
IF OBJECT_ID('dbo.vw_tmi_program_metrics', 'V') IS NOT NULL
    DROP VIEW dbo.vw_tmi_program_metrics;
GO

CREATE VIEW dbo.vw_tmi_program_metrics AS
SELECT 
    p.program_id,
    p.ctl_element,
    p.program_type,
    p.status,
    p.start_utc,
    p.end_utc,
    -- Stored metrics
    p.total_flights,
    p.controlled_flights,
    p.exempt_flights,
    p.popup_flights,
    p.avg_delay_min,
    p.max_delay_min,
    p.total_delay_min,
    -- Live slot counts
    (SELECT COUNT(*) FROM dbo.tmi_slots s WHERE s.program_id = p.program_id AND s.is_archived = 0) AS live_slot_count,
    (SELECT COUNT(*) FROM dbo.tmi_slots s WHERE s.program_id = p.program_id AND s.slot_status = 'ASSIGNED' AND s.is_archived = 0) AS live_assigned_count,
    (SELECT COUNT(*) FROM dbo.tmi_slots s WHERE s.program_id = p.program_id AND s.slot_status = 'OPEN' AND s.is_archived = 0) AS live_open_count,
    -- Live flight counts
    (SELECT COUNT(*) FROM dbo.tmi_flight_control fc WHERE fc.program_id = p.program_id AND fc.is_archived = 0) AS live_flight_count,
    (SELECT COUNT(*) FROM dbo.tmi_flight_control fc WHERE fc.program_id = p.program_id AND fc.gs_held = 1 AND fc.is_archived = 0) AS live_gs_held_count,
    (SELECT COUNT(*) FROM dbo.tmi_popup_queue q WHERE q.program_id = p.program_id AND q.queue_status = 'PENDING') AS pending_popup_count
FROM dbo.tmi_programs p
WHERE p.is_archived = 0;
GO

PRINT 'Migration 005: Views created successfully';
GO
