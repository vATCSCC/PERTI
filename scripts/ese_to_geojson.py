#!/usr/bin/env python3
"""
ESE to GeoJSON converter — extracts FIR sector boundaries from EuroScope .ese files.

Parses SECTORLINE/COORD definitions and SECTOR/BORDER references,
chains sectorlines into closed polygons, classifies by altitude tier
(low/high/superhigh), and outputs GeoJSON FeatureCollections matching
the format used by PERTI's ARTCC sector boundary layers.

Usage:
    python3 ese_to_geojson.py <ese_file> <output_dir> [--fir CODE]

Examples:
    python3 ese_to_geojson.py C:/Temp/CZYZ.ese assets/data/sectors/CZYZ/
    python3 ese_to_geojson.py C:/Temp/CZWG.ese assets/data/sectors/CZWG/ --fir CZWG
"""

import re
import json
import math
import sys
import os
import argparse
import io
from collections import defaultdict
from datetime import datetime, timezone


# ─── Coordinate parsing ─────────────────────────────────────────────

def parse_dms(coord_str):
    """Parse DMS coordinate like N047.05.00.000 or W087.00.00.000 to decimal degrees."""
    coord_str = coord_str.strip().upper()
    m = re.match(r'([NSEW])(\d+)\.(\d+)\.(\d+)\.(\d+)', coord_str)
    if not m:
        return None
    hemi, deg, mins, secs, frac = m.groups()
    decimal = int(deg) + int(mins) / 60.0 + (int(secs) + int(frac) / 1000.0) / 3600.0
    if hemi in ('S', 'W'):
        decimal = -decimal
    return decimal


def parse_coord_line(line):
    """Parse a COORD: line into (lat, lon) decimal degrees."""
    parts = line.split(':')
    if len(parts) < 3:
        return None
    lat = parse_dms(parts[1])
    lon = parse_dms(parts[2])
    if lat is None or lon is None:
        return None
    return (lat, lon)


# ─── Circle generation ──────────────────────────────────────────────

AIRPORT_COORDS = {
    # CZYZ (Toronto FIR)
    'CYYZ': (43.6772, -79.6306), 'CYXU': (43.0356, -81.1539),
    'CYHM': (43.1736, -79.9350), 'CYKF': (43.4608, -80.3847),
    'CYTZ': (43.6275, -79.3962), 'CYOO': (43.9228, -78.8950),
    'CYTR': (44.1189, -77.5281), 'CYAM': (46.4850, -84.5094),
    'CYQG': (42.2756, -82.9556), 'CYSN': (42.7706, -79.1717),
    'CYGK': (44.2253, -76.5969), 'CYYB': (46.3636, -79.4228),
    'CYSB': (46.6250, -80.7989), 'CYTS': (48.5697, -81.3767),
    'CYMO': (51.2911, -80.6078), 'CYQA': (44.9747, -79.3033),
    'CYOW': (45.3225, -75.6692),
    # CZWG (Winnipeg FIR)
    'CYWG': (49.9100, -97.2398), 'CYQR': (50.4319, -104.6658),
    'CYXE': (52.1708, -106.6997), 'CYQT': (48.3719, -89.3239),
    'CYMJ': (50.3303, -105.5592), 'CYAV': (50.0564, -97.0325),
    'CYPG': (49.9031, -97.3472), 'CYRL': (51.0667, -93.7931),
    'CYQW': (50.2753, -107.6844), 'CYPA': (53.2142, -105.6728),
    'CYBR': (49.9108, -99.9519), 'CYBL': (51.3894, -102.7822),
    'CYDN': (51.1008, -100.0525), 'CYKJ': (50.7106, -101.7583),
    # CZUL (Montreal FIR)
    'CYBG': (48.3306, -70.9964), 'CYHU': (45.5175, -73.4169),
    'CYJN': (45.2944, -73.2811), 'CYMX': (45.6795, -74.0387),
    'CYQB': (46.7911, -71.3933), 'CYRC': (46.3528, -72.6794),
    'CYUL': (45.4706, -73.7408),
    # CZQM (Moncton FIR)
    'CYHZ': (44.8808, -63.5086), 'CYSJ': (45.3161, -65.8903),
    'CYFC': (45.8689, -66.5372), 'CYQM': (46.1122, -64.6786),
    'CYZX': (44.9844, -64.9169),
    # CZQX (Gander FIR)
    'CYQX': (48.9369, -54.5681), 'CYYT': (47.6186, -52.7519),
    'CYYR': (53.3192, -60.4258), 'LFVP': (46.7628, -56.1731),
    'CYDF': (49.2108, -57.3914),
}


