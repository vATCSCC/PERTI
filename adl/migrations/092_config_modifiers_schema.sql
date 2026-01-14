-- =====================================================
-- Config Modifiers Schema
-- Migration: 092
-- Description: Adds structured modifier system for
--              runway configurations
-- =====================================================

SET NOCOUNT ON;

PRINT '=== Migration 092: Config Modifiers Schema ===';
PRINT '';

-- =====================================================
-- Step 1: Create modifier_category lookup table
-- =====================================================
PRINT '=== Step 1: Create modifier_category table ===';

IF NOT EXISTS (SELECT 1 FROM sys.tables WHERE name = 'modifier_category')
BEGIN
    CREATE TABLE dbo.modifier_category (
        category_code VARCHAR(16) NOT NULL PRIMARY KEY,
        category_name VARCHAR(32) NOT NULL,
        display_order TINYINT NOT NULL,
        color_hex VARCHAR(7) NULL,
        created_utc DATETIME2 DEFAULT SYSUTCDATETIME()
    );

    INSERT INTO dbo.modifier_category (category_code, category_name, display_order, color_hex) VALUES
    ('PARALLEL_OPS',   'Parallel Operations',  1, '#3B82F6'),  -- Blue
    ('APPROACH_TYPE',  'Approach Type',        2, '#8B5CF6'),  -- Purple
    ('TRAFFIC_BIAS',   'Traffic Bias',         3, '#10B981'),  -- Green
    ('VISIBILITY_CAT', 'Visibility Category',  4, '#F59E0B'),  -- Amber
    ('SPECIAL_OPS',    'Special Operations',   5, '#EF4444'),  -- Red
    ('TIME_RESTRICT',  'Time Restriction',     6, '#6366F1'),  -- Indigo
    ('WEATHER_OPS',    'Weather/Seasonal',     7, '#06B6D4'),  -- Cyan
    ('NAMED',          'Named Variant',        8, '#6B7280');  -- Gray

    PRINT 'Created modifier_category table with 8 categories';
END
ELSE
BEGIN
    PRINT 'modifier_category table already exists - skipping';
END
GO

-- =====================================================
-- Step 2: Create modifier_type definitions table
-- =====================================================
PRINT '';
PRINT '=== Step 2: Create modifier_type table ===';

