# vATCSCC Advisory Formatting Specification

**For Claude Code Implementation**  
**Version:** 1.0 | **Date:** January 5, 2026

---

## Overview

This document specifies the formatting rules for vATCSCC (virtual Air Traffic Control System Command Center) advisories. These formats are based on FAA TFMS (Traffic Flow Management System) specifications, adapted for the VATSIM virtual ATC network.

The advisory builder generates text-based advisories that are published to controllers and pilots. Consistency with real-world formats improves realism and training value.

---

## 1. Common Formatting Rules

### 1.1 Line Length
- Maximum 68 characters per line
- Word-wrap longer content

### 1.2 Date/Time Formats

| Context | Format | Example |
|---------|--------|---------|
| Header date | `mm/dd/yyyy` | `03/20/2025` |
| Valid time range | `ddhhmm-ddhhmm` | `202215-210000` |
| Program period | `dd/hhmmZ – dd/hhmmZ` | `27/1600Z – 27/2159Z` |
| ADL timestamp | `hhmmZ` | `2355Z` |
| Signature | `yy/mm/dd hh:mm` | `25/03/20 22:16` |

**Note:** All times are UTC (Zulu).

### 1.3 Facility Formatting

| Context | Format | Example |
|---------|--------|---------|
| Slash-separated | `/FAC/FAC/FAC` | `/ZNY/ZOB/ZDC` |
| Space-separated | `FAC FAC FAC` | `ZNY ZOB ZDC` |
| With keyword | `(KEYWORD) FAC FAC` | `(MANUAL) ZNY ZOB ZDC` |

### 1.4 Standard Field Values

**Impacting Conditions:**
- `WEATHER` - Weather-related (ceilings, winds, storms)
- `VOLUME` - Traffic demand exceeds capacity
- `RUNWAY` - Runway closures/configuration
- `EQUIPMENT` - ATC equipment issues
- `OTHER` - Anything else

**Probability of Extension:**
- `NONE`, `LOW`, `MEDIUM`, `HIGH`

**Advisory Actions (for reroutes):**
- `RQD` - Required (must comply)
- `RMD` - Recommended
- `PLN` - Planned (future)
- `FYI` - Information only

---

## 2. Advisory Types & Templates

### 2.1 Reroute Advisory

**Header format:**
```
vATCSCC ADVZY ### FAC mm/dd/yyyy TYPE - ACTION
```

Where:
- `###` = 3-digit advisory number (zero-padded)
- `FAC` = Issuing facility (usually `DCC`)
- `TYPE` = `ROUTE`, `PLAYBOOK`, `CDR`, or `NAT`
- `ACTION` = `RQD`, `RMD`, `PLN`, or `FYI`

**Full template:**
```
vATCSCC ADVZY ### DCC mm/dd/yyyy ROUTE - RQD
IMPACTED AREA: [area description]
REASON: [cause]
INCLUDE TRAFFIC: [traffic filter]
VALID TIMES: ETD START: ddhhmm END: ddhhmm
FACILITIES INCLUDED: /FAC/FAC/FAC
PROBABILITY OF EXTENSION: [NONE|LOW|MEDIUM|HIGH]
REMARKS: [text or blank]
ASSOCIATED RESTRICTIONS: [text or blank]
MODIFICATIONS: [text or blank]
ROUTE:
ORIG DEST ASSIGNED ROUTE
---- ---- --------------
XXX  XXX  [SID] >protected segment< [STAR]

TMI ID: RRFAC###
ddhhmm-ddhhmm
yy/mm/dd hh:mm
```

**Route table rules:**
- `>` and `<` bracket the protected (mandatory) segment
- Column headers: 5 chars for ORIG, 5 chars for DEST
- Multiple origins can share a line: `EWR LGA  PIT  >ROUTE<`

**Example:**
```
vATCSCC ADVZY 003 DCC 03/20/2025 ROUTE - RQD
IMPACTED AREA: ZNY
REASON: VOLUME
INCLUDE TRAFFIC: KJFK/KEWR/KLGA DEPARTURES TO KPIT
VALID TIMES: ETD START: 202215 END: 210000
FACILITIES INCLUDED: /ZNY/ZOB
PROBABILITY OF EXTENSION: MEDIUM
REMARKS:
ASSOCIATED RESTRICTIONS: ZNY REQUESTS AOB FL300
MODIFICATIONS:
ROUTE:
ORIG DEST ASSIGNED ROUTE
---- ---- --------------
JFK  PIT  DEEZZ5 >CANDR J60 PSB< HAYNZ6
EWR  PIT  >NEWEL J60 PSB< HAYNZ6
LGA  PIT  >NEWEL J60 PSB< HAYNZ6

TMI ID: RRDCC003
202215-210000
25/03/20 22:16
```

