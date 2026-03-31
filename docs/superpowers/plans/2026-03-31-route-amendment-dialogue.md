# Route Amendment Dialogue (RAD) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a Route Amendment Dialogue page where TMU operators search flights, compose route amendments, deliver via CPDLC, and monitor compliance — plus matching VATSWIM API endpoints.

**Architecture:** Modular PHP page (`rad.php`) with MapLibre map on top and 4 tabbed sections below. Shared `RADService.php` backs both internal (`api/rad/`) and SWIM (`api/swim/v1/rad/`) endpoints. Amendment state in VATSIM_TMI, filter presets in MySQL, route expansion via PostGIS. JS uses event bus pattern following `playbook-*.js` conventions.

**Tech Stack:** PHP 8.2, Azure SQL (sqlsrv), MySQL (PDO), PostgreSQL/PostGIS (PDO), MapLibre GL JS 4.5, jQuery 2.2.4, Bootstrap 4.5, SweetAlert2, existing route-maplibre.js/route-analysis-panel.js/playbook-cdr-search.js

**Spec:** `docs/superpowers/specs/2026-03-31-route-amendment-dialogue-design.md`

**No automated test suite** — this project uses manual testing. Each task includes manual verification steps.

---

## File Structure

### New Files
| File | Purpose |
|------|---------|
| `adl/migrations/core/014_adl_gufi_column.sql` | GUFI UUID column on adl_flight_core |
| `database/migrations/tmi/057_rad_tables.sql` | rad_amendments + rad_amendment_log + adl_flight_tmi columns |
| `database/migrations/swim/036_swim_rad_mirror.sql` | swim_rad_amendments mirror + swim_api_keys.allowed_features |
| `load/services/RADService.php` | Shared service: search, amend, comply, route options |
| `api/rad/common.php` | RAD API shared auth + helpers (follows api/gdt/common.php pattern) |
| `api/rad/search.php` | Flight search endpoint |
| `api/rad/amendment.php` | Amendment CRUD + send/resend/cancel |
| `api/rad/compliance.php` | Compliance polling |
| `api/rad/routes.php` | Route options + recently sent |
| `api/rad/history.php` | Route change history |
| `api/rad/filters.php` | Filter preset CRUD |
| `api/swim/v1/rad/flights.php` | SWIM: flight search |
| `api/swim/v1/rad/amendments.php` | SWIM: amendment CRUD |
| `api/swim/v1/rad/compliance.php` | SWIM: compliance |
| `api/swim/v1/rad/routes.php` | SWIM: route options |
| `api/swim/v1/rad/history.php` | SWIM: route history |
| `rad.php` | Main page |
| `assets/css/rad.css` | RAD styles |
| `assets/js/rad.js` | Controller: tab mgmt, map↔tab coordination |
| `assets/js/rad-event-bus.js` | Pub/sub event bus (~30 LOC) |
| `assets/js/rad-flight-search.js` | Flight Search tab |
| `assets/js/rad-flight-detail.js` | Flight Detail tab |
| `assets/js/rad-amendment.js` | Route Edit tab |
| `assets/js/rad-monitoring.js` | Monitoring tab |

### Modified Files
| File | Change |
|------|--------|
| `scripts/swim_sync.php` | Read `adl_flight_core.gufi` → pass to `swim_flights` |
| `scripts/swim_tmi_sync_daemon.php` | Add `swim_rad_amendments` sync config |
| `scripts/vatsim_adl_daemon.php` | Add RAD compliance check in deferred cycle |
| `load/nav.php` | Add RAD nav link |
| `assets/locales/en-US.json` | Add `rad.*` i18n keys |

---

## Task 1: Database Migrations

**Files:**
- Create: `adl/migrations/core/014_adl_gufi_column.sql`
- Create: `database/migrations/tmi/057_rad_tables.sql`
- Create: `database/migrations/swim/036_swim_rad_mirror.sql`

- [ ] **Step 1: Create ADL GUFI migration**

Create `adl/migrations/core/014_adl_gufi_column.sql`:

```sql
-- Migration 014: Add GUFI (UUID) to adl_flight_core
-- GUFI = Globally Unique Flight Identifier, immutable per flight
-- Generated on INSERT via DEFAULT NEWID(), propagated to SWIM sync

ALTER TABLE dbo.adl_flight_core ADD gufi UNIQUEIDENTIFIER NOT NULL
    CONSTRAINT DF_adl_flight_core_gufi DEFAULT NEWID();

CREATE UNIQUE INDEX IX_adl_flight_core_gufi ON dbo.adl_flight_core (gufi);
```

- [ ] **Step 2: Create TMI RAD tables migration**

Create `database/migrations/tmi/057_rad_tables.sql`:

```sql
-- Migration 057: Route Amendment Dialogue tables
-- rad_amendments: amendment lifecycle tracking
-- rad_amendment_log: audit trail of status transitions

CREATE TABLE dbo.rad_amendments (
    id              INT IDENTITY(1,1) PRIMARY KEY,
    gufi            UNIQUEIDENTIFIER NOT NULL,
    callsign        VARCHAR(10) NOT NULL,
    origin          CHAR(4) NOT NULL,
    destination     CHAR(4) NOT NULL,
    original_route  VARCHAR(MAX),
    assigned_route  VARCHAR(MAX) NOT NULL,
    assigned_route_geojson VARCHAR(MAX),
    status          VARCHAR(10) NOT NULL DEFAULT 'DRAFT',
    rrstat          VARCHAR(10),
    tmi_reroute_id  INT NULL,
    tmi_id_label    VARCHAR(20),
    delivery_channels VARCHAR(50),
    cpdlc_message_id VARCHAR(50),
    route_color     VARCHAR(10),
    created_by      INT,
    created_utc     DATETIME2 NOT NULL DEFAULT SYSUTCDATETIME(),
    sent_utc        DATETIME2,
    delivered_utc   DATETIME2,
    resolved_utc    DATETIME2,
    expires_utc     DATETIME2,
    notes           VARCHAR(500),
    CONSTRAINT CK_rad_status CHECK (status IN ('DRAFT','SENT','DLVD','ACPT','RJCT','EXPR'))
);

CREATE INDEX IX_rad_amendments_gufi ON dbo.rad_amendments (gufi);
CREATE INDEX IX_rad_amendments_status ON dbo.rad_amendments (status)
    INCLUDE (gufi, callsign, origin, destination, assigned_route, rrstat, sent_utc)
    WHERE status NOT IN ('ACPT','RJCT','EXPR');
CREATE INDEX IX_rad_amendments_tmi ON dbo.rad_amendments (tmi_reroute_id)
    WHERE tmi_reroute_id IS NOT NULL;

CREATE TABLE dbo.rad_amendment_log (
    id              INT IDENTITY(1,1) PRIMARY KEY,
    amendment_id    INT NOT NULL,
    status_from     VARCHAR(10),
    status_to       VARCHAR(10) NOT NULL,
    detail          VARCHAR(500),
    changed_by      INT,
    changed_utc     DATETIME2 NOT NULL DEFAULT SYSUTCDATETIME(),
    CONSTRAINT FK_rad_log_amendment FOREIGN KEY (amendment_id)
        REFERENCES dbo.rad_amendments(id) ON DELETE CASCADE
);

CREATE INDEX IX_rad_log_amendment ON dbo.rad_amendment_log (amendment_id);

-- New columns on adl_flight_tmi (cross-DB logical reference to rad_amendments.id)
-- Run this against VATSIM_ADL database
-- ALTER TABLE dbo.adl_flight_tmi ADD rad_amendment_id INT NULL;
-- ALTER TABLE dbo.adl_flight_tmi ADD rad_assigned_route VARCHAR(MAX) NULL;
```

- [ ] **Step 3: Create SWIM mirror migration**

Create `database/migrations/swim/036_swim_rad_mirror.sql`:

```sql
-- Migration 036: SWIM RAD mirror + API key feature gating
-- swim_rad_amendments: mirror of rad_amendments, synced by swim_tmi_sync_daemon.php
-- swim_api_keys.allowed_features: JSON array for feature-level gating

CREATE TABLE dbo.swim_rad_amendments (
    id              INT PRIMARY KEY,
    gufi            UNIQUEIDENTIFIER NOT NULL,
    callsign        VARCHAR(10) NOT NULL,
    origin          CHAR(4) NOT NULL,
    destination     CHAR(4) NOT NULL,
    original_route  VARCHAR(MAX),
    assigned_route  VARCHAR(MAX) NOT NULL,
    assigned_route_geojson VARCHAR(MAX),
    status          VARCHAR(10) NOT NULL,
    rrstat          VARCHAR(10),
    tmi_reroute_id  INT NULL,
    tmi_id_label    VARCHAR(20),
    delivery_channels VARCHAR(50),
    route_color     VARCHAR(10),
    created_by      INT,
    created_utc     DATETIME2,
    sent_utc        DATETIME2,
    delivered_utc   DATETIME2,
    resolved_utc    DATETIME2,
    expires_utc     DATETIME2,
    notes           VARCHAR(500)
);

CREATE INDEX IX_swim_rad_gufi ON dbo.swim_rad_amendments (gufi);
CREATE INDEX IX_swim_rad_status ON dbo.swim_rad_amendments (status)
    WHERE status NOT IN ('ACPT','RJCT','EXPR');

-- Feature gating column on swim_api_keys
-- NULL = all features allowed. JSON array = restricted to listed features.
ALTER TABLE dbo.swim_api_keys ADD allowed_features NVARCHAR(MAX) NULL;
```

- [ ] **Step 4: Create MySQL filter presets table**

This will be executed inline in `api/rad/filters.php` on first use, but here's the DDL for reference (run against `perti_site`):

```sql
CREATE TABLE IF NOT EXISTS rad_filter_presets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_cid INT NULL COMMENT 'NULL = global preset',
    name VARCHAR(100) NOT NULL,
    filters_json JSON NOT NULL,
    created_utc DATETIME DEFAULT UTC_TIMESTAMP(),
    updated_utc DATETIME DEFAULT UTC_TIMESTAMP() ON UPDATE UTC_TIMESTAMP()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

- [ ] **Step 5: Run ADL GUFI migration on VATSIM_ADL**

```bash
# Connect as jpeterson (admin) since adl_api_user lacks ALTER TABLE
sqlcmd -S vatsim.database.windows.net -d VATSIM_ADL -U jpeterson -P Jhp21012 \
  -i adl/migrations/core/014_adl_gufi_column.sql
```

Verify: `SELECT TOP 5 flight_uid, gufi FROM dbo.adl_flight_core;` — each row should have a unique UUID.

- [ ] **Step 6: Run TMI RAD tables migration on VATSIM_TMI**

```bash
sqlcmd -S vatsim.database.windows.net -d VATSIM_TMI -U jpeterson -P Jhp21012 \
  -i database/migrations/tmi/057_rad_tables.sql
```

Then run the ADL ALTER TABLE separately on VATSIM_ADL:
```sql
ALTER TABLE dbo.adl_flight_tmi ADD rad_amendment_id INT NULL;
ALTER TABLE dbo.adl_flight_tmi ADD rad_assigned_route VARCHAR(MAX) NULL;
```

Verify: `SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = 'rad_amendments';`

- [ ] **Step 7: Run SWIM mirror migration on SWIM_API**

```bash
sqlcmd -S vatsim.database.windows.net -d SWIM_API -U jpeterson -P Jhp21012 \
  -i database/migrations/swim/036_swim_rad_mirror.sql
```

Verify: `SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_NAME LIKE 'swim_rad%';`

- [ ] **Step 8: Create MySQL filter presets table on perti_site**

```bash
mysql -h vatcscc-perti.mysql.database.azure.com -u perti_admin -p perti_site \
  -e "CREATE TABLE IF NOT EXISTS rad_filter_presets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_cid INT NULL,
    name VARCHAR(100) NOT NULL,
    filters_json JSON NOT NULL,
    created_utc DATETIME DEFAULT UTC_TIMESTAMP(),
    updated_utc DATETIME DEFAULT UTC_TIMESTAMP() ON UPDATE UTC_TIMESTAMP()
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;"
```

- [ ] **Step 9: Commit migrations**

```bash
git add adl/migrations/core/014_adl_gufi_column.sql \
        database/migrations/tmi/057_rad_tables.sql \
        database/migrations/swim/036_swim_rad_mirror.sql
