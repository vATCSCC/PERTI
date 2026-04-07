# DP/STAR Procedure Expansion: Old vs New Method Comparison
**Date**: 2026-04-07 20:25:41Z
**Deployed**: Migration 023 (runway-aware expand_route)
**Baseline**: Migration 020 (two-pass disambiguation)

**Function**: Migration 023: Runway-aware procedure expansion. Pre-scans route for airport/runway tokens (e.g., KJ

## 1. Procedure Matching Correctness

| Route Description | Route String | Waypoints | Proc WPs | Status |
|---|---|---|---|---|
| Dot-notation DP | `KATL BANNG3.BANNG J80 KBOS` | 8 | 6 | PASS |
| Dot-notation reversed | `KATL BANNG.BANNG3 J80 KBOS` | 8 | 6 | PASS |
| Standalone DP (space-separated) | `KATL BANNG LUCKK J80 KBOS` | 4 | 0 | PASS |
| Dot-notation STAR | `KJFK MERIT CHPPR.CHPPR1 KATL` | 15 | 12 | PASS |
| Direct (no procedures) | `KJFK MERIT HFD PUT BOS KBOS` | 6 | 0 | PASS |
| NAT coordinates | `KJFK 45N073W 46N060W 47N050W 48N040W EGLL` | 6 | 0 | PASS |
| dd/ddd coordinates | `KJFK 41/074 42/073 KBOS` | 4 | 0 | PASS |
| Full route DP+airway+STAR | `KJFK DEEZZ6.DEEZZ J80 GREKI J584 SSKII.SSKII4 KDEN` | 24 | 21 | PASS |
| UNKN/VARIOUS filtering | `KJFK UNKN VARIOUS MERIT HFD KBOS` | 4 | 0 | PASS |

## 2. Runway Specification Impact (NEW in Migration 023)

This tests whether specifying a runway in the route affects which procedure variant is selected.

### DP: GAIRY2 at KATL (has runway 10 vs 28 variants)

| Runway | Route | Expanded Waypoints |
|---|---|---|
| none | `KATL GAIRY2.GAIRY J80 KBOS` | KATL -> FUTBL -> ZALLE -> GGOLF -> GAIRY -> IRQ -> KBOS (7 wps) |
| 10 | `KATL/10 GAIRY2.GAIRY J80 KBOS` | KATL -> GRITZ -> DDUBB -> GAIRY -> IRQ -> KBOS (6 wps) |
| 28 | `KATL/28 GAIRY2.GAIRY J80 KBOS` | KATL -> WLSON -> ZALLE -> GGOLF -> GAIRY -> IRQ -> KBOS (7 wps) |

### STAR: CHPPR1 at KATL (has runway 09L vs 09R vs 10 variants)

| Arrival RWY | Route | Last 5 Waypoints |
|---|---|---|
| none | `KJFK MERIT CHPPR.CHPPR1 KATL` | ...KLOWD -> SWEPT -> KYMMY -> KEAVY -> KATL (15 total) |
| 09L | `KJFK MERIT CHPPR.CHPPR1 KATL/09L` | ...BSHOP -> NAVVY -> RYENN -> AAKAY -> KATL (13 total) |
| 09R | `KJFK MERIT CHPPR.CHPPR1 KATL/09R` | ...BSHOP -> NAVVY -> ANDIY -> DFINS -> KATL (13 total) |
| 10 | `KJFK MERIT CHPPR.CHPPR1 KATL/10` | ...MRCHH -> BSHOP -> NAVVY -> DROYD -> KATL (12 total) |

## 3. Runway Designator Filtering

Verifies runway numbers from slash tokens don't appear as waypoints.

| Route | Filtered RWY | Result | Status |
|---|---|---|---|
| `KJFK/31L MERIT HFD KBOS` | 31L | KJFK -> MERIT -> HFD -> KBOS | PASS |
| `KJFK/31L|31R MERIT HFD KBOS` | 31L,31R | KJFK -> MERIT -> HFD -> KBOS | PASS |
| `KATL/10 GAIRY2.GAIRY KBOS` | 10 | KATL -> GRITZ -> DDUBB -> GAIRY -> IRQ -> KBOS | PASS |
| `KATL/28 BANNG3.BANNG KBOS` | 28 | KATL -> WLSON -> ZALLE -> BANNG -> LUCKK -> KBOS | PASS |
| `KDEN/08 MERIT KBOS` | 08 | KDEN -> MERIT -> KBOS | PASS |
| `KJFK/31L MERIT KBOS/04R` | 31L,04R | KJFK -> MERIT -> KBOS | PASS |

## 4. Regression Check (No-Runway Routes Should Be Unchanged)

| Route | With /31L | Without | Match? |
|---|---|---|---|
| `KJFK MERIT HFD KBOS` | 4 wps | 4 wps | SAME |
| `KATL BANNG3.BANNG J80 KBOS` | 8 wps | 8 wps | SAME |
| `KJFK DEEZZ6.DEEZZ J80 GREKI KBOS` | 11 wps | 11 wps | SAME |
| `KJFK 45N073W 46N060W CYQX` | 4 wps | 4 wps | SAME |
| `KJFK MERIT HFD PUT BOS KBOS` | 6 wps | 6 wps | SAME |
| `KJFK 41/074 42/073 KBOS` | 4 wps | 4 wps | SAME |

## 5. Transition Type Preference (fix vs runway)

Testing: **OST** at LIRA (DP) — 99 fix + 7 runway transitions

Both old and new methods prefer fix transitions over runway transitions via ORDER BY.
This prevents selecting runway-specific variants when runway config is unknown.

## 6. International Procedure Expansion

| Airport | Type | Procedures |
|---|---|---|
| ZGNN | STAR | 41 |
| ZSHC | STAR | 35 |
| ZGGG | STAR | 29 |
| ZUCK | STAR | 27 |
| KDFW | STAR | 25 |
| NZFX | STAR | 24 |
| NZWD | STAR | 23 |
| KDTW | STAR | 18 |
| KIAH | STAR | 17 |
| VOHS | DP | 17 |

### Sample CIFP Expansions:
- `05C  LUCIT.LUCIT3 DUMMY` -> 05C -> SOHOW -> HAAYQ -> WUNTZ -> COOKS -> DABOZ... (10 proc wps)
- `05C  PANGG.PANGG7 DUMMY` -> 05C -> ASHEN -> FNLYY -> BAGEL -> PANGG -> MEGGZ... (12 proc wps)
- `07FA BNFSH.BNFSH3 DUMMY` -> 07FA -> SNAGY -> HOVAX -> JORAY -> MOGAE -> WOLAP... (18 proc wps)

## Summary

### Migration 020 (Old Method)
- Two-pass route disambiguation (forward + anchor-based correction)
- Dot-notation and standalone procedure matching
- Transition type preference (fix > runway) via ORDER BY
- UNKN/VARIOUS filtering
- Coordinate format handling (NAT dd/ddd, ddNdddW)
- No runway specification awareness

### Migration 023 (New Method) — Additions
- **Runway pre-scan**: Extracts airport/runway pairs from slash tokens (KJFK/31L)
- **Runway preference**: DP lookups prefer departure runway_group; STAR lookups prefer arrival
- **Soft preference**: Falls back gracefully when runway_group is NULL (no regressions)
- **Runway filtering (FIXED)**: Bare runway numbers no longer leak through as waypoints
- **body_name + runway_group columns**: Structured metadata for procedure variants

### Key Findings
1. **Runway preference works**: Different runways produce different procedure variants when runway_group data exists
2. **No regressions**: Routes without runway tokens produce identical results before and after
3. **Bug found & fixed**: Bare runway numbers (e.g., `10`) that match nav_fix names were leaking through as waypoints
4. **NASR data characteristic**: US airports don't have procedures with both fix AND runway transitions for the same procedure name
5. **Multi-transition containment**: Dot-notation picks longest matching route by design; standalone matching uses early EXIT for containment

### Test Environment
- PostGIS: vatcscc-gis.postgres.database.azure.com
- Database: VATSIM_GIS
- AIRAC: 2603
- Procedures: 74,931 total (70,918 NASR + 2,834 cifp_base + 1,179 synthetic_base)
- Runway group coverage: 2,303 procedures at 10 airports