# Navigation Data Import Guide

This guide explains how to import worldwide navigation data (fixes, navaids, airways) into ADL for enhanced route parsing.

## Overview

ADL's route parser requires navigation reference data to resolve waypoints and expand airways. By default, only US FAA data may be available. This guide shows how to import **worldwide** data from Navigraph or X-Plane/FlightGear.

## Recommended: Navigraph Data (AIRAC Subscription)

**Navigraph is the best option** if you have a subscription. It provides:
- Official AIRAC data updated every 28 days
- Complete worldwide coverage
- Same data used by real airlines
- SIDs, STARs, and approaches included

### Using Navigraph FMS Data Manager

1. **Install Navigraph FMS Data Manager** from https://navigraph.com/apps/navigation-data/fms-data-manager

2. **Download X-Plane 12 data** (uses same format as X-Plane 11):
   - Open FMS Data Manager
   - Select "X-Plane 12" from the addon list
   - Click "Install" to download the current AIRAC cycle
   - Note the installation path shown in the app

3. **Locate the navigation data files**:

   After installation, the files are typically located at:
   ```
   Windows: %LOCALAPPDATA%\Navigraph\FMS Data\xplane12\
   macOS: ~/Library/Application Support/Navigraph/FMS Data/xplane12/
   ```

   Or if installed directly to X-Plane:
   ```
   X-Plane 12/Custom Data/
   ```

   The files you need are:
   - `earth_fix.dat` - Waypoints/fixes
   - `earth_nav.dat` - Navaids (VOR, NDB, DME)
   - `earth_awy.dat` - Airways

4. **Parse and import** using the same process as X-Plane data (see Step 2 below):
   ```powershell
   cd c:\path\to\PERTI\adl\php
   .\Import-XPlaneNavData.ps1 -DataPath "C:\Users\<you>\AppData\Local\Navigraph\FMS Data\xplane12" -OutputPath ".\nav_import"
   ```

### Staying Current with Navigraph

When a new AIRAC cycle is released (every 28 days):
1. Open FMS Data Manager and update X-Plane 12 data
2. Re-run the PowerShell parser
3. Import with `@clear_existing = 1` to replace old data

## Data Coverage After Import

| Data Type | Approximate Count | Coverage |
|-----------|------------------|----------|
| Fixes/Waypoints | ~200,000 | Worldwide |
| Navaids (VOR/NDB/DME) | ~15,000 | Worldwide |
| Airways | ~10,000+ | Worldwide (J, V, Q, T, A, L, M, N, B, G, R, UL, UM, etc.) |

## Alternative: Free X-Plane/FlightGear Data

If you don't have a Navigraph subscription, you can use free X-Plane data.

### Step 1: Obtain X-Plane Navigation Data

**Option A: Download from X-Plane Gateway**

The navigation data files can be downloaded from:
- https://gateway.x-plane.com/navdata/earthnav/

Download these files:
- `earth_fix.dat` - Waypoints (~200K fixes)
- `earth_nav.dat` - Navaids (VOR, NDB, DME)
- `earth_awy.dat` - Airways

**Option B: Extract from X-Plane Installation**

If you have X-Plane 11/12 installed, the files are in:
```
X-Plane 11/Resources/default data/
X-Plane 12/Resources/default data/
```

**Option C: FlightGear Data**

FlightGear uses compatible formats:
```
FlightGear/data/Navaids/
```

### Step 2: Parse the Data Files

Place the `.dat` files in a folder, then run the PowerShell parser:

```powershell
cd c:\path\to\PERTI\adl\php

# Parse downloaded data (files in .\xplane_navdata folder)
.\Import-XPlaneNavData.ps1 -DataPath ".\xplane_navdata" -OutputPath ".\nav_import"

# Or download automatically (if gateway allows)
.\Import-XPlaneNavData.ps1 -DownloadData -OutputPath ".\nav_import"
```

