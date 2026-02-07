"""
TMI Compliance Analyzer - NTML Parser
======================================

Parses NTML (National Traffic Management Log) text into TMI objects.
Handles raw Discord-pasted content with usernames, timestamps, and Unicode formatting.
"""

import re
import logging
from dataclasses import dataclass, field
from datetime import datetime, timedelta
from typing import List, Tuple, Optional

from .models import (
    TMI, TMIType, MITModifier, DelayEntry, DelayType, DelayTrend, HoldingStatus,
    AirportConfig, CancelEntry, TrafficDirection, TrafficFilter, AircraftType, AltitudeFilter,
    ComparisonOp, ThruType, ThruFilter, ScopeLogic, create_thru_filter, SkippedLine,
    GSProgram, GSAdvisory, RerouteProgram, RerouteAdvisory, RouteEntry
)

logger = logging.getLogger(__name__)


@dataclass
class ParseResult:
    """
    Result of parsing NTML text.

    Contains both successfully parsed TMIs and lines that couldn't be parsed.
    Skipped lines can be displayed to users for manual definition.
    Also contains GS and Reroute program chains built from ADVZY blocks.
    """
    tmis: List[TMI]
    skipped_lines: List[SkippedLine]
    gs_programs: List[GSProgram] = field(default_factory=list)
    reroute_programs: List[RerouteProgram] = field(default_factory=list)

    def __iter__(self):
        """Allow unpacking: tmis, skipped = parse_result"""
        return iter([self.tmis, self.skipped_lines])

    def __len__(self):
        """Return number of parsed TMIs (for backward compat)"""
        return len(self.tmis)


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
        - "advzy_header": vATCSCC ADVZY header line (generic/unrecognized)
        - "advzy_gs": vATCSCC ADVZY Ground Stop header
        - "advzy_gs_cnx": vATCSCC ADVZY GS Cancellation header
        - "advzy_reroute": vATCSCC ADVZY Reroute header (ROUTE RQD, FEA FYI, etc.)
        - "advzy_reroute_cnx": vATCSCC ADVZY Reroute Cancellation header
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

    # ADVZY header detection - type-specific
    if re.match(r'^vATCSCC\s+ADVZY\s+\d+', line, re.IGNORECASE):
        upper = line.upper()
        if 'CDM GS CNX' in upper or 'GS CNX' in upper:
            return ("advzy_gs_cnx", {"line": line})
        if 'REROUTE CANCELLATION' in upper:
            return ("advzy_reroute_cnx", {"line": line})
        if 'GROUND STOP' in upper or 'CDM GROUND STOP' in upper:
            return ("advzy_gs", {"line": line})
        # Reroute types: ROUTE RQD, ROUTE RMD, FEA FYI, FCA RQD, ICR RQD, etc.
        if re.search(r'\b(ROUTE|FEA|FCA|ICR)\s+(RQD|RMD|PLN|FYI)\b', upper):
            return ("advzy_reroute", {"line": line})
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
                            destinations: List[str]) -> Tuple[Optional[TMI], Optional[GSAdvisory], int]:
    """
    Parse an ADVZY Ground Stop block starting at the given index.

    ADVZY Ground Stop format:
        vATCSCC ADVZY 001 LAS/ZLA 01/18/2026 CDM GROUND STOP
        CTL ELEMENT: LAS
        ELEMENT TYPE: APT
        ADL TIME: 0244Z
        GROUND STOP PERIOD: 18/0230Z - 18/0315Z
        CUMULATIVE PROGRAM PERIOD: 18/0230Z - 18/0315Z
        DEP FACILITIES INCLUDED: (1stTier) ZAB, ZKC, ZMP
        IMPACTING CONDITION: LOW CEILINGS
        PROBABILITY OF EXTENSION: HIGH
        FLT INCL: ALL
        PREVIOUS TOTAL, MAXIMUM, AVERAGE DELAYS: 0, 0, 0
        NEW TOTAL, MAXIMUM, AVERAGE DELAYS: 10, 5, 3
        COMMENTS: Expect extensions if weather persists

    Returns:
        Tuple of (TMI or None, GSAdvisory or None, number of lines consumed)
    """
    if start_idx >= len(lines):
        return (None, None, 0)

    header_line = lines[start_idx]

    # Verify this is a Ground Stop ADVZY
    if 'GROUND STOP' not in header_line.upper():
        return (None, None, 0)

    # Extract advisory number from header (e.g., "ADVZY 001" -> "001")
    advzy_num_match = re.search(r'ADVZY\s+(\d+)', header_line, re.IGNORECASE)
    advzy_number = advzy_num_match.group(1) if advzy_num_match else ''

    # Extract airport from header (format: "vATCSCC ADVZY 001 LAS/ZLA 01/18/2026 CDM GROUND STOP")
    header_match = re.search(r'ADVZY\s+\d+\s+([A-Z]{3})/', header_line, re.IGNORECASE)
    dest_from_header = header_match.group(1).upper() if header_match else None

    # Parse subsequent lines to extract fields
    dest = dest_from_header
    gs_start = None
    gs_end = None
    cumulative_start = None
    cumulative_end = None
    issued_time = None
    dep_facilities = []
    dep_facility_tier = ''
    prob_extension = ''
    impacting_condition = ''
    flt_incl = ''
    delay_prev = None
    delay_new = None
    comments_lines = []
    in_comments = False
    lines_consumed = 1  # Start with the header line
    raw_lines = [header_line]

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

    def parse_delay_triple(text: str) -> Optional[tuple]:
        """Parse 'X, Y, Z' delay format into (total, max, avg) tuple"""
        m = re.search(r'(\d+)\s*,\s*(\d+)\s*,\s*(\d+)', text)
        if m:
            return (int(m.group(1)), int(m.group(2)), int(m.group(3)))
        return None

    # Scan subsequent lines for ADVZY fields
    for i in range(start_idx + 1, min(start_idx + 25, len(lines))):  # Look at most 25 lines ahead
        line = lines[i].strip()
        if not line:
            lines_consumed += 1
            if in_comments:
                # Empty line in comments section - end of comments
                in_comments = False
            continue

        # Check if we've hit another TMI or ADVZY (end of this block)
        if re.match(r'^\d{2}/\d{4}\s+', line) or re.match(r'^vATCSCC\s+ADVZY', line, re.IGNORECASE):
            break

        lines_consumed += 1
        raw_lines.append(line)

        upper_line = line.upper()

        # If we're accumulating comments, check if this is a new field or continuation
        if in_comments:
            # Check if this line starts a new known field
            if re.match(r'^(CTL|ELEMENT|ADL|GROUND|CUMULATIVE|DEP|IMPACTING|PROBABILITY|FLT|PREVIOUS|NEW\s+TOTAL|COMMENTS)\s', upper_line, re.IGNORECASE):
                in_comments = False
                # Fall through to field parsing below
            else:
                comments_lines.append(line)
                continue

        # CTL ELEMENT: LAS
        ctl_match = re.match(r'^CTL\s+ELEMENT:\s*(\w+)', line, re.IGNORECASE)
        if ctl_match:
            dest = ctl_match.group(1).upper()
            continue

        # ELEMENT TYPE: APT (skip, just informational)
        if upper_line.startswith('ELEMENT TYPE:'):
            continue

        # ADL TIME: 0244Z (issued time)
        adl_match = re.match(r'^ADL\s+TIME:\s*(\d{4})Z?', line, re.IGNORECASE)
        if adl_match:
            issued_time = parse_time(adl_match.group(1))
            continue

        # GROUND STOP PERIOD: 18/0230Z - 18/0315Z
        # Handle various dash types (- en-dash em-dash)
        gs_period_match = re.search(r'GROUND\s+STOP\s+PERIOD:\s*\d{2}/(\d{4})Z?\s*[-\u2013\u2014]\s*\d{2}/(\d{4})Z?',
                                    line, re.IGNORECASE)
        if gs_period_match:
            gs_start = parse_time(gs_period_match.group(1))
            gs_end = parse_time(gs_period_match.group(2))
            continue

        # CUMULATIVE PROGRAM PERIOD: 18/0230Z - 18/0315Z
        cum_match = re.search(r'CUMULATIVE\s+PROGRAM\s+PERIOD:\s*\d{2}/(\d{4})Z?\s*[-\u2013\u2014]\s*\d{2}/(\d{4})Z?',
                              line, re.IGNORECASE)
        if cum_match:
            cumulative_start = parse_time(cum_match.group(1))
            cumulative_end = parse_time(cum_match.group(2))
            continue

        # DEP FACILITIES INCLUDED: (1stTier) ZAB, ZKC, ZMP
        dep_fac_match = re.match(r'^DEP\s+FACILITIES\s+INCLUDED:\s*(.*)', line, re.IGNORECASE)
        if dep_fac_match:
            fac_text = dep_fac_match.group(1).strip()
            # Extract tier from (1stTier), (2ndTier), (Manual)
            tier_match = re.search(r'\((\w+(?:Tier)?)\)', fac_text, re.IGNORECASE)
            if tier_match:
                dep_facility_tier = tier_match.group(1)
                fac_text = re.sub(r'\([^)]+\)\s*', '', fac_text).strip()
            # Parse comma-separated facility codes
            dep_facilities = [f.strip() for f in re.findall(r'[A-Z]{2,4}', fac_text.upper()) if f.strip()]
            continue

        # IMPACTING CONDITION: LOW CEILINGS
        imp_match = re.match(r'^IMPACTING\s+CONDITION:\s*(.*)', line, re.IGNORECASE)
        if imp_match:
            impacting_condition = imp_match.group(1).strip()
            continue

        # PROBABILITY OF EXTENSION: HIGH
        prob_match = re.match(r'^PROBABILITY\s+OF\s+EXTENSION:\s*(.*)', line, re.IGNORECASE)
        if prob_match:
            prob_extension = prob_match.group(1).strip()
            continue

        # FLT INCL: ALL
        flt_match = re.match(r'^FLT\s+INCL:\s*(.*)', line, re.IGNORECASE)
        if flt_match:
            flt_incl = flt_match.group(1).strip()
            continue

        # PREVIOUS TOTAL, MAXIMUM, AVERAGE DELAYS: X, Y, Z
        if 'PREVIOUS TOTAL' in upper_line and 'DELAYS' in upper_line:
            delay_prev = parse_delay_triple(line)
            continue

        # NEW TOTAL, MAXIMUM, AVERAGE DELAYS: X, Y, Z
        if 'NEW TOTAL' in upper_line and 'DELAYS' in upper_line:
            delay_new = parse_delay_triple(line)
            continue

        # COMMENTS: (start accumulating multi-line comments)
        comments_match = re.match(r'^COMMENTS:\s*(.*)', line, re.IGNORECASE)
        if comments_match:
            first_comment = comments_match.group(1).strip()
            if first_comment:
                comments_lines.append(first_comment)
            in_comments = True
            continue

    # Build raw_text
    raw_text = '\n'.join(raw_lines)
    comments_text = '\n'.join(comments_lines).strip()

    # Use first dep facility as provider for backward-compat TMI
    provider = dep_facilities[0] if dep_facilities else 'ALL'

    # Create TMI if we have enough info
    tmi = None
    gs_advisory = None

    if dest and (gs_start or gs_end):
        tmi = TMI(
            tmi_id=f'GS_ADVZY_{dest}_{advzy_number or provider}',
            tmi_type=TMIType.GS,
            destinations=[dest] if dest not in ['ALL', 'ANY'] else destinations,
            origins=[],
            provider=provider,
            requestor=dest,
            start_utc=gs_start or event_start,
            end_utc=gs_end or event_end,
            issued_utc=issued_time,
            reason=impacting_condition or 'ADVZY Ground Stop',
            raw_text=raw_text
        )

        gs_advisory = GSAdvisory(
            advzy_number=advzy_number,
            advisory_type='INITIAL',  # Default; chaining may change to EXTENSION
            adl_time=issued_time,
            gs_period_start=gs_start,
            gs_period_end=gs_end,
            cumulative_start=cumulative_start,
            cumulative_end=cumulative_end,
            dep_facilities=dep_facilities,
            dep_facility_tier=dep_facility_tier,
            delay_prev=delay_prev,
            delay_new=delay_new,
            prob_extension=prob_extension,
            impacting_condition=impacting_condition,
            flt_incl=flt_incl,
            comments=comments_text,
            raw_text=raw_text
        )

    return (tmi, gs_advisory, lines_consumed)


