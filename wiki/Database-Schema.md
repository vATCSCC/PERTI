# Database Schema

PERTI uses two databases: MySQL for application data and Azure SQL for flight/ADL data.

---

## MySQL (PERTI Application)

### Core Tables

| Table | Purpose |
|-------|---------|
| `users` | User preferences and settings |
| `plans` | Planning worksheets |
| `schedules` | Staff scheduling |
| `comments` | Plan review comments |

### TMI Tables

| Table | Purpose |
|-------|---------|
| `initiatives` | TMI initiative definitions |
| `ground_stops` | Ground stop programs (legacy) |
| `gdp_programs` | Ground delay programs |
| `reroutes` | Reroute definitions |

### JATOC Tables

| Table | Purpose |
|-------|---------|
| `incidents` | ATC incidents |
| `incident_updates` | Incident timeline |
| `incident_types` | Incident categories |

### Configuration Tables

| Table | Purpose |
|-------|---------|
| `splits_areas` | Sector area definitions |
| `splits_configs` | Saved configurations |
| `advisories` | DCC advisories |

---

## Azure SQL (VATSIM_ADL)

### Flight Tables

| Table | Purpose |
|-------|---------|
| `adl_flights` | Current flight state |
| `adl_flights_history` | Historical snapshots |
| `adl_trajectories` | Position history |
| `adl_parse_queue` | Routes awaiting parsing |
| `adl_parsed_routes` | Expanded route waypoints |

### TMI Tables (NTML)

| Table | Purpose |
|-------|---------|
| `ground_stop_programs` | Ground stop definitions |
| `ground_stop_flights` | Affected flights with EDCTs |
| `gdp_programs` | GDP definitions |
| `gdp_slots` | GDP slot allocations |

### Reference Tables

| Table | Purpose |
|-------|---------|
| `airports` | Airport data |
| `navaids` | Navigation aids |
| `waypoints` | Fix/waypoint data |
| `airways` | Airway definitions |
| `sids` | Standard Instrument Departures |
| `stars` | Standard Terminal Arrivals |

### Boundary Tables

| Table | Purpose |
|-------|---------|
| `artcc_boundaries` | ARTCC geographic boundaries |
| `sector_boundaries` | Sector boundaries |
| `tracon_boundaries` | TRACON boundaries |

### Weather Tables

| Table | Purpose |
|-------|---------|
| `adl_weather_alerts` | Active SIGMETs/AIRMETs |
| `adl_atis` | ATIS data |
| `wind_data` | Upper wind forecasts |

### Airport Configuration (v16)

| Table | Purpose |
|-------|---------|
| `airport_config` | Runway configurations |
| `airport_config_runway` | Runways per config |
| `airport_config_rate` | Rates per config |
| `runway_in_use` | Current runway assignments |
| `manual_rate_override` | Manual rate overrides |
| `rate_history` | Rate change audit trail |

### ATFM Simulator Reference (v17)

| Table | Purpose |
|-------|---------|
| `sim_ref_carrier_lookup` | 17 US carriers with IATA/ICAO codes |
| `sim_ref_route_patterns` | 3,989 O-D routes with hourly patterns |
| `sim_ref_airport_demand` | 107 airports with demand curves |

---

## Key Relationships

### Flight → Route

```
adl_flights.id ──▶ adl_parsed_routes.flight_id
```

### Ground Stop → Flights

```
ground_stop_programs.id ──▶ ground_stop_flights.program_id
```

### Airport → Configuration

```
airports.icao ──▶ airport_config.airport
airport_config.id ──▶ airport_config_runway.config_id
airport_config.id ──▶ airport_config_rate.config_id
```

---

## Indexes

Critical indexes for performance:

| Table | Index | Purpose |
|-------|-------|---------|
| `adl_flights` | `idx_destination` | Arrival queries |
| `adl_flights` | `idx_departure` | Departure queries |
| `adl_flights` | `idx_phase` | Phase filtering |
| `adl_flights_history` | `idx_snapshot_utc` | Historical queries |
| `adl_parsed_routes` | `idx_flight_seq` | Route ordering |

---

## Migrations

Migrations are located in:
- `database/migrations/` - MySQL
- `adl/migrations/` - Azure SQL

Apply in numerical order within each category.

---

## See Also

- [[Architecture]] - System overview
- [[Data Flow]] - Data pipelines
- [[Deployment]] - Database setup
