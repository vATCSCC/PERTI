# Route Amendment Dialogue (RAD) Design

**Date**: 2026-03-31
**Status**: Draft
**Author**: Claude (design), Jeremy Peterson (review)
**TFMS Reference**: TFMS TSD Reference Manual v9.5, pages 4-394 through 4-407

## 1. Purpose

RAD is a new page (`rad.php`) where TMU operators monitor flights for necessary reroutes, manage flight plans, explore reroute options, and conduct route validation. It provides a complete amendment lifecycle: search flights, compose route amendments, deliver via CPDLC, and monitor compliance.

External clients access the same capabilities through VATSWIM API endpoints authenticated via API keys.

## 2. Design Decisions

| Decision | Choice | Rationale |
|----------|--------|-----------|
| Amendment delivery | ADL fields + CPDLC (4-channel) | Active pilot notification via Hoppie, VatswimPlugin, WS, Discord. Tracks delivery + compliance. |
| Scope | TMI reroutes + ad-hoc | Matches real TFMS where RAD handles both reroute compliance and individual amendments. |
| RRIA | Deferred | V1 focuses on core RAD workflow. RRIA (Preview + Model modes) is a future enhancement. |
| Amendment statuses | DRAFT/SENT/DLVD/ACPT/RJCT/EXPR | Full lifecycle. DLVD = CPDLC confirmed. ACPT = pilot filed matching route. EXPR = departed without amending. |
| Layout | Map top, tabbed bottom | Map always visible. Tabs: Flight Search, Flight Detail, Route Edit, Monitoring. Reduces visual clutter. |
| Architecture | Modular JS (Approach B) | Separate modules per tab + shared event bus. Follows `playbook-*.js` pattern. No new dependencies. |
| Flight identifier | GUFI (UUID v4) | Same GUFI that VATSWIM uses. New column on `adl_flight_core`, propagated to SWIM sync. Immutable per flight. |

## 3. Page Layout

### 3.1 Overall Structure

```
+---------------------------------------------------------------+
| Header / Nav Bar                                    ADL LIVE   |
+---------------------------------------------------------------+
|                                                                |
|  [Route Input Overlay]                   [Layer Toggles]       |
|                                                                |
|                    MapLibre GL Map                              |
|           (reuses route-maplibre.js)                           |
|                                                                |
|  Flight symbols + data blocks + route lines + context menu     |
|                                                                |
+---------------------------------------------------------------+
| [Flight Search] [Flight Detail (3)] [Route Edit] [Monitoring] |
+---------------------------------------------------------------+
|                                                                |
|                    Active Tab Content                           |
|                                                                |
+---------------------------------------------------------------+
```

### 3.2 Map Region (Top)

Reuses `route-maplibre.js` with all existing layers and symbology:
- Route string input overlay (top-left, same as route.php)
- Layer toggles (top-right): ARTCC, TRACON, High/Low/Superhigh sectors, Flights, Weather
- Flight context menu on click: Toggle Data Block, Toggle Orig/Dest, Toggle Route, Draw Route, History, Change Color, **Amend Route** (opens Route Edit tab with flight pre-selected), Deselect
- All map layers, labels, draw orders, and symbology match route.php and playbook.php

### 3.3 Tab Bar

Four tabs with badge counts:
- **Flight Search** — Find and select flights from ADL
- **Flight Detail** — View expanded info for selected flights (badge = count)
- **Route Edit** — Compose and send route amendments
- **Monitoring** — Track amendment delivery and compliance (badge = active count)

## 4. Tab Specifications

### 4.1 Flight Search Tab

**Search bar**: Callsign text search (instant filter).

**Filter controls**: Origin (airport/TRACON/center), Destination (airport/TRACON/center), Aircraft type (ICAO code), Carrier, Departure/Arrival times (ETD/CTD/ATD etc.) with UTC date/time picker (default start=now, end=now+2h, allow open-ended), Route string elements. Multi-filter enabled. Filters persist for session. Global and local filter save/load.

**Results table** — two sub-rows per flight:

