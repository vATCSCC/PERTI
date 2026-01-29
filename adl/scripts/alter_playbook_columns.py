#!/usr/bin/env python3
"""
Alter play_name column in VATSIM_REF and VATSIM_ADL to NVARCHAR(64).

This allows longer play names like "DC METRO NATS ESCAPE VIA GOATR_old_2601" (38 chars).
"""

import pyodbc
import sys
from pathlib import Path

# Add parent directory to path for config access
sys.path.insert(0, str(Path(__file__).parent.parent.parent))

def get_connection(server: str, database: str, username: str, password: str):
    """Create a connection to Azure SQL Server."""
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


def alter_column(conn, db_name: str):
    """Alter play_name column to NVARCHAR(64)."""
    cursor = conn.cursor()

    # Check current column size
    cursor.execute("""
        SELECT CHARACTER_MAXIMUM_LENGTH
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_NAME = 'playbook_routes' AND COLUMN_NAME = 'play_name'
    """)
    row = cursor.fetchone()
    current_size = row[0] if row else None
    print(f"  {db_name}.playbook_routes.play_name: current size = {current_size}")

    if current_size and current_size >= 64:
        print(f"  Already NVARCHAR(64) or larger, skipping.")
        return True

    # Alter the column
    try:
        cursor.execute("ALTER TABLE dbo.playbook_routes ALTER COLUMN play_name NVARCHAR(64) NOT NULL")
        conn.commit()
        print(f"  SUCCESS: Altered to NVARCHAR(64)")
        return True
    except Exception as e:
        print(f"  ERROR: {e}")
        return False


def main():
    # Database credentials
    server = "vatsim.database.windows.net"

    # Admin credentials for both databases
    admin_user = "jpeterson"
    admin_pass = "Jhp21012"

    ref_db = "VATSIM_REF"
    adl_db = "VATSIM_ADL"

    print("=" * 60)
    print("Altering play_name columns to NVARCHAR(64)")
    print("=" * 60)

    success = True

    # Alter VATSIM_REF
    print(f"\n1. Connecting to {ref_db}...")
    try:
        conn_ref = get_connection(server, ref_db, admin_user, admin_pass)
        if not alter_column(conn_ref, ref_db):
            success = False
        conn_ref.close()
    except Exception as e:
        print(f"  Connection failed: {e}")
        success = False

    # Alter VATSIM_ADL
    print(f"\n2. Connecting to {adl_db}...")
    try:
        conn_adl = get_connection(server, adl_db, admin_user, admin_pass)
        if not alter_column(conn_adl, adl_db):
            success = False
        conn_adl.close()
    except Exception as e:
        print(f"  Connection failed: {e}")
        success = False

    print("\n" + "=" * 60)
    if success:
        print("DONE - Both columns altered successfully")
        print("\nNext: Re-run import_playbook_to_ref.py to import full play names")
    else:
        print("FAILED - See errors above")
    print("=" * 60)

    return 0 if success else 1


if __name__ == "__main__":
    sys.exit(main())
