# PERTI Enhanced OOOI System Design

**Version:** 2.1  
**Date:** 2026-01-06  
**Standards:** TFMS CDM, A-CDM, EUROCONTROL APOC, OSM Aeroways  
**Status:** Phase 4 Implementation Complete

---

## Implementation Status

| Component | Status | Notes |
|-----------|--------|-------|
| Schema (tables, columns) | ✅ Complete | `041_oooi_deploy.sql` |
| Zone detection functions | ✅ Complete | `fn_DetectCurrentZone` |
| Batch processing | ✅ Complete | `sp_ProcessZoneDetectionBatch` |
| Fallback zone generator | ✅ Complete | `sp_GenerateFallbackZones` |
| OSM import script | ✅ Complete | `ImportOSM.ps1` (PowerShell) |
| Airport coverage | ✅ 201 airports | ASPM77 + CA + MX + LatAm + Caribbean |
| Integration with refresh | ⏳ Pending | Add batch call to refresh proc |
| OSM data import | ⏳ Pending | Run `ImportOSM.ps1` after deploy |

---

## 1. Architecture Overview

### 1.1 Key Design Decisions

| Decision | Rationale |
|----------|-----------|
| **Separate times table** | Keeps ADL lean; supports multiple legs per flight; enables detailed analytics |
| **OSM airport geometry** | Precise zone detection (gate, taxiway, runway); standard data source |
| **Leg-based tracking** | Natural support for pattern work, diversions, go-arounds |
| **Event-driven architecture** | Each zone transition creates an event; times derived from events |
| **Fallback zones** | Concentric circles when OSM data unavailable; speed-based detection |

### 1.2 Table Relationships

```
┌─────────────────────┐       ┌─────────────────────────┐
│   dbo.adl_flight_   │       │  dbo.airport_geometry   │
│       core          │       │  (OSM zones per airport)│
└──────────┬──────────┘       └────────────┬────────────┘
           │                               │
           │ flight_uid                    │ airport_icao
           │                               │
           ▼                               ▼
┌─────────────────────────────────────────────────────────┐
│              dbo.adl_flight_times                       │
│  (one row per leg; OUT/OFF/ON/IN + zone transitions)    │
└─────────────────────────────────────────────────────────┘
           │
           │ flight_uid
           ▼
┌─────────────────────────────────────────────────────────┐
│              dbo.adl_zone_events                        │
│  (every zone entry/exit; granular position history)     │
└─────────────────────────────────────────────────────────┘
```

---

## 2. OSM Airport Geometry

### 2.1 OSM Aeroway Tags to Import

| OSM Tag | Zone Type | Description |
|---------|-----------|-------------|
| `aeroway=parking_position` | `PARKING` | Aircraft stand/gate position |
| `aeroway=gate` | `GATE` | Passenger boarding gate |
| `aeroway=apron` | `APRON` | Ramp/apron area |
| `aeroway=taxilane` | `TAXILANE` | Non-movement area taxiway |
| `aeroway=taxiway` | `TAXIWAY` | Movement area taxiway |
| `aeroway=holding_position` | `HOLD` | Runway holding position |
| `aeroway=runway` | `RUNWAY` | Runway surface |
| `aeroway=stopway` | `STOPWAY` | Overrun area |

### 2.2 Zone Hierarchy (Inner to Outer)

```
PARKING → APRON → TAXILANE → TAXIWAY → HOLD → RUNWAY → AIRBORNE
    ↑                                                      │
    └──────────────────────────────────────────────────────┘
                    (arrival reverses direction)
```

### 2.3 Airport Geometry Table (IMPLEMENTED)

```sql
CREATE TABLE dbo.airport_geometry (
    id              INT IDENTITY(1,1) PRIMARY KEY,
    airport_icao    NVARCHAR(4) NOT NULL,
    zone_type       NVARCHAR(16) NOT NULL,      -- PARKING/APRON/TAXIWAY/HOLD/RUNWAY
    zone_name       NVARCHAR(32) NULL,          -- e.g., "A1", "RWY 28L", "TANGO"
    osm_id          BIGINT NULL,                -- OSM way/node ID
    geometry        GEOGRAPHY NOT NULL,         -- Polygon or LineString buffered
    geometry_wkt    NVARCHAR(MAX) NULL,         -- WKT for debugging
    center_lat      DECIMAL(10,7) NULL,
    center_lon      DECIMAL(11,7) NULL,
    heading_deg     SMALLINT NULL,              -- For runways
    length_ft       INT NULL,                   -- For runways
    width_ft        INT NULL,                   -- For runways/taxiways
    elevation_ft    INT NULL,                   -- Airport elevation
    is_active       BIT NOT NULL DEFAULT 1,
    source          NVARCHAR(16) DEFAULT 'OSM', -- OSM/FALLBACK/MANUAL
    import_utc      DATETIME2(0) NOT NULL DEFAULT SYSUTCDATETIME(),
    updated_utc     DATETIME2(0) NOT NULL DEFAULT SYSUTCDATETIME(),
    
    INDEX IX_airport_geo_icao (airport_icao, zone_type),
    SPATIAL INDEX IX_airport_geo_spatial (geometry)
);
```

