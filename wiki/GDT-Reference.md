# GDT Reference — TFMS Traffic Management Operations

> Comprehensive reference synthesized from 8 FAA/TFMS source documents (906 pages total). All factual claims cite source document and page number.

**Source Documents:**

| # | Document | Version | Pages |
|---|----------|---------|-------|
| 1 | FSM User's Guide | v13.0 | 558 |
| 2 | FSM Training Guide | v9.0 | 21 |
| 3 | RT FSA User's Guide | v9.0 | 43 |
| 4 | TFMDI ICD | Release 3 | 48 |
| 5 | ADL File Specification | v14.1 | 58 |
| 6 | ADL File Specification | v13.3 | 57 |
| 7 | FADT File Specification | v4.3 | 50 |
| 8 | Advisories & General Messages | v1.3 | 71 |

**Citation Format:** `[Source#, page]` — e.g., `[1, p.293]` refers to FSM User's Guide page 293.

---

## Part I: Foundations

### 1. TFMS System Overview

The Traffic Flow Management System (TFMS) is the FAA's primary decision-support tool for managing air traffic flow across the National Airspace System (NAS). It replaced the legacy Enhanced Traffic Management System (ETMS) and serves as the backbone for all strategic and tactical traffic management operations conducted by the Air Traffic Control System Command Center (ATCSCC), Traffic Management Units (TMUs), and airline operations centers.

#### 1.1 System Components

TFMS is composed of several interdependent subsystems [1, Ch.1-2]:

| Component | Role |
|-----------|------|
| **TFMS-Core** | Central server infrastructure that ingests NAS data, maintains the Aggregate Demand List (ADL), runs TMI algorithms (RBS++, Compression), and distributes results |
| **FSM (Flight Schedule Monitor)** | Java-based client application used by traffic management specialists to visualize demand, model TMIs, and issue programs |
| **TFMDI (TFM Data to Industry)** | External data interface providing ADL and advisory data to CDM participants, airlines, and third-party systems [4, p.1] |
| **RT FSA (Real-Time Flight Schedule Analyzer)** | Web-based companion application that monitors TMI execution and generates 12 report types updated at 5-minute intervals [3, p.1] |
| **Autosend Server** | Distribution system that delivers FADT files, coversheets, and advisories to hub sites and recipients [1, Ch.11, p.346] |

TFMS-Core generates ADL data; FSM clients consume it for demand visualization, TMI modeling, and program issuance [1, Ch.1-2]. The core server processes inputs from multiple sources — OAG airline schedules, CDM participant messages (airline OUT/OFF/ON/IN times, earliest runway times, flight cancellations), NAS ATC system messages (flight plan amendments, departure and arrival messages, position updates), and EDCT compliance data — to produce a continuously updated picture of NAS demand [5, p.7-9].

#### 1.2 TFMDI Architecture

The TFMDI interface provides two operational servers that are equivalent in function — neither is designated primary or backup [4, p.15]. External clients (airlines, CDM participants, third-party systems) connect to either server to receive ADL data, advisory messages, and TMI notifications. The interface supports both pull-based (file retrieval) and push-based (broadcast) data delivery modes [4, p.15].

#### 1.3 FSM Data Modes

FSM operates in four distinct data modes, each suited to different operational tasks [1, Ch.4, p.203]:

| Mode | Purpose | Data Source | Key Use |
|------|---------|-------------|---------|
| **Monitored Live** | Real-time operations | ADL updates approximately every 5 minutes | Demand monitoring, current situation awareness |
| **Historical** | Post-event analysis | Archived ADL data replayed from a past date/time | After-action reviews, training |
| **GDT (Ground Delay Tool)** | TMI modeling and issuance | Snapshot of live ADL loaded into modeling workspace | GDP/GS/AFP modeling, what-if analysis, program issuance |
| **IPM (Integrated Planning Model)** | Multi-TMI comparison | Multiple simultaneous models for side-by-side evaluation | Strategic planning, comparing program options |

In Monitored Live mode, the FSM client receives ADL updates at approximately 5-minute intervals from TFMS-Core. The GDT mode is the primary workspace for modeling and issuing traffic management initiatives — when a user enters GDT mode, the current ADL snapshot is loaded into the modeling environment where parameters can be adjusted without affecting the live system until a program is formally issued [1, Ch.4, p.203].

#### 1.4 Data Flow Architecture

The overall data flow through TFMS follows this path [5, p.7-9]:

```
OAG Schedules ─────────────┐
CDM Participant Messages ───┤
NAS ATC System Messages ────┤──→ TFMS-Core ──→ ADL Files ──→ FSM Clients
EDCT Compliance Data ───────┤                        │
TBFM Metering Data ─────────┘                        ├──→ TFMDI (external)
                                                     ├──→ RT FSA (web reports)
                                                     └──→ Autosend (FADT/advisories)
```

#### 1.5 RT FSA Reports

RT FSA provides 12 report types for monitoring TMI execution, each updated at 5-minute intervals [3, p.1]:

- **Demand/Capacity** — arrivals vs. AAR by time period
- **Delay Distribution** — histogram of flight delays across the program
- **Program Compliance** — CTD and CTA compliance statistics
- **Cancellation Analysis** — cancelled flights and slot utilization impact
- **Substitution Activity** — SCS/ECR activity and slot movement
- **Pop-up Summary** — flights appearing after program issuance
- **Compression Opportunities** — potential delay savings from compression
- **Carrier Equity** — per-carrier delay distribution and EMA/EMF metrics
- **Airborne Holding** — flights in holding patterns and expected hold durations
- **Exemption Summary** — breakdown of exempt flights by exemption type
- **Slot Utilization** — assigned vs. used slots by time period
- **Program Timeline** — chronological view of program events and modifications

---

### 2. GDP vs GS vs AFP Comparison

TFMS supports three primary TMI program types for managing demand-capacity imbalances. Each targets a different type of constraint and uses a different mechanism to control traffic flow.

#### 2.1 Side-by-Side Comparison

| Aspect | GDP (Ground Delay Program) | Ground Stop (GS) | AFP (Airspace Flow Program) |
|--------|---------------------------|-------------------|----------------------------|
| **Purpose** | Distribute delay across arrival flights via assigned departure slots | Halt all departures to a specific airport | Control traffic flow through a constrained airspace volume |
| **Constraint Type** | AAR (Airport Acceptance Rate) at destination [1, Ch.8, p.293] | Complete airport closure or severe capacity reduction | FCA element acceptance rate (flights per hour through airspace) |
| **Scope Basis** | Airport-based with tier or distance scope [1, Ch.3, p.109-113] | Airport-based with tier scope | FCA/FEA-based airspace volume [1, Ch.3, p.96-97] |
| **Algorithm** | RBS++ (Ration By Schedule plus automatic Compression) [1, App C] | ETD = GS End Time + 1 minute for held flights | RBS++ adapted for airspace element |
| **Delay Mode** | DAS, GAAP, or UDP [1, Ch.3, p.96-97] | N/A (binary hold/release) | DAS, GAAP, or UDP |
| **EDCT Delivery** | FADT file transmitted to hub site [1, Ch.11, p.346] | FADT file transmitted to hub site | FADT file transmitted to hub site |
| **Available for FCA** | No — airport constraint only | No — airport constraint only | Yes — designed for FCA/FEA elements |
| **Blanket Mode** | Yes — affects all flights regardless of scope [1, Ch.18, p.428-434] | Not recommended | No |
| **Substitutions** | Full: SCS, ECR, SUB [1, Ch.12, p.366] | Limited substitution capability | Full: SCS, ECR, SUB |
| **Pop-up Handling** | DAS delay assignment or GAAP unassigned slot fill [1, Ch.11, p.351-352] | Held until GS End + 1 minute | DAS delay or GAAP unassigned slot fill |
| **Typical Duration** | Hours to full operational day | Minutes to a few hours | Hours; often longer than GDP |
| **Typical Trigger** | Sustained reduced AAR (weather, runway closures) | Severe weather, security event, equipment failure | Convective weather, SUA activation, sector capacity |

#### 2.2 Program Types

TFMS defines the following program types, each with distinct algorithm behavior [1, Ch.3, p.96-97]:

| Program Type | Description |
|--------------|-------------|
| **GDP-DAS** | Default program type for airports. Runs the RBS++ algorithm (Ration By Schedule plus Compression). Pop-up flights receive DAS (Delay Assignment) delay. |
| **GDP-GAAP** | Generates unassigned slots for unknown future demand. As flights become known, TFMS assigns them to open slots or gives DAS delay. Automatically sets GAAP delay mode. |
| **GDP-UDP** | Unified Delay Program. Combines DAS-GDP and GAAP-GDP approaches with reserved pop-up slots, a target delay multiplier, and a maximum delay limit. |
| **Compress Flights** | Runs compression algorithm on an existing program to fill gaps from cancellations and no-shows. |
| **Compress Slots** | GAAP only. Adjusts unassigned slot positions without modifying flight EDCTs. No advisory generated. |
| **Ground Stop** | Halts all departures to the constrained airport within scope. |
| **Blanket** | Uniform delay adjustment (positive adds delay, negative releases, -999 releases all). Airport only. |
| **Airborne Holding** | Assigns holding delays to airborne flights approaching a constrained airport. |
| **Purge** | Cancels an active program and releases all EDCTs and controlled times. |
| **AFP-DAS** | Airspace Flow Program with DAS delay assignment for pop-ups. |
| **AFP-GAAP** | Airspace Flow Program with GAAP unassigned slot methodology. |
| **AFP-UDP** | Airspace Flow Program with UDP reserved slots and delay multiplier. |

---

### 3. Time Conventions & Data Timing

TFMS uses a rigorous system of time prefixes, suffixes, and freeze rules to track every phase of a flight's lifecycle. Understanding these conventions is essential for interpreting ADL data, EDCT assignments, and delay calculations.

**All times in TFMS are UTC/Zulu.**

#### 3.1 ETD Prefix Codes

The ETD (Estimated Time of Departure) prefix indicates the source and confidence level of the departure time estimate. Prefixes are listed in ascending priority — higher-priority sources override lower ones [5, p.38-39]:

| Priority | Prefix | Status | Source | Description |
|----------|--------|--------|--------|-------------|
| 1 (lowest) | **S** | Scheduled | OAG data only | Airline-published schedule time; no flight plan filed |
| 2 | **N** | Early iNtent / TOS | Early-intent flight plan | Trajectory Option Set or early-intent message from airline |
| 3 | **R** | Reroute | ATCSCC SWU reroute | Departure time resulting from an ATCSCC reroute assignment |
| 4 | **P** | Proposed | NAS flight plan | Flight plan filed in the NAS (FP or AF message received) |
| 5 | **L** | airLine | CDM message | Airline-reported time via CDM (OUT time estimate or update) |
| 6 | **T** | Taxied | CDM OUT time | Aircraft has pushed back; CDM OUT event received |
| 7 | **E** | Estimated | Active NAS message | NAS system estimate for an active (airborne or taxiing) flight |
| 8 | **A** | Actual | DZ or CDM OFF | Confirmed departure — DZ (departure) message or CDM OFF event |
| 9 (highest) | **M** | Metering | TBFM STD time | TBFM (Time-Based Flow Management) scheduled departure time (v14.1 only) |

#### 3.2 ETA Prefix Codes

ETA prefixes differ based on whether the flight is on the ground or airborne [5, p.39]:

**On Ground (not yet departed):**

| Prefix | Source |
|--------|--------|
| **E** | Estimated (computed from ETD + flight time) |
| **L** | Airline-reported (CDM arrival estimate) |
| **C** | Controlled (CTA assigned by TMI) |

**Departed (airborne):**

| Prefix | Source |
|--------|--------|
| **E** | Estimated (NAS radar/position-based estimate) |
| **A** | Actual (AZ arrival message or CDM ON event) |

#### 3.3 Key Time Fields

The ADL specification defines an extensive set of time fields that track a flight from schedule publication through gate arrival. These fields follow the prefix/suffix naming convention and are documented in the ADL Appendix B [1, App B, p.523-528]:

**Schedule & Initial Times:**

| Field | Full Name | Description | Freeze Behavior |
|-------|-----------|-------------|-----------------|
| **SGTD** | Scheduled Gate Time of Departure | OAG-published gate departure time | Static; set from airline schedule |
| **SGTA** | Scheduled Gate Time of Arrival | OAG-published gate arrival time | Static; set from airline schedule |
| **IGTD** | Initial Gate Time of Departure | First gate departure time computed by TFMS | Set once when flight enters ADL; **never changes** |
| **IGTA** | Initial Gate Time of Arrival | First gate arrival time computed by TFMS | Set once when flight enters ADL; **never changes** |

**Proposed & Airline Times:**

| Field | Full Name | Description |
|-------|-----------|-------------|
| **PGTD** | Proposed Gate Time of Departure | Departure time from NAS flight plan (FP message) |
| **PGTA** | Proposed Gate Time of Arrival | Arrival time derived from NAS flight plan |
| **LRTD** | Airline Runway Time of Departure | Airline-reported runway departure time (CDM) |
| **LRTA** | Airline Runway Time of Arrival | Airline-reported runway arrival time (CDM) |
| **LGTD** | Airline Gate Time of Departure | Airline-reported gate departure time (CDM) |
| **LGTA** | Airline Gate Time of Arrival | Airline-reported gate arrival time (CDM) |

**Earliest & Original Estimated Times:**

| Field | Full Name | Description | Freeze Behavior |
|-------|-----------|-------------|-----------------|
| **ERTD** | Earliest Runway Time of Departure | Earliest possible departure time (CDM) | Updated by airline until departure |
| **ERTA** | Earliest Runway Time of Arrival | Derived: Earliest EDCT + (ETA - ETD) [1, Ch.14, p.391] | Computed from ERTD |
| **OETD** | Original Estimated Time of Departure | Estimated departure time frozen at TMI/departure/timeout | **Freezes** when TMI issued, flight departs, or timeout occurs |
| **OETA** | Original Estimated Time of Arrival | Estimated arrival time frozen at TMI/departure/timeout | **Freezes** with OETD |
| **BETD** | Base Estimated Time of Departure | Estimated departure at TMI issuance or departure | **Freezes** at TMI issuance or departure |
| **BETA** | Base Estimated Time of Arrival | Estimated arrival at TMI issuance or departure | **Freezes** at TMI issuance or departure |

**Actual Times:**

