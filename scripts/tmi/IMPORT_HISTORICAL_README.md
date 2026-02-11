# TMI Historical Import Tool

## Overview

Import historical TMI (Traffic Management Initiative) data from Discord-exported text files into the `VATSIM_TMI` Azure SQL database. The data comes from two Discord channels where controllers logged NTML entries and advisories from 2020 through 2026.

**Branch**: `feature/tmi-import` (worktree at `C:/Temp/perti-worktrees/tmi-import`)

**Status**: Parser V1 built and tested for TFMS-format Discord code blocks. **Needs significant rework** to handle the actual data formats described below.

## Source Data Files

Both files live at:
```
C:\Users\jerem.DESKTOP-T926IG8\OneDrive - Virtual Air Traffic Control System Command Center\
Documents - Virtual Air Traffic Control System Command Center\DCC\
```

### 1. `NTML_2020.txt` (9,579 lines, ~470KB)

**Content**: NTML log entries — MIT, MINIT, STOP, CONFIG, CFR, APREQ, TBM, D/D, E/D, A/D, Holding, CANCEL, Planning, and misc entries.

**Date range**: April 2020 through February 2026 (despite the filename).

**Overall structure**: Alternating header lines and entry lines.

#### Header Line Format
```
AuthorName | Facility Rating — MM/DD/YYYY HH:MM
```
Examples:
```
Jeremy P | ZNY C1 — 04/17/2020 19:45
Simon H | ZTL C1 — 02/08/2024 18:17
Matt B | ZJX C3 — 02/09/2024 18:29
Dean V | ZHU DATM
```
Note: The `—` is an em-dash (U+2014), which may appear as `�` in some encodings. Some later header lines are split across 2-3 lines (author on one line, blank, then `— date` on next).

The date in the header is the **local time** when the Discord message was posted. It is NOT UTC — the actual UTC timestamps are embedded in each entry line.

#### Entry Line Formats

All entry lines start with `DD/HHMM` (day/UTC time).

##### MIT (Miles-in-Trail)
```
DD/HHMM    [airport(s)] via [fix(es)] [value]MIT [qualifiers] [REASON:cause] EXCL:[exclusions] [start-end] [req:prov]
```
Examples:
```
17/2344    BOS via MERIT 15MIT VOLUME:VOLUME EXCL:NONE 2345-0000 ZBW:ZNY
24/2305    MIA via CIGAR 35MIT VOLUME:VOLUME EXCL:NONE 2300-0400 ZMA:ZJX30
22/2340    ORD via ZZIPR 20MIT TYPE:JET VOLUME:VOLUME EXCL:NONE 2300-0300 ZAU:ZMP
28/0121    EWR via FLOSI 20MIT SPD:=250 VOLUME:VOLUME EXCL:NONE 0100-0400 N90:ZBW
03/1556    DCA via ALL 30MIT PER STREAM VOLUME:VOLUME EXCL:NONE 2300-0400 ZDC:ZBW,ZID,ZJX,ZNY,ZOB,ZTL
24/2313    MIA 30MIT PER AIRPORT VOLUME:VOLUME EXCL:NONE 2300-0400 ZNY:N90,PHL,EWR,JFK,LGA,ISP
27/2256    EWR 30MIT VOLUME:VOLUME EXCL:NONE 2300-0400 ZNY:ZDC
09/2345 JAX via DUCHY, ICONS 20 MIT JETS 2345-0330 ZJX:ZTL,CLT
```
Variations:
- `via` may be absent: `EWR 30MIT ...`
- Fix may be `ALL`, `ALL FIXES`, `THROUGH ZLC`, or sector-specific: `ZDC18/19`
- Qualifiers before MIT: `TYPE:JET`, `TYPE:ALL`, `JETS`, `PROPS`
- Qualifiers after MIT: `PER AIRPORT`, `PER STREAM`, `PER ROUTE`, `AS ONE`, `SINGLE STREAM`, `NO STACKS`, `NO COMP`, `EACH`, `EACH FIX`
- Speed restrictions embedded: `SPD:=250`, `SPD:250KT`
- Altitude restrictions: `ALT:AOB300`, `AOB FL230`, `AOB FL280`
- `arrivals` or `departures` qualifiers: `EWR arrivals via FLOSI`, `BOS departures 40MIT`
- MIT value may have space: `20 MIT` or no space: `20MIT`
- Providing facility may include sectors: `ZJX30`, `ZJX35/52`, `ZJX33`
- 2024+ entries sometimes omit `REASON:` and `EXCL:` fields
- Some 2024+ entries have trailing codes: `$ 05B01A`

##### MINIT (Minutes-in-Trail)
```
DD/HHMM    [airport] [value]MINIT [REASON:cause] EXCL:[exclusions] [start-end] [req:prov]
```
Examples:
```
17/2350    BOS 8MINIT VOLUME:VOLUME EXCL:NONE 2330-0300 ZBW:CZY
25/0100    BOS 8MINIT VOLUME:VOLUME EXCL:NONE 0100-0300 ZOB:CZY
```

