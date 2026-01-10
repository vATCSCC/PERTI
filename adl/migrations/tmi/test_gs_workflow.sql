-- ============================================================================
-- GS Quick Test Script
-- Run each section one at a time to verify the workflow
-- ============================================================================

-- ============================================================================
-- STEP 1: Check current traffic to pick a good test airport
-- ============================================================================

SELECT TOP 10 
    fp.fp_dest_icao AS airport,
    COUNT(*) AS inbound_flights,
    SUM(CASE WHEN c.phase IN ('departed', 'enroute', 'descending') THEN 1 ELSE 0 END) AS airborne,
    SUM(CASE WHEN c.phase IN ('prefile', 'taxiing') OR c.phase IS NULL THEN 1 ELSE 0 END) AS on_ground,
    MIN(ft.eta_runway_utc) AS earliest_eta,
    MAX(ft.eta_runway_utc) AS latest_eta
FROM dbo.adl_flight_core c
INNER JOIN dbo.adl_flight_plan fp ON fp.flight_uid = c.flight_uid
INNER JOIN dbo.adl_flight_times ft ON ft.flight_uid = c.flight_uid
WHERE c.is_active = 1
  AND fp.fp_dest_icao IS NOT NULL
  AND ft.eta_runway_utc >= SYSUTCDATETIME()
  AND ft.eta_runway_utc <= DATEADD(HOUR, 3, SYSUTCDATETIME())
GROUP BY fp.fp_dest_icao
ORDER BY COUNT(*) DESC;

-- ============================================================================
-- STEP 2: Create a test Ground Stop
-- Replace 'KJFK' with an airport from Step 1 that has traffic
-- ============================================================================

DECLARE @program_id INT;
DECLARE @start_utc DATETIME2(0) = SYSUTCDATETIME();
DECLARE @end_utc DATETIME2(0) = DATEADD(HOUR, 2, SYSUTCDATETIME());

EXEC dbo.sp_GS_Create
    @ctl_element = 'KJFK',              -- Change to airport with traffic
    @start_utc = @start_utc,
    @end_utc = @end_utc,
    @scope_type = 'TIER',
    @scope_tier = 1,
    @exempt_airborne = 1,
    @exempt_within_min = 45,
    @impacting_condition = 'WEATHER',
    @cause_text = 'Test ground stop - low visibility',
    @created_by = 'TEST',
    @program_id = @program_id OUTPUT;

SELECT @program_id AS created_program_id;

-- View the created program
SELECT * FROM dbo.ntml WHERE program_id = @program_id;

-- ============================================================================
-- STEP 3: Model the Ground Stop
-- Use the program_id from Step 2
-- Pass the tier-expanded ARTCC list (from TierInfo.csv for KJFK 1st tier)
-- ============================================================================

-- KJFK 1st Tier = ZNY ZDC ZBW ZOB
-- (Adjust dep_facilities based on your target airport's tier)

EXEC dbo.sp_GS_Model
    @program_id = 1,                    -- Replace with your program_id from Step 2
    @dep_facilities = 'ZNY ZDC ZBW ZOB',
    @performed_by = 'TEST';

-- ============================================================================
-- STEP 4: Check results
-- ============================================================================

-- View updated program metrics
SELECT 
    program_id,
    program_name,
    ctl_element,
    status,
    start_utc,
    end_utc,
    total_flights,
    controlled_flights,
    exempt_flights,
    airborne_flights
FROM dbo.ntml 
WHERE program_id = 1;  -- Replace with your program_id

-- View affected flights
EXEC dbo.sp_GS_GetFlights @program_id = 1;  -- Replace with your program_id

-- View event log
SELECT * FROM dbo.ntml_info WHERE program_id = 1 ORDER BY performed_utc;

-- ============================================================================
-- STEP 5: Test activation (optional)
-- ============================================================================

EXEC dbo.sp_GS_IssueEDCTs
    @program_id = 1,                    -- Replace with your program_id
    @activated_by = 'TEST';

-- Check status changed to ACTIVE
SELECT program_id, program_name, status, activated_utc 
FROM dbo.ntml WHERE program_id = 1;

-- ============================================================================
-- STEP 6: Clean up (optional - purge the test)
-- ============================================================================

EXEC dbo.sp_GS_Purge
    @program_id = 1,                    -- Replace with your program_id
    @purged_by = 'TEST',
    @purge_reason = 'Test cleanup';

-- Verify purged
SELECT program_id, program_name, status, purged_utc 
FROM dbo.ntml WHERE program_id = 1;

-- ============================================================================
-- BONUS: View using the new GDT views
-- ============================================================================

-- Active programs
SELECT * FROM dbo.vw_NTML_Active;

-- Today's programs
SELECT * FROM dbo.vw_NTML_Today;

-- Demand by hour for an airport
SELECT * FROM dbo.vw_GDT_DemandByHour 
WHERE airport = 'KJFK' 
ORDER BY hour_utc;
