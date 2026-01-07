-- ============================================================================
-- VATUSA Event Statistics - New Events Import
-- Generated: 2026-01-07 06:45:24
-- Source: Statsim.net
-- Events: 32
-- ============================================================================

SET NOCOUNT ON;
GO

-- Event: Stuff the Albu-Turkey!
-- Statsim ID: 11883

IF NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202511212300T202511220300/EVT/EVT83')
BEGIN
    INSERT INTO dbo.vatusa_event (
        event_idx, event_name, event_type, event_code,
        start_utc, end_utc, day_of_week,
        total_arrivals, total_departures, total_operations, airport_count,
        season, month_num, year_num, source
    ) VALUES (
        N'202511212300T202511220300/EVT/EVT83',
        N'Stuff the Albu-Turkey!',
        N'EVT',
        N'EVT83',
        '2025-11-21 23:00:00',
        '2025-11-22 03:00:00',
        N'Fri',
        195,
        140,
        335,
        2,
        N'Fall',
        11,
        2025,
        'STATSIM'
    );
    PRINT 'Inserted event: Stuff the Albu-Turkey!';
END
ELSE
BEGIN
    PRINT 'Skipped existing event: 202511212300T202511220300/EVT/EVT83';
END
GO

IF NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202511212300T202511220300/EVT/EVT83' AND airport_icao = N'KPHX')
BEGIN
    INSERT INTO dbo.vatusa_event_airport (
        event_idx, airport_icao, is_featured,
        total_arrivals, total_departures, total_operations
    ) VALUES (
        N'202511212300T202511220300/EVT/EVT83',
        N'KPHX',
        1,
        131,
        84,
        215
    );
END
GO

IF NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202511212300T202511220300/EVT/EVT83' AND airport_icao = N'KABQ')
BEGIN
    INSERT INTO dbo.vatusa_event_airport (
        event_idx, airport_icao, is_featured,
        total_arrivals, total_departures, total_operations
    ) VALUES (
        N'202511212300T202511220300/EVT/EVT83',
        N'KABQ',
        1,
        64,
        56,
        120
    );
END
GO

-- Event: Stuff the Albu-Turkey!
-- Statsim ID: 12236

IF NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202511212359T202511220400/EVT/EVT36')
BEGIN
    INSERT INTO dbo.vatusa_event (
        event_idx, event_name, event_type, event_code,
        start_utc, end_utc, day_of_week,
        total_arrivals, total_departures, total_operations, airport_count,
        season, month_num, year_num, source
    ) VALUES (
        N'202511212359T202511220400/EVT/EVT36',
        N'Stuff the Albu-Turkey!',
        N'EVT',
        N'EVT36',
        '2025-11-21 23:59:00',
        '2025-11-22 04:00:00',
        N'Fri',
        262,
        175,
        437,
        2,
        N'Fall',
        11,
        2025,
        'STATSIM'
    );
    PRINT 'Inserted event: Stuff the Albu-Turkey!';
END
ELSE
BEGIN
    PRINT 'Skipped existing event: 202511212359T202511220400/EVT/EVT36';
END
GO

IF NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202511212359T202511220400/EVT/EVT36' AND airport_icao = N'KPHX')
BEGIN
    INSERT INTO dbo.vatusa_event_airport (
        event_idx, airport_icao, is_featured,
        total_arrivals, total_departures, total_operations
    ) VALUES (
        N'202511212359T202511220400/EVT/EVT36',
        N'KPHX',
        1,
        180,
        111,
        291
    );
END
GO

IF NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202511212359T202511220400/EVT/EVT36' AND airport_icao = N'KABQ')
BEGIN
    INSERT INTO dbo.vatusa_event_airport (
        event_idx, airport_icao, is_featured,
        total_arrivals, total_departures, total_operations
    ) VALUES (
        N'202511212359T202511220400/EVT/EVT36',
        N'KABQ',
        1,
        82,
        64,
        146
    );
END
GO

-- Event: Rock Around the Clock: 24 Hours in Cleveland
-- Statsim ID: 12378

IF NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202511152200T202511162200/EVT/EVT78')
BEGIN
    INSERT INTO dbo.vatusa_event (
        event_idx, event_name, event_type, event_code,
        start_utc, end_utc, day_of_week,
        total_arrivals, total_departures, total_operations, airport_count,
        season, month_num, year_num, source
    ) VALUES (
        N'202511152200T202511162200/EVT/EVT78',
        N'Rock Around the Clock: 24 Hours in Cleveland',
        N'EVT',
        N'EVT78',
        '2025-11-15 22:00:00',
        '2025-11-16 22:00:00',
        N'Sat',
        278,
        250,
        528,
        1,
        N'Fall',
        11,
        2025,
        'STATSIM'
    );
    PRINT 'Inserted event: Rock Around the Clock: 24 Hours in Cleveland';
END
ELSE
BEGIN
    PRINT 'Skipped existing event: 202511152200T202511162200/EVT/EVT78';
END
GO

IF NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202511152200T202511162200/EVT/EVT78' AND airport_icao = N'KCLE')
BEGIN
    INSERT INTO dbo.vatusa_event_airport (
        event_idx, airport_icao, is_featured,
        total_arrivals, total_departures, total_operations
    ) VALUES (
        N'202511152200T202511162200/EVT/EVT78',
        N'KCLE',
        1,
        278,
        250,
        528
    );
END
GO

-- Event: Operation Good Cheer
-- Statsim ID: 12389

IF NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202512131800T202512132100/EVT/EVT89')
BEGIN
    INSERT INTO dbo.vatusa_event (
        event_idx, event_name, event_type, event_code,
        start_utc, end_utc, day_of_week,
        total_arrivals, total_departures, total_operations, airport_count,
        season, month_num, year_num, source
    ) VALUES (
        N'202512131800T202512132100/EVT/EVT89',
        N'Operation Good Cheer',
        N'EVT',
        N'EVT89',
        '2025-12-13 18:00:00',
        '2025-12-13 21:00:00',
        N'Sat',
        12,
        40,
        52,
        1,
        N'Winter',
        12,
        2025,
        'STATSIM'
    );
    PRINT 'Inserted event: Operation Good Cheer';
END
ELSE
BEGIN
    PRINT 'Skipped existing event: 202512131800T202512132100/EVT/EVT89';
END
GO

IF NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202512131800T202512132100/EVT/EVT89' AND airport_icao = N'KPTK')
BEGIN
    INSERT INTO dbo.vatusa_event_airport (
        event_idx, airport_icao, is_featured,
        total_arrivals, total_departures, total_operations
    ) VALUES (
        N'202512131800T202512132100/EVT/EVT89',
        N'KPTK',
        1,
        12,
        40,
        52
    );
END
GO

-- Event: Vinos and Vectors
-- Statsim ID: 12456

IF NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202512310100T202512310400/EVT/EVT56')
BEGIN
    INSERT INTO dbo.vatusa_event (
        event_idx, event_name, event_type, event_code,
        start_utc, end_utc, day_of_week,
        total_arrivals, total_departures, total_operations, airport_count,
        season, month_num, year_num, source
    ) VALUES (
        N'202512310100T202512310400/EVT/EVT56',
        N'Vinos and Vectors',
        N'EVT',
        N'EVT56',
        '2025-12-31 01:00:00',
        '2025-12-31 04:00:00',
        N'Wed',
        47,
        44,
        91,
        4,
        N'Winter',
        12,
        2025,
        'STATSIM'
    );
    PRINT 'Inserted event: Vinos and Vectors';
END
ELSE
BEGIN
    PRINT 'Skipped existing event: 202512310100T202512310400/EVT/EVT56';
END
GO

IF NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202512310100T202512310400/EVT/EVT56' AND airport_icao = N'KAPC')
BEGIN
    INSERT INTO dbo.vatusa_event_airport (
        event_idx, airport_icao, is_featured,
        total_arrivals, total_departures, total_operations
    ) VALUES (
        N'202512310100T202512310400/EVT/EVT56',
        N'KAPC',
        1,
        19,
        13,
        32
    );
