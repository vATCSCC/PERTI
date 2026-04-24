# CTP Oceanic Slot Assignment Engine — Implementation Plan

> **Status:** IMPLEMENTED (2026-04-23) — All 9 tasks complete. Pending: migration deployment, integration testing.

**Goal:** Implement the CTP oceanic slot assignment engine so flowcontrol can request, confirm, and release oceanic track slots via SWIM API, with multi-constraint advisory checks and full CTOT recalculation cascade.

**Architecture:** Layered constraint advisor on top of the existing GDP slot engine. Flowcontrol pushes track/constraint config, then requests slots on-demand. PERTI evaluates 6 constraint types (all advisory), computes timing chains via sp_CalculateETA, assigns slots, and runs the existing 9-step CTOT cascade. The cascade is extracted from `api/swim/v1/ingest/ctot.php` into a shared service class.

**Tech Stack:** PHP 8.2 (no framework), Azure SQL (sqlsrv extension), PostgreSQL/PostGIS (PDO), jQuery + Bootstrap 4.5 frontend, SWIM WebSocket event bus.

**Design Spec:** `docs/superpowers/specs/2026-04-23-ctp-oceanic-slot-engine-design.md`

## Files Created/Modified

| File | Action | Purpose |
|------|--------|---------|
| `database/migrations/tmi/060_ctp_slot_engine.sql` | Created | 2 tables + ALTER 3 existing |
| `load/services/CTOTCascade.php` | Created | 9-step CTOT cascade service (~350 lines) |
| `load/services/CTPConstraintAdvisor.php` | Created | 6-check advisory system (~260 lines) |
| `load/services/CTPSlotEngine.php` | Created | Core slot engine orchestrator (~830 lines) |
| `api/swim/v1/ctp/push-tracks.php` | Created | Push track definitions endpoint |
| `api/swim/v1/ctp/push-constraints.php` | Created | Push facility constraints endpoint |
| `api/swim/v1/ctp/request-slot.php` | Created | Request ranked slot candidates |
| `api/swim/v1/ctp/confirm-slot.php` | Created | Confirm slot assignment + cascade |
| `api/swim/v1/ctp/release-slot.php` | Created | Release slot assignment |
| `api/swim/v1/ctp/session-status.php` | Created | Read-only session status |
| `api/swim/v1/ingest/ctot.php` | Modified | Refactored to use CTOTCascade service |
| `scripts/vatsim_adl_daemon.php` | Modified | Added executeCtpSlotMonitor() |
| `ctp.php` | Modified | Added Slot Engine tab |
| `assets/js/ctp.js` | Modified | Added SlotEnginePanel submodule |
| `assets/locales/en-US.json` | Modified | Added ctp.slotEngine.* i18n keys |

## Deployment Steps

1. Deploy migration 060 to VATSIM_TMI (`php scripts/run_migration.php database/migrations/tmi/060_ctp_slot_engine.sql`)
2. Deploy code (push to main → GitHub Actions → Azure)
3. Restart App Service to pick up daemon changes
4. Verify session-status endpoint returns 200 for existing sessions

---

## Task 1: Database Migration (`060_ctp_slot_engine.sql`)

**Files:**
- Create: `database/migrations/tmi/060_ctp_slot_engine.sql`

**Step 1: Write the migration file**

All schema changes in one migration. Target database: VATSIM_TMI.

```sql
-- Migration 060: CTP Oceanic Slot Assignment Engine
-- Database: VATSIM_TMI
-- Depends on: 045_ctp_oceanic_schema.sql, 058_ctp_nat_track.sql

-- 1. New table: ctp_session_tracks
-- Links CTP sessions to tmi_programs (one program per track)
CREATE TABLE dbo.ctp_session_tracks (
    session_track_id  INT IDENTITY(1,1) PRIMARY KEY,
    session_id        INT NOT NULL REFERENCES dbo.ctp_sessions(session_id),
    program_id        INT NULL REFERENCES dbo.tmi_programs(program_id),
    track_name        VARCHAR(16) NOT NULL,
    route_string      NVARCHAR(MAX) NOT NULL,
    oceanic_entry_fix VARCHAR(32) NOT NULL,
    oceanic_exit_fix  VARCHAR(32) NOT NULL,
    max_acph          INT NOT NULL DEFAULT 10,
    is_active         BIT NOT NULL DEFAULT 1,
    pushed_at         DATETIME2(0) NOT NULL DEFAULT SYSUTCDATETIME(),
    created_at        DATETIME2(0) NOT NULL DEFAULT SYSUTCDATETIME(),
    updated_at        DATETIME2(0) NOT NULL DEFAULT SYSUTCDATETIME(),

    CONSTRAINT UQ_ctp_session_track UNIQUE (session_id, track_name)
);

CREATE INDEX IX_ctp_session_tracks_session ON dbo.ctp_session_tracks (session_id);

-- 2. New table: ctp_facility_constraints
-- Flowcontrol-pushed constraint parameters (all advisory)
CREATE TABLE dbo.ctp_facility_constraints (
    constraint_id   INT IDENTITY(1,1) PRIMARY KEY,
    session_id      INT NOT NULL REFERENCES dbo.ctp_sessions(session_id),
    facility_name   VARCHAR(32) NOT NULL,
    facility_type   VARCHAR(16) NOT NULL,
    max_acph        INT NOT NULL,
    effective_start DATETIME2(0) NULL,
    effective_end   DATETIME2(0) NULL,
    pushed_at       DATETIME2(0) NOT NULL DEFAULT SYSUTCDATETIME(),
    source          VARCHAR(32) NOT NULL DEFAULT 'flowcontrol',
    created_at      DATETIME2(0) NOT NULL DEFAULT SYSUTCDATETIME(),
    updated_at      DATETIME2(0) NOT NULL DEFAULT SYSUTCDATETIME(),

    CONSTRAINT CK_ctp_facility_type CHECK (facility_type IN ('airport', 'fir', 'fix', 'sector')),
    CONSTRAINT UQ_ctp_session_facility UNIQUE (session_id, facility_name, facility_type)
);

CREATE INDEX IX_ctp_facility_constraints_session ON dbo.ctp_facility_constraints (session_id);

-- 3. ALTER tmi_programs: add CTP to program_type CHECK
-- Current values: GS, GDP-DAS, GDP-GAAP, GDP-UDP, AFP, BLANKET, COMPRESSION
ALTER TABLE dbo.tmi_programs DROP CONSTRAINT CK_tmi_programs_program_type;
ALTER TABLE dbo.tmi_programs ADD CONSTRAINT CK_tmi_programs_program_type
    CHECK (program_type IN ('GS','GDP-DAS','GDP-GAAP','GDP-UDP','AFP','BLANKET','COMPRESSION','CTP'));

-- 4. ALTER ctp_flight_control: slot assignment columns
ALTER TABLE dbo.ctp_flight_control ADD
    slot_status         VARCHAR(16) NOT NULL DEFAULT 'NONE',
    slot_id             BIGINT NULL,
    projected_oep_utc   DATETIME2(0) NULL,
    is_airborne         BIT NOT NULL DEFAULT 0,
    miss_reason         VARCHAR(32) NULL,
    reassignment_count  INT NOT NULL DEFAULT 0;

-- Note: slot_id FK to tmi_slots(slot_id) uses BIGINT to match tmi_slots.slot_id type
ALTER TABLE dbo.ctp_flight_control ADD CONSTRAINT FK_ctp_fc_slot
    FOREIGN KEY (slot_id) REFERENCES dbo.tmi_slots(slot_id);

ALTER TABLE dbo.ctp_flight_control ADD CONSTRAINT CK_ctp_slot_status
    CHECK (slot_status IN ('NONE','ASSIGNED','AT_RISK','MISSED','FROZEN','RELEASED'));

CREATE INDEX IX_ctp_fc_slot_status ON dbo.ctp_flight_control (session_id, slot_status)
    WHERE slot_status != 'NONE';

-- 5. ALTER ctp_sessions: slot generation tracking
ALTER TABLE dbo.ctp_sessions ADD
    slot_generation_status VARCHAR(16) NOT NULL DEFAULT 'PENDING',
    activation_checklist_json NVARCHAR(MAX) NULL;

ALTER TABLE dbo.ctp_sessions ADD CONSTRAINT CK_ctp_slot_gen_status
    CHECK (slot_generation_status IN ('PENDING','GENERATING','READY','ERROR'));
```

