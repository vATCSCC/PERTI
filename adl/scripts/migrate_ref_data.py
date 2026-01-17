#!/usr/bin/env python3
"""
VATSIM_REF Initial Data Migration Script

Migrates reference data FROM VATSIM_ADL TO VATSIM_REF.
Azure SQL doesn't support cross-database queries, so this script
uses two separate connections to move data.

Usage:
    python migrate_ref_data.py --adl-user USER --adl-pass PASS --ref-user USER --ref-pass PASS
"""

import pyodbc
import argparse
import sys
from datetime import datetime

# Connection settings
SERVER = "vatsim.database.windows.net"
ADL_DB = "VATSIM_ADL"
REF_DB = "VATSIM_REF"

# Tables to migrate (in order due to FK constraints)
# For GEOGRAPHY columns, we use special handling:
#   - select_columns: what to SELECT (converts GEOGRAPHY to WKT)
#   - insert_columns: what columns to INSERT into
#   - geo_columns: list of column names that are GEOGRAPHY (for special INSERT handling)
TABLES = [
    {
        "name": "nav_fixes",
        "select_columns": "fix_id, fix_name, fix_type, lat, lon, artcc_id, state_code, country_code, freq_mhz, mag_var, elevation_ft, source, effective_date, position_geo.STAsText() AS position_geo_wkt",
        "insert_columns": "fix_id, fix_name, fix_type, lat, lon, artcc_id, state_code, country_code, freq_mhz, mag_var, elevation_ft, source, effective_date, position_geo",
        "geo_columns": ["position_geo"],
        "geo_index": 13,  # 0-based index of geo column in result
        "identity": True
    },
    {
        "name": "airways",
        "select_columns": "airway_id, airway_name, airway_type, fix_sequence, fix_count, start_fix, end_fix, min_alt_ft, max_alt_ft, direction, source, effective_date",
        "insert_columns": "airway_id, airway_name, airway_type, fix_sequence, fix_count, start_fix, end_fix, min_alt_ft, max_alt_ft, direction, source, effective_date",
        "identity": True
    },
    {
        "name": "airway_segments",
        "select_columns": "segment_id, airway_id, airway_name, sequence_num, from_fix, to_fix, from_lat, from_lon, to_lat, to_lon, distance_nm, course_deg, min_alt_ft, max_alt_ft, segment_geo.STAsText() AS segment_geo_wkt",
        "insert_columns": "segment_id, airway_id, airway_name, sequence_num, from_fix, to_fix, from_lat, from_lon, to_lat, to_lon, distance_nm, course_deg, min_alt_ft, max_alt_ft, segment_geo",
        "geo_columns": ["segment_geo"],
        "geo_index": 14,
        "identity": True
    },
    {
        "name": "nav_procedures",
        "select_columns": "procedure_id, procedure_type, airport_icao, procedure_name, computer_code, transition_name, full_route, runways, is_active, source, effective_date",
        "insert_columns": "procedure_id, procedure_type, airport_icao, procedure_name, computer_code, transition_name, full_route, runways, is_active, source, effective_date",
        "identity": True
    },
    {
        "name": "coded_departure_routes",
        "select_columns": "cdr_id, cdr_code, full_route, origin_icao, dest_icao, direction, altitude_min_ft, altitude_max_ft, is_active, source, effective_date",
        "insert_columns": "cdr_id, cdr_code, full_route, origin_icao, dest_icao, direction, altitude_min_ft, altitude_max_ft, is_active, source, effective_date",
        "identity": True
    },
    {
        "name": "playbook_routes",
        "select_columns": "playbook_id, play_name, full_route, origin_airports, origin_tracons, origin_artccs, dest_airports, dest_tracons, dest_artccs, altitude_min_ft, altitude_max_ft, is_active, source, effective_date",
        "insert_columns": "playbook_id, play_name, full_route, origin_airports, origin_tracons, origin_artccs, dest_airports, dest_tracons, dest_artccs, altitude_min_ft, altitude_max_ft, is_active, source, effective_date",
        "identity": True
    },
    {
        "name": "area_centers",
        "select_columns": "center_id, center_code, center_type, center_name, lat, lon, parent_artcc, position_geo.STAsText() AS position_geo_wkt",
        "insert_columns": "center_id, center_code, center_type, center_name, lat, lon, parent_artcc, position_geo",
        "geo_columns": ["position_geo"],
        "geo_index": 7,
        "identity": True
    },
    {
        "name": "oceanic_fir_bounds",
        "select_columns": "fir_id, fir_code, fir_name, fir_type, min_lat, max_lat, min_lon, max_lon, keeps_tier_1",
        "insert_columns": "fir_id, fir_code, fir_name, fir_type, min_lat, max_lat, min_lon, max_lon, keeps_tier_1",
        "identity": True
    }
]