END
GO

IF NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202512310100T202512310400/EVT/EVT56' AND airport_icao = N'KCCR')
BEGIN
    INSERT INTO dbo.vatusa_event_airport (
        event_idx, airport_icao, is_featured,
        total_arrivals, total_departures, total_operations
    ) VALUES (
        N'202512310100T202512310400/EVT/EVT56',
        N'KCCR',
        1,
        14,
        7,
        21
    );
END
GO

IF NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202512310100T202512310400/EVT/EVT56' AND airport_icao = N'KSAC')
BEGIN
    INSERT INTO dbo.vatusa_event_airport (
        event_idx, airport_icao, is_featured,
        total_arrivals, total_departures, total_operations
    ) VALUES (
        N'202512310100T202512310400/EVT/EVT56',
        N'KSAC',
        1,
        7,
        9,
        16
    );
END
GO

IF NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202512310100T202512310400/EVT/EVT56' AND airport_icao = N'KSTS')
BEGIN
    INSERT INTO dbo.vatusa_event_airport (
        event_idx, airport_icao, is_featured,
        total_arrivals, total_departures, total_operations
    ) VALUES (
        N'202512310100T202512310400/EVT/EVT56',
        N'KSTS',
        1,
        7,
        15,
        22
    );
END
GO

-- Event: Salish Sunday
-- Statsim ID: 12468

IF NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202511240000T202511240300/SUN/SUN68')
BEGIN
    INSERT INTO dbo.vatusa_event (
        event_idx, event_name, event_type, event_code,
        start_utc, end_utc, day_of_week,
        total_arrivals, total_departures, total_operations, airport_count,
        season, month_num, year_num, source
    ) VALUES (
        N'202511240000T202511240300/SUN/SUN68',
        N'Salish Sunday',
        N'SUN',
        N'SUN68',
        '2025-11-24 00:00:00',
        '2025-11-24 03:00:00',
        N'Mon',
        21,
        31,
        52,
        3,
        N'Fall',
        11,
        2025,
        'STATSIM'
    );
    PRINT 'Inserted event: Salish Sunday';
END
ELSE
BEGIN
    PRINT 'Skipped existing event: 202511240000T202511240300/SUN/SUN68';
END
GO

IF NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202511240000T202511240300/SUN/SUN68' AND airport_icao = N'KBLI')
BEGIN
    INSERT INTO dbo.vatusa_event_airport (
        event_idx, airport_icao, is_featured,
        total_arrivals, total_departures, total_operations
    ) VALUES (
        N'202511240000T202511240300/SUN/SUN68',
        N'KBLI',
        1,
        7,
        8,
        15
    );
END
GO

IF NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202511240000T202511240300/SUN/SUN68' AND airport_icao = N'KNUW')
BEGIN
    INSERT INTO dbo.vatusa_event_airport (
        event_idx, airport_icao, is_featured,
        total_arrivals, total_departures, total_operations
    ) VALUES (
        N'202511240000T202511240300/SUN/SUN68',
        N'KNUW',
        1,
        2,
        10,
        12
    );
END
GO

IF NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202511240000T202511240300/SUN/SUN68' AND airport_icao = N'CYYJ')
BEGIN
    INSERT INTO dbo.vatusa_event_airport (
        event_idx, airport_icao, is_featured,
        total_arrivals, total_departures, total_operations
    ) VALUES (
        N'202511240000T202511240300/SUN/SUN68',
        N'CYYJ',
        1,
        12,
        13,
        25
    );
END
GO

-- Event: Sunday Skies: Central America
-- Statsim ID: 12525

IF NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202511162200T202511170100/SUN/SUN25')
BEGIN
    INSERT INTO dbo.vatusa_event (
        event_idx, event_name, event_type, event_code,
        start_utc, end_utc, day_of_week,
        total_arrivals, total_departures, total_operations, airport_count,
        season, month_num, year_num, source
    ) VALUES (
        N'202511162200T202511170100/SUN/SUN25',
        N'Sunday Skies: Central America',
        N'SUN',
        N'SUN25',
        '2025-11-16 22:00:00',
        '2025-11-17 01:00:00',
        N'Sun',
        13,
        9,
        22,
        5,
        N'Fall',
        11,
        2025,
        'STATSIM'
    );
    PRINT 'Inserted event: Sunday Skies: Central America';
END
ELSE
BEGIN
    PRINT 'Skipped existing event: 202511162200T202511170100/SUN/SUN25';
END
GO

IF NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202511162200T202511170100/SUN/SUN25' AND airport_icao = N'MSLP')
BEGIN
    INSERT INTO dbo.vatusa_event_airport (
        event_idx, airport_icao, is_featured,
        total_arrivals, total_departures, total_operations
    ) VALUES (
        N'202511162200T202511170100/SUN/SUN25',
        N'MSLP',
        1,
        1,
        0,
        1
    );
END
GO

IF NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202511162200T202511170100/SUN/SUN25' AND airport_icao = N'MROC')
BEGIN
    INSERT INTO dbo.vatusa_event_airport (
        event_idx, airport_icao, is_featured,
        total_arrivals, total_departures, total_operations
    ) VALUES (
        N'202511162200T202511170100/SUN/SUN25',
        N'MROC',
        1,
        1,
        1,
        2
    );
END
GO

IF NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202511162200T202511170100/SUN/SUN25' AND airport_icao = N'MHTG')
BEGIN
    INSERT INTO dbo.vatusa_event_airport (
        event_idx, airport_icao, is_featured,
        total_arrivals, total_departures, total_operations
    ) VALUES (
        N'202511162200T202511170100/SUN/SUN25',
        N'MHTG',
        1,
        4,
        0,
        4
    );
END
GO

IF NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202511162200T202511170100/SUN/SUN25' AND airport_icao = N'MGGT')
BEGIN
    INSERT INTO dbo.vatusa_event_airport (
        event_idx, airport_icao, is_featured,
        total_arrivals, total_departures, total_operations
    ) VALUES (
        N'202511162200T202511170100/SUN/SUN25',
        N'MGGT',
        1,
        1,
        2,
        3
    );
END
GO

IF NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202511162200T202511170100/SUN/SUN25' AND airport_icao = N'MPTO')
BEGIN
    INSERT INTO dbo.vatusa_event_airport (
        event_idx, airport_icao, is_featured,
        total_arrivals, total_departures, total_operations
    ) VALUES (
        N'202511162200T202511170100/SUN/SUN25',
        N'MPTO',
        1,
        6,
        6,
        12
    );
END
GO

-- Event: Sunday Skies: Central America
-- Statsim ID: 12526

IF NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202511302200T202512010100/SUN/SUN26')
BEGIN
    INSERT INTO dbo.vatusa_event (
        event_idx, event_name, event_type, event_code,
        start_utc, end_utc, day_of_week,
        total_arrivals, total_departures, total_operations, airport_count,
        season, month_num, year_num, source
    ) VALUES (
        N'202511302200T202512010100/SUN/SUN26',
        N'Sunday Skies: Central America',
        N'SUN',
        N'SUN26',
        '2025-11-30 22:00:00',
        '2025-12-01 01:00:00',
        N'Sun',
        9,
        4,
        13,
        5,
        N'Fall',
        11,
        2025,
        'STATSIM'
    );
    PRINT 'Inserted event: Sunday Skies: Central America';
END
ELSE
BEGIN
    PRINT 'Skipped existing event: 202511302200T202512010100/SUN/SUN26';
END
GO

IF NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202511302200T202512010100/SUN/SUN26' AND airport_icao = N'MSLP')
BEGIN
    INSERT INTO dbo.vatusa_event_airport (
        event_idx, airport_icao, is_featured,
        total_arrivals, total_departures, total_operations
    ) VALUES (
        N'202511302200T202512010100/SUN/SUN26',
        N'MSLP',
        1,
        0,
        0,
        0
    );
END
GO

