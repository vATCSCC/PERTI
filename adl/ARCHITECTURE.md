# ADL Database Redesign: Complete Architecture

**Version:** 2.0  
**Date:** January 2025  
**Status:** Design Document

---

## 1. Executive Summary

### 1.1 Project Goals

Transform the monolithic `adl_flights` table into a normalized, GIS-enabled architecture that:

- **Normalizes** flight data into purpose-specific tables for better performance
- **Parses routes** into GIS-native GEOGRAPHY for spatial queries
- **Extracts SimBrief data** including runways, step climbs, and cost index
- **Optimizes refresh cycles** through tiered async parsing based on operational relevance
- **Reduces costs** through intelligent workload distribution

### 1.2 Key Metrics

| Metric | Current (Jan 2026) | Notes |
|--------|-------------------|-------|
| Refresh time (typical) | **~5.6 sec** | 44% faster than legacy |
| Refresh time (peak) | **~8.5 sec** | 53% faster than legacy |
| Spatial query capability | **Full GIS** | GEOGRAPHY-enabled |
| SimBrief data extraction | **Runways, steps, CI** | Automatic parsing |
| Azure ADL monthly cost | **~$2,100** | Hyperscale Serverless (3/16 vCores) |

**Infrastructure Note (Updated January 21, 2026):**
VATSIM_ADL runs on Azure SQL Hyperscale Serverless (HS_S_Gen5_16) with 3 min/16 max vCores.
Configuration was right-sized from 4/24 vCores, saving ~$1,140/month while maintaining 54% worker headroom during peak VATSIM events.

---

## 2. Table Structure

| # | Table | Purpose | Update Frequency |
|---|-------|---------|------------------|
| 0 | `adl_flight_core` | Master registry, surrogate keys | Every refresh |
| 1 | `adl_flight_position` | Real-time position + spatial | Every refresh |
| 2 | `adl_flight_plan` | Route + GIS geometry | On FP change |
| 2B | `adl_flight_waypoints` | Parsed route waypoints | On FP change |
| 2C | `adl_flight_stepclimbs` | Step climb records | On FP change |
| 3 | `adl_flight_times` | 40+ TFMS time fields | Every refresh |
| 4 | `adl_flight_trajectory` | Position history (15s) | Every refresh |
| 5 | `adl_flight_tmi` | TMI controls | Every refresh |
| 6 | `adl_flight_legs` | Multi-leg flights | On change |
| 7 | `adl_flight_aircraft` | Aircraft info | On change |
| 8 | `adl_flight_changelog` | Audit trail | Every refresh |

---

## 3. Tiered Parsing Strategy

### 3.1 Tier Definitions

| Tier | Region | Interval | Condition |
|------|--------|----------|-----------|
| **0** | US/CA/LatAm/Caribbean | 15 sec | <500nm from CONUS |
| **1** | US/CA oceanic approaches | 30 sec | >500nm, in NA oceanic |
| **2** | Europe, South America | 1 min | Non-US domestic |
| **3** | Africa, Middle East | 2 min | Low priority |
| **4** | Asia, Oceania, distant | 5 min | Lowest priority |

### 3.2 Distance Demotion Rules

| Rule | Condition | Effect |
|------|-----------|--------|
| **Base** | Both O/D in US/CA/LatAm/Caribbean | Always Tier 0 |
| **Rule 1** | One O/D + >500nm from CONUS | Demote to Tier 1 |
| **Rule 2** | Rule 1 + in ZAK + NOT AK/HI dest | Demote to Tier 4 |
| **Rule 3** | Rule 1 + NOT in US/CA/LatAm oceanic | Demote to Tier 4 |

### 3.3 Progressive Promotion Example

```
EGLL → KJFK Flight:

Position                     Distance    Tier    Latency
─────────────────────────────────────────────────────────
Departing EGLL               3,000 nm    4       5 min
Mid-Atlantic (Gander)        1,500 nm    1       30 sec  ← Promoted
Off Nova Scotia                400 nm    0       15 sec  ← Promoted
```

---

## 4. GIS Capabilities

### 4.1 Spatial Query Examples

```sql
-- Flights crossing ZNY airspace
SELECT callsign FROM vw_adl_flights
WHERE route_geometry.STIntersects(@zny_boundary) = 1;

-- Flights within FCA
SELECT callsign, 
       route_geometry.STIntersection(@fca).STLength() / 1852.0 AS nm_in_fca
FROM vw_adl_flights
WHERE route_geometry.STIntersects(@fca) = 1;

-- Flights on specific airway
SELECT DISTINCT callsign
FROM adl_flight_waypoints
WHERE on_airway = 'J60';
```

### 4.2 SimBrief Data Extraction

- **Runway specs**: `dep_runway`, `arr_runway` with source tracking
- **Step climbs**: Parsed from route (`FIX/N0450F350`) and remarks
- **Cost index**: Extracted from `CI###` patterns
- **OFP ID**: SimBrief flight plan identifier

---

## 5. Implementation Status

- [x] Core table design
- [x] Migration scripts (5 files)
- [x] Tier assignment function
- [x] Parse queue procedures
- [ ] Full GIS route parsing
- [ ] Reference data import
- [ ] API endpoint updates
- [ ] Data migration

---

## 6. Files

```
adl/
├── migrations/
│   ├── 001_adl_core_tables.sql
│   ├── 002_adl_times_trajectory.sql
│   ├── 003_adl_waypoints_stepclimbs.sql
│   ├── 004_adl_reference_tables.sql
│   └── 005_adl_views_seed_data.sql
├── procedures/
│   ├── fn_GetParseTier.sql
│   └── sp_ParseQueue.sql
└── README.md
```
