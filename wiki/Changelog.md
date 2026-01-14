# Changelog

This document tracks significant changes to PERTI across versions.

---

## Version 17 (Current)

*Released: January 2026*

### New Features

#### ATFM Training Simulator

- **Simulator Page** (`/simulator.php`) - TMU training environment
- Node.js flight engine with realistic physics simulation
- Practice GS/GDP/AFP/MIT/Reroute TMI decisions
- Reference data: 3,989 O-D route patterns, 107 airports, 17 carriers
- Web-based interface with MapLibre visualization

### API Additions (v17)

- `GET /api/simulator/navdata.php` - Navigation data for routing
- `GET/POST /api/simulator/engine.php` - Engine control
- `GET /api/simulator/routes.php` - Route pattern data
- `GET/POST /api/simulator/traffic.php` - Traffic generation

### Database Changes (v17)

- New tables: `sim_ref_carrier_lookup`, `sim_ref_route_patterns`, `sim_ref_airport_demand`
- Data sourced from BTS On-Time Performance (20.6M flight records)

---

## Version 16

*Released: January 2026*

### New Features

#### Demand Analysis System

- **Demand Page** (`/demand.php`) - Airport demand visualization
- Weather-aware rate suggestions with confidence scoring
- Manual rate override support with time windows
- Multi-level rate suggestion algorithm

#### Airport Configuration & ATIS

- Normalized runway configuration schema
- VATSIM ATIS import with weather extraction
- Runway-in-use detection from ATIS parsing
- Flight-track-based runway detection as fallback
- Rate change audit trail

### API Additions

- `GET /api/demand/airports.php` - Airport list
- `GET /api/demand/summary.php` - Demand summary
- `GET /api/demand/rates.php` - Rate data
- `POST /api/demand/override.php` - Manual override

### Database Changes

- Migrations 079-091 (Airport config, ATIS, rates)
- New tables: `airport_config`, `airport_config_runway`, `airport_config_rate`
- New tables: `runway_in_use`, `manual_rate_override`, `rate_history`

---

## Version 15

*Released: December 2025*

### New Features

#### GDT Ground Stop NTML Architecture

- Complete program lifecycle management
- Stored procedure-based workflow
- Pop-up flight detection
- EDCT issuance and management

#### Weather Radar Integration

- IEM NEXRAD/MRMS tile integration
- Multiple color table options
- Configurable opacity and layers

#### SUA/TFR Display

- Special Use Airspace boundaries on map
- TFR visualization
- Active/inactive filtering

#### Initiative Timeline

- Gantt-style TMI visualization
- Interactive timeline navigation
- Multiple initiative tracking

### API Changes

- New GS API endpoints (`/api/tmi/gs/*`)
- Enhanced flight data responses
- WebSocket support preparation

### Database Changes

- NTML schema (`tmi/001_ntml_schema.sql`)
- GS procedures (`tmi/002_gs_procedures.sql`)
- GDT views (`tmi/003_gdt_views.sql`)
- Phase column unification in ADL

---

## Version 14

*Released: October 2025*

### New Features

- ETA calculation with aircraft performance
- Trajectory logging and visualization
- Zone detection (OOOI) implementation
- Boundary crossing detection

### Improvements

- Route parsing accuracy enhancements
- SimBrief flight plan integration
- Performance optimizations for large events

---

## Version 13

*Released: August 2025*

### New Features

- JATOC incident management
- NOD dashboard
- Public route sharing
- Discord webhook integration

### API Changes

- JATOC CRUD endpoints
- NOD data endpoints
- Public routes API

---

## Version 12

*Released: June 2025*

### New Features

- Splits sector configuration
- Route Plotter TSD interface
- Weather alert integration

### Improvements

- MapLibre GL JS migration
- Performance improvements
- Mobile responsiveness

---

## Version 11

*Released: April 2025*

### New Features

- Ground Delay Program (GDP) support
- EDCT management
- Enhanced planning worksheets

---

## Version 10

*Released: February 2025*

### New Features

- Initial PERTI platform release
- VATSIM OAuth integration
- Basic planning tools
- Ground Stop prototype

---

## Migration Notes

### Upgrading to v16

1. Apply migrations 079-091 to Azure SQL
2. Update ATIS daemon to latest version
3. Configure demand page access
4. Review rate suggestion settings

### Upgrading to v15

1. Apply TMI migrations (001-003)
2. Update GDT frontend components
3. Configure weather tile sources
4. Test GS workflow end-to-end

---

## Deprecations

### v16

- Legacy GDP endpoints (use new GDT API)
- Old demand calculation views (replaced by procedures)

### v15

- `flight_status` column (replaced by `phase`)
- Legacy ground stop tables (migrated to NTML)

---

## Upcoming (Planned)

### v18 (Planned)

- ATFM Simulator Phase 1 (GS/GDP implementation)
- Enhanced GDP slot management
- Reroute compliance automation
- StatSim v2 integration
- Performance dashboard

---

## See Also

- [[Getting Started]] - Setup guide
- [[Deployment]] - Deployment procedures
- [[Architecture]] - System overview
