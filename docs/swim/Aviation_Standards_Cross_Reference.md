# Aviation Data Exchange Standards - Consolidated Field Reference

**Version:** 2.0
**Date:** 2026-03-29
**Purpose:** Cross-reference of equivalent field names across aviation data exchange standards

---

> **FIXM Migration Complete (2026-03-29):** VATSWIM now uses FIXM 4.3.0-aligned field naming
> in its REST API. Legacy OOOI column names (`out_utc`, `off_utc`, `on_utc`, `in_utc`) remain
> in the database for backward compatibility but the API returns FIXM-aligned names
> (`actual_off_block_time`, `actual_time_of_departure`, `actual_landing_time`,
> `actual_in_block_time`). See [VATSWIM_FIXM_Field_Mapping.md](VATSWIM_FIXM_Field_Mapping.md)
> for the complete DB-to-API mapping.

---

## API Response Structure

The VATSWIM REST API returns flight data as nested JSON objects aligned with FIXM 4.3.0.
The VATSWIM column in tables below shows the **API response field path** (e.g., `identity.aircraft_identification`).
Database column names are noted in parentheses where they differ from the API field.

```
GET /api/swim/v1/flights?format=fixm
Authorization: Bearer {api_key}  or  X-API-Key: {api_key}

Response: 98 fields across 8 object groups
  gufi, flight_uid, flight_key          (root)
  identity.*                            (9 fields)
  flight_plan.*                         (18 fields)
  position.*                            (10 fields)
  progress.*                            (8 fields)
  times.*                               (17 fields)
  tmi.*                                 (13 fields)
  metering.*                            (15 fields)
  + 5 metadata fields at root
```

**Data pipeline:** `VATSIM_ADL` (6 normalized tables, 274 columns) -> 2-min sync -> `SWIM_API.swim_flights` (213 columns) -> SQL select ~65 cols -> `formatFlightRecordFIXM()` -> 98 API fields.

**Supported formats:** `fixm` (default), `xml`, `geojson`, `csv`, `kml`, `ndjson`

---

## 1. Flight Identification

| Concept | FIXM 4.3 | AIDX 22.1 | TFMS/FAA | A-CDM | VATSWIM API |
|---------|----------|-----------|----------|-------|-------------|
| Unique flight ID | `gufi` | -- | `ACID`+`DEP`+`DEST`+`ETD` | -- | `gufi` |
| Callsign | `flightIdentification/aircraftIdentification` | `FlightId/FlightNumber` | `ACID` | `arcid` | `identity.aircraft_identification` |
| Airline ICAO code | `flightIdentification/majorCarrierIdentifier` | `FlightId/AirlineIATA` | `AIRLINE` | -- | `identity.operator_icao` |
| Airline name | -- | `AirlineName` | -- | -- | `identity.operator_name` |
| Pilot ID | -- | -- | -- | -- | `identity.pilot_cid` (VATSIM CID) |
| Internal flight UID | -- | -- | -- | -- | `flight_uid` (bigint PK) |
| Flight key | -- | -- | -- | -- | `flight_key` (natural key) |

**GUFI format:** `VAT-YYYYMMDD-{callsign}-{dept}-{dest}` (computed column in `swim_flights`)

---

## 2. Aircraft Information

| Concept | FIXM 4.3 | AIDX 22.1 | TFMS/FAA | A-CDM | ICAO FPL | VATSWIM API |
|---------|----------|-----------|----------|-------|----------|-------------|
| Aircraft type | `aircraft/aircraftType/icaoAircraftTypeDesignator` | `AircraftType/AircraftIATACode` | `ACFT_TYPE` | `arctyp` | Field 9 | `identity.aircraft_type` |
| ICAO type code | `aircraft/aircraftType/icaoAircraftTypeDesignator` | -- | -- | -- | -- | `identity.aircraft_type_icao` |
| FAA type code | `aircraft/aircraftType/otherAircraftType` | -- | `ACFT_FAA` | -- | -- | `identity.aircraft_type_faa` |
| Wake category | `aircraft/wakeTurbulence` | -- | `WAKE` | `wtc` | Field 9 | `identity.wake_turbulence` |
| Weight class | -- | -- | `WEIGHT_CLASS` | -- | -- | `identity.weight_class` |

### Wake Turbulence Categories (`wake_category` column)

| Category | FIXM | ICAO | FAA | VATSWIM `wake_category` | Description |
|----------|------|------|-----|------------------------|-------------|
| Light | `LIGHT` | `L` | `SMALL` | `L` | <7,000 kg / 15,500 lbs |
| Medium | `MEDIUM` | `M` | `LARGE` | `M` | 7,000-136,000 kg |
| Heavy | `HEAVY` | `H` | `HEAVY` | `H` | >136,000 kg / 300,000 lbs |
| Super | `SUPER` | `J` | `SUPER` | `J` | A380, AN-225 |

### FAA Weight Classes (`weight_class` column)

| Class | FAA | VATSWIM `weight_class` | Description |
|-------|-----|------------------------|-------------|
| Small | `SMALL` | `S` | <41,000 lbs MTOW |
| Large | `LARGE` | `L` | 41,000-300,000 lbs MTOW |
| Heavy | `HEAVY` | `H` | >300,000 lbs MTOW |
| Super | `SUPER` | `J` | A380 class |

### Additional Aircraft Fields (DB only, not in API response)

| DB Column | Type | Purpose |
|-----------|------|---------|
| `engine_type` | nvarchar(8) | JET, TURBOPROP, PISTON |
| `engine_count` | smallint | Number of engines |
| `cruise_tas_kts` | smallint | Reference cruise TAS from BADA |
| `ceiling_ft` | int | Service ceiling |

---

## 3. Departure Information

| Concept | FIXM 4.3 | AIDX 22.1 | TFMS/FAA | A-CDM | VATSWIM API |
|---------|----------|-----------|----------|-------|-------------|
| Departure airport | `departure/aerodrome/locationIndicator` | `DepartureAirport` | `DEP` | `adep` | `flight_plan.departure_aerodrome` |
| Departure runway | `departure/runwayDirection/designator` | `DepartureRunway` | `DEP_RWY` | `drwy` | `flight_plan.departure_runway` |
| Departure fix | `departure/departurePoint/fix` | -- | `DEP_FIX` | -- | `flight_plan.departure_point` |
| SID | `routeTrajectoryGroup/routeInformation/sidStarReference` | -- | `SID` | -- | `flight_plan.sid` |
| Departure ARTCC | `departure/airspace` | -- | `DEP_CTR` | -- | `flight_plan.departure_airspace` |
| Departure TRACON | -- | -- | `DEP_TRACON` | -- | `flight_plan.departure_tracon` |

