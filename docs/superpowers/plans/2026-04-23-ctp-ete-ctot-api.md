# CTP ETE/CTOT Bidirectional API Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Two SWIM API endpoints — a public ETE query and an authenticated CTOT assignment with immediate 9-step recalculation cascade across 4 databases.

**Architecture:** Public `ete.php` computes ETE via `sp_CalculateETA` with a new `@departure_override` parameter, anchoring computation to CTP-provided TOBT. Authenticated `ctot.php` derives EOBT/EDCT from CTOT, writes to the TMI pipeline, and synchronously recalculates ETAs, waypoint times, and boundary crossings.

**Tech Stack:** PHP 8.2, Azure SQL (sqlsrv extension), PostgreSQL/PostGIS (PDO), T-SQL stored procedures

**Spec:** `docs/superpowers/specs/2026-04-23-ctp-ete-edct-api-design.md`

---

## File Structure

```
New files:
  database/migrations/swim/040_swim_ete_etot_columns.sql   — SWIM_API: 2 new columns on swim_flights
  database/migrations/tmi/058_ctp_nat_track.sql             — VATSIM_TMI: assigned_nat_track on ctp_flight_control
  api/swim/v1/ete.php                                       — ETE query endpoint (public, no auth)
  api/swim/v1/ingest/ctot.php                               — CTOT assignment endpoint (write + CTP authority)

Modified files:
  adl/procedures/sp_CalculateETA.sql                        — Add @departure_override param + 2 new columns on adl_flight_times
```

**Not modified:** `swim_sync.php`, `swim_config.php`, auth.php, any daemon files. All changes are additive.

---

### Task 1: SWIM Schema — Add ETOT and EET Columns

**Files:**
- Create: `database/migrations/swim/040_swim_ete_etot_columns.sql`

These columns store CTP-computed values that are NOT overwritten by `swim_sync_daemon` (which only syncs canonical ADL columns).

- [ ] **Step 1: Write the migration file**

```sql
-- ============================================================================
-- 040_swim_ete_etot_columns.sql
-- SWIM_API Database: Add ETOT and computed EET columns for CTP integration
--
-- estimated_takeoff_time: Computed ETOT = TOBT + taxi_ref (wheels-up estimate)
-- computed_ete_minutes:   Computed EET from sp_CalculateETA (distinct from
--                         pilot-filed ete_minutes which is user-reported)
--
-- These columns are written by the CTP API endpoints (ete.php, ctot.php)
-- and are NOT synced by swim_sync_daemon (not in its column list).
-- ============================================================================

USE SWIM_API;
GO

PRINT '==========================================================================';
PRINT '  Migration 040: ETOT and Computed EET Columns for CTP';
PRINT '  ' + CONVERT(VARCHAR, GETUTCDATE(), 120);
PRINT '==========================================================================';
GO

-- estimated_takeoff_time (ETOT) — TOBT + taxi reference
-- FIXM: estimatedTakeoffTime
-- Distinct from target_takeoff_time (TTOT/CTOT = controlled wheels-up)
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.swim_flights') AND name = 'estimated_takeoff_time')
BEGIN
    ALTER TABLE dbo.swim_flights ADD estimated_takeoff_time DATETIME2(0) NULL;
    PRINT '+ Added estimated_takeoff_time (ETOT = TOBT + taxi)';
END
ELSE PRINT '= estimated_takeoff_time already exists';
GO

-- computed_ete_minutes — server-computed enroute time from sp_CalculateETA
-- Distinct from ete_minutes which is the PILOT-FILED enroute time
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.swim_flights') AND name = 'computed_ete_minutes')
BEGIN
    ALTER TABLE dbo.swim_flights ADD computed_ete_minutes SMALLINT NULL;
    PRINT '+ Added computed_ete_minutes (sp_CalculateETA result, distinct from pilot-filed ete_minutes)';
END
ELSE PRINT '= computed_ete_minutes already exists';
GO

PRINT '';
PRINT '  Migration 040 Complete';
PRINT '  New columns: estimated_takeoff_time, computed_ete_minutes';
PRINT '  NOTE: Existing ete_minutes column is pilot-filed and is NOT touched';
GO
```

- [ ] **Step 2: Deploy to SWIM_API**

Run against SWIM_API database using `jpeterson` admin credentials:

```bash
sqlcmd -S vatsim.database.windows.net -d SWIM_API -U jpeterson -P Jhp21012 \
  -i database/migrations/swim/040_swim_ete_etot_columns.sql
```

Expected output:
```
+ Added estimated_takeoff_time (ETOT = TOBT + taxi)
+ Added computed_ete_minutes (sp_CalculateETA result, distinct from pilot-filed ete_minutes)
Migration 040 Complete
```

- [ ] **Step 3: Verify columns exist**

```sql
SELECT name, system_type_name
FROM sys.dm_exec_describe_first_result_set(
    N'SELECT estimated_takeoff_time, computed_ete_minutes, ete_minutes FROM dbo.swim_flights WHERE 1=0', NULL, 0
);
```

Expected: 3 rows — `estimated_takeoff_time` (datetime2), `computed_ete_minutes` (smallint), `ete_minutes` (int or smallint — pilot-filed, untouched).

- [ ] **Step 4: Commit**

```bash
git add database/migrations/swim/040_swim_ete_etot_columns.sql
git commit -m "feat(swim): add ETOT and computed EET columns for CTP integration"
```

---

### Task 2: TMI Schema — Add assigned_nat_track Column

**Files:**
- Create: `database/migrations/tmi/058_ctp_nat_track.sql`

- [ ] **Step 1: Write the migration file**

```sql
-- ============================================================================
-- 058_ctp_nat_track.sql
-- VATSIM_TMI Database: Add assigned_nat_track to ctp_flight_control
--
-- Migration 045 created ctp_flight_control with route segments but no
-- NAT track column. CTP assigns specific NAT tracks (A, B, SM1, etc.)
-- that need to be stored separately from the oceanic route segment.
-- ============================================================================

USE VATSIM_TMI;
GO

PRINT '==========================================================================';
PRINT '  Migration 058: Add assigned_nat_track to ctp_flight_control';
PRINT '  ' + CONVERT(VARCHAR, GETUTCDATE(), 120);
PRINT '==========================================================================';
GO

IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.ctp_flight_control') AND name = 'assigned_nat_track')
BEGIN
    ALTER TABLE dbo.ctp_flight_control ADD assigned_nat_track VARCHAR(4) NULL;
    PRINT '+ Added assigned_nat_track (pattern: A, B, SM1, etc.)';
END
ELSE PRINT '= assigned_nat_track already exists';
GO

-- ============================================================================
-- Verify CTP is in tmi_flight_control CHECK constraint
-- Migration 003 CHECK does not include CTP, but ingest/ctp.php uses it.
-- This adds CTP if the constraint exists without it.
-- ============================================================================

IF EXISTS (
    SELECT 1 FROM sys.check_constraints
    WHERE parent_object_id = OBJECT_ID('dbo.tmi_flight_control')
      AND name = 'CK_tmi_flight_control_type'
      AND definition NOT LIKE '%CTP%'
)
BEGIN
    ALTER TABLE dbo.tmi_flight_control DROP CONSTRAINT CK_tmi_flight_control_type;
    ALTER TABLE dbo.tmi_flight_control ADD CONSTRAINT CK_tmi_flight_control_type
        CHECK (ctl_type IS NULL OR ctl_type IN
            ('GDP', 'AFP', 'GS', 'DAS', 'GAAP', 'UDP', 'COMP', 'BLKT', 'ECR', 'ADPT', 'ABRG', 'CTOP', 'CTP'));
    PRINT '+ Updated CK_tmi_flight_control_type to include CTP';
END
ELSE PRINT '= CK_tmi_flight_control_type already includes CTP (or does not exist)';
GO

PRINT '';
PRINT '  Migration 058 Complete';
GO
```

