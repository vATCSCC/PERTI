# vATCSCC Advisory Builder Alignment Document

**Version:** 1.0  
**Date:** January 5, 2026  
**Reference Documents:**
- Advisories and General Messages v1.3 (CSC/TFMM-10/1077, November 2012)
- FAA Order 7210.3T, Section 16
- FSM 9.0 Training Guide
- RT_FSA_Users_Guide_9.0
- TFMDI ICD, R10 ADL File Specification v14.1

---

## Executive Summary

This document identifies discrepancies between current vATCSCC advisory formats and real-world ATCSCC advisory specifications, providing corrected templates and implementation guidance. The goal is to improve realism while maintaining operational clarity for the VATSIM community.

---

## 1. Current State Analysis

### 1.1 Advisory Types Currently Supported

| Type | Current Implementation | FAA Specification |
|------|----------------------|-------------------|
| Reroute (ROUTE RQD) | ✅ Implemented | Needs alignment |
| Ground Stop | ✅ Implemented | Needs alignment |
| Hotline | ✅ Implemented (custom) | Custom/vATCSCC-specific |
| SWAP | ✅ Implemented | Needs alignment |
| Informational | ✅ Implemented | Mostly aligned |
| GDP | ⚠️ Partial | Needs major work |
| AFP | ❌ Not implemented | Consider adding |
| CTOP | ❌ Not implemented | Future enhancement |

### 1.2 Key Formatting Discrepancies

#### 1.2.1 Header Line Format

**Current vATCSCC:**
```
vATCSCC ADVZY 001 DCC 02/28/2020 ROUTE RQD
vATCSCC ADVZY 003 EWR/ZNY 11/03/2025 CDM GROUND STOP
```

**FAA Standard:**
```
ATCSCC ADVZY 057 DCC 02/28/2003 PLAYBOOK - RQD/FL
ATCSCC ADVZY ### ATL/ZTL 12/27/2002 CDM GROUND STOP
```

**Issues Identified:**
1. Missing separator ` - ` between route type and action
2. Route type should be specific: ROUTE, PLAYBOOK, CDR, NAT, etc.
3. Action format should be: RQD, RMD, PLN, FYI (with optional `/FL` for flight list)
4. Date format inconsistencies (should be mm/dd/yyyy)

#### 1.2.2 Time Format

**Current vATCSCC (inconsistent):**
- `ETD 290030 TO 010500`
- `032300 - 040300`
- `11/15/2025 2359 - 11/22/2025 0400`
- `VALID: ETD 072200 TO 080400`

**FAA Standard:**
- Valid time line: `ddhhmm-ddhhmm` (e.g., `281700-282000`)
- Reroutes use: `VALID TIMES: ETD START: ddhhmm END: ddhhmm`
- GS/GDP use: `dd/ddddZ – dd/ddddZ` (e.g., `27/1600Z – 27/2159Z`)

#### 1.2.3 Field Labels

| Current vATCSCC | FAA Standard | Notes |
|-----------------|--------------|-------|
| NAME: | (in header) | Move to header line |
| CONSTRAINED AREA: | IMPACTED AREA: | Rename field |
| REASON: | IMPACTING CONDITION: | Rename, restrict values |
| VALID: ETD xxx TO xxx | VALID TIMES: ETD START: xxx END: xxx | Full format |
| FLIGHT STATUS: | (not standard) | Remove or map to FLT INCL |
| EFFECTIVE TIME: | (signature area) | Relocate |

---

## 2. Corrected Advisory Templates

### 2.1 Reroute Advisory (ROUTE/PLAYBOOK/CDR)

The Formatted Reroute Advisory per FAA Order 7210.3T, Section 16:

```
ATCSCC ADVZY ### DCC mm/dd/yyyy [TYPE] - [ACTION][/FL]
IMPACTED AREA: [free text - keep concise]
REASON: [free text]
INCLUDE TRAFFIC: [traffic description]
VALID TIMES: ETD START: ddhhmm END: ddhhmm
FACILITIES INCLUDED: /[FAC]/[FAC]/...
PROBABILITY OF EXTENSION: [NONE|LOW|MEDIUM|HIGH]
REMARKS: [free text or blank]
ASSOCIATED RESTRICTIONS: [free text or blank]
MODIFICATIONS: [free text or blank]
ROUTE:
ORIG DEST ASSIGNED ROUTE
---- ---- --------------
[orig] [dest] [SID] >[protected segment]< [STAR]
[...]

TMI ID: RR[FAC]###
ddhhmm-ddhhmm
yy/mm/dd hh:mm
```

