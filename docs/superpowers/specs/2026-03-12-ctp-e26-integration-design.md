# CTP E26 Integration Design Spec

**Date**: 2026-03-12
**Status**: Implementation Ready
**Scope**: Playbook Route Analysis, NAT Naming, Comprehensive Changelogging

## 1. Existing Foundation

The CTP Oceanic Slot Management system (commit a2e2fdd, migration 045) already provides:
- `ctp_sessions` (event sessions with perspective org mapping)
- `ctp_flight_control` (per-flight with 3-segment route decomposition: NA/Oceanic/EU)
- `ctp_audit_log` (action audit with `action_detail_json` for before/after)
- `ctp_route_templates` (NAT tracks, segment-scoped route templates)
- `playbook_changelog` (MySQL, play/route-level changes with old_value/new_value)
- `playbook_plays` / `playbook_routes` (with visibility, ACL, traversed_artccs/tracons/sectors)
- SWIM API playbook endpoint (`api/swim/v1/playbook/plays.php`)
- Full CTP API under `api/ctp/` (sessions, flights, routes, audit, demand, stats)

## 2. Feature 1: Playbook Route Analysis & CTP API Integration

### 2.1 Route Analysis Engine (PHP API)

**New file**: `api/data/playbook/analysis.php`

Computes ordered facility traversal, distances, and time segments for any playbook route string.

**Algorithm**:
1. Accept `route_id` or raw `route_string` + `origin` + `dest`
2. Parse route to ordered waypoints using PostGIS `nav_fixes` lookup (reuse existing `parse_route_to_fixes()` pattern from `adl/php/parse_queue_gis_daemon.php`)
3. Build LINESTRING geometry from ordered waypoint lat/lons
4. Intersect LINESTRING with `artcc_boundaries`, `tracon_boundaries`, `sector_boundaries` in PostGIS
5. For each boundary intersection, compute entry/exit point, distance within, and fraction along route
6. Apply speed model to convert distances to times (user-configurable or default)
7. Return ordered traversal with cumulative distances and times

**Response schema**:
```json
{
  "route_id": 123,
  "route_string": "KJFK DCT HAPIE J584 DOTTY ...",
  "origin": "KJFK",
  "dest": "EGLL",
  "total_distance_nm": 3459.2,
  "total_time_min": 432.4,
  "speed_profile": { "climb": 280, "cruise": 460, "descent": 250 },
  "wind_profile": { "component_kts": 0, "direction_deg": null },
  "waypoints": [
    { "fix": "HAPIE", "lat": 40.63, "lon": -73.12, "cum_dist_nm": 15.2, "cum_time_min": 3.3 }
  ],
  "facility_traversal": [
    {
      "type": "ARTCC",
      "id": "ZNY",
      "name": "New York Center",
      "entry_fix": "KJFK",
      "exit_fix": "DOTTY",
      "entry_dist_nm": 0,
      "exit_dist_nm": 120.5,
      "distance_within_nm": 120.5,
      "time_within_min": 15.1,
      "entry_time_min": 0,
      "exit_time_min": 15.1,
      "order": 1
    }
  ],
  "fix_analysis": [
    {
      "fix": "HAPIE",
      "dist_from_origin_nm": 15.2,
      "dist_to_dest_nm": 3444.0,
      "time_from_origin_min": 3.3,
      "time_to_dest_min": 429.1,
      "facility": "ZNY"
    }
  ]
}
```

**Speed model defaults** (user-configurable via query params):
- `climb_kts=280` (below FL180)
- `cruise_kts=460` (TAS, FL180+)
- `descent_kts=250` (within 40nm of dest)
- `wind_component_kts=0` (headwind positive, tailwind negative)

### 2.2 PostGIS Route Analysis Function

**New PostGIS function**: `analyze_route_traversal(route_geom, facility_types)`

```sql
CREATE OR REPLACE FUNCTION analyze_route_traversal(
    p_route_geom geometry,
    p_facility_types text[] DEFAULT ARRAY['ARTCC','FIR']
) RETURNS TABLE (
    facility_type text,
    facility_id text,
    facility_name text,
    entry_fraction float,
    exit_fraction float,
    distance_nm float,
    entry_point geometry,
    exit_point geometry,
    traversal_order int
)
```