- [ ] **Step 2: Deploy to VATSIM_TMI**

```bash
sqlcmd -S vatsim.database.windows.net -d VATSIM_TMI -U jpeterson -P Jhp21012 \
  -i database/migrations/tmi/058_ctp_nat_track.sql
```

- [ ] **Step 3: Verify**

```sql
SELECT name FROM sys.columns
WHERE object_id = OBJECT_ID('dbo.ctp_flight_control') AND name = 'assigned_nat_track';
```

Expected: 1 row.

- [ ] **Step 4: Commit**

```bash
git add database/migrations/tmi/058_ctp_nat_track.sql
git commit -m "feat(tmi): add assigned_nat_track to ctp_flight_control for CTP"
```

---

### Task 3: Modify sp_CalculateETA — Add @departure_override Parameter

**Files:**
- Modify: `adl/procedures/sp_CalculateETA.sql`

This is the core change. The SP currently hardcodes `@now = SYSUTCDATETIME()`. We add an optional `@departure_override` parameter that, when provided:
1. Replaces `@now` as the departure anchor
2. Skips the internal 15-minute taxi estimate (caller provides wheels-up time)

Additionally, we add `estimated_takeoff_time` and `computed_ete_minutes` columns to `adl_flight_times` (same database).

- [ ] **Step 1: Add new columns to adl_flight_times**

At the top of `adl/procedures/sp_CalculateETA.sql`, after the existing `eta_dist_source` ALTER TABLE block (around line 36), add:

```sql
-- Add estimated_takeoff_time column if not exists (ETOT for CTP integration)
IF NOT EXISTS (
    SELECT 1 FROM sys.columns
    WHERE object_id = OBJECT_ID(N'dbo.adl_flight_times')
    AND name = 'estimated_takeoff_time'
)
BEGIN
    ALTER TABLE dbo.adl_flight_times
    ADD estimated_takeoff_time DATETIME2(0) NULL;
    PRINT 'Added estimated_takeoff_time column to adl_flight_times';
END
GO

-- Add computed_ete_minutes column if not exists (distinct from pilot-filed ete_minutes)
IF NOT EXISTS (
    SELECT 1 FROM sys.columns
    WHERE object_id = OBJECT_ID(N'dbo.adl_flight_times')
    AND name = 'computed_ete_minutes'
)
BEGIN
    ALTER TABLE dbo.adl_flight_times
    ADD computed_ete_minutes SMALLINT NULL;
    PRINT 'Added computed_ete_minutes column to adl_flight_times';
END
GO
```

- [ ] **Step 2: Add @departure_override parameter to SP signature**

Change the CREATE PROCEDURE line from:

```sql
CREATE PROCEDURE dbo.sp_CalculateETA
    @flight_uid BIGINT
AS
```

To:

```sql
CREATE PROCEDURE dbo.sp_CalculateETA
    @flight_uid BIGINT,
    @departure_override DATETIME2(0) = NULL
AS
```

- [ ] **Step 3: Replace @now initialization**

Change:

```sql
DECLARE @now DATETIME2(0) = SYSUTCDATETIME();
```

To:

```sql
DECLARE @now DATETIME2(0) = ISNULL(@departure_override, SYSUTCDATETIME());
```

This single change propagates through ALL internal references to `@now`:
- TMI delay: `DATEDIFF(MINUTE, @now, @edct_utc)` → uses override as baseline
- ETA derivation: `DATEADD(MINUTE, @time_to_dest_min, @now)` → anchors to override
- TOD ETA: `DATEADD(MINUTE, @time_to_tod, @now)` → anchors to override
- Timestamps: `eta_last_calc_utc = @now`, `times_updated_utc = @now` → records actual computation time when no override

**Default NULL** preserves backward compatibility — all existing callers (`sp_CalculateETABatch`, daemon code) pass only `@flight_uid` and get the same `SYSUTCDATETIME()` behavior.

- [ ] **Step 4: Skip taxi estimate when override provided**

In the prefile path (around line 310, inside the `ELSE BEGIN -- Pre-filed or unknown` block), change:

```sql
DECLARE @taxi_estimate INT = 15;
```

To:

```sql
-- When @departure_override is provided, caller passes wheels-up time (ETOT/CTOT)
-- so taxi is already accounted for in the gap between TOBT/EOBT and the override.
DECLARE @taxi_estimate INT = CASE WHEN @departure_override IS NOT NULL THEN 0 ELSE 15 END;
```

- [ ] **Step 5: Deploy to VATSIM_ADL**

```bash
sqlcmd -S vatsim.database.windows.net -d VATSIM_ADL -U jpeterson -P Jhp21012 \
  -i adl/procedures/sp_CalculateETA.sql
```

Expected output includes:
```
Added estimated_takeoff_time column to adl_flight_times
Added computed_ete_minutes column to adl_flight_times
```

The SP DROP + CREATE should complete without error. No deadlock risk since daemons call `sp_CalculateETABatch` (a different SP), not `sp_CalculateETA`.

- [ ] **Step 6: Verify backward compatibility**

Test that existing single-flight ETA calculation still works with no second parameter:

```sql
-- Pick any active flight
DECLARE @uid BIGINT;
SELECT TOP 1 @uid = flight_uid FROM dbo.adl_flight_core WHERE is_active = 1;
EXEC dbo.sp_CalculateETA @uid;
SELECT eta_utc, eta_method, eta_confidence FROM dbo.adl_flight_times WHERE flight_uid = @uid;
```

Expected: eta_utc is populated, eta_method starts with `V3`.

- [ ] **Step 7: Verify @departure_override works**

