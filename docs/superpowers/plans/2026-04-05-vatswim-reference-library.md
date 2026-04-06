# VATSWIM Reference Library Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a comprehensive public reference data API for VATSIM developers, exposing aviation reference data across 10 domains through the existing VATSWIM API layer.

**Architecture:** REST endpoints under `/api/swim/v1/reference/{domain}`, querying source databases directly (PostGIS, VATSIM_REF, VATSIM_ADL, MySQL) with response caching. Includes a browsable geographic hierarchy and static bulk download files. All endpoints require SWIM API key auth.

**Tech Stack:** PHP 8.2, PostGIS/PDO, Azure SQL/sqlsrv, MySQL/PDO, nginx, existing SWIM infrastructure (SwimAuth, SwimResponse, SwimFormat)

**Spec:** `docs/superpowers/specs/2026-04-05-vatswim-reference-library-design.md`

---

## File Structure

### New Files to Create

```
api/swim/v1/reference/
  airports.php        -- Airport lookups, profiles, facilities, runways, search
  navigation.php      -- Fixes, airways, procedures (DP/STAR)
  airspace.php        -- Boundaries, sectors, FIRs, point-in-polygon
  facilities.php      -- Center/TRACON lists, tier adjacency, curated lists
  aircraft.php        -- Types, families, performance
  airlines.php        -- Operator codes, callsigns
  routes.php          -- Popular routes, city-pair statistics
  airac.php           -- Cycle metadata, changelogs, superseded data
  utilities.php       -- Distance, bearing, route decode
  hierarchy.php       -- Geographic tree navigation
  bulk.php            -- Catalog and file downloads

assets/data/hierarchy.json           -- Static hierarchy reference data
scripts/reference/generate_bulk.php  -- Bulk file generation script
```

### Files to Modify

```
default                              -- nginx routing rules (add reference/* catch-all)
load/swim_config.php                 -- Cache TTL entries for new domains
api/swim/v1/.htaccess                -- Apache rewrite rules for reference sub-paths
```

---

## Key Patterns (Reference)

All new endpoint files follow the exact pattern from `api/swim/v1/reference/taxi-times.php`:

```php
<?php
require_once __DIR__ . '/../auth.php';
$auth = swim_init_auth(true, false);  // require auth, read-only

// Path parsing
$request_uri = $_SERVER['REQUEST_URI'] ?? '';
$path = parse_url($request_uri, PHP_URL_PATH);
$path = preg_replace('#^.*/reference/{domain}/?#', '', $path);
$path_parts = array_values(array_filter(explode('/', $path)));

// Parameters
$format = swim_validate_format(swim_get_param('format', 'json'), 'reference');

// Cache check
if (SwimResponse::tryCachedFormatted('reference', $cache_params, $format, $format_options)) { exit; }

// DB connections (use as needed)
$conn_gis = get_conn_gis();     // PostGIS (PDO pgsql)
$conn_adl = get_conn_adl();     // VATSIM_ADL (sqlsrv)
$conn_ref = get_conn_ref();     // VATSIM_REF (sqlsrv)
// MySQL: use global $conn_pdo

// Response
SwimResponse::formatted($data, $format, 'reference', $cache_params, $format_options);
// Or paginated:
SwimResponse::paginatedFormatted($data, $total, $page, $per_page, $format, 'reference', $cache_params, $format_options);
```

### Database Column References (Verified from Codebase)

**PostGIS `airports`**: icao_code, faa_lid, name, city, state_code, country_code, latitude, longitude, elevation_ft, mag_var, is_towered, airport_class, geom
**PostGIS `nav_fixes`**: fix_name, latitude, longitude, fix_type, artcc_code, country_code, is_superseded, superseded_cycle, superseded_reason, airac_cycle, geom
**PostGIS `nav_procedures`**: computer_code, procedure_name, procedure_type, airport_icao, transition_name, transition_type, route_string, waypoints (JSONB), source, is_superseded, superseded_cycle, airac_cycle, geom
**PostGIS `airways`**: airway_name, airway_type, geom, is_superseded, airac_cycle
**PostGIS `airway_segments`**: airway_name, sequence_num, fix_from, fix_to, course, distance_nm, min_altitude_ft, geom
**PostGIS `artcc_boundaries`**: artcc_code, artcc_name, hierarchy_type, is_oceanic, geom
**PostGIS `tracon_boundaries`**: tracon_code, tracon_name, parent_artcc, geom
**PostGIS `sector_boundaries`**: sector_code, sector_name, parent_artcc, sector_type, floor_fl, ceiling_fl, geom
**PostGIS `boundary_adjacency`**: boundary_type_a, boundary_code_a, boundary_type_b, boundary_code_b, adjacency_class, shared_length_m
**PostGIS `coded_departure_routes`**: cdr_code, cdr_type, origin_airport, dest_airport, route_string, is_superseded, airac_cycle, geom
**PostGIS `playbook_routes`**: pb_code, origin_airport, dest_airport, route_string, artccs_traversed, is_superseded, airac_cycle, geom
**ADL `ACD_Data`**: ICAO_Code, Manufacturer, Model, TypeName, WTC, EngineType, EngineCount, WeightClass (verify exact column names at implementation time)
**ADL `airlines`**: icao_code, iata_code, name, callsign, country
**ADL `airport_geometry`**: airport_icao, runway_id, length_ft, width_ft, surface, heading
**ADL `airport_connect_reference`**: airport_icao, unimpeded_connect_sec, sample_size, confidence, last_refreshed_utc
**ADL `apts`**: icao, iata, name, city, state, country, lat, lon, elevation
**REF `navdata_changelogs`**: change_type, entity_type, entity_name, field_name, old_value, new_value, airac_cycle, created_utc
**MySQL `route_history_facts`**: route_id, aircraft_type_id, operator_id, time_id, flight_time_sec
**MySQL `dim_route`**: id, origin_icao, dest_icao, route_string, route_hash

> **Note**: Column names above are best-effort from codebase analysis. Step 1 of each task MUST verify columns against the actual database before writing queries. Use `SELECT TOP 1 *` (Azure SQL) or `SELECT * LIMIT 1` (PostGIS) to confirm.

---

### Task 1: Foundation — Nginx Routing, Config, and .htaccess

**Files:**
- Modify: `default` (nginx config)
- Modify: `load/swim_config.php`
- Modify: `api/swim/v1/.htaccess`

- [ ] **Step 1: Add nginx catch-all rule for reference sub-paths**

In `default`, add a new location block BEFORE the existing `taxi-times` rule (around line 82). This single rule routes all reference domain paths to the correct PHP file:

```nginx
    # SWIM API: reference/{domain}/{path...} - all reference library endpoints
    location ~ ^/api/swim/v1/reference/(airports|navigation|airspace|facilities|aircraft|airlines|routes|airac|utilities|hierarchy|bulk)/(.+) {
        fastcgi_pass 127.0.0.1:9000;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root/api/swim/v1/reference/$1.php;
        fastcgi_param QUERY_STRING $query_string;
        fastcgi_read_timeout 600;
    }
```

Base URLs without sub-paths (e.g., `/reference/airports`) are already handled by the `@phpfallback` location block which tries `$uri.php`.

- [ ] **Step 2: Update swim_config.php cache TTLs**

In `load/swim_config.php`, find the `$SWIM_CACHE_TTLS` array and add entries for the new reference domains. The existing `'reference' => 300` (5 min) is the base TTL. Add domain-specific overrides:

```php
// Add after existing 'reference' => 300 entry:
'reference_nav'      => 86400,  // 24h - AIRAC-stable
'reference_airspace' => 86400,  // 24h - AIRAC-stable
'reference_facility' => 86400,  // 24h - AIRAC-stable
'reference_aircraft' => 604800, // 7 days - rarely changes
'reference_airline'  => 604800, // 7 days - rarely changes
'reference_route'    => 3600,   // 1h - derived from recent data
'reference_airac'    => 86400,  // 24h - changes only at cycle boundary
'reference_hierarchy'=> 86400,  // 24h - static structure
'reference_bulk'     => 86400,  // 24h - regenerated per AIRAC cycle
```

- [ ] **Step 3: Update .htaccess rewrite rules**

In `api/swim/v1/.htaccess`, add a rewrite rule for reference sub-paths. Add BEFORE the generic "Clean URLs" section (around line 38):

```apache
    # Reference library: /reference/{domain}/{path...} -> /reference/{domain}.php
    RewriteRule ^reference/([a-zA-Z0-9_-]+)/(.+)$ reference/$1.php [L,QSA]
```

- [ ] **Step 4: Verify routing works**

Deploy and test that nginx correctly routes:
```bash
curl -s "https://perti.vatcscc.org/api/swim/v1/reference/taxi-times" -H "X-API-Key: YOUR_KEY" | head -c 200
# Should return existing taxi-times data (confirms existing routing still works)
```

- [ ] **Step 5: Commit**

```bash
git add default load/swim_config.php api/swim/v1/.htaccess
git commit -m "feat(swim): add reference library routing and cache config"
```

---

### Task 2: Hierarchy Reference Data (JSON)

**Files:**
- Create: `assets/data/hierarchy.json`

- [ ] **Step 1: Create the static hierarchy reference JSON**

This file maps the top 3 levels of the geographic tree (Region → Division → DCC Region → Centers). Lower levels are resolved at runtime from PostGIS.

```json
{
  "_meta": {
    "description": "VATSWIM geographic hierarchy reference data",
    "version": "1.0.0",
    "last_updated": "2026-04-05"
  },
  "regions": [
    {
      "code": "AMAS",
      "name": "Americas",
      "divisions": [
        {
          "code": "VATUSA",
          "name": "VATSIM United States",
          "icao_prefixes": ["K", "PA", "PH", "PG", "PW", "PM"],
          "dcc_regions": [
            {
              "code": "EASTERN",
              "name": "Eastern Service Area",
              "centers": ["KZBW", "KZNY", "KZDC", "KZOB", "KZID", "KZTL", "KZJX", "KZMA"]
            },
            {
              "code": "CENTRAL",
              "name": "Central Service Area",
              "centers": ["KZAU", "KZMP", "KZKC", "KZME", "KZFW", "KZHU"]
            },
            {
              "code": "WESTERN",
              "name": "Western Service Area",
              "centers": ["KZDV", "KZAB", "KZLC", "KZLA", "KZOA", "KZSE"]
            },
            {
              "code": "ALASKA",
              "name": "Alaskan Region",
              "centers": ["PAZA"]
            }
          ]
        },
        {
          "code": "VATCAN",
          "name": "VATSIM Canada",
          "icao_prefixes": ["C"],
          "dcc_regions": [],
          "centers": ["CZEG", "CZWG", "CZYZ", "CZUL", "CZQX", "CZQM", "CZVR"]
        },
        {
          "code": "VATCAR",
          "name": "VATSIM Caribbean",
          "icao_prefixes": ["M", "T"],
          "dcc_regions": [],
          "centers": []
        },
        {
          "code": "VATSAM",
          "name": "VATSIM South America",
          "icao_prefixes": ["S"],
          "dcc_regions": [],
          "centers": []
        },
        {
          "code": "VATMEX",
          "name": "VATSIM Mexico",
          "icao_prefixes": ["MM"],
          "dcc_regions": [],
          "centers": ["MMEX", "MMTY", "MMID"]
        }
      ]
    },
    {
      "code": "EMEA",
      "name": "Europe, Middle East and Africa",
      "divisions": [
        {
          "code": "VATEUR",
          "name": "VATSIM Europe",
          "icao_prefixes": [],
          "dcc_regions": [],
          "centers": [],
          "note": "Subdivisions per vACC. Centers resolved from PostGIS artcc_boundaries WHERE hierarchy_type='FIR'."
        },
        {
          "code": "VATUK",
          "name": "VATSIM United Kingdom",
          "icao_prefixes": ["EG"],
          "dcc_regions": [],
          "centers": ["EGTT", "EGPX"]
        },
        {
          "code": "VATMENA",
          "name": "VATSIM Middle East and North Africa",
          "icao_prefixes": ["O", "H"],
          "dcc_regions": [],
          "centers": []
        },
        {
          "code": "VATAFA",
          "name": "VATSIM Africa",
          "icao_prefixes": ["F", "D", "G"],
          "dcc_regions": [],
          "centers": []
        }
      ]
    },
    {
      "code": "APAC",
      "name": "Asia Pacific",
      "divisions": [
        {
          "code": "VATPAC",
          "name": "VATSIM Pacific",
          "icao_prefixes": ["Y", "NZ"],
          "dcc_regions": [],
          "centers": []
        },
        {
          "code": "VATJPN",
          "name": "VATSIM Japan",
          "icao_prefixes": ["RJ", "RO"],
          "dcc_regions": [],
          "centers": ["RJJJ", "RJTG"]
        },
        {
          "code": "VATKOR",
          "name": "VATSIM Korea",
          "icao_prefixes": ["RK"],
          "dcc_regions": [],
          "centers": ["RKRR"]
        },
        {
          "code": "VATPRC",
          "name": "VATSIM China",
          "icao_prefixes": ["Z"],
          "dcc_regions": [],
          "centers": []
        },
        {
          "code": "VATSEA",
          "name": "VATSIM Southeast Asia",
          "icao_prefixes": ["V", "W"],
          "dcc_regions": [],
          "centers": []
        }
      ]
    }
  ],
  "curated_lists": {
    "oep35": {
      "name": "OEP 35 Airports",
      "description": "FAA Operational Evolution Partnership core 35 airports",
      "airports": ["KATL","KBOS","KBWI","KCLE","KCLT","KCVG","KDCA","KDEN","KDFW","KDTW","KEWR","KFLL","KHNL","KHOU","KIAD","KIAH","KJFK","KLAS","KLAX","KLGA","KMCO","KMDW","KMEM","KMIA","KMSP","KORD","KPBI","KPDX","KPHL","KPHX","KPIT","KSAN","KSEA","KSFO","KSLC","KSTL","KTPA"]
    },
    "core30": {
      "name": "Core 30 Airports",
      "description": "FAA Core 30 high-delay airports for ATCSCC monitoring",
      "airports": ["KATL","KBOS","KBWI","KCLE","KCLT","KDCA","KDEN","KDFW","KDTW","KEWR","KFLL","KIAH","KJFK","KLAS","KLAX","KLGA","KMCO","KMDW","KMEM","KMIA","KMSP","KORD","KPHL","KPHX","KPIT","KSAN","KSEA","KSFO","KSLC","KTPA"]
    },
    "aspm82": {
      "name": "ASPM 82 Airports",
      "description": "FAA Aviation System Performance Metrics 82-airport set",
      "airports": ["KABQ","KANC","KATL","KAUS","KBDL","KBHM","KBNA","KBOS","KBTV","KBUF","KBUR","KBWI","KCLE","KCLT","KCMH","KCVG","KDAL","KDCA","KDEN","KDFW","KDTW","KELP","KEWR","KFLL","KFNT","KGRR","KGSO","KHNL","KHOU","KHPN","KIAD","KIAH","KICT","KIND","KISP","KJAX","KJFK","KLAS","KLAX","KLGA","KLIT","KMCI","KMCO","KMDW","KMEM","KMHT","KMIA","KMKE","KMSP","KMSY","KOAK","KOKC","KOMA","KONT","KORD","KORF","KPBI","KPDX","KPHL","KPHX","KPIT","KPVD","KRDU","KRIC","KRNO","KROC","KRSW","KSAN","KSAT","KSDF","KSEA","KSFO","KSJC","KSLC","KSMF","KSNA","KSTL","KSWF","KSYR","KTEB","KTPA","KTUL","KTUS"]
    },
    "opsnet45": {
      "name": "OPSNET 45 Airports",
      "description": "FAA Operations Network 45-airport performance tracking set",
      "airports": ["KABQ","KATL","KAUS","KBNA","KBOS","KBWI","KCLE","KCLT","KCVG","KDCA","KDEN","KDFW","KDTW","KELP","KEWR","KFLL","KHNL","KHOU","KIAD","KIAH","KIND","KJAX","KJFK","KLAS","KLAX","KLGA","KMCI","KMCO","KMDW","KMEM","KMIA","KMKE","KMSP","KMSY","KOAK","KORD","KPDX","KPHL","KPHX","KPIT","KSAN","KSEA","KSFO","KSLC","KSTL","KTPA"]
    }
  }
}
```

- [ ] **Step 2: Commit**

```bash
git add assets/data/hierarchy.json
git commit -m "feat(swim): add hierarchy reference data for geographic tree navigation"
```

---

### Task 3: Airlines Endpoint (Simplest — validates pattern)

**Files:**
- Create: `api/swim/v1/reference/airlines.php`

This is the simplest endpoint (228 rows, 2 endpoints). Build it first to validate the full pattern before tackling more complex domains.

- [ ] **Step 1: Verify airlines table columns**

Run against VATSIM_ADL to confirm exact column names:
```sql
SELECT TOP 1 * FROM dbo.airlines;
```

Document the actual columns found.

- [ ] **Step 2: Write airlines.php**

Create `api/swim/v1/reference/airlines.php`:

```php
<?php
/**
 * VATSWIM API v1 - Airline Reference Data
 *
 * @version 1.0.0
 * @since 2026-04-05
 *
 * Endpoints:
 *   GET /reference/airlines              - List/search all airlines
 *   GET /reference/airlines/{icao}       - Single airline detail
 *
 * Query Parameters:
 *   search       - Free text search (name, callsign, code)
 *   country      - Country filter
 *   page         - Page number (default 1)
 *   per_page     - Results per page (default 100, max 1000)
 *   format       - Response format: json (default), xml, csv, ndjson
 */

require_once __DIR__ . '/../auth.php';

$auth = swim_init_auth(true, false);

// Parse path: /reference/airlines/{icao_code}
$request_uri = $_SERVER['REQUEST_URI'] ?? '';
$path = parse_url($request_uri, PHP_URL_PATH);
$path = preg_replace('#^.*/reference/airlines/?#', '', $path);
$path_parts = array_values(array_filter(explode('/', $path)));

$airline_code = !empty($path_parts[0]) ? strtoupper(trim($path_parts[0])) : null;

// Validate airline code
if ($airline_code !== null && (strlen($airline_code) < 2 || strlen($airline_code) > 4)) {
    SwimResponse::error('Invalid airline code. Use 2-letter IATA or 3-letter ICAO.', 400, 'INVALID_CODE');
}

// Query parameters
$search = swim_get_param('search');
$country = swim_get_param('country');
$page = swim_get_int_param('page', 1, 1, 10000);
$per_page = swim_get_int_param('per_page', 100, 1, 1000);
$format = swim_validate_format(swim_get_param('format', 'json'), 'reference');

$format_options = [
    'root' => 'swim_airlines',
    'item' => 'airline',
    'name' => 'VATSWIM Airline Reference' . ($airline_code ? ' - ' . $airline_code : ''),
    'filename' => 'swim_airlines' . ($airline_code ? '_' . $airline_code : '') . '_' . date('Ymd_His')
];

// Cache key
$cache_params = array_filter([
    'airline' => $airline_code,
    'search' => $search,
    'country' => $country,
    'page' => $page > 1 ? (string)$page : null,
    'per_page' => $per_page != 100 ? (string)$per_page : null,
    'format' => $format !== 'json' ? $format : null,
], fn($v) => $v !== null && $v !== '');

if (SwimResponse::tryCachedFormatted('reference_airline', $cache_params, $format, $format_options)) {
    exit;
}

// Connect to ADL (airlines table)
$conn = get_conn_adl();
if (!$conn) {
    SwimResponse::error('Database connection unavailable', 503, 'SERVICE_UNAVAILABLE');
}

if ($airline_code !== null) {
    handleSingleAirline($conn, $airline_code, $format, $cache_params, $format_options);
} else {
    handleAirlineList($conn, $search, $country, $page, $per_page, $format, $cache_params, $format_options);
}

function handleSingleAirline($conn, $code, $format, $cache_params, $format_options) {
    // Try ICAO first, then IATA
    $sql = "SELECT icao_code, iata_code, name, callsign, country
            FROM dbo.airlines
            WHERE icao_code = ? OR iata_code = ?";
    $stmt = sqlsrv_query($conn, $sql, [$code, $code]);
    if ($stmt === false) {
        SwimResponse::error('Database query failed', 500, 'DB_ERROR');
    }

    $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    sqlsrv_free_stmt($stmt);

    if (!$row) {
        SwimResponse::error("Airline not found: $code", 404, 'NOT_FOUND');
    }

    $airline = formatAirlineRow($row);
    SwimResponse::formatted(['airline' => $airline], $format, 'reference_airline', $cache_params, $format_options);
}

function handleAirlineList($conn, $search, $country, $page, $per_page, $format, $cache_params, $format_options) {
    $where = [];
    $params = [];

    if ($search) {
        $where[] = "(name LIKE ? OR callsign LIKE ? OR icao_code LIKE ? OR iata_code LIKE ?)";
        $like = '%' . $search . '%';
        $params = array_merge($params, [$like, $like, $like, $like]);
    }

    if ($country) {
        $where[] = "country = ?";
        $params[] = strtoupper($country);
    }

    $where_sql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

    // Count total
    $count_sql = "SELECT COUNT(*) AS total FROM dbo.airlines $where_sql";
    $count_stmt = sqlsrv_query($conn, $count_sql, $params);
    $total = 0;
    if ($count_stmt !== false) {
        $count_row = sqlsrv_fetch_array($count_stmt, SQLSRV_FETCH_ASSOC);
        $total = (int)($count_row['total'] ?? 0);
        sqlsrv_free_stmt($count_stmt);
    }

    // Fetch page
    $offset = ($page - 1) * $per_page;
    $sql = "SELECT icao_code, iata_code, name, callsign, country
            FROM dbo.airlines
            $where_sql
            ORDER BY icao_code
            OFFSET ? ROWS FETCH NEXT ? ROWS ONLY";
    $params[] = $offset;
    $params[] = $per_page;

    $stmt = sqlsrv_query($conn, $sql, $params);
    if ($stmt === false) {
        SwimResponse::error('Database query failed', 500, 'DB_ERROR');
    }

    $airlines = [];
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $airlines[] = formatAirlineRow($row);
    }
    sqlsrv_free_stmt($stmt);

    $data = [
        'airlines' => $airlines,
        'count' => count($airlines),
        'total' => $total,
    ];

    SwimResponse::paginatedFormatted($data, $total, $page, $per_page, $format, 'reference_airline', $cache_params, $format_options);
}

function formatAirlineRow($row) {
    return [
        'icao_code' => $row['icao_code'] ?? null,
        'iata_code' => $row['iata_code'] ?? null,
        'name' => $row['name'] ?? null,
        'callsign' => $row['callsign'] ?? null,
        'country' => $row['country'] ?? null,
    ];
}
```