git commit -m "feat(rad): database migrations — GUFI column, RAD tables, SWIM mirror"
```

---

## Task 2: RAD Service Layer

**Files:**
- Create: `load/services/RADService.php`

- [ ] **Step 1: Create RADService.php**

Create `load/services/RADService.php` with all service methods. This is the shared backend used by both internal and SWIM API endpoints.

```php
<?php
/**
 * RAD Service — Route Amendment Dialogue Business Logic
 *
 * Shared service layer used by both internal (api/rad/) and SWIM (api/swim/v1/rad/)
 * endpoints. All database queries, amendment lifecycle, and compliance logic live here.
 *
 * @package PERTI
 * @subpackage RAD
 * @version 1.0.0
 */

class RADService
{
    private $conn_adl;
    private $conn_tmi;
    private $conn_gis;

    public function __construct($conn_adl, $conn_tmi, $conn_gis = null)
    {
        $this->conn_adl = $conn_adl;
        $this->conn_tmi = $conn_tmi;
        $this->conn_gis = $conn_gis;
    }

    // =========================================================================
    // FLIGHT SEARCH
    // =========================================================================

    /**
     * Search ADL flights with filters.
     *
     * @param array $filters Keys: cs, orig, dest, orig_tracon, orig_center,
     *   dest_tracon, dest_center, type, carrier, time_field, time_start,
     *   time_end, route, status, page, limit
     * @return array ['flights' => [...], 'total' => int]
     */
    public function searchFlights(array $filters): array
    {
        $where = ["1=1"];
        $params = [];

        // Callsign search (LIKE)
        if (!empty($filters['cs'])) {
            $where[] = "c.callsign LIKE ?";
            $params[] = '%' . str_replace(['%','_'], ['[%]','[_]'], $filters['cs']) . '%';
        }

        // Origin/destination airport
        if (!empty($filters['orig'])) {
            $where[] = "p.fp_dept_icao = ?";
            $params[] = strtoupper($filters['orig']);
        }
        if (!empty($filters['dest'])) {
            $where[] = "p.fp_dest_icao = ?";
            $params[] = strtoupper($filters['dest']);
        }

        // TRACON filters (origin/dest zone from adl_flight_core)
        if (!empty($filters['orig_tracon'])) {
            $where[] = "c.dept_tracon = ?";
            $params[] = strtoupper($filters['orig_tracon']);
        }
        if (!empty($filters['dest_tracon'])) {
            $where[] = "c.dest_tracon = ?";
            $params[] = strtoupper($filters['dest_tracon']);
        }

        // Center filters
        if (!empty($filters['orig_center'])) {
            $where[] = "c.dept_artcc = ?";
            $params[] = strtoupper($filters['orig_center']);
        }
        if (!empty($filters['dest_center'])) {
            $where[] = "c.dest_artcc = ?";
            $params[] = strtoupper($filters['dest_center']);
        }

        // Aircraft type
        if (!empty($filters['type'])) {
            $where[] = "a.fp_aircraft_icao = ?";
            $params[] = strtoupper($filters['type']);
        }

        // Carrier
        if (!empty($filters['carrier'])) {
            $where[] = "a.airline_icao = ?";
            $params[] = strtoupper($filters['carrier']);
        }

        // Time range filter
        $time_field = $filters['time_field'] ?? 'etd';
        $time_col_map = [
            'etd' => 't.etd_utc', 'ctd' => 't.ctd_utc', 'atd' => 't.atd_utc',
            'eta' => 't.eta_utc', 'cta' => 't.cta_utc', 'ata' => 't.ata_utc',
        ];
        $time_col = $time_col_map[$time_field] ?? 't.etd_utc';
        if (!empty($filters['time_start'])) {
            $where[] = "$time_col >= ?";
            $params[] = $filters['time_start'];
        }
        if (!empty($filters['time_end'])) {
            $where[] = "$time_col <= ?";
            $params[] = $filters['time_end'];
        }

        // Route string element search
        if (!empty($filters['route'])) {
            $where[] = "p.route LIKE ?";
            $params[] = '%' . str_replace(['%','_'], ['[%]','[_]'], $filters['route']) . '%';
        }

        // Flight status
        if (!empty($filters['status'])) {
            $where[] = "c.flight_phase = ?";
            $params[] = strtoupper($filters['status']);
        }

        // Default: active + prefiled
        if (empty($filters['status'])) {
            $where[] = "c.is_active = 1";
        }

        $where_sql = implode(' AND ', $where);
        $page = max(1, (int)($filters['page'] ?? 1));
        $limit = min(200, max(1, (int)($filters['limit'] ?? 50)));
        $offset = ($page - 1) * $limit;

        // Count query
        $count_sql = "SELECT COUNT(*) as total
            FROM dbo.adl_flight_core c
            JOIN dbo.adl_flight_plan p ON c.flight_uid = p.flight_uid
            JOIN dbo.adl_flight_times t ON c.flight_uid = t.flight_uid
            JOIN dbo.adl_flight_aircraft a ON c.flight_uid = a.flight_uid
            WHERE $where_sql";
        $count_stmt = sqlsrv_query($this->conn_adl, $count_sql, $params);
        $total = 0;
        if ($count_stmt) {
            $row = sqlsrv_fetch_array($count_stmt, SQLSRV_FETCH_ASSOC);
            $total = $row['total'] ?? 0;
            sqlsrv_free_stmt($count_stmt);
        }

        // Main query
        $sql = "SELECT
                c.flight_uid, c.gufi, c.callsign, c.flight_phase,
                c.dept_artcc, c.dest_artcc, c.dept_tracon, c.dest_tracon,
                p.fp_dept_icao, p.fp_dest_icao, p.route,
                a.fp_aircraft_icao, a.airline_icao,
                t.etd_utc, t.eta_utc, t.ctd_utc, t.cta_utc,
                t.atd_utc, t.ata_utc, t.ete_min, t.cte_min,
                tmi.rad_amendment_id, tmi.rad_assigned_route
            FROM dbo.adl_flight_core c
            JOIN dbo.adl_flight_plan p ON c.flight_uid = p.flight_uid
            JOIN dbo.adl_flight_times t ON c.flight_uid = t.flight_uid
            JOIN dbo.adl_flight_aircraft a ON c.flight_uid = a.flight_uid
            LEFT JOIN dbo.adl_flight_tmi tmi ON c.flight_uid = tmi.flight_uid
            WHERE $where_sql
            ORDER BY t.etd_utc ASC
            OFFSET ? ROWS FETCH NEXT ? ROWS ONLY";
        $params[] = $offset;
        $params[] = $limit;

        $stmt = sqlsrv_query($this->conn_adl, $sql, $params);
        $flights = [];
        if ($stmt) {
            while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
                foreach ($row as $k => $v) {
                    if ($v instanceof DateTimeInterface) {
                        $row[$k] = $v->format('Y-m-d\TH:i:s') . 'Z';
                    }
                }
                $flights[] = $row;
            }
            sqlsrv_free_stmt($stmt);
        }

