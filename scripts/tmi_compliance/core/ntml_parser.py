"""
TMI Compliance Analyzer - NTML Parser
======================================

Parses NTML (National Traffic Management Log) text into TMI objects.
Handles raw Discord-pasted content with usernames, timestamps, and Unicode formatting.
"""

import re
import logging
from dataclasses import dataclass
from datetime import datetime, timedelta
from typing import List, Tuple, Optional

from .models import (
    TMI, TMIType, MITModifier, DelayEntry, DelayType, DelayTrend, HoldingStatus,
    AirportConfig, CancelEntry, TrafficDirection, TrafficFilter, AircraftType, AltitudeFilter,
    ComparisonOp
)

logger = logging.getLogger(__name__)


def clean_discord_text(text: str) -> str:
    """
    Remove Discord metadata and Unicode formatting from pasted text.

    Filters out:
    - Discord usernames with timestamps (e.g., "Daniel G | ZBW I1 — 05:24")
    - Unicode directional marks (U+2068, U+2069, etc.)
    - Empty lines and whitespace-only lines
    """
    # Remove Unicode directional/formatting characters
    # U+2068 (First Strong Isolate), U+2069 (Pop Directional Isolate)
    # U+200B (Zero Width Space), U+FEFF (BOM)
    unicode_chars = '\u2068\u2069\u200b\ufeff\u200c\u200d\u2066\u2067\u202a\u202b\u202c\u202d\u202e'
    for char in unicode_chars:
        text = text.replace(char, '')

    lines = text.split('\n')
    cleaned_lines = []

    for line in lines:
        line = line.strip()
        if not line:
            continue

        # Skip Discord username lines (pattern: "Name | Facility Role — HH:MM" or "Name | Facility Role — Today at HH:MM")
        # Common patterns:
        # - "Daniel G | ZBW I1 — 05:24"
        # - "Cameron P | ZBW EC — 08:49"
        # - "Michael B | VATUSA5 — 11:00"
        if re.match(r'^[A-Za-z\s]+\s*\|\s*\w+.*\s*—\s*\d{1,2}:\d{2}', line):
            continue
        if re.match(r'^[A-Za-z\s]+\s*\|\s*\w+.*\s*—\s*Today at\s*\d{1,2}:\d{2}', line, re.IGNORECASE):
            continue

        cleaned_lines.append(line)

    return '\n'.join(cleaned_lines)


def classify_line(line: str) -> Tuple[str, Optional[dict]]:
    """
    Classify a line as a specific type and extract key info.

    Returns:
        Tuple of (line_type, metadata_dict or None)

    Line types:
        - "advzy_header": vATCSCC ADVZY header line
        - "advzy_content": Content within an ADVZY block
        - "mit": Miles-in-trail restriction
        - "stop": STOP restriction
        - "cfr": Call for release
        - "cancel": Cancellation
        - "airport_config": Airport configuration (VMC/IMC, runways, rates)
        - "ed_hold": E/D (Expect Delays with holding)
        - "dd_delay": D/D (Departure Delays)
        - "route_table": Route table content from ADVZY
        - "unknown": Unrecognized format
    """
    line = line.strip()
    if not line:
        return ("empty", None)

    # ADVZY header: "vATCSCC ADVZY 001 DCC 01/30/2026 ROUTE RQD"
    if re.match(r'^vATCSCC\s+ADVZY\s+\d+', line, re.IGNORECASE):
        return ("advzy_header", {"line": line})

    # Airport configuration: "30/2328    BOS    VMC    ARR:27/32 DEP:33L    AAR:40 ADR:40"
    if re.search(r'\b(VMC|IMC)\b.*\bARR:', line) or re.search(r'\bAAR:\d+\s+ADR:\d+', line):
        return ("airport_config", {"line": line})

    # E/D (En Route Delays): "31/0127    ZBW E/D for BOS +Holding/0147/2 ACFT"
    # Can have +Holding (start holding), -Holding (stop holding), or delay amounts
    if re.search(r'\bE/D\b', line, re.IGNORECASE):
        return ("ed_delay", {"line": line})

    # A/D (Arrival Delays): Similar format to E/D
    if re.search(r'\bA/D\b', line, re.IGNORECASE):
        return ("ad_delay", {"line": line})

    # D/D (Departure Delays): "31/0153    D/D from BOS +35/0153"
    # Typically ground delays, measured in 15-min increments
    if re.search(r'\bD/D\b', line, re.IGNORECASE):
        return ("dd_delay", {"line": line})

    # CANCEL patterns - be flexible with variations:
    # - "31/0326    BOS via RBV CANCEL RESTR ZNY:ZDC"
    # - "BOS via ALL CANCEL RESTR ZBW:ZNY,ZOB"
    # - "CANCEL RESTR" anywhere in line
    # - "CNCL" abbreviation
    # - Just "CANCEL" followed by facility info
    if re.search(r'\bCANCEL\b', line, re.IGNORECASE) or re.search(r'\bCNCL\b', line, re.IGNORECASE):
        return ("cancel", {"line": line})

    # STOP restriction: "BOS via Q133 STOP" or "30/2327    BOS via HNK STOP VOLUME:VOLUME 2330-0400 ZBW:ZNY"
    if re.search(r'\bvia\s+\S+\s+STOP\b', line, re.IGNORECASE):
        return ("stop", {"line": line})

    # CFR: "JFK via ALL CFR VOLUME:VOLUME 0000-0400 ZDC:PCT"
    if re.search(r'\bCFR\b', line, re.IGNORECASE) and re.search(r'\bvia\b', line, re.IGNORECASE):
        return ("cfr", {"line": line})

    # MIT restriction: "30/2100    LGA via BEUTY 25 MIT VOLUME:VOLUME 2330-0400 N90:ZNY"
    if re.search(r'\b\d+\s*MIT\b', line, re.IGNORECASE):
        return ("mit", {"line": line})

    # MINIT restriction
    if re.search(r'\b\d+\s*MINIT\b', line, re.IGNORECASE):
        return ("minit", {"line": line})

    # TMI ID line: "TMI ID: RRDCC001"
    if re.match(r'^TMI\s+ID:', line, re.IGNORECASE):
        return ("tmi_id", {"line": line})

    # Time range line: "302230 - 310300" or "26/01/30 20:24"
    if re.match(r'^\d{6}\s*-\s*\d{6}$', line) or re.match(r'^\d{2}/\d{2}/\d{2}\s+\d{2}:\d{2}', line):
        return ("timestamp", {"line": line})

    # ADVZY field lines: "NAME:", "CONSTRAINED AREA:", "REASON:", etc.
    advzy_fields = ['NAME:', 'CONSTRAINED AREA:', 'REASON:', 'INCLUDE TRAFFIC:',
                    'FACILITIES INCLUDED:', 'FLIGHT STATUS:', 'VALID:',
                    'PROBABILITY OF EXTENSION:', 'REMARKS:', 'ASSOCIATED RESTRICTIONS:',
                    'MODIFICATIONS:', 'ROUTES:', 'EVENT TIME:', 'CTL ELEMENT:',
                    'ELEMENT TYPE:', 'ADL TIME:', 'GROUND STOP PERIOD:',
                    'DEP FACILITIES INCLUDED:', 'IMPACTING CONDITION:', 'COMMENTS:',
                    'NEW TOTAL, MAXIMUM, AVERAGE DELAYS:']
    for field in advzy_fields:
        if line.upper().startswith(field):
            return ("advzy_field", {"field": field, "line": line})

    # Route table header: "ORIG       DEST    ROUTE" or "----       ----    -----"
    if re.match(r'^ORIG\s+DEST\s+ROUTE', line, re.IGNORECASE) or re.match(r'^-+\s+-+\s+-+', line):
        return ("route_header", {"line": line})

    # Route table entry: "ORF        BOS     >HPW BBOBO Q22 RBV Q419 JFK< ROBUC3"
    if re.search(r'>\S+.*<', line):  # Contains route markers >...<
        return ("route_entry", {"line": line})

    return ("unknown", {"line": line})


