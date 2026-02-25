# PERTI Wiki

Welcome to the **PERTI** (Plan, Execute, Review, Train, and Improve) wiki - a comprehensive traffic flow management platform for VATSIM.

**Production URL:** https://perti.vatcscc.org

---

## Quick Links

| Section | Description |
|---------|-------------|
| [[Navigation Helper]] | **NEW** - Find the right documentation quickly |
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

**v18** - Includes:
- **vATCSCC Playbook** - Pre-coordinated route play catalog with CRUD, map visualization, and shareable links
- **Canadian FIR Sectors** - 377 sector boundaries across 7 Canadian FIRs (CZYZ, CZWG, CZEG, CZUL, CZVR, CZQM, CZQX)
- **Splits Enhancements** - Scheduled splits layer with low/high/superhigh strata filtering, sector map on plan pages
- **Ops Plan** - Structured FAA-format Ops Plan with plan page sortable columns and ARTCC grouping
- **Multi-Organization Support** - Org-scoped TMI/JATOC authorization, multi-org Discord posting, CANOC/ECFMP integration
- **Traffic Management Review (TMR)** - Guided NTMO-style post-event review reports
- **NOD TMI Enhancements** - Rich TMI sidebar cards, map status layer, facility flow configs, FEA integration
- **Internationalization (i18n)** - 450+ translation keys across 4 locales (en-US, fr-CA, en-CA, en-EU), 28 PHP pages, 13+ JS modules
- **PERTI_MYSQL_ONLY Optimization** - ~98 endpoints skip Azure SQL connections (~500-1000ms faster)

See [[Changelog]] for full version history.

---

## Getting Help

- **Issues:** [GitHub Issues](https://github.com/vATCSCC/PERTI/issues)
- **Security:** See [Security Policy](https://github.com/vATCSCC/PERTI/blob/main/SECURITY.md)
- **Contact:** vATCSCC development team

---

*Last updated: 2026-02-25*
