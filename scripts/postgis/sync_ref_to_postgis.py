#!/usr/bin/env python3
"""
Sync reference data from VATSIM_REF (Azure SQL) to VATSIM_GIS (PostGIS).

Syncs the navigation reference tables that PostGIS route expansion functions
depend on: nav_fixes, airways, airway_segments, area_centers.

Usage:
    python sync_ref_to_postgis.py                    # Full sync (all tables)
    python sync_ref_to_postgis.py --table airways    # Single table
    python sync_ref_to_postgis.py --dry-run          # Preview without changes

Requirements:
    pip install pyodbc psycopg2-binary
"""

import os
import sys
import argparse
import time
from datetime import datetime
from typing import List, Dict, Optional

try:
    import pyodbc
except ImportError:
    print("Error: pyodbc not installed. Run: pip install pyodbc")
    sys.exit(1)

try:
    import psycopg2
    from psycopg2.extras import execute_values
except ImportError:
    print("Error: psycopg2 not installed. Run: pip install psycopg2-binary")
    sys.exit(1)


# ==============================================================================
# Configuration
# ==============================================================================

# Azure SQL (VATSIM_REF)
REF_SERVER = "vatsim.database.windows.net"
REF_DATABASE = "VATSIM_REF"
REF_USER = os.environ.get("ADL_SQL_USER", "adl_api_user")
REF_PASS = os.environ.get("ADL_SQL_PASSWORD", "")

# PostGIS (VATSIM_GIS)
GIS_HOST = os.environ.get("GIS_SQL_HOST", "localhost")
GIS_PORT = os.environ.get("GIS_SQL_PORT", "5432")
GIS_DATABASE = os.environ.get("GIS_SQL_DATABASE", "VATSIM_GIS")
GIS_USER = os.environ.get("GIS_SQL_USERNAME", "GIS_admin")
GIS_PASS = os.environ.get("GIS_SQL_PASSWORD", "")

BATCH_SIZE = 5000


# ==============================================================================
# Database Connections
# ==============================================================================

def get_ref_connection():
    """Connect to VATSIM_REF (Azure SQL)."""
    conn_str = (
        f"DRIVER={{ODBC Driver 18 for SQL Server}};"
        f"SERVER={REF_SERVER};"
        f"DATABASE={REF_DATABASE};"
        f"UID={REF_USER};"
        f"PWD={REF_PASS};"
        f"Encrypt=yes;"
        f"TrustServerCertificate=no;"
        f"LoginTimeout=30;"
    )
    return pyodbc.connect(conn_str)


def get_gis_connection():
    """Connect to VATSIM_GIS (PostGIS)."""
    return psycopg2.connect(
        host=GIS_HOST,
        port=int(GIS_PORT),
        database=GIS_DATABASE,
        user=GIS_USER,
        password=GIS_PASS,
    )


# ==============================================================================
# Sync Functions
# ==============================================================================

def sync_nav_fixes(cursor_ref, cursor_gis, conn_gis, dry_run=False):
    """Sync nav_fixes from REF to PostGIS."""
    print("\n  Syncing nav_fixes...")

    cursor_ref.execute(
        "SELECT fix_id, fix_name, fix_type, lat, lon, artcc_id "
        "FROM dbo.nav_fixes"
    )
    rows = cursor_ref.fetchall()
    print(f"    Read {len(rows):,} rows from VATSIM_REF")

    if dry_run:
        print("    [DRY RUN] Would sync to PostGIS")
        return len(rows)

    cursor_gis.execute("DELETE FROM nav_fixes")

    inserted = 0
    batch = []
    for row in rows:
        fix_id, fix_name, fix_type, lat, lon, artcc_id = row
        if lat is None or lon is None:
            continue
        batch.append((fix_id, fix_name, fix_type, float(lat), float(lon), artcc_id))

        if len(batch) >= BATCH_SIZE:
            execute_values(
                cursor_gis,
                "INSERT INTO nav_fixes (fix_id, fix_name, fix_type, lat, lon, artcc_id) "
                "VALUES %s",
                batch,
            )
            inserted += len(batch)
            batch = []

    if batch:
        execute_values(
            cursor_gis,
            "INSERT INTO nav_fixes (fix_id, fix_name, fix_type, lat, lon, artcc_id) "
            "VALUES %s",
            batch,
        )
        inserted += len(batch)

    conn_gis.commit()
    print(f"    Inserted {inserted:,} rows into PostGIS")
    return inserted


