# VATSIM_ADL Database Schema Reference
## adl_flights Table

**Generated:** 2026-01-15  
**Total Columns:** 158  
**Database:** Azure SQL (VATSIM_ADL)

---

## Column Reference

### Identity & Core (Positions 1-19)
| Position | Column | Type | Length | Description |
|----------|--------|------|--------|-------------|
| 1 | id | bigint | | Primary key |
| 2 | cid | int | | VATSIM CID |
| 3 | callsign | nvarchar | 16 | Flight callsign |
| 4 | flight_id | nvarchar | 32 | VATSIM flight ID |
| 5 | flight_key | nvarchar | 64 | Unique flight identifier |
| 6 | phase | nvarchar | 16 | Flight phase (PREFLIGHT, PUSHBACK, TAXI_OUT, TAKEOFF, CLIMB, CRUISE, DESCENT, APPROACH, TAXI_IN, ARRIVED) |
| 7 | last_source | nvarchar | 16 | Last data source |
| 8 | is_active | bit | | Active flight flag |
| 9 | adl_date | date | | ADL date |
| 10 | adl_time | time | | ADL time |
| 11 | aircraft_type | nvarchar | 8 | ICAO aircraft type |
| 12 | ac_cat | nvarchar | 32 | Aircraft category |
| 13 | weight_class | nvarchar | 16 | Wake turbulence category |
| 14 | major_carrier | nvarchar | 8 | Major carrier code |
| 15 | cdm_participant | bit | | CDM participant flag |
| 16 | user_category | nvarchar | 16 | User category |
| 17 | first_seen_utc | datetime2 | | First position timestamp |
| 18 | last_seen_utc | datetime2 | | Last position timestamp |
| 19 | logon_time_utc | datetime2 | | VATSIM logon time |

### Flight Plan (Positions 20-42)
| Position | Column | Type | Length | Description |
|----------|--------|------|--------|-------------|
| 20 | fp_rule | nvarchar | 1 | Flight rules (I/V/Y/Z) |
| 21 | fp_dept_icao | char | 4 | Departure airport ICAO |
| 22 | fp_dest_icao | char | 4 | Destination airport ICAO |
| 23 | fp_alt_icao | char | 4 | Alternate airport ICAO |
| 24 | fp_dept_time_z | char | 4 | Filed departure time (HHMM) |
| 25 | fp_enroute_minutes | int | | Filed enroute time (minutes) |
| 26 | fp_fuel_minutes | int | | Filed fuel endurance (minutes) |
| 27 | fp_altitude_ft | int | | Filed cruise altitude (feet) |
| 28 | fp_tas_kts | int | | Filed true airspeed (knots) |
| 29 | fp_route | nvarchar | MAX | Filed route string |
| 30 | fp_remarks | nvarchar | MAX | Flight plan remarks |
| 31 | aircraft_icao | nvarchar | 64 | Full ICAO aircraft designator |
| 32 | aircraft_equipment | nvarchar | 64 | Equipment codes |
| 33 | aircraft_transponder | nvarchar | 16 | Transponder codes |
| 34 | dfix | nvarchar | 8 | Departure fix |
| 35 | eftd_utc | datetime2 | | Estimated flight time departure |
| 36 | dp_name | nvarchar | 16 | Departure procedure name |
| 37 | dtrsn | nvarchar | 16 | Departure transition |
| 38 | gcd_nm | decimal | | Great circle distance (nm) |
| 39 | afix | nvarchar | 8 | Arrival fix |
| 40 | eaft_utc | datetime2 | | Estimated arrival fix time |
| 41 | star_name | nvarchar | 16 | STAR name |
| 42 | strsn | nvarchar | 16 | STAR transition |

### Position & Velocity (Positions 43-46, 130-132)
| Position | Column | Type | Length | Description |
|----------|--------|------|--------|-------------|
| 43 | lat | decimal | | Latitude |
| 44 | lon | decimal | | Longitude |
| 45 | altitude_ft | int | | Current altitude (feet) |
| 46 | groundspeed_kts | int | | Ground speed (knots) |
| 130 | heading_deg | smallint | | Heading (degrees) |
| 131 | qnh_in_hg | decimal | | Altimeter setting (inHg) |
| 132 | qnh_mb | int | | Altimeter setting (millibars) |

