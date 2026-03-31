# TFMS TMI Operations & Algorithms Analysis -- Prong 2

**Source Documents:**
- TFMS Reference Manual, TSD Version 8.9 (Document Version 24)
- TSD Reference Manual, Version 9.5 (CSC/TFMM-13/1600)

**Analysis Date:** 2026-03-31

---

## 1. Ground Delay Programs (GDP)

### 1.1 Definition

A GDP is "a specific operation that assigns delay (control times) to the departure times of flights that will be arriving at a specific airport." The control element (`CTL_ELEM`) for a GDP is always an arrival airport.

### 1.2 GDP Program Modes

| Mode | Description |
|------|-------------|
| **DAS** (Delay Assignment) | Standard RBS-based slot assignment. Pop-up flights receive average delay. |
| **GAAP** (General Aviation Airport Program) | Used "when current demand does not meet capacity but it is believed that unknown traffic will meet or near capacity." Pop-ups assigned to unassigned slots when available. |
| **UDP** (Unified Delay Program) | Pop-up and re-control flights allocated to unassigned slots. Bridge creation (`UBRG`) is automatic. |

### 1.3 RBS++ Algorithm

The manuals do not contain full RBS pseudocode (they are operator/UI manuals). However, key behavioral details are documented.

From TSD 9.5 CTOP Flight List:
> "All flights with the same MAJOR value are considered together during the intra-airline swapping portion of RBS++ and Compression."

This reveals RBS++ operates in two phases:
1. **Inter-airline allocation**: Slots assigned across all operators using Ration-By-Schedule (proportional to demand)
2. **Intra-airline swapping**: Within each operator (identified by `MAJOR` code), flights re-ordered to minimize operator-specific costs

The `MAJOR` code determines operator grouping:
- 3-letter codes = official airline designators
- Codes starting with `.` = dummy codes for non-airline CDM participants

### 1.4 Slot Assignment Time Parameters

**IGTD (Initial Gate Time of Departure)**: Set when flight first created from OAG, CDM, or flight plan data. Never changed. Null if created from active message. Used for flight leg identification.

**IENTRY (Initial Element Entry Time)**: Used by FSM to determine priority order for slot allocation.
- For FEA: `IENTRY = ENTRY - (ETD - IGTD)` (predicted entry minus accrued delay)
- For FCA: Same formula, set when flight first observed traversing FCA; thereafter never changed

**BETD (Base Estimated Time of Departure)**: Saved at TMI issuance or flight departure. Updated from FS/FC/FM/FZ messages while flight not controlled. Used to compute TMI-attributable departure delay.

**ERTA (Earliest Runway Time of Arrival)**: "The earliest CTA that the airline would accept" -- from CDM Participant

**ERTD (Earliest Runway Time of Departure)**: "Earliest time the flight could depart the runway" -- from AOC/FOC

**EENTRY (Earliest Element Entry Time)**: Computed by TFMS-Core. "Used to ensure that a flight is not assigned a slot for an FEA/FCA that it cannot use."

### 1.5 Control Types (`CTL_TYPE` Taxonomy)

| CTL_TYPE | Description | Programs |
|----------|------------|---------|
| `GDP` | Initial or Revision | GDP |
| `GS` | Ground Stop | GDP |
| `BLKT` | Blanket (+/-) | GDP |
| `COMP` | Compression | AFP, GDP |
| `ADPT` | Adaptive Compression by TFMS-Core | AFP, GDP |
| `ABRG` | Adaptive Compression bridge | AFP, GDP |
| `DAS` | Average delay for pop-up (no unassigned slot) | AFP, GDP, CTOP |
| `GAAP` | Pop-up allocated to unassigned slot in GAAP/UDP | AFP, GDP |
| `SCS` | Slot Credit Substitution by NAS User | AFP, GDP |
| `ECR` | Slot Credit Substitution by FAA user | AFP, GDP |
| `SBRG` | Bridge for SCS/ECR request | AFP, GDP |
| `UBRG` | Bridge for pop-up during UDP (automatic) | AFP, GDP |
| `SUB` | Conventional user substitution | AFP, GDP, CTOP |
| `UPD` | EDCT Update by FAA user | AFP, GDP, CTOP |
| `RCTL` | Re-control (purged/dropped, re-controlled) | AFP, GDP, CTOP |
| `AFP` | AFP-Initial or AFP-Revision | AFP |
| `CTOP` | CTOP initial or revision | CTOP |

### 1.6 Slot Credit Substitution (SCS)

