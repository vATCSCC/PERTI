"""
TMI Compliance Analyzer - Data Models
=====================================

Core data classes for TMI compliance analysis.
"""

from enum import Enum
from dataclasses import dataclass, field
from datetime import datetime, timedelta
from typing import Optional, List


class TMIType(Enum):
    """Traffic Management Initiative Types"""
    MIT = 'MIT'           # Miles-In-Trail
    MINIT = 'MINIT'       # Minutes-In-Trail
    GS = 'GS'             # Ground Stop
    GDP = 'GDP'           # Ground Delay Program
    AFP = 'AFP'           # Airspace Flow Program
    REROUTE = 'REROUTE'   # Reroute/Playbook
    APREQ = 'APREQ'       # Approval Request (Call For Release)
    CFR = 'CFR'           # Call For Release (alias for APREQ)
    ED = 'E/D'            # En Route Delay (holding in air)
    DD = 'D/D'            # Departure Delay (ground delay)
    AD = 'A/D'            # Arrival Delay (holding for arrival)


class MITModifier(Enum):
    """
    MIT Modifier types - how MIT applies to multiple streams/routes

    STANDARD: Default - each stream/fix gets its own MIT (explicit or implied)

    AS_ONE / SINGLE_STREAM: Provider must provide 1 stream/flow to requestor
            by handoff point (not multiple streams/handoff points). All traffic
            merged into single stream regardless of origin.
            e.g., "30MIT AS ONE" means JFK-LGA-FRG traffic all in same stream

    PER_STREAM / PER_FIX / PER_ROUTE / EACH: Each fix/route gets separate MIT
            These are all equivalent for analysis purposes.
            e.g., "35MIT PER STREAM" with "AUDIL/MEMMS" = separate MIT per fix

    PER_AIRPORT: Each origin/destination airport gets its own MIT
            e.g., "15MIT PER AIRPORT" for EWR,LGA departures = separate per airport

    NO_STACKS: Don't send over planes that overlap each other (no vertical stacking).
            Provider cannot stack aircraft vertically to meet demand.

    EVERY_OTHER: TMI applies to alternating flights only.
            For flow A-B-C-D-E-F, TMI applies only to/between A, C, E (odd positions).

    RALT: Regardless of altitude - MIT applies to all altitudes, no altitude filtering.
    """
    STANDARD = 'STANDARD'         # Default behavior
    AS_ONE = 'AS_ONE'             # All origins merged into one stream
    SINGLE_STREAM = 'SINGLE_STREAM'  # Alias for AS_ONE
    PER_STREAM = 'PER_STREAM'     # Each fix gets separate MIT analysis
    PER_FIX = 'PER_FIX'           # Alias for PER_STREAM
    PER_ROUTE = 'PER_ROUTE'       # Each route gets separate MIT analysis
    EACH = 'EACH'                 # Alias for PER_STREAM/PER_ROUTE
    PER_AIRPORT = 'PER_AIRPORT'   # Each airport gets separate MIT
    NO_STACKS = 'NO_STACKS'       # No vertical stacking of aircraft
    EVERY_OTHER = 'EVERY_OTHER'   # Alternating traffic (A, C, E not B, D, F)
    RALT = 'RALT'                 # Regardless of altitude


class TrafficDirection(Enum):
    """Traffic direction for arrival/departure restrictions"""
    ARRIVALS = 'arrivals'       # Traffic arriving at destination
    DEPARTURES = 'departures'   # Traffic departing from origin
    BOTH = 'both'               # Both directions (default)


class AircraftType(Enum):
    """Aircraft type filters"""
    ALL = 'ALL'
    JET = 'JET'
    PROP = 'PROP'
    TURBOPROP = 'TURBOPROP'


class AltitudeFilter(Enum):
    """Altitude filter types"""
    AT = 'AT'       # At specific altitude
    AOB = 'AOB'     # At or below
    AOA = 'AOA'     # At or above (alias: LOA = Level or Above)


