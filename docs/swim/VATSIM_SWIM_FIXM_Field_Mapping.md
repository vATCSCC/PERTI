# VATSIM SWIM API Field Mapping
## FIXM + TFMS Alignment with vATCSCC Extensions

**Version:** 1.1  
**Date:** 2026-01-16  
**Purpose:** Comprehensive field mapping using FIXM as primary standard, TFMS for abbreviations, with vATCSCC extensions for VATSIM-specific fields.

---

## Naming Conventions

| Layer | Convention | Example |
|-------|------------|---------|
| **FIXM (Full)** | camelCase, hierarchical | `actualOffBlockTime` |
| **TFMS (Abbrev)** | UPPERCASE, 2-6 chars | `AOBT` |
| **vATCSCC Extension** | camelCase with `vATCSCC:` prefix | `vATCSCC:pilotCid` |
| **JSON API** | snake_case | `actual_off_block_time` |
| **Database** | snake_case | `actual_off_block_time` |

---

## 1. Flight Identification

| Concept | FIXM Field | TFMS | JSON API | Current DB | New DB | Migration |
|---------|------------|------|----------|------------|--------|-----------|
| **Unique Flight ID** | `gufi` | `GUFI` | `gufi` | `gufi` | `gufi` | ✅ OK |
| **Internal Flight Key** | — | — | `flight_key` | `flight_key` | `flight_key` | ✅ OK |
| **Callsign** | `aircraftIdentification` | `ACID` | `aircraft_identification` | `callsign` | `aircraft_identification` | 🔄 Rename |
| **VATSIM Pilot CID** | `vATCSCC:pilotCid` ⭐ | `CID` | `pilot_cid` | `cid` | `pilot_cid` | 🔄 Rename |
| **VATSIM Pilot Name** | `vATCSCC:pilotName` ⭐ | — | `pilot_name` | — | `pilot_name` | ➕ Add |
| **VATSIM Pilot Rating** | `vATCSCC:pilotRating` ⭐ | — | `pilot_rating` | — | `pilot_rating` | ➕ Add |
| **Flight Number** | `flightNumber` | `FLT_NUM` | `flight_number` | — | `flight_number` | ➕ Add |
| **Airline (ICAO)** | `operatorIcaoDesignator` | `AIRLINE` | `operator_icao` | `airline_icao` | `operator_icao` | 🔄 Rename |
| **Airline (IATA)** | `operatorIataDesignator` | — | `operator_iata` | — | `operator_iata` | ➕ Add |
| **Airline Name** | `operatorName` | — | `operator_name` | `airline_name` | `operator_name` | 🔄 Rename |
| **Registration** | `registration` | `REG` | `registration` | — | `registration` | ➕ Add |
| **SSR Code (Squawk)** | `assignedCode` | `BCN` | `assigned_code` | `transponder` | `assigned_code` | 🔄 Rename |

---

## 2. Aircraft Information

| Concept | FIXM Field | TFMS | JSON API | Current DB | New DB | Migration |
|---------|------------|------|----------|------------|--------|-----------|
| **Aircraft Type (ICAO)** | `aircraftType` | `TYPE` | `aircraft_type` | `aircraft_type` | `aircraft_type` | ✅ OK |
| **Aircraft Type (FAA)** | `otherAircraftType` | `APTS` | `other_aircraft_type` | `aircraft_faa` | `other_aircraft_type` | 🔄 Rename |
| **Wake Category (ICAO)** | `wakeTurbulence` | `WAKE` | `wake_turbulence` | `wake_category` | `wake_turbulence` | 🔄 Rename |
| **Weight Class (FAA)** | `nas:weightClass` | `WGTCLASS` | `weight_class` | `weight_class` | `weight_class` | ✅ OK |
| **Engine Type** | `engineType` | — | `engine_type` | `engine_type` | `engine_type` | ✅ OK |
| **Engine Count** | `engineCount` | — | `engine_count` | — | `engine_count` | ➕ Add |
| **Aircraft Category** | `aircraftCategory` | `AC_CAT` | `aircraft_category` | `ac_cat` | `aircraft_category` | 🔄 Rename |
| **Equipment Qualifier** | `equipmentQualifier` | `EQUIP` | `equipment_qualifier` | — | `equipment_qualifier` | ➕ Add |
| **User Category** | `nas:userCategory` | `USER_CAT` | `user_category` | `user_category` | `user_category` | ✅ OK |
| **CDM Participant** | `nas:cdmParticipant` | `CDM` | `cdm_participant` | `cdm_participant` | `cdm_participant` | ✅ OK |

---

## 3. Airports & Locations

