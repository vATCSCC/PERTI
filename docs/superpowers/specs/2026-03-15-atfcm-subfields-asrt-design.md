# ATFCM Sub-fields and ASRT Support

**Date:** 2026-03-15
**Status:** Approved
**Scope:** Data layer only (schema + daemon + ingest endpoint)

## Context

CDM Plugin v2.2.8.25 (rpuig2001/CDM) introduces:

1. **ATFCM Panel** — FMP controls for EXCL/REA/SIR with individual boolean flags in `atfcmData`
2. **ASRT** — Actual Startup Request Time from pilot, exposed via `/ifps/depAirport`
3. **CTOT handling improvements** — better slot priority and guard logic (plugin-side only)

Our vIFF polling daemon (`scripts/viff_cdm_poll_daemon.php`) already polls three vIFF endpoints and writes A-CDM milestones to `SWIM_API.swim_flights`. However:

- The daemon only checks `atfcmData.excluded` to derive `eu_atfcm_status` — it discards `isRea` and `SIR` sub-fields
- No ASRT column exists on `swim_flights`
- The `/ifps/depAirport` endpoint (which provides ASRT) is not polled

This migration adds the missing columns, updates the daemon to capture all sub-fields, and extends the CDM ingest endpoint to accept ASRT from external sources.

## Schema Changes

### New columns on `SWIM_API.dbo.swim_flights`

| Column | Type | Default | FIXM/Standard Mapping |
|--------|------|---------|----------------------|
| `actual_startup_request_time` | `DATETIME2(0)` | `NULL` | FIXM 4.3 EUR `actualStartUpRequestTime` |
| `eu_atfcm_excluded` | `BIT` | `0` | NM B2B `exclusionFromRegulations` |
| `eu_atfcm_ready` | `BIT` | `0` | NM B2B `readyStatus` (REA/NOT_REA) |
| `eu_atfcm_slot_improvement` | `BIT` | `0` | NM B2B `slotImprovementProposal` |

### A-CDM milestone chain (complete after this migration)

```
TOBT → ASRT → TSAT → ASAT → TTOT/CTOT → AOBT → ATOT
 ↑       ↑      ↑      ↑       ↑          ↑      ↑
 014    028    014    023     014         019    019    ← migration that added each
```

### Index

Filtered index on the three BIT columns:

```sql
CREATE NONCLUSTERED INDEX IX_swim_flights_atfcm_flags
ON dbo.swim_flights (eu_atfcm_excluded, eu_atfcm_ready, eu_atfcm_slot_improvement)
WHERE eu_atfcm_excluded = 1 OR eu_atfcm_ready = 1 OR eu_atfcm_slot_improvement = 1;
```

### Backfill

Backfill existing data for consistency with daemon logic (line 783-784):

```sql
UPDATE dbo.swim_flights
SET eu_atfcm_excluded = 1
WHERE eu_atfcm_status = 'EXCLUDED'
  AND eu_atfcm_excluded = 0;
```

Targets all flights (active and historical) since the flag should reflect what was recorded. Idempotent — safe to re-run. Leaves `eu_atfcm_ready` and `eu_atfcm_slot_improvement` at their defaults (0) since those sub-fields were never captured before this migration.

## Daemon Changes (`scripts/viff_cdm_poll_daemon.php`)

### A. Parse ATFCM sub-fields from `/etfms/relevant`

In `viff_update_flight()`, add three SET clauses when `atfcmData` is present:

```
eu_atfcm_excluded         = (bool) $f['atfcmData']['excluded']
eu_atfcm_ready            = (bool) $f['atfcmData']['isRea']
eu_atfcm_slot_improvement = (bool) $f['atfcmData']['SIR']
```

The existing `eu_atfcm_status` derivation from the top-level `atfcmStatus` field is unchanged.

### B. Secondary poll phase for `/ifps/depAirport`

After the existing 3-endpoint parallel fetch (Step A in `viff_poll()`), add a new Step A2:

1. Extract unique departure ICAO codes from the `/etfms/relevant` response (flights where `isCdm = true`)
2. Cap at 50 airports per cycle to bound HTTP fanout (skip if more — log warning)
3. Build URLs: `/ifps/depAirport?airport={ICAO}` for each unique airport
4. Fetch all in parallel via existing `viff_fetch_multi()`
5. Parse `cdmData.reqAsrt` from each flight record; convert HHMM to ISO 8601 via existing `viff_time_to_iso()`
6. Match flights to `swim_flights` using the same GUFI/fallback strategy already in the daemon
7. Write `actual_startup_request_time` where ASRT is non-empty