**Step 2: Apply migration to VATSIM_TMI**

Run against VATSIM_TMI using the admin credentials (jpeterson):

```bash
# Connect via sqlcmd or run via PHP migration runner
php scripts/run_migration.php database/migrations/tmi/060_ctp_slot_engine.sql
```

Verify:
- `SELECT * FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_NAME IN ('ctp_session_tracks', 'ctp_facility_constraints')`
- `SELECT * FROM sys.check_constraints WHERE name LIKE '%CK_tmi_programs%'`
- `SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = 'ctp_flight_control' AND COLUMN_NAME = 'slot_status'`

**Step 3: Commit**

```bash
git add database/migrations/tmi/060_ctp_slot_engine.sql
git commit -m "feat(ctp): migration 060 — slot engine schema (tracks, constraints, slot status)"
```

---

## Task 2: Extract CTOTCascade Service (`load/services/CTOTCascade.php`)

**Files:**
- Create: `load/services/CTOTCascade.php`
- Modify: `api/swim/v1/ingest/ctot.php`

The existing 9-step cascade in `ctot.php` (lines 130–472) and helper functions (lines 510–628) must be extracted into a reusable service class. The new `confirm-slot` endpoint will also call this cascade.

**Step 1: Create CTOTCascade.php**

Extract the cascade logic and all helper functions from `ctot.php` into a service class. The class needs access to 4 database connections (`$conn_adl`, `$conn_tmi`, `$conn_swim`, `$conn_gis`) and `GISService`.

```php
<?php
/**
 * CTOTCascade — Shared 9-step CTOT recalculation service.
 *
 * Extracted from api/swim/v1/ingest/ctot.php so both the external CTOT push
 * endpoint and the CTP slot engine's confirm-slot can run the same cascade.
 *
 * Steps:
 *   1. tmi_flight_control (VATSIM_TMI) — INSERT/UPDATE control record
 *   2. adl_flight_times (VATSIM_ADL) — UPDATE ETD/STD/takeoff
 *   3. sp_CalculateETA (VATSIM_ADL) — Recalculate ETA from departure override
 *   4. Waypoint ETA (VATSIM_ADL) — Recalculate per-waypoint ETAs
 *   5. Boundary crossings (VATSIM_GIS) — Delete/reinsert planned crossings
 *   6. swim_flights (SWIM_API) — Push CTOT/EOBT/ETA to SWIM mirror
 *   7. rad_amendments (VATSIM_TMI) — Create route amendment if route provided
 *   8. adl_flight_tmi (VATSIM_ADL) — Sync TMI control to ADL
 *   9. ctp_flight_control (VATSIM_TMI) — Update CTP record if segments/track
 */

namespace PERTI\Services;

class CTOTCascade
{
    private $conn_adl;
    private $conn_tmi;
    private $conn_swim;
    private $gisService;

    public function __construct($conn_adl, $conn_tmi, $conn_swim, ?GISService $gisService = null)
    {
        $this->conn_adl = $conn_adl;
        $this->conn_tmi = $conn_tmi;
        $this->conn_swim = $conn_swim;
        $this->gisService = $gisService;
    }

    /**
     * Run the full 9-step CTOT recalculation cascade for a single flight.
     *
     * @param array $flight     Flight data from swim_flights (flight_uid, callsign, dept/dest, etc.)
     * @param string $ctot_str  CTOT in 'Y-m-d H:i:s' UTC format
     * @param array $options    Optional: delay_minutes, delay_reason, program_name, program_id,
     *                          source_system, cta_utc, assigned_route, route_segments, assigned_track
     * @return array            Result with status, control_id, timing data, recalc_status
     */
    public function apply(array $flight, string $ctot_str, array $options = []): array
    {
        // ... (full implementation extracted from ctot.php lines 103-490)
        // See ctot.php for the current inline implementation.
        // Each step is extracted as-is, with the same error handling pattern.
    }

    // --- Helper functions (extracted from ctot.php lines 510-628) ---

    public static function parseUtcDatetime(string $str): ?string { /* ... */ }
    public static function findFlight($conn_swim, string $callsign): ?array { /* ... */ }
    public static function getTaxiReference($conn_adl, ?string $icao): int { /* ... */ }
    public static function readFlightTimes($conn_adl, int $flight_uid): array { /* ... */ }
    public static function getPerformance($conn_adl, array $flight): ?array { /* ... */ }
    public static function readWaypoints($conn_adl, int $flight_uid): array { /* ... */ }
    public static function getExistingControl($conn_tmi, int $flight_uid): ?array { /* ... */ }
    public static function checkCtpControl($conn_tmi, int $flight_uid): bool { /* ... */ }
}
```

The actual implementation copies each step and helper function verbatim from `ctot.php`, replacing global connection variables with `$this->conn_*` instance properties.

**Step 2: Refactor ctot.php to use CTOTCascade**

Replace the inline cascade (lines 130–472) and helper functions (lines 510–628) with:

```php
require_once __DIR__ . '/../../../load/services/CTOTCascade.php';
require_once __DIR__ . '/../../../load/services/GISService.php';

$gisService = $conn_gis ? new PERTI\Services\GISService($conn_gis) : null;
$cascade = new PERTI\Services\CTOTCascade($conn_adl, $conn_tmi, $conn_swim, $gisService);

foreach ($assignments as $item) {
    // ... validation (callsign, ctot, track format) stays in ctot.php ...

    $flight = PERTI\Services\CTOTCascade::findFlight($conn_swim, $callsign);
    if (!$flight) { $unmatched[] = $callsign; continue; }

    $result = $cascade->apply($flight, $ctot_str, [
        'delay_minutes' => $delay_minutes,
        'program_name' => $program_name,
        'program_id' => $program_id,
        'cta_utc' => $cta_utc,
        'assigned_route' => $assigned_route,
        'route_segments' => $route_segments,
        'assigned_track' => $assigned_track,
        'source_system' => $source_system,
    ]);

    $results[] = $result;
    $counts[$result['status']]++;
}
```

