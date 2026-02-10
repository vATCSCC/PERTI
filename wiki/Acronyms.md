# Acronyms & Terminology

This reference defines acronyms and terminology used throughout PERTI and VATSIM traffic management.

---

## PERTI-Specific Terms

| Acronym | Definition |
|---------|------------|
| **PERTI** | Plan, Execute, Review, Train, and Improve |
| **DCC** | Command Center |
| **ADL** | Aggregate Demand List - flight data feed containing schedules and NAS information |
| **GDT** | Ground Delay Tool |
| **JATOC** | Joint Air Traffic Operations Command |
| **NOD** | NAS Operations Dashboard |
| **TMR** | Traffic Management Review - NTMO Guide-style post-event review report (NEW v18) |
| **TSD** | Traffic Situation Display |
| **NTML** | National Traffic Management Log - record of TMI actions |
| **SWIM** | System Wide Information Management - PERTI's public API layer |
| **FEA** | Flow Evaluation Area - airspace element for traffic demand analysis |
| **OpLevel** | Operational Level 1-4 (traffic impact severity) |
| **RBS** | Ration By Schedule - GDP slot assignment algorithm |
| **ASPM82** | FAA Aviation System Performance Metrics 82-airport set |
| **OPSNET45** | FAA Operations Network 45-airport performance metric set |
| **CID** | VATSIM Certificate ID (user identifier) |
| **i18n** | Internationalization - multi-language support system (NEW v18) |

---

## Traffic Management

| Acronym | Definition |
|---------|------------|
| **TMI** | Traffic Management Initiative |
| **TMU** | Traffic Management Unit |
| **GS** | Ground Stop |
| **GDP** | Ground Delay Program |
| **AFP** | Airspace Flow Program - controls departure times for flights arriving at an FCA |
| **EDCT** | Expect Departure Clearance Time |
| **CTA** | Controlled Time of Arrival - assigned arrival slot |
| **ETD** | Estimated Time of Departure |
| **MIT** | Miles-in-Trail |
| **MINIT** | Minutes-in-Trail |
| **AAR** | Airport Arrival Rate (arrivals per hour) |
| **ADR** | Airport Departure Rate (departures per hour) |
| **SWAP** | Severe Weather Avoidance Plan |
| **CTOP** | Collaborative Trajectory Options Program |
| **FCA** | Flow Constrained Area - airspace element used with AFPs |
| **FEA** | Flow Evaluation Area - airspace element for traffic analysis |
| **CDM** | Collaborative Decision Making |
| **ATFM** | Air Traffic Flow Management |
| **DCT** | Direct (routing between two points without following an airway) |

---

## Airspace & Facilities

| Acronym | Definition |
|---------|------------|
| **ARTCC** | Air Route Traffic Control Center (Center) |
| **TRACON** | Terminal Radar Approach Control |
| **ATCT** | Airport Traffic Control Tower |
| **ATCSCC** | Air Traffic Control System Command Center |
| **SUA** | Special Use Airspace |
| **MOA** | Military Operating Area |
| **TFR** | Temporary Flight Restriction |
| **NSA** | National Security Area |
| **FIR** | Flight Information Region |
| **NAS** | National Airspace System |

---

## Flight Operations

| Acronym | Definition |
|---------|------------|
| **IFR** | Instrument Flight Rules |
| **VFR** | Visual Flight Rules |
| **SID** | Standard Instrument Departure |
| **STAR** | Standard Terminal Arrival Route |
| **DP** | Departure Procedure |
| **IAP** | Instrument Approach Procedure |
| **CDR** | Coded Departure Route |
| **OOOI** | Out-Off-On-In (flight phase tracking) |
| **AFIX** | Arrival Fix |
| **DFIX** | Departure Fix |
| **ASECTR** | Arrival Sector |
| **DSECTR** | Departure Sector |

---

## Timing & Scheduling

TFMS uses a consistent prefix/suffix naming convention for times. Understanding this system helps interpret any timing acronym.

### Prefix Meanings