| Column | Top Sub-Row | Bottom Sub-Row |
|--------|-------------|----------------|
| Checkbox | (spans both) | |
| Callsign | (spans both) | |
| Orig/Dest | Origin airport | Destination airport |
| Type | (spans both, ICAO) | |
| Times | ETD / ETE / ETA | CTD / CTE / CTA |
| Status | (spans both) | |

- Sort by any column (click cycle: asc → desc → none)
- Multi-select via checkboxes, select all/none
- "Add to Detail" button moves selected flights to Flight Detail tab

### 4.2 Flight Detail Tab

**Action bar**: Select All, Select None, Remove Selected.

**Detail table** — two sub-rows per flight:

| Column | Top Sub-Row | Bottom Sub-Row |
|--------|-------------|----------------|
| Checkbox | (spans both) | |
| Callsign | (spans both) | |
| Orig/Dest | Origin airport | Destination airport |
| TRACON | Origin TRACON | Destination TRACON |
| Center | Origin center | Destination center |
| Amendment | Amendment status badge | Route History button |
| Route | Full route string (auto-fit, wrap on spaces) | |
| Type | (spans both, ICAO) | |
| Times | ETD / ETE / ETA | CTD / CTE / CTA |
| Status | (spans both) | |

- Select/deselect controls which flights are included in route amendment
- Route History button opens dialog showing route changes since flight initialization with diff view

### 4.3 Route Edit Tab

Two-column layout:

**Left column — Retrieve Routes:**
- **Recently Sent** button: Opens dialog with recently sent routes for the defined city pair(s). Grayed out if none. Shows scrollable list with checkboxes.
- **Search DB** button: Opens Route Search dialog (reuses `playbook-cdr-search.js`) to retrieve from Playbook, CDR, or Preferred Routes database.
- **Route Code**: Text input + "Get CDR" button for CDR code lookup.
- **Add Route**: Manual route entry textarea. Must match acceptable route.php formats. Validate + Plot on Map buttons.
- **Route Color**: Color picker for route symbology on map.
- **Route Options** button: Opens Route Options Dialog (see Section 4.3.1).

**Right column — Current Routes + Create Amendment:**

**Current Routes section** — per selected flight:
- Draw route toggle icon (default off)
- Callsign (black=active, gray=inactive)
- Current route string
- TMI ID (or "Multiple") if applicable
- Amendment status badge
- Rte Options button

**Create Route Amendment section:**
- New route display (the route to be applied)
- Route comparison diff view (red=old, green=new)
- Delivery channel checkboxes: CPDLC (default on), SWIM broadcast (default on), Discord notify
- TMI association dropdown: None (ad-hoc), or select active TMI program
- Save Draft / Send Amendment buttons

#### 4.3.1 Route Options Dialog

Shows route options per flight with sub-sections:
- **Applicable TMI Route Options** (or "No TMI Route Options are available.") — routes from active TMI reroutes
- **Applicable TOS Options** (or "No TOS Options are available.") — Trajectory Option Sets
- **Applicable ERAM/ABRR Route Options** — placeholder, future feature
- **Applicable Adapted Route Options** — placeholder (ADR, AAR, ADAR), future feature

Each option shows: draw route toggle, checkbox, route string, TMI name if applicable.

### 4.4 Monitoring Tab

**Summary cards**: Total, DRAFT, SENT, DLVD, ACPT, RJCT, EXPR counts. Auto-refresh indicator (30s cycle, last update timestamp).

**Filter bar**: Show All / Pending / Non-Compliant / Alerts. TMI program filter dropdown.

**Amendment tracking table:**

| Column | Content |
|--------|---------|
| Callsign | Colored by assignment |
| O/D | Origin/Destination pair |
| Amdt Status | DRAFT/SENT/DLVD/ACPT/RJCT/EXPR badge |
| RRSTAT | Conformance: C/NC/NC_OK/UNKN/OK/EXC |
| TMI ID | Associated TMI or dash |
| Assigned Route | Route sent to pilot (truncated) |
| Filed Route | Pilot's current filed route (red if mismatched) |
| Sent At | UTC timestamp |
| Flight Status | ACTIVE/PREFILED/etc. |
| Actions | Resend, Send (for DRAFT), Delete, alert text |

