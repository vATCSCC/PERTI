-- ============================================================================
-- GDT Sandbox Tables Migration
--
-- Creates/updates the sandbox tables used by GDT simulation scripts:
-- - adl_flights_gs: Ground Stop simulation sandbox
-- - adl_flights_gdp: GDP simulation sandbox
--
-- These tables mirror the adl_flights view structure to allow the PHP
-- simulation scripts to work with a snapshot of flight data.
--
-- Run after: 004_gdt_columns_fix.sql
-- ============================================================================

SET ANSI_NULLS ON;
SET QUOTED_IDENTIFIER ON;
GO

PRINT '=== GDT Sandbox Tables Migration ===';
PRINT 'Started at: ' + CONVERT(VARCHAR, GETUTCDATE(), 120);
GO

-- ============================================================================
-- 1. Create adl_flights_gs (Ground Stop sandbox)
-- ============================================================================

IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'adl_flights_gs')
BEGIN
    CREATE TABLE dbo.adl_flights_gs (
        id                      BIGINT IDENTITY(1,1) NOT NULL PRIMARY KEY,

        -- Core identifiers (from adl_flight_core)
        flight_uid              BIGINT NULL,
        flight_key              NVARCHAR(64) NULL,
        cid                     INT NULL,
        callsign                NVARCHAR(16) NULL,
        flight_id               NVARCHAR(32) NULL,
        phase                   NVARCHAR(16) NULL,
        last_source             NVARCHAR(16) NULL,
        is_active               BIT NULL,
        first_seen_utc          DATETIME2(0) NULL,
        last_seen_utc           DATETIME2(0) NULL,
        logon_time_utc          DATETIME2(0) NULL,
        adl_date                DATE NULL,
        adl_time                TIME(0) NULL,
        snapshot_utc            DATETIME2(0) NULL,

        -- Position (from adl_flight_position)
        lat                     DECIMAL(10,7) NULL,
        lon                     DECIMAL(11,7) NULL,
        altitude_ft             INT NULL,
        groundspeed_kts         INT NULL,
        heading                 SMALLINT NULL,
        dist_to_dest_nm         DECIMAL(8,2) NULL,

        -- Flight Plan (from adl_flight_plan)
        fp_dept_icao            CHAR(4) NULL,
        fp_dest_icao            CHAR(4) NULL,
        fp_alt_icao             CHAR(4) NULL,
        fp_dept_artcc           NVARCHAR(4) NULL,
        fp_dest_artcc           NVARCHAR(4) NULL,
        fp_route                NVARCHAR(MAX) NULL,
        fp_altitude_ft          INT NULL,
        aircraft_type           NVARCHAR(8) NULL,
        dfix                    NVARCHAR(8) NULL,
        afix                    NVARCHAR(8) NULL,

        -- Aircraft (from adl_flight_aircraft)
        aircraft_icao           NVARCHAR(8) NULL,
        weight_class            NCHAR(1) NULL,
        ac_cat                  NVARCHAR(8) NULL,       -- JET, PROP, etc.
        major_carrier           NVARCHAR(8) NULL,

        -- Times (from adl_flight_times)
        std_utc                 DATETIME2(0) NULL,
        etd_utc                 DATETIME2(0) NULL,
        etd_runway_utc          DATETIME2(0) NULL,
        atd_utc                 DATETIME2(0) NULL,
        sta_utc                 DATETIME2(0) NULL,
        eta_utc                 DATETIME2(0) NULL,
        eta_runway_utc          DATETIME2(0) NULL,
        ata_utc                 DATETIME2(0) NULL,
        ete_minutes             INT NULL,

        -- Controlled Times
        ctd_utc                 DATETIME2(0) NULL,
        cta_utc                 DATETIME2(0) NULL,
        edct_utc                DATETIME2(0) NULL,

        -- Original/Baseline Times
        octd_utc                DATETIME2(0) NULL,
        octa_utc                DATETIME2(0) NULL,
        oetd_utc                DATETIME2(0) NULL,
        betd_utc                DATETIME2(0) NULL,
        oeta_utc                DATETIME2(0) NULL,
        beta_utc                DATETIME2(0) NULL,
        oete_minutes            INT NULL,
        cete_minutes            INT NULL,
        igta_utc                DATETIME2(0) NULL,
        eta_prefix              NCHAR(1) NULL,

        -- TMI fields
        ctl_type                NVARCHAR(8) NULL,
        ctl_element             NVARCHAR(8) NULL,
        ctl_prgm                NVARCHAR(50) NULL,
        delay_status            NVARCHAR(16) NULL,
        slot_time               NVARCHAR(8) NULL,       -- dd/HHmm format for display
        slot_time_utc           DATETIME2(0) NULL,

        -- Delay metrics
        program_delay_min       INT NULL,
        absolute_delay_min      INT NULL,
        schedule_variation_min  INT NULL,
        delay_capped            BIT NULL DEFAULT 0,

        -- Ground Stop specific
        gs_held                 BIT NULL DEFAULT 0,
        gs_release_utc          DATETIME2(0) NULL,

        -- Exemption
        ctl_exempt              BIT NULL DEFAULT 0,
        ctl_exempt_reason       NVARCHAR(64) NULL,

        -- Scope tracking (for unique index)
        scope                   NVARCHAR(16) NULL,

        -- Timestamps
        created_utc             DATETIME2(0) NULL DEFAULT SYSUTCDATETIME()
    );

    -- Unique index on scope + flight_key (allows same flight in different scopes)
    CREATE UNIQUE INDEX UQ_adl_flights_gs_scope_flight
        ON dbo.adl_flights_gs(scope, flight_key)
        WHERE flight_key IS NOT NULL;

    CREATE INDEX IX_adl_flights_gs_dest ON dbo.adl_flights_gs(fp_dest_icao);
    CREATE INDEX IX_adl_flights_gs_ctl_type ON dbo.adl_flights_gs(ctl_type);
    CREATE INDEX IX_adl_flights_gs_eta ON dbo.adl_flights_gs(eta_runway_utc);

    PRINT 'Created table dbo.adl_flights_gs';