| Field | Full Name | Description |
|-------|-----------|-------------|
| **ARTD** | Actual Runway Time of Departure | Confirmed wheels-off time (DZ message or CDM OFF) |
| **ARTA** | Actual Runway Time of Arrival | Confirmed wheels-on time (AZ message or CDM ON) |

**OOOI Times (CDM Participant Data):**

| Field | Full Name | Description |
|-------|-----------|-------------|
| **OUT** | Gate Pushback | Aircraft pushed back from gate (CDM participant report) |
| **OFF** | Wheels Off | Aircraft lifted off runway (CDM participant report) |
| **ON** | Wheels On | Aircraft touched down on runway (CDM participant report) |
| **IN** | Gate Arrival | Aircraft arrived at gate (CDM participant report) |

**Controlled Times (TMI-Assigned):**

| Field | Full Name | Description | Freeze Behavior |
|-------|-----------|-------------|-----------------|
| **CTD** | Controlled Time of Departure | Current EDCT — the departure time the flight must meet | Updated with each TMI action |
| **CTA** | Controlled Time of Arrival | Current controlled arrival slot time | Updated with each TMI action |
| **OCTD** | Original Controlled Time of Departure | First EDCT ever assigned to this flight | Set once at first TMI assignment; **never changes** |
| **OCTA** | Original Controlled Time of Arrival | First CTA ever assigned to this flight | Set once at first TMI assignment; **never changes** |

#### 3.4 Delay Formulas

TFMS computes delay using standardized formulas [1, App B, p.528]:

| Delay Type | Formula | Notes |
|------------|---------|-------|
| **Absolute Delay** | `Max(0, ETA - (IGTA - taxi_time))` | Total delay relative to initial schedule, adjusted for taxi |
| **Program Delay (GDP)** | `Max(0, CTA - BETA)` | Delay attributable to the GDP program specifically |
| **Program Delay (AFP)** | `Max(0, CTA - BENTRY)` | Delay attributable to the AFP, using baseline entry time |

The distinction between absolute delay and program delay is critical: absolute delay captures the total schedule deviation regardless of cause, while program delay isolates the delay imposed by a specific TMI program.

#### 3.5 Time-Out Logic

When a flight's ETD passes without a confirmed departure, TFMS-Core applies time-out logic to prevent stale data from distorting demand forecasts [5, p.40]:

- If the ETD passes by more than 5 minutes without a departure message, TFMS-Core adds 5 minutes to the ETD **without changing the ETD prefix**
- A flight is cancelled (timed out) after **90 minutes** if NAS messages have been received for the flight, or after **5 minutes** if only OAG/CDM data exists
- Time-out processing applies only to flights within **CONUS plus 7 Canadian centers (ZEG, ZUL, ZMO, ZWG, ZYZ, ZYE, ZVR) plus ZHN (Honolulu) and ZAN (Anchorage)**
- International flights outside these centers are **not timed out** by TFMS-Core

#### 3.6 Time Display Conventions

- All times displayed and stored in **UTC (Zulu)**
- FSM displays times in `ddhhmm` format (2-digit day, 2-digit hour, 2-digit minute) for program parameters [1, Ch.8, p.293]
- ADL files use `YYMMDDHHMM` or `YYMMDDHHMMSS` depending on the field [5, p.12]
- FADT files use `HHMM` local time for EDCT delivery to towers and centers [7]

---

### 4. ADL Data Cycle

The Aggregate Demand List (ADL) is the central data structure in TFMS. It contains a comprehensive record of every known flight relevant to a monitored NAS element, including schedule data, flight plan information, position updates, TMI control actions, and time estimates.

#### 4.1 ADL Fundamentals

- TFMS-Core generates one ADL per monitored NAS element — each airport, FEA, or FCA has its own ADL [5, p.7]
- An airport ADL covers a time window from **current hour minus 1:00** to **current hour plus 35:59**, yielding an approximately 37-hour lookahead window [5, p.12]
- Flights are added to the ADL approximately **24 hours before departure** [5, p.9]
- Flights with delays that push them up to **12 hours past the ADL end time** remain listed, creating an effective display range of approximately 36 hours [5, p.9]
- The ADL is the authoritative source of demand data for all FSM visualizations, TMI modeling, and program execution

#### 4.2 ADL Update Cycle

ADL updates are broadcast from TFMS-Core to connected clients on multiple triggers [5, p.52]:

| Trigger | Frequency | Description |
|---------|-----------|-------------|
| **Periodic broadcast** | Every 5 minutes | Regular interval update with latest flight data |
| **TMI processing** | On event | Immediate update when a GDP, GS, AFP, or other TMI action is processed |
| **FEA/FCA change** | On event | Update when a Flow Evaluation Area or Flow Constrained Area is modified |
| **Client request** | On demand | FSM client can request a fresh ADL at any time |
| **Daily reset** | 0800Z | Broadcast sequence counter resets at 0800Z daily |

FSM displays a warning when the gap between the ADL update timestamp and the local clock exceeds **11 minutes**, indicating stale data — displayed as a red warning indicator [1, Ch.3, p.81].

#### 4.3 ADL File Format

ADL files follow a structured naming convention [5, p.9-10]:

```
element.type.datetime.seq.data.filter
```

| Component | Description | Example |
|-----------|-------------|---------|
| `element` | NAS element identifier (airport, FEA, FCA) | `bos__` (5 chars, padded with underscores) |
| `type` | Data type identifier | `lcdm` (live CDM data) |
| `datetime` | Timestamp in `MMDDHHmm` format | `06151533` (June 15, 15:33Z) |
| `seq` | Sequence number within broadcast cycle | `01` |
| `data` | Data direction | `arr` (arrivals) or `dep` (departures) |
| `filter` | Filter status | `unfilt` (unfiltered) or filter identifier |

All file name components are **lowercase** [5, p.9-10].

Example: `bos__.lcdm.06151533.01.arr.unfilt` — BOS airport, live CDM, June 15 at 15:33Z, sequence 01, arrivals, unfiltered.

#### 4.4 ADL Encryption and Compression

- **GZIP compression** is required for all ADL files due to file size [5, p.10]
- **Blowfish encryption** is required for ADL files distributed to external CDM clients, per Memorandum of Understanding (MOU) requirements [5, p.10]
- Internal TFMS clients (FSM workstations at ATCSCC and TMUs) receive unencrypted data

#### 4.5 ADL Data Blocks

The ADL file contains **19 data block types** that organize flight and program information into logical groups. Each block begins with a block header identifying the block type and record count. The full block-by-block specification is detailed in Part V, Section 29 of this document.

#### 4.6 ADL Version Differences (v13.3 vs v14.1)

Two ADL file specification versions are referenced in this document:

| Feature | v13.3 [6] | v14.1 [5] |
|---------|-----------|-----------|
| **Metering prefix (M)** | Not present | Added for TBFM STD metering times |
| **TBFM fields** | Not included | TBFM_STD, TBFM_STA, metering status fields added |
| **Flight UID** | Present | Present with expanded usage |
| **Block types** | 19 types | 19 types with expanded field definitions |
| **Core structure** | Identical | Backward-compatible with v13.3 |

---

## Part II: Ground Delay Programs

### 5. GDP Core Algorithm

A Ground Delay Program (GDP) is the primary TFMS mechanism for distributing arrival delay across flights bound for a capacity-constrained airport. Rather than stopping all traffic (as a Ground Stop does), a GDP assigns each affected flight an Expect Departure Clearance Time (EDCT) that delays its departure to match the reduced acceptance rate at the destination [1, Ch.8, p.293].

#### 5.1 Ration By Schedule (RBS)

The core algorithm underlying all GDP operations is **Ration By Schedule (RBS)**. RBS allocates arrival slots at the constrained airport based on the original schedule order of flights [1, App C]:

1. **Generate arrival slots** based on the declared Airport Acceptance Rate (AAR) for each time period (typically 15-minute intervals)
2. **Sort eligible flights** by their Original Estimated Time of Arrival (OETA)
3. **Assign each flight** to the earliest available slot at or after its OETA
4. **Compute EDCT** by subtracting the estimated time enroute from the assigned CTA (slot time)

The enhanced version, **RBS++**, adds automatic compression after the initial slot assignment to fill gaps created by exempt flights, cancellations, and flights that do not need their full delay allocation [1, App C].

#### 5.2 GDP-DAS

GDP-DAS is described as "the default Program Type for airports. This program runs the Ration By Schedule Algorithm plus Compression." [1, Ch.3, p.96]

In DAS mode, pop-up flights — those that appear in the ADL after the GDP has been issued — receive a **DAS (Delay Assignment) delay**. This is a fixed delay value computed based on the current demand-capacity situation at the time the pop-up is detected [1, App A, p.519]. DAS delay ensures that late-appearing flights do not receive preferential treatment over flights that were known at GDP issuance.

#### 5.3 GDP-GAAP

GAAP (Ground Allocated Arrival Period) mode takes a different approach to unknown demand. Instead of assigning delay to pop-ups after the fact, GAAP **generates unassigned slots** in the program to reserve capacity for anticipated future demand [1, Ch.11, p.351-352].

As flights become known (flight plans filed, CDM messages received), TFMS assigns them to available unassigned slots. If no unassigned slot is available, the flight receives DAS delay. The key characteristic of GAAP is stated in the FSM documentation: "The DAS delay time also differs in that it is one set value for all flights. No flight at a GAAP airport receives a delay longer than the maximum delay limit entered by the user." [1, Ch.11, p.351-352]

#### 5.4 GDP-UDP

The Unified Delay Program (UDP) combines the strengths of DAS-GDP and GAAP-GDP approaches [2, p.5-12]:

| UDP Feature | Description |
|-------------|-------------|
| **Reserved Slots** | Pre-allocated slots for anticipated pop-up demand, providing more realistic initial delay estimates |
| **Target Delay Multiplier** | Multiplied by the average delay in each 15-minute ETA bin to determine pop-up delay. Example: if average delay in a bin is 20 minutes and the multiplier is 1.5, a pop-up receives 30 minutes of delay [1, Ch.3, p.107-108] |
| **Delay Limit** | Maximum delay any single flight can receive (default: 180 minutes) |
| **Earliest R-Slot** | Prevents reserved slots from being placed too close to the current time, where they would be unusable |

UDP was introduced to address a fundamental limitation of DAS-GDP (where pop-ups often receive much less delay than flights known at issuance) and GAAP-GDP (where unassigned slots can create artificially optimistic delay pictures).

#### 5.5 Compression

After the initial RBS slot assignment, automatic compression runs to optimize slot utilization [1, Ch.8, p.353]:

- Compression attempts to move flights forward to fill any open slots between their current slot and their earliest possible arrival time
- Same-airline preference: the algorithm first tries to move a flight from the same airline into an open slot, then considers other airlines [1, Ch.16, p.409]
- The Analysis Report generated after modeling contains two sections: **RBS results** and **Compression results**, including bridge-only carrier statistics [1, Ch.8, p.353]

#### 5.6 Algorithm Summary

```
                     ┌─────────────┐
   AAR/15-min ──────→│ Generate    │──→ Arrival Slots
                     │ Slots       │
                     └─────────────┘
                           │
   Eligible Flights ──────→│
   (sorted by OETA)        ▼
                     ┌─────────────┐
                     │ RBS Assign  │──→ Initial Slot Assignments
                     │ (by OETA    │    (CTA, CTD computed)
                     │  order)     │
                     └─────────────┘
                           │
                           ▼
                     ┌─────────────┐
                     │ Auto        │──→ Compressed Assignments
                     │ Compression │    (gaps filled, delays reduced)
                     └─────────────┘
                           │
                           ▼
                     ┌─────────────┐
                     │ EDCT =      │──→ Final EDCTs
                     │ CTA - ETE   │    (delivered via FADT)
                     └─────────────┘
```

---

### 6. GDP Parameters

The GDP Parameters tab in FSM defines the core operational characteristics of a program. Each parameter directly influences the RBS++ algorithm's slot generation and flight assignment behavior.

#### 6.1 Core Parameters

The Parameters tab contains the following settings [1, Ch.8, p.293-312]:

| Parameter | Format | Description |
|-----------|--------|-------------|
| **Start Time** | `ddhhmm` (UTC) | Time when the GDP begins accepting constrained arrivals |
| **End Time** | `ddhhmm` (UTC) | Time when the GDP stops generating constrained slots; flights with CTAs after this time are uncontrolled |
| **Program Rate** | Flights per hour | Overall acceptance rate; editable per 15-minute period via the "Edit 15" function |
| **Pop-Up Factor** | Per hour | Expected additional flights per hour not yet in the ADL. Values less than 10 may include decimals; values greater than 10 are whole numbers only [1, Ch.3, p.105-106] |
| **Program Cancellation Time** | `ddhhmm` (UTC) | Models the unrecoverable delay that would result if the program were cancelled at a different time than planned [1, Ch.3, p.115] |

#### 6.2 UDP-Specific Parameters

When the program type is set to GDP-UDP, additional parameters become available [2, p.7-9]:

| Parameter | Range | Description |
|-----------|-------|-------------|
| **Reserve Rate** | Per hour | Number of reserved slots for pop-up demand, informed by Historical Pop-Up demand data |
| **Target Delay Multiplier** | 1.0 - 9.9 | Multiplied by average delay in each 15-minute ETA bin to determine pop-up delay. Always 1.0 for DAS mode; N/A for GAAP mode [1, Ch.3, p.107] |
| **Delay Limit** | Minutes | Maximum delay any flight receives. Default: 180 minutes. Applies to both GAAP and UDP modes [1, Ch.11, p.351] |
| **Earliest R-Slot** | Time | Earliest allowed time for reserved slots. Range: 0 to before program end time [2, p.9] |

#### 6.3 General Options

The General Options section provides additional controls that modify program behavior [1, Ch.3, p.106-108]:

| Option | Description |
|--------|-------------|
| **Slot Hold Override** | Override airline slot hold status for specific carriers. Available for RBS++ and Compression program types only. Allows the user to treat specific carrier flights as slot-held or not slot-held regardless of airline CDM settings. |
| **Impact Elements** | Specify up to **5 additional airports** whose demand will be included in the impact analysis. Used when a GDP at one airport may affect traffic at nearby airports. |
| **Include Only: Arrival Fix** | Filter the program to affect only flights arriving via a specific fix or set of fixes. Flights arriving via other fixes are exempt. |
| **Include Only: Aircraft Type** | Filter by aircraft type designator. Only matching aircraft types receive EDCTs. |
| **Include Only: Carrier** | Filter by airline/carrier code. Only matching carriers are included in the program. |