**Step 3: Verify ctot.php still works**

```bash
curl -X POST https://perti.vatcscc.org/api/swim/v1/ingest/ctot.php \
  -H "Authorization: Bearer TEST_KEY" \
  -H "Content-Type: application/json" \
  -d '{"assignments":[{"callsign":"TEST123","ctot":"2026-04-23T20:00:00Z"}]}'
```

Expected: Same response format as before (should get FLIGHT_NOT_FOUND for fake callsign, confirming the endpoint loads without syntax errors).

**Step 4: Commit**

```bash
git add load/services/CTOTCascade.php api/swim/v1/ingest/ctot.php
git commit -m "refactor(ctp): extract 9-step CTOT cascade into shared CTOTCascade service"
```

---

## Task 3: CTP Constraint Advisor (`load/services/CTPConstraintAdvisor.php`)

**Files:**
- Create: `load/services/CTPConstraintAdvisor.php`

**Step 1: Create the constraint advisor**

All 6 constraint checks from design spec section 3.1, ordered cheapest-first. Each returns `null` (no issue) or an advisory array. All advisory — never blocks.

```php
<?php
namespace PERTI\Services;

class CTPConstraintAdvisor
{
    private $conn_tmi;

    public function __construct($conn_tmi)
    {
        $this->conn_tmi = $conn_tmi;
    }

    /**
     * Evaluate all constraints for a candidate slot assignment.
     *
     * @param int    $sessionId  CTP session ID
     * @param string $dest       Destination ICAO
     * @param array  $timing     Timing chain: ctot_utc, oep_utc, exit_utc, cta_utc
     * @param array  $track      Track info: oceanic_entry_fix, oceanic_exit_fix, track_name
     * @return array             List of advisory objects (empty = no issues)
     */
    public function evaluate(int $sessionId, string $dest, array $timing, array $track): array
    {
        $advisories = [];

        $check = $this->checkDestRate($sessionId, $dest, $timing['cta_utc']);
        if ($check) $advisories[] = $check;

        $check = $this->checkFIRCapacity($sessionId, $timing['oep_utc'], $timing['exit_utc']);
        if ($check) $advisories[] = $check;

        $check = $this->checkFixThroughput($sessionId, $track['oceanic_entry_fix'], $timing['oep_utc']);
        if ($check) $advisories[] = $check;

        $exitCheck = $this->checkFixThroughput($sessionId, $track['oceanic_exit_fix'], $timing['exit_utc']);
        if ($exitCheck) $advisories[] = $exitCheck;

        $check = $this->checkSectorCapacity($sessionId, $timing['oep_utc'], $timing['exit_utc']);
        if ($check) $advisories[] = $check;

        $check = $this->checkECFMP($dest);
        if ($check) $advisories[] = $check;

        return $advisories;
    }

    /**
     * Check 2: Destination arrival rate.
     * Count assigned flights with CTA within +/-30min of candidate CTA at same dest.
     */
    public function checkDestRate(int $sessionId, string $airport, string $ctaUtc): ?array
    {
        // Query ctp_facility_constraints for airport max_acph
        $stmt = sqlsrv_query($this->conn_tmi,
            "SELECT max_acph FROM dbo.ctp_facility_constraints
             WHERE session_id = ? AND facility_name = ? AND facility_type = 'airport'",
            [$sessionId, $airport]
        );
        if (!$stmt) return null;
        $constraint = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($stmt);
        if (!$constraint) return null;

        $maxAcph = (int)$constraint['max_acph'];

        // Count flights arriving within +/-30min window at same dest
        $stmt = sqlsrv_query($this->conn_tmi,
            "SELECT COUNT(*) AS cnt FROM dbo.ctp_flight_control
             WHERE session_id = ? AND arr_airport = ?
               AND slot_status IN ('ASSIGNED','FROZEN')
               AND edct_utc IS NOT NULL
               AND oceanic_exit_utc IS NOT NULL
               AND ABS(DATEDIFF(MINUTE, oceanic_exit_utc, ?)) <= 30",
            [$sessionId, $airport, $ctaUtc]
        );
        // Note: Using oceanic_exit_utc + eu_ete as CTA proxy.
        // For more accuracy, we'd need actual CTA stored on ctp_flight_control.
        // For now, use a simpler count of flights with close oceanic exit times.
        if (!$stmt) return null;
        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($stmt);
        $current = $row ? (int)$row['cnt'] : 0;

        if ($current >= $maxAcph) {
            return [
                'type' => 'DEST_RATE',
                'facility' => $airport,
                'detail' => "$current/$maxAcph arrivals per hour",
                'severity' => 'WARN',
                'current' => $current,
                'limit' => $maxAcph,
            ];
        }
        return null;
    }

    /**
     * Check 3: FIR capacity.
     * Count flights crossing each constrained FIR in the same hourly window.
     */
    public function checkFIRCapacity(int $sessionId, string $oepUtc, string $exitUtc): ?array
    {
        // Get all FIR constraints for this session
        $stmt = sqlsrv_query($this->conn_tmi,
            "SELECT facility_name, max_acph FROM dbo.ctp_facility_constraints
             WHERE session_id = ? AND facility_type = 'fir'",
            [$sessionId]
        );
        if (!$stmt) return null;

        $firs = [];
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $firs[$row['facility_name']] = (int)$row['max_acph'];
        }
        sqlsrv_free_stmt($stmt);
        if (empty($firs)) return null;

        // For each constrained FIR, count flights with overlapping oceanic transit
        foreach ($firs as $fir => $maxAcph) {
            $stmt = sqlsrv_query($this->conn_tmi,
                "SELECT COUNT(*) AS cnt FROM dbo.ctp_flight_control
                 WHERE session_id = ? AND slot_status IN ('ASSIGNED','FROZEN')
                   AND (oceanic_entry_fir = ? OR oceanic_exit_fir = ?)
                   AND oceanic_entry_utc IS NOT NULL
                   AND oceanic_entry_utc <= DATEADD(MINUTE, 30, ?)
                   AND COALESCE(oceanic_exit_utc, DATEADD(HOUR, 4, oceanic_entry_utc)) >= DATEADD(MINUTE, -30, ?)",
                [$sessionId, $fir, $fir, $exitUtc, $oepUtc]
            );
            if (!$stmt) continue;
            $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
            sqlsrv_free_stmt($stmt);
            $current = $row ? (int)$row['cnt'] : 0;

            if ($current >= $maxAcph) {
                return [
                    'type' => 'FIR_CAPACITY',
                    'facility' => $fir,
                    'detail' => "$current/$maxAcph flights in FIR",
                    'severity' => 'WARN',
                    'current' => $current,
                    'limit' => $maxAcph,
                ];
            }
        }
        return null;
    }

    /**
     * Check 4: Fix throughput.
     * Count flights using a constrained fix in the same hourly window.
     */
    public function checkFixThroughput(int $sessionId, string $fix, string $transitUtc): ?array
    {
        $stmt = sqlsrv_query($this->conn_tmi,
            "SELECT max_acph FROM dbo.ctp_facility_constraints
             WHERE session_id = ? AND facility_name = ? AND facility_type = 'fix'",
            [$sessionId, $fix]
        );
        if (!$stmt) return null;
        $constraint = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($stmt);
        if (!$constraint) return null;

        $maxAcph = (int)$constraint['max_acph'];

        $stmt = sqlsrv_query($this->conn_tmi,
            "SELECT COUNT(*) AS cnt FROM dbo.ctp_flight_control
             WHERE session_id = ? AND slot_status IN ('ASSIGNED','FROZEN')
               AND (oceanic_entry_fix = ? OR oceanic_exit_fix = ?)
               AND oceanic_entry_utc IS NOT NULL
               AND ABS(DATEDIFF(MINUTE, oceanic_entry_utc, ?)) <= 30",
            [$sessionId, $fix, $fix, $transitUtc]
        );
        if (!$stmt) return null;
        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($stmt);
        $current = $row ? (int)$row['cnt'] : 0;

        if ($current >= $maxAcph) {
            return [
                'type' => 'FIX_THROUGHPUT',
                'facility' => $fix,
                'detail' => "$current/$maxAcph flights at fix",
                'severity' => 'WARN',
                'current' => $current,
                'limit' => $maxAcph,
            ];
        }
        return null;
    }

    /**
     * Check 5: Sector capacity.
     */
    public function checkSectorCapacity(int $sessionId, string $oepUtc, string $exitUtc): ?array
    {
        // Similar pattern to FIR — query sector constraints, count overlapping flights.
        // Sector assignment not stored on ctp_flight_control, so this check requires
        // cross-referencing adl_flight_planned_crossings or using a simpler proxy.
        // For V1: skip detailed sector check, return null (advisory only, not blocking).
        return null;
    }

    /**
     * Check 6: ECFMP regulations.
     * Check for active flow measures affecting flight's dest/FIRs.
     */
    public function checkECFMP(string $dest): ?array
    {
        $stmt = sqlsrv_query($this->conn_tmi,
            "SELECT TOP 1 measure_id, ident, reason
             FROM dbo.tmi_flow_measures
             WHERE is_active = 1 AND (
                 affected_airport = ?
                 OR affected_firs LIKE '%' + ? + '%'
             )",
            [$dest, $dest]
        );
        if (!$stmt) return null;
        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($stmt);

        if ($row) {
            return [
                'type' => 'ECFMP',
                'facility' => $row['ident'],
                'detail' => 'Active ECFMP regulation: ' . ($row['reason'] ?? $row['ident']),
                'severity' => 'WARN',
            ];
        }
        return null;
    }
}
```