IF NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202511302200T202512010100/SUN/SUN26' AND airport_icao = N'MROC')
BEGIN
    INSERT INTO dbo.vatusa_event_airport (
        event_idx, airport_icao, is_featured,
        total_arrivals, total_departures, total_operations
    ) VALUES (
        N'202511302200T202512010100/SUN/SUN26',
        N'MROC',
        1,
        7,
        1,
        8
    );
END
GO

IF NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202511302200T202512010100/SUN/SUN26' AND airport_icao = N'MHTG')
BEGIN
    INSERT INTO dbo.vatusa_event_airport (
        event_idx, airport_icao, is_featured,
        total_arrivals, total_departures, total_operations
    ) VALUES (
        N'202511302200T202512010100/SUN/SUN26',
        N'MHTG',
        1,
        0,
        0,
        0
    );
END
GO

IF NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202511302200T202512010100/SUN/SUN26' AND airport_icao = N'MGGT')
BEGIN
    INSERT INTO dbo.vatusa_event_airport (
        event_idx, airport_icao, is_featured,
        total_arrivals, total_departures, total_operations
    ) VALUES (
        N'202511302200T202512010100/SUN/SUN26',
        N'MGGT',
        1,
        0,
        2,
        2
    );
END
GO

IF NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202511302200T202512010100/SUN/SUN26' AND airport_icao = N'MPTO')
BEGIN
    INSERT INTO dbo.vatusa_event_airport (
        event_idx, airport_icao, is_featured,
        total_arrivals, total_departures, total_operations
    ) VALUES (
        N'202511302200T202512010100/SUN/SUN26',
        N'MPTO',
        1,
        2,
        1,
        3
    );
END
GO

-- Event: West Atlantic Free Flight Experience
-- Statsim ID: 12527

IF NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202511221500T202511222000/EVT/EVT27')
BEGIN
    INSERT INTO dbo.vatusa_event (
        event_idx, event_name, event_type, event_code,
        start_utc, end_utc, day_of_week,
        total_arrivals, total_departures, total_operations, airport_count,
        season, month_num, year_num, source
    ) VALUES (
        N'202511221500T202511222000/EVT/EVT27',
        N'West Atlantic Free Flight Experience',
        N'EVT',
        N'EVT27',
        '2025-11-22 15:00:00',
        '2025-11-22 20:00:00',
        N'Sat',
        190,
        282,
        472,
        4,
        N'Fall',
        11,
        2025,
        'STATSIM'
    );
    PRINT 'Inserted event: West Atlantic Free Flight Experience';
END
ELSE
BEGIN
    PRINT 'Skipped existing event: 202511221500T202511222000/EVT/EVT27';
END
GO

IF NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202511221500T202511222000/EVT/EVT27' AND airport_icao = N'KBOS')
BEGIN
    INSERT INTO dbo.vatusa_event_airport (
        event_idx, airport_icao, is_featured,
        total_arrivals, total_departures, total_operations
    ) VALUES (
        N'202511221500T202511222000/EVT/EVT27',
        N'KBOS',
        1,
        77,
        131,
        208
    );
END
GO

IF NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202511221500T202511222000/EVT/EVT27' AND airport_icao = N'KEWR')
BEGIN
    INSERT INTO dbo.vatusa_event_airport (
        event_idx, airport_icao, is_featured,
        total_arrivals, total_departures, total_operations
    ) VALUES (
        N'202511221500T202511222000/EVT/EVT27',
        N'KEWR',
        1,
        55,
        95,
        150
    );
END
GO

IF NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202511221500T202511222000/EVT/EVT27' AND airport_icao = N'TXKF')
BEGIN
    INSERT INTO dbo.vatusa_event_airport (
        event_idx, airport_icao, is_featured,
        total_arrivals, total_departures, total_operations
    ) VALUES (
        N'202511221500T202511222000/EVT/EVT27',
        N'TXKF',
        1,
        38,
        26,
        64
    );
END
GO

IF NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202511221500T202511222000/EVT/EVT27' AND airport_icao = N'TJSJ')
BEGIN
    INSERT INTO dbo.vatusa_event_airport (
        event_idx, airport_icao, is_featured,
        total_arrivals, total_departures, total_operations
    ) VALUES (
        N'202511221500T202511222000/EVT/EVT27',
        N'TJSJ',
        1,
        20,
        30,
        50
    );
END
GO

-- Event: Sunday Skies: Central America
-- Statsim ID: 12528

IF NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202511232200T202511240100/SUN/SUN28')
BEGIN
    INSERT INTO dbo.vatusa_event (
        event_idx, event_name, event_type, event_code,
        start_utc, end_utc, day_of_week,
        total_arrivals, total_departures, total_operations, airport_count,
        season, month_num, year_num, source
    ) VALUES (
        N'202511232200T202511240100/SUN/SUN28',
        N'Sunday Skies: Central America',
        N'SUN',
        N'SUN28',
        '2025-11-23 22:00:00',
        '2025-11-24 01:00:00',
        N'Sun',
        3,
        5,
        8,
        5,
        N'Fall',
        11,
        2025,
        'STATSIM'
    );
    PRINT 'Inserted event: Sunday Skies: Central America';
END
ELSE
BEGIN
    PRINT 'Skipped existing event: 202511232200T202511240100/SUN/SUN28';
END
GO

IF NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202511232200T202511240100/SUN/SUN28' AND airport_icao = N'MSLP')
BEGIN
    INSERT INTO dbo.vatusa_event_airport (
        event_idx, airport_icao, is_featured,
        total_arrivals, total_departures, total_operations
    ) VALUES (
        N'202511232200T202511240100/SUN/SUN28',
        N'MSLP',
        1,
        0,
        0,
        0
    );
END
GO

IF NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202511232200T202511240100/SUN/SUN28' AND airport_icao = N'MROC')
BEGIN
    INSERT INTO dbo.vatusa_event_airport (
        event_idx, airport_icao, is_featured,
        total_arrivals, total_departures, total_operations
    ) VALUES (
        N'202511232200T202511240100/SUN/SUN28',
        N'MROC',
        1,
        1,
        2,
        3
    );
END
GO

IF NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202511232200T202511240100/SUN/SUN28' AND airport_icao = N'MHTG')
BEGIN
    INSERT INTO dbo.vatusa_event_airport (
        event_idx, airport_icao, is_featured,
        total_arrivals, total_departures, total_operations
    ) VALUES (
        N'202511232200T202511240100/SUN/SUN28',
        N'MHTG',
        1,
        0,
        0,
        0
    );
END
GO

IF NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202511232200T202511240100/SUN/SUN28' AND airport_icao = N'MGGT')
BEGIN
    INSERT INTO dbo.vatusa_event_airport (
        event_idx, airport_icao, is_featured,
        total_arrivals, total_departures, total_operations
    ) VALUES (
        N'202511232200T202511240100/SUN/SUN28',
        N'MGGT',
        1,
        0,
        0,
        0
    );
END
GO

IF NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202511232200T202511240100/SUN/SUN28' AND airport_icao = N'MPTO')
BEGIN
    INSERT INTO dbo.vatusa_event_airport (
        event_idx, airport_icao, is_featured,
        total_arrivals, total_departures, total_operations
    ) VALUES (
        N'202511232200T202511240100/SUN/SUN28',
        N'MPTO',
        1,
        2,
        3,
        5
    );
END
GO

-- Event: Nashville Nights, Charlotte Lights
-- Statsim ID: 12607

IF NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202511142359T202511150400/EVT/EVT07')
BEGIN
    INSERT INTO dbo.vatusa_event (
        event_idx, event_name, event_type, event_code,
        start_utc, end_utc, day_of_week,
        total_arrivals, total_departures, total_operations, airport_count,
        season, month_num, year_num, source
    ) VALUES (
        N'202511142359T202511150400/EVT/EVT07',
        N'Nashville Nights, Charlotte Lights',
        N'EVT',
        N'EVT07',
        '2025-11-14 23:59:00',
        '2025-11-15 04:00:00',
        N'Fri',
        295,
        235,
        530,
        2,
        N'Fall',
        11,
        2025,
        'STATSIM'
    );
    PRINT 'Inserted event: Nashville Nights, Charlotte Lights';