def parse_advzy_gs_cnx(lines: List[str], start_idx: int, event_start: datetime, event_end: datetime) -> Tuple[Optional[GSAdvisory], int]:
    """
    Parse an ADVZY GS CNX (Ground Stop Cancellation) block.

    Format:
        vATCSCC ADVZY 003 SFO/ZOA 12/07/2025 CDM GS CNX
        CTL ELEMENT: SFO
        ELEMENT TYPE: APT
        ADL TIME: 0310Z
        GS CNX PERIOD: 07/0310 - 07/0330
        COMMENTS: 15 MIT ON SFO ARRIVALS VIA ...

    Returns:
        Tuple of (GSAdvisory or None, number of lines consumed)
    """
    if start_idx >= len(lines):
        return (None, 0)

    header_line = lines[start_idx]

    # Extract advisory number
    advzy_num_match = re.search(r'ADVZY\s+(\d+)', header_line, re.IGNORECASE)
    advzy_number = advzy_num_match.group(1) if advzy_num_match else ''

    # Extract airport from header
    header_match = re.search(r'ADVZY\s+\d+\s+([A-Z]{3})/', header_line, re.IGNORECASE)
    airport = header_match.group(1).upper() if header_match else ''

    issued_time = None
    cnx_start = None
    cnx_end = None
    comments_lines = []
    in_comments = False
    lines_consumed = 1
    raw_lines = [header_line]

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

    for i in range(start_idx + 1, min(start_idx + 15, len(lines))):
        line = lines[i].strip()
        if not line:
            lines_consumed += 1
            if in_comments:
                in_comments = False
            continue

        # Check if we've hit another TMI or ADVZY
        if re.match(r'^\d{2}/\d{4}\s+', line) or re.match(r'^vATCSCC\s+ADVZY', line, re.IGNORECASE):
            break

        lines_consumed += 1
        raw_lines.append(line)

        upper_line = line.upper()

        if in_comments:
            if re.match(r'^(CTL|ELEMENT|ADL|GS\s+CNX|COMMENTS)\s', upper_line, re.IGNORECASE):
                in_comments = False
            else:
                comments_lines.append(line)
                continue

        # CTL ELEMENT: SFO
        ctl_match = re.match(r'^CTL\s+ELEMENT:\s*(\w+)', line, re.IGNORECASE)
        if ctl_match:
            airport = ctl_match.group(1).upper()
            continue

        # ELEMENT TYPE: APT (skip)
        if upper_line.startswith('ELEMENT TYPE:'):
            continue

        # ADL TIME: 0310Z
        adl_match = re.match(r'^ADL\s+TIME:\s*(\d{4})Z?', line, re.IGNORECASE)
        if adl_match:
            issued_time = parse_time(adl_match.group(1))
            continue

        # GS CNX PERIOD: 07/0310 - 07/0330
        cnx_period_match = re.search(r'GS\s+CNX\s+PERIOD:\s*\d{2}/(\d{4})Z?\s*[-\u2013\u2014]\s*\d{2}/(\d{4})Z?',
                                     line, re.IGNORECASE)
        if cnx_period_match:
            cnx_start = parse_time(cnx_period_match.group(1))
            cnx_end = parse_time(cnx_period_match.group(2))
            continue

        # COMMENTS:
        comments_match = re.match(r'^COMMENTS:\s*(.*)', line, re.IGNORECASE)
        if comments_match:
            first_comment = comments_match.group(1).strip()
            if first_comment:
                comments_lines.append(first_comment)
            in_comments = True
            continue

    raw_text = '\n'.join(raw_lines)
    comments_text = '\n'.join(comments_lines).strip()

    if airport:
        advisory = GSAdvisory(
            advzy_number=advzy_number,
            advisory_type='CNX',
            adl_time=issued_time,
            gs_period_start=cnx_start,
            gs_period_end=cnx_end,
            comments=comments_text,
            raw_text=raw_text
        )
        return (advisory, lines_consumed)

    return (None, lines_consumed)


