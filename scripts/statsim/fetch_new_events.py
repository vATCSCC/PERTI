#!/usr/bin/env python3
"""
Fetch New VATUSA Events from Statsim.net

Scrapes Statsim.net for past events with US airports and generates SQL
for importing into the VATUSA Event Statistics database.

Usage:
    # Fetch events since a specific date
    python fetch_new_events.py --since 2025-11-14

    # Fetch events and generate SQL
    python fetch_new_events.py --since 2025-11-14 -o new_events.sql

    # Fetch and import directly to database
    python fetch_new_events.py --since 2025-11-14 --import

    # List events only (no stats fetch)
    python fetch_new_events.py --since 2025-11-14 --list-only
"""

import argparse
import logging
import re
import sys
from dataclasses import dataclass, field
from datetime import datetime, timedelta, timezone
from pathlib import Path
from typing import Dict, List, Optional, Tuple
from urllib.parse import urljoin, quote
import os

try:
    import requests
except ImportError:
    print("Installing requests...")
    import subprocess
    subprocess.check_call([sys.executable, "-m", "pip", "install", "requests"])
    import requests

try:
    from bs4 import BeautifulSoup
except ImportError:
    print("Installing beautifulsoup4...")
    import subprocess
    subprocess.check_call([sys.executable, "-m", "pip", "install", "beautifulsoup4"])
    from bs4 import BeautifulSoup

logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s - %(levelname)s - %(message)s'
)
logger = logging.getLogger(__name__)


# =============================================================================
# DATA CLASSES
# =============================================================================

@dataclass
class AirportStats:
    """Flight statistics for a single airport in an event."""
    icao: str
    departures: int = 0
    arrivals: int = 0
    total: int = 0
    is_featured: bool = True

    def __post_init__(self):
        if self.total == 0:
            self.total = self.departures + self.arrivals


@dataclass
class StatsimEvent:
    """Event data from Statsim.net."""
    event_id: int
    name: str
    start_utc: datetime
    end_utc: datetime
    airports_raw: str
    event_type: str = "Event"

    # Populated when fetching details
    airport_stats: List[AirportStats] = field(default_factory=list)
    total_movements: int = 0

    @property
    def airports(self) -> List[str]:
        """Parse airports from raw string."""
        if not self.airports_raw:
            return []
        return [a.strip() for a in self.airports_raw.split(',') if a.strip()]

    @property
    def event_idx(self) -> str:
        """Generate event index in format: YYYYMMDDHHMMT YYYYMMDDHHMM/TYPE/CODE"""
        start_str = self.start_utc.strftime('%Y%m%d%H%M')
        end_str = self.end_utc.strftime('%Y%m%d%H%M')
        cat = self._detect_category()
        code = f"{cat}{self.event_id % 100:02d}"
        return f"{start_str}T{end_str}/{cat}/{code}"

    @property
    def day_of_week(self) -> str:
        """Get day of week abbreviation."""
        return self.start_utc.strftime('%a')

    @property
    def duration_hours(self) -> float:
        """Get event duration in hours."""
        delta = self.end_utc - self.start_utc
        return delta.total_seconds() / 3600

    def _detect_category(self) -> str:
        """Detect event category from name.

        Uses the same classification logic as sync_perti_events.php:
        - Name-based patterns for special events (CTP, 24HRSOV, REALOPS, etc.)
        - VATUSA day-of-week conventions (FNO, SAT, SUN, MWK) for unmatched events
        - UNKN for truly unclassifiable events
        """
        name_lower = self.name.lower()

        # Priority 1: Explicit "Not an FNO" exclusion
        if 'not an fno' in name_lower or 'not a fno' in name_lower:
            return 'MWK'

        # Priority 2: Cross-division special events (by name)
        patterns = {
            'CTP': [r'cross the pond', r'cross-the-pond', r'\bctp\b'],
            'CTL': [r'cross the land', r'cross-the-land', r'\bctl\b'],
            'WF': [r'worldflight', r'world flight'],
            '24HRSOV': [r'24hr', r'24 hour', r'sovereignty'],
            'FNO': [r'friday night ops', r'friday night operations', r'\bfno\b'],
            'OMN': [r'open mic', r'\bomn\b'],  # OMN but not KOMN airport
            'REALOPS': [r'real ops', r'realops', r'real-ops', r'real operations'],
            'LIVE': [r'\blive\b'],
            'TRAIN': [r'training', r'exam', r'first wings'],
            'SPEC': [r'special', r'anniversary', r'celebration', r'holiday',
                     r'christmas', r'thanksgiving', r'overload', r'screamin'],
        }

        for category, pats in patterns.items():
            for pat in pats:
                if re.search(pat, name_lower):
                    # Avoid matching KOMN airport code as OMN
                    if category == 'OMN' and 'komn' in name_lower:
                        continue
                    return category

        # Priority 3: SNO detection (Saturday Night Ops)
        if re.search(r'\bsno\b', name_lower):
            return 'SAT'

        # Priority 4: VATUSA time-based classification (day-of-week)
        # This matches the Excel formula logic for VATUSA events
        day_of_week = self.start_utc.weekday()  # 0=Mon, 6=Sun
        hour = self.start_utc.hour

        # FNO: Friday 21:00+ or Saturday before 06:00 UTC
        if day_of_week == 4 and hour >= 21:  # Friday
            return 'FNO'
        if day_of_week == 5 and hour < 6:  # Saturday early morning
            return 'FNO'

        # SAT: Saturday (after 06:00)
        if day_of_week == 5:
            return 'SAT'

        # SUN: Sunday
        if day_of_week == 6:
            return 'SUN'

        # MWK: Monday through Thursday, or Friday before 21:00
        if day_of_week in [0, 1, 2, 3] or (day_of_week == 4 and hour < 21):
            return 'MWK'

        return 'UNKN'  # Fallback for truly unclassifiable events


