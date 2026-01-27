# VATSIM_ADL Normalized Schema Reference
## SWIM API v2.0

**Generated:** 2026-01-15 (Updated 2026-01-27)
**Database:** Azure SQL (VATSIM_ADL)
**Schema:** Normalized ADL Tables

---

> **FIXM Migration (2026-01-27):** The `adl_flight_times` table will receive new FIXM-aligned
> columns alongside legacy OOOI columns. During the 30-day transition, both sets are populated.
>
> **New FIXM Time Columns:** `actual_off_block_time`, `actual_time_of_departure`,
> `actual_landing_time`, `actual_in_block_time`, `estimated_time_of_arrival`,
> `estimated_off_block_time`, `estimated_runway_arrival_time`, `controlled_time_of_departure`,
> `controlled_time_of_arrival`
>
> See [VATSWIM_FIXM_Field_Mapping.md](VATSWIM_FIXM_Field_Mapping.md) for complete mapping.

---

## Normalized Table Architecture

All tables linked by `flight_uid` (BIGINT) as primary/foreign key.

| Table | Purpose | Key Columns |
|-------|---------|-------------|
| `adl_flight_core` | Master flight registry | flight_uid (PK), callsign, cid, phase, is_active |
| `adl_flight_position` | Current position & velocity | lat, lon, altitude_ft, groundspeed_kts |
| `adl_flight_plan` | Route, O/D, procedures | fp_dept_icao, fp_dest_icao, fp_route |
| `adl_flight_times` | All time fields (80 cols) | eta_utc, out/off/on/in_utc |
| `adl_flight_tmi` | TMI assignments | ctl_type, slot_time_utc, gs_held |
| `adl_flight_aircraft` | Aircraft & carrier info | aircraft_icao, weight_class, airline_name |

---

## adl_flight_core (49 columns)

| Column | Type | Description |
|--------|------|-------------|
| flight_uid | bigint | **Primary Key** |
| flight_key | nvarchar(64) | Unique flight identifier |
| cid | int | VATSIM CID |
| callsign | nvarchar(16) | Flight callsign |
| flight_id | nvarchar(32) | VATSIM flight ID |
| phase | nvarchar(16) | Flight phase |
| last_source | nvarchar(16) | Last data source |
| is_active | bit | Active flight flag |
| first_seen_utc | datetime2 | First position timestamp |
| last_seen_utc | datetime2 | Last position timestamp |
| logon_time_utc | datetime2 | VATSIM logon time |
| adl_date | date | ADL date |
| adl_time | time | ADL time |
| snapshot_utc | datetime2 | Snapshot time |
| flight_phase | nvarchar(16) | Detailed phase |
| current_artcc | varchar(10) | Current ARTCC |
| current_tracon | varchar(20) | Current TRACON |
| current_zone | nvarchar(16) | Airport zone |
| current_zone_airport | nvarchar(4) | Zone airport |
| current_sector_low | varchar(255) | Low sector(s) |
| current_sector_high | varchar(255) | High sector(s) |
| weather_impact | nvarchar(32) | Weather impact |
| weather_alert_ids | nvarchar(256) | Weather alert IDs |

---

## adl_flight_position (24 columns)

| Column | Type | Description |
|--------|------|-------------|
| flight_uid | bigint | **FK to core** |
| lat | decimal | Latitude |
| lon | decimal | Longitude |
| altitude_ft | int | Current altitude |
| altitude_assigned | int | Assigned altitude |
| altitude_cleared | int | Cleared altitude |
| groundspeed_kts | int | Ground speed |
| true_airspeed_kts | int | True airspeed |
| mach | decimal | Mach number |
| vertical_rate_fpm | int | Vertical rate |
| heading_deg | smallint | Heading |
| track_deg | smallint | Track |
| qnh_in_hg | decimal | Altimeter (inHg) |
| qnh_mb | int | Altimeter (mb) |
| dist_to_dest_nm | decimal | **GCD distance remaining** |
| dist_flown_nm | decimal | Distance flown |
| pct_complete | decimal | **Percent complete** |
| route_dist_to_dest_nm | decimal | Route distance remaining |
| route_pct_complete | decimal | Route percent complete |
| next_waypoint_name | nvarchar(64) | Next waypoint |
| dist_to_next_waypoint_nm | decimal | Distance to next waypoint |

