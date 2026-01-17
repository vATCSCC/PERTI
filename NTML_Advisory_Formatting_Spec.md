# NTML and Advisory Formatting Specification

**Source Documents:**
- TMIs.pdf (NTML Guide - vATCSCC internal)
- Advisories_and_General_Messages_v1_3.pdf (FAA Spec)

---

## NTML Entry Formats

### Restriction Entries (MIT/STOP/etc.)

**General Format:**
```
DD/HHMM [location] [direction] via [fix/navaid/airway] [restriction] [qualifier] [options] HHMM-HHMM [requester]:[provider]
```

**Components:**

| Component | Format | Description |
|-----------|--------|-------------|
| Log Time | DD/HHMM | Day and time entry was logged (Zulu) |
| Location | Laa[,Laa...] | Airport(s), facility, or fix |
| Direction | arrivals/departures/via | Traffic direction |
| Route Element | via [fix/navaid/airway] | The route element being restricted |
| Restriction | ##MIT / ##MINIT / STOP / DSP / APRREQ / TBM / CFR / TXT | Restriction type and value |
| Qualifier | AS ONE / EACH / EVERY OTHER / NO STACKS / PER AIRPORT / PER FIX / PER ROUTE / PER STRAT / PER STREAM / SINGLE STREAM | How restriction applies |
| TYPE | TYPE:[ALL/JET/PROP/TURBOPROP] | Aircraft type filter (optional) |
| SPD | SPD:[=/≤/≥]### | Speed restriction (optional) |
| ALT | ALT:[AT/AOB/AOA]### | Altitude restriction (optional) |
| VOLUME | VOLUME:[text] | Volume condition (optional) |
| WEATHER | WEATHER:[reason] | Weather condition (optional) |
| EXCL | EXCL:[facilities] | Exclusions (optional) |
| Valid Time | HHMM-HHMM | Valid period in Zulu (no day prefix) |
| Coordination | [requesting]:[providing] | Facility coordination |

**Examples:**

```
# Arrival restriction with MIT and options
14/1442 JFK arrivals via CAMRN 20MIT NO STACKS TYPE:ALL SPD:≤210 ALT:AOB090 VOLUME:VOLUME EXCL:PHL 2015-2315 N90:ZNY

# Departure restriction per airport
14/1442 EWR,LGA departures via BIGGY 15MIT PER AIRPORT TYPE:JET VOLUME:VOLUME 0030-0145 N90:EWR,LGA

# Ground Stop on airway
14/1443 PHL via J152 STOP TYPE:ALL WEATHER:THUNDERSTORMS EXCL:PNE 0100-0130 ZNY:ZOB
```

### Delay Report Entries (D/D, E/D, A/D)

**Format:**
```
DD/HHMM [delay_type] [prep] [location], [value]/HHMM/## ACFT [options]
```

**Delay Types:**
- `D/D` = Departure Delay (from [departure airport])
- `E/D` = Enroute Delay (for [destination])
- `A/D` = Arrival Delay (to [arrival airport])

**Value Format:** `[+/-]##` or `[+/-]Holding`
- `+##` = XX minutes and increasing
- `-##` = XX minutes and decreasing
- `+Holding` = Entering holding
- `-Holding` = Exiting holding

**Additional Fields:**
- `/HHMM/` = Time of observation (Zulu)
- `/## ACFT` = Number of aircraft affected
- `FIX/NAVAID:[fix]` = Associated fix (optional)
- `VOLUME:[text]` = Volume note (optional)

**Examples:**

```
# Departure delay decreasing
14/1447 D/D from PHL, -60/0215/10 ACFT VOLUME:VOLUME

# Enroute delay entering holding
14/1448 ZDC E/D for ATL, +Holding/0215/8 ACFT FIX/NAVAID:FLASK VOLUME:VOLUME

# Arrival delay increasing  
14/1449 ZFW A/D to DFW, +75/0215/22 ACFT FIX/NAVAID:JEN VOLUME:VOLUME
```

### Airport Configuration Entries

**Format:**
```
DD/HHMM [APT] [WX] ARR:[runways] DEP:[runways] AAR([type]):[rate] [ADR:[rate]] [AAR Adjustment:[note]]
```

