"""
TMI Compliance Analyzer - NTML Parser
======================================

Parses NTML (National Traffic Management Log) text into TMI objects.

Supported formats:
- MIT: "LAS via FLCHR 20MIT ZLA:ZOA 2359Z-0400Z"
- MIT with qualifiers: "ATL via EMI 25 MIT VOLUME:VOLUME 0000-0400 ZDC:ZNY"
- MIT with application: "BNA via GROAT 20 MIT AS ONE 0000-0400 ZME:ZID"
- MINIT: "BNA via GROAT 5MINIT ZME:ZID 0000Z-0400Z"
- GS: "LAS GS (NCT) 0230Z-0315Z issued 0244Z"
- APREQ/CFR: "BNA via ALL CFR 2359-0400 ZME:ZME"
- Cancellations: "CXLD 0330Z" at end of line
- Exclusions: "EXCL PROPS", "EXCL VFR" etc.
"""

import re
import logging
from datetime import datetime, timedelta
from typing import List, Optional, Tuple, Dict

from .models import TMI, TMIType

logger = logging.getLogger(__name__)


def parse_ntml_to_tmis(ntml_text: str, event_start: datetime, event_end: datetime, destinations: List[str]) -> List[TMI]:
    """Parse NTML text into TMI objects."""
    tmis = []
    lines = ntml_text.strip().split('\n')
    event_date = event_start.date()

    def parse_time(time_str: str, base_date) -> Optional[datetime]:
        """Parse HHMM or HH:MM format time, adjusting date for overnight"""
        if not time_str:
            return None
        time_str = time_str.replace(':', '').replace('Z', '').strip()
        if len(time_str) >= 4:
            try:
                hour = int(time_str[:2])
                minute = int(time_str[2:4])
                result = datetime(base_date.year, base_date.month, base_date.day, hour, minute)
                # If time < event start time, it's likely next day
                if result < event_start - timedelta(hours=2):
                    result = result + timedelta(days=1)
                return result
            except (ValueError, IndexError):
                return None
        return None

    def extract_modifiers(text: str) -> Dict[str, any]:
        """Extract qualifiers, conditions, exclusions from NTML text"""
        mods = {
            'application': '',
            'impacting_condition': '',
            'exclusions': [],
            'tier': '',
            'aircraft_type': '',
            'altitude_restriction': ''
        }

        # Application type: AS ONE, PER STREAM, PER FIX
        app_match = re.search(r'(AS\s+ONE|PER\s+STREAM|PER\s+FIX)', text, re.IGNORECASE)
        if app_match:
            mods['application'] = app_match.group(1).upper().replace('  ', ' ')

        # Aircraft type: JETS ONLY, PROPS ONLY, HEAVIES, TURBOPROPS, etc.
        acft_match = re.search(r'(JETS|PROPS|HEAVIES|TURBOPROPS|TURBO)\s*(ONLY)?', text, re.IGNORECASE)
        if acft_match:
            mods['aircraft_type'] = acft_match.group(0).upper()

        # Altitude restriction: AOB270, AAB240, AOA310, etc.
        alt_match = re.search(r'(AOB|AAB|AOA|ABA)\s*(\d{2,3})', text, re.IGNORECASE)
        if alt_match:
            mods['altitude_restriction'] = alt_match.group(1).upper() + alt_match.group(2)

        # Impacting condition patterns:
        # - VOLUME:VOLUME, HEAVY:HEAVY (4+ letter codes)
        # - WX:LOVIS, WX:LOCIGS, WX:LOVIS/LOCIGS (weather)
        cond_match = re.search(r'(VOLUME|HEAVY|DEMAND|CAPACITY):(VOLUME|HEAVY|DEMAND|CAPACITY)', text, re.IGNORECASE)
        if cond_match:
            mods['impacting_condition'] = cond_match.group(0).upper()
        else:
            # Weather condition: WX:something
            wx_match = re.search(r'WX:(\S+)', text, re.IGNORECASE)
            if wx_match:
                mods['impacting_condition'] = 'WX:' + wx_match.group(1).upper()

        # Exclusions - multiple formats:
        # - EXCL PROPS, EXCL VFR
        # - EXCL:PCT LTFC, EXCL:LIFEGUARD
        excl_matches = re.findall(r'EXCL[:\s]+(\S+(?:\s+\w+)?)', text, re.IGNORECASE)
        mods['exclusions'] = [e.strip().upper() for e in excl_matches]

        # Tier
        tier_match = re.search(r'TIER\s*(\d+)', text, re.IGNORECASE)
        if tier_match:
            mods['tier'] = tier_match.group(1)

        return mods

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
            line = re.sub(r'CXLD?\s*\d{4}Z?', '', line, flags=re.IGNORECASE).strip()

        # Strip leading date/time prefix like "17/2300" or "01/2300"
        clean_line = re.sub(r'^\d{2}/\d{4}\s+', '', line)

        # =====================================================
        # MIT/MINIT PARSING - More flexible approach
        # =====================================================
        # Check if this line contains MIT or MINIT (handle duplicate "MIT MIT" typos)
        mit_type_match = re.search(r'(\d+)\s*(MIT|MINIT)(?:\s+MIT)?', clean_line, re.IGNORECASE)

        if mit_type_match and not tmi:
            value = int(mit_type_match.group(1))
            tmi_type = TMIType.MIT if 'MIT' in mit_type_match.group(2).upper() else TMIType.MINIT

            # Extract destination and fix from various patterns:
            # "DEST via FIX" or "DEST departures via FIX" or "DEST arrivals via FIX"
            dest_fix_match = re.match(
                r'(\w+)\s+(?:departures?\s+|arrivals?\s+)?via\s+([\w,/\s]+?)\s+\d+',
                clean_line, re.IGNORECASE
            )
            if dest_fix_match:
                dest = dest_fix_match.group(1).upper()
                fix_raw = dest_fix_match.group(2).strip().upper()

                # Handle multiple fixes: BARMY/KILNS or BARMY, KILNS
                if '/' in fix_raw:
                    fixes = [f.strip() for f in fix_raw.split('/')]
                elif ',' in fix_raw:
                    fixes = [f.strip() for f in fix_raw.split(',')]
                else:
                    fixes = [fix_raw]
                fix = fixes[0]  # Primary fix for analysis

                # Find time range: look for HHMM-HHMM or HHMMZ-HHMMZ pattern anywhere in line
                time_match = re.search(r'(\d{4})Z?\s*[-–]\s*(\d{4})Z?', clean_line)
                if time_match:
                    start_time = parse_time(time_match.group(1), event_date)
                    end_time = parse_time(time_match.group(2), event_date)

                    # Extract requestor:provider - look for 2-4 letter codes at end
                    # Must be 2-4 letter codes (not VOLUME, HEAVY, etc.)
                    req_prov_match = re.search(r'\b([A-Z]{2,4}):([A-Z]{2,4})\s*$', clean_line, re.IGNORECASE)
                    if not req_prov_match:
                        # Try just before end, after times
                        req_prov_match = re.search(r'(\d{4})\s+([A-Z]{2,4}):([A-Z]{2,4})', clean_line, re.IGNORECASE)
                        if req_prov_match:
                            requestor = req_prov_match.group(2).upper()
                            provider = req_prov_match.group(3).upper()
                        else:
                            requestor = ''
                            provider = ''
                    else:
                        requestor = req_prov_match.group(1).upper()
                        provider = req_prov_match.group(2).upper()

                    # Don't use impacting conditions as req:prov
                    if requestor.upper() in ['VOLUME', 'HEAVY', 'DEMAND', 'CAPACITY', 'NONE']:
                        requestor = ''
                        provider = ''

                    # Extract all modifiers from the line
                    mods = extract_modifiers(clean_line)

                    if start_time and end_time:
                        tmi = TMI(
                            tmi_id=f'{tmi_type.value}_{fix}_{dest}',
                            tmi_type=tmi_type,
                            fix=fix,
                            fixes=fixes if len(fixes) > 1 else [],
                            destinations=[dest] if dest not in ['ALL', 'ANY'] else destinations,
                            value=value,
                            unit='nm' if tmi_type == TMIType.MIT else 'min',
                            provider=provider,
                            requestor=requestor,
                            start_utc=start_time,
                            end_utc=end_time,
                            cancelled_utc=cancelled_utc,
                            application=mods['application'],
                            impacting_condition=mods['impacting_condition'],
                            exclusions=mods['exclusions'],
                            tier=mods['tier'],
                            aircraft_type=mods['aircraft_type'],
                            altitude_restriction=mods['altitude_restriction']
                        )
                        logger.info(f"Parsed MIT/MINIT: {fix} {value} {tmi_type.value} {start_time}-{end_time} [{mods.get('impacting_condition', '')}]")
                    else:
                        logger.warning(f"Could not parse times for MIT/MINIT line: {clean_line}")

        # Ground Stop pattern: "DEST GS (SCOPE) 0230Z-0315Z issued 0244Z"
        gs_match = re.match(
            r'(\w+)\s+GS\s*\(([^)]+)\)\s*(\d{4})Z?\s*-\s*(\d{4})Z?(?:\s*issued\s*(\d{4})Z?)?',
            clean_line, re.IGNORECASE
        )

        # Also check for ADVZY Ground Stop format:
        # "GROUND STOP PERIOD: 18/0230Z – 18/0315Z"
        if not gs_match and not tmi:
            advzy_gs_match = re.search(r'GROUND\s+STOP\s+PERIOD:\s*\d{2}/(\d{4})Z?\s*[–-]\s*\d{2}/(\d{4})Z?', line, re.IGNORECASE)
            if advzy_gs_match:
                # Look for CTL ELEMENT in nearby context (we'd need multi-line parsing for full support)
                # For now, use destinations from config
                start_time = parse_time(advzy_gs_match.group(1), event_date)
                end_time = parse_time(advzy_gs_match.group(2), event_date)

                # Try to find issued time from "ADL TIME: 0244Z"
                adl_match = re.search(r'ADL\s+TIME:\s*(\d{4})Z?', ntml_text, re.IGNORECASE)
                issued_time = parse_time(adl_match.group(1), event_date) if adl_match else start_time

                # Try to find scope from "DEP FACILITIES INCLUDED: (Manual) ZOA"
                scope_match = re.search(r'DEP\s+FACILITIES\s+INCLUDED:.*?(\w{3})', ntml_text, re.IGNORECASE)
                scope = scope_match.group(1) if scope_match else 'MANUAL'

                tmi = TMI(
                    tmi_id=f'GS_{scope}_{destinations[0] if destinations else "ALL"}_ALL',
                    tmi_type=TMIType.GS,
                    destinations=destinations,
                    origins=[],
                    provider=scope,
                    requestor=destinations[0] if destinations else 'ALL',
                    start_utc=start_time,
                    end_utc=end_time,
                    issued_utc=issued_time,
                    cancelled_utc=cancelled_utc,
                    reason=f'Ground Stop from {scope}'
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

        # APREQ/CFR pattern - more flexible to handle modifiers between CFR and times
        # Examples: "JFK via ALL CFR VOLUME:VOLUME 0000-0400 ZDC:PCT"
        #           "BNA via ALL CFR 2359-0400 ZME:ZME"
        if not tmi and re.search(r'\b(APREQ|CFR)\b', clean_line, re.IGNORECASE):
            apreq_type_match = re.search(r'\b(APREQ|CFR)\b', clean_line, re.IGNORECASE)
            dest_fix_match = re.match(r'(\w+)\s+via\s+(\w+)', clean_line, re.IGNORECASE)
            time_match = re.search(r'(\d{4})Z?\s*[-–]\s*(\d{4})Z?', clean_line)

            if dest_fix_match and time_match:
                dest = dest_fix_match.group(1).upper()
                fix = dest_fix_match.group(2).upper()
                tmi_type = TMIType.APREQ if apreq_type_match.group(1).upper() == 'APREQ' else TMIType.CFR
                start_time = parse_time(time_match.group(1), event_date)
                end_time = parse_time(time_match.group(2), event_date)

                # Extract requestor:provider at end
                req_prov_match = re.search(r'\b([A-Z]{2,4}):([A-Z]{2,4})\s*$', clean_line, re.IGNORECASE)
                if req_prov_match:
                    requestor = req_prov_match.group(1).upper()
                    provider = req_prov_match.group(2).upper()
                    # Don't use impacting conditions as req:prov
                    if requestor in ['VOLUME', 'HEAVY', 'DEMAND', 'CAPACITY']:
                        requestor = ''
                        provider = ''
                else:
                    requestor = ''
                    provider = ''

                mods = extract_modifiers(clean_line)

                if start_time and end_time:
                    tmi = TMI(
                        tmi_id=f'{tmi_type.value}_{fix}_{dest}',
                        tmi_type=tmi_type,
                        fix=fix if fix not in ['ALL', 'ANY'] else None,
                        destinations=[dest] if dest not in ['ALL', 'ANY'] else destinations,
                        provider=provider,
                        requestor=requestor,
                        start_utc=start_time,
                        end_utc=end_time,
                        cancelled_utc=cancelled_utc,
                        impacting_condition=mods['impacting_condition']
                    )
                    logger.info(f"Parsed APREQ/CFR: {dest} via {fix} {tmi_type.value} {start_time}-{end_time}")

        if tmi:
            tmis.append(tmi)

    return tmis