- Alert row: Red background for EXPR flights that departed without amending
- **Reroute monitoring**: Polls ADL for flight plan changes, detects when filed route matches assigned route (→ ACPT), alerts when flight departs without amendment (→ EXPR)

**Aggregate compliance bar**: Per-TMI-program breakdown showing C/NC/UNKN/EXC counts with compliance rate percentage.

## 5. Data Model

### 5.1 GUFI on ADL (New)

```sql
-- adl_flight_core (VATSIM_ADL)
ALTER TABLE dbo.adl_flight_core ADD gufi UNIQUEIDENTIFIER NOT NULL
    CONSTRAINT DF_adl_flight_core_gufi DEFAULT NEWID();
CREATE UNIQUE INDEX IX_adl_flight_core_gufi ON dbo.adl_flight_core (gufi);
```

Generated once on flight insert, immutable. Propagated to `swim_flights.gufi` during SWIM sync (replaces current `NEWID()` on SWIM side).

### 5.2 `rad_amendments` (VATSIM_TMI)

| Column | Type | Purpose |
|--------|------|---------|
| `id` | int PK identity | Amendment ID |
| `gufi` | uniqueidentifier NOT NULL | Flight identifier (from `adl_flight_core.gufi`) |
| `callsign` | varchar(10) | Denormalized for display |
| `origin` | char(4) | Airport ICAO |
| `destination` | char(4) | Airport ICAO |
| `original_route` | varchar(max) | Route at time of amendment |
| `assigned_route` | varchar(max) | New route being assigned |
| `assigned_route_geojson` | varchar(max) | GeoJSON geometry (from PostGIS expand_route) |
| `status` | varchar(10) NOT NULL | DRAFT/SENT/DLVD/ACPT/RJCT/EXPR |
| `rrstat` | varchar(10) | C/NC/NC_OK/UNKN/OK/EXC |
| `tmi_reroute_id` | int nullable FK | Links to `tmi_reroutes` if TMI-associated |
| `tmi_id_label` | varchar(20) | Display label (e.g., RRDCC045) |
| `delivery_channels` | varchar(50) | Comma-separated: CPDLC,SWIM,DISCORD |
| `cpdlc_message_id` | varchar(50) | Hoppie message tracking ID |
| `route_color` | varchar(10) | Hex color for map display |
| `created_by` | int | User CID |
| `created_utc` | datetime2 DEFAULT SYSUTCDATETIME() | When created |
| `sent_utc` | datetime2 | When sent |
| `delivered_utc` | datetime2 | CPDLC delivery confirmation |
| `resolved_utc` | datetime2 | When ACPT/RJCT/EXPR |
| `expires_utc` | datetime2 | Auto-expire time |
| `notes` | varchar(500) | Operator notes |

Indexes: `IX_rad_amendments_gufi` on `(gufi)`, `IX_rad_amendments_status` filtered on `status NOT IN ('ACPT','RJCT','EXPR')`, `IX_rad_amendments_tmi` on `(tmi_reroute_id)` WHERE NOT NULL.

### 5.3 `rad_amendment_log` (VATSIM_TMI)

| Column | Type | Purpose |
|--------|------|---------|
| `id` | int PK identity | Log entry ID |
| `amendment_id` | int FK | Links to `rad_amendments` |
| `status_from` | varchar(10) | Previous status |
| `status_to` | varchar(10) | New status |
| `detail` | varchar(500) | Transition detail (e.g., "CPDLC delivery confirmed") |
| `changed_by` | int nullable | User CID or NULL for system |
| `changed_utc` | datetime2 DEFAULT SYSUTCDATETIME() | When |

### 5.4 `adl_flight_tmi` — New Columns

| Column | Type | Purpose |
|--------|------|---------|
| `rad_amendment_id` | int nullable | Current active amendment FK |
| `rad_assigned_route` | varchar(max) nullable | Assigned route (separate from filed route) |

### 5.5 `rad_filter_presets` (perti_site MySQL)

| Column | Type | Purpose |
|--------|------|---------|
| `id` | int PK auto_increment | Preset ID |
| `user_cid` | int nullable | NULL = global, else user-specific |
| `name` | varchar(100) | Preset display name |
| `filters_json` | JSON | Serialized filter state |
| `created_utc` | datetime DEFAULT UTC_TIMESTAMP | When created |
| `updated_utc` | datetime | When last modified |