def build_gs_programs(gs_tmis: List[TMI], gs_advisories: List[GSAdvisory],
                      gs_cnx_advisories: List[GSAdvisory]) -> List[GSProgram]:
    """
    Chain GS advisories into programs by airport.

    Logic:
    1. Group GS TMIs + advisories by airport (CTL ELEMENT)
    2. Sort by issued time within each airport
    3. First advisory -> INITIAL, subsequent -> EXTENSION/UPDATE
    4. Match CNX advisories by airport -> close program
    5. No CNX -> EXPIRATION at last advisory's end time
    """
    from collections import defaultdict

    # Group advisories by airport
    # Use advisories first; for TMIs without a matching advisory, create synthetic ones
    airport_advisories = defaultdict(list)

    # Index advisories by their raw_text to avoid duplicates when matching TMIs
    advisory_raw_texts = set()
    for adv in gs_advisories:
        # Determine airport from the advisory's raw_text or from associated TMI
        airport = ''
        ctl_match = re.search(r'CTL\s+ELEMENT:\s*(\w+)', adv.raw_text, re.IGNORECASE)
        if ctl_match:
            airport = ctl_match.group(1).upper()
        if not airport:
            # Try header
            header_match = re.search(r'ADVZY\s+\d+\s+([A-Z]{3})/', adv.raw_text, re.IGNORECASE)
            if header_match:
                airport = header_match.group(1).upper()

        if airport:
            airport_advisories[airport].append(adv)
            advisory_raw_texts.add(adv.raw_text)

    # For GS TMIs that have no matching advisory, create synthetic wrappers
    for tmi in gs_tmis:
        if tmi.raw_text and tmi.raw_text in advisory_raw_texts:
            continue  # Already have a real advisory for this

        for dest in (tmi.destinations or []):
            if dest not in airport_advisories or not any(
                a.advzy_number for a in airport_advisories[dest]
                if a.raw_text == tmi.raw_text
            ):
                synthetic = GSAdvisory(
                    advzy_number='',
                    advisory_type='INITIAL',
                    adl_time=tmi.issued_utc,
                    gs_period_start=tmi.start_utc,
                    gs_period_end=tmi.end_utc,
                    dep_facilities=[tmi.provider] if tmi.provider and tmi.provider != 'ALL' else [],
                    impacting_condition=tmi.reason if tmi.reason != 'ADVZY Ground Stop' else '',
                    raw_text=tmi.raw_text or ''
                )
                airport_advisories[dest].append(synthetic)

    # Index CNX advisories by airport
    cnx_by_airport = defaultdict(list)
    for cnx in gs_cnx_advisories:
        airport = ''
        ctl_match = re.search(r'CTL\s+ELEMENT:\s*(\w+)', cnx.raw_text, re.IGNORECASE)
        if ctl_match:
            airport = ctl_match.group(1).upper()
        if not airport:
            header_match = re.search(r'ADVZY\s+\d+\s+([A-Z]{3})/', cnx.raw_text, re.IGNORECASE)
            if header_match:
                airport = header_match.group(1).upper()
        if airport:
            cnx_by_airport[airport].append(cnx)

    # Build programs
    programs = []
    for airport, advisories in airport_advisories.items():
        # Sort by adl_time (issued time), falling back to gs_period_start
        advisories.sort(key=lambda a: a.adl_time or a.gs_period_start or datetime.min)

        # Set advisory types: first = INITIAL, subsequent = EXTENSION
        for idx, adv in enumerate(advisories):
            if adv.advisory_type == 'CNX':
                continue  # Don't change CNX type
            if idx == 0:
                adv.advisory_type = 'INITIAL'
            else:
                adv.advisory_type = 'EXTENSION'

        # Compute effective times
        starts = [a.gs_period_start for a in advisories if a.gs_period_start]
        ends = [a.gs_period_end for a in advisories if a.gs_period_end]
        effective_start = min(starts) if starts else None
        effective_end = max(ends) if ends else None

        # Union of dep facilities
        all_dep_facilities = []
        dep_fac_set = set()
        for adv in advisories:
            for f in adv.dep_facilities:
                if f not in dep_fac_set:
                    dep_fac_set.add(f)
                    all_dep_facilities.append(f)

        # Latest tier
        latest_tier = ''
        for adv in reversed(advisories):
            if adv.dep_facility_tier:
                latest_tier = adv.dep_facility_tier
                break

        # Latest impacting condition and prob extension
        latest_impact = ''
        latest_prob = ''
        for adv in reversed(advisories):
            if adv.impacting_condition and not latest_impact:
                latest_impact = adv.impacting_condition
            if adv.prob_extension and not latest_prob:
                latest_prob = adv.prob_extension
            if latest_impact and latest_prob:
                break

        # All comments
        all_comments = [adv.comments for adv in advisories if adv.comments]

        # Check for CNX
        cnx_list = cnx_by_airport.get(airport, [])
        ended_by = 'EXPIRATION'
        cnx_comments = ''
        if cnx_list:
            ended_by = 'CNX'
            # Use the last CNX advisory's end time
            cnx_sorted = sorted(cnx_list, key=lambda c: c.adl_time or c.gs_period_start or datetime.min)
            last_cnx = cnx_sorted[-1]
            if last_cnx.gs_period_end:
                effective_end = last_cnx.gs_period_end
            elif last_cnx.adl_time:
                effective_end = last_cnx.adl_time
            cnx_comments = last_cnx.comments
            # Add CNX advisories to the advisory chain
            advisories.extend(cnx_sorted)

        program = GSProgram(
            airport=airport,
            advisories=advisories,
            dep_facilities=all_dep_facilities,
            dep_facility_tier=latest_tier,
            effective_start=effective_start,
            effective_end=effective_end,
            ended_by=ended_by,
            impacting_condition=latest_impact,
            prob_extension=latest_prob,
            comments=all_comments,
            cnx_comments=cnx_comments
        )
        programs.append(program)

    logger.debug(f"Built {len(programs)} GS programs from {len(gs_advisories)} advisories + {len(gs_cnx_advisories)} CNX")
    return programs