#### 6.4 Historical Pop-Up Demand

To support accurate reserve slot planning (especially for UDP), FSM provides historical pop-up demand analysis at three confidence levels [1, Ch.3, p.103]:

| Confidence Level | Description |
|------------------|-------------|
| **High** | Conservative estimate — uses historical data from days with similar traffic patterns and weather. Most likely to reflect actual pop-up demand. |
| **Medium** | Moderate estimate — broader historical sample with some adjustment for current conditions. |
| **Low** | Aggressive estimate — includes outlier days and less-filtered historical data. May overestimate pop-ups. |

Each confidence level produces per-hour predictions that can be used to set the Reserve Rate and Pop-Up Factor parameters for UDP programs.

#### 6.5 Edit 15 Function

The Program Rate parameter sets an hourly rate, but actual demand varies significantly within each hour. The **Edit 15** function allows the user to modify the acceptance rate for each individual 15-minute period within the program window [1, Ch.8, p.293-312]. This is critical for airports where capacity changes mid-hour due to runway configuration changes, weather movement, or other factors.

---

### 7. GDP Scope

Scope defines which flights are included in (controlled by) a GDP and which are exempt. TFMS provides two scope modes and multiple exemption mechanisms to give traffic managers precise control over program applicability.

#### 7.1 Tier-Based Scope

Tier-based scope groups departure ARTCCs into tiers, typically organized by distance from the destination airport [1, Ch.3, p.109-113]:

- **Tier 1**: Closest departure centers (shortest flights)
- **Tier 2**: Intermediate departure centers
- **Tier 3**: Farthest departure centers (longest flights)
- Additional tiers as defined by the user or facility-specific configuration

Selection is made on the **Scope tab** via center tier group checkboxes. The user selects which tiers are included (controlled) and which are exempt.

**Tier-based scope limits:**
- Maximum **24 exempt airports**
- Maximum **16 non-exempt airports** (specific airports within an exempt tier that should still be controlled)

#### 7.2 Distance-Based Scope

Distance-based scope uses a nautical mile radius from the destination airport to determine inclusion [1, Ch.3, p.109-113]:

- **Default distance**: 199 nautical miles
- Flights departing from airports within the specified radius are **exempt** (too close to benefit from ground delay)
- Flights departing from airports beyond the radius are **controlled**

**Distance-based scope limits:**
- Maximum **36 exempt centers**
- Maximum **16 non-exempt centers**
- Maximum **24 exempt airports**
- Maximum **16 non-exempt airports**

#### 7.3 Exemption Types

TFMS supports a comprehensive set of exemption types that determine why a flight is excluded from a GDP. Each exempt flight carries one of these codes [1, App A, p.519-520]:

| Exemption Type | Code | Description |
|----------------|------|-------------|
| **By Departing Center** | Center-based | Flight's departure center is in an exempt tier or beyond the distance threshold |
| **By Distance** | Distance | Flight is within the exempt distance radius from the destination |
| **By Departing Airport** | Airport | Flight's departure airport is explicitly listed in the exempt airports |
| **By Specific Flight** | Flight | Individual flight exempted by ACID (call sign) |
| **By Departure Status** | Status | Flight has ETD prefix A (Actual) or E (Estimated) — already active/airborne |
| **By Departure Time** | Time | Revised EDCT falls before Current Time + Now_Plus offset — too late to control |
| **By Aircraft Type** | Type | Flight's aircraft type excluded by the Include Only: Aircraft Type filter |
| **By Arrival Fix** | Fix | Flight's arrival fix excluded by the Include Only: Arrival Fix filter |
| **By Arrival Time** | ArrTime | Flight's ETA falls outside the program's controlled arrival time window |
| **By Departure Time (GS)** | GS-Time | Ground Stop-specific: flight's departure time qualifies it for exemption |
| **Excluded and Exempted** | Both | Flight met both exclusion criteria and exemption criteria simultaneously |

#### 7.4 Ground Stop Scope Default

Ground Stops use a specific default scope setting: **"Exempt Active Flights Only (By Status)"** [1, Ch.3, p.111]. This means that by default, only flights already airborne (ETD prefix A or E) are exempt from a Ground Stop — all other flights within scope are held.

---

### 8. GDP Modeling Options

FSM provides several modeling capabilities that allow traffic managers to evaluate multiple scenarios before committing to a program. These tools support the collaborative decision-making process by quantifying the impact of different parameter choices.

#### 8.1 Power Run

Power Run enables multi-scenario comparison by automatically iterating a program across a range of parameter values and presenting the results side by side [1, Ch.3, p.114-116]:

| Program Type | Available Power Run Options |
|--------------|----------------------------|
| **GDP-DAS / GDP-GAAP** | Distance; Data Time; Data Time & Distance; Center Group; Center Group & Data Time |
| **AFP-DAS / AFP-GAAP** | Percent Demand; Percent Capacity; Data Time |
| **Ground Stop** | Center Group; Time Period; Center Group & Time Period |
| **Compress Flights** | Start Time |
| **Blanket** | Minutes of Adjustment |

**Power Run Distance defaults** [1, Ch.3, p.114]:
- Start: 199 nautical miles
- End: 2,600 nautical miles
- Step: 200 nautical miles

This generates scenarios at 199nm, 399nm, 599nm, 799nm, and so on up to 2,599nm, allowing the user to see how expanding or contracting the scope distance affects total delay, average delay, maximum delay, and equity metrics.

#### 8.2 Data Graph Statistics

The Data Graph displays statistical summaries for each modeled scenario. These metrics are the primary quantitative basis for GDP decision-making [1, Ch.3, p.128-129]:

| Statistic | Abbreviation | Description |
|-----------|--------------|-------------|
| **Total # Flights** | Total # Flts | Total flights in the program window, including cancelled and exempt flights |
| **Affected Flights** | Affected Flts | Non-exempt, non-cancelled flights — those that would actually receive EDCTs |
| **Total Delay** | Total Delay | Sum of all delay minutes across all affected flights |
| **Maximum Delay** | Max Delay | Highest delay assigned to any single flight in the program |
| **Average Delay** | Avg Delay | Total delay divided by affected flights |
| **Maximum Airborne Hold** | Max Air Hold | Maximum holding delay assigned to any airborne flight |
| **Average Airborne Hold** | Avg Air Hold | Average holding delay across airborne flights with holding assignments |
| **Stack** | Stack | Number of flights whose CTAs are pushed past the program end time |
| **Unrecoverable Delay** | Unrec Delay | Delay minutes that cannot be recovered if the program were cancelled at the specified Program Cancellation Time |
| **Percent Unrecoverable** | % Unrec | Unrecoverable delay as a percentage of total delay |
| **Delay Variance** | Delay Var | Standard deviation of per-carrier average delays — measures delay distribution fairness |
| **Equity Metric Airlines** | EMA | Measures how equitably delay is distributed across airlines. Scale: 1 = perfect equity; 2-8 = good; 9-16 = significant inequity; >16 = poor equity |
| **Equity Metric Flights** | EMF | Measures how equitably delay is distributed across individual flights. Same scale as EMA. |

#### 8.3 Interpreting Equity Metrics

EMA and EMF are critical decision-support metrics:

- **EMA** (Equity Metric Airlines): Compares each airline's average delay against the program-wide average. An EMA of 1.0 means every airline experiences exactly the same average delay. Higher values indicate that some airlines bear disproportionately more delay than others — often driven by hub/spoke patterns where one airline dominates a constrained airport.

- **EMF** (Equity Metric Flights): Applies the same equity calculation at the individual flight level. An EMF of 1.0 means every flight experiences the same delay. Higher values indicate wide dispersion in individual flight delays.

Traffic managers use these metrics to identify programs where scope adjustments, distance changes, or rate modifications could improve equity without significantly increasing total delay.

---

### 9. GDP Lifecycle

A GDP follows a structured lifecycle from initial modeling through cancellation. Each phase has specific procedures, validation steps, and system interactions.

#### 9.1 Modeling Phase

The modeling workflow [1, Ch.8, p.293-312]:

1. **Open Data Set** in Monitored Live mode — ensure current ADL data is loaded
2. **Click GDT Mode** — transitions FSM from monitoring to modeling workspace
3. **Select Program Type** — GDP-DAS, GDP-GAAP, GDP-UDP, or other type from the Program Type selector
4. **Configure Parameters Tab** — set Start/End times, Program Rate, Pop-Up Factor, and type-specific parameters
5. **Configure Scope Tab** — select tier-based or distance-based scope, set exemptions
6. **Configure Modeling Options Tab** — set Power Run parameters, analysis preferences
7. **Click Model** — TFMS-Core runs the RBS++ algorithm; all GDT components update with results (Data Graph, Bar Graph, Time Line, Map, Flight List, Demand by Center); the red "unmodeled" border disappears
8. **Optionally click Reload** — fetches the latest ADL data and reapplies the model with updated flight information
9. **Review outputs** — examine Data Graph statistics, Bar Graph demand visualization, Time Line program schedule, Map geographic distribution, Flight List per-flight assignments, and Demand by Center breakdown

#### 9.2 Issuance Phase

Once the model results are satisfactory [1, Ch.11, p.343-354]:

1. **Click Run Proposed** (for coordination with other facilities) or **Run Actual** (for immediate activation)
2. The **Coversheet** window opens, displaying all program parameters in a review format
3. **Review Program Parameters** — a checkmark is required to confirm review. This is a mandatory step — the Send button will not activate without parameter review confirmation.
4. **Complete Advisory/Causal Factors** — enter the advisory text describing the reason for the program (weather, equipment, staffing, etc.) and select causal factor codes. A checkmark is required.
5. **Click Send Actual/Proposed** — initiates program distribution
6. **Autosend processing** — the system automatically distributes:
   - **FADT file** to the hub site (machine-readable slot assignments for centers and carriers)
   - **Coversheet XML** to the National Traffic Management Log (NTML)
   - **Advisory message** via TFMS E-mail system to all configured recipients
7. **Three reports generated** upon issuance:
   - **FADT** — detailed slot assignments (see Section 13)
   - **Analysis Report** — RBS and Compression results with equity metrics
   - **Carrier Statistics** — per-carrier delay breakdown and equity analysis

#### 9.3 Staleness Warning

If the **Send** button is clicked more than **15 minutes** after the last Model run, FSM displays a staleness warning recommending that the user **Reload** the ADL data and re-model before sending [1, Ch.11, p.347]. This safeguard prevents program issuance based on outdated demand data that may have changed significantly since the model was run.

#### 9.4 Coversheet File Naming

Coversheet files follow this naming convention [1, Ch.11, p.348]:

```
fsmc.<Airport>.<DDHHMMss>.<Rate>.<Type>.<Scope>
```

| Component | Description | Example |
|-----------|-------------|---------|
| `fsmc` | Fixed prefix (FSM Coversheet) | `fsmc` |
| `Airport` | Destination airport ICAO/FAA code | `JFK` |
| `DDHHMMss` | Date-time of issuance (day, hour, minute, second) | `15143022` |
| `Rate` | Program rate | `36` |
| `Type` | Program type code | `DAS` |
| `Scope` | Scope description | `T2` (tier 2) |

Example: `fsmc.JFK.15143022.36.DAS.T2`

#### 9.5 Monitoring Phase

After issuance, the traffic manager monitors program execution by:

- Tracking **CTD compliance** — are flights departing within the compliance window of their EDCT?
- Monitoring **CTA compliance** — are flights arriving within the compliance window of their slot?
- Watching for **pop-up flights** that need DAS delay or GAAP slot assignment
- Reviewing **substitution activity** (SCS/ECR) for slot movement patterns
- Checking **compression opportunities** as cancellations create open slots
- Using **RT FSA reports** (updated every 5 minutes) for execution metrics [3, p.1]

#### 9.6 Modification Phase

Active programs can be modified through several mechanisms (detailed in Sections 11-12):

- **Revision/Extension** — change rate, scope, times, or program type
- **Compression** — fill gaps from cancellations
- **Blanket** — uniform delay adjustment
- **Substitution** — swap individual flights between slots (SCS/ECR)

#### 9.7 Purge Phase

A GDP is terminated by issuing a **Purge**, which:

- Cancels the program
- Releases all EDCTs (CTD values cleared)
- Returns all flights to uncontrolled status
- Generates a FADT file communicating the release
- Posts an advisory message to NTML
- Flights retain their OCTD/OCTA values for post-event analysis

---

### 10. GDP Slot Assignment & EDCT Delivery

The slot assignment process is the mechanism by which abstract program parameters (rate, scope, times) are translated into specific, actionable departure times for individual flights. Understanding slot identifiers, control types, and alarm flags is essential for interpreting TMI data.

#### 10.1 ASLOT Format

Each arrival slot in a GDP or AFP is identified by a unique **ASLOT** string with the following format [5, p.36]:

```
element.slottimeL
```

Where:
- `element` = the NAS element identifier (e.g., airport code)
- `slottime` = the slot time in `DDHHMM` format (UTC)
- `L` = a letter suffix indicating the slot sequence and type

**Letter suffix meanings** [5, p.36]:

| Suffix | Meaning |
|--------|---------|
| **A, B, C...** | Normal sequence within the same minute. A = first slot in that minute, B = second, etc. |
| **P, Q...** | RCTL (re-control) flights or EDCT UPDATE actions |
| **Z** | Pop-up flight awaiting actual slot assignment (temporary placeholder) |

Example: `JFK.091530A` = JFK airport, slot at 09th day 15:30Z, first slot in that minute.
Example: `JFK.091530B` = JFK airport, slot at 09th day 15:30Z, second slot in that minute.
Example: `JFK.091545Z` = JFK airport, pop-up flight with preliminary slot at 09th day 15:45Z, awaiting final assignment.

#### 10.2 Control Types (CTL_TYPE)

Every controlled flight carries a CTL_TYPE code indicating how it was assigned to the program. There are **17 control type values** [5, p.35]:

| Code | Full Name | Applicable Program Types | Description |
|------|-----------|-------------------------|-------------|
| **GDP** | GDP-Initial/Revision | GDP | Flight controlled by initial GDP issuance or GDP revision |
| **GS** | Ground Stop | GDP | Flight held by a Ground Stop issued within a GDP |
| **AFP** | AFP-Initial/Revision | AFP | Flight controlled by initial AFP issuance or AFP revision |
| **COMP** | Compression | GDP, AFP | Flight moved forward by compression algorithm |
| **DAS** | Delay Assignment | GDP, AFP, CTOP | Pop-up flight assigned delay after program issuance |
| **GAAP** | Unassigned Slot | GDP, AFP | Unassigned slot generated for anticipated pop-up demand |
| **BLKT** | Blanket | GDP | Flight delay adjusted by blanket action |
| **SUB** | Airline Substitution | GDP, AFP, CTOP | Airline-initiated flight substitution into a slot |
| **SCS** | Slot Credit Substitution | GDP, AFP | NAS user (airline) swaps flight into available slot credit |
| **ECR** | EDCT Change Request | GDP, AFP | FAA user modifies flight's EDCT assignment |
| **RCTL** | Re-control | AFP, CTOP | Flight re-entering controlled status after previous release |
| **ABRG** | Adaptive Bridge | GDP, AFP | Flight moved by Adaptive Compression background process |
| **SBRG** | SCS/ECR Bridge | GDP, AFP | Bridge slot created during SCS or ECR operation |
| **UBRG** | UDP Bridge | GDP, AFP | Bridge slot created for UDP pop-up processing |
| **ADPT** | Adaptive Compression | GDP, AFP | Flight assignment modified by Adaptive Compression |
| **UPD** | EDCT Update | GDP, AFP, CTOP | FAA user manually updated flight's EDCT |
| **CTOP** | CTOP-Initial/Revision | CTOP | Flight controlled by Collaborative Trajectory Options Program |

#### 10.3 Alarm Flags (CTL_ALM)

The CTL_ALM field is a **bitmask** that flags compliance and anomaly conditions for controlled flights [5, p.37]:

| Bit Position | Hex Value | Alarm Name | Description |
|-------------|-----------|------------|-------------|
| 0 | `0x0` | **NO_ALARM** | No alarm condition — flight is compliant or not yet evaluated |
| 1 | `0x1` | **CTA_COMPLIANCE** | Flight landed outside the +/-5 minute CTA compliance window |
| 2 | `0x2` | **CTD_COMPLIANCE** | Flight departed outside the +/-5 minute CTD compliance window |
| 3 | `0x4` | **ETE_VALUE** | Actual vs. controlled Estimated Time Enroute differs by more than 15 minutes |
| 4 | `0x8` | **SPURIOUS_FLT** | Unscheduled flight that was controlled, then cancelled by CDM |
| 5 | `0x10` | **CANCELLED_FLEW** | Flight was cancelled in the system but subsequently became active (flew anyway) |

Since CTL_ALM is a bitmask, multiple alarm conditions can be set simultaneously. For example, a value of `0x3` (binary `011`) indicates both CTA_COMPLIANCE and CTD_COMPLIANCE alarms are active — the flight missed both its departure and arrival windows.

#### 10.4 EDCT Delivery via FADT

The FADT (Fuel Advisory Delay Table) is the machine-readable file used to communicate slot assignments to centers and carriers. FADT delivery is the operational bridge between TFMS modeling and real-world EDCT compliance — towers and airline dispatch receive FADT data and enforce the assigned departure times.

FADT distribution uses the **Autosend** server, which transmits the file to the designated hub site immediately upon program issuance [1, Ch.11, p.346]. The detailed FADT format is covered in Section 13.

---

### 11. GDP Modifications

Active GDP programs frequently require modification as conditions evolve — weather improves or deteriorates, traffic patterns shift, cancellations open slots, and new flights appear. TFMS provides four primary modification mechanisms: Compression, Revision/Extension, Blanket, and Adaptive Compression.

#### 11.1 Compression (Compress Flights)

Compression is the most common modification, used to recover delay from cancelled and no-show flights [1, Ch.16, p.409-418]:

**Procedure:**

1. **Turn SUBS OFF** — this is **mandatory** before running compression. Substitutions must be disabled to prevent concurrent slot movement during the compression calculation.
2. **Select "Compress Flights"** as the program type
3. **Optionally check "Compress to Last CTA"** — when enabled, compression includes flights whose CTAs have been pushed past the program end time (the "stack")
4. **Click Model** — compression algorithm runs and results appear in all GDT components
5. **Review results** — verify that total delay and average delay are reduced
6. **Click Run Actual/Proposed** — issue the compression

**Algorithm behavior** [1, Ch.16, p.409]:

> "Compression attempts to fill all available slots with flights that, although delayed from their OETA or OENTRY, can still arrive at the available slot time."

The compression algorithm uses **same-airline preference**: it first tries to move a flight from the same airline into an open slot before considering flights from other airlines [1, Ch.16, p.409].

**Key guarantee** [1, Ch.17, p.421]:

> "Compression can reduce, but cannot increase, both the total delay and average delay of a GDP."

This guarantee means compression is always a non-negative operation from the traffic manager's perspective — it will never make a program worse.

#### 11.2 Compress Slots (GAAP Only)

Compress Slots is a distinct operation available only for GAAP-mode programs [1, Ch.16, p.415-417]:

- Adjusts the positions of **unassigned slots** without changing any flight's EDCT
- Used to redistribute reserved capacity across the program timeline
- **No advisory is sent** for Compress Slots operations (unlike Compress Flights, which generates a full advisory)
- Does not affect any flight currently assigned to a slot

#### 11.3 Adaptive Compression

Adaptive Compression is an automated background process that runs continuously at the TFMS Hub site [1, Ch.16, p.409-410]:

- Monitors active programs for open slots that are in danger of going unused
- When an open slot is identified, Adaptive Compression attempts to move a delayed flight forward into that slot
- Controlled by the **ADPT ON/OFF** flag — traffic managers can enable or disable it per program
- **Military and GA bridging is disabled by default** [1, Ch.12, p.366] — meaning Adaptive Compression will not move military or general aviation flights unless explicitly enabled
- Generates ABRG (Adaptive Bridge) control type entries in the ADL for moved flights

#### 11.4 Revision and Extension

When program parameters need to change (rate, scope, times, program type), a Revision is issued [1, Ch.15, p.395-408]:

**Procedure:**

1. **Request latest ADL** — ensure current data is loaded
2. **Click "Load Actual Parameters"** — imports the currently active program's settings as the baseline
3. **Modify parameters** as needed:
   - Change rate (increase or decrease acceptance rate)
   - Adjust scope (expand or contract distance/tier)
   - Extend or shorten program time window
   - Change program type (e.g., GAAP to DAS)
4. **Check purge options** if applicable:
   - "Purge Flights Before Revision Start" — releases EDCTs for flights with CTAs before the new start time
   - "Purge Flights After Revision End" — releases EDCTs for flights with CTAs after the new end time
5. **Turn SUBS OFF** before issuing (mandatory)
6. **Click Model**, then **Run Actual/Proposed**, then complete Coversheet and **Send**

**Special case — GAAP to DAS revision** [1, Ch.15, p.406]: When revising a GAAP program to DAS mode, all existing unassigned slots are removed from the program. Flights previously assigned to unassigned slots receive new DAS-based slot assignments.

#### 11.5 Blanket Adjustment

Blanket provides a uniform delay adjustment applied to all flights in a program [1, Ch.18, p.428-434]:

| Value | Effect |
|-------|--------|
| **Positive number** (e.g., +15) | Adds the specified minutes of delay to all controlled flights |
| **Negative number** (e.g., -15) | Removes the specified minutes of delay from all controlled flights |
| **-999** | Special value: releases **all** delay for all controlled flights |

**Blanket constraints:**

- Available for **airport-based programs only** — not for Ground Stops or FCA-based programs
- Uses **IGTA-based slot time** for determining which flights are included
- **Ignores unassigned slots** in GAAP mode — only affects flights with actual assignments

---

### 12. GDP Substitutions & SCS

Substitutions and EDCT Change Requests (ECRs) allow individual flight-level modifications within an active program. These tools are essential for operational flexibility — airlines need to swap flights between slots, and FAA users need to adjust individual EDCTs in response to changing circumstances.

#### 12.1 Access Levels

Different users have different substitution capabilities [1, Ch.14, p.379-394]:

| User Type | Available Operations |
|-----------|---------------------|
| **NAS Users** (Airlines via CDM) | SCS (Slot Credit Substitution) only |
| **Field Users** (TMU at centers/TRACONs) | SCS + Unlimited ECR |
| **ATCSCC Users** (Command Center) | SCS + Limited ECR + Unlimited ECR + Manual ECR |

#### 12.2 ECR Process

The EDCT Change Request workflow [1, Ch.14, p.379-394]:

1. **Enter ACID** — specify the flight callsign to be modified
2. **Get Flight Data** — retrieve the flight's current slot assignment, EDCT, and program membership
3. **Enter Earliest EDCT** — specify the earliest time the flight can depart (based on operational constraints)
4. **Apply Model** — system computes available options based on the Earliest EDCT and current program state
5. **Select Option** — choose from available slot assignments (Limited, Unlimited, or Manual depending on access level)
6. **Send** — apply the new EDCT and update the program

#### 12.3 Limited ECR Option

The Limited option assigns the flight to a **pseudo-slot (PSLOT)** within the **Limited CTA Range** [1, Ch.14, p.391]:

- The system searches for the first available free PSLOT within the Limited CTA Range
- If no free PSLOT is available, the flight is placed in the minute that has the **fewest airborne-holding flights**
- Limited ECR constrains the new CTA to a relatively narrow window to minimize disruption to other flights

#### 12.4 Unlimited ECR Option

The Unlimited option provides broader flexibility [1, Ch.14, p.391]:

> "Will always find a free PSLOT, even though that may cause the flight to incur substantial additional delay."

- Searches for the first available free PSLOT in the **Unlimited CTA Range**
- Because it searches a larger time range, it **always** finds an available PSLOT
- The tradeoff is that the flight may receive significantly more delay than under the Limited option

#### 12.5 ECR Computation Details

**CTA Range minimum**: Both Limited and Unlimited CTA Ranges have a minimum width of **30 minutes** [1, Ch.14, p.389].

**PSLOT computation basis**: ECR uses the **AAR (Airport Acceptance Rate)** — not the Program Rate — for computing PSLOT availability [1, Ch.14, p.391]. This means the actual airport capacity, which may differ from the GDP's program rate, governs slot availability for ECR operations.

**ERTA formula**: The Earliest Runway Time of Arrival is computed as [1, Ch.14, p.391]:

```
ERTA = Earliest EDCT + (ETA - ETD)
```

This formula derives the earliest possible arrival time by adding the flight's estimated time enroute to the earliest acceptable departure time.

#### 12.6 Substitution Control Flags

Several flags control substitution behavior within an active program [1, Ch.12, p.366]:

| Flag | Values | Description |
|------|--------|-------------|
| **SUBS** | ON / OFF | Master switch for all substitution operations. Must be OFF during compression and revision issuance. |
| **SCS** | ON / OFF | Enables or disables Slot Credit Substitutions (airline-initiated swaps) |
| **ADPT** | ON / OFF | Enables or disables Adaptive Compression (automatic background slot filling) |
| **BRIDGING** | OFF per user type | Controls whether specific user categories (airline, GA, military) can bridge across slots |

**Default states**: "Military and GA bridging is disabled by default" [1, Ch.12, p.366]. This means that by default, military and general aviation flights cannot be moved by Adaptive Compression or used to fill open slots through bridging operations. This default can be overridden by the traffic manager on a per-program basis.

---

### 13. FADT File Format & Contents

The FADT (Fuel Advisory Delay Table) is the machine-readable file that bridges the gap between TFMS modeling and real-world EDCT enforcement. When a GDP, GS, AFP, or related action is issued, the FADT file delivers specific slot assignments — departure times, arrival slots, delay values, and control metadata — to the centers and carriers that need to implement them.

#### 13.1 FADT Generation Triggers

A FADT file is generated for the following program actions [5, FADT_TIMES block]:

| Action | FADT Type Code | Description |
|--------|---------------|-------------|
| **GDP-Initial** | `GDP` | New GDP issuance — full slot assignments for all controlled flights |
| **GDP-Revision** | `GDP` | Modified GDP — updated slot assignments reflecting parameter changes |
| **Compression** | `COMP` | Compress Flights operation — updated assignments for moved flights |
| **Blanket** | `BLKT` | Blanket delay adjustment — updated CTD/CTA for all affected flights |
| **Ground Stop** | `GS` | Ground Stop issuance or modification |
| **Purge** | (GDP) | Program cancellation — releases all controlled times |
| **AFP-Initial** | `AFP` | New AFP issuance |
| **AFP-Revision** | `AFP` | Modified AFP |
| **AFP-Compression** | `COMP` | AFP compression operation |
| **CTOP-Initial** | `CTOP` | New CTOP issuance |
| **CTOP-Revision** | `CTOP` | Modified CTOP |

#### 13.2 FADT Distribution

The FADT file is distributed via the **Autosend server** to the designated hub site immediately upon program issuance [1, Ch.11, p.346]. From the hub site, the data flows to:

- **Air Route Traffic Control Centers (ARTCCs)** — receive slot assignments grouped by departure center for EDCT coordination with towers
- **Airline Operations Centers (AOCs)** — receive slot assignments grouped by carrier for fleet management and crew scheduling
- **CDM participants** — receive the data via TFMDI for integration into airline decision-support tools

#### 13.3 FADT Sub-File Structure

The FADT contains multiple sub-files directed to different recipients [7]:

| Sub-File | Name | Organization | Purpose |
|----------|------|-------------- |---------|
| **B6 List** | By Center | Grouped by departure ARTCC | Enables each center to see all controlled flights departing from their airspace, ordered by EDCT. Centers use this to coordinate EDCT delivery with towers. |
| **B8 List** | By Carrier | Grouped by airline/carrier code | Enables each airline to see all their controlled flights across the NAS, ordered by EDCT. Airlines use this for fleet management and passenger rebooking. |
| **B9 List** | Combined | Combined format | Comprehensive view combining center and carrier perspectives |

#### 13.4 FADT Key Data Fields

Each flight record in the FADT contains the essential information needed for EDCT enforcement [7]:

| Field | Description |
|-------|-------------|
| **ACID** | Aircraft identifier (callsign) |
| **Origin** | Departure airport |
| **Destination** | Arrival airport |
| **ETD** | Current estimated departure time |
| **EDCT (CTD)** | Assigned departure clearance time — the time the flight is cleared to depart |
| **CTA** | Controlled arrival time — the assigned arrival slot at the destination |
| **Delay** | Minutes of delay assigned (CTA - BETA or CTA - OETA) |
| **Aircraft Type** | ICAO aircraft type designator |
| **Carrier** | Airline or operator code |
| **Departure Center** | ARTCC responsible for the departure airport |
| **Slot ID (ASLOT)** | Unique arrival slot identifier (e.g., `JFK.091530A`) |
| **Control Type** | CTL_TYPE code indicating how the flight was assigned (GDP, COMP, DAS, etc.) |
| **Exemption Status** | Whether the flight is exempt and the exemption reason code |

#### 13.5 FADT in the ADL Context

Within the ADL file structure, FADT-related data appears in the **FADT_TIMES** block, which records the timestamps of all FADT events for the program [5, p.27]:

- GDP events: `GDP`, `COMP`, `BLKT`, `GS`
- AFP events: `AFP`, `COMP`
- CTOP events: `CTOP`

Each FADT_TIMES entry records when the FADT was generated, the program action that triggered it, and the sequence number within the program's history. This provides a complete audit trail of all program actions and their corresponding EDCT distributions.

#### 13.6 FADT Timing and Staleness

FADT files represent a point-in-time snapshot of program assignments. Between FADT distributions, individual flight assignments may change due to:

- Adaptive Compression (ADPT) moving flights to open slots
- Airline substitutions (SCS) swapping flights between slots
- FAA ECR actions modifying individual EDCTs
- Cancellations freeing slots for subsequent reallocation

These inter-FADT changes are reflected in the ADL in real-time but may not be captured in the most recent FADT file. Centers and carriers typically receive the cumulative effect of all changes in the next FADT distribution (triggered by the next formal program action such as Compression or Revision).

> **Note:** The complete field-by-field FADT format specification is documented in Appendix C of this reference.

---

## Part III: Ground Stops

### 14. GS Core Algorithm

A Ground Stop (GS) is the most restrictive traffic management initiative available in TFMS. Unlike a GDP, which distributes delay across flights, a Ground Stop **halts all departures** to a constrained airport within the defined scope [1, Ch.7].

#### 14.1 Fundamental GS Equation

The core GS algorithm is remarkably simple compared to the GDP's RBS++ approach [1, Ch.7]:

```
ETD for held flight = GS End Time + 1 minute
```

All flights within scope that have not yet departed receive this ETD assignment. This means every held flight is estimated to depart one minute after the Ground Stop is expected to end. Unlike GDP slots, which are distributed across time based on acceptance rate, GS holds create a concentrated "wall" of demand at the end of the stop.

#### 14.2 Flight Categories During a GS

When a Ground Stop is active, flights fall into distinct categories [1, Ch.7]:

| Category | Treatment |
|----------|-----------|
| **Airborne flights** | Exempt by status — already departed, cannot be held on the ground |
| **Departed during GS** | Flights that pushed back before the GS was communicated — tracked but not recalled |
| **Held flights (within scope)** | Assigned ETD = GS End + 1 min; departures physically held by towers |
| **Flights outside scope** | Unaffected — continue normal operations |
| **Exempt flights** | Specifically exempted by the traffic manager (e.g., medevac, military priority) |

#### 14.3 GS vs GDP Algorithm Comparison

| Aspect | GDP Algorithm | GS Algorithm |
|--------|-------------- |-------------- |
| **Delay distribution** | Spread across flights proportionally by RBS++ | All delay concentrated at GS end |
| **Slot assignment** | Individual CTA/CTD per flight | Single ETD for all held flights |
| **Rate-based** | Yes — driven by AAR per 15-min interval | No — binary (0 or full capacity) |
| **Compression applicable** | Yes — fills gaps from cancellations | No — no slots to compress |
| **Pop-up handling** | DAS delay or GAAP slot assignment | Held with ETD = GS End + 1 min |

#### 14.4 Ground Stop to GDP Transition

A common operational pattern is to transition from a Ground Stop to a GDP as conditions improve [1, Ch.8]:

1. **GS active** — all departures held
2. **Conditions improving** — capacity returning but not yet at normal levels
3. **Issue GDP** — create a GDP with reduced rate to manage the surge of held traffic
4. **Purge GS** — release the Ground Stop; held flights now receive GDP slot assignments
5. **GDP manages flow** — delay is distributed across the released flights plus new demand

This transition prevents the "tidal wave" effect where all held flights depart simultaneously when a GS ends without flow management.

---

### 15. GS Parameters & Scope

#### 15.1 GS Parameters

Ground Stop parameters are configured on the GDT Parameters tab [1, Ch.7]:

| Parameter | Format | Description |
|-----------|--------|-------------|
| **Start Time** | `ddhhmm` (UTC) | When the Ground Stop takes effect |
| **End Time** | `ddhhmm` (UTC) | Expected end of the Ground Stop. All held flight ETDs are set to End + 1 min |
| **Reason** | Code | Causal factor: weather, equipment, security, volume, runway, other |
| **Scope** | Tier-based | Defines which departure centers/airports are affected |

#### 15.2 GS Scope

Ground Stops use **tier-based scope** with a specific default [1, Ch.3, p.111]:

- Default scope: **"Exempt Active Flights Only (By Status)"**
- This means only airborne flights (ETD prefix A or E) are automatically exempt
- All other flights within the selected tiers are held
- The traffic manager can add specific flight, airport, or center exemptions

#### 15.3 GS Power Run Options

Power Run for Ground Stops supports the following scenarios [1, Ch.3, p.114-116]:

| Power Run Type | Description |
|----------------|-------------|
| **Center Group** | Iterate across different tier group selections to see how expanding/contracting scope affects the number of held flights |
| **Time Period** | Iterate across different GS end times to evaluate the delay impact of shorter vs. longer stops |
| **Center Group & Time Period** | Combined iteration: scope × time for comprehensive scenario analysis |

---

### 16. GS Modeling Options

#### 16.1 GS Power Run

The Power Run modeling workflow for Ground Stops parallels the GDP workflow [1, Ch.8]:

1. **Configure base parameters** — set Start Time, End Time, Scope
2. **Select Power Run type** — Center Group, Time Period, or both
3. **Set iteration parameters**:
   - Center Group: select tier groups to iterate
   - Time Period: specify start/end/step for GS end time iteration
4. **Click Model** — system generates all scenarios simultaneously
5. **Review Data Graph** — compare affected flight counts, total delay, and max delay across scenarios
6. **Select preferred scenario** for issuance

#### 16.2 GS Statistics

The Data Graph for Ground Stops displays [1, Ch.3, p.128-129]:

| Statistic | GS Relevance |
|-----------|-------------|
| **Total # Flights** | All flights to the destination within the time window |
| **Affected Flights** | Non-exempt flights that will be held |
| **Total Delay** | Sum of all delay minutes (concentrated at GS end) |
| **Maximum Delay** | Longest individual flight delay — typically the flight with the earliest original ETD |
| **Stack** | Flights whose delayed ETAs exceed the program window — large for long Ground Stops |

---

### 17. GS Lifecycle

#### 17.1 Issuance

The GS issuance workflow [1, Ch.7-8]:

1. **Model** the Ground Stop with desired parameters and scope
2. **Review** the Data Graph statistics and affected flight list
3. **Click Run Actual** (or Run Proposed for coordination)
4. **Complete Coversheet** — review parameters (checkmark required), enter advisory text and causal factors (checkmark required)
5. **Click Send** — distributes:
   - FADT file to hub site (held flight list)
   - Coversheet to NTML
   - Advisory via TFMS E-mail

#### 17.2 Extension

When a Ground Stop must continue beyond its original end time [1, Ch.7-8]:

1. **Load current GS parameters** — Click "Load Actual Parameters"
2. **Modify End Time** — set new, later end time
3. **Model** the extension — system recalculates with new end time
4. **Review** impact — additional delay for held flights plus any new flights captured
5. **Issue** the extension — triggers updated FADT and advisory

Extending a GS is operationally equivalent to issuing a GS revision with a later end time.

#### 17.3 Scope Reduction

To narrow the scope of an active Ground Stop (releasing some departure areas while keeping others held) [1, Ch.7]:

1. **Load current parameters**
2. **Modify scope** — deselect tiers or centers to release
3. **Model and review** — verify that released flights are no longer in the held list
4. **Issue revision** — flights from released areas get their holds cancelled

#### 17.4 Purge (Cancellation)

Purging a Ground Stop [1, Ch.7-8]:

1. **Click Purge** on the active GS
2. **Confirm** the purge action
3. System releases all holds:
   - All held flight ETDs return to normal estimates
   - FADT file distributed with release information
   - Advisory posted announcing GS cancellation
4. **Consider transition to GDP** — if capacity is still reduced, issue a GDP before or simultaneous with the GS purge to manage the release flow

---

### 18. GS Coversheet & Advisory

#### 18.1 GS Coversheet Format

The Ground Stop coversheet follows the same general structure as the GDP coversheet [1, Ch.11]:

```
fsmc.<Airport>.<DDHHMMss>.<Type>.<Scope>
```

GS-specific coversheet content includes:
- **Affected airport** and reason code
- **Scope** definition (which tiers are held)
- **Start and end times**
- **Number of affected flights** at time of issuance
- **Advisory text** — free-form description of conditions and expected duration

#### 18.2 GS Advisory Content

GS advisories contain [8, p.12-25]:

| Field | Content |
|-------|---------|
| **Subject** | Ground Stop issuance/extension/cancellation |
| **Affected Airport** | ICAO/FAA code |
| **Reason** | Weather, equipment, security, etc. |
| **Scope** | Tier description |
| **Times** | Start, expected end |
| **Impact** | Number of affected flights, estimated total delay |
| **Causal Factors** | Standardized codes for the underlying cause |

---

### 19. GS Program Interactions

#### 19.1 GS Within a GDP

A Ground Stop can be issued within the context of an existing GDP [1, Ch.8]:

- When a GS is issued at an airport that already has an active GDP, the GS takes precedence
- GDP-controlled flights within GS scope switch from slot-based delay to full hold
- Flights outside GS scope but within GDP scope continue under GDP control
- When the GS is purged, flights revert to GDP control with updated slot assignments

#### 19.2 Multi-Program Precedence

When multiple TMIs affect the same flight, TFMS applies a precedence hierarchy [1, Ch.8]:

1. **Ground Stop** — highest priority; overrides GDP/AFP assignments
2. **GDP** — overrides AFP for airport-bound traffic
3. **AFP** — applies to airspace-constrained traffic not covered by GDP/GS
4. **CTOP** — Collaborative Trajectory Options Program

A flight subject to both a GS and a GDP receives the GS hold. When the GS is purged, the flight is re-evaluated against the active GDP and receives a slot assignment if applicable.

---

## Part IV: Shared Operations

### 20. Demand Visualization

FSM provides multiple demand visualization tools that support both monitoring and modeling of traffic flow.

#### 20.1 Bar Graph

The Bar Graph is the primary demand visualization component [1, Ch.3, p.128-143]:

- Displays demand (flights per time period) as vertical bars against capacity (AAR/ADR) as a horizontal line
- Time periods are typically **15-minute intervals**, configurable to 30 or 60 minutes
- **Stacked bars** show the composition of demand: scheduled, proposed, active, cancelled
- **Red shading** indicates intervals where demand exceeds capacity
- Mouse-over displays exact counts for each bar segment

Bar Graph display modes:

| Mode | Shows |
|------|-------|
| **Arrivals** | Arriving flights per interval vs. AAR |
| **Departures** | Departing flights per interval vs. ADR |
| **Both** | Split display showing arrivals and departures |

#### 20.2 Timeline

The Timeline component shows program duration and key events on a horizontal time axis [1, Ch.3, p.143-148]:

- **Program bars** — colored horizontal bars showing each TMI's start/end times
- **Stacked programs** — when multiple TMIs overlap, they stack vertically
- **Event markers** — compression events, revisions, extensions, and purges marked on the timeline
- **Current time indicator** — vertical line showing the current UTC time
- Clickable — selecting a program bar loads its parameters into the GDT workspace

#### 20.3 Demand by Center

The Demand by Center view breaks down demand by departure ARTCC [1, Ch.5]:

- Shows how many flights are departing from each center toward the constrained airport
- Useful for scope decisions — identifies which centers contribute the most demand
- Supports tier selection by visualizing the geographic distribution of demand
- Updated with each ADL refresh cycle

#### 20.4 Map Display

The Map component provides a geographic view of traffic flow [1, Ch.3]:

- Displays flight positions as aircraft icons colored by status (active, held, delayed, exempt)
- Airport markers show GS/GDP status colors
- Scope boundaries visualized as circles (distance-based) or highlighted centers (tier-based)
- FCA/FEA boundaries for AFP programs
- Configurable layers: weather overlay, sector boundaries, ARTCC boundaries, SUA
- Supports pan, zoom, and click-for-detail on individual flights

---

### 21. Flight List & Query Manager

#### 21.1 Flight List

The Flight List is the detailed per-flight view within the GDT workspace [1, Ch.6]:

| Column | Description |
|--------|-------------|
| **ACID** | Aircraft identifier (callsign) |
| **Origin** | Departure airport |
| **Destination** | Arrival airport (usually the constrained airport) |
| **A/C Type** | Aircraft type designator |
| **Carrier** | Airline/operator code |
| **ETD** | Current estimated departure time with prefix code |
| **ETA** | Current estimated arrival time with prefix code |
| **CTD (EDCT)** | Controlled departure time — the EDCT assigned to this flight |
| **CTA** | Controlled arrival time — the assigned arrival slot |
| **OETA** | Original estimated arrival time (frozen at program issuance) |
| **Delay** | Minutes of delay: CTA - BETA |
| **Status** | Current flight status: scheduled, proposed, active, departed, arrived, cancelled |
| **CTL_TYPE** | Control type code (GDP, COMP, DAS, SCS, etc.) |
| **Exempt** | Exemption code if the flight is exempt from the program |
| **ALM** | Alarm flags (bitmask) |

#### 21.2 Flight List Filtering

The Flight List supports extensive filtering capabilities [1, Ch.6]:

- **By status**: Active, proposed, scheduled, cancelled, exempt
- **By control type**: GDP, DAS, COMP, SCS, ECR, etc.
- **By carrier**: Individual airline or group of airlines
- **By origin**: Departure airport or ARTCC
- **By alarm**: Flights with specific alarm flags set
- **By delay range**: Minimum and/or maximum delay thresholds
- **Free-text search**: Search by ACID (callsign)