END
ELSE
BEGIN
    PRINT 'Skipped existing event: 202511142359T202511150400/EVT/EVT07';
END
GO

IF NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202511142359T202511150400/EVT/EVT07' AND airport_icao = N'KBNA')
BEGIN
    INSERT INTO dbo.vatusa_event_airport (
        event_idx, airport_icao, is_featured,
        total_arrivals, total_departures, total_operations
    ) VALUES (
        N'202511142359T202511150400/EVT/EVT07',
        N'KBNA',
        1,
        140,
        102,
        242
    );
END
GO

IF NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202511142359T202511150400/EVT/EVT07' AND airport_icao = N'KCLT')
BEGIN
    INSERT INTO dbo.vatusa_event_airport (
        event_idx, airport_icao, is_featured,
        total_arrivals, total_departures, total_operations
    ) VALUES (
        N'202511142359T202511150400/EVT/EVT07',
        N'KCLT',
        1,
        155,
        133,
        288
    );
END
GO

-- Event: Opposite Day in the Bay
-- Statsim ID: 12621

IF NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202512070000T202512070400/EVT/EVT21')
BEGIN
    INSERT INTO dbo.vatusa_event (
        event_idx, event_name, event_type, event_code,
        start_utc, end_utc, day_of_week,
        total_arrivals, total_departures, total_operations, airport_count,
        season, month_num, year_num, source
    ) VALUES (
        N'202512070000T202512070400/EVT/EVT21',
        N'Opposite Day in the Bay',
        N'EVT',
        N'EVT21',
        '2025-12-07 00:00:00',
        '2025-12-07 04:00:00',
        N'Sun',
        121,
        105,
        226,
        3,
        N'Winter',
        12,
        2025,
        'STATSIM'
    );
    PRINT 'Inserted event: Opposite Day in the Bay';
END
ELSE
BEGIN
    PRINT 'Skipped existing event: 202512070000T202512070400/EVT/EVT21';
END
GO

IF NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202512070000T202512070400/EVT/EVT21' AND airport_icao = N'KSFO')
BEGIN
    INSERT INTO dbo.vatusa_event_airport (
        event_idx, airport_icao, is_featured,
        total_arrivals, total_departures, total_operations
    ) VALUES (
        N'202512070000T202512070400/EVT/EVT21',
        N'KSFO',
        1,
        88,
        76,
        164
    );
END
GO

IF NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202512070000T202512070400/EVT/EVT21' AND airport_icao = N'KOAK')
BEGIN
    INSERT INTO dbo.vatusa_event_airport (
        event_idx, airport_icao, is_featured,
        total_arrivals, total_departures, total_operations
    ) VALUES (
        N'202512070000T202512070400/EVT/EVT21',
        N'KOAK',
        1,
        21,
        15,
        36
    );
END
GO

IF NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202512070000T202512070400/EVT/EVT21' AND airport_icao = N'KSJC')
BEGIN
    INSERT INTO dbo.vatusa_event_airport (
        event_idx, airport_icao, is_featured,
        total_arrivals, total_departures, total_operations
    ) VALUES (
        N'202512070000T202512070400/EVT/EVT21',
        N'KSJC',
        1,
        12,
        14,
        26
    );
END
GO

-- Event: Eggnog In The Everglades
-- Statsim ID: 12625

IF NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202512212359T202512220400/EVT/EVT25')
BEGIN
    INSERT INTO dbo.vatusa_event (
        event_idx, event_name, event_type, event_code,
        start_utc, end_utc, day_of_week,
        total_arrivals, total_departures, total_operations, airport_count,
        season, month_num, year_num, source
    ) VALUES (
        N'202512212359T202512220400/EVT/EVT25',
        N'Eggnog In The Everglades',
        N'EVT',
        N'EVT25',
        '2025-12-21 23:59:00',
        '2025-12-22 04:00:00',
        N'Sun',
        174,
        151,
        325,
        2,
        N'Winter',
        12,
        2025,
        'STATSIM'
    );
    PRINT 'Inserted event: Eggnog In The Everglades';
END
ELSE
BEGIN
    PRINT 'Skipped existing event: 202512212359T202512220400/EVT/EVT25';
END
GO

IF NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202512212359T202512220400/EVT/EVT25' AND airport_icao = N'KMIA')
BEGIN
    INSERT INTO dbo.vatusa_event_airport (
        event_idx, airport_icao, is_featured,
        total_arrivals, total_departures, total_operations
    ) VALUES (
        N'202512212359T202512220400/EVT/EVT25',
        N'KMIA',
        1,
        137,
        121,
        258
    );
END
GO

IF NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202512212359T202512220400/EVT/EVT25' AND airport_icao = N'KEYW')
BEGIN
    INSERT INTO dbo.vatusa_event_airport (
        event_idx, airport_icao, is_featured,
        total_arrivals, total_departures, total_operations
    ) VALUES (
        N'202512212359T202512220400/EVT/EVT25',
        N'KEYW',
        1,
        37,
        30,
        67
    );
END
GO

-- Event: South America Tour - Leg 5
-- Statsim ID: 12635

IF NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202511162000T202511162300/EVT/EVT35')
BEGIN
    INSERT INTO dbo.vatusa_event (
        event_idx, event_name, event_type, event_code,
        start_utc, end_utc, day_of_week,
        total_arrivals, total_departures, total_operations, airport_count,
        season, month_num, year_num, source
    ) VALUES (
        N'202511162000T202511162300/EVT/EVT35',
        N'South America Tour - Leg 5',
        N'EVT',
        N'EVT35',
        '2025-11-16 20:00:00',
        '2025-11-16 23:00:00',
        N'Sun',
        12,
        24,
        36,
        2,
        N'Fall',
        11,
        2025,
        'STATSIM'
    );
    PRINT 'Inserted event: South America Tour - Leg 5';
END
ELSE
BEGIN
    PRINT 'Skipped existing event: 202511162000T202511162300/EVT/EVT35';
END
GO

IF NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202511162000T202511162300/EVT/EVT35' AND airport_icao = N'SLVR')
BEGIN
    INSERT INTO dbo.vatusa_event_airport (
        event_idx, airport_icao, is_featured,
        total_arrivals, total_departures, total_operations
    ) VALUES (
        N'202511162000T202511162300/EVT/EVT35',
        N'SLVR',
        1,
        8,
        20,
        28
    );
END
GO

IF NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202511162000T202511162300/EVT/EVT35' AND airport_icao = N'SPJC')
BEGIN
    INSERT INTO dbo.vatusa_event_airport (
        event_idx, airport_icao, is_featured,
        total_arrivals, total_departures, total_operations
    ) VALUES (
        N'202511162000T202511162300/EVT/EVT35',
        N'SPJC',
        1,
        4,
        4,
        8
    );
END
GO

-- Event: South America Tour - Leg 6
-- Statsim ID: 12636

IF NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202511302000T202511302300/EVT/EVT36')
BEGIN
    INSERT INTO dbo.vatusa_event (
        event_idx, event_name, event_type, event_code,
        start_utc, end_utc, day_of_week,
        total_arrivals, total_departures, total_operations, airport_count,
        season, month_num, year_num, source
    ) VALUES (
        N'202511302000T202511302300/EVT/EVT36',
        N'South America Tour - Leg 6',
        N'EVT',
        N'EVT36',
        '2025-11-30 20:00:00',
        '2025-11-30 23:00:00',
        N'Sun',
        12,
        35,
        47,
        2,
        N'Fall',
        11,
        2025,
        'STATSIM'
    );
    PRINT 'Inserted event: South America Tour - Leg 6';
END
ELSE
BEGIN
    PRINT 'Skipped existing event: 202511302000T202511302300/EVT/EVT36';
END
GO

IF NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202511302000T202511302300/EVT/EVT36' AND airport_icao = N'SPJC')
BEGIN
    INSERT INTO dbo.vatusa_event_airport (
        event_idx, airport_icao, is_featured,
        total_arrivals, total_departures, total_operations
    ) VALUES (
        N'202511302000T202511302300/EVT/EVT36',
        N'SPJC',
        1,
        5,
        31,
        36
    );
