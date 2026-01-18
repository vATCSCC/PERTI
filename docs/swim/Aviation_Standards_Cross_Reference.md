# Aviation Data Exchange Standards - Consolidated Field Reference

**Version:** 1.0  
**Date:** 2026-01-16  
**Purpose:** Cross-reference of equivalent field names across aviation data exchange standards

---

## 1. Flight Identification

| Concept | FIXM 4.3 | AIDX 22.1 | TFMS/FAA | A-CDM | ARINC 633 | VATSWIM |
|---------|----------|-----------|----------|-------|-----------|-------------|
| Unique flight ID | `gufi` | — | `ACID` + `DEP` + `DEST` + `ETD` | — | — | `gufi` |
| Callsign | `flightIdentification/aircraftIdentification` | `FlightId/FlightNumber` | `ACID` | `arcid` | `FlightNumber` | `callsign` |
| Airline code | `flightIdentification/majorCarrierIdentifier` | `FlightId/AirlineIATA` | `AIRLINE` | `aobt` | `AirlineCode` | `airline_icao` |
| Flight number | `flightIdentification/flightNumber` | `FlightId/FlightNumber` | — | — | `FlightNumber` | — |
| CID/Pilot ID | — | — | — | — | — | `cid` (VATSIM-specific) |

---

## 2. Aircraft Information

| Concept | FIXM 4.3 | AIDX 22.1 | TFMS/FAA | A-CDM | ICAO FPL | VATSWIM |
|---------|----------|-----------|----------|-------|----------|-------------|
| Aircraft type | `aircraft/aircraftType/icaoAircraftTypeDesignator` | `AircraftType/AircraftIATACode` | `ACFT_TYPE` | `arctyp` | Field 9 | `aircraft_type` |
| Registration | `aircraft/registration` | `AircraftType/AircraftRegistration` | `REG` | `reg` | Field 7 | `registration` |
| Wake category | `aircraft/wakeTurbulence` | — | `WAKE` | `wtc` | Field 9 | `wake_category` |
| Weight class | — | — | `WEIGHT_CLASS` | — | — | `weight_class` |
| Equipment | `aircraft/capabilities` | — | `EQUIP` | — | Field 10 | `equipment` |

### Wake Turbulence Categories

| Category | FIXM | ICAO | FAA | Description |
|----------|------|------|-----|-------------|
| Light | `LIGHT` | `L` | `SMALL` | <7,000 kg / 15,500 lbs |
| Medium | `MEDIUM` | `M` | `LARGE` | 7,000-136,000 kg |
| Heavy | `HEAVY` | `H` | `HEAVY` | >136,000 kg / 300,000 lbs |
| Super | `SUPER` | `J` | `SUPER` | A380, AN-225 |

---

## 3. Departure Information

| Concept | FIXM 4.3 | AIDX 22.1 | TFMS/FAA | A-CDM | ACARS/633 | VATSWIM |
|---------|----------|-----------|----------|-------|-----------|-------------|
| Departure airport | `departure/aerodrome/locationIndicator` | `DepartureAirport` | `DEP` | `adep` | `DepAirport` | `dept_icao` |
| Departure gate | `departure/departurePoint/stand` | `DepartureGate` | — | `dgate` | `Gate` | `dept_gate` |
| Departure runway | `departure/runwayDirection/designator` | `DepartureRunway` | `DEP_RWY` | `drwy` | — | `dept_runway` |
| Departure fix | `departure/departurePoint/fix` | — | `DEP_FIX` | — | — | `dept_fix` |
| SID | `routeTrajectoryGroup/routeInformation/sidStarReference` | — | `SID` | — | — | `sid` |

---

## 4. Arrival Information

| Concept | FIXM 4.3 | AIDX 22.1 | TFMS/FAA | A-CDM | ACARS/633 | VATSWIM |
|---------|----------|-----------|----------|-------|-----------|-------------|
| Destination airport | `arrival/destinationAerodrome/locationIndicator` | `ArrivalAirport` | `DEST` | `ades` | `ArrAirport` | `dest_icao` |
| Arrival gate | `arrival/arrivalPoint/stand` | `ArrivalGate` | — | `agate` | `Gate` | `dest_gate` |
| Arrival runway | `arrival/runwayDirection/designator` | `ArrivalRunway` | `ARR_RWY` | `arwy` | — | `dest_runway` |
| Arrival fix | `arrival/destinationPoint/fix` | — | `ARR_FIX` | — | — | `arr_fix` |
| STAR | `routeTrajectoryGroup/routeInformation/sidStarReference` | — | `STAR` | — | — | `star` |
| Alternate | `arrival/destinationAerodromeAlternate` | `AlternateAirport` | `ALT` | — | `AltAirport` | `alternate` |

