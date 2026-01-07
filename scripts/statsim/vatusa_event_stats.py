#!/usr/bin/env python3
"""
VATUSA Event Statistics Fetcher

Fetches VATUSA event metadata from the VATUSA API and flight statistics
from Statsim.net, then generates SQL for import into Azure SQL.

Usage:
    # Fetch recent events and generate SQL
    python vatusa_event_stats.py -o event_import.sql

    # Fetch with custom airport mappings
    python vatusa_event_stats.py -m event_facilities.json -o event_import.sql

    # Fetch specific date range
    python vatusa_event_stats.py --start 2024-01-01 --end 2024-01-31 -o jan_events.sql

    # List events without fetching stats (dry run)
    python vatusa_event_stats.py --list-only

Requirements:
    pip install requests beautifulsoup4
"""

import argparse
import json
import logging
import re
import sys
from dataclasses import dataclass, field
from datetime import datetime, date, timedelta
from pathlib import Path
from typing import Dict, List, Optional, Tuple
from urllib.parse import urljoin

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
    """Flight statistics for a single airport."""
    icao: str
    departures: int = 0
    arrivals: int = 0
    total: int = 0
    is_featured: bool = True


@dataclass
class VATUSAEvent:
    """VATUSA event with metadata and flight stats."""
    event_id: int
    title: str
    start_date: date
    end_date: Optional[date] = None
    start_utc: Optional[datetime] = None
    end_utc: Optional[datetime] = None
    topic_id: Optional[int] = None
    category: Optional[str] = None
    airports: List[str] = field(default_factory=list)
    airport_stats: List[AirportStats] = field(default_factory=list)

    @property
    def event_idx(self) -> str:
        """Generate event index in format: YYYYMMDDHHMMT YYYYMMDDHHMM/TYPE/CODE"""
        start_str = self.start_utc.strftime('%Y%m%d%H%M') if self.start_utc else self.start_date.strftime('%Y%m%d') + '0000'
        end_str = self.end_utc.strftime('%Y%m%d%H%M') if self.end_utc else self.start_date.strftime('%Y%m%d') + '2359'
        cat = self.category or 'UNK'
        code = f"{cat}{self.event_id % 100:02d}"
        return f"{start_str}T{end_str}/{cat}/{code}"

    @property
    def total_operations(self) -> int:
        return sum(s.total for s in self.airport_stats)


# =============================================================================
# VATUSA API CLIENT
# =============================================================================

