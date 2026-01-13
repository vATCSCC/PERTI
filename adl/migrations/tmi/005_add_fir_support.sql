-- ============================================================================
-- Migration: Add FIR (Flight Information Region) Support for International GS/GDP
-- 
-- Changes:
--   1. Add RESP_FIR_ID column to apts table
--   2. Populate US airports with RESP_FIR_ID = RESP_ARTCC_ID (K prefix)
--   3. Populate Canadian airports with FIR IDs (C prefix)
--   4. Create FIR reference table for tier groupings
--
-- Date: 2026-01-13
-- ============================================================================

-- ============================================================================
-- Step 1: Add RESP_FIR_ID column to apts table
-- ============================================================================

IF NOT EXISTS (
    SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_NAME = 'apts' AND COLUMN_NAME = 'RESP_FIR_ID'
)
BEGIN
    ALTER TABLE dbo.apts ADD RESP_FIR_ID NVARCHAR(8) NULL;
    PRINT 'Added RESP_FIR_ID column to apts table';
END
ELSE
BEGIN
    PRINT 'RESP_FIR_ID column already exists';
END
GO

-- ============================================================================
-- Step 2: Populate RESP_FIR_ID for US airports (K prefix)
-- US FIRs map directly to ARTCCs
-- ============================================================================

UPDATE dbo.apts
SET RESP_FIR_ID = RESP_ARTCC_ID
WHERE ICAO_ID LIKE 'K%'
  AND RESP_ARTCC_ID IS NOT NULL
  AND (RESP_FIR_ID IS NULL OR RESP_FIR_ID = '');

PRINT 'Updated US airports: RESP_FIR_ID = RESP_ARTCC_ID';
GO

-- ============================================================================
-- Step 3: Populate RESP_FIR_ID for Canadian airports (C prefix)
-- Canadian FIRs: CZEG (Edmonton), CZUL (Montreal), CZWG (Winnipeg), 
--                CZVR (Vancouver), CZYZ (Toronto), CZQX (Gander Oceanic), CZQM (Moncton)
-- ============================================================================

-- Map Canadian airports to their FIRs based on province/region
UPDATE dbo.apts
SET RESP_FIR_ID = CASE
    -- British Columbia -> Vancouver FIR
    WHEN ICAO_ID LIKE 'CY%' AND (STATE = 'BC' OR LONG_DECIMAL < -115) THEN 'CZVR'
    -- Alberta, Saskatchewan, Manitoba, NWT, Nunavut -> Edmonton/Winnipeg
    WHEN ICAO_ID LIKE 'CY%' AND STATE IN ('AB', 'NT', 'NU') THEN 'CZEG'
    WHEN ICAO_ID LIKE 'CY%' AND STATE IN ('SK', 'MB') THEN 'CZWG'
    -- Ontario -> Toronto FIR
    WHEN ICAO_ID LIKE 'CY%' AND STATE = 'ON' THEN 'CZYZ'
    -- Quebec -> Montreal FIR
    WHEN ICAO_ID LIKE 'CY%' AND STATE = 'QC' THEN 'CZUL'
    -- Atlantic provinces -> Moncton FIR
    WHEN ICAO_ID LIKE 'CY%' AND STATE IN ('NB', 'NS', 'PE', 'NL') THEN 'CZQM'
    -- Fallback: use longitude-based assignment
    WHEN ICAO_ID LIKE 'C%' AND LONG_DECIMAL < -125 THEN 'CZVR'
    WHEN ICAO_ID LIKE 'C%' AND LONG_DECIMAL < -110 THEN 'CZEG'
    WHEN ICAO_ID LIKE 'C%' AND LONG_DECIMAL < -95 THEN 'CZWG'
    WHEN ICAO_ID LIKE 'C%' AND LONG_DECIMAL < -75 THEN 'CZYZ'
    WHEN ICAO_ID LIKE 'C%' AND LONG_DECIMAL < -60 THEN 'CZUL'
    WHEN ICAO_ID LIKE 'C%' THEN 'CZQM'
    ELSE NULL
END
WHERE ICAO_ID LIKE 'C%'
  AND (RESP_FIR_ID IS NULL OR RESP_FIR_ID = '');

PRINT 'Updated Canadian airports with FIR assignments';
GO

-- ============================================================================
-- Step 4: Populate RESP_FIR_ID for international airports using ICAO prefix
-- For non-US/Canada airports, use the 2-letter ICAO prefix as the FIR identifier
-- This allows pattern matching like EG** for UK, LF** for France, etc.
-- ============================================================================