---

## 4. Arrival Information

| Concept | FIXM 4.3 | AIDX 22.1 | TFMS/FAA | A-CDM | VATSWIM API |
|---------|----------|-----------|----------|-------|-------------|
| Destination airport | `arrival/destinationAerodrome/locationIndicator` | `ArrivalAirport` | `DEST` | `ades` | `flight_plan.arrival_aerodrome` |
| Arrival runway | `arrival/runwayDirection/designator` | `ArrivalRunway` | `ARR_RWY` | `arwy` | `flight_plan.arrival_runway` |
| Arrival fix | `arrival/destinationPoint/fix` | -- | `ARR_FIX` | -- | `flight_plan.arrival_point` |
| STAR | `routeTrajectoryGroup/routeInformation/sidStarReference` | -- | `STAR` | -- | `flight_plan.star` |
| Alternate | `arrival/destinationAerodromeAlternate` | `AlternateAirport` | `ALT` | -- | `flight_plan.alternate_aerodrome` |
| Arrival ARTCC | `arrival/airspace` | -- | `DEST_CTR` | -- | `flight_plan.arrival_airspace` |
| Arrival TRACON | -- | -- | `DEST_TRACON` | -- | `flight_plan.arrival_tracon` |

---

## 5. Route & Trajectory

| Concept | FIXM 4.3 | AIDX 22.1 | TFMS/FAA | ICAO FPL | VATSWIM API |
|---------|----------|-----------|----------|----------|-------------|
| Route string | `routeTrajectoryGroup/element/routeDesignator` | -- | `ROUTE` | Field 15 | `flight_plan.route_text` |
| Filed altitude | `routeTrajectoryGroup/cruisingLevel` | -- | `REQ_ALT` | Field 15 | `flight_plan.cruising_level` |
| Filed speed | `routeTrajectoryGroup/cruisingSpeed` | -- | `SPEED` | Field 15 | `flight_plan.cruising_speed` |
| Flight rules | `filingInfo/flightRulesCategory` | -- | `FLT_RULES` | Field 8 | `flight_plan.flight_rules_category` |
| Remarks | `filingInfo/remarks` | -- | `REMARKS` | Field 18 | `flight_plan.remarks` |

### Route Progress (VATSWIM extension)

| Concept | VATSWIM API | DB Column | Description |
|---------|-------------|-----------|-------------|
| Great circle distance | `progress.great_circle_distance` | `gcd_nm` | Straight-line dep-dest (nm) |
| Total route distance | `progress.total_flight_distance` | `route_total_nm` | Full route distance (nm) |
| Distance flown | `progress.distance_flown` | `dist_flown_nm` | Route distance already traveled |
| Distance remaining | `progress.distance_to_destination` | `dist_to_dest_nm` | Route distance remaining |
| Percent complete | `progress.percent_complete` | `pct_complete` | Route completion percentage |
| Time to destination | `progress.time_to_destination` | (computed) | `(dist_to_dest_nm / groundspeed_kts) * 60` min |

---

## 6. Time Fields

### 6.1 Departure Times

| Time Type | FIXM 4.3 | AIDX 22.1 | TFMS/FAA | A-CDM | VATSWIM API | DB Column |
|-----------|----------|-----------|----------|-------|-------------|-----------|
| **Estimated off-block** | `departure/estimatedOffBlockTime` | `EstimatedOffBlockDateTime` | `EOBT` | `eobt` | `times.estimated_off_block_time` | `estimated_off_block_time` |
| **Estimated runway** | `departure/estimatedRunwayTime` | -- | `P-TIME` / `ERDT` | `etot` | (DB only) | `etd_runway_utc` |
| **Actual off-block** | `departure/actualOffBlockTime` | `ActualOffBlockDateTime` | `AOBT` | `aobt` | `times.actual_off_block_time` | `actual_off_block_time` |
| **Actual takeoff** | `departure/actualTimeOfDeparture` | `ActualDepartureDateTime` | `ATD` / `ATOT` | `atot` | `times.actual_time_of_departure` | `actual_time_of_departure` |
| **Controlled departure** | `departure/controlledOffBlockTime` | -- | `CTD` | `ctot` | `times.controlled_time_of_departure` | `controlled_time_of_departure` |
| **EDCT** | `nas:edct/time` | -- | `EDCT` | -- | `times.edct` | `edct_utc` |

### 6.2 Arrival Times

| Time Type | FIXM 4.3 | AIDX 22.1 | TFMS/FAA | A-CDM | VATSWIM API | DB Column |
|-----------|----------|-----------|----------|-------|-------------|-----------|
| **Estimated arrival** | `arrival/estimatedArrivalTime` | `EstimatedArrivalDateTime` | `ETA` | `eldt` | `times.estimated_time_of_arrival` | `estimated_time_of_arrival` |
| **Estimated runway** | `arrival/estimatedRunwayTime` | -- | `ERTA` | `eldt` | `times.estimated_runway_arrival` | `estimated_runway_arrival_time` |
| **Actual landing** | `arrival/actualLandingTime` | `ActualArrivalDateTime` | `ATA` / `ALDT` | `aldt` | `times.actual_landing_time` | `actual_landing_time` |
| **Actual in-block** | `arrival/actualInBlockTime` | `ActualInBlockDateTime` | `AIBT` | `aibt` | `times.actual_in_block_time` | `actual_in_block_time` |
| **Controlled arrival** | `arrival/controlledArrivalTime` | -- | `CTA` | `cta` | `times.controlled_time_of_arrival` | `controlled_time_of_arrival` |

### 6.3 ETA Computation (VATSWIM extension)

| Field | VATSWIM API | DB Column | Description |
|-------|-------------|-----------|-------------|
| ETA source | `times.eta_source` | `eta_source` | Data source for ETA (trajectory, airline, etc.) |
| ETA method | `times.eta_method` | `eta_method` | Algorithm version (e.g., `V35`) |
| Estimated elapsed time | `times.estimated_elapsed_time` | `ete_minutes` | Total flight duration in minutes |

### 6.4 SimTraffic Departure Phase Times (VATSWIM extension)

| Field | VATSWIM API | DB Column | Description |
|-------|-------------|-----------|-------------|
| Taxi start | `times.taxi_start_time` | `taxi_start_time` | When aircraft begins taxi-out |
| Departure sequence | `times.departure_sequence_time` | `departure_sequence_time` | Departure queue entry |
| Hold short | `times.hold_short_time` | `hold_short_time` | Hold short of runway |
| Runway entry | `times.runway_entry_time` | `runway_entry_time` | Entered active runway |

### 6.5 OOOI Times (Out-Off-On-In)

