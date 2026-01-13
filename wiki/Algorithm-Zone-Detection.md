# Zone Detection Algorithm (OOOI)

The zone detection system automatically identifies flight OOOI (Out-Off-On-In) phases by detecting when aircraft transition between airport zones. The system uses OpenStreetMap airport geometry data with speed-based fallback detection.

---

## For Traffic Managers

### What OOOI Tracking Shows

OOOI times represent key operational milestones:

| Event | Definition | Trigger |
|-------|------------|---------|
| **OUT** | Gate departure | Aircraft leaves PARKING zone |
| **OFF** | Wheels up | Aircraft enters AIRBORNE from RUNWAY |
| **ON** | Wheels down | Aircraft enters RUNWAY from AIRBORNE |
| **IN** | Gate arrival | Aircraft enters PARKING zone |

### Zone Types

The system tracks aircraft through these airport zones:

```
┌─────────────────────────────────────────────────────────────┐
│                     AIRPORT ZONES                           │
├─────────────────────────────────────────────────────────────┤
│                                                             │
│   ┌─────────┐    ┌─────────┐    ┌─────────┐    ┌─────────┐ │
│   │ PARKING │───►│ TAXILANE│───►│ TAXIWAY │───►│  HOLD   │ │
│   │ (Gate)  │    │ (Apron) │    │ (Alpha) │    │ (Short) │ │
│   └─────────┘    └─────────┘    └─────────┘    └────┬────┘ │
│                                                      │      │
│                                                      ▼      │
│                                               ┌─────────┐   │
│                                               │ RUNWAY  │   │
│                                               │ (28L)   │   │
│                                               └────┬────┘   │
│                                                    │        │
│                                                    ▼        │
│                                               ┌─────────┐   │
│                                               │AIRBORNE │   │
│                                               └─────────┘   │
└─────────────────────────────────────────────────────────────┘
```

### Operational Metrics Derived from OOOI

| Metric | Calculation | Use |
|--------|-------------|-----|
| **Taxi-Out Time** | OFF - OUT | Departure efficiency |
| **Block Time** | IN - OUT | Total gate-to-gate |
| **Air Time** | ON - OFF | Actual flight time |
| **Taxi-In Time** | IN - ON | Arrival efficiency |
| **Hold Time** | RUNWAY_ENTERED - HOLD_ENTERED | Departure queue delays |

### Coverage

Zone detection is enabled for **201 airports** with OSM geometry data:
- All Core30/OEP35 airports
- Major regional airports
- Busy GA fields

Airports without geometry data use **speed-based fallback** detection.

---

## For Technical Operations

### Monitoring Zone Detection

```sql
-- Recent zone transitions
SELECT TOP 20
    ze.event_utc,
    c.callsign,
    ze.airport_icao,
    ze.from_zone,
    ze.to_zone,
    ze.detection_method,
    ze.confidence
FROM dbo.adl_zone_events ze
JOIN dbo.adl_flight_core c ON c.flight_uid = ze.flight_uid
ORDER BY ze.event_utc DESC;
```

**Expected Detection Methods:**
| Method | Description | Confidence |
|--------|-------------|------------|
| OSM_GEOMETRY | Matched against OSM polygons | 0.75-0.99 |
| SPEED_FALLBACK | Speed-based heuristic | 0.50-0.60 |

### Health Checks

```sql
-- Zone detection coverage
SELECT 
    COUNT(DISTINCT airport_icao) AS airports_with_geometry
FROM dbo.airport_geometry
WHERE is_active = 1;

-- Detection method distribution (last hour)
SELECT 
    detection_method,
    COUNT(*) AS transitions
FROM dbo.adl_zone_events
WHERE event_utc > DATEADD(HOUR, -1, GETUTCDATE())
GROUP BY detection_method;

-- Current zone distribution
SELECT 
    current_zone,
    COUNT(*) AS flights
FROM dbo.adl_flight_core
WHERE is_active = 1
  AND current_zone IS NOT NULL
GROUP BY current_zone;
```

### Common Issues

| Symptom | Cause | Resolution |
|---------|-------|------------|
| No zone events | Procedure not running | Check refresh daemon |
| All SPEED_FALLBACK | Missing airport geometry | Run OSM import |
| Missing OUT events | Aircraft spawned on taxiway | Normal for VATSIM |
| False RUNWAY detections | Speed too high on taxiway | Check speed thresholds |
| Duplicate IN events | Repositioning at gate | Normal behavior |

### Performance

Target: **< 500ms** for batch processing

```sql
-- Check zone detection step timing
SELECT 
    step9_zone_ms,
    zone_transitions
FROM (
    SELECT TOP 10 * FROM dbo.adl_run_log ORDER BY run_utc DESC
) recent;
```

**V3.0 Optimizations:**
- Removed STBuffer() - uses direct STDistance()
- Bounding box pre-filter eliminates flights >12nm from airport
- Skips stationary flights checked within 30 seconds

---

## For Developers

### Algorithm Overview

```
1. Identify flights needing zone check
   - Pre-departure (hasn't taken off yet)
   - Arriving (>80% complete)
   
2. Apply bounding box pre-filter
   - Skip flights >0.2° lat or >0.25° lon from airport
   
3. Skip stationary flights
   - If groundspeed < 5 and checked within 30 seconds
   
4. Calculate zone using STDistance
   - Match against airport_geometry polygons
   - Or use speed-based fallback
   
5. Log zone transitions
   - Only when zone changes from previous
   
6. Update OOOI times
   - Based on specific zone transitions
```