def get_connection(server, database, username, password):
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
    return pyodbc.connect(conn_str)

def migrate_table(adl_conn, ref_conn, table_info, skip_if_exists=True):
    """Migrate a single table from ADL to REF."""
    table_name = table_info["name"]
    select_columns = table_info.get("select_columns", table_info.get("columns"))
    insert_columns = table_info.get("insert_columns", table_info.get("columns"))
    has_identity = table_info.get("identity", False)
    geo_index = table_info.get("geo_index")  # Index of geography column (if any)

    print(f"  {table_name}:", flush=True)
    start_time = datetime.now()

    # Check if target already has data
    ref_cursor = ref_conn.cursor()
    ref_cursor.execute(f"SELECT COUNT(*) FROM dbo.{table_name}")
    existing_count = ref_cursor.fetchone()[0]

    if existing_count > 0 and skip_if_exists:
        print(f"    SKIP: Already has {existing_count:,} rows")
        return existing_count

    # Count source rows
    adl_cursor = adl_conn.cursor()
    adl_cursor.execute(f"SELECT COUNT(*) FROM dbo.{table_name}")
    source_count = adl_cursor.fetchone()[0]

    if source_count == 0:
        print(f"    SKIP: Source empty")
        return 0

    print(f"    Source: {source_count:,} rows", flush=True)
    print(f"    Fetching from ADL...", end=" ", flush=True)

    # Fetch all data from ADL
    adl_cursor.execute(f"SELECT {select_columns} FROM dbo.{table_name}")
    rows = adl_cursor.fetchall()
    print(f"done", flush=True)

    # Build parameterized insert
    col_list = insert_columns.split(", ")

    # If we have a geography column, we need special placeholder
    if geo_index is not None:
        placeholders = []
        for i, col in enumerate(col_list):
            if i == geo_index:
                placeholders.append("geography::STGeomFromText(?, 4326)")
            else:
                placeholders.append("?")
        placeholders = ", ".join(placeholders)
    else:
        placeholders = ", ".join(["?" for _ in col_list])

    insert_sql = f"INSERT INTO dbo.{table_name} ({insert_columns}) VALUES ({placeholders})"

    # Use smaller batches for Azure SQL Basic tier
    # Commit after each batch to avoid transaction log issues
    batch_size = 100  # Small batches for Basic tier
    total_inserted = 0

    try:
        # Enable identity insert if needed (once at start)
        if has_identity:
            ref_cursor.execute(f"SET IDENTITY_INSERT dbo.{table_name} ON")
            ref_conn.commit()

        for i in range(0, len(rows), batch_size):
            batch = rows[i:i+batch_size]
            # Convert rows to lists for modification
            batch_data = [list(row) for row in batch]

            # Insert each row individually to avoid executemany issues
            for row_data in batch_data:
                try:
                    ref_cursor.execute(insert_sql, row_data)
                except Exception as row_err:
                    print(f"\n    WARNING: Row insert failed: {row_err}")
                    continue

            ref_conn.commit()  # Commit after each batch
            total_inserted += len(batch)

            # Progress report
            pct = (total_inserted / source_count) * 100
            print(f"\r    Inserting: {total_inserted:,}/{source_count:,} ({pct:.1f}%)", end="", flush=True)

        # Disable identity insert
        if has_identity:
            ref_cursor.execute(f"SET IDENTITY_INSERT dbo.{table_name} OFF")
            ref_conn.commit()

        print()  # Newline after progress

    except Exception as e:
        # Make sure to turn off identity insert on error
        try:
            if has_identity:
                ref_cursor.execute(f"SET IDENTITY_INSERT dbo.{table_name} OFF")
            ref_conn.commit()
        except:
            pass
        raise e

    duration = (datetime.now() - start_time).total_seconds()
    print(f"    Done: {total_inserted:,} rows in {duration:.1f}s")

    return total_inserted