UPDATE dbo.apts
SET RESP_FIR_ID = LEFT(ICAO_ID, 2)
WHERE ICAO_ID NOT LIKE 'K%'
  AND ICAO_ID NOT LIKE 'C%'
  AND ICAO_ID NOT LIKE 'P%'  -- Pacific (handled separately)
  AND LEN(ICAO_ID) = 4
  AND (RESP_FIR_ID IS NULL OR RESP_FIR_ID = '');

-- Pacific region (P prefix) - Keep prefix for pattern matching
UPDATE dbo.apts
SET RESP_FIR_ID = LEFT(ICAO_ID, 2)
WHERE ICAO_ID LIKE 'P%'
  AND LEN(ICAO_ID) = 4
  AND (RESP_FIR_ID IS NULL OR RESP_FIR_ID = '');

PRINT 'Updated international airports with ICAO prefix as FIR';
GO

-- ============================================================================
-- Step 5: Create index for FIR lookups
-- ============================================================================

IF NOT EXISTS (
    SELECT 1 FROM sys.indexes 
    WHERE name = 'IX_apts_RESP_FIR_ID' AND object_id = OBJECT_ID('dbo.apts')
)
BEGIN
    CREATE NONCLUSTERED INDEX IX_apts_RESP_FIR_ID 
    ON dbo.apts (RESP_FIR_ID) 
    WHERE RESP_FIR_ID IS NOT NULL;
    PRINT 'Created index IX_apts_RESP_FIR_ID';
END
GO

-- ============================================================================
-- Step 6: Create FIR reference table for metadata
-- ============================================================================