| Event | FIXM 4.3 | TFMS | A-CDM | ACARS | VATSWIM API | Legacy DB |
|-------|----------|------|-------|-------|-------------|-----------|
| **OUT** (pushback) | `actualOffBlockTime` | `AOBT` | `aobt` | `OUT` | `times.actual_off_block_time` | `out_utc` |
| **OFF** (wheels up) | `actualTimeOfDeparture` | `ATOT` | `atot` | `OFF` | `times.actual_time_of_departure` | `off_utc` |
| **ON** (wheels down) | `actualLandingTime` | `ALDT` | `aldt` | `ON` | `times.actual_landing_time` | `on_utc` |
| **IN** (at gate) | `actualInBlockTime` | `AIBT` | `aibt` | `IN` | `times.actual_in_block_time` | `in_utc` |

### 6.6 Time Prefixes (TFMS Convention)

| Prefix | Meaning | Example | VATSWIM DB Pattern |
|--------|---------|---------|-------------------|
| `S` | Scheduled | SDTE, SATE | `std_utc`, `sta_utc` |
| `E` | Estimated | EOBT, ETA | `estimated_*`, `eta_*` |
| `T` | Target | TOBT, TSAT, TTOT, TLDT | `target_*` |
| `A` | Actual | AOBT, ATOT, ALDT, AIBT | `actual_*` |
| `C` | Controlled | CTD, CTA, CTOT | `controlled_*`, `ctd_utc` |
| `P` | Proposed | P-TIME | `ptd_utc` |

### 6.7 Additional Time Fields (DB only, 97 columns in `adl_flight_times`)

The ADL stores extensive time data not exposed in the standard API response:

| Category | DB Columns | Purpose |
|----------|-----------|---------|
| Fix times | `etd_dfix_utc`, `atd_dfix_utc`, `eta_afix_utc`, `ata_afix_utc`, `eaft_utc` | ETA/ATA at departure/arrival fixes |
| Meter fix times | `eta_meterfix_utc`, `sta_meterfix_utc` | Metering fix scheduling |
| Airspace times | `center_entry_utc`, `center_exit_utc`, `sector_entry_utc`, `sector_exit_utc`, `oceanic_entry_utc` | Boundary crossing times |
| Buckets | `arrival_bucket_utc`, `departure_bucket_utc` | 15-min demand bucketing |
| Epoch | `eta_epoch`, `etd_epoch` | Unix epoch for fast comparisons |
| Wind ETA | `eta_wind_component_kts`, `eta_wind_adj_kts`, `eta_wind_climb_kts`, `eta_wind_cruise_kts`, `eta_wind_descent_kts` | Wind-adjusted ETA computation |
| Original times | `octd_utc`, `octa_utc`, `oetd_utc`, `oeta_utc` | Pre-TMI baseline times |
| Phase milestones (dep) | `parking_left_utc`, `taxiway_entered_utc`, `hold_entered_utc`, `runway_entered_utc`, `takeoff_roll_utc`, `rotation_utc` | Departure phase timestamps |
| Phase milestones (arr) | `approach_start_utc`, `threshold_utc`, `touchdown_utc`, `rollout_end_utc`, `taxiway_arr_utc`, `parking_entered_utc` | Arrival phase timestamps |

---

## 7. Flight Status & Phase

### 7.1 Flight Status (API `progress.flight_status`)

| Status | FIXM FlightStatusType | AIDX FlightLegStatus | TFMS | VATSWIM API Value |
|--------|----------------------|---------------------|------|-------------------|
| Filed/Prefiled | `FILED` | -- | `FILED` | `prefile` |
| Taxiing | -- | `Boarding` | -- | `taxiing` |
| Departed | `ASCENDING` | `Departed` | `DEP` | `departed` |
| Enroute | `CRUISING` | `InFlight` | `ENRT` | `enroute` |
| Descending | `DESCENDING` | -- | `DSC` | `descending` |
| Arrived | -- | `Arrived` | `ARR` | `arrived` |
| Disconnected | -- | -- | -- | `disconnected` |
| Unknown | `UNKNOWN` | -- | -- | `unknown` |

### 7.2 Active Status

| Field | VATSWIM API | Type | Description |
|-------|-------------|------|-------------|
| Active flag | `progress.is_active` | boolean | `true` while flight is transmitting position data |

---

## 8. Position & Track Data

| Concept | FIXM 4.3 | ASTERIX CAT062 | ADS-B | GeoJSON | VATSWIM API |
|---------|----------|----------------|-------|---------|-------------|
| Latitude | `position/position/latitude` | I062/105 | Lat | `coordinates[1]` | `position.latitude` |
| Longitude | `position/position/longitude` | I062/105 | Lon | `coordinates[0]` | `position.longitude` |
| Altitude | `position/altitude/altitude` | I062/136 | FL | `coordinates[2]` | `position.altitude` |
| Track | `position/track/trackAngle` | I062/180 | Track | -- | `position.track` * |
| Ground speed | `position/track/groundSpeed` | I062/185 | GS | `properties.groundspeed` | `position.ground_speed` |
| True airspeed | `position/track/trueAirspeed` | -- | -- | -- | `position.true_airspeed` |
| Vertical rate | `position/track/verticalRate` | I062/220 | VRate | `properties.vertical_rate` | `position.vertical_rate` |

> **\* Mapping note:** `position.track` is sourced from DB column `heading_deg` (not `track_deg`).
> The ADL stores both `heading_deg` (magnetic heading) and `track_deg` (true track angle) in
> `adl_flight_position`, but the API maps `heading_deg` to the `track` response field. This is
> a known semantic mismatch inherited from the VATSIM data feed which provides heading, not track.

### Airspace Position (VATSWIM extension)

| Concept | VATSWIM API | DB Column | Description |
|---------|-------------|-----------|-------------|
| Current ARTCC | `position.current_airspace` | `current_artcc` | Overlying ARTCC/FIR (e.g., `KZNY`) |
| Current TRACON | `position.current_tracon` | `current_tracon` | Overlying TRACON if applicable |
| Airport zone | `position.current_airport_zone` | `current_zone` | `AIRBORNE`, `TERMINAL`, `SURFACE`, etc. |

### Additional Position Fields (DB only, 24 columns in `adl_flight_position`)

