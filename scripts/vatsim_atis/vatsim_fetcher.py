"""
VATSIM Data Fetcher for ATIS Information

Fetches live ATIS data from the VATSIM API and extracts controller information.
Based on data fetching patterns from https://github.com/leftos/vatsim_control_recs
"""

import json
import logging
import re
import time
from dataclasses import dataclass, field
from datetime import datetime, timezone
from typing import Optional
from urllib.request import urlopen, Request
from urllib.error import URLError, HTTPError

logger = logging.getLogger(__name__)

# VATSIM API endpoints
VATSIM_DATA_URL = "https://data.vatsim.net/v3/vatsim-data.json"

# Cache for rate limiting
_cache: dict = {}
_cache_time: float = 0
CACHE_TTL_SECONDS = 15  # VATSIM updates every ~15 seconds


@dataclass
class AtisController:
    """Represents an ATIS controller from VATSIM data"""
    callsign: str           # e.g., "JFK_ATIS", "JFK_D_ATIS"
    airport_icao: str       # e.g., "KJFK"
    atis_type: str          # "COMB", "ARR", "DEP"
    atis_code: Optional[str] = None   # Information letter (A-Z)
    frequency: Optional[str] = None    # e.g., "121.900"
    atis_text: str = ""                # Full ATIS text (joined lines)
    controller_cid: Optional[int] = None
    logon_time: Optional[datetime] = None
    facility: int = 0                  # VATSIM facility type

    def to_dict(self) -> dict:
        return {
            'airport_icao': self.airport_icao,
            'callsign': self.callsign,
            'atis_type': self.atis_type,
            'atis_code': self.atis_code,
            'frequency': self.frequency,
            'atis_text': self.atis_text,
            'controller_cid': self.controller_cid,
            'logon_time': self.logon_time.isoformat() if self.logon_time else None,
        }


def fetch_vatsim_data(use_cache: bool = True) -> Optional[dict]:
    """
    Fetch current VATSIM data from the API.

    Args:
        use_cache: If True, return cached data if within TTL

    Returns:
        JSON data as dictionary, or None on error
    """
    global _cache, _cache_time

    # Check cache
    if use_cache and _cache and (time.time() - _cache_time) < CACHE_TTL_SECONDS:
        return _cache

    retries = 3
    backoff = 0.5

    for attempt in range(retries):
        try:
            request = Request(
                VATSIM_DATA_URL,
                headers={
                    'User-Agent': 'PERTI-VATSIM-ADL/1.0',
                    'Accept': 'application/json',
                }
            )

            with urlopen(request, timeout=30) as response:
                data = json.loads(response.read().decode('utf-8'))
                _cache = data
                _cache_time = time.time()
                return data

        except (URLError, HTTPError) as e:
            logger.warning(f"VATSIM API request failed (attempt {attempt + 1}): {e}")
            if attempt < retries - 1:
                time.sleep(backoff)
                backoff *= 2

        except json.JSONDecodeError as e:
            logger.error(f"Failed to parse VATSIM JSON: {e}")
            return None

    # Return stale cache if available
    if _cache:
        logger.warning("Returning stale cached data after API failures")
        return _cache

    return None


def _parse_callsign(callsign: str) -> tuple[str, str]:
    """
    Parse ATIS callsign to determine airport and ATIS type.

    Callsign patterns:
    - KJFK_ATIS, JFK_ATIS -> Combined ATIS
    - KJFK_D_ATIS, JFK_DEP_ATIS -> Departure ATIS
    - KJFK_A_ATIS, JFK_ARR_ATIS -> Arrival ATIS

    Returns:
        Tuple of (airport_icao, atis_type)
    """
    callsign = callsign.upper()

    # Pattern for departure ATIS
    dep_match = re.match(r'^([A-Z]{3,4})_(?:D|DEP)_ATIS$', callsign)
    if dep_match:
        airport = dep_match.group(1)
        # Add K prefix for US 3-letter codes
        if len(airport) == 3 and not airport.startswith(('P', 'K')):
            airport = 'K' + airport
        return airport, 'DEP'

    # Pattern for arrival ATIS
    arr_match = re.match(r'^([A-Z]{3,4})_(?:A|ARR)_ATIS$', callsign)
    if arr_match:
        airport = arr_match.group(1)
        if len(airport) == 3 and not airport.startswith(('P', 'K')):
            airport = 'K' + airport
        return airport, 'ARR'

    # Pattern for combined ATIS
    comb_match = re.match(r'^([A-Z]{3,4})_ATIS$', callsign)
    if comb_match:
        airport = comb_match.group(1)
        if len(airport) == 3 and not airport.startswith(('P', 'K')):
            airport = 'K' + airport
        return airport, 'COMB'

    # Fallback - try to extract airport code
    match = re.match(r'^([A-Z]{3,4})', callsign)
    if match:
        airport = match.group(1)
        if len(airport) == 3 and not airport.startswith(('P', 'K')):
            airport = 'K' + airport
        return airport, 'COMB'

    return '', 'COMB'


