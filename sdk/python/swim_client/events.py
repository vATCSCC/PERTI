"""
SWIM Event Types and Data Classes

Defines event types and structured data classes for SWIM WebSocket events.
"""

from dataclasses import dataclass, field
from datetime import datetime
from enum import Enum
from typing import List, Optional, Dict, Any


class EventType(str, Enum):
    """SWIM WebSocket event types."""
    
    # Connection events
    CONNECTED = 'connected'
    SUBSCRIBED = 'subscribed'
    UNSUBSCRIBED = 'unsubscribed'
    DISCONNECTED = 'disconnected'
    ERROR = 'error'
    PONG = 'pong'
    STATUS = 'status'
    
    # System events
    HEARTBEAT = 'system.heartbeat'
    
    # Flight lifecycle events
    FLIGHT_CREATED = 'flight.created'
    FLIGHT_UPDATED = 'flight.updated'
    FLIGHT_DEPARTED = 'flight.departed'
    FLIGHT_ARRIVED = 'flight.arrived'
    FLIGHT_DELETED = 'flight.deleted'
    
    # Position events
    FLIGHT_POSITION = 'flight.position'
    FLIGHT_POSITIONS = 'flight.positions'
    
    # TMI events
    TMI_ISSUED = 'tmi.issued'
    TMI_MODIFIED = 'tmi.modified'
    TMI_RELEASED = 'tmi.released'
    
    # Wildcards (for subscription)
    FLIGHT_ALL = 'flight.*'
    TMI_ALL = 'tmi.*'
    SYSTEM_ALL = 'system.*'


@dataclass
class Position:
    """Aircraft position data."""
    
    callsign: str
    flight_uid: str
    latitude: float
    longitude: float
    altitude_ft: int
    groundspeed_kts: int
    heading_deg: int
    vertical_rate_fpm: int = 0
    current_artcc: Optional[str] = None
    dep: Optional[str] = None
    arr: Optional[str] = None
    
    @classmethod
    def from_dict(cls, data: Dict[str, Any]) -> 'Position':
        return cls(
            callsign=data.get('callsign', ''),
            flight_uid=data.get('flight_uid', ''),
            latitude=float(data.get('latitude', 0)),
            longitude=float(data.get('longitude', 0)),
            altitude_ft=int(data.get('altitude_ft', 0)),
            groundspeed_kts=int(data.get('groundspeed_kts', 0)),
            heading_deg=int(data.get('heading_deg', 0)),
            vertical_rate_fpm=int(data.get('vertical_rate_fpm', 0)),
            current_artcc=data.get('current_artcc'),
            dep=data.get('dep'),
            arr=data.get('arr'),
        )


@dataclass
class FlightEvent:
    """Flight lifecycle event data."""
    
    callsign: str
    flight_uid: str
    dep: Optional[str] = None
    arr: Optional[str] = None
    equipment: Optional[str] = None
    route: Optional[str] = None
    off_utc: Optional[str] = None
    in_utc: Optional[str] = None
    latitude: Optional[float] = None
    longitude: Optional[float] = None
    altitude_ft: Optional[int] = None
    groundspeed_kts: Optional[int] = None
    heading_deg: Optional[int] = None
    
    @classmethod
    def from_dict(cls, data: Dict[str, Any]) -> 'FlightEvent':
        return cls(
            callsign=data.get('callsign', ''),
            flight_uid=data.get('flight_uid', ''),
            dep=data.get('dep'),
            arr=data.get('arr'),
            equipment=data.get('equipment'),
            route=data.get('route'),
            off_utc=data.get('off_utc'),
            in_utc=data.get('in_utc'),
            latitude=data.get('latitude'),
            longitude=data.get('longitude'),
            altitude_ft=data.get('altitude_ft'),
            groundspeed_kts=data.get('groundspeed_kts'),
            heading_deg=data.get('heading_deg'),
        )


@dataclass
class PositionBatch:
    """Batch of position updates."""
    
    count: int
    positions: List[Position] = field(default_factory=list)
    
    @classmethod
    def from_dict(cls, data: Dict[str, Any]) -> 'PositionBatch':
        positions = [
            Position.from_dict(p) 
            for p in data.get('positions', [])
        ]
        return cls(
            count=data.get('count', len(positions)),
            positions=positions,
        )


@dataclass
class TMIEvent:
    """Traffic Management Initiative event data."""
    
    program_id: str
    program_type: str
    airport: str
    start_time: Optional[str] = None
    end_time: Optional[str] = None
    reason: Optional[str] = None
    status: Optional[str] = None
    
    @classmethod
    def from_dict(cls, data: Dict[str, Any]) -> 'TMIEvent':
        return cls(
            program_id=data.get('program_id', ''),
            program_type=data.get('program_type', ''),
            airport=data.get('airport', ''),
            start_time=data.get('start_time'),
            end_time=data.get('end_time'),
            reason=data.get('reason'),
            status=data.get('status'),
        )


@dataclass
class HeartbeatEvent:
    """System heartbeat event data."""
    
    connected_clients: int
    uptime_seconds: int
    
    @classmethod
    def from_dict(cls, data: Dict[str, Any]) -> 'HeartbeatEvent':
        return cls(
            connected_clients=data.get('connected_clients', 0),
            uptime_seconds=data.get('uptime_seconds', 0),
        )


@dataclass
class ConnectionInfo:
    """Connection state information."""
    
    client_id: str
    server_time: str
    version: str
    
    @classmethod
    def from_dict(cls, data: Dict[str, Any]) -> 'ConnectionInfo':
        return cls(
            client_id=data.get('client_id', ''),
            server_time=data.get('server_time', ''),
            version=data.get('version', ''),
        )


@dataclass
class SubscriptionFilters:
    """Subscription filter criteria."""
    
    airports: Optional[List[str]] = None
    artccs: Optional[List[str]] = None
    callsign_prefix: Optional[List[str]] = None
    bbox: Optional[Dict[str, float]] = None
    
    def to_dict(self) -> Dict[str, Any]:
        """Convert to dict for JSON serialization."""
        result = {}
        if self.airports:
            result['airports'] = [a.upper() for a in self.airports]
        if self.artccs:
            result['artccs'] = [a.upper() for a in self.artccs]
        if self.callsign_prefix:
            result['callsign_prefix'] = [c.upper() for c in self.callsign_prefix]
        if self.bbox:
            result['bbox'] = self.bbox
        return result
