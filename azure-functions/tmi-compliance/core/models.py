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
    """
    tmi_id: str
    tmi_type: TMIType

    # Scope
    fix: Optional[str] = None              # Control fix (for MIT/MINIT)
    fixes: List[str] = field(default_factory=list)  # Multiple fixes
    destinations: List[str] = field(default_factory=list)
    origins: List[str] = field(default_factory=list)
    artccs: List[str] = field(default_factory=list)  # Affected ARTCCs

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

    # Metadata
    reason: str = ''
    notes: str = ''

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


@dataclass
class EventConfig:
    """Event configuration"""
    name: str
    start_utc: datetime
    end_utc: datetime
    destinations: List[str] = field(default_factory=list)
    origins: List[str] = field(default_factory=list)
    tmis: List[TMI] = field(default_factory=list)


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
