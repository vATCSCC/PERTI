# Aviation Data Exchange Standards Cross-Reference

**Purpose:** Consolidated reference mapping equivalent concepts across FIXM, AIDX, TFMS, A-CDM, ASTERIX, and other aviation data exchange standards.

**Version:** 1.0  
**Date:** 2026-01-16

---

## 1. Flight Identification

| Concept | FIXM 4.3 | AIDX 22.1 | TFMS/FADT | A-CDM | ASTERIX | ARINC 633 | VATSWIM |
|---------|----------|-----------|-----------|-------|---------|-----------|-------------|
| **Unique Flight ID** | `gufi` | `FlightId` | `ACID` + `DEPT` + `DEST` + `ETD` | `FlightId` | I062/390 | — | `gufi` |
| **Callsign** | `aircraftIdentification` | `AirlineFlightId` | `ACID` | `AircraftId` | I062/060 (Mode 3/A) | `FlightNumber` | `callsign` |
| **Flight Number** | `flightNumber` | `FlightNumber` | — | `FlightNumber` | — | `FlightNumber` | — |
| **Airline Code (ICAO)** | `operatorIcaoDesignator` | `AirlineIATA` / `AirlineICAO` | `AIRLINE` | `AirlineCode` | — | `Airline` | `airline_icao` |
| **Airline Code (IATA)** | — | `AirlineIATA` | — | `AirlineCode` | — | `Airline` | — |
| **Pilot/Operator ID** | — | — | — | — | — | — | `cid` (VATSIM-specific) |
| **Registration** | `registration` | `AircraftRegistration` | `REG` | `Registration` | I062/390 | `Registration` | — |
| **Mode S Address** | `aircraftAddress` | — | `BEACON` | — | I062/080 | — | — |
| **SSR Code (Squawk)** | `assignedCode` | — | `BCN` | — | I062/060 | — | `transponder` |

---

## 2. Aircraft Information

| Concept | FIXM 4.3 | AIDX 22.1 | TFMS/FADT | A-CDM | ASTERIX | ARINC 633 | VATSWIM |
|---------|----------|-----------|-----------|-------|---------|-----------|-------------|
| **Aircraft Type (ICAO)** | `aircraftType` | `AircraftType` | `APTS` / `TYPE` | `AircraftType` | I062/390 | `AircraftType` | `aircraft_type` |
| **Aircraft Type (FAA)** | `otherAircraftType` | — | `APTS` | — | — | — | `aircraft_faa` |
| **Wake Category (ICAO)** | `wakeTurbulence` | `WakeCategory` | `WGTCLASS` | `WTC` | I062/390 | — | `wake_category` |
| **Wake Category (FAA)** | — | — | `WGTCLASS` | — | — | — | `weight_class` |
| **Equipment Suffix** | `equipmentQualifier` | — | `EQUIP` | — | — | — | — |
| **SUR Capability** | `surveillanceCapabilities` | — | `SURV` | — | — | — | — |
| **Number of Aircraft** | `formationCount` | — | — | — | — | — | — |

### Wake Turbulence Category Codes

| Category | ICAO (FIXM) | FAA (TFMS) | Description |
|----------|-------------|------------|-------------|
| Light | `L` | `L` | < 15,500 lbs |
| Medium | `M` | `L` | 15,500 - 300,000 lbs |
| Heavy | `H` | `H` | > 300,000 lbs |
| Super | `J` | `J` | A380, AN-225 |

---

## 3. Airports & Locations

