#!/usr/bin/env python3
"""
ADL Reference Data: Generate Synthetic Base Transitions

Creates synthetic base transitions for STARs and DPs where the transition
fix matches the procedure name (e.g., WATSN.WATSN4, DEEZZ5.DEEZZ).

FAA doesn't publish these base transitions, but they're commonly used in
flight plans. This script extracts the common route segments from all
transitions of a procedure to create synthetic base routes.

For STARs: Common SUFFIX of all transitions (routes converge at end)
For DPs: Common PREFIX of all transitions (routes diverge at start)

Usage: python generate_base_transitions.py [--dry-run]
"""

import os
import sys
import re
from datetime import datetime
from collections import defaultdict

# Add parent directories to path for config
script_dir = os.path.dirname(os.path.abspath(__file__))
perti_root = os.path.dirname(os.path.dirname(script_dir))

# Try to import pyodbc for SQL Server connection
try:
    import pyodbc
except ImportError:
    print("ERROR: pyodbc not installed. Run: pip install pyodbc")
    sys.exit(1)

# Configuration
DRY_RUN = '--dry-run' in sys.argv
MIN_COMMON_WAYPOINTS = 2  # Minimum waypoints needed for a valid base route

def load_config():
    """Load database config from config.php"""
    config_path = os.path.join(perti_root, 'load', 'config.php')
    config = {}

    if os.path.exists(config_path):
        with open(config_path, 'r') as f:
            content = f.read()
        pattern = r"define\(['\"](\w+)['\"],\s*['\"]([^'\"]+)['\"]\)"
        for match in re.finditer(pattern, content):
            config[match.group(1)] = match.group(2)

    return config

def is_valid_procedure_name(name):
    """
    Check if this is a valid procedure base name (not a runway transition).
    Valid: WATSN4, ROBUC3, DEEZZ5, JCOBS2
    Invalid: RW03, RW14L, RW26R
    """
    if not name:
        return False
    # Skip runway designators
    if name.startswith('RW'):
        return False
    # Must be 3-7 chars, letters and numbers, ending in a digit
    if not re.match(r'^[A-Z]{2,6}\d{1,2}$', name):
        return False
    return True

def extract_base_fix(proc_name):
    """
    Extract the base fix name from procedure name.
    e.g., WATSN4 -> WATSN, ROBUC3 -> ROBUC, DEEZZ5 -> DEEZZ
    """
    return re.sub(r'\d+$', '', proc_name)

def get_primary_airport(airport_icao):
    """Get first airport if comma-separated"""
    if not airport_icao:
        return None
    return airport_icao.split(',')[0].strip()

def find_common_suffix(routes):
    """
    Find common suffix of routes (for STARs - routes converge at end).
    Returns list of unique common waypoints from the end.
    """
    if not routes or len(routes) < 2:
        return []

    # Convert routes to lists of waypoints (reversed for suffix comparison)
    waypoint_arrays = []
    for route in routes:
        waypoints = route.split()
        # Remove any duplicate consecutive waypoints
        cleaned = []
        for wp in waypoints:
            if not cleaned or cleaned[-1] != wp:
                cleaned.append(wp)
        waypoint_arrays.append(list(reversed(cleaned)))

    if not waypoint_arrays:
        return []

    # Find minimum length
    min_len = min(len(arr) for arr in waypoint_arrays)
    if min_len == 0:
        return []

    # Find common suffix length
    common_len = 0
    for i in range(min_len):
        current = waypoint_arrays[0][i]
        if all(arr[i] == current for arr in waypoint_arrays):
            common_len += 1
        else:
            break

    if common_len == 0:
        return []

    # Return common suffix (un-reversed)
    return list(reversed(waypoint_arrays[0][:common_len]))

