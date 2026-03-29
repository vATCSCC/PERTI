-- ============================================================================
-- VATSIM_TMI Migration 052: ECR Audit Columns
-- ============================================================================
-- Version: 1.0.0
-- Date: 2026-03-29
-- Author: HP/Claude
--
-- Adds audit tracking columns to tmi_flight_control for ECR actions.
-- The ecr_pending and ecr columns already exist from earlier migrations;
-- this adds explicit tracking of last ECR action and timestamp.
-- ============================================================================

USE VATSIM_TMI;
GO

-- Add ECR tracking columns to tmi_flight_control (if not already present)
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.tmi_flight_control') AND name = 'ecr_action')
BEGIN
    ALTER TABLE dbo.tmi_flight_control ADD ecr_action NVARCHAR(16) NULL;
    PRINT 'Added ecr_action column to tmi_flight_control';
END
GO

IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.tmi_flight_control') AND name = 'ecr_performed_by')
BEGIN
    ALTER TABLE dbo.tmi_flight_control ADD ecr_performed_by NVARCHAR(64) NULL;
    PRINT 'Added ecr_performed_by column to tmi_flight_control';
END
GO

IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.tmi_flight_control') AND name = 'ecr_performed_utc')
BEGIN
    ALTER TABLE dbo.tmi_flight_control ADD ecr_performed_utc DATETIME2(0) NULL;
    PRINT 'Added ecr_performed_utc column to tmi_flight_control';
END
GO

IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.tmi_flight_control') AND name = 'ecr_count')
BEGIN
    ALTER TABLE dbo.tmi_flight_control ADD ecr_count INT NOT NULL DEFAULT 0;
    PRINT 'Added ecr_count column to tmi_flight_control';
END
GO

-- Add compliance_status column if not present (used by GDP compliance monitoring)
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.tmi_flight_control') AND name = 'compliance_status')
BEGIN
    ALTER TABLE dbo.tmi_flight_control ADD compliance_status NVARCHAR(16) NULL;
    PRINT 'Added compliance_status column to tmi_flight_control';
END
GO

PRINT 'Migration 052: ECR audit columns added to tmi_flight_control';
GO