def parse_advzy_ground_stop(lines: List[str], start_idx: int, event_start: datetime, event_end: datetime,
                            destinations: List[str]) -> Tuple[Optional[TMI], int]:
    """
    Parse an ADVZY Ground Stop block starting at the given index.

    ADVZY Ground Stop format:
        vATCSCC ADVZY 001 LAS/ZLA 01/18/2026 CDM GROUND STOP
        CTL ELEMENT: LAS
        ELEMENT TYPE: APT
        ADL TIME: 0244Z
        GROUND STOP PERIOD: 18/0230Z – 18/0315Z
        DEP FACILITIES INCLUDED: (Manual) ZOA
        ...

    Returns:
        Tuple of (TMI or None, number of lines consumed)
    """
    if start_idx >= len(lines):
        return (None, 0)

    header_line = lines[start_idx]

    # Verify this is a Ground Stop ADVZY
    if 'GROUND STOP' not in header_line.upper():
        return (None, 0)

    # Extract airport from header (format: "vATCSCC ADVZY 001 LAS/ZLA 01/18/2026 CDM GROUND STOP")
    header_match = re.search(r'ADVZY\s+\d+\s+([A-Z]{3})/', header_line, re.IGNORECASE)
    dest_from_header = header_match.group(1).upper() if header_match else None

    # Parse subsequent lines to extract fields
    dest = dest_from_header
    gs_start = None
    gs_end = None
    issued_time = None
    provider = None
    lines_consumed = 1  # Start with the header line

    event_date = event_start.date()

    def parse_time(time_str: str) -> Optional[datetime]:
        """Parse HHMM or HH:MMZ format time"""
        if not time_str:
            return None
        time_str = time_str.replace(':', '').replace('Z', '').strip()
        if len(time_str) >= 4:
            try:
                hour = int(time_str[:2])
                minute = int(time_str[2:4])
                result = datetime(event_date.year, event_date.month, event_date.day, hour, minute)
                if result < event_start - timedelta(hours=2):
                    result = result + timedelta(days=1)
                return result
            except ValueError:
                return None
        return None

    # Scan subsequent lines for ADVZY fields
    for i in range(start_idx + 1, min(start_idx + 15, len(lines))):  # Look at most 15 lines ahead
        line = lines[i].strip()
        if not line:
            lines_consumed += 1
            continue

        # Check if we've hit another TMI or ADVZY (end of this block)
        if re.match(r'^\d{2}/\d{4}\s+', line) or re.match(r'^vATCSCC\s+ADVZY', line, re.IGNORECASE):
            break

        lines_consumed += 1

        # CTL ELEMENT: LAS
        ctl_match = re.match(r'^CTL\s+ELEMENT:\s*(\w+)', line, re.IGNORECASE)
        if ctl_match:
            dest = ctl_match.group(1).upper()
            continue

        # GROUND STOP PERIOD: 18/0230Z – 18/0315Z
        # Handle various dash types (– — -)
        gs_period_match = re.search(r'GROUND\s+STOP\s+PERIOD:\s*\d{2}/(\d{4})Z?\s*[-–—]\s*\d{2}/(\d{4})Z?',
                                    line, re.IGNORECASE)
        if gs_period_match:
            gs_start = parse_time(gs_period_match.group(1))
            gs_end = parse_time(gs_period_match.group(2))
            continue

        # ADL TIME: 0244Z (issued time)
        adl_match = re.match(r'^ADL\s+TIME:\s*(\d{4})Z?', line, re.IGNORECASE)
        if adl_match:
            issued_time = parse_time(adl_match.group(1))
            continue

        # DEP FACILITIES INCLUDED: (Manual) ZOA
        dep_fac_match = re.search(r'DEP\s+FACILITIES\s+INCLUDED:.*?([A-Z]{3})', line, re.IGNORECASE)
        if dep_fac_match:
            provider = dep_fac_match.group(1).upper()
            continue

    # Create TMI if we have enough info
    if dest and (gs_start or gs_end):
        tmi = TMI(
            tmi_id=f'GS_ADVZY_{dest}_{provider or "ALL"}',
            tmi_type=TMIType.GS,
            destinations=[dest] if dest not in ['ALL', 'ANY'] else destinations,
            origins=[],
            provider=provider or 'ALL',
            requestor=dest,
            start_utc=gs_start or event_start,
            end_utc=gs_end or event_end,
            issued_utc=issued_time,
            reason=f'ADVZY Ground Stop'
        )
        return (tmi, lines_consumed)

    return (None, lines_consumed)


