# VATSWIM Route Query API — Design Spec

**Date**: 2026-03-30
**Status**: Draft
**Approach**: Hybrid — direct playbook/CDR queries + pre-aggregated historical stats + live TMI annotation

## Overview

A unified route query API (`POST /api/swim/v1/routes/query`) that returns ranked route suggestions from three data sources — playbook routes (56K), coded departure routes (41K CDRs), and historical flight statistics (~1.1M flights) — with optional TMI impact annotations. Designed for all consumer types: pilot clients, ATC tools, and virtual airline dispatch systems.

## API Contract

### Endpoint

```
POST /api/swim/v1/routes/query
GET  /api/swim/v1/routes/query?origin=KJFK&destination=KLAX&limit=10
```

**Auth**: Required (any SWIM API key tier). Rate limits per standard tier rules.

The GET variant is a convenience shorthand for simple city-pair lookups. It maps to the same handler with default sources and no filters.

### Request Schema (POST)

```json
{
  "origin": ["KJFK", "KEWR", "KLGA"],
  "destination": "KLAX",

  "filter": "THRU:ZOB,ZAU & -THRU:ZBW & VIA:J584",

  "filters": {
    "altitude_min": 35000,
    "altitude_max": 41000,
    "direction": "WEST",
    "text": "PARCH"
  },

  "sources": ["playbook", "cdr", "historical"],

  "context": {
    "include_active_tmis": true,
    "departure_time_utc": "2026-03-30T18:00:00Z",
    "aircraft_type": "B738"
  },

  "include": ["geometry", "traversal", "statistics"],
  "sort": "score",
  "limit": 20,
  "offset": 0
}
```

#### Field Reference

| Field | Type | Required | Default | Description |
|-------|------|----------|---------|-------------|
| `origin` | string or string[] | At least one of origin/dest/filter | — | Departure scope (see Facility Token Resolution below) |
| `destination` | string or string[] | At least one of origin/dest/filter | — | Arrival scope (see Facility Token Resolution below) |
| `filter` | string | No | — | Boolean filter expression (see Filter Expression Language below) |
| `filters.altitude_min` | int | No | — | Minimum altitude in feet |
| `filters.altitude_max` | int | No | — | Maximum altitude in feet |
| `filters.direction` | string | No | — | Cardinal direction: NORTH, SOUTH, EAST, WEST |
| `filters.text` | string | No | — | Free-text search across route string, play name, remarks |
| `sources` | string[] | No | all three | Which data pools: `"playbook"`, `"cdr"`, `"historical"` |
| `context.include_active_tmis` | bool | No | false | Annotate results with active TMI impact flags |
| `context.departure_time_utc` | string (ISO 8601) | No | now | Used for TMI time window matching |
| `context.aircraft_type` | string (ICAO) | No | — | Aircraft type for altitude/performance context |
| `include` | string[] | No | — | Optional enrichments: `"geometry"`, `"traversal"`, `"statistics"` |
| `sort` | string | No | `"score"` | Sort mode: `"score"`, `"popularity"`, `"distance"`, `"recency"` |
| `limit` | int (1-100) | No | 20 | Max results per page |
| `offset` | int | No | 0 | Pagination offset |

### Facility Token Resolution

`origin`, `destination`, and `filters.thru`/`filters.avoid` all accept **facility tokens** — identifiers that can be airports, ARTCCs, or TRACONs. Tokens are classified by format:

| Pattern | Type | Example | Matches |
|---------|------|---------|---------|
| 4-char starting with K/P | Airport (ICAO) | `KJFK`, `PHNL` | Exact airport |
| 4-char international | Airport (ICAO) | `EGLL`, `LFPG` | Exact airport |
| 3-char starting with Z | ARTCC | `ZNY`, `ZLA` | All airports/routes in that ARTCC |
| 3-char TRACON code | TRACON | `N90`, `PCT` | All airports/routes in that TRACON |
| 3-char FAA LID | Airport (FAA) | `JFK`, `LAX` | Resolved to ICAO equivalent |

**Array values use OR logic**: `"origin": ["KJFK", "KEWR", "KLGA"]` matches routes from any of those airports. `"origin": "ZNY"` matches routes from any airport in the ZNY ARTCC.

**Mixed types allowed**: `"origin": ["KJFK", "N90"]` matches routes from KJFK specifically OR any airport in the N90 TRACON.

