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

3. adl/migrations/wind/003_wind_tiered_resolution.sql
   - Tiered resolution system (airport proximity, regions)

4. services/wind/fetch_noaa_gfs.py
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
In SSMS (run in order):
    :r "adl\migrations\wind\001_wind_grid_schema.sql"
    :r "adl\migrations\wind\002_eta_wind_integration.sql"
    :r "adl\migrations\wind\003_wind_tiered_resolution.sql"

Step 3: Build Grid-Tier Lookup Table
------------------------------------
    EXEC dbo.sp_BuildWindGridTierLookup @debug = 1;

This precomputes airport proximity and assigns tiers to all grid points.
Takes ~2-3 minutes. Only needs to run once (or when airports change).

Step 4: Verify Tier Configuration
---------------------------------
    SELECT * FROM dbo.vw_WindTierStats;

Expected output shows points per tier with resolution and altitude info.

Step 5: Test Wind Fetch
-----------------------
    cd services/wind
    python fetch_noaa_gfs.py --tier=0,1 --debug

This fetches only domestic tiers (near airports and enroute).
Expected: Downloads GRIB files, extracts grid points, inserts to database.

Step 6: Verify Data
-------------------
    SELECT
        tier,
        pressure_hpa,
        COUNT(*) AS points,
        AVG(wind_speed_kts) AS avg_speed
    FROM dbo.wind_grid
    GROUP BY tier, pressure_hpa
    ORDER BY tier, pressure_hpa;

Step 7: Test Wind Calculation
-----------------------------
    DECLARE @cnt INT;
    EXEC dbo.sp_CalculateWindBatch @processed_count = @cnt OUTPUT, @debug = 1;

Step 8: Schedule Wind Fetch
---------------------------
Windows Task Scheduler (every 6 hours):
    Program: python
    Arguments: "C:\path\to\services\wind\fetch_noaa_gfs.py" --all-tiers
    Start in: C:\path\to\services\wind

Or PowerShell:
    $action = New-ScheduledTaskAction -Execute "python" `
        -Argument "fetch_noaa_gfs.py --all-tiers" `
        -WorkingDirectory "C:\path\to\services\wind"
    $trigger = New-ScheduledTaskTrigger -Daily -At "02:00" `
        -RepetitionInterval (New-TimeSpan -Hours 6)
    Register-ScheduledTask -TaskName "PERTI-WindFetch" `
        -Action $action -Trigger $trigger


TIERED RESOLUTION SYSTEM
========================

The system uses 11 tiers optimized for different regions and altitudes:

Tier  Name              Res    Min Alt   Use Case
----  ----------------  ----   --------  ---------------------------------
  0   DOMESTIC_AIRPORT  0.25°  All       CONUS/CAN/MEX near major airports
  1   DOMESTIC_ENROUTE  0.25°  10,000ft  CONUS/CAN/MEX enroute
  2   NAT_WATRS         0.25°  FL180     North Atlantic/WATRS oceanic
  3   PACIFIC_OCEANIC   0.25°  FL240     Oakland Oceanic (ZAK)
  4   INTL_AIRPORT      0.50°  All       International near airports
  5   EUROPE_ENROUTE    0.50°  10,000ft  Europe enroute
  6   PACIFIC_REMOTE    0.50°  FL180     Remote Pacific oceanic
  7   INTL_ENROUTE      0.50°  FL240     South America/Africa/Middle East
  8   REMOTE_AIRPORT    1.00°  10,000ft  Remote areas near airports
  9   ASIA_ENROUTE      1.00°  10,000ft  Asia/Middle East enroute
 10   POLAR_OCEANIC     1.00°  FL180     Polar/remote oceanic

Pressure Levels by Altitude:
    All levels:  150, 200, 250, 300, 400, 500, 600, 700, 850, 925 hPa
    AOA 10,000:  150, 200, 250, 300, 400, 500, 600, 700 hPa
    AOA FL180:   150, 200, 250, 300, 400, 500 hPa
    AOA FL240:   150, 200, 250, 300, 400 hPa

Airport Proximity:
    - 50nm radius around major airports
    - Uses precomputed lookup table for efficiency
    - ~100 major airports defined globally


COMMAND LINE OPTIONS
====================

Tier Selection:
    --tier=0,1,2,3     Fetch specific tiers (comma-separated)
    --all-tiers        Fetch all configured tiers
    (default)          Tiers 0-3 (domestic only)

Other Options:
    --debug            Enable verbose output

Examples:
    python fetch_noaa_gfs.py                    # Domestic tiers only
    python fetch_noaa_gfs.py --tier=0,4        # Near-airport tiers
    python fetch_noaa_gfs.py --all-tiers       # All tiers globally
    python fetch_noaa_gfs.py --tier=5,6 --debug # Europe + Pacific with debug


Database Connection
-------------------
Edit DB_CONFIG in fetch_noaa_gfs.py:
    DB_CONFIG = {
        'driver': '{ODBC Driver 18 for SQL Server}',
        'server': 'tcp:vatsim.database.windows.net,1433',
        'database': 'VATSIM_ADL',
        'username': 'adl_api_user',
        'password': '...',
        'encrypt': 'yes',
        'trust_cert': 'no'
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
