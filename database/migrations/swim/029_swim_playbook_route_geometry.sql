-- Migration 029: Add route_geometry to swim_playbook_routes
-- Stores the frozen JSON envelope (geojson + waypoints + distance_nm)
-- synced from MySQL perti_site.playbook_routes.route_geometry
-- Eliminates PostGIS dependency for SWIM ?include=geometry

IF NOT EXISTS (
    SELECT 1 FROM sys.columns
    WHERE object_id = OBJECT_ID('dbo.swim_playbook_routes')
      AND name = 'route_geometry'
)
BEGIN
    ALTER TABLE dbo.swim_playbook_routes
    ADD route_geometry NVARCHAR(MAX) NULL;
    PRINT 'Added column: swim_playbook_routes.route_geometry';
END
GO
