"""
ATIS Text Parser for Runway Assignments

Parses ATIS text to extract runway assignments and approach types.
Based on parsing logic from https://github.com/leftos/vatsim_control_recs

Supports multiple ATIS formats:
- US format: "LDG RWY 27L", "DEP RWY 28R"
- Compound: "LDG/DEPTG 4/8", "LDG AND DEPTG RWY 27"
- Australian: "RWY 03 FOR ARR"
- Approach types: "ILS RWY 22R", "EXPECT RNAV APPROACH RWY 35L"
- Simultaneous: "SIMUL DEPARTURES RWYS 24 AND 25"
"""

import re
from dataclasses import dataclass
from typing import Optional


@dataclass
class RunwayAssignment:
    """Represents a runway assignment from ATIS"""
    runway_id: str          # e.g., "27L", "09R", "04"
    runway_use: str         # "ARR", "DEP", or "BOTH"
    approach_type: Optional[str] = None  # "ILS", "RNAV", "VISUAL", etc.

    def to_dict(self) -> dict:
        return {
            'runway_id': self.runway_id,
            'runway_use': self.runway_use,
            'approach_type': self.approach_type
        }


# Regex patterns for runway numbers
# Matches: 27, 27L, 27R, 27C, 9L, 09R, etc.
RUNWAY_NUMBER_PATTERN = r'\b([0-3]?\d)\s*(L(?:EFT)?|R(?:IGHT)?|C(?:ENTER)?)?(?:\s*(?:AND|,|/)\s*([0-3]?\d)?\s*(L(?:EFT)?|R(?:IGHT)?|C(?:ENTER)?)?)*\b'

# Keywords for landing operations
LANDING_KEYWORDS = r'(?:LAND(?:ING)?|LDG|LNDG|ARR(?:IV(?:ING|AL))?|APPROACH(?:ES)?|EXPECT(?:ING)?)'

# Keywords for departure operations
DEPARTURE_KEYWORDS = r'(?:DEP(?:ART(?:ING|URE)?)?|DEPTG|DEPG|DPTG|TKOF|TAKE\s*OFF|DEPARTING)'

# Keywords for combined operations
COMBINED_KEYWORDS = r'(?:LDG\s*(?:AND|/|&)\s*(?:DEP(?:TG)?|DPTG)|(?:DEP(?:TG)?|DPTG)\s*(?:AND|/|&)\s*LDG|ALL\s*(?:OPERATIONS?|OPS))'

# Approach type keywords
APPROACH_TYPES = r'(?:ILS|RNAV|GPS|RNP|VISUAL|VOR|NDB|LOC|LDA|SDF|TACAN|PAR|ASR|CIRCLING|CAT\s*(?:I{1,3}|II?I?(?:\s*B)?)|AUTOLAND)'


def _normalize_runway_designator(number: str, suffix: str = '') -> str:
    """
    Normalize runway designator to standard format.

    Args:
        number: Runway number (e.g., "9", "27", "04")
        suffix: Optional suffix (e.g., "L", "LEFT", "R", "RIGHT", "C", "CENTER")

    Returns:
        Normalized designator like "09L", "27R", "04C"
    """
    # Pad single digits with zero
    num = number.zfill(2) if len(number) == 1 else number

    # Normalize suffix
    if suffix:
        suffix_upper = suffix.upper()
        if suffix_upper.startswith('L'):
            return f"{num}L"
        elif suffix_upper.startswith('R'):
            return f"{num}R"
        elif suffix_upper.startswith('C'):
            return f"{num}C"

    return num


def _extract_runway_numbers(text: str) -> list[str]:
    """
    Extract runway numbers from text fragment.

    Handles formats like:
    - "27L"
    - "27 LEFT"
    - "4 AND 8"
    - "17R AND LEFT" (meaning 17R and 17L)
    - "26L, 27R"
    - "16L/17R"

    Returns:
        List of normalized runway designators
    """
    runways = []
    text = text.upper().strip()

    # Remove common prefixes
    text = re.sub(r'^(?:RWY?S?|RUNWAY?S?)\s*', '', text)

    # Pattern for individual runway
    pattern = r'([0-3]?\d)\s*(L(?:EFT)?|R(?:IGHT)?|C(?:ENTER)?)?'

    # Split on separators
    parts = re.split(r'\s*(?:AND|,|/|&)\s*', text)

    last_number = None
    for part in parts:
        part = part.strip()
        if not part:
            continue

        match = re.match(pattern, part)
        if match:
            number = match.group(1)
            suffix = match.group(2) or ''
            runway = _normalize_runway_designator(number, suffix)
            runways.append(runway)
            last_number = number
        elif last_number and re.match(r'^(L(?:EFT)?|R(?:IGHT)?|C(?:ENTER)?)$', part):
            # Handle "17R AND LEFT" -> 17R and 17L
            runway = _normalize_runway_designator(last_number, part)
            runways.append(runway)

    return list(dict.fromkeys(runways))  # Dedupe while preserving order


