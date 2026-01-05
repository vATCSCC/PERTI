-- ============================================================
-- NAS Operations Dashboard (NOD) - Advisory Database
-- Migration: 006_dcc_advisories.sql
-- Database: VATSIM_ADL (Azure SQL)
-- ============================================================

-- Advisory table for storing DCC advisories
-- Mirrors FAA ATCSCC Advisory Database structure
-- Advisory types parsed from header: "vATCSCC ADVZY ### FAC MM/DD/YYYY <TYPE>"
IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'dcc_advisories')
BEGIN
    CREATE TABLE dbo.dcc_advisories (
        id                  INT IDENTITY(1,1) PRIMARY KEY,
        
        -- Advisory identification
        adv_number          NVARCHAR(16) NOT NULL,           -- e.g., "DCC 001"
        adv_type            NVARCHAR(32) NOT NULL,           -- OPERATIONS_PLAN, REROUTE, GDP, GS, AFP, FACILITY_OUTAGE, GENERAL
        adv_category        NVARCHAR(32) NULL,               -- Sub-category (e.g., PLAYBOOK, SEVERE_WX, CONSTRUCTION)
        
        -- Content
        subject             NVARCHAR(256) NOT NULL,          -- Brief subject line
        body_text           NVARCHAR(MAX) NOT NULL,          -- Full advisory text (can be multi-line)
        
        -- Validity period
        valid_start_utc     DATETIME2 NOT NULL,
        valid_end_utc       DATETIME2 NULL,                  -- NULL = indefinite
        
        -- Scope/Applicability
        impacted_facilities NVARCHAR(MAX) NULL,              -- JSON array: ["ZDC", "ZNY", "ZBW"]
        impacted_airports   NVARCHAR(MAX) NULL,              -- JSON array: ["KJFK", "KEWR", "KLGA"]
        impacted_area       NVARCHAR(128) NULL,              -- e.g., "NY METRO", "NE CORRIDOR"
        
        -- Source information
        source              NVARCHAR(64) NOT NULL DEFAULT 'MANUAL', -- MANUAL, DISCORD, SYSTEM
        source_ref          NVARCHAR(256) NULL,              -- Discord message ID, etc.
        
        -- Status
        status              NVARCHAR(16) NOT NULL DEFAULT 'ACTIVE', -- DRAFT, ACTIVE, CANCELLED, EXPIRED
        priority            TINYINT NOT NULL DEFAULT 2,      -- 1=HIGH, 2=NORMAL, 3=LOW
        
        -- Audit
        created_by          NVARCHAR(64) NOT NULL,
        created_at          DATETIME2 NOT NULL DEFAULT GETUTCDATE(),
        updated_by          NVARCHAR(64) NULL,
        updated_at          DATETIME2 NULL,
        cancelled_by        NVARCHAR(64) NULL,
        cancelled_at        DATETIME2 NULL,
        cancel_reason       NVARCHAR(256) NULL,
        
        -- Indexes for common queries
        INDEX IX_dcc_advisories_status (status),
        INDEX IX_dcc_advisories_type (adv_type),
        INDEX IX_dcc_advisories_valid (valid_start_utc, valid_end_utc),
        INDEX IX_dcc_advisories_created (created_at DESC)
    );
    
    PRINT 'Created table: dbo.dcc_advisories';
END
GO

-- Sequence for advisory numbers (per day, resets)
IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'dcc_advisory_sequences')
BEGIN
    CREATE TABLE dbo.dcc_advisory_sequences (
        seq_date        DATE PRIMARY KEY,
        seq_number      INT NOT NULL DEFAULT 0
    );
    
    PRINT 'Created table: dbo.dcc_advisory_sequences';
END
GO

-- Stored procedure to generate next advisory number
IF EXISTS (SELECT * FROM sys.objects WHERE type = 'P' AND name = 'sp_dcc_next_advisory_number')
    DROP PROCEDURE dbo.sp_dcc_next_advisory_number;
GO

CREATE PROCEDURE dbo.sp_dcc_next_advisory_number
    @prefix NVARCHAR(8) = 'DCC'
AS
BEGIN
    SET NOCOUNT ON;
    
    DECLARE @today DATE = CAST(GETUTCDATE() AS DATE);
    DECLARE @seq INT;
    
    -- Upsert today's sequence
    MERGE dbo.dcc_advisory_sequences AS target
    USING (SELECT @today AS seq_date) AS source
    ON target.seq_date = source.seq_date
    WHEN MATCHED THEN
        UPDATE SET seq_number = seq_number + 1
    WHEN NOT MATCHED THEN
        INSERT (seq_date, seq_number) VALUES (@today, 1);
    
    -- Get the new number
    SELECT @seq = seq_number FROM dbo.dcc_advisory_sequences WHERE seq_date = @today;
    
    -- Return formatted advisory number: "DCC 001"
    SELECT @prefix + ' ' + RIGHT('000' + CAST(@seq AS VARCHAR(3)), 3) AS adv_number;