class VATUSAClient:
    """Client for VATUSA public API."""

    BASE_URL = "https://api.vatusa.net"
    EVENTS_ENDPOINT = "/v2/public/events"

    # Regex patterns for extracting airports from event titles
    ICAO_PATTERNS = [
        r'\(([A-Z]{4})\)',                    # (KORD), (KJFK)
        r'\b(K[A-Z]{3})\b',                   # KORD, KJFK
        r'\b(P[AGHW][A-Z]{2})\b',             # PAFA, PHOG (Alaska, Hawaii)
        r'\b(T[IJ][A-Z]{2})\b',               # TJSJ (Puerto Rico)
        r'([A-Z]{4})/([A-Z]{4})',             # KJFK/KLGA
    ]

    # Event category detection patterns
    CATEGORY_PATTERNS = {
        'FNO': [r'friday night ops', r'friday night operations', r'\bfno\b'],
        'CTP': [r'cross the pond', r'\bctp\b'],
        'WEX': [r'westbound', r'eastbound'],
        'SAT': [r'saturday', r'\bsat\b'],
        'MWK': [r'midweek'],
        'TRA': [r'training'],
        'SPL': [r'special', r'anniversary', r'celebration'],
    }

    def __init__(self, timeout: int = 30):
        self.session = requests.Session()
        self.session.headers.update({
            'User-Agent': 'PERTI-EventStats/1.0',
            'Accept': 'application/json'
        })
        self.timeout = timeout

    def get_events(self, limit: int = 100) -> List[Dict]:
        """Fetch recent events from VATUSA API."""
        url = f"{self.BASE_URL}{self.EVENTS_ENDPOINT}"
        params = {'limit': limit}

        try:
            response = self.session.get(url, params=params, timeout=self.timeout)
            response.raise_for_status()
            data = response.json()

            # API returns {'data': [...events...]}
            events = data.get('data', data) if isinstance(data, dict) else data
            logger.info(f"Fetched {len(events)} events from VATUSA API")
            return events

        except requests.RequestException as e:
            logger.error(f"Failed to fetch VATUSA events: {e}")
            return []

    def parse_event(self, raw: Dict) -> Optional[VATUSAEvent]:
        """Parse raw API response into VATUSAEvent object."""
        try:
            event_id = raw.get('id') or raw.get('id_event')
            title = raw.get('name') or raw.get('title', '')

            # Parse dates
            start_str = raw.get('start') or raw.get('start_date')
            end_str = raw.get('end') or raw.get('end_date')

            start_date = self._parse_date(start_str)
            end_date = self._parse_date(end_str) if end_str else start_date

            if not event_id or not start_date:
                return None

            event = VATUSAEvent(
                event_id=int(event_id),
                title=title,
                start_date=start_date,
                end_date=end_date,
                topic_id=raw.get('id_topic'),
            )

            # Extract airports and category from title
            event.airports = self.extract_airports(title)
            event.category = self.detect_category(title)

            # Try to parse times if available
            event.start_utc = self._parse_datetime(start_str)
            event.end_utc = self._parse_datetime(end_str)

            return event

        except Exception as e:
            logger.warning(f"Failed to parse event: {e}")
            return None

    def extract_airports(self, title: str) -> List[str]:
        """Extract ICAO airport codes from event title."""
        airports = set()
        title_upper = title.upper()

        for pattern in self.ICAO_PATTERNS:
            matches = re.findall(pattern, title_upper)
            for match in matches:
                if isinstance(match, tuple):
                    airports.update(m for m in match if m)
                else:
                    airports.add(match)

        # Filter to valid-looking ICAO codes
        return sorted([a for a in airports if len(a) == 4 and a.isalpha()])

    def detect_category(self, title: str) -> Optional[str]:
        """Detect event category from title."""
        title_lower = title.lower()

        for category, patterns in self.CATEGORY_PATTERNS.items():
            for pattern in patterns:
                if re.search(pattern, title_lower):
                    return category

        return 'EVT'  # Generic event

    def _parse_date(self, date_str: str) -> Optional[date]:
        """Parse date string to date object."""
        if not date_str:
            return None

        formats = [
            '%Y-%m-%dT%H:%M:%S.%fZ',
            '%Y-%m-%dT%H:%M:%SZ',
            '%Y-%m-%dT%H:%M:%S',
            '%Y-%m-%d %H:%M:%S',
            '%Y-%m-%d',
        ]

        for fmt in formats:
            try:
                return datetime.strptime(date_str, fmt).date()
            except ValueError:
                continue

        return None

    def _parse_datetime(self, dt_str: str) -> Optional[datetime]:
        """Parse datetime string to datetime object."""
        if not dt_str:
            return None

        formats = [
            '%Y-%m-%dT%H:%M:%S.%fZ',
            '%Y-%m-%dT%H:%M:%SZ',
            '%Y-%m-%dT%H:%M:%S',
            '%Y-%m-%d %H:%M:%S',
        ]

        for fmt in formats:
            try:
                return datetime.strptime(dt_str, fmt)
            except ValueError:
                continue

        return None


# =============================================================================
# STATSIM SCRAPER
# =============================================================================

