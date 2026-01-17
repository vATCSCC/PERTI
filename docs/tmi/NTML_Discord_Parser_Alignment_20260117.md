# NTML Discord Posting & Parser Alignment
## Session Date: January 17, 2026

---

## Summary

Aligned NTML message formatting across three code locations to match the historical NTML_2020.txt format. Previously, each file used different formatting approaches.

---

## Problem Statement

Three files were building NTML messages with different formats:

| File | Previous Format | Issue |
|------|-----------------|-------|
| `TMIDiscord.php` | Proper NTML format | Reference implementation |
| `api/mgt/ntml/post.php` | Markdown with arrows (→) | Didn't match NTML spec |
| `assets/js/ntml.js` | Bold markdown, multi-line | Didn't match NTML spec |

---

## Historical NTML Format Reference

Analyzed `DCC/NTML_2020.txt` to determine actual historical format:

### MIT/MINIT Format
```
DD/HHMM APT [arrivals/departures] via FIX ##MIT [QUALIFIERS] REASON EXCL:xxx HHMM-HHMM REQ:PROV
```

**Examples:**
```
17/2344    BOS via MERIT 15MIT VOLUME:VOLUME EXCL:NONE 2345-0000 ZBW:ZNY
18/0043    BOS departures 40MIT VOLUME:VOLUME EXCL:NONE 0045-0300 ZDC:PCT
16/1644    LGA via J146 25MIT NO STACKS VOLUME:VOLUME EXCL:NONE 2200-0300 ZNY:ZOB
```

### STOP Format
```
DD/HHMM APT[,APT] [direction] STOP REASON EXCL:xxx HHMM-HHMM REQ:PROV
```

**Examples:**
```
17/2349    BOS STOP VOLUME:VOLUME EXCL:NONE 2345-0015 ZNY:PHL
18/2350    ATL departures STOP VOLUME:VOLUME EXCL:NONE 2345-0100 ZDC:PCT
```

### Delay Format (D/D, E/D, A/D)
```
DD/HHMM [FAC] TYPE prep APT, +/-value/HHMM[/# ACFT] [NAVAID:xxx] REASON
```

**Examples:**
```
18/0010     D/D from JFK, +45/0010 VOLUME:VOLUME
18/0019    ZDC E/D for BOS, +30/0019/13 ACFT VOLUME:VOLUME
25/0059    ZJX66 A/D to MIA, +Holding/0058 NAVAID:OMN STREAM VOLUME:VOLUME
```

### Config Format
```
DD/HHMM APT    WX    ARR:rwys DEP:rwys    AAR(type):##    [AAR Adjustment:xx]    ADR:##
```

**Examples:**
```
18/2221    ATL    VMC    ARR:26R/27L/28 DEP:26L/27R    AAR(Strat):132    ADR:70
01/2217    JFK    VMC    ARR:ILS_31R_VAP_31L DEP:31L    AAR(Strat):58    ADR:24
```

---

## Files Modified

### 1. `api/mgt/ntml/post.php`

**Changes:**
- Replaced custom message building functions with format-compliant versions
- Added `mapPostToEntryData()` to normalize POST data
- Added `buildNTMLMessageFromEntry()` dispatcher
- Added type-specific builders:
  - `buildRestrictionNTML()` - MIT, MINIT, STOP, APREQ, CFR
  - `buildDelayNTML()` - D/D, E/D, A/D, Holding
  - `buildConfigNTML()` - Airport configurations
  - `buildCancelNTML()` - Cancellation entries
  - `buildTBMNTML()` - Time-Based Metering

**Key format changes:**
- Log time now `DD/HHMM` format (was missing day)
- Facility pair now `REQ:PROV` (was `PROV→REQ` with arrows)
- Uses tab spacing for alignment like historical
- Reason format now `REASON:REASON` (e.g., `VOLUME:VOLUME`)

### 2. `assets/js/ntml.js`

**Parser Changes:**
- `parseMIT_NLP()` - Now correctly separates:
  - `condition` = airport(s) (e.g., JFK)
  - `viaRoute` = fix(es) (e.g., LENDY, CAMRN)
