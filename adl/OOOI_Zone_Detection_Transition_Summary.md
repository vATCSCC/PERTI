# OOOI Zone Detection System - Transition Summary

## Document Information
- **Created**: 2026-01-06
- **Phase**: 4 of 6 (ETA/Trajectory Enhancement Project)
- **Status**: Implementation Complete, Deployment Pending
- **Previous Phase**: ETA Calculation & Trajectory Logging (Complete)

---

## Executive Summary

This session implemented Phase 4 of the ETA/Trajectory Enhancement Project: the OOOI Zone Detection System. This system enables automatic capture of OUT/OFF/ON/IN times by detecting aircraft transitions between airport zones (parking, taxiway, runway, airborne) using OpenStreetMap geometry data with speed-based fallback detection.

---

## What Was Built

### 1. Database Schema (Migration 040/041)

**New Tables:**
| Table | Purpose |
|-------|---------|
| `airport_geometry` | Stores airport zone polygons (runways, taxiways, aprons, parking) |
| `adl_zone_events` | Logs all zone transition events with lat/lon/speed/heading |
| `airport_geometry_import_log` | Tracks OSM import history per airport |

**New Columns on `adl_flight_core`:**
- `current_zone` - Current zone type (PARKING, TAXIWAY, RUNWAY, AIRBORNE, etc.)
- `current_zone_airport` - ICAO of airport where zone detected
- `last_zone_check_utc` - Timestamp of last zone detection

**New Columns on `adl_flight_times`:**
- `parking_left_utc`, `taxiway_entered_utc`, `hold_entered_utc`
- `runway_entered_utc`, `takeoff_roll_utc`, `rotation_utc`
- `approach_start_utc`, `threshold_utc`, `touchdown_utc`
- `rollout_end_utc`, `taxiway_arr_utc`, `parking_entered_utc`

### 2. Zone Detection Functions

**`fn_DetectCurrentZone`**
- Scalar function returning zone type for a given position
- Uses OSM geometry when available, falls back to speed-based rules
- Priority order: PARKING > GATE > HOLD > RUNWAY > TAXILANE > TAXIWAY > APRON

**Fallback Rules (when no OSM geometry):**
| Speed | Altitude AGL | Zone |
|-------|--------------|------|
| < 5 kts | - | PARKING |
| 5-35 kts | - | TAXIWAY |
| > 35 kts | < 100 ft | RUNWAY |
| - | > 500 ft | AIRBORNE |

### 3. Stored Procedures

**`sp_GenerateFallbackZones`**
- Creates concentric circle zones when OSM data unavailable
- Zones: 200m runway, 500m taxiway, 800m apron, 1200m parking

**`sp_ProcessZoneDetectionBatch`**
- Batch processes all active flights near airports
- Detects zone transitions and logs to `adl_zone_events`
- Automatically sets OOOI times based on transitions:
  - OUT: PARKING → non-PARKING
  - OFF: RUNWAY → AIRBORNE
  - ON: AIRBORNE → RUNWAY
  - IN: non-PARKING → PARKING (after landing)

### 4. OSM Import Scripts

**PowerShell Script** (`ImportOSM.ps1`) - RECOMMENDED
- Native Windows solution, no PHP extensions required
- Auto-reads credentials from `config.php`
- Queries Overpass API for airport geometry
- 2-second delay between requests (rate limiting)

**PHP Script** (`import_osm_airport_geometry.php`)
- Requires sqlsrv extension (PHP 8.4 or earlier)
- Same functionality as PowerShell version

---

## Airport Coverage

**Total: 201 airports**

| Region | Count | Examples |
|--------|-------|----------|
| ASPM77 (US) | 77 | KATL, KJFK, KLAX, KORD, KSFO |
| Canada | 17 | CYYZ, CYVR, CYUL, CYYC, CYOW |
| Mexico | 20 | MMMX, MMUN, MMTJ, MMMY, MMGL |
| Central America | 9 | MGGT, MSLP, MROC, MPTO |
| Caribbean | 33 | TJSJ, MYNN, TNCM, MKJP, MDPC |
| South America | 45 | SBGR, SAEZ, SCEL, SKBO, SPJC |

---

## Files Created

### Migrations
```
adl/migrations/
├── 040_oooi_schema.sql          # Schema definitions (reference)
├── 041_oooi_deploy.sql          # Complete deployment script
└── 042_seed_airport_zones.sql   # Seeds fallback zones via SQL
```

### Functions & Procedures
```
adl/procedures/
├── fn_DetectCurrentZone.sql         # Zone detection function
├── fn_DetectCurrentZoneWithDetails.sql  # Detailed version (TVF)
├── sp_DetectZoneTransition.sql      # Single-flight detection
├── sp_ProcessZoneDetectionBatch.sql # Batch processor
├── sp_GenerateFallbackZones.sql     # Fallback zone generator
└── sp_ImportAirportGeometry.sql     # OSM JSON parser
```

### Import Scripts
```
adl/php/
├── ImportOSM.ps1                    # PowerShell OSM importer (USE THIS)
├── Import-OSMAirportGeometry.ps1    # PowerShell (original version)
├── import_osm_airport_geometry.php  # PHP version (needs sqlsrv)
└── import_osm_web.php               # Web-callable PHP version
```

---

## Deployment Instructions