END
ELSE
BEGIN
    -- Table exists, add any missing columns
    PRINT 'Table dbo.adl_flights_gs already exists - checking for missing columns...';

    -- Add missing columns if they don't exist
    IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID('dbo.adl_flights_gs') AND name = 'octd_utc')
        ALTER TABLE dbo.adl_flights_gs ADD octd_utc DATETIME2(0) NULL;
    IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID('dbo.adl_flights_gs') AND name = 'octa_utc')
        ALTER TABLE dbo.adl_flights_gs ADD octa_utc DATETIME2(0) NULL;
    IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID('dbo.adl_flights_gs') AND name = 'oetd_utc')
        ALTER TABLE dbo.adl_flights_gs ADD oetd_utc DATETIME2(0) NULL;
    IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID('dbo.adl_flights_gs') AND name = 'betd_utc')
        ALTER TABLE dbo.adl_flights_gs ADD betd_utc DATETIME2(0) NULL;
    IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID('dbo.adl_flights_gs') AND name = 'oeta_utc')
        ALTER TABLE dbo.adl_flights_gs ADD oeta_utc DATETIME2(0) NULL;
    IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID('dbo.adl_flights_gs') AND name = 'beta_utc')
        ALTER TABLE dbo.adl_flights_gs ADD beta_utc DATETIME2(0) NULL;
    IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID('dbo.adl_flights_gs') AND name = 'oete_minutes')
        ALTER TABLE dbo.adl_flights_gs ADD oete_minutes INT NULL;
    IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID('dbo.adl_flights_gs') AND name = 'cete_minutes')
        ALTER TABLE dbo.adl_flights_gs ADD cete_minutes INT NULL;
    IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID('dbo.adl_flights_gs') AND name = 'igta_utc')
        ALTER TABLE dbo.adl_flights_gs ADD igta_utc DATETIME2(0) NULL;
    IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID('dbo.adl_flights_gs') AND name = 'eta_prefix')
        ALTER TABLE dbo.adl_flights_gs ADD eta_prefix NCHAR(1) NULL;
    IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID('dbo.adl_flights_gs') AND name = 'program_delay_min')
        ALTER TABLE dbo.adl_flights_gs ADD program_delay_min INT NULL;
    IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID('dbo.adl_flights_gs') AND name = 'absolute_delay_min')
        ALTER TABLE dbo.adl_flights_gs ADD absolute_delay_min INT NULL;
    IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID('dbo.adl_flights_gs') AND name = 'schedule_variation_min')
        ALTER TABLE dbo.adl_flights_gs ADD schedule_variation_min INT NULL;
    IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID('dbo.adl_flights_gs') AND name = 'delay_capped')
        ALTER TABLE dbo.adl_flights_gs ADD delay_capped BIT NULL DEFAULT 0;
    IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID('dbo.adl_flights_gs') AND name = 'gs_held')
        ALTER TABLE dbo.adl_flights_gs ADD gs_held BIT NULL DEFAULT 0;
    IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID('dbo.adl_flights_gs') AND name = 'gs_release_utc')
        ALTER TABLE dbo.adl_flights_gs ADD gs_release_utc DATETIME2(0) NULL;
    IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID('dbo.adl_flights_gs') AND name = 'ctl_exempt')
        ALTER TABLE dbo.adl_flights_gs ADD ctl_exempt BIT NULL DEFAULT 0;
    IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID('dbo.adl_flights_gs') AND name = 'ctl_exempt_reason')
        ALTER TABLE dbo.adl_flights_gs ADD ctl_exempt_reason NVARCHAR(64) NULL;
    IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID('dbo.adl_flights_gs') AND name = 'ctl_prgm')
        ALTER TABLE dbo.adl_flights_gs ADD ctl_prgm NVARCHAR(50) NULL;
    IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID('dbo.adl_flights_gs') AND name = 'slot_time')
        ALTER TABLE dbo.adl_flights_gs ADD slot_time NVARCHAR(8) NULL;

    PRINT 'Added missing columns to adl_flights_gs';
