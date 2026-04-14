# TMI Publisher v1.6.0 Transition Summary

**Date:** 2026-01-27  
**Version:** 1.6.0  
**File:** `assets/js/tmi-publish.js` (2935 lines)

---

## Overview

This session implemented major enhancements to the TMI Publisher including:
1. Hotline Activation boilerplate with TypeForm-matching fields
2. 68-character FAA-standard text wrapping utilities
3. NTML format alignment with Zapier/TypeForm output
4. Category:Cause reason structure per OPSNET/ASPM standards
5. Exclusions (EXCL:) field support

---

## 1. Hotline Activation Boilerplate

### New Form Fields
| Field | Type | Description |
|-------|------|-------------|
| Hotline Name | Dropdown | EAST/WEST/MIDWEST/NE/SE/SW/NW COAST HOTLINE, CUSTOM |
| Custom Name | Text | Enabled when CUSTOM selected (max 40 chars) |
| Start Time | Time picker | UTC start time |
| End Time | Time picker | UTC end time |
| Participation | Dropdown | MANDATORY/OPTIONAL/ENCOURAGED |
| Probability of Extension | Dropdown | NONE/LOW/MEDIUM/HIGH |
| Constrained Facilities | Text | Facilities with constraints |
| Facilities to Attend | Text | Required attendees |
| Impacting Condition | Dropdown | WEATHER/VOLUME/EQUIPMENT/STAFFING/etc. |
| Location of Impact | Text | e.g., "NY Metro, EWR/JFK/LGA arrivals" |
| Hotline Location | Text | e.g., "vATCSCC Discord" |
| Hotline Address | Text | URL/link to join |
| Hotline PIN | Text | Access code |
| Associated Restrictions | Text | Related TMIs |
| Additional Remarks | Textarea | Free text |

### Preview Formats by Action Type

**ACTIVATION:**
```
vATCSCC ADVZY 001 DCC 01/27/2026 HOTLINE ACTIVATION

EVENT TIME: 27/1530Z - 27/1930Z
CONSTRAINED FACILITIES: ZNY, ZBW, ZDC

THE EAST COAST HOTLINE IS BEING ACTIVATED TO ADDRESS WEATHER IN NY
METRO ARRIVALS. THE LOCATION IS THE vATCSCC Discord, EAST COAST
HOTLINE, (https://discord.gg/xxx), PIN: 1234. PARTICIPATION IS
MANDATORY FOR ZNY, ZBW, ZDC, ZOB, ZID. AFFECTED MAJOR UNDERLYING
FACILITIES ARE STRONGLY ENCOURAGED TO ATTEND. ALL OTHER PARTICIPANTS
ARE WELCOME TO JOIN. PLEASE MESSAGE THE NOM IF YOU HAVE ISSUES OR
QUESTIONS.

ASSOCIATED RESTRICTIONS: 20MIT, APREQ

REMARKS: Additional coordination notes here.

TMI ID: HP.RRDCC001
271530-271930
26/01/27 15:30 HP
```

**UPDATE:** Simplified format with key fields only
**TERMINATION:** Minimal format with termination time and affected facilities

---

## 2. 68-Character Text Wrapping Utilities

### Constants
```javascript
const TEXT_FORMAT = {
    LINE_WIDTH: 68,
    SEPARATOR: '____________________________________________________________________', // 68 underscores
    INDENT: '    ' // 4 spaces for continuation
};
```

### Functions

| Function | Purpose | Usage |
|----------|---------|-------|
| `wrapText(text, width, indent)` | Word-boundary wrapping | Body text, remarks |
| `wrapWithLabel(label, text, width)` | Hanging indent for labeled content | REMARKS:, ASSOCIATED RESTRICTIONS: |
| `formatSection(content)` | 68-underscore separator lines | Section dividers |
| `formatColumns(rows, colWidths)` | Column-aligned tables | Route tables |

### Integration
- Applied to all advisory preview builders (Ops Plan, Free Form, Hotline, SWAP)
- Maintains FAA-standard formatting across all output types

---

## 3. NTML Format - Zapier Alignment

### Sample Zapier Output (Reference)
```
14/2351    DCA via CLIPR/SKILS 20MIT AS ONE EXCL:NONE VOLUME:VOLUME 2359-0300 PCT:ZNY
16/2112    PCT departures via CLTCH 25MIT PER AIRPORT EXCL:NONE VOLUME:VOLUME 0000-0400 ZDC:PCT
22/2246    KDFW    VLIMC    ARR:31R DEP:13L    AAR(Strat):10 ADR:25
```

