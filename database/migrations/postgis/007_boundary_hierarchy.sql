-- Migration 007: Create boundary_hierarchy table for PostGIS
-- Stores parent-child relationships between boundaries (ARTCC containment, sector membership, etc.)

CREATE TABLE IF NOT EXISTS boundary_hierarchy (
    hierarchy_id SERIAL PRIMARY KEY,
    parent_boundary_id INTEGER NOT NULL,
    child_boundary_id INTEGER NOT NULL,
    parent_code VARCHAR(50) NOT NULL,
    child_code VARCHAR(50) NOT NULL,
    parent_type VARCHAR(20) NOT NULL,
    child_type VARCHAR(20) NOT NULL,
    relationship_type VARCHAR(20) NOT NULL,
    coverage_ratio DECIMAL(5,4),
    created_at TIMESTAMPTZ DEFAULT NOW(),
    CONSTRAINT uq_hierarchy_edge UNIQUE (parent_boundary_id, child_boundary_id)
);

CREATE INDEX IF NOT EXISTS ix_hierarchy_parent ON boundary_hierarchy (parent_boundary_id);
CREATE INDEX IF NOT EXISTS ix_hierarchy_child ON boundary_hierarchy (child_boundary_id);
CREATE INDEX IF NOT EXISTS ix_hierarchy_type ON boundary_hierarchy (relationship_type);
CREATE INDEX IF NOT EXISTS ix_hierarchy_parent_code ON boundary_hierarchy (parent_code);
CREATE INDEX IF NOT EXISTS ix_hierarchy_child_code ON boundary_hierarchy (child_code);
