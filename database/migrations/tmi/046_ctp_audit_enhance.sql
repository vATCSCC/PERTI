-- ============================================================================
-- VATSIM_TMI Migration 046: CTP Audit Log Enhancements
--
-- Purpose: Add display name column and extend action types for comprehensive
--          changelogging during CTP E26.
-- ============================================================================

USE VATSIM_TMI;
GO

SET ANSI_NULLS ON;
SET QUOTED_IDENTIFIER ON;
GO

PRINT '=== VATSIM_TMI Migration 046: CTP Audit Enhancements ===';
PRINT 'Started at: ' + CONVERT(VARCHAR, GETUTCDATE(), 120);
GO

-- ----------------------------------------------------------------------------
-- 1. Add performed_by_name to ctp_audit_log
-- ----------------------------------------------------------------------------
IF EXISTS (SELECT * FROM sys.objects WHERE object_id = OBJECT_ID(N'dbo.ctp_audit_log') AND type = 'U')
   AND NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID(N'dbo.ctp_audit_log') AND name = 'performed_by_name')
BEGIN
    ALTER TABLE dbo.ctp_audit_log
        ADD performed_by_name NVARCHAR(64) NULL;
    PRINT 'Added column: ctp_audit_log.performed_by_name';
END
ELSE
    PRINT 'Column ctp_audit_log.performed_by_name already exists or table missing - skipping';
GO

-- ----------------------------------------------------------------------------
-- 2. Add ip_address to ctp_audit_log
-- ----------------------------------------------------------------------------
IF EXISTS (SELECT * FROM sys.objects WHERE object_id = OBJECT_ID(N'dbo.ctp_audit_log') AND type = 'U')
   AND NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID(N'dbo.ctp_audit_log') AND name = 'ip_address')
BEGIN
    ALTER TABLE dbo.ctp_audit_log
        ADD ip_address NVARCHAR(45) NULL;
    PRINT 'Added column: ctp_audit_log.ip_address';
END
ELSE
    PRINT 'Column ctp_audit_log.ip_address already exists or table missing - skipping';
GO

-- ----------------------------------------------------------------------------
-- 3. Add performed_by_name to ctp_route_templates for audit
-- ----------------------------------------------------------------------------
IF EXISTS (SELECT * FROM sys.objects WHERE object_id = OBJECT_ID(N'dbo.ctp_route_templates') AND type = 'U')
   AND NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID(N'dbo.ctp_route_templates') AND name = 'updated_by')
BEGIN
    ALTER TABLE dbo.ctp_route_templates
        ADD updated_by NVARCHAR(16) NULL;
    PRINT 'Added column: ctp_route_templates.updated_by';
END
ELSE
    PRINT 'Column ctp_route_templates.updated_by already exists or table missing - skipping';
GO

-- ============================================================================
PRINT '';
PRINT '====================================================================';
PRINT 'Migration 046: CTP Audit Enhancements completed';
PRINT 'Finished at: ' + CONVERT(VARCHAR, GETUTCDATE(), 120);
PRINT '====================================================================';
GO
