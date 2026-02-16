-- ============================================================================
-- Migration: 001_create_organizations.sql
-- Database:  perti_site (MySQL 8)
-- Purpose:   Create multi-organization tables and seed vATCSCC + VATCAN data
-- ============================================================================

-- ----------------------------------------------------------------------------
-- 1. Organizations table
--    Each row represents an operating organization (e.g. vATCSCC, VATCAN).
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS organizations (
    org_code      VARCHAR(16)  PRIMARY KEY,
    org_name      VARCHAR(64)  NOT NULL,
    display_name  VARCHAR(64)  NOT NULL,
    region        VARCHAR(8)   NOT NULL,
    vatsim_division VARCHAR(8) NOT NULL,
    default_locale VARCHAR(8)  NOT NULL DEFAULT 'en-US',
    is_active     TINYINT      NOT NULL DEFAULT 1,
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- ----------------------------------------------------------------------------
-- 2. User-organization memberships
--    Links VATSIM CIDs to organizations with privilege and primary flags.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS user_orgs (
    id          INT          PRIMARY KEY AUTO_INCREMENT,
    cid         INT          NOT NULL,
    org_code    VARCHAR(16)  NOT NULL,
    is_privileged TINYINT    NOT NULL DEFAULT 0,
    is_primary  TINYINT      NOT NULL DEFAULT 0,
    assigned_by INT          NULL,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_cid_org (cid, org_code),
    FOREIGN KEY (org_code) REFERENCES organizations(org_code)
);

-- ----------------------------------------------------------------------------
-- 3. Organization facility assignments
--    Maps ARTCCs / FIRs to their owning organization.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS org_facilities (
    org_code      VARCHAR(16) NOT NULL,
    facility_code VARCHAR(8)  NOT NULL,
    facility_type VARCHAR(16) NOT NULL,
    PRIMARY KEY (org_code, facility_code),
    FOREIGN KEY (org_code) REFERENCES organizations(org_code)
);

-- ============================================================================
-- SEED DATA
-- ============================================================================

-- ----------------------------------------------------------------------------
-- Organizations
-- ----------------------------------------------------------------------------
INSERT INTO organizations (org_code, org_name, display_name, region, vatsim_division, default_locale, is_active, created_at)
VALUES
    ('vatcscc', 'vATCSCC', 'DCC', 'US', 'VATUSA', 'en-US', 1, NOW()),
    ('vatcan',  'VATCAN',  'NOC', 'CA', 'VATCAN', 'en-CA', 1, NOW());

-- ----------------------------------------------------------------------------
-- CID 1234727 (Jeremy Peterson / HP) - privileged on both orgs, primary vatcscc
-- ----------------------------------------------------------------------------
INSERT INTO user_orgs (cid, org_code, is_privileged, is_primary)
VALUES
    (1234727, 'vatcscc', 1, 1),
    (1234727, 'vatcan',  1, 0);

-- ----------------------------------------------------------------------------
-- Backfill: all existing users and admin_users get vatcscc membership
-- INSERT IGNORE skips duplicates (e.g. CID 1234727 already inserted above)
-- ----------------------------------------------------------------------------
INSERT IGNORE INTO user_orgs (cid, org_code, is_privileged, is_primary)
SELECT cid, 'vatcscc', 0, 1
FROM users;

INSERT IGNORE INTO user_orgs (cid, org_code, is_privileged, is_primary)
SELECT cid, 'vatcscc', 1, 0
FROM admin_users;

-- ----------------------------------------------------------------------------
-- Facility assignments: US continental ARTCCs -> vatcscc
-- ----------------------------------------------------------------------------
INSERT INTO org_facilities (org_code, facility_code, facility_type) VALUES
    ('vatcscc', 'ZAB', 'ARTCC'),
    ('vatcscc', 'ZAU', 'ARTCC'),
    ('vatcscc', 'ZBW', 'ARTCC'),
    ('vatcscc', 'ZDC', 'ARTCC'),
    ('vatcscc', 'ZDV', 'ARTCC'),
    ('vatcscc', 'ZFW', 'ARTCC'),
    ('vatcscc', 'ZHU', 'ARTCC'),
    ('vatcscc', 'ZID', 'ARTCC'),
    ('vatcscc', 'ZJX', 'ARTCC'),
    ('vatcscc', 'ZKC', 'ARTCC'),
    ('vatcscc', 'ZLA', 'ARTCC'),
    ('vatcscc', 'ZLC', 'ARTCC'),
    ('vatcscc', 'ZMA', 'ARTCC'),
    ('vatcscc', 'ZME', 'ARTCC'),
    ('vatcscc', 'ZMP', 'ARTCC'),
    ('vatcscc', 'ZNY', 'ARTCC'),
    ('vatcscc', 'ZOA', 'ARTCC'),
    ('vatcscc', 'ZOB', 'ARTCC'),
    ('vatcscc', 'ZSE', 'ARTCC'),
    ('vatcscc', 'ZTL', 'ARTCC');

-- Oceanic / Pacific ARTCCs -> vatcscc
INSERT INTO org_facilities (org_code, facility_code, facility_type) VALUES
    ('vatcscc', 'ZAK', 'ARTCC'),
    ('vatcscc', 'ZAN', 'ARTCC'),
    ('vatcscc', 'ZHN', 'ARTCC'),
    ('vatcscc', 'ZAP', 'ARTCC'),
    ('vatcscc', 'ZWY', 'ARTCC'),
    ('vatcscc', 'ZHO', 'ARTCC'),
    ('vatcscc', 'ZMO', 'ARTCC'),
    ('vatcscc', 'ZUA', 'ARTCC');

-- Caribbean ARTCC -> vatcscc
INSERT INTO org_facilities (org_code, facility_code, facility_type) VALUES
    ('vatcscc', 'ZSU', 'ARTCC');

-- ----------------------------------------------------------------------------
-- Facility assignments: Canadian FIRs -> vatcan
-- ----------------------------------------------------------------------------
INSERT INTO org_facilities (org_code, facility_code, facility_type) VALUES
    ('vatcan', 'CZYZ', 'FIR'),
    ('vatcan', 'CZUL', 'FIR'),
    ('vatcan', 'CZEG', 'FIR'),
    ('vatcan', 'CZVR', 'FIR'),
    ('vatcan', 'CZWG', 'FIR'),
    ('vatcan', 'CZQM', 'FIR'),
    ('vatcan', 'CZQX', 'FIR'),
    ('vatcan', 'CZQO', 'FIR');
