# Prong 4: TFMS Demand & Capacity Management Analysis

**Source Documents**: TFMS Reference Manual TSD v8.9, TSD Reference Manual v9.5, TFM Adaptation Manual
**Analysis Date**: 2026-03-31
**Purpose**: Extract actionable technical details for improving PERTI's VATSIM simulation of FAA TFMS demand and capacity management functions.

---

## 1. Aggregate Demand List (ADL)

### 1.1 Definition and Purpose

The ADL is the mechanism by which the Volpe National Transportation Systems Center distributes updated flight schedule and NAS information back to CDM (Collaborative Decision Making) Airline Operations Centers (AOCs). Per the TFMS glossary:

> "When Volpe receives updated flight schedules and NAS info from CDM AOCs, it sends information back as an ADL."

The ADL is not a single static list displayed in the TSD; rather, it is the aggregate data feed that populates the TFMS Traffic Database (TDB), which in turn drives all demand prediction, monitoring, and alerting functions.

### 1.2 Data Sources Feeding the ADL/TDB

| Source | Description | Scope |
|--------|-------------|-------|
| **OAG (Official Airline Guide)** | Scheduled airline flight plans from the Schedule Database (SDB). Used when request time exceeds TDB scope (~15+ hours in future, up to ~45 days). | Scheduled commercial flights |
| **BASE (Baseline)** | Flight count/list reports based on baseline sector configurations rather than current sector configurations. | Sector-referenced demand |
| **FCATA** | Flight predictions for flights traversing a specific FEA/FCA, including secondary filter support. | FCA-scoped demand |
| **TZ Messages** | Position updates from ARTCCs transmitted every 5 minutes. When no TZ messages are received from an ARTCC for 3 successive updates, a "no radar" symbol appears. | Active flight positions |
| **CDM AOC Data** | Airlines provide OUT/OFF/ON/IN times, early intent, and cancellations. | Airline operational data |
| **GAEL/GAES** | General Aviation Estimate data per 15-minute interval per airport, set by traffic managers to account for unscheduled VFR/GA traffic. | Unscheduled demand |

### 1.3 Flight Status Lifecycle

Every flight in the TDB has a status code indicating its phase in the lifecycle. The ETD prefix indicates both status and data source:

| Status | Meaning | ETD Prefix |
|--------|---------|------------|
| **S** | Scheduled (OAG) -- flight exists only in schedule database | S |
| **N** | Early Intent -- airline has filed early notification | N |
| **P** | Proposed -- NAS flight plan filed but not yet active | P |
| **T** | Taxi -- aircraft has pushed back from gate, taxiing | T |
| **A** | Active -- airborne, tracked by radar | A |
| **E** | Estimated -- position estimated (between radar updates) | E |
| **R** | Replaced -- assigned route has replaced filed route | R |
| **C** | Controlled -- under TMI (GDP/GS/AFP) control | C |
| **L** | Airline Provided -- airline-reported time | L |
| **M** | TMA Release Time | M |

### 1.4 Position Update Cycle

- Radar position data (TZ messages) transmits from ARTCCs **every 5 minutes**
- Between TZ updates, TFMS **estimates positions every 1 minute** for display interpolation
- If no position data received for **7+ minutes**, a **ghost icon** (outline) appears for the flight
- If no TZ messages from an ARTCC for **3 successive updates** (15 minutes), a no-radar symbol appears

### 1.5 Flight Data Fields

Key time fields tracked per flight:

- **ETD** -- Estimated Time of Departure (wheels-up)
- **ETA** -- Estimated Time of Arrival (wheels-down)
- **BETA/BETD** -- Beginning Estimated Time of Arrival/Departure (initial estimate when flight created)
- **OETA/OETD** -- Original Estimated Time of Arrival/Departure
- **CTA/CTD** -- Controlled Time of Arrival/Departure (GDP/GS/CTOP assigned)
- **OCTA/OCTD** -- Original Control Time of Arrival/Departure
- **ARTA/ARTD** -- Actual Runway Time of Arrival/Departure
- **OUT/OFF/ON/IN** -- Airline-reported OOOI gate events
- **IGTA/IGTD** -- Initial Gate Time of Arrival/Departure (flight creation time, used for positive identification)
- **LGTA/LGTD** -- Airline Gate Time of Arrival/Departure
- **PGTA/PGTD** -- Proposed Gate Time of Arrival/Departure

