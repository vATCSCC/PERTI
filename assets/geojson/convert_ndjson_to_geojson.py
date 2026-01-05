#!/usr/bin/env python3
"""
NDJSON to GeoJSON FeatureCollection Converter

Converts newline-delimited JSON files (one Feature per line) 
to proper GeoJSON FeatureCollection format for MapLibre/Leaflet.

Usage: python convert_ndjson_to_geojson.py <input_file> [output_file]
       python convert_ndjson_to_geojson.py --batch <directory>
"""

import json
import sys
import os
from pathlib import Path


def convert_ndjson_to_featurecollection(input_path, output_path=None):
    """Convert NDJSON file to GeoJSON FeatureCollection."""
    
    input_path = Path(input_path)
    
    if not input_path.exists():
        print(f"Error: File not found: {input_path}")
        return False
    
    # Default output path: same name (replace original)
    if output_path is None:
        output_path = input_path
    else:
        output_path = Path(output_path)
    
    print(f"Converting: {input_path}")
    
    features = []
    errors = []
    
    with open(input_path, 'r', encoding='utf-8') as f:
        for line_num, line in enumerate(f, 1):
            line = line.strip()
            
            # Skip empty lines
            if not line:
                continue
            
            try:
                feature = json.loads(line)
            except json.JSONDecodeError as e:
                errors.append(f"Line {line_num}: {e}")
                continue
            
            # Validate it's a Feature
            if not isinstance(feature, dict) or feature.get('type') != 'Feature':
                errors.append(f"Line {line_num}: Not a GeoJSON Feature")
                continue
            
            features.append(feature)
    
    # Report errors if any
    if errors:
        print(f"  Warnings ({len(errors)}):")
        for error in errors[:5]:
            print(f"    - {error}")
        if len(errors) > 5:
            print(f"    ... and {len(errors) - 5} more")
    
    if not features:
        print("  Error: No valid features found!")
        return False
    
    # Build FeatureCollection
    feature_collection = {
        'type': 'FeatureCollection',
        'features': features
    }
    
    # Write output (compact JSON for smaller file size)
    json_output = json.dumps(feature_collection, separators=(',', ':'))
    
    with open(output_path, 'w', encoding='utf-8') as f:
        f.write(json_output)
    
    input_size = input_path.stat().st_size
    output_size = len(json_output)
    
    print(f"  Features: {len(features)}")
    print(f"  Input size: {input_size:,} bytes")
    print(f"  Output size: {output_size:,} bytes")
    print(f"  Output: {output_path}")
    print("  Done!\n")
    
    return True


def batch_convert(directory):
    """Convert all NDJSON files in a directory."""
    
    directory = Path(directory)
    files = list(directory.glob('*.json'))
    
    if not files:
        print(f"No JSON files found in: {directory}")
        return
    
    print(f"Found {len(files)} JSON files in {directory}\n")
    
    converted = 0
    skipped = 0
    
    for file_path in files:
        # Skip already converted files
        if '_fc' in file_path.stem:
            print(f"Skipping (already converted): {file_path}")
            skipped += 1
            continue
        
        # Read first line to check format
        with open(file_path, 'r', encoding='utf-8') as f:
            first_line = f.readline().strip()
        
        # If it's already a FeatureCollection, skip
        if '"FeatureCollection"' in first_line:
            print(f"Skipping (already FeatureCollection): {file_path}")
            skipped += 1
            continue
        
        # Check if first line is a Feature
        try:
            first_obj = json.loads(first_line)
            if not isinstance(first_obj, dict) or first_obj.get('type') != 'Feature':
                print(f"Skipping (not NDJSON Features): {file_path}")
                skipped += 1
                continue
        except json.JSONDecodeError:
            print(f"Skipping (invalid JSON): {file_path}")
            skipped += 1
            continue
        
        if convert_ndjson_to_featurecollection(file_path):
            converted += 1
    
    print("=== Summary ===")
    print(f"Converted: {converted}")
    print(f"Skipped: {skipped}")


def main():
    if len(sys.argv) < 2:
        print("Usage: python convert_ndjson_to_geojson.py <input_file> [output_file]")
        print("       python convert_ndjson_to_geojson.py --batch <directory>")
        sys.exit(1)
    
    if sys.argv[1] == '--batch':
        if len(sys.argv) < 3:
            print("Error: --batch requires a directory path")
            sys.exit(1)
        batch_convert(sys.argv[2])
    else:
        input_file = sys.argv[1]
        output_file = sys.argv[2] if len(sys.argv) > 2 else None
        convert_ndjson_to_featurecollection(input_file, output_file)


if __name__ == '__main__':
    main()