This leverages existing `artcc_boundaries` and `tracon_boundaries` tables with `ST_Intersection`, `ST_LineLocatePoint`, and `ST_Length` (geography cast for nautical miles).

### 2.3 SWIM API Route Analysis Endpoint

**New file**: `api/swim/v1/playbook/analysis.php`

Exposes the same analysis to external CTP consumers. Requires API key auth. Returns the same JSON schema as 2.1.

### 2.4 SWIM Ingest: CTP Throughput Data

**New file**: `api/swim/v1/playbook/throughput.php`

CTP pushes per-route throughput data to PERTI:
- `POST` with API key auth
- Body: `{ "play_id": 123, "route_id": 456, "throughput": { "planned_count": 45, "slot_count": 40, "peak_rate": 12 } }`
- Stored in new `playbook_route_throughput` table

**New MySQL table**: `playbook_route_throughput`
```sql
CREATE TABLE playbook_route_throughput (
    throughput_id   INT AUTO_INCREMENT PRIMARY KEY,
    route_id        INT NOT NULL,
    play_id         INT NOT NULL,
    source          VARCHAR(50) NOT NULL DEFAULT 'CTP',
    planned_count   INT NULL,
    slot_count      INT NULL,
    peak_rate_hr    INT NULL,
    avg_rate_hr     DECIMAL(6,1) NULL,
    period_start    DATETIME NULL,
    period_end      DATETIME NULL,
    metadata_json   JSON NULL,
    updated_by      VARCHAR(20) NULL,
    updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (route_id) REFERENCES playbook_routes(route_id) ON DELETE CASCADE,
    FOREIGN KEY (play_id) REFERENCES playbook_plays(play_id) ON DELETE CASCADE,
    UNIQUE KEY uq_route_source (route_id, source),
    INDEX idx_play (play_id)
);
```

### 2.5 Playbook UI: Analysis Panel

**Modified files**: `assets/js/playbook.js`, `assets/css/playbook.css`, `playbook.php`

Add a collapsible "Route Analysis" panel below the route list that shows:
- When a route is clicked on the map: origin, filters, dest, route string
- Expandable ordered facility traversal table
- Distance/time columns
- Speed/wind config inputs (collapsed by default)

**Map interaction**: Click a route line on the map -> highlight it -> populate analysis panel.

### 2.6 Route Page: Analysis Panel

**Modified files**: `assets/js/route-maplibre.js`, `route.php`

Similar analysis panel for the route page. When user plots a route, they can click "Analyze" to see facility traversal and timing data.

### 2.7 Throughput Toggle on Map

**Modified files**: `assets/js/playbook.js`

Add a toggle button in the playbook toolbar: "Show Throughput". When enabled:
- Route lines are color-coded by throughput volume
- Labels show planned count on each route segment
- Uses `playbook_route_throughput` data

## 3. Feature 2: NAT Naming System

### 3.1 NAT Track Resolution in Route Plotter

The `ctp_route_templates` table (migration 045) already stores NAT track definitions with `segment='OCEANIC'` and `template_name` like "NAT-A", "NAT-B", etc.

**Modified file**: `assets/js/route-maplibre.js`

Add NAT token resolution to the route parser:
1. When parsing a route string, detect tokens matching `NAT[A-Z]` or `NAT-[A-Z]`
2. Look up the token against `ctp_route_templates` via API
3. Replace the NAT token with the actual oceanic route string from the template
4. Plot the full expanded route on the map

**New API endpoint**: `api/data/playbook/nat_tracks.php`
- `GET` returns all active NAT track definitions from `ctp_route_templates` where `segment='OCEANIC'`
- Supports `?session_id=X` filter for session-specific tracks
- Returns: `{ "tracks": [{ "name": "NATA", "route_string": "CYMON 50N020W ...", "aliases": ["NAT-A","TRACK A","NATA"] }] }`