def parse_advzy_reroute(lines: List[str], start_idx: int, event_start: datetime, event_end: datetime,
                        destinations: List[str]) -> Tuple[Optional[TMI], Optional[RerouteAdvisory], int]:
    """
    Parse a Reroute ADVZY block (ROUTE RQD, FEA FYI, FCA RQD, ICR RQD, etc.).

    Format:
        vATCSCC ADVZY 026 DCC 06/22/2025 ROUTE RQD
        NAME: MCO_NO_GRNCH_PRICY
        CONSTRAINED AREA: ZJX
        REASON: WEATHER
        INCLUDE TRAFFIC: ETD FROM MCO
        FACILITIES INCLUDED: ZJX, ZMA
        VALID: ETD 1900-2359
        TMI ID: RRDCC506
        ORIG       DEST    ROUTE
        ----       ----    -----
        MCO        BOS     V547 SAV >J79 HPW BBOBO Q22 RBV Q419 JFK< ROBUC3
        MCO        EWR     V547 SAV >J79 HPW< BIGGY3
        REMARKS: REPLACES ADVZY 020
        MODIFICATIONS: ARRIVAL CHANGED TO SNFLD3
        ASSOCIATED RESTRICTIONS: 20 MIT OVER BUGSY
        PROBABILITY OF EXTENSION: HIGH
        EXEMPTIONS: AR AND Y ROUTES, Q75 EXEMPT

    Returns:
        Tuple of (TMI or None, RerouteAdvisory or None, number of lines consumed)
    """
    if start_idx >= len(lines):
        return (None, None, 0)

    header_line = lines[start_idx]

    # Extract advisory number
    advzy_num_match = re.search(r'ADVZY\s+(\d+)', header_line, re.IGNORECASE)
    advzy_number = advzy_num_match.group(1) if advzy_num_match else ''

    # Extract route_type and action from header (e.g., "ROUTE RQD", "FEA FYI")
    type_action_match = re.search(r'\b(ROUTE|FEA|FCA|ICR)\s+(RQD|RMD|PLN|FYI)\b', header_line, re.IGNORECASE)
    route_type = type_action_match.group(1).upper() if type_action_match else ''
    action = type_action_match.group(2).upper() if type_action_match else ''

    name = ''
    constrained_area = ''
    reason = ''
    time_type = ''
    origins = []
    reroute_destinations = []
    facilities = []
    valid_start = None
    valid_end = None
    tmi_id = ''
    routes = []
    modifications = ''
    replaces_advzy = ''
    associated_restrictions = ''
    prob_extension = ''
    exemptions = ''
    comments = ''
    in_route_table = False
    lines_consumed = 1
    raw_lines = [header_line]

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

    for i in range(start_idx + 1, min(start_idx + 50, len(lines))):  # Reroutes can be long
        line = lines[i].strip()
        if not line:
            lines_consumed += 1
            if in_route_table:
                in_route_table = False  # Empty line ends route table
            continue

        # Check if we've hit another TMI or ADVZY
        if re.match(r'^vATCSCC\s+ADVZY', line, re.IGNORECASE):
            break
        if re.match(r'^\d{2}/\d{4}\s+', line) and not in_route_table:
            break

        lines_consumed += 1
        raw_lines.append(line)

        upper_line = line.upper()

        # Route table parsing - check this first since route entries could look like other patterns
        if in_route_table:
            # Separator line (---- ---- -----)
            if re.match(r'^-+\s+-+\s+-+', line):
                continue
            # Route entry: "MCO        BOS     V547 SAV >J79 HPW BBOBO Q22 RBV Q419 JFK< ROBUC3"
            route_match = re.match(r'([A-Z]{3})\s+([A-Z]{3})\s+(.*)', line, re.IGNORECASE)
            if route_match:
                orig_code = route_match.group(1).upper()
                dest_code = route_match.group(2).upper()
                route_string = route_match.group(3).strip()

                # Extract required fixes from >...< markers
                required_fixes = re.findall(r'>([^<]+)<', route_string)
                required_fix_list = []
                for segment in required_fixes:
                    # Split segment into individual fixes
                    for fix in segment.split():
                        fix_clean = fix.strip()
                        if fix_clean and re.match(r'^[A-Z0-9]+$', fix_clean, re.IGNORECASE):
                            required_fix_list.append(fix_clean.upper())

                routes.append(RouteEntry(
                    origins=[orig_code],
                    destination=dest_code,
                    route_string=route_string,
                    required_fixes=required_fix_list
                ))

                if orig_code not in origins:
                    origins.append(orig_code)
                if dest_code not in reroute_destinations:
                    reroute_destinations.append(dest_code)
                continue
            else:
                # No longer a route entry; exit route table mode
                in_route_table = False
                # Fall through to field parsing

        # NAME: MCO_NO_GRNCH_PRICY
        name_match = re.match(r'^NAME:\s*(.*)', line, re.IGNORECASE)
        if name_match:
            name = name_match.group(1).strip()
            continue

        # CONSTRAINED AREA: ZJX
        ca_match = re.match(r'^CONSTRAINED\s+AREA:\s*(.*)', line, re.IGNORECASE)
        if ca_match:
            constrained_area = ca_match.group(1).strip()
            continue

        # REASON: WEATHER
        reason_match = re.match(r'^REASON:\s*(.*)', line, re.IGNORECASE)
        if reason_match:
            reason = reason_match.group(1).strip()
            continue

        # INCLUDE TRAFFIC: ETD FROM MCO
        incl_match = re.match(r'^INCLUDE\s+TRAFFIC:\s*(.*)', line, re.IGNORECASE)
        if incl_match:
            incl_text = incl_match.group(1).strip()
            # Extract time_type (ETD/ETA) and origins/destinations
            tt_match = re.search(r'\b(ETD|ETA)\b', incl_text, re.IGNORECASE)
            if tt_match:
                time_type = tt_match.group(1).upper()
            # Extract airport codes after FROM/TO
            from_match = re.search(r'\bFROM\s+([A-Z,\s]+)', incl_text, re.IGNORECASE)
            if from_match:
                for code in re.findall(r'[A-Z]{3}', from_match.group(1).upper()):
                    if code not in origins:
                        origins.append(code)
            to_match = re.search(r'\bTO\s+([A-Z,\s]+)', incl_text, re.IGNORECASE)
            if to_match:
                for code in re.findall(r'[A-Z]{3}', to_match.group(1).upper()):
                    if code not in reroute_destinations:
                        reroute_destinations.append(code)
            continue

        # FACILITIES INCLUDED: ZJX, ZMA
        fac_match = re.match(r'^FACILITIES\s+INCLUDED:\s*(.*)', line, re.IGNORECASE)
        if fac_match:
            fac_text = fac_match.group(1).strip()
            facilities = [f.strip() for f in re.findall(r'[A-Z]{2,4}', fac_text.upper()) if f.strip()]
            continue

        # VALID: ETD 1900-2359
        valid_match = re.match(r'^VALID:\s*(.*)', line, re.IGNORECASE)
        if valid_match:
            valid_text = valid_match.group(1).strip()
            # Extract time_type if present
            vtt_match = re.search(r'\b(ETD|ETA)\b', valid_text, re.IGNORECASE)
            if vtt_match:
                time_type = vtt_match.group(1).upper()
            # Extract time range HHMM-HHMM
            vtime_match = re.search(r'(\d{4})\s*[-\u2013\u2014]\s*(\d{4})', valid_text)
            if vtime_match:
                valid_start = parse_time(vtime_match.group(1))
                valid_end = parse_time(vtime_match.group(2))
            continue

        # TMI ID: RRDCC506
        tmi_id_match = re.match(r'^TMI\s+ID:\s*(.*)', line, re.IGNORECASE)
        if tmi_id_match:
            tmi_id = tmi_id_match.group(1).strip()
            continue

        # ORIG DEST ROUTE header -> start route table
        if re.match(r'^ORIG\s+DEST\s+ROUTE', line, re.IGNORECASE):
            in_route_table = True
            continue

        # Separator line within route table context
        if re.match(r'^-+\s+-+\s+-+', line):
            in_route_table = True
            continue

        # REMARKS: REPLACES ADVZY 020
        remarks_match = re.match(r'^REMARKS:\s*(.*)', line, re.IGNORECASE)
        if remarks_match:
            remarks_text = remarks_match.group(1).strip()
            # Check for "REPLACES ADVZY NNN"
            replaces_match = re.search(r'REPLACES\s+ADVZY\s+(\d+)', remarks_text, re.IGNORECASE)
            if replaces_match:
                replaces_advzy = replaces_match.group(1)
            if not comments:
                comments = remarks_text
            continue

        # MODIFICATIONS:
        mod_match = re.match(r'^MODIFICATIONS:\s*(.*)', line, re.IGNORECASE)
        if mod_match:
            modifications = mod_match.group(1).strip()
            continue

        # ASSOCIATED RESTRICTIONS:
        assoc_match = re.match(r'^ASSOCIATED\s+RESTRICTIONS:\s*(.*)', line, re.IGNORECASE)
        if assoc_match:
            associated_restrictions = assoc_match.group(1).strip()
            continue

        # PROBABILITY OF EXTENSION:
        prob_match = re.match(r'^PROBABILITY\s+OF\s+EXTENSION:\s*(.*)', line, re.IGNORECASE)
        if prob_match:
            prob_extension = prob_match.group(1).strip()
            continue

        # EXEMPTIONS:
        exempt_match = re.match(r'^EXEMPTIONS:\s*(.*)', line, re.IGNORECASE)
        if exempt_match:
            exemptions = exempt_match.group(1).strip()
            continue

    raw_text = '\n'.join(raw_lines)

    # Build TMI for backward compatibility
    tmi = None
    advisory = None

    if name or routes or tmi_id:
        # Determine destinations for TMI
        tmi_dests = reroute_destinations if reroute_destinations else destinations

        tmi = TMI(
            tmi_id=tmi_id or f'REROUTE_{name}',
            tmi_type=TMIType.REROUTE,
            destinations=tmi_dests,
            origins=origins,
            start_utc=valid_start or event_start,
            end_utc=valid_end or event_end,
            reroute_name=name,
            reroute_mandatory=(action == 'RQD'),
            reroute_routes=[re.route_string for re in routes],
            time_type=time_type,
            reason=reason,
            raw_text=raw_text
        )

        advisory = RerouteAdvisory(
            advzy_number=advzy_number,
            advisory_type='INITIAL',  # Default; chaining may change
            route_type=route_type,
            action=action,
            adl_time=None,  # Reroute ADVZYs don't have ADL TIME
            valid_start=valid_start,
            valid_end=valid_end,
            time_type=time_type,
            routes=routes,
            origins=origins,
            destinations=reroute_destinations,
            facilities=facilities,
            modifications=modifications,
            replaces_advzy=replaces_advzy,
            associated_restrictions=associated_restrictions,
            prob_extension=prob_extension,
            exemptions=exemptions,
            comments=comments,
            raw_text=raw_text
        )

    return (tmi, advisory, lines_consumed)


