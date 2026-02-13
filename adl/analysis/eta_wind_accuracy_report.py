#!/usr/bin/env python3
"""
ETA Wind Accuracy Analysis Report

Analyzes ETA calculation accuracy with and without wind adjustments.
Run: python eta_wind_accuracy_report.py
"""

import os
import sys
from datetime import datetime

# Database config - same as wind fetcher
DB_CONFIG = {
    'driver': '{ODBC Driver 18 for SQL Server}',
    'server': os.environ.get('WIND_DB_SERVER', 'tcp:vatsim.database.windows.net,1433'),
    'database': os.environ.get('WIND_DB_NAME', 'VATSIM_ADL'),
    'username': os.environ.get('WIND_DB_USER', 'adl_api_user'),
    'password': os.environ.get('WIND_DB_PASSWORD', os.environ.get("ADL_SQL_PASSWORD", "")),
}

def get_connection():
    import pyodbc
    conn_str = (
        f"DRIVER={DB_CONFIG['driver']};"
        f"SERVER={DB_CONFIG['server']};"
        f"DATABASE={DB_CONFIG['database']};"
        f"UID={DB_CONFIG['username']};"
        f"PWD={DB_CONFIG['password']};"
        f"Encrypt=yes;TrustServerCertificate=no;"
    )
    return pyodbc.connect(conn_str, timeout=30)

def query(cursor, sql):
    cursor.execute(sql)
    columns = [col[0] for col in cursor.description]
    rows = cursor.fetchall()
    return columns, rows

def print_table(title, columns, rows):
    print(f"\n{title}")
    print("-" * len(title))

    if not rows:
        print("No data")
        return

    # Calculate column widths
    widths = [len(str(c)) for c in columns]
    for row in rows:
        for i, val in enumerate(row):
            widths[i] = max(widths[i], len(str(val) if val is not None else 'NULL'))

    # Header
    header = "  ".join(str(c).ljust(widths[i]) for i, c in enumerate(columns))
    print(header)
    print("-" * len(header))

    # Rows
    for row in rows:
        line = "  ".join(str(val if val is not None else 'NULL').ljust(widths[i]) for i, val in enumerate(row))
        print(line)

