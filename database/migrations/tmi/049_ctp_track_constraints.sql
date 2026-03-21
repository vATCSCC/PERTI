-- Migration 049: CTP Planning Track Constraints
-- Adds per-track constraints (max ACPH, ocean entry windows, FL range)
-- that planners can set to override computed defaults.
--
-- Run against: VATSIM_TMI
-- Date: 2026-03-21

IF NOT EXISTS (SELECT 1 FROM sys.tables WHERE name = 'ctp_planning_track_constraints')
BEGIN
    CREATE TABLE dbo.ctp_planning_track_constraints (
        constraint_id INT IDENTITY(1,1) PRIMARY KEY,
        session_id INT NOT NULL REFERENCES dbo.ctp_sessions(session_id),
        track_name VARCHAR(20) NOT NULL,
        max_acph INT NULL,
        ocean_entry_start DATETIME2 NULL,
        ocean_entry_end DATETIME2 NULL,
        fl_min INT NULL,
        fl_max INT NULL,
        priority INT NOT NULL DEFAULT 50,
        notes NVARCHAR(500) NULL,
        created_at DATETIME2 NOT NULL DEFAULT SYSUTCDATETIME(),
        updated_at DATETIME2 NOT NULL DEFAULT SYSUTCDATETIME(),
        UNIQUE(session_id, track_name)
    );
END
GO