def parse_ntml_to_tmis(ntml_text: str, event_start: datetime, event_end: datetime, destinations: List[str]) -> List[TMI]:
    """
    Parse NTML text into TMI objects.

    Handles raw Discord-pasted content with automatic filtering of:
    - Discord usernames and timestamps
    - Unicode formatting characters
    - Non-TMI lines (airport configs, E/D, D/D, ADVZY blocks)

    Supported TMI formats:
    - MIT: "30/2100    LGA via BEUTY 25 MIT VOLUME:VOLUME 2330-0400 N90:ZNY"
    - MINIT: "BNA via GROAT 5 MINIT ZME:ZID 0000Z-0400Z"
    - STOP: "30/2327    BOS via HNK STOP VOLUME:VOLUME 2330-0400 ZBW:ZNY"
    - CFR: "JFK via ALL CFR VOLUME:VOLUME 0000-0400 ZDC:PCT"
    - GS: "LAS GS (NCT) 0230Z-0315Z issued 0244Z"
    - Cancel: "31/0326    BOS via RBV CANCEL RESTR ZNY:ZDC"
    - Cancellations (old format): "CXLD 0330Z" at end of line
    """
    tmis = []

    # Clean Discord metadata first
    cleaned_text = clean_discord_text(ntml_text)
    lines = cleaned_text.strip().split('\n')

    # Get event date for parsing times
    event_date = event_start.date()

    def parse_time(time_str: str, base_date) -> Optional[datetime]:
        """Parse HHMM or HH:MM format time, adjusting date for overnight"""
        if not time_str:
            return None
        time_str = time_str.replace(':', '').replace('Z', '').strip()
        if len(time_str) == 4:
            try:
                hour = int(time_str[:2])
                minute = int(time_str[2:])
                result = datetime(base_date.year, base_date.month, base_date.day, hour, minute)
                # If time < event start time, it's likely next day
                if result < event_start - timedelta(hours=2):
                    result = result + timedelta(days=1)
                return result
            except ValueError:
                return None
        return None

    def parse_ntml_time_range(text: str, base_date) -> Tuple[Optional[datetime], Optional[datetime]]:
        """
        Parse time range from NTML format.
        Handles: "2330-0400", "0000-0400", "2359Z-0400Z"
        """
        match = re.search(r'(\d{4})Z?\s*-\s*(\d{4})Z?', text)
        if match:
            start = parse_time(match.group(1), base_date)
            end = parse_time(match.group(2), base_date)
            return (start, end)
        return (None, None)

    def parse_facilities(text: str) -> Tuple[str, str, bool]:
        """
        Parse requestor:provider facility pair.

        Handles various facility types and formats:
        - ARTCC: ZNY, ZDC, ZBW (3-letter starting with Z)
        - TRACON: N90, A90, C90, PCT, SCT (2-4 chars)
        - Airport: KJFK, KBOS, JFK (3-4 chars)

        Formats supported:
        - Simple: "N90:ZNY", "ZDC:ZBW"
        - Multiple facilities: "ZNY,N90:ZBW,ZDC,ZOB"
        - With (MULTIPLE) suffix: "ZNY:ZDC(MULTIPLE)"
        - With trailing TMI ID: "ZOA:ZSE $ 05B01E"

        Returns: (requestor, provider, is_multiple)
        """
        # Check for and strip (MULTIPLE) suffix
        is_multiple = False
        clean_text = text
        if re.search(r'\(MULTIPLE\)\s*$', text, re.IGNORECASE):
            is_multiple = True
            clean_text = re.sub(r'\(MULTIPLE\)\s*$', '', text, flags=re.IGNORECASE).strip()

        # Strip trailing TMI ID codes (format: "$ DDCDDL" where D=digit, C=[ABCDEXO], L=optional letter)
        # Pattern: 2 digits + [ABCDEXO] + 2 digits + optional letter
        clean_text = re.sub(r'\s*\$\s*\d{2}[ABCDEXO]\d{2}[A-Za-z]?\s*$', '', clean_text).strip()

        # Find ALL facility pairs in the line (pattern: FACILITY:FACILITY)
        # We want the LAST one that looks like a real facility pair (not EXCL:NONE, VOLUME:VOLUME, etc.)
        # Real facility codes: Z** (ARTCC), *## (TRACON like N90, A80), 3-4 letter codes
        all_matches = re.findall(r'([A-Z][A-Z0-9,]+):([A-Z][A-Z0-9,]+)', clean_text)

        # Filter out known non-facility patterns
        non_facility_patterns = {'EXCL', 'NONE', 'VOLUME', 'TYPE', 'SPD', 'ALT', 'STREAM'}

        for requestor, provider in reversed(all_matches):
            # Check if either part looks like a non-facility keyword
            req_parts = requestor.split(',')
            prov_parts = provider.split(',')

            # Skip if any part is a known non-facility keyword
            if any(p.upper() in non_facility_patterns for p in req_parts + prov_parts):
                continue

            # This looks like a valid facility pair
            return (requestor, provider, is_multiple)

        return ('', '', False)

    def parse_ntml_timestamp(line: str, base_date) -> Optional[datetime]:
        """
        Parse NTML timestamp prefix (DD/HHMM format).
        Example: "30/2100" = day 30, 21:00Z

        Returns datetime when this NTML entry was issued/posted.
        Used for tracking MIT amendments (multiple entries updating same restriction).
        """
        match = re.match(r'^(\d{2})/(\d{4})\s+', line)
        if match:
            day = int(match.group(1))
            time_str = match.group(2)
            try:
                hour = int(time_str[:2])
                minute = int(time_str[2:])

                # Start with base date's year and month
                result = datetime(base_date.year, base_date.month, day, hour, minute)

                # If result is way before event, it might be next month
                if result < event_start - timedelta(days=7):
                    # Try next month
                    if base_date.month == 12:
                        result = datetime(base_date.year + 1, 1, day, hour, minute)
                    else:
                        result = datetime(base_date.year, base_date.month + 1, day, hour, minute)

                return result
            except ValueError:
                return None
        return None

    def parse_mit_modifier(line: str) -> MITModifier:
        """
        Parse MIT modifier from line.

        AS_ONE / SINGLE_STREAM: Provider must provide 1 stream/flow to requestor
            by handoff point (not multiple streams/handoff points). All traffic
            merged into single stream regardless of origin.
        PER_STREAM / PER_FIX / PER_ROUTE / EACH: Each fix/route gets separate MIT
            e.g., "35MIT PER STREAM" with "AUDIL/MEMMS" = separate MIT per fix
        PER_AIRPORT: Each origin/destination airport gets its own MIT
        NO_STACKS: Don't send over planes that overlap each other (no vertical stacking)
        EVERY_OTHER: TMI applies to alternating flights (A, C, E but not B, D, F)
        RALT: Regardless of altitude - MIT applies to all altitudes
        """
        # Check for compound modifiers (can have multiple)
        # Priority: specific modifiers first, then general

        # AS ONE / SINGLE STREAM
        if re.search(r'\bAS\s+ONE\b', line, re.IGNORECASE):
            return MITModifier.AS_ONE
        if re.search(r'\bSINGLE\s+STREAM\b', line, re.IGNORECASE):
            return MITModifier.SINGLE_STREAM

        # PER variants (all equivalent for analysis purposes)
        if re.search(r'\bPER\s+STREAM\b', line, re.IGNORECASE):
            return MITModifier.PER_STREAM
        if re.search(r'\bPER\s+FIX\b', line, re.IGNORECASE):
            return MITModifier.PER_FIX
        if re.search(r'\bPER\s+ROUTE\b', line, re.IGNORECASE):
            return MITModifier.PER_ROUTE
        if re.search(r'\bPER\s+STRAT\b', line, re.IGNORECASE):  # Per stratum
            return MITModifier.PER_STREAM
        if re.search(r'\bPER\s+AIRPORT\b', line, re.IGNORECASE):
            return MITModifier.PER_AIRPORT
        if re.search(r'\bEACH\b', line, re.IGNORECASE):
            return MITModifier.EACH

        # Special modifiers
        if re.search(r'\bNO\s+STACKS?\b', line, re.IGNORECASE):
            return MITModifier.NO_STACKS
        if re.search(r'\bEVERY\s+OTHER\b', line, re.IGNORECASE):
            return MITModifier.EVERY_OTHER
        if re.search(r'\bRALT\b', line, re.IGNORECASE):
            return MITModifier.RALT

        return MITModifier.STANDARD

    def parse_traffic_filter(line: str) -> Optional[TrafficFilter]:
        """
        Parse traffic filter specifications from NTML line.

        Examples:
        - TYPE:ALL - All aircraft types
        - TYPE:JET - Jets only
        - SPD:S210 or SPD:<=210 - Speed <= 210 knots
        - SPD:210 or SPD:=210 - Speed AT 210 knots
        - SPD:>210 - Speed > 210 knots
        - ALT:AOB090 - At or below FL090
        - EXCL:PHL - Exclude PHL traffic

        SPD format: SPD:[<=, ≤, <, =, >, ≥, >=, S]<value>[KT|KTS]
        - If no operator, means AT specified speed
        - S prefix (e.g., S210) means <= (at or below)
        """
        filter_obj = TrafficFilter()
        has_filter = False

        # Parse TYPE filter (TYPE:ALL, TYPE:JET, TYPE:PROP, TYPE:TURBOPROP)
        type_match = re.search(r'\bTYPE:\s*(ALL|JET|PROP|TURBOPROP)\b', line, re.IGNORECASE)
        if type_match:
            type_str = type_match.group(1).upper()
            filter_obj.aircraft_type = AircraftType[type_str]
            has_filter = True

        # Parse SPD filter with full operator support
        # Pattern: SPD:[op]<value>[KT|KTS]
        # Operators: <=, ≤, <, =, >, ≥, >=, S (S = <=)
        spd_match = re.search(
            r'\bSPD:\s*(<=|≤|<|=|>=|≥|>|S)?(\d+)(?:KTS?)?',
            line, re.IGNORECASE
        )
        if spd_match:
            op_str = spd_match.group(1) or ''
            speed_val = int(spd_match.group(2))

            # Map operator string to ComparisonOp
            op_str_upper = op_str.upper() if op_str else ''
            if op_str_upper in ['<=', '≤', 'S']:
                filter_obj.speed_op = ComparisonOp.LE
            elif op_str_upper == '<':
                filter_obj.speed_op = ComparisonOp.LT
            elif op_str_upper in ['>=', '≥']:
                filter_obj.speed_op = ComparisonOp.GE
            elif op_str_upper == '>':
                filter_obj.speed_op = ComparisonOp.GT
            else:
                # No operator or '=' means AT
                filter_obj.speed_op = ComparisonOp.AT

            filter_obj.speed_value = speed_val
            has_filter = True

        # Parse ALT filter (ALT:AOB090, ALT:AOA180, ALT:AT350)
        alt_match = re.search(r'\bALT:\s*(AT|AOB|AOA|LOA)(\d+)\b', line, re.IGNORECASE)
        if alt_match:
            alt_type = alt_match.group(1).upper()
            # LOA = Level or Above = AOA
            if alt_type == 'LOA':
                alt_type = 'AOA'
            filter_obj.altitude_filter = AltitudeFilter[alt_type]
            filter_obj.altitude_value = int(alt_match.group(2))
            has_filter = True

        # Parse EXCL filter (EXCL:PHL, EXCL:EWR,LGA, EXCL:NONE)
        excl_match = re.search(r'\bEXCL:\s*([A-Z0-9,]+)\b', line, re.IGNORECASE)
        if excl_match:
            excl_str = excl_match.group(1).upper()
            if excl_str != 'NONE':
                filter_obj.exclusions = [e.strip() for e in excl_str.split(',') if e.strip()]
                has_filter = True

        return filter_obj if has_filter else None

    def parse_traffic_direction(line: str) -> TrafficDirection:
        """
        Parse traffic direction from NTML line.

        Patterns:
        - "JFK arrivals via CAMRN" -> ARRIVALS
        - "EWR,LGA departures via BIGGY" -> DEPARTURES
        - "JFK via CAMRN" (no direction specified) -> BOTH
        - Variants: ARVL, ARVLS, ARR, ARRS, DEP, DEPS, DEPT, DEPTS
        """
        # Check for arrivals keywords
        if re.search(r'\b(arrivals?|arvls?|arrs?)\b', line, re.IGNORECASE):
            return TrafficDirection.ARRIVALS

        # Check for departures keywords
        if re.search(r'\b(departures?|deps?|depts?)\b', line, re.IGNORECASE):
            return TrafficDirection.DEPARTURES

        # Check for LTFC (Landing Traffic) and DTFC (Departing Traffic)
        if re.search(r'\bLTFC\b', line, re.IGNORECASE):
            return TrafficDirection.ARRIVALS
        if re.search(r'\bDTFC\b', line, re.IGNORECASE):
            return TrafficDirection.DEPARTURES

        return TrafficDirection.BOTH

    skipped_lines = []

    i = 0
    while i < len(lines):
        line = lines[i].strip()
        if not line:
            i += 1
            continue

        line_type, metadata = classify_line(line)
        tmi = None

        # Handle ADVZY Ground Stops (multi-line format)
        if line_type == 'advzy_header' and 'GROUND STOP' in line.upper():
            gs_tmi, lines_consumed = parse_advzy_ground_stop(lines, i, event_start, event_end, destinations)
            if gs_tmi:
                tmis.append(gs_tmi)
                logger.debug(f"Parsed ADVZY Ground Stop: {gs_tmi.destinations} from {gs_tmi.provider}")
            i += lines_consumed
            continue

        # Skip other non-TMI lines but log them
        if line_type in ('advzy_header', 'advzy_field', 'airport_config', 'ed_delay',
                         'ad_delay', 'dd_delay', 'route_header', 'route_entry', 'tmi_id',
                         'timestamp', 'empty'):
            skipped_lines.append((line_type, line))
            i += 1
            continue

        # Handle CANCEL RESTR - these cancel existing TMIs, not create new ones
        if line_type == 'cancel':
            # Log cancellation but don't create TMI
            # Format: "31/0326    BOS via RBV CANCEL RESTR ZNY:ZDC"
            logger.debug(f"Cancellation line (not creating TMI): {line}")
            skipped_lines.append((line_type, line))
            i += 1
            continue

        cancelled_utc = None

        # Check for old-style cancellation at end of line
        cxld_match = re.search(r'CXLD?\s*(\d{4})Z?', line, re.IGNORECASE)
        if cxld_match:
            cancelled_utc = parse_time(cxld_match.group(1), event_date)
            line = re.sub(r'CXLD?\s*\d{4}Z?', '', line, flags=re.IGNORECASE).strip()

        # Parse STOP restriction
        # Format: "30/2327    BOS via HNK STOP VOLUME:VOLUME 2330-0400 ZBW:ZNY"
        # Also: "BOS via Q133 STOP TYPE:JET EXCL:NONE VOLUME:VOLUME 2330-0400 ZNY:ZDC"
        if line_type == 'stop':
            # Remove leading timestamp if present (DD/HHMM)
            clean_line = re.sub(r'^\d{2}/\d{4}\s+', '', line).strip()

            stop_match = re.match(
                r'(\w+)\s+via\s+(\S+)\s+STOP\b',
                clean_line, re.IGNORECASE
            )
            if stop_match:
                dest = stop_match.group(1).upper()
                fix = stop_match.group(2).upper()
                start_time, end_time = parse_ntml_time_range(line, event_date)
                requestor, provider, is_multiple = parse_facilities(line)

                tmi = TMI(
                    tmi_id=f'STOP_{fix}_{dest}',
                    tmi_type=TMIType.MIT,  # STOP is essentially 0 MIT (no departures via this fix)
                    fix=fix,
                    destinations=[dest] if dest not in ['ALL', 'ANY'] else destinations,
                    value=0,  # STOP = 0 MIT
                    unit='nm',
                    provider=provider,
                    requestor=requestor,
                    is_multiple=is_multiple,
                    start_utc=start_time or event_start,
                    end_utc=end_time or event_end,
                    cancelled_utc=cancelled_utc,
                    reason=f'STOP via {fix}'
                )

        # Parse MIT restriction
        # Format: "30/2100    LGA via BEUTY 25 MIT VOLUME:VOLUME 2330-0400 N90:ZNY"
        # Also handles: "LGA via VALRE/NOBBI 20 MIT AS ONE VOLUME:VOLUME 2330-0400 N90:ZBW"
        # Also handles: "BOS via AUDIL/MEMMS 35 MIT PER STREAM VOLUME:VOLUME 2330-0400 ZBW:ZOB"
        # Also handles: "JFK via ALL 60 MIT AS ONE VOLUME:VOLUME 2300-0300 ZOB:ZID"
        # Also handles: "JFK arrivals via CAMRN 20MIT NO STACKS TYPE:ALL SPD:S210"
        # Also handles: "EWR,LGA departures via BIGGY 15MIT PER AIRPORT TYPE:JET"
        elif line_type == 'mit':
            # Parse NTML timestamp prefix as issued_utc
            issued_utc = parse_ntml_timestamp(line, event_date)

            # Remove leading timestamp if present
            clean_line = re.sub(r'^\d{2}/\d{4}\s+', '', line).strip()

            # Enhanced pattern to capture arrivals/departures and multi-airport specs
            # Pattern: "JFK arrivals via CAMRN 20MIT" or "EWR,LGA departures via BIGGY 15MIT"
            mit_match = re.match(
                r'([A-Z0-9,\s]+?)(?:\s+(arrivals?|departures?|arvls?|deps?|depts?))?\s+via\s+(\S+)\s+(\d+)\s*MIT\b',
                clean_line, re.IGNORECASE
            )
            if mit_match:
                airport_str = mit_match.group(1).strip().upper()
                direction_word = mit_match.group(2)  # May be None
                fix_str = mit_match.group(3).upper()
                value = int(mit_match.group(4))
                start_time, end_time = parse_ntml_time_range(line, event_date)
                requestor, provider, is_multiple = parse_facilities(line)

                # Parse modifier (AS ONE, PER STREAM, PER ROUTE, etc.)
                modifier = parse_mit_modifier(line)

                # Parse traffic filter (TYPE, SPD, ALT, EXCL)
                traffic_filter = parse_traffic_filter(line)

                # Parse traffic direction from the full line
                traffic_direction = parse_traffic_direction(line)

                # Parse destinations/origins from airport_str
                # May be comma-separated: "EWR,LGA" or single: "JFK"
                if ',' in airport_str:
                    airports = [a.strip() for a in airport_str.split(',') if a.strip()]
                else:
                    airports = [airport_str] if airport_str not in ['ALL', 'ANY'] else destinations

                # Determine if these are destinations or origins based on direction
                if traffic_direction == TrafficDirection.DEPARTURES:
                    dest_list = destinations  # Using event destinations
                    origin_list = airports
                else:
                    dest_list = airports
                    origin_list = []

                # Handle multi-fix entries (e.g., "AUDIL/MEMMS" for PER STREAM)
                # For PER STREAM, split into separate TMIs per fix
                if '/' in fix_str and modifier in [MITModifier.PER_STREAM, MITModifier.PER_FIX, MITModifier.EACH]:
                    fixes = [f.strip() for f in fix_str.split('/') if f.strip()]
                    for fix in fixes:
                        tmi = TMI(
                            tmi_id=f'MIT_{fix}_{airport_str}',
                            tmi_type=TMIType.MIT,
                            fix=fix,
                            fixes=[fix],  # Single fix for PER STREAM
                            destinations=dest_list,
                            origins=origin_list,
                            traffic_direction=traffic_direction,
                            traffic_filter=traffic_filter,
                            value=value,
                            unit='nm',
                            provider=provider,
                            requestor=requestor,
                            is_multiple=is_multiple,
                            start_utc=start_time or event_start,
                            end_utc=end_time or event_end,
                            issued_utc=issued_utc,
                            cancelled_utc=cancelled_utc,
                            modifier=modifier,
                            notes=f'Part of multi-fix entry: {fix_str}'
                        )
                        tmis.append(tmi)
                    # Skip to next line since we added multiple TMIs
                    continue
                else:
                    # For ALL fix or single fix entries
                    fixes = [fix_str] if fix_str not in ['ALL', 'ANY'] else []

                    tmi = TMI(
                        tmi_id=f'MIT_{fix_str}_{airport_str}',
                        tmi_type=TMIType.MIT,
                        fix=fix_str if fix_str not in ['ALL', 'ANY'] else None,
                        fixes=fixes,
                        destinations=dest_list,
                        origins=origin_list,
                        traffic_direction=traffic_direction,
                        traffic_filter=traffic_filter,
                        value=value,
                        unit='nm',
                        provider=provider,
                        requestor=requestor,
                        is_multiple=is_multiple,
                        start_utc=start_time or event_start,
                        end_utc=end_time or event_end,
                        issued_utc=issued_utc,
                        cancelled_utc=cancelled_utc,
                        modifier=modifier
                    )

        # Parse MINIT restriction
        # Similar patterns to MIT but with time-based spacing
        elif line_type == 'minit':
            # Parse NTML timestamp prefix as issued_utc
            issued_utc = parse_ntml_timestamp(line, event_date)

            clean_line = re.sub(r'^\d{2}/\d{4}\s+', '', line).strip()

            # Enhanced pattern to capture arrivals/departures and multi-airport specs
            minit_match = re.match(
                r'([A-Z0-9,\s]+?)(?:\s+(arrivals?|departures?|arvls?|deps?|depts?))?\s+via\s+(\S+)\s+(\d+)\s*MINIT\b',
                clean_line, re.IGNORECASE
            )
            if minit_match:
                airport_str = minit_match.group(1).strip().upper()
                direction_word = minit_match.group(2)  # May be None
                fix_str = minit_match.group(3).upper()
                value = int(minit_match.group(4))
                start_time, end_time = parse_ntml_time_range(line, event_date)
                requestor, provider, is_multiple = parse_facilities(line)

                # Parse modifier
                modifier = parse_mit_modifier(line)

                # Parse traffic filter (TYPE, SPD, ALT, EXCL)
                traffic_filter = parse_traffic_filter(line)

                # Parse traffic direction
                traffic_direction = parse_traffic_direction(line)

                # Parse destinations/origins from airport_str
                if ',' in airport_str:
                    airports = [a.strip() for a in airport_str.split(',') if a.strip()]
                else:
                    airports = [airport_str] if airport_str not in ['ALL', 'ANY'] else destinations

                # Determine if these are destinations or origins based on direction
                if traffic_direction == TrafficDirection.DEPARTURES:
                    dest_list = destinations
                    origin_list = airports
                else:
                    dest_list = airports
                    origin_list = []

                # Handle multi-fix for PER STREAM
                if '/' in fix_str and modifier in [MITModifier.PER_STREAM, MITModifier.PER_FIX, MITModifier.EACH]:
                    fixes = [f.strip() for f in fix_str.split('/') if f.strip()]
                    for fix in fixes:
                        tmi = TMI(
                            tmi_id=f'MINIT_{fix}_{airport_str}',
                            tmi_type=TMIType.MINIT,
                            fix=fix,
                            fixes=[fix],
                            destinations=dest_list,
                            origins=origin_list,
                            traffic_direction=traffic_direction,
                            traffic_filter=traffic_filter,
                            value=value,
                            unit='min',
                            provider=provider,
                            requestor=requestor,
                            is_multiple=is_multiple,
                            start_utc=start_time or event_start,
                            end_utc=end_time or event_end,
                            issued_utc=issued_utc,
                            cancelled_utc=cancelled_utc,
                            modifier=modifier,
                            notes=f'Part of multi-fix entry: {fix_str}'
                        )
                        tmis.append(tmi)
                    continue
                else:
                    tmi = TMI(
                        tmi_id=f'MINIT_{fix_str}_{airport_str}',
                        tmi_type=TMIType.MINIT,
                        fix=fix_str if fix_str not in ['ALL', 'ANY'] else None,
                        fixes=[fix_str] if fix_str not in ['ALL', 'ANY'] else [],
                        destinations=dest_list,
                        origins=origin_list,
                        traffic_direction=traffic_direction,
                        traffic_filter=traffic_filter,
                        value=value,
                        unit='min',
                        provider=provider,
                        requestor=requestor,
                        is_multiple=is_multiple,
                        start_utc=start_time or event_start,
                        end_utc=end_time or event_end,
                        issued_utc=issued_utc,
                        cancelled_utc=cancelled_utc,
                        modifier=modifier
                    )

        # Parse CFR restriction
        # Formats:
        # - "JFK via ALL CFR VOLUME:VOLUME 0000-0400 ZDC:PCT"
        # - "30/2353 JFK, LGA, BOS via CLT Departures CFR VOLUME:VOLUME 0000-0400 ZDC:ZTL"
        # - "BOS via ALL CFR VOLUME:VOLUME 0000-0400 ZDC:PCT"
        elif line_type == 'cfr':
            clean_line = re.sub(r'^\d{2}/\d{4}\s+', '', line).strip()

            # Try enhanced pattern first: "JFK, LGA, BOS via CLT Departures CFR"
            # This captures comma-separated destinations and optional "X Departures" origin
            cfr_match = re.match(
                r'([A-Z0-9,\s]+)\s+via\s+(.+?)\s+CFR\b',
                clean_line, re.IGNORECASE
            )
            if cfr_match:
                dest_str = cfr_match.group(1).strip().upper()
                via_part = cfr_match.group(2).strip().upper()

                start_time, end_time = parse_ntml_time_range(line, event_date)
                requestor, provider, is_multiple = parse_facilities(line)

                # Parse destinations (may be comma-separated)
                if ',' in dest_str:
                    dests = [d.strip() for d in dest_str.split(',') if d.strip()]
                else:
                    dests = [dest_str] if dest_str not in ['ALL', 'ANY'] else destinations

                # Parse fix/origin from via part
                # Check for "X Departures" format (indicates origin)
                fix = None
                origins = []
                departures_match = re.match(r'([A-Z]{3})\s+DEPARTURES', via_part, re.IGNORECASE)
                if departures_match:
                    # "CLT Departures" means origin is CLT
                    origin_code = departures_match.group(1)
                    origins = [origin_code]
                    fix = 'ALL'  # CFR applies to all fixes from this origin
                elif via_part in ['ALL', 'ANY']:
                    fix = None  # No specific fix
                else:
                    fix = via_part

                tmi = TMI(
                    tmi_id=f'CFR_{fix or "ALL"}_{",".join(dests[:2])}',
                    tmi_type=TMIType.CFR,
                    fix=fix,
                    destinations=dests,
                    origins=origins,
                    provider=provider,
                    requestor=requestor,
                    is_multiple=is_multiple,
                    start_utc=start_time or event_start,
                    end_utc=end_time or event_end,
                    cancelled_utc=cancelled_utc
                )

        # Fallback: Try old-style patterns for backwards compatibility
        if not tmi and line_type == 'unknown':
            # Ground Stop pattern: "DEST GS (SCOPE) 0230Z-0315Z issued 0244Z"
            gs_match = re.match(
                r'(\w+)\s+GS\s*\(([^)]+)\)\s*(\d{4})Z?\s*-\s*(\d{4})Z?(?:\s*issued\s*(\d{4})Z?)?',
                line, re.IGNORECASE
            )
            if gs_match:
                dest = gs_match.group(1).upper()
                scope = gs_match.group(2).strip()
                start_time = parse_time(gs_match.group(3), event_date)
                end_time = parse_time(gs_match.group(4), event_date)
                issued_time = parse_time(gs_match.group(5), event_date) if gs_match.group(5) else start_time

                tmi = TMI(
                    tmi_id=f'GS_{scope}_{dest}_ALL',
                    tmi_type=TMIType.GS,
                    destinations=[dest] if dest not in ['ALL', 'ANY'] else destinations,
                    origins=[],
                    provider=scope,
                    requestor=dest,
                    start_utc=start_time,
                    end_utc=end_time,
                    issued_utc=issued_time,
                    cancelled_utc=cancelled_utc,
                    reason=f'Ground Stop from {scope}'
                )

            # Old MIT/MINIT pattern: "DEST via FIX 20MIT REQ:PROV 2359Z-0400Z"
            if not tmi:
                mit_match = re.match(
                    r'(\w+)\s+via\s+(\w+)\s+(\d+)\s*(MIT|MINIT)\s*(?:AS\s+ONE\s+)?',
                    line, re.IGNORECASE
                )
                if mit_match:
                    dest = mit_match.group(1).upper()
                    fix = mit_match.group(2).upper()
                    value = int(mit_match.group(3))
                    tmi_type = TMIType.MIT if mit_match.group(4).upper() == 'MIT' else TMIType.MINIT
                    start_time, end_time = parse_ntml_time_range(line, event_date)
                    requestor, provider, is_multiple = parse_facilities(line)

                    tmi = TMI(
                        tmi_id=f'{tmi_type.value}_{fix}_{fix}',
                        tmi_type=tmi_type,
                        fix=fix,
                        destinations=[dest] if dest not in ['ALL', 'ANY'] else destinations,
                        value=value,
                        unit='nm' if tmi_type == TMIType.MIT else 'min',
                        provider=provider,
                        requestor=requestor,
                        is_multiple=is_multiple,
                        start_utc=start_time,
                        end_utc=end_time,
                        cancelled_utc=cancelled_utc
                    )

            # Old APREQ/CFR pattern: "DEST via FIX CFR 2359-0400 REQ:PROV"
            if not tmi:
                apreq_match = re.match(
                    r'(\w+)\s+via\s+(\w+)\s+(APREQ|CFR)\s*(\d{4})Z?\s*-\s*(\d{4})Z?',
                    line, re.IGNORECASE
                )
                if apreq_match:
                    dest = apreq_match.group(1).upper()
                    fix = apreq_match.group(2).upper()
                    tmi_type = TMIType.APREQ if apreq_match.group(3).upper() == 'APREQ' else TMIType.CFR
                    start_time = parse_time(apreq_match.group(4), event_date)
                    end_time = parse_time(apreq_match.group(5), event_date)
                    requestor, provider, is_multiple = parse_facilities(line)

                    tmi = TMI(
                        tmi_id=f'{tmi_type.value}_{fix}',
                        tmi_type=tmi_type,
                        fix=fix if fix not in ['ALL', 'ANY'] else None,
                        destinations=[dest] if dest not in ['ALL', 'ANY'] else destinations,
                        provider=provider,
                        requestor=requestor,
                        is_multiple=is_multiple,
                        start_utc=start_time,
                        end_utc=end_time,
                        cancelled_utc=cancelled_utc
                    )

        if tmi:
            tmis.append(tmi)
            logger.debug(f"Parsed TMI: {tmi.tmi_type.value} via {tmi.fix} for {tmi.destinations}")
        elif line_type == 'unknown':
            skipped_lines.append(('unparsed', line))

        i += 1

    # Log summary of skipped lines
    if skipped_lines:
        type_counts = {}
        for line_type, _ in skipped_lines:
            type_counts[line_type] = type_counts.get(line_type, 0) + 1
        logger.info(f"Skipped {len(skipped_lines)} non-TMI lines: {type_counts}")

    # Link TMI amendments (without cancellations - use parse_ntml_full for full processing)
    tmis = link_tmi_amendments(tmis)

    logger.info(f"Parsed {len(tmis)} TMIs from NTML text")
    return tmis