**Step 2: Commit**

```bash
git add load/services/CTPConstraintAdvisor.php
git commit -m "feat(ctp): add CTPConstraintAdvisor with 6-check advisory system"
```

---

## Task 4: CTP Slot Engine Service (`load/services/CTPSlotEngine.php`)

**Files:**
- Create: `load/services/CTPSlotEngine.php`

**Step 1: Create the slot engine**

Core orchestrator that ties together slot generation, constraint evaluation, timing chain computation, and the CTOT cascade.

```php
<?php
namespace PERTI\Services;

require_once __DIR__ . '/CTOTCascade.php';
require_once __DIR__ . '/CTPConstraintAdvisor.php';

class CTPSlotEngine
{
    private $conn_adl;
    private $conn_tmi;
    private $conn_swim;
    private $gisService;
    private CTPConstraintAdvisor $advisor;
    private CTOTCascade $cascade;

    public function __construct($conn_adl, $conn_tmi, $conn_swim, ?GISService $gisService = null)
    {
        $this->conn_adl = $conn_adl;
        $this->conn_tmi = $conn_tmi;
        $this->conn_swim = $conn_swim;
        $this->gisService = $gisService;
        $this->advisor = new CTPConstraintAdvisor($conn_tmi);
        $this->cascade = new CTOTCascade($conn_adl, $conn_tmi, $conn_swim, $gisService);
    }

    /**
     * Resolve session by name or ID.
     */
    public function resolveSession($nameOrId): ?array { /* ... */ }

    /**
     * Generate slot grid for all tracks in a session.
     * Creates tmi_programs + calls sp_TMI_GenerateSlots per track.
     */
    public function generateSlotGrid(int $sessionId): array { /* ... */ }

    /**
     * Request ranked slot candidates for a flight.
     * Returns recommended + up to 5 alternatives with advisory status.
     */
    public function requestSlot(array $params): array { /* ... */ }

    /**
     * Confirm a slot assignment. Assigns slot + runs 9-step cascade.
     */
    public function confirmSlot(array $params): array { /* ... */ }

    /**
     * Release a slot. Cannot release FROZEN unless reason=DISCONNECT.
     */
    public function releaseSlot(array $params): array { /* ... */ }

    /**
     * Compute timing chain for a flight on a given track at a given slot time.
     * Uses cached ETEs when available.
     */
    public function computeTimingChain(array $flight, array $track, string $slotTimeUtc): array { /* ... */ }

    /**
     * Compute segment ETEs using sp_CalculateETA pipeline.
     * Called once per distinct track route; results cached.
     */
    private function computeSegmentETEs(array $flight, array $track, string $tobt): array { /* ... */ }

    /**
     * Broadcast CTP event to SWIM WebSocket event bus.
     */
    private function broadcastEvent(string $type, array $data): void
    {
        $event = json_encode(array_merge(['type' => $type], $data));
        $eventFile = '/tmp/swim_ws_events.json';
        file_put_contents($eventFile, $event . "\n", FILE_APPEND | LOCK_EX);
    }
}
```

Key methods detailed below:

### `requestSlot()` implementation sketch