| DB Column | Type | Description |
|-----------|------|-------------|
| `altitude_assigned` | int | ATC-assigned altitude |
| `altitude_cleared` | int | ATC-cleared altitude |
| `heading_deg` | smallint | Magnetic heading |
| `track_deg` | smallint | True track angle |
| `mach` | decimal(4,3) | Current Mach number |
| `qnh_in_hg` | decimal(5,2) | Altimeter setting (inHg) |
| `qnh_mb` | int | Altimeter setting (mb) |
| `position_geo` | geography | SQL Server spatial column |
| `route_dist_to_dest_nm` | decimal | Route-based distance remaining |
| `route_pct_complete` | decimal | Route-based completion % |
| `next_waypoint_name` | nvarchar(64) | Next waypoint on route |
| `dist_to_next_waypoint_nm` | decimal | Distance to next waypoint |

---

## 9. TMI (Traffic Management Initiative) Data

### 9.1 TMI Types

| TMI Type | FIXM US Extension | TFMS | EUROCONTROL | VATSWIM `tmi.control_type` |
|----------|-------------------|------|-------------|---------------------------|
| Ground Stop | `nas:groundStop` | `GS` | `GS` | `GS` |
| Ground Delay Program | `nas:groundDelayProgram` | `GDP` | `GDP` | `GDP` |
| Airspace Flow Program | `nas:airspaceFlowProgram` | `AFP` | `AFP` | `AFP` |
| Miles-in-Trail | `nas:milesInTrail` | `MIT` | `MIT` | `MIT` |
| Minutes-in-Trail | `nas:minutesInTrail` | `MINIT` | `MINIT` | `MINIT` |
| CTOP | `nas:ctop` | `CTOP` | -- | `CTOP` |
| Reroute | `nas:reroute` | `RR` | `RR` | `REROUTE` |

### 9.2 TMI Fields

| Concept | FIXM US Extension | TFMS | VATSWIM API | DB Column |
|---------|-------------------|------|-------------|-----------|
| Is controlled | -- | -- | `tmi.is_controlled` | (computed: `gs_held OR ctl_type IS NOT NULL`) |
| Ground stop held | `nas:groundStop` | `GS` | `tmi.ground_stop_held` | `gs_held` |
| GS release time | `nas:groundStop/release` | `GS_REL` | `tmi.ground_stop_release_time` | `gs_release_utc` |
| Control type | `nas:controlType` | `TMI_TYPE` | `tmi.control_type` | `ctl_type` |
| Control program | `nas:controlProgram` | `PROGRAM_NAME` | `tmi.program_name` | `ctl_prgm` |
| Control element | `nas:controlElement` | `FCA` / `FEA` | `tmi.control_element` | `ctl_element` |
| Exempt flag | `nas:exemptIndicator` | `EXEMPT` | `tmi.exempt_indicator` | `is_exempt` |
| Exempt reason | `nas:exemptReason` | `EXEMPT_REASON` | `tmi.exempt_reason` | `exempt_reason` |
| Delay value | `nas:delay/delayValue` | `DELAY` | `tmi.delay_value` | `delay_minutes` |
| Delay status | `nas:delay/delayStatus` | `DLY_STATUS` | `tmi.delay_status` | `delay_status` |
| Slot time | `nas:slot/time` | `SLOT_TIME` | `tmi.slot_time` | `slot_time_utc` |
| Program ID | -- | -- | `tmi.program_id` | `program_id` |
| Slot ID | `nas:slot/slotId` | `SLOT_ID` | `tmi.slot_id` | `slot_id` |
| EDCT | `nas:edct/time` | `EDCT` | `times.edct` | `edct_utc` |

### 9.3 Delay Status Codes (TFMS)

| Code | Meaning | Used in `tmi.delay_status` |
|------|---------|---------------------------|
| `GDP` | Ground Delay Program delay | Yes |
| `AFP` | Airspace Flow Program delay | Yes |
| `GSD` | Ground Stop Delay | Yes |
| `ALD` | Airline Delay | Yes |
| `DAS` | Delay Assignment | Yes |
| `TOD` | Time Out Delayed | Yes |
| `CTOP` | CTOP delay | Yes |
| `APREQ` | Approval Request delay | Yes |

### 9.4 Additional TMI Fields (DB only, 43 columns in `adl_flight_tmi`)

| DB Column | Type | Purpose |
|-----------|------|---------|
| `aslot` | nvarchar(16) | Alternative slot assignment |
| `delay_source` | nvarchar(16) | Delay source attribution |
| `is_popup` | bit | Popup (late-filing) flight |
| `popup_detected_utc` | datetime2 | When popup was detected |
| `ecr_pending` | bit | Exemption/Compression Request pending |
| `ecr_requested_cta` | datetime2 | Requested CTA in ECR |
| `reroute_status` | nvarchar(16) | Reroute compliance status |
| `reroute_id` | nvarchar(32) | Associated reroute definition |
| `absolute_delay_min` | int | Absolute delay (CTA - original ETA) |
| `schedule_variation_min` | int | Schedule variation |
| `assigned_utc` | datetime2 | When flight was assigned to program |
| `octd_utc` / `octa_utc` | datetime2 | Original (pre-reopt) controlled times |

---

## 10. Metering & Sequencing (TBFM/SimTraffic)

This section covers arrival metering data, typically sourced from SimTraffic or TBFM-like systems.

| Concept | FIXM 4.3 | TFMS | VATSWIM API | DB Column |
|---------|----------|------|-------------|-----------|
| Sequence number | -- | `SEQ` | `metering.sequence_number` | `sequence_number` |
| STA (metering) | `arrival/scheduledTimeOfArrival` | `STA` | `metering.scheduled_time_of_arrival` | `scheduled_time_of_arrival` |
| STD (metering) | `departure/scheduledTimeOfDeparture` | `STD` | `metering.scheduled_time_of_departure` | `scheduled_time_of_departure` |
| Meter fix | -- | `MF` | `metering.metering_point` | `metering_point` |
| Meter fix time | -- | `MF_TIME` | `metering.metering_time` | `metering_time` |
| Metering delay | -- | `DLA_ASGN` | `metering.delay_value` | `metering_delay` |
| Frozen | -- | `FROZEN` | `metering.frozen_indicator` | `metering_frozen` |
| Metering status | -- | `MTR_STS` | `metering.metering_status` | `metering_status` |
| Arrival stream/gate | -- | `GATE` | `metering.arrival_stream` | `arrival_stream` |
| Undelayed ETA | -- | `UETA` | `metering.undelayed_eta` | `undelayed_eta` |
| ETA at vertex | -- | `ETA_VT` | `metering.eta_vertex` | `eta_vertex` |
| STA at vertex | -- | `STA_VT` | `metering.sta_vertex` | `sta_vertex` |
| Vertex fix | -- | `VT_FIX` | `metering.vertex_point` | `vertex_point` |
| Metering source | -- | -- | `metering.metering_source` | `metering_source` |
| Last update | -- | -- | `metering.metering_updated_time` | `metering_updated_at` |

