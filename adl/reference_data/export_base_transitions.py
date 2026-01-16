#!/usr/bin/env python3
"""
Export CIFP base transitions from database to CSV files for frontend use.

The frontend (procs_enhanced.js) loads procedures from:
- assets/data/star_full_routes.csv
- assets/data/dp_full_routes.csv

This script appends the cifp_base entries to these files.
"""

import os
import sys
import re
from datetime import datetime

script_dir = os.path.dirname(os.path.abspath(__file__))
perti_root = os.path.dirname(os.path.dirname(script_dir))

try:
    import pyodbc
except ImportError:
    print("ERROR: pyodbc not installed. Run: pip install pyodbc")
    sys.exit(1)

def load_config():
    config_path = os.path.join(perti_root, 'load', 'config.php')
    config = {}
    if os.path.exists(config_path):
        with open(config_path, 'r') as f:
            content = f.read()
        pattern = r"define\(['\"](\w+)['\"],\s*['\"]([^'\"]+)['\"]\)"
        for match in re.finditer(pattern, content):
            config[match.group(1)] = match.group(2)
    return config

def get_sql_server_driver():
    drivers = pyodbc.drivers()
    for driver in ['ODBC Driver 18 for SQL Server', 'ODBC Driver 17 for SQL Server',
                   'SQL Server Native Client 11.0', 'SQL Server']:
        if driver in drivers:
            return driver
    return None

def main():
    print("=== Export CIFP Base Transitions to CSV ===")
    print(f"Started at: {datetime.now().strftime('%Y-%m-%d %H:%M:%S')}\n")

    config = load_config()
    driver = get_sql_server_driver()
    if not driver:
        print("ERROR: No SQL Server ODBC driver found")
        sys.exit(1)

    conn_str = (
        f"DRIVER={{{driver}}};"
        f"SERVER={config['ADL_SQL_HOST']};"
        f"DATABASE={config['ADL_SQL_DATABASE']};"
        f"UID={config['ADL_SQL_USERNAME']};"
        f"PWD={config['ADL_SQL_PASSWORD']};"
        f"Encrypt=yes;TrustServerCertificate=yes;"
    )

    conn = pyodbc.connect(conn_str)
    cursor = conn.cursor()
    print("Connected to database.")

    # Get current date for EFF_DATE
    eff_date = datetime.now().strftime('%m/%d/%Y')

    # Export STARs
    star_csv_path = os.path.join(perti_root, 'assets', 'data', 'star_full_routes.csv')
    print(f"\nExporting STARs to: {star_csv_path}")

    cursor.execute("""
        SELECT computer_code, procedure_name, airport_icao, full_route, transition_name
        FROM dbo.nav_procedures
        WHERE source = 'cifp_base' AND procedure_type = 'STAR'
        ORDER BY computer_code
    """)

    star_count = 0
    star_lines = []
    for row in cursor.fetchall():
        computer_code = row.computer_code  # e.g., WATSN.WATSN4
        proc_name = row.procedure_name     # e.g., WATSN4
        airport = row.airport_icao         # e.g., KORD
        route = row.full_route             # e.g., WATSN HAUPO MKITA PRISE...
        trans_name = row.transition_name   # e.g., WATSN TRANSITION

        # Build CSV line
        # Format: EFF_DATE,ARRIVAL_NAME,STAR_COMPUTER_CODE,ARTCC,DEST_GROUP,BODY_NAME,TRANSITION_COMPUTER_CODE,TRANSITION_NAME,ROUTE_POINTS,ROUTE_FROM_DEST_GROUP
        route_with_airport = f"{route} {airport}" if airport else route
        line = f'{eff_date},{proc_name},{computer_code},,{airport},,{computer_code},{trans_name},{route},{route_with_airport}'
        star_lines.append(line)
        star_count += 1

    # Append to CSV
    with open(star_csv_path, 'a', encoding='utf-8') as f:
        f.write('\n')  # Ensure newline after existing content
        f.write('\n'.join(star_lines))

    print(f"  Added {star_count} STAR base transitions")

    # Export DPs
    dp_csv_path = os.path.join(perti_root, 'assets', 'data', 'dp_full_routes.csv')
    print(f"\nExporting DPs to: {dp_csv_path}")

    cursor.execute("""
        SELECT computer_code, procedure_name, airport_icao, full_route, transition_name
        FROM dbo.nav_procedures
        WHERE source = 'cifp_base' AND procedure_type = 'DP'
        ORDER BY computer_code
    """)

    dp_count = 0
    dp_lines = []
    for row in cursor.fetchall():
        computer_code = row.computer_code  # e.g., DEEZZ5.DEEZZ
        proc_name = row.procedure_name     # e.g., DEEZZ5
        airport = row.airport_icao         # e.g., KFRG
        route = row.full_route             # e.g., DEEZZ HEERO
        trans_name = row.transition_name   # e.g., DEEZZ TRANSITION

        # Build CSV line
        # Format: EFF_DATE,DP_NAME,DP_COMPUTER_CODE,ARTCC,ORIG_GROUP,BODY_NAME,TRANSITION_COMPUTER_CODE,TRANSITION_NAME,ROUTE_POINTS,ROUTE_FROM_ORIG_GROUP
        route_with_airport = f"{airport} {route}" if airport else route
        line = f'{eff_date},{proc_name},{computer_code},,{airport},,{computer_code},{trans_name},{route},{route_with_airport}'
        dp_lines.append(line)
        dp_count += 1

    # Append to CSV
    with open(dp_csv_path, 'a', encoding='utf-8') as f:
        f.write('\n')
        f.write('\n'.join(dp_lines))

    print(f"  Added {dp_count} DP base transitions")

    conn.close()

    print(f"\n=== Export Complete ===")
    print(f"Total: {star_count} STARs + {dp_count} DPs = {star_count + dp_count} base transitions")
    print("Refresh the browser to load updated CSV data.")

if __name__ == '__main__':
    main()