---

## adl_flight_plan (52 columns)

| Column | Type | Description |
|--------|------|-------------|
| flight_uid | bigint | **FK to core** |
| fp_rule | nchar(1) | Flight rules (I/V/Y/Z) |
| fp_dept_icao | char(4) | **Departure airport** |
| fp_dest_icao | char(4) | **Destination airport** |
| fp_alt_icao | char(4) | Alternate airport |
| fp_dept_tracon | nvarchar(64) | Departure TRACON |
| fp_dept_artcc | nvarchar(8) | **Departure ARTCC** |
| fp_dest_tracon | nvarchar(64) | Destination TRACON |
| fp_dest_artcc | nvarchar(8) | **Destination ARTCC** |
| dfix | nvarchar(8) | Departure fix |
| dp_name | nvarchar(16) | SID name |
| dtrsn | nvarchar(16) | SID transition |
| afix | nvarchar(8) | Arrival fix |
| star_name | nvarchar(16) | STAR name |
| strsn | nvarchar(16) | STAR transition |
| approach | nvarchar(16) | Approach procedure |
| dep_runway | nvarchar(4) | Departure runway |
| arr_runway | nvarchar(4) | Arrival runway |
| fp_route | nvarchar(MAX) | **Filed route** |
| fp_route_expanded | nvarchar(MAX) | Expanded route |
| fp_dept_time_z | char(4) | Filed departure time |
| fp_altitude_ft | int | **Cruise altitude** |
| fp_tas_kts | int | **Filed TAS** |
| fp_enroute_minutes | int | Filed ETE |
| fp_fuel_minutes | int | Fuel endurance |
| fp_remarks | nvarchar(MAX) | Remarks |
| gcd_nm | decimal | **Great circle distance** |
| route_total_nm | decimal | **Route total distance** |
| aircraft_type | nvarchar(8) | **Aircraft type** |
| aircraft_equip | nvarchar(32) | Equipment codes |
| waypoint_count | int | Parsed waypoint count |
| parse_status | nvarchar(16) | Route parse status |
| is_simbrief | bit | SimBrief flag |
| simbrief_id | nvarchar(32) | SimBrief OFP ID |

---

## adl_flight_times (80 columns)

| Column | Type | Description |
|--------|------|-------------|
| flight_uid | bigint | **FK to core** |
| std_utc | datetime2 | Scheduled departure |
| sta_utc | datetime2 | Scheduled arrival |
| etd_utc | datetime2 | Estimated departure |
| etd_runway_utc | datetime2 | ETD runway |
| etd_source | nvarchar(16) | ETD source |
| eta_utc | datetime2 | **Estimated arrival** |
| eta_runway_utc | datetime2 | **ETA runway** |
| eta_source | nvarchar(16) | **ETA source** |
| eta_method | nvarchar(16) | **ETA method** |
| atd_utc | datetime2 | Actual departure |
| atd_runway_utc | datetime2 | ATD runway |
| ata_utc | datetime2 | Actual arrival |
| ata_runway_utc | datetime2 | ATA runway |
| ctd_utc | datetime2 | **Controlled departure** |
| cta_utc | datetime2 | **Controlled arrival** |
| edct_utc | datetime2 | **EDCT** |
| octd_utc | datetime2 | Original CTD |
| octa_utc | datetime2 | Original CTA |
| out_utc | datetime2 | **OOOI - OUT** |
| off_utc | datetime2 | **OOOI - OFF** |
| on_utc | datetime2 | **OOOI - ON** |
| in_utc | datetime2 | **OOOI - IN** |
| ete_minutes | int | **Estimated time enroute** |
| ate_minutes | int | Actual time enroute |
| delay_minutes | int | Delay (minutes) |
| eta_confidence | decimal | ETA confidence score |
| eta_wind_component_kts | int | Wind component |

---

## adl_flight_tmi (43 columns)

