-- splits/schema.sql - Database Schema Reference
-- Tables exist in VATSIM_ADL Azure SQL database

-- =========================================================================
-- splits_areas - Pre-defined groups of sectors (e.g., "ZNY A" = ZNY07+ZNY08+ZNY09...)
-- =========================================================================
CREATE TABLE splits_areas (
    id INT IDENTITY(1,1) PRIMARY KEY,
    artcc NVARCHAR(4) NOT NULL,           -- e.g., 'ZNY', 'ZLA', 'ZDC'
    area_name NVARCHAR(100) NOT NULL,      -- e.g., 'ZNY A', 'East Side'
    sectors NVARCHAR(MAX) NOT NULL,        -- JSON array: ["ZNY07", "ZNY08", "ZNY09"]
    description NVARCHAR(500) NULL,        -- Optional description
    created_by NVARCHAR(50) NOT NULL DEFAULT 'system',
    created_at DATETIME2 NOT NULL DEFAULT GETUTCDATE(),
    updated_at DATETIME2 NOT NULL DEFAULT GETUTCDATE()
);

CREATE INDEX IX_splits_areas_artcc ON splits_areas(artcc);

-- =========================================================================
-- splits_configs - Split configurations (defines a set of positions/sectors)
-- =========================================================================
CREATE TABLE splits_configs (
    id INT IDENTITY(1,1) PRIMARY KEY,
    artcc NVARCHAR(4) NOT NULL,            -- e.g., 'ZNY'
    config_name NVARCHAR(100) NOT NULL,    -- e.g., 'East Flow Primary'
    start_time_utc DATETIME2 NULL,         -- Optional scheduled start time
    end_time_utc DATETIME2 NULL,           -- Optional scheduled end time
    status NVARCHAR(20) NOT NULL DEFAULT 'draft', -- draft, active, inactive, archived
    sector_type NVARCHAR(10) NOT NULL DEFAULT 'high', -- high, low
    created_by NVARCHAR(50) NOT NULL DEFAULT 'system',
    created_at DATETIME2 NOT NULL DEFAULT GETUTCDATE(),
    activated_by NVARCHAR(50) NULL,        -- Who activated this config
    activated_at DATETIME2 NULL            -- When it was activated
);

CREATE INDEX IX_splits_configs_artcc ON splits_configs(artcc);
CREATE INDEX IX_splits_configs_status ON splits_configs(status);
CREATE INDEX IX_splits_configs_artcc_status ON splits_configs(artcc, status);

-- =========================================================================
-- splits_positions - Individual positions within a configuration
-- =========================================================================
CREATE TABLE splits_positions (
    id INT IDENTITY(1,1) PRIMARY KEY,
    config_id INT NOT NULL,                -- FK to splits_configs.id
    position_name NVARCHAR(50) NOT NULL,   -- e.g., 'NY_E_CTR', 'NY_W_CTR'
    color NVARCHAR(10) NOT NULL DEFAULT '#808080', -- Hex color for map display
    sectors NVARCHAR(MAX) NOT NULL,        -- JSON array: ["ZNY07", "ZNY08"]
    display_order INT NOT NULL DEFAULT 1,  -- Order in UI list
    start_time_utc DATETIME2 NULL,         -- Optional position-specific timing
    end_time_utc DATETIME2 NULL,
    
    CONSTRAINT FK_splits_positions_config 
        FOREIGN KEY (config_id) REFERENCES splits_configs(id) ON DELETE CASCADE
);

CREATE INDEX IX_splits_positions_config ON splits_positions(config_id);

-- =========================================================================
-- Example Data
-- =========================================================================

-- Example area
-- INSERT INTO splits_areas (artcc, area_name, sectors, description, created_by)
-- VALUES ('ZNY', 'ZNY A', '["ZNY07", "ZNY08", "ZNY09"]', 'Eastern sectors', 'admin');

-- Example config with positions
-- INSERT INTO splits_configs (artcc, config_name, status, sector_type, created_by)
-- VALUES ('ZNY', 'East Flow Primary', 'draft', 'high', 'admin');
-- 
-- INSERT INTO splits_positions (config_id, position_name, color, sectors, display_order)
-- VALUES (1, 'NY_E_CTR', '#FF5733', '["ZNY07", "ZNY08"]', 1);
-- 
-- INSERT INTO splits_positions (config_id, position_name, color, sectors, display_order)
-- VALUES (1, 'NY_W_CTR', '#33FF57', '["ZNY01", "ZNY02"]', 2);