| Concept | FIXM Field | TFMS | JSON API | Current DB | New DB | Migration |
|---------|------------|------|----------|------------|--------|-----------|
| **Departure Airport** | `departureAerodrome` | `DEPT` | `departure_aerodrome` | `dept_icao` | `departure_aerodrome` | 🔄 Rename |
| **Arrival Airport** | `arrivalAerodrome` | `DEST` | `arrival_aerodrome` | `dest_icao` | `arrival_aerodrome` | 🔄 Rename |
| **Alternate Airport** | `alternateAerodrome` | `ALT1` | `alternate_aerodrome` | — | `alternate_aerodrome` | ➕ Add |
| **Diversion Airport** | `diversionAerodrome` | `DIV` | `diversion_aerodrome` | — | `diversion_aerodrome` | ➕ Add |
| **Departure Gate** | `departureGate` | — | `departure_gate` | — | `departure_gate` | ➕ Add |
| **Arrival Gate** | `arrivalGate` | — | `arrival_gate` | — | `arrival_gate` | ➕ Add |
| **Departure Runway** | `departureRunway` | `DRWY` | `departure_runway` | — | `departure_runway` | ➕ Add |
| **Arrival Runway** | `arrivalRunway` | `ARWY` | `arrival_runway` | — | `arrival_runway` | ➕ Add |
| **Departure ARTCC** | `departureAirspace` | `DEPT_CTR` | `departure_airspace` | `fp_dept_artcc` | `departure_airspace` | 🔄 Rename |
| **Arrival ARTCC** | `arrivalAirspace` | `DEST_CTR` | `arrival_airspace` | `fp_dest_artcc` | `arrival_airspace` | 🔄 Rename |
| **Departure TRACON** | `vATCSCC:departureTracon` ⭐ | `DEPT_APP` | `departure_tracon` | `fp_dept_tracon` | `departure_tracon` | 🔄 Rename |
| **Arrival TRACON** | `vATCSCC:arrivalTracon` ⭐ | `DEST_APP` | `arrival_tracon` | `fp_dest_tracon` | `arrival_tracon` | 🔄 Rename |

---

## 4. Route & Trajectory

| Concept | FIXM Field | TFMS | JSON API | Current DB | New DB | Migration |
|---------|------------|------|----------|------------|--------|-----------|
| **Filed Route** | `routeText` | `ROUTE` | `route_text` | `route` | `route_text` | 🔄 Rename |
| **Current Route** | `currentRouteText` | `CURR_RTE` | `current_route_text` | — | `current_route_text` | ➕ Add |
| **Parsed Route** | `vATCSCC:parsedRoute` ⭐ | — | `parsed_route` | `route_parsed` | `parsed_route` | 🔄 Rename |
| **Route Quality** | `vATCSCC:routeQuality` ⭐ | — | `route_quality` | `route_quality` | `route_quality` | ✅ OK |
| **SID** | `standardInstrumentDeparture` | `SID` | `sid` | — | `sid` | ➕ Add |
| **STAR** | `standardInstrumentArrival` | `STAR` | `star` | — | `star` | ➕ Add |
| **Approach** | `approachProcedure` | `IAP` | `approach_procedure` | — | `approach_procedure` | ➕ Add |
| **Departure Fix** | `departurePoint` | `DFIX` | `departure_point` | — | `departure_point` | ➕ Add |
| **Arrival Fix** | `arrivalPoint` | `AFIX` | `arrival_point` | — | `arrival_point` | ➕ Add |
| **Cruise Altitude** | `cruisingLevel` | `ALT` | `cruising_level` | `cruise_altitude` | `cruising_level` | 🔄 Rename |
| **Cruise Speed** | `cruisingSpeed` | `SPD` | `cruising_speed` | `cruise_speed` | `cruising_speed` | 🔄 Rename |
| **Flight Rules** | `flightRulesCategory` | `FLT_RULES` | `flight_rules_category` | `flight_rules` | `flight_rules_category` | 🔄 Rename |
| **Total Distance** | `totalFlightDistance` | `DIST` | `total_flight_distance` | `route_total_nm` | `total_flight_distance` | 🔄 Rename |
| **GCD Distance** | `greatCircleDistance` | `GCD` | `great_circle_distance` | `gcd_nm` | `great_circle_distance` | 🔄 Rename |
| **Remarks** | `remarks` | `RMK` | `remarks` | `remarks` | `remarks` | ✅ OK |

---

## 5. Position & Track Data