##### STOP (Ground Stop / Route Stop)
```
DD/HHMM    [airport(s)] [departures] STOP [REASON:cause] EXCL:[exclusions] [start-end] [req:prov]
DD/HHMM    [airport(s)] via [fix] STOP [REASON:cause] EXCL:[exclusions] [start-end] [req:prov]
DD/HHMM    [facility] departures via [route description] STOP [REASON:cause] EXCL:[exclusions] [start-end] [req:prov]
```
Examples:
```
17/2349    BOS STOP VOLUME:VOLUME EXCL:NONE 2345-0015 ZNY:PHL
18/0012    BOS STOP VOLUME:VOLUME EXCL:NONE 0000-0100 ZBW:CZY
18/2350    ATL departures STOP VOLUME:VOLUME EXCL:NONE 2345-0100 ZDC:PCT
29/2322    PHL departures via PTW STOP WEATHER:THUNDERSTORMS EXCL:NONE 2300-0030 ZNY:PHL
25/0114    MIA STOP RUNWAY:CONFIG CHG EXCL:NONE 0115-0130 ZMA:ZJX
25/0127    MIA via SSCOT STOP VOLUME:VOLUME EXCL:NONE 0130-0145 ZMA:ZJX
29/2347    EWR+SATS departures via WEST & NORTH GATES STOP WEATHER:THUNDERSTORMS EXCL:COATE,NEION 2345-0030 ZNY:N90
```

##### CONFIG (Airport Configuration)
```
DD/HHMM    [airport]    [weather]    ARR:[runways] DEP:[runways]    AAR(type):value    ADR:value
```
Examples:
```
18/2221    ATL    VMC    ARR:26R/27L/28 DEP:26L/27R    AAR(Strat):132    ADR:70
24/1851    MIA    VMC    ARR:09/12 DEP:08L/08R    AAR(Dyn):66 AAR Adjustment:OTHER    ADR:72
01/2217    BOS    LVMC    ARR:27/22L DEP:22R/22L    AAR(Dyn):43 AAR Adjustment:OTHER    ADR:48
22/2356    DTW    IMC    ARR:04L/03R DEP:03L    AAR(Dyn):64 AAR Adjustment:CLSD RWY/TWY    ADR:32
16/2214    KDFW    VMC    ARR:36L/35R DEP:36R/35C    AAR(Strat):80 ADR:96 $ 01A00A
```
Notes:
- Weather: VMC, LVMC, IMC, LIMC
- AAR types: `AAR(Strat)`, `AAR(Dyn)`, sometimes with `AAR Adjustment:OTHER` or `AAR Adjustment:CLSD RWY/TWY`
- Airport may use FAA code (3-letter: ATL) or ICAO (4-letter: KDFW)
- Some entries have `$ 01A00A` suffix codes

##### D/D (Departure Delay Report)
```
DD/HHMM     D/D from [airport], +/-[minutes]/[time] [REASON:cause]
DD/HHMM     D/D from [airport], +/-[minutes]/[time]/[count] ACFT [REASON:cause]
```
Examples:
```
18/0010     D/D from JFK, +45/0010 VOLUME:VOLUME
18/0108     D/D from JFK, -30/0108 VOLUME:VOLUME
25/0100     D/D from ATL, +15/0056 VOLUME:VOLUME
13/0129     D/D from DEN, +15/0126 VOLUME:MULTITAXI
13/0133     D/D from DEN, +30/2133/1 ACFT VOLUME:MULTITAXI
```
Notes: `+` means delay increasing, `-` means decreasing. Time after `/` is when the delay was measured. Optional `/N ACFT` means N aircraft affected.

##### E/D (Expected Delay / Airborne Delay)
```
DD/HHMM    [facility] E/D for [airport], +/-[Holding|minutes]/[time][/count ACFT] [NAVAID:fix] [REASON:cause]
```
Examples:
```
18/0019    ZDC E/D for BOS, +30/0019/13 ACFT VOLUME:VOLUME
18/0042    ZDC E/D for BOS, -Holding/0042/13 ACFT VOLUME:VOLUME
25/0059    ZJX66 A/D to MIA, +Holding/0058 NAVAID:OMN STREAM VOLUME:VOLUME
25/0116    ZTL E/D for MIA, +Holding/0114 VOLUME:VOLUME
25/0143    ZJX E/D for MIA, +15/0143/4 ACFT NAVAID:ENDEW VOLUME:VOLUME
25/0146    ZJX E/D for MIA, +15/0146 NAVAID:ARS VOLUME:VOLUME
25/0150    ZNY A/D to JFK, -Holding/0150 NAVAID:CAMRN OTHER:OTHER
```
Notes: `E/D` = Expected Delay, `A/D` = Airborne Delay (same format). `+Holding` means holding is in effect; `-Holding` means holding released.