def generate_circle(center_lat, center_lon, radius_nm, num_points=64):
    """Generate polygon coordinates for a circle at given center and radius (nm)."""
    coords = []
    radius_deg = radius_nm / 60.0
    for i in range(num_points + 1):
        angle = 2 * math.pi * i / num_points
        lat = center_lat + radius_deg * math.cos(angle)
        lon = center_lon + radius_deg * math.sin(angle) / math.cos(math.radians(center_lat))
        coords.append((lat, lon))
    return coords


# ─── ESE Parser ─────────────────────────────────────────────────────

def parse_ese(filepath):
    """Parse an ESE file and return sectorlines and sectors."""
    sectorlines = {}
    sectors = []

    with open(filepath, 'rb') as f:
        raw = f.read()
    # Detect encoding: if file uses 0xB7 (middle dot) as field separator, it's Latin-1
    encoding = 'latin-1' if b'\xb7' in raw else 'utf-8'
    lines = raw.decode(encoding, errors='replace').splitlines(keepends=True)

    in_airspace = False
    current_sectorline = None
    current_sector = None

    for raw_line in lines:
        line = raw_line.strip()

        # Skip empty lines
        if not line:
            continue

        # Comments — ;- is a sector separator
        if line.startswith(';'):
            if current_sector and line.startswith(';-'):
                sectors.append(current_sector)
                current_sector = None
            continue

        # Section headers
        if line == '[AIRSPACE]':
            in_airspace = True
            continue
        if line.startswith('[') and line != '[AIRSPACE]':
            if in_airspace and current_sector:
                sectors.append(current_sector)
                current_sector = None
            in_airspace = False
            current_sectorline = None
            continue

        if not in_airspace:
            continue

        # SECTORLINE
        if line.startswith('SECTORLINE:'):
            if current_sector:
                sectors.append(current_sector)
                current_sector = None
            name = line.split(':', 1)[1].strip()
            current_sectorline = name
            sectorlines[name] = []
            continue

        # COORD
        if line.startswith('COORD:') and current_sectorline:
            coord = parse_coord_line(line)
            if coord:
                sectorlines[current_sectorline].append(coord)
            continue

        # DISPLAY (metadata within sectorline, skip)
        if line.startswith('DISPLAY:'):
            continue

        # CIRCLE_SECTORLINE
        if line.startswith('CIRCLE_SECTORLINE:'):
            if current_sector:
                sectors.append(current_sector)
                current_sector = None
            parts = line.split(':')
            if len(parts) >= 4:
                name = parts[1].strip()
                airport = parts[2].strip()
                try:
                    radius_nm = float(parts[3].strip())
                except ValueError:
                    continue
                if airport in AIRPORT_COORDS:
                    lat, lon = AIRPORT_COORDS[airport]
                    sectorlines[name] = generate_circle(lat, lon, radius_nm)
            current_sectorline = None
            continue

        # SECTOR
        if line.startswith('SECTOR:'):
            if current_sector:
                sectors.append(current_sector)
            current_sectorline = None
            parts = line.split(':')
            raw_name = parts[1].strip() if len(parts) > 1 else ''
            floor_ft = int(parts[2]) if len(parts) > 2 and parts[2].strip().lstrip('-').isdigit() else 0
            ceiling_ft = int(parts[3]) if len(parts) > 3 and parts[3].strip().lstrip('-').isdigit() else 0

            # Handle middle-dot (·) delimited names: {ARTCC}·{name}·{floor_hundreds}·{ceiling_hundreds}
            artcc_owner = None
            if '\xb7' in raw_name:
                dot_parts = raw_name.split('\xb7')
                artcc_owner = dot_parts[0].strip()
                raw_name = dot_parts[1].strip() if len(dot_parts) > 1 else raw_name

            current_sector = {
                'name': raw_name,
                'artcc_owner': artcc_owner,
                'floor_ft': floor_ft,
                'ceiling_ft': ceiling_ft,
                'owner': [],
                'borders': [],
                'active': [],
            }
            continue

        # Sector sub-lines
        if current_sector:
            if line.startswith('OWNER:'):
                current_sector['owner'] = [x.strip() for x in line.split(':')[1:] if x.strip()]
            elif line.startswith('BORDER:'):
                border_refs = [x.strip() for x in line.split(':')[1:] if x.strip()]
                current_sector['borders'].append(border_refs)
            elif line.startswith('ACTIVE:'):
                current_sector['active'].append(line.split(':', 1)[1].strip())
            # Skip ALTOWNER, DEACTIVE, ARRAPT, DEPAPT, COPX, COPN

    if current_sector:
        sectors.append(current_sector)

    return sectorlines, sectors