END
GO

IF NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202511302000T202511302300/EVT/EVT36' AND airport_icao = N'SBEG')
BEGIN
    INSERT INTO dbo.vatusa_event_airport (
        event_idx, airport_icao, is_featured,
        total_arrivals, total_departures, total_operations
    ) VALUES (
        N'202511302000T202511302300/EVT/EVT36',
        N'SBEG',
        1,
        7,
        4,
        11
    );
END
GO

-- Event: 11 Hours of F11
-- Statsim ID: 12788

IF NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202511161600T202511170400/EVT/EVT88')
BEGIN
    INSERT INTO dbo.vatusa_event (
        event_idx, event_name, event_type, event_code,
        start_utc, end_utc, day_of_week,
        total_arrivals, total_departures, total_operations, airport_count,
        season, month_num, year_num, source
    ) VALUES (
        N'202511161600T202511170400/EVT/EVT88',
        N'11 Hours of F11',
        N'EVT',
        N'EVT88',
        '2025-11-16 16:00:00',
        '2025-11-17 04:00:00',
        N'Sun',
        209,
        270,
        479,
        2,
        N'Fall',
        11,
        2025,
        'STATSIM'
    );
    PRINT 'Inserted event: 11 Hours of F11';
END
ELSE
BEGIN
    PRINT 'Skipped existing event: 202511161600T202511170400/EVT/EVT88';
END
GO

IF NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202511161600T202511170400/EVT/EVT88' AND airport_icao = N'KMCO')
BEGIN
    INSERT INTO dbo.vatusa_event_airport (
        event_idx, airport_icao, is_featured,
        total_arrivals, total_departures, total_operations
    ) VALUES (
        N'202511161600T202511170400/EVT/EVT88',
        N'KMCO',
        1,
        200,
        250,
        450
    );
END
GO

IF NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202511161600T202511170400/EVT/EVT88' AND airport_icao = N'KSFB')
BEGIN
    INSERT INTO dbo.vatusa_event_airport (
        event_idx, airport_icao, is_featured,
        total_arrivals, total_departures, total_operations
    ) VALUES (
        N'202511161600T202511170400/EVT/EVT88',
        N'KSFB',
        1,
        9,
        20,
        29
    );
END
GO

-- Event: Pumpkin Pie and Patterns
-- Statsim ID: 12848

IF NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202511242359T202511250400/EVT/EVT48')
BEGIN
    INSERT INTO dbo.vatusa_event (
        event_idx, event_name, event_type, event_code,
        start_utc, end_utc, day_of_week,
        total_arrivals, total_departures, total_operations, airport_count,
        season, month_num, year_num, source
    ) VALUES (
        N'202511242359T202511250400/EVT/EVT48',
        N'Pumpkin Pie and Patterns',
        N'EVT',
        N'EVT48',
        '2025-11-24 23:59:00',
        '2025-11-25 04:00:00',
        N'Mon',
        92,
        128,
        220,
        4,
        N'Fall',
        11,
        2025,
        'STATSIM'
    );
    PRINT 'Inserted event: Pumpkin Pie and Patterns';
END
ELSE
BEGIN
    PRINT 'Skipped existing event: 202511242359T202511250400/EVT/EVT48';
END
GO

IF NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202511242359T202511250400/EVT/EVT48' AND airport_icao = N'KHUF')
BEGIN
    INSERT INTO dbo.vatusa_event_airport (
        event_idx, airport_icao, is_featured,
        total_arrivals, total_departures, total_operations
    ) VALUES (
        N'202511242359T202511250400/EVT/EVT48',
        N'KHUF',
        1,
        16,
        12,
        28
    );
END
GO

IF NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202511242359T202511250400/EVT/EVT48' AND airport_icao = N'KIND')
BEGIN
    INSERT INTO dbo.vatusa_event_airport (
        event_idx, airport_icao, is_featured,
        total_arrivals, total_departures, total_operations
    ) VALUES (
        N'202511242359T202511250400/EVT/EVT48',
        N'KIND',
        1,
        60,
        76,
        136
    );
END
GO

IF NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202511242359T202511250400/EVT/EVT48' AND airport_icao = N'KBAK')
BEGIN
    INSERT INTO dbo.vatusa_event_airport (
        event_idx, airport_icao, is_featured,
        total_arrivals, total_departures, total_operations
    ) VALUES (
        N'202511242359T202511250400/EVT/EVT48',
        N'KBAK',
        1,
        5,
        25,
        30
    );
END
GO

IF NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202511242359T202511250400/EVT/EVT48' AND airport_icao = N'KBMG')
BEGIN
    INSERT INTO dbo.vatusa_event_airport (
        event_idx, airport_icao, is_featured,
        total_arrivals, total_departures, total_operations
    ) VALUES (
        N'202511242359T202511250400/EVT/EVT48',
        N'KBMG',
        1,
        11,
        15,
        26
    );
END
GO

-- Event: Tuesday Nights in New York: Turkey Day at Teterboro
-- Statsim ID: 12849

IF NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202511252359T202511260300/MWK/MWK49')
BEGIN
    INSERT INTO dbo.vatusa_event (
        event_idx, event_name, event_type, event_code,
        start_utc, end_utc, day_of_week,
        total_arrivals, total_departures, total_operations, airport_count,
        season, month_num, year_num, source
    ) VALUES (
        N'202511252359T202511260300/MWK/MWK49',
        N'Tuesday Nights in New York: Turkey Day at Teterboro',
        N'MWK',
        N'MWK49',
        '2025-11-25 23:59:00',
        '2025-11-26 03:00:00',
        N'Tue',
        40,
        30,
        70,
        1,
        N'Fall',
        11,
        2025,
        'STATSIM'
    );
    PRINT 'Inserted event: Tuesday Nights in New York: Turkey Day at Teterbor';
END
ELSE
BEGIN
    PRINT 'Skipped existing event: 202511252359T202511260300/MWK/MWK49';
END
GO

IF NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202511252359T202511260300/MWK/MWK49' AND airport_icao = N'KTEB')
BEGIN
    INSERT INTO dbo.vatusa_event_airport (
        event_idx, airport_icao, is_featured,
        total_arrivals, total_departures, total_operations
    ) VALUES (
        N'202511252359T202511260300/MWK/MWK49',
        N'KTEB',
        1,
        40,
        30,
        70
    );
END
GO

-- Event: Fallin' Into Fort Lauderdale
-- Statsim ID: 12851

IF NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202511302300T202512010200/EVT/EVT51')
BEGIN
    INSERT INTO dbo.vatusa_event (
        event_idx, event_name, event_type, event_code,
        start_utc, end_utc, day_of_week,
        total_arrivals, total_departures, total_operations, airport_count,
        season, month_num, year_num, source
    ) VALUES (
        N'202511302300T202512010200/EVT/EVT51',
        N'Fallin'' Into Fort Lauderdale',
        N'EVT',
        N'EVT51',
        '2025-11-30 23:00:00',
        '2025-12-01 02:00:00',
        N'Sun',
        79,
        47,
        126,
        1,
        N'Fall',
        11,
        2025,
        'STATSIM'
    );
    PRINT 'Inserted event: Fallin'' Into Fort Lauderdale';
END
ELSE
BEGIN
    PRINT 'Skipped existing event: 202511302300T202512010200/EVT/EVT51';
END
GO

IF NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202511302300T202512010200/EVT/EVT51' AND airport_icao = N'KFLL')
BEGIN
    INSERT INTO dbo.vatusa_event_airport (
        event_idx, airport_icao, is_featured,
        total_arrivals, total_departures, total_operations
    ) VALUES (
        N'202511302300T202512010200/EVT/EVT51',
        N'KFLL',
        1,
        79,
        47,
        126
    );
END
GO

-- Event: A Wednesday Night at Washington National
-- Statsim ID: 12852