**Resolution logic** (in `RouteQueryService`):
1. Classify each token by pattern
2. Expand ARTCC/TRACON tokens to airport lists using `swim_playbook_routes` scope columns (`origin_airports`, `origin_artccs`, `origin_tracons`)
3. For CDR source: ARTCC tokens match `dep_artcc`/`arr_artcc`; TRACON tokens expand to airport list via `airports` reference
4. For historical source: ARTCC/TRACON tokens expand to airport lists, then query `swim_route_stats` with `origin_icao IN (...)` / `dest_icao IN (...)`
5. 3-char FAA LIDs resolved to 4-char ICAO via `airports` table lookup

`filter` expression qualifiers (`THRU:`, `ORIG:`, `DEST:`, etc.) similarly accept any facility token type (ARTCC, TRACON, airport).

### Filter Expression Language

The `filter` field accepts the same boolean filter language used by the internal playbook UI (`playbook-filter-parser.js`). This is a server-side PHP port of that parser.

**Qualifiers:**

| Qualifier | Meaning | CSV column matched |
|-----------|---------|-------------------|
| `ORIG:` | Origin scope | `origin_airports`, `origin_tracons`, `origin_artccs` |
| `DEST:` | Destination scope | `dest_airports`, `dest_tracons`, `dest_artccs` |
| `THRU:` | Traversed facility | `traversed_artccs`, `traversed_tracons` |
| `VIA:` | Route string contains | Direct route string search |
| `FIR:` | FIR/region tier | Expands to ARTCC list, then matches traversed |
| `AVOID:` | Must NOT traverse | Negated match on `traversed_artccs`, `traversed_tracons` |

**Operators:**

| Operator | Syntax | Example |
|----------|--------|---------|
| AND | `&`, `AND`, space (explicit mode) | `THRU:ZDC & THRU:ZOB` |
| OR | `\|`, `OR` | `ORIG:KJFK \| ORIG:KEWR` |
| NOT | `-`, `!`, `NOT` | `-THRU:ZBW` |
| Grouping | `()` | `(ORIG:KJFK \| ORIG:KEWR) & DEST:KLAX` |

**Comma semantics** (matching playbook UI behavior):
- Multi-valued qualifiers (`THRU`, `VIA`, `FIR`, `AVOID`): comma = AND (must traverse both)
- Single-valued qualifiers (`ORIG`, `DEST`): comma = OR (any origin)

**Examples:**
```
ORIG:KJFK,KEWR,KLGA & DEST:KLAX                       # NYC metro → LAX
THRU:ZOB,ZAU & -THRU:ZBW                               # Through ZOB AND ZAU, avoid ZBW
(ORIG:ZNY | ORIG:N90) & DEST:ZLA & VIA:J584            # ZNY/N90 origin, ZLA dest, via J584
FIR:CONUS & DEST:EGLL                                   # All CONUS origins to Heathrow
ORIG:KJFK & DEST:KLAX & -AVOID:ZMP                     # JFK→LAX avoiding ZMP
```

**Interaction with `origin`/`destination` fields**: If both `origin`/`destination` fields AND `filter` are provided, they are combined with AND logic. The `filter` expression adds additional constraints on top of the origin/dest scope.

**Implementation**: PHP port of `playbook-filter-parser.js` recursive descent parser, placed in `load/services/RouteFilterParser.php`. Produces an AST, then `RouteQueryService` evaluates the AST against each candidate route's scope/traversal columns.

### Response Schema

```json
{
  "query": {
    "origin": "KJFK",
    "destination": "KLAX",
    "filters_applied": { "thru": ["ZOB"] },
    "sources_queried": ["playbook", "cdr", "historical"]
  },
  "results": [
    {
      "rank": 1,
      "score": 87.5,
      "source": "playbook",
      "also_in": ["cdr", "historical"],
      "route_string": "DEEZZ5 DEEZZ J584 SLT FRNCH4",

      "metadata": {
        "play_name": "JFK WEST 2",
        "play_id": 145,
        "cdr_code": "JFKLAX1",
        "distance_nm": 2168.4,
        "direction": "WEST"
      },

      "statistics": {
        "flight_count": 847,
        "usage_pct": 18.3,
        "avg_altitude_ft": 37200,
        "common_aircraft": ["B738", "A320", "B772"],
        "common_operators": ["AAL", "UAL", "DAL"],
        "first_seen": "2025-12-01",
        "last_seen": "2026-03-29"
      },

      "tmi_flags": [
        {
          "type": "GDP",
          "airport": "KLAX",
          "program_id": 42,
          "aar": 30,
          "status": "active",
          "impact": "arrival_delay"
        }
      ],

      "traversal": {
        "artccs": ["ZNY", "ZOB", "ZAU", "ZMP", "ZDV", "ZLA"],
        "tracons": ["N90", "L30"]
      },

      "geometry": {
        "type": "LineString",
        "coordinates": [[-73.78, 40.64], [-118.41, 33.94]]
      }
    }
  ],
  "summary": {
    "total_results": 34,
    "returned": 20,
    "offset": 0,
    "sources_hit": {
      "playbook": 12,
      "cdr": 8,
      "historical": 22
    },
    "active_tmis": 1,
    "query_time_ms": 245
  },
  "warnings": []
}
```