### Zone Detection Function

```sql
-- fn_DetectCurrentZone logic
1. If AGL > 500ft → AIRBORNE
2. Check OSM geometry match (within 100m)
3. Priority order: PARKING > GATE > HOLD > RUNWAY > TAXILANE > TAXIWAY > APRON
4. If no match, speed-based fallback:
   - < 5 kts → PARKING
   - 5-35 kts → TAXIWAY
   - > 35 kts on ground → RUNWAY
```

### OOOI Time Triggers

**Departure Events:**

| Event | From Zone | To Zone | Column Updated |
|-------|-----------|---------|----------------|
| OUT | PARKING | Any other | `out_utc` |
| Taxiway entry | PARKING/APRON | TAXIWAY | `taxiway_entered_utc` |
| Hold entry | TAXIWAY | HOLD | `hold_entered_utc` |
| Runway entry | TAXIWAY/HOLD | RUNWAY | `runway_entered_utc` |
| OFF | RUNWAY | AIRBORNE | `off_utc`, `atd_utc` |

> **V2.1 Note:** `atd_utc` (Actual Time of Departure) is now set on takeoff for ETA accuracy analysis.

**Arrival Events:**

| Event | From Zone | To Zone | Column Updated |
|-------|-----------|---------|----------------|
| ON | AIRBORNE | RUNWAY | `on_utc`, `ata_runway_utc` |
| Runway exit | RUNWAY | TAXIWAY | `taxiway_arr_utc` |
| IN | TAXIWAY/APRON | PARKING | `in_utc` |

> **V2.1 Note:** `ata_runway_utc` (Actual Time of Arrival at Runway) is now set on touchdown for ETA accuracy analysis.

### Database Tables

**airport_geometry** - OSM polygon data:
| Column | Type | Description |
|--------|------|-------------|
| geometry_id | INT | Primary key |
| airport_icao | CHAR(4) | Airport code |
| zone_type | VARCHAR(16) | PARKING/TAXIWAY/RUNWAY/etc |
| zone_name | VARCHAR(64) | e.g., "Taxiway A", "Gate B22" |
| geometry | GEOGRAPHY | Polygon shape |
| is_active | BIT | Enable/disable flag |

**adl_zone_events** - Transition log:
| Column | Type | Description |
|--------|------|-------------|
| event_id | BIGINT | Primary key |
| flight_uid | BIGINT | Flight reference |
| event_utc | DATETIME2(0) | When transition occurred |
| event_type | VARCHAR(16) | TRANSITION |
| airport_icao | CHAR(4) | Which airport |
| from_zone | VARCHAR(16) | Previous zone |
| to_zone | VARCHAR(16) | New zone |
| zone_name | VARCHAR(64) | Specific zone name |
| lat/lon | DECIMAL | Position at transition |
| detection_method | VARCHAR(32) | OSM_GEOMETRY or SPEED_FALLBACK |
| confidence | DECIMAL(3,2) | 0.00-1.00 |

### Spatial Query Optimization

```sql
-- V3.0 optimized approach (no STBuffer)
SELECT TOP 1 zone_type
FROM dbo.airport_geometry ag
WHERE ag.airport_icao = @airport
  AND ag.geometry.STDistance(geography::Point(@lat, @lon, 4326)) < 100
ORDER BY 
    CASE zone_type
        WHEN 'PARKING' THEN 1
        WHEN 'RUNWAY' THEN 4
        WHEN 'TAXIWAY' THEN 6
        ELSE 99
    END,
    ag.geometry.STDistance(geography::Point(@lat, @lon, 4326));
```

**Performance comparison:**
| Approach | Execution Time |
|----------|----------------|
| STBuffer + STIntersects (V1) | ~1.8 seconds |
| Direct STDistance (V2.0) | ~0.3-0.5 seconds |

### Version History

| Version | Date | Changes |
|---------|------|---------|  
| V1.0 | 2025-12 | Initial OSM geometry matching |
| V2.0 | 2026-01 | Performance optimization (STDistance vs STBuffer) |
| V2.1 | 2026-01-13 | Added `atd_utc` and `ata_runway_utc` for ETA accuracy analysis |

### Speed-Based Fallback Logic

When OSM geometry is unavailable:

```sql
CASE
    WHEN @groundspeed_kts < 5 THEN 'PARKING'
    WHEN @groundspeed_kts BETWEEN 5 AND 35 THEN 'TAXIWAY'
    WHEN @groundspeed_kts > 35 AND @agl < 100 THEN 'RUNWAY'
    WHEN @agl BETWEEN 100 AND 500 THEN 'AIRBORNE'
    ELSE 'UNKNOWN'
END
```

### OSM Import Process

Airport geometry is imported from OpenStreetMap via Overpass API:

```powershell
# PowerShell import script queries:
# - aeroway=taxiway
# - aeroway=runway
# - aeroway=parking_position
# - aeroway=apron
# - aeroway=holding_position
```

Coverage: 201 airports prioritized by traffic volume.

---

## Related Documentation

- [[Acronyms#flight-status--delay]] - OOOI definitions
- [[Algorithm-Trajectory-Tiering]] - Uses zone for Tier 3 decisions
- [[Database-Schema]] - Table definitions
- [[Troubleshooting]] - OOOI tracking issues

