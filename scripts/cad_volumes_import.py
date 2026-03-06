#!/usr/bin/env python3
"""
CAD Airspace Volume Importer

Imports Controller Assignment Display (CAD) airspace capacity volumes
from GeoJSON files into PostGIS sector_boundaries and Azure SQL adl_boundary.

Maps:
  - MinFL/MaxFL -> floor_altitude/ceiling_altitude (existing columns)
  - capacity    -> capacity (new column from migration 010/005)
  - source      -> capacity_source = 'CAD'
  - type        -> capacity_type (MONITOR, ENTRY_RATE, OCCUPANCY)

Usage:
    python3 cad_volumes_import.py --input volumes.geojson [--dry-run] [--verbose]
    python3 cad_volumes_import.py --url https://example.com/cad_volumes.geojson

Options:
    --input FILE    GeoJSON file path
    --url URL       Download GeoJSON from URL
    --dry-run       Show what would be done without writing
    --verbose       Enable verbose output
    --postgis-only  Only update PostGIS, skip Azure SQL
    --azuresql-only Only update Azure SQL, skip PostGIS

Requirements:
    pip install psycopg2-binary pyodbc requests
"""

import json
import sys
import os
import argparse
import logging
from datetime import datetime, timezone

# Optional imports — fail gracefully with clear messages
try:
    import psycopg2
    import psycopg2.extras
    HAS_PSYCOPG2 = True
except ImportError:
    HAS_PSYCOPG2 = False

try:
    import pyodbc
    HAS_PYODBC = True
except ImportError:
    HAS_PYODBC = False

try:
    import requests
    HAS_REQUESTS = True
except ImportError:
    HAS_REQUESTS = False


# Database connection config (from environment or defaults)
POSTGIS_CONFIG = {
    'host': os.getenv('POSTGIS_HOST', 'vatcscc-gis.postgres.database.azure.com'),
    'port': int(os.getenv('POSTGIS_PORT', '5432')),
    'dbname': os.getenv('POSTGIS_DB', 'VATSIM_GIS'),
    'user': os.getenv('POSTGIS_USER', 'GIS_admin'),
    'password': os.getenv('POSTGIS_PASS', 'GIS_Admin_2026!'),
    'sslmode': 'require'
}

AZURESQL_CONFIG = {
    'server': os.getenv('AZURESQL_HOST', 'vatsim.database.windows.net'),
    'database': os.getenv('AZURESQL_DB', 'VATSIM_ADL'),
    'username': os.getenv('AZURESQL_USER', 'jpeterson'),
    'password': os.getenv('AZURESQL_PASS', 'Jhp21012'),
}

# Valid capacity types
VALID_CAPACITY_TYPES = {'MONITOR', 'ENTRY_RATE', 'OCCUPANCY'}

logger = logging.getLogger('cad_import')


def load_geojson(source):
    """Load GeoJSON from file path or URL."""
    if source.startswith('http://') or source.startswith('https://'):
        if not HAS_REQUESTS:
            logger.error("'requests' package required for URL download. pip install requests")
            sys.exit(1)
        logger.info(f"Downloading GeoJSON from {source}...")
        resp = requests.get(source, timeout=30)
        resp.raise_for_status()
        return resp.json()
    else:
        logger.info(f"Loading GeoJSON from {source}...")
        with open(source, 'r', encoding='utf-8') as f:
            return json.load(f)