### Times - Estimated (Positions 47-56)
| Position | Column | Type | Length | Description |
|----------|--------|------|--------|-------------|
| 47 | etd_prefix | nchar | 1 | ETD prefix |
| 48 | etd_runway_utc | datetime2 | | ETD runway time |
| 49 | entry_utc | datetime2 | | Entry time |
| 50 | exit_utc | datetime2 | | Exit time |
| 51 | ete_minutes | int | | Estimated time enroute (minutes) |
| 52 | eta_prefix | nchar | 1 | ETA prefix |
| 53 | eta_runway_utc | datetime2 | | **ETA runway time** |
| 54 | ctd_utc | datetime2 | | Controlled time departure |
| 55 | cta_utc | datetime2 | | Controlled time arrival |
| 56 | cete_minutes | int | | Controlled ETE (minutes) |

### Times - Scheduled/Proposed (Positions 57-65)
| Position | Column | Type | Length | Description |
|----------|--------|------|--------|-------------|
| 57 | sgtd_utc | datetime2 | | Scheduled gate departure |
| 58 | sgta_utc | datetime2 | | Scheduled gate arrival |
| 59 | pgtd_utc | datetime2 | | Proposed gate departure |
| 60 | pgta_utc | datetime2 | | Proposed gate arrival |
| 61 | pete_minutes | int | | Proposed ETE (minutes) |
| 62 | lrtd_utc | datetime2 | | Last revised departure |
| 63 | lrta_utc | datetime2 | | Last revised arrival |
| 64 | lgtd_utc | datetime2 | | Last gate departure |
| 65 | lgta_utc | datetime2 | | Last gate arrival |

### Times - Initial/Actual (Positions 66-84)
| Position | Column | Type | Length | Description |
|----------|--------|------|--------|-------------|
| 66 | igtd_utc | datetime2 | | Initial gate departure |
| 67 | igta_utc | datetime2 | | Initial gate arrival |
| 68 | ientry_utc | datetime2 | | Initial entry time |
| 69 | artd_utc | datetime2 | | Actual runway departure |
| 70 | arta_utc | datetime2 | | Actual runway arrival |
| 71 | out_utc | datetime2 | | **OOOI - OUT** |
| 72 | off_utc | datetime2 | | **OOOI - OFF** |
| 73 | on_utc | datetime2 | | **OOOI - ON** |
| 74 | in_utc | datetime2 | | **OOOI - IN** |
| 75 | ertd_utc | datetime2 | | Early runway departure |
| 76 | erta_utc | datetime2 | | Early runway arrival |
| 77 | eentry_utc | datetime2 | | Early entry time |
| 78 | oetd_utc | datetime2 | | Original ETD |
| 79 | oeta_utc | datetime2 | | Original ETA |
| 80 | oentry_utc | datetime2 | | Original entry |
| 81 | oete_minutes | int | | Original ETE (minutes) |
| 82 | betd_utc | datetime2 | | Base ETD |
| 83 | beta_utc | datetime2 | | Base ETA |
| 84 | bentry_utc | datetime2 | | Base entry |

### Times - TMA/Control (Positions 85-87)
| Position | Column | Type | Length | Description |
|----------|--------|------|--------|-------------|
| 85 | tma_rt_utc | datetime2 | | TMA runway time |
| 86 | octd_utc | datetime2 | | Original controlled departure |
| 87 | octa_utc | datetime2 | | Original controlled arrival |

