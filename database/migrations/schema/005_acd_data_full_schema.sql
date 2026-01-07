-- ============================================================================
-- Migration 025: ACD_Data Full FAA Schema
--
-- Replaces existing ACD_Data table with complete FAA Aircraft Characteristics
-- Database schema from https://www.faa.gov/airports/engineering/aircraft_char_database
--
-- Source: FAA ACD Database (downloaded and converted to SQL)
-- ============================================================================

SET ANSI_NULLS ON;
SET QUOTED_IDENTIFIER ON;
GO

PRINT '=== Migration 025: ACD_Data Full FAA Schema ===';
PRINT 'Started at: ' + CONVERT(VARCHAR, GETUTCDATE(), 120);
GO

-- ============================================================================
-- 1. Drop existing table if it exists
-- ============================================================================

IF EXISTS (SELECT * FROM sys.objects WHERE object_id = OBJECT_ID(N'dbo.ACD_Data') AND type = 'U')
BEGIN
    DROP TABLE dbo.ACD_Data;
    PRINT 'Dropped existing ACD_Data table';
END
GO

-- ============================================================================
-- 2. Create new ACD_Data table with complete FAA schema
-- ============================================================================

CREATE TABLE dbo.ACD_Data (
    -- Primary Key
    acd_id                          INT IDENTITY(1,1) NOT NULL,

    -- Aircraft Identifiers
    ICAO_Code                       NVARCHAR(8) NOT NULL,           -- ICAO Aircraft Type Designator
    FAA_Designator                  NVARCHAR(8) NULL,               -- FAA Aircraft Type Designator
    Manufacturer                    NVARCHAR(64) NULL,              -- Aircraft Manufacturer Name
    Model_FAA                       NVARCHAR(128) NULL,             -- FAA Aircraft Model Name
    Model_BADA                      NVARCHAR(128) NULL,             -- Eurocontrol BADA Aircraft Model Name

    -- Engine Information
    Physical_Class_Engine           NVARCHAR(16) NULL,              -- Jet, Turboprop, Piston, Turboshaft, Electric, Rocket
    Num_Engines                     TINYINT NULL,                   -- Number of Engines

    -- Aircraft Approach Category (AAC)
    AAC                             NVARCHAR(4) NULL,               -- Aircraft Approach Category (A-E or N/A)
    AAC_minimum                     NVARCHAR(4) NULL,               -- Minimum AAC for dual-value aircraft
    AAC_maximum                     NVARCHAR(4) NULL,               -- Maximum AAC for dual-value aircraft

    -- Airport Design Standards
    ADG                             NVARCHAR(4) NULL,               -- Aircraft Design Group (I-VI)
    TDG                             NVARCHAR(4) NULL,               -- Taxiway Design Group (1A-7)

    -- Approach Speeds
    Approach_Speed_knot             DECIMAL(6,1) NULL,              -- Approach Speed at MALW (knots)
    Approach_Speed_minimum_knot     DECIMAL(6,1) NULL,              -- Minimum Approach Speed (knots)
    Approach_Speed_maximum_knot     DECIMAL(6,1) NULL,              -- Maximum Approach Speed (knots)

    -- Aircraft Dimensions
    Wingspan_ft_without_winglets    DECIMAL(7,2) NULL,              -- Wingspan without winglets/sharklets (ft)
    Wingspan_ft_with_winglets       DECIMAL(7,2) NULL,              -- Wingspan with winglets/sharklets (ft)
    Length_ft                       DECIMAL(7,2) NULL,              -- Aircraft Full Length (ft)
    Tail_Height_at_OEW_ft           DECIMAL(6,2) NULL,              -- Tail Height at Operating Empty Weight (ft)
    Wheelbase_ft                    DECIMAL(7,2) NULL,              -- Distance between main/nose gear (ft)
    Cockpit_to_Main_Gear_ft         DECIMAL(7,2) NULL,              -- Cockpit to Main Gear Distance (ft)
    Main_Gear_Width_ft              DECIMAL(6,2) NULL,              -- Main Gear Width (ft)
    Rotor_Diameter_ft               DECIMAL(7,2) NULL,              -- Rotor Diameter for helicopters/tiltrotors (ft)

    -- Weight Data
    MTOW_lb                         DECIMAL(12,3) NULL,             -- Maximum Takeoff Gross Weight (lbs)
    MALW_lb                         NVARCHAR(16) NULL,              -- Maximum Allowable Landing Weight (lbs or N/A)

    -- Landing Gear
    Main_Gear_Config                NVARCHAR(8) NULL,               -- Main Gears Configuration (S, D, 2S, 2D, etc.)

    -- Wake Turbulence Categories
    ICAO_WTC                        NVARCHAR(16) NULL,              -- ICAO Wake Turbulence Category (Super/Heavy/Medium/Light/Light/Medium)

    -- Parking
    Parking_Area_ft2                DECIMAL(10,2) NULL,             -- Minimum Parking Position Sizing (sq ft)

    -- Aircraft Classification
    Class                           NVARCHAR(16) NULL,              -- Fixed-wing, Amphibian, Gyrocopter, Helicopter, Tiltrotor
    FAA_Weight                      NVARCHAR(8) NULL,               -- FAA Weight Class (Super, Heavy, Large, Small, Small+)

    -- Consolidated Wake Turbulence (CWT)
    CWT                             NCHAR(1) NULL,                  -- Category A (largest) to I (smallest)

    -- RECAT Wake Categories
    One_Half_Wake_Category          NVARCHAR(4) NULL,               -- RECAT 1.5 Wake Category (A-F or N/A)
    Two_Wake_Category_Appx_A        NVARCHAR(4) NULL,               -- RECAT 2 Wake Category Appendix A (A-G or N/A)
    Two_Wake_Category_Appx_B        NVARCHAR(4) NULL,               -- RECAT 2 Wake Category Appendix B (A-G or N/A)

    -- Separation Categories
    SRS                             NVARCHAR(8) NULL,               -- Same Runway Separation Category
    LAHSO                           NVARCHAR(8) NULL,               -- Land and Hold Short Operations Group (1-10)

    -- FAA Registry Information
    FAA_Registry                    NVARCHAR(4) NULL,               -- Flag if registered by FAA (Yes/No)
    Registration_Count              INT NULL,                       -- Total registered aircraft count

    -- Traffic Data
    TFMS_Operations_FY24            INT NULL,                       -- NAS Operations with TFMS Flight Plan

    -- Metadata
    Remarks                         NVARCHAR(MAX) NULL,             -- Additional remarks
    LastUpdate                      DATE NULL,                      -- Date record last updated

    -- System columns
    created_utc                     DATETIME2(0) NOT NULL DEFAULT SYSUTCDATETIME(),

    CONSTRAINT PK_ACD_Data PRIMARY KEY CLUSTERED (acd_id)
);
GO