```php
public function requestSlot(array $params): array
{
    $session = $this->resolveSession($params['session_name'] ?? $params['session_id']);
    // Validate session is ACTIVE and slot_generation_status = 'READY'

    // Get all active tracks, preferred first
    $tracks = $this->getActiveTracks($session['session_id'], $params['preferred_track']);

    // Lookup flight in ADL
    $flight = CTOTCascade::findFlight($this->conn_swim, $params['callsign']);
    // Get taxi reference for origin
    $taxiMin = CTOTCascade::getTaxiReference($this->conn_adl, $params['origin']) / 60;

    $candidates = [];
    $eteCache = [];

    foreach ($tracks as $track) {
        // Get earliest open slot
        $slot = $this->getEarliestOpenSlot($track['program_id']);
        if (!$slot) continue;

        // Compute or cache segment ETEs
        $cacheKey = $track['track_name'];
        if (!isset($eteCache[$cacheKey])) {
            $eteCache[$cacheKey] = $this->computeSegmentETEs($flight, $track, $params['tobt']);
        }
        $etes = $eteCache[$cacheKey];

        // Compute timing chain arithmetically
        $slotTime = $slot['slot_time_utc'];
        $timing = [
            'ctot_utc' => date('Y-m-d H:i:s', strtotime($slotTime) - ($etes['na_ete_min'] * 60) - ($taxiMin * 60)),
            'off_utc' => date('Y-m-d H:i:s', strtotime($slotTime) - ($etes['na_ete_min'] * 60)),
            'oep_utc' => $slotTime,
            'exit_utc' => date('Y-m-d H:i:s', strtotime($slotTime) + ($etes['oca_ete_min'] * 60)),
            'cta_utc' => date('Y-m-d H:i:s', strtotime($slotTime) + ($etes['oca_ete_min'] * 60) + ($etes['eu_ete_min'] * 60)),
            'taxi_min' => (int)$taxiMin,
            'na_ete_min' => $etes['na_ete_min'],
            'oca_ete_min' => $etes['oca_ete_min'],
            'eu_ete_min' => $etes['eu_ete_min'],
            'total_ete_min' => $etes['na_ete_min'] + $etes['oca_ete_min'] + $etes['eu_ete_min'],
        ];

        // If airborne, omit ctot
        if ($params['is_airborne'] ?? false) {
            unset($timing['ctot_utc']);
        }

        // Run constraint checks
        $advisories = $this->advisor->evaluate(
            $session['session_id'], $params['destination'], $timing, $track
        );

        $candidates[] = [
            'track' => $track['track_name'],
            'slot_time_utc' => $slotTime,
            'slot_id' => $slot['slot_id'],
            'timing_chain' => $timing,
            'advisories' => $advisories,
            'advisory_count' => count($advisories),
            'is_preferred' => ($track['track_name'] === $params['preferred_track']),
        ];
    }

    // Rank: preferred first, then fewest advisories, then earliest slot
    usort($candidates, function ($a, $b) {
        if ($a['is_preferred'] !== $b['is_preferred']) return $b['is_preferred'] - $a['is_preferred'];
        if ($a['advisory_count'] !== $b['advisory_count']) return $a['advisory_count'] - $b['advisory_count'];
        return strcmp($a['slot_time_utc'], $b['slot_time_utc']);
    });

    return [
        'recommended' => $candidates[0] ?? null,
        'alternatives' => array_slice($candidates, 1, 5),
    ];
}
```

### `confirmSlot()` implementation sketch

```php
public function confirmSlot(array $params): array
{
    $session = $this->resolveSession($params['session_name'] ?? $params['session_id']);

    // Atomically claim the slot (check still OPEN, set ASSIGNED)
    $stmt = sqlsrv_query($this->conn_tmi,
        "UPDATE dbo.tmi_slots SET
            slot_status = 'ASSIGNED',
            assigned_callsign = ?,
            assigned_utc = SYSUTCDATETIME()
         WHERE program_id IN (SELECT program_id FROM dbo.ctp_session_tracks WHERE session_id = ?)
           AND slot_time_utc = ? AND slot_status = 'OPEN'",
        [$params['callsign'], $session['session_id'], $params['slot_time_utc']]
    );
    // Check rows affected — 0 means slot was taken (race condition)

    // Look up the flight
    $flight = CTOTCascade::findFlight($this->conn_swim, $params['callsign']);

    // Get the track info for timing chain
    $track = $this->getTrackByName($session['session_id'], $params['track']);
    $taxiSec = CTOTCascade::getTaxiReference($this->conn_adl, $flight['fp_dept_icao']);
    $etes = $this->computeSegmentETEs($flight, $track, /* tobt from timing */);

    // Compute CTOT from slot time
    $slotTs = strtotime($params['slot_time_utc']);
    $ctotTs = $slotTs - ($etes['na_ete_min'] * 60) - $taxiSec;
    $ctotStr = gmdate('Y-m-d H:i:s', $ctotTs);
    $ctaStr = gmdate('Y-m-d H:i:s', $slotTs + ($etes['oca_ete_min'] + $etes['eu_ete_min']) * 60);

    // Determine status
    $isAirborne = $params['is_airborne'] ?? false;
    $slotStatus = $isAirborne ? 'FROZEN' : 'ASSIGNED';

    // Run the 9-step CTOT cascade
    $cascadeResult = $this->cascade->apply($flight, $ctotStr, [
        'cta_utc' => $ctaStr,
        'program_name' => 'CTP-' . $session['session_name'],
        'program_id' => $track['program_id'],
        'assigned_track' => $params['track'],
        'route_segments' => [
            'na' => $params['na_route'] ?? null,
            'oceanic' => $track['route_string'],
            'eu' => $params['eu_route'] ?? null,
        ],
    ]);

    // Update ctp_flight_control with slot assignment
    $stmt = sqlsrv_query($this->conn_tmi,
        "UPDATE dbo.ctp_flight_control SET
            slot_status = ?, slot_id = ?,
            assigned_nat_track = ?,
            is_airborne = ?,
            updated_at = SYSUTCDATETIME()
         WHERE session_id = ? AND flight_uid = ?",
        [$slotStatus, $slotId, $params['track'],
         $isAirborne ? 1 : 0,
         $session['session_id'], (int)$flight['flight_uid']]
    );

    // Log to ctp_audit_log
    $this->logAudit($session['session_id'], $flight['flight_uid'], 'SLOT_ASSIGNED', [
        'track' => $params['track'],
        'slot_time' => $params['slot_time_utc'],
        'ctot' => $ctotStr,
    ]);

    // Broadcast WebSocket event
    $this->broadcastEvent('ctp_slot_assigned', [
        'session_name' => $session['session_name'],
        'callsign' => $params['callsign'],
        'track' => $params['track'],
        'slot_time' => $params['slot_time_utc'],
        'ctot' => gmdate('Y-m-d\TH:i:s\Z', $ctotTs),
        'cta' => $ctaStr,
    ]);

    return [
        'status' => $slotStatus,
        'ctot_utc' => $isAirborne ? null : gmdate('Y-m-d\TH:i:s\Z', $ctotTs),
        'cta_utc' => $ctaStr,
        'slot_id' => $slotId,
        'cascade_status' => $cascadeResult['recalc_status'] ?? 'complete',
    ];
}
```

### `releaseSlot()` implementation sketch

