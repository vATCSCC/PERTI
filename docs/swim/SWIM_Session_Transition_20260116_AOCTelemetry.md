# SWIM Session Transition - AOC Telemetry Support

**Date:** January 16, 2026  
**Session:** AOC Telemetry Integration  
**Status:** ✅ COMPLETE

---

## Executive Summary

Added support for Virtual Airlines (AOC) to push flight sim telemetry via the SWIM ingest API. Key fields like `vertical_rate_fpm` and OOOI times can now be received from flight simulators rather than being calculated server-side.

**Key Decision:** Receive telemetry from AOC sources rather than calculate in ADL.

---

## What Was Done

### 1. Analyzed Data Enrichment Gaps

Investigated SWIM API field population rates:

| Field | VATSIM Sync | Status |
|-------|-------------|--------|
| vertical_rate_fpm | ❌ Not provided | **Now via AOC** |
| out_utc | ⚠️ Zone detection (36%) | **Now via AOC** |
| off_utc | ⚠️ Zone detection (~10%) | **Now via AOC** |
| on_utc | ⚠️ Zone detection (~10%) | **Now via AOC** |
| in_utc | ⚠️ Zone detection (~10%) | **Now via AOC** |

### 2. Updated Ingest Endpoints

**api/swim/v1/ingest/adl.php** (v3.2.0)
- Added `vertical_rate_fpm` field mapping
- Added OOOI times (`out_utc`, `off_utc`, `on_utc`, `in_utc`)
- Added `eta_utc` and `etd_utc` for FMC times
- Supports both update and insert operations

**api/swim/v1/ingest/track.php** (v1.2.0)
- Fixed database connection (`$conn_swim` instead of `$conn_adl`)
- Added `vertical_rate_fpm` support
- High-frequency position updates (1000/batch max)

### 3. Verified Schema - No Migration Needed

All telemetry columns already exist in `swim_flights`:

```sql
-- From 003_swim_api_database_fixed.sql
vertical_rate_fpm INT NULL,
out_utc DATETIME2 NULL,
off_utc DATETIME2 NULL,
on_utc DATETIME2 NULL,
in_utc DATETIME2 NULL,
eta_utc DATETIME2 NULL,
etd_utc DATETIME2 NULL,
```

### 4. Updated Documentation

- `SWIM_TODO.md` - Added AOC Telemetry section
- `VATSIM_SWIM_API.postman_collection.json` - Added AOC examples
- Removed unnecessary migration file (005_swim_add_telemetry_columns.sql)
- Removed unnecessary ADL calculation procedure

---

## Files Changed

| File | Change |
|------|--------|
| `api/swim/v1/ingest/adl.php` | Added telemetry fields, v3.2.0 |
| `api/swim/v1/ingest/track.php` | Fixed DB connection, added vertical_rate, v1.2.0 |
| `docs/swim/SWIM_TODO.md` | Added AOC Telemetry section |
| `docs/swim/VATSIM_SWIM_API.postman_collection.json` | Added AOC examples |

### Files Removed
- `adl/procedures/sp_Adl_CalculateVerticalRate.sql` (not needed)
- `adl/migrations/050_add_vertical_rate_calculation.sql` (not needed)
- `database/migrations/swim/005_swim_add_telemetry_columns.sql` (columns exist)

---

## API Usage Examples

### Push Telemetry with Vertical Rate

```bash
curl -X POST "https://perti.vatcscc.org/api/swim/v1/ingest/adl" \
  -H "Authorization: Bearer swim_par_your_key" \
  -H "Content-Type: application/json" \
  -d '{
    "flights": [{
      "callsign": "DLH401",
      "dept_icao": "KJFK",
      "dest_icao": "EDDF",
      "phase": "climbing",
      "latitude": 41.2345,
      "longitude": -72.5678,
      "altitude_ft": 18500,
      "groundspeed_kts": 320,
      "vertical_rate_fpm": 2200
    }]
  }'
```