**Field Definitions:**

| Field | Description | Values |
|-------|-------------|--------|
| TYPE | Category of route | ROUTE, PLAYBOOK, CDR, NAT, SPECIAL OPERATIONS, NRP SUSPENSIONS, VS, SHUTTLE ACTIVITY, FCA, FEA, INFORMATIONAL, MISCELLANEOUS |
| ACTION | Required action | RQD (Required), RMD (Recommended), PLN (Planned), FYI (Info only) |
| /FL | Flight list attached | Optional suffix |
| IMPACTED AREA | Affected area/flow | Free text, max 68 chars |
| REASON | Cause of advisory | Free text |
| Protected Segment | Segment between `>` and `<` | The reroute portion pilots must fly |
| TMI ID | Traffic Mgmt Initiative ID | Format: RR + 3-char facility + 3-digit number |

**vATCSCC Example (Corrected):**
```
vATCSCC ADVZY 003 DCC 03/20/2020 ROUTE - RQD
IMPACTED AREA: ZNY
REASON: VOLUME
INCLUDE TRAFFIC: KJFK/KEWR/KLGA/KPHL DEPARTURES TO KPIT
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
PHL  PIT  >PTW SARAA DANNR J60 PSB< HAYNZ6

TMI ID: RRDCC003
202215-210000
20/03/20 22:16
```

### 2.2 Ground Stop Advisory - Actual

Per the Advisories and General Messages specification:

```
ATCSCC ADVZY ### [APT]/[ARTCC] mm/dd/yyyy CDM GROUND STOP
CTL ELEMENT: [APT]
ELEMENT TYPE: APT
ADL TIME: hhmmZ
GROUND STOP PERIOD: dd/hhmmZ – dd/hhmmZ
DEP FACILITIES INCLUDED: [ALL|keyword Zxx ...]
PREVIOUS TOTAL, MAXIMUM, AVERAGE DELAYS: n / n / n
NEW TOTAL, MAXIMUM, AVERAGE DELAYS: n / n / n
PROBABILITY OF EXTENSION: [LOW|MEDIUM|HIGH]
IMPACTING CONDITION: [weather|volume|runway|equipment|other] [free text]
COMMENTS: [free text]
ddhhmm-ddhhmm
```

**vATCSCC Example (Corrected):**
```
vATCSCC ADVZY 003 EWR/ZNY 11/03/2025 CDM GROUND STOP
CTL ELEMENT: EWR
ELEMENT TYPE: APT
ADL TIME: 2355Z
GROUND STOP PERIOD: 04/0005Z – 04/0200Z
DEP FACILITIES INCLUDED: (MANUAL) ZAB ZAU ZBW ZDC ZDV ZFW ZHU ZID ZJX ZKC ZLA ZLC ZMA ZME ZMP ZNY ZOA ZSE ZTL
PREVIOUS TOTAL, MAXIMUM, AVERAGE DELAYS: 0 / 0 / 0
NEW TOTAL, MAXIMUM, AVERAGE DELAYS: 0 / 0 / 0
PROBABILITY OF EXTENSION: HIGH
IMPACTING CONDITION: VOLUME
COMMENTS: ALTERNATES RECOMMENDED: JFK, LGA
040005-040200
25/11/04 00:03
```

### 2.3 Ground Stop Cancel - Actual

```
ATCSCC ADVZY ### [APT]/[ARTCC] mm/dd/yyyy CDM GS CNX
CTL ELEMENT: [APT]
ELEMENT TYPE: APT
ADL TIME: hhmmZ
GS CNX PERIOD: dd/hhmmZ – dd/hhmmZ
FLIGHTS MAY RECEIVE NEW EDCTS DUE TO AN ACTIVE AFP (optional)
COMMENTS: [free text]
ddhhmm-ddhhmm
```

### 2.4 Ground Delay Program Advisory - Actual