---

## 2. Trajectory Modeling

### 2.1 ManualProfile -- Standard Ascent/Descent Profiles

TFMS predicts flight altitudes during climb and descent using the **ManualProfile** adaptation data, stored under `/opt/tfms/adapt_data/ManualProfile/`.

The Standard Ascent/Descent Profile Definition (SA/DPD) database uses three tables:

| Table | Purpose | Key Columns |
|-------|---------|-------------|
| `src_asc_dsc_profile` | Profile definition (name, type A/D) | `profile_name`, `type` (A=Ascent, D=Descent), `IDFR` |
| `src_profile_mapping` | Maps profiles to aircraft types | `profile_name`, `acft_type` (A=All, J=Jet, P=Prop, T=Turbo) |
| `src_profile_pt` | Profile waypoints (altitude/distance/speed) | `alt`, `horizontal_distance`, `vertical_segment_pair`, `true_air_speed`, `indicated_airspeed`, `mach` |

Each profile is a sequence of **level segments** defined by:
- **Level Segment Altitude** (`alt`) -- flight level at this segment
- **Level Segment Length** (`horizontal_distance`) -- horizontal distance of this segment in NM
- **Climb/Descend Angle** (`vertical_segment_pair`) -- vertical path angle
- **Acceleration/Deceleration** (`true_air_speed`) -- TAS at segment
- **Climb/Descend IAS** (`indicated_airspeed`) -- indicated airspeed at segment
- **Mach** -- Mach number at segment

Custom profiles stored in:
- `custom_ascent_profiles.dat` -- Field 10 (modified), aircraft category, ascent profile
- `custom_descent_profiles.dat` -- Field 10 (modified), aircraft category, descent profile

### 2.2 Position Estimation Between Updates

- Updated position information transmits from ARTCCs at least **every 5 minutes**
- Positions are **estimated between data updates** so screen display changes **every minute**
- The estimation uses filed route, current position, groundspeed, and heading to project forward
- Lead lines on the display show projected future positions assuming constant direction and speed

### 2.3 Ground Time Predictor (GTP)

The **Ground Time Predictor** predicts flight departures based on historical data:
- **GTP ACID** -- displays up to 14 most recent departures for a specific flight from a specific airport
- **GTP AIRP** -- displays summary overview of ground times at specified airports over a 24-hour period
- **GTM** (Ground Time Method) -- method for making predictions of flights based on ground time data

---

## 3. Demand Prediction

### 3.1 The 15-Minute Interval Standard

All TFMS demand prediction, monitoring, and alerting operates on a **15-minute interval** basis. This is the fundamental time quantum of the entire system:

- NAS Monitor displays alerts in 15-minute columns
- Bar charts show demand per 15-minute interval
- FEA/FCA timelines count flights per 15-minute interval
- GAEL/GAES sets GA estimates per 15-minute interval
- Request commands default to 5-hour windows divided into 15-minute intervals
- GDP slots are assigned in 15-minute buckets

### 3.2 Demand Counting Methods

TFMS counts demand using three distinct methods, depending on the NAS element type:

| Element Type | Demand Definition | Counting Method |
|--------------|-------------------|-----------------|
| **Airport** | Number of arrivals and departures during the interval | Two separate counts: arriving flights landing within the 15-min period, departing flights taking off within the 15-min period |
| **Sector** | Peak occupancy -- the greatest number of flights projected to be within the sector at any instant | **Peak 1-minute count**: the maximum number of flights in the sector at any single minute within the 15-minute interval |
| **Fix** | Number of flights crossing the fix during the interval | Count of flights whose trajectory crosses the fix within the 15-minute period |

The **peak demand** definition is critical for sectors:
> "The greatest number of flights projected to be within a sector at any instant during a given time period. Also called peak load."

For FEA/FCA timelines, three count types are available:
1. **Entry Count** -- flights entering the FEA/FCA during the 15-minute interval
2. **Peak Occupancy** -- "maximum number of flights expected to occupy the FEA/FCA during any 1-minute interval within the 15-minute interval"
3. **Total Flights** -- total distinct flights traversing the FEA/FCA during the interval

### 3.3 Data Source Hierarchy

