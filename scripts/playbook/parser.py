"""
Main FAA Playbook parser orchestrator.

Coordinates:
- Fetching play list from FAA menu
- Fetching individual play pages
- Parsing HTML tables
- Combining two-table format routes
- Formatting procedures
- Generating output
"""

import csv
import time
import urllib.request
import urllib.error
import logging
from dataclasses import dataclass
from datetime import datetime
from pathlib import Path
from typing import Dict, List, Optional, NamedTuple
from collections import defaultdict

from .config import (
    FAA_PLAYBOOK_BASE, MENU_URL, PLAY_URL_TEMPLATE,
    REQUEST_DELAY, MAX_RETRIES, RETRY_DELAY, REQUEST_TIMEOUT,
    USER_AGENT, OUTPUT_COLUMNS, APTS_CSV, DP_CSV, STAR_CSV,
    ARTCC_PREFIX, ARTCC_LENGTH, AIRAC_EPOCH_DATE, AIRAC_CYCLE_DAYS
)
from .html_extractor import HTMLTableExtractor, ParsedTable, extract_routes_from_table
from .route_combiner import TwoTableCombiner, deduplicate_routes
from .procedure_detector import ProcedureDetector


# Configure logging
logger = logging.getLogger(__name__)


class RouteEntry(NamedTuple):
    """Output format for a single route entry."""
    play: str
    route_string: str
    origins: str
    origin_tracons: str
    origin_artccs: str
    destinations: str
    dest_tracons: str
    dest_artccs: str


@dataclass
class AirportInfo:
    """Airport metadata."""
    icao: str = ''
    faa_id: str = ''
    tracon: str = ''
    artcc: str = ''
    is_artcc: bool = False
    is_tracon: bool = False


class AirportLookup:
    """Airport and facility code resolver."""

    def __init__(self, apts_csv: Path):
        self.airports: Dict[str, AirportInfo] = {}
        self.faa_to_icao: Dict[str, str] = {}
        self.artcc_airports: Dict[str, List[str]] = defaultdict(list)
        self.tracon_airports: Dict[str, List[str]] = defaultdict(list)

        if apts_csv.exists():
            self._load_airports(apts_csv)

    def _load_airports(self, csv_path: Path):
        """Load airport data from CSV."""
        try:
            with open(csv_path, 'r', encoding='utf-8-sig') as f:
                reader = csv.DictReader(f)
                for row in reader:
                    faa_id = row.get('ARPT_ID', '').strip()
                    icao_id = row.get('ICAO_ID', '').strip()
                    artcc = row.get('RESP_ARTCC_ID', '').strip()

                    # Get TRACON from approach/departure facilities
                    tracon = (
                        row.get('Approach ID', '') or
                        row.get('Departure ID', '') or
                        row.get('Approach/Departure ID', '') or ''
                    ).strip()

                    if not faa_id:
                        continue

                    # Build ICAO if not present
                    if not icao_id and len(faa_id) == 3:
                        icao_id = 'K' + faa_id

                    info = AirportInfo(
                        icao=icao_id,
                        faa_id=faa_id,
                        tracon=tracon,
                        artcc=artcc
                    )

                    if icao_id:
                        self.airports[icao_id] = info
                        self.faa_to_icao[faa_id] = icao_id

                        if artcc:
                            self.artcc_airports[artcc].append(icao_id)
                        if tracon:
                            self.tracon_airports[tracon].append(icao_id)

        except Exception as e:
            logger.warning(f"Error loading airports: {e}")

    def resolve(self, code: str) -> AirportInfo:
        """
        Resolve any airport/facility code to AirportInfo.

        Handles: FAA 3-letter, ICAO 4-letter, ARTCC (Z-prefix), TRACON
        """
        code = code.strip().upper()

        if not code:
            return AirportInfo()

        # Check if it's an ARTCC
        if self._is_artcc(code):
            return AirportInfo(artcc=code, is_artcc=True)

        # Direct ICAO lookup
        if code in self.airports:
            return self.airports[code]

        # FAA to ICAO conversion
        if code in self.faa_to_icao:
            icao = self.faa_to_icao[code]
            return self.airports.get(icao, AirportInfo(icao=icao))

        # Try adding K prefix for 3-letter codes
        if len(code) == 3 and code.isalpha():
            icao = 'K' + code
            if icao in self.airports:
                return self.airports[icao]

        # Check if it might be a TRACON
        if self._is_tracon(code):
            return AirportInfo(tracon=code, is_tracon=True)

        # Return with original code preserved
        return AirportInfo(icao=code if len(code) == 4 else '', faa_id=code)

    def _is_artcc(self, code: str) -> bool:
        """Check if code is an ARTCC/FIR (US Z-prefix or Canadian CZ-prefix)."""
        if len(code) != ARTCC_LENGTH or not code.isalpha():
            return False
        # US ARTCCs: Z-prefix (ZAU, ZBW, ZDC, etc.)
        if code.startswith(ARTCC_PREFIX):
            return True
        # Canadian FIRs: CZ-prefix (CZU, CZY, CZE, etc.)
        if code.startswith('CZ'):
            return True
        return False

    def _is_tracon(self, code: str) -> bool:
        """Check if code could be a TRACON."""
        if len(code) != 3:
            return False
        # Exclude ARTCCs (Z-prefix), airports (K-prefix), and Canadian FIRs (CZ-prefix)
        if code.startswith('Z') or code.startswith('K') or code.startswith('CZ'):
            return False
        return True