@dataclass
class NTMLParseResult:
    """Complete result from parsing NTML text"""
    tmis: List[TMI]
    delays: List[DelayEntry]
    airport_configs: List[AirportConfig]
    cancellations: List[CancelEntry]
    unparsed_lines: List[str]


def parse_ntml_full(ntml_text: str, event_start: datetime, event_end: datetime, destinations: List[str]) -> NTMLParseResult:
    """
    Comprehensive NTML parser that extracts all entry types.

    Returns:
        NTMLParseResult containing:
        - tmis: List of TMI restrictions
        - delays: List of E/D, A/D, D/D entries
        - airport_configs: List of airport configuration updates
        - cancellations: List of CANCEL entries
        - unparsed_lines: Lines that couldn't be classified
    """
    # Start with TMIs from the existing parser
    tmis = parse_ntml_to_tmis(ntml_text, event_start, event_end, destinations)

    delays = []
    airport_configs = []
    cancellations = []
    unparsed_lines = []

    # Clean and process lines
    cleaned_text = clean_discord_text(ntml_text)
    lines = cleaned_text.strip().split('\n')
    event_date = event_start.date()

    def parse_time_from_ntml(time_str: str, base_date) -> Optional[datetime]:
        """Parse HHMM time string"""
        if not time_str:
            return None
        time_str = time_str.replace(':', '').replace('Z', '').strip()
        if len(time_str) == 4:
            try:
                hour = int(time_str[:2])
                minute = int(time_str[2:])
                result = datetime(base_date.year, base_date.month, base_date.day, hour, minute)
                if result < event_start - timedelta(hours=2):
                    result = result + timedelta(days=1)
                return result
            except ValueError:
                return None
        return None

    def parse_ntml_timestamp(line: str, base_date) -> Optional[datetime]:
        """Parse the DD/HHMM timestamp from start of NTML line"""
        match = re.match(r'^(\d{2})/(\d{4})\s+', line)
        if match:
            day = int(match.group(1))
            time_str = match.group(2)
            try:
                hour = int(time_str[:2])
                minute = int(time_str[2:])
                # Use event date's month/year, adjust day
                result = datetime(base_date.year, base_date.month, day, hour, minute)
                # Handle month rollover
                if result < event_start - timedelta(days=2):
                    # Probably next month
                    if base_date.month == 12:
                        result = result.replace(year=base_date.year + 1, month=1)
                    else:
                        result = result.replace(month=base_date.month + 1)
                return result
            except ValueError:
                return None
        return None

    for line in lines:
        line = line.strip()
        if not line:
            continue

        line_type, _ = classify_line(line)
        timestamp = parse_ntml_timestamp(line, event_date)

        # Parse E/D (En Route Delays)
        # Format: "31/0127    ZBW E/D for BOS +Holding/0147/2 ACFT  FIX/NAVAID:AJJAY VOLUME:VOLUME"
        # Can also be: "ZBW E/D for BOS +30/0147" (30 min delay starting at 0147)
        # Or: "ZBW E/D for BOS -Holding" (no longer holding)
        if line_type == 'ed_delay':
            ed_match = re.search(r'(\w+)\s+E/D\s+for\s+(\w+)', line, re.IGNORECASE)
            if ed_match:
                facility = ed_match.group(1).upper()
                airport = ed_match.group(2).upper()

                # Determine holding status
                holding_status = HoldingStatus.NONE
                if re.search(r'\+Holding\b', line, re.IGNORECASE):
                    holding_status = HoldingStatus.HOLDING
                elif re.search(r'-Holding\b', line, re.IGNORECASE):
                    holding_status = HoldingStatus.NOT_HOLDING

                # Extract holding fix from "FIX/NAVAID:AJJAY"
                holding_fix = ''
                fix_match = re.search(r'FIX/NAVAID:(\w+)', line, re.IGNORECASE)
                if fix_match:
                    holding_fix = fix_match.group(1).upper()

                # Extract aircraft count from "2 ACFT"
                aircraft_holding = 0
                acft_match = re.search(r'(\d+)\s*ACFT', line, re.IGNORECASE)
                if acft_match:
                    aircraft_holding = int(acft_match.group(1))

                # Extract delay minutes and start time
                # Format: +Holding/0147 or +30/0147
                delay_minutes = 0
                delay_start = None
                delay_match = re.search(r'\+(\w+)/(\d{4})', line)
                if delay_match:
                    value = delay_match.group(1)
                    if value.isdigit():
                        delay_minutes = int(value)
                    delay_start = parse_time_from_ntml(delay_match.group(2), event_date)

                # Determine delay trend (would need previous entries to be accurate)
                delay_trend = DelayTrend.UNKNOWN

                delays.append(DelayEntry(
                    delay_type=DelayType.ED,
                    airport=airport,
                    facility=facility,
                    timestamp_utc=timestamp,
                    delay_minutes=delay_minutes,
                    delay_trend=delay_trend,
                    delay_start_utc=delay_start,
                    holding_status=holding_status,
                    holding_fix=holding_fix,
                    aircraft_holding=aircraft_holding,
                    reason='VOLUME' if 'VOLUME' in line.upper() else '',
                    raw_line=line
                ))

        # Parse A/D (Arrival Delays) - same structure as E/D
        elif line_type == 'ad_delay':
            ad_match = re.search(r'(\w+)\s+A/D\s+for\s+(\w+)', line, re.IGNORECASE)
            if ad_match:
                facility = ad_match.group(1).upper()
                airport = ad_match.group(2).upper()

                # Holding status
                holding_status = HoldingStatus.NONE
                if re.search(r'\+Holding\b', line, re.IGNORECASE):
                    holding_status = HoldingStatus.HOLDING
                elif re.search(r'-Holding\b', line, re.IGNORECASE):
                    holding_status = HoldingStatus.NOT_HOLDING

                # Holding fix
                holding_fix = ''
                fix_match = re.search(r'FIX/NAVAID:(\w+)', line, re.IGNORECASE)
                if fix_match:
                    holding_fix = fix_match.group(1).upper()

                # Aircraft count
                aircraft_holding = 0
                acft_match = re.search(r'(\d+)\s*ACFT', line, re.IGNORECASE)
                if acft_match:
                    aircraft_holding = int(acft_match.group(1))

                # Delay amount and start
                delay_minutes = 0
                delay_start = None
                delay_match = re.search(r'\+(\w+)/(\d{4})', line)
                if delay_match:
                    value = delay_match.group(1)
                    if value.isdigit():
                        delay_minutes = int(value)
                    delay_start = parse_time_from_ntml(delay_match.group(2), event_date)
                else:
                    # Try simple +NN format
                    simple_match = re.search(r'\+(\d+)\b', line)
                    if simple_match:
                        delay_minutes = int(simple_match.group(1))

                delays.append(DelayEntry(
                    delay_type=DelayType.AD,
                    airport=airport,
                    facility=facility,
                    timestamp_utc=timestamp,
                    delay_minutes=delay_minutes,
                    delay_trend=DelayTrend.UNKNOWN,
                    delay_start_utc=delay_start,
                    holding_status=holding_status,
                    holding_fix=holding_fix,
                    aircraft_holding=aircraft_holding,
                    reason='VOLUME' if 'VOLUME' in line.upper() else '',
                    raw_line=line
                ))

        # Parse D/D (Departure Delays)
        # Format: "31/0153    D/D from BOS +35/0153  VOLUME:VOLUME"
        # +35 means 35-minute delays, /0153 is when delays started
        elif line_type == 'dd_delay':
            dd_match = re.search(r'D/D\s+from\s+(\w+)', line, re.IGNORECASE)
            if dd_match:
                airport = dd_match.group(1).upper()

                # Extract delay amount and start time
                # Format: +35/0153 (35 min delay starting at 0153)
                delay_minutes = 0
                delay_start = None
                delay_match = re.search(r'\+(\d+)/(\d{4})', line)
                if delay_match:
                    delay_minutes = int(delay_match.group(1))
                    delay_start = parse_time_from_ntml(delay_match.group(2), event_date)
                else:
                    # Try simple +NN format
                    simple_match = re.search(r'\+(\d+)\b', line)
                    if simple_match:
                        delay_minutes = int(simple_match.group(1))

                # D/D can rarely have holding (plane departs then holds)
                holding_status = HoldingStatus.NONE
                if re.search(r'\+Holding\b', line, re.IGNORECASE):
                    holding_status = HoldingStatus.HOLDING
                elif re.search(r'-Holding\b', line, re.IGNORECASE):
                    holding_status = HoldingStatus.NOT_HOLDING

                delays.append(DelayEntry(
                    delay_type=DelayType.DD,
                    airport=airport,
                    facility='',  # D/D typically doesn't specify facility
                    timestamp_utc=timestamp,
                    delay_minutes=delay_minutes,
                    delay_trend=DelayTrend.UNKNOWN,
                    delay_start_utc=delay_start,
                    holding_status=holding_status,
                    holding_fix='',
                    aircraft_holding=0,
                    reason='VOLUME' if 'VOLUME' in line.upper() else '',
                    raw_line=line
                ))

        # Parse Airport Configuration
        # Format: "30/2328    BOS    VMC    ARR:27/32 DEP:33L    AAR:40 ADR:40"
        elif line_type == 'airport_config':
            # Extract airport code (3-letter code following timestamp)
            airport_match = re.search(r'^\d{2}/\d{4}\s+(\w{3})\s+', line)
            if not airport_match:
                # Try without timestamp
                airport_match = re.match(r'^(\w{3})\s+', line)

            if airport_match:
                airport = airport_match.group(1).upper()

                # Extract conditions (VMC/IMC)
                conditions = 'VMC' if 'VMC' in line.upper() else ('IMC' if 'IMC' in line.upper() else '')

                # Extract arrival runways (ARR:27/32)
                arr_runways = []
                arr_match = re.search(r'ARR:([^\s]+)', line, re.IGNORECASE)
                if arr_match:
                    arr_runways = [r.strip() for r in arr_match.group(1).split('/')]

                # Extract departure runways (DEP:33L)
                dep_runways = []
                dep_match = re.search(r'DEP:([^\s]+)', line, re.IGNORECASE)
                if dep_match:
                    dep_runways = [r.strip() for r in dep_match.group(1).split('/')]

                # Extract AAR and ADR
                aar = 0
                adr = 0
                aar_match = re.search(r'AAR:(\d+)', line, re.IGNORECASE)
                if aar_match:
                    aar = int(aar_match.group(1))
                adr_match = re.search(r'ADR:(\d+)', line, re.IGNORECASE)
                if adr_match:
                    adr = int(adr_match.group(1))

                airport_configs.append(AirportConfig(
                    airport=airport,
                    timestamp_utc=timestamp,
                    conditions=conditions,
                    arrival_runways=arr_runways,
                    departure_runways=dep_runways,
                    aar=aar,
                    adr=adr
                ))

        # Parse Cancellations
        # Various formats:
        # - "31/0326    BOS via RBV CANCEL RESTR ZNY:ZDC"
        # - "BOS via ALL CANCEL RESTR ZBW:ZNY,ZOB"
        # - "JFK, LGA, BOS via CLT Departures CFR VOLUME:VOLUME CANCEL RESTR ZDC:ZTL"
        elif line_type == 'cancel':
            # Remove timestamp prefix if present
            clean_line = re.sub(r'^\d{2}/\d{4}\s+', '', line).strip()

            # Try to extract destination and fix
            dest = ''
            fix = ''

            # Pattern 1: "DEST via FIX CANCEL"
            via_match = re.search(r'(\S+)\s+via\s+(\S+).*CANCEL', clean_line, re.IGNORECASE)
            if via_match:
                dest = via_match.group(1).upper()
                fix = via_match.group(2).upper()

            # Extract facilities (requestor:provider) with optional (MULTIPLE) suffix
            requestor = ''
            provider = ''
            is_multiple = False

            # Check for (MULTIPLE) suffix
            if re.search(r'\(MULTIPLE\)\s*$', line.upper()):
                is_multiple = True
                clean_fac_line = re.sub(r'\(MULTIPLE\)\s*$', '', line.upper()).strip()
            else:
                clean_fac_line = line.upper()

            facilities_match = re.search(r'([A-Z0-9,]+):([A-Z0-9,]+)\s*$', clean_fac_line)
            if facilities_match:
                requestor = facilities_match.group(1)
                provider = facilities_match.group(2)

            cancellations.append(CancelEntry(
                timestamp_utc=timestamp,
                destination=dest,
                fix=fix,
                requestor=requestor,
                provider=provider,
                is_multiple=is_multiple
            ))

        # Track unparsed lines
        elif line_type == 'unknown':
            unparsed_lines.append(line)

    # Detect delay trends by comparing sequential entries
    delays = detect_delay_trends(delays)

    # Link TMI amendments and apply cancellations
    tmis = link_tmi_amendments(tmis, cancellations)

    # Log summary
    logger.info(f"NTML parse complete: {len(tmis)} TMIs, {len(delays)} delays, "
                f"{len(airport_configs)} configs, {len(cancellations)} cancellations, "
                f"{len(unparsed_lines)} unparsed")

    return NTMLParseResult(
        tmis=tmis,
        delays=delays,
        airport_configs=airport_configs,
        cancellations=cancellations,
        unparsed_lines=unparsed_lines
    )


