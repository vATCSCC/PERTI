#!/usr/bin/env python3
"""
Import Playbook Routes to VATSIM_REF

Imports playbook routes from playbook_routes.csv into VATSIM_REF.playbook_routes table.

Usage:
    python import_playbook_to_ref.py --user USERNAME --password PASSWORD
    python import_playbook_to_ref.py --user USERNAME --password PASSWORD --dry-run
"""

import pyodbc
import argparse
import csv
import sys
from datetime import datetime
from pathlib import Path

# Connection settings
SERVER = "vatsim.database.windows.net"
DATABASE = "VATSIM_REF"

# Path to CSV file (relative to script location)
SCRIPT_DIR = Path(__file__).parent
CSV_PATH = SCRIPT_DIR / "../../assets/data/playbook_routes.csv"


def get_connection(server: str, database: str, username: str, password: str):
    """Create a connection to Azure SQL."""
    conn_str = (
        f"DRIVER={{ODBC Driver 18 for SQL Server}};"
        f"SERVER={server};"
        f"DATABASE={database};"
        f"UID={username};"
        f"PWD={password};"
        f"Encrypt=yes;"
        f"TrustServerCertificate=no;"
    )
    conn = pyodbc.connect(conn_str)
    # Enable fast executemany for bulk inserts (10-100x faster)
    conn.autocommit = False
    return conn


def load_csv(csv_path: Path) -> list:
    """Load playbook routes from CSV file."""
    routes = []

    with open(csv_path, 'r', encoding='utf-8-sig', newline='') as f:
        reader = csv.DictReader(f)
        for row in reader:
            play_name = row.get('Play', '').strip()
            route_string = row.get('Route String', '').strip()

            # Skip empty rows
            if not play_name or not route_string:
                continue

            # play_name column is NVARCHAR(64) - no truncation needed
            routes.append({
                'play_name': play_name,
                'full_route': route_string,
                'origin_airports': row.get('Origins', '').strip() or None,
                'origin_tracons': row.get('Origin_TRACONs', '').strip() or None,
                'origin_artccs': row.get('Origin_ARTCCs', '').strip() or None,
                'dest_airports': row.get('Destinations', '').strip() or None,
                'dest_tracons': row.get('Dest_TRACONs', '').strip() or None,
                'dest_artccs': row.get('Dest_ARTCCs', '').strip() or None,
            })

    return routes


def import_routes(conn, routes: list, batch_size: int = 1000) -> tuple:
    """Import routes to VATSIM_REF.playbook_routes table using fast bulk insert."""
    cursor = conn.cursor()
    # Enable fast executemany for 10-100x faster bulk inserts
    cursor.fast_executemany = True

    # Get current count
    cursor.execute("SELECT COUNT(*) FROM dbo.playbook_routes")
    existing_count = cursor.fetchone()[0]
    print(f"  Existing rows: {existing_count:,}")

    # Truncate existing data
    print("  Truncating playbook_routes table...")
    cursor.execute("DELETE FROM dbo.playbook_routes")
    conn.commit()

    # Prepare insert statement
    insert_sql = """
        INSERT INTO dbo.playbook_routes
        (play_name, full_route, origin_airports, origin_tracons, origin_artccs,
         dest_airports, dest_tracons, dest_artccs, is_active, source, effective_date)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, 'playbook_routes.csv', GETUTCDATE())
    """

    total_inserted = 0
    errors = 0

    # Process in larger batches using executemany
    for i in range(0, len(routes), batch_size):
        batch = routes[i:i+batch_size]

        # Prepare batch data as list of tuples
        batch_data = [
            (
                route['play_name'],
                route['full_route'],
                route['origin_airports'],
                route['origin_tracons'],
                route['origin_artccs'],
                route['dest_airports'],
                route['dest_tracons'],
                route['dest_artccs'],
            )
            for route in batch
        ]

        try:
            cursor.executemany(insert_sql, batch_data)
            conn.commit()
            total_inserted += len(batch)
        except Exception as e:
            # Fall back to individual inserts on batch failure
            conn.rollback()
            for route in batch:
                try:
                    cursor.execute(insert_sql, (
                        route['play_name'],
                        route['full_route'],
                        route['origin_airports'],
                        route['origin_tracons'],
                        route['origin_artccs'],
                        route['dest_airports'],
                        route['dest_tracons'],
                        route['dest_artccs'],
                    ))
                    conn.commit()
                    total_inserted += 1
                except Exception as row_err:
                    errors += 1
                    if errors <= 5:
                        print(f"\n  ERROR inserting {route['play_name']}: {row_err}")

        # Progress report
        pct = (total_inserted / len(routes)) * 100
        print(f"\r  Inserting: {total_inserted:,}/{len(routes):,} ({pct:.1f}%)", end="", flush=True)

    print()  # Newline after progress

    return total_inserted, errors