---

## 11. A-CDM Milestones (Airport Collaborative Decision Making)

| Milestone | Full Name | A-CDM | AIDX | FIXM | VATSWIM DB | In API? |
|-----------|-----------|-------|------|------|------------|---------|
| SOBT | Scheduled Off-Block Time | `sobt` | `ScheduledDepartureDateTime` | -- | `std_utc` | No |
| EOBT | Estimated Off-Block Time | `eobt` | `EstimatedOffBlockDateTime` | `estimatedOffBlockTime` | `estimated_off_block_time` | Yes |
| TOBT | Target Off-Block Time | `tobt` | `TargetOffBlockDateTime` | `targetOffBlockTime` | `target_off_block_time` | No |
| TSAT | Target Startup Approval Time | `tsat` | -- | `targetStartupApprovalTime` | `target_startup_approval_time` | No |
| ASAT | Actual Startup Approval Time | `asat` | -- | -- | `actual_startup_approval_time` | No |
| ASRT | Actual Startup Request Time | -- | -- | -- | `actual_startup_request_time` | No |
| AOBT | Actual Off-Block Time | `aobt` | `ActualOffBlockDateTime` | `actualOffBlockTime` | `actual_off_block_time` | Yes |
| EXOT | Estimated Taxi-Out Time | `exot` | -- | -- | `expected_taxi_out_time` | No |
| TTOT | Target Takeoff Time | `ttot` | `TargetDepartureDateTime` | `targetTakeOffTime` | `target_takeoff_time` | No |
| ETOT | Estimated Takeoff Time | `etot` | -- | -- | `etd_runway_utc` | No |
| ATOT | Actual Takeoff Time | `atot` | `ActualDepartureDateTime` | `actualTimeOfDeparture` | `actual_time_of_departure` | Yes |
| ELDT | Estimated Landing Time | `eldt` | `EstimatedArrivalDateTime` | `estimatedArrivalTime` | `estimated_runway_arrival_time` | Yes |
| TLDT | Target Landing Time | `tldt` | `TargetArrivalDateTime` | `targetLandingTime` | `target_landing_time` | No |
| ALDT | Actual Landing Time | `aldt` | `ActualArrivalDateTime` | `actualLandingTime` | `actual_landing_time` | Yes |
| EIBT | Estimated In-Block Time | `eibt` | `EstimatedInBlockDateTime` | `estimatedInBlockTime` | `estimated_in_block_time` | No |
| AIBT | Actual In-Block Time | `aibt` | `ActualInBlockDateTime` | `actualInBlockTime` | `actual_in_block_time` | Yes |
| SIBT | Scheduled In-Block Time | `sibt` | `ScheduledArrivalDateTime` | -- | `sta_utc` | No |

### CDM Source Tracking (DB only)

| DB Column | Type | Purpose |
|-----------|------|---------|
| `cdm_source` | nvarchar(50) | CDM data provider (e.g., SimTraffic, vACDM) |
| `cdm_updated_at` | datetime2 | Last CDM milestone update |

---

## 12. ECFMP Flow Control (EUROCONTROL-style, VATSWIM extension)

| DB Column | Type | Description |
|-----------|------|-------------|
| `flow_event_id` | int | ECFMP flow event identifier |
| `flow_event_code` | nvarchar(32) | Flow event code |
| `flow_priority` | nvarchar(16) | Flight priority in flow |
| `flow_gs_exempt` | bit | Ground stop exemption from flow |
| `flow_measure_id` | int | Applied flow measure ID |
| `flow_measure_ident` | nvarchar(32) | Flow measure identifier |
| `eu_atfcm_status` | nvarchar(16) | ATFCM regulation status |
| `eu_atfcm_excluded` | bit | Excluded from ATFCM |
| `eu_atfcm_ready` | bit | Ready for ATFCM processing |
| `eu_atfcm_slot_improvement` | bit | Slot improvement eligible |

These fields are populated when flights interact with ECFMP (European Centre for Flow Management on VATSIM). Not currently exposed in the REST API.

---

## 13. NAT Track Resolution (VATSWIM extension)

| DB Column | Type | Description |
|-----------|------|-------------|
| `resolved_nat_track` | nvarchar(8) | Resolved North Atlantic Track letter (e.g., `A`, `B`) |
| `nat_track_resolved_at` | datetime2 | When the track was resolved |
| `nat_track_source` | nvarchar(8) | Resolution source |

These fields track North Atlantic Organized Track System (OTS) assignments. Not currently exposed in the REST API.

---

## 14. SimBrief OFP Integration (DB only)

| DB Column | Type | Description |
|-----------|------|-------------|
| `simbrief_ofp_id` | nvarchar(32) | SimBrief Operational Flight Plan ID |
| `simbrief_route` | nvarchar(MAX) | SimBrief-optimized route string |
| `cost_index` | int | Cost index from OFP |
| `block_fuel` | decimal | Block fuel (kg) |
| `zero_fuel_weight` | decimal | Zero-fuel weight (kg) |
| `takeoff_weight` | decimal | Takeoff weight (kg) |
| `simbrief_updated_at` | datetime2 | Last SimBrief data update |

---

## 15. Airspace & Facility References

| Concept | FIXM 4.3 | AIXM 5.1 | TFMS | VATSWIM API |
|---------|----------|----------|------|-------------|
| ARTCC/FIR | `airspace/designator` | `AirspaceDesignator` | `CTR` | `position.current_airspace` |
| TRACON | -- | `AirspaceDesignator` | `TRACON` | `position.current_tracon` |
| Departure ARTCC | `departure/airspace` | -- | `DEP_CTR` | `flight_plan.departure_airspace` |
| Arrival ARTCC | `arrival/airspace` | -- | `DEST_CTR` | `flight_plan.arrival_airspace` |
| Fix/Waypoint | `routePoint/fix` | `DesignatedPoint` | `FIX` | `flight_plan.departure_point` / `arrival_point` |
| Airway | `routeSegment/airway` | `Route` | `AIRWAY` | (in `route_text`) |
| FCA | `nas:flowControlArea` | -- | `FCA` | `tmi.control_element` |
| FEA | `nas:flowEvaluationArea` | -- | `FEA` | `tmi.control_element` |

### Additional Airspace (DB only)

| DB Column | Source Table | Description |
|-----------|-------------|-------------|
| `current_sector_low` | `adl_flight_core` | Low-altitude sector name |
| `current_sector_high` | `adl_flight_core` | High-altitude sector name |
| `current_sector_superhigh` | `adl_flight_core` | Super-high sector name |
| `current_zone_airport` | `adl_flight_core` | Nearest airport code |
| `artccs_traversed` | `adl_flight_plan` | Space-delimited ARTCC list on route |
| `tracons_traversed` | `adl_flight_plan` | Space-delimited TRACON list on route |