| Concept | FIXM Field | TFMS | JSON API | Current DB | New DB | Migration |
|---------|------------|------|----------|------------|--------|-----------|
| **Latitude** | `position/latitude` | `LAT` | `latitude` | `lat` | `latitude` | 🔄 Rename |
| **Longitude** | `position/longitude` | `LON` | `longitude` | `lon` | `longitude` | 🔄 Rename |
| **Altitude (ft)** | `altitude` | `ALT` | `altitude` | `altitude` | `altitude` | ✅ OK |
| **Flight Level** | `flightLevel` | `FL` | `flight_level` | — | `flight_level` | ➕ Add |
| **Track (°)** | `track` | `HDG` | `track` | `heading` | `track` | 🔄 Rename |
| **Ground Speed (kts)** | `groundSpeed` | `GS` | `ground_speed` | `groundspeed` | `ground_speed` | 🔄 Rename |
| **True Airspeed** | `trueAirspeed` | `TAS` | `true_airspeed` | — | `true_airspeed` | ➕ Add |
| **Mach Number** | `machNumber` | `MACH` | `mach_number` | — | `mach_number` | ➕ Add |
| **Vertical Rate** | `verticalRate` | `VR` | `vertical_rate` | — | `vertical_rate` | ➕ Add |
| **Position Time** | `positionTime` | `POSTIME` | `position_time` | `last_seen_utc` | `position_time` | 🔄 Rename |
| **Distance to Dest** | `distanceToDestination` | `DTG` | `distance_to_destination` | `dist_to_dest_nm` | `distance_to_destination` | 🔄 Rename |
| **Distance Flown** | `distanceFlown` | — | `distance_flown` | `dist_flown_nm` | `distance_flown` | 🔄 Rename |
| **Percent Complete** | `vATCSCC:percentComplete` ⭐ | `PCT` | `percent_complete` | `pct_complete` | `percent_complete` | 🔄 Rename |
| **Current ARTCC** | `currentAirspace` | `CUR_ARTCC` | `current_airspace` | — | `current_airspace` | ➕ Add |
| **Current Sector** | `currentSector` | `SECTOR` | `current_sector` | — | `current_sector` | ➕ Add |
| **Current Airport Zone** | `vATCSCC:currentAirportZone` ⭐ | `ZONE` | `current_airport_zone` | `current_zone` | `current_airport_zone` | 🔄 Rename |
| **Zone Airport** | `vATCSCC:currentZoneAirport` ⭐ | — | `current_zone_airport` | `current_zone_airport` | `current_zone_airport` | ✅ OK |

---

## 6. Flight Times - OOOI

| Concept | FIXM Field | TFMS | JSON API | Current DB | New DB | Migration |
|---------|------------|------|----------|------------|--------|-----------|
| **OUT (Block Out)** | `actualOffBlockTime` | `AOBT` | `actual_off_block_time` | `out_utc` | `actual_off_block_time` | 🔄 Rename |
| **OFF (Wheels Up)** | `actualTimeOfDeparture` | `ATOT` | `actual_time_of_departure` | `off_utc` | `actual_time_of_departure` | 🔄 Rename |
| **ON (Wheels Down)** | `actualLandingTime` | `ALDT` | `actual_landing_time` | `on_utc` | `actual_landing_time` | 🔄 Rename |
| **IN (Block In)** | `actualInBlockTime` | `AIBT` | `actual_in_block_time` | `in_utc` | `actual_in_block_time` | 🔄 Rename |

---

## 7. Flight Times - Estimated

| Concept | FIXM Field | TFMS | JSON API | Current DB | New DB | Migration |
|---------|------------|------|----------|------------|--------|-----------|
| **Est Off-Block** | `estimatedOffBlockTime` | `EOBT` | `estimated_off_block_time` | `etd_utc` | `estimated_off_block_time` | 🔄 Rename |
| **Est Departure** | `estimatedTimeOfDeparture` | `ETD` | `estimated_time_of_departure` | — | `estimated_time_of_departure` | ➕ Add |
| **Est Arrival** | `estimatedTimeOfArrival` | `ETA` | `estimated_time_of_arrival` | `eta_utc` | `estimated_time_of_arrival` | 🔄 Rename |
| **Est In-Block** | `estimatedInBlockTime` | `EIBT` | `estimated_in_block_time` | — | `estimated_in_block_time` | ➕ Add |
| **ETA Qualifier** | `etaQualifier` | `ETA_PREFIX` | `eta_qualifier` | — | `eta_qualifier` | ➕ Add |
| **ETD Qualifier** | `etdQualifier` | `ETD_PREFIX` | `etd_qualifier` | — | `etd_qualifier` | ➕ Add |

---

## 8. Flight Times - Controlled (TMI)

| Concept | FIXM Field | TFMS | JSON API | Current DB | New DB | Migration |
|---------|------------|------|----------|------------|--------|-----------|
| **EDCT** | `expectedDepartureClearanceTime` | `EDCT` | `edct` | — | `edct` | ➕ Add |
| **Original EDCT** | `originalEdct` | `OEDCT` | `original_edct` | — | `original_edct` | ➕ Add |
| **Controlled Departure** | `controlledTimeOfDeparture` | `CTD` | `controlled_time_of_departure` | — | `controlled_time_of_departure` | ➕ Add |
| **Original CTD** | `originalCtd` | `OCTD` | `original_ctd` | — | `original_ctd` | ➕ Add |
| **Controlled Arrival** | `controlledTimeOfArrival` | `CTA` | `controlled_time_of_arrival` | — | `controlled_time_of_arrival` | ➕ Add |
| **Slot Time** | `slotTime` | `SLOT` | `slot_time` | — | `slot_time` | ➕ Add |