def find_common_prefix(routes):
    """
    Find common prefix of routes (for DPs - routes diverge at start).
    Returns list of unique common waypoints from the start.
    """
    if not routes or len(routes) < 2:
        return []

    # Convert routes to lists of waypoints
    waypoint_arrays = []
    for route in routes:
        waypoints = route.split()
        # Remove any duplicate consecutive waypoints
        cleaned = []
        for wp in waypoints:
            if not cleaned or cleaned[-1] != wp:
                cleaned.append(wp)
        waypoint_arrays.append(cleaned)

    if not waypoint_arrays:
        return []

    # Find minimum length
    min_len = min(len(arr) for arr in waypoint_arrays)
    if min_len == 0:
        return []

    # Find common prefix length
    common_len = 0
    for i in range(min_len):
        current = waypoint_arrays[0][i]
        if all(arr[i] == current for arr in waypoint_arrays):
            common_len += 1
        else:
            break

    if common_len == 0:
        return []

    return waypoint_arrays[0][:common_len]

def build_star_base_route(base_fix, common_suffix):
    """Build synthetic base route for a STAR"""
    if not common_suffix or len(common_suffix) < MIN_COMMON_WAYPOINTS:
        return None

    # If base_fix is already anywhere in the suffix, use suffix as-is
    # (STAR routes contain the namesake fix as part of the arrival path)
    if base_fix in common_suffix:
        route = common_suffix
    else:
        # Prepend base_fix if not present
        route = [base_fix] + common_suffix

    # Remove consecutive duplicates
    final = []
    for wp in route:
        if not final or final[-1] != wp:
            final.append(wp)

    if len(final) < 2:
        return None

    return ' '.join(final)

def build_dp_base_route(base_fix, common_prefix):
    """Build synthetic base route for a DP"""
    if not common_prefix or len(common_prefix) < MIN_COMMON_WAYPOINTS:
        return None

    # If base_fix is already anywhere in the prefix, use prefix as-is
    # (DP routes contain the namesake fix as part of the departure path)
    if base_fix in common_prefix:
        route = common_prefix
    else:
        # Append base_fix if not present
        route = common_prefix + [base_fix]

    # Remove consecutive duplicates
    final = []
    for wp in route:
        if not final or final[-1] != wp:
            final.append(wp)

    if len(final) < 2:
        return None

    return ' '.join(final)

def get_sql_server_driver():
    """Find available SQL Server ODBC driver"""
    drivers = pyodbc.drivers()
    for driver in ['ODBC Driver 18 for SQL Server', 'ODBC Driver 17 for SQL Server',
                   'SQL Server Native Client 11.0', 'SQL Server']:
        if driver in drivers:
            return driver
    for driver in drivers:
        if 'SQL Server' in driver:
            return driver
    return None

