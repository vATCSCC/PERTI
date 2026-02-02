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

CROSSING_RADIUS_NM = 5  # Fix crossing detection radius (reduced from 10nm for accuracy)


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
    thru: Optional['ThruFilter'] = None    # Thru facility filter (e.g., "thru ZAU")

    # Traffic direction (arrivals/departures/both)
    traffic_direction: 'TrafficDirection' = None  # arrivals, departures, or both

    # Scope logic - how to combine origin/dest/thru filters
    scope_logic: Optional['ScopeLogic'] = None  # Determined by NTML pattern

    # Traffic filters
    traffic_filter: 'TrafficFilter' = None  # TYPE, SPD, ALT, EXCL filters

    # Value
    value: float = 0                       # Required spacing (nm) or delay (min)
    unit: str = 'nm'                       # 'nm', 'min'

    # Parties (NTML format: REQUESTOR:PROVIDER)
    # Can be comma-separated for multiple facilities: "ZNY,N90:ZDC,ZBW"
    provider: str = ''                     # ARTCC/TRACON/Airport executing/providing the TMI
    requestor: str = ''                    # ARTCC/TRACON/Airport requesting the TMI
    is_multiple: bool = False              # (MULTIPLE) suffix - applies to multiple handoff points

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
    raw_text: str = ''                         # Original NTML text
    is_user_defined: bool = False              # True if user-provided (not auto-parsed)

    # Reroute-specific fields
    reroute_name: Optional[str] = None         # Playbook/route advisory name
    reroute_mandatory: bool = False            # True=ROUTE RQD, False=FEA FYI
    reroute_routes: List[dict] = field(default_factory=list)  # [{'orig': 'KPHL', 'dest': 'KBOS', 'route': '...'}]
    time_type: Optional[str] = None            # 'ETA' or 'ETD' for reroute validity window

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
    is_multiple: bool = False              # (MULTIPLE) suffix - applies to multiple handoff points


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
    skipped_lines: List['SkippedLine'] = field(default_factory=list)  # Lines parser could not handle
    user_defined_tmis: List['UserTMIDefinition'] = field(default_factory=list)  # User overrides


class MeasurementType(Enum):
    """
    Where MIT compliance is measured

    FIX: Measure spacing at a specified fix (legacy approach)
         e.g., "via CAMRN" - measure at CAMRN fix

    BOUNDARY: Measure spacing at ARTCC handoff point (preferred)
              e.g., "ZNY:ZDC" - measure at the ZNY/ZDC boundary
              The fix indicates routing, but measurement is at boundary

    BOUNDARY_FALLBACK_FIX: Boundary was attempted but fell back to fix
                          (e.g., GIS unavailable, no boundary data)
    """
    FIX = 'FIX'
    BOUNDARY = 'BOUNDARY'
    BOUNDARY_FALLBACK_FIX = 'BOUNDARY_FALLBACK_FIX'


class ThruType(Enum):
    """
    Type of facility/construct for 'thru' filter in TMI scope

    NTML can specify flights passing through various airspace constructs:
    - "thru ZAU" - ARTCC
    - "thru ZNY66" - Sector
    - "thru N90" - TRACON
    - "thru FCAA07" - Flow Constrained Area
    - "thru J60" - Airway (global - J/Q/V/T/A/B/G/R routes)
    - "thru MERIT" - Waypoint

    Each type requires different transit detection logic:
    - ARTCC/SECTOR/TRACON: Polygon → ST_Intersects
    - FCA/FEA: Can be Polygon, Line, or Point
      - Polygon: ST_Intersects
      - Line: ST_Crosses (trajectory crosses the line)
      - Point: ST_DWithin (within radius of point)
    - AIRWAY: Line → ST_Crosses or route string contains (global, all types)
    - ROUTE: Check route string for DP/STAR/preferred route
    - WAYPOINT: Any named point (fix, navaid, ICAO lat/lon, ARINC 424)
      - Check route string or ST_DWithin for coordinates
    """
    ARTCC = 'artcc'        # ARTCC/FIR (ZAU, ZNY, CZYZ) - Polygon
    SECTOR = 'sector'      # Sector within ARTCC (ZNY66, ZLA_HIGH_31) - Polygon
    TRACON = 'tracon'      # TRACON (N90, SCT, A80) - Polygon
    FCA = 'fca'            # Flow Constrained Area - Polygon/Line/Point
    FEA = 'fea'            # Flow Evaluation Area - Polygon/Line/Point
    AFP = 'afp'            # Airspace Flow Program (look up associated FCA)
    ROUTE = 'route'        # Preferred route, DP, STAR - route string check
    AIRWAY = 'airway'      # Global airways: J/Q/V/T/A/B/G/R-routes - Line
    WAYPOINT = 'waypoint'  # Any named point: fix, navaid, ICAO lat/lon, ARINC 424 - Point
    UNKNOWN = 'unknown'