```sql
DECLARE @uid BIGINT;
SELECT TOP 1 @uid = flight_uid FROM dbo.adl_flight_core WHERE phase = 'prefile' AND is_active = 1;
-- Override with a time 2 hours from now
EXEC dbo.sp_CalculateETA @uid, @departure_override = '2026-04-23 16:00:00';
SELECT eta_utc, eta_method, eta_tmi_delay_min FROM dbo.adl_flight_times WHERE flight_uid = @uid;
```

Expected: `eta_utc` should be approximately 2026-04-23 16:00 + flight_time (no 15-min taxi added). `eta_tmi_delay_min` should be 0 (no EDCT set).

- [ ] **Step 8: Commit**

```bash
git add adl/procedures/sp_CalculateETA.sql
git commit -m "feat(eta): add @departure_override parameter to sp_CalculateETA for CTP"
```

---

### Task 4: Build ETE Query Endpoint

**Files:**
- Create: `api/swim/v1/ete.php`

Public endpoint (no API key required). CTP sends callsigns + optional TOBT. PERTI computes ETE/ETA using `sp_CalculateETA` with `@departure_override`, stores results, returns computed times.

- [ ] **Step 1: Create the endpoint file**

Create `api/swim/v1/ete.php`:

```php
<?php
/**
 * VATSWIM API v1 - ETE Query Endpoint
 *
 * Public endpoint (no API key required). Flight times are public information.
 *
 * CTP sends callsigns + optional TOBT per flight.
 * PERTI computes ETE/ETA using sp_CalculateETA with departure override,
 * stores TOBT/ETOT/EET, and returns computed times.
 *
 * POST /api/swim/v1/ete.php
 *
 * @see docs/superpowers/specs/2026-04-23-ctp-ete-edct-api-design.md
 */

require_once __DIR__ . '/auth.php';

// Public endpoint: handle CORS/OPTIONS, no auth required
swim_init_auth(false, false);

global $conn_swim;
if (!$conn_swim) {
    SwimResponse::error('SWIM database connection not available', 503, 'SERVICE_UNAVAILABLE');
}

// POST only
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    SwimResponse::error('Method not allowed. Use POST.', 405, 'METHOD_NOT_ALLOWED');
}

$body = swim_get_json_body();
if (!$body || !isset($body['flights']) || !is_array($body['flights'])) {
    SwimResponse::error('Request body must contain a "flights" array', 400, 'INVALID_REQUEST');
}

$flights_input = $body['flights'];
if (count($flights_input) === 0) {
    SwimResponse::error('flights array must not be empty', 400, 'INVALID_REQUEST');
}
if (count($flights_input) > 50) {
    SwimResponse::error('flights array must not exceed 50 items', 400, 'INVALID_REQUEST');
}

// Get ADL connection for sp_CalculateETA + taxi reference
$conn_adl = get_conn_adl();
if (!$conn_adl) {
    SwimResponse::error('ADL database connection not available', 503, 'SERVICE_UNAVAILABLE');
}

$results = [];
$unmatched = [];

foreach ($flights_input as $item) {
    $callsign = strtoupper(trim($item['callsign'] ?? ''));
    if (strlen($callsign) < 2 || strlen($callsign) > 12 || !preg_match('/^[A-Z0-9]+$/', $callsign)) {
        $unmatched[] = $callsign ?: '(invalid)';
        continue;
    }

    // Validate TOBT if provided
    $tobt_str = null;
    if (!empty($item['tobt'])) {
        $tobt_str = ete_parse_utc_datetime($item['tobt']);
        if ($tobt_str === null) {
            $results[] = [
                'callsign' => $callsign,
                'error' => 'Invalid tobt datetime format. Use ISO 8601 (e.g., 2026-04-23T12:00:00Z).'
            ];
            continue;
        }
    }

    // Find matching active flight in swim_flights
    $flight = ete_find_flight($conn_swim, $callsign);
    if (!$flight) {
        $unmatched[] = $callsign;
        continue;
    }

    $flight_uid = (int)$flight['flight_uid'];
    $dept_icao = $flight['fp_dept_icao'];

    // Resolve departure basis: CTP-provided TOBT, or existing EOBT/ETD
    $departure_basis = $tobt_str
        ?? $flight['estimated_off_block_time']
        ?? $flight['etd_utc']
        ?? null;

    if (!$departure_basis) {
        $results[] = [
            'callsign' => $callsign,
            'flight_uid' => $flight_uid,
            'error' => 'No TOBT provided and no existing ETD available for this flight.'
        ];
        continue;
    }

    // Normalize departure_basis to string
    if ($departure_basis instanceof DateTime) {
        $departure_basis = $departure_basis->format('Y-m-d H:i:s');
    }

    // Get taxi reference for departure airport (default 600s = 10 min)
    $taxi_seconds = ete_get_taxi_reference($conn_adl, $dept_icao);
    $taxi_minutes = (int)round($taxi_seconds / 60);

    // ETOT = TOBT + taxi (estimated wheels-up time)
    $tobt_ts = strtotime($departure_basis . ' UTC');
    $etot_ts = $tobt_ts + $taxi_seconds;
    $etot_str = gmdate('Y-m-d H:i:s', $etot_ts);
    $tobt_iso = gmdate('Y-m-d\TH:i:s\Z', $tobt_ts);
    $etot_iso = gmdate('Y-m-d\TH:i:s\Z', $etot_ts);

    // Call sp_CalculateETA with @departure_override = ETOT (wheels-up anchor)
    $sp = sqlsrv_query($conn_adl,
        "EXEC dbo.sp_CalculateETA @flight_uid = ?, @departure_override = ?",
        [$flight_uid, $etot_str]
    );
    if ($sp) sqlsrv_free_stmt($sp);

    // Read computed results from adl_flight_times
    $times = ete_read_flight_times($conn_adl, $flight_uid);
    $eta_utc = $times['eta_utc'] ?? null;

    // Compute ETE = minutes from ETOT to ETA
    $ete_minutes = null;
    $eta_iso = null;
    if ($eta_utc) {
        if ($eta_utc instanceof DateTime) {
            $eta_ts = $eta_utc->getTimestamp();
        } else {
            $eta_ts = strtotime($eta_utc . ' UTC');
        }
        $ete_minutes = (int)round(($eta_ts - $etot_ts) / 60);
        $eta_iso = gmdate('Y-m-d\TH:i:s\Z', $eta_ts);
    }

    // Get aircraft cruise speed from performance function
    $cruise_speed = ete_get_cruise_speed($conn_adl, $flight);

    // Store computed values in swim_flights + adl_flight_times
    ete_store_results($conn_swim, $conn_adl, $flight_uid, $tobt_str, $etot_str, $ete_minutes);

    // Build response record
    $results[] = [
        'callsign' => $callsign,
        'flight_uid' => $flight_uid,
        'gufi' => $flight['gufi'] ?? null,
        'departure_airport' => $dept_icao,
        'arrival_airport' => $flight['fp_dest_icao'],
        'aircraft_type' => $flight['aircraft_type'] ?? $flight['aircraft_icao'] ?? null,
        'tobt' => $tobt_iso,
        'etot' => $etot_iso,
        'estimated_elapsed_time' => $ete_minutes,
        'estimated_time_of_arrival' => $eta_iso,
        'taxi_time_minutes' => $taxi_minutes,
        'eta_method' => $times['eta_method'] ?? null,
        'eta_confidence' => isset($times['eta_confidence']) ? round((float)$times['eta_confidence'], 2) : null,
        'route_distance_nm' => isset($times['eta_route_dist_nm']) ? round((float)$times['eta_route_dist_nm'], 1) : null,
        'aircraft_cruise_speed_kts' => $cruise_speed,
        'flight_phase' => $flight['phase'],
        'filed_route' => $flight['fp_route'] ?? null,
        'latitude' => isset($flight['lat']) ? (float)$flight['lat'] : null,
        'longitude' => isset($flight['lon']) ? (float)$flight['lon'] : null,
    ];
}

SwimResponse::success([
    'flights' => $results,
    'unmatched' => $unmatched,
], [
    'total_requested' => count($flights_input),
    'total_matched' => count($results),
    'total_unmatched' => count($unmatched),
]);

// ============================================================================
// Helper Functions
// ============================================================================

/**
 * Parse ISO 8601 datetime string to 'Y-m-d H:i:s' format.
 * Returns null if invalid.
 */
function ete_parse_utc_datetime(string $str): ?string {
    // Accept: 2026-04-23T12:00:00Z, 2026-04-23T12:00:00+00:00, 2026-04-23 12:00:00
    $str = trim($str);
    $ts = strtotime($str);
    if ($ts === false || $ts < 0) return null;
    return gmdate('Y-m-d H:i:s', $ts);
}

/**
 * Find an active flight by callsign in swim_flights.
 * Returns the flight row or null.
 */
function ete_find_flight($conn_swim, string $callsign): ?array {
    $stmt = sqlsrv_query($conn_swim,
        "SELECT TOP 1
            flight_uid, gufi, callsign, fp_dept_icao, fp_dest_icao,
            aircraft_type, aircraft_icao, weight_class, engine_type,
            phase, fp_route, lat, lon,
            estimated_off_block_time, etd_utc
         FROM dbo.swim_flights
         WHERE callsign = ? AND is_active = 1
         ORDER BY flight_uid DESC",
        [$callsign]
    );
    if (!$stmt) return null;
    $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    sqlsrv_free_stmt($stmt);
    return $row ?: null;
}

/**
 * Get unimpeded taxi time for an airport.
 * Returns seconds (default 600s = 10 min).
 */
function ete_get_taxi_reference($conn_adl, ?string $icao): int {
    if (!$icao) return 600;
    $stmt = sqlsrv_query($conn_adl,
        "SELECT unimpeded_taxi_sec FROM dbo.airport_taxi_reference WHERE airport_icao = ?",
        [$icao]
    );
    if (!$stmt) return 600;
    $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    sqlsrv_free_stmt($stmt);
    return $row ? (int)$row['unimpeded_taxi_sec'] : 600;
}

/**
 * Read ETA results from adl_flight_times after sp_CalculateETA execution.
 */
function ete_read_flight_times($conn_adl, int $flight_uid): array {
    $stmt = sqlsrv_query($conn_adl,
        "SELECT eta_utc, eta_method, eta_confidence, eta_route_dist_nm, eta_dist_source
         FROM dbo.adl_flight_times WHERE flight_uid = ?",
        [$flight_uid]
    );
    if (!$stmt) return [];
    $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    sqlsrv_free_stmt($stmt);
    return $row ?: [];
}

/**
 * Get aircraft cruise speed via fn_GetAircraftPerformance.
 * Returns cruise_speed_ktas or null.
 */
function ete_get_cruise_speed($conn_adl, array $flight): ?int {
    $icao = $flight['aircraft_icao'] ?? $flight['aircraft_type'] ?? null;
    $wc = $flight['weight_class'] ?? 'L';
    $et = $flight['engine_type'] ?? 'JET';
    if (!$icao) return null;

    $stmt = sqlsrv_query($conn_adl,
        "SELECT cruise_speed_ktas FROM dbo.fn_GetAircraftPerformance(?, ?, ?)",
        [$icao, $wc, $et]
    );
    if (!$stmt) return null;
    $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    sqlsrv_free_stmt($stmt);
    return $row ? (int)$row['cruise_speed_ktas'] : null;
}

/**
 * Store TOBT/ETOT/EET in swim_flights and adl_flight_times.
 */
function ete_store_results($conn_swim, $conn_adl, int $flight_uid, ?string $tobt, string $etot, ?int $ete_minutes): void {
    // Update swim_flights
    sqlsrv_query($conn_swim,
        "UPDATE dbo.swim_flights SET
            target_off_block_time = COALESCE(?, target_off_block_time),
            estimated_takeoff_time = ?,
            computed_ete_minutes = ?
         WHERE flight_uid = ?",
        [$tobt, $etot, $ete_minutes, $flight_uid]
    );

    // Update adl_flight_times
    sqlsrv_query($conn_adl,
        "UPDATE dbo.adl_flight_times SET
            estimated_takeoff_time = ?,
            computed_ete_minutes = ?
         WHERE flight_uid = ?",
        [$etot, $ete_minutes, $flight_uid]
    );
}
```

