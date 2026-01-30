#!/usr/bin/env python3
"""
PERTI PostGIS Boundary Import Script

Imports GeoJSON boundary files into PostGIS tables for spatial route analysis.

Usage:
    python import_boundaries.py --host localhost --port 5432 --database VATSIM_GIS --user GIS_admin

    # With environment variables (matches other PERTI databases):
    export GIS_SQL_HOST=localhost
    export GIS_SQL_PORT=5432
    export GIS_SQL_DATABASE=VATSIM_GIS
    export GIS_SQL_USERNAME=GIS_admin
    export GIS_SQL_PASSWORD=GIS_Admin_2026!
    python import_boundaries.py

Requirements:
    pip install psycopg2-binary
"""

import json
import os
import sys
import argparse
from pathlib import Path

try:
    import psycopg2
    from psycopg2.extras import execute_values
except ImportError:
    print("Error: psycopg2 not installed. Run: pip install psycopg2-binary")
    sys.exit(1)


# Path to GeoJSON files relative to this script
SCRIPT_DIR = Path(__file__).parent
PROJECT_ROOT = SCRIPT_DIR.parent.parent
GEOJSON_DIR = PROJECT_ROOT / "assets" / "geojson"

# GeoJSON file mappings
GEOJSON_FILES = {
    "artcc": GEOJSON_DIR / "artcc.json",
    "high": GEOJSON_DIR / "high.json",
    "low": GEOJSON_DIR / "low.json",
    "superhigh": GEOJSON_DIR / "superhigh.json",
    "tracon": GEOJSON_DIR / "tracon.json",
}


def get_connection(args):
    """Create database connection from args or environment variables."""
    return psycopg2.connect(
        host=args.host or os.environ.get("GIS_SQL_HOST", "localhost"),
        port=args.port or os.environ.get("GIS_SQL_PORT", 5432),
        database=args.database or os.environ.get("GIS_SQL_DATABASE", "VATSIM_GIS"),
        user=args.user or os.environ.get("GIS_SQL_USERNAME", "GIS_admin"),
        password=args.password or os.environ.get("GIS_SQL_PASSWORD", ""),
    )


def load_geojson(filepath: Path) -> dict:
    """Load and parse a GeoJSON file."""
    print(f"  Loading {filepath.name}...")
    with open(filepath, "r", encoding="utf-8") as f:
        data = json.load(f)
    print(f"    Found {len(data.get('features', []))} features")
    return data


def import_artcc_boundaries(conn, geojson: dict, dry_run: bool = False):
    """Import ARTCC/FIR boundaries from GeoJSON."""
    print("\nImporting ARTCC boundaries...")

    cursor = conn.cursor()

    if not dry_run:
        cursor.execute("TRUNCATE artcc_boundaries RESTART IDENTITY CASCADE;")

    insert_sql = """
        INSERT INTO artcc_boundaries (
            artcc_code, fir_name, icao_code, vatsim_region, vatsim_division,
            vatsim_subdiv, floor_altitude, ceiling_altitude, is_oceanic,
            label_lat, label_lon, geom
        ) VALUES %s
    """

    rows = []
    us_artccs = set()  # Track US ARTCCs for filtering

    for feature in geojson.get("features", []):
        props = feature.get("properties", {})
        geom = feature.get("geometry", {})

        # Extract properties with fallbacks
        artcc_code = props.get("ICAOCODE") or props.get("FIRname", "")
        fir_name = props.get("FIRname", "")

        # Skip non-US ARTCCs if you only want CONUS (optional filter)
        # US ARTCCs start with 'Z' or 'P' (ZNY, ZLA, PHNL, etc.)
        # Uncomment to filter:
        # if not artcc_code.startswith(('Z', 'P', 'C')):  # US + Canada
        #     continue

        # Track US ARTCCs (3-char codes starting with Z)
        if len(artcc_code) == 3 and artcc_code.startswith('Z'):
            us_artccs.add(artcc_code)

        row = (
            artcc_code[:4] if artcc_code else "UNK",
            fir_name[:64] if fir_name else None,
            props.get("ICAOCODE", "")[:4] or None,
            props.get("VATSIM Reg", "")[:16] or None,
            props.get("VATSIM Div", "")[:16] or None,
            props.get("VATSIM Sub", "")[:16] or None,
            props.get("FLOOR"),
            props.get("CEILING"),
            bool(props.get("oceanic", 0)),
            props.get("label_lat"),
            props.get("label_lon"),
            json.dumps(geom),  # Pass as GeoJSON string
        )
        rows.append(row)

    if not dry_run and rows:
        # Use execute_values with a template that converts GeoJSON to geometry
        template = """(
            %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s,
            ST_SetSRID(ST_GeomFromGeoJSON(%s), 4326)
        )"""
        execute_values(cursor, insert_sql, rows, template=template, page_size=100)
        conn.commit()

    print(f"  Imported {len(rows)} ARTCC boundaries")
    print(f"  US ARTCCs found: {sorted(us_artccs)}")

    cursor.close()
    return len(rows)