def parse_advzy_reroute_cancellation(lines: List[str], start_idx: int,
                                     event_start: datetime) -> Tuple[Optional[RerouteAdvisory], int]:
    """
    Parse an ADVZY Reroute Cancellation block.

    Format:
        vATCSCC ADVZY 100 DCC 06/22/2025 REROUTE CANCELLATION
        MCO_NO_GRNCH_PRICY HAS BEEN CANCELLED AT 2108Z

    Returns:
        Tuple of (RerouteAdvisory or None, number of lines consumed)
    """
    if start_idx >= len(lines):
        return (None, 0)

    header_line = lines[start_idx]

    # Extract advisory number
    advzy_num_match = re.search(r'ADVZY\s+(\d+)', header_line, re.IGNORECASE)
    advzy_number = advzy_num_match.group(1) if advzy_num_match else ''

    name = ''
    cancel_time = None
    lines_consumed = 1
    raw_lines = [header_line]

    event_date = event_start.date()

    for i in range(start_idx + 1, min(start_idx + 10, len(lines))):
        line = lines[i].strip()
        if not line:
            lines_consumed += 1
            continue

        # Check if we've hit another ADVZY or TMI
        if re.match(r'^vATCSCC\s+ADVZY', line, re.IGNORECASE):
            break
        if re.match(r'^\d{2}/\d{4}\s+', line):
            break

        lines_consumed += 1
        raw_lines.append(line)

        # Pattern: "MCO_NO_GRNCH_PRICY HAS BEEN CANCELLED AT 2108Z"
        cancel_match = re.search(r'(\S+)\s+HAS\s+BEEN\s+CANCEL', line, re.IGNORECASE)
        if cancel_match:
            name = cancel_match.group(1).upper()

            # Extract cancellation time
            time_match = re.search(r'AT\s+(\d{4})Z?', line, re.IGNORECASE)
            if time_match:
                time_str = time_match.group(1)
                try:
                    hour = int(time_str[:2])
                    minute = int(time_str[2:4])
                    cancel_time = datetime(event_date.year, event_date.month, event_date.day, hour, minute)
                    if cancel_time < event_start - timedelta(hours=2):
                        cancel_time = cancel_time + timedelta(days=1)
                except ValueError:
                    pass
            continue

    raw_text = '\n'.join(raw_lines)

    if name:
        advisory = RerouteAdvisory(
            advzy_number=advzy_number,
            advisory_type='CANCELLATION',
            route_type='',
            action='',
            adl_time=cancel_time,
            valid_start=None,
            valid_end=cancel_time,
            raw_text=raw_text
        )
        # Store name for matching in program chaining
        advisory.comments = name  # Use comments to carry the reroute name
        return (advisory, lines_consumed)

    return (None, lines_consumed)


