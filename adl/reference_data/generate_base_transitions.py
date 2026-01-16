#!/usr/bin/env python3
"""
ADL Reference Data: Generate Base Transitions from CIFP Data

Creates base transitions for STARs and DPs using authoritative FAA CIFP data
from X-Plane/Navigraph. This replaces the heuristic approach of finding common
route segments.

CIFP data contains explicit route_type markers:
  - route_type=4: Transition-specific (runway or enroute transition legs)
  - route_type=5: Enroute/Common (the shared "base" portion)
  - route_type=6: Enroute transition extensions

For STARs: route_type=5 with "ALL" transition = base arrival route
For DPs: route_type=5 with empty transition = base departure route

Usage: python generate_base_transitions.py [--dry-run] [--cifp-path PATH]
"""

import os
import sys
import re
import glob
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
CIFP_PATH = None
for i, arg in enumerate(sys.argv):
    if arg == '--cifp-path' and i + 1 < len(sys.argv):
        CIFP_PATH = sys.argv[i + 1]

# Default CIFP path
if not CIFP_PATH:
    CIFP_PATH = r"C:\X-Plane 12\Custom Data\CIFP"

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

def parse_cifp_file(filepath):
    """
    Parse a single CIFP .dat file and extract base transition routes.

    Returns dict with:
      stars: {proc_name: [list of fix names in sequence]}
      dps: {proc_name: [list of fix names in sequence]}
    """
    airport = os.path.splitext(os.path.basename(filepath))[0].upper()

    # Collect legs by procedure
    star_legs = defaultdict(list)  # proc_name -> [(seq, fix_name)]
    dp_legs = defaultdict(list)

    try:
        with open(filepath, 'r', encoding='utf-8', errors='ignore') as f:
            for line in f:
                line = line.strip().rstrip(';')
                if not line:
                    continue

                # Only process SID/STAR lines
                if not (line.startswith('SID:') or line.startswith('STAR:')):
                    continue

                fields = line.split(',')
                if len(fields) < 12:
                    continue

                # Parse header: TYPE:SEQ
                type_seq = fields[0]
                match = re.match(r'^(SID|STAR):(\d{3})$', type_seq)
                if not match:
                    continue

                proc_type = match.group(1)
                seq_num = int(match.group(2))
                route_type = int(fields[1]) if fields[1].isdigit() else 0
                proc_name = fields[2].strip()
                transition = fields[3].strip()
                fix_name = fields[4].strip() if len(fields) > 4 else ''

                # Skip invalid procedure names
                if not is_valid_procedure_name(proc_name):
                    continue

                # We want route_type=5 (enroute/common portion)
                # For STARs: transition = "ALL" or empty
                # For DPs: transition = empty
                if route_type != 5:
                    continue

                # Skip legs without a fix (VA/VM/CA legs)
                if not fix_name:
                    continue

                if proc_type == 'STAR':
                    star_legs[proc_name].append((seq_num, fix_name))
                else:
                    dp_legs[proc_name].append((seq_num, fix_name))

    except Exception as e:
        print(f"  Warning: Error reading {filepath}: {e}")
        return {'airport': airport, 'stars': {}, 'dps': {}}

    # Convert leg lists to ordered routes
    stars = {}
    for proc_name, legs in star_legs.items():
        # Sort by sequence number and extract fix names
        legs.sort(key=lambda x: x[0])
        fixes = [fix for seq, fix in legs]
        # Remove consecutive duplicates
        cleaned = []
        for fix in fixes:
            if not cleaned or cleaned[-1] != fix:
                cleaned.append(fix)
        if len(cleaned) >= 2:
            stars[proc_name] = cleaned

    dps = {}
    for proc_name, legs in dp_legs.items():
        legs.sort(key=lambda x: x[0])
        fixes = [fix for seq, fix in legs]
        cleaned = []
        for fix in fixes:
            if not cleaned or cleaned[-1] != fix:
                cleaned.append(fix)
        if len(cleaned) >= 2:
            dps[proc_name] = cleaned

    return {'airport': airport, 'stars': stars, 'dps': dps}

