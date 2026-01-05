#!/usr/bin/env python3
"""
FAA Playbook Routes Scraper and Updater

Scrapes route data from https://www.fly.faa.gov/playbook/ and updates
data/playbook_routes.csv with new routes while preserving old versions
for backwards compatibility.
"""

import csv
import re
import time
import urllib.request
import urllib.error
from datetime import datetime, timedelta
from html.parser import HTMLParser
from typing import Dict, List, Set, Tuple, Optional, NamedTuple
from collections import defaultdict
import os

# Configuration
FAA_PLAYBOOK_BASE = "https://www.fly.faa.gov/playbook"
MENU_URL = f"{FAA_PLAYBOOK_BASE}/playbookMenu?active=1"
PLAY_URL_TEMPLATE = f"{FAA_PLAYBOOK_BASE}/playbook?playkey={{playkey}}"

# Throttling settings (FAA server can be slow)
REQUEST_DELAY = 0.5  # seconds between requests
MAX_RETRIES = 3
RETRY_DELAY = 2  # seconds between retries

# Output format
OUTPUT_COLUMNS = [
    "Play", "Route String", "Origins", "Origin_TRACONs", "Origin_ARTCCs",
    "Destinations", "Dest_TRACONs", "Dest_ARTCCs"
]


class RouteEntry(NamedTuple):
    """Represents a single route entry in the output CSV."""
    play: str
    route_string: str
    origins: str
    origin_tracons: str
    origin_artccs: str
    destinations: str
    dest_tracons: str
    dest_artccs: str


class AirportData:
    """Manages airport data for TRACON/ARTCC lookups."""
    
    def __init__(self, apts_csv_path: str):
        self.airports: Dict[str, dict] = {}  # ICAO -> airport data
        self.faa_to_icao: Dict[str, str] = {}  # FAA ID -> ICAO
        self.artcc_airports: Dict[str, List[str]] = defaultdict(list)  # ARTCC -> [ICAO airports]
        self.tracon_airports: Dict[str, List[str]] = defaultdict(list)  # TRACON -> [ICAO airports]
        self._load_airports(apts_csv_path)
    
    def _load_airports(self, path: str):
        """Load airport data from CSV."""
        with open(path, 'r', encoding='utf-8-sig') as f:
            reader = csv.DictReader(f)
            for row in reader:
                faa_id = row.get('ARPT_ID', '').strip()
                icao_id = row.get('ICAO_ID', '').strip()
                artcc = row.get('RESP_ARTCC_ID', '').strip()
                
                # Get approach/departure facility (TRACON)
                tracon = (row.get('Approach ID', '') or 
                         row.get('Departure ID', '') or 
                         row.get('Approach/Departure ID', '') or '').strip()
                
                if not faa_id:
                    continue
                
                # Build ICAO if not present
                if not icao_id and len(faa_id) == 3:
                    icao_id = 'K' + faa_id
                
                airport_data = {
                    'faa_id': faa_id,
                    'icao_id': icao_id,
                    'artcc': artcc,
                    'tracon': tracon,
                }
                
                if icao_id:
                    self.airports[icao_id] = airport_data
                    self.faa_to_icao[faa_id] = icao_id
                    
                    if artcc:
                        self.artcc_airports[artcc].append(icao_id)
                    if tracon:
                        self.tracon_airports[tracon].append(icao_id)
    
    def get_icao(self, code: str) -> Optional[str]:
        """Convert a code to ICAO format if possible."""
        code = code.strip().upper()
        if code.startswith('K') and len(code) == 4:
            return code
        if code.startswith('C') and len(code) == 4:  # Canadian
            return code
        if code in self.airports:
            return code
        if code in self.faa_to_icao:
            return self.faa_to_icao[code]
        # Try adding K prefix
        if len(code) == 3:
            icao = 'K' + code
            if icao in self.airports:
                return icao
        return None
    
    def get_airport_info(self, icao: str) -> Optional[dict]:
        """Get airport info by ICAO code."""
        return self.airports.get(icao)
    
    def is_artcc(self, code: str) -> bool:
        """Check if code is an ARTCC identifier."""
        code = code.strip().upper()
        return code.startswith('Z') and len(code) == 3
    
    def is_tracon(self, code: str) -> bool:
        """Check if code could be a TRACON (3-letter, not starting with Z or K)."""
        code = code.strip().upper()
        return len(code) == 3 and not code.startswith('Z') and not code.startswith('K')
    
    def get_airports_for_artcc(self, artcc: str) -> List[str]:
        """Get all ICAO airports in an ARTCC."""
        return self.artcc_airports.get(artcc.upper(), [])
    
    def get_airports_for_tracon(self, tracon: str) -> List[str]:
        """Get all ICAO airports served by a TRACON."""
        return self.tracon_airports.get(tracon.upper(), [])