# =============================================================================
# STATSIM SCRAPER
# =============================================================================

class StatsimScraper:
    """Scraper for Statsim.net event data."""

    BASE_URL = "https://statsim.net"

    # Keywords to identify US/VATUSA events
    US_KEYWORDS = ['VATUSA', 'vZDC', 'vZNY', 'vZBW', 'vZJX', 'vZTL', 'vZMA', 'vZHU',
                   'vZFW', 'vZLA', 'vZSE', 'vZOA', 'vZAB', 'vZAU', 'vZID', 'vZKC',
                   'vZMP', 'vZDV', 'NorCal', 'SoCal', 'America']

    def __init__(self, timeout: int = 30):
        self.session = requests.Session()
        self.session.headers.update({
            'User-Agent': 'PERTI-EventStats/2.0 (VATSIM ADL)',
        })
        self.timeout = timeout

    def get_past_events(self, since_date: datetime = None, us_only: bool = True) -> List[StatsimEvent]:
        """Fetch past events from Statsim."""
        url = f"{self.BASE_URL}/events/past/"

        try:
            response = self.session.get(url, timeout=self.timeout)
            response.raise_for_status()
        except requests.RequestException as e:
            logger.error(f"Failed to fetch past events: {e}")
            return []

        soup = BeautifulSoup(response.text, 'html.parser')
        table = soup.find('table')

        if not table:
            logger.error("No events table found on page")
            return []

        events = []
        rows = table.find_all('tr')

        for row in rows[1:]:  # Skip header
            cells = row.find_all('td')
            if len(cells) < 5:
                continue

            # Extract data from cells
            name = cells[0].get_text(strip=True)
            start_str = cells[1].get_text(strip=True)
            end_str = cells[2].get_text(strip=True)
            airports = cells[3].get_text(strip=True)
            event_type = cells[4].get_text(strip=True) if len(cells) > 4 else "Event"

            # Get event ID from link
            link = cells[0].find('a')
            event_id = None
            if link and link.get('href'):
                match = re.search(r'eventid=(\d+)', link.get('href'))
                if match:
                    event_id = int(match.group(1))

            if not event_id:
                continue

            # Parse dates
            try:
                start_utc = datetime.strptime(start_str, '%Y-%m-%d %H:%M')
                end_utc = datetime.strptime(end_str, '%Y-%m-%d %H:%M')
            except ValueError:
                continue

            # Filter by date
            if since_date and start_utc < since_date:
                continue

            # Filter for US events
            if us_only:
                airports_list = [a.strip() for a in airports.split(',') if a.strip()]
                is_us = any(a.startswith('K') or a.startswith('P') for a in airports_list)
                is_vatusa = any(kw.lower() in name.lower() for kw in self.US_KEYWORDS)

                if not (is_us or is_vatusa):
                    continue

            event = StatsimEvent(
                event_id=event_id,
                name=name,
                start_utc=start_utc,
                end_utc=end_utc,
                airports_raw=airports,
                event_type=event_type,
            )
            events.append(event)

        logger.info(f"Found {len(events)} events since {since_date}")
        return events

    def get_event_details(self, event: StatsimEvent) -> bool:
        """Fetch detailed statistics for an event."""
        url = f"{self.BASE_URL}/events/event/?eventid={event.event_id}"

        try:
            response = self.session.get(url, timeout=self.timeout)
            response.raise_for_status()
        except requests.RequestException as e:
            logger.warning(f"Failed to fetch event {event.event_id}: {e}")
            return False

        soup = BeautifulSoup(response.text, 'html.parser')

        # Get total movements from the info table
        table = soup.find('table')
        if table:
            for row in table.find_all('tr'):
                cells = row.find_all('td')
                if len(cells) == 2:
                    label = cells[0].get_text(strip=True)
                    value = cells[1].get_text(strip=True)
                    if 'Total movements' in label:
                        try:
                            event.total_movements = int(value)
                        except ValueError:
                            pass

        # Get per-airport statistics from h4 sections
        h4_tags = soup.find_all('h4')

        for h4 in h4_tags:
            text = h4.get_text(strip=True)
            icao_match = re.search(r'\(([A-Z]{4})\)', text)

            if icao_match:
                icao = icao_match.group(1)

                # Get departures/arrivals from following div
                next_div = h4.find_next_sibling('div')
                if next_div:
                    div_text = next_div.get_text()

                    dep_match = re.search(r'Departures:\s*(\d+)', div_text)
                    arr_match = re.search(r'Arrivals:\s*(\d+)', div_text)

                    departures = int(dep_match.group(1)) if dep_match else 0
                    arrivals = int(arr_match.group(1)) if arr_match else 0

                    stats = AirportStats(
                        icao=icao,
                        departures=departures,
                        arrivals=arrivals,
                    )
                    event.airport_stats.append(stats)

        logger.debug(f"Event {event.event_id}: {len(event.airport_stats)} airports, {event.total_movements} movements")
        return True