END
GO

PRINT 'Created procedure: dbo.sp_dcc_next_advisory_number';
GO

-- Discord TMI integration table (placeholder for future webhook data)
IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'dcc_discord_tmi')
BEGIN
    CREATE TABLE dbo.dcc_discord_tmi (
        id                  INT IDENTITY(1,1) PRIMARY KEY,
        
        -- Discord message info
        discord_message_id  NVARCHAR(64) NOT NULL UNIQUE,
        discord_channel_id  NVARCHAR(64) NOT NULL,
        discord_guild_id    NVARCHAR(64) NULL,
        
        -- Parsed TMI data
        tmi_type            NVARCHAR(16) NOT NULL,           -- GS, GDP, AFP, REROUTE, STOP
        airport             NVARCHAR(4) NULL,
        reason              NVARCHAR(256) NULL,
        start_time_utc      DATETIME2 NULL,
        end_time_utc        DATETIME2 NULL,
        
        -- Raw content
        raw_content         NVARCHAR(MAX) NOT NULL,
        
        -- Status
        status              NVARCHAR(16) NOT NULL DEFAULT 'ACTIVE', -- ACTIVE, ENDED, CANCELLED
        
        -- Audit
        received_at         DATETIME2 NOT NULL DEFAULT GETUTCDATE(),
        parsed_at           DATETIME2 NULL,
        ended_at            DATETIME2 NULL,
        
        INDEX IX_discord_tmi_status (status),
        INDEX IX_discord_tmi_type (tmi_type),
        INDEX IX_discord_tmi_airport (airport)
    );
    
    PRINT 'Created table: dbo.dcc_discord_tmi';
END
GO

-- View for today's active advisories
IF EXISTS (SELECT * FROM sys.views WHERE name = 'vw_dcc_advisories_today')
    DROP VIEW dbo.vw_dcc_advisories_today;
GO

CREATE VIEW dbo.vw_dcc_advisories_today AS
SELECT 
    id,
    adv_number,
    adv_type,
    adv_category,
    subject,
    body_text,
    valid_start_utc,
    valid_end_utc,
    impacted_facilities,
    impacted_airports,
    impacted_area,
    source,
    status,
    priority,
    created_by,
    created_at
FROM dbo.dcc_advisories
WHERE status = 'ACTIVE'
  AND valid_start_utc <= GETUTCDATE()
  AND (valid_end_utc IS NULL OR valid_end_utc > GETUTCDATE())
  AND CAST(created_at AS DATE) = CAST(GETUTCDATE() AS DATE);
GO

PRINT 'Created view: dbo.vw_dcc_advisories_today';
GO

-- Sample advisory types for reference (parsed from header title)
/*
Advisory Types (adv_type) - Parsed from advisory header:
Header format: "vATCSCC ADVZY ### FAC MM/DD/YYYY <TYPE>"

ROUTE Types:
- ROUTE RQD       : Route Required
- ROUTE RMD       : Route Reminder
- ROUTE PLN       : Route Plan
- ROUTE FYI       : Route For Your Information

Ground Stop/GDP Types:
- CDM GROUND STOP           : Ground Stop
- CDM GS CNX                : Ground Stop Cancel
- CDM PROPOSED GROUND STOP  : Proposed Ground Stop
- CDM GROUND DELAY PROGRAM  : GDP
- CDM GDP CNX               : GDP Cancel

FCA/AFP Types:
- FCA RQD         : FCA Required
- AFP RQD         : AFP Required
- AFP CNX         : AFP Cancel

Other Types:
- OPERATIONS PLAN : Daily operations plan
- INFORMATIONAL   : Information advisory
- HOTLINE         : Hotline notification
- DIVERSION RECOVERY : Diversion recovery
- SWAP            : SWAP implementation
- PLAYBOOK        : Playbook route

TMI_Type field values (for filtering):
- GS              : Ground Stop
- GDP             : Ground Delay Program
- Reroute         : Route advisory
- FCA             : Flow Constrained Area
- HOTLINE         : Hotline notification
- OTHER           : Other/General
- MIT             : Miles in Trail
- STOP            : Departure Stop
- MINIT           : Minutes in Trail
- APREQ           : Approval Required
- TBFM            : Time-Based Flow Management
- APT_CONFIG      : Airport Configuration
*/

PRINT '====================================================';
PRINT 'NOD Advisory Database migration complete';
PRINT '====================================================';
GO