def build_reroute_programs(reroute_tmis: List[TMI], reroute_advisories: List[RerouteAdvisory],
                           reroute_cnx_advisories: List[RerouteAdvisory]) -> List[RerouteProgram]:
    """
    Chain reroute advisories into programs by name/TMI ID.

    Logic:
    1. Group by NAME (primary) or TMI ID (fallback)
    2. Sort by advisory number within each group
    3. First -> INITIAL, "REPLACES ADVZY NNN" -> UPDATE/EXTENSION
    4. REROUTE CANCELLATION matching NAME -> close
    5. No cancellation -> EXPIRATION at last advisory's VALID end
    6. Update route_type and action to latest non-cancelled advisory
    7. current_routes = routes from latest non-cancelled advisory
    """
    from collections import defaultdict

    # Group advisories by name
    name_groups = defaultdict(list)
    tmi_id_to_name = {}

    for adv in reroute_advisories:
        # Get name from the advisory raw text
        name_match = re.search(r'^NAME:\s*(\S+)', adv.raw_text, re.MULTILINE | re.IGNORECASE)
        name = name_match.group(1).upper() if name_match else ''

        # Get TMI ID
        id_match = re.search(r'^TMI\s+ID:\s*(\S+)', adv.raw_text, re.MULTILINE | re.IGNORECASE)
        reroute_tmi_id = id_match.group(1).upper() if id_match else ''

        if name:
            name_groups[name].append(adv)
            if reroute_tmi_id:
                tmi_id_to_name[reroute_tmi_id] = name
        elif reroute_tmi_id:
            name_groups[reroute_tmi_id].append(adv)

    # For reroute TMIs without matching advisories, create synthetic entries
    for tmi in reroute_tmis:
        rr_name = tmi.reroute_name or tmi.tmi_id
        if rr_name and rr_name.upper() not in name_groups:
            synthetic = RerouteAdvisory(
                advzy_number='',
                advisory_type='INITIAL',
                route_type='ROUTE',
                action='RQD' if tmi.reroute_mandatory else 'FYI',
                valid_start=tmi.start_utc,
                valid_end=tmi.end_utc,
                time_type=tmi.time_type or '',
                origins=tmi.origins,
                destinations=tmi.destinations,
                raw_text=tmi.raw_text or ''
            )
            name_groups[rr_name.upper()].append(synthetic)

    # Index CNX advisories by reroute name
    cnx_by_name = defaultdict(list)
    for cnx in reroute_cnx_advisories:
        # The reroute name is stored in comments by parse_advzy_reroute_cancellation
        cnx_name = cnx.comments.upper() if cnx.comments else ''
        if cnx_name:
            cnx_by_name[cnx_name].append(cnx)

    # Build programs
    programs = []
    for group_name, advisories in name_groups.items():
        # Sort by advisory number
        advisories.sort(key=lambda a: int(a.advzy_number) if a.advzy_number.isdigit() else 0)

        # Set advisory types
        for idx, adv in enumerate(advisories):
            if adv.advisory_type == 'CANCELLATION':
                continue
            if idx == 0:
                adv.advisory_type = 'INITIAL'
            elif adv.replaces_advzy:
                adv.advisory_type = 'UPDATE'
            else:
                adv.advisory_type = 'EXTENSION'

        # Get latest non-cancelled advisory for current state
        active_advisories = [a for a in advisories if a.advisory_type != 'CANCELLATION']
        latest = active_advisories[-1] if active_advisories else advisories[0]

        # Compute effective times
        starts = [a.valid_start for a in active_advisories if a.valid_start]
        ends = [a.valid_end for a in active_advisories if a.valid_end]
        effective_start = min(starts) if starts else None
        effective_end = max(ends) if ends else None

        # Union of origins, destinations, facilities
        all_origins = []
        all_dests = []
        all_facilities = []
        for adv in active_advisories:
            for o in adv.origins:
                if o not in all_origins:
                    all_origins.append(o)
            for d in adv.destinations:
                if d not in all_dests:
                    all_dests.append(d)
            for f in adv.facilities:
                if f not in all_facilities:
                    all_facilities.append(f)

        # Check for cancellation
        cnx_list = cnx_by_name.get(group_name, [])
        ended_by = 'EXPIRATION'
        if cnx_list:
            ended_by = 'CANCELLATION'
            cnx_sorted = sorted(cnx_list, key=lambda c: c.adl_time or datetime.min)
            last_cnx = cnx_sorted[-1]
            if last_cnx.valid_end:
                effective_end = last_cnx.valid_end
            elif last_cnx.adl_time:
                effective_end = last_cnx.adl_time
            advisories.extend(cnx_sorted)

        # Get TMI ID from any advisory
        reroute_tmi_id = ''
        for adv in active_advisories:
            id_match = re.search(r'^TMI\s+ID:\s*(\S+)', adv.raw_text, re.MULTILINE | re.IGNORECASE)
            if id_match:
                reroute_tmi_id = id_match.group(1).upper()
                break

        program = RerouteProgram(
            name=group_name,
            tmi_id=reroute_tmi_id,
            route_type=latest.route_type,
            action=latest.action,
            advisories=advisories,
            constrained_area='',  # From latest advisory
            reason='',
            effective_start=effective_start,
            effective_end=effective_end,
            ended_by=ended_by,
            current_routes=latest.routes if latest.routes else [],
            origins=all_origins,
            destinations=all_dests,
            facilities=all_facilities,
            exemptions=latest.exemptions,
            associated_restrictions=latest.associated_restrictions
        )

        # Extract constrained_area and reason from latest advisory
        for adv in reversed(active_advisories):
            ca_match = re.search(r'^CONSTRAINED\s+AREA:\s*(.*)', adv.raw_text, re.MULTILINE | re.IGNORECASE)
            if ca_match and not program.constrained_area:
                program.constrained_area = ca_match.group(1).strip()
            reason_match = re.search(r'^REASON:\s*(.*)', adv.raw_text, re.MULTILINE | re.IGNORECASE)
            if reason_match and not program.reason:
                program.reason = reason_match.group(1).strip()
            if program.constrained_area and program.reason:
                break

        programs.append(program)

    logger.debug(f"Built {len(programs)} reroute programs from {len(reroute_advisories)} advisories + {len(reroute_cnx_advisories)} CNX")
    return programs