IF NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202512102359T202512110300/MWK/MWK52')
BEGIN
    INSERT INTO dbo.vatusa_event (
        event_idx, event_name, event_type, event_code,
        start_utc, end_utc, day_of_week,
        total_arrivals, total_departures, total_operations, airport_count,
        season, month_num, year_num, source
    ) VALUES (
        N'202512102359T202512110300/MWK/MWK52',
        N'A Wednesday Night at Washington National',
        N'MWK',
        N'MWK52',
        '2025-12-10 23:59:00',
        '2025-12-11 03:00:00',
        N'Wed',
        85,
        40,
        125,
        1,
        N'Winter',
        12,
        2025,
        'STATSIM'
    );
    PRINT 'Inserted event: A Wednesday Night at Washington National';
END
ELSE
BEGIN
    PRINT 'Skipped existing event: 202512102359T202512110300/MWK/MWK52';
END
GO

IF NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202512102359T202512110300/MWK/MWK52' AND airport_icao = N'KDCA')
BEGIN
    INSERT INTO dbo.vatusa_event_airport (
        event_idx, airport_icao, is_featured,
        total_arrivals, total_departures, total_operations
    ) VALUES (
        N'202512102359T202512110300/MWK/MWK52',
        N'KDCA',
        1,
        85,
        40,
        125
    );
END
GO

-- Event: vZDC Holiday Shopping Event Regional Night
-- Statsim ID: 12853

IF NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202512152359T202512160300/SPL/SPL53')
BEGIN
    INSERT INTO dbo.vatusa_event (
        event_idx, event_name, event_type, event_code,
        start_utc, end_utc, day_of_week,
        total_arrivals, total_departures, total_operations, airport_count,
        season, month_num, year_num, source
    ) VALUES (
        N'202512152359T202512160300/SPL/SPL53',
        N'vZDC Holiday Shopping Event Regional Night',
        N'SPL',
        N'SPL53',
        '2025-12-15 23:59:00',
        '2025-12-16 03:00:00',
        N'Mon',
        31,
        23,
        54,
        3,
        N'Winter',
        12,
        2025,
        'STATSIM'
    );
    PRINT 'Inserted event: vZDC Holiday Shopping Event Regional Night';
END
ELSE
BEGIN
    PRINT 'Skipped existing event: 202512152359T202512160300/SPL/SPL53';
END
GO

IF NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202512152359T202512160300/SPL/SPL53' AND airport_icao = N'KHGR')
BEGIN
    INSERT INTO dbo.vatusa_event_airport (
        event_idx, airport_icao, is_featured,
        total_arrivals, total_departures, total_operations
    ) VALUES (
        N'202512152359T202512160300/SPL/SPL53',
        N'KHGR',
        1,
        13,
        14,
        27
    );
END
GO

IF NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202512152359T202512160300/SPL/SPL53' AND airport_icao = N'KFDK')
BEGIN
    INSERT INTO dbo.vatusa_event_airport (
        event_idx, airport_icao, is_featured,
        total_arrivals, total_departures, total_operations
    ) VALUES (
        N'202512152359T202512160300/SPL/SPL53',
        N'KFDK',
        1,
        15,
        6,
        21
    );
END
GO

IF NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202512152359T202512160300/SPL/SPL53' AND airport_icao = N'KMRB')
BEGIN
    INSERT INTO dbo.vatusa_event_airport (
        event_idx, airport_icao, is_featured,
        total_arrivals, total_departures, total_operations
    ) VALUES (
        N'202512152359T202512160300/SPL/SPL53',
        N'KMRB',
        1,
        3,
        3,
        6
    );
END
GO

-- Event: Opposite Day in the Bay
-- Statsim ID: 12872

IF NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202512070000T202512070400/EVT/EVT72')
BEGIN
    INSERT INTO dbo.vatusa_event (
        event_idx, event_name, event_type, event_code,
        start_utc, end_utc, day_of_week,
        total_arrivals, total_departures, total_operations, airport_count,
        season, month_num, year_num, source
    ) VALUES (
        N'202512070000T202512070400/EVT/EVT72',
        N'Opposite Day in the Bay',
        N'EVT',
        N'EVT72',
        '2025-12-07 00:00:00',
        '2025-12-07 04:00:00',
        N'Sun',
        121,
        105,
        226,
        3,
        N'Winter',
        12,
        2025,
        'STATSIM'
    );
    PRINT 'Inserted event: Opposite Day in the Bay';
END
ELSE
BEGIN
    PRINT 'Skipped existing event: 202512070000T202512070400/EVT/EVT72';
END
GO

IF NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202512070000T202512070400/EVT/EVT72' AND airport_icao = N'KSFO')
BEGIN
    INSERT INTO dbo.vatusa_event_airport (
        event_idx, airport_icao, is_featured,
        total_arrivals, total_departures, total_operations
    ) VALUES (
        N'202512070000T202512070400/EVT/EVT72',
        N'KSFO',
        1,
        88,
        76,
        164
    );
END
GO

IF NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202512070000T202512070400/EVT/EVT72' AND airport_icao = N'KOAK')
BEGIN
    INSERT INTO dbo.vatusa_event_airport (
        event_idx, airport_icao, is_featured,
        total_arrivals, total_departures, total_operations
    ) VALUES (
        N'202512070000T202512070400/EVT/EVT72',
        N'KOAK',
        1,
        21,
        15,
        36
    );
END
GO

IF NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202512070000T202512070400/EVT/EVT72' AND airport_icao = N'KSJC')
BEGIN
    INSERT INTO dbo.vatusa_event_airport (
        event_idx, airport_icao, is_featured,
        total_arrivals, total_departures, total_operations
    ) VALUES (
        N'202512070000T202512070400/EVT/EVT72',
        N'KSJC',
        1,
        12,
        14,
        26
    );
END
GO

-- Event: Houston-Mobay Fever
-- Statsim ID: 12940

IF NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202512132359T202512140400/EVT/EVT40')
BEGIN
    INSERT INTO dbo.vatusa_event (
        event_idx, event_name, event_type, event_code,
        start_utc, end_utc, day_of_week,
        total_arrivals, total_departures, total_operations, airport_count,
        season, month_num, year_num, source
    ) VALUES (
        N'202512132359T202512140400/EVT/EVT40',
        N'Houston-Mobay Fever',
        N'EVT',
        N'EVT40',
        '2025-12-13 23:59:00',
        '2025-12-14 04:00:00',
        N'Sat',
        131,
        132,
        263,
        3,
        N'Winter',
        12,
        2025,
        'STATSIM'
    );
    PRINT 'Inserted event: Houston-Mobay Fever';
END
ELSE
BEGIN
    PRINT 'Skipped existing event: 202512132359T202512140400/EVT/EVT40';
END
GO

IF NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202512132359T202512140400/EVT/EVT40' AND airport_icao = N'KIAH')
BEGIN
    INSERT INTO dbo.vatusa_event_airport (
        event_idx, airport_icao, is_featured,
        total_arrivals, total_departures, total_operations
    ) VALUES (
        N'202512132359T202512140400/EVT/EVT40',
        N'KIAH',
        1,
        89,
        94,
        183
    );
END
GO

IF NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202512132359T202512140400/EVT/EVT40' AND airport_icao = N'MKJS')
BEGIN
    INSERT INTO dbo.vatusa_event_airport (
        event_idx, airport_icao, is_featured,
        total_arrivals, total_departures, total_operations
    ) VALUES (
        N'202512132359T202512140400/EVT/EVT40',
        N'MKJS',
        1,
        19,
        4,
        23
    );
END
GO

IF NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202512132359T202512140400/EVT/EVT40' AND airport_icao = N'KHOU')
BEGIN
    INSERT INTO dbo.vatusa_event_airport (
        event_idx, airport_icao, is_featured,
        total_arrivals, total_departures, total_operations
    ) VALUES (
        N'202512132359T202512140400/EVT/EVT40',
        N'KHOU',
        1,
        23,
        34,
        57
    );
END
GO

-- Event: Caroling in Columbus, Decking the halls in Dayton
-- Statsim ID: 12944

