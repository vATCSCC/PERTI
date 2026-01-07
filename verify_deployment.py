#!/usr/bin/env python3
"""Verify ADL Archive System deployment"""

import pymssql

SERVER = 'vatsim.database.windows.net'
DATABASE = 'VATSIM_ADL'
USERNAME = 'jpeterson'
PASSWORD = '***REMOVED***'

def main():
    print(f"Connecting to {SERVER}/{DATABASE}...")
    conn = pymssql.connect(server=SERVER, user=USERNAME, password=PASSWORD, database=DATABASE, tds_version='7.3')
    cursor = conn.cursor()
    print("Connected!\n")

    # Verify archive stats view
    print("=" * 60)
    print("Archive Stats (SELECT * FROM dbo.v_archive_stats)")
    print("=" * 60)
    cursor.execute("SELECT * FROM dbo.v_archive_stats")
    rows = cursor.fetchall()

    print(f"{'Table Name':<25} {'Row Count':>12} {'Oldest Record':<22} {'Newest Record':<22} {'Tier':<10}")
    print("-" * 95)
    for row in rows:
        table_name = row[0] or ''
        row_count = row[1] or 0
        oldest = str(row[2])[:19] if row[2] else 'N/A'
        newest = str(row[3])[:19] if row[3] else 'N/A'
        tier = row[4] or ''
        print(f"{table_name:<25} {row_count:>12,} {oldest:<22} {newest:<22} {tier:<10}")

    # Verify archive objects exist
    print("\n" + "=" * 60)
    print("Archive Objects Verification")
    print("=" * 60)

    cursor.execute("""
        SELECT name, type_desc
        FROM sys.objects
        WHERE name IN (
            'adl_archive_config', 'adl_archive_log', 'adl_flight_archive',
            'adl_trajectory_archive', 'v_archive_stats',
            'sp_Archive_CompletedFlights', 'sp_Archive_Trajectory_ToWarm',
            'sp_Downsample_Trajectory_ToCold', 'sp_Purge_OldData',
            'sp_Get_Flight_History', 'sp_Get_Flight_Track',
            'sp_Trajectory_Stats', 'sp_Archive_RunAll', 'sp_Log_Trajectory'
        )
        ORDER BY type_desc, name
    """)

    for row in cursor.fetchall():
        print(f"  {row[1]:<25} {row[0]}")

    # Check if sp_Log_Trajectory is in refresh procedure
    print("\n" + "=" * 60)
    print("Verifying sp_Log_Trajectory call in refresh procedure")
    print("=" * 60)
    cursor.execute("""
        SELECT CASE WHEN OBJECT_DEFINITION(OBJECT_ID('dbo.sp_Adl_RefreshFromVatsim_Normalized'))
                    LIKE '%sp_Log_Trajectory%' THEN 'YES' ELSE 'NO' END AS contains_call
    """)
    result = cursor.fetchone()
    print(f"  sp_Adl_RefreshFromVatsim_Normalized contains 'EXEC dbo.sp_Log_Trajectory': {result[0]}")

    cursor.close()
    conn.close()

    print("\n" + "=" * 60)
    print("DEPLOYMENT VERIFICATION COMPLETE")
    print("=" * 60)

if __name__ == '__main__':
    main()
