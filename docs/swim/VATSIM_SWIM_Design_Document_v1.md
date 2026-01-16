# VATSIM SWIM (System Wide Information Management)
# Design Document v1.3

**Document Status:** PHASE 0 COMPLETE  
**Version:** 1.3  
**Date:** 2026-01-16  
**Author:** vATCSCC Development Team  
**Classification:** Public  

---

## Table of Contents

1. [Executive Summary](#1-executive-summary)
2. [Vision & Objectives](#2-vision--objectives)
3. [Architecture Overview](#3-architecture-overview)
4. [Infrastructure & Cost](#4-infrastructure--cost)
5. [Unified Flight Record](#5-unified-flight-record)
6. [API Design](#6-api-design)
7. [Security & Authentication](#7-security--authentication)
8. [Implementation Status](#8-implementation-status)
9. [Implementation Roadmap](#9-implementation-roadmap)
10. [Appendices](#10-appendices)

---

## 1. Executive Summary

### 1.1 Purpose

VATSIM SWIM (System Wide Information Management) is a centralized data exchange hub enabling real-time flight information sharing across the VATSIM ecosystem. It provides a single source of truth for flight data, enabling consistent Traffic Management Initiative (TMI) implementation, synchronized arrival/departure times, and seamless data exchange between all VATSIM systems.

### 1.2 Problem Statement

Currently, VATSIM systems operate in silos:
- **vATCSCC/PERTI** calculates demand, manages TMIs, tracks OOOI times
- **vNAS** manages ATC automation, tracks, clearances, handoffs
- **CRC/EuroScope** display flight data but lack TMI awareness
- **SimTraffic** computes metering times independently
- **Pilot clients** have no visibility into TMI status
- **Virtual airlines** cannot access real-time flight tracking

### 1.3 Solution

SWIM provides:
- **Unified Flight Record**: Single document per flight with data from all sources
- **Real-Time Distribution**: Sub-second updates via WebSocket/Event streaming
- **Authoritative Data Model**: Clear ownership rules for each data domain
- **Open API**: Enabling innovation across the VATSIM ecosystem
- **Cost-Optimized Infrastructure**: Dedicated cheap database isolates API load from internal systems

---

## 2. Vision & Objectives

### 2.1 Vision Statement

*"Enable every VATSIM system to access consistent, real-time flight information, creating a seamlessly integrated virtual airspace where TMIs are automatically applied, metering is coordinated, and all participants share common situational awareness."*

### 2.2 Strategic Objectives

1. **Unified Data**: Single authoritative record for each flight
2. **Real-Time Sync**: All systems see the same data simultaneously
3. **TMI Consistency**: Ground stops and delays applied uniformly
4. **Open Ecosystem**: Enable third-party innovation
5. **Cost Efficiency**: Dedicated infrastructure prevents API load from impacting internal systems

---

## 3. Architecture Overview

### 3.1 High-Level Architecture

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                           INTERNAL SYSTEMS                                  │
│  ┌─────────────────────────────────────────────────────────────────────┐   │
│  │                                                                     │   │
│  │   ┌─────────────┐     ┌─────────────────────────────────────┐      │   │
│  │   │   VATSIM    │     │        VATSIM_ADL (Azure SQL)       │      │   │
│  │   │    API      │────▶│           Serverless                │      │   │
│  │   └─────────────┘     │   (Internal use only - expensive)   │      │   │
│  │                       └──────────────────┬──────────────────┘      │   │
│  │                                          │                         │   │
│  │   ┌─────────────┐                        │ (sync every 15 sec)     │   │
│  │   │   MySQL     │                        │                         │   │
│  │   │  (PERTI)    │                        ▼                         │   │
│  │   └──────┬──────┘     ┌──────────────────────────────────────┐    │   │
│  │          │            │     SWIM_API Database (Azure SQL)    │    │   │
│  │          │            │        Basic Tier ($5/month)         │    │   │
│  │          └───────────▶│    (Dedicated for public API)        │    │   │
│  │                       └──────────────────┬───────────────────┘    │   │
│  │                                          │                         │   │
│  └──────────────────────────────────────────┼─────────────────────────┘   │
│                                             │                              │
└─────────────────────────────────────────────┼──────────────────────────────┘
                                              │
                                              ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│                           PUBLIC SWIM API                                   │
│  ┌─────────────────────────────────────────────────────────────────────┐   │
│  │                                                                     │   │
│  │   ┌─────────────────┐    ┌─────────────────┐                       │   │
│  │   │  REST API       │    │  WebSocket      │                       │   │
│  │   │  /api/swim/v1/  │    │  /api/swim/v1/  │                       │   │
│  │   │                 │    │  stream         │                       │   │
│  │   └────────┬────────┘    └────────┬────────┘                       │   │
│  │            │                      │                                 │   │
│  │            └──────────┬───────────┘                                │   │
│  │                       │                                             │   │
│  │                       ▼                                             │   │
│  │   ┌─────────────────────────────────────────────────────────────┐  │   │
│  │   │                    SUBSCRIBERS                              │  │   │
│  │   │  CRC │ EuroScope │ vPilot │ SimAware │ VAs │ Analytics     │  │   │
│  │   └─────────────────────────────────────────────────────────────┘  │   │
│  │                                                                     │   │
│  └─────────────────────────────────────────────────────────────────────┘   │
│                                                                             │
└─────────────────────────────────────────────────────────────────────────────┘
```

### 3.2 Key Design Principle: Database Isolation

**CRITICAL:** The public SWIM API must NEVER query VATSIM_ADL directly.

| Database | Purpose | Tier | Cost | Access |
|----------|---------|------|------|--------|
| **VATSIM_ADL** | Internal ADL processing | Serverless | Variable (expensive) | Internal only |
| **SWIM_API** | Public API queries | Basic | $5/month (fixed) | Public API |
| **MySQL (PERTI)** | Ground stops, site data | Existing | Already paid | Both |

**Why?**
- VATSIM_ADL Serverless charges per vCore-second of usage
- External API traffic could cost $500-7,500/month if querying directly
- Dedicated Basic tier has fixed $5/month cost regardless of query volume
- Isolates public load from internal processing

### 3.3 Data Flow

```
1. VATSIM API → VATSIM_ADL (every 15 sec via sp_Adl_RefreshFromVatsim_Normalized)
2. VATSIM_ADL → SWIM_API (every 15 sec via sp_Swim_SyncFromAdl)
3. MySQL → SWIM_API (every 15 sec for ground stops)
4. SWIM_API → Public REST/WebSocket API
```

---

## 4. Infrastructure & Cost

### 4.1 Target Monthly Cost: ~$21-47/month

| Service | Purpose | Tier | Monthly Cost |
|---------|---------|------|--------------|
| **Azure SQL (SWIM_API)** | Dedicated API database | Basic | **$5** |
| Azure Redis (optional) | Hot cache for high traffic | Basic C0 | $16 |
| Azure Functions | Processing (if needed) | Consumption | **FREE** |
| Azure SignalR | WebSocket (Phase 2) | Free | **FREE** |
| Azure Storage | Archives | LRS | $2-3 |
| **TOTAL (Minimum)** | | | **~$7-8/month** |
| **TOTAL (With Redis)** | | | **~$21-24/month** |

### 4.2 SWIM_API Database Schema

The dedicated SWIM_API database contains denormalized, read-optimized tables:

```sql
-- Core flight data (synced from VATSIM_ADL normalized tables)
CREATE TABLE swim_flights (
    flight_uid BIGINT PRIMARY KEY,
    flight_key NVARCHAR(64),
    gufi NVARCHAR(64),
    callsign NVARCHAR(16),
    cid INT,
    
    -- Position
    lat DECIMAL(9,6),
    lon DECIMAL(10,6),
    altitude_ft INT,
    heading_deg SMALLINT,
    groundspeed_kts INT,
    
    -- Flight Plan
    fp_dept_icao CHAR(4),
    fp_dest_icao CHAR(4),
    fp_altitude_ft INT,
    fp_route NVARCHAR(MAX),
    fp_dept_artcc NVARCHAR(8),
    fp_dest_artcc NVARCHAR(8),
    
    -- Progress
    phase NVARCHAR(16),
    is_active BIT,
    dist_to_dest_nm DECIMAL(10,2),
    pct_complete DECIMAL(5,2),
    
    -- Times
    eta_utc DATETIME2,
    out_utc DATETIME2,
    off_utc DATETIME2,
    on_utc DATETIME2,
    in_utc DATETIME2,
    
    -- TMI
    gs_held BIT,
    ctl_type NVARCHAR(8),
    ctl_prgm NVARCHAR(32),
    slot_time_utc DATETIME2,
    delay_minutes INT,
    
    -- Aircraft
    aircraft_type NVARCHAR(8),
    weight_class NCHAR(1),
    airline_icao NVARCHAR(4),
    
    -- Metadata
    last_sync_utc DATETIME2 DEFAULT GETUTCDATE(),
    
    INDEX IX_swim_flights_active (is_active, callsign),
    INDEX IX_swim_flights_dept (fp_dept_icao),
    INDEX IX_swim_flights_dest (fp_dest_icao),
    INDEX IX_swim_flights_artcc (fp_dest_artcc)
);

-- API keys (can stay in SWIM_API or VATSIM_ADL)
CREATE TABLE swim_api_keys (...);

-- Audit log
CREATE TABLE swim_audit_log (...);
```

### 4.3 Sync Implementation

**Note:** Azure SQL Basic tier doesn't support cross-database queries, so sync is implemented via PHP rather than a cross-database stored procedure.

**Sync Flow:**
```
1. ADL daemon refreshes VATSIM_ADL (every 15 seconds)
2. Every 8th cycle (2 minutes), daemon triggers SWIM sync
3. PHP reads from VATSIM_ADL normalized tables
4. PHP encodes ~2,000 flights as JSON (~3MB)
5. PHP calls sp_Swim_BulkUpsert on SWIM_API
6. SP parses JSON, performs MERGE, returns stats
```

**Files:**
- `scripts/swim_sync.php` - PHP sync logic (V2 with batch SP)
- `scripts/vatsim_adl_daemon.php` - Integration point
- `database/migrations/swim/004_swim_bulk_upsert_sp.sql` - Batch upsert SP

**sp_Swim_BulkUpsert (SWIM_API):**
```sql
CREATE PROCEDURE dbo.sp_Swim_BulkUpsert @Json NVARCHAR(MAX)
AS
BEGIN
    -- Parse JSON into temp table
    SELECT ... INTO #flights FROM OPENJSON(@Json);
    
    -- MERGE: Insert new, update existing
    MERGE dbo.swim_flights AS target
    USING #flights AS source ON target.flight_uid = source.flight_uid
    WHEN MATCHED THEN UPDATE SET ...
    WHEN NOT MATCHED THEN INSERT ...;
    
    -- Delete stale flights (inactive >2 hours)
    DELETE FROM dbo.swim_flights 
    WHERE is_active = 0 AND last_sync_utc < DATEADD(HOUR, -2, SYSUTCDATETIME());
    
    -- Return stats
    SELECT @inserted AS inserted, @updated AS updated, @deleted AS deleted;
END;
```

### 4.4 Cost Comparison

| Scenario | Direct VATSIM_ADL | Dedicated SWIM_API |
|----------|-------------------|-------------------|
| 10K requests/day | ~$15-45/mo | **$5/mo** |
| 100K requests/day | ~$150-450/mo | **$5/mo** |
| 1M requests/day | ~$1,500-4,500/mo | **$5/mo** |
| 10M requests/day | ~$15,000+/mo | **$5/mo** |

---

## 5. Unified Flight Record

### 5.1 GUFI (Globally Unique Flight Identifier)

Format: `VAT-YYYYMMDD-CALLSIGN-DEPT-DEST`

Example: `VAT-20260115-UAL123-KJFK-KLAX`

### 5.2 Data Authority Matrix

| Field Path | Authoritative Source | Can Override |
|------------|---------------------|--------------|
| `identity.*` | VATSIM | No |
| `flight_plan.*` | VATSIM | No |
| `simbrief.*` | SIMBRIEF | No |
| `adl.*` | VATCSCC | No |
| `tmi.*` | VATCSCC | No |
| `track.*` | VNAS/CRC | Yes |
| `metering.*` | SIMTRAFFIC | Yes |
| `telemetry.*` | SIMULATOR | Yes |

---

## 6. API Design

### 6.1 Base URL
```
Production: https://perti.vatcscc.org/api/swim/v1
```

### 6.2 Authentication
```http
Authorization: Bearer {api_key}
```

### 6.3 API Key Tiers

| Tier | Prefix | Rate Limit | Write Access |
|------|--------|-----------|--------------|
| System | `swim_sys_` | 10,000/min | Yes |
| Partner | `swim_par_` | 1,000/min | Limited |
| Developer | `swim_dev_` | 100/min | No |
| Public | `swim_pub_` | 30/min | No |

### 6.4 Endpoints

| Method | Endpoint | Status | Description |
|--------|----------|--------|-------------|
| GET | `/api/swim/v1` | ✅ Done | API info |
| GET | `/api/swim/v1/flights` | ✅ Done | List flights with filters |
| GET | `/api/swim/v1/flight` | ✅ Done | Get single flight |
| GET | `/api/swim/v1/positions` | ✅ Done | Bulk positions (GeoJSON) |
| GET | `/api/swim/v1/tmi/programs` | ⚠️ Error | Active TMI programs |
| GET | `/api/swim/v1/tmi/controlled` | ✅ Done | TMI-controlled flights |
| POST | `/api/swim/v1/ingest/adl` | ✅ Done | Ingest ADL data |
| POST | `/api/swim/v1/ingest/track` | ⏳ Pending | Ingest track data |
| POST | `/api/swim/v1/ingest/metering` | ⏳ Pending | Ingest metering data |
| WS | `/api/swim/v1/stream` | ⏳ Pending | Real-time updates |

---

## 7. Security & Authentication

### 7.1 Authentication Flow

1. Client sends request with `Authorization: Bearer {api_key}` header
2. `auth.php` middleware validates key format and tier
3. Key lookup in `swim_api_keys` table
4. Rate limit check via APCu cache
5. Access logged to `swim_audit_log`

### 7.2 CORS Configuration

Allowed origins:
- `https://perti.vatcscc.org`
- `https://vatcscc.org`
- `https://swim.vatcscc.org`
- `http://localhost:*` (development)

---

## 8. Implementation Status

### 8.1 Current State (v1.3 - Infrastructure Complete)

✅ **Infrastructure migration complete.** SWIM_API database deployed with dedicated sync from ADL daemon.

| Component | Status | Notes |
|-----------|--------|-------|
| SWIM_API Database | ✅ Deployed | Azure SQL Basic $5/mo |
| swim_flights Table | ✅ Deployed | 75-column full schema |
| sp_Swim_BulkUpsert | ✅ Deployed | MERGE-based batch sync |
| ADL Daemon Sync | ✅ Integrated | 2-minute interval via PHP |
| API Endpoints | ✅ Functional | SWIM_API with ADL fallback |
| Authentication | ✅ Complete | Working |
| Rate Limiting | ✅ Complete | APCu-based |
| VATSIM_ADL Isolation | ✅ Complete | No SWIM objects remain |

### 8.2 Sync Performance

| Metric | Value | Notes |
|--------|-------|-------|
| Sync Method | PHP batch via sp_Swim_BulkUpsert | Not cross-DB (Basic tier limitation) |
| Sync Interval | 2 minutes | Every 8th daemon cycle |
| Sync Duration | ~30 seconds | 2,000 flights × 75 columns |
| Data Staleness | 30s - 2.5 min | Acceptable for current usage |
| DTU Utilization | ~25% | Comfortable headroom |

### 8.3 Completed Components

| Component | Location | Status |
|-----------|----------|--------|
| Configuration | `load/config.php`, `load/connect.php` | ✅ Complete |
| Auth Middleware | `api/swim/v1/auth.php` | ✅ Complete |
| API Router | `api/swim/v1/index.php` | ✅ Complete |
| Flights Endpoint | `api/swim/v1/flights.php` | ✅ Complete (SWIM_API) |
| Flight Endpoint | `api/swim/v1/flight.php` | ✅ Complete (ADL for detail) |
| Positions Endpoint | `api/swim/v1/positions.php` | ✅ Complete (SWIM_API) |
| TMI Programs | `api/swim/v1/tmi/programs.php` | ✅ Fixed |
| TMI Controlled | `api/swim/v1/tmi/controlled.php` | ✅ Complete (SWIM_API) |
| Sync Script | `scripts/swim_sync.php` | ✅ V2 with batch SP |
| Daemon Integration | `scripts/vatsim_adl_daemon.php` | ✅ 2-min interval |

---

## 9. Implementation Roadmap

### Phase 0: Infrastructure ✅ COMPLETE

| Task | Owner | Status |
|------|-------|--------|
| Create Azure SQL Basic database "SWIM_API" | DevOps | ✅ |
| Create swim_flights table (75 columns) | Dev | ✅ |
| Create sp_Swim_BulkUpsert procedure | Dev | ✅ |
| Integrate sync into ADL daemon | Dev | ✅ |
| Update connection strings in config.php | Dev | ✅ |
| Update API endpoints with SWIM_API fallback | Dev | ✅ |
| Clean SWIM objects from VATSIM_ADL | Dev | ✅ |
| Test sync performance | QA | ✅ |

### Phase 1: Foundation ✅ COMPLETE

| Week | Focus | Status |
|------|-------|--------|
| 1-2 | Design & Architecture | ✅ Complete |
| 2-3 | Core API Implementation | ✅ Complete |
| 3-4 | Infrastructure Migration | ✅ Complete |

### Phase 2: Real-Time Distribution (Weeks 5-8)

| Week | Focus |
|------|-------|
| 5-6 | WebSocket server, subscription management |
| 7-8 | Event publishing, vNAS integration |

### Phase 3: Partner Integrations (Weeks 9-12)

| Week | Focus |
|------|-------|
| 9-10 | CRC plugin, EuroScope integration |
| 11-12 | SimTraffic, Virtual Airlines |

---

## 10. Appendices

### 10.1 Glossary

| Term | Definition |
|------|------------|
| ADL | Aggregate Demand List |
| EDCT | Expect Departure Clearance Time |
| GDP | Ground Delay Program |
| GS | Ground Stop |
| GUFI | Globally Unique Flight Identifier |
| OOOI | Out-Off-On-In times |
| SWIM | System Wide Information Management |
| TMI | Traffic Management Initiative |

### 10.2 File Structure

```
VATSIM PERTI/PERTI/
├── api/swim/v1/
│   ├── auth.php              # Authentication middleware
│   ├── index.php             # API router
│   ├── flights.php           # Flights endpoint
│   ├── flight.php            # Single flight endpoint
│   ├── positions.php         # GeoJSON positions
│   ├── ingest/
│   │   └── adl.php           # ADL ingest
│   └── tmi/
│       ├── programs.php      # TMI programs
│       └── controlled.php    # TMI controlled flights
├── database/migrations/swim/
│   ├── 001_swim_tables.sql   # API keys, audit tables
│   └── 002_swim_api_database.sql  # Dedicated SWIM_API schema
├── docs/swim/
│   ├── README.md             # Overview
│   ├── VATSIM_SWIM_Design_Document_v1.md
│   └── SWIM_TODO.md          # Implementation tracker
└── load/
    └── swim_config.php       # Configuration
```

### 10.3 Related Documents

- [SWIM README](./README.md) - Quick start guide
- [SWIM TODO](./SWIM_TODO.md) - Implementation tracker
- [Normalized Schema Reference](./ADL_NORMALIZED_SCHEMA_REFERENCE.md) - Source data schema

---

**Contact:** dev@vatcscc.org  
**Repository:** VATSIM PERTI/PERTI

*End of Document*
