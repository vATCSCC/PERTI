"""
ATIS Text Parser for Runway Assignments

Parses ATIS text to extract runway assignments and approach types.

Supports multiple ATIS formats:
- US format: "LDG RWY 27L", "DEP RWY 28R"
- Compound: "LDG/DEPTG 4/8", "LDG AND DEPTG RWY 27"
- Australian: "RWY 03 FOR ARR"
- Approach types: "ILS RWY 22R", "EXPECT RNAV APPROACH RWY 35L"
- Simultaneous: "SIMUL DEPARTURES RWYS 24 AND 25"

Improvements (v2):
- Better METAR filtering using patterns from python-metar-taf-parser
- Runway number validation (01-36 only)
- Confidence scoring for parsed results
- Negative patterns to exclude false positives from weather data
"""

import re
from dataclasses import dataclass, field
from typing import Optional


@dataclass
class RunwayAssignment:
    """Represents a runway assignment from ATIS"""
    runway_id: str          # e.g., "27L", "09R", "04"
    runway_use: str         # "ARR", "DEP", or "BOTH"
    approach_type: Optional[str] = None  # "ILS", "RNAV", "VISUAL", etc.
    confidence: int = 100   # Confidence score 0-100

    def to_dict(self) -> dict:
        return {
            'runway_id': self.runway_id,
            'runway_use': self.runway_use,
            'approach_type': self.approach_type,
            'confidence': self.confidence
        }


@dataclass
class ParseResult:
    """Result of ATIS parsing with confidence metrics"""
    landing_runways: set = field(default_factory=set)
    departing_runways: set = field(default_factory=set)
    confidence: int = 0  # Overall confidence 0-100
    match_sources: list = field(default_factory=list)  # Which patterns matched
    warnings: list = field(default_factory=list)  # Any parsing warnings


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

# =============================================================================
# METAR ELEMENT PATTERNS (from python-metar-taf-parser)
# Used to identify and remove weather data that could be confused with runways
# =============================================================================

# Wind patterns: 27015KT, 27015G25KT, VRB05KT, 000/00KT
METAR_WIND_PATTERN = r'\b(?:VRB|000|[0-3]\d{2})\d{2}(?:G\d{2,3})?(?:KT|MPS|KM/H)\b'
METAR_WIND_VARIATION = r'\b\d{3}V\d{3}\b'  # 250V310
METAR_WIND_SHEAR = r'\bWS\d{3}/\w{3}\d{2}(?:G\d{2,3})?(?:KT|MPS|KM/H)\b'

# Visibility patterns: 9999, 0400, P6SM, 10SM, 1/2SM, M1/4SM
METAR_VIS_METERS = r'\b\d{4}(?:NDV)?\b'  # 4-digit meter visibility (but not near runway context)
METAR_VIS_SM = r'\b[PM]?\d+(?:/\d+)?SM\b'  # Statute miles
METAR_VIS_DIRECTIONAL = r'\b\d{4}(?:N|NE|E|SE|S|SW|W|NW)\b'

# Altimeter patterns: A2992, Q1013, QNH1013
METAR_ALTIMETER = r'\b[AQ](?:NH)?\s?\d{4}\b'

# Temperature/dewpoint: 15/12, M02/M05, 20/M01
METAR_TEMP_DEWPOINT = r'\bM?\d{2}/M?\d{2}\b'

# Cloud patterns: FEW020, SCT035, BKN080, OVC100, VV003
METAR_CLOUDS = r'\b(?:FEW|SCT|BKN|OVC|VV|CLR|SKC|NSC|NCD|CAVOK)\d{0,3}(?:CB|TCU)?\b'

# Weather phenomena: -RA, +TSRA, VCSH, BR, FG, HZ
METAR_PHENOMENA = r'\b(?:[-+]|VC)?(?:MI|PR|BC|DR|BL|SH|TS|FZ)?(?:DZ|RA|SN|SG|IC|PL|GR|GS|UP|BR|FG|FU|VA|DU|SA|HZ|PY|PO|SQ|FC|SS|DS)+\b'