# ─── Polygon assembly ───────────────────────────────────────────────

def distance_sq(p1, p2):
    return (p1[0] - p2[0]) ** 2 + (p1[1] - p2[1]) ** 2


def chain_sectorlines(border_refs, sectorlines):
    """Chain sectorline references into a single coordinate ring."""
    all_coords = []
    prev_end = None

    for ref in border_refs:
        coords = sectorlines.get(ref)
        if not coords or len(coords) == 0:
            continue

        if prev_end is None:
            all_coords.extend(coords)
            prev_end = coords[-1]
        else:
            d_fwd = distance_sq(prev_end, coords[0])
            d_rev = distance_sq(prev_end, coords[-1])

            if d_rev < d_fwd:
                coords = list(reversed(coords))

            if distance_sq(prev_end, coords[0]) < 1e-8:
                all_coords.extend(coords[1:])
            else:
                all_coords.extend(coords)

            prev_end = all_coords[-1]

    # Close ring
    if len(all_coords) >= 3:
        if distance_sq(all_coords[0], all_coords[-1]) > 1e-8:
            all_coords.append(all_coords[0])

    return all_coords


# ─── Altitude tier classification ───────────────────────────────────

def classify_tier(floor_ft, ceiling_ft):
    """
    Classify a sector into low/high/superhigh based on altitude.
    Canadian FIR tiers (approximate):
      Low:       0 to FL230 (23,000 ft)
      High:      FL240 to FL350-360
      Superhigh: FL370+
    """
    if ceiling_ft <= 23500:
        return 'low'
    if floor_ft >= 34500:
        return 'superhigh'
    if floor_ft >= 23500:
        return 'high'
    # Spans low and high — classify by where the bulk of the range is
    if ceiling_ft <= 36500:
        return 'low' if floor_ft == 0 else 'high'
    return 'high'


# ─── Sector filtering ───────────────────────────────────────────────

# Runway config pattern: "05/06", "23/24", "33", "15" as prefix or embedded
RWY_CONFIG_RE = re.compile(r'(?:^|\s)(?:05/06|23/24|33|15)(?:\s|$)')

SKIP_SUFFIXES = ('_TWR', '_GND', '_RMP', '_DEL', '_APP', '_DEP', '_TML', '_MF')

# Terminal keyword patterns (CZWG uses "WINNIPEG TOWER", "REGINA GROUND", etc.)
TERMINAL_KEYWORDS = ('DELIVERY', 'GROUND', 'TOWER', 'TCA', 'ARRIVAL', 'DEPARTURE',
                     'CORRIDOR', 'OCA FIR', 'CLASS_A', 'CLASS_C', 'CLASS_D', 'CLASS_E',
                     'RADIO', 'UNICOM', 'NO-CONTROL', 'TRANSITION')