---

## 5. Route & Trajectory

| Concept | FIXM 4.3 | AIDX 22.1 | TFMS/FAA | ICAO FPL | VATSWIM |
|---------|----------|-----------|----------|----------|-------------|
| Route string | `routeTrajectoryGroup/element/routeDesignator` | — | `ROUTE` | Field 15 | `route` |
| Filed altitude | `routeTrajectoryGroup/cruisingLevel` | — | `REQ_ALT` | Field 15 | `cruise_altitude` |
| Filed speed | `routeTrajectoryGroup/cruisingSpeed` | — | `SPEED` | Field 15 | `cruise_speed` |
| Route distance | `routeTrajectoryGroup/routeDistance` | — | — | — | `route_distance_nm` |
| 4D trajectory | `routeTrajectoryGroup/element/point4D` | — | `TRAJECTORY` | — | — |

---

## 6. Time Fields - COMPREHENSIVE

### 6.1 Departure Times

| Time Type | FIXM 4.3 | AIDX 22.1 | TFMS/FAA | A-CDM | ACARS | VATSWIM |
|-----------|----------|-----------|----------|-------|-------|-------------|
| **Scheduled departure** | `departure/estimatedTime` (with qualifier) | `ScheduledDepartureDateTime` | `SDTE` | `sibt` | — | `scheduled_dept_utc` |
| **Estimated off-block** | `departure/estimatedOffBlockTime` | `EstimatedOffBlockDateTime` | `EOBT` | `eobt` | — | `etd_utc` |
| **Target off-block** | `departure/targetOffBlockTime` | `TargetOffBlockDateTime` | `TOBT` | `tobt` | — | `tobt_utc` |
| **Target startup approval** | `departure/targetStartupApprovalTime` | — | `TSAT` | `tsat` | — | `tsat_utc` |
| **Target takeoff** | `departure/targetTakeOffTime` | `TargetDepartureDateTime` | `TTOT` | `ttot` | — | `ttot_utc` |
| **Estimated runway** | `departure/estimatedRunwayTime` | — | `P-TIME` / `ERDT` | `etot` | — | `etd_runway_utc` |
| **Actual off-block** | `departure/actualOffBlockTime` | `ActualOffBlockDateTime` | `AOBT` | `aobt` | `OUT` | `out_utc` |
| **Actual takeoff** | `departure/actualTimeOfDeparture` | `ActualDepartureDateTime` | `ATD` / `ATOT` | `atot` | `OFF` | `off_utc` |
| **Controlled departure** | `departure/controlledOffBlockTime` | — | `CTD` / `EDCT` | `ctot` | — | `ctd_utc` / `edct_utc` |

### 6.2 Arrival Times

| Time Type | FIXM 4.3 | AIDX 22.1 | TFMS/FAA | A-CDM | ACARS | VATSWIM |
|-----------|----------|-----------|----------|-------|-------|-------------|
| **Scheduled arrival** | `arrival/estimatedTime` (with qualifier) | `ScheduledArrivalDateTime` | `SATE` | `sibt` | — | `scheduled_arr_utc` |
| **Estimated landing** | `arrival/estimatedArrivalTime` | `EstimatedArrivalDateTime` | `ETA` | `eldt` | — | `eta_utc` |
| **Estimated runway** | `arrival/estimatedRunwayTime` | — | `ERTA` | `eldt` | — | `eta_runway_utc` |
| **Estimated in-block** | `arrival/estimatedInBlockTime` | `EstimatedInBlockDateTime` | — | `eibt` | — | `eta_gate_utc` |
| **Target landing** | `arrival/targetLandingTime` | `TargetArrivalDateTime` | `TLDT` | `tldt` | — | `tldt_utc` |
| **Target in-block** | `arrival/targetInBlockTime` | `TargetInBlockDateTime` | — | `tibt` | — | `tibt_utc` |
| **Actual landing** | `arrival/actualLandingTime` | `ActualArrivalDateTime` | `ATA` / `ALDT` | `aldt` | `ON` | `on_utc` |
| **Actual in-block** | `arrival/actualInBlockTime` | `ActualInBlockDateTime` | `AIBT` | `aibt` | `IN` | `in_utc` |
| **Controlled arrival** | `arrival/controlledArrivalTime` | — | `CTA` | `cta` | — | `cta_utc` |

