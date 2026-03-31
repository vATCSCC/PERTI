# GUFI UUID Migration Design

**Date**: 2026-03-29
**Status**: Draft
**Author**: Claude (design), Jeremy Peterson (review)
**Branch**: TBD (will be created during implementation)

## 1. Problem Statement

VATSWIM's current GUFI (Globally Unique Flight Identifier) implementation uses a human-readable format (`VAT-YYYYMMDD-CALLSIGN-DEPT-DEST`) that violates ICAO standards in three ways:

1. **Mutable attributes**: GUFI is derived from callsign, departure, and destination — all of which can change mid-flight. When they do, the GUFI changes, breaking correlation across systems. This violates ICAO Requirement 3 (immutability).
2. **Non-standard format**: ICAO Doc 9965 and FIXM 4.2+ specify UUID format. FAA SFDPS and EUROCONTROL NM both use UUID v4 in production.
3. **Regenerated every sync cycle**: `swim_generate_gufi_sync()` recalculates the GUFI from current attributes every 2 minutes, meaning the same flight can have different GUFIs over its lifecycle.

## 2. Design Decisions (User-Approved)

| Decision | Choice | Rationale |
|----------|--------|-----------|
| UUID version | **UUID v4** (random) | ICAO/FIXM standard; FAA SFDPS + EUROCONTROL NM use v4 |
| Display format | **Keep both** (UUID + legacy) | UUID for machine correlation, legacy for human readability |
| API response format | **FIXM metadata object** | Rich format matching EUROCONTROL NM B2B structure |
| Migration approach | **Big-Bang** (Approach A) | Single migration, no dual-format transition period |

## 3. Database Schema Changes

### 3.1 `swim_flights` Table (SWIM_API)

```sql
-- Step 1: Add new columns (DEFAULT on gufi_created_utc ensures no NULL gap
-- while the sync daemon continues running with old SP during migration)
ALTER TABLE dbo.swim_flights ADD gufi_legacy NVARCHAR(64) NULL;
ALTER TABLE dbo.swim_flights ADD gufi_created_utc DATETIME2(3) NULL
    CONSTRAINT DF_swim_flights_gufi_created_utc DEFAULT SYSUTCDATETIME();

-- Step 2: Backfill gufi_legacy from existing gufi values
UPDATE dbo.swim_flights SET gufi_legacy = gufi WHERE gufi IS NOT NULL;

-- Step 3: Backfill gufi_created_utc from first_seen_utc (best available timestamp)
UPDATE dbo.swim_flights
SET gufi_created_utc = COALESCE(first_seen_utc, inserted_utc, SYSUTCDATETIME());

-- Step 4: Drop old filtered index
DROP INDEX IX_swim_flights_gufi ON dbo.swim_flights;

-- Step 5: Drop existing gufi column
ALTER TABLE dbo.swim_flights DROP COLUMN gufi;

-- Step 6: Add new gufi as UNIQUEIDENTIFIER with DEFAULT NEWID()
-- This auto-generates a UUID v4 for all existing rows
ALTER TABLE dbo.swim_flights ADD gufi UNIQUEIDENTIFIER NOT NULL
    CONSTRAINT DF_swim_flights_gufi DEFAULT NEWID();

-- Step 7: Create indexes
CREATE UNIQUE INDEX IX_swim_flights_gufi ON dbo.swim_flights (gufi);
CREATE INDEX IX_swim_flights_gufi_legacy ON dbo.swim_flights (gufi_legacy)
    WHERE gufi_legacy IS NOT NULL;
```

### 3.2 `swim_handoff_log` Table (SWIM_API, migration 021)

```sql
-- Add gufi_legacy column, rename existing gufi to gufi_legacy
ALTER TABLE dbo.swim_handoff_log ADD gufi_legacy NVARCHAR(64) NULL;
UPDATE dbo.swim_handoff_log SET gufi_legacy = gufi;
-- Keep gufi column as NVARCHAR(64) for now — will store UUID string from swim_flights
-- (This table stores the GUFI value at handoff time, not a foreign key)
```

### 3.3 `swim_acars_messages` Table (SWIM_API, migration 020)

