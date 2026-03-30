# Two-Pass Route Disambiguation Design

**Date**: 2026-03-30
**Status**: Validated against 327 real routes
**Scope**: PostGIS `expand_route()` enhancement

---

## Problem

`expand_route()` resolves waypoints left-to-right in a single forward pass. Each waypoint's resolution uses the previous waypoint's coordinates as proximity context. This fails when:

1. **First waypoint is ambiguous** (no prior context) — `resolve_waypoint()` falls back to `LIMIT 1` without `ORDER BY`, returning an arbitrary row
2. **Leading pseudo-fixes** like UNKN provide zero context, so the first real waypoint also resolves arbitrarily
3. **Cascading errors** — a wrong-continent first resolution poisons context for subsequent waypoints

Real bugs found in production:
- **PIKIL** resolved to Australia (-32S, 117E) instead of North Atlantic (56N, 15W) — 9,558nm error
- **SPP** resolved to Spain (36N, 5W) instead of Caribbean (10N, 66W) — 4,792nm error
- **JSY** (reported by user) resolved to Oregon airport instead of Jersey VOR (English Channel)

---

## Design: Forward Pass + Anchor-Based Correction Pass

### Approach

Add a second pass to `expand_route()` after the existing forward pass. The correction pass identifies "anchor" waypoints (high-confidence resolutions) and re-resolves ambiguous waypoints using bidirectional anchor context.

### Pass 1: Forward Pass (existing, unchanged)

Left-to-right resolution using previous waypoint as proximity context. No changes needed — this handles 98.8% of routes correctly.

### Pass 2: Anchor Classification

After forward pass, classify each resolved waypoint:

**Anchors** (high confidence — position is trustworthy):
- Airports (`airport`, `airport_faa`, `airport_k` waypoint types)
- Coordinate waypoints (`coordinate` type — e.g., `5676N`, `60N09`)
- Area centers (`area_center` type)
- Airway intermediates (`airway_*` types — resolved from airway definitions, not ambiguous lookup)
- Single-match fixes (only 1 entry in `nav_fixes` for that `fix_name`)

**Ambiguous** (may need correction):
- Fixes with 2+ entries in `nav_fixes` that are NOT in the above categories

### Pass 3: Correction Pass

For each ambiguous waypoint:
1. Find nearest anchor to the **left** (backward scan)
2. Find nearest anchor to the **right** (forward scan)
3. Compute context point:
   - Both anchors exist: midpoint of left and right anchor coordinates
   - Only right anchor: use right anchor coordinates
   - Only left anchor: use left anchor coordinates (forward pass was already correct here)
   - No anchors: skip (cannot improve)
4. Re-resolve via `resolve_waypoint(fix_name, ctx_lat, ctx_lon)`
5. If new resolution differs from forward pass by >0.01 degrees (~0.6nm), use the new resolution

### Key Insight: Right-Anchor Context

The critical improvement is using **right-side context** — something the forward pass cannot do. When PIKIL is the first waypoint, the forward pass has no context. But the correction pass sees that the next anchor (e.g., a coordinate waypoint `5850N` at 58N) is in the North Atlantic, and resolves PIKIL there instead of Australia.

---

## Validation Results

Tested against 327 real routes from 5 playbook plays covering domestic US, Canadian polar, Caribbean, South American, North Atlantic, and European routing.

| Metric | Value |
|--------|-------|
| Total routes tested | 327 |
| Real bugs corrected | 4 (1.2%) |
| Trivial shifts (<10nm) | 9 (2.8%) |
| False positives | 0 |
| Routes unchanged | 314 (96.0%) |

### Real Corrections

| Fix | Routes | Shift (nm) | From | To |
|-----|--------|------------|------|-----|
| PIKIL | 3 | 9,558 | Australia (-32S, 117E) | N Atlantic (56N, 15W) |
| SPP | 1 | 4,792 | Spain (36N, 5W) | Caribbean (10N, 66W) |

### Trivial Shifts (harmless, same geographic area)

| Fix | Count | Shift | Cause |
|-----|-------|-------|-------|
| OGLVE | 2 | ~1nm | Near-duplicate entries |
| ALB | 5 | ~1nm | Albany VOR variants |
| HFD | 2 | ~8nm | Hartford-area entries |

---

## Implementation Strategy

### Option A: Modify `expand_route()` in-place

Add correction pass as a loop after the existing forward pass, before returning results. Requires:
- Candidate count query (or pre-cached counts)
- Anchor classification logic
- Re-resolution loop with bidirectional scan

**Pros**: Single function, no API changes, transparent to callers
**Cons**: Increases function complexity; candidate count queries add latency

### Option B: New `expand_route_v2()` function

Create new function with two-pass logic. Keep `expand_route()` as-is for backward compatibility.

**Pros**: No risk to existing callers; can A/B test
**Cons**: Code duplication; callers must opt in

### Recommendation: Option A

Modify `expand_route()` directly. The correction pass is additive (only changes ambiguous waypoints) and validated against 327 routes with zero false positives. The function is already complex; a v2 fork would drift.

### Performance Considerations

- **Candidate counts**: Cache in a CTE or temp table at function start — one query for all unique fix names in the route
- **Re-resolution**: Only called for ambiguous fixes (typically 0-5 per route)
- **Expected overhead**: <10ms for typical routes (most have 0 corrections)
- **Worst case**: Route with many ambiguous fixes — still bounded by route length (~50 waypoints max)

### Migration File

`database/migrations/postgis/012_two_pass_disambiguation.sql`

Changes to `expand_route()`:
1. After forward pass populates result array, add anchor classification step
2. Add correction loop for ambiguous waypoints
3. Update resolved coordinates in result array where correction applies

### No-Context Fallback Fix

Separately fix `resolve_waypoint()` to use `ORDER BY fix_name, lat, lon` in the no-context `LIMIT 1` path. This makes the arbitrary selection at least deterministic (currently returns whatever the query planner picks). Not a substitute for the correction pass, but eliminates nondeterminism.

---

## Out of Scope

- **NAVAID type preservation** (XP12 parser discarding VOR/NDB/DME/TACAN types) — separate issue, tracked in international NAVAID audit
- **Map symbology** (international NAVAIDs rendering as waypoint dots) — depends on type preservation
- **Disambiguation for standalone lookups** (no route context) — separate feature

---

## Test Plan

1. Deploy migration to PostGIS
2. Re-run all 327 test routes through updated `expand_route()`
3. Verify PIKIL routes resolve to N Atlantic, SPP routes resolve to Caribbean
4. Verify trivial shifts (ALB, OGLVE, HFD) resolve to same area (either resolution acceptable)
5. Verify no regressions on clean routes (should produce identical output)
6. Test edge cases: routes starting with UNKN, routes with all-ambiguous fixes, single-waypoint routes