### 6.3 OOOI Times (Out-Off-On-In)

| Event | FIXM 4.3 | AIDX 22.1 | TFMS | A-CDM | ACARS | VATSWIM |
|-------|----------|-----------|------|-------|-------|-------------|
| **OUT** (pushback) | `departure/actualOffBlockTime` | `ActualOffBlockDateTime` | `AOBT` | `aobt` | `OUT` | `out_utc` |
| **OFF** (wheels up) | `departure/actualTimeOfDeparture` | `ActualDepartureDateTime` | `ATOT` | `atot` | `OFF` | `off_utc` |
| **ON** (wheels down) | `arrival/actualLandingTime` | `ActualArrivalDateTime` | `ALDT` | `aldt` | `ON` | `on_utc` |
| **IN** (at gate) | `arrival/actualInBlockTime` | `ActualInBlockDateTime` | `AIBT` | `aibt` | `IN` | `in_utc` |

### 6.4 Time Prefixes (TFMS Convention)

| Prefix | Meaning | Example | VATSIM Mapping |
|--------|---------|---------|----------------|
| `S` | Scheduled | SDTE, SATE | `scheduled_*` |
| `E` | Estimated | EOBT, ETA | `eta_*`, `etd_*` |
| `T` | Target | TOBT, TSAT, TTOT, TLDT | `tobt_*`, `tsat_*` |
| `A` | Actual | AOBT, ATOT, ALDT, AIBT | `out_*`, `off_*`, `on_*`, `in_*` |
| `C` | Controlled | CTD, CTA, CTOT | `ctd_*`, `cta_*` |
| `P` | Proposed | P-TIME | `proposed_*` |

---

## 7. Flight Status & Phase

### 7.1 Flight Status

| Status | FIXM FlightStatusType | AIDX FlightLegStatus | TFMS | VATSWIM |
|--------|----------------------|---------------------|------|-------------|
| Unknown | `UNKNOWN` | — | — | `UNKNOWN` |
| Scheduled | `SCHEDULED` | `Scheduled` | `SKED` | `SCHEDULED` |
| Filed | `FILED` | — | `FILED` | `FILED` |
| Active/Ready | `ACTIVE` | `Active` | `ACTV` | `ACTIVE` |
| Taxiing | — | `Boarding` | — | `TAXIING` |
| Departed | `ASCENDING` | `Departed` | `DEP` | `DEPARTED` |
| Airborne/Enroute | `CRUISING` | `InFlight` | `ENRT` | `ENROUTE` |
| Descending | `DESCENDING` | — | `DSC` | `DESCENT` |
| Arrived | — | `Arrived` | `ARR` | `ARRIVED` |
| Completed | `COMPLETED` | `Completed` | `COMP` | `COMPLETED` |
| Cancelled | `CANCELLED` | `Cancelled` | `CNX` | `CANCELLED` |
| Diverted | — | `Diverted` | `DIV` | `DIVERTED` |
| Controlled | `CONTROLLED` | — | `CTRL` | `CONTROLLED` |
| Decontrolled | `DECONTROLLED` | — | `DCTL` | `DECONTROLLED` |

### 7.2 Flight Phase (Detailed)