- [ ] **Step 2: Verify file structure and includes**

Confirm the require path resolves correctly:
```bash
# From api/swim/v1/ete.php, auth.php is at ./auth.php
ls api/swim/v1/auth.php
```

Confirm `swim_init_auth(false, false)` returns null (line 545 of auth.php: `if (!$require_auth) return null;`). The endpoint doesn't need `$auth` since it's public.

- [ ] **Step 3: Commit**

```bash
git add api/swim/v1/ete.php
git commit -m "feat(swim): add public ETE query endpoint for CTP integration"
```

---

### Task 5: Build CTOT Assignment Endpoint

**Files:**
- Create: `api/swim/v1/ingest/ctot.php`

Authenticated endpoint (write + CTP authority). Receives CTOT assignments, derives EOBT/EDCT, performs 9-step recalculation cascade, returns updated times.

- [ ] **Step 1: Create the endpoint file**

Create `api/swim/v1/ingest/ctot.php`:

```php
<?php
/**
 * VATSWIM API v1 - CTOT Assignment Endpoint
 *
 * Authenticated endpoint: requires SWIM API key with write permission + CTP authority.
 *
 * CTP assigns Controlled Take-Off Times and optional routes/tracks.
 * PERTI derives EOBT/EDCT, stores in TMI pipeline, and immediately
 * recalculates ETAs, waypoint times, and boundary crossings.
 *
 * POST /api/swim/v1/ingest/ctot.php
 *
 * 9-step recalculation cascade:
 *   1. tmi_flight_control (VATSIM_TMI)
 *   2. adl_flight_times (VATSIM_ADL)
 *   3. sp_CalculateETA with @departure_override (VATSIM_ADL)
 *   4. Waypoint ETA inline SQL (VATSIM_ADL)
 *   5. Boundary crossing recalc (VATSIM_GIS via GISService)
 *   6. swim_flights push (SWIM_API)
 *   7. rad_amendments if route provided (VATSIM_TMI)
 *   8. adl_flight_tmi sync (VATSIM_ADL)
 *   9. ctp_flight_control if segments/track (VATSIM_TMI)
 *
 * @see docs/superpowers/specs/2026-04-23-ctp-ete-edct-api-design.md
 */

require_once __DIR__ . '/../auth.php';

global $conn_swim;
if (!$conn_swim) {
    SwimResponse::error('SWIM database connection not available', 503, 'SERVICE_UNAVAILABLE');
}

// Require write + CTP authority
$auth = swim_init_auth(true, true);
if (!$auth->canWriteField('ctp')) {
    SwimResponse::error('CTP write authority required', 403, 'FORBIDDEN');
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    SwimResponse::error('Method not allowed. Use POST.', 405, 'METHOD_NOT_ALLOWED');
}

$body = swim_get_json_body();
if (!$body || !isset($body['assignments']) || !is_array($body['assignments'])) {
    SwimResponse::error('Request body must contain an "assignments" array', 400, 'INVALID_REQUEST');
}

$assignments = $body['assignments'];
if (count($assignments) === 0) {
    SwimResponse::error('assignments array must not be empty', 400, 'INVALID_REQUEST');
}
if (count($assignments) > 50) {
    SwimResponse::error('assignments array must not exceed 50 items', 400, 'INVALID_REQUEST');
}

// Get all database connections
$conn_adl = get_conn_adl();
$conn_tmi = get_conn_tmi();
$conn_gis = get_conn_gis();

if (!$conn_adl || !$conn_tmi) {
    SwimResponse::error('Required database connections not available', 503, 'SERVICE_UNAVAILABLE');
}

// Load GISService for boundary crossing recalc (step 5)
require_once __DIR__ . '/../../../load/services/GISService.php';
$gisService = $conn_gis ? new PERTI\Services\GISService($conn_gis) : null;

$results = [];
$unmatched = [];
$counts = ['created' => 0, 'updated' => 0, 'skipped' => 0];

foreach ($assignments as $item) {
    $callsign = strtoupper(trim($item['callsign'] ?? ''));
    if (strlen($callsign) < 2 || strlen($callsign) > 12 || !preg_match('/^[A-Z0-9]+$/', $callsign)) {
        $unmatched[] = $callsign ?: '(invalid)';
        continue;
    }

    // Validate CTOT (required)
    $ctot_str = ctot_parse_utc_datetime($item['ctot'] ?? '');
    if (!$ctot_str) {
        $results[] = ['callsign' => $callsign, 'status' => 'error', 'error' => 'Missing or invalid ctot datetime'];
        continue;
    }

    // Validate assigned_track format if provided
    $assigned_track = $item['assigned_track'] ?? null;
    if ($assigned_track && !preg_match('/^[A-Z]{1,2}\d?$/', $assigned_track)) {
        $results[] = ['callsign' => $callsign, 'status' => 'error', 'error' => 'Invalid assigned_track format (expected: A, B, SM1, etc.)'];
        continue;
    }

    // Find matching flight
    $flight = ctot_find_flight($conn_swim, $callsign);
    if (!$flight) {
        $unmatched[] = $callsign;
        continue;
    }

    $flight_uid = (int)$flight['flight_uid'];
    $dept_icao = $flight['fp_dept_icao'];
    $dest_icao = $flight['fp_dest_icao'];

    // Derive EOBT = CTOT - taxi_ref
    $taxi_seconds = ctot_get_taxi_reference($conn_adl, $dept_icao);
    $ctot_ts = strtotime($ctot_str . ' UTC');
    $eobt_ts = $ctot_ts - $taxi_seconds;
    $eobt_str = gmdate('Y-m-d H:i:s', $eobt_ts);

    $delay_minutes = isset($item['delay_minutes']) ? (int)$item['delay_minutes'] : null;
    $delay_reason = $item['delay_reason'] ?? null;
    $program_name = $item['program_name'] ?? null;
    $program_id = isset($item['program_id']) ? (int)$item['program_id'] : null;
    $source_system = $item['source_system'] ?? ($auth->getKeyInfo()['source_id'] ?? 'CTP');
    $cta_utc = !empty($item['cta_utc']) ? ctot_parse_utc_datetime($item['cta_utc']) : null;
    $assigned_route = $item['assigned_route'] ?? null;
    $route_segments = $item['route_segments'] ?? null;

    // ========================================================================
    // Step 1: tmi_flight_control (VATSIM_TMI)
    // ========================================================================
    $existing_control = ctot_get_existing_control($conn_tmi, $flight_uid);

    if ($existing_control) {
        // Check idempotency: same CTOT → skip
        $existing_eobt = $existing_control['ctd_utc'];
        if ($existing_eobt instanceof DateTime) {
            $existing_eobt = $existing_eobt->format('Y-m-d H:i:s');
        }
        if ($existing_eobt === $eobt_str) {
            $results[] = [
                'callsign' => $callsign,
                'status' => 'skipped',
                'flight_uid' => $flight_uid,
                'control_id' => (int)$existing_control['control_id'],
                'ctot' => gmdate('Y-m-d\TH:i:s\Z', $ctot_ts),
                'eobt' => gmdate('Y-m-d\TH:i:s\Z', $eobt_ts),
                'recalc_status' => 'skipped_idempotent',
            ];
            $counts['skipped']++;
            continue;
        }

        // Update existing control (preserve octd_utc)
        sqlsrv_query($conn_tmi,
            "UPDATE dbo.tmi_flight_control SET
                ctd_utc = ?, cta_utc = ?,
                program_delay_min = ?, ctl_type = 'CTP', ctl_prgm = ?,
                program_id = ?, dep_airport = ?, arr_airport = ?,
                modified_utc = SYSUTCDATETIME()
             WHERE control_id = ?",
            [$eobt_str, $cta_utc, $delay_minutes, $program_name,
             $program_id, $dept_icao, $dest_icao,
             $existing_control['control_id']]
        );
        $control_id = (int)$existing_control['control_id'];
        $status = 'updated';
        $counts['updated']++;
    } else {
        // Insert new control
        $stmt = sqlsrv_query($conn_tmi,
            "INSERT INTO dbo.tmi_flight_control
                (flight_uid, callsign, ctd_utc, octd_utc, cta_utc,
                 program_delay_min, ctl_type, ctl_prgm, ctl_elem,
                 program_id, dep_airport, arr_airport,
                 orig_etd_utc, control_assigned_utc)
             OUTPUT INSERTED.control_id
             VALUES (?, ?, ?, ?, ?, ?, 'CTP', ?, ?,
                     ?, ?, ?,
                     ?, SYSUTCDATETIME())",
            [$flight_uid, $callsign, $eobt_str, $eobt_str, $cta_utc,
             $delay_minutes, $program_name, $dest_icao,
             $program_id, $dept_icao, $dest_icao,
             $flight['estimated_off_block_time'] ?? $flight['etd_utc']]
        );
        $row = $stmt ? sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC) : null;
        if ($stmt) sqlsrv_free_stmt($stmt);
        $control_id = $row ? (int)$row['control_id'] : null;
        $status = 'created';
        $counts['created']++;
    }

    // ========================================================================
    // Step 2: adl_flight_times (VATSIM_ADL)
    // ========================================================================
    sqlsrv_query($conn_adl,
        "UPDATE dbo.adl_flight_times SET
            etd_utc = ?, std_utc = ?,
            estimated_takeoff_time = ?
         WHERE flight_uid = ?",
        [$eobt_str, $eobt_str, $ctot_str, $flight_uid]
    );

    // ========================================================================
    // Step 3: sp_CalculateETA with @departure_override = CTOT
    // ========================================================================
    $sp = sqlsrv_query($conn_adl,
        "EXEC dbo.sp_CalculateETA @flight_uid = ?, @departure_override = ?",
        [$flight_uid, $ctot_str]
    );
    if ($sp) sqlsrv_free_stmt($sp);

    // Read recalculated ETA
    $times = ctot_read_flight_times($conn_adl, $flight_uid);
    $eta_utc = $times['eta_utc'] ?? null;

    // Compute ETE = minutes from CTOT to ETA
    $ete_minutes = null;
    $eta_iso = null;
    if ($eta_utc) {
        $eta_ts = ($eta_utc instanceof DateTime) ? $eta_utc->getTimestamp() : strtotime($eta_utc . ' UTC');
        $ete_minutes = (int)round(($eta_ts - $ctot_ts) / 60);
        $eta_iso = gmdate('Y-m-d\TH:i:s\Z', $eta_ts);
    }

    // Store computed_ete_minutes
    sqlsrv_query($conn_adl,
        "UPDATE dbo.adl_flight_times SET computed_ete_minutes = ? WHERE flight_uid = ?",
        [$ete_minutes, $flight_uid]
    );

    // ========================================================================
    // Step 4: Waypoint ETA recalc (inline SQL)
    // sp_CalculateWaypointETABatch_Tiered cannot target a single flight.
    // ========================================================================
    $perf = ctot_get_performance($conn_adl, $flight);
    $effective_speed = $perf ? (int)$perf['cruise_speed_ktas'] : 450;

    // Apply wind adjustment if available
    $wind = $times['eta_wind_component_kts'] ?? 0;
    $effective_speed += (int)$wind;
    if ($effective_speed < 100) $effective_speed = 100; // floor

    sqlsrv_query($conn_adl,
        "UPDATE dbo.adl_flight_waypoints SET
            eta_utc = DATEADD(SECOND,
                CAST(distance_from_dep_nm / ? * 3600 AS INT),
                ?)
         WHERE flight_uid = ? AND distance_from_dep_nm IS NOT NULL",
        [(float)$effective_speed, $ctot_str, $flight_uid]
    );

    // ========================================================================
    // Step 5: Boundary crossing recalc (PostGIS via GISService)
    // ========================================================================
    if ($gisService) {
        // Read waypoints for crossing calculation
        $waypoints = ctot_read_waypoints($conn_adl, $flight_uid);
        if (!empty($waypoints)) {
            // Use CTOT as current time anchor
            $crossings = $gisService->calculateCrossingEtas(
                $waypoints,
                (float)($flight['lat'] ?? 0),
                (float)($flight['lon'] ?? 0),
                0, // dist_flown = 0 for prefiles
                $effective_speed,
                $ctot_str
            );

            // Update adl_flight_planned_crossings
            if (!empty($crossings)) {
                // Clear existing crossings for this flight
                sqlsrv_query($conn_adl,
                    "DELETE FROM dbo.adl_flight_planned_crossings WHERE flight_uid = ?",
                    [$flight_uid]
                );

                foreach ($crossings as $cx) {
                    sqlsrv_query($conn_adl,
                        "INSERT INTO dbo.adl_flight_planned_crossings
                            (flight_uid, boundary_type, boundary_code, boundary_name,
                             parent_artcc, crossing_lat, crossing_lon,
                             distance_from_origin_nm, distance_remaining_nm,
                             eta_utc, crossing_type)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                        [$flight_uid, $cx['boundary_type'], $cx['boundary_code'],
                         $cx['boundary_name'], $cx['parent_artcc'],
                         $cx['crossing_lat'], $cx['crossing_lon'],
                         $cx['distance_from_origin_nm'], $cx['distance_remaining_nm'],
                         $cx['eta_utc'], $cx['crossing_type']]
                    );
                }
            }
        }
    }

    // ========================================================================
    // Step 6: swim_flights push (SWIM_API)
    // ========================================================================
    $original_edct_clause = "original_edct = CASE WHEN original_edct IS NULL THEN ? ELSE original_edct END,";

    sqlsrv_query($conn_swim,
        "UPDATE dbo.swim_flights SET
            target_takeoff_time = ?,
            controlled_time_of_departure = ?,
            estimated_off_block_time = ?,
            estimated_takeoff_time = ?,
            edct_utc = ?,
            estimated_time_of_arrival = ?,
            computed_ete_minutes = ?,
            controlled_time_of_arrival = COALESCE(?, controlled_time_of_arrival),
            $original_edct_clause
            delay_minutes = ?,
            ctl_type = 'CTP'
         WHERE flight_uid = ?",
        [$ctot_str, $eobt_str, $eobt_str, $ctot_str, $eobt_str,
         $eta_utc instanceof DateTime ? $eta_utc->format('Y-m-d H:i:s') : $eta_utc,
         $ete_minutes,
         $cta_utc,
         $eobt_str, // for original_edct CASE
         $delay_minutes,
         $flight_uid]
    );

    // ========================================================================
    // Step 7: rad_amendments if assigned_route provided (VATSIM_TMI)
    // ========================================================================
    $route_amendment_id = null;
    if ($assigned_route) {
        $gufi = $flight['gufi'] ?? ('PERTI-' . $flight_uid);
        $stmt = sqlsrv_query($conn_tmi,
            "INSERT INTO dbo.rad_amendments
                (gufi, callsign, origin, destination, original_route,
                 assigned_route, status, tmi_id_label, created_utc)
             OUTPUT INSERTED.id
             VALUES (?, ?, ?, ?, ?, ?, 'DRAFT', ?, SYSUTCDATETIME())",
            [$gufi, $callsign, $dept_icao, $dest_icao,
             $flight['fp_route'], $assigned_route, $program_name]
        );
        $row = $stmt ? sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC) : null;
        if ($stmt) sqlsrv_free_stmt($stmt);
        $route_amendment_id = $row ? (int)$row['id'] : null;
    }

    // ========================================================================
    // Step 8: adl_flight_tmi sync (VATSIM_ADL)
    // ========================================================================
    $tmi_update_fields = "ctd_utc = ?, edct_utc = ?, delay_minutes = ?, ctl_type = 'CTP'";
    $tmi_params = [$eobt_str, $eobt_str, $delay_minutes];

    if ($route_amendment_id) {
        $tmi_update_fields .= ", rad_amendment_id = ?, rad_assigned_route = ?";
        $tmi_params[] = $route_amendment_id;
        $tmi_params[] = $assigned_route;
    }

    $tmi_params[] = $flight_uid;
    sqlsrv_query($conn_adl,
        "UPDATE dbo.adl_flight_tmi SET $tmi_update_fields WHERE flight_uid = ?",
        $tmi_params
    );

    // ========================================================================
    // Step 9: ctp_flight_control if route_segments or track (VATSIM_TMI)
    // ========================================================================
    if ($route_segments || $assigned_track) {
        $ctp_exists = ctot_check_ctp_control($conn_tmi, $flight_uid);

        if ($ctp_exists) {
            $ctp_sets = ["edct_utc = ?", "tmi_control_id = ?"];
            $ctp_params = [$eobt_str, $control_id];

            if ($assigned_track) {
                $ctp_sets[] = "assigned_nat_track = ?";
                $ctp_params[] = $assigned_track;
            }
            if (isset($route_segments['na'])) {
                $ctp_sets[] = "seg_na_route = ?";
                $ctp_sets[] = "seg_na_status = 'VALIDATED'";
                $ctp_params[] = $route_segments['na'];
            }
            if (isset($route_segments['oceanic'])) {
                $ctp_sets[] = "seg_oceanic_route = ?";
                $ctp_sets[] = "seg_oceanic_status = 'VALIDATED'";
                $ctp_params[] = $route_segments['oceanic'];
            }
            if (isset($route_segments['eu'])) {
                $ctp_sets[] = "seg_eu_route = ?";
                $ctp_sets[] = "seg_eu_status = 'VALIDATED'";
                $ctp_params[] = $route_segments['eu'];
            }

            $ctp_params[] = $flight_uid;
            sqlsrv_query($conn_tmi,
                "UPDATE dbo.ctp_flight_control SET " . implode(', ', $ctp_sets) . " WHERE flight_uid = ?",
                $ctp_params
            );
        }
        // If no ctp_flight_control record exists, the flight wasn't imported via CTP session.
        // Don't create one here — that's done by ingest/ctp.php during session import.
    }

    // Build response record
    $results[] = [
        'callsign' => $callsign,
        'status' => $status,
        'flight_uid' => $flight_uid,
        'control_id' => $control_id,
        'ctot' => gmdate('Y-m-d\TH:i:s\Z', $ctot_ts),
        'eobt' => gmdate('Y-m-d\TH:i:s\Z', $eobt_ts),
        'edct_utc' => gmdate('Y-m-d\TH:i:s\Z', $eobt_ts),
        'estimated_time_of_arrival' => $eta_iso,
        'estimated_elapsed_time' => $ete_minutes,
        'eta_method' => $times['eta_method'] ?? null,
        'delay_minutes' => $delay_minutes,
        'route_amendment_id' => $route_amendment_id,
        'assigned_track' => $assigned_track,
        'recalc_status' => 'complete',
    ];
}

SwimResponse::success([
    'results' => $results,
    'unmatched' => $unmatched,
], [
    'total_submitted' => count($assignments),
    'created' => $counts['created'],
    'updated' => $counts['updated'],
    'skipped' => $counts['skipped'],
    'unmatched' => count($unmatched),
]);

// ============================================================================
// Helper Functions
// ============================================================================

function ctot_parse_utc_datetime(string $str): ?string {
    $str = trim($str);
    if (empty($str)) return null;
    $ts = strtotime($str);
    if ($ts === false || $ts < 0) return null;
    return gmdate('Y-m-d H:i:s', $ts);
}

function ctot_find_flight($conn_swim, string $callsign): ?array {
    $stmt = sqlsrv_query($conn_swim,
        "SELECT TOP 1
            flight_uid, gufi, callsign, fp_dept_icao, fp_dest_icao,
            aircraft_type, aircraft_icao, weight_class, engine_type,
            phase, fp_route, lat, lon,
            estimated_off_block_time, etd_utc
         FROM dbo.swim_flights
         WHERE callsign = ? AND is_active = 1
         ORDER BY flight_uid DESC",
        [$callsign]
    );
    if (!$stmt) return null;
    $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    sqlsrv_free_stmt($stmt);
    return $row ?: null;
}

function ctot_get_taxi_reference($conn_adl, ?string $icao): int {
    if (!$icao) return 600;
    $stmt = sqlsrv_query($conn_adl,
        "SELECT unimpeded_taxi_sec FROM dbo.airport_taxi_reference WHERE airport_icao = ?",
        [$icao]
    );
    if (!$stmt) return 600;
    $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    sqlsrv_free_stmt($stmt);
    return $row ? (int)$row['unimpeded_taxi_sec'] : 600;
}

function ctot_read_flight_times($conn_adl, int $flight_uid): array {
    $stmt = sqlsrv_query($conn_adl,
        "SELECT eta_utc, eta_method, eta_confidence, eta_route_dist_nm,
                eta_wind_component_kts
         FROM dbo.adl_flight_times WHERE flight_uid = ?",
        [$flight_uid]
    );
    if (!$stmt) return [];
    $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    sqlsrv_free_stmt($stmt);
    return $row ?: [];
}

function ctot_get_performance($conn_adl, array $flight): ?array {
    $icao = $flight['aircraft_icao'] ?? $flight['aircraft_type'] ?? null;
    $wc = $flight['weight_class'] ?? 'L';
    $et = $flight['engine_type'] ?? 'JET';
    if (!$icao) return null;

    $stmt = sqlsrv_query($conn_adl,
        "SELECT cruise_speed_ktas FROM dbo.fn_GetAircraftPerformance(?, ?, ?)",
        [$icao, $wc, $et]
    );
    if (!$stmt) return null;
    $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    sqlsrv_free_stmt($stmt);
    return $row ?: null;
}

function ctot_read_waypoints($conn_adl, int $flight_uid): array {
    $stmt = sqlsrv_query($conn_adl,
        "SELECT fix_name, latitude, longitude, distance_from_dep_nm, waypoint_sequence
         FROM dbo.adl_flight_waypoints
         WHERE flight_uid = ? AND latitude IS NOT NULL
         ORDER BY waypoint_sequence",
        [$flight_uid]
    );
    if (!$stmt) return [];
    $waypoints = [];
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $waypoints[] = [
            'name' => $row['fix_name'],
            'lat' => (float)$row['latitude'],
            'lon' => (float)$row['longitude'],
            'dist_from_dep' => (float)$row['distance_from_dep_nm'],
            'sequence' => (int)$row['waypoint_sequence'],
        ];
    }
    sqlsrv_free_stmt($stmt);
    return $waypoints;
}

function ctot_get_existing_control($conn_tmi, int $flight_uid): ?array {
    $stmt = sqlsrv_query($conn_tmi,
        "SELECT control_id, ctd_utc, ctl_type
         FROM dbo.tmi_flight_control
         WHERE flight_uid = ? AND ctl_type = 'CTP'",
        [$flight_uid]
    );
    if (!$stmt) return null;
    $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    sqlsrv_free_stmt($stmt);
    return $row ?: null;
}

function ctot_check_ctp_control($conn_tmi, int $flight_uid): bool {
    $stmt = sqlsrv_query($conn_tmi,
        "SELECT 1 FROM dbo.ctp_flight_control WHERE flight_uid = ?",
        [$flight_uid]
    );
    if (!$stmt) return false;
    $exists = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC) !== null;
    sqlsrv_free_stmt($stmt);
    return $exists;
}
```