### Format Specifications

**MIT/MINIT:**
```
{DD/HHMM}    {element} via {fix} {value}{type} {spacing} EXCL:{excl} {category}:{cause} {valid} {req}:{prov}
```

**CONFIG:**
```
{DD/HHMM}    {airport}    {weather}    ARR:{arr} DEP:{dep}    AAR(type):{aar} ADR:{adr}
```

**STOP:**
```
{DD/HHMM}    {element} via {fix} STOP {qualifiers} EXCL:{excl} {category}:{cause} {valid} {req}:{prov}
```

### Key Changes
- 4 spaces between major sections
- `EXCL:` field added (default: NONE)
- `via` always present (defaults to ALL if not specified)
- Uppercase enforcement on all facility/runway/fix inputs
- Determinant codes removed (deferred for later implementation with lookup table)

---

## 4. Category:Cause Reason Structure

Per FSM documentation Table 3-12 (Advisory/Causal Factors) and OPSNET standards:

### Reason Categories
| Code | Label |
|------|-------|
| VOLUME | Volume |
| WEATHER | Weather |
| RUNWAY | Runway |
| EQUIPMENT | Equipment |
| OTHER | Other |

### Cause Codes by Category

**VOLUME:**
- VOLUME (General)
- COMPACTED DEMAND
- MULTI-TAXI
- AIRSPACE

**WEATHER:**
- WEATHER (General)
- THUNDERSTORMS
- LOW CEILINGS
- LOW VISIBILITY
- FOG
- WIND
- SNOW/ICE

**RUNWAY:**
- RUNWAY (General)
- RUNWAY CONFIGURATION
- RUNWAY CONSTRUCTION
- RUNWAY CLOSURE

**EQUIPMENT:**
- EQUIPMENT (General)
- FAA EQUIPMENT
- NON-FAA EQUIPMENT

**OTHER:**
- OTHER (General)
- STAFFING
- AIR SHOW
- VIP MOVEMENT
- SPECIAL EVENT
- SECURITY

### UI Implementation
- Two side-by-side dropdowns (Category, Cause)
- Cause options dynamically update when Category changes
- Function: `TMIPublisher.updateCauseOptions()`

---

## 5. Updated Spacing Qualifiers

| Code | Label | Description |
|------|-------|-------------|
| AS ONE | AS ONE | Combined traffic as one stream (default) |
| PER STREAM | PER STREAM | Spacing per traffic stream |
| PER AIRPORT | PER AIRPORT | Spacing per departure airport |
| PER FIX | PER FIX | Spacing per arrival fix |
| EACH | EACH | Each aircraft separately |

---

## 6. Form Layout Updates

### MIT/MINIT Form
- Row 1: Value, Airport/Fix, Via Route/Fix
- Row 2: Reason (Category:Cause) - full width with two dropdowns
- Row 3: Requesting Facility, Providing Facility, Exclusions
- Row 4: Valid From, Valid Until
- Qualifiers section below

### STOP Form
- Similar restructuring with Category:Cause and Exclusions

---

## Database Alignment

The `ntml` table in VATSIM_ADL already has columns for this structure:
- `impacting_condition` (nvarchar 64) - Category
- `cause_text` (nvarchar 512) - Cause

---

## Public API Updates

Added to `window.TMIPublisher`:
```javascript
updateCauseOptions: updateCauseOptions
```

---

## Deferred Items

1. **Determinant Codes** - Requires lookup table implementation; format placeholder removed
2. **OI Configuration System** - User's Operating Initials; multiple approaches discussed (JATOC integration, dedicated TMI setting, in-app config)
3. **Additional Advisory Types** - GDP/GS/Reroute from ATCSCC_Advisory_Builder.xlsx

---

## Files Modified

| File | Changes |
|------|---------|
| `assets/js/tmi-publish.js` | All changes (2826 → 2935 lines) |

---

## Testing Notes

1. Test Category/Cause dropdown interaction
2. Verify NTML output format matches Zapier samples
3. Confirm Hotline boilerplate text wrapping at 68 chars
4. Check uppercase enforcement on all facility inputs

---

## Related Documentation

- `/mnt/project/GDT_Unified_Design_Document_v1.md`
- `/mnt/project/NTML_Quick_Entry_Transition.md`
- FSM User Guide Version 13.0, Table 3-12 (Advisory/Causal Factors)
- Advisories and General Messages v1.3.pdf

---

## Transcript Location

Full conversation: `/mnt/transcripts/2026-01-27-*.txt`