END
GO

-- ============================================================================
-- 2. Create adl_flights_gdp (GDP sandbox)
-- ============================================================================

IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'adl_flights_gdp')
BEGIN
    -- Create by copying structure from adl_flights_gs (which we just created/updated)
    SELECT TOP 0 * INTO dbo.adl_flights_gdp FROM dbo.adl_flights_gs;

    -- Add GDP-specific columns
    ALTER TABLE dbo.adl_flights_gdp ADD gdp_program_id NVARCHAR(50) NULL;
    ALTER TABLE dbo.adl_flights_gdp ADD gdp_slot_index INT NULL;
    ALTER TABLE dbo.adl_flights_gdp ADD gdp_slot_time_utc DATETIME2(0) NULL;
    ALTER TABLE dbo.adl_flights_gdp ADD gdp_original_eta_utc DATETIME2(0) NULL;

    -- Change the identity column - SQL Server requires rebuilding
    -- Since this is a sandbox table, we can drop and recreate the identity constraint
    -- For now, just ensure the table is usable

    -- Create unique index on flight_key
    CREATE UNIQUE INDEX UQ_adl_flights_gdp_flight_key
        ON dbo.adl_flights_gdp(flight_key)
        WHERE flight_key IS NOT NULL;

    CREATE INDEX IX_adl_flights_gdp_program ON dbo.adl_flights_gdp(gdp_program_id);
    CREATE INDEX IX_adl_flights_gdp_dest ON dbo.adl_flights_gdp(fp_dest_icao);
    CREATE INDEX IX_adl_flights_gdp_eta ON dbo.adl_flights_gdp(eta_runway_utc);

    PRINT 'Created table dbo.adl_flights_gdp (copied from adl_flights_gs structure)';