| Phase | FIXM | TFMS/FSM | EUROCONTROL | VATSWIM |
|-------|------|----------|-------------|-------------|
| Pre-departure | `FILED` | `PROPOSED` | `PLANNED` | `PROPOSED` |
| At gate (ready) | `ACTIVE` | `ACTIVE` | `TAXI` | `DEPARTING` |
| Taxi out | — | `TAXI` | `TAXI` | `TAXI_OUT` |
| Takeoff roll | — | — | `DEPARTURE` | `TAKEOFF` |
| Initial climb | `ASCENDING` | `CLIMBING` | `CLIMB` | `CLIMBING` |
| Enroute | `CRUISING` | `ENROUTE` | `CRUISE` | `ENROUTE` |
| Top of descent | `DESCENDING` | `DESCENDING` | `DESCENT` | `DESCENT` |
| Approach | — | `APPROACH` | `APPROACH` | `APPROACH` |
| Landing | — | — | `LANDING` | `LANDING` |
| Taxi in | — | `TAXI` | `TAXI` | `TAXI_IN` |
| At gate (arrived) | `COMPLETED` | `ARRIVED` | `PARKED` | `ARRIVED` |

---

## 8. Position & Track Data

| Concept | FIXM 4.3 | ASTERIX CAT062 | ADS-B | GeoJSON | VATSWIM |
|---------|----------|----------------|-------|---------|-------------|
| Latitude | `position/position/latitude` | I062/105 | Lat | `coordinates[1]` | `lat` |
| Longitude | `position/position/longitude` | I062/105 | Lon | `coordinates[0]` | `lon` |
| Altitude (pressure) | `position/altitude/altitude` | I062/136 | FL | `coordinates[2]` | `altitude_ft` |
| Altitude (geometric) | `position/altitude/geometricAltitude` | I062/130 | GNSS Alt | — | `geo_altitude_ft` |
| Heading | `position/track/heading` | I062/180 | Heading | `properties.heading` | `heading` |
| Ground speed | `position/track/groundSpeed` | I062/185 | GS | `properties.groundspeed` | `groundspeed_kts` |
| Vertical rate | `position/track/verticalRate` | I062/220 | VRate | `properties.vertical_rate` | `vertical_rate_fpm` |
| Track angle | `position/track/trackAngle` | I062/180 | Track | — | `track` |

---

## 9. TMI (Traffic Management Initiative) Data

### 9.1 TMI Types

| TMI Type | FIXM US Extension | TFMS | EUROCONTROL | VATSWIM |
|----------|-------------------|------|-------------|-------------|
| Ground Stop | `nas:groundStop` | `GS` | `GS` | `GS` |
| Ground Delay Program | `nas:groundDelayProgram` | `GDP` | `GDP` | `GDP` |
| Airspace Flow Program | `nas:airspaceFlowProgram` | `AFP` | `AFP` | `AFP` |
| Miles-in-Trail | `nas:milesInTrail` | `MIT` | `MIT` | `MIT` |
| Minutes-in-Trail | `nas:minutesInTrail` | `MINIT` | `MINIT` | `MINIT` |
| CTOP | `nas:ctop` | `CTOP` | — | `CTOP` |
| Reroute | `nas:reroute` | `RR` | `RR` | `REROUTE` |

### 9.2 TMI Fields

| Concept | FIXM US Extension | TFMS | VATSWIM |
|---------|-------------------|------|-------------|
| Controlled element | `nas:controlElement` | `FCA` / `FEA` | `control_element` |
| Control program name | `nas:controlProgram` | `PROGRAM_NAME` | `control_program` |
| EDCT | `nas:edct/time` | `EDCT` | `edct_utc` |
| Slot time | `nas:slot/time` | `SLOT_TIME` | `slot_time_utc` |
| Slot ID | `nas:slot/slotId` | `SLOT_ID` | `slot_id` |
| Delay minutes | `nas:delay/delayValue` | `DELAY` | `delay_minutes` |
| Delay status | `nas:delay/delayStatus` | `DLY_STATUS` | `delay_status` |
| Exempt flag | `nas:exemptIndicator` | `EXEMPT` | `is_exempt` |
| Exempt reason | `nas:exemptReason` | `EXEMPT_REASON` | `exempt_reason` |

### 9.3 Delay Status Codes (TFMS)

| Code | Meaning | VATSWIM |
|------|---------|-------------|
| `GDP` | Ground Delay Program delay | `GDP` |
| `AFP` | Airspace Flow Program delay | `AFP` |
| `GSD` | Ground Stop Delay | `GSD` |
| `ALD` | Airline Delay | `ALD` |
| `DAS` | Delay Assignment | `DAS` |
| `TOD` | Time Out Delayed | `TOD` |
| `CTOP` | CTOP delay | `CTOP` |
| `APREQ` | Approval Request delay | `APREQ` |