Definition: "An operator has flight f0 with slot at time t0. f0 cannot use its slot because it is delayed or cancelled. SCS provides a mechanism for an operator to substitute other operator's flights to bridge the slot from an unusable time to a time the operator can use."

- Toggle: `EDCT SCS OFF/ON <airport|FCA|ALL>`
- Bridge flights: `CTL_TYPE = SBRG`
- Substituted flight: `CTL_TYPE = SCS`
- ECR variant: FAA-authorized user performs substitution
- RCTL flights retain full substitution rights in AFP

### 1.7 Adaptive Compression (AC)

- Separate from SCS
- Toggle: `EDCT AC OFF/ON <airport|FCA|ALL PROGRAMS>`
- Automatic by TFMS-Core
- Creates bridges (`ABRG`) and compressed assignments (`ADPT`)

### 1.8 Pop-Up Flight Handling

| Mode | Treatment |
|------|----------|
| DAS | Average delay; `CTL_TYPE = DAS` |
| GAAP/UDP | Unassigned slot if available (`CTL_TYPE = GAAP`); else average delay (`CTL_TYPE = DAS`) |
| CTOP | Unassigned slot if available (`CTL_TYPE = GAAP`); else average delay (`CTL_TYPE = DAS`) |

**Pop-Up Delay Limit**: 0-999 minutes, default 180.

**Exempt by time**: Flights departing within N minutes (default 45) get EDCT but no delay added.

---

## 2. Ground Stops (GS)

### 2.1 Definition

"A traffic management action that halts departures for a given airport."

### 2.2 Key Details

- `CTL_TYPE = GS`: "Control times assigned as part of a GDP-Ground Stop" -- GS is modeled as a GDP variant in TFMS
- GS slots managed in same infrastructure as GDP slots (visible in `EDCT SLIST` with `TYPE = GS`)
- Program Type flag: `GSD` = Impacted by Ground Stop

### 2.3 GS/GDP Interaction

The `EDCT LIST` command shows all active programs with CONTROL type (EDCT or FA), flight count, substitution count, and SCS status. GS programs appear alongside GDP programs in this unified view.

---

## 3. Airspace Flow Programs (AFP)

### 3.1 GDP vs. AFP

| Characteristic | GDP | AFP |
|---------------|-----|-----|
| Control Element | Airport | FCA |
| Constraint | Airport arrival rate | FCA entry rate/capacity |
| Definition Basis | Airport-based | Airspace polygon/line/circle/NAS element |
| FSM Eligibility | Inherent | Requires `FSM Eligible` checkbox |
| FCA Name Max | N/A | 6 characters for FSM-eligible |

### 3.2 FCA Definition

Requires Public FCA designated FSM-Eligible (ATCSCC only). Public FCAs auto-named `FCA001`+, resetting at 0900Z daily.

**Geometric types**: Polygon (moving: heading 0-359, speed 0-99 kt), Line (moving), Circle (center ddmmN/dddmmW + radius 1-999 nm), NAS Element (Airport/Center/Sector/Base Sector/Fix/TRACON/SUA)

**Parameters**: Time (15-min granularity), Altitude (000-600 hundreds of feet), Extended (up to 7 days), Look Ahead (1-24 hours). Auto-expire 60 minutes after end time.

### 3.3 FCA Capacity Grid (5 rows)

1. **Time**: Bin headers (15 or 60 min)
2. **Demand**: Non-editable flight count per bin
3. **Capacity**: Editable (0-900 for 60-min, 0-225 for 15-min)
4. **AR Above**: Auto-revision upper trigger
5. **AR Below**: Auto-revision lower trigger

**15-minute distribution**: Hourly value splits via 3-level tree. Example: 30/hour becomes 15+15, then 8,7,8,7 per quarter.

### 3.4 FCA Primary Filter

Filter by: departure/arrival airports/ARTCCs, traversed sectors/fixes/ARTCCs, airways, current location, aircraft type/category/weight/user category, flight status, heading range (0-359 +/- 0-180), ACID prefix, flight level (000-600), RVSM status, departure/arrival time range. Logic: AND all vs. OR at least one.

---

## 4. Collaborative Trajectory Options Program (CTOP)

### 4.1 Definition

"A method of managing demand through constrained airspace identified by one or more FCAs."

### 4.2 Key Concepts

**TOS (Trajectory Options Set)**: Multiple route/altitude/speed options ranked by airline preference, each with an RTC value.

**RTC (Relative Trajectory Cost)**: Minutes of ground delay airline accepts before moving to next TOS option.

**Assignment options**: (1) EDCT with delay, (2) EDCT without delay, (3) route around FCA, (4) combination.

