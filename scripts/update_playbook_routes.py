#!/usr/bin/env python3
"""
FAA Playbook Routes Scraper and Updater

Scrapes route data from https://www.fly.faa.gov/playbook/ and updates
data/playbook_routes.csv with new routes while preserving old versions
for backwards compatibility.

Uses the modular parser from scripts/playbook/ for robust HTML extraction.
"""

import csv
import os
import sys
import logging
from datetime import datetime
from typing import Dict, List, Optional
from collections import defaultdict
from pathlib import Path

# Add parent directory to path for imports
sys.path.insert(0, str(Path(__file__).parent.parent))

from scripts.playbook import PlaybookParser, calculate_airac_cycle
from scripts.playbook.parser import RouteEntry
from scripts.playbook.config import (
    APTS_CSV, DP_CSV, STAR_CSV, OUTPUT_CSV, OUTPUT_COLUMNS,
    REQUEST_DELAY
)

# Configure logging
logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s - %(levelname)s - %(message)s'
)
logger = logging.getLogger(__name__)


def load_existing_routes(csv_path: Path) -> Dict[str, List[RouteEntry]]:
    """Load existing routes from CSV, grouped by play name."""
    routes_by_play: Dict[str, List[RouteEntry]] = defaultdict(list)

    if not csv_path.exists():
        return routes_by_play

    with open(csv_path, 'r', encoding='utf-8-sig', newline='') as f:
        reader = csv.DictReader(f)
        for row in reader:
            play_name = row.get('Play', '').strip()
            # Skip empty or header-like entries
            if not play_name or play_name == 'Play':
                continue
            entry = RouteEntry(
                play=play_name,
                route_string=row.get('Route String', ''),
                origins=row.get('Origins', ''),
                origin_tracons=row.get('Origin_TRACONs', ''),
                origin_artccs=row.get('Origin_ARTCCs', ''),
                destinations=row.get('Destinations', ''),
                dest_tracons=row.get('Dest_TRACONs', ''),
                dest_artccs=row.get('Dest_ARTCCs', ''),
            )
            routes_by_play[entry.play].append(entry)

    return routes_by_play


def routes_are_equal(routes1: List[RouteEntry], routes2: List[RouteEntry]) -> bool:
    """Check if two route lists are equivalent (ignoring order)."""
    if len(routes1) != len(routes2):
        return False

    # Compare by route strings
    strings1 = set(r.route_string for r in routes1)
    strings2 = set(r.route_string for r in routes2)

    return strings1 == strings2


def write_routes_csv(routes: List[RouteEntry], output_path: Path):
    """Write routes to CSV with Windows line endings."""
    with open(output_path, 'w', encoding='utf-8', newline='') as f:
        writer = csv.writer(f, lineterminator='\r\n')
        writer.writerow(OUTPUT_COLUMNS)

        for entry in routes:
            writer.writerow([
                entry.play,
                entry.route_string,
                entry.origins,
                entry.origin_tracons,
                entry.origin_artccs,
                entry.destinations,
                entry.dest_tracons,
                entry.dest_artccs,
            ])


def merge_routes(
    existing_routes: Dict[str, List[RouteEntry]],
    new_routes: Dict[str, List[RouteEntry]],
    airac_cycle: str
) -> tuple[List[RouteEntry], dict]:
    """
    Merge new routes with existing, versioning changed plays.

    Returns:
        (final_routes, stats) where stats contains counts of changes
    """
    final_routes: List[RouteEntry] = []
    stats = {
        'plays_new': set(),
        'plays_updated': set(),
        'plays_deleted': set(),
        'plays_unchanged': set(),
    }

    # Process new routes
    for play_name, new_entries in new_routes.items():
        if play_name in existing_routes:
            old_entries = existing_routes[play_name]

            if routes_are_equal(old_entries, new_entries):
                # Unchanged - keep as is
                final_routes.extend(new_entries)
                stats['plays_unchanged'].add(play_name)
            else:
                # Changed - version the old routes
                old_play_name = f"{play_name}_old_{airac_cycle}"
                for entry in old_entries:
                    final_routes.append(entry._replace(play=old_play_name))

                # Add new routes
                final_routes.extend(new_entries)
                stats['plays_updated'].add(play_name)
                logger.info(f"Updated: {play_name} (old version saved as {old_play_name})")
        else:
            # New play
            final_routes.extend(new_entries)
            stats['plays_new'].add(play_name)
            logger.info(f"New: {play_name}")

    # Handle deleted plays (in existing but not in new)
    for play_name in existing_routes:
        if play_name not in new_routes and not play_name.endswith(f'_old_{airac_cycle}'):
            # Check if it's not already an old version
            if '_old_' not in play_name:
                old_play_name = f"{play_name}_old_{airac_cycle}"
                for entry in existing_routes[play_name]:
                    final_routes.append(entry._replace(play=old_play_name))
                stats['plays_deleted'].add(play_name)
                logger.info(f"Deleted: {play_name} (saved as {old_play_name})")
            else:
                # Keep existing old versions
                final_routes.extend(existing_routes[play_name])

    # Sort routes by play name, then route string
    final_routes.sort(key=lambda r: (r.play, r.route_string))

    return final_routes, stats