# Patterns for terminal/approach sectors to skip (airport-specific positions)
# Matches: CY**_GND, CY**_TWR, CY**_APP, CYVR_L_APP, etc.
AIRPORT_TERMINAL_RE = re.compile(r'(?:CY|LF)[A-Z]{2}_(?:GND|TWR|DEL|RMP|APP|DEP|TML|MF|CZ|FI_APP)', re.IGNORECASE)
# Control zone patterns: CYBG_CZ, CYFB_CZ, etc.
CONTROL_ZONE_RE = re.compile(r'(?:CY|LF)[A-Z]{2}_CZ\b', re.IGNORECASE)
# Approach box/corridor patterns: AN_BOX_06, AS_CORR1_06, etc.
APPROACH_BOX_RE = re.compile(r'(?:AN|AS)_(?:BOX|CORR)', re.IGNORECASE)
# Runway config specific: names containing (08), (26), etc.
RUNWAY_CONFIG_PAREN_RE = re.compile(r'\(\d+\)')

# Adjacent FIR prefixes to skip, keyed by own FIR code
ADJACENT_FIRS = {
    'CZYZ': ['ZOB', 'ZMP', 'ZBW'],
    'CZWG': ['CZEG', 'CZUL', 'CZYZ', 'ZLC', 'ZMP', 'ZSC'],
    'CZEG': ['CZWG', 'CZUL', 'CZVR', 'PAZA', 'ZLC', 'ZSE', 'CZQX', 'CZQXO', 'BGGL', 'BIRD'],
    'CZUL': ['CZYZ', 'CZWG', 'CZEG', 'CZQX', 'CZQXO', 'ZBW', 'BGGL', 'BIRD', 'CZVR'],
    'CZVR': ['CZEG', 'ZSE', 'PAZA', 'ZAK'],
    'CZQM': ['CZUL', 'CZQX', 'CZQXO', 'ZBW', 'BGGL', 'BIRD', 'CZEG'],
    'CZQX': ['CZQM', 'CZQXO', 'CZUL', 'ZBW', 'BGGL', 'BIRD', 'CZEG', 'ZWY'],
}

# All known Canadian/US FIR prefixes for generic adjacency detection
ALL_FIR_PREFIXES = ('CZEG', 'CZUL', 'CZYZ', 'CZWG', 'CZVR', 'CZQX', 'CZQXO', 'CZQM', 'CZQQ',
                    'ZOB', 'ZMP', 'ZBW', 'ZLC', 'ZSC', 'ZSE', 'ZAU', 'ZNY', 'ZAK', 'ZWY',
                    'PAZA', 'BGGL', 'BIRD')


def should_include_sector(name, fir_code='CZYZ', artcc_owner=None):
    """Determine if a sector should be included in the output."""
    upper = name.upper()

    # If the sector has an artcc_owner from middle-dot format, skip if it belongs to another FIR
    if artcc_owner and artcc_owner.upper() != fir_code.upper():
        return False

    # Strip the FIR prefix if the sector name starts with it (e.g., "CZEG_BS" → "BS")
    display_name = upper
    if upper.startswith(fir_code + '_'):
        display_name = upper[len(fir_code) + 1:]

    # Skip terminal positions (suffix-based: _TWR, _GND, _DEP, _TML, _MF, etc.)
    if any(upper.endswith(s) or s + ':' in upper for s in SKIP_SUFFIXES):
        return False

    # Skip terminal positions (keyword-based: "WINNIPEG TOWER", "REGINA GROUND", etc.)
    if any(kw in upper for kw in TERMINAL_KEYWORDS):
        return False

    # Skip airport-specific terminal sectors (CY**_GND, CY**_TWR, etc.)
    if AIRPORT_TERMINAL_RE.search(upper):
        return False

    # Skip control zone sectors (CYBG_CZ, CYFB_CZ, etc.)
    if CONTROL_ZONE_RE.search(upper):
        return False

    # Skip approach box/corridor sectors (AN_BOX_06, AS_CORR1_06, etc.)
    if APPROACH_BOX_RE.search(display_name):
        return False

    # Skip runway config specific sectors with parenthesized configs: (08), (26)
    if RUNWAY_CONFIG_PAREN_RE.search(name):
        return False

    # Skip adjacent FIR delegated sectors
    skip_prefixes = ADJACENT_FIRS.get(fir_code, [])
    if any(name.startswith(p) for p in skip_prefixes):
        return False

    # Generic: skip any sector prefixed with a known FIR code that isn't ours
    for prefix in ALL_FIR_PREFIXES:
        if prefix != fir_code and (name.startswith(prefix + '_') or upper.startswith(prefix)):
            return False

    # Skip runway-config-specific approach sectors (old pattern)
    if RWY_CONFIG_RE.search(name):
        return False

    return True