class StatsimScraper:
    """Scraper for flight statistics from Statsim.net."""

    BASE_URL = "https://statsim.net"
    API_URL = "https://api.statsim.net"

    def __init__(self, timeout: int = 30):
        self.session = requests.Session()
        self.session.headers.update({
            'User-Agent': 'PERTI-EventStats/1.0',
        })
        self.timeout = timeout

    def get_airport_stats(
        self,
        icao: str,
        event_date: date,
        hours_window: int = 6
    ) -> Optional[AirportStats]:
        """
        Get flight statistics for an airport on a specific date.

        Tries API first, falls back to scraping.
        """
        # Try API endpoint first
        stats = self._try_api(icao, event_date)
        if stats:
            return stats

        # Fall back to scraping
        return self._scrape_flights(icao, event_date)

    def _try_api(self, icao: str, event_date: date) -> Optional[AirportStats]:
        """Try to get stats from Statsim API."""
        try:
            # API endpoint: /airport/{icao}/flights
            url = f"{self.API_URL}/airport/{icao}/flights"
            params = {
                'date': event_date.isoformat(),
            }

            response = self.session.get(url, params=params, timeout=self.timeout)

            if response.status_code == 200:
                data = response.json()
                return AirportStats(
                    icao=icao,
                    departures=data.get('departures', 0),
                    arrivals=data.get('arrivals', 0),
                    total=data.get('total', 0)
                )

        except Exception as e:
            logger.debug(f"API failed for {icao}: {e}")

        return None

    def _scrape_flights(self, icao: str, event_date: date) -> Optional[AirportStats]:
        """Scrape flight statistics from Statsim.net flights page."""
        try:
            url = f"{self.BASE_URL}/flights/"
            params = {
                'airport': icao,
                'date': event_date.strftime('%Y-%m-%d'),
            }

            response = self.session.get(url, params=params, timeout=self.timeout)
            response.raise_for_status()

            soup = BeautifulSoup(response.text, 'html.parser')

            # Parse the stats from the page
            # Look for statistics table or summary
            departures = 0
            arrivals = 0

            # Try to find stats in common locations
            stats_div = soup.find('div', class_='stats') or soup.find('div', id='stats')
            if stats_div:
                dep_elem = stats_div.find(string=re.compile(r'departures?', re.I))
                arr_elem = stats_div.find(string=re.compile(r'arrivals?', re.I))

                if dep_elem:
                    departures = self._extract_number(dep_elem.parent.text)
                if arr_elem:
                    arrivals = self._extract_number(arr_elem.parent.text)

            # Alternative: count flight rows
            if departures == 0 and arrivals == 0:
                flight_rows = soup.find_all('tr', class_='flight-row') or \
                              soup.find_all('tr', {'data-flight': True})

                for row in flight_rows:
                    flight_type = row.get('data-type') or row.find('td', class_='type')
                    if flight_type:
                        type_text = str(flight_type).lower()
                        if 'dep' in type_text:
                            departures += 1
                        elif 'arr' in type_text:
                            arrivals += 1

            return AirportStats(
                icao=icao,
                departures=departures,
                arrivals=arrivals,
                total=departures + arrivals
            )

        except Exception as e:
            logger.warning(f"Failed to scrape {icao} for {event_date}: {e}")
            return None

    def _extract_number(self, text: str) -> int:
        """Extract first number from text."""
        match = re.search(r'\d+', text)
        return int(match.group()) if match else 0


# =============================================================================
# FACILITY MAPPER
# =============================================================================

class FacilityMapper:
    """Maps events to airports using multiple strategies."""

    def __init__(self, config_path: Optional[str] = None):
        self.event_mappings: Dict[int, List[str]] = {}
        self.name_mappings: List[Tuple[str, List[str], str]] = []
        self.facility_airports: Dict[str, List[str]] = {}

        if config_path:
            self.load_config(config_path)

    def load_config(self, path: str):
        """Load event-to-airports mapping from JSON file."""
        config_file = Path(path)
        if not config_file.exists():
            logger.warning(f"Config file not found: {path}")
            return

        try:
            with open(config_file, 'r') as f:
                config = json.load(f)

            # Load event-specific mappings
            for event in config.get('events', []):
                if 'vatusa_event_id' in event:
                    self.event_mappings[event['vatusa_event_id']] = event.get('airports', [])
                if 'name_match' in event:
                    self.name_mappings.append((
                        event['name_match'].lower(),
                        event.get('airports', []),
                        event.get('category', 'EVT')
                    ))

            logger.info(f"Loaded {len(self.event_mappings)} event mappings")

        except Exception as e:
            logger.error(f"Failed to load config: {e}")

    def load_facility_airports(self, path: str):
        """Load ARTCC-to-airports mapping."""
        facility_file = Path(path)
        if not facility_file.exists():
            return

        try:
            with open(facility_file, 'r') as f:
                self.facility_airports = json.load(f)
            logger.info(f"Loaded {len(self.facility_airports)} facility mappings")
        except Exception as e:
            logger.error(f"Failed to load facility airports: {e}")

    def get_airports(self, event: VATUSAEvent) -> List[str]:
        """Get airports for an event using all available strategies."""
        # Priority 1: Exact event ID match
        if event.event_id in self.event_mappings:
            return self.event_mappings[event.event_id]

        # Priority 2: Name pattern match
        title_lower = event.title.lower()
        for pattern, airports, category in self.name_mappings:
            if pattern in title_lower:
                if category:
                    event.category = category
                return airports

        # Priority 3: Airports extracted from title
        if event.airports:
            return event.airports

        # Priority 4: Facility lookup from title
        for facility, airports in self.facility_airports.items():
            if facility.lower() in title_lower:
                return airports

        return []


# =============================================================================
# SQL GENERATOR
# =============================================================================

