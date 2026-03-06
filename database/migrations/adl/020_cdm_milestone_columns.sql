-- ============================================================================
-- VATSIM_ADL Migration 020: A-CDM Milestone Columns on adl_flight_times
-- Adds TOBT/TSAT/TTOT milestone tracking and gate-hold status to the
-- authoritative flight times table.
-- ============================================================================
-- Version: 1.0.0
-- Date: 2026-03-05
-- Author: HP/Claude
--
-- Context:
--   swim_flights already has TOBT/TSAT/TTOT/TLDT columns (migration 014)
--   but those are denormalized copies for API consumers. The authoritative
--   source for milestone times is adl_flight_times, which feeds SWIM sync.
--
-- Columns added:
--   - tobt_utc           Target Off-Block Time (pilot readiness signal)
--   - tsat_utc           Target Start-Up Approval Time (gate release)
--   - ttot_utc           Target Take-Off Time (expected wheels-up)
--   - tobt_source        How TOBT was determined
--   - tsat_source        How TSAT was determined
--   - gate_hold_active   Currently held at gate?
--   - gate_hold_issued_utc  When gate hold was issued
--   - gate_hold_released_utc When gate hold was released
--   - cdm_readiness_state Current CDM readiness state
-- ============================================================================

USE VATSIM_ADL;
GO

PRINT '==========================================================================';
PRINT '  Migration 020: A-CDM Milestone Columns on adl_flight_times';
PRINT '  ' + CONVERT(VARCHAR, GETUTCDATE(), 120);
PRINT '==========================================================================';
GO

-- TOBT — Target Off-Block Time
-- When pilot intends to push back. Set by pilot readiness signal or computed
-- from first_seen + connect_baseline.
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.adl_flight_times') AND name = 'tobt_utc')
BEGIN
    ALTER TABLE dbo.adl_flight_times ADD tobt_utc DATETIME2(0) NULL;
    PRINT '+ Added tobt_utc';
END
ELSE PRINT '= tobt_utc already exists';
GO

-- TSAT — Target Start-Up Approval Time
-- When pilot should expect pushback approval. Computed:
-- TSAT = max(TOBT, EDCT - taxi_time(airport))
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.adl_flight_times') AND name = 'tsat_utc')
BEGIN
    ALTER TABLE dbo.adl_flight_times ADD tsat_utc DATETIME2(0) NULL;
    PRINT '+ Added tsat_utc';
END
ELSE PRINT '= tsat_utc already exists';
GO

-- TTOT — Target Take-Off Time
-- Expected wheels-up. Computed: TTOT = TSAT + taxi_time(airport)
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.adl_flight_times') AND name = 'ttot_utc')
BEGIN
    ALTER TABLE dbo.adl_flight_times ADD ttot_utc DATETIME2(0) NULL;
    PRINT '+ Added ttot_utc';
END
ELSE PRINT '= ttot_utc already exists';
GO

-- TOBT source — how TOBT was determined
-- pilot = pilot signaled readiness, simbrief = SimBrief ETD,
-- auto = first_seen + connect_baseline, controller = manual override
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.adl_flight_times') AND name = 'tobt_source')
BEGIN
    ALTER TABLE dbo.adl_flight_times ADD tobt_source NVARCHAR(20) NULL;
    PRINT '+ Added tobt_source';
END
ELSE PRINT '= tobt_source already exists';
GO

-- TSAT source — how TSAT was determined
-- calculated = TSAT engine, manual = controller override
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.adl_flight_times') AND name = 'tsat_source')
BEGIN
    ALTER TABLE dbo.adl_flight_times ADD tsat_source NVARCHAR(20) NULL;
    PRINT '+ Added tsat_source';
END
ELSE PRINT '= tsat_source already exists';
GO

-- Gate hold status
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.adl_flight_times') AND name = 'gate_hold_active')
BEGIN
    ALTER TABLE dbo.adl_flight_times ADD gate_hold_active BIT DEFAULT 0;
    PRINT '+ Added gate_hold_active';
END
ELSE PRINT '= gate_hold_active already exists';
GO

IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.adl_flight_times') AND name = 'gate_hold_issued_utc')
BEGIN
    ALTER TABLE dbo.adl_flight_times ADD gate_hold_issued_utc DATETIME2(0) NULL;
    PRINT '+ Added gate_hold_issued_utc';
END
ELSE PRINT '= gate_hold_issued_utc already exists';
GO

IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.adl_flight_times') AND name = 'gate_hold_released_utc')
BEGIN
    ALTER TABLE dbo.adl_flight_times ADD gate_hold_released_utc DATETIME2(0) NULL;
    PRINT '+ Added gate_hold_released_utc';
END
ELSE PRINT '= gate_hold_released_utc already exists';
GO

-- CDM readiness state cached on flight_times for fast joins
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.adl_flight_times') AND name = 'cdm_readiness_state')
BEGIN
    ALTER TABLE dbo.adl_flight_times ADD cdm_readiness_state NVARCHAR(20) NULL;
    PRINT '+ Added cdm_readiness_state';
END
ELSE PRINT '= cdm_readiness_state already exists';
GO

-- ============================================================================
-- INDEXES
-- ============================================================================

-- Index: Flights with gate holds active
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id = OBJECT_ID('dbo.adl_flight_times') AND name = 'IX_adl_flight_times_gate_hold')
BEGIN
    CREATE INDEX IX_adl_flight_times_gate_hold
        ON dbo.adl_flight_times (gate_hold_active, tsat_utc)
        WHERE gate_hold_active = 1;
    PRINT '+ Created index IX_adl_flight_times_gate_hold';
END
ELSE PRINT '= IX_adl_flight_times_gate_hold already exists';
GO

-- Index: TOBT for CDM airport status queries
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id = OBJECT_ID('dbo.adl_flight_times') AND name = 'IX_adl_flight_times_tobt')
BEGIN
    CREATE INDEX IX_adl_flight_times_tobt
        ON dbo.adl_flight_times (tobt_utc)
        WHERE tobt_utc IS NOT NULL;
    PRINT '+ Created index IX_adl_flight_times_tobt';
END
ELSE PRINT '= IX_adl_flight_times_tobt already exists';
GO

PRINT '';
PRINT '==========================================================================';
PRINT '  Migration 020 Complete: A-CDM Milestone Columns';
PRINT '';
PRINT '  Columns: tobt_utc, tsat_utc, ttot_utc, tobt_source, tsat_source,';
PRINT '           gate_hold_active, gate_hold_issued_utc, gate_hold_released_utc,';
PRINT '           cdm_readiness_state';
PRINT '  Indexes: IX_adl_flight_times_gate_hold, IX_adl_flight_times_tobt';
PRINT '==========================================================================';
GO
