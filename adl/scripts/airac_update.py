#!/usr/bin/env python3
"""
AIRAC Update Master Script

Imports all navigation reference data from CSV files to VATSIM_REF,
then syncs to VATSIM_ADL cache.

Usage:
    python airac_update.py                    # Full update (all tables)
    python airac_update.py --table nav_fixes  # Single table
    python airac_update.py --sync-only        # Only sync REF -> ADL
    python airac_update.py --dry-run          # Preview without changes

Data flow:
    CSV files (assets/data/) -> VATSIM_REF -> VATSIM_ADL

Tables imported:
    1. nav_fixes       <- points.csv + navaids.csv (~270K rows)
    2. airways         <- awys.csv (~1.5K rows)
    3. coded_departure_routes <- cdrs.csv (~44K rows)
    4. nav_procedures  <- dp_full_routes.csv + star_full_routes.csv (~100K rows)
    5. playbook_routes <- playbook_routes.csv (~56K rows)
"""

import pyodbc
import csv
import argparse
import sys
from datetime import datetime
from pathlib import Path
from typing import List, Dict, Tuple, Optional

# ==============================================================================
# Configuration
# ==============================================================================

SERVER = "vatsim.database.windows.net"
REF_DATABASE = "VATSIM_REF"
ADL_DATABASE = "VATSIM_ADL"

# API user for data operations
API_USER = "adl_api_user"
API_PASS = "***REMOVED***"

# Paths relative to script location
SCRIPT_DIR = Path(__file__).parent
DATA_DIR = SCRIPT_DIR / "../../assets/data"

# CSV file mappings
CSV_FILES = {
    'points': DATA_DIR / "points.csv",
    'navaids': DATA_DIR / "navaids.csv",
    'airways': DATA_DIR / "awys.csv",
    'cdrs': DATA_DIR / "cdrs.csv",
    'dp_routes': DATA_DIR / "dp_full_routes.csv",
    'star_routes': DATA_DIR / "star_full_routes.csv",
    'playbook': DATA_DIR / "playbook_routes.csv",
}


# ==============================================================================
# Database Connection
# ==============================================================================

def get_connection(database: str, user: str = API_USER, password: str = API_PASS):
    """Create connection to Azure SQL Server."""
    conn_str = (
        f"DRIVER={{ODBC Driver 18 for SQL Server}};"
        f"SERVER={SERVER};"
        f"DATABASE={database};"
        f"UID={user};"
        f"PWD={password};"
        f"Encrypt=yes;"
        f"TrustServerCertificate=no;"
    )
    conn = pyodbc.connect(conn_str)
    conn.autocommit = False
    return conn


# ==============================================================================
# Import Functions
# ==============================================================================

