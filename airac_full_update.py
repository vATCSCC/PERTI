#!/usr/bin/env python3
"""
AIRAC Full Update - Master Script

Performs the complete AIRAC update process:
  Step 1: Download FAA NASR data and update CSV/JS files
  Step 2: Scrape FAA Playbook and update playbook_routes.csv
  Step 3: Import all data to VATSIM_REF and sync to VATSIM_ADL

Usage:
    python airac_full_update.py                   # Full update
    python airac_full_update.py --dry-run         # Preview without changes
    python airac_full_update.py --step 1          # Run only step 1 (NASR)
    python airac_full_update.py --step 2          # Run only step 2 (Playbook)
    python airac_full_update.py --step 3          # Run only step 3 (Database)
    python airac_full_update.py --skip-playbook   # Skip playbook scrape
    python airac_full_update.py --skip-database   # Skip database import

Requirements:
    - Python 3.8+
    - pyodbc (for database import)
    - Internet access (for FAA data download)

Run from project root directory.
"""

import subprocess
import sys
import argparse
import time
from datetime import datetime
from pathlib import Path


# ==============================================================================
# Configuration
# ==============================================================================

SCRIPT_DIR = Path(__file__).parent
SCRIPTS = {
    'nasr': SCRIPT_DIR / "nasr_navdata_updater.py",
    'playbook': SCRIPT_DIR / "scripts" / "update_playbook_routes.py",
    'database': SCRIPT_DIR / "adl" / "scripts" / "airac_update.py",
}


# ==============================================================================
# Step Runners
# ==============================================================================

def run_step(name: str, script: Path, args: list = None, dry_run: bool = False) -> bool:
    """
    Run a step script and capture output.

    Returns True if successful, False otherwise.
    """
    if not script.exists():
        print(f"  ERROR: Script not found: {script}")
        return False

    cmd = [sys.executable, str(script)]
    if args:
        cmd.extend(args)
    if dry_run:
        cmd.append("--dry-run")

    print(f"  Command: {' '.join(cmd)}")
    print()

    try:
        # Run subprocess with real-time output
        process = subprocess.Popen(
            cmd,
            stdout=subprocess.PIPE,
            stderr=subprocess.STDOUT,
            text=True,
            bufsize=1,
            universal_newlines=True,
            cwd=str(SCRIPT_DIR)
        )

        # Stream output in real-time
        for line in process.stdout:
            print(f"    {line}", end='')

        process.wait()

        if process.returncode != 0:
            print(f"\n  Step '{name}' failed with exit code {process.returncode}")
            return False

        return True

    except Exception as e:
        print(f"  ERROR running {name}: {e}")
        return False


def step1_nasr_update(dry_run: bool = False, force: bool = False) -> bool:
    """
    Step 1: Download FAA NASR data and update local CSV/JS files.

    Updates:
      - assets/data/points.csv
      - assets/data/navaids.csv
      - assets/data/awys.csv
      - assets/data/cdrs.csv
      - assets/data/dp_full_routes.csv
      - assets/data/star_full_routes.csv
      - assets/js/awys.js
      - assets/js/procs.js
    """
    print("\n" + "=" * 70)
    print("  STEP 1: FAA NASR NavData Update")
    print("=" * 70)
    print("  Downloads current + next AIRAC cycle data from FAA NASR")
    print("  Updates: points.csv, navaids.csv, awys.csv, cdrs.csv,")
    print("           dp_full_routes.csv, star_full_routes.csv, awys.js, procs.js")
    print()

    args = []
    if force:
        args.append("--force")

    return run_step("NASR Update", SCRIPTS['nasr'], args, dry_run)


def step2_playbook_update(dry_run: bool = False) -> bool:
    """
    Step 2: Scrape FAA Playbook and update playbook_routes.csv.

    Updates:
      - assets/data/playbook_routes.csv
    """
    print("\n" + "=" * 70)
    print("  STEP 2: FAA Playbook Routes Update")
    print("=" * 70)
    print("  Scrapes current routes from fly.faa.gov/playbook")
    print("  Updates: playbook_routes.csv")
    print()

    return run_step("Playbook Update", SCRIPTS['playbook'], dry_run=dry_run)


def step3_database_import(dry_run: bool = False, table: str = None) -> bool:
    """
    Step 3: Import CSV data to VATSIM_REF and sync to VATSIM_ADL.

    Imports:
      - nav_fixes       <- points.csv + navaids.csv
      - airways         <- awys.csv
      - coded_departure_routes <- cdrs.csv
      - nav_procedures  <- dp_full_routes.csv + star_full_routes.csv
      - playbook_routes <- playbook_routes.csv

    Then syncs VATSIM_REF -> VATSIM_ADL cache.
    """
    print("\n" + "=" * 70)
    print("  STEP 3: Database Import & Sync")
    print("=" * 70)
    print("  Imports CSV data to VATSIM_REF (authoritative)")
    print("  Syncs to VATSIM_ADL (cache for sp_ParseRoute)")
    print()

    args = []
    if table:
        args.extend(["--table", table])

    return run_step("Database Import", SCRIPTS['database'], args, dry_run)


