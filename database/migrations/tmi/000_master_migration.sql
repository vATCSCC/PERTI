-- ============================================================================
-- VATSIM_TMI Database Creation and Migration Master Script
-- ============================================================================
-- Version: 1.0.0
-- Date: 2026-01-18
-- Author: HP/Claude
--
-- This script creates the VATSIM_TMI database and runs all migrations.
-- Run this on the Azure SQL Server to set up the GDT system.
--
-- Prerequisites:
--   - Azure SQL Server instance
--   - Appropriate permissions to create database
--   - VATSIM_ADL database exists (for cross-database references)
--   - VATSIM_REF database exists (for reference data)
-- ============================================================================

-- ============================================================================
-- Step 1: Create Database (run as server admin)
-- ============================================================================
/*
-- Uncomment and run separately if database doesn't exist:

CREATE DATABASE VATSIM_TMI
COLLATE SQL_Latin1_General_CP1_CI_AS;
GO

-- For Azure SQL, you may need to set the service tier:
-- ALTER DATABASE VATSIM_TMI MODIFY (EDITION = 'Basic', SERVICE_OBJECTIVE = 'Basic');
*/

USE VATSIM_TMI;
GO

-- ============================================================================
-- Step 2: Run Migrations in Order
-- ============================================================================

PRINT '=== Starting VATSIM_TMI Database Migrations ===';
PRINT '';

-- Migration 001: Create tmi_programs table
PRINT 'Running Migration 001: tmi_programs...';
-- :r 001_create_tmi_programs.sql
GO

-- Migration 002: Create tmi_slots table
PRINT 'Running Migration 002: tmi_slots...';
-- :r 002_create_tmi_slots.sql
GO

-- Migration 003: Create tmi_flight_control table
PRINT 'Running Migration 003: tmi_flight_control...';
-- :r 003_create_tmi_flight_control.sql
GO

-- Migration 004: Create tmi_events and tmi_popup_queue tables
PRINT 'Running Migration 004: tmi_events & tmi_popup_queue...';
-- :r 004_create_tmi_events_and_popup.sql
GO

-- Migration 005: Create Views
PRINT 'Running Migration 005: Views...';
-- :r 005_create_views.sql
GO

-- Migration 006: Create Core Procedures
PRINT 'Running Migration 006: Core procedures...';
-- :r 006_create_core_procedures.sql
GO

-- Migration 007: Create RBS and Pop-up Procedures
PRINT 'Running Migration 007: RBS and pop-up procedures...';
-- :r 007_create_rbs_and_popup_procedures.sql
GO

-- Migration 008: Create GS and Transition Procedures
PRINT 'Running Migration 008: GS and transition procedures...';
-- :r 008_create_gs_and_transition_procedures.sql
GO

-- Migration 009: Create Compression and Retention Procedures
PRINT 'Running Migration 009: Compression and retention procedures...';
-- :r 009_create_compression_and_retention.sql
GO

PRINT '';
PRINT '=== VATSIM_TMI Database Migrations Complete ===';
GO

-- ============================================================================
-- Step 3: Create Cross-Database User (for VATSIM_ADL access)
-- ============================================================================

-- If using contained database users:
/*
CREATE USER [perti_tmi_user] WITH PASSWORD = 'your_password_here';
GRANT SELECT, INSERT, UPDATE, DELETE ON SCHEMA::dbo TO [perti_tmi_user];
GRANT EXECUTE ON SCHEMA::dbo TO [perti_tmi_user];
*/

-- ============================================================================
-- Step 4: Verify Installation
-- ============================================================================
PRINT 'Verifying installation...';
PRINT '';

SELECT 'Tables' AS ObjectType, name AS ObjectName, create_date
FROM sys.tables 
WHERE schema_id = SCHEMA_ID('dbo')
ORDER BY name;

SELECT 'Views' AS ObjectType, name AS ObjectName, create_date
FROM sys.views 
WHERE schema_id = SCHEMA_ID('dbo')
ORDER BY name;

SELECT 'Procedures' AS ObjectType, name AS ObjectName, create_date
FROM sys.procedures 
WHERE schema_id = SCHEMA_ID('dbo')
ORDER BY name;

SELECT 'User Types' AS ObjectType, name AS ObjectName, NULL as create_date
FROM sys.types 
WHERE is_user_defined = 1;

PRINT '';
PRINT 'Installation verification complete.';
GO
