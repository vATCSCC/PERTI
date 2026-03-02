-- JATOC Multi-Facility Support
-- Migration 007: Add affected_facilities column for multi-facility incidents
-- Stores comma-separated facility codes (e.g., "ZAB,ZAU,ZBW,ZDC,...")

IF NOT EXISTS (
    SELECT * FROM sys.columns
    WHERE object_id = OBJECT_ID('dbo.jatoc_incidents')
      AND name = 'affected_facilities'
)
BEGIN
    ALTER TABLE dbo.jatoc_incidents
    ADD affected_facilities NVARCHAR(MAX) NULL;
END
GO
