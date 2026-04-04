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
    python airac_update.py --airac-cycle 2603 # Specify AIRAC cycle for changelog

Data flow:
    CSV files (assets/data/) -> VATSIM_REF -> VATSIM_ADL

Tables imported:
    1. nav_fixes       <- points.csv + navaids.csv (~380K rows)
    2. airways         <- awys.csv (~17K rows)
    3. coded_departure_routes <- cdrs.csv (~47K rows)
    4. nav_procedures  <- dp_full_routes.csv + star_full_routes.csv (~15K rows)
    5. playbook_routes <- playbook_routes.csv (~56K rows)
    6. preferred_routes <- prefroutes_db.csv (imported via refdata daemon pipeline)
"""

import pyodbc
import csv
import argparse
import sys
import re
import json
import math
from datetime import datetime
from pathlib import Path
from typing import List, Dict, Tuple, Optional
import os

# ==============================================================================
# Configuration
# ==============================================================================

SERVER = "vatsim.database.windows.net"
REF_DATABASE = "VATSIM_REF"
ADL_DATABASE = "VATSIM_ADL"

# API user for data operations
API_USER = os.environ.get("ADL_SQL_USER", "adl_api_user")
API_PASS = os.environ.get("ADL_SQL_PASSWORD", "")

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
    'preferred': DATA_DIR / "prefroutes_db.csv",
}


# ==============================================================================
# Helper Functions
# ==============================================================================

def parse_old_suffix(name: str) -> tuple:
    """Parse _old_ suffix. Returns (clean_name, is_superseded, cycle, reason).

    Formats handled:
      MERIT_old_2602_moved     -> ('MERIT', True, '2602', 'moved')
      AA_old_pre2602           -> ('AA', True, 'pre2602', None)
      PLAY_old_2601            -> ('PLAY', True, '2601', None)
      V1_old_2602_changed      -> ('V1', True, '2602', 'changed')
      NAME_OLD                 -> ('NAME', True, None, None)
      NORMAL_NAME              -> ('NORMAL_NAME', False, None, None)
    """
    # Full format: name_old_YYNN_reason
    match = re.match(r'^(.+?)_old_(\d{4})_(\w+)$', name, re.IGNORECASE)
    if match:
        return match.group(1), True, match.group(2), match.group(3)

    # Legacy format: name_old_preYYNN
    match = re.match(r'^(.+?)_old_(pre\d{4})$', name, re.IGNORECASE)
    if match:
        return match.group(1), True, match.group(2), None

    # Catch-all: name_old_anything (includes cycle-only like _old_2601)
    match = re.match(r'^(.+?)_old_(.+)$', name, re.IGNORECASE)
    if match:
        return match.group(1), True, match.group(2), None

    # Bare legacy: name_OLD (no underscore-delimited suffix)
    match = re.match(r'^(.+?)_OLD$', name)
    if match:
        return match.group(1), True, None, None

    return name, False, None, None


def haversine_nm(lat1, lon1, lat2, lon2):
    """Haversine distance in nautical miles."""
    R = 3440.065  # Earth radius in nm
    dlat = math.radians(lat2 - lat1)
    dlon = math.radians(lon2 - lon1)
    a = (math.sin(dlat / 2) ** 2 +
         math.cos(math.radians(lat1)) * math.cos(math.radians(lat2)) *
         math.sin(dlon / 2) ** 2)
    return R * 2 * math.asin(min(1.0, math.sqrt(a)))


def initial_bearing_deg(lat1, lon1, lat2, lon2):
    """Initial bearing from point 1 to point 2 in degrees."""
    lat1r, lon1r = math.radians(lat1), math.radians(lon1)
    lat2r, lon2r = math.radians(lat2), math.radians(lon2)
    dlon = lon2r - lon1r
    x = math.sin(dlon) * math.cos(lat2r)
    y = (math.cos(lat1r) * math.sin(lat2r) -
         math.sin(lat1r) * math.cos(lat2r) * math.cos(dlon))
    return (math.degrees(math.atan2(x, y)) + 360) % 360


def equirect_dist_sq(lat1, lon1, lat2, lon2):
    """Fast equirectangular approximate distance squared (for sorting only)."""
    dlat = lat2 - lat1
    dlon = (lon2 - lon1) * math.cos(math.radians((lat1 + lat2) / 2))
    return dlat * dlat + dlon * dlon


def classify_airway_type(name: str) -> str:
    """Classify airway type from name prefix."""
    if name.startswith('J'):
        return 'JET'
    elif name.startswith('V') and not name.startswith('V-'):
        return 'VICTOR'
    elif name.startswith(('Q', 'T')):
        return 'RNAV'
    elif name.startswith('U'):
        return 'UPPER'
    elif name.startswith(('A', 'B', 'G', 'R', 'W', 'L', 'M', 'N', 'H')):
        return 'INTL'
    else:
        return 'OTHER'


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
# Changelog
# ==============================================================================

def _normalize_key_val(val):
    """Normalize a value for changelog key comparison.

    Handles Decimal->float rounding and None normalization so that
    pyodbc Decimal('45.123400') matches Python float 45.1234.
    """
    if val is None:
        return ''
    try:
        f = float(val)
        return f'{f:.6f}'
    except (TypeError, ValueError):
        return str(val)


def snapshot_table(conn, table_name: str, key_sql: str, value_sql: str) -> dict:
    """Snapshot a table into {key: {values}} dict for changelog comparison."""
    cursor = conn.cursor()
    cursor.execute(f"SELECT {key_sql}, {value_sql} FROM dbo.{table_name}")
    rows = {}
    key_count = len(key_sql.split(','))
    for row in cursor.fetchall():
        key = tuple(_normalize_key_val(row[i]) for i in range(key_count))
        if key_count == 1:
            key = key[0]
        vals = {col.strip(): row[key_count + i]
                for i, col in enumerate(value_sql.split(','))}
        rows[key] = vals
    return rows


def generate_changelog(conn, table_name: str, old_rows: dict, new_rows: dict,
                       airac_cycle: str, coord_fields=None):
    """Compare old vs new data and insert changelog entries."""
    if not airac_cycle:
        return 0

    cursor = conn.cursor()
    changes = []

    old_keys = set(old_rows.keys())
    new_keys = set(new_rows.keys())

    # Added
    for key in new_keys - old_keys:
        changes.append((airac_cycle, table_name, str(key)[:64], 'added', None,
                        json.dumps(new_rows[key], default=str)[:4000], None))

    # Removed
    for key in old_keys - new_keys:
        changes.append((airac_cycle, table_name, str(key)[:64], 'removed',
                        json.dumps(old_rows[key], default=str)[:4000], None, None))

    # Changed/Moved
    for key in old_keys & new_keys:
        old = old_rows[key]
        new = new_rows[key]
        if coord_fields:
            lat_f, lon_f = coord_fields
            old_lat = float(old.get(lat_f) or 0)
            old_lon = float(old.get(lon_f) or 0)
            new_lat = float(new.get(lat_f) or 0)
            new_lon = float(new.get(lon_f) or 0)
            if abs(old_lat - new_lat) > 0.0001 or abs(old_lon - new_lon) > 0.0001:
                dist = haversine_nm(old_lat, old_lon, new_lat, new_lon)
                changes.append((airac_cycle, table_name, str(key)[:64], 'moved',
                               json.dumps(old, default=str)[:4000],
                               json.dumps(new, default=str)[:4000],
                               f'moved {dist:.1f}nm'))
        elif old != new:
            changes.append((airac_cycle, table_name, str(key)[:64], 'changed',
                           json.dumps(old, default=str)[:4000],
                           json.dumps(new, default=str)[:4000], None))

    # Batch insert
    if changes:
        insert_sql = """
            INSERT INTO dbo.navdata_changelogs
            (airac_cycle, table_name, entry_name, change_type, old_value, new_value, delta_detail)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        """
        for i in range(0, len(changes), 1000):
            batch = changes[i:i + 1000]
            try:
                cursor.executemany(insert_sql, batch)
                conn.commit()
            except Exception as e:
                conn.rollback()
                print(f"\n  Changelog insert error: {e}")

    added = len([c for c in changes if c[3] == 'added'])
    removed = len([c for c in changes if c[3] == 'removed'])
    moved = len([c for c in changes if c[3] == 'moved'])
    changed = len([c for c in changes if c[3] == 'changed'])
    print(f"  Changelog: {added} added, {removed} removed, {moved} moved, {changed} changed")

    return len(changes)


# ==============================================================================
# Import Functions
# ==============================================================================

def import_nav_fixes(conn, dry_run: bool = False, airac_cycle: str = None) -> Tuple[int, int]:
    """
    Import nav_fixes from points.csv and navaids.csv.

    CSV format (no header): FIX_NAME,LAT,LON
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
            name = parts[0].strip()
            clean_name, is_superseded, sup_cycle, sup_reason = parse_old_suffix(name)
            if len(parts) >= 3:
                try:
                    fixes.append({
                        'fix_name': clean_name[:32],
                        'fix_type': 'WAYPOINT',
                        'lat': float(parts[1]),
                        'lon': float(parts[2]),
                        'is_superseded': is_superseded,
                        'superseded_cycle': sup_cycle,
                        'superseded_reason': sup_reason,
                    })
                except ValueError:
                    continue
    active_points = sum(1 for f in fixes if not f['is_superseded'])
    superseded_points = sum(1 for f in fixes if f['is_superseded'])
    print(f"    Loaded {len(fixes):,} waypoints ({active_points:,} active, {superseded_points:,} superseded)")

    # Load navaids.csv (VOR/NDB type)
    navaid_start = len(fixes)
    print(f"  Loading {navaids_file.name}...")
    with open(navaids_file, 'r', encoding='utf-8-sig') as f:
        for line in f:
            line = line.strip()
            if not line:
                continue
            parts = line.split(',')
            name = parts[0].strip()
            clean_name, is_superseded, sup_cycle, sup_reason = parse_old_suffix(name)
            if len(parts) >= 3:
                try:
                    fixes.append({
                        'fix_name': clean_name[:32],
                        'fix_type': 'NAVAID',
                        'lat': float(parts[1]),
                        'lon': float(parts[2]),
                        'is_superseded': is_superseded,
                        'superseded_cycle': sup_cycle,
                        'superseded_reason': sup_reason,
                    })
                except ValueError:
                    continue
    navaid_count = len(fixes) - navaid_start
    print(f"    Loaded {navaid_count:,} navaids")
    print(f"  Total: {len(fixes):,} fixes")

    if dry_run:
        print("  [DRY RUN] Would import to VATSIM_REF.nav_fixes")
        return len(fixes), 0

    # Snapshot for changelog
    old_rows = {}
    if airac_cycle:
        print("  Snapshotting existing data for changelog...")
        old_rows = snapshot_table(conn, 'nav_fixes',
                                  'fix_name, lat, lon', 'fix_type')

    # Rich snapshot for supersession preservation
    old_fix_snapshot = []
    if airac_cycle:
        snap_cursor = conn.cursor()
        snap_cursor.execute("""
            SELECT fix_name, fix_type, lat, lon,
                   is_superseded, superseded_cycle, superseded_reason
            FROM dbo.nav_fixes
        """)
        for row in snap_cursor.fetchall():
            old_fix_snapshot.append({
                'fix_name': row[0],
                'fix_type': row[1],
                'lat': float(row[2]) if row[2] is not None else 0.0,
                'lon': float(row[3]) if row[3] is not None else 0.0,
                'is_superseded': bool(row[4]),
                'superseded_cycle': row[5],
                'superseded_reason': row[6],
            })
        print(f"    Supersession snapshot: {len(old_fix_snapshot):,} entries "
              f"({sum(1 for f in old_fix_snapshot if f['is_superseded']):,} already superseded)")

    # Get current count
    cursor.execute("SELECT COUNT(*) FROM dbo.nav_fixes")
    existing = cursor.fetchone()[0]
    print(f"  Existing rows: {existing:,}")

    # Truncate and insert
    print("  Truncating table...")
    cursor.execute("TRUNCATE TABLE dbo.nav_fixes")
    conn.commit()

    # Insert in batches
    insert_sql = """
        INSERT INTO dbo.nav_fixes
        (fix_name, fix_type, lat, lon, is_superseded, superseded_cycle, superseded_reason,
         source, effective_date)
        VALUES (?, ?, ?, ?, ?, ?, ?, 'NASR', GETUTCDATE())
    """

    batch_size = 5000
    inserted = 0
    errors = 0

    for i in range(0, len(fixes), batch_size):
        batch = fixes[i:i + batch_size]
        batch_data = [
            (f['fix_name'], f['fix_type'], f['lat'], f['lon'],
             1 if f['is_superseded'] else 0, f['superseded_cycle'], f['superseded_reason'])
            for f in batch
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

        pct = (inserted / len(fixes)) * 100
        print(f"\r  Progress: {inserted:,}/{len(fixes):,} ({pct:.0f}%)", end="", flush=True)

    print()

    # ---- Supersession Preservation for nav_fixes ----
    if airac_cycle and old_fix_snapshot:
        # Build lookup of imported active fixes: (name, type, lat6, lon6) for exact match
        imported_fix_coords = set()
        imported_fix_names = set()  # (name, type) for removal detection
        imported_sup_fixes = set()  # (name, type, lat6, lon6, sup_cycle) for superseded dedup
        for f in fixes:
            key = (f['fix_name'], f['fix_type'])
            lat6 = round(f['lat'], 6)
            lon6 = round(f['lon'], 6)
            if f['is_superseded']:
                imported_sup_fixes.add((*key, lat6, lon6, f['superseded_cycle']))
            else:
                imported_fix_coords.add((*key, lat6, lon6))
                imported_fix_names.add(key)

        superseded_fix_inserts = []
        for old in old_fix_snapshot:
            key = (old['fix_name'], old['fix_type'])
            lat6 = round(old['lat'], 6)
            lon6 = round(old['lon'], 6)
            coord_key = (*key, lat6, lon6)

            if old['is_superseded']:
                check = (*key, lat6, lon6, old['superseded_cycle'])
                if check not in imported_sup_fixes:
                    superseded_fix_inserts.append(old)
            else:
                if coord_key in imported_fix_coords:
                    pass  # Exact match — already imported
                elif key in imported_fix_names:
                    # Same name at different coordinates — moved
                    superseded_fix_inserts.append({
                        **old, 'is_superseded': True,
                        'superseded_cycle': airac_cycle, 'superseded_reason': 'moved',
                    })
                else:
                    # Name not in new data — removed
                    superseded_fix_inserts.append({
                        **old, 'is_superseded': True,
                        'superseded_cycle': airac_cycle, 'superseded_reason': 'removed',
                    })

        if superseded_fix_inserts:
            newly_moved = sum(1 for s in superseded_fix_inserts
                              if s.get('superseded_reason') == 'moved'
                              and s.get('superseded_cycle') == airac_cycle)
            newly_removed = sum(1 for s in superseded_fix_inserts
                                if s.get('superseded_reason') == 'removed'
                                and s.get('superseded_cycle') == airac_cycle)
            carried_fwd = len(superseded_fix_inserts) - newly_moved - newly_removed
            print(f"\n  Supersession preservation: {len(superseded_fix_inserts):,} fixes")
            print(f"    Carried forward: {carried_fwd:,}  "
                  f"Newly moved: {newly_moved:,}  Newly removed: {newly_removed:,}")

            sup_inserted = 0
            for i in range(0, len(superseded_fix_inserts), batch_size):
                batch = superseded_fix_inserts[i:i + batch_size]
                batch_data = [
                    (s['fix_name'], s['fix_type'], s['lat'], s['lon'],
                     1, s['superseded_cycle'], s['superseded_reason'])
                    for s in batch
                ]
                try:
                    cursor.executemany(insert_sql, batch_data)
                    conn.commit()
                    sup_inserted += len(batch)
                except Exception as e:
                    conn.rollback()
                    # Retry row-by-row on batch failure
                    for row in batch_data:
                        try:
                            cursor.execute(insert_sql, row)
                            conn.commit()
                            sup_inserted += 1
                        except Exception as e2:
                            conn.rollback()
                            errors += 1
                            if errors <= 10:
                                print(f"\n  ERROR superseded fix insert: {e2}")
                                print(f"    Data: {row[:2]}")

            inserted += sup_inserted
            print(f"    Inserted {sup_inserted:,} superseded fixes")
        else:
            print("\n  No superseded fixes to preserve (CSV already complete)")

    # Changelog: key by (fix_name, lat, lon) with normalized formatting.
    # Coordinate moves appear as remove+add pairs which is correct for nav_fixes
    # since _old_ suffixes already record move reasons.
    if airac_cycle and old_rows:
        new_rows = {}
        for f in fixes:
            key = (f['fix_name'], f'{f["lat"]:.6f}', f'{f["lon"]:.6f}')
            new_rows[key] = {'fix_type': f['fix_type']}
        generate_changelog(conn, 'nav_fixes', old_rows, new_rows, airac_cycle)

    # Log sync
    cursor.execute("""
        INSERT INTO dbo.ref_sync_log (table_name, rows_synced, sync_direction, sync_status)
        VALUES ('nav_fixes', ?, 'FROM_SOURCE', ?)
    """, inserted, 'SUCCESS' if errors == 0 else 'PARTIAL')
    conn.commit()

    return inserted, errors


def import_airways(conn, dry_run: bool = False, airac_cycle: str = None) -> Tuple[int, int]:
    """
    Import airways from awys.csv.

    CSV format (no header): AIRWAY_NAME,FIX1 FIX2 FIX3...
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
                raw_name = parts[0].strip()
                clean_name, is_superseded, sup_cycle, sup_reason = parse_old_suffix(raw_name)
                fix_sequence = parts[1].strip()
                fixes = fix_sequence.split()

                airway_type = classify_airway_type(clean_name)

                airways.append({
                    'airway_name': clean_name[:30],
                    'airway_type': airway_type,
                    'fix_sequence': fix_sequence,
                    'fix_count': len(fixes),
                    'start_fix': fixes[0][:32] if fixes else None,
                    'end_fix': fixes[-1][:32] if fixes else None,
                    'is_superseded': is_superseded,
                    'superseded_cycle': sup_cycle,
                    'superseded_reason': sup_reason,
                })

    active = sum(1 for a in airways if not a['is_superseded'])
    superseded = sum(1 for a in airways if a['is_superseded'])
    print(f"  Loaded {len(airways):,} airways ({active:,} active, {superseded:,} superseded)")

    if dry_run:
        print("  [DRY RUN] Would import to VATSIM_REF.airways")
        return len(airways), 0

    # Snapshot for changelog
    old_rows = {}
    if airac_cycle:
        print("  Snapshotting existing data for changelog...")
        old_rows = snapshot_table(conn, 'airways',
                                  'airway_name, fix_sequence', 'airway_type, fix_count')

    # Get current count
    cursor.execute("SELECT COUNT(*) FROM dbo.airways")
    existing = cursor.fetchone()[0]
    print(f"  Existing rows: {existing:,}")

    # Must clear segments first (FK constraint)
    print("  Clearing airway_segments...")
    cursor.execute("TRUNCATE TABLE dbo.airway_segments")

    print("  Truncating airways...")
    cursor.execute("DELETE FROM dbo.airways")
    cursor.execute("DBCC CHECKIDENT ('dbo.airways', RESEED, 0)")
    conn.commit()

    # Insert airways in batches with per-row retry
    insert_sql = """
        INSERT INTO dbo.airways
        (airway_name, airway_type, fix_sequence, fix_count, start_fix, end_fix,
         is_superseded, superseded_cycle, superseded_reason, source, effective_date)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'NASR', GETUTCDATE())
    """

    batch_size = 500
    inserted = 0
    errors = 0

    for i in range(0, len(airways), batch_size):
        batch = airways[i:i + batch_size]
        batch_data = [
            (a['airway_name'], a['airway_type'], a['fix_sequence'],
             a['fix_count'], a['start_fix'], a['end_fix'],
             1 if a['is_superseded'] else 0,
             a['superseded_cycle'], a['superseded_reason'])
            for a in batch
        ]

        try:
            cursor.executemany(insert_sql, batch_data)
            conn.commit()
            inserted += len(batch)
        except Exception as e:
            conn.rollback()
            # Per-row retry for failed batches
            for row_data in batch_data:
                try:
                    cursor.execute(insert_sql, row_data)
                    conn.commit()
                    inserted += 1
                except Exception as e2:
                    conn.rollback()
                    errors += 1
                    if errors <= 20:
                        print(f"\n  ERROR: {row_data[0]}: {e2}")

        pct = (inserted / len(airways)) * 100
        print(f"\r  Progress: {inserted:,}/{len(airways):,} ({pct:.0f}%)", end="", flush=True)

    print(f"\n  Inserted {inserted:,} airways ({errors} errors)")

    # Generate airway segments
    generate_airway_segments(conn)

    # Changelog
    if airac_cycle and old_rows:
        new_rows = {}
        for a in airways:
            key = (a['airway_name'], a['fix_sequence'])
            new_rows[key] = {'airway_type': a['airway_type'],
                             'fix_count': a['fix_count']}
        generate_changelog(conn, 'airways', old_rows, new_rows, airac_cycle)

    # Log sync
    cursor.execute("""
        INSERT INTO dbo.ref_sync_log (table_name, rows_synced, sync_direction, sync_status)
        VALUES ('airways', ?, 'FROM_SOURCE', ?)
    """, inserted, 'SUCCESS' if errors == 0 else 'PARTIAL')
    conn.commit()

    return inserted, errors


def generate_airway_segments(conn, dry_run: bool = False) -> int:
    """Generate airway segments from airways + nav_fixes using proximity disambiguation."""
    print("\n  Generating airway segments...")

    cursor = conn.cursor()

    # Load all nav_fixes into memory: fix_name -> list of (lat, lon)
    print("    Loading nav_fixes for disambiguation...")
    cursor.execute(
        "SELECT fix_name, lat, lon FROM dbo.nav_fixes "
        "WHERE lat IS NOT NULL AND lon IS NOT NULL AND is_superseded = 0"
    )
    fix_lookup = {}
    for row in cursor.fetchall():
        name = row[0]
        if name not in fix_lookup:
            fix_lookup[name] = []
        fix_lookup[name].append((float(row[1]), float(row[2])))
    print(f"    Loaded {len(fix_lookup):,} unique fix names ({sum(len(v) for v in fix_lookup.values()):,} total positions)")

    # Load active airways
    cursor.execute("""
        SELECT airway_id, airway_name, fix_sequence
        FROM dbo.airways
        WHERE is_superseded = 0 AND fix_sequence IS NOT NULL
    """)
    airways = cursor.fetchall()
    print(f"    Processing {len(airways):,} active airways...")

    if dry_run:
        print(f"    [DRY RUN] Would generate segments for {len(airways)} airways")
        return 0

    segments = []
    skipped_dist = 0
    no_fix_count = 0

    for airway_id, airway_name, fix_sequence in airways:
        fix_names = fix_sequence.split()
        if len(fix_names) < 2:
            continue

        # Find first anchor: first fix with exactly one location
        anchor_idx = None
        anchor_pos = None
        for idx, fn in enumerate(fix_names):
            positions = fix_lookup.get(fn, [])
            if len(positions) == 1:
                anchor_idx = idx
                anchor_pos = positions[0]
                break

        if anchor_pos is None:
            # Try closest pair of first two ambiguous fixes
            pos0 = fix_lookup.get(fix_names[0], [])
            pos1 = fix_lookup.get(fix_names[1], []) if len(fix_names) > 1 else []
            if pos0 and pos1:
                best_dist = float('inf')
                best_pair = None
                for p0 in pos0:
                    for p1 in pos1:
                        d = equirect_dist_sq(p0[0], p0[1], p1[0], p1[1])
                        if d < best_dist:
                            best_dist = d
                            best_pair = (p0, p1)
                if best_pair:
                    anchor_idx = 0
                    anchor_pos = best_pair[0]

        if anchor_pos is None:
            no_fix_count += 1
            continue

        # Resolve all fixes relative to anchor
        resolved_map = {anchor_idx: anchor_pos}

        # Forward from anchor
        prev_pos = anchor_pos
        for idx in range(anchor_idx + 1, len(fix_names)):
            positions = fix_lookup.get(fix_names[idx], [])
            if not positions:
                resolved_map[idx] = None
                continue
            if len(positions) == 1:
                resolved_map[idx] = positions[0]
                prev_pos = positions[0]
            else:
                best = min(positions, key=lambda p: equirect_dist_sq(
                    prev_pos[0], prev_pos[1], p[0], p[1]))
                resolved_map[idx] = best
                prev_pos = best

        # Backward from anchor
        prev_pos = anchor_pos
        for idx in range(anchor_idx - 1, -1, -1):
            positions = fix_lookup.get(fix_names[idx], [])
            if not positions:
                resolved_map[idx] = None
                continue
            if len(positions) == 1:
                resolved_map[idx] = positions[0]
                prev_pos = positions[0]
            else:
                best = min(positions, key=lambda p: equirect_dist_sq(
                    prev_pos[0], prev_pos[1], p[0], p[1]))
                resolved_map[idx] = best
                prev_pos = best

        # Generate segments for consecutive resolved pairs
        for idx in range(len(fix_names) - 1):
            pos_a = resolved_map.get(idx)
            pos_b = resolved_map.get(idx + 1)
            if pos_a is None or pos_b is None:
                continue

            dist = haversine_nm(pos_a[0], pos_a[1], pos_b[0], pos_b[1])
            if dist > 1000:
                skipped_dist += 1
                continue

            bearing = initial_bearing_deg(pos_a[0], pos_a[1], pos_b[0], pos_b[1])

            segments.append((
                airway_id, airway_name, idx + 1,
                fix_names[idx][:32], fix_names[idx + 1][:32],
                pos_a[0], pos_a[1], pos_b[0], pos_b[1],
                round(dist, 1), round(bearing)
            ))

    # Batch insert segments
    insert_sql = """
        INSERT INTO dbo.airway_segments
        (airway_id, airway_name, sequence_num, from_fix, to_fix,
         from_lat, from_lon, to_lat, to_lon, distance_nm, course_deg)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    """

    cursor.fast_executemany = True
    seg_inserted = 0
    seg_errors = 0

    for i in range(0, len(segments), 500):
        batch = segments[i:i + 500]
        try:
            cursor.executemany(insert_sql, batch)
            conn.commit()
            seg_inserted += len(batch)
        except Exception as e:
            conn.rollback()
            for row in batch:
                try:
                    cursor.execute(insert_sql, row)
                    conn.commit()
                    seg_inserted += 1
                except Exception as e2:
                    conn.rollback()
                    seg_errors += 1
                    if seg_errors <= 20:
                        print(f"\n    Segment ERROR: {row[1]} seq {row[2]}: {e2}")

    print(f"    Generated {seg_inserted:,} segments "
          f"({skipped_dist} skipped >1000nm, {no_fix_count} airways unresolvable, "
          f"{seg_errors} errors)")
    return seg_inserted


def import_cdrs(conn, dry_run: bool = False, airac_cycle: str = None) -> Tuple[int, int]:
    """
    Import coded_departure_routes from cdrs.csv.

    CSV format (no header): CDR_CODE,FULL_ROUTE
    """
    print("\n" + "=" * 60)
    print("Importing: coded_departure_routes")
    print("  Source: cdrs.csv")
    print("=" * 60)

    cursor = conn.cursor()

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
                raw_code = parts[0].strip()
                clean_name, is_superseded, sup_cycle, sup_reason = parse_old_suffix(raw_code)
                full_route = parts[1].strip()

                # Try to extract origin/dest from route
                route_parts = full_route.split()
                origin = route_parts[0][:4] if route_parts and route_parts[0].startswith('K') else None
                dest = route_parts[-1][:4] if route_parts and route_parts[-1].startswith('K') else None

                cdrs.append({
                    'cdr_code': clean_name[:16],
                    'full_route': full_route,
                    'origin_icao': origin,
                    'dest_icao': dest,
                    'is_superseded': is_superseded,
                    'superseded_cycle': sup_cycle,
                    'superseded_reason': sup_reason,
                })

    active = sum(1 for c in cdrs if not c['is_superseded'])
    superseded = sum(1 for c in cdrs if c['is_superseded'])
    print(f"  Loaded {len(cdrs):,} CDRs ({active:,} active, {superseded:,} superseded)")

    if dry_run:
        print("  [DRY RUN] Would import to VATSIM_REF.coded_departure_routes")
        return len(cdrs), 0

    # Snapshot for changelog
    old_rows = {}
    if airac_cycle:
        print("  Snapshotting existing data for changelog...")
        old_rows = snapshot_table(conn, 'coded_departure_routes',
                                  'cdr_code', 'full_route')

    # Get current count
    cursor.execute("SELECT COUNT(*) FROM dbo.coded_departure_routes")
    existing = cursor.fetchone()[0]
    print(f"  Existing rows: {existing:,}")

    # Truncate and insert
    print("  Truncating table...")
    cursor.execute("TRUNCATE TABLE dbo.coded_departure_routes")
    conn.commit()

    insert_sql = """
        INSERT INTO dbo.coded_departure_routes
        (cdr_code, full_route, origin_icao, dest_icao, is_active,
         is_superseded, superseded_cycle, superseded_reason, source, effective_date)
        VALUES (?, ?, ?, ?, 1, ?, ?, ?, 'NASR', GETUTCDATE())
    """

    # Use a fresh cursor for inserts with fast_executemany + setinputsizes
    # (intermediate execute() calls on the same cursor can reset input sizes)
    ins_cursor = conn.cursor()
    ins_cursor.fast_executemany = True
    ins_cursor.setinputsizes([
        (pyodbc.SQL_WVARCHAR, 16, 0),    # cdr_code
        (pyodbc.SQL_WVARCHAR, 200, 0),   # full_route
        (pyodbc.SQL_WVARCHAR, 4, 0),     # origin_icao
        (pyodbc.SQL_WVARCHAR, 4, 0),     # dest_icao
        (pyodbc.SQL_INTEGER, 0, 0),      # is_superseded
        (pyodbc.SQL_WVARCHAR, 8, 0),     # superseded_cycle
        (pyodbc.SQL_WVARCHAR, 16, 0),    # superseded_reason
    ])

    batch_size = 5000
    inserted = 0
    errors = 0

    for i in range(0, len(cdrs), batch_size):
        batch = cdrs[i:i + batch_size]
        batch_data = [
            (c['cdr_code'], c['full_route'], c['origin_icao'], c['dest_icao'],
             1 if c['is_superseded'] else 0,
             c['superseded_cycle'], c['superseded_reason'])
            for c in batch
        ]

        try:
            ins_cursor.executemany(insert_sql, batch_data)
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

    # Changelog
    if airac_cycle and old_rows:
        new_rows = {c['cdr_code']: {'full_route': c['full_route']} for c in cdrs}
        generate_changelog(conn, 'coded_departure_routes', old_rows, new_rows, airac_cycle)

    # Log sync
    cursor.execute("""
        INSERT INTO dbo.ref_sync_log (table_name, rows_synced, sync_direction, sync_status)
        VALUES ('coded_departure_routes', ?, 'FROM_SOURCE', ?)
    """, inserted, 'SUCCESS' if errors == 0 else 'PARTIAL')
    conn.commit()

    return inserted, errors


def import_procedures(conn, dry_run: bool = False, airac_cycle: str = None) -> Tuple[int, int]:
    """
    Import nav_procedures from dp_full_routes.csv and star_full_routes.csv.
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

            clean_code, is_superseded, sup_cycle, sup_reason = parse_old_suffix(computer_code)

            # Extract airport from ORIG_GROUP (e.g., "MKE/01L|01R" -> "KMKE")
            orig_group = row.get('ORIG_GROUP', '').strip()
            airport = None
            if orig_group:
                first_part = orig_group.split()[0] if orig_group else ''
                if '/' in first_part:
                    airport = first_part.split('/')[0]
                else:
                    airport = first_part
                if airport and len(airport) == 3:
                    airport = 'K' + airport
                airport = airport[:4] if airport else None

            # Transition type: 'fix', 'runway', or None
            trans_type_raw = row.get('TRANSITION_TYPE', '').strip()
            trans_type = trans_type_raw if trans_type_raw in ('fix', 'runway') else None

            procedures.append({
                'procedure_type': 'DP',
                'airport_icao': airport,
                'procedure_name': row.get('DP_NAME', '').strip()[:32],
                'computer_code': clean_code[:16],
                'transition_name': row.get('TRANSITION_NAME', '').strip()[:16] or None,
                'transition_type': trans_type,
                'full_route': row.get('ROUTE_POINTS', '').strip(),
                'runways': orig_group[:64] if orig_group else None,
                'is_superseded': is_superseded,
                'superseded_cycle': sup_cycle,
                'superseded_reason': sup_reason,
            })
    dp_count = len(procedures)
    print(f"    Loaded {dp_count:,} DPs")

    # Load STARs
    star_file = CSV_FILES['star_routes'].resolve()
    print(f"  Loading {star_file.name}...")
    with open(star_file, 'r', encoding='utf-8-sig', newline='') as f:
        reader = csv.DictReader(f)
        for row in reader:
            computer_code = row.get('STAR_COMPUTER_CODE', '').strip()
            if not computer_code:
                continue

            clean_code, is_superseded, sup_cycle, sup_reason = parse_old_suffix(computer_code)

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

            trans_type_raw = row.get('TRANSITION_TYPE', '').strip()
            trans_type = trans_type_raw if trans_type_raw in ('fix', 'runway') else None

            procedures.append({
                'procedure_type': 'STAR',
                'airport_icao': airport,
                'procedure_name': row.get('ARRIVAL_NAME', '').strip()[:32],
                'computer_code': clean_code[:16],
                'transition_name': row.get('TRANSITION_NAME', '').strip()[:16] or None,
                'transition_type': trans_type,
                'full_route': row.get('ROUTE_POINTS', '').strip(),
                'runways': dest_group[:64] if dest_group else None,
                'is_superseded': is_superseded,
                'superseded_cycle': sup_cycle,
                'superseded_reason': sup_reason,
            })

    star_count = len(procedures) - dp_count
    active = sum(1 for p in procedures if not p['is_superseded'])
    superseded = sum(1 for p in procedures if p['is_superseded'])
    print(f"    Loaded {star_count:,} STARs")
    print(f"  Total: {len(procedures):,} procedures ({active:,} active, {superseded:,} superseded)")

    if dry_run:
        print("  [DRY RUN] Would import to VATSIM_REF.nav_procedures")
        return len(procedures), 0

    # Snapshot for changelog
    old_rows = {}
    if airac_cycle:
        print("  Snapshotting existing data for changelog...")
        old_rows = snapshot_table(conn, 'nav_procedures',
                                  'computer_code, transition_name',
                                  'procedure_type, full_route')

    # Rich snapshot for supersession preservation (all columns needed for re-insert).
    # This ensures outdated navdata is preserved even if CSVs lack _old_ entries.
    old_proc_snapshot = []
    if airac_cycle:
        snap_cursor = conn.cursor()
        snap_cursor.execute("""
            SELECT procedure_type, airport_icao, procedure_name, computer_code, transition_name,
                   transition_type, full_route, runways,
                   is_superseded, superseded_cycle, superseded_reason
            FROM dbo.nav_procedures
        """)
        for row in snap_cursor.fetchall():
            old_proc_snapshot.append({
                'procedure_type': row[0],
                'airport_icao': row[1],
                'procedure_name': row[2],
                'computer_code': row[3],
                'transition_name': row[4],
                'transition_type': row[5],
                'full_route': row[6],
                'runways': row[7],
                'is_superseded': bool(row[8]),
                'superseded_cycle': row[9],
                'superseded_reason': row[10],
            })
        print(f"    Supersession snapshot: {len(old_proc_snapshot):,} entries "
              f"({sum(1 for p in old_proc_snapshot if p['is_superseded']):,} already superseded)")

    # Get current count
    cursor.execute("SELECT COUNT(*) FROM dbo.nav_procedures")
    existing = cursor.fetchone()[0]
    print(f"  Existing rows: {existing:,}")

    # Truncate and insert
    print("  Truncating table...")
    cursor.execute("TRUNCATE TABLE dbo.nav_procedures")
    conn.commit()

    # Ensure transition_type column exists (added for CIFP transition classification)
    try:
        cursor.execute("""
            IF NOT EXISTS (SELECT 1 FROM sys.columns
                           WHERE object_id = OBJECT_ID('dbo.nav_procedures')
                             AND name = 'transition_type')
            ALTER TABLE dbo.nav_procedures ADD transition_type NVARCHAR(10) NULL;
        """)
        conn.commit()
    except Exception:
        conn.rollback()

    insert_sql = """
        INSERT INTO dbo.nav_procedures
        (procedure_type, airport_icao, procedure_name, computer_code, transition_name,
         transition_type, full_route, runways, is_active,
         is_superseded, superseded_cycle, superseded_reason,
         source, effective_date)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?, ?, 'NASR', GETUTCDATE())
    """

    batch_size = 2000
    inserted = 0
    errors = 0

    for i in range(0, len(procedures), batch_size):
        batch = procedures[i:i + batch_size]
        batch_data = [
            (p['procedure_type'], p['airport_icao'] or 'ZZZZ', p['procedure_name'],
             p['computer_code'], p['transition_name'], p['transition_type'],
             p['full_route'], p['runways'],
             1 if p['is_superseded'] else 0,
             p['superseded_cycle'], p['superseded_reason'])
            for p in batch
        ]

        try:
            cursor.executemany(insert_sql, batch_data)
            conn.commit()
            inserted += len(batch)
        except Exception as e:
            conn.rollback()
            # Retry with smaller batches
            for row in batch_data:
                try:
                    cursor.execute(insert_sql, row)
                    conn.commit()
                    inserted += 1
                except Exception as e2:
                    conn.rollback()
                    errors += 1
                    if errors <= 10:
                        print(f"\n  ERROR row {i}: {e2}")
                        print(f"    Data: {row[:4]}")

        pct = (inserted / len(procedures)) * 100
        print(f"\r  Progress: {inserted:,}/{len(procedures):,} ({pct:.0f}%)", end="", flush=True)

    print()

    # ---- Supersession Preservation ----
    # Re-insert old entries that are missing from the CSV import so outdated
    # navdata is never silently lost.  Three cases:
    #   1. Old ACTIVE entries removed from new cycle  → re-insert as superseded (reason='removed')
    #   2. Old ACTIVE entries with changed full_route  → re-insert old version as superseded (reason='changed')
    #   3. Old SUPERSEDED entries not in CSV            → carry forward as-is
    if airac_cycle and old_proc_snapshot:
        # Build lookup of what was just imported from CSV.
        # Use a set of routes per key because the same (code, trans) can appear
        # for different runway groups at the same airport with different routes.
        imported_active = {}      # (code, trans_str) -> set of full_routes
        imported_superseded = set()  # (code, trans_str, sup_cycle) for dedup
        for p in procedures:
            key = (p['computer_code'], str(p['transition_name']))
            if p['is_superseded']:
                imported_superseded.add((*key, p['superseded_cycle']))
            else:
                imported_active.setdefault(key, set()).add(p['full_route'])

        superseded_inserts = []
        for old in old_proc_snapshot:
            code = old['computer_code']
            trans = str(old['transition_name'])
            key = (code, trans)

            if old['is_superseded']:
                # Already-superseded entry from previous cycle — carry forward
                # unless CSV already includes it
                check = (*key, old['superseded_cycle'])
                if check not in imported_superseded:
                    superseded_inserts.append(old)
            else:
                # Active entry in old DB
                if key not in imported_active:
                    # Removed from new AIRAC cycle
                    superseded_inserts.append({
                        **old,
                        'is_superseded': True,
                        'superseded_cycle': airac_cycle,
                        'superseded_reason': 'removed',
                    })
                elif old['full_route'] not in imported_active[key]:
                    # Route changed — preserve old version
                    superseded_inserts.append({
                        **old,
                        'is_superseded': True,
                        'superseded_cycle': airac_cycle,
                        'superseded_reason': 'changed',
                    })

        if superseded_inserts:
            newly_removed = sum(1 for s in superseded_inserts
                                if s.get('superseded_reason') == 'removed'
                                and s.get('superseded_cycle') == airac_cycle)
            newly_changed = sum(1 for s in superseded_inserts
                                if s.get('superseded_reason') == 'changed'
                                and s.get('superseded_cycle') == airac_cycle)
            carried_fwd = len(superseded_inserts) - newly_removed - newly_changed
            print(f"\n  Supersession preservation: {len(superseded_inserts):,} entries")
            print(f"    Carried forward: {carried_fwd:,}  "
                  f"Newly removed: {newly_removed:,}  Newly changed: {newly_changed:,}")

            sup_inserted = 0
            for i in range(0, len(superseded_inserts), batch_size):
                batch = superseded_inserts[i:i + batch_size]
                batch_data = [
                    (s['procedure_type'], s['airport_icao'] or 'ZZZZ', s['procedure_name'],
                     s['computer_code'], s['transition_name'], s['transition_type'],
                     s['full_route'], s['runways'],
                     1,  # is_superseded = always true here
                     s['superseded_cycle'], s['superseded_reason'])
                    for s in batch
                ]
                try:
                    cursor.executemany(insert_sql, batch_data)
                    conn.commit()
                    sup_inserted += len(batch)
                except Exception as e:
                    conn.rollback()
                    for row in batch_data:
                        try:
                            cursor.execute(insert_sql, row)
                            conn.commit()
                            sup_inserted += 1
                        except Exception as e2:
                            conn.rollback()
                            errors += 1
                            if errors <= 20:
                                print(f"\n  ERROR superseded insert: {e2}")
                                print(f"    Data: {row[:4]}")

            inserted += sup_inserted
            print(f"    Inserted {sup_inserted:,} superseded entries")
        else:
            print("\n  No superseded entries to preserve (CSV already complete)")

    # Changelog
    if airac_cycle and old_rows:
        new_rows = {}
        for p in procedures:
            key = (p['computer_code'], str(p['transition_name']))
            new_rows[key] = {'procedure_type': p['procedure_type'],
                             'full_route': p['full_route']}
        generate_changelog(conn, 'nav_procedures', old_rows, new_rows, airac_cycle)

    # Log sync
    cursor.execute("""
        INSERT INTO dbo.ref_sync_log (table_name, rows_synced, sync_direction, sync_status)
        VALUES ('nav_procedures', ?, 'FROM_SOURCE', ?)
    """, inserted, 'SUCCESS' if errors == 0 else 'PARTIAL')
    conn.commit()

    return inserted, errors