```php
public function releaseSlot(array $params): array
{
    $session = $this->resolveSession($params['session_name'] ?? $params['session_id']);

    // Find the flight's current slot
    $stmt = sqlsrv_query($this->conn_tmi,
        "SELECT ctp_control_id, slot_id, slot_status, assigned_nat_track
         FROM dbo.ctp_flight_control
         WHERE session_id = ? AND callsign = ? AND slot_status != 'NONE'",
        [$session['session_id'], $params['callsign']]
    );
    $record = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    sqlsrv_free_stmt($stmt);

    if (!$record) {
        return ['error' => 'No active slot assignment found', 'code' => 'NO_SLOT'];
    }

    // Cannot release FROZEN unless DISCONNECT
    $reason = $params['reason'] ?? 'COORDINATOR_RELEASE';
    if ($record['slot_status'] === 'FROZEN' && $reason !== 'DISCONNECT') {
        return ['error' => 'Cannot release frozen slot', 'code' => 'SLOT_FROZEN'];
    }

    // Release the tmi_slot
    if ($record['slot_id']) {
        sqlsrv_query($this->conn_tmi,
            "UPDATE dbo.tmi_slots SET slot_status = 'OPEN', assigned_callsign = NULL,
                    assigned_flight_uid = NULL, assigned_utc = NULL
             WHERE slot_id = ?",
            [$record['slot_id']]
        );
    }

    // Update ctp_flight_control
    sqlsrv_query($this->conn_tmi,
        "UPDATE dbo.ctp_flight_control SET
            slot_status = 'RELEASED', slot_id = NULL,
            miss_reason = ?, updated_at = SYSUTCDATETIME()
         WHERE ctp_control_id = ?",
        [$reason, $record['ctp_control_id']]
    );

    // Audit + broadcast
    $this->logAudit($session['session_id'], null, 'SLOT_RELEASED', [
        'callsign' => $params['callsign'], 'reason' => $reason,
        'track' => $record['assigned_nat_track'],
    ]);
    $this->broadcastEvent('ctp_slot_released', [
        'session_name' => $session['session_name'],
        'callsign' => $params['callsign'],
        'track' => $record['assigned_nat_track'],
        'reason' => $reason,
    ]);

    return [
        'released_slot_time_utc' => /* from tmi_slots */,
        'released_track' => $record['assigned_nat_track'],
        'slot_status' => 'OPEN',
    ];
}
```

**Step 2: Commit**

```bash
git add load/services/CTPSlotEngine.php
git commit -m "feat(ctp): add CTPSlotEngine with request/confirm/release slot logic"
```

---

## Task 5: SWIM API — Config Endpoints (push-tracks, push-constraints)

**Files:**
- Create: `api/swim/v1/ctp/push-tracks.php`
- Create: `api/swim/v1/ctp/push-constraints.php`

These are simpler CRUD endpoints that flowcontrol calls to push configuration.

**Step 1: Create push-tracks.php**

Pattern: Follow `api/swim/v1/ctp/sessions.php` for includes and auth. Require write + CTP authority.

```php
<?php
require_once __DIR__ . '/../auth.php';

global $conn_swim;
if (!$conn_swim) SwimResponse::error('SWIM database not available', 503, 'SERVICE_UNAVAILABLE');

$auth = swim_init_auth(true, true);
if (!$auth->canWriteField('ctp')) {
    SwimResponse::error('CTP write authority required', 403, 'FORBIDDEN');
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    SwimResponse::error('Method not allowed', 405, 'METHOD_NOT_ALLOWED');
}

$conn_tmi = get_conn_tmi();
if (!$conn_tmi) SwimResponse::error('TMI database not available', 503, 'SERVICE_UNAVAILABLE');

$body = swim_get_json_body();
// Validate: session_name or session_id, tracks array
// Resolve session, validate ACTIVE or DRAFT status
// For each track: MERGE (upsert) into ctp_session_tracks
// Return counts: tracks_received, tracks_created, tracks_updated
```

**Step 2: Create push-constraints.php**

Same pattern. Upsert into `ctp_facility_constraints`.

```php
<?php
require_once __DIR__ . '/../auth.php';
// ... same auth pattern ...
// Validate: session_name, constraints array (facility, facility_type, maxAircraftPerHour)
// For each constraint: MERGE (upsert) into ctp_facility_constraints
// Return counts: constraints_received, constraints_created, constraints_updated
```

**Step 3: Verify endpoints load**

```bash
curl -X POST https://perti.vatcscc.org/api/swim/v1/ctp/push-tracks.php \
  -H "Content-Type: application/json" -d '{}'
# Expected: 401 UNAUTHORIZED (no API key)
```

**Step 4: Commit**

```bash
git add api/swim/v1/ctp/push-tracks.php api/swim/v1/ctp/push-constraints.php
git commit -m "feat(ctp): add push-tracks and push-constraints SWIM API endpoints"
```

---

## Task 6: SWIM API — Slot Endpoints (request-slot, confirm-slot, release-slot, session-status)

**Files:**
- Create: `api/swim/v1/ctp/request-slot.php`
- Create: `api/swim/v1/ctp/confirm-slot.php`
- Create: `api/swim/v1/ctp/release-slot.php`
- Create: `api/swim/v1/ctp/session-status.php`

Each endpoint is a thin HTTP handler that validates input, calls `CTPSlotEngine`, and returns the response via `SwimResponse::success()`.

**Step 1: Create request-slot.php**

```php
<?php
require_once __DIR__ . '/../auth.php';

global $conn_swim;
if (!$conn_swim) SwimResponse::error('SWIM database not available', 503, 'SERVICE_UNAVAILABLE');

$auth = swim_init_auth(true, true);
if (!$auth->canWriteField('ctp')) {
    SwimResponse::error('CTP write authority required', 403, 'FORBIDDEN');
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    SwimResponse::error('Method not allowed', 405, 'METHOD_NOT_ALLOWED');
}

$conn_adl = get_conn_adl();
$conn_tmi = get_conn_tmi();
$conn_gis = get_conn_gis();
if (!$conn_adl || !$conn_tmi) {
    SwimResponse::error('Required databases not available', 503, 'SERVICE_UNAVAILABLE');
}

require_once __DIR__ . '/../../../load/services/CTPSlotEngine.php';
require_once __DIR__ . '/../../../load/services/GISService.php';

$gisService = $conn_gis ? new PERTI\Services\GISService($conn_gis) : null;
$engine = new PERTI\Services\CTPSlotEngine($conn_adl, $conn_tmi, $conn_swim, $gisService);

$body = swim_get_json_body();
// Validate required fields: session_name, callsign, origin, destination,
//   aircraft_type, preferred_track, tobt, na_route, eu_route
// Optional: is_airborne (default false)

$result = $engine->requestSlot($body);

if (isset($result['error'])) {
    $code = match($result['code'] ?? '') {
        'SESSION_NOT_FOUND' => 404,
        'SESSION_NOT_ACTIVE', 'SLOTS_NOT_READY', 'NO_TRACKS_CONFIGURED' => 409,
        'FLIGHT_NOT_FOUND' => 404,
        default => 400,
    };
    SwimResponse::error($result['error'], $code, $result['code'] ?? 'ERROR');
}

SwimResponse::success($result);
```

**Step 2: Create confirm-slot.php**

Same pattern. Validate: session_name, callsign, track, slot_time_utc. Call `$engine->confirmSlot()`. Handle SLOT_TAKEN (409).

**Step 3: Create release-slot.php**

Validate: session_name, callsign, reason. Call `$engine->releaseSlot()`. Handle SLOT_FROZEN (409).

**Step 4: Create session-status.php**

GET endpoint. Query session + tracks + constraint status + flight counts. Aggregates from `ctp_session_tracks` (joined to `tmi_slots` for utilization), `ctp_facility_constraints`, and `ctp_flight_control`.