```
ATCSCC ADVZY ### [APT]/[ARTCC] mm/dd/yyyy CDM GROUND DELAY PROGRAM
CTL ELEMENT: [APT]
ELEMENT TYPE: APT
ADL TIME: hhmmZ
DELAY ASSIGNMENT MODE: [DAS|GAAP|UDP]
ARRIVALS ESTIMATED FOR: dd/hhmmZ – dd/hhmmZ
CUMULATIVE PROGRAM PERIOD: dd/hhmmZ – dd/hhmmZ
PROGRAM RATE: rr/rr/rr/rr/rr/rr
POP-UP FACTOR: n/n/n/n/n/n (optional)
FLT INCL: ALL CONTIGUOUS US DEP
DEP SCOPE: [distance] or [(keyword) Zxx ...]
ADDITIONAL DEP FACILITIES INCLUDED: Zxx ... (optional)
EXEMPT DEP FACILITIES: Zxx ... (optional)
MAXIMUM DELAY: nnn
AVERAGE DELAY: nnn
IMPACTING CONDITION: [weather|volume|runway|equipment|other] [free text]
COMMENTS: [free text]
ddhhmm-ddhhmm
```

### 2.5 Hotline Advisory (vATCSCC Custom)

Since Hotline advisories are vATCSCC-specific, we should maintain a consistent format:

```
vATCSCC ADVZY ### DCC mm/dd/yyyy [REGION] HOTLINE - FYI
VALID FOR ddhhmm THROUGH ddhhmm
CONSTRAINED FACILITIES: [FAC]/[FAC]/...
[Body text describing hotline activation/termination]
LOCATION: [TeamSpeak/Zello/Discord location]
PARTICIPATION: [REQUIRED|RECOMMENDED|VOLUNTARY] FOR [facilities]
ddhhmm-ddhhmm
yy/mm/dd hh:mm
```

### 2.6 SWAP Advisory (Severe Weather Avoidance Plan)

```
vATCSCC ADVZY ### DCC mm/dd/yyyy [NAME] - FYI
VALID FOR ddhhmm THROUGH ddhhmm
CONSTRAINED FACILITIES: [FAC list]
[SWAP statement body]
EXPECTED IMPACT AREA(S): [gate/sector descriptions]
[Route impact assessments]
PLANNED ALTERNATE DEPARTURE ROUTES: [description or "AS NECESSARY"]
PLANNED ALTERNATE ARRIVAL ROUTES: [description or "AS NECESSARY"]
PLANNED OVERFLIGHT ROUTES: [description or "AS NECESSARY"]
[Optional hotline info]
ddhhmm-ddhhmm
yy/mm/dd hh:mm
```

### 2.7 Informational Advisory

Per FAA spec - free-formatted but with standard header/footer:

```
ATCSCC ADVZY ### [FAC] mm/dd/yyyy INFORMATIONAL
VALID FOR ddhhmm THROUGH ddhhmm
[Free-form text body - max 68 chars per line]
ddhhmm-ddhhmm
yy/mm/dd hh:mm
```

---

## 3. Impacting Condition Values

The FAA specification defines five valid impacting condition categories:

| Value | Usage |
|-------|-------|
| WEATHER | Weather-related constraints (low ceilings, winds, thunderstorms, snow) |
| VOLUME | Traffic demand exceeds capacity |
| RUNWAY | Runway closures, construction, configuration changes |
| EQUIPMENT | ATC equipment issues, radar outages |
| OTHER | Any other cause not fitting above categories |

**Current vATCSCC Issues:**
- Using compound values like "VOLUME/VOLUME" or "VOLUME / VOLUME"
- Not following the category list consistently
- Some advisories use free-text descriptions instead

**Recommendation:**
Use single category followed by free-text explanation:
```
IMPACTING CONDITION: VOLUME DUE TO FNO EVENT TRAFFIC
IMPACTING CONDITION: WEATHER THUNDERSTORMS OVER J60/J70 CORRIDOR
```

---

## 4. Probability of Extension Values

Valid values per specification:
- **NONE** - No extension expected
- **LOW** - Extension unlikely
- **MEDIUM** - Extension possible
- **HIGH** - Extension likely

---

## 5. TMI ID Format

Format: `RR[FAC][###]`

Where:
- `RR` = Reroute prefix (static)
- `[FAC]` = 3-character facility ID that created the reroute (e.g., DCC, ZNY)
- `[###]` = 3-digit advisory number (zero-padded)

Examples:
- `RRDCC001` - DCC reroute advisory #1
- `RRZNY015` - ZNY reroute advisory #15

---

## 6. Route Table Format

### 6.1 Column Headers

**Standard (5 spaces between columns):**
```
ORIG DEST ASSIGNED ROUTE
---- ---- --------------
```

