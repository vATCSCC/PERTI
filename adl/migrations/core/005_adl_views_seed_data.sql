-- ============================================================================
-- ADL Normalized Schema - Migration 005: Compatibility View & Seed Data
-- 
-- Part of the ADL Database Redesign
-- Creates backward-compatible view and seeds reference data
-- 
-- Run Order: 5 of 5
-- Depends on: 001-004
-- ============================================================================

SET ANSI_NULLS ON;
SET QUOTED_IDENTIFIER ON;
GO

PRINT '=== ADL Migration 005: Compatibility View & Seed Data ===';
PRINT 'Started at: ' + CONVERT(VARCHAR, GETUTCDATE(), 120);
GO

-- ============================================================================
-- 1. vw_adl_flights - Backward Compatibility View
-- 
-- This view presents the normalized tables as a single flat structure
-- matching the original adl_flights table for existing queries.
-- ============================================================================

IF EXISTS (SELECT * FROM sys.views WHERE object_id = OBJECT_ID(N'dbo.vw_adl_flights'))
BEGIN
    DROP VIEW dbo.vw_adl_flights;
    PRINT 'Dropped existing view dbo.vw_adl_flights';
END
GO

CREATE VIEW dbo.vw_adl_flights AS
SELECT
    -- Core identifiers
    c.flight_uid,
    c.flight_key,
    c.cid,
    c.callsign,
    c.flight_id,
    
    -- Lifecycle
    c.phase,
    c.last_source,
    c.is_active,
    
    -- Timestamps (core)
    c.first_seen_utc,
    c.last_seen_utc,
    c.logon_time_utc,
    c.adl_date,
    c.adl_time,
    c.snapshot_utc,
    
    -- Position
    p.lat,
    p.lon,
    p.position_geo,
    p.altitude_ft,
    p.altitude_assigned,
    p.altitude_cleared,
    p.groundspeed_kts,
    p.true_airspeed_kts,
    p.mach,
    p.vertical_rate_fpm,
    p.heading_deg AS heading,
    p.track_deg AS track,
    p.qnh_in_hg,
    p.qnh_mb,
    p.dist_to_dest_nm,
    p.dist_flown_nm,
    p.pct_complete,
    
    -- Flight Plan
    fp.fp_rule,
    fp.fp_dept_icao,
    fp.fp_dest_icao,
    fp.fp_alt_icao,
    fp.fp_dept_tracon,
    fp.fp_dept_artcc,
    fp.dfix,
    fp.dp_name,
    fp.dtrsn,
    fp.fp_dest_tracon,
    fp.fp_dest_artcc,
    fp.afix,
    fp.star_name,
    fp.strsn,
    fp.approach,
    fp.runway,
    fp.eaft_utc,
    fp.fp_route,
    fp.fp_route_expanded,
    fp.route_geometry,
    fp.waypoints_json,
    fp.waypoint_count,
    fp.parse_status,
    fp.parse_tier,
    fp.dep_runway,
    fp.arr_runway,
    fp.initial_alt_ft,
    fp.final_alt_ft,
    fp.stepclimb_count,
    fp.is_simbrief,
    fp.simbrief_id,
    fp.cost_index,
    fp.fp_dept_time_z,
    fp.fp_altitude_ft,
    fp.fp_tas_kts,
    fp.fp_enroute_minutes,
    fp.fp_fuel_minutes,
    fp.fp_remarks,
    fp.gcd_nm,
    fp.aircraft_type,
    fp.aircraft_equip,
    fp.artccs_traversed,
    fp.tracons_traversed,
    
    -- Aircraft
    ac.aircraft_icao,
    ac.weight_class,
    ac.engine_type,
    ac.wake_category,
    ac.airline_icao,
    ac.airline_name,
    -- Derived columns for TMI compatibility
    ac.airline_icao AS major_carrier,
    CASE
        WHEN ac.engine_type IN ('JET') THEN 'JET'
        WHEN ac.engine_type IN ('TURBOPROP', 'PISTON') THEN 'PROP'
        ELSE ac.engine_type
    END AS ac_cat,
    
    -- Times
    t.std_utc,
    t.etd_utc,
    t.etd_runway_utc,
    t.atd_utc,
    t.atd_runway_utc,
    t.ctd_utc,
    t.edct_utc,
    t.sta_utc,
    t.eta_utc,
    t.eta_runway_utc,
    t.ata_utc,
    t.ata_runway_utc,
    t.cta_utc,
    t.eta_epoch,
    t.etd_epoch,
    t.arrival_bucket_utc,
    t.departure_bucket_utc,
    t.ete_minutes,
    t.delay_minutes,
    
    -- TMI
    tmi.ctl_type,
    tmi.ctl_element,
    tmi.delay_status,
    tmi.slot_time_utc,
    tmi.slot_status,
    tmi.is_exempt,
    tmi.exempt_reason,
    tmi.reroute_status,
    tmi.reroute_id
    
