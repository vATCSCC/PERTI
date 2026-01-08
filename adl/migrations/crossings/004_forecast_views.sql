-- ============================================================================
-- Planned Crossings Forecast Views
-- Version: 1.0
-- Date: 2026-01-07
-- Description: Aggregation views for boundary workload forecasting
-- ============================================================================

-- ============================================================================
-- 1. Boundary Workload Forecast (15-minute buckets)
-- Query: "How many flights will cross each boundary in the next X hours?"
-- ============================================================================
CREATE OR ALTER VIEW dbo.vw_boundary_workload_forecast
AS
SELECT
    c.boundary_code,
    c.boundary_type,
    -- 15-minute time bucket
    DATEADD(MINUTE,
        (DATEDIFF(MINUTE, '2000-01-01', c.planned_entry_utc) / 15) * 15,
        '2000-01-01') AS time_bucket,
    COUNT(*) AS expected_entries,
    COUNT(DISTINCT c.flight_uid) AS unique_flights
FROM dbo.adl_flight_planned_crossings c
WHERE c.planned_entry_utc >= GETUTCDATE()
  AND c.planned_entry_utc < DATEADD(HOUR, 12, GETUTCDATE())
  AND c.boundary_code IS NOT NULL
GROUP BY
    c.boundary_code,
    c.boundary_type,
    DATEADD(MINUTE,
        (DATEDIFF(MINUTE, '2000-01-01', c.planned_entry_utc) / 15) * 15,
        '2000-01-01');
GO

PRINT 'Created view: vw_boundary_workload_forecast';
GO

-- ============================================================================
-- 2. ARTCC Workload Summary (hourly buckets)
-- Query: "Summary by ARTCC for the next 6 hours"
-- ============================================================================
CREATE OR ALTER VIEW dbo.vw_artcc_workload_summary
AS
SELECT
    c.boundary_code AS artcc,
    DATEADD(HOUR, DATEDIFF(HOUR, 0, c.planned_entry_utc), 0) AS hour_bucket,
    COUNT(*) AS expected_entries,
    COUNT(DISTINCT c.flight_uid) AS unique_flights,
    MIN(c.planned_entry_utc) AS first_entry,
    MAX(c.planned_entry_utc) AS last_entry
FROM dbo.adl_flight_planned_crossings c
WHERE c.boundary_type = 'ARTCC'
  AND c.planned_entry_utc >= GETUTCDATE()
  AND c.planned_entry_utc < DATEADD(HOUR, 6, GETUTCDATE())
GROUP BY
    c.boundary_code,
    DATEADD(HOUR, DATEDIFF(HOUR, 0, c.planned_entry_utc), 0);
GO

PRINT 'Created view: vw_artcc_workload_summary';
GO

-- ============================================================================
-- 3. Flights Crossing Specific Boundary
-- Query: "Which flights will cross ZDC in the next 2 hours?"
-- ============================================================================
CREATE OR ALTER VIEW dbo.vw_flights_crossing_boundary
AS
SELECT
    c.boundary_code,
    c.boundary_type,
    c.flight_uid,
    f.callsign,
    f.dept,
    f.dest,
    f.aircraft_type,
    c.crossing_order,
    c.entry_fix_name,
    c.exit_fix_name,
    c.planned_entry_utc,
    c.planned_exit_utc,
    DATEDIFF(MINUTE, c.planned_entry_utc, c.planned_exit_utc) AS transit_minutes,
    DATEDIFF(MINUTE, GETUTCDATE(), c.planned_entry_utc) AS minutes_until_entry,
    c.entry_lat,
    c.entry_lon
FROM dbo.adl_flight_planned_crossings c
JOIN dbo.adl_flight_core f ON f.flight_uid = c.flight_uid
WHERE c.planned_entry_utc >= GETUTCDATE()
  AND f.is_active = 1;
GO

PRINT 'Created view: vw_flights_crossing_boundary';
GO

-- ============================================================================
-- 4. Custom Element Crossings
-- Query: "Which flights will cross a custom-defined airspace element?"
-- ============================================================================
CREATE OR ALTER VIEW dbo.vw_flights_crossing_element
AS
SELECT
    e.element_id,
    e.element_name,
    e.element_type,
    e.category,
    c.flight_uid,
    f.callsign,
    f.dept,
    f.dest,
    c.crossing_type,
    c.planned_entry_utc,
    c.planned_exit_utc,
    DATEDIFF(MINUTE, GETUTCDATE(), c.planned_entry_utc) AS minutes_until_entry
FROM dbo.adl_flight_planned_crossings c
JOIN dbo.adl_airspace_element e ON e.element_id = c.element_id
JOIN dbo.adl_flight_core f ON f.flight_uid = c.flight_uid
WHERE c.element_id IS NOT NULL
  AND c.planned_entry_utc >= GETUTCDATE()
  AND f.is_active = 1
  AND e.is_active = 1;
