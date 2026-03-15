-- Migration 047: Fix active views to handle NULL end_utc
--
-- Bug: vw_tmi_active_reroutes and vw_tmi_active_public_routes filter on
-- end_utc > SYSUTCDATETIME(), which excludes rows where end_utc is NULL.
-- When activateProposal() sets status=2 without populating end_utc
-- (e.g. user didn't specify valid_until), the reroute/route becomes
-- invisible to the active TMI query.
--
-- Fix: Treat NULL end_utc as "no expiration" (active until manually cancelled).
-- Also fixes activateProposal() to always populate end_utc going forward,
-- but the view change protects against any remaining edge cases.

-- Recreate vw_tmi_active_reroutes
IF OBJECT_ID('dbo.vw_tmi_active_reroutes', 'V') IS NOT NULL
    DROP VIEW dbo.vw_tmi_active_reroutes;
GO

CREATE VIEW dbo.vw_tmi_active_reroutes AS
SELECT * FROM dbo.tmi_reroutes
WHERE status = 2 AND (end_utc IS NULL OR end_utc > SYSUTCDATETIME());
GO

-- Recreate vw_tmi_active_public_routes
IF OBJECT_ID('dbo.vw_tmi_active_public_routes', 'V') IS NOT NULL
    DROP VIEW dbo.vw_tmi_active_public_routes;
GO

CREATE VIEW dbo.vw_tmi_active_public_routes AS
SELECT * FROM dbo.tmi_public_routes
WHERE status = 1 AND (valid_end_utc IS NULL OR valid_end_utc > SYSUTCDATETIME());
GO

-- Backfill: set end_utc for any currently active reroutes that have NULL end_utc
UPDATE dbo.tmi_reroutes
SET end_utc = DATEADD(HOUR, 24, SYSUTCDATETIME()),
    updated_at = SYSUTCDATETIME()
WHERE status = 2 AND end_utc IS NULL;

PRINT 'Migration 047 complete: active views now handle NULL end_utc';
GO
