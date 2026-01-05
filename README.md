# vATCSCC PERTI - Virtual Air Traffic Control System Command Center

## Overview

PERTI is a comprehensive web-based traffic flow management platform for VATSIM (Virtual Air Traffic Control Simulation). It provides professional-grade tools for virtual air traffic controllers to manage traffic flow, monitor incidents, and coordinate operations.

**Production URL:** https://vatcscc.azurewebsites.net

---

## 🗺️ Site Map & Functionality

### Public Pages

| Page | URL | Description |
|------|-----|-------------|
| **Home** | `/` | Landing page and dashboard |
| **JATOC** | `/jatoc.php` | AWO Incident Monitor (no login required) |
| **NOD** | `/nod.php` | NAS Operations Dashboard (no login required) |
| **Privacy Policy** | `/privacy.php` | Privacy policy |

### Traffic Management Tools (Authentication Required)

| Page | URL | Description |
|------|-----|-------------|
| **GDT** | `/gdt.php` | Ground Delay Tool - FSM-style GDP/GS interface with modeling |
| **Route Plotter** | `/route.php` | TSD-style live flight map with route plotting |
| **Splits** | `/splits.php` | Sector/position split configuration |
| **Demand** | `/demand.php` | FSM-style demand visualization (in development) |
| **SUA** | `/sua.php` | Special Use Airspace display |

### Planning & Scheduling (Authentication Required)

| Page | URL | Description |
|------|-----|-------------|
| **Plan** | `/plan.php` | Traffic management planning worksheets |
| **Schedule** | `/schedule.php` | Staff scheduling |
| **Data Sheet** | `/sheet.php` | Operational data sheets (`/data.php` redirects here) |
| **Review** | `/review.php` | Plan review and comments |

---

## 🔧 Key Features

### JATOC - Joint Air Traffic Operations Command
*AWO Incident Monitor* - Publicly accessible at `/jatoc.php`

- **Incident Tracking:** Monitor ATC Zero, ATC Alert, ATC Limited, and Non-Responsive incidents
- **Operations Level:** Real-time 1/2/3 status with color-coded display
- **Map Visualization:** Interactive MapLibre map with ARTCC/TRACON boundaries
- **POTUS/Space Calendar:** Track special operations activities
- **Personnel Roster:** JATOC position assignments
- **Incident Search:** Multi-criteria historical search

### NOD - NAS Operations Dashboard
*Consolidated Monitoring View* - Publicly accessible at `/nod.php`

- **System-Wide Overview:** Aggregated view of all active TMIs
- **Live Flight Display:** TSD-style aircraft visualization
- **Split Configuration:** Current sector splits across facilities
- **Weather Integration:** Radar overlay and METAR display
- **Advisory Feed:** Active advisories and alerts

### GDT - Ground Delay Tool
*FSM-Style TMI Interface* - at `/gdt.php`

- **Ground Stops (GS):** Preview, simulate, and apply ground stops
- **Ground Delay Programs (GDP):** EDCT/CTA slot allocation with modeling
- **Rate Management:** Configure airport acceptance rates
- **Flight List:** Preview affected flights with EDCT assignments
- **Status Bar:** Real-time flight statistics by phase/region
- **FSM Compliance:** Interface modeled after FAA FSM

### Route Plotter
*TSD-Style Flight Visualization* - at `/route.php`

- **Live Flights:** Real-time VATSIM flight display with TSD symbology
- **Route Plotting:** Multi-route plotting with DP/STAR resolution
- **Public Routes:** Globally shared route advisories
- **Advisory Builder:** Generate TFMS-style route advisories
- **Export:** GeoJSON, KML, GeoPackage export formats
- **Playbook/CDR Search:** FAA playbook and CDR route lookup

### Splits
*Sector Configuration* - at `/splits.php`

- **Area Management:** Define and save sector groupings
- **Configuration Presets:** Reusable split configurations
- **Active Splits:** Real-time position assignments
- **Map Visualization:** MapLibre-based sector display with color coding

### Demand Visualization
*FSM-Style Demand Analysis* - at `/demand.php` (in development)

- **Airport Demand:** Single airport arrival/departure analysis
- **System Demand:** Multi-airport comparative view
- **Element Demand:** Sector and fix loading analysis
- **Historical Analysis:** Analog situation finder

---

## 📁 Directory Structure