```sql
-- Same pattern: add gufi_legacy, backfill
ALTER TABLE dbo.swim_acars_messages ADD gufi_legacy NVARCHAR(64) NULL;
UPDATE dbo.swim_acars_messages SET gufi_legacy = gufi WHERE gufi IS NOT NULL;
-- Keep gufi as NVARCHAR(64) — will now store UUID string (CONVERT from UNIQUEIDENTIFIER)
```

### 3.4 Database Views

Both views must be recreated to include new columns. The `...` below represents all remaining columns from the existing view definitions (copy from migration 003, lines 213-233 and 235-253 respectively):

```sql
CREATE OR ALTER VIEW dbo.vw_swim_active_flights AS
SELECT
    flight_uid, flight_key, gufi, gufi_legacy, gufi_created_utc,
    callsign, cid, ... -- (remaining columns unchanged from migration 003)
FROM dbo.swim_flights
WHERE is_active = 1;

CREATE OR ALTER VIEW dbo.vw_swim_tmi_controlled AS
SELECT
    flight_uid, flight_key, gufi, gufi_legacy, gufi_created_utc,
    callsign, cid, ... -- (remaining columns unchanged from migration 003)
FROM dbo.swim_flights
WHERE is_active = 1 AND tmi_controlled = 1;
```

### 3.5 Stored Procedure: `sp_Swim_BulkUpsert` v4

**Key changes from v3:**

1. **Remove `gufi` from OPENJSON**: Server generates GUFI, not PHP
2. **Remove `gufi` from UPDATE SET**: Never overwrite an existing UUID
3. **Omit `gufi` from INSERT column list**: `DEFAULT NEWID()` generates it
4. **Add `gufi_legacy` to OPENJSON and INSERT**: PHP passes the legacy format string

```sql
-- OPENJSON extraction (remove gufi, add gufi_legacy):
gufi_legacy NVARCHAR(64),  -- NEW: legacy human-readable identifier
-- DELETE: gufi NVARCHAR(64),

-- UPDATE clause (never touch gufi):
-- DELETE: t.gufi = s.gufi,
t.gufi_legacy = s.gufi_legacy,  -- NEW: update legacy value

-- INSERT column list (omit gufi, add gufi_legacy):
-- gufi column omitted — DEFAULT NEWID() generates UUID automatically
INSERT (..., gufi_legacy, gufi_created_utc, ...)
VALUES (..., s.gufi_legacy, SYSUTCDATETIME(), ...);
```

### 3.6 Stored Procedure: `sp_Swim_GetFlightByGufi` (migration 001)

This SP parses the `VAT-...` format to extract callsign/dept/dest. It needs a complete rewrite:

```sql
CREATE OR ALTER PROCEDURE dbo.sp_Swim_GetFlightByGufi
    @identifier NVARCHAR(100)
AS
BEGIN
    -- Auto-detect format: UUID or legacy
    IF TRY_CONVERT(UNIQUEIDENTIFIER, @identifier) IS NOT NULL
    BEGIN
        -- UUID lookup
        SELECT * FROM dbo.swim_flights WHERE gufi = TRY_CONVERT(UNIQUEIDENTIFIER, @identifier);
    END
    ELSE
    BEGIN
        -- Legacy format lookup
        SELECT * FROM dbo.swim_flights WHERE gufi_legacy = @identifier;
    END
END;
```

## 4. PHP Sync Layer Changes

### 4.1 `load/swim_config.php`

**Remove/deprecate:**
- `swim_generate_gufi()` (line 626-637) — no longer called for new flights
- `swim_parse_gufi()` (line 642-654) — parses legacy format; keep for backward-compat but mark deprecated
- `SWIM_GUFI_PREFIX`, `SWIM_GUFI_SEPARATOR` constants — keep for legacy generator only

**Add:**
- `swim_generate_gufi_legacy()` — renamed version that generates the `VAT-...` format for `gufi_legacy` column only
- `swim_format_gufi_response($uuid, $legacy, $created_utc)` — formats FIXM metadata object

```php
function swim_format_gufi_response($uuid, $legacy, $created_utc) {
    return [
        'value' => $uuid,
        'codeSpace' => 'urn:uuid',
        'creationTime' => $created_utc,
        'namespaceDomain' => 'FULLY_QUALIFIED_DOMAIN_NAME',
        'namespaceIdentifier' => 'vatcscc.org'
    ];
}
```

