# VATSWIM Python SDK

Official Python SDK for the VATSWIM (System Wide Information Management) API.

[![Python 3.8+](https://img.shields.io/badge/python-3.8+-blue.svg)](https://www.python.org/downloads/)
[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](https://opensource.org/licenses/MIT)

## Features

- **REST API Client** - Query flights, positions, TMIs with filtering and pagination
- **WebSocket Client** - Real-time flight event streaming
- **Typed Models** - Full type hints and dataclasses for all responses
- **Async Support** - Both sync and async clients for REST and WebSocket
- **Zero Dependencies** - Core REST client uses only stdlib (aiohttp optional for async)

## Installation

```bash
# Basic installation (REST + WebSocket)
pip install swim-client

# With async REST support
pip install swim-client[async]

# Development
pip install swim-client[dev]
```

Or install from source:
```bash
cd sdk/python
pip install -e .
```

## Quick Start

### REST API

```python
from swim_client import SWIMRestClient

# Create client
client = SWIMRestClient('your-api-key')

# Get active flights to JFK
flights = client.get_flights(dest_icao='KJFK')
for flight in flights:
    print(f"{flight.callsign}: {flight.departure} -> {flight.destination}")

# Get positions as GeoJSON
positions = client.get_positions(artcc='ZNY')
print(f"Found {positions.count} aircraft in ZNY airspace")

# Get active TMI programs
tmi = client.get_tmi_programs()
for gs in tmi.ground_stops:
    print(f"Ground Stop at {gs.airport}: {gs.reason}")
```

### WebSocket Streaming

```python
from swim_client import SWIMClient

# Create WebSocket client
client = SWIMClient('your-api-key')

@client.on('flight.departed')
def on_departure(event, timestamp):
    print(f"{event.callsign} departed {event.dep} at {timestamp}")

@client.on('flight.arrived')  
def on_arrival(event, timestamp):
    print(f"{event.callsign} arrived at {event.arr}")

@client.on('tmi.issued')
def on_tmi(event, timestamp):
    print(f"TMI issued: {event.program_type} at {event.airport}")

# Subscribe to channels with filters
client.subscribe(
    channels=['flight.departed', 'flight.arrived', 'tmi.*'],
    airports=['KJFK', 'KLGA', 'KEWR'],
    artccs=['ZNY']
)

# Run (blocking)
client.run()
```

### Async Usage

```python
import asyncio
from swim_client import AsyncSWIMRestClient, SWIMClient

async def main():
    # Async REST client
    async with AsyncSWIMRestClient('your-api-key') as client:
        flights = await client.get_flights(dest_icao='KJFK')
        positions = await client.get_positions(artcc='ZNY')
    
    # Async WebSocket
    ws = SWIMClient('your-api-key')
    
    @ws.on('flight.departed')
    def on_departure(event, ts):
        print(event.callsign)
    
    ws.subscribe(['flight.departed'])
    await ws.run_async()

asyncio.run(main())
```

## REST API Reference

### SWIMRestClient

```python
client = SWIMRestClient(
    api_key='your-key',
    base_url='https://perti.vatcscc.org/api/swim/v1',  # optional
    timeout=30.0,  # seconds
    debug=False,
)
```

#### Flight Methods

```python
# Get flights with filtering
flights = client.get_flights(
    status='active',           # 'active', 'completed', 'all'
    dept_icao='KJFK',          # Single or list: ['KJFK', 'KLGA']
    dest_icao='KLAX',
    artcc='ZNY',               # ARTCC filter
    callsign='UAL*',           # Wildcard pattern
    tmi_controlled=True,       # TMI-controlled only
    phase='ENROUTE',           # Flight phase filter
    page=1,
    per_page=100,              # Max 1000
)

# Get with pagination info
response = client.get_flights_paginated(dest_icao='KJFK')
print(f"Page {response.pagination.page} of {response.pagination.total_pages}")

# Get all flights (auto-pagination)
all_flights = client.get_all_flights(dest_icao='KJFK')

# Get single flight
flight = client.get_flight(gufi='VAT-20260116-UAL123-KLAX-KJFK')
flight = client.get_flight(flight_key='UAL123_KLAX_KJFK_20260116')
```

#### Position Methods

```python
# Get positions as GeoJSON
positions = client.get_positions(
    dept_icao='KJFK',
    dest_icao='KLAX',
    artcc='ZNY',
    tmi_controlled=True,
    phase='ENROUTE',
    include_route=True,
)

# Iterate features
for feature in positions.features:
    print(f"{feature.callsign} at {feature.latitude}, {feature.longitude}")

# Get positions in bounding box
positions = client.get_positions_bbox(
    north=42.0, south=39.0,
    east=-72.0, west=-76.0
)
```

#### TMI Methods

```python
# Get active TMI programs
tmi = client.get_tmi_programs(
    type='all',          # 'all', 'gs', 'gdp'
    airport='KJFK',
    artcc='ZNY',
    include_history=False,
)

# Access ground stops
for gs in tmi.ground_stops:
    print(f"{gs.airport}: {gs.reason} ({gs.start_time} - {gs.end_time})")

# Access GDPs
for gdp in tmi.gdp_programs:
    print(f"{gdp.airport}: {gdp.average_delay_minutes}min avg delay")

# Get TMI-controlled flights
controlled = client.get_tmi_controlled_flights(airport='KJFK')
```

#### Ingest Methods (Write Access Required)

```python
from swim_client import FlightIngest, TrackIngest

# Ingest flight data
result = client.ingest_flights([
    FlightIngest(
        callsign='VPA123',
        dept_icao='KJFK',
        dest_icao='KLAX',
        cid=1234567,
        latitude=40.6413,
        longitude=-73.7781,
        altitude_ft=35000,
        groundspeed_kts=450,
        vertical_rate_fpm=-500,
        off_utc='2026-01-16T14:45:00Z',
        eta_utc='2026-01-16T18:30:00Z',
    )
])
print(f"Processed: {result.processed}, Errors: {result.errors}")

# Ingest track/position data (high frequency)
result = client.ingest_tracks([
    TrackIngest(
        callsign='VPA123',
        latitude=41.2345,
        longitude=-74.5678,
        altitude_ft=35000,
        ground_speed_kts=450,
        heading_deg=270,
        vertical_rate_fpm=-500,
    )
])
```

## WebSocket API Reference

### SWIMClient

```python
client = SWIMClient(
    api_key='your-key',
    url='wss://perti.vatcscc.org/api/swim/v1/ws',  # optional
    reconnect=True,              # Auto-reconnect on disconnect
    reconnect_interval=5.0,      # Initial retry delay
    max_reconnect_interval=60.0, # Max retry delay
    ping_interval=30.0,          # Keepalive interval
    debug=False,
)
```

### Event Channels

| Channel | Description |
|---------|-------------|
| `flight.created` | New pilot connected |
| `flight.departed` | Aircraft wheels-up |
| `flight.arrived` | Aircraft landed |
| `flight.deleted` | Pilot disconnected |
| `flight.positions` | Batched position updates |
| `flight.*` | All flight events |
| `tmi.issued` | New GS/GDP created |
| `tmi.modified` | TMI modified |
| `tmi.released` | TMI ended |
| `tmi.*` | All TMI events |
| `system.heartbeat` | Server keepalive |

### Subscription Filters

```python
client.subscribe(
    channels=['flight.departed', 'flight.arrived'],
    airports=['KJFK', 'KLGA', 'KEWR'],    # Filter by airports
    artccs=['ZNY', 'ZBW'],                # Filter by ARTCCs
    callsign_prefix=['UAL', 'DAL'],       # Filter by callsign prefix
    bbox={                                 # Filter by bounding box
        'north': 42.0,
        'south': 39.0,
        'east': -72.0,
        'west': -76.0
    }
)
```

### Event Handlers

```python
from swim_client import FlightEvent, TMIEvent, PositionBatch

@client.on('flight.departed')
def on_departure(event: FlightEvent, timestamp: str):
    print(f"{event.callsign} from {event.dep}")

@client.on('flight.positions')
def on_positions(batch: PositionBatch, timestamp: str):
    print(f"Received {batch.count} positions")
    for pos in batch.positions:
        print(f"  {pos.callsign}: {pos.altitude_ft}ft")

@client.on('tmi.issued')
def on_tmi(event: TMIEvent, timestamp: str):
    print(f"{event.program_type} at {event.airport}")

@client.on('error')
def on_error(error: dict, timestamp: str):
    print(f"Error: {error.get('message')}")
```

## Data Models

### Flight Model

```python
flight = client.get_flight(gufi='...')

# Identity
flight.identity.callsign      # 'UAL123'
flight.identity.aircraft_type # 'B738'
flight.identity.airline_icao  # 'UAL'

# Flight Plan
flight.flight_plan.departure  # 'KLAX'
flight.flight_plan.destination # 'KJFK'
flight.flight_plan.route      # 'DCT JFK ...'

# Position
flight.position.latitude      # 40.1234
flight.position.altitude_ft   # 35000
flight.position.ground_speed_kts # 480

# Progress
flight.progress.phase         # 'ENROUTE'
flight.progress.pct_complete  # 95.2

# Times
flight.times.eta              # '2026-01-16T18:45:00Z'
flight.times.off              # '2026-01-16T14:18:00Z'

# TMI Status
flight.tmi.is_controlled      # True/False
flight.tmi.delay_minutes      # 45
```

## Error Handling

```python
from swim_client import SWIMRestError

try:
    flights = client.get_flights(dest_icao='KJFK')
except SWIMRestError as e:
    print(f"API Error [{e.status_code}]: {e.message}")
    print(f"Error Code: {e.code}")
```

## Configuration

### Environment Variables

```bash
export SWIM_API_KEY=your-api-key
export SWIM_API_URL=https://perti.vatcscc.org/api/swim/v1
```

```python
import os
from swim_client import SWIMRestClient

client = SWIMRestClient(
    api_key=os.environ['SWIM_API_KEY'],
    base_url=os.environ.get('SWIM_API_URL'),
)
```

## Examples

See the `examples/` directory for complete examples:

- `basic_example.py` - Simple WebSocket connection
- `airport_monitor.py` - Monitor arrivals/departures at specific airports
- `position_tracker.py` - Track positions in real-time
- `tmi_monitor.py` - Monitor TMI programs
- `rest_example.py` - REST API usage examples
- `async_example.py` - Async REST + WebSocket

## API Documentation

Full API documentation: https://perti.vatcscc.org/api/swim/v1/docs

## License

MIT License - see LICENSE file.

## Support

- Email: dev@vatcscc.org
- Discord: vATCSCC Server
- Issues: https://github.com/vatcscc/swim-client-python/issues