| Prefix | Meaning | Description |
|--------|---------|-------------|
| **O** | Original | Initial value before any modifications or delays |
| **A** | Actual | Recorded actual time (or Adjusted in some contexts) |
| **P** | Proposed/Predicted | Proposed time or prediction |
| **S** | Scheduled | Airline-scheduled time |
| **C** | Controlled | Time assigned by traffic management |
| **E** | Earliest/Estimated | Earliest possible or estimated time |
| **L** | Latest | Latest acceptable time |
| **SR** | Short Range | Estimate valid ~2 hours out |
| **B** | Baseline | Reference baseline time |
| **I** | Initial | Initial calculated time |

### Suffix Meanings

| Suffix | Meaning | Description |
|--------|---------|-------------|
| **TA** | Time of Arrival | Arrival time (general) |
| **TD** | Time of Departure | Departure time (general) |
| **ETA** | Estimated Time of Arrival | Wheel-on time estimate |
| **ETD** | Estimated Time of Departure | Wheel-off time estimate |
| **RTA** | Runway Time of Arrival | Threshold crossing time |
| **RTD** | Runway Time of Departure | Runway departure time |
| **GTA** | Gate Time of Arrival | Gate arrival time (legacy, see note) |
| **GTD** | Gate Time of Departure | Gate departure time (legacy, see note) |
| **TE** | Time Enroute | Flight duration |

> **Note on Gate vs Runway Times:** TFMS renamed gate times to runway times:
> - AGTA/AGTD → ARTA/ARTD (Actual Runway Times)
> - OGTA/OGTD → ORTA/ORTD (Original Runway Times)
> 
> Some legacy systems may still reference the old gate time nomenclature.

### Common Timing Acronyms

#### Estimated Times

| Acronym | Definition |
|---------|------------|
| **ETA** | Estimated Time of Arrival (wheel time) |
| **ETD** | Estimated Time of Departure (wheel time) |
| **OETA** | Original Estimated Time of Arrival |
| **OETD** | Original Estimated Time of Departure |
| **BETA** | Baseline Estimated Time of Arrival |
| **BETD** | Baseline Estimated Time of Departure |
| **ETE** | Estimated Time Enroute |
| **OETE** | Original Estimated Time Enroute |

#### Actual Times

| Acronym | Definition |
|---------|------------|
| **ATA** | Actual Time of Arrival (wheels-on) |
| **ATD** | Actual Time of Departure (wheels-off) |
| **ARTA** | Actual Runway Time of Arrival (formerly AGTA) |
| **ARTD** | Actual Runway Time of Departure (formerly AGTD) |

#### Controlled Times

| Acronym | Definition |
|---------|------------|
| **CTA** | Controlled Time of Arrival |
| **CTD** | Controlled Time of Departure |
| **OCTA** | Original Controlled Time of Arrival |
| **OCTD** | Original Controlled Time of Departure |
| **CETE** | Controlled Estimated Time Enroute |

#### Original/Baseline Times

| Acronym | Definition |
|---------|------------|
| **ORTA** | Original Runway Time of Arrival (formerly OGTA) |
| **ORTD** | Original Runway Time of Departure (formerly OGTD) |
| **OENTRY** | Original Element Entry Time |

#### Runway Times

| Acronym | Definition |
|---------|------------|
| **ERTA** | Earliest Runway Time of Arrival |
| **LRTA** | Latest Runway Time of Arrival |
| **SRTA** | Short Range Time of Arrival (~2 hrs out) |

#### Scheduled Times

| Acronym | Definition |
|---------|------------|
| **STA** | Scheduled Time of Arrival |
| **STD** | Scheduled Time of Departure |

#### Slot Times

| Acronym | Definition |
|---------|------------|
| **ASLOT** | Arrival slot assigned to a flight (airport + time) |
| **DSLOT** | Departure slot assigned to a flight |
| **Slot** | Allocated arrival time in GDP/GS |
| **R-Slot** | Reserved arrival slot |

#### Other Timing Terms