# Runway visual range: R27L/0800, R09/P2000FT
METAR_RVR = r'\bR\d{2}[LRC]?/[PM]?\d{4}(?:V\d{4})?(?:FT)?[UDN]?\b'

# Time group: 121856Z, 0718Z
METAR_TIME = r'\b\d{4,6}Z\b'

# Remarks section marker - only remove actual METAR remarks (before runway info)
# Be careful not to remove runway assignments that come after RMK
METAR_REMARKS = r'\bRMK\s+(?:AO[12]|SLP\d{3}|T\d{8}|P\d{4}|[A-Z]{2,3}\d{2,3}|\$)+(?:\s+(?:AO[12]|SLP\d{3}|T\d{8}|P\d{4}|[A-Z]{2,3}\d{2,3}|\$))*'

# Trend markers - remove forecast sections
METAR_TREND = r'\s+(?:NOSIG|BECMG\s+\S+|TEMPO\s+\S+)(?:\s+\S+)*?(?=\s+(?:LDG|ARR|DEP|RWY|LAND|RUNWAY)|$)'


def _is_valid_runway_number(runway: str) -> bool:
    """
    Validate that a runway number is valid (01-36 with optional L/R/C suffix).

    Args:
        runway: Runway designator like "27L", "09", "36R"

    Returns:
        True if valid runway number, False otherwise
    """
    match = re.match(r'^(\d{1,2})([LRC])?$', runway)
    if not match:
        return False

    num = int(match.group(1))
    # Valid runway numbers are 01-36
    return 1 <= num <= 36


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


def _extract_runway_numbers(text: str, validate: bool = True) -> list[str]:
    """
    Extract runway numbers from text fragment.

    Handles formats like:
    - "27L"
    - "27 LEFT"
    - "4 AND 8"
    - "17R AND LEFT" (meaning 17R and 17L)
    - "26L, 27R"
    - "16L/17R"
    - "10L 10C 10R" (space-separated)

    Args:
        text: Text fragment containing runway numbers
        validate: If True, only return valid runway numbers (01-36)

    Returns:
        List of normalized runway designators
    """
    runways = []
    text = text.upper().strip()

    # Remove common prefixes
    text = re.sub(r'^(?:RWY?S?|RUNWAY?S?)\s*', '', text)

    # Pattern for individual runway with optional suffix
    runway_pattern = r'([0-3]?\d)\s*(L(?:EFT)?|R(?:IGHT)?|C(?:ENTER)?)?'

    # Split on separators (including spaces, but be careful with "27 LEFT" format)
    # First, normalize "27 LEFT" to "27LEFT" to prevent incorrect splitting
    text = re.sub(r'(\d)\s+(L(?:EFT)?|R(?:IGHT)?|C(?:ENTER)?)\b', r'\1\2', text, flags=re.IGNORECASE)

    # Now split on separators including spaces
    parts = re.split(r'\s*(?:AND|,|/|&|\s)\s*', text)

    last_number = None
    for part in parts:
        part = part.strip()
        if not part:
            continue

        match = re.match(runway_pattern, part)
        if match:
            number = match.group(1)
            suffix = match.group(2) or ''
            runway = _normalize_runway_designator(number, suffix)

            # Validate runway number if requested
            if validate and not _is_valid_runway_number(runway):
                continue

            runways.append(runway)
            last_number = number
        elif last_number and re.match(r'^(L(?:EFT)?|R(?:IGHT)?|C(?:ENTER)?)$', part):
            # Handle "17R AND LEFT" -> 17R and 17L
            runway = _normalize_runway_designator(last_number, part)

            if validate and not _is_valid_runway_number(runway):
                continue

            runways.append(runway)

    return list(dict.fromkeys(runways))  # Dedupe while preserving order


