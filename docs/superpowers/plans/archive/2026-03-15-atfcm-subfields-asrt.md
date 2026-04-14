# ATFCM Sub-fields and ASRT Implementation Plan

> **For agentic workers:** REQUIRED: Use superpowers:subagent-driven-development (if subagents available) or superpowers:executing-plans to implement this plan. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add ATFCM regulatory sub-fields and ASRT milestone column to swim_flights, update vIFF daemon to capture them, and extend CDM ingest endpoint to accept them.

**Architecture:** Single migration adds 4 columns to swim_flights. The vIFF daemon gets ATFCM sub-field parsing in its existing update function plus a new `/ifps/depAirport` poll phase for ASRT data. The CDM ingest endpoint accepts 4 new optional fields.

**Tech Stack:** Azure SQL (sqlsrv), PHP 8.2, curl_multi, SWIM_API database

**Spec:** `docs/superpowers/specs/2026-03-15-atfcm-subfields-asrt-design.md`

**Note:** This project has no automated test suite. Verification is via manual SQL queries and API calls against Azure SQL.

---

## File Map

| File | Action | Responsibility |
|------|--------|----------------|
| `database/migrations/swim/028_atfcm_subfields_asrt.sql` | Create | Schema: 4 columns, filtered index, backfill |
| `scripts/viff_cdm_poll_daemon.php` | Modify | Parse atfcmData sub-fields in `viff_update_flight()`; add `/ifps/depAirport` ASRT poll phase in `viff_poll()` |
| `api/swim/v1/ingest/cdm.php` | Modify | Accept `asrt`, `atfcm_excluded`, `atfcm_ready`, `atfcm_slot_improvement` in `processCdmUpdate()` |

---

## Chunk 1: Migration SQL

### Task 1: Create migration 028

**Files:**
- Create: `database/migrations/swim/028_atfcm_subfields_asrt.sql`

- [ ] **Step 1: Write the migration file**

Follow the pattern from `027_viff_cdm_integration.sql` — USE SWIM_API, IF NOT EXISTS guards, GO between statements.

```sql
-- ============================================================================
-- Migration 028: ATFCM Sub-fields and ASRT
--
-- Adds ATFCM regulatory sub-fields (excluded/ready/slot_improvement) and
-- ASRT (Actual Startup Request Time) milestone to swim_flights.
--
-- Supports CDM Plugin v2.2.8.25+ which exposes individual atfcmData flags
-- and ASRT via the /ifps/depAirport endpoint.
--
-- Run with DDL admin (jpeterson):
--   sqlcmd -S vatsim.database.windows.net -U jpeterson -P Jhp21012 -d SWIM_API -i 028_atfcm_subfields_asrt.sql
-- ============================================================================

USE SWIM_API;
GO

-- ASRT — Actual Startup Request Time (FIXM 4.3 EUR: actualStartUpRequestTime)
-- When the pilot requests startup clearance. Completes the A-CDM chain:
-- TOBT → ASRT → TSAT → ASAT → TTOT/CTOT → AOBT → ATOT
IF NOT EXISTS (
    SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_NAME = 'swim_flights' AND COLUMN_NAME = 'actual_startup_request_time'
)
ALTER TABLE dbo.swim_flights ADD actual_startup_request_time DATETIME2(0) NULL;
GO

-- EU ATFCM excluded flag (NM B2B: exclusionFromRegulations)
-- Flight excluded from ATFCM regulation
IF NOT EXISTS (
    SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_NAME = 'swim_flights' AND COLUMN_NAME = 'eu_atfcm_excluded'
)
ALTER TABLE dbo.swim_flights ADD eu_atfcm_excluded BIT NOT NULL DEFAULT 0;
GO

-- EU ATFCM ready flag (NM B2B: readyStatus)
-- Flight is departure-ready and eligible for slot improvement
IF NOT EXISTS (
    SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_NAME = 'swim_flights' AND COLUMN_NAME = 'eu_atfcm_ready'
)
ALTER TABLE dbo.swim_flights ADD eu_atfcm_ready BIT NOT NULL DEFAULT 0;
GO

-- EU ATFCM slot improvement flag (NM B2B: slotImprovementProposal)
-- Active Slot Improvement Request
IF NOT EXISTS (
    SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_NAME = 'swim_flights' AND COLUMN_NAME = 'eu_atfcm_slot_improvement'
)
ALTER TABLE dbo.swim_flights ADD eu_atfcm_slot_improvement BIT NOT NULL DEFAULT 0;
GO

-- Filtered index on ATFCM regulatory flags
-- Supports CDM dashboard queries filtering on regulatory state
IF NOT EXISTS (
    SELECT 1 FROM sys.indexes
    WHERE name = 'IX_swim_flights_atfcm_flags' AND object_id = OBJECT_ID('dbo.swim_flights')
)
CREATE NONCLUSTERED INDEX IX_swim_flights_atfcm_flags
ON dbo.swim_flights (eu_atfcm_excluded, eu_atfcm_ready, eu_atfcm_slot_improvement)
WHERE eu_atfcm_excluded = 1 OR eu_atfcm_ready = 1 OR eu_atfcm_slot_improvement = 1;
GO

-- Backfill: sync eu_atfcm_excluded with existing eu_atfcm_status = 'EXCLUDED'
UPDATE dbo.swim_flights
SET eu_atfcm_excluded = 1
WHERE eu_atfcm_status = 'EXCLUDED'
  AND eu_atfcm_excluded = 0;
GO

PRINT 'Migration 028: ATFCM Sub-fields and ASRT complete';
GO
```