def import_playbook(conn, dry_run: bool = False, airac_cycle: str = None) -> Tuple[int, int]:
    """
    Import playbook_routes from playbook_routes.csv.

    CSV header: Play,Route String,Origins,Origin_TRACONs,Origin_ARTCCs,
                Destinations,Dest_TRACONs,Dest_ARTCCs
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

            clean_name, is_superseded, sup_cycle, sup_reason = parse_old_suffix(play_name)

            routes.append({
                'play_name': clean_name[:64],
                'full_route': route_string,
                'origin_airports': row.get('Origins', '').strip()[:256] or None,
                'origin_tracons': row.get('Origin_TRACONs', '').strip()[:128] or None,
                'origin_artccs': row.get('Origin_ARTCCs', '').strip()[:64] or None,
                'dest_airports': row.get('Destinations', '').strip()[:256] or None,
                'dest_tracons': row.get('Dest_TRACONs', '').strip()[:128] or None,
                'dest_artccs': row.get('Dest_ARTCCs', '').strip()[:64] or None,
                'is_superseded': is_superseded,
                'superseded_cycle': sup_cycle,
                'superseded_reason': sup_reason,
            })

    active = sum(1 for r in routes if not r['is_superseded'])
    superseded = sum(1 for r in routes if r['is_superseded'])
    print(f"  Loaded {len(routes):,} routes ({active:,} active, {superseded:,} superseded)")
    unique_plays = len(set(r['play_name'] for r in routes))
    print(f"  Unique plays: {unique_plays:,}")

    if dry_run:
        print("  [DRY RUN] Would import to VATSIM_REF.playbook_routes")
        return len(routes), 0

    # Snapshot for changelog
    old_rows = {}
    if airac_cycle:
        print("  Snapshotting existing data for changelog...")
        old_rows = snapshot_table(conn, 'playbook_routes',
                                  'play_name, full_route', 'origin_airports')

    # Get current count
    cursor.execute("SELECT COUNT(*) FROM dbo.playbook_routes")
    existing = cursor.fetchone()[0]
    print(f"  Existing rows: {existing:,}")

    # Truncate and insert
    print("  Truncating table...")
    cursor.execute("TRUNCATE TABLE dbo.playbook_routes")
    conn.commit()

    insert_sql = """
        INSERT INTO dbo.playbook_routes
        (play_name, full_route, origin_airports, origin_tracons, origin_artccs,
         dest_airports, dest_tracons, dest_artccs, is_active,
         is_superseded, superseded_cycle, superseded_reason, source, effective_date)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?, ?, 'playbook_routes.csv', GETUTCDATE())
    """

    batch_size = 1000
    inserted = 0
    errors = 0

    for i in range(0, len(routes), batch_size):
        batch = routes[i:i + batch_size]
        batch_data = [
            (r['play_name'], r['full_route'], r['origin_airports'], r['origin_tracons'],
             r['origin_artccs'], r['dest_airports'], r['dest_tracons'], r['dest_artccs'],
             1 if r['is_superseded'] else 0,
             r['superseded_cycle'], r['superseded_reason'])
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

    # Changelog
    if airac_cycle and old_rows:
        new_rows = {}
        for r in routes:
            key = (r['play_name'], r['full_route'])
            new_rows[key] = {'origin_airports': r['origin_airports']}
        generate_changelog(conn, 'playbook_routes', old_rows, new_rows, airac_cycle)

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
             'country_code', 'freq_mhz', 'mag_var', 'elevation_ft', 'source', 'effective_date',
             'is_superseded', 'superseded_cycle', 'superseded_reason'],
            True, None
        ),
        'airways': (
            ['airway_id', 'airway_name', 'airway_type', 'fix_sequence', 'fix_count',
             'start_fix', 'end_fix', 'min_alt_ft', 'max_alt_ft', 'direction', 'source', 'effective_date',
             'is_superseded', 'superseded_cycle', 'superseded_reason'],
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
             'altitude_min_ft', 'altitude_max_ft', 'is_active', 'source', 'effective_date',
             'is_superseded', 'superseded_cycle', 'superseded_reason'],
            True, None
        ),
        'playbook_routes': (
            ['playbook_id', 'play_name', 'full_route', 'origin_airports', 'origin_tracons',
             'origin_artccs', 'dest_airports', 'dest_tracons', 'dest_artccs',
             'altitude_min_ft', 'altitude_max_ft', 'is_active', 'source', 'effective_date',
             'is_superseded', 'superseded_cycle', 'superseded_reason'],
            True, None
        ),
        'preferred_routes': (
            ['preferred_route_id', 'origin_code', 'dest_code', 'origin_raw', 'dest_raw', 'route_string',
             'hours1', 'hours2', 'hours3', 'route_type', 'area', 'altitude', 'aircraft', 'direction', 'seq',
             'dep_artcc', 'arr_artcc', 'origin_tracon', 'origin_center', 'dest_tracon', 'dest_center',
             'traversed_centers', 'origin_is_airport', 'dest_is_airport', 'is_active', 'source',
             'effective_date', 'last_updated_utc'],
            True, None
        ),
        'nav_procedures': (
            ['procedure_id', 'procedure_type', 'airport_icao', 'procedure_name', 'computer_code',
             'transition_name', 'transition_type', 'full_route', 'runways', 'is_active', 'source', 'effective_date',
             'is_superseded', 'superseded_cycle', 'superseded_reason'],
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

    # Ensure transition_type column exists on ADL nav_procedures
    if 'nav_procedures' in table_defs:
        try:
            cursor_adl.execute("""
                IF NOT EXISTS (SELECT 1 FROM sys.columns
                               WHERE object_id = OBJECT_ID('dbo.nav_procedures')
                                 AND name = 'transition_type')
                ALTER TABLE dbo.nav_procedures ADD transition_type NVARCHAR(10) NULL;
            """)
            conn_adl.commit()
        except Exception:
            conn_adl.rollback()

    results = {}

    # Tables with FK references that prevent TRUNCATE -- must use DELETE
    fk_tables = {'airways', 'nav_procedures'}

    for table_name, (columns, has_identity, clear_first) in table_defs.items():
        print(f"\n  Syncing {table_name}...")
        identity_on = False

        try:
            # Clear dependent table first if needed
            if clear_first:
                cursor_adl.execute(f"DELETE FROM dbo.{clear_first}")
                conn_adl.commit()
                print(f"    Cleared {clear_first}")

            # Clear target -- DELETE for FK-constrained tables, TRUNCATE otherwise
            if table_name in fk_tables:
                cursor_adl.execute(f"DELETE FROM dbo.{table_name}")
            else:
                cursor_adl.execute(f"TRUNCATE TABLE dbo.{table_name}")
            conn_adl.commit()

            # Enable identity insert
            if has_identity:
                cursor_adl.execute(f"SET IDENTITY_INSERT dbo.{table_name} ON")
                identity_on = True

            # Read from REF
            col_list = ', '.join(columns)
            cursor_ref.execute(f"SELECT {col_list} FROM dbo.{table_name}")
            rows = cursor_ref.fetchall()

            if not rows:
                print(f"    No rows to sync")
                results[table_name] = 0
                if identity_on:
                    cursor_adl.execute(f"SET IDENTITY_INSERT dbo.{table_name} OFF")
                    identity_on = False
                continue

            # Insert to ADL in batches
            placeholders = ', '.join(['?'] * len(columns))
            insert_sql = f"INSERT INTO dbo.{table_name} ({col_list}) VALUES ({placeholders})"

            batch_size = 5000
            for i in range(0, len(rows), batch_size):
                batch = [tuple(row) for row in rows[i:i + batch_size]]
                cursor_adl.executemany(insert_sql, batch)
                conn_adl.commit()

            # Disable identity insert
            if identity_on:
                cursor_adl.execute(f"SET IDENTITY_INSERT dbo.{table_name} OFF")
                identity_on = False

            print(f"    Synced {len(rows):,} rows")
            results[table_name] = len(rows)

        except Exception as e:
            print(f"    ERROR: {e}")
            conn_adl.rollback()
            results[table_name] = 0
            # Always clean up IDENTITY_INSERT state
            if identity_on:
                try:
                    cursor_adl.execute(f"SET IDENTITY_INSERT dbo.{table_name} OFF")
                    identity_on = False
                except:
                    pass

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
# Sync REF -> PostGIS
# ==============================================================================

def sync_ref_to_postgis(dry_run: bool = False) -> Dict[str, int]:
    """
    Sync reference data from VATSIM_REF to VATSIM_GIS (PostGIS).

    Delegates to scripts/postgis/sync_ref_to_postgis.py which handles
    nav_fixes, airways, airway_segments, area_centers, coded_departure_routes,
    nav_procedures, and playbook_routes.
    """
    print("\n" + "=" * 60)
    print("Syncing: VATSIM_REF -> VATSIM_GIS (PostGIS)")
    print("=" * 60)

    import subprocess
    sync_script = Path(__file__).parent.parent.parent / "scripts" / "postgis" / "sync_ref_to_postgis.py"

    if not sync_script.exists():
        print(f"  WARNING: PostGIS sync script not found: {sync_script}")
        return {}

    cmd = [sys.executable, str(sync_script)]
    if dry_run:
        cmd.append("--dry-run")

    try:
        result = subprocess.run(
            cmd,
            capture_output=False,
            text=True,
            cwd=str(sync_script.parent),
        )
        if result.returncode != 0:
            print(f"  PostGIS sync failed with exit code {result.returncode}")
            return {"status": "FAILED"}
        return {"status": "SUCCESS"}
    except Exception as e:
        print(f"  ERROR running PostGIS sync: {e}")
        return {"status": "FAILED"}


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
    parser.add_argument('--skip-postgis', action='store_true',
                        help='Skip REF -> PostGIS sync after import')
    parser.add_argument('--dry-run', action='store_true',
                        help='Preview without making changes')
    parser.add_argument('--airac-cycle', type=str, default=None,
                        help='AIRAC cycle identifier (e.g., 2603) for changelog generation')
    args = parser.parse_args()

    print("=" * 70)
    print("              AIRAC UPDATE - Navigation Reference Data")
    print("=" * 70)
    print(f"Started: {datetime.now().strftime('%Y-%m-%d %H:%M:%S')}")
    print(f"Target: VATSIM_REF ({SERVER})")
    if args.airac_cycle:
        print(f"AIRAC Cycle: {args.airac_cycle}")
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
                try:
                    inserted, errors = import_funcs[table](conn, args.dry_run, args.airac_cycle)
                    results[table] = {'inserted': inserted, 'errors': errors}
                except Exception as e:
                    print(f"\n  FATAL ERROR importing {table}: {e}")
                    results[table] = {'inserted': 0, 'errors': -1}
                    conn.rollback()

        conn.close()

    # Sync to ADL
    if not args.skip_sync and not args.dry_run:
        sync_results = sync_ref_to_adl(dry_run=args.dry_run)
        results['sync'] = sync_results

    # Sync to PostGIS
    if not args.skip_sync and not args.skip_postgis and not args.dry_run:
        postgis_results = sync_ref_to_postgis(dry_run=args.dry_run)
        results['postgis'] = postgis_results

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
        elif table == 'postgis':
            status = data.get('status', 'UNKNOWN')
            print(f"\nSync to PostGIS: {status}")
        else:
            print(f"{table}: {data['inserted']:,} inserted, {data['errors']} errors")

    print(f"\nDuration: {duration:.1f}s")
    print(f"Finished: {datetime.now().strftime('%Y-%m-%d %H:%M:%S')}")
    print("=" * 70)


if __name__ == "__main__":
    main()