        return ['flights' => $flights, 'total' => $total];
    }

    // =========================================================================
    // AMENDMENT LIFECYCLE
    // =========================================================================

    /**
     * Create a route amendment.
     *
     * @param string $gufi Flight GUFI (UUID)
     * @param string $assigned_route New route string
     * @param array $options Keys: delivery_channels, tmi_reroute_id, tmi_id_label,
     *   route_color, notes, send (bool), created_by
     * @return array ['id' => int, 'status' => string] or ['error' => string]
     */
    public function createAmendment(string $gufi, string $assigned_route, array $options = []): array
    {
        // Look up flight by GUFI
        $flight = $this->getFlightByGufi($gufi);
        if (!$flight) {
            return ['error' => 'Flight not found for GUFI'];
        }

        $status = !empty($options['send']) ? 'SENT' : 'DRAFT';
        $channels = $options['delivery_channels'] ?? 'CPDLC,SWIM';

        $sql = "INSERT INTO dbo.rad_amendments
            (gufi, callsign, origin, destination, original_route, assigned_route,
             status, tmi_reroute_id, tmi_id_label, delivery_channels, route_color,
             created_by, notes, expires_utc)
            OUTPUT INSERTED.id
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
                    DATEADD(HOUR, 6, SYSUTCDATETIME()))";

        $params = [
            $gufi,
            $flight['callsign'],
            $flight['fp_dept_icao'],
            $flight['fp_dest_icao'],
            $flight['route'],
            $assigned_route,
            $status,
            $options['tmi_reroute_id'] ?? null,
            $options['tmi_id_label'] ?? null,
            $channels,
            $options['route_color'] ?? null,
            $options['created_by'] ?? null,
            $options['notes'] ?? null,
        ];

        $stmt = sqlsrv_query($this->conn_tmi, $sql, $params);
        if ($stmt === false) {
            $errors = sqlsrv_errors();
            return ['error' => 'Insert failed: ' . ($errors[0]['message'] ?? 'Unknown')];
        }
        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        $id = $row['id'] ?? null;
        sqlsrv_free_stmt($stmt);

        if (!$id) {
            return ['error' => 'Failed to get inserted ID'];
        }

        // Log creation
        $this->logTransition($id, null, $status, 'Amendment created', $options['created_by'] ?? null);

        // If send=true, trigger delivery
        if ($status === 'SENT') {
            $this->triggerDelivery($id, $flight, $assigned_route, $channels, $options);
        }

        // Update adl_flight_tmi
        $this->updateAdlFlightTmi($flight['flight_uid'], $id, $assigned_route);

        return ['id' => $id, 'status' => $status];
    }

    /**
     * Send a DRAFT amendment.
     */
    public function sendAmendment(int $id, ?int $user_cid = null): array
    {
        $amendment = $this->getAmendment($id);
        if (!$amendment) return ['error' => 'Amendment not found'];
        if ($amendment['status'] !== 'DRAFT') return ['error' => 'Only DRAFT amendments can be sent'];

        $sql = "UPDATE dbo.rad_amendments SET status = 'SENT', sent_utc = SYSUTCDATETIME() WHERE id = ?";
        $stmt = sqlsrv_query($this->conn_tmi, $sql, [$id]);
        if ($stmt === false) return ['error' => 'Update failed'];
        sqlsrv_free_stmt($stmt);

        $this->logTransition($id, 'DRAFT', 'SENT', 'Sent by operator', $user_cid);

        $flight = $this->getFlightByGufi($amendment['gufi']);
        if ($flight) {
            $this->triggerDelivery($id, $flight, $amendment['assigned_route'],
                $amendment['delivery_channels'], ['created_by' => $user_cid]);
        }

        return ['success' => true, 'status' => 'SENT'];
    }

    /**
     * Resend an already-sent amendment.
     */
    public function resendAmendment(int $id, ?int $user_cid = null): array
    {
        $amendment = $this->getAmendment($id);
        if (!$amendment) return ['error' => 'Amendment not found'];
        if (!in_array($amendment['status'], ['SENT', 'DLVD'])) {
            return ['error' => 'Only SENT/DLVD amendments can be resent'];
        }

        $this->logTransition($id, $amendment['status'], $amendment['status'], 'Resent by operator', $user_cid);

        $flight = $this->getFlightByGufi($amendment['gufi']);
        if ($flight) {
            $this->triggerDelivery($id, $flight, $amendment['assigned_route'],
                $amendment['delivery_channels'], ['created_by' => $user_cid]);
        }

        return ['success' => true];
    }

    /**
     * Cancel a DRAFT or SENT amendment (deletes it).
     */
    public function cancelAmendment(int $id, ?int $user_cid = null): array
    {
        $amendment = $this->getAmendment($id);
        if (!$amendment) return ['error' => 'Amendment not found'];
        if (!in_array($amendment['status'], ['DRAFT', 'SENT'])) {
            return ['error' => 'Only DRAFT/SENT amendments can be cancelled'];
        }

        // Clear adl_flight_tmi reference
        $this->clearAdlFlightTmi($amendment['gufi']);

        // Delete (CASCADE deletes log entries)
        $sql = "DELETE FROM dbo.rad_amendments WHERE id = ?";
        $stmt = sqlsrv_query($this->conn_tmi, $sql, [$id]);
        if ($stmt === false) return ['error' => 'Delete failed'];
        sqlsrv_free_stmt($stmt);

        return ['success' => true];
    }

    /**
     * Get amendments with optional filters.
     */
    public function getAmendments(array $filters = []): array
    {
        $where = ["1=1"];
        $params = [];

        if (!empty($filters['gufi'])) {
            $where[] = "gufi = ?";
            $params[] = $filters['gufi'];
        }
        if (!empty($filters['status'])) {
            $statuses = array_map('trim', explode(',', strtoupper($filters['status'])));
            $ph = implode(',', array_fill(0, count($statuses), '?'));
            $where[] = "status IN ($ph)";
            $params = array_merge($params, $statuses);
        }
        if (!empty($filters['tmi_reroute_id'])) {
            $where[] = "tmi_reroute_id = ?";
            $params[] = (int)$filters['tmi_reroute_id'];
        }

        $where_sql = implode(' AND ', $where);
        $page = max(1, (int)($filters['page'] ?? 1));
        $limit = min(200, max(1, (int)($filters['limit'] ?? 50)));
        $offset = ($page - 1) * $limit;

        $sql = "SELECT * FROM dbo.rad_amendments WHERE $where_sql
                ORDER BY created_utc DESC OFFSET ? ROWS FETCH NEXT ? ROWS ONLY";
        $params[] = $offset;
        $params[] = $limit;

        $stmt = sqlsrv_query($this->conn_tmi, $sql, $params);
        $rows = [];
        if ($stmt) {
            while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
                foreach ($row as $k => $v) {
                    if ($v instanceof DateTimeInterface) $row[$k] = $v->format('Y-m-d\TH:i:s') . 'Z';
                }
                $rows[] = $row;
            }
            sqlsrv_free_stmt($stmt);
        }
        return $rows;
    }

    // =========================================================================
    // COMPLIANCE
    // =========================================================================

    /**
     * Get compliance status for amendments.
     *
     * @param array $filters Keys: amendment_ids (comma-sep), tmi_reroute_id
     * @return array Per-amendment compliance + aggregate
     */
    public function getCompliance(array $filters): array
    {
        $where = ["status IN ('SENT','DLVD')"];
        $params = [];

        if (!empty($filters['amendment_ids'])) {
            $ids = array_map('intval', explode(',', $filters['amendment_ids']));
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $where[] = "id IN ($ph)";
            $params = array_merge($params, $ids);
        }
        if (!empty($filters['tmi_reroute_id'])) {
            $where[] = "tmi_reroute_id = ?";
            $params[] = (int)$filters['tmi_reroute_id'];
        }

        $where_sql = implode(' AND ', $where);
        $sql = "SELECT id, gufi, callsign, status, rrstat, assigned_route,
                       tmi_reroute_id, tmi_id_label, sent_utc
                FROM dbo.rad_amendments WHERE $where_sql ORDER BY sent_utc DESC";

        $stmt = sqlsrv_query($this->conn_tmi, $sql, $params);
        $items = [];
        $agg = ['C' => 0, 'NC' => 0, 'NC_OK' => 0, 'UNKN' => 0, 'OK' => 0, 'EXC' => 0];
        if ($stmt) {
            while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
                foreach ($row as $k => $v) {
                    if ($v instanceof DateTimeInterface) $row[$k] = $v->format('Y-m-d\TH:i:s') . 'Z';
                }

                // Look up current filed route from ADL
                $flight = $this->getFlightByGufi($row['gufi']);
                $row['filed_route'] = $flight ? ($flight['route'] ?? '') : '';
                $row['flight_phase'] = $flight ? ($flight['flight_phase'] ?? '') : '';

                $items[] = $row;
                $rs = $row['rrstat'] ?? 'UNKN';
                if (isset($agg[$rs])) $agg[$rs]++;
            }
            sqlsrv_free_stmt($stmt);
        }

        $total = array_sum($agg);
        $rate = $total > 0 ? round(($agg['C'] + $agg['OK'] + $agg['EXC']) / $total * 100, 1) : 0;

        return [
            'amendments' => $items,
            'aggregate' => $agg,
            'compliance_rate' => $rate,
            'total' => $total,
        ];
    }

    /**
     * Run compliance check for all active amendments (called by ADL daemon).
     * Compares filed route against assigned route, updates rrstat and status.
     */
    public function runComplianceCheck(): array
    {
        $sql = "SELECT id, gufi, assigned_route, status FROM dbo.rad_amendments
                WHERE status IN ('SENT', 'DLVD')";
        $stmt = sqlsrv_query($this->conn_tmi, $sql);
        if (!$stmt) return ['error' => 'Query failed'];

        $checked = 0;
        $transitioned = 0;

        while ($amend = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $flight = $this->getFlightByGufi($amend['gufi']);
            if (!$flight) continue;

            $checked++;
            $new_rrstat = $this->computeRrstat($flight['route'] ?? '', $amend['assigned_route']);

            // Check for status transitions
            $new_status = $amend['status'];
            if ($new_rrstat === 'C' && in_array($amend['status'], ['SENT', 'DLVD'])) {
                $new_status = 'ACPT';
                $transitioned++;
            } elseif ($flight['flight_phase'] === 'ACTIVE' && !empty($flight['atd_utc'])) {
                // Flight departed — check if route matches
                if ($new_rrstat !== 'C') {
                    $new_status = 'EXPR';
                    $transitioned++;
                }
            }

            // Update if changed
            if ($new_rrstat !== $amend['rrstat'] || $new_status !== $amend['status']) {
                $upd_sql = "UPDATE dbo.rad_amendments SET rrstat = ?, status = ?";
                $upd_params = [$new_rrstat, $new_status];
                if ($new_status !== $amend['status'] && in_array($new_status, ['ACPT', 'EXPR'])) {
                    $upd_sql .= ", resolved_utc = SYSUTCDATETIME()";
                }
                $upd_sql .= " WHERE id = ?";
                $upd_params[] = $amend['id'];
                $upd_stmt = sqlsrv_query($this->conn_tmi, $upd_sql, $upd_params);
                if ($upd_stmt) sqlsrv_free_stmt($upd_stmt);

                if ($new_status !== $amend['status']) {
                    $detail = $new_status === 'ACPT'
                        ? 'Pilot filed matching route'
                        : 'Flight departed without amendment';
                    $this->logTransition($amend['id'], $amend['status'], $new_status, $detail, null);

                    // Broadcast status change on SWIM WebSocket
                    $this->broadcastWebSocket('rad:amendment_update', [
                        'amendment_id' => $amend['id'],
                        'gufi' => $amend['gufi'],
                        'status' => $new_status,
                        'rrstat' => $new_rrstat,
                    ]);
                    $this->broadcastWebSocket('rad:compliance_update', [
                        'amendment_id' => $amend['id'],
                        'rrstat' => $new_rrstat,
                    ]);
                }
            }
        }
        sqlsrv_free_stmt($stmt);

        return ['checked' => $checked, 'transitioned' => $transitioned];
    }

    // =========================================================================
    // ROUTE OPTIONS & HISTORY
    // =========================================================================

    /**
     * Get route options for a flight (TMI reroutes, CDR matches).
     */
    public function getRouteOptions(string $gufi): array
    {
        $flight = $this->getFlightByGufi($gufi);
        if (!$flight) return ['error' => 'Flight not found'];

        $options = ['tmi_routes' => [], 'tos_options' => []];

        // TMI reroute routes matching this flight's city pair
        $sql = "SELECT r.reroute_id, r.reroute_name, r.advisory_number,
                       rr.route_string, rr.route_id
                FROM dbo.tmi_reroutes r
                JOIN dbo.tmi_reroute_routes rr ON r.reroute_id = rr.reroute_id
                WHERE r.status = 'ACTIVE'
                  AND (r.ctl_element = ? OR r.ctl_element = ?)";
        $stmt = sqlsrv_query($this->conn_tmi, $sql,
            [$flight['fp_dept_icao'], $flight['fp_dest_icao']]);
        if ($stmt) {
            while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
                $options['tmi_routes'][] = $row;
            }
            sqlsrv_free_stmt($stmt);
        }

        return $options;
    }

    /**
     * Get recently sent routes for a city pair.
     */
    public function getRecentRoutes(string $origin, string $destination): array
    {
        $sql = "SELECT DISTINCT TOP 20 assigned_route, tmi_id_label, created_utc
                FROM dbo.rad_amendments
                WHERE origin = ? AND destination = ? AND status != 'DRAFT'
                ORDER BY created_utc DESC";
        $stmt = sqlsrv_query($this->conn_tmi, $sql,
            [strtoupper($origin), strtoupper($destination)]);
        $routes = [];
        if ($stmt) {
            while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
                foreach ($row as $k => $v) {
                    if ($v instanceof DateTimeInterface) $row[$k] = $v->format('Y-m-d\TH:i:s') . 'Z';
                }
                $routes[] = $row;
            }
            sqlsrv_free_stmt($stmt);
        }
        return $routes;
    }

    /**
     * Get route change history for a flight from adl_flight_changelog.
     */
    public function getRouteHistory(string $gufi): array
    {
        // Get flight_uid from gufi
        $flight = $this->getFlightByGufi($gufi);
        if (!$flight) return [];

        $sql = "SELECT changed_utc, field_name, old_value, new_value
                FROM dbo.adl_flight_changelog
                WHERE flight_uid = ? AND field_name = 'route'
                ORDER BY changed_utc DESC";
        $stmt = sqlsrv_query($this->conn_adl, $sql, [$flight['flight_uid']]);
        $history = [];
        if ($stmt) {
            while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
                foreach ($row as $k => $v) {
                    if ($v instanceof DateTimeInterface) $row[$k] = $v->format('Y-m-d\TH:i:s') . 'Z';
                }
                $history[] = $row;
            }
            sqlsrv_free_stmt($stmt);
        }
        return $history;
    }

    /**
     * Validate a route string via PostGIS expand_route().
     */
    public function validateRoute(string $routeString): array
    {
        if (!$this->conn_gis) {
            return ['valid' => true, 'warning' => 'PostGIS unavailable, skipping validation'];
        }

        try {
            $stmt = $this->conn_gis->prepare("SELECT * FROM expand_route(?)");
            $stmt->execute([$routeString]);
            $waypoints = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            return ['valid' => count($waypoints) > 0, 'waypoints' => count($waypoints)];
        } catch (\Exception $e) {
            return ['valid' => false, 'error' => $e->getMessage()];
        }
    }

    // =========================================================================
    // INTERNAL HELPERS
    // =========================================================================

    private function getAmendment(int $id): ?array
    {
        $stmt = sqlsrv_query($this->conn_tmi,
            "SELECT * FROM dbo.rad_amendments WHERE id = ?", [$id]);
        if (!$stmt) return null;
        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($stmt);
        if (!$row) return null;
        foreach ($row as $k => $v) {
            if ($v instanceof DateTimeInterface) $row[$k] = $v->format('Y-m-d\TH:i:s') . 'Z';
        }
        return $row;
    }

    private function getFlightByGufi(string $gufi): ?array
    {
        $sql = "SELECT c.flight_uid, c.gufi, c.callsign, c.flight_phase,
                       c.dept_artcc, c.dest_artcc, c.dept_tracon, c.dest_tracon,
                       p.fp_dept_icao, p.fp_dest_icao, p.route,
                       t.etd_utc, t.eta_utc, t.ctd_utc, t.cta_utc, t.atd_utc, t.ata_utc
                FROM dbo.adl_flight_core c
                JOIN dbo.adl_flight_plan p ON c.flight_uid = p.flight_uid
                JOIN dbo.adl_flight_times t ON c.flight_uid = t.flight_uid
                WHERE c.gufi = ?";
        $stmt = sqlsrv_query($this->conn_adl, $sql, [$gufi]);
        if (!$stmt) return null;
        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($stmt);
        if (!$row) return null;
        foreach ($row as $k => $v) {
            if ($v instanceof DateTimeInterface) $row[$k] = $v->format('Y-m-d\TH:i:s') . 'Z';
        }
        return $row;
    }

    private function logTransition(int $amendment_id, ?string $from, string $to, string $detail, ?int $user_cid): void
    {
        $sql = "INSERT INTO dbo.rad_amendment_log (amendment_id, status_from, status_to, detail, changed_by)
                VALUES (?, ?, ?, ?, ?)";
        $stmt = sqlsrv_query($this->conn_tmi, $sql, [$amendment_id, $from, $to, $detail, $user_cid]);
        if ($stmt) sqlsrv_free_stmt($stmt);
    }

    /**
     * Compute RRSTAT by comparing filed route against assigned route.
     * Simple string match for V1 — future versions can use PostGIS geometry comparison.
     */
    private function computeRrstat(string $filedRoute, string $assignedRoute): string
    {
        if (empty($filedRoute) || empty($assignedRoute)) return 'UNKN';

        // Normalize: uppercase, collapse whitespace, trim
        $filed = strtoupper(preg_replace('/\s+/', ' ', trim($filedRoute)));
        $assigned = strtoupper(preg_replace('/\s+/', ' ', trim($assignedRoute)));

        if ($filed === $assigned) return 'C';

        // Check if assigned route is contained within filed route (partial match)
        if (strpos($filed, $assigned) !== false) return 'C';

        return 'NC';
    }

    private function triggerDelivery(int $amendment_id, array $flight, string $route, string $channels, array $options): void
    {
        // Build CPDLC message
        $tmi_label = $options['tmi_id_label'] ?? 'ATC';
        $message = "ROUTE AMENDMENT: {$flight['callsign']} CLEARED $route PER $tmi_label";

        // Use EDCTDelivery pattern for multi-channel delivery
        // Channel: CPDLC via Hoppie
        $cpdlc_ok = false;
        if (strpos($channels, 'CPDLC') !== false) {
            $cpdlc_ok = $this->deliverViaCPDLC($flight['callsign'], $message);
            if ($cpdlc_ok) {
                // Hoppie accepted — transition to DLVD, store message ID
                $upd = "UPDATE dbo.rad_amendments SET status = 'DLVD', delivered_utc = SYSUTCDATETIME()
                        WHERE id = ? AND status = 'SENT'";
                $upd_stmt = sqlsrv_query($this->conn_tmi, $upd, [$amendment_id]);
                if ($upd_stmt) sqlsrv_free_stmt($upd_stmt);
                $this->logTransition($amendment_id, 'SENT', 'DLVD', 'CPDLC delivery confirmed', null);
            }
        }

        // Channel: WebSocket broadcast
        if (strpos($channels, 'SWIM') !== false) {
            $this->broadcastWebSocket('rad:amendment_update', [
                'amendment_id' => $amendment_id,
                'gufi' => $flight['gufi'],
                'callsign' => $flight['callsign'],
                'status' => 'SENT',
                'assigned_route' => $route,
            ]);
        }
    }

    private function deliverViaCPDLC(string $callsign, string $message): bool
    {
        // Use Hoppie ACARS API (same pattern as EDCTDelivery)
        if (!defined('HOPPIE_LOGON_CODE') || !HOPPIE_LOGON_CODE) return false;

        $data = [
            'logon' => HOPPIE_LOGON_CODE,
            'from' => defined('HOPPIE_STATION') ? HOPPIE_STATION : 'VATCSCC',
            'to' => $callsign,
            'type' => 'cpdlc',
            'packet' => '/data2/' . strlen($message) . '//' . $message,
        ];

        $ch = curl_init('https://www.hoppie.nl/acars/system/connect.html');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($data),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
        ]);
        $response = curl_exec($ch);
        curl_close($ch);

        $ok = $response !== false && strpos($response, 'ok') !== false;

        // If Hoppie accepted, transition SENT→DLVD and store message tracking
        // Hoppie API is synchronous — "ok" means message was delivered to the network
        if ($ok) {
            // Extract message ID from response if available
            // Hoppie returns "ok {id}" on success
            $parts = explode(' ', trim($response));
            $msg_id = isset($parts[1]) ? $parts[1] : null;

            // Note: caller should update rad_amendments.cpdlc_message_id and
            // transition to DLVD after all channels are attempted
        }

        return $ok;
    }

    private function broadcastWebSocket(string $event, array $data): void
    {
        $payload = json_encode(['event' => $event, 'data' => $data]);
        $file = sys_get_temp_dir() . '/swim_ws_events.json';
        @file_put_contents($file, $payload . "\n", FILE_APPEND | LOCK_EX);
    }

    private function updateAdlFlightTmi(int $flight_uid, int $amendment_id, string $route): void
    {
        $sql = "UPDATE dbo.adl_flight_tmi SET rad_amendment_id = ?, rad_assigned_route = ?
                WHERE flight_uid = ?";
        $stmt = sqlsrv_query($this->conn_adl, $sql, [$amendment_id, $route, $flight_uid]);
        if ($stmt) sqlsrv_free_stmt($stmt);
    }

    private function clearAdlFlightTmi(string $gufi): void
    {
        $flight = $this->getFlightByGufi($gufi);
        if (!$flight) return;
        $sql = "UPDATE dbo.adl_flight_tmi SET rad_amendment_id = NULL, rad_assigned_route = NULL
                WHERE flight_uid = ?";
        $stmt = sqlsrv_query($this->conn_adl, $sql, [$flight['flight_uid']]);
        if ($stmt) sqlsrv_free_stmt($stmt);
    }
}
```

- [ ] **Step 2: Verify RADService.php is syntactically correct**

```bash
php -l load/services/RADService.php
```

Expected: `No syntax errors detected`

- [ ] **Step 3: Commit**

```bash
git add load/services/RADService.php
git commit -m "feat(rad): RADService shared service layer"
```

---

## Task 3: Internal API Endpoints

**Files:**
- Create: `api/rad/common.php`
- Create: `api/rad/search.php`
- Create: `api/rad/amendment.php`
- Create: `api/rad/compliance.php`
- Create: `api/rad/routes.php`
- Create: `api/rad/history.php`
- Create: `api/rad/filters.php`

- [ ] **Step 1: Create api/rad/common.php**

Follows `api/gdt/common.php` pattern — session auth + DB connections + RADService init.

```php
<?php
/**
 * RAD API Common Utilities
 *
 * Shared auth, DB connections, and helpers for Route Amendment Dialogue endpoints.
 * Follows api/gdt/common.php pattern.
 */