| Acronym | Definition |
|---------|------------|
| **P-Time** | Proposed departure time |
| **A-Time** | Actual departure time |
| **IGTA** | Initial Gate Time of Arrival |
| **AGT** | Actual Ground Time (difference between ARTD and PGTD) |

### Time Difference Calculations

| Acronym | Definition |
|---------|------------|
| **OA_DIF** | Difference between ARTA and ORTA |
| **OD_DIF** | Difference between ARTD and ORTD |
| **PA_DIF** | Difference between ARTA and PGTA |

---

## Flight Status & Delay

| Acronym | Definition |
|---------|------------|
| **ALD** | Airline-imposed delay (carrier delay status) |
| **ARRD** | Arrival Delay prediction |
| **OUT** | Actual gate pushback time (airline reported) |
| **OFF** | Actual wheels-up time (airline reported) |
| **ON** | Actual wheels-on time (airline reported) |
| **IN** | Actual gate arrival time (airline reported) |

---

## Weather Categories

| Acronym | Definition |
|---------|------------|
| **VMC** | Visual Meteorological Conditions |
| **IMC** | Instrument Meteorological Conditions |
| **LVMC** | Low Visibility Meteorological Conditions |
| **LIMC** | Low Instrument Meteorological Conditions |
| **VLIMC** | Very Low Instrument Meteorological Conditions |

---

## Weather & Information

| Acronym | Definition |
|---------|------------|
| **ATIS** | Automatic Terminal Information Service |
| **METAR** | Meteorological Aerodrome Report |
| **TAF** | Terminal Aerodrome Forecast |
| **SIGMET** | Significant Meteorological Information |
| **AIRMET** | Airmen's Meteorological Information |
| **NEXRAD** | Next-Generation Radar |
| **MRMS** | Multi-Radar Multi-Sensor |
| **NOTAM** | Notice to Air Missions |
| **NCWF** | National Convective Weather Forecast |
| **NOWRAD** | Current radar precipitation display |

---

## Airport Categories

| Acronym | Definition |
|---------|------------|
| **Core30** | 30 busiest US airports by operations |
| **OEP35** | 35 Operational Evolution Partnership airports |
| **ASPM82** | 82 airports tracked in FAA Aviation System Performance Metrics |
| **OPSNET45** | 45 airports in FAA Operations Network performance metric set |
| **Pacing Airport** | ~30 larger airports whose traffic sets pace for CONUS operations |

---

## Navigation

| Acronym | Definition |
|---------|------------|
| **VOR** | VHF Omnidirectional Range |
| **NDB** | Non-Directional Beacon |
| **DME** | Distance Measuring Equipment |
| **RNAV** | Area Navigation |
| **RNP** | Required Navigation Performance |
| **GPS** | Global Positioning System |
| **ILS** | Instrument Landing System |

---

## GIS & Spatial

| Acronym | Definition |
|---------|------------|
| **GIS** | Geographic Information System - spatial data management and analysis |
| **PostGIS** | PostgreSQL extension for spatial/geographic data types and functions |
| **WGS84** | World Geodetic System 1984 - GPS coordinate reference system |
| **SRID** | Spatial Reference System Identifier (4326 = WGS84) |
| **GeoJSON** | JSON format for encoding geographic data structures |
| **GIST** | Generalized Search Tree - PostgreSQL index type for spatial queries |
| **Adjacency Class** | Type of boundary relationship: POINT (corner touch), LINE (shared border), POLY (overlap) |
| **Boundary Adjacency** | Graph network of which airspace boundaries share borders |
| **Proximity Tier** | Distance-based classification between boundaries (0-25nm, 25-50nm, 50-100nm, 100-200nm) |
| **Shared Length** | Length in nautical miles of common border between adjacent boundaries |
| **Trajectory Crossing** | Point where a flight path intersects an airspace boundary |
| **Route Expansion** | Process of converting route string to waypoint coordinates via GIS |

---

## Systems & Data