class SQLGenerator:
    """Generates SQL statements for database import."""

    def __init__(self):
        self.events: List[VATUSAEvent] = []

    def add_event(self, event: VATUSAEvent):
        """Add event to the generator."""
        self.events.append(event)

    def generate(self) -> str:
        """Generate complete SQL import script."""
        lines = [
            "-- VATUSA Event Statistics Import",
            f"-- Generated: {datetime.utcnow().isoformat()}Z",
            "-- Source: VATUSA API + Statsim.net",
            "",
            "SET NOCOUNT ON;",
            "GO",
            "",
        ]

        # Generate event inserts
        for event in self.events:
            lines.extend(self._generate_event_sql(event))
            lines.append("")

        lines.extend([
            "-- Summary",
            f"PRINT 'Imported {len(self.events)} events';",
            "GO",
        ])

        return '\n'.join(lines)

    def _generate_event_sql(self, event: VATUSAEvent) -> List[str]:
        """Generate SQL for a single event."""
        lines = []

        # Escape strings for SQL
        title_escaped = event.title.replace("'", "''") if event.title else ''
        event_idx = event.event_idx

        # Event insert/update (MERGE)
        lines.append(f"-- Event: {event.title[:50]}...")
        lines.append("MERGE dbo.vatusa_event AS target")
        lines.append(f"USING (SELECT '{event_idx}' AS event_idx) AS source")
        lines.append("ON target.event_idx = source.event_idx")
        lines.append("WHEN MATCHED THEN UPDATE SET")
        lines.append(f"    event_name = N'{title_escaped}',")
        lines.append(f"    event_type = '{event.category or 'EVT'}',")
        lines.append(f"    total_arrivals = {sum(s.arrivals for s in event.airport_stats)},")
        lines.append(f"    total_departures = {sum(s.departures for s in event.airport_stats)},")
        lines.append(f"    total_operations = {event.total_operations},")
        lines.append(f"    airport_count = {len(event.airport_stats)},")
        lines.append("    updated_utc = GETUTCDATE()")
        lines.append("WHEN NOT MATCHED THEN INSERT (")
        lines.append("    event_idx, event_name, event_type, event_code,")
        lines.append("    start_utc, end_utc, day_of_week,")
        lines.append("    total_arrivals, total_departures, total_operations, airport_count,")
        lines.append("    year_num, month_num, source")
        lines.append(") VALUES (")

        start_utc = f"'{event.start_utc.isoformat()}'" if event.start_utc else 'NULL'
        end_utc = f"'{event.end_utc.isoformat()}'" if event.end_utc else 'NULL'
        dow = event.start_date.strftime('%a') if event.start_date else 'NULL'

        lines.append(f"    '{event_idx}',")
        lines.append(f"    N'{title_escaped}',")
        lines.append(f"    '{event.category or 'EVT'}',")
        lines.append(f"    '{event.category or 'EVT'}{event.event_id % 100:02d}',")
        lines.append(f"    {start_utc},")
        lines.append(f"    {end_utc},")
        lines.append(f"    '{dow}',")
        lines.append(f"    {sum(s.arrivals for s in event.airport_stats)},")
        lines.append(f"    {sum(s.departures for s in event.airport_stats)},")
        lines.append(f"    {event.total_operations},")
        lines.append(f"    {len(event.airport_stats)},")
        lines.append(f"    {event.start_date.year},")
        lines.append(f"    {event.start_date.month},")
        lines.append("    'VATUSA'")
        lines.append(");")
        lines.append("GO")

        # Airport stats
        for stats in event.airport_stats:
            lines.append("")
            lines.append("MERGE dbo.vatusa_event_airport AS target")
            lines.append(f"USING (SELECT '{event_idx}' AS event_idx, '{stats.icao}' AS airport_icao) AS source")
            lines.append("ON target.event_idx = source.event_idx AND target.airport_icao = source.airport_icao")
            lines.append("WHEN MATCHED THEN UPDATE SET")
            lines.append(f"    total_arrivals = {stats.arrivals},")
            lines.append(f"    total_departures = {stats.departures},")
            lines.append(f"    total_operations = {stats.total}")
            lines.append("WHEN NOT MATCHED THEN INSERT (")
            lines.append("    event_idx, airport_icao, is_featured,")
            lines.append("    total_arrivals, total_departures, total_operations")
            lines.append(") VALUES (")
            lines.append(f"    '{event_idx}', '{stats.icao}', {1 if stats.is_featured else 0},")
            lines.append(f"    {stats.arrivals}, {stats.departures}, {stats.total}")
            lines.append(");")
            lines.append("GO")

        return lines


# =============================================================================
# MAIN PROCESSOR
# =============================================================================