def sync_airways(cursor_ref, cursor_gis, conn_gis, dry_run=False):
    """Sync airways from REF to PostGIS. Clears airway_segments first (FK)."""
    print("\n  Syncing airways (+ airway_segments)...")

    # Read airways
    cursor_ref.execute(
        "SELECT airway_id, airway_name, airway_type, fix_sequence, fix_count, "
        "start_fix, end_fix, source "
        "FROM dbo.airways"
    )
    airway_rows = cursor_ref.fetchall()
    print(f"    Read {len(airway_rows):,} airways from VATSIM_REF")

    # Read segments
    cursor_ref.execute(
        "SELECT airway_id, airway_name, sequence_num, from_fix, to_fix, "
        "from_lat, from_lon, to_lat, to_lon, distance_nm "
        "FROM dbo.airway_segments"
    )
    segment_rows = cursor_ref.fetchall()
    print(f"    Read {len(segment_rows):,} segments from VATSIM_REF")

    if dry_run:
        print("    [DRY RUN] Would sync to PostGIS")
        return len(airway_rows), len(segment_rows)

    # Clear in FK order
    cursor_gis.execute("DELETE FROM airway_segments")
    cursor_gis.execute("DELETE FROM airways")
    conn_gis.commit()

    # Insert airways
    inserted_awy = 0
    batch = []
    for row in airway_rows:
        airway_id, name, atype, fix_seq, fix_count, start_fix, end_fix, source = row
        batch.append((airway_id, name, atype, fix_seq, fix_count, start_fix, end_fix, source))

        if len(batch) >= BATCH_SIZE:
            execute_values(
                cursor_gis,
                "INSERT INTO airways (airway_id, airway_name, airway_type, fix_sequence, "
                "fix_count, start_fix, end_fix, source) VALUES %s",
                batch,
            )
            inserted_awy += len(batch)
            batch = []

    if batch:
        execute_values(
            cursor_gis,
            "INSERT INTO airways (airway_id, airway_name, airway_type, fix_sequence, "
            "fix_count, start_fix, end_fix, source) VALUES %s",
            batch,
        )
        inserted_awy += len(batch)

    conn_gis.commit()
    print(f"    Inserted {inserted_awy:,} airways")

    # Insert segments
    inserted_seg = 0
    batch = []
    for row in segment_rows:
        airway_id, name, seq, from_fix, to_fix, from_lat, from_lon, to_lat, to_lon, dist = row
        if from_lat is None or to_lat is None:
            continue
        batch.append((
            airway_id, name, seq, from_fix, to_fix,
            float(from_lat), float(from_lon), float(to_lat), float(to_lon),
            float(dist) if dist else None,
        ))

        if len(batch) >= BATCH_SIZE:
            execute_values(
                cursor_gis,
                "INSERT INTO airway_segments (airway_id, airway_name, sequence_num, "
                "from_fix, to_fix, from_lat, from_lon, to_lat, to_lon, distance_nm) "
                "VALUES %s",
                batch,
            )
            inserted_seg += len(batch)
            batch = []

    if batch:
        execute_values(
            cursor_gis,
            "INSERT INTO airway_segments (airway_id, airway_name, sequence_num, "
            "from_fix, to_fix, from_lat, from_lon, to_lat, to_lon, distance_nm) "
            "VALUES %s",
            batch,
        )
        inserted_seg += len(batch)

    conn_gis.commit()
    print(f"    Inserted {inserted_seg:,} segments")

    return inserted_awy, inserted_seg