- [ ] **Step 2: Deploy migration to Azure SQL**

```bash
sqlcmd -S vatsim.database.windows.net -U jpeterson -P Jhp21012 -d SWIM_API -i database/migrations/swim/028_atfcm_subfields_asrt.sql
```

Expected: `Migration 028: ATFCM Sub-fields and ASRT complete`

- [ ] **Step 3: Verify columns exist**

```sql
SELECT COLUMN_NAME, DATA_TYPE, IS_NULLABLE, COLUMN_DEFAULT
FROM SWIM_API.INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_NAME = 'swim_flights'
  AND COLUMN_NAME IN ('actual_startup_request_time', 'eu_atfcm_excluded', 'eu_atfcm_ready', 'eu_atfcm_slot_improvement');
```

Expected: 4 rows — DATETIME2/NULL, BIT/NOT NULL/0, BIT/NOT NULL/0, BIT/NOT NULL/0

- [ ] **Step 4: Verify index exists**

```sql
SELECT name, filter_definition FROM sys.indexes
WHERE object_id = OBJECT_ID('SWIM_API.dbo.swim_flights')
  AND name = 'IX_swim_flights_atfcm_flags';
```

Expected: 1 row with filter containing the OR clause

- [ ] **Step 5: Commit migration file**

```bash
git add database/migrations/swim/028_atfcm_subfields_asrt.sql
git commit -m "feat: add ATFCM sub-fields and ASRT to swim_flights (migration 028)"
```

---

## Chunk 2: Daemon — ATFCM Sub-fields

### Task 2: Parse ATFCM sub-fields in `viff_update_flight()`

**Files:**
- Modify: `scripts/viff_cdm_poll_daemon.php` — `viff_update_flight()` function (lines 702-830)

- [ ] **Step 1: Add ATFCM sub-field SET clauses**

In `viff_update_flight()`, after the existing `eu_atfcm_status` block (lines 781-791), add parsing for the three BIT columns. Insert this block immediately after line 791 (`}`):

```php
    // ATFCM sub-fields (individual regulatory flags from atfcmData)
    if (isset($f['atfcmData']) && is_array($f['atfcmData'])) {
        $setClauses[] = 'eu_atfcm_excluded = ?';
        $params[] = !empty($f['atfcmData']['excluded']) ? 1 : 0;

        $setClauses[] = 'eu_atfcm_ready = ?';
        $params[] = !empty($f['atfcmData']['isRea']) ? 1 : 0;

        $setClauses[] = 'eu_atfcm_slot_improvement = ?';
        $params[] = !empty($f['atfcmData']['SIR']) ? 1 : 0;
    }
```