IF NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202512190000T202512190400/EVT/EVT44')
BEGIN
    INSERT INTO dbo.vatusa_event (
        event_idx, event_name, event_type, event_code,
        start_utc, end_utc, day_of_week,
        total_arrivals, total_departures, total_operations, airport_count,
        season, month_num, year_num, source
    ) VALUES (
        N'202512190000T202512190400/EVT/EVT44',
        N'Caroling in Columbus, Decking the halls in Dayton',
        N'EVT',
        N'EVT44',
        '2025-12-19 00:00:00',
        '2025-12-19 04:00:00',
        N'Fri',
        72,
        83,
        155,
        4,
        N'Winter',
        12,
        2025,
        'STATSIM'
    );
    PRINT 'Inserted event: Caroling in Columbus, Decking the halls in Dayton';
END
ELSE
BEGIN
    PRINT 'Skipped existing event: 202512190000T202512190400/EVT/EVT44';
END
GO

IF NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202512190000T202512190400/EVT/EVT44' AND airport_icao = N'KDAY')
BEGIN
    INSERT INTO dbo.vatusa_event_airport (
        event_idx, airport_icao, is_featured,
        total_arrivals, total_departures, total_operations
    ) VALUES (
        N'202512190000T202512190400/EVT/EVT44',
        N'KDAY',
        1,
        21,
        23,
        44
    );
END
GO

IF NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202512190000T202512190400/EVT/EVT44' AND airport_icao = N'KCMH')
BEGIN
    INSERT INTO dbo.vatusa_event_airport (
        event_idx, airport_icao, is_featured,
        total_arrivals, total_departures, total_operations
    ) VALUES (
        N'202512190000T202512190400/EVT/EVT44',
        N'KCMH',
        1,
        46,
        51,
        97
    );
END
GO

IF NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202512190000T202512190400/EVT/EVT44' AND airport_icao = N'KILN')
BEGIN
    INSERT INTO dbo.vatusa_event_airport (
        event_idx, airport_icao, is_featured,
        total_arrivals, total_departures, total_operations
    ) VALUES (
        N'202512190000T202512190400/EVT/EVT44',
        N'KILN',
        1,
        1,
        5,
        6
    );
END
GO

IF NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202512190000T202512190400/EVT/EVT44' AND airport_icao = N'KOSU')
BEGIN
    INSERT INTO dbo.vatusa_event_airport (
        event_idx, airport_icao, is_featured,
        total_arrivals, total_departures, total_operations
    ) VALUES (
        N'202512190000T202512190400/EVT/EVT44',
        N'KOSU',
        1,
        4,
        4,
        8
    );
END
GO

-- Event: Gift Returns Sunday
-- Statsim ID: 12973

IF NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202512282359T202512290400/SUN/SUN73')
BEGIN
    INSERT INTO dbo.vatusa_event (
        event_idx, event_name, event_type, event_code,
        start_utc, end_utc, day_of_week,
        total_arrivals, total_departures, total_operations, airport_count,
        season, month_num, year_num, source
    ) VALUES (
        N'202512282359T202512290400/SUN/SUN73',
        N'Gift Returns Sunday',
        N'SUN',
        N'SUN73',
        '2025-12-28 23:59:00',
        '2025-12-29 04:00:00',
        N'Sun',
        146,
        98,
        244,
        1,
        N'Winter',
        12,
        2025,
        'STATSIM'
    );
    PRINT 'Inserted event: Gift Returns Sunday';
END
ELSE
BEGIN
    PRINT 'Skipped existing event: 202512282359T202512290400/SUN/SUN73';
END
GO

IF NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202512282359T202512290400/SUN/SUN73' AND airport_icao = N'KMSP')
BEGIN
    INSERT INTO dbo.vatusa_event_airport (
        event_idx, airport_icao, is_featured,
        total_arrivals, total_departures, total_operations
    ) VALUES (
        N'202512282359T202512290400/SUN/SUN73',
        N'KMSP',
        1,
        146,
        98,
        244
    );
END
GO

-- Event: Home for the Holidays 2025
-- Statsim ID: 13055

IF NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202512192359T202512200400/SPL/SPL55')
BEGIN
    INSERT INTO dbo.vatusa_event (
        event_idx, event_name, event_type, event_code,
        start_utc, end_utc, day_of_week,
        total_arrivals, total_departures, total_operations, airport_count,
        season, month_num, year_num, source
    ) VALUES (
        N'202512192359T202512200400/SPL/SPL55',
        N'Home for the Holidays 2025',
        N'SPL',
        N'SPL55',
        '2025-12-19 23:59:00',
        '2025-12-20 04:00:00',
        N'Fri',
        314,
        175,
        489,
        3,
        N'Winter',
        12,
        2025,
        'STATSIM'
    );
    PRINT 'Inserted event: Home for the Holidays 2025';
END
ELSE
BEGIN
    PRINT 'Skipped existing event: 202512192359T202512200400/SPL/SPL55';
END
GO

IF NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202512192359T202512200400/SPL/SPL55' AND airport_icao = N'KORD')
BEGIN
    INSERT INTO dbo.vatusa_event_airport (
        event_idx, airport_icao, is_featured,
        total_arrivals, total_departures, total_operations
    ) VALUES (
        N'202512192359T202512200400/SPL/SPL55',
        N'KORD',
        1,
        215,
        108,
        323
    );
END
GO

IF NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202512192359T202512200400/SPL/SPL55' AND airport_icao = N'KMDW')
BEGIN
    INSERT INTO dbo.vatusa_event_airport (
        event_idx, airport_icao, is_featured,
        total_arrivals, total_departures, total_operations
    ) VALUES (
        N'202512192359T202512200400/SPL/SPL55',
        N'KMDW',
        1,
        74,
        42,
        116
    );
END
GO

IF NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202512192359T202512200400/SPL/SPL55' AND airport_icao = N'KMKE')
BEGIN
    INSERT INTO dbo.vatusa_event_airport (
        event_idx, airport_icao, is_featured,
        total_arrivals, total_departures, total_operations
    ) VALUES (
        N'202512192359T202512200400/SPL/SPL55',
        N'KMKE',
        1,
        25,
        25,
        50
    );
END
GO

-- Event: Tailgating with the Eagles
-- Statsim ID: 13106

IF NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202512211800T202512212100/EVT/EVT06')
BEGIN
    INSERT INTO dbo.vatusa_event (
        event_idx, event_name, event_type, event_code,
        start_utc, end_utc, day_of_week,
        total_arrivals, total_departures, total_operations, airport_count,
        season, month_num, year_num, source
    ) VALUES (
        N'202512211800T202512212100/EVT/EVT06',
        N'Tailgating with the Eagles',
        N'EVT',
        N'EVT06',
        '2025-12-21 18:00:00',
        '2025-12-21 21:00:00',
        N'Sun',
        42,
        26,
        68,
        1,
        N'Winter',
        12,
        2025,
        'STATSIM'
    );
    PRINT 'Inserted event: Tailgating with the Eagles';
END
ELSE
BEGIN
    PRINT 'Skipped existing event: 202512211800T202512212100/EVT/EVT06';
END
GO

IF NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202512211800T202512212100/EVT/EVT06' AND airport_icao = N'KPHL')
BEGIN
    INSERT INTO dbo.vatusa_event_airport (
        event_idx, airport_icao, is_featured,
        total_arrivals, total_departures, total_operations
    ) VALUES (
        N'202512211800T202512212100/EVT/EVT06',
        N'KPHL',
        1,
        42,
        26,
        68
    );
END
GO

-- Event: Mele Kalikimaka 2025 - Big Island Holiday
-- Statsim ID: 13151