def calculate_airac_cycle(date: datetime = None) -> str:
    """
    Calculate the AIRAC cycle number for a given date.
    Returns format YYNN (e.g., 2511 for year 2025, cycle 11).
    """
    if date is None:
        date = datetime.now()
    
    # AIRAC reference: January 2, 2020 was cycle 2001
    reference_date = datetime(2020, 1, 2)
    cycle_days = 28
    
    days_diff = (date - reference_date).days
    cycles_since_ref = days_diff // cycle_days
    
    # Calculate year and cycle within year
    year = 2020
    cycle = 1 + cycles_since_ref
    
    while cycle > 13:
        cycle -= 13
        year += 1
    
    return f"{year % 100:02d}{cycle:02d}"


def fetch_url(url: str, retries: int = MAX_RETRIES) -> Optional[str]:
    """Fetch URL content with retries and error handling."""
    for attempt in range(retries):
        try:
            req = urllib.request.Request(
                url,
                headers={'User-Agent': 'Mozilla/5.0 (compatible; PlaybookScraper/1.0)'}
            )
            with urllib.request.urlopen(req, timeout=30) as response:
                content = response.read().decode('utf-8', errors='replace')
                
                # Check for FAA apology page
                if 'apology_files' in content and 'currently down' in content:
                    if attempt < retries - 1:
                        print(f"  Server returned apology page, retrying in {RETRY_DELAY}s...")
                        time.sleep(RETRY_DELAY)
                        continue
                    else:
                        print(f"  WARNING: Server still returning apology page after {retries} attempts")
                        return None
                
                return content
                
        except (urllib.error.URLError, urllib.error.HTTPError, TimeoutError) as e:
            if attempt < retries - 1:
                print(f"  Request failed ({e}), retrying in {RETRY_DELAY}s...")
                time.sleep(RETRY_DELAY)
            else:
                print(f"  ERROR: Failed to fetch {url} after {retries} attempts: {e}")
                return None
    
    return None


class PlaybookMenuParser(HTMLParser):
    """Parse the playbook menu to extract play names and keys."""
    
    def __init__(self):
        super().__init__()
        self.plays: Dict[str, str] = {}  # play_name -> playkey
        self._in_link = False
        self._current_href = None
        self._current_text = []
    
    def handle_starttag(self, tag, attrs):
        if tag == 'a':
            attrs_dict = dict(attrs)
            href = attrs_dict.get('href', '')
            if 'playbook?playkey=' in href:
                self._in_link = True
                self._current_href = href
                self._current_text = []
    
    def handle_endtag(self, tag):
        if tag == 'a' and self._in_link:
            self._in_link = False
            play_name = ''.join(self._current_text).strip()
            if play_name and self._current_href:
                # Extract playkey from href
                match = re.search(r'playkey=(\d+)', self._current_href)
                if match:
                    self.plays[play_name] = match.group(1)
            self._current_href = None
            self._current_text = []
    
    def handle_data(self, data):
        if self._in_link:
            self._current_text.append(data)


def get_play_list() -> Dict[str, str]:
    """Fetch and parse the playbook menu to get all plays."""
    print("Fetching playbook menu...")
    content = fetch_url(MENU_URL)
    if not content:
        raise RuntimeError("Failed to fetch playbook menu")
    
    parser = PlaybookMenuParser()
    parser.feed(content)
    
    print(f"Found {len(parser.plays)} plays")
    return parser.plays


