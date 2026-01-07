-- =====================================================
-- Airport Groupings Schema
-- Migration: 075_airport_groupings.sql
-- Database: VATSIM_ADL (Azure SQL)
-- Purpose: Define named airport groupings for events/analysis
--          with criteria-based membership
-- =====================================================

SET NOCOUNT ON;
GO

-- =====================================================
-- 1. GROUPING DEFINITION TABLE
-- Defines what groupings exist and their criteria
-- =====================================================

IF EXISTS (SELECT * FROM sys.tables WHERE name = 'airport_grouping_member')
    DROP TABLE dbo.airport_grouping_member;
IF EXISTS (SELECT * FROM sys.tables WHERE name = 'airport_grouping')
    DROP TABLE dbo.airport_grouping;
GO

CREATE TABLE dbo.airport_grouping (
    grouping_id         INT IDENTITY(1,1) PRIMARY KEY,
    grouping_name       VARCHAR(64) NOT NULL,           -- e.g., 'ZLA Minors', 'N90 Minors'
    grouping_code       VARCHAR(32) NOT NULL,           -- e.g., 'ZLA_MINORS', 'N90_MINORS'
    category            VARCHAR(16) NOT NULL,           -- 'MAJOR' or 'MINOR'

    -- Facility filter (either ARTCC or TRACON, not both)
    filter_artcc        VARCHAR(4) NULL,                -- e.g., 'ZLA', 'ZFW' (responsible ARTCC)
    filter_tracon       VARCHAR(4) NULL,                -- e.g., 'N90', 'MIA' (approach facility)

    -- For MAJOR: which designation tier to check (in order)
    -- Fallback hierarchy: Core30 -> OEP35 -> ASPM77
    require_major_tier  BIT DEFAULT 0,                  -- 1 = must match Core30/OEP35/ASPM77

    -- For MINOR: exclude Core30 airports
    exclude_core30      BIT DEFAULT 0,                  -- 1 = exclude Core30 airports

    -- All groupings require commercial service
    require_commercial  BIT DEFAULT 1,                  -- 1 = must have tower (ATCT or ATCT-TRACON)

    -- For ARTCC TRACON groupings: filter by TRACON type
    filter_tracon_type  VARCHAR(16) NULL,               -- 'MAJOR_TRACON', 'MINOR_TRACON', 'ALL_TRACON', 'MIL_TRACON'

    description         NVARCHAR(256) NULL,
    is_active           BIT DEFAULT 1,
    created_utc         DATETIME2 DEFAULT GETUTCDATE(),
    updated_utc         DATETIME2 NULL,

    CONSTRAINT UQ_airport_grouping_code UNIQUE (grouping_code),
    CONSTRAINT CK_airport_grouping_facility CHECK (
        filter_artcc IS NOT NULL OR filter_tracon IS NOT NULL
    )
);
GO

PRINT 'Created airport_grouping table';
GO

-- =====================================================
-- 2. GROUPING MEMBERSHIP TABLE
-- Stores which airports belong to each grouping
-- =====================================================

CREATE TABLE dbo.airport_grouping_member (
    id                  BIGINT IDENTITY(1,1) PRIMARY KEY,
    grouping_id         INT NOT NULL,
    airport_icao        VARCHAR(4) NOT NULL,

    -- Metadata about why this airport qualifies
    matched_by          VARCHAR(16) NULL,               -- 'CORE30', 'OEP35', 'ASPM77', 'COMMERCIAL'

    created_utc         DATETIME2 DEFAULT GETUTCDATE(),

    CONSTRAINT FK_grouping_member_grouping FOREIGN KEY (grouping_id)
        REFERENCES dbo.airport_grouping(grouping_id) ON DELETE CASCADE,
    CONSTRAINT UQ_grouping_member UNIQUE (grouping_id, airport_icao),
    INDEX IX_grouping_member_airport (airport_icao),
    INDEX IX_grouping_member_grouping (grouping_id)
);
GO

PRINT 'Created airport_grouping_member table';
GO

-- =====================================================
-- 3. MAJOR TRACON REFERENCE TABLE
-- Defines which TRACONs are considered "major"
-- =====================================================

IF EXISTS (SELECT * FROM sys.tables WHERE name = 'major_tracon')
    DROP TABLE dbo.major_tracon;
GO

CREATE TABLE dbo.major_tracon (
    tracon_id       VARCHAR(4) PRIMARY KEY,
    tracon_name     VARCHAR(64) NOT NULL,
    artcc_id        VARCHAR(4) NOT NULL              -- Which ARTCC this TRACON is in
);
GO

INSERT INTO dbo.major_tracon (tracon_id, tracon_name, artcc_id) VALUES
    ('A80', 'Atlanta TRACON', 'ZTL'),
    ('C90', 'Chicago TRACON', 'ZAU'),
    ('D01', 'Denver TRACON', 'ZDV'),
    ('D10', 'Dallas TRACON', 'ZFW'),
    ('F11', 'Central Florida TRACON', 'ZJX'),
    ('I90', 'Houston TRACON', 'ZHU'),
    ('M98', 'Minneapolis TRACON', 'ZMP'),
    ('MIA', 'Miami TRACON', 'ZMA'),
    ('N90', 'New York TRACON', 'ZNY'),
    ('NCT', 'NorCal TRACON', 'ZOA'),
    ('P50', 'Phoenix TRACON', 'ZAB'),
    ('PCT', 'Potomac TRACON', 'ZDC'),
    ('R90', 'Indianapolis TRACON', 'ZID'),
    ('S56', 'Seattle TRACON', 'ZSE'),
    ('SCT', 'SoCal TRACON', 'ZLA'),
    ('T75', 'St Louis TRACON', 'ZKC'),
    ('Y90', 'Yankee TRACON', 'ZBW');
GO

PRINT 'Created and populated major_tracon reference table';
GO

-- =====================================================
-- 4. SEED THE GROUPING DEFINITIONS
-- All US ARTCCs and Major TRACONs (MAJOR + MINOR for each)
-- =====================================================

INSERT INTO dbo.airport_grouping
    (grouping_name, grouping_code, category, filter_artcc, filter_tracon,
     require_major_tier, exclude_core30, require_commercial, description)