##### CFR (Call For Release)
```
DD/HHMM    CFR [airport(s)] departures  [TYPE:type] [REASON:cause] EXCL:[exclusions] [start-end] [req:prov]
```
Examples:
```
18/0040    CFR BOS departures  VOLUME:VOLUME EXCL:NONE 0045-0300 ZNY:N90,JFK,EWR,LGA,PHL
24/2140    CFR MIA,FLL,RSW departures  TYPE:ALL VOLUME:VOLUME EXCL:NONE 2100-0400 ZMA:F11
22/2347    CFR MSP,DTW departures  VOLUME:VOLUME EXCL:ORD,MDW 2300-0300 ZAU:ZAU
```

##### APREQ (Approval Request)
```
DD/HHMM    APREQ [airport(s)] departures [via fix] [REASON:cause] EXCL:[exclusions] [start-end] [req:prov]
DD/HHMM    APREQ [airport] to [destinations] [start-end] [req:prov]
```
Examples:
```
18/2338    APREQ ATL departures via BOBZY VOLUME:VOLUME EXCL:NONE 2330-0100 ZTL:CLT
24/2210    APREQ JFK,EWR,LGA via J220  TYPE:ALL VOLUME:VOLUME EXCL:NONE 2330-0400 ZNY:ZDC
09/2359 APREQ JAX to PNS, MYR, DAB 2359-0330 ZJX:JAX
```

##### TBM (Time-Based Metering)
```
DD/HHMM    [airport] TBM [name] [REASON:cause] EXCL:[exclusions] [start-end] [req:prov]
DD/HHMM    [airport] TBM [REASON:cause] EXCL:[exclusions] [start-end] [req:prov]
```
Examples:
```
18/2206    ATL TBM 3_WEST VOLUME:VOLUME EXCL:NONE 2230-0400 ZTL:ZJX,ZME,ZID,ZHU
17/2312    ATL TBM VOLUME:VOLUME EXCL:NONE 2300-0400 ZTL:ZJX,ZME,ZID,ZHU
```

##### Planning Entry
```
DD/HHMM    TYPE:Planning TIME:[time] REASON:[reason] vATCSCC_FACILITATORS:[names]    CENTERS:[list] TRACONS:[list] TOWERS:[list] AIRLINES:[list] AIRPORT_AUTHORITIES:[list] REGIONS:[list]
```
Example:
```
12/2334    TYPE:Planning TIME:2300 REASON:FNO vATCSCC_FACILITATORS:NA    CENTERS:ZLC,ZDV,ZSE TRACONS:NONE TOWERS:NONE AIRLINES:NONE AIRPORT_AUTHORITIES:NONE REGIONS:USA5
```

##### CANCEL
```
DD/HHMM  [airport] CANCEL ALL MIT [req:prov]
DD/HHMM  [airport] CANCEL ALL TMI [req:prov(s)]
```
Examples:
```
11/0330  LAS CANCEL ALL MIT ZLA:ZOA
10/0313 CANCEL ALL TMI ZJX: ZTL, ZDC, ZHU, ZMA
```

##### Miscellaneous / Speed Restrictions / Altitude Restrictions
```
08/2227    CAP GSO LTFC via TRAKS AOB FL230 OTHER:OTHER 0000-0300 ZTL:ZTL
12/2327    ALL NON-RVSM ACFT THROUGH ZLC MUST BE AOB FL280 VOLUME:VOLUME EXCL:NONE 0000-0500 ZLC:ZLA,ZOA,ZSE,ZDV,ZMP
06/2306    GSO via HENBY SPD:250KT VOLUME:VOLUME 0200-0215 GSO:ZDC
```

#### Format Variations Over Time

| Period | Authors | Format Notes |
|--------|---------|-------------|
| 2020 | Jeremy P only | Consistent format, always has `VOLUME:VOLUME` or `WEATHER:cause`, `EXCL:`, time range, req:prov |
| 2022-2023 | Jeremy P, others join | Same format, more authors |
| 2024+ | Many authors (Simon H, Matt B, Joshua D, Dean V, Brody B, Vi, Zackaria, etc.) | Some entries omit `REASON:` and `EXCL:` fields. Some have `$ CODE` suffixes. Category headers appear ("MIT / MINIT", "Airport Configuration"). Author lines sometimes split across multiple lines. |
| 2026 | Multiple authors | Most relaxed format — some entries have no REASON or EXCL |

#### Noise / Non-Entry Lines

These lines appear in the file but are NOT NTML entries:
```
MIT over Slate run, what's new?!              ← Chat message (line 286)
disregard bot^                                 ← Chat message (line 5060)
MIT / MINIT                                    ← Category header (line 5042)
Airport Configuration                          ← Category header (line 5083)
APP                                            ← App name in author block (line 5043)
(blank lines and lines with only whitespace)   ← Throughout
```

---

### 2. `ADVZY_2020.txt` (21,092 lines, ~1MB)