class PlayPageParser(HTMLParser):
    """Parse a play page to extract route tables."""
    
    def __init__(self):
        super().__init__()
        self.play_name = ""
        self.tables: List[List[List[str]]] = []  # List of tables, each table is list of rows
        
        self._in_title_font = False
        self._title_collected = False
        self._in_table = False
        self._in_row = False
        self._in_cell = False
        self._current_table: List[List[str]] = []
        self._current_row: List[str] = []
        self._current_cell: List[str] = []
        self._table_depth = 0
    
    def handle_starttag(self, tag, attrs):
        attrs_dict = dict(attrs)
        
        # Capture play name from title font
        if tag == 'font' and not self._title_collected:
            color = attrs_dict.get('color', '').lower()
            size = attrs_dict.get('size', '')
            if color == '#005a86' and size == '6':
                self._in_title_font = True
        
        if tag == 'table':
            self._table_depth += 1
            if self._table_depth == 1:
                self._in_table = True
                self._current_table = []
        
        if tag == 'tr' and self._in_table and self._table_depth == 1:
            self._in_row = True
            self._current_row = []
        
        if tag == 'td' and self._in_row:
            self._in_cell = True
            self._current_cell = []
    
    def handle_endtag(self, tag):
        if tag == 'font' and self._in_title_font:
            self._in_title_font = False
            self._title_collected = True
        
        if tag == 'td' and self._in_cell:
            self._in_cell = False
            cell_text = ''.join(self._current_cell).strip()
            # Clean up &nbsp; and extra whitespace
            cell_text = re.sub(r'\s+', ' ', cell_text).strip()
            self._current_row.append(cell_text)
        
        if tag == 'tr' and self._in_row:
            self._in_row = False
            if self._current_row:
                self._current_table.append(self._current_row)
        
        if tag == 'table':
            if self._table_depth == 1 and self._current_table:
                self.tables.append(self._current_table)
                self._current_table = []
            self._table_depth -= 1
            if self._table_depth == 0:
                self._in_table = False
    
    def handle_data(self, data):
        if self._in_title_font:
            self.play_name += data
        if self._in_cell:
            self._current_cell.append(data)
    
    def handle_entityref(self, name):
        if self._in_cell:
            if name == 'nbsp':
                self._current_cell.append(' ')


def parse_play_page(content: str) -> Tuple[str, List[dict], List[dict]]:
    """
    Parse a play page and extract route information.
    
    Returns:
        (play_name, origin_routes, dest_routes)
        - origin_routes: List of dicts with keys: origin, filters, route, dest (if present), remarks
        - dest_routes: List of dicts with keys: dest, route, remarks (for two-table format)
    """
    parser = PlayPageParser()
    parser.feed(content)
    
    play_name = parser.play_name.strip()
    origin_routes = []
    dest_routes = []
    
    for table in parser.tables:
        if not table:
            continue
        
        # Check header row to determine table type
        header = [cell.upper() for cell in table[0]]
        
        if 'ORIGIN' in header:
            # This is an origin routes table
            # Determine column indices
            origin_idx = header.index('ORIGIN') if 'ORIGIN' in header else -1
            filters_idx = header.index('FILTERS') if 'FILTERS' in header else -1
            route_idx = header.index('ROUTE') if 'ROUTE' in header else -1
            dest_idx = header.index('DEST') if 'DEST' in header else -1
            remarks_idx = header.index('REMARKS') if 'REMARKS' in header else -1
            
            for row in table[1:]:  # Skip header
                if len(row) <= max(origin_idx, route_idx):
                    continue
                
                route_entry = {
                    'origin': row[origin_idx] if origin_idx >= 0 and origin_idx < len(row) else '',
                    'filters': row[filters_idx] if filters_idx >= 0 and filters_idx < len(row) else '',
                    'route': row[route_idx] if route_idx >= 0 and route_idx < len(row) else '',
                    'dest': row[dest_idx] if dest_idx >= 0 and dest_idx < len(row) else '',
                    'remarks': row[remarks_idx] if remarks_idx >= 0 and remarks_idx < len(row) else '',
                }
                origin_routes.append(route_entry)
        
        elif 'DESTINATION' in header:
            # This is a destination routes table (for two-table format plays)
            dest_idx = header.index('DESTINATION') if 'DESTINATION' in header else -1
            route_idx = header.index('ROUTE') if 'ROUTE' in header else -1
            remarks_idx = header.index('REMARKS') if 'REMARKS' in header else -1
            
            for row in table[1:]:  # Skip header
                if len(row) <= max(dest_idx, route_idx):
                    continue
                
                dest_entry = {
                    'dest': row[dest_idx] if dest_idx >= 0 and dest_idx < len(row) else '',
                    'route': row[route_idx] if route_idx >= 0 and route_idx < len(row) else '',
                    'remarks': row[remarks_idx] if remarks_idx >= 0 and remarks_idx < len(row) else '',
                }
                dest_routes.append(dest_entry)
    
    return play_name, origin_routes, dest_routes


