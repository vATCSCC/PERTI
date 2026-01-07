# BADA Import Scripts

Scripts for importing EUROCONTROL BADA (Base of Aircraft Data) performance files into PERTI.

## Prerequisites

1. **BADA License**: Apply at https://www.eurocontrol.int/model/bada
2. **Python 3.x**: Standard library only (no additional packages required)
3. **SQL Server**: Run migration 046 first to create tables

## Quick Start

### Step 1: Run Database Migration

```sql
-- In SSMS, run:
-- adl/migrations/046_bada_import_infrastructure.sql
```

### Step 2: Parse PTF Files (Performance Tables)

```bash
# Extract BADA archive to a folder, then:
python bada_ptf_parser.py -i "C:\BADA\PTF" -o bada_ptf_import.sql -r 3.12
```

This generates SQL MERGE statements for all aircraft.

### Step 3: Parse APF Files (Speed Schedules)

```bash
python bada_apf_parser.py -i "C:\BADA\APF" -o bada_apf_import.sql -r 3.12
```

### Step 4: Run Generated SQL

```sql
-- In SSMS, run the generated files:
-- 1. bada_ptf_import.sql
-- 2. bada_apf_import.sql
```

## BADA File Types

| File | Contents | Used For |
|------|----------|----------|
| **PTF** | TAS, ROCD, fuel by flight level | ETA calculation, climb/descent modeling |
| **APF** | CAS/Mach schedules | Speed procedure definitions |
| OPF | Drag polar, thrust, mass limits | Advanced trajectory prediction |

## Database Tables Created

| Table | Purpose |
|-------|---------|
| `aircraft_performance_ptf` | Flight-level specific performance |
| `aircraft_performance_apf` | Speed schedules |
| `aircraft_performance_opf` | Aircraft limits & coefficients |
| `bada_import_log` | Import audit trail |

## Stored Procedures

| Procedure | Purpose |
|-----------|---------|
| `sp_SyncBADA_ToProfiles` | Sync PTF data to summary profiles table |

## Functions

| Function | Purpose |
|----------|---------|
| `fn_GetAircraftPerformanceAtFL` | Get performance at specific flight level |

## Example: Using Altitude-Specific Data

```sql
-- Get B738 performance at FL350 during cruise
SELECT * FROM dbo.fn_GetAircraftPerformanceAtFL('B738', 350, 'cruise');

-- Compare climb rates at different altitudes
SELECT flight_level, climb_rocd_fpm, climb_tas_kts
FROM dbo.aircraft_performance_ptf
WHERE aircraft_icao = 'B738'
ORDER BY flight_level;
```

## Source Priority

The `fn_GetAircraftPerformance` function uses this priority:
1. **BADA** - Full PTF data available
2. **SEED** - Manual seed data (045 migration)
3. **DEFAULT** - Category defaults (_DEF_JL, etc.)
4. **HARDCODED** - Ultimate fallback (280/450/280 kts)

## Troubleshooting

### Parser doesn't find any files
- Check file extensions are `.PTF` or `.APF` (case-insensitive)
- Verify path is correct

### SQL import errors
- Ensure migration 046 has been run first
- Check for aircraft_icao column length (max 8 chars)

### Missing aircraft types
- BADA may use different ICAO codes than VATSIM users
- Check Synonym file in BADA distribution for mappings
