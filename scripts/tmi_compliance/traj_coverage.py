#!/usr/bin/env python3
"""Trajectory data coverage analysis for TMI compliance"""
import pymssql
import sys

def main():
    conn = pymssql.connect(
        server='vatsim.database.windows.net',
        user='adl_api_user',
        password='***REMOVED***',
        database='VATSIM_ADL'
    )
    cursor = conn.cursor()

    event_start = '2025-01-16 23:59:00'
    event_end = '2025-01-17 04:00:00'

    print('='*80)
    print('TRAJECTORY DATA COVERAGE ANALYSIS')
    print('='*80)
    print(f'Event Window: {event_start} to {event_end}')
    print()

    # 1. Total flights to KATL
    cursor.execute("""
        SELECT COUNT(DISTINCT c.callsign)
        FROM dbo.adl_flight_core c
        INNER JOIN dbo.adl_flight_plan p ON c.flight_uid = p.flight_uid
        WHERE p.fp_dest_icao = 'KATL'
          AND c.first_seen_utc <= %s
          AND c.last_seen_utc >= %s
    """, (event_end, event_start))
    total = cursor.fetchone()[0]
    print(f'1. TOTAL FLIGHTS TO KATL: {total}')

    # 2. Flights with ANY trajectory data
    cursor.execute("""
        SELECT COUNT(DISTINCT c.callsign)
        FROM dbo.adl_flight_core c
        INNER JOIN dbo.adl_flight_plan p ON c.flight_uid = p.flight_uid
        WHERE p.fp_dest_icao = 'KATL'
          AND c.first_seen_utc <= %s
          AND c.last_seen_utc >= %s
          AND EXISTS (SELECT 1 FROM dbo.adl_trajectory_archive t WHERE t.flight_uid = c.flight_uid)
    """, (event_end, event_start))
    with_traj = cursor.fetchone()[0]
    print(f'2. FLIGHTS WITH TRAJECTORY: {with_traj} ({100*with_traj/total:.1f}%)')

    # 3. Total trajectory points in event window
    cursor.execute("""
        SELECT COUNT(*) FROM dbo.adl_trajectory_archive t
        WHERE t.timestamp_utc >= %s AND t.timestamp_utc <= %s
    """, (event_start, event_end))
    total_pts = cursor.fetchone()[0]
    print(f'3. TOTAL TRAJ POINTS IN WINDOW: {total_pts}')

    # 4. KATL trajectory points
    cursor.execute("""
        SELECT COUNT(*) FROM dbo.adl_trajectory_archive t
        INNER JOIN dbo.adl_flight_plan p ON t.flight_uid = p.flight_uid
        WHERE t.timestamp_utc >= %s AND t.timestamp_utc <= %s
          AND p.fp_dest_icao = 'KATL'
    """, (event_start, event_end))
    katl_pts = cursor.fetchone()[0]
    print(f'4. KATL TRAJ POINTS: {katl_pts}')

    print()
    print('='*80)
    print('TOP FLIGHTS BY TRAJECTORY POINTS')
    print('='*80)
    cursor.execute("""
        SELECT TOP 15 c.callsign, p.fp_dept_icao,
               CONVERT(VARCHAR, c.first_seen_utc, 120) as first_seen,
               CONVERT(VARCHAR, c.last_seen_utc, 120) as last_seen,
               (SELECT COUNT(*) FROM dbo.adl_trajectory_archive t WHERE t.flight_uid = c.flight_uid) as pts
        FROM dbo.adl_flight_core c
        INNER JOIN dbo.adl_flight_plan p ON c.flight_uid = p.flight_uid
        WHERE p.fp_dest_icao = 'KATL'
          AND c.first_seen_utc <= %s
          AND c.last_seen_utc >= %s
        ORDER BY pts DESC
    """, (event_end, event_start))
    print(f'{"Callsign":<12} {"Origin":<6} {"First Seen":<22} {"Last Seen":<22} {"Pts":<6}')
    print('-'*75)
    for r in cursor.fetchall():
        print(f'{r[0]:<12} {r[1]:<6} {r[2]:<22} {r[3]:<22} {r[4]:<6}')

    print()
    print('='*80)
    print('FLIGHTS WITH ZERO TRAJECTORY POINTS')
    print('='*80)
    cursor.execute("""
        SELECT TOP 15 c.callsign, p.fp_dept_icao,
               CONVERT(VARCHAR, c.first_seen_utc, 120) as first_seen,
               CONVERT(VARCHAR, c.last_seen_utc, 120) as last_seen
        FROM dbo.adl_flight_core c
        INNER JOIN dbo.adl_flight_plan p ON c.flight_uid = p.flight_uid
        WHERE p.fp_dest_icao = 'KATL'
          AND c.first_seen_utc <= %s
          AND c.last_seen_utc >= %s
          AND NOT EXISTS (SELECT 1 FROM dbo.adl_trajectory_archive t WHERE t.flight_uid = c.flight_uid)
        ORDER BY c.first_seen_utc
    """, (event_end, event_start))
    print(f'{"Callsign":<12} {"Origin":<6} {"First Seen":<22} {"Last Seen":<22}')
    print('-'*65)
    for r in cursor.fetchall():
        print(f'{r[0]:<12} {r[1]:<6} {r[2]:<22} {r[3]:<22}')

    conn.close()

if __name__ == '__main__':
    main()
