# SWIM SDK Python Examples

This directory contains example applications demonstrating various use cases for the VATSWIM API Python SDK.

## Quick Start

```bash
# Install the SDK
pip install swim-client

# Install optional dependencies for specific examples
pip install requests aiohttp        # For REST client
pip install fastapi uvicorn         # For webhook receiver
pip install discord.py              # For Discord bot
```

## Examples by Consumer Type

### 🛫 Virtual Airlines

| Example | Description | Use Case |
|---------|-------------|----------|
| [airline_fleet_tracker.py](airline_fleet_tracker.py) | Real-time fleet monitoring | Track all flights for an airline prefix, OOOI logging, OTP metrics |
| [basic_example.py](basic_example.py) | Simple WebSocket client | Getting started with real-time events |

**Airline Fleet Tracker:**
```bash
python airline_fleet_tracker.py YOUR_API_KEY DAL
python airline_fleet_tracker.py YOUR_API_KEY "VAL*"
```

### 🏢 Facility / vNAS

| Example | Description | Use Case |
|---------|-------------|----------|
| [sector_traffic_monitor.py](sector_traffic_monitor.py) | ARTCC traffic monitoring | Track sector entry/exit, flow rates, alerts |
| [airport_demand_dashboard.py](airport_demand_dashboard.py) | Demand visualization | 15-min demand buckets, TMI overlay, capacity planning |
| [route_compliance_monitor.py](route_compliance_monitor.py) | Reroute compliance | Track adherence to assigned routes |
| [position_tracker.py](position_tracker.py) | Position tracking | Real-time aircraft positions |

**Sector Traffic Monitor:**
```bash
python sector_traffic_monitor.py YOUR_API_KEY ZNY
python sector_traffic_monitor.py YOUR_API_KEY ZNY,ZDC,ZBW
```

**Airport Demand Dashboard:**
```bash
python airport_demand_dashboard.py YOUR_API_KEY KJFK
python airport_demand_dashboard.py YOUR_API_KEY KJFK,KEWR,KLGA --export demand.json
```

**Route Compliance Monitor:**
```bash
python route_compliance_monitor.py YOUR_API_KEY --fix MERIT --dest KLAX
python route_compliance_monitor.py YOUR_API_KEY --route "MERIT..ROBER..ALCOA"
```

### 📊 TMI Coordination

| Example | Description | Use Case |
|---------|-------------|----------|
| [tmi_monitor.py](tmi_monitor.py) | TMI event monitoring | Track Ground Stops, GDPs, AFPs |
| [airport_monitor.py](airport_monitor.py) | Airport-focused monitoring | Arrivals/departures with TMI status |

**TMI Monitor:**
```bash
python tmi_monitor.py YOUR_API_KEY
```

### 🔌 Integration & Data

| Example | Description | Use Case |
|---------|-------------|----------|
| [webhook_receiver.py](webhook_receiver.py) | FastAPI webhook server | Receive events for external systems |
| [discord_bot.py](discord_bot.py) | Discord integration | Post events to Discord channels |
| [data_export_pipeline.py](data_export_pipeline.py) | Batch data export | JSON/CSV/GeoJSON export for analysis |
| [data_provider_example.py](data_provider_example.py) | Data ingest | CRC/provider flight data submission |

**Webhook Receiver:**
```bash
pip install fastapi uvicorn
python webhook_receiver.py --port 8080 --secret YOUR_SECRET
```

**Discord Bot:**
```bash
pip install discord.py
export DISCORD_TOKEN=your_token
export SWIM_API_KEY=your_key
python discord_bot.py
```

**Data Export:**
```bash
# Export to JSON
python data_export_pipeline.py YOUR_KEY --format json -o flights.json

# Export JFK arrivals to CSV
python data_export_pipeline.py YOUR_KEY --format csv --dest KJFK -o jfk.csv

# Export positions as GeoJSON
python data_export_pipeline.py YOUR_KEY --format geojson --artcc ZNY -o traffic.geojson

# Export OOOI times report
python data_export_pipeline.py YOUR_KEY --format oooi -o oooi_report.csv
```

**Data Provider (Ingest):**
```bash
# Demo mode - generates test data
python data_provider_example.py YOUR_KEY --mode demo

# File mode - load from JSON
python data_provider_example.py YOUR_KEY --mode file --input flights.json

# Continuous mode - periodic updates
python data_provider_example.py YOUR_KEY --mode continuous --interval 15
```

### 🔬 Async / Advanced

| Example | Description | Use Case |
|---------|-------------|----------|
| [async_example.py](async_example.py) | Async WebSocket client | Non-blocking event processing |
| [rest_example.py](rest_example.py) | REST API client | Query flights, positions, TMIs |

## API Endpoints Demonstrated

### REST API
- `GET /flights` - Query flights with filters
- `GET /flight` - Get single flight by GUFI/flight_key
- `GET /positions` - Get positions as GeoJSON
- `GET /tmi/programs` - Get active TMIs
- `GET /tmi/controlled` - Get TMI-controlled flights
- `POST /ingest/adl` - Ingest flight data (write access)
- `POST /ingest/track` - Ingest track data (write access)

### WebSocket Events
- `flight.created` - New flight filed
- `flight.departed` - Flight pushed back/took off
- `flight.arrived` - Flight landed/arrived at gate
- `flight.position` - Position update
- `flight.sector_entry` - Entered ARTCC sector
- `flight.sector_exit` - Exited ARTCC sector
- `flight.route_change` - Route amendment
- `tmi.issued` - New TMI issued
- `tmi.modified` - TMI modified
- `tmi.released` - TMI released/cancelled
- `system.heartbeat` - Periodic heartbeat

## Getting an API Key

Contact the vATCSCC SWIM team to request an API key:
- **Read-only**: Flight data, positions, TMIs
- **Write access**: Required for data ingest (CRC, providers)

API keys are rate-limited based on tier:
- **Basic**: 60 req/min, 1000 req/hour
- **Standard**: 120 req/min, 5000 req/hour  
- **Premium**: 300 req/min, unlimited

## Support

- Documentation: https://perti.vatcscc.org/api/swim/docs
- Issues: Contact vATCSCC technical team
- Discord: #swim-api channel

## License

These examples are provided under the MIT License for use with VATSIM operations.