def parse_features(geojson):
    """Parse GeoJSON features into a list of volume records."""
    features = geojson.get('features', [])
    if not features:
        logger.warning("No features found in GeoJSON")
        return []

    volumes = []
    for feat in features:
        props = feat.get('properties', {})
        geom = feat.get('geometry')

        if not geom:
            continue

        # Extract sector code — try common field names
        sector_code = (
            props.get('sector_code') or
            props.get('sectorCode') or
            props.get('code') or
            props.get('name') or
            props.get('id') or
            ''
        ).strip().upper()

        if not sector_code:
            continue

        # Extract altitude bounds (FL or feet)
        floor_alt = props.get('MinFL') or props.get('min_fl') or props.get('floor_altitude') or props.get('floor')
        ceiling_alt = props.get('MaxFL') or props.get('max_fl') or props.get('ceiling_altitude') or props.get('ceiling')

        if floor_alt is not None:
            floor_alt = int(float(floor_alt))
        if ceiling_alt is not None:
            ceiling_alt = int(float(ceiling_alt))

        # Extract capacity
        capacity = props.get('capacity') or props.get('max_aircraft') or props.get('cap')
        if capacity is not None:
            capacity = int(float(capacity))

        # Capacity type
        cap_type = (
            props.get('capacity_type') or
            props.get('cap_type') or
            'MONITOR'
        ).upper()

        if cap_type not in VALID_CAPACITY_TYPES:
            cap_type = 'MONITOR'

        # Parent ARTCC
        parent_artcc = (
            props.get('parent_artcc') or
            props.get('artcc') or
            props.get('fir') or
            ''
        ).strip().upper()

        # Sector type
        sector_type = (
            props.get('sector_type') or
            props.get('type') or
            'HIGH'
        ).upper()

        volumes.append({
            'sector_code': sector_code,
            'parent_artcc': parent_artcc,
            'sector_type': sector_type,
            'floor_altitude': floor_alt,
            'ceiling_altitude': ceiling_alt,
            'capacity': capacity,
            'capacity_type': cap_type,
            'geometry': geom,
        })

    return volumes


def update_postgis(volumes, dry_run=False):
    """Upsert capacity columns on PostGIS sector_boundaries."""
    if not HAS_PSYCOPG2:
        logger.warning("psycopg2 not available, skipping PostGIS update")
        return {'matched': 0, 'updated': 0, 'unmatched': 0}

    stats = {'matched': 0, 'updated': 0, 'unmatched': 0}

    try:
        conn = psycopg2.connect(**POSTGIS_CONFIG)
        conn.autocommit = False
        cur = conn.cursor()

        for vol in volumes:
            code = vol['sector_code']

            # Try to match existing sector boundary by code
            cur.execute(
                "SELECT sector_id FROM sector_boundaries WHERE UPPER(sector_code) = %s",
                (code,)
            )
            row = cur.fetchone()

            if not row:
                # Try partial match (e.g., ZNY_42 matches ZNY42)
                code_nounderscore = code.replace('_', '')
                cur.execute(
                    "SELECT sector_id FROM sector_boundaries WHERE REPLACE(UPPER(sector_code), '_', '') = %s",
                    (code_nounderscore,)
                )
                row = cur.fetchone()

            if not row:
                stats['unmatched'] += 1
                logger.debug(f"  No PostGIS match for sector {code}")
                continue

            stats['matched'] += 1
            sector_id = row[0]

            if dry_run:
                logger.info(f"  [DRY-RUN] Would update sector {code} (id={sector_id}): "
                           f"capacity={vol['capacity']}, type={vol['capacity_type']}")
                stats['updated'] += 1
                continue

            # Update capacity columns
            updates = []
            params = []

            if vol['capacity'] is not None:
                updates.append("capacity = %s")
                params.append(vol['capacity'])
            updates.append("capacity_type = %s")
            params.append(vol['capacity_type'])
            updates.append("capacity_source = %s")
            params.append('CAD')
            updates.append("updated_at = NOW()")

            # Also update altitude bounds if provided
            if vol['floor_altitude'] is not None:
                updates.append("floor_altitude = %s")
                params.append(vol['floor_altitude'])
            if vol['ceiling_altitude'] is not None:
                updates.append("ceiling_altitude = %s")
                params.append(vol['ceiling_altitude'])

            params.append(sector_id)
            sql = f"UPDATE sector_boundaries SET {', '.join(updates)} WHERE sector_id = %s"
            cur.execute(sql, params)
            stats['updated'] += 1

        if not dry_run:
            conn.commit()

        cur.close()
        conn.close()

    except Exception as e:
        logger.error(f"PostGIS error: {e}")

    return stats


