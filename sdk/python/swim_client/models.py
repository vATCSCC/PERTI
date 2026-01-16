"""
SWIM API Data Models

Typed dataclasses for SWIM API responses and requests.
"""

from dataclasses import dataclass, field
from datetime import datetime
from typing import Any, Dict, List, Optional, Union
from enum import Enum


class FlightPhase(str, Enum):
    """Flight phase enumeration."""
    PREFLIGHT = 'PREFLIGHT'
    DEPARTING = 'DEPARTING'
    CLIMBING = 'CLIMBING'
    ENROUTE = 'ENROUTE'
    DESCENDING = 'DESCENDING'
    APPROACH = 'APPROACH'
    LANDED = 'LANDED'
    ARRIVED = 'ARRIVED'


class TMIType(str, Enum):
    """Traffic Management Initiative types."""
    GROUND_STOP = 'GS'
    GDP = 'GDP'
    MIT = 'MIT'
    MINIT = 'MINIT'
    AFP = 'AFP'


class FlightStatus(str, Enum):
    """Flight status enumeration."""
    ACTIVE = 'active'
    COMPLETED = 'completed'
    CANCELLED = 'cancelled'


# =============================================================================
# Flight Models
# =============================================================================

@dataclass
class FlightIdentity:
    """Flight identity information."""
    callsign: str
    cid: Optional[int] = None
    aircraft_type: Optional[str] = None
    aircraft_icao: Optional[str] = None
    weight_class: Optional[str] = None
    wake_category: Optional[str] = None
    airline_icao: Optional[str] = None
    airline_name: Optional[str] = None

    @classmethod
    def from_dict(cls, data: Dict[str, Any]) -> 'FlightIdentity':
        return cls(
            callsign=data.get('callsign', ''),
            cid=data.get('cid'),
            aircraft_type=data.get('aircraft_type'),
            aircraft_icao=data.get('aircraft_icao'),
            weight_class=data.get('weight_class'),
            wake_category=data.get('wake_category'),
            airline_icao=data.get('airline_icao'),
            airline_name=data.get('airline_name'),
        )


@dataclass
class FlightPlan:
    """Flight plan information."""
    departure: str
    destination: str
    alternate: Optional[str] = None
    cruise_altitude: Optional[int] = None
    cruise_speed: Optional[int] = None
    route: Optional[str] = None
    flight_rules: Optional[str] = None
    departure_artcc: Optional[str] = None
    destination_artcc: Optional[str] = None
    arrival_fix: Optional[str] = None
    arrival_procedure: Optional[str] = None

    @classmethod
    def from_dict(cls, data: Dict[str, Any]) -> 'FlightPlan':
        return cls(
            departure=data.get('departure', ''),
            destination=data.get('destination', ''),
            alternate=data.get('alternate'),
            cruise_altitude=data.get('cruise_altitude'),
            cruise_speed=data.get('cruise_speed'),
            route=data.get('route'),
            flight_rules=data.get('flight_rules'),
            departure_artcc=data.get('departure_artcc'),
            destination_artcc=data.get('destination_artcc'),
            arrival_fix=data.get('arrival_fix'),
            arrival_procedure=data.get('arrival_procedure'),
        )


@dataclass
class FlightPosition:
    """Current flight position."""
    latitude: float
    longitude: float
    altitude_ft: int
    heading: int
    ground_speed_kts: int
    vertical_rate_fpm: int = 0
    current_artcc: Optional[str] = None

    @classmethod
    def from_dict(cls, data: Dict[str, Any]) -> 'FlightPosition':
        return cls(
            latitude=float(data.get('latitude', 0)),
            longitude=float(data.get('longitude', 0)),
            altitude_ft=int(data.get('altitude_ft', 0)),
            heading=int(data.get('heading', 0)),
            ground_speed_kts=int(data.get('ground_speed_kts', 0)),
            vertical_rate_fpm=int(data.get('vertical_rate_fpm', 0)),
            current_artcc=data.get('current_artcc'),
        )