def import_nav_fixes(conn, dry_run: bool = False) -> Tuple[int, int]:
    """
    Import nav_fixes from points.csv and navaids.csv.

    CSV format (no header): FIX_NAME,LAT,LON

    Table columns: fix_id, fix_name, fix_type, lat, lon, artcc_id, state_code,
                   country_code, freq_mhz, mag_var, elevation_ft, source, effective_date
    """
    print("\n" + "=" * 60)
    print("Importing: nav_fixes")
    print("  Sources: points.csv, navaids.csv")
    print("=" * 60)

    cursor = conn.cursor()
    cursor.fast_executemany = True

    # Load points (waypoints)
    points_file = CSV_FILES['points'].resolve()
    navaids_file = CSV_FILES['navaids'].resolve()

    fixes = []

    # Load points.csv (WAYPOINT type)
    print(f"  Loading {points_file.name}...")
    with open(points_file, 'r', encoding='utf-8-sig') as f:
        for line in f:
            line = line.strip()
            if not line:
                continue
            parts = line.split(',')
            if len(parts) >= 3:
                try:
                    fixes.append({
                        'fix_name': parts[0].strip()[:16],
                        'fix_type': 'WAYPOINT',
                        'lat': float(parts[1]),
                        'lon': float(parts[2]),
                    })
                except ValueError:
                    continue
    print(f"    Loaded {len(fixes):,} waypoints")

    # Load navaids.csv (VOR/NDB type)
    navaid_count = 0
    print(f"  Loading {navaids_file.name}...")
    with open(navaids_file, 'r', encoding='utf-8-sig') as f:
        for line in f:
            line = line.strip()
            if not line:
                continue
            parts = line.split(',')
            if len(parts) >= 3:
                try:
                    fixes.append({
                        'fix_name': parts[0].strip()[:16],
                        'fix_type': 'NAVAID',
                        'lat': float(parts[1]),
                        'lon': float(parts[2]),
                    })
                    navaid_count += 1
                except ValueError:
                    continue
    print(f"    Loaded {navaid_count:,} navaids")
    print(f"  Total: {len(fixes):,} fixes")

    if dry_run:
        print("  [DRY RUN] Would import to VATSIM_REF.nav_fixes")
        return len(fixes), 0

    # Get current count
    cursor.execute("SELECT COUNT(*) FROM dbo.nav_fixes")
    existing = cursor.fetchone()[0]
    print(f"  Existing rows: {existing:,}")

    # Truncate and insert
    print("  Truncating table...")
    cursor.execute("DELETE FROM dbo.nav_fixes")
    cursor.execute("DBCC CHECKIDENT ('dbo.nav_fixes', RESEED, 0)")
    conn.commit()

    # Insert in batches
    insert_sql = """
        INSERT INTO dbo.nav_fixes (fix_name, fix_type, lat, lon, source, effective_date)
        VALUES (?, ?, ?, ?, 'NASR', GETUTCDATE())
    """

    batch_size = 5000
    inserted = 0
    errors = 0

    for i in range(0, len(fixes), batch_size):
        batch = fixes[i:i+batch_size]
        batch_data = [(f['fix_name'], f['fix_type'], f['lat'], f['lon']) for f in batch]

        try:
            cursor.executemany(insert_sql, batch_data)
            conn.commit()
            inserted += len(batch)
        except Exception as e:
            conn.rollback()
            errors += len(batch)
            if errors <= 5:
                print(f"\n  ERROR: {e}")

        pct = (inserted / len(fixes)) * 100
        print(f"\r  Progress: {inserted:,}/{len(fixes):,} ({pct:.0f}%)", end="", flush=True)

    print()

    # Log sync
    cursor.execute("""
        INSERT INTO dbo.ref_sync_log (table_name, rows_synced, sync_direction, sync_status)
        VALUES ('nav_fixes', ?, 'FROM_SOURCE', ?)
    """, inserted, 'SUCCESS' if errors == 0 else 'PARTIAL')
    conn.commit()

    return inserted, errors


