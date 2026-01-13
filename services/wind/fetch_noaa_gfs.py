#!/usr/bin/env python3
"""
NOAA GFS Wind Fetch Service

Fetches upper-level wind data directly from NOAA GFS.
- FREE and UNLIMITED
- 0.25° native resolution
- Global coverage

Requirements:
    pip install cfgrib xarray requests pyodbc ecmwflibs

Usage:
    python fetch_noaa_gfs.py [--debug] [--region=CONUS] [--grid-step=0.5]

Schedule via Task Scheduler or cron every 6 hours:
    0 2,8,14,20 * * * python /path/to/fetch_noaa_gfs.py --region=CONUS

Data Source: NOAA NOMADS (https://nomads.ncep.noaa.gov/)
"""

import argparse
import datetime
import math
import os
import sys
import tempfile
from pathlib import Path

# Configuration
NOMADS_BASE = "https://nomads.ncep.noaa.gov/cgi-bin/filter_gfs_0p25.pl"
PRESSURE_LEVELS = [200, 250, 300, 400, 500]  # hPa (FL390, FL340, FL300, FL240, FL180)
FORECAST_HOURS = [0, 6, 12, 24]

# Region definitions (lat_min, lat_max, lon_min, lon_max)
REGIONS = {
    'CONUS': (20, 55, -130, -60),
    'NORTH_ATLANTIC': (25, 65, -80, 0),
    'EUROPE': (30, 65, -15, 40),
    'PACIFIC': (15, 55, 120, 240),  # Using 0-360 for Pacific
    'ALL': None  # Fetch all defined regions
}