Or simplified (4 spaces):
```
ORIG    DEST    ROUTE
----    ----    -----
```

### 6.2 Protected Segment Notation

The `>` and `<` characters denote the protected segment - the portion of the route that aircraft MUST fly as the reroute. This is the "mandatory" portion.

**Example:**
```
JFK  IAH  WAVEY EMJAY J174 >ZIZZI LFK MPORT< BIKKR2
```
- Aircraft must fly via ZIZZI LFK MPORT
- Everything before `>` is departure routing (can vary)
- Everything after `<` is arrival routing (can vary)

### 6.3 Multi-Origin Notation

When multiple origins share the same route, they can be combined:
```
EWR LGA PIT  >NEWEL J60 PSB< HAYNZ6
```

Or listed separately:
```
EWR     PIT  >NEWEL J60 PSB< HAYNZ6
LGA     PIT  >NEWEL J60 PSB< HAYNZ6
```

---

## 7. Date/Time Format Reference

| Context | Format | Example |
|---------|--------|---------|
| Header date | mm/dd/yyyy | 03/20/2020 |
| Valid times (free-form) | ddhhmm THROUGH ddhhmm | 202215 THROUGH 210000 |
| Valid times (reroute) | ETD START: ddhhmm END: ddhhmm | ETD START: 202215 END: 210000 |
| Program period | dd/hhmmZ – dd/hhmmZ | 27/1600Z – 27/2159Z |
| Advisory valid time | ddhhmm-ddhhmm | 202215-210000 |
| Signature timestamp | yy/mm/dd hh:mm | 20/03/20 22:16 |
| ADL time | hhmmZ | 2355Z |

---

## 8. Implementation Recommendations

### 8.1 Database Schema Changes

Consider adding/modifying fields in `dcc_advisories` table:

```sql
-- Add structured fields for better reporting
ALTER TABLE dcc_advisories ADD COLUMN advisory_category NVARCHAR(32);  -- ROUTE, PLAYBOOK, CDR, etc.
ALTER TABLE dcc_advisories ADD COLUMN advisory_action NVARCHAR(8);     -- RQD, RMD, PLN, FYI
ALTER TABLE dcc_advisories ADD COLUMN impacting_condition NVARCHAR(16); -- WEATHER, VOLUME, etc.
ALTER TABLE dcc_advisories ADD COLUMN probability_extension NVARCHAR(8); -- NONE, LOW, MEDIUM, HIGH
ALTER TABLE dcc_advisories ADD COLUMN tmi_id NVARCHAR(16);             -- RRDCC001 format
ALTER TABLE dcc_advisories ADD COLUMN ctl_element NVARCHAR(4);         -- APT code for GS/GDP
ALTER TABLE dcc_advisories ADD COLUMN element_type NVARCHAR(4);        -- APT, FCA, etc.
```

### 8.2 Advisory Builder UI Changes

1. **Header Section:**
   - Add dropdown for advisory TYPE (ROUTE, PLAYBOOK, CDR, etc.)
   - Add dropdown for ACTION (RQD, RMD, PLN, FYI)
   - Checkbox for flight list attachment (/FL)

2. **Reroute-Specific Fields:**
   - Rename "Constrained Area" → "Impacted Area"
   - Add "Reason" as free-text with category prefix helper
   - Update valid times format helper
   - Add protected segment notation help

3. **GS/GDP-Specific Fields:**
   - Add CTL Element field
   - Add Element Type dropdown (APT/FCA)
   - Add ADL Time field (auto-populate option)
   - Add Delay Assignment Mode for GDP
   - Add Program Rate builder for GDP

4. **Common Fields:**
   - Impacting Condition dropdown + text field
   - Probability of Extension dropdown
   - Auto-generate TMI ID from facility + sequence

### 8.3 Template Selector

Implement a template dropdown that pre-populates the correct format:

```javascript
const advisoryTemplates = {
    'REROUTE_RQD': { type: 'ROUTE', action: 'RQD', template: '...' },
    'PLAYBOOK_RQD': { type: 'PLAYBOOK', action: 'RQD', template: '...' },
    'CDR_RQD': { type: 'CDR', action: 'RQD', template: '...' },
    'GROUND_STOP': { type: 'GS', template: '...' },
    'GROUND_STOP_CNX': { type: 'GS_CNX', template: '...' },
    'GDP': { type: 'GDP', template: '...' },
    'HOTLINE_ACTIVATE': { type: 'HOTLINE', template: '...' },
    'HOTLINE_TERMINATE': { type: 'HOTLINE_CNX', template: '...' },
    'SWAP': { type: 'SWAP', template: '...' },
    'INFORMATIONAL': { type: 'INFO', template: '...' }
};
```