def import_airways(conn, dry_run: bool = False) -> Tuple[int, int]:
    """
    Import airways from awys.csv.

    CSV format (no header): AIRWAY_NAME,FIX1 FIX2 FIX3...

    Table columns: airway_id, airway_name, airway_type, fix_sequence, fix_count,
                   start_fix, end_fix, min_alt_ft, max_alt_ft, direction, source, effective_date
    """
    print("\n" + "=" * 60)
    print("Importing: airways")
    print("  Source: awys.csv")
    print("=" * 60)

    cursor = conn.cursor()
    cursor.fast_executemany = True

    airways_file = CSV_FILES['airways'].resolve()
    airways = []

    print(f"  Loading {airways_file.name}...")
    with open(airways_file, 'r', encoding='utf-8-sig') as f:
        for line in f:
            line = line.strip()
            if not line:
                continue
            parts = line.split(',', 1)
            if len(parts) >= 2:
                airway_name = parts[0].strip()[:8]
                fix_sequence = parts[1].strip()
                fixes = fix_sequence.split()

                # Determine airway type from name
                if airway_name.startswith('J'):
                    airway_type = 'JET'
                elif airway_name.startswith('V'):
                    airway_type = 'VICTOR'
                elif airway_name.startswith('Q') or airway_name.startswith('T'):
                    airway_type = 'RNAV'
                else:
                    airway_type = 'OTHER'

                airways.append({
                    'airway_name': airway_name,
                    'airway_type': airway_type,
                    'fix_sequence': fix_sequence,
                    'fix_count': len(fixes),
                    'start_fix': fixes[0][:16] if fixes else None,
                    'end_fix': fixes[-1][:16] if fixes else None,
                })

    print(f"  Loaded {len(airways):,} airways")

    if dry_run:
        print("  [DRY RUN] Would import to VATSIM_REF.airways")
        return len(airways), 0

    # Get current count
    cursor.execute("SELECT COUNT(*) FROM dbo.airways")
    existing = cursor.fetchone()[0]
    print(f"  Existing rows: {existing:,}")

    # Must clear segments first (FK constraint)
    print("  Clearing airway_segments...")
    cursor.execute("DELETE FROM dbo.airway_segments")

    print("  Truncating airways...")
    cursor.execute("DELETE FROM dbo.airways")
    cursor.execute("DBCC CHECKIDENT ('dbo.airways', RESEED, 0)")
    conn.commit()

    # Insert airways
    insert_sql = """
        INSERT INTO dbo.airways
        (airway_name, airway_type, fix_sequence, fix_count, start_fix, end_fix, source, effective_date)
        VALUES (?, ?, ?, ?, ?, ?, 'NASR', GETUTCDATE())
    """

    batch_data = [
        (a['airway_name'], a['airway_type'], a['fix_sequence'],
         a['fix_count'], a['start_fix'], a['end_fix'])
        for a in airways
    ]

    try:
        cursor.executemany(insert_sql, batch_data)
        conn.commit()
        inserted = len(airways)
        errors = 0
    except Exception as e:
        conn.rollback()
        print(f"\n  ERROR: {e}")
        inserted = 0
        errors = len(airways)

    print(f"  Inserted {inserted:,} airways")

    # Log sync
    cursor.execute("""
        INSERT INTO dbo.ref_sync_log (table_name, rows_synced, sync_direction, sync_status)
        VALUES ('airways', ?, 'FROM_SOURCE', ?)
    """, inserted, 'SUCCESS' if errors == 0 else 'FAILED')
    conn.commit()

    return inserted, errors


def import_cdrs(conn, dry_run: bool = False) -> Tuple[int, int]:
    """
    Import coded_departure_routes from cdrs.csv.

    CSV format (no header): CDR_CODE,FULL_ROUTE

    Table columns: cdr_id, cdr_code, full_route, origin_icao, dest_icao, direction,
                   altitude_min_ft, altitude_max_ft, is_active, source, effective_date
    """
    print("\n" + "=" * 60)
    print("Importing: coded_departure_routes")
    print("  Source: cdrs.csv")
    print("=" * 60)

    cursor = conn.cursor()
    cursor.fast_executemany = True

    cdrs_file = CSV_FILES['cdrs'].resolve()
    cdrs = []

    print(f"  Loading {cdrs_file.name}...")
    with open(cdrs_file, 'r', encoding='utf-8-sig') as f:
        for line in f:
            line = line.strip()
            if not line:
                continue
            parts = line.split(',', 1)
            if len(parts) >= 2:
                cdr_code = parts[0].strip()[:16]
                full_route = parts[1].strip()

                # Try to extract origin/dest from route
                route_parts = full_route.split()
                origin = route_parts[0][:4] if route_parts and route_parts[0].startswith('K') else None
                dest = route_parts[-1][:4] if route_parts and route_parts[-1].startswith('K') else None

                cdrs.append({
                    'cdr_code': cdr_code,
                    'full_route': full_route,
                    'origin_icao': origin,
                    'dest_icao': dest,
                })

    print(f"  Loaded {len(cdrs):,} CDRs")

    if dry_run:
        print("  [DRY RUN] Would import to VATSIM_REF.coded_departure_routes")
        return len(cdrs), 0

    # Get current count
    cursor.execute("SELECT COUNT(*) FROM dbo.coded_departure_routes")
    existing = cursor.fetchone()[0]
    print(f"  Existing rows: {existing:,}")

    # Truncate and insert
    print("  Truncating table...")
    cursor.execute("DELETE FROM dbo.coded_departure_routes")
    cursor.execute("DBCC CHECKIDENT ('dbo.coded_departure_routes', RESEED, 0)")
    conn.commit()

    insert_sql = """
        INSERT INTO dbo.coded_departure_routes
        (cdr_code, full_route, origin_icao, dest_icao, is_active, source, effective_date)
        VALUES (?, ?, ?, ?, 1, 'NASR', GETUTCDATE())
    """

    batch_size = 5000
    inserted = 0
    errors = 0

    for i in range(0, len(cdrs), batch_size):
        batch = cdrs[i:i+batch_size]
        batch_data = [(c['cdr_code'], c['full_route'], c['origin_icao'], c['dest_icao']) for c in batch]

        try:
            cursor.executemany(insert_sql, batch_data)
            conn.commit()
            inserted += len(batch)
        except Exception as e:
            conn.rollback()
            errors += len(batch)
            if errors <= 5:
                print(f"\n  ERROR: {e}")

        pct = (inserted / len(cdrs)) * 100
        print(f"\r  Progress: {inserted:,}/{len(cdrs):,} ({pct:.0f}%)", end="", flush=True)

    print()

    # Log sync
    cursor.execute("""
        INSERT INTO dbo.ref_sync_log (table_name, rows_synced, sync_direction, sync_status)
        VALUES ('coded_departure_routes', ?, 'FROM_SOURCE', ?)
    """, inserted, 'SUCCESS' if errors == 0 else 'PARTIAL')
    conn.commit()

    return inserted, errors


