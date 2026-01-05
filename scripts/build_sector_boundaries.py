#!/usr/bin/env python3
"""
Build Sector Boundary GeoJSON Files from CRC VideoMaps

This script processes CRC ARTCC metadata and VideoMap GeoJSON files to create
consolidated sector boundary files for high, low, and superhigh altitude sectors.

Output files match the format used by PERTI's MapLibre layers:
- high.json: High altitude sector boundaries
- low.json: Low altitude sector boundaries  
- superhigh.json: Ultra-high/Super-high altitude sector boundaries

Usage:
    python3 build_sector_boundaries.py --crc-path /path/to/CRC_extracted --output-dir ./output

The script expects:
    /path/to/CRC_extracted/ARTCCs/*.json    - ARTCC metadata files
    /path/to/CRC_extracted/VideoMaps/ZXX/*.geojson - VideoMap GeoJSON files
"""

import json
import os
import sys
import argparse
import re
from datetime import datetime, timezone
from typing import Dict, List, Optional, Tuple

# Configuration for sector identification
SECTOR_PATTERNS = {
    'high': {
        'name_patterns': [
            r'high\s*sector',
            r'high\s*split',
            r'hi\s*sector',
            r'_high$',
            r'\shigh$',
            r'high\s*boundaries',
            r'high\s*control',
        ],
        'exclude_patterns': [
            r'ultra',
            r'super',
            r'airway',
            r'vor',
            r'fix',
            r'text',
            r'label',
            r'id\s*$',
        ],
        'tag_include': ['ERAM', 'SECTOR', 'SECTORS'],
    },
    'low': {
        'name_patterns': [
            r'low\s*sector',
            r'low\s*split', 
            r'lo\s*sector',
            r'_low$',
            r'\slow$',
            r'low\s*boundaries',
            r'low\s*control',
        ],
        'exclude_patterns': [
            r'airway',
            r'vor',
            r'fix',
            r'text',
            r'label',
            r'id\s*$',
        ],
        'tag_include': ['ERAM', 'SECTOR', 'SECTORS'],
    },
    'superhigh': {
        'name_patterns': [
            r'ultra\s*high',
            r'super\s*high',
            r'ultra',
            r'_uh\s',
            r'\suh\s',
        ],
        'exclude_patterns': [
            r'text',
            r'label',
            r'id\s*$',
        ],
        'tag_include': ['ERAM', 'SECTOR', 'SECTORS'],
    },
}

# Individual sector pattern exclusion (skip "#7 (SECTOR)" type entries)
INDIVIDUAL_SECTOR_PATTERN = re.compile(r'#\d+\s*\(SECTOR\)', re.IGNORECASE)

# Skip TRACON/STARS maps - these are approach control, not ARTCC sectors
TRACON_PATTERNS = [
    r'^DAB\s',   # Daytona Beach
    r'^JAX\s',   # Jacksonville TRACON
    r'^MCO\s',   # Orlando
    r'^MIA\s',   # Miami
    r'^TPA\s',   # Tampa
    r'^FLL\s',   # Fort Lauderdale
    r'^PBI\s',   # Palm Beach
    r'^SAV\s',   # Savannah
    r'^CAE\s',   # Columbia
    r'^CHS\s',   # Charleston
    r'^TLH\s',   # Tallahassee
    r'^FLO\s',   # Florence
    r'^STARS',   # STARS system maps
    r'TRACON',   # TRACON boundaries
    r'APPROACH', # Approach control
    r'\sOPS\s',  # Operations maps
]


def log(msg: str, level: str = 'INFO'):
    """Simple logging."""
    timestamp = datetime.now().strftime('%H:%M:%S')
    print(f"[{timestamp}] [{level}] {msg}")


def find_artcc_files(crc_path: str) -> List[str]:
    """Find all ARTCC JSON metadata files."""
    artcc_dir = os.path.join(crc_path, 'ARTCCs')
    if not os.path.isdir(artcc_dir):
        raise FileNotFoundError(f"ARTCCs directory not found: {artcc_dir}")
    
    files = []
    for f in os.listdir(artcc_dir):
        if f.endswith('.json') and len(f) == 8:  # ZXX.json format
            files.append(os.path.join(artcc_dir, f))
    
    return sorted(files)


def matches_pattern(name: str, patterns: List[str]) -> bool:
    """Check if name matches any of the regex patterns."""
    name_lower = name.lower()
    for pattern in patterns:
        if re.search(pattern, name_lower):
            return True
    return False