- [ ] **Step 2: Verify file structure and includes**

Confirm the require path resolves correctly:
```bash
# From api/swim/v1/ingest/ctot.php, auth.php is at ../auth.php
ls api/swim/v1/auth.php
ls load/services/GISService.php
```

- [ ] **Step 3: Commit**

```bash
git add api/swim/v1/ingest/ctot.php
git commit -m "feat(swim): add CTOT assignment endpoint with 9-step recalc cascade"
```

---

### Task 6: Manual Integration Testing

**Files:** No files changed. Deploy and test against live API.

- [ ] **Step 1: Deploy to Azure**

Push to `main` branch to trigger GitHub Actions deployment:

```bash
git push origin feature/nod-flight-search-route-analysis
# Then create PR, merge to main, or deploy branch directly
```

Wait for deployment to complete (~2 minutes).

- [ ] **Step 2: Test ETE endpoint (public, no auth)**

```bash
# Find active callsigns first
curl -s "https://perti.vatcscc.org/api/swim/v1/flights.php?per_page=3&format=fixm" \
  | python -c "import sys,json; d=json.load(sys.stdin); [print(f['callsign']) for f in d.get('data',{}).get('flights',[])]"

# Test ETE with TOBT
curl -s -X POST "https://perti.vatcscc.org/api/swim/v1/ete.php" \
  -H "Content-Type: application/json" \
  -d '{
    "flights": [
      {"callsign": "REPLACE_WITH_ACTIVE", "tobt": "2026-04-23T18:00:00Z"},
      {"callsign": "NONEXISTENT99"}
    ]
  }' | python -m json.tool
```

