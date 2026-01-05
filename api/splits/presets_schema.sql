-- ============================================================================
-- Splits Presets Schema
-- 
-- Creates tables for storing reusable split configuration presets.
-- Run this script on the Azure SQL database (VATSIM_ADL).
-- ============================================================================

-- Presets master table
IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'splits_presets')
BEGIN
    CREATE TABLE dbo.splits_presets (
        id INT IDENTITY(1,1) PRIMARY KEY,
        preset_name NVARCHAR(100) NOT NULL,
        artcc CHAR(3) NOT NULL,
        description NVARCHAR(500) NULL,
        created_at DATETIME2 NOT NULL DEFAULT GETUTCDATE(),
        updated_at DATETIME2 NOT NULL DEFAULT GETUTCDATE()
    );
    
    CREATE INDEX IX_splits_presets_artcc ON dbo.splits_presets(artcc);
    
    PRINT 'Created table: dbo.splits_presets';
END
ELSE
BEGIN
    PRINT 'Table dbo.splits_presets already exists';
END
GO

-- Preset positions table
IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'splits_preset_positions')
BEGIN
    CREATE TABLE dbo.splits_preset_positions (
        id INT IDENTITY(1,1) PRIMARY KEY,
        preset_id INT NOT NULL,
        position_name NVARCHAR(50) NOT NULL,
        sectors NVARCHAR(MAX) NOT NULL,  -- JSON array of sector IDs
        color CHAR(7) NOT NULL DEFAULT '#4dabf7',
        frequency NVARCHAR(10) NULL,
        sort_order INT NOT NULL DEFAULT 0,
        filters NVARCHAR(MAX) NULL,  -- JSON object for filters
        
        CONSTRAINT FK_preset_positions_preset 
            FOREIGN KEY (preset_id) 
            REFERENCES dbo.splits_presets(id) 
            ON DELETE CASCADE
    );
    
    CREATE INDEX IX_splits_preset_positions_preset ON dbo.splits_preset_positions(preset_id);
    
    PRINT 'Created table: dbo.splits_preset_positions';
END
ELSE
BEGIN
    PRINT 'Table dbo.splits_preset_positions already exists';
END
GO

-- ============================================================================
-- Sample Data (Optional - comment out if not needed)
-- ============================================================================

/*
-- Insert sample preset for ZDC
INSERT INTO dbo.splits_presets (preset_name, artcc, description)
VALUES ('Standard Day Split', 'ZDC', 'Typical daytime configuration with 4 positions');

DECLARE @presetId INT = SCOPE_IDENTITY();

INSERT INTO dbo.splits_preset_positions (preset_id, position_name, sectors, color, sort_order)
VALUES 
    (@presetId, 'Sector A', '["ZDC01","ZDC02","ZDC03"]', '#e63946', 0),
    (@presetId, 'Sector B', '["ZDC04","ZDC05"]', '#f4a261', 1),
    (@presetId, 'Sector C', '["ZDC06","ZDC07","ZDC08"]', '#2a9d8f', 2),
    (@presetId, 'Sector D', '["ZDC09","ZDC10"]', '#e9c46a', 3);
*/
