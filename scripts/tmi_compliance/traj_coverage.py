#!/usr/bin/env python3
"""Trajectory data coverage analysis for TMI compliance"""
import pymssql
import sys

def main():
    conn = pymssql.connect(
        server='vatsim.database.windows.net',
        user='adl_api_user',
        password='CAMRN@11000',
        database='VATSIM_ADL'
    )
    cursor = conn.cursor()

    print('='*80)
    print('DATA RANGE ANALYSIS')
    print('='*80)

    # Check trajectory archive date range
    cursor.execute("""
        SELECT MIN(timestamp_utc), MAX(timestamp_utc), COUNT(*)
        FROM dbo.adl_trajectory_archive
    """)
    row = cursor.fetchone()
    print(f'TRAJECTORY_ARCHIVE:')
    print(f'  Earliest: {row[0]}')
    print(f'  Latest:   {row[1]}')
    print(f'  Total records: {row[2]:,}')

    # Check flight_core date range
    cursor.execute("""
        SELECT MIN(first_seen_utc), MAX(first_seen_utc), COUNT(*)
        FROM dbo.adl_flight_core
    """)
    row = cursor.fetchone()
    print(f'FLIGHT_CORE:')
    print(f'  Earliest: {row[0]}')
    print(f'  Latest:   {row[1]}')
    print(f'  Total records: {row[2]:,}')

    # Check KATL flights specifically
    cursor.execute("""
        SELECT MIN(c.first_seen_utc), MAX(c.first_seen_utc), COUNT(*)
        FROM dbo.adl_flight_core c
        INNER JOIN dbo.adl_flight_plan p ON c.flight_uid = p.flight_uid
        WHERE p.fp_dest_icao = 'KATL'
    """)
    row = cursor.fetchone()
    print(f'KATL FLIGHTS:')
    print(f'  Earliest: {row[0]}')
    print(f'  Latest:   {row[1]}')
    print(f'  Total records: {row[2]:,}')

    # Check around Jan 16-17 2025
    print()
    print('='*80)
    print('JAN 16-17 2025 EVENT WINDOW CHECK')
    print('='*80)

    cursor.execute("""
        SELECT COUNT(*)
        FROM dbo.adl_flight_core c
        WHERE c.first_seen_utc >= '2025-01-16 00:00:00'
          AND c.first_seen_utc <= '2025-01-18 00:00:00'
    """)
    row = cursor.fetchone()
    print(f'All flights Jan 16-18 2025: {row[0]}')

    cursor.execute("""
        SELECT COUNT(*)
        FROM dbo.adl_trajectory_archive t
        WHERE t.timestamp_utc >= '2025-01-16 00:00:00'
          AND t.timestamp_utc <= '2025-01-18 00:00:00'
    """)
    row = cursor.fetchone()
    print(f'Trajectory points Jan 16-18 2025: {row[0]}')

    # Check trajectory data for each month
    print()
    print('='*80)
    print('MONTHLY TRAJECTORY DATA BREAKDOWN')
    print('='*80)
    cursor.execute("""
        SELECT
            YEAR(timestamp_utc) as yr,
            MONTH(timestamp_utc) as mo,
            COUNT(*) as cnt
        FROM dbo.adl_trajectory_archive
        GROUP BY YEAR(timestamp_utc), MONTH(timestamp_utc)
        ORDER BY yr DESC, mo DESC
    """)
    print(f'{"Year":<6} {"Month":<6} {"Count":>15}')
    print('-'*30)
    for row in cursor.fetchall():
        print(f'{row[0]:<6} {row[1]:<6} {row[2]:>15,}')

    conn.close()

if __name__ == '__main__':
    main()