def filter_atis_text(atis_text: str) -> str:
    """
    Filter ATIS text to remove METAR/weather data before parsing.

    The METAR portion can contain runway-like numbers (visibility, wind direction)
    that could be falsely matched as runway assignments.

    Uses comprehensive METAR element patterns from python-metar-taf-parser library.
    """
    if not atis_text:
        return ''

    text = atis_text.upper()

    # Remove inline METAR/SPECI markers but preserve text after runway keywords
    text = re.sub(r'\.\s*(?:METAR|SPECI)\s+\d{6}Z(?:.*?)(?=\s+(?:LDG|ARR|DEP|RWY|LAND|RUNWAY)|$)', ' ', text, flags=re.IGNORECASE)

    # Remove METAR remarks section (AO2, SLP, etc.) but stop at runway keywords
    text = re.sub(METAR_REMARKS, ' ', text, flags=re.IGNORECASE)

    # Remove trend forecasts (NOSIG, BECMG, TEMPO sections) but stop at runway keywords
    text = re.sub(METAR_TREND, ' ', text, flags=re.IGNORECASE)

    # ==========================================================================
    # Remove METAR elements in order of specificity (most specific first)
    # ==========================================================================

    # 1. Runway Visual Range (must be before wind - contains runway numbers)
    #    R27L/0800, R09/P2000FT - this is RVR, not runway assignment
    text = re.sub(METAR_RVR, ' [RVR] ', text)

    # 2. Wind patterns (contain 3-digit direction that looks like runway)
    #    27015KT, 27015G25KT, VRB05KT
    text = re.sub(METAR_WIND_PATTERN, ' [WIND] ', text)
    text = re.sub(METAR_WIND_VARIATION, ' [WIND_VAR] ', text)
    text = re.sub(METAR_WIND_SHEAR, ' [WS] ', text)

    # 3. Time groups (6-digit timestamps like 121856Z)
    text = re.sub(METAR_TIME, ' [TIME] ', text)

    # 4. Altimeter settings (A2992, Q1013, QNH1013)
    text = re.sub(METAR_ALTIMETER, ' [ALT] ', text)

    # 5. Temperature/dewpoint (15/12, M02/M05)
    text = re.sub(METAR_TEMP_DEWPOINT, ' [TEMP] ', text)

    # 6. Visibility in statute miles (10SM, P6SM, 1/2SM)
    text = re.sub(METAR_VIS_SM, ' [VIS] ', text)

    # 7. Directional visibility (2000NE, 9999SW)
    text = re.sub(METAR_VIS_DIRECTIONAL, ' [VIS_DIR] ', text)

    # 8. 4-digit meter visibility ONLY when not near runway keywords
    #    Be careful: "9999" is visibility, but we don't want to remove
    #    numbers that are part of runway assignments
    #    Only remove 4-digit patterns that are NOT preceded by runway keywords
    text = re.sub(r'(?<!RWY\s)(?<!RUNWAY\s)(?<!RWYS\s)\b(?:9999|[0-8]\d{3})\b(?!\s*(?:L|R|C|LEFT|RIGHT|CENTER))', ' [VIS_M] ', text)

    # 9. Cloud layers (FEW020, SCT035, BKN080, OVC100, VV003)
    text = re.sub(METAR_CLOUDS, ' [CLD] ', text)

    # 10. Weather phenomena (-RA, +TSRA, VCSH, BR, FG)
    text = re.sub(METAR_PHENOMENA, ' [WX] ', text)

    # 11. Remove stray 3-digit numbers that are likely wind directions
    #     (only if followed by 2-digit speed pattern, already filtered, or standalone)
    #     This catches cases where wind was partially removed
    text = re.sub(r'\b([12]\d{2}|0[0-9]{2}|3[0-5]\d|360)\s*(?:AT|@)\s*\d+\b', ' [WIND_TEXT] ', text)

    # 12. Clean up placeholder markers (we used them to prevent re-matching)
    text = re.sub(r'\[(?:RVR|WIND|WIND_VAR|WS|TIME|ALT|TEMP|VIS|VIS_DIR|VIS_M|CLD|WX|WIND_TEXT)\]', '', text)

    # 13. Clean up extra whitespace
    text = re.sub(r'\s+', ' ', text).strip()

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

    # Pattern 18: Approach type mentions imply arrival runway
    # "ILS RWY 25L", "VISUAL APPROACH RWY 24R", "RNAV RWY 33"
    approach_runway_pattern = rf'(?:EXPECT\s+)?(?:{APPROACH_TYPES})\s+(?:APPROACH(?:ES)?\s+)?(?:RWY?S?\s+)?([0-3]?\d[LRC]?)'
    for match in re.finditer(approach_runway_pattern, text, re.IGNORECASE):
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