IF NOT EXISTS (SELECT 1 FROM sys.tables WHERE name = 'modifier_type')
BEGIN
    CREATE TABLE dbo.modifier_type (
        modifier_code VARCHAR(16) NOT NULL PRIMARY KEY,
        category_code VARCHAR(16) NOT NULL
            CONSTRAINT FK_modifier_type_category
            REFERENCES dbo.modifier_category(category_code),
        display_name VARCHAR(32) NOT NULL,
        abbrev VARCHAR(8) NOT NULL,
        description VARCHAR(128) NULL,
        is_active BIT NOT NULL DEFAULT 1,
        created_utc DATETIME2 DEFAULT SYSUTCDATETIME()
    );

    PRINT 'Created modifier_type table';

    -- PARALLEL_OPS - Parallel runway approach types
    INSERT INTO dbo.modifier_type (modifier_code, category_code, display_name, abbrev, description) VALUES
    ('SIMOS',        'PARALLEL_OPS', 'Simultaneous Offset ILS', 'SIMOS', 'PRM-monitored offset ILS approaches'),
    ('STAGGERED',    'PARALLEL_OPS', 'Staggered',               'STAG',  'Staggered diagonal spacing on parallels'),
    ('SIDE_BY_SIDE', 'PARALLEL_OPS', 'Side-by-Side',            'SBS',   'Side-by-side parallel operations'),
    ('IN_TRAIL',     'PARALLEL_OPS', 'In-Trail',                'ITR',   'In-trail spacing on parallel runways'),
    ('PRM',          'PARALLEL_OPS', 'Precision Runway Monitor','PRM',   'PRM-monitored approaches'),
    ('CSPR',         'PARALLEL_OPS', 'Closely Spaced Parallels','CSPR',  'Runways <4300ft apart'),
    ('SOIA',         'PARALLEL_OPS', 'SOIA',                    'SOIA',  'Simultaneous Offset Instrument Approach'),
    ('INDEPENDENT',  'PARALLEL_OPS', 'Independent',             'IND',   'Independent parallel approaches'),
    ('DEPENDENT',    'PARALLEL_OPS', 'Dependent',               'DEP',   'Dependent parallel approaches');

    -- APPROACH_TYPE - Instrument approach procedures
    INSERT INTO dbo.modifier_type (modifier_code, category_code, display_name, abbrev, description) VALUES
    ('ILS',        'APPROACH_TYPE', 'ILS',           'ILS',  'Instrument Landing System'),
    ('VOR',        'APPROACH_TYPE', 'VOR',           'VOR',  'VOR approach'),
    ('RNAV',       'APPROACH_TYPE', 'RNAV',          'RNAV', 'Area Navigation (GPS/X/Y/Z variants)'),
    ('LDA',        'APPROACH_TYPE', 'LDA',           'LDA',  'Localizer Directional Aid'),
    ('LOC',        'APPROACH_TYPE', 'Localizer',     'LOC',  'Localizer only approach'),
    ('GLS',        'APPROACH_TYPE', 'GLS',           'GLS',  'GBAS Landing System'),
    ('RNP',        'APPROACH_TYPE', 'RNP',           'RNP',  'RNP AR approach'),
    ('VISUAL',     'APPROACH_TYPE', 'Visual',        'VIS',  'Visual approach'),
    ('FMS_VISUAL', 'APPROACH_TYPE', 'FMS Visual',    'FMS',  'FMS Bridge/Charted Visual approach (e.g., SFO .308)');

    -- TRAFFIC_BIAS - Arrival/departure balance
    INSERT INTO dbo.modifier_type (modifier_code, category_code, display_name, abbrev, description) VALUES
    ('ARR_ONLY',  'TRAFFIC_BIAS', 'Arrivals Only',     'ARR',  'Runway used for arrivals only'),
    ('DEP_ONLY',  'TRAFFIC_BIAS', 'Departures Only',   'DEP',  'Runway used for departures only'),
    ('ARR_HEAVY', 'TRAFFIC_BIAS', 'Arrival Heavy',     'ARRH', 'Primarily arrivals with some departures'),
    ('DEP_HEAVY', 'TRAFFIC_BIAS', 'Departure Heavy',   'DEPH', 'Primarily departures with some arrivals'),
    ('BALANCED',  'TRAFFIC_BIAS', 'Balanced',          'BAL',  'Equal arrival/departure usage'),
    ('MIXED',     'TRAFFIC_BIAS', 'Mixed',             'MIX',  'Flexible arrival/departure mix');

    -- VISIBILITY_CAT - ILS categories for low visibility
    INSERT INTO dbo.modifier_type (modifier_code, category_code, display_name, abbrev, description) VALUES
    ('CAT_I',   'VISIBILITY_CAT', 'CAT I',   'I',   'Category I: RVR 2400ft, DH 200ft'),
    ('CAT_II',  'VISIBILITY_CAT', 'CAT II',  'II',  'Category II: RVR 1200ft, DH 100ft'),
    ('CAT_III', 'VISIBILITY_CAT', 'CAT III', 'III', 'Category III: RVR <700ft');

    -- SPECIAL_OPS - Special operational procedures
    INSERT INTO dbo.modifier_type (modifier_code, category_code, display_name, abbrev, description) VALUES
    ('LAHSO',      'SPECIAL_OPS', 'LAHSO',              'LAHSO', 'Land and Hold Short Operations'),
    ('SINGLE_RWY', 'SPECIAL_OPS', 'Single Runway',      'SRO',   'Single runway operations'),
    ('CIRCLING',   'SPECIAL_OPS', 'Circling',           'CIRC',  'Circle-to-land approach'),
    ('VAP',        'SPECIAL_OPS', 'Visual Approach',    'VAP',   'Visual approach procedure'),
    ('CONVERGING', 'SPECIAL_OPS', 'Converging',         'CONV',  'Converging runway operations'),
    ('LAND_OVER',  'SPECIAL_OPS', 'Land Over',          'LOVR',  'Land over departing traffic');

    -- TIME_RESTRICT - Time-based restrictions
    INSERT INTO dbo.modifier_type (modifier_code, category_code, display_name, abbrev, description) VALUES
    ('DAY',    'TIME_RESTRICT', 'Day Only',    'DAY',  'Daytime operations only'),
    ('NIGHT',  'TIME_RESTRICT', 'Night Only',  'NGT',  'Nighttime operations only'),
    ('CURFEW', 'TIME_RESTRICT', 'Curfew',      'CRF',  'Noise curfew period');

    -- WEATHER_OPS - Weather/seasonal conditions
    INSERT INTO dbo.modifier_type (modifier_code, category_code, display_name, abbrev, description) VALUES
    ('WINTER', 'WEATHER_OPS', 'Winter',      'WNT',  'Winter/snow operations'),
    ('NOISE',  'WEATHER_OPS', 'Noise Abate', 'NSE',  'Noise abatement procedures'),
    ('TSTM',   'WEATHER_OPS', 'Thunderstorm','TSTM', 'Thunderstorm/convective avoidance'),
    ('WIND',   'WEATHER_OPS', 'Wind-Based',  'WND',  'Wind-driven configuration');

    -- NAMED - Named configuration variants
    INSERT INTO dbo.modifier_type (modifier_code, category_code, display_name, abbrev, description) VALUES
    ('NAMED', 'NAMED', 'Named Variant', 'VAR', 'Named configuration variant');

    PRINT 'Inserted modifier types';
