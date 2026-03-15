-- =============================================================================
-- Migration 006: Widen sector_boundaries columns for CDM sectors
-- =============================================================================
-- CDM sector codes (e.g., EDGGFRK/1) and parent ARTCC codes (e.g., EDMM) can
-- exceed the original VARCHAR(16)/VARCHAR(4) limits designed for US sectors.
-- =============================================================================

-- Widen sector_code from VARCHAR(16) to VARCHAR(50)
ALTER TABLE sector_boundaries ALTER COLUMN sector_code TYPE VARCHAR(50);

-- Widen parent_artcc from VARCHAR(4) to VARCHAR(10)
ALTER TABLE sector_boundaries ALTER COLUMN parent_artcc TYPE VARCHAR(10);

-- Recreate get_route_sectors() with wider return types
CREATE OR REPLACE FUNCTION get_route_sectors(
    route_geom GEOMETRY,
    cruise_altitude INT,
    sector_type_filter VARCHAR(16) DEFAULT NULL
)
RETURNS TABLE (
    sector_code VARCHAR(50),
    sector_name VARCHAR(64),
    parent_artcc VARCHAR(10),
    sector_type VARCHAR(16),
    traversal_order FLOAT
) AS $$
BEGIN
    RETURN QUERY
    SELECT
        sb.sector_code,
        sb.sector_name,
        sb.parent_artcc,
        sb.sector_type,
        ST_LineLocatePoint(route_geom, ST_Centroid(ST_Intersection(route_geom, sb.geom))) AS traversal_order
    FROM sector_boundaries sb
    WHERE ST_Intersects(route_geom, sb.geom)
      AND (sb.floor_altitude IS NULL OR sb.floor_altitude <= cruise_altitude)
      AND (sb.ceiling_altitude IS NULL OR sb.ceiling_altitude >= cruise_altitude)
      AND (sector_type_filter IS NULL OR sb.sector_type = sector_type_filter)
    ORDER BY traversal_order;
END;
$$ LANGUAGE plpgsql STABLE;
