# OOOI Zone Detection - Quick Start Guide
**Date:** 2026-01-06  
**Status:** Implementation Complete, Deployment Pending

---

## 🎯 TL;DR

Phase 4 (OOOI Zone Detection) code is **done**. You just need to:
1. Run the SQL deployment script
2. Run the PowerShell OSM import
3. Add one line to the refresh procedure

---

## ✅ What's Been Built

| Component | File | Status |
|-----------|------|--------|
| Schema + Functions + Procedures | `041_oooi_deploy.sql` | Ready to deploy |
| SQL-only fallback seeding | `042_seed_airport_zones.sql` | Backup option |
| OSM Import Script | `ImportOSM.ps1` | Ready to run |
| Transition Summary | `OOOI_Zone_Detection_Transition_Summary.md` | Documentation |
| Updated OOOI Design Doc | `oooi_enhanced_design_v2.1.md` | In adl/ folder |

**Airport Coverage:** 201 airports (ASPM77 + Canada + Mexico + LatAm + Caribbean)

---

## 📋 TO-DO LIST

### Step 1: Deploy Schema (5 min)
Open SSMS, connect to VATSIM_ADL, run:
```
adl/migrations/041_oooi_deploy.sql
```

This creates:
- `airport_geometry` table
- `adl_zone_events` table
- `airport_geometry_import_log` table
- New columns on `adl_flight_core` and `adl_flight_times`
- `fn_DetectCurrentZone` function
- `sp_ProcessZoneDetectionBatch` procedure
- `sp_GenerateFallbackZones` procedure
- Seeds 30 starter airports with fallback zones

### Step 2: Import OSM Geometry (~7 min)
Open PowerShell, run:
```powershell
cd "C:\Users\jerem.DESKTOP-T926IG8\OneDrive - Virtual Air Traffic Control System Command Center\Documents - Virtual Air Traffic Control System Command Center\VATSIM PERTI\PERTI\adl\php"

# Test with one airport first
.\ImportOSM.ps1 -Airport KJFK

# If that works, run full import
.\ImportOSM.ps1
```

**Note:** Script auto-reads credentials from `config.php`. Takes ~7 minutes for all 201 airports (2-second delay between API calls).

### Step 3: Integrate with Refresh Procedure
Add this to `sp_Adl_RefreshFromVatsim_Normalized` (after trajectory processing):
```sql
-- Zone detection for OOOI
DECLARE @zone_transitions INT;
EXEC dbo.sp_ProcessZoneDetectionBatch @zone_transitions OUTPUT;
```

### Step 4: Verify
Run these queries to confirm everything works:
```sql
-- Check zone coverage
SELECT 
    COUNT(DISTINCT airport_icao) AS airports,
    SUM(CASE WHEN source = 'OSM' THEN 1 ELSE 0 END) AS osm_zones,
    SUM(CASE WHEN source = 'FALLBACK' THEN 1 ELSE 0 END) AS fallback_zones
FROM dbo.airport_geometry;

-- Test zone detection function
SELECT dbo.fn_DetectCurrentZone('KJFK', 40.6413, -73.7781, 13, 0);
-- Should return: PARKING or APRON

-- Check import log
SELECT TOP 10 * FROM dbo.airport_geometry_import_log ORDER BY import_utc DESC;
```

---

## 🔧 If Something Goes Wrong

### OSM Import Fails
Use SQL-only fallback instead:
```sql
-- Run in SSMS
adl/migrations/042_seed_airport_zones.sql
```
This creates basic concentric circle zones for all 201 airports.

### PowerShell Execution Policy Error
```powershell
Set-ExecutionPolicy -Scope Process -ExecutionPolicy Bypass
```

### Resume OSM Import
If import was interrupted:
```powershell
.\ImportOSM.ps1 -StartFrom CYYZ  # Replace with last successful airport
```

---

## 📁 Key File Locations

```
PERTI/adl/
├── migrations/
│   ├── 041_oooi_deploy.sql          ← RUN THIS FIRST
│   └── 042_seed_airport_zones.sql   ← Backup option
├── php/
│   └── ImportOSM.ps1                ← RUN THIS SECOND
├── OOOI_Zone_Detection_Transition_Summary.md
└── oooi_enhanced_design_v2.1.md
```

---

## 🔮 What's Next (After Deployment)

1. **Test with live VATSIM data** - Watch zone_events populate
2. **Monitor OOOI times** - Verify OUT/OFF/ON/IN are being set
3. **Phase 5** - Weather integration (TCF/SIGMET boundaries)
4. **Phase 6** - Testing & optimization

---

## 📊 How It Works (Quick Reference)

```
Aircraft at gate (GS=0) → PARKING zone
Aircraft pushes back    → OUT time set
Aircraft taxiing (GS=15)→ TAXIWAY zone
Aircraft holds short    → HOLD zone
Aircraft on runway      → RUNWAY zone
Aircraft rotates (GS>100, climbing) → OFF time set, AIRBORNE zone

[Flight enroute...]

Aircraft descending     → Approach tracking starts
Aircraft touches down   → ON time set, RUNWAY zone
Aircraft exits runway   → TAXIWAY zone
Aircraft at gate        → IN time set, PARKING zone
```

---

**Good luck! 🚀**