def sync_area_centers(cursor_ref, cursor_gis, conn_gis, dry_run=False):
    """Sync area_centers from REF to PostGIS."""
    print("\n  Syncing area_centers...")

    cursor_ref.execute(
        "SELECT center_id, center_code, center_type, center_name, lat, lon, parent_artcc "
        "FROM dbo.area_centers"
    )
    rows = cursor_ref.fetchall()
    print(f"    Read {len(rows):,} rows from VATSIM_REF")

    if dry_run:
        print("    [DRY RUN] Would sync to PostGIS")
        return len(rows)

    cursor_gis.execute("DELETE FROM area_centers")

    batch = []
    for row in rows:
        center_id, code, ctype, name, lat, lon, parent = row
        if lat is None or lon is None:
            continue
        batch.append((center_id, code, ctype, name, float(lat), float(lon), parent))

    if batch:
        execute_values(
            cursor_gis,
            "INSERT INTO area_centers (center_id, center_code, center_type, "
            "center_name, lat, lon, parent_artcc) VALUES %s",
            batch,
        )

    conn_gis.commit()
    inserted = len(batch)
    print(f"    Inserted {inserted:,} rows into PostGIS")
    return inserted


# ==============================================================================
# Main
# ==============================================================================

def main():
    parser = argparse.ArgumentParser(
        description="Sync VATSIM_REF reference data to PostGIS (VATSIM_GIS)"
    )
    parser.add_argument(
        "--table",
        choices=["nav_fixes", "airways", "area_centers", "all"],
        default="all",
        help="Table to sync (default: all). 'airways' includes airway_segments.",
    )
    parser.add_argument(
        "--dry-run",
        action="store_true",
        help="Preview without making changes",
    )
    args = parser.parse_args()

    print("=" * 60)
    print("  Sync: VATSIM_REF -> VATSIM_GIS (PostGIS)")
    print("=" * 60)
    print(f"  Started: {datetime.now().strftime('%Y-%m-%d %H:%M:%S')}")
    if args.dry_run:
        print("  Mode: DRY RUN")
    print()

    start = time.time()

    # Connect
    print("  Connecting to VATSIM_REF (Azure SQL)...")
    conn_ref = get_ref_connection()
    cursor_ref = conn_ref.cursor()
    print("  Connected")

    print("  Connecting to VATSIM_GIS (PostGIS)...")
    conn_gis = get_gis_connection()
    cursor_gis = conn_gis.cursor()
    print("  Connected")

    results = {}
    tables = [args.table] if args.table != "all" else ["nav_fixes", "airways", "area_centers"]

    try:
        if "nav_fixes" in tables:
            results["nav_fixes"] = sync_nav_fixes(
                cursor_ref, cursor_gis, conn_gis, args.dry_run
            )

        if "airways" in tables:
            awy, seg = sync_airways(
                cursor_ref, cursor_gis, conn_gis, args.dry_run
            )
            results["airways"] = awy
            results["airway_segments"] = seg

        if "area_centers" in tables:
            results["area_centers"] = sync_area_centers(
                cursor_ref, cursor_gis, conn_gis, args.dry_run
            )

    except Exception as e:
        print(f"\n  FATAL ERROR: {e}")
        conn_gis.rollback()
        conn_ref.close()
        conn_gis.close()
        sys.exit(1)

    conn_ref.close()
    conn_gis.close()

    elapsed = time.time() - start

    print("\n" + "=" * 60)
    print("  Sync Complete")
    print("=" * 60)
    for table, count in results.items():
        if isinstance(count, tuple):
            print(f"    {table}: {count[0]:,} / {count[1]:,} rows")
        else:
            print(f"    {table}: {count:,} rows")
    print(f"  Duration: {elapsed:.1f}s")
    print(f"  Finished: {datetime.now().strftime('%Y-%m-%d %H:%M:%S')}")
    print("=" * 60)


if __name__ == "__main__":
    main()