def filter_atis_text(atis_text: str) -> str:
    """
    Filter ATIS text to remove METAR/weather data before parsing.

    The METAR portion can contain runway-like numbers (visibility, wind direction)
    that could be falsely matched as runway assignments.
    """
    if not atis_text:
        return ''

    text = atis_text.upper()

    # For METAR-style ATIS (starts with ATIS/INFO followed by METAR),
    # keep the whole text but just remove weather elements
    # Don't truncate at METAR since runway info may come after

    # Only truncate at actual inline weather report markers (not header METAR)
    late_metar_markers = [
        r'\.\s*(?:METAR|SPECI)\s+\d{6}Z',  # ". METAR 070820Z" inline METAR
        r'\s+RMK\s+',                       # Remarks section
        r'\s+NOSIG\s*$',                    # No significant changes
        r'\s+BECMG\s+',                     # Becoming
        r'\s+TEMPO\s+',                     # Temporary
    ]

    for marker in late_metar_markers:
        match = re.search(marker, text)
        if match:
            text = text[:match.start()] + ' '

    # Remove altimeter settings (A2992, QNH 1013)
    text = re.sub(r'\b[AQ]\s*\d{4}\b', '', text)

    # Remove temperature/dewpoint (15/12, M02/M05)
    text = re.sub(r'\bM?\d{2}/M?\d{2}\b', '', text)

    # Remove visibility (10SM, P6SM, 9999) - but be careful with runway numbers
    text = re.sub(r'\b(?:P?\d+SM)\b', '', text)  # Keep 4-digit for safety

    # Remove wind (27015KT, 27015G25KT, VRB05KT)
    text = re.sub(r'\b(?:VRB|\d{3})\d{2}(?:G\d{2})?KT\b', '', text)

    return text