IF NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202512281900T202512282200/SPL/SPL51')
BEGIN
    INSERT INTO dbo.vatusa_event (
        event_idx, event_name, event_type, event_code,
        start_utc, end_utc, day_of_week,
        total_arrivals, total_departures, total_operations, airport_count,
        season, month_num, year_num, source
    ) VALUES (
        N'202512281900T202512282200/SPL/SPL51',
        N'Mele Kalikimaka 2025 - Big Island Holiday',
        N'SPL',
        N'SPL51',
        '2025-12-28 19:00:00',
        '2025-12-28 22:00:00',
        N'Sun',
        21,
        36,
        57,
        2,
        N'Winter',
        12,
        2025,
        'STATSIM'
    );
    PRINT 'Inserted event: Mele Kalikimaka 2025 - Big Island Holiday';
END
ELSE
BEGIN
    PRINT 'Skipped existing event: 202512281900T202512282200/SPL/SPL51';
END
GO

IF NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202512281900T202512282200/SPL/SPL51' AND airport_icao = N'PHKO')
BEGIN
    INSERT INTO dbo.vatusa_event_airport (
        event_idx, airport_icao, is_featured,
        total_arrivals, total_departures, total_operations
    ) VALUES (
        N'202512281900T202512282200/SPL/SPL51',
        N'PHKO',
        1,
        14,
        24,
        38
    );
END
GO

IF NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202512281900T202512282200/SPL/SPL51' AND airport_icao = N'PHTO')
BEGIN
    INSERT INTO dbo.vatusa_event_airport (
        event_idx, airport_icao, is_featured,
        total_arrivals, total_departures, total_operations
    ) VALUES (
        N'202512281900T202512282200/SPL/SPL51',
        N'PHTO',
        1,
        7,
        12,
        19
    );
END
GO

-- Event: South America Tour: Leg 7
-- Statsim ID: 13160

IF NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202512142000T202512142300/EVT/EVT60')
BEGIN
    INSERT INTO dbo.vatusa_event (
        event_idx, event_name, event_type, event_code,
        start_utc, end_utc, day_of_week,
        total_arrivals, total_departures, total_operations, airport_count,
        season, month_num, year_num, source
    ) VALUES (
        N'202512142000T202512142300/EVT/EVT60',
        N'South America Tour: Leg 7',
        N'EVT',
        N'EVT60',
        '2025-12-14 20:00:00',
        '2025-12-14 23:00:00',
        N'Sun',
        9,
        28,
        37,
        2,
        N'Winter',
        12,
        2025,
        'STATSIM'
    );
    PRINT 'Inserted event: South America Tour: Leg 7';
END
ELSE
BEGIN
    PRINT 'Skipped existing event: 202512142000T202512142300/EVT/EVT60';
END
GO

IF NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202512142000T202512142300/EVT/EVT60' AND airport_icao = N'SBEG')
BEGIN
    INSERT INTO dbo.vatusa_event_airport (
        event_idx, airport_icao, is_featured,
        total_arrivals, total_departures, total_operations
    ) VALUES (
        N'202512142000T202512142300/EVT/EVT60',
        N'SBEG',
        1,
        2,
        22,
        24
    );
END
GO

IF NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202512142000T202512142300/EVT/EVT60' AND airport_icao = N'SEQM')
BEGIN
    INSERT INTO dbo.vatusa_event_airport (
        event_idx, airport_icao, is_featured,
        total_arrivals, total_departures, total_operations
    ) VALUES (
        N'202512142000T202512142300/EVT/EVT60',
        N'SEQM',
        1,
        7,
        6,
        13
    );
END
GO

-- Event: New Year, Nashville
-- Statsim ID: 13192

IF NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202601032359T202601040400/EVT/EVT92')
BEGIN
    INSERT INTO dbo.vatusa_event (
        event_idx, event_name, event_type, event_code,
        start_utc, end_utc, day_of_week,
        total_arrivals, total_departures, total_operations, airport_count,
        season, month_num, year_num, source
    ) VALUES (
        N'202601032359T202601040400/EVT/EVT92',
        N'New Year, Nashville',
        N'EVT',
        N'EVT92',
        '2026-01-03 23:59:00',
        '2026-01-04 04:00:00',
        N'Sat',
        185,
        80,
        265,
        1,
        N'Winter',
        1,
        2026,
        'STATSIM'
    );
    PRINT 'Inserted event: New Year, Nashville';
END
ELSE
BEGIN
    PRINT 'Skipped existing event: 202601032359T202601040400/EVT/EVT92';
END
GO

IF NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202601032359T202601040400/EVT/EVT92' AND airport_icao = N'KBNA')
BEGIN
    INSERT INTO dbo.vatusa_event_airport (
        event_idx, airport_icao, is_featured,
        total_arrivals, total_departures, total_operations
    ) VALUES (
        N'202601032359T202601040400/EVT/EVT92',
        N'KBNA',
        1,
        185,
        80,
        265
    );
END
GO

-- Event: 80 hours of A80
-- Statsim ID: 13302

IF NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202512291300T202512312100/EVT/EVT02')
BEGIN
    INSERT INTO dbo.vatusa_event (
        event_idx, event_name, event_type, event_code,
        start_utc, end_utc, day_of_week,
        total_arrivals, total_departures, total_operations, airport_count,
        season, month_num, year_num, source
    ) VALUES (
        N'202512291300T202512312100/EVT/EVT02',
        N'80 hours of A80',
        N'EVT',
        N'EVT02',
        '2025-12-29 13:00:00',
        '2025-12-31 21:00:00',
        N'Mon',
        993,
        1237,
        2230,
        1,
        N'Winter',
        12,
        2025,
        'STATSIM'
    );
    PRINT 'Inserted event: 80 hours of A80';
END
ELSE
BEGIN
    PRINT 'Skipped existing event: 202512291300T202512312100/EVT/EVT02';
END
GO

IF NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202512291300T202512312100/EVT/EVT02' AND airport_icao = N'KATL')
BEGIN
    INSERT INTO dbo.vatusa_event_airport (
        event_idx, airport_icao, is_featured,
        total_arrivals, total_departures, total_operations
    ) VALUES (
        N'202512291300T202512312100/EVT/EVT02',
        N'KATL',
        1,
        993,
        1237,
        2230
    );
END
GO

-- Event: South America Tour Leg 8
-- Statsim ID: 13328

IF NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202512282000T202512282300/EVT/EVT28')
BEGIN
    INSERT INTO dbo.vatusa_event (
        event_idx, event_name, event_type, event_code,
        start_utc, end_utc, day_of_week,
        total_arrivals, total_departures, total_operations, airport_count,
        season, month_num, year_num, source
    ) VALUES (
        N'202512282000T202512282300/EVT/EVT28',
        N'South America Tour Leg 8',
        N'EVT',
        N'EVT28',
        '2025-12-28 20:00:00',
        '2025-12-28 23:00:00',
        N'Sun',
        13,
        6,
        19,
        2,
        N'Winter',
        12,
        2025,
        'STATSIM'
    );
    PRINT 'Inserted event: South America Tour Leg 8';
END
ELSE
BEGIN
    PRINT 'Skipped existing event: 202512282000T202512282300/EVT/EVT28';
END
GO

IF NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202512282000T202512282300/EVT/EVT28' AND airport_icao = N'SEQM')
BEGIN
    INSERT INTO dbo.vatusa_event_airport (
        event_idx, airport_icao, is_featured,
        total_arrivals, total_departures, total_operations
    ) VALUES (
        N'202512282000T202512282300/EVT/EVT28',
        N'SEQM',
        1,
        5,
        1,
        6
    );
END
GO

IF NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202512282000T202512282300/EVT/EVT28' AND airport_icao = N'SKBO')
BEGIN
    INSERT INTO dbo.vatusa_event_airport (
        event_idx, airport_icao, is_featured,
        total_arrivals, total_departures, total_operations
    ) VALUES (
        N'202512282000T202512282300/EVT/EVT28',
        N'SKBO',
        1,
        8,
        5,
        13
    );
END
GO

-- ============================================================================
-- Summary
-- ============================================================================
PRINT 'Imported 32 events';
GO

SELECT 'vatusa_event' AS [Table], COUNT(*) AS [Count] FROM dbo.vatusa_event
UNION ALL
SELECT 'vatusa_event_airport', COUNT(*) FROM dbo.vatusa_event_airport;
GO