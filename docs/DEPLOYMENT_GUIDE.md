# PERTI Deployment Guide

Complete step-by-step guide for deploying your own PERTI (Plan, Execute, Review, Train, Improve) instance — a web-based air traffic flow management platform for the VATSIM virtual ATC network.

**Target audience**: Technical administrators setting up a new PERTI instance from the GitHub repository.

**Estimated time**: 4-8 hours for a complete deployment (longer for first-time Azure users).

---

## Table of Contents

1. [Prerequisites](#1-prerequisites)
2. [Azure Resource Provisioning](#2-azure-resource-provisioning)
3. [Database Creation](#3-database-creation)
4. [Database Schema Deployment](#4-database-schema-deployment)
5. [Reference Data Import](#5-reference-data-import)
6. [Application Configuration](#6-application-configuration)
7. [VATSIM OAuth Setup](#7-vatsim-oauth-setup)
8. [Discord Integration](#8-discord-integration)
9. [Azure App Service Deployment](#9-azure-app-service-deployment)
10. [CI/CD Pipeline Setup](#10-cicd-pipeline-setup)
11. [Background Daemons](#11-background-daemons)
12. [Post-Deployment Verification](#12-post-deployment-verification)
13. [Optional Components](#13-optional-components)
14. [Troubleshooting](#14-troubleshooting)
15. [Architecture Reference](#15-architecture-reference)

---

## 1. Prerequisites

### Accounts Required

| Account | Purpose | URL |
|---------|---------|-----|
| **Microsoft Azure** | Hosting (App Service, SQL, PostgreSQL) | https://portal.azure.com |
| **GitHub** | Source code repository, CI/CD | https://github.com |
| **VATSIM Connect** | User authentication (OAuth 2.0) | https://auth.vatsim.net |
| **Discord** | TMI publishing & coordination bot | https://discord.com/developers |

### Local Development Tools

- **PHP 8.2+** with extensions: `pdo`, `mysqli`, `sqlsrv`, `pdo_pgsql`, `openssl`, `curl`, `mbstring`, `json`
- **Composer** (PHP dependency manager)
- **Node.js 18+** (for Discord bot)
- **Python 3.x** (for navdata imports, wind data, analysis scripts)
- **Git**
- **Azure CLI** (`az`) — optional but recommended

### Estimated Azure Costs (Monthly)

Queried directly from the live vATCSCC Azure subscription (Resource Group `VATSIM_RG`, March 2026). These are the actual production tiers operating at 3,000-6,000 concurrent flights:

| Resource | Actual SKU | Configuration | Est. Monthly Cost |
|----------|-----------|---------------|-------------------|
| **App Service** | P1v2 (PremiumV2) | 1 vCPU, 3.5GB RAM, 1 worker | ~$80 |
| **VATSIM_ADL** | Hyperscale Serverless Gen5 | Min 3 / Max 16 vCores, auto-pause disabled | ~$1,000-2,500 |
| **SWIM_API** | Basic (5 DTU) | 2GB max size | ~$4.90 |
| **VATSIM_TMI** | Basic (5 DTU) | 2GB max size | ~$4.90 |
| **VATSIM_REF** | Basic (5 DTU) | 2GB max size | ~$4.90 |
| **VATSIM_STATS** | GP Serverless Gen5 | Min 0.5 / Max 1 vCore, auto-pauses after 60min | ~$5-150 |
| **MySQL perti_site** | Standard_D2ds_v4 (GP) | 2 vCores, 20GB storage, 360 IOPS | ~$122 |
| **PostgreSQL VATSIM_GIS** | Standard_B2s (Burstable) | 2 vCores, 32GB storage, PostgreSQL 16 | ~$25 |
| **Storage accounts** | LRS/RAGRS/ZRS (6 accounts) | pertiadlarchive, pertisyndatalake, vatcsccadlraw, vatsimadlarchive, vatsimdatastorage, vatsimswimdata | ~$5-15 |
| **Data Factory** | vatsim-adl-history | Historical data pipelines | ~$0-10 |
| **Logic App** | stats-loader-scheduler | Scheduler (consumption plan) | ~$0-1 |
| **Synapse Analytics** | perti-synapse (serverless SQL) | On-demand querying of archived data | ~$0-5 |
| **Total (production)** | | | **~$1,250-2,900/mo** |

> **VATSIM_ADL dominates cost** (~75-85% at scale). The 15-second ingest cycle with 8-table MERGEs and concurrent daemon queries creates sustained compute demand that requires vCore-based serverless — DTU tiers (S0-S3) throttle and cause missed VATSIM feeds. Organizations with lower traffic or event-only operations can use significantly cheaper tiers (GP Serverless with auto-pause, ~$150-400/mo). See `COMPUTATIONAL_REFERENCE.md` Section 15 for detailed scaling guidance by flight volume.

---

## 2. Azure Resource Provisioning

### 2.1 Create a Resource Group

All PERTI resources should be in one resource group for easy management.

```bash
az group create --name perti-rg --location eastus
```

Choose a region close to most of your user base. VATSIM traffic is global but US-centric for vATCSCC.

### 2.2 Create Azure SQL Server

This single logical server hosts 5 databases: VATSIM_ADL, VATSIM_TMI, VATSIM_REF, SWIM_API, VATSIM_STATS.

```bash
az sql server create \
  --name your-perti-sql \
  --resource-group perti-rg \
  --location eastus \
  --admin-user your_admin_user \
  --admin-password 'YourStrongPassword123!'
```

**Configure firewall rules** to allow Azure services and your IP:

```bash
# Allow Azure services
az sql server firewall-rule create \
  --server your-perti-sql \
  --resource-group perti-rg \
  --name AllowAzureServices \
  --start-ip-address 0.0.0.0 \
  --end-ip-address 0.0.0.0

# Allow your IP for management
az sql server firewall-rule create \
  --server your-perti-sql \
  --resource-group perti-rg \
  --name AllowMyIP \
  --start-ip-address YOUR.IP.HERE \
  --end-ip-address YOUR.IP.HERE
```

### 2.3 Create Azure SQL Databases

Create each of the 5 Azure SQL databases. Choose SKU based on expected load:

```bash
# VATSIM_ADL - Flight data (highest load — use GP Serverless vCore, NOT DTU tiers)
# DTU tiers (S0-S3) cause throttling during the 15-second ingest cycle.
# Min/max vCores control cost: lower min = cheaper idle, higher max = handles peaks.
az sql db create --server your-perti-sql --resource-group perti-rg \
  --name VATSIM_ADL --edition GeneralPurpose --compute-model Serverless \
  --family Gen5 --capacity 4 --min-capacity 0.5 --auto-pause-delay 60

# VATSIM_TMI - Traffic management
az sql db create --server your-perti-sql --resource-group perti-rg \
  --name VATSIM_TMI --service-objective Basic

# VATSIM_REF - Reference data (low traffic)
az sql db create --server your-perti-sql --resource-group perti-rg \
  --name VATSIM_REF --service-objective Basic

# SWIM_API - Public API database
az sql db create --server your-perti-sql --resource-group perti-rg \
  --name SWIM_API --service-objective Basic

# VATSIM_STATS - Statistics (can use free tier)
az sql db create --server your-perti-sql --resource-group perti-rg \
  --name VATSIM_STATS --service-objective Free
```

> **Note**: The `Free` tier auto-pauses after 1 hour of inactivity. Use `Basic` ($5/mo) if you need always-on stats.

### 2.4 Create MySQL Flexible Server

MySQL hosts the primary web application database (`perti_site`).

```bash
az mysql flexible-server create \
  --name your-perti-mysql \
  --resource-group perti-rg \
  --location eastus \
  --admin-user your_mysql_admin \
  --admin-password 'YourMySQLPassword123!' \
  --sku-name Standard_B1ms \
  --storage-size 32 \
  --version 8.0
```

Create the database:

```bash
az mysql flexible-server db create \
  --server-name your-perti-mysql \
  --resource-group perti-rg \
  --database-name perti_site
```

Enable public access and add firewall rules:

```bash
az mysql flexible-server firewall-rule create \
  --name your-perti-mysql \
  --resource-group perti-rg \
  --rule-name AllowAzure \
  --start-ip-address 0.0.0.0 \
  --end-ip-address 0.0.0.0
```

### 2.5 Create PostgreSQL Flexible Server (PostGIS)

PostgreSQL with PostGIS handles all spatial/geographic queries.

```bash
az postgres flexible-server create \
  --name your-perti-gis \
  --resource-group perti-rg \
  --location eastus \
  --admin-user gis_admin \
  --admin-password 'YourPostgresPassword123!' \
  --sku-name Standard_B1ms \
  --storage-size 32 \
  --version 16
```

Create the database and enable PostGIS:

```bash
az postgres flexible-server db create \
  --server-name your-perti-gis \
  --resource-group perti-rg \
  --database-name VATSIM_GIS

# Connect via psql and enable PostGIS
psql "host=your-perti-gis.postgres.database.azure.com dbname=VATSIM_GIS user=gis_admin" -c "CREATE EXTENSION IF NOT EXISTS postgis;"
```

### 2.6 Create Azure App Service

```bash
# Create App Service Plan (Linux)
az appservice plan create \
  --name perti-plan \
  --resource-group perti-rg \
  --sku P1V2 \
  --is-linux

# Create Web App with PHP 8.2
az webapp create \
  --name your-perti-app \
  --resource-group perti-rg \
  --plan perti-plan \
  --runtime "PHP|8.2"
```

**Configure the startup command**:

```bash
az webapp config set \
  --name your-perti-app \
  --resource-group perti-rg \
  --startup-file "/home/site/wwwroot/scripts/startup.sh"
```

**Configure health check** (prevents restart loops):

```bash
az webapp config set \
  --name your-perti-app \
  --resource-group perti-rg \
  --generic-configurations '{"healthCheckPath":"/healthcheck.php"}'
```

**Set timezone and PHP settings**:

```bash
az webapp config appsettings set \
  --name your-perti-app \
  --resource-group perti-rg \
  --settings \
    WEBSITE_TIME_ZONE="UTC" \
    PHP_INI_SCAN_DIR="/usr/local/etc/php/conf.d:/home/site/ini"
```

---

## 3. Database Creation

### 3.1 MySQL — `perti_site` Schema

Connect to your MySQL server and create the core website tables. These store PERTI plans, users, staffing, constraints, and review data.

```sql
-- =====================================================
-- perti_site MySQL Database Schema
-- Run against: your-perti-mysql.mysql.database.azure.com
-- Database: perti_site
-- =====================================================

-- Users table (VATSIM-authenticated staff)
CREATE TABLE IF NOT EXISTS `users` (
  `id` int NOT NULL AUTO_INCREMENT,
  `cid` int NOT NULL,
  `first_name` varchar(100) DEFAULT NULL,
  `last_name` varchar(100) DEFAULT NULL,
  `last_session_ip` varchar(45) DEFAULT NULL,
  `last_selfcookie` varchar(255) DEFAULT NULL,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `cid` (`cid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Admin users (elevated privileges)
CREATE TABLE IF NOT EXISTS `admin_users` (
  `id` int NOT NULL AUTO_INCREMENT,
  `cid` int NOT NULL,
  `first_name` varchar(100) DEFAULT NULL,
  `last_name` varchar(100) DEFAULT NULL,
  `last_session_ip` varchar(45) DEFAULT NULL,
  `last_selfcookie` varchar(255) DEFAULT NULL,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `cid` (`cid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- SWIM-only users (self-service API key management)
CREATE TABLE IF NOT EXISTS `swim_users` (
  `id` int NOT NULL AUTO_INCREMENT,
  `cid` int NOT NULL,
  `first_name` varchar(100) DEFAULT NULL,
  `last_name` varchar(100) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `vatsim_rating` varchar(10) DEFAULT NULL,
  `vatsim_division` varchar(10) DEFAULT NULL,
  `last_login_at` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `cid` (`cid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- User organization assignments (multi-org support)
CREATE TABLE IF NOT EXISTS `user_orgs` (
  `id` int NOT NULL AUTO_INCREMENT,
  `cid` int NOT NULL,
  `org_code` varchar(20) NOT NULL,
  `is_privileged` tinyint(1) DEFAULT 0,
  `is_primary` tinyint(1) DEFAULT 0,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_cid` (`cid`),
  KEY `idx_org` (`org_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- PERTI event plans
CREATE TABLE IF NOT EXISTS `p_plans` (
  `id` int NOT NULL AUTO_INCREMENT,
  `event_name` varchar(255) DEFAULT NULL,
  `event_date` date DEFAULT NULL,
  `event_start` time DEFAULT NULL,
  `event_banner` varchar(500) DEFAULT NULL,
  `oplevel` int DEFAULT 1,
  `hotline` varchar(255) DEFAULT NULL,
  `event_end_date` date DEFAULT NULL,
  `event_end_time` time DEFAULT NULL,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Airport configs per plan
CREATE TABLE IF NOT EXISTS `p_configs` (
  `id` int NOT NULL AUTO_INCREMENT,
  `p_id` int NOT NULL,
  `airport` varchar(10) DEFAULT NULL,
  `weather` int DEFAULT NULL,
  `arrive` varchar(100) DEFAULT NULL,
  `depart` varchar(100) DEFAULT NULL,
  `aar` int DEFAULT NULL,
  `adr` int DEFAULT NULL,
  `comments` text,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_plan` (`p_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Default airport rate configs (global reference)
CREATE TABLE IF NOT EXISTS `config_data` (
  `id` int NOT NULL AUTO_INCREMENT,
  `airport` varchar(10) DEFAULT NULL,
  `arr` varchar(100) DEFAULT NULL,
  `dep` varchar(100) DEFAULT NULL,
  `vmc_aar` int DEFAULT NULL,
  `lvmc_aar` int DEFAULT NULL,
  `imc_aar` int DEFAULT NULL,
  `limc_aar` int DEFAULT NULL,
  `vmc_adr` int DEFAULT NULL,
  `imc_adr` int DEFAULT NULL,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `airport` (`airport`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Terminal staffing per plan
CREATE TABLE IF NOT EXISTS `p_terminal_staffing` (
  `id` int NOT NULL AUTO_INCREMENT,
  `p_id` int NOT NULL,
  `facility_name` varchar(100) DEFAULT NULL,
  `staffing_status` int DEFAULT NULL,
  `staffing_quantity` int DEFAULT NULL,
  `comments` text,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_plan` (`p_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Enroute staffing per plan
CREATE TABLE IF NOT EXISTS `p_enroute_staffing` (
  `id` int NOT NULL AUTO_INCREMENT,
  `p_id` int NOT NULL,
  `facility_name` varchar(100) DEFAULT NULL,
  `staffing_status` int DEFAULT NULL,
  `staffing_quantity` int DEFAULT NULL,
  `comments` text,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_plan` (`p_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- DCC staffing per plan
CREATE TABLE IF NOT EXISTS `p_dcc_staffing` (
  `id` int NOT NULL AUTO_INCREMENT,
  `p_id` int NOT NULL,
  `position_name` varchar(100) DEFAULT NULL,
  `position_facility` varchar(100) DEFAULT NULL,
  `personnel_name` varchar(200) DEFAULT NULL,
  `personnel_ois` varchar(100) DEFAULT NULL,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_plan` (`p_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Terminal constraints per plan
CREATE TABLE IF NOT EXISTS `p_terminal_constraints` (
  `id` int NOT NULL AUTO_INCREMENT,
  `p_id` int NOT NULL,
  `location` varchar(100) DEFAULT NULL,
  `context` text,
  `date` varchar(100) DEFAULT NULL,
  `impact` text,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_plan` (`p_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Enroute constraints per plan
CREATE TABLE IF NOT EXISTS `p_enroute_constraints` (
  `id` int NOT NULL AUTO_INCREMENT,
  `p_id` int NOT NULL,
  `location` varchar(100) DEFAULT NULL,
  `context` text,
  `date` varchar(100) DEFAULT NULL,
  `impact` text,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_plan` (`p_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Terminal initiatives
CREATE TABLE IF NOT EXISTS `p_terminal_init` (
  `id` int NOT NULL AUTO_INCREMENT,
  `p_id` int NOT NULL,
  `title` varchar(255) DEFAULT NULL,
  `context` text,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_plan` (`p_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Enroute initiatives
CREATE TABLE IF NOT EXISTS `p_enroute_init` (
  `id` int NOT NULL AUTO_INCREMENT,
  `p_id` int NOT NULL,
  `title` varchar(255) DEFAULT NULL,
  `context` text,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_plan` (`p_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Terminal initiative timelines
CREATE TABLE IF NOT EXISTS `p_terminal_init_timeline` (
  `id` int NOT NULL AUTO_INCREMENT,
  `p_id` int NOT NULL,
  `facility` varchar(50) DEFAULT NULL,
  `area` varchar(100) DEFAULT NULL,
  `tmi_type` varchar(50) DEFAULT NULL,
  `tmi_type_other` varchar(100) DEFAULT NULL,
  `cause` varchar(255) DEFAULT NULL,
  `start_datetime` datetime DEFAULT NULL,
  `end_datetime` datetime DEFAULT NULL,
  `level` varchar(20) DEFAULT NULL,
  `notes` text,
  `is_global` tinyint(1) DEFAULT 0,
  `advzy_number` varchar(50) DEFAULT NULL,
  `created_by` int DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_plan` (`p_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Enroute initiative timelines
CREATE TABLE IF NOT EXISTS `p_enroute_init_timeline` (
  `id` int NOT NULL AUTO_INCREMENT,
  `p_id` int NOT NULL,
  `facility` varchar(50) DEFAULT NULL,
  `area` varchar(100) DEFAULT NULL,
  `tmi_type` varchar(50) DEFAULT NULL,
  `tmi_type_other` varchar(100) DEFAULT NULL,
  `cause` varchar(255) DEFAULT NULL,
  `start_datetime` datetime DEFAULT NULL,
  `end_datetime` datetime DEFAULT NULL,
  `level` varchar(20) DEFAULT NULL,
  `notes` text,
  `is_global` tinyint(1) DEFAULT 0,
  `advzy_number` varchar(50) DEFAULT NULL,
  `created_by` int DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_plan` (`p_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Terminal initiative time entries
CREATE TABLE IF NOT EXISTS `p_terminal_init_times` (
  `id` int NOT NULL AUTO_INCREMENT,
  `init_id` int NOT NULL,
  `time` varchar(50) DEFAULT NULL,
  `probability` int DEFAULT NULL,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_init` (`init_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Enroute initiative time entries
CREATE TABLE IF NOT EXISTS `p_enroute_init_times` (
  `id` int NOT NULL AUTO_INCREMENT,
  `init_id` int NOT NULL,
  `time` varchar(50) DEFAULT NULL,
  `probability` int DEFAULT NULL,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_init` (`init_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Terminal planning comments
CREATE TABLE IF NOT EXISTS `p_terminal_planning` (
  `id` int NOT NULL AUTO_INCREMENT,
  `p_id` int NOT NULL,
  `facility_name` varchar(100) DEFAULT NULL,
  `comments` text,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_plan` (`p_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Enroute planning comments
CREATE TABLE IF NOT EXISTS `p_enroute_planning` (
  `id` int NOT NULL AUTO_INCREMENT,
  `p_id` int NOT NULL,
  `facility_name` varchar(100) DEFAULT NULL,
  `comments` text,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_plan` (`p_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Planning goals
CREATE TABLE IF NOT EXISTS `p_op_goals` (
  `id` int NOT NULL AUTO_INCREMENT,
  `p_id` int NOT NULL,
  `comments` text,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_plan` (`p_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Demand forecasts
CREATE TABLE IF NOT EXISTS `p_forecast` (
  `id` int NOT NULL AUTO_INCREMENT,
  `p_id` int NOT NULL,
  `date` date DEFAULT NULL,
  `summary` text,
  `image_url` varchar(500) DEFAULT NULL,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_plan` (`p_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Historical reference data
CREATE TABLE IF NOT EXISTS `p_historical` (
  `id` int NOT NULL AUTO_INCREMENT,
  `p_id` int NOT NULL,
  `title` varchar(255) DEFAULT NULL,
  `date` date DEFAULT NULL,
  `summary` text,
  `image_url` varchar(500) DEFAULT NULL,
  `source_url` varchar(500) DEFAULT NULL,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_plan` (`p_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Group flight entries
CREATE TABLE IF NOT EXISTS `p_group_flights` (
  `id` int NOT NULL AUTO_INCREMENT,
  `p_id` int NOT NULL,
  `entity` varchar(100) DEFAULT NULL,
  `dep` varchar(10) DEFAULT NULL,
  `arr` varchar(10) DEFAULT NULL,
  `etd` varchar(50) DEFAULT NULL,
  `eta` varchar(50) DEFAULT NULL,
  `pilot_quantity` int DEFAULT NULL,
  `route` text,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_plan` (`p_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Review scores
CREATE TABLE IF NOT EXISTS `r_scores` (
  `id` int NOT NULL AUTO_INCREMENT,
  `p_id` int NOT NULL,
  `category` varchar(100) DEFAULT NULL,
  `score` int DEFAULT NULL,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_plan` (`p_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Review comments
CREATE TABLE IF NOT EXISTS `r_comments` (
  `id` int NOT NULL AUTO_INCREMENT,
  `p_id` int NOT NULL,
  `author_cid` int DEFAULT NULL,
  `comment` text,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_plan` (`p_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Review data
CREATE TABLE IF NOT EXISTS `r_data` (
  `id` int NOT NULL AUTO_INCREMENT,
  `p_id` int NOT NULL,
  `field_name` varchar(100) DEFAULT NULL,
  `field_value` text,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_plan` (`p_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Review ops data
CREATE TABLE IF NOT EXISTS `r_ops_data` (
  `id` int NOT NULL AUTO_INCREMENT,
  `p_id` int NOT NULL,
  `field_name` varchar(100) DEFAULT NULL,
  `field_value` text,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_plan` (`p_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- PERTI role assignments
CREATE TABLE IF NOT EXISTS `assigned` (
  `id` int NOT NULL AUTO_INCREMENT,
  `e_id` int DEFAULT NULL,
  `e_title` varchar(255) DEFAULT NULL,
  `e_date` date DEFAULT NULL,
  `p_cid` int DEFAULT NULL,
  `e_cid` int DEFAULT NULL,
  `r_cid` int DEFAULT NULL,
  `t_cid` int DEFAULT NULL,
  `i_cid` int DEFAULT NULL,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- CDR routes (MySQL copy for web display)
CREATE TABLE IF NOT EXISTS `route_cdr` (
  `fid` int NOT NULL AUTO_INCREMENT,
  `cdr_id` varchar(50) DEFAULT NULL,
  `cdr_code` varchar(10) DEFAULT NULL,
  `rte_orig` varchar(10) DEFAULT NULL,
  `rte_dest` varchar(10) DEFAULT NULL,
  `rte_dep_fix` varchar(20) DEFAULT NULL,
  `rte_string` text,
  `rte_dep_artcc` varchar(10) DEFAULT NULL,
  `rte_arr_artcc` varchar(10) DEFAULT NULL,
  `rte_t_artcc` varchar(50) DEFAULT NULL,
  `rte_coord_rqd` varchar(10) DEFAULT NULL,
  `pb_name` varchar(100) DEFAULT NULL,
  `rte_nav_eqpt` varchar(20) DEFAULT NULL,
  `rte_string_perti` text,
  PRIMARY KEY (`fid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Playbook routes (MySQL copy for web display)
CREATE TABLE IF NOT EXISTS `route_playbook` (
  `fid` int NOT NULL AUTO_INCREMENT,
  `pb_id` varchar(50) DEFAULT NULL,
  `pb_name` varchar(100) DEFAULT NULL,
  `pb_category` varchar(100) DEFAULT NULL,
  `pb_route_advisory` text,
  `pb_route_advisory_fca` text,
  PRIMARY KEY (`fid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Seed your first admin user (replace CID with your VATSIM CID)
-- INSERT INTO users (cid, first_name, last_name) VALUES (YOUR_CID, 'Your', 'Name');
-- INSERT INTO admin_users (cid, first_name, last_name) VALUES (YOUR_CID, 'Your', 'Name');
```

> **Important**: After creating the schema, insert at least one row into `users` and `admin_users` with your VATSIM CID. Without this, you cannot log in (the OAuth callback checks the `users` table).

### 3.2 Create Database Users

Create dedicated application users with minimum privileges (not the admin user):

**Azure SQL** (run on each database):

```sql
-- On the master database of your Azure SQL Server
CREATE LOGIN adl_api_user WITH PASSWORD = 'YourAppPassword123!';

-- On each database (VATSIM_ADL, VATSIM_TMI, etc.)
CREATE USER adl_api_user FOR LOGIN adl_api_user;
ALTER ROLE db_datareader ADD MEMBER adl_api_user;
ALTER ROLE db_datawriter ADD MEMBER adl_api_user;
GRANT EXECUTE TO adl_api_user;  -- For stored procedures
```

> **Note**: The app user does not need `CREATE TABLE` or `CREATE PROCEDURE` privileges. Use the admin user for schema migrations, and the app user (`adl_api_user`) in `config.php`.

**MySQL**:

```sql
CREATE USER 'perti_app'@'%' IDENTIFIED BY 'YourMySQLAppPassword!';
GRANT SELECT, INSERT, UPDATE, DELETE ON perti_site.* TO 'perti_app'@'%';
FLUSH PRIVILEGES;
```

---

## 4. Database Schema Deployment

Run migrations **in order** for each database. Migrations are idempotent SQL scripts in `database/migrations/` and `adl/migrations/`.

### 4.1 VATSIM_ADL (Azure SQL)

Run these against VATSIM_ADL using SSMS, Azure Data Studio, or `sqlcmd`:

```
-- Core flight tables (run in order)
adl/migrations/core/001_adl_core_tables.sql
adl/migrations/core/002_adl_times_trajectory.sql
adl/migrations/core/003_adl_waypoints_stepclimbs.sql
adl/migrations/core/004_adl_reference_tables.sql
adl/migrations/core/005_adl_views_seed_data.sql
adl/migrations/core/006_airlines_table.sql
adl/migrations/core/007_remove_flight_status.sql

-- Boundary detection
adl/migrations/boundaries/001_boundaries_schema.sql
adl/migrations/boundaries/002_boundaries_log_fix.sql
adl/migrations/boundaries/003_boundary_import_procedure.sql
adl/migrations/boundaries/007_integrate_boundary_detection.sql
adl/migrations/boundaries/008_boundary_optimization.sql
adl/migrations/boundaries/009_boundary_grid_lookup.sql

-- Planned crossings
adl/migrations/crossings/001_planned_crossings_schema.sql
adl/migrations/crossings/002_flight_core_crossing_columns.sql
adl/migrations/crossings/004_forecast_views.sql

-- Demand functions
adl/migrations/demand/001_demand_indexes.sql
adl/migrations/demand/002_fn_FixDemand.sql
adl/migrations/demand/003_fn_AirwaySegmentDemand.sql
adl/migrations/demand/004_fn_RouteSegmentDemand.sql
adl/migrations/demand/005_fn_BatchDemandBucketed.sql
adl/migrations/demand/006_fn_ViaDemandBucketed.sql
adl/migrations/demand/007_fn_AirwayDemandBucketed.sql
adl/migrations/demand/008_demand_monitors_table.sql

-- ETA trajectory calculation
adl/migrations/eta/001_eta_trajectory_schema.sql
adl/migrations/eta/009_deploy_eta_trajectory_system.sql

-- Navdata / waypoints
adl/migrations/navdata/001_waypoint_dp_star_columns.sql
adl/migrations/navdata/003_route_distance_columns.sql
adl/migrations/navdata/007_waypoint_eta_integration.sql
adl/migrations/navdata/008_route_distance_integration.sql

-- Changelog triggers
adl/migrations/changelog/000_deploy_changelog_system.sql
adl/migrations/changelog/002_trigger_flight_core.sql
adl/migrations/changelog/003_trigger_flight_plan.sql
adl/migrations/changelog/004_trigger_flight_times.sql
adl/migrations/changelog/005_trigger_aircraft_tmi.sql
adl/migrations/changelog/006_utility_procedures.sql

-- OOOI flight phase detection
adl/migrations/oooi/001_oooi_schema.sql
adl/migrations/oooi/002_oooi_deploy.sql

-- Flight statistics
adl/migrations/stats/001_flight_stats_schema.sql
adl/migrations/stats/002_flight_stats_procedures.sql

-- Aircraft performance data
adl/migrations/performance/001_aircraft_performance_seed.sql

-- CIFP procedure legs
adl/migrations/cifp/001_cifp_procedure_legs.sql

-- Additional ADL feature migrations (numbered, run sequentially)
database/migrations/schema/004_adl_normalized_schema.sql
database/migrations/schema/005_acd_data_full_schema.sql
database/migrations/schema/009_scheduler_state.sql
database/migrations/adl/001_create_division_events.sql
database/migrations/adl/002_create_perti_events.sql
database/migrations/adl/004_create_event_position_log.sql
database/migrations/adl/010_create_tmi_trajectory.sql
```

### 4.2 VATSIM_TMI (Azure SQL)

```
-- Core TMI schema (master migration includes all core tables)
database/migrations/tmi/000_master_migration.sql
-- OR run individually:
database/migrations/tmi/001_tmi_core_schema_azure_sql.sql
database/migrations/tmi/001_create_tmi_programs.sql
database/migrations/tmi/002_create_tmi_slots.sql
database/migrations/tmi/003_create_tmi_flight_control.sql
database/migrations/tmi/004_create_tmi_events_and_popup.sql
database/migrations/tmi/005_create_views.sql
database/migrations/tmi/006_create_core_procedures.sql
database/migrations/tmi/007_create_rbs_and_popup_procedures.sql
database/migrations/tmi/008_create_gs_and_transition_procedures.sql
database/migrations/tmi/009_create_compression_and_retention.sql

-- GDT (Ground Delay Table)
database/migrations/tmi/010_gdt_incremental_schema.sql
database/migrations/tmi/011_create_gdt_views.sql
database/migrations/tmi/012_create_gdt_procedures.sql

-- Public routes & reroutes
database/migrations/tmi/013_migrate_public_routes.sql
database/migrations/tmi/024_create_reroute_routes.sql
database/migrations/tmi/025_reroute_drafts.sql
database/migrations/tmi/026_reroute_routes_filters.sql
database/migrations/tmi/035_add_color_to_reroutes.sql

-- Proposals & coordination
database/migrations/tmi/020_tmi_proposals.sql
database/migrations/tmi/027_add_program_coordination.sql
database/migrations/tmi/028_add_proposal_program_link.sql
database/migrations/tmi/029_create_tmi_flight_list.sql
database/migrations/tmi/030_create_program_coord_log.sql

-- Discord integration
database/migrations/tmi/016_tmi_discord_posts.sql

-- Advisory number sequence fix
database/migrations/tmi/033_fix_advisory_number_race_condition.sql

-- Delay tracking
database/migrations/tmi/034_create_delay_tracking.sql
```

### 4.3 SWIM_API (Azure SQL)

```
database/migrations/swim/001_swim_tables.sql
database/migrations/swim/002_swim_api_database.sql
database/migrations/swim/003_swim_api_database_fixed.sql
database/migrations/swim/004_swim_bulk_upsert_sp.sql
database/migrations/swim/004_swim_api_keys_owner_cid.sql
database/migrations/swim/005_swim_add_telemetry_columns.sql
database/migrations/swim/005_swim_metering_fields.sql

-- FIXM-aligned extended tables
database/migrations/swim/010_swim_fixm_identity_aircraft.sql
database/migrations/swim/011_swim_fixm_route_trajectory.sql
database/migrations/swim/012_swim_fixm_airports_gates.sql
database/migrations/swim/013_swim_fixm_airspace_position.sql
database/migrations/swim/014_swim_fixm_times_acdm.sql
database/migrations/swim/015_swim_fixm_tmi_metering.sql
database/migrations/swim/016_swim_fixm_simbrief_flow.sql
database/migrations/swim/017_swim_simtraffic_times.sql
database/migrations/swim/018_swim_simtraffic_api_key.sql
database/migrations/swim/019_swim_fixm_column_names.sql
database/migrations/swim/020_swim_acars_messages.sql
database/migrations/swim/021_vnas_integration_schema.sql
```

### 4.4 VATSIM_REF (Azure SQL)

The VATSIM_REF tables are created by the reference data import scripts (Section 5). The core tables (`nav_fixes`, `nav_procedures`, `airways`, `airway_segments`, `area_centers`, `coded_departure_routes`, `playbook_routes`) are created as part of the navdata import process.

### 4.5 VATSIM_GIS (PostgreSQL/PostGIS)

```
-- Ensure PostGIS extension is enabled first
-- psql: CREATE EXTENSION IF NOT EXISTS postgis;

database/migrations/postgis/001_boundaries_schema.sql
database/migrations/postgis/002_extended_functions.sql
```

### 4.6 VATSIM_STATS (Azure SQL)

```
database/migrations/vatsim_stats/001_complete_schema.sql
```

### 4.7 Additional MySQL Migrations

Run these against `perti_site`:

```
-- GDP tables
database/migrations/gdp/001_gdp_tables.sql
database/migrations/gdp/002_gdp_tables_patch.sql

-- Plan initiative timelines
database/migrations/initiatives/001_add_plan_end_datetime.sql
database/migrations/initiatives/002_initiative_timeline.sql
database/migrations/initiatives/004_initiative_timeline_alter_mysql.sql

-- Integration features
database/migrations/integration/001_add_position_columns.sql
database/migrations/integration/002_vatsim_adl_hourly_rates.sql
database/migrations/integration/003_discord_integration.sql

-- JATOC incident management
database/migrations/jatoc/001_jatoc_tables.sql
database/migrations/jatoc/002_add_incident_numbers.sql
database/migrations/jatoc/003_jatoc_reports_table.sql
database/migrations/jatoc/005_jatoc_user_roles.sql

-- Reroutes (MySQL copy)
database/migrations/reroute/001_create_reroute_tables.sql

-- SUA
database/migrations/sua/001_sua_activations.sql
database/migrations/sua/002_sua_definitions.sql

-- Advisories
database/migrations/advisories/001_dcc_advisories.sql
database/migrations/advisories/002_nod_advisories.sql

-- Splits
database/migrations/schema/003_splits_areas_color.sql
database/migrations/schema/006_splits_preset_strata_filter.sql
database/migrations/schema/007_splits_configs_updated_at.sql
database/migrations/schema/008_splits_positions_strata_filter.sql
```

---

## 5. Reference Data Import

PERTI requires navigation data (fixes, airways, procedures, airports) to function. This data comes from FAA NASR sources and is imported via Python scripts.

### 5.1 Navigation Data (FAA NASR)

The navdata importer downloads and processes FAA data:

```bash
cd scripts/
pip install -r requirements.txt  # If requirements.txt exists

# Full AIRAC cycle update (all navdata)
python airac_full_update.py

# Or individual importers:
python nasr_navdata_updater.py
```

This populates:
- `nav_fixes` in VATSIM_ADL and VATSIM_REF
- `nav_procedures` in VATSIM_ADL and VATSIM_REF
- `airways` / `airway_segments` in VATSIM_ADL and VATSIM_REF

### 5.2 Static CSV Data Files

The repository includes pre-built CSV files in `assets/data/` that are used by the frontend JavaScript:

| File | Content |
|------|---------|
| `apts.csv` | Airport reference data (ICAO, coordinates, ARTCC) |
| `awys.csv` | Airway definitions (authoritative source for client-side) |
| `cdrs.csv` | Coded Departure Routes |
| `playbook_routes.csv` | FAA playbook routes |
| `points.csv` | Navigation fixes/waypoints |
| `navaids.csv` | VOR/DME navaids |
| `dp_full_routes.csv` | Departure procedure full route strings |
| `star_full_routes.csv` | STAR full route strings |
| `artcc_tiers.json` | ARTCC tier hierarchy |
| `fir_tiers.json` | FIR tier hierarchy |

These files are included in the repository and deployed automatically. No manual import needed.

### 5.3 Boundary Data (PostGIS)

ARTCC, TRACON, and sector boundaries must be imported into the PostGIS database:

```bash
cd scripts/
python build_sector_boundaries.py
```

This reads boundary definition files and imports them as PostGIS geometry objects into `artcc_boundaries`, `tracon_boundaries`, and `sector_boundaries` tables in VATSIM_GIS.

### 5.4 Airport Data

Airport reference data is seeded from FAA NASR data. The `apts` table in VATSIM_ADL and the `airports` table in VATSIM_GIS are populated during the navdata import.

### 5.5 CDR / Playbook Routes

```bash
cd scripts/playbook/
python import_cdrs.py
python import_playbook.py
```

### 5.6 Default Airport Rates

Populate the `config_data` table in `perti_site` MySQL with default AAR/ADR rates for airports you want to plan around. Example:

```sql
INSERT INTO config_data (airport, arr, dep, vmc_aar, imc_aar, vmc_adr, imc_adr)
VALUES
  ('KJFK', '31L,31R', '31L,22R', 44, 33, 44, 33),
  ('KATL', '26L,27R,28', '26R,27L,28', 126, 96, 126, 96),
  ('KLAX', '24L,25L', '24R,25R', 74, 48, 74, 48);
```

---

## 6. Application Configuration

### 6.1 Create `config.php`

Copy the template and fill in your values:

```bash
cp load/config.example.php load/config.php
```

Edit `load/config.php` with your database credentials and settings:

```php
<?php
if (!defined("SQL_USERNAME")) {

    // PRIMARY MYSQL DATABASE (perti_site)
    define("SQL_USERNAME", "perti_app");
    define("SQL_PASSWORD", "YourMySQLAppPassword!");
    define("SQL_HOST", "your-perti-mysql.mysql.database.azure.com");
    define("SQL_DATABASE", "perti_site");

    // VATSIM_ADL - Flight Data
    define("ADL_SQL_HOST", "your-perti-sql.database.windows.net");
    define("ADL_SQL_DATABASE", "VATSIM_ADL");
    define("ADL_SQL_USERNAME", "adl_api_user");
    define("ADL_SQL_PASSWORD", "YourAppPassword123!");

    // SWIM_API - Public API Database
    define("SWIM_SQL_HOST", "your-perti-sql.database.windows.net");
    define("SWIM_SQL_DATABASE", "SWIM_API");
    define("SWIM_SQL_USERNAME", "adl_api_user");
    define("SWIM_SQL_PASSWORD", "YourAppPassword123!");

    // VATSIM_TMI - Traffic Management Initiatives
    define("TMI_SQL_HOST", "your-perti-sql.database.windows.net");
    define("TMI_SQL_DATABASE", "VATSIM_TMI");
    define("TMI_SQL_USERNAME", "adl_api_user");
    define("TMI_SQL_PASSWORD", "YourAppPassword123!");

    // VATSIM_REF - Reference Data
    define("REF_SQL_HOST", "your-perti-sql.database.windows.net");
    define("REF_SQL_DATABASE", "VATSIM_REF");
    define("REF_SQL_USERNAME", "adl_api_user");
    define("REF_SQL_PASSWORD", "YourAppPassword123!");

    // VATSIM_GIS - PostGIS Spatial Database
    define("GIS_SQL_HOST", "your-perti-gis.postgres.database.azure.com");
    define("GIS_SQL_PORT", "5432");
    define("GIS_SQL_DATABASE", "VATSIM_GIS");
    define("GIS_SQL_USERNAME", "gis_admin");
    define("GIS_SQL_PASSWORD", "YourPostgresPassword123!");

    // VATSIM_STATS - Statistics
    define("STATS_SQL_HOST", "your-perti-sql.database.windows.net");
    define("STATS_SQL_DATABASE", "VATSIM_STATS");
    define("STATS_SQL_USERNAME", "adl_api_user");
    define("STATS_SQL_PASSWORD", "YourAppPassword123!");

    // SITE
    define("SITE_DOMAIN", "your-perti-app.azurewebsites.net");
    define("ENV", 'prod');  // 'dev' for local development

    // VATSIM CONNECT OAUTH (see Section 7)
    define("CONNECT_CLIENT_ID", 0);          // Your VATSIM app client ID
    define("CONNECT_SECRET", '');            // Your VATSIM app secret
    define("CONNECT_SCOPES", 'full_name vatsim_details');
    define("CONNECT_REDIRECT_URI", 'https://your-perti-app.azurewebsites.net/login/callback');
    define("CONNECT_URL_BASE", 'https://auth.vatsim.net');

    // DISCORD (see Section 8)
    define("DISCORD_BOT_TOKEN", '');
    define("DISCORD_APPLICATION_ID", '');
    define("DISCORD_PUBLIC_KEY", '');
    define('DISCORD_API_VERSION', '10');
    define('DISCORD_API_BASE', 'https://discord.com/api/v10');

    // Multi-org Discord config (customize for your organization)
    define('DISCORD_ORGANIZATIONS', json_encode([
        'your_org' => [
            'name' => 'Your Organization',
            'region' => 'US',
            'guild_id' => 'YOUR_GUILD_ID',
            'channels' => [
                'ntml' => 'YOUR_NTML_CHANNEL_ID',
                'advisories' => 'YOUR_ADVISORIES_CHANNEL_ID',
                'ntml_staging' => 'YOUR_STAGING_CHANNEL_ID',
                'advzy_staging' => 'YOUR_STAGING_CHANNEL_ID'
            ],
            'enabled' => true,
            'default' => true
        ]
    ]));

    // Legacy Discord channel constants (for backward compatibility)
    define("DISCORD_CHANNEL_NTML", 'YOUR_NTML_CHANNEL_ID');
    define("DISCORD_CHANNEL_ADVISORIES", 'YOUR_ADVISORIES_CHANNEL_ID');
    define("DISCORD_CHANNEL_NTML_STAGING", 'YOUR_STAGING_CHANNEL_ID');
    define("DISCORD_CHANNEL_ADVZY_STAGING", 'YOUR_STAGING_CHANNEL_ID');
    define("DISCORD_NTML_ACTIVE", DISCORD_CHANNEL_NTML_STAGING);
    define("DISCORD_ADVZY_ACTIVE", DISCORD_CHANNEL_ADVZY_STAGING);
    define('DISCORD_GUILD_ID', 'YOUR_GUILD_ID');
    define('DISCORD_CHANNELS', json_encode([
        'tmi' => DISCORD_CHANNEL_NTML,
        'ntml' => DISCORD_CHANNEL_NTML,
        'advisories' => DISCORD_CHANNEL_ADVISORIES,
        'ntml_staging' => DISCORD_CHANNEL_NTML_STAGING,
        'advzy_staging' => DISCORD_CHANNEL_ADVZY_STAGING,
        'operations' => '',
        'alerts' => '',
        'general' => ''
    ]));

    // API keys
    define("SWIM_PUBLIC_ROUTES_KEY", "generate-a-random-api-key-here");

    // Feature Flags
    define("PERTI_LOADED", true);
    define("DISCORD_MULTI_ORG_ENABLED", true);
    define("TMI_STAGING_REQUIRED", true);
    define("TMI_APPROVAL_REACTIONS", true);
    define("TMI_CROSS_BORDER_AUTO_DETECT", true);
}
?>
```

> **Critical**: `config.php` is gitignored and must NEVER be committed. It contains all credentials.

### 6.2 Install PHP Dependencies

```bash
composer install --no-dev
```

This installs:
- `cboden/ratchet` — WebSocket server for SWIM real-time API
- `react/event-loop` — Async event loop for WebSocket

### 6.3 Local Development Mode

For local development, set `ENV` to `'dev'` in `config.php`. The session handler (`sessions/handler.php`) auto-creates a dummy session with CID 0 when `DEV === true`, bypassing VATSIM OAuth.

To define DEV mode, add to your `config.php`:

```php
define("DEV", true);   // Only for local development!
define("ENV", 'dev');
```

Start the local server:

```bash
php -S localhost:8000
```

> **Note**: Local development requires the `sqlsrv` and `pdo_pgsql` PHP extensions to connect to Azure SQL and PostgreSQL. On Windows, download the SQLSRV drivers from Microsoft. On Mac/Linux, install via PECL.

---

## 7. VATSIM OAuth Setup

PERTI authenticates users via VATSIM Connect (OAuth 2.0). Every user must have a VATSIM account.

### 7.1 Register a VATSIM Application

1. Go to https://auth.vatsim.net (or https://auth-dev.vatsim.net for testing)
2. Log in with your VATSIM account
3. Navigate to **Developer** > **Create Application**
4. Fill in:
   - **Name**: Your PERTI Instance
   - **Redirect URI**: `https://your-site.com/login/callback`
   - **Scopes**: `full_name vatsim_details`
5. Save the **Client ID** and **Client Secret**

### 7.2 Configure in `config.php`

```php
define("CONNECT_CLIENT_ID", 12345);  // Your numeric client ID
define("CONNECT_SECRET", 'your-client-secret-here');
define("CONNECT_SCOPES", 'full_name vatsim_details');
define("CONNECT_REDIRECT_URI", 'https://your-site.com/login/callback');
define("CONNECT_URL_BASE", 'https://auth.vatsim.net');
```

> **Dev/Testing**: Use `https://auth-dev.vatsim.net` for the URL base during development. You'll need a separate app registration on the dev server.

### 7.3 Login Flow

The authentication flow works as follows:

1. User visits `/login/` → `login/index.php` redirects to VATSIM Connect OAuth authorize endpoint
2. User authenticates on VATSIM's site
3. VATSIM redirects back to `/login/callback` with an authorization code
4. `login/callback.php` exchanges code for access token, fetches user profile
5. Callback checks if user's CID exists in the `users` table
   - **If found**: Creates a session with CID, first_name, last_name → redirects to home
   - **If not found** (but SWIM login): Creates limited SWIM-only session → redirects to API key page
   - **If not found** (regular login): Shows "not on privileged users list" error

### 7.4 Adding Authorized Users

To allow someone to log in, insert their VATSIM CID into the `users` table:

```sql
INSERT INTO users (cid, first_name, last_name) VALUES (1234567, 'John', 'Doe');
```

For admin access:

```sql
INSERT INTO admin_users (cid, first_name, last_name) VALUES (1234567, 'John', 'Doe');
```

---

## 8. Discord Integration

PERTI publishes TMI advisories and NTML entries to Discord channels, and uses a Gateway bot for real-time coordination reactions.

### 8.1 Create a Discord Application

1. Go to https://discord.com/developers/applications
2. Click **New Application**, name it (e.g., "PERTI TMI Bot")
3. Under **Bot**:
   - Click **Add Bot**
   - Copy the **Bot Token** → this goes in `DISCORD_BOT_TOKEN`
   - Enable these **Privileged Gateway Intents**:
     - `SERVER MEMBERS INTENT` (for user role lookup)
     - `MESSAGE CONTENT INTENT` (for reading coordination messages)
4. Under **General Information**:
   - Copy **Application ID** → `DISCORD_APPLICATION_ID`
   - Copy **Public Key** → `DISCORD_PUBLIC_KEY`
5. Under **OAuth2** > **URL Generator**:
   - Scopes: `bot`
   - Bot Permissions: `Send Messages`, `Read Messages/View Channels`, `Add Reactions`, `Read Message History`, `Manage Messages` (optional)
   - Copy the generated URL and visit it to invite the bot to your Discord server

### 8.2 Discord Server Setup

Create these channels in your Discord server:

| Channel | Purpose | Example Name |
|---------|---------|--------------|
| NTML | Traffic management log (production) | `#ntml` |
| Advisories | TMI advisories (production) | `#advisories` |
| NTML Staging | Test NTML posts before production | `#ntml-staging` |
| Advisory Staging | Test advisories before production | `#advzy-staging` |

Get the **Channel IDs** (Developer Mode → right-click channel → Copy ID) and **Guild/Server ID** for your `config.php`.

### 8.3 Discord Bot Deployment

The bot is a Node.js application in `discord-bot/`:

```bash
cd discord-bot/
npm install
```

Create `discord-bot/.env`:

```env
DISCORD_BOT_TOKEN=your-bot-token-here
API_BASE_URL=https://your-perti-site.com
API_KEY=your-internal-api-key
COORDINATION_CHANNEL_ID=your-coordination-channel-id
DISCORD_GUILD_ID=your-guild-id
LOG_LEVEL=info
```

Start the bot:

```bash
npm start
```

> **Note**: The bot must run separately from the App Service (it's a long-running Node.js process). Consider hosting it on:
> - A small Azure VM or Container Instance
> - A Heroku dyno
> - A Raspberry Pi / VPS
> - Your own server

The bot watches for emoji reactions on threads in the coordination channel and forwards them to `api/mgt/tmi/coordinate.php` to update proposal approval status.

---

## 9. Azure App Service Deployment

### 9.1 Configure App Settings

Set these environment variables in the App Service (Azure Portal > App Service > Configuration > Application settings):

```bash
az webapp config appsettings set --name your-perti-app --resource-group perti-rg --settings \
  WEBSITE_TIME_ZONE="UTC" \
  USE_GIS_DAEMONS="1" \
  SCM_DO_BUILD_DURING_DEPLOYMENT="false"
```

**Optional** (for blob archive daemon):

```bash
az webapp config appsettings set --name your-perti-app --resource-group perti-rg --settings \
  ADL_ARCHIVE_STORAGE_CONN="DefaultEndpointsProtocol=https;AccountName=...;AccountKey=...;EndpointSuffix=core.windows.net" \
  ADL_ARCHIVE_HOUR_UTC="10"
```

### 9.2 Nginx Configuration

The file `default` in the repository root is the nginx configuration. It is automatically copied to `/etc/nginx/sites-enabled/default` by `scripts/startup.sh` at container boot.

Key features of the nginx config:
- Extensionless URL rewrites (e.g., `/demand` → `demand.php`)
- SWIM API RESTful routes (`/api/swim/v1/flight/{gufi}`)
- Gzip compression for all text responses
- Static file caching (30 days)
- Security headers (X-Frame-Options, XSS protection)
- Hidden file/sensitive file access denied
- 50MB max POST body (for trajectory data)

### 9.3 Manual Deployment (First Time)

For the first deployment, you can deploy via ZIP:

```bash
# From the repository root
git archive --format=zip HEAD -o deploy.zip
az webapp deploy --name your-perti-app --resource-group perti-rg --src-path deploy.zip --type zip
```

Or via FTP/FTPS using credentials from the Azure Portal (Deployment Center > FTPS credentials).

### 9.4 Verify Deployment

After deployment:

```bash
# Check health endpoint
curl https://your-perti-app.azurewebsites.net/healthcheck.php

# Expected response:
# {"status":"healthy","timestamp":"2026-03-01T12:00:00Z","php_version":"8.2.x","memory_usage_mb":2.0}
```

Check the Kudu console for daemon logs:
- Navigate to `https://your-perti-app.scm.azurewebsites.net`
- Go to **Debug console** > **Bash**
- View logs: `cat /home/LogFiles/vatsim_adl.log`

---

## 10. CI/CD Pipeline Setup

### 10.1 GitHub Actions Workflow

The repository includes `.github/workflows/azure-webapp-vatcscc.yml` which deploys on push to `main`.

To use it for your own instance:

1. **Get your Azure Publish Profile**:
   - Azure Portal > App Service > **Get publish profile** (download XML file)

2. **Add GitHub Secret**:
   - GitHub Repo > Settings > Secrets and variables > Actions
   - Create secret: `AZUREAPPSERVICE_PUBLISHPROFILE_VATCSCC`
   - Paste the entire XML content of the publish profile

3. **Update the workflow** (fork the repo first):

Edit `.github/workflows/azure-webapp-vatcscc.yml`:

```yaml
env:
  AZURE_WEBAPP_NAME: 'your-perti-app'    # Change to your App Service name
  PHP_VERSION: '8.2'
```

4. **Push to `main`** to trigger deployment.

### 10.2 What the Workflow Does

1. Checks out the repository
2. Sets up PHP 8.2 with Composer
3. Runs `composer install --no-dev`
4. Creates a deployment package via `rsync` (excludes `sdk/`, `.git/`, `.github/`, `docs/` except `docs/swim/` and `docs/stats/`)
5. Deploys to Azure App Service via publish profile

### 10.3 Post-Deployment

After the App Service receives the deployment:
1. The container restarts
2. `scripts/startup.sh` runs:
   - Copies nginx config from `default` file
   - Reloads nginx
   - Starts all background daemons (ADL ingest, parse queue, boundary detection, etc.)
   - Configures PHP OPcache (128MB, 60s revalidation)
   - Configures PHP-FPM (40 workers for P1v2 tier)
   - Starts PHP-FPM in foreground

---

## 11. Background Daemons

The startup script (`scripts/startup.sh`) launches 14+ background PHP daemons. Here is what each does and when it is needed:

### Core Daemons (Required)

| Daemon | Script | Interval | What It Does |
|--------|--------|----------|-------------|
| **ADL Ingest** | `scripts/vatsim_adl_daemon.php` | 15s | Fetches live VATSIM data (pilot positions, flight plans) from the VATSIM data API, processes ATIS data, and populates the ADL flight tables |
| **Parse Queue** | `adl/php/parse_queue_gis_daemon.php` | 10s batch | Parses filed route strings into waypoint sequences using PostGIS for spatial matching |
| **Boundary Detection** | `adl/php/boundary_gis_daemon.php` | 15s | Detects which ARTCC/TRACON/sector each flight is currently in using PostGIS point-in-polygon |
| **SWIM Sync** | `scripts/swim_sync_daemon.php` | 2min | Syncs flight data from VATSIM_ADL to SWIM_API for public API consumers |

### Feature Daemons (Optional but Recommended)

| Daemon | Script | Interval | What It Does |
|--------|--------|----------|-------------|
| **Crossing Calc** | `adl/php/crossing_gis_daemon.php` | Tiered | Predicts when flights will cross ARTCC boundaries (for demand forecasting) |
| **Waypoint ETA** | `adl/php/waypoint_eta_daemon.php` | Tiered | Calculates ETAs for each waypoint along a flight's route |
| **SWIM WebSocket** | `scripts/swim_ws_server.php` | Persistent | Real-time flight event WebSocket server on port 8090 |
| **Scheduler** | `scripts/scheduler_daemon.php` | 60s | Auto-activates scheduled sector splits and route configurations |
| **Archival** | `scripts/archival_daemon.php` | 1-4h | Moves old trajectory data to archive tables, purges stale changelogs |
| **Monitoring** | `scripts/monitoring_daemon.php` | 60s | Collects system performance metrics, logs to /home/LogFiles/ |
| **Discord Queue** | `scripts/tmi/process_discord_queue.php` | Continuous | Processes queued TMI Discord posts asynchronously |
| **Event Sync** | `scripts/event_sync_daemon.php` | 6h | Imports events from VATUSA, VATCAN, and VATSIM APIs |

### Integration Daemons (Optional)

| Daemon | Script | Interval | What It Does |
|--------|--------|----------|-------------|
| **SimTraffic Poll** | `scripts/simtraffic_swim_poll.php` | 2min | Fetches time data from SimTraffic API |
| **Reverse Sync** | `scripts/swim_adl_reverse_sync_daemon.php` | 2min | Syncs SimTraffic data from SWIM back to ADL |
| **ADL Archive** | `scripts/adl_archive_daemon.php` | Daily 10:00Z | Archives trajectory data to Azure Blob Storage |

### Disabling Daemons

To disable optional daemons, comment out their lines in `scripts/startup.sh`. The system will function with reduced features.

At minimum, you need: ADL Ingest + Parse Queue + Boundary Detection.

---

## 12. Post-Deployment Verification

### 12.1 Checklist

After deployment, verify each component:

- [ ] **Health check**: `curl https://your-site.com/healthcheck.php` returns `{"status":"healthy"}`
- [ ] **Home page loads**: Visit `https://your-site.com/` — should show PERTI landing page (or login redirect)
- [ ] **VATSIM OAuth works**: Click Login → redirected to VATSIM → redirected back → session created
- [ ] **ADL data flowing**: After ~30 seconds, check `https://your-site.com/api/adl/current.php` — should show live flight data
- [ ] **Demand page works**: Visit `https://your-site.com/demand` — should show demand charts with live data
- [ ] **Route visualization**: Visit `https://your-site.com/route` — should show MapLibre map with routes
- [ ] **SWIM API**: `https://your-site.com/api/swim/v1/flights.php` — should return flights (requires API key)
- [ ] **Discord posting**: Trigger a test TMI advisory → should appear in staging Discord channel
- [ ] **Daemon health**: Check Kudu logs at `https://your-site.scm.azurewebsites.net/api/logstream`

### 12.2 Common Issues

| Symptom | Cause | Fix |
|---------|-------|-----|
| 503 on all pages | MySQL connection failed | Check `SQL_HOST`, `SQL_USERNAME`, `SQL_PASSWORD` in config.php; check firewall rules |
| Login fails with "not on privileged users" | CID not in `users` table | INSERT into `users` table |
| ADL shows no flights | ADL daemon not running or Azure SQL connection failed | Check `/home/LogFiles/vatsim_adl.log` via Kudu |
| Route parsing stuck | Parse queue daemon failed or PostGIS unreachable | Check `/home/LogFiles/parse_queue_gis.log` |
| Boundaries all NULL | Boundary daemon failed or boundaries not imported | Run boundary import script, check daemon log |
| SWIM API returns 401 | No API key | Generate API key in `swim_api_keys` table |

---

## 13. Optional Components

### 13.1 Wind Data Service

PERTI can incorporate NOAA GFS wind data for more accurate ETAs.

Location: `services/wind/`

```bash
# Install Python dependencies
pip install requests numpy

# Fetch wind data (run periodically via cron/scheduler)
python services/wind/fetch_noaa_gfs.py
```

On Windows, a Task Scheduler setup script is provided:

```powershell
powershell -File services/wind/setup_task_scheduler.ps1
```

### 13.2 TMI Compliance Analysis

Python-based TMI compliance analysis:

```bash
cd scripts/tmi_compliance/
pip install -r requirements.txt
python run.py
```

### 13.3 SWIM API Key Management

Users can self-service SWIM API keys via `/swim-keys`. To enable this:
1. Ensure the SWIM API database schema is deployed
2. Add initial API keys manually if needed:

```sql
-- On SWIM_API database
INSERT INTO swim_api_keys (api_key, tier, owner_name, owner_email, is_active)
VALUES ('your-generated-key', 'standard', 'Admin', 'admin@example.com', 1);
```

### 13.4 Multi-Language Support (i18n)

PERTI supports internationalization. Translations are in `assets/locales/`:
- `en-US.json` — English (complete, 450+ keys)
- `fr-CA.json` — French Canadian (near-complete)
- `en-CA.json` — Canadian English overlay
- `en-EU.json` — European English overlay

Users can switch locale via URL parameter `?locale=fr-CA` or browser settings.

### 13.5 Flight Simulator Integrations

PERTI includes plugins for:
- **MSFS** (C++, SimConnect): `integrations/flight-sim/msfs/`
- **X-Plane** (C, DataRefs): `integrations/flight-sim/xplane/`
- **Prepar3D** (C++, SimConnect): `integrations/flight-sim/p3d/`

And pilot client plugins:
- **vPilot** (C#): `integrations/pilot-clients/vpilot/`
- **xPilot** (Python): `integrations/pilot-clients/xpilot/`

These send OOOI (Out-Off-On-In) events and position data to the SWIM API.

### 13.6 Client SDKs

Pre-built client libraries for consuming the SWIM API in `sdk/`:
- C++ (`sdk/cpp/`)
- C# (`sdk/csharp/`)
- Java (`sdk/java/`)
- JavaScript (`sdk/javascript/`)
- PHP (`sdk/php/`)
- Python (`sdk/python/`)

---

## 14. Troubleshooting

### 14.1 Kudu SSH Access

Access the container's shell via:

```
https://your-perti-app.scm.azurewebsites.net/api/command
```

Or via Azure Portal: App Service > Development Tools > SSH.

Useful commands:

```bash
# Check running daemons
ps aux | grep php

# View daemon logs
tail -100 /home/LogFiles/vatsim_adl.log
tail -100 /home/LogFiles/parse_queue_gis.log
tail -100 /home/LogFiles/boundary_gis.log

# Check PHP extensions
php -m | grep -E "sqlsrv|pdo_pgsql|mysqli"

# Check nginx config
nginx -t
cat /etc/nginx/sites-enabled/default

# Check PHP-FPM status
curl http://127.0.0.1:9000/fpm-status
```

### 14.2 Database Connectivity

Test connections from the container:

```bash
# Test MySQL
php -r "new PDO('mysql:host=your-mysql.mysql.database.azure.com;dbname=perti_site', 'user', 'pass'); echo 'OK';"

# Test Azure SQL (requires sqlsrv)
php -r "sqlsrv_connect('your-sql.database.windows.net', ['Database'=>'VATSIM_ADL','UID'=>'user','PWD'=>'pass']); echo 'OK';"

# Test PostgreSQL
php -r "new PDO('pgsql:host=your-gis.postgres.database.azure.com;dbname=VATSIM_GIS', 'user', 'pass'); echo 'OK';"
```

### 14.3 PHP Extensions on Azure

Azure's PHP 8.2 container includes most extensions, but `sqlsrv` requires ODBC Driver 18. The startup script should handle this, but if not:

```bash
# In Kudu SSH (as root during startup)
curl https://packages.microsoft.com/keys/microsoft.asc | apt-key add -
curl https://packages.microsoft.com/config/debian/11/prod.list > /etc/apt/sources.list.d/mssql-release.list
apt-get update
ACCEPT_EULA=Y apt-get install -y msodbcsql18
pecl install sqlsrv pdo_sqlsrv
```

### 14.4 Session Issues

If sessions are not working:
- `sessions/handler.php` must be included BEFORE `connect.php` (because `connect.php` has a closing `?>` tag that outputs a newline, which sends headers and prevents `session_start()`)
- Check that `session_start()` runs before any output

---

## 15. Architecture Reference

### 15.1 Request Flow

```
Client Browser
    │
    ▼
Azure App Service (nginx on port 8080)
    │
    ├─ Static files → Served directly (30-day cache)
    │
    ├─ PHP pages → PHP-FPM (port 9000)
    │   ├─ sessions/handler.php (session start)
    │   ├─ load/config.php (credentials)
    │   ├─ load/connect.php (database connections)
    │   │   ├─ MySQL (perti_site) — always connected
    │   │   ├─ Azure SQL (ADL/TMI/SWIM/REF) — lazy loaded
    │   │   └─ PostgreSQL (GIS) — lazy loaded on demand
    │   └─ Page logic + API calls
    │
    └─ API endpoints → PHP-FPM
        ├─ /api/adl/* → Flight data
        ├─ /api/tmi/* → TMI management
        ├─ /api/swim/v1/* → Public SWIM API
        └─ /api/data/* → Reference data
```

### 15.2 Data Flow

```
VATSIM Data API (https://data.vatsim.net/v3/vatsim-data.json)
    │
    ▼ (every 15 seconds)
vatsim_adl_daemon.php
    │
    ├─ Ingest → VATSIM_ADL (normalized 8 tables)
    │   ├─ adl_flight_core
    │   ├─ adl_flight_plan
    │   ├─ adl_flight_position
    │   ├─ adl_flight_times
    │   ├─ adl_flight_tmi
    │   ├─ adl_flight_aircraft
    │   ├─ adl_flight_trajectory
    │   └─ adl_flight_waypoints
    │
    ├─ parse_queue_gis_daemon → Route parsing (PostGIS)
    ├─ boundary_gis_daemon → Boundary detection (PostGIS)
    ├─ crossing_gis_daemon → Crossing predictions (PostGIS)
    ├─ waypoint_eta_daemon → Waypoint ETA calculation
    │
    └─ swim_sync_daemon → SWIM_API (swim_flights)
        │
        └─ swim_ws_server → WebSocket clients (port 8090)
```

### 15.3 External Dependencies

| Service | URL | Used For |
|---------|-----|----------|
| VATSIM Data API | `https://data.vatsim.net/v3/vatsim-data.json` | Live pilot positions, flight plans, ATIS |
| VATSIM Connect | `https://auth.vatsim.net` | OAuth 2.0 user authentication |
| VATUSA API | `https://api.vatusa.net` | Event sync, division data |
| VATCAN API | (if configured) | Canadian event sync |
| SimTraffic API | (if configured) | SimTraffic time data |
| Discord API | `https://discord.com/api/v10` | TMI publishing, coordination |
| NOAA GFS | `https://nomads.ncep.noaa.gov` | Wind data (optional) |
| MapLibre GL JS | CDN | Map visualization |
| jQuery/Bootstrap | CDN | Frontend framework |
| Chart.js | CDN | Data visualization |
| SweetAlert2 | CDN | UI notifications |

### 15.4 File Reference: Key Files to Customize

| File | What to Change |
|------|---------------|
| `load/config.php` | All credentials, site domain, OAuth, Discord |
| `default` | nginx config (if custom URL routing needed) |
| `scripts/startup.sh` | Enable/disable daemons, tune PHP-FPM workers |
| `assets/css/perti_theme.css` | Branding colors, logos |
| `assets/css/perti-colors.css` | Color variables |
| `load/nav.php` | Navigation bar branding |
| `load/header.php` | Page title, meta tags |
| `load/footer.php` | Footer branding |
| `assets/locales/en-US.json` | UI text customization |
| `assets/js/config/constants.js` | Frontend constants |

---

## Appendix A: Full Environment Variable Reference

These environment variables can be set in Azure App Service configuration:

| Variable | Default | Description |
|----------|---------|-------------|
| `USE_GIS_DAEMONS` | `1` | Use PostGIS daemons (1) or ADL-only mode (0) |
| `ADL_ARCHIVE_STORAGE_CONN` | (none) | Azure Blob Storage connection string for trajectory archival |
| `ADL_ARCHIVE_HOUR_UTC` | `10` | Hour (0-23 UTC) to run daily archive |
| `WEBSITE_TIME_ZONE` | `UTC` | App Service timezone |
| `SCM_DO_BUILD_DURING_DEPLOYMENT` | `false` | Skip Kudu build (we build in CI) |

## Appendix B: Azure SQL SKU Recommendations

| Database | Minimum SKU | Recommended SKU | Notes |
|----------|-------------|-----------------|-------|
| VATSIM_ADL | GP Serverless Gen5 (0.5-2 vCore) | GP Serverless Gen5 (0.5-4 vCore) | Highest load — 15s ingest cycle, 8-table MERGEs, concurrent daemons. **Do not use DTU tiers** — they throttle under sustained load and cause missed VATSIM feeds. For 3,000+ concurrent flights or 24/7 ops, use Hyperscale Serverless (min 2-3, max 8-16 vCores). |
| VATSIM_TMI | Basic (5 DTU) | Basic (5 DTU) | Event-driven TMI operations; spikes only during active GDP/GS |
| VATSIM_REF | Basic (5 DTU) | Basic (5 DTU) | Low traffic, mostly reads |
| SWIM_API | Basic (5 DTU) | Basic (5 DTU) | Sufficient for <100 API consumers; upgrade to S0 if needed |
| VATSIM_STATS | Free | Basic (5 DTU) | Free tier auto-pauses after 60min; Basic stays on for dashboards |

> **Why not DTU for VATSIM_ADL?** DTU tiers (S0=10, S1=20, S2=50, S3=100) impose hard compute caps. PERTI's 15-second ingest cycle with 8-table MERGEs, plus concurrent parse queue, boundary detection, crossing prediction, and SWIM sync daemons, creates sustained compute demand that exceeds DTU caps unpredictably. vATCSCC experienced missed VATSIM feeds and cascading daemon delays on S2 (50 DTU) at ~3,000 concurrent flights. vCore-based serverless billing is more expensive but eliminates throttling. See `COMPUTATIONAL_REFERENCE.md` Section 15 for detailed scaling guidance.

## Appendix C: PHP-FPM Worker Tuning

Adjust `scripts/startup.sh` based on your App Service tier:

| App Service Tier | RAM | Recommended `max_children` |
|-----------------|-----|---------------------------|
| B1 (1.75GB) | 1.75GB | 25 |
| B2/P1v2 (3.5GB) | 3.5GB | 40-50 |
| B3/P2v2 (7GB) | 7GB | 80-100 |
| P3v2 (14GB) | 14GB | 150+ |

Formula: `(TOTAL_RAM - 500MB overhead) / 50MB per worker = max_children`