def categorize_videomap(vm: dict, artcc: str) -> Optional[str]:
    """
    Categorize a videomap into high, low, superhigh, or None.
    Returns the category string or None if not a sector boundary map.
    """
    name = vm.get('name', '')
    tags = [t.upper() for t in vm.get('tags', [])]
    
    # Skip individual sector pieces
    if INDIVIDUAL_SECTOR_PATTERN.search(name):
        return None
    
    # Skip text/label maps
    if any(x in name.upper() for x in ['TEXT', 'LABEL', 'IDS', 'ID$']):
        return None
    
    # Skip TRACON/STARS maps
    name_upper = name.upper()
    for pattern in TRACON_PATTERNS:
        if re.search(pattern, name_upper, re.IGNORECASE):
            return None
    
    # Skip if tagged as STARS (TRACON system)
    if 'STARS' in tags:
        return None
    
    # Check each category
    for category, patterns in SECTOR_PATTERNS.items():
        # Check name patterns
        if matches_pattern(name, patterns['name_patterns']):
            # Check exclusions
            if not matches_pattern(name, patterns['exclude_patterns']):
                return category
    
    # Special handling for ARTCC-specific main sector maps
    name_upper = name.upper()
    if artcc in name_upper:
        if 'HIGH' in name_upper and 'SECTOR' in name_upper:
            if 'ULTRA' not in name_upper and 'SUPER' not in name_upper:
                return 'high'
        if 'LOW' in name_upper and 'SECTOR' in name_upper:
            return 'low'
        if 'ULTRA' in name_upper or 'SUPER' in name_upper:
            return 'superhigh'
    
    return None


def load_geojson(path: str) -> Optional[dict]:
    """Load and parse a GeoJSON file."""
    try:
        with open(path, 'r', encoding='utf-8') as f:
            return json.load(f)
    except (json.JSONDecodeError, UnicodeDecodeError, FileNotFoundError) as e:
        log(f"Error loading {path}: {e}", 'WARN')
        return None


def extract_linestrings(geojson: dict, artcc: str, source_name: str) -> List[dict]:
    """
    Extract LineString features from a GeoJSON file.
    Adds artcc and source properties to each feature.
    """
    features = []
    
    if geojson.get('type') == 'FeatureCollection':
        for feat in geojson.get('features', []):
            geom = feat.get('geometry')
            if not geom:
                continue  # Skip features with null/missing geometry
            geom_type = geom.get('type')
            
            # Include LineString and MultiLineString
            if geom_type in ('LineString', 'MultiLineString'):
                coords = geom.get('coordinates', [])
                
                # Skip degenerate geometries
                if not coords or len(coords) < 2:
                    continue
                
                # Skip lines at origin (invalid data)
                if geom_type == 'LineString':
                    if all(c[0] == 0 and c[1] == 0 for c in coords):
                        continue
                
                # Create clean feature with just geometry and properties
                new_feat = {
                    'type': 'Feature',
                    'properties': {
                        'artcc': artcc,
                        'source': source_name,
                    },
                    'geometry': geom
                }
                features.append(new_feat)
            
            # Also handle Polygon boundaries (extract as LineString)
            elif geom_type == 'Polygon':
                # Convert polygon ring to linestring
                coords = geom.get('coordinates', [])
                if coords and len(coords) > 0 and len(coords[0]) >= 3:
                    # Skip polygons at origin
                    if all(c[0] == 0 and c[1] == 0 for c in coords[0]):
                        continue
                    
                    new_feat = {
                        'type': 'Feature',
                        'properties': {
                            'artcc': artcc,
                            'source': source_name,
                        },
                        'geometry': {
                            'type': 'LineString',
                            'coordinates': coords[0]  # Outer ring
                        }
                    }
                    features.append(new_feat)
    
    elif geojson.get('type') == 'LineString':
        coords = geojson.get('coordinates', [])
        if coords and len(coords) >= 2:
            if not all(c[0] == 0 and c[1] == 0 for c in coords):
                features.append({
                    'type': 'Feature',
                    'properties': {'artcc': artcc, 'source': source_name},
                    'geometry': geojson
                })
    
    return features


def process_artcc(artcc_path: str, crc_path: str) -> Dict[str, List[dict]]:
    """
    Process a single ARTCC and return categorized features.
    Returns dict with 'high', 'low', 'superhigh' keys containing feature lists.
    """
    result = {'high': [], 'low': [], 'superhigh': []}
    
    artcc = os.path.basename(artcc_path).replace('.json', '')
    log(f"Processing {artcc}...")
    
    # Load ARTCC metadata
    try:
        with open(artcc_path, 'r', encoding='utf-8') as f:
            data = json.load(f)
    except Exception as e:
        log(f"Error loading {artcc_path}: {e}", 'ERROR')
        return result
    
    videomaps = data.get('videoMaps', [])
    videomap_dir = os.path.join(crc_path, 'VideoMaps', artcc)
    
    if not os.path.isdir(videomap_dir):
        log(f"VideoMaps directory not found for {artcc}: {videomap_dir}", 'WARN')
        return result
    
    # Process each videomap
    categorized_count = {'high': 0, 'low': 0, 'superhigh': 0}
    
    for vm in videomaps:
        category = categorize_videomap(vm, artcc)
        if not category:
            continue
        
        # Load the GeoJSON
        vm_id = vm.get('id', '')
        geojson_path = os.path.join(videomap_dir, f"{vm_id}.geojson")
        
        if not os.path.exists(geojson_path):
            continue
        
        geojson = load_geojson(geojson_path)
        if not geojson:
            continue
        
        # Extract features
        features = extract_linestrings(geojson, artcc, vm.get('name', vm_id))
        
        if features:
            result[category].extend(features)
            categorized_count[category] += 1
            log(f"  [{category.upper()}] {vm.get('name', vm_id)}: {len(features)} features")
    
    # Log summary for this ARTCC
    total = sum(categorized_count.values())
    if total > 0:
        log(f"  {artcc} totals: HIGH={categorized_count['high']}, LOW={categorized_count['low']}, SUPERHIGH={categorized_count['superhigh']}")
    
    return result