| Time Horizon | Data Source | Characteristics |
|--------------|-------------|-----------------|
| Past 15 hours to present | TDB (Traffic Database) with actual + estimated data | Most accurate, based on radar and flight plan data |
| Present to +15 hours | TDB with flight plans + projections | Includes filed routes, ETAs, and scheduled flights |
| +15 hours to +45 days | SDB (Schedule Database / OAG) | Schedule-only data; fix and sector information unavailable beyond 15 hours |

### 3.4 Demand Calculation for Reports

Request commands generate demand reports with these options:
- **EVERY** option: Sets the reporting interval (default 15 minutes)
- **SETLEN** option: Sets the time window for each report
- **COUNT** option: Generates flight count reports (vs flight list reports)
- **Arrivals/Departures (A/D)**: Limits airport reports to arrivals or departures only
- **GSTOP** option: For controlled airports, shows total flights, controlled flights (past original departure), and flights past their ETD

---

## 4. Capacity Management

### 4.1 Monitor Alert Parameter (MAP)

The **MAP** (Monitor Alert Parameter) is the threshold that determines alert status. Each NAS element (airport, sector, fix) has a MAP value representing its capacity.

MAP values operate with a **hysteresis mechanism** using MAP On and MAP Off values:
- **MAP On**: The threshold at which an alert triggers (demand exceeds this value)
- **MAP Off**: The lower threshold at which an alert clears (demand drops below this value)
- MAP Off is always less than or equal to MAP On, preventing rapid alert toggling

### 4.2 Default Capacity Values

From the `sectorcapacities.dat` adaptation file:

```
# (default capacities for low, high, superhigh, tracon and oceanic sectors)
20 15 12 20 15
```

| Sector Type | Default MAP Value |
|-------------|-------------------|
| **Low altitude** (ground to ~23,000 ft) | **20** flights |
| **High altitude** (~24,000 to ~33,000/60,000 ft) | **15** flights |
| **Superhigh altitude** | **12** flights |
| **TRACON** | **20** flights |
| **Oceanic** | **15** flights |

These defaults are overridden per-sector in ARTCC-specific threshold files:
- Path pattern: `TFMS/tdb/static_data/{ARTCC}_sector_thresholds.dat`
- 34 ARTCC files listed (20 US ARTCCs + 7 Canadian centers + ZUA1 + ZHN + ZSU)
- Updated on the **7-day weekly adaptation cycle** (picked up Tuesday night, available Wednesday morning)

### 4.3 Airport Capacity Files

| File | Contents | Format |
|------|----------|--------|
| `aar_adr` | Default arrival and departure rates for monitored airports; includes default (0) setting for FCAs | Identifier, Arrival Rate, Departure Rate |
| `airportcapacities.dat` | Departure and arrival capacity thresholds | Designator, Dep cap, Arr cap |
| `fixcapacities.dat` | Fix crossing capacity per 15-minute interval for low, high, and superhigh | Designator, Low cap, High cap, Superhigh cap |

### 4.4 CAPS Command -- Setting Capacity

The **CAPS** (Capacities Set) command allows dynamic capacity changes:

- **ATCSCC users**: Can change MAP for any airport, fix, or sector in the NAS
- **Field site users**: Can only change MAP for elements within their own ARTCC
- **Duration**: Settings can be applied for up to **24 hours**

### 4.5 CAPL Command -- Listing Capacity

The **CAPL** (Capacities List) command displays current capacity settings:
- Shows **nominal** (adaptation-default) MAP values alongside **today's** (dynamically set) values
- Indicates which values have been changed from defaults
- Available for airports (showing AAR and ADR separately), sectors, and fixes

### 4.6 Airport Acceptance Rate (AAR) and Airport Departure Rate (ADR)

- **AAR**: "The number of arriving aircraft that an airport or terminal area can accept from the ARTCC or TRACON, as appropriate, per unit of time"
- **ADR**: "The number of departing aircraft an airport can accommodate during a period of time"
- Both are expressed as flights per time period (typically per hour or per 15-minute interval)
- Pacing airports (~30 larger airports) whose arrival/departure traffic "sets the pace for all air traffic throughout the CONUS"

---

## 5. Monitor/Alert System

### 5.1 NAS Monitor Display Structure

The NAS Monitor is the primary alerting interface:

