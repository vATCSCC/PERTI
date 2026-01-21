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
- **Reroutes** - Reroute authoring and compliance monitoring
- **Splits** - Sector/position split configuration
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
| Mapping | MapLibre GL JS, Leaflet |
| Charts | Chart.js |
| Databases | MySQL, Azure SQL |
| Hosting | Azure App Service |
| Auth | VATSIM Connect (OAuth) |
| Weather | IEM NEXRAD/MRMS tiles |

---

## Current Version

**v17** - Includes:
- **ATFM Training Simulator** (NEW) - TMU training with Node.js flight engine
- Airport demand analysis with weather-aware rate suggestions
- Normalized runway configuration schema
- VATSIM ATIS import with weather extraction
- Multi-level rate suggestion algorithm

See [[Changelog]] for full version history.

---

## Getting Help

- **Issues:** [GitHub Issues](https://github.com/vATCSCC/PERTI/issues)
- **Security:** See [Security Policy](https://github.com/vATCSCC/PERTI/blob/main/SECURITY.md)
- **Contact:** vATCSCC development team

---

*Last updated: 2026-01-21*
