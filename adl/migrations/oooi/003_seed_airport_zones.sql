-- ============================================================================
-- 042_seed_airport_zones.sql
-- Seeds fallback zones for all 201 target airports
-- Run this in SSMS after 041_oooi_deploy.sql
-- ============================================================================

SET NOCOUNT ON;

PRINT '==========================================================================';
PRINT '  Seeding Airport Geometry Zones';
PRINT '  201 Airports (ASPM77 + Canada + Mexico + LatAm + Caribbean)';
PRINT '  ' + CONVERT(VARCHAR, GETUTCDATE(), 120);
PRINT '==========================================================================';
PRINT '';

-- Create temp table with all target airports
CREATE TABLE #target_airports (icao NVARCHAR(4) PRIMARY KEY);

-- ASPM77
INSERT INTO #target_airports VALUES 
('KATL'),('KBOS'),('KBWI'),('KCLE'),('KCLT'),('KCVG'),('KDCA'),('KDEN'),('KDFW'),('KDTW'),
('KEWR'),('KFLL'),('KHNL'),('KHOU'),('KHPN'),('KIAD'),('KIAH'),('KISP'),('KJFK'),('KLAS'),
('KLAX'),('KLGA'),('KMCI'),('KMCO'),('KMDW'),('KMEM'),('KMIA'),('KMKE'),('KMSP'),('KMSY'),
('KOAK'),('KONT'),('KORD'),('KPBI'),('KPDX'),('KPHL'),('KPHX'),('KPIT'),('KPVD'),('KRDU'),
('KRSW'),('KSAN'),('KSAT'),('KSDF'),('KSEA'),('KSFO'),('KSJC'),('KSLC'),('KSMF'),('KSNA'),
('KSTL'),('KSWF'),('KTEB'),('KTPA'),('KAUS'),('KABQ'),('KANC'),('KBDL'),('KBNA'),('KBUF'),
('KBUR'),('KCHS'),('KCMH'),('KDAL'),('KGSO'),('KIND'),('KJAX'),('KMHT'),('KOMA'),('KORF'),
('KPWM'),('KRNO'),('KRIC'),('KSAV'),('KSYR'),('KTUL');

-- Canada
INSERT INTO #target_airports VALUES 
('CYYZ'),('CYVR'),('CYUL'),('CYYC'),('CYOW'),('CYEG'),('CYWG'),('CYHZ'),('CYQB'),('CYYJ'),
('CYXE'),('CYQR'),('CYYT'),('CYTZ'),('CYQM'),('CYZF'),('CYXY');

-- Mexico
INSERT INTO #target_airports VALUES 
('MMMX'),('MMUN'),('MMTJ'),('MMMY'),('MMGL'),('MMPR'),('MMSD'),('MMCZ'),('MMMD'),('MMHO'),
('MMCU'),('MMMZ'),('MMTO'),('MMZH'),('MMAA'),('MMVR'),('MMTC'),('MMCL'),('MMAS'),('MMBT');

-- Central America
INSERT INTO #target_airports VALUES 
('MGGT'),('MSLP'),('MHTG'),('MNMG'),('MROC'),('MPTO'),('MRLB'),('MPHO'),('MZBZ');

-- Caribbean
INSERT INTO #target_airports VALUES 
('TJSJ'),('TJBQ'),('TIST'),('TISX'),('MYNN'),('MYEF'),('MYGF'),('MUHA'),('MUVR'),('MUCU'),
('MKJP'),('MKJS'),('MDSD'),('MDPP'),('MDPC'),('MTPP'),('MWCR'),('MBPV'),('TNCM'),('TNCA'),
('TNCB'),('TNCC'),('TBPB'),('TLPL'),('TAPA'),('TKPK'),('TGPY'),('TTPP'),('TUPJ'),('TFFR'),
('TFFF'),('TFFJ'),('TFFG');

-- South America
INSERT INTO #target_airports VALUES 
('SBGR'),('SBSP'),('SBRJ'),('SBGL'),('SBKP'),('SBBR'),('SBCF'),('SBPA'),('SBSV'),('SBRF'),
('SBFZ'),('SBCT'),('SBFL'),('SAEZ'),('SABE'),('SACO'),('SAAR'),('SAWH'),('SANC'),('SAME'),
('SCEL'),('SCFA'),('SCIE'),('SCTE'),('SCDA'),('SKBO'),('SKRG'),('SKCL'),('SKBQ'),('SKCG'),
('SKSP'),('SPJC'),('SPZO'),('SPQU'),('SEQM'),('SEGU'),('SEGS'),('SVMI'),('SVMC'),('SVVA'),
('SLLP'),('SLVR'),('SGAS'),('SUMU'),('SYCJ'),('SMJP');