---

### 2.2 Ground Stop (GS) Advisory

**Header format:**
```
vATCSCC ADVZY ### APT/ARTCC mm/dd/yyyy CDM GROUND STOP
```

**Full template:**
```
vATCSCC ADVZY ### APT/ARTCC mm/dd/yyyy CDM GROUND STOP
CTL ELEMENT: APT
ELEMENT TYPE: APT
ADL TIME: hhmmZ
GROUND STOP PERIOD: dd/hhmmZ – dd/hhmmZ
DEP FACILITIES INCLUDED: [ALL|(KEYWORD) FAC FAC FAC]
PREVIOUS TOTAL, MAXIMUM, AVERAGE DELAYS: n / n / n
NEW TOTAL, MAXIMUM, AVERAGE DELAYS: n / n / n
PROBABILITY OF EXTENSION: [LOW|MEDIUM|HIGH]
IMPACTING CONDITION: [CONDITION] [description]
COMMENTS: [text]
ddhhmm-ddhhmm
yy/mm/dd hh:mm
```

**Example:**
```
vATCSCC ADVZY 003 EWR/ZNY 11/03/2025 CDM GROUND STOP
CTL ELEMENT: EWR
ELEMENT TYPE: APT
ADL TIME: 2355Z
GROUND STOP PERIOD: 04/0005Z – 04/0200Z
DEP FACILITIES INCLUDED: (MANUAL) ZAB ZAU ZBW ZDC ZNY ZOB
PREVIOUS TOTAL, MAXIMUM, AVERAGE DELAYS: 0 / 0 / 0
NEW TOTAL, MAXIMUM, AVERAGE DELAYS: 0 / 0 / 0
PROBABILITY OF EXTENSION: HIGH
IMPACTING CONDITION: VOLUME
COMMENTS: ALTERNATES RECOMMENDED: JFK, LGA
040005-040200
25/11/04 00:03
```

---

### 2.3 Ground Stop Cancel (GS CNX)

**Template:**
```
vATCSCC ADVZY ### APT/ARTCC mm/dd/yyyy CDM GS CNX
CTL ELEMENT: APT
ELEMENT TYPE: APT
ADL TIME: hhmmZ
GS CNX PERIOD: dd/hhmmZ – dd/hhmmZ
COMMENTS: [text]
ddhhmm-ddhhmm
yy/mm/dd hh:mm
```

---

### 2.4 Ground Delay Program (GDP) Advisory

**Template:**
```
vATCSCC ADVZY ### APT/ARTCC mm/dd/yyyy CDM GROUND DELAY PROGRAM
CTL ELEMENT: APT
ELEMENT TYPE: APT
ADL TIME: hhmmZ
DELAY ASSIGNMENT MODE: [DAS|GAAP|UDP]
ARRIVALS ESTIMATED FOR: dd/hhmmZ – dd/hhmmZ
CUMULATIVE PROGRAM PERIOD: dd/hhmmZ – dd/hhmmZ
PROGRAM RATE: rr/rr/rr/rr/rr/rr
FLT INCL: ALL CONTIGUOUS US DEP
DEP SCOPE: [distance or (KEYWORD) FAC FAC]
MAXIMUM DELAY: nnn
AVERAGE DELAY: nnn
IMPACTING CONDITION: [CONDITION] [description]
COMMENTS: [text]
ddhhmm-ddhhmm
yy/mm/dd hh:mm
```

**Delay Assignment Modes:**
- `DAS` - Delay Assignment System (standard)
- `GAAP` - Ground-Airline-Airport Partnership
- `UDP` - User-Defined Parameter

---

### 2.5 GDP Cancel (GDP CNX)

**Template:**
```
vATCSCC ADVZY ### APT/ARTCC mm/dd/yyyy CDM GROUND DELAY PROGRAM CNX
CTL ELEMENT: APT
ELEMENT TYPE: APT
ADL TIME: hhmmZ
GDP CNX PERIOD: dd/hhmmZ – dd/hhmmZ
DISREGARD EDCTS FOR DEST APT
COMMENTS: [text]
ddhhmm-ddhhmm
yy/mm/dd hh:mm
```

---

### 2.6 Hotline Advisory (vATCSCC Custom)