---

## 16. Weather Integration

### VATSWIM Weather Fields (DB only)

| DB Column | Source Table | Description |
|-----------|-------------|-------------|
| `weather_impact` | `adl_flight_core` | Weather impact assessment |
| `weather_alert_ids` | `adl_flight_core` | Associated weather alert IDs |
| `eta_wind_component_kts` | `adl_flight_times` | Net wind component in ETA calculation |
| `eta_wind_adj_kts` | `adl_flight_times` | Wind adjustment to ETA |
| `eta_weather_delay_min` | `adl_flight_times` | Weather-related delay estimate |

### IWXXM/METAR Standards Reference

| Concept | IWXXM 3.0 | METAR/TAF | GFS/GRIB |
|---------|-----------|-----------|----------|
| Wind direction | `iwxxm:windDirection` | `ddd` | `UGRD`/`VGRD` |
| Wind speed | `iwxxm:windSpeed` | `ff` | `WIND` |
| Temperature | `iwxxm:airTemperature` | `TT` | `TMP` |
| Visibility | `iwxxm:prevailingVisibility` | `VVVV` | -- |
| Ceiling | `iwxxm:cloudLayer/base` | `ccc` | -- |
| Altimeter | `iwxxm:qnh` | `AAAA` | `PRMSL` |

---

## 17. Response Envelope Standards

### VATSWIM JSON Response (REST API)

```json
{
  "success": true,
  "data": [
    {
      "gufi": "VAT-20260329-UAL123-KJFK-KLAX",
      "flight_uid": "12345",
      "flight_key": "1234567|UAL123|KJFK|KLAX|1430",
      "identity": { ... },
      "flight_plan": { ... },
      "position": { ... },
      "progress": { ... },
      "times": { ... },
      "tmi": { ... },
      "metering": { ... },
      "data_source": "vatcscc",
      "first_tracked_time": "2026-03-29T14:25:00+00:00",
      "position_time": "2026-03-29T16:45:00+00:00",
      "logon_time": "2026-03-29T14:20:00+00:00",
      "last_sync_time": "2026-03-29T16:45:15+00:00"
    }
  ],
  "pagination": {
    "total": 2735,
    "page": 1,
    "per_page": 500,
    "total_pages": 6,
    "has_more": true
  },
  "timestamp": "2026-03-29T19:10:25+00:00"
}
```

### FIXM XML Envelope

```xml
<fx:Flight xmlns:fx="http://www.fixm.aero/flight/4.3"
           xmlns:fb="http://www.fixm.aero/base/4.3"
           xmlns:nas="http://www.faa.aero/nas/4.3">
  <fx:gufi>VAT-20260329-UAL123-KJFK-KLAX</fx:gufi>
  ...
</fx:Flight>
```

### AIDX XML Envelope

```xml
<aidx:IATA_AIDX_FlightLegNotifRQ
    xmlns:aidx="http://www.iata.org/IATA/2007/00"
    Version="22.1">
  <aidx:FlightLeg>
    ...
  </aidx:FlightLeg>
</aidx:IATA_AIDX_FlightLegNotifRQ>
```

---

## 18. Quick Reference: VATSWIM API Fields

Complete mapping of all 98 API response fields to their standards equivalents.

### Root Fields

| VATSWIM API Field | FIXM | TFMS | DB Column |
|-------------------|------|------|-----------|
| `gufi` | `gufi` | composite | `gufi` (computed) |
| `flight_uid` | -- | -- | `flight_uid` |
| `flight_key` | -- | -- | `flight_key` |
| `data_source` | -- | -- | (literal `vatcscc`) |
| `first_tracked_time` | -- | -- | `first_seen_utc` |
| `position_time` | -- | -- | `last_seen_utc` |
| `logon_time` | -- | -- | `logon_time_utc` |
| `last_sync_time` | -- | -- | `last_sync_utc` |

### Identity Block

| VATSWIM API Field | FIXM | TFMS | DB Column |
|-------------------|------|------|-----------|
| `identity.aircraft_identification` | `aircraftIdentification` | `ACID` | `callsign` |
| `identity.pilot_cid` | -- | -- | `cid` |
| `identity.aircraft_type` | `aircraftType` | `ACFT_TYPE` | `aircraft_type` |
| `identity.aircraft_type_icao` | `icaoAircraftTypeDesignator` | -- | `aircraft_icao` |
| `identity.aircraft_type_faa` | `otherAircraftType` | `ACFT_FAA` | `aircraft_faa` |
| `identity.weight_class` | -- | `WEIGHT_CLASS` | `weight_class` |
| `identity.wake_turbulence` | `wakeTurbulence` | `WAKE` | `wake_category` |
| `identity.operator_icao` | `operatorIcaoDesignator` | `AIRLINE` | `airline_icao` |
| `identity.operator_name` | -- | -- | `airline_name` |

### Flight Plan Block

| VATSWIM API Field | FIXM | TFMS | DB Column |
|-------------------|------|------|-----------|
| `flight_plan.departure_aerodrome` | `departureAerodrome` | `DEP` | `fp_dept_icao` |
| `flight_plan.arrival_aerodrome` | `arrivalAerodrome` | `DEST` | `fp_dest_icao` |
| `flight_plan.alternate_aerodrome` | `alternateAerodrome` | `ALT` | `fp_alt_icao` |
| `flight_plan.cruising_level` | `cruisingLevel` | `REQ_ALT` | `fp_altitude_ft` |
| `flight_plan.cruising_speed` | `cruisingSpeed` | `SPEED` | `fp_tas_kts` |
| `flight_plan.route_text` | `routeText` | `ROUTE` | `fp_route` |
| `flight_plan.remarks` | `remarks` | `REMARKS` | `fp_remarks` |
| `flight_plan.flight_rules_category` | `flightRulesCategory` | `FLT_RULES` | `fp_rule` |
| `flight_plan.departure_airspace` | `departureAirspace` | `DEP_CTR` | `fp_dept_artcc` |
| `flight_plan.arrival_airspace` | `arrivalAirspace` | `DEST_CTR` | `fp_dest_artcc` |
| `flight_plan.departure_tracon` | -- | `DEP_TRACON` | `fp_dept_tracon` |
| `flight_plan.arrival_tracon` | -- | `DEST_TRACON` | `fp_dest_tracon` |
| `flight_plan.departure_point` | `departurePoint` | `DEP_FIX` | `dfix` |
| `flight_plan.sid` | `sidStarReference` | `SID` | `dp_name` |
| `flight_plan.arrival_point` | `arrivalPoint` | `ARR_FIX` | `afix` |
| `flight_plan.star` | `sidStarReference` | `STAR` | `star_name` |
| `flight_plan.departure_runway` | `departureRunway` | `DEP_RWY` | `dep_runway` |
| `flight_plan.arrival_runway` | `arrivalRunway` | `ARR_RWY` | `arr_runway` |