- `parseMINIT_NLP()` - Same separation of condition/viaRoute
- Both now capture fixes without "via" keyword (e.g., "JFK LENDY 20MIT")

**Message Building Changes:**
- Added `getLogTime()` helper for DD/HHMM format
- Replaced markdown formatting (**bold**, →) with plain NTML format
- All message functions now produce single-line format matching historical
- `buildPostData()` now includes `via_route` for MIT and MINIT types

**Updated Functions:**
- `buildMITMessage()`
- `buildMINITMessage()`
- `buildStopMessage()`
- `buildAPREQMessage()`
- `buildTBMMessage()`
- `buildHoldingMessage()`
- `buildDelayMessage()`
- `buildConfigMessage()`
- `buildCancelMessage()`
- `buildRerouteMessage()`
- `buildOtherMessage()`

---

## Format Comparison

### MIT Entry - Before vs After

**Before (ntml.js):**
```
**[05A01] 20MIT** ZBW→ZNY JFK NO STACKS
VOLUME:VOLUME EXCL:NONE 2300-0300
```

**After (ntml.js):**
```
17/1445    JFK via LENDY 20MIT NO STACKS VOLUME:VOLUME EXCL:NONE 2300-0300 ZNY:ZBW
```

### Delay Entry - Before vs After

**Before:**
```
**[04B01] DELAY** JFK
Longest: 45min | Trend: Increasing | Flights: 12
Reason: VOLUME
```

**After:**
```
17/1445     D/D from JFK, +45/1445 VOLUME:VOLUME
```

---

## Testing Checklist

### Parser Tests
- [ ] `"20MIT ZBW:ZNY JFK LENDY VOL 2300-0300"` → condition=JFK, viaRoute=LENDY
- [ ] `"BOS via MERIT 15MIT VOLUME 2345-0000 ZBW:ZNY"` → condition=BOS, viaRoute=MERIT
- [ ] `"BOS 8MINIT VOLUME:VOLUME EXCL:NONE 2330-0300 ZBW:CZY"` → type=MINIT, condition=BOS
- [ ] `"CFR MIA,FLL departures TYPE:ALL VOL 2100-0400 ZMA:F11"` → type=CFR, condition=MIA,FLL

### Discord Output Tests
- [ ] MIT entry matches historical format
- [ ] MINIT entry matches historical format
- [ ] STOP entry matches historical format
- [ ] CFR/APREQ entry matches historical format
- [ ] TBM entry matches historical format
- [ ] D/D entry matches historical format
- [ ] E/D entry matches historical format (with NAVAID)
- [ ] A/D entry matches historical format (with Holding)
- [ ] CONFIG entry matches historical format (with approach types)

---

## Related Files

| File | Status | Notes |
|------|--------|-------|
| `NTML_Advisory_Formatting_Spec.md` | Reference | Format specification |
| `TMI_Documentation_Index.md` | Updated | Quick reference |
| `DCC/NTML_2020.txt` | Reference | Historical validation data |
| `DCC/ADVZY_2020.txt` | Pending | Advisory validation data |

---

## Next Steps

1. **Test Discord posting** - Submit test entries via ntml.php and verify format
2. **Validate against historical** - Compare output to NTML_2020.txt examples
3. **Test edge cases:**
   - Multiple airports (MIA,FLL,RSW)
   - Multiple fixes (CHPPR,GLAVN)
   - Qualifiers (NO STACKS, PER AIRPORT, RALT)
   - Complex configs (ILS_31R_VAP_31L)
4. **Advisory alignment** - Apply same format consistency to advisory-builder.js

---

## Notes

- The facility pair order is `REQ:PROV` (requesting:providing)
- "arrivals" is implicit when using "via FIX" pattern
- "departures" must be explicit
- Historical data uses variable spacing (tabs/spaces) for alignment
- Reason format is always `TYPE:DETAIL` (e.g., `VOLUME:VOLUME`, `WEATHER:THUNDERSTORMS`)