# =============================================================================
# SQL GENERATOR
# =============================================================================

class SQLGenerator:
    """Generates SQL statements for database import."""

    @staticmethod
    def escape(value) -> str:
        """Escape value for SQL."""
        if value is None:
            return 'NULL'
        if isinstance(value, bool):
            return '1' if value else '0'
        if isinstance(value, (int, float)):
            return str(value)
        if isinstance(value, datetime):
            return f"'{value.strftime('%Y-%m-%d %H:%M:%S')}'"
        # String - escape single quotes
        s = str(value).replace("'", "''")
        return f"N'{s}'"

    def generate(self, events: List[StatsimEvent]) -> str:
        """Generate complete SQL import script."""
        timestamp = datetime.now(timezone.utc).strftime('%Y-%m-%d %H:%M:%S')

        lines = [
            "-- ============================================================================",
            "-- VATUSA Event Statistics - New Events Import",
            f"-- Generated: {timestamp}",
            f"-- Source: Statsim.net",
            f"-- Events: {len(events)}",
            "-- ============================================================================",
            "",
            "SET NOCOUNT ON;",
            "GO",
            "",
        ]

        # Generate event inserts
        for event in events:
            lines.extend(self._generate_event_sql(event))
            lines.append("")

        lines.extend([
            "-- ============================================================================",
            "-- Summary",
            "-- ============================================================================",
            f"PRINT 'Imported {len(events)} events';",
            "GO",
            "",
            "SELECT 'vatusa_event' AS [Table], COUNT(*) AS [Count] FROM dbo.vatusa_event",
            "UNION ALL",
            "SELECT 'vatusa_event_airport', COUNT(*) FROM dbo.vatusa_event_airport;",
            "GO",
        ])

        return '\n'.join(lines)

    def _generate_event_sql(self, event: StatsimEvent) -> List[str]:
        """Generate SQL for a single event."""
        lines = []

        event_idx = event.event_idx
        total_arr = sum(s.arrivals for s in event.airport_stats)
        total_dep = sum(s.departures for s in event.airport_stats)
        total_ops = total_arr + total_dep

        # Season calculation
        month = event.start_utc.month
        if month in [12, 1, 2]:
            season = 'Winter'
        elif month in [3, 4, 5]:
            season = 'Spring'
        elif month in [6, 7, 8]:
            season = 'Summer'
        else:
            season = 'Fall'

        lines.append(f"-- Event: {event.name[:60]}")
        lines.append(f"-- Statsim ID: {event.event_id}")
        lines.append(f"""
IF NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = {self.escape(event_idx)})
BEGIN
    INSERT INTO dbo.vatusa_event (
        event_idx, event_name, event_type, event_code,
        start_utc, end_utc, day_of_week,
        total_arrivals, total_departures, total_operations, airport_count,
        season, month_num, year_num, source
    ) VALUES (
        {self.escape(event_idx)},
        {self.escape(event.name)},
        {self.escape(event._detect_category())},
        {self.escape(event._detect_category() + str(event.event_id % 100).zfill(2))},
        {self.escape(event.start_utc)},
        {self.escape(event.end_utc)},
        {self.escape(event.day_of_week)},
        {total_arr},
        {total_dep},
        {total_ops},
        {len(event.airport_stats)},
        {self.escape(season)},
        {event.start_utc.month},
        {event.start_utc.year},
        'STATSIM'
    );
    PRINT 'Inserted event: {event.name[:50].replace("'", "''")}';
END
ELSE
BEGIN
    PRINT 'Skipped existing event: {event_idx}';
END
GO""")

        # Airport stats
        for stats in event.airport_stats:
            lines.append(f"""
IF NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = {self.escape(event_idx)} AND airport_icao = {self.escape(stats.icao)})
BEGIN
    INSERT INTO dbo.vatusa_event_airport (
        event_idx, airport_icao, is_featured,
        total_arrivals, total_departures, total_operations
    ) VALUES (
        {self.escape(event_idx)},
        {self.escape(stats.icao)},
        1,
        {stats.arrivals},
        {stats.departures},
        {stats.total}
    );
END
GO""")

        return lines