def update_azuresql(volumes, dry_run=False):
    """Update capacity columns on Azure SQL adl_boundary."""
    if not HAS_PYODBC:
        logger.warning("pyodbc not available, skipping Azure SQL update")
        return {'matched': 0, 'updated': 0, 'unmatched': 0}

    stats = {'matched': 0, 'updated': 0, 'unmatched': 0}

    try:
        conn_str = (
            f"DRIVER={{ODBC Driver 18 for SQL Server}};"
            f"SERVER={AZURESQL_CONFIG['server']};"
            f"DATABASE={AZURESQL_CONFIG['database']};"
            f"UID={AZURESQL_CONFIG['username']};"
            f"PWD={AZURESQL_CONFIG['password']};"
            f"Encrypt=yes;TrustServerCertificate=no"
        )
        conn = pyodbc.connect(conn_str)
        cursor = conn.cursor()

        for vol in volumes:
            code = vol['sector_code']

            # Match by boundary_code in adl_boundary
            cursor.execute(
                "SELECT boundary_id FROM dbo.adl_boundary WHERE UPPER(boundary_code) = ?",
                (code,)
            )
            row = cursor.fetchone()

            if not row:
                stats['unmatched'] += 1
                continue

            stats['matched'] += 1
            boundary_id = row[0]

            if dry_run:
                logger.info(f"  [DRY-RUN] Would update adl_boundary {code} (id={boundary_id})")
                stats['updated'] += 1
                continue

            updates = []
            params = []

            if vol['capacity'] is not None:
                updates.append("capacity = ?")
                params.append(vol['capacity'])
            updates.append("capacity_type = ?")
            params.append(vol['capacity_type'])
            updates.append("capacity_source = ?")
            params.append('CAD')

            if vol['floor_altitude'] is not None:
                updates.append("floor_altitude = ?")
                params.append(vol['floor_altitude'])
            if vol['ceiling_altitude'] is not None:
                updates.append("ceiling_altitude = ?")
                params.append(vol['ceiling_altitude'])

            params.append(boundary_id)
            sql = f"UPDATE dbo.adl_boundary SET {', '.join(updates)} WHERE boundary_id = ?"
            cursor.execute(sql, params)
            stats['updated'] += 1

        if not dry_run:
            conn.commit()

        cursor.close()
        conn.close()

    except Exception as e:
        logger.error(f"Azure SQL error: {e}")

    return stats


def main():
    parser = argparse.ArgumentParser(description='CAD Airspace Volume Importer')
    parser.add_argument('--input', help='GeoJSON file path')
    parser.add_argument('--url', help='GeoJSON download URL')
    parser.add_argument('--dry-run', action='store_true', help='Preview changes without writing')
    parser.add_argument('--verbose', action='store_true', help='Verbose output')
    parser.add_argument('--postgis-only', action='store_true', help='Only update PostGIS')
    parser.add_argument('--azuresql-only', action='store_true', help='Only update Azure SQL')
    args = parser.parse_args()

    # Configure logging
    level = logging.DEBUG if args.verbose else logging.INFO
    logging.basicConfig(level=level, format='%(asctime)s %(levelname)s %(message)s')

    if not args.input and not args.url:
        parser.error("Must provide --input or --url")

    source = args.url if args.url else args.input

    print("=" * 60)
    print("  CAD Airspace Volume Importer")
    print(f"  {datetime.now(timezone.utc).strftime('%Y-%m-%d %H:%M:%S UTC')}")
    if args.dry_run:
        print("  MODE: DRY RUN (no writes)")
    print("=" * 60)

    # Load and parse GeoJSON
    geojson = load_geojson(source)
    volumes = parse_features(geojson)

    print(f"\nParsed {len(volumes)} volume records")
    if not volumes:
        print("Nothing to import.")
        return

    # Show summary
    with_capacity = sum(1 for v in volumes if v['capacity'] is not None)
    print(f"  With capacity values: {with_capacity}")
    print(f"  Without capacity: {len(volumes) - with_capacity}")

    # Update PostGIS
    if not args.azuresql_only:
        print(f"\n--- PostGIS (sector_boundaries) ---")
        postgis_stats = update_postgis(volumes, dry_run=args.dry_run)
        print(f"  Matched: {postgis_stats['matched']}")
        print(f"  Updated: {postgis_stats['updated']}")
        print(f"  Unmatched: {postgis_stats['unmatched']}")
    else:
        print("\nPostGIS: SKIPPED (--azuresql-only)")

    # Update Azure SQL
    if not args.postgis_only:
        print(f"\n--- Azure SQL (adl_boundary) ---")
        azuresql_stats = update_azuresql(volumes, dry_run=args.dry_run)
        print(f"  Matched: {azuresql_stats['matched']}")
        print(f"  Updated: {azuresql_stats['updated']}")
        print(f"  Unmatched: {azuresql_stats['unmatched']}")
    else:
        print("\nAzure SQL: SKIPPED (--postgis-only)")

    print(f"\n{'DRY RUN complete' if args.dry_run else 'Import complete'}.")


if __name__ == '__main__':
    main()