IF NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_NAME = 'fir_reference')
BEGIN
    CREATE TABLE dbo.fir_reference (
        fir_id NVARCHAR(8) PRIMARY KEY,
        fir_name NVARCHAR(128),
        icao_region CHAR(1),           -- E, L, R, etc.
        country_code CHAR(2),          -- ISO 3166-1 alpha-2
        region_group NVARCHAR(16),     -- EUR, APAC, NAM, SAM, AFR, MID
        is_oceanic BIT DEFAULT 0,
        parent_fir NVARCHAR(8) NULL,   -- For UIR/FIR relationships
        created_utc DATETIME2(0) DEFAULT GETUTCDATE()
    );
    
    PRINT 'Created fir_reference table';
    
    -- Insert major FIRs for reference
    INSERT INTO dbo.fir_reference (fir_id, fir_name, icao_region, country_code, region_group, is_oceanic) VALUES
    -- US ARTCCs (map as FIRs)
    ('ZAB', 'Albuquerque ARTCC', 'K', 'US', 'NAM', 0),
    ('ZAU', 'Chicago ARTCC', 'K', 'US', 'NAM', 0),
    ('ZBW', 'Boston ARTCC', 'K', 'US', 'NAM', 0),
    ('ZDC', 'Washington ARTCC', 'K', 'US', 'NAM', 0),
    ('ZDV', 'Denver ARTCC', 'K', 'US', 'NAM', 0),
    ('ZFW', 'Fort Worth ARTCC', 'K', 'US', 'NAM', 0),
    ('ZHU', 'Houston ARTCC', 'K', 'US', 'NAM', 0),
    ('ZID', 'Indianapolis ARTCC', 'K', 'US', 'NAM', 0),
    ('ZJX', 'Jacksonville ARTCC', 'K', 'US', 'NAM', 0),
    ('ZKC', 'Kansas City ARTCC', 'K', 'US', 'NAM', 0),
    ('ZLA', 'Los Angeles ARTCC', 'K', 'US', 'NAM', 0),
    ('ZLC', 'Salt Lake City ARTCC', 'K', 'US', 'NAM', 0),
    ('ZMA', 'Miami ARTCC', 'K', 'US', 'NAM', 0),
    ('ZME', 'Memphis ARTCC', 'K', 'US', 'NAM', 0),
    ('ZMP', 'Minneapolis ARTCC', 'K', 'US', 'NAM', 0),
    ('ZNY', 'New York ARTCC', 'K', 'US', 'NAM', 0),
    ('ZOA', 'Oakland ARTCC', 'K', 'US', 'NAM', 0),
    ('ZOB', 'Cleveland ARTCC', 'K', 'US', 'NAM', 0),
    ('ZSE', 'Seattle ARTCC', 'K', 'US', 'NAM', 0),
    ('ZTL', 'Atlanta ARTCC', 'K', 'US', 'NAM', 0),
    -- Canadian FIRs
    ('CZEG', 'Edmonton FIR', 'C', 'CA', 'NAM', 0),
    ('CZUL', 'Montreal FIR', 'C', 'CA', 'NAM', 0),
    ('CZWG', 'Winnipeg FIR', 'C', 'CA', 'NAM', 0),
    ('CZVR', 'Vancouver FIR', 'C', 'CA', 'NAM', 0),
    ('CZYZ', 'Toronto FIR', 'C', 'CA', 'NAM', 0),
    ('CZQM', 'Moncton FIR', 'C', 'CA', 'NAM', 0),
    ('CZQX', 'Gander Oceanic FIR', 'C', 'CA', 'NAM', 1),
    -- UK/Ireland
    ('EG', 'UK FIR', 'E', 'GB', 'EUR', 0),
    ('EI', 'Ireland (Shannon) FIR', 'E', 'IE', 'EUR', 0),
    -- Northern Europe
    ('ED', 'Germany FIR', 'E', 'DE', 'EUR', 0),
    ('EH', 'Netherlands FIR', 'E', 'NL', 'EUR', 0),
    ('EB', 'Belgium FIR', 'E', 'BE', 'EUR', 0),
    ('EL', 'Luxembourg FIR', 'E', 'LU', 'EUR', 0),
    ('EK', 'Denmark FIR', 'E', 'DK', 'EUR', 0),
    ('EN', 'Norway FIR', 'E', 'NO', 'EUR', 0),
    ('ES', 'Sweden FIR', 'E', 'SE', 'EUR', 0),
    ('EF', 'Finland FIR', 'E', 'FI', 'EUR', 0),
    ('EE', 'Estonia FIR', 'E', 'EE', 'EUR', 0),
    ('EV', 'Latvia FIR', 'E', 'LV', 'EUR', 0),
    ('EY', 'Lithuania FIR', 'E', 'LT', 'EUR', 0),
    ('EP', 'Poland FIR', 'E', 'PL', 'EUR', 0),
    -- Southern Europe
    ('LF', 'France FIR', 'L', 'FR', 'EUR', 0),
    ('LE', 'Spain FIR', 'L', 'ES', 'EUR', 0),
    ('LP', 'Portugal FIR', 'L', 'PT', 'EUR', 0),
    ('LI', 'Italy FIR', 'L', 'IT', 'EUR', 0),
    ('LS', 'Switzerland FIR', 'L', 'CH', 'EUR', 0),
    ('LO', 'Austria FIR', 'L', 'AT', 'EUR', 0),
    ('LK', 'Czech Republic FIR', 'L', 'CZ', 'EUR', 0),
    ('LH', 'Hungary FIR', 'L', 'HU', 'EUR', 0),
    ('LG', 'Greece FIR', 'L', 'GR', 'EUR', 0),
    ('LT', 'Turkey FIR', 'L', 'TR', 'EUR', 0),
    -- East Asia
    ('RJ', 'Japan FIR', 'R', 'JP', 'APAC', 0),
    ('RK', 'Korea (South) FIR', 'R', 'KR', 'APAC', 0),
    ('RP', 'Philippines FIR', 'R', 'PH', 'APAC', 0),
    ('RC', 'Taiwan FIR', 'R', 'TW', 'APAC', 0),
    -- China
    ('ZB', 'Beijing FIR', 'Z', 'CN', 'APAC', 0),
    ('ZS', 'Shanghai FIR', 'Z', 'CN', 'APAC', 0),
    ('ZG', 'Guangzhou FIR', 'Z', 'CN', 'APAC', 0),
    -- Southeast Asia
    ('VT', 'Thailand FIR', 'V', 'TH', 'APAC', 0),
    ('VV', 'Vietnam FIR', 'V', 'VN', 'APAC', 0),
    ('WM', 'Malaysia FIR', 'W', 'MY', 'APAC', 0),
    ('WS', 'Singapore FIR', 'W', 'SG', 'APAC', 0),
    ('WI', 'Indonesia FIR', 'W', 'ID', 'APAC', 0),
    -- Australia/Pacific
    ('YM', 'Melbourne FIR', 'Y', 'AU', 'APAC', 0),
    ('YB', 'Brisbane FIR', 'Y', 'AU', 'APAC', 0),
    ('NZ', 'New Zealand FIR', 'N', 'NZ', 'APAC', 0),
    -- Middle East
    ('OE', 'Saudi Arabia FIR', 'O', 'SA', 'MID', 0),
    ('OI', 'Iran FIR', 'O', 'IR', 'MID', 0),
    ('OJ', 'Jordan FIR', 'O', 'JO', 'MID', 0),
    ('OT', 'Qatar FIR', 'O', 'QA', 'MID', 0),
    ('OM', 'UAE FIR', 'O', 'AE', 'MID', 0),
    ('OB', 'Bahrain FIR', 'O', 'BH', 'MID', 0),
    ('OK', 'Kuwait FIR', 'O', 'KW', 'MID', 0),
    ('OP', 'Pakistan FIR', 'O', 'PK', 'MID', 0),
    ('OA', 'Afghanistan FIR', 'O', 'AF', 'MID', 0),
    -- India/South Asia
    ('VI', 'India (Delhi) FIR', 'V', 'IN', 'MID', 0),
    ('VE', 'India (Kolkata) FIR', 'V', 'IN', 'MID', 0),
    ('VO', 'India (Chennai) FIR', 'V', 'IN', 'MID', 0),
    ('VA', 'India (Mumbai) FIR', 'V', 'IN', 'MID', 0),
    -- Russia
    ('UU', 'Moscow FIR', 'U', 'RU', 'EUR', 0),
    ('UL', 'St Petersburg FIR', 'U', 'RU', 'EUR', 0),
    -- South America
    ('SB', 'Brazil FIR', 'S', 'BR', 'SAM', 0),
    ('SA', 'Argentina FIR', 'S', 'AR', 'SAM', 0),
    ('SC', 'Chile FIR', 'S', 'CL', 'SAM', 0),
    ('SP', 'Peru FIR', 'S', 'PE', 'SAM', 0),
    ('SK', 'Colombia FIR', 'S', 'CO', 'SAM', 0),
    -- Mexico/Central America
    ('MM', 'Mexico FIR', 'M', 'MX', 'NAM', 0),
    -- Caribbean
    ('TJ', 'Puerto Rico FIR', 'T', 'PR', 'NAM', 0),
    ('TT', 'Trinidad FIR', 'T', 'TT', 'NAM', 0),
    ('MK', 'Jamaica FIR', 'M', 'JM', 'NAM', 0),
    -- Africa
    ('DT', 'Tunisia FIR', 'D', 'TN', 'AFR', 0),
    ('DA', 'Algeria FIR', 'D', 'DZ', 'AFR', 0),
    ('GM', 'Morocco FIR', 'G', 'MA', 'AFR', 0),
    ('HE', 'Egypt FIR', 'H', 'EG', 'AFR', 0),
    ('FA', 'South Africa FIR', 'F', 'ZA', 'AFR', 0),
    ('DN', 'Nigeria FIR', 'D', 'NG', 'AFR', 0),
    ('HK', 'Kenya FIR', 'H', 'KE', 'AFR', 0);
    
    PRINT 'Inserted FIR reference data';