- [ ] **Step 3: Test airlines endpoint**

```bash
# List all airlines
curl -s "https://perti.vatcscc.org/api/swim/v1/reference/airlines" -H "X-API-Key: YOUR_KEY" | python -m json.tool | head -30

# Search by name
curl -s "https://perti.vatcscc.org/api/swim/v1/reference/airlines?search=delta" -H "X-API-Key: YOUR_KEY"

# Single airline
curl -s "https://perti.vatcscc.org/api/swim/v1/reference/airlines/DAL" -H "X-API-Key: YOUR_KEY"

# CSV format
curl -s "https://perti.vatcscc.org/api/swim/v1/reference/airlines?format=csv" -H "X-API-Key: YOUR_KEY" | head -5
```

- [ ] **Step 4: Commit**

```bash
git add api/swim/v1/reference/airlines.php
git commit -m "feat(swim): add airlines reference endpoint"
```

---

### Task 4: Aircraft Endpoint

**Files:**
- Create: `api/swim/v1/reference/aircraft.php`

- [ ] **Step 1: Verify ACD_Data and BADA table columns**

Run against VATSIM_ADL:
```sql
SELECT TOP 1 * FROM dbo.ACD_Data;
SELECT TOP 1 * FROM dbo.bada_opf;  -- if exists
```

Document actual columns. Key columns expected: ICAO_Code (or icao_code), Manufacturer, Model, TypeName, WTC (wake turbulence category), EngineType, EngineCount, WeightClass.

- [ ] **Step 2: Write aircraft.php**

Create `api/swim/v1/reference/aircraft.php`:

```php
<?php
/**
 * VATSWIM API v1 - Aircraft Reference Data
 *
 * @version 1.0.0
 * @since 2026-04-05
 *
 * Endpoints:
 *   GET /reference/aircraft/types           - List/search aircraft types
 *   GET /reference/aircraft/types/{icao}    - Single type detail
 *   GET /reference/aircraft/families        - List all families
 *   GET /reference/aircraft/families/{key}  - Family detail with members
 *   GET /reference/aircraft/performance/{icao} - BADA performance data
 *
 * Query Parameters (types list):
 *   search        - Free text (manufacturer, model, ICAO code)
 *   manufacturer  - Manufacturer filter
 *   weight_class  - S, L, H, SUPER
 *   wake_category - L, M, H, J
 *   engine_type   - jet, turboprop, piston
 *   family        - Family key filter (e.g., a320fam)
 *   page, per_page, format
 */

require_once __DIR__ . '/../auth.php';

$auth = swim_init_auth(true, false);

// Parse path: /reference/aircraft/{sub}/{code}
$request_uri = $_SERVER['REQUEST_URI'] ?? '';
$path = parse_url($request_uri, PHP_URL_PATH);
$path = preg_replace('#^.*/reference/aircraft/?#', '', $path);
$path_parts = array_values(array_filter(explode('/', $path)));

$sub = $path_parts[0] ?? null;  // 'types', 'families', 'performance'
$code = isset($path_parts[1]) ? strtoupper(trim($path_parts[1])) : null;

$format = swim_validate_format(swim_get_param('format', 'json'), 'reference');

$format_options = [
    'root' => 'swim_aircraft',
    'item' => 'aircraft',
    'name' => 'VATSWIM Aircraft Reference',
    'filename' => 'swim_aircraft_' . date('Ymd_His')
];

$cache_params = array_filter([
    'sub' => $sub,
    'code' => $code,
    'search' => swim_get_param('search'),
    'manufacturer' => swim_get_param('manufacturer'),
    'weight_class' => swim_get_param('weight_class'),
    'wake_category' => swim_get_param('wake_category'),
    'engine_type' => swim_get_param('engine_type'),
    'family' => swim_get_param('family'),
    'page' => swim_get_param('page'),
    'format' => $format !== 'json' ? $format : null,
], fn($v) => $v !== null && $v !== '');

if (SwimResponse::tryCachedFormatted('reference_aircraft', $cache_params, $format, $format_options)) {
    exit;
}

// Load aircraft families
require_once __DIR__ . '/../../../../load/aircraft_families.php';
global $AIRCRAFT_FAMILIES;

// Route to handler
switch ($sub) {
    case 'families':
        if ($code) {
            handleFamilyDetail(strtolower($code), $AIRCRAFT_FAMILIES, $format, $cache_params, $format_options);
        } else {
            handleFamilyList($AIRCRAFT_FAMILIES, $format, $cache_params, $format_options);
        }
        break;

    case 'performance':
        if (!$code) {
            SwimResponse::error('ICAO type code required for performance lookup', 400, 'MISSING_PARAM');
        }
        handlePerformance($code, $format, $cache_params, $format_options);
        break;

    case 'types':
    case null:
        // Default: if code looks like an ICAO type, treat as single lookup
        if ($sub === null && $code === null) {
            // No sub-path at all: list types
            handleTypeList($AIRCRAFT_FAMILIES, $format, $cache_params, $format_options);
        } elseif ($sub === 'types' && $code) {
            handleTypeDetail($code, $AIRCRAFT_FAMILIES, $format, $cache_params, $format_options);
        } elseif ($sub === 'types') {
            handleTypeList($AIRCRAFT_FAMILIES, $format, $cache_params, $format_options);
        } else {
            // $sub is something else - treat as ICAO code for backward compat
            handleTypeDetail(strtoupper($sub), $AIRCRAFT_FAMILIES, $format, $cache_params, $format_options);
        }
        break;

    default:
        SwimResponse::error("Unknown aircraft sub-resource: $sub. Use 'types', 'families', or 'performance'.", 400, 'INVALID_RESOURCE');
}

function handleTypeDetail($icao, $families, $format, $cache_params, $format_options) {
    $conn = get_conn_adl();
    if (!$conn) {
        SwimResponse::error('Database unavailable', 503, 'SERVICE_UNAVAILABLE');
    }

    // Query ACD_Data - verify column names match actual schema
    $sql = "SELECT * FROM dbo.ACD_Data WHERE ICAO_Code = ?";
    $stmt = sqlsrv_query($conn, $sql, [$icao]);
    if ($stmt === false) {
        SwimResponse::error('Database query failed', 500, 'DB_ERROR');
    }

    $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    sqlsrv_free_stmt($stmt);

    if (!$row) {
        SwimResponse::error("Aircraft type not found: $icao", 404, 'NOT_FOUND');
    }

    $aircraft = formatTypeRow($row);

    // Add family info
    $aircraft['family'] = null;
    $aircraft['family_members'] = [];
    foreach ($families as $key => $members) {
        if (in_array($icao, $members)) {
            $aircraft['family'] = $key;
            $aircraft['family_members'] = $members;
            break;
        }
    }

    SwimResponse::formatted(['aircraft' => $aircraft], $format, 'reference_aircraft', $cache_params, $format_options);
}

function handleTypeList($families, $format, $cache_params, $format_options) {
    $conn = get_conn_adl();
    if (!$conn) {
        SwimResponse::error('Database unavailable', 503, 'SERVICE_UNAVAILABLE');
    }

    $search = swim_get_param('search');
    $manufacturer = swim_get_param('manufacturer');
    $weight_class = swim_get_param('weight_class');
    $wake_category = swim_get_param('wake_category');
    $engine_type = swim_get_param('engine_type');
    $family_filter = swim_get_param('family');
    $page = swim_get_int_param('page', 1, 1, 10000);
    $per_page = swim_get_int_param('per_page', 100, 1, 1000);

    $where = [];
    $params = [];

    if ($search) {
        $where[] = "(ICAO_Code LIKE ? OR Manufacturer LIKE ? OR Model LIKE ?)";
        $like = '%' . $search . '%';
        $params = array_merge($params, [$like, $like, $like]);
    }
    if ($manufacturer) {
        $where[] = "Manufacturer LIKE ?";
        $params[] = '%' . $manufacturer . '%';
    }
    if ($weight_class) {
        $where[] = "WeightClass = ?";
        $params[] = strtoupper($weight_class);
    }
    if ($wake_category) {
        $where[] = "WTC = ?";
        $params[] = strtoupper($wake_category);
    }
    if ($engine_type) {
        $where[] = "EngineType LIKE ?";
        $params[] = '%' . $engine_type . '%';
    }

    // Family filter: expand to list of ICAO codes
    if ($family_filter && isset($families[strtolower($family_filter)])) {
        $members = $families[strtolower($family_filter)];
        $placeholders = implode(',', array_fill(0, count($members), '?'));
        $where[] = "ICAO_Code IN ($placeholders)";
        $params = array_merge($params, $members);
    }

    $where_sql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

    // Count
    $count_sql = "SELECT COUNT(*) AS total FROM dbo.ACD_Data $where_sql";
    $count_stmt = sqlsrv_query($conn, $count_sql, $params);
    $total = 0;
    if ($count_stmt !== false) {
        $r = sqlsrv_fetch_array($count_stmt, SQLSRV_FETCH_ASSOC);
        $total = (int)($r['total'] ?? 0);
        sqlsrv_free_stmt($count_stmt);
    }

    // Fetch page
    $offset = ($page - 1) * $per_page;
    $sql = "SELECT * FROM dbo.ACD_Data $where_sql ORDER BY ICAO_Code OFFSET ? ROWS FETCH NEXT ? ROWS ONLY";
    $params[] = $offset;
    $params[] = $per_page;

    $stmt = sqlsrv_query($conn, $sql, $params);
    if ($stmt === false) {
        SwimResponse::error('Database query failed', 500, 'DB_ERROR');
    }

    $types = [];
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $types[] = formatTypeRow($row);
    }
    sqlsrv_free_stmt($stmt);

    $data = ['types' => $types, 'count' => count($types), 'total' => $total];
    SwimResponse::paginatedFormatted($data, $total, $page, $per_page, $format, 'reference_aircraft', $cache_params, $format_options);
}

function handleFamilyList($families, $format, $cache_params, $format_options) {
    $list = [];
    foreach ($families as $key => $members) {
        $list[] = [
            'key' => $key,
            'member_count' => count($members),
            'members' => $members,
        ];
    }

    SwimResponse::formatted([
        'families' => $list,
        'count' => count($list),
    ], $format, 'reference_aircraft', $cache_params, $format_options);
}

function handleFamilyDetail($key, $families, $format, $cache_params, $format_options) {
    if (!isset($families[$key])) {
        SwimResponse::error("Aircraft family not found: $key", 404, 'NOT_FOUND');
    }

    $members = $families[$key];

    // Fetch full details for each member
    $conn = get_conn_adl();
    if (!$conn) {
        SwimResponse::error('Database unavailable', 503, 'SERVICE_UNAVAILABLE');
    }

    $placeholders = implode(',', array_fill(0, count($members), '?'));
    $sql = "SELECT * FROM dbo.ACD_Data WHERE ICAO_Code IN ($placeholders) ORDER BY ICAO_Code";
    $stmt = sqlsrv_query($conn, $sql, $members);

    $details = [];
    if ($stmt !== false) {
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $details[] = formatTypeRow($row);
        }
        sqlsrv_free_stmt($stmt);
    }

    SwimResponse::formatted([
        'family' => $key,
        'member_count' => count($members),
        'member_codes' => $members,
        'members' => $details,
    ], $format, 'reference_aircraft', $cache_params, $format_options);
}

function handlePerformance($icao, $format, $cache_params, $format_options) {
    $conn = get_conn_adl();
    if (!$conn) {
        SwimResponse::error('Database unavailable', 503, 'SERVICE_UNAVAILABLE');
    }

    // Check if BADA tables exist and have data for this type
    // The exact BADA table structure varies - check what's available
    $sql = "SELECT * FROM dbo.ACD_Data WHERE ICAO_Code = ?";
    $stmt = sqlsrv_query($conn, $sql, [$icao]);
    if ($stmt === false) {
        SwimResponse::error('Database query failed', 500, 'DB_ERROR');
    }

    $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    sqlsrv_free_stmt($stmt);

    if (!$row) {
        SwimResponse::error("Aircraft type not found: $icao", 404, 'NOT_FOUND');
    }

    $aircraft = formatTypeRow($row);

    // Try BADA performance data
    $performance = null;
    $bada_sql = "SELECT TOP 1 * FROM dbo.bada_opf WHERE icao_code = ?";
    $bada_stmt = sqlsrv_query($conn, $bada_sql, [$icao]);
    if ($bada_stmt !== false) {
        $bada_row = sqlsrv_fetch_array($bada_stmt, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($bada_stmt);
        if ($bada_row) {
            $performance = $bada_row;  // Pass through raw BADA data
        }
    }

    SwimResponse::formatted([
        'aircraft' => $aircraft,
        'performance' => $performance,
        'source' => $performance ? 'BADA' : null,
    ], $format, 'reference_aircraft', $cache_params, $format_options);
}

/**
 * Format ACD_Data row - adapt column names to match actual schema
 * NOTE: Column names must match actual ACD_Data columns. Verify in Step 1.
 */
function formatTypeRow($row) {
    return [
        'icao_code' => $row['ICAO_Code'] ?? $row['icao_code'] ?? null,
        'name' => $row['TypeName'] ?? $row['Model'] ?? null,
        'manufacturer' => $row['Manufacturer'] ?? null,
        'weight_class' => $row['WeightClass'] ?? null,
        'wake_category' => $row['WTC'] ?? null,
        'engine_type' => $row['EngineType'] ?? null,
        'engine_count' => isset($row['EngineCount']) ? (int)$row['EngineCount'] : null,
    ];
}
```

- [ ] **Step 3: Test aircraft endpoints**

```bash
# List types
curl -s "https://perti.vatcscc.org/api/swim/v1/reference/aircraft/types" -H "X-API-Key: KEY" | head -c 500

# Single type
curl -s "https://perti.vatcscc.org/api/swim/v1/reference/aircraft/types/B738" -H "X-API-Key: KEY"

# Families list
curl -s "https://perti.vatcscc.org/api/swim/v1/reference/aircraft/families" -H "X-API-Key: KEY"

# Family detail
curl -s "https://perti.vatcscc.org/api/swim/v1/reference/aircraft/families/b737" -H "X-API-Key: KEY"

# Search by manufacturer
curl -s "https://perti.vatcscc.org/api/swim/v1/reference/aircraft/types?manufacturer=boeing" -H "X-API-Key: KEY"
```

- [ ] **Step 4: Commit**

```bash
git add api/swim/v1/reference/aircraft.php
git commit -m "feat(swim): add aircraft reference endpoint with types, families, performance"
```

---

### Task 5: Airports Endpoint

**Files:**
- Create: `api/swim/v1/reference/airports.php`

This is a medium-complexity endpoint with 7 sub-endpoints querying PostGIS + ADL.

- [ ] **Step 1: Verify PostGIS airports columns and ADL airport tables**

```sql
-- PostGIS
SELECT * FROM airports LIMIT 1;
SELECT column_name, data_type FROM information_schema.columns WHERE table_name = 'airports' ORDER BY ordinal_position;

-- ADL
SELECT TOP 1 * FROM dbo.airport_geometry;
SELECT TOP 1 * FROM dbo.airport_connect_reference;
```

- [ ] **Step 2: Write airports.php**

Create `api/swim/v1/reference/airports.php`. This file is large (~350 lines). Key handlers:

```php
<?php
/**
 * VATSWIM API v1 - Airport Reference Data
 *
 * @version 1.0.0
 * @since 2026-04-05
 *
 * Endpoints:
 *   GET /reference/airports/lookup?faa={lid}&icao={code}  - Code conversion
 *   GET /reference/airports/search?q=...&near=lat,lon     - Search
 *   GET /reference/airports/{code}                        - Full profile
 *   GET /reference/airports/{code}/facilities             - Responsible TRACON/Center
 *   GET /reference/airports/{code}/runways                - Runway configurations
 *   GET /reference/airports/{code}/taxi-times             - Proxy to taxi-times.php
 *   GET /reference/airports/{code}/connect-times          - Connect-to-push times
 */

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../../../../load/services/GISService.php';

$auth = swim_init_auth(true, false);

$request_uri = $_SERVER['REQUEST_URI'] ?? '';
$path = parse_url($request_uri, PHP_URL_PATH);
$path = preg_replace('#^.*/reference/airports/?#', '', $path);
$path_parts = array_values(array_filter(explode('/', $path)));

$code = !empty($path_parts[0]) ? strtoupper(trim($path_parts[0])) : null;
$action = $path_parts[1] ?? null;

$format = swim_validate_format(swim_get_param('format', 'json'), 'reference');
$include_geometry = in_array('geometry', explode(',', swim_get_param('include', '')));

$format_options = [
    'root' => 'swim_airports',
    'item' => 'airport',
    'name' => 'VATSWIM Airport Reference',
    'filename' => 'swim_airports_' . date('Ymd_His')
];

$cache_params = array_filter([
    'code' => $code, 'action' => $action,
    'q' => swim_get_param('q'), 'near' => swim_get_param('near'),
    'faa' => swim_get_param('faa'), 'icao' => swim_get_param('icao'),
    'include' => swim_get_param('include'),
    'format' => $format !== 'json' ? $format : null,
], fn($v) => $v !== null && $v !== '');

if (SwimResponse::tryCachedFormatted('reference', $cache_params, $format, $format_options)) {
    exit;
}

// Route to handler
if ($code === 'lookup') {
    handleLookup($format, $cache_params, $format_options);
} elseif ($code === 'search') {
    handleSearch($include_geometry, $format, $cache_params, $format_options);
} elseif ($code !== null && $action === 'facilities') {
    handleFacilities($code, $format, $cache_params, $format_options);
} elseif ($code !== null && $action === 'runways') {
    handleRunways($code, $format, $cache_params, $format_options);
} elseif ($code !== null && $action === 'taxi-times') {
    // Proxy to existing taxi-times endpoint
    header('Location: /api/swim/v1/reference/taxi-times/' . urlencode($code) . '?' . $_SERVER['QUERY_STRING']);
    exit;
} elseif ($code !== null && $action === 'connect-times') {
    handleConnectTimes($code, $format, $cache_params, $format_options);
} elseif ($code !== null) {
    handleAirportProfile($code, $include_geometry, $format, $cache_params, $format_options);
} else {
    SwimResponse::error('Specify an airport code, or use /lookup or /search', 400, 'MISSING_PARAM');
}

function handleLookup($format, $cache_params, $format_options) {
    $faa = swim_get_param('faa');
    $icao = swim_get_param('icao');

    if (!$faa && !$icao) {
        SwimResponse::error('Provide faa or icao parameter', 400, 'MISSING_PARAM');
    }

    $conn = get_conn_gis();
    if (!$conn) {
        SwimResponse::error('GIS service unavailable', 503, 'SERVICE_UNAVAILABLE');
    }

    if ($faa) {
        $stmt = $conn->prepare("SELECT icao_code, faa_lid, name, city, state_code, country_code FROM airports WHERE faa_lid = :faa LIMIT 5");
        $stmt->execute([':faa' => strtoupper($faa)]);
    } else {
        $stmt = $conn->prepare("SELECT icao_code, faa_lid, name, city, state_code, country_code FROM airports WHERE icao_code = :icao LIMIT 5");
        $stmt->execute([':icao' => strtoupper($icao)]);
    }

    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (empty($results)) {
        SwimResponse::error('Airport not found', 404, 'NOT_FOUND');
    }

    SwimResponse::formatted([
        'airports' => $results,
        'count' => count($results),
    ], $format, 'reference', $cache_params, $format_options);
}

function handleAirportProfile($code, $include_geometry, $format, $cache_params, $format_options) {
    $conn = get_conn_gis();
    if (!$conn) {
        SwimResponse::error('GIS service unavailable', 503, 'SERVICE_UNAVAILABLE');
    }

    // Auto-detect: 3 chars = FAA LID, 4 chars = ICAO
    $geom_col = $include_geometry ? ", ST_AsGeoJSON(geom, 5) AS geometry" : "";
    $sql = "SELECT icao_code, faa_lid, name, city, state_code, country_code,
                   latitude, longitude, elevation_ft, mag_var, is_towered, airport_class
                   $geom_col
            FROM airports
            WHERE " . (strlen($code) === 3 ? "faa_lid = :code" : "icao_code = :code") . "
            LIMIT 1";

    $stmt = $conn->prepare($sql);
    $stmt->execute([':code' => $code]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        // Try the other field
        $alt_sql = "SELECT icao_code, faa_lid, name, city, state_code, country_code,
                           latitude, longitude, elevation_ft, mag_var, is_towered, airport_class
                           $geom_col
                    FROM airports
                    WHERE " . (strlen($code) === 3 ? "icao_code = :code" : "faa_lid = :code") . "
                    LIMIT 1";
        $stmt2 = $conn->prepare($alt_sql);
        $stmt2->execute([':code' => $code]);
        $row = $stmt2->fetch(PDO::FETCH_ASSOC);
    }

    if (!$row) {
        SwimResponse::error("Airport not found: $code", 404, 'NOT_FOUND');
    }

    if (isset($row['geometry'])) {
        $row['geometry'] = json_decode($row['geometry'], true);
    }

    SwimResponse::formatted(['airport' => $row], $format, 'reference', $cache_params, $format_options);
}

function handleFacilities($code, $format, $cache_params, $format_options) {
    $gis = GISService::getInstance();
    if (!$gis) {
        SwimResponse::error('GIS service unavailable', 503, 'SERVICE_UNAVAILABLE');
    }

    // Get airport coordinates first
    $conn = get_conn_gis();
    $stmt = $conn->prepare("SELECT latitude, longitude FROM airports WHERE icao_code = :code OR faa_lid = :code LIMIT 1");
    $stmt->execute([':code' => $code]);
    $apt = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$apt) {
        SwimResponse::error("Airport not found: $code", 404, 'NOT_FOUND');
    }

    // Point-in-polygon via GISService
    $boundaries = $gis->getBoundariesAtPoint((float)$apt['latitude'], (float)$apt['longitude']);

    SwimResponse::formatted([
        'airport' => $code,
        'facilities' => $boundaries,
    ], $format, 'reference', $cache_params, $format_options);
}

function handleRunways($code, $format, $cache_params, $format_options) {
    $conn = get_conn_adl();
    if (!$conn) {
        SwimResponse::error('Database unavailable', 503, 'SERVICE_UNAVAILABLE');
    }

    $sql = "SELECT runway_id, length_ft, width_ft, surface, heading
            FROM dbo.airport_geometry
            WHERE airport_icao = ?
            ORDER BY runway_id";
    $stmt = sqlsrv_query($conn, $sql, [$code]);

    if ($stmt === false) {
        SwimResponse::error('Database query failed', 500, 'DB_ERROR');
    }

    $runways = [];
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $runways[] = $row;
    }
    sqlsrv_free_stmt($stmt);

    // Also try with K prefix removed for FAA LIDs
    if (empty($runways) && strlen($code) === 4 && $code[0] === 'K') {
        $faa_lid = substr($code, 1);
        $stmt2 = sqlsrv_query($conn, $sql, [$faa_lid]);
        if ($stmt2 !== false) {
            while ($row = sqlsrv_fetch_array($stmt2, SQLSRV_FETCH_ASSOC)) {
                $runways[] = $row;
            }
            sqlsrv_free_stmt($stmt2);
        }
    }

    SwimResponse::formatted([
        'airport' => $code,
        'runways' => $runways,
        'count' => count($runways),
    ], $format, 'reference', $cache_params, $format_options);
}

function handleConnectTimes($code, $format, $cache_params, $format_options) {
    $conn = get_conn_adl();
    if (!$conn) {
        SwimResponse::error('Database unavailable', 503, 'SERVICE_UNAVAILABLE');
    }

    $sql = "SELECT airport_icao, unimpeded_connect_sec, sample_size, confidence, last_refreshed_utc
            FROM dbo.airport_connect_reference
            WHERE airport_icao = ?";
    $stmt = sqlsrv_query($conn, $sql, [$code]);

    if ($stmt === false) {
        SwimResponse::error('Database query failed', 500, 'DB_ERROR');
    }

    $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    sqlsrv_free_stmt($stmt);

    if (!$row) {
        SwimResponse::error("No connect time data for: $code", 404, 'NOT_FOUND');
    }

    foreach ($row as $k => $v) {
        if ($v instanceof DateTime) {
            $row[$k] = $v->format('c');
        }
    }

    SwimResponse::formatted([
        'airport' => $code,
        'connect_time' => $row,
        'methodology' => [
            'description' => 'Unimpeded connect-to-push time, 90-day rolling window',
            'default_connect_sec' => 900,
            'refresh_schedule' => 'Daily at 02:15Z',
        ],
    ], $format, 'reference', $cache_params, $format_options);
}

function handleSearch($include_geometry, $format, $cache_params, $format_options) {
    $q = swim_get_param('q');
    $near = swim_get_param('near');
    $radius = swim_get_int_param('radius', 25, 1, 250);
    $country = swim_get_param('country');
    $airport_class = swim_get_param('class');
    $min_runway = swim_get_int_param('min_runway_ft', 0, 0, 99999);
    $page = swim_get_int_param('page', 1, 1, 10000);
    $per_page = swim_get_int_param('per_page', 50, 1, 100);

    if (!$q && !$near) {
        SwimResponse::error('Provide q (text search) or near (lat,lon) parameter', 400, 'MISSING_PARAM');
    }

    $conn = get_conn_gis();
    if (!$conn) {
        SwimResponse::error('GIS service unavailable', 503, 'SERVICE_UNAVAILABLE');
    }

    $geom_col = $include_geometry ? ", ST_AsGeoJSON(geom, 5) AS geometry" : "";
    $where = [];
    $params = [];
    $order_by = "name";

    if ($q) {
        $where[] = "(icao_code ILIKE :q OR faa_lid ILIKE :q OR name ILIKE :qw OR city ILIKE :qw)";
        $params[':q'] = $q . '%';
        $params[':qw'] = '%' . $q . '%';
    }

    if ($near) {
        $parts = explode(',', $near);
        if (count($parts) !== 2) {
            SwimResponse::error('near parameter must be lat,lon', 400, 'INVALID_PARAM');
        }
        $lat = (float)$parts[0];
        $lon = (float)$parts[1];
        $radius_m = $radius * 1852;  // nm to meters
        $where[] = "ST_DWithin(geom::geography, ST_SetSRID(ST_MakePoint(:lon, :lat), 4326)::geography, :radius)";
        $params[':lat'] = $lat;
        $params[':lon'] = $lon;
        $params[':radius'] = $radius_m;
        $order_by = "ST_Distance(geom::geography, ST_SetSRID(ST_MakePoint($lon, $lat), 4326)::geography)";
    }

    if ($country) {
        $where[] = "country_code = :country";
        $params[':country'] = strtoupper($country);
    }

    if ($airport_class) {
        $where[] = "airport_class = :class";
        $params[':class'] = $airport_class;
    }

    $where_sql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';
    $offset = ($page - 1) * $per_page;

    // Count
    $count_sql = "SELECT COUNT(*) AS total FROM airports $where_sql";
    $count_stmt = $conn->prepare($count_sql);
    $count_stmt->execute($params);
    $total = (int)($count_stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);

    // Fetch
    $sql = "SELECT icao_code, faa_lid, name, city, state_code, country_code,
                   latitude, longitude, elevation_ft, airport_class $geom_col
            FROM airports $where_sql
            ORDER BY $order_by
            LIMIT :limit OFFSET :offset";
    $params[':limit'] = $per_page;
    $params[':offset'] = $offset;

    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $airports = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($airports as &$a) {
        if (isset($a['geometry'])) {
            $a['geometry'] = json_decode($a['geometry'], true);
        }
    }

    $data = ['airports' => $airports, 'count' => count($airports), 'total' => $total];
    SwimResponse::paginatedFormatted($data, $total, $page, $per_page, $format, 'reference', $cache_params, $format_options);
}
```

- [ ] **Step 3: Test airports endpoints**

```bash
# Lookup
curl -s "https://perti.vatcscc.org/api/swim/v1/reference/airports/lookup?faa=JFK" -H "X-API-Key: KEY"

# Profile
curl -s "https://perti.vatcscc.org/api/swim/v1/reference/airports/KJFK" -H "X-API-Key: KEY"

# Facilities
curl -s "https://perti.vatcscc.org/api/swim/v1/reference/airports/KJFK/facilities" -H "X-API-Key: KEY"

# Runways
curl -s "https://perti.vatcscc.org/api/swim/v1/reference/airports/KJFK/runways" -H "X-API-Key: KEY"

# Search
curl -s "https://perti.vatcscc.org/api/swim/v1/reference/airports/search?q=kennedy" -H "X-API-Key: KEY"

# Proximity search
curl -s "https://perti.vatcscc.org/api/swim/v1/reference/airports/search?near=40.64,-73.78&radius=20" -H "X-API-Key: KEY"
```

- [ ] **Step 4: Commit**

```bash
git add api/swim/v1/reference/airports.php
git commit -m "feat(swim): add airports reference endpoint with profile, facilities, runways, search"
```

---

### Task 6: Navigation Endpoint

**Files:**
- Create: `api/swim/v1/reference/navigation.php`

Covers fixes, airways, and procedures (DP/STAR). All queries hit PostGIS.

- [ ] **Step 1: Verify PostGIS nav table columns**

```sql
SELECT column_name, data_type FROM information_schema.columns
WHERE table_name IN ('nav_fixes', 'nav_procedures', 'airways', 'airway_segments')
ORDER BY table_name, ordinal_position;
```

- [ ] **Step 2: Write navigation.php**

Create `api/swim/v1/reference/navigation.php`:

```php
<?php
/**
 * VATSWIM API v1 - Navigation Reference Data
 *
 * @version 1.0.0
 * @since 2026-04-05
 *
 * Endpoints:
 *   GET /reference/navigation/fixes              - List/search fixes
 *   GET /reference/navigation/fixes/{name}        - Fix detail (may return array)
 *   GET /reference/navigation/airways             - List airways
 *   GET /reference/navigation/airways/{name}      - Airway with segments + geometry
 *   GET /reference/navigation/airways/{name}/segment?from=X&to=Y - Partial airway
 *   GET /reference/navigation/procedures          - List DPs/STARs
 *   GET /reference/navigation/procedures/{code}   - Procedure detail by computer_code
 *   GET /reference/navigation/procedures/airport/{icao}?type=DP - Per-airport list
 */

require_once __DIR__ . '/../auth.php';

$auth = swim_init_auth(true, false);

$request_uri = $_SERVER['REQUEST_URI'] ?? '';
$path = parse_url($request_uri, PHP_URL_PATH);
$path = preg_replace('#^.*/reference/navigation/?#', '', $path);
$path_parts = array_values(array_filter(explode('/', $path)));

$sub = $path_parts[0] ?? null;
$code = isset($path_parts[1]) ? trim($path_parts[1]) : null;
$action = $path_parts[2] ?? null;

$format = swim_validate_format(swim_get_param('format', 'json'), 'reference');
$include_geometry = in_array('geometry', explode(',', swim_get_param('include', '')));

$format_options = [
    'root' => 'swim_navigation',
    'item' => 'nav_element',
    'name' => 'VATSWIM Navigation Reference',
    'filename' => 'swim_nav_' . date('Ymd_His')
];

$cache_params = array_filter([
    'sub' => $sub, 'code' => $code, 'action' => $action,
    'name' => swim_get_param('name'), 'type' => swim_get_param('type'),
    'near' => swim_get_param('near'), 'artcc' => swim_get_param('artcc'),
    'airport' => swim_get_param('airport'), 'contains_fix' => swim_get_param('contains_fix'),
    'from' => swim_get_param('from'), 'to' => swim_get_param('to'),
    'include' => swim_get_param('include'),
    'page' => swim_get_param('page'),
    'format' => $format !== 'json' ? $format : null,
], fn($v) => $v !== null && $v !== '');

if (SwimResponse::tryCachedFormatted('reference_nav', $cache_params, $format, $format_options)) {
    exit;
}

$conn = get_conn_gis();
if (!$conn) {
    SwimResponse::error('GIS service unavailable', 503, 'SERVICE_UNAVAILABLE');
}

switch ($sub) {
    case 'fixes':
        if ($code) {
            handleFixDetail($conn, strtoupper($code), $include_geometry, $format, $cache_params, $format_options);
        } else {
            handleFixList($conn, $include_geometry, $format, $cache_params, $format_options);
        }
        break;

    case 'airways':
        if ($code && $action === 'segment') {
            handleAirwaySegment($conn, strtoupper($code), $format, $cache_params, $format_options);
        } elseif ($code) {
            handleAirwayDetail($conn, strtoupper($code), $include_geometry, $format, $cache_params, $format_options);
        } else {
            handleAirwayList($conn, $format, $cache_params, $format_options);
        }
        break;

    case 'procedures':
        if ($code === 'airport' && isset($path_parts[2])) {
            handleProceduresByAirport($conn, strtoupper($path_parts[2]), $format, $cache_params, $format_options);
        } elseif ($code) {
            handleProcedureDetail($conn, $code, $include_geometry, $format, $cache_params, $format_options);
        } else {
            handleProcedureList($conn, $format, $cache_params, $format_options);
        }
        break;

    default:
        SwimResponse::error("Unknown navigation sub-resource: $sub. Use 'fixes', 'airways', or 'procedures'.", 400, 'INVALID_RESOURCE');
}

// === FIX HANDLERS ===

function handleFixDetail($conn, $name, $include_geometry, $format, $cache_params, $format_options) {
    $geom = $include_geometry ? ", ST_AsGeoJSON(geom, 5) AS geometry" : "";
    $sql = "SELECT fix_name, latitude, longitude, fix_type, artcc_code, country_code,
                   is_superseded, airac_cycle $geom
            FROM nav_fixes
            WHERE fix_name = :name AND (is_superseded = false OR is_superseded IS NULL)
            ORDER BY country_code";
    $stmt = $conn->prepare($sql);
    $stmt->execute([':name' => $name]);
    $fixes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($fixes)) {
        SwimResponse::error("Fix not found: $name", 404, 'NOT_FOUND');
    }

    foreach ($fixes as &$f) {
        if (isset($f['geometry'])) $f['geometry'] = json_decode($f['geometry'], true);
        $f['latitude'] = (float)$f['latitude'];
        $f['longitude'] = (float)$f['longitude'];
    }

    SwimResponse::formatted([
        'fix_name' => $name,
        'locations' => $fixes,
        'count' => count($fixes),
        'note' => count($fixes) > 1 ? 'Fix name exists in multiple locations' : null,
    ], $format, 'reference_nav', $cache_params, $format_options);
}

function handleFixList($conn, $include_geometry, $format, $cache_params, $format_options) {
    $name = swim_get_param('name');
    $type = swim_get_param('type');
    $near = swim_get_param('near');
    $radius = swim_get_int_param('radius', 25, 1, 250);
    $artcc = swim_get_param('artcc');
    $country = swim_get_param('country');
    $page = swim_get_int_param('page', 1, 1, 10000);
    $per_page = swim_get_int_param('per_page', 100, 1, 200);

    $where = ["(is_superseded = false OR is_superseded IS NULL)"];
    $params = [];

    if ($name) {
        if (str_contains($name, '*')) {
            $where[] = "fix_name LIKE :name";
            $params[':name'] = str_replace('*', '%', $name);
        } else {
            $where[] = "fix_name = :name";
            $params[':name'] = strtoupper($name);
        }
    }
    if ($type) {
        $where[] = "fix_type = :type";
        $params[':type'] = strtoupper($type);
    }
    if ($artcc) {
        $where[] = "artcc_code = :artcc";
        $params[':artcc'] = strtoupper($artcc);
    }
    if ($country) {
        $where[] = "country_code = :country";
        $params[':country'] = strtoupper($country);
    }

    $order_by = "fix_name";
    if ($near) {
        $parts = explode(',', $near);
        if (count($parts) !== 2) SwimResponse::error('near must be lat,lon', 400, 'INVALID_PARAM');
        $lat = (float)$parts[0];
        $lon = (float)$parts[1];
        $radius_m = $radius * 1852;
        $where[] = "ST_DWithin(geom::geography, ST_SetSRID(ST_MakePoint(:lon, :lat), 4326)::geography, :radius)";
        $params[':lat'] = $lat;
        $params[':lon'] = $lon;
        $params[':radius'] = $radius_m;
        $order_by = "ST_Distance(geom::geography, ST_SetSRID(ST_MakePoint($lon, $lat), 4326)::geography)";
    }

    $where_sql = 'WHERE ' . implode(' AND ', $where);
    $offset = ($page - 1) * $per_page;

    $geom = $include_geometry ? ", ST_AsGeoJSON(geom, 5) AS geometry" : "";

    $count_sql = "SELECT COUNT(*) AS total FROM nav_fixes $where_sql";
    $count_stmt = $conn->prepare($count_sql);
    $count_stmt->execute($params);
    $total = (int)($count_stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);

    $sql = "SELECT fix_name, latitude, longitude, fix_type, artcc_code, country_code $geom
            FROM nav_fixes $where_sql ORDER BY $order_by LIMIT :limit OFFSET :offset";
    $params[':limit'] = $per_page;
    $params[':offset'] = $offset;

    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $fixes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($fixes as &$f) {
        if (isset($f['geometry'])) $f['geometry'] = json_decode($f['geometry'], true);
        $f['latitude'] = (float)$f['latitude'];
        $f['longitude'] = (float)$f['longitude'];
    }

    $data = ['fixes' => $fixes, 'count' => count($fixes), 'total' => $total];
    SwimResponse::paginatedFormatted($data, $total, $page, $per_page, $format, 'reference_nav', $cache_params, $format_options);
}

// === AIRWAY HANDLERS ===

function handleAirwayDetail($conn, $name, $include_geometry, $format, $cache_params, $format_options) {
    $geom = $include_geometry ? ", ST_AsGeoJSON(geom, 5) AS geometry" : "";

    // Get segments
    $sql = "SELECT sequence_num, fix_from, fix_to, course, distance_nm, min_altitude_ft $geom
            FROM airway_segments
            WHERE airway_name = :name
            ORDER BY sequence_num";
    $stmt = $conn->prepare($sql);
    $stmt->execute([':name' => $name]);
    $segments = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($segments)) {
        SwimResponse::error("Airway not found: $name", 404, 'NOT_FOUND');
    }

    foreach ($segments as &$s) {
        if (isset($s['geometry'])) $s['geometry'] = json_decode($s['geometry'], true);
        $s['distance_nm'] = isset($s['distance_nm']) ? round((float)$s['distance_nm'], 1) : null;
    }

    $total_distance = array_sum(array_column($segments, 'distance_nm'));

    // Get full airway geometry if requested
    $full_geom = null;
    if ($include_geometry) {
        $geom_sql = "SELECT ST_AsGeoJSON(geom, 5) AS geometry FROM airways WHERE airway_name = :name LIMIT 1";
        $geom_stmt = $conn->prepare($geom_sql);
        $geom_stmt->execute([':name' => $name]);
        $geom_row = $geom_stmt->fetch(PDO::FETCH_ASSOC);
        if ($geom_row) $full_geom = json_decode($geom_row['geometry'], true);
    }

    SwimResponse::formatted([
        'airway_name' => $name,
        'segments' => $segments,
        'segment_count' => count($segments),
        'total_distance_nm' => round($total_distance, 1),
        'geometry' => $full_geom,
    ], $format, 'reference_nav', $cache_params, $format_options);
}

function handleAirwaySegment($conn, $name, $format, $cache_params, $format_options) {
    $from = strtoupper(swim_get_param('from', ''));
    $to = strtoupper(swim_get_param('to', ''));

    if (!$from || !$to) {
        SwimResponse::error('Both from and to fix parameters required', 400, 'MISSING_PARAM');
    }

    // Use PostGIS expand_airway function
    $sql = "SELECT * FROM expand_airway(:name, :from_fix, :to_fix)";
    $stmt = $conn->prepare($sql);
    $stmt->execute([':name' => $name, ':from_fix' => $from, ':to_fix' => $to]);
    $result = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($result)) {
        SwimResponse::error("Could not expand airway $name from $from to $to", 404, 'NOT_FOUND');
    }

    SwimResponse::formatted([
        'airway_name' => $name,
        'from_fix' => $from,
        'to_fix' => $to,
        'waypoints' => $result,
        'count' => count($result),
    ], $format, 'reference_nav', $cache_params, $format_options);
}

function handleAirwayList($conn, $format, $cache_params, $format_options) {
    $name = swim_get_param('name');
    $type = swim_get_param('type');
    $contains_fix = swim_get_param('contains_fix');
    $page = swim_get_int_param('page', 1, 1, 10000);
    $per_page = swim_get_int_param('per_page', 100, 1, 200);

    $where = ["(is_superseded = false OR is_superseded IS NULL)"];
    $params = [];

    if ($name) {
        if (str_contains($name, '*')) {
            $where[] = "airway_name LIKE :name";
            $params[':name'] = str_replace('*', '%', $name);
        } else {
            $where[] = "airway_name = :name";
            $params[':name'] = strtoupper($name);
        }
    }
    if ($type) {
        $where[] = "airway_name LIKE :type_prefix";
        $params[':type_prefix'] = strtoupper($type) . '%';
    }
    if ($contains_fix) {
        // Airways containing a specific fix
        $where[] = "airway_name IN (SELECT DISTINCT airway_name FROM airway_segments WHERE fix_from = :fix OR fix_to = :fix2)";
        $params[':fix'] = strtoupper($contains_fix);
        $params[':fix2'] = strtoupper($contains_fix);
    }

    $where_sql = 'WHERE ' . implode(' AND ', $where);
    $offset = ($page - 1) * $per_page;

    $count_sql = "SELECT COUNT(DISTINCT airway_name) AS total FROM airways $where_sql";
    $count_stmt = $conn->prepare($count_sql);
    $count_stmt->execute($params);
    $total = (int)($count_stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);

    $sql = "SELECT airway_name, airway_type, airac_cycle
            FROM airways $where_sql
            ORDER BY airway_name
            LIMIT :limit OFFSET :offset";
    $params[':limit'] = $per_page;
    $params[':offset'] = $offset;

    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $airways = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $data = ['airways' => $airways, 'count' => count($airways), 'total' => $total];
    SwimResponse::paginatedFormatted($data, $total, $page, $per_page, $format, 'reference_nav', $cache_params, $format_options);
}

// === PROCEDURE HANDLERS ===

function handleProcedureDetail($conn, $computer_code, $include_geometry, $format, $cache_params, $format_options) {
    $geom = $include_geometry ? ", ST_AsGeoJSON(geom, 5) AS geometry" : "";
    $sql = "SELECT computer_code, procedure_name, procedure_type, airport_icao,
                   transition_name, transition_type, route_string, waypoints,
                   source, airac_cycle, is_superseded $geom
            FROM nav_procedures
            WHERE computer_code = :code
            LIMIT 1";
    $stmt = $conn->prepare($sql);
    $stmt->execute([':code' => $computer_code]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        SwimResponse::error("Procedure not found: $computer_code", 404, 'NOT_FOUND');
    }

    if (isset($row['geometry'])) $row['geometry'] = json_decode($row['geometry'], true);
    if (isset($row['waypoints']) && is_string($row['waypoints'])) {
        $row['waypoints'] = json_decode($row['waypoints'], true);
    }

    SwimResponse::formatted(['procedure' => $row], $format, 'reference_nav', $cache_params, $format_options);
}

function handleProcedureList($conn, $format, $cache_params, $format_options) {
    $airport = swim_get_param('airport');
    $type = swim_get_param('type');
    $name = swim_get_param('name');
    $transition = swim_get_param('transition');
    $trans_type = swim_get_param('transition_type');
    $source = swim_get_param('source');
    $page = swim_get_int_param('page', 1, 1, 10000);
    $per_page = swim_get_int_param('per_page', 100, 1, 200);

    $where = ["(is_superseded = false OR is_superseded IS NULL)"];
    $params = [];

    if ($airport) { $where[] = "airport_icao = :airport"; $params[':airport'] = strtoupper($airport); }
    if ($type) { $where[] = "procedure_type = :type"; $params[':type'] = strtoupper($type); }
    if ($name) {
        if (str_contains($name, '*')) {
            $where[] = "procedure_name LIKE :name";
            $params[':name'] = str_replace('*', '%', strtoupper($name));
        } else {
            $where[] = "procedure_name = :name";
            $params[':name'] = strtoupper($name);
        }
    }
    if ($transition) { $where[] = "transition_name = :trans"; $params[':trans'] = strtoupper($transition); }
    if ($trans_type) { $where[] = "transition_type = :ttype"; $params[':ttype'] = $trans_type; }
    if ($source) { $where[] = "source = :source"; $params[':source'] = $source; }

    $where_sql = 'WHERE ' . implode(' AND ', $where);
    $offset = ($page - 1) * $per_page;

    $count_sql = "SELECT COUNT(*) AS total FROM nav_procedures $where_sql";
    $count_stmt = $conn->prepare($count_sql);
    $count_stmt->execute($params);
    $total = (int)($count_stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);

    $sql = "SELECT computer_code, procedure_name, procedure_type, airport_icao,
                   transition_name, transition_type, source, airac_cycle
            FROM nav_procedures $where_sql
            ORDER BY airport_icao, procedure_type, procedure_name
            LIMIT :limit OFFSET :offset";
    $params[':limit'] = $per_page;
    $params[':offset'] = $offset;

    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $procs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $data = ['procedures' => $procs, 'count' => count($procs), 'total' => $total];
    SwimResponse::paginatedFormatted($data, $total, $page, $per_page, $format, 'reference_nav', $cache_params, $format_options);
}

function handleProceduresByAirport($conn, $icao, $format, $cache_params, $format_options) {
    $type = swim_get_param('type');
    $where = ["airport_icao = :airport", "(is_superseded = false OR is_superseded IS NULL)"];
    $params = [':airport' => $icao];

    if ($type) {
        $where[] = "procedure_type = :type";
        $params[':type'] = strtoupper($type);
    }

    $sql = "SELECT procedure_name, procedure_type, transition_name, transition_type, computer_code, source
            FROM nav_procedures
            WHERE " . implode(' AND ', $where) . "
            ORDER BY procedure_type, procedure_name, transition_type, transition_name";
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Group by procedure name
    $grouped = [];
    foreach ($rows as $row) {
        $key = $row['procedure_type'] . ':' . $row['procedure_name'];
        if (!isset($grouped[$key])) {
            $grouped[$key] = [
                'procedure_name' => $row['procedure_name'],
                'procedure_type' => $row['procedure_type'],
                'transitions' => [],
            ];
        }
        $grouped[$key]['transitions'][] = [
            'transition_name' => $row['transition_name'],
            'transition_type' => $row['transition_type'],
            'computer_code' => $row['computer_code'],
        ];
    }

    SwimResponse::formatted([
        'airport' => $icao,
        'procedures' => array_values($grouped),
        'count' => count($grouped),
    ], $format, 'reference_nav', $cache_params, $format_options);
}
```

- [ ] **Step 3: Test navigation endpoints**

```bash
# Fix lookup
curl -s "https://perti.vatcscc.org/api/swim/v1/reference/navigation/fixes/MERIT" -H "X-API-Key: KEY"

# Fix search near
curl -s "https://perti.vatcscc.org/api/swim/v1/reference/navigation/fixes?near=40.6,-73.8&radius=10" -H "X-API-Key: KEY"

# Airway detail
curl -s "https://perti.vatcscc.org/api/swim/v1/reference/navigation/airways/J60?include=geometry" -H "X-API-Key: KEY"

# Procedures for airport
curl -s "https://perti.vatcscc.org/api/swim/v1/reference/navigation/procedures/airport/KJFK?type=STAR" -H "X-API-Key: KEY"
```

- [ ] **Step 4: Commit**

```bash
git add api/swim/v1/reference/navigation.php
git commit -m "feat(swim): add navigation reference endpoint with fixes, airways, procedures"
```

---

### Task 7: Airspace Endpoint

**Files:**
- Create: `api/swim/v1/reference/airspace.php`

Exposes boundary polygons, sectors, FIRs, and point-in-polygon queries via PostGIS.

- [ ] **Step 1: Verify PostGIS boundary table columns**

```sql
SELECT column_name, data_type FROM information_schema.columns
WHERE table_name IN ('artcc_boundaries', 'tracon_boundaries', 'sector_boundaries')
ORDER BY table_name, ordinal_position;
```

- [ ] **Step 2: Write airspace.php**

Create `api/swim/v1/reference/airspace.php`:

```php
<?php
/**
 * VATSWIM API v1 - Airspace Reference Data
 *
 * @version 1.0.0
 * @since 2026-04-05
 *
 * Endpoints:
 *   GET /reference/airspace/boundaries?type=artcc     - List boundaries by type
 *   GET /reference/airspace/boundaries/{type}/{code}  - Single boundary + geometry
 *   GET /reference/airspace/at-point?lat=X&lon=Y      - Point-in-polygon
 *   GET /reference/airspace/firs?pattern=EG..          - FIR listing
 *   GET /reference/airspace/sectors?artcc=ZNY          - Sector listing
 */

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../../../../load/services/GISService.php';

$auth = swim_init_auth(true, false);

$request_uri = $_SERVER['REQUEST_URI'] ?? '';
$path = parse_url($request_uri, PHP_URL_PATH);
$path = preg_replace('#^.*/reference/airspace/?#', '', $path);
$path_parts = array_values(array_filter(explode('/', $path)));

$sub = $path_parts[0] ?? null;

$format = swim_validate_format(swim_get_param('format', 'json'), 'reference');
$include_geometry = in_array('geometry', explode(',', swim_get_param('include', '')));

$format_options = [
    'root' => 'swim_airspace',
    'item' => 'boundary',
    'name' => 'VATSWIM Airspace Reference',
    'filename' => 'swim_airspace_' . date('Ymd_His')
];

$cache_params = array_filter([
    'sub' => $sub,
    'type' => swim_get_param('type') ?? ($path_parts[1] ?? null),
    'code' => $path_parts[2] ?? null,
    'lat' => swim_get_param('lat'), 'lon' => swim_get_param('lon'),
    'alt' => swim_get_param('alt'),
    'pattern' => swim_get_param('pattern'),
    'artcc' => swim_get_param('artcc'), 'strata' => swim_get_param('strata'),
    'simplify' => swim_get_param('simplify'),
    'include' => swim_get_param('include'),
    'page' => swim_get_param('page'),
    'format' => $format !== 'json' ? $format : null,
], fn($v) => $v !== null && $v !== '');

if (SwimResponse::tryCachedFormatted('reference_airspace', $cache_params, $format, $format_options)) {
    exit;
}

$conn = get_conn_gis();
if (!$conn) {
    SwimResponse::error('GIS service unavailable', 503, 'SERVICE_UNAVAILABLE');
}

switch ($sub) {
    case 'boundaries':
        $type = $path_parts[1] ?? swim_get_param('type');
        $code = isset($path_parts[2]) ? strtoupper($path_parts[2]) : null;
        if ($code) {
            handleBoundaryDetail($conn, $type, $code, $format, $cache_params, $format_options);
        } else {
            handleBoundaryList($conn, $type, $include_geometry, $format, $cache_params, $format_options);
        }
        break;

    case 'at-point':
        handleAtPoint($format, $cache_params, $format_options);
        break;

    case 'firs':
        handleFirs($conn, $include_geometry, $format, $cache_params, $format_options);
        break;

    case 'sectors':
        handleSectors($conn, $include_geometry, $format, $cache_params, $format_options);
        break;

    default:
        SwimResponse::error("Unknown airspace sub-resource: $sub. Use 'boundaries', 'at-point', 'firs', or 'sectors'.", 400, 'INVALID_RESOURCE');
}

function handleBoundaryDetail($conn, $type, $code, $format, $cache_params, $format_options) {
    $simplify = swim_get_param('simplify');
    $geom_expr = $simplify
        ? "ST_AsGeoJSON(ST_Simplify(geom, " . (float)$simplify . "), 5) AS geometry"
        : "ST_AsGeoJSON(geom, 5) AS geometry";

    $table = getBoundaryTable($type);
    if (!$table) {
        SwimResponse::error("Invalid boundary type: $type. Use artcc, tracon, or sector.", 400, 'INVALID_PARAM');
    }

    $code_col = $table['code_col'];
    $sql = "SELECT *, $geom_expr,
                   ST_Area(geom::geography) / 3429904.0 AS area_sq_nm
            FROM {$table['table']}
            WHERE $code_col = :code
            LIMIT 1";
    $stmt = $conn->prepare($sql);
    $stmt->execute([':code' => $code]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        SwimResponse::error("Boundary not found: $type/$code", 404, 'NOT_FOUND');
    }

    $row['geometry'] = json_decode($row['geometry'] ?? 'null', true);
    $row['area_sq_nm'] = isset($row['area_sq_nm']) ? round((float)$row['area_sq_nm'], 1) : null;
    unset($row['geom']);

    SwimResponse::formatted(['boundary' => $row], $format, 'reference_airspace', $cache_params, $format_options);
}

function handleBoundaryList($conn, $type, $include_geometry, $format, $cache_params, $format_options) {
    if (!$type) {
        SwimResponse::error("type parameter required (artcc, tracon, sector)", 400, 'MISSING_PARAM');
    }

    $table = getBoundaryTable($type);
    if (!$table) {
        SwimResponse::error("Invalid type: $type", 400, 'INVALID_PARAM');
    }

    $strata = swim_get_param('strata');
    $artcc = swim_get_param('artcc');
    $page = swim_get_int_param('page', 1, 1, 10000);
    $per_page = swim_get_int_param('per_page', 100, 1, 1000);

    $where = [];
    $params = [];

    if ($strata && $type === 'sector') {
        $where[] = "sector_type = :strata";
        $params[':strata'] = strtoupper($strata);
    }
    if ($artcc && in_array($type, ['tracon', 'sector'])) {
        $where[] = "parent_artcc = :artcc";
        $params[':artcc'] = strtoupper($artcc);
    }

    $where_sql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';
    $geom = $include_geometry ? ", ST_AsGeoJSON(geom, 5) AS geometry" : "";
    $offset = ($page - 1) * $per_page;

    $count_sql = "SELECT COUNT(*) AS total FROM {$table['table']} $where_sql";
    $count_stmt = $conn->prepare($count_sql);
    $count_stmt->execute($params);
    $total = (int)($count_stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);

    $cols = $table['list_cols'];
    $sql = "SELECT $cols $geom FROM {$table['table']} $where_sql
            ORDER BY {$table['code_col']} LIMIT :limit OFFSET :offset";
    $params[':limit'] = $per_page;
    $params[':offset'] = $offset;

    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as &$r) {
        if (isset($r['geometry'])) $r['geometry'] = json_decode($r['geometry'], true);
    }

    $data = ['boundaries' => $rows, 'count' => count($rows), 'total' => $total, 'type' => $type];
    SwimResponse::paginatedFormatted($data, $total, $page, $per_page, $format, 'reference_airspace', $cache_params, $format_options);
}

function handleAtPoint($format, $cache_params, $format_options) {
    $lat = swim_get_param('lat');
    $lon = swim_get_param('lon');
    $alt = swim_get_param('alt');

    if ($lat === null || $lon === null) {
        SwimResponse::error('lat and lon parameters required', 400, 'MISSING_PARAM');
    }

    $gis = GISService::getInstance();
    if (!$gis) {
        SwimResponse::error('GIS service unavailable', 503, 'SERVICE_UNAVAILABLE');
    }

    $result = $gis->getBoundariesAtPoint((float)$lat, (float)$lon, $alt !== null ? (int)$alt : null);

    SwimResponse::formatted([
        'query' => ['lat' => (float)$lat, 'lon' => (float)$lon, 'alt' => $alt !== null ? (int)$alt : null],
        'boundaries' => $result,
    ], $format, 'reference_airspace', $cache_params, $format_options);
}

function handleFirs($conn, $include_geometry, $format, $cache_params, $format_options) {
    $pattern = swim_get_param('pattern');
    $is_oceanic = swim_get_param('is_oceanic');
    $page = swim_get_int_param('page', 1, 1, 10000);
    $per_page = swim_get_int_param('per_page', 100, 1, 1000);

    $where = ["hierarchy_type = 'FIR'"];
    $params = [];

    if ($pattern) {
        $like = str_replace('.', '_', $pattern);
        $like = str_replace('*', '%', $like);
        $where[] = "artcc_code LIKE :pattern";
        $params[':pattern'] = strtoupper($like);
    }
    if ($is_oceanic !== null) {
        $where[] = "is_oceanic = :oceanic";
        $params[':oceanic'] = filter_var($is_oceanic, FILTER_VALIDATE_BOOLEAN);
    }

    $where_sql = 'WHERE ' . implode(' AND ', $where);
    $geom = $include_geometry ? ", ST_AsGeoJSON(geom, 5) AS geometry" : "";
    $offset = ($page - 1) * $per_page;

    $count_sql = "SELECT COUNT(*) AS total FROM artcc_boundaries $where_sql";
    $count_stmt = $conn->prepare($count_sql);
    $count_stmt->execute($params);
    $total = (int)($count_stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);

    $sql = "SELECT artcc_code, artcc_name, hierarchy_type, is_oceanic $geom
            FROM artcc_boundaries $where_sql
            ORDER BY artcc_code LIMIT :limit OFFSET :offset";
    $params[':limit'] = $per_page;
    $params[':offset'] = $offset;

    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $firs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($firs as &$f) {
        if (isset($f['geometry'])) $f['geometry'] = json_decode($f['geometry'], true);
    }

    $data = ['firs' => $firs, 'count' => count($firs), 'total' => $total];
    SwimResponse::paginatedFormatted($data, $total, $page, $per_page, $format, 'reference_airspace', $cache_params, $format_options);
}

function handleSectors($conn, $include_geometry, $format, $cache_params, $format_options) {
    $artcc = swim_get_param('artcc');
    $strata = swim_get_param('strata');
    $page = swim_get_int_param('page', 1, 1, 10000);
    $per_page = swim_get_int_param('per_page', 100, 1, 1000);

    $where = [];
    $params = [];

    if ($artcc) { $where[] = "parent_artcc = :artcc"; $params[':artcc'] = strtoupper($artcc); }
    if ($strata) { $where[] = "sector_type = :strata"; $params[':strata'] = strtoupper($strata); }

    $where_sql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';
    $geom = $include_geometry ? ", ST_AsGeoJSON(geom, 5) AS geometry" : "";
    $offset = ($page - 1) * $per_page;

    $count_sql = "SELECT COUNT(*) AS total FROM sector_boundaries $where_sql";
    $count_stmt = $conn->prepare($count_sql);
    $count_stmt->execute($params);
    $total = (int)($count_stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);

    $sql = "SELECT sector_code, sector_name, parent_artcc, sector_type, floor_fl, ceiling_fl $geom
            FROM sector_boundaries $where_sql
            ORDER BY parent_artcc, sector_code LIMIT :limit OFFSET :offset";
    $params[':limit'] = $per_page;
    $params[':offset'] = $offset;

    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $sectors = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($sectors as &$s) {
        if (isset($s['geometry'])) $s['geometry'] = json_decode($s['geometry'], true);
    }

    $data = ['sectors' => $sectors, 'count' => count($sectors), 'total' => $total];
    SwimResponse::paginatedFormatted($data, $total, $page, $per_page, $format, 'reference_airspace', $cache_params, $format_options);
}

function getBoundaryTable($type) {
    $tables = [
        'artcc' => ['table' => 'artcc_boundaries', 'code_col' => 'artcc_code', 'list_cols' => 'artcc_code, artcc_name, hierarchy_type, is_oceanic'],
        'tracon' => ['table' => 'tracon_boundaries', 'code_col' => 'tracon_code', 'list_cols' => 'tracon_code, tracon_name, parent_artcc'],
        'sector' => ['table' => 'sector_boundaries', 'code_col' => 'sector_code', 'list_cols' => 'sector_code, sector_name, parent_artcc, sector_type, floor_fl, ceiling_fl'],
    ];
    return $tables[strtolower($type)] ?? null;
}
```