### Position Block

| VATSWIM API Field | FIXM | DB Column |
|-------------------|------|-----------|
| `position.latitude` | `position/latitude` | `lat` |
| `position.longitude` | `position/longitude` | `lon` |
| `position.altitude` | `altitude` | `altitude_ft` |
| `position.track` | `trackAngle` | `heading_deg` * |
| `position.ground_speed` | `groundSpeed` | `groundspeed_kts` |
| `position.true_airspeed` | `trueAirspeed` | `true_airspeed_kts` |
| `position.vertical_rate` | `verticalRate` | `vertical_rate_fpm` |
| `position.current_airspace` | `airspace/designator` | `current_artcc` |
| `position.current_tracon` | -- | `current_tracon` |
| `position.current_airport_zone` | -- | `current_zone` |

### Progress Block

| VATSWIM API Field | FIXM | DB Column |
|-------------------|------|-----------|
| `progress.flight_status` | `FlightStatusType` | `phase` |
| `progress.is_active` | -- | `is_active` |
| `progress.distance_to_destination` | `distanceToDestination` | `dist_to_dest_nm` |
| `progress.distance_flown` | -- | `dist_flown_nm` |
| `progress.great_circle_distance` | -- | `gcd_nm` |
| `progress.total_flight_distance` | `routeDistance` | `route_total_nm` |
| `progress.percent_complete` | -- | `pct_complete` |
| `progress.time_to_destination` | -- | (computed from speed/distance) |

### Times Block

| VATSWIM API Field | FIXM | TFMS | DB Column |
|-------------------|------|------|-----------|
| `times.estimated_off_block_time` | `estimatedOffBlockTime` | `EOBT` | `estimated_off_block_time` |
| `times.estimated_time_of_arrival` | `estimatedArrivalTime` | `ETA` | `estimated_time_of_arrival` |
| `times.estimated_runway_arrival` | `estimatedRunwayTime` | `ERTA` | `estimated_runway_arrival_time` |
| `times.eta_source` | -- | -- | `eta_source` |
| `times.eta_method` | -- | -- | `eta_method` |
| `times.estimated_elapsed_time` | `estimatedElapsedTime` | `ETE` | `ete_minutes` |
| `times.actual_off_block_time` | `actualOffBlockTime` | `AOBT` | `actual_off_block_time` |
| `times.actual_time_of_departure` | `actualTimeOfDeparture` | `ATOT` | `actual_time_of_departure` |
| `times.actual_landing_time` | `actualLandingTime` | `ALDT` | `actual_landing_time` |
| `times.actual_in_block_time` | `actualInBlockTime` | `AIBT` | `actual_in_block_time` |
| `times.controlled_time_of_departure` | `controlledTimeOfDeparture` | `CTD` | `controlled_time_of_departure` |
| `times.controlled_time_of_arrival` | `controlledTimeOfArrival` | `CTA` | `controlled_time_of_arrival` |
| `times.edct` | `nas:edct/time` | `EDCT` | `edct_utc` |
| `times.taxi_start_time` | -- | -- | `taxi_start_time` |
| `times.departure_sequence_time` | -- | -- | `departure_sequence_time` |
| `times.hold_short_time` | -- | -- | `hold_short_time` |
| `times.runway_entry_time` | -- | -- | `runway_entry_time` |

### TMI Block

| VATSWIM API Field | FIXM | TFMS | DB Column |
|-------------------|------|------|-----------|
| `tmi.is_controlled` | -- | -- | (computed) |
| `tmi.ground_stop_held` | `nas:groundStop` | `GS` | `gs_held` |
| `tmi.ground_stop_release_time` | -- | `GS_REL` | `gs_release_utc` |
| `tmi.control_type` | `nas:controlType` | `TMI_TYPE` | `ctl_type` |
| `tmi.program_name` | `nas:controlProgram` | `PROGRAM_NAME` | `ctl_prgm` |
| `tmi.control_element` | `nas:controlElement` | `FCA`/`FEA` | `ctl_element` |
| `tmi.exempt_indicator` | `nas:exemptIndicator` | `EXEMPT` | `is_exempt` |
| `tmi.exempt_reason` | `nas:exemptReason` | `EXEMPT_REASON` | `exempt_reason` |
| `tmi.delay_value` | `nas:delay/delayValue` | `DELAY` | `delay_minutes` |
| `tmi.delay_status` | `nas:delay/delayStatus` | `DLY_STATUS` | `delay_status` |
| `tmi.slot_time` | `nas:slot/time` | `SLOT_TIME` | `slot_time_utc` |
| `tmi.program_id` | -- | -- | `program_id` |
| `tmi.slot_id` | `nas:slot/slotId` | `SLOT_ID` | `slot_id` |

### Metering Block

| VATSWIM API Field | TFMS | DB Column |
|-------------------|------|-----------|
| `metering.sequence_number` | `SEQ` | `sequence_number` |
| `metering.scheduled_time_of_arrival` | `STA` | `scheduled_time_of_arrival` |
| `metering.scheduled_time_of_departure` | `STD` | `scheduled_time_of_departure` |
| `metering.metering_point` | `MF` | `metering_point` |
| `metering.metering_time` | `MF_TIME` | `metering_time` |
| `metering.delay_value` | `DLA_ASGN` | `metering_delay` |
| `metering.frozen_indicator` | `FROZEN` | `metering_frozen` |
| `metering.metering_status` | `MTR_STS` | `metering_status` |
| `metering.arrival_stream` | `GATE` | `arrival_stream` |
| `metering.undelayed_eta` | `UETA` | `undelayed_eta` |
| `metering.eta_vertex` | `ETA_VT` | `eta_vertex` |
| `metering.sta_vertex` | `STA_VT` | `sta_vertex` |
| `metering.vertex_point` | `VT_FIX` | `vertex_point` |
| `metering.metering_source` | -- | `metering_source` |
| `metering.metering_updated_time` | -- | `metering_updated_at` |

---

## 19. Database Schema Summary

### swim_flights Table (SWIM_API database)

**Total columns:** 213 (denormalized from 6 ADL source tables)
**Update frequency:** Every 2 minutes via `swim_sync_daemon.php`
**Change detection:** SHA1 row hash on 21 volatile columns skips no-op updates

