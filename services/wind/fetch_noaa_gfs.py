#!/usr/bin/env python3
"""
NOAA GFS Wind Fetch Service - Tiered Resolution

Fetches upper-level wind data directly from NOAA GFS using a tiered approach:
- Uses precomputed grid-tier lookup table from database
- Higher resolution near airports and domestic airspace
- Lower resolution for remote/oceanic areas

Requirements (use conda for Windows):
    conda install -c conda-forge cfgrib eccodes xarray requests pyodbc

Usage:
    python fetch_noaa_gfs.py [--debug] [--tier=0,1,2] [--all-tiers]

Schedule via Task Scheduler every 6 hours:
    0 2,8,14,20 * * * python /path/to/fetch_noaa_gfs.py --all-tiers

Data Source: NOAA NOMADS (https://nomads.ncep.noaa.gov/)
"""

import argparse
import datetime
import math
import os
import sys
import tempfile
from pathlib import Path
from collections import defaultdict

# Configuration
NOMADS_BASE = "https://nomads.ncep.noaa.gov/cgi-bin/filter_gfs_0p25.pl"
FORECAST_HOURS = [0, 6, 12, 24]

# Pressure levels by altitude floor
PRESSURE_LEVELS_BY_ALTITUDE = {
    0:     [150, 200, 250, 300, 400, 500, 600, 700, 850, 925],  # All levels
    10000: [150, 200, 250, 300, 400, 500, 600, 700],            # AOA 10,000ft
    18000: [150, 200, 250, 300, 400, 500],                       # AOA FL180
    24000: [150, 200, 250, 300, 400],                            # AOA FL240
}

# Database connection - Azure SQL
DB_CONFIG = {
    'driver': '{ODBC Driver 18 for SQL Server}',
    'server': 'tcp:vatsim.database.windows.net,1433',
    'database': 'VATSIM_ADL',
    'username': 'adl_api_user',
    'password': 'CAMRN@11000',
    'encrypt': 'yes',
    'trust_cert': 'no'
}


def check_dependencies():
    """Check and import required packages."""
    missing = []

    try:
        import xarray
    except ImportError:
        missing.append('xarray')

    try:
        import cfgrib
    except ImportError:
        missing.append('cfgrib')

    try:
        import requests
    except ImportError:
        missing.append('requests')

    try:
        import pyodbc
    except ImportError:
        missing.append('pyodbc')

    if missing:
        print("Missing required packages:")
        print(f"  conda install -c conda-forge {' '.join(missing)} eccodes")
        sys.exit(1)

    return True


def get_db_connection():
    """Create database connection."""
    import pyodbc
    conn_str = (
        f"DRIVER={DB_CONFIG['driver']};"
        f"SERVER={DB_CONFIG['server']};"
        f"DATABASE={DB_CONFIG['database']};"
        f"UID={DB_CONFIG['username']};"
        f"PWD={DB_CONFIG['password']};"
        f"Encrypt={DB_CONFIG['encrypt']};"
        f"TrustServerCertificate={DB_CONFIG['trust_cert']};"
    )
    return pyodbc.connect(conn_str)


def get_tier_config(conn):
    """Get tier configuration from database."""
    cursor = conn.cursor()
    cursor.execute("""
        SELECT tier, tier_name, resolution_deg, min_altitude_ft
        FROM dbo.wind_tier_config
        ORDER BY tier
    """)

    tiers = {}
    for row in cursor.fetchall():
        tiers[row.tier] = {
            'name': row.tier_name,
            'resolution': float(row.resolution_deg),
            'min_altitude': row.min_altitude_ft,
            'pressure_levels': PRESSURE_LEVELS_BY_ALTITUDE.get(row.min_altitude_ft, [200, 250, 300])
        }
    return tiers