**Content**: vATCSCC advisory messages — routes, SWAP plans, operations plans, ground stops, hotline activations, informational messages, GDP/AFP programs.

**Date range**: February 2020 through February 2026.

**Overall structure**: Header line (author/date) → Advisory text block → Footer (time range + timestamp) → Blank line separator.

#### Header Line
Same format as NTML file:
```
AuthorName | Facility Rating — MM/DD/YYYY HH:MM
```

#### Advisory Types

Each advisory starts with:
```
vATCSCC ADVZY ### [ELEMENT] MM/DD/YYYY [TYPE]
```
Where:
- `###` = sequential advisory number (resets daily)
- `[ELEMENT]` = `DCC` (system-wide), `[airport]/[center]` (e.g., `KJFK/ZNY`, `LGA/ZNY`, `DCA`), or other
- `[TYPE]` = one of the types below

| Type | Description | Frequency |
|------|-------------|-----------|
| `ROUTE RQD` | Required reroute | Very common |
| `ROUTE PLN` | Planned reroute (not yet active) | Common |
| `ROUTE REQ` / `ROUTE REQ /FL` | Required route (variant) | 2026+ |
| `INFORMATIONAL` | General information | Common |
| `OPERATIONS PLAN` | Ops plan for an event | Common |
| `CDM GROUND STOP` | Ground stop program | Common |
| `CDM GS CNX` | Ground stop cancellation | Moderate |
| `CDM GROUND DELAY PROGRAM` | GDP | Rare in this dataset |
| `*_HOTLINE` / `*_HOTLINE_FYI` | Hotline activation/termination | Common |
| `*_SWAP IMPLEMENTATION PLAN_FYI` | SWAP plan | Moderate |
| `SWAP IMPLEMENTATION PLAN` | SWAP plan (no area prefix) | Moderate |
| `EXPECTED ARRIVAL DELAY` | Arrival delay advisory | Occasional |

#### Route Advisory Format (ROUTE RQD / ROUTE PLN / ROUTE REQ)
```
vATCSCC ADVZY ### DCC MM/DD/YYYY ROUTE RQD
NAME: [route_name]
IMPACTED AREA: [facility]             (2020-2023)
CONSTRAINED AREA: [facility]          (2024+, same meaning)
REASON: [reason]
INCLUDE TRAFFIC: [description]
VALID: ETD [ddHHmm] TO [ddHHmm]
FACILITIES INCLUDED: [facility list]
PROBABILITY OF EXTENSION: [LOW/MEDIUM/HIGH]
REMARKS: [text]
ASSOCIATED RESTRICTIONS: [text]
MODIFICATIONS: [text]
ROUTE:                                (2020-2023)
ROUTES:                               (2024+, same meaning)
ORIG    DEST    ROUTE
----    ----    -----
JFK     PIT     DEEZZ5 >CANDR J60 PSB< HAYNZ6
EWR LGA PIT     >NEWEL J60 PSB< HAYNZ6
PHL     PIT     >PTW SARAA DANNR J60 PSB< HAYNZ6

TMI ID: RRDCC###
[ddHHmm]-[ddHHmm]
YY/MM/DD HH:MM
```

Notes:
- Route strings use `>` and `<` to delimit the mandatory segment
- Multi-origin routes put origins on same line: `EWR LGA PIT`
- Routes may span multiple lines (wrapped with leading whitespace)
- Some routes have qualifiers: `(NON-RNAV TYPE:PROPS)`, `(NON-RNAV OPTION 1)`
- Exclusion airports may appear as `-FMY -PIE` on separate lines
- Later (2026) uses `CONSTRAINED AREA:` instead of `IMPACTED AREA:`, and `ROUTES:` instead of `ROUTE:`, and adds `FLIGHT STATUS: ALL_FLIGHTS`

#### Ground Stop Format (CDM GROUND STOP / CDM GS CNX)
```
vATCSCC ADVZY ### [airport]/[center] MM/DD/YYYY CDM GROUND STOP
CTL ELEMENT: [airport]
ELEMENT TYPE: APT
ADL TIME: [HHmm]Z
GROUND STOP PERIOD: DD/HHMMZ - DD/HHMMZ
CUMULATIVE PROGRAM PERIOD: DD/HHMMZ - DD/HHMMZ
FLT INCL: [ALL_FLIGHTS | description]
DEP FACILITIES INCLUDED: [tier info] [facility list]
PROBABILITY OF EXTENSION: [LOW/MEDIUM/HIGH]
IMPACTING CONDITION: [condition] / [detail]
COMMENTS: [text]

[ddHHmm]-[ddHHmm]
YY/MM/DD HH:MM
```

