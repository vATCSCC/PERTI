# Route Parsing Algorithm

The route parsing system converts filed flight plan route strings into geographic coordinates, enabling accurate route visualization, distance calculation, and waypoint ETA prediction. The V4 algorithm uses proximity-based resolution and airway-aware disambiguation to handle duplicate fix names correctly.

---

## For Traffic Managers

### What Route Parsing Does

When a pilot files a route like:
```
KJFK DEEZZ5 DEEZZ J36 JEEMY WAVEY2 KIAD
```

The parser converts this into:
1. **Waypoint list** with coordinates
2. **Route geometry** for map display
3. **Segment distances** for ETA calculation
4. **Procedure identification** (SID/STAR)

### Parsed Route Information

| Field | Example | Use |
|-------|---------|-----|
| **Expanded Route** | KJFK DEEZZ J36 JEEMY WAVEY KIAD | Shows actual waypoints |
| **Total Distance** | 228.4 nm | More accurate than GCD |
| **Departure Procedure** | DEEZZ5 | SID identification |
| **Departure Fix** | DEEZZ | Transition point |
| **Arrival Procedure** | WAVEY2 | STAR identification |
| **Arrival Fix** | WAVEY | STAR entry point |

### Why Parsed Distance Matters

| Method | JFK→IAD Example | Accuracy |
|--------|-----------------|----------|
| Great Circle Distance (GCD) | 213 nm | Baseline |
| Parsed Route Distance | 228 nm | +7% (actual route) |

ETAs using parsed routes are more accurate because they account for:
- Airway routing (not direct)
- SID/STAR routing
- Published procedure constraints

### Route Display

On the TSD/Route Plotter, parsed routes show as:
- **Blue line**: Planned route
- **Waypoint markers**: Each fix on the route
- **Segment labels**: Distance between fixes

---

## For Technical Operations

### Monitoring Parse Health

```sql
-- Parse status distribution
SELECT 
    parse_status,
    COUNT(*) AS flights
FROM dbo.adl_flight_plan
WHERE fp_route IS NOT NULL
GROUP BY parse_status;
```

**Expected Distribution:**
| Status | Description | Typical % |
|--------|-------------|-----------|
| COMPLETE | All waypoints resolved | 70-80% |
| PARTIAL | Some waypoints unresolved | 15-25% |
| PENDING | Queued for parsing | 2-5% |
| FAILED | Parse error | <2% |
| NO_ROUTE | No route filed | Variable |

### Parse Queue Management

```sql
-- Current queue depth by tier
SELECT 
    parse_tier,
    status,
    COUNT(*) AS queued
FROM dbo.adl_parse_queue
GROUP BY parse_tier, status
ORDER BY parse_tier;

-- Oldest pending items
SELECT TOP 10
    pq.flight_uid,
    c.callsign,
    pq.parse_tier,
    pq.queued_utc,
    pq.attempts
FROM dbo.adl_parse_queue pq
JOIN dbo.adl_flight_core c ON c.flight_uid = pq.flight_uid
WHERE pq.status = 'PENDING'
ORDER BY pq.queued_utc;
```

**Parse Tiers (Priority):**
| Tier | Criteria | Parse Order |
|------|----------|-------------|
| 1 | Departing from Core30 | Highest |
| 2 | Arriving at Core30 | High |
| 3 | US domestic flights | Medium |
| 4 | International/other | Lower |

### Common Issues

| Symptom | Cause | Resolution |
|---------|-------|------------|
| Many PARTIAL status | Missing nav fixes | Check nav_fixes coverage |
| Queue backing up | Parser too slow | Check batch size settings |
| Wrong route geometry | Duplicate fix names | V4 should handle this |
| Missing procedures | CIFP data outdated | Run procedure import |
| All routes PENDING | Parser not running | Check refresh daemon |

### Fix Resolution Health

```sql
-- Check for unresolved waypoints
SELECT TOP 20
    fw.fix_name,
    COUNT(*) AS unresolved_count
FROM dbo.adl_flight_waypoints fw
WHERE fw.lat IS NULL
GROUP BY fw.fix_name
ORDER BY COUNT(*) DESC;
```

If common fixes are unresolved, check `nav_fixes` table coverage.

---

## For Developers

### Algorithm Overview (V4)

```
PHASE 1: Tokenize route string
    └─► Identify: FIX, AIRWAY, SID, STAR, LATLON, AIRPORT, SPEED_ALT

PHASE 2: Build waypoint list (names only)
    ├─► Expand airways inline
    ├─► Cap SID expansion at 15 waypoints
    └─► Skip STAR expansion (avoid transition bloat)

PHASE 3: Build candidate lookup with airway membership
    └─► ONE query to get all possible fix coordinates

PHASE 4: Sequential coordinate resolution
    ├─► Use proximity to previous fix
    └─► Prefer fixes actually on the current airway

PHASE 5: Build geometry and calculate distances
    └─► Create LineString, compute segment distances

PHASE 6: Save to database
    └─► Update adl_flight_plan + adl_flight_waypoints
```

### Token Type Detection

```sql
-- fn_GetTokenType returns:
SKIP        -- DCT, DIRECT, IFR, VFR
SPEED_ALT   -- N0450F350, FL350, A350
SID         -- DEEZZ5.DEEZZ (ends with digit, has dot)
STAR        -- WAVEY.WAVEY2 (starts with fix, ends with digit)
SID_OR_STAR -- Ambiguous format
AIRWAY      -- J36, V123, Q76, UL607, A15
LATLON      -- 40N73W, N4012W07305
AIRPORT     -- KJFK (4-letter ICAO prefix)
FIX         -- DEEZZ, WAVEY (2-5 letters)
UNKNOWN     -- Unrecognized
```