def link_tmi_amendments(tmis: List[TMI], cancellations: List['CancelEntry'] = None) -> List[TMI]:
    """
    Link TMI amendments to track value changes over time.

    When the same dest/fix combination has multiple NTML entries with different
    issued times, the later ones supersede the earlier ones. This function:
    1. Groups TMIs by (destinations, fix, tmi_type)
    2. Sorts each group by issued_utc (oldest first)
    3. Links earlier TMIs to later ones via supersedes_tmi_id/superseded_by_tmi_id
    4. Adjusts effective end times - superseded TMI ends when successor is issued
    5. Applies cancellations from CancelEntry list

    Example: BOS via RBV 25MIT @ 21:00, then 20MIT @ 22:30, then 15MIT @ 23:00
    Result:
    - TMI1 (25MIT): effective 21:00-22:30, superseded_by=TMI2
    - TMI2 (20MIT): effective 22:30-23:00, supersedes=TMI1, superseded_by=TMI3
    - TMI3 (15MIT): effective 23:00-end, supersedes=TMI2

    Args:
        tmis: List of parsed TMI objects
        cancellations: Optional list of CancelEntry objects

    Returns:
        Updated list of TMIs with amendment links
    """
    if not tmis:
        return tmis

    from collections import defaultdict

    # Group TMIs by destination set + fix + type
    # Use frozenset of destinations for hashable key
    def make_group_key(tmi: TMI) -> tuple:
        """Create grouping key for TMI amendments"""
        dest_key = frozenset(d.upper() for d in (tmi.destinations or []))
        fix_key = (tmi.fix or 'ALL').upper()
        type_key = tmi.tmi_type.value if tmi.tmi_type else 'MIT'
        return (dest_key, fix_key, type_key)

    groups: dict[tuple, List[TMI]] = defaultdict(list)
    for tmi in tmis:
        key = make_group_key(tmi)
        groups[key].append(tmi)

    # Process each group
    linked_tmis = []
    for key, group in groups.items():
        # Filter to only those with issued_utc for proper ordering
        with_issued = [t for t in group if t.issued_utc is not None]
        without_issued = [t for t in group if t.issued_utc is None]

        if len(with_issued) <= 1:
            # No amendments to link - just add all TMIs
            linked_tmis.extend(group)
            continue

        # Sort by issued_utc (oldest first)
        with_issued.sort(key=lambda t: t.issued_utc)

        # Link each to the next
        for i, tmi in enumerate(with_issued):
            if i > 0:
                # This TMI supersedes the previous one
                prev_tmi = with_issued[i - 1]
                tmi.supersedes_tmi_id = prev_tmi.tmi_id
                prev_tmi.superseded_by_tmi_id = tmi.tmi_id

                # Adjust previous TMI's effective end time
                # It ends when this one was issued
                prev_tmi.end_utc = tmi.issued_utc

                # Log the amendment
                logger.debug(
                    f"TMI amendment: {prev_tmi.fix} {prev_tmi.value}{prev_tmi.unit} "
                    f"({prev_tmi.issued_utc.strftime('%H:%M') if prev_tmi.issued_utc else '?'}) -> "
                    f"{tmi.value}{tmi.unit} ({tmi.issued_utc.strftime('%H:%M') if tmi.issued_utc else '?'})"
                )

        linked_tmis.extend(with_issued)
        linked_tmis.extend(without_issued)

    # Apply cancellations
    if cancellations:
        for cancel in cancellations:
            if not cancel.destination or not cancel.fix:
                continue

            # Find matching TMI(s) to mark as cancelled
            for tmi in linked_tmis:
                # Check if this TMI matches the cancellation
                dest_match = (
                    cancel.destination.upper() in [d.upper() for d in (tmi.destinations or [])] or
                    cancel.destination.upper() == 'ALL'
                )
                fix_match = (
                    (tmi.fix or '').upper() == cancel.fix.upper() or
                    cancel.fix.upper() == 'ALL'
                )

                if dest_match and fix_match:
                    # Only cancel if this TMI hasn't been superseded by another
                    # (cancellation applies to the active TMI)
                    if not tmi.superseded_by_tmi_id:
                        tmi.cancelled_utc = cancel.timestamp_utc
                        if cancel.timestamp_utc and tmi.end_utc:
                            # Cancellation ends the TMI at the cancel time
                            if cancel.timestamp_utc < tmi.end_utc:
                                tmi.end_utc = cancel.timestamp_utc
                        logger.debug(
                            f"TMI cancelled: {tmi.fix} {tmi.value}{tmi.unit} "
                            f"at {cancel.timestamp_utc.strftime('%H:%M') if cancel.timestamp_utc else '?'}"
                        )

    # Log summary
    amendment_count = sum(1 for t in linked_tmis if t.supersedes_tmi_id)
    cancel_count = sum(1 for t in linked_tmis if t.cancelled_utc)
    if amendment_count or cancel_count:
        logger.info(f"TMI amendments linked: {amendment_count} supersedes, {cancel_count} cancellations")

    return linked_tmis


