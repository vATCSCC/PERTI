-- ============================================================================
-- 058_ctp_nat_track.sql
-- VATSIM_TMI Database: Add assigned_nat_track to ctp_flight_control
--
-- Migration 045 created ctp_flight_control with route segments but no
-- NAT track column. CTP assigns specific NAT tracks (A, B, SM1, etc.)
-- that need to be stored separately from the oceanic route segment.
-- ============================================================================

USE VATSIM_TMI;
GO

PRINT '==========================================================================';
PRINT '  Migration 058: Add assigned_nat_track to ctp_flight_control';
PRINT '  ' + CONVERT(VARCHAR, GETUTCDATE(), 120);
PRINT '==========================================================================';
GO

IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.ctp_flight_control') AND name = 'assigned_nat_track')
BEGIN
    ALTER TABLE dbo.ctp_flight_control ADD assigned_nat_track VARCHAR(4) NULL;
    PRINT '+ Added assigned_nat_track (pattern: A, B, SM1, etc.)';
END
ELSE PRINT '= assigned_nat_track already exists';
GO

-- ============================================================================
-- Verify CTP is in tmi_flight_control CHECK constraint
-- Migration 003 CHECK does not include CTP, but ingest/ctp.php uses it.
-- This adds CTP if the constraint exists without it.
-- ============================================================================

IF EXISTS (
    SELECT 1 FROM sys.check_constraints
    WHERE parent_object_id = OBJECT_ID('dbo.tmi_flight_control')
      AND name = 'CK_tmi_flight_control_type'
      AND definition NOT LIKE '%CTP%'
)
BEGIN
    ALTER TABLE dbo.tmi_flight_control DROP CONSTRAINT CK_tmi_flight_control_type;
    ALTER TABLE dbo.tmi_flight_control ADD CONSTRAINT CK_tmi_flight_control_type
        CHECK (ctl_type IS NULL OR ctl_type IN
            ('GDP', 'AFP', 'GS', 'DAS', 'GAAP', 'UDP', 'COMP', 'BLKT', 'ECR', 'ADPT', 'ABRG', 'CTOP', 'CTP'));
    PRINT '+ Updated CK_tmi_flight_control_type to include CTP';
END
ELSE PRINT '= CK_tmi_flight_control_type already includes CTP (or does not exist)';
GO

PRINT '';
PRINT '  Migration 058 Complete';
GO