This uses `!empty()` which handles missing keys, null, false, 0, and "" safely — all map to 0. Only explicit truthy values map to 1.

- [ ] **Step 2: Verify no syntax errors**

```bash
php -l scripts/viff_cdm_poll_daemon.php
```

Expected: `No syntax errors detected`

- [ ] **Step 3: Commit**

```bash
git add scripts/viff_cdm_poll_daemon.php
git commit -m "feat(viff): parse ATFCM sub-fields (excluded/ready/slot_improvement)"
```

---

## Chunk 3: Daemon — ASRT Poll Phase

### Task 3: Add `/ifps/depAirport` poll phase for ASRT

**Files:**
- Modify: `scripts/viff_cdm_poll_daemon.php` — `viff_poll()` function (lines 431-664)

This is the largest change. We add:
1. A new helper function `viff_update_asrt()` that writes ASRT to a single flight
2. A new Step A2 in `viff_poll()` between the existing Step A (fetch) and Step B (load cache)

- [ ] **Step 1: Add `viff_update_asrt()` helper function**

Insert this new function before the `viff_poll()` function (before line 431). Place it after the `viff_match_flight_fallback()` function (after line 413):

```php
/**
 * Update actual_startup_request_time (ASRT) for a matched flight.
 *
 * @param resource $conn_swim SWIM database connection
 * @param int $flightUid Matched flight UID
 * @param string $asrtIso ISO 8601 datetime for ASRT
 * @param bool $debug Debug logging
 * @return bool True if row was updated
 */
function viff_update_asrt($conn_swim, int $flightUid, string $asrtIso, bool $debug): bool {
    $sql = "UPDATE dbo.swim_flights
            SET actual_startup_request_time = TRY_CONVERT(datetime2, ?),
                cdm_source = 'VIFF_CDM',
                cdm_updated_at = GETUTCDATE(),
                last_sync_utc = GETUTCDATE()
            WHERE flight_uid = ?";
    $stmt = sqlsrv_query($conn_swim, $sql, [$asrtIso, $flightUid]);
    if ($stmt === false) {
        $err = sqlsrv_errors();
        viff_log("ASRT update failed for uid=$flightUid: " . ($err[0]['message'] ?? 'Unknown'), 'ERROR');
        return false;
    }
    $rows = sqlsrv_rows_affected($stmt);
    sqlsrv_free_stmt($stmt);

    if ($debug && $rows > 0) {
        viff_log("  ASRT updated: uid=$flightUid asrt=$asrtIso", 'DEBUG');
    }
    return $rows > 0;
}
```

- [ ] **Step 2: Add `$recordErrors` parameter to `viff_fetch_multi()`**

The ASRT poll uses `viff_fetch_multi()` which calls `viff_record_error()` on every HTTP failure (line 211) and JSON parse failure (line 217). Per-airport ASRT failures must NOT trip the circuit breaker (spec: "Per-airport failures are logged but do not block other airports or trigger the circuit breaker"). Add an optional parameter:

Change the function signature (line 165):
```php
function viff_fetch_multi(array $urls, bool $recordErrors = true): array {
```

Then wrap the two `viff_record_error()` calls (lines 211 and 217) in conditionals:

At line 211, change:
```php
            viff_record_error();
```
to:
```php
            if ($recordErrors) viff_record_error();
```

At line 217, change:
```php
                viff_record_error();
```
to:
```php
                if ($recordErrors) viff_record_error();
```

The logging (`viff_log(... 'ERROR')`) remains unconditional — we still want to see failures in the log, just not trip the breaker.

- [ ] **Step 3: Add ASRT poll phase in `viff_poll()`**

In `viff_poll()`, insert the ASRT polling logic between the end of Step A (line 496, after the statusMap block) and Step B (line 501, `// Step B: Load cache`). Add a new `$stats` key for ASRT tracking:

First, add `'asrt_updated' => 0` to the `$stats` array initialization (after line 440, add to the array):

```php
        'asrt_updated' => 0,
```

Then insert the ASRT poll phase after line 496:

```php
    // -------------------------------------------------------------------------
    // Step A2: Fetch ASRT data from /ifps/depAirport (per-airport)
    // -------------------------------------------------------------------------
    $asrtUpdated = 0;

    // Extract unique departure airports from CDM flights
    $cdmAirports = [];
    foreach ($flights as $f) {
        if (!empty($f['isCdm']) && !empty($f['departure'])) {
            $dept = strtoupper(trim($f['departure']));
            if ($dept !== '') {
                $cdmAirports[$dept] = true;
            }
        }
    }
    $cdmAirports = array_keys($cdmAirports);

    if (count($cdmAirports) > 50) {
        viff_log("ASRT poll: " . count($cdmAirports) . " airports exceeds cap of 50 — skipping", 'WARN');
        $cdmAirports = [];
    }

    if (!empty($cdmAirports)) {
        // Build per-airport URLs
        $airportUrls = [];
        foreach ($cdmAirports as $icao) {
            $airportUrls["dep_$icao"] = VIFF_API_BASE . '/ifps/depAirport?airport=' . $icao;
        }

        if ($debug) viff_log("  ASRT poll: " . count($airportUrls) . " airports", 'DEBUG');

        // Fetch all in parallel (recordErrors=false: per-airport failures don't trip breaker)
        $airportResponses = viff_fetch_multi($airportUrls, false);

        // Load ASRT cache (separate from main flight hash cache)
        $asrtCache = [];
        if (file_exists(VIFF_CACHE_FILE)) {
            $allCache = json_decode(file_get_contents(VIFF_CACHE_FILE), true) ?: [];
            foreach ($allCache as $k => $v) {
                if (strpos($k, 'ASRT:') === 0) {
                    $asrtCache[$k] = $v;
                }
            }
        }
        $newAsrtCache = [];

        foreach ($airportResponses as $key => $data) {
            if ($data === null || !is_array($data)) continue;

            $icao = substr($key, 4); // strip "dep_" prefix

            foreach ($data as $record) {
                $callsign = strtoupper(trim($record['callsign'] ?? ''));
                if ($callsign === '') continue;

                $reqAsrt = trim($record['cdmData']['reqAsrt'] ?? '');
                if ($reqAsrt === '' || $reqAsrt === '0' || $reqAsrt === '0000') continue;

                // Convert HHMM to ISO 8601
                $asrtIso = viff_time_to_iso($reqAsrt);
                if ($asrtIso === null) continue;

                // Cache check: skip if unchanged
                $cacheKey = "ASRT:$callsign:$icao";
                $newAsrtCache[$cacheKey] = $asrtIso;
                if (isset($asrtCache[$cacheKey]) && $asrtCache[$cacheKey] === $asrtIso) {
                    continue;
                }

                // Skip airborne flights (atot present means already departed)
                $atot = trim($record['atot'] ?? '');
                if ($atot !== '' && $atot !== '0' && $atot !== '0000') continue;

                // Match flight to swim_flights
                $match = viff_match_flight_fallback($conn_swim, [
                    'callsign' => $callsign,
                    'departure' => $icao,
                    'arrival' => '',
                ]);

                if (!$match) {
                    if ($debug) viff_log("  ASRT not found: $callsign ($icao)", 'DEBUG');
                    continue;
                }

                // Write ASRT
                if (viff_update_asrt($conn_swim, $match['flight_uid'], $asrtIso, $debug)) {
                    $asrtUpdated++;
                }
            }
        }

        // Merge ASRT cache entries back into main cache file
        // (done later in Step F alongside main cache write)
        $stats['_asrtCache'] = $newAsrtCache;
    }

    $stats['asrt_updated'] = $asrtUpdated;
```

- [ ] **Step 4: Merge ASRT cache into BOTH cache write paths**

The cache is written in two places: (1) the early return at line 559 when no main flights changed, and (2) Step F finalize at line 655. ASRT cache must be merged in BOTH paths or ASRT values get lost on cycles where main flight data is unchanged, causing redundant DB writes every 30s.

**Path 1: Early return (line 557-561)**

