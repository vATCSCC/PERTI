-- Migration 013: Add frozen route geometry to playbook_routes
-- Stores GeoJSON TEXT at write time so routes survive AIRAC fix removals.
-- A 10-waypoint LineString is ~300-500 bytes; 42K routes = ~20MB total.

ALTER TABLE playbook_routes
ADD COLUMN route_geometry TEXT NULL DEFAULT NULL
AFTER traversed_sectors_superhigh;