#### Result Fields

| Field | Presence | Description |
|-------|----------|-------------|
| `rank` | Always | 1-based position in ranked results |
| `score` | Always | 0-100 composite score |
| `source` | Always | Primary source: `"playbook"`, `"cdr"`, or `"historical"` |
| `also_in` | When deduplicated | Other sources this route appears in |
| `route_string` | Always | Route string (origin/dest stripped) |
| `metadata.play_name` | Playbook source | Play name from playbook |
| `metadata.play_id` | Playbook source | Play ID for linking |
| `metadata.cdr_code` | CDR source | CDR code |
| `metadata.distance_nm` | When available | Great circle or route distance in NM |
| `metadata.direction` | CDR source | Cardinal direction |
| `statistics` | When `include` has `"statistics"` or source is `"historical"` | Pre-aggregated usage stats |
| `tmi_flags` | When `context.include_active_tmis` is true | Active TMI impact annotations |
| `traversal` | When `include` has `"traversal"` | ARTCC/TRACON traversal list |
| `geometry` | When `include` has `"geometry"` | GeoJSON LineString |

## Data Layer

### New Table: `swim_route_stats` (SWIM_API database)

Pre-aggregated historical route statistics per city pair per normalized route.

```sql
CREATE TABLE swim_route_stats (
    stat_id          INT IDENTITY(1,1) PRIMARY KEY,
    origin_icao      NVARCHAR(4)   NOT NULL,
    dest_icao        NVARCHAR(4)   NOT NULL,
    route_hash       BINARY(16)    NOT NULL,
    normalized_route NVARCHAR(MAX) NOT NULL,
    flight_count     INT           NOT NULL,
    usage_pct        DECIMAL(5,2)  NOT NULL,
    avg_altitude_ft  INT           NULL,
    common_aircraft  NVARCHAR(200) NULL,
    common_operators NVARCHAR(200) NULL,
    first_seen       DATE          NOT NULL,
    last_seen        DATE          NOT NULL,
    last_sync_utc    DATETIME2(0)  NOT NULL
);

CREATE UNIQUE INDEX IX_route_stats_pair_hash
    ON swim_route_stats(origin_icao, dest_icao, route_hash);

CREATE INDEX IX_route_stats_pair_count
    ON swim_route_stats(origin_icao, dest_icao, flight_count DESC)
    INCLUDE (normalized_route, usage_pct, last_seen);
```

**Estimated size**: 50-100K rows (unique city pair + route combos with 5+ flights).

### Stats Sync Logic

Added as a new phase in `swim_tmi_sync_daemon.php`. Runs daily at ~04:00Z.

**Source**: MySQL `route_history_facts` JOIN `dim_route` JOIN `dim_aircraft_type` JOIN `dim_operator`

**Aggregation per city pair per normalized route**:
1. `flight_count` = COUNT(*)
2. `usage_pct` = route count / city pair total * 100
3. `avg_altitude_ft` = AVG(altitude_ft) rounded to nearest 100
4. `common_aircraft` = top 5 ICAO types by frequency, CSV
5. `common_operators` = top 5 airline ICAO codes by frequency, CSV
6. `first_seen` / `last_seen` = MIN/MAX flight_date from dim_time

**Sync pattern**: DELETE all + INSERT fresh (same as other SWIM sync tables). Minimum 5 flights per route to be included.

### Existing Tables Used

| Table | Database | Purpose in Query |
|-------|----------|-----------------|
| `swim_playbook_routes` | SWIM_API | Playbook route search |
| `swim_playbook_plays` | SWIM_API | Play metadata (name, category, source) |
| `swim_coded_departure_routes` | SWIM_API | CDR route search |
| `swim_route_stats` | SWIM_API (new) | Historical usage statistics |
| `tmi_programs` | VATSIM_TMI | Active TMI annotations |

## Query Execution Plan

### Step 1: Parse & Validate

