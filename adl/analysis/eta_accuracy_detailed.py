#!/usr/bin/env python3
"""Detailed ETA accuracy analysis with arrival time tracking."""
import pyodbc

conn_str = (
    'DRIVER={ODBC Driver 18 for SQL Server};'
    'SERVER=tcp:vatsim.database.windows.net,1433;'
    'DATABASE=VATSIM_ADL;'
    'UID=adl_api_user;'
    'PWD=***REMOVED***;'
    'Encrypt=yes;TrustServerCertificate=no;'
)
conn = pyodbc.connect(conn_str, timeout=60)
cursor = conn.cursor()

print('=' * 80)
print('  ETA ACCURACY ANALYSIS - DETAILED')
print('=' * 80)

# 1. Check what's in ata_utc - sample rows
print()
print('=== 1. SAMPLE ATA VALUES ===')
cursor.execute("""
    SELECT TOP 10
        ft.flight_uid,
        ft.eta_utc,
        ft.ata_utc,
        ft.ata_runway_utc,
        ft.on_utc
    FROM adl_flight_times ft
    WHERE ft.ata_utc IS NOT NULL
    ORDER BY ft.ata_utc DESC
""")
print(f'{"flight_uid":<40} {"eta_utc":<22} {"ata_utc":<22} {"ata_rwy":<22}')
print('-' * 110)
for row in cursor.fetchall():
    print(f'{str(row[0]):<40} {str(row[1]):<22} {str(row[2]):<22} {str(row[3]):<22}')

# 2. Check adl_flight_changelog columns
print()
print('=== 2. CHANGELOG TABLE STRUCTURE ===')
try:
    cursor.execute("""
        SELECT TOP 1 * FROM adl_flight_changelog
    """)
    cols = [desc[0] for desc in cursor.description]
    print(f'Columns: {cols}')
except Exception as e:
    print(f'Error: {e}')

# 3. Get flights where we can compare ETA vs actual arrival
print()
print('=== 3. ETA ACCURACY FOR COMPLETED FLIGHTS ===')
cursor.execute("""
    SELECT
        COUNT(*) AS total_with_both,
        COUNT(CASE WHEN ABS(DATEDIFF(MINUTE, ft.eta_utc, COALESCE(ft.ata_runway_utc, ft.ata_utc))) <= 2 THEN 1 END) AS within_2min,
        COUNT(CASE WHEN ABS(DATEDIFF(MINUTE, ft.eta_utc, COALESCE(ft.ata_runway_utc, ft.ata_utc))) <= 5 THEN 1 END) AS within_5min,
        COUNT(CASE WHEN ABS(DATEDIFF(MINUTE, ft.eta_utc, COALESCE(ft.ata_runway_utc, ft.ata_utc))) <= 10 THEN 1 END) AS within_10min,
        AVG(CAST(DATEDIFF(MINUTE, ft.eta_utc, COALESCE(ft.ata_runway_utc, ft.ata_utc)) AS FLOAT)) AS avg_error_min,
        AVG(ABS(CAST(DATEDIFF(MINUTE, ft.eta_utc, COALESCE(ft.ata_runway_utc, ft.ata_utc)) AS FLOAT))) AS mae_min,
        STDEV(CAST(DATEDIFF(MINUTE, ft.eta_utc, COALESCE(ft.ata_runway_utc, ft.ata_utc)) AS FLOAT)) AS stdev_min
    FROM adl_flight_times ft
    WHERE ft.eta_utc IS NOT NULL
      AND (ft.ata_utc IS NOT NULL OR ft.ata_runway_utc IS NOT NULL)
      AND ABS(DATEDIFF(MINUTE, ft.eta_utc, COALESCE(ft.ata_runway_utc, ft.ata_utc))) < 120
""")
row = cursor.fetchone()
if row[0] > 0:
    print(f'Flights with ETA and ATA (within 2hr window): {row[0]}')
    print(f'Within 2 min:  {row[1]} ({100*row[1]/row[0]:.1f}%)')
    print(f'Within 5 min:  {row[2]} ({100*row[2]/row[0]:.1f}%)')
    print(f'Within 10 min: {row[3]} ({100*row[3]/row[0]:.1f}%)')
    print(f'Average error: {row[4]:.1f} min (positive = arrived later than ETA)')
    print(f'MAE:           {row[5]:.1f} min')
    print(f'Std Dev:       {row[6]:.1f} min')