> "Displays alert information in table form with NAS elements along one axis and 15-minute time intervals along the other."

Layout:
- **Rows**: NAS elements (airports, sectors, fixes) -- configurable via adaptation
- **Columns**: 15-minute time intervals spanning the **Alert Time Limit**
- **Alert Time Limit**: Minimum **0.25 hours**, maximum **6.0 hours**, default display range **2.25 hours**
- Each cell shows the **peak 1-minute count** within that 15-minute interval

### 5.2 Alert Color Coding

Three alert states with strict color definitions:

| Color | Condition | Meaning |
|-------|-----------|---------|
| **Red** | Active flights predicted in element **exceed MAP On** | Demand exceeds capacity based on currently airborne/taxiing flights |
| **Yellow** | Total flights (active + proposed) **exceed MAP On**, but active alone do not | Demand exceeds capacity only when including scheduled/proposed flights |
| **Green** | Demand at or below **MAP On/MAP Off values** | No alert condition |

Additional visual states:
- **Green with yellow stripe**: Previous alert for proposed flights that has since resolved
- **Turn Green**: TMU acknowledgment that an alert has been reviewed; transmitted to all TSDs at all sites at the next alert update (within five minutes)

### 5.3 Bar Chart Display

- Shows **active, proposed, and total flights** projected for each 15-minute interval
- For **airports**: Two bars per interval -- one for arrivals, one for departures
- For **sectors**: Peak 1-minute count display
- **Bar chart colors**: Red (active flights), Yellow (proposed flights)
- **Display range**: Up to **6 hours**
- **Capacity line**: A horizontal line at the MAP value shows the threshold

### 5.4 Time in Sector Display

A granular visualization within a single 15-minute interval:

> "Graphically depicts the flights that cross the sector during a 15-minute interval. Each flight is represented as a horizontal bar from its entry into the sector to its exit... horizontal line near the bottom shows one-minute intervals."

This provides 1-minute resolution of sector occupancy within each 15-minute bucket.

### 5.5 Examined Flights

For each alerted element, users can view detailed flight breakdowns:
- **Total flights** in the element during the interval
- **Controlled flights** -- flights past their original departure time (under TMI control)
- **Past ETD** -- flights that have exceeded their estimated departure time
- **RVSM non-conformant** -- flights not meeting Reduced Vertical Separation Minimum requirements

### 5.6 Turn Green Protocol

When a TMU acknowledges an alert:
1. The element is marked as "Turn Green" locally
2. The acknowledgment transmits to **all TSDs at all sites within 5 minutes** (at next alert update cycle)
3. All other TSDs display the change when alert data is updated

This is a coordination mechanism -- it does not change the underlying demand or capacity, only indicates the alert has been reviewed.

---

## 6. Flow Constrained Areas (FCA)

### 6.1 FEA vs FCA Distinction

| Feature | FEA (Flow Evaluation Area) | FCA (Flow Constrained Area) |
|---------|---------------------------|----------------------------|
| **Created by** | Any field site | ATCSCC only |
| **Can have AFP** | No | Yes (if FSM-Eligible) |
| **Can have CTOP** | No | Yes |

### 6.2 FEA/FCA Geometry Types

1. **Polygon** -- Up to 60 points (moving: heading 0-359, speed 0-99 kt)
2. **Line** -- Line segment with lateral width
3. **Circle** -- Center point + radius (1-999 nm)
4. **NAS Element** -- Airport/Center/Sector/Base Sector/Fix/TRACON/SUA

### 6.3 Parameters

| Parameter | Range/Default | Notes |
|-----------|---------------|-------|
| **Start Time** | Current 15-minute interval | Aligns to 15-min boundary |
| **End Time** | Up to 24 hours (standard) | Must be after start time |
| **Extended** | Up to **7 days** | Checkbox enables longer duration |
| **Look Ahead Time Range** | **1 to 24 hours** | How far ahead to project flights |
| **Altitude Floor** | **000 to 600** (hundreds of feet) | 000 = surface, 600 = FL600 |
| **Altitude Ceiling** | **000 to 600** (hundreds of feet) | Must be >= Floor |
| **Moving Heading** | **000 to 359 degrees** | Direction FEA/FCA moves |
| **Moving Speed** | **00 to 99 knots** | Speed of FEA/FCA movement |
| **Auto-expiry** | **60 minutes after end time** | Automatically deleted |
| **FSM-Eligible name** | **Max 6 characters** | Cannot match airport name |