### Step 1: Deploy Schema & Procedures
Run in SSMS against VATSIM_ADL:
```sql
-- Execute the complete deployment script
adl/migrations/041_oooi_deploy.sql
```

This creates:
- All tables and columns
- Zone detection functions
- Batch processing procedures
- Seeds 30 starter airports with fallback zones

### Step 2: Import OSM Geometry (Optional but Recommended)
```powershell
cd PERTI\adl\php

# Test with single airport
.\ImportOSM.ps1 -Airport KJFK

# Full import (201 airports, ~7 minutes)
.\ImportOSM.ps1

# Resume from specific airport if interrupted
.\ImportOSM.ps1 -StartFrom CYYZ
```

### Step 3: Alternative - SQL-Only Fallback Zones
If OSM import not possible, run:
```sql
adl/migrations/042_seed_airport_zones.sql
```
This creates fallback zones for all 201 airports using `apts` table coordinates.

### Step 4: Integrate with Refresh Procedure
Add to `sp_Adl_RefreshFromVatsim_Normalized` after trajectory processing:
```sql
-- Zone detection for OOOI
DECLARE @zone_transitions INT;
EXEC dbo.sp_ProcessZoneDetectionBatch @zone_transitions OUTPUT;
```

---

## Zone Hierarchy

### Departure Sequence
```
PARKING → APRON → TAXILANE → TAXIWAY → HOLD → RUNWAY → AIRBORNE
   │                                              │         │
   └─ OUT time                                    │         └─ OFF time
                                                  └─ takeoff_roll_utc
```

### Arrival Sequence
```
AIRBORNE → RUNWAY → TAXIWAY → APRON → PARKING
    │         │                          │
    │         └─ ON time                 └─ IN time
    └─ approach_start_utc
```

---

## Technical Notes

### OSM Data Quality
- OSM coverage varies by airport
- Major US/European airports: Excellent (detailed runways, taxiways, gates)
- Smaller/international airports: Variable (may need fallback)
- Fallback zones provide basic detection even without OSM

### Performance Considerations
- Spatial index on `airport_geometry.geometry` for fast queries
- Batch processing only checks relevant flights (pre-departure or >80% complete)
- 100m buffer for spatial queries to reduce false negatives

### PHP Extension Issue
- PHP 8.5 has no sqlsrv support yet (Microsoft hasn't released it)
- PowerShell script works natively on Windows
- Web PHP works if using PHP 8.4 or earlier on the server

---

## Known Limitations

1. **No real-time runway identification** - Zones are circular/buffered, not true polygons
2. **OSM data freshness** - Airport layouts change; periodic re-import recommended
3. **Speed-based fallback** - Less accurate than geometry-based detection
4. **No ground track history** - Only current zone tracked, not path taken

---

## Future Enhancements (Phase 5-6)

1. **Weather Integration** - TCF/eTCF/SIGMET boundaries
2. **Sector Boundaries** - Real-time sector split awareness
3. **True Polygon Support** - Full OSM way geometry instead of buffered points
4. **Machine Learning** - Improve OOOI detection accuracy
5. **90-Day Retention** - Automated cleanup of historical data

---

## Verification Queries

### Check Zone Coverage
```sql
SELECT 
    COUNT(DISTINCT airport_icao) AS airports_with_zones,
    SUM(CASE WHEN source = 'OSM' THEN 1 ELSE 0 END) AS osm_zones,
    SUM(CASE WHEN source = 'FALLBACK' THEN 1 ELSE 0 END) AS fallback_zones
FROM dbo.airport_geometry;
```

### Check Import Log
```sql
SELECT TOP 20 * 
FROM dbo.airport_geometry_import_log 
ORDER BY import_utc DESC;
```

### Test Zone Detection
```sql
SELECT dbo.fn_DetectCurrentZone('KJFK', 40.6413, -73.7781, 13, 0);
-- Should return: PARKING or similar
```

### Check Zone Events
```sql
SELECT TOP 50 
    e.flight_uid, e.event_utc, e.from_zone, e.to_zone, 
    e.airport_icao, e.groundspeed_kts
FROM dbo.adl_zone_events e
ORDER BY e.event_utc DESC;
```

---

## Session Context

### Previous Sessions Referenced
- ETA/Trajectory Integration Patch (V7) - `/mnt/transcripts/2026-01-06-10-40-45-eta-trajectory-integration-patch.txt`
- Design Document: `oooi_enhanced_design_v2.md`

### Deployment Dependencies
- `041_oooi_deploy.sql` must run BEFORE OSM import
- `sp_GenerateFallbackZones` must exist before running import scripts
- `apts` table must have airport coordinates for fallback generation

---

## Summary

Phase 4 implementation is complete. The OOOI zone detection system is ready for deployment:

1. ✅ Schema created (tables, columns, indexes)
2. ✅ Functions created (zone detection)
3. ✅ Procedures created (batch processing, fallback generation)
4. ✅ Import scripts created (PowerShell and PHP)
5. ✅ 201 airports defined (ASPM77 + international)
6. ⏳ Deployment pending
7. ⏳ Integration with refresh procedure pending
8. ⏳ OSM data import pending

Next action: Run `041_oooi_deploy.sql` in SSMS, then `.\ImportOSM.ps1` in PowerShell.