def import_procedures(conn, dry_run: bool = False) -> Tuple[int, int]:
    """
    Import nav_procedures from dp_full_routes.csv and star_full_routes.csv.

    DP CSV header: EFF_DATE,DP_NAME,DP_COMPUTER_CODE,ARTCC,ORIG_GROUP,BODY_NAME,
                   TRANSITION_COMPUTER_CODE,TRANSITION_NAME,ROUTE_POINTS,ROUTE_FROM_ORIG_GROUP

    STAR CSV header: EFF_DATE,ARRIVAL_NAME,STAR_COMPUTER_CODE,ARTCC,DEST_GROUP,BODY_NAME,
                     TRANSITION_COMPUTER_CODE,TRANSITION_NAME,ROUTE_POINTS,ROUTE_FROM_DEST_GROUP

    Table columns: procedure_id, procedure_type, airport_icao, procedure_name, computer_code,
                   transition_name, full_route, runways, is_active, source, effective_date
    """
    print("\n" + "=" * 60)
    print("Importing: nav_procedures")
    print("  Sources: dp_full_routes.csv, star_full_routes.csv")
    print("=" * 60)

    cursor = conn.cursor()
    cursor.fast_executemany = True

    procedures = []

    # Load DPs
    dp_file = CSV_FILES['dp_routes'].resolve()
    print(f"  Loading {dp_file.name}...")
    with open(dp_file, 'r', encoding='utf-8-sig', newline='') as f:
        reader = csv.DictReader(f)
        for row in reader:
            computer_code = row.get('DP_COMPUTER_CODE', '').strip()
            if not computer_code:
                continue

            # Extract airport from ORIG_GROUP (e.g., "MKE/01L|01R" -> "KMKE")
            orig_group = row.get('ORIG_GROUP', '').strip()
            airport = None
            if orig_group:
                # Take first airport before /
                first_part = orig_group.split()[0] if orig_group else ''
                if '/' in first_part:
                    airport = first_part.split('/')[0]
                else:
                    airport = first_part
                # Add K prefix if 3 chars
                if airport and len(airport) == 3:
                    airport = 'K' + airport
                airport = airport[:4] if airport else None

            procedures.append({
                'procedure_type': 'DP',
                'airport_icao': airport,
                'procedure_name': row.get('DP_NAME', '').strip()[:32],
                'computer_code': computer_code[:16],
                'transition_name': row.get('TRANSITION_NAME', '').strip()[:16] or None,
                'full_route': row.get('ROUTE_POINTS', '').strip(),
                'runways': orig_group[:64] if orig_group else None,
            })
    print(f"    Loaded {len(procedures):,} DPs")

    # Load STARs
    star_file = CSV_FILES['star_routes'].resolve()
    star_count = 0
    print(f"  Loading {star_file.name}...")
    with open(star_file, 'r', encoding='utf-8-sig', newline='') as f:
        reader = csv.DictReader(f)
        for row in reader:
            computer_code = row.get('STAR_COMPUTER_CODE', '').strip()
            if not computer_code:
                continue

            # Extract airport from DEST_GROUP
            dest_group = row.get('DEST_GROUP', '').strip()
            airport = None
            if dest_group:
                first_part = dest_group.split()[0] if dest_group else ''
                if '/' in first_part:
                    airport = first_part.split('/')[0]
                else:
                    airport = first_part
                if airport and len(airport) == 3:
                    airport = 'K' + airport
                airport = airport[:4] if airport else None

            procedures.append({
                'procedure_type': 'STAR',
                'airport_icao': airport,
                'procedure_name': row.get('ARRIVAL_NAME', '').strip()[:32],
                'computer_code': computer_code[:16],
                'transition_name': row.get('TRANSITION_NAME', '').strip()[:16] or None,
                'full_route': row.get('ROUTE_POINTS', '').strip(),
                'runways': dest_group[:64] if dest_group else None,
            })
            star_count += 1
    print(f"    Loaded {star_count:,} STARs")
    print(f"  Total: {len(procedures):,} procedures")

    if dry_run:
        print("  [DRY RUN] Would import to VATSIM_REF.nav_procedures")
        return len(procedures), 0

    # Get current count
    cursor.execute("SELECT COUNT(*) FROM dbo.nav_procedures")
    existing = cursor.fetchone()[0]
    print(f"  Existing rows: {existing:,}")

    # Truncate and insert
    print("  Truncating table...")
    cursor.execute("DELETE FROM dbo.nav_procedures")
    cursor.execute("DBCC CHECKIDENT ('dbo.nav_procedures', RESEED, 0)")
    conn.commit()

    insert_sql = """
        INSERT INTO dbo.nav_procedures
        (procedure_type, airport_icao, procedure_name, computer_code, transition_name,
         full_route, runways, is_active, source, effective_date)
        VALUES (?, ?, ?, ?, ?, ?, ?, 1, 'NASR', GETUTCDATE())
    """

    batch_size = 5000
    inserted = 0
    errors = 0

    for i in range(0, len(procedures), batch_size):
        batch = procedures[i:i+batch_size]
        batch_data = [
            (p['procedure_type'], p['airport_icao'], p['procedure_name'],
             p['computer_code'], p['transition_name'], p['full_route'], p['runways'])
            for p in batch
        ]

        try:
            cursor.executemany(insert_sql, batch_data)
            conn.commit()
            inserted += len(batch)
        except Exception as e:
            conn.rollback()
            errors += len(batch)
            if errors <= 5:
                print(f"\n  ERROR: {e}")

        pct = (inserted / len(procedures)) * 100
        print(f"\r  Progress: {inserted:,}/{len(procedures):,} ({pct:.0f}%)", end="", flush=True)

    print()

    # Log sync
    cursor.execute("""
        INSERT INTO dbo.ref_sync_log (table_name, rows_synced, sync_direction, sync_status)
        VALUES ('nav_procedures', ?, 'FROM_SOURCE', ?)
    """, inserted, 'SUCCESS' if errors == 0 else 'PARTIAL')
    conn.commit()

    return inserted, errors


