-- =============================================================================
-- VATSIM_GIS Database Creation & User Setup
-- Server: PostgreSQL 15+ with PostGIS 3.4+
-- Database: VATSIM_GIS
-- Version: 1.0
-- Date: 2026-01-29
-- =============================================================================
--
-- This script creates the VATSIM_GIS database and GIS_admin.
-- Run in two parts:
--   1. Part A: Run as superuser (postgres) on any database
--   2. Part B: Run as superuser (postgres) on VATSIM_GIS database
--
-- =============================================================================

-- =============================================================================
-- PART A: CREATE DATABASE AND LOGIN (Run as postgres on 'postgres' database)
-- =============================================================================
-- psql -U postgres -d postgres -f 000_create_database.sql

-- Create the database
CREATE DATABASE "VATSIM_GIS"
    WITH
    OWNER = postgres
    ENCODING = 'UTF8'
    LC_COLLATE = 'en_US.UTF-8'
    LC_CTYPE = 'en_US.UTF-8'
    TEMPLATE = template0
    CONNECTION LIMIT = -1;

-- Create the API user role (login)
-- Replace 'YourStrongPassword123!' with a secure password
CREATE ROLE GIS_admin WITH
    LOGIN
    NOSUPERUSER
    NOCREATEDB
    NOCREATEROLE
    INHERIT
    NOREPLICATION
    PASSWORD '<PASSWORD>';

-- =============================================================================
-- PART B: SETUP EXTENSIONS AND PERMISSIONS (Run on 'VATSIM_GIS' database)
-- =============================================================================
-- \c VATSIM_GIS
-- OR: psql -U postgres -d VATSIM_GIS -f 000_create_database.sql

-- Enable PostGIS extensions
CREATE EXTENSION IF NOT EXISTS postgis;
CREATE EXTENSION IF NOT EXISTS postgis_topology;

-- =============================================================================
-- GRANT PERMISSIONS
-- =============================================================================

-- Connect permission
GRANT CONNECT ON DATABASE "VATSIM_GIS" TO GIS_admin;

-- Schema usage
GRANT USAGE ON SCHEMA public TO GIS_admin;

-- Read access to all existing and future tables
GRANT SELECT ON ALL TABLES IN SCHEMA public TO GIS_admin;
ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT SELECT ON TABLES TO GIS_admin;

-- Execute access to all existing and future functions
GRANT EXECUTE ON ALL FUNCTIONS IN SCHEMA public TO GIS_admin;
ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT EXECUTE ON FUNCTIONS TO GIS_admin;

-- Sequence usage (for any serial columns if needed)
GRANT USAGE ON ALL SEQUENCES IN SCHEMA public TO GIS_admin;
ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT USAGE ON SEQUENCES TO GIS_admin;

-- =============================================================================
-- VERIFY SETUP
-- =============================================================================

-- Check PostGIS version
SELECT PostGIS_Full_Version();

-- Check user exists and permissions
SELECT
    r.rolname AS username,
    r.rolcanlogin AS can_login,
    r.rolcreatedb AS can_create_db,
    r.rolsuper AS is_superuser
FROM pg_roles r
WHERE r.rolname = 'GIS_admin';

-- Check database permissions
SELECT
    datname,
    datacl
FROM pg_database
WHERE datname = 'VATSIM_GIS';

-- =============================================================================
-- CONNECTION STRING EXAMPLES
-- =============================================================================

/*
PHP PDO:
    $dsn = "pgsql:host=your-server;port=5432;dbname=VATSIM_GIS";
    $pdo = new PDO($dsn, 'GIS_admin', 'YourStrongPassword123!');

Environment variables:
    GIS_SQL_HOST=your-server
    GIS_SQL_PORT=5432
    GIS_SQL_DATABASE=VATSIM_GIS
    GIS_SQL_USERNAME=GIS_admin
    GIS_SQL_PASSWORD=YourStrongPassword123!

psql:
    psql -h your-server -p 5432 -U GIS_admin -d VATSIM_GIS
*/

-- =============================================================================
-- END DATABASE CREATION
-- =============================================================================