END
ELSE
BEGIN
    PRINT 'modifier_type table already exists - skipping';
END

-- Show count after the block
IF EXISTS (SELECT 1 FROM sys.tables WHERE name = 'modifier_type')
BEGIN
    DECLARE @modCount INT;
    SELECT @modCount = COUNT(*) FROM dbo.modifier_type;
    PRINT 'Total modifier types: ' + CAST(@modCount AS VARCHAR);
END
GO

-- =====================================================
-- Step 3: Create config_modifier linking table
-- =====================================================
PRINT '';
PRINT '=== Step 3: Create config_modifier table ===';

IF NOT EXISTS (SELECT 1 FROM sys.tables WHERE name = 'config_modifier')
BEGIN
    CREATE TABLE dbo.config_modifier (
        id BIGINT IDENTITY(1,1) NOT NULL PRIMARY KEY,
        config_id INT NOT NULL
            CONSTRAINT FK_config_modifier_config
            REFERENCES dbo.airport_config(config_id) ON DELETE CASCADE,
        runway_id VARCHAR(8) NULL,              -- NULL = config-level modifier
        modifier_code VARCHAR(16) NOT NULL
            CONSTRAINT FK_config_modifier_type
            REFERENCES dbo.modifier_type(modifier_code),
        original_value VARCHAR(32) NULL,        -- Preserve original (e.g., 'RNAV_GPS_Y', 'STAGGERED_DUAL')
        variant_value VARCHAR(16) NULL,         -- For CIRCLING->12, VAP->31L, NAMED->3, RNAV->GPS_Y
        created_utc DATETIME2 DEFAULT SYSUTCDATETIME(),

        CONSTRAINT UQ_config_modifier UNIQUE (config_id, runway_id, modifier_code)
    );

    -- Index for efficient lookups
    CREATE NONCLUSTERED INDEX IX_config_modifier_config
        ON dbo.config_modifier(config_id) INCLUDE (runway_id, modifier_code, variant_value);

    CREATE NONCLUSTERED INDEX IX_config_modifier_type
        ON dbo.config_modifier(modifier_code) INCLUDE (config_id);

    PRINT 'Created config_modifier table with indexes';