def parse_runway_assignments(atis_text: str) -> tuple[set[str], set[str]]:
    """
    Parse ATIS text to extract landing and departing runway assignments.

    Args:
        atis_text: Full ATIS text

    Returns:
        Tuple of (landing_runways, departing_runways) as sets of runway designators
    """
    landing_runways: set[str] = set()
    departing_runways: set[str] = set()

    if not atis_text:
        return landing_runways, departing_runways

    text = filter_atis_text(atis_text).upper()

    # Pattern 1: Compound operations - "LDG/DEPTG RWY 27" or "LDG AND DEPTG 4/8"
    compound_pattern = rf'{COMBINED_KEYWORDS}\s+(?:RWY?S?\s+)?(.+?)(?:\.|,|$)'
    for match in re.finditer(compound_pattern, text, re.IGNORECASE):
        runways = _extract_runway_numbers(match.group(1))
        landing_runways.update(runways)
        departing_runways.update(runways)

    # Pattern 2: Landing operations
    landing_patterns = [
        rf'{LANDING_KEYWORDS}\s+(?:RWY?S?\s+)?([0-3]?\d[LRC]?(?:\s*(?:AND|,|/)\s*[0-3]?\d?[LRC]?)*)',
        rf'{LANDING_KEYWORDS}\s+(?:RWY?S?\s+)?(.+?)(?:\s+(?:DEP|FOR|SIMUL)|[.,]|$)',
    ]

    for pattern in landing_patterns:
        for match in re.finditer(pattern, text, re.IGNORECASE):
            runway_text = match.group(1)
            if runway_text and len(runway_text) < 50:  # Sanity check
                runways = _extract_runway_numbers(runway_text)
                landing_runways.update(runways)

    # Pattern 3: Departure operations
    departure_patterns = [
        rf'{DEPARTURE_KEYWORDS}\s+(?:RWY?S?\s+)?([0-3]?\d[LRC]?(?:\s*(?:AND|,|/)\s*[0-3]?\d?[LRC]?)*)',
        rf'{DEPARTURE_KEYWORDS}\s+(?:RWY?S?\s+)?(.+?)(?:\s+(?:ARR|FOR|SIMUL)|[.,]|$)',
    ]

    for pattern in departure_patterns:
        for match in re.finditer(pattern, text, re.IGNORECASE):
            runway_text = match.group(1)
            if runway_text and len(runway_text) < 50:
                runways = _extract_runway_numbers(runway_text)
                departing_runways.update(runways)

    # Pattern 4: Australian format - "RWY 03 FOR ARR/DEP"
    aussie_pattern = r'RWY?\s+([0-3]?\d[LRC]?)\s+(?:FOR|IN\s+USE\s+FOR)\s+(ARR(?:IVAL)?S?|DEP(?:ARTURE)?S?|BOTH)'
    for match in re.finditer(aussie_pattern, text, re.IGNORECASE):
        runways = _extract_runway_numbers(match.group(1))
        use_type = match.group(2).upper()
        if use_type.startswith('ARR'):
            landing_runways.update(runways)
        elif use_type.startswith('DEP'):
            departing_runways.update(runways)
        else:  # BOTH
            landing_runways.update(runways)
            departing_runways.update(runways)

    # Pattern 5: Simultaneous operations
    simul_pattern = r'SIMUL(?:TANEOUS)?\s+(ARR(?:IVAL)?S?|DEP(?:ARTURE)?S?)\s+(?:RWY?S?\s+)?(.+?)(?:\.|,|$)'
    for match in re.finditer(simul_pattern, text, re.IGNORECASE):
        use_type = match.group(1).upper()
        runways = _extract_runway_numbers(match.group(2))
        if use_type.startswith('ARR'):
            landing_runways.update(runways)
        else:
            departing_runways.update(runways)

    # Pattern 6: European "RUNWAY(S) IN USE" format
    # e.g., "RUNWAY IN USE 22", "RUNWAYS IN USE 25R AND 25L"
    in_use_pattern = r'(?:RUNWAY?S?|RWY)\s+(?:IN\s+USE|ACTIVE)\s+([0-3]?\d[LRC]?(?:\s*(?:AND|,|/)\s*[0-3]?\d?[LRC]?)*)'
    for match in re.finditer(in_use_pattern, text, re.IGNORECASE):
        runways = _extract_runway_numbers(match.group(1))
        # Check context for arrival/departure indication
        context_start = max(0, match.start() - 100)
        context = text[context_start:match.start()].upper()
        if 'ARRIVAL' in context or 'ARR ' in context:
            landing_runways.update(runways)
        elif 'DEPARTURE' in context or 'DEP ' in context:
            departing_runways.update(runways)
        else:
            # Default to both if no context
            landing_runways.update(runways)
            departing_runways.update(runways)

    # Pattern 7: "RWY/RUNWAY XX IN USE" or "ILS RWY XX IN USE" (Scandinavian/European)
    rwy_in_use_pattern = r'(?:ILS\s+)?(?:RWY|RUNWAY)\s+([0-3]?\d[LRC]?)\s+IN\s+USE'
    for match in re.finditer(rwy_in_use_pattern, text, re.IGNORECASE):
        runways = _extract_runway_numbers(match.group(1))
        # Check context for arrival/departure indication
        context_start = max(0, match.start() - 100)
        context = text[context_start:match.start()].upper()
        is_arrival_context = 'ILS' in match.group(0).upper() or 'ARRIVAL' in context or 'APCH' in context
        is_departure_context = 'DEPARTURE' in context

        if is_arrival_context and not is_departure_context:
            landing_runways.update(runways)
        elif is_departure_context and not is_arrival_context:
            departing_runways.update(runways)
        else:
            landing_runways.update(runways)
            departing_runways.update(runways)

    # Pattern 8: Australian bracket format "[RWY] 11" - infer from context
    bracket_pattern = r'\[RWY\]\s*([0-3]?\d[LRC]?)'
    for match in re.finditer(bracket_pattern, text, re.IGNORECASE):
        runways = _extract_runway_numbers(match.group(1))
        # Check context for arrival/departure indication
        context_start = max(0, match.start() - 50)
        context = text[context_start:match.start()].upper()
        if 'ARR' in context or 'APCH' in context or 'APPROACH' in context:
            landing_runways.update(runways)
        elif 'DEP' in context or 'TKOF' in context:
            departing_runways.update(runways)
        else:
            # Default to both if no context
            landing_runways.update(runways)
            departing_runways.update(runways)

    # Pattern 9: "LDG ... AND DPTG RWY" combined pattern (Vietnamese style)
    combined_ldg_dptg = r'LDG\s+(?:RWY\s+)?([0-3]?\d[LRC]?)\s+AND\s+DPT?G\s+(?:RWY\s+)?([0-3]?\d[LRC]?)'
    for match in re.finditer(combined_ldg_dptg, text, re.IGNORECASE):
        landing_rwys = _extract_runway_numbers(match.group(1))
        departing_rwys = _extract_runway_numbers(match.group(2))
        landing_runways.update(landing_rwys)
        departing_runways.update(departing_rwys)

    # Pattern 10: Middle East "ARRDEP RWYXX" or "ARR DEP RWY XX"
    arrdep_pattern = r'ARR(?:\s+)?DEP\s+(?:RWY\s*)?([0-3]?\d[LRC]?(?:\s*(?:RWY\s*)?[0-3]?\d?[LRC]?)*)'
    for match in re.finditer(arrdep_pattern, text, re.IGNORECASE):
        runways = _extract_runway_numbers(match.group(1))
        landing_runways.update(runways)
        departing_runways.update(runways)

    # Pattern 11: "ARRS EXP ... APPR. RWY XX IN USE" (Philippines)
    arrs_exp_pattern = r'ARRS?\s+EXP(?:ECT)?\s+(?:.*?)\s+RWY\s+([0-3]?\d[LRC]?)\s+(?:APPR?|APPROACH)'
    for match in re.finditer(arrs_exp_pattern, text, re.IGNORECASE):
        runways = _extract_runway_numbers(match.group(1))
        landing_runways.update(runways)

    # Pattern 12: "RWY XX AND RWY YY IN USE" (multiple runways)
    multi_rwy_pattern = r'RWY\s+([0-3]?\d[LRC]?)\s+(?:AND\s+)?RWY\s+([0-3]?\d[LRC]?)\s+IN\s+USE'
    for match in re.finditer(multi_rwy_pattern, text, re.IGNORECASE):
        runways1 = _extract_runway_numbers(match.group(1))
        runways2 = _extract_runway_numbers(match.group(2))
        landing_runways.update(runways1)
        landing_runways.update(runways2)
        departing_runways.update(runways1)
        departing_runways.update(runways2)

    # Pattern 13: "SIMUL ... IN USE RWY XX" (US simultaneous operations)
    simul_in_use_pattern = r'SIMUL(?:TANEOUS)?\s+(?:VIS\s+)?(?:APCHS?|APPROACHES?|DEPS?|DEPARTURES?)\s+IN\s+USE\s+(?:RWY\s+)?([0-3]?\d[LRC]?(?:\s*[,/]?\s*[0-3]?\d?[LRC]?)*)'
    for match in re.finditer(simul_in_use_pattern, text, re.IGNORECASE):
        runways = _extract_runway_numbers(match.group(1))
        matched_text = match.group(0).upper()
        if 'APCH' in matched_text or 'APPROACH' in matched_text:
            landing_runways.update(runways)
        elif 'DEP' in matched_text:
            departing_runways.update(runways)
        else:
            landing_runways.update(runways)
            departing_runways.update(runways)

    # Pattern 14: "EXPECT ... APPROACH RUNWAY XX" (general approach expect)
    expect_apch_pattern = r'EXPECT\s+(?:.*?)\s+APPROACH\s+RUNWAY\s+([0-3]?\d[LRC]?(?:\s*(?:AND\s+)?[0-3]?\d?[LRC]?)*)'
    for match in re.finditer(expect_apch_pattern, text, re.IGNORECASE):
        runways = _extract_runway_numbers(match.group(1))
        landing_runways.update(runways)

    # Pattern 15: "DEPARTURE RUNWAY XX" explicit
    dep_rwy_pattern = r'DEPARTURE\s+RUNWAY\s+([0-3]?\d[LRC]?)'
    for match in re.finditer(dep_rwy_pattern, text, re.IGNORECASE):
        runways = _extract_runway_numbers(match.group(1))
        departing_runways.update(runways)

    # Pattern 16: "FOR ARRIVALS AND DEPARTURES" with preceding runway
    for_arr_dep_pattern = r'(?:RUNWAY|RWY)\s+([0-3]?\d[LRC]?)\s+FOR\s+(?:ARRIVALS?\s+AND\s+DEPARTURES?|ARR(?:IVAL)?S?\s+AND\s+DEP(?:ARTURE)?S?)'
    for match in re.finditer(for_arr_dep_pattern, text, re.IGNORECASE):
        runways = _extract_runway_numbers(match.group(1))
        landing_runways.update(runways)
        departing_runways.update(runways)

    # Pattern 17: "EXPECT RADAR VECTORS RWY XX"
    vectors_pattern = r'EXPECT\s+(?:RADAR\s+)?VECTORS?\s+(?:FOR\s+)?(?:.*?)\s*RWY\s+([0-3]?\d[LRC]?)'
    for match in re.finditer(vectors_pattern, text, re.IGNORECASE):
        runways = _extract_runway_numbers(match.group(1))
        landing_runways.update(runways)

    return landing_runways, departing_runways


