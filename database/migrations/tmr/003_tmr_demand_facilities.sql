-- TMR: Add demand snapshots and featured facilities
-- Database: perti_site (MySQL 8)
-- Depends on: 002_tmr_enhancements.sql

-- Demand chart snapshots: JSON blob of { airport: { state, data, rates } }
-- Saves the ADL demand data that was loaded during the event for recall after the event ends.
ALTER TABLE r_tmr_reports ADD COLUMN demand_snapshots JSON NULL AFTER airport_config_correct;

-- Featured facilities: comma-separated list of ARTCCs/TRACONs/airports (same as TMI Compliance)
-- Scopes the TMR report to specific facilities for filtering and context.
ALTER TABLE r_tmr_reports ADD COLUMN featured_facilities VARCHAR(500) NULL AFTER host_artcc;