VALUES
    -- =========================================
    -- US ARTCCs - MAJORS (Core30/OEP35/ASPM77)
    -- =========================================
    ('ZAB Majors', 'ZAB_MAJORS', 'MAJOR', 'ZAB', NULL, 1, 0, 1, 'Albuquerque Center major airports'),
    ('ZAN Majors', 'ZAN_MAJORS', 'MAJOR', 'ZAN', NULL, 1, 0, 1, 'Anchorage Center major airports'),
    ('ZAU Majors', 'ZAU_MAJORS', 'MAJOR', 'ZAU', NULL, 1, 0, 1, 'Chicago Center major airports'),
    ('ZBW Majors', 'ZBW_MAJORS', 'MAJOR', 'ZBW', NULL, 1, 0, 1, 'Boston Center major airports'),
    ('ZDC Majors', 'ZDC_MAJORS', 'MAJOR', 'ZDC', NULL, 1, 0, 1, 'Washington Center major airports'),
    ('ZDV Majors', 'ZDV_MAJORS', 'MAJOR', 'ZDV', NULL, 1, 0, 1, 'Denver Center major airports'),
    ('ZFW Majors', 'ZFW_MAJORS', 'MAJOR', 'ZFW', NULL, 1, 0, 1, 'Fort Worth Center major airports'),
    ('ZHN Majors', 'ZHN_MAJORS', 'MAJOR', 'ZHN', NULL, 1, 0, 1, 'Honolulu Center major airports'),
    ('ZHU Majors', 'ZHU_MAJORS', 'MAJOR', 'ZHU', NULL, 1, 0, 1, 'Houston Center major airports'),
    ('ZID Majors', 'ZID_MAJORS', 'MAJOR', 'ZID', NULL, 1, 0, 1, 'Indianapolis Center major airports'),
    ('ZJX Majors', 'ZJX_MAJORS', 'MAJOR', 'ZJX', NULL, 1, 0, 1, 'Jacksonville Center major airports'),
    ('ZKC Majors', 'ZKC_MAJORS', 'MAJOR', 'ZKC', NULL, 1, 0, 1, 'Kansas City Center major airports'),
    ('ZLA Majors', 'ZLA_MAJORS', 'MAJOR', 'ZLA', NULL, 1, 0, 1, 'Los Angeles Center major airports'),
    ('ZLC Majors', 'ZLC_MAJORS', 'MAJOR', 'ZLC', NULL, 1, 0, 1, 'Salt Lake Center major airports'),
    ('ZMA Majors', 'ZMA_MAJORS', 'MAJOR', 'ZMA', NULL, 1, 0, 1, 'Miami Center major airports'),
    ('ZME Majors', 'ZME_MAJORS', 'MAJOR', 'ZME', NULL, 1, 0, 1, 'Memphis Center major airports'),
    ('ZMP Majors', 'ZMP_MAJORS', 'MAJOR', 'ZMP', NULL, 1, 0, 1, 'Minneapolis Center major airports'),
    ('ZNY Majors', 'ZNY_MAJORS', 'MAJOR', 'ZNY', NULL, 1, 0, 1, 'New York Center major airports'),
    ('ZOA Majors', 'ZOA_MAJORS', 'MAJOR', 'ZOA', NULL, 1, 0, 1, 'Oakland Center major airports'),
    ('ZOB Majors', 'ZOB_MAJORS', 'MAJOR', 'ZOB', NULL, 1, 0, 1, 'Cleveland Center major airports'),
    ('ZSE Majors', 'ZSE_MAJORS', 'MAJOR', 'ZSE', NULL, 1, 0, 1, 'Seattle Center major airports'),
    ('ZTL Majors', 'ZTL_MAJORS', 'MAJOR', 'ZTL', NULL, 1, 0, 1, 'Atlanta Center major airports'),

    -- =========================================
    -- US ARTCCs - MINORS (not Core30)
    -- =========================================
    ('ZAB Minors', 'ZAB_MINORS', 'MINOR', 'ZAB', NULL, 0, 1, 1, 'Albuquerque Center minor airports'),
    ('ZAN Minors', 'ZAN_MINORS', 'MINOR', 'ZAN', NULL, 0, 1, 1, 'Anchorage Center minor airports'),
    ('ZAU Minors', 'ZAU_MINORS', 'MINOR', 'ZAU', NULL, 0, 1, 1, 'Chicago Center minor airports'),
    ('ZBW Minors', 'ZBW_MINORS', 'MINOR', 'ZBW', NULL, 0, 1, 1, 'Boston Center minor airports'),
    ('ZDC Minors', 'ZDC_MINORS', 'MINOR', 'ZDC', NULL, 0, 1, 1, 'Washington Center minor airports'),
    ('ZDV Minors', 'ZDV_MINORS', 'MINOR', 'ZDV', NULL, 0, 1, 1, 'Denver Center minor airports'),
    ('ZFW Minors', 'ZFW_MINORS', 'MINOR', 'ZFW', NULL, 0, 1, 1, 'Fort Worth Center minor airports'),
    ('ZHN Minors', 'ZHN_MINORS', 'MINOR', 'ZHN', NULL, 0, 1, 1, 'Honolulu Center minor airports'),
    ('ZHU Minors', 'ZHU_MINORS', 'MINOR', 'ZHU', NULL, 0, 1, 1, 'Houston Center minor airports'),
    ('ZID Minors', 'ZID_MINORS', 'MINOR', 'ZID', NULL, 0, 1, 1, 'Indianapolis Center minor airports'),
    ('ZJX Minors', 'ZJX_MINORS', 'MINOR', 'ZJX', NULL, 0, 1, 1, 'Jacksonville Center minor airports'),
    ('ZKC Minors', 'ZKC_MINORS', 'MINOR', 'ZKC', NULL, 0, 1, 1, 'Kansas City Center minor airports'),
    ('ZLA Minors', 'ZLA_MINORS', 'MINOR', 'ZLA', NULL, 0, 1, 1, 'Los Angeles Center minor airports'),
    ('ZLC Minors', 'ZLC_MINORS', 'MINOR', 'ZLC', NULL, 0, 1, 1, 'Salt Lake Center minor airports'),
    ('ZMA Minors', 'ZMA_MINORS', 'MINOR', 'ZMA', NULL, 0, 1, 1, 'Miami Center minor airports'),
    ('ZME Minors', 'ZME_MINORS', 'MINOR', 'ZME', NULL, 0, 1, 1, 'Memphis Center minor airports'),
    ('ZMP Minors', 'ZMP_MINORS', 'MINOR', 'ZMP', NULL, 0, 1, 1, 'Minneapolis Center minor airports'),
    ('ZNY Minors', 'ZNY_MINORS', 'MINOR', 'ZNY', NULL, 0, 1, 1, 'New York Center minor airports'),
    ('ZOA Minors', 'ZOA_MINORS', 'MINOR', 'ZOA', NULL, 0, 1, 1, 'Oakland Center minor airports'),
    ('ZOB Minors', 'ZOB_MINORS', 'MINOR', 'ZOB', NULL, 0, 1, 1, 'Cleveland Center minor airports'),
    ('ZSE Minors', 'ZSE_MINORS', 'MINOR', 'ZSE', NULL, 0, 1, 1, 'Seattle Center minor airports'),
    ('ZTL Minors', 'ZTL_MINORS', 'MINOR', 'ZTL', NULL, 0, 1, 1, 'Atlanta Center minor airports'),

    -- =========================================
    -- Major TRACONs - MAJORS (Core30/OEP35/ASPM77)
    -- =========================================
    ('A80 Majors', 'A80_MAJORS', 'MAJOR', NULL, 'A80', 1, 0, 1, 'Atlanta TRACON major airports'),
    ('C90 Majors', 'C90_MAJORS', 'MAJOR', NULL, 'C90', 1, 0, 1, 'Chicago TRACON major airports'),
    ('D01 Majors', 'D01_MAJORS', 'MAJOR', NULL, 'D01', 1, 0, 1, 'Denver TRACON major airports'),
    ('D10 Majors', 'D10_MAJORS', 'MAJOR', NULL, 'D10', 1, 0, 1, 'Dallas TRACON major airports'),
    ('F11 Majors', 'F11_MAJORS', 'MAJOR', NULL, 'F11', 1, 0, 1, 'Central Florida TRACON major airports'),
    ('I90 Majors', 'I90_MAJORS', 'MAJOR', NULL, 'I90', 1, 0, 1, 'Houston TRACON major airports'),
    ('M98 Majors', 'M98_MAJORS', 'MAJOR', NULL, 'M98', 1, 0, 1, 'Minneapolis TRACON major airports'),
    ('MIA Majors', 'MIA_MAJORS', 'MAJOR', NULL, 'MIA', 1, 0, 1, 'Miami TRACON major airports'),
    ('N90 Majors', 'N90_MAJORS', 'MAJOR', NULL, 'N90', 1, 0, 1, 'New York TRACON major airports'),
    ('NCT Majors', 'NCT_MAJORS', 'MAJOR', NULL, 'NCT', 1, 0, 1, 'NorCal TRACON major airports'),
    ('P50 Majors', 'P50_MAJORS', 'MAJOR', NULL, 'P50', 1, 0, 1, 'Phoenix TRACON major airports'),
    ('PCT Majors', 'PCT_MAJORS', 'MAJOR', NULL, 'PCT', 1, 0, 1, 'Potomac TRACON major airports'),
    ('R90 Majors', 'R90_MAJORS', 'MAJOR', NULL, 'R90', 1, 0, 1, 'Indianapolis TRACON major airports'),
    ('S56 Majors', 'S56_MAJORS', 'MAJOR', NULL, 'S56', 1, 0, 1, 'Seattle TRACON major airports'),
    ('SCT Majors', 'SCT_MAJORS', 'MAJOR', NULL, 'SCT', 1, 0, 1, 'SoCal TRACON major airports'),
    ('T75 Majors', 'T75_MAJORS', 'MAJOR', NULL, 'T75', 1, 0, 1, 'St Louis TRACON major airports'),
    ('Y90 Majors', 'Y90_MAJORS', 'MAJOR', NULL, 'Y90', 1, 0, 1, 'Yankee TRACON major airports'),

    -- =========================================
    -- Major TRACONs - MINORS (not Core30)
    -- =========================================
    ('A80 Minors', 'A80_MINORS', 'MINOR', NULL, 'A80', 0, 1, 1, 'Atlanta TRACON minor airports'),
    ('C90 Minors', 'C90_MINORS', 'MINOR', NULL, 'C90', 0, 1, 1, 'Chicago TRACON minor airports'),
    ('D01 Minors', 'D01_MINORS', 'MINOR', NULL, 'D01', 0, 1, 1, 'Denver TRACON minor airports'),
    ('D10 Minors', 'D10_MINORS', 'MINOR', NULL, 'D10', 0, 1, 1, 'Dallas TRACON minor airports'),
    ('F11 Minors', 'F11_MINORS', 'MINOR', NULL, 'F11', 0, 1, 1, 'Central Florida TRACON minor airports'),
    ('I90 Minors', 'I90_MINORS', 'MINOR', NULL, 'I90', 0, 1, 1, 'Houston TRACON minor airports'),
    ('M98 Minors', 'M98_MINORS', 'MINOR', NULL, 'M98', 0, 1, 1, 'Minneapolis TRACON minor airports'),
    ('MIA Minors', 'MIA_MINORS', 'MINOR', NULL, 'MIA', 0, 1, 1, 'Miami TRACON minor airports'),
    ('N90 Minors', 'N90_MINORS', 'MINOR', NULL, 'N90', 0, 1, 1, 'New York TRACON minor airports'),
    ('NCT Minors', 'NCT_MINORS', 'MINOR', NULL, 'NCT', 0, 1, 1, 'NorCal TRACON minor airports'),
    ('P50 Minors', 'P50_MINORS', 'MINOR', NULL, 'P50', 0, 1, 1, 'Phoenix TRACON minor airports'),
    ('PCT Minors', 'PCT_MINORS', 'MINOR', NULL, 'PCT', 0, 1, 1, 'Potomac TRACON minor airports'),
    ('R90 Minors', 'R90_MINORS', 'MINOR', NULL, 'R90', 0, 1, 1, 'Indianapolis TRACON minor airports'),
    ('S56 Minors', 'S56_MINORS', 'MINOR', NULL, 'S56', 0, 1, 1, 'Seattle TRACON minor airports'),
    ('SCT Minors', 'SCT_MINORS', 'MINOR', NULL, 'SCT', 0, 1, 1, 'SoCal TRACON minor airports'),
    ('T75 Minors', 'T75_MINORS', 'MINOR', NULL, 'T75', 0, 1, 1, 'St Louis TRACON minor airports'),
    ('Y90 Minors', 'Y90_MINORS', 'MINOR', NULL, 'Y90', 0, 1, 1, 'Yankee TRACON minor airports');