def parse_approach_info(atis_text: str) -> dict[str, list[str]]:
    """
    Parse ATIS text to extract approach types for each runway.

    Args:
        atis_text: Full ATIS text

    Returns:
        Dictionary mapping runway designator to list of approach types
        e.g., {"27L": ["ILS", "RNAV"], "27R": ["VISUAL"]}
    """
    approaches: dict[str, list[str]] = {}

    if not atis_text:
        return approaches

    text = filter_atis_text(atis_text).upper()

    # Pattern: "ILS RWY 27L" or "EXPECT ILS APPROACH RWY 35L"
    approach_pattern = rf'(?:EXPECT\s+)?({APPROACH_TYPES})\s+(?:APPROACH(?:ES)?\s+)?(?:RWY?S?\s+)?([0-3]?\d[LRC]?)'

    for match in re.finditer(approach_pattern, text, re.IGNORECASE):
        approach_type = match.group(1).upper().strip()
        runway = _normalize_runway_designator(
            re.match(r'(\d+)', match.group(2)).group(1),
            re.search(r'([LRC])', match.group(2)).group(1) if re.search(r'([LRC])', match.group(2)) else ''
        )

        # Normalize approach type
        approach_type = re.sub(r'\s+', ' ', approach_type)

        if runway not in approaches:
            approaches[runway] = []
        if approach_type not in approaches[runway]:
            approaches[runway].append(approach_type)

    return approaches


