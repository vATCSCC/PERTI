# PERTI Phase 5: Weather & Boundaries Integration

**Document Version:** 1.0  
**Created:** 2026-01-06  
**Author:** Development Team  
**Status:** Design - Ready for Implementation

---

## Table of Contents

1. [Executive Summary](#1-executive-summary)
2. [Goals & Requirements](#2-goals--requirements)
3. [Data Sources](#3-data-sources)
4. [Database Schema](#4-database-schema)
5. [Weather Integration](#5-weather-integration)
6. [Boundary Integration](#6-boundary-integration)
7. [Flight Impact Analysis](#7-flight-impact-analysis)
8. [API Specification](#8-api-specification)
9. [UI Components](#9-ui-components)
10. [Implementation Phases](#10-implementation-phases)
11. [Performance Considerations](#11-performance-considerations)
12. [Testing Strategy](#12-testing-strategy)

---

## 1. Executive Summary

### 1.1 Purpose

Phase 5 integrates real-world weather hazards and airspace boundaries into PERTI to provide traffic managers with enhanced situational awareness. This includes convective weather (SIGMETs), turbulence/icing (AIRMETs), temporary flight restrictions (TFRs), and ARTCC/sector boundary tracking for flight transitions.

### 1.2 Scope

| Component | Description |
|-----------|-------------|
| Weather Alerts | SIGMETs, AIRMETs, convective hazards with polygon boundaries |
| TFRs | Temporary Flight Restrictions with circular/polygon areas |
| ARTCC Boundaries | Center boundary polygons for sector tracking |
| Flight Impact | Detect flights affected by weather/TFRs |
| Boundary Crossing | Track ARTCC/sector transitions per flight |

### 1.3 Key Outcomes

- Real-time weather hazard display on TSD map
- Flights color-coded by weather impact
- ARTCC boundary visualization
- Flight-to-weather proximity alerts
- Sector transition logging for flight history

---

## 2. Goals & Requirements

### 2.1 Functional Requirements

| ID | Requirement | Priority |
|----|-------------|----------|
| FR-501 | Import SIGMET data with polygon boundaries | P0 |
| FR-502 | Import AIRMET data with polygon boundaries | P1 |
| FR-503 | Import TFR data with circular/polygon areas | P1 |
| FR-504 | Store ARTCC boundary polygons | P0 |
| FR-505 | Detect flights within weather hazard areas | P0 |
| FR-506 | Detect flights within TFR areas | P1 |
| FR-507 | Log ARTCC boundary crossings per flight | P1 |
| FR-508 | Display weather polygons on TSD map | P0 |
| FR-509 | Display TFR boundaries on TSD map | P1 |
| FR-510 | Auto-refresh weather data (5-minute cycle) | P0 |
| FR-511 | Weather alert age indicator (staleness) | P2 |
| FR-512 | Historical weather playback | P3 |

### 2.2 Non-Functional Requirements

| ID | Requirement | Target |
|----|-------------|--------|
| NFR-501 | Weather import latency | < 60 seconds from source |
| NFR-502 | Spatial query performance | < 100ms per flight |
| NFR-503 | Bulk impact check (2000 flights) | < 5 seconds |
| NFR-504 | Weather data storage | 7-day retention |
| NFR-505 | Boundary polygon accuracy | Within 0.1 nm |

---

## 3. Data Sources

### 3.1 Aviation Weather Center (aviationweather.gov)

**SIGMET/AIRMET Endpoint:**
```
https://aviationweather.gov/api/data/airsigmet
```

**Parameters:**
- `format=json` or `format=xml`
- `type=sigmet` or `type=airmet`
- `hazard=convective,turb,ice,ifr,mtn`
- `date=` (YYYYMMDD_HHMM format)

**Response includes:**
- Hazard type, severity
- Valid time range (start/end)
- Altitude range (floor/ceiling)
- Polygon coordinates (lat/lon points)

### 3.2 FAA TFR Data

**TFR GeoJSON Feed:**
```
https://tfr.faa.gov/tfr2/list.html  (HTML scrape)
https://tfr.faa.gov/save_pages/detail_X_XXXX.xml (individual TFR)
```

**Alternative - FAA NOTAM API:**
```
https://external-api.faa.gov/notamapi/v1/notams
```
Requires API key registration.

### 3.3 ARTCC Boundaries

**Source Options:**

1. **VATSpy Data** (already in project)
   - File: `Boundaries.txt` in VATSpy data
   - Format: Custom text with lat/lon points

2. **FAA Facility Boundaries**
   - AIXM format from FAA
   - More accurate but complex parsing

3. **GitHub - Open Aviation Data**
   ```
   https://github.com/jpatokal/openflights
   https://github.com/vatsimnetwork/vatspy-data-project
   ```

### 3.4 Data Refresh Schedule

| Source | Refresh Interval | Method |
|--------|------------------|--------|
| SIGMET | 5 minutes | API poll |
| AIRMET | 15 minutes | API poll |
| TFR | 30 minutes | API poll |
| ARTCC Boundaries | Manual | One-time import |

---

## 4. Database Schema

### 4.1 Weather Alerts Table

```sql
CREATE TABLE dbo.weather_alerts (
    alert_id            INT IDENTITY(1,1) PRIMARY KEY,
    alert_type          NVARCHAR(16) NOT NULL,     -- SIGMET, AIRMET, CONVECTIVE
    hazard              NVARCHAR(32) NOT NULL,     -- CONVECTIVE, TURB, ICE, IFR, MTN
    severity            NVARCHAR(16) NULL,         -- SEV, MOD, LGT
    source_id           NVARCHAR(32) NOT NULL,     -- e.g., WST1, SIGC05
    
    -- Time validity
    valid_from_utc      DATETIME2(0) NOT NULL,
    valid_to_utc        DATETIME2(0) NOT NULL,
    
    -- Altitude range (in 100s of feet)
    floor_fl            INT NULL,                  -- 0 = surface
    ceiling_fl          INT NULL,                  -- 600 = FL600
    
    -- Movement
    direction_deg       INT NULL,                  -- Direction of movement
    speed_kts           INT NULL,                  -- Speed of movement
    
    -- Geometry
    geometry            GEOGRAPHY NOT NULL,        -- Polygon boundary
    center_lat          DECIMAL(10,7) NULL,
    center_lon          DECIMAL(11,7) NULL,
    
    -- Metadata
    raw_text            NVARCHAR(MAX) NULL,        -- Original text
    import_utc          DATETIME2(0) NOT NULL DEFAULT SYSUTCDATETIME(),
    is_active           BIT NOT NULL DEFAULT 1,
    
    INDEX IX_weather_active (is_active, valid_to_utc),
    INDEX IX_weather_type (alert_type, hazard),
    SPATIAL INDEX IX_weather_geo (geometry)
);
```

### 4.2 TFR Table

```sql
CREATE TABLE dbo.tfr_restrictions (
    tfr_id              INT IDENTITY(1,1) PRIMARY KEY,
    notam_id            NVARCHAR(32) NOT NULL,     -- e.g., 6/3241
    tfr_type            NVARCHAR(32) NOT NULL,     -- VIP, HAZARD, SECURITY, SPACE, etc.
    
    -- Time validity
    effective_utc       DATETIME2(0) NOT NULL,
    expires_utc         DATETIME2(0) NOT NULL,
    
    -- Altitude range
    floor_ft            INT NOT NULL DEFAULT 0,
    ceiling_ft          INT NOT NULL DEFAULT 99999,
    
    -- Location
    facility_name       NVARCHAR(64) NULL,         -- e.g., "Kennedy Space Center"
    state               NVARCHAR(2) NULL,
    
    -- Geometry (circle or polygon)
    geometry_type       NVARCHAR(16) NOT NULL,     -- CIRCLE, POLYGON
    center_lat          DECIMAL(10,7) NULL,
    center_lon          DECIMAL(11,7) NULL,
    radius_nm           DECIMAL(8,2) NULL,         -- For circular TFRs
    geometry            GEOGRAPHY NOT NULL,        -- Actual boundary
    
    -- Metadata
    description         NVARCHAR(MAX) NULL,
    raw_notam           NVARCHAR(MAX) NULL,
    import_utc          DATETIME2(0) NOT NULL DEFAULT SYSUTCDATETIME(),
    is_active           BIT NOT NULL DEFAULT 1,
    
    INDEX IX_tfr_active (is_active, expires_utc),
    SPATIAL INDEX IX_tfr_geo (geometry)
);
```

### 4.3 ARTCC Boundaries Table

```sql
CREATE TABLE dbo.artcc_boundaries (
    boundary_id         INT IDENTITY(1,1) PRIMARY KEY,
    artcc_id            NVARCHAR(4) NOT NULL,      -- ZNY, ZDC, etc.
    artcc_name          NVARCHAR(64) NOT NULL,     -- New York Center
    boundary_type       NVARCHAR(16) NOT NULL,     -- ARTCC, HIGH, LOW
    
    -- Geometry
    geometry            GEOGRAPHY NOT NULL,
    area_sq_nm          DECIMAL(12,2) NULL,
    
    -- Altitude range (for sector boundaries)
    floor_fl            INT NULL,
    ceiling_fl          INT NULL,
    
    -- Metadata
    source              NVARCHAR(32) NOT NULL,     -- VATSPY, FAA
    import_utc          DATETIME2(0) NOT NULL DEFAULT SYSUTCDATETIME(),
    
    INDEX IX_artcc_id (artcc_id, boundary_type),
    SPATIAL INDEX IX_artcc_geo (geometry)
);
```

### 4.4 Sector Boundaries Table

```sql
CREATE TABLE dbo.sector_boundaries (
    sector_id           INT IDENTITY(1,1) PRIMARY KEY,
    artcc_id            NVARCHAR(4) NOT NULL,
    sector_name         NVARCHAR(8) NOT NULL,      -- e.g., ZNY32
    sector_type         NVARCHAR(16) NOT NULL,     -- HIGH, LOW, ULTRA_HIGH
    
    -- Altitude range
    floor_fl            INT NOT NULL,
    ceiling_fl          INT NOT NULL,
    
    -- Geometry
    geometry            GEOGRAPHY NOT NULL,
    
    -- Metadata
    source              NVARCHAR(32) NOT NULL,
    import_utc          DATETIME2(0) NOT NULL DEFAULT SYSUTCDATETIME(),
    
    INDEX IX_sector_artcc (artcc_id, sector_type),
    SPATIAL INDEX IX_sector_geo (geometry)
);
```

### 4.5 Flight Weather Impact Table

```sql
CREATE TABLE dbo.adl_flight_weather_impact (
    impact_id           BIGINT IDENTITY(1,1) PRIMARY KEY,
    flight_uid          BIGINT NOT NULL,
    alert_id            INT NOT NULL,              -- FK to weather_alerts
    
    detected_utc        DATETIME2(0) NOT NULL,
    impact_type         NVARCHAR(16) NOT NULL,     -- DIRECT, NEAR, ROUTE
    distance_nm         DECIMAL(8,2) NULL,         -- Distance to hazard
    
    -- Position at detection
    lat                 DECIMAL(10,7) NOT NULL,
    lon                 DECIMAL(11,7) NOT NULL,
    altitude_ft         INT NULL,
    
    INDEX IX_weather_impact_flight (flight_uid, detected_utc),
    INDEX IX_weather_impact_alert (alert_id)
);
```

### 4.6 Boundary Crossing Events Table

```sql
CREATE TABLE dbo.adl_boundary_crossings (
    crossing_id         BIGINT IDENTITY(1,1) PRIMARY KEY,
    flight_uid          BIGINT NOT NULL,
    crossing_utc        DATETIME2(0) NOT NULL,
    
    boundary_type       NVARCHAR(16) NOT NULL,     -- ARTCC, SECTOR, FIR
    from_region         NVARCHAR(8) NULL,          -- e.g., ZDC
    to_region           NVARCHAR(8) NOT NULL,      -- e.g., ZNY
    
    -- Position at crossing
    lat                 DECIMAL(10,7) NOT NULL,
    lon                 DECIMAL(11,7) NOT NULL,
    altitude_ft         INT NULL,
    
    INDEX IX_crossing_flight (flight_uid, crossing_utc),
    INDEX IX_crossing_artcc (to_region, crossing_utc)
);
```

### 4.7 Columns to Add to adl_flight_core

```sql
ALTER TABLE dbo.adl_flight_core ADD
    current_artcc       NVARCHAR(4) NULL,
    current_sector      NVARCHAR(8) NULL,
    weather_impact      NVARCHAR(32) NULL,         -- NONE, SIGMET, CONVECTIVE
    weather_alert_id    INT NULL,
    last_boundary_check_utc DATETIME2(0) NULL;
```

---

## 5. Weather Integration

### 5.1 Import Procedure

```sql
CREATE PROCEDURE dbo.sp_ImportWeatherAlerts
AS
BEGIN
    -- Called by scheduled job every 5 minutes
    -- 1. Fetch from aviationweather.gov API
    -- 2. Parse JSON response
    -- 3. Upsert into weather_alerts
    -- 4. Mark expired alerts as inactive
    -- 5. Return import stats
END
```

### 5.2 PHP Import Script

**File:** `adl/php/import_weather_alerts.php`

```php
<?php
// Fetch SIGMET/AIRMET data from aviationweather.gov
$url = 'https://aviationweather.gov/api/data/airsigmet?format=json';
$response = file_get_contents($url);
$data = json_decode($response, true);

foreach ($data as $alert) {
    // Parse coordinates into WKT polygon
    $points = [];
    foreach ($alert['coords'] as $coord) {
        $points[] = "{$coord['lon']} {$coord['lat']}";
    }
    $wkt = "POLYGON((" . implode(',', $points) . "," . $points[0] . "))";
    
    // Insert into database
    $sql = "
        MERGE dbo.weather_alerts AS target
        USING (VALUES (...)) AS source
        ON target.source_id = source.source_id
        WHEN MATCHED THEN UPDATE SET ...
        WHEN NOT MATCHED THEN INSERT ...
    ";
}
```

### 5.3 Weather Alert Types

| Type | Hazard | Source | Description |
|------|--------|--------|-------------|
| SIGMET | CONVECTIVE | WST | Convective SIGMET - thunderstorms |
| SIGMET | TURB | WS | Turbulence SIGMET |
| SIGMET | ICE | WS | Icing SIGMET |
| AIRMET | TURB | WA | Turbulence AIRMET (Tango) |
| AIRMET | ICE | WA | Icing AIRMET (Zulu) |
| AIRMET | IFR | WA | IFR AIRMET (Sierra) |
| AIRMET | MTN | WA | Mountain obscuration |

### 5.4 Weather Impact Detection

```sql
CREATE PROCEDURE dbo.sp_DetectWeatherImpact
AS
BEGIN
    -- For each active flight, check if within any weather polygon
    INSERT INTO dbo.adl_flight_weather_impact (flight_uid, alert_id, detected_utc, impact_type, lat, lon, altitude_ft)
    SELECT 
        c.flight_uid,
        w.alert_id,
        SYSUTCDATETIME(),
        CASE 
            WHEN w.geometry.STContains(geography::Point(p.lat, p.lon, 4326)) = 1 THEN 'DIRECT'
            WHEN w.geometry.STDistance(geography::Point(p.lat, p.lon, 4326)) < 37040 THEN 'NEAR' -- 20nm
            ELSE 'ROUTE'
        END,
        p.lat, p.lon, p.altitude_ft
    FROM dbo.adl_flight_core c
    JOIN dbo.adl_flight_position p ON p.flight_uid = c.flight_uid
    JOIN dbo.weather_alerts w ON w.is_active = 1
        AND SYSUTCDATETIME() BETWEEN w.valid_from_utc AND w.valid_to_utc
        AND (w.floor_fl IS NULL OR p.altitude_ft / 100 >= w.floor_fl)
        AND (w.ceiling_fl IS NULL OR p.altitude_ft / 100 <= w.ceiling_fl)
    WHERE c.is_active = 1
      AND w.geometry.STDistance(geography::Point(p.lat, p.lon, 4326)) < 185200 -- 100nm
      AND NOT EXISTS (
          SELECT 1 FROM dbo.adl_flight_weather_impact i
          WHERE i.flight_uid = c.flight_uid AND i.alert_id = w.alert_id
            AND i.detected_utc > DATEADD(MINUTE, -15, SYSUTCDATETIME())
      );
END
```

---

## 6. Boundary Integration

### 6.1 ARTCC Boundary Import

**Source:** VATSpy Boundaries.txt or FAA AIXM

```sql
CREATE PROCEDURE dbo.sp_ImportARTCCBoundaries
    @source NVARCHAR(32) = 'VATSPY'
AS
BEGIN
    -- Parse boundary file and insert polygons
    -- VATSpy format: ARTCC|lat1,lon1|lat2,lon2|...
END
```

### 6.2 ARTCC Detection Function

```sql
CREATE FUNCTION dbo.fn_DetectCurrentARTCC(
    @lat DECIMAL(10,7),
    @lon DECIMAL(11,7)
)
RETURNS NVARCHAR(4)
AS
BEGIN
    DECLARE @artcc NVARCHAR(4);
    
    SELECT TOP 1 @artcc = artcc_id
    FROM dbo.artcc_boundaries
    WHERE boundary_type = 'ARTCC'
      AND geometry.STContains(geography::Point(@lat, @lon, 4326)) = 1;
    
    RETURN @artcc;
END
```

### 6.3 Boundary Crossing Detection

```sql
CREATE PROCEDURE dbo.sp_DetectBoundaryCrossings
AS
BEGIN
    DECLARE @now DATETIME2(0) = SYSUTCDATETIME();
    
    -- Detect ARTCC transitions
    INSERT INTO dbo.adl_boundary_crossings (flight_uid, crossing_utc, boundary_type, from_region, to_region, lat, lon, altitude_ft)
    SELECT 
        c.flight_uid,
        @now,
        'ARTCC',
        c.current_artcc,
        dbo.fn_DetectCurrentARTCC(p.lat, p.lon),
        p.lat, p.lon, p.altitude_ft
    FROM dbo.adl_flight_core c
    JOIN dbo.adl_flight_position p ON p.flight_uid = c.flight_uid
    WHERE c.is_active = 1
      AND c.current_artcc IS NOT NULL
      AND c.current_artcc != dbo.fn_DetectCurrentARTCC(p.lat, p.lon)
      AND dbo.fn_DetectCurrentARTCC(p.lat, p.lon) IS NOT NULL;
    
    -- Update current ARTCC
    UPDATE c
    SET c.current_artcc = dbo.fn_DetectCurrentARTCC(p.lat, p.lon),
        c.last_boundary_check_utc = @now
    FROM dbo.adl_flight_core c
    JOIN dbo.adl_flight_position p ON p.flight_uid = c.flight_uid
    WHERE c.is_active = 1;
END
```

---

## 7. Flight Impact Analysis

### 7.1 Impact Categories

| Category | Criteria | UI Color |
|----------|----------|----------|
| DIRECT | Flight currently inside hazard polygon | Red |
| NEAR | Flight within 20nm of hazard | Orange |
| ROUTE | Projected route crosses hazard | Yellow |
| CLEAR | No weather impact | Green/Default |

### 7.2 Impact Summary Query

```sql
-- Get weather impact summary for active flights
SELECT 
    w.hazard,
    w.severity,
    COUNT(DISTINCT i.flight_uid) AS flights_affected,
    w.source_id,
    w.valid_from_utc,
    w.valid_to_utc
FROM dbo.weather_alerts w
LEFT JOIN dbo.adl_flight_weather_impact i ON i.alert_id = w.alert_id
    AND i.detected_utc > DATEADD(MINUTE, -15, SYSUTCDATETIME())
WHERE w.is_active = 1
GROUP BY w.alert_id, w.hazard, w.severity, w.source_id, w.valid_from_utc, w.valid_to_utc
ORDER BY flights_affected DESC;
```

### 7.3 Route Weather Check

For flights with parsed routes, check if route crosses weather:

```sql
CREATE FUNCTION dbo.fn_RouteWeatherImpact(
    @flight_uid BIGINT
)
RETURNS TABLE
AS
RETURN
    SELECT 
        w.alert_id,
        w.hazard,
        w.severity,
        fp.route_geometry.STIntersection(w.geometry).STLength() / 1852.0 AS affected_nm
    FROM dbo.adl_flight_plan fp
    CROSS JOIN dbo.weather_alerts w
    WHERE fp.flight_uid = @flight_uid
      AND fp.route_geometry IS NOT NULL
      AND w.is_active = 1
      AND fp.route_geometry.STIntersects(w.geometry) = 1;
```

---

## 8. API Specification

### 8.1 Weather Alerts Endpoint

**GET** `/api/weather/alerts.php`

```json
// Request
{
  "type": ["SIGMET", "AIRMET"],    // Optional filter
  "hazard": ["CONVECTIVE", "TURB"], // Optional filter
  "bounds": {                       // Optional geographic filter
    "north": 50.0, "south": 25.0,
    "east": -60.0, "west": -130.0
  }
}

// Response
{
  "success": true,
  "generated_utc": "2026-01-06T19:30:00Z",
  "alerts": [
    {
      "alert_id": 1234,
      "type": "SIGMET",
      "hazard": "CONVECTIVE",
      "severity": "SEV",
      "source_id": "WST1",
      "valid_from": "2026-01-06T18:00:00Z",
      "valid_to": "2026-01-06T22:00:00Z",
      "floor_fl": 0,
      "ceiling_fl": 450,
      "geometry": {
        "type": "Polygon",
        "coordinates": [[[-85.0, 35.0], [-80.0, 35.0], ...]]
      },
      "flights_affected": 47,
      "raw_text": "CONVECTIVE SIGMET 1E VALID..."
    }
  ]
}
```

### 8.2 TFR Endpoint

**GET** `/api/weather/tfrs.php`

```json
{
  "success": true,
  "tfrs": [
    {
      "tfr_id": 56,
      "notam_id": "6/3241",
      "type": "VIP",
      "facility": "Camp David",
      "effective": "2026-01-06T12:00:00Z",
      "expires": "2026-01-06T18:00:00Z",
      "floor_ft": 0,
      "ceiling_ft": 18000,
      "geometry": {
        "type": "Circle",
        "center": [-77.46, 39.65],
        "radius_nm": 30
      }
    }
  ]
}
```

### 8.3 ARTCC Boundaries Endpoint

**GET** `/api/boundaries/artcc.php`

```json
{
  "success": true,
  "artccs": [
    {
      "id": "ZNY",
      "name": "New York Center",
      "geometry": {
        "type": "Polygon",
        "coordinates": [...]
      }
    }
  ]
}
```

### 8.4 Flight Weather Impact Endpoint

**GET** `/api/flights/weather-impact.php?flight_uid=12345`

```json
{
  "flight_uid": 12345,
  "callsign": "UAL123",
  "current_impact": "NEAR",
  "alerts": [
    {
      "alert_id": 1234,
      "hazard": "CONVECTIVE",
      "distance_nm": 15.3,
      "impact_type": "NEAR"
    }
  ],
  "route_impacts": [
    {
      "alert_id": 1235,
      "hazard": "TURB",
      "affected_nm": 45.2
    }
  ]
}
```

---

## 9. UI Components

### 9.1 TSD Map Overlays

| Layer | Style | Toggle |
|-------|-------|--------|
| Convective SIGMET | Red fill, 30% opacity | ☑ Conv |
| Turbulence | Orange fill, 25% opacity | ☑ Turb |
| Icing | Blue fill, 25% opacity | ☑ Ice |
| IFR/MVFR | Gray fill, 20% opacity | ☑ IFR |
| TFR | Pink fill, striped | ☑ TFR |
| ARTCC Boundaries | White dashed lines | ☑ ARTCC |

### 9.2 Weather Panel

```
┌─────────────────────────────────────────────┐
│ ACTIVE WEATHER HAZARDS              [Refresh]│
├─────────────────────────────────────────────┤
│ ● CONV SIGMET WST1    18:00-22:00z   47 flt │
│ ● TURB SIGMET WS2C    17:00-21:00z   23 flt │
│ ○ ICE AIRMET WA3Z     16:00-20:00z    8 flt │
├─────────────────────────────────────────────┤
│ ACTIVE TFRs                                  │
│ ○ 6/3241 VIP         12:00-18:00z    0 flt │
│ ○ 5/8892 SPACE       14:00-16:30z    3 flt │
└─────────────────────────────────────────────┘
```

### 9.3 Flight Impact Badge

On flight list and data tags:
- 🔴 SIGMET (direct)
- 🟠 SIGMET (near)
- 🟡 Route impact
- ⚪ Clear

---

## 10. Implementation Phases

### Phase 5A: Weather Import (3-4 days)
| Task | Effort |
|------|--------|
| Create weather_alerts table | 1 hr |
| Build PHP import script for aviationweather.gov | 4 hrs |
| Implement import stored procedure | 2 hrs |
| Set up scheduled job (5-min refresh) | 1 hr |
| Test with live data | 2 hrs |

### Phase 5B: Weather Display (2-3 days)
| Task | Effort |
|------|--------|
| Create weather API endpoints | 3 hrs |
| Add weather polygon layer to TSD | 4 hrs |
| Build weather panel component | 3 hrs |
| Weather layer toggle controls | 2 hrs |

### Phase 5C: Flight Impact Detection (2-3 days)
| Task | Effort |
|------|--------|
| Create weather impact detection procedure | 3 hrs |
| Integrate into refresh cycle | 1 hr |
| Add impact badge to flight display | 2 hrs |
| Build flight weather impact API | 2 hrs |
| Route impact analysis | 4 hrs |

### Phase 5D: TFR Integration (2 days)
| Task | Effort |
|------|--------|
| Create TFR table | 1 hr |
| Build TFR import script | 4 hrs |
| Add TFR display layer | 3 hrs |
| TFR flight impact detection | 2 hrs |

### Phase 5E: Boundaries (3-4 days)
| Task | Effort |
|------|--------|
| Create ARTCC boundaries table | 1 hr |
| Import VATSpy boundary data | 3 hrs |
| Create boundary crossing detection | 3 hrs |
| Add ARTCC boundary layer to TSD | 2 hrs |
| Log boundary crossings | 2 hrs |
| Add current_artcc to flight display | 1 hr |

### Total Estimated Effort: 12-16 days

---

## 11. Performance Considerations

### 11.1 Spatial Index Usage

All geometry columns have spatial indexes. Queries should use:
- `STContains()` for point-in-polygon
- `STDistance()` with distance filter first
- `STIntersects()` for route crossing

### 11.2 Batch Processing

Weather impact detection runs in batch:
- Every 15 seconds (with VATSIM refresh)
- Only check active flights
- Only check active weather alerts
- Skip recent duplicate detections

### 11.3 Caching

- Weather polygons cached in JavaScript (refresh every 5 min)
- ARTCC boundaries cached permanently (rarely change)
- Flight impact status cached on flight_core table

---

## 12. Testing Strategy

### 12.1 Unit Tests

| Test | Expected |
|------|----------|
| Parse SIGMET JSON | Valid polygon created |
| Parse AIRMET JSON | Valid polygon created |
| Parse TFR circle | Valid circular geography |
| Point in polygon | Correct containment result |
| Distance calculation | Within 0.1nm accuracy |

### 12.2 Integration Tests

| Test | Expected |
|------|----------|
| Weather import cycle | New alerts imported, expired marked inactive |
| Flight impact detection | Correctly identifies flights in hazards |
| Boundary crossing | Logs transitions between ARTCCs |
| API responses | Valid JSON with correct structure |

### 12.3 Performance Tests

| Scenario | Target |
|----------|--------|
| Import 50 weather alerts | < 10 seconds |
| Check 2000 flights vs 30 alerts | < 5 seconds |
| Render 50 polygons on map | < 1 second |
| Boundary detection for 2000 flights | < 3 seconds |

---

## Appendices

### A. aviationweather.gov API Examples

**SIGMET Response:**
```json
{
  "airsigmet": [
    {
      "rawAirSigmet": "CONVECTIVE SIGMET 1E VALID UNTIL 2200Z...",
      "airSigmetType": "SIGMET",
      "hazard": "CONVECTIVE",
      "severity": "SEV",
      "validTimeFrom": "2026-01-06T18:00:00Z",
      "validTimeTo": "2026-01-06T22:00:00Z",
      "altitudeLow1": 0,
      "altitudeHi1": 45000,
      "coords": [
        {"lat": 35.0, "lon": -85.0},
        {"lat": 35.0, "lon": -80.0},
        {"lat": 40.0, "lon": -80.0},
        {"lat": 40.0, "lon": -85.0}
      ]
    }
  ]
}
```

### B. VATSpy Boundary Format

```
[ZNY]
LAT1|LON1
LAT2|LON2
...
```

### C. CONUS ARTCCs

| ID | Name |
|----|------|
| ZAB | Albuquerque Center |
| ZAU | Chicago Center |
| ZBW | Boston Center |
| ZDC | Washington Center |
| ZDV | Denver Center |
| ZFW | Fort Worth Center |
| ZHU | Houston Center |
| ZID | Indianapolis Center |
| ZJX | Jacksonville Center |
| ZKC | Kansas City Center |
| ZLA | Los Angeles Center |
| ZLC | Salt Lake City Center |
| ZMA | Miami Center |
| ZME | Memphis Center |
| ZMP | Minneapolis Center |
| ZNY | New York Center |
| ZOA | Oakland Center |
| ZOB | Cleveland Center |
| ZSE | Seattle Center |
| ZTL | Atlanta Center |

### D. Version History

| Version | Date | Changes |
|---------|------|---------|
| 1.0 | 2026-01-06 | Initial design document |

---

*Document End*