END
ELSE
BEGIN
    PRINT 'config_modifier table already exists - skipping';
END
GO

-- =====================================================
-- Step 4: Add intersection column to runway table
-- =====================================================
PRINT '';
PRINT '=== Step 4: Add intersection column to airport_config_runway ===';

IF NOT EXISTS (SELECT 1 FROM sys.columns
               WHERE object_id = OBJECT_ID('dbo.airport_config_runway')
               AND name = 'intersection')
BEGIN
    ALTER TABLE dbo.airport_config_runway ADD intersection VARCHAR(8) NULL;
    PRINT 'Added intersection column to airport_config_runway';
END
ELSE
BEGIN
    PRINT 'intersection column already exists - skipping';
END
GO

-- =====================================================
-- Step 5: Create view for configs with modifiers
-- =====================================================
PRINT '';
PRINT '=== Step 5: Create vw_config_with_modifiers view ===';

IF EXISTS (SELECT 1 FROM sys.views WHERE name = 'vw_config_with_modifiers')
    DROP VIEW dbo.vw_config_with_modifiers;
GO

CREATE VIEW dbo.vw_config_with_modifiers AS
SELECT
    c.config_id,
    c.airport_faa,
    c.airport_icao,
    c.config_name,
    c.config_code,
    c.is_active,
    -- Aggregated config-level modifiers
    STUFF((
        SELECT ', ' + mt.abbrev
        FROM dbo.config_modifier cm2
        JOIN dbo.modifier_type mt ON cm2.modifier_code = mt.modifier_code
        JOIN dbo.modifier_category mc2 ON mt.category_code = mc2.category_code
        WHERE cm2.config_id = c.config_id AND cm2.runway_id IS NULL
        ORDER BY mc2.display_order
        FOR XML PATH(''), TYPE
    ).value('.', 'VARCHAR(MAX)'), 1, 2, '') AS config_modifiers
FROM dbo.airport_config c;
GO

PRINT 'Created vw_config_with_modifiers view';
GO

-- =====================================================
-- Step 6: Create view for runway modifiers
-- =====================================================
PRINT '';
PRINT '=== Step 6: Create vw_runway_with_modifiers view ===';

IF EXISTS (SELECT 1 FROM sys.views WHERE name = 'vw_runway_with_modifiers')
    DROP VIEW dbo.vw_runway_with_modifiers;
GO

CREATE VIEW dbo.vw_runway_with_modifiers AS
SELECT
    r.id,
    r.config_id,
    r.runway_id,
    r.runway_use,
    r.priority,
    r.intersection,
    r.approach_type AS legacy_approach_type,
    r.config_mode AS legacy_config_mode,
    r.notes AS legacy_notes,
    -- Aggregated runway modifiers
    STUFF((
        SELECT ', ' + mt.abbrev
        FROM dbo.config_modifier cm
        JOIN dbo.modifier_type mt ON cm.modifier_code = mt.modifier_code
        JOIN dbo.modifier_category mc ON mt.category_code = mc.category_code
        WHERE cm.config_id = r.config_id AND cm.runway_id = r.runway_id
        ORDER BY mc.display_order
        FOR XML PATH(''), TYPE
    ).value('.', 'VARCHAR(MAX)'), 1, 2, '') AS runway_modifiers
FROM dbo.airport_config_runway r;
GO

PRINT 'Created vw_runway_with_modifiers view';
GO

-- =====================================================
-- Summary
-- =====================================================
PRINT '';
PRINT '=== Migration 092 Summary ===';

SELECT 'modifier_category' AS table_name, COUNT(*) AS row_count FROM dbo.modifier_category
UNION ALL
SELECT 'modifier_type', COUNT(*) FROM dbo.modifier_type
UNION ALL
SELECT 'config_modifier', COUNT(*) FROM dbo.config_modifier;

PRINT '';
PRINT 'Migration 092 completed successfully.';
GO