def import_sector_boundaries(conn, geojson: dict, sector_type: str, dry_run: bool = False):
    """Import sector boundaries from GeoJSON."""
    print(f"\nImporting {sector_type} sector boundaries...")

    cursor = conn.cursor()

    if not dry_run:
        cursor.execute(
            "DELETE FROM sector_boundaries WHERE sector_type = %s;",
            (sector_type.upper(),)
        )

    insert_sql = """
        INSERT INTO sector_boundaries (
            sector_code, sector_name, parent_artcc, sector_type,
            floor_altitude, ceiling_altitude, label_lat, label_lon, geom
        ) VALUES %s
    """

    rows = []

    for feature in geojson.get("features", []):
        props = feature.get("properties", {})
        geom = feature.get("geometry", {})

        # Extract ARTCC code (normalize to uppercase)
        artcc = props.get("artcc", props.get("ARTCC", ""))
        if artcc:
            artcc = artcc.upper()[:4]

        # Build sector code (no underscore - matches ADL adl_boundary format)
        sector = props.get("sector", props.get("SECTOR", ""))
        sector_code = f"{artcc}{sector}" if artcc and sector else sector or "UNK"

        row = (
            sector_code[:16],
            props.get("label", props.get("name", ""))[:64] or None,
            artcc or None,
            sector_type.upper(),
            props.get("floor"),
            props.get("ceiling"),
            props.get("label_lat"),
            props.get("label_lon"),
            json.dumps(geom),
        )
        rows.append(row)

    if not dry_run and rows:
        template = """(
            %s, %s, %s, %s, %s, %s, %s, %s,
            ST_SetSRID(ST_GeomFromGeoJSON(%s), 4326)
        )"""
        execute_values(cursor, insert_sql, rows, template=template, page_size=100)
        conn.commit()

    print(f"  Imported {len(rows)} {sector_type} sector boundaries")

    cursor.close()
    return len(rows)


def import_tracon_boundaries(conn, geojson: dict, dry_run: bool = False):
    """Import TRACON boundaries from GeoJSON."""
    print("\nImporting TRACON boundaries...")

    cursor = conn.cursor()

    if not dry_run:
        cursor.execute("TRUNCATE tracon_boundaries RESTART IDENTITY CASCADE;")

    insert_sql = """
        INSERT INTO tracon_boundaries (
            tracon_code, tracon_name, parent_artcc, sector_code,
            floor_altitude, ceiling_altitude, label_lat, label_lon, geom
        ) VALUES %s
    """

    rows = []

    for feature in geojson.get("features", []):
        props = feature.get("properties", {})
        geom = feature.get("geometry", {})

        # Extract ARTCC code
        artcc = props.get("artcc", props.get("ARTCC", ""))
        if artcc:
            artcc = artcc.upper()[:4]

        sector = props.get("sector", "")
        label = props.get("label", props.get("name", ""))

        # Build TRACON code from artcc or label
        tracon_code = artcc or label[:16] if label else "UNK"

        row = (
            tracon_code[:16],
            label[:64] if label else None,
            artcc or None,
            sector[:16] if sector else None,
            props.get("floor"),
            props.get("ceiling"),
            props.get("label_lat"),
            props.get("label_lon"),
            json.dumps(geom),
        )
        rows.append(row)

    if not dry_run and rows:
        template = """(
            %s, %s, %s, %s, %s, %s, %s, %s,
            ST_SetSRID(ST_GeomFromGeoJSON(%s), 4326)
        )"""
        execute_values(cursor, insert_sql, rows, template=template, page_size=100)
        conn.commit()

    print(f"  Imported {len(rows)} TRACON boundaries")

    cursor.close()
    return len(rows)