def extract_sector_code(name):
    """Extract the base sector code from a sector name like 'RA 240-340' → 'RA'."""
    # Remove altitude range suffixes like " 240-340", " 0-230", " 110"
    base = re.sub(r'\s+\d+[-/]?\d*$', '', name).strip()
    # Remove further altitude qualifiers
    base = re.sub(r'\s+\d+-\d+$', '', base).strip()
    # The code is the first token
    code = base.split()[0] if base else name
    return code, base


# ─── GeoJSON output ─────────────────────────────────────────────────

def to_geojson_coord(lat, lon):
    return [round(lon, 6), round(lat, 6)]


def polygon_centroid(ring):
    """Compute the centroid of a polygon ring [(lat, lon), ...]."""
    if not ring:
        return None, None
    lats = [p[0] for p in ring]
    lons = [p[1] for p in ring]
    return round(sum(lats) / len(lats), 6), round(sum(lons) / len(lons), 6)


def build_geojson(sectors, sectorlines, fir_code='CZYZ'):
    """Build three GeoJSON FeatureCollections: low, high, superhigh."""
    tiers = {'low': [], 'high': [], 'superhigh': []}
    stats = {'included': 0, 'skipped_filter': 0, 'skipped_no_border': 0,
             'skipped_bad_geom': 0, 'missing_sectorline': set()}

    for sector in sectors:
        name = sector['name']
        artcc_owner = sector.get('artcc_owner')

        if not should_include_sector(name, fir_code, artcc_owner):
            stats['skipped_filter'] += 1
            continue

        if not sector['borders']:
            stats['skipped_no_border'] += 1
            continue

        tier = classify_tier(sector['floor_ft'], sector['ceiling_ft'])

        # Strip FIR prefix from sector name for code extraction (e.g., "CZEG_BS" → "BS")
        display_name = name
        if name.upper().startswith(fir_code + '_'):
            display_name = name[len(fir_code) + 1:]

        code, base_name = extract_sector_code(display_name)
        owner_primary = sector['owner'][0] if sector['owner'] else ''

        # Build polygon(s) from all BORDER lines for this sector definition
        polygons = []
        for border_refs in sector['borders']:
            # Track missing sectorlines
            for ref in border_refs:
                if ref not in sectorlines:
                    stats['missing_sectorline'].add(ref)

            coords = chain_sectorlines(border_refs, sectorlines)
            if len(coords) < 4:
                stats['skipped_bad_geom'] += 1
                continue
            ring = [to_geojson_coord(lat, lon) for lat, lon in coords]
            polygons.append(ring)

        if not polygons:
            continue

        # Compute centroid from first polygon's raw coords for label placement
        first_border = sector['borders'][0] if sector['borders'] else []
        raw_coords = chain_sectorlines(first_border, sectorlines) if first_border else []
        label_lat, label_lon = polygon_centroid(raw_coords) if raw_coords else (None, None)

        props = {
            'artcc': fir_code,
            'sector': code,
            'label': f'{fir_code}{code}',
            'name': display_name,
            'floor': sector['floor_ft'],
            'ceiling': sector['ceiling_ft'],
            'label_lat': label_lat,
            'label_lon': label_lon,
            'owner_primary': owner_primary,
            'owner_chain': ':'.join(sector['owner']),
            'source': f'{fir_code}.ese',
        }

        if len(polygons) == 1:
            geometry = {'type': 'Polygon', 'coordinates': [polygons[0]]}
        else:
            geometry = {'type': 'MultiPolygon', 'coordinates': [[p] for p in polygons]}

        feature = {'type': 'Feature', 'properties': props, 'geometry': geometry}
        tiers[tier].append(feature)
        stats['included'] += 1

    return tiers, stats


