#!/usr/bin/env python3
"""
Daily VATUSA Event Statistics Update

Automatically fetches new events from Statsim.net that haven't been imported yet.
Determines the "since" date by querying the database for the most recent event.

Run daily via Task Scheduler or cron:
    python daily_event_update.py

Or manually with options:
    python daily_event_update.py --days-back 7   # Look back 7 days from latest
    python daily_event_update.py --dry-run       # Show what would be imported
    python daily_event_update.py --sql-only      # Generate SQL without importing
"""

import argparse
import logging
import sys
from datetime import datetime, timedelta, timezone
from pathlib import Path

# Add parent directory to path for imports
sys.path.insert(0, str(Path(__file__).parent))

from fetch_new_events import StatsimScraper, SQLGenerator, DatabaseImporter
import os

logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s - %(levelname)s - %(message)s',
    handlers=[
        logging.StreamHandler(),
        logging.FileHandler(Path(__file__).parent / 'vatsim_adl.log')
    ]
)
logger = logging.getLogger(__name__)


def get_latest_event_date() -> datetime:
    """Query database for the most recent event date (returns naive datetime)."""
    try:
        import pyodbc
    except ImportError:
        logger.warning("pyodbc not available, using default date")
        return datetime.utcnow() - timedelta(days=30)

    connection_string = (
        "Driver={ODBC Driver 17 for SQL Server};"
        "Server=vatsim.database.windows.net;"
        "Database=VATSIM_ADL;"
        "Uid=jpeterson;"
        f"Pwd={os.environ.get('DDL_SQL_PASSWORD', '')};"
    )

    try:
        conn = pyodbc.connect(connection_string)
        cursor = conn.cursor()
        cursor.execute("""
            SELECT MAX(start_utc) FROM dbo.vatusa_event
            WHERE source = 'STATSIM'
        """)
        result = cursor.fetchone()
        conn.close()

        if result and result[0]:
            # Return naive datetime (pyodbc returns naive datetime from SQL Server)
            dt = result[0]
            if hasattr(dt, 'tzinfo') and dt.tzinfo is not None:
                dt = dt.replace(tzinfo=None)
            return dt
        else:
            # No STATSIM events yet, use all events
            conn = pyodbc.connect(connection_string)
            cursor = conn.cursor()
            cursor.execute("SELECT MAX(start_utc) FROM dbo.vatusa_event")
            result = cursor.fetchone()
            conn.close()

            if result and result[0]:
                dt = result[0]
                if hasattr(dt, 'tzinfo') and dt.tzinfo is not None:
                    dt = dt.replace(tzinfo=None)
                return dt

    except Exception as e:
        logger.error(f"Database query failed: {e}")

    # Default: 30 days ago (naive datetime)
    return datetime.utcnow() - timedelta(days=30)


def main():
    parser = argparse.ArgumentParser(
        description='Daily VATUSA event statistics update'
    )

    parser.add_argument(
        '--days-back',
        type=int,
        default=3,
        help='Days to look back from latest event (default: 3)'
    )

    parser.add_argument(
        '--dry-run',
        action='store_true',
        help='Show what would be imported without making changes'
    )

    parser.add_argument(
        '--sql-only',
        action='store_true',
        help='Generate SQL file instead of direct import'
    )

    parser.add_argument(
        '-o', '--output',
        default=None,
        help='Output SQL file path (default: auto-generated)'
    )

    parser.add_argument(
        '-v', '--verbose',
        action='store_true',
        help='Verbose output'
    )

    args = parser.parse_args()

    if args.verbose:
        logging.getLogger().setLevel(logging.DEBUG)

    # Get the latest event date from database
    latest_date = get_latest_event_date()
    logger.info(f"Latest event in database: {latest_date.strftime('%Y-%m-%d %H:%M')}")

    # Calculate since date (look back a few days to catch any missed events)
    since_date = latest_date - timedelta(days=args.days_back)
    logger.info(f"Fetching events since: {since_date.strftime('%Y-%m-%d')}")

    # Initialize scraper
    scraper = StatsimScraper()

    # Fetch events
    events = scraper.get_past_events(since_date=since_date, us_only=True)

    if not events:
        logger.info("No new events found")
        return 0

    logger.info(f"Found {len(events)} events to check")

    if args.dry_run:
        print(f"\nDry run - {len(events)} events would be checked:")
        for e in events:
            print(f"  {e.start_utc.strftime('%Y-%m-%d')} | {e.airports_raw[:25]:25} | {e.name[:45]}")
        return 0

    # Fetch details for each event
    logger.info("Fetching event details...")
    for i, event in enumerate(events):
        logger.debug(f"[{i+1}/{len(events)}] {event.name[:50]}")
        scraper.get_event_details(event)

    # Filter events with actual data
    events_with_data = [e for e in events if e.airport_stats]

    if not events_with_data:
        logger.info("No events with flight data to import")
        return 0

    logger.info(f"{len(events_with_data)} events have airport statistics")

    # Generate SQL or import directly
    if args.sql_only:
        generator = SQLGenerator()
        sql = generator.generate(events_with_data)

        output_path = args.output or f"event_update_{datetime.now().strftime('%Y%m%d_%H%M%S')}.sql"
        Path(output_path).write_text(sql, encoding='utf-8')

        logger.info(f"Generated SQL: {output_path}")
        print(f"\nSQL file: {output_path}")
        print(f"Events: {len(events_with_data)}")
        print(f"\nTo import: sqlcmd -S vatsim.database.windows.net -d VATSIM_ADL -i {output_path}")

    else:
        # Direct database import
        importer = DatabaseImporter()
        try:
            inserted, skipped = importer.import_events(events_with_data)
            logger.info(f"Import complete: {inserted} inserted, {skipped} skipped")

            print(f"\nImport Summary:")
            print(f"  New events inserted: {inserted}")
            print(f"  Existing events skipped: {skipped}")

            if inserted > 0:
                logger.info(f"Successfully imported {inserted} new events")
            else:
                logger.info("No new events to import (all already exist)")

        except Exception as e:
            logger.error(f"Import failed: {e}")
            return 1
        finally:
            importer.close()

    return 0


if __name__ == '__main__':
    sys.exit(main())