def parse_ntml_to_tmis(ntml_text: str, event_start: datetime, event_end: datetime, destinations: List[str]) -> ParseResult:
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

    Returns:
        ParseResult with:
        - tmis: List of successfully parsed TMI objects
        - skipped_lines: List of SkippedLine objects for lines that couldn't be parsed
                        (type='unparsed' are candidates for user definition)
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
        # We want the LAST one that looks like a real facility pair (not REASON:CONDITION)
        all_matches = re.findall(r'([A-Z][A-Z0-9,]+):([A-Z][A-Z0-9,]+)', clean_text)

        def looks_like_facility(code: str) -> bool:
            """
            Check if a code looks like a valid ATC facility.

            Valid patterns:
            - ARTCC: Z + 2 letters (ZNY, ZDC) or CZ + 2 letters (CZYZ, CZUL for Canada)
            - TRACON: Letter + 2 digits (N90, A80, C90) or 3-4 letter codes (PCT, SCT, NCT)
            - Sector: ARTCC + digits (ZNY66, ZDC42)
            - Airport: 3-4 letters optionally starting with K/C/P (JFK, KJFK, LGA)

            Returns False for full English words (VOLUME, STAFFING, WEATHER, etc.)
            """
            code = code.upper()

            # ARTCC: Z + 2 letters
            if re.match(r'^Z[A-Z]{2}$', code):
                return True
            # Canadian FIR: CZ + 2 letters
            if re.match(r'^CZ[A-Z]{2}$', code):
                return True
            # TRACON: Letter + 2 digits (N90, A80, C90, D10, etc.)
            if re.match(r'^[A-Z]\d{2}$', code):
                return True
            # Sector: ARTCC + digits (ZNY66, ZDC42)
            if re.match(r'^Z[A-Z]{2}\d+$', code):
                return True
            # 3-letter codes (PCT, SCT, NCT, JFK, LGA, MIA, etc.) - must have at least one digit OR be short
            if re.match(r'^[A-Z]{3}$', code):
                return True
            # 4-letter airport with K/C/P prefix (KJFK, KLAX, CYYZ)
            if re.match(r'^[KCP][A-Z]{3}$', code):
                return True
            # 4-letter TRACON codes that aren't words (U90, etc.)
            if re.match(r'^[A-Z]\d{2}[A-Z]?$', code):
                return True

            # If it's 5+ letters with no digits, it's probably a word (VOLUME, STAFFING, etc.)
            if len(code) >= 5 and code.isalpha():
                return False

            # 2-letter codes could be navaids - allow them
            if re.match(r'^[A-Z]{2}$', code):
                return True

            return False

        logger.debug(f"parse_facilities: all_matches={all_matches} from text='{text[:100]}...'")

        for requestor, provider in reversed(all_matches):
            # Check if BOTH parts look like valid facility codes
            req_parts = requestor.split(',')
            prov_parts = provider.split(',')

            # All parts must look like facilities
            if not all(looks_like_facility(p) for p in req_parts + prov_parts):
                logger.debug(f"parse_facilities: skipping {requestor}:{provider} (not facility codes)")
                continue

            # This looks like a valid facility pair
            logger.debug(f"parse_facilities: found {requestor}:{provider}")
            return (requestor, provider, is_multiple)

        logger.debug(f"parse_facilities: no valid facility pair found")
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

    def parse_thru_clause(line: str) -> Optional[ThruFilter]:
        """
        Parse thru/through clause from NTML line.

        Patterns:
        - "JFK to LAX thru ZAU 75MIT" -> ThruFilter(value='ZAU', thru_type=ThruType.ARTCC)
        - "OAK via ALL through ZLC 20MIT" -> ThruFilter(value='ZLC', thru_type=ThruType.ARTCC)
        - "ZNY overflights thru ZNY66 20MIT" -> ThruFilter(value='ZNY66', thru_type=ThruType.SECTOR)
        - "...thru J60 15MIT" -> ThruFilter(value='J60', thru_type=ThruType.AIRWAY)

        Returns:
            ThruFilter if thru clause found, None otherwise
        """
        # Match "thru" or "through" followed by facility code
        # The facility code is typically followed by MIT value, time range, or end of relevant portion
        thru_match = re.search(
            r'\b(?:thru|through)\s+([A-Z0-9_]+)\b',
            line, re.IGNORECASE
        )
        if thru_match:
            thru_value = thru_match.group(1).upper()
            return create_thru_filter(thru_value)
        return None

    def determine_scope_logic(
        line: str,
        has_origins: bool,
        has_destinations: bool,
        has_thru: bool,
        traffic_direction: TrafficDirection
    ) -> ScopeLogic:
        """
        Determine the appropriate ScopeLogic based on NTML patterns.

        Logic:
        - "JFK departures..." → DEPARTURES_ONLY (check origins only)
        - "arrivals to JFK..." → ARRIVALS_ONLY (check destinations only)
        - "JFK to LAX..." → OD_PAIR (must match BOTH origin AND destination)
        - "JFK via ALL" → ANY_TRAFFIC (match either origin OR destination)
        - "ZNY overflights..." → OVERFLIGHTS (transit but NOT origin/dest)
        - "...thru ZAU" (no origin/dest) → THRU_ONLY (only check thru facility)

        Args:
            line: Original NTML line
            has_origins: Whether origins list is populated
            has_destinations: Whether destinations list is populated
            has_thru: Whether thru clause was found
            traffic_direction: Parsed traffic direction

        Returns:
            ScopeLogic enum value
        """
        line_upper = line.upper()

        # Check for "overflights" keyword
        if re.search(r'\boverflights?\b', line, re.IGNORECASE):
            return ScopeLogic.OVERFLIGHTS

        # Check for explicit OD pair pattern: "XXX to YYY" (not "to" as part of word)
        # e.g., "JFK to LAX", "BOS to MIA"
        if re.search(r'\b[A-Z]{3,4}\s+to\s+[A-Z]{3,4}\b', line, re.IGNORECASE):
            return ScopeLogic.OD_PAIR

        # If only thru is specified (no origins/destinations from airport pattern)
        if has_thru and not has_origins and not has_destinations:
            return ScopeLogic.THRU_ONLY

        # Based on explicit traffic direction
        if traffic_direction == TrafficDirection.DEPARTURES:
            return ScopeLogic.DEPARTURES_ONLY

        if traffic_direction == TrafficDirection.ARRIVALS:
            return ScopeLogic.ARRIVALS_ONLY

        # "via ALL" patterns typically mean any traffic to/from the airport
        # e.g., "JFK via ALL" means all JFK traffic (departures OR arrivals)
        if re.search(r'\bvia\s+ALL\b', line, re.IGNORECASE):
            if has_origins or has_destinations:
                return ScopeLogic.ANY_TRAFFIC

        # Default: if we have destinations but no origins, it's arrivals-focused
        # If we have origins but no destinations, it's departures-focused
        if has_destinations and not has_origins:
            return ScopeLogic.ARRIVALS_ONLY
        if has_origins and not has_destinations:
            return ScopeLogic.DEPARTURES_ONLY

        # If we have both, default to ANY_TRAFFIC (OR logic)
        if has_origins and has_destinations:
            return ScopeLogic.ANY_TRAFFIC

        # Fallback to ANY_TRAFFIC
        return ScopeLogic.ANY_TRAFFIC

    skipped_lines = []
    gs_advisories = []
    gs_cnx_advisories = []
    reroute_advisories = []
    reroute_cnx_advisories = []

    i = 0
    while i < len(lines):
        line = lines[i].strip()
        if not line:
            i += 1
            continue

        line_type, metadata = classify_line(line)
        tmi = None

        # Handle ADVZY Ground Stops (multi-line format)
        if line_type == 'advzy_gs':
            gs_tmi, gs_advisory, lines_consumed = parse_advzy_ground_stop(lines, i, event_start, event_end, destinations)
            if gs_tmi:
                tmis.append(gs_tmi)
                logger.debug(f"Parsed ADVZY Ground Stop: {gs_tmi.destinations} from {gs_tmi.provider}")
            if gs_advisory:
                gs_advisories.append(gs_advisory)
            i += lines_consumed
            continue

        # Handle ADVZY GS CNX (cancellation)
        if line_type == 'advzy_gs_cnx':
            cnx_advisory, lines_consumed = parse_advzy_gs_cnx(lines, i, event_start, event_end)
            if cnx_advisory:
                gs_cnx_advisories.append(cnx_advisory)
                logger.debug(f"Parsed ADVZY GS CNX: {cnx_advisory.advzy_number}")
            i += lines_consumed
            continue

        # Handle ADVZY Reroutes (ROUTE RQD, FEA FYI, etc.)
        if line_type == 'advzy_reroute':
            rte_tmi, rte_advisory, lines_consumed = parse_advzy_reroute(lines, i, event_start, event_end, destinations)
            if rte_tmi:
                tmis.append(rte_tmi)
                logger.debug(f"Parsed ADVZY Reroute: {rte_advisory.route_type if rte_advisory else 'unknown'}")
            if rte_advisory:
                reroute_advisories.append(rte_advisory)
            i += lines_consumed
            continue

        # Handle ADVZY Reroute Cancellation
        if line_type == 'advzy_reroute_cnx':
            cnx_advisory, lines_consumed = parse_advzy_reroute_cancellation(lines, i, event_start)
            if cnx_advisory:
                reroute_cnx_advisories.append(cnx_advisory)
                logger.debug(f"Parsed ADVZY Reroute Cancellation: {cnx_advisory.advzy_number}")
            i += lines_consumed
            continue

        # === ADVZY-ONLY RULE: Skip NTML single-line GS and reroute entries ===
        if line_type in ('mit', 'stop', 'cfr', 'minit', 'unknown'):
            upper_line = line.upper()
            if re.search(r'\bGS\b', upper_line) and 'GROUND STOP' not in upper_line:
                logger.debug(f"Skipping NTML GS line (ADVZY-only): {line[:60]}...")
                i += 1
                continue
            if re.search(r'\bRE-?ROUTE\b|\bROUTE\s+RQD\b|\bFEA\s+FYI\b', upper_line):
                logger.debug(f"Skipping NTML reroute line (ADVZY-only): {line[:60]}...")
                i += 1
                continue

        # Skip non-TMI informational lines (not candidates for user override)
        if line_type in ('advzy_header', 'advzy_field', 'airport_config',
                         'route_header', 'route_entry', 'tmi_id',
                         'timestamp', 'empty'):
            skipped_lines.append((line_type, line, i + 1))  # i+1 for 1-based line number
            i += 1
            continue

        # Delay entries (E/D, D/D, A/D) are parsed by parse_ntml_full() into DelayEntry objects
        # They're not "skipped" - they're just handled separately from spacing TMIs
        if line_type in ('ed_delay', 'ad_delay', 'dd_delay'):
            # Don't add to skipped_lines - these ARE parsed, just not as TMI objects
            logger.debug(f"Delay entry (parsed via parse_ntml_full): {line[:60]}...")
            i += 1
            continue

        # Handle CANCEL RESTR - these cancel existing TMIs, not create new ones
        if line_type == 'cancel':
            # Log cancellation but don't create TMI
            # Format: "31/0326    BOS via RBV CANCEL RESTR ZNY:ZDC"
            logger.debug(f"Cancellation line (not creating TMI): {line}")
            skipped_lines.append((line_type, line, i + 1))
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

                # Parse thru clause (e.g., "thru ZAU", "through ZLC")
                thru_filter = parse_thru_clause(line)

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

                # Determine scope logic based on pattern analysis
                scope_logic = determine_scope_logic(
                    line,
                    has_origins=bool(origin_list),
                    has_destinations=bool(dest_list),
                    has_thru=thru_filter is not None,
                    traffic_direction=traffic_direction
                )

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
                            thru=thru_filter,
                            traffic_direction=traffic_direction,
                            scope_logic=scope_logic,
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
                            notes=f'Part of multi-fix entry: {fix_str}',
                            raw_text=line
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
                        thru=thru_filter,
                        traffic_direction=traffic_direction,
                        scope_logic=scope_logic,
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
                        raw_text=line
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

                # Parse thru clause
                thru_filter = parse_thru_clause(line)

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

                # Determine scope logic
                scope_logic = determine_scope_logic(
                    line,
                    has_origins=bool(origin_list),
                    has_destinations=bool(dest_list),
                    has_thru=thru_filter is not None,
                    traffic_direction=traffic_direction
                )

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
                            thru=thru_filter,
                            traffic_direction=traffic_direction,
                            scope_logic=scope_logic,
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
                            notes=f'Part of multi-fix entry: {fix_str}',
                            raw_text=line
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
                        thru=thru_filter,
                        traffic_direction=traffic_direction,
                        scope_logic=scope_logic,
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
                        raw_text=line
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
            # NOTE: GS (Ground Stop) is NOT parsed from NTML lines - GS only comes from ADVZY blocks
            # The parse_advzy_ground_stop() function handles ADVZY Ground Stops correctly

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
            skipped_lines.append(('unparsed', line, i + 1))

        i += 1

    # Log summary of skipped lines
    if skipped_lines:
        type_counts = {}
        for line_type, _, _ in skipped_lines:
            type_counts[line_type] = type_counts.get(line_type, 0) + 1
        logger.info(f"Skipped {len(skipped_lines)} non-TMI lines: {type_counts}")

    # Link TMI amendments (without cancellations - use parse_ntml_full for full processing)
    tmis = link_tmi_amendments(tmis)

    # Convert internal skipped_lines to SkippedLine objects
    # Only include 'unparsed' lines as candidates for user definition
    skipped_line_objects = [
        SkippedLine(
            line=line_text,
            line_number=line_num,
            reason='No pattern match' if line_type == 'unparsed' else f'Skipped: {line_type}'
        )
        for line_type, line_text, line_num in skipped_lines
        if line_type == 'unparsed'  # Only unparsed lines need user definition
    ]

    # Build GS programs from parsed TMIs and advisories
    gs_programs = build_gs_programs(
        [t for t in tmis if t.tmi_type == TMIType.GS],
        gs_advisories,
        gs_cnx_advisories
    )

    # Build reroute programs from parsed TMIs and advisories
    reroute_programs = build_reroute_programs(
        [t for t in tmis if t.tmi_type == TMIType.REROUTE],
        reroute_advisories,
        reroute_cnx_advisories
    )

    logger.info(f"Parsed {len(tmis)} TMIs from NTML text, {len(skipped_line_objects)} unparsed lines, "
                f"{len(gs_programs)} GS programs, {len(reroute_programs)} reroute programs")
    return ParseResult(tmis=tmis, skipped_lines=skipped_line_objects,
                       gs_programs=gs_programs, reroute_programs=reroute_programs)