@dataclass
class FlightProgress:
    """Flight progress information."""
    phase: str
    is_active: bool
    distance_remaining_nm: Optional[float] = None
    pct_complete: Optional[float] = None
    time_to_dest_min: Optional[float] = None

    @classmethod
    def from_dict(cls, data: Dict[str, Any]) -> 'FlightProgress':
        return cls(
            phase=data.get('phase', 'UNKNOWN'),
            is_active=data.get('is_active', False),
            distance_remaining_nm=data.get('distance_remaining_nm'),
            pct_complete=data.get('pct_complete'),
            time_to_dest_min=data.get('time_to_dest_min'),
        )


@dataclass
class FlightTimes:
    """Flight times (OOOI + ETA)."""
    eta: Optional[str] = None
    eta_runway: Optional[str] = None
    out: Optional[str] = None
    off: Optional[str] = None
    on: Optional[str] = None
    in_: Optional[str] = None  # 'in' is reserved in Python

    @classmethod
    def from_dict(cls, data: Dict[str, Any]) -> 'FlightTimes':
        return cls(
            eta=data.get('eta'),
            eta_runway=data.get('eta_runway'),
            out=data.get('out'),
            off=data.get('off'),
            on=data.get('on'),
            in_=data.get('in'),
        )


@dataclass
class FlightTMI:
    """Flight TMI control status."""
    is_controlled: bool = False
    ground_stop_held: bool = False
    control_type: Optional[str] = None
    edct: Optional[str] = None
    delay_minutes: Optional[int] = None

    @classmethod
    def from_dict(cls, data: Dict[str, Any]) -> 'FlightTMI':
        return cls(
            is_controlled=data.get('is_controlled', False),
            ground_stop_held=data.get('ground_stop_held', False),
            control_type=data.get('control_type'),
            edct=data.get('edct'),
            delay_minutes=data.get('delay_minutes'),
        )


@dataclass
class Flight:
    """Complete flight record."""
    gufi: str
    flight_uid: int
    flight_key: str
    identity: FlightIdentity
    flight_plan: FlightPlan
    position: Optional[FlightPosition] = None
    progress: Optional[FlightProgress] = None
    times: Optional[FlightTimes] = None
    tmi: Optional[FlightTMI] = None

    @classmethod
    def from_dict(cls, data: Dict[str, Any]) -> 'Flight':
        return cls(
            gufi=data.get('gufi', ''),
            flight_uid=data.get('flight_uid', 0),
            flight_key=data.get('flight_key', ''),
            identity=FlightIdentity.from_dict(data.get('identity', {})),
            flight_plan=FlightPlan.from_dict(data.get('flight_plan', {})),
            position=FlightPosition.from_dict(data['position']) if data.get('position') else None,
            progress=FlightProgress.from_dict(data['progress']) if data.get('progress') else None,
            times=FlightTimes.from_dict(data['times']) if data.get('times') else None,
            tmi=FlightTMI.from_dict(data['tmi']) if data.get('tmi') else None,
        )

    @property
    def callsign(self) -> str:
        return self.identity.callsign

    @property
    def departure(self) -> str:
        return self.flight_plan.departure

    @property
    def destination(self) -> str:
        return self.flight_plan.destination


# =============================================================================
# TMI Models
# =============================================================================

@dataclass
class GroundStop:
    """Ground Stop program."""
    type: str = 'ground_stop'
    airport: str = ''
    airport_name: Optional[str] = None
    artcc: Optional[str] = None
    reason: Optional[str] = None
    probability_of_extension: Optional[int] = None
    start_time: Optional[str] = None
    end_time: Optional[str] = None
    is_active: bool = True

    @classmethod
    def from_dict(cls, data: Dict[str, Any]) -> 'GroundStop':
        times = data.get('times', {})
        return cls(
            type=data.get('type', 'ground_stop'),
            airport=data.get('airport', ''),
            airport_name=data.get('airport_name'),
            artcc=data.get('artcc'),
            reason=data.get('reason'),
            probability_of_extension=data.get('probability_of_extension'),
            start_time=times.get('start') or data.get('start_time'),
            end_time=times.get('end') or data.get('end_time'),
            is_active=data.get('is_active', True),
        )