**Template for activation:**
```
vATCSCC ADVZY ### DCC mm/dd/yyyy [REGION] HOTLINE - FYI
VALID FOR ddhhmm THROUGH ddhhmm
CONSTRAINED FACILITIES: FAC/FAC/FAC
THE [REGION] HOTLINE IS BEING ACTIVATED TO ADDRESS [REASON] IN [AREA].
THE LOCATION IS [TEAMSPEAK/DISCORD LOCATION], [PASSWORD INFO].
PARTICIPATION IS [REQUIRED|RECOMMENDED] FOR [FACILITIES].
ALL OTHER PARTICIPANTS ARE WELCOME TO JOIN.
PLEASE MESSAGE [CONTACT] IF YOU HAVE ISSUES OR QUESTIONS.

ddhhmm-ddhhmm
yy/mm/dd hh:mm
```

**Template for termination:**
```
vATCSCC ADVZY ### DCC mm/dd/yyyy [REGION] HOTLINE - FYI
VALID FOR ddhhmm THROUGH ddhhmm
CONSTRAINED FACILITIES: FAC/FAC/FAC
THE [REGION] HOTLINE IS NOW TERMINATED.

ddhhmm-ddhhmm
yy/mm/dd hh:mm
```

**Example:**
```
vATCSCC ADVZY 001 DCC 11/16/2025 EAST COAST HOTLINE - FYI
VALID FOR 152359 THROUGH 160400
CONSTRAINED FACILITIES: ZOB/ZAU/ZID/ZDC/ZNY/ZBW/ZMP
THE EAST COAST HOTLINE IS BEING ACTIVATED TO ADDRESS VOLUME IN
ZOB/ZAU/ZID/ZDC/ZNY/ZBW/ZMP.
THE LOCATION IS THE VATUSA TEAMSPEAK, EAST COAST HOTLINE,
(TS.VATUSA.NET), NO PIN.
PARTICIPATION IS RECOMMENDED FOR ZOB/ZAU/ZID/ZDC/ZNY/ZBW/ZMP.
ALL OTHER PARTICIPANTS ARE WELCOME TO JOIN.
PLEASE MESSAGE MICHAEL BONAGA IF YOU HAVE ISSUES OR QUESTIONS.

152359-160400
25/11/16 00:23
```

---

### 2.7 Informational Advisory

**Template:**
```
vATCSCC ADVZY ### FAC mm/dd/yyyy INFORMATIONAL
VALID FOR ddhhmm THROUGH ddhhmm

[Free-form body text, max 68 chars per line]

ddhhmm-ddhhmm
yy/mm/dd hh:mm
```

---

### 2.8 SWAP Advisory (Severe Weather Avoidance Plan)

**Template:**
```
vATCSCC ADVZY ### DCC mm/dd/yyyy [NAME] SWAP IMPLEMENTATION PLAN - FYI
VALID FOR ddhhmm THROUGH ddhhmm
CONSTRAINED FACILITIES: FAC/FAC/FAC

THIS ADVISORY IS FOR PLANNING PURPOSES ONLY. CUSTOMERS ARE
ENCOURAGED TO COMPLY WITH ALL vATCSCC ROUTE ADVISORIES.

SWAP STATEMENT: [Weather description and impact forecast]

EXPECTED IMPACT AREA(S): [Gate/sector descriptions]

[Gate impact assessments, e.g.:]
AZEZU-PAEPR-HANRI (L453-M201): IMPACTS ARE: NOT EXPECTED
WEST GATES: IMPACTS ARE: POSSIBLE AFTER 2300Z

PLANNED ALTERNATE DEPARTURE ROUTES:
[Routes or "POSSIBLE REROUTES/CDR'S WILL BE PROVIDED AS NECESSARY"]

PLANNED ALTERNATE ARRIVAL ROUTES:
[Routes or "AS NECESSARY"]

PLANNED OVERFLIGHT ROUTES:
[Routes or "AS NECESSARY"]

[Optional hotline info]

ddhhmm-ddhhmm
yy/mm/dd hh:mm
```

---

## 3. TMI ID Format

**Format:** `RRFAC###`

- `RR` = Fixed prefix for reroutes
- `FAC` = 3-character facility code (e.g., `DCC`, `ZNY`)
- `###` = 3-digit advisory number

**Examples:** `RRDCC001`, `RRZNY015`, `RRZOB003`

---

## 4. Route Elements Reference

