================================================================================
WIND DATA INTEGRATION - DEPLOYMENT GUIDE (NOAA GFS)
================================================================================

PURPOSE
-------
Integrate upper-level wind forecast data into ETA calculations.
Expected improvement: 30-50% reduction in prediction error.

DATA SOURCE
-----------
NOAA GFS via NOMADS (https://nomads.ncep.noaa.gov/)
- FREE and UNLIMITED
- 0.25° native resolution
- Global coverage
- Updates every 6 hours (00, 06, 12, 18 UTC)


FILES
-----
1. adl/migrations/wind/001_wind_grid_schema.sql
   - Database schema for wind data storage

2. adl/migrations/wind/002_eta_wind_integration.sql
   - ETA integration (wind adjustment columns, batch calc)

3. services/wind/fetch_noaa_gfs.py
   - Python script to fetch wind data from NOAA


DEPLOYMENT STEPS
================

Step 1: Install Python Dependencies (use Conda on Windows)
-----------------------------------------------------------
    conda install -c conda-forge cfgrib eccodes xarray requests pyodbc

    Note: pip install won't work on Windows due to GRIB library requirements.
    Conda handles the native eccodes/cfgrib libraries automatically.

Step 2: Deploy Database Schema
------------------------------
In SSMS:
    :r "adl\migrations\wind\001_wind_grid_schema.sql"
    :r "adl\migrations\wind\002_eta_wind_integration.sql"

Step 3: Test Wind Fetch
-----------------------
    cd services/wind
    python fetch_noaa_gfs.py --region=CONUS --debug

Expected output:
    - Downloads ~500KB GRIB files per forecast hour
    - Extracts thousands of grid points
    - Inserts into wind_grid table

Step 4: Verify Data
-------------------
    SELECT
        pressure_hpa,
        COUNT(*) AS points,
        AVG(wind_speed_kts) AS avg_speed
    FROM dbo.wind_grid
    GROUP BY pressure_hpa;

Step 5: Test Wind Calculation
-----------------------------
    DECLARE @cnt INT;
    EXEC dbo.sp_CalculateWindBatch @processed_count = @cnt OUTPUT, @debug = 1;

Step 6: Schedule Wind Fetch
---------------------------
Windows Task Scheduler (every 6 hours):
    Program: python
    Arguments: "C:\path\to\services\wind\fetch_noaa_gfs.py" --region=ALL
    Start in: C:\path\to\services\wind

Or PowerShell:
    $action = New-ScheduledTaskAction -Execute "python" `
        -Argument "fetch_noaa_gfs.py --region=ALL" `
        -WorkingDirectory "C:\path\to\services\wind"
    $trigger = New-ScheduledTaskTrigger -Daily -At "02:00" `
        -RepetitionInterval (New-TimeSpan -Hours 6)
    Register-ScheduledTask -TaskName "PERTI-WindFetch" `
        -Action $action -Trigger $trigger


CONFIGURATION
=============

Grid Resolution (--grid-step)
-----------------------------
    0.25  Native GFS (highest detail, most data)
    0.5   Default (good balance)
    1.0   Coarse (faster, less data)

Regions (--region)
------------------
    CONUS           Continental US
    NORTH_ATLANTIC  NAT tracks
    EUROPE          European coverage
    PACIFIC         Pacific routes
    ALL             All regions

Database Connection
-------------------
Edit DB_CONFIG in fetch_noaa_gfs.py:
    DB_CONFIG = {
        'driver': '{ODBC Driver 17 for SQL Server}',
        'server': 'localhost',
        'database': 'PERTI',
        'trusted_connection': 'yes'
    }


MONITORING
==========

Check data freshness:
    SELECT
        MAX(fetched_utc) AS last_fetch,
        DATEDIFF(MINUTE, MAX(fetched_utc), SYSUTCDATETIME()) AS min_ago,
        COUNT(*) AS total_points
    FROM dbo.wind_grid;

Check by region (approximate):
    SELECT
        CASE
            WHEN lat BETWEEN 20 AND 55 AND lon BETWEEN -130 AND -60 THEN 'CONUS'
            WHEN lat BETWEEN 25 AND 65 AND lon BETWEEN -80 AND 0 THEN 'NAT'
            WHEN lat BETWEEN 30 AND 65 AND lon BETWEEN -15 AND 40 THEN 'EUR'
            ELSE 'OTHER'
        END AS region,
        COUNT(*) AS points
    FROM dbo.wind_grid
    GROUP BY CASE
        WHEN lat BETWEEN 20 AND 55 AND lon BETWEEN -130 AND -60 THEN 'CONUS'
        WHEN lat BETWEEN 25 AND 65 AND lon BETWEEN -80 AND 0 THEN 'NAT'
        WHEN lat BETWEEN 30 AND 65 AND lon BETWEEN -15 AND 40 THEN 'EUR'
        ELSE 'OTHER'
    END;


TROUBLESHOOTING
===============

"Missing required packages"
    conda install -c conda-forge cfgrib eccodes xarray requests pyodbc

"Database connection failed"
    Check DB_CONFIG settings in fetch_noaa_gfs.py
    Verify SQL Server is running and accessible

"GRIB parse error"
    Ensure eccodes is installed: conda install -c conda-forge eccodes
    Try downloading file manually to verify NOMADS is accessible

"No data returned"
    Check NOMADS availability: https://nomads.ncep.noaa.gov/
    GFS data is available ~4 hours after model run


================================================================================
