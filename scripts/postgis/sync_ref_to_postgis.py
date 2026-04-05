#!/usr/bin/env python3
"""
Sync reference data from VATSIM_REF (Azure SQL) to VATSIM_GIS (PostGIS).

Syncs all navigation reference tables that PostGIS route expansion functions
depend on: nav_fixes, airways, airway_segments, area_centers,
coded_departure_routes, nav_procedures, playbook_routes.

Usage:
    python sync_ref_to_postgis.py                              # Full sync (all tables)
    python sync_ref_to_postgis.py --table airways              # Single table
    python sync_ref_to_postgis.py --table nav_procedures       # UPSERT (preserves CIFP)
    python sync_ref_to_postgis.py --dry-run                    # Preview without changes

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
        sslmode='require',
    )


# ==============================================================================
# Sync Functions
# ==============================================================================

def sync_nav_fixes(cursor_ref, cursor_gis, conn_gis, dry_run=False):
    """Sync nav_fixes from REF to PostGIS (full replace)."""
    print("\n  Syncing nav_fixes...")

    cursor_ref.execute(
        "SELECT fix_id, fix_name, fix_type, lat, lon, artcc_id, "
        "is_superseded, superseded_cycle, superseded_reason "
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
        fix_id, fix_name, fix_type, lat, lon, artcc_id, is_sup, sup_cycle, sup_reason = row
        if lat is None or lon is None:
            continue
        batch.append((fix_id, fix_name, fix_type, float(lat), float(lon), artcc_id,
                      bool(is_sup) if is_sup is not None else False,
                      sup_cycle, sup_reason))

        if len(batch) >= BATCH_SIZE:
            execute_values(
                cursor_gis,
                "INSERT INTO nav_fixes (fix_id, fix_name, fix_type, lat, lon, artcc_id, "
                "is_superseded, superseded_cycle, superseded_reason) VALUES %s",
                batch,
            )
            inserted += len(batch)
            batch = []

    if batch:
        execute_values(
            cursor_gis,
            "INSERT INTO nav_fixes (fix_id, fix_name, fix_type, lat, lon, artcc_id, "
            "is_superseded, superseded_cycle, superseded_reason) VALUES %s",
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
        "start_fix, end_fix, source, "
        "is_superseded, superseded_cycle, superseded_reason "
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
        (airway_id, name, atype, fix_seq, fix_count, start_fix, end_fix, source,
         is_sup, sup_cycle, sup_reason) = row
        batch.append((airway_id, name, atype, fix_seq, fix_count, start_fix, end_fix, source,
                      bool(is_sup) if is_sup is not None else False,
                      sup_cycle, sup_reason))

        if len(batch) >= BATCH_SIZE:
            execute_values(
                cursor_gis,
                "INSERT INTO airways (airway_id, airway_name, airway_type, fix_sequence, "
                "fix_count, start_fix, end_fix, source, "
                "is_superseded, superseded_cycle, superseded_reason) VALUES %s",
                batch,
            )
            inserted_awy += len(batch)
            batch = []

    if batch:
        execute_values(
            cursor_gis,
            "INSERT INTO airways (airway_id, airway_name, airway_type, fix_sequence, "
            "fix_count, start_fix, end_fix, source, "
            "is_superseded, superseded_cycle, superseded_reason) VALUES %s",
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


def sync_coded_departure_routes(cursor_ref, cursor_gis, conn_gis, dry_run=False):
    """Sync coded_departure_routes from REF to PostGIS (full replace)."""
    print("\n  Syncing coded_departure_routes...")

    cursor_ref.execute(
        "SELECT cdr_id, cdr_code, full_route, origin_icao, dest_icao, direction, "
        "altitude_min_ft, altitude_max_ft, is_active, source, effective_date, "
        "is_superseded, superseded_cycle, superseded_reason "
        "FROM dbo.coded_departure_routes"
    )
    rows = cursor_ref.fetchall()
    print(f"    Read {len(rows):,} rows from VATSIM_REF")

    if dry_run:
        print("    [DRY RUN] Would sync to PostGIS")
        return len(rows)

    cursor_gis.execute("DELETE FROM coded_departure_routes")

    inserted = 0
    batch = []
    for row in rows:
        (cdr_id, cdr_code, full_route, origin, dest, direction,
         alt_min, alt_max, is_active, source, eff_date,
         is_sup, sup_cycle, sup_reason) = row
        batch.append((
            cdr_id, cdr_code, full_route, origin, dest, direction,
            alt_min, alt_max,
            bool(is_active) if is_active is not None else True,
            source,
            eff_date,
            bool(is_sup) if is_sup is not None else False,
            sup_cycle, sup_reason
        ))

        if len(batch) >= BATCH_SIZE:
            execute_values(
                cursor_gis,
                "INSERT INTO coded_departure_routes "
                "(cdr_id, cdr_code, full_route, origin_icao, dest_icao, direction, "
                "altitude_min_ft, altitude_max_ft, is_active, source, effective_date, "
                "is_superseded, superseded_cycle, superseded_reason) VALUES %s",
                batch,
            )
            inserted += len(batch)
            batch = []

    if batch:
        execute_values(
            cursor_gis,
            "INSERT INTO coded_departure_routes "
            "(cdr_id, cdr_code, full_route, origin_icao, dest_icao, direction, "
            "altitude_min_ft, altitude_max_ft, is_active, source, effective_date, "
            "is_superseded, superseded_cycle, superseded_reason) VALUES %s",
            batch,
        )
        inserted += len(batch)

    conn_gis.commit()
    print(f"    Inserted {inserted:,} rows into PostGIS")
    return inserted


def sync_nav_procedures(cursor_ref, cursor_gis, conn_gis, dry_run=False):
    """Sync nav_procedures from REF to PostGIS using UPSERT strategy.

    The NASR pipeline now generates properly transition-separated records
    for both US (NASR source) and international (CIFP source) airports.
    We delete all records except cifp_base and synthetic_base (which are
    generated separately by generate_base_transitions.py), then re-insert
    from VATSIM_REF.
    """
    print("\n  Syncing nav_procedures (UPSERT - preserving cifp_base/synthetic_base)...")

    # Ensure new columns exist (migrations 021, 022)
    for col_ddl in [
        "ALTER TABLE nav_procedures ADD COLUMN IF NOT EXISTS transition_type VARCHAR(10)",
        "ALTER TABLE nav_procedures ADD COLUMN IF NOT EXISTS body_name VARCHAR(64)",
        "ALTER TABLE nav_procedures ADD COLUMN IF NOT EXISTS runway_group TEXT",
    ]:
        cursor_gis.execute(col_ddl)
    conn_gis.commit()

    cursor_ref.execute(
        "SELECT procedure_id, procedure_type, airport_icao, procedure_name, "
        "computer_code, transition_name, transition_type, full_route, runways, "
        "body_name, runway_group, is_active, "
        "source, effective_date, "
        "is_superseded, superseded_cycle, superseded_reason "
        "FROM dbo.nav_procedures"
    )
    rows = cursor_ref.fetchall()
    print(f"    Read {len(rows):,} procedures from VATSIM_REF")

    if dry_run:
        # Check existing counts
        cursor_gis.execute("SELECT COUNT(*) FROM nav_procedures")
        total = cursor_gis.fetchone()[0]
        cursor_gis.execute("SELECT COUNT(*) FROM nav_procedures WHERE source IN ('NASR', 'nasr')")
        nasr = cursor_gis.fetchone()[0]
        print(f"    [DRY RUN] PostGIS has {total:,} total ({nasr:,} NASR, {total - nasr:,} CIFP)")
        print(f"    [DRY RUN] Would delete {nasr:,} NASR and insert {len(rows):,}")
        return len(rows)

    # Count before
    cursor_gis.execute("SELECT COUNT(*) FROM nav_procedures")
    before_total = cursor_gis.fetchone()[0]

    # Delete all records except cifp_base and synthetic_base (generated separately).
    # The old 'CIFP' source records were corrupted (concatenated all transitions);
    # the NASR pipeline now properly handles international procedures via CIFP parsing.
    cursor_gis.execute(
        "DELETE FROM nav_procedures WHERE source NOT IN ('cifp_base', 'synthetic_base') "
        "OR source IS NULL"
    )
    deleted = cursor_gis.rowcount
    conn_gis.commit()
    print(f"    Deleted {deleted:,} records (preserved cifp_base + synthetic_base)")

    # Insert REF records (omit procedure_id — let PostGIS auto-assign to avoid
    # PK conflicts with existing CIFP records that use procedure_id 1..97K)
    inserted = 0
    batch = []
    insert_sql = (
        "INSERT INTO nav_procedures "
        "(procedure_type, airport_icao, procedure_name, "
        "computer_code, transition_name, transition_type, full_route, runways, "
        "body_name, runway_group, is_active, "
        "source, effective_date, "
        "is_superseded, superseded_cycle, superseded_reason) VALUES %s"
    )
    for row in rows:
        (proc_id, ptype, airport, pname, code, trans, trans_type, route, runways,
         body_name, runway_group, is_active, source, eff_date,
         is_sup, sup_cycle, sup_reason) = row
        batch.append((
            ptype, airport, pname, code, trans, trans_type, route, runways,
            body_name, runway_group,
            bool(is_active) if is_active is not None else True,
            source, eff_date,
            bool(is_sup) if is_sup is not None else False,
            sup_cycle, sup_reason
        ))

        if len(batch) >= BATCH_SIZE:
            execute_values(cursor_gis, insert_sql, batch)
            inserted += len(batch)
            batch = []

    if batch:
        execute_values(cursor_gis, insert_sql, batch)
        inserted += len(batch)

    conn_gis.commit()

    # Count after
    cursor_gis.execute("SELECT COUNT(*) FROM nav_procedures")
    after_total = cursor_gis.fetchone()[0]
    print(f"    Inserted {inserted:,} NASR procedures")
    print(f"    PostGIS total: {before_total:,} -> {after_total:,}")
    return inserted


def sync_playbook_routes(cursor_ref, cursor_gis, conn_gis, dry_run=False):
    """Sync playbook_routes from REF to PostGIS using UPSERT strategy.

    Deletes only NASR-sourced records and re-inserts, preserving any
    PostGIS-specific computed data (future route geometry, etc.).
    Omits playbook_id to avoid PK conflicts with auto-assigned IDs.
    """
    print("\n  Syncing playbook_routes (UPSERT - preserving PostGIS data)...")

    cursor_ref.execute(
        "SELECT playbook_id, play_name, full_route, origin_airports, origin_tracons, "
        "origin_artccs, dest_airports, dest_tracons, dest_artccs, "
        "altitude_min_ft, altitude_max_ft, is_active, source, effective_date, "
        "is_superseded, superseded_cycle, superseded_reason "
        "FROM dbo.playbook_routes"
    )
    rows = cursor_ref.fetchall()
    print(f"    Read {len(rows):,} rows from VATSIM_REF")

    if dry_run:
        cursor_gis.execute("SELECT COUNT(*) FROM playbook_routes")
        total = cursor_gis.fetchone()[0]
        print(f"    [DRY RUN] PostGIS has {total:,} rows, would replace NASR-sourced")
        return len(rows)

    # Count before
    cursor_gis.execute("SELECT COUNT(*) FROM playbook_routes")
    before_total = cursor_gis.fetchone()[0]

    # Delete only NASR-sourced records (old syncs used 'playbook_routes.csv')
    cursor_gis.execute(
        "DELETE FROM playbook_routes "
        "WHERE source IN ('NASR', 'nasr', 'playbook_routes.csv') OR source IS NULL"
    )
    deleted = cursor_gis.rowcount
    conn_gis.commit()
    print(f"    Deleted {deleted:,} NASR-sourced records")

    # Insert REF records (omit playbook_id — auto-assigned)
    inserted = 0
    batch = []
    insert_sql = (
        "INSERT INTO playbook_routes "
        "(play_name, full_route, origin_airports, origin_tracons, "
        "origin_artccs, dest_airports, dest_tracons, dest_artccs, "
        "altitude_min_ft, altitude_max_ft, is_active, source, effective_date, "
        "is_superseded, superseded_cycle, superseded_reason) VALUES %s"
    )
    for row in rows:
        (pb_id, name, route, orig_apt, orig_trc, orig_artcc,
         dest_apt, dest_trc, dest_artcc,
         alt_min, alt_max, is_active, source, eff_date,
         is_sup, sup_cycle, sup_reason) = row
        batch.append((
            name, route, orig_apt, orig_trc, orig_artcc,
            dest_apt, dest_trc, dest_artcc,
            alt_min, alt_max,
            bool(is_active) if is_active is not None else True,
            source, eff_date,
            bool(is_sup) if is_sup is not None else False,
            sup_cycle, sup_reason
        ))

        if len(batch) >= BATCH_SIZE:
            execute_values(cursor_gis, insert_sql, batch)
            inserted += len(batch)
            batch = []

    if batch:
        execute_values(cursor_gis, insert_sql, batch)
        inserted += len(batch)

    conn_gis.commit()

    cursor_gis.execute("SELECT COUNT(*) FROM playbook_routes")
    after_total = cursor_gis.fetchone()[0]
    print(f"    Inserted {inserted:,} rows")
    print(f"    PostGIS total: {before_total:,} -> {after_total:,}")
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
        choices=["nav_fixes", "airways", "area_centers",
                 "coded_departure_routes", "nav_procedures", "playbook_routes", "all"],
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
    tables = [args.table] if args.table != "all" else [
        "nav_fixes", "airways", "area_centers",
        "coded_departure_routes", "nav_procedures", "playbook_routes"
    ]

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

        if "coded_departure_routes" in tables:
            results["coded_departure_routes"] = sync_coded_departure_routes(
                cursor_ref, cursor_gis, conn_gis, args.dry_run
            )

        if "nav_procedures" in tables:
            results["nav_procedures"] = sync_nav_procedures(
                cursor_ref, cursor_gis, conn_gis, args.dry_run
            )

        if "playbook_routes" in tables:
            results["playbook_routes"] = sync_playbook_routes(
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