GO

PRINT 'Seeded 78 base grouping definitions (22 ARTCCs x 2 + 17 major TRACONs x 2)';
GO

-- =========================================
-- ARTCCs - MAJOR TRACONs (airports served by major TRACONs in the ARTCC)
-- =========================================
INSERT INTO dbo.airport_grouping
    (grouping_name, grouping_code, category, filter_artcc, filter_tracon,
     require_major_tier, exclude_core30, require_commercial, filter_tracon_type, description)
VALUES
    ('ZAB Major TRACONs', 'ZAB_MAJOR_TRACONS', 'TRACON', 'ZAB', NULL, 0, 0, 1, 'MAJOR_TRACON', 'Albuquerque Center airports in major TRACONs (P50)'),
    ('ZAN Major TRACONs', 'ZAN_MAJOR_TRACONS', 'TRACON', 'ZAN', NULL, 0, 0, 1, 'MAJOR_TRACON', 'Anchorage Center airports in major TRACONs'),
    ('ZAU Major TRACONs', 'ZAU_MAJOR_TRACONS', 'TRACON', 'ZAU', NULL, 0, 0, 1, 'MAJOR_TRACON', 'Chicago Center airports in major TRACONs (C90)'),
    ('ZBW Major TRACONs', 'ZBW_MAJOR_TRACONS', 'TRACON', 'ZBW', NULL, 0, 0, 1, 'MAJOR_TRACON', 'Boston Center airports in major TRACONs (Y90)'),
    ('ZDC Major TRACONs', 'ZDC_MAJOR_TRACONS', 'TRACON', 'ZDC', NULL, 0, 0, 1, 'MAJOR_TRACON', 'Washington Center airports in major TRACONs (PCT)'),
    ('ZDV Major TRACONs', 'ZDV_MAJOR_TRACONS', 'TRACON', 'ZDV', NULL, 0, 0, 1, 'MAJOR_TRACON', 'Denver Center airports in major TRACONs (D01)'),
    ('ZFW Major TRACONs', 'ZFW_MAJOR_TRACONS', 'TRACON', 'ZFW', NULL, 0, 0, 1, 'MAJOR_TRACON', 'Fort Worth Center airports in major TRACONs (D10)'),
    ('ZHN Major TRACONs', 'ZHN_MAJOR_TRACONS', 'TRACON', 'ZHN', NULL, 0, 0, 1, 'MAJOR_TRACON', 'Honolulu Center airports in major TRACONs'),
    ('ZHU Major TRACONs', 'ZHU_MAJOR_TRACONS', 'TRACON', 'ZHU', NULL, 0, 0, 1, 'MAJOR_TRACON', 'Houston Center airports in major TRACONs (I90)'),
    ('ZID Major TRACONs', 'ZID_MAJOR_TRACONS', 'TRACON', 'ZID', NULL, 0, 0, 1, 'MAJOR_TRACON', 'Indianapolis Center airports in major TRACONs (R90)'),
    ('ZJX Major TRACONs', 'ZJX_MAJOR_TRACONS', 'TRACON', 'ZJX', NULL, 0, 0, 1, 'MAJOR_TRACON', 'Jacksonville Center airports in major TRACONs (F11)'),
    ('ZKC Major TRACONs', 'ZKC_MAJOR_TRACONS', 'TRACON', 'ZKC', NULL, 0, 0, 1, 'MAJOR_TRACON', 'Kansas City Center airports in major TRACONs (T75)'),
    ('ZLA Major TRACONs', 'ZLA_MAJOR_TRACONS', 'TRACON', 'ZLA', NULL, 0, 0, 1, 'MAJOR_TRACON', 'Los Angeles Center airports in major TRACONs (SCT)'),
    ('ZLC Major TRACONs', 'ZLC_MAJOR_TRACONS', 'TRACON', 'ZLC', NULL, 0, 0, 1, 'MAJOR_TRACON', 'Salt Lake Center airports in major TRACONs'),
    ('ZMA Major TRACONs', 'ZMA_MAJOR_TRACONS', 'TRACON', 'ZMA', NULL, 0, 0, 1, 'MAJOR_TRACON', 'Miami Center airports in major TRACONs (MIA)'),
    ('ZME Major TRACONs', 'ZME_MAJOR_TRACONS', 'TRACON', 'ZME', NULL, 0, 0, 1, 'MAJOR_TRACON', 'Memphis Center airports in major TRACONs'),
    ('ZMP Major TRACONs', 'ZMP_MAJOR_TRACONS', 'TRACON', 'ZMP', NULL, 0, 0, 1, 'MAJOR_TRACON', 'Minneapolis Center airports in major TRACONs (M98)'),
    ('ZNY Major TRACONs', 'ZNY_MAJOR_TRACONS', 'TRACON', 'ZNY', NULL, 0, 0, 1, 'MAJOR_TRACON', 'New York Center airports in major TRACONs (N90)'),
    ('ZOA Major TRACONs', 'ZOA_MAJOR_TRACONS', 'TRACON', 'ZOA', NULL, 0, 0, 1, 'MAJOR_TRACON', 'Oakland Center airports in major TRACONs (NCT)'),
    ('ZOB Major TRACONs', 'ZOB_MAJOR_TRACONS', 'TRACON', 'ZOB', NULL, 0, 0, 1, 'MAJOR_TRACON', 'Cleveland Center airports in major TRACONs'),
    ('ZSE Major TRACONs', 'ZSE_MAJOR_TRACONS', 'TRACON', 'ZSE', NULL, 0, 0, 1, 'MAJOR_TRACON', 'Seattle Center airports in major TRACONs (S56)'),
    ('ZTL Major TRACONs', 'ZTL_MAJOR_TRACONS', 'TRACON', 'ZTL', NULL, 0, 0, 1, 'MAJOR_TRACON', 'Atlanta Center airports in major TRACONs (A80)');