### 4.3 Program Parameters

| Parameter | Range | Default |
|-----------|-------|---------|
| Name | 30 char alphanumeric+underscore | Required |
| Start/End | Earliest/latest controlled time bin | Required |
| Rank | Integer (precedence) | Required |
| Auto Revision | On/Off | Off |
| AR Refresh Interval | 5-60 min | -- |
| AR Smoothing Factor | 15-120 min | -- |
| Pop-Up Delay Limit | 0-999 min | 180 |
| Exempt Within | 0+ min | 45 |

### 4.4 CTOP Exemptions

Types: Canada (centers), Mexico (centers), ACID, Arrival Center, Departure Center, Arrival Airport, Departure Airport, By Time (default 45 min -- gets EDCT but no delay).

### 4.5 CTOP Dialog Tabs

Monitor (view demand) -> Model (create/modify, editable) -> Proposed (static shared) -> Active (live) -> Results (multi-FCA aggregate)

### 4.6 CTOP Bar Chart Categories

Routed Out, NC Demand, All Other, CTD, Exempt Other, CTD Other, Active, Current Demand (Model only)

### 4.7 Impact Assessment Statistics

Avg/Max/Total Ground Delay, Avg/Max/Total Route Delay, Affected Flights, Route Out, Exempt. Red border if remodel required.

### 4.8 Automatic Revision (AR)

Per-FCA: AR Above (upper trigger), AR Below (lower trigger), AR Start/End Time. Evaluates only bins with both capacity AND AR values. Refresh: 5-60 min. Smoothing: 15-120 min.

### 4.9 CTOP Lifecycle

Monitor -> Model -> Send Proposed -> Send Actual -> Revise -> Purge Program

**Remodel required when**: Model older than 15 min (configurable), FCA list changed, exemptions/capacities changed, overrides added/removed, FCA definition modified/deleted.

### 4.10 CTOP Flight List Columns (Default)

ACID, ETD, CTD, Entry, Override/Assigned Route

**Full column set**: STATUS, DEST, ACENTR, ORIG, DCENTR, ETA, DFIX, DP, AFIX, STAR, STRSN, TMA-RT, USR, TYPE, CTG, CLS, IGTD, IENTRY, IGTA, PGTD, PGTA, PETE, LRTD, LRTA, ERTD, EENTRY, BETD, BENTRY, BETA, CTD, CTA, ASLOT, CTL_ELEM, CTL_TYPE, EX_REASON, GDPIndicator, AFPIndicator, GSIndicator, EDCT_COMP, MAJOR, ETE, Current RTE, RFP, TMI, DEP_DLY, ASSIGN_RTE, ASSIGN_ALT, ASSIGN_SPD, FXA_POSS, FXA_INT, RTE_DLY, CONF_STAT, Override

### 4.11 Conformance & Override

`CONF_STAT`: Conformant, NC, NC-ALT, NC-SPD

`Override`: Blank (none), `Y` (overridden), `P` (pending), `PA` (pending apply from Model tab)

### 4.12 CTOP Advisory Reasons

Required: Reason (Equipment, Runway/Taxi, Weather, Volume/Center, Volume/Terminal, Other), SubReason (full taxonomy of 40+ values), Charge To (Center/Terminal/Non-FAA + facility ID)

### 4.13 Significant Event Types (Flight History)

CDM: FC, FM, FX. NAS: FZ, AF, UZ, DZ, AZ, RZ. TOS receipt. CTOP substitution. TBFM: MRT, MRT-TO. Surface: SM_SPOT, SM_OFF, SM_ON. TMI issuance/revision. Manual route/EDCT.

---

## 5. Miles-In-Trail / Minutes-In-Trail (MIT/MINIT)

### 5.1 Definition

"An air traffic management initiative used to space aircraft."

### 5.2 Definition Methods

Crossing Segment (geographic line), Navaid (specific fix), FEA/FCA (existing area)

### 5.3 Parameters

| Parameter | Range | Notes |
|-----------|-------|-------|
| Spacing | 1-100 miles | Integer |
| Options | "As One", "Exclude" | -- |
| Time Mode | Auto, Manual, FEA/FCA | Auto = first/last affected flight |
| Destinations | Up to 10 rows | Separate spacing per dest |
| Apply To | All, In Reroute, Not In Reroute | Scope filter |

### 5.4 MIT Data Block

Before modeling: name, scope (RR/ALL/NoRR), spacing. After modeling: + flight count, avg delay, max delay.

### 5.5 MIT Flight List Columns