def import_airports(conn, dry_run: bool = False):
    """Import sample US airports for ARTCC assignment testing."""
    print("\nImporting sample airports...")

    cursor = conn.cursor()

    # Sample major US airports with coordinates
    airports = [
        ("KJFK", "JFK", "John F Kennedy International", 40.639801, -73.778900, 13, "large_airport", "US", "US-NY"),
        ("KLAX", "LAX", "Los Angeles International", 33.942501, -118.408997, 128, "large_airport", "US", "US-CA"),
        ("KORD", "ORD", "Chicago O'Hare International", 41.978600, -87.904800, 672, "large_airport", "US", "US-IL"),
        ("KDFW", "DFW", "Dallas Fort Worth International", 32.896900, -97.038002, 607, "large_airport", "US", "US-TX"),
        ("KATL", "ATL", "Hartsfield Jackson Atlanta International", 33.636700, -84.428101, 1026, "large_airport", "US", "US-GA"),
        ("KDEN", "DEN", "Denver International", 39.861698, -104.672997, 5431, "large_airport", "US", "US-CO"),
        ("KMCO", "MCO", "Orlando International", 28.429399, -81.309000, 96, "large_airport", "US", "US-FL"),
        ("KSFO", "SFO", "San Francisco International", 37.618999, -122.375000, 13, "large_airport", "US", "US-CA"),
        ("KLAS", "LAS", "Harry Reid International", 36.080101, -115.152000, 2181, "large_airport", "US", "US-NV"),
        ("KMIA", "MIA", "Miami International", 25.793200, -80.290604, 8, "large_airport", "US", "US-FL"),
        ("KSEA", "SEA", "Seattle Tacoma International", 47.449001, -122.308998, 433, "large_airport", "US", "US-WA"),
        ("KPHX", "PHX", "Phoenix Sky Harbor International", 33.437302, -112.007797, 1135, "large_airport", "US", "US-AZ"),
        ("KBOS", "BOS", "General Edward Lawrence Logan International", 42.364300, -71.005203, 20, "large_airport", "US", "US-MA"),
        ("KEWR", "EWR", "Newark Liberty International", 40.692501, -74.168701, 18, "large_airport", "US", "US-NJ"),
        ("KLGA", "LGA", "LaGuardia", 40.777199, -73.872597, 21, "large_airport", "US", "US-NY"),
        ("KIAD", "IAD", "Washington Dulles International", 38.944500, -77.455803, 313, "large_airport", "US", "US-VA"),
        ("KDCA", "DCA", "Ronald Reagan Washington National", 38.852100, -77.037697, 15, "large_airport", "US", "US-VA"),
        ("KPHL", "PHL", "Philadelphia International", 39.871899, -75.241096, 36, "large_airport", "US", "US-PA"),
        ("KSTL", "STL", "St Louis Lambert International", 38.748697, -90.370003, 618, "large_airport", "US", "US-MO"),
        ("KMSP", "MSP", "Minneapolis Saint Paul International", 44.882000, -93.221802, 841, "large_airport", "US", "US-MN"),
        ("KHOU", "HOU", "William P Hobby", 29.645500, -95.278900, 46, "large_airport", "US", "US-TX"),
        ("KIAH", "IAH", "George Bush Intercontinental", 29.984400, -95.341400, 97, "large_airport", "US", "US-TX"),
        ("KSAN", "SAN", "San Diego International", 32.733556, -117.189667, 17, "large_airport", "US", "US-CA"),
        ("KTPA", "TPA", "Tampa International", 27.975500, -82.533200, 26, "large_airport", "US", "US-FL"),
        ("KCLT", "CLT", "Charlotte Douglas International", 35.214000, -80.943139, 748, "large_airport", "US", "US-NC"),
    ]

    if not dry_run:
        # Clear existing airports
        cursor.execute("DELETE FROM airports;")

        insert_sql = """
            INSERT INTO airports (icao_id, iata_id, airport_name, lat, lon, elevation_ft, airport_type, country_code, region_code)
            VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s)
            ON CONFLICT (icao_id) DO UPDATE SET
                iata_id = EXCLUDED.iata_id,
                airport_name = EXCLUDED.airport_name,
                lat = EXCLUDED.lat,
                lon = EXCLUDED.lon,
                elevation_ft = EXCLUDED.elevation_ft,
                updated_at = NOW()
        """

        for airport in airports:
            cursor.execute(insert_sql, airport)

        conn.commit()

        # Refresh ARTCC assignments
        print("  Refreshing ARTCC assignments...")
        cursor.execute("SELECT * FROM refresh_airport_artccs();")
        result = cursor.fetchone()
        if result:
            print(f"    Updated {result[0]} airports, {result[1]} without ARTCC")

    print(f"  Imported {len(airports)} sample airports")

    cursor.close()
    return len(airports)