- Validate JSON body (or map GET params)
- Require at least one of `origin` / `destination`
- Validate ICAO codes (4-char alpha)
- Clamp `limit` to 1-100, default 20
- Default `sources` to `["playbook", "cdr", "historical"]`

### Step 2: Source Queries (Sequential on Shared Connection)

**Playbook** (when `"playbook"` in sources):
```sql
SELECT r.route_id, r.route_string, r.origin, r.dest,
       r.origin_airports, r.dest_airports,
       r.origin_artccs, r.dest_artccs, r.origin_tracons, r.dest_tracons,
       r.traversed_artccs, r.traversed_tracons,
       r.route_geometry, r.remarks,
       p.play_name, p.display_name, p.category, p.source, p.status
FROM swim_playbook_routes r
JOIN swim_playbook_plays p ON r.play_id = p.play_id
WHERE p.status = 'active'
  AND p.visibility = 'public'
```

Origin/dest filtering runs in PHP after fetch, using **facility token resolution**:
- Each origin/dest token is classified (airport, ARTCC, TRACON)
- Airport tokens: match against `origin_airports`/`dest_airports` CSV column
- ARTCC tokens: match against `origin_artccs`/`dest_artccs` CSV column
- TRACON tokens: match against `origin_tracons`/`dest_tracons` CSV column
- Multiple tokens use OR logic (route matches if ANY token hits)