This creates CSV files in the output folder:
- `xplane_fixes.csv`
- `xplane_navaids.csv`
- `xplane_airways.csv`
- `xplane_airway_segments.csv`

### Step 3: Deploy SQL Import Procedure

Run the migration in Azure Data Studio or SSMS:

```sql
-- Run migration (creates staging tables and import procedures)
-- Execute: adl\migrations\050_xplane_navdata_import.sql
```

### Step 4: Import the Data

```sql
-- Import from the folder containing CSV files
EXEC sp_ImportXPlaneFromFolder
    @folder_path = 'C:\path\to\nav_import',
    @clear_existing = 0;  -- Set to 1 to replace existing X-Plane data
```

Or import individual files:

```sql
EXEC sp_ImportXPlaneNavData
    @fixes_csv = 'C:\path\to\xplane_fixes.csv',
    @navaids_csv = 'C:\path\to\xplane_navaids.csv',
    @airways_csv = 'C:\path\to\xplane_airways.csv',
    @segments_csv = 'C:\path\to\xplane_airway_segments.csv';
```

## Verification

After import, verify the data:

```sql
-- Check totals by source
SELECT source, fix_type, COUNT(*) as cnt
FROM nav_fixes
GROUP BY source, fix_type
ORDER BY source, fix_type;

-- Check airways
SELECT airway_type, COUNT(*) as cnt
FROM airways
GROUP BY airway_type;

-- Test a specific international fix
SELECT * FROM nav_fixes WHERE fix_name = 'NATIK';  -- North Atlantic
SELECT * FROM nav_fixes WHERE fix_name = 'POVEL';  -- Europe
SELECT * FROM nav_fixes WHERE fix_name = 'NOPAC';  -- Pacific

-- Test an international airway
SELECT * FROM airways WHERE airway_name = 'L10';   -- European airway
SELECT * FROM airways WHERE airway_name = 'A1';    -- Oceanic airway
```

## Updating Navigation Data

X-Plane navigation data is updated with each AIRAC cycle (every 28 days). To update:

1. Download fresh `.dat` files
2. Run the parser again
3. Import with `@clear_existing = 1` to replace old X-Plane data

```sql
EXEC sp_ImportXPlaneFromFolder
    @folder_path = 'C:\path\to\nav_import',
    @clear_existing = 1;  -- Clears existing XPLANE-sourced records first
```

## Troubleshooting

### Bulk Insert Fails

If you get "Access is denied" errors:
1. Ensure SQL Server has read access to the CSV folder
2. Or copy CSVs to a location SQL Server can access (e.g., `C:\Temp\`)

### Missing Airways in Parser Output

The airway expansion in sp_ParseRoute requires:
1. Both endpoints to exist in `nav_fixes`
2. The airway to exist in `airways` with a valid `fix_sequence`

After import, test with:

```sql
-- Test airway expansion
SELECT * FROM dbo.fn_ExpandAirway('J60', 'JUDDS', 'HAVER');
```

### International Routes Still Not Parsing

Some reasons:
1. **Oceanic coordinates**: Routes like `5530N020W` are coordinate waypoints, not named fixes
2. **Random routing**: Some routes use random lat/lon points not in any database
3. **Proprietary data**: Some SIDs/STARs require paid AIRAC subscriptions

The parser handles coordinate waypoints natively - these don't need nav_fixes entries.

## Data Sources Summary

| Source | Coverage | License | Update Frequency |
|--------|----------|---------|-----------------|
| **Navigraph** | Worldwide | Subscription required | Every 28 days (AIRAC) |
| X-Plane Gateway | Worldwide | Free for personal use | Each AIRAC cycle |
| FlightGear | Worldwide | GPL | Community updates |
| FAA NASR | US only | Public domain | 28-day cycle |

## Files Reference

```
adl/
├── php/
│   └── Import-XPlaneNavData.ps1    # PowerShell parser
├── migrations/
│   └── 050_xplane_navdata_import.sql  # SQL import procedures
└── NAVDATA_IMPORT.md               # This file
```
