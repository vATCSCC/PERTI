# RAD Amendment Workflow V2 — Multi-Actor Partial Route Amendments

**Date**: 2026-04-03
**Status**: Draft
**Supersedes**: `2026-03-31-route-amendment-dialogue-design.md` (V1)
**Author**: Claude (design), Jeremy Peterson (review)

## 1. Purpose

RAD V2 extends the existing Route Amendment Dialogue with three major capabilities:

1. **Clearance Builder** — Partial route amendments with auto-diff, structured phraseology generation, and manual override. Replaces full-route replacement with segment-level precision.
2. **Multi-Actor Workflow** — Four roles (TMU, ATC, Pilot, VA) on a single RAD page with role-based views and permissions. Adds ISSUED state for ATC handoff.
3. **Trajectory Option Set (TOS)** — Pilot rejection response with ranked route preferences, TMU resolution with route distance/time diffs and color-coded map comparison.

### 1.1 What Already Exists (V1)

The following V1 infrastructure is deployed and will be extended, not replaced:

- **Migration 057**: `rad_amendments` + `rad_amendment_log` tables in VATSIM_TMI
- **RADService.php**: Full service layer with amendment CRUD, compliance checking, CPDLC delivery
- **JS modules**: `rad.js`, `rad-event-bus.js`, `rad-flight-search.js`, `rad-flight-detail.js`, `rad-amendment.js`, `rad-monitoring.js`
- **API endpoints**: `api/rad/search.php`, `amendment.php`, `compliance.php`, `routes.php`, `history.php`, `filters.php`
- **Auth**: `api/rad/common.php` with `rad_require_auth()` and `rad_require_tmu()`

---

## 2. Design Decisions

| Decision | Choice | Rationale |
|----------|--------|-----------|
| Partial vs full-route amendment | Partial (segment-level) | ATC clearances reference changed segments, not full route. Matches real-world phraseology. |
| Route diff algorithm | LCS on waypoint tokens | Identifies common anchor fixes between filed and assigned routes. Standard diffing approach. |
| Phraseology delivery | Text for CPDLC/Discord, structured JSON for SWIM | CPDLC uses natural language; SWIM consumers need machine-readable segment boundaries. |
| Default closing phrase | "then as filed" with toggle to "rest of route unchanged" | "then as filed" is standard ATC phraseology. Toggle for flexibility. |
| State machine | 8 states (adds ISSUED, TOS_PENDING, TOS_RESOLVED, FORCED) | ISSUED separates "TMU sent to ATC" from "ATC issued to pilot". TOS states handle rejection workflow. |
| Role detection | Auto-detect from VATSIM network data | TMU via admin_users (existing), ATC via VNAS controller feed, Pilot via adl_flight_core.cid. VA is user-specified. |
| TOS format | Free-form route strings + auto "as filed"/"as amended" | Pilots can propose any route. Auto-options guarantee the original and amended routes are always available. |
| TOS resolution UI | Distance/time diffs + color-coded map comparison | TMU needs quantitative route comparison, not just text. Map overlay shows spatial differences. |
| Page architecture | Single RAD page, role-based view switching | Avoids maintaining separate pages. JS module shows/hides capabilities based on detected role. |

---

## 3. Amendment State Machine

### 3.1 States

| State | Description | Who Sets It |
|-------|-------------|-------------|
| `DRAFT` | Amendment created, not yet sent to ATC | TMU |
| `SENT` | Sent to ATC sector for issuance | TMU |
| `ISSUED` | ATC has issued clearance to pilot | ATC / VA |
| `ACPT` | Pilot accepted (filed matching route) | System / ATC / VA / Pilot |
| `RJCT` | Pilot rejected without TOS | ATC / VA / Pilot |
| `TOS_PENDING` | Pilot rejected with TOS submission | ATC / VA / Pilot |
| `TOS_RESOLVED` | TMU resolved TOS (accepted option or counter-proposed) | TMU |
| `FORCED` | TMU forced original amendment despite rejection | TMU |
| `EXPR` | Expired — flight departed or timeout | System |

### 3.2 Transitions

```
DRAFT ──send──> SENT ──issue──> ISSUED ──accept──> ACPT (terminal)
                                   │
                                   ├──reject──> RJCT (terminal, no TOS)
                                   │
                                   ├──reject+tos──> TOS_PENDING ──resolve──> TOS_RESOLVED (terminal*)
                                   │                                │
                                   │                                └──force──> FORCED (terminal)
                                   │
                                   └──depart/timeout──> EXPR (terminal)

DRAFT ──cancel──> (deleted)
SENT ──cancel──> (deleted)

* TOS_RESOLVED triggers creation of a NEW amendment (DRAFT) with the accepted/counter-proposed route
```

### 3.3 Transition Triggers

| Transition | Trigger | Actor |
|------------|---------|-------|
| DRAFT → SENT | "Send to ATC" button or API call | TMU |
| SENT → ISSUED | "Mark as Issued" button. TMU can also mark ISSUED to bypass ATC handoff (e.g., direct pilot contact). | ATC / VA / TMU |
| ISSUED → ACPT | Compliance daemon detects filed route matches assigned, OR manual accept button | System / ATC / VA / Pilot |
| ISSUED → RJCT | "Reject" button (without TOS) | ATC / VA / Pilot |
| ISSUED → TOS_PENDING | "Reject + Submit TOS" | ATC / VA / Pilot |
| TOS_PENDING → TOS_RESOLVED | TMU accepts a TOS option or counter-proposes | TMU |
| TOS_PENDING → FORCED | TMU forces original amendment | TMU |
| SENT/ISSUED → EXPR | Flight departs without matching route, or `expires_utc` reached | System |