if (!defined('RAD_API_INCLUDED')) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'message' => 'Direct access not allowed']);
    exit;
}

// Session BEFORE config/connect (connect.php closing ?> sends headers)
require_once(__DIR__ . '/../../sessions/handler.php');

if (!defined('PERTI_LOADED')) {
    define('PERTI_LOADED', true);
}
require_once(__DIR__ . '/../../load/config.php');
require_once(__DIR__ . '/../../load/connect.php');
require_once(__DIR__ . '/../../load/services/RADService.php');

function rad_respond_json($code, $payload) {
    if (!isset($payload['timestamp'])) $payload['timestamp'] = gmdate('c');
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function rad_read_payload() {
    $raw = file_get_contents('php://input');
    if ($raw !== false && strlen(trim($raw)) > 0) {
        $decoded = json_decode($raw, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) return $decoded;
    }
    return array_merge($_GET ?? [], $_POST ?? []);
}

function rad_require_auth() {
    if (!isset($_SESSION['VATSIM_CID']) || empty($_SESSION['VATSIM_CID'])) {
        rad_respond_json(401, ['status' => 'error', 'message' => 'Authentication required']);
    }
    return $_SESSION['VATSIM_CID'];
}

function rad_get_service() {
    global $conn_adl, $conn_tmi, $conn_gis;
    if (!$conn_adl) rad_respond_json(500, ['status' => 'error', 'message' => 'ADL connection unavailable']);
    if (!$conn_tmi) rad_respond_json(500, ['status' => 'error', 'message' => 'TMI connection unavailable']);
    return new RADService($conn_adl, $conn_tmi, $conn_gis);
}

/**
 * Require TMU-level permission for amendment write operations.
 * Checks admin_users table in MySQL.
 */
function rad_require_tmu($cid) {
    global $conn_sqli;
    $check = $conn_sqli->query("SELECT 1 FROM admin_users WHERE cid='$cid' AND role IN ('tmu','atcscc','admin') LIMIT 1");
    if (!$check || $check->num_rows === 0) {
        rad_respond_json(403, ['status' => 'error', 'message' => 'TMU-level permission required for amendments']);
    }
}
```

- [ ] **Step 2: Create api/rad/search.php**

```php
<?php
/** RAD API: Flight Search — GET /api/rad/search.php */
define('RAD_API_INCLUDED', true);
require_once __DIR__ . '/common.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    rad_respond_json(405, ['status' => 'error', 'message' => 'GET only']);
}

rad_require_auth();
$svc = rad_get_service();

$filters = [
    'cs'           => $_GET['cs'] ?? null,
    'orig'         => $_GET['orig'] ?? null,
    'dest'         => $_GET['dest'] ?? null,
    'orig_tracon'  => $_GET['orig_tracon'] ?? null,
    'orig_center'  => $_GET['orig_center'] ?? null,
    'dest_tracon'  => $_GET['dest_tracon'] ?? null,
    'dest_center'  => $_GET['dest_center'] ?? null,
    'type'         => $_GET['type'] ?? null,
    'carrier'      => $_GET['carrier'] ?? null,
    'time_field'   => $_GET['time_field'] ?? 'etd',
    'time_start'   => $_GET['time_start'] ?? null,
    'time_end'     => $_GET['time_end'] ?? null,
    'route'        => $_GET['route'] ?? null,
    'status'       => $_GET['status'] ?? null,
    'page'         => $_GET['page'] ?? 1,
    'limit'        => $_GET['limit'] ?? 50,
];

$result = $svc->searchFlights($filters);
rad_respond_json(200, ['status' => 'ok', 'data' => $result['flights'], 'total' => $result['total']]);
```

- [ ] **Step 3: Create api/rad/amendment.php**

```php
<?php
/** RAD API: Amendment CRUD — GET/POST /api/rad/amendment.php */
define('RAD_API_INCLUDED', true);
require_once __DIR__ . '/common.php';

$cid = rad_require_auth();
$svc = rad_get_service();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? null;

// POST operations require TMU-level permission
if ($method === 'POST') {
    rad_require_tmu($cid);
}

if ($method === 'GET') {
    $filters = [
        'gufi'           => $_GET['gufi'] ?? null,
        'status'         => $_GET['status'] ?? null,
        'tmi_reroute_id' => $_GET['tmi_reroute_id'] ?? null,
        'page'           => $_GET['page'] ?? 1,
        'limit'          => $_GET['limit'] ?? 50,
    ];
    $result = $svc->getAmendments($filters);
    rad_respond_json(200, ['status' => 'ok', 'data' => $result]);

} elseif ($method === 'POST') {
    $body = rad_read_payload();

    if ($action === 'send') {
        $id = (int)($body['id'] ?? 0);
        if (!$id) rad_respond_json(400, ['status' => 'error', 'message' => 'id required']);
        $result = $svc->sendAmendment($id, (int)$cid);
        if (isset($result['error'])) rad_respond_json(400, ['status' => 'error', 'message' => $result['error']]);
        rad_respond_json(200, ['status' => 'ok', 'data' => $result]);

    } elseif ($action === 'resend') {
        $id = (int)($body['id'] ?? 0);
        if (!$id) rad_respond_json(400, ['status' => 'error', 'message' => 'id required']);
        $result = $svc->resendAmendment($id, (int)$cid);
        if (isset($result['error'])) rad_respond_json(400, ['status' => 'error', 'message' => $result['error']]);
        rad_respond_json(200, ['status' => 'ok', 'data' => $result]);

    } elseif ($action === 'cancel') {
        $id = (int)($body['id'] ?? 0);
        if (!$id) rad_respond_json(400, ['status' => 'error', 'message' => 'id required']);
        $result = $svc->cancelAmendment($id, (int)$cid);
        if (isset($result['error'])) rad_respond_json(400, ['status' => 'error', 'message' => $result['error']]);
        rad_respond_json(200, ['status' => 'ok', 'data' => $result]);

    } else {
        // Create new amendment
        $gufi = $body['gufi'] ?? null;
        $route = $body['assigned_route'] ?? null;
        if (!$gufi || !$route) {
            rad_respond_json(400, ['status' => 'error', 'message' => 'gufi and assigned_route required']);
        }
        $options = [
            'delivery_channels' => $body['delivery_channels'] ?? 'CPDLC,SWIM',
            'tmi_reroute_id'    => $body['tmi_reroute_id'] ?? null,
            'tmi_id_label'      => $body['tmi_id_label'] ?? null,
            'route_color'       => $body['route_color'] ?? null,
            'notes'             => $body['notes'] ?? null,
            'send'              => !empty($body['send']),
            'created_by'        => (int)$cid,
        ];
        $result = $svc->createAmendment($gufi, $route, $options);
        if (isset($result['error'])) rad_respond_json(400, ['status' => 'error', 'message' => $result['error']]);
        rad_respond_json(201, ['status' => 'ok', 'data' => $result]);
    }

} else {
    rad_respond_json(405, ['status' => 'error', 'message' => 'Method not allowed']);
}
```

- [ ] **Step 4: Create api/rad/compliance.php**

```php
<?php
/** RAD API: Compliance Polling — GET /api/rad/compliance.php */
define('RAD_API_INCLUDED', true);
require_once __DIR__ . '/common.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    rad_respond_json(405, ['status' => 'error', 'message' => 'GET only']);
}

rad_require_auth();
$svc = rad_get_service();

$filters = [
    'amendment_ids'  => $_GET['amendment_ids'] ?? null,
    'tmi_reroute_id' => $_GET['tmi_reroute_id'] ?? null,
];

