# Variable Rate GDP — Unified Rate Editor Design

**Date**: 2026-03-29
**Status**: Approved

## Summary

Add a two-tier variable rate editor for GDP programs in GDT: an hourly rate table (always visible for GDP types) with an expandable per-15-minute "Edit 15" detail view. Both tiers use the same unit (arrivals/hr) and stay synchronized.

## Unit Convention

All rate values across all three levels represent **arrivals per hour** applied during that time period:

- **Flat rate** (e.g., 30): uniform arrivals/hr for the entire program
- **Hourly rate** (e.g., 14Z: 30): arrivals/hr applied during that hour
- **Quarter rate** (e.g., 14:00: 30): arrivals/hr applied during that 15-min period

The SP (`sp_TMI_GenerateSlots`, migration 053) calculates slot spacing as `3600 / rate`, so a rate of 30 = 120-second slot spacing regardless of granularity level.

## Data Storage

- **Authoritative**: `rates_quarter_json` on `tmi_programs` (NVARCHAR(MAX))
  - Format: `{"14:00":30,"14:15":25,"14:30":30,"14:45":35,...}`
  - Keys are `HH:MM` at quarter-hour boundaries, values are arrivals/hr
- **Fallback**: `program_rate` (flat integer) — used when no quarter rates set
- **Deprecated**: `rates_hourly_json` — not written by new code; hourly is derived

## UI Layout

```
[Flat Rate: 30 /hr]  [Max Delay: 180 min]  [Reserve: 5 /hr]     <- existing row

Hourly Rate Table (arr/hr):                                       <- NEW, visible for GDP
+--------+------+------+------+------+------+
|        | 14Z  | 15Z  | 16Z  | 17Z  | 18Z |   <- hour headers
+--------+------+------+------+------+------+
|AAR     |  30  |  25  |  30  |  34  |  30  |   <- editable, arrivals/hr
+--------+------+------+------+------+------+

[> Edit 15]  [Fill All: [30] [Apply]]            <- toggle + bulk fill

Per-15-Min Detail (arr/hr):                                       <- hidden until toggled
+--------+------+------+------+------+------+---
|        |14:00 |14:15 |14:30 |14:45 |15:00 |
+--------+------+------+------+------+------+
| Rate   |  30  |  30  |  30  |  30  |  25  |   <- editable, arrivals/hr
+--------+------+------+------+------+------+
```

Labels include unit "(arr/hr)" at each tier to avoid ambiguity.

## Sync Logic

1. **Flat rate -> All**: When flat rate input changes, all hourly and quarter cells update to that value
2. **Hourly -> Quarters**: When an hourly cell changes, its 4 quarter cells all set to the new value
3. **Quarter -> Hourly**: When any quarter cell changes, parent hourly cell = `Math.round(avg(4 quarters))`
4. **Edit 15 closed**: Quarter data retained in memory; hourly row still reflects averages
5. **Load existing program**: `rates_quarter_json` populates quarters, hourly derived from averages

## API Flow

- **Create**: Sends `program_rate` (flat) + `rates_quarter_json` (if variable rates edited)
- **Revise**: Sends `rates_quarter_json` (already supported, lines 142-155)
- **SP**: Prefers `rates_quarter_json` > `rates_hourly_json` > `program_rate` (migration 053)
- **Load**: `get_program()` uses `SELECT *`, returns `rates_quarter_json` for population

## Files Changed

| File | Change |
|------|--------|
| `gdt.php` | Replace `gs_edit15_container` with unified rate editor HTML (hourly row + quarter row + fill controls) |
| `assets/js/gdt.js` | New functions: `buildRateEditor()`, `syncHourlyFromQuarters()`, `syncQuartersFromHourly()`, `collectRatesJson()`. Replace existing `buildEdit15Grid`/`getEdit15Json`/`toggleEdit15`/`clearEdit15`. Update create payload. |
| `assets/locales/en-US.json` | Add i18n keys for rate editor labels |

## Files NOT Changed

- `api/gdt/programs/create.php` — already accepts `rates_quarter_json`
- `api/gdt/programs/revise.php` — already accepts `rates_quarter_json`
- `api/gdt/programs/active.php` — already SELECTs `rates_quarter_json`
- `database/migrations/tmi/053_edit15_quarter_rates.sql` — already deployed
- `sp_TMI_GenerateSlots` — already supports variable quarter rates
- GS program flows — rate editor only shown for GDP types