| Concept | FIXM 4.3 | AIDX 22.1 | TFMS/FADT | A-CDM | ASTERIX | ARINC 633 | VATSWIM |
|---------|----------|-----------|-----------|-------|---------|-----------|-------------|
| **Departure Airport** | `departureAerodrome` | `DepartureAirport` | `DEPT` | `ADEP` | I062/390 | `DepartureAirport` | `dept_icao` |
| **Arrival Airport** | `arrivalAerodrome` | `ArrivalAirport` | `DEST` | `ADES` | I062/390 | `ArrivalAirport` | `dest_icao` |
| **Alternate Airport** | `alternateAerodrome` | `AlternateAirport` | `ALT1` | `ALTN` | — | `AlternateAirport` | `alternate_icao` |
| **Diversion Airport** | `destinationAerodromeAlternate` | `DiversionAirport` | `DIV` | — | — | — | — |
| **Departure Gate** | `departureGate` | `DepartureGate` | — | `Gate` | — | `Gate` | — |
| **Arrival Gate** | `arrivalGate` | `ArrivalGate` | — | `Gate` | — | `Gate` | — |
| **Departure Runway** | `departureRunway` | `DepartureRunway` | `DRWY` | `RWY` | — | `Runway` | `departure_runway` |
| **Arrival Runway** | `arrivalRunway` | `ArrivalRunway` | `ARWY` | `RWY` | — | `Runway` | `arrival_runway` |
| **Departure Stand** | `departureStand` | `DepartureStand` | — | `Stand` | — | — | — |
| **Arrival Stand** | `arrivalStand` | `ArrivalStand` | — | `Stand` | — | — | — |

---

## 4. Route & Trajectory

| Concept | FIXM 4.3 | AIDX 22.1 | TFMS/FADT | A-CDM | ASTERIX | ARINC 633 | VATSWIM |
|---------|----------|-----------|-----------|-------|---------|-----------|-------------|
| **Filed Route** | `routeText` | — | `ROUTE` | — | — | `Route` | `route` |
| **Current Route** | `currentRouteText` | — | `CURR_RTE` | — | — | — | `current_route` |
| **Departure Procedure (SID)** | `standardInstrumentDeparture` | — | `SID` | `SID` | — | `SID` | `departure_procedure` |
| **Arrival Procedure (STAR)** | `standardInstrumentArrival` | — | `STAR` | `STAR` | — | `STAR` | `arrival_procedure` |
| **Approach** | `approachProcedure` | — | `IAP` | `IAP` | — | — | — |
| **Departure Fix** | `departurePoint` | — | `DFIX` | — | — | — | `departure_fix` |
| **Arrival Fix** | `arrivalPoint` | — | `APTS_ARR_FIX` | — | — | — | `arrival_fix` |
| **Cruise Altitude** | `cruisingLevel` | `CruiseAltitude` | `ALT` | — | — | `CruiseAltitude` | `cruise_altitude` |
| **Cruise Speed** | `cruisingSpeed` | `CruiseSpeed` | `SPD` | — | — | `CruiseSpeed` | `cruise_speed` |
| **Flight Rules** | `flightRules` | — | `FLT_RULES` | — | — | — | `flight_rules` |
| **Route Distance** | `totalFlightDistance` | — | `DIST` | — | — | — | `route_total_nm` |

---

## 5. Position & Track Data

| Concept | FIXM 4.3 | AIDX 22.1 | TFMS/FADT | A-CDM | ASTERIX CAT062 | GeoJSON | VATSWIM |
|---------|----------|-----------|-----------|-------|----------------|---------|-------------|
| **Latitude** | `position/latitude` | — | `LAT` | — | I062/105 | `coordinates[1]` | `lat` |
| **Longitude** | `position/longitude` | — | `LON` | — | I062/105 | `coordinates[0]` | `lon` |
| **Altitude (ft)** | `altitude` | — | `ALT` | — | I062/135 | `coordinates[2]` | `altitude_ft` |
| **Flight Level** | `flightLevel` | — | `FL` | — | I062/136 | — | — |
| **Heading (°)** | `track` | — | `HDG` | — | I062/180 | — | `heading` |
| **Ground Speed (kts)** | `groundSpeed` | — | `GS` | — | I062/185 | — | `groundspeed` |
| **True Airspeed (kts)** | `trueAirspeed` | — | `TAS` | — | — | — | `true_airspeed` |
| **Vertical Rate (fpm)** | `verticalRate` | — | `VR` | — | I062/220 | — | `vertical_rate` |
| **Track Angle** | `trackAngle` | — | — | — | I062/180 | — | — |
| **Position Time** | `positionTime` | — | `POSTIME` | — | I062/070 | — | `position_time` |

---

## 6. Flight Times - OOOI (Out-Off-On-In)