---

## 9. Flight Times - A-CDM Milestones

| Concept | FIXM Field | TFMS | JSON API | Current DB | New DB | Migration |
|---------|------------|------|----------|------------|--------|-----------|
| **TOBT** | `targetOffBlockTime` | `TOBT` | `target_off_block_time` | — | `target_off_block_time` | ➕ Add |
| **TSAT** | `targetStartupApprovalTime` | `TSAT` | `target_startup_approval_time` | — | `target_startup_approval_time` | ➕ Add |
| **TTOT** | `targetTakeoffTime` | `TTOT` | `target_takeoff_time` | — | `target_takeoff_time` | ➕ Add |
| **TLDT** | `targetLandingTime` | `TLDT` | `target_landing_time` | — | `target_landing_time` | ➕ Add |

---

## 10. Flight Times - Zone Detection (vATCSCC Extension)

| Concept | FIXM-like Field | JSON API | Current DB | New DB | Migration |
|---------|-----------------|----------|------------|--------|-----------|
| **Parking Left** | `vATCSCC:parkingLeftTime` ⭐ | `parking_left_time` | `parking_left_utc` | `parking_left_time` | 🔄 Rename |
| **Taxiway Entered** | `vATCSCC:taxiwayEnteredTime` ⭐ | `taxiway_entered_time` | `taxiway_entered_utc` | `taxiway_entered_time` | 🔄 Rename |
| **Hold Entered** | `vATCSCC:holdEnteredTime` ⭐ | `hold_entered_time` | `hold_entered_utc` | `hold_entered_time` | 🔄 Rename |
| **Runway Entered** | `vATCSCC:runwayEnteredTime` ⭐ | `runway_entered_time` | `runway_entered_utc` | `runway_entered_time` | 🔄 Rename |
| **Rotation** | `vATCSCC:rotationTime` ⭐ | `rotation_time` | `rotation_utc` | `rotation_time` | 🔄 Rename |
| **Approach Start** | `vATCSCC:approachStartTime` ⭐ | `approach_start_time` | `approach_start_utc` | `approach_start_time` | 🔄 Rename |
| **Threshold** | `vATCSCC:thresholdTime` ⭐ | `threshold_time` | `threshold_utc` | `threshold_time` | 🔄 Rename |
| **Touchdown** | `vATCSCC:touchdownTime` ⭐ | `touchdown_time` | `touchdown_utc` | `touchdown_time` | 🔄 Rename |
| **Rollout End** | `vATCSCC:rolloutEndTime` ⭐ | `rollout_end_time` | `rollout_end_utc` | `rollout_end_time` | 🔄 Rename |
| **Parking Entered** | `vATCSCC:parkingEnteredTime` ⭐ | `parking_entered_time` | `parking_entered_utc` | `parking_entered_time` | 🔄 Rename |

---

## 11. Flight Status & Phase

| Concept | FIXM Field | TFMS | JSON API | Current DB | New DB | Migration |
|---------|------------|------|----------|------------|--------|-----------|
| **Flight Status** | `flightStatus` | `STATUS` | `flight_status` | `phase` | `flight_status` | 🔄 Rename |
| **Is Active** | `vATCSCC:isActive` ⭐ | `ACTIVE` | `is_active` | `is_active` | `is_active` | ✅ OK |
| **Last Source** | `vATCSCC:lastSource` ⭐ | `SRC` | `last_source` | `last_source` | `last_source` | ✅ OK |

---

## 12. TMI / ATFM Control Data

| Concept | FIXM Field | TFMS | JSON API | Current DB | New DB | Migration |
|---------|------------|------|----------|------------|--------|-----------|
| **Control Type** | `controlType` | `CTL_TYPE` | `control_type` | — | `control_type` | ➕ Add |
| **Control Element** | `controlElement` | `CTL_ELEM` | `control_element` | — | `control_element` | ➕ Add |
| **Program Name** | `programName` | `CTL_PRGM` | `program_name` | — | `program_name` | ➕ Add |
| **Delay Value** | `delayValue` | `DLA_ASGN` | `delay_value` | — | `delay_value` | ➕ Add |
| **Delay Status** | `delayStatus` | `DLY_STATUS` | `delay_status` | — | `delay_status` | ➕ Add |
| **Ground Stop Held** | `groundStopHeld` | `GS_HELD` | `ground_stop_held` | — | `ground_stop_held` | ➕ Add |
| **Exempt Indicator** | `exemptIndicator` | `EXEMPT` | `exempt_indicator` | — | `exempt_indicator` | ➕ Add |
| **Exempt Reason** | `exemptReason` | `EXEMPT_RSN` | `exempt_reason` | — | `exempt_reason` | ➕ Add |