**Components:**
- `WX` = IMC or VMC
- `ARR:` = Arrival runway(s), can include approach type (e.g., ILS_31R_VAP_31L, LOC_31, RNAV_X_29)
- `DEP:` = Departure runway(s)
- `AAR(Strat):` = Strategic Arrival Rate
- `AAR(Dyn):` = Dynamic Arrival Rate
- `ADR:` = Airport Departure Rate
- `AAR Adjustment:` = Adjustment note (e.g., XW-TLWD for crosswind/tailwind)

**Examples:**

```
14/1449 EWR VMC ARR:04R/RNAV_X_29 DEP:04L AAR(Strat):40 ADR:38
14/1449 LGA VMC ARR:LOC_31 DEP:31 AAR(Strat):30 ADR:32
14/1449 JFK VMC ARR:ILS_31R_VAP_31L DEP:31L AAR(Strat):58 ADR:24
14/1451 PHL IMC ARR:27R DEP:27L/35 AAR(Dyn):36 AAR Adjustment:XW-TLWD ADR:28
```

---

## Advisory Format (FAA TFMS Spec)

### Message Structure

**Line 1 - Header:**
```
[PREFIX] ADVZY ### [FACILITY/APT] MM/DD/YYYY [ADVISORY_TYPE]
```

Components:
- `[PREFIX]` = ATCSCC, vATCSCC, NAV CANADA, etc.
- `ADVZY ###` = Advisory with sequential number
- `[FACILITY/APT]` = APT/ARTCC or just facility (e.g., JFK/ZNY, DCC)
- `MM/DD/YYYY` = Issue date
- `[ADVISORY_TYPE]` = Type name (see below)

**Advisory Types:**
- CDM GROUND DELAY PROGRAM
- CDM GROUND STOP
- CDM AIRSPACE FLOW PROGRAM
- ACTUAL CTOP
- ROUTE RQD/FL or FCA RQD/FL (Reroute)
- PLAYBOOK ROUTE
- CDR
- SPECIAL OPERATIONS
- OPERATIONS PLAN
- NRP SUSPENSIONS
- VS (SEVERE WEATHER)
- NAT (North Atlantic Tracks)
- SHUTTLE ACTIVITY
- INFORMATIONAL
- MISCELLANEOUS
- [TYPE] CANCELLATION
- CDM PROPOSED [TYPE]
- CDM PROPOSED COMPRESSION

**Line 2 - Valid Time (Optional):**
```
VALID FOR ddhhmm THROUGH ddhhmm.
```

**Lines 3+ - Body:**
Free-form text. **No line exceeding 68 characters.**

Standard fields (vary by type):
- `CTL ELEMENT: [identifier]`
- `ELEMENT TYPE: [ARPT/FCA/CTOP]`
- `ADL TIME: [HHMMZ]`
- `DELAY ASSIGNMENT MODE: [DAS/GAAP/UDP/RBS+]`
- `PROGRAM RATE: [##/HR or rates per period]`
- `IMPACTING CONDITION: [reason]`
- `SCOPE: [description]`
- etc.

**Footer - Effective Time Range:**
```
ddhhmm-ddhhmm
```
(Start to end, 6-digit format each, hyphen separator)

**Signature:**
```
YY/MM/DD HH:MM
```

### Key Constraints

1. **Line Length:** No line exceeding 68 characters (IATA Type B message format)
2. **Times:** All times in Zulu (UTC)
3. **Case:** Generally UPPERCASE for all advisory content
4. **Field Labels:** Colon-terminated (per FAA spec)

---

## GDP Advisory Example

```
vATCSCC ADVZY 002 JFK/ZNY 04/14/2020 CDM GROUND DELAY PROGRAM

CTL ELEMENT: JFK
ELEMENT TYPE: APT
ADL TIME: 1349Z
DELAY ASSIGNMENT MODE: DAS
ARRIVALS ESTIMATED FOR: 14/1415Z - 14/2315Z
CUMULATIVE PROGRAM PERIOD: 14/1415Z - 14/2315Z
PROGRAM RATE: 40/40/40/30/25/20/20/36/54
POP-UP FACTOR: MEDIUM
FLT INCL: 1stTier
FLT INCL: CZY
DEPARTURE SCOPE: 1200
ADDITIONAL DEP FACILITIES INCLUDED: KATL
EXEMPT DEP FACILITIES: KORD
CANADIAN ARPTS INCLUDED: CYYZ
DELAY ASSIGNMENT TABLE APPLIES TO: ZNY
DELAY LIMIT: 240
MAXIMUM DELAY: 171
AVERAGE DELAY: 38
IMPACTING CONDITION: WEATHER / THUNDERSTORMS
COMMENTS: COMMENTS COMMENTS COMMENTS
141415-142315
20/04/14 13:49
```