def save_tier(features, output_path, name):
    """Save a tier as a GeoJSON FeatureCollection."""
    collection = {
        'type': 'FeatureCollection',
        'name': name,
        'features': features,
        'metadata': {
            'generated': datetime.now(timezone.utc).isoformat().replace('+00:00', 'Z'),
            'generator': 'ese_to_geojson.py',
            'source': 'EuroScope .ese sector definitions',
            'feature_count': len(features),
        }
    }
    with open(output_path, 'w', encoding='utf-8') as f:
        json.dump(collection, f, indent=2)
    size = os.path.getsize(output_path)
    print(f'  Saved {output_path} ({len(features)} features, {size:,} bytes)')


def main():
    parser = argparse.ArgumentParser(description='Convert EuroScope .ese files to GeoJSON sector boundaries')
    parser.add_argument('ese_path', nargs='?', default='C:/Temp/CZYZ.ese', help='Path to .ese file')
    parser.add_argument('output_dir', nargs='?', default=None, help='Output directory')
    parser.add_argument('--fir', default=None, help='FIR code (auto-detected from filename if omitted)')
    args = parser.parse_args()

    ese_path = args.ese_path

    # Auto-detect FIR code from filename (e.g., CZWG.ese → CZWG)
    fir_code = args.fir or os.path.splitext(os.path.basename(ese_path))[0].upper()
    output_dir = args.output_dir or 'C:/Temp'

    os.makedirs(output_dir, exist_ok=True)

    print(f'Parsing {ese_path} (FIR: {fir_code})...')
    sectorlines, sectors = parse_ese(ese_path)
    print(f'  {len(sectorlines)} sectorlines, {len(sectors)} sector definitions')

    print(f'Building GeoJSON...')
    tiers, stats = build_geojson(sectors, sectorlines, fir_code)

    print(f'\nStats:')
    print(f'  Included:           {stats["included"]}')
    print(f'  Skipped (filter):   {stats["skipped_filter"]}')
    print(f'  Skipped (no border):{stats["skipped_no_border"]}')
    print(f'  Skipped (bad geom): {stats["skipped_bad_geom"]}')
    if stats['missing_sectorline']:
        print(f'  Missing sectorlines: {len(stats["missing_sectorline"])}')
        for m in sorted(stats['missing_sectorline'])[:10]:
            print(f'    - {m}')

    print(f'\nSaving tier files to {output_dir}/')
    save_tier(tiers['low'], os.path.join(output_dir, f'{fir_code}_low.geojson'),
              f'{fir_code} FIR Low Altitude Sector Boundaries')
    save_tier(tiers['high'], os.path.join(output_dir, f'{fir_code}_high.geojson'),
              f'{fir_code} FIR High Altitude Sector Boundaries')
    save_tier(tiers['superhigh'], os.path.join(output_dir, f'{fir_code}_superhigh.geojson'),
              f'{fir_code} FIR Superhigh Altitude Sector Boundaries')

    # Summary of unique sector codes per tier
    for tier_name in ['low', 'high', 'superhigh']:
        codes = sorted(set(f['properties']['sector'] for f in tiers[tier_name]))
        print(f'\n{tier_name.upper()} sectors ({len(tiers[tier_name])} features, {len(codes)} codes):')
        print(f'  {", ".join(codes)}')


if __name__ == '__main__':
    # Ensure UTF-8 output on Windows
    if sys.platform == 'win32':
        sys.stdout = io.TextIOWrapper(sys.stdout.buffer, encoding='utf-8', errors='replace')
    main()