---

## 12.1 External Flow Management (ECFMP, NavCanada, VATPAC)

Provider-agnostic integration for external flow management systems. FIXM-aligned field mapping for global interoperability.

### Flow Providers

| Concept | FIXM Field | JSON API | DB Column | Description |
|---------|------------|----------|-----------|-------------|
| **Provider Code** | `providerCode` | `provider.code` | `provider_code` | ECFMP, NAVCAN, VATPAC |
| **Provider Name** | `providerName` | `provider.name` | `provider_name` | Display name |
| **API Base URL** | — | `api.base_url` | `api_base_url` | Provider API endpoint |
| **Region Codes** | `flightInformationRegion` | `coverage.regions` | `region_codes_json` | EUR, NAM, NAT, PAC |
| **FIR Codes** | `flightInformationRegion` | `coverage.firs` | `fir_codes_json` | EGTT, CZQX, etc. |

### Flow Events (FIXM: `/flight/specialHandling`)

| Concept | FIXM Field | JSON API | DB Column | Description |
|---------|------------|----------|-----------|-------------|
| **Event Code** | `specialHandlingCode` | `code` | `event_code` | CTP2026, FNO2026 |
| **Event Name** | `specialHandlingDescription` | `name` | `event_name` | Cross the Pond 2026 |
| **Event Type** | `specialHandlingType` | `type` | `event_type` | SPECIAL, EXERCISE, VIP |
| **Start Time** | `timeRange/start` | `timeRange.start` | `start_utc` | Event start |
| **End Time** | `timeRange/end` | `timeRange.end` | `end_utc` | Event end |
| **GS Exempt** | `exemptIndicator` | `exemptions.groundStop` | `gs_exempt` | Event flights exempt from GS |
| **GDP Priority** | `priorityIndicator` | `exemptions.gdpPriority` | `gdp_priority` | Event flights get priority |
| **Participant Count** | — | `participantCount` | `participant_count` | Number of registered pilots |

### Flow Event Participants (FIXM: `/flight/flightIdentification`)

| Concept | FIXM Field | JSON API | DB Column | Description |
|---------|------------|----------|-----------|-------------|
| **Pilot CID** | `vATCSCC:pilotCid` | `cid` | `pilot_cid` | VATSIM CID |
| **Callsign** | `aircraftIdentification` | `callsign` | `callsign` | Pre-registered callsign |
| **Departure** | `departureAerodrome` | `departure` | `dep_aerodrome` | Origin ICAO |
| **Arrival** | `arrivalAerodrome` | `arrival` | `arr_aerodrome` | Destination ICAO |
| **Flight Matched** | — | `flightMatched` | `flight_uid` | Matched to active flight |

### Flow Measures (FIXM: `/atfm/flowElement`)

| Concept | FIXM Field | TFMS | JSON API | DB Column | Description |
|---------|------------|------|----------|-----------|-------------|
| **Measure Ident** | `flowMeasureIdentifier` | `FM_ID` | `ident` | `ident` | EGTT22A |
| **Measure Type** | `flowMeasureType` | `FM_TYPE` | `type` | `measure_type` | See table below |
| **Measure Value** | `flowMeasureValue` | `FM_VAL` | `value` | `measure_value` | Numeric value |
| **Measure Unit** | `flowMeasureUnit` | — | `unit` | `measure_unit` | NM, MIN, SEC, PER_HOUR |
| **Reason** | `atfmReason` | `FM_RSN` | `reason` | `reason` | CTP Event Traffic |
| **Control Element** | `controlElement` | `CTL_ELEM` | `controlElement` | `ctl_element` | Airport/FIR/Fix |
| **Element Type** | `elementType` | `ELM_TYPE` | `elementType` | `element_type` | APT, FIR, FCA, FIX |
| **Mandatory Route** | `routeConstraint` | `MAND_RTE` | `mandatoryRoute` | `mandatory_route_json` | Required route fixes |

### Measure Types (TFMS-aligned)

| Type | FIXM Equivalent | Description | Unit |
|------|-----------------|-------------|------|
| `MIT` | `milesInTrail` | Miles-In-Trail | NM |
| `MINIT` | `minutesInTrail` | Minutes-In-Trail | MIN |
| `MDI` | `minimumDepartureInterval` | Minimum Departure Interval | SEC |
| `RATE` | `departureRate` | Departure Rate Cap | PER_HOUR |
| `GS` | `groundStop` | Ground Stop | — |
| `GDP` | `groundDelayProgram` | Ground Delay Program | MIN |
| `AFP` | `airspaceFlowProgram` | Airspace Flow Program | MIN |
| `REROUTE` | `routeConstraint` | Mandatory Reroute | — |