### 6.4 FEA/FCA Timeline Display

Three toggleable count types:
1. **Total Counts** -- all flights in the FCA during the interval
2. **Entry Counts** -- flights entering the FCA during the interval
3. **Peak Counts** -- maximum simultaneous flights (1-minute peak within 15-minute interval)

---

## 7. Weather Impact on Demand/Capacity

### 7.1 Weather Data Sources

**CIWS** forecasts at **30, 60, and 120 minute** horizons. Storm motion updated every **2.5 minutes** with extrapolated positions at **10 and 20 minutes**.

**CCFP**: 4/6/8-hour convective forecasts, updated every 2 hours.

### 7.2 Weather Impact on Capacity

Weather drives capacity reduction through:
1. **AAR/ADR reduction**: TMUs reduce rates via CAPS command when convective weather affects approaches
2. **Reroute demand redistribution**: RRIA quantifies sector impact with Alerted Sector Peaks
3. **FCA creation for weather**: Moving FCAs tracking convective cells
4. **Model mode impact assessment**: NAS snapshot projecting weather impact (static, staleness warning at >15 minutes)

---

## 8. Summary of Key Thresholds and Parameters

| Parameter | TFMS Value | PERTI Mapping |
|-----------|------------|---------------|
| Demand interval | 15 minutes | `bucket_*` columns in `adl_flight_times` |
| Peak counting resolution | 1-minute within 15-min interval | Needs implementation in demand calculation |
| Position update cycle | 5 min (TZ messages) | 15 sec (VATSIM feed -- higher resolution) |
| Ghost threshold | 7 minutes no update | `last_seen_utc` check in ADL daemon |
| No-radar threshold | 3 successive missed TZ updates (15 min) | Not yet implemented |
| Default sector MAPs | Low=20, High=15, Superhigh=12, TRACON=20, Oceanic=15 | `sector_boundaries` or new config table |
| Alert time limit | 0.25 to 6.0 hours, default 2.25 | Configurable per user in NOD |
| MAP hysteresis | MAP On (trigger) / MAP Off (clear) | Dual threshold in `demand_monitors` |
| FCA altitude range | FL000 to FL600 | PostGIS boundary definitions with altitude |
| FCA time range | Up to 24 hours (7 days extended) | FCA table with start/end timestamps |
| Moving FCA | Heading 000-359, Speed 00-99 kt | PostGIS time-varying boundary |
| FCA auto-expiry | 60 minutes after end time | Cron-based cleanup |
| CIWS forecast horizons | 30, 60, 120 minutes | Weather forecast integration |
| Storm motion update | Every 2.5 minutes | Weather radar integration |
| Adaptation cycle (sectors) | 7-day weekly | Weekly threshold file refresh |
| Adaptation cycle (airspace) | 56-day | Aligned with AIRAC cycle |
| Request data scope | Past 15 hours to future 45 days | ADL query range limits |
| Bar chart display range | Up to 6 hours | Demand chart configuration |
| Turn Green propagation | Within 5 minutes to all TSDs | WebSocket broadcast |

---

## 9. Priority Implementation Recommendations

### High Priority (Core monitoring accuracy)
1. **Peak 1-minute counting within 15-minute intervals** for sector demand
2. **MAP On/Off hysteresis** for alert thresholds to prevent flickering
3. **Three count types** (Entry, Peak, Total) for all demand displays
4. **Default sector capacities** (20/15/12/20/15) as sensible VATSIM defaults

### Medium Priority (Enhanced flow management)
5. **FCA/FEA geometry engine** using PostGIS with polygon/circle/line/NAS-element types
6. **Primary filter system** for FCA flight scoping (15+ criteria)
7. **Moving FCA support** with time-varying boundary calculations
8. **Reroute impact assessment** metrics (added distance, added time, sector better/worse)

### Lower Priority (Advanced features)
9. **CTOP/TOS/RTC simulation** extending existing GDP algorithm
10. **Model mode** for what-if scenario analysis without affecting live operations
11. **GAEL/GAES equivalent** for unscheduled demand estimation
12. **Turn Green coordination protocol** via WebSocket