def verify_import(conn):
    """Verify import by running test queries."""
    print("\n" + "=" * 60)
    print("VERIFICATION")
    print("=" * 60)

    cursor = conn.cursor()

    # Count records
    cursor.execute("SELECT * FROM boundary_stats;")
    print("\nTable Statistics:")
    for row in cursor.fetchall():
        print(f"  {row[0]}: {row[1]} rows, {row[2]} total")

    # Test route query - JFK to LAX approximate
    print("\nTest Query: JFK to LAX route (approximate waypoints)")
    test_query = """
        SELECT artcc_code, fir_name, ROUND(traversal_order::numeric, 3) as position
        FROM get_route_artccs_from_waypoints(
            '[
                {"lon": -73.78, "lat": 40.64},
                {"lon": -75.5, "lat": 40.0},
                {"lon": -78.0, "lat": 38.5},
                {"lon": -84.0, "lat": 35.5},
                {"lon": -90.0, "lat": 35.0},
                {"lon": -97.0, "lat": 33.0},
                {"lon": -105.0, "lat": 33.0},
                {"lon": -112.0, "lat": 34.0},
                {"lon": -118.41, "lat": 33.94}
            ]'::jsonb
        );
    """

    try:
        cursor.execute(test_query)
        results = cursor.fetchall()
        print("  ARTCCs traversed:")
        for code, name, pos in results:
            print(f"    {code} ({name or 'N/A'}) at position {pos}")
    except Exception as e:
        print(f"  Query failed (may need US ARTCC data): {e}")

    # Test airport ARTCC assignments
    print("\nAirport ARTCC Assignments:")
    try:
        cursor.execute("""
            SELECT icao_id, airport_name, parent_artcc, parent_tracon
            FROM airports
            WHERE parent_artcc IS NOT NULL
            ORDER BY icao_id
            LIMIT 10
        """)
        results = cursor.fetchall()
        for icao, name, artcc, tracon in results:
            print(f"  {icao}: {artcc or 'N/A'} (TRACON: {tracon or 'N/A'})")
    except Exception as e:
        print(f"  Airport query failed: {e}")

    # Test TMI route analysis
    print("\nTest Query: DFW to MCO route (TMI analysis)")
    tmi_test_query = """
        SELECT * FROM analyze_tmi_route(
            '[
                {"lon": -97.038, "lat": 32.897},
                {"lon": -95.5, "lat": 32.5},
                {"lon": -92.0, "lat": 31.5},
                {"lon": -88.0, "lat": 30.5},
                {"lon": -84.0, "lat": 29.5},
                {"lon": -81.309, "lat": 28.429}
            ]'::jsonb,
            'KDFW',
            'KMCO',
            35000
        );
    """
    try:
        cursor.execute(tmi_test_query)
        result = cursor.fetchone()
        if result:
            print(f"  Facilities traversed: {result[0]}")
            print(f"  ARTCCs: {result[1]}")
            print(f"  Origin ARTCC: {result[4]}, Dest ARTCC: {result[5]}")
    except Exception as e:
        print(f"  TMI analysis query failed: {e}")

    cursor.close()