def parse_origin_codes(origin_str: str) -> List[str]:
    """
    Parse origin string which may contain multiple codes.
    E.g., "ZID ZFW ZHU ZAB" -> ["ZID", "ZFW", "ZHU", "ZAB"]
    E.g., "KIAH KHOU" -> ["KIAH", "KHOU"]
    """
    if not origin_str:
        return []
    
    # Split by whitespace
    codes = origin_str.strip().split()
    return [c.strip() for c in codes if c.strip()]


def parse_filters(filter_str: str) -> List[str]:
    """
    Parse filter string to extract exclusions.
    E.g., "-PHL -JFK" -> ["PHL", "JFK"]
    """
    if not filter_str:
        return []
    
    exclusions = []
    for part in filter_str.split():
        part = part.strip()
        if part.startswith('-'):
            exclusions.append(part[1:])
    
    return exclusions


def generate_route_entries(
    play_name: str,
    origin_routes: List[dict],
    dest_routes: List[dict],
    airport_data: AirportData
) -> List[RouteEntry]:
    """
    Generate all route entries for a play.
    
    Handles both single-table format (with DEST column) and two-table format.
    """
    entries = []
    
    # Determine format
    has_dest_column = any(r.get('dest') for r in origin_routes)
    has_dest_table = bool(dest_routes)
    
    if has_dest_column:
        # Single table format - each row is a complete route
        for route in origin_routes:
            origin_codes = parse_origin_codes(route['origin'])
            dest_codes = parse_origin_codes(route['dest'])
            route_str = route['route'].strip()
            
            if not route_str:
                continue
            
            for origin in origin_codes:
                for dest in (dest_codes if dest_codes else ['']):
                    entry = build_route_entry(
                        play_name, origin, route_str, dest, airport_data
                    )
                    if entry:
                        entries.append(entry)
    
    elif has_dest_table:
        # Two-table format - combine origin routes with destination routes
        for origin_route in origin_routes:
            origin_codes = parse_origin_codes(origin_route['origin'])
            origin_route_str = origin_route['route'].strip()
            
            if not origin_route_str:
                continue
            
            for dest_route in dest_routes:
                dest_codes = parse_origin_codes(dest_route['dest'])
                dest_route_str = dest_route['route'].strip()
                
                # Combine routes - the destination route typically starts with a waypoint
                # that overlaps with the end of the origin route
                combined_route = combine_routes(origin_route_str, dest_route_str)
                
                for origin in origin_codes:
                    for dest in dest_codes:
                        entry = build_route_entry(
                            play_name, origin, combined_route, dest, airport_data
                        )
                        if entry:
                            entries.append(entry)
    
    else:
        # Origin routes only (no destination specified in table)
        for route in origin_routes:
            origin_codes = parse_origin_codes(route['origin'])
            route_str = route['route'].strip()
            
            if not route_str:
                continue
            
            for origin in origin_codes:
                entry = build_route_entry(
                    play_name, origin, route_str, '', airport_data
                )
                if entry:
                    entries.append(entry)
    
    return entries