GO

PRINT 'Created view: vw_flights_crossing_element';
GO

-- ============================================================================
-- 5. Sector Demand Forecast
-- Query: "Expected traffic per sector in 30-minute windows"
-- ============================================================================
CREATE OR ALTER VIEW dbo.vw_sector_demand_forecast
AS
SELECT
    c.boundary_code AS sector,
    c.boundary_type AS sector_type,
    -- 30-minute buckets
    DATEADD(MINUTE,
        (DATEDIFF(MINUTE, '2000-01-01', c.planned_entry_utc) / 30) * 30,
        '2000-01-01') AS time_bucket_30min,
    COUNT(*) AS expected_transits,
    COUNT(DISTINCT c.flight_uid) AS unique_flights
FROM dbo.adl_flight_planned_crossings c
WHERE c.boundary_type IN ('SECTOR_HIGH', 'SECTOR_LOW', 'SECTOR_SUPERHIGH')
  AND c.planned_entry_utc >= GETUTCDATE()
  AND c.planned_entry_utc < DATEADD(HOUR, 4, GETUTCDATE())
GROUP BY
    c.boundary_code,
    c.boundary_type,
    DATEADD(MINUTE,
        (DATEDIFF(MINUTE, '2000-01-01', c.planned_entry_utc) / 30) * 30,
        '2000-01-01');
GO

PRINT 'Created view: vw_sector_demand_forecast';
GO

-- ============================================================================
-- 6. Flight Route Crossings (all crossings for a single flight)
-- Query: "Show me all boundaries this flight will cross"
-- ============================================================================
CREATE OR ALTER VIEW dbo.vw_flight_route_crossings
AS
SELECT
    c.flight_uid,
    f.callsign,
    f.dept,
    f.dest,
    c.crossing_order,
    c.boundary_type,
    c.boundary_code,
    c.crossing_type,
    c.entry_fix_name,
    c.exit_fix_name,
    c.planned_entry_utc,
    c.planned_exit_utc,
    DATEDIFF(MINUTE, c.planned_entry_utc, c.planned_exit_utc) AS transit_minutes,
    c.entry_lat,
    c.entry_lon
FROM dbo.adl_flight_planned_crossings c
JOIN dbo.adl_flight_core f ON f.flight_uid = c.flight_uid
WHERE f.is_active = 1;
GO

PRINT 'Created view: vw_flight_route_crossings';
GO

-- ============================================================================
-- 7. Hot Boundaries (busiest in next hour)
-- Query: "Which boundaries will be busiest?"
-- ============================================================================
CREATE OR ALTER VIEW dbo.vw_hot_boundaries
AS
SELECT TOP 50
    c.boundary_code,
    c.boundary_type,
    COUNT(*) AS expected_entries_1hr,
    COUNT(DISTINCT c.flight_uid) AS unique_flights_1hr,
    MIN(c.planned_entry_utc) AS next_entry,
    MAX(c.planned_entry_utc) AS last_entry_in_window
FROM dbo.adl_flight_planned_crossings c
WHERE c.planned_entry_utc >= GETUTCDATE()
  AND c.planned_entry_utc < DATEADD(HOUR, 1, GETUTCDATE())
  AND c.boundary_code IS NOT NULL
GROUP BY c.boundary_code, c.boundary_type
ORDER BY COUNT(*) DESC;
GO

PRINT 'Created view: vw_hot_boundaries';
GO

-- ============================================================================
-- 8. Crossing Statistics (overall system metrics)
-- ============================================================================
CREATE OR ALTER VIEW dbo.vw_crossing_statistics
AS
SELECT
    COUNT(DISTINCT flight_uid) AS flights_with_crossings,
    COUNT(*) AS total_crossings,
    SUM(CASE WHEN boundary_type = 'ARTCC' THEN 1 ELSE 0 END) AS artcc_crossings,
    SUM(CASE WHEN boundary_type = 'SECTOR_HIGH' THEN 1 ELSE 0 END) AS sector_high_crossings,
    SUM(CASE WHEN boundary_type = 'SECTOR_LOW' THEN 1 ELSE 0 END) AS sector_low_crossings,
    SUM(CASE WHEN boundary_type = 'TRACON' THEN 1 ELSE 0 END) AS tracon_crossings,
    SUM(CASE WHEN element_id IS NOT NULL THEN 1 ELSE 0 END) AS element_crossings,
    MIN(calculated_at) AS oldest_calculation,
    MAX(calculated_at) AS newest_calculation
FROM dbo.adl_flight_planned_crossings;
GO

PRINT 'Created view: vw_crossing_statistics';
GO

PRINT '============================================================================';
PRINT 'Forecast Views - Complete';
PRINT '============================================================================';
GO
