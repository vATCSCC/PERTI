# NTML & Advisory Formatting Compliance - Transition Summary
## Session Date: January 17, 2026

---

## Overview

Updated TMIDiscord.php to comply with official FAA TFMS specifications for NTML entries and Advisory messages. Created comprehensive formatting specification document based on authoritative source documents.

---

## Source Documents Used

| Document | Content |
|----------|---------|
| **TMIs.pdf** | vATCSCC NTML Guide - restriction, delay, and config entry formats |
| **Advisories_and_General_Messages_v1_3.pdf** | FAA TFMS Advisory specification |
| **advisory-builder.js** | Reference implementation for 68-char wrapping |

---

## Files Created

### 1. `NTML_Advisory_Formatting_Spec.md`
**Location:** `PERTI\NTML_Advisory_Formatting_Spec.md`

Comprehensive reference document containing:
- NTML restriction entry format (MIT/MINIT/STOP/DSP/APREQ/TBM/CFR)
- NTML delay entry format (D/D, E/D, A/D)
- NTML airport configuration format
- Advisory message structure (header, body, footer, signature)
- Advisory type templates (GDP, GS, Reroute, CNX)
- Qualifier and restriction type reference tables
- Implementation notes

---

## Files Modified

### 1. `load/discord/TMIDiscord.php`
**Location:** `PERTI\load\discord\TMIDiscord.php`

#### Changes Made:

**A. Restriction Entry Format (`formatRestrictionEntry`)**
- Changed from generic `REASON:detail` to separate `VOLUME:text` and `WEATHER:reason` fields
- Added `speed_operator` support for SPD field (=/≤/≥)
- Reordered optional fields per spec: TYPE, SPD, ALT, VOLUME, WEATHER, EXCL
- Always includes TYPE field (not just when non-ALL)

**B. Delay Entry Format (`formatDelayEntry`)**
- Replaced `REASON:detail` with separate `VOLUME:text` and `FIX/NAVAID:fix` optional fields
- Added proper documentation of format per TMIs.pdf
- Clarified holding indicator format (+Holding/-Holding)

**C. Config Entry Format (`formatConfigEntry`)**
- Removed double-space padding (cleaner formatting)
- Added documentation for runway format options (ILS_31R_VAP_31L, LOC_31, RNAV_X_29)

**D. Advisory Formatting**
- Added `MAX_LINE_LENGTH = 68` constant
- Added `wrapText()` helper function for 68-character line wrapping
- Comments fields now only included when populated
- Comments wrapped to 68 characters per IATA Type B spec

**E. Route Table Format (`formatRouteTable`)**
- Changed column spacing to 5 spaces between ORIG, DEST, ROUTE per spec
- Added support for facility prefix format (---ZBW, ---MCO)
- Added documentation for protected segment markers (><)

---

## Format Examples

### NTML Restriction Entry
```
14/1442 JFK arrivals via CAMRN 20MIT NO STACKS TYPE:ALL SPD:≤210 ALT:AOB090 VOLUME:VOLUME EXCL:PHL 2015-2315 N90:ZNY
```

### NTML Delay Entry
```
14/1447 D/D from PHL, -60/0215/10 ACFT VOLUME:VOLUME FIX/NAVAID:FLASK
14/1448 ZDC E/D for ATL, +Holding/0215/8 ACFT VOLUME:VOLUME FIX/NAVAID:JEN
```

### NTML Config Entry
```
14/1449 EWR VMC ARR:04R/RNAV_X_29 DEP:04L AAR(Strat):40 ADR:38
14/1451 PHL IMC ARR:27R DEP:27L/35 AAR(Dyn):36 AAR Adjustment:XW-TLWD ADR:28
```

### Advisory Header
```
vATCSCC ADVZY 002 JFK/ZNY 04/14/2020 CDM GROUND DELAY PROGRAM
```

### Advisory Valid Time Footer
```
141415-142315
```

### Advisory Signature
```
20/04/14 13:49
```

### Route Table
```
ORIG     DEST     ROUTE
---ZBW   ---MCO   >GONZZ Q29 DORET DJB J84 SPA J85 TWINS< BUGGZ4
```

---

## Key Format Rules

### NTML Entries

| Field | Format | Example |
|-------|--------|---------|
| Log Time | DD/HHMM | 14/1442 |
| Valid Time | HHMM-HHMM | 2015-2315 |
| Coordination | REQ:PROV | N90:ZNY |
| Aircraft Type | TYPE:value | TYPE:JET |
| Speed | SPD:op+value | SPD:≤210 |
| Altitude | ALT:type+value | ALT:AOB090 |
| Volume | VOLUME:text | VOLUME:VOLUME |
| Weather | WEATHER:reason | WEATHER:THUNDERSTORMS |
| Exclusions | EXCL:list | EXCL:PHL,BWI |

### Advisory Messages

| Field | Format | Example |
|-------|--------|---------|
| Header Date | MM/DD/YYYY | 04/14/2020 |
| Program Time | DD/HHMMZ | 14/1415Z |
| Valid Range | ddhhmm-ddhhmm | 141415-142315 |
| Signature | YY/MM/DD HH:MM | 20/04/14 13:49 |
| ADL Time | HHMMZ | 1349Z |
| Max Line | 68 characters | (IATA Type B) |

---

## Related Files

| File | Purpose |
|------|---------|
| `assets/js/advisory-builder.js` | Client-side advisory builder with same formatting |
| `api/mgt/ntml/post.php` | NTML API endpoint |
| `load/discord/DiscordAPI.php` | Discord posting base class |

---

## Testing

To test the updated formatting:

```php
<?php
require_once 'load/discord/TMIDiscord.php';

$tmi = new TMIDiscord();

// Test restriction entry
$restriction = [
    'entry_type' => 'MIT',
    'airport' => 'JFK',
    'flow_type' => 'arrivals',
    'fix' => 'CAMRN',
    'restriction_value' => 20,
    'qualifiers' => 'NO_STACKS',
    'aircraft_type' => 'ALL',
    'speed' => '210',
    'speed_operator' => '≤',
    'altitude' => '090',
    'alt_type' => 'AOB',
    'volume' => 'VOLUME',
    'exclusions' => 'PHL',
    'valid_from' => '2026-01-17 20:15:00',
    'valid_until' => '2026-01-17 23:15:00',
    'requesting_facility' => 'N90',
    'providing_facility' => 'ZNY'
];

// This would format and post to Discord
// $result = $tmi->postNtmlEntry($restriction, 'ntml_staging');
```

---

## Next Steps

1. **Test all format changes** - Verify Discord output matches spec
2. **Update NTML Quick Entry** - Align `ntml.js` parser with updated formats
3. **Advisory Builder sync** - Ensure `advisory-builder.js` matches PHP formatting
4. **Historical validation** - Compare against NTML_2020.txt and ADVZY_2020.txt examples

---

## Session Notes

- User provided official FAA documentation to replace hallucinated formats
- PDF extraction of Advisories spec had encoding issues but project knowledge search worked
- Format spec now serves as single source of truth for NTML/Advisory formatting
- 68-character line limit is critical for IATA Type B message compatibility