## Ground Stop Advisory Example

```
vATCSCC ADVZY 003 DFW/ZFW 04/14/2020 CDM GROUND STOP

CTL ELEMENT: DFW
ELEMENT TYPE: APT
ADL TIME: 1354Z
GROUND STOP PERIOD: 14/1430Z - 14/1630Z
CUMULATIVE PROGRAM PERIOD: 14/1430Z - 14/1630Z
FLT INCL: (Manual) ZHU ZJX ZMA ZME ZTL
ADDITIONAL DEP FACILITIES INCLUDED: KDEN
CURRENT TOTAL, MAXIMUM, AVERAGE DELAYS: 1240/414/81
PREVIOUS TOTAL, MAXIMUM, AVERAGE DELAYS: 636/211/70
NEW TOTAL, MAXIMUM, AVERAGE DELAYS: 1876/625/151
PROBABILITY OF EXTENSION: MEDIUM
IMPACTING CONDITION: EQUIPMENT / STARS
COMMENTS: BLAH
141430-141630
20/04/14 13:54
```

## Reroute Advisory Example

```
vATCSCC ADVZY 004 DCC 04/14/2020 FCA RQD/FL
NAME: NO_J75_3_PARTIAL
IMPACTED AREA: ZDC
REASON: WEATHER / THUNDERSTORMS
INCLUDE TRAFFIC: KBOS DEPARTURES TO KMCO
VALID: FCA ENTRY TIME FROM 142030 TO 150230
FACILITIES INCLUDED: ALL_FLIGHTS
PROBABILITY OF EXTENSION: MEDIUM
REMARKS:
ASSOCIATED RESTRICTIONS:
MODIFICATIONS:
ROUTE:
ORIG    DEST    ROUTE
---ZBW  ---MCO  >GONZZ Q29 DORET DJB J84 SPA J85 TWINS JEFOI SHEMP< BUGGZ4

TMI ID: RRDCC004
142030-150230
20/04/14 14:36
```

### Route Protected Segments

In route strings, mandatory route segments are enclosed in `>` and `<`:
```
>GONZZ Q29 DORET DJB J84 SPA J85 TWINS JEFOI SHEMP< BUGGZ4
```
- Text between `>` and `<` is the protected/required segment
- SIDs and STARs are NOT enclosed in the protected segment markers

---

## MIT Restriction Types Reference

| Type | Description |
|------|-------------|
| ##MIT | Miles-in-trail separation (nautical miles) |
| ##MINIT | Minutes-in-trail separation |
| STOP | Ground stop |
| DSP | Departure Spacing Program |
| APRREQ | Approval Request |
| TBM | Time-Based Management |
| CFR | Call-for-Release |
| TXT | Free text restriction |

## MIT Qualifier Reference

| Qualifier | Description |
|-----------|-------------|
| AS ONE | Traffic treated as single group |
| EACH | Apply to each aircraft |
| EVERY OTHER | Apply to every other aircraft |
| NO STACKS | No holding stacks |
| PER AIRPORT | Separate restriction per airport |
| PER FIX | Separate restriction per fix |
| PER ROUTE | Separate restriction per route |
| PER STRAT | Separate restriction per strategy |
| PER STREAM | Separate restriction per traffic stream |
| SINGLE STREAM | Single traffic stream only |

---

## Implementation Notes for TMIDiscord.php

1. **68-character line limit** must be enforced for all advisory text
2. **Discord code blocks** should wrap advisory text for proper formatting
3. **NTML entries** should be formatted as shown in examples above
4. **Signature timestamps** use `YY/MM/DD HH:MM` format
5. **Valid time ranges** in footer use `ddhhmm-ddhhmm` format
6. **Field labels** use colon termination (e.g., `CTL ELEMENT:`)