FROM dbo.adl_flight_core c
LEFT JOIN dbo.adl_flight_position p ON p.flight_uid = c.flight_uid
LEFT JOIN dbo.adl_flight_plan fp ON fp.flight_uid = c.flight_uid
LEFT JOIN dbo.adl_flight_aircraft ac ON ac.flight_uid = c.flight_uid
LEFT JOIN dbo.adl_flight_times t ON t.flight_uid = c.flight_uid
LEFT JOIN dbo.adl_flight_tmi tmi ON tmi.flight_uid = c.flight_uid;
GO

PRINT 'Created view dbo.vw_adl_flights';
GO

-- ============================================================================
-- 2. Seed Oceanic FIR Boundaries (for tier assignment)
-- ============================================================================

IF NOT EXISTS (SELECT 1 FROM dbo.oceanic_fir_bounds)
BEGIN
    INSERT INTO dbo.oceanic_fir_bounds (fir_code, fir_name, fir_type, min_lat, max_lat, min_lon, max_lon, keeps_tier_1)
    VALUES
    -- US Oceanic FIRs (keep Tier 1)
    ('ZNY_OC', 'New York Oceanic', 'US_OCEANIC', 30.0, 45.0, -75.0, -40.0, 1),
    ('ZMA_OC', 'Miami Oceanic', 'US_OCEANIC', 18.0, 30.0, -85.0, -55.0, 1),
    ('ZHU_OC', 'Houston Oceanic', 'US_OCEANIC', 18.0, 30.0, -98.0, -85.0, 1),
    ('ZAN_OC', 'Anchorage Oceanic', 'US_OCEANIC', 50.0, 75.0, -180.0, -130.0, 1),
    
    -- Canadian Oceanic FIRs (keep Tier 1)
    ('CZQX', 'Gander Oceanic', 'CA_OCEANIC', 40.0, 65.0, -60.0, -30.0, 1),
    ('CZQM', 'Moncton FIR', 'CA_OCEANIC', 42.0, 52.0, -70.0, -55.0, 1),
    ('CZVR', 'Vancouver Oceanic', 'CA_OCEANIC', 45.0, 60.0, -140.0, -125.0, 1),
    
    -- Caribbean/LatAm (keep Tier 1)
    ('TJZS', 'San Juan FIR', 'LATAM_OCEANIC', 10.0, 22.0, -72.0, -60.0, 1),
    ('MHTG', 'Central America', 'LATAM_OCEANIC', 5.0, 18.0, -92.0, -77.0, 1),
    ('SBAO', 'SA Atlantic Approaches', 'LATAM_OCEANIC', -5.0, 15.0, -60.0, -35.0, 1),
    
    -- Oakland Oceanic (special handling - does NOT keep Tier 1 by default)
    ('ZAK', 'Oakland Oceanic', 'US_OCEANIC', 10.0, 55.0, -180.0, -125.0, 0);
    
    -- Note: ZAK is marked keeps_tier_1 = 0 because transpacific flights to CONUS
    -- should be Tier 4 unless destination is AK/HI/Pacific territories.
    -- This is handled in the fn_GetParseTier function.
    
    PRINT 'Seeded oceanic_fir_bounds with ' + CAST(@@ROWCOUNT AS VARCHAR) + ' rows';
END
ELSE
BEGIN
    PRINT 'oceanic_fir_bounds already has data - skipping seed';
END
GO

-- ============================================================================
-- 3. Seed Area Centers (ARTCC/TRACON pseudo-fixes)
-- ============================================================================