class ScopeLogic(Enum):
    """
    How to combine origin/destination/thru filters in TMI scope matching

    Derived from NTML text patterns to determine correct AND/OR logic:
    - "JFK departures" → DEPARTURES_ONLY (check origins only)
    - "JFK arrivals" → ARRIVALS_ONLY (check destinations only)
    - "JFK via ALL" → ANY_TRAFFIC (match either origin OR destination)
    - "JFK to LAX" → OD_PAIR (must match BOTH origin AND destination)
    - "ZNY overflights" → OVERFLIGHTS (transit but NOT origin/dest)
    - "thru ZAU" (no origin/dest) → THRU_ONLY (only check thru facility)
    """
    DEPARTURES_ONLY = 'departures'      # Check origins only
    ARRIVALS_ONLY = 'arrivals'          # Check destinations only
    OD_PAIR = 'od_pair'                 # Check both (AND logic)
    ANY_TRAFFIC = 'any'                 # Check either (OR logic)
    OVERFLIGHTS = 'overflights'         # Transit but not origin/dest
    THRU_ONLY = 'thru'                  # Only check thru facility


@dataclass
class ThruFilter:
    """
    Thru facility filter for TMI scope

    Examples:
    - "JFK to LAX thru ZAU" → ThruFilter(value='ZAU', thru_type=ThruType.ARTCC)
    - "OAK via ALL thru ZLC" → ThruFilter(value='ZLC', thru_type=ThruType.ARTCC)
    - "thru J60" → ThruFilter(value='J60', thru_type=ThruType.AIRWAY)
    """
    value: str                          # Facility/construct identifier
    thru_type: ThruType                 # What type of thing this is


@dataclass
class StreamMatchInfo:
    """
    Debug information for stream/scope matching

    Captures why a flight was included or excluded from TMI scope,
    plus all boundary crossings for human interpretation (especially
    useful for airspace shelf edge cases where altitude limits are unknown).
    """
    callsign: str
    matched: bool = False
    match_reasons: List[str] = field(default_factory=list)   # Why it matched
    fail_reasons: List[str] = field(default_factory=list)    # Why it was excluded
    all_crossings: List[dict] = field(default_factory=list)  # Full crossing breakdown
    relevant_crossings: List[dict] = field(default_factory=list)  # Crossings for TMI facilities


@dataclass
class SkippedLine:
    """
    An NTML line that couldn't be parsed automatically.

    Returned by parse_ntml_to_tmis() so the UI can show unparsed lines
    and allow users to define them manually.
    """
    line: str                    # Original NTML text
    line_number: int             # Line number in the input (1-based)
    reason: str = ''             # Why it couldn't be parsed
    context: str = ''            # Surrounding context (e.g., provider:requestor)


