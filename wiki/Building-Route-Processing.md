# Building Your Own Route Processing

How to build a route parsing and navdata system that resolves aviation route strings into geographic coordinates, geometry, and facility traversals — matching PERTI's capabilities.

---

## What PERTI's Route Parser Does

Given a real filed route string (BAW545, London Heathrow → Hamburg):
```
BPK Q295 SOMVA DCT MAVAS DCT VALAM DCT GASTU DCT OSTOR T904 RIBSO RIBSO3P
```

PERTI's PostGIS pipeline produces:

| Output | Real Result |
|--------|-------------|
| **Resolved waypoints** | `BPK: 51.7497°N, 0.1067°W` → `TOTRI: 51.7750°N, 0.1967°E` → `MATCH: 51.7792°N, 0.2500°E` → `BRAIN: 51.8111°N, 0.6517°E` → `PAAVO: 51.8636°N, 0.8544°E` → `SOMVA: 52.3072°N, 2.6440°E` → ... → `RIBSO: 53.8128°N, 9.3402°E` |
| **Airway expansion** | `Q295` between BPK and SOMVA → 4 intermediate fixes inserted (TOTRI, MATCH, BRAIN, PAAVO) |
| **STAR detection** | `RIBSO3P` |
| **Route distance** | `367 nm` (vs. 340 nm great circle) |
| **Waypoint count** | `10` |

Another example — CDR JFKLAX1D (from `dbo.coded_departure_routes`):
```
KJFK GREKI JUDDS CAM BUGSY POLTY UBTIX ULAMO STNRD COLDD EXHOS SWTHN DNW FFU J9 MLF WINEN Q73 HAKMN ANJLL4 KLAX
```
Produces 24 waypoints, 2615.6 nm route distance, traversing `ZNY → ZBW → CZUL → CZYZ → ZMP → ZLC → ZLA`.

---

## Architecture

```
┌─────────────┐    ┌─────────────┐    ┌──────────────────┐    ┌──────────────┐
│ Route String │───▶│  Tokenizer  │───▶│ Waypoint Resolver │───▶│ Geometry     │
│              │    │             │    │                  │    │ Builder      │
└─────────────┘    └─────────────┘    └──────────────────┘    └──────┬───────┘
                                                                     │
                   ┌─────────────┐    ┌──────────────────┐           │
                   │  Traversal  │◀───│ Boundary Overlay  │◀──────────┘
                   │  Output     │    │ (ST_Intersects)  │
                   └─────────────┘    └──────────────────┘
```

**Components:**
1. **Tokenizer** — split route string, classify tokens (airport, fix, airway, coordinate, procedure)
2. **Waypoint Resolver** — convert tokens to lat/lon using navdata with proximity disambiguation
3. **Airway Expander** — insert intermediate fixes between airway entry/exit points
4. **Geometry Builder** — connect waypoints into a GeoJSON LineString
5. **Boundary Overlay** — intersect geometry with ARTCC/TRACON/sector polygons (requires PostGIS)

---

## Step 1: Acquire Navdata

### Required Data Sources

| Source | URL | Update Cycle | What You Get |
|--------|-----|-------------|--------------|
| **FAA NASR** | `https://nfdc.faa.gov/webContent/28DaySub/` | 28-day AIRAC | Fixes, navaids, airways, airports, SIDs, STARs, CDRs (US only) |
| **X-Plane 12 / Navigraph** | Navigraph subscription or X-Plane install | 28-day AIRAC | International fixes, airways, CIFP procedures (global) |
| **OurAirports** | `https://ourairports.com/data/` | Continuous | 37K+ global airports with coordinates |

**US-only minimum**: NASR alone gives you full CONUS + Alaska + Hawaii + territories.

**Global coverage**: Add X-Plane/Navigraph data for international airways and procedures.

### NASR Files You Need

Download the 28-day subscription ZIP from NASR. Extract:

| File | Content |
|------|---------|
| `FIX_BASE.csv` | Waypoint fixes (~270K intersections, reporting points) |
| `NAV_BASE.csv` | Navaids (VORs, NDBs, TACANs, DMEs) |
| `APT_BASE.csv` | US airports with coordinates |
| `AWY_SEG_ALT.csv` | Airway segment definitions (from_fix → to_fix per segment) |
| `DP_BASE.csv` + `DP_RTE.csv` | Departure Procedures (SIDs) |
| `STAR_BASE.csv` + `STAR_RTE.csv` | Standard Terminal Arrivals |
| `CDR.csv` | Coded Departure Routes |

### X-Plane/Navigraph Files (International)

| File | Content |
|------|---------|
| `earth_fix.dat` | International waypoints |
| `earth_nav.dat` | International navaids |
| `earth_awy.dat` | International airways |
| `CIFP/*.dat` | ARINC 424 procedures per airport |

---

## Step 2: Build Your Database

### Minimum Schema (5 Tables)

#### `nav_fixes` — Waypoints, VORs, NDBs

```sql
CREATE TABLE nav_fixes (
    fix_name    VARCHAR(32) NOT NULL,
    lat         DECIMAL(10,7) NOT NULL,
    lon         DECIMAL(11,7) NOT NULL,
    fix_type    VARCHAR(16),
    source      VARCHAR(8)
);
CREATE INDEX idx_fixes_name ON nav_fixes(fix_name);
```

**Real data from PERTI's 384,106-fix database:**

| fix_name | lat | lon | fix_type |
|----------|-----|-----|----------|
| GREKI | 41.4800083 | -73.3141611 | WAYPOINT |
| MERIT | 41.3819500 | -73.1374306 | WAYPOINT |
| DEEZZ | 41.1144444 | -73.7777778 | WAYPOINT |
| CAMRN | 40.0173028 | -73.8610583 | WAYPOINT |
| WAVEY | 40.2345833 | -73.3943778 | WAYPOINT |
| HEMEL | 51.8055560 | -0.4193830 | WAYPOINT |
| NIKOL | 37.9674667 | -118.6825528 | WAYPOINT |

**Critical — fix names are NOT globally unique.** From production data:

`BPK` resolves to **4 different locations**:
| lat | lon | Where |
|-----|-----|-------|
| 51.7496986 | -0.1066670 | Brookmans Park, UK |
| 51.7497361 | -0.1067361 | Brookmans Park, UK (duplicate entry) |
| 36.3689425 | -92.4705197 | Arkansas, USA |
| -34.6166667 | 138.4683333 | Australia |

`NIKOL` resolves to **3 locations**: California (37.97°N), Russia (43.17°N, 132.81°E), and Croatia (44.22°N, 13.69°E).

Without proximity disambiguation, your parser will randomly pick one and plot routes through the wrong continent.

#### `airports` — Global Airports

```sql
CREATE TABLE airports (
    icao_id     VARCHAR(4) PRIMARY KEY,
    faa_id      VARCHAR(4),
    lat         DECIMAL(10,7) NOT NULL,
    lon         DECIMAL(11,7) NOT NULL,
    name        VARCHAR(100),
    country     VARCHAR(4)
);
```

**Real data from PERTI's 37,527-airport database:**

| icao_id | faa_id | lat | lon | name |
|---------|--------|-----|-----|------|
| KJFK | JFK | 40.6399280 | -73.7786920 | JOHN F KENNEDY INTL |
| KLAX | LAX | 33.9424960 | -118.4080490 | LOS ANGELES INTL |
| KATL | ATL | 33.6367000 | -84.4278640 | HARTSFIELD/JACKSON ATLANTA INTL |
| KORD | ORD | 41.9769400 | -87.9081500 | CHICAGO O'HARE INTL |
| KMCO | MCO | 28.4293890 | -81.3090000 | ORLANDO INTL |
| EGLL | EGLL | 51.4706000 | -0.4619410 | London Heathrow Airport |
| LFPG | LFPG | 49.0127980 | 2.5500000 | Charles de Gaulle International Airport |
| RJTT | RJTT | 35.5522990 | 139.7799990 | Tokyo Haneda International Airport |