@dataclass
class GDPProgram:
    """Ground Delay Program."""
    type: str = 'gdp'
    program_id: str = ''
    airport: str = ''
    airport_name: Optional[str] = None
    artcc: Optional[str] = None
    reason: Optional[str] = None
    program_rate: Optional[int] = None
    delay_limit_minutes: Optional[int] = None
    average_delay_minutes: Optional[int] = None
    maximum_delay_minutes: Optional[int] = None
    total_flights: Optional[int] = None
    affected_flights: Optional[int] = None
    is_active: bool = True

    @classmethod
    def from_dict(cls, data: Dict[str, Any]) -> 'GDPProgram':
        rates = data.get('rates', {})
        delays = data.get('delays', {})
        flights = data.get('flights', {})
        return cls(
            type=data.get('type', 'gdp'),
            program_id=data.get('program_id', ''),
            airport=data.get('airport', ''),
            airport_name=data.get('airport_name'),
            artcc=data.get('artcc'),
            reason=data.get('reason'),
            program_rate=rates.get('program_rate'),
            delay_limit_minutes=delays.get('limit_minutes'),
            average_delay_minutes=delays.get('average_minutes'),
            maximum_delay_minutes=delays.get('maximum_minutes'),
            total_flights=flights.get('total'),
            affected_flights=flights.get('affected'),
            is_active=data.get('is_active', True),
        )


@dataclass
class TMIPrograms:
    """Active TMI programs response."""
    ground_stops: List[GroundStop] = field(default_factory=list)
    gdp_programs: List[GDPProgram] = field(default_factory=list)
    active_ground_stops: int = 0
    active_gdp_programs: int = 0
    total_controlled_airports: int = 0

    @classmethod
    def from_dict(cls, data: Dict[str, Any]) -> 'TMIPrograms':
        summary = data.get('summary', {})
        return cls(
            ground_stops=[GroundStop.from_dict(gs) for gs in data.get('ground_stops', [])],
            gdp_programs=[GDPProgram.from_dict(gdp) for gdp in data.get('gdp_programs', [])],
            active_ground_stops=summary.get('active_ground_stops', 0),
            active_gdp_programs=summary.get('active_gdp_programs', 0),
            total_controlled_airports=summary.get('total_controlled_airports', 0),
        )


# =============================================================================
# GeoJSON Models
# =============================================================================

@dataclass
class GeoJSONFeature:
    """GeoJSON Feature for position data."""
    id: int
    coordinates: List[float]  # [lon, lat, alt]
    callsign: str
    aircraft: Optional[str] = None
    departure: Optional[str] = None
    destination: Optional[str] = None
    phase: Optional[str] = None
    altitude: Optional[int] = None
    heading: Optional[int] = None
    groundspeed: Optional[int] = None
    distance_remaining_nm: Optional[float] = None
    tmi_status: Optional[str] = None

    @classmethod
    def from_dict(cls, data: Dict[str, Any]) -> 'GeoJSONFeature':
        geometry = data.get('geometry', {})
        props = data.get('properties', {})
        coords = geometry.get('coordinates', [0, 0, 0])
        return cls(
            id=data.get('id', 0),
            coordinates=coords,
            callsign=props.get('callsign', ''),
            aircraft=props.get('aircraft'),
            departure=props.get('departure'),
            destination=props.get('destination'),
            phase=props.get('phase'),
            altitude=props.get('altitude'),
            heading=props.get('heading'),
            groundspeed=props.get('groundspeed'),
            distance_remaining_nm=props.get('distance_remaining_nm'),
            tmi_status=props.get('tmi_status'),
        )

    @property
    def latitude(self) -> float:
        return self.coordinates[1] if len(self.coordinates) > 1 else 0

    @property
    def longitude(self) -> float:
        return self.coordinates[0] if len(self.coordinates) > 0 else 0

    @property
    def altitude_ft(self) -> int:
        return int(self.coordinates[2]) if len(self.coordinates) > 2 else 0


@dataclass
class PositionsResponse:
    """GeoJSON FeatureCollection response."""
    features: List[GeoJSONFeature] = field(default_factory=list)
    count: int = 0
    timestamp: Optional[str] = None

    @classmethod
    def from_dict(cls, data: Dict[str, Any]) -> 'PositionsResponse':
        metadata = data.get('metadata', {})
        return cls(
            features=[GeoJSONFeature.from_dict(f) for f in data.get('features', [])],
            count=metadata.get('count', 0),
            timestamp=metadata.get('timestamp'),
        )