### TMI Control (Positions 88-111)
| Position | Column | Type | Length | Description |
|----------|--------|------|--------|-------------|
| 88 | ctl_exempt | bit | | **Exempt from TMI flag** |
| 89 | ctl_type | nvarchar | 8 | **Control type (GDP, AFP, GS, etc.)** |
| 90 | subbable | bit | | Substitution eligible |
| 91 | ctl_program | nvarchar | 16 | **Control program name** |
| 92 | ctl_element | nvarchar | 32 | **Controlled element (airport/FCA)** |
| 93 | slot_id | nvarchar | 16 | Slot identifier |
| 94 | slot_hold | bit | | Slot hold flag |
| 95 | div_recovery | nchar | 1 | Diversion recovery |
| 96 | delay_status | nvarchar | 16 | Delay status |
| 97 | ltod_minutes | int | | Late takeoff delay (minutes) |
| 98 | cnx_status | nvarchar | 2 | Cancellation status |
| 99 | remark_code | nvarchar | 8 | Remark code |
| 100 | nrp_flag | bit | | NRP flag |
| 101 | lfg_flag | bit | | Long-haul flag |
| 102 | iii_flag | bit | | International flag |
| 103 | atv_flag | bit | | ATV flag |
| 104 | swp_flag | bit | | Swap flag |
| 105 | dvt_flag | bit | | Divert flag |
| 106 | adc_flag | bit | | ADC flag |
| 107 | fca_flag | bit | | FCA flag |
| 108 | wxr_flag | bit | | Weather flag |
| 109 | alarm_code | nvarchar | 2 | Alarm code |
| 110 | do_flag | bit | | DO flag |
| 111 | absolute_delay_min | int | | Absolute delay (minutes) |

### Delay & Schedule (Positions 112-117, 121)
| Position | Column | Type | Length | Description |
|----------|--------|------|--------|-------------|
| 112 | schedule_variation_min | int | | Schedule variation (minutes) |
| 113 | program_delay_min | int | | Program-assigned delay (minutes) |
| 114 | estimated_dep_utc | datetime2 | | Estimated departure |
| 115 | estimated_arr_utc | datetime2 | | **Estimated arrival** |
| 116 | eta_source | nvarchar | 16 | ETA source |
| 117 | arrival_bucket_utc | datetime2 | | Arrival bucket time |
| 121 | arrival_bucket_minutes | datetime2 | | Arrival bucket (minutes) |

### Status & Flags (Position 119-122)
| Position | Column | Type | Length | Description |
|----------|--------|------|--------|-------------|
| 119 | last_raw_json | nvarchar | MAX | Last raw JSON data |
| 120 | flight_status | nvarchar | 32 | Flight status |
| 122 | gs_flag | bit | | **Ground stop flag** |

### Facility Assignment (Positions 123-128)
| Position | Column | Type | Length | Description |
|----------|--------|------|--------|-------------|
| 123 | fp_dept_tracon | nvarchar | 64 | Departure TRACON |
| 124 | fp_dept_artcc | nvarchar | 8 | **Departure ARTCC** |
| 125 | fp_dest_tracon | nvarchar | 64 | Destination TRACON |
| 126 | fp_dest_artcc | nvarchar | 8 | **Destination ARTCC** |
| 127 | fp_alt_tracon | nvarchar | 64 | Alternate TRACON |
| 128 | fp_alt_artcc | nvarchar | 8 | Alternate ARTCC |

### VATSIM/Extended Aircraft (Positions 129, 133-140)
| Position | Column | Type | Length | Description |
|----------|--------|------|--------|-------------|
| 129 | vatsim_server | nvarchar | 32 | VATSIM server |
| 133 | aircraft_faa | nvarchar | 16 | **FAA aircraft designator** |
| 134 | aircraft_short | nvarchar | 16 | **Short aircraft name** |
| 135 | fp_revision_id | int | | Flight plan revision ID |
| 136 | fp_assigned_transponder | nvarchar | 16 | **Assigned transponder code** |
| 137 | fp_dof_utc | date | | Date of flight |
| 138 | fp_eet_minutes | int | | Extended EET (minutes) |
| 139 | fp_opr | nvarchar | 8 | Operator code |
| 140 | fp_eet | nvarchar | MAX | Extended EET string |

### Sequencing Times (Positions 141-148)
| Position | Column | Type | Length | Description |
|----------|--------|------|--------|-------------|
| 141 | sequence_time_utc | datetime2 | | Sequence assignment time |
| 142 | holdshort_time_utc | datetime2 | | Hold short time |
| 143 | runway_time_utc | datetime2 | | Runway time |
| 144 | eta_vt_utc | datetime2 | | ETA VT |
| 145 | vt_utc | datetime2 | | Virtual target time |
| 146 | sequence_utc | datetime2 | | Sequence time |
| 147 | holdshort_utc | datetime2 | | Hold short time |
| 148 | runway_utc | datetime2 | | Runway assignment time |