**Route plotter flow**:
1. User types: `KJFK DCT MERIT NATC BURAK DCT EGLL`
2. JS detects `NATC` token
3. Fetches NAT-C route string from API (cached client-side)
4. Expands to: `KJFK DCT MERIT [oceanic fixes from NAT-C] BURAK DCT EGLL`
5. Plots full route on map

### 3.2 NAT Track Management (CTP Route Templates Enhancement)

The `ctp_route_templates` table already supports this. We add a dedicated UI section in the CTP management page.

**Modified file**: `assets/js/ctp.js`, `ctp.php`

Add "NAT Tracks" tab in CTP session management:
- List current NAT tracks for the session
- Create/edit/delete NAT tracks
- Each track: name (NATA-NATZ), route string, altitude range
- Changes logged to `ctp_audit_log`

### 3.3 Playbook Scope Linking

CTP teams create 3 private playbooks (NA, EU, OCN). The system needs to link them for full route construction.

**New MySQL columns on `playbook_plays`**:
```sql
ALTER TABLE playbook_plays
ADD COLUMN ctp_scope ENUM('NA','OCEANIC','EU') NULL DEFAULT NULL AFTER org_code,
ADD COLUMN ctp_session_id INT NULL DEFAULT NULL AFTER ctp_scope;
```

This allows querying "all plays for CTP session X, scope NA" to build complete origin-to-destination routes by concatenating NA + OCEANIC + EU segments.

## 4. Feature 3: Comprehensive Changelogging

### 4.1 Existing State

- `playbook_changelog` (MySQL): Already tracks play/route changes with `old_value`/`new_value`, `changed_by`, `field_name`
- `ctp_audit_log` (Azure SQL): Tracks CTP actions with `action_detail_json`
- `tmi_events` (Azure SQL): TMI event log
- `adl_flight_changelog` (Azure SQL): Flight data changes

### 4.2 Enhanced Changelog Architecture

The design requirement is "make sure ALL changelogging is as detailed as possible, storing previous & current values, author info, time."

**4.2.1 Playbook Changelog Enhancement**

Current `playbook_changelog` already has `old_value`/`new_value`/`field_name`/`changed_by`. Enhancement:
- Add `changed_by_name` column for display name (avoids JOIN on every read)
- Add `ip_address` column for audit trail
- Add `session_context` JSON column for additional metadata (e.g., which CTP session, what tool was used)

```sql
ALTER TABLE playbook_changelog
ADD COLUMN changed_by_name VARCHAR(100) NULL AFTER changed_by,
ADD COLUMN ip_address VARCHAR(45) NULL AFTER changed_by_name,
ADD COLUMN session_context JSON NULL AFTER ip_address;
```

**4.2.2 CTP Audit Log Enhancement**

The `ctp_audit_log.action_detail_json` already stores before/after. Enhancement:
- Add `performed_by_name` column
- Add more action types
- Extend action_type constraint

```sql
ALTER TABLE dbo.ctp_audit_log
ADD performed_by_name NVARCHAR(64) NULL;
```

**4.2.3 Changelog PHP Helper**

**New file**: `lib/Changelog.php`

A reusable changelog helper class:
```php
class Changelog {
    public static function logPlaybookChange($conn, $play_id, $route_id, $action, $field, $old, $new, $context = []);
    public static function logCTPChange($conn, $session_id, $control_id, $action, $segment, $detail, $performer);
    public static function diffArrays($old, $new): array; // Returns field-level diffs
    public static function getPlaybookHistory($conn, $play_id, $limit = 50): array;
    public static function getCTPHistory($conn, $session_id, $limit = 50): array;
}
```

Key behaviors:
- Automatically captures `$_SESSION['VATSIM_CID']` and display name
- Captures `$_SERVER['REMOTE_ADDR']`
- For object/array changes, computes field-level diff (old→new per field)
- Timestamps are always UTC

### 4.3 Changelog UI Components

**4.3.1 Playbook Changelog Panel**

**Modified files**: `assets/js/playbook.js`, `playbook.php`