IF NOT EXISTS (SELECT 1 FROM dbo.area_centers)
BEGIN
    INSERT INTO dbo.area_centers (center_code, center_type, center_name, lat, lon, parent_artcc)
    VALUES
    -- ARTCCs
    ('ZNY', 'ARTCC', 'New York Center', 40.7128, -74.0060, NULL),
    ('ZDC', 'ARTCC', 'Washington Center', 38.9072, -77.0369, NULL),
    ('ZBW', 'ARTCC', 'Boston Center', 42.3601, -71.0589, NULL),
    ('ZOB', 'ARTCC', 'Cleveland Center', 41.4993, -81.6944, NULL),
    ('ZID', 'ARTCC', 'Indianapolis Center', 39.7684, -86.1581, NULL),
    ('ZAU', 'ARTCC', 'Chicago Center', 41.8781, -87.6298, NULL),
    ('ZMP', 'ARTCC', 'Minneapolis Center', 44.9778, -93.2650, NULL),
    ('ZKC', 'ARTCC', 'Kansas City Center', 39.0997, -94.5786, NULL),
    ('ZFW', 'ARTCC', 'Fort Worth Center', 32.7767, -96.7970, NULL),
    ('ZHU', 'ARTCC', 'Houston Center', 29.7604, -95.3698, NULL),
    ('ZME', 'ARTCC', 'Memphis Center', 35.1495, -90.0490, NULL),
    ('ZTL', 'ARTCC', 'Atlanta Center', 33.7490, -84.3880, NULL),
    ('ZJX', 'ARTCC', 'Jacksonville Center', 30.3322, -81.6557, NULL),
    ('ZMA', 'ARTCC', 'Miami Center', 25.7617, -80.1918, NULL),
    ('ZDV', 'ARTCC', 'Denver Center', 39.7392, -104.9903, NULL),
    ('ZAB', 'ARTCC', 'Albuquerque Center', 35.0844, -106.6504, NULL),
    ('ZLA', 'ARTCC', 'Los Angeles Center', 34.0522, -118.2437, NULL),
    ('ZOA', 'ARTCC', 'Oakland Center', 37.7749, -122.4194, NULL),
    ('ZSE', 'ARTCC', 'Seattle Center', 47.6062, -122.3321, NULL),
    ('ZLC', 'ARTCC', 'Salt Lake Center', 40.7608, -111.8910, NULL),
    ('ZAN', 'ARTCC', 'Anchorage Center', 61.2181, -149.9003, NULL),
    ('ZHN', 'ARTCC', 'Honolulu Center', 21.3069, -157.8583, NULL),
    
    -- Major TRACONs
    ('N90', 'TRACON', 'New York TRACON', 40.7831, -73.9712, 'ZNY'),
    ('PCT', 'TRACON', 'Potomac TRACON', 38.8977, -77.0365, 'ZDC'),
    ('A80', 'TRACON', 'Atlanta TRACON', 33.6407, -84.4277, 'ZTL'),
    ('C90', 'TRACON', 'Chicago TRACON', 41.9742, -87.9073, 'ZAU'),
    ('D10', 'TRACON', 'Dallas TRACON', 32.8998, -97.0403, 'ZFW'),
    ('I90', 'TRACON', 'Houston TRACON', 29.9844, -95.3414, 'ZHU'),
    ('NCT', 'TRACON', 'NorCal TRACON', 37.6213, -122.3790, 'ZOA'),
    ('SCT', 'TRACON', 'SoCal TRACON', 33.9425, -118.4081, 'ZLA'),
    ('S56', 'TRACON', 'Seattle TRACON', 47.4502, -122.3088, 'ZSE'),
    ('MIA', 'TRACON', 'Miami TRACON', 25.7959, -80.2870, 'ZMA'),
    ('D01', 'TRACON', 'Denver TRACON', 39.8561, -104.6737, 'ZDV'),
    ('P50', 'TRACON', 'Phoenix TRACON', 33.4373, -112.0078, 'ZAB'),
    ('Y90', 'TRACON', 'Yankee TRACON', 42.4634, -71.2590, 'ZBW'),
    ('M98', 'TRACON', 'Minneapolis TRACON', 44.8848, -93.2223, 'ZMP'),
    ('R90', 'TRACON', 'Indy TRACON', 39.7173, -86.2944, 'ZID'),
    ('T75', 'TRACON', 'St Louis TRACON', 38.7487, -90.3700, 'ZKC'),
    ('F11', 'TRACON', 'Central Florida TRACON', 28.4312, -81.3081, 'ZJX');
    
    PRINT 'Seeded area_centers with ' + CAST(@@ROWCOUNT AS VARCHAR) + ' rows';