@dataclass
class UserTMIDefinition:
    """
    User-provided TMI definition for unparsed NTML lines.

    When the parser can't recognize an NTML pattern, users can manually
    specify the TMI fields. These are merged with parsed TMIs during analysis.

    Example usage:
        user_def = UserTMIDefinition(
            original_line="ZDC:ZNY SOMETHING WEIRD 15MIT",
            tmi_type=TMIType.MIT,
            fix="MERIT",
            destinations=["KJFK", "KEWR"],
            value=15
        )
    """
    # Original text (for reference/matching)
    original_line: str
    definition_id: str = ''         # Unique ID for this definition (auto-generated if empty)

    # TMI type (required)
    tmi_type: TMIType = TMIType.MIT

    # Scope
    fix: Optional[str] = None       # Control fix (for MIT/MINIT)
    fixes: List[str] = field(default_factory=list)  # Multiple fixes
    destinations: List[str] = field(default_factory=list)
    origins: List[str] = field(default_factory=list)
    thru: Optional['ThruFilter'] = None
    scope_logic: 'ScopeLogic' = None
    traffic_direction: 'TrafficDirection' = None
    traffic_filter: Optional['TrafficFilter'] = None

    # Value
    value: float = 0                # Required spacing (nm) or delay (min)
    unit: str = 'nm'                # 'nm', 'min'

    # Parties
    provider: str = ''
    requestor: str = ''

    # Time window (inherited from event if not specified)
    start_utc: Optional[datetime] = None
    end_utc: Optional[datetime] = None

    # Metadata
    created_by: str = ''            # Username who created this definition
    created_at: Optional[datetime] = None
    notes: str = ''                 # User notes explaining interpretation

    def to_tmi(self, event_start: datetime = None, event_end: datetime = None) -> 'TMI':
        """Convert this user definition to a TMI object"""
        return TMI(
            tmi_id=self.definition_id or f'USER_{self.tmi_type.value}_{self.fix or "ALL"}',
            tmi_type=self.tmi_type,
            fix=self.fix,
            fixes=self.fixes,
            destinations=self.destinations,
            origins=self.origins,
            thru=self.thru,
            traffic_direction=self.traffic_direction or TrafficDirection.BOTH,
            scope_logic=self.scope_logic or ScopeLogic.ANY_TRAFFIC,
            traffic_filter=self.traffic_filter,
            value=self.value,
            unit=self.unit,
            provider=self.provider,
            requestor=self.requestor,
            start_utc=self.start_utc or event_start,
            end_utc=self.end_utc or event_end,
            raw_text=self.original_line,
            is_user_defined=True,
        )


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
class BoundaryCrossing:
    """
    Result of an ARTCC boundary crossing detection (from PostGIS)

    Used for boundary-aware MIT compliance analysis where spacing
    is measured at the handoff point rather than a specified fix.
    """
    callsign: str
    flight_uid: str
    crossing_time: datetime     # Interpolated time at boundary crossing
    crossing_lat: float
    crossing_lon: float
    from_artcc: str             # ARTCC being exited (e.g., ZDC = provider)
    to_artcc: str               # ARTCC being entered (e.g., ZNY = requestor)
    groundspeed: float          # GS at crossing (interpolated)
    altitude: float             # Altitude at crossing
    dept: str
    dest: str
    distance_from_origin_nm: float = 0  # Distance from route start to crossing
    crossing_type: str = 'ENTRY'        # ENTRY or EXIT relative to to_artcc


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
        # Canadian airports get CY prefix (major airports)
        canadian_airports = [
            'YVR', 'YYZ', 'YUL', 'YYC', 'YEG', 'YOW', 'YWG', 'YHZ', 'YQB', 'YXE',
            'YQR', 'YYJ', 'YXX', 'YLW', 'YYT', 'YQT', 'YXU', 'YQM', 'YFC', 'YZF',
            'YXY', 'YQG', 'YTS', 'YMM', 'YZR', 'YQX', 'YDF', 'YQY', 'YXS', 'YPR',
        ]
        if code in canadian_airports or code.startswith('Y'):
            return 'C' + code

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


# Known AFP names (for AFP → FCA lookup)
KNOWN_AFPS = {
    'MIT', 'SWAP', 'EWR_VOLUME',  # Add as needed
}

# Known FCA names (for direct FCA matching)
KNOWN_FCAS = {
    'FCAA07', 'FCAA08', 'FCA_EAST', 'FCA_WEST',  # Add as needed
}