Add "History" tab/panel to playbook detail view:
- Chronological list of changes
- Each entry shows: timestamp, author, action, field changed, old→new values
- Color-coded diffs (red for removed, green for added)
- Filterable by action type, author, date range

**4.3.2 CTP Changelog Panel**

**Modified files**: `assets/js/ctp.js`, `ctp.php`

Add "Audit Log" tab in CTP session view:
- Chronological list with author, action, segment, detail
- Filter by segment (NA/OCEANIC/EU), action type, author
- Expandable JSON detail for complex changes

**4.3.3 Changelog Badge/Indicator**

Visual indicator when recent changes exist:
- Badge on "History" tab showing unread change count
- Toast notification when another user makes a change (via WebSocket)

### 4.4 API Endpoints

**New**: `api/data/playbook/changelog.php`
- `GET ?play_id=X&limit=50&offset=0` - Get changelog for a play
- `GET ?play_id=X&route_id=Y` - Get changelog for specific route
- `GET ?play_id=X&since=2026-03-12T00:00:00Z` - Changes since timestamp

**New**: `api/ctp/changelog.php`
- `GET ?session_id=X&limit=50` - Get CTP audit log
- `GET ?session_id=X&segment=NA` - Filter by segment

## 5. Database Migrations

### Migration 012: Playbook Analysis & Throughput (MySQL)

```sql
-- playbook_route_throughput table
-- playbook_plays CTP scope columns
-- playbook_changelog enhancements
```

### Migration 046: CTP Audit Enhancements (Azure SQL)

```sql
-- ctp_audit_log.performed_by_name column
```

### PostGIS Migration: Route Analysis Function

```sql
-- analyze_route_traversal() function
```

## 6. Internationalization

All new UI strings use `PERTII18n.t()`. New i18n keys added to `en-US.json` under:
- `playbook.analysis.*` - Route analysis panel strings
- `playbook.throughput.*` - Throughput display strings
- `playbook.changelog.*` - Changelog panel strings
- `ctp.nat.*` - NAT track management strings
- `ctp.changelog.*` - CTP audit log strings
- `route.analysis.*` - Route page analysis strings

Corresponding keys added to `fr-CA.json`, `en-CA.json`, `en-EU.json`.

## 7. Files to Create/Modify

### New Files
| File | Purpose |
|------|---------|
| `api/data/playbook/analysis.php` | Route analysis API endpoint |
| `api/data/playbook/changelog.php` | Playbook changelog API |
| `api/data/playbook/nat_tracks.php` | NAT track lookup API |
| `api/swim/v1/playbook/analysis.php` | SWIM route analysis endpoint |
| `api/swim/v1/playbook/throughput.php` | SWIM throughput ingest |
| `api/ctp/changelog.php` | CTP audit log API |
| `lib/Changelog.php` | Reusable changelog helper |
| `database/migrations/playbook/012_analysis_throughput.sql` | MySQL migration |
| `database/migrations/tmi/046_ctp_audit_enhance.sql` | Azure SQL migration |
| `database/migrations/postgis/route_analysis_function.sql` | PostGIS function |

### Modified Files
| File | Changes |
|------|---------|
| `assets/js/playbook.js` | Analysis panel, throughput toggle, changelog tab, NAT display |
| `assets/js/route-maplibre.js` | NAT token resolution, analysis panel |
| `assets/css/playbook.css` | Analysis panel styles, throughput colors, changelog styles |
| `assets/js/ctp.js` | NAT tracks tab, enhanced audit log |
| `playbook.php` | Analysis panel HTML, changelog panel HTML |
| `route.php` | Analysis panel HTML |
| `ctp.php` | NAT tracks tab HTML, audit log tab HTML |
| `assets/locales/en-US.json` | New i18n keys |
| `assets/locales/fr-CA.json` | French translations |
| `assets/locales/en-CA.json` | Canadian English overlay |
| `assets/locales/en-EU.json` | European English overlay |
| `api/mgt/playbook/save.php` | Enhanced changelog logging |
| `api/mgt/playbook/route.php` | Enhanced changelog logging |
| `api/ctp/common.php` | Enhanced audit logging with name |