### 4.2 `scripts/swim_sync.php`

**Before (current):**
```php
$row['gufi'] = swim_generate_gufi_sync($row['callsign'], $row['fp_dept_icao'], ...);
```

**After:**
```php
$row['gufi_legacy'] = swim_generate_gufi_legacy($row['callsign'], $row['fp_dept_icao'], ...);
// gufi (UUID) is NOT passed — SP DEFAULT NEWID() handles it on INSERT
// gufi is NOT updated — SP never overwrites existing UUID
```

### 4.3 `api/swim/v1/flight.php`

**Before (current, line 57-77):**
Decomposes GUFI into parts for lookup (`WHERE callsign = ? AND fp_dept_icao = ? ...`)

**After:**
```php
// Auto-detect format and lookup directly
$identifier = $_GET['gufi'] ?? '';
if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $identifier)) {
    // UUID format — query gufi column
    $sql = "SELECT * FROM dbo.swim_flights WHERE gufi = ?";
    $params = [$identifier];
} else {
    // Legacy format — query gufi_legacy column
    $sql = "SELECT * FROM dbo.swim_flights WHERE gufi_legacy = ?";
    $params = [$identifier];
}
```

### 4.4 `api/swim/v1/flights.php`

**Before (line 308, 450):**
```php
$gufi = $row['gufi'] ?? swim_generate_gufi($row['callsign'], ...);
```

**After:**
```php
// In FIXM format response:
$flight['gufi'] = swim_format_gufi_response(
    $row['gufi'],           // UNIQUEIDENTIFIER → string
    $row['gufi_legacy'],
    $row['gufi_created_utc']
);
// In legacy format response:
$flight['gufi'] = $row['gufi'];  // UUID string
$flight['gufi_legacy'] = $row['gufi_legacy'];
```

## 5. API Response Format

### 5.1 FIXM Format (default)

```json
{
    "gufi": {
        "value": "dd056de9-0ba9-4d55-82cf-7b976b0b6d29",
        "codeSpace": "urn:uuid",
        "creationTime": "2026-03-29T14:30:00Z",
        "namespaceDomain": "FULLY_QUALIFIED_DOMAIN_NAME",
        "namespaceIdentifier": "vatcscc.org"
    },
    "gufi_legacy": "VAT-20260329-UAL123-KJFK-KLAX",
    "flight_uid": 1234567,
    "identity": { ... },
    "flight_plan": { ... }
}
```

### 5.2 Legacy Format

```json
{
    "gufi": "dd056de9-0ba9-4d55-82cf-7b976b0b6d29",
    "gufi_legacy": "VAT-20260329-UAL123-KJFK-KLAX",
    "flight_uid": 1234567,
    "callsign": "UAL123",
    ...
}
```

### 5.3 GUFI Lookup (backward-compatible)

The `/flight?gufi=` parameter accepts both formats:
- UUID: `?gufi=dd056de9-0ba9-4d55-82cf-7b976b0b6d29` → queries `gufi` column
- Legacy: `?gufi=VAT-20260329-UAL123-KJFK-KLAX` → queries `gufi_legacy` column

Format auto-detection uses UUID v4 regex (case-insensitive).

## 6. Ingest Endpoint Changes

### 6.1 `ingest/adl.php` (CRITICAL)

**Current behavior (line 88-91):** Reconstructs GUFI from input attributes to check existence:
```php
$gufi = swim_generate_gufi($callsign, $dept_icao, $dest_icao);
$sql = "SELECT flight_uid FROM dbo.swim_flights WHERE gufi = ?";
```

**Problem:** After migration, this generates a legacy format string but `gufi` column is UUID. Would never match, causing duplicate inserts.

**Fix:** Use `flight_uid` for existence check (already available in the input):
```php
// Primary: lookup by flight_uid (most reliable)
if (!empty($flight_uid)) {
    $sql = "SELECT flight_uid FROM dbo.swim_flights WHERE flight_uid = ?";
    $params = [$flight_uid];
} else {
    // Fallback: callsign + dept + dest + active
    $sql = "SELECT flight_uid FROM dbo.swim_flights
            WHERE callsign = ? AND fp_dept_icao = ? AND fp_dest_icao = ? AND is_active = 1";
    $params = [$callsign, $dept_icao, $dest_icao];
}
```

