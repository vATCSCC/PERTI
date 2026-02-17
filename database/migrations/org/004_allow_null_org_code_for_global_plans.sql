-- Migration: Allow NULL org_code for global plans
-- Date: 2026-02-17
-- Purpose: Plans with org_code = NULL are "global" (visible to all orgs).
--          Previously org_code was NOT NULL DEFAULT 'vatcscc', preventing global plans.
-- Applied to: p_plans + all 22 child/related tables that have org_code.

-- Parent table
ALTER TABLE p_plans MODIFY COLUMN org_code VARCHAR(16) NULL DEFAULT NULL;

-- Child plan tables
ALTER TABLE p_configs MODIFY COLUMN org_code VARCHAR(16) NULL DEFAULT NULL;
ALTER TABLE p_dcc_staffing MODIFY COLUMN org_code VARCHAR(16) NULL DEFAULT NULL;
ALTER TABLE p_enroute_constraints MODIFY COLUMN org_code VARCHAR(16) NULL DEFAULT NULL;
ALTER TABLE p_enroute_init MODIFY COLUMN org_code VARCHAR(16) NULL DEFAULT NULL;
ALTER TABLE p_enroute_planning MODIFY COLUMN org_code VARCHAR(16) NULL DEFAULT NULL;
ALTER TABLE p_enroute_staffing MODIFY COLUMN org_code VARCHAR(16) NULL DEFAULT NULL;
ALTER TABLE p_forecast MODIFY COLUMN org_code VARCHAR(16) NULL DEFAULT NULL;
ALTER TABLE p_group_flights MODIFY COLUMN org_code VARCHAR(16) NULL DEFAULT NULL;
ALTER TABLE p_historical MODIFY COLUMN org_code VARCHAR(16) NULL DEFAULT NULL;
ALTER TABLE p_op_goals MODIFY COLUMN org_code VARCHAR(16) NULL DEFAULT NULL;
ALTER TABLE p_terminal_constraints MODIFY COLUMN org_code VARCHAR(16) NULL DEFAULT NULL;
ALTER TABLE p_terminal_init MODIFY COLUMN org_code VARCHAR(16) NULL DEFAULT NULL;
ALTER TABLE p_terminal_planning MODIFY COLUMN org_code VARCHAR(16) NULL DEFAULT NULL;
ALTER TABLE p_terminal_staffing MODIFY COLUMN org_code VARCHAR(16) NULL DEFAULT NULL;

-- Initiative timeline/times tables
ALTER TABLE p_terminal_init_timeline MODIFY COLUMN org_code VARCHAR(16) NULL DEFAULT NULL;
ALTER TABLE p_enroute_init_timeline MODIFY COLUMN org_code VARCHAR(16) NULL DEFAULT NULL;
ALTER TABLE p_terminal_init_times MODIFY COLUMN org_code VARCHAR(16) NULL DEFAULT NULL;
ALTER TABLE p_enroute_init_times MODIFY COLUMN org_code VARCHAR(16) NULL DEFAULT NULL;

-- Review tables
ALTER TABLE r_scores MODIFY COLUMN org_code VARCHAR(16) NULL DEFAULT NULL;
ALTER TABLE r_comments MODIFY COLUMN org_code VARCHAR(16) NULL DEFAULT NULL;
ALTER TABLE r_data MODIFY COLUMN org_code VARCHAR(16) NULL DEFAULT NULL;
ALTER TABLE r_ops_data MODIFY COLUMN org_code VARCHAR(16) NULL DEFAULT NULL;
