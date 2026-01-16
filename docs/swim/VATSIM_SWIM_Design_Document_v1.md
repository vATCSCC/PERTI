# VATSIM SWIM (System Wide Information Management)
# Design Document v1.2

**Document Status:** IN DEVELOPMENT  
**Version:** 1.2  
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

### 4.3 Sync Procedure

```sql
-- Runs every 15 seconds after ADL refresh
CREATE PROCEDURE sp_Swim_SyncFromAdl
AS
BEGIN
    -- Merge active flights from normalized ADL tables
    MERGE swim_flights AS target
    USING (
        SELECT 
            c.flight_uid, c.flight_key, c.callsign, c.cid,
            pos.lat, pos.lon, pos.altitude_ft, pos.heading_deg, pos.groundspeed_kts,
            fp.fp_dept_icao, fp.fp_dest_icao, fp.fp_altitude_ft, fp.fp_route,
            fp.fp_dept_artcc, fp.fp_dest_artcc,
            c.phase, c.is_active, pos.dist_to_dest_nm, pos.pct_complete,
            t.eta_utc, t.out_utc, t.off_utc, t.on_utc, t.in_utc,
            tmi.gs_held, tmi.ctl_type, tmi.ctl_prgm, tmi.slot_time_utc, tmi.delay_minutes,
            fp.aircraft_type, ac.weight_class, ac.airline_icao
        FROM VATSIM_ADL.dbo.adl_flight_core c
        LEFT JOIN VATSIM_ADL.dbo.adl_flight_position pos ON pos.flight_uid = c.flight_uid
        LEFT JOIN VATSIM_ADL.dbo.adl_flight_plan fp ON fp.flight_uid = c.flight_uid
        LEFT JOIN VATSIM_ADL.dbo.adl_flight_times t ON t.flight_uid = c.flight_uid
        LEFT JOIN VATSIM_ADL.dbo.adl_flight_tmi tmi ON tmi.flight_uid = c.flight_uid
        LEFT JOIN VATSIM_ADL.dbo.adl_flight_aircraft ac ON ac.flight_uid = c.flight_uid
        WHERE c.is_active = 1 OR c.last_seen_utc > DATEADD(HOUR, -2, GETUTCDATE())
    ) AS source ON target.flight_uid = source.flight_uid
    WHEN MATCHED THEN UPDATE SET ...
    WHEN NOT MATCHED THEN INSERT ...;
    
    -- Remove stale flights
    DELETE FROM swim_flights 
    WHERE is_active = 0 AND last_sync_utc < DATEADD(HOUR, -2, GETUTCDATE());
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

### 8.1 Current State (v1 - Temporary)

⚠️ **Current implementation queries VATSIM_ADL directly** - this is temporary and must be migrated to the dedicated SWIM_API database before heavy public use.

| Component | Status | Notes |
|-----------|--------|-------|
| API Endpoints | ✅ Functional | Querying VATSIM_ADL (temporary) |
| Authentication | ✅ Complete | Working |
| Rate Limiting | ✅ Complete | APCu-based |
| SWIM_API Database | ❌ Not Created | **BLOCKING** |
| Sync Procedure | ❌ Not Created | **BLOCKING** |

### 8.2 Migration Tasks (REQUIRED)

| Task | Priority | Effort | Status |
|------|----------|--------|--------|
| Create SWIM_API database (Azure SQL Basic) | **CRITICAL** | 1h | ❌ |
| Create swim_flights table | **CRITICAL** | 1h | ❌ |
| Create sp_Swim_SyncFromAdl | **CRITICAL** | 2h | ❌ |
| Update API endpoints to query SWIM_API | **CRITICAL** | 2h | ❌ |
| Schedule sync job (every 15 sec) | **CRITICAL** | 1h | ❌ |
| Test API against SWIM_API | High | 2h | ❌ |

### 8.3 Completed Components

| Component | Location | Status |
|-----------|----------|--------|
| Configuration | `load/swim_config.php` | ✅ Complete |
| Auth Middleware | `api/swim/v1/auth.php` | ✅ Complete |
| API Router | `api/swim/v1/index.php` | ✅ Complete |
| Flights Endpoint | `api/swim/v1/flights.php` | ⚠️ Needs DB switch |
| Flight Endpoint | `api/swim/v1/flight.php` | ⚠️ Needs DB switch |
| Positions Endpoint | `api/swim/v1/positions.php` | ⚠️ Needs DB switch |
| TMI Programs | `api/swim/v1/tmi/programs.php` | ⚠️ Has error |
| TMI Controlled | `api/swim/v1/tmi/controlled.php` | ⚠️ Needs DB switch |

---

## 9. Implementation Roadmap

### Phase 0: Infrastructure (IMMEDIATE - REQUIRED)

| Task | Owner | Status |
|------|-------|--------|
| Create Azure SQL Basic database "SWIM_API" | DevOps | ❌ |
| Create swim_flights table | Dev | ❌ |
| Create sp_Swim_SyncFromAdl procedure | Dev | ❌ |
| Update connection strings in swim_config.php | Dev | ❌ |
| Update API endpoints to use SWIM_API | Dev | ❌ |
| Test all endpoints | QA | ❌ |

### Phase 1: Foundation (Weeks 1-4) - IN PROGRESS

| Week | Focus | Status |
|------|-------|--------|
| 1-2 | Design & Architecture | ✅ Complete |
| 2-3 | Core API Implementation | ✅ Complete |
| 3-4 | **Infrastructure Migration** | 🔄 In Progress |

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
