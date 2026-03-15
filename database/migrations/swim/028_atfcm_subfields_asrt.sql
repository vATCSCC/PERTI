-- ============================================================================
-- Migration 028: ATFCM Sub-fields and ASRT
--
-- Adds ATFCM regulatory sub-fields (excluded/ready/slot_improvement) and
-- ASRT (Actual Startup Request Time) milestone to swim_flights.
--
-- Supports CDM Plugin v2.2.8.25+ which exposes individual atfcmData flags
-- and ASRT via the /ifps/depAirport endpoint.
--
-- Run with DDL admin (jpeterson):
--   sqlcmd -S vatsim.database.windows.net -U jpeterson -P Jhp21012 -d SWIM_API -i 028_atfcm_subfields_asrt.sql
-- ============================================================================

USE SWIM_API;
GO

-- ASRT — Actual Startup Request Time (FIXM 4.3 EUR: actualStartUpRequestTime)
-- When the pilot requests startup clearance. Completes the A-CDM chain:
-- TOBT → ASRT → TSAT → ASAT → TTOT/CTOT → AOBT → ATOT
IF NOT EXISTS (
    SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_NAME = 'swim_flights' AND COLUMN_NAME = 'actual_startup_request_time'
)
ALTER TABLE dbo.swim_flights ADD actual_startup_request_time DATETIME2(0) NULL;
GO

-- EU ATFCM excluded flag (NM B2B: exclusionFromRegulations)
-- Flight excluded from ATFCM regulation
IF NOT EXISTS (
    SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_NAME = 'swim_flights' AND COLUMN_NAME = 'eu_atfcm_excluded'
)
ALTER TABLE dbo.swim_flights ADD eu_atfcm_excluded BIT NOT NULL DEFAULT 0;
GO

-- EU ATFCM ready flag (NM B2B: readyStatus)
-- Flight is departure-ready and eligible for slot improvement
IF NOT EXISTS (
    SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_NAME = 'swim_flights' AND COLUMN_NAME = 'eu_atfcm_ready'
)
ALTER TABLE dbo.swim_flights ADD eu_atfcm_ready BIT NOT NULL DEFAULT 0;
GO

-- EU ATFCM slot improvement flag (NM B2B: slotImprovementProposal)
-- Active Slot Improvement Request
IF NOT EXISTS (
    SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_NAME = 'swim_flights' AND COLUMN_NAME = 'eu_atfcm_slot_improvement'
)
ALTER TABLE dbo.swim_flights ADD eu_atfcm_slot_improvement BIT NOT NULL DEFAULT 0;
GO

-- Filtered index on ATFCM regulatory flags
-- Supports CDM dashboard queries filtering on regulatory state
IF NOT EXISTS (
    SELECT 1 FROM sys.indexes
    WHERE name = 'IX_swim_flights_atfcm_flags' AND object_id = OBJECT_ID('dbo.swim_flights')
)
CREATE NONCLUSTERED INDEX IX_swim_flights_atfcm_flags
ON dbo.swim_flights (eu_atfcm_excluded, eu_atfcm_ready, eu_atfcm_slot_improvement)
WHERE eu_atfcm_excluded = 1 OR eu_atfcm_ready = 1 OR eu_atfcm_slot_improvement = 1;
GO

-- Backfill: sync eu_atfcm_excluded with existing eu_atfcm_status = 'EXCLUDED'
UPDATE dbo.swim_flights
SET eu_atfcm_excluded = 1
WHERE eu_atfcm_status = 'EXCLUDED'
  AND eu_atfcm_excluded = 0;
GO

PRINT 'Migration 028: ATFCM Sub-fields and ASRT complete';
GO