END
ELSE
BEGIN
    -- Table exists, add any missing columns
    PRINT 'Table dbo.adl_flights_gdp already exists - checking for missing columns...';

    -- Copy missing column logic from adl_flights_gs
    IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID('dbo.adl_flights_gdp') AND name = 'octd_utc')
        ALTER TABLE dbo.adl_flights_gdp ADD octd_utc DATETIME2(0) NULL;
    IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID('dbo.adl_flights_gdp') AND name = 'octa_utc')
        ALTER TABLE dbo.adl_flights_gdp ADD octa_utc DATETIME2(0) NULL;
    IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID('dbo.adl_flights_gdp') AND name = 'oetd_utc')
        ALTER TABLE dbo.adl_flights_gdp ADD oetd_utc DATETIME2(0) NULL;
    IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID('dbo.adl_flights_gdp') AND name = 'betd_utc')
        ALTER TABLE dbo.adl_flights_gdp ADD betd_utc DATETIME2(0) NULL;
    IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID('dbo.adl_flights_gdp') AND name = 'oeta_utc')
        ALTER TABLE dbo.adl_flights_gdp ADD oeta_utc DATETIME2(0) NULL;
    IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID('dbo.adl_flights_gdp') AND name = 'beta_utc')
        ALTER TABLE dbo.adl_flights_gdp ADD beta_utc DATETIME2(0) NULL;
    IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID('dbo.adl_flights_gdp') AND name = 'oete_minutes')
        ALTER TABLE dbo.adl_flights_gdp ADD oete_minutes INT NULL;
    IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID('dbo.adl_flights_gdp') AND name = 'cete_minutes')
        ALTER TABLE dbo.adl_flights_gdp ADD cete_minutes INT NULL;
    IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID('dbo.adl_flights_gdp') AND name = 'igta_utc')
        ALTER TABLE dbo.adl_flights_gdp ADD igta_utc DATETIME2(0) NULL;
    IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID('dbo.adl_flights_gdp') AND name = 'eta_prefix')
        ALTER TABLE dbo.adl_flights_gdp ADD eta_prefix NCHAR(1) NULL;
    IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID('dbo.adl_flights_gdp') AND name = 'program_delay_min')
        ALTER TABLE dbo.adl_flights_gdp ADD program_delay_min INT NULL;
    IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID('dbo.adl_flights_gdp') AND name = 'absolute_delay_min')
        ALTER TABLE dbo.adl_flights_gdp ADD absolute_delay_min INT NULL;
    IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID('dbo.adl_flights_gdp') AND name = 'schedule_variation_min')
        ALTER TABLE dbo.adl_flights_gdp ADD schedule_variation_min INT NULL;
    IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID('dbo.adl_flights_gdp') AND name = 'delay_capped')
        ALTER TABLE dbo.adl_flights_gdp ADD delay_capped BIT NULL DEFAULT 0;
    IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID('dbo.adl_flights_gdp') AND name = 'gdp_program_id')
        ALTER TABLE dbo.adl_flights_gdp ADD gdp_program_id NVARCHAR(50) NULL;
    IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID('dbo.adl_flights_gdp') AND name = 'gdp_slot_index')
        ALTER TABLE dbo.adl_flights_gdp ADD gdp_slot_index INT NULL;
    IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID('dbo.adl_flights_gdp') AND name = 'gdp_slot_time_utc')
        ALTER TABLE dbo.adl_flights_gdp ADD gdp_slot_time_utc DATETIME2(0) NULL;
    IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID('dbo.adl_flights_gdp') AND name = 'gdp_original_eta_utc')
        ALTER TABLE dbo.adl_flights_gdp ADD gdp_original_eta_utc DATETIME2(0) NULL;
    IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID('dbo.adl_flights_gdp') AND name = 'ctl_prgm')
        ALTER TABLE dbo.adl_flights_gdp ADD ctl_prgm NVARCHAR(50) NULL;
    IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID('dbo.adl_flights_gdp') AND name = 'slot_time')
        ALTER TABLE dbo.adl_flights_gdp ADD slot_time NVARCHAR(8) NULL;

    PRINT 'Added missing columns to adl_flights_gdp';
END
GO

-- ============================================================================
-- 3. Verify column presence summary
-- ============================================================================

PRINT '';
PRINT 'Column verification for adl_flights_gs:';
SELECT
    'adl_flights_gs' AS table_name,
    COUNT(*) AS column_count
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_NAME = 'adl_flights_gs';

PRINT '';
PRINT 'Column verification for adl_flights_gdp:';
SELECT
    'adl_flights_gdp' AS table_name,
    COUNT(*) AS column_count
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_NAME = 'adl_flights_gdp';
GO

PRINT '';
PRINT '=== GDT Sandbox Tables Migration Complete ===';
PRINT 'Finished at: ' + CONVERT(VARCHAR, GETUTCDATE(), 120);
PRINT '';
PRINT 'Tables created/updated:';
PRINT '- adl_flights_gs: Ground Stop simulation sandbox';
PRINT '- adl_flights_gdp: GDP simulation sandbox';
PRINT '';
PRINT 'Next steps:';
PRINT '1. Run the GDT simulation from the UI to verify it works';
PRINT '2. Check that flights are being assigned CTD/CTA values';
PRINT '3. Verify the Apply function updates the main ADL tables';
GO
