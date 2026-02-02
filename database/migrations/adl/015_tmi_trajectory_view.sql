-- ============================================================================
-- ADL Migration 015: Unified TMI Trajectory View
--
-- Purpose: Seamless querying across TMI high-res and archive tables
-- Usage: TMI Compliance Analyzer queries this view instead of individual tables
--
-- Target Database: VATSIM_ADL
-- ============================================================================

SET ANSI_NULLS ON;
SET QUOTED_IDENTIFIER ON;
GO

PRINT '=== ADL Migration 015: Unified TMI Trajectory View ===';
GO

CREATE OR ALTER VIEW dbo.vw_trajectory_tmi_complete
AS
-- High-resolution TMI data (T-0, T-1, T-2) - preferred source
SELECT
    t.flight_uid,
    c.callsign,
    t.timestamp_utc,
    t.lat,
    t.lon,
    t.altitude_ft,
    t.groundspeed_kts,
    t.track_deg,
    t.vertical_rate_fpm,
    t.tmi_tier,
    t.perti_event_id,
    'TMI' AS source_table,
    CASE t.tmi_tier
        WHEN 0 THEN 15
        WHEN 1 THEN 30
        WHEN 2 THEN 60
    END AS resolution_sec
FROM dbo.adl_tmi_trajectory t
JOIN dbo.adl_flight_core c ON t.flight_uid = c.flight_uid

UNION ALL

-- Archive data for flights NOT in TMI table (outside coverage or pre-TMI system)
SELECT
    a.flight_uid,
    a.callsign,
    a.timestamp_utc,
    a.lat,
    a.lon,
    a.altitude_ft,
    a.groundspeed_kts,
    a.heading_deg AS track_deg,
    a.vertical_rate_fpm,
    NULL AS tmi_tier,
    NULL AS perti_event_id,
    'ARCHIVE' AS source_table,
    a.sample_interval_sec AS resolution_sec
FROM dbo.adl_trajectory_archive a
WHERE NOT EXISTS (
    -- Exclude rows that exist in TMI table (avoid duplicates)
    SELECT 1 FROM dbo.adl_tmi_trajectory t
    WHERE t.flight_uid = a.flight_uid
      AND t.timestamp_utc = a.timestamp_utc
);
GO

PRINT 'Created view dbo.vw_trajectory_tmi_complete';
GO

-- ============================================================================
-- Helper view: TMI-only data (for compliance analysis)
-- ============================================================================

CREATE OR ALTER VIEW dbo.vw_trajectory_tmi_only
AS
SELECT
    t.tmi_trajectory_id,
    t.flight_uid,
    c.callsign,
    p.fp_dept_icao,
    p.fp_dest_icao,
    t.timestamp_utc,
    t.lat,
    t.lon,
    t.altitude_ft,
    t.groundspeed_kts,
    t.track_deg,
    t.vertical_rate_fpm,
    t.tmi_tier,
    t.perti_event_id,
    e.event_name,
    e.featured_airports
FROM dbo.adl_tmi_trajectory t
JOIN dbo.adl_flight_core c ON t.flight_uid = c.flight_uid
LEFT JOIN dbo.adl_flight_plan p ON t.flight_uid = p.flight_uid
LEFT JOIN dbo.perti_events e ON t.perti_event_id = e.event_id;
GO

PRINT 'Created view dbo.vw_trajectory_tmi_only';
GO

PRINT '=== ADL Migration 015 Complete ===';
GO