**Error handling**: Per-airport failures are logged but do not block other airports or trigger the circuit breaker. Only the main 3-endpoint fetch can trip the breaker. An airport returning an empty array is silently skipped (no cache entry, re-fetched next cycle).

### ASRT cache

ASRT values from `/ifps/depAirport` use a separate lightweight cache to avoid redundant writes:

- Cache key: `ASRT:{callsign}:{dept_icao}` → last written ASRT value (includes departure to avoid callsign reuse collisions)
- Stored in the existing `VIFF_CACHE_FILE` alongside the main flight hash cache
- Only triggers a DB write when the ASRT value changes

### ASRT source precedence

Both the vIFF daemon and the CDM ingest endpoint can write `actual_startup_request_time`. Last writer wins — consistent with how all other CDM milestone columns behave (`cdm_source` and `cdm_updated_at` track provenance).

### vIFF API response formats

**`/etfms/relevant` response** (existing, sub-fields now captured):
```json
{
  "callsign": "BAW123",
  "departure": "EGLL",
  "arrival": "KJFK",
  "eobt": "1430",
  "tobt": "1435",
  "ctot": "1502",
  "taxi": "5",
  "aobt": "",
  "atot": "",
  "atfcmStatus": "REA",
  "atfcmData": {
    "excluded": false,
    "isRea": true,
    "SIR": false
  },
  "isCdm": true
}
```

**`/ifps/depAirport?airport=EGLL` response** (new poll):
```json
[
  {
    "callsign": "BAW123",
    "atot": "",
    "cdmData": {
      "reqTobt": "1435",
      "reqTobtType": "manual",
      "reqAsrt": "1432"
    }
  }
]
```

## CDM Ingest Endpoint Changes (`api/swim/v1/ingest/cdm.php`)

Four new optional fields in `processCdmUpdate()`:

| Payload Field | Column | Validation |
|---|---|---|
| `asrt` | `actual_startup_request_time` | ISO 8601 datetime |
| `atfcm_excluded` | `eu_atfcm_excluded` | Boolean (`true`/`false`, `1`/`0`) |
| `atfcm_ready` | `eu_atfcm_ready` | Boolean (`true`/`false`, `1`/`0`) |
| `atfcm_slot_improvement` | `eu_atfcm_slot_improvement` | Boolean (`true`/`false`, `1`/`0`) |

Boolean fields accept JSON `true`/`false` or integer `1`/`0`. Non-boolean values are silently ignored (field skipped, no error). ASRT follows the same ISO 8601 pattern as `asat` — invalid datetimes fail via `TRY_CONVERT` returning NULL.

No changes to auth, batch limits, or readiness state logic.

Updated payload example:
```json
{
  "updates": [
    {
      "callsign": "BAW123",
      "airport": "EGLL",
      "tobt": "2026-03-15T14:35:00Z",
      "asrt": "2026-03-15T14:32:00Z",
      "atfcm_excluded": false,
      "atfcm_ready": true,
      "atfcm_slot_improvement": false,
      "source": "VACDM"
    }
  ]
}
```

## Files Changed

| File | Change Type | Description |
|------|-------------|-------------|
| `database/migrations/swim/028_atfcm_subfields_asrt.sql` | New | Schema (4 columns), filtered index, backfill |
| `scripts/viff_cdm_poll_daemon.php` | Modify | Parse atfcmData sub-fields; add `/ifps/depAirport` poll phase with ASRT cache |
| `api/swim/v1/ingest/cdm.php` | Modify | Accept `asrt`, `atfcm_excluded`, `atfcm_ready`, `atfcm_slot_improvement` |

## What This Does NOT Include

- No UI/dashboard changes (data layer only)
- No bidirectional DPI commands (POST to vIFF) — future work
- No stored procedure changes
- No changes to the CDMService class or compliance engine
- No changes to the SWIM sync daemon — it syncs ADL→SWIM with a hardcoded column list; these new columns are written directly to `swim_flights` by the vIFF daemon and CDM ingest endpoint, never sourced from ADL