Replace:
```php
    if (empty($changedFlights)) {
        if ($debug) viff_log("  No changed flights — skipping DB operations", 'DEBUG');
        @file_put_contents(VIFF_CACHE_FILE, json_encode($newCache), LOCK_EX);
        viff_update_provider_sync($stats);
        return $stats;
    }
```

With:
```php
    if (empty($changedFlights)) {
        if ($debug) viff_log("  No changed flights — skipping DB operations", 'DEBUG');
        // Merge ASRT cache entries before writing (even when no main flights changed)
        if (!empty($stats['_asrtCache'])) {
            foreach ($stats['_asrtCache'] as $k => $v) {
                $newCache[$k] = $v;
            }
            unset($stats['_asrtCache']);
        }
        @file_put_contents(VIFF_CACHE_FILE, json_encode($newCache), LOCK_EX);
        viff_update_provider_sync($stats);
        return $stats;
    }
```

**Path 2: Step F finalize (line 655)**

Replace:
```php
@file_put_contents(VIFF_CACHE_FILE, json_encode($newCache), LOCK_EX);
```

With:
```php
    // Merge ASRT cache entries into main cache before writing
    if (!empty($stats['_asrtCache'])) {
        foreach ($stats['_asrtCache'] as $k => $v) {
            $newCache[$k] = $v;
        }
        unset($stats['_asrtCache']);
    }
    @file_put_contents(VIFF_CACHE_FILE, json_encode($newCache), LOCK_EX);
```

- [ ] **Step 5: Add asrt_updated to log output**

In the main loop (line ~909), the stats format string is:
```php
$msg = sprintf('fetched=%d updated=%d not_found=%d unchanged=%d skipped=%d cache_hits=%d errors=%d',
```

Add `asrt_updated` to the format:

```php
$msg = sprintf('fetched=%d updated=%d not_found=%d unchanged=%d skipped=%d cache_hits=%d errors=%d asrt=%d',
    $stats['fetched'], $stats['updated'], $stats['not_found'],
    $stats['unchanged'], $stats['skipped'], $stats['cache_hits'], $stats['errors'],
    $stats['asrt_updated']);
```

Also add `asrt_updated` to the provider sync message in `viff_update_provider_sync()` (line ~673):

```php
$syncMsg = sprintf('%d fetched, %d updated, %d not found, %d unchanged, %d skipped, %d cache_hits, %d asrt',
    $stats['fetched'], $stats['updated'], $stats['not_found'],
    $stats['unchanged'], $stats['skipped'], $stats['cache_hits'],
    $stats['asrt_updated'] ?? 0);
```

- [ ] **Step 6: Verify no syntax errors**

```bash
php -l scripts/viff_cdm_poll_daemon.php
```

Expected: `No syntax errors detected`

- [ ] **Step 7: Commit**

```bash
git add scripts/viff_cdm_poll_daemon.php
git commit -m "feat(viff): add /ifps/depAirport ASRT poll phase with per-airport parallel fetch"
```

---

## Chunk 4: CDM Ingest Endpoint

### Task 4: Extend CDM ingest endpoint with new fields

**Files:**
- Modify: `api/swim/v1/ingest/cdm.php` — `processCdmUpdate()` function (lines 134-292)

- [ ] **Step 1: Add ASRT field handling**

In `processCdmUpdate()`, after the ASAT block (lines 207-211), add:

```php
    // ASRT - Actual Startup Request Time (when pilot requests startup)
    if (!empty($record['asrt'])) {
        $set_clauses[] = 'actual_startup_request_time = TRY_CONVERT(datetime2, ?)';
        $update_params[] = $record['asrt'];
    }
```

- [ ] **Step 2: Add ATFCM boolean flag handling**

After the EXOT block (lines 214-220), add:

```php
    // ATFCM regulatory flags (boolean: true/false or 1/0)
    if (isset($record['atfcm_excluded']) && is_bool_like($record['atfcm_excluded'])) {
        $set_clauses[] = 'eu_atfcm_excluded = ?';
        $update_params[] = filter_var($record['atfcm_excluded'], FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
    }

    if (isset($record['atfcm_ready']) && is_bool_like($record['atfcm_ready'])) {
        $set_clauses[] = 'eu_atfcm_ready = ?';
        $update_params[] = filter_var($record['atfcm_ready'], FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
    }

    if (isset($record['atfcm_slot_improvement']) && is_bool_like($record['atfcm_slot_improvement'])) {
        $set_clauses[] = 'eu_atfcm_slot_improvement = ?';
        $update_params[] = filter_var($record['atfcm_slot_improvement'], FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
    }
```

- [ ] **Step 3: Add `is_bool_like()` helper**

Add this helper function at the bottom of the file (after `processCdmUpdate()`):

```php
/**
 * Check if a value is boolean-like (true/false, 1/0).
 * Non-boolean values (strings like "yes", arrays, etc.) return false.
 */
function is_bool_like($value): bool {
    return is_bool($value) || $value === 1 || $value === 0;
}
```

- [ ] **Step 4: Update the file docblock**

In the header comment (lines 11-16), add `asrt` to the A-CDM milestone mapping:

```php
 *   asrt  -> actual_startup_request_time (ASRT - Actual Startup Request Time)
```

And add a line in the payload example:

```php
 *       "asrt": "2026-03-05T14:32:00Z",
```

- [ ] **Step 5: Verify no syntax errors**

```bash
php -l api/swim/v1/ingest/cdm.php
```

Expected: `No syntax errors detected`

- [ ] **Step 6: Commit**

```bash
git add api/swim/v1/ingest/cdm.php
git commit -m "feat(swim): accept ASRT and ATFCM flags in CDM ingest endpoint"
```

---

## Chunk 5: Verification and Final Commit

### Task 5: End-to-end verification

- [ ] **Step 1: Verify all 3 files lint clean**

```bash
php -l scripts/viff_cdm_poll_daemon.php
php -l api/swim/v1/ingest/cdm.php
```

Expected: Both `No syntax errors detected`

- [ ] **Step 2: Verify migration columns via SQL**

Connect to Azure SQL and confirm all 4 columns exist with correct types:

```sql
SELECT COLUMN_NAME, DATA_TYPE, IS_NULLABLE, COLUMN_DEFAULT
FROM SWIM_API.INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_NAME = 'swim_flights'
  AND COLUMN_NAME IN (
    'actual_startup_request_time',
    'eu_atfcm_excluded',
    'eu_atfcm_ready',
    'eu_atfcm_slot_improvement'
  )
ORDER BY COLUMN_NAME;
```

Expected: 4 rows

- [ ] **Step 3: Test CDM ingest endpoint with new fields**

Send a test request with the new fields (use a known active callsign):

```bash
curl -X POST https://perti.vatcscc.org/api/swim/v1/ingest/cdm \
  -H "Content-Type: application/json" \
  -H "X-API-Key: <system-key>" \
  -d '{
    "updates": [{
      "callsign": "<active-callsign>",
      "asrt": "2026-03-15T14:32:00Z",
      "atfcm_excluded": false,
      "atfcm_ready": true,
      "atfcm_slot_improvement": false,
      "source": "TEST"
    }]
  }'
```

Expected: `{"processed": 1, "updated": 1, ...}`

- [ ] **Step 4: Verify written values in DB**

```sql
SELECT callsign, actual_startup_request_time,
       eu_atfcm_excluded, eu_atfcm_ready, eu_atfcm_slot_improvement,
       cdm_source, cdm_updated_at
FROM SWIM_API.dbo.swim_flights
WHERE callsign = '<active-callsign>' AND is_active = 1;
```

Expected: ASRT populated, ready=1, excluded=0, slot_improvement=0, source=TEST

- [ ] **Step 5: Clean up test data**

```sql
UPDATE SWIM_API.dbo.swim_flights
SET actual_startup_request_time = NULL,
    eu_atfcm_excluded = 0,
    eu_atfcm_ready = 0,
    eu_atfcm_slot_improvement = 0,
    cdm_source = NULL,
    cdm_updated_at = NULL
WHERE callsign = '<active-callsign>' AND cdm_source = 'TEST';
```