| Column | Type | Description |
|--------|------|-------------|
| flight_uid | bigint | **FK to core** |
| ctl_type | nvarchar(8) | **Control type (GDP, AFP, GS)** |
| ctl_element | nvarchar(8) | Controlled element |
| ctl_prgm | nvarchar(32) | **Control program name** |
| ctl_exempt | bit | Exempt flag |
| ctl_exempt_reason | nvarchar(32) | Exemption reason |
| delay_status | nvarchar(16) | Delay status |
| delay_minutes | int | **Delay (minutes)** |
| delay_source | nvarchar(16) | Delay source |
| ctd_utc | datetime2 | Controlled departure |
| cta_utc | datetime2 | Controlled arrival |
| edct_utc | datetime2 | EDCT |
| slot_time_utc | datetime2 | **Slot time** |
| slot_status | nvarchar(16) | Slot status |
| aslot | nvarchar(16) | ASLOT identifier |
| is_exempt | bit | **Exempt flag** |
| exempt_reason | nvarchar(64) | **Exemption reason** |
| program_id | int | **TMI program ID** |
| slot_id | bigint | **Slot ID** |
| gs_held | bit | **Ground stop held** |
| gs_release_utc | datetime2 | **GS release time** |
| is_popup | bit | Popup flag |
| popup_detected_utc | datetime2 | Popup detection time |
| ecr_pending | bit | ECR pending flag |
| reroute_status | nvarchar(16) | Reroute status |
| reroute_id | nvarchar(32) | Reroute ID |
| absolute_delay_min | int | Absolute delay |
| schedule_variation_min | int | Schedule variation |

---

## adl_flight_aircraft (12 columns)

| Column | Type | Description |
|--------|------|-------------|
| flight_uid | bigint | **FK to core** |
| aircraft_icao | nvarchar(8) | **ICAO type code** |
| aircraft_faa | nvarchar(8) | **FAA type code** |
| weight_class | nchar(1) | **Weight class (L/M/H/J)** |
| wake_category | nvarchar(8) | **Wake category** |
| engine_type | nvarchar(8) | Engine type |
| engine_count | tinyint | Number of engines |
| cruise_tas_kts | int | Cruise TAS |
| ceiling_ft | int | Service ceiling |
| airline_icao | nvarchar(4) | **Airline ICAO** |
| airline_name | nvarchar(64) | **Airline name** |

---

## SWIM API Column Usage Summary

### Minimal Query (positions.php)
```sql
SELECT 
    c.flight_uid, c.flight_key, c.callsign, c.phase, c.current_artcc,
    pos.lat, pos.lon, pos.altitude_ft, pos.heading_deg, pos.groundspeed_kts,
    pos.dist_to_dest_nm, pos.pct_complete,
    fp.fp_dept_icao, fp.fp_dest_icao, fp.fp_dest_artcc, fp.aircraft_type,
    t.eta_runway_utc, t.ete_minutes,
    tmi.gs_held, tmi.ctl_type, tmi.program_id,
    ac.weight_class, ac.wake_category
FROM dbo.adl_flight_core c
INNER JOIN dbo.adl_flight_position pos ON pos.flight_uid = c.flight_uid
LEFT JOIN dbo.adl_flight_plan fp ON fp.flight_uid = c.flight_uid
LEFT JOIN dbo.adl_flight_times t ON t.flight_uid = c.flight_uid
LEFT JOIN dbo.adl_flight_tmi tmi ON tmi.flight_uid = c.flight_uid
LEFT JOIN dbo.adl_flight_aircraft ac ON ac.flight_uid = c.flight_uid
WHERE c.is_active = 1
```

### Full Query (flight.php)
All columns from all 6 tables with LEFT JOINs.

---

## Key Differences from Legacy adl_flights

| Legacy Column | Normalized Location | Notes |
|---------------|---------------------|-------|
| gdp_program_id | tmi.program_id | Renamed |
| gdp_slot_time_utc | tmi.slot_time_utc | Renamed |
| gdp_slot_index | tmi.slot_id | Different type |
| gs_flag | tmi.gs_held | Renamed |
| ac_cat | ac.wake_category | Renamed |
| major_carrier | ac.airline_icao | Renamed |
| eta_runway_utc | t.eta_runway_utc | Same name, different table |
| (N/A) | pos.dist_to_dest_nm | NEW - calculated distance |
| (N/A) | pos.pct_complete | NEW - progress percentage |
| (N/A) | pos.next_waypoint_name | NEW - waypoint tracking |

---

**Last Verified:** 2026-01-15 against live VATSIM_ADL Azure SQL database