$result = $svc->getCompliance($filters);
rad_respond_json(200, ['status' => 'ok', 'data' => $result]);
```

- [ ] **Step 5: Create api/rad/routes.php**

```php
<?php
/** RAD API: Route Options & Recently Sent — GET /api/rad/routes.php */
define('RAD_API_INCLUDED', true);
require_once __DIR__ . '/common.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    rad_respond_json(405, ['status' => 'error', 'message' => 'GET only']);
}

rad_require_auth();
$svc = rad_get_service();
$source = $_GET['source'] ?? 'options';

if ($source === 'recent') {
    $origin = $_GET['origin'] ?? null;
    $dest = $_GET['destination'] ?? null;
    if (!$origin || !$dest) {
        rad_respond_json(400, ['status' => 'error', 'message' => 'origin and destination required']);
    }
    $result = $svc->getRecentRoutes($origin, $dest);
    rad_respond_json(200, ['status' => 'ok', 'data' => $result]);

} elseif ($source === 'options') {
    $gufi = $_GET['gufi'] ?? null;
    if (!$gufi) rad_respond_json(400, ['status' => 'error', 'message' => 'gufi required']);
    $result = $svc->getRouteOptions($gufi);
    if (isset($result['error'])) rad_respond_json(404, ['status' => 'error', 'message' => $result['error']]);
    rad_respond_json(200, ['status' => 'ok', 'data' => $result]);

} else {
    rad_respond_json(400, ['status' => 'error', 'message' => 'Invalid source param']);
}
```

- [ ] **Step 6: Create api/rad/history.php**

```php
<?php
/** RAD API: Route Change History — GET /api/rad/history.php */
define('RAD_API_INCLUDED', true);
require_once __DIR__ . '/common.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    rad_respond_json(405, ['status' => 'error', 'message' => 'GET only']);
}

rad_require_auth();
$svc = rad_get_service();

$gufi = $_GET['gufi'] ?? null;
if (!$gufi) rad_respond_json(400, ['status' => 'error', 'message' => 'gufi required']);

$result = $svc->getRouteHistory($gufi);
rad_respond_json(200, ['status' => 'ok', 'data' => $result]);
```

- [ ] **Step 7: Create api/rad/filters.php**

```php
<?php
/** RAD API: Filter Preset CRUD — GET/POST/DELETE /api/rad/filters.php */
define('RAD_API_INCLUDED', true);
require_once __DIR__ . '/common.php';

$cid = rad_require_auth();
global $conn_pdo;
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $stmt = $conn_pdo->prepare(
        "SELECT * FROM rad_filter_presets WHERE user_cid IS NULL OR user_cid = ? ORDER BY name");
    $stmt->execute([(int)$cid]);
    $presets = $stmt->fetchAll(\PDO::FETCH_ASSOC);
    rad_respond_json(200, ['status' => 'ok', 'data' => $presets]);

} elseif ($method === 'POST') {
    $body = rad_read_payload();
    $name = $body['name'] ?? null;
    $filters_json = $body['filters_json'] ?? null;
    $is_global = !empty($body['global']);
    if (!$name || !$filters_json) {
        rad_respond_json(400, ['status' => 'error', 'message' => 'name and filters_json required']);
    }
    $stmt = $conn_pdo->prepare(
        "INSERT INTO rad_filter_presets (user_cid, name, filters_json) VALUES (?, ?, ?)");
    $stmt->execute([$is_global ? null : (int)$cid, $name, json_encode($filters_json)]);
    rad_respond_json(201, ['status' => 'ok', 'id' => $conn_pdo->lastInsertId()]);

} elseif ($method === 'DELETE') {
    $id = $_GET['id'] ?? null;
    if (!$id) rad_respond_json(400, ['status' => 'error', 'message' => 'id required']);
    $stmt = $conn_pdo->prepare("DELETE FROM rad_filter_presets WHERE id = ? AND (user_cid = ? OR user_cid IS NULL)");
    $stmt->execute([(int)$id, (int)$cid]);
    rad_respond_json(200, ['status' => 'ok']);

} else {
    rad_respond_json(405, ['status' => 'error', 'message' => 'Method not allowed']);
}
```

- [ ] **Step 8: Verify all API files parse correctly**

```bash
for f in api/rad/common.php api/rad/search.php api/rad/amendment.php \
         api/rad/compliance.php api/rad/routes.php api/rad/history.php api/rad/filters.php; do
    php -l "$f"
done
```

Expected: All files report `No syntax errors detected`

- [ ] **Step 9: Commit**

```bash
git add api/rad/
git commit -m "feat(rad): internal API endpoints — search, amendment, compliance, routes, history, filters"
```

---

## Task 4: SWIM API Endpoints

**Files:**
- Create: `api/swim/v1/rad/flights.php`
- Create: `api/swim/v1/rad/amendments.php`
- Create: `api/swim/v1/rad/compliance.php`
- Create: `api/swim/v1/rad/routes.php`
- Create: `api/swim/v1/rad/history.php`

All SWIM endpoints follow the pattern in `api/swim/v1/tmi/event-log.php`: `require_once auth.php`, `swim_init_auth()`, `SwimResponse::json()`. They reuse `RADService.php`.

- [ ] **Step 1: Create api/swim/v1/rad/flights.php**

```php
<?php
/**
 * VATSWIM API v1 - RAD Flight Search
 * GET /api/swim/v1/rad/flights
 */
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../../../../load/services/RADService.php';

global $conn_swim;
if (!$conn_swim) SwimResponse::error('SWIM database unavailable', 503, 'SERVICE_UNAVAILABLE');

$auth = swim_init_auth(true, false);

// RAD feature gate check
swim_check_feature_access($auth, 'rad');

// Need ADL connection for flight search
global $conn_adl, $conn_tmi, $conn_gis;
if (!$conn_adl) SwimResponse::error('ADL database unavailable', 503, 'SERVICE_UNAVAILABLE');

$svc = new RADService($conn_adl, $conn_tmi, $conn_gis);

$filters = [
    'cs'           => swim_get_param('cs'),
    'orig'         => swim_get_param('orig'),
    'dest'         => swim_get_param('dest'),
    'orig_tracon'  => swim_get_param('orig_tracon'),
    'orig_center'  => swim_get_param('orig_center'),
    'dest_tracon'  => swim_get_param('dest_tracon'),
    'dest_center'  => swim_get_param('dest_center'),
    'type'         => swim_get_param('type'),
    'carrier'      => swim_get_param('carrier'),
    'time_field'   => swim_get_param('time_field', 'etd'),
    'time_start'   => swim_get_param('time_start'),
    'time_end'     => swim_get_param('time_end'),
    'route'        => swim_get_param('route'),
    'status'       => swim_get_param('status'),
    'page'         => swim_get_int_param('page', 1, 1, 1000),
    'limit'        => swim_get_int_param('per_page', 50, 1, 200),
];

$result = $svc->searchFlights($filters);

SwimResponse::json([
    'success' => true,
    'data' => $result['flights'],
    'pagination' => [
        'total' => $result['total'],
        'page' => (int)$filters['page'],
        'per_page' => (int)$filters['limit'],
    ],
    'timestamp' => gmdate('c'),
]);
```

- [ ] **Step 2: Create api/swim/v1/rad/amendments.php**

```php
<?php
/**
 * VATSWIM API v1 - RAD Amendments
 * GET  /api/swim/v1/rad/amendments — list amendments
 * POST /api/swim/v1/rad/amendments — create/send/cancel
 */
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../../../../load/services/RADService.php';

global $conn_swim, $conn_adl, $conn_tmi, $conn_gis;
if (!$conn_swim) SwimResponse::error('SWIM database unavailable', 503, 'SERVICE_UNAVAILABLE');

$method = $_SERVER['REQUEST_METHOD'];
$require_write = ($method === 'POST');
$auth = swim_init_auth(true, $require_write);
swim_check_feature_access($auth, 'rad');

if (!$conn_tmi) SwimResponse::error('TMI database unavailable', 503, 'SERVICE_UNAVAILABLE');

$svc = new RADService($conn_adl, $conn_tmi, $conn_gis);

if ($method === 'GET') {
    $filters = [
        'gufi'           => swim_get_param('gufi'),
        'status'         => swim_get_param('status'),
        'tmi_reroute_id' => swim_get_param('tmi_reroute_id'),
        'page'           => swim_get_int_param('page', 1, 1, 1000),
        'limit'          => swim_get_int_param('per_page', 50, 1, 200),
    ];
    $result = $svc->getAmendments($filters);
    SwimResponse::json(['success' => true, 'data' => $result, 'timestamp' => gmdate('c')]);

} elseif ($method === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    $action = swim_get_param('action');

    if ($action === 'send') {
        $id = (int)($body['id'] ?? 0);
        if (!$id) SwimResponse::error('id required', 400, 'BAD_REQUEST');
        $result = $svc->sendAmendment($id);
        if (isset($result['error'])) SwimResponse::error($result['error'], 400, 'BAD_REQUEST');
        SwimResponse::json(['success' => true, 'data' => $result]);

    } elseif ($action === 'cancel') {
        $id = (int)($body['id'] ?? 0);
        if (!$id) SwimResponse::error('id required', 400, 'BAD_REQUEST');
        $result = $svc->cancelAmendment($id);
        if (isset($result['error'])) SwimResponse::error($result['error'], 400, 'BAD_REQUEST');
        SwimResponse::json(['success' => true, 'data' => $result]);

    } else {
        $gufi = $body['gufi'] ?? null;
        $route = $body['assigned_route'] ?? null;
        if (!$gufi || !$route) SwimResponse::error('gufi and assigned_route required', 400, 'BAD_REQUEST');
        $options = [
            'delivery_channels' => $body['delivery_channels'] ?? 'CPDLC,SWIM',
            'tmi_reroute_id'    => $body['tmi_reroute_id'] ?? null,
            'tmi_id_label'      => $body['tmi_id_label'] ?? null,
            'route_color'       => $body['route_color'] ?? null,
            'notes'             => $body['notes'] ?? null,
            'send'              => !empty($body['send']),
        ];
        $result = $svc->createAmendment($gufi, $route, $options);
        if (isset($result['error'])) SwimResponse::error($result['error'], 400, 'BAD_REQUEST');
        SwimResponse::json(['success' => true, 'data' => $result], 201);
    }
} else {
    SwimResponse::error('Method not allowed', 405, 'METHOD_NOT_ALLOWED');
}
```

- [ ] **Step 3: Create api/swim/v1/rad/compliance.php**

```php
<?php
/** VATSWIM API v1 - RAD Compliance — GET */
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../../../../load/services/RADService.php';

global $conn_swim, $conn_adl, $conn_tmi, $conn_gis;
if (!$conn_swim) SwimResponse::error('SWIM database unavailable', 503, 'SERVICE_UNAVAILABLE');

$auth = swim_init_auth(true, false);
swim_check_feature_access($auth, 'rad');

if (!$conn_tmi) SwimResponse::error('TMI database unavailable', 503, 'SERVICE_UNAVAILABLE');

$svc = new RADService($conn_adl, $conn_tmi, $conn_gis);

$filters = [
    'amendment_ids'  => swim_get_param('amendment_ids'),
    'tmi_reroute_id' => swim_get_param('tmi_reroute_id'),
];

$result = $svc->getCompliance($filters);
SwimResponse::json(['success' => true, 'data' => $result, 'timestamp' => gmdate('c')]);
```

- [ ] **Step 4: Create api/swim/v1/rad/routes.php and history.php**

`api/swim/v1/rad/routes.php`:
```php
<?php
/** VATSWIM API v1 - RAD Route Options — GET */
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../../../../load/services/RADService.php';

global $conn_swim, $conn_adl, $conn_tmi, $conn_gis;
if (!$conn_swim) SwimResponse::error('SWIM database unavailable', 503, 'SERVICE_UNAVAILABLE');
$auth = swim_init_auth(true, false);
swim_check_feature_access($auth, 'rad');
if (!$conn_tmi) SwimResponse::error('TMI database unavailable', 503, 'SERVICE_UNAVAILABLE');
$svc = new RADService($conn_adl, $conn_tmi, $conn_gis);

$source = swim_get_param('source', 'options');

if ($source === 'recent') {
    $origin = swim_get_param('origin');
    $dest = swim_get_param('destination');
    if (!$origin || !$dest) SwimResponse::error('origin and destination required', 400, 'BAD_REQUEST');
    SwimResponse::json(['success' => true, 'data' => $svc->getRecentRoutes($origin, $dest)]);
} else {
    $gufi = swim_get_param('gufi');
    if (!$gufi) SwimResponse::error('gufi required', 400, 'BAD_REQUEST');
    $result = $svc->getRouteOptions($gufi);
    if (isset($result['error'])) SwimResponse::error($result['error'], 404, 'NOT_FOUND');
    SwimResponse::json(['success' => true, 'data' => $result]);
}
```

`api/swim/v1/rad/history.php`:
```php
<?php
/** VATSWIM API v1 - RAD Route History — GET */
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../../../../load/services/RADService.php';