| Concept | FIXM 4.3 | AIDX 22.1 | TFMS/FADT | A-CDM | ARINC 633 | ACARS | VATSWIM |
|---------|----------|-----------|-----------|-------|-----------|-------|-------------|
| **OUT (Block Out/Pushback)** | `actualOffBlockTime` | `ActualOffBlockTime` (AOBT) | `OUT` | `AOBT` | `AOBT` | `OUT` | `out_utc` |
| **OFF (Wheels Up/Takeoff)** | `actualTimeOfDeparture` | `ActualDepartureTime` (ATOT) | `OFF` | `ATOT` | `ATOT` | `OFF` | `off_utc` |
| **ON (Wheels Down/Landing)** | `actualLandingTime` | `ActualLandingTime` (ALDT) | `ON` | `ALDT` | `ALDT` | `ON` | `on_utc` |
| **IN (Block In/Gate Arrival)** | `actualInBlockTime` | `ActualInBlockTime` (AIBT) | `IN` | `AIBT` | `AIBT` | `IN` | `in_utc` |

---

## 7. Flight Times - Scheduled/Estimated/Target

| Concept | FIXM 4.3 | AIDX 22.1 | TFMS/FADT | A-CDM | ARINC 633 | VATSWIM |
|---------|----------|-----------|-----------|-------|-----------|-------------|
| **Scheduled Departure (Block)** | `scheduledOffBlockTime` | `ScheduledOffBlockTime` (SOBT) | `SCHED_DEP` | `SOBT` | `SOBT` | — |
| **Scheduled Departure (Runway)** | `scheduledTimeOfDeparture` | `ScheduledDepartureTime` (STD) | — | — | `STD` | — |
| **Scheduled Arrival (Block)** | `scheduledInBlockTime` | `ScheduledInBlockTime` (SIBT) | `SCHED_ARR` | `SIBT` | `SIBT` | — |
| **Scheduled Arrival (Runway)** | `scheduledTimeOfArrival` | `ScheduledArrivalTime` (STA) | — | — | `STA` | — |
| **Estimated Departure (Block)** | `estimatedOffBlockTime` | `EstimatedOffBlockTime` (EOBT) | `EOBT` | `EOBT` | `EOBT` | `etd_utc` |
| **Estimated Departure (Runway)** | `estimatedTimeOfDeparture` | `EstimatedDepartureTime` (ETD) | `ETD` / `P-TIME` | `ETOT` | `ETD` | `etd_runway_utc` |
| **Estimated Arrival (Block)** | `estimatedInBlockTime` | `EstimatedInBlockTime` (EIBT) | — | `EIBT` | `EIBT` | — |
| **Estimated Arrival (Runway)** | `estimatedTimeOfArrival` | `EstimatedArrivalTime` (ETA) | `ETA` / `OETA` | `ELDT` | `ETA` | `eta_utc` |
| **Calculated Takeoff Time** | `calculatedTimeOfDeparture` | — | `CTOT` | `CTOT` | — | — |
| **Calculated Landing Time** | `calculatedTimeOfArrival` | — | — | — | — | — |

---

## 8. Flight Times - A-CDM Milestones