**Expected:**
- `success: true`
- First flight: has `etot`, `estimated_elapsed_time` (positive integer), `estimated_time_of_arrival`, `eta_method` starting with `V3`
- Second flight: in `unmatched` array
- No 401 error (endpoint is public)

- [ ] **Step 3: Test ETE without auth (verify public access)**

```bash
# No API key header — should still work
curl -s -X POST "https://perti.vatcscc.org/api/swim/v1/ete.php" \
  -H "Content-Type: application/json" \
  -d '{"flights": [{"callsign": "REPLACE_WITH_ACTIVE"}]}' \
  | python -m json.tool
```

**Expected:** 200 OK with computed ETE (no 401).

- [ ] **Step 4: Test CTOT endpoint (requires auth)**

```bash
# Without auth — should get 401
curl -s -X POST "https://perti.vatcscc.org/api/swim/v1/ingest/ctot.php" \
  -H "Content-Type: application/json" \
  -d '{"assignments": [{"callsign": "TEST", "ctot": "2026-04-23T19:00:00Z"}]}' \
  | python -m json.tool
# Expected: 401 UNAUTHORIZED

# With CTP API key
curl -s -X POST "https://perti.vatcscc.org/api/swim/v1/ingest/ctot.php" \
  -H "Content-Type: application/json" \
  -H "X-API-Key: CTP_API_KEY_HERE" \
  -d '{
    "assignments": [{
      "callsign": "REPLACE_WITH_ACTIVE",
      "ctot": "2026-04-23T19:00:00Z",
      "delay_minutes": 30,
      "assigned_route": "HAPIE DCT CYMON NAT-A LIMRI",
      "assigned_track": "A"
    }]
  }' | python -m json.tool
```

