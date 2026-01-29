#!/usr/bin/env python3
"""
Sync playbook_routes from VATSIM_REF to VATSIM_ADL.

Azure SQL Basic doesn't support cross-database queries, so we:
1. Read all data from VATSIM_REF
2. Truncate VATSIM_ADL table
3. Insert data to VATSIM_ADL
"""

import pyodbc
import sys
from datetime import datetime

SERVER = "vatsim.database.windows.net"
USERNAME = "jpeterson"
PASSWORD = "***REMOVED***"


def get_connection(database: str):
    """Create connection to Azure SQL."""
    conn_str = (
        f"DRIVER={{ODBC Driver 18 for SQL Server}};"
        f"SERVER={SERVER};"
        f"DATABASE={database};"
        f"UID={USERNAME};"
        f"PWD={PASSWORD};"
        f"Encrypt=yes;"
        f"TrustServerCertificate=no;"
    )
    return pyodbc.connect(conn_str)


def main():
    print("=" * 60)
    print("VATSIM_REF -> VATSIM_ADL Sync (playbook_routes)")
    print(f"Started: {datetime.now().strftime('%Y-%m-%d %H:%M:%S')}")
    print("=" * 60)
    print()

    # Connect to VATSIM_REF and read all playbook routes
    print("1. Reading from VATSIM_REF...")
    conn_ref = get_connection("VATSIM_REF")
    cursor_ref = conn_ref.cursor()

    cursor_ref.execute("""
        SELECT playbook_id, play_name, full_route,
               origin_airports, origin_tracons, origin_artccs,
               dest_airports, dest_tracons, dest_artccs,
               altitude_min_ft, altitude_max_ft,
               is_active, source, effective_date
        FROM dbo.playbook_routes
    """)

    rows = cursor_ref.fetchall()
    print(f"   Read {len(rows):,} rows")
    conn_ref.close()

    # Connect to VATSIM_ADL and sync
    print("2. Syncing to VATSIM_ADL...")
    conn_adl = get_connection("VATSIM_ADL")
    conn_adl.autocommit = False
    cursor_adl = conn_adl.cursor()
    cursor_adl.fast_executemany = True

    # Truncate and insert
    print("   Truncating table...")
    cursor_adl.execute("TRUNCATE TABLE dbo.playbook_routes")

    print("   Inserting rows...")
    cursor_adl.execute("SET IDENTITY_INSERT dbo.playbook_routes ON")

    insert_sql = """
        INSERT INTO dbo.playbook_routes
        (playbook_id, play_name, full_route,
         origin_airports, origin_tracons, origin_artccs,
         dest_airports, dest_tracons, dest_artccs,
         altitude_min_ft, altitude_max_ft,
         is_active, source, effective_date)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    """

    # Convert rows to list of tuples
    data = [tuple(row) for row in rows]

    # Batch insert
    batch_size = 1000
    for i in range(0, len(data), batch_size):
        batch = data[i:i+batch_size]
        cursor_adl.executemany(insert_sql, batch)
        pct = min(100, ((i + batch_size) / len(data)) * 100)
        print(f"\r   Progress: {pct:.0f}%", end="", flush=True)

    cursor_adl.execute("SET IDENTITY_INSERT dbo.playbook_routes OFF")
    conn_adl.commit()
    print()

    # Verify
    cursor_adl.execute("SELECT COUNT(*) FROM dbo.playbook_routes")
    final_count = cursor_adl.fetchone()[0]

    conn_adl.close()

    print()
    print("=" * 60)
    print(f"Sync Complete - {final_count:,} rows in VATSIM_ADL")
    print(f"Finished: {datetime.now().strftime('%Y-%m-%d %H:%M:%S')}")
    print("=" * 60)


if __name__ == "__main__":
    main()