def _extract_atis_code(text_lines: list[str]) -> Optional[str]:
    """
    Extract ATIS information code (letter) from text.

    Looks for patterns like:
    - "ATIS INFO A"
    - "INFORMATION BRAVO"
    - "INFO C"
    """
    full_text = ' '.join(text_lines).upper()

    # Pattern for letter code
    match = re.search(r'(?:ATIS\s+)?INFO(?:RMATION)?\s+([A-Z])\b', full_text)
    if match:
        return match.group(1)

    # Pattern for spelled out (ALPHA, BRAVO, etc.)
    nato_map = {
        'ALFA': 'A', 'ALPHA': 'A', 'BRAVO': 'B', 'CHARLIE': 'C', 'DELTA': 'D',
        'ECHO': 'E', 'FOXTROT': 'F', 'GOLF': 'G', 'HOTEL': 'H', 'INDIA': 'I',
        'JULIET': 'J', 'JULIETT': 'J', 'KILO': 'K', 'LIMA': 'L', 'MIKE': 'M',
        'NOVEMBER': 'N', 'OSCAR': 'O', 'PAPA': 'P', 'QUEBEC': 'Q', 'ROMEO': 'R',
        'SIERRA': 'S', 'TANGO': 'T', 'UNIFORM': 'U', 'VICTOR': 'V', 'WHISKEY': 'W',
        'XRAY': 'X', 'X-RAY': 'X', 'YANKEE': 'Y', 'ZULU': 'Z'
    }

    for word, letter in nato_map.items():
        if re.search(rf'(?:ATIS\s+)?INFO(?:RMATION)?\s+{word}\b', full_text):
            return letter

    return None


def extract_atis_controllers(vatsim_data: dict) -> list[AtisController]:
    """
    Extract all ATIS controllers from VATSIM data.

    Args:
        vatsim_data: Raw VATSIM API response

    Returns:
        List of AtisController objects
    """
    atis_list: list[AtisController] = []

    if not vatsim_data or 'atis' not in vatsim_data:
        return atis_list

    for atis in vatsim_data.get('atis', []):
        callsign = atis.get('callsign', '')

        # Skip non-ATIS entries
        if '_ATIS' not in callsign.upper():
            continue

        airport, atis_type = _parse_callsign(callsign)
        if not airport:
            continue

        # Parse logon time
        logon_time = None
        logon_str = atis.get('logon_time', '')
        if logon_str:
            try:
                # VATSIM format: "2024-01-15T12:30:00"
                logon_time = datetime.fromisoformat(logon_str.replace('Z', '+00:00'))
            except ValueError:
                pass

        # Get ATIS text (join lines)
        text_lines = atis.get('text_atis', []) or []
        atis_text = ' '.join(text_lines)

        # Extract ATIS code
        atis_code = _extract_atis_code(text_lines)

        controller = AtisController(
            callsign=callsign,
            airport_icao=airport,
            atis_type=atis_type,
            atis_code=atis_code or atis.get('atis_code'),
            frequency=atis.get('frequency'),
            atis_text=atis_text,
            controller_cid=atis.get('cid'),
            logon_time=logon_time,
            facility=atis.get('facility', 0)
        )

        atis_list.append(controller)

    return atis_list


def get_atis_for_airports(
    airports: Optional[list[str]] = None,
    include_all: bool = False
) -> dict[str, list[AtisController]]:
    """
    Get ATIS information for specified airports.

    Args:
        airports: List of airport ICAO codes to filter (e.g., ["KJFK", "KLAX"])
                  If None and include_all=True, returns all airports
        include_all: If True and airports is None, return all ATIS data

    Returns:
        Dictionary mapping airport ICAO to list of AtisController objects
    """
    vatsim_data = fetch_vatsim_data()
    if not vatsim_data:
        return {}

    all_atis = extract_atis_controllers(vatsim_data)

    # Group by airport
    result: dict[str, list[AtisController]] = {}

    for controller in all_atis:
        # Filter by airport if specified
        if airports:
            # Normalize airport codes for comparison
            normalized_airports = set()
            for apt in airports:
                apt = apt.upper()
                normalized_airports.add(apt)
                # Also add with/without K prefix
                if apt.startswith('K') and len(apt) == 4:
                    normalized_airports.add(apt[1:])
                elif len(apt) == 3:
                    normalized_airports.add('K' + apt)

            if controller.airport_icao not in normalized_airports:
                continue

        elif not include_all:
            continue

        if controller.airport_icao not in result:
            result[controller.airport_icao] = []
        result[controller.airport_icao].append(controller)

    return result


def get_all_atis_as_json() -> str:
    """
    Get all ATIS data formatted as JSON for database import.

    Returns:
        JSON string suitable for sp_ImportVatsimAtis
    """
    atis_dict = get_atis_for_airports(include_all=True)

    records = []
    for airport, controllers in atis_dict.items():
        for ctrl in controllers:
            records.append(ctrl.to_dict())

    return json.dumps(records)


# === Testing ===

if __name__ == '__main__':
    import sys

    logging.basicConfig(level=logging.INFO)

    print("Fetching VATSIM ATIS data...")
    data = fetch_vatsim_data()

    if not data:
        print("Failed to fetch data")
        sys.exit(1)

    print(f"Data timestamp: {data.get('general', {}).get('update_timestamp', 'unknown')}")

    atis_list = extract_atis_controllers(data)
    print(f"Found {len(atis_list)} ATIS controllers")

    if atis_list:
        print("\nSample ATIS entries:")
        for atis in atis_list[:10]:
            print(f"  {atis.callsign} ({atis.airport_icao}): "
                  f"Type={atis.atis_type}, Code={atis.atis_code or '-'}, "
                  f"Freq={atis.frequency or '-'}")
            if atis.atis_text:
                preview = atis.atis_text[:100] + "..." if len(atis.atis_text) > 100 else atis.atis_text
                print(f"    Text: {preview}")

    # Show airports with ATIS
    airports = sorted(set(a.airport_icao for a in atis_list))
    print(f"\nAirports with ATIS ({len(airports)}): {', '.join(airports[:20])}...")