def deduplicate_features(features: List[dict]) -> List[dict]:
    """
    Remove duplicate LineString features based on coordinates.
    Uses coordinate hash for comparison.
    """
    seen = set()
    unique = []
    
    for feat in features:
        coords = feat.get('geometry', {}).get('coordinates', [])
        
        # Skip empty geometries
        if not coords:
            continue
        
        try:
            # Create a hashable key from coordinates
            if isinstance(coords[0], list) and len(coords[0]) > 0 and isinstance(coords[0][0], list):
                # MultiLineString
                coord_key = tuple(tuple(tuple(c) for c in line) for line in coords)
            else:
                # LineString
                coord_key = tuple(tuple(c) if isinstance(c, list) else c for c in coords)
            
            if coord_key not in seen:
                seen.add(coord_key)
                unique.append(feat)
        except (TypeError, IndexError):
            # If we can't hash it, just include it
            unique.append(feat)
    
    return unique


def save_geojson(features: List[dict], output_path: str, name: str):
    """Save features as a GeoJSON FeatureCollection."""
    collection = {
        'type': 'FeatureCollection',
        'name': name,
        'features': features,
        'metadata': {
            'generated': datetime.now(timezone.utc).isoformat().replace('+00:00', 'Z'),
            'generator': 'build_sector_boundaries.py',
            'feature_count': len(features)
        }
    }
    
    with open(output_path, 'w', encoding='utf-8') as f:
        json.dump(collection, f, separators=(',', ':'))  # Minified
    
    # Also save pretty version for debugging
    pretty_path = output_path.replace('.json', '_pretty.json')
    with open(pretty_path, 'w', encoding='utf-8') as f:
        json.dump(collection, f, indent=2)
    
    log(f"Saved {output_path} ({len(features)} features, {os.path.getsize(output_path)} bytes)")


def main():
    parser = argparse.ArgumentParser(
        description='Build sector boundary GeoJSON from CRC VideoMaps'
    )
    parser.add_argument(
        '--crc-path', '-c',
        required=True,
        help='Path to CRC extraction directory (contains ARTCCs/ and VideoMaps/)'
    )
    parser.add_argument(
        '--output-dir', '-o',
        default='.',
        help='Output directory for generated files (default: current directory)'
    )
    parser.add_argument(
        '--artcc', '-a',
        help='Process only specified ARTCC (e.g., ZJX)'
    )
    parser.add_argument(
        '--verbose', '-v',
        action='store_true',
        help='Verbose output'
    )
    
    args = parser.parse_args()
    
    # Validate paths
    if not os.path.isdir(args.crc_path):
        log(f"CRC path not found: {args.crc_path}", 'ERROR')
        sys.exit(1)
    
    os.makedirs(args.output_dir, exist_ok=True)
    
    log(f"CRC Path: {args.crc_path}")
    log(f"Output Directory: {args.output_dir}")
    
    # Find ARTCC files
    artcc_files = find_artcc_files(args.crc_path)
    log(f"Found {len(artcc_files)} ARTCC metadata files")
    
    # Filter to specific ARTCC if requested
    if args.artcc:
        artcc_files = [f for f in artcc_files if args.artcc.upper() in f]
        if not artcc_files:
            log(f"ARTCC not found: {args.artcc}", 'ERROR')
            sys.exit(1)
    
    # Process all ARTCCs
    all_features = {'high': [], 'low': [], 'superhigh': []}
    
    for artcc_file in artcc_files:
        features = process_artcc(artcc_file, args.crc_path)
        for category in all_features:
            all_features[category].extend(features[category])
    
    # Deduplicate
    log("Deduplicating features...")
    for category in all_features:
        original = len(all_features[category])
        all_features[category] = deduplicate_features(all_features[category])
        deduped = len(all_features[category])
        if original != deduped:
            log(f"  {category}: {original} -> {deduped} (removed {original - deduped} duplicates)")
    
    # Save output files
    log("Saving output files...")
    
    save_geojson(
        all_features['high'],
        os.path.join(args.output_dir, 'high.json'),
        'ARTCC High Altitude Sector Boundaries'
    )
    
    save_geojson(
        all_features['low'],
        os.path.join(args.output_dir, 'low.json'),
        'ARTCC Low Altitude Sector Boundaries'
    )
    
    save_geojson(
        all_features['superhigh'],
        os.path.join(args.output_dir, 'superhigh.json'),
        'ARTCC Ultra-High/Super-High Altitude Sector Boundaries'
    )
    
    # Summary
    log("=" * 60)
    log("COMPLETE!")
    log(f"  high.json: {len(all_features['high'])} features")
    log(f"  low.json: {len(all_features['low'])} features")
    log(f"  superhigh.json: {len(all_features['superhigh'])} features")
    log("=" * 60)


if __name__ == '__main__':
    main()
