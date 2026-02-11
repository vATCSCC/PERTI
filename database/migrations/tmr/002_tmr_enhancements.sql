-- TMR Enhancements: new columns for triggers, staffing, and ops plan
-- Database: perti_site (MySQL 8)
-- Depends on: 001_tmr_reports.sql

ALTER TABLE r_tmr_reports ADD COLUMN tmr_trigger_other_text VARCHAR(500) NULL AFTER tmr_triggers;
ALTER TABLE r_tmr_reports ADD COLUMN staffing_assessment JSON NULL AFTER personnel_details;
ALTER TABLE r_tmr_reports ADD COLUMN goals_assessment JSON NULL AFTER operational_plan_link;