GO

-- =========================================
-- ARTCCs - MINOR TRACONs (airports served by minor TRACONs in the ARTCC)
-- =========================================
INSERT INTO dbo.airport_grouping
    (grouping_name, grouping_code, category, filter_artcc, filter_tracon,
     require_major_tier, exclude_core30, require_commercial, filter_tracon_type, description)
VALUES
    ('ZAB Minor TRACONs', 'ZAB_MINOR_TRACONS', 'TRACON', 'ZAB', NULL, 0, 0, 1, 'MINOR_TRACON', 'Albuquerque Center airports in minor TRACONs'),
    ('ZAN Minor TRACONs', 'ZAN_MINOR_TRACONS', 'TRACON', 'ZAN', NULL, 0, 0, 1, 'MINOR_TRACON', 'Anchorage Center airports in minor TRACONs'),
    ('ZAU Minor TRACONs', 'ZAU_MINOR_TRACONS', 'TRACON', 'ZAU', NULL, 0, 0, 1, 'MINOR_TRACON', 'Chicago Center airports in minor TRACONs'),
    ('ZBW Minor TRACONs', 'ZBW_MINOR_TRACONS', 'TRACON', 'ZBW', NULL, 0, 0, 1, 'MINOR_TRACON', 'Boston Center airports in minor TRACONs'),
    ('ZDC Minor TRACONs', 'ZDC_MINOR_TRACONS', 'TRACON', 'ZDC', NULL, 0, 0, 1, 'MINOR_TRACON', 'Washington Center airports in minor TRACONs'),
    ('ZDV Minor TRACONs', 'ZDV_MINOR_TRACONS', 'TRACON', 'ZDV', NULL, 0, 0, 1, 'MINOR_TRACON', 'Denver Center airports in minor TRACONs'),
    ('ZFW Minor TRACONs', 'ZFW_MINOR_TRACONS', 'TRACON', 'ZFW', NULL, 0, 0, 1, 'MINOR_TRACON', 'Fort Worth Center airports in minor TRACONs'),
    ('ZHN Minor TRACONs', 'ZHN_MINOR_TRACONS', 'TRACON', 'ZHN', NULL, 0, 0, 1, 'MINOR_TRACON', 'Honolulu Center airports in minor TRACONs'),
    ('ZHU Minor TRACONs', 'ZHU_MINOR_TRACONS', 'TRACON', 'ZHU', NULL, 0, 0, 1, 'MINOR_TRACON', 'Houston Center airports in minor TRACONs'),
    ('ZID Minor TRACONs', 'ZID_MINOR_TRACONS', 'TRACON', 'ZID', NULL, 0, 0, 1, 'MINOR_TRACON', 'Indianapolis Center airports in minor TRACONs'),
    ('ZJX Minor TRACONs', 'ZJX_MINOR_TRACONS', 'TRACON', 'ZJX', NULL, 0, 0, 1, 'MINOR_TRACON', 'Jacksonville Center airports in minor TRACONs'),
    ('ZKC Minor TRACONs', 'ZKC_MINOR_TRACONS', 'TRACON', 'ZKC', NULL, 0, 0, 1, 'MINOR_TRACON', 'Kansas City Center airports in minor TRACONs'),
    ('ZLA Minor TRACONs', 'ZLA_MINOR_TRACONS', 'TRACON', 'ZLA', NULL, 0, 0, 1, 'MINOR_TRACON', 'Los Angeles Center airports in minor TRACONs'),
    ('ZLC Minor TRACONs', 'ZLC_MINOR_TRACONS', 'TRACON', 'ZLC', NULL, 0, 0, 1, 'MINOR_TRACON', 'Salt Lake Center airports in minor TRACONs'),
    ('ZMA Minor TRACONs', 'ZMA_MINOR_TRACONS', 'TRACON', 'ZMA', NULL, 0, 0, 1, 'MINOR_TRACON', 'Miami Center airports in minor TRACONs'),
    ('ZME Minor TRACONs', 'ZME_MINOR_TRACONS', 'TRACON', 'ZME', NULL, 0, 0, 1, 'MINOR_TRACON', 'Memphis Center airports in minor TRACONs'),
    ('ZMP Minor TRACONs', 'ZMP_MINOR_TRACONS', 'TRACON', 'ZMP', NULL, 0, 0, 1, 'MINOR_TRACON', 'Minneapolis Center airports in minor TRACONs'),
    ('ZNY Minor TRACONs', 'ZNY_MINOR_TRACONS', 'TRACON', 'ZNY', NULL, 0, 0, 1, 'MINOR_TRACON', 'New York Center airports in minor TRACONs'),
    ('ZOA Minor TRACONs', 'ZOA_MINOR_TRACONS', 'TRACON', 'ZOA', NULL, 0, 0, 1, 'MINOR_TRACON', 'Oakland Center airports in minor TRACONs'),
    ('ZOB Minor TRACONs', 'ZOB_MINOR_TRACONS', 'TRACON', 'ZOB', NULL, 0, 0, 1, 'MINOR_TRACON', 'Cleveland Center airports in minor TRACONs'),
    ('ZSE Minor TRACONs', 'ZSE_MINOR_TRACONS', 'TRACON', 'ZSE', NULL, 0, 0, 1, 'MINOR_TRACON', 'Seattle Center airports in minor TRACONs'),
    ('ZTL Minor TRACONs', 'ZTL_MINOR_TRACONS', 'TRACON', 'ZTL', NULL, 0, 0, 1, 'MINOR_TRACON', 'Atlanta Center airports in minor TRACONs');
GO

-- =========================================
-- ARTCCs - ALL TRACONs (all airports served by any TRACON in the ARTCC)
-- =========================================
INSERT INTO dbo.airport_grouping
    (grouping_name, grouping_code, category, filter_artcc, filter_tracon,
     require_major_tier, exclude_core30, require_commercial, filter_tracon_type, description)