def log_sync(conn, rows: int, status: str, duration_ms: int, error: str = None):
    """Log the sync operation to ref_sync_log."""
    cursor = conn.cursor()
    cursor.execute("""
        INSERT INTO dbo.ref_sync_log (table_name, rows_synced, sync_direction, sync_status, duration_ms, error_message)
        VALUES ('playbook_routes', ?, 'FROM_SOURCE', ?, ?, ?)
    """, rows, status, duration_ms, error)
    conn.commit()


def main():
    parser = argparse.ArgumentParser(description="Import playbook routes to VATSIM_REF")
    parser.add_argument("--user", "-u", required=True, help="VATSIM_REF username")
    parser.add_argument("--password", "-p", required=True, help="VATSIM_REF password")
    parser.add_argument("--csv", help="Path to playbook_routes.csv (optional)")
    parser.add_argument("--dry-run", action="store_true", help="Parse only, don't import")
    parser.add_argument("--batch-size", type=int, default=100, help="Batch size for inserts")
    args = parser.parse_args()

    csv_path = Path(args.csv) if args.csv else CSV_PATH.resolve()

    print("=" * 60)
    print("VATSIM_REF Playbook Routes Import")
    print(f"Started: {datetime.now().strftime('%Y-%m-%d %H:%M:%S')}")
    print("=" * 60)
    print()

    # Load CSV
    print(f"Loading playbook routes from {csv_path}...")
    if not csv_path.exists():
        print(f"  ERROR: File not found: {csv_path}")
        sys.exit(1)

    routes = load_csv(csv_path)
    print(f"  Loaded {len(routes):,} routes")

    # Count unique plays
    unique_plays = len(set(r['play_name'] for r in routes))
    print(f"  Unique play names: {unique_plays:,}")
    print()

    if args.dry_run:
        print("[DRY RUN] Would import to VATSIM_REF but --dry-run specified")
        print()
        print("Sample routes:")
        for route in routes[:5]:
            print(f"  {route['play_name']}: {route['full_route'][:60]}...")
        sys.exit(0)

    # Connect to database
    print(f"Connecting to {SERVER}/{DATABASE}...")
    try:
        conn = get_connection(SERVER, DATABASE, args.user, args.password)
        print("  Connected successfully")
    except Exception as e:
        print(f"  ERROR: Connection failed: {e}")
        sys.exit(1)

    print()
    print("Importing routes...")
    start_time = datetime.now()

    try:
        inserted, errors = import_routes(conn, routes, args.batch_size)
        duration_ms = int((datetime.now() - start_time).total_seconds() * 1000)

        if errors == 0:
            log_sync(conn, inserted, "SUCCESS", duration_ms)
        else:
            log_sync(conn, inserted, "PARTIAL", duration_ms, f"{errors} rows failed")

    except Exception as e:
        duration_ms = int((datetime.now() - start_time).total_seconds() * 1000)
        log_sync(conn, 0, "FAILED", duration_ms, str(e))
        print(f"  ERROR: Import failed: {e}")
        conn.close()
        sys.exit(1)

    # Verify
    cursor = conn.cursor()
    cursor.execute("SELECT COUNT(*), COUNT(DISTINCT play_name) FROM dbo.playbook_routes")
    final_count, unique_plays = cursor.fetchone()

    duration = (datetime.now() - start_time).total_seconds()

    print()
    print("=" * 60)
    print("Import Complete")
    print(f"  Total routes: {final_count:,}")
    print(f"  Unique plays: {unique_plays:,}")
    print(f"  Errors: {errors}")
    print(f"  Duration: {duration:.1f}s")
    print(f"Finished: {datetime.now().strftime('%Y-%m-%d %H:%M:%S')}")
    print("=" * 60)
    print()
    print("NOTE: Run sync_ref_to_adl.sql to refresh VATSIM_ADL cache.")

    conn.close()


if __name__ == "__main__":
    main()
