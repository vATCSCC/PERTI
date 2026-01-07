-- Migration: SUA Aggregate Members Table (SQL Server)
-- Links individual SUA features to aggregate complexes
-- E.g., "MOA DELTA NORTH", "MOA DELTA SOUTH" -> "DELTA MOA COMPLEX"

CREATE TABLE sua_aggregate_members (
    id INT IDENTITY(1,1) PRIMARY KEY,

    -- The aggregate (parent) SUA
    aggregate_sua_id NVARCHAR(64) NOT NULL,

    -- The member (child) SUA
    member_sua_id NVARCHAR(64) NOT NULL,

    -- Member role within aggregate
    member_role NVARCHAR(32) NULL,             -- e.g., 'NORTH', 'SOUTH', 'HIGH', 'LOW', 'PRIMARY', 'EXTENSION'

    -- Display order within aggregate
    display_order INT NOT NULL DEFAULT 0,

    -- Timestamps
    created_at DATETIME2 DEFAULT GETUTCDATE(),

    -- Constraints
    CONSTRAINT FK_aggregate_sua FOREIGN KEY (aggregate_sua_id) REFERENCES sua_definitions(sua_id),
    CONSTRAINT FK_member_sua FOREIGN KEY (member_sua_id) REFERENCES sua_definitions(sua_id),
    CONSTRAINT UQ_aggregate_member UNIQUE (aggregate_sua_id, member_sua_id)
);

-- Index for querying members of an aggregate
CREATE INDEX IX_sua_aggregate_members_aggregate ON sua_aggregate_members (aggregate_sua_id);
CREATE INDEX IX_sua_aggregate_members_member ON sua_aggregate_members (member_sua_id);

-- Example Usage:
-- A "WHISKEY MOA COMPLEX" aggregate might have members:
--   aggregate_sua_id: 'MOA_WHISKEY_COMPLEX'
--   member_sua_id: 'MOA_WHISKEY_NORTH', role: 'NORTH', order: 1
--   member_sua_id: 'MOA_WHISKEY_SOUTH', role: 'SOUTH', order: 2
--   member_sua_id: 'MOA_WHISKEY_EAST',  role: 'EAST',  order: 3