### 9.4 Cancellation Status Codes (TFMS CNX)

| Code | Meaning | VATSWIM |
|------|---------|-------------|
| `UX` | Update cancelled | `UX` |
| `FX` | Airline/flight cancelled | `FX` |
| `RZ` | NAS cancelled | `RZ` |
| `RS` | Schedule (OAG) cancelled | `RS` |
| `TO` | Time Out cancelled | `TO` |
| `DV` | Diverted | `DV` |
| `RM` | Removed from system | `RM` |
| `ID` | ID/Callsign changed | `ID` |

---

## 10. A-CDM Milestones (Airport Collaborative Decision Making)

| Milestone | Full Name | A-CDM Code | AIDX | FIXM | VATSWIM |
|-----------|-----------|------------|------|------|-------------|
| SOBT | Scheduled Off-Block Time | `sobt` | `ScheduledDepartureDateTime` | — | `scheduled_dept_utc` |
| EOBT | Estimated Off-Block Time | `eobt` | `EstimatedOffBlockDateTime` | `estimatedOffBlockTime` | `etd_utc` |
| TOBT | Target Off-Block Time | `tobt` | `TargetOffBlockDateTime` | `targetOffBlockTime` | `tobt_utc` |
| TSAT | Target Startup Approval Time | `tsat` | — | `targetStartupApprovalTime` | `tsat_utc` |
| ASAT | Actual Startup Approval Time | `asat` | — | — | `asat_utc` |
| AOBT | Actual Off-Block Time | `aobt` | `ActualOffBlockDateTime` | `actualOffBlockTime` | `out_utc` |
| EXOT | Estimated Taxi-Out Time | `exot` | — | — | `taxi_out_min` |
| AXOT | Actual Taxi-Out Time | `axot` | — | — | `actual_taxi_out_min` |
| TTOT | Target Takeoff Time | `ttot` | `TargetDepartureDateTime` | `targetTakeOffTime` | `ttot_utc` |
| ETOT | Estimated Takeoff Time | `etot` | — | — | `etd_runway_utc` |
| ATOT | Actual Takeoff Time | `atot` | `ActualDepartureDateTime` | `actualTimeOfDeparture` | `off_utc` |
| ELDT | Estimated Landing Time | `eldt` | `EstimatedArrivalDateTime` | `estimatedArrivalTime` | `eta_runway_utc` |
| TLDT | Target Landing Time | `tldt` | `TargetArrivalDateTime` | `targetLandingTime` | `tldt_utc` |
| ALDT | Actual Landing Time | `aldt` | `ActualArrivalDateTime` | `actualLandingTime` | `on_utc` |
| EIBT | Estimated In-Block Time | `eibt` | `EstimatedInBlockDateTime` | `estimatedInBlockTime` | `eta_gate_utc` |
| TIBT | Target In-Block Time | `tibt` | `TargetInBlockDateTime` | `targetInBlockTime` | `tibt_utc` |
| AIBT | Actual In-Block Time | `aibt` | `ActualInBlockDateTime` | `actualInBlockTime` | `in_utc` |
| SIBT | Scheduled In-Block Time | `sibt` | `ScheduledArrivalDateTime` | — | `scheduled_arr_utc` |

---

## 11. Airspace & Facility References

| Concept | FIXM 4.3 | AIXM 5.1 | TFMS | VATSWIM |
|---------|----------|----------|------|-------------|
| ARTCC/FIR | `airspace/designator` | `AirspaceDesignator` | `CTR` | `artcc` |
| Sector | `airspace/sectorIdentifier` | `SectorDesignator` | `SECTOR` | `sector` |
| TRACON | — | `AirspaceDesignator` | `TRACON` | `tracon` |
| Fix/Waypoint | `routePoint/fix` | `DesignatedPoint` | `FIX` | `fix` |
| Airway | `routeSegment/airway` | `Route` | `AIRWAY` | `airway` |
| FCA (Flow Control Area) | `nas:flowControlArea` | — | `FCA` | `fca` |
| FEA (Flow Evaluation Area) | `nas:flowEvaluationArea` | — | `FEA` | `fea` |

---

## 12. Weather Integration (IWXXM/WXXM)

