#!/usr/bin/env python3
"""Quick check of arrival time tracking status."""
import pyodbc

conn_str = (
    'DRIVER={ODBC Driver 18 for SQL Server};'
    'SERVER=tcp:vatsim.database.windows.net,1433;'
    'DATABASE=VATSIM_ADL;'
    'UID=adl_api_user;'
    'PWD=CAMRN@11000;'
    'Encrypt=yes;TrustServerCertificate=no;'
)
conn = pyodbc.connect(conn_str, timeout=30)
cursor = conn.cursor()

print('=== ARRIVAL TIME TRACKING STATUS ===')
print()

# Check what arrival columns exist and have data
cursor.execute('''
    SELECT
        COUNT(*) AS total_flights,
        SUM(CASE WHEN ata_utc IS NOT NULL THEN 1 ELSE 0 END) AS with_ata,
        SUM(CASE WHEN ata_runway_utc IS NOT NULL THEN 1 ELSE 0 END) AS with_ata_runway,
        SUM(CASE WHEN on_utc IS NOT NULL THEN 1 ELSE 0 END) AS with_on_time,
        SUM(CASE WHEN in_utc IS NOT NULL THEN 1 ELSE 0 END) AS with_in_time
    FROM adl_flight_times
''')
row = cursor.fetchone()
print(f'Total flights in times table: {row[0]}')
print(f'With ATA (gate):             {row[1]}')
print(f'With ATA runway:             {row[2]}')
print(f'With ON time (wheels down):  {row[3]}')
print(f'With IN time (at gate):      {row[4]}')

print()
print('=== RECENT ARRIVED FLIGHTS ===')
cursor.execute("""
    SELECT TOP 10
        c.callsign,
        c.phase,
        fp.fp_dest_icao,
        FORMAT(ft.eta_utc, 'HH:mm') AS eta,
        FORMAT(ft.ata_utc, 'HH:mm') AS ata,
        FORMAT(ft.ata_runway_utc, 'HH:mm') AS ata_rwy,
        FORMAT(c.last_seen_utc, 'HH:mm') AS last_seen
    FROM adl_flight_core c
    JOIN adl_flight_times ft ON ft.flight_uid = c.flight_uid
    JOIN adl_flight_plan fp ON fp.flight_uid = c.flight_uid
    WHERE c.phase = 'arrived'
    ORDER BY c.last_seen_utc DESC
""")
print(f'{"callsign":<12} {"phase":<10} {"dest":<6} {"eta":<6} {"ata":<6} {"ata_rwy":<8} {"last_seen":<10}')
print('-' * 70)
for row in cursor.fetchall():
    print(f'{row[0] or "":<12} {row[1] or "":<10} {row[2] or "":<6} {row[3] or "-":<6} {row[4] or "-":<6} {row[5] or "-":<8} {row[6] or "-":<10}')

print()
print('=== CHANGELOG TABLE EXISTS? ===')
try:
    cursor.execute("""
        SELECT TOP 5 field_name, COUNT(*) AS changes
        FROM adl_flight_changelog
        WHERE changed_utc > DATEADD(HOUR, -24, GETUTCDATE())
        GROUP BY field_name
        ORDER BY COUNT(*) DESC
    """)
    print('Most tracked fields (last 24h):')
    for row in cursor.fetchall():
        print(f'  {row[0]}: {row[1]} changes')
except Exception as e:
    print(f'Changelog table not found or error: {e}')

print()
print('=== ETA vs LAST_SEEN FOR ARRIVED FLIGHTS ===')
cursor.execute("""
    SELECT TOP 10
        c.callsign,
        fp.fp_dest_icao AS dest,
        ft.eta_wind_adj_kts AS wind,
        DATEDIFF(MINUTE, ft.eta_utc, c.last_seen_utc) AS eta_error_min,
        FORMAT(ft.eta_utc, 'HH:mm') AS eta,
        FORMAT(c.last_seen_utc, 'HH:mm') AS arrived_at
    FROM adl_flight_core c
    JOIN adl_flight_times ft ON ft.flight_uid = c.flight_uid
    JOIN adl_flight_plan fp ON fp.flight_uid = c.flight_uid
    WHERE c.phase = 'arrived'
      AND ft.eta_utc IS NOT NULL
      AND c.last_seen_utc IS NOT NULL
    ORDER BY c.last_seen_utc DESC
""")
print('Using last_seen_utc as proxy for arrival time:')
print(f'{"callsign":<12} {"dest":<6} {"wind":<6} {"err_min":<8} {"eta":<6} {"arrived":<8}')
print('-' * 60)
for row in cursor.fetchall():
    wind = f'{int(row[2])}' if row[2] else '-'
    err = f'{row[3]}' if row[3] else '-'
    print(f'{row[0] or "":<12} {row[1] or "":<6} {wind:<6} {err:<8} {row[4] or "-":<6} {row[5] or "-":<8}')

conn.close()