```
wwwroot/
├── api/                    # API endpoints
│   ├── adl/               # ADL flight data APIs
│   ├── data/              # Reference data APIs
│   ├── demand/            # Demand visualization APIs
│   ├── jatoc/             # JATOC incident APIs
│   ├── mgt/               # Management CRUD APIs
│   ├── routes/            # Public routes APIs
│   ├── splits/            # Splits APIs
│   ├── statsim/           # StatSim integration APIs
│   ├── tmi/               # TMI workflow APIs (GS/GDP)
│   └── user/              # User-specific APIs
│
├── assets/
│   ├── css/               # Stylesheets
│   ├── data/              # Navigation data (CSV)
│   │   ├── ARTCCs/        # Per-ARTCC sector data
│   │   └── backups/       # Navdata backups
│   ├── geojson/           # Map boundary files
│   ├── img/               # Images and icons
│   ├── js/                # JavaScript modules
│   └── vendor/            # Third-party libraries
│
├── database/
│   └── migrations/        # SQL migration scripts
│
├── includes/              # PHP includes
├── load/                  # Shared PHP includes
│   ├── config.php         # Configuration
│   ├── connect.php        # Database connections
│   ├── header.php         # HTML head
│   ├── nav.php            # Navigation
│   └── footer.php         # Footer
│
├── login/                 # VATSIM OAuth login
├── scripts/               # Background scripts
├── sessions/              # Session handling
└── sql/                   # Additional SQL scripts
```

---

## 🗄️ Databases

### MySQL
- Plans, schedules, configs, comments
- Ground stop definitions

### Azure SQL (ADL)
- Live flight state (`dbo.adl_flights`)
- TMI workflows (GS/GDP)
- Splits configurations
- JATOC incidents
- Flight history
- Demand snapshots

---

## 📊 API Quick Reference

### ADL Flight Data
- `GET /api/adl/current.php` - Current flights snapshot
- `GET /api/adl/flight.php?id=xxx` - Single flight lookup
- `GET /api/adl/stats.php` - Flight statistics

### TMI Operations
- `POST /api/tmi/gs_*.php` - Ground Stop operations
- `POST /api/tmi/gdp_*.php` - GDP operations

### JATOC
- `GET /api/jatoc/incidents.php` - List incidents
- `GET /api/jatoc/incident.php?id=xxx` - Get incident
- `POST /api/jatoc/incident.php` - Create incident
- `PUT /api/jatoc/incident.php?id=xxx` - Update incident

### Splits
- `GET /api/splits/areas.php` - Area definitions
- `GET /api/splits/configs.php` - Configurations
- `GET /api/splits/active.php` - Active splits

### Public Routes
- `GET /api/routes/public.php` - List public routes
- `POST /api/routes/public_post.php` - Create route

### Demand (Planned)
- `GET /api/demand/airport.php` - Single airport demand
- `GET /api/demand/system.php` - Multi-airport overview
- `GET /api/demand/flights.php` - Flight list drill-down

---

## 🔐 Authentication

PERTI uses VATSIM Connect (OAuth) for authentication. Users log in via `/login/` which redirects to VATSIM's OAuth server and returns to `/login/callback.php`.

Session data is stored in PHP sessions and includes:
- `VATSIM_CID` - VATSIM CID
- `VATSIM_FIRST_NAME` / `VATSIM_LAST_NAME` - User name

**Note:** JATOC and NOD viewing is public; editing requires DCC role assignment.

---

## 🛠️ Maintenance Scripts

| Script | Purpose |
|--------|---------|
| `scripts/vatsim_adl_daemon.php` | Refreshes flight data from VATSIM every ~15s |
| `scripts/refresh_vatsim_boundaries.php` | Updates ARTCC/TRACON boundary GeoJSON |
| `scripts/update_playbook_routes.py` | Updates playbook route CSV from FAA |
| `nasr_navdata_updater.py` | Updates navigation data from FAA NASR |

---

## 📝 Configuration

Main configuration in `load/config.php`:
- Database credentials
- VATSIM OAuth settings
- ADL SQL connection settings
- Site configuration

Example config template: `load/config.example.php`

---

## 🔗 External Data Sources

- **VATSIM API** - Live flight data
- **FAA NFDC** - NASR navigation data
- **Iowa Environmental Mesonet** - Weather radar
- **VATSpy/SimAware** - Boundary data
- **FAA Playbook** - Route playbooks
- **VATUSA** - Events integration

---

## 📚 Documentation

For detailed technical documentation, see:
- `assistant_codebase_index_v13.md` - Comprehensive codebase index
- `scripts/README.md` - Script documentation
- `scripts/README_boundaries.md` - Boundary refresh documentation
- Database migrations in `database/migrations/`

---

## ⚙️ Technology Stack

- **Backend:** PHP 7.4+
- **Frontend:** JavaScript (ES6+), jQuery, Bootstrap 4.5
- **Mapping:** MapLibre GL JS, Leaflet
- **Charts:** Chart.js, D3.js, Apache ECharts
- **Databases:** MySQL, Azure SQL
- **Hosting:** Azure App Service
- **Auth:** VATSIM Connect (OAuth)

---

## 📞 Contact

For issues or questions about PERTI, contact the vATCSCC development team.

---

*Last updated: 2026-01-05*