### 8.4 Validation Rules

Implement validation to ensure compliance:

```javascript
const validationRules = {
    // Header validation
    advisoryNumber: /^\d{3}$/,
    dateFormat: /^\d{2}\/\d{2}\/\d{4}$/,
    
    // Time validation
    validTimeFormat: /^\d{6}-\d{6}$/,
    programPeriodFormat: /^\d{2}\/\d{4}Z\s*–\s*\d{2}\/\d{4}Z$/,
    
    // TMI ID validation
    tmiIdFormat: /^RR[A-Z]{3}\d{3}$/,
    
    // Impacting condition
    impactingConditions: ['WEATHER', 'VOLUME', 'RUNWAY', 'EQUIPMENT', 'OTHER'],
    
    // Probability
    probExtension: ['NONE', 'LOW', 'MEDIUM', 'HIGH'],
    
    // Line length
    maxLineLength: 68
};
```

---

## 9. Migration Path

### Phase 1: Template Alignment (Immediate)
- Update advisory text generation to match FAA format
- Add proper header formatting
- Fix time formats

### Phase 2: UI Enhancement (Short-term)
- Add template selector
- Add field helpers and validation
- Implement auto-TMI ID generation

### Phase 3: Database Enhancement (Medium-term)
- Add structured fields to database
- Migrate existing advisories if needed
- Improve reporting capabilities

### Phase 4: GDP/AFP Implementation (Long-term)
- Implement full GDP advisory support
- Add AFP advisory type
- Consider CTOP support

---

## Appendix A: Complete Advisory Format Comparison

### A.1 Current vs. Corrected - Reroute

**Current Format (from CSV):**
```
vATCSCC ADVZY 001 DCC 02/28/2020 ROUTE RQD
NAME: C90_TO_MSP
IMPACTED AREA: ZAU
REASON: OTHER
INCLUDE TRAFFIC: KORD/KMDW DEPARTURES TO KMSP
VALID: ETD 290030 TO 010500
FACILITIES INCLUDED: ZAU/ZMP
PROBABILITY OF EXTENSION: LOW
REMARKS: 
ASSOCIATED RESTRICTIONS: 
MODIFICATIONS: 
ROUTE: 
ORIG    DEST    ROUTE
----    ----    -----
ORD     MSP     >PMPKN NEATO DLLAN RONIC KAMMA< KKILR3
MDW     MSP     >PEKUE OBENE MONNY MNOSO< BLUEM3

TMI ID: RRDCC001
290030-010500
20/02/28 22:06
```

**Corrected Format:**
```
vATCSCC ADVZY 001 DCC 02/28/2020 ROUTE - RQD
IMPACTED AREA: ZAU IAH DAS/STROS STAR
REASON: OTHER
INCLUDE TRAFFIC: KORD/KMDW DEPARTURES TO KMSP
VALID TIMES: ETD START: 290030 END: 010500
FACILITIES INCLUDED: /ZAU/ZMP
PROBABILITY OF EXTENSION: LOW
REMARKS:
ASSOCIATED RESTRICTIONS:
MODIFICATIONS:
ROUTE:
ORIG DEST ASSIGNED ROUTE
---- ---- --------------
ORD  MSP  >PMPKN NEATO DLLAN RONIC KAMMA< KKILR3
MDW  MSP  >PEKUE OBENE MONNY MNOSO< BLUEM3
ORD  MSP  >BAE< EAU9 (NON-RNAV TYPE:PROPS)
MDW  MSP  >PLL DBQ ALO< KASPR7 (NON-RNAV TYPE:PROPS)

TMI ID: RRDCC001
290030-010500
20/02/28 22:06
```

**Key Changes:**
1. Added ` - ` separator between type and action in header
2. Removed NAME: line (integrated into context)
3. Changed VALID: format to VALID TIMES:
4. Changed FACILITIES INCLUDED format (prefix with /)
5. Standardized route table headers

### A.2 Current vs. Corrected - Ground Stop