class PlaybookParser:
    """
    Main orchestrator for FAA Playbook parsing.

    Usage:
        parser = PlaybookParser()
        routes = parser.fetch_and_parse_all()
        parser.write_csv(routes, output_path)
    """

    def __init__(
        self,
        apts_csv: Path = APTS_CSV,
        dp_csv: Path = DP_CSV,
        star_csv: Path = STAR_CSV
    ):
        self.airport_lookup = AirportLookup(apts_csv)
        self.procedure_detector = ProcedureDetector(dp_csv, star_csv)
        self.combiner = TwoTableCombiner()

        # Statistics
        self.stats = {
            'plays_fetched': 0,
            'plays_parsed': 0,
            'plays_failed': 0,
            'routes_generated': 0,
            'two_table_plays': 0,
        }

    def fetch_url(self, url: str, retries: int = MAX_RETRIES) -> Optional[str]:
        """Fetch URL content with retries and error handling."""
        for attempt in range(retries):
            try:
                req = urllib.request.Request(
                    url,
                    headers={'User-Agent': USER_AGENT}
                )
                with urllib.request.urlopen(req, timeout=REQUEST_TIMEOUT) as response:
                    content = response.read().decode('utf-8', errors='replace')

                    # Check for FAA apology page
                    if 'apology_files' in content and 'currently down' in content:
                        if attempt < retries - 1:
                            logger.warning(f"Server returned apology page, retrying...")
                            time.sleep(RETRY_DELAY)
                            continue
                        else:
                            logger.error(f"Server still returning apology page")
                            return None

                    return content

            except (urllib.error.URLError, urllib.error.HTTPError, TimeoutError) as e:
                if attempt < retries - 1:
                    logger.warning(f"Request failed ({e}), retrying...")
                    time.sleep(RETRY_DELAY)
                else:
                    logger.error(f"Failed to fetch {url}: {e}")
                    return None

        return None

    def get_play_list(self) -> Dict[str, str]:
        """
        Fetch and parse the playbook menu to get all plays.

        Returns:
            Dict mapping play name -> playkey
        """
        logger.info("Fetching playbook menu...")
        content = self.fetch_url(MENU_URL)

        if not content:
            raise RuntimeError("Failed to fetch playbook menu")

        plays = {}

        # Parse menu links
        import re
        for match in re.finditer(
            r'href="[^"]*playbook\?playkey=(\d+)"[^>]*>([^<]+)</a>',
            content,
            re.IGNORECASE
        ):
            playkey = match.group(1)
            play_name = match.group(2).strip()
            if play_name and playkey:
                plays[play_name] = playkey

        logger.info(f"Found {len(plays)} plays")
        return plays

    def parse_play_page(self, content: str, play_name: str) -> List[dict]:
        """
        Parse a single play page and extract routes.

        Args:
            content: HTML content of the play page
            play_name: Name of the play (for fallback)

        Returns:
            List of route dicts
        """
        extractor = HTMLTableExtractor()
        extractor.feed(content)

        parsed = extractor.get_parsed_play()

        # Use extracted name if available, otherwise use provided name
        actual_name = parsed.play_name or play_name

        routes = []

        if parsed.has_two_table_format:
            # Two-table format - combine origin and destination routes
            self.stats['two_table_plays'] += 1

            origin_table = parsed.get_origin_table()
            dest_table = parsed.get_dest_table()

            if origin_table and dest_table:
                origin_routes = extract_routes_from_table(origin_table)
                dest_routes = extract_routes_from_table(dest_table)
                routes = self.combiner.combine_all_combinations(origin_routes, dest_routes)
        else:
            # Single-table format - extract and expand multi-value cells
            for table in parsed.tables:
                table_routes = extract_routes_from_table(table)
                # Expand multi-value origin/dest cells
                expanded = self._expand_multi_value_routes(table_routes)
                routes.extend(expanded)

        # Format procedures
        for route in routes:
            route['route'] = self.procedure_detector.format_route(route.get('route', ''))
            route['play_name'] = actual_name

        return routes

    def _expand_multi_value_routes(self, routes: List[dict]) -> List[dict]:
        """
        Expand routes with multi-value origin/dest cells into individual routes.

        E.g., a route with origin "KBRO KCRP KLRD" becomes 3 separate routes.
        """
        expanded = []

        for route in routes:
            origin_str = route.get('origin', '').strip()
            dest_str = route.get('dest', '').strip()
            route_str = route.get('route', '').strip()

            if not route_str:
                continue

            # Parse multi-value fields (space or comma separated)
            origins = self._parse_multi_value(origin_str) or ['']
            dests = self._parse_multi_value(dest_str) or ['']

            # Generate a route for each origin/dest combination
            for origin in origins:
                for dest in dests:
                    expanded.append({
                        'origin': origin,
                        'dest': dest,
                        'route': route_str,
                        'filters': route.get('filters', ''),
                        'remarks': route.get('remarks', ''),
                    })

        return expanded

    def _parse_multi_value(self, value: str) -> List[str]:
        """Parse a multi-value field (space or comma separated)."""
        if not value:
            return []
        # Replace commas with spaces and split
        normalized = value.replace(',', ' ')
        return [c.strip() for c in normalized.split() if c.strip()]

    def build_route_entry(
        self,
        play_name: str,
        route_dict: dict
    ) -> Optional[RouteEntry]:
        """
        Build a RouteEntry from parsed route data.

        Args:
            play_name: Name of the play
            route_dict: Dict with origin, dest, route, filters, remarks

        Returns:
            RouteEntry or None if invalid
        """
        origin = route_dict.get('origin', '').strip()
        dest = route_dict.get('dest', '').strip()
        route_str = route_dict.get('route', '').strip()

        if not route_str:
            return None

        # Resolve origin
        origin_icao = ''
        origin_tracon = ''
        origin_artcc = ''

        if origin:
            info = self.airport_lookup.resolve(origin)
            if info.is_artcc:
                origin_artcc = info.artcc
            elif info.is_tracon:
                origin_tracon = info.tracon
            else:
                origin_icao = info.icao or origin
                origin_tracon = info.tracon
                origin_artcc = info.artcc

        # Resolve destination
        dest_icao = ''
        dest_tracon = ''
        dest_artcc = ''

        if dest:
            info = self.airport_lookup.resolve(dest)
            if info.is_artcc:
                dest_artcc = info.artcc
            elif info.is_tracon:
                dest_tracon = info.tracon
            else:
                dest_icao = info.icao or dest
                dest_tracon = info.tracon
                dest_artcc = info.artcc

        # Build full route string with origin/destination
        full_route = self._build_full_route(origin, route_str, dest)

        return RouteEntry(
            play=play_name,
            route_string=full_route,
            origins=origin_icao,
            origin_tracons=origin_tracon,
            origin_artccs=origin_artcc,
            destinations=dest_icao,
            dest_tracons=dest_tracon,
            dest_artccs=dest_artcc,
        )

    def _build_full_route(self, origin: str, route: str, dest: str) -> str:
        """Build complete route string with origin and destination."""
        parts = []

        # Add origin (convert to ICAO if possible)
        if origin:
            info = self.airport_lookup.resolve(origin)
            if info.icao:
                parts.append(info.icao)
            elif not info.is_artcc and not info.is_tracon:
                parts.append(origin)
            else:
                parts.append(origin)

        # Add route
        if route:
            parts.append(route)

        # Add destination (convert to ICAO if possible)
        if dest:
            info = self.airport_lookup.resolve(dest)
            if info.icao:
                parts.append(info.icao)
            elif not info.is_artcc and not info.is_tracon:
                parts.append(dest)
            else:
                parts.append(dest)

        return ' '.join(parts)

    def fetch_and_parse_play(self, play_name: str, playkey: str) -> List[RouteEntry]:
        """
        Fetch and parse a single play.

        Args:
            play_name: Name of the play
            playkey: FAA playkey identifier

        Returns:
            List of RouteEntry objects
        """
        url = PLAY_URL_TEMPLATE.format(playkey=playkey)
        content = self.fetch_url(url)

        if not content:
            self.stats['plays_failed'] += 1
            return []

        self.stats['plays_fetched'] += 1

        try:
            routes = self.parse_play_page(content, play_name)
            entries = []

            for route_dict in routes:
                entry = self.build_route_entry(play_name, route_dict)
                if entry:
                    entries.append(entry)

            self.stats['plays_parsed'] += 1
            self.stats['routes_generated'] += len(entries)

            return entries

        except Exception as e:
            logger.error(f"Error parsing play {play_name}: {e}")
            self.stats['plays_failed'] += 1
            return []

    def fetch_and_parse_all(
        self,
        plays: Optional[Dict[str, str]] = None,
        limit: Optional[int] = None,
        delay: float = REQUEST_DELAY
    ) -> List[RouteEntry]:
        """
        Fetch and parse all plays from FAA.

        Args:
            plays: Optional dict of play_name -> playkey (fetches if not provided)
            limit: Optional limit on number of plays to process
            delay: Delay between requests in seconds

        Returns:
            List of all RouteEntry objects
        """
        if plays is None:
            plays = self.get_play_list()

        if limit:
            plays = dict(list(plays.items())[:limit])

        all_entries = []

        for i, (play_name, playkey) in enumerate(plays.items()):
            logger.info(f"[{i+1}/{len(plays)}] Processing: {play_name}")

            entries = self.fetch_and_parse_play(play_name, playkey)
            all_entries.extend(entries)

            logger.debug(f"  Generated {len(entries)} routes")

            # Throttle requests
            if i < len(plays) - 1:
                time.sleep(delay)

        # Deduplicate
        unique_count_before = len(all_entries)
        all_entries = self._deduplicate_entries(all_entries)

        logger.info(f"Deduplicated: {unique_count_before} -> {len(all_entries)} routes")

        return all_entries

    def _deduplicate_entries(self, entries: List[RouteEntry]) -> List[RouteEntry]:
        """Remove duplicate route entries."""
        seen = set()
        unique = []

        for entry in entries:
            key = (
                entry.play.upper(),
                entry.route_string.upper(),
                entry.origins.upper(),
                entry.destinations.upper()
            )
            if key not in seen:
                seen.add(key)
                unique.append(entry)

        return unique

    def write_csv(self, entries: List[RouteEntry], output_path: Path):
        """Write route entries to CSV file."""
        with open(output_path, 'w', encoding='utf-8', newline='') as f:
            writer = csv.writer(f, lineterminator='\r\n')
            writer.writerow(OUTPUT_COLUMNS)

            for entry in entries:
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

        logger.info(f"Wrote {len(entries)} routes to {output_path}")

    def get_stats(self) -> dict:
        """Get parsing statistics."""
        return self.stats.copy()


def calculate_airac_cycle() -> str:
    """
    Calculate current AIRAC cycle ID (YYNN format).

    Uses same logic as nasr_navdata_updater.py
    """
    from datetime import datetime, timedelta

    epoch = datetime.strptime(AIRAC_EPOCH_DATE, '%Y-%m-%d')
    today = datetime.now()

    days_since_epoch = (today - epoch).days
    cycles_since_epoch = days_since_epoch // AIRAC_CYCLE_DAYS

    year = 24 + (cycles_since_epoch // 13)
    cycle_in_year = (cycles_since_epoch % 13) + 1

    return f"{year:02d}{cycle_in_year:02d}"