### 2.4 Zone Detection Function (IMPLEMENTED)

```sql
CREATE FUNCTION dbo.fn_DetectCurrentZone(
    @airport_icao NVARCHAR(4), 
    @lat DECIMAL(10,7), 
    @lon DECIMAL(11,7), 
    @altitude_ft INT, 
    @groundspeed_kts INT
)
RETURNS NVARCHAR(16) AS
BEGIN
    DECLARE @zone NVARCHAR(16) = 'UNKNOWN';
    DECLARE @airport_elev INT, @agl INT;
    
    IF @lat IS NULL OR @lon IS NULL OR @airport_icao IS NULL RETURN 'UNKNOWN';
    
    -- Get airport elevation
    SELECT @airport_elev = ISNULL(CAST(ELEV AS INT), 0) 
    FROM dbo.apts WHERE ICAO_ID = @airport_icao;
    SET @agl = @altitude_ft - ISNULL(@airport_elev, 0);
    
    -- Check if airborne
    IF @agl > 500 RETURN 'AIRBORNE';
    
    -- Check OSM geometry (prioritized by zone type)
    SELECT TOP 1 @zone = ag.zone_type 
    FROM dbo.airport_geometry ag
    WHERE ag.airport_icao = @airport_icao 
      AND ag.is_active = 1 
      AND ag.geometry.STDistance(geography::Point(@lat, @lon, 4326)) < 100
    ORDER BY 
        CASE ag.zone_type 
            WHEN 'PARKING' THEN 1 
            WHEN 'GATE' THEN 2 
            WHEN 'HOLD' THEN 3 
            WHEN 'RUNWAY' THEN 4 
            WHEN 'TAXILANE' THEN 5 
            WHEN 'TAXIWAY' THEN 6 
            WHEN 'APRON' THEN 7 
            ELSE 99 
        END, 
        ag.geometry.STDistance(geography::Point(@lat, @lon, 4326));
    
    IF @zone != 'UNKNOWN' RETURN @zone;
    
    -- Fallback: speed-based detection
    IF @groundspeed_kts < 5 RETURN 'PARKING';
    IF @groundspeed_kts BETWEEN 5 AND 35 RETURN 'TAXIWAY';
    IF @groundspeed_kts > 35 AND @agl < 100 RETURN 'RUNWAY';
    
    RETURN 'AIRBORNE';
END
```

### 2.5 Fallback Zone Generator (IMPLEMENTED)

When OSM data is not available, generates concentric circle zones:

| Zone | Radius | Description |
|------|--------|-------------|
| RUNWAY | 200m | Inner circle around airport center |
| TAXIWAY | 500m | Ring from 200m to 500m |
| APRON | 800m | Ring from 500m to 800m |
| PARKING | 1200m | Ring from 800m to 1200m |

```sql
EXEC dbo.sp_GenerateFallbackZones @airport_icao = 'KJFK';
```

---

## 3. Airport Coverage (IMPLEMENTED)

### 3.1 Target Airports: 201 Total

| Region | Count | Examples |
|--------|-------|----------|
| **ASPM77 (US)** | 77 | KATL, KJFK, KLAX, KORD, KSFO, KDFW |
| **Canada** | 17 | CYYZ, CYVR, CYUL, CYYC, CYOW |
| **Mexico** | 20 | MMMX, MMUN, MMTJ, MMMY, MMGL |
| **Central America** | 9 | MGGT, MSLP, MROC, MPTO |
| **Caribbean** | 33 | TJSJ, MYNN, TNCM, MKJP, MDPC |
| **South America** | 45 | SBGR, SAEZ, SCEL, SKBO, SPJC |

### 3.2 OSM Import Script

PowerShell script for importing OSM geometry:

```powershell
# Test single airport
.\ImportOSM.ps1 -Airport KJFK

# Full import (201 airports, ~7 minutes)
.\ImportOSM.ps1

# Resume from specific airport
.\ImportOSM.ps1 -StartFrom CYYZ
```

---

## 4. Zone Events Table (IMPLEMENTED)

```sql
CREATE TABLE dbo.adl_zone_events (
    event_id        BIGINT IDENTITY(1,1) PRIMARY KEY,
    flight_uid      BIGINT NOT NULL,
    event_utc       DATETIME2(0) NOT NULL,
    event_type      NVARCHAR(16) NOT NULL,      -- TRANSITION/DEPARTURE/ARRIVAL
    airport_icao    NVARCHAR(4) NULL,
    from_zone       NVARCHAR(16) NULL,
    to_zone         NVARCHAR(16) NOT NULL,
    zone_name       NVARCHAR(32) NULL,          -- e.g., "RWY 28L"
    lat             DECIMAL(10,7) NOT NULL,
    lon             DECIMAL(11,7) NOT NULL,
    altitude_ft     INT NULL,
    groundspeed_kts INT NULL,
    heading_deg     SMALLINT NULL,
    vertical_rate_fpm INT NULL,
    detection_method NVARCHAR(32) NOT NULL DEFAULT 'OSM_GEOMETRY',
    distance_to_zone_m DECIMAL(10,2) NULL,
    confidence      DECIMAL(3,2) NULL,          -- 0.00 to 1.00
    
    INDEX IX_zone_events_flight (flight_uid, event_utc),
    INDEX IX_zone_events_airport (airport_icao, event_utc DESC)
);
```