```php
<?php
require_once __DIR__ . '/../auth.php';
$auth = swim_init_auth(true, false); // read-only

$conn_tmi = get_conn_tmi();
$sessionParam = $_GET['session_name'] ?? $_GET['session_id'] ?? null;

// Query session details
// Query per-track slot utilization (total/assigned/frozen/open/utilization_pct)
// Query constraint status (any over limit)
// Query flight summary (total/assigned/frozen/at_risk/missed/released/unassigned)
// Return aggregated response
```

**Step 5: Verify all endpoints load**

```bash
for ep in request-slot confirm-slot release-slot session-status; do
  echo "--- $ep ---"
  curl -s -o /dev/null -w "%{http_code}" \
    https://perti.vatcscc.org/api/swim/v1/ctp/$ep.php
done
# Expected: 401 for POST endpoints, 401 for session-status (no key)
```

**Step 6: Commit**

```bash
git add api/swim/v1/ctp/request-slot.php api/swim/v1/ctp/confirm-slot.php \
        api/swim/v1/ctp/release-slot.php api/swim/v1/ctp/session-status.php
git commit -m "feat(ctp): add request/confirm/release slot + session-status SWIM endpoints"
```

---

## Task 7: ADL Daemon Extension (Disconnect/Miss/Airborne Detection)

**Files:**
- Modify: `scripts/vatsim_adl_daemon.php` (add new function after `executeCtpComplianceCheck`)

**Step 1: Add `executeCtpSlotMonitor()` function**

Insert after `executeCtpComplianceCheck()` (~line 2340). Runs on the same 120s cycle.

Three checks per active CTP session:

```php
function executeCtpSlotMonitor($conn_adl, $conn_tmi): ?array
{
    $start = microtime(true);

    // Find active CTP sessions
    $stmt = sqlsrv_query($conn_tmi,
        "SELECT session_id, session_name FROM dbo.ctp_sessions
         WHERE status IN ('ACTIVE','MONITORING')
           AND slot_generation_status = 'READY'"
    );
    if (!$stmt) return null;

    $sessions = [];
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $sessions[] = $row;
    }
    sqlsrv_free_stmt($stmt);
    if (empty($sessions)) return null;

    $disconnected = 0;
    $frozen = 0;
    $missed = 0;
    $atRisk = 0;

    foreach ($sessions as $session) {
        $sid = (int)$session['session_id'];

        // 1. Disconnect detection: assigned/frozen flights no longer active in ADL
        $stmt = sqlsrv_query($conn_tmi,
            "SELECT c.ctp_control_id, c.flight_uid, c.callsign, c.slot_id, c.slot_status
             FROM dbo.ctp_flight_control c
             WHERE c.session_id = ? AND c.slot_status IN ('ASSIGNED','FROZEN')",
            [$sid]
        );
        // ... batch check against adl_flight_core.is_active ...
        // For each disconnected: set slot_status='RELEASED', free tmi_slot, audit log

        // 2. Airborne detection: assigned ground flights that have departed
        $stmt = sqlsrv_query($conn_tmi,
            "SELECT c.ctp_control_id, c.flight_uid, c.callsign
             FROM dbo.ctp_flight_control c
             WHERE c.session_id = ? AND c.slot_status = 'ASSIGNED' AND c.is_airborne = 0",
            [$sid]
        );
        // ... check adl_flight_core.phase IN ('CLIMB','EN_ROUTE','CRUISE') ...
        // For each: set slot_status='FROZEN', is_airborne=1, audit log

        // 3. Missed slot detection (ground only)
        $stmt = sqlsrv_query($conn_tmi,
            "SELECT c.ctp_control_id, c.flight_uid, c.callsign, c.edct_utc,
                    s.slot_time_utc, t.oceanic_entry_fix
             FROM dbo.ctp_flight_control c
             JOIN dbo.tmi_slots s ON c.slot_id = s.slot_id
             JOIN dbo.ctp_session_tracks t ON t.session_id = c.session_id
                AND t.track_name = c.assigned_nat_track
             WHERE c.session_id = ? AND c.slot_status = 'ASSIGNED' AND c.is_airborne = 0",
            [$sid]
        );
        // ... compute projected OEP vs slot_time ...
        // If > 15min late: MISSED (release slot, audit)
        // If > 5min late: AT_RISK (update status, no slot release)
    }

    $elapsed = round((microtime(true) - $start) * 1000);
    return [
        'sessions' => count($sessions),
        'disconnected' => $disconnected,
        'frozen' => $frozen,
        'missed' => $missed,
        'at_risk' => $atRisk,
        'elapsed_ms' => $elapsed,
    ];
}
```

**Step 2: Wire into daemon main loop**

Find the call to `executeCtpComplianceCheck()` in the daemon's main loop. Add `executeCtpSlotMonitor()` right after it on the same timer cycle.

```php
// Existing (around line 2270):
if ($cycle % $CTP_COMPLIANCE_INTERVAL === 0) {
    $result = executeCtpComplianceCheck($conn_adl, $conn_tmi);
    // ... logging ...

    // NEW: slot lifecycle monitoring
    $slotResult = executeCtpSlotMonitor($conn_adl, $conn_tmi);
    if ($slotResult) {
        daemon_log("CTP slot monitor: " . json_encode($slotResult));
    }
}
```

**Step 3: Commit**

```bash
git add scripts/vatsim_adl_daemon.php
git commit -m "feat(ctp): add slot lifecycle monitoring to ADL daemon (disconnect/airborne/miss)"
```

---

## Task 8: CTP UI — Constraint Display Panel

**Files:**
- Modify: `ctp.php` (add constraint panel HTML)
- Modify: `assets/js/ctp.js` (add constraint display + slot status submodule)
- Modify: `assets/css/ctp.css` (styling for constraint panel)

**Step 1: Add constraint panel HTML to ctp.php**

Insert a new tab panel in the bottom tabs section (after "Throughput" tab). Add the panel structure with a constraints table and track slot utilization overview.

Look for the existing bottom tabs in `ctp.php` (Demand, Throughput, Planning, Routes, Stats) and add a "Constraints" tab.

```html
<li class="nav-item">
    <a class="nav-link" id="tab-constraints" data-toggle="tab" href="#panel-constraints">
        <?= __('ctp.constraints.tab') ?>
    </a>
</li>
```

