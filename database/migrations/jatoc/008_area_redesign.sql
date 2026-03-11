-- JATOC Area Redesign: Widen facility column for free-text incident names
-- Migration 008: facility column from NVARCHAR(8) -> NVARCHAR(128)
--
-- The "facility" field is being repurposed from a facility code to an incident name
-- (free-text like "Eastern US Staffing Shortage", "ZTL ATC Zero", etc.)
-- Geographic definition is now handled entirely by affected_facilities.

-- Widen facility in jatoc_incidents
ALTER TABLE dbo.jatoc_incidents ALTER COLUMN facility NVARCHAR(128) NULL;
GO

-- Widen facility in jatoc_reports (stores snapshot copy)
ALTER TABLE dbo.jatoc_reports ALTER COLUMN facility NVARCHAR(128) NULL;
GO