class EventStatsProcessor:
    """Main orchestrator for fetching and processing event statistics."""

    def __init__(
        self,
        config_path: Optional[str] = None,
        facility_path: Optional[str] = None
    ):
        self.vatusa = VATUSAClient()
        self.statsim = StatsimScraper()
        self.mapper = FacilityMapper(config_path)

        if facility_path:
            self.mapper.load_facility_airports(facility_path)

        self.sql_gen = SQLGenerator()

    def process_recent_events(
        self,
        days_back: int = 7,
        start_date: Optional[date] = None,
        end_date: Optional[date] = None,
        list_only: bool = False
    ) -> List[VATUSAEvent]:
        """Process recent VATUSA events."""
        # Fetch events from API
        raw_events = self.vatusa.get_events(limit=100)

        # Parse and filter
        events = []
        today = date.today()
        cutoff_start = start_date or (today - timedelta(days=days_back))
        cutoff_end = end_date or today

        for raw in raw_events:
            event = self.vatusa.parse_event(raw)
            if not event:
                continue

            # Filter by date range
            if event.start_date < cutoff_start or event.start_date > cutoff_end:
                continue

            events.append(event)

        logger.info(f"Found {len(events)} events in date range")

        if list_only:
            return events

        # Process each event
        for event in events:
            self._process_event(event)

        return events

    def _process_event(self, event: VATUSAEvent):
        """Fetch statistics for a single event."""
        # Get airports for this event
        airports = self.mapper.get_airports(event)

        if not airports:
            logger.warning(f"No airports found for event: {event.title[:50]}")
            return

        logger.info(f"Processing {event.title[:40]}... ({len(airports)} airports)")

        # Fetch stats for each airport
        for icao in airports:
            stats = self.statsim.get_airport_stats(icao, event.start_date)
            if stats:
                event.airport_stats.append(stats)
                logger.debug(f"  {icao}: {stats.total} ops")
            else:
                # Add with zero stats if fetch failed
                event.airport_stats.append(AirportStats(icao=icao))

        # Add to SQL generator
        self.sql_gen.add_event(event)

    def generate_sql(self) -> str:
        """Generate SQL output."""
        return self.sql_gen.generate()


# =============================================================================
# CLI
# =============================================================================

def main():
    parser = argparse.ArgumentParser(
        description='Fetch VATUSA event statistics from Statsim.net'
    )

    parser.add_argument(
        '-o', '--output',
        default='vatusa_event_import.sql',
        help='Output SQL file (default: vatusa_event_import.sql)'
    )

    parser.add_argument(
        '-m', '--mapping',
        help='Path to event_facilities.json mapping file'
    )

    parser.add_argument(
        '-f', '--facilities',
        help='Path to facility_airports.json mapping file'
    )

    parser.add_argument(
        '-d', '--days',
        type=int,
        default=7,
        help='Days back to fetch events (default: 7)'
    )

    parser.add_argument(
        '--start',
        type=lambda s: datetime.strptime(s, '%Y-%m-%d').date(),
        help='Start date (YYYY-MM-DD)'
    )

    parser.add_argument(
        '--end',
        type=lambda s: datetime.strptime(s, '%Y-%m-%d').date(),
        help='End date (YYYY-MM-DD)'
    )

    parser.add_argument(
        '--list-only',
        action='store_true',
        help='List events without fetching stats'
    )

    parser.add_argument(
        '-v', '--verbose',
        action='store_true',
        help='Verbose output'
    )

    args = parser.parse_args()

    if args.verbose:
        logging.getLogger().setLevel(logging.DEBUG)

    # Initialize processor
    processor = EventStatsProcessor(
        config_path=args.mapping,
        facility_path=args.facilities
    )

    # Process events
    events = processor.process_recent_events(
        days_back=args.days,
        start_date=args.start,
        end_date=args.end,
        list_only=args.list_only
    )

    if args.list_only:
        print(f"\nFound {len(events)} events:\n")
        for e in events:
            airports = ', '.join(e.airports[:5]) if e.airports else '(none detected)'
            print(f"  [{e.event_id}] {e.start_date} - {e.title[:60]}")
            print(f"           Airports: {airports}")
            print()
        return

    # Generate SQL
    sql = processor.generate_sql()

    output_path = Path(args.output)
    output_path.write_text(sql, encoding='utf-8')

    logger.info(f"Generated SQL: {output_path}")
    logger.info(f"Total events: {len(events)}")
    logger.info(f"Total operations: {sum(e.total_operations for e in events)}")

    print(f"\nOutput: {output_path}")
    print(f"Events: {len(events)}")
    print("\nTo import into database:")
    print(f"  sqlcmd -S vatsim.database.windows.net -d VATSIM_ADL -U <user> -P <pass> -i {output_path}")


if __name__ == '__main__':
    main()