| Acronym | Definition |
|---------|------------|
| **TFMS** | Traffic Flow Management System (current system) |
| **ETMS** | Enhanced Traffic Management System (legacy, replaced by TFMS) |
| **FSM** | Flight Schedule Monitor |
| **ASDI** | Aircraft Situation Display to Industry |
| **TFMDI** | Traffic Flow Management Data to Industry |
| **ASD** | Aircraft Situation Display (legacy, replaced by TSD) |
| **OAG** | Official Airline Guide (schedule data source) |
| **ARINC** | Aeronautical Radio Inc. |
| **CIFP** | Coded Instrument Flight Procedures (DPs/STARs) |
| **BADA** | Base of Aircraft Data - EUROCONTROL aircraft performance model |
| **NASR** | National Airspace System Resources - FAA navdata source |
| **AIRAC** | Aeronautical Information Regulation and Control - 28-day navdata cycle |

---

## VATSIM-Specific

| Acronym | Definition |
|---------|------------|
| **VATSIM** | Virtual Air Traffic Simulation Network |
| **CID** | VATSIM Certificate ID (user identifier) |
| **VATUSA** | VATSIM USA Division |
| **VATCAN** | VATSIM Canada Division |
| **vATCSCC** | Virtual Air Traffic Control System Command Center |
| **CTP** | Cross The Pond - major transatlantic VATSIM event |
| **FNO** | Friday Night Operations - regular VATSIM event |
| **NTMO** | National Traffic Management Officer |

---

## Infrastructure & Technology

| Acronym | Definition |
|---------|------------|
| **PostGIS** | PostgreSQL extension for spatial/geographic data |
| **GIS** | Geographic Information System |
| **CDN** | Content Delivery Network |
| **HA** | High Availability |
| **DTU** | Database Transaction Unit (Azure SQL performance metric) |
| **vCore** | Virtual CPU core (Azure SQL compute unit) |
| **PERTI_MYSQL_ONLY** | Optimization flag to skip Azure SQL connections (~500-1000ms saved) |
| **PSR-12** | PHP Standard Recommendation 12 (coding style) |

---

## Communications

| Acronym | Definition |
|---------|------------|
| **ATC** | Air Traffic Control |
| **CTAF** | Common Traffic Advisory Frequency |
| **UNICOM** | Universal Communications |
| **FSS** | Flight Service Station |

---

## Aircraft & Organizations

| Acronym | Definition |
|---------|------------|
| **GA** | General Aviation |
| **ICAO** | International Civil Aviation Organization |
| **FAA** | Federal Aviation Administration |
| **CONUS** | Continental United States |

---

## GDP/GS Specific Terms

| Term | Definition |
|------|------------|
| **Adaptive Compression (ADPT)** | Moves flights up to fill wasted slots |
| **AffAvgDelay** | Affected Average Delay - total delay ÷ non-exempted flights |
| **Blanket** | GDP affecting all flights regardless of distance |
| **DAS** | Delay Assignment Strategy - GDP compression algorithm |
| **Distance Tier** | GDP scope based on flight distance from destination |
| **ECR** | EDCT Change Request - request to modify assigned departure time |
| **GAAP** | Ground Allocated Arrival Period - GDP slot methodology |
| **Pop-up** | Flight appearing in scope after program activation |
| **Purge** | Cancel a GDP/GS and release all assigned EDCTs |
| **Recontrol** | Re-assign EDCT to a previously controlled flight |
| **Revision** | Modification to an active GDP/GS |
| **Slot Credit Substitution (SCS)** | Swap flights between slots |
| **UDP** | Unified Delay Program - GDP variant with automatic compression |

---

## Data Systems

| Acronym | Definition |
|---------|------------|
| **SWIM** | System Wide Information Management - FAA data sharing program |
| **STDDS** | SWIM Terminal Data Distribution System |
| **SFDPS** | SWIM Flight Data Publication Service |
| **AOC** | Airline Operations Center |
| **FIXM** | Flight Information Exchange Model - XML standard for flight data |
| **WXXM** | Weather Information Exchange Model |

---

## See Also

- [[FAQ]] - Frequently asked questions
- [[Glossary|FAQ#glossary]] - Additional terminology
