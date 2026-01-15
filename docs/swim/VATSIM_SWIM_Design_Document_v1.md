# VATSIM SWIM (System Wide Information Management)
# Design Document v1.1

**Document Status:** IN DEVELOPMENT  
**Version:** 1.1  
**Date:** 2026-01-15  
**Author:** vATCSCC Development Team  
**Classification:** Public  

---

## Table of Contents

1. [Executive Summary](#1-executive-summary)
2. [Vision & Objectives](#2-vision--objectives)
3. [Architecture Overview](#3-architecture-overview)
4. [Unified Flight Record](#4-unified-flight-record)
5. [Integration Specifications](#5-integration-specifications)
6. [API Design](#6-api-design)
7. [Security & Authentication](#8-security--authentication)
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

---

## 2. Vision & Objectives

### 2.1 Vision Statement

*"Enable every VATSIM system to access consistent, real-time flight information, creating a seamlessly integrated virtual airspace where TMIs are automatically applied, metering is coordinated, and all participants share common situational awareness."*

### 2.2 Strategic Objectives

1. **Unified Data**: Single authoritative record for each flight
2. **Real-Time Sync**: All systems see the same data simultaneously
3. **TMI Consistency**: Ground stops and delays applied uniformly
4. **Open Ecosystem**: Enable third-party innovation

---

## 3. Architecture Overview

### 3.1 High-Level Architecture

```
PUBLISHERS                    SWIM HUB                      SUBSCRIBERS
───────────                   ────────                      ───────────
┌─────────┐                 ┌──────────┐                   ┌─────────┐
│ vATCSCC │──ADL/TMI───────▶│          │──────────────────▶│   CRC   │
│ vNAS    │──Track─────────▶│  Azure   │──────────────────▶│EuroScope│
│ CRC     │──Tags──────────▶│   SQL    │──────────────────▶│ vPilot  │
│SimTraff │──Metering──────▶│          │──────────────────▶│SimAware │
│Simulator│──Telemetry─────▶│ + MySQL  │──────────────────▶│  VAs    │
│SimBrief │──OFP───────────▶│          │──────────────────▶│Analytics│
└─────────┘                 └──────────┘                   └─────────┘
```

### 3.2 Data Sources

| Source | Database | Key Tables |
|--------|----------|------------|
| Flight Data (ADL) | Azure SQL (VATSIM_ADL) | `adl_flights` |
| Ground Stops | MySQL (PERTI) | `tmi_ground_stops` |
| GDP Programs | Azure SQL (VATSIM_ADL) | `gdp_log` |
| Airport Info | Azure SQL (VATSIM_ADL) | `apts` |

---

## 4. Unified Flight Record

### 4.1 GUFI (Globally Unique Flight Identifier)

Format: `VAT-YYYYMMDD-CALLSIGN-DEPT-DEST`

Example: `VAT-20260115-UAL123-KJFK-KLAX`

### 4.2 Data Authority Matrix

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

### 4.3 adl_flights Column Reference

All SWIM API queries use these verified column names from the `adl_flights` table:

**Position Data:**
| Column | Description |
|--------|-------------|
| `lat` | Latitude (decimal) |
| `lon` | Longitude (decimal) |
| `altitude_ft` | Altitude in feet |
| `heading_deg` | Heading in degrees |
| `groundspeed_kts` | Ground speed in knots |

**Flight Plan Data (all prefixed with `fp_`):**
| Column | Description |
|--------|-------------|
| `fp_dept_icao` | Departure airport ICAO |
| `fp_dest_icao` | Destination airport ICAO |
| `fp_alt_icao` | Alternate airport ICAO |
| `fp_altitude_ft` | Cruise altitude |
| `fp_tas_kts` | True airspeed |
| `fp_route` | Filed route |
| `fp_remarks` | Remarks field |
| `fp_rule` | Flight rules (I/V) |
| `fp_dept_artcc` | Departure ARTCC |
| `fp_dest_artcc` | Destination ARTCC |

**TMI Data:**
| Column | Description |
|--------|-------------|
| `gs_flag` | Ground stop flag (1/0) |
| `ctl_type` | Control type |
| `ctl_program` | Control program |
| `ctl_element` | Control element |
| `ctl_exempt` | Exempt flag |
| `gdp_program_id` | GDP program ID |
| `gdp_slot_index` | GDP slot index |
| `gdp_slot_time_utc` | GDP slot time |

**Time Data:**
| Column | Description |
|--------|-------------|
| `first_seen_utc` | First seen timestamp |
| `last_seen_utc` | Last seen timestamp |
| `out_utc`, `off_utc`, `on_utc`, `in_utc` | OOOI times |
| `eta_runway_utc` | ETA at runway |
| `estimated_arr_utc` | Estimated arrival |

**Distance/Time:**
| Column | Description |
|--------|-------------|
| `gcd_nm` | Great circle distance in NM |
| `ete_minutes` | Estimated time enroute |

---

## 5. Integration Specifications

### 5.1 vATCSCC/PERTI (Phase 1 - Current)
- **Role:** Authoritative for ADL/TMI
- **Frequency:** Every 15 seconds (via ADL refresh)
- **Data:** Flights, positions, TMI status

### 5.2 vNAS (Phase 2 - Future)
- **Role:** Authoritative for Track/ATC
- **Frequency:** 1-5 Hz
- **Protocol:** WebSocket/Event Hub

### 5.3 CRC (Phase 2 - Future)
- **Role:** Bidirectional ATC client
- **Inbound:** TMI status, metering
- **Outbound:** Track, tags, handoffs

### 5.4 SimTraffic (Phase 2 - Future)
- **Role:** Metering data provider
- **Data:** STA, sequence, delays

### 5.5 Simulator Telemetry (Phase 3 - Future)
- **Protocol:** To be determined
- **Data:** Position, FMS, fuel, autopilot

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
| GET | `/api/swim/v1/flights/{gufi}` | ⏳ Pending | Get single flight by GUFI |
| GET | `/api/swim/v1/positions` | ✅ Done | Bulk positions (GeoJSON) |
| GET | `/api/swim/v1/tmi/programs` | ✅ Done | Active TMI programs |
| GET | `/api/swim/v1/tmi/controlled` | ⏳ Pending | TMI-controlled flights |
| POST | `/api/swim/v1/ingest/adl` | ✅ Done | Ingest ADL data |
| POST | `/api/swim/v1/ingest/track` | ⏳ Pending | Ingest track data |
| POST | `/api/swim/v1/ingest/metering` | ⏳ Pending | Ingest metering data |
| WS | `/api/swim/v1/stream` | ⏳ Pending | Real-time updates |

---

## 7. Security & Authentication

### 7.1 Authentication Flow

1. Client sends request with `Authorization: Bearer {api_key}` header
2. `auth.php` middleware validates key format and tier
3. Key lookup in `swim_api_keys` table (or fallback for development)
4. Rate limit check via APCu cache
5. Access logged to `swim_audit_log`

### 7.2 CORS Configuration

Allowed origins:
- `https://perti.vatcscc.org`
- `https://vatcscc.org`
- `https://swim.vatcscc.org`
- `http://localhost:3000` (development)
- `http://localhost:8080` (development)

---

## 8. Implementation Status

### 8.1 Completed (Phase 1 Foundation)

| Component | Location | Status |
|-----------|----------|--------|
| Configuration | `load/swim_config.php` | ✅ Complete |
| Auth Middleware | `api/swim/v1/auth.php` | ✅ Complete |
| API Router | `api/swim/v1/index.php` | ✅ Complete |
| Flights Endpoint | `api/swim/v1/flights.php` | ✅ Complete |
| Positions Endpoint | `api/swim/v1/positions.php` | ✅ Complete |
| TMI Programs | `api/swim/v1/tmi/programs.php` | ✅ Complete |
| ADL Ingest | `api/swim/v1/ingest/adl.php` | ✅ Complete |
| DB Migration | `database/migrations/swim/001_swim_tables.sql` | ✅ Complete |

### 8.2 Pending (Phase 1 Remaining)

| Component | Priority | Notes |
|-----------|----------|-------|
| Single flight endpoint (`/flights/{gufi}`) | High | Next sprint |
| TMI controlled endpoint | High | Next sprint |
| Track ingest endpoint | Medium | For vNAS integration |
| Metering ingest endpoint | Medium | For SimTraffic integration |
| API Reference documentation | High | Needed for partners |

### 8.3 Future (Phase 2+)

| Component | Phase | Notes |
|-----------|-------|-------|
| WebSocket server | 2 | Real-time distribution |
| Event publishing | 2 | Hook into ADL refresh |
| vNAS integration | 2 | Track data |
| CRC plugin | 2 | Bidirectional sync |
| SimTraffic integration | 2 | Metering data |
| Telemetry collection | 3 | Simulator data |

---

## 9. Implementation Roadmap

### Phase 1: Foundation (Weeks 1-4) - CURRENT

| Week | Focus | Status |
|------|-------|--------|
| 1-2 | Design & Architecture | ✅ Complete |
| 2-3 | Core API Implementation | ✅ Complete |
| 3-4 | Database Migration & Testing | 🔄 In Progress |

### Phase 2: Real-Time Distribution (Weeks 5-8)

| Week | Focus |
|------|-------|
| 5-6 | WebSocket server, subscription management |
| 7-8 | Event publishing, vNAS integration |

### Phase 3: Telemetry Integration (Weeks 9-12)

| Week | Focus |
|------|-------|
| 9-10 | Telemetry schema, IoT endpoint |
| 11-12 | SimConnect integration, pilot client spec |

### Phase 4: Partner Integrations (Weeks 13-16)

| Week | Focus |
|------|-------|
| 13-14 | SimBrief webhook, VA integrations |
| 15-16 | CRC plugin, EuroScope integration |

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
│   ├── positions.php         # GeoJSON positions
│   ├── ingest/
│   │   └── adl.php           # ADL ingest
│   └── tmi/
│       └── programs.php      # TMI programs
├── database/migrations/swim/
│   └── 001_swim_tables.sql   # Database schema
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
- [PERTI Status](../STATUS.md) - System status dashboard

---

**Contact:** dev@vatcscc.org  
**Repository:** VATSIM PERTI/PERTI

*End of Document*