# =============================================================================
# DATABASE IMPORTER
# =============================================================================

class DatabaseImporter:
    """Direct database import using pyodbc."""

    def __init__(self, connection_string: str = None):
        self.connection_string = connection_string or (
            "Driver={ODBC Driver 17 for SQL Server};"
            "Server=vatsim.database.windows.net;"
            "Database=VATSIM_ADL;"
            "Uid=jpeterson;"
            f"Pwd={os.environ.get('DDL_SQL_PASSWORD', '')};"
        )
        self.conn = None

    def connect(self):
        """Establish database connection."""
        try:
            import pyodbc
        except ImportError:
            print("Installing pyodbc...")
            import subprocess
            subprocess.check_call([sys.executable, "-m", "pip", "install", "pyodbc"])
            import pyodbc

        self.conn = pyodbc.connect(self.connection_string)
        logger.info("Connected to database")

    def import_events(self, events: List[StatsimEvent]) -> Tuple[int, int]:
        """Import events to database. Returns (inserted, skipped) counts."""
        if not self.conn:
            self.connect()

        cursor = self.conn.cursor()
        inserted = 0
        skipped = 0

        for event in events:
            event_idx = event.event_idx

            # Check if event exists
            cursor.execute(
                "SELECT 1 FROM dbo.vatusa_event WHERE event_idx = ?",
                event_idx
            )

            if cursor.fetchone():
                logger.debug(f"Skipped existing: {event_idx}")
                skipped += 1
                continue

            # Calculate totals
            total_arr = sum(s.arrivals for s in event.airport_stats)
            total_dep = sum(s.departures for s in event.airport_stats)
            total_ops = total_arr + total_dep

            # Season
            month = event.start_utc.month
            season = 'Winter' if month in [12, 1, 2] else \
                     'Spring' if month in [3, 4, 5] else \
                     'Summer' if month in [6, 7, 8] else 'Fall'

            # Insert event
            cursor.execute("""
                INSERT INTO dbo.vatusa_event (
                    event_idx, event_name, event_type, event_code,
                    start_utc, end_utc, day_of_week,
                    total_arrivals, total_departures, total_operations, airport_count,
                    season, month_num, year_num, source
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'STATSIM')
            """, (
                event_idx, event.name, event._detect_category(),
                event._detect_category() + str(event.event_id % 100).zfill(2),
                event.start_utc, event.end_utc, event.day_of_week,
                total_arr, total_dep, total_ops, len(event.airport_stats),
                season, event.start_utc.month, event.start_utc.year
            ))

            # Insert airport stats
            for stats in event.airport_stats:
                cursor.execute("""
                    INSERT INTO dbo.vatusa_event_airport (
                        event_idx, airport_icao, is_featured,
                        total_arrivals, total_departures, total_operations
                    ) VALUES (?, ?, 1, ?, ?, ?)
                """, (
                    event_idx, stats.icao,
                    stats.arrivals, stats.departures, stats.total
                ))

            inserted += 1
            logger.info(f"Inserted: {event.name[:50]} ({len(event.airport_stats)} airports)")

        self.conn.commit()
        return inserted, skipped

    def close(self):
        """Close database connection."""
        if self.conn:
            self.conn.close()
            self.conn = None