#### 21.3 Query Manager

The Query Manager allows users to build and save complex multi-criteria queries against the ADL data [1, Ch.6, Ch.16]:

- Supports boolean logic (AND, OR, NOT) across all flight data fields
- Saved queries persist across FSM sessions
- Results can be exported or used as input to other FSM functions
- Pre-defined queries provided for common operational scenarios (e.g., "all delayed flights > 60 min", "all non-compliant flights")

---

### 22. Flight Status, Flags & Alarm Codes

#### 22.1 Flight Status Values

TFMS tracks flights through a defined set of status values [1, Ch.6, App A]:

| Status | Description |
|--------|-------------|
| **Scheduled** | Flight exists in OAG data only — no flight plan or CDM message received |
| **Proposed** | Flight plan filed but not yet active in the NAS |
| **Active** | Flight is in the NAS — taxiing, departing, en route, or arriving |
| **Completed** | Flight has arrived at destination (AZ message or CDM ON/IN received) |
| **Cancelled** | Flight removed from the ADL — timed out, cancelled by airline, or other removal |

#### 22.2 ETD/ETA Prefix Hierarchy

When multiple data sources provide departure or arrival time estimates, TFMS uses the prefix priority hierarchy (Section 3.1) to determine which estimate to display. The highest-priority prefix always wins [5, p.38-39]:

```
Priority: S < N < R < P < L < T < E < A < M
           (lowest)                    (highest)
```

A flight that has an OAG schedule time (S), then files a flight plan (P), then receives an airline update (L), then pushes back (T), then becomes airborne (A) — the displayed ETD will reflect the highest available prefix at each point in time.

#### 22.3 Alarm Codes (Detailed)

Beyond the CTL_ALM bitmask (Section 10.3), FSM displays additional alarm and status indicators [1, Ch.6, App A]:

| Indicator | Meaning |
|-----------|---------|
| **CDM Cancel** | Airline has sent a CDM cancellation message for the flight |
| **CDM Reinstate** | Previously cancelled flight has been reinstated by airline CDM message |
| **Slot Hold** | Airline has placed a hold on the flight's slot (prevents substitution) |
| **Bridged** | Flight has been moved to a different slot by bridging (SCS, ECR, or Adaptive) |
| **Rerouted** | Flight has been rerouted by ATCSCC or TMU |
| **Pop-up** | Flight appeared in the ADL after the current program was issued |
| **Time-out** | Flight's ETD has passed without departure — approaching cancellation |

---

### 23. Map Display & Airport Status Colors

#### 23.1 Airport Status Color Coding

FSM uses a standardized color scheme to indicate airport TMI status on the map display [1, Ch.3]:

| Color | Status |
|-------|--------|
| **Green** | No active TMI — normal operations |
| **Yellow** | Advisory or minor restriction — monitoring recommended |
| **Orange** | Active GDP or AFP — flights receiving controlled times |
| **Red** | Active Ground Stop — departures halted |
| **Magenta** | Multiple overlapping TMIs — highest severity displayed |

#### 23.2 Flight Icon Colors

Individual flight icons on the map are colored by their current status within the active TMI [1, Ch.3]:

| Color | Flight Status |
|-------|--------------|
| **Green** | Uncontrolled — no TMI affecting this flight |
| **Blue** | Controlled — has an assigned EDCT/CTA, not yet departed |
| **Cyan** | Departed — has departed per EDCT, en route to destination |
| **Yellow** | Delayed — controlled with significant delay (>30 min configurable) |
| **Red** | Non-compliant — departed outside CTD compliance window |
| **Gray** | Exempt — within program scope but exempted |

---

### 24. Monitoring & Alerts

#### 24.1 Parameter Alerts

FSM generates alerts when program parameters change or require attention [1, Ch.11]:

| Alert | Trigger |
|-------|---------|
| **Rate Change** | AAR/ADR has been modified since the program was issued |
| **Scope Change** | Program scope has been expanded or contracted |
| **Compression Available** | Open slots detected that could be filled by compression |
| **Pop-up Surge** | Pop-up flight count exceeds the forecast |
| **Program Staleness** | Active program has not been reviewed or modified for an extended period |
| **FADT Pending** | FADT distribution is queued but not yet confirmed sent |

#### 24.2 Compliance Monitoring

TFMS continuously monitors controlled flights for EDCT compliance [1, Ch.11]:

- **CTD compliance window**: ±5 minutes from the assigned EDCT
- **CTA compliance window**: ±5 minutes from the assigned arrival slot
- Non-compliant flights trigger CTD_COMPLIANCE or CTA_COMPLIANCE alarm flags
- Compliance statistics are available per program and per carrier
- RT FSA provides dedicated compliance reports updated every 5 minutes [3, p.1]

---

### 25. Autosend & Communication Architecture

#### 25.1 Autosend System

The Autosend server is the distribution backbone for all TMI communications [1, Ch.11, p.346]:

| Output | Trigger | Recipients | Format |
|--------|---------|------------|--------|
| **FADT file** | Program issuance, revision, compression, blanket | Hub site → centers → towers, airlines | Machine-readable (see Section 13) |
| **Coversheet** | Program issuance, revision | NTML, designated recipients | XML formatted |
| **Advisory message** | Program issuance, revision, extension, purge | TFMS E-mail distribution lists | Plain text / structured |
| **Analysis Report** | Program issuance | Requesting user, designated recipients | Tabular format |
| **Carrier Statistics** | Program issuance | Requesting user, designated recipients | Tabular format |

#### 25.2 Distribution Hierarchy

FADT data flows through a tiered distribution hierarchy [1, Ch.11]:

```
TFMS-Core ──→ Autosend Server ──→ Hub Site
                                      │
                    ┌─────────────────┼─────────────────┐
                    ▼                 ▼                  ▼
              ARTCC TMUs        Airline AOCs       CDM Participants
                    │                 │                  │
                    ▼                 ▼                  ▼
              Tower Cabs        Dispatch/Crew      Third-Party Tools
```

---

### 26. Coversheet Workflow

#### 26.1 Two-Phase Review Process

The coversheet workflow enforces a mandatory two-phase review before any TMI can be sent [1, Ch.11]:

**Phase 1: Parameter Review**
1. Coversheet displays all program parameters in a formatted review layout
2. User reviews every parameter — rate, scope, times, program type, exemptions
3. User clicks the **parameter review checkmark** to confirm review
4. The checkmark is **mandatory** — Send button remains disabled without it

**Phase 2: Advisory Review**
1. User enters the advisory text — free-form description of conditions and program rationale
2. User selects **causal factor codes** from the standardized list (weather categories, equipment, security, etc.)
3. User clicks the **advisory review checkmark** to confirm
4. The checkmark is **mandatory** — Send button remains disabled without it

Only when both checkmarks are confirmed does the **Send** button become active.

#### 26.2 Advisory Text Standards

Advisory text follows ATCSCC conventions [8]:

- **Concise description** of the causal factor (e.g., "Low ceilings and visibility at JFK due to IFR conditions")
- **Expected duration** if known ("Expected to improve by 1800Z")
- **Coordination notes** ("Coordinated with N90, ZNY")
- **Impact summary** ("Approximately 150 flights affected")

---

### 27. RT FSA Demand/Capacity Analysis

The Real-Time Flight Schedule Analyzer (RT FSA) is a web-based companion application to FSM that provides continuous monitoring of TMI execution [3, p.1].

#### 27.1 RT FSA Architecture

- Web-based application — accessible via browser, no Java client required
- Data source: TFMS-Core ADL data (same data as FSM)
- Update interval: **every 5 minutes** — all 12 report types refresh simultaneously
- Reports available for any active TMI element (GDP, GS, AFP airport or FCA)

#### 27.2 RT FSA Report Types (Detailed)

| # | Report | Purpose | Key Metrics |
|---|--------|---------|-------------|
| 1 | **Demand/Capacity** | Arrivals vs. AAR by time period | Demand bars, capacity line, excess demand highlighting |
| 2 | **Delay Distribution** | Histogram of per-flight delays | Delay bins, count per bin, cumulative percentage |
| 3 | **Program Compliance** | CTD and CTA compliance rates | % compliant, % early, % late, by carrier |
| 4 | **Cancellation Analysis** | Impact of cancellations on slot utilization | Cancelled count, freed slots, reallocation status |
| 5 | **Substitution Activity** | SCS/ECR activity tracking | Sub count, slot movement direction (earlier/later), by carrier |
| 6 | **Pop-up Summary** | Flights appearing after program issuance | Pop-up count vs. forecast, DAS delay assigned, timing distribution |
| 7 | **Compression Opportunities** | Potential delay savings from compression | Open slots, potential delay reduction, recommended action |
| 8 | **Carrier Equity** | Per-carrier delay distribution | Average delay by carrier, EMA, EMF values |
| 9 | **Airborne Holding** | Flights in holding patterns | Hold count, average hold duration, fuel impact estimate |
| 10 | **Exemption Summary** | Exempt flights by exemption type | Count per exemption code, percentage of total |
| 11 | **Slot Utilization** | Assigned vs. used slots by time period | Fill rate, unused slot count, demand vs. slot count |
| 12 | **Program Timeline** | Chronological view of program events | Event timestamps, parameter changes, revision history |

#### 27.3 RT FSA vs FSM

| Aspect | FSM | RT FSA |
|--------|-----|--------|
| **Platform** | Java client | Web browser |
| **Primary use** | Modeling and issuance | Monitoring and analysis |
| **Update mode** | ADL refresh (~5 min) + manual reload | Automatic every 5 min |
| **Report depth** | Data Graph statistics, Flight List, Map | 12 dedicated report types |
| **Can issue TMIs** | Yes | No — monitoring only |

---

## Part V: Data Interfaces

### 28. TFMDI Interface Architecture

The TFMS Data to Industry (TFMDI) interface provides the data bridge between FAA traffic management systems and external stakeholders — airlines, CDM participants, third-party vendors, and international partners [4].

#### 28.1 Operational Configuration

TFMDI operates with two equivalent servers — neither designated primary or backup [4, p.15]:

| Component | Description |
|-----------|-------------|
| **Server A** | Full-function TFMDI server; processes and distributes all data types |
| **Server B** | Full-function TFMDI server; identical capability to Server A |
| **Client connectivity** | External clients connect to either server; automatic failover not guaranteed |

#### 28.2 Data Products

TFMDI distributes the following data products to external subscribers [4]:

| Product | Content | Update Frequency |
|---------|---------|-----------------|
| **ADL Files** | Per-element flight data (91 fields per flight) | ~5 min periodic + event-triggered |
| **Advisory Messages** | TMI issuance, revision, extension, purge notifications | On event |
| **General Messages** | Informational and administrative messages | On event |
| **FADT Files** | Slot assignment data for GDP/GS/AFP actions | On program action |
| **NTML Posts** | National Traffic Management Log entries | On event |

#### 28.3 TFMDI Data Filtering

TFMDI supports filtering at the subscription level [4]:

- **Element filter**: Subscribe to specific airports, FEAs, or FCAs
- **Carrier filter**: Receive only flights for specified carriers
- **Data type filter**: Select which data products to receive (ADL, advisories, FADT, etc.)
- **Format filter**: Choose between compressed/uncompressed, encrypted/unencrypted formats

---

### 29. ADL File Format

The ADL (Aggregate Demand List) file contains comprehensive flight-level data for a single NAS element. Each file contains a header section, element definition, and flight data organized into **19 data block types** [5].

#### 29.1 ADL Block Types

| Block # | Block Name | Description |
|---------|------------|-------------|
| 1 | **Header** | ADL metadata: element, timestamp, sequence, record counts |
| 2 | **Element Info** | Element type (airport/FEA/FCA), AAR/ADR rates, runway config |
| 3 | **Flight ID** | ACID, origin, destination, aircraft type, carrier code |
| 4 | **Schedule Times** | SGTD, SGTA, IGTD, IGTA, PGTD, PGTA — schedule and initial times |
| 5 | **Current Times** | Current ETD/ETA with prefix codes, OETD/OETA, BETD/BETA |
| 6 | **CDM Times** | Airline-reported times: LGTD/LGTA, LRTD/LRTA, OUT/OFF/ON/IN |
| 7 | **Controlled Times** | CTD, CTA, OCTD, OCTA — TMI-assigned times |
| 8 | **Delay Data** | Absolute delay, program delay, delay type codes |
| 9 | **TMI Control** | CTL_TYPE, CTL_ALM, ASLOT, program ID, exemption status |
| 10 | **Position Data** | Current lat/lon, altitude, ground speed, heading |
| 11 | **Route Data** | Filed route string, route type |
| 12 | **Flight Status** | Active/scheduled/cancelled, departure/arrival status flags |
| 13 | **CDM Participant** | CDM airline code, CDM message status |
| 14 | **Earliest Times** | ERTD, ERTA — earliest possible departure/arrival |
| 15 | **Center Data** | Departure/arrival ARTCC, sectors, fix data |
| 16 | **Program Info** | Program ID, program type, scope membership |
| 17 | **FADT_TIMES** | Timestamps of all FADT events for the program |
| 18 | **Substitution Data** | SCS/ECR history, original slot, bridging information |
| 19 | **TBFM Data** | TBFM metering times, STA, status (v14.1 only) |

#### 29.2 ADL Flight Record Structure

Each flight record in the ADL contains up to **91 data fields** organized across the 19 blocks [5, p.12-50]. Key structural rules:

- Fields are **pipe-delimited** (`|`) within each block
- Blocks are **newline-separated** within each flight record
- Flight records are separated by **double-newline** markers
- Empty/unknown fields contain a **dash** (`-`) placeholder
- Times use `YYMMDDHHMM` or `YYMMDDHHMMSS` format depending on the field

#### 29.3 ADL Element Definition Fields

The element definition section (Block 2) contains [5, p.14-16]:

| Field | Description |
|-------|-------------|
| **Element ID** | Airport code, FEA name, or FCA name |
| **Element Type** | `A` (airport), `F` (FEA), `C` (FCA) |
| **AAR** | Airport Acceptance Rate (arrivals per hour) |
| **ADR** | Airport Departure Rate (departures per hour) |
| **Runway Config** | Current runway configuration string |
| **Weather** | Current weather category code |
| **Program Count** | Number of active TMI programs affecting this element |

---