def import_playbook(conn, dry_run: bool = False) -> Tuple[int, int]:
    """
    Import playbook_routes from playbook_routes.csv.

    CSV header: Play,Route String,Origins,Origin_TRACONs,Origin_ARTCCs,
                Destinations,Dest_TRACONs,Dest_ARTCCs

    Table columns: playbook_id, play_name, full_route, origin_airports, origin_tracons,
                   origin_artccs, dest_airports, dest_tracons, dest_artccs,
                   altitude_min_ft, altitude_max_ft, is_active, source, effective_date
    """
    print("\n" + "=" * 60)
    print("Importing: playbook_routes")
    print("  Source: playbook_routes.csv")
    print("=" * 60)

    cursor = conn.cursor()
    cursor.fast_executemany = True

    playbook_file = CSV_FILES['playbook'].resolve()
    routes = []

    print(f"  Loading {playbook_file.name}...")
    with open(playbook_file, 'r', encoding='utf-8-sig', newline='') as f:
        reader = csv.DictReader(f)
        for row in reader:
            play_name = row.get('Play', '').strip()
            route_string = row.get('Route String', '').strip()

            if not play_name or not route_string:
                continue

            routes.append({
                'play_name': play_name[:64],  # NVARCHAR(64)
                'full_route': route_string,
                'origin_airports': row.get('Origins', '').strip()[:256] or None,
                'origin_tracons': row.get('Origin_TRACONs', '').strip()[:128] or None,
                'origin_artccs': row.get('Origin_ARTCCs', '').strip()[:64] or None,
                'dest_airports': row.get('Destinations', '').strip()[:256] or None,
                'dest_tracons': row.get('Dest_TRACONs', '').strip()[:128] or None,
                'dest_artccs': row.get('Dest_ARTCCs', '').strip()[:64] or None,
            })

    print(f"  Loaded {len(routes):,} routes")
    unique_plays = len(set(r['play_name'] for r in routes))
    print(f"  Unique plays: {unique_plays:,}")

    if dry_run:
        print("  [DRY RUN] Would import to VATSIM_REF.playbook_routes")
        return len(routes), 0

    # Get current count
    cursor.execute("SELECT COUNT(*) FROM dbo.playbook_routes")
    existing = cursor.fetchone()[0]
    print(f"  Existing rows: {existing:,}")

    # Truncate and insert
    print("  Truncating table...")
    cursor.execute("DELETE FROM dbo.playbook_routes")
    cursor.execute("DBCC CHECKIDENT ('dbo.playbook_routes', RESEED, 0)")
    conn.commit()

    insert_sql = """
        INSERT INTO dbo.playbook_routes
        (play_name, full_route, origin_airports, origin_tracons, origin_artccs,
         dest_airports, dest_tracons, dest_artccs, is_active, source, effective_date)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, 'playbook_routes.csv', GETUTCDATE())
    """

    batch_size = 1000
    inserted = 0
    errors = 0

    for i in range(0, len(routes), batch_size):
        batch = routes[i:i+batch_size]
        batch_data = [
            (r['play_name'], r['full_route'], r['origin_airports'], r['origin_tracons'],
             r['origin_artccs'], r['dest_airports'], r['dest_tracons'], r['dest_artccs'])
            for r in batch
        ]

        try:
            cursor.executemany(insert_sql, batch_data)
            conn.commit()
            inserted += len(batch)
        except Exception as e:
            conn.rollback()
            errors += len(batch)
            if errors <= 5:
                print(f"\n  ERROR: {e}")

        pct = (inserted / len(routes)) * 100
        print(f"\r  Progress: {inserted:,}/{len(routes):,} ({pct:.0f}%)", end="", flush=True)

    print()

    # Log sync
    cursor.execute("""
        INSERT INTO dbo.ref_sync_log (table_name, rows_synced, sync_direction, sync_status)
        VALUES ('playbook_routes', ?, 'FROM_SOURCE', ?)
    """, inserted, 'SUCCESS' if errors == 0 else 'PARTIAL')
    conn.commit()

    return inserted, errors