| Concept | FIXM 4.3 US Ext | AIDX 22.1 | TFMS | A-CDM | Description | VATSWIM |
|---------|-----------------|-----------|------|-------|-------------|-------------|
| **TOBT** | `targetOffBlockTime` | `TargetOffBlockTime` | — | `TOBT` | Target Off-Block Time (airline's estimate) | — |
| **TSAT** | `targetStartupApprovalTime` | `TargetStartupApprovalTime` | — | `TSAT` | Target Startup Approval Time | — |
| **TTOT** | `targetTakeoffTime` | `TargetTakeoffTime` | — | `TTOT` | Target Takeoff Time | — |
| **TLDT** | `targetLandingTime` | `TargetLandingTime` | — | `TLDT` | Target Landing Time | — |
| **ELDT** | `estimatedLandingTime` | `EstimatedLandingTime` | `OETA` | `ELDT` | Estimated Landing Time | `eta_runway_utc` |
| **ALDT** | `actualLandingTime` | `ActualLandingTime` | `ON` | `ALDT` | Actual Landing Time | `on_utc` |
| **AIBT** | `actualInBlockTime` | `ActualInBlockTime` | `IN` | `AIBT` | Actual In-Block Time | `in_utc` |
| **ASAT** | — | — | — | `ASAT` | Actual Startup Approval Time | — |
| **ASRT** | — | — | — | `ASRT` | Actual Startup Request Time | — |
| **AXIT** | — | — | — | `AXIT` | Actual Taxi Time | — |
| **AXOT** | — | — | — | `AXOT` | Actual Exiting Runway Time | — |

---

## 9. TMI / ATFM Control Data

| Concept | FIXM 4.3 US Ext | AIDX | TFMS/FADT | A-CDM | EUROCONTROL | VATSWIM |
|---------|-----------------|------|-----------|-------|-------------|-------------|
| **EDCT** | `expectedDepartureClearanceTime` | — | `EDCT` | `CTOT` | `CTOT` | `edct_utc` |
| **Controlled Time Departure** | `controlledTimeOfDeparture` | — | `CTD` | `CTOT` | `CTOT` | `ctd_utc` |
| **Controlled Time Arrival** | `controlledTimeOfArrival` | — | `CTA` | — | `CTA` | `cta_utc` |
| **Slot Time** | `slotTime` | — | `SLOT_TIME` | — | `CTOT` | `slot_time_utc` |
| **TMI Control Element** | `controlElement` | — | `CTL_ELEM` | — | `ATFM_DELAY_REF` | `ctl_element` |
| **TMI Control Type** | `controlType` | — | `CTL_TYPE` | — | — | `ctl_type` |
| **TMI Program Name** | `programName` | — | `CTL_PRGM` | — | `REG_ID` | `ctl_prgm` |
| **Delay Minutes** | `delayValue` | — | `DLA_ASGN` | — | `ATFM_DELAY` | `delay_minutes` |
| **Ground Stop Status** | `groundStopStatus` | — | `GS_STATUS` | — | — | `gs_held` |
| **Exempt Status** | `exemptIndicator` | — | `EXEMPT` | — | — | `is_exempt` |
| **Exempt Reason** | `exemptReason` | — | `EXEMPT_RSN` | — | — | `exempt_reason` |

### TMI Control Types

| Type | FIXM US Ext | TFMS Code | EUROCONTROL | Description | VATSWIM |
|------|-------------|-----------|-------------|-------------|-------------|
| Ground Stop | `GROUND_STOP` | `GS` | — | Ground Stop | `GS` |
| Ground Delay Program | `GDP` | `GDP` | `GDP` | Ground Delay Program | `GDP` |
| Airspace Flow Program | `AFP` | `AFP` | `AFP` | Airspace Flow Program | `AFP` |
| Miles-in-Trail | `MIT` | `MIT` | `MIT` | Miles-in-Trail | `MIT` |
| Minutes-in-Trail | `MINIT` | `MINIT` | `MINIT` | Minutes-in-Trail | `MINIT` |
| Reroute | `REROUTE` | `RR` | `RR` | Reroute | `REROUTE` |
| CTOP | `CTOP` | `CTOP` | — | Collaborative Trajectory Options | — |
| Regulation | — | — | `REG` | EUROCONTROL Regulation | — |

### Delay Status Codes (TFMS)

| Code | TFMS | Description | VATSWIM |
|------|------|-------------|-------------|
| `ALD` | Airline Delay | Carrier-caused delay | `ALD` |
| `GDP` | GDP Delay | Ground Delay Program | `GDP` |
| `AFP` | AFP Delay | Airspace Flow Program | `AFP` |
| `GSD` | Ground Stop Delay | Ground Stop hold | `GSD` |
| `DAS` | Delay Assignment | Generic delay assignment | `DAS` |
| `TOD` | Timeout Delay | PTIME + 2hrs exceeded | `TOD` |
| `CTOP` | CTOP Delay | CTOP program delay | — |

---

## 10. Flight Status / Phase

| Concept | FIXM 4.3 (FlightStatusType) | AIDX 22.1 | TFMS Status | A-CDM | ASTERIX | VATSWIM |
|---------|------------------------------|-----------|-------------|-------|---------|-------------|
| **Unknown** | `UNKNOWN` | — | — | — | — | `UNKNOWN` |
| **Scheduled** | `SCHEDULED` | `Scheduled` | `SCHED` | `SCH` | — | `SCHEDULED` |
| **Filed** | `FILED` | `Filed` | `FILED` | — | — | `FILED` |
| **Active (Preflight)** | `ACTIVE` | `Active` | `ACTIVE` | `ACT` | — | `ACTIVE` |
| **Taxiing Out** | — | `Boarding` / `Taxiing` | — | `BRD` / `TXO` | — | `TAXI_OUT` |
| **Departed** | — | `Departed` | `DEPT` | `DEP` | — | `DEPARTING` |
| **Climbing** | `ASCENDING` | `Airborne` | `AIRBORNE` | `AIR` | I062/200 | `ASCENDING` |
| **Cruising** | `CRUISING` | `Airborne` | `AIRBORNE` | `AIR` | I062/200 | `CRUISING` |
| **Descending** | `DESCENDING` | `Airborne` | `AIRBORNE` | `AIR` | I062/200 | `DESCENDING` |
| **Approach** | — | `Approach` | — | — | — | `APPROACH` |
| **Landed** | — | `Landed` | — | `LND` | — | `ARRIVED` |
| **Taxiing In** | — | `Taxiing` | — | `TXI` | — | `TAXI_IN` |
| **Arrived (In Block)** | — | `Arrived` | `ARRIVED` | `ARR` | — | `ARRIVED` |
| **Completed** | `COMPLETED` | `Completed` | `COMPLETED` | — | — | `COMPLETED` |
| **Cancelled** | `CANCELLED` | `Cancelled` | `CNX` | `CNX` | — | — |
| **Controlled (TMI)** | `CONTROLLED` | — | `CONTROLLED` | — | — | `CONTROLLED` |
| **Decontrolled** | `DECONTROLLED` | — | `DECONTROLLED` | — | — | — |
| **Diverted** | — | `Diverted` | `DIV` | `DIV` | — | — |
| **Error** | `ERROR` | — | — | — | — | `ERROR` |

---

## 11. Cancellation Status (CNX)

| Code | TFMS | FIXM | Description | VATSWIM |
|------|------|------|-------------|-------------|
| `UX` | Update Cancelled | — | Flight plan update cancelled | — |
| `FX` | Flight Cancelled | `CANCELLED` | Airline/operator cancelled | `FX` |
| `RZ` | NAS Removal | — | Removed by NAS/ATC | `RZ` |
| `RS` | Schedule Removal | — | Removed from OAG schedule | — |
| `TO` | Timeout | — | PTIME + 2hrs without departure | `TO` |
| `DV` | Diverted | — | Diverted to different airport | `DV` |
| `RM` | Removed | — | Removed from system | `RM` |
| `ID` | ID Changed | — | Callsign/ID changed | `ID` |

---

## 12. ETD/ETA Prefix Codes (TFMS)

| Prefix | TFMS Meaning | Description | Source |
|--------|--------------|-------------|--------|
| `S` | Scheduled | From OAG/schedule data | Schedule |
| `P` | Proposed | From filed flight plan | Flight Plan |
| `N` | Early Intent | Airline pre-departure intent | Airline |
| `L` | Airline | Airline-provided time | Airline |
| `E` | Estimated | System-calculated estimate | TFMS |
| `T` | Taxied | Aircraft has begun taxi | OOOI |
| `A` | Actual | Actual observed time | OOOI |
| `R` | Reroute | Time after reroute | TMI |
| `M` | Metering | TBFM metering time | TBFM |
| `D` | DOF-based | Date-of-flight derived | System |
| `C` | Controlled | TMI-controlled time | TMI |

---

## 13. Airspace & Boundaries

| Concept | FIXM 4.3 | AIXM 5.1 | TFMS | ASTERIX | VATSWIM |
|---------|----------|----------|------|---------|-------------|
| **ARTCC/FIR** | `airspace` | `Airspace` (type=FIR) | `ARTCC` | I062/270 | `current_artcc` |
| **Sector** | `sectorId` | `AirspaceVolume` | `SECTOR` | — | `current_sector` |
| **TRACON** | — | `Airspace` (type=TMA) | `TRACON` | — | `current_tracon` |
| **Departure ARTCC** | `departureAirspace` | — | `DEPT_CTR` | — | `dept_artcc` |
| **Arrival ARTCC** | `arrivalAirspace` | — | `DEST_CTR` | — | `dest_artcc` |
| **Current FIR** | `currentAirspace` | — | `CUR_ARTCC` | — | `current_artcc` |
| **Boundary Crossing** | `boundaryCrossing` | — | `BDRY_TIME` | — | — |
| **Entry Point** | `entryPoint` | `SignificantPoint` | — | — | — |
| **Exit Point** | `exitPoint` | `SignificantPoint` | — | — | — |

---

## 14. Metering & Sequencing (TBFM)

| Concept | FIXM 4.3 US Ext | TFMS/TBFM | EUROCONTROL | SimTraffic | VATSWIM |
|---------|-----------------|-----------|-------------|------------|-------------|
| **Meter Fix** | `meteringPoint` | `METER_FIX` | — | `meter_fix` | `meter_fix` |
| **Meter Fix Time** | `meteringTime` | `MF_TIME` | — | `mf_time` | `meter_fix_time` |
| **Scheduled Time of Arrival** | `scheduledTimeOfArrival` | `STA` | `STA` | `sta` | `sta_utc` |
| **Runway Scheduled Time** | `runwayScheduledTime` | `RST` | — | `rst` | — |
| **Sequence Number** | `sequenceNumber` | `SEQ_NUM` | — | `sequence` | `sequence_number` |
| **Delay at Meter Fix** | `meteringDelay` | `MF_DELAY` | — | `mf_delay` | — |
| **Freeze Horizon** | — | `FREEZE_HORIZON` | — | — | — |
| **Coupled Status** | — | `COUPLED` | — | — | — |

---

## 15. Weather Data (IWXXM / WXXM)

| Concept | IWXXM 3.0 | WXXM 2.0 | TFMS | VATSWIM |
|---------|-----------|----------|------|-------------|
| **Wind Direction** | `windDirection` | `WindDirection` | `WIND_DIR` | `wind_dir_deg` |
| **Wind Speed** | `windSpeed` | `WindSpeed` | `WIND_SPD` | `wind_speed_kts` |
| **Temperature** | `airTemperature` | `Temperature` | `TEMP` | `temperature_c` |
| **Altimeter** | `qnh` | `AltimeterSetting` | `ALTIM` | `altimeter_inhg` |
| **Visibility** | `visibility` | `Visibility` | `VIS` | — |
| **Ceiling** | `cloudBase` | `CloudBase` | `CIG` | — |
| **Weather Phenomenon** | `presentWeather` | `Weather` | `WX` | — |

---

## 16. Units & Formats

| Measurement | FIXM | AIDX | TFMS | ASTERIX | Preferred |
|-------------|------|------|------|---------|-----------|
| **Altitude** | feet (ft) | feet | hundreds of feet | flight levels / 25ft | feet |
| **Speed** | knots (kts) | knots | knots | 1/4 NM/s | knots |
| **Distance** | nautical miles (NM) | NM | NM | 1/128 NM | NM |
| **Heading/Track** | degrees true | degrees | degrees | degrees × 360/2^16 | degrees |
| **Vertical Rate** | feet/min | feet/min | feet/min | 6.25 ft/min | feet/min |
| **Time** | ISO 8601 UTC | ISO 8601 UTC | HHMM UTC | seconds past midnight | ISO 8601 UTC |
| **Date** | YYYY-MM-DD | YYYY-MM-DD | YYMMDD | — | ISO 8601 |
| **Coordinates** | decimal degrees | decimal degrees | deg/min/sec | WGS-84 | decimal degrees |

### Time Format Examples

| Standard | Format | Example |
|----------|--------|---------|
| FIXM | `YYYY-MM-DDTHH:MM:SSZ` | `2026-01-16T14:30:00Z` |
| AIDX | `YYYY-MM-DDTHH:MM:SS` | `2026-01-16T14:30:00` |
| TFMS | `YYMMDDHHMM` | `2601161430` |
| ASTERIX | Seconds since midnight UTC | `52200` |
| ACARS | `DDHHMM` | `161430` |

---

## 17. GUFI Format Comparison

| Standard | Format | Example |
|----------|--------|---------|
| **FIXM/FF-ICE** | UUID v4 | `123e4567-e89b-12d3-a456-426614174000` |
| **FAA GUFI** | `YYYYMMDD.HHMM.ACID.DEPT.DEST.REG` | `20260116.1430.UAL123.KJFK.KLAX.N12345` |
| **EUROCONTROL** | IFPLID | `AA12345678` |
| **VATSWIM** | `VAT-YYYYMMDD-CALLSIGN-DEPT-DEST` | `VAT-20260116-UAL123-KJFK-KLAX` |

---

## 18. Namespace Reference

| Standard | Namespace URI | Prefix |
|----------|---------------|--------|
| FIXM Core 4.3 | `http://www.fixm.aero/flight/4.3` | `fx` |
| FIXM Base 4.3 | `http://www.fixm.aero/base/4.3` | `fb` |
| FIXM US Extension | `http://www.faa.aero/nas/4.3` | `nas` |
| FF-ICE Application | `http://www.fixm.aero/app/ffice/1.1` | `ffice` |
| AIXM 5.1 | `http://www.aixm.aero/schema/5.1` | `aixm` |
| IWXXM 3.0 | `http://icao.int/iwxxm/3.0` | `iwxxm` |
| AIDX 22.1 | `http://www.iata.org/IATA/2007/00` | `aidx` |
| GML 3.2 | `http://www.opengis.net/gml/3.2` | `gml` |
| vATCSCC Extension | `http://vatcscc.org/schema/1.0` | `vatc` |

---

## 19. Quick Reference: Common Field Mappings for VATSWIM

| Your Current Field | FIXM 4.3 | AIDX | TFMS | Recommended Standard Name |
|--------------------|----------|------|------|---------------------------|
| `gufi` | `gufi` | `FlightId` | — | `gufi` ✓ |
| `callsign` | `aircraftIdentification` | `AirlineFlightId` | `ACID` | `aircraftIdentification` |
| `cid` | — | — | — | `vatsimCid` (extension) |
| `dept_icao` | `departureAerodrome` | `DepartureAirport` | `DEPT` | `departureAerodrome` |
| `dest_icao` | `arrivalAerodrome` | `ArrivalAirport` | `DEST` | `arrivalAerodrome` |
| `aircraft_type` | `aircraftType` | `AircraftType` | `TYPE` | `aircraftType` |
| `route` | `routeText` | — | `ROUTE` | `routeText` |
| `lat` | `position/latitude` | — | `LAT` | `latitude` |
| `lon` | `position/longitude` | — | `LON` | `longitude` |
| `altitude_ft` | `altitude` | — | `ALT` | `altitude` |
| `heading` | `track` | — | `HDG` | `track` |
| `groundspeed` | `groundSpeed` | — | `GS` | `groundSpeed` |
| `phase` | `flightStatus` | `FlightStatus` | `STATUS` | `flightStatus` |
| `out_utc` | `actualOffBlockTime` | `AOBT` | `OUT` | `actualOffBlockTime` |
| `off_utc` | `actualTimeOfDeparture` | `ATOT` | `OFF` | `actualTimeOfDeparture` |
| `on_utc` | `actualLandingTime` | `ALDT` | `ON` | `actualLandingTime` |
| `in_utc` | `actualInBlockTime` | `AIBT` | `IN` | `actualInBlockTime` |
| `etd_utc` | `estimatedOffBlockTime` | `EOBT` | `EOBT` | `estimatedOffBlockTime` |
| `eta_utc` | `estimatedTimeOfArrival` | `ETA` | `ETA` | `estimatedTimeOfArrival` |
| `edct_utc` | `expectedDepartureClearanceTime` | — | `EDCT` | `expectedDepartureClearanceTime` |
| `ctd_utc` | `controlledTimeOfDeparture` | — | `CTD` | `controlledTimeOfDeparture` |
| `ctl_type` | `controlType` | — | `CTL_TYPE` | `controlType` |
| `ctl_prgm` | `programName` | — | `CTL_PRGM` | `programName` |
| `delay_minutes` | `delayValue` | — | `DLA_ASGN` | `delayValue` |
| `gs_held` | `groundStopStatus` | — | `GS_STATUS` | `groundStopHeld` |

---

*End of Cross-Reference Document*
