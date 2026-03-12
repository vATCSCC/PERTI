-- ============================================================================
-- Migration: 011_org_roles.sql
-- Database:  perti_site (MySQL 8)
-- Purpose:   Create org-level roles for profile/staffing display
-- ============================================================================

-- ----------------------------------------------------------------------------
-- 1. Organization roles definition table
--    Generic — any org can define roles. Initially seeded for CTP.
--    Roles are for profile settings & TMU staffing display, not access control.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS org_roles (
    role_id         INT           AUTO_INCREMENT PRIMARY KEY,
    org_code        VARCHAR(16)   NOT NULL,
    role_code       VARCHAR(20)   NOT NULL,
    role_name       VARCHAR(64)   NOT NULL,
    description     VARCHAR(255)  NULL,
    sort_order      INT           NOT NULL DEFAULT 0,
    is_active       TINYINT       NOT NULL DEFAULT 1,
    created_at      TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_org_role (org_code, role_code),
    INDEX idx_org_roles_org (org_code),
    FOREIGN KEY (org_code) REFERENCES organizations(org_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------------------------
-- 2. User-role junction table
--    Links VATSIM CIDs to org-specific roles.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS user_org_roles (
    id              INT           AUTO_INCREMENT PRIMARY KEY,
    cid             INT           NOT NULL,
    org_code        VARCHAR(16)   NOT NULL,
    role_code       VARCHAR(20)   NOT NULL,
    assigned_by     INT           NULL,
    created_at      TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_user_org_role (cid, org_code, role_code),
    INDEX idx_user_org_roles_cid (cid),
    FOREIGN KEY (org_code, role_code) REFERENCES org_roles(org_code, role_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------------------------
-- 3. Seed CTP roles
-- ----------------------------------------------------------------------------
INSERT INTO org_roles (org_code, role_code, role_name, sort_order) VALUES
    ('ctp', 'PLAN',       'Planning Team',           1),
    ('ctp', 'COORD',      'Coordinator',             2),
    ('ctp', 'COMMS',      'Communications',          3),
    ('ctp', 'ROUTE-EMEA', 'EMEA Routes',             4),
    ('ctp', 'ROUTE-AMAS', 'AMAS Routes',             5),
    ('ctp', 'DATA-LOG',   'Data & Logistics',        6),
    ('ctp', 'OCEAN',      'Oceanic',                 7),
    ('ctp', 'TECH',       'Technology',              8),
    ('ctp', 'DEV',        'Development',             9),
    ('ctp', 'PILOT',      'Pilot Training & Outreach', 10),
    ('ctp', 'ASSIST',     'Coordinators'' Assistant', 11),
    ('ctp', 'OCEAN-OPS',  'Oceanic Operations',      12),
    ('ctp', 'OCEAN-WRN',  'Oceanic Wrangler',        13);