### Push OOOI Times

```bash
curl -X POST "https://perti.vatcscc.org/api/swim/v1/ingest/adl" \
  -H "Authorization: Bearer swim_par_your_key" \
  -H "Content-Type: application/json" \
  -d '{
    "flights": [{
      "callsign": "DLH401",
      "dept_icao": "KJFK",
      "dest_icao": "EDDF",
      "out_utc": "2026-01-16T14:30:00Z",
      "off_utc": "2026-01-16T14:45:00Z"
    }]
  }'
```

### High-Frequency Track Updates

```bash
curl -X POST "https://perti.vatcscc.org/api/swim/v1/ingest/track" \
  -H "Authorization: Bearer swim_par_your_key" \
  -H "Content-Type: application/json" \
  -d '{
    "tracks": [{
      "callsign": "DLH401",
      "latitude": 54.5123,
      "longitude": -24.8765,
      "altitude_ft": 39000,
      "ground_speed_kts": 486,
      "heading_deg": 78,
      "vertical_rate_fpm": 0
    }]
  }'
```

---

## Data Flow

```
┌─────────────────────┐
│   VATSIM Network    │──────────────────────────┐
│                     │                          │
└─────────────────────┘                          │
                                                 │
┌─────────────────────┐     ┌─────────────────┐  │   ┌─────────────────┐
│   Virtual Airline   │     │   ADL Daemon    │◄─┴──▶│   SWIM_API DB   │
│   (smartCARS, etc)  │     │  (15s refresh)  │      │  (Azure SQL)    │
└──────────┬──────────┘     └────────┬────────┘      └────────┬────────┘
           │                         │                        │
           │ telemetry               │ sync                   │
           ▼                         ▼                        ▼
    ┌─────────────────────────────────────────────────────────────┐
    │                      SWIM Ingest API                        │
    │                                                             │
    │   vertical_rate_fpm ◄── From flight sim                     │
    │   OOOI times        ◄── From ACARS/flight sim               │
    │   eta_utc           ◄── From FMC                            │
    └─────────────────────────────────────────────────────────────┘
```

---

## Telemetry Fields Reference

| Field | Type | Description | Source |
|-------|------|-------------|--------|
| `vertical_rate_fpm` | INT | Climb/descent rate (ft/min) | Flight sim |
| `out_utc` | DATETIME | Gate departure (pushback) | ACARS |
| `off_utc` | DATETIME | Wheels up (takeoff) | ACARS |
| `on_utc` | DATETIME | Wheels down (landing) | ACARS |
| `in_utc` | DATETIME | Gate arrival | ACARS |
| `eta_utc` | DATETIME | FMC-calculated ETA | FMC |
| `etd_utc` | DATETIME | Expected departure | Dispatch |

**Vertical Rate Convention:**
- Positive = Climbing (e.g., +2200 fpm)
- Negative = Descending (e.g., -1800 fpm)
- Zero = Level flight

---

## Next Steps

1. **Test with live virtual airline** - Coordinate with VA to test integration
2. **Expand airport geometry** - Improve zone detection coverage (currently 201 airports)
3. **C#/Java SDKs** - Build when consumers need them

---

## Technical Notes

### Why Not Calculate Vertical Rate?

Initially considered calculating `vertical_rate_fpm` from trajectory altitude deltas in ADL. However:

1. **Flight sims have native data** - MSFS, X-Plane, P3D all expose vertical speed directly
2. **More accurate** - Sim data is instantaneous; calculation has 15-60s lag
3. **Less compute** - No need to query trajectory tables
4. **Already in schema** - Column exists, just needed ingest mapping

### Zone Detection Still Running

OOOI zone detection in ADL continues to work as fallback:
- Processing 2,297 transitions/hour
- Available for ~201 airports with OSM geometry
- AOC-provided times override zone detection when present

---

## Contact

- **Developer:** HP
- **Email:** dev@vatcscc.org

---

*Session completed January 16, 2026*