### 6.2 `ingest/cdm.php` (line 148-152)

**Current:** `WHERE gufi = ? AND is_active = 1`

**Fix:** Use the shared `swim_gufi_lookup_sql()` helper (Section 6.4):
```php
[$where, $params] = swim_gufi_lookup_sql($gufi);
$sql = "SELECT ... FROM dbo.swim_flights $where";
```

### 6.3 `ingest/simtraffic.php` (line 354-358)

Same pattern as CDM — auto-detect and route to correct column.

### 6.4 `ingest/vnas/track.php`, `handoff.php`, `tags.php`, `metering.php`

All use `WHERE gufi = ? AND is_active = 1`. All need the same auto-detect pattern. Extract to a shared helper:

```php
// In common.php or swim_config.php:
function swim_gufi_lookup_sql($identifier) {
    if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $identifier)) {
        return ['WHERE gufi = ? AND is_active = 1', [$identifier]];
    }
    return ['WHERE gufi_legacy = ? AND is_active = 1', [$identifier]];
}
```

### 6.5 `ingest/acars.php` (line 242)

Currently stores `gufi` value in `swim_acars_messages.gufi`. After migration, the value from swim_flights will be a UUID string (CONVERT from UNIQUEIDENTIFIER). The ACARS table keeps `gufi` as NVARCHAR(64) and stores the UUID string representation.

### 6.6 `ingest/vnas/handoff.php` (line 250-256)

Currently stores `$existing['gufi']` in `swim_handoff_log.gufi`. After migration, `$existing['gufi']` will be a UUID string. The handoff_log table keeps `gufi` as NVARCHAR(64) to store the UUID string, plus `gufi_legacy` for the human-readable version.

## 7. External Consumer Changes

### 7.1 `scripts/viff_cdm_poll_daemon.php` (line 320-361)

**Current:** `viff_batch_gufi_lookup()` constructs legacy-format GUFIs and queries `WHERE gufi IN (...)`.

**Fix:** Query `gufi_legacy` column instead:
```php
// Before:
$sql = "SELECT gufi, flight_uid, ... FROM dbo.swim_flights WHERE gufi IN ($placeholders) AND is_active = 1";
// After:
$sql = "SELECT gufi_legacy, gufi, flight_uid, ... FROM dbo.swim_flights WHERE gufi_legacy IN ($placeholders) AND is_active = 1";
```

The fallback cascade (lines 373-409) that uses `callsign + dept + dest` remains unchanged.

### 7.2 `scripts/swim_adl_reverse_sync.php` (line 79)

Read-only consumer — reads `sf.gufi` for display/logging. Will automatically receive UUID string after migration. No code change needed.

### 7.3 GDT System (`api/gdt/programs/publish.php`, `flight_list.php`)

**No changes needed.** GDT uses a completely separate GUFI system:
- Format: `CALLSIGN-DEP-ARR-YYYYMMDD-HHMM`
- Stored in: `tmi_flight_list.flight_gufi` (VATSIM_TMI database)
- Generated by: `generate_gufi()` function local to `publish.php`

This is independent of the SWIM GUFI system.

## 8. SDK Changes

All 6 SDK languages need model updates to support the new GUFI format.

### 8.1 Approach: Backward-Compatible Object Deserialization