# ==============================================================================
# Sync REF -> ADL
# ==============================================================================

def sync_ref_to_adl(tables: Optional[List[str]] = None, dry_run: bool = False) -> Dict[str, int]:
    """
    Sync specified tables from VATSIM_REF to VATSIM_ADL.

    Since Azure SQL Basic doesn't support cross-database queries,
    we read from REF and write to ADL in a two-step process.
    """
    print("\n" + "=" * 60)
    print("Syncing: VATSIM_REF -> VATSIM_ADL")
    print("=" * 60)

    # Table definitions: (table_name, columns, has_identity, clear_first)
    table_defs = {
        'nav_fixes': (
            ['fix_id', 'fix_name', 'fix_type', 'lat', 'lon', 'artcc_id', 'state_code',
             'country_code', 'freq_mhz', 'mag_var', 'elevation_ft', 'source', 'effective_date'],
            True, None
        ),
        'airways': (
            ['airway_id', 'airway_name', 'airway_type', 'fix_sequence', 'fix_count',
             'start_fix', 'end_fix', 'min_alt_ft', 'max_alt_ft', 'direction', 'source', 'effective_date'],
            True, 'airway_segments'
        ),
        'airway_segments': (
            ['segment_id', 'airway_id', 'airway_name', 'sequence_num', 'from_fix', 'to_fix',
             'from_lat', 'from_lon', 'to_lat', 'to_lon', 'distance_nm', 'course_deg',
             'min_alt_ft', 'max_alt_ft'],
            True, None
        ),
        'coded_departure_routes': (
            ['cdr_id', 'cdr_code', 'full_route', 'origin_icao', 'dest_icao', 'direction',
             'altitude_min_ft', 'altitude_max_ft', 'is_active', 'source', 'effective_date'],
            True, None
        ),
        'playbook_routes': (
            ['playbook_id', 'play_name', 'full_route', 'origin_airports', 'origin_tracons',
             'origin_artccs', 'dest_airports', 'dest_tracons', 'dest_artccs',
             'altitude_min_ft', 'altitude_max_ft', 'is_active', 'source', 'effective_date'],
            True, None
        ),
        'nav_procedures': (
            ['procedure_id', 'procedure_type', 'airport_icao', 'procedure_name', 'computer_code',
             'transition_name', 'full_route', 'runways', 'is_active', 'source', 'effective_date'],
            True, None
        ),
        'area_centers': (
            ['center_id', 'center_code', 'center_type', 'center_name', 'lat', 'lon', 'parent_artcc'],
            True, None
        ),
        'oceanic_fir_bounds': (
            ['fir_id', 'fir_code', 'fir_name', 'fir_type', 'min_lat', 'max_lat', 'min_lon', 'max_lon', 'keeps_tier_1'],
            True, None
        ),
    }

    # Filter tables if specified
    if tables:
        table_defs = {k: v for k, v in table_defs.items() if k in tables}

    if dry_run:
        print(f"  [DRY RUN] Would sync {len(table_defs)} tables")
        return {}

    # Connect to both databases
    print("  Connecting to VATSIM_REF...")
    conn_ref = get_connection(REF_DATABASE)
    print("  Connecting to VATSIM_ADL...")
    conn_adl = get_connection(ADL_DATABASE)

    cursor_ref = conn_ref.cursor()
    cursor_adl = conn_adl.cursor()
    cursor_adl.fast_executemany = True

    results = {}

    for table_name, (columns, has_identity, clear_first) in table_defs.items():
        print(f"\n  Syncing {table_name}...")

        try:
            # Clear dependent table first if needed
            if clear_first:
                cursor_adl.execute(f"DELETE FROM dbo.{clear_first}")
                conn_adl.commit()
                print(f"    Cleared {clear_first}")

            # Truncate target
            cursor_adl.execute(f"TRUNCATE TABLE dbo.{table_name}")
            conn_adl.commit()

            # Enable identity insert
            if has_identity:
                cursor_adl.execute(f"SET IDENTITY_INSERT dbo.{table_name} ON")

            # Read from REF
            col_list = ', '.join(columns)
            cursor_ref.execute(f"SELECT {col_list} FROM dbo.{table_name}")
            rows = cursor_ref.fetchall()

            if not rows:
                print(f"    No rows to sync")
                results[table_name] = 0
                continue

            # Insert to ADL in batches
            placeholders = ', '.join(['?'] * len(columns))
            insert_sql = f"INSERT INTO dbo.{table_name} ({col_list}) VALUES ({placeholders})"

            batch_size = 5000
            for i in range(0, len(rows), batch_size):
                batch = [tuple(row) for row in rows[i:i+batch_size]]
                cursor_adl.executemany(insert_sql, batch)
                conn_adl.commit()

            # Disable identity insert
            if has_identity:
                cursor_adl.execute(f"SET IDENTITY_INSERT dbo.{table_name} OFF")

            print(f"    Synced {len(rows):,} rows")
            results[table_name] = len(rows)

        except Exception as e:
            print(f"    ERROR: {e}")
            results[table_name] = 0

    # Log sync
    cursor_ref.execute("""
        INSERT INTO dbo.ref_sync_log (table_name, rows_synced, sync_direction, sync_status)
        VALUES ('ALL_TABLES', ?, 'TO_ADL', 'SUCCESS')
    """, sum(results.values()))
    conn_ref.commit()

    conn_ref.close()
    conn_adl.close()

    return results