def detect_thru_type(value: str) -> ThruType:
    """
    Detect the type of a thru facility/construct from its identifier.

    Uses facility hierarchy cache when available, falls back to pattern matching.

    Examples:
    - ZAU → ARTCC
    - ZNY66 → SECTOR
    - N90 → TRACON
    - FCAA07 → FCA
    - J60, A123, B456 → AIRWAY (global, all route types)
    - MERIT → WAYPOINT
    """
    import re

    if not value:
        return ThruType.UNKNOWN

    value = value.upper().strip()

    # Try facility hierarchy cache first
    try:
        from .facility_hierarchy import is_known_tracon, is_known_artcc, get_facility_cache
        cache = get_facility_cache()
        if cache._loaded:
            if cache.is_artcc(value):
                return ThruType.ARTCC
            if cache.is_tracon(value):
                return ThruType.TRACON
            if cache.is_sector(value):
                return ThruType.SECTOR
    except ImportError:
        pass  # Fall back to pattern matching

    # ARTCC: 3-letter starting with Z (US) or 4-letter ICAO FIR
    if re.match(r'^Z[A-Z]{2}$', value):  # ZNY, ZAU, ZLA
        return ThruType.ARTCC
    if re.match(r'^CZ[A-Z]{2}$', value):  # CZYZ, CZUL (Canadian)
        return ThruType.ARTCC
    # International FIRs (4-letter ICAO)
    if re.match(r'^[A-Z]{4}$', value) and value[0] not in ['K', 'P', 'T']:
        # Could be international FIR - check if it looks like one
        # Most FIRs end in specific patterns
        return ThruType.ARTCC

    # Sector: ARTCC code + number (ZNY66) or with type (ZLA_HIGH_31)
    if re.match(r'^Z[A-Z]{2}\d+$', value):  # ZNY66, ZAU32
        return ThruType.SECTOR
    if re.match(r'^Z[A-Z]{2}_(HIGH|LOW|SUPERHIGH)_\d+$', value):  # ZLA_HIGH_31
        return ThruType.SECTOR

    # TRACON: Pattern matching (letter + 2 digits like A80, N90)
    # Use facility hierarchy if available
    try:
        from .facility_hierarchy import is_known_tracon
        if is_known_tracon(value):
            return ThruType.TRACON
    except ImportError:
        pass

    if re.match(r'^[A-Z]\d{2}$', value):  # A80, N90, C90
        return ThruType.TRACON

    # FCA: Usually FCA prefix or known FCA names
    if value.startswith('FCA') or value in KNOWN_FCAS:
        return ThruType.FCA

    # FEA: Flow Evaluation Area
    if value.startswith('FEA'):
        return ThruType.FEA

    # AFP: Known AFP names
    if value in KNOWN_AFPS:
        return ThruType.AFP

    # Airway: Global route types
    # J-routes (US jet), Q-routes (RNAV), V-routes (US VOR), T-routes (US RNAV low)
    # A-routes (Pacific/international), B-routes (Pacific), G-routes (international)
    # R-routes (Russian), L-routes, M-routes, N-routes, etc.
    if re.match(r'^[JQVTABGRLMNUW]\d+[A-Z]?$', value):
        return ThruType.AIRWAY

    # Route: DPs, STARs (usually alphanumeric ending in digit)
    # e.g., CAMRN4, ROBUC3, PHLBO1
    if re.match(r'^[A-Z]{3,5}\d$', value):
        return ThruType.ROUTE

    # Waypoint: 5-letter identifier (MERIT, CAMRN, BIGGY) - named fixes
    if re.match(r'^[A-Z]{5}$', value):
        return ThruType.WAYPOINT

    # 3-letter could be navaid (VOR/NDB) or airport
    if re.match(r'^[A-Z]{3}$', value):
        return ThruType.WAYPOINT

    # 2-letter navaids (some NDBs)
    if re.match(r'^[A-Z]{2}$', value):
        return ThruType.WAYPOINT

    # ARINC 424 format: lat/lon encoded (e.g., 4000N07500W)
    if re.match(r'^\d{4}[NS]\d{5}[EW]$', value):
        return ThruType.WAYPOINT

    return ThruType.UNKNOWN


def create_thru_filter(value: str) -> Optional[ThruFilter]:
    """
    Create a ThruFilter from a facility/construct identifier.

    Returns None if the value is empty or invalid.
    """
    if not value:
        return None

    value = value.upper().strip()
    thru_type = detect_thru_type(value)

    return ThruFilter(value=value, thru_type=thru_type)