VALUES
    ('ZAB TRACONs', 'ZAB_TRACONS', 'TRACON', 'ZAB', NULL, 0, 0, 1, 'ALL_TRACON', 'Albuquerque Center airports in all TRACONs'),
    ('ZAN TRACONs', 'ZAN_TRACONS', 'TRACON', 'ZAN', NULL, 0, 0, 1, 'ALL_TRACON', 'Anchorage Center airports in all TRACONs'),
    ('ZAU TRACONs', 'ZAU_TRACONS', 'TRACON', 'ZAU', NULL, 0, 0, 1, 'ALL_TRACON', 'Chicago Center airports in all TRACONs'),
    ('ZBW TRACONs', 'ZBW_TRACONS', 'TRACON', 'ZBW', NULL, 0, 0, 1, 'ALL_TRACON', 'Boston Center airports in all TRACONs'),
    ('ZDC TRACONs', 'ZDC_TRACONS', 'TRACON', 'ZDC', NULL, 0, 0, 1, 'ALL_TRACON', 'Washington Center airports in all TRACONs'),
    ('ZDV TRACONs', 'ZDV_TRACONS', 'TRACON', 'ZDV', NULL, 0, 0, 1, 'ALL_TRACON', 'Denver Center airports in all TRACONs'),
    ('ZFW TRACONs', 'ZFW_TRACONS', 'TRACON', 'ZFW', NULL, 0, 0, 1, 'ALL_TRACON', 'Fort Worth Center airports in all TRACONs'),
    ('ZHN TRACONs', 'ZHN_TRACONS', 'TRACON', 'ZHN', NULL, 0, 0, 1, 'ALL_TRACON', 'Honolulu Center airports in all TRACONs'),
    ('ZHU TRACONs', 'ZHU_TRACONS', 'TRACON', 'ZHU', NULL, 0, 0, 1, 'ALL_TRACON', 'Houston Center airports in all TRACONs'),
    ('ZID TRACONs', 'ZID_TRACONS', 'TRACON', 'ZID', NULL, 0, 0, 1, 'ALL_TRACON', 'Indianapolis Center airports in all TRACONs'),
    ('ZJX TRACONs', 'ZJX_TRACONS', 'TRACON', 'ZJX', NULL, 0, 0, 1, 'ALL_TRACON', 'Jacksonville Center airports in all TRACONs'),
    ('ZKC TRACONs', 'ZKC_TRACONS', 'TRACON', 'ZKC', NULL, 0, 0, 1, 'ALL_TRACON', 'Kansas City Center airports in all TRACONs'),
    ('ZLA TRACONs', 'ZLA_TRACONS', 'TRACON', 'ZLA', NULL, 0, 0, 1, 'ALL_TRACON', 'Los Angeles Center airports in all TRACONs'),
    ('ZLC TRACONs', 'ZLC_TRACONS', 'TRACON', 'ZLC', NULL, 0, 0, 1, 'ALL_TRACON', 'Salt Lake Center airports in all TRACONs'),
    ('ZMA TRACONs', 'ZMA_TRACONS', 'TRACON', 'ZMA', NULL, 0, 0, 1, 'ALL_TRACON', 'Miami Center airports in all TRACONs'),
    ('ZME TRACONs', 'ZME_TRACONS', 'TRACON', 'ZME', NULL, 0, 0, 1, 'ALL_TRACON', 'Memphis Center airports in all TRACONs'),
    ('ZMP TRACONs', 'ZMP_TRACONS', 'TRACON', 'ZMP', NULL, 0, 0, 1, 'ALL_TRACON', 'Minneapolis Center airports in all TRACONs'),
    ('ZNY TRACONs', 'ZNY_TRACONS', 'TRACON', 'ZNY', NULL, 0, 0, 1, 'ALL_TRACON', 'New York Center airports in all TRACONs'),
    ('ZOA TRACONs', 'ZOA_TRACONS', 'TRACON', 'ZOA', NULL, 0, 0, 1, 'ALL_TRACON', 'Oakland Center airports in all TRACONs'),
    ('ZOB TRACONs', 'ZOB_TRACONS', 'TRACON', 'ZOB', NULL, 0, 0, 1, 'ALL_TRACON', 'Cleveland Center airports in all TRACONs'),
    ('ZSE TRACONs', 'ZSE_TRACONS', 'TRACON', 'ZSE', NULL, 0, 0, 1, 'ALL_TRACON', 'Seattle Center airports in all TRACONs'),
    ('ZTL TRACONs', 'ZTL_TRACONS', 'TRACON', 'ZTL', NULL, 0, 0, 1, 'ALL_TRACON', 'Atlanta Center airports in all TRACONs');
GO

-- =========================================
-- ARTCCs - MILITARY (RAPCONs, CERAPs, military tower)
-- =========================================
INSERT INTO dbo.airport_grouping
    (grouping_name, grouping_code, category, filter_artcc, filter_tracon,
     require_major_tier, exclude_core30, require_commercial, filter_tracon_type, description)
VALUES
    ('ZAB Military', 'ZAB_MILITARY', 'MILITARY', 'ZAB', NULL, 0, 0, 0, 'MIL_TRACON', 'Albuquerque Center military facilities'),
    ('ZAN Military', 'ZAN_MILITARY', 'MILITARY', 'ZAN', NULL, 0, 0, 0, 'MIL_TRACON', 'Anchorage Center military facilities'),
    ('ZAU Military', 'ZAU_MILITARY', 'MILITARY', 'ZAU', NULL, 0, 0, 0, 'MIL_TRACON', 'Chicago Center military facilities'),
    ('ZBW Military', 'ZBW_MILITARY', 'MILITARY', 'ZBW', NULL, 0, 0, 0, 'MIL_TRACON', 'Boston Center military facilities'),
    ('ZDC Military', 'ZDC_MILITARY', 'MILITARY', 'ZDC', NULL, 0, 0, 0, 'MIL_TRACON', 'Washington Center military facilities'),
    ('ZDV Military', 'ZDV_MILITARY', 'MILITARY', 'ZDV', NULL, 0, 0, 0, 'MIL_TRACON', 'Denver Center military facilities'),
    ('ZFW Military', 'ZFW_MILITARY', 'MILITARY', 'ZFW', NULL, 0, 0, 0, 'MIL_TRACON', 'Fort Worth Center military facilities'),
    ('ZHN Military', 'ZHN_MILITARY', 'MILITARY', 'ZHN', NULL, 0, 0, 0, 'MIL_TRACON', 'Honolulu Center military facilities'),
    ('ZHU Military', 'ZHU_MILITARY', 'MILITARY', 'ZHU', NULL, 0, 0, 0, 'MIL_TRACON', 'Houston Center military facilities'),
    ('ZID Military', 'ZID_MILITARY', 'MILITARY', 'ZID', NULL, 0, 0, 0, 'MIL_TRACON', 'Indianapolis Center military facilities'),
    ('ZJX Military', 'ZJX_MILITARY', 'MILITARY', 'ZJX', NULL, 0, 0, 0, 'MIL_TRACON', 'Jacksonville Center military facilities'),
    ('ZKC Military', 'ZKC_MILITARY', 'MILITARY', 'ZKC', NULL, 0, 0, 0, 'MIL_TRACON', 'Kansas City Center military facilities'),
    ('ZLA Military', 'ZLA_MILITARY', 'MILITARY', 'ZLA', NULL, 0, 0, 0, 'MIL_TRACON', 'Los Angeles Center military facilities'),
    ('ZLC Military', 'ZLC_MILITARY', 'MILITARY', 'ZLC', NULL, 0, 0, 0, 'MIL_TRACON', 'Salt Lake Center military facilities'),
    ('ZMA Military', 'ZMA_MILITARY', 'MILITARY', 'ZMA', NULL, 0, 0, 0, 'MIL_TRACON', 'Miami Center military facilities'),
    ('ZME Military', 'ZME_MILITARY', 'MILITARY', 'ZME', NULL, 0, 0, 0, 'MIL_TRACON', 'Memphis Center military facilities'),
    ('ZMP Military', 'ZMP_MILITARY', 'MILITARY', 'ZMP', NULL, 0, 0, 0, 'MIL_TRACON', 'Minneapolis Center military facilities'),
    ('ZNY Military', 'ZNY_MILITARY', 'MILITARY', 'ZNY', NULL, 0, 0, 0, 'MIL_TRACON', 'New York Center military facilities'),
    ('ZOA Military', 'ZOA_MILITARY', 'MILITARY', 'ZOA', NULL, 0, 0, 0, 'MIL_TRACON', 'Oakland Center military facilities'),
    ('ZOB Military', 'ZOB_MILITARY', 'MILITARY', 'ZOB', NULL, 0, 0, 0, 'MIL_TRACON', 'Cleveland Center military facilities'),
    ('ZSE Military', 'ZSE_MILITARY', 'MILITARY', 'ZSE', NULL, 0, 0, 0, 'MIL_TRACON', 'Seattle Center military facilities'),
    ('ZTL Military', 'ZTL_MILITARY', 'MILITARY', 'ZTL', NULL, 0, 0, 0, 'MIL_TRACON', 'Atlanta Center military facilities');