A/G (airborne/ground), Delayed Time (with MIT), MIT Delay (non-cumulative), Original Time (without MIT). Timeline: quarter-hour intervals. Red delay = excessive (may need pass-back MIT).

---

## 6. Reroutes

### 6.1 Domains

Public (ATCSCC, all users), Shared (field site, facility+ATCSCC+designated), Local (field site, facility only), Private (workstation only)

### 6.2 Advisory Actions

RQD (Required), RMD (Recommended), PLN (Planned), FYI (For Your Information). CTOP flights show `CTP` until ETD < (creation time + exempt-by-time), then `RQD`.

### 6.3 Route Sources

Playbook (pre-defined plays), Route Search (CDR/Playbook/Preferred Routes databases), My Routes (saved), RMT File (import), Manual entry

### 6.4 Conformance Status (`RRSTAT`)

C (Conformant), NC (Non-Conformant), NC/OK (Non-Conformant previously approved), UNKN (Unknown), OK (Exception Granted), EXC (Excluded)

### 6.5 TMI ID Format

`RR` + 3-char facility ID + advisory number (or 3-digit sequence). Example: `RRDCC345`

### 6.6 Key Reroute Monitor Columns

ACTION, AMDT STATUS, CENTRS (red=can move, black=no action), CTL ELEM, CTL PRGM (airport for GDP, FCA for non-CTOP, CTOP name, or "-"), RRSTAT, ROUTE GUIDANCE, CURRENT ROUTE/REROUTE(S), TMI ID, TIME TO (intersect), FCA ENTRY/EXIT, CONF_STAT

### 6.7 Route Amendment

Via RAD (Route Amendment Dialog) -> ERAM. Requires Flight Plan + Planned status + GUFI + assigned route. Protected segments drawn in slate blue. Cancellation messages for dropped flights (All or Not Airborne).

### 6.8 Reroute Impact Assessment (RRIA)

Preview Mode: flight counts on TSD. Model Mode: NAS Monitor (sector peaks with +/- delta), FEA/FCA Timeline (with/without slash-separated), Bar Chart (dual-bar), POI counts (total/hourly/15-min). NAS Monitor shows: Impacted, Alerted, Alerted+Count Up, All.

---

## 7. EDCT Delivery & Compliance

### 7.1 EDCT Command Set

`EDCT AC OFF/ON`, `EDCT CHECK`, `EDCT CNX`, `EDCT CTALIST`, `EDCT HOLD`, `EDCT LIST`, `EDCT LOG`, `EDCT PURGE`, `EDCT RELEASE`, `EDCT SCS OFF/ON`, `EDCT SHOW`, `EDCT SLIST`, `EDCT SLOTS`, `EDCT SUB OFF/ON/PRINT/SHOW`, `EDCT UNASSIGNED SLOTS`, `EDCT UPDATE`

### 7.2 EDCT CHECK Response

ACID, ASLOT (airport+ddhhmm), DEP, CTD (ddhhmm), CTA (ddhhmm), TYPE (GDP/GS/SUB/UPD/FA/COMP/BLKT), EX (Y/N), CX (Y/N), SH (Y/N), ERTA (ddhhmm), IGTD (ddhhmm), CT sent (Y/N), CT time

### 7.3 EDCT SLIST (Airline Delivery Format)

ACID, ASLOT, DEP, CTD, CTA, TYPE, EX, CX, SH, ERTA, IGTD -- described as the "exact format that goes to the airlines." Times in ddhhmm format.

### 7.4 Compliance Window

**+/- 5 minutes from CTD.** From TSD 9.5: "`CTD_COMPLIANCE` -- For any flight controlled by an EDCT, indicates that a flight took off outside the CTD compliance window (more than 5 minutes earlier or 5 minutes later than the CTD)."

### 7.5 EDCT HOLD/RELEASE

`EDCT HOLD ALL SLOTS FOR <airport>` or `EDCT HOLD ALL SLOTS FOR <FCA> <airline>`. SH=Y when held.

### 7.6 EDCT PURGE

Per airport, FCA, or ALL. Time filter (4-digit UTC) with Before/After. Purge clears CTD for non-active, non-completed flights.

---

## 8. TMI Coordination & Advisories

### 8.1 Advisory Tab

Action (RQD/RMD/PLN/FYI), Remarks, Advisory number (auto-assigned)

### 8.2 TMI Change Notification

Warning Triangle icon in NAS Monitor when TMI issued/modified/deleted during modeling. Includes type (GDP/GS/AFP/RR/FEA/FCA) and entity name.

### 8.3 Multi-Facility Coordination (Shared Sites)