DECLARE @target_count INT = (SELECT COUNT(*) FROM #target_airports);
PRINT 'Target airports: ' + CAST(@target_count AS VARCHAR);

-- ============================================================================
-- Generate fallback zones for airports that have coordinates in apts table
-- ============================================================================

DECLARE @processed INT = 0;
DECLARE @success INT = 0;
DECLARE @skipped INT = 0;
DECLARE @icao NVARCHAR(4);
DECLARE @lat DECIMAL(10,7);
DECLARE @lon DECIMAL(11,7);
DECLARE @elev INT;

DECLARE airport_cursor CURSOR FAST_FORWARD FOR
    SELECT t.icao
    FROM #target_airports t
    WHERE NOT EXISTS (SELECT 1 FROM dbo.airport_geometry g WHERE g.airport_icao = t.icao)
    ORDER BY t.icao;

OPEN airport_cursor;
FETCH NEXT FROM airport_cursor INTO @icao;

WHILE @@FETCH_STATUS = 0
BEGIN
    SET @processed = @processed + 1;
    
    -- Get airport coordinates
    SELECT @lat = LAT_DECIMAL, @lon = LONG_DECIMAL, @elev = TRY_CAST(ELEV AS INT)
    FROM dbo.apts
    WHERE ICAO_ID = @icao;
    
    IF @lat IS NOT NULL AND @lon IS NOT NULL
    BEGIN
        -- Delete any existing zones
        DELETE FROM dbo.airport_geometry WHERE airport_icao = @icao;
        
        -- Create concentric fallback zones
        DECLARE @center GEOGRAPHY = geography::Point(@lat, @lon, 4326);
        
        INSERT INTO dbo.airport_geometry (airport_icao, zone_type, zone_name, geometry, center_lat, center_lon, elevation_ft, source)
        VALUES 
            (@icao, 'RUNWAY', 'FALLBACK_RWY', @center.STBuffer(200), @lat, @lon, @elev, 'FALLBACK'),
            (@icao, 'TAXIWAY', 'FALLBACK_TWY', @center.STBuffer(500).STDifference(@center.STBuffer(200)), @lat, @lon, @elev, 'FALLBACK'),
            (@icao, 'APRON', 'FALLBACK_APRON', @center.STBuffer(800).STDifference(@center.STBuffer(500)), @lat, @lon, @elev, 'FALLBACK'),
            (@icao, 'PARKING', 'FALLBACK_PARK', @center.STBuffer(1200).STDifference(@center.STBuffer(800)), @lat, @lon, @elev, 'FALLBACK');
        
        -- Log
        INSERT INTO dbo.airport_geometry_import_log (airport_icao, source, zones_imported, success)
        VALUES (@icao, 'FALLBACK', 4, 1);
        
        SET @success = @success + 1;
        
        IF @processed % 20 = 0
            PRINT '  Processed ' + CAST(@processed AS VARCHAR) + ' airports...';
    END
    ELSE
    BEGIN
        SET @skipped = @skipped + 1;
        PRINT '  WARNING: No coordinates for ' + @icao;
    END
    
    FETCH NEXT FROM airport_cursor INTO @icao;
END

CLOSE airport_cursor;
DEALLOCATE airport_cursor;

DROP TABLE #target_airports;

-- Summary
PRINT '';
PRINT '==========================================================================';
PRINT '  Seeding Complete';
PRINT '==========================================================================';
PRINT '  Processed: ' + CAST(@processed AS VARCHAR);
PRINT '  Success:   ' + CAST(@success AS VARCHAR);
PRINT '  Skipped:   ' + CAST(@skipped AS VARCHAR) + ' (no coordinates in apts table)';
PRINT '';

-- Show results
SELECT 
    COUNT(DISTINCT airport_icao) AS airports_with_zones,
    COUNT(*) AS total_zones,
    SUM(CASE WHEN zone_type = 'RUNWAY' THEN 1 ELSE 0 END) AS runway_zones,
    SUM(CASE WHEN zone_type = 'TAXIWAY' THEN 1 ELSE 0 END) AS taxiway_zones,
    SUM(CASE WHEN zone_type = 'APRON' THEN 1 ELSE 0 END) AS apron_zones,
    SUM(CASE WHEN zone_type = 'PARKING' THEN 1 ELSE 0 END) AS parking_zones
FROM dbo.airport_geometry;

PRINT '';
PRINT '  OOOI Zone Detection is now ready!';
PRINT '  OSM data can be imported later for more accurate zones.';
PRINT '==========================================================================';
GO
