-- Migration 058: RAD V2 Workflow — Multi-Actor Amendments + TOS
-- Target: VATSIM_TMI database
-- Depends on: 057_rad_tables.sql (deployed)

-- 1. Replace CHECK constraint: add V2 states, keep DLVD for backward compat
ALTER TABLE dbo.rad_amendments DROP CONSTRAINT CK_rad_status;
ALTER TABLE dbo.rad_amendments ADD CONSTRAINT CK_rad_status
    CHECK (status IN ('DRAFT','SENT','ISSUED','DLVD','ACPT','RJCT',
                      'TOS_PENDING','TOS_RESOLVED','FORCED','EXPR'));

-- 2. New columns for V2
ALTER TABLE dbo.rad_amendments ADD
    clearance_text      VARCHAR(MAX) NULL,
    clearance_segments  VARCHAR(MAX) NULL,
    closing_phrase      VARCHAR(20)  NULL,
    issued_by           INT          NULL,
    issued_utc          DATETIME2    NULL,
    rejected_by         INT          NULL,
    rejected_utc        DATETIME2    NULL,
    resolved_by         INT          NULL,
    tos_id              INT          NULL,
    forced_utc          DATETIME2    NULL,
    parent_amendment_id INT          NULL,
    actor_role          VARCHAR(10)  NULL;

-- 3. Update filtered index (include new active statuses)
-- SQL Server filtered indexes cannot use IN(); rewrite as individual predicates with OR
-- Actually, SQL Server filtered indexes DO support IN() syntax. But from memory notes:
-- "SQL Server filtered indexes: Cannot use OR in the WHERE clause"
-- So we must drop and recreate without a filter, or use a simpler predicate.
-- Best approach: non-filtered covering index (small table, ~hundreds of rows max)
DROP INDEX IX_rad_amendments_status ON dbo.rad_amendments;
CREATE INDEX IX_rad_amendments_status ON dbo.rad_amendments (status)
    INCLUDE (gufi, callsign, origin, destination, assigned_route, rrstat, sent_utc);

-- 4. Index for TOS resolution chain lookups
CREATE INDEX IX_rad_amendments_parent ON dbo.rad_amendments (parent_amendment_id)
    INCLUDE (gufi, status)
    WHERE parent_amendment_id IS NOT NULL;

-- 5. TOS table (Phase 3, deployed now for schema stability)
CREATE TABLE dbo.rad_tos (
    id              INT IDENTITY(1,1) PRIMARY KEY,
    amendment_id    INT NOT NULL,
    gufi            NVARCHAR(64) NOT NULL,
    submitted_by    INT NOT NULL,
    submitted_role  VARCHAR(10) NOT NULL,
    submitted_utc   DATETIME2 NOT NULL DEFAULT SYSUTCDATETIME(),
    resolved_utc    DATETIME2 NULL,
    resolved_action VARCHAR(20) NULL,
    resolved_option_rank INT NULL,
    resolved_by     INT NULL,
    notes           VARCHAR(500) NULL,
    CONSTRAINT FK_rad_tos_amendment FOREIGN KEY (amendment_id)
        REFERENCES dbo.rad_amendments(id)
);

CREATE INDEX IX_rad_tos_amendment ON dbo.rad_tos (amendment_id);
CREATE INDEX IX_rad_tos_gufi ON dbo.rad_tos (gufi);

-- 6. TOS options table (Phase 3)
CREATE TABLE dbo.rad_tos_options (
    id              INT IDENTITY(1,1) PRIMARY KEY,
    tos_id          INT NOT NULL,
    rank            INT NOT NULL,
    route_string    VARCHAR(MAX) NOT NULL,
    option_type     VARCHAR(20) NOT NULL,
    distance_nm     DECIMAL(10,1) NULL,
    time_minutes    INT NULL,
    route_geojson   VARCHAR(MAX) NULL,
    CONSTRAINT FK_rad_tos_option_tos FOREIGN KEY (tos_id)
        REFERENCES dbo.rad_tos(id) ON DELETE CASCADE
);

CREATE INDEX IX_rad_tos_options_tos ON dbo.rad_tos_options (tos_id);