Center checkboxes (US+Canadian), Select Impacted (auto-select traversed centers), Additional Facilities by name. Workflow: field site creates -> shares -> receiving sites View Model/Copy/Edit -> ATCSCC can promote to Public.

### 8.4 CTOP Advisory

Program name, Start/End, Rank, Reason/SubReason (required), Charge To + ID (required), Coversheet (optional). Preview before send.

### 8.5 OpsNet Reason/SubReason Taxonomy

Full taxonomy of ~40+ reason/subreason pairs covering Equipment, Runway/Taxi, Weather, Volume/Center, Volume/Terminal, and Other categories with both OpsNet text format (e.g., "WEATHER / THUNDERSTORMS") and advisory text format (e.g., "Weather, Thunderstorms").

---

## 9. Key Data Structures

### 9.1 ETD Prefix Values

S=Scheduled, N=Early Intent/Least Cost TOS, L=Airline-supplied, P=Flight Plan, T=Taxied, M=TBFM Release, R=Reroute/CTOP assigned, E=Estimated, A=Actual, C=Controlled

### 9.2 Flight Status

S=Scheduled, N=Early Intent, P=Proposed, T=Taxi, A=Active, E=Estimated, R=Replaced

### 9.3 User Category

C=Air Carrier, F=Freight/Cargo, G=General Aviation, M=Military, T=Air Taxi, O=Other

### 9.4 Aircraft Category

J=Jets, P=Props, T=Turbo

### 9.5 Weight Class

H=Heavy, L=Large, S=Small

### 9.6 Time Formats

All UTC. Time bins: 15 or 60 min. EDCT: ddhhmm. FCA: mm/dd/yy hh:mm.

---

## 10. PERTI Coverage Gap Analysis

### 10.1 Gap Summary

| Feature | PERTI Status | Gap |
|---------|-------------|-----|
| GDP/GS | `tmi_programs`, lifecycle | Missing: GAAP/UDP modes, FA controls |
| Slot Assignment | `sp_TMI_RunGDP` FPFS+RBD | Different by design |
| SCS/ECR | Not implemented | Large gap |
| Adaptive Compression | `sp_TMI_CompressProgram` | Missing: bridge types (ABRG/SBRG/UBRG) |
| AFP | Not implemented | Large; needs FCA infrastructure |
| CTOP | Not implemented | Largest gap; most complex TMI |
| MIT/MINIT | Not implemented | Moderate gap |
| EDCT Delivery | `EDCTDelivery.php` (4-channel) | Good; missing FA type |
| EDCT Compliance | `tmi_flight_control` | Needs +/- 5 min validation |
| Reroutes | `tmi_reroutes`, CDRs, playbook | Good foundation; missing amendment workflow |
| Conformance | `tmi_reroute_flights` | Missing NC/OK and EXC states |
| FCA | PostGIS `adl_boundary` | Foundation exists; missing filter infrastructure |
| OpsNet Reasons | `tmi_advisories` reason field | Need structured reason/subreason |

### 10.2 Priority Recommendations

**High (extend GDP/GS):** Full CTL_TYPE taxonomy, EDCT compliance validation, structured OpsNet reasons, conformance states NC/OK + EXC

**Medium (new TMI types):** FCA in PostGIS, MIT definitions, AFP as FCA-based GDP

**Low (advanced):** SCS mechanism, CTOP with TOS/RTC, Adaptive Compression with bridges

### 10.3 Data Model Alignment

| TFMS Concept | PERTI Table | Action |
|--------------|------------|--------|
| CTL_ELEM | `tmi_programs.scope_airport/scope_fca` | Generalize |
| CTL_TYPE | `tmi_flight_control.control_type` | Expand to 17-value taxonomy |
| ASLOT | `tmi_slots.slot_time_utc` | Good match |
| CTD/CTA | `tmi_flight_control.ctd_utc/cta_utc` | Good match |
| IGTD | `adl_flight_times` | May need population |
| BETD | Not present | Add base ETD tracking |
| ERTA | Not present | Add for airline preference |
| MAJOR | Not present | Add for intra-airline swap |
| EX_REASON | `tmi_flight_control.exempt` | Expand boolean to enum |
| EDCT_COMP | `tmi_flight_control.compliance_status` | Exists |
| CONF_STAT | `tmi_reroute_flights.conformance` | Add states |

---

*Note: These are operator/UI reference manuals, not algorithm specification documents. The FSM Algorithms document (`FSM_v8.4_Algorithms.pdf`) would be a better source for the actual GDP/AFP slot assignment algorithm internals.*
