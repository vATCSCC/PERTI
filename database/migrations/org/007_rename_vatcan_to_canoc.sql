-- Migration: 007_rename_vatcan_to_canoc.sql
-- Date: 2026-02-18
-- Purpose: Rename vatcan org to canoc, add org_name_long, update ECFMP division

-- 1. Add org_name_long column
ALTER TABLE organizations ADD COLUMN org_name_long VARCHAR(255) NULL AFTER org_name;

-- 2. Update all three orgs with new names and org_name_long
UPDATE organizations SET
    org_name = 'vATCSCC',
    org_name_long = 'Virtual Air Traffic Control System Command Center'
WHERE org_code = 'vatcscc';

UPDATE organizations SET
    org_name = 'ECFMP',
    org_name_long = 'European Collaboration and Flow Management Project',
    vatsim_division = 'VATEMEA'
WHERE org_code = 'ecfmp';

-- 3. Rename vatcan -> canoc (org itself last, after FK updates)
-- Update user_orgs first
UPDATE user_orgs SET org_code = 'canoc' WHERE org_code = 'vatcan';

-- Update p_plans
UPDATE p_plans SET org_code = 'canoc' WHERE org_code = 'vatcan';

-- Now update the organization row
UPDATE organizations SET
    org_code = 'canoc',
    org_name = 'CANOC',
    org_name_long = 'Canadian National Operations Centre',
    display_name = 'CANOC'
WHERE org_code = 'vatcan';