### Flow Measure Filters (FIXM: `/atfm/flowElement/filter`)

| Concept | FIXM Field | JSON API | DB (filters_json) | Description |
|---------|------------|----------|-------------------|-------------|
| **Departure Airports** | `departureAerodrome` | `filters.departureAerodrome` | `adep` | Origin filter |
| **Arrival Airports** | `arrivalAerodrome` | `filters.arrivalAerodrome` | `ades` | Destination filter |
| **Departure FIRs** | `departureFir` | `filters.departureFir` | `adep_fir` | Origin FIR filter |
| **Arrival FIRs** | `arrivalFir` | `filters.arrivalFir` | `ades_fir` | Destination FIR filter |
| **Waypoints** | `routePoint` | `filters.waypoints` | `waypoints` | Route point filter |
| **Airways** | `airway` | `filters.airways` | `airways` | Airway filter |
| **Flight Level** | `flightLevel` | `filters.flightLevel` | `levels` | Min/max altitude |
| **Aircraft Type** | `aircraftType` | `filters.aircraftType` | `aircraft_type` | Type filter |
| **Event Membership** | — | `filters.memberEvent` | `member_event` | Event ID filter |

### Flight-Level Flow Data (adl_flight_tmi extension)

| Concept | FIXM Field | JSON API | DB Column | Description |
|---------|------------|----------|-----------|-------------|
| **Flow Event ID** | — | `ecfmp.event.id` | `flow_event_id` | FK to tmi_flow_events |
| **Flow Event Code** | `specialHandlingCode` | `ecfmp.event.code` | `flow_event_code` | CTP2026 |
| **Flow Priority** | `priorityIndicator` | `ecfmp.priority` | `flow_priority` | EVENT, STANDARD |
| **GS Exempt Flag** | `exemptIndicator` | `ecfmp.gsExempt` | `flow_gs_exempt` | Event flight exempt |
| **Flow Measure ID** | — | `ecfmp.activeMeasure.id` | `flow_measure_id` | FK to tmi_flow_measures |
| **Flow Measure Ident** | `flowMeasureIdentifier` | `ecfmp.activeMeasure.ident` | `flow_measure_ident` | EGTT22A |

---

## 13. Metering & Sequencing (TBFM)

### Core TBFM Fields (FIXM 4.3)

| Concept | FIXM Field | TFMS | JSON API | Current DB | New DB | Migration |
|---------|------------|------|----------|------------|--------|-----------|
| **Meter Fix** | `meteringPoint` | `MF` | `metering_point` | — | `metering_point` | ➕ Add |
| **Meter Time** | `meteringTime` | `MF_TIME` | `metering_time` | — | `metering_time` | ➕ Add |
| **STA (Runway)** | `scheduledTimeOfArrival` | `STA` | `scheduled_time_of_arrival` | — | `scheduled_time_of_arrival` | ➕ Add |
| **STD (Runway)** | `scheduledTimeOfDeparture` | `STD` | `scheduled_time_of_departure` | — | `scheduled_time_of_departure` | ➕ Add |
| **Sequence Number** | `sequenceNumber` | `SEQ` | `sequence_number` | — | `sequence_number` | ➕ Add |
| **Delay Value** | `delayValue` | `DLA_ASGN` | `metering_delay` | — | `metering_delay` | ➕ Add |
| **Frozen Indicator** | `frozenIndicator` | `FROZEN` | `metering_frozen` | — | `metering_frozen` | ➕ Add |
| **Arrival Stream** | `arrivalStream` | `GATE` | `arrival_stream` | — | `arrival_stream` | ➕ Add |

### TBFM Extended Fields (vATCSCC)

| Concept | FIXM-like Field | TFMS | JSON API | New DB | Description |
|---------|-----------------|------|----------|--------|-------------|
| **Metering Status** | `vATCSCC:meteringStatus` ⭐ | `MTR_STS` | `metering_status` | `metering_status` | UNMETERED/METERED/FROZEN/SUSPENDED/EXEMPT |
| **Undelayed ETA** | `vATCSCC:undelayedEta` ⭐ | `UETA` | `undelayed_eta` | `undelayed_eta` | Baseline ETA without TBFM delay |
| **ETA at Vertex** | `vATCSCC:etaVertex` ⭐ | `ETA_VT` | `eta_vertex` | `eta_vertex` | ETA at corner post/vertex |
| **STA at Vertex** | `vATCSCC:staVertex` ⭐ | `STA_VT` | `sta_vertex` | `sta_vertex` | Assigned time at vertex |
| **Vertex Point** | `vATCSCC:vertexPoint` ⭐ | `VT_FIX` | `vertex_point` | `vertex_point` | Vertex fix identifier |
| **Metering Source** | `vATCSCC:meteringSource` ⭐ | `MTR_SRC` | `metering_source` | `metering_source` | simtraffic/vatcscc/vnas/topsky |
| **Metering Updated** | `vATCSCC:meteringUpdatedTime` ⭐ | — | `metering_updated_at` | `metering_updated_at` | Last metering update timestamp |

