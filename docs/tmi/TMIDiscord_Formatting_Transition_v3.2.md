# TMIDiscord Formatting Transition Summary v3.2

**Date:** January 17, 2026  
**Version:** TMIDiscord.php v3.2.0, ntml_discord_test.php v1.5.0

## Summary

Comprehensive formatting updates to TMIDiscord.php based on analysis of real-world FAA Advisory Database examples and vATCSCC ADVZY_2020.txt historical data. This update aligns advisory output with actual operational formats.

---

## Key Format Corrections Applied

### 1. Reroute Advisory Header
**Before:** `vATCSCC ADVZY T01 DCC 01/17/2026 ROUTE - RQD`  
**After:** `vATCSCC ADVZY 001 DCC 01/17/2026 ROUTE RQD`

Changes:
- Advisory number: stripped non-numeric prefix (T, #, etc.) - now uses `001` format
- Removed dash between route type and action (`ROUTE RQD` not `ROUTE - RQD`)

### 2. Reroute NAME Field
**Before:** NAME field was not included  
**After:** `NAME: ZNY_TO_PIT` field added after header

Per real examples, NAME field should appear for reroute advisories.

### 3. VALID Time Format
**Before:** `VALID TIMES: ETD START: 172200 END: 180200`  
**After:** `VALID: ETD 172200 TO 180200`

Simplified format matching real-world examples.

### 4. FACILITIES INCLUDED
**Before:** `FACILITIES INCLUDED:/ZNY/ZOB` (leading slash)  
**After:** `FACILITIES INCLUDED: ZNY/ZOB` (space after colon, no leading slash)

### 5. Valid Range Footer
**Before:** `172200-180200` (no spaces)  
**After:** `172200 - 180200` (spaces around dash)

### 6. Route Table - Dynamic Column Widths
**Before:** Fixed 8-character columns  
**After:** Dynamic column widths based on content with 3-space minimum gap

Example output:
```
ORIG      DEST   ROUTE
----      ----   -----
JFK       PIT    DEEZZ5 >CANDR J60 PSB< HAYNZ6
EWR LGA   PIT    >NEWEL J60 PSB< HAYNZ6
PHL       PIT    >PTW SARAA DANNR J60 PSB< HAYNZ6
```

### 7. Route Table - Line Wrapping
Routes that exceed 68 characters now wrap to continuation lines with proper indentation matching the ROUTE column start position.

### 8. Support for CONSTRAINED AREA
Added support for `constrained_area` field as alternative to `impacted_area`, using appropriate label based on which field is provided.

### 9. FLIGHT STATUS Field (Optional)
Added support for optional `flight_status` field per some real examples.

---

## Files Modified

### TMIDiscord.php (v3.2.0)
**Location:** `load/discord/TMIDiscord.php`

Functions updated:
- `formatRerouteAdvisory()` - Comprehensive rewrite for real-world format
- `formatRouteTable()` - Dynamic column widths, line wrapping

### ntml_discord_test.php (v1.5.0)  
**Location:** `api/test/ntml_discord_test.php`

Test data updated to match real-world patterns from ADVZY_2020.txt:
- Ground Stop: uses `001` advisory number, proper delay format
- GDP: uses `002` advisory number
- Reroute: uses `003` advisory number, NAME field, ZNY_TO_PIT example route

---

## Real-World Reference Examples

### From ADVZY_2020.txt - Reroute Advisory
```
vATCSCC ADVZY 003 DCC 03/20/2020 ROUTE RQD
NAME: ZNY_TO_PIT
IMPACTED AREA: ZNY
REASON: VOLUME
INCLUDE TRAFFIC: KJFK/KEWR/KLGA/KPHL DEPARTURES TO KPIT
VALID: ETD 202215 TO 210000
FACILITIES INCLUDED: ZNY/ZOB
PROBABILITY OF EXTENSION: MEDIUM
REMARKS: 
ASSOCIATED RESTRICTIONS: ZNY REQUESTS AOB FL300
MODIFICATIONS: 
ROUTE: 
ORIG    DEST    ROUTE
----    ----    -----
JFK     PIT     DEEZZ5 >CANDR J60 PSB< HAYNZ6
EWR LGA PIT     >NEWEL J60 PSB< HAYNZ6
PHL     PIT     >PTW SARAA DANNR J60 PSB< HAYNZ6

TMI ID: RRDCC003
202215-210000
20/03/20 22:16
```

### From ADVZY_2020.txt - Ground Stop Advisory
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

---

## Testing

### Deploy & Test Commands
```bash
# Test advisory formatting
curl "https://perti.vatcscc.org/api/test/ntml_discord_test.php?key=perti-ntml-test-2026&type=advisory"

# Test all (NTML + advisory)
curl "https://perti.vatcscc.org/api/test/ntml_discord_test.php?key=perti-ntml-test-2026&type=all"
```

### Discord Test Channels
- NTML: `#⚠ntml-staging⚠` (1039586515115839621)
- Advisory: `#⚠advzy-staging⚠` (1039586515115839622)

---

## Expected Reroute Output After Changes

```
vATCSCC ADVZY 003 DCC 01/17/2026 ROUTE RQD
NAME: ZNY_TO_PIT
IMPACTED AREA: ZNY
REASON: VOLUME
INCLUDE TRAFFIC: KJFK/KEWR/KLGA/KPHL DEPARTURES TO KPIT
FACILITIES INCLUDED: ZNY/ZOB
VALID: ETD 172245 TO 180245
PROBABILITY OF EXTENSION: MEDIUM
REMARKS:
ASSOCIATED RESTRICTIONS: ZNY REQUESTS AOB FL300
MODIFICATIONS:
ROUTE:
ORIG      DEST   ROUTE
----      ----   -----
JFK       PIT    DEEZZ5 >CANDR J60 PSB< HAYNZ6
EWR LGA   PIT    >NEWEL J60 PSB< HAYNZ6
PHL       PIT    >PTW SARAA DANNR J60 PSB< HAYNZ6

TMI ID: RRDCC003
172245 - 180245
26/01/17 22:45
```

---

## Reference Files

- **ADVZY_2020.txt**: `DCC\ADVZY_2020.txt` - Historical real-world advisory examples
- **FAA Advisory Database**: https://www.fly.faa.gov/adv/advAdvisoryForm
- **Advisories_and_General_Messages_v1_3.pdf**: `/mnt/project/` - Official spec

---

## Remaining Items to Verify After Deployment

1. **68-char line wrapping** - Verify COMMENTS and REMARKS fields wrap properly
2. **Route table column alignment** - Verify columns align for varying origin/dest lengths
3. **Header number format** - Confirm `001` format appears correctly (no T prefix)
4. **FACILITIES INCLUDED** - Confirm no leading slash
5. **Valid range footer** - Confirm spaces around dash

---

## Previous Session Transcript
Full conversation details available at:
`/mnt/transcripts/2026-01-17-22-43-24-gdt-formatting-tmidiscord.txt`