GO

PRINT 'Seeded 88 additional ARTCC TRACON groupings (22 ARTCCs x 4 types)';
GO

-- =====================================================
-- 5. STORED PROCEDURE: Generate All TRACON Groupings
-- Scans apts table for all unique TRACONs and creates groupings
-- =====================================================

IF EXISTS (SELECT * FROM sys.procedures WHERE name = 'sp_GenerateTRACONGroupings')
    DROP PROCEDURE dbo.sp_GenerateTRACONGroupings;
GO

CREATE PROCEDURE dbo.sp_GenerateTRACONGroupings
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @tracons_added INT = 0;

    -- Get all unique TRACON IDs from the apts table
    -- Only include valid facility codes (3-4 chars), exclude ARTCC codes (Z prefix), exclude text descriptions
    ;WITH AllTRACONs AS (
        SELECT DISTINCT RTRIM(LTRIM(Approach_ID)) AS tracon_id FROM dbo.apts WHERE Approach_ID IS NOT NULL AND LEN(RTRIM(LTRIM(Approach_ID))) BETWEEN 2 AND 4 AND Approach_ID NOT LIKE 'Z%'
        UNION
        SELECT DISTINCT RTRIM(LTRIM(Consolidated_Approach_ID)) FROM dbo.apts WHERE Consolidated_Approach_ID IS NOT NULL AND LEN(RTRIM(LTRIM(Consolidated_Approach_ID))) BETWEEN 2 AND 4 AND Consolidated_Approach_ID NOT LIKE 'Z%'
        UNION
        SELECT DISTINCT RTRIM(LTRIM(Secondary_Approach_ID)) FROM dbo.apts WHERE Secondary_Approach_ID IS NOT NULL AND LEN(RTRIM(LTRIM(Secondary_Approach_ID))) BETWEEN 2 AND 4 AND Secondary_Approach_ID NOT LIKE 'Z%'
        UNION
        SELECT DISTINCT RTRIM(LTRIM(Approach_Departure_ID)) FROM dbo.apts WHERE Approach_Departure_ID IS NOT NULL AND LEN(RTRIM(LTRIM(Approach_Departure_ID))) BETWEEN 2 AND 4 AND Approach_Departure_ID NOT LIKE 'Z%'
    )
    -- Insert MAJOR groupings for TRACONs that don't already exist
    INSERT INTO dbo.airport_grouping
        (grouping_name, grouping_code, category, filter_artcc, filter_tracon,
         require_major_tier, exclude_core30, require_commercial, description)
    SELECT
        t.tracon_id + ' Majors',
        t.tracon_id + '_MAJORS',
        'MAJOR',
        NULL,
        t.tracon_id,
        1,  -- require_major_tier
        0,  -- exclude_core30
        1,  -- require_commercial
        t.tracon_id + ' TRACON major airports'
    FROM AllTRACONs t
    WHERE NOT EXISTS (
        SELECT 1 FROM dbo.airport_grouping g
        WHERE g.grouping_code = t.tracon_id + '_MAJORS'
    );

    SET @tracons_added = @@ROWCOUNT;

    -- Insert MINOR groupings for TRACONs that don't already exist
    ;WITH AllTRACONs AS (
        SELECT DISTINCT RTRIM(LTRIM(Approach_ID)) AS tracon_id FROM dbo.apts WHERE Approach_ID IS NOT NULL AND LEN(RTRIM(LTRIM(Approach_ID))) BETWEEN 2 AND 4 AND Approach_ID NOT LIKE 'Z%'
        UNION
        SELECT DISTINCT RTRIM(LTRIM(Consolidated_Approach_ID)) FROM dbo.apts WHERE Consolidated_Approach_ID IS NOT NULL AND LEN(RTRIM(LTRIM(Consolidated_Approach_ID))) BETWEEN 2 AND 4 AND Consolidated_Approach_ID NOT LIKE 'Z%'
        UNION
        SELECT DISTINCT RTRIM(LTRIM(Secondary_Approach_ID)) FROM dbo.apts WHERE Secondary_Approach_ID IS NOT NULL AND LEN(RTRIM(LTRIM(Secondary_Approach_ID))) BETWEEN 2 AND 4 AND Secondary_Approach_ID NOT LIKE 'Z%'
        UNION
        SELECT DISTINCT RTRIM(LTRIM(Approach_Departure_ID)) FROM dbo.apts WHERE Approach_Departure_ID IS NOT NULL AND LEN(RTRIM(LTRIM(Approach_Departure_ID))) BETWEEN 2 AND 4 AND Approach_Departure_ID NOT LIKE 'Z%'
    )
    INSERT INTO dbo.airport_grouping
        (grouping_name, grouping_code, category, filter_artcc, filter_tracon,
         require_major_tier, exclude_core30, require_commercial, description)
    SELECT
        t.tracon_id + ' Minors',
        t.tracon_id + '_MINORS',
        'MINOR',
        NULL,
        t.tracon_id,
        0,  -- require_major_tier
        1,  -- exclude_core30
        1,  -- require_commercial
        t.tracon_id + ' TRACON minor airports'
    FROM AllTRACONs t
    WHERE NOT EXISTS (
        SELECT 1 FROM dbo.airport_grouping g
        WHERE g.grouping_code = t.tracon_id + '_MINORS'
    );

    SET @tracons_added = @tracons_added + @@ROWCOUNT;

    PRINT 'Generated ' + CAST(@tracons_added AS VARCHAR) + ' new TRACON groupings';
END;
GO

PRINT 'Created sp_GenerateTRACONGroupings procedure';
GO

-- Run the TRACON generation procedure
EXEC dbo.sp_GenerateTRACONGroupings;
GO

-- =====================================================
-- 6. STORED PROCEDURE: Populate Grouping Members
-- Reads from apts table and populates membership
-- =====================================================

IF EXISTS (SELECT * FROM sys.procedures WHERE name = 'sp_RefreshAirportGroupings')
    DROP PROCEDURE dbo.sp_RefreshAirportGroupings;
GO