### Metering Status Values

| Status | Description |
|--------|-------------|
| `UNMETERED` | Not yet under TBFM control |
| `METERED` | Active TBFM metering, sequence may change |
| `FROZEN` | Sequence frozen, no further changes |
| `SUSPENDED` | TBFM suspended (e.g., weather deviation) |
| `EXEMPT` | Exempt from metering (priority, emergency) |

### SimTraffic Integration Flow

```
SimTraffic  ──(POST /ingest/metering)──►  SWIM  ──(GET /metering/{airport})──►  vNAS  ──►  CRC Datablock
```

**SimTraffic provides:**
- `sequence_number` - Arrival sequence (1 = next to land)
- `scheduled_time_of_arrival` - STA at runway threshold
- `metering_time` - STA at meter fix
- `metering_delay` - Minutes of assigned delay
- `metering_frozen` - Frozen flag
- `arrival_stream` - Corner post assignment

**vNAS/CRC displays:**
- Sequence number in datablock
- STA/ETA comparison (delay indicator)
- Frozen status

---

## 14. SimBrief Integration (vATCSCC Extension)

| Concept | FIXM-like Field | JSON API | Current DB | New DB | Migration |
|---------|-----------------|----------|------------|--------|-----------|
| **OFP ID** | `vATCSCC:simbriefOfpId` ⭐ | `simbrief_ofp_id` | — | `simbrief_ofp_id` | ➕ Add |
| **SimBrief Route** | `vATCSCC:simbriefRoute` ⭐ | `simbrief_route` | — | `simbrief_route` | ➕ Add |
| **Cost Index** | `vATCSCC:costIndex` ⭐ | `cost_index` | — | `cost_index` | ➕ Add |
| **Block Fuel** | `vATCSCC:blockFuel` ⭐ | `block_fuel` | — | `block_fuel` | ➕ Add |
| **ZFW** | `vATCSCC:zeroFuelWeight` ⭐ | `zero_fuel_weight` | — | `zero_fuel_weight` | ➕ Add |
| **TOW** | `vATCSCC:takeoffWeight` ⭐ | `takeoff_weight` | — | `takeoff_weight` | ➕ Add |

---

## 15. Data Source Timestamps (vATCSCC Extension)

| Concept | FIXM-like Field | JSON API | Current DB | New DB | Migration |
|---------|-----------------|----------|------------|--------|-----------|
| **ADL Updated** | `vATCSCC:adlUpdatedTime` ⭐ | `adl_updated_at` | `adl_updated_at` | `adl_updated_at` | ✅ OK |
| **Track Updated** | `vATCSCC:trackUpdatedTime` ⭐ | `track_updated_at` | `track_updated_at` | `track_updated_at` | ✅ OK |
| **SimBrief Updated** | `vATCSCC:simbriefUpdatedTime` ⭐ | `simbrief_updated_at` | `simbrief_updated_at` | `simbrief_updated_at` | ✅ OK |
| **Created At** | `recordCreationTime` | `created_at` | `created_at` | `created_at` | ✅ OK |
| **Updated At** | `recordUpdateTime` | `updated_at` | `updated_at` | `updated_at` | ✅ OK |

---

## 16. Migration Summary

### By Action Type

| Action | Count | Description |
|--------|-------|-------------|
| ✅ OK (No change) | 22 | Already compliant or acceptable |
| 🔄 Rename | 42 | Existing columns need renaming |
| ➕ Add | 45 | New columns to add |

---

## 17. vATCSCC Extension XSD Namespace

