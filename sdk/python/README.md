# VATSIM SWIM Client - Python SDK

Real-time flight data streaming from the PERTI SWIM API.

## Installation

```bash
pip install swim-client
```

Or install from source:

```bash
cd sdk/python
pip install -e .
```

## Quick Start

```python
from swim_client import SWIMClient

# Create client with your API key
client = SWIMClient('your-api-key')

# Register event handlers using decorator
@client.on('flight.departed')
def on_departure(data, timestamp):
    print(f"[{timestamp}] {data.callsign} departed {data.dep} → {data.arr}")

@client.on('flight.arrived')
def on_arrival(data, timestamp):
    print(f"[{timestamp}] {data.callsign} arrived at {data.arr}")

# Subscribe to channels with optional filters
client.subscribe(
    channels=['flight.departed', 'flight.arrived'],
    airports=['KJFK', 'KLAX', 'KATL']  # Only events for these airports
)

# Run (blocking)
client.run()
```

## Event Types

### Flight Events

| Event | Description |
|-------|-------------|
| `flight.created` | New flight appeared on network |
| `flight.departed` | Flight took off (wheels up) |
| `flight.arrived` | Flight landed (wheels down) |
| `flight.deleted` | Flight disconnected from network |
| `flight.positions` | Batch of position updates |
| `flight.*` | All flight events (wildcard) |

### TMI Events

| Event | Description |
|-------|-------------|
| `tmi.issued` | New Ground Stop or GDP issued |
| `tmi.modified` | TMI parameters changed |
| `tmi.released` | TMI ended or cancelled |
| `tmi.*` | All TMI events (wildcard) |

### System Events

| Event | Description |
|-------|-------------|
| `system.heartbeat` | Server heartbeat (every 30s) |

## Filtering

Filter events by airport, ARTCC, callsign prefix, or geographic bounding box:

```python
# Filter by airports
client.subscribe(
    channels=['flight.departed', 'flight.arrived'],
    airports=['KJFK', 'KEWR', 'KLGA']
)

# Filter by ARTCCs
client.subscribe(
    channels=['flight.*'],
    artccs=['ZNY', 'ZDC']
)

# Filter by airline (callsign prefix)
client.subscribe(
    channels=['flight.departed'],
    callsign_prefix=['AAL', 'DAL', 'UAL']
)

# Filter by geographic area
client.subscribe(
    channels=['flight.positions'],
    bbox={
        'north': 42.0,
        'south': 40.0,
        'east': -73.0,
        'west': -75.0
    }
)
```

## Async Usage

```python
import asyncio
from swim_client import SWIMClient

async def main():
    client = SWIMClient('your-api-key')
    
    @client.on('flight.departed')
    def on_departure(data, timestamp):
        print(f"{data.callsign} departed")
    
    client.subscribe(['flight.departed', 'flight.arrived'])
    
    await client.connect()
    await client.run_async()

asyncio.run(main())
```

## Event Data Classes

Events are automatically parsed into typed data classes:

```python
from swim_client import FlightEvent, TMIEvent, PositionBatch

@client.on('flight.departed')
def on_departure(event: FlightEvent, timestamp: str):
    print(f"Callsign: {event.callsign}")
    print(f"From: {event.dep}")
    print(f"To: {event.arr}")
    print(f"Equipment: {event.equipment}")
    print(f"Departure time: {event.off_utc}")

@client.on('flight.positions')
def on_positions(batch: PositionBatch, timestamp: str):
    print(f"Received {batch.count} position updates")
    for pos in batch.positions:
        print(f"  {pos.callsign}: {pos.latitude}, {pos.longitude} @ {pos.altitude_ft}ft")

@client.on('tmi.issued')
def on_tmi(event: TMIEvent, timestamp: str):
    print(f"TMI: {event.program_type} at {event.airport}")
    print(f"Reason: {event.reason}")
```

## Multiple Handlers

Register multiple handlers for the same event:

```python
@client.on('flight.departed')
def log_departure(data, timestamp):
    logger.info(f"Departure: {data.callsign}")

@client.on('flight.departed')
def update_database(data, timestamp):
    db.insert_departure(data.callsign, data.dep, data.arr)

@client.on('flight.departed')
def notify_discord(data, timestamp):
    webhook.send(f"✈️ {data.callsign} departed {data.dep}")
```

## Wildcard Handlers

Use wildcards to catch all events of a category:

```python
@client.on('flight.*')
def on_any_flight_event(data, timestamp, event_type):
    print(f"[{event_type}] {data}")

@client.on('tmi.*')
def on_any_tmi_event(data, timestamp, event_type):
    print(f"[{event_type}] TMI at {data.airport}")
```

## Connection Events

Handle connection state changes:

```python
@client.on('connected')
def on_connected(info, timestamp):
    print(f"Connected! Client ID: {info.client_id}")

@client.on('disconnected')
def on_disconnected(data, timestamp):
    print(f"Disconnected: {data}")

@client.on('error')
def on_error(error, timestamp):
    print(f"Error: {error.get('code')} - {error.get('message')}")

@client.on('system.heartbeat')
def on_heartbeat(data, timestamp):
    print(f"Heartbeat: {data.connected_clients} clients, uptime {data.uptime_seconds}s")
```

## Configuration Options

```python
client = SWIMClient(
    api_key='your-api-key',
    url='wss://perti.vatcscc.org/api/swim/v1/ws',  # WebSocket URL
    reconnect=True,                                  # Auto-reconnect on disconnect
    reconnect_interval=5.0,                          # Initial reconnect delay (seconds)
    max_reconnect_interval=60.0,                     # Max reconnect delay (seconds)
    ping_interval=30.0,                              # Keep-alive ping interval
    debug=False,                                     # Enable debug logging
)
```

## Manual Connection Control

```python
import asyncio

async def controlled_session():
    client = SWIMClient('your-api-key')
    
    # Connect
    success = await client.connect()
    if not success:
        print("Failed to connect")
        return
    
    # Do something
    client.subscribe(['flight.departed'])
    await asyncio.sleep(60)
    
    # Disconnect
    await client.disconnect()
```

## Error Handling

```python
from swim_client import SWIMClient

client = SWIMClient('your-api-key')

@client.on('error')
def on_error(error, timestamp):
    code = error.get('code', 'UNKNOWN')
    message = error.get('message', '')
    
    if code == 'AUTH_FAILED':
        print("Authentication failed - check your API key")
    elif code == 'RATE_LIMITED':
        print("Rate limited - slow down requests")
    elif code == 'INVALID_CHANNEL':
        print(f"Invalid channel: {message}")
    else:
        print(f"Error: {code} - {message}")
```

## API Key Tiers

| Tier | Rate Limit | Description |
|------|------------|-------------|
| `public` | 30/min | Basic access |
| `developer` | 100/min | Development/testing |
| `partner` | 1000/min | Integration partners |
| `system` | 10000/min | Trusted systems |

Request an API key at https://perti.vatcscc.org/api/keys

## License

MIT License - see LICENSE file for details.

## Links

- [SWIM API Documentation](https://perti.vatcscc.org/api/swim/v1/docs)
- [PERTI Home](https://perti.vatcscc.org)
- [vATCSCC](https://vatcscc.org)
