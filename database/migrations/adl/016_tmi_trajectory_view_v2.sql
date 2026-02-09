-- ============================================================================
-- ADL Migration 016: Updated TMI Trajectory View (Flight-Level Preference)
--
-- Purpose: Implement flight-level TMI data preference over archive data.
--          If ANY TMI trajectory data exists for a flight, use ONLY TMI data.
--          Archive data is only included for flights with NO TMI coverage.
--          Adds data_priority column for explicit ordering.
--
-- Changes from 015:
--   - NOT EXISTS now checks only flight_uid (flight-level, not point-level)
--   - Added data_priority column (1=TMI, 2=Archive)
--   - Resolution: TMI T-0=15s, T-1=30s, T-2=60s preferred over archive
--
-- Target Database: VATSIM_ADL
-- ============================================================================

SET ANSI_NULLS ON;
SET QUOTED_IDENTIFIER ON;
GO

PRINT '=== ADL Migration 016: Updated TMI Trajectory View (Flight-Level Preference) ===';
GO

CREATE OR ALTER VIEW dbo.vw_trajectory_tmi_complete
AS
-- High-resolution TMI data (T-0=15s, T-1=30s, T-2=60s) - preferred source
-- When TMI data exists for a flight, this is the ONLY source used
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
    END AS resolution_sec,
    1 AS data_priority
FROM dbo.adl_tmi_trajectory t
JOIN dbo.adl_flight_core c ON t.flight_uid = c.flight_uid

UNION ALL

-- Archive data ONLY for flights with NO TMI data at all (fallback)
-- Flight-level exclusion: if ANY TMI row exists for this flight_uid, skip entirely
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
    a.sample_interval_sec AS resolution_sec,
    2 AS data_priority
FROM dbo.adl_trajectory_archive a
WHERE NOT EXISTS (
    SELECT 1 FROM dbo.adl_tmi_trajectory t
    WHERE t.flight_uid = a.flight_uid
);
GO

PRINT 'Updated view dbo.vw_trajectory_tmi_complete (flight-level TMI preference)';
GO

PRINT '=== ADL Migration 016 Complete ===';
GO
