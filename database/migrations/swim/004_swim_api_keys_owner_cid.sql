-- ============================================================================
-- SWIM API Keys - Add Owner CID Column
--
-- Adds owner_cid column to track which VATSIM user created each API key.
-- Required for self-service API key management portal.
--
-- Version: 1.0.1
-- Date: 2026-01-16
-- ============================================================================

-- Add owner_cid column if it doesn't exist
IF NOT EXISTS (
    SELECT 1 FROM sys.columns
    WHERE object_id = OBJECT_ID('dbo.swim_api_keys')
    AND name = 'owner_cid'
)
BEGIN
    ALTER TABLE dbo.swim_api_keys
    ADD owner_cid NVARCHAR(16) NULL;

    PRINT 'Added owner_cid column to swim_api_keys';
END
GO

-- Add description column if it doesn't exist
IF NOT EXISTS (
    SELECT 1 FROM sys.columns
    WHERE object_id = OBJECT_ID('dbo.swim_api_keys')
    AND name = 'description'
)
BEGIN
    ALTER TABLE dbo.swim_api_keys
    ADD description NVARCHAR(256) NULL;

    PRINT 'Added description column to swim_api_keys';
END
GO

-- Create index on owner_cid for faster lookups
IF NOT EXISTS (
    SELECT 1 FROM sys.indexes
    WHERE object_id = OBJECT_ID('dbo.swim_api_keys')
    AND name = 'IX_swim_api_keys_owner_cid'
)
BEGIN
    CREATE INDEX IX_swim_api_keys_owner_cid
    ON dbo.swim_api_keys (owner_cid)
    WHERE owner_cid IS NOT NULL;

    PRINT 'Created index IX_swim_api_keys_owner_cid';
END
GO

-- ============================================================================
-- Verify changes
-- ============================================================================
SELECT
    c.name AS column_name,
    t.name AS data_type,
    c.max_length,
    c.is_nullable
FROM sys.columns c
JOIN sys.types t ON c.user_type_id = t.user_type_id
WHERE c.object_id = OBJECT_ID('dbo.swim_api_keys')
ORDER BY c.column_id;
GO