```xml
<?xml version="1.0" encoding="UTF-8"?>
<xs:schema xmlns:xs="http://www.w3.org/2001/XMLSchema"
           xmlns:vATCSCC="http://vatcscc.org/schema/1.0"
           xmlns:fx="http://www.fixm.aero/flight/4.3"
           targetNamespace="http://vatcscc.org/schema/1.0"
           elementFormDefault="qualified">

  <!-- VATSIM Pilot Identification -->
  <xs:complexType name="VatsimIdentificationType">
    <xs:sequence>
      <xs:element name="pilotCid" type="xs:positiveInteger"/>
      <xs:element name="pilotName" type="xs:string" minOccurs="0"/>
      <xs:element name="pilotRating" type="vATCSCC:PilotRatingType" minOccurs="0"/>
      <xs:element name="homeArtcc" type="xs:string" minOccurs="0"/>
    </xs:sequence>
  </xs:complexType>

  <!-- Airport Zone Detection -->
  <xs:complexType name="AirportZoneType">
    <xs:sequence>
      <xs:element name="currentAirportZone" type="vATCSCC:ZoneNameType" minOccurs="0"/>
      <xs:element name="currentZoneAirport" type="xs:string" minOccurs="0"/>
    </xs:sequence>
  </xs:complexType>

  <!-- Zone Times -->
  <xs:complexType name="ZoneTimesType">
    <xs:sequence>
      <xs:element name="parkingLeftTime" type="xs:dateTime" minOccurs="0"/>
      <xs:element name="taxiwayEnteredTime" type="xs:dateTime" minOccurs="0"/>
      <xs:element name="holdEnteredTime" type="xs:dateTime" minOccurs="0"/>
      <xs:element name="runwayEnteredTime" type="xs:dateTime" minOccurs="0"/>
      <xs:element name="rotationTime" type="xs:dateTime" minOccurs="0"/>
      <xs:element name="approachStartTime" type="xs:dateTime" minOccurs="0"/>
      <xs:element name="thresholdTime" type="xs:dateTime" minOccurs="0"/>
      <xs:element name="touchdownTime" type="xs:dateTime" minOccurs="0"/>
      <xs:element name="rolloutEndTime" type="xs:dateTime" minOccurs="0"/>
      <xs:element name="parkingEnteredTime" type="xs:dateTime" minOccurs="0"/>
    </xs:sequence>
  </xs:complexType>

  <!-- SimBrief integration -->
  <xs:complexType name="SimbriefDataType">
    <xs:sequence>
      <xs:element name="ofpId" type="xs:string" minOccurs="0"/>
      <xs:element name="route" type="xs:string" minOccurs="0"/>
      <xs:element name="costIndex" type="xs:integer" minOccurs="0"/>
      <xs:element name="blockFuel" type="xs:decimal" minOccurs="0"/>
      <xs:element name="zeroFuelWeight" type="xs:decimal" minOccurs="0"/>
      <xs:element name="takeoffWeight" type="xs:decimal" minOccurs="0"/>
    </xs:sequence>
  </xs:complexType>

  <!-- Enumerations -->
  <xs:simpleType name="PilotRatingType">
    <xs:restriction base="xs:string">
      <xs:enumeration value="NEW"/>
      <xs:enumeration value="PPL"/>
      <xs:enumeration value="IR"/>
      <xs:enumeration value="CMEL"/>
      <xs:enumeration value="ATPL"/>
    </xs:restriction>
  </xs:simpleType>

  <xs:simpleType name="ZoneNameType">
    <xs:restriction base="xs:string">
      <xs:enumeration value="PARKING"/>
      <xs:enumeration value="TAXIWAY"/>
      <xs:enumeration value="HOLD"/>
      <xs:enumeration value="RUNWAY"/>
      <xs:enumeration value="AIRBORNE"/>
      <xs:enumeration value="APPROACH"/>
      <xs:enumeration value="FINAL"/>
    </xs:restriction>
  </xs:simpleType>

</xs:schema>
```

---

## 18. API Response Example (Post-Migration)

```json
{
  "gufi": "VAT-20260116-UAL123-KJFK-KLAX",
  "flight_key": "UAL123-KJFK-KLAX-20260116",
  "aircraft_identification": "UAL123",
  "pilot_cid": 1234567,
  "operator_icao": "UAL",
  "operator_name": "United Airlines",
  
  "aircraft_type": "B738",
  "other_aircraft_type": "B738",
  "wake_turbulence": "MEDIUM",
  "weight_class": "L",
  
  "departure_aerodrome": "KJFK",
  "arrival_aerodrome": "KLAX",
  "departure_airspace": "ZNY",
  "arrival_airspace": "ZLA",
  
  "route_text": "DEEZZ5 DEEZZ J60 PSB J584 BJARR ANJLL4",
  "cruising_level": 35000,
  "cruising_speed": 460,
  "total_flight_distance": 2475.3,
  
  "latitude": 39.8561,
  "longitude": -104.6737,
  "altitude": 35000,
  "ground_speed": 487,
  "track": 268,
  "percent_complete": 45.2,
  "current_airport_zone": null,
  
  "actual_off_block_time": "2026-01-16T14:30:00Z",
  "actual_time_of_departure": "2026-01-16T14:45:00Z",
  "estimated_time_of_arrival": "2026-01-16T19:15:00Z",
  
  "flight_status": "CRUISING",
  "is_active": true,
  "last_source": "VATSIM",
  
  "position_time": "2026-01-16T16:45:00Z",
  "updated_at": "2026-01-16T16:45:15Z"
}
```

---

*End of Field Mapping Document v1.1*
