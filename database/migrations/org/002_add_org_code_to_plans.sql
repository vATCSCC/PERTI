-- ============================================================================
-- Migration: 002_add_org_code_to_plans.sql
-- Database:  perti_site (MySQL 8)
-- Purpose:   Add org_code column to all PERTI plan and review tables.
--            Existing rows default to 'vatcscc'.
-- ============================================================================

-- ----------------------------------------------------------------------------
-- Plans and configs
-- ----------------------------------------------------------------------------
ALTER TABLE p_plans
    ADD COLUMN org_code VARCHAR(16) NOT NULL DEFAULT 'vatcscc';

ALTER TABLE p_configs
    ADD COLUMN org_code VARCHAR(16) NOT NULL DEFAULT 'vatcscc';

-- ----------------------------------------------------------------------------
-- Staffing tables
-- ----------------------------------------------------------------------------
ALTER TABLE p_terminal_staffing
    ADD COLUMN org_code VARCHAR(16) NOT NULL DEFAULT 'vatcscc';

ALTER TABLE p_enroute_staffing
    ADD COLUMN org_code VARCHAR(16) NOT NULL DEFAULT 'vatcscc';

ALTER TABLE p_dcc_staffing
    ADD COLUMN org_code VARCHAR(16) NOT NULL DEFAULT 'vatcscc';

-- ----------------------------------------------------------------------------
-- Constraints
-- ----------------------------------------------------------------------------
ALTER TABLE p_terminal_constraints
    ADD COLUMN org_code VARCHAR(16) NOT NULL DEFAULT 'vatcscc';

ALTER TABLE p_enroute_constraints
    ADD COLUMN org_code VARCHAR(16) NOT NULL DEFAULT 'vatcscc';

-- ----------------------------------------------------------------------------
-- Initiatives
-- ----------------------------------------------------------------------------
ALTER TABLE p_terminal_init
    ADD COLUMN org_code VARCHAR(16) NOT NULL DEFAULT 'vatcscc';

ALTER TABLE p_enroute_init
    ADD COLUMN org_code VARCHAR(16) NOT NULL DEFAULT 'vatcscc';

-- ----------------------------------------------------------------------------
-- Initiative timelines
-- ----------------------------------------------------------------------------
ALTER TABLE p_terminal_init_timeline
    ADD COLUMN org_code VARCHAR(16) NOT NULL DEFAULT 'vatcscc';

ALTER TABLE p_enroute_init_timeline
    ADD COLUMN org_code VARCHAR(16) NOT NULL DEFAULT 'vatcscc';

-- ----------------------------------------------------------------------------
-- Initiative times
-- ----------------------------------------------------------------------------
ALTER TABLE p_terminal_init_times
    ADD COLUMN org_code VARCHAR(16) NOT NULL DEFAULT 'vatcscc';

ALTER TABLE p_enroute_init_times
    ADD COLUMN org_code VARCHAR(16) NOT NULL DEFAULT 'vatcscc';

-- ----------------------------------------------------------------------------
-- Planning
-- ----------------------------------------------------------------------------
ALTER TABLE p_terminal_planning
    ADD COLUMN org_code VARCHAR(16) NOT NULL DEFAULT 'vatcscc';

ALTER TABLE p_enroute_planning
    ADD COLUMN org_code VARCHAR(16) NOT NULL DEFAULT 'vatcscc';

-- ----------------------------------------------------------------------------
-- Goals, forecast, historical, group flights
-- ----------------------------------------------------------------------------
ALTER TABLE p_op_goals
    ADD COLUMN org_code VARCHAR(16) NOT NULL DEFAULT 'vatcscc';

ALTER TABLE p_forecast
    ADD COLUMN org_code VARCHAR(16) NOT NULL DEFAULT 'vatcscc';

ALTER TABLE p_historical
    ADD COLUMN org_code VARCHAR(16) NOT NULL DEFAULT 'vatcscc';

ALTER TABLE p_group_flights
    ADD COLUMN org_code VARCHAR(16) NOT NULL DEFAULT 'vatcscc';

-- ----------------------------------------------------------------------------
-- Review tables
-- ----------------------------------------------------------------------------
ALTER TABLE r_scores
    ADD COLUMN org_code VARCHAR(16) NOT NULL DEFAULT 'vatcscc';

ALTER TABLE r_comments
    ADD COLUMN org_code VARCHAR(16) NOT NULL DEFAULT 'vatcscc';

ALTER TABLE r_data
    ADD COLUMN org_code VARCHAR(16) NOT NULL DEFAULT 'vatcscc';

ALTER TABLE r_ops_data
    ADD COLUMN org_code VARCHAR(16) NOT NULL DEFAULT 'vatcscc';

-- ----------------------------------------------------------------------------
-- Assignments
-- ----------------------------------------------------------------------------
ALTER TABLE assigned
    ADD COLUMN org_code VARCHAR(16) NOT NULL DEFAULT 'vatcscc';

-- ----------------------------------------------------------------------------
-- Index on p_plans for org-scoped queries
-- ----------------------------------------------------------------------------
ALTER TABLE p_plans ADD INDEX idx_org_code (org_code);