### 30. ADL Broadcast Cycle & Distribution

#### 30.1 Broadcast Mechanics

ADL files are broadcast from TFMS-Core on a defined cycle [5, p.52]:

| Aspect | Value |
|--------|-------|
| **Normal interval** | Every 5 minutes |
| **Event-triggered** | Immediately after TMI actions |
| **Sequence counter** | Increments with each broadcast; resets at **0800Z daily** |
| **Time window** | Current hour minus 1:00 to current hour plus 35:59 |
| **Stale data warning** | FSM alerts if ADL gap exceeds 11 minutes |

#### 30.2 Distribution Architecture

```
TFMS-Core ──→ Internal Network ──→ FSM Clients (ATCSCC, TMUs)
     │
     └──→ TFMDI Servers ──→ External CDM Clients
                                │
                                ├── Airlines (encrypted + compressed)
                                ├── Third-party vendors (encrypted + compressed)
                                └── International partners (filtered)
```

#### 30.3 ADL Completeness

An ADL file represents the **complete known state** of an element at the time of generation. Each broadcast replaces (not patches) the previous version. This means:

- Clients should treat each ADL file as a complete snapshot
- There is no delta/diff mechanism between successive broadcasts
- Any flight present in one ADL but absent from the next has been removed (cancelled, timed out, or exited the time window)
- The sequence counter helps clients detect missed broadcasts

---

### 31. Advisory Message Format

TMI advisories are the primary human-readable communication channel for traffic management actions. They are distributed via TFMS E-mail, posted to the NTML, and can be forwarded to external recipients [8].

#### 31.1 Advisory Structure

Advisory messages follow a structured format [8, p.12-25]:

| Section | Content |
|---------|---------|
| **Header** | Message type, timestamp, originator, sequence number |
| **Subject Line** | Structured: `<Type> <Airport> <Action>` (e.g., "GDP JFK ISSUED") |
| **Program Parameters** | Rate, scope, times, program type — machine-parseable fields |
| **Advisory Text** | Free-form narrative describing conditions and rationale |
| **Causal Factors** | Standardized codes: weather (WX-xxx), equipment (EQ-xxx), security (SC-xxx), volume (VL-xxx), runway (RW-xxx), other (OT-xxx) |
| **Distribution** | List of recipient addresses/groups |
| **Footer** | Originator name, facility, contact information |

#### 31.2 Advisory Types

| Type | Trigger |
|------|---------|
| **GDP ISSUED** | New GDP issuance |
| **GDP REVISED** | GDP parameter modification |
| **GDP EXTENDED** | GDP time window extension |
| **GDP CANCELLED** | GDP purge |
| **GS ISSUED** | New Ground Stop |
| **GS EXTENDED** | GS end time extension |
| **GS CANCELLED** | GS purge |
| **AFP ISSUED** | New Airspace Flow Program |
| **AFP REVISED** | AFP modification |
| **AFP CANCELLED** | AFP purge |
| **COMPRESSION** | Compression operation completed |
| **BLANKET** | Blanket adjustment applied |
| **INFORMATION** | General advisory (no program action) |

---

### 32. General Messages Format

General Messages are informational communications that do not correspond to specific TMI program actions [8, p.40-55]:

#### 32.1 General Message Types

| Type | Purpose |
|------|---------|
| **INFORMATION** | General operational information (e.g., runway closures, expected weather changes) |
| **COORDINATION** | Multi-facility coordination messages |
| **PLANNING** | Strategic planning communications (e.g., anticipated TMI actions later in the day) |
| **TEST** | System test messages (clearly marked; not operational) |

#### 32.2 General Message Structure

| Section | Content |
|---------|---------|
| **Header** | Message type = GENERAL, timestamp, originator |
| **Subject** | Brief topic description |
| **Body** | Free-form text |
| **Distribution** | Recipient list |
| **Expiration** | Optional expiration time after which the message is no longer relevant |

---

## Part VI: PERTI Implementation

### 33. Architecture Mapping

PERTI implements TFMS concepts using a modern web architecture. This section maps FAA/TFMS concepts to their PERTI equivalents.

#### 33.1 System Component Mapping

| TFMS Component | PERTI Equivalent |
|----------------|-----------------|
| **TFMS-Core** | ADL ingest daemon (`scripts/vatsim_adl_daemon.php`) + Azure SQL databases |
| **FSM Client** | GDT web interface (`gdt.php` + `assets/js/gdt.js`) |
| **TFMDI** | SWIM API (`api/swim/v1/`) + WebSocket server (`scripts/swim_ws_server.php`) |
| **RT FSA** | TMI Compliance system (`scripts/tmi_compliance/`) |
| **Autosend** | Discord queue processor (`scripts/tmi/process_discord_queue.php`) + TMI Publish (`tmi-publish.php`) |
| **NTML** | TMI Advisories system (`tmi_advisories` table + Discord channels) |
| **ADL Data** | 8-table normalized architecture in VATSIM_ADL database |
| **FADT** | `tmi_slots` + `tmi_flight_control` tables in VATSIM_TMI |
| **Hub Site** | Azure App Service (`vatcscc`) |

#### 33.2 Data Model Mapping

| TFMS Concept | PERTI Table | Database |
|------------- |-------------|----------|
| **ADL flight record** | `adl_flight_core` + `_plan` + `_position` + `_times` + `_tmi` + `_aircraft` | VATSIM_ADL |
| **TMI Program** | `tmi_programs` | VATSIM_TMI |
| **Arrival Slot** | `tmi_slots` | VATSIM_TMI |
| **Flight EDCT/CTA** | `tmi_flight_control` | VATSIM_TMI |
| **Flight List** | `tmi_flight_list` | VATSIM_TMI |
| **Advisory** | `tmi_advisories` | VATSIM_TMI |
| **Pop-up Queue** | `tmi_popup_queue` | VATSIM_TMI |
| **Event Log** | `tmi_events` | VATSIM_TMI |
| **SWIM Flight** | `swim_flights` | SWIM_API |

#### 33.3 Key Divergences from FAA TFMS

| Aspect | FAA TFMS | PERTI |
|--------|----------|-------|
| **Data source** | OAG + CDM + NAS ATC + TBFM | VATSIM data feed + SimTraffic + ATIS parsing |
| **GDP algorithm** | RBS++ (Ration By Schedule + Auto Compression) | CASA-FPFS+RBD hybrid (see Section 36) |
| **FADT distribution** | Hub site → ARTCC → tower | Discord multi-org notifications + SWIM API |
| **Advisory format** | TFMS E-mail + NTML | Discord posts + NTML-format advisories in DB |
| **User authentication** | FAA network credentials | VATSIM Connect OAuth 2.0 |
| **Client platform** | Java thick client | Web browser (PHP + vanilla JS + jQuery) |
| **Spatial queries** | Internal TFMS geography | PostGIS (`VATSIM_GIS` database) |

---

### 34. GDT UI Components

The GDT page (`gdt.php`) implements the FSM modeling workspace as a web application.

#### 34.1 Page Structure

| Component | Implementation | Purpose |
|-----------|---------------|---------|
| **Airport Selector** | Select2 dropdown | Choose target airport for TMI modeling |
| **Demand Chart** | Chart.js bar chart | FSM-style bar graph of arrivals/departures by 15-min interval |
| **Program Panel** | Dynamic HTML cards | Shows active/proposed GS and GDP programs with status badges |
| **Flight List** | DataTables table | Affected flights with EDCT assignments, sortable/filterable |
| **Program Controls** | Button group | Create, Model, Activate, Extend, Compress, Reoptimize, Purge |
| **Scope Configuration** | Tier checkboxes | Tier 1/2/3 selection (maps to FSM Scope Tab) |
| **Parameter Form** | Form inputs | Rate, start/end times, reason, notes |

#### 34.2 JavaScript Architecture

The GDT frontend is implemented in `assets/js/gdt.js` with supporting modules:

| Module | Purpose |
|--------|---------|
| `gdt.js` | Main GDT controller — program CRUD, demand chart, flight list, state management |
| `tmi-gdp.js` | GDP-specific logic — slot preview, compression triggers, delay histogram |
| `adl-service.js` | ADL data fetching and caching |
| `lib/datetime.js` | UTC time formatting and conversion |
| `lib/dialog.js` | SweetAlert2 wrapper with i18n support |
| `lib/i18n.js` | Internationalization (7,276 translation keys) |

#### 34.3 API Communication

The GDT UI communicates with the backend via the GDT API (`api/gdt/`):

| Endpoint | Method | Purpose |
|----------|--------|---------|
| `api/gdt/programs/list.php` | GET | List all programs for an airport |
| `api/gdt/programs/active.php` | GET | Get active programs |
| `api/gdt/programs/create.php` | POST | Create a new GS or GDP program |
| `api/gdt/programs/activate.php` | POST | Activate a proposed program |
| `api/gdt/programs/extend.php` | POST | Extend an active program |
| `api/gdt/programs/purge.php` | POST | Purge/cancel an active program |
| `api/gdt/slots/list.php` | GET | Get slot assignments for a program |
| `api/gdt/flights/list.php` | GET | Get flight list for a program |
| `api/gdt/demand/chart.php` | GET | Get demand data for chart rendering |

Legacy TMI API endpoints (`api/tmi/`) remain available for backward compatibility.

---

### 35. TMI API Endpoints

#### 35.1 GDT Unified API (`api/gdt/`)

The GDT API provides a unified interface for all TMI program types:

**Programs:**

| Endpoint | Method | Description |
|----------|--------|-------------|
| `programs/list.php` | GET | List programs (filter by airport, status, type) |
| `programs/active.php` | GET | Get currently active programs |
| `programs/create.php` | POST | Create GDP or GS program |
| `programs/activate.php` | POST | Transition program from proposed → active |
| `programs/extend.php` | POST | Extend program end time |
| `programs/purge.php` | POST | Cancel program and release EDCTs |
| `programs/model.php` | POST | Run GDP model (preview without activating) |

**Slots & Flights:**

| Endpoint | Method | Description |
|----------|--------|-------------|
| `slots/list.php` | GET | List slot assignments for a program |
| `flights/list.php` | GET | Flight list with EDCT/CTA/delay data |
| `flights/exempt.php` | POST | Exempt a specific flight |

**Demand:**

| Endpoint | Method | Description |
|----------|--------|-------------|
| `demand/chart.php` | GET | Demand data for bar chart (15-min intervals) |

#### 35.2 Legacy TMI API (`api/tmi/`)

| Endpoint | Method | Description |
|----------|--------|-------------|
| `gdp_preview.php` | POST | Preview GDP slot allocation |
| `gdp_apply.php` | POST | Activate GDP and issue EDCTs |
| `gdp_simulate.php` | POST | Simulate GDP impact without activation |
| `gdp_purge.php` | POST | Cancel GDP and release EDCTs |
| `compress.php` | POST | Trigger CASA compression |
| `reoptimize.php` | POST | Trigger full FPFS+RBD reoptimization |
| `gs/create.php` | POST | Create Ground Stop |
| `gs/activate.php` | POST | Activate Ground Stop |
| `gs/extend.php` | POST | Extend Ground Stop |
| `gs/purge.php` | POST | Cancel Ground Stop |

---

### 36. GDP Algorithm (CASA-FPFS+RBD)

PERTI replaces the FAA's RBS++ algorithm with a modern hybrid approach: **CASA-FPFS+RBD** (Compression After Slot Assignment - First Planned First Served + Ration By Distance).

#### 36.1 Why Replace RBS++?

| Limitation of RBS++ | PERTI Solution |
|---------------------|---------------|
| Schedule-order bias favors airlines with early schedule slots | FPFS uses actual ETA, not OAG schedule |
| No distance-based equity | RBD distributes delay proportionally by distance |
| Compression is a separate action | CASA integrates compression into the assignment loop |
| Pop-up handling is reactive | Adaptive reserves pre-allocate capacity for anticipated pop-ups |

#### 36.2 Algorithm Components

**FPFS (First Planned First Served):**
- Orders flights by estimated arrival time (ETA), **not** by OAG schedule time (SGTA)
- Within 5-minute ETA buckets, flights are treated equally
- Removes the schedule-position advantage that RBS++ gives to airlines with published schedules
- Fairer for VATSIM operations where many flights don't have OAG schedules

**RBD (Ration By Distance):**
- Within each 5-minute ETA bucket, delays are distributed proportionally by distance to destination
- Closer flights receive less delay (less fuel burn, less holding potential)
- Farther flights receive more delay (more ground delay = more fuel savings vs. airborne holding)
- Tiebreaker formula: `delay_weight = flight_distance / max_distance_in_bucket`

**CASA (Compression After Slot Assignment):**
- After initial FPFS+RBD allocation, compression runs automatically
- Fills gaps from exempt flights, cancellations, and over-allocated periods
- Same-airline preference inherited from FAA compression logic
- Guarantee preserved: compression never increases total or average delay

**Adaptive Reserves:**
- Pre-allocates reserve slots for anticipated pop-up demand
- Formula: `target = floor + (initial_reserves - floor) × (1 - demand_ratio / 100)`
- Floor: 20% of initial reserves (minimum always maintained)
- As demand increases (demand_ratio → 100), reserves decrease toward floor
- Prevents over-reservation that would artificially inflate delays

#### 36.3 Non-Anticipativity Constraints

The PERTI algorithm enforces non-anticipativity [memory/gdp-algorithm-design.md]:

- A flight's EDCT cannot be set earlier than the current time + minimum taxi time
- Compression cannot move a flight to a slot that has already passed
- Reserve slots cannot be placed before the current time

These constraints ensure that all assignments are operationally feasible.

#### 36.4 Implementation

| Component | Location |
|-----------|----------|
| **FPFS+RBD assignment** | `sp_TMI_AssignSlots` stored procedure (VATSIM_TMI) |
| **CASA compression** | `sp_TMI_CompressProgram` stored procedure (VATSIM_TMI) |
| **Reoptimization orchestrator** | `sp_TMI_ReoptimizeProgram` stored procedure (VATSIM_TMI) |
| **Compress API** | `api/tmi/compress.php` |
| **Reoptimize API** | `api/tmi/reoptimize.php` |
| **GDP Preview** | `api/tmi/gdp_preview.php` |
| **GDP Apply** | `api/tmi/gdp_apply.php` |
| **FlightListType TVP** | Table-valued parameter for batch flight data to stored procedures |

#### 36.5 Database Implementation

