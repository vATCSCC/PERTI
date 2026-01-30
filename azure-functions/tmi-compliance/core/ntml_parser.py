"""
TMI Compliance Analyzer - NTML Parser
======================================

Parses NTML (National Traffic Management Log) text into TMI objects.
"""

import re
from datetime import datetime, timedelta
from typing import List

from .models import TMI, TMIType


def parse_ntml_to_tmis(ntml_text: str, event_start: datetime, event_end: datetime, destinations: List[str]) -> List[TMI]:
    """
    Parse NTML text into TMI objects.

    Supported formats:
    - MIT: "LAS via FLCHR 20MIT ZLA:ZOA 2359Z-0400Z"
    - MINIT: "BNA via GROAT 5MINIT ZME:ZID 0000Z-0400Z"
    - GS: "LAS GS (NCT) 0230Z-0315Z issued 0244Z"
    - APREQ/CFR: "BNA via ALL CFR 2359-0400 ZME:ZME"
    - Cancellations: "CXLD 0330Z" at end of line
    """
    tmis = []
    lines = ntml_text.strip().split('\n')

    # Get event date for parsing times
    event_date = event_start.date()

    def parse_time(time_str: str, base_date) -> datetime:
        """Parse HHMM or HH:MM format time, adjusting date for overnight"""
        time_str = time_str.replace(':', '').replace('Z', '').strip()
        if len(time_str) == 4:
            hour = int(time_str[:2])
            minute = int(time_str[2:])
            result = datetime(base_date.year, base_date.month, base_date.day, hour, minute)
            # If time < event start time, it's likely next day
            if result < event_start - timedelta(hours=2):
                result = result + timedelta(days=1)
            return result
        return None

    for line in lines:
        line = line.strip()
        if not line:
            continue

        tmi = None
        cancelled_utc = None

        # Check for cancellation at end of line
        cxld_match = re.search(r'CXLD?\s*(\d{4})Z?', line, re.IGNORECASE)
        if cxld_match:
            cancelled_utc = parse_time(cxld_match.group(1), event_date)
            # Remove cancellation text for further parsing
            line = re.sub(r'CXLD?\s*\d{4}Z?', '', line, flags=re.IGNORECASE).strip()

        # MIT/MINIT pattern: "DEST via FIX 20MIT REQ:PROV 2359Z-0400Z"
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

        # Ground Stop pattern: "DEST GS (SCOPE) 0230Z-0315Z issued 0244Z"
        gs_match = re.match(
            r'(\w+)\s+GS\s*\(([^)]+)\)\s*(\d{4})Z?\s*-\s*(\d{4})Z?(?:\s*issued\s*(\d{4})Z?)?',
            line, re.IGNORECASE
        )
        if gs_match and not tmi:
            dest = gs_match.group(1).upper()
            scope = gs_match.group(2).strip()
            start_time = parse_time(gs_match.group(3), event_date)
            end_time = parse_time(gs_match.group(4), event_date)
            issued_time = parse_time(gs_match.group(5), event_date) if gs_match.group(5) else start_time

            tmi = TMI(
                tmi_id=f'GS_{scope}_{dest}_ALL',
                tmi_type=TMIType.GS,
                destinations=[dest] if dest not in ['ALL', 'ANY'] else destinations,
                origins=[],  # Will need to be populated based on scope
                provider=scope,
                requestor=dest,
                start_utc=start_time,
                end_utc=end_time,
                issued_utc=issued_time,
                cancelled_utc=cancelled_utc,
                reason=f'Ground Stop from {scope}'
            )

        # APREQ/CFR pattern: "DEST via FIX CFR 2359-0400 REQ:PROV"
        apreq_match = re.match(
            r'(\w+)\s+via\s+(\w+)\s+(APREQ|CFR)\s*(\d{4})Z?\s*-\s*(\d{4})Z?\s*(\w+)?:?(\w+)?',
            line, re.IGNORECASE
        )
        if apreq_match and not tmi:
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

    return tmis