def print_parse_report(parser: PlaybookParser, stats: dict, total_routes: int):
    """Print a detailed parse report."""
    print("\n" + "=" * 60)
    print("PLAYBOOK PARSE REPORT")
    print("=" * 60)

    parser_stats = parser.get_stats()

    print("\nPARSING SUMMARY:")
    print(f"  Plays fetched:     {parser_stats['plays_fetched']}")
    print(f"  Plays parsed:      {parser_stats['plays_parsed']}")
    print(f"  Plays failed:      {parser_stats['plays_failed']}")
    print(f"  Two-table plays:   {parser_stats['two_table_plays']}")
    print(f"  Routes generated:  {parser_stats['routes_generated']}")

    print("\nMERGE SUMMARY:")
    print(f"  New plays:         {len(stats['plays_new'])}")
    print(f"  Updated plays:     {len(stats['plays_updated'])}")
    print(f"  Deleted plays:     {len(stats['plays_deleted'])}")
    print(f"  Unchanged plays:   {len(stats['plays_unchanged'])}")

    print(f"\nFINAL OUTPUT:")
    print(f"  Total routes:      {total_routes}")
    print("=" * 60)


def main():
    """Main entry point."""
    import argparse

    arg_parser = argparse.ArgumentParser(
        description='Update playbook routes from FAA website using robust parser',
        formatter_class=argparse.RawDescriptionHelpFormatter,
        epilog="""
Examples:
  # Full update (in-place)
  python update_playbook_routes.py

  # Test with limited plays
  python update_playbook_routes.py --limit 10

  # Test single play by name
  python update_playbook_routes.py --test-play "ABI"

  # Test single play by playkey
  python update_playbook_routes.py --test-playkey 202510216853

  # Dry run (no output file written)
  python update_playbook_routes.py --dry-run

  # Verbose output
  python update_playbook_routes.py --verbose
"""
    )
    arg_parser.add_argument('--data-dir', type=Path,
                           help='Data directory (default: assets/data)')
    arg_parser.add_argument('--output-csv', type=Path,
                           help='Path to output CSV (default: playbook_routes.csv)')
    arg_parser.add_argument('--test-play', help='Test with a single play name')
    arg_parser.add_argument('--test-playkey', help='Test with a single playkey')
    arg_parser.add_argument('--limit', type=int, help='Limit number of plays to process')
    arg_parser.add_argument('--dry-run', action='store_true',
                           help='Show what would be done without writing output')
    arg_parser.add_argument('--verbose', '-v', action='store_true',
                           help='Enable verbose logging')
    arg_parser.add_argument('--delay', type=float, default=REQUEST_DELAY,
                           help=f'Delay between requests (default: {REQUEST_DELAY}s)')

    args = arg_parser.parse_args()

    # Configure logging level
    if args.verbose:
        logging.getLogger().setLevel(logging.DEBUG)

    # Resolve file paths
    if args.data_dir:
        apts_csv = args.data_dir / 'apts.csv'
        dp_csv = args.data_dir / 'dp_full_routes.csv'
        star_csv = args.data_dir / 'star_full_routes.csv'
        input_csv = args.data_dir / 'playbook_routes.csv'
        output_csv = args.output_csv or input_csv
    else:
        apts_csv = APTS_CSV
        dp_csv = DP_CSV
        star_csv = STAR_CSV
        input_csv = OUTPUT_CSV
        output_csv = args.output_csv or OUTPUT_CSV

    # Calculate current AIRAC cycle
    airac_cycle = calculate_airac_cycle()
    print(f"Current AIRAC cycle: {airac_cycle}")

    # Initialize parser with data files
    print(f"Initializing parser...")
    print(f"  Airport data: {apts_csv}")
    print(f"  DP procedures: {dp_csv}")
    print(f"  STAR procedures: {star_csv}")

    parser = PlaybookParser(
        apts_csv=apts_csv,
        dp_csv=dp_csv,
        star_csv=star_csv
    )

    # Load existing routes
    print(f"Loading existing routes from {input_csv}...")
    existing_routes = load_existing_routes(input_csv)
    total_existing = sum(len(r) for r in existing_routes.values())
    print(f"  Loaded {total_existing} routes from {len(existing_routes)} plays")

    # Test mode - single playkey
    if args.test_playkey:
        print(f"\nTesting with playkey {args.test_playkey}...")
        entries = parser.fetch_and_parse_play("TEST_PLAY", args.test_playkey)

        print(f"\nGenerated {len(entries)} route entries:")
        for entry in entries[:10]:
            print(f"  {entry.route_string}")
        if len(entries) > 10:
            print(f"  ... and {len(entries) - 10} more")

        print("\nParser stats:")
        for k, v in parser.get_stats().items():
            print(f"  {k}: {v}")
        return

    # Get play list
    plays = parser.get_play_list()

    # Filter by test play name
    if args.test_play:
        matching = {k: v for k, v in plays.items() if args.test_play.upper() in k.upper()}
        if matching:
            plays = matching
            print(f"Filtered to {len(plays)} plays matching '{args.test_play}'")
        else:
            print(f"No plays found matching '{args.test_play}'")
            print("Available plays:")
            for name in sorted(plays.keys())[:20]:
                print(f"  {name}")
            return

    # Apply limit
    if args.limit:
        plays = dict(list(plays.items())[:args.limit])
        print(f"Limited to {len(plays)} plays")

    # Fetch and parse all plays
    print(f"\nProcessing {len(plays)} plays...")
    all_entries = parser.fetch_and_parse_all(plays=plays, delay=args.delay)

    # Group routes by play name for merging
    new_routes: Dict[str, List[RouteEntry]] = defaultdict(list)
    for entry in all_entries:
        new_routes[entry.play].append(entry)

    # Merge with existing routes
    print("\nMerging routes...")
    final_routes, stats = merge_routes(existing_routes, new_routes, airac_cycle)

    # Print report
    print_parse_report(parser, stats, len(final_routes))

    # Write output
    if args.dry_run:
        print(f"\n[DRY RUN] Would write {len(final_routes)} routes to {output_csv}")
    else:
        print(f"\nWriting {len(final_routes)} routes to {output_csv}...")
        write_routes_csv(final_routes, output_csv)
        print("Done!")


if __name__ == '__main__':
    main()
