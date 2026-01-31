"""
TMI Compliance Analyzer - NTML Parser
======================================

Parses NTML (National Traffic Management Log) text into TMI objects.
Handles raw Discord-pasted content with usernames, timestamps, and Unicode formatting.
"""

import re
import logging
from datetime import datetime, timedelta
from typing import List, Tuple, Optional

from .models import TMI, TMIType

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

    # E/D (Expect Delays with holding): "31/0127    ZBW E/D for BOS +Holding/0147/2 ACFT"
    if re.search(r'\bE/D\b.*\+Holding', line, re.IGNORECASE):
        return ("ed_hold", {"line": line})

    # D/D (Departure Delays): "31/0153    D/D from BOS +35/0153"
    if re.search(r'\bD/D\b.*from\s+\w+\s+\+\d+', line, re.IGNORECASE):
        return ("dd_delay", {"line": line})

    # CANCEL RESTR: "31/0326    BOS via RBV CANCEL RESTR ZNY:ZDC"
    if re.search(r'\bCANCEL\s+RESTR\b', line, re.IGNORECASE):
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

    def parse_facilities(text: str) -> Tuple[str, str]:
        """
        Parse requestor:provider facility pair.
        Handles: "N90:ZNY", "ZDC:ZBW", "ZNY,N90:ZBW,ZDC,ZOB"
        Returns: (requestor, provider)
        """
        # Look for FACILITY:FACILITY pattern at end of line
        match = re.search(r'([A-Z0-9,]+):([A-Z0-9,]+)\s*$', text)
        if match:
            return (match.group(1), match.group(2))
        return ('', '')

    skipped_lines = []

    for line in lines:
        line = line.strip()
        if not line:
            continue

        line_type, metadata = classify_line(line)
        tmi = None

        # Skip non-TMI lines but log them
        if line_type in ('advzy_header', 'advzy_field', 'airport_config', 'ed_hold',
                         'dd_delay', 'route_header', 'route_entry', 'tmi_id',
                         'timestamp', 'empty'):
            skipped_lines.append((line_type, line))
            continue

        # Handle CANCEL RESTR - these cancel existing TMIs, not create new ones
        if line_type == 'cancel':
            # Log cancellation but don't create TMI
            # Format: "31/0326    BOS via RBV CANCEL RESTR ZNY:ZDC"
            logger.debug(f"Cancellation line (not creating TMI): {line}")
            skipped_lines.append((line_type, line))
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
                requestor, provider = parse_facilities(line)

                tmi = TMI(
                    tmi_id=f'STOP_{fix}_{dest}',
                    tmi_type=TMIType.GS,  # STOP is effectively a ground stop
                    fix=fix,
                    destinations=[dest] if dest not in ['ALL', 'ANY'] else destinations,
                    provider=provider,
                    requestor=requestor,
                    start_utc=start_time or event_start,
                    end_utc=end_time or event_end,
                    cancelled_utc=cancelled_utc,
                    reason=f'STOP via {fix}'
                )

        # Parse MIT restriction
        # Format: "30/2100    LGA via BEUTY 25 MIT VOLUME:VOLUME 2330-0400 N90:ZNY"
        # Also handles: "LGA via VALRE/NOBBI 20 MIT AS ONE VOLUME:VOLUME 2330-0400 N90:ZBW"
        # Also handles: "BOS via AUDIL/MEMMS 35 MIT PER STREAM VOLUME:VOLUME 2330-0400 ZBW:ZOB"
        elif line_type == 'mit':
            # Remove leading timestamp if present
            clean_line = re.sub(r'^\d{2}/\d{4}\s+', '', line).strip()

            mit_match = re.match(
                r'(\w+)\s+via\s+(\S+)\s+(\d+)\s*MIT\b',
                clean_line, re.IGNORECASE
            )
            if mit_match:
                dest = mit_match.group(1).upper()
                fix = mit_match.group(2).upper()
                value = int(mit_match.group(3))
                start_time, end_time = parse_ntml_time_range(line, event_date)
                requestor, provider = parse_facilities(line)

                # Check for modifiers
                as_one = bool(re.search(r'\bAS\s+ONE\b', line, re.IGNORECASE))
                per_stream = bool(re.search(r'\bPER\s+STREAM\b', line, re.IGNORECASE))
                per_route = bool(re.search(r'\bPER\s+ROUTE\b', line, re.IGNORECASE))

                modifier = ''
                if as_one:
                    modifier = 'AS ONE'
                elif per_stream:
                    modifier = 'PER STREAM'
                elif per_route:
                    modifier = 'PER ROUTE'

                tmi = TMI(
                    tmi_id=f'MIT_{fix}_{dest}',
                    tmi_type=TMIType.MIT,
                    fix=fix,
                    destinations=[dest] if dest not in ['ALL', 'ANY'] else destinations,
                    value=value,
                    unit='nm',
                    provider=provider,
                    requestor=requestor,
                    start_utc=start_time or event_start,
                    end_utc=end_time or event_end,
                    cancelled_utc=cancelled_utc,
                    reason=modifier if modifier else None
                )

        # Parse MINIT restriction
        elif line_type == 'minit':
            clean_line = re.sub(r'^\d{2}/\d{4}\s+', '', line).strip()

            minit_match = re.match(
                r'(\w+)\s+via\s+(\S+)\s+(\d+)\s*MINIT\b',
                clean_line, re.IGNORECASE
            )
            if minit_match:
                dest = minit_match.group(1).upper()
                fix = minit_match.group(2).upper()
                value = int(minit_match.group(3))
                start_time, end_time = parse_ntml_time_range(line, event_date)
                requestor, provider = parse_facilities(line)

                tmi = TMI(
                    tmi_id=f'MINIT_{fix}_{dest}',
                    tmi_type=TMIType.MINIT,
                    fix=fix,
                    destinations=[dest] if dest not in ['ALL', 'ANY'] else destinations,
                    value=value,
                    unit='min',
                    provider=provider,
                    requestor=requestor,
                    start_utc=start_time or event_start,
                    end_utc=end_time or event_end,
                    cancelled_utc=cancelled_utc
                )

        # Parse CFR restriction
        # Format: "JFK via ALL CFR VOLUME:VOLUME 0000-0400 ZDC:PCT"
        elif line_type == 'cfr':
            clean_line = re.sub(r'^\d{2}/\d{4}\s+', '', line).strip()

            cfr_match = re.match(
                r'(\S+)\s+via\s+(\S+)\s+CFR\b',
                clean_line, re.IGNORECASE
            )
            if cfr_match:
                dest = cfr_match.group(1).upper()
                fix = cfr_match.group(2).upper()
                start_time, end_time = parse_ntml_time_range(line, event_date)
                requestor, provider = parse_facilities(line)

                # Handle multi-destination format like "JFK, LGA, BOS via CLT"
                if ',' in dest:
                    dests = [d.strip().upper() for d in dest.split(',')]
                else:
                    dests = [dest] if dest not in ['ALL', 'ANY'] else destinations

                tmi = TMI(
                    tmi_id=f'CFR_{fix}',
                    tmi_type=TMIType.CFR,
                    fix=fix if fix not in ['ALL', 'ANY'] else None,
                    destinations=dests,
                    provider=provider,
                    requestor=requestor,
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
                    r'(\w+)\s+via\s+(\w+)\s+(\d+)\s*(MIT|MINIT)\s*(?:AS\s+ONE\s+)?(\w+)?:?(\w+)?\s*(\d{4})Z?\s*-\s*(\d{4})Z?',
                    line, re.IGNORECASE
                )
                if mit_match:
                    dest = mit_match.group(1).upper()
                    fix = mit_match.group(2).upper()
                    value = int(mit_match.group(3))
                    tmi_type = TMIType.MIT if mit_match.group(4).upper() == 'MIT' else TMIType.MINIT
                    requestor = mit_match.group(5) or ''
                    provider = mit_match.group(6) or ''
                    start_time = parse_time(mit_match.group(7), event_date)
                    end_time = parse_time(mit_match.group(8), event_date)

                    tmi = TMI(
                        tmi_id=f'{tmi_type.value}_{fix}_{fix}',
                        tmi_type=tmi_type,
                        fix=fix,
                        destinations=[dest] if dest not in ['ALL', 'ANY'] else destinations,
                        value=value,
                        unit='nm' if tmi_type == TMIType.MIT else 'min',
                        provider=provider,
                        requestor=requestor,
                        start_utc=start_time,
                        end_utc=end_time,
                        cancelled_utc=cancelled_utc
                    )

            # Old APREQ/CFR pattern: "DEST via FIX CFR 2359-0400 REQ:PROV"
            if not tmi:
                apreq_match = re.match(
                    r'(\w+)\s+via\s+(\w+)\s+(APREQ|CFR)\s*(\d{4})Z?\s*-\s*(\d{4})Z?\s*(\w+)?:?(\w+)?',
                    line, re.IGNORECASE
                )
                if apreq_match:
                    dest = apreq_match.group(1).upper()
                    fix = apreq_match.group(2).upper()
                    tmi_type = TMIType.APREQ if apreq_match.group(3).upper() == 'APREQ' else TMIType.CFR
                    start_time = parse_time(apreq_match.group(4), event_date)
                    end_time = parse_time(apreq_match.group(5), event_date)
                    requestor = apreq_match.group(6) or ''
                    provider = apreq_match.group(7) or ''

                    tmi = TMI(
                        tmi_id=f'{tmi_type.value}_{fix}',
                        tmi_type=tmi_type,
                        fix=fix if fix not in ['ALL', 'ANY'] else None,
                        destinations=[dest] if dest not in ['ALL', 'ANY'] else destinations,
                        provider=provider,
                        requestor=requestor,
                        start_utc=start_time,
                        end_utc=end_time,
                        cancelled_utc=cancelled_utc
                    )

        if tmi:
            tmis.append(tmi)
            logger.debug(f"Parsed TMI: {tmi.tmi_type.value} via {tmi.fix} for {tmi.destinations}")
        elif line_type == 'unknown':
            skipped_lines.append(('unparsed', line))

    # Log summary of skipped lines
    if skipped_lines:
        type_counts = {}
        for line_type, _ in skipped_lines:
            type_counts[line_type] = type_counts.get(line_type, 0) + 1
        logger.info(f"Skipped {len(skipped_lines)} non-TMI lines: {type_counts}")

    logger.info(f"Parsed {len(tmis)} TMIs from NTML text")
    return tmis