| Concept | IWXXM 3.0 | METAR/TAF | GFS/GRIB | VATSWIM |
|---------|-----------|-----------|----------|-------------|
| Wind direction | `iwxxm:windDirection` | `ddd` | `UGRD`/`VGRD` | `wind_dir_deg` |
| Wind speed | `iwxxm:windSpeed` | `ff` | `WIND` | `wind_speed_kts` |
| Temperature | `iwxxm:airTemperature` | `TT` | `TMP` | `temperature_c` |
| Visibility | `iwxxm:prevailingVisibility` | `VVVV` | — | `visibility_sm` |
| Ceiling | `iwxxm:cloudLayer/base` | `ccc` | — | `ceiling_ft` |
| Altimeter | `iwxxm:qnh` | `AAAA` | `PRMSL` | `altimeter_inhg` |

---

## 13. Response Envelope Standards

### JSON Response (REST API)

```json
{
  "success": true,
  "data": { },
  "timestamp": "2026-01-16T12:00:00Z",
  "meta": {
    "source": "vatcscc",
    "schema_version": "1.0.0"
  }
}
```

### FIXM XML Envelope

```xml
<fx:Flight xmlns:fx="http://www.fixm.aero/flight/4.3"
           xmlns:fb="http://www.fixm.aero/base/4.3"
           xmlns:nas="http://www.faa.aero/nas/4.3">
  <fx:gufi>VAT-20260116-UAL123-KJFK-KLAX</fx:gufi>
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

## 14. Quick Reference: VATSWIM ↔ Standards Mapping

| VATSWIM Field | FIXM | AIDX | TFMS | A-CDM |
|-------------------|------|------|------|-------|
| `gufi` | `gufi` | — | composite | — |
| `callsign` | `aircraftIdentification` | `FlightNumber` | `ACID` | `arcid` |
| `dept_icao` | `departure/aerodrome` | `DepartureAirport` | `DEP` | `adep` |
| `dest_icao` | `arrival/destinationAerodrome` | `ArrivalAirport` | `DEST` | `ades` |
| `aircraft_type` | `aircraft/aircraftType` | `AircraftType` | `ACFT_TYPE` | `arctyp` |
| `out_utc` | `actualOffBlockTime` | `ActualOffBlockDateTime` | `AOBT` | `aobt` |
| `off_utc` | `actualTimeOfDeparture` | `ActualDepartureDateTime` | `ATOT` | `atot` |
| `on_utc` | `actualLandingTime` | `ActualArrivalDateTime` | `ALDT` | `aldt` |
| `in_utc` | `actualInBlockTime` | `ActualInBlockDateTime` | `AIBT` | `aibt` |
| `eta_utc` | `estimatedArrivalTime` | `EstimatedArrivalDateTime` | `ETA` | `eldt` |
| `etd_utc` | `estimatedOffBlockTime` | `EstimatedOffBlockDateTime` | `EOBT` | `eobt` |
| `ctd_utc` | `targetStartupApprovalTime` | — | `EDCT` | `ctot` |
| `phase` | `FlightStatusType` | `FlightLegStatus` | status | — |
| `gs_held` | `groundStop` | — | `GS` | — |
| `delay_minutes` | `delay/delayValue` | — | `DELAY` | — |
| `slot_time_utc` | `slot/time` | — | `SLOT_TIME` | — |

---

## 15. Namespace Reference

| Standard | Namespace URI | Prefix |
|----------|---------------|--------|
| FIXM Core 4.3 | `http://www.fixm.aero/flight/4.3` | `fx` |
| FIXM Base 4.3 | `http://www.fixm.aero/base/4.3` | `fb` |
| FIXM US Extension | `http://www.faa.aero/nas/4.3` | `nas` |
| AIXM 5.1 | `http://www.aixm.aero/schema/5.1` | `aixm` |
| IWXXM 3.0 | `http://icao.int/iwxxm/3.0` | `iwxxm` |
| AIDX 22.1 | `http://www.iata.org/IATA/2007/00` | `aidx` |
| GML 3.2 | `http://www.opengis.net/gml/3.2` | `gml` |
| GeoJSON | — (JSON format) | — |
| vATCSCC Extension | `http://vatcscc.org/schema/1.0` | `vatc` |

---

*Document generated for VATSWIM API standards alignment*