CREATE PROCEDURE dbo.sp_RefreshAirportGroupings
    @grouping_code VARCHAR(32) = NULL   -- NULL = refresh all groupings
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @start_time DATETIME2 = GETUTCDATE();
    DECLARE @members_added INT = 0;

    -- Clear existing members for the specified grouping(s)
    IF @grouping_code IS NOT NULL
        DELETE FROM dbo.airport_grouping_member
        WHERE grouping_id IN (SELECT grouping_id FROM dbo.airport_grouping WHERE grouping_code = @grouping_code);
    ELSE
        TRUNCATE TABLE dbo.airport_grouping_member;

    -- Populate each active grouping
    DECLARE @gid INT, @gcode VARCHAR(32), @cat VARCHAR(16);
    DECLARE @artcc VARCHAR(4), @tracon VARCHAR(4);
    DECLARE @req_major BIT, @excl_core30 BIT, @req_commercial BIT;
    DECLARE @tracon_type VARCHAR(16);

    DECLARE grouping_cursor CURSOR LOCAL FAST_FORWARD FOR
        SELECT grouping_id, grouping_code, category,
               filter_artcc, filter_tracon,
               require_major_tier, exclude_core30, require_commercial,
               filter_tracon_type
        FROM dbo.airport_grouping
        WHERE is_active = 1
          AND (@grouping_code IS NULL OR grouping_code = @grouping_code);

    OPEN grouping_cursor;
    FETCH NEXT FROM grouping_cursor INTO @gid, @gcode, @cat, @artcc, @tracon,
                                         @req_major, @excl_core30, @req_commercial, @tracon_type;

    WHILE @@FETCH_STATUS = 0
    BEGIN
        -- Insert matching airports
        INSERT INTO dbo.airport_grouping_member (grouping_id, airport_icao, matched_by)
        SELECT
            @gid,
            a.ICAO_ID,
            CASE
                WHEN @tracon_type = 'MIL_TRACON' THEN 'MILITARY'
                WHEN @tracon_type IS NOT NULL THEN
                    CASE
                        WHEN EXISTS (SELECT 1 FROM dbo.major_tracon mt
                                     WHERE mt.tracon_id IN (a.Approach_ID, a.Consolidated_Approach_ID,
                                                            a.Secondary_Approach_ID, a.Approach_Departure_ID))
                        THEN 'MAJOR_TRACON'
                        ELSE 'MINOR_TRACON'
                    END
                WHEN a.Core30 = 'TRUE' THEN 'CORE30'
                WHEN a.OEP35 = 'TRUE' THEN 'OEP35'
                WHEN a.ASPM77 = 'TRUE' THEN 'ASPM77'
                ELSE 'COMMERCIAL'
            END
        FROM dbo.apts a
        WHERE
            -- Must have ICAO code
            a.ICAO_ID IS NOT NULL
            AND LEN(a.ICAO_ID) = 4

            -- ARTCC filter (if specified)
            AND (@artcc IS NULL OR a.RESP_ARTCC_ID = @artcc)

            -- TRACON filter (if specified) - check approach facility columns
            AND (@tracon IS NULL OR
                 a.Approach_ID = @tracon OR
                 a.Consolidated_Approach_ID = @tracon OR
                 a.Secondary_Approach_ID = @tracon OR
                 a.Approach_Departure_ID = @tracon)

            -- Commercial service filter (has tower)
            AND (@req_commercial = 0 OR a.TWR_TYPE_CODE IN ('ATCT', 'ATCT-TRACON'))

            -- MINOR category: exclude Core30
            AND (@excl_core30 = 0 OR a.Core30 <> 'TRUE')

            -- TRACON type filter (for ARTCC TRACON groupings)
            AND (@tracon_type IS NULL OR (
                CASE @tracon_type
                    WHEN 'MAJOR_TRACON' THEN
                        -- Airport must be served by a major TRACON
                        CASE WHEN EXISTS (
                            SELECT 1 FROM dbo.major_tracon mt
                            WHERE mt.tracon_id IN (a.Approach_ID, a.Consolidated_Approach_ID,
                                                   a.Secondary_Approach_ID, a.Approach_Departure_ID)
                        ) THEN 1 ELSE 0 END
                    WHEN 'MINOR_TRACON' THEN
                        -- Airport must have a TRACON but NOT a major one
                        CASE WHEN (
                            -- Has some TRACON
                            (a.Approach_ID IS NOT NULL AND LEN(RTRIM(LTRIM(a.Approach_ID))) BETWEEN 2 AND 4 AND a.Approach_ID NOT LIKE 'Z%')
                            OR (a.Consolidated_Approach_ID IS NOT NULL AND LEN(RTRIM(LTRIM(a.Consolidated_Approach_ID))) BETWEEN 2 AND 4)
                            OR (a.Secondary_Approach_ID IS NOT NULL AND LEN(RTRIM(LTRIM(a.Secondary_Approach_ID))) BETWEEN 2 AND 4)
                            OR (a.Approach_Departure_ID IS NOT NULL AND LEN(RTRIM(LTRIM(a.Approach_Departure_ID))) BETWEEN 2 AND 4)
                        ) AND NOT EXISTS (
                            -- But NOT a major TRACON
                            SELECT 1 FROM dbo.major_tracon mt
                            WHERE mt.tracon_id IN (a.Approach_ID, a.Consolidated_Approach_ID,
                                                   a.Secondary_Approach_ID, a.Approach_Departure_ID)
                        ) THEN 1 ELSE 0 END
                    WHEN 'ALL_TRACON' THEN
                        -- Airport must have some TRACON (any)
                        CASE WHEN (
                            (a.Approach_ID IS NOT NULL AND LEN(RTRIM(LTRIM(a.Approach_ID))) BETWEEN 2 AND 4 AND a.Approach_ID NOT LIKE 'Z%')
                            OR (a.Consolidated_Approach_ID IS NOT NULL AND LEN(RTRIM(LTRIM(a.Consolidated_Approach_ID))) BETWEEN 2 AND 4)
                            OR (a.Secondary_Approach_ID IS NOT NULL AND LEN(RTRIM(LTRIM(a.Secondary_Approach_ID))) BETWEEN 2 AND 4)
                            OR (a.Approach_Departure_ID IS NOT NULL AND LEN(RTRIM(LTRIM(a.Approach_Departure_ID))) BETWEEN 2 AND 4)
                        ) THEN 1 ELSE 0 END
                    WHEN 'MIL_TRACON' THEN
                        -- Airport is military based on name patterns or TWR_TYPE_CODE
                        -- Run 076_apts_military_columns.sql + NASR import for better detection via IS_MILITARY
                        CASE WHEN (
                            -- Military name patterns
                            a.ARPT_NAME LIKE '%AFB%'
                            OR a.ARPT_NAME LIKE '%AIR FORCE%'
                            OR a.ARPT_NAME LIKE '%NAS %'
                            OR a.ARPT_NAME LIKE '% NAS'
                            OR a.ARPT_NAME LIKE '%NAVAL AIR%'
                            OR a.ARPT_NAME LIKE '%MCAS%'
                            OR a.ARPT_NAME LIKE '%MARINE CORPS%'
                            OR a.ARPT_NAME LIKE '%AAF%'
                            OR a.ARPT_NAME LIKE '%ARMY%'
                            OR a.ARPT_NAME LIKE '%NATIONAL GUARD%'
                            OR a.ARPT_NAME LIKE '%JRB%'
                            OR a.ARPT_NAME LIKE '%JOINT RESERVE%'
                            OR a.ARPT_NAME LIKE '%JOINT BASE%'
                            -- TWR_TYPE_CODE patterns
                            OR a.TWR_TYPE_CODE IN ('RAPCON', 'CERAP', 'RATCF', 'ARAC', 'NON-ATCT-MIL')
                        )
                        THEN 1 ELSE 0 END
                    ELSE 1
                END = 1
            ))

            -- MAJOR category: must match Core30, OEP35, or ASPM77 (in fallback order)
            AND (@req_major = 0 OR (
                -- First try Core30
                a.Core30 = 'TRUE'
                OR (
                    -- If no Core30 airports match the facility filter, try OEP35
                    NOT EXISTS (
                        SELECT 1 FROM dbo.apts x
                        WHERE x.Core30 = 'TRUE'
                          AND (@artcc IS NULL OR x.RESP_ARTCC_ID = @artcc)
                          AND (@tracon IS NULL OR x.Approach_ID = @tracon OR x.Consolidated_Approach_ID = @tracon OR x.Approach_Departure_ID = @tracon)
                          AND x.TWR_TYPE_CODE IN ('ATCT', 'ATCT-TRACON')
                    )
                    AND a.OEP35 = 'TRUE'
                )
                OR (
                    -- If no Core30 or OEP35 airports match, try ASPM77
                    NOT EXISTS (
                        SELECT 1 FROM dbo.apts x
                        WHERE (x.Core30 = 'TRUE' OR x.OEP35 = 'TRUE')
                          AND (@artcc IS NULL OR x.RESP_ARTCC_ID = @artcc)
                          AND (@tracon IS NULL OR x.Approach_ID = @tracon OR x.Consolidated_Approach_ID = @tracon OR x.Approach_Departure_ID = @tracon)
                          AND x.TWR_TYPE_CODE IN ('ATCT', 'ATCT-TRACON')
                    )
                    AND a.ASPM77 = 'TRUE'
                )
            ));

        SET @members_added = @members_added + @@ROWCOUNT;

        FETCH NEXT FROM grouping_cursor INTO @gid, @gcode, @cat, @artcc, @tracon,
                                             @req_major, @excl_core30, @req_commercial, @tracon_type;
    END

    CLOSE grouping_cursor;
    DEALLOCATE grouping_cursor;

    -- Update timestamps
    UPDATE dbo.airport_grouping
    SET updated_utc = GETUTCDATE()
    WHERE is_active = 1
      AND (@grouping_code IS NULL OR grouping_code = @grouping_code);

    PRINT 'Refreshed airport groupings: ' + CAST(@members_added AS VARCHAR) + ' members added';
    PRINT 'Execution time: ' + CAST(DATEDIFF(MILLISECOND, @start_time, GETUTCDATE()) AS VARCHAR) + 'ms';
