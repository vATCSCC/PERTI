#!/usr/bin/env python3
"""
FAA Playbook PDF Parser — CLI Entry Point

Parses historical FAA National Severe Weather Playbook PDFs (2001-2021)
and produces CSV output compatible with assets/data/playbook_routes.csv.

Uses the same pipeline as the HTML scraper (PlaybookParser) for:
- Two-table play detection and combination
- Multi-value origin/dest expansion
- SID/STAR dot-notation formatting
- Airport/ARTCC/TRACON resolution

Usage:
  # Parse single PDF
  python scripts/parse_playbook_pdf.py 20210812.pdf

  # Specify output
  python scripts/parse_playbook_pdf.py 20210812.pdf --output routes_2021.csv

  # Parse all PDFs in directory (historical mode: date-stamps play names)
  python scripts/parse_playbook_pdf.py /path/to/PLAYBOOK/ --historical

  # Dry run (no output written)
  python scripts/parse_playbook_pdf.py 20210812.pdf --dry-run

  # Verbose logging
  python scripts/parse_playbook_pdf.py 20210812.pdf --verbose
"""

import argparse
import csv
import logging
import sys
from pathlib import Path
from typing import Dict, List, Tuple

# Add parent directory to path for imports
sys.path.insert(0, str(Path(__file__).parent.parent))

from scripts.playbook.pdf_parser import PDFPlaybookParser, PDFPlayGroup
from scripts.playbook.html_extractor import extract_routes_from_table
from scripts.playbook.route_combiner import TwoTableCombiner, deduplicate_routes
from scripts.playbook.procedure_detector import ProcedureDetector
from scripts.playbook.parser import PlaybookParser, RouteEntry
from scripts.playbook.config import APTS_CSV, DP_CSV, STAR_CSV, OUTPUT_COLUMNS

logger = logging.getLogger(__name__)


def process_play_group(
    group: PDFPlayGroup,
    playbook_parser: PlaybookParser,
    combiner: TwoTableCombiner,
) -> List[RouteEntry]:
    """
    Process a single PDFPlayGroup through the existing pipeline.

    Reuses PlaybookParser methods for:
    - Two-table combination (TwoTableCombiner)
    - Multi-value expansion (_expand_multi_value_routes)
    - Procedure formatting (ProcedureDetector)
    - Airport resolution (build_route_entry)
    """
    parsed_play = group.to_parsed_play()
    routes = []

    if parsed_play.has_two_table_format:
        origin_table = parsed_play.get_origin_table()
        dest_table = parsed_play.get_dest_table()

        if origin_table and dest_table:
            origin_routes = extract_routes_from_table(origin_table)
            dest_routes = extract_routes_from_table(dest_table)
            routes = combiner.combine_all_combinations(
                origin_routes, dest_routes
            )
    else:
        for table in parsed_play.tables:
            table_routes = extract_routes_from_table(table)
            expanded = playbook_parser._expand_multi_value_routes(table_routes)
            routes.extend(expanded)

    # Format procedures and build route entries
    entries = []
    for route_dict in routes:
        route_dict['route'] = playbook_parser.procedure_detector.format_route(
            route_dict.get('route', '')
        )
        entry = playbook_parser.build_route_entry(group.play_name, route_dict)
        if entry:
            entries.append(entry)

    return entries


def parse_single_pdf(
    pdf_path: Path,
    playbook_parser: PlaybookParser,
    historical_suffix: str = '',
) -> Tuple[List[RouteEntry], Dict[str, str]]:
    """Parse one PDF and return route entries + play_name->category mapping."""
    pdf_parser = PDFPlaybookParser()
    combiner = TwoTableCombiner()

    play_groups = pdf_parser.parse_pdf(pdf_path)

    all_entries = []
    play_categories: Dict[str, str] = {}

    for group in play_groups:
        # Apply historical suffix if requested
        if historical_suffix:
            group.play_name = f"{group.play_name}_{historical_suffix}"

        # Track category for this play name
        if group.category:
            play_categories[group.play_name] = group.category

        entries = process_play_group(group, playbook_parser, combiner)
        all_entries.extend(entries)

    # Deduplicate
    before = len(all_entries)
    all_entries = playbook_parser._deduplicate_entries(all_entries)
    if before != len(all_entries):
        logger.info(f"  Deduplicated: {before} -> {len(all_entries)} routes")

    # Print parser stats
    stats = pdf_parser.get_stats()
    print(f"\n  {pdf_path.name}:")
    print(f"    Pages: {stats['pages_total']} total, "
          f"{stats['pages_play_start']} play starts, "
          f"{stats['pages_play_cont']} continuations")
    print(f"    Plays: {stats['plays_extracted']}")
    print(f"    Tables: {stats['tables_extracted']}")
    if stats['paired_tables_unpacked']:
        print(f"    Paired dest tables unpacked: {stats['paired_tables_unpacked']}")
    if stats['facility_cells_normalized']:
        print(f"    FACILITY cells normalized: {stats['facility_cells_normalized']}")
    cat_count = sum(1 for g in play_groups if g.category)
    print(f"    Categories: {cat_count}/{len(play_groups)} plays")
    print(f"    Routes: {len(all_entries)}")

    return all_entries, play_categories