global $conn_swim, $conn_adl, $conn_tmi, $conn_gis;
if (!$conn_swim) SwimResponse::error('SWIM database unavailable', 503, 'SERVICE_UNAVAILABLE');
$auth = swim_init_auth(true, false);
swim_check_feature_access($auth, 'rad');
if (!$conn_adl) SwimResponse::error('ADL database unavailable', 503, 'SERVICE_UNAVAILABLE');
$svc = new RADService($conn_adl, $conn_tmi, $conn_gis);

$gufi = swim_get_param('gufi');
if (!$gufi) SwimResponse::error('gufi required', 400, 'BAD_REQUEST');

SwimResponse::json(['success' => true, 'data' => $svc->getRouteHistory($gufi), 'timestamp' => gmdate('c')]);
```

- [ ] **Step 5: Add swim_check_feature_access helper to auth.php**

Add to `api/swim/v1/auth.php` (after `swim_get_int_param`):

```php
/**
 * Check if API key has access to a specific feature.
 * Keys with NULL allowed_features have access to everything.
 */
function swim_check_feature_access($auth, string $feature): void {
    if (!$auth) return; // No auth required
    $allowed = $auth->getAllowedFeatures();
    if ($allowed === null) return; // NULL = all features
    if (!in_array($feature, $allowed)) {
        SwimResponse::error("API key does not have access to '$feature' feature", 403, 'FORBIDDEN');
    }
}
```

And add `getAllowedFeatures()` method to `SwimAuth` class:

```php
public function getAllowedFeatures(): ?array {
    if (!$this->key_data) return null;
    $features = $this->key_data['allowed_features'] ?? null;
    if ($features === null) return null;
    $decoded = json_decode($features, true);
    return is_array($decoded) ? $decoded : null;
}
```

- [ ] **Step 6: Commit**

```bash
git add api/swim/v1/rad/ api/swim/v1/auth.php
git commit -m "feat(rad): VATSWIM API endpoints — flights, amendments, compliance, routes, history"
```

---

## Task 5: PHP Page + CSS

**Files:**
- Create: `rad.php`
- Create: `assets/css/rad.css`
- Modify: `load/nav.php` (add RAD nav link)

- [ ] **Step 1: Create rad.php**

Follows `route.php` pattern: session → config → connect (NO `PERTI_MYSQL_ONLY`) → header → MapLibre → nav → map → tabs → footer → JS includes.

```php
<?php
include("sessions/handler.php");
if (session_status() == PHP_SESSION_NONE) {
    session_start();
    ob_start();
}

include("load/config.php");
// NO PERTI_MYSQL_ONLY — needs $conn_adl, $conn_tmi, $conn_gis
include("load/connect.php");
include("load/i18n.php");

// Check Perms — require authenticated session
$perm = false;
$cid = null;
if (!defined('DEV')) {
    if (isset($_SESSION['VATSIM_CID'])) {
        $cid = session_get('VATSIM_CID', '');
        $p_check = $conn_sqli->query("SELECT * FROM users WHERE cid='$cid'");
        if ($p_check) {
            $perm = true;
        }
    }
} else {
    $perm = true;
    $_SESSION['VATSIM_FIRST_NAME'] = $_SESSION['VATSIM_LAST_NAME'] = $_SESSION['VATSIM_CID'] = 0;
    $cid = 0;
}