def parse_runway_assignments_v2(atis_text: str, airport_icao: str = None,
                                  known_runways: set[str] = None) -> ParseResult:
    """
    Enhanced ATIS parsing with confidence scoring and optional airport validation.

    This is the recommended parsing function that provides:
    - Better METAR filtering
    - Runway number validation (01-36)
    - Optional validation against known airport runways
    - Confidence scoring based on match quality

    Args:
        atis_text: Full ATIS text to parse
        airport_icao: Optional ICAO code for context (e.g., "KJFK")
        known_runways: Optional set of valid runways for this airport
                       (e.g., {"04L", "04R", "13L", "13R", "22L", "22R", "31L", "31R"})

    Returns:
        ParseResult with landing/departing runways, confidence, and diagnostics
    """
    result = ParseResult()

    if not atis_text:
        result.warnings.append("Empty ATIS text")
        return result

    # Get basic parsed runways
    landing, departing = parse_runway_assignments(atis_text)

    # Start with base confidence
    base_confidence = 50

    # Validate against known airport runways if provided
    if known_runways:
        validated_landing = set()
        validated_departing = set()

        for rwy in landing:
            if rwy in known_runways:
                validated_landing.add(rwy)
                result.match_sources.append(f"ARR:{rwy}:VALIDATED")
            else:
                result.warnings.append(f"Runway {rwy} not in known runways for airport")

        for rwy in departing:
            if rwy in known_runways:
                validated_departing.add(rwy)
                result.match_sources.append(f"DEP:{rwy}:VALIDATED")
            else:
                result.warnings.append(f"Runway {rwy} not in known runways for airport")

        landing = validated_landing
        departing = validated_departing

        # Boost confidence if runways validated against known set
        if landing or departing:
            base_confidence += 30

    # Calculate confidence based on parsing quality
    if landing and departing:
        # Both found - good confidence
        base_confidence += 20
        result.match_sources.append("BOTH_FOUND")
    elif landing or departing:
        # Only one type found
        base_confidence += 10
        result.match_sources.append("PARTIAL_FOUND")
    else:
        # Nothing found
        base_confidence = 10
        result.warnings.append("No runways parsed from ATIS")

    # Check for suspicious patterns that might indicate parsing errors
    filtered_text = filter_atis_text(atis_text)
    original_text = atis_text.upper()

    # If filtered text is much shorter, we removed a lot of weather data
    if len(filtered_text) < len(original_text) * 0.5:
        result.match_sources.append("HEAVY_WEATHER_FILTERING")
        # This is actually good - we had lots of weather to filter

    # Check for potentially confused runways (e.g., 27 when wind is 270)
    wind_match = re.search(r'\b(\d{3})\d{2}(?:G\d{2})?KT\b', original_text)
    if wind_match:
        wind_dir = int(wind_match.group(1))
        wind_runway = str(wind_dir // 10).zfill(2)  # 270 -> 27
        opposite_runway = str(((wind_dir + 180) % 360) // 10).zfill(2)  # 270 -> 09

        for rwy in landing | departing:
            rwy_base = rwy.rstrip('LRC')
            if rwy_base == wind_runway or rwy_base == opposite_runway:
                # Runway aligns with wind - this is expected, boost confidence
                base_confidence += 5
                result.match_sources.append(f"WIND_ALIGNED:{rwy}")

    # Cap confidence at 100
    result.confidence = min(100, base_confidence)
    result.landing_runways = landing
    result.departing_runways = departing

    return result


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
    # Test cases - including problematic weather data scenarios
    test_cases = [
        # Standard US formats
        ("JFK ATIS INFO A. LDG RWY 13L AND 13R. DEP RWY 13L AND 31L.", None),
        ("LAX ATIS B. ILS RWY 25L. VISUAL APPROACH RWY 24R. DEPTG RWYS 25R AND 24L.", None),
        ("ORD INFO C. LNDG RUNWAYS 10L 10C 10R. DEPARTING RWYS 10C 28R.", None),

        # Compound formats
        ("LDG/DEPTG RWY 27. EXPECT ILS APPROACH.", None),
        ("LANDING RWY 17R AND LEFT. DEPARTING 17L.", None),

        # Australian/International formats
        ("RWY 03 FOR ARR. RWY 21 FOR DEP.", None),
        ("SIMUL DEPARTURES RWYS 24 AND 25.", None),

        # === PROBLEMATIC CASES - Weather data that could confuse parser ===

        # Wind direction looks like runway
        ("KJFK ATIS INFO A 121856Z 27015KT 10SM FEW250 15/12 A2992 LDG RWY 22L DEP RWY 22R",
         {"04L", "04R", "13L", "13R", "22L", "22R", "31L", "31R"}),

        # Temperature looks like runway numbers
        ("KORD INFO B 150923Z 36008KT 10SM SCT035 BKN080 18/12 A3002 ARR RWY 10L DEP 28R",
         {"04R", "09L", "09R", "10C", "10L", "10R", "14L", "14R", "22L", "22R", "27L", "27R", "28C", "28L", "28R", "32L", "32R"}),

        # Visibility with numbers
        ("INFO C. 10SM VIS. LDG 27L. DEPTG 28R. 27015G25KT", None),

        # RVR that contains runway numbers (should not be parsed as runway assignment)
        ("R27L/0800 R27R/P2000FT. LDG RWY 27L. DEP RWY 27R.",
         {"09L", "09R", "27L", "27R"}),

        # Wind variation
        ("250V310 27015KT ARR RWY 28R DEP RWY 28L", None),

        # European format with 4-digit visibility
        ("EGLL ATIS K 9999 FEW020 RWY 27L IN USE FOR ARR AND DEP", None),

        # Complex METAR-heavy ATIS
        ("KATL ATIS INFO Z 142353Z 18012G18KT 7SM -RA BKN015 OVC025 18/16 A2983 "
         "RMK AO2 RAB35 SLP098 P0002 T01830161 LDG RWY 08L 09L DEP RWY 08R 09R", None),

        # No clear runway info (should return empty with low confidence)
        ("WEATHER 27015KT 10SM A2992 15/12 FEW250", None),
    ]

    print("ATIS Parser Test Results (v2 with Confidence)")
    print("=" * 80)

    for item in test_cases:
        if isinstance(item, tuple):
            atis, known_rwys = item
        else:
            atis = item
            known_rwys = None

        print(f"\nATIS: {atis[:70]}...")
        if known_rwys:
            print(f"  Known runways: {sorted(known_rwys)}")

        # Test v2 parser with confidence
        result = parse_runway_assignments_v2(atis, known_runways=known_rwys)
        print(f"  Landing:    {sorted(result.landing_runways) if result.landing_runways else 'None'}")
        print(f"  Departing:  {sorted(result.departing_runways) if result.departing_runways else 'None'}")
        print(f"  Confidence: {result.confidence}%")
        if result.match_sources:
            print(f"  Sources:    {result.match_sources}")
        if result.warnings:
            print(f"  Warnings:   {result.warnings}")

        # Also show filtered text for debugging
        filtered = filter_atis_text(atis)
        if filtered != atis.upper():
            print(f"  Filtered:   {filtered[:60]}...")

    print("\n" + "=" * 80)
    print("Test complete.")