class ComparisonOp(Enum):
    """Comparison operators for speed/altitude filters"""
    AT = '='        # At/equal to (default when no operator)
    LT = '<'        # Less than
    LE = '<='       # Less than or equal (also ≤, S prefix)
    GT = '>'        # Greater than
    GE = '>='       # Greater than or equal (also ≥)


@dataclass
class TrafficFilter:
    """
    Traffic filter specifications from NTML

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
    aircraft_type: Optional[AircraftType] = None  # TYPE:ALL/JET/PROP/TURBOPROP
    speed_op: Optional[ComparisonOp] = None       # Comparison operator for speed
    speed_value: Optional[int] = None             # Speed value in knots
    altitude_filter: Optional[AltitudeFilter] = None  # AT/AOB/AOA
    altitude_value: Optional[int] = None          # FL or altitude in 100s ft
    exclusions: List[str] = field(default_factory=list)  # EXCL:PHL,EWR


class Compliance(Enum):
    """Compliance status categories"""
    COMPLIANT = 'COMPLIANT'
    EXEMPT = 'EXEMPT'
    NON_COMPLIANT = 'NON-COMPLIANT'


class SpacingCategory(Enum):
    """Spacing categorization for MIT/MINIT"""
    UNDER = 'UNDER'       # < 95% of required (>5% shortfall) - VIOLATION
    WITHIN = 'WITHIN'     # 95%-110% of required - IDEAL
    OVER = 'OVER'         # 110%-200% of required - ACCEPTABLE GAP
    GAP = 'GAP'           # > 200% of required - EXCESSIVE GAP


# Spacing thresholds (as fraction of required)
SPACING_UNDER_THRESHOLD = 0.95      # < 95% = UNDER (violation)
SPACING_WITHIN_THRESHOLD = 1.10     # 95-110% = WITHIN (ideal)
SPACING_OVER_THRESHOLD = 2.00       # 110-200% = OVER (acceptable gap)
                                     # > 200% = GAP (excessive)

CROSSING_RADIUS_NM = 10  # Fix crossing detection radius


@dataclass
class TMI:
    """
    Traffic Management Initiative definition

    REQUESTOR:PROVIDER CONVENTION:
    ==============================
    In NTML notation "ZME:ZID", the format is REQUESTOR:PROVIDER, meaning:
    - ZME is the REQUESTOR (they need traffic managed to their destination)
    - ZID is the PROVIDER (they implement/execute the TMI)

    TRAFFIC SPECIFICATION:
    ======================
    NTML entries specify WHICH traffic is affected using:
    - arrivals/departures: Direction of traffic (JFK arrivals, EWR departures)
    - via FIX: Routing fix (via CAMRN, via BIGGY)
    - TYPE: Aircraft type filter (TYPE:JET, TYPE:ALL)
    - SPD: Speed filter (SPD:S210 = max 210 knots)
    - ALT: Altitude filter (ALT:AOB090 = at or below FL090)
    - EXCL: Exclusions (EXCL:PHL = exclude PHL traffic)
    """
    tmi_id: str
    tmi_type: TMIType

    # Scope
    fix: Optional[str] = None              # Control fix (for MIT/MINIT)
    fixes: List[str] = field(default_factory=list)  # Multiple fixes
    destinations: List[str] = field(default_factory=list)
    origins: List[str] = field(default_factory=list)
    artccs: List[str] = field(default_factory=list)  # Affected ARTCCs

    # Traffic direction (arrivals/departures/both)
    traffic_direction: 'TrafficDirection' = None  # arrivals, departures, or both

    # Traffic filters
    traffic_filter: 'TrafficFilter' = None  # TYPE, SPD, ALT, EXCL filters

    # Value
    value: float = 0                       # Required spacing (nm) or delay (min)
    unit: str = 'nm'                       # 'nm', 'min'

    # Parties (NTML format: REQUESTOR:PROVIDER)
    provider: str = ''                     # ARTCC executing/providing the TMI
    requestor: str = ''                    # ARTCC requesting the TMI

    # Timing
    start_utc: Optional[datetime] = None
    end_utc: Optional[datetime] = None
    issued_utc: Optional[datetime] = None
    cancelled_utc: Optional[datetime] = None  # None if not cancelled

    # MIT Modifiers (how MIT applies to multiple streams)
    modifier: 'MITModifier' = None  # AS_ONE, PER_STREAM, PER_ROUTE, etc.

    # Metadata
    reason: str = ''
    notes: str = ''

    # For tracking amendments (same dest/fix with different values over time)
    supersedes_tmi_id: Optional[str] = None  # ID of TMI this one supersedes
    superseded_by_tmi_id: Optional[str] = None  # ID of TMI that supersedes this one

    def is_active_at(self, check_time: datetime) -> bool:
        """Check if TMI is active at a given time (considering cancellation)"""
        if check_time.tzinfo:
            check_time = check_time.replace(tzinfo=None)

        if self.start_utc is None or self.end_utc is None:
            return False

        # Check basic time window
        if not (self.start_utc <= check_time <= self.end_utc):
            return False

        # Check if cancelled before this time
        if self.cancelled_utc and check_time >= self.cancelled_utc:
            return False

        return True

    def get_effective_end(self) -> datetime:
        """Get effective end time (cancelled_utc if cancelled, else end_utc)"""
        if self.cancelled_utc and self.cancelled_utc < self.end_utc:
            return self.cancelled_utc
        return self.end_utc


class DelayType(Enum):
    """
    Delay entry types from NTML

    - D/D: Departure Delays - planes on ground waiting to depart
    - E/D: En Route Delays - flights in air being delayed (holding, vectors, turns)
    - A/D: Arrival Delays - similar to E/D but specifically for arrivals
    """
    DD = 'D/D'    # Departure Delays (ground)
    ED = 'E/D'    # En Route Delays (airborne)
    AD = 'A/D'    # Arrival Delays (typically holding before arrival)


class DelayTrend(Enum):
    """Delay trend direction"""
    INCREASING = 'increasing'
    DECREASING = 'decreasing'
    STEADY = 'steady'
    UNKNOWN = 'unknown'


class HoldingStatus(Enum):
    """Holding pattern status for E/D and A/D entries"""
    HOLDING = '+Holding'      # Entered holding (started holding planes)
    NOT_HOLDING = '-Holding'  # Exited holding (planes no longer holding)
    NONE = 'none'             # No holding info in this entry


@dataclass
class DelayEntry:
    """
    Delay entry from NTML (D/D, E/D, A/D lines)

    Delay tracking methodology:
    - Tracked in 15-minute increments
    - Logging starts when delays reach 15+ minutes (or +Holding for E/D/A/D)
    - Logging ends when delays drop below 15 minutes (or -Holding)
    - Can be increasing, decreasing, or steady

    D/D (Departure Delays):
        Planes on ground waiting to depart.
        Example: "31/0153    D/D from BOS +35/0153  VOLUME:VOLUME"
        - +35 means 35-minute departure delays
        - /0153 is the time delays started

    E/D (En Route Delays):
        Flights in the air being delayed via holding, delay vectors, turns, etc.
        Example: "31/0127    ZBW E/D for BOS +Holding/0147/2 ACFT  FIX/NAVAID:AJJAY VOLUME:VOLUME"
        - +Holding indicates aircraft are in holding patterns
        - /0147 is when holding started
        - 2 ACFT is number currently holding
        - FIX/NAVAID:AJJAY is the holding fix

    A/D (Arrival Delays):
        Same as E/D but specifically for arrivals (typically holding before landing).
    """
    delay_type: DelayType
    airport: str                               # Affected airport (BOS, LGA, etc.)
    facility: str = ''                         # Issuing/reporting facility (ZBW, N90, etc.)
    timestamp_utc: Optional[datetime] = None   # When this update was posted

    # Delay amount (in minutes, tracked in 15-min increments)
    delay_minutes: int = 0                     # Current delay amount
    delay_trend: DelayTrend = DelayTrend.UNKNOWN  # Is delay increasing/decreasing/steady?
    delay_start_utc: Optional[datetime] = None    # When delays started (from +XX/HHMM)

    # Holding info (primarily for E/D and A/D)
    holding_status: HoldingStatus = HoldingStatus.NONE
    holding_fix: str = ''                      # Fix/navaid for holding pattern (AJJAY, etc.)
    aircraft_holding: int = 0                  # Number of aircraft currently holding

    # Context
    reason: str = ''                           # VOLUME, WEATHER, etc.
    raw_line: str = ''                         # Original line for debugging


@dataclass
class AirportConfig:
    """
    Airport configuration from NTML

    Example: "30/2328    BOS    VMC    ARR:27/32 DEP:33L    AAR:40 ADR:40"
    """
    airport: str                           # Airport code (BOS, LGA, DCA)
    timestamp_utc: Optional[datetime] = None

    # Weather/visibility
    conditions: str = 'VMC'                # VMC or IMC

    # Runway configuration
    arrival_runways: List[str] = field(default_factory=list)   # e.g., ['27', '32']
    departure_runways: List[str] = field(default_factory=list) # e.g., ['33L']

    # Rates
    aar: int = 0                           # Arrival Acceptance Rate
    adr: int = 0                           # Airport Departure Rate


@dataclass
class CancelEntry:
    """
    Cancellation entry from NTML

    Examples:
    - "31/0326    BOS via RBV CANCEL RESTR ZNY:ZDC"
    - "31/0340    LGA via ALL CANCEL RESTR ZNY,N90:ZBW,ZDC,ZOB"
    - "31/0350 DCA via JOOLO CANCEL RESTR  ZDC:ZTL"
    """
    timestamp_utc: Optional[datetime] = None
    destination: str = ''                  # Airport being cancelled for (BOS, LGA, DCA)
    fix: str = ''                          # Fix/route being cancelled (RBV, ALL, JOOLO)
    requestor: str = ''                    # Facility that requested (ZNY, N90)
    provider: str = ''                     # Facility providing (ZDC, ZBW)


@dataclass
class EventConfig:
    """Event configuration"""
    name: str
    start_utc: datetime
    end_utc: datetime
    destinations: List[str] = field(default_factory=list)
    origins: List[str] = field(default_factory=list)
    tmis: List[TMI] = field(default_factory=list)
    delays: List[DelayEntry] = field(default_factory=list)
    airport_configs: List[AirportConfig] = field(default_factory=list)
    cancellations: List[CancelEntry] = field(default_factory=list)


@dataclass
class CrossingResult:
    """Result of a fix crossing detection"""
    callsign: str
    flight_uid: str
    crossing_time: datetime
    distance_nm: float
    lat: float
    lon: float
    groundspeed: float
    altitude: float
    dept: str
    dest: str


@dataclass
class ComplianceResult:
    """Compliance analysis result for a flight/pair"""
    status: Compliance
    shortfall_pct: float = 0  # For NON_COMPLIANT: % below required (positive = shortfall)
    spacing_category: Optional[SpacingCategory] = None  # For MIT/MINIT
    actual_value: float = 0
    required_value: float = 0
    margin_pct: float = 0
    details: str = ''


def categorize_spacing(actual: float, required: float) -> SpacingCategory:
    """Categorize spacing relative to required value"""
    if required <= 0:
        return SpacingCategory.GAP

    ratio = actual / required

    if ratio < SPACING_UNDER_THRESHOLD:
        return SpacingCategory.UNDER
    elif ratio <= SPACING_WITHIN_THRESHOLD:
        return SpacingCategory.WITHIN
    elif ratio <= SPACING_OVER_THRESHOLD:
        return SpacingCategory.OVER
    else:
        return SpacingCategory.GAP


def calculate_shortfall_pct(actual: float, required: float) -> float:
    """Calculate the shortfall percentage (positive = under required)"""
    if required <= 0:
        return 0.0
    return round(((required - actual) / required) * 100, 1)


def normalize_datetime(dt: datetime) -> datetime:
    """Normalize datetime to naive UTC"""
    if dt is None:
        return None
    if dt.tzinfo is not None:
        return dt.replace(tzinfo=None)
    return dt


def normalize_icao(code: str) -> str:
    """
    Normalize airport code to ICAO format.

    - 3-letter US codes get K prefix (ATL -> KATL)
    - 4-letter codes pass through unchanged
    - Handles common exceptions (Hawaii, Alaska, etc.)
    """
    if not code:
        return code

    code = code.upper().strip()

    # Already 4+ letters, return as-is
    if len(code) >= 4:
        return code

    # 3-letter codes - determine region
    if len(code) == 3:
        # Alaska airports start with PA
        alaska_prefixes = ['ANC', 'FAI', 'JNU', 'BET', 'OME', 'OTZ', 'SCC', 'ADQ', 'DLG', 'CDV']
        if code in alaska_prefixes:
            return 'P' + code

        # Hawaii airports start with PH
        hawaii_prefixes = ['HNL', 'OGG', 'LIH', 'KOA', 'ITO', 'MKK', 'LNY']
        if code in hawaii_prefixes:
            return 'PH' + code[-2:] if len(code) == 3 else 'P' + code

        # Pacific territories
        if code in ['GUM', 'SPN']:
            return 'PG' + code[-2:]

        # Caribbean/Puerto Rico (TJ prefix)
        caribbean = ['SJU', 'BQN', 'PSE', 'RVR', 'STT', 'STX']
        if code in caribbean:
            if code in ['SJU', 'BQN', 'PSE', 'RVR']:
                return 'TJ' + code[-2:]  # Puerto Rico
            else:
                return 'TI' + code[-2:]  # US Virgin Islands

        # Default: CONUS airports get K prefix
        return 'K' + code

    return code


def normalize_icao_list(codes: List[str]) -> List[str]:
    """Normalize a list of airport codes, keeping both original and ICAO versions"""
    if not codes:
        return codes

    result = set()
    for code in codes:
        code_upper = code.upper().strip()
        result.add(code_upper)

        normalized = normalize_icao(code_upper)
        if normalized != code_upper:
            result.add(normalized)

    return list(result)


# Facility alias mappings (NTML shorthand -> official identifier)
# Used for cross-border and special facility references
FACILITY_ALIASES = {
    # Canadian FIRs (ZYZ pattern used in NTML for Canadian centers)
    'ZYZ': 'CZYZ',     # Toronto ACC
    'ZEG': 'CZEG',     # Edmonton FIR
    'ZWG': 'CZWG',     # Winnipeg FIR
    'ZQM': 'CZQM',     # Moncton FIR
    'ZQX': 'CZQX',     # Gander FIR
    'ZUL': 'CZUL',     # Montreal FIR
    'ZVR': 'CZVR',     # Vancouver FIR

    # Common TRACON aliases
    'N90': 'N90',      # New York TRACON
    'PCT': 'PCT',      # Potomac TRACON
    'A90': 'A90',      # Boston TRACON
    'C90': 'C90',      # Chicago TRACON
    'NCT': 'NCT',      # NorCal TRACON
    'SCT': 'SCT',      # SoCal TRACON
}


def resolve_facility_alias(facility: str) -> str:
    """
    Resolve facility alias to official identifier.

    Examples:
    - ZYZ -> CZYZ (Toronto ACC)
    - N90 -> N90 (no change, already correct)
    """
    if not facility:
        return facility

    facility_upper = facility.upper().strip()
    return FACILITY_ALIASES.get(facility_upper, facility_upper)


def resolve_facility_list(facilities: List[str]) -> List[str]:
    """Resolve a list of facility codes, keeping both original and resolved versions"""
    if not facilities:
        return facilities

    result = set()
    for fac in facilities:
        fac_upper = fac.upper().strip()
        result.add(fac_upper)

        resolved = resolve_facility_alias(fac_upper)
        if resolved != fac_upper:
            result.add(resolved)

    return list(result)