def main():
    print("=== ADL: Generate Base Transitions from CIFP ===")
    if DRY_RUN:
        print("*** DRY RUN MODE - No changes will be made ***")
    print(f"Started at: {datetime.now().strftime('%Y-%m-%d %H:%M:%S')} UTC")
    print(f"CIFP Path: {CIFP_PATH}\n")

    # Check CIFP path exists
    if not os.path.isdir(CIFP_PATH):
        print(f"ERROR: CIFP directory not found: {CIFP_PATH}")
        sys.exit(1)

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
        print("\nRemoving previous cifp_base entries...")
        cursor.execute("DELETE FROM dbo.nav_procedures WHERE source = 'cifp_base'")
        deleted = cursor.rowcount
        conn.commit()
        print(f"  Removed {deleted} previous entries")

    # Get list of CIFP files
    cifp_files = glob.glob(os.path.join(CIFP_PATH, "*.dat"))
    print(f"\nFound {len(cifp_files)} CIFP files to process")

    # Load existing procedure templates (for effective_date, runways)
    print("Loading procedure templates from database...")
    cursor.execute("""
        SELECT procedure_type, airport_icao, procedure_name, runways, effective_date
        FROM dbo.nav_procedures
        WHERE source != 'cifp_base' AND source != 'synthetic_base'
        GROUP BY procedure_type, airport_icao, procedure_name, runways, effective_date
    """)

    templates = {}
    for row in cursor.fetchall():
        key = (row.procedure_type, row.airport_icao.split(',')[0].strip() if row.airport_icao else '', row.procedure_name)
        if key not in templates:
            templates[key] = {
                'runways': row.runways,
                'effective_date': row.effective_date
            }
    print(f"  Loaded {len(templates)} procedure templates")

    # Process all CIFP files
    print("\n=== Processing CIFP Files ===")

    all_stars = {}  # (airport, proc_name) -> [fixes]
    all_dps = {}

    processed = 0
    for filepath in cifp_files:
        result = parse_cifp_file(filepath)
        airport = result['airport']

        for proc_name, fixes in result['stars'].items():
            all_stars[(airport, proc_name)] = fixes

        for proc_name, fixes in result['dps'].items():
            all_dps[(airport, proc_name)] = fixes

        processed += 1
        if processed % 1000 == 0:
            print(f"  Processed {processed}/{len(cifp_files)} files...")

    print(f"\nExtracted {len(all_stars)} STAR base routes")
    print(f"Extracted {len(all_dps)} DP base routes")

    # Track what we've created to avoid duplicates
    created_codes = set()

    # Generate STAR base transitions
    print("\n=== Generating STAR Base Transitions ===")
    star_inserted = 0
    star_skipped = 0

    for (airport, proc_name), fixes in all_stars.items():
        base_fix = extract_base_fix(proc_name)
        base_code = f"{base_fix}.{proc_name}"  # e.g., WATSN.WATSN4

        # Skip if already created for this airport
        unique_key = f"{airport}|{base_code}"
        if unique_key in created_codes:
            star_skipped += 1
            continue

        # Build route string
        route = ' '.join(fixes)

        # Get template for metadata
        template = templates.get(('STAR', airport, proc_name), {
            'runways': None,
            'effective_date': None
        })

        if DRY_RUN:
            if star_inserted < 20:
                print(f"  Would create: {base_code} @ {airport}")
                print(f"    Route: {route[:70]}{'...' if len(route) > 70 else ''}")
            star_inserted += 1
            created_codes.add(unique_key)
        else:
            try:
                cursor.execute("""
                    INSERT INTO dbo.nav_procedures
                    (procedure_type, airport_icao, procedure_name, computer_code, transition_name,
                     full_route, runways, is_active, source, effective_date)
                    VALUES ('STAR', ?, ?, ?, ?, ?, ?, 1, 'cifp_base', ?)
                """, (
                    airport,
                    proc_name,
                    base_code,
                    f"{base_fix} TRANSITION",
                    route,
                    template['runways'],
                    template['effective_date']
                ))
                conn.commit()
                star_inserted += 1
                created_codes.add(unique_key)

                if star_inserted <= 10:
                    print(f"  Created: {base_code} @ {airport}")
                    print(f"    Route: {route[:70]}{'...' if len(route) > 70 else ''}")

            except Exception as e:
                star_skipped += 1
                if star_skipped <= 5:
                    print(f"ERROR inserting {base_code}: {e}")

    print(f"\nSTAR Results:")
    print(f"  {'Would insert' if DRY_RUN else 'Inserted'}: {star_inserted}")
    print(f"  Skipped: {star_skipped}")

    # Generate DP base transitions
    print("\n=== Generating DP Base Transitions ===")
    dp_inserted = 0
    dp_skipped = 0

    for (airport, proc_name), fixes in all_dps.items():
        base_fix = extract_base_fix(proc_name)
        base_code = f"{proc_name}.{base_fix}"  # e.g., DEEZZ5.DEEZZ

        # Skip if already created for this airport
        unique_key = f"{airport}|{base_code}"
        if unique_key in created_codes:
            dp_skipped += 1
            continue

        # Build route string
        route = ' '.join(fixes)

        # Get template for metadata
        template = templates.get(('DP', airport, proc_name), {
            'runways': None,
            'effective_date': None
        })

        if DRY_RUN:
            if dp_inserted < 20:
                print(f"  Would create: {base_code} @ {airport}")
                print(f"    Route: {route[:70]}{'...' if len(route) > 70 else ''}")
            dp_inserted += 1
            created_codes.add(unique_key)
        else:
            try:
                cursor.execute("""
                    INSERT INTO dbo.nav_procedures
                    (procedure_type, airport_icao, procedure_name, computer_code, transition_name,
                     full_route, runways, is_active, source, effective_date)
                    VALUES ('DP', ?, ?, ?, ?, ?, ?, 1, 'cifp_base', ?)
                """, (
                    airport,
                    proc_name,
                    base_code,
                    f"{base_fix} TRANSITION",
                    route,
                    template['runways'],
                    template['effective_date']
                ))
                conn.commit()
                dp_inserted += 1
                created_codes.add(unique_key)

                if dp_inserted <= 10:
                    print(f"  Created: {base_code} @ {airport}")
                    print(f"    Route: {route[:70]}{'...' if len(route) > 70 else ''}")

            except Exception as e:
                dp_skipped += 1
                if dp_skipped <= 5:
                    print(f"ERROR inserting {base_code}: {e}")

    print(f"\nDP Results:")
    print(f"  {'Would insert' if DRY_RUN else 'Inserted'}: {dp_inserted}")
    print(f"  Skipped: {dp_skipped}")

    # Verification
    if not DRY_RUN:
        print("\n=== Verification ===")

        cursor.execute("""
            SELECT computer_code, full_route
            FROM dbo.nav_procedures
            WHERE computer_code = 'WATSN.WATSN4' AND source = 'cifp_base'
        """)
        row = cursor.fetchone()
        if row:
            print(f"WATSN.WATSN4: {row.full_route}")
        else:
            print("WATSN.WATSN4: NOT FOUND")

        cursor.execute("""
            SELECT computer_code, full_route
            FROM dbo.nav_procedures
            WHERE computer_code LIKE 'DEEZZ%.DEEZZ' AND source = 'cifp_base'
        """)
        row = cursor.fetchone()
        if row:
            print(f"{row.computer_code}: {row.full_route}")
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