---

## 4. Clearance Builder

### 4.1 Route Diff Engine (Client-Side JS)

Tokenizes filed and assigned routes into waypoint arrays, runs LCS (Longest Common Subsequence) to find common anchor fixes, and identifies changed segments between anchors.

**Input**: Filed route string, assigned route string
**Output**: Structured diff with segments:

```javascript
{
    anchors: ['BRIGS', 'COLIN', 'CAMRN'],  // common fixes (LCS result)
    segments: [
        {
            anchor_before: 'BRIGS',
            anchor_after: 'COLIN',
            removed: ['J60', 'PHILA'],           // from filed route
            inserted: ['J80', 'SBY'],             // in assigned route
            type: 'mid'                            // 'begin' | 'mid' | 'end'
        },
        {
            anchor_before: 'COLIN',
            anchor_after: 'CAMRN',
            removed: ['V276', 'DIXIE'],
            inserted: ['V16', 'MERIT'],
            type: 'mid'
        }
    ],
    unchanged_prefix: ['KATL', 'KAJIN2'],         // before first change
    unchanged_suffix: ['LENDY6', 'KJFK'],         // after last change
    clearance_limit: 'KJFK'                       // destination
}
```

### 4.2 Auto-Diff Visualization

Displays filed and assigned routes with color-coded tokens:

| Color | Meaning |
|-------|---------|
| Green (#5a5) | Common anchor fix |
| Red (#f66, strikethrough) | Removed from filed route |
| Teal (#4ECDC4, bold) | New segment in assigned route |
| Light green (#6f8) | Unchanged prefix/suffix |

### 4.3 Clearance Phraseology Patterns

Three patterns based on segment position, composable for multi-segment amendments:

**Beginning** (change starts before first common fix):
```
{callsign} cleared to {clearance_limit} via {amendment}, {common_fix}, rest of route unchanged
```

**Mid-route** (change between two common fixes):
```
{callsign} cleared to {clearance_limit} via after {common_fix_1}, {amendment}, {common_fix_2}, rest of route unchanged
```

**End** (change after last common fix):
```
{callsign} cleared to {clearance_limit} via after {common_fix}, {amendment}, rest of route unchanged
```

**Multi-segment** (chain with "then after"):
```
{callsign} cleared to {clearance_limit} via after {fix_1}, {amendment_1}, {fix_2}, then after {fix_3}, {amendment_2}, {fix_4}, rest of route unchanged
```

**Closing phrase**: Default "then as filed" (toggle to "rest of route unchanged").

### 4.4 Structured Clearance Builder Form

Auto-populated from diff, each segment editable:

- **Anchor dropdowns**: Adjustable — user can change which common fixes serve as segment boundaries
- **Amendment text**: Read-only field showing the new segment (editable via route edit)
- **+ Add Segment / - Remove Last**: Manual segment management
- **Closing phrase toggle**: "then as filed" (default) / "rest of route unchanged"
- **Generated clearance preview**: Live-updated as form changes

### 4.5 Delivery Channel Split

| Channel | Format | Content |
|---------|--------|---------|
| CPDLC (Hoppie) | Text | Full clearance phraseology string |
| Discord | Text | Same phraseology string |
| SWIM API | JSON | `{ segments: [{anchor_before, amendment, anchor_after}], clearance_text, closing_phrase }` |
| WebSocket | JSON | Same as SWIM |

---

## 5. Role-Based Views

### 5.1 Role Detection

Priority order — first match wins:

| Priority | Check | Role | Data Source |
|----------|-------|------|-------------|
| 1 | CID in `admin_users` table | **TMU** | MySQL `admin_users`, checked by existing `rad_require_tmu()` |
| 2 | CID in VNAS controller feed as active controller | **ATC** | `https://live.env.vnas.vatsim.net/data-feed/controllers.json` — poll + cache |
| 3 | User self-selects VA role + airline on page | **VA** | User-specified (no CID→VA mapping exists in DB) |
| 4 | CID in `adl_flight_core.cid` as active pilot | **Pilot** | `adl_flight_core.cid` (INT, indexed via `IX_core_cid`) |
| 5 | Authenticated but none of above | **Observer** | Read-only |

### 5.2 VNAS Controller Feed Integration

**Endpoint**: `https://live.env.vnas.vatsim.net/data-feed/controllers.json`

**Data structure** (from VNAS data feed — camelCase JSON, not PascalCase like the C# models):
```json
{
    "artccId": "ZNY",
    "primaryFacilityId": "ZNY",
    "positions": [
        {
            "facilityId": "ZNY",
            "facilityName": "New York Center",
            "positionType": "Artcc"  // Artcc | Tracon | Atct
        }
    ],
    "vatsimData": {
        "cid": "1234567",
        "callsign": "NY_66_CTR",
        "realName": "John Doe"
    }
}
```

**Note**: CID is a string in the JSON feed, not an integer. Parse with `intval()` for DB lookups.

**Caching**: PHP-side poll every 60 seconds, cache to `$_SESSION['vnas_controllers']` or APCu/file cache. JS-side: fetch role on page load via `api/rad/role.php`, re-check every 5 minutes.

**ATC context enrichment**: When ATC role detected, extract:
- `artcc_id` — filter amendments to flights in/through this ARTCC
- `facility_id` — filter to sector-specific amendments
- `position_type` — Artcc/Tracon/Atct (determines amendment scope)
- `callsign` — display in UI

### 5.3 Pilot GUFI Matching

When Pilot role detected via `adl_flight_core.cid`:
- Query: `SELECT flight_key AS gufi, callsign, ... FROM adl_flight_core WHERE cid = ? AND is_active = 1`
- If multiple active flights (rare but possible with prefiled), show flight selector
- Auto-filter all views to pilot's own flight(s)

### 5.4 VA Role

- User self-selects on page via dropdown: "Role: VA" + airline selector (populated from `airlines` table where `is_virtual = 1`)
- VA context stored in `sessionStorage` (key: `RAD_VA_CONTEXT`)
- Filters views to flights matching `adl_flight_aircraft.airline_icao`
- No automatic detection — `airlines` table has no CID mapping

### 5.5 Role Capabilities Matrix

| Capability | TMU | ATC | Pilot | VA | Observer |
|------------|-----|-----|-------|----|---------|
| Flight Search tab | Yes | Limited* | No | Limited* | No |
| Flight Detail tab | Yes | Yes | Own flight | Airline flights | No |
| Route Edit tab | Yes | No | No | No | No |
| Monitoring tab | Yes | Sector queue | Own flight | Airline flights | No |
| Create amendment | Yes | No | No | No | No |
| Send amendment to ATC | Yes | No | No | No | No |
| Mark as ISSUED | Yes* | Yes | No | Yes | No |
| Accept on behalf of pilot | No | Yes | Yes (own) | Yes | No |
| Reject on behalf of pilot | No | Yes | Yes (own) | Yes | No |
| Submit TOS | No | Yes | Yes (own) | Yes | No |
| Resolve TOS | Yes | No | No | No | No |
| Force amendment | Yes | No | No | No | No |
| Counter-propose route | Yes | No | No | No | No |
| View clearance phraseology | Yes | Yes | Yes (own) | Yes | No |

*TMU can mark ISSUED to bypass ATC handoff (direct pilot contact scenario).

ATC: filtered to flights in their sector. VA: filtered to airline flights.

---

## 6. Trajectory Option Set (TOS)

### 6.1 TOS Entry (Pilot / ATC / VA)

Shown when rejecting an amendment. Contains:

**Automatic options** (always present, rank adjustable):
- **"As Originally Filed"** — the route before the amendment
- **"As Amended"** — the amendment route being rejected

**Free-form options** (0 or more):
- Text input for any route string
- Rank dropdown (1-N, unique per option)
- Remove button per option
- "+ Add Route Option" button

**Rank**: Integer 1-N, where 1 = most preferred. Each option gets a unique rank. Auto-options start at rank 1 and 3; pilot options inserted between.

**Submit actions**:
- **"Submit TOS"** → status transitions to `TOS_PENDING`
- **"Reject Without TOS"** → status transitions to `RJCT` (terminal)

### 6.2 TOS Resolution (TMU View)

TMU sees all TOS options ranked by pilot preference, with route metrics and map comparison.

**Per-option display**:
- Rank badge (#1, #2, #3, ...)
- Color swatch (clickable — toggles route on/off on map)
- Full route string
- Label: "As Originally Filed" / "As Amended" / "Pilot Option"
- **Distance**: Total route distance in nm (from PostGIS `expand_route()` → geometry length)
- **Time**: Estimated flight time (distance / cruise GS from BADA or filed TAS)
- **Delta**: Difference vs baseline (option #1): "+16 nm / +4 min" in amber

**Map comparison**:
- Each TOS option gets a unique color from the palette: `#32CD32`, `#FFD700`, `#4ECDC4`, `#FF6347`, `#9370DB`, `#00BFFF`, `#FFA500`, `#FF69B4`
- Color swatch next to each option matches the map line
- Clicking swatch toggles that route on/off on the map
- "Plot All" button shows all options simultaneously
- "Clear" button removes all plotted routes
- Legend shows option number + label + distance for each plotted route

**TMU actions** (all three transition the original amendment to a terminal state first):
- **"Accept"** per option → original amendment → `TOS_RESOLVED`, then creates new DRAFT amendment with accepted route (linked via `parent_amendment_id`)
- **"Counter-Propose New Route"** → original amendment → `TOS_RESOLVED` (with `resolved_action = 'COUNTER'`), then opens Route Edit with flight pre-selected to create new DRAFT (linked via `parent_amendment_id`)
- **"Force Original Amendment"** → original amendment → `FORCED` (terminal), original amendment's assigned route stands as-is and compliance daemon treats it as mandatory

### 6.3 Route Distance Calculation

Use PostGIS `expand_route()` to get waypoint geometry, then `ST_Length()` on the resulting LINESTRING:

```sql
SELECT ST_Length(
    ST_MakeLine(ARRAY(
        SELECT geom FROM expand_route($route_string) ORDER BY seq
    ))::geography
) / 1852.0 AS distance_nm
```

Time estimate: `distance_nm / cruise_gs_knots * 60` (minutes). Cruise GS sourced from:
1. `adl_flight_position.groundspeed` if airborne
2. Filed TAS from `adl_flight_plan.fp_tas`
3. BADA performance lookup by aircraft type (fallback)

---

## 7. Data Model Changes

### 7.0 GUFI Column Clarification

The `rad_amendments.gufi` column (NVARCHAR(64), from migration 057) stores `adl_flight_core.flight_key` — the human-readable flight identifier string (e.g., `AAL123_KATL_KJFK_1712345678`), NOT the `adl_flight_core.gufi` UUID. This follows the existing pattern where `RADService::getFlightByGufi()` queries `WHERE c.flight_key = ?`.

If a future migration adds a true UUID GUFI to `adl_flight_core`, the RAD column should be migrated to match. For now, `gufi` in this spec always means `flight_key`.

### 7.1 Migration 058: `rad_amendments` Schema Changes (VATSIM_TMI)

Extends existing migration 057 table:

```sql
-- Replace CHECK constraint: add V2 states, keep DLVD for backward compat with V1 amendments
-- DLVD is deprecated in V2 (replaced by ISSUED) but existing V1 DLVD rows must remain valid
ALTER TABLE dbo.rad_amendments DROP CONSTRAINT CK_rad_status;
ALTER TABLE dbo.rad_amendments ADD CONSTRAINT CK_rad_status
    CHECK (status IN ('DRAFT','SENT','ISSUED','DLVD','ACPT','RJCT',
                      'TOS_PENDING','TOS_RESOLVED','FORCED','EXPR'));

-- New columns for V2
ALTER TABLE dbo.rad_amendments ADD
    clearance_text      VARCHAR(MAX) NULL,       -- generated phraseology
    clearance_segments  VARCHAR(MAX) NULL,        -- JSON: [{anchor_before, amendment, anchor_after}]
    closing_phrase      VARCHAR(20)  NULL,         -- 'then_as_filed' or 'rest_unchanged'
    issued_by           INT          NULL,         -- CID of ATC/VA who issued
    issued_utc          DATETIME2    NULL,         -- when ISSUED
    rejected_by         INT          NULL,         -- CID of rejector
    rejected_utc        DATETIME2    NULL,         -- when RJCT/TOS_PENDING
    resolved_by         INT          NULL,         -- CID of TMU who resolved TOS
    tos_id              INT          NULL,         -- FK to rad_tos if TOS submitted
    forced_utc          DATETIME2    NULL,         -- when FORCED
    parent_amendment_id INT          NULL,         -- links TOS_RESOLVED → new DRAFT
    actor_role          VARCHAR(10)  NULL;         -- role of last actor: TMU/ATC/PILOT/VA

-- Update filtered index to include new active statuses
DROP INDEX IX_rad_amendments_status ON dbo.rad_amendments;
CREATE INDEX IX_rad_amendments_status ON dbo.rad_amendments (status)
    INCLUDE (gufi, callsign, origin, destination, assigned_route, rrstat, sent_utc)
    WHERE status IN ('DRAFT','SENT','ISSUED','TOS_PENDING');

-- Index for TOS resolution chain lookups
CREATE INDEX IX_rad_amendments_parent ON dbo.rad_amendments (parent_amendment_id)
    INCLUDE (gufi, status)
    WHERE parent_amendment_id IS NOT NULL;
```

### 7.2 Migration 058: `rad_tos` Table (VATSIM_TMI)

```sql
CREATE TABLE dbo.rad_tos (
    id              INT IDENTITY(1,1) PRIMARY KEY,
    amendment_id    INT NOT NULL,                  -- parent amendment
    gufi            NVARCHAR(64) NOT NULL,
    submitted_by    INT NOT NULL,                  -- CID of submitter
    submitted_role  VARCHAR(10) NOT NULL,           -- ATC/PILOT/VA
    submitted_utc   DATETIME2 NOT NULL DEFAULT SYSUTCDATETIME(),
    resolved_utc    DATETIME2 NULL,
    resolved_action VARCHAR(20) NULL,               -- ACCEPT/COUNTER/FORCE
    resolved_option_rank INT NULL,                  -- which rank was accepted
    resolved_by     INT NULL,                       -- TMU CID
    notes           VARCHAR(500) NULL,
    CONSTRAINT FK_rad_tos_amendment FOREIGN KEY (amendment_id)
        REFERENCES dbo.rad_amendments(id)
);

CREATE INDEX IX_rad_tos_amendment ON dbo.rad_tos (amendment_id);
CREATE INDEX IX_rad_tos_gufi ON dbo.rad_tos (gufi);
```

### 7.3 Migration 058: `rad_tos_options` Table (VATSIM_TMI)

```sql
CREATE TABLE dbo.rad_tos_options (
    id              INT IDENTITY(1,1) PRIMARY KEY,
    tos_id          INT NOT NULL,
    rank            INT NOT NULL,                   -- pilot preference order (1 = most preferred)
    route_string    VARCHAR(MAX) NOT NULL,
    option_type     VARCHAR(20) NOT NULL,            -- 'as_filed' | 'as_amended' | 'pilot_option'
    distance_nm     DECIMAL(10,1) NULL,              -- computed route distance
    time_minutes    INT NULL,                         -- estimated flight time
    route_geojson   VARCHAR(MAX) NULL,                -- cached geometry for map display
    CONSTRAINT FK_rad_tos_option_tos FOREIGN KEY (tos_id)
        REFERENCES dbo.rad_tos(id) ON DELETE CASCADE
);

CREATE INDEX IX_rad_tos_options_tos ON dbo.rad_tos_options (tos_id);
```

### 7.4 Migration 058: `rad_role_cache` Table (perti_site MySQL)

```sql
CREATE TABLE rad_role_cache (
    cid             INT PRIMARY KEY,
    detected_role   VARCHAR(10) NOT NULL,            -- TMU/ATC/PILOT/VA/OBSERVER
    artcc_id        VARCHAR(4) NULL,                 -- for ATC: artcc code
    facility_id     VARCHAR(10) NULL,                -- for ATC: facility ID
    position_type   VARCHAR(10) NULL,                -- for ATC: Artcc/Tracon/Atct
    callsign        VARCHAR(20) NULL,                -- for ATC: position callsign
    flight_gufi     VARCHAR(64) NULL,                -- for PILOT: matched flight
    airline_icao    VARCHAR(4) NULL,                  -- for VA: selected airline
    detected_utc    DATETIME NOT NULL DEFAULT UTC_TIMESTAMP,
    expires_utc     DATETIME NOT NULL,
    INDEX IX_rad_role_expires (expires_utc)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Cleanup: delete expired rows hourly (add to monitoring_daemon or cron)
-- DELETE FROM rad_role_cache WHERE expires_utc < UTC_TIMESTAMP;
```

### 7.5 SWIM Mirror Update

```sql
-- swim_rad_tos in SWIM_API (synced by swim_tmi_sync_daemon.php)
CREATE TABLE dbo.swim_rad_tos (
    id              INT PRIMARY KEY,
    amendment_id    INT NOT NULL,
    gufi            NVARCHAR(64) NOT NULL,
    submitted_by    INT NOT NULL,
    submitted_role  VARCHAR(10) NOT NULL,
    submitted_utc   DATETIME2 NOT NULL,
    resolved_utc    DATETIME2 NULL,
    resolved_action VARCHAR(20) NULL,
    synced_utc      DATETIME2 NOT NULL DEFAULT SYSUTCDATETIME()
);

-- Update swim_rad_amendments mirror to include new columns
ALTER TABLE dbo.swim_rad_amendments ADD
    clearance_text      VARCHAR(MAX) NULL,
    clearance_segments  VARCHAR(MAX) NULL,
    issued_by           INT          NULL,
    issued_utc          DATETIME2    NULL,
    actor_role          VARCHAR(10)  NULL;
```

---

## 8. API Changes

### 8.1 New Endpoints

| Endpoint | Method | Auth | Purpose |
|----------|--------|------|---------|
| `api/rad/role.php` | GET | Session | Detect caller's role (returns role, context, capabilities) |
| `api/rad/tos.php` | GET | Session | Get TOS for an amendment |
| `api/rad/tos.php` | POST | Session (ATC/Pilot/VA) | Submit TOS with ranked options |
| `api/rad/tos.php?action=resolve` | POST | Session (TMU) | Resolve TOS (accept/counter/force) |
| `api/rad/amendment.php?action=issue` | POST | Session (ATC/VA) | Mark amendment as ISSUED |
| `api/rad/amendment.php?action=accept` | POST | Session (ATC/VA/Pilot) | Accept amendment |
| `api/rad/amendment.php?action=reject` | POST | Session (ATC/VA/Pilot) | Reject amendment (with or without TOS) |
| `api/rad/clearance.php` | POST | Session (TMU) | Generate clearance phraseology from diff |
| `api/rad/route-metrics.php` | POST | Session | Compute distance/time for route strings (uses PostGIS) |
| `api/swim/v1/rad/tos.php` | GET/POST | API key | SWIM TOS endpoints |

### 8.2 Modified Endpoints

| Endpoint | Change |
|----------|--------|
| `api/rad/amendment.php` POST | Add `clearance_text`, `clearance_segments`, `closing_phrase` to create payload |
| `api/rad/compliance.php` | Include ISSUED status in active amendment queries |
| `api/rad/routes.php?source=options` | Add TOS options to response when TOS exists |

### 8.3 Role Detection Endpoint (`api/rad/role.php`)

**Response**:
```json
{
    "role": "ATC",
    "cid": 1234567,
    "context": {
        "artcc_id": "ZNY",
        "facility_id": "ZNY",
        "position_type": "Artcc",
        "callsign": "NY_66_CTR"
    },
    "capabilities": {
        "can_create_amendment": false,
        "can_issue": true,
        "can_accept_reject": true,
        "can_submit_tos": true,
        "can_resolve_tos": false,
        "can_force": false,
        "tabs": ["detail", "monitoring"]
    }
}
```

### 8.4 Route Metrics Endpoint (`api/rad/route-metrics.php`)

**Request** (POST):
```json
{
    "routes": [
        "KATL KAJIN2 BRIGS J60 PHILA COLIN V276 DIXIE CAMRN LENDY6 KJFK",
        "KATL KAJIN2 BRIGS J80 SBY COLIN V16 MERIT CAMRN LENDY6 KJFK"
    ],
    "aircraft_type": "B738",
    "cruise_speed_kts": 450
}
```

**Response**:
```json
{
    "metrics": [
        {
            "route": "KATL KAJIN2 BRIGS J60 ...",
            "distance_nm": 742.3,
            "time_minutes": 106,
            "geojson": { "type": "LineString", "coordinates": [...] }
        },
        {
            "route": "KATL KAJIN2 BRIGS J80 ...",
            "distance_nm": 781.1,
            "time_minutes": 112,
            "geojson": { "type": "LineString", "coordinates": [...] }
        }
    ],
    "baseline_index": 0
}
```

---

## 9. JS Module Changes

### 9.1 New Module: `rad-clearance-builder.js`

Handles clearance builder form, route diff engine, and phraseology generation.

**Public API**:
```javascript
window.RADClearanceBuilder = {
    init: function(container),
    setRoutes: function(filedRoute, assignedRoute),   // triggers auto-diff
    getDiff: function(),                                // returns diff object
    getClearanceText: function(),                       // returns phraseology string
    getSegments: function(),                            // returns structured segments JSON
    setClosingPhrase: function(phrase),                 // 'then_as_filed' or 'rest_unchanged'
    reset: function()
};
```

**Event bus messages**:
| Event | Payload |
|-------|---------|
| `clearance:updated` | `{ text, segments, closingPhrase }` |
| `clearance:anchor-changed` | `{ segmentIndex, anchorType, newFix }` |

### 9.2 New Module: `rad-role.js`

Handles role detection, capability gating, and UI adaptation.

**Public API**:
```javascript
window.RADRole = {
    init: function(),                                    // fetches role from api/rad/role.php
    getRole: function(),                                 // returns 'TMU'|'ATC'|'PILOT'|'VA'|'OBSERVER'
    getContext: function(),                               // returns role context object
    can: function(capability),                           // checks capability
    setVAContext: function(airlineIcao),                  // user-specified VA
    onRoleChanged: function(callback)                    // notified when role re-detected
};
```

### 9.3 New Module: `rad-tos.js`

Handles TOS entry form (Pilot/ATC/VA) and TOS resolution panel (TMU).

**Public API**:
```javascript
window.RADTOS = {
    init: function(container),
    showEntryForm: function(amendment),                   // for Pilot/ATC/VA rejection
    showResolutionPanel: function(amendment, tosData),    // for TMU resolution
    getOptions: function(),                               // returns ranked route array
    plotOption: function(rank, color),                    // plot single option on map
    plotAll: function(),                                   // plot all options with colors
    clearPlots: function()
};
```

### 9.4 Modified: `rad-amendment.js`

Add clearance builder integration:

```javascript
// After route is selected/entered:
RADClearanceBuilder.setRoutes(filedRoute, assignedRoute);

// In createAmendment():
var clearance = RADClearanceBuilder.getClearanceText();
var segments = RADClearanceBuilder.getSegments();
// Send clearance_text + clearance_segments with amendment POST
```

### 9.5 Modified: `rad-monitoring.js`

Add role-based filtering and TOS indicators:

- Filter amendments by role context (ATC: sector, VA: airline, Pilot: own flights)
- Show "TOS" badge on TOS_PENDING amendments
- Show "Enter TOS" action button for ATC/VA on ISSUED+RJCT rows
- Show "Resolve TOS" action button for TMU on TOS_PENDING rows

### 9.6 Modified: `rad.js`

- Call `RADRole.init()` on page load, show/hide tabs based on role
- Wire role change handler to re-render active tab
- Add VA role selector UI (dropdown in header bar, visible only when no other role detected)

### 9.7 Event Bus Additions

| Event | Producer | Consumer | Payload |
|-------|----------|----------|---------|
| `role:detected` | rad-role.js | All modules | `{ role, context, capabilities }` |
| `amendment:issued` | ATC/VA action | Monitoring | `{ amendmentId }` |
| `amendment:rejected` | ATC/VA/Pilot | Monitoring/TOS | `{ amendmentId, hasTos }` |
| `tos:submitted` | rad-tos.js | Monitoring | `{ tosId, amendmentId }` |
| `tos:resolved` | rad-tos.js | Monitoring | `{ tosId, action, newAmendmentId? }` |
| `clearance:updated` | rad-clearance-builder.js | Amendment | `{ text, segments }` |

---

## 10. RADService.php Changes

### 10.1 New Methods

```php
// Role detection
public function detectRole(int $cid, $conn_mysql, $vnas_cache = null): array

// TOS lifecycle
public function submitTOS(int $amendment_id, int $cid, string $role, array $options): array
public function resolveTOS(int $tos_id, string $action, ?int $accepted_rank, ?string $counter_route, int $tmu_cid): array

// Amendment state transitions
public function issueAmendment(int $id, int $issuer_cid, string $issuer_role): array
public function acceptAmendment(int $id, int $cid, string $role): array
public function rejectAmendment(int $id, int $cid, string $role, bool $with_tos = false): array
public function forceAmendment(int $tos_id, int $tmu_cid): array

// Route metrics
public function computeRouteMetrics(array $routes, ?string $aircraft_type, ?int $cruise_speed): array

// Clearance generation
public function generateClearance(string $filed, string $assigned, string $closing = 'then_as_filed'): array
```

### 10.2 VNAS Controller Feed Polling

New helper in `load/services/VNASService.php`:

```php
class VNASService {
    private const FEED_URL = 'https://live.env.vnas.vatsim.net/data-feed/controllers.json';
    private const CACHE_TTL = 60; // seconds

    public static function getControllers(): array;           // fetch + cache
    public static function findByCID(int $cid): ?array;       // find controller by CID
    public static function isActiveController(int $cid): bool; // quick check
}
```

Cache storage: File-based (`/tmp/vnas_controllers.json` with timestamp) on production, APCu on dev if available.

---

## 11. UI Views per Role

### 11.1 TMU View

Full RAD page as currently designed. Additions:
- Clearance Builder panel in Route Edit tab (replaces simple route entry)
- TOS Resolution panel in Monitoring tab (inline expandable per TOS_PENDING row)
- Amendment status badges now include ISSUED/TOS_PENDING/TOS_RESOLVED/FORCED

### 11.2 ATC View

Simplified single-panel view:

**Amendment Queue** (replaces Search/Detail/Edit tabs):
- Table of amendments in SENT/ISSUED state for flights in ATC's sector
- Columns: Callsign, O/D, Status, Clearance (phraseology), Actions
- Actions per row:
  - SENT → "Issue" button (transitions to ISSUED)
  - ISSUED → "Accepted" / "Rejected" buttons
  - RJCT → "Enter TOS" button (opens TOS entry form)
- Clearance text displayed in the table for read-and-issue workflow

**Map**: Same map, filtered to sector flights with amendment routes auto-plotted.

### 11.3 Pilot View

Minimal view showing only own flight:

- Amendment panel: Shows current amendment if any, with clearance text
- Accept/Reject buttons when status is ISSUED
- TOS entry form when rejecting
- Flight info header: callsign, origin/dest, route, status

### 11.4 VA View

Similar to ATC view but filtered by airline:

- Amendment queue filtered to `adl_flight_aircraft.airline_icao` matching selected VA
- Same Issue/Accept/Reject/TOS capabilities as ATC
- Airline selector dropdown when multiple VAs available

---

## 12. CPDLC Integration Updates

### 12.1 Message Formats

**Route Amendment (existing, updated)**:
```
ROUTE AMENDMENT: {callsign} CLEARED TO {dest} VIA AFTER {fix}, {amendment}, THEN AS FILED PER {tmi_label}
```

**TOS Request (new)**:
```
TOS REQUEST: {callsign} AMENDMENT REJECTED. SUBMIT PREFERRED ROUTES VIA RAD PAGE OR REPLY WITH ROUTE STRING.
```

**TOS Resolution (new)**:
```
TOS RESOLVED: {callsign} CLEARED TO {dest} VIA {accepted_route} PER {tmi_label}
```

**Force Notification (new)**:
```
AMENDMENT MANDATORY: {callsign} MUST COMPLY WITH {assigned_route} PER {tmi_label}. CONTACT ATC.
```

### 12.2 CPDLC TOS Response Parsing

If pilot responds to TOS REQUEST via CPDLC with a route string, parse the response and auto-create a TOS option:

- Listen for Hoppie TELEX responses to RAD-initiated messages
- Parse route string from response body
- Auto-add as a TOS option with rank = next available
- Notify TMU via WebSocket `tos:option_added` event

---

## 13. Compliance Daemon Updates

The existing compliance check in `RADService::runComplianceCheck()` needs to handle new states:

```php
// Active statuses to check: SENT, ISSUED (not just SENT, DLVD)
// DLVD is deprecated — replaced by ISSUED for the V2 workflow
// Keep DLVD in query for backward compat with any V1 amendments still in-flight

$sql = "SELECT id, gufi, assigned_route, status FROM dbo.rad_amendments
        WHERE status IN ('SENT', 'DLVD', 'ISSUED')";
```

New transition logic:
- ISSUED + filed route matches → ACPT
- ISSUED + flight departed + route doesn't match → EXPR
- SENT + timeout → EXPR (ATC never issued)

---

## 14. i18n Additions

New keys under `rad.*` namespace in `en-US.json`:

```json
{
    "rad": {
        "role": {
            "tmu": "TMU",
            "atc": "ATC",
            "pilot": "Pilot",
            "va": "Virtual Airline",
            "observer": "Observer",
            "detecting": "Detecting role...",
            "selectVA": "Select your airline"
        },
        "clearance": {
            "builder": "Clearance Builder",
            "preview": "Amendment Preview",
            "filedRoute": "Filed Route",
            "assignedRoute": "Assigned Route",
            "generatedClearance": "Generated Clearance",
            "closingPhrase": "Closing Phrase",
            "thenAsFiled": "then as filed",
            "restUnchanged": "rest of route unchanged",
            "addSegment": "+ Add Segment",
            "removeSegment": "- Remove Last",
            "autoDetected": "Auto-detected",
            "anchor": "Anchor Fix",
            "amendment": "Amendment"
        },
        "tos": {
            "title": "Trajectory Option Set",
            "pilotPreferences": "Pilot Preferences (ranked)",
            "automaticOptions": "Automatic Options",
            "pilotOptions": "Pilot Route Options",
            "addOption": "+ Add Route Option",
            "submitTos": "Submit TOS",
            "rejectWithoutTos": "Reject Without TOS",
            "counterPropose": "Counter-Propose New Route",
            "forceOriginal": "Force Original Amendment",
            "asOriginallyFiled": "As Originally Filed",
            "asAmended": "As Amended",
            "pilotOption": "Pilot Option",
            "plotAll": "Plot All",
            "clear": "Clear",
            "baseline": "baseline",
            "resolve": "Resolve TOS",
            "mapLegend": "Map"
        },
        "status": {
            "ISSUED": "Issued",
            "TOS_PENDING": "TOS Pending",
            "TOS_RESOLVED": "TOS Resolved",
            "FORCED": "Forced"
        },
        "actions": {
            "issue": "Issue to Pilot",
            "markIssued": "Mark as Issued",
            "acceptOnBehalf": "Accept (on behalf)",
            "rejectOnBehalf": "Reject (on behalf)",
            "enterTos": "Enter TOS",
            "viewClearance": "View Clearance"
        },
        "queue": {
            "title": "Amendment Queue",
            "noAmendments": "No pending amendments for your sector",
            "sector": "Sector"
        }
    }
}
```

---

## 15. Migration Numbering

| Migration | Database | Content |
|-----------|----------|---------|
| `058_rad_v2_workflow.sql` | VATSIM_TMI | Schema changes to `rad_amendments`, new `rad_tos` + `rad_tos_options` tables |
| `058_rad_v2_adl.sql` | VATSIM_ADL | (none needed — V1 already added `rad_amendment_id` + `rad_assigned_route`) |
| `037_swim_rad_v2.sql` | SWIM_API | `swim_rad_tos` mirror + `swim_rad_amendments` column additions |
| `rad_role_cache.sql` | perti_site MySQL | `rad_role_cache` table |

---

## 16. File Inventory

| Action | File | Purpose |
|--------|------|---------|
| **New** | `assets/js/rad-clearance-builder.js` | Route diff engine + phraseology generator + structured form |
| **New** | `assets/js/rad-role.js` | Role detection, capability gating, UI adaptation |
| **New** | `assets/js/rad-tos.js` | TOS entry form + resolution panel + map plotting |
| **New** | `load/services/VNASService.php` | VNAS controller feed polling + caching |
| **New** | `api/rad/role.php` | Role detection endpoint |
| **New** | `api/rad/tos.php` | TOS CRUD endpoint |
| **New** | `api/rad/clearance.php` | Clearance phraseology generation |
| **New** | `api/rad/route-metrics.php` | Route distance/time computation |
| **New** | `api/swim/v1/rad/tos.php` | SWIM TOS endpoint |
| **New** | `database/migrations/tmi/058_rad_v2_workflow.sql` | TMI schema changes |
| **New** | `database/migrations/swim/037_swim_rad_v2.sql` | SWIM mirror updates |
| **Modify** | `rad.php` | Add clearance builder panel, role selector, TOS panels |
| **Modify** | `assets/css/rad.css` | Styles for clearance builder, TOS, role badges, ATC queue |
| **Modify** | `assets/js/rad.js` | Role-based tab visibility, VA selector, init new modules |
| **Modify** | `assets/js/rad-amendment.js` | Integrate clearance builder, add issue/accept/reject actions |
| **Modify** | `assets/js/rad-monitoring.js` | Role-based filtering, TOS indicators, new action buttons |
| **Modify** | `assets/js/rad-flight-detail.js` | Role-based column visibility |
| **Modify** | `load/services/RADService.php` | New methods for TOS, role detection, clearance, metrics |
| **Modify** | `api/rad/common.php` | Add `rad_require_role()` for role-based auth |
| **Modify** | `api/rad/amendment.php` | Add issue/accept/reject actions |
| **Modify** | `api/rad/compliance.php` | Handle ISSUED status |
| **Modify** | `scripts/swim_tmi_sync_daemon.php` | Sync rad_tos to swim_rad_tos |
| **Modify** | `assets/locales/en-US.json` | New i18n keys |

---

## 17. Implementation Phasing

This spec is large enough to warrant phased implementation:

| Phase | Scope | Dependencies |
|-------|-------|-------------|
| **Phase 1: Clearance Builder** | Route diff engine, phraseology generation, structured form, ISSUED state. TMU-only. | V1 infrastructure (deployed) |
| **Phase 2: Multi-Actor Roles** | Role detection (VNAS + CID), ATC/Pilot/VA views, role-based tab visibility, `api/rad/role.php` | Phase 1 (ISSUED state) |
| **Phase 3: TOS Workflow** | TOS entry, TOS resolution, route metrics, color-coded map comparison, RJCT/TOS_PENDING/TOS_RESOLVED/FORCED states | Phase 1 + Phase 2 |

Each phase is independently deployable and testable.

---

## 18. Future Enhancements (Out of Scope)

- **CPDLC TOS auto-parsing**: Parse pilot CPDLC responses to auto-create TOS options
- **ERAM/ABRR route options**: Adaptive route suggestions in TOS resolution
- **Cross-facility TOS negotiation**: Multi-ARTCC coordination for complex reroutes
- **Bulk TOS resolution**: Resolve multiple TOS_PENDING amendments simultaneously
- **Historical TOS analytics**: Track TOS acceptance rates, common pilot preferences
- **Protected segments**: Slate blue highlighting for route segments that must not change