### ADL Source Tables (VATSIM_ADL database)

| Table | Columns | Key Fields |
|-------|---------|------------|
| `adl_flight_core` | 46 | flight_uid (PK), flight_key, callsign, cid, phase, is_active, current_artcc/tracon/sector |
| `adl_flight_plan` | 52 | route, airports, SID/STAR, parse_status, route_geometry, waypoints_json |
| `adl_flight_position` | 24 | lat/lon, altitude, speed, heading, position_geo (geography) |
| `adl_flight_times` | 97 | 50+ time columns, OOOI, fix times, wind ETA, buckets, phase milestones |
| `adl_flight_tmi` | 43 | TMI control: program, slot, delay, exemption, reroute, ECR, popup |
| `adl_flight_aircraft` | 12 | ICAO/FAA type, weight, wake, engine, airline |

### Additional swim_flights Columns (DB only, not in API response)

These columns exist in `swim_flights` but are not exposed in the REST API `formatFlightRecordFIXM()` response.

**Flight Plan / Route:**

| DB Column | Type | Description |
|-----------|------|-------------|
| `flight_id` | nvarchar(32) | VATSIM flight plan ID |
| `fp_route_expanded` | nvarchar(MAX) | Airway-expanded route string (all airways resolved to fix sequences) |
| `fp_fuel_minutes` | int | Filed fuel endurance (minutes, from flight plan) |
| `waypoint_count` | int | Number of parsed route waypoints |
| `parse_status` | nvarchar(16) | Route parsing status: `parsed`, `failed`, `pending`, `none` |
| `dtrsn` | nvarchar(32) | Departure transition name (DP transition fix) |
| `strsn` | nvarchar(32) | STAR transition name (arrival transition fix) |
| `equipment_qualifier` | nvarchar(8) | ICAO equipment qualifier from Field 10a (e.g., `S`, `G`, `R`) |
| `approach_procedure` | nvarchar(16) | Filed approach procedure name |

**Position / Airspace:**

| DB Column | Type | Description |
|-----------|------|-------------|
| `current_sector` | nvarchar(16) | Current ATC sector name |
| `current_sector_strata` | nvarchar(10) | Sector altitude stratum (`low`, `high`, `superhigh`) |
| `mach_number` | decimal | Current Mach number |

**Times:**

| DB Column | Type | Description |
|-----------|------|-------------|
| `estimated_time_of_departure` | datetime2 | Estimated time of departure (FIXM-aligned column) |
| `ate_minutes` | decimal | Actual time enroute (minutes elapsed since departure) |
| `eta_confidence` | nvarchar(8) | ETA confidence assessment |
| `eta_qualifier` | nvarchar(8) | ETA qualifier code (indicates ETA basis) |
| `etd_qualifier` | nvarchar(8) | ETD qualifier code |

**TMI:**

| DB Column | Type | Description |
|-----------|------|-------------|
| `slot_status` | nvarchar(16) | GDP slot status: `open`, `assigned`, `frozen`, `released` |
| `original_ctd` | datetime2 | Pre-reoptimization controlled departure time |
| `original_edct` | datetime2 | Pre-reoptimization EDCT |

**SimTraffic / Metering:**

| DB Column | Type | Description |
|-----------|------|-------------|
| `simtraffic_sync_utc` | datetime2 | Last SimTraffic data synchronization |
| `simtraffic_phase` | nvarchar(16) | SimTraffic-reported flight phase |
| `actual_metering_time` | datetime2 | Actual time at metering fix |
| `actual_vertex_time` | datetime2 | Actual time at vertex fix |

**Internal / Sync (not meaningful for consumers):**

| DB Column | Type | Description |
|-----------|------|-------------|
| `sync_source` | nvarchar(16) | Internal sync source identifier |
| `row_hash` | binary(20) | SHA1 hash for change detection (21 volatile columns) |
| `track_updated_at` | datetime2 | Last position/track data update |
| `adl_updated_at` | datetime2 | Last ADL sync timestamp |
| `last_source` | nvarchar(32) | Last data source that updated this flight |

### Related Tables (not in swim_flights)

| Table | Database | Purpose |
|-------|----------|---------|
| `adl_flight_waypoints` | VATSIM_ADL | Parsed route waypoints with ETAs (9.3M rows) |
| `adl_flight_trajectory` | VATSIM_ADL | Position history (1M+ rows) |
| `adl_flight_planned_crossings` | VATSIM_ADL | Boundary crossing predictions (20.5M rows) |
| `adl_flight_changelog` | VATSIM_ADL | Field-level audit trail |

---

## 20. Namespace Reference

| Standard | Namespace URI | Prefix |
|----------|---------------|--------|
| FIXM Core 4.3 | `http://www.fixm.aero/flight/4.3` | `fx` |
| FIXM Base 4.3 | `http://www.fixm.aero/base/4.3` | `fb` |
| FIXM US Extension | `http://www.faa.aero/nas/4.3` | `nas` |
| AIXM 5.1 | `http://www.aixm.aero/schema/5.1` | `aixm` |
| IWXXM 3.0 | `http://icao.int/iwxxm/3.0` | `iwxxm` |
| AIDX 22.1 | `http://www.iata.org/IATA/2007/00` | `aidx` |
| GML 3.2 | `http://www.opengis.net/gml/3.2` | `gml` |
| GeoJSON | -- (JSON format) | -- |
| vATCSCC Extension | `http://vatcscc.org/schema/1.0` | `vatc` |

---

## Appendix A: API Query Filters

| Parameter | Example | Description |
|-----------|---------|-------------|
| `status` | `active` / `completed` | Default: active |
| `dept_icao` | `KJFK,KLAX` | Comma-separated departure airports |
| `dest_icao` | `EGLL` | Comma-separated destination airports |
| `callsign` | `AAL*` | Wildcard supported |
| `artcc` / `dest_artcc` | `ZNY` | Filter by ARTCC |
| `dep_artcc` | `ZDC` | Departure ARTCC |
| `dest_tracon` / `dep_tracon` | `N90` | TRACON filter |
| `current_artcc` | `ZBW` | Position-based ARTCC |
| `current_tracon` | `N90` | Position-based TRACON |
| `phase` | `CRUISING,DESCENDING` | Comma-separated phases |
| `tmi_controlled` | `true` | GS held or CTL_TYPE set |
| `page` | `1` | Pagination (default: 1) |
| `per_page` | `500` | Results per page (max: 10000) |
| `format` | `fixm` | fixm, xml, geojson, csv, kml, ndjson |

---

*Document version 2.0 -- updated 2026-03-29 from verified production database schemas (213 swim_flights columns, 274 ADL columns) and live API response (98 fields).*