@dataclass
class NTMLParseResult:
    """Complete result from parsing NTML text"""
    tmis: List[TMI]
    delays: List[DelayEntry]
    airport_configs: List[AirportConfig]
    cancellations: List[CancelEntry]
    skipped_lines: List[SkippedLine]  # Lines that couldn't be parsed (for user override)


def parse_ntml_full(ntml_text: str, event_start: datetime, event_end: datetime, destinations: List[str]) -> NTMLParseResult:
    """
    Comprehensive NTML parser that extracts all entry types.

    Returns:
        NTMLParseResult containing:
        - tmis: List of TMI restrictions
        - delays: List of E/D, A/D, D/D entries
        - airport_configs: List of airport configuration updates
        - cancellations: List of CANCEL entries
        - skipped_lines: SkippedLine objects for lines that couldn't be parsed
                        (candidates for user-defined TMI override)
    """
    # Start with TMIs from the existing parser
    parse_result = parse_ntml_to_tmis(ntml_text, event_start, event_end, destinations)
    tmis = parse_result.tmis
    # Start with skipped lines from TMI parser (these are unparsed TMI-like lines)
    skipped_lines = list(parse_result.skipped_lines)

    delays = []
    airport_configs = []
    cancellations = []

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

        # Track unparsed lines (from this pass - delays/configs/cancels that failed to parse)
        # Note: TMI-like unparsed lines are already captured from parse_ntml_to_tmis
        elif line_type == 'unknown':
            # Check if already in skipped_lines from TMI parser
            if not any(sl.line == line for sl in skipped_lines):
                skipped_lines.append(SkippedLine(
                    line=line,
                    line_number=0,  # We don't track line numbers in this pass
                    reason='No pattern match (full parser)'
                ))

    # Detect delay trends by comparing sequential entries
    delays = detect_delay_trends(delays)

    # Link TMI amendments and apply cancellations
    tmis = link_tmi_amendments(tmis, cancellations)

    # Log summary
    logger.info(f"NTML parse complete: {len(tmis)} TMIs, {len(delays)} delays, "
                f"{len(airport_configs)} configs, {len(cancellations)} cancellations, "
                f"{len(skipped_lines)} unparsed")

    return NTMLParseResult(
        tmis=tmis,
        delays=delays,
        airport_configs=airport_configs,
        cancellations=cancellations,
        skipped_lines=skipped_lines
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