Migrations that implement the GDP algorithm:

| Migration | Content |
|-----------|---------|
| **037** | Bug fixes, compress.php endpoint, batch optimize |
| **038** | FPFS+RBD algorithm, adaptive reserves, FlightListType TVP |
| **039** | `sp_TMI_ReoptimizeProgram` orchestrator, reoptimize.php endpoint |
| **041** | Reversal metrics, anti-gaming flags, GDT UI (compress/reopt/observability) |

---

### 37. Database Schema

#### 37.1 `tmi_programs` Table

| Column | Type | Description |
|--------|------|-------------|
| `program_id` | int (PK) | Unique program identifier |
| `airport_icao` | varchar(4) | Constrained airport |
| `program_type` | varchar(20) | GS, GDP-DAS, GDP-GAAP, GDP-UDP, AFP |
| `status` | varchar(20) | PROPOSED, ACTIVE, CANCELLED, COMPLETED |
| `start_utc` | datetime | Program start time |
| `end_utc` | datetime | Program end time |
| `program_rate` | int | Acceptance rate (flights/hour) |
| `scope_type` | varchar(10) | TIER or DISTANCE |
| `scope_tiers` | varchar(50) | Comma-separated tier numbers |
| `scope_distance_nm` | int | Distance in nautical miles (distance-based scope) |
| `reason` | varchar(50) | Causal factor code |
| `notes` | text | Free-form notes |
| `created_by` | int | User CID who created the program |
| `created_utc` | datetime | Creation timestamp |
| `activated_utc` | datetime | Activation timestamp |
| `cancelled_utc` | datetime | Cancellation timestamp |

#### 37.2 `tmi_slots` Table

| Column | Type | Description |
|--------|------|-------------|
| `slot_id` | int (PK) | Unique slot identifier |
| `program_id` | int (FK) | Associated program |
| `slot_time_utc` | datetime | Arrival slot time (CTA) |
| `slot_label` | varchar(20) | ASLOT-format label (e.g., `JFK.091530A`) |
| `assigned_flight_uid` | bigint | Flight UID assigned to this slot (NULL if unassigned) |
| `assigned_callsign` | varchar(10) | Flight callsign |
| `ctd_utc` | datetime | Controlled departure time (EDCT) |
| `delay_minutes` | int | Assigned delay |
| `control_type` | varchar(10) | CTL_TYPE code |
| `assigned_at` | datetime | When the flight was assigned to this slot |
| `updated_at` | datetime | Last modification timestamp |

#### 37.3 `tmi_flight_control` Table

| Column | Type | Description |
|--------|------|-------------|
| `control_id` | int (PK) | Unique control record identifier |
| `program_id` | int (FK) | Associated program |
| `flight_uid` | bigint | Flight UID |
| `callsign` | varchar(10) | Flight callsign |
| `origin` | varchar(4) | Departure airport |
| `destination` | varchar(4) | Arrival airport |
| `edct_utc` | datetime | Current EDCT (CTD) |
| `cta_utc` | datetime | Current CTA |
| `original_edct_utc` | datetime | First EDCT assigned (OCTD equivalent) |
| `original_cta_utc` | datetime | First CTA assigned (OCTA equivalent) |
| `delay_minutes` | int | Current delay |
| `control_type` | varchar(10) | CTL_TYPE code |
| `exempt` | bit | Whether the flight is exempt |
| `exempt_reason` | varchar(20) | Exemption code |
| `reversal_count` | int | Number of times EDCT moved later |
| `anti_gaming_flag` | bit | Whether flight behavior suggests gaming |
| `compliance_status` | varchar(20) | PENDING, COMPLIANT, NON_COMPLIANT, DEPARTED, ARRIVED |

#### 37.4 `tmi_events` Table

| Column | Type | Description |
|--------|------|-------------|
| `event_id` | int (PK) | Unique event identifier |
| `program_id` | int (FK) | Associated program |
| `event_type` | varchar(30) | CREATE, ACTIVATE, EXTEND, COMPRESS, REOPTIMIZE, PURGE, etc. |
| `event_utc` | datetime | Event timestamp |
| `event_data` | nvarchar(max) | JSON payload with event-specific data |
| `created_by` | int | User CID who triggered the event |

---

### 38. Daemon Integration

#### 38.1 TMI→ADL Sync

The `executeDeferredTMISync()` function in the ADL ingest daemon (`scripts/vatsim_adl_daemon.php`) synchronizes TMI control data from `VATSIM_TMI` to `VATSIM_ADL` on a 60-second cycle:

1. **Read active programs** from `tmi_programs` where status = 'ACTIVE'
2. **Read flight control records** from `tmi_flight_control` for active programs
3. **Update ADL flight records** — write EDCT, CTA, delay, control type, compliance status to `adl_flight_tmi`
4. **Multi-program precedence**: When a flight is affected by multiple programs, GS takes priority, then the program with the highest CTA (most delay) wins

#### 38.2 Reoptimization Cycle (Planned)

A daemon reoptimization cycle (2-5 minute intervals) is planned to run `sp_TMI_ReoptimizeProgram` periodically on all active GDP programs:

1. **Detect changes** — new pop-ups, cancellations, position updates since last optimization
2. **Run CASA compression** — fill gaps from cancellations and exempt flights
3. **Run FPFS+RBD** — reassign any newly detected flights
4. **Update delay metrics** — recalculate program-wide statistics
5. **Sync to ADL** — trigger `executeDeferredTMISync()` for immediate reflection in ADL

This feature is pending implementation.

#### 38.3 Pop-up Detection

The `tmi_popup_queue` table captures flights that appear in the ADL after a program is issued:

- ADL ingest daemon detects new flights matching active program scope
- Inserts record into `tmi_popup_queue` with flight details and detection timestamp
- GDT UI polls the queue and displays pop-ups for traffic manager review
- Pop-ups can be: added to program (receive EDCT), exempted, or monitored only

#### 38.4 Discord Integration

TMI actions trigger Discord notifications via the queue processor:

1. **Program action** (create, activate, extend, purge) → record inserted in `discord_messages` queue
2. **`process_discord_queue.php`** daemon picks up queued messages
3. **Multi-org routing** — messages sent to configured Discord channels based on affected facilities
4. **Rich embeds** — program details formatted as Discord embeds with demand chart thumbnails

---

## Appendices

### Appendix A: FSM Field Definitions

Key field definitions from FSM Appendices A and B [1, App A-B]:

| Field | Definition | Notes |
|-------|-----------|-------|
| **ACID** | Aircraft Identifier | Callsign — e.g., `UAL123`, `DAL456` |
| **IGTA** | Initial Gate Time of Arrival | First arrival estimate computed by TFMS; **frozen — never changes** |
| **IGTD** | Initial Gate Time of Departure | First departure estimate computed by TFMS; **frozen — never changes** |
| **OETA** | Original Estimated Time of Arrival | ETA frozen at TMI issuance, departure, or timeout |
| **OETD** | Original Estimated Time of Departure | ETD frozen at TMI issuance, departure, or timeout |
| **BETA** | Base Estimated Time of Arrival | ETA frozen at TMI issuance or departure |
| **BETD** | Base Estimated Time of Departure | ETD frozen at TMI issuance or departure |
| **CTA** | Controlled Time of Arrival | Assigned arrival slot time |
| **CTD** | Controlled Time of Departure | Assigned EDCT |
| **OCTA** | Original Controlled Time of Arrival | First CTA assigned; **frozen — never changes** |
| **OCTD** | Original Controlled Time of Departure | First EDCT assigned; **frozen — never changes** |
| **DAS** | Delay Assignment | Pop-up delay value |
| **EMA** | Equity Metric Airlines | 1=perfect; 2-8=good; 9-16=significant; >16=poor |
| **EMF** | Equity Metric Flights | Same scale as EMA, per-flight level |
| **PSLOT** | Pseudo-Slot | Virtual slot used in ECR calculations |
| **ASLOT** | Arrival Slot | Physical slot: `element.DDHHMMletter` |

### Appendix B: ADL Field Reference

Complete list of ADL flight record fields with data types [5, p.12-50]:

| # | Field | Type | Length | Description |
|---|-------|------|--------|-------------|
| 1 | ACID | Char | 7 | Aircraft identifier |
| 2 | FLT_UID | Numeric | 10 | Unique flight identifier (persistent across ADL refreshes) |
| 3 | ORIG | Char | 5 | Origin airport |
| 4 | DEST | Char | 5 | Destination airport |
| 5 | ACFT_TYPE | Char | 4 | ICAO aircraft type designator |
| 6 | CARRIER | Char | 3 | Carrier/airline code |
| 7 | ETD | Char | 12 | Estimated departure: prefix + YYMMDDHHMM |
| 8 | ETA | Char | 12 | Estimated arrival: prefix + YYMMDDHHMM |
| 9 | CTD | Char | 10 | Controlled departure time: YYMMDDHHMM |
| 10 | CTA | Char | 10 | Controlled arrival time: YYMMDDHHMM |
| ... | ... | ... | ... | (91 total fields — see ADL spec v14.1 for complete list) |

### Appendix C: FADT Field Reference

FADT flight record fields [7]:

| Field | Description |
|-------|-------------|
| **ACID** | Aircraft identifier |
| **DEP** | Departure airport |
| **ARR** | Arrival airport |
| **ETD** | Estimated departure time |
| **EDCT** | Assigned departure clearance time |
| **CTA** | Controlled arrival time |
| **DELAY** | Delay in minutes |
| **ACFT** | Aircraft type |
| **CARRIER** | Carrier code |
| **DEP_CENTER** | Departure ARTCC |
| **ASLOT** | Arrival slot identifier |
| **CTL_TYPE** | Control type code |
| **EXEMPT** | Exemption status and code |

### Appendix D: Advisory Field Reference

Advisory message fields [8]:

| Field | Description |
|-------|-------------|
| **MSG_TYPE** | Advisory type (GDP_ISSUED, GS_ISSUED, etc.) |
| **ELEMENT** | Affected airport/FCA/FEA |
| **TIMESTAMP** | Message generation time (UTC) |
| **ORIGINATOR** | Issuing facility/user |
| **PROGRAM_TYPE** | GDP-DAS, GDP-GAAP, GDP-UDP, GS, AFP, etc. |
| **RATE** | Program rate (if applicable) |
| **SCOPE** | Scope description |
| **START_TIME** | Program start (UTC) |
| **END_TIME** | Program end (UTC) |
| **CAUSAL_FACTORS** | Comma-separated causal factor codes |
| **ADVISORY_TEXT** | Free-form narrative |
| **DISTRIBUTION** | Recipient list |
| **SEQUENCE** | Message sequence number |

### Appendix E: Glossary

| Term | Definition |
|------|-----------|
| **AAR** | Airport Acceptance Rate — maximum arrivals per hour the airport can handle |
| **ACID** | Aircraft Identifier — flight callsign |
| **ADL** | Aggregate Demand List — TFMS per-element flight data file |
| **ADR** | Airport Departure Rate — maximum departures per hour |
| **AFP** | Airspace Flow Program — TMI for FCA/FEA airspace constraints |
| **ASLOT** | Arrival Slot — unique slot identifier in format `element.DDHHMMLetter` |
| **ATCSCC** | Air Traffic Control System Command Center — FAA national TFM facility |
| **BETA/BETD** | Base Estimated Time of Arrival/Departure — frozen at TMI or departure |
| **CASA** | Compression After Slot Assignment — PERTI algorithm component |
| **CDM** | Collaborative Decision Making — FAA/airline data sharing program |
| **CTA** | Controlled Time of Arrival — TMI-assigned arrival slot time |
| **CTD** | Controlled Time of Departure — TMI-assigned EDCT |
| **CTOP** | Collaborative Trajectory Options Program — multi-route TMI |
| **CTL_TYPE** | Control Type — code indicating how a flight was assigned (GDP, COMP, DAS, etc.) |
| **CTL_ALM** | Control Alarm — bitmask of compliance and anomaly flags |
| **DAS** | Delay Assignment — pop-up flight delay value in GDP |
| **EDCT** | Expect Departure Clearance Time — assigned controlled departure time |
| **EMA/EMF** | Equity Metric Airlines/Flights — delay distribution fairness metrics |
| **ERTA** | Earliest Runway Time of Arrival — computed from earliest EDCT + flight time |
| **ETE** | Estimated Time Enroute — computed: ETA - ETD |
| **FADT** | Fuel Advisory Delay Table — machine-readable TMI slot assignment file |
| **FCA** | Flow Constrained Area — airspace volume under flow management |
| **FEA** | Flow Evaluation Area — monitored airspace volume |
| **FPFS** | First Planned First Served — PERTI algorithm, orders by ETA not schedule |
| **FSM** | Flight Schedule Monitor — TFMS Java client application |
| **GAAP** | Ground Allocated Arrival Period — GDP mode with pre-allocated unassigned slots |
| **GDT** | Ground Delay Tool — FSM modeling workspace; PERTI web interface |
| **GDP** | Ground Delay Program — TMI distributing delay via slot-based EDCT assignment |
| **GS** | Ground Stop — TMI halting all departures to a constrained airport |
| **IGTA/IGTD** | Initial Gate Time of Arrival/Departure — first TFMS estimate, never changes |
| **IPM** | Integrated Planning Model — FSM multi-scenario comparison mode |
| **NTML** | National Traffic Management Log — public record of TMI actions |
| **OETA/OETD** | Original Estimated Time of Arrival/Departure — frozen at TMI/departure/timeout |
| **OOOI** | Out-Off-On-In — CDM gate/runway event times |
| **PSLOT** | Pseudo-Slot — virtual slot used in ECR calculations |
| **RBD** | Ration By Distance — PERTI algorithm, distributes delay by distance |
| **RBS/RBS++** | Ration By Schedule — FAA GDP algorithm (++ = with auto compression) |
| **RT FSA** | Real-Time Flight Schedule Analyzer — web-based TMI monitoring tool |
| **SCS** | Slot Credit Substitution — airline-initiated flight swap between slots |
| **TFMDI** | TFM Data to Industry — external data interface for CDM participants |
| **TFMS** | Traffic Flow Management System — FAA's primary TFM decision support system |
| **TMI** | Traffic Management Initiative — any program (GDP, GS, AFP, reroute, etc.) |
| **TMU** | Traffic Management Unit — ARTCC facility responsible for local TFM |
| **UDP** | Unified Delay Program — GDP mode combining DAS + GAAP with reserves |
