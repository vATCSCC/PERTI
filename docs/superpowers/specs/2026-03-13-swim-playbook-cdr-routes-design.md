# SWIM Playbook & CDR Routes API + Daily Refresh

**Date**: 2026-03-13
**Status**: Design
**Author**: Claude (with user direction)

## Problem Statement

External SWIM API consumers need access to playbook route data and Coded Departure Routes (CDRs). Currently:

- **Playbooks**: Exposed via `/api/swim/v1/playbook/plays` (reads from MySQL `playbook_plays`/`playbook_routes`). Working but data may go stale if imports aren't run manually after AIRAC updates.
- **CDRs**: No SWIM API endpoint exists. The only external access is the legacy `api/data/routes.php` (session-gated, returns HTML, SQL injection vulnerable). CDR data lives in VATSIM_REF `coded_departure_routes` (~41K rows) imported from `assets/data/cdrs.csv` (~47K rows) via manual script execution.

Both data sources update with AIRAC cycles (every 28 days) but imports are manual.

## Goals

1. Create a new SWIM API endpoint for CDRs with full filtering (origin, dest, code, search, ARTCC)
2. Ensure canonical data sources stay up to date via automated daily reimport
3. Keep the existing playbook endpoint working (it already reads from the right tables)

## Non-Goals

- No unified endpoint merging playbooks and CDRs (different data shapes, different databases)
- No GeoJSON route geometry for CDRs (route strings only, same as playbooks currently)
- No changes to internal UI endpoints (`api/data/routes.php`, `api/data/playbook/`)
- No SWIM database mirroring (endpoints read directly from canonical sources)

---

## Architecture

```
┌─── DATA SOURCES (CSV files in repo) ───────────────────────────┐
│ assets/data/cdrs.csv              (47K CDRs)                   │
│ assets/data/playbook_routes.csv   (55K playbook routes)        │
└────────────────────────────────────────────────────────────────-┘
        │ Daily at 06:00Z (refdata_sync_daemon.php)
        ▼
┌─── CANONICAL DATABASES ───────────────────────────────────────-┐
│ VATSIM_REF.coded_departure_routes (Azure SQL, ~41K rows)       │
│   + NEW: dep_artcc, arr_artcc columns (populated during import)│
│                                                                │
│ perti_site.playbook_plays + playbook_routes (MySQL, ~56K rtes) │
│   (FAA-source plays truncated & reloaded daily)                │
└────────────────────────────────────────────────────────────────-┘
        │ SWIM endpoints read directly
        ▼
┌─── SWIM API ENDPOINTS ────────────────────────────────────────-┐
│ /api/swim/v1/routes/cdrs         NEW  (reads VATSIM_REF)       │
│ /api/swim/v1/playbook/plays      EXISTING (reads MySQL)        │
└────────────────────────────────────────────────────────────────-┘
```

---

## Component 1: CDR SWIM Endpoint

### File: `api/swim/v1/routes/cdrs.php`

**HTTP Methods**: GET only

**Authentication**: Optional (public access by default, API key for rate limiting)

**Query Parameters**:

| Parameter | Type | Description | Example |
|-----------|------|-------------|---------|
| `origin` | string | Filter by origin ICAO code | `KJFK` |
| `dest` | string | Filter by destination ICAO code | `KORD` |
| `code` | string | Filter by CDR code (exact or prefix match) | `JFKORD` |
| `search` | string | Free-text search across code + route string | `GREKI` |
| `artcc` | string | Filter by departure OR arrival ARTCC | `ZNY` |
| `dep_artcc` | string | Filter by departure ARTCC only | `ZNY` |
| `arr_artcc` | string | Filter by arrival ARTCC only | `ZAU` |
| `page` | int | Page number (default 1, min 1) | `1` |
| `per_page` | int | Results per page (default 50, max 200) | `100` |

**Response Format (JSON)**:

```json
{
  "success": true,
  "data": [
    {
      "cdr_id": 1234,
      "cdr_code": "JFKORDNB",
      "full_route": "KJFK GREKI J584 CUTTA COATE4 KORD",
      "origin_icao": "KJFK",
      "dest_icao": "KORD",
      "dep_artcc": "ZNY",
      "arr_artcc": "ZAU",
      "direction": null,
      "altitude_min_ft": null,
      "altitude_max_ft": null,
      "is_active": true,
      "source": "cdrs.csv"
    }
  ],
  "pagination": {
    "page": 1,
    "per_page": 50,
    "total": 47141,
    "total_pages": 943
  },
  "metadata": {
    "generated": "2026-03-13T12:00:00Z",
    "source": "vatsim_ref.coded_departure_routes",
    "last_import": "2026-03-13T06:00:00Z"
  }
}
```

**Implementation Notes**:
- Reads from VATSIM_REF `coded_departure_routes` via `get_conn_ref()` (sqlsrv)
- Uses `swim_init_auth(false, false)` for optional auth (same pattern as playbook endpoint)
- CORS headers: `Access-Control-Allow-Origin: *`
- Parameterized queries throughout (no SQL injection)
- `artcc` parameter searches both `dep_artcc` and `arr_artcc` columns
- CDR code search uses prefix match: `WHERE cdr_code LIKE ? + '%'`
- Free-text search uses contains: `WHERE (cdr_code LIKE '%' + ? + '%' OR full_route LIKE '%' + ? + '%')`
- Pagination: count query + data query pattern (same as playbook endpoint)

**Error Responses**:
- 503 if VATSIM_REF database unavailable
- 405 for non-GET methods
- 400 for invalid pagination parameters

---

## Component 2: Schema Migration

### File: `database/migrations/adl/ref_cdr_artcc_columns.sql`

Adds ARTCC columns and indexes to `coded_departure_routes` in both VATSIM_REF and VATSIM_ADL (ADL has a synced copy):

```sql
-- VATSIM_REF
ALTER TABLE dbo.coded_departure_routes ADD dep_artcc NVARCHAR(4) NULL;
ALTER TABLE dbo.coded_departure_routes ADD arr_artcc NVARCHAR(4) NULL;

CREATE NONCLUSTERED INDEX IX_cdr_dep_artcc
  ON dbo.coded_departure_routes(dep_artcc) WHERE dep_artcc IS NOT NULL;
CREATE NONCLUSTERED INDEX IX_cdr_arr_artcc
  ON dbo.coded_departure_routes(arr_artcc) WHERE arr_artcc IS NOT NULL;
CREATE NONCLUSTERED INDEX IX_cdr_origin_dest
  ON dbo.coded_departure_routes(origin_icao, dest_icao);
```

Same migration applied to VATSIM_ADL (which has a synced copy of the table).

**ARTCC Population**: During import, after inserting CDR rows, run an UPDATE joining against the `apts` table to populate `dep_artcc`/`arr_artcc`:

```sql
UPDATE c
SET c.dep_artcc = a.ARTCC_ID
FROM dbo.coded_departure_routes c
INNER JOIN dbo.apts a ON c.origin_icao = a.ICAO_ID
WHERE c.dep_artcc IS NULL;

UPDATE c
SET c.arr_artcc = a.ARTCC_ID
FROM dbo.coded_departure_routes c
INNER JOIN dbo.apts a ON c.dest_icao = a.ICAO_ID
WHERE c.arr_artcc IS NULL;
```

Note: The `apts` table column for ARTCC may be named `ARTCC_ID`, `RESPONSIBLE_ARTCC`, or similar — will be verified during implementation.

---

## Component 3: Daily Reimport Daemon

### File: `scripts/refdata_sync_daemon.php`

A lightweight daemon (started by `startup.sh`) that:
1. Sleeps until 06:00Z daily
2. Reimports CDRs from `cdrs.csv` → VATSIM_REF `coded_departure_routes`
3. Populates `dep_artcc`/`arr_artcc` via airport ARTCC lookup
4. Reimports FAA playbook routes from `playbook_routes.csv` → MySQL `playbook_plays`/`playbook_routes`
5. Logs results
6. Records last-sync timestamp (accessible by SWIM endpoints for `last_import` metadata field)

### CDR Reimport Logic (extracted from `adl/reference_data/import_cdrs.php`):

1. Parse `cdrs.csv` (format: `CODE,ROUTE`)
2. Extract origin/dest ICAO from route string endpoints and CDR code pattern
3. `DELETE FROM dbo.coded_departure_routes` (truncate)
4. Batch INSERT (500 rows/batch for performance)
5. UPDATE `dep_artcc`/`arr_artcc` from `apts` table join
6. Log row count

