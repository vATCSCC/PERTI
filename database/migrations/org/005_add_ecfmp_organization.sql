-- Migration: Add ECFMP organization
-- Date: 2026-02-17
-- Purpose: Add European CFMP (ECFMP) as an organization for flow control integration.

INSERT INTO organizations (org_code, org_name, display_name, region, vatsim_division, default_locale)
VALUES ('ecfmp', 'European Collaboration and Flow Management Project', 'ECFMP', 'EU', 'ECFMP', 'en-EU');