# ==============================================================================
# Main
# ==============================================================================

def print_banner():
    """Print the startup banner."""
    print()
    print("=" * 70)
    print("            AIRAC FULL UPDATE - Master Script")
    print("=" * 70)
    print()
    print("  This script performs the complete AIRAC update in three steps:")
    print()
    print("    1. NASR Update     - Download FAA navdata, update CSVs")
    print("    2. Playbook Update - Scrape FAA playbook, update routes")
    print("    3. Database Import - Import to VATSIM_REF, sync to VATSIM_ADL")
    print()


def main():
    parser = argparse.ArgumentParser(
        description='AIRAC Full Update - Complete AIRAC update workflow',
        formatter_class=argparse.RawDescriptionHelpFormatter,
        epilog="""
Examples:
  python airac_full_update.py                   # Full update (all steps)
  python airac_full_update.py --dry-run         # Preview what would happen
  python airac_full_update.py --step 1          # Only run NASR update
  python airac_full_update.py --step 2          # Only run Playbook update
  python airac_full_update.py --step 3          # Only run Database import
  python airac_full_update.py --skip-playbook   # Skip playbook (faster)
  python airac_full_update.py --skip-database   # Only update local files
  python airac_full_update.py --force           # Force re-download of NASR data

After running, don't forget to:
  1. Review the changes in assets/data/
  2. Commit and push the updated CSV/JS files
  3. Deploy to production
"""
    )

    parser.add_argument('--dry-run', action='store_true',
                        help='Preview without making changes')
    parser.add_argument('--step', type=int, choices=[1, 2, 3],
                        help='Run only a specific step (1=NASR, 2=Playbook, 3=Database)')
    parser.add_argument('--skip-playbook', action='store_true',
                        help='Skip playbook update (Step 2)')
    parser.add_argument('--skip-database', action='store_true',
                        help='Skip database import (Step 3)')
    parser.add_argument('--force', action='store_true',
                        help='Force re-download of NASR data even if cached')
    parser.add_argument('--table',
                        choices=['nav_fixes', 'airways', 'cdrs', 'procedures', 'playbook'],
                        help='Only import specific table (Step 3 only)')

    args = parser.parse_args()

    print_banner()

    print(f"  Started: {datetime.now().strftime('%Y-%m-%d %H:%M:%S')}")
    if args.dry_run:
        print("  Mode: DRY RUN (no changes will be made)")
    print()

    start_time = time.time()
    results = {}

    # Determine which steps to run
    run_step1 = args.step is None or args.step == 1
    run_step2 = (args.step is None or args.step == 2) and not args.skip_playbook
    run_step3 = (args.step is None or args.step == 3) and not args.skip_database

    # Step 1: NASR Update
    if run_step1:
        success = step1_nasr_update(args.dry_run, args.force)
        results['NASR Update'] = 'SUCCESS' if success else 'FAILED'
        if not success and args.step is None:
            print("\n  WARNING: NASR update failed, continuing with remaining steps...")

    # Step 2: Playbook Update
    if run_step2:
        success = step2_playbook_update(args.dry_run)
        results['Playbook Update'] = 'SUCCESS' if success else 'FAILED'
        if not success and args.step is None:
            print("\n  WARNING: Playbook update failed, continuing with remaining steps...")

    # Step 3: Database Import
    if run_step3:
        success = step3_database_import(args.dry_run, args.table)
        results['Database Import'] = 'SUCCESS' if success else 'FAILED'

    # Summary
    elapsed = time.time() - start_time
    elapsed_min = int(elapsed // 60)
    elapsed_sec = int(elapsed % 60)

    print("\n" + "=" * 70)
    print("                         AIRAC UPDATE COMPLETE")
    print("=" * 70)
    print()
    print("  Results:")
    for step_name, status in results.items():
        icon = "+" if status == 'SUCCESS' else "X"
        print(f"    [{icon}] {step_name}: {status}")

    print()
    print(f"  Duration: {elapsed_min}m {elapsed_sec}s")
    print(f"  Finished: {datetime.now().strftime('%Y-%m-%d %H:%M:%S')}")

    # Check for failures
    failed = [k for k, v in results.items() if v == 'FAILED']
    if failed:
        print()
        print("  WARNINGS:")
        for step in failed:
            print(f"    - {step} failed. Check output above for errors.")
        print()
        print("=" * 70)
        sys.exit(1)

    print()
    print("  NEXT STEPS:")
    print("    1. Review changes in assets/data/ and assets/js/")
    print("    2. git add -A && git commit -m 'Update AIRAC navdata'")
    print("    3. git push && deploy to production")
    print()
    print("=" * 70)


if __name__ == "__main__":
    main()
