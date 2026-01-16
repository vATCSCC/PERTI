"""
VATSIM SWIM Client - Python SDK

Real-time flight data streaming from PERTI SWIM API.

Usage:
    from swim_client import SWIMClient

    client = SWIMClient('your-api-key')
    
    @client.on('flight.departed')
    def on_departure(data):
        print(f"{data['callsign']} departed {data['dep']}")
    
    client.subscribe(['flight.departed', 'flight.arrived'], airports=['KJFK', 'KLAX'])
    client.run()

"""

__version__ = '1.0.0'
__author__ = 'vATCSCC'

from .client import SWIMClient
from .events import EventType, FlightEvent, TMIEvent, PositionBatch

__all__ = [
    'SWIMClient',
    'EventType',
    'FlightEvent',
    'TMIEvent',
    'PositionBatch',
]