**Current Format (from CSV):**
```
vATCSCC ADVZY 003 EWR/ZNY 11/03/2025 CDM GROUND STOP
CTL ELEMENT: EWR
ELEMENT TYPE: APT
ADL TIME: 2355Z
GROUND STOP PERIOD: 04/0005Z - 04/0200Z
DEP FACILITIES INCLUDED: (Manual) ZAB ZAU ZBW ZDC ZDV ZFW ZHU ZID ZJX ZKC ZLA ZLC ZMA ZME ZMP ZNY ZOA ZSE ZTL CZE CZM CZU CZV CZW CZY
PREVIOUS TOTAL, MAXIMUM, AVERAGE DELAYS: 0 / 0 / 0
NEW TOTAL, MAXIMUM, AVERAGE DELAYS: 0 / 0 / 0
PROBABILITY OF EXTENSION: HIGH
IMPACTING CONDITION: VOLUME / VOLUME
COMMENTS: ALTERNATES RECOMMENDED: JFK, LGA

040005 - 040200
25/11/04 00:03
```

**Corrected Format:**
```
vATCSCC ADVZY 003 EWR/ZNY 11/03/2025 CDM GROUND STOP
CTL ELEMENT: EWR
ELEMENT TYPE: APT
ADL TIME: 2355Z
GROUND STOP PERIOD: 04/0005Z – 04/0200Z
DEP FACILITIES INCLUDED: (MANUAL) ZAB ZAU ZBW ZDC ZDV ZFW ZHU ZID ZJX ZKC ZLA ZLC ZMA ZME ZMP ZNY ZOA ZSE ZTL CZE CZM CZU CZV CZW CZY
PREVIOUS TOTAL, MAXIMUM, AVERAGE DELAYS: 0 / 0 / 0
NEW TOTAL, MAXIMUM, AVERAGE DELAYS: 0 / 0 / 0
PROBABILITY OF EXTENSION: HIGH
IMPACTING CONDITION: VOLUME
COMMENTS: ALTERNATES RECOMMENDED: JFK, LGA
040005-040200
25/11/04 00:03
```

**Key Changes:**
1. Fixed hyphen to en-dash (–) in GROUND STOP PERIOD
2. Changed (Manual) to uppercase (MANUAL) per convention
3. Fixed IMPACTING CONDITION (single value, not compound)
4. Fixed valid time format (no spaces around hyphen)

---

## Appendix B: Special Notations

### B.1 Filter Indicators in Routes

| Notation | Meaning |
|----------|---------|
| (NON-RNAV TYPE:PROPS) | Props/turboprops without RNAV capability |
| (RNAV EQUIPPED) | Aircraft with RNAV capability |
| (JETS ONLY) | Jet aircraft only |
| (ALL FLIGHTS) | No filter - all qualifying flights |

### B.2 Airport/Center Notation

| Format | Example | Meaning |
|--------|---------|---------|
| Kxxx | KJFK | US airport (ICAO) |
| xxx | JFK | US airport (FAA 3-letter) |
| Zxx | ZNY | US ARTCC |
| Cxxxx | CYYZ | Canadian airport |
| CZx | CZY | Nav Canada FIR |

### B.3 Route Elements

| Element | Example | Description |
|---------|---------|-------------|
| Fix | MERIT, PSB | Named fix/waypoint |
| Jxx | J60 | Jet route |
| Qxxx | Q100 | RNAV Q-route |
| Vxxx | V308 | Victor airway |
| Txxx | T42 | RNAV T-route |
| Lxxx | L453 | Low-altitude RNAV |
| xxxxx# | DEEZZ5 | SID (name + revision) |
| xxxxx# | HAYNZ6 | STAR (name + revision) |

---

## Appendix C: Advisory Type Quick Reference

| Type | Header Format | Use Case |
|------|---------------|----------|
| ROUTE - RQD | Route required | General rerouting |
| PLAYBOOK - RQD | Playbook required | National Playbook route |
| CDR - RQD | CDR required | Coded Departure Route |
| ROUTE - RMD | Route recommended | Suggested rerouting |
| CDM GROUND STOP | Ground stop | Stop all departures |
| CDM GS CNX | GS cancel | Cancel ground stop |
| CDM GROUND DELAY PROGRAM | GDP | Delay program |
| CDM GDP CNX | GDP cancel | Cancel GDP |
| INFORMATIONAL | Info only | FYI messages |
| OPERATIONS PLAN | Ops plan | Daily planning |
| SWAP | FYI | Severe weather plan |

---

*End of Document*