else:
    print('No flights with valid ETA/ATA for comparison')

# 4. ETA accuracy BY wind category
print()
print('=== 4. ETA ACCURACY BY WIND ADJUSTMENT ===')
cursor.execute("""
    SELECT
        CASE
            WHEN ft.eta_wind_adj_kts IS NULL THEN 'No Wind'
            WHEN ABS(ft.eta_wind_adj_kts) < 5 THEN 'Minimal (<5kt)'
            WHEN ft.eta_wind_adj_kts >= 5 THEN 'Tailwind (5+kt)'
            WHEN ft.eta_wind_adj_kts <= -5 THEN 'Headwind (5+kt)'
        END AS wind_category,
        COUNT(*) AS flights,
        AVG(CAST(DATEDIFF(MINUTE, ft.eta_utc, COALESCE(ft.ata_runway_utc, ft.ata_utc)) AS FLOAT)) AS avg_error,
        AVG(ABS(CAST(DATEDIFF(MINUTE, ft.eta_utc, COALESCE(ft.ata_runway_utc, ft.ata_utc)) AS FLOAT))) AS mae,
        AVG(ft.eta_wind_adj_kts) AS avg_wind_adj
    FROM adl_flight_times ft
    WHERE ft.eta_utc IS NOT NULL
      AND (ft.ata_utc IS NOT NULL OR ft.ata_runway_utc IS NOT NULL)
      AND ABS(DATEDIFF(MINUTE, ft.eta_utc, COALESCE(ft.ata_runway_utc, ft.ata_utc))) < 120
    GROUP BY
        CASE
            WHEN ft.eta_wind_adj_kts IS NULL THEN 'No Wind'
            WHEN ABS(ft.eta_wind_adj_kts) < 5 THEN 'Minimal (<5kt)'
            WHEN ft.eta_wind_adj_kts >= 5 THEN 'Tailwind (5+kt)'
            WHEN ft.eta_wind_adj_kts <= -5 THEN 'Headwind (5+kt)'
        END
    ORDER BY COUNT(*) DESC
""")
print(f'{"wind_category":<20} {"flights":<10} {"avg_error":<12} {"MAE":<10} {"avg_wind":<10}')
print('-' * 70)
for row in cursor.fetchall():
    avg_err = f'{row[2]:.1f}' if row[2] else '-'
    mae = f'{row[3]:.1f}' if row[3] else '-'
    wind = f'{row[4]:.1f}' if row[4] else '-'
    print(f'{row[0] or "Unknown":<20} {row[1]:<10} {avg_err:<12} {mae:<10} {wind:<10}')