All CSV matching uses word-boundary logic: split on comma, trim, exact match per element. This avoids partial ICAO code matches (e.g., `KJFK` won't match `KJFKA`).

Traversal filters (`thru`/`avoid`) applied in PHP against `traversed_artccs` CSV column using the same word-boundary split+match. Text filter applied as `AND (r.route_string LIKE '%text%' OR p.play_name LIKE '%text%' OR r.remarks LIKE '%text%')`.

**CDR** (when `"cdr"` in sources):
```sql
SELECT cdr_id, cdr_code, full_route, origin_icao, dest_icao,
       dep_artcc, arr_artcc, direction, altitude_min_ft, altitude_max_ft
FROM swim_coded_departure_routes
WHERE is_active = 1
```

Origin/dest token resolution for CDRs:
- Airport tokens: `origin_icao IN ('KJFK', 'KEWR', 'KLGA')`
- ARTCC tokens: `dep_artcc IN ('ZNY')` / `arr_artcc IN ('ZLA')`
- TRACON tokens: expand to airport list via reference, then `origin_icao IN (...)`

Direction and altitude filters applied as additional WHERE clauses. Traversal filters limited to `dep_artcc`/`arr_artcc` (CDRs lack en-route traversal data unless geometry is requested).

**Historical** (when `"historical"` in sources):
```sql
SELECT normalized_route, route_hash, flight_count, usage_pct,
       avg_altitude_ft, common_aircraft, common_operators,
       first_seen, last_seen
FROM swim_route_stats
WHERE origin_icao IN ('KJFK', 'KEWR', 'KLGA') AND dest_icao = 'KLAX'
ORDER BY flight_count DESC
```

Origin/dest token resolution for historical stats:
- Airport tokens: direct `IN (...)` clause
- ARTCC/TRACON tokens: expand to airport list via reference, then `IN (...)`

Historical routes do not support `thru`/`avoid` filters natively (no traversal data in stats table). If `include` contains `"traversal"`, PostGIS expansion runs and filters can be applied post-hoc.

### Step 3: TMI Annotation (if requested)

```sql
SELECT program_id, program_type, airport_icao, status,
       current_aar, scope_artccs, start_utc, end_utc
FROM tmi_programs
WHERE status IN ('active', 'pending')
  AND (airport_icao IN ('KJFK', 'KLAX')
       OR scope_artccs LIKE '%ZNY%' OR scope_artccs LIKE '%ZLA%')
```

Each result route is checked against active TMIs:
- GDP/GS at destination airport → `impact: "arrival_delay"` or `impact: "ground_stop"`
- Reroute affecting traversed ARTCCs → `impact: "reroute_active"`
- MIT/AFP in traversed ARTCCs → `impact: "flow_restriction"`

### Step 4: Merge & Deduplicate

1. **Normalize** each route string: strip origin/dest ICAO, collapse whitespace, uppercase
2. **Group** by normalized string
3. **Merge** metadata: primary source = highest-scoring, other sources listed in `also_in`
4. **Combine** statistics from historical source onto any matching playbook/CDR route
5. **Attach** TMI flags to all routes

### Step 5: Rank

**Default scoring** (0-100):

| Component | Points | Logic |
|-----------|--------|-------|
| Historical popularity | 0-50 | `min(50, flight_count / max_count_for_pair * 50)` |
| Source authority | 0-20 | Playbook = 20, CDR = 15, Historical-only = 10 |
| Recency | 0-15 | Last 7d = 15, 30d = 10, 90d = 5, older = 0 |
| TMI compliance | 0-15 | No TMI impact = 15, has impact = 0 (only when TMI context active) |

**Alternative sort modes**:
- `"popularity"`: `flight_count DESC`
- `"distance"`: `distance_nm ASC` (requires geometry expansion)
- `"recency"`: `last_seen DESC`

### Step 6: Enrich (if requested)

**Geometry** (`include: ["geometry"]`):
- Check `route_geometry` column on playbook routes (frozen JSON) — use if available
- Otherwise call `GISService::expandRoutesBatch()` for live PostGIS expansion
- Batch up to 50 routes per PostGIS call

**Traversal** (`include: ["traversal"]`):
- Use pre-computed `traversed_artccs`/`traversed_tracons` from playbook routes
- For CDR/historical routes without traversal data, derive from geometry waypoints

**Statistics** (`include: ["statistics"]`):
- Already loaded from `swim_route_stats` for historical source
- For playbook/CDR routes, JOIN to stats table by matching normalized route + city pair

### Step 7: Return

Paginate with `limit`/`offset`, return `summary` with counts per source and timing.

## Error Handling

| Condition | HTTP | Response |
|-----------|------|----------|
| Missing origin AND destination | 400 | `"At least one of origin or destination is required"` |
| Invalid ICAO code | 400 | `"Invalid ICAO code: XYZ"` |
| Invalid source value | 400 | `"Unknown source: foo. Valid: playbook, cdr, historical"` |
| No results found | 200 | Empty `results` array, `total_results: 0` |
| PostGIS unavailable | 200 | Results without geometry, `warnings: ["geometry_unavailable"]` |
| Historical stats empty | 200 | Playbook/CDR results only, historical source omitted from `sources_hit` |
| TMI DB unavailable | 200 | Results without TMI flags, `warnings: ["tmi_data_unavailable"]` |
| Rate limited | 429 | Standard SWIM rate limit response |

## Caching

- **Cache key**: `md5("route_query:" . json_encode($normalized_request))`
- **Storage**: APCu (existing SWIM pattern)
- **TTL by tier**: system/partner = 60s, developer = 120s, public = 300s
- **TMI flags bypass cache**: Always queried live and attached after cache lookup
- **Geometry**: Cached separately via existing GISService cache layer

## File Structure

```
api/swim/v1/routes/query.php           — Main endpoint (POST + GET handler)
load/services/RouteQueryService.php    — Core query logic, source fan-out, merge, rank
load/services/RouteFilterParser.php    — PHP port of playbook boolean filter parser
database/migrations/swim/058_swim_route_stats.sql  — New table DDL
```

- `query.php`: Auth, input validation, delegates to `RouteQueryService`, formats response
- `RouteQueryService.php`: Stateless service class with methods: `query()`, `queryPlaybook()`, `queryCDR()`, `queryHistorical()`, `resolveFacilityTokens()`, `annotateWithTMI()`, `mergeAndRank()`, `enrichWithGeometry()`
- `RouteFilterParser.php`: Recursive descent parser for boolean filter expressions. PHP port of `assets/js/playbook-filter-parser.js`. Methods: `parse()`, `evaluate()`, `collectTerms()`. Returns AST compatible with `RouteQueryService` evaluation.
- Sync logic added to existing `swim_tmi_sync_daemon.php` as a new phase

## SWIM API Index Update

Add to `api/swim/v1/index.php` endpoint listing:
- `POST /api/swim/v1/routes/query` — Unified route query with multi-source suggestions
- `GET /api/swim/v1/routes/query` — Simple city-pair route lookup (shorthand)

Also document the existing but unlisted endpoints:
- `/routes/cdrs`, `/routes/resolve`
- `/playbook/plays`, `/playbook/analysis`, `/playbook/traversal`, `/playbook/throughput`, `/playbook/facility-counts`

## Migration Numbering

Next available SWIM migration: `058` (after 057 for splits bridge).

## Dependencies

- Existing: `swim_tmi_sync_daemon.php`, `GISService.php`, SWIM auth layer (`auth.php`)
- Route history backfill must be running (populates `route_history_facts` in MySQL)
- PostGIS must be available for geometry enrichment (graceful degradation if not)