- [ ] **Step 3: Test airspace endpoints**

```bash
# List ARTCC boundaries
curl -s "https://perti.vatcscc.org/api/swim/v1/reference/airspace/boundaries?type=artcc" -H "X-API-Key: KEY" | head -c 500

# Single boundary with geometry
curl -s "https://perti.vatcscc.org/api/swim/v1/reference/airspace/boundaries/artcc/KZNY" -H "X-API-Key: KEY"

# Point-in-polygon
curl -s "https://perti.vatcscc.org/api/swim/v1/reference/airspace/at-point?lat=40.64&lon=-73.78" -H "X-API-Key: KEY"

# FIR pattern
curl -s "https://perti.vatcscc.org/api/swim/v1/reference/airspace/firs?pattern=EG.." -H "X-API-Key: KEY"
```

- [ ] **Step 4: Commit**

```bash
git add api/swim/v1/reference/airspace.php
git commit -m "feat(swim): add airspace reference endpoint with boundaries, at-point, FIRs, sectors"
```

---

### Task 8: Facilities Endpoint

**Files:**
- Create: `api/swim/v1/reference/facilities.php`

- [ ] **Step 1: Write facilities.php**

Create `api/swim/v1/reference/facilities.php`:

```php
<?php
/**
 * VATSWIM API v1 - Facility Reference Data
 *
 * @version 1.0.0
 * @since 2026-04-05
 *
 * Endpoints:
 *   GET /reference/facilities/centers                  - List centers/ARTCCs
 *   GET /reference/facilities/centers/{code}           - Center detail
 *   GET /reference/facilities/centers/{code}/tiers     - Tier adjacency
 *   GET /reference/facilities/centers/{code}/sectors   - Sectors in center
 *   GET /reference/facilities/tracons                  - List TRACONs
 *   GET /reference/facilities/tracons/{code}           - TRACON detail
 *   GET /reference/facilities/dcc-regions              - DCC region listing
 *   GET /reference/facilities/lists/{name}             - Curated airport lists
 */

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../../../../load/services/GISService.php';

$auth = swim_init_auth(true, false);

$request_uri = $_SERVER['REQUEST_URI'] ?? '';
$path = parse_url($request_uri, PHP_URL_PATH);
$path = preg_replace('#^.*/reference/facilities/?#', '', $path);
$path_parts = array_values(array_filter(explode('/', $path)));

$sub = $path_parts[0] ?? null;
$code = isset($path_parts[1]) ? strtoupper(trim($path_parts[1])) : null;
$action = $path_parts[2] ?? null;

$format = swim_validate_format(swim_get_param('format', 'json'), 'reference');
$include_geometry = in_array('geometry', explode(',', swim_get_param('include', '')));

$format_options = [
    'root' => 'swim_facilities',
    'item' => 'facility',
    'name' => 'VATSWIM Facility Reference',
    'filename' => 'swim_facilities_' . date('Ymd_His')
];

$cache_params = array_filter([
    'sub' => $sub, 'code' => $code, 'action' => $action,
    'depth' => swim_get_param('depth'), 'strata' => swim_get_param('strata'),
    'artcc' => swim_get_param('artcc'),
    'include' => swim_get_param('include'),
    'format' => $format !== 'json' ? $format : null,
], fn($v) => $v !== null && $v !== '');

if (SwimResponse::tryCachedFormatted('reference_facility', $cache_params, $format, $format_options)) {
    exit;
}

switch ($sub) {
    case 'centers':
        if ($code && $action === 'tiers') {
            handleCenterTiers($code, $format, $cache_params, $format_options);
        } elseif ($code && $action === 'sectors') {
            handleCenterSectors($code, $format, $cache_params, $format_options);
        } elseif ($code) {
            handleCenterDetail($code, $include_geometry, $format, $cache_params, $format_options);
        } else {
            handleCenterList($include_geometry, $format, $cache_params, $format_options);
        }
        break;

    case 'tracons':
        if ($code) {
            handleTraconDetail($code, $include_geometry, $format, $cache_params, $format_options);
        } else {
            handleTraconList($include_geometry, $format, $cache_params, $format_options);
        }
        break;

    case 'dcc-regions':
        handleDccRegions($format, $cache_params, $format_options);
        break;

    case 'lists':
        handleCuratedList($code, $format, $cache_params, $format_options);
        break;

    default:
        SwimResponse::error("Unknown facilities sub-resource: $sub. Use 'centers', 'tracons', 'dcc-regions', or 'lists'.", 400, 'INVALID_RESOURCE');
}

function handleCenterList($include_geometry, $format, $cache_params, $format_options) {
    $conn = get_conn_gis();
    if (!$conn) SwimResponse::error('GIS unavailable', 503, 'SERVICE_UNAVAILABLE');

    $geom = $include_geometry ? ", ST_AsGeoJSON(geom, 5) AS geometry" : "";
    $sql = "SELECT artcc_code, artcc_name, hierarchy_type, is_oceanic $geom
            FROM artcc_boundaries ORDER BY artcc_code";
    $stmt = $conn->query($sql);
    $centers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($centers as &$c) {
        if (isset($c['geometry'])) $c['geometry'] = json_decode($c['geometry'], true);
    }

    SwimResponse::formatted([
        'centers' => $centers,
        'count' => count($centers),
    ], $format, 'reference_facility', $cache_params, $format_options);
}

function handleCenterDetail($code, $include_geometry, $format, $cache_params, $format_options) {
    $conn = get_conn_gis();
    if (!$conn) SwimResponse::error('GIS unavailable', 503, 'SERVICE_UNAVAILABLE');

    $geom = $include_geometry ? ", ST_AsGeoJSON(geom, 5) AS geometry" : "";
    $sql = "SELECT artcc_code, artcc_name, hierarchy_type, is_oceanic $geom
            FROM artcc_boundaries WHERE artcc_code = :code LIMIT 1";
    $stmt = $conn->prepare($sql);
    $stmt->execute([':code' => $code]);
    $center = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$center) SwimResponse::error("Center not found: $code", 404, 'NOT_FOUND');
    if (isset($center['geometry'])) $center['geometry'] = json_decode($center['geometry'], true);

    // Count children
    $tracon_stmt = $conn->prepare("SELECT COUNT(*) AS c FROM tracon_boundaries WHERE parent_artcc = :code");
    $tracon_stmt->execute([':code' => $code]);
    $center['total_tracons'] = (int)($tracon_stmt->fetch(PDO::FETCH_ASSOC)['c'] ?? 0);

    $sector_stmt = $conn->prepare("SELECT COUNT(*) AS c FROM sector_boundaries WHERE parent_artcc = :code");
    $sector_stmt->execute([':code' => $code]);
    $center['total_sectors'] = (int)($sector_stmt->fetch(PDO::FETCH_ASSOC)['c'] ?? 0);

    SwimResponse::formatted(['center' => $center], $format, 'reference_facility', $cache_params, $format_options);
}

function handleCenterTiers($code, $format, $cache_params, $format_options) {
    $depth = swim_get_int_param('depth', 1, 1, 4);

    $gis = GISService::getInstance();
    if (!$gis) SwimResponse::error('GIS unavailable', 503, 'SERVICE_UNAVAILABLE');

    $tiers = $gis->getProximityTiers('ARTCC', $code, (float)$depth, true);

    // Group by tier
    $grouped = [];
    foreach ($tiers as $t) {
        $tier_key = (string)$t['tier'];
        if (!isset($grouped[$tier_key])) $grouped[$tier_key] = [];
        $grouped[$tier_key][] = $t;
    }

    SwimResponse::formatted([
        'center' => $code,
        'max_depth' => $depth,
        'tiers' => $grouped,
        'total_neighbors' => count($tiers),
    ], $format, 'reference_facility', $cache_params, $format_options);
}

function handleCenterSectors($code, $format, $cache_params, $format_options) {
    $strata = swim_get_param('strata');
    $conn = get_conn_gis();
    if (!$conn) SwimResponse::error('GIS unavailable', 503, 'SERVICE_UNAVAILABLE');

    $where = ["parent_artcc = :code"];
    $params = [':code' => $code];
    if ($strata) { $where[] = "sector_type = :strata"; $params[':strata'] = strtoupper($strata); }

    $sql = "SELECT sector_code, sector_name, sector_type, floor_fl, ceiling_fl
            FROM sector_boundaries WHERE " . implode(' AND ', $where) . "
            ORDER BY sector_type, sector_code";
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $sectors = $stmt->fetchAll(PDO::FETCH_ASSOC);

    SwimResponse::formatted([
        'center' => $code,
        'sectors' => $sectors,
        'count' => count($sectors),
    ], $format, 'reference_facility', $cache_params, $format_options);
}

function handleTraconList($include_geometry, $format, $cache_params, $format_options) {
    $artcc = swim_get_param('artcc');
    $conn = get_conn_gis();
    if (!$conn) SwimResponse::error('GIS unavailable', 503, 'SERVICE_UNAVAILABLE');

    $where = [];
    $params = [];
    if ($artcc) { $where[] = "parent_artcc = :artcc"; $params[':artcc'] = strtoupper($artcc); }
    $where_sql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

    $geom = $include_geometry ? ", ST_AsGeoJSON(geom, 5) AS geometry" : "";
    $sql = "SELECT tracon_code, tracon_name, parent_artcc $geom
            FROM tracon_boundaries $where_sql ORDER BY tracon_code";
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $tracons = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($tracons as &$t) {
        if (isset($t['geometry'])) $t['geometry'] = json_decode($t['geometry'], true);
    }

    SwimResponse::formatted(['tracons' => $tracons, 'count' => count($tracons)], $format, 'reference_facility', $cache_params, $format_options);
}

function handleTraconDetail($code, $include_geometry, $format, $cache_params, $format_options) {
    $conn = get_conn_gis();
    if (!$conn) SwimResponse::error('GIS unavailable', 503, 'SERVICE_UNAVAILABLE');

    $geom = $include_geometry ? ", ST_AsGeoJSON(geom, 5) AS geometry" : "";
    $sql = "SELECT tracon_code, tracon_name, parent_artcc $geom
            FROM tracon_boundaries WHERE tracon_code = :code LIMIT 1";
    $stmt = $conn->prepare($sql);
    $stmt->execute([':code' => $code]);
    $tracon = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$tracon) SwimResponse::error("TRACON not found: $code", 404, 'NOT_FOUND');
    if (isset($tracon['geometry'])) $tracon['geometry'] = json_decode($tracon['geometry'], true);

    // Get airports within this TRACON via spatial containment
    $apt_sql = "SELECT a.icao_code, a.faa_lid, a.name
                FROM airports a, tracon_boundaries t
                WHERE t.tracon_code = :code
                AND ST_Contains(t.geom, a.geom)
                ORDER BY a.icao_code";
    $apt_stmt = $conn->prepare($apt_sql);
    $apt_stmt->execute([':code' => $code]);
    $airports = $apt_stmt->fetchAll(PDO::FETCH_ASSOC);

    $tracon['airports'] = $airports;
    $tracon['airport_count'] = count($airports);

    SwimResponse::formatted(['tracon' => $tracon], $format, 'reference_facility', $cache_params, $format_options);
}

function handleDccRegions($format, $cache_params, $format_options) {
    $hierarchy_file = __DIR__ . '/../../../../assets/data/hierarchy.json';
    if (!file_exists($hierarchy_file)) {
        SwimResponse::error('Hierarchy data not available', 503, 'SERVICE_UNAVAILABLE');
    }

    $data = json_decode(file_get_contents($hierarchy_file), true);
    $regions = [];

    // Extract DCC regions from VATUSA
    foreach ($data['regions'] ?? [] as $region) {
        foreach ($region['divisions'] ?? [] as $div) {
            if (!empty($div['dcc_regions'])) {
                foreach ($div['dcc_regions'] as $dcc) {
                    $regions[] = [
                        'code' => $dcc['code'],
                        'name' => $dcc['name'],
                        'division' => $div['code'],
                        'centers' => $dcc['centers'],
                        'center_count' => count($dcc['centers']),
                    ];
                }
            }
        }
    }

    SwimResponse::formatted(['dcc_regions' => $regions, 'count' => count($regions)], $format, 'reference_facility', $cache_params, $format_options);
}

function handleCuratedList($name, $format, $cache_params, $format_options) {
    if (!$name) {
        SwimResponse::error('List name required. Valid: oep35, core30, aspm82, opsnet45', 400, 'MISSING_PARAM');
    }

    $hierarchy_file = __DIR__ . '/../../../../assets/data/hierarchy.json';
    if (!file_exists($hierarchy_file)) {
        SwimResponse::error('List data not available', 503, 'SERVICE_UNAVAILABLE');
    }

    $data = json_decode(file_get_contents($hierarchy_file), true);
    $list_key = strtolower($name);

    if (!isset($data['curated_lists'][$list_key])) {
        SwimResponse::error("Unknown list: $name. Valid: oep35, core30, aspm82, opsnet45", 404, 'NOT_FOUND');
    }

    $list = $data['curated_lists'][$list_key];

    SwimResponse::formatted([
        'list' => $list,
        'count' => count($list['airports']),
    ], $format, 'reference_facility', $cache_params, $format_options);
}
```

- [ ] **Step 2: Test facilities endpoints**

```bash
curl -s "https://perti.vatcscc.org/api/swim/v1/reference/facilities/centers" -H "X-API-Key: KEY" | head -c 500
curl -s "https://perti.vatcscc.org/api/swim/v1/reference/facilities/centers/KZNY" -H "X-API-Key: KEY"
curl -s "https://perti.vatcscc.org/api/swim/v1/reference/facilities/centers/KZNY/tiers?depth=2" -H "X-API-Key: KEY"
curl -s "https://perti.vatcscc.org/api/swim/v1/reference/facilities/lists/oep35" -H "X-API-Key: KEY"
```

- [ ] **Step 3: Commit**

```bash
git add api/swim/v1/reference/facilities.php
git commit -m "feat(swim): add facilities reference endpoint with centers, TRACONs, DCC regions, curated lists"
```

---

### Task 9: Routes Endpoint

**Files:**
- Create: `api/swim/v1/reference/routes.php`

Queries MySQL `route_history_facts` star schema.

- [ ] **Step 1: Verify MySQL route history schema**

```sql
SELECT * FROM route_history_facts LIMIT 1;
SELECT * FROM dim_route LIMIT 1;
SELECT * FROM dim_aircraft_type LIMIT 1;
DESCRIBE route_history_facts;
DESCRIBE dim_route;
```

- [ ] **Step 2: Write routes.php**

Create `api/swim/v1/reference/routes.php`:

```php
<?php
/**
 * VATSWIM API v1 - Route Reference Data
 *
 * @version 1.0.0
 * @since 2026-04-05
 *
 * Endpoints:
 *   GET /reference/routes/popular?origin=KJFK&dest=KLAX    - Most popular routes
 *   GET /reference/routes/statistics?origin=KJFK&dest=KLAX  - Aggregate city-pair stats
 */

require_once __DIR__ . '/../auth.php';

$auth = swim_init_auth(true, false);

$request_uri = $_SERVER['REQUEST_URI'] ?? '';
$path = parse_url($request_uri, PHP_URL_PATH);
$path = preg_replace('#^.*/reference/routes/?#', '', $path);
$path_parts = array_values(array_filter(explode('/', $path)));

$sub = $path_parts[0] ?? null;

$format = swim_validate_format(swim_get_param('format', 'json'), 'reference');
$format_options = [
    'root' => 'swim_routes',
    'item' => 'route',
    'name' => 'VATSWIM Route Reference',
    'filename' => 'swim_routes_' . date('Ymd_His')
];

$cache_params = array_filter([
    'sub' => $sub,
    'origin' => swim_get_param('origin'),
    'dest' => swim_get_param('dest'),
    'format' => $format !== 'json' ? $format : null,
], fn($v) => $v !== null && $v !== '');

if (SwimResponse::tryCachedFormatted('reference_route', $cache_params, $format, $format_options)) {
    exit;
}

switch ($sub) {
    case 'popular':
        handlePopularRoutes($format, $cache_params, $format_options);
        break;
    case 'statistics':
        handleRouteStatistics($format, $cache_params, $format_options);
        break;
    default:
        SwimResponse::error("Unknown routes sub-resource: $sub. Use 'popular' or 'statistics'.", 400, 'INVALID_RESOURCE');
}

function handlePopularRoutes($format, $cache_params, $format_options) {
    $origin = strtoupper(swim_get_param('origin', ''));
    $dest = strtoupper(swim_get_param('dest', ''));

    if (!$origin || !$dest) {
        SwimResponse::error('Both origin and dest parameters required', 400, 'MISSING_PARAM');
    }

    global $conn_pdo;
    if (!$conn_pdo) {
        SwimResponse::error('Database unavailable', 503, 'SERVICE_UNAVAILABLE');
    }

    $sql = "SELECT r.route_string,
                   COUNT(*) AS frequency,
                   AVG(f.flight_time_sec) AS avg_flight_time_sec,
                   MAX(t.date) AS last_seen
            FROM route_history_facts f
            JOIN dim_route r ON f.route_id = r.id
            JOIN dim_time t ON f.time_id = t.id
            WHERE r.origin_icao = :origin AND r.dest_icao = :dest
            GROUP BY r.route_string
            ORDER BY frequency DESC
            LIMIT 20";

    $stmt = $conn_pdo->prepare($sql);
    $stmt->execute([':origin' => $origin, ':dest' => $dest]);
    $routes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $total_flights = array_sum(array_column($routes, 'frequency'));

    foreach ($routes as &$r) {
        $r['frequency'] = (int)$r['frequency'];
        $r['avg_flight_time_sec'] = $r['avg_flight_time_sec'] !== null ? (int)round($r['avg_flight_time_sec']) : null;
        $r['percentage'] = $total_flights > 0 ? round(100.0 * $r['frequency'] / $total_flights, 1) : 0;
    }

    SwimResponse::formatted([
        'origin' => $origin,
        'dest' => $dest,
        'routes' => $routes,
        'count' => count($routes),
        'total_flights_sampled' => $total_flights,
    ], $format, 'reference_route', $cache_params, $format_options);
}

function handleRouteStatistics($format, $cache_params, $format_options) {
    $origin = strtoupper(swim_get_param('origin', ''));
    $dest = strtoupper(swim_get_param('dest', ''));

    if (!$origin || !$dest) {
        SwimResponse::error('Both origin and dest parameters required', 400, 'MISSING_PARAM');
    }

    global $conn_pdo;
    if (!$conn_pdo) {
        SwimResponse::error('Database unavailable', 503, 'SERVICE_UNAVAILABLE');
    }

    // Aggregate stats
    $sql = "SELECT COUNT(*) AS total_flights,
                   COUNT(DISTINCT r.route_string) AS unique_routes,
                   AVG(f.flight_time_sec) AS avg_flight_time_sec,
                   MIN(t.date) AS earliest_date,
                   MAX(t.date) AS latest_date
            FROM route_history_facts f
            JOIN dim_route r ON f.route_id = r.id
            JOIN dim_time t ON f.time_id = t.id
            WHERE r.origin_icao = :origin AND r.dest_icao = :dest";
    $stmt = $conn_pdo->prepare($sql);
    $stmt->execute([':origin' => $origin, ':dest' => $dest]);
    $stats = $stmt->fetch(PDO::FETCH_ASSOC);

    // Common aircraft types
    $type_sql = "SELECT at.icao_code, COUNT(*) AS flights
                 FROM route_history_facts f
                 JOIN dim_route r ON f.route_id = r.id
                 JOIN dim_aircraft_type at ON f.aircraft_type_id = at.id
                 WHERE r.origin_icao = :origin AND r.dest_icao = :dest
                 GROUP BY at.icao_code
                 ORDER BY flights DESC LIMIT 10";
    $type_stmt = $conn_pdo->prepare($type_sql);
    $type_stmt->execute([':origin' => $origin, ':dest' => $dest]);
    $common_types = $type_stmt->fetchAll(PDO::FETCH_ASSOC);

    SwimResponse::formatted([
        'origin' => $origin,
        'dest' => $dest,
        'statistics' => [
            'total_flights' => (int)($stats['total_flights'] ?? 0),
            'unique_routes' => (int)($stats['unique_routes'] ?? 0),
            'avg_flight_time_sec' => $stats['avg_flight_time_sec'] !== null ? (int)round($stats['avg_flight_time_sec']) : null,
            'date_range' => [
                'earliest' => $stats['earliest_date'],
                'latest' => $stats['latest_date'],
            ],
        ],
        'common_aircraft' => $common_types,
    ], $format, 'reference_route', $cache_params, $format_options);
}
```

- [ ] **Step 3: Test routes endpoints**

```bash
curl -s "https://perti.vatcscc.org/api/swim/v1/reference/routes/popular?origin=KJFK&dest=KLAX" -H "X-API-Key: KEY"
curl -s "https://perti.vatcscc.org/api/swim/v1/reference/routes/statistics?origin=KJFK&dest=KLAX" -H "X-API-Key: KEY"
```

- [ ] **Step 4: Commit**

```bash
git add api/swim/v1/reference/routes.php
git commit -m "feat(swim): add routes reference endpoint with popular routes and city-pair statistics"
```

---

### Task 10: AIRAC Endpoint

**Files:**
- Create: `api/swim/v1/reference/airac.php`

- [ ] **Step 1: Verify navdata_changelogs columns in VATSIM_REF**

```sql
SELECT TOP 1 * FROM dbo.navdata_changelogs;
```

- [ ] **Step 2: Write airac.php**

Create `api/swim/v1/reference/airac.php`:

```php
<?php
/**
 * VATSWIM API v1 - AIRAC Cycle Reference Data
 *
 * @version 1.0.0
 * @since 2026-04-05
 *
 * Endpoints:
 *   GET /reference/airac/current              - Current AIRAC cycle metadata
 *   GET /reference/airac/changelog?cycle=2603 - Changes in a specific cycle
 *   GET /reference/airac/superseded?type=procedure - List superseded items
 */

require_once __DIR__ . '/../auth.php';

$auth = swim_init_auth(true, false);

$request_uri = $_SERVER['REQUEST_URI'] ?? '';
$path = parse_url($request_uri, PHP_URL_PATH);
$path = preg_replace('#^.*/reference/airac/?#', '', $path);
$path_parts = array_values(array_filter(explode('/', $path)));

$sub = $path_parts[0] ?? null;

$format = swim_validate_format(swim_get_param('format', 'json'), 'reference');
$format_options = [
    'root' => 'swim_airac',
    'item' => 'airac',
    'name' => 'VATSWIM AIRAC Reference',
    'filename' => 'swim_airac_' . date('Ymd_His')
];

$cache_params = array_filter([
    'sub' => $sub,
    'cycle' => swim_get_param('cycle'),
    'type' => swim_get_param('type'),
    'airport' => swim_get_param('airport'),
    'page' => swim_get_param('page'),
    'format' => $format !== 'json' ? $format : null,
], fn($v) => $v !== null && $v !== '');

if (SwimResponse::tryCachedFormatted('reference_airac', $cache_params, $format, $format_options)) {
    exit;
}

switch ($sub) {
    case 'current':
        handleCurrentCycle($format, $cache_params, $format_options);
        break;
    case 'changelog':
        handleChangelog($format, $cache_params, $format_options);
        break;
    case 'superseded':
        handleSuperseded($format, $cache_params, $format_options);
        break;
    default:
        SwimResponse::error("Unknown AIRAC sub-resource: $sub. Use 'current', 'changelog', or 'superseded'.", 400, 'INVALID_RESOURCE');
}

function handleCurrentCycle($format, $cache_params, $format_options) {
    // AIRAC cycles follow a fixed 28-day schedule starting from a known epoch
    // Epoch: AIRAC 2301 effective 2023-01-26
    $epoch = new DateTime('2023-01-26', new DateTimeZone('UTC'));
    $now = new DateTime('now', new DateTimeZone('UTC'));

    $diff_days = (int)$now->diff($epoch)->days;
    $cycle_num = intdiv($diff_days, 28);
    $current_start = clone $epoch;
    $current_start->modify("+{$cycle_num} cycles");
    // Use actual days
    $days_offset = $cycle_num * 28;
    $current_start = clone $epoch;
    $current_start->modify("+{$days_offset} days");

    $next_start = clone $current_start;
    $next_start->modify('+28 days');

    $days_remaining = (int)$now->diff($next_start)->days;

    // Compute cycle code: YYMM format
    $year_short = (int)$current_start->format('y');
    $cycle_in_year = intdiv((int)$current_start->diff(new DateTime($current_start->format('Y') . '-01-01'))->days, 28) + 1;
    $cycle_code = sprintf('%02d%02d', $year_short, $cycle_in_year);

    $next_year_short = (int)$next_start->format('y');
    $next_cycle_in_year = intdiv((int)$next_start->diff(new DateTime($next_start->format('Y') . '-01-01'))->days, 28) + 1;
    $next_cycle_code = sprintf('%02d%02d', $next_year_short, $next_cycle_in_year);

    SwimResponse::formatted([
        'cycle' => $cycle_code,
        'effective_date' => $current_start->format('Y-m-d'),
        'expiry_date' => $next_start->modify('-1 day')->format('Y-m-d'),
        'next_cycle' => $next_cycle_code,
        'next_effective' => $next_start->modify('+1 day')->format('Y-m-d'),
        'days_remaining' => $days_remaining,
        'data_sources' => ['FAA NASR (US)', 'X-Plane 12 CIFP (International)'],
    ], $format, 'reference_airac', $cache_params, $format_options);
}

function handleChangelog($format, $cache_params, $format_options) {
    $cycle = swim_get_param('cycle');
    $type = swim_get_param('type');
    $airport = swim_get_param('airport');
    $page = swim_get_int_param('page', 1, 1, 10000);
    $per_page = swim_get_int_param('per_page', 100, 1, 1000);

    $conn = get_conn_ref();
    if (!$conn) SwimResponse::error('REF database unavailable', 503, 'SERVICE_UNAVAILABLE');

    $where = [];
    $params = [];

    if ($cycle) { $where[] = "airac_cycle = ?"; $params[] = $cycle; }
    if ($type) { $where[] = "change_type = ?"; $params[] = strtolower($type); }
    if ($airport) { $where[] = "(entity_name LIKE ? OR entity_name = ?)"; $params[] = '%' . strtoupper($airport) . '%'; $params[] = strtoupper($airport); }

    $where_sql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

    // Count
    $count_sql = "SELECT COUNT(*) AS total FROM dbo.navdata_changelogs $where_sql";
    $count_stmt = sqlsrv_query($conn, $count_sql, $params);
    $total = 0;
    if ($count_stmt !== false) {
        $r = sqlsrv_fetch_array($count_stmt, SQLSRV_FETCH_ASSOC);
        $total = (int)($r['total'] ?? 0);
        sqlsrv_free_stmt($count_stmt);
    }

    // Summary by type
    $summary_sql = "SELECT change_type, COUNT(*) AS cnt FROM dbo.navdata_changelogs $where_sql GROUP BY change_type";
    $summary_stmt = sqlsrv_query($conn, $summary_sql, $params);
    $summary = [];
    if ($summary_stmt !== false) {
        while ($sr = sqlsrv_fetch_array($summary_stmt, SQLSRV_FETCH_ASSOC)) {
            $summary[$sr['change_type']] = (int)$sr['cnt'];
        }
        sqlsrv_free_stmt($summary_stmt);
    }

    // Fetch page
    $offset = ($page - 1) * $per_page;
    $sql = "SELECT change_type, entity_type, entity_name, field_name, old_value, new_value, airac_cycle, created_utc
            FROM dbo.navdata_changelogs $where_sql
            ORDER BY created_utc DESC
            OFFSET ? ROWS FETCH NEXT ? ROWS ONLY";
    $params[] = $offset;
    $params[] = $per_page;

    $stmt = sqlsrv_query($conn, $sql, $params);
    if ($stmt === false) SwimResponse::error('Query failed', 500, 'DB_ERROR');

    $changes = [];
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        foreach ($row as $k => $v) {
            if ($v instanceof DateTime) $row[$k] = $v->format('c');
        }
        $changes[] = $row;
    }
    sqlsrv_free_stmt($stmt);

    $data = [
        'changes' => $changes,
        'count' => count($changes),
        'total' => $total,
        'summary' => $summary,
    ];
    SwimResponse::paginatedFormatted($data, $total, $page, $per_page, $format, 'reference_airac', $cache_params, $format_options);
}

function handleSuperseded($format, $cache_params, $format_options) {
    $type = swim_get_param('type');
    $page = swim_get_int_param('page', 1, 1, 10000);
    $per_page = swim_get_int_param('per_page', 100, 1, 500);

    if (!$type) {
        SwimResponse::error("type parameter required (fix, procedure, airway)", 400, 'MISSING_PARAM');
    }

    $conn = get_conn_gis();
    if (!$conn) SwimResponse::error('GIS unavailable', 503, 'SERVICE_UNAVAILABLE');

    $offset = ($page - 1) * $per_page;

    switch (strtolower($type)) {
        case 'fix':
            $count_sql = "SELECT COUNT(*) AS total FROM nav_fixes WHERE is_superseded = true";
            $sql = "SELECT fix_name, latitude, longitude, fix_type, superseded_cycle, superseded_reason, airac_cycle
                    FROM nav_fixes WHERE is_superseded = true ORDER BY fix_name LIMIT :limit OFFSET :offset";
            break;
        case 'procedure':
            $count_sql = "SELECT COUNT(*) AS total FROM nav_procedures WHERE is_superseded = true";
            $sql = "SELECT computer_code, procedure_name, procedure_type, airport_icao, superseded_cycle, source, airac_cycle
                    FROM nav_procedures WHERE is_superseded = true ORDER BY airport_icao, procedure_name LIMIT :limit OFFSET :offset";
            break;
        case 'airway':
            $count_sql = "SELECT COUNT(*) AS total FROM airways WHERE is_superseded = true";
            $sql = "SELECT airway_name, superseded_cycle, airac_cycle
                    FROM airways WHERE is_superseded = true ORDER BY airway_name LIMIT :limit OFFSET :offset";
            break;
        default:
            SwimResponse::error("Invalid type: $type. Use fix, procedure, or airway.", 400, 'INVALID_PARAM');
            return;
    }

    $count_stmt = $conn->query($count_sql);
    $total = (int)($count_stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);

    $stmt = $conn->prepare($sql);
    $stmt->execute([':limit' => $per_page, ':offset' => $offset]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $data = ['type' => $type, 'superseded' => $items, 'count' => count($items), 'total' => $total];
    SwimResponse::paginatedFormatted($data, $total, $page, $per_page, $format, 'reference_airac', $cache_params, $format_options);
}
```

- [ ] **Step 3: Test and commit**

```bash
curl -s "https://perti.vatcscc.org/api/swim/v1/reference/airac/current" -H "X-API-Key: KEY"
curl -s "https://perti.vatcscc.org/api/swim/v1/reference/airac/changelog?cycle=2603&per_page=5" -H "X-API-Key: KEY"
curl -s "https://perti.vatcscc.org/api/swim/v1/reference/airac/superseded?type=procedure&per_page=5" -H "X-API-Key: KEY"

git add api/swim/v1/reference/airac.php
git commit -m "feat(swim): add AIRAC reference endpoint with cycle metadata, changelogs, superseded data"
```

---

### Task 11: Utilities Endpoint

**Files:**
- Create: `api/swim/v1/reference/utilities.php`

Pure computation (distance/bearing) plus proxy to existing route resolve.

- [ ] **Step 1: Write utilities.php**

Create `api/swim/v1/reference/utilities.php`:

```php
<?php
/**
 * VATSWIM API v1 - Utility Functions
 *
 * @version 1.0.0
 * @since 2026-04-05
 *
 * Endpoints:
 *   GET /reference/utilities/distance?from=X&to=Y  - Great circle distance
 *   GET /reference/utilities/bearing?from=X&to=Y   - Bearing between points
 *   GET /reference/utilities/decode-route?route=... - Proxy to routes/resolve
 */

require_once __DIR__ . '/../auth.php';

$auth = swim_init_auth(true, false);

$request_uri = $_SERVER['REQUEST_URI'] ?? '';
$path = parse_url($request_uri, PHP_URL_PATH);
$path = preg_replace('#^.*/reference/utilities/?#', '', $path);
$path_parts = array_values(array_filter(explode('/', $path)));

$sub = $path_parts[0] ?? null;
$format = swim_validate_format(swim_get_param('format', 'json'), 'reference');

$format_options = [
    'root' => 'swim_utilities',
    'item' => 'result',
    'name' => 'VATSWIM Utilities',
    'filename' => 'swim_utility_' . date('Ymd_His')
];

switch ($sub) {
    case 'distance':
        handleDistance($format, $format_options);
        break;
    case 'bearing':
        handleBearing($format, $format_options);
        break;
    case 'decode-route':
        handleDecodeRoute();
        break;
    default:
        SwimResponse::error("Unknown utility: $sub. Use 'distance', 'bearing', or 'decode-route'.", 400, 'INVALID_RESOURCE');
}

function resolvePoint($param_name) {
    $val = swim_get_param($param_name, '');
    if (!$val) return null;

    // Check if it's lat,lon pair
    if (preg_match('/^-?\d+\.?\d*\s*,\s*-?\d+\.?\d*$/', $val)) {
        $parts = array_map('trim', explode(',', $val));
        return ['lat' => (float)$parts[0], 'lon' => (float)$parts[1], 'label' => $val];
    }

    // Otherwise treat as fix/airport code - resolve via PostGIS
    $conn = get_conn_gis();
    if (!$conn) return null;

    $code = strtoupper(trim($val));

    // Try airports first
    $stmt = $conn->prepare("SELECT latitude AS lat, longitude AS lon FROM airports WHERE icao_code = :code OR faa_lid = :code LIMIT 1");
    $stmt->execute([':code' => $code]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) return ['lat' => (float)$row['lat'], 'lon' => (float)$row['lon'], 'label' => $code];

    // Try fixes
    $stmt2 = $conn->prepare("SELECT latitude AS lat, longitude AS lon FROM nav_fixes WHERE fix_name = :code AND (is_superseded = false OR is_superseded IS NULL) LIMIT 1");
    $stmt2->execute([':code' => $code]);
    $row2 = $stmt2->fetch(PDO::FETCH_ASSOC);
    if ($row2) return ['lat' => (float)$row2['lat'], 'lon' => (float)$row2['lon'], 'label' => $code];

    return null;
}

function handleDistance($format, $format_options) {
    $from = resolvePoint('from');
    $to = resolvePoint('to');

    if (!$from) SwimResponse::error("Could not resolve 'from' parameter", 400, 'INVALID_PARAM');
    if (!$to) SwimResponse::error("Could not resolve 'to' parameter", 400, 'INVALID_PARAM');

    $dist = vincentyDistance($from['lat'], $from['lon'], $to['lat'], $to['lon']);
    $bearing = initialBearing($from['lat'], $from['lon'], $to['lat'], $to['lon']);
    $final_bearing = initialBearing($to['lat'], $to['lon'], $from['lat'], $from['lon']);
    $final_bearing = fmod($final_bearing + 180, 360);

    SwimResponse::formatted([
        'from' => $from,
        'to' => $to,
        'distance_nm' => round($dist / 1852, 1),
        'distance_km' => round($dist / 1000, 1),
        'initial_bearing' => round($bearing, 1),
        'final_bearing' => round($final_bearing, 1),
    ], $format, 'reference', [], $format_options);
}

function handleBearing($format, $format_options) {
    $from = resolvePoint('from');
    $to = resolvePoint('to');

    if (!$from) SwimResponse::error("Could not resolve 'from' parameter", 400, 'INVALID_PARAM');
    if (!$to) SwimResponse::error("Could not resolve 'to' parameter", 400, 'INVALID_PARAM');

    $bearing = initialBearing($from['lat'], $from['lon'], $to['lat'], $to['lon']);

    SwimResponse::formatted([
        'from' => $from,
        'to' => $to,
        'bearing' => round($bearing, 1),
    ], $format, 'reference', [], $format_options);
}

function handleDecodeRoute() {
    $route = swim_get_param('route');
    $origin = swim_get_param('origin');
    $dest = swim_get_param('dest');

    if (!$route) SwimResponse::error("route parameter required", 400, 'MISSING_PARAM');

    // Redirect to existing resolve endpoint
    $params = http_build_query(array_filter([
        'route_string' => $route,
        'origin' => $origin,
        'dest' => $dest,
        'format' => swim_get_param('format'),
    ]));
    header('Location: /api/swim/v1/routes/resolve?' . $params, true, 307);
    exit;
}

/**
 * Vincenty distance formula (meters)
 */
function vincentyDistance($lat1, $lon1, $lat2, $lon2) {
    $a = 6378137.0;
    $f = 1 / 298.257223563;
    $b = $a * (1 - $f);

    $lat1 = deg2rad($lat1); $lon1 = deg2rad($lon1);
    $lat2 = deg2rad($lat2); $lon2 = deg2rad($lon2);

    $U1 = atan((1 - $f) * tan($lat1));
    $U2 = atan((1 - $f) * tan($lat2));
    $L = $lon2 - $lon1;
    $lambda = $L;

    $sinU1 = sin($U1); $cosU1 = cos($U1);
    $sinU2 = sin($U2); $cosU2 = cos($U2);

    for ($i = 0; $i < 100; $i++) {
        $sinLam = sin($lambda); $cosLam = cos($lambda);
        $sinSig = sqrt(pow($cosU2 * $sinLam, 2) + pow($cosU1 * $sinU2 - $sinU1 * $cosU2 * $cosLam, 2));
        if ($sinSig == 0) return 0;
        $cosSig = $sinU1 * $sinU2 + $cosU1 * $cosU2 * $cosLam;
        $sigma = atan2($sinSig, $cosSig);
        $sinAlpha = $cosU1 * $cosU2 * $sinLam / $sinSig;
        $cos2Alpha = 1 - $sinAlpha * $sinAlpha;
        $cos2SigM = ($cos2Alpha != 0) ? $cosSig - 2 * $sinU1 * $sinU2 / $cos2Alpha : 0;
        $C = $f / 16 * $cos2Alpha * (4 + $f * (4 - 3 * $cos2Alpha));
        $prev = $lambda;
        $lambda = $L + (1 - $C) * $f * $sinAlpha * ($sigma + $C * $sinSig * ($cos2SigM + $C * $cosSig * (-1 + 2 * $cos2SigM * $cos2SigM)));
        if (abs($lambda - $prev) < 1e-12) break;
    }

    $u2 = $cos2Alpha * ($a * $a - $b * $b) / ($b * $b);
    $A = 1 + $u2 / 16384 * (4096 + $u2 * (-768 + $u2 * (320 - 175 * $u2)));
    $B = $u2 / 1024 * (256 + $u2 * (-128 + $u2 * (74 - 47 * $u2)));
    $deltaSig = $B * $sinSig * ($cos2SigM + $B / 4 * ($cosSig * (-1 + 2 * $cos2SigM * $cos2SigM) - $B / 6 * $cos2SigM * (-3 + 4 * $sinSig * $sinSig) * (-3 + 4 * $cos2SigM * $cos2SigM)));

    return $b * $A * ($sigma - $deltaSig);
}

/**
 * Initial bearing (degrees)
 */
function initialBearing($lat1, $lon1, $lat2, $lon2) {
    $lat1 = deg2rad($lat1); $lon1 = deg2rad($lon1);
    $lat2 = deg2rad($lat2); $lon2 = deg2rad($lon2);
    $dLon = $lon2 - $lon1;
    $y = sin($dLon) * cos($lat2);
    $x = cos($lat1) * sin($lat2) - sin($lat1) * cos($lat2) * cos($dLon);
    return fmod(rad2deg(atan2($y, $x)) + 360, 360);
}
```