Cancellation:
```
vATCSCC ADVZY ### [airport] MM/DD/YYYY CDM GS CNX
CTL ELEMENT: [airport]
ELEMENT TYPE: APT
ADL TIME: [HHmm]Z
GS CNX PERIOD: DD/HHMMZ - DD/HHMMZ
FLIHTS MAY RECEIVE NEW EDCTS DUE TO AN ACTIVE AFP:
COMMENTS: [text]
[ddHHmm]-[ddHHmm]
YY/MM/DD HH:MM
```
Note: "FLIHTS" is a typo in the actual data (should be "FLIGHTS").

#### Operations Plan Format
```
vATCSCC ADVZY ### DCC MM/DD/YYYY OPERATIONS PLAN
EVENT TIME: [ddHHmm] - AND LATER
___________________________________________________________________
[free text describing operational goals and constraints]
___________________________________________________________________

TERMINAL ACTIVE:
[entries or NONE]

TERMINAL PLANNED:
[entries or NONE]

TERMINAL CONSTRAINTS:
[entries or NONE]

ACTIVE ROUTES:
[entries or NONE]

PLANNED ROUTES:
[entries or NONE]

ENROUTE CONSTRAINTS:
[entries or NONE]

ATC STATUS:
[entries or NONE]

NEXT PLANNING WEBINAR: [time or NA]
[ddHHmm]-AND LATER
YY/MM/DD HH:MM
```

#### Hotline Format
```
vATCSCC ADVZY ### DCC MM/DD/YYYY [AREA] HOTLINE[_FYI]
VALID FOR [ddHHmm] THROUGH [ddHHmm]
[or: EVENT TIME: dd/HHmm - dd/HHmm]
CONSTRAINED FACILITIES: [facility list]
[free text about hotline activation/termination]

[ddHHmm] - [ddHHmm]
YY/MM/DD HH:MM
```

#### SWAP Format
```
vATCSCC ADVZY ### DCC MM/DD/YYYY [AREA] SWAP IMPLEMENTATION PLAN[_FYI]
VALID FOR [ddHHmm] THROUGH [ddHHmm]
CONSTRAINED FACILITIES: [facility]
[extensive free text about expected impacts, alternate routes, etc.]

[ddHHmm] - [ddHHmm]
YY/MM/DD HH:MM
```

#### Informational Format
```
vATCSCC ADVZY ### DCC MM/DD/YYYY INFORMATIONAL
VALID FOR [ddHHmm] THROUGH [ddHHmm]
[free text]

[ddHHmm] - [ddHHmm]
YY/MM/DD HH:MM
```

#### Footer / Timestamp
All advisories end with:
```
[ddHHmm]-[ddHHmm]          (validity period, no spaces or with spaces)
YY/MM/DD HH:MM              (creation timestamp)
```
Or for open-ended:
```
[ddHHmm]-AND LATER
```

#### Noise / Non-Advisory Lines

```
(Notification: @unknown-role)          ← Discord notification (line 330)
?                                       ← Stray characters (lines 20916, 20953, etc.)
(blank lines)                           ← Throughout
```

---

## Current Implementation

### Files Created

#### `scripts/tmi/import_historical.php` (985 lines)

Main import script. Currently handles **TFMS-format Discord code block** entries (the format used for live TMI operations), NOT the historical compact formats in the data files.

**Input**: JSON via POST or stdin
```json
{
  "entries": ["```\nATCSCC ADVZY 003...\n```", ...],
  "dry_run": true,
  "force": false,
  "created_by": "12345"
}
```
Or:
```json
{
  "raw": "full paste with ``` code blocks",
  "dry_run": true
}
```

**Key functions**:

| Function | Purpose | Status |
|----------|---------|--------|
| `cleanDiscordMessage()` | Strip ``` markers, staging prefix | Works |
| `splitRawPaste()` | Split raw text into entries | Works for code blocks |
| `detectEntryType()` | Pattern-match TFMS header | Needs rewrite for actual formats |
| `parseEntry()` | Extract fields from TFMS format | Needs rewrite for actual formats |
| `parseKeyValueLines()` | Extract `KEY...: VALUE` pairs | Works for TFMS dot-separator format |
| `parseTfmsTime()` | Parse `ddHHmm` time with base date | Works |
| `insertProgram()` | Insert `tmi_programs` row | Works (untested with real DB) |
| `insertAdvisory()` | Insert `tmi_advisories` row | Works (untested with real DB) |
| `insertNtmlEntry()` | Insert `tmi_entries` row | Works (untested with real DB) |
| `insertReroute()` | Insert `tmi_reroutes` + `tmi_reroute_routes` | Works (untested with real DB) |
| `checkDuplicate()` | Dedup check | Works |
| `buildSubject()` | Generate subject line | Works |

**What needs to change**: The `splitRawPaste()`, `detectEntryType()`, `parseEntry()`, and `parseKeyValueLines()` functions all assume TFMS advisory format with dot-separated key-value lines. The actual data uses two completely different formats.

#### `scripts/tmi/import_historical_test.php` (231 lines)