---

## 5. Flight Times Columns (IMPLEMENTED)

Added to `adl_flight_times`:

```sql
-- Departure zone times
parking_left_utc    DATETIME2(0) NULL,
taxiway_entered_utc DATETIME2(0) NULL,
hold_entered_utc    DATETIME2(0) NULL,
runway_entered_utc  DATETIME2(0) NULL,
takeoff_roll_utc    DATETIME2(0) NULL,
rotation_utc        DATETIME2(0) NULL,

-- Arrival zone times
approach_start_utc  DATETIME2(0) NULL,
threshold_utc       DATETIME2(0) NULL,
touchdown_utc       DATETIME2(0) NULL,
rollout_end_utc     DATETIME2(0) NULL,
taxiway_arr_utc     DATETIME2(0) NULL,
parking_entered_utc DATETIME2(0) NULL,
```

---

## 6. Batch Processing (IMPLEMENTED)

### 6.1 Zone Detection Batch Procedure

```sql
CREATE PROCEDURE dbo.sp_ProcessZoneDetectionBatch 
    @transitions_detected INT = NULL OUTPUT 
AS
BEGIN
    -- Identifies flights near airports
    -- Detects zone changes using fn_DetectCurrentZone
    -- Logs transitions to adl_zone_events
    -- Updates OOOI times automatically:
    --   OUT: PARKING → non-PARKING
    --   OFF: RUNWAY → AIRBORNE
    --   ON:  AIRBORNE → RUNWAY
    --   IN:  non-PARKING → PARKING (after landing)
END;
```

### 6.2 Integration with Refresh Procedure

Add to `sp_Adl_RefreshFromVatsim_Normalized`:

```sql
-- After trajectory processing
DECLARE @zone_transitions INT;
EXEC dbo.sp_ProcessZoneDetectionBatch @zone_transitions OUTPUT;
```

---

## 7. Deployment Files

| File | Purpose |
|------|---------|
| `adl/migrations/041_oooi_deploy.sql` | Complete schema + procedures |
| `adl/migrations/042_seed_airport_zones.sql` | SQL-only fallback seeding |
| `adl/php/ImportOSM.ps1` | PowerShell OSM importer |
| `adl/OOOI_Zone_Detection_Transition_Summary.md` | Transition documentation |

---

## 8. Verification Queries

### Check Zone Coverage
```sql
SELECT 
    COUNT(DISTINCT airport_icao) AS airports_with_zones,
    SUM(CASE WHEN source = 'OSM' THEN 1 ELSE 0 END) AS osm_zones,
    SUM(CASE WHEN source = 'FALLBACK' THEN 1 ELSE 0 END) AS fallback_zones
FROM dbo.airport_geometry;
```

### Test Zone Detection
```sql
SELECT dbo.fn_DetectCurrentZone('KJFK', 40.6413, -73.7781, 13, 0);
-- Expected: PARKING or similar
```

### Check Recent Zone Events
```sql
SELECT TOP 50 
    e.flight_uid, e.event_utc, e.from_zone, e.to_zone, 
    e.airport_icao, e.groundspeed_kts
FROM dbo.adl_zone_events e
ORDER BY e.event_utc DESC;
```

---

## 9. Implementation Phases (Updated)

### Phase 1: Core Tables ✅ COMPLETE
- Created `adl_flight_times` table
- Created `adl_zone_events` table
- Added FK columns to `adl_flight_core`

### Phase 2: OSM Integration ✅ COMPLETE
- Created `airport_geometry` table
- Built OSM import pipeline (PowerShell)
- Defined 201 target airports
- Implemented zone detection function

### Phase 3: Pattern Work ⏳ FUTURE
- Implement leg detection
- Add pattern work classification
- Handle go-arounds

### Phase 4: Analytics & API ⏳ FUTURE
- Create aggregation procedures
- Build API endpoints
- Dashboard integration

---

## 10. Known Limitations

1. **No real-time runway identification** - Zones are circular/buffered, not true polygons
2. **OSM data freshness** - Airport layouts change; periodic re-import recommended
3. **Speed-based fallback** - Less accurate than geometry-based detection
4. **No ground track history** - Only current zone tracked, not path taken

---

## 11. Version History

| Version | Date | Changes |
|---------|------|---------|
| 2.0 | 2026-01-05 | Initial design document |
| 2.1 | 2026-01-06 | Implementation complete; added deployment details, verification queries, airport coverage |

---

*Document End*