def get_tier_grid_points(conn, tiers_to_fetch, debug=False):
    """Get grid points from lookup table, grouped by tier and region."""
    cursor = conn.cursor()

    # Get unique points per tier with their bounds
    tier_str = ','.join(str(t) for t in tiers_to_fetch)
    cursor.execute(f"""
        SELECT
            tier,
            MIN(lat) AS lat_min,
            MAX(lat) AS lat_max,
            MIN(lon) AS lon_min,
            MAX(lon) AS lon_max,
            COUNT(*) AS point_count
        FROM dbo.wind_grid_tier_lookup
        WHERE tier IN ({tier_str})
        GROUP BY tier
    """)

    tier_bounds = {}
    for row in cursor.fetchall():
        tier_bounds[row.tier] = {
            'lat_min': float(row.lat_min),
            'lat_max': float(row.lat_max),
            'lon_min': float(row.lon_min),
            'lon_max': float(row.lon_max),
            'point_count': row.point_count
        }
        if debug:
            print(f"  Tier {row.tier}: {row.point_count} points, "
                  f"bounds ({row.lat_min},{row.lon_min}) to ({row.lat_max},{row.lon_max})")

    return tier_bounds


def get_tier_points_for_region(conn, tier, lat_min, lat_max, lon_min, lon_max):
    """Get specific grid points for a tier within a bounding box."""
    cursor = conn.cursor()
    cursor.execute("""
        SELECT lat, lon
        FROM dbo.wind_grid_tier_lookup
        WHERE tier = ?
          AND lat BETWEEN ? AND ?
          AND lon BETWEEN ? AND ?
    """, (tier, lat_min, lat_max, lon_min, lon_max))

    return [(float(row.lat), float(row.lon)) for row in cursor.fetchall()]