### 5.6 SWIM Mirror

`swim_rad_amendments` in SWIM_API — mirror of `rad_amendments`, synced by `swim_tmi_sync_daemon.php` on 5min cycle.

## 6. API Endpoints

### 6.1 Internal API (`api/rad/`)

Session-authenticated (same as other `api/mgt/` endpoints).

| Endpoint | Method | Purpose |
|----------|--------|---------|
| `search.php` | GET | Search flights from ADL with filters. Params: `cs`, `orig`, `dest`, `orig_tracon`, `orig_center`, `dest_tracon`, `dest_center`, `type`, `carrier`, `time_field`, `time_start`, `time_end`, `route`, `status`, `page`, `limit`. |
| `amendment.php` | GET | List amendments. Params: `gufi`, `status`, `tmi_id`, `page`, `limit`. |
| `amendment.php` | POST | Create amendment. Body: `gufi`, `assigned_route`, `delivery_channels`, `tmi_reroute_id`, `route_color`, `notes`, `send` (bool). |
| `amendment.php?action=send` | POST | Send DRAFT. Body: `id`. |
| `amendment.php?action=resend` | POST | Resend SENT/DLVD. Body: `id`. |
| `amendment.php?action=cancel` | POST | Cancel DRAFT/SENT. Body: `id`. |
| `compliance.php` | GET | Poll compliance. Params: `amendment_ids` or `tmi_reroute_id`. Returns per-flight RRSTAT + aggregate. |
| `routes.php?source=recent` | GET | Recently sent routes. Params: `origin`, `destination`. |
| `routes.php?source=options` | GET | Route options for flight. Params: `gufi`. Returns TMI routes, TOS, CDR matches. |
| `history.php` | GET | Route change history. Params: `gufi`. Returns diffs from `adl_flight_changelog`. |
| `filters.php` | GET/POST/DELETE | Filter preset CRUD. |

### 6.2 VATSWIM API (`api/swim/v1/rad/`)

API key authenticated (`X-API-Key` header). Same capabilities as internal API.

| Endpoint | Method | Auth Scope | Purpose |
|----------|--------|------------|---------|
| `flights.php` | GET | `rad:read` | Search ADL flights (same filters as internal) |
| `amendments.php` | GET | `rad:read` | List amendments by GUFI, status, TMI, time range |
| `amendments.php` | POST | `rad:write` | Create amendment (DRAFT or SEND) |
| `amendments.php?action=send` | POST | `rad:write` | Send DRAFT amendment |
| `amendments.php?action=cancel` | POST | `rad:write` | Cancel amendment |
| `compliance.php` | GET | `rad:read` | Poll compliance status |
| `routes.php` | GET | `rad:read` | Route options for city pair or GUFI |
| `history.php` | GET | `rad:read` | Route change history for GUFI |

**SWIM-specific behavior:**
- All endpoints use `gufi` (UUID) as primary flight identifier
- FIXM-aligned response format with `routeTrajectoryGroup` for route data
- Amendment status changes broadcast on SWIM WebSocket (port 8090) as `rad:amendment_update` event
- Compliance changes broadcast as `rad:compliance_update` event
- Rate limiting: 60 amendment creations/min per API key (configurable)
- All operations logged to `swim_audit_log`

**New `swim_api_keys` scope values:**
- `rad:read` — Query flights, amendments, compliance, routes
- `rad:write` — Create/send/cancel amendments (implies `rad:read`)

## 7. Amendment Status Lifecycle

```
DRAFT ──send──> SENT ──cpdlc_confirm──> DLVD ──pilot_amends──> ACPT
                  │                       │
                  │                       └──pilot_departs──> EXPR
                  │                       └──pilot_files_different──> RJCT
                  │
                  └──timeout──> EXPR
                  └──cancel──> (deleted)

DRAFT ──cancel──> (deleted)
```

