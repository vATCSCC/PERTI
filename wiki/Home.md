# PERTI Wiki

Welcome to the **PERTI** (Plan, Execute, Review, Train, and Improve) wiki - a comprehensive traffic flow management platform for VATSIM.

**Production URL:** https://perti.vatcscc.org

---

## Comprehensive Guides

These in-depth documents cover everything needed to deploy and understand PERTI:

| Guide | Description |
|-------|-------------|
| [Deployment Guide](../blob/main/docs/operations/DEPLOYMENT_GUIDE.md) | Full deployment walkthrough: Azure provisioning, 7-database schema deployment, stored procedures, reference data import, daemon setup, i18n, multi-org configuration, code standards, and operational procedures |
| [Computational Reference](../blob/main/docs/reference/COMPUTATIONAL_REFERENCE.md) | Complete algorithm documentation: ADL ingest cycle, ETA calculation, route parsing, boundary detection, GDP/GS slot assignment, TMI compliance, trajectory tiering, and performance tuning |

---

## Quick Links

| Section | Description |
|---------|-------------|
| [[Navigation Helper]] | Find the right documentation quickly |
| [[Getting Started]] | Installation, prerequisites, and first steps |
| [[Architecture]] | System design, data flow, and components |
| [[Configuration]] | Environment setup and configuration options |
| [[API Reference]] | Complete API documentation |
| [[Database Schema]] | Tables, columns, and relationships |
| [[Deployment]] | Azure deployment and CI/CD pipeline |
| [[Contributing]] | How to contribute to PERTI |
| [[FAQ]] | Frequently asked questions |

---

## Features Overview

### Public Tools (No Login Required)

- **JATOC** - Joint Air Traffic Operations Command incident monitor
- **NOD** - NAS Operations Dashboard with active TMIs and advisories

### Traffic Management (Authenticated)

- **GDT** - Ground Delay Tool with FSM-style GDP interface
- **Route Plotter** - TSD-style live flight map with weather radar
- **Playbook** - Pre-coordinated route play catalog with map visualization
- **TMI Publisher** - NTML/advisory publishing to Discord with multi-org support
- **Reroutes** - Reroute authoring and compliance monitoring
- **Splits** - Sector/position split configuration with strata filtering and scheduled splits
- **Demand Analysis** - Airport demand/capacity visualization with rate suggestions

### Training (Authenticated)

- **ATFM Simulator** - Training simulator for TMU personnel (NEW v17)

### Planning & Scheduling (Authenticated)

- **Plan** - Traffic management planning worksheets
- **Schedule** - Staff scheduling
- **Data Sheet** - Operational data sheets
- **Review** - Plan review with StatSim integration

---

## System Scale (as of April 2026)

| Metric | Count |
|--------|-------|
| Total flights tracked | 1,625,115 |
| Flight plans parsed | 1,620,920 |
| Route waypoints extracted | 9,295,153 |
| Boundary crossings predicted | 20,548,518 |
| Navigation fixes | 268,998 |
| Airports in database | 27,231 (ADL) / 37,527 (GIS) |
| Airlines tracked | 228 |
| ARTCC/sector boundaries | 3,033 (ADL) / 1,004 ARTCC + 1,023 TRACON (GIS) |
| Airways | 1,515 |
| DPs/STARs | ~77,000 (NASR + international CIFP) |
| Coded Departure Routes | 41,138 |
| Playbook routes | 55,682 |
| TMI programs issued | 172 (139 GDP, 29 GS, 4 AFP) |
| TMI advisories published | 1,020 |
| Reroutes defined | 268 |
| vNAS facilities imported | 782 |
| vNAS positions imported | 3,990 |
| PERTI plans created | 239 |
| Registered users | 25 |
| Translation keys | 7,276 (en-US) |
| Supported locales | 4 (en-US, fr-CA, en-CA, en-EU) |

*Data reflects cumulative totals as of April 2026. System hibernating (re-entered 2026-03-30).*

---

## Technology Stack

| Component | Technology |
|-----------|------------|
| Backend | PHP 8.2+ |
| Frontend | JavaScript (ES6+), jQuery, Bootstrap 4.5 |
| Mapping | MapLibre GL JS |
| Charts | Chart.js |
| Databases | MySQL, Azure SQL, PostgreSQL/PostGIS |
| Hosting | Azure App Service |
| Auth | VATSIM Connect (OAuth) |
| Weather | IEM NEXRAD/MRMS tiles |

---

## Current Version

**v20** (April 2026) - Includes:

> **System Status: HIBERNATING** (re-entered 2026-03-30). Only always-on daemons running, Azure resources downscaled.

- **vNAS Reference Sync** - 14 vNAS tables imported from CRC facility JSON files (782 facilities, 3,990 positions, 15,007 video maps)
- **International CIFP Integration** - 62,000+ DPs/STARs from 9,561 international airports via X-Plane 12 / Navigraph CIFP data
- **AIRAC 2603 with Supersession Tracking** - `is_superseded`, `superseded_cycle`, `superseded_reason` on 5 tables across REF/ADL/PostGIS
- **TMI Operations & Analytics Platform** - 10 tables across 3 layers (unified log, delay attribution, facility stats) with SWIM API endpoints
- **GDP Algorithm Redesign** (Phases 1-4 complete) - CASA-FPFS + RBD hybrid slot assignment, compression, reoptimization, reversal metrics, anti-gaming flags
- **Playbook Spatial Validation** - PostGIS expand_airway() with 2000km gap check, expand_route() with 7400km distance cap
- **Airport Reference Systems** - Taxi reference (3,628 airports) and connect-to-push reference (5,552 airports) with FAA p5-p15 methodology
- **vATCSCC Playbook** - Pre-coordinated route play catalog with spatial validation, compound filters, geometry backfill
- **Splits Enhancements** - Scheduled splits, strata filtering, international FIR code support (NVARCHAR(4))
- **Multi-Organization Support** - Org-scoped TMI/JATOC authorization, multi-org Discord posting, CANOC/ECFMP integration
- **Internationalization (i18n)** - 7,276 translation keys across 4 locales (en-US, fr-CA, en-CA, en-EU), 30 PHP pages, 45 JS modules
- **PERTI_MYSQL_ONLY Optimization** - ~98 endpoints skip Azure SQL connections (~500-1000ms faster)

See [[Changelog]] for full version history.

---

## Getting Help

- **Issues:** [GitHub Issues](https://github.com/vATCSCC/PERTI/issues)
- **Security:** See [Security Policy](https://github.com/vATCSCC/PERTI/blob/main/SECURITY.md)
- **Contact:** vATCSCC development team

---

*Last updated: 2026-04-14*