def main():
    print("=== ADL: Generate Synthetic Base Transitions ===")
    if DRY_RUN:
        print("*** DRY RUN MODE - No changes will be made ***")
    print(f"Started at: {datetime.now().strftime('%Y-%m-%d %H:%M:%S')} UTC\n")

    # Load config
    config = load_config()
    if not config.get('ADL_SQL_HOST') or not config.get('ADL_SQL_DATABASE'):
        print("ERROR: ADL_SQL_* constants not found in config.php")
        sys.exit(1)

    # Find SQL Server driver
    driver = get_sql_server_driver()
    if not driver:
        print("ERROR: No SQL Server ODBC driver found")
        print(f"Available drivers: {pyodbc.drivers()}")
        sys.exit(1)
    print(f"Using ODBC driver: {driver}")

    # Build connection string
    conn_str = (
        f"DRIVER={{{driver}}};"
        f"SERVER={config['ADL_SQL_HOST']};"
        f"DATABASE={config['ADL_SQL_DATABASE']};"
        f"UID={config['ADL_SQL_USERNAME']};"
        f"PWD={config['ADL_SQL_PASSWORD']};"
        f"Encrypt=yes;TrustServerCertificate=yes;"
    )

    try:
        conn = pyodbc.connect(conn_str)
        cursor = conn.cursor()
        print("Connected to ADL database.")
    except Exception as e:
        print(f"ERROR: Connection failed - {e}")
        sys.exit(1)

    # First, clean up any previously generated synthetic transitions
    if not DRY_RUN:
        print("\nRemoving previous synthetic_base entries...")
        cursor.execute("DELETE FROM dbo.nav_procedures WHERE source = 'synthetic_base'")
        deleted = cursor.rowcount
        conn.commit()
        print(f"  Removed {deleted} previous entries")

    # Load all procedures
    print("\nLoading procedures from database...")
    cursor.execute("""
        SELECT procedure_id, procedure_type, airport_icao, procedure_name,
               computer_code, transition_name, full_route, runways,
               is_active, source, effective_date
        FROM dbo.nav_procedures
        WHERE computer_code LIKE '%.%'
          AND source != 'synthetic_base'
        ORDER BY procedure_type, computer_code
    """)

    # Group STARs by (airport, procedure_name) where procedure_name is like WATSN4
    # For STARs: computer_code = TRANS.PROCNAME (e.g., BONNT.WATSN4)
    # For DPs: computer_code = PROCNAME.TRANS (e.g., DEEZZ5.JUDDS)

    star_groups = defaultdict(list)  # (airport, proc_name) -> [full_routes]
    dp_groups = defaultdict(list)

    star_templates = {}  # (airport, proc_name) -> template row
    dp_templates = {}

    for row in cursor.fetchall():
        proc_type = row.procedure_type
        computer_code = row.computer_code
        parts = computer_code.split('.')

        if len(parts) != 2:
            continue

        airport = get_primary_airport(row.airport_icao)
        if not airport:
            continue

        # Database has two formats:
        # STARs: TRANS.STAR_NAME (e.g., MERIT.ROBUC3) or STAR_NAME.TRANS (e.g., WATSN4.BONNT)
        # DPs: DP_NAME.TRANS (e.g., DEEZZ5.JUDDS)
        #
        # Detect which part is the procedure name (ends in digit 1-9)
        part0, part1 = parts
        if proc_type == 'STAR':
            # Check which part looks like a STAR name (ends in digit)
            if is_valid_procedure_name(part1):
                proc_name = part1  # Format: TRANS.STAR_NAME (e.g., MERIT.ROBUC3)
            elif is_valid_procedure_name(part0):
                proc_name = part0  # Format: STAR_NAME.TRANS (e.g., WATSN4.BONNT)
            else:
                continue
        else:
            # DPs always use DP_NAME.TRANS
            proc_name = part0
            if not is_valid_procedure_name(proc_name):
                continue

        key = (airport, proc_name)
        if row.full_route:
            if proc_type == 'STAR':
                star_groups[key].append(row.full_route)
            else:
                dp_groups[key].append(row.full_route)

        template_dict = star_templates if proc_type == 'STAR' else dp_templates
        if key not in template_dict:
            template_dict[key] = {
                'procedure_name': row.procedure_name,
                'runways': row.runways,
                'effective_date': row.effective_date
            }

    print(f"Found {len(star_groups)} unique STAR procedures")
    print(f"Found {len(dp_groups)} unique DP procedures")

    # Track what we've created to avoid duplicates
    created_codes = set()

    # Generate synthetic STAR base transitions
    print("\n=== Generating STAR Base Transitions ===")
    star_inserted = 0
    star_failed = 0

    for (airport, proc_name), routes in star_groups.items():
        base_fix = extract_base_fix(proc_name)
        base_code = f"{base_fix}.{proc_name}"  # e.g., WATSN.WATSN4

        # Skip if already created
        if base_code in created_codes:
            continue

        # Need at least 2 transitions to find common route
        if len(routes) < 2:
            star_failed += 1
            continue

        # Find common suffix
        common_suffix = find_common_suffix(routes)

        # Build synthetic route
        synthetic_route = build_star_base_route(base_fix, common_suffix)
        if not synthetic_route:
            star_failed += 1
            continue

        template = star_templates[(airport, proc_name)]

        if DRY_RUN:
            if star_inserted < 20:
                print(f"  Would create: {base_code} @ {airport}")
                print(f"    Route: {synthetic_route[:70]}...")
            star_inserted += 1
            created_codes.add(base_code)
        else:
            try:
                cursor.execute("""
                    INSERT INTO dbo.nav_procedures
                    (procedure_type, airport_icao, procedure_name, computer_code, transition_name,
                     full_route, runways, is_active, source, effective_date)
                    VALUES ('STAR', ?, ?, ?, ?, ?, ?, 1, 'synthetic_base', ?)
                """, (
                    airport,
                    template['procedure_name'],
                    base_code,
                    f"{base_fix} TRANSITION",
                    synthetic_route,
                    template['runways'],
                    template['effective_date']
                ))
                conn.commit()
                star_inserted += 1
                created_codes.add(base_code)

                if star_inserted <= 10:
                    print(f"  Created: {base_code} @ {airport}")
                    print(f"    Route: {synthetic_route[:70]}...")

            except Exception as e:
                star_failed += 1
                if star_failed <= 5:
                    print(f"ERROR inserting {base_code}: {e}")

    print(f"\nSTAR Results:")
    print(f"  {'Would insert' if DRY_RUN else 'Inserted'}: {star_inserted}")
    print(f"  Failed (insufficient common route): {star_failed}")

    # Generate synthetic DP base transitions
    print("\n=== Generating DP Base Transitions ===")
    dp_inserted = 0
    dp_failed = 0

    for (airport, proc_name), routes in dp_groups.items():
        base_fix = extract_base_fix(proc_name)
        base_code = f"{proc_name}.{base_fix}"  # e.g., DEEZZ5.DEEZZ

        # Skip if already created
        if base_code in created_codes:
            continue

        # Need at least 2 transitions to find common route
        if len(routes) < 2:
            dp_failed += 1
            continue

        # Find common prefix
        common_prefix = find_common_prefix(routes)

        # Build synthetic route
        synthetic_route = build_dp_base_route(base_fix, common_prefix)
        if not synthetic_route:
            dp_failed += 1
            continue

        template = dp_templates[(airport, proc_name)]

        if DRY_RUN:
            if dp_inserted < 20:
                print(f"  Would create: {base_code} @ {airport}")
                print(f"    Route: {synthetic_route[:70]}...")
            dp_inserted += 1
            created_codes.add(base_code)
        else:
            try:
                cursor.execute("""
                    INSERT INTO dbo.nav_procedures
                    (procedure_type, airport_icao, procedure_name, computer_code, transition_name,
                     full_route, runways, is_active, source, effective_date)
                    VALUES ('DP', ?, ?, ?, ?, ?, ?, 1, 'synthetic_base', ?)
                """, (
                    airport,
                    template['procedure_name'],
                    base_code,
                    f"{base_fix} TRANSITION",
                    synthetic_route,
                    template['runways'],
                    template['effective_date']
                ))
                conn.commit()
                dp_inserted += 1
                created_codes.add(base_code)

                if dp_inserted <= 10:
                    print(f"  Created: {base_code} @ {airport}")
                    print(f"    Route: {synthetic_route[:70]}...")

            except Exception as e:
                dp_failed += 1
                if dp_failed <= 5:
                    print(f"ERROR inserting {base_code}: {e}")

    print(f"\nDP Results:")
    print(f"  {'Would insert' if DRY_RUN else 'Inserted'}: {dp_inserted}")
    print(f"  Failed (insufficient common route): {dp_failed}")

    # Verification
    if not DRY_RUN:
        print("\n=== Verification ===")

        cursor.execute("""
            SELECT computer_code, LEFT(full_route, 80) AS route
            FROM dbo.nav_procedures
            WHERE computer_code = 'WATSN.WATSN4' AND source = 'synthetic_base'
        """)
        row = cursor.fetchone()
        if row:
            print(f"WATSN.WATSN4: {row.route}")
        else:
            print("WATSN.WATSN4: NOT FOUND")

        cursor.execute("""
            SELECT computer_code, LEFT(full_route, 80) AS route
            FROM dbo.nav_procedures
            WHERE computer_code LIKE 'DEEZZ%.DEEZZ' AND source = 'synthetic_base'
        """)
        row = cursor.fetchone()
        if row:
            print(f"{row.computer_code}: {row.route}")
        else:
            print("DEEZZ#.DEEZZ: NOT FOUND")

    # Summary
    print(f"\n=== {'Dry Run' if DRY_RUN else 'Import'} Complete ===")
    print(f"Total STAR base transitions: {star_inserted}")
    print(f"Total DP base transitions: {dp_inserted}")
    print(f"Finished at: {datetime.now().strftime('%Y-%m-%d %H:%M:%S')} UTC")

    conn.close()

if __name__ == '__main__':
    main()
