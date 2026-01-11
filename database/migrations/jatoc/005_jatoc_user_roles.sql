-- Migration 005: JATOC User Roles Table
-- Stores user permissions for JATOC operations
--
-- Roles:
--   DCC      - Full access: create, update, delete, close, report, ops_level, personnel
--   FACILITY - Facility-level: create, update, close
--   ECFMP    - Limited: create, update
--   CTP      - Limited: create, update
--   READONLY - View only

-- ============================================================================
-- Step 1: Create jatoc_user_roles table
-- ============================================================================
IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'jatoc_user_roles')
BEGIN
    CREATE TABLE dbo.jatoc_user_roles (
        id INT IDENTITY(1,1) PRIMARY KEY,
        cid VARCHAR(10) NOT NULL,                  -- VATSIM CID
        role_code VARCHAR(16) NOT NULL,            -- DCC, FACILITY, ECFMP, CTP, READONLY
        facility_code VARCHAR(8) NULL,             -- Optional: restrict to specific facility
        active BIT NOT NULL DEFAULT 1,             -- Whether role is active
        granted_by VARCHAR(64) NULL,               -- Who granted this role
        granted_at DATETIME2 NOT NULL DEFAULT SYSUTCDATETIME(),
        expires_at DATETIME2 NULL,                 -- Optional expiration
        notes NVARCHAR(256) NULL                   -- Optional notes
    );

    PRINT 'Created jatoc_user_roles table';
END
GO

-- ============================================================================
-- Step 2: Create indexes
-- ============================================================================
IF NOT EXISTS (
    SELECT * FROM sys.indexes
    WHERE object_id = OBJECT_ID('dbo.jatoc_user_roles')
    AND name = 'IX_jatoc_user_roles_cid'
)
BEGIN
    CREATE INDEX IX_jatoc_user_roles_cid
    ON dbo.jatoc_user_roles(cid);
    PRINT 'Created index on cid';
END
GO

IF NOT EXISTS (
    SELECT * FROM sys.indexes
    WHERE object_id = OBJECT_ID('dbo.jatoc_user_roles')
    AND name = 'IX_jatoc_user_roles_active'
)
BEGIN
    CREATE INDEX IX_jatoc_user_roles_active
    ON dbo.jatoc_user_roles(cid, active)
    WHERE active = 1;
    PRINT 'Created filtered index on active roles';
END
GO

-- ============================================================================
-- Step 3: Create unique constraint (cid + role_code + facility_code)
-- ============================================================================
IF NOT EXISTS (
    SELECT * FROM sys.indexes
    WHERE object_id = OBJECT_ID('dbo.jatoc_user_roles')
    AND name = 'IX_jatoc_user_roles_unique'
)
BEGIN
    -- Unique constraint for facility-specific roles
    CREATE UNIQUE INDEX IX_jatoc_user_roles_unique
    ON dbo.jatoc_user_roles(cid, role_code, facility_code)
    WHERE facility_code IS NOT NULL;
    PRINT 'Created unique index for facility-specific roles';
END
GO

IF NOT EXISTS (
    SELECT * FROM sys.indexes
    WHERE object_id = OBJECT_ID('dbo.jatoc_user_roles')
    AND name = 'IX_jatoc_user_roles_unique_global'
)
BEGIN
    -- Unique constraint for global roles (no facility)
    CREATE UNIQUE INDEX IX_jatoc_user_roles_unique_global
    ON dbo.jatoc_user_roles(cid, role_code)
    WHERE facility_code IS NULL;
    PRINT 'Created unique index for global roles';
END
GO

-- ============================================================================
-- Step 4: Seed initial DCC users (update with actual CIDs as needed)
-- ============================================================================
-- Uncomment and modify these to add initial DCC users:
--
-- INSERT INTO dbo.jatoc_user_roles (cid, role_code, granted_by, notes)
-- SELECT '123456', 'DCC', 'System', 'Initial setup'
-- WHERE NOT EXISTS (
--     SELECT 1 FROM dbo.jatoc_user_roles
--     WHERE cid = '123456' AND role_code = 'DCC'
-- );

PRINT 'Migration 005 complete: Created jatoc_user_roles table';
GO