END;
GO

PRINT 'Created sp_RefreshAirportGroupings procedure';
GO

-- =====================================================
-- 7. VIEW: Airport Groupings with Member Count
-- =====================================================

IF EXISTS (SELECT * FROM sys.views WHERE name = 'vw_airport_groupings')
    DROP VIEW dbo.vw_airport_groupings;
GO

CREATE VIEW dbo.vw_airport_groupings AS
SELECT
    g.grouping_id,
    g.grouping_name,
    g.grouping_code,
    g.category,
    g.filter_artcc,
    g.filter_tracon,
    g.filter_tracon_type,
    g.description,
    g.is_active,
    COUNT(m.airport_icao) AS member_count,
    STRING_AGG(m.airport_icao, ', ') WITHIN GROUP (ORDER BY m.airport_icao) AS airports
FROM dbo.airport_grouping g
LEFT JOIN dbo.airport_grouping_member m ON g.grouping_id = m.grouping_id
GROUP BY
    g.grouping_id, g.grouping_name, g.grouping_code, g.category,
    g.filter_artcc, g.filter_tracon, g.filter_tracon_type, g.description, g.is_active;
GO

PRINT 'Created vw_airport_groupings view';
GO

-- =====================================================
-- 8. VIEW: Flat list of airports with their groupings
-- =====================================================

IF EXISTS (SELECT * FROM sys.views WHERE name = 'vw_airport_grouping_flat')
    DROP VIEW dbo.vw_airport_grouping_flat;
GO

CREATE VIEW dbo.vw_airport_grouping_flat AS
SELECT
    m.airport_icao,
    g.grouping_name,
    g.grouping_code,
    g.category,
    m.matched_by,
    COALESCE(g.filter_artcc, g.filter_tracon) AS facility
FROM dbo.airport_grouping_member m
JOIN dbo.airport_grouping g ON m.grouping_id = g.grouping_id
WHERE g.is_active = 1;
GO

PRINT 'Created vw_airport_grouping_flat view';
GO

-- =====================================================
-- 9. FUNCTION: Get airports for a grouping
-- =====================================================

IF EXISTS (SELECT * FROM sys.objects WHERE object_id = OBJECT_ID(N'dbo.fn_GetAirportGrouping') AND type = 'TF')
    DROP FUNCTION dbo.fn_GetAirportGrouping;
GO

CREATE FUNCTION dbo.fn_GetAirportGrouping(@grouping_code VARCHAR(32))
RETURNS @result TABLE (
    airport_icao VARCHAR(4),
    matched_by VARCHAR(16)
)
AS
BEGIN
    INSERT INTO @result
    SELECT m.airport_icao, m.matched_by
    FROM dbo.airport_grouping_member m
    JOIN dbo.airport_grouping g ON m.grouping_id = g.grouping_id
    WHERE g.grouping_code = @grouping_code
      AND g.is_active = 1;
    RETURN;
END;
GO

PRINT 'Created fn_GetAirportGrouping function';
GO

-- =====================================================
-- SUMMARY
-- =====================================================

PRINT '';
PRINT '075_airport_groupings.sql completed successfully';
PRINT '';
PRINT 'Tables created:';
PRINT '  - airport_grouping: Grouping definitions with criteria';
PRINT '  - airport_grouping_member: Airport membership in groupings';
PRINT '  - major_tracon: Reference table of 17 major TRACONs';
PRINT '';
PRINT 'Views created:';
PRINT '  - vw_airport_groupings: Groupings with member counts and airport list';
PRINT '  - vw_airport_grouping_flat: Flat list of airports with their groupings';
PRINT '';
PRINT 'Functions created:';
PRINT '  - fn_GetAirportGrouping(@grouping_code): Returns airports for a grouping';
PRINT '';
PRINT 'Procedures created:';
PRINT '  - sp_GenerateTRACONGroupings: Auto-generates groupings for all TRACONs in apts table';
PRINT '  - sp_RefreshAirportGroupings: Populates membership based on criteria';
PRINT '';
PRINT 'Seeded groupings (166 base):';
PRINT '  ARTCC Airport Groupings (44):';
PRINT '    - 22 US ARTCCs x 2 (MAJORS + MINORS each)';
PRINT '    ZAB, ZAN, ZAU, ZBW, ZDC, ZDV, ZFW, ZHN, ZHU, ZID, ZJX,';
PRINT '    ZKC, ZLA, ZLC, ZMA, ZME, ZMP, ZNY, ZOA, ZOB, ZSE, ZTL';
PRINT '';
PRINT '  TRACON Airport Groupings (34):';
PRINT '    - 17 Major TRACONs x 2 (MAJORS + MINORS each)';
PRINT '    A80, C90, D01, D10, F11, I90, M98, MIA, N90, NCT,';
PRINT '    P50, PCT, R90, S56, SCT, T75, Y90';
PRINT '';
PRINT '  ARTCC TRACON Groupings (88):';
PRINT '    - 22 ARTCCs x 4 types:';
PRINT '      * ZXX_MAJOR_TRACONS: Airports served by major TRACONs';
PRINT '      * ZXX_MINOR_TRACONS: Airports served by minor TRACONs';
PRINT '      * ZXX_TRACONS: All airports with any TRACON service';
PRINT '      * ZXX_MILITARY: Military facilities (RAPCON, CERAP, etc.)';
PRINT '';
PRINT '  Dynamic TRACON groupings (auto-generated from apts table)';
PRINT '';
PRINT 'Next steps:';
PRINT '  1. Run: EXEC sp_RefreshAirportGroupings';
PRINT '  2. Query: SELECT * FROM vw_airport_groupings';
PRINT '  3. To regenerate TRACON groupings: EXEC sp_GenerateTRACONGroupings';
GO