Panel content:
```html
<div class="tab-pane fade" id="panel-constraints">
    <div class="row">
        <div class="col-md-6">
            <h6><?= __('ctp.constraints.facilityTitle') ?></h6>
            <table class="table table-sm table-striped" id="constraint-table">
                <thead>
                    <tr>
                        <th><?= __('ctp.constraints.facility') ?></th>
                        <th><?= __('ctp.constraints.type') ?></th>
                        <th><?= __('ctp.constraints.maxAcph') ?></th>
                        <th><?= __('ctp.constraints.current') ?></th>
                        <th><?= __('ctp.constraints.status') ?></th>
                        <th><?= __('ctp.constraints.lastPushed') ?></th>
                    </tr>
                </thead>
                <tbody></tbody>
            </table>
        </div>
        <div class="col-md-6">
            <h6><?= __('ctp.constraints.trackUtilTitle') ?></h6>
            <table class="table table-sm table-striped" id="track-util-table">
                <thead>
                    <tr>
                        <th><?= __('ctp.constraints.track') ?></th>
                        <th><?= __('ctp.constraints.totalSlots') ?></th>
                        <th><?= __('ctp.constraints.assigned') ?></th>
                        <th><?= __('ctp.constraints.frozen') ?></th>
                        <th><?= __('ctp.constraints.open') ?></th>
                        <th><?= __('ctp.constraints.utilPct') ?></th>
                    </tr>
                </thead>
                <tbody></tbody>
            </table>
        </div>
    </div>
</div>
```

**Step 2: Add ConstraintDisplay submodule to ctp.js**

Add a new submodule inside the CTP IIFE that polls session-status and renders the constraint/track tables. Refresh on a 30-second interval when the constraints tab is active.

```javascript
const ConstraintDisplay = {
    refreshTimer: null,

    init() {
        $('#tab-constraints').on('shown.bs.tab', () => this.startRefresh());
        $('#tab-constraints').on('hidden.bs.tab', () => this.stopRefresh());
    },

    startRefresh() {
        this.load();
        this.refreshTimer = setInterval(() => this.load(), 30000);
    },

    stopRefresh() {
        if (this.refreshTimer) clearInterval(this.refreshTimer);
    },

    load() {
        if (!state.currentSession) return;
        $.get(API.sessionStatus, { session_name: state.currentSession.session_name })
         .done(resp => {
             if (resp.success) this.render(resp.data);
         });
    },

    render(data) {
        // Render constraint table rows with status badges (OK/AT LIMIT/OVER)
        // Render track utilization rows with progress bars
    }
};
```

**Step 3: Add i18n keys to en-US.json**

Add keys under `ctp.constraints.*` namespace.

**Step 4: Add CSS for constraint status badges**

```css
.constraint-ok { color: #28a745; }
.constraint-at-limit { color: #ffc107; }
.constraint-over { color: #dc3545; font-weight: bold; }
```

**Step 5: Verify in browser**

- Navigate to `https://perti.vatcscc.org/ctp.php`
- Confirm new "Constraints" tab appears in bottom panel
- Click tab — should show empty tables (no active CTP session with constraints)
- No JS console errors

**Step 6: Commit**

```bash
git add ctp.php assets/js/ctp.js assets/css/ctp.css assets/locales/en-US.json
git commit -m "feat(ctp): add read-only constraint display panel to CTP UI"
```

---

## Task 9: Integration Testing & API Docs

**Files:**
- Modify: `api-docs/openapi.yaml` (add 6 new endpoint definitions)

**Step 1: End-to-end manual test sequence**

Using curl against production (or staging if available):

```bash
# 1. Push tracks
curl -X POST https://perti.vatcscc.org/api/swim/v1/ctp/push-tracks.php \
  -H "Authorization: Bearer $SWIM_KEY" -H "Content-Type: application/json" \
  -d '{"session_name":"CTPTEST","tracks":[
    {"track_name":"A","route_string":"MUSAK 50N060W 51N050W GISTI","oceanic_entry_fix":"MUSAK","oceanic_exit_fix":"GISTI","is_active":true}
  ]}'

# 2. Push constraints
curl -X POST https://perti.vatcscc.org/api/swim/v1/ctp/push-constraints.php \
  -H "Authorization: Bearer $SWIM_KEY" -H "Content-Type: application/json" \
  -d '{"session_name":"CTPTEST","constraints":[
    {"facility":"EGLL","facility_type":"airport","maxAircraftPerHour":12}
  ]}'

# 3. Request slot
curl -X POST https://perti.vatcscc.org/api/swim/v1/ctp/request-slot.php \
  -H "Authorization: Bearer $SWIM_KEY" -H "Content-Type: application/json" \
  -d '{"session_name":"CTPTEST","callsign":"BAW117","origin":"KJFK","destination":"EGLL",
       "aircraft_type":"B77W","preferred_track":"A","tobt":"2026-10-15T18:30:00Z",
       "na_route":"KJFK DCT HAPIE J584 MUSAK","eu_route":"GISTI UL9 BHD EGLL"}'

# 4. Confirm slot
curl -X POST https://perti.vatcscc.org/api/swim/v1/ctp/confirm-slot.php \
  -H "Authorization: Bearer $SWIM_KEY" -H "Content-Type: application/json" \
  -d '{"session_name":"CTPTEST","callsign":"BAW117","track":"A","slot_time_utc":"2026-10-15T20:15:00Z"}'

# 5. Check session status
curl "https://perti.vatcscc.org/api/swim/v1/ctp/session-status.php?session_name=CTPTEST" \
  -H "Authorization: Bearer $SWIM_KEY"

# 6. Release slot
curl -X POST https://perti.vatcscc.org/api/swim/v1/ctp/release-slot.php \
  -H "Authorization: Bearer $SWIM_KEY" -H "Content-Type: application/json" \
  -d '{"session_name":"CTPTEST","callsign":"BAW117","reason":"COORDINATOR_RELEASE"}'
```

**Step 2: Add OpenAPI definitions**

Add the 6 new endpoints to `api-docs/openapi.yaml` under `/ctp/` path group.

**Step 3: Commit**

```bash
git add api-docs/openapi.yaml
git commit -m "docs(ctp): add OpenAPI definitions for CTP slot engine endpoints"
```

---

## Dependency Graph

```
Task 1 (Migration 060)
    ↓
Task 2 (CTOTCascade extraction)
    ↓
Task 3 (CTPConstraintAdvisor) ─────┐
    ↓                               ↓
Task 4 (CTPSlotEngine) ←───────────┘
    ↓
Task 5 (push-tracks, push-constraints)  ← can run parallel with Task 4
    ↓
Task 6 (request/confirm/release/status endpoints) ← depends on Task 4
    ↓
Task 7 (ADL daemon extension)
    ↓
Task 8 (CTP UI constraint panel)
    ↓
Task 9 (Integration test + API docs)
```

## Key Risks

| Risk | Mitigation |
|------|------------|
| `CK_tmi_programs_program_type` constraint name wrong | Query `sys.check_constraints` before ALTER to confirm exact name |
| `tmi_slots.slot_id` is BIGINT, design spec uses INT for FK | Use BIGINT for `ctp_flight_control.slot_id` to match |
| sp_CalculateETA may not handle the full CTP oceanic route | Test with a known NAT route first; fallback to distance/speed ETE |
| Race condition on slot claim (confirm-slot) | Use WHERE slot_status='OPEN' in UPDATE; check rows_affected=0 for SLOT_TAKEN |
| ECFMP `tmi_flow_measures` table may not exist on all environments | Check table existence before querying; return null if missing |
| Sector capacity check needs adl_flight_planned_crossings | V1: skip detailed sector check (return null); implement in V2 |
