-- ============================================================================
-- perti_site (MySQL) Migration 002: Add flight detail columns to facts table
-- ============================================================================
-- Version: 1.0.0
-- Date: 2026-03-26
-- Author: HP/Claude
--
-- Adds callsign, flight_number (normalized), departure/arrival fix,
-- and SID/STAR procedure names to route_history_facts.
-- These columns enable callsign frequency analysis, fix distribution
-- charts, and procedure usage breakdowns in the route info dialog.
-- ============================================================================

-- 1. Add columns
ALTER TABLE `route_history_facts`
    ADD COLUMN `callsign` VARCHAR(12) NULL AFTER `operator_dim_id`,
    ADD COLUMN `flight_number` VARCHAR(12) NULL AFTER `callsign`,
    ADD COLUMN `dfix` VARCHAR(8) NULL AFTER `flight_number`,
    ADD COLUMN `afix` VARCHAR(8) NULL AFTER `dfix`,
    ADD COLUMN `dp_name` VARCHAR(16) NULL AFTER `afix`,
    ADD COLUMN `star_name` VARCHAR(16) NULL AFTER `dp_name`;

-- 2. Indexes for grouping/filtering
--    Partitioned tables require partition_month in every index
ALTER TABLE `route_history_facts`
    ADD INDEX `ix_callsign` (`callsign`, `partition_month`),
    ADD INDEX `ix_flight_number` (`flight_number`, `partition_month`),
    ADD INDEX `ix_dfix` (`dfix`, `partition_month`),
    ADD INDEX `ix_afix` (`afix`, `partition_month`);