**Expected:**
- `status: "created"` with `control_id`, `eobt`, `estimated_time_of_arrival`, `estimated_elapsed_time`
- `recalc_status: "complete"`

- [ ] **Step 5: Verify database state after CTOT**

```sql
-- Check tmi_flight_control
SELECT control_id, callsign, ctd_utc, ctl_type
FROM VATSIM_TMI.dbo.tmi_flight_control
WHERE ctl_type = 'CTP' ORDER BY control_id DESC;

-- Check swim_flights
SELECT flight_uid, target_takeoff_time, controlled_time_of_departure,
       estimated_takeoff_time, computed_ete_minutes, edct_utc
FROM SWIM_API.dbo.swim_flights
WHERE callsign = 'REPLACE';

-- Check adl_flight_times
SELECT estimated_takeoff_time, computed_ete_minutes, eta_utc, eta_method
FROM VATSIM_ADL.dbo.adl_flight_times
WHERE flight_uid = REPLACE_UID;
```

- [ ] **Step 6: Test idempotency**

Re-submit the same CTOT for the same callsign:

```bash
# Same CTOT — should get "skipped"
curl -s -X POST "https://perti.vatcscc.org/api/swim/v1/ingest/ctot.php" \
  -H "Content-Type: application/json" \
  -H "X-API-Key: CTP_API_KEY_HERE" \
  -d '{"assignments": [{"callsign": "SAME_CALLSIGN", "ctot": "2026-04-23T19:00:00Z"}]}' \
  | python -m json.tool
```

**Expected:** `status: "skipped"`, `recalc_status: "skipped_idempotent"`.

- [ ] **Step 7: Final commit — update spec status**

```bash
# Update spec status from "Design" to "Implemented"
# Edit docs/superpowers/specs/2026-04-23-ctp-ete-edct-api-design.md line 4
git add docs/superpowers/specs/2026-04-23-ctp-ete-edct-api-design.md
git commit -m "docs: mark CTP ETE/CTOT API spec as implemented"
```