# 5. ETA accuracy BY wind confidence
print()
print('=== 5. ETA ACCURACY BY WIND CONFIDENCE ===')
cursor.execute("""
    SELECT
        CASE
            WHEN ft.eta_wind_confidence >= 0.90 THEN '1-High (Grid)'
            WHEN ft.eta_wind_confidence >= 0.60 THEN '2-Medium (GS-Cruise)'
            WHEN ft.eta_wind_confidence >= 0.40 THEN '3-Low (GS-Other)'
            WHEN ft.eta_wind_confidence IS NOT NULL THEN '4-Very Low'
            ELSE '5-No Wind'
        END AS confidence_tier,
        COUNT(*) AS flights,
        AVG(CAST(DATEDIFF(MINUTE, ft.eta_utc, COALESCE(ft.ata_runway_utc, ft.ata_utc)) AS FLOAT)) AS avg_error,
        AVG(ABS(CAST(DATEDIFF(MINUTE, ft.eta_utc, COALESCE(ft.ata_runway_utc, ft.ata_utc)) AS FLOAT))) AS mae,
        COUNT(CASE WHEN ABS(DATEDIFF(MINUTE, ft.eta_utc, COALESCE(ft.ata_runway_utc, ft.ata_utc))) <= 5 THEN 1 END) * 100.0 / COUNT(*) AS pct_within_5min
    FROM adl_flight_times ft
    WHERE ft.eta_utc IS NOT NULL
      AND (ft.ata_utc IS NOT NULL OR ft.ata_runway_utc IS NOT NULL)
      AND ABS(DATEDIFF(MINUTE, ft.eta_utc, COALESCE(ft.ata_runway_utc, ft.ata_utc))) < 120
    GROUP BY
        CASE
            WHEN ft.eta_wind_confidence >= 0.90 THEN '1-High (Grid)'
            WHEN ft.eta_wind_confidence >= 0.60 THEN '2-Medium (GS-Cruise)'
            WHEN ft.eta_wind_confidence >= 0.40 THEN '3-Low (GS-Other)'
            WHEN ft.eta_wind_confidence IS NOT NULL THEN '4-Very Low'
            ELSE '5-No Wind'
        END
    ORDER BY 1
""")
print(f'{"confidence":<22} {"flights":<10} {"avg_error":<12} {"MAE":<10} {"% within 5min":<15}')
print('-' * 75)
for row in cursor.fetchall():
    avg_err = f'{row[2]:.1f}' if row[2] else '-'
    mae = f'{row[3]:.1f}' if row[3] else '-'
    pct = f'{row[4]:.1f}%' if row[4] else '-'
    print(f'{row[0] or "Unknown":<22} {row[1]:<10} {avg_err:<12} {mae:<10} {pct:<15}')

# 6. Sample flights with good/bad ETA accuracy
print()
print('=== 6. SAMPLE FLIGHTS: ETA vs ACTUAL ===')
cursor.execute("""
    SELECT TOP 15
        c.callsign,
        fp.fp_dept_icao + '->' + fp.fp_dest_icao AS route,
        CAST(ft.eta_wind_adj_kts AS INT) AS wind,
        CAST(ft.eta_wind_confidence * 100 AS INT) AS conf,
        FORMAT(ft.eta_utc, 'HH:mm') AS eta,
        FORMAT(COALESCE(ft.ata_runway_utc, ft.ata_utc), 'HH:mm') AS actual,
        DATEDIFF(MINUTE, ft.eta_utc, COALESCE(ft.ata_runway_utc, ft.ata_utc)) AS error_min
    FROM adl_flight_times ft
    JOIN adl_flight_core c ON c.flight_uid = ft.flight_uid
    JOIN adl_flight_plan fp ON fp.flight_uid = ft.flight_uid
    WHERE ft.eta_utc IS NOT NULL
      AND (ft.ata_utc IS NOT NULL OR ft.ata_runway_utc IS NOT NULL)
      AND ft.eta_wind_adj_kts IS NOT NULL
      AND ABS(DATEDIFF(MINUTE, ft.eta_utc, COALESCE(ft.ata_runway_utc, ft.ata_utc))) < 60
    ORDER BY ABS(ft.eta_wind_adj_kts) DESC
""")
print(f'{"callsign":<12} {"route":<15} {"wind":<6} {"conf":<5} {"eta":<6} {"actual":<8} {"error":<8}')
print('-' * 70)
for row in cursor.fetchall():
    wind = str(row[2]) if row[2] else '-'
    conf = f'{row[3]}%' if row[3] else '-'
    err = f'{row[6]} min' if row[6] else '-'
    print(f'{row[0] or "":<12} {row[1] or "":<15} {wind:<6} {conf:<5} {row[4] or "-":<6} {row[5] or "-":<8} {err:<8}')

conn.close()
print()
print('=' * 80)
print('  ANALYSIS COMPLETE')
print('=' * 80)