# Database connection - adjust for your environment
DB_CONFIG = {
    'driver': '{ODBC Driver 17 for SQL Server}',
    'server': 'localhost',
    'database': 'PERTI',
    'trusted_connection': 'yes'
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
        print(f"  pip install {' '.join(missing)} ecmwflibs")
        sys.exit(1)

    return True


def get_db_connection():
    """Create database connection."""
    import pyodbc
    conn_str = (
        f"DRIVER={DB_CONFIG['driver']};"
        f"SERVER={DB_CONFIG['server']};"
        f"DATABASE={DB_CONFIG['database']};"
        f"Trusted_Connection={DB_CONFIG['trusted_connection']};"
    )
    return pyodbc.connect(conn_str)


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


def download_gfs_grib(date, cycle, forecast_hour, region_bounds, debug=False):
    """Download GFS GRIB2 file for specific forecast hour."""
    import requests

    lat_min, lat_max, lon_min, lon_max = region_bounds

    # Convert longitude to 0-360 if needed
    if lon_min < 0:
        lon_min += 360
    if lon_max < 0:
        lon_max += 360

    # Build level parameters
    level_params = '&'.join(f'lev_{lev}_mb=on' for lev in PRESSURE_LEVELS)

    url = (
        f"{NOMADS_BASE}?"
        f"file=gfs.t{cycle}z.pgrb2.0p25.f{forecast_hour:03d}&"
        f"{level_params}&"
        f"var_UGRD=on&var_VGRD=on&"
        f"subregion=&"
        f"leftlon={lon_min}&rightlon={lon_max}&"
        f"toplat={lat_max}&bottomlat={lat_min}&"
        f"dir=/gfs.{date}/{cycle}/atmos"
    )

    if debug:
        print(f"  URL: {url[:100]}...")

    response = requests.get(url, timeout=300)
    response.raise_for_status()

    # Save to temp file
    fd, temp_path = tempfile.mkstemp(suffix='.grib2')
    os.close(fd)

    with open(temp_path, 'wb') as f:
        f.write(response.content)

    return temp_path


def parse_grib_to_grid(grib_path, grid_step=0.5, debug=False):
    """Parse GRIB2 file and extract wind grid points."""
    import xarray as xr

    results = []

    try:
        # Open GRIB with cfgrib engine
        ds = xr.open_dataset(grib_path, engine='cfgrib',
                            backend_kwargs={'filter_by_keys': {'typeOfLevel': 'isobaricInhPa'}})

        # Get coordinate arrays
        lats = ds.latitude.values
        lons = ds.longitude.values

        # Determine resampling
        native_step = abs(lats[1] - lats[0]) if len(lats) > 1 else 0.25
        step_factor = max(1, int(grid_step / native_step))

        # Sample at grid_step intervals
        for i, lat in enumerate(lats[::step_factor]):
            for j, lon in enumerate(lons[::step_factor]):
                # Get pressure levels available
                if 'isobaricInhPa' in ds.dims:
                    levels = ds.isobaricInhPa.values
                else:
                    levels = [ds.isobaricInhPa.values] if hasattr(ds, 'isobaricInhPa') else PRESSURE_LEVELS

                for level in levels:
                    if int(level) not in PRESSURE_LEVELS:
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

                        # Convert longitude from 0-360 to -180 to 180
                        std_lon = lon if lon <= 180 else lon - 360

                        results.append({
                            'lat': round(float(lat), 2),
                            'lon': round(float(std_lon), 2),
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
        print(f"  GRIB parse error: {e}")

    finally:
        # Cleanup temp file
        try:
            os.unlink(grib_path)
        except:
            pass

    return results


def insert_wind_data(conn, wind_data, model_run_str, valid_time_str, forecast_hour, debug=False):
    """Insert wind data into database."""
    cursor = conn.cursor()
    inserted = 0

    for point in wind_data:
        try:
            cursor.execute("""
                MERGE dbo.wind_grid AS target
                USING (SELECT ? AS lat, ? AS lon, ? AS pressure_hpa, ? AS valid_time_utc) AS source
                ON target.lat = source.lat
                   AND target.lon = source.lon
                   AND target.pressure_hpa = source.pressure_hpa
                   AND target.valid_time_utc = source.valid_time_utc
                WHEN MATCHED THEN
                    UPDATE SET
                        wind_speed_kts = ?,
                        wind_dir_deg = ?,
                        wind_u_kts = ?,
                        wind_v_kts = ?,
                        forecast_hour = ?,
                        model_run_utc = ?,
                        fetched_utc = SYSUTCDATETIME()
                WHEN NOT MATCHED THEN
                    INSERT (lat, lon, pressure_hpa, wind_speed_kts, wind_dir_deg,
                            wind_u_kts, wind_v_kts, forecast_hour, model_run_utc, valid_time_utc)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?);
            """, (
                point['lat'], point['lon'], point['pressure_hpa'], valid_time_str,
                point['wind_speed_kts'], point['wind_dir_deg'],
                point['wind_u_kts'], point['wind_v_kts'],
                forecast_hour, model_run_str,
                point['lat'], point['lon'], point['pressure_hpa'],
                point['wind_speed_kts'], point['wind_dir_deg'],
                point['wind_u_kts'], point['wind_v_kts'],
                forecast_hour, model_run_str, valid_time_str
            ))
            inserted += 1
        except Exception as e:
            if debug:
                print(f"    Insert error: {e}")

    conn.commit()
    return inserted


def main():
    parser = argparse.ArgumentParser(description='Fetch NOAA GFS wind data (FREE, UNLIMITED)')
    parser.add_argument('--debug', action='store_true', help='Enable debug output')
    parser.add_argument('--region', default='CONUS', choices=list(REGIONS.keys()),
                        help='Region to fetch (default: CONUS)')
    parser.add_argument('--grid-step', type=float, default=0.5,
                        help='Grid resolution in degrees (default: 0.5)')
    args = parser.parse_args()

    print("=" * 60)
    print("  NOAA GFS Wind Fetch - FREE & UNLIMITED")
    print("=" * 60)

    # Check dependencies
    check_dependencies()

    # Get latest GFS cycle
    date, cycle = get_latest_gfs_cycle()
    model_run = datetime.datetime.strptime(f"{date}{cycle}", "%Y%m%d%H")

    print(f"Model Run: {date} {cycle}Z")
    print(f"Region: {args.region}")
    print(f"Grid Step: {args.grid_step}°")
    print(f"Pressure Levels: {PRESSURE_LEVELS}")
    print()

    # Determine regions to process
    if args.region == 'ALL':
        regions_to_fetch = {k: v for k, v in REGIONS.items() if v is not None}
    else:
        regions_to_fetch = {args.region: REGIONS[args.region]}

    # Connect to database
    try:
        conn = get_db_connection()
        print("Database: Connected")
    except Exception as e:
        print(f"Database connection failed: {e}")
        print("Check DB_CONFIG settings in script")
        sys.exit(1)

    total_inserted = 0

    for region_name, bounds in regions_to_fetch.items():
        print(f"\n--- Region: {region_name} ---")

        for fh in FORECAST_HOURS:
            valid_time = model_run + datetime.timedelta(hours=fh)
            print(f"\nForecast +{fh:02d}h (valid: {valid_time.strftime('%Y-%m-%d %H:%MZ')})")

            try:
                # Download GRIB
                print("  Downloading from NOMADS...")
                grib_path = download_gfs_grib(date, cycle, fh, bounds, args.debug)
                print(f"  Downloaded: {os.path.getsize(grib_path) / 1024:.0f} KB")

                # Parse to grid
                print("  Parsing GRIB2...")
                wind_data = parse_grib_to_grid(grib_path, args.grid_step, args.debug)
                print(f"  Extracted: {len(wind_data)} grid points")

                # Insert to database
                if wind_data:
                    inserted = insert_wind_data(
                        conn, wind_data,
                        model_run.strftime("%Y-%m-%d %H:%M:%S"),
                        valid_time.strftime("%Y-%m-%d %H:%M:%S"),
                        fh, args.debug
                    )
                    print(f"  Inserted: {inserted} records")
                    total_inserted += inserted

            except Exception as e:
                print(f"  ERROR: {e}")
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