def main():
    parser = argparse.ArgumentParser(description="Import GeoJSON boundaries into PostGIS")
    parser.add_argument("--host", help="PostgreSQL host")
    parser.add_argument("--port", type=int, help="PostgreSQL port")
    parser.add_argument("--database", help="Database name")
    parser.add_argument("--user", help="Database user")
    parser.add_argument("--password", help="Database password")
    parser.add_argument("--dry-run", action="store_true", help="Parse files without importing")
    parser.add_argument("--skip-schema", action="store_true", help="Skip running schema migration")
    args = parser.parse_args()

    print("=" * 60)
    print("PERTI PostGIS Boundary Import")
    print("=" * 60)

    # Check files exist
    print("\nChecking GeoJSON files...")
    for name, path in GEOJSON_FILES.items():
        if path.exists():
            size_mb = path.stat().st_size / (1024 * 1024)
            print(f"  {name}: {path.name} ({size_mb:.1f} MB)")
        else:
            print(f"  {name}: NOT FOUND at {path}")

    if args.dry_run:
        print("\n[DRY RUN MODE - No database changes will be made]")

    # Connect to database
    print("\nConnecting to database...")
    try:
        conn = get_connection(args)
        print("  Connected successfully")
    except Exception as e:
        print(f"  Connection failed: {e}")
        sys.exit(1)

    # Run schema migrations if needed
    if not args.skip_schema and not args.dry_run:
        print("\nRunning schema migrations...")
        migrations_dir = SCRIPT_DIR.parent.parent / "database" / "migrations" / "postgis"
        schema_files = [
            "001_boundaries_schema.sql",
            "002_extended_functions.sql",
            "003_airports_table.sql",
        ]

        cursor = conn.cursor()
        for schema_file in schema_files:
            schema_path = migrations_dir / schema_file
            if schema_path.exists():
                print(f"  Running {schema_file}...")
                with open(schema_path, "r", encoding="utf-8") as f:
                    cursor.execute(f.read())
                conn.commit()
            else:
                print(f"  Skipping {schema_file} (not found)")
        cursor.close()
        print("  Schema migrations complete")

    # Import each file
    total = 0

    # ARTCC boundaries
    if GEOJSON_FILES["artcc"].exists():
        geojson = load_geojson(GEOJSON_FILES["artcc"])
        total += import_artcc_boundaries(conn, geojson, args.dry_run)

    # Sector boundaries
    for sector_type in ["high", "low", "superhigh"]:
        if GEOJSON_FILES[sector_type].exists():
            geojson = load_geojson(GEOJSON_FILES[sector_type])
            total += import_sector_boundaries(conn, geojson, sector_type, args.dry_run)

    # TRACON boundaries
    if GEOJSON_FILES["tracon"].exists():
        geojson = load_geojson(GEOJSON_FILES["tracon"])
        total += import_tracon_boundaries(conn, geojson, args.dry_run)

    # Sample airports (for testing ARTCC assignments)
    total += import_airports(conn, args.dry_run)

    print(f"\n{'Would import' if args.dry_run else 'Imported'} {total} total records (boundaries + airports)")

    # Verify
    if not args.dry_run:
        verify_import(conn)

    conn.close()
    print("\nDone!")


if __name__ == "__main__":
    main()