### Playbook Reimport Logic (extracted from `scripts/playbook/import_faa_to_db.php`):

1. Parse `playbook_routes.csv` (header: `Play,Route String,Origins,Origin_TRACONs,Origin_ARTCCs,Destinations,Dest_TRACONs,Dest_ARTCCs`)
2. Group by play name (~1,800 plays)
3. Delete existing FAA-source plays: `DELETE FROM playbook_routes WHERE play_id IN (SELECT play_id FROM playbook_plays WHERE source = 'FAA')`; `DELETE FROM playbook_plays WHERE source = 'FAA'`
4. Batch INSERT plays + routes
5. Log row counts

### Daemon Lifecycle:
- PID file: `/tmp/refdata_sync_daemon.pid`
- Heartbeat: `/tmp/refdata_sync_daemon.heartbeat`
- Log: `/home/LogFiles/refdata_sync.log`
- Hibernation-aware: runs even during hibernation (reference data, not operational)
- On startup: check if a sync is overdue (>24h since last), run immediately if so

### startup.sh Integration:

Add to the daemon startup block in `scripts/startup.sh`:

```bash
# Reference data sync (daily CDR + playbook reimport)
php /home/site/wwwroot/scripts/refdata_sync_daemon.php >> /home/LogFiles/refdata_sync.log 2>&1 &
```

---

## Component 4: Existing Playbook Endpoint (No Changes)

`/api/swim/v1/playbook/plays` continues to read from MySQL `playbook_plays`/`playbook_routes`. After the daily reimport updates those tables with fresh FAA data, the endpoint automatically serves the latest version. No code changes needed.

---

## Component 5: OpenAPI Spec Update

### File: `api-docs/openapi.yaml`

Add the `/swim/v1/routes/cdrs` endpoint documentation:
- GET method with all query parameters
- Response schema matching the JSON format above
- 200/400/405/503 response codes
- Tag: `Routes`

---

## Files Created/Modified

| File | Action | Description |
|------|--------|-------------|
| `api/swim/v1/routes/cdrs.php` | Create | New CDR SWIM endpoint |
| `scripts/refdata_sync_daemon.php` | Create | Daily reimport daemon |
| `database/migrations/adl/ref_cdr_artcc_columns.sql` | Create | Schema migration for ARTCC columns |
| `scripts/startup.sh` | Modify | Add refdata_sync_daemon to daemon startup |
| `api-docs/openapi.yaml` | Modify | Document new CDR endpoint |

---

## Testing Plan

1. **Migration**: Run schema migration on VATSIM_REF and VATSIM_ADL; verify columns and indexes created
2. **CDR Import**: Run reimport manually; verify row count matches CSV; verify `dep_artcc`/`arr_artcc` populated
3. **CDR Endpoint**: Test all query parameters:
   - `?origin=KJFK` — returns CDRs departing JFK
   - `?dest=KORD` — returns CDRs arriving ORD
   - `?code=JFKORD` — prefix match on CDR codes
   - `?artcc=ZNY` — returns CDRs with ZNY as dep or arr ARTCC
   - `?search=GREKI` — free-text match
   - `?page=2&per_page=100` — pagination
   - Combined filters: `?origin=KJFK&artcc=ZAU`
4. **Playbook Reimport**: Run reimport; verify FAA plays recreated with correct route counts
5. **Daemon**: Start daemon; verify it runs at 06:00Z; check logs
6. **CORS**: Verify `Access-Control-Allow-Origin: *` on CDR endpoint
7. **Auth**: Verify endpoint works without API key (public) and with API key

## Risk Assessment

- **CDR reimport is destructive** (DELETE + INSERT): Brief window where CDR table is empty. Acceptable for reference data; consumers retry on empty results.
- **Playbook reimport deletes FAA plays**: Only FAA-source plays are affected. DCC/ECFMP/CANOC plays are untouched.
- **ARTCC lookup may miss airports**: Some non-standard ICAO codes (military, heliports) may not have ARTCC in `apts`. `dep_artcc`/`arr_artcc` remain NULL for these — acceptable.
- **No impact on hibernation**: Reference data daemon runs independently of operational daemons.