Note: US airports have 3-letter FAA codes (`JFK`, `ATL`, `LAX`). International airports use the ICAO code as both `icao_id` and `faa_id`.

#### `airway_segments` — Named Route Segments

```sql
CREATE TABLE airway_segments (
    airway_name VARCHAR(8) NOT NULL,
    sequence_num INT NOT NULL,
    from_fix    VARCHAR(32) NOT NULL,
    to_fix      VARCHAR(32) NOT NULL,
    from_lat    DECIMAL(10,7) NOT NULL,
    from_lon    DECIMAL(11,7) NOT NULL,
    to_lat      DECIMAL(10,7) NOT NULL,
    to_lon      DECIMAL(11,7) NOT NULL,
    distance_nm DECIMAL(8,1)
);
CREATE INDEX idx_airway_name ON airway_segments(airway_name);
CREATE INDEX idx_airway_fix ON airway_segments(airway_name, from_fix);
```

**Real J80 airway data from PERTI's 89,559-segment database:**

The same airway name `J80` exists in two completely different regions:

**J80 (US: Nevada → Colorado)**
| seq | from_fix | to_fix | from_lat | from_lon | to_lat | to_lon | dist_nm |
|-----|----------|--------|----------|----------|--------|--------|---------|
| 1 | OAL | ILC | 38.0032614 | -117.7704458 | 38.2501928 | -114.3942264 | 160.1 |
| 2 | ILC | MLF | 38.2501928 | -114.3942264 | 38.3603556 | -113.0132328 | 65.4 |
| 3 | MLF | SAKES | 38.3603556 | -113.0132328 | 38.8334750 | -110.2712556 | 131.8 |
| 4 | SAKES | JNC | 38.8334750 | -110.2712556 | 39.0595656 | -108.7925739 | 70.4 |
| 5 | JNC | GLENO | 39.0595656 | -108.7925739 | 39.3477333 | -107.3689139 | 68.5 |
| 6 | GLENO | DBL | 39.3477333 | -107.3689139 | 39.4393453 | -106.8946803 | 22.7 |

**J80 (India: Allahabad → Gorakhpur)**
| seq | from_fix | to_fix | from_lat | from_lon | dist_nm |
|-----|----------|--------|----------|----------|---------|
| 1 | ALH | AYD | 25.4438056 | 81.7233972 | 81.9 |
| 2 | AYD | GKP | 26.7466278 | 82.1726778 | 67.9 |

**Your parser must validate that BOTH the entry and exit fix exist on the same airway variant** before expanding. Otherwise `J80 MLF SAKES` might accidentally use the Indian variant.

#### `artcc_boundaries` — ATC Center Polygons (for traversal)

```sql
CREATE TABLE artcc_boundaries (
    artcc_code  VARCHAR(4) NOT NULL,
    fir_name    VARCHAR(64),
    geom        GEOMETRY(MultiPolygon, 4326),
    is_oceanic  BOOLEAN DEFAULT FALSE
);
CREATE INDEX idx_artcc_geom ON artcc_boundaries USING GIST(geom);
```