def extract_date_from_filename(pdf_path: Path) -> str:
    """
    Extract a date suffix from PDF filename for historical mode.

    Filenames like 20210812.pdf -> '20210812'
    """
    stem = pdf_path.stem
    # Match YYYYMMDD pattern
    if len(stem) == 8 and stem.isdigit():
        return stem
    # Match other patterns with date
    import re
    match = re.search(r'(\d{8})', stem)
    if match:
        return match.group(1)
    return stem


def main():
    parser = argparse.ArgumentParser(
        description='Parse FAA Playbook PDFs into route CSV',
        formatter_class=argparse.RawDescriptionHelpFormatter,
        epilog="""
Examples:
  # Parse single PDF
  python scripts/parse_playbook_pdf.py 20210812.pdf

  # Specify output CSV
  python scripts/parse_playbook_pdf.py 20210812.pdf --output routes_2021.csv

  # Parse all PDFs in directory with date-stamped play names
  python scripts/parse_playbook_pdf.py /path/to/PLAYBOOK/ --historical --output historical.csv

  # Dry run
  python scripts/parse_playbook_pdf.py 20210812.pdf --dry-run

  # Verbose output
  python scripts/parse_playbook_pdf.py 20210812.pdf --verbose
"""
    )
    parser.add_argument(
        'input', type=Path,
        help='PDF file or directory of PDFs to parse'
    )
    parser.add_argument(
        '--output', '-o', type=Path,
        help='Output CSV path (default: playbook_routes_<name>.csv)'
    )
    parser.add_argument(
        '--historical', action='store_true',
        help='Historical mode: suffix play names with PDF date (for batch)'
    )
    parser.add_argument(
        '--data-dir', type=Path,
        help='Data directory for airport/procedure files (default: assets/data)'
    )
    parser.add_argument(
        '--dry-run', action='store_true',
        help='Parse and report but do not write output'
    )
    parser.add_argument(
        '--verbose', '-v', action='store_true',
        help='Enable verbose logging'
    )

    args = parser.parse_args()

    # Configure logging
    logging.basicConfig(
        level=logging.DEBUG if args.verbose else logging.INFO,
        format='%(asctime)s - %(levelname)s - %(message)s'
    )

    # Resolve data paths
    if args.data_dir:
        apts_csv = args.data_dir / 'apts.csv'
        dp_csv = args.data_dir / 'dp_full_routes.csv'
        star_csv = args.data_dir / 'star_full_routes.csv'
    else:
        apts_csv = APTS_CSV
        dp_csv = DP_CSV
        star_csv = STAR_CSV

    # Initialize shared parser
    print("Initializing pipeline...")
    print(f"  Airport data: {apts_csv}")
    print(f"  DP procedures: {dp_csv}")
    print(f"  STAR procedures: {star_csv}")
    playbook_parser = PlaybookParser(
        apts_csv=apts_csv,
        dp_csv=dp_csv,
        star_csv=star_csv,
    )

    # Collect PDF files
    input_path = args.input
    if input_path.is_dir():
        pdf_files = sorted(input_path.glob('*.pdf'))
        if not pdf_files:
            print(f"No PDF files found in {input_path}")
            return
        print(f"\nFound {len(pdf_files)} PDFs in {input_path}")
    elif input_path.is_file():
        pdf_files = [input_path]
    else:
        print(f"Input not found: {input_path}")
        sys.exit(1)

    # Parse each PDF
    all_entries: List[RouteEntry] = []
    all_categories: Dict[str, str] = {}

    for pdf_path in pdf_files:
        suffix = ''
        if args.historical:
            suffix = extract_date_from_filename(pdf_path)

        entries, categories = parse_single_pdf(pdf_path, playbook_parser, suffix)
        all_entries.extend(entries)
        all_categories.update(categories)

    # Final dedup across all PDFs (for batch mode)
    if len(pdf_files) > 1:
        before = len(all_entries)
        all_entries = playbook_parser._deduplicate_entries(all_entries)
        print(f"\nCross-PDF dedup: {before} -> {len(all_entries)} routes")

    # Category summary
    unique_plays = set(e.play for e in all_entries)
    categorized = sum(1 for p in unique_plays if p in all_categories)

    # Summary
    print(f"\n{'=' * 60}")
    print(f"FINAL SUMMARY")
    print(f"{'=' * 60}")
    print(f"  PDFs processed:  {len(pdf_files)}")
    print(f"  Total routes:    {len(all_entries)}")
    print(f"  Unique plays:    {len(unique_plays)}")
    print(f"  Categorized:     {categorized}/{len(unique_plays)}")
    print(f"{'=' * 60}")

    # Write output
    if args.dry_run:
        print(f"\n[DRY RUN] Would write {len(all_entries)} routes")
    else:
        if args.output:
            output_path = args.output
        elif len(pdf_files) == 1:
            output_path = Path(f"playbook_routes_{pdf_files[0].stem}.csv")
        else:
            output_path = Path("playbook_routes_historical.csv")

        print(f"\nWriting {len(all_entries)} routes to {output_path}...")

        # Write 9-column CSV (standard 8 + Category)
        columns = list(OUTPUT_COLUMNS) + ['Category']
        with open(output_path, 'w', newline='', encoding='utf-8') as f:
            writer = csv.writer(f)
            writer.writerow(columns)
            for entry in all_entries:
                category = all_categories.get(entry.play, '')
                writer.writerow(list(entry) + [category])

        print("Done!")


if __name__ == '__main__':
    main()