### Airway Expansion

Airways are expanded to their constituent fixes:

```sql
-- fn_ExpandAirwayNames returns fix sequence
-- Input: J36, entry=DEEZZ, exit=JEEMY
-- Output: DEEZZ, COLIN, JEEMY (if that's the J36 sequence)
```

**Key optimization:** Only fix *names* are returned; coordinates resolved later using proximity.

### Proximity-Based Resolution (V4 Innovation)

Previous versions had a "zigzag problem" where duplicate fix names (e.g., FERDI exists in Europe and Americas) caused incorrect routing.

V4 solution:
```sql
-- Resolve each fix using previous fix's coordinates
SELECT TOP 1 lat, lon
FROM #candidates c
WHERE c.fix_name = @wp_name
ORDER BY 
    -- Prefer candidates on the current airway
    CASE WHEN @current_airway IS NOT NULL 
              AND c.on_airways LIKE '%' + @current_airway + '%' 
         THEN 0 ELSE 1 END,
    -- Then by distance to previous fix
    geography::Point(@prev_lat, @prev_lon, 4326).STDistance(
        geography::Point(c.lat, c.lon, 4326)
    );
```

### Database Tables

**adl_flight_plan** (route metadata):
| Column | Type | Description |
|--------|------|-------------|
| route_geometry | GEOGRAPHY | LineString of route |
| fp_route_expanded | NVARCHAR(MAX) | Expanded waypoint list |
| route_total_nm | DECIMAL(10,2) | Total route distance |
| route_dist_nm | DECIMAL(10,2) | Distance remaining (updated live) |
| parse_status | VARCHAR(20) | COMPLETE/PARTIAL/PENDING/FAILED |
| parse_utc | DATETIME2(0) | When parsed |
| dp_name | VARCHAR(16) | Departure procedure |
| dfix | VARCHAR(8) | Departure fix |
| star_name | VARCHAR(16) | Arrival procedure |
| afix | VARCHAR(8) | Arrival fix |
| waypoint_count | INT | Number of resolved waypoints |

**adl_flight_waypoints** (individual waypoints):
| Column | Type | Description |
|--------|------|-------------|
| waypoint_id | BIGINT | Primary key |
| flight_uid | BIGINT | Flight reference |
| sequence_num | INT | Order in route |
| fix_name | VARCHAR(50) | Waypoint identifier |
| lat | FLOAT | Latitude |
| lon | FLOAT | Longitude |
| position_geo | GEOGRAPHY | Point geometry |
| fix_type | VARCHAR(20) | AIRPORT/WAYPOINT/COORD |
| source | VARCHAR(20) | ORIGIN/ROUTE/AIRWAY/SID/STAR/DESTINATION |
| on_airway | VARCHAR(10) | Which airway (if any) |
| on_dp | VARCHAR(20) | Which SID (if any) |
| on_star | VARCHAR(20) | Which STAR (if any) |
| segment_dist_nm | DECIMAL(10,2) | Distance from previous |
| cum_dist_nm | DECIMAL(10,2) | Cumulative distance |

### Reference Data Dependencies

| Table | Source | Update Frequency |
|-------|--------|------------------|
| nav_fixes | FAA NASR + X-Plane | Every AIRAC cycle |
| airways | FAA NASR | Every AIRAC cycle |
| nav_procedures | FAA CIFP | Every AIRAC cycle |

### Batch Processing

Route parsing is handled by the **GIS daemon** (`adl/php/parse_queue_gis_daemon.php`), which picks up queued routes from `adl_parse_queue` and processes them using PostGIS for spatial operations. The daemon runs continuously with 10-second batch cycles.

Processing is tiered to prioritize operationally significant routes:
- Tier 1-2: Parse immediately (Core30 traffic)
- Tier 3-4: Parse as resources allow

### Performance Targets

| Metric | Target |
|--------|--------|
| Parse time per route | < 200ms |
| Fix resolution | Single query for all candidates |
| Airway expansion | In-memory (no additional queries) |
| Batch throughput | 50 routes/cycle |

### Integration Points

```
sp_Adl_RefreshFromVatsim_Staged (V9.4.0)
    │
    ├─► Step 4: Detect route changes (hash comparison)
    │
    ├─► Step 5: Queue new routes → adl_parse_queue
    │
    └─► Step 5b: Update route distances (Route Distance V2.2)

parse_queue_gis_daemon.php (GIS daemon, continuous)
    │
    ├─► Pick up PENDING routes from adl_parse_queue
    │
    └─► Process batch via PostGIS
            │
            ├─► Tokenization (V4 algorithm)
            ├─► Airway expansion
            ├─► Proximity-based coordinate resolution
            └─► Geometry building (PostGIS LINESTRING)
```

### Debugging

```sql
-- Debug parse for specific flight
EXEC dbo.sp_ParseRoute 
    @flight_uid = 12345, 
    @debug = 1;
```

Debug output includes:
- Token list with types
- Waypoint resolution attempts
- Unresolved fixes
- Final geometry details

---

## Related Documentation

- [[Algorithm-ETA-Calculation]] - Uses route distance for ETA
- [[Route-Plotter]] - Displays parsed routes
- [[NASR Navigation Data Updater|Maintenance#nasr-updates]] - Fix/airway data source
- [[Troubleshooting]] - Parse failure debugging