Test harness with 8 sample TFMS-format entries. All 8 pass. Tests:
1. Ground Stop (GS)
2. Ground Delay Program (GDP)
3. Miles-in-Trail (MIT)
4. Reroute (REROUTE)
5. General ATCSCC message (ATCSCC)
6. Cancellation (CNX)
7. Airspace Flow Program (AFP)
8. Ground Stop with staging prefix (GS)

**Needs**: New test cases using actual data samples from both files.

---

## What Needs to Be Built

### Phase 1: NTML Parser (`parseNtmlEntry()`)

A new parser function for the compact NTML shorthand format. Must handle:

1. **Entry splitter**: Split the file by header lines (`AuthorName | Facility — date`) to group entries. Each group may contain 1+ entry lines.

2. **Header parser**: Extract author name, facility, date from header line. Handle multi-line headers (2024+).

3. **Entry line parser**: Parse the `DD/HHMM` prefix and classify the entry type:
   - MIT: contains `[N]MIT` (with or without space before MIT)
   - MINIT: contains `[N]MINIT`
   - STOP: contains ` STOP ` (as word, not in "THUNDERSTORMS")
   - CONFIG: contains `VMC`/`IMC`/`LVMC`/`LIMC` and `ARR:`/`DEP:`
   - D/D: contains `D/D from`
   - E/D: contains `E/D for` or `A/D to`
   - CFR: starts with or contains `CFR`
   - APREQ: starts with or contains `APREQ`
   - TBM: contains `TBM`
   - CANCEL: contains `CANCEL ALL`
   - Planning: contains `TYPE:Planning`
   - Noise: chat messages, category headers, etc. (skip)

4. **Field extraction per type**: Each type has different field positions. Key fields to extract:
   - `airport(s)` — the controlled element
   - `via fix(es)` — the measurement point / restriction fix
   - `value` + `unit` (MIT/MINIT) — restriction value
   - Qualifiers: `TYPE:JET`, `PER AIRPORT`, `AS ONE`, etc.
   - `REASON:cause` — impacting condition
   - `EXCL:exclusions` — exemptions
   - `start-end` — validity period (4-digit `HHMM-HHMM`)
   - `req:prov` — requesting and providing facilities

5. **Date context**: The `DD/HHMM` in entry lines gives day and UTC time but no month/year. Month/year must be inferred from the preceding header line's date. Handle month rollover (e.g., header is 04/24/2020 but entry starts with `25/0100`).

### Phase 2: ADVZY Parser (`parseAdvzyEntry()`)

A new parser for the vATCSCC advisory format. Must handle:

1. **Entry splitter**: Split the file by Discord header lines. Each header-to-header block contains one advisory.

2. **Advisory header parser**: Extract advisory number, element, date, type from:
   ```
   vATCSCC ADVZY ### [ELEMENT] MM/DD/YYYY [TYPE]
   ```

3. **Type-specific field extraction**:
   - **Route advisories**: NAME, IMPACTED AREA/CONSTRAINED AREA, REASON, INCLUDE TRAFFIC, VALID, FACILITIES INCLUDED, PROBABILITY, REMARKS, ASSOCIATED RESTRICTIONS, MODIFICATIONS, route table (ORIG/DEST/ROUTE), TMI ID
   - **Ground stops**: CTL ELEMENT, ELEMENT TYPE, ADL TIME, GS PERIOD, FLT INCL, DEP FACILITIES, PROBABILITY, IMPACTING CONDITION, COMMENTS
   - **GS cancellations**: CTL ELEMENT, GS CNX PERIOD, COMMENTS
   - **Ops plans**: EVENT TIME, free text sections (TERMINAL ACTIVE/PLANNED/CONSTRAINTS, ROUTES, ENROUTE, ATC STATUS)
   - **Hotlines**: EVENT TIME/VALID FOR, CONSTRAINED FACILITIES, body text
   - **SWAP plans**: VALID FOR, CONSTRAINED FACILITIES, body text
   - **Informational**: VALID FOR, body text

4. **Route table parser**: Parse the tabular ORIG/DEST/ROUTE format, handling:
   - Multi-origin on same line: `EWR LGA PIT`
   - Route continuation lines (indented)
   - Exclusion lines: `-FMY -PIE`
   - Qualifiers in parens: `(NON-RNAV TYPE:PROPS)`

5. **Time parsing**: Handle multiple time formats:
   - `ETD ddHHmm TO ddHHmm` (VALID field)
   - `dd/HHmmZ - dd/HHmmZ` (GS periods)
   - `ddHHmm-ddHHmm` (footer)
   - `ddHHmm - ddHHmm` (footer with spaces)
   - `ddHHmm-AND LATER` (open-ended)
   - `YY/MM/DD HH:MM` (creation timestamp)

### Phase 3: Database Mapping

Map parsed data to the correct TMI tables:

| Source Type | Target Table(s) | Key Fields |
|-------------|-----------------|------------|
| MIT/MINIT (NTML) | `tmi_entries` | entry_type, ctl_element, restriction_value, restriction_unit, condition_text (fix), reason_code, valid_from/until, requesting/providing_facility |
| STOP (NTML) | `tmi_entries` | entry_type='STOP', ctl_element, reason_code, valid_from/until |
| CONFIG (NTML) | `tmi_entries` | entry_type='CONFIG', ctl_element, parsed_data JSON with weather/runways/rates |
| D/D (NTML) | `tmi_entries` | entry_type='DELAY', ctl_element, parsed_data JSON |
| E/D / A/D (NTML) | `tmi_entries` | entry_type='DELAY', ctl_element, parsed_data JSON |
| CFR (NTML) | `tmi_entries` | entry_type='APREQ', subtype=CFR |
| APREQ (NTML) | `tmi_entries` | entry_type='APREQ' |
| TBM (NTML) | `tmi_entries` | entry_type='MISC', subtype=TBM |
| CANCEL (NTML) | `tmi_entries` | entry_type='MISC', subtype=CANCEL |
| ROUTE RQD/PLN (ADVZY) | `tmi_advisories` + `tmi_reroutes` + `tmi_reroute_routes` | advisory_type='REROUTE', reroute_name, route_string, origin/dest per route |
| CDM GROUND STOP (ADVZY) | `tmi_programs` + `tmi_advisories` | program_type='GS', advisory_type='GS' |
| CDM GS CNX (ADVZY) | `tmi_advisories` | advisory_type='GS', status='CANCELLED' |
| OPERATIONS PLAN (ADVZY) | `tmi_advisories` | advisory_type='OPERATIONS PLAN' |
| HOTLINE (ADVZY) | `tmi_advisories` | advisory_type='INFORMATIONAL' |
| SWAP (ADVZY) | `tmi_advisories` | advisory_type='INFORMATIONAL' or 'VS' |
| INFORMATIONAL (ADVZY) | `tmi_advisories` | advisory_type='INFORMATIONAL' |

All imported records:
- `source_type = 'IMPORT'`
- `status = 'EXPIRED'` (advisories/entries) or `'COMPLETED'` (programs)
- `created_at` = entry's effective_from time (preserves historical ordering)

### Phase 4: Validation & Error Report

Before importing, run dry_run on both files and report:
- Entries that fail to parse (with line numbers and preview text)
- Entries with ambiguous types
- Entries that look like chat messages / noise
- Date inconsistencies (month rollover issues)
- Missing required fields
- Duplicate detection against existing DB records

---

## Known Data Issues to Watch For

1. **Multi-line author headers** (2024+): Author name on one line, blank, then `— date` on next
2. **Chat messages mixed in**: "MIT over Slate run, what's new?!", "disregard bot^"
3. **Category headers**: "MIT / MINIT", "Airport Configuration", "APP"
4. **Stray `?` characters** in ADVZY file (lines 20916, 20953, etc.)
5. **Discord notifications**: `(Notification: @unknown-role)`
6. **Encoding issues**: Em-dash `—` appears as `�` in some contexts
7. **Inconsistent spacing**: Some entries have 2 spaces, some 4, some tabs between fields
8. **Trailing codes**: `$ 05B01A` on some 2024+ entries (meaning unknown — possibly bot metadata)
9. **Month rollover**: Entry `25/0100` under header dated `04/24/2020` means April 25, 0100Z
10. **Year rollover**: Entries near December 31 / January 1 boundaries
11. **Typos in actual data**: "FLIHTS" instead of "FLIGHTS", "RERTES" instead of "ROUTES"
12. **Field name evolution**: `IMPACTED AREA` → `CONSTRAINED AREA`, `ROUTE:` → `ROUTES:`
13. **Open-ended time ranges**: `021500-AND LATER`
14. **Multi-line route entries**: Routes that wrap to next line with indentation
15. **Providing facility with sector numbers**: `ZJX30`, `ZJX35/52`, `ZJX33`

---

## Deployment Plan

1. Upload via Kudu VFS API:
```bash
curl -X PUT -u '$vatcscc:password' \
  --data-binary @scripts/tmi/import_historical.php \
  -H 'If-Match: *' \
  https://vatcscc.scm.azurewebsites.net/api/vfs/site/wwwroot/scripts/tmi/import_historical.php
```

2. Execute via public HTTP:
```bash
curl -X POST https://perti.vatcscc.org/scripts/tmi/import_historical.php \
  -H 'Content-Type: application/json' \
  -d '{"entries": [...], "dry_run": true}'
```

3. Cleanup:
```bash
curl -X DELETE -u '$vatcscc:password' \
  -H 'If-Match: *' \
  https://vatcscc.scm.azurewebsites.net/api/vfs/site/wwwroot/scripts/tmi/import_historical.php
```

---

## Database Schema Quick Reference

