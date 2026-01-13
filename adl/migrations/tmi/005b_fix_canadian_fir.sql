-- ============================================================================
-- Fix: Canadian Airport FIR Assignment (longitude-based)
-- 
-- The original migration failed for Canadian airports because 'STATE' column
-- doesn't exist in the apts table. This fix uses longitude-based assignment.
--
-- Run this after 005_add_fir_support.sql
-- ============================================================================

UPDATE dbo.apts
SET RESP_FIR_ID = CASE
    -- British Columbia / Yukon -> Vancouver FIR
    WHEN LONG_DECIMAL < -120 AND LAT_DECIMAL < 60 THEN 'CZVR'
    -- Alberta / NWT / Nunavut -> Edmonton FIR  
    WHEN LONG_DECIMAL >= -120 AND LONG_DECIMAL < -110 THEN 'CZEG'
    WHEN LAT_DECIMAL >= 60 AND LONG_DECIMAL >= -120 AND LONG_DECIMAL < -85 THEN 'CZEG'
    -- Saskatchewan / Manitoba -> Winnipeg FIR
    WHEN LONG_DECIMAL >= -110 AND LONG_DECIMAL < -90 AND LAT_DECIMAL < 60 THEN 'CZWG'
    -- Ontario -> Toronto FIR
    WHEN LONG_DECIMAL >= -90 AND LONG_DECIMAL < -75 THEN 'CZYZ'
    -- Quebec -> Montreal FIR
    WHEN LONG_DECIMAL >= -75 AND LONG_DECIMAL < -60 THEN 'CZUL'
    -- Atlantic provinces -> Moncton FIR
    WHEN LONG_DECIMAL >= -60 THEN 'CZQM'
    -- Fallback
    ELSE 'CZYZ'
END
WHERE ICAO_ID LIKE 'C%'
  AND LEN(ICAO_ID) = 4
  AND (RESP_FIR_ID IS NULL OR RESP_FIR_ID = '');

PRINT 'Updated Canadian airports with FIR assignments (longitude-based)';
GO

-- Verify Canadian FIR distribution
SELECT RESP_FIR_ID, COUNT(*) as airport_count
FROM dbo.apts 
WHERE ICAO_ID LIKE 'C%' AND RESP_FIR_ID IS NOT NULL
GROUP BY RESP_FIR_ID
ORDER BY RESP_FIR_ID;

-- Sample major Canadian airports
SELECT ICAO_ID, RESP_FIR_ID, LAT_DECIMAL, LONG_DECIMAL
FROM dbo.apts
WHERE ICAO_ID IN ('CYYZ', 'CYVR', 'CYUL', 'CYYC', 'CYEG', 'CYOW', 'CYWG', 'CYHZ', 'CYQB');
GO
