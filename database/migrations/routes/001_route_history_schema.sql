-- ============================================================================
-- perti_site (MySQL) Migration 001: Historical Route Data — Star Schema
-- ============================================================================
-- Version: 1.0.0
-- Date: 2026-03-23
-- Author: HP/Claude
--
-- Tables:
--   1. dim_route                - Unique normalized route skeletons
--   2. dim_aircraft_type        - Aircraft types enriched from ACD_Data
--   3. dim_operator             - Airlines and operator groups
--   4. dim_time                 - Date/hour dimension
--   5. route_history_facts      - Partitioned fact table (one row per flight)
--   6. route_history_sync_state - Aggregation job tracking (singleton)
--   7. route_operator_groups    - Operator classification reference
-- ============================================================================

-- 1. dim_route
CREATE TABLE IF NOT EXISTS `dim_route` (
    `route_dim_id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `normalized_route` TEXT NOT NULL,
    `route_hash` BINARY(16) NOT NULL,
    `sample_raw_route` TEXT NULL,
    `waypoint_count` SMALLINT UNSIGNED NULL,
    `first_seen` DATE NOT NULL,
    `last_seen` DATE NOT NULL,
    `row_updated_utc` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `ux_route_hash` (`route_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. dim_aircraft_type
CREATE TABLE IF NOT EXISTS `dim_aircraft_type` (
    `aircraft_dim_id` SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `icao_code` VARCHAR(8) NOT NULL,
    `faa_designator` VARCHAR(12) NULL,
    `manufacturer` VARCHAR(100) NULL,
    `model` VARCHAR(100) NULL,
    `weight_class` CHAR(1) NULL COMMENT 'S/L/H/J',
    `wake_category` CHAR(1) NULL COMMENT 'L/M/H/J',
    `faa_weight` VARCHAR(8) NULL COMMENT 'Small/Small+/Large/Heavy/Super',
    `icao_wtc` VARCHAR(16) NULL COMMENT 'Light/Light-Medium/Medium/Heavy/Super',
    `engine_type` CHAR(1) NULL COMMENT 'J/T/P/E',
    `engine_count` TINYINT UNSIGNED NULL,
    `aac` VARCHAR(4) NULL COMMENT 'Aircraft Approach Category',
    `row_updated_utc` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `ux_icao_code` (`icao_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. dim_operator
CREATE TABLE IF NOT EXISTS `dim_operator` (
    `operator_dim_id` SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `airline_icao` VARCHAR(8) NOT NULL,
    `airline_name` VARCHAR(100) NULL,
    `callsign_prefix` VARCHAR(8) NULL,
    `operator_group` VARCHAR(50) NULL DEFAULT 'other',
    `row_updated_utc` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `ux_airline_icao` (`airline_icao`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. dim_time
CREATE TABLE IF NOT EXISTS `dim_time` (
    `time_dim_id` INT UNSIGNED NOT NULL PRIMARY KEY COMMENT 'YYYYMMDDHH composite',
    `flight_date` DATE NOT NULL,
    `year_val` SMALLINT UNSIGNED NOT NULL,
    `month_val` TINYINT UNSIGNED NOT NULL COMMENT '1-12',
    `day_of_week` TINYINT UNSIGNED NOT NULL COMMENT '1=Mon..7=Sun ISO 8601',
    `hour_utc` TINYINT UNSIGNED NOT NULL COMMENT '0-23',
    `season` VARCHAR(8) NOT NULL COMMENT 'winter/spring/summer/fall',
    `is_weekend` TINYINT(1) NOT NULL DEFAULT 0,
    INDEX `ix_year_month` (`year_val`, `month_val`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. route_history_facts (partitioned)
CREATE TABLE IF NOT EXISTS `route_history_facts` (
    `fact_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `flight_uid` BIGINT UNSIGNED NOT NULL,
    `route_dim_id` INT UNSIGNED NOT NULL,
    `aircraft_dim_id` SMALLINT UNSIGNED NULL,
    `operator_dim_id` SMALLINT UNSIGNED NULL,
    `time_dim_id` INT UNSIGNED NOT NULL,
    `origin_icao` CHAR(4) NOT NULL,
    `dest_icao` CHAR(4) NOT NULL,
    `origin_tracon` VARCHAR(8) NULL,
    `origin_artcc` VARCHAR(8) NULL,
    `dest_tracon` VARCHAR(8) NULL,
    `dest_artcc` VARCHAR(8) NULL,
    `raw_route` TEXT NULL,
    `gcd_nm` DECIMAL(8,2) NULL,
    `ete_minutes` SMALLINT UNSIGNED NULL,
    `altitude_ft` MEDIUMINT UNSIGNED NULL,
    `partition_month` MEDIUMINT UNSIGNED NOT NULL COMMENT 'YYYYMM',
    PRIMARY KEY (`fact_id`, `partition_month`),
    UNIQUE KEY `ux_flight_uid` (`flight_uid`, `partition_month`),
    INDEX `ix_origin_dest` (`origin_icao`, `dest_icao`),
    INDEX `ix_origin_artcc` (`origin_artcc`),
    INDEX `ix_dest_artcc` (`dest_artcc`),
    INDEX `ix_origin_tracon` (`origin_tracon`),
    INDEX `ix_dest_tracon` (`dest_tracon`),
    INDEX `ix_route_dim` (`route_dim_id`),
    INDEX `ix_aircraft_dim` (`aircraft_dim_id`),
    INDEX `ix_operator_dim` (`operator_dim_id`),
    INDEX `ix_time_dim` (`time_dim_id`),
    INDEX `ix_city_pair_covering` (`origin_icao`, `dest_icao`, `route_dim_id`, `partition_month`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
PARTITION BY RANGE (`partition_month`) (
    PARTITION p202512 VALUES LESS THAN (202601),
    PARTITION p202601 VALUES LESS THAN (202602),
    PARTITION p202602 VALUES LESS THAN (202603),
    PARTITION p202603 VALUES LESS THAN (202604),
    PARTITION p202604 VALUES LESS THAN (202605),
    PARTITION p_future VALUES LESS THAN MAXVALUE
);

-- 6. route_history_sync_state (singleton)
CREATE TABLE IF NOT EXISTS `route_history_sync_state` (
    `id` INT UNSIGNED NOT NULL PRIMARY KEY DEFAULT 1,
    `last_flight_uid` BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `last_run_utc` DATETIME NULL,
    `rows_inserted` INT UNSIGNED NOT NULL DEFAULT 0,
    `status` VARCHAR(20) NOT NULL DEFAULT 'idle',
    CHECK (`id` = 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `route_history_sync_state` (`id`, `last_flight_uid`, `rows_inserted`, `status`)
VALUES (1, 0, 0, 'idle');

-- 7. route_operator_groups (reference/seed)
CREATE TABLE IF NOT EXISTS `route_operator_groups` (
    `airline_icao` VARCHAR(8) NOT NULL PRIMARY KEY,
    `operator_group` VARCHAR(50) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed top ~60 airlines on VATSIM
INSERT IGNORE INTO `route_operator_groups` (`airline_icao`, `operator_group`) VALUES
-- Legacy Carriers
('AAL', 'legacy_carrier'), ('DAL', 'legacy_carrier'), ('UAL', 'legacy_carrier'),
('BAW', 'legacy_carrier'), ('DLH', 'legacy_carrier'), ('AFR', 'legacy_carrier'),
('KLM', 'legacy_carrier'), ('SAS', 'legacy_carrier'), ('ACA', 'legacy_carrier'),
('QFA', 'legacy_carrier'), ('SIA', 'legacy_carrier'), ('CPA', 'legacy_carrier'),
('JAL', 'legacy_carrier'), ('ANA', 'legacy_carrier'), ('THY', 'legacy_carrier'),
('ETH', 'legacy_carrier'), ('UAE', 'legacy_carrier'), ('QTR', 'legacy_carrier'),
('IBE', 'legacy_carrier'), ('TAP', 'legacy_carrier'), ('AZA', 'legacy_carrier'),
('SWR', 'legacy_carrier'), ('AUA', 'legacy_carrier'), ('LOT', 'legacy_carrier'),
-- Low Cost Carriers
('SWA', 'lcc'), ('RYR', 'lcc'), ('EZY', 'lcc'), ('WZZ', 'lcc'),
('JBU', 'lcc'), ('NKS', 'lcc'), ('FFT', 'lcc'), ('VIR', 'lcc'),
('WJA', 'lcc'),
-- Business Aviation
('EJA', 'bizjet'), ('NJT', 'bizjet'),
-- Regional
('SKW', 'regional'), ('RPA', 'regional'), ('ENY', 'regional'),
('JIA', 'regional'), ('PDT', 'regional'), ('ASH', 'regional'),
('CJC', 'regional'), ('BEE', 'regional'),
-- Cargo
('FDX', 'cargo'), ('UPS', 'cargo'), ('GTI', 'cargo'),
('ABW', 'cargo'), ('CLX', 'cargo'), ('BOX', 'cargo'),
('MPH', 'cargo'), ('ADB', 'cargo'),
-- Charter
('TOM', 'charter'), ('TCX', 'charter'),
-- Military
('RCH', 'military'), ('AIO', 'military'), ('RRR', 'military');