# =============================================================================
# MAIN
# =============================================================================

def main():
    parser = argparse.ArgumentParser(
        description='Fetch new VATUSA events from Statsim.net'
    )

    parser.add_argument(
        '--since',
        type=lambda s: datetime.strptime(s, '%Y-%m-%d'),
        help='Fetch events since date (YYYY-MM-DD)'
    )

    parser.add_argument(
        '--days',
        type=int,
        default=30,
        help='Days back to fetch (default: 30, ignored if --since is set)'
    )

    parser.add_argument(
        '-o', '--output',
        help='Output SQL file path'
    )

    parser.add_argument(
        '--import',
        dest='do_import',
        action='store_true',
        help='Import directly to database'
    )

    parser.add_argument(
        '--list-only',
        action='store_true',
        help='List events without fetching details'
    )

    parser.add_argument(
        '--all-events',
        action='store_true',
        help='Include all events (not just US/VATUSA)'
    )

    parser.add_argument(
        '-v', '--verbose',
        action='store_true',
        help='Verbose output'
    )

    args = parser.parse_args()

    if args.verbose:
        logging.getLogger().setLevel(logging.DEBUG)

    # Calculate since date
    since_date = args.since or (datetime.now() - timedelta(days=args.days))

    # Initialize scraper
    scraper = StatsimScraper()

    # Fetch events
    logger.info(f"Fetching events since {since_date.strftime('%Y-%m-%d')}...")
    events = scraper.get_past_events(
        since_date=since_date,
        us_only=not args.all_events
    )

    if not events:
        print("No events found")
        return

    print(f"\nFound {len(events)} events:\n")

    if args.list_only:
        for e in events:
            airports = e.airports_raw[:25] if e.airports_raw else '(none)'
            print(f"  {e.event_id:>6} | {e.start_utc.strftime('%Y-%m-%d %H:%M')} | {airports:25} | {e.name[:45]}")
        return

    # Fetch details for each event
    logger.info("Fetching event details...")
    for i, event in enumerate(events):
        print(f"  [{i+1}/{len(events)}] {event.name[:50]}...", end=" ")
        success = scraper.get_event_details(event)
        if success:
            print(f"{event.total_movements} movements")
        else:
            print("FAILED")

    # Filter events with actual data
    events_with_data = [e for e in events if e.airport_stats]
    print(f"\n{len(events_with_data)} events have airport statistics")

    if not events_with_data:
        print("No events with data to import")
        return

    # Generate SQL or import
    if args.do_import:
        logger.info("Importing to database...")
        importer = DatabaseImporter()
        try:
            inserted, skipped = importer.import_events(events_with_data)
            print(f"\nDatabase import complete:")
            print(f"  Inserted: {inserted}")
            print(f"  Skipped (existing): {skipped}")
        finally:
            importer.close()

    elif args.output:
        generator = SQLGenerator()
        sql = generator.generate(events_with_data)

        output_path = Path(args.output)
        output_path.write_text(sql, encoding='utf-8')

        print(f"\nGenerated SQL: {output_path}")
        print(f"Events: {len(events_with_data)}")
        print(f"\nTo import:")
        print(f"  sqlcmd -S vatsim.database.windows.net -d VATSIM_ADL -U jpeterson -P <pass> -i {output_path}")

    else:
        # Print summary
        print("\nEvent Summary:")
        print("-" * 80)
        total_ops = 0
        for e in events_with_data:
            ops = sum(s.total for s in e.airport_stats)
            total_ops += ops
            airports = ', '.join(s.icao for s in e.airport_stats)
            print(f"{e.start_utc.strftime('%Y-%m-%d')} | {ops:4} ops | {airports[:30]:30} | {e.name[:35]}")
        print("-" * 80)
        print(f"Total operations: {total_ops}")
        print(f"\nUse -o FILE.sql to generate SQL or --import to insert directly")


if __name__ == '__main__':
    main()