-- ============================================================================
-- 3. Create Indexes
-- ============================================================================

-- Primary lookup by ICAO code
CREATE UNIQUE NONCLUSTERED INDEX IX_ACD_ICAO ON dbo.ACD_Data (ICAO_Code);

-- FAA designator lookup
CREATE NONCLUSTERED INDEX IX_ACD_FAA ON dbo.ACD_Data (FAA_Designator) WHERE FAA_Designator IS NOT NULL;

-- Weight class lookups
CREATE NONCLUSTERED INDEX IX_ACD_FAA_Weight ON dbo.ACD_Data (FAA_Weight);
CREATE NONCLUSTERED INDEX IX_ACD_ICAO_WTC ON dbo.ACD_Data (ICAO_WTC);
CREATE NONCLUSTERED INDEX IX_ACD_CWT ON dbo.ACD_Data (CWT);

-- Engine type lookup
CREATE NONCLUSTERED INDEX IX_ACD_Engine ON dbo.ACD_Data (Physical_Class_Engine);

-- Aircraft class lookup
CREATE NONCLUSTERED INDEX IX_ACD_Class ON dbo.ACD_Data (Class);

PRINT 'Created ACD_Data table with indexes';
GO

-- ============================================================================
-- 4. Create helper function for weight class lookup
-- ============================================================================

IF OBJECT_ID('dbo.fn_GetWeightClass', 'FN') IS NOT NULL
    DROP FUNCTION dbo.fn_GetWeightClass;
GO

CREATE FUNCTION dbo.fn_GetWeightClass(@icao_code NVARCHAR(8))
RETURNS NCHAR(1)
AS
BEGIN
    DECLARE @weight NCHAR(1);

    SELECT @weight = CASE FAA_Weight
        WHEN 'Super' THEN 'J'
        WHEN 'Heavy' THEN 'H'
        WHEN 'Large' THEN 'L'
        WHEN 'Small' THEN 'S'
        WHEN 'Small+' THEN 'S'
        ELSE 'L'  -- Default to Large
    END
    FROM dbo.ACD_Data
    WHERE ICAO_Code = @icao_code;

    RETURN ISNULL(@weight, 'L');
END;
GO

-- ============================================================================
-- 5. Create helper function for wake category lookup
-- ============================================================================

IF OBJECT_ID('dbo.fn_GetWakeCategory', 'FN') IS NOT NULL
    DROP FUNCTION dbo.fn_GetWakeCategory;
GO

CREATE FUNCTION dbo.fn_GetWakeCategory(@icao_code NVARCHAR(8))
RETURNS NVARCHAR(8)
AS
BEGIN
    DECLARE @wake NVARCHAR(8);

    SELECT @wake = ICAO_WTC
    FROM dbo.ACD_Data
    WHERE ICAO_Code = @icao_code;

    RETURN ISNULL(@wake, 'Medium');
END;
GO

-- ============================================================================
-- 6. Create view for common lookups
-- ============================================================================

IF OBJECT_ID('dbo.vw_ACD_Weight_Lookup', 'V') IS NOT NULL
    DROP VIEW dbo.vw_ACD_Weight_Lookup;
GO

CREATE VIEW dbo.vw_ACD_Weight_Lookup AS
SELECT
    ICAO_Code,
    FAA_Designator,
    Manufacturer,
    Model_FAA,
    Physical_Class_Engine,
    Num_Engines,
    FAA_Weight,
    ICAO_WTC,
    CWT,
    One_Half_Wake_Category AS RECAT_15,
    Two_Wake_Category_Appx_A AS RECAT_2A,
    Two_Wake_Category_Appx_B AS RECAT_2B,
    CASE FAA_Weight
        WHEN 'Super' THEN 'J'
        WHEN 'Heavy' THEN 'H'
        WHEN 'Large' THEN 'L'
        WHEN 'Small' THEN 'S'
        WHEN 'Small+' THEN 'S'
        ELSE 'L'
    END AS Weight_Code,
    Wingspan_ft_without_winglets,
    Wingspan_ft_with_winglets,
    COALESCE(Wingspan_ft_with_winglets, Wingspan_ft_without_winglets) AS Wingspan_ft,
    Length_ft,
    MTOW_lb,
    SRS,
    Class
FROM dbo.ACD_Data;
GO

PRINT 'Created helper functions and views';
GO

PRINT '';
PRINT '=== Migration 025 Complete ===';
PRINT 'Finished at: ' + CONVERT(VARCHAR, GETUTCDATE(), 120);
PRINT '';
PRINT 'Next step: Run the seed data script to populate ACD_Data';
GO