- [ ] **Step 2: Test and commit**

```bash
curl -s "https://perti.vatcscc.org/api/swim/v1/reference/utilities/distance?from=KJFK&to=KLAX" -H "X-API-Key: KEY"
curl -s "https://perti.vatcscc.org/api/swim/v1/reference/utilities/distance?from=40.64,-73.78&to=33.94,-118.41" -H "X-API-Key: KEY"
curl -s "https://perti.vatcscc.org/api/swim/v1/reference/utilities/bearing?from=KJFK&to=EGLL" -H "X-API-Key: KEY"

git add api/swim/v1/reference/utilities.php
git commit -m "feat(swim): add utilities reference endpoint with distance, bearing, route decode"
```

---

### Task 12: Hierarchy Endpoint

**Files:**
- Create: `api/swim/v1/reference/hierarchy.php`

- [ ] **Step 1: Write hierarchy.php**

Create `api/swim/v1/reference/hierarchy.php`:

```php
<?php
/**
 * VATSWIM API v1 - Geographic Hierarchy Navigation
 *
 * @version 1.0.0
 * @since 2026-04-05
 *
 * Endpoints:
 *   GET /reference/hierarchy                              - Entry point (regions)
 *   GET /reference/hierarchy/{type}/{code}                - Node detail + children
 *   GET /reference/hierarchy/{type}/{code}/children       - Children of specific type
 *   GET /reference/hierarchy/{type}/{code}/ancestors      - Parent chain to root
 *   GET /reference/hierarchy/search?q=...                 - Cross-level search
 */

require_once __DIR__ . '/../auth.php';

$auth = swim_init_auth(true, false);

$request_uri = $_SERVER['REQUEST_URI'] ?? '';
$path = parse_url($request_uri, PHP_URL_PATH);
$path = preg_replace('#^.*/reference/hierarchy/?#', '', $path);
$path_parts = array_values(array_filter(explode('/', $path)));

$format = swim_validate_format(swim_get_param('format', 'json'), 'reference');
$include_geometry = in_array('geometry', explode(',', swim_get_param('include', '')));

$format_options = [
    'root' => 'swim_hierarchy',
    'item' => 'node',
    'name' => 'VATSWIM Geographic Hierarchy',
    'filename' => 'swim_hierarchy_' . date('Ymd_His')
];

$cache_params = array_filter([
    'path' => implode('/', $path_parts),
    'q' => swim_get_param('q'),
    'type' => swim_get_param('type'),
    'include' => swim_get_param('include'),
    'format' => $format !== 'json' ? $format : null,
], fn($v) => $v !== null && $v !== '');

if (SwimResponse::tryCachedFormatted('reference_hierarchy', $cache_params, $format, $format_options)) {
    exit;
}

// Load hierarchy reference data
$hierarchy_file = __DIR__ . '/../../../../assets/data/hierarchy.json';
if (!file_exists($hierarchy_file)) {
    SwimResponse::error('Hierarchy data not available', 503, 'SERVICE_UNAVAILABLE');
}
$hierarchy_data = json_decode(file_get_contents($hierarchy_file), true);

// Route
if (empty($path_parts)) {
    handleRoot($hierarchy_data, $format, $cache_params, $format_options);
} elseif ($path_parts[0] === 'search') {
    handleSearch($hierarchy_data, $format, $cache_params, $format_options);
} elseif (count($path_parts) >= 2) {
    $type = $path_parts[0];
    $code = strtoupper($path_parts[1]);
    $action = $path_parts[2] ?? null;

    if ($action === 'children') {
        handleChildren($hierarchy_data, $type, $code, $format, $cache_params, $format_options);
    } elseif ($action === 'ancestors') {
        handleAncestors($hierarchy_data, $type, $code, $format, $cache_params, $format_options);
    } else {
        handleNode($hierarchy_data, $type, $code, $include_geometry, $format, $cache_params, $format_options);
    }
} else {
    SwimResponse::error('Specify a node type and code, or use /search', 400, 'MISSING_PARAM');
}

function handleRoot($data, $format, $cache_params, $format_options) {
    $roots = [];
    foreach ($data['regions'] as $region) {
        $roots[] = [
            'code' => $region['code'],
            'name' => $region['name'],
            'type' => 'region',
            'children_count' => count($region['divisions']),
        ];
    }

    SwimResponse::formatted([
        'levels' => ['region', 'division', 'dcc_region', 'center', 'tracon', 'airport', 'runway'],
        'roots' => $roots,
    ], $format, 'reference_hierarchy', $cache_params, $format_options);
}

function handleNode($data, $type, $code, $include_geometry, $format, $cache_params, $format_options) {
    switch ($type) {
        case 'region':
            $region = findRegion($data, $code);
            if (!$region) SwimResponse::error("Region not found: $code", 404, 'NOT_FOUND');
            $children = array_map(fn($d) => [
                'code' => $d['code'], 'name' => $d['name'], 'type' => 'division',
                'children_count' => count($d['dcc_regions'] ?? []) + count($d['centers'] ?? []),
            ], $region['divisions']);

            SwimResponse::formatted([
                'node' => ['code' => $region['code'], 'name' => $region['name'], 'type' => 'region'],
                'breadcrumb' => [],
                'children' => ['divisions' => $children],
            ], $format, 'reference_hierarchy', $cache_params, $format_options);
            break;

        case 'division':
            $result = findDivision($data, $code);
            if (!$result) SwimResponse::error("Division not found: $code", 404, 'NOT_FOUND');
            [$div, $parent_region] = $result;

            $children = [];
            if (!empty($div['dcc_regions'])) {
                $children['dcc_regions'] = array_map(fn($d) => [
                    'code' => $d['code'], 'name' => $d['name'], 'type' => 'dcc_region',
                    'children_count' => count($d['centers']),
                ], $div['dcc_regions']);
            }
            if (!empty($div['centers'])) {
                $children['centers'] = array_map(fn($c) => [
                    'code' => $c, 'type' => 'center',
                ], $div['centers']);
            }

            SwimResponse::formatted([
                'node' => ['code' => $div['code'], 'name' => $div['name'], 'type' => 'division'],
                'breadcrumb' => [['code' => $parent_region['code'], 'name' => $parent_region['name'], 'type' => 'region']],
                'children' => $children,
            ], $format, 'reference_hierarchy', $cache_params, $format_options);
            break;

        case 'center':
            handleCenterNode($data, $code, $include_geometry, $format, $cache_params, $format_options);
            break;

        case 'tracon':
            handleTraconNode($data, $code, $include_geometry, $format, $cache_params, $format_options);
            break;

        default:
            SwimResponse::error("Unsupported hierarchy type: $type", 400, 'INVALID_PARAM');
    }
}

function handleCenterNode($data, $code, $include_geometry, $format, $cache_params, $format_options) {
    $conn = get_conn_gis();
    if (!$conn) SwimResponse::error('GIS unavailable', 503, 'SERVICE_UNAVAILABLE');

    $geom = $include_geometry ? ", ST_AsGeoJSON(geom, 5) AS geometry" : "";
    $stmt = $conn->prepare("SELECT artcc_code, artcc_name, hierarchy_type $geom FROM artcc_boundaries WHERE artcc_code = :code LIMIT 1");
    $stmt->execute([':code' => $code]);
    $center = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$center) SwimResponse::error("Center not found: $code", 404, 'NOT_FOUND');
    if (isset($center['geometry'])) $center['geometry'] = json_decode($center['geometry'], true);

    // Build breadcrumb from hierarchy data
    $breadcrumb = buildBreadcrumb($data, 'center', $code);

    // Get TRACONs
    $tracon_stmt = $conn->prepare("SELECT tracon_code, tracon_name FROM tracon_boundaries WHERE parent_artcc = :code ORDER BY tracon_code");
    $tracon_stmt->execute([':code' => $code]);
    $tracons = $tracon_stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get sector count
    $sector_stmt = $conn->prepare("SELECT sector_type, COUNT(*) AS cnt FROM sector_boundaries WHERE parent_artcc = :code GROUP BY sector_type");
    $sector_stmt->execute([':code' => $code]);
    $sector_summary = [];
    while ($row = $sector_stmt->fetch(PDO::FETCH_ASSOC)) {
        $sector_summary[$row['sector_type']] = (int)$row['cnt'];
    }

    SwimResponse::formatted([
        'node' => [
            'code' => $center['artcc_code'],
            'name' => $center['artcc_name'],
            'type' => 'center',
            'geometry' => $center['geometry'] ?? null,
            'detail_url' => "/api/swim/v1/reference/facilities/centers/$code",
        ],
        'breadcrumb' => $breadcrumb,
        'children' => [
            'tracons' => array_map(fn($t) => ['code' => $t['tracon_code'], 'name' => $t['tracon_name'], 'type' => 'tracon'], $tracons),
        ],
        'summary' => [
            'total_tracons' => count($tracons),
            'sectors' => $sector_summary,
        ],
    ], $format, 'reference_hierarchy', $cache_params, $format_options);
}

function handleTraconNode($data, $code, $include_geometry, $format, $cache_params, $format_options) {
    $conn = get_conn_gis();
    if (!$conn) SwimResponse::error('GIS unavailable', 503, 'SERVICE_UNAVAILABLE');

    $geom = $include_geometry ? ", ST_AsGeoJSON(geom, 5) AS geometry" : "";
    $stmt = $conn->prepare("SELECT tracon_code, tracon_name, parent_artcc $geom FROM tracon_boundaries WHERE tracon_code = :code LIMIT 1");
    $stmt->execute([':code' => $code]);
    $tracon = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$tracon) SwimResponse::error("TRACON not found: $code", 404, 'NOT_FOUND');
    if (isset($tracon['geometry'])) $tracon['geometry'] = json_decode($tracon['geometry'], true);

    // Get airports within TRACON
    $apt_sql = "SELECT a.icao_code, a.faa_lid, a.name FROM airports a, tracon_boundaries t
                WHERE t.tracon_code = :code AND ST_Contains(t.geom, a.geom) ORDER BY a.icao_code";
    $apt_stmt = $conn->prepare($apt_sql);
    $apt_stmt->execute([':code' => $code]);
    $airports = $apt_stmt->fetchAll(PDO::FETCH_ASSOC);

    $breadcrumb = buildBreadcrumb($data, 'tracon', $code, $tracon['parent_artcc']);

    SwimResponse::formatted([
        'node' => [
            'code' => $tracon['tracon_code'],
            'name' => $tracon['tracon_name'],
            'type' => 'tracon',
            'parent_artcc' => $tracon['parent_artcc'],
            'geometry' => $tracon['geometry'] ?? null,
            'detail_url' => "/api/swim/v1/reference/facilities/tracons/$code",
        ],
        'breadcrumb' => $breadcrumb,
        'children' => [
            'airports' => array_map(fn($a) => [
                'code' => $a['icao_code'], 'faa_lid' => $a['faa_lid'], 'name' => $a['name'], 'type' => 'airport'
            ], $airports),
        ],
        'summary' => ['total_airports' => count($airports)],
    ], $format, 'reference_hierarchy', $cache_params, $format_options);
}

function handleSearch($data, $format, $cache_params, $format_options) {
    $q = swim_get_param('q');
    $type_filter = swim_get_param('type');

    if (!$q || strlen($q) < 2) SwimResponse::error('q parameter required (min 2 chars)', 400, 'MISSING_PARAM');

    $results = [];
    $q_upper = strtoupper($q);

    // Search static hierarchy (regions, divisions, DCC regions)
    if (!$type_filter || in_array($type_filter, ['region', 'division', 'dcc_region'])) {
        foreach ($data['regions'] as $region) {
            if ((!$type_filter || $type_filter === 'region') && (stripos($region['name'], $q) !== false || stripos($region['code'], $q_upper) !== false)) {
                $results[] = ['code' => $region['code'], 'name' => $region['name'], 'type' => 'region'];
            }
            foreach ($region['divisions'] as $div) {
                if ((!$type_filter || $type_filter === 'division') && (stripos($div['name'], $q) !== false || stripos($div['code'], $q_upper) !== false)) {
                    $results[] = ['code' => $div['code'], 'name' => $div['name'], 'type' => 'division'];
                }
                foreach ($div['dcc_regions'] ?? [] as $dcc) {
                    if ((!$type_filter || $type_filter === 'dcc_region') && (stripos($dcc['name'], $q) !== false || stripos($dcc['code'], $q_upper) !== false)) {
                        $results[] = ['code' => $dcc['code'], 'name' => $dcc['name'], 'type' => 'dcc_region'];
                    }
                }
            }
        }
    }

    // Search PostGIS for centers, TRACONs, airports
    $conn = get_conn_gis();
    if ($conn) {
        if (!$type_filter || $type_filter === 'center') {
            $stmt = $conn->prepare("SELECT artcc_code AS code, artcc_name AS name FROM artcc_boundaries WHERE artcc_code ILIKE :q OR artcc_name ILIKE :qw LIMIT 20");
            $stmt->execute([':q' => $q_upper . '%', ':qw' => '%' . $q . '%']);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) { $results[] = array_merge($r, ['type' => 'center']); }
        }
        if (!$type_filter || $type_filter === 'tracon') {
            $stmt = $conn->prepare("SELECT tracon_code AS code, tracon_name AS name FROM tracon_boundaries WHERE tracon_code ILIKE :q OR tracon_name ILIKE :qw LIMIT 20");
            $stmt->execute([':q' => $q_upper . '%', ':qw' => '%' . $q . '%']);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) { $results[] = array_merge($r, ['type' => 'tracon']); }
        }
        if (!$type_filter || $type_filter === 'airport') {
            $stmt = $conn->prepare("SELECT icao_code AS code, name FROM airports WHERE icao_code ILIKE :q OR faa_lid ILIKE :q OR name ILIKE :qw OR city ILIKE :qw LIMIT 20");
            $stmt->execute([':q' => $q_upper . '%', ':qw' => '%' . $q . '%']);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) { $results[] = array_merge($r, ['type' => 'airport']); }
        }
    }

    SwimResponse::formatted([
        'query' => $q,
        'results' => $results,
        'count' => count($results),
    ], $format, 'reference_hierarchy', $cache_params, $format_options);
}

function handleChildren($data, $type, $code, $format, $cache_params, $format_options) {
    $child_type = swim_get_param('type');
    $page = swim_get_int_param('page', 1, 1, 10000);
    $per_page = swim_get_int_param('per_page', 100, 1, 1000);

    // For center -> airports (across all TRACONs)
    if ($type === 'center' && $child_type === 'airport') {
        $conn = get_conn_gis();
        if (!$conn) SwimResponse::error('GIS unavailable', 503, 'SERVICE_UNAVAILABLE');

        $offset = ($page - 1) * $per_page;
        $sql = "SELECT a.icao_code, a.faa_lid, a.name, a.city
                FROM airports a, artcc_boundaries b
                WHERE b.artcc_code = :code AND ST_Contains(b.geom, a.geom)
                ORDER BY a.icao_code LIMIT :limit OFFSET :offset";
        $stmt = $conn->prepare($sql);
        $stmt->execute([':code' => $code, ':limit' => $per_page, ':offset' => $offset]);
        $airports = $stmt->fetchAll(PDO::FETCH_ASSOC);

        SwimResponse::formatted([
            'parent' => ['type' => $type, 'code' => $code],
            'child_type' => 'airport',
            'children' => $airports,
            'count' => count($airports),
        ], $format, 'reference_hierarchy', $cache_params, $format_options);
        return;
    }

    SwimResponse::error("Unsupported children query: $type/$code children of type $child_type", 400, 'INVALID_PARAM');
}

function handleAncestors($data, $type, $code, $format, $cache_params, $format_options) {
    $breadcrumb = [];

    if ($type === 'tracon') {
        $conn = get_conn_gis();
        if ($conn) {
            $stmt = $conn->prepare("SELECT parent_artcc FROM tracon_boundaries WHERE tracon_code = :code LIMIT 1");
            $stmt->execute([':code' => $code]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) $breadcrumb = buildBreadcrumb($data, 'tracon', $code, $row['parent_artcc']);
        }
    } elseif ($type === 'center') {
        $breadcrumb = buildBreadcrumb($data, 'center', $code);
    }

    SwimResponse::formatted([
        'node' => ['type' => $type, 'code' => $code],
        'ancestors' => $breadcrumb,
    ], $format, 'reference_hierarchy', $cache_params, $format_options);
}

// === HELPERS ===

function findRegion($data, $code) {
    foreach ($data['regions'] as $r) { if (strtoupper($r['code']) === $code) return $r; }
    return null;
}

function findDivision($data, $code) {
    foreach ($data['regions'] as $r) {
        foreach ($r['divisions'] as $d) {
            if (strtoupper($d['code']) === $code) return [$d, $r];
        }
    }
    return null;
}

function buildBreadcrumb($data, $type, $code, $parent_artcc = null) {
    $crumbs = [];

    // Find which division/region owns this center
    $center_code = ($type === 'center') ? $code : $parent_artcc;
    if (!$center_code) return $crumbs;

    foreach ($data['regions'] as $region) {
        foreach ($region['divisions'] as $div) {
            $all_centers = $div['centers'] ?? [];
            foreach ($div['dcc_regions'] ?? [] as $dcc) {
                $all_centers = array_merge($all_centers, $dcc['centers']);
            }
            if (in_array($center_code, $all_centers)) {
                $crumbs[] = ['code' => $region['code'], 'name' => $region['name'], 'type' => 'region'];
                $crumbs[] = ['code' => $div['code'], 'name' => $div['name'], 'type' => 'division'];

                foreach ($div['dcc_regions'] ?? [] as $dcc) {
                    if (in_array($center_code, $dcc['centers'])) {
                        $crumbs[] = ['code' => $dcc['code'], 'name' => $dcc['name'], 'type' => 'dcc_region'];
                        break;
                    }
                }

                if ($type === 'tracon' && $parent_artcc) {
                    $crumbs[] = ['code' => $parent_artcc, 'type' => 'center'];
                }

                return $crumbs;
            }
        }
    }

    return $crumbs;
}
```

