-- ============================================================================
-- 061_ctp_exempt_reason.sql
--
-- Add 'CTP' to tmi_flight_control.ctl_exempt_reason CHECK constraint
--
-- Database: VATSIM_TMI
-- Run as: jpeterson (DDL admin)
--
-- CTP event flights are exempt from all TMIs (GDP, GS, AFP, reroutes)
-- because CTP has its own slot engine. This adds 'CTP' as a valid
-- exemption reason so simulate.php and apply.php can tag them.
--
-- Run after: 060_ctp_slot_engine.sql
-- ============================================================================

SET ANSI_NULLS ON;
SET QUOTED_IDENTIFIER ON;
GO

PRINT '=== Migration 061: Add CTP Exempt Reason ===';
PRINT 'Started at: ' + CONVERT(VARCHAR, GETUTCDATE(), 120);
GO

-- Drop existing constraint
IF EXISTS (SELECT 1 FROM sys.check_constraints WHERE name = 'CK_tmi_flight_control_exempt')
    ALTER TABLE dbo.tmi_flight_control DROP CONSTRAINT CK_tmi_flight_control_exempt;
GO

-- Recreate with 'CTP' added
ALTER TABLE dbo.tmi_flight_control
ADD CONSTRAINT CK_tmi_flight_control_exempt CHECK (ctl_exempt_reason IS NULL OR ctl_exempt_reason IN
    ('AIRBORNE', 'DISTANCE', 'CENTER', 'CARRIER', 'TYPE', 'EARLY', 'LATE', 'MANUAL', 'OTHER',
     'DEPARTING_SOON', 'EXEMPT_ORIGIN', 'EXEMPT_FLIGHT', 'CTP'));
GO

PRINT 'Updated CK_tmi_flight_control_exempt: added CTP';
GO

-- Verify
PRINT '';
PRINT 'Verifying constraint:';
SELECT name, definition
FROM sys.check_constraints
WHERE name = 'CK_tmi_flight_control_exempt';
GO

PRINT '';
PRINT '=== Migration 061 Complete ===';
PRINT 'Finished at: ' + CONVERT(VARCHAR, GETUTCDATE(), 120);
GO