### GDP Integration (Positions 149-158)
| Position | Column | Type | Length | Description |
|----------|--------|------|--------|-------------|
| 149 | gdp_program_id | nvarchar | 50 | **GDP program ID** |
| 150 | gdp_slot_index | int | | **GDP slot index** |
| 151 | gdp_slot_time_utc | datetime2 | | **GDP assigned slot time** |
| 152 | ctl_prgm | nvarchar | 50 | Control program (alternate) |
| 153 | delay_capped | bit | | Delay capped flag |
| 154 | gs_held | bit | | Ground stop held flag |
| 155 | gs_release_utc | datetime2 | | Ground stop release time |
| 156 | ctl_exempt_reason | nvarchar | 64 | Exemption reason |
| 157 | slot_time | nvarchar | 8 | Slot time (HHMM format) |
| 158 | slot_time_utc | datetime2 | | Slot time UTC |

---

## SWIM API Column Usage

### flights.php & flight.php (Full Flight Record)
```
flight_key, callsign, cid, aircraft_type, aircraft_faa, aircraft_icao, aircraft_short,
aircraft_equipment, aircraft_transponder, ac_cat, weight_class, major_carrier, user_category,
fp_dept_icao, fp_dest_icao, fp_alt_icao, fp_altitude_ft, fp_tas_kts, fp_route, fp_remarks, fp_rule,
fp_dept_time_z, fp_enroute_minutes, fp_assigned_transponder, fp_dept_artcc, fp_dest_artcc,
fp_dept_tracon, fp_dest_tracon, dfix, dp_name, afix, star_name, phase, flight_status, is_active,
lat, lon, altitude_ft, heading_deg, groundspeed_kts, eftd_utc, out_utc, off_utc, eta_runway_utc,
estimated_arr_utc, on_utc, in_utc, ete_minutes, gcd_nm, gs_flag, ctl_type, ctl_program, ctl_element,
ctl_exempt, gdp_program_id, gdp_slot_index, gdp_slot_time_utc, first_seen_utc, last_seen_utc, logon_time_utc
```

### positions.php (GeoJSON - Minimal)
```
flight_key, callsign, aircraft_type, aircraft_short, ac_cat, weight_class, fp_dept_icao, fp_dest_icao,
fp_dest_artcc, phase, lat, lon, altitude_ft, heading_deg, groundspeed_kts, eta_runway_utc,
estimated_arr_utc, fp_route, gcd_nm, ete_minutes, gs_flag, ctl_type, ctl_program, ctl_element,
gdp_program_id, gdp_slot_time_utc
```

### tmi/controlled.php (TMI Focus)
```
flight_key, callsign, cid, aircraft_type, aircraft_icao, ac_cat, weight_class, fp_dept_icao,
fp_dest_icao, fp_alt_icao, fp_altitude_ft, fp_route, fp_dept_artcc, fp_dest_artcc, dfix, dp_name,
afix, star_name, phase, is_active, lat, lon, altitude_ft, heading_deg, groundspeed_kts, eftd_utc,
out_utc, off_utc, eta_runway_utc, ete_minutes, gcd_nm, gs_flag, ctl_type, ctl_program, ctl_element,
ctl_exempt, gdp_program_id, gdp_slot_index, gdp_slot_time_utc, first_seen_utc, last_seen_utc
```

---

## Key Column Groups for TMI Operations

### Ground Stop Detection
- `gs_flag` (bit) - Primary GS indicator
- `gs_held` (bit) - Currently held
- `gs_release_utc` (datetime2) - Release time

### GDP Assignment
- `gdp_program_id` (nvarchar 50) - GDP program identifier
- `gdp_slot_index` (int) - Position in GDP sequence
- `gdp_slot_time_utc` (datetime2) - Assigned departure slot

### General TMI Control
- `ctl_type` (nvarchar 8) - GDP, AFP, GS, REROUTE, etc.
- `ctl_program` (nvarchar 16) - Program name
- `ctl_element` (nvarchar 32) - Controlled element (airport, FCA)
- `ctl_exempt` (bit) - Exemption status
- `ctl_exempt_reason` (nvarchar 64) - Exemption reason

### OOOI Times (SWIM Primary)
- `out_utc` - Pushback/gate departure
- `off_utc` - Takeoff
- `on_utc` - Landing
- `in_utc` - Arrival at gate

---

**Last Verified:** 2026-01-15 against live VATSIM_ADL Azure SQL database