### `tmi_entries` (NTML log)
```sql
entry_id INT IDENTITY PK
entry_guid UNIQUEIDENTIFIER DEFAULT NEWID()
determinant_code VARCHAR(20)      -- MIT, MINIT, DELAY, CONFIG, APREQ, CONTINGENCY, MISC, REROUTE
protocol_type INT DEFAULT 1
entry_type VARCHAR(20)            -- Same as determinant_code
ctl_element VARCHAR(10)           -- Airport/facility code
element_type VARCHAR(10)          -- APT, ARTCC, FCA, etc.
requesting_facility VARCHAR(10)
providing_facility VARCHAR(50)    -- Can be comma-separated
restriction_value INT
restriction_unit VARCHAR(10)      -- NM, MIN
condition_text VARCHAR(200)       -- Fix name, or descriptive text
reason_code VARCHAR(50)           -- WEATHER, VOLUME, RUNWAY, EQUIPMENT, OTHER
valid_from DATETIME2
valid_until DATETIME2
status VARCHAR(20)                -- ACTIVE, EXPIRED
source_type VARCHAR(20)           -- MANUAL, COORDINATED, IMPORT
raw_input NVARCHAR(MAX)
parsed_data NVARCHAR(MAX)         -- JSON blob
discord_message_id VARCHAR(50)
created_by VARCHAR(50)
created_by_name VARCHAR(100)
created_at DATETIME2
updated_at DATETIME2
```

### `tmi_advisories`
```sql
advisory_id INT IDENTITY PK
advisory_guid UNIQUEIDENTIFIER DEFAULT NEWID()
advisory_number VARCHAR(20)       -- 'ADVZY 003'
advisory_type VARCHAR(30)         -- GS, GDP, AFP, REROUTE, MIT, INFORMATIONAL, etc.
ctl_element VARCHAR(10)
element_type VARCHAR(10)
scope_facilities VARCHAR(200)
program_id INT FK                 -- Links to tmi_programs for GS/GDP/AFP
program_rate INT
delay_cap INT
effective_from DATETIME2
effective_until DATETIME2
subject VARCHAR(200)
body_text NVARCHAR(MAX)
reason_code VARCHAR(50)
reroute_id INT
reroute_name VARCHAR(50)
reroute_area VARCHAR(50)
reroute_string NVARCHAR(MAX)
reroute_from VARCHAR(200)
reroute_to VARCHAR(200)
mit_miles INT
mit_type VARCHAR(10)
mit_fix VARCHAR(20)
status VARCHAR(20)                -- ACTIVE, EXPIRED
is_proposed BIT DEFAULT 0
source_type VARCHAR(20)           -- MANUAL, BOT, IMPORT
discord_message_id VARCHAR(50)
created_by VARCHAR(50)
created_by_name VARCHAR(100)
created_at DATETIME2
updated_at DATETIME2
```

### `tmi_programs`
```sql
program_id INT IDENTITY PK
program_guid UNIQUEIDENTIFIER DEFAULT NEWID()
ctl_element VARCHAR(10)
element_type VARCHAR(10)
program_type VARCHAR(20)          -- GS, GDP-DAS, GDP-GAAP, GDP-UDP, AFP-DAS, etc.
program_name VARCHAR(100)
adv_number VARCHAR(20)
start_utc DATETIME2
end_utc DATETIME2
status VARCHAR(20)                -- PROPOSED, ACTIVE, COMPLETED, CANCELLED
is_proposed BIT
is_active BIT
program_rate INT
reserve_rate INT
delay_limit_min INT
rates_hourly_json NVARCHAR(MAX)
scope_json NVARCHAR(MAX)          -- JSON: {"centers":["ZNY","ZBW"]}
exemptions_json NVARCHAR(MAX)
impacting_condition VARCHAR(50)
cause_text VARCHAR(200)
source_type VARCHAR(20)           -- MANUAL, BOT, IMPORT
created_by VARCHAR(50)
created_at DATETIME2
updated_at DATETIME2
```

### `tmi_reroutes`
```sql
reroute_id INT IDENTITY PK
reroute_guid UNIQUEIDENTIFIER DEFAULT NEWID()
status INT                        -- 1=PROPOSED, 2=ACTIVE, 3=CANCELLED, 4=EXPIRED
name VARCHAR(50)
adv_number VARCHAR(20)
start_utc DATETIME2
end_utc DATETIME2
origin_airports VARCHAR(200)
origin_tracons VARCHAR(200)
origin_centers VARCHAR(200)
dest_airports VARCHAR(200)
dest_tracons VARCHAR(200)
dest_centers VARCHAR(200)
comments NVARCHAR(MAX)
impacting_condition VARCHAR(50)
source_type VARCHAR(20)
created_by VARCHAR(50)
created_at DATETIME2
updated_at DATETIME2
```

### `tmi_reroute_routes`
```sql
route_id INT IDENTITY PK
reroute_id INT FK
origin VARCHAR(50)
destination VARCHAR(50)
route_string NVARCHAR(MAX)
origin_filter VARCHAR(200)
dest_filter VARCHAR(200)
```
