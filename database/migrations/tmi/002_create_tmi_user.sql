-- =============================================================================
-- VATSIM_TMI Database User Setup
-- Server: vatsim.database.windows.net
-- Database: VATSIM_TMI
-- =============================================================================
--
-- This script creates a dedicated user for the TMI database with appropriate
-- permissions. Run this in two parts:
--   1. Part A: Run connected to the 'master' database
--   2. Part B: Run connected to the 'VATSIM_TMI' database
--
-- =============================================================================

-- =============================================================================
-- PART A: CREATE LOGIN (Run on 'master' database)
-- =============================================================================
-- Connect to: vatsim.database.windows.net / master

/*
-- Option 1: Create a new login with a strong password
-- Replace 'YourStrongPassword123!' with a secure password

CREATE LOGIN tmi_api_user WITH PASSWORD = 'YourStrongPassword123!';

-- Verify login was created
SELECT name, type_desc, create_date FROM sys.sql_logins WHERE name = 'tmi_api_user';
*/

-- =============================================================================
-- PART B: CREATE USER AND GRANT PERMISSIONS (Run on 'VATSIM_TMI' database)
-- =============================================================================
-- Connect to: vatsim.database.windows.net / VATSIM_TMI

-- Option 1: Create user from new login (if you created a new login above)
-- CREATE USER [tmi_api_user] FROM LOGIN [tmi_api_user];

-- Option 2: Use existing ADL login (simpler, shares credentials)
-- This creates a user in VATSIM_TMI that uses the existing adl_api_user login
-- CREATE USER [tmi_api_user] FROM LOGIN [adl_api_user];

-- For Azure SQL, you can also create a contained database user (no server login needed):
-- CREATE USER [tmi_api_user] WITH PASSWORD = 'YourStrongPassword123!';

-- =============================================================================
-- GRANT PERMISSIONS
-- =============================================================================

-- Basic read/write access
ALTER ROLE db_datareader ADD MEMBER [tmi_api_user];
ALTER ROLE db_datawriter ADD MEMBER [tmi_api_user];

-- Execute stored procedures
GRANT EXECUTE ON SCHEMA::dbo TO [tmi_api_user];

-- Specific table permissions (if you want more granular control)
-- GRANT SELECT, INSERT, UPDATE, DELETE ON dbo.tmi_entries TO [tmi_api_user];
-- GRANT SELECT, INSERT, UPDATE, DELETE ON dbo.tmi_programs TO [tmi_api_user];
-- GRANT SELECT, INSERT, UPDATE, DELETE ON dbo.tmi_advisories TO [tmi_api_user];
-- GRANT SELECT, INSERT, UPDATE, DELETE ON dbo.tmi_slots TO [tmi_api_user];
-- GRANT SELECT, INSERT, UPDATE, DELETE ON dbo.tmi_reroutes TO [tmi_api_user];
-- GRANT SELECT, INSERT, UPDATE, DELETE ON dbo.tmi_reroute_flights TO [tmi_api_user];
-- GRANT SELECT, INSERT, UPDATE, DELETE ON dbo.tmi_reroute_compliance_log TO [tmi_api_user];
-- GRANT SELECT, INSERT, UPDATE, DELETE ON dbo.tmi_public_routes TO [tmi_api_user];
-- GRANT SELECT, INSERT, UPDATE, DELETE ON dbo.tmi_events TO [tmi_api_user];
-- GRANT SELECT, INSERT, UPDATE ON dbo.tmi_advisory_sequences TO [tmi_api_user];

-- View permissions (read-only)
GRANT SELECT ON dbo.vw_tmi_active_entries TO [tmi_api_user];
GRANT SELECT ON dbo.vw_tmi_active_advisories TO [tmi_api_user];
GRANT SELECT ON dbo.vw_tmi_active_programs TO [tmi_api_user];
GRANT SELECT ON dbo.vw_tmi_active_reroutes TO [tmi_api_user];
GRANT SELECT ON dbo.vw_tmi_active_public_routes TO [tmi_api_user];
GRANT SELECT ON dbo.vw_tmi_recent_entries TO [tmi_api_user];

-- Stored procedure permissions
GRANT EXECUTE ON dbo.sp_GetNextAdvisoryNumber TO [tmi_api_user];
GRANT EXECUTE ON dbo.sp_LogTmiEvent TO [tmi_api_user];
GRANT EXECUTE ON dbo.sp_ExpireOldEntries TO [tmi_api_user];
GRANT EXECUTE ON dbo.sp_GetActivePublicRoutes TO [tmi_api_user];

-- =============================================================================
-- VERIFY PERMISSIONS
-- =============================================================================

-- Check user exists
SELECT 
    dp.name AS username,
    dp.type_desc AS user_type,
    dp.create_date
FROM sys.database_principals dp
WHERE dp.name = 'tmi_api_user';

-- Check role memberships
SELECT 
    dp.name AS username,
    r.name AS role_name
FROM sys.database_principals dp
JOIN sys.database_role_members drm ON dp.principal_id = drm.member_principal_id
JOIN sys.database_principals r ON drm.role_principal_id = r.principal_id
WHERE dp.name = 'tmi_api_user';

-- Check explicit permissions
SELECT 
    dp.name AS username,
    o.name AS object_name,
    p.permission_name,
    p.state_desc
FROM sys.database_permissions p
JOIN sys.database_principals dp ON p.grantee_principal_id = dp.principal_id
LEFT JOIN sys.objects o ON p.major_id = o.object_id
WHERE dp.name = 'tmi_api_user'
ORDER BY o.name, p.permission_name;

PRINT 'TMI user setup complete!';
