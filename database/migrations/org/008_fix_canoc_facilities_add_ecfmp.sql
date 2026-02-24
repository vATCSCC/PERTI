-- ============================================================================
-- Migration: 008_fix_canoc_facilities_add_ecfmp.sql
-- Database:  perti_site (MySQL 8)
-- Date:      2026-02-24
-- Purpose:   1. Fix org_facilities FK: vatcan -> canoc (missed in 007)
--            2. Seed ECFMP facility assignments (48 FIRs from facility-hierarchy.js)
-- ============================================================================

-- ----------------------------------------------------------------------------
-- 1. Fix CANOC: rename org_code from 'vatcan' to 'canoc'
--    Migration 007 updated organizations/user_orgs/p_plans but missed org_facilities.
-- ----------------------------------------------------------------------------
UPDATE org_facilities SET org_code = 'canoc' WHERE org_code = 'vatcan';

-- ----------------------------------------------------------------------------
-- 2. Add ECFMP facility assignments (European FIRs)
--    Source: assets/js/facility-hierarchy.js ECFMP_ALL region definition
-- ----------------------------------------------------------------------------

-- UK & Ireland
INSERT INTO org_facilities (org_code, facility_code, facility_type) VALUES
    ('ecfmp', 'EGPX', 'FIR'),
    ('ecfmp', 'EGTT', 'FIR'),
    ('ecfmp', 'EISN', 'FIR');

-- France
INSERT INTO org_facilities (org_code, facility_code, facility_type) VALUES
    ('ecfmp', 'LFFF', 'FIR'),
    ('ecfmp', 'LFBB', 'FIR'),
    ('ecfmp', 'LFEE', 'FIR'),
    ('ecfmp', 'LFMM', 'FIR'),
    ('ecfmp', 'LFRR', 'FIR');

-- Germany, Netherlands, Belgium, Luxembourg, Switzerland
INSERT INTO org_facilities (org_code, facility_code, facility_type) VALUES
    ('ecfmp', 'EDGG', 'FIR'),
    ('ecfmp', 'EDMM', 'FIR'),
    ('ecfmp', 'EDUU', 'FIR'),
    ('ecfmp', 'EDWW', 'FIR'),
    ('ecfmp', 'EHAA', 'FIR'),
    ('ecfmp', 'EBBU', 'FIR'),
    ('ecfmp', 'ELLX', 'FIR'),
    ('ecfmp', 'LSAS', 'FIR');

-- Nordics & Iceland
INSERT INTO org_facilities (org_code, facility_code, facility_type) VALUES
    ('ecfmp', 'EFIN', 'FIR'),
    ('ecfmp', 'ENOR', 'FIR'),
    ('ecfmp', 'ESAA', 'FIR'),
    ('ecfmp', 'EKDK', 'FIR'),
    ('ecfmp', 'BIRD', 'FIR'),
    ('ecfmp', 'BICC', 'FIR');

-- Baltics & Poland
INSERT INTO org_facilities (org_code, facility_code, facility_type) VALUES
    ('ecfmp', 'EETT', 'FIR'),
    ('ecfmp', 'EVRR', 'FIR'),
    ('ecfmp', 'EYVL', 'FIR'),
    ('ecfmp', 'EPWW', 'FIR');

-- Central Europe (Austria, Czechia, Slovakia, Hungary, Croatia, Slovenia, Bosnia)
INSERT INTO org_facilities (org_code, facility_code, facility_type) VALUES
    ('ecfmp', 'LOVV', 'FIR'),
    ('ecfmp', 'LKAA', 'FIR'),
    ('ecfmp', 'LZBB', 'FIR'),
    ('ecfmp', 'LHCC', 'FIR'),
    ('ecfmp', 'LDZO', 'FIR'),
    ('ecfmp', 'LJLA', 'FIR'),
    ('ecfmp', 'LQSB', 'FIR');

-- Iberian Peninsula (Spain, Portugal)
INSERT INTO org_facilities (org_code, facility_code, facility_type) VALUES
    ('ecfmp', 'LECM', 'FIR'),
    ('ecfmp', 'LECB', 'FIR'),
    ('ecfmp', 'LECS', 'FIR'),
    ('ecfmp', 'LPPC', 'FIR');

-- Italy, Greece, Cyprus, Malta
INSERT INTO org_facilities (org_code, facility_code, facility_type) VALUES
    ('ecfmp', 'LIBB', 'FIR'),
    ('ecfmp', 'LIMM', 'FIR'),
    ('ecfmp', 'LIPP', 'FIR'),
    ('ecfmp', 'LIRR', 'FIR'),
    ('ecfmp', 'LGGG', 'FIR'),
    ('ecfmp', 'LCCC', 'FIR'),
    ('ecfmp', 'LMMM', 'FIR');

-- Southeast Europe (Romania, Bulgaria, Turkey, Serbia, Albania, North Macedonia, Moldova)
INSERT INTO org_facilities (org_code, facility_code, facility_type) VALUES
    ('ecfmp', 'LRBB', 'FIR'),
    ('ecfmp', 'LBSR', 'FIR'),
    ('ecfmp', 'LTAA', 'FIR'),
    ('ecfmp', 'LYBA', 'FIR'),
    ('ecfmp', 'LAAA', 'FIR'),
    ('ecfmp', 'LWSK', 'FIR'),
    ('ecfmp', 'LUUU', 'FIR');