def get_effective_tmi_value(tmis: List[TMI], dest: str, fix: str,
                            tmi_type: TMIType, at_time: datetime) -> Optional[TMI]:
    """
    Get the effective TMI value at a specific time.

    When multiple TMIs exist for the same dest/fix due to amendments,
    this returns the one that was in effect at the given time.

    Args:
        tmis: List of TMI objects (should be already linked via link_tmi_amendments)
        dest: Destination airport code
        fix: Control fix
        tmi_type: Type of TMI (MIT, MINIT, etc.)
        at_time: Time to check

    Returns:
        The TMI that was in effect at at_time, or None if no matching TMI
    """
    matching = []

    for tmi in tmis:
        # Check type match
        if tmi.tmi_type != tmi_type:
            continue

        # Check fix match
        if (tmi.fix or 'ALL').upper() != fix.upper():
            continue

        # Check destination match
        if dest.upper() not in [d.upper() for d in (tmi.destinations or [])]:
            continue

        # Check if cancelled before at_time
        if tmi.cancelled_utc and tmi.cancelled_utc <= at_time:
            continue

        # Check time range
        start = tmi.start_utc or datetime.min
        end = tmi.end_utc or datetime.max
        if start <= at_time <= end:
            matching.append(tmi)

    if not matching:
        return None

    # If multiple match (shouldn't happen if properly linked), return most recently issued
    matching.sort(key=lambda t: t.issued_utc or datetime.min, reverse=True)
    return matching[0]