def log_sync(ref_conn, table_name, rows, status, duration_ms, error=None):
    """Log the sync operation to ref_sync_log."""
    cursor = ref_conn.cursor()
    cursor.execute("""
        INSERT INTO dbo.ref_sync_log (table_name, rows_synced, sync_direction, sync_status, duration_ms, error_message)
        VALUES (?, ?, 'FROM_ADL', ?, ?, ?)
    """, table_name, rows, status, duration_ms, error)
    ref_conn.commit()

def main():
    parser = argparse.ArgumentParser(description="Migrate reference data from VATSIM_ADL to VATSIM_REF")
    parser.add_argument("--adl-user", required=True, help="VATSIM_ADL username")
    parser.add_argument("--adl-pass", required=True, help="VATSIM_ADL password")
    parser.add_argument("--ref-user", required=True, help="VATSIM_REF username")
    parser.add_argument("--ref-pass", required=True, help="VATSIM_REF password")
    parser.add_argument("--table", help="Migrate only this table (optional)")
    args = parser.parse_args()

    print("=" * 60)
    print("VATSIM_REF Initial Data Migration")
    print(f"Started: {datetime.now().strftime('%Y-%m-%d %H:%M:%S')}")
    print("=" * 60)
    print()

    # Connect to both databases
    print("Connecting to databases...")
    try:
        adl_conn = get_connection(SERVER, ADL_DB, args.adl_user, args.adl_pass)
        print(f"  Connected to {ADL_DB}")
    except Exception as e:
        print(f"  ERROR: Failed to connect to {ADL_DB}: {e}")
        sys.exit(1)

    try:
        ref_conn = get_connection(SERVER, REF_DB, args.ref_user, args.ref_pass)
        print(f"  Connected to {REF_DB}")
    except Exception as e:
        print(f"  ERROR: Failed to connect to {REF_DB}: {e}")
        sys.exit(1)

    print()
    print("Migrating tables...")

    # Filter tables if specific table requested
    tables_to_migrate = TABLES
    if args.table:
        tables_to_migrate = [t for t in TABLES if t["name"] == args.table]
        if not tables_to_migrate:
            print(f"  ERROR: Table '{args.table}' not found in migration list")
            sys.exit(1)

    total_rows = 0
    start_time = datetime.now()

    for table_info in tables_to_migrate:
        try:
            table_start = datetime.now()
            rows = migrate_table(adl_conn, ref_conn, table_info)
            duration_ms = int((datetime.now() - table_start).total_seconds() * 1000)
            total_rows += rows
            log_sync(ref_conn, table_info["name"], rows, "SUCCESS", duration_ms)
        except Exception as e:
            print(f"  ERROR migrating {table_info['name']}: {e}")
            log_sync(ref_conn, table_info["name"], 0, "FAILED", 0, str(e))

    duration = (datetime.now() - start_time).total_seconds()

    print()
    print("=" * 60)
    print(f"Migration Complete")
    print(f"Total rows: {total_rows:,}")
    print(f"Duration: {duration:.1f}s")
    print(f"Finished: {datetime.now().strftime('%Y-%m-%d %H:%M:%S')}")
    print("=" * 60)

    # Close connections
    adl_conn.close()
    ref_conn.close()

if __name__ == "__main__":
    main()