END
ELSE
BEGIN
    PRINT 'area_centers already has data - skipping seed';
END
GO

-- Update position_geo for area_centers
UPDATE dbo.area_centers
SET position_geo = geography::Point(lat, lon, 4326)
WHERE position_geo IS NULL;

PRINT 'Updated position_geo for area_centers';
GO

-- ============================================================================
-- 4. Create helper function for CONUS distance check
-- ============================================================================

IF EXISTS (SELECT * FROM sys.objects WHERE object_id = OBJECT_ID(N'dbo.fn_DistanceToConusNm') AND type = 'FN')
BEGIN
    DROP FUNCTION dbo.fn_DistanceToConusNm;
END
GO

CREATE FUNCTION dbo.fn_DistanceToConusNm(
    @lat DECIMAL(10,7),
    @lon DECIMAL(11,7)
)
RETURNS DECIMAL(10,2)
AS
BEGIN
    -- CONUS bounding box
    DECLARE @conus_min_lat DECIMAL(10,7) = 24.5;
    DECLARE @conus_max_lat DECIMAL(10,7) = 49.5;
    DECLARE @conus_min_lon DECIMAL(11,7) = -125.0;
    DECLARE @conus_max_lon DECIMAL(11,7) = -66.5;
    
    -- If inside CONUS, return 0
    IF @lat BETWEEN @conus_min_lat AND @conus_max_lat
       AND @lon BETWEEN @conus_min_lon AND @conus_max_lon
    BEGIN
        RETURN 0;
    END
    
    -- Find nearest point on CONUS bounding box
    DECLARE @nearest_lat DECIMAL(10,7) = 
        CASE 
            WHEN @lat < @conus_min_lat THEN @conus_min_lat
            WHEN @lat > @conus_max_lat THEN @conus_max_lat
            ELSE @lat
        END;
    
    DECLARE @nearest_lon DECIMAL(11,7) = 
        CASE
            WHEN @lon < @conus_min_lon THEN @conus_min_lon
            WHEN @lon > @conus_max_lon THEN @conus_max_lon
            ELSE @lon
        END;
    
    -- Approximate distance in nm (simplified haversine)
    DECLARE @dlat DECIMAL(10,7) = ABS(@lat - @nearest_lat);
    DECLARE @dlon DECIMAL(11,7) = ABS(@lon - @nearest_lon);
    DECLARE @avg_lat DECIMAL(10,7) = (@lat + @nearest_lat) / 2.0;
    DECLARE @cos_lat DECIMAL(10,7) = COS(RADIANS(@avg_lat));
    
    RETURN SQRT(POWER(@dlat * 60.0, 2) + POWER(@dlon * 60.0 * @cos_lat, 2));
END
GO

PRINT 'Created function dbo.fn_DistanceToConusNm';
GO

-- ============================================================================
-- 5. Create indexes for common query patterns
-- ============================================================================

-- Active flights by destination (for demand queries)
IF NOT EXISTS (SELECT * FROM sys.indexes WHERE name = 'IX_active_dest' AND object_id = OBJECT_ID('dbo.adl_flight_core'))
BEGIN
    PRINT 'Note: Consider adding composite index for common query patterns after data migration';
END
GO

PRINT '';
PRINT '=== ADL Migration 005 Complete ===';
PRINT 'Finished at: ' + CONVERT(VARCHAR, GETUTCDATE(), 120);
PRINT '';
PRINT 'NEXT STEPS:';
PRINT '1. Run reference data import scripts to populate nav_fixes, airways, CDRs';
PRINT '2. Deploy stored procedures from the procedures/ directory';
PRINT '3. Set up Azure Automation jobs for tiered parsing';
PRINT '4. Begin parallel operation testing';
GO
