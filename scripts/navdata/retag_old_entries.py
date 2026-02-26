#!/usr/bin/env python3
"""
One-off migration: retag untagged _OLD entries across all navdata CSV files.

Renames:
  NAME_OLD  ->  NAME_old_pre2602   (cycle unknown, pre-existing)

This establishes the new _old_{AIRAC}_{reason} naming convention.
Future AIRAC updates will use: NAME_old_2603_moved, NAME_old_2603_removed, etc.

Run from project root:
    python scripts/navdata/retag_old_entries.py [--dry-run]
"""

import re
import sys
from pathlib import Path

# Pattern: name ending with _OLD (optionally followed by digits)
# Examples: JULAS_OLD, J101_OLD, ABQASESK_OLD
OLD_SUFFIX_RE = re.compile(r'^(.+?)_OLD(\d*)$')

NEW_SUFFIX = '_old_pre2602'

DATA_FILES = {
    'points':  ('assets/data/points.csv',  'csv_name_first'),   # NAME,lat,lon
    'navaids': ('assets/data/navaids.csv', 'csv_name_first'),   # NAME,lat,lon,...
    'airways': ('assets/data/awys.csv',    'csv_name_first'),   # AWY_ID,fix1 fix2 ...
    'cdrs':    ('assets/data/cdrs.csv',    'csv_name_first'),   # CODE,route
}


def retag_file(filepath: str, fmt: str, dry_run: bool) -> int:
    """Retag _OLD entries in a single file. Returns count of retagged entries."""
    path = Path(filepath)
    if not path.exists():
        print(f"  SKIP: {filepath} not found")
        return 0

    lines = path.read_text(encoding='utf-8-sig').splitlines()
    retagged = 0
    output = []

    for line in lines:
        stripped = line.strip()
        if not stripped:
            continue

        parts = stripped.split(',', 1)
        name = parts[0].strip()

        match = OLD_SUFFIX_RE.match(name)
        if match:
            base_name = match.group(1)
            old_cycle = match.group(2)  # empty string if just _OLD

            if old_cycle:
                # NAME_OLD2602 -> NAME_old_2602 (normalize format, keep cycle)
                new_name = f"{base_name}_old_{old_cycle}"
            else:
                # NAME_OLD -> NAME_old_pre2602 (cycle unknown)
                new_name = f"{base_name}{NEW_SUFFIX}"

            new_line = f"{new_name},{parts[1]}" if len(parts) > 1 else new_name
            output.append(new_line)
            retagged += 1

            if retagged <= 5:
                print(f"    {name} -> {new_name}")
            elif retagged == 6:
                print(f"    ... (showing first 5)")
        else:
            output.append(stripped)

    print(f"  {filepath}: {retagged} entries retagged (of {len(lines)} total)")

    if not dry_run and retagged > 0:
        path.write_text('\n'.join(output) + '\n', encoding='utf-8')

    return retagged


def main():
    dry_run = '--dry-run' in sys.argv
    total = 0

    print(f"Retagging untagged _OLD entries -> {NEW_SUFFIX}")
    if dry_run:
        print("[DRY RUN MODE]\n")
    else:
        print()

    for label, (filepath, fmt) in DATA_FILES.items():
        print(f"Processing {label}:")
        count = retag_file(filepath, fmt, dry_run)
        total += count
        print()

    print(f"Total entries retagged: {total}")
    if dry_run:
        print("\n[DRY RUN] No files modified.")


if __name__ == '__main__':
    main()
