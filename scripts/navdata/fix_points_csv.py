#!/usr/bin/env python3
"""
One-off script to fix data quality issues in assets/data/points.csv:

1. Reconstruct ~302 scientific notation-corrupted oceanic waypoints
   (e.g., "1.00E+60" with coords 1.0,160.0 -> "01E160")
2. Remove bogus FADW entry at 0.0,0.0
3. Deduplicate entries (e.g., KMTC_OLD appears twice)

The corruption happened when oceanic waypoints with 'E' in their name
(e.g., 01E160 = lat 01, east lon 160) were opened in Excel, which
interpreted them as scientific notation numbers.

Run from project root:
    python scripts/navdata/fix_points_csv.py [--dry-run]
"""

import re
import sys
from pathlib import Path

POINTS_CSV = Path('assets/data/points.csv')

# Scientific notation pattern in the name field.
# Requires either a decimal point OR explicit +/- after E to avoid
# matching legitimate FAA identifiers like 0E9, 2E8, 4E7 etc.
SCI_NOTATION_RE = re.compile(
    r'^[+-]?\d+\.\d*[Ee][+-]?\d+$'   # decimal point present: 1.00E+60
    r'|'
    r'^[+-]?\d+[Ee][+-]\d+$'          # explicit sign after E: 1E+60
)


def is_near_integer(val: float, tolerance: float = 0.01) -> bool:
    """Check if a float value is within tolerance of an integer."""
    return abs(val - round(val)) < tolerance


def reconstruct_oceanic_name(name: str, lat: float, lon: float) -> str:
    """
    Reconstruct oceanic waypoint name from coordinates.
    Format: {lat_int:02d}E{lon_int:03d} for east longitudes

    These Pacific oceanic waypoints use the format NNEnnn where
    NN = integer latitude and nnn = integer east longitude.
    """
    lat_int = abs(int(round(lat)))
    lon_int = abs(int(round(lon)))

    if lon >= 0:
        return f"{lat_int:02d}E{lon_int:03d}"
    else:
        return f"{lat_int:02d}W{lon_int:03d}"


def fix_points_csv(dry_run: bool = False):
    if not POINTS_CSV.exists():
        print(f"ERROR: {POINTS_CSV} not found. Run from project root.")
        sys.exit(1)

    lines = POINTS_CSV.read_text(encoding='utf-8-sig').splitlines()
    print(f"Read {len(lines)} lines from {POINTS_CSV}")

    fixed = []
    sci_fixed = 0
    sci_skipped = 0
    fadw_removed = 0
    deduped = 0
    seen_names = set()

    for i, line in enumerate(lines):
        line = line.strip()
        if not line:
            continue

        parts = line.split(',')
        if len(parts) < 3:
            fixed.append(line)
            continue

        name = parts[0].strip()
        try:
            lat = float(parts[1])
            lon = float(parts[2])
        except ValueError:
            fixed.append(line)
            continue

        # Fix 1: Remove FADW at 0,0 (bogus entry)
        if name == 'FADW' and lat == 0.0 and lon == 0.0:
            fadw_removed += 1
            print(f"  Removed: FADW,0.0,0.0 (bogus zero-coordinate entry)")
            continue

        # Fix 2: Reconstruct scientific notation-corrupted names
        # Only fix entries where:
        #   a) Name matches explicit scientific notation pattern
        #   b) Coordinates are near-integers (oceanic waypoints use integer coords)
        #   c) Reconstructed name is a valid oceanic format (lat 0-90, lon 0-180)
        if SCI_NOTATION_RE.match(name):
            if is_near_integer(lat) and is_near_integer(lon):
                lat_int = abs(int(round(lat)))
                lon_int = abs(int(round(lon)))
                if lat_int <= 90 and lon_int <= 180:
                    new_name = reconstruct_oceanic_name(name, lat, lon)
                    if sci_fixed < 10:
                        print(f"  Fixed: {name} -> {new_name} (coords: {lat},{lon})")
                    elif sci_fixed == 10:
                        print(f"  ... (showing first 10 of many)")
                    sci_fixed += 1
                    name = new_name
                    line = f"{name},{lat},{lon}"
                else:
                    sci_skipped += 1
            else:
                sci_skipped += 1

        # Fix 3: Deduplicate (keep first occurrence)
        if name in seen_names:
            deduped += 1
            print(f"  Deduped: {name} at line {i+1} (duplicate, keeping first)")
            continue
        seen_names.add(name)

        fixed.append(line)

    print(f"\nSummary:")
    print(f"  Scientific notation fixed: {sci_fixed}")
    print(f"  Sci-notation skipped (legitimate IDs): {sci_skipped}")
    print(f"  FADW removed: {fadw_removed}")
    print(f"  Duplicates removed: {deduped}")
    print(f"  Original lines: {len(lines)}")
    print(f"  Final lines: {len(fixed)}")
    print(f"  Net change: {len(fixed) - len(lines)}")

    if dry_run:
        print("\n[DRY RUN] No files modified.")
    else:
        POINTS_CSV.write_text('\n'.join(fixed) + '\n', encoding='utf-8')
        print(f"\nWrote {len(fixed)} lines to {POINTS_CSV}")


if __name__ == '__main__':
    dry_run = '--dry-run' in sys.argv
    fix_points_csv(dry_run=dry_run)