def parse_full_runway_info(atis_text: str) -> list[RunwayAssignment]:
    """
    Parse ATIS text to extract complete runway assignment information.

    Combines runway use with approach type information.

    Args:
        atis_text: Full ATIS text

    Returns:
        List of RunwayAssignment objects
    """
    landing, departing = parse_runway_assignments(atis_text)
    approaches = parse_approach_info(atis_text)

    assignments: list[RunwayAssignment] = []
    all_runways = landing | departing

    for runway in all_runways:
        # Determine use type
        is_landing = runway in landing
        is_departing = runway in departing

        if is_landing and is_departing:
            use = 'BOTH'
        elif is_landing:
            use = 'ARR'
        else:
            use = 'DEP'

        # Get approach type if available (only for arrivals)
        approach_type = None
        if is_landing and runway in approaches:
            # Take the first/primary approach type
            approach_type = approaches[runway][0] if approaches[runway] else None

        assignments.append(RunwayAssignment(
            runway_id=runway,
            runway_use=use,
            approach_type=approach_type
        ))

    return assignments


def format_runway_summary(landing: set[str], departing: set[str]) -> str:
    """
    Format runway assignments as a summary string.

    Args:
        landing: Set of landing runway designators
        departing: Set of departing runway designators

    Returns:
        Formatted string like "L:27L,28L D:27R,28R"
    """
    landing_str = ','.join(sorted(landing)) if landing else '-'
    departing_str = ','.join(sorted(departing)) if departing else '-'
    return f"L:{landing_str} D:{departing_str}"


# === Testing ===

if __name__ == '__main__':
    # Test cases
    test_cases = [
        "JFK ATIS INFO A. LDG RWY 13L AND 13R. DEP RWY 13L AND 31L.",
        "LAX ATIS B. ILS RWY 25L. VISUAL APPROACH RWY 24R. DEPTG RWYS 25R AND 24L.",
        "ORD INFO C. LNDG RUNWAYS 10L 10C 10R. DEPARTING RWYS 10C 28R.",
        "LDG/DEPTG RWY 27. EXPECT ILS APPROACH.",
        "RWY 03 FOR ARR. RWY 21 FOR DEP.",
        "SIMUL DEPARTURES RWYS 24 AND 25.",
        "LANDING RWY 17R AND LEFT. DEPARTING 17L.",
    ]

    print("ATIS Parser Test Results")
    print("=" * 60)

    for atis in test_cases:
        print(f"\nATIS: {atis[:60]}...")
        landing, departing = parse_runway_assignments(atis)
        approaches = parse_approach_info(atis)
        print(f"  Landing:   {sorted(landing) if landing else 'None'}")
        print(f"  Departing: {sorted(departing) if departing else 'None'}")
        if approaches:
            print(f"  Approaches: {approaches}")
        print(f"  Summary: {format_runway_summary(landing, departing)}")