def is_procedure_name(name: str) -> bool:
    """
    Check if a name looks like a STAR/SID procedure name.
    These typically end in a single digit (1-9).
    E.g., JJEDI4, ONDRE1, GLAVN2, OOSHN5, BRWNZ4
    """
    if not name:
        return False
    name = name.strip().upper()
    # Must be at least 4 chars, end with a digit 1-9
    if len(name) < 4:
        return False
    if name[-1] not in '123456789':
        return False
    # Rest should be letters (procedure base name)
    if not name[:-1].isalpha():
        return False
    return True


def format_route_with_dots(route_str: str) -> str:
    """
    Format a route string with proper dot notation for STAR/SID procedures.
    
    E.g., "SKWKR JJEDI4" -> "SKWKR.JJEDI4"
    E.g., "GLAVN GLAVN2" -> "GLAVN.GLAVN2"
    """
    if not route_str:
        return route_str
    
    parts = route_str.split()
    if len(parts) < 2:
        return route_str
    
    result = []
    i = 0
    while i < len(parts):
        current = parts[i]
        
        # Check if next part is a procedure and current is a fix
        if i + 1 < len(parts):
            next_part = parts[i + 1]
            if is_procedure_name(next_part) and not is_procedure_name(current):
                # Connect with dot: FIX.PROCEDURE
                result.append(f"{current}.{next_part}")
                i += 2
                continue
        
        result.append(current)
        i += 1
    
    return ' '.join(result)


def combine_routes(origin_route: str, dest_route: str) -> str:
    """
    Combine origin and destination route strings.
    
    The destination route typically starts with a fix that should connect
    to (or overlap with) the origin route.
    """
    if not dest_route:
        return origin_route
    if not origin_route:
        return dest_route
    
    # Get the first fix of dest route
    dest_parts = dest_route.split()
    if not dest_parts:
        return origin_route
    
    first_dest_fix = dest_parts[0]
    
    # Check if origin route ends with this fix
    origin_parts = origin_route.split()
    if origin_parts and origin_parts[-1].upper() == first_dest_fix.upper():
        # Remove duplicate fix
        return origin_route + ' ' + ' '.join(dest_parts[1:])
    else:
        # Just concatenate
        return origin_route + ' ' + dest_route


def build_route_entry(
    play_name: str,
    origin: str,
    route_str: str,
    dest: str,
    airport_data: AirportData
) -> Optional[RouteEntry]:
    """
    Build a single RouteEntry with proper TRACON/ARTCC resolution.
    """
    origin = origin.strip().upper()
    dest = dest.strip().upper()
    
    # Determine origin type and get info
    origin_icao = ''
    origin_tracon = ''
    origin_artcc = ''
    
    if airport_data.is_artcc(origin):
        # Origin is an ARTCC
        origin_artcc = origin
        origin_icao = ''
        origin_tracon = ''
    else:
        # Try to get as airport
        icao = airport_data.get_icao(origin)
        if icao:
            origin_icao = icao
            info = airport_data.get_airport_info(icao)
            if info:
                origin_tracon = info.get('tracon', '')
                origin_artcc = info.get('artcc', '')
        elif airport_data.is_tracon(origin):
            # Might be a TRACON used as origin
            origin_tracon = origin
    
    # Determine destination type and get info
    dest_icao = ''
    dest_tracon = ''
    dest_artcc = ''
    
    if dest:
        if airport_data.is_artcc(dest):
            dest_artcc = dest
        else:
            icao = airport_data.get_icao(dest)
            if icao:
                dest_icao = icao
                info = airport_data.get_airport_info(icao)
                if info:
                    dest_tracon = info.get('tracon', '')
                    dest_artcc = info.get('artcc', '')
            elif airport_data.is_tracon(dest):
                dest_tracon = dest
    
    # Build the full route string
    full_route = build_full_route_string(origin, route_str, dest, airport_data)
    
    if not full_route:
        return None
    
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