- [ ] **Step 2: Test and commit**

```bash
curl -s "https://perti.vatcscc.org/api/swim/v1/reference/hierarchy" -H "X-API-Key: KEY"
curl -s "https://perti.vatcscc.org/api/swim/v1/reference/hierarchy/region/AMAS" -H "X-API-Key: KEY"
curl -s "https://perti.vatcscc.org/api/swim/v1/reference/hierarchy/center/KZNY" -H "X-API-Key: KEY"
curl -s "https://perti.vatcscc.org/api/swim/v1/reference/hierarchy/search?q=kennedy" -H "X-API-Key: KEY"

git add api/swim/v1/reference/hierarchy.php
git commit -m "feat(swim): add geographic hierarchy endpoint with tree navigation and search"
```

---

### Task 13: Bulk Downloads Endpoint + Generation Script

**Files:**
- Create: `api/swim/v1/reference/bulk.php`
- Create: `scripts/reference/generate_bulk.php`

- [ ] **Step 1: Create data/bulk directory**

```bash
mkdir -p data/bulk
echo '{}' > data/bulk/.gitkeep
```

- [ ] **Step 2: Write bulk.php (catalog + download proxy)**

Create `api/swim/v1/reference/bulk.php`:

```php
<?php
/**
 * VATSWIM API v1 - Bulk Download Reference Data
 *
 * @version 1.0.0
 * @since 2026-04-05
 *
 * Endpoints:
 *   GET /reference/bulk/catalog              - List available bulk files
 *   GET /reference/bulk/{dataset}?format=json - Download a complete dataset
 */

require_once __DIR__ . '/../auth.php';

$auth = swim_init_auth(true, false);

$request_uri = $_SERVER['REQUEST_URI'] ?? '';
$path = parse_url($request_uri, PHP_URL_PATH);
$path = preg_replace('#^.*/reference/bulk/?#', '', $path);
$path_parts = array_values(array_filter(explode('/', $path)));

$dataset = $path_parts[0] ?? null;
$format = swim_get_param('format', 'json');

$bulk_dir = __DIR__ . '/../../../../data/bulk';
$meta_file = $bulk_dir . '/catalog.json';

if ($dataset === 'catalog' || $dataset === null) {
    // Return catalog
    if (!file_exists($meta_file)) {
        SwimResponse::error('Bulk catalog not yet generated. Run scripts/reference/generate_bulk.php first.', 503, 'NOT_GENERATED');
    }

    $catalog = json_decode(file_get_contents($meta_file), true);
    SwimResponse::success(['catalog' => $catalog['datasets'] ?? [], 'generated_utc' => $catalog['generated_utc'] ?? null, 'airac_cycle' => $catalog['airac_cycle'] ?? null]);
}

// Validate dataset name
$valid_datasets = ['airports', 'fixes', 'airways', 'procedures', 'boundaries_artcc', 'boundaries_tracon', 'boundaries_sector', 'cdrs', 'aircraft', 'airlines', 'hierarchy'];
if (!in_array($dataset, $valid_datasets)) {
    SwimResponse::error("Unknown dataset: $dataset. Valid: " . implode(', ', $valid_datasets), 404, 'NOT_FOUND');
}

// Map format to file extension
$ext_map = ['json' => 'json', 'geojson' => 'geojson', 'csv' => 'csv'];
$ext = $ext_map[$format] ?? 'json';
$file_path = "$bulk_dir/{$dataset}.{$ext}";

if (!file_exists($file_path)) {
    // Try json as fallback
    $file_path = "$bulk_dir/{$dataset}.json";
    if (!file_exists($file_path)) {
        SwimResponse::error("Bulk file not available: {$dataset}.{$ext}. Run generate_bulk.php first.", 503, 'NOT_GENERATED');
    }
}

// Serve file directly with appropriate headers
$content_types = [
    'json' => 'application/json',
    'geojson' => 'application/geo+json',
    'csv' => 'text/csv',
];

$stat = stat($file_path);
$etag = '"' . md5($file_path . $stat['mtime']) . '"';
$last_modified = gmdate('D, d M Y H:i:s', $stat['mtime']) . ' GMT';

// Check If-None-Match / If-Modified-Since
if (isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim($_SERVER['HTTP_IF_NONE_MATCH']) === $etag) {
    http_response_code(304);
    exit;
}

header('Content-Type: ' . ($content_types[$ext] ?? 'application/json'));
header('Content-Length: ' . filesize($file_path));
header('ETag: ' . $etag);
header('Last-Modified: ' . $last_modified);
header('Cache-Control: public, max-age=86400');
header('Content-Disposition: inline; filename="' . basename($file_path) . '"');

readfile($file_path);
exit;
```

- [ ] **Step 3: Write generate_bulk.php**

Create `scripts/reference/generate_bulk.php`. This is the bulk file generation script, run post-AIRAC-update or manually:

```php
<?php
/**
 * VATSWIM Bulk Reference Data Generator
 *
 * Generates static JSON/GeoJSON/CSV files for bulk download.
 * Run post-AIRAC-update or manually via CLI/web.
 *
 * CLI:  php scripts/reference/generate_bulk.php [--force]
 * Web:  https://perti.vatcscc.org/scripts/reference/generate_bulk.php?run=1
 *
 * Output: data/bulk/{dataset}.{json|geojson|csv}
 */

$is_cli = (php_sapi_name() === 'cli');

if (!$is_cli) {
    // Web mode - require run param
    if (!isset($_GET['run'])) {
        header('Content-Type: application/json');
        echo json_encode(['status' => 'idle', 'usage' => 'Add ?run=1 to execute']);
        exit;
    }
    header('Content-Type: application/json');
}

// Load config
require_once __DIR__ . '/../../load/config.php';
require_once __DIR__ . '/../../load/connect.php';

$bulk_dir = __DIR__ . '/../../data/bulk';
if (!is_dir($bulk_dir)) {
    mkdir($bulk_dir, 0755, true);
}

$results = [];
$start = microtime(true);

function log_msg($msg) {
    global $is_cli;
    if ($is_cli) echo "$msg\n";
}

// === AIRPORTS ===
log_msg("Generating airports...");
$conn_gis = get_conn_gis();
if ($conn_gis) {
    $stmt = $conn_gis->query("SELECT icao_code, faa_lid, name, city, state_code, country_code, latitude, longitude, elevation_ft, mag_var, is_towered, airport_class FROM airports ORDER BY icao_code");
    $airports = $stmt->fetchAll(PDO::FETCH_ASSOC);
    file_put_contents("$bulk_dir/airports.json", json_encode(['airports' => $airports, 'count' => count($airports)], JSON_PRETTY_PRINT));
    $results['airports'] = ['count' => count($airports), 'size' => filesize("$bulk_dir/airports.json")];
    log_msg("  airports: " . count($airports) . " records");
}

// === FIXES ===
log_msg("Generating fixes...");
if ($conn_gis) {
    $stmt = $conn_gis->query("SELECT fix_name, latitude, longitude, fix_type, artcc_code, country_code, airac_cycle FROM nav_fixes WHERE is_superseded = false OR is_superseded IS NULL ORDER BY fix_name");
    $fixes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    file_put_contents("$bulk_dir/fixes.json", json_encode(['fixes' => $fixes, 'count' => count($fixes)]));
    $results['fixes'] = ['count' => count($fixes), 'size' => filesize("$bulk_dir/fixes.json")];
    log_msg("  fixes: " . count($fixes) . " records");
}

// === AIRWAYS ===
log_msg("Generating airways...");
if ($conn_gis) {
    $stmt = $conn_gis->query("SELECT airway_name, airway_type, airac_cycle FROM airways WHERE is_superseded = false OR is_superseded IS NULL ORDER BY airway_name");
    $airways = $stmt->fetchAll(PDO::FETCH_ASSOC);
    file_put_contents("$bulk_dir/airways.json", json_encode(['airways' => $airways, 'count' => count($airways)]));
    $results['airways'] = ['count' => count($airways), 'size' => filesize("$bulk_dir/airways.json")];
    log_msg("  airways: " . count($airways) . " records");
}

// === PROCEDURES ===
log_msg("Generating procedures...");
if ($conn_gis) {
    $stmt = $conn_gis->query("SELECT computer_code, procedure_name, procedure_type, airport_icao, transition_name, transition_type, source, airac_cycle FROM nav_procedures WHERE is_superseded = false OR is_superseded IS NULL ORDER BY airport_icao, procedure_type, procedure_name");
    $procs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    file_put_contents("$bulk_dir/procedures.json", json_encode(['procedures' => $procs, 'count' => count($procs)]));
    $results['procedures'] = ['count' => count($procs), 'size' => filesize("$bulk_dir/procedures.json")];
    log_msg("  procedures: " . count($procs) . " records");
}

// === BOUNDARIES (GeoJSON) ===
foreach (['artcc' => 'artcc_boundaries', 'tracon' => 'tracon_boundaries', 'sector' => 'sector_boundaries'] as $key => $table) {
    log_msg("Generating boundaries_$key...");
    if ($conn_gis) {
        $code_col = $key === 'artcc' ? 'artcc_code' : ($key === 'tracon' ? 'tracon_code' : 'sector_code');
        $stmt = $conn_gis->query("SELECT *, ST_AsGeoJSON(geom, 5) AS geometry FROM $table ORDER BY $code_col");
        $features = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $geom = json_decode($row['geometry'], true);
            unset($row['geometry'], $row['geom']);
            $features[] = ['type' => 'Feature', 'properties' => $row, 'geometry' => $geom];
        }
        $geojson = ['type' => 'FeatureCollection', 'features' => $features];
        file_put_contents("$bulk_dir/boundaries_{$key}.geojson", json_encode($geojson));
        $results["boundaries_$key"] = ['count' => count($features), 'size' => filesize("$bulk_dir/boundaries_{$key}.geojson")];
        log_msg("  boundaries_$key: " . count($features) . " features");
    }
}

// === CDRS ===
log_msg("Generating CDRs...");
if ($conn_gis) {
    $stmt = $conn_gis->query("SELECT cdr_code, cdr_type, origin_airport, dest_airport, route_string, airac_cycle FROM coded_departure_routes WHERE is_superseded = false OR is_superseded IS NULL ORDER BY cdr_code");
    $cdrs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    file_put_contents("$bulk_dir/cdrs.json", json_encode(['cdrs' => $cdrs, 'count' => count($cdrs)]));
    $results['cdrs'] = ['count' => count($cdrs), 'size' => filesize("$bulk_dir/cdrs.json")];
    log_msg("  cdrs: " . count($cdrs) . " records");
}

// === AIRCRAFT ===
log_msg("Generating aircraft...");
$conn_adl = get_conn_adl();
if ($conn_adl) {
    $stmt = sqlsrv_query($conn_adl, "SELECT * FROM dbo.ACD_Data ORDER BY ICAO_Code");
    $aircraft = [];
    if ($stmt !== false) {
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) { $aircraft[] = $row; }
        sqlsrv_free_stmt($stmt);
    }
    file_put_contents("$bulk_dir/aircraft.json", json_encode(['aircraft' => $aircraft, 'count' => count($aircraft)]));
    $results['aircraft'] = ['count' => count($aircraft), 'size' => filesize("$bulk_dir/aircraft.json")];
    log_msg("  aircraft: " . count($aircraft) . " records");
}

// === AIRLINES ===
log_msg("Generating airlines...");
if ($conn_adl) {
    $stmt = sqlsrv_query($conn_adl, "SELECT icao_code, iata_code, name, callsign, country FROM dbo.airlines ORDER BY icao_code");
    $airlines = [];
    if ($stmt !== false) {
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) { $airlines[] = $row; }
        sqlsrv_free_stmt($stmt);
    }
    file_put_contents("$bulk_dir/airlines.json", json_encode(['airlines' => $airlines, 'count' => count($airlines)]));
    $results['airlines'] = ['count' => count($airlines), 'size' => filesize("$bulk_dir/airlines.json")];
    log_msg("  airlines: " . count($airlines) . " records");
}

// === HIERARCHY ===
log_msg("Copying hierarchy...");
$hierarchy_src = __DIR__ . '/../../assets/data/hierarchy.json';
if (file_exists($hierarchy_src)) {
    copy($hierarchy_src, "$bulk_dir/hierarchy.json");
    $results['hierarchy'] = ['size' => filesize("$bulk_dir/hierarchy.json")];
}

// === CATALOG ===
$elapsed = round(microtime(true) - $start, 1);
$catalog = [
    'generated_utc' => gmdate('c'),
    'generation_time_sec' => $elapsed,
    'airac_cycle' => null,  // Populated from REF if available
    'datasets' => [],
];

foreach ($results as $key => $info) {
    $catalog['datasets'][] = [
        'key' => $key,
        'records' => $info['count'] ?? null,
        'size_bytes' => $info['size'] ?? null,
        'format' => str_contains($key, 'boundaries') ? 'geojson' : 'json',
        'url' => "/api/swim/v1/reference/bulk/$key",
    ];
}

file_put_contents("$bulk_dir/catalog.json", json_encode($catalog, JSON_PRETTY_PRINT));
log_msg("\nDone in {$elapsed}s. Catalog: $bulk_dir/catalog.json");

if (!$is_cli) {
    echo json_encode(['success' => true, 'results' => $results, 'elapsed_sec' => $elapsed]);
}
```

- [ ] **Step 4: Test bulk generation and download**

```bash
# Generate bulk files (web mode)
curl -s "https://perti.vatcscc.org/scripts/reference/generate_bulk.php?run=1" -H "X-API-Key: KEY"

# Check catalog
curl -s "https://perti.vatcscc.org/api/swim/v1/reference/bulk/catalog" -H "X-API-Key: KEY"

# Download a dataset
curl -s "https://perti.vatcscc.org/api/swim/v1/reference/bulk/airlines" -H "X-API-Key: KEY" | head -c 500
```

- [ ] **Step 5: Commit**

```bash
git add api/swim/v1/reference/bulk.php scripts/reference/generate_bulk.php data/bulk/.gitkeep
git commit -m "feat(swim): add bulk download endpoint and generation script"
```

---

### Task 14: Integration Testing and Final PR

- [ ] **Step 1: Run full endpoint smoke test**

Test every endpoint with a real API key. Document any column name mismatches or query errors found:

```bash
# Airlines
curl -s ".../reference/airlines" -H "X-API-Key: KEY" | python -m json.tool | head -5
curl -s ".../reference/airlines/DAL" -H "X-API-Key: KEY"

# Aircraft
curl -s ".../reference/aircraft/types?per_page=3" -H "X-API-Key: KEY"
curl -s ".../reference/aircraft/types/B738" -H "X-API-Key: KEY"
curl -s ".../reference/aircraft/families" -H "X-API-Key: KEY"

# Airports
curl -s ".../reference/airports/KJFK" -H "X-API-Key: KEY"
curl -s ".../reference/airports/KJFK/facilities" -H "X-API-Key: KEY"
curl -s ".../reference/airports/KJFK/runways" -H "X-API-Key: KEY"
curl -s ".../reference/airports/search?q=kennedy" -H "X-API-Key: KEY"

# Navigation
curl -s ".../reference/navigation/fixes/MERIT" -H "X-API-Key: KEY"
curl -s ".../reference/navigation/airways/J60" -H "X-API-Key: KEY"
curl -s ".../reference/navigation/procedures/airport/KJFK?type=STAR" -H "X-API-Key: KEY"

# Airspace
curl -s ".../reference/airspace/boundaries?type=artcc&per_page=3" -H "X-API-Key: KEY"
curl -s ".../reference/airspace/at-point?lat=40.64&lon=-73.78" -H "X-API-Key: KEY"

# Facilities
curl -s ".../reference/facilities/centers/KZNY" -H "X-API-Key: KEY"
curl -s ".../reference/facilities/lists/oep35" -H "X-API-Key: KEY"

# Routes
curl -s ".../reference/routes/popular?origin=KJFK&dest=KLAX" -H "X-API-Key: KEY"

# AIRAC
curl -s ".../reference/airac/current" -H "X-API-Key: KEY"

# Utilities
curl -s ".../reference/utilities/distance?from=KJFK&to=KLAX" -H "X-API-Key: KEY"

# Hierarchy
curl -s ".../reference/hierarchy" -H "X-API-Key: KEY"
curl -s ".../reference/hierarchy/center/KZNY" -H "X-API-Key: KEY"

# Bulk
curl -s ".../reference/bulk/catalog" -H "X-API-Key: KEY"
```

- [ ] **Step 2: Fix any issues found during smoke testing**

Address column name mismatches, query errors, or response format issues.

- [ ] **Step 3: Create final commit with all fixes**

```bash
git add -A api/swim/v1/reference/ scripts/reference/ assets/data/hierarchy.json data/bulk/ default load/swim_config.php api/swim/v1/.htaccess
git status
```

- [ ] **Step 4: Create PR**

```bash
git checkout -b feature/vatswim-reference-library
git push -u origin feature/vatswim-reference-library
gh pr create --title "feat: VATSWIM Reference Library API" --body "$(cat <<'EOF'
## Summary
- 11 new reference API endpoints under `/api/swim/v1/reference/`
- Geographic hierarchy with progressive drill-down navigation
- Bulk download system with static file generation
- Covers: airports, navigation (fixes/airways/procedures), airspace (boundaries/sectors/FIRs), facilities (centers/TRACONs/DCC regions), aircraft, airlines, routes, AIRAC, utilities

## Domains
| Domain | Endpoints | Source DB |
|--------|-----------|-----------|
| airports | 7 | PostGIS + ADL |
| navigation | 8 | PostGIS |
| airspace | 5 | PostGIS + GISService |
| facilities | 8 | PostGIS + static JSON |
| aircraft | 5 | ADL + BADA |
| airlines | 2 | ADL |
| routes | 2 | MySQL |
| airac | 3 | REF + PostGIS |
| utilities | 3 | PostGIS + computation |
| hierarchy | 5 | PostGIS + static JSON |
| bulk | 2 | All sources |

## Test plan
- [ ] Smoke test all endpoints with valid API key
- [ ] Verify cache behavior (check X-Cache headers)
- [ ] Test format support (json, csv, xml where applicable)
- [ ] Verify geometry opt-in (?include=geometry)
- [ ] Run bulk generation and verify catalog
- [ ] Test pagination on list endpoints

🤖 Generated with [Claude Code](https://claude.com/claude-code)
EOF
)"
```