def main():
    print("=" * 80)
    print("  ETA WIND ACCURACY ANALYSIS")
    print(f"  Generated: {datetime.utcnow().strftime('%Y-%m-%d %H:%M:%S')} UTC")
    print("=" * 80)

    conn = get_connection()
    cursor = conn.cursor()

    # 1. DATA AVAILABILITY
    print("\n" + "=" * 80)
    print("  1. DATA AVAILABILITY")
    print("=" * 80)

    cols, rows = query(cursor, """
        SELECT
            (SELECT COUNT(*) FROM adl_flight_times) AS total_flights,
            (SELECT COUNT(*) FROM adl_flight_times WHERE eta_wind_adj_kts IS NOT NULL) AS with_wind_calc,
            (SELECT COUNT(*) FROM adl_flight_times WHERE ABS(ISNULL(eta_wind_adj_kts, 0)) > 5) AS significant_wind,
            (SELECT COUNT(*) FROM adl_flight_core WHERE is_active = 1) AS active_flights
    """)
    print_table("Flight Data Status", cols, rows)

    # 2. WIND CONFIDENCE DISTRIBUTION
    print("\n" + "=" * 80)
    print("  2. WIND CONFIDENCE DISTRIBUTION (Active Flights)")
    print("=" * 80)

    cols, rows = query(cursor, """
        SELECT
            CASE
                WHEN ft.eta_wind_confidence >= 0.90 THEN '1-High (0.90+) Grid-based'
                WHEN ft.eta_wind_confidence >= 0.60 THEN '2-Medium (0.60-0.89) GS-Cruise'
                WHEN ft.eta_wind_confidence >= 0.40 THEN '3-Low (0.40-0.59) GS-Other'
                WHEN ft.eta_wind_confidence IS NOT NULL THEN '4-Very Low (<0.40)'
                ELSE '5-No Wind Calc'
            END AS confidence_tier,
            COUNT(*) AS flights,
            CAST(ROUND(AVG(ft.eta_wind_adj_kts), 1) AS DECIMAL(6,1)) AS avg_wind_adj,
            CAST(ROUND(AVG(ABS(ft.eta_wind_adj_kts)), 1) AS DECIMAL(6,1)) AS avg_abs_wind,
            CAST(MIN(ft.eta_wind_adj_kts) AS INT) AS min_wind,
            CAST(MAX(ft.eta_wind_adj_kts) AS INT) AS max_wind,
            CAST(ROUND(STDEV(ft.eta_wind_adj_kts), 1) AS DECIMAL(6,1)) AS stdev
        FROM adl_flight_times ft
        JOIN adl_flight_core c ON c.flight_uid = ft.flight_uid
        WHERE c.is_active = 1
        GROUP BY
            CASE
                WHEN ft.eta_wind_confidence >= 0.90 THEN '1-High (0.90+) Grid-based'
                WHEN ft.eta_wind_confidence >= 0.60 THEN '2-Medium (0.60-0.89) GS-Cruise'
                WHEN ft.eta_wind_confidence >= 0.40 THEN '3-Low (0.40-0.59) GS-Other'
                WHEN ft.eta_wind_confidence IS NOT NULL THEN '4-Very Low (<0.40)'
                ELSE '5-No Wind Calc'
            END
        ORDER BY 1
    """)
    print_table("Wind Confidence Tiers", cols, rows)

    # 3. WIND BY FLIGHT PHASE
    print("\n" + "=" * 80)
    print("  3. WIND ADJUSTMENT BY FLIGHT PHASE")
    print("=" * 80)

    cols, rows = query(cursor, """
        SELECT
            c.phase,
            COUNT(*) AS flights,
            SUM(CASE WHEN ft.eta_wind_adj_kts IS NOT NULL THEN 1 ELSE 0 END) AS with_wind,
            CAST(ROUND(AVG(ft.eta_wind_adj_kts), 1) AS DECIMAL(6,1)) AS avg_wind,
            CAST(ROUND(AVG(ABS(ft.eta_wind_adj_kts)), 1) AS DECIMAL(6,1)) AS avg_abs_wind,
            CAST(ROUND(AVG(ft.eta_wind_confidence), 2) AS DECIMAL(4,2)) AS avg_conf
        FROM adl_flight_core c
        LEFT JOIN adl_flight_times ft ON ft.flight_uid = c.flight_uid
        WHERE c.is_active = 1
        GROUP BY c.phase
        ORDER BY COUNT(*) DESC
    """)
    print_table("Wind by Phase", cols, rows)

    # 4. THEORETICAL TIME IMPACT
    print("\n" + "=" * 80)
    print("  4. THEORETICAL ETA IMPACT FROM WIND")
    print("=" * 80)

    # Use estimated TAS based on groundspeed minus wind adjustment (reverse calculation)
    # Or default to 450kts for jets in cruise
    cols, rows = query(cursor, """
        SELECT
            CASE
                WHEN time_impact_min IS NULL THEN '0-No calc possible'
                WHEN time_impact_min < -10 THEN '1-Saves >10 min (tailwind)'
                WHEN time_impact_min < -5 THEN '2-Saves 5-10 min'
                WHEN time_impact_min < -2 THEN '3-Saves 2-5 min'
                WHEN time_impact_min <= 2 THEN '4-Minimal (+-2 min)'
                WHEN time_impact_min <= 5 THEN '5-Adds 2-5 min'
                WHEN time_impact_min <= 10 THEN '6-Adds 5-10 min'
                ELSE '7-Adds >10 min (headwind)'
            END AS impact_category,
            COUNT(*) AS flights,
            CAST(ROUND(AVG(time_impact_min), 1) AS DECIMAL(6,1)) AS avg_impact_min,
            CAST(ROUND(AVG(wind_adj), 1) AS DECIMAL(6,1)) AS avg_wind_kts,
            CAST(ROUND(AVG(dist_nm), 0) AS INT) AS avg_dist_nm
        FROM (
            SELECT
                ft.eta_wind_adj_kts AS wind_adj,
                p.dist_to_dest_nm AS dist_nm,
                -- Estimate TAS as GS - wind_adj (reverse the wind effect)
                -- For cruise jets, use 450 as fallback
                CASE
                    WHEN p.dist_to_dest_nm > 50 AND ABS(ft.eta_wind_adj_kts) > 5
                    THEN (p.dist_to_dest_nm / 450.0 -
                          p.dist_to_dest_nm / (450.0 + ft.eta_wind_adj_kts)) * 60
                    ELSE NULL
                END AS time_impact_min
            FROM adl_flight_times ft
            JOIN adl_flight_core c ON c.flight_uid = ft.flight_uid
            JOIN adl_flight_position p ON p.flight_uid = ft.flight_uid
            WHERE c.is_active = 1
              AND c.phase IN ('enroute', 'cruise', 'climbing')
        ) analysis
        GROUP BY
            CASE
                WHEN time_impact_min IS NULL THEN '0-No calc possible'
                WHEN time_impact_min < -10 THEN '1-Saves >10 min (tailwind)'
                WHEN time_impact_min < -5 THEN '2-Saves 5-10 min'
                WHEN time_impact_min < -2 THEN '3-Saves 2-5 min'
                WHEN time_impact_min <= 2 THEN '4-Minimal (+-2 min)'
                WHEN time_impact_min <= 5 THEN '5-Adds 2-5 min'
                WHEN time_impact_min <= 10 THEN '6-Adds 5-10 min'
                ELSE '7-Adds >10 min (headwind)'
            END
        ORDER BY 1
    """)
    print_table("ETA Impact Distribution", cols, rows)

    # 5. WIND GRID STATUS
    print("\n" + "=" * 80)
    print("  5. WIND GRID DATA STATUS")
    print("=" * 80)

    try:
        cols, rows = query(cursor, """
            SELECT
                COUNT(*) AS total_records,
                COUNT(DISTINCT CONCAT(lat, ',', lon)) AS grid_points,
                COUNT(DISTINCT pressure_hpa) AS pressure_levels,
                FORMAT(MIN(valid_time_utc), 'yyyy-MM-dd HH:mm') AS earliest_forecast,
                FORMAT(MAX(valid_time_utc), 'yyyy-MM-dd HH:mm') AS latest_forecast,
                FORMAT(MAX(fetched_utc), 'yyyy-MM-dd HH:mm') AS last_fetched,
                DATEDIFF(HOUR, MAX(fetched_utc), GETUTCDATE()) AS hours_since_fetch
            FROM dbo.wind_grid
        """)
        print_table("Grid-Based Wind Data", cols, rows)

        if rows and rows[0][6] is not None:
            hours = rows[0][6]
            if hours <= 6:
                print("\n  STATUS: FRESH - Grid data updated within 6 hours")
            elif hours <= 12:
                print("\n  STATUS: STALE - Grid data >6 hours old, falling back to GS-based")
            else:
                print("\n  STATUS: OLD - Grid data >12 hours old, run wind fetcher!")
    except Exception as e:
        print(f"  wind_grid table not found or error: {e}")
        print("  Only GS-based wind estimation is available.")

    # 6. SAMPLE FLIGHTS
    print("\n" + "=" * 80)
    print("  6. SAMPLE FLIGHTS WITH SIGNIFICANT WIND IMPACT")
    print("=" * 80)

    cols, rows = query(cursor, """
        SELECT TOP 15
            c.callsign,
            fp.fp_dept_icao + '->' + fp.fp_dest_icao AS route,
            CAST(p.altitude_ft / 100 AS VARCHAR) + '00' AS alt,
            CAST(p.groundspeed_kts AS INT) AS gs,
            CAST(p.groundspeed_kts - ft.eta_wind_adj_kts AS INT) AS est_tas,
            CAST(ft.eta_wind_adj_kts AS INT) AS wind_adj,
            CAST(ft.eta_wind_confidence * 100 AS INT) AS conf_pct,
            CAST(p.dist_to_dest_nm AS INT) AS dist_nm,
            CAST(CASE
                WHEN p.dist_to_dest_nm > 50
                THEN (p.dist_to_dest_nm / 450.0 -
                      p.dist_to_dest_nm / (450.0 + ft.eta_wind_adj_kts)) * 60
                ELSE 0
            END AS DECIMAL(5,1)) AS eta_impact_min,
            FORMAT(ft.eta_utc, 'HH:mm') AS eta
        FROM adl_flight_times ft
        JOIN adl_flight_core c ON c.flight_uid = ft.flight_uid
        JOIN adl_flight_position p ON p.flight_uid = ft.flight_uid
        JOIN adl_flight_plan fp ON fp.flight_uid = ft.flight_uid
        WHERE c.is_active = 1
          AND c.phase IN ('enroute', 'cruise')
          AND ABS(ft.eta_wind_adj_kts) > 15
          AND p.dist_to_dest_nm > 100
        ORDER BY ABS(ft.eta_wind_adj_kts) DESC
    """)
    print_table("Flights with >15kt Wind Adjustment", cols, rows)

    # 7. SUMMARY
    print("\n" + "=" * 80)
    print("  7. SUMMARY STATISTICS")
    print("=" * 80)

    cols, rows = query(cursor, """
        SELECT
            (SELECT COUNT(*) FROM adl_flight_core WHERE is_active = 1) AS active_flights,
            (SELECT COUNT(*) FROM adl_flight_times ft
             JOIN adl_flight_core c ON c.flight_uid = ft.flight_uid
             WHERE c.is_active = 1 AND ft.eta_wind_adj_kts IS NOT NULL) AS with_wind_calc,
            (SELECT COUNT(*) FROM adl_flight_times ft
             JOIN adl_flight_core c ON c.flight_uid = ft.flight_uid
             WHERE c.is_active = 1 AND ABS(ft.eta_wind_adj_kts) > 5) AS wind_applied,
            (SELECT CAST(ROUND(AVG(ABS(ft.eta_wind_adj_kts)), 1) AS DECIMAL(5,1))
             FROM adl_flight_times ft
             JOIN adl_flight_core c ON c.flight_uid = ft.flight_uid
             WHERE c.is_active = 1 AND ft.eta_wind_adj_kts IS NOT NULL) AS avg_abs_wind,
            (SELECT COUNT(*) FROM adl_flight_times ft
             JOIN adl_flight_core c ON c.flight_uid = ft.flight_uid
             WHERE c.is_active = 1 AND ft.eta_wind_confidence >= 0.90) AS grid_based,
            (SELECT COUNT(*) FROM adl_flight_times ft
             JOIN adl_flight_core c ON c.flight_uid = ft.flight_uid
             WHERE c.is_active = 1 AND ft.eta_wind_confidence >= 0.40 AND ft.eta_wind_confidence < 0.90) AS gs_based
    """)
    print_table("Wind Integration Summary", cols, rows)

    # Notes
    print("\n" + "=" * 80)
    print("  ANALYSIS NOTES")
    print("=" * 80)
    print("""
Wind Adjustment Impact on ETA:
- Positive wind_adj (tailwind): Faster ground speed -> Earlier arrival
- Negative wind_adj (headwind): Slower ground speed -> Later arrival
- Only adjustments >5 kts are applied to ETA calculation

Confidence Tiers:
- 0.90+: Grid-based wind from NOAA GFS (requires fetcher running)
- 0.60-0.89: GS-based estimate during cruise (GS - expected TAS)
- 0.40-0.59: GS-based estimate in other phases
- <0.40: Insufficient data

ETA Impact Example:
  500nm flight at 450kts cruise with +50kt tailwind:
  - Without wind: 500/450 = 66.7 min
  - With wind:    500/500 = 60.0 min
  - Difference:   -6.7 min (arrives 6.7 min earlier)
""")

    conn.close()
    print("=" * 80)
    print("  ANALYSIS COMPLETE")
    print("=" * 80)

if __name__ == '__main__':
    main()
