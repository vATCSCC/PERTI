-- ============================================================================
-- Migration 021: Add transition_type column to nav_procedures
-- ============================================================================
-- Classifies procedure transitions into three categories:
--   'fix'    — Fix-based transition (e.g., RAKAM, LOOSE, SAPPO)
--   'runway' — Runway-specific transition (e.g., RW09L, RW27, RW34B)
--   NULL     — Base procedure with no specific transition
--
-- This enables proper display labeling and allows expand_route() to
-- prefer fix transitions over runway-specific ones during route expansion.
--
-- Safe to re-run (ADD COLUMN IF NOT EXISTS + backfill is idempotent).
-- ============================================================================

-- Add column
ALTER TABLE nav_procedures ADD COLUMN IF NOT EXISTS transition_type VARCHAR(10);

-- Backfill existing data based on transition_name patterns
-- NASR data: all non-empty transitions are fix-based (NASR doesn't have runway transitions)
-- CIFP data: RW-prefixed transition_name = runway, others = fix
UPDATE nav_procedures
SET transition_type = CASE
    WHEN transition_name IS NULL OR transition_name = '' THEN NULL
    WHEN transition_name ~ '^RW\d' THEN 'runway'
    WHEN transition_name LIKE 'RWY %' THEN 'runway'
    WHEN transition_name LIKE 'RUNWAY %' THEN 'runway'
    ELSE 'fix'
END
WHERE transition_type IS NULL;

-- Also classify based on computer_code pattern for CIFP entries
-- where transition_name might not follow the expected pattern
UPDATE nav_procedures
SET transition_type = 'runway'
WHERE transition_type IS NULL
  AND source IN ('CIFP', 'cifp_base')
  AND computer_code ~ '\.(RW\d|RW$)'
  AND (transition_name IS NOT NULL AND transition_name != '');

-- Index for filtering by transition type
CREATE INDEX IF NOT EXISTS IX_proc_transition_type
    ON nav_procedures (transition_type)
    WHERE transition_type IS NOT NULL;