def get_latest_gfs_cycle():
    """Get the most recent available GFS model run."""
    now = datetime.datetime.utcnow()
    # GFS is available ~3.5 hours after cycle time
    delay_hours = 4
    available = now - datetime.timedelta(hours=delay_hours)

    cycle_hour = (available.hour // 6) * 6
    cycle_date = available.date()

    if available.hour < cycle_hour:
        cycle_date -= datetime.timedelta(days=1)
        cycle_hour = 18

    return cycle_date.strftime("%Y%m%d"), f"{cycle_hour:02d}"


def download_gfs_grib(date, cycle, forecast_hour, lat_min, lat_max, lon_min, lon_max,
                      pressure_levels, debug=False):
    """Download GFS GRIB2 file for specific forecast hour and region."""
    import requests

    # Convert longitude to 0-360 if needed for NOMADS
    lon_min_360 = lon_min + 360 if lon_min < 0 else lon_min
    lon_max_360 = lon_max + 360 if lon_max < 0 else lon_max

    # Build level parameters
    level_params = '&'.join(f'lev_{lev}_mb=on' for lev in pressure_levels)

    url = (
        f"{NOMADS_BASE}?"
        f"file=gfs.t{cycle}z.pgrb2.0p25.f{forecast_hour:03d}&"
        f"{level_params}&"
        f"var_UGRD=on&var_VGRD=on&"
        f"subregion=&"
        f"leftlon={lon_min_360}&rightlon={lon_max_360}&"
        f"toplat={lat_max}&bottomlat={lat_min}&"
        f"dir=/gfs.{date}/{cycle}/atmos"
    )

    if debug:
        print(f"    URL: {url[:120]}...")

    response = requests.get(url, timeout=300)
    response.raise_for_status()

    # Save to temp file
    fd, temp_path = tempfile.mkstemp(suffix='.grib2')
    os.close(fd)

    with open(temp_path, 'wb') as f:
        f.write(response.content)

    return temp_path


def parse_grib_to_grid(grib_path, target_points, resolution, pressure_levels, debug=False):
    """Parse GRIB2 file and extract wind at target grid points."""
    import xarray as xr

    results = []
    target_set = set(target_points)  # For O(1) lookup

    try:
        # Open GRIB with cfgrib engine
        ds = xr.open_dataset(grib_path, engine='cfgrib',
                            backend_kwargs={'filter_by_keys': {'typeOfLevel': 'isobaricInhPa'}})

        # Get coordinate arrays
        lats = ds.latitude.values
        lons = ds.longitude.values

        # Calculate step factor based on resolution
        native_step = abs(lats[1] - lats[0]) if len(lats) > 1 else 0.25
        step_factor = max(1, int(resolution / native_step))

        # Sample at resolution intervals
        for i in range(0, len(lats), step_factor):
            lat = lats[i]
            for j in range(0, len(lons), step_factor):
                lon = lons[j]

                # Convert longitude from 0-360 to -180 to 180 for lookup
                std_lon = lon if lon <= 180 else lon - 360

                # Round to resolution for lookup matching
                lat_rounded = round(float(lat) / resolution) * resolution
                lon_rounded = round(float(std_lon) / resolution) * resolution

                # Skip if not in our target points
                if (round(lat_rounded, 2), round(lon_rounded, 2)) not in target_set:
                    continue

                # Get pressure levels available
                if 'isobaricInhPa' in ds.dims:
                    levels = ds.isobaricInhPa.values
                else:
                    levels = [ds.isobaricInhPa.values] if hasattr(ds, 'isobaricInhPa') else pressure_levels

                for level in levels:
                    if int(level) not in pressure_levels:
                        continue

                    try:
                        # Get U and V components
                        if 'isobaricInhPa' in ds.dims:
                            u = float(ds['u'].sel(latitude=lat, longitude=lon,
                                                  isobaricInhPa=level, method='nearest').values)
                            v = float(ds['v'].sel(latitude=lat, longitude=lon,
                                                  isobaricInhPa=level, method='nearest').values)
                        else:
                            u = float(ds['u'].sel(latitude=lat, longitude=lon, method='nearest').values)
                            v = float(ds['v'].sel(latitude=lat, longitude=lon, method='nearest').values)

                        # Convert m/s to knots
                        u_kts = u * 1.94384
                        v_kts = v * 1.94384

                        # Calculate speed and direction
                        speed_kts = math.sqrt(u_kts**2 + v_kts**2)
                        direction = (math.degrees(math.atan2(-u_kts, -v_kts)) + 360) % 360

                        results.append({
                            'lat': round(float(lat_rounded), 2),
                            'lon': round(float(lon_rounded), 2),
                            'pressure_hpa': int(level),
                            'wind_speed_kts': round(speed_kts, 1),
                            'wind_dir_deg': int(round(direction)),
                            'wind_u_kts': round(u_kts, 2),
                            'wind_v_kts': round(v_kts, 2)
                        })
                    except Exception as e:
                        continue

        ds.close()

    except Exception as e:
        print(f"    GRIB parse error: {e}")

    finally:
        # Cleanup temp file
        try:
            os.unlink(grib_path)
        except:
            pass

    return results


def parse_grib_full_region(grib_path, resolution, pressure_levels, debug=False):
    """Parse GRIB2 file and extract all wind points at given resolution using vectorized ops."""
    import xarray as xr
    import numpy as np

    results = []

    try:
        ds = xr.open_dataset(grib_path, engine='cfgrib',
                            backend_kwargs={'filter_by_keys': {'typeOfLevel': 'isobaricInhPa'}})

        lats = ds.latitude.values
        lons = ds.longitude.values

        # Calculate step for subsampling
        native_step = abs(lats[1] - lats[0]) if len(lats) > 1 else 0.25
        step_factor = max(1, int(resolution / native_step))

        # Subsample coordinates
        lat_subset = lats[::step_factor]
        lon_subset = lons[::step_factor]

        # Get available pressure levels
        if 'isobaricInhPa' in ds.dims:
            available_levels = ds.isobaricInhPa.values
        else:
            available_levels = [float(ds.isobaricInhPa.values)]

        # Filter to requested levels
        target_levels = [lv for lv in available_levels if int(lv) in pressure_levels]

        if debug:
            print(f"      Extracting {len(lat_subset)}x{len(lon_subset)}x{len(target_levels)} = "
                  f"{len(lat_subset)*len(lon_subset)*len(target_levels)} points")

        # Extract full arrays at once (vectorized - much faster)
        for level in target_levels:
            if 'isobaricInhPa' in ds.dims:
                u_data = ds['u'].sel(isobaricInhPa=level).values[::step_factor, ::step_factor]
                v_data = ds['v'].sel(isobaricInhPa=level).values[::step_factor, ::step_factor]
            else:
                u_data = ds['u'].values[::step_factor, ::step_factor]
                v_data = ds['v'].values[::step_factor, ::step_factor]

            # Vectorized conversion to knots
            u_kts = u_data * 1.94384
            v_kts = v_data * 1.94384

            # Vectorized speed and direction
            speed_kts = np.sqrt(u_kts**2 + v_kts**2)
            direction = (np.degrees(np.arctan2(-u_kts, -v_kts)) + 360) % 360

            # Build results
            for i, lat in enumerate(lat_subset):
                for j, lon in enumerate(lon_subset):
                    if np.isnan(u_kts[i, j]):
                        continue

                    std_lon = lon if lon <= 180 else lon - 360

                    results.append({
                        'lat': round(float(lat), 2),
                        'lon': round(float(std_lon), 2),
                        'pressure_hpa': int(level),
                        'wind_speed_kts': round(float(speed_kts[i, j]), 1),
                        'wind_dir_deg': int(round(direction[i, j])),
                        'wind_u_kts': round(float(u_kts[i, j]), 2),
                        'wind_v_kts': round(float(v_kts[i, j]), 2)
                    })

        ds.close()

    except Exception as e:
        print(f"    GRIB parse error: {e}")
        if debug:
            import traceback
            traceback.print_exc()

    finally:
        try:
            os.unlink(grib_path)
        except:
            pass

    return results


def insert_wind_data(conn, wind_data, tier, model_run_str, valid_time_str, forecast_hour, debug=False):
    """Insert wind data into database with tier using bulk operations."""
    cursor = conn.cursor()

    # Enable fast executemany for bulk inserts
    cursor.fast_executemany = True

    # Prepare batch data
    batch_data = [
        (
            point['lat'], point['lon'], point['pressure_hpa'], valid_time_str,
            point['wind_speed_kts'], point['wind_dir_deg'],
            point['wind_u_kts'], point['wind_v_kts'],
            forecast_hour, model_run_str, tier
        )
        for point in wind_data
    ]

    # Use temp table + MERGE for fast bulk upsert
    try:
        # Create temp table
        cursor.execute("""
            IF OBJECT_ID('tempdb..#wind_import') IS NOT NULL DROP TABLE #wind_import;
            CREATE TABLE #wind_import (
                lat DECIMAL(6,2), lon DECIMAL(7,2), pressure_hpa SMALLINT,
                valid_time_utc DATETIME2(0), wind_speed_kts DECIMAL(5,1),
                wind_dir_deg SMALLINT, wind_u_kts DECIMAL(6,2), wind_v_kts DECIMAL(6,2),
                forecast_hour TINYINT, model_run_utc DATETIME2(0), tier TINYINT
            );
        """)

        # Bulk insert into temp table
        cursor.executemany("""
            INSERT INTO #wind_import VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        """, batch_data)

        # Single MERGE from temp table
        cursor.execute("""
            MERGE dbo.wind_grid AS target
            USING #wind_import AS source
            ON target.lat = source.lat
               AND target.lon = source.lon
               AND target.pressure_hpa = source.pressure_hpa
               AND target.valid_time_utc = source.valid_time_utc
            WHEN MATCHED THEN
                UPDATE SET
                    wind_speed_kts = source.wind_speed_kts,
                    wind_dir_deg = source.wind_dir_deg,
                    wind_u_kts = source.wind_u_kts,
                    wind_v_kts = source.wind_v_kts,
                    forecast_hour = source.forecast_hour,
                    model_run_utc = source.model_run_utc,
                    tier = source.tier,
                    fetched_utc = SYSUTCDATETIME()
            WHEN NOT MATCHED THEN
                INSERT (lat, lon, pressure_hpa, wind_speed_kts, wind_dir_deg,
                        wind_u_kts, wind_v_kts, forecast_hour, model_run_utc, valid_time_utc, tier)
                VALUES (source.lat, source.lon, source.pressure_hpa, source.wind_speed_kts,
                        source.wind_dir_deg, source.wind_u_kts, source.wind_v_kts,
                        source.forecast_hour, source.model_run_utc, source.valid_time_utc, source.tier);
        """)

        conn.commit()
        return len(batch_data)

    except Exception as e:
        if debug:
            print(f"      Bulk insert error: {e}")
        conn.rollback()
        return 0


def main():
    parser = argparse.ArgumentParser(description='Fetch NOAA GFS wind data with tiered resolution')
    parser.add_argument('--debug', action='store_true', help='Enable debug output')
    parser.add_argument('--tier', type=str, default=None,
                        help='Comma-separated list of tiers to fetch (e.g., 0,1,2)')
    parser.add_argument('--all-tiers', action='store_true',
                        help='Fetch all configured tiers')
    parser.add_argument('--region', type=str, default=None,
                        help='Legacy: specific region to fetch (CONUS, etc.)')
    args = parser.parse_args()

    print("=" * 60)
    print("  NOAA GFS Wind Fetch - Tiered Resolution")
    print("=" * 60)

    # Check dependencies
    check_dependencies()

    # Connect to database
    try:
        conn = get_db_connection()
        print("Database: Connected")
    except Exception as e:
        print(f"Database connection failed: {e}")
        print("Check DB_CONFIG settings in script")
        sys.exit(1)

    # Get tier configuration
    tier_config = get_tier_config(conn)
    if not tier_config:
        print("ERROR: No tier configuration found. Run 003_wind_tiered_resolution.sql first.")
        sys.exit(1)

    print(f"Loaded {len(tier_config)} tier configurations")

    # Determine which tiers to fetch
    if args.all_tiers:
        tiers_to_fetch = list(tier_config.keys())
    elif args.tier:
        tiers_to_fetch = [int(t.strip()) for t in args.tier.split(',')]
    else:
        # Default: fetch domestic tiers (0, 1, 2, 3)
        tiers_to_fetch = [0, 1, 2, 3]

    print(f"Fetching tiers: {tiers_to_fetch}")

    # Get latest GFS cycle
    date, cycle = get_latest_gfs_cycle()
    model_run = datetime.datetime.strptime(f"{date}{cycle}", "%Y%m%d%H")

    print(f"Model Run: {date} {cycle}Z")
    print()

    # Get tier bounds from lookup table
    tier_bounds = get_tier_grid_points(conn, tiers_to_fetch, args.debug)

    if not tier_bounds:
        print("ERROR: No grid points found in lookup table.")
        print("Run: EXEC dbo.sp_BuildWindGridTierLookup @debug = 1")
        sys.exit(1)

    total_inserted = 0

    for tier in tiers_to_fetch:
        if tier not in tier_bounds:
            print(f"\nTier {tier}: No grid points in lookup table - skipping")
            continue

        config = tier_config.get(tier)
        if not config:
            print(f"\nTier {tier}: No configuration found - skipping")
            continue

        bounds = tier_bounds[tier]
        print(f"\n{'='*60}")
        print(f"Tier {tier}: {config['name']}")
        print(f"  Resolution: {config['resolution']}°")
        print(f"  Min altitude: {config['min_altitude']} ft")
        print(f"  Pressure levels: {config['pressure_levels']}")
        print(f"  Grid points: {bounds['point_count']}")
        print(f"  Bounds: ({bounds['lat_min']}, {bounds['lon_min']}) to ({bounds['lat_max']}, {bounds['lon_max']})")

        for fh in FORECAST_HOURS:
            valid_time = model_run + datetime.timedelta(hours=fh)
            print(f"\n  Forecast +{fh:02d}h (valid: {valid_time.strftime('%Y-%m-%d %H:%MZ')})")

            try:
                # Download GRIB for this tier's bounding box
                print("    Downloading from NOMADS...")
                grib_path = download_gfs_grib(
                    date, cycle, fh,
                    bounds['lat_min'], bounds['lat_max'],
                    bounds['lon_min'], bounds['lon_max'],
                    config['pressure_levels'],
                    args.debug
                )
                print(f"    Downloaded: {os.path.getsize(grib_path) / 1024:.0f} KB")

                # Parse at this tier's resolution
                print("    Parsing GRIB2...")
                wind_data = parse_grib_full_region(
                    grib_path,
                    config['resolution'],
                    config['pressure_levels'],
                    args.debug
                )
                print(f"    Extracted: {len(wind_data)} grid points")

                # Insert to database
                if wind_data:
                    inserted = insert_wind_data(
                        conn, wind_data, tier,
                        model_run.strftime("%Y-%m-%d %H:%M:%S"),
                        valid_time.strftime("%Y-%m-%d %H:%M:%S"),
                        fh, args.debug
                    )
                    print(f"    Inserted: {inserted} records")
                    total_inserted += inserted

            except Exception as e:
                print(f"    ERROR: {e}")
                if args.debug:
                    import traceback
                    traceback.print_exc()

    conn.close()

    print()
    print("=" * 60)
    print(f"  Total records: {total_inserted}")
    print("=" * 60)


if __name__ == '__main__':
    main()