def build_full_route_string(
    origin: str,
    route_str: str,
    dest: str,
    airport_data: AirportData
) -> str:
    """
    Build the full route string in the format used by playbook_routes.csv.
    
    Format: "{Origin} {Route} {Destination}" or "{Origin} {Route}.{STAR} {Destination}"
    """
    parts = []
    
    # Add origin
    if origin:
        # Convert to ICAO if it's an airport
        icao = airport_data.get_icao(origin)
        if icao:
            parts.append(icao)
        else:
            parts.append(origin)
    
    # Add route with proper dot formatting
    if route_str:
        formatted_route = format_route_with_dots(route_str)
        parts.append(formatted_route)
    
    # Add destination (if not already at end of route)
    if dest:
        icao = airport_data.get_icao(dest)
        dest_code = icao if icao else dest
        parts.append(dest_code)
    
    return ' '.join(parts)


def load_existing_routes(csv_path: str) -> Dict[str, List[RouteEntry]]:
    """Load existing routes from CSV, grouped by play name."""
    routes_by_play: Dict[str, List[RouteEntry]] = defaultdict(list)
    
    if not os.path.exists(csv_path):
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


def write_routes_csv(routes: List[RouteEntry], output_path: str):
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


def main():
    """Main entry point."""
    import argparse
    
    parser = argparse.ArgumentParser(
        description='Update playbook routes from FAA website',
        formatter_class=argparse.RawDescriptionHelpFormatter,
        epilog="""
Examples:
  # Full update (in-place)
  python update_playbook_routes.py --data-dir ./data

  # Test with limited plays
  python update_playbook_routes.py --data-dir ./data --limit 10

  # Test single play
  python update_playbook_routes.py --data-dir ./data --test-playkey 202510216853

  # Custom input/output files
  python update_playbook_routes.py --apts-csv data/apts.csv --input-csv data/playbook_routes.csv --output-csv data/playbook_routes_new.csv
"""
    )
    parser.add_argument('--data-dir', help='Data directory containing apts.csv and playbook_routes.csv')
    parser.add_argument('--apts-csv', help='Path to airports CSV (default: data/apts.csv or apts.csv)')
    parser.add_argument('--input-csv', help='Path to existing routes CSV (default: data/playbook_routes.csv or playbook_routes.csv)')
    parser.add_argument('--output-csv', help='Path to output CSV (default: same as input)')
    parser.add_argument('--test-play', help='Test with a single play name')
    parser.add_argument('--test-playkey', help='Test with a single playkey')
    parser.add_argument('--limit', type=int, help='Limit number of plays to process')
    parser.add_argument('--dry-run', action='store_true', help='Show what would be done without writing output')
    
    args = parser.parse_args()
    
    # Resolve file paths
    if args.data_dir:
        apts_csv = os.path.join(args.data_dir, 'apts.csv')
        input_csv = os.path.join(args.data_dir, 'playbook_routes.csv')
        output_csv = args.output_csv or input_csv
    else:
        apts_csv = args.apts_csv or 'apts.csv'
        input_csv = args.input_csv or 'playbook_routes.csv'
        output_csv = args.output_csv or input_csv.replace('.csv', '_new.csv')
    
    # Calculate current AIRAC cycle
    airac_cycle = calculate_airac_cycle()
    print(f"Current AIRAC cycle: {airac_cycle}")
    
    # Load airport data
    print(f"Loading airport data from {apts_csv}...")
    airport_data = AirportData(apts_csv)
    print(f"Loaded {len(airport_data.airports)} airports")
    
    # Load existing routes
    print(f"Loading existing routes from {input_csv}...")
    existing_routes = load_existing_routes(input_csv)
    print(f"Loaded {sum(len(r) for r in existing_routes.values())} existing routes from {len(existing_routes)} plays")
    
    # Test mode - single play
    if args.test_playkey:
        print(f"\nTesting with playkey {args.test_playkey}...")
        url = PLAY_URL_TEMPLATE.format(playkey=args.test_playkey)
        content = fetch_url(url)
        if content:
            play_name, origin_routes, dest_routes = parse_play_page(content)
            print(f"Play name: {play_name}")
            print(f"Origin routes: {len(origin_routes)}")
            print(f"Dest routes: {len(dest_routes)}")
            
            if origin_routes:
                print("\nSample origin routes:")
                for r in origin_routes[:3]:
                    print(f"  {r}")
            
            if dest_routes:
                print("\nSample dest routes:")
                for r in dest_routes[:3]:
                    print(f"  {r}")
            
            entries = generate_route_entries(play_name, origin_routes, dest_routes, airport_data)
            print(f"\nGenerated {len(entries)} route entries")
            if entries:
                print("\nSample entries:")
                for e in entries[:5]:
                    print(f"  {e.route_string}")
        return
    
    # Get list of all plays
    plays = get_play_list()
    
    if args.test_play:
        if args.test_play in plays:
            plays = {args.test_play: plays[args.test_play]}
        else:
            print(f"Play '{args.test_play}' not found")
            return
    
    if args.limit:
        plays = dict(list(plays.items())[:args.limit])
    
    # Process each play
    all_new_routes: Dict[str, List[RouteEntry]] = {}
    
    for i, (play_name, playkey) in enumerate(plays.items()):
        print(f"\n[{i+1}/{len(plays)}] Processing: {play_name}")
        
        url = PLAY_URL_TEMPLATE.format(playkey=playkey)
        content = fetch_url(url)
        
        if not content:
            print(f"  WARNING: Could not fetch play, skipping")
            continue
        
        parsed_name, origin_routes, dest_routes = parse_play_page(content)
        print(f"  Parsed: {len(origin_routes)} origin routes, {len(dest_routes)} dest routes")
        
        entries = generate_route_entries(play_name, origin_routes, dest_routes, airport_data)
        print(f"  Generated: {len(entries)} route entries")
        
        all_new_routes[play_name] = entries
        
        # Throttle requests
        time.sleep(REQUEST_DELAY)
    
    # Merge with existing routes, handling versioning
    print("\n" + "="*60)
    print("Merging routes...")
    
    final_routes: List[RouteEntry] = []
    
    # Track which existing plays were updated
    plays_updated = set()
    plays_deleted = set()
    plays_new = set()
    plays_unchanged = set()
    
    # Process new routes
    for play_name, new_entries in all_new_routes.items():
        if play_name in existing_routes:
            old_entries = existing_routes[play_name]
            
            if routes_are_equal(old_entries, new_entries):
                # Unchanged - keep as is
                final_routes.extend(new_entries)
                plays_unchanged.add(play_name)
            else:
                # Changed - version the old routes
                old_play_name = f"{play_name}_old_{airac_cycle}"
                for entry in old_entries:
                    final_routes.append(entry._replace(play=old_play_name))
                
                # Add new routes
                final_routes.extend(new_entries)
                plays_updated.add(play_name)
                print(f"  Updated: {play_name} (old version saved as {old_play_name})")
        else:
            # New play
            final_routes.extend(new_entries)
            plays_new.add(play_name)
            print(f"  New: {play_name}")
    
    # Handle deleted plays (in existing but not in new)
    for play_name in existing_routes:
        if play_name not in all_new_routes and not play_name.endswith(f'_old_{airac_cycle}'):
            # Check if it's not already an old version
            if '_old_' not in play_name:
                old_play_name = f"{play_name}_old_{airac_cycle}"
                for entry in existing_routes[play_name]:
                    final_routes.append(entry._replace(play=old_play_name))
                plays_deleted.add(play_name)
                print(f"  Deleted: {play_name} (saved as {old_play_name})")
            else:
                # Keep existing old versions
                final_routes.extend(existing_routes[play_name])
    
    # Sort routes by play name, then route string
    final_routes.sort(key=lambda r: (r.play, r.route_string))
    
    # Write output
    if args.dry_run:
        print(f"\n[DRY RUN] Would write {len(final_routes)} routes to {output_csv}")
    else:
        print(f"\nWriting {len(final_routes)} routes to {output_csv}...")
        write_routes_csv(final_routes, output_csv)
    
    # Summary
    print("\n" + "="*60)
    print("Summary:")
    print(f"  New plays: {len(plays_new)}")
    print(f"  Updated plays: {len(plays_updated)}")
    print(f"  Deleted plays: {len(plays_deleted)}")
    print(f"  Unchanged plays: {len(plays_unchanged)}")
    print(f"  Total routes: {len(final_routes)}")


if __name__ == '__main__':
    main()