| Element | Example | Description |
|---------|---------|-------------|
| Fix | `MERIT`, `PSB` | Named waypoint |
| Jet Route | `J60`, `J75` | High-altitude airway |
| Q-Route | `Q100` | RNAV high-altitude |
| Victor | `V308` | Low-altitude airway |
| SID | `DEEZZ5` | Departure procedure |
| STAR | `HAYNZ6` | Arrival procedure |

**Protected segment:** Enclosed in `>` and `<` characters.
```
JFK  IAH  WAVEY EMJAY >J174 ZIZZI LFK MPORT< BIKKR2
```
The segment `J174 ZIZZI LFK MPORT` is mandatory.

---

## 5. Validation Rules

1. **Advisory number:** 3 digits, zero-padded (`001`, `015`, `123`)
2. **Date format:** `mm/dd/yyyy` in header
3. **Valid time:** `ddhhmm-ddhhmm` (no spaces around hyphen)
4. **Program period:** Use en-dash `–` (not hyphen) between times
5. **Impacting condition:** Single category, optionally followed by description
6. **Line length:** Max 68 characters
7. **TMI ID:** Must match `RRFAC###` pattern
8. **Facility codes:** Uppercase, 3 characters for ARTCCs (Zxx), 3-4 for airports

---

## 6. Database Fields

The `dcc_advisories` table should store:

| Field | Type | Description |
|-------|------|-------------|
| `advisory_number` | INT | Sequential number per day |
| `facility` | VARCHAR(4) | Issuing facility |
| `advisory_type` | VARCHAR(32) | ROUTE, GS, GDP, HOTLINE, etc. |
| `advisory_action` | VARCHAR(8) | RQD, RMD, PLN, FYI |
| `ctl_element` | VARCHAR(4) | Control element (airport) for GS/GDP |
| `impacting_condition` | VARCHAR(16) | WEATHER, VOLUME, etc. |
| `prob_extension` | VARCHAR(8) | NONE, LOW, MEDIUM, HIGH |
| `tmi_id` | VARCHAR(16) | RRFAC### format |
| `valid_start` | DATETIME | Start of validity period |
| `valid_end` | DATETIME | End of validity period |
| `raw_text` | TEXT | Header line only |
| `full_text` | TEXT | Complete advisory text |
| `scope` | VARCHAR(256) | Traffic filter description |

---

## 7. Implementation Notes

### 7.1 Text Generation Function

```javascript
function generateAdvisory(type, params) {
    // 1. Validate required fields
    // 2. Format dates/times per rules above
    // 3. Build text line by line
    // 4. Apply word-wrap at 68 chars
    // 5. Add signature timestamp
    return advisoryText;
}
```

### 7.2 Key Formatting Functions Needed

- `formatDateMMDDYYYY(date)` → `03/20/2025`
- `formatTimeDDHHMM(date)` → `202215`
- `formatProgramTime(date)` → `20/2215Z`
- `formatADLTime(date)` → `2215Z`
- `formatSignature(date)` → `25/03/20 22:15`
- `generateTMIId(facility, number)` → `RRDCC003`
- `wordWrap(text, maxLen=68)` → wrapped text

### 7.3 Route Table Generation

```javascript
function generateRouteTable(routes) {
    let output = 'ORIG DEST ASSIGNED ROUTE\n';
    output += '---- ---- --------------\n';
    for (const r of routes) {
        output += `${r.orig.padEnd(5)}${r.dest.padEnd(5)}${r.route}\n`;
    }
    return output;
}
```

---

## 8. Quick Reference Card

| Advisory Type | Header Pattern |
|--------------|----------------|
| Reroute | `vATCSCC ADVZY ### DCC mm/dd/yyyy ROUTE - RQD` |
| Ground Stop | `vATCSCC ADVZY ### APT/ZXX mm/dd/yyyy CDM GROUND STOP` |
| GS Cancel | `vATCSCC ADVZY ### APT/ZXX mm/dd/yyyy CDM GS CNX` |
| GDP | `vATCSCC ADVZY ### APT/ZXX mm/dd/yyyy CDM GROUND DELAY PROGRAM` |
| GDP Cancel | `vATCSCC ADVZY ### APT/ZXX mm/dd/yyyy CDM GROUND DELAY PROGRAM CNX` |
| Hotline | `vATCSCC ADVZY ### DCC mm/dd/yyyy [NAME] HOTLINE - FYI` |
| SWAP | `vATCSCC ADVZY ### DCC mm/dd/yyyy [NAME] SWAP - FYI` |
| Info | `vATCSCC ADVZY ### FAC mm/dd/yyyy INFORMATIONAL` |

---

*End of Specification*