**Transition triggers:**
- DRAFT → SENT: Operator clicks "Send Amendment" (or SWIM POST with `send: true`)
- SENT → DLVD: Hoppie CPDLC delivery confirmation callback
- DLVD → ACPT: Compliance daemon detects `adl_flight_plan.route` matches `assigned_route`
- DLVD → RJCT: Compliance daemon detects pilot filed a different route after delivery
- SENT/DLVD → EXPR: Flight departs (ATD set) without matching route, or `expires_utc` reached
- DRAFT/SENT → (deleted): Operator cancels

**Compliance daemon**: Runs within the existing ADL ingest daemon cycle (15s). For each active amendment (status IN SENT, DLVD), compares `adl_flight_plan.route` against `rad_amendments.assigned_route`. Updates `rrstat` accordingly.

## 8. CPDLC Delivery

Follows the existing `EDCTDelivery.php` 4-channel pattern:

1. **CPDLC** (Hoppie): Uplink route amendment message to pilot's callsign
2. **VatswimPlugin**: Push via SWIM WebSocket for connected clients
3. **WebSocket**: Broadcast `rad:amendment_update` event
4. **Discord**: Optional notification to configured TMI channel

Message format for CPDLC: `ROUTE AMENDMENT: [callsign] CLEARED [new_route] PER [tmi_id_label or "ATC"]`

## 9. JS Module Architecture

```
assets/js/
  rad.js                    — Controller: tab management, init, map↔tab coordination
  rad-event-bus.js          — Simple pub/sub event bus (~30 LOC)
  rad-flight-search.js      — Flight Search tab: filters, ADL query, results table, selection
  rad-flight-detail.js      — Flight Detail tab: selected flights table, route history dialog
  rad-amendment.js           — Route Edit tab: retrieve routes, create amendment, send
  rad-monitoring.js          — Monitoring tab: compliance polling, status cards, alert rows
```

**Event bus messages:**

| Event | Producer | Consumer | Payload |
|-------|----------|----------|---------|
| `flight:selected` | Search | Detail | `{ gufi, callsign, ... }` |
| `flight:deselected` | Search/Detail | Detail/Map | `{ gufi }` |
| `flight:highlighted` | Any tab | Map | `{ gufi, color }` |
| `route:plot` | Route Edit | Map | `{ routeString, color, id }` |
| `route:clear` | Route Edit | Map | `{ id }` |
| `amendment:created` | Route Edit | Monitoring | `{ amendmentId, gufi }` |
| `amendment:sent` | Route Edit | Monitoring/Detail | `{ amendmentId }` |
| `amendment:updated` | Monitoring | Detail | `{ amendmentId, status, rrstat }` |
| `map:flight-clicked` | Map | Search/Detail | `{ gufi, callsign }` |

**Reused modules** (no changes):
- `route-maplibre.js` — Map, layers, route expansion, flight symbology
- `route-analysis-panel.js` — Facility traversal tables (embedded in Route Edit)
- `route-symbology.js` — Segment styling
- `adl-service.js` — Live flight data subscription
- `playbook-cdr-search.js` — Route search dialog
- `lib/dialog.js` — PERTIDialog
- `lib/i18n.js` — All strings via `PERTII18n.t()`

## 10. Shared Service Layer

`load/services/RADService.php` — shared PHP service class used by both internal (`api/rad/`) and SWIM (`api/swim/v1/rad/`) endpoints.

**Methods:**
- `searchFlights($filters)` — Query ADL normalized tables with filter params
- `createAmendment($gufi, $route, $options)` — Insert into `rad_amendments`, optionally trigger send
- `sendAmendment($id)` — Status transition + CPDLC delivery via `EDCTDelivery.php` pattern
- `cancelAmendment($id)` — Cancel DRAFT/SENT
- `resendAmendment($id)` — Re-trigger delivery
- `getCompliance($ids)` — Calculate per-flight RRSTAT by comparing routes
- `getAggregateCompliance($tmiRerouteId)` — Aggregate C/NC/UNKN/EXC counts + rate
- `getRouteOptions($gufi)` — TMI routes, TOS, CDR matches for a flight
- `getRecentRoutes($origin, $dest)` — Recently sent routes for city pair
- `getRouteHistory($gufi)` — Route change diffs from `adl_flight_changelog`
- `validateRoute($routeString)` — Validate via PostGIS `expand_route()`

