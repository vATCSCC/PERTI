-- Migration: Add global role to user_orgs
-- Date: 2026-02-18
-- Purpose: Add is_global flag so designated users can see/edit/delete
--          ALL plans regardless of org scope. Also fix missing privileges.

-- 1. Add is_global column
ALTER TABLE user_orgs ADD COLUMN is_global TINYINT NOT NULL DEFAULT 0;

-- 2. Grant global role to CID 1234727 (Jeremy Peterson)
UPDATE user_orgs SET is_global = 1 WHERE cid = 1234727;

-- 3. Ensure CID 1233493 (Nathan Power) has is_privileged = 1 for all orgs
UPDATE user_orgs SET is_privileged = 1 WHERE cid = 1233493;

-- 4. Ensure CID 1233493 has all three org memberships
INSERT IGNORE INTO user_orgs (cid, org_code, is_privileged, is_primary, is_global)
VALUES
    (1233493, 'vatcscc', 1, 1, 1),
    (1233493, 'vatcan',  1, 0, 1),
    (1233493, 'ecfmp',   1, 0, 1);

-- Also grant Nathan global role
UPDATE user_orgs SET is_global = 1 WHERE cid = 1233493;