def detect_delay_trends(delays: List[DelayEntry]) -> List[DelayEntry]:
    """
    Detect delay trends by comparing sequential entries for the same airport/type.

    Trend logic:
    - INCREASING: Current delay_minutes > previous delay_minutes, or +Holding entered
    - DECREASING: Current delay_minutes < previous delay_minutes, or -Holding exited
    - STEADY: Same delay_minutes as previous entry
    - UNKNOWN: First entry for this airport/type, or can't determine

    Entries are processed in timestamp order (oldest first).
    """
    if not delays:
        return delays

    # Sort by timestamp (oldest first) to process chronologically
    sorted_delays = sorted(delays, key=lambda d: d.timestamp_utc or datetime.min)

    # Track last entry per airport/type combination
    last_entry: dict[tuple[str, DelayType], DelayEntry] = {}

    updated_delays = []

    for delay in sorted_delays:
        key = (delay.airport, delay.delay_type)
        prev = last_entry.get(key)

        # Determine trend
        trend = DelayTrend.UNKNOWN

        if prev is None:
            # First entry for this airport/type
            if delay.holding_status == HoldingStatus.HOLDING:
                trend = DelayTrend.INCREASING  # Entering holding = delays starting
            elif delay.delay_minutes > 0:
                trend = DelayTrend.INCREASING  # First delay report = delays starting
            # else UNKNOWN
        else:
            # Compare to previous entry
            prev_minutes = prev.delay_minutes
            curr_minutes = delay.delay_minutes

            # Handle holding transitions
            if delay.holding_status == HoldingStatus.HOLDING and prev.holding_status != HoldingStatus.HOLDING:
                # Entered holding
                trend = DelayTrend.INCREASING
            elif delay.holding_status == HoldingStatus.NOT_HOLDING and prev.holding_status == HoldingStatus.HOLDING:
                # Exited holding
                trend = DelayTrend.DECREASING
            elif delay.holding_status == HoldingStatus.HOLDING and prev.holding_status == HoldingStatus.HOLDING:
                # Still in holding - compare aircraft count or just mark steady
                if delay.aircraft_holding > prev.aircraft_holding:
                    trend = DelayTrend.INCREASING
                elif delay.aircraft_holding < prev.aircraft_holding:
                    trend = DelayTrend.DECREASING
                else:
                    trend = DelayTrend.STEADY
            else:
                # Compare delay minutes
                if curr_minutes > prev_minutes:
                    trend = DelayTrend.INCREASING
                elif curr_minutes < prev_minutes:
                    trend = DelayTrend.DECREASING
                elif curr_minutes == prev_minutes and curr_minutes > 0:
                    trend = DelayTrend.STEADY
                # If both are 0, keep UNKNOWN

        # Create updated entry with trend
        updated_delay = DelayEntry(
            delay_type=delay.delay_type,
            airport=delay.airport,
            facility=delay.facility,
            timestamp_utc=delay.timestamp_utc,
            delay_minutes=delay.delay_minutes,
            delay_trend=trend,
            delay_start_utc=delay.delay_start_utc,
            holding_status=delay.holding_status,
            holding_fix=delay.holding_fix,
            aircraft_holding=delay.aircraft_holding,
            reason=delay.reason,
            raw_line=delay.raw_line
        )

        updated_delays.append(updated_delay)
        last_entry[key] = updated_delay

    # Log trend summary
    trend_counts = {}
    for d in updated_delays:
        trend_counts[d.delay_trend.value] = trend_counts.get(d.delay_trend.value, 0) + 1
    if trend_counts:
        logger.debug(f"Delay trends detected: {trend_counts}")

    return updated_delays