Internal endpoints pass session user context. SWIM endpoints pass API key context. Both call the same service methods.

## 11. Conformance Status Codes (RRSTAT)

Per TFMS reference:

| Code | Meaning | Detection |
|------|---------|-----------|
| `C` | Conformant | Filed route matches assigned route |
| `NC` | Non-Conformant | Filed route does not match assigned route |
| `NC_OK` | NC Previously Approved | Non-conformant but exception granted |
| `UNKN` | Unknown | Cannot determine (route not parseable, flight data incomplete) |
| `OK` | Exception Granted | Operator explicitly approved non-conformance |
| `EXC` | Excluded | Flight excluded from reroute scope |

## 12. Database Connections

`rad.php` does NOT use `PERTI_MYSQL_ONLY` — it needs:
- `$conn_adl` (VATSIM_ADL) — flight data queries
- `$conn_tmi` (VATSIM_TMI) — amendment CRUD, TMI reroute data
- `$conn_gis` (VATSIM_GIS) — route validation/expansion
- MySQL `$conn_pdo` — filter presets

## 13. Permissions

- RAD page requires authenticated session (VATSIM OAuth)
- Amendment creation/sending restricted to TMU-level users (check against `admin_users` or role-based permission)
- SWIM API uses `rad:read` / `rad:write` scopes on `swim_api_keys`

## 14. GUFI Propagation in SWIM Sync

`scripts/swim_sync.php` currently generates `gufi` via `NEWID()` on the SWIM side. After this migration:
- ADL ingest assigns `gufi` on `adl_flight_core` INSERT (via `DEFAULT NEWID()`)
- `swim_sync.php` reads `adl_flight_core.gufi` and passes it to `swim_flights` during upsert
- `swim_flights.gufi` DEFAULT constraint removed (or left as fallback) — value comes from ADL
- This ensures the same UUID follows the flight from ADL → SWIM → RAD → CPDLC

## 15. i18n

All RAD strings use `PERTII18n.t()` with namespace prefix `rad.*`. Key structure in `en-US.json`:

```json
{
  "rad": {
    "tabs": { "search": "Flight Search", "detail": "Flight Detail", "edit": "Route Edit", "monitoring": "Monitoring" },
    "search": { "placeholder": "Search callsign...", "filter": "Filter", "save": "Save Filter", ... },
    "detail": { "selectAll": "Select All", "removeSelected": "Remove Selected", ... },
    "edit": { "recentlySent": "Recently Sent", "searchDb": "Search Playbook / CDR / Preferred", ... },
    "monitoring": { "total": "Total", "autoRefresh": "Auto-refresh", ... },
    "status": { "DRAFT": "Draft", "SENT": "Sent", "DLVD": "Delivered", "ACPT": "Accepted", "RJCT": "Rejected", "EXPR": "Expired" },
    "rrstat": { "C": "Conformant", "NC": "Non-Conformant", "NC_OK": "NC Approved", "UNKN": "Unknown", "OK": "Exception", "EXC": "Excluded" }
  }
}
```

## 16. Migration Numbering

- ADL: Next available migration number in `adl/migrations/core/` for GUFI column
- TMI: Next available migration number in `database/migrations/tmi/` for `rad_amendments` + `rad_amendment_log` + `adl_flight_tmi` columns
- SWIM: Next available migration in `database/migrations/swim/` for `swim_rad_amendments` mirror + scope values
- MySQL: `rad_filter_presets` table created via PHP setup script or inline migration

## 17. Future Enhancements (Out of Scope for V1)

- **RRIA (Reroute Impact Assessment)**: Preview mode (flight counts) and Model mode (demand impact analysis)
- **ERAM/ABRR Route Options**: Placeholder in Route Options Dialog
- **Adapted Route Options (ADR/AAR/ADAR)**: Placeholder in Route Options Dialog
- **TOS (Trajectory Option Sets)**: Placeholder in Route Options Dialog
- **Protected segments visualization**: Slate blue highlighting for read-only route segments
- **Bulk amendment operations**: Send amendments to multiple flights simultaneously with different routes