Since SDKs use `@JsonIgnoreProperties(ignoreUnknown = true)` (Java), `PropertyNameCaseInsensitive` (C#), and similar patterns, the new `gufi_legacy` and `gufi_created_utc` fields will be silently ignored by old SDK versions. The `gufi` field type change (string → object in FIXM format) **will** break old SDK versions that expect a plain string.

**Mitigation:** Legacy format responses still return `gufi` as a plain UUID string. Only FIXM format returns the rich object. SDKs that use `format=legacy` (default for `getAllFlights`) will continue to work without changes.

### 8.2 PHP SDK (`sdk/php/`)

| File | Change |
|------|--------|
| `src/Models/Flight.php:15` | `public ?string $gufi = null;` → add `public ?string $gufi_legacy = null;` + `public ?string $gufi_created_utc = null;` |
| `src/SwimClient.php` | `getFlightByGufi()` — no change needed (passes as query param, server auto-detects) |
| `README.md` | Update examples to show UUID format |

### 8.3 Python SDK (`sdk/python/`)

| File | Change |
|------|--------|
| `swim_client/models.py:192` | `gufi: str` → keep as `str` (UUID string), add `gufi_legacy: Optional[str] = None`, `gufi_created_utc: Optional[str] = None` |
| `swim_client/rest.py:207` | `get_flight(gufi=...)` — no change (passes as query param) |
| `README.md:153` | Update example: `client.get_flight(gufi='dd056de9-...')` |

### 8.4 JavaScript/TypeScript SDK (`sdk/javascript/`)

| File | Change |
|------|--------|
| `src/types.ts:94` | `gufi: string` → keep as `string \| GufiMetadata`, add `gufi_legacy?: string`, `gufi_created_utc?: string` |
| `src/types.ts` (new) | Add `GufiMetadata` interface: `{ value: string, codeSpace: string, creationTime: string, namespaceDomain: string, namespaceIdentifier: string }` |
| `src/rest.ts:144` | `getFlightByGufi(gufi: string)` — no change (passes as query param) |
| `README.md` | Update examples |

### 8.5 C# SDK (`sdk/csharp/`)

| File | Change |
|------|--------|
| `Models/Flight.cs:42-43` | Keep `public string Gufi` (receives UUID string in legacy format), add `public string? GufiLegacy`, `public string? GufiCreatedUtc` |
| `SwimRestClient.cs:135` | `GetFlightAsync(string? gufi)` — no change (passes as query param) |
| `README.md:126,263` | Update examples |

### 8.6 Java SDK (`sdk/java/`)

| File | Change |
|------|--------|
| `model/Flight.java:12-13` | Keep `private String gufi;` (receives UUID string), add `private String gufiLegacy;`, `private String gufiCreatedUtc;` with getters/setters |
| `SwimRestClient.java:183` | `getFlightByGufi(String gufi)` — no change (passes as query param) |
| `README.md:130,205` | Update examples |

### 8.7 C++ SDK (`sdk/cpp/`)

| File | Change |
|------|--------|
| `include/swim/types.h:29` | `#define SWIM_MAX_GUFI 64` — keep as-is (UUID string = 36 chars + null, fits in 64) |
| No Flight model | C++ SDK is ingest-only (track + ADL data). No Flight deserialization. **No changes needed.** |

## 9. OpenAPI Spec Changes

### 9.1 `docs/swim/openapi.yaml`

**Description section (line 46-50):**
```yaml
## GUFI Format

Globally Unique Flight Identifier (GUFI) uses UUID v4 format per ICAO Doc 9965
and FIXM 4.2+ specifications.

Example UUID: `dd056de9-0ba9-4d55-82cf-7b976b0b6d29`
Legacy format (preserved in gufi_legacy): `VAT-20260329-UAL123-KJFK-KLAX`
```

**Flight schema (line 4455-4462):**
```yaml
Flight:
  type: object
  properties:
    gufi:
      oneOf:
        - type: string
          format: uuid
          description: UUID v4 (legacy response format)
        - $ref: '#/components/schemas/GufiMetadata'
          description: FIXM metadata object (FIXM response format)
    gufi_legacy:
      type: string
      description: Human-readable legacy identifier (VAT-YYYYMMDD-CALLSIGN-DEPT-DEST)
    gufi_created_utc:
      type: string
      format: date-time
      description: UTC timestamp when the GUFI was first assigned
```

**New schema:**
```yaml
GufiMetadata:
  type: object
  description: FIXM-compliant GUFI metadata (per EUROCONTROL NM B2B format)
  properties:
    value:
      type: string
      format: uuid
    codeSpace:
      type: string
      enum: [urn:uuid]
    creationTime:
      type: string
      format: date-time
    namespaceDomain:
      type: string
      enum: [FULLY_QUALIFIED_DOMAIN_NAME]
    namespaceIdentifier:
      type: string
      example: vatcscc.org
```

## 10. Migration Strategy

### 10.1 Deployment Order

The migration must be deployed atomically (single deployment push):

1. **Database migration** (run first, manually via SSMS or migration script)
   - Add columns, backfill, drop/recreate gufi, rebuild indexes
   - Update views
   - Deploy `sp_Swim_BulkUpsert` v4
   - Update `sp_Swim_GetFlightByGufi`

2. **PHP code deployment** (GitHub push to main)
   - Updated `swim_config.php` (new helper functions)
   - Updated `swim_sync.php` (stop passing gufi, pass gufi_legacy)
   - Updated `flight.php`, `flights.php` (new response format)
   - Updated all ingest endpoints (auto-detect lookup)
   - Updated `viff_cdm_poll_daemon.php` (query gufi_legacy)

3. **SDK + OpenAPI updates** (same push, but SDKs are client-side)
   - Updated model files for all 6 SDKs
   - Updated OpenAPI spec
   - Updated README examples

### 10.2 Deployment Sequence

```
1. Take snapshot of swim_flights table (backup)
2. Run database migration SQL in SSMS (jpeterson admin creds)
3. Verify: SELECT TOP 10 gufi, gufi_legacy, gufi_created_utc FROM swim_flights
4. Deploy SP v4
5. Push PHP + SDK changes to main (triggers GitHub Actions deploy)
6. After deploy: restart App Service to pick up new PHP code
7. Verify: curl swim API, check GUFI format in response
8. Monitor: watch swim_sync_daemon logs for 10 minutes
```

### 10.3 Rollback Plan

If issues arise after deployment:

1. **PHP rollback**: `git revert` the commit, push to main
2. **SP rollback**: Re-deploy `sp_Swim_BulkUpsert` v3 (migration 031)
3. **Column rollback**:
   ```sql
   -- Recreate old gufi column from gufi_legacy
   DROP INDEX IX_swim_flights_gufi ON dbo.swim_flights;
   ALTER TABLE dbo.swim_flights DROP CONSTRAINT DF_swim_flights_gufi;
   ALTER TABLE dbo.swim_flights DROP COLUMN gufi;
   EXEC sp_rename 'dbo.swim_flights.gufi_legacy', 'gufi', 'COLUMN';
   CREATE INDEX IX_swim_flights_gufi ON dbo.swim_flights (gufi) WHERE gufi IS NOT NULL;
   ```

### 10.4 Backfill Details

For existing `swim_flights` rows at migration time:
- **`gufi`**: Auto-generated by `DEFAULT NEWID()` during ALTER TABLE ADD COLUMN
- **`gufi_legacy`**: Copied from old `gufi` column before DROP
- **`gufi_created_utc`**: Set to `COALESCE(first_seen_utc, inserted_utc, SYSUTCDATETIME())`

Estimated table size: Active flights (~500-2,000 rows) + recent completed (varies). The ALTER TABLE ADD with DEFAULT NEWID() writes to every row but should complete in seconds for this table size.

## 11. Files Changed Summary

### Database (5 objects)
| Object | Type | Change |
|--------|------|--------|
| `swim_flights` | Table | `gufi` NVARCHAR→UNIQUEIDENTIFIER, add `gufi_legacy`, `gufi_created_utc` |
| `swim_handoff_log` | Table | Add `gufi_legacy` column |
| `swim_acars_messages` | Table | Add `gufi_legacy` column |
| `vw_swim_active_flights` | View | Add `gufi_legacy`, `gufi_created_utc` |
| `vw_swim_tmi_controlled` | View | Add `gufi_legacy`, `gufi_created_utc` |

### Stored Procedures (2)
| SP | Change |
|----|--------|
| `sp_Swim_BulkUpsert` | v4: Remove gufi from OPENJSON/UPDATE, add gufi_legacy, omit gufi from INSERT |
| `sp_Swim_GetFlightByGufi` | Rewrite: auto-detect UUID vs legacy format |

### PHP Files (11)
| File | Change |
|------|--------|
| `load/swim_config.php` | Rename `swim_generate_gufi()` → `swim_generate_gufi_legacy()`, add `swim_format_gufi_response()`, add `swim_gufi_lookup_sql()` |
| `scripts/swim_sync.php` | Stop passing `gufi`, pass `gufi_legacy` instead |
| `api/swim/v1/flight.php` | Auto-detect GUFI format for lookup, return FIXM metadata |
| `api/swim/v1/flights.php` | Update response formatting, remove fallback generation |
| `api/swim/v1/ingest/adl.php` | Replace GUFI-based existence check with flight_uid lookup |
| `api/swim/v1/ingest/cdm.php` | Auto-detect GUFI format for lookup |
| `api/swim/v1/ingest/simtraffic.php` | Auto-detect GUFI format for lookup |
| `api/swim/v1/ingest/vnas/track.php` | Auto-detect GUFI format for lookup |
| `api/swim/v1/ingest/vnas/handoff.php` | Auto-detect + store gufi_legacy in handoff_log |
| `api/swim/v1/ingest/vnas/tags.php` | Auto-detect GUFI format for lookup |
| `api/swim/v1/ingest/metering.php` | Auto-detect GUFI format for lookup |

### External Consumers (1)
| File | Change |
|------|--------|
| `scripts/viff_cdm_poll_daemon.php` | Query `gufi_legacy` column instead of `gufi` |

### SDKs (12 files across 5 languages)
| SDK | Files | Change |
|-----|-------|--------|
| PHP | `Models/Flight.php`, `README.md` | Add `gufi_legacy`, `gufi_created_utc` properties |
| Python | `models.py`, `README.md` | Add optional fields |
| JavaScript | `types.ts`, `README.md` | Add `GufiMetadata` interface, optional fields |
| C# | `Models/Flight.cs`, `README.md` | Add nullable properties |
| Java | `model/Flight.java`, `README.md` | Add fields + getters/setters |
| C++ | (none) | No changes — ingest-only SDK |

### Documentation (2)
| File | Change |
|------|--------|
| `docs/swim/openapi.yaml` | Update GUFI description, Flight schema, add GufiMetadata schema |
| `api-docs/openapi.yaml` | No GUFI references (confirmed) — no changes |

### NOT Changed (confirmed by audit)
| File/System | Reason |
|-------------|--------|
| GDT `publish.php` / `flight_list.php` | Separate GUFI system in VATSIM_TMI |
| `tmi_flight_list.flight_gufi` | Independent TMI GUFI format |
| `scripts/swim_ws_server.php` | No GUFI references |
| `scripts/simtraffic_swim_poll.php` | No GUFI references |
| `scripts/swim_adl_reverse_sync.php` | Read-only — auto-receives UUID |
| `lib/DateTime.php` | `gufiDate()` utility — still used by legacy generator |

## 12. Risk Assessment

| Risk | Likelihood | Impact | Mitigation |
|------|-----------|--------|------------|
| SDK consumers break on GUFI type change | Medium | Medium | Legacy format still returns plain string; only FIXM format returns object |
| vIFF CDM poll fails to match flights | High if missed | High | Must deploy PHP change with DB migration simultaneously |
| External ingest sends legacy GUFI | Medium | Low | Auto-detect handles both formats transparently |
| ALTER TABLE slow on large swim_flights | Low | Low | Table is small (active flights only ~2K rows) |
| SP deadlock during deployment | Low | Low | Auto-recovers (known behavior from MEMORY.md) |

## 13. Testing Plan

1. **Pre-deployment**: Query `SELECT COUNT(*), COUNT(gufi) FROM dbo.swim_flights` to baseline
2. **Post-migration SQL**: Verify `SELECT TOP 10 gufi, gufi_legacy, gufi_created_utc FROM dbo.swim_flights` shows UUID + legacy + timestamp
3. **Post-deployment API**:
   - `GET /api/swim/v1/flights?format=fixm` — verify GUFI metadata object
   - `GET /api/swim/v1/flights?format=legacy` — verify UUID string in gufi field
   - `GET /api/swim/v1/flight?gufi=<uuid>` — verify UUID lookup works
   - `GET /api/swim/v1/flight?gufi=VAT-...` — verify legacy lookup works
4. **Sync verification**: Wait 2-5 minutes, verify new flights get UUIDs and legacy values
5. **vIFF verification**: Check viff_cdm_poll_daemon logs for successful GUFI lookups