# =============================================================================
# Pagination & Response Models
# =============================================================================

@dataclass
class Pagination:
    """Pagination information."""
    total: int
    page: int
    per_page: int
    total_pages: int
    has_more: bool

    @classmethod
    def from_dict(cls, data: Dict[str, Any]) -> 'Pagination':
        return cls(
            total=data.get('total', 0),
            page=data.get('page', 1),
            per_page=data.get('per_page', 100),
            total_pages=data.get('total_pages', 1),
            has_more=data.get('has_more', False),
        )


@dataclass
class FlightsResponse:
    """Paginated flights response."""
    flights: List[Flight] = field(default_factory=list)
    pagination: Optional[Pagination] = None
    timestamp: Optional[str] = None

    @classmethod
    def from_dict(cls, data: Dict[str, Any]) -> 'FlightsResponse':
        return cls(
            flights=[Flight.from_dict(f) for f in data.get('data', [])],
            pagination=Pagination.from_dict(data['pagination']) if data.get('pagination') else None,
            timestamp=data.get('timestamp'),
        )


@dataclass
class IngestResult:
    """Result from ingest operation."""
    processed: int
    created: int
    updated: int
    errors: int
    error_details: List[str] = field(default_factory=list)

    @classmethod
    def from_dict(cls, data: Dict[str, Any]) -> 'IngestResult':
        return cls(
            processed=data.get('processed', 0),
            created=data.get('created', 0),
            updated=data.get('updated', 0),
            errors=data.get('errors', 0),
            error_details=data.get('error_details', []),
        )


# =============================================================================
# Ingest Request Models
# =============================================================================

@dataclass
class FlightIngest:
    """Flight data for ingest."""
    callsign: str
    dept_icao: str
    dest_icao: str
    cid: Optional[int] = None
    aircraft_type: Optional[str] = None
    route: Optional[str] = None
    phase: Optional[str] = None
    is_active: bool = True
    latitude: Optional[float] = None
    longitude: Optional[float] = None
    altitude_ft: Optional[int] = None
    heading_deg: Optional[int] = None
    groundspeed_kts: Optional[int] = None
    vertical_rate_fpm: Optional[int] = None
    out_utc: Optional[str] = None
    off_utc: Optional[str] = None
    on_utc: Optional[str] = None
    in_utc: Optional[str] = None
    eta_utc: Optional[str] = None
    etd_utc: Optional[str] = None

    def to_dict(self) -> Dict[str, Any]:
        """Convert to dict for JSON serialization."""
        result = {
            'callsign': self.callsign,
            'dept_icao': self.dept_icao,
            'dest_icao': self.dest_icao,
            'is_active': self.is_active,
        }
        # Add optional fields if set
        for field_name in [
            'cid', 'aircraft_type', 'route', 'phase',
            'latitude', 'longitude', 'altitude_ft', 'heading_deg',
            'groundspeed_kts', 'vertical_rate_fpm',
            'out_utc', 'off_utc', 'on_utc', 'in_utc', 'eta_utc', 'etd_utc'
        ]:
            value = getattr(self, field_name)
            if value is not None:
                result[field_name] = value
        return result


@dataclass
class TrackIngest:
    """Track/position data for ingest."""
    callsign: str
    latitude: float
    longitude: float
    altitude_ft: Optional[int] = None
    ground_speed_kts: Optional[int] = None
    heading_deg: Optional[int] = None
    vertical_rate_fpm: Optional[int] = None
    squawk: Optional[str] = None
    track_source: Optional[str] = None
    timestamp: Optional[str] = None

    def to_dict(self) -> Dict[str, Any]:
        """Convert to dict for JSON serialization."""
        result = {
            'callsign': self.callsign,
            'latitude': self.latitude,
            'longitude': self.longitude,
        }
        for field_name in [
            'altitude_ft', 'ground_speed_kts', 'heading_deg',
            'vertical_rate_fpm', 'squawk', 'track_source', 'timestamp'
        ]:
            value = getattr(self, field_name)
            if value is not None:
                result[field_name] = value
        return result
