"""
VATSWIM Client SDK

A Python SDK for the VATSWIM (System Wide Information Management) API.

Features:
- REST API client for querying flights, positions, and TMIs
- WebSocket client for real-time event streaming
- Async support for both REST and WebSocket

Quick Start:
    # REST API (sync)
    from swim_client import SWIMRestClient
    
    client = SWIMRestClient('your-api-key')
    result = client.get_flights(dest_icao='KJFK')
    for flight in result['data']:
        print(f"{flight['identity']['callsign']}")
    
    # REST API (async)
    async with SWIMRestClient('your-api-key') as client:
        result = await client.get_flights_async(dest_icao='KJFK')
    
    # WebSocket Streaming
    from swim_client import SWIMClient
    
    ws = SWIMClient('your-api-key')
    
    @ws.on('flight.departed')
    def on_departure(event, timestamp):
        print(f"{event.callsign} departed")
    
    ws.subscribe(['flight.departed'])
    ws.run()

Documentation:
    https://perti.vatcscc.org/api/swim/v1/docs
"""

__version__ = '2.0.0'
__author__ = 'vATCSCC'
__email__ = 'dev@vatcscc.org'

# WebSocket Client
from .client import SWIMClient

# REST Client
from .rest import (
    SWIMRestClient,
    SWIMAPIError,
    SWIMAuthError,
    SWIMRateLimitError,
)

# Event Types (for WebSocket)
from .events import (
    EventType,
    FlightEvent,
    TMIEvent,
    Position,
    PositionBatch,
    HeartbeatEvent,
    ConnectionInfo,
    SubscriptionFilters,
)

__all__ = [
    # Version
    '__version__',
    # WebSocket
    'SWIMClient',
    # REST
    'SWIMRestClient',
    'SWIMAPIError',
    'SWIMAuthError',
    'SWIMRateLimitError',
    # Events
    'EventType',
    'FlightEvent',
    'TMIEvent',
    'Position',
    'PositionBatch',
    'HeartbeatEvent',
    'ConnectionInfo',
    'SubscriptionFilters',
]