# ==============================================================================
# Main
# ==============================================================================

def main():
    parser = argparse.ArgumentParser(
        description='AIRAC Update: Import navdata from CSV to VATSIM_REF and sync to VATSIM_ADL'
    )
    parser.add_argument('--table', choices=['nav_fixes', 'airways', 'cdrs', 'procedures', 'playbook', 'all'],
                        default='all', help='Table to import (default: all)')
    parser.add_argument('--sync-only', action='store_true',
                        help='Only sync REF -> ADL (skip CSV import)')
    parser.add_argument('--skip-sync', action='store_true',
                        help='Skip REF -> ADL sync after import')
    parser.add_argument('--dry-run', action='store_true',
                        help='Preview without making changes')
    args = parser.parse_args()

    print("=" * 70)
    print("              AIRAC UPDATE - Navigation Reference Data")
    print("=" * 70)
    print(f"Started: {datetime.now().strftime('%Y-%m-%d %H:%M:%S')}")
    print(f"Target: VATSIM_REF ({SERVER})")
    print()

    start_time = datetime.now()
    results = {}

    if not args.sync_only:
        # Connect to VATSIM_REF
        print("Connecting to VATSIM_REF...")
        try:
            conn = get_connection(REF_DATABASE)
            print("  Connected\n")
        except Exception as e:
            print(f"  ERROR: {e}")
            sys.exit(1)

        # Import tables
        import_funcs = {
            'nav_fixes': import_nav_fixes,
            'airways': import_airways,
            'cdrs': import_cdrs,
            'procedures': import_procedures,
            'playbook': import_playbook,
        }

        tables_to_import = [args.table] if args.table != 'all' else list(import_funcs.keys())

        for table in tables_to_import:
            if table in import_funcs:
                inserted, errors = import_funcs[table](conn, args.dry_run)
                results[table] = {'inserted': inserted, 'errors': errors}

        conn.close()

    # Sync to ADL
    if not args.skip_sync and not args.dry_run:
        sync_results = sync_ref_to_adl(dry_run=args.dry_run)
        results['sync'] = sync_results

    # Summary
    duration = (datetime.now() - start_time).total_seconds()

    print("\n" + "=" * 70)
    print("                              SUMMARY")
    print("=" * 70)

    for table, data in results.items():
        if table == 'sync':
            print(f"\nSync to VATSIM_ADL:")
            for t, count in data.items():
                print(f"  {t}: {count:,} rows")
        else:
            print(f"{table}: {data['inserted']:,} inserted, {data['errors']} errors")

    print(f"\nDuration: {duration:.1f}s")
    print(f"Finished: {datetime.now().strftime('%Y-%m-%d %H:%M:%S')}")
    print("=" * 70)


if __name__ == "__main__":
    main()