END
ELSE
BEGIN
    PRINT 'fir_reference table already exists';
END
GO

-- ============================================================================
-- Verification queries
-- ============================================================================

PRINT '--- Verification ---';

SELECT 'US airports with FIR' AS check_type, COUNT(*) AS count
FROM dbo.apts WHERE ICAO_ID LIKE 'K%' AND RESP_FIR_ID IS NOT NULL;

SELECT 'Canadian airports with FIR' AS check_type, COUNT(*) AS count
FROM dbo.apts WHERE ICAO_ID LIKE 'C%' AND RESP_FIR_ID IS NOT NULL;

SELECT 'International airports with FIR' AS check_type, COUNT(*) AS count
FROM dbo.apts WHERE ICAO_ID NOT LIKE 'K%' AND ICAO_ID NOT LIKE 'C%' AND RESP_FIR_ID IS NOT NULL;

SELECT 'Sample UK airports' AS check_type, ICAO_ID, RESP_FIR_ID
FROM dbo.apts WHERE ICAO_ID IN ('EGLL', 'EGKK', 'EGCC', 'EGGW');

SELECT 'Sample European airports' AS check_type, ICAO_ID, RESP_FIR_ID
FROM dbo.apts WHERE ICAO_ID IN ('LFPG', 'EDDF', 'EHAM', 'LIRF', 'LEMD');

SELECT 'FIR reference count' AS check_type, COUNT(*) AS count FROM dbo.fir_reference;
GO

PRINT 'FIR support migration completed successfully';
GO