if (!$perm) {
    header('Location: /login/');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php
        $page_title = __('rad.pageTitle');
        include("load/header.php");
    ?>
    <script>window.PERTI_USE_MAPLIBRE = true;</script>
    <link href="https://unpkg.com/maplibre-gl@4.5.0/dist/maplibre-gl.css" rel="stylesheet" />
    <script src="https://unpkg.com/maplibre-gl@4.5.0/dist/maplibre-gl.js"></script>
    <script src="https://unpkg.com/@turf/turf@6/turf.min.js"></script>
    <link rel="stylesheet" href="assets/css/route-analysis.css<?= _v('assets/css/route-analysis.css') ?>">
    <link rel="stylesheet" href="assets/css/rad.css<?= _v('assets/css/rad.css') ?>">
</head>
<body>
<?php include("load/nav.php"); ?>

<!-- ===================== MAP REGION (TOP) ===================== -->
<div class="rad-map-section" id="rad_map_section">
    <div class="rad-map-controls">
        <textarea id="routeSearch" class="rad-route-input"
            placeholder="<?= __('route.page.plotPlaceholder') ?>" rows="2"></textarea>
        <button id="plot_r" class="btn btn-sm btn-primary ml-2"><?= __('route.page.plotButton') ?></button>
    </div>
    <div id="map_wrapper" class="rad-map-wrapper">
        <div id="placeholder"></div>
        <div id="graphic"></div>
    </div>
</div>

<!-- ===================== TAB REGION (BOTTOM) ===================== -->
<div class="container-fluid rad-tabs-container mt-2">
    <ul class="nav nav-tabs" id="radTabs" role="tablist">
        <li class="nav-item">
            <a class="nav-link active" id="tab-search" data-toggle="tab" href="#pane-search" role="tab">
                <i class="fas fa-search mr-1"></i><?= __('rad.tabs.search') ?>
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link" id="tab-detail" data-toggle="tab" href="#pane-detail" role="tab">
                <i class="fas fa-plane mr-1"></i><?= __('rad.tabs.detail') ?>
                <span class="badge badge-secondary ml-1" id="rad_detail_badge" style="display:none;">0</span>
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link" id="tab-edit" data-toggle="tab" href="#pane-edit" role="tab">
                <i class="fas fa-edit mr-1"></i><?= __('rad.tabs.edit') ?>
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link" id="tab-monitoring" data-toggle="tab" href="#pane-monitoring" role="tab">
                <i class="fas fa-eye mr-1"></i><?= __('rad.tabs.monitoring') ?>
                <span class="badge badge-warning ml-1" id="rad_monitoring_badge" style="display:none;">0</span>
            </a>
        </li>
    </ul>

    <div class="tab-content" id="radTabContent">
        <!-- Flight Search Tab -->
        <div class="tab-pane fade show active" id="pane-search" role="tabpanel">
            <div class="rad-search-bar mt-2 mb-2">
                <input type="text" id="rad_cs_search" class="form-control form-control-sm d-inline-block"
                    style="width:200px;" placeholder="<?= __('rad.search.placeholder') ?>">
                <button class="btn btn-sm btn-outline-secondary ml-2" id="rad_filter_toggle">
                    <i class="fas fa-filter"></i> <?= __('rad.search.filter') ?>
                </button>
                <button class="btn btn-sm btn-outline-info ml-1" id="rad_save_filter">
                    <i class="fas fa-save"></i> <?= __('rad.search.save') ?>
                </button>
                <select class="form-control form-control-sm d-inline-block ml-2" id="rad_filter_presets" style="width:180px;">
                    <option value=""><?= __('rad.search.presetDefault') ?></option>
                </select>
            </div>
            <div id="rad_filter_panel" style="display:none;" class="rad-filter-panel mb-2"></div>
            <div class="table-responsive">
                <table class="table table-sm table-striped table-hover rad-table" id="rad_search_table">
                    <thead><tr>
                        <th style="width:30px;"><input type="checkbox" id="rad_search_select_all"></th>
                        <th><?= __('rad.search.callsign') ?></th>
                        <th><?= __('rad.search.origDest') ?></th>
                        <th><?= __('rad.search.type') ?></th>
                        <th><?= __('rad.search.times') ?></th>
                        <th><?= __('rad.search.status') ?></th>
                    </tr></thead>
                    <tbody id="rad_search_tbody"></tbody>
                </table>
            </div>
            <div class="rad-search-actions mt-2">
                <button class="btn btn-sm btn-primary" id="rad_add_to_detail">
                    <i class="fas fa-plus mr-1"></i><?= __('rad.search.addToDetail') ?>
                </button>
                <span class="ml-2 text-muted" id="rad_search_count"></span>
            </div>
        </div>

        <!-- Flight Detail Tab -->
        <div class="tab-pane fade" id="pane-detail" role="tabpanel">
            <div class="rad-detail-actions mt-2 mb-2">
                <button class="btn btn-sm btn-outline-secondary" id="rad_detail_select_all"><?= __('rad.detail.selectAll') ?></button>
                <button class="btn btn-sm btn-outline-secondary ml-1" id="rad_detail_select_none"><?= __('rad.detail.selectNone') ?></button>
                <button class="btn btn-sm btn-outline-danger ml-1" id="rad_detail_remove"><?= __('rad.detail.removeSelected') ?></button>
            </div>
            <div class="table-responsive">
                <table class="table table-sm table-striped table-hover rad-table" id="rad_detail_table">
                    <thead><tr>
                        <th style="width:30px;"><input type="checkbox" id="rad_detail_cb_all"></th>
                        <th><?= __('rad.search.callsign') ?></th>
                        <th><?= __('rad.search.origDest') ?></th>
                        <th>TRACON</th>
                        <th>Center</th>
                        <th>Amendment</th>
                        <th>Route</th>
                        <th><?= __('rad.search.type') ?></th>
                        <th><?= __('rad.search.times') ?></th>
                        <th><?= __('rad.search.status') ?></th>
                    </tr></thead>
                    <tbody id="rad_detail_tbody"></tbody>
                </table>
            </div>
        </div>

        <!-- Route Edit Tab -->
        <div class="tab-pane fade" id="pane-edit" role="tabpanel">
            <div class="row mt-2">
                <div class="col-md-5 rad-edit-left">
                    <h6><?= __('rad.edit.retrieveRoutes') ?></h6>
                    <button class="btn btn-sm btn-outline-primary mb-1" id="rad_recently_sent" disabled>
                        <i class="fas fa-history mr-1"></i><?= __('rad.edit.recentlySent') ?>
                    </button>
                    <button class="btn btn-sm btn-outline-primary mb-1 ml-1" id="rad_search_db">
                        <i class="fas fa-database mr-1"></i><?= __('rad.edit.searchDb') ?>
                    </button>
                    <div class="input-group input-group-sm mb-2 mt-2">
                        <input type="text" class="form-control" id="rad_cdr_code" placeholder="Route Code">
                        <div class="input-group-append">
                            <button class="btn btn-outline-secondary" id="rad_get_cdr">Get CDR</button>
                        </div>
                    </div>
                    <textarea id="rad_manual_route" class="form-control form-control-sm mb-2" rows="2"
                        placeholder="<?= __('rad.edit.manualPlaceholder') ?>"></textarea>
                    <div class="d-flex align-items-center mb-2">
                        <button class="btn btn-sm btn-outline-success mr-2" id="rad_validate_route">Validate</button>
                        <button class="btn btn-sm btn-outline-info mr-2" id="rad_plot_route">Plot on Map</button>
                        <label class="mb-0 mr-1">Color:</label>
                        <input type="color" id="rad_route_color" value="#FF6600" style="width:30px;height:24px;">
                    </div>
                    <button class="btn btn-sm btn-outline-warning" id="rad_route_options">
                        <i class="fas fa-list mr-1"></i>Route Options
                    </button>
                </div>

                <div class="col-md-7 rad-edit-right">
                    <h6>Current Routes</h6>
                    <div id="rad_current_routes" class="rad-current-routes mb-3"></div>

                    <h6><?= __('rad.edit.createAmendment') ?></h6>
                    <div id="rad_amendment_preview" class="rad-amendment-preview mb-2"></div>
                    <div class="rad-delivery-options mb-2">
                        <label class="mr-3"><input type="checkbox" id="rad_ch_cpdlc" checked> CPDLC</label>
                        <label class="mr-3"><input type="checkbox" id="rad_ch_swim" checked> SWIM broadcast</label>
                        <label><input type="checkbox" id="rad_ch_discord"> Discord notify</label>
                    </div>
                    <div class="mb-2">
                        <label>TMI Association:</label>
                        <select class="form-control form-control-sm d-inline-block" id="rad_tmi_assoc" style="width:200px;">
                            <option value="">None (ad-hoc)</option>
                        </select>
                    </div>
                    <div>
                        <button class="btn btn-sm btn-secondary mr-2" id="rad_save_draft">
                            <i class="fas fa-save mr-1"></i>Save Draft
                        </button>
                        <button class="btn btn-sm btn-success" id="rad_send_amendment">
                            <i class="fas fa-paper-plane mr-1"></i>Send Amendment
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Monitoring Tab -->
        <div class="tab-pane fade" id="pane-monitoring" role="tabpanel">
            <div class="rad-summary-cards mt-2 mb-2 d-flex flex-wrap" id="rad_summary_cards"></div>
            <div class="rad-monitor-filters mb-2">
                <div class="btn-group btn-group-sm" id="rad_monitor_filter_group">
                    <button class="btn btn-outline-secondary active" data-filter="all">All</button>
                    <button class="btn btn-outline-secondary" data-filter="pending">Pending</button>
                    <button class="btn btn-outline-secondary" data-filter="nc">Non-Compliant</button>
                    <button class="btn btn-outline-secondary" data-filter="alerts">Alerts</button>
                </div>
                <select class="form-control form-control-sm d-inline-block ml-2" id="rad_tmi_filter" style="width:180px;">
                    <option value="">All TMIs</option>
                </select>
                <span class="ml-2 text-muted" id="rad_refresh_status"></span>
            </div>
            <div class="table-responsive">
                <table class="table table-sm table-striped table-hover rad-table" id="rad_monitor_table">
                    <thead><tr>
                        <th>Callsign</th><th>O/D</th><th>Amdt Status</th><th>RRSTAT</th>
                        <th>TMI ID</th><th>Assigned Route</th><th>Filed Route</th>
                        <th>Sent At</th><th>Status</th><th>Actions</th>
                    </tr></thead>
                    <tbody id="rad_monitor_tbody"></tbody>
                </table>
            </div>
            <div class="rad-aggregate-bar mt-2" id="rad_aggregate_bar"></div>
        </div>
    </div>
</div>

<?php include('load/footer.php'); ?>

<!-- Route processing JS (same as route.php and playbook.php) -->
<script src="assets/js/config/phase-colors.js<?= _v('assets/js/config/phase-colors.js') ?>"></script>
<script src="assets/js/config/filter-colors.js<?= _v('assets/js/config/filter-colors.js') ?>"></script>
<script src="assets/js/awys.js<?= _v('assets/js/awys.js') ?>"></script>
<script src="assets/js/procs_enhanced.js<?= _v('assets/js/procs_enhanced.js') ?>"></script>
<script src="assets/js/route-symbology.js<?= _v('assets/js/route-symbology.js') ?>"></script>
<script src="assets/js/playbook-cdr-search.js<?= _v('assets/js/playbook-cdr-search.js') ?>"></script>
<script src="assets/js/lib/artcc-hierarchy.js<?= _v('assets/js/lib/artcc-hierarchy.js') ?>"></script>
<script src="assets/js/route-maplibre.js<?= _v('assets/js/route-maplibre.js') ?>"></script>
<script src="assets/js/route-analysis-panel.js<?= _v('assets/js/route-analysis-panel.js') ?>"></script>
<script src="assets/js/adl-service.js<?= _v('assets/js/adl-service.js') ?>"></script>

<!-- RAD modules -->
<script src="assets/js/rad-event-bus.js<?= _v('assets/js/rad-event-bus.js') ?>"></script>
<script src="assets/js/rad-flight-search.js<?= _v('assets/js/rad-flight-search.js') ?>"></script>
<script src="assets/js/rad-flight-detail.js<?= _v('assets/js/rad-flight-detail.js') ?>"></script>
<script src="assets/js/rad-amendment.js<?= _v('assets/js/rad-amendment.js') ?>"></script>
<script src="assets/js/rad-monitoring.js<?= _v('assets/js/rad-monitoring.js') ?>"></script>
<script src="assets/js/rad.js<?= _v('assets/js/rad.js') ?>"></script>

</body>
</html>
```

- [ ] **Step 2: Create assets/css/rad.css**

See separate file (Task 5 Step 2 body). Core styles: map section (50vh), tab container, dual sub-row table, status badges, amendment preview, summary cards, alert rows.

```css
/* RAD - Route Amendment Dialogue */

.rad-map-section {
    position: relative;
    height: 50vh;
    min-height: 350px;
}
.rad-map-wrapper {
    width: 100%;
    height: 100%;
}
.rad-map-controls {
    position: absolute;
    top: 10px;
    left: 10px;
    z-index: 10;
    display: flex;
    align-items: flex-start;
}
.rad-route-input {
    width: 320px;
    font-family: Inconsolata, monospace;
    font-size: 0.85rem;
    background: rgba(255,255,255,0.95);
    border: 1px solid #ccc;
    border-radius: 4px;
    padding: 4px 8px;
    resize: vertical;
}

/* Tabs */
.rad-tabs-container { padding: 0 12px; }
.rad-table { font-size: 0.82rem; }
.rad-table td, .rad-table th { vertical-align: middle; padding: 4px 6px; }

/* Dual sub-row: top + bottom in same cell */
.rad-table .sub-top { display: block; font-weight: 500; }
.rad-table .sub-bot { display: block; font-size: 0.78rem; color: #6c757d; }

/* Filter panel */
.rad-filter-panel {
    background: #f8f9fa;
    border: 1px solid #dee2e6;
    border-radius: 4px;
    padding: 8px 12px;
}

/* Status badges */
.rad-badge { font-size: 0.72rem; padding: 2px 6px; border-radius: 3px; font-weight: 600; }
.rad-badge-DRAFT { background: #6c757d; color: #fff; }
.rad-badge-SENT { background: #007bff; color: #fff; }
.rad-badge-DLVD { background: #17a2b8; color: #fff; }
.rad-badge-ACPT { background: #28a745; color: #fff; }
.rad-badge-RJCT { background: #dc3545; color: #fff; }
.rad-badge-EXPR { background: #ffc107; color: #212529; }

/* RRSTAT badges */
.rad-rrstat-C { color: #28a745; font-weight: 600; }
.rad-rrstat-NC { color: #dc3545; font-weight: 600; }
.rad-rrstat-NC_OK { color: #fd7e14; }
.rad-rrstat-UNKN { color: #6c757d; }
.rad-rrstat-OK { color: #17a2b8; }
.rad-rrstat-EXC { color: #6f42c1; }

/* Route Edit columns */
.rad-edit-left { border-right: 1px solid #dee2e6; }
.rad-current-routes { max-height: 200px; overflow-y: auto; }
.rad-amendment-preview {
    background: #f8f9fa;
    border: 1px solid #dee2e6;
    border-radius: 4px;
    padding: 8px;
    font-family: Inconsolata, monospace;
    font-size: 0.85rem;
    min-height: 60px;
}

/* Monitoring summary cards */
.rad-summary-cards .rad-card {
    padding: 8px 16px;
    border-radius: 4px;
    text-align: center;
    margin-right: 8px;
    margin-bottom: 8px;
    min-width: 80px;
    background: #f8f9fa;
    border: 1px solid #dee2e6;
}
.rad-card-count { font-size: 1.4rem; font-weight: 700; }
.rad-card-label { font-size: 0.72rem; text-transform: uppercase; color: #6c757d; }

/* Alert row (EXPR flights) */
.rad-alert-row { background-color: #f8d7da !important; }

/* Aggregate compliance bar */
.rad-aggregate-bar {
    display: flex;
    height: 24px;
    border-radius: 4px;
    overflow: hidden;
}
.rad-agg-segment { height: 100%; display: flex; align-items: center; justify-content: center;
    font-size: 0.7rem; color: #fff; font-weight: 600; }
.rad-agg-C { background: #28a745; }
.rad-agg-NC { background: #dc3545; }
.rad-agg-UNKN { background: #6c757d; }
.rad-agg-EXC { background: #6f42c1; }
```

- [ ] **Step 3: Add RAD link to nav.php**

Find the nav links section in `load/nav.php` and add RAD alongside existing operational pages.

- [ ] **Step 4: Verify rad.php parses**

```bash
php -l rad.php
```

- [ ] **Step 5: Commit**

```bash
git add rad.php assets/css/rad.css load/nav.php
git commit -m "feat(rad): page shell with map, 4 tabs, CSS, nav link"
```

---

## Task 6: JavaScript Modules

**Files:**
- Create: `assets/js/rad-event-bus.js`
- Create: `assets/js/rad-flight-search.js`
- Create: `assets/js/rad-flight-detail.js`
- Create: `assets/js/rad-amendment.js`
- Create: `assets/js/rad-monitoring.js`
- Create: `assets/js/rad.js`

Due to the length of these files, each JS module is described with its full API, event subscriptions, and key implementation. The implementing agent should create each file following these specifications exactly.

- [ ] **Step 1: Create rad-event-bus.js**

```javascript
/**
 * RAD Event Bus — Simple pub/sub for inter-module communication.
 * Usage: RADEventBus.on('event', callback); RADEventBus.emit('event', data);
 */
window.RADEventBus = (function() {
    var listeners = {};
    return {
        on: function(event, fn) {
            if (!listeners[event]) listeners[event] = [];
            listeners[event].push(fn);
        },
        off: function(event, fn) {
            if (!listeners[event]) return;
            listeners[event] = listeners[event].filter(function(f) { return f !== fn; });
        },
        emit: function(event, data) {
            if (!listeners[event]) return;
            listeners[event].forEach(function(fn) {
                try { fn(data); } catch(e) { console.error('RADEventBus error on ' + event, e); }
            });
        }
    };
})();
```

- [ ] **Step 2: Create rad-flight-search.js**

Module handles: filter panel, callsign search, ADL API calls, results table with dual sub-rows, multi-select, sort-by-column, "Add to Detail" button.

Key API: `window.RADFlightSearch.init()`, `.refresh()`, `.getSelected()`.

Events emitted: `flight:selected`, `flight:deselected`.
Events consumed: `map:flight-clicked`.

The module calls `$.get('api/rad/search.php', filters)` and renders dual sub-row table rows. Each row has a checkbox. "Add to Detail" emits `flight:selected` for each checked flight with full flight data payload. Filter state stored in `sessionStorage` key `RAD_ACTIVE_FILTERS`. Filter presets loaded from `api/rad/filters.php`.

Full implementation: ~300 LOC. Key functions:
- `buildFilterPanel()` — renders filter inputs (origin, dest, type, carrier, time range, route)
- `executeSearch()` — calls API, renders tbody
- `renderRow(flight)` — dual sub-row with checkbox
- `handleSort(col)` — cycle asc/desc/none
- `addToDetail()` — emits events for checked flights

- [ ] **Step 3: Create rad-flight-detail.js**

Module handles: selected flights table (expanded columns), select/deselect, remove, route history dialog.

Key API: `window.RADFlightDetail.init()`, `.addFlight(data)`, `.getSelected()`, `.getFlights()`.

Events emitted: `flight:deselected`, `flight:highlighted`.
Events consumed: `flight:selected`, `amendment:updated`.

The detail table shows all columns from spec section 4.2. "Route History" button calls `$.get('api/rad/history.php', {gufi})` and shows a SweetAlert2 dialog with diff view. Badge count updates on `#rad_detail_badge`.

Full implementation: ~250 LOC.

- [ ] **Step 4: Create rad-amendment.js**

Module handles: Route Edit tab — retrieve routes (recently sent, search DB, CDR lookup, manual entry), route validation, plot on map, route options dialog, create amendment, send amendment.

Key API: `window.RADAmendment.init()`, `.setRoute(routeString)`.

Events emitted: `route:plot`, `route:clear`, `amendment:created`, `amendment:sent`.
Events consumed: `flight:selected`, `flight:deselected`.

Key interactions:
- "Recently Sent" button: `$.get('api/rad/routes.php?source=recent', {origin, destination})` → SweetAlert2 dialog
- "Search DB" button: calls `window.PlaybookCDRSearch.open(callback)` (reuses existing module)
- "Get CDR" button: calls `$.get('api/rad/routes.php?source=cdr', {code})`
- "Validate" button: calls PostGIS expand_route via `window.MapLibreRoute.processRoutes()`
- "Plot on Map" button: emits `route:plot` with route string and color
- "Route Options" button: `$.get('api/rad/routes.php?source=options', {gufi})` → SweetAlert2 dialog with TMI/TOS/ERAM/Adapted sub-sections
- "Save Draft": `$.post('api/rad/amendment.php', {gufi, assigned_route, ...})`
- "Send Amendment": `$.post('api/rad/amendment.php', {gufi, assigned_route, send: true, ...})`

Route diff view: compare original vs assigned route, highlight added/removed segments with red/green spans.

Full implementation: ~400 LOC.

- [ ] **Step 5: Create rad-monitoring.js**

Module handles: Monitoring tab — summary cards, filter bar, amendment tracking table, compliance polling, alert toasts, aggregate compliance bar.

Key API: `window.RADMonitoring.init()`, `.startPolling()`, `.stopPolling()`.

Events emitted: `amendment:updated`.
Events consumed: `amendment:created`, `amendment:sent`.

Polls `api/rad/compliance.php` every 30 seconds. Renders summary cards (Total, DRAFT, SENT, DLVD, ACPT, RJCT, EXPR). Table rows use status and RRSTAT badge classes from rad.css. EXPR rows get `rad-alert-row` class. New EXPR transitions trigger `PERTIDialog.warning()` toast.

Actions column: Resend (for SENT/DLVD), Send (for DRAFT), Delete (for DRAFT/SENT).

Aggregate compliance bar: renders proportional colored segments for C/NC/UNKN/EXC with percentage labels.

Full implementation: ~350 LOC.

- [ ] **Step 6: Create rad.js (controller)**

Main controller module. Initializes all sub-modules, coordinates map↔tab interactions.

```javascript
/**
 * RAD Controller — Tab management, map↔tab coordination, init.
 */
window.RADController = (function() {
    var mapInitialized = false;

    function init() {
        // Initialize event bus consumers for map
        RADEventBus.on('route:plot', function(data) {
            if (window.MapLibreRoute) {
                window.MapLibreRoute.processRoutes(data.routeString, {
                    color: data.color || '#FF6600',
                    id: data.id || 'rad-route-' + Date.now()
                });
            }
        });

        RADEventBus.on('route:clear', function(data) {
            // Clear specific route layer from map
            var map = window.MapLibreRoute ? window.MapLibreRoute.getMap() : null;
            if (map && data.id) {
                if (map.getLayer(data.id)) map.removeLayer(data.id);
                if (map.getSource(data.id)) map.removeSource(data.id);
            }
        });

        RADEventBus.on('flight:highlighted', function(data) {
            // Highlight flight on map (TSD symbology)
        });

        // Initialize sub-modules
        if (window.RADFlightSearch) RADFlightSearch.init();
        if (window.RADFlightDetail) RADFlightDetail.init();
        if (window.RADAmendment) RADAmendment.init();
        if (window.RADMonitoring) RADMonitoring.init();

        // Tab change: start/stop monitoring poll
        $('#radTabs a[data-toggle="tab"]').on('shown.bs.tab', function(e) {
            if (e.target.id === 'tab-monitoring' && window.RADMonitoring) {
                RADMonitoring.startPolling();
            }
        });

        // Initialize map (same as route.php)
        initMap();
    }

    function initMap() {
        if (mapInitialized) return;
        if (window.MapLibreRoute) {
            window.MapLibreRoute.init({
                containerId: 'graphic',
                enableFlights: true,
                enableContextMenu: true,
                contextMenuItems: [
                    { label: 'Amend Route', action: function(flight) {
                        RADEventBus.emit('flight:selected', flight);
                        $('#tab-edit').tab('show');
                    }}
                ]
            });
            mapInitialized = true;
        }
    }

    return { init: init };
})();

// Auto-init on DOM ready
$(document).ready(function() {
    RADController.init();
});
```

- [ ] **Step 7: Commit**

```bash
git add assets/js/rad-event-bus.js assets/js/rad-flight-search.js \
        assets/js/rad-flight-detail.js assets/js/rad-amendment.js \
        assets/js/rad-monitoring.js assets/js/rad.js
git commit -m "feat(rad): JS modules — event bus, flight search, detail, amendment, monitoring, controller"
```

---

## Task 7: SWIM Sync + GUFI Propagation

**Files:**
- Modify: `scripts/swim_sync.php` (~3 lines)
- Modify: `scripts/swim_tmi_sync_daemon.php` (~30 lines)

- [ ] **Step 1: Update swim_sync.php for GUFI propagation**

Currently `swim_sync.php` generates GUFI via `NEWID()` on the SWIM side (line ~291-292 comment). Change to read `adl_flight_core.gufi` and pass it through.

In the SELECT query that reads from ADL (around line 288), add `c.gufi` to the SELECT list (it already joins `adl_flight_core c`).

In the INSERT/UPDATE logic (around line 571-674), pass the ADL-sourced `gufi` value instead of relying on the SWIM-side `DEFAULT NEWID()`. Specifically:
- In the UPDATE statement (line ~573): no change needed (gufi doesn't change on UPDATE)
- In the INSERT statement (line ~603): add `gufi` to the column list and `?` to values, using `$f['gufi']`
- In the parameter array (line ~670+): add `$f['gufi']` at the appropriate position

The `gufi_legacy` generation continues unchanged for backwards compatibility.

- [ ] **Step 2: Add swim_rad_amendments to swim_tmi_sync_daemon.php**

In `scripts/swim_tmi_sync_daemon.php`, add a new sync config entry for `swim_rad_amendments`. Find the array of table sync configs (the `$tier1Tables` or equivalent array) and add:

```php
[
    'swim_table'   => 'swim_rad_amendments',
    'pk'           => 'id',
    'columns'      => [
        'id' => 'INT', 'gufi' => 'UNIQUEIDENTIFIER', 'callsign' => 'VARCHAR(10)',
        'origin' => 'CHAR(4)', 'destination' => 'CHAR(4)',
        'original_route' => 'VARCHAR(MAX)', 'assigned_route' => 'VARCHAR(MAX)',
        'assigned_route_geojson' => 'VARCHAR(MAX)',
        'status' => 'VARCHAR(10)', 'rrstat' => 'VARCHAR(10)',
        'tmi_reroute_id' => 'INT', 'tmi_id_label' => 'VARCHAR(20)',
        'delivery_channels' => 'VARCHAR(50)', 'route_color' => 'VARCHAR(10)',
        'created_by' => 'INT',
        'created_utc' => 'DATETIME2', 'sent_utc' => 'DATETIME2',
        'delivered_utc' => 'DATETIME2', 'resolved_utc' => 'DATETIME2',
        'expires_utc' => 'DATETIME2', 'notes' => 'VARCHAR(500)',
    ],
    'watermark'    => 'created_utc',
    'source_query' => 'SELECT * FROM dbo.rad_amendments',
],
```

- [ ] **Step 3: Commit**

```bash
git add scripts/swim_sync.php scripts/swim_tmi_sync_daemon.php
git commit -m "feat(rad): GUFI propagation in SWIM sync + RAD amendment mirror sync"
```

---

## Task 8: ADL Daemon Compliance Integration

**Files:**
- Modify: `scripts/vatsim_adl_daemon.php` (~20 lines)

- [ ] **Step 1: Add RAD compliance check to ADL daemon**

In `scripts/vatsim_adl_daemon.php`, find the section where `executeDeferredTMISync()` runs (the deferred processing section, runs every ~60s). After the TMI sync call, add RAD compliance checking:

```php
// RAD compliance check (runs on deferred cycle, ~60s)
try {
    require_once __DIR__ . '/../load/services/RADService.php';
    $rad_conn_tmi = get_conn_tmi();
    $rad_conn_adl = get_conn_adl();
    if ($rad_conn_tmi && $rad_conn_adl) {
        $radService = new RADService($rad_conn_adl, $rad_conn_tmi);
        $compliance_result = $radService->runComplianceCheck();
        if ($compliance_result['transitioned'] > 0) {
            daemon_log("RAD compliance: checked={$compliance_result['checked']}, transitioned={$compliance_result['transitioned']}");
        }
    }
} catch (Exception $e) {
    daemon_log("RAD compliance error: " . $e->getMessage(), 'ERROR');
}
```

This runs the compliance check which:
- Compares `adl_flight_plan.route` against `rad_amendments.assigned_route` for all SENT/DLVD amendments
- Transitions to ACPT if routes match
- Transitions to EXPR if flight departed without matching route
- Updates `rrstat` field

- [ ] **Step 2: Commit**

```bash
git add scripts/vatsim_adl_daemon.php
git commit -m "feat(rad): compliance checking in ADL daemon deferred cycle"
```

---

## Task 9: i18n Keys + Nav Link

**Files:**
- Modify: `assets/locales/en-US.json`
- Modify: `load/nav.php`

- [ ] **Step 1: Add RAD i18n keys to en-US.json**

Add the following keys under a new `"rad"` top-level key in `assets/locales/en-US.json`:

```json
{
  "rad": {
    "pageTitle": "Route Amendment Dialogue",
    "tabs": {
      "search": "Flight Search",
      "detail": "Flight Detail",
      "edit": "Route Edit",
      "monitoring": "Monitoring"
    },
    "search": {
      "placeholder": "Search callsign...",
      "filter": "Filter",
      "save": "Save Filter",
      "presetDefault": "-- Filter Presets --",
      "callsign": "Callsign",
      "origDest": "Orig/Dest",
      "type": "Type",
      "times": "Times",
      "status": "Status",
      "addToDetail": "Add to Detail",
      "resultCount": "{count} flights",
      "noResults": "No flights match your search criteria"
    },
    "detail": {
      "selectAll": "Select All",
      "selectNone": "Select None",
      "removeSelected": "Remove Selected",
      "routeHistory": "Route History",
      "noHistory": "No route changes recorded"
    },
    "edit": {
      "retrieveRoutes": "Retrieve Routes",
      "recentlySent": "Recently Sent",
      "searchDb": "Search Playbook / CDR / Preferred",
      "manualPlaceholder": "Enter route string manually...",
      "createAmendment": "Create Route Amendment",
      "routeOptions": "Route Options",
      "noTmiRoutes": "No TMI Route Options are available.",
      "noTosOptions": "No TOS Options are available.",
      "eramPlaceholder": "Applicable ERAM/ABRR Route Options — future feature",
      "adaptedPlaceholder": "Applicable Adapted Route Options — future feature"
    },
    "monitoring": {
      "total": "Total",
      "autoRefresh": "Auto-refresh",
      "lastUpdate": "Last update",
      "resend": "Resend",
      "send": "Send",
      "delete": "Delete",
      "departedWithout": "Departed without amendment",
      "complianceRate": "Compliance Rate"
    },
    "status": {
      "DRAFT": "Draft",
      "SENT": "Sent",
      "DLVD": "Delivered",
      "ACPT": "Accepted",
      "RJCT": "Rejected",
      "EXPR": "Expired"
    },
    "rrstat": {
      "C": "Conformant",
      "NC": "Non-Conformant",
      "NC_OK": "NC Approved",
      "UNKN": "Unknown",
      "OK": "Exception",
      "EXC": "Excluded"
    }
  }
}
```

- [ ] **Step 2: Add RAD to nav.php**

In `load/nav.php`, add a RAD link in the Operations dropdown (near the route.php and playbook.php links):

```php
<a class="dropdown-item" href="/rad.php">
    <i class="fas fa-exchange-alt fa-fw mr-1"></i><?= __('rad.pageTitle') ?>
</a>
```

- [ ] **Step 3: Commit**

```bash
git add assets/locales/en-US.json load/nav.php
git commit -m "feat(rad): i18n keys and nav link"
```

---

## Task Summary

| Task | Description | Est. Commits |
|------|-------------|-------------|
| 1 | Database migrations (GUFI, RAD tables, SWIM mirror, MySQL presets) | 1 |
| 2 | RADService.php shared service layer | 1 |
| 3 | Internal API endpoints (api/rad/*) | 1 |
| 4 | SWIM API endpoints (api/swim/v1/rad/*) | 1 |
| 5 | rad.php page + rad.css + nav link | 1 |
| 6 | JS modules (event bus, search, detail, amendment, monitoring, controller) | 1 |
| 7 | SWIM sync + GUFI propagation | 1 |
| 8 | ADL daemon compliance integration | 1 |
| 9 | i18n keys + nav link | 1 |
| **Total** | | **9 commits** |

## Verification Checklist

After all tasks are complete, manually verify:

1. Navigate to `https://perti.vatcscc.org/rad.php` — page loads with map + 4 tabs
2. Flight Search tab: search by callsign, filter by origin/dest, results appear with dual sub-rows
3. Select flights → "Add to Detail" → Flight Detail tab shows expanded table, badge updates
4. Route Edit tab: enter manual route → "Validate" → "Plot on Map" → route appears on map
5. Route Edit tab: "Search DB" opens playbook/CDR search dialog
6. Create amendment (Save Draft) → appears in Monitoring tab as DRAFT
7. Send amendment → status transitions to SENT, CPDLC delivery attempted
8. Monitoring tab: summary cards show correct counts, 30s auto-refresh works
9. SWIM API: `curl -H "X-API-Key: ..." https://perti.vatcscc.org/api/swim/v1/rad/flights` returns flights
10. SWIM API: POST to `/api/swim/v1/rad/amendments` creates amendment