**Boundary sources:**
- US ARTCC/Sectors: [vIFF CDM project](https://github.com/rpuig2001/vIFF-Capacity-Availability-Document) (GeoJSON)
- TRACONs: [SimAware TRACON project](https://github.com/vatsimnetwork/simaware-tracon-project) (GeoJSON)

PERTI's boundary database: 1,004 ARTCC boundaries + 1,203 TRACONs + 4,085 sectors.

#### `area_centers` — ARTCC/TRACON Names as Fixes

```sql
CREATE TABLE area_centers (
    center_code VARCHAR(8) NOT NULL,
    center_type VARCHAR(8),
    lat         DECIMAL(10,7),
    lon         DECIMAL(11,7)
);
```

**Real data from PERTI's 53 area center records:**

| center_code | center_type | lat | lon |
|-------------|-------------|-----|-----|
| ZNY | ARTCC | 40.7128000 | -74.0060000 |
| ZDC | ARTCC | 38.9072000 | -77.0369000 |
| ZBW | ARTCC | 42.3601000 | -71.0589000 |
| ZJX | ARTCC | 30.3322000 | -81.6557000 |
| ZLA | ARTCC | 34.0522000 | -118.2437000 |
| N90 | TRACON | 40.7831000 | -73.9712000 |

Route strings sometimes reference center codes as waypoints (e.g., `DCT ZMP DCT` means "direct to Minneapolis Center"). Your resolver needs to check this table as a fallback.

---

## Step 3: Tokenize the Route String

Split the route string into typed tokens. PERTI splits on whitespace (`\s+`) and classifies each token.

Using the real BAW545 route: `BPK Q295 SOMVA DCT MAVAS DCT VALAM DCT GASTU DCT OSTOR T904 RIBSO RIBSO3P`

```python
import re

def tokenize_route(route_string):
    tokens = re.split(r'\s+', route_string.strip())
    classified = []

    for token in tokens:
        if token == 'DCT':
            continue  # Direct — no intermediate processing needed

        # Strip procedure notation: "KJFK.DEEZZ5" → "KJFK"
        if '.' in token:
            token = token.split('.')[0]

        # Skip pseudo-fixes
        if token in ('UNKN', 'VARIOUS'):
            continue

        classified.append(classify_token(token))

    return classified
```

### Token Classification

```python
def classify_token(token):
    # 1. Airport (4-char ICAO)
    if re.match(r'^[A-Z]{4}$', token) and is_airport(token):
        return ('airport', token)

    # 2. Airway (J/Q/V/T + digits, or named airways like A1, UB881, T904)
    if re.match(r'^[JQVT]\d+$', token) or re.match(r'^[A-Z]{1,3}\d+[A-Z]?$', token):
        if is_airway(token):
            return ('airway', token)

    # 3. Coordinate token — 5 aviation formats (see below)
    coord = parse_coordinate(token)
    if coord:
        return ('coordinate', coord)

    # 4. SID/STAR — matches pattern but not a nav fix
    if re.match(r'^[A-Z]{3,5}\d[A-Z]?$', token) and not is_fix(token) and is_procedure(token):
        return ('procedure', token)

    # 5. Nav fix (default)
    return ('fix', token)
```

**Real tokenization of BAW545's route:**

| Token | Classification |
|-------|---------------|
| `BPK` | fix (nav_fix at 51.7497°N, 0.1067°W — Brookmans Park VOR, UK) |
| `Q295` | airway |
| `SOMVA` | fix |
| `MAVAS` | fix |
| `VALAM` | fix |
| `GASTU` | fix |
| `OSTOR` | fix |
| `T904` | airway |
| `RIBSO` | fix |
| `RIBSO3P` | procedure (STAR into EDDH) |

### Coordinate Formats

Aviation uses 5 coordinate encoding formats. All are found in real VATSIM flight plans:

| Format | Example | Decoded | Usage |
|--------|---------|---------|-------|
| **ICAO compact** | `4520N07350W` | 45.333°N, 73.833°W | Global standard |
| **NAT slash** | `45/73` | 45°N, 73°W | North Atlantic Tracks |
| **NAT half-degree** | `H4573` | 45.5°N, 73.5°W | NAT half-degree points |
| **ARINC trailing** | `4573N` | 45°N, 73°W | ARINC 424 |
| **ARINC middle** | `45N73` | 45°N, 73°W | ARINC 424 |

```python
def parse_coordinate(token):
    # ICAO compact: 4520N07350W
    m = re.match(r'^(\d{2})(\d{2})([NS])(\d{3})(\d{2})([EW])$', token)
    if m:
        lat = int(m[1]) + int(m[2]) / 60.0
        lon = int(m[4]) + int(m[5]) / 60.0
        if m[3] == 'S': lat = -lat
        if m[6] == 'W': lon = -lon
        return (lat, lon)

    # NAT slash: 45/73 (always N latitude, W longitude)
    m = re.match(r'^(\d{2})/(\d{2,3})$', token)
    if m:
        return (float(m[1]), -float(m[2]))

    # NAT half-degree: H4573
    m = re.match(r'^H(\d{2})(\d{2})$', token)
    if m:
        return (float(m[1]) + 0.5, -(float(m[2]) + 0.5))

    # ARINC trailing hemisphere: 4573N
    m = re.match(r'^(\d{2})(\d{2})([NSEW])$', token)
    if m:
        lat, lon = float(m[1]), float(m[2])
        if m[3] in ('S',): lat = -lat
        if m[3] in ('W',): lon = -lon
        return (lat, -lon)

    # ARINC middle hemisphere: 45N73
    m = re.match(r'^(\d{2})([NS])(\d{2,3})$', token)
    if m:
        lat = float(m[1])
        lon = float(m[3])
        if m[2] == 'S': lat = -lat
        return (lat, -lon)

    return None
```

---

## Step 4: Resolve Waypoints with Proximity Disambiguation

The hardest part. Use the **previous resolved waypoint** as context to pick the nearest match when fix names repeat.

### Algorithm

```python
def resolve_route(tokens):
    waypoints = []
    prev_lat, prev_lon = None, None

    i = 0
    while i < len(tokens):
        token_type, token_value = tokens[i]

        if token_type == 'airport':
            wp = lookup_airport(token_value)
            waypoints.append(wp)
            prev_lat, prev_lon = wp['lat'], wp['lon']

        elif token_type == 'coordinate':
            lat, lon = token_value
            waypoints.append({'id': f'{lat:.0f}/{lon:.0f}', 'lat': lat, 'lon': lon})
            prev_lat, prev_lon = lat, lon

        elif token_type == 'airway':
            # Need entry fix (previous) and exit fix (next token)
            entry_fix = waypoints[-1]['id'] if waypoints else None
            exit_fix = tokens[i + 1][1] if i + 1 < len(tokens) else None

            if entry_fix and exit_fix:
                expanded = expand_airway(token_value, entry_fix, exit_fix, prev_lat, prev_lon)
                for wp in expanded[1:]:  # Skip entry fix (already in list)
                    waypoints.append(wp)
                    prev_lat, prev_lon = wp['lat'], wp['lon']

        elif token_type == 'fix':
            wp = resolve_fix(token_value, prev_lat, prev_lon)
            if wp:
                # Distance validation: reject fixes > 4000nm from previous
                if prev_lat is not None:
                    dist = haversine(prev_lat, prev_lon, wp['lat'], wp['lon'])
                    if dist > 4000:
                        i += 1
                        continue  # Wrong hemisphere match — skip
                waypoints.append(wp)
                prev_lat, prev_lon = wp['lat'], wp['lon']

        elif token_type == 'procedure':
            pass  # SID/STAR name — skip, or optionally look up the procedure's fix sequence

        i += 1

    return waypoints
```

### Fix Resolution with Proximity

```python
def resolve_fix(fix_name, prev_lat=None, prev_lon=None):
    """Find the closest fix matching this name to the previous waypoint."""

    # 1. Nav fixes (most common — waypoints, VORs, NDBs)
    candidates = db.query(
        "SELECT fix_name, lat, lon FROM nav_fixes WHERE fix_name = %s", fix_name
    )

    # 2. Airport by ICAO code
    if not candidates:
        candidates = db.query(
            "SELECT icao_id AS fix_name, lat, lon FROM airports WHERE icao_id = %s", fix_name
        )

    # 3. Airport by FAA code (3-letter: BOS, JFK, ATL)
    if not candidates:
        candidates = db.query(
            "SELECT icao_id AS fix_name, lat, lon FROM airports WHERE faa_id = %s", fix_name
        )

    # 4. K-prefix conversion (BOS → KBOS)
    if not candidates:
        candidates = db.query(
            "SELECT icao_id AS fix_name, lat, lon FROM airports WHERE icao_id = %s",
            'K' + fix_name
        )

    # 5. Area center (ZNY, ZDC, N90)
    if not candidates:
        candidates = db.query(
            "SELECT center_code AS fix_name, lat, lon FROM area_centers WHERE center_code = %s",
            fix_name
        )

    if len(candidates) == 1:
        return candidates[0]

    if len(candidates) > 1 and prev_lat is not None:
        # Sort by distance to previous waypoint, pick closest
        candidates.sort(key=lambda c: haversine(prev_lat, prev_lon, c['lat'], c['lon']))
        return candidates[0]

    return candidates[0] if candidates else None
```

**Real example**: resolving `BPK` in the BAW545 route.

The previous waypoint is EGLL (51.47°N, 0.46°W). The `nav_fixes` table returns 4 candidates for `BPK`:

| lat | lon | Distance from EGLL |
|-----|-----|--------------------|
| 51.7497 | -0.1067 | **19 nm** (UK — correct) |
| 51.7497 | -0.1067 | 19 nm (UK duplicate) |
| 36.3689 | -92.4706 | 4,290 nm (Arkansas, USA) |
| -34.6167 | 138.4683 | 10,104 nm (Australia) |

Proximity disambiguation correctly picks the UK entry (19 nm away). Without it, you'd have a 1-in-4 chance of the wrong answer.

### Airway Expansion

**Real example**: Q295 between BPK and SOMVA in the BAW545 route.

From production `adl_flight_waypoints` for BAW545:

| seq | fix_name | lat | lon | fix_type |
|-----|----------|-----|-----|----------|
| 1 | BPK | 51.7496986 | -0.1066670 | nav_fix |
| 2 | TOTRI | 51.7750000 | 0.1966670 | airway_Q295 |
| 3 | MATCH | 51.7792222 | 0.2500000 | airway_Q295 |
| 4 | BRAIN | 51.8110861 | 0.6516667 | airway_Q295 |
| 5 | PAAVO | 51.8636110 | 0.8544440 | airway_Q295 |
| 6 | SOMVA | 52.3072220 | 2.6440110 | airway_Q295 |

The parser found BPK and SOMVA on Q295, extracted the segment between them, and inserted 4 intermediate fixes (TOTRI → MATCH → BRAIN → PAAVO).

```python
def expand_airway(airway_name, entry_fix, exit_fix, context_lat, context_lon):
    """Expand an airway between two fixes, inserting intermediate waypoints."""

    # Get all segments for this airway
    segments = db.query("""
        SELECT from_fix, to_fix, from_lat, from_lon, to_lat, to_lon, sequence_num
        FROM airway_segments
        WHERE airway_name = %s
        ORDER BY sequence_num
    """, airway_name)

    # Build ordered fix list per airway variant
    # (same airway name may exist in different regions — J80 in US vs India)
    variants = build_fix_chains(segments)

    # Find the variant where BOTH entry and exit fix exist
    for chain in variants:
        fix_names = [f['fix_name'] for f in chain]
        if entry_fix in fix_names and exit_fix in fix_names:
            entry_idx = fix_names.index(entry_fix)
            exit_idx = fix_names.index(exit_fix)

            # Extract segment (handles both forward and reverse traversal)
            if entry_idx <= exit_idx:
                segment = chain[entry_idx:exit_idx + 1]
            else:
                segment = chain[exit_idx:entry_idx + 1][::-1]

            # Validate: no segment > 2000km (catches wrong-hemisphere data)
            for j in range(1, len(segment)):
                dist = haversine(segment[j-1]['lat'], segment[j-1]['lon'],
                                segment[j]['lat'], segment[j]['lon'])
                if dist > 1080:  # ~2000km
                    break
            else:
                return segment

    return []  # Neither variant matched — skip expansion
```

---

## Step 5: Build Geometry

Convert resolved waypoints to a GeoJSON LineString:

```python
def build_geometry(waypoints):
    return {
        "type": "LineString",
        "coordinates": [[wp['lon'], wp['lat']] for wp in waypoints]
    }
```

### Calculate Route Distance

```python
from math import radians, sin, cos, asin, sqrt

def haversine(lat1, lon1, lat2, lon2):
    """Distance in nautical miles."""
    R = 3440.065  # Earth radius in nm
    dlat = radians(lat2 - lat1)
    dlon = radians(lon2 - lon1)
    a = sin(dlat/2)**2 + cos(radians(lat1)) * cos(radians(lat2)) * sin(dlon/2)**2
    return 2 * R * asin(sqrt(a))

def route_distance(waypoints):
    total = 0
    for i in range(1, len(waypoints)):
        total += haversine(
            waypoints[i-1]['lat'], waypoints[i-1]['lon'],
            waypoints[i]['lat'], waypoints[i]['lon']
        )
    return round(total, 1)
```

---

## Step 6: Facility Traversal (Requires PostGIS)

If you need to know which ARTCCs/TRACONs/sectors a route passes through, you need a spatial database.

### PostGIS Setup

```sql
CREATE EXTENSION postgis;

-- Load boundary polygons (from vIFF CDM GeoJSON)
CREATE TABLE artcc_boundaries (
    artcc_code VARCHAR(4),
    fir_name   VARCHAR(64),
    geom       GEOMETRY(MultiPolygon, 4326)
);
CREATE INDEX idx_artcc_geom ON artcc_boundaries USING GIST(geom);
```

### Traversal Query

Build a LineString from your waypoints, then intersect with boundary polygons:

```sql
-- Build route geometry from waypoint array
WITH route AS (
    SELECT ST_MakeLine(
        ARRAY[
            ST_SetSRID(ST_MakePoint(-73.7786922, 40.6399281), 4326),  -- KJFK
            ST_SetSRID(ST_MakePoint(-73.3141611, 41.4800083), 4326),  -- GREKI
            ST_SetSRID(ST_MakePoint(-73.1082444, 41.6346722), 4326),  -- JUDDS
            -- ... more waypoints ...
            ST_SetSRID(ST_MakePoint(-118.4080490, 33.9424960), 4326)  -- KLAX
        ]
    ) AS geom
)
SELECT DISTINCT ON (ab.artcc_code)
    ab.artcc_code,
    ST_LineLocatePoint(r.geom, ST_Centroid(ST_Intersection(ab.geom, r.geom))) AS traversal_order
FROM artcc_boundaries ab, route r
WHERE ST_Intersects(ab.geom, r.geom)
  AND ST_IsValid(ab.geom)
ORDER BY ab.artcc_code, traversal_order;
```

**Important**: Always pre-filter with `ST_IsValid(geom)`. PERTI's production data has 108/1,203 TRACONs and 265/4,085 sectors with invalid geometry (unclosed LinearRings) that cause `ST_Intersects()` to throw GEOS errors. You **cannot** rely on `WHERE ST_IsValid(geom) AND ST_Intersects(geom, route)` — PostgreSQL doesn't guarantee evaluation order. Use a subquery:

```sql
FROM (SELECT * FROM artcc_boundaries WHERE ST_IsValid(geom)) ab
```

---

## Common Pitfalls

### 1. Duplicate Fix Names

`BPK` exists in 4 locations (UK, USA, Australia, UK duplicate). `NIKOL` exists in 3 (California, Russia, Croatia). Without proximity disambiguation, your parser will randomly pick one.

**Fix**: Always pass the previous waypoint's coordinates to the resolver. Sort candidates by distance, pick the nearest.

### 2. Airway Name Collisions

`J80` exists as an airway over Nevada/Utah/Colorado (OAL → ILC → MLF → SAKES → JNC → GLENO → DBL) AND over India (ALH → AYD → GKP). Both share the same `airway_name` in the database with overlapping `sequence_num` values.

**Fix**: Require BOTH the entry and exit fix to exist on the same airway variant before expanding. Build separate chains per connected-component of segments.

### 3. FAA 3-Letter Airport Codes

Pilots file `MCO` (not `KMCO`), `JFK` (not `KJFK`) in route strings. Your resolver must try K-prefix conversion as a fallback.

From PERTI's real data: DAL2820 filed `KMYR` → `KATL` with route `CAE SKWKR JJEDI4`. The `CAE` token resolves to the Columbia VOR (nav_fix), not the airport — context matters.

### 4. Procedure Notation

Route strings include SID/STAR names: BAW545 filed `RIBSO3P` (a STAR into EDDH). This is not a fix — it's a procedure reference. PERTI's parser detected the STAR and set `star_name = 'RIBSO3P'` while resolving the base fix `RIBSO` (53.8128°N, 9.3402°E) as the last nav waypoint.

Detection heuristic: token matches `^[A-Z]{3,5}\d[A-Z]?$` and is NOT in `nav_fixes` but IS in `nav_procedures`.

### 5. Distance Validation (4000nm Cap)

After resolving each waypoint, check it's within 4000nm of the previous one. Larger gaps indicate a wrong-hemisphere disambiguation. PERTI's `expand_route()` enforces a 7400km (~4000nm) cap between consecutive waypoints.

### 6. NAT Coordinate Conventions

NAT slash format (`45/73`) always means North latitude, West longitude. This is a fixed convention for the North Atlantic — don't infer hemisphere from context.

---

## Data Maintenance

### AIRAC Update Cycle

Aviation navdata updates every 28 days. Your pipeline:

1. **Download** new NASR ZIP from `nfdc.faa.gov` on effective date
2. **Parse** CSV files into your database (UPSERT pattern — don't truncate)
3. **Track supersessions** — fixes move, airways get renumbered, procedures change
4. **Validate** a known route produces the same result

PERTI's AIRAC 2603 changelog (March 2026) contained **179,127 entries**: 89K fixes added, 87K removed, 1,203 moved, 1,281 airway segments modified.

### Supersession Tracking

Track `is_superseded` flags on navdata rows rather than deleting old data, so historical route lookups still work.

---

## Scale Reference

PERTI's production navdata database (PostGIS):

| Table | Rows |
|-------|------|
| nav_fixes | 384,106 |
| nav_procedures | 105,562 |
| playbook_routes | 96,019 |
| airway_segments | 89,559 |
| coded_departure_routes | 47,141 |
| airports | 37,527 |
| airways | 16,988 |
| sector_boundaries | 4,085 |
| tracon_boundaries | 1,203 |
| artcc_boundaries | 1,004 |
| area_centers | 53 |

Parse performance: ~14 routes/second on a 1-vCPU B1ms PostgreSQL instance including airway expansion and ARTCC traversal. A 4-vCPU instance handles ~100 routes/second.

---

## Or Just Use VATSWIM

If this seems like more effort than it's worth — it probably is for most use cases. The [[SWIM Route Data Integration|SWIM-Route-Data-Integration]] page documents how to submit route strings to VATSWIM's `POST /playbook/traversal` endpoint and get back waypoints, geometry, distance, and traversals without maintaining any of this yourself.

Build your own only if you need:
- Offline/airgapped operation
- Custom disambiguation logic
- Sub-second latency for thousands of routes
- Control over navdata update timing
- Non-VATSIM route processing
