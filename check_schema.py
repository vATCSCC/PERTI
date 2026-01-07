#!/usr/bin/env python3
"""Check existing table schemas"""

import pymssql

SERVER = 'vatsim.database.windows.net'
DATABASE = 'VATSIM_ADL'
USERNAME = 'jpeterson'
PASSWORD = '***REMOVED***'

def main():
    conn = pymssql.connect(server=SERVER, user=USERNAME, password=PASSWORD, database=DATABASE, tds_version='7.3')
    cursor = conn.cursor()

    # Check adl_flight_trajectory columns
    print("=== adl_flight_trajectory columns ===")
    cursor.execute("""
        SELECT COLUMN_NAME, DATA_TYPE
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_NAME = 'adl_flight_trajectory'
        ORDER BY ORDINAL_POSITION
    """)
    for row in cursor.fetchall():
        print(f"  {row[0]}: {row[1]}")

    # Check adl_flight_changelog columns
    print("\n=== adl_flight_changelog columns ===")
    cursor.execute("""
        SELECT COLUMN_NAME, DATA_TYPE
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_NAME = 'adl_flight_changelog'
        ORDER BY ORDINAL_POSITION
    """)
    for row in cursor.fetchall():
        print(f"  {row[0]}: {row[1]}")

    # Check what archive objects were created
    print("\n=== Archive objects created ===")
    cursor.execute("""
        SELECT name, type_desc
        FROM sys.objects
        WHERE name LIKE '%archive%' OR name LIKE '%trajectory%' OR name LIKE 'sp_Log%'
        ORDER BY type_desc, name
    """)
    for row in cursor.fetchall():
        print(f"  {row[1]}: {row[0]}")

    cursor.close()
    conn.close()

if __name__ == '__main__':
    main()
